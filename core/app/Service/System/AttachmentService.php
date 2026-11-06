<?php
namespace App\Service\System;

class AttachmentService
{
    const COLS = 'id,url,origin,path,backend,bytes,sha256,created,stored_id';

    public static function resolve(string $url): string
    {
        $path = AttachmentStorage::localPath($url);
        return $path !== '' ? AttachmentStorage::localUrl($path) : $url;
    }

    /** Only rewrite image attributes; text and links in article bodies stay untouched. */
    public static function images(string $html, ?callable $replace = null)
    {
        $found = [];
        $out = preg_replace_callback('~<img\b[^>]*>~i', static function ($tag) use (&$found, $replace) {
            return preg_replace_callback('~(\s(?:src|data-src)\s*=\s*)(["\'])(.*?)\2~is', static function ($m) use (&$found, $replace) {
                $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($url === '' || str_starts_with($url, 'data:')) return $m[0];
                $found[$url] = $url;
                $new = $replace ? $replace($url) : $url;
                return $new === $url ? $m[0] : $m[1] . $m[2] . htmlspecialchars($new, ENT_QUOTES, 'UTF-8') . $m[2];
            }, $tag[0]);
        }, $html);
        return $replace ? $out : array_values($found);
    }

    public static function record(string $url, string $origin = '', string $path = '', string $backend = 'external', array $info = [], array $profile = []): array
    {
        AttachmentSchema::ensure();
        $table = AttachmentSchema::table('attachments');
        AttachmentSchema::sql('INSERT IGNORE INTO ' . $table . ' (url_hash,url,origin_hash,origin,path,backend,profile,bytes,sha256,created) VALUES (?,?,?,?,?,?,?,?,?,?)', [hash('sha256', $url),$url,hash('sha256',$origin),$origin,$path,$backend,$profile ? xn_encrypt(json_encode($profile)) : '',$info['bytes'] ?? 0,$info['sha256'] ?? '',time()]);
        if ($info) AttachmentSchema::sql('UPDATE ' . $table . ' SET origin_hash=?,origin=?,path=?,backend=?,profile=?,bytes=?,sha256=? WHERE url_hash=?', [hash('sha256',$origin),$origin,$path,$backend,$profile ? xn_encrypt(json_encode($profile)) : '',$info['bytes'],$info['sha256'],hash('sha256',$url)]);
        return AttachmentSchema::sql('SELECT ' . self::COLS . ' FROM ' . $table . ' WHERE url_hash=?', [hash('sha256',$url)])->fetch(\PDO::FETCH_ASSOC);
    }

    public static function index(string $source): array
    {
        AttachmentSchema::ensure();
        $existing = AttachmentSchema::sql('SELECT ' . self::COLS . ' FROM ' . AttachmentSchema::table('attachments') . ' WHERE url_hash=?', [hash('sha256',$source)])->fetch(\PDO::FETCH_ASSOC);
        if ($existing) return $existing;
        $path = AttachmentStorage::localPath($source);
        $info = [];
        if ($path !== '') {
            try { $info = AttachmentStorage::imageInfo(AttachmentStorage::absolute($path)); } catch (\Throwable $e) {}
        }
        return self::record($source, $path === '' ? $source : '', $path, $path === '' ? 'external' : 'local', $info);
    }

    /** Only unchanged local references can be settled during scanning without writes. */
    public static function availableLocal(array $sources): array
    {
        $hashes=array_values(array_unique(array_map(static fn($url)=>hash('sha256',$url),$sources)));
        $ready=[];$files=[];
        foreach(array_chunk($hashes,200) as $batch) {
            if(!$batch) continue;
            $marks=implode(',',array_fill(0,count($batch),'?'));
            $rows=AttachmentSchema::sql('SELECT '.self::COLS.' FROM '.AttachmentSchema::table('attachments')." WHERE backend='local' AND url_hash IN ($marks)",$batch)->fetchAll(\PDO::FETCH_ASSOC);
            foreach($rows as $asset) {
                try {
                    $path=$asset['path'];
                    if(!array_key_exists($path,$files)) {
                        $file=AttachmentStorage::absolute($path);clearstatcache(true,$file);
                        $files[$path]=is_file($file)&&is_readable($file)?filesize($file):0;
                    }
                    if($files[$path]>0 && $files[$path]==(int)$asset['bytes']) $ready[$asset['url']]=$asset;
                } catch(\Throwable $e) { /* Leave missing or invalid files for the normal recovery path. */ }
            }
        }
        return $ready;
    }

