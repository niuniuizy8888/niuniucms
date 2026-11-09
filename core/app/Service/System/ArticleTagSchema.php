<?php
namespace App\Service\System;

/** Separate article bindings without changing the existing video relation table. */
class ArticleTagSchema
{
    private const STATE_KEY = 'article_tag_schema_v1';
    private static $ready = false;

    public static function table(string $name): string
    {
        $db = _SERVER('db');
        return $db->qi($db->tablepre . $name);
    }

    public static function sql(string $sql, array $args = []): \PDOStatement
    {
        $stmt = _SERVER('db')->connect()->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    private static function state(): array
    {
        $value = self::sql('SELECT v FROM ' . self::table('kv') . ' WHERE k=?', [self::STATE_KEY])->fetchColumn();
        return (array)json_decode((string)$value, true);
    }

    /** Frontend callers only read the marker; they never create tables or migrate data. */
    public static function ready(): bool
    {
        if (!self::$ready) self::$ready = !empty(self::state()['done']);
        return self::$ready;
    }

    public static function ensureReady(): void
    {
        if (self::ready()) return;
        $state = self::migrate();
        if (empty($state['done'])) {
            throw new \RuntimeException('文章标签索引正在升级，已检查 ' . (int)($state['checked'] ?? 0) . ' 篇文章；进度已保存，请刷新文章管理页或再次执行当前操作继续，完成后会恢复正常处理。');
        }
    }

    /** At most 5 batches / 2 seconds per call. Checkpoints and each batch commit together. */
    public static function migrate(int $maxBatches = 5): array
    {
        $db = _SERVER('db');
        $pdo = $db->connect();
        if ($db->inTransaction() || $pdo->inTransaction()) {
            throw new \RuntimeException('文章标签索引尚未升级完成，请在当前事务结束后再次执行操作，或刷新文章管理页继续升级。');
        }
        $lock = 'tag_art_' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn() . $db->tablepre), 0, 48);
        if ((int)self::sql('SELECT GET_LOCK(?, 2)', [$lock])->fetchColumn() !== 1) {
            throw new \RuntimeException('文章标签索引正在升级，请稍后刷新重试。');
        }
        try {
            $state = self::state();
            if (!empty($state['done'])) { self::$ready = true; return $state; }
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::table('tag_art') . ' (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tagid INT UNSIGNED NOT NULL, aid INT UNSIGNED NOT NULL,
                UNIQUE KEY tagid_aid(tagid,aid), KEY aid(aid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $deadline = microtime(true) + 2;
            for ($batch = 0; $batch < max(1, min(50, $maxBatches)); ++$batch) {
                $pdo->beginTransaction();
                try {
                    $rows = self::sql('SELECT aid,tag FROM ' . self::table('art') . ' WHERE aid>? ORDER BY aid LIMIT 200 FOR UPDATE', [(int)($state['cursor'] ?? 0)])->fetchAll(\PDO::FETCH_ASSOC);
                    \App\Service\Content\ArticleTagService::migrateRows($rows);
                    if ($rows) $state['cursor'] = (int)end($rows)['aid'];
                    $state['checked'] = (int)($state['checked'] ?? 0) + count($rows);
                    $state['done'] = count($rows) < 200;
                    self::sql('INSERT INTO ' . self::table('kv') . ' (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [self::STATE_KEY, json_encode($state)]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                // Cached video relations from the previous schema must not survive a migrated batch.
                if ($rows && function_exists('page_cache_bump')) page_cache_bump(true);
                if ($state['done'] || microtime(true) >= $deadline) break;
            }
            self::$ready = !empty($state['done']);
            return $state;
        } finally {
            self::sql('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
