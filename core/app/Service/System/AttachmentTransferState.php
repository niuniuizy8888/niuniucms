<?php
namespace App\Service\System;

/** Task checkpoints: 0 downloaded, 1 uploaded, 2 upload uncertain, 3 verified. */
class AttachmentTransferState
{
    public static function read(int $job, string $source): ?array
    {
        if ($job<1) return null;
        $row=AttachmentSchema::sql('SELECT path,origin,bytes,sha256,uploaded FROM '.AttachmentSchema::table('attachment_transfers').' WHERE job_id=? AND source_hash=?',[$job,hash('sha256',$source)])->fetch(\PDO::FETCH_ASSOC);
        if ($row) return $row;
        // Older running jobs already saved successful results before checkpoints
        // existed. Keep using that job's image rather than downloading a newer one.
        $assets=AttachmentSchema::table('attachments');
        $legacy=AttachmentSchema::sql('SELECT a.path,a.origin,a.bytes,a.sha256 FROM '.AttachmentSchema::table('attachment_items')." i JOIN $assets a ON a.url_hash=SHA2(i.result_url,256) WHERE i.job_id=? AND i.source_hash=? AND i.state IN ('done','reused','skipped') AND i.asset_id>0 AND a.backend='ftp' AND a.path<>'' AND a.sha256<>'' ORDER BY i.id LIMIT 1",[$job,hash('sha256',$source)])->fetch(\PDO::FETCH_ASSOC);
        if (!$legacy) return null;
        self::downloaded($job,$source,$legacy['path'],$legacy['origin'],$legacy);
        self::verified($job,$source);
        return $legacy+['uploaded'=>3];
    }

    public static function downloaded(int $job, string $source, string $path, string $origin, array $info): void
    {
        if ($job<1) return;
        AttachmentSchema::sql('INSERT INTO '.AttachmentSchema::table('attachment_transfers').' (job_id,source_hash,path,origin,bytes,sha256,uploaded,updated) VALUES (?,?,?,?,?,?,0,?) ON DUPLICATE KEY UPDATE path=VALUES(path),origin=VALUES(origin),bytes=VALUES(bytes),sha256=VALUES(sha256),uploaded=0,updated=VALUES(updated)',[$job,hash('sha256',$source),$path,$origin,$info['bytes'],$info['sha256'],time()]);
    }

    public static function uploaded(int $job, string $source): void
    {
        if ($job<1) return;
        AttachmentSchema::sql('UPDATE '.AttachmentSchema::table('attachment_transfers').' SET uploaded=1,updated=? WHERE job_id=? AND source_hash=?',[time(),$job,hash('sha256',$source)]);
    }

    public static function uploading(int $job, string $source): void
    {
        if ($job<1) return;
        // 2 means STOR may have reached the server but its acknowledgement is not
        // persisted yet. On restart, verify the remote bytes before sending again.
        AttachmentSchema::sql('UPDATE '.AttachmentSchema::table('attachment_transfers').' SET uploaded=2,updated=? WHERE job_id=? AND source_hash=?',[time(),$job,hash('sha256',$source)]);
    }

    public static function verified(int $job, string $source): void
    {
        if ($job<1) return;
        AttachmentSchema::sql('UPDATE '.AttachmentSchema::table('attachment_transfers').' SET uploaded=3,updated=? WHERE job_id=? AND source_hash=?',[time(),$job,hash('sha256',$source)]);
    }

    public static function needsLocal(string $path): bool
    {
        // Different origins can share a content-hashed file. Keep it until every
        // retryable transfer using it has verified its own remote destination.
        return (bool)AttachmentSchema::sql('SELECT 1 FROM '.AttachmentSchema::table('attachment_transfers').' t JOIN '.AttachmentSchema::table('attachment_jobs')." j ON j.id=t.job_id WHERE t.path=? AND t.uploaded<>3 AND (j.state IN ('running','paused') OR (j.state='finished' AND j.failed_count>0)) LIMIT 1",[$path])->fetchColumn();
    }
}
