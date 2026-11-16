<?php
namespace App\Service\Collection;

class ApiBindService
{
    /**
     * 绑定分类
     * 
     * @param string $code 采集源编码
     * @param string $name 分类名称
     * @param int $localId 本地分类ID
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function bind(string $code, string $name, int $localId, string $sourceId = ''): array
    {
        $conf = _SERVER('conf');
        if (empty($code) || empty($name)) {
            return ['success' => false, 'msg' => '参数不完整'];
        }
        if ($localId <= 0) {
            return ['success' => false, 'msg' => '请选择有效的本地分类'];
        }
        // 发布前检查修复（A-3）：code 字符集白名单（ApiSourceService 写入侧同口径）——
        // 绑定键会进 kv 'bind' 数组键与后台属性位输出，纵深防键逃逸
        if (1 !== preg_match('#^[A-Za-z0-9_.-]{1,32}$#', $code)) {
            return ['success' => false, 'msg' => 'code invalid'];
        }
        if (empty($conf['api'][$code]['api'])) return ['success' => false, 'msg' => '采集源不存在，请刷新列表'];
        $model = CollectionCategoryService::sourceModel($code, $conf);
        $targets = CollectionCategoryService::targets($model, [$localId]);
        if (!isset($targets[$localId])) return ['success'=>false, 'msg'=>'所选分类已删除、类型不符或不能用于采集，请重新绑定'];

        $saved = (array)($conf['bind'][$code] ?? []);
        if ($model === 0 || CollectionCategoryService::usesVideoIds($saved)) {
            try {
                $types = VideoCategoryCatalog::categories($conf['api'][$code]['api'])['rows'];
                $idsByName = [];
                $namesById = array_column($types, 'type_name', 'type_id');
                foreach ($namesById as $id => $typeName) $idsByName[$typeName][] = (string)$id;
                $ids = $idsByName[$name] ?? [];
                if ($sourceId === '' && count($ids) === 1) $sourceId = $ids[0];
                if (!in_array($sourceId, $ids, true)) throw new \RuntimeException('请在配置采集中按来源层级选择分类');
                // 旧版可能按名称绑定了多个分类。只在能逐项识别时迁移，避免改一项丢失其他绑定。
                $bindings = [];
                foreach ($saved as $key => $entry) {
                    if (is_array($entry)) {
                        $entry['name'] = $namesById[$key] ?? $entry['name'];
                        $entry['unique'] = isset($namesById[$key]) && count($idsByName[$entry['name']]) === 1;
                        $bindings[$key] = $entry;
                        continue;
                    }
                    $matches = $idsByName[$key] ?? [];
                    if (count($matches) !== 1) throw new \RuntimeException('旧绑定中有重名或已移除的分类，请在配置采集中逐项确认');
                    $bindings[$matches[0]] = ['cid'=>(int)$entry, 'name'=>(string)$key, 'unique'=>true];
                }
                $bindings[$sourceId] = ['cid'=>$localId, 'name'=>$name, 'unique'=>count($ids) === 1];
            } catch (\RuntimeException $e) { return ['success'=>false, 'msg'=>$e->getMessage()]; }
        } else {
            $bindings = $saved;
            $bindings[$name] = $localId;
        }
        if (!self::storeBindings($conf, $code, $bindings)) return ['success'=>false, 'msg'=>self::SAVE_FAILED];

        admin_audit_log('api_cate_bind', array(
            'code' => $code, 
            'name' => $name, 
            'binstatus' => 'bind', 
            'local_id' => $localId
        ));
        
        return ['success' => true];
    }

    /**
     * 解绑分类
     * 
     * @param string $code 采集源编码
     * @param string $name 分类名称
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function unbind(string $code, string $name, string $sourceId = ''): array
    {
        $conf = _SERVER('conf');
        if (empty($code) || empty($name)) {
            return ['success' => false, 'msg' => '参数不完整'];
        }
        
        $bindings = (array)($conf['bind'][$code] ?? []);
        $key = $name;
        if (CollectionCategoryService::usesVideoIds($bindings)) {
            if ($sourceId !== '') {
                if (!preg_match('/^[1-9][0-9]{0,9}$/D', $sourceId)) return ['success'=>false, 'msg'=>'来源分类编号无效'];
                $key = $sourceId;
            } else {
                $keys = [];
                foreach ($bindings as $id => $entry) {
                    if (($entry['name'] ?? null) === $name) $keys[] = $id;
                }
                if (!$keys) return ['success'=>true];
                if (count($keys) !== 1 || empty($bindings[$keys[0]]['unique'])) return ['success'=>false, 'msg'=>'请按来源分类编号解除同名分类的绑定'];
                $key = $keys[0];
            }
        }
        if (isset($bindings[$key])) {
            unset($bindings[$key]);
            if (!self::storeBindings($conf, $code, $bindings)) return ['success'=>false, 'msg'=>self::SAVE_FAILED];
        }

        admin_audit_log('api_cate_bind', array(
            'code' => $code, 
            'name' => $name, 
            'binstatus' => 'unbind', 
            'local_id' => 0
        ));
        
        return ['success' => true];
    }

    private const SAVE_FAILED = '分类绑定未保存，请重新读取当前配置后重试';

    private static function storeBindings(array $conf, string $code, array $bindings): bool
    {
        return CollectionCategoryService::updateBindings($conf, static function (array $current) use ($conf, $code, $bindings) {
            if (($current['api'][$code] ?? null) !== ($conf['api'][$code] ?? null)
                || ($current['bind'][$code] ?? null) !== ($conf['bind'][$code] ?? null)) return null;
            $current['bind'][$code] = $bindings;
            return $current['bind'];
        });
    }

    /**
     * 自动绑定所有同名分类
     * 
     * @param string $code 采集源编码
     * @param string $api API地址
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function autoBindAll(string $code, string $api): array
    {
        $conf = _SERVER('conf');
        $api = $conf['api'][$code]['api'] ?? $api;
        
        if (empty($code) || empty($api)) {
            return ['success' => false, 'msg' => '参数不完整'];
        }
        $model = CollectionCategoryService::sourceModel($code, $conf);
        if ($model !== null && $model !== 0) return ['success' => false, 'msg' => '采集源类型不符，请在文章采集中绑定分类'];

        $saved = (array)($conf['bind'][$code] ?? []);
        if ($model === 0 || CollectionCategoryService::usesVideoIds($saved)) {
            try {
                $rows = CollectionSetupService::videoBindingRows($conf['api'][$code]['api'], $saved);
            } catch (\RuntimeException $e) { return ['success'=>false, 'msg'=>$e->getMessage()]; }
            $bindings = [];
            foreach ($rows as $row) {
                if ($row['target']) $bindings[$row['id']] = ['cid'=>$row['target'], 'name'=>$row['name'], 'unique'=>$row['unique']];
            }
            if (!$bindings) return ['success'=>false, 'msg'=>'没有匹配的本地分类，已保留原有绑定'];
            if (!self::storeBindings($conf, $code, $bindings)) return ['success'=>false, 'msg'=>self::SAVE_FAILED];
            admin_audit_log('api_bind_auto_all', ['code'=>$code]);
            return ['success'=>true, 'msg'=>'已绑定 '.count($bindings).' 个分类'];
        }

        // 新商店对接修复：取源分类统一走 ApiExecutionService::sourceClassList()
        // （缓存键 api_cls_ 前缀保留；真凶是商店**明文** URL 被 core_decrypt 判死 ⇒ class 恒空；
        // 另修掉"空结果被缓存 1 小时"）。
        $apiarr = \App\Service\Collection\ApiExecutionService::sourceClassList($code, $api);
        if (empty($apiarr)) {
            // fail-closed：取不到源分类时**绝不能**走到下面那句整条替换——旧实现会把 kv('bind') 写成
            // 空数组并回「操作成功」，实测把已有 44 条绑定静默清空（故障被伪装成成功，最难排查）。
            return ['success' => false, 'msg' => '未取到采集源分类（源站不可达或接口无 class 字段），已保留原有绑定'];
        }

        // 未标记模型的旧混合源使用名称键。视频自动绑定只处理能明确区分的名称。
        $video = CollectionCategoryService::targets(0);
        $article = CollectionCategoryService::validBindings($saved, 1);
        $byName = $counts = $catelistarr = [];
        foreach ($video as $cid => $row) $byName[htmlspecialchars_decode($row['name'], ENT_QUOTES)][] = $cid;
        foreach ($apiarr as $row) {
            if (isset($row['type_mid']) && !in_array($row['type_mid'], [1, '1'], true)) continue;
            $name = $row['type_name'] ?? '';
            if (is_string($name) && $name !== '') $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        foreach ($counts as $name => $count) {
            if ($count !== 1 || isset($article[$name])) continue;
            $cid = $saved[$name] ?? 0;
            if (isset($video[$cid])) $catelistarr[$name] = (int)$cid;
            elseif (count($byName[$name] ?? []) === 1) $catelistarr[$name] = $byName[$name][0];
        }

        if (empty($catelistarr)) {
            // 源有分类但本地一个同名都没有：同样不能把绑定清空（先「一键采集/创建分类」再来绑定）
            return ['success' => false, 'msg' => '源站 ' . count($apiarr) . ' 个分类在本地无同名分类，未做任何绑定（请先执行「一键采集」或「创建分类」）'];
        }

        if (!self::storeBindings($conf, $code, $catelistarr + $article)) return ['success'=>false, 'msg'=>self::SAVE_FAILED];
        
        admin_audit_log('api_bind_auto_all', array('code' => $code));
        
        return ['success' => true, 'msg' => '自动绑定分类完成：绑定 ' . count($catelistarr) . ' 个（源站 ' . count($apiarr) . ' 个）'];
    }
}
