<?php
namespace App\Service\Collection;

/** 保留旧名称绑定的首次识别结果。目录缓存更新或清空都不能重新指定它的来源编号。 */
class LegacyVideoBindingService
{
    public static function observe(string $code, array $bindings, array $catalog, ?array $previous): array
    {
        $legacy = array_filter($bindings, static fn($entry) => !is_array($entry));
        if (!$legacy) return $catalog;
        $db = _SERVER('db');
        $pdo = $db->connect();
        $table = $db->qi($db->tablepre . 'kv');
        $key = 'vbind_' . substr(hash('sha256', $code), 0, 26);
        if (!$pdo) throw new \RuntimeException('采集数据库暂不可用，请稍后重试');
        $query = $pdo->prepare("SELECT v FROM $table WHERE k=?");
        $paths = VideoCategoryCatalog::paths($catalog);
        $seed = !empty($previous['hierarchical']) ? $previous : $catalog;
        $previousIds = [];
        foreach ($previous['rows'] ?? [] as $row) $previousIds[$row['type_name']][] = (int)$row['type_id'];
        $eligible = $previousIds;
        $other = $previous['other'] ?? null;
        $otherPaths = null;
        if ($other) {
            $otherIds = [];
            foreach ($other['rows'] as $row) $otherIds[$row['type_name']][] = (int)$row['type_id'];
            foreach ($eligible as $name => $ids) $eligible[$name] = array_intersect($ids, $otherIds[$name] ?? []);
            if ($other['hierarchical']) $otherPaths = VideoCategoryCatalog::paths($other);
        }
        // 即使某一支恰好在旧父级下，历史记录仍有多个可能编号时也须人工确认。
        $eligible = array_filter($eligible, static fn($ids) => count($ids) === 1);
        $candidates = $seedPaths = null;

        // 确认待处理后只允许人工恢复。并发采集用原值比较更新，较早返回的目录不能覆盖待确认状态。
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $query->execute([$key]);
            $raw = $query->fetchColumn();
            $history = $raw === false ? [] : json_decode($raw, true);
            if (!is_array($history)) throw new \RuntimeException('旧分类绑定记录无法读取，请重新核对分类');
            $next = $history;
            foreach ($legacy as $name => $cid) {
                $cid = (int)$cid;
                $ref = $history[$name] ?? [];
                if (($ref['cid'] ?? 0) !== $cid || !array_key_exists('pending', $ref)) {
                    $candidates ??= $previous ? CollectionCategoryService::legacyCandidates($bindings, $seed, $eligible) : [];
                    $seedPaths ??= VideoCategoryCatalog::paths($seed);
                    $known = $previousIds[$name] ?? [];
                    $id = $candidates[$name] ?? (count($known) === 1 ? $known[0] : 0);
                    $path = $seedPaths[$id] ?? '';
                    $certain = isset($candidates[$name]) && ($otherPaths === null || ($otherPaths[$id] ?? null) === $path);
                    $ref = ['cid'=>$cid, 'id'=>$id, 'path'=>$path, 'pending'=>!$certain];
                }
                if (!$ref['pending'] && ($paths[$ref['id']] ?? null) !== $ref['path']) $ref['pending'] = true;
                $next[$name] = $ref;
            }
            if ($next !== $history) {
                $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if ($raw === false) {
                    $write = $pdo->prepare("INSERT IGNORE INTO $table (k,v) VALUES (?,?)");
                    $write->execute([$key, $json]);
                } else {
                    $write = $pdo->prepare("UPDATE $table SET v=? WHERE k=? AND BINARY v=?");
                    $write->execute([$json, $key, $raw]);
                }
                if ($write->rowCount() !== 1) continue;
            }
            $catalog['legacy'] = array_intersect_key($next, $legacy);
            $catalog['history_key'] = $key;
            return $catalog;
        }
        throw new \RuntimeException('旧分类绑定正在更新，请重试本页采集');
    }

    /** 网络取页和海报预取期间，另一个请求可能已发现分类变更或保存了新的绑定。 */
    public static function checkBeforeImport(string $code, array $bindings, ?array $catalog): void
    {
        $db = _SERVER('db');
        $keys = ['bind'];
        if (isset($catalog['history_key'])) $keys[] = $catalog['history_key'];
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $q = $db->connect()->prepare('SELECT k,v FROM ' . $db->qi($db->tablepre . 'kv') . " WHERE k IN ($marks) ORDER BY k LOCK IN SHARE MODE");
        $q->execute($keys);
        $records = $q->fetchAll(\PDO::FETCH_KEY_PAIR);
        if (isset($records['bind']) && (json_decode($records['bind'], true)[$code] ?? []) !== $bindings) {
            throw new \RuntimeException('资源站分类绑定已变更，请重新采集本页');
        }
        if (isset($catalog['history_key'])) {
            $current = json_decode($records[$catalog['history_key']] ?? '', true);
            if (!is_array($current) || array_intersect_key($current, $catalog['legacy']) !== $catalog['legacy']) {
                throw new \RuntimeException('来源分类已变更，请重新采集本页');
            }
        }
    }
}