    public static function transfer(string $source, string $target, array $settings, ?string $prefetched = null, bool $redownload = false): array
    {
        AttachmentSchema::ensure();
        return AttachmentSchema::lock('source:' . hash('sha256', $source), static function () use ($source,$target,$settings,$prefetched,$redownload) {
            $table = AttachmentSchema::table('attachments');
            $existing = AttachmentSchema::sql('SELECT ' . self::COLS . ',profile FROM ' . $table . ' WHERE url_hash=?', [hash('sha256', $source)])->fetch(\PDO::FETCH_ASSOC);
            $saved = !empty($existing['stored_id']) ? AttachmentSchema::sql('SELECT '.self::COLS.',profile FROM '.$table.' WHERE id=?',[$existing['stored_id']])->fetch(\PDO::FETCH_ASSOC) : false;
            // Reuse a completed download. One URL shared by many rows is downloaded once.
            $candidate = AttachmentSchema::sql('SELECT ' . self::COLS . ',profile FROM ' . $table . ' WHERE origin_hash=? AND backend=? ORDER BY id DESC LIMIT 1', [hash('sha256',$source),$target])->fetch(\PDO::FETCH_ASSOC);
            if ($saved && $saved['backend'] === $target) $candidate = $saved;
            $path = $existing['path'] ?? AttachmentStorage::localPath($source);
            $origin = $existing['origin'] ?? '';
            if ($path === '' && $saved) $path = $saved['path'];
            if (!$redownload && $candidate && ($target !== 'ftp' || self::sameFtp($candidate,$settings))) {
                if ($target === 'ftp') {
                    $reused=self::reuseFtp($candidate,$settings);
                    if($reused) return self::rememberSource($source,$reused,$existing);
                }
                if (self::availableLocal([$candidate['url']])) return self::rememberSource($source,$candidate,$existing)+['reused'=>true];
            }
            if (!$redownload && $existing && $existing['backend'] === 'ftp' && $target === 'ftp' && self::sameFtp($existing,$settings) && (!$candidate || $candidate['id']!==$existing['id'])) {
                $reused=self::reuseFtp($existing,$settings);
                if($reused) return $reused;
            }
            $tmp = null;
            try {
                $local = $path !== '' ? AttachmentStorage::absolute($path) : '';
                $info=null;
                if (!$redownload && $local !== '' && is_file($local)) {
                    try { $info = AttachmentStorage::imageInfo($local); } catch (\Throwable $e) { /* Recover invalid copies from their source. */ }
                }
                if ($info===null) {
                    $download=preg_match('~^(?:https?:)?//~i',$origin)&&AttachmentStorage::localPath($origin)===''?$origin:$source;
                    if(!preg_match('~^(?:https?:)?//~i',$download)||AttachmentStorage::localPath($download)!=='') {
                        $original=$existing?AttachmentSchema::sql("SELECT url FROM $table WHERE backend='external' AND stored_id=? ORDER BY id DESC LIMIT 1",[$existing['id']])->fetchColumn():false;
                        if(!$original) throw new \RuntimeException('这张图片没有原始下载地址，无法重新下载；原图已保留，可使用普通同步或重新上传');
                        $download=$original;
                    }
                    $tmp = AttachmentStorage::temporary();
                    if ($prefetched !== null && $prefetched !== '') {
                        if (strlen($prefetched) > AttachmentStorage::MAX_BYTES || file_put_contents($tmp, $prefetched) === false) throw new \RuntimeException('图片预取内容无法保存');
                        $info = AttachmentStorage::imageInfo($tmp);
                    } else $info = AttachmentStorage::download($download, $tmp);
                    $path = 'upload/images/' . substr($info['sha256'],0,2) . '/' . $info['sha256'] . '.' . $info['ext'];
                    $local = AttachmentStorage::absolute($path);
                    if (!is_dir(dirname($local))) mkdir(dirname($local),0755,true);
                    // Identical bytes keep one file. Replace a damaged file only after
                    // its replacement has downloaded and passed image validation.
                    AttachmentSchema::lock('delete:'.$path,static function() use($tmp,$local,$info) {
                        if ((!is_file($local)||hash_file('sha256',$local)!==$info['sha256']) && !rename($tmp,$local)) throw new \RuntimeException('无法保存图片，请检查 upload 目录权限');
                    });
                    $origin = $download;
                }
                if (!preg_match('~^(?:https?:)?//~i',$origin)) $origin = preg_match('~^(?:https?:)?//~i',$source) ? $source : '';
                if ($target === 'ftp') self::rememberSource($source,self::record('/'.$path,$origin,$path,'local',$info),$existing);
                $url = $target === 'ftp' ? AttachmentStorage::upload($settings, $path, $local) : '/' . $path;
                // Even when FTP upload fails, the downloaded local original remains available.
                return self::rememberSource($source,self::record($url, $origin, $path, $target, $info, $target === 'ftp' ? $settings : []),$existing)+['reused'=>$target==='local'&&$tmp===null];
            } finally { if ($tmp && is_file($tmp)) @unlink($tmp); }
        });
    }

