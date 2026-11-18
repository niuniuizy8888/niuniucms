<?php
namespace App\Service\Collection;

use App\Repository\RepositoryFactory;
use App\Service\Content\VideoService;

/** 来源身份和人工分类是业务数据，与影片同事务保存，不依赖缓存。 */
class VideoOriginService
{
    private static bool $ready = false;

    public static function table(string $name): string { return _SERVER('db')->qi(_SERVER('db')->tablepre . $name); }

    public static function sql(string $sql, array $args = []): \PDOStatement
    {
        $stmt = _SERVER('db')->connect()->prepare($sql);
        if (!$stmt || !$stmt->execute($args)) throw new \RuntimeException('影片来源记录读写失败，请重试');
        return $stmt;
    }

    public static function ensureReady(): void
    {
        if (self::$ready) return;
        if (self::sql('SELECT v FROM ' . self::table('kv') . ' WHERE k=?', ['vod_origin_schema_v1'])->fetchColumn() === '1') {
            self::$ready = true; return;
        }
        if (_SERVER('db')->inTransaction()) throw new \RuntimeException('请先打开视频管理完成来源记录升级');
        self::sql('CREATE TABLE IF NOT EXISTS ' . self::table('vod_origin') . ' (
            source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            remote_id BIGINT UNSIGNED NOT NULL, vid INT UNSIGNED NOT NULL, alias VARCHAR(32) NOT NULL,
            type_id INT UNSIGNED NOT NULL DEFAULT 0, type_name VARCHAR(255) NOT NULL DEFAULT \'\',
            type_path VARCHAR(1000) NOT NULL DEFAULT \'\', suggested_cid INT UNSIGNED NOT NULL DEFAULT 0,
            mismatch TINYINT UNSIGNED NOT NULL DEFAULT 0, verified TINYINT UNSIGNED NOT NULL DEFAULT 0,
            checked_at INT UNSIGNED NOT NULL, PRIMARY KEY(source,remote_id,vid), KEY vid(vid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        self::sql('CREATE TABLE IF NOT EXISTS ' . self::table('vod_category_guard') . ' (
            vid INT UNSIGNED NOT NULL PRIMARY KEY, alias VARCHAR(32) NOT NULL,
            cid INT UNSIGNED NOT NULL, updated_at INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        self::sql('INSERT INTO ' . self::table('kv') . ' (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', ['vod_origin_schema_v1','1']);
        self::$ready = true;
    }

    /** 与普通、云端、定时采集使用同一把锁，防止改类与入库交错。 */
    public static function locked(callable $work)
    {
        self::ensureReady();
        $db = _SERVER('db'); $pdo = $db->connect();
        if ($db->inTransaction()) throw new \LogicException('分类操作不能嵌套采集事务');
        $lock = 'collect_vod_' . substr(hash('sha256', $db->tablepre . $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
        if ((int)self::sql('SELECT GET_LOCK(?,30)', [$lock])->fetchColumn() !== 1) throw new \RuntimeException('其他采集正在保存，请稍后重试');
        try {
            if (!$db->beginTransaction()) throw new \RuntimeException('无法开始分类事务');
            $result = $work();
            if (!$db->commit()) throw new \RuntimeException('分类保存失败');
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack(); throw $e;
        } finally { self::sql('SELECT RELEASE_LOCK(?)', [$lock]); }
    }

    public static function name(string $name): string
    {
        $name = preg_replace('/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $name) ?? trim($name);
        return VideoService::fitColumns(['name'=>htmlspecialchars($name, ENT_QUOTES, 'UTF-8')])['name'];
    }

    private static function remoteId($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D',(string)$value)) return 0;
        return (int)(filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0);
    }

    /** 一页一次取来源关联及同名候选；跨分类复用必须有来源身份或同线路地址依据。 */
    public static function context(array $items, string $code): array
    {
        $names = $ids = [];
        foreach ($items as $item) {
            $name = self::name((string)($item['vod_name'] ?? ''));
            if ($name !== '') $names[$name] = $name;
            $remoteId=self::remoteId($item['vod_id'] ?? 0);
            if ($remoteId) $ids[$remoteId] = $remoteId;
        }
        $known = $rows = $byName = [];
        $v = self::table('vod'); $o = self::table('vod_origin'); $g = self::table('vod_category_guard');
        $fields=array_unique(array_merge(['vid','alias','cid','name','year','douban_id','remarks','play_from','play_url','play_server','play_note'],array_keys(ApiImportService::fastDbDiffLabels())));
        $columns=implode(',',array_map(static fn($field)=>'v.'._SERVER('db')->qi($field),$fields));
        $select = "SELECT $columns,IF(BINARY g.alias=BINARY v.alias AND g.cid=v.cid,g.cid,0) AS _manual_cid FROM $v v LEFT JOIN $g g ON g.vid=v.vid ";
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $marks = implode(',', array_fill(0,count($chunk),'?'));
            $origins = self::sql("SELECT o.remote_id,o.vid FROM $o o JOIN $v v ON v.vid=o.vid AND BINARY v.alias=BINARY o.alias WHERE o.source=? AND o.verified=1 AND o.remote_id IN ($marks)", array_merge([$code],$chunk))->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($origins as $r) $known[(int)$r['remote_id']][(int)$r['vid']] = (int)$r['vid'];
        }
        foreach (array_chunk(array_values($names), 250) as $chunk) {
            $marks = implode(',', array_fill(0,count($chunk),'?'));
            $found = self::sql($select . "WHERE v.name IN ($marks) LIMIT 2001", $chunk)->fetchAll(\PDO::FETCH_ASSOC);
            if (count($found)>2000) throw new \RuntimeException('同名历史影片过多，请先处理重复视频后重试');
            foreach ($found as $r) { $rows[(int)$r['vid']]=$r; $byName[self::name(htmlspecialchars_decode($r['name'],ENT_QUOTES))][(int)$r['vid']] = (int)$r['vid']; }
        }
        $missing=[]; foreach ($known as $vids) foreach ($vids as $vid) if (!isset($rows[$vid])) $missing[$vid]=$vid;
        foreach (array_chunk(array_values($missing),500) as $chunk) {
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            foreach (self::sql($select . "WHERE v.vid IN ($marks)",$chunk)->fetchAll(\PDO::FETCH_ASSOC) as $r) $rows[(int)$r['vid']]=$r;
        }
        return compact('known','rows','byName');
    }

    private static function addresses(array $row, string $prefix): array
    {
        $out=[]; $urls=explode('$$$',(string)($row[$prefix.'play_url'] ?? ''));
        foreach (explode('$$$',(string)($row[$prefix.'play_from'] ?? '')) as $i=>$line) {
            foreach (explode('#',xn_url_strip($urls[$i] ?? '')) as $entry) {
                $parts=explode('$',$entry,2);
                if (count($parts)===2 && preg_match('#^https?://#i',$parts[1])) $out[$line][hash('sha256',$parts[1])]=true;
            }
        }
        return $out;
    }

    public static function resolve(array $item, array $context): array
    {
        $known=$context['known'][self::remoteId($item['vod_id'] ?? 0)] ?? [];
        if (count($known)===1) return ['row'=>$context['rows'][reset($known)],'blocked'=>false];
        if ($known) return ['row'=>null,'blocked'=>true];
        $exact=$protected=[]; $incoming=self::addresses($item,'vod_');
        foreach ($context['byName'][self::name((string)($item['vod_name'] ?? ''))] ?? [] as $vid) {
            $row=$context['rows'][$vid];
            if (!empty($item['vod_douban_id']) && !empty($row['douban_id']) && (int)$item['vod_douban_id']!==(int)$row['douban_id']) continue;
            $same=false;
            foreach (self::addresses($row,'') as $line=>$urls) if (array_intersect_key($urls,$incoming[$line] ?? [])) { $same=true; break; }
            if ($same) $exact[$vid]=$row;
            if (!empty($row['_manual_cid']) && (empty($item['vod_year']) || !$row['year'] || (int)$item['vod_year']===(int)$row['year'])) $protected[$vid]=$row;
        }
        if (count($exact)===1) return ['row'=>reset($exact),'blocked'=>false];
        if ($exact) {
            $confirmed=array_intersect_key($exact,$protected);
            if (count($confirmed)===1) return ['row'=>reset($confirmed),'blocked'=>false];
        }
        // 无地址依据的同名人工改类影片不得按旧绑定另建，留给分类核对确认来源。
        return ['row'=>null,'blocked'=>(bool)($exact || $protected)];
    }

    /** 有目录时才更新分类核对信息；普通编号采集不因目录暂缺清掉已有核对结果。 */
    public static function remember(string $code, array $item, array $row, bool $verified, ?array $type = null): void
    {
        if (!_SERVER('db')->inTransaction()) throw new \LogicException('来源身份必须与影片同事务保存');
        $id=self::remoteId($item['vod_id'] ?? 0); if ($id<1 || (int)$row['vid']<1) return;
        $table=self::table('vod_origin');
        if ($verified) self::sql("DELETE FROM $table WHERE source=? AND remote_id=? AND vid<>?",[$code,$id,$row['vid']]);
        self::sql("INSERT INTO $table (source,remote_id,vid,alias,type_id,type_name,type_path,suggested_cid,mismatch,verified,checked_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
            verified=IF(BINARY alias=BINARY VALUES(alias),GREATEST(verified,VALUES(verified)),VALUES(verified)),alias=VALUES(alias),
            type_id=VALUES(type_id),type_name=VALUES(type_name),checked_at=VALUES(checked_at)" . ($type!==null ? ',type_path=VALUES(type_path),suggested_cid=VALUES(suggested_cid),mismatch=VALUES(mismatch)' : ''),
            [$code,$id,$row['vid'],$row['alias'],max(0,(int)($item['type_id'] ?? 0)),VideoService::fitText((string)($item['type_name'] ?? ''),255),
                $type['path'] ?? '',$type['cid'] ?? 0,$type['mismatch'] ?? 0,(int)$verified,time()]);
    }

    public static function rememberContext(array &$context, array $item, array $row): void
    {
        $vid=(int)$row['vid']; $context['rows'][$vid]=$row;
        $context['byName'][self::name(htmlspecialchars_decode($row['name'],ENT_QUOTES))][$vid]=$vid;
        if ($id=self::remoteId($item['vod_id'] ?? 0)) $context['known'][$id]=[$vid=>$vid];
    }

    public static function purgeOrphans(): void
    {
        if (self::sql('SELECT v FROM '.self::table('kv').' WHERE k=?',['vod_origin_schema_v1'])->fetchColumn()!=='1') return;
        $vod=self::table('vod'); $recycle=self::table('recycle');
        foreach (['vod_origin','vod_category_guard'] as $name) {
            self::sql('DELETE m FROM '.self::table($name)." m LEFT JOIN $vod v ON v.vid=m.vid LEFT JOIN $recycle r ON r.type='vod' AND r.target_id=m.vid WHERE v.vid IS NULL AND r.id IS NULL");
        }
    }

    /** 后台编辑和核对确认共用；分类、保护标记、索引和计数一起提交。 */
    public static function saveManual(int $vid, array $update, bool $confirm = false, ?callable $after = null): bool
    {
        $result=self::locked(static function() use ($vid,$update,$confirm,$after) {
            $v=self::table('vod'); $g=self::table('vod_category_guard');
            $old=self::sql("SELECT v.vid,v.alias,v.cid,IF(BINARY g.alias=BINARY v.alias AND g.cid=v.cid,g.cid,0) AS _manual_cid FROM $v v LEFT JOIN $g g ON g.vid=v.vid WHERE v.vid=? FOR UPDATE",[$vid])->fetch(\PDO::FETCH_ASSOC);
            if (!$old) throw new \RuntimeException('影片不存在，请刷新后重试');
            $cid=(int)($update['cid'] ?? $old['cid']);
            if (!isset(CollectionCategoryService::targets(0,[$cid])[$cid])) throw new \RuntimeException('请选择有效的视频分类');
            if (VideoService::update($vid,$update)===false) throw new \RuntimeException('影片保存失败');
            if (RepositoryFactory::vodVid()->replace(['vid'=>$vid,'cid'=>$cid])===false) throw new \RuntimeException('分类索引保存失败');
            if (RepositoryFactory::table('vod_sticky','vid')->update(['vid'=>$vid],['cid'=>$cid])===false) throw new \RuntimeException('推荐分类保存失败');
            $alias=(string)($update['alias'] ?? $old['alias']);
            if ($confirm || $cid!==(int)$old['cid'] || !empty($old['_manual_cid'])) {
                self::sql("INSERT INTO $g (vid,alias,cid,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE alias=VALUES(alias),cid=VALUES(cid),updated_at=VALUES(updated_at)",[$vid,$alias,$cid,time()]);
            }
            self::sql('UPDATE '.self::table('vod_origin').' SET alias=? WHERE vid=? AND alias=?',[$alias,$vid,$old['alias']]);
            $cate=self::table('cate'); $index=self::table('vod_vid');
            self::sql("UPDATE $cate c SET c.videos=(SELECT COUNT(*) FROM $index i WHERE i.cid=c.cid) WHERE c.cid IN (?,?)",[$cid,$old['cid']]);
            if ($after!==null) $after(array_merge($old,$update));
            return true;
        });
        content_cache_delete('vod_',$vid); page_cache_bump(); cate_list_cache_delete();
        cache_delete('sticky_video_list'); cache_set('sticky_video_ver',time(),86400);
        \App\Service\Front\TypeService::bumpFacets();
        return $result;
    }
}
