<?php
namespace App\Service\Collection;

use App\Repository\RepositoryFactory;

/** Classification rules shared by legacy binding and every collection importer. */
class CollectionCategoryService
{
    public static function sourceModel(string $code, array $conf): ?int
    {
        $model = $conf['api'][$code]['model'] ?? null;
        return in_array($model, [0, 1, '0', '1'], true) ? (int)$model : null;
    }

    /** Query current rows, not the category cache; locking reads also avoid stale replicas. */
    public static function targets(?int $model = null, ?array $ids = null): array
    {
        if ($model !== null && !in_array($model, [0, 1], true)) return [];
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map([self::class, 'positiveId'], $ids))));
            if (!$ids) return [];
        }
        $query = RepositoryFactory::cate()->builder()->select(['cid', 'cup', 'name', 'model', 'category'])
            ->whereIn('model', $model === null ? [0, 1] : [$model])->where('category', '<>', 2)->lock();
        if ($ids !== null) $query->whereIn('cid', $ids);
        $rows = $query->get();
        if ($rows === false) throw new \RuntimeException('读取采集分类失败，请稍后重试');
        $out = [];
        foreach ($rows as $row) $out[(int)$row['cid']] = $row;
        return $out;
    }

    public static function validBindings(array $bindings, int $model): array
    {
        $targets = self::targets($model, array_values($bindings));
        $out = [];
        foreach ($bindings as $name => $value) {
            $cid = self::positiveId($value);
            if (isset($targets[$cid])) $out[$name] = $cid;
        }
        return $out;
    }

    /** 在内存中把旧名称绑定还原到当前来源编号，保留站长保存的原始配置。 */
    public static function videoBindings(array $bindings, ?array $targets = null, ?array $catalog = null): array
    {
        $targets = $targets ?? self::targets(0, array_map(static function ($entry) {
            return is_array($entry) ? ($entry['cid'] ?? 0) : $entry;
        }, $bindings));
        $ids = $byName = [];
        foreach ($bindings as $key => $entry) {
            if (is_array($entry)) {
                $cid = self::positiveId($entry['cid'] ?? 0);
                if (!isset($targets[$cid])) continue;
                $ids[$key] = $cid;
            } else {
                $cid = self::positiveId($entry);
                if (isset($targets[$cid])) $byName[$key] = $cid;
            }
        }
        $types = array_column($catalog['rows'] ?? [], null, 'type_id');
        $groups = [];
        foreach ($types as $id => $type) $groups[$type['type_name']][] = $id;

        $legacy = $ambiguous = [];
        foreach ($byName as $name => $cid) {
            $group = $groups[$name] ?? [];
            if (count($group) === 1) {
                if (!isset($ids[$group[0]])) {
                    $ids[$group[0]] = $cid;
                    $legacy[$group[0]] = (string)$name;
                }
            } elseif (count($group) > 1) {
                $ambiguous[$name] = $cid;
            }
        }
        if ($ambiguous && !empty($catalog['hierarchical'])) {
            $targets = self::withVideoParents($targets);
            $anchors = $ids;
            foreach ($ambiguous as $name => $cid) {
                $matches = [];
                foreach ($groups[$name] as $id) {
                    if (self::sameVideoParent((int)$types[$id]['type_pid'], (int)$targets[$cid]['cup'], $types, $targets, $anchors)) $matches[] = $id;
                }
                if (count($matches) !== 1) continue;
                $id = $matches[0];
                if (!isset($ids[$id])) {
                    $ids[$id] = $cid;
                    $legacy[$id] = (string)$name;
                }
                unset($ambiguous[$name]);
            }
        }
        $names = [];
        foreach ($groups as $name => $group) {
            if (count($group) === 1 && isset($ids[$group[0]])) $names[$name] = $ids[$group[0]];
        }
        return ['ids'=>$ids, 'names'=>$names, 'legacy'=>$legacy, 'ambiguous'=>$ambiguous];
    }

    /** 只补齐已绑定分类缺少的祖先；按层取数，避免在入库事务中锁住整张分类表。 */
    private static function withVideoParents(array $targets): array
    {
        $rows = $targets;
        while ($rows) {
            $missing = array_diff(array_unique(array_filter(array_column($rows, 'cup'))), array_keys($targets));
            if (!$missing) break;
            $rows = self::targets(0, $missing);
            $targets += $rows;
        }
        return $targets;
    }

    private static function sameVideoParent(int $remote, int $local, array $types, array $targets, array $anchors): bool
    {
        while ($remote && $local) {
            // 父级有明确绑定时，以站长的映射为准，允许本站重命名或移动父分类。
            if (isset($anchors[$remote])) return $anchors[$remote] === $local;
            if (!isset($types[$remote], $targets[$local])) return false;
            if (trim(filter_all_html($types[$remote]['type_name'])) !== htmlspecialchars_decode($targets[$local]['name'], ENT_QUOTES)) return false;
            $remote = (int)$types[$remote]['type_pid'];
            $local = (int)$targets[$local]['cup'];
        }
        return $remote === $local;
    }

    public static function videoTarget(array $index, array $item): int
    {
        $name = $item['type_name'] ?? '';
        $id = $item['type_id'] ?? null;
        if (!is_string($name) || ($id !== null && !is_int($id) && !is_string($id))) return 0;
        if ($id === null || $id === '' || $id === 0 || $id === '0') return $index['names'][$name] ?? 0;
        if (isset($index['legacy'][$id]) && $index['legacy'][$id] !== $name) return 0;
        return $index['ids'][$id] ?? 0;
    }

    public static function usesVideoIds(array $bindings): bool
    {
        foreach ($bindings as $entry) if (is_array($entry)) return true;
        return false;
    }

    /** 在主库锁内更新绑定，回调返回 null 表示预览后配置已变更。 */
    public static function updateBindings(array $conf, callable $change): bool
    {
        $db = _SERVER('db');
        $pdo = $db->connect();
        if (!$pdo || $pdo->inTransaction()) return false;
        $table = $db->qi($db->tablepre . 'kv');
        try {
            $pdo->beginTransaction();
            $current = ['api'=>$conf['api'] ?? [], 'bind'=>$conf['bind'] ?? []];
            $query = $pdo->query("SELECT k,v FROM $table WHERE k IN ('api','bind') ORDER BY k FOR UPDATE");
            foreach ($query as $row) {
                $value = json_decode($row['v'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($value)) throw new \RuntimeException('采集配置格式无效');
                $current[$row['k']] = $value;
            }
            $bindings = $change($current);
            if ($bindings === null) {
                $pdo->rollBack();
                return false;
            }
            $stmt = $pdo->prepare("REPLACE INTO $table (k,v) VALUES ('bind',?)");
            $stmt->execute([json_encode($bindings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            xn_log('保存采集分类绑定失败：' . $e->getMessage(), 'error');
            return false;
        }
        $memo =& kv_request_cache();
        unset($memo['bind']);
        cache_delete('bind');
        return true;
    }

    private static function positiveId($value): int
    {
        if (!is_int($value) && !is_string($value)) return 0;
        if (!preg_match('/^[1-9][0-9]*$/D', (string)$value)) return 0;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? 0 : $id;
    }
}
