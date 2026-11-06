<?php
namespace App\Service\System;

class AttachmentJobService
{
    public static function options(array $input): array
    {
        $assets = array_values(array_unique(array_filter(array_map('intval',(array)($input['assets']??[])),static fn($id)=>$id>0)));
        if (count($assets)>500) throw new \InvalidArgumentException('每次最多选择 500 张图片');
        sort($assets, SORT_NUMERIC);
        // Selected attachments carry their saved references; unrelated bulk filters must
        // not exclude an article body image or expand the selection to other pictures.
        if ($assets) $input = array_replace($input, ['fields'=>['vod.pic','vod.pic_slide','art.pic','art.content'], 'ids'=>'', 'cid'=>0, 'since'=>'']);
        $fields = array_values(array_intersect(['vod.pic','vod.pic_slide','art.pic','art.content'], (array)($input['fields'] ?? ['vod.pic','vod.pic_slide','art.pic'])));
        if (!$fields) throw new \InvalidArgumentException('请至少选择一种图片');
        $target = (string)($input['target'] ?? 'local');
        if (!in_array($target,['index','local','ftp'],true)) throw new \InvalidArgumentException('同步目标无效');
        $ids = preg_split('/[\s,，]+/u', trim((string)($input['ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($ids as $id) if (!ctype_digit($id) || (int)$id < 1) throw new \InvalidArgumentException('内容编号请填写正整数，以逗号分隔');
        if (count($ids) > 500) throw new \InvalidArgumentException('每次最多选择 500 个内容编号');
        $since = trim((string)($input['since'] ?? ''));
        if ($since !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$since) || strtotime($since) === false)) throw new \InvalidArgumentException('日期格式无效');
        return ['fields'=>$fields,'target'=>$target,'redownload'=>$target!=='index'&&!empty($input['redownload']), 'cid'=>max(0,(int)($input['cid']??0)), 'since'=>$since ? strtotime($since):0, 'ids'=>array_map('intval',$ids), 'assets'=>$assets, 'keep_local'=>empty($input['delete_local'])?1:0, 'mode'=>($input['mode']??'page')==='background'?'background':'page'];
    }

    private static function where(array $o, string $kind, int $cursor, int $max): array
    {
        $pk = $kind==='vod'?'vid':'aid';
        $sql = "`$pk` > ? AND `$pk` <= ?";
        $args = [$cursor,$max];
        if ($o['cid']) { $sql .= ' AND cid=?'; $args[]=$o['cid']; }
        if ($o['since']) { $sql .= ' AND create_date>=?'; $args[]=$o['since']; }
        if ($o['ids']) { $sql .= " AND `$pk` IN (" . implode(',',array_fill(0,count($o['ids']),'?')) . ')'; $args = array_merge($args,$o['ids']); }
        return [$sql,$args];
    }

    public static function preview(array $input): array
    {
        AttachmentSchema::ensure();
        $o = self::options($input);
        if ($o['assets']) return self::selectedPreview($o);
        $counts=[];
        foreach (['vod'=>'vid','art'=>'aid'] as $kind=>$pk) {
            $selected = array_filter($o['fields'],static fn($f)=>str_starts_with($f,$kind.'.'));
            $max = (int)AttachmentSchema::sql('SELECT COALESCE(MAX(`'.$pk.'`),0) FROM '.AttachmentSchema::table($kind))->fetchColumn();
            [$where,$args]=self::where($o,$kind,0,$max);
            $o['max_'.$kind]=$max;
            $counts[$kind]=$selected ? (int)AttachmentSchema::sql('SELECT COUNT(*) FROM '.AttachmentSchema::table($kind).' WHERE '.$where,$args)->fetchColumn():0;
        }
        $o['records']=array_sum($counts);
        return ['options'=>$o,'counts'=>$counts,'message'=>'预计检查 '.$counts['vod'].' 条视频、'.$counts['art'].' 篇文章；图片数量将在扫描时统计'.($o['redownload']?'，从原始地址重新下载；失败保留原图':($o['target']==='local'?'，已有且可用的本地图片直接标为已就绪，只补下载缺失图片':''))];
    }

    private static function selectedPreview(array $o): array
    {
        $marks = implode(',', array_fill(0,count($o['assets']),'?'));
        $assets = AttachmentSchema::sql('SELECT id,url,backend FROM '.AttachmentSchema::table('attachments').' WHERE id IN ('.$marks.') ORDER BY id', $o['assets'])->fetchAll(\PDO::FETCH_ASSOC);
        if (count($assets) !== count($o['assets'])) throw new \InvalidArgumentException('部分选中的图片已不存在，请刷新列表后重试');
        $table = AttachmentSchema::table('attachment_items');
        $where = "(a.id IN ($marks) OR (a.backend='external' AND a.stored_id IN ($marks))) AND i.state IN ('done','reused') AND i.kind IN ('vod','art')";
        $owner = "CASE WHEN a.backend='external' AND a.stored_id IN (".implode(',',$o['assets']).") THEN a.stored_id ELSE a.id END";
        $rows = AttachmentSchema::sql('SELECT '.$owner.' AS asset_id,i.kind,COUNT(DISTINCT i.record_id,i.field) AS refs,COUNT(DISTINCT i.record_id) AS records,MAX(i.id) AS last_id FROM '.$table.' i JOIN '.AttachmentSchema::table('attachments').' a ON a.id=i.asset_id WHERE '.$where.' GROUP BY '.$owner.',i.kind', array_merge($o['assets'],$o['assets']))->fetchAll(\PDO::FETCH_ASSOC);
        $counts=['vod'=>0,'art'=>0]; $known=[]; $o['selection_ref_max']=0; $o['records']=0;
        foreach ($rows as $row) {
            $known[(int)$row['asset_id']]=true;
            $counts[$row['kind']]+=(int)$row['records'];
            $o['records']+=(int)$row['refs'];
            $o['selection_ref_max']=max($o['selection_ref_max'],(int)$row['last_id']);
        }
        $o['selection_unlinked']=array_values(array_filter($o['assets'],static fn($id)=>!isset($known[$id])));
        $o['records']+=count($o['selection_unlinked']);
        return ['options'=>$o,'counts'=>$counts,'selected'=>$assets,'message'=>'已选择 '.count($assets).' 张图片，使用已保存的扫描信息（'.$counts['vod'].' 条视频关联、'.$counts['art'].' 条文章关联）'.($o['selection_unlinked']?'；其中 '.count($o['selection_unlinked']).' 张暂无关联记录，仅同步文件':'')];
    }

    public static function create(array $input): array
    {
        $preview=self::preview($input);
        $o=$preview['options'];
        $o['work_version']=2;
        $c=AttachmentStorage::settings(true);
        if ($o['target']==='ftp') {
            $c['backend']='ftp';
            $c=AttachmentStorage::normalize($c);
        }
        $signatureOptions=$o['assets'] ? self::options($input) : $o;
        $sig=hash('sha256',json_encode([$signatureOptions,$c]));
        return AttachmentSchema::lock('create:'.$sig,static function() use($o,$c,$sig) {
            $table=AttachmentSchema::table('attachment_jobs');
            $row=AttachmentSchema::sql('SELECT id FROM '.$table.' WHERE signature=? AND state IN (\'running\',\'paused\') ORDER BY id DESC LIMIT 1',[$sig])->fetch(\PDO::FETCH_ASSOC);
            if($row) return self::status((int)$row['id']);
            $o['storage']=xn_encrypt(json_encode($c));
            AttachmentSchema::sql('INSERT INTO '.$table.' (signature,options,state,stats_ready,created,updated) VALUES (?,?,\'running\',1,?,?)',[$sig,json_encode($o),time(),time()]);
            $id=(int)_SERVER('db')->connect()->lastInsertId();
            if($o['mode']==='background') TaskQueueService::enqueue('attachment_sync',['job'=>$id]);
            return self::status($id);
        });
    }

    private static function job(int $id): array
    {
        AttachmentSchema::ensure();
        $row=AttachmentSchema::sql('SELECT '.AttachmentProgress::JOB_COLS.' FROM '.AttachmentSchema::table('attachment_jobs').' WHERE id=?',[$id])->fetch(\PDO::FETCH_ASSOC);
        if(!$row) throw new \InvalidArgumentException('图片任务不存在');
        if(!(int)$row['stats_ready']) { AttachmentProgress::initialize($id); return self::job($id); }
        $row['options']=json_decode($row['options'],true);
        return $row;
    }

    private static function summary(array $job): array
    {
        $counts=AttachmentProgress::counts($job);
        foreach(AttachmentProgress::STATES as $state) unset($job[$state.'_count']);
        unset($job['stats_ready'],$job['options']['storage'],$job['options']['selection_assets'],$job['options']['selection_unlinked']);
        return $job+['counts'=>$counts,'errors'=>[],'total'=>array_sum($counts),'work_total'=>array_sum($counts)-$counts['reused']];
    }

    public static function status(int $id, bool $withDetails = true): array
    {
        $job=self::job($id);
        $result=self::summary($job);
        if ($withDetails) {
            $result['errors']=AttachmentSchema::sql('SELECT id,title,error FROM '.AttachmentSchema::table('attachment_items').' WHERE job_id=? AND state=\'failed\' ORDER BY id DESC LIMIT 10',[$id])->fetchAll(\PDO::FETCH_ASSOC);
            $result['busy']=AttachmentSchema::isLocked('job:'.$id);
            $next=AttachmentSchema::sql('SELECT id,title,source,kind,field,started_at FROM '.AttachmentSchema::table('attachment_items')." WHERE job_id=? AND state='pending' ORDER BY id LIMIT 1",[$id])->fetch(\PDO::FETCH_ASSOC);
            $result['current']=$next && $result['busy'] && (int)$next['started_at']>0 ? $next : null;
            $result['next']=$next ?: null;
            $result['server_time']=time();
            $result['details']=self::details($id,[],$job);
        }
        return $result;
    }

    public static function details(int $id, array $query, ?array $job = null): array
    {
        $job=$job??self::job($id);
        $page=max(1,(int)($query['page']??1));
        $state=(string)($query['state']??''); $where='i.job_id=?'; $args=[$id];
        $filtered=in_array($state,AttachmentProgress::STATES,true);
        $legacySkipped=$state==='skipped' && !empty($query['legacy_states']);
        if($legacySkipped) $where.=" AND i.state IN ('skipped','reused')";
        elseif($filtered) { $where.=' AND i.state=?'; $args[]=$state; }
        $items=AttachmentSchema::table('attachment_items');
        $counts=AttachmentProgress::counts($job);$total=$filtered?$counts[$state]:array_sum($counts);
        if($legacySkipped) $total+=$counts['reused'];
        $cursor=isset($query['cursor'])&&$query['cursor']!==''?filter_var($query['cursor'],FILTER_VALIDATE_INT):null;
        if($cursor===false) throw new \InvalidArgumentException('明细位置无效，请返回最新结果');
        if(!array_key_exists('cursor',$query) && $page>1) {
            // Compatibility for already-open tabs that still send page numbers only.
            // Locate one anchor using the covering index; new clients use cursors.
            if($page>100) throw new \InvalidArgumentException('页面已更新，请刷新后使用新的明细翻页；任务进度已保留');
            $anchor=AttachmentSchema::sql("SELECT i.sort_id FROM $items i WHERE $where ORDER BY i.sort_id DESC LIMIT 1 OFFSET ".(($page-1)*20-1),$args)->fetchColumn();
            if($anchor!==false) $cursor=(int)$anchor;
        }
        $previous=($query['direction']??'next')==='prev' && $cursor!==null;
        if($cursor!==null) { $where.=$previous?' AND i.sort_id>?':' AND i.sort_id<?';$args[]=$cursor; }
        else $page=1;
        $order=$previous?'ASC':'DESC';
        $rows=AttachmentSchema::sql('SELECT i.id,i.sort_id,i.kind,i.record_id,i.field,i.title,i.source,i.state,i.error,i.started_at,i.finished_at,i.elapsed_ms,COALESCE(i.result_url,a.url) AS result_url,a.backend,a.bytes FROM '.$items.' i LEFT JOIN '.AttachmentSchema::table('attachments').' a ON a.id=i.asset_id WHERE '.$where.' ORDER BY i.sort_id '.$order.' LIMIT 21',$args)->fetchAll(\PDO::FETCH_ASSOC);
        $more=count($rows)>20;if($more)array_pop($rows);if($previous)$rows=array_reverse($rows);
        return ['rows'=>$rows,'page'=>$page,'total'=>$total,'target'=>$job['options']['target'],'has_prev'=>$previous?$more:$cursor!==null,'has_next'=>$previous?$cursor!==null:$more,'prev_cursor'=>$rows?$rows[0]['sort_id']:null,'next_cursor'=>$rows?end($rows)['sort_id']:null];
    }

    public static function control(int $id, string $action, int $itemId = 0): array
    {
        $job=self::job($id);
        $table=AttachmentSchema::table('attachment_jobs');
        if($action==='pause') AttachmentSchema::sql("UPDATE $table SET state='paused',updated=? WHERE id=? AND state='running'",[time(),$id]);
        elseif($action==='stop') AttachmentSchema::sql("UPDATE $table SET state='stopped',updated=? WHERE id=? AND state IN ('running','paused')",[time(),$id]);
        elseif(in_array($action,['resume','retry'],true)) {
            AttachmentSchema::lock('job:'.$id,static function() use($id,$action,$table,$itemId) {
                $current=self::job($id);
                if($current['state']==='stopped') throw new \RuntimeException('已停止的任务不能继续，请重新创建');
                if($action==='retry') {
                    $changed=AttachmentProgress::retry($id,$itemId);
                    if($itemId>0 && !$changed) throw new \InvalidArgumentException('这张图片不属于当前任务的失败记录，请刷新进度');
                }
                AttachmentSchema::sql("UPDATE $table SET state='running',updated=? WHERE id=?",[time(),$id]);
            });
            if($job['options']['mode']==='background') TaskQueueService::enqueue('attachment_sync',['job'=>$id]);
        } else throw new \InvalidArgumentException('操作无效');
        return self::status($id);
    }

    private static function scan(array $job): void
    {
        $id=(int)$job['id']; $o=$job['options'];
        if (isset($o['selection_ref_max'])) { self::scanSelected($job); return; }
        $kind=(int)$job['phase']===0?'vod':'art'; $pk=$kind==='vod'?'vid':'aid';
        $fields=array_map(static fn($f)=>explode('.',$f)[1],array_filter($o['fields'],static fn($f)=>str_starts_with($f,$kind.'.')));
        [$where,$args]=self::where($o,$kind,(int)$job['scan_cursor'],(int)$o['max_'.$kind]);
        $rows=$fields ? AttachmentSchema::sql('SELECT `'.$pk.'`,name,`'.implode('`,`',$fields).'` FROM '.AttachmentSchema::table($kind).' WHERE '.$where.' ORDER BY `'.$pk.'` LIMIT 100',$args)->fetchAll(\PDO::FETCH_ASSOC):[];
        $selected=[];
        if($o['assets']) {
            $marks=implode(',',array_fill(0,count($o['assets']),'?'));
            foreach(AttachmentSchema::sql('SELECT url FROM '.AttachmentSchema::table('attachments').' WHERE id IN ('.$marks.')',$o['assets'])->fetchAll(\PDO::FETCH_ASSOC) as $asset) $selected[$asset['url']]=true;
        }
        $newItems=[];
        foreach($rows as $row) {
            foreach($fields as $field) {
                $urls=$field==='content'?AttachmentService::images((string)$row[$field]):[(string)$row[$field]];
                foreach($urls as $source) {
                    if($source==='' || ($o['assets'] && !isset($selected[$source]))) continue;
                    $newItems[]=['kind'=>$kind,'record_id'=>$row[$pk],'field'=>$field,'title'=>$row['name'],'source'=>$source];
                }
            }
        }
        AttachmentProgress::insert($job,$newItems);
        $table=AttachmentSchema::table('attachment_jobs');
        if(!$rows) AttachmentSchema::sql("UPDATE $table SET phase=phase+1,scan_cursor=0,updated=? WHERE id=?",[time(),$id]);
        else AttachmentSchema::sql("UPDATE $table SET scan_cursor=?,scanned=scanned+?,updated=? WHERE id=?",[(int)end($rows)[$pk],count($rows),time(),$id]);
    }

    private static function scanSelected(array $job): void
    {
        $id=(int)$job['id']; $o=$job['options']; $cursor=(int)$job['scan_cursor'];
        $items=AttachmentSchema::table('attachment_items'); $assets=AttachmentSchema::table('attachments');
        $marks=implode(',',array_fill(0,count($o['assets']),'?'));
        $added=0;
        $newItems=[];
        if ($cursor===0 && $o['selection_unlinked']) {
            $missing=implode(',',array_fill(0,count($o['selection_unlinked']),'?'));
            foreach(AttachmentSchema::sql("SELECT id,url FROM $assets WHERE id IN ($missing)",$o['selection_unlinked'])->fetchAll(\PDO::FETCH_ASSOC) as $asset) $newItems[]=['kind'=>'asset','record_id'=>$asset['id'],'field'=>'url','title'=>'附件 #'.$asset['id'],'source'=>$asset['url'],'asset_id'=>$asset['id']];
        }
        $vod=AttachmentSchema::table('vod'); $art=AttachmentSchema::table('art');
        $rows=AttachmentSchema::sql("SELECT i.id,i.kind,i.record_id,i.field,i.title,a.url AS source,COALESCE(saved.url,a.url) AS saved_url,i.asset_id,CASE WHEN i.kind='vod' THEN IF(i.field='pic_slide',v.pic_slide,v.pic) ELSE IF(i.field='content',r.content,r.pic) END AS current_value FROM $items i JOIN $assets a ON a.id=i.asset_id LEFT JOIN $assets saved ON a.backend='external' AND saved.id=a.stored_id LEFT JOIN $vod v ON i.kind='vod' AND v.vid=i.record_id LEFT JOIN $art r ON i.kind='art' AND r.aid=i.record_id WHERE (a.id IN ($marks) OR (a.backend='external' AND a.stored_id IN ($marks))) AND i.state IN ('done','reused') AND i.kind IN ('vod','art') AND i.id>? AND i.id<=? ORDER BY i.id LIMIT 100",array_merge($o['assets'],$o['assets'],[$cursor,$o['selection_ref_max']]))->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $current=$row['field']==='content'?AttachmentService::images((string)$row['current_value']):[(string)$row['current_value']];
            foreach (array_unique([$row['source'],$row['saved_url']]) as $source) {
                if(!in_array($source,$current,true)) continue;
                $newItems[]=['kind'=>$row['kind'],'record_id'=>$row['record_id'],'field'=>$row['field'],'title'=>$row['title'],'source'=>$source,'asset_id'=>$row['asset_id']];
            }
        }
        $added+=AttachmentProgress::insert($job,$newItems);
        if(!$rows) {
            // Saved scan history may now refer only to deleted/edited content.
            // The selected file must still be processed even without a live content row.
            $unlinked=AttachmentSchema::sql("SELECT a.id,a.url FROM $assets a WHERE a.id IN ($marks) AND NOT EXISTS (SELECT 1 FROM $items i WHERE i.job_id=? AND (i.source_hash=a.url_hash OR i.asset_id=a.id))",array_merge($o['assets'],[$id]))->fetchAll(\PDO::FETCH_ASSOC);
            $added+=AttachmentProgress::insert($job,array_map(static fn($a)=>['kind'=>'asset','record_id'=>$a['id'],'field'=>'url','title'=>'附件 #'.$a['id'],'source'=>$a['url'],'asset_id'=>$a['id']],$unlinked));
        }
        $last=$rows ? (int)end($rows)['id'] : $cursor;
        AttachmentSchema::sql('UPDATE '.AttachmentSchema::table('attachment_jobs').' SET phase=?,scan_cursor=?,scanned=scanned+?,updated=? WHERE id=?',[$rows?0:2,$last,$added,time(),$id]);
    }

    private static function apply(array $item,array $asset): bool
    {
        return AttachmentService::withReference($asset, static fn()=>self::applyReference($item,$asset));
    }

    private static function applyReference(array $item,array $asset): bool
    {
        $kind=$item['kind']; $pk=$kind==='vod'?'vid':'aid'; $field=$item['field'];
        if(!in_array($kind.'.'.$field,['vod.pic','vod.pic_slide','art.pic','art.content'],true)) throw new \RuntimeException('图片引用类型无效');
        $table=AttachmentSchema::table($kind);
        $current=AttachmentSchema::sql('SELECT `'.$field.'` FROM '.$table.' WHERE `'.$pk.'`=?',[$item['record_id']])->fetchColumn();
        if($current===false) return false;
        if($field==='content') {
            $next=AttachmentService::images($current,static fn($url)=>$url===$item['source']?$asset['url']:$url);
            if($next===$current) return in_array($asset['url'],AttachmentService::images($current),true);
        } else {
            if($current!==$item['source']) return $current===$asset['url'];
            $next=$asset['url'];
        }
        if($next!==$current) {
            $updated=AttachmentSchema::sql('UPDATE '.$table.' SET `'.$field.'`=? WHERE `'.$pk.'`=? AND BINARY `'.$field.'`=BINARY ?',[$next,$item['record_id'],$current])->rowCount();
            if(!$updated) return false;
            content_cache_delete($kind.'_', (int)$item['record_id']);
            page_cache_bump(true);
        }
        return true;
    }

    public static function step(int $id): array
    {
        AttachmentSchema::ensure();
        return AttachmentSchema::lock('job:'.$id,static function() use($id) {
            $job=self::job($id);
            if($job['state']!=='running') return self::status($id);
            if((int)$job['phase']<2) { self::scan($job); return self::status($id); }
            $o=$job['options'];
            $c=json_decode((string)xn_decrypt($o['storage']),true);
            if(!is_array($c)) throw new \RuntimeException('任务存储配置无法解密，请重新创建任务');
            $c['keep_local']=$o['keep_local'];
            $items=AttachmentSchema::table('attachment_items');
            $limit=$o['target']==='index'?50:($o['target']==='local'?100:1);
            $rows=AttachmentSchema::sql("SELECT id,kind,record_id,field,title,source FROM $items WHERE job_id=? AND state='pending' ORDER BY id LIMIT $limit",[$id])->fetchAll(\PDO::FETCH_ASSOC);
            $ready=$o['target']==='local'&&empty($o['redownload'])?AttachmentService::availableLocal(array_column($rows,'source')):[];
            $batchStarted=microtime(true);
            foreach($rows as $item) {
                $started=microtime(true);
                $mayDownload=$o['target']!=='index'&&!isset($ready[$item['source']]);
                AttachmentSchema::sql("UPDATE $items SET started_at=?,finished_at=0,elapsed_ms=0 WHERE id=?",[time(),$item['id']]);
                try {
                    if($o['target']==='index') { $asset=AttachmentService::index($item['source']); $ok=true; }
                    else {
                        $asset=$ready[$item['source']]??null;
                        if($asset) $asset['reused']=true;
                        // One source may serve many contents. An explicit refresh downloads
                        // it once per job, including across pauses and separate HTTP requests.
                        if(!$asset && !empty($o['redownload'])) {
                            $previous=AttachmentSchema::sql("SELECT result_url FROM $items WHERE job_id=? AND source_hash=? AND state IN ('done','reused','skipped') AND asset_id>0 AND result_url IS NOT NULL ORDER BY state,id LIMIT 1",[$id,hash('sha256',$item['source'])])->fetchColumn();
                            if($previous) $asset=AttachmentService::transfer($previous,$o['target'],$c);
                        }
                        if(!$asset) $asset=AttachmentService::transfer($item['source'],$o['target'],$c,null,!empty($o['redownload']));
                        $ok=$item['kind']==='asset' || self::apply($item,$asset);
                    }
                    if($ok && $o['target']==='ftp' && !$o['keep_local']) AttachmentService::cleanupLocal($asset);
                    $reused=$ok&&$o['target']!=='index'&&!empty($asset['reused']);
                    AttachmentProgress::finish($id,(int)$item['id'],$ok?($reused?'reused':'done'):'skipped',['asset_id'=>$asset['id'],'url'=>$asset['url'],'error'=>$ok?($reused?'已有文件可用，本次未重复下载或上传':''):'内容已修改或删除，保留当前图片','elapsed_ms'=>(int)round((microtime(true)-$started)*1000)]);
                } catch(\Throwable $e) {
                    xn_log('attachment item #'.$item['id'].' failed: '.$e->getMessage(),'attachment_error');
                    AttachmentProgress::finish($id,(int)$item['id'],'failed',['error'=>mb_substr($e->getMessage(),0,450),'elapsed_ms'=>(int)round((microtime(true)-$started)*1000)]);
                }
                // Old paused tasks may still queue thousands of existing local files.
                // Settle those in bounded batches, but allow at most one network transfer.
                if($mayDownload || microtime(true)-$batchStarted>=2) break;
            }
            $pending=(int)AttachmentSchema::sql('SELECT pending_count FROM '.AttachmentSchema::table('attachment_jobs').' WHERE id=?',[$id])->fetchColumn();
            AttachmentSchema::sql('UPDATE '.AttachmentSchema::table('attachment_jobs').' SET state=?,updated=? WHERE id=? AND state=\'running\'',[$pending?'running':'finished',time(),$id]);
            return self::status($id);
        });
    }

    public static function worker(array $params): bool { return self::step((int)($params['job']??0))['state']!=='running'; }

    public static function overview(array $query): array
    {
        AttachmentSchema::ensure();
        $page=max(1,(int)($query['page']??1));
        $filter=(string)($query['backend']??'');
        $table=AttachmentSchema::table('attachments');
        $q=trim((string)($query['q']??'')); $args=[];
        $where="(a.backend<>'external' OR a.stored_id=0 OR NOT EXISTS (SELECT 1 FROM $table saved WHERE saved.id=a.stored_id))";
        if(in_array($filter,['external','local','ftp'],true)) { $where.=' AND a.backend=?';$args[]=$filter; }
        if($q!=='') { $where.=" AND (a.url LIKE ? OR a.origin LIKE ? OR EXISTS (SELECT 1 FROM $table original WHERE original.stored_id=a.id AND original.url LIKE ?))";$args[]='%'.$q.'%';$args[]='%'.$q.'%';$args[]='%'.$q.'%'; }
        $assets=AttachmentSchema::sql('SELECT a.'.str_replace(',',',a.',AttachmentService::COLS).' FROM '.$table.' a WHERE '.$where.' ORDER BY a.id DESC LIMIT 24 OFFSET '.(($page-1)*24),$args)->fetchAll(\PDO::FETCH_ASSOC);
        $originals=[];
        if($assets) {
            $marks=implode(',',array_fill(0,count($assets),'?'));
            foreach(AttachmentSchema::sql("SELECT stored_id,MIN(url) AS source_url,COUNT(*) AS source_count FROM $table WHERE backend='external' AND stored_id IN ($marks) GROUP BY stored_id",array_column($assets,'id'))->fetchAll(\PDO::FETCH_ASSOC) as $row) $originals[$row['stored_id']]=$row;
        }
        foreach($assets as &$asset) {
            $asset['display_url']=AttachmentService::resolve($asset['url']);
            $asset['local_exists']=$asset['path']!=='' && is_file(AttachmentStorage::absolute($asset['path']));
            $asset['source_url']=$originals[$asset['id']]['source_url']??$asset['origin'];
            $asset['source_count']=(int)($originals[$asset['id']]['source_count']??0);
        } unset($asset);
        $refs=[];
        if($assets) {
            $marks=implode(',',array_fill(0,count($assets),'?'));
            $owner="CASE WHEN a.backend='external' AND a.stored_id>0 THEN a.stored_id ELSE a.id END";
            $refs=AttachmentSchema::sql('SELECT '.$owner.' AS asset_id,MIN(i.title) AS title,COUNT(DISTINCT i.kind,i.record_id) AS reference_count,GROUP_CONCAT(DISTINCT CONCAT(i.kind,\'.\',i.field) ORDER BY i.kind,i.field) AS types FROM '.AttachmentSchema::table('attachment_items')." i JOIN $table a ON a.id=i.asset_id WHERE (a.id IN ($marks) OR (a.backend='external' AND a.stored_id IN ($marks))) AND i.kind IN ('vod','art') GROUP BY ".$owner,array_merge(array_column($assets,'id'),array_column($assets,'id')))->fetchAll(\PDO::FETCH_ASSOC);
        }
        $jobs=[];
        foreach(AttachmentSchema::sql('SELECT '.AttachmentProgress::JOB_COLS.' FROM '.AttachmentSchema::table('attachment_jobs').' ORDER BY id DESC LIMIT 12')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if(!(int)$row['stats_ready']) $row=self::job((int)$row['id']);
            else $row['options']=json_decode($row['options'],true);
            $jobs[]=self::summary($row);
        }
        return ['assets'=>$assets,'references'=>$refs,'total'=>(int)AttachmentSchema::sql('SELECT COUNT(*) FROM '.$table.' a WHERE '.$where,$args)->fetchColumn(),'page'=>$page,'jobs'=>$jobs];
    }
}
