<?php
namespace App\Service\System;

/** Lazy, idempotent upgrade: old installations do not need an import script. */
class AttachmentSchema
{
    private static $ready = false;
    public static function ensure(): void
    {
        if (self::$ready) return;
        $db = _SERVER('db');
        $pdo = $db->connect();
        $definitions = array(
            'attachments' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, url_hash CHAR(64) NOT NULL, url TEXT NOT NULL, origin_hash CHAR(64) NOT NULL, origin TEXT NOT NULL, path VARCHAR(512) NOT NULL DEFAULT '', backend VARCHAR(16) NOT NULL DEFAULT 'external', profile LONGTEXT NOT NULL, bytes BIGINT UNSIGNED NOT NULL DEFAULT 0, sha256 CHAR(64) NOT NULL DEFAULT '', created INT UNSIGNED NOT NULL, UNIQUE KEY url_hash(url_hash), KEY source_backend(origin_hash,backend)",
            'attachment_jobs' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, signature CHAR(64) NOT NULL, options LONGTEXT NOT NULL, state VARCHAR(16) NOT NULL, phase TINYINT NOT NULL DEFAULT 0, scan_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0, scanned BIGINT UNSIGNED NOT NULL DEFAULT 0, created INT UNSIGNED NOT NULL, updated INT UNSIGNED NOT NULL, KEY signature(signature,state)",
            'attachment_items' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(8) NOT NULL, record_id BIGINT UNSIGNED NOT NULL, field VARCHAR(16) NOT NULL, title VARCHAR(255) NOT NULL, source TEXT NOT NULL, source_hash CHAR(64) NOT NULL, asset_id BIGINT UNSIGNED NOT NULL DEFAULT 0, state VARCHAR(16) NOT NULL DEFAULT 'pending', error VARCHAR(500) NOT NULL DEFAULT '', UNIQUE KEY reference_item(job_id,kind,record_id,field,source_hash), KEY job_state(job_id,state,id), KEY asset_id(asset_id)",
        );
        foreach ($definitions as $name => $ddl) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $db->qi($db->tablepre . $name) . ' (' . $ddl . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        $upgrades = [
            'attachments' => ['stored_id'=>"ADD stored_id BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD KEY stored_id(stored_id)"],
            'attachment_jobs' => [
                'stats_ready'=>'ADD stats_ready TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'pending_count'=>'ADD pending_count BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'done_count'=>'ADD done_count BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'failed_count'=>'ADD failed_count BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'skipped_count'=>'ADD skipped_count BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'reused_count'=>'ADD reused_count BIGINT UNSIGNED NOT NULL DEFAULT 0',
            ],
            'attachment_items' => [
                'started_at'=>'ADD started_at INT UNSIGNED NOT NULL DEFAULT 0',
                'finished_at'=>'ADD finished_at INT UNSIGNED NOT NULL DEFAULT 0',
                'elapsed_ms'=>'ADD elapsed_ms INT UNSIGNED NOT NULL DEFAULT 0',
                'result_url'=>'ADD result_url TEXT NULL',
                'sort_id'=>'ADD sort_id BIGINT NOT NULL DEFAULT 0, ADD KEY job_sort(job_id,sort_id), ADD KEY job_state_sort(job_id,state,sort_id)',
            ],
        ];
        foreach ($upgrades as $name=>$columns) {
            $table = self::table($name);
            $existing = $pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(\PDO::FETCH_COLUMN);
            $missing = array_diff_key($columns, array_flip($existing));
            if (!$missing) continue;
            self::lock('attachment-upgrade', static function () use ($pdo,$table,$missing,$name) {
                $existing = $pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(\PDO::FETCH_COLUMN);
                $missing = array_diff_key($missing, array_flip($existing));
                if (!$missing) return;
                $pdo->exec('ALTER TABLE '.$table.' '.implode(', ', $missing));
                if ($name === 'attachments' && isset($missing['stored_id'])) {
                    // Keep source URLs for recovery, but group old successful downloads
                    // with their saved files instead of showing a second undownloaded card.
                    $pdo->exec("UPDATE $table a JOIN (SELECT origin_hash,MAX(id) AS id FROM $table WHERE backend IN ('local','ftp') AND origin<>'' GROUP BY origin_hash) b ON b.origin_hash=a.url_hash SET a.stored_id=b.id WHERE a.backend='external'");
                    $items=self::table('attachment_items');
                    $pdo->exec("UPDATE $table a JOIN (SELECT i.source_hash,MAX(i.id) AS last_id FROM $items i JOIN $table saved ON saved.id=i.asset_id WHERE i.state='done' AND saved.backend IN ('local','ftp') GROUP BY i.source_hash) latest ON latest.source_hash=a.url_hash JOIN $items done ON done.id=latest.last_id SET a.stored_id=done.asset_id WHERE a.backend='external'");
                }
                if ($name === 'attachment_items' && isset($missing['sort_id'])) {
                    $pdo->exec("UPDATE $table SET sort_id=IF(state='pending',-CAST(id AS SIGNED),CAST(id AS SIGNED))");
                }
            });
        }
        $items=self::table('attachment_items');
        if(!$pdo->query("SHOW INDEX FROM $items WHERE Key_name='job_source'")->fetch()) {
            self::lock('attachment-upgrade',static function() use($pdo,$items) {
                if(!$pdo->query("SHOW INDEX FROM $items WHERE Key_name='job_source'")->fetch()) $pdo->exec("ALTER TABLE $items ADD KEY job_source(job_id,source_hash,state,id)");
            });
        }
        self::$ready = true;
    }
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
    public static function lock(string $name, callable $work)
    {
        $key = self::lockKey($name);
        if ((int)self::sql('SELECT GET_LOCK(?, 5)', [$key])->fetchColumn() !== 1) throw new \RuntimeException('图片任务正在处理，请稍后再试');
        try { return $work(); } finally { self::sql('SELECT RELEASE_LOCK(?)', [$key]); }
    }

    private static function lockKey(string $name): string
    {
        $db = _SERVER('db');
        return 'nna_' . hash('sha224', $db->connect()->query('SELECT DATABASE()')->fetchColumn() . $db->tablepre . $name);
    }

    public static function isLocked(string $name): bool
    {
        return self::sql('SELECT IS_USED_LOCK(?)', [self::lockKey($name)])->fetchColumn() !== null;
    }

    public static function transaction(callable $work)
    {
        $pdo=_SERVER('db')->connect();
        if($pdo->inTransaction()) return $work();
        $pdo->beginTransaction();
        try { $result=$work(); $pdo->commit(); return $result; }
        catch(\Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
