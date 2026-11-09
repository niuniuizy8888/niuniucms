<?php
namespace App\Service\Collection;

/** Persistent batch policy and checkpoints, read from the primary connection rather than cache. */
class CollectionBatchStateService
{
    private static function key(int $id): string { return 'api_batch_state_' . $id; }

    private static function sql(string $sql, array $args = [])
    {
        $stmt = _SERVER('db')->connect()->prepare($sql);
        if (!$stmt || !$stmt->execute($args)) throw new \RuntimeException('采集批次状态保存失败，请重试');
        return $stmt;
    }

    public static function withLock(int $id, callable $work)
    {
        if ($id < 1) throw new \RuntimeException('采集批次不存在');
        $db = _SERVER('db'); $pdo = $db->connect();
        $lock = 'fast_batch_' . substr(hash('sha256', $pdo->query('SELECT DATABASE()')->fetchColumn() . '|' . $db->tablepre . '|' . $id), 0, 48);
        if ((int)self::sql('SELECT GET_LOCK(?, 10)', [$lock])->fetchColumn() !== 1) throw new \RuntimeException('该批次正在处理，请稍后重试');
        try { return $work(); }
        finally { self::sql('SELECT RELEASE_LOCK(?)', [$lock]); }
    }

    public static function read(int $id): array
    {
        $db = _SERVER('db');
        $row = self::sql('SELECT v FROM ' . $db->qi($db->tablepre . 'kv') . ' WHERE k=?', [self::key($id)])->fetchColumn();
        $state = json_decode((string)$row, true);
        return is_array($state) ? $state : [];
    }

    public static function save(int $id, array $state): void
    {
        $db = _SERVER('db');
        if (!$db->inTransaction()) throw new \LogicException('批次断点必须与采集数据在同一事务保存');
        self::sql('INSERT INTO ' . $db->qi($db->tablepre . 'kv') . ' (k,v,expiry) VALUES (?,?,0) ON DUPLICATE KEY UPDATE v=VALUES(v),expiry=0',
            [self::key($id), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    public static function batch(int $id): array
    {
        $db = _SERVER('db');
        return self::sql('SELECT id,source_code,status,limit_num,last_page,processed_num,imported_num,duplicate_num FROM '
            . $db->qi($db->tablepre . 'api_import_batch') . ' WHERE id=?', [$id])->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Call under the batch lock. Replayed committed pages return the latest receipt without any write. */
    public static function validate(array $batch, array $state, string $code, int $page): ?array
    {
        if (!$batch) throw new \RuntimeException('采集批次不存在');
        if ((string)$batch['source_code'] !== $code) throw new \RuntimeException('采集源与批次不一致，请重新打开该批次');
        if ((int)$batch['status'] === 2) throw new \RuntimeException('该批次已回滚，不能继续导入');
        if (($state['v'] ?? 0) !== 1 || ($state['items_version'] ?? 0) !== 1 || ($state['code'] ?? '') !== $code
            || !in_array($state['mode'] ?? '', ['skip', 'update'], true)
            || !array_key_exists($state['scope'] ?? '', ApiImportService::fastDbUpdateScopeOptions())
            || (int)($state['limit'] ?? 0) < 1 || (int)$state['limit'] !== (int)$batch['limit_num']) {
            throw new \RuntimeException('旧批次缺少可靠的导入策略，已导入内容保留；请重新创建采集批次');
        }
        $last = (int)$batch['last_page'];
        if ($page < 1 || !in_array((int)$batch['status'], [0, 1], true)) throw new \RuntimeException('该批次不能继续导入');
        if ($page <= $last && isset($state['receipt']) && (int)($state['receipt']['page'] ?? -1) === $last) {
            return $state['receipt'] + ['reused' => true];
        }
        if ((int)$batch['status'] !== 0) throw new \RuntimeException('该批次已完成，请重新创建批次');
        if ($page !== $last + 1) throw new \RuntimeException('采集页码与已保存进度不一致，请从第 ' . ($last + 1) . ' 页继续');
        return null;
    }
}