    private static function rememberSource(string $source, array $asset, $existing): array
    {
        $table = AttachmentSchema::table('attachments');
        if (!$existing && AttachmentStorage::localPath($source) === '' && preg_match('~^(?:https?:)?//~i',$source)) {
            $existing = self::record($source,$source);
        }
        if ($existing && (int)$existing['id'] !== (int)$asset['id']) {
            AttachmentSchema::sql("UPDATE $table SET stored_id=? WHERE backend='external' AND (id=? OR stored_id=?)",[$asset['id'],$existing['id'],$existing['id']]);
        }
        return $asset;
    }

    private static function sameFtp(array $asset, array $settings): bool
    {
        $profile = json_decode((string)xn_decrypt($asset['profile']),true);
        if (!is_array($profile)) return false;
        foreach (['host','port','user','directory','public_url','tls'] as $key) if (($profile[$key]??null) !== ($settings[$key]??null)) return false;
        return true;
    }

    private static function reuseFtp(array $asset, array $settings): ?array
    {
        try {
            $restored=AttachmentStorage::verify($asset,!empty($settings['keep_local']));
            if($restored) self::record('/'.$asset['path'],$asset['origin'],$asset['path'],'local',['bytes'=>$asset['bytes'],'sha256'=>$asset['sha256']]);
            return $asset+['reused'=>!$restored];
        } catch(\Throwable $e) {
            // A missing/invalid remote copy is repaired through the ordinary upload path.
            xn_log('attachment remote verification failed: '.$e->getMessage(),'attachment_error');
            return null;
        }
    }

    public static function uploaded(string $path): array
    {
        $c = AttachmentStorage::settings(true);
        $info = AttachmentStorage::imageInfo(AttachmentStorage::absolute($path));
        self::record('/'.$path, '', $path, 'local', $info);
        if ($c['backend'] === 'ftp') {
            // The editor has not saved its reference yet: keep the local copy here.
            $asset = self::transfer('/'.$path, 'ftp', $c);
            return ['path'=>$path, 'url'=>$asset['url']];
        }
        return ['path'=>$path, 'url'=>'/'.$path];
    }

    /** Query live references before deleting; an old index is never deletion authority. */
    public static function referenced(array $asset, bool $localOnly = false): bool
    {
        return (bool)self::referenceRows($asset,1,$localOnly);
    }

