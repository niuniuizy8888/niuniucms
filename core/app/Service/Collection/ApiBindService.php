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
        if ($model === 0 || ($model === null && (int)$targets[$localId]['model'] === 0)) {
            try {
                $catalog = VideoCategoryCatalog::forBindings($code, $conf['api'][$code]['api'], $saved);
                $types = $catalog['rows'];
                $idsByName = [];
                $namesById = array_column($types, 'type_name', 'type_id');
                foreach ($namesById as $id => $typeName) $idsByName[$typeName][] = (string)$id;
                $ids = $idsByName[$name] ?? [];
                $index = CollectionCategoryService::videoBindings($saved, catalog: $catalog);
                if ($sourceId === '' && count($ids) === 1 && !isset($index['pending'][$name]) && !isset($index['pendingIds'][$ids[0]])) {
                    $sourceId = $ids[0];
                }
                if (!in_array($sourceId, $ids, true)) throw new \RuntimeException('请在配置采集中按来源编号和层级确认分类');
                $bindings = $saved;
                // 单项操作只确认当前来源编号，其他名称绑定仍须在采集时核验。
                foreach ([$name, $index['legacy'][$sourceId] ?? null, $index['pendingIds'][$sourceId] ?? null] as $oldName) {
                    if ($oldName !== null && isset($bindings[$oldName]) && !is_array($bindings[$oldName])
                        && ($model === 0 || isset($index['pending'][$oldName]) || in_array((string)$oldName, $index['legacy'], true))) unset($bindings[$oldName]);
                }
                if (isset($bindings[$sourceId]) && !is_array($bindings[$sourceId])) throw new \RuntimeException('来源编号与旧分类名称冲突，请在配置采集中逐项确认');
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
        $usesIds = CollectionCategoryService::usesVideoIds($bindings);
        $model = CollectionCategoryService::sourceModel($code, $conf);
        $named = isset($bindings[$name]) && !is_array($bindings[$name]);
        $namedVideo = $named && ($model === 0 || ($model === null && CollectionCategoryService::targets(0, [$bindings[$name]])));
        if ($sourceId !== '' && $namedVideo && !is_array($bindings[$sourceId] ?? null)) {
            try {
                $catalog = VideoCategoryCatalog::forBindings($code, (string)($conf['api'][$code]['api'] ?? ''), $bindings);
                $index = CollectionCategoryService::videoBindings($bindings, catalog: $catalog);
            } catch (\RuntimeException $e) { return ['success'=>false, 'msg'=>$e->getMessage()]; }
            if (($index['legacy'][$sourceId] ?? null) !== $name) return ['success'=>false, 'msg'=>'该来源编号与旧绑定不符，请在配置采集中核对分类'];
        } elseif ($usesIds) {
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

    public static function storeBindings(array $conf, string $code, array $bindings): bool
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
        try {
            $bindings = CollectionSetupService::automaticVideoBindings($code, $api, $saved);
        } catch (\RuntimeException $e) { return ['success'=>false, 'msg'=>$e->getMessage()]; }
        $article = $model === null ? CollectionCategoryService::validBindings($saved, 1) : [];
        $bindings = array_diff_key($bindings, $article);
        if (!$bindings) return ['success'=>false, 'msg'=>'没有可确定的本地视频分类，已保留原有绑定'];
        if (!self::storeBindings($conf, $code, $bindings + $article)) return ['success'=>false, 'msg'=>self::SAVE_FAILED];
        admin_audit_log('api_bind_auto_all', ['code'=>$code]);
        return ['success'=>true, 'msg'=>'已绑定 '.count($bindings).' 个视频分类'];
    }
}
