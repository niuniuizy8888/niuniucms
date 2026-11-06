<?php
namespace App\Service\System;

/** Persist counters alongside item changes; polling never recounts an entire job. */
class AttachmentProgress
{
    const STATES=['pending','done','failed','skipped','reused'];
    const JOB_COLS='id,options,state,phase,scan_cursor,scanned,created,updated,stats_ready,pending_count,done_count,failed_count,skipped_count,reused_count';

    public static function initialize(int $id): void
    {
        AttachmentSchema::lock('job:'.$id,static function() use($id) {
            $jobs=AttachmentSchema::table('attachment_jobs');
            if((int)AttachmentSchema::sql("SELECT stats_ready FROM $jobs WHERE id=?",[$id])->fetchColumn()) return;
            AttachmentSchema::transaction(static function() use($id,$jobs) {
                $counts=array_fill_keys(self::STATES,0);
                foreach(AttachmentSchema::sql('SELECT state,COUNT(*) AS n FROM '.AttachmentSchema::table('attachment_items').' WHERE job_id=? GROUP BY state',[$id])->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if(isset($counts[$row['state']])) $counts[$row['state']]=(int)$row['n'];
                }
                $set=implode(',',array_map(static fn($state)=>$state.'_count=?',self::STATES));
                AttachmentSchema::sql("UPDATE $jobs SET $set,stats_ready=1 WHERE id=?",array_merge(array_values($counts),[$id]));
            });
        });
    }

    public static function counts(array $job): array
    {
        $out=[];
        foreach(self::STATES as $state) $out[$state]=(int)$job[$state.'_count'];
        return $out;
    }

    /** Inserts are grouped so replayed scan batches cannot inflate counters. */
    public static function insert(array $job, array $rows): int
    {
        if(!$rows) return 0;
        $ready=$job['options']['target']==='local'&&empty($job['options']['redownload']) ? AttachmentService::availableLocal(array_column($rows,'source')) : [];
        $groups=[];
        foreach($rows as $row) {
            $asset=$ready[$row['source']]??null;
            $state=$asset?'reused':'pending';
            $groups[$state][]=[(int)$job['id'],$row['kind'],$row['record_id'],$row['field'],$row['title'],$row['source'],hash('sha256',$row['source']),$asset['id']??($row['asset_id']??0),$state,$asset?$asset['url']:null,$asset?time():0,$asset?'本地图片已就绪，本次未下载':''];
        }
        return AttachmentSchema::transaction(static function() use($job,$groups) {
            $added=0; $items=AttachmentSchema::table('attachment_items'); $jobs=AttachmentSchema::table('attachment_jobs');
            foreach($groups as $state=>$rows) foreach(array_chunk($rows,200) as $batch) {
                $values=[]; foreach($batch as $row) array_push($values,...$row);
                $sql="INSERT IGNORE INTO $items (job_id,kind,record_id,field,title,source,source_hash,asset_id,state,result_url,finished_at,error) VALUES ".implode(',',array_fill(0,count($batch),'('.implode(',',array_fill(0,12,'?')).')'));
                $n=AttachmentSchema::sql($sql,$values)->rowCount();
                if($n) {
                    AttachmentSchema::sql("UPDATE $items SET sort_id=IF(state='pending',-CAST(id AS SIGNED),CAST(id AS SIGNED)) WHERE job_id=? AND sort_id=0",[$job['id']]);
                    AttachmentSchema::sql("UPDATE $jobs SET {$state}_count={$state}_count+?,updated=? WHERE id=?",[$n,time(),$job['id']]);
                }
                $added+=$n;
            }
            return $added;
        });
    }

    public static function finish(int $jobId, int $itemId, string $state, array $result): void
    {
        if(!in_array($state,['done','failed','skipped','reused'],true)) throw new \InvalidArgumentException('图片处理结果无效');
        AttachmentSchema::transaction(static function() use($jobId,$itemId,$state,$result) {
            $items=AttachmentSchema::table('attachment_items');$jobs=AttachmentSchema::table('attachment_jobs');
            $changed=AttachmentSchema::sql("UPDATE $items SET state=?,asset_id=?,result_url=?,error=?,finished_at=?,elapsed_ms=?,sort_id=CAST(id AS SIGNED) WHERE id=? AND job_id=? AND state='pending'",[$state,$result['asset_id']??0,$result['url']??null,$result['error']??'',time(),$result['elapsed_ms']??0,$itemId,$jobId])->rowCount();
            if($changed) AttachmentSchema::sql("UPDATE $jobs SET pending_count=pending_count-1,{$state}_count={$state}_count+1,updated=? WHERE id=?",[time(),$jobId]);
        });
    }

    public static function retry(int $jobId, int $itemId): int
    {
        return AttachmentSchema::transaction(static function() use($jobId,$itemId) {
            $items=AttachmentSchema::table('attachment_items');$jobs=AttachmentSchema::table('attachment_jobs');
            $n=AttachmentSchema::sql("UPDATE $items SET state='pending',error='',started_at=0,finished_at=0,elapsed_ms=0,sort_id=-CAST(id AS SIGNED) WHERE job_id=? AND state='failed'".($itemId>0?' AND id=?':''),$itemId>0?[$jobId,$itemId]:[$jobId])->rowCount();
            if($n) AttachmentSchema::sql("UPDATE $jobs SET failed_count=failed_count-?,pending_count=pending_count+?,updated=? WHERE id=?",[$n,$n,time(),$jobId]);
            return $n;
        });
    }
}