    private static function matchesReference(string $value, array $asset, bool $localOnly): bool
    {
        $value=html_entity_decode(trim($value),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if($localOnly || $asset['backend']==='local') {
            return $asset['path']!=='' && AttachmentStorage::localPath(preg_split('/[?#]/',$value,2)[0])===$asset['path'];
        }
        if($asset['backend']==='ftp') return preg_split('/[?#]/',$value,2)[0]===$asset['url'];
        return explode('#',$value,2)[0]===explode('#',$asset['url'],2)[0];
    }

    private static function fieldReferences(string $field, string $value, array $asset, bool $localOnly): bool
    {
        if($field==='data') {
            $decoded=json_decode($value,true);
            if(!is_array($decoded)) return true; // Unreadable snapshots must not authorize file deletion.
            $row=$decoded['row']??$decoded;
            if(!is_array($row)) return true;
            foreach(['pic','pic_slide','content'] as $key) if(self::fieldReferences($key,(string)($row[$key]??''),$asset,$localOnly)) return true;
            return false;
        }
        if($field!=='content') return self::matchesReference($value,$asset,$localOnly);
        // Include unquoted attributes and CSS URLs when checking deletion safety.
        // Match entire URLs first so another host with the same filename is independent.
        preg_match_all('~(?:https?:)?//[^\s<>"\'()]+|(?<![a-zA-Z0-9:/])/?upload/[a-zA-Z0-9_./-]+(?:\?[^\s<>"\'()]*)?~i',html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'),$matches);
        foreach(array_unique(array_merge(self::images($value),$matches[0])) as $url) if(self::matchesReference($url,$asset,$localOnly)) return true;
        return false;
    }

    /** Bounded live lookup on demand; do not expand every image card into content queries. */
    private static function referenceRows(array $asset, int $limit, bool $localOnly = false): array
    {
        $needle=$asset['path']!==''?basename($asset['path']):basename((string)(parse_url($asset['url'],PHP_URL_PATH)?:$asset['url']));
        if($needle==='') $needle=(string)parse_url($asset['url'],PHP_URL_HOST);
        if($needle==='') throw new \RuntimeException('图片地址无效，无法确认引用，请先检查附件记录');
        $like='%'.strtr($needle,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $specs=[
            'vod'=>['vid','视频',['pic'=>'封面','pic_slide'=>'横版海报','content'=>'正文']],
            'art'=>['aid','文章',['pic'=>'封面','content'=>'正文']],
            'cate'=>['cid','分类',['content'=>'正文']],
            'ad'=>['id','广告',['img_url'=>'图片']],
            'recycle'=>['id','回收站',['data'=>'内容快照']],
        ];
        $out=[];
        foreach($specs as $kind=>[$pk,$label,$fields]) {
            $conditions=array_map(static fn($field)=>"`$field` LIKE ? ESCAPE '!'",array_keys($fields));
            $args=array_fill(0,count($fields),$like);$cursor=PHP_INT_MAX;
            $select=$kind==='recycle'?'id,type,target_id,data':"`$pk` AS id,name,`".implode('`,`',array_keys($fields)).'`';
            do {
            $rows=AttachmentSchema::sql('SELECT '.$select.' FROM '.AttachmentSchema::table($kind).' WHERE ('.implode(' OR ',$conditions).') AND `'.$pk.'`<? ORDER BY `'.$pk.'` DESC LIMIT 100',array_merge($args,[$cursor]))->fetchAll(\PDO::FETCH_ASSOC);
            foreach($rows as $row) {
                $cursor=(int)$row['id'];$matched=[];
                foreach($fields as $field=>$fieldLabel) if(self::fieldReferences($field,(string)$row[$field],$asset,$localOnly)) $matched[]=$fieldLabel;
                if(!$matched) continue;
                $title=$row['name']??'';$params=[$pk=>(int)$row['id']];$route=$kind.'-update';
                if($kind==='recycle') {
                    $snapshot=json_decode((string)$row['data'],true);$snapshot=$snapshot['row']??$snapshot;
                    $title=is_array($snapshot)?(string)($snapshot['name']??''):'';
                    $params=['type'=>$row['type']];$route='recycle-list';
                } elseif($kind==='art') { $route='art-list';$params=['keyword'=>$title]; }
                elseif($kind==='ad') { $route='ad-list';$params=[]; }
                $out[]=['kind'=>$kind,'label'=>$label,'id'=>(int)$row['id'],'title'=>$title,'fields'=>implode('、',$matched),'target_id'=>(int)($row['target_id']??$row['id']),'url'=>url($route,$params,true)];
                if(count($out)>=$limit) return $out;
            }
            } while(count($rows)===100);
        }
        return $out;
    }

    public static function references(int $id): array
    {
        AttachmentSchema::ensure();
        $asset=AttachmentSchema::sql('SELECT '.self::COLS.' FROM '.AttachmentSchema::table('attachments').' WHERE id=?',[$id])->fetch(\PDO::FETCH_ASSOC);
        if(!$asset) throw new \InvalidArgumentException('图片不存在');
        $rows=self::referenceRows($asset,21);$more=count($rows)>20;if($more) array_pop($rows);
        return ['asset_id'=>$id,'rows'=>$rows,'has_more'=>$more,'blocked'=>(bool)$rows,'message'=>$rows?'这张图片正在被以下内容使用，请先在对应内容中更换或移除图片。仅需重新下载时无需删除。':'未发现内容引用。删除会直接移除图片文件和附件记录，不会进入回收站。'];
    }

    public static function cleanupLocal(array $asset): bool
    {
        if ($asset['backend'] !== 'ftp' || $asset['path'] === '') return false;
        return AttachmentSchema::lock('delete:' . $asset['path'], static function () use ($asset) {
            if (self::referenced($asset, true)) return false;
            $file = AttachmentStorage::absolute($asset['path']);
            return !is_file($file) || unlink($file);
        });
    }

    public static function cleanupCollected(string $url): void
    {
        if (AttachmentStorage::settings()['keep_local']) return;
        $asset = AttachmentSchema::sql('SELECT ' . self::COLS . ' FROM ' . AttachmentSchema::table('attachments') . ' WHERE url_hash=?', [hash('sha256',$url)])->fetch(\PDO::FETCH_ASSOC);
        if ($asset) self::cleanupLocal($asset);
    }

    /** Serialize new references with local-copy cleanup, including concurrent jobs. */
    public static function withReference(array $asset, callable $write)
    {
        return AttachmentSchema::lock('delete:' . $asset['path'], static function () use ($asset,$write) {
            if(!AttachmentSchema::sql('SELECT id FROM '.AttachmentSchema::table('attachments').' WHERE id=?',[$asset['id']])->fetchColumn()) throw new \RuntimeException('图片已被删除，已保留原链接，请重试');
            if ($asset['backend'] === 'local' && !is_file(AttachmentStorage::absolute($asset['path']))) throw new \RuntimeException('本地图片在处理期间发生变化，已保留原链接，请重试');
            return $write();
        });
    }

    public static function withUrlReference(string $url, callable $write)
    {
        $asset = AttachmentSchema::sql('SELECT ' . self::COLS . ' FROM ' . AttachmentSchema::table('attachments') . ' WHERE url_hash=?', [hash('sha256',$url)])->fetch(\PDO::FETCH_ASSOC);
        return $asset ? self::withReference($asset,$write) : $write();
    }

    public static function delete(int $id): array
    {
        AttachmentSchema::ensure();
        return AttachmentSchema::lock('asset-delete:' . $id, static function () use ($id) {
            $table = AttachmentSchema::table('attachments');
            $asset = AttachmentSchema::sql('SELECT ' . self::COLS . ',profile FROM ' . $table . ' WHERE id=?', [$id])->fetch(\PDO::FETCH_ASSOC);
            if (!$asset) throw new \InvalidArgumentException('图片不存在');
            return AttachmentSchema::lock('source:'.hash('sha256',$asset['url']),static function() use($asset,$id,$table) {
            $asset=AttachmentSchema::sql('SELECT '.self::COLS.',profile FROM '.$table.' WHERE id=?',[$id])->fetch(\PDO::FETCH_ASSOC);
            if(!$asset) throw new \InvalidArgumentException('图片不存在');
            return AttachmentSchema::lock('delete:' . $asset['path'], static function () use ($asset,$id,$table) {
            $refs=self::referenceRows($asset,1);
            if($refs) { $r=$refs[0];throw new \RuntimeException('图片被'.$r['label'].' #'.$r['id'].'《'.$r['title'].'》的'.$r['fields'].'引用。请点击“查看引用”定位；重新下载图片无需先删除。'); }
            $jobs=AttachmentSchema::table('attachment_jobs');$items=AttachmentSchema::table('attachment_items');
            $busy=AttachmentSchema::sql("SELECT j.id FROM $jobs j JOIN $items i ON i.job_id=j.id LEFT JOIN $table a ON a.id=i.asset_id WHERE j.state IN ('running','paused') AND i.state='pending' AND (i.source_hash=? OR i.asset_id=? OR (a.path<>'' AND a.path=?) OR a.stored_id=?) LIMIT 1",[hash('sha256',$asset['url']),$id,$asset['path'],$id])->fetchColumn();
            if(!$busy) {
                $busy=AttachmentSchema::sql("SELECT id FROM $jobs WHERE state IN ('running','paused') AND phase<2 AND (JSON_CONTAINS(JSON_EXTRACT(options,'$.assets'),?) OR (state='running' AND COALESCE(JSON_LENGTH(JSON_EXTRACT(options,'$.assets')),0)=0)) LIMIT 1",[(string)$id])->fetchColumn();
            }
            if($busy) throw new \RuntimeException('图片任务 #'.$busy.' 正在使用这张图片或扫描范围，请先完成或停止该任务');
            if ($asset['backend'] === 'ftp') {
                $profile = json_decode((string)xn_decrypt($asset['profile']), true);
                if (!is_array($profile)) throw new \RuntimeException('FTP 配置无法解密，请检查站点密钥');
                AttachmentStorage::removeRemote($profile, $asset['path']);
            }
            $keepLocal=$asset['backend']==='ftp' && (self::referenced($asset,true) || AttachmentSchema::sql("SELECT id FROM $table WHERE id<>? AND backend='local' AND path=? LIMIT 1",[$id,$asset['path']])->fetchColumn());
            if ($asset['path'] !== '' && !$keepLocal) {
                $file = AttachmentStorage::absolute($asset['path']);
                if (is_file($file) && !unlink($file)) throw new \RuntimeException('本地图片删除失败');
            }
            AttachmentSchema::sql('DELETE FROM ' . $table . ' WHERE id=?',[$id]);
            AttachmentSchema::sql('UPDATE '.$table.' SET stored_id=0 WHERE stored_id=?',[$id]);
            return ['message'=>$keepLocal?'FTP 图片已删除；仍被使用或单独管理的本地副本已保留':'图片已删除'];
            });
            });
        });
    }
}
