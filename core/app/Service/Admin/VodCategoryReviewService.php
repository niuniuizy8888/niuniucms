<?php
namespace App\Service\Admin;

use App\Service\Collection\VideoOriginService as Origin;
use App\Service\Collection\VideoCategoryCatalog;
use App\Service\Collection\CollectionCategoryService;
use App\Service\Collection\ApiExecutionService;

/** 分页核对真实来源，只登记疑点；用户确认前不改影片、不改资源站绑定。 */
class VodCategoryReviewService
{
    public static function sources(): array
    {
        $sources=[];
        foreach (_SERVER('conf')['api'] ?? [] as $code=>$source) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,32}$/D',(string)$code) && isset($source['model']) && (int)$source['model']===0 && !empty($source['api'])) {
                $sources[(string)$code]=['code'=>(string)$code,'name'=>(string)($source['name'] ?? $code)];
            }
        }
        return $sources;
    }

    private static function key(string $code): string { return 'vcs_'.substr(hash('sha256',$code),0,28); }

    public static function state(string $code): array
    {
        $raw=Origin::sql('SELECT v FROM '.Origin::table('kv').' WHERE k=?',[self::key($code)])->fetchColumn();
        return $raw ? (array)json_decode($raw,true,512,JSON_THROW_ON_ERROR) : [];
    }

    private static function saveState(string $code, array $state): void
    {
        Origin::sql('INSERT INTO '.Origin::table('kv').' (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)',[self::key($code),json_encode($state,JSON_THROW_ON_ERROR)]);
    }

    public static function page(int $uid, string $code, int $page, bool $all, bool $canScan): array
    {
        Origin::ensureReady();
        $categories=array_values(CollectionCategoryService::targets(0));
        foreach ($categories as &$category) $category['name']=htmlspecialchars_decode($category['name'],ENT_QUOTES);
        return ['header'=>['title'=>'历史分类核对'],'review_sources'=>array_values(self::sources()),
            'review_code'=>$code,'review_all'=>$all,'review_page'=>max(1,$page),'review_can_scan'=>$canScan,
            'review_state'=>self::state($code),'review_result'=>self::listing($code,$page,$all),
            'review_categories'=>$categories,
            'safe_token'=>bull_token_set($uid,'admin_vod_category_review')];
    }

    public static function listing(string $code, int $page, bool $all): array
    {
        $v=Origin::table('vod'); $o=Origin::table('vod_origin'); $g=Origin::table('vod_category_guard');
        $join=" FROM $o o JOIN $v v ON v.vid=o.vid AND BINARY v.alias=BINARY o.alias LEFT JOIN $g g ON g.vid=v.vid ";
        $protected='(BINARY g.alias=BINARY v.alias AND g.cid=v.cid)';
        $pending="(o.verified=0 OR ((o.mismatch=1 OR (o.suggested_cid>0 AND o.suggested_cid<>v.cid)) AND NOT COALESCE($protected,0)))";
        $where=' WHERE 1=1'; $args=[];
        if ($code!=='') { $where.=' AND o.source=?'; $args[]=$code; }
        if (!$all) $where.=' AND '.$pending;
        $total=(int)Origin::sql('SELECT COUNT(*)'.$join.$where,$args)->fetchColumn();
        $pages=max(1,(int)ceil($total/20)); $page=max(1,min($pages,$page));
        $rows=Origin::sql("SELECT o.source,o.remote_id,o.vid,o.type_id,o.type_name,o.type_path,o.suggested_cid,o.verified,v.name,v.cid,COALESCE($protected,0) AS protected,$pending AS pending".$join.$where.' ORDER BY o.vid,o.source,o.remote_id LIMIT 20 OFFSET '.(($page-1)*20),$args)->fetchAll(\PDO::FETCH_ASSOC);
        $cats=CollectionCategoryService::targets(0); $sources=self::sources();
        foreach ($rows as &$row) {
            $row['name']=htmlspecialchars_decode($row['name'],ENT_QUOTES);
            $row['local_path']=self::localPath((int)$row['cid'],$cats);
            $row['source_name']=$sources[$row['source']]['name'] ?? $row['source'];
            $row['status']=!(int)$row['verified'] ? '同名来源待确认' : ((int)$row['protected'] ? '已人工确认' : ((int)$row['pending'] ? '分类待核对' : '分类一致'));
        }
        return compact('rows','total','pages','page');
    }

    private static function localPath(int $cid, array $cats): string
    {
        $names=[]; $seen=[];
        while ($cid && isset($cats[$cid]) && !isset($seen[$cid])) {
            $seen[$cid]=true; array_unshift($names,htmlspecialchars_decode($cats[$cid]['name'],ENT_QUOTES)); $cid=(int)$cats[$cid]['cup'];
        }
        return implode(' / ',$names);
    }

    private static function typeInfo(array $catalog, array $cats): array
    {
        $locals=[];
        foreach ($cats as $cid=>$cat) $locals[self::localPath((int)$cid,$cats)][]=(int)$cid;
        $types=[];
        foreach ($catalog['rows'] as $type) {
            $parent=$types[$type['type_pid']]['path'] ?? '';
            $path=($parent!=='' ? $parent.' / ' : '').$type['type_name'];
            $matches=$locals[$path] ?? [];
            $types[$type['type_id']]=['path'=>\App\Service\Content\VideoService::fitText($path,1000),'cid'=>count($matches)===1 ? $matches[0] : 0];
        }
        return $types;
    }

    public static function scan(string $code, bool $start, string $run): array
    {
        Origin::ensureReady();
        if (!isset(self::sources()[$code])) throw new \RuntimeException('请选择已配置的视频资源站');
        $api=(string)_SERVER('conf')['api'][$code]['api'];
        $lock='vod_scan_'.substr(hash('sha256',Origin::table('vod').$code),0,48);
        if ((int)Origin::sql('SELECT GET_LOCK(?,1)',[$lock])->fetchColumn()!==1) throw new \RuntimeException('该资源站正在核对，请稍后继续');
        try {
            $state=self::state($code);
            if ($start) {
                $state=['run'=>bin2hex(random_bytes(16)),'api'=>hash('sha256',$api),'next'=>1,'pages'=>0,'checked'=>0,'matched'=>0,'done'=>false];
                self::saveState($code,$state);
                return $state;
            }
            if (!$state || !hash_equals($state['run'],$run)) throw new \RuntimeException('核对任务已变化，请刷新后继续');
            if ($state['api']!==hash('sha256',$api)) throw new \RuntimeException('资源站地址已变更，请重新开始核对');
            if ($state['done']) return $state;
            $page=(int)$state['next'];
            $catalog=VideoCategoryCatalog::categories($api,$page===1);
            $data=ApiExecutionService::fetchVideoJson($api,'',$page,'');
            if (!isset($data['page']['pagecount']) || (int)$data['page']['page']!==$page || !is_array($data['data']) || count($data['data'])>1000) throw new \RuntimeException('来源取页失败，已保留进度，请继续重试');
            return Origin::locked(static function() use ($data,$catalog,$code,$state,$page) {
                $cats=CollectionCategoryService::targets(0); $types=self::typeInfo($catalog,$cats);
                $context=Origin::context($data['data'],$code); $matched=0;
                foreach ($data['data'] as $item) {
                    if ((int)($item['vod_id'] ?? 0)<1) continue;
                    $resolved=Origin::resolve($item,$context); $row=$resolved['row'];
                    $candidates=$row ? [$row['vid']=>$row['vid']] : ($context['byName'][Origin::name((string)($item['vod_name'] ?? ''))] ?? []);
                    foreach ($candidates as $vid) {
                        $candidate=$context['rows'][$vid];
                        $type=$types[$item['type_id'] ?? 0] ?? ['path'=>(string)($item['type_name'] ?? ''),'cid'=>0];
                        $type['mismatch']=(int)($type['path']!==self::localPath((int)$candidate['cid'],$cats));
                        Origin::remember($code,$item,$candidate,$row!==null,$type);
                        ++$matched;
                    }
                }
                $state['checked']+=count($data['data']); $state['matched']+=$matched;
                $state['pages']=(int)$data['page']['pagecount']; $state['next']=$page+1;
                $state['done']=$page >= $state['pages'];
                self::saveState($code,$state);
                return $state;
            });
        } finally { Origin::sql('SELECT RELEASE_LOCK(?)',[$lock]); }
    }

    public static function confirm(string $source, int $remoteId, int $vid, int $cid): void
    {
        Origin::saveManual($vid,['cid'=>$cid],true,static function($video) use ($source,$remoteId,$vid) {
            $origin=Origin::sql('SELECT source,remote_id,vid,alias,type_id,type_name FROM '.Origin::table('vod_origin').' WHERE source=? AND remote_id=? AND vid=? FOR UPDATE',[$source,$remoteId,$vid])->fetch(\PDO::FETCH_ASSOC);
            if (!$origin || $origin['alias']!==$video['alias']) throw new \RuntimeException('核对记录已失效，请重新核对');
            Origin::remember($source,['vod_id'=>$remoteId,'type_id'=>$origin['type_id'],'type_name'=>$origin['type_name']],$video,true);
        });
    }
}
