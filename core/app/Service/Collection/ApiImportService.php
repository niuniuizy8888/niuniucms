<?php
namespace App\Service\Collection;

class ApiImportService
{
    use \App\Service\Collection\Traits\ApiImportFastDbTrait;
    /**
     * 快速采集 - 预览阶段
     * 
     * @param string $code 采集源编码
     * @param string $updateScope 更新范围
     * @return array 预览数据与视图参数
     */
    public static function preview(string $code, string $updateScope): array
    {
        api_import_batch_require_tables();
        $preview = self::fastDbPreview($code, 12, $updateScope);
        $updateScopeOptions = self::fastDbUpdateScopeOptions();
        
        admin_audit_log('api_fastdb_preview', array(
            'code' => $code,
            'total' => intval($preview['total'] ?? 0),
            'importable' => intval($preview['importable'] ?? 0),
            'updatable' => intval($preview['updatable'] ?? 0),
            'unchanged' => intval($preview['unchanged'] ?? 0),
            'unbound' => intval($preview['unbound'] ?? 0),
            'update_scope' => $updateScope,
        ));
        
        return [
            'preview' => $preview,
            'update_scope_options' => $updateScopeOptions,
        ];
    }

    /**
     * 快速采集 - 准备阶段 (创建批次)
     * 
     * @param string $code 采集源编码
     * @param int $limit 分页大小
     * @param string $updateMode 更新模式
     * @param string $updateScope 更新范围
     * @param int $uid 操作用户ID
     * @param bool $canCreateCategories 调用方按当前用户权限取得；未授权时只绑定已有分类
     * @return array 包含准备信息的数组
     */
    public static function prepare(string $code, int $limit, string $updateMode, string $updateScope, int $uid, bool $canCreateCategories = false): array
    {
        api_import_batch_require_tables();
        if ($limit < 1 || $limit > 50000 || !in_array($updateMode, ['skip','update'], true)
            || !array_key_exists($updateScope, self::fastDbUpdateScopeOptions())) throw new \InvalidArgumentException('快速采集策略无效');
        $categories = self::fastCat($code, $canCreateCategories);
        if (!$categories['bound']) throw new \RuntimeException('没有可采集的已绑定视频分类，请先在视频采集中配置分类');
        $sourceMeta = api_import_batch_source_meta($code);
        
        $note = $updateMode === 'update' 
            ? '快速采集批次已创建，策略：新增并更新差异字段，范围：' . (self::fastDbUpdateScopeOptions()[$updateScope] ?? $updateScope) 
            : '快速采集批次已创建，策略：仅新增';
            
        $db = _SERVER('db');
        if ($db->inTransaction() || !$db->beginTransaction()) throw new \RuntimeException('无法创建采集批次');
        try {
            $batchId = api_import_batch_create(array(
                'source_code' => $code,
                'source_name' => $sourceMeta['name'] ?? $code,
                'uid' => $uid,
                'status' => 0,
                'limit_num' => $limit,
                'create_date' => time(),
                'note' => $note,
            ));
            if (!$batchId) throw new \RuntimeException('无法创建采集批次');
            CollectionBatchStateService::save((int)$batchId, ['v'=>1, 'items_version'=>1, 'code'=>$code, 'limit'=>$limit, 'mode'=>$updateMode, 'scope'=>$updateScope]);
            if (!$db->commit()) throw new \RuntimeException('采集批次保存失败');
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
        
        admin_audit_log('api_fastdb_prepare', array(
            'code' => $code, 
            'batch_id' => intval($batchId), 
            'limit' => $limit, 
            'update_mode' => $updateMode, 
            'update_scope' => $updateScope
        ));
        
        return [
            'batch_id' => $batchId,
            'resstr' => '将按已绑定的 ' . $categories['bound'] . ' 个视频分类采集，准备开始第 1 页数据入库',
        ];
    }

    /**
     * 快速采集 - 导入阶段 (执行分页入库)
     * 
     * @param string $code 采集源编码
     * @param int $page 当前页码
     * @param int $limit 分页大小
     * @param int $batchId 批次ID
     * @param string $updateMode 更新模式
     * @param string $updateScope 更新范围
     * @return array 导入结果及下一页状态
     */
    public static function import(string $code, int $page, int $limit, int $batchId, string $updateMode, string $updateScope): array
    {
        api_import_batch_require_tables();
        try {
            $result = self::fastDbRun($code, $page, $limit, $batchId, $updateMode, $updateScope);
        } catch (\RuntimeException $e) {
            return ['error'=>true, 'msg'=>$e instanceof \PDOException ? '批次保存失败，请重试当前页' : $e->getMessage()];
        }
        
        if (($result['status'] ?? '') === 'done') {
            $batch = api_import_batch_read($batchId);
            admin_audit_log('api_fastdb_finish', array(
                'code' => $code, 
                'batch_id' => intval($batchId), 
                'total' => intval($result['total'] ?? 0)
            ));
            
            $doneMsg = '批次导入完成，累计新增 ' . intval($batch['imported_num'] ?? 0) . ' 条';
            if (api_import_batch_supports_updated_num()) {
                $doneMsg .= '，更新 ' . intval($batch['updated_num'] ?? 0) . ' 条';
            }
            
            return [
                'done' => true,
                'msg' => $doneMsg,
            ];
        }
        
        $nextp = (int)$result['next_page'];
        $resstr = '批次 #' . $batchId . ' 第' . (int)$result['page'] . '页已处理，新增 ' . intval($result['inserted'] ?? 0) . ' 条，更新 ' . intval($result['updated'] ?? 0) . ' 条，跳过 ' . intval($result['duplicates'] ?? 0) . ' 条，即将开始第' . $nextp . '页';
        
        return [
            'done' => false,
            'next_page' => $nextp,
            'resstr' => $resstr,
        ];
    }

    /**
     * 快速采集「导入分类」步骤（由 prepare() 在建批次前调用；门禁在 route/api.php case 'fastdb'：
     * POST + admin_require_bull_token('admin_api_fastdb') + manageapi + code 白名单，本方法自身无门禁）。
     * 动作：把商店 cloudPayload 里该源的 api/player 条目写入运行时配置——其中 vodplayer 的 parse
     * 有**站内播放器保护闸**（现值为 inline/same 时保留站长本地配置，只接受第三方完整地址形态，注释在案）；
     * 分类取数三级回落 fast_type → fast_down → 源站 api(ac=list)，全落空则 message(-1) 明确报错（不再静默建 0 类）。
     * 仅授权创建时调用 buildCategoryTree；视频新绑定使用已核对的来源分类编号。
     * @param string $code 采集源编码（调用点已限 [a-zA-Z0-9_-]）
     * @param bool $canCreateCategories 调用方取得的创建分类权限，默认不授权
     * @return array{bound:int, skipped:int} 已绑定与未绑定的源分类数量
     */
    public static function fastCat(string $code, bool $canCreateCategories = false): array
    {
        global $conf;
        $model = CollectionCategoryService::sourceModel($code, $conf);
        if ($model === 1) throw new \RuntimeException('所选资源站用于文章采集，请选择视频资源站');
        if (isset($conf['api'][$code]) && array_key_exists($code, $conf['bind'] ?? [])) {
            $saved = (array)$conf['bind'][$code];
            $catalog = VideoCategoryCatalog::forImport((string)($conf['api'][$code]['api'] ?? ''), $saved, [], $code);
            $bindings = CollectionCategoryService::videoBindings($saved, catalog: $catalog);
            $bound = count($bindings['ids']);
            return ['bound'=>$bound, 'skipped'=>count($saved)-$bound];
        }
        $_codes = \App\Service\System\CloudOpsService::cloudPayload();
        $arrlist = $_codes['api'] ?? array();
        if (empty($arrlist[$code]) || empty($arrlist[$code]['api'])) {
            message(-1, "未在商店清单中找到可用采集源：{$code}");
        }
        $api = (string)$arrlist[$code]['api'];
        $_code = isset($arrlist[$code]['code']) && '' !== (string)$arrlist[$code]['code'] ? (string)$arrlist[$code]['code'] : $code;
        $name = isset($arrlist[$code]['name']) && '' !== (string)$arrlist[$code]['name'] ? (string)$arrlist[$code]['name'] : $code;
        $play = isset($arrlist[$code]['player']) ? (string)$arrlist[$code]['player'] : '';
        // 先验证分类数据可获取，再写入 kv('api')/kv('vodplayer')，避免失败路径污染 KV
        $apiarr = array();
        foreach (array('fast_type', 'fast_down') as $_fk) {
            if (empty($arrlist[$code][$_fk])) continue;
            $_fu = \App\Service\System\CloudOpsService::storeUrl((string)$arrlist[$code][$_fk]);
            if ('' === $_fu) continue;
            $_fcls = xn_json_decode(http_get($_fu, '', 30, 3, 'cloud'));
            if (!empty($_fcls['class']) && is_array($_fcls['class'])) {
                $apiarr = $_fcls['class'];
                break;
            }
        }
        if (empty($apiarr)) {
            $apiarr = \App\Service\Collection\ApiExecutionService::sourceClassList($code, $api);
        }
        if (empty($apiarr)) {
            message(-1, "未取到 {$code} 的分类数据（商店未配置 fast_type/fast_down，且源站 api 未返回 class）——请在商店侧补齐快速通道，或改用「采集」");
        }
        $arr = $conf['api'] ?? array();
        $arr[$code] = array(
            'api' => $api,
            'code' => $_code,
            'model' => 0,
        );
        $conf['api'] = $arr;
        $vodplayer = $conf['vodplayer'] ?? array();
        $parse = $play;
        $tip = '无需安装任何插件';
        if (isset($vodplayer[$code]['parse'])) {
            $kind = player_parse_kind($vodplayer[$code]['parse']);
            if ('inline' === $kind || 'same' === $kind) {
                $parse = (string) $vodplayer[$code]['parse'];
                $tip = '站内本地播放器，无需安装任何插件';
            }
        }
        unset($vodplayer[$code]);
        $vodplayer[$code] = array(
            'status' => '1',
            'from' => $code,
            'show' => $name,
            'des' => $name,
            'target' => '_self',
            'ps' => '1',
            'parse' => $parse,
            'sort' => '1000',
            'tip' => $tip,
        );
        $conf['vodplayer'] = $vodplayer;
        kv_set('api', $conf['api']);
        kv_set('vodplayer', $conf['vodplayer']);
        $res2arr = $apiarr;
        $r = arrlist_chunk($res2arr, 'type_pid');
        $typearr = array(
            'type' => 1,
            'model' => 0,
            'display' => 0,
            'nav_display' => 1,
            'seo_title' => '',
            'seo_key' => '',
            'seo_des' => '',
            'create_date' => time(),
            'alias' => '',
        );
        self::buildCategoryTree($r, $typearr, function ($value) {
            $mid = intval($value['type_mid'] ?? 0);
            if ($mid == 1) return 0;
            if ($mid == 2) return 1;
            return NULL;
        }, $canCreateCategories);
        $bindings = CollectionSetupService::automaticVideoBindings($code, (string)$api, [], $apiarr);
        if (!ApiBindService::storeBindings($conf, $code, $bindings)) throw new \RuntimeException('分类绑定已变更，请重新读取后再采集');
        $conf['bind'][$code] = $bindings;
        return ['bound'=>count($bindings), 'skipped'=>count($apiarr)-count($bindings)];
    }

    /**
     * 按接口分类结构（arrlist_chunk 输出：0=>顶层, type_id=>子级）创建分类树
     * 供 fastCat / ApiMiscService::autoInto 复用
     * @param array $r 分类结构
     * @param array $typearr 分类基础字段（引用传递：model 覆盖会跨分类累积生效，保持原 fastCat 语义）
     * @param callable|null $modelResolver 可选：输入顶层分类 value，返回 model 覆盖值或 NULL 不覆盖
     * @param bool $canCreateCategories 创建分类能力；调用方未显式授权时不写分类
     */
    public static function buildCategoryTree($r, array &$typearr, $modelResolver = NULL, bool $canCreateCategories = false): void
    {
        if (!$canCreateCategories) return;
        // 分类名入库转义（红线「输出到 HTML 的内容必须转义」入库侧落点，前台导航裸 echo 依赖此）；
        // 为兼容存量未转义的特殊字符分类，查重时原名/转义名双查，两查皆无才以转义名新建，避免重复分类
        $resolve_cate = function ($raw) use (&$typearr) {
            $esc = htmlspecialchars((string)$raw, ENT_QUOTES, 'UTF-8');
            $find = static function ($name) use (&$typearr) {
                return \App\Repository\RepositoryFactory::cate()->builder()->select(['cid', 'name', 'model', 'category'])
                    ->where('name', '=', $name)->where('model', '=', (int)$typearr['model'])->where('category', '<>', 2)->lock()->first();
            };
            $hit = $find($esc);
            if (empty($hit) && (string)$raw !== $esc) {
                $hit = $find((string)$raw);
            }
            return array($esc, $hit);
        };
        foreach (($r[0] ?? array()) as $value) {
            if ($modelResolver) {
                $m = call_user_func($modelResolver, $value);
                $m !== NULL and $typearr['model'] = $m;
            }
            $tname = $resolve_cate($value['type_name']);
            $escName = $tname[0];
            $typeData = $tname[1];
            if (isset($r[$value['type_id']])) {
                if (!empty($typeData)) {
                    $parentId = $typeData['cid'];
                    $subCategories = $r[$value['type_id']];
                    foreach ($subCategories as $v) {
                        $sub = $resolve_cate($v['type_name']);
                        if (empty($sub[1])) {
                            $typearr['cup'] = $parentId;
                            $typearr['name'] = $sub[0];
                            $typearr['category'] = 0;
                            $_cid = \App\Repository\RepositoryFactory::cate()->insert($typearr);
                            $_cid and \App\Repository\RepositoryFactory::cate()->update(array('cid' => $parentId), array('son+' => 1));
                            cate_list_cache_delete();
                        }
                    }
                } else {
                    $typearr['cup'] = 0;
                    $typearr['name'] = $escName;
                    $typearr['category'] = 1;
                    $_cid = \App\Repository\RepositoryFactory::cate()->insert($typearr);
                    $parentIds = $_cid;
                    $subCategories = $r[$value['type_id']];
                    foreach ($subCategories as $v) {
                        $sub = $resolve_cate($v['type_name']);
                        if (empty($sub[1])) {
                            $typearr['cup'] = $parentIds;
                            $typearr['name'] = $sub[0];
                            $typearr['category'] = 0;
                            \App\Repository\RepositoryFactory::cate()->insert($typearr);
                            \App\Repository\RepositoryFactory::cate()->update(array('cid' => $parentIds), array('son+' => 1));
                            cate_list_cache_delete();
                        }
                    }
                }
            } else {
                if (empty($typeData)) {
                    $typearr['cup'] = 0;
                    $typearr['name'] = $escName;
                    $typearr['category'] = 0;
                    \App\Repository\RepositoryFactory::cate()->insert($typearr);
                }
            }
        }
    }

}
