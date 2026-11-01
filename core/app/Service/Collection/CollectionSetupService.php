<?php
namespace App\Service\Collection;

use App\Repository\RepositoryFactory;

/** 先预览、再确认；预览快照保存在会话中，不信任客户端回传的名称和源地址。 */
class CollectionSetupService
{
    public static function preview(string $kind, string $key, int $model = 0): array
    {
        $conf = _SERVER('conf');
        $source = null;
        if (!in_array($model, [0, 1], true)) throw new \RuntimeException('采集类型无效');
        if ($kind === 'source') {
            foreach ((\App\Service\System\CloudOpsService::cloudPayload()[$model === 1 ? 'artapi' : 'list'] ?? []) as $item) {
                if ((string)($item['code'] ?? '') === $key) { $source = $item; break; }
            }
            if (!$source || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $key)) throw new \RuntimeException('采集源不存在，请刷新列表重试');
            if (isset($conf['api'][$key]['model']) && (int)$conf['api'][$key]['model'] !== $model) throw new \RuntimeException('采集源类型不符，请检查源配置');
            $source['api'] = $conf['api'][$key]['api'] ?? $source['api'];
            $raw = ApiExecutionService::sourceClassList($key, (string)$source['api']);
        } else {
            if (!in_array($key, ['v', 'x', 'art', 'av'], true)) throw new \RuntimeException('分类模板无效');
            $model = in_array($key, ['art', 'av'], true) ? 1 : 0;
            $tree = ApiMiscService::remoteCategoryTree($key);
            $raw = [];
            foreach ($tree as $parent => $items) {
                foreach ((array)$items as $item) {
                    if (is_array($item)) { $item['type_pid'] = $parent; $raw[] = $item; }
                }
            }
        }
        if (empty($raw)) throw new \RuntimeException('未取到分类，请检查源站连接后重试；尚未创建或绑定任何分类');
        $local = self::localRows($model);
        $byName = [];
        foreach ($local as $c) $byName[$c['name']][] = $c['cid'];
        $rows = []; $names = [];
        foreach ($raw as $item) {
            $name = trim((string)($item['type_name'] ?? ''));
            $id = (string)($item['type_id'] ?? '');
            if ($name === '' || $id === '' || isset($names[$name])) continue;
            if (!preg_match('/^[1-9][0-9]{0,9}$/', $id)) throw new \RuntimeException('源站分类编号无效');
            if (isset($rows[$id])) throw new \RuntimeException('源站分类编号重复，无法安全导入');
            $names[$name] = true;
            $bound = $kind === 'source' ? (int)($conf['bind'][$key][$name] ?? 0) : 0;
            $matches = $byName[$name] ?? [];
            $hit = isset($local[$bound]) ? $bound : (count($matches) === 1 ? $matches[0] : 0);
            // 已配置源沿用上次确认的范围；同名匹配仅提供目标，不重新勾选已取消的分类。
            $selected = $kind === 'source' && array_key_exists($key, (array)($conf['bind'] ?? []))
                ? $bound > 0 && isset($local[$bound]) : $hit > 0;
            $rows[$id] = ['id'=>$id, 'name'=>$name, 'parent'=>(string)($item['type_pid'] ?? '0'), 'target'=>$hit, 'selected'=>$selected, 'status'=>$hit ? ($bound === $hit ? '已绑定' : '已匹配') : (count($matches)>1 ? '同名待确认' : '未创建')];
        }
        if (!$rows || count($rows)>1000) throw new \RuntimeException('分类为空或超过 1000 个，请检查源站数据');
        $id = bin2hex(random_bytes(16));
        $plans = (array)($_SESSION['collection_plans'] ?? []);
        foreach ($plans as $p => $v) if (($v['expires'] ?? 0) < time()) unset($plans[$p]);
        $plans = array_slice($plans, -4, null, true);
        $plans[$id] = ['kind'=>$kind, 'key'=>$key, 'model'=>$model, 'source'=>$source, 'rows'=>$rows, 'expires'=>time()+900];
        $_SESSION['collection_plans'] = $plans;
        return ['plan'=>$id, 'kind'=>$kind, 'model'=>$model, 'name'=>$source['name'] ?? ['v'=>'影视分类','x'=>'X 分类','art'=>'文章分类','av'=>'X 资讯分类'][$key], 'rows'=>array_values($rows), 'local'=>array_values($local), 'official_player'=>$model === 0 && !empty($source['player']), 'has_player'=>$model === 0 && !empty($conf['vodplayer'][$key]), 'player_name'=>$kind === 'source' && $model === 0 ? self::playerDisplayName((array)($conf['vodplayer'] ?? []), $key) : '', 'empty_local'=>!$local];
    }

    /** 名称按纯文本保存，由前台和管理模板各自在输出时转义。 */
    private static function playerDisplayName(array $players, string $key): string
    {
        $current = trim((string)($players[$key]['show'] ?? ''));
        if ($current !== '') return $current;
        $used = [];
        foreach ($players as $entry) $used[trim((string)($entry['show'] ?? ''))] = true;
        for ($n = 1; isset($used['高速线路'.$n]); $n++) {}
        return '高速线路'.$n;
    }

    private static function localRows(int $model): array
    {
        $out = [];
        foreach (RepositoryFactory::cate()->find(['model'=>$model], ['cid'=>1], 1, 10000) as $row) {
            if ((int)$row['category'] === 2) continue;
            $out[(int)$row['cid']] = ['cid'=>(int)$row['cid'], 'name'=>htmlspecialchars_decode((string)$row['name'], ENT_QUOTES)];
        }
        return $out;
    }

    public static function apply(string $id, array $choices, string $player, string $run, bool $canCreate, string $kind, ?string $playerName = null): array
    {
        $plan = $_SESSION['collection_plans'][$id] ?? null;
        if (!$plan || $plan['expires'] < time() || $plan['kind'] !== $kind) throw new \RuntimeException('预览已过期，请重新打开向导');
        if (!in_array($player, ['keep','official'], true) || !in_array($run, ['save','day','all','async_all'], true)) throw new \RuntimeException('选项无效');
        $rows = $plan['rows']; $selected = [];
        foreach ($choices as $idKey => $target) {
            if (!isset($rows[$idKey]) || (!is_scalar($target)) || !preg_match('/^(create|[1-9][0-9]*)$/', (string)$target)) throw new \RuntimeException('分类选择无效，请重新预览');
            if ($target === 'create' && !$canCreate) throw new \RuntimeException('没有创建分类权限，请联系管理员');
            $selected[(string)$idKey] = (string)$target;
        }
        if (!$selected) throw new \RuntimeException('请至少选择一个分类');
        $source = $plan['source']; $key = $plan['key'];
        if ($plan['model'] === 1 && $player !== 'keep') throw new \RuntimeException('文章采集不使用播放器');
        if ($playerName !== null) {
            if ($kind !== 'source' || $plan['model'] !== 0) throw new \RuntimeException('仅视频采集支持设置播放器名称');
            $playerName = trim($playerName);
            if ($playerName === '' || !mb_check_encoding($playerName, 'UTF-8') || mb_strlen($playerName, 'UTF-8') > 100 || preg_match('/[\x00-\x1F\x7F]/u', $playerName)) {
                throw new \RuntimeException('请填写 1～100 个字符的播放器名称，不支持换行或控制字符');
            }
        }
        if ($kind === 'source' && $player === 'official' && (empty($source['player']) || !preg_match('#^https?://#i', $source['player']))) throw new \RuntimeException('此采集源未提供可用的官方播放器');
        $db = _SERVER('db'); $pdo = $db->connect();
        $prefix = $db->tablepre;
        if (!$pdo || !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) throw new \RuntimeException('数据库不可用');
        $lock = 'collection_setup_' . substr(hash('sha256', $prefix . ($pdo->query('SELECT DATABASE()')->fetchColumn())),0,32);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)'); $stmt->execute([$lock]);
        if ((int)$stmt->fetchColumn() !== 1) throw new \RuntimeException('其他导入正在执行，请稍后重试');
        $created = 0; $bound = 0;
        try {
            $pdo->beginTransaction();
            $cats = $pdo->query("SELECT cid,name,model,category FROM `{$prefix}cate` FOR UPDATE")->fetchAll(\PDO::FETCH_ASSOC);
            $local = []; $byName = [];
            foreach ($cats as $c) if ((int)$c['model'] === $plan['model'] && (int)$c['category'] !== 2) {
                $local[(int)$c['cid']] = $c;
                $byName[htmlspecialchars_decode($c['name'], ENT_QUOTES)][] = (int)$c['cid'];
            }
            foreach ($selected as $sid=>$target) if ($target !== 'create' && !isset($local[(int)$target])) throw new \RuntimeException('所选本地分类已删除或类型不符，请重新预览');
            $resolved=[]; $visiting=[];
            $create = function($sid) use (&$create, &$resolved, &$visiting, &$created, &$byName, $rows, $selected, $plan, $pdo, $prefix) {
                if (isset($resolved[$sid])) return $resolved[$sid];
                if (isset($visiting[$sid])) throw new \RuntimeException('源站分类存在循环父级，请检查分类');
                $visiting[$sid]=true;
                $row=$rows[$sid]; $target=$selected[$sid];
                if ($target !== 'create') return $resolved[$sid]=(int)$target;
                $matches=$byName[$row['name']] ?? [];
                if (count($matches)>1) throw new \RuntimeException('存在同名分类，请手动选择绑定目标');
                if ($matches) return $resolved[$sid]=$matches[0];
                $parent=$row['parent'];
                $cup=($parent !== (string)$sid && isset($selected[$parent])) ? $create($parent) : 0;
                $hasChildren=false;
                foreach ($rows as $child) if ($child['parent'] === (string)$sid && isset($selected[$child['id']])) { $hasChildren=true; break; }
                $stmt=$pdo->prepare("INSERT INTO `{$prefix}cate` (cup,type,model,category,name,create_date,display,nav_display,alias) VALUES (?,1,?,?,?,?,0,1,?)");
                $stmt->execute([$cup,$plan['model'],$cup ? 0 : ($hasChildren ? 1 : 0),htmlspecialchars(filter_all_html($row['name']),ENT_QUOTES,'UTF-8'),time(), 'c'.bin2hex(random_bytes(8))]);
                $cid=(int)$pdo->lastInsertId();
                if (!$cid) throw new \RuntimeException('创建分类失败');
                if ($cup) { $s=$pdo->prepare("UPDATE `{$prefix}cate` SET son=son+1 WHERE cid=?"); $s->execute([$cup]); }
                $created++; $byName[$row['name']]=[$cid];
                return $resolved[$sid]=$cid;
            };
            foreach ($selected as $sid=>$target) $create($sid);
            if ($kind === 'source') {
                $conf=_SERVER('conf'); $values=[];
                foreach ($plan['model'] === 1 ? ['api','bind'] : ['api','bind','vodplayer'] as $k) {
                    $s=$pdo->prepare("SELECT v FROM `{$prefix}kv` WHERE k=? FOR UPDATE"); $s->execute([$k]); $v=$s->fetchColumn();
                    $values[$k]=$v === false ? ($conf[$k] ?? []) : (json_decode($v,true) ?? []);
                }
                $values['api'][$key]=['api'=>$source['api'],'code'=>$key,'model'=>$plan['model']];
                // 此源的分类范围以本次确认的选择为准，其他源保持不变。
                $values['bind'][$key]=[];
                foreach ($resolved as $sid=>$cid) { $values['bind'][$key][$rows[$sid]['name']]=$cid; $bound++; }
                if ($plan['model'] === 0) {
                    $displayName = $playerName ?? self::playerDisplayName($values['vodplayer'], $key);
                    if ($player === 'official' || empty($values['vodplayer'][$key])) {
                        $values['vodplayer'][$key]=['status'=>'1','from'=>$key,'show'=>$displayName,'des'=>$displayName,'target'=>'_self','ps'=>'1','parse'=>$player === 'official' ? $source['player'] : '', 'prefer_external'=>$player === 'official' ? 1 : 0, 'sort'=>'1000','tip'=>'无需安装任何插件'];
                    } elseif ($playerName !== null) {
                        // 仅改显示名称时保留解析地址、标识、排序及其他自定义属性。
                        $values['vodplayer'][$key]['show'] = $displayName;
                        $values['vodplayer'][$key]['des'] = $displayName;
                    }
                }
                foreach ($values as $k=>$value) {
                    $s=$pdo->prepare("REPLACE INTO `{$prefix}kv` (k,v) VALUES (?,?)");
                    $s->execute([$k,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $s=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$lock]);
        }
        cate_list_cache_delete();
        page_cache_bump();
        if ($kind === 'source') {
            $memo =& kv_request_cache();
            foreach ($plan['model'] === 1 ? ['api','bind'] : ['api','bind','vodplayer'] as $k) { unset($memo[$k]); cache_delete($k); }
        }
        unset($_SESSION['collection_plans'][$id]);
        admin_audit_log('collection_setup', ['kind'=>$kind,'key'=>$key,'created'=>$created,'bound'=>$bound,'player'=>$player,'run'=>$run]);
        $next = '';
        if ($kind === 'source' && $run !== 'save') {
            $async = $run === 'async_all';
            if ($async) $_SESSION['collection_cloud_start'] = ['code'=>$key, 'model'=>$plan['model'], 'expires'=>time()+120];
            $next = $async
                ? url($plan['model'] === 1 ? 'api-art_list' : 'api-list', ['cloud_start'=>$key], true)
                : url($plan['model'] === 1 ? 'api-art_api' : 'api-api', ['code'=>$key, 'type'=>$run, 'safe_token'=>bull_token_set((int)_SESSION('uid'), 'admin_api_exec')], true);
        }
        return ['created'=>$created,'bound'=>$bound,'skipped'=>count($rows)-count($selected), 'next'=>$next];
    }
}
