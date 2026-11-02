<?php
namespace App\Service\Collection;

use App\Repository\RepositoryFactory;

/** 电影版本归属是持久数据，不走缓存；与播放地址在同一个采集事务中读写。 */
class VideoVersionService
{
    private static function key(int $vid): string { return 'collect_vod_' . $vid; }

    public static function owner(string $source, int $remoteId): string
    {
        return $source !== '' && $remoteId > 0 ? hash('sha256', $source . "\0" . $remoteId) : '';
    }

    public static function emptyState(array $row): array
    {
        return array('v' => 1, 'alias' => (string)($row['alias'] ?? ''), 'groups' => array());
    }

    /** 按本页候选影片批量取回，避免逐影片查 KV；事务保证读取主库。 */
    public static function load(array $videos): array
    {
        $keys = $states = array();
        foreach ($videos as $vid => $row) {
            $keys[self::key((int)$vid)] = (int)$vid;
            $states[$vid] = self::emptyState($row);
        }
        foreach (array_chunk(array_keys($keys), 500) as $chunk) {
            $rows = RepositoryFactory::table('kv', 'k')->builder()->select(array('k', 'v'))->whereIn('k', $chunk)->get();
            if ($rows === false) throw new \RuntimeException('版本来源读取失败，已停止本页采集');
            foreach ($rows as $row) {
                $vid = $keys[$row['k']];
                $state = json_decode($row['v'], true);
                if (!is_array($state) || ($state['v'] ?? 0) !== 1 || !isset($state['alias']) || !is_array($state['groups'] ?? null)) {
                    throw new \RuntimeException('版本来源记录损坏，已停止采集以保护现有地址');
                }
                // 手工重用 vid 时不能继承另一个影片的归属；回收站恢复原 alias 则仍能续更。
                if ($state['alias'] !== $states[$vid]['alias']) continue;
                foreach ($state['groups'] as $group) {
                    if (!is_array($group)) throw new \RuntimeException('版本来源记录损坏');
                    foreach ($group as $hash => $owners) {
                        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !is_array($owners)) throw new \RuntimeException('版本来源记录损坏');
                        foreach ($owners as $owner => $flag) {
                            if (!preg_match('/^[a-f0-9]{64}$/', $owner) || $flag !== true) throw new \RuntimeException('版本来源记录损坏');
                        }
                    }
                }
                $states[$vid] = $state;
            }
        }
        return $states;
    }

    /** 只保存变化的影片，批量删除/插入均在调用方事务内，失败整页回滚。 */
    public static function save(array $states): void
    {
        if (!$states) return;
        if (!_SERVER('db')->inTransaction()) throw new \LogicException('版本来源必须与采集事务一起保存');
        foreach (array_chunk($states, 200, true) as $chunk) {
            $rows = array();
            foreach ($chunk as $vid => $state) {
                $rows[] = array('k' => self::key((int)$vid), 'v' => json_encode($state, JSON_THROW_ON_ERROR), 'expiry' => 0);
            }
            RepositoryFactory::table('kv', 'k')->delete(array('k' => array_column($rows, 'k')));
            if (RepositoryFactory::table('kv', 'k')->bigInsert($rows) !== count($rows)) {
                throw new \RuntimeException('版本来源保存失败，本页已回滚，请重试');
            }
        }
    }

    /** 回收站彻底清理后回收孤立来源记录；仍在主表或回收站中的影片继续保留归属。 */
    public static function purgeOrphans(): void
    {
        $db = _SERVER('db');
        $prefix = $db->tablepre;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) throw new \RuntimeException('无效的数据表前缀');
        $sql = "DELETE m FROM `{$prefix}kv` m
            LEFT JOIN `{$prefix}vod` v ON v.vid=CAST(SUBSTRING(m.k,13) AS UNSIGNED)
            LEFT JOIN `{$prefix}recycle` r ON r.type='vod' AND r.target_id=CAST(SUBSTRING(m.k,13) AS UNSIGNED)
            WHERE m.k LIKE 'collect!_vod!_%' ESCAPE '!'
              AND m.k REGEXP '^collect_vod_[1-9][0-9]*$' AND v.vid IS NULL AND r.id IS NULL";
        if (!$db->prepare_execute($sql)) throw new \RuntimeException('孤立版本来源清理失败');
    }

    public static function seed(array $row, string $owner): array
    {
        $state = self::emptyState($row);
        $urls = explode('$$$', $row['play_url']);
        foreach (explode('$$$', $row['play_from']) as $i => $source) {
            $group = array();
            self::merge('', $urls[$i] ?? '', $owner, $group);
            if ($group) $state['groups'][$source] = $group;
        }
        return $state;
    }

    /** 命名电影入口与编号/日期剧集分开处理，后者返回 null，沿用原覆盖/合并设置。 */
    private static function parse(string $value): ?array
    {
        $entries = $labels = $addresses = array();
        foreach (array_unique(explode('#', xn_url_strip($value))) as $entry) {
            if ($entry === '') continue;
            $parts = explode('$', $entry, 2);
            $label = trim($parts[0]);
            if ($label === '' || preg_match('/第\s*[0-9零〇一二三四五六七八九十百千两]+\s*[集话期]|(?:episode|ep)\s*\d|\d+\s*[集话期]|^s\d+\s*e\d+|^\d+(?:\s*[-_.\/]\s*\d+)*(?:\s*[上下中])?$/iu', $label)) return null;
            if (count($parts) !== 2 || trim($parts[1]) === '') continue;
            $labels[mb_strtolower($label, 'UTF-8')][] = count($entries);
            $addresses[trim($parts[1])][] = count($entries);
            $entries[] = $entry;
        }
        return array($entries, $labels, $addresses);
    }

    /**
     * 版本更新只匹配当前来源条目拥有的入口，不以全局 HD 等名称判断所有权。
     * 不同条目可共用同一地址；其中一个换链时分离入口，另一个保留旧地址。
     * 无历史归属或缺少源 ID 时，仅凭完全相同地址认领/去重，不能猜测覆盖。
     */
    public static function merge(string $old, string $incoming, string $owner, array &$owners): ?string
    {
        $previous = self::parse($old);
        $next = self::parse($incoming);
        if ($previous === null || $next === null) { $owners = array(); return null; }
        [$result, $oldLabels, $oldAddresses] = $previous;
        [$entries, $newLabels, $newAddresses] = $next;
        $hashes = array_map(static fn($entry) => hash('sha256', $entry), $result);
        $owners = array_intersect_key($owners, array_fill_keys($hashes, true));
        $seen = array_flip($result);
        $replaced = array();
        foreach ($entries as $entry) {
            $hash = hash('sha256', $entry);
            [$label, $address] = explode('$', $entry, 2);
            $key = mb_strtolower(trim($label), 'UTF-8');
            $address = trim($address);
            $matches = array_values(array_filter($oldLabels[$key] ?? array(), static function ($index) use ($owners, $hashes, $owner) {
                return $owner !== '' && isset($owners[$hashes[$index]][$owner]);
            }));
            if (isset($seen[$entry])) {
                // 自己换到了另一条资源现有的地址：解除旧地址归属，再共享新地址。
                // 否则旧地址也一直属于自己，下一次更新会被误判为同名多版本。
                if (count($matches) === 1 && count($newLabels[$key]) === 1) {
                    $target = $matches[0];
                    $oldHash = $hashes[$target];
                    if ($oldHash !== $hash && !isset($replaced[$target])) {
                        unset($owners[$oldHash][$owner]);
                        if (empty($owners[$oldHash])) {
                            unset($owners[$oldHash], $seen[$result[$target]], $result[$target]);
                        }
                        $replaced[$target] = true;
                    }
                }
                if ($owner !== '') $owners[$hash][$owner] = true;
                continue;
            }
            // 只认领未归属的相同地址，或匹配自己拥有的地址（支持版本改名）。
            $addressMatches = array_values(array_filter($oldAddresses[$address] ?? array(), static function ($index) use ($owners, $hashes, $owner) {
                return empty($owners[$hashes[$index]]) || ($owner !== '' && isset($owners[$hashes[$index]][$owner]));
            }));
            $target = null;
            if (count($addressMatches) === 1 && count($newAddresses[$address]) === 1) $target = $addressMatches[0];
            elseif (count($matches) === 1 && count($newLabels[$key]) === 1) $target = $matches[0];
            if ($target !== null && !isset($replaced[$target])) {
                $oldHash = $hashes[$target];
                $otherOwners = $owners[$oldHash] ?? array();
                unset($otherOwners[$owner]);
                if ($otherOwners) {
                    // 共享入口写时分离，不能替其他来源换链。
                    unset($owners[$oldHash][$owner]);
                    $result[] = $entry;
                } else {
                    unset($seen[$result[$target]], $owners[$oldHash]);
                    $result[$target] = $entry;
                }
                $replaced[$target] = true;
            } else {
                $result[] = $entry;
            }
            if ($owner !== '') $owners[$hash][$owner] = true;
            $seen[$entry] = true;
        }
        $owners = array_intersect_key($owners, array_fill_keys(array_map(static fn($entry) => hash('sha256', $entry), $result), true));
        return implode('#', $result);
    }
}
