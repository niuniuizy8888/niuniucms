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
     * @return array 包含准备信息的数组
     */
    public static function prepare(string $code, int $limit, string $updateMode, string $updateScope, int $uid): array
    {
        api_import_batch_require_tables();
        self::fastCat($code);
        $sourceMeta = api_import_batch_source_meta($code);
        
        $note = $updateMode === 'update' 
            ? '快速采集批次已创建，策略：新增并更新差异字段，范围：' . (self::fastDbUpdateScopeOptions()[$updateScope] ?? $updateScope) 
            : '快速采集批次已创建，策略：仅新增';
            
        $batchId = api_import_batch_create(array(
            'source_code' => $code,
            'source_name' => $sourceMeta['name'] ?? $code,
            'uid' => $uid,
            'status' => 0,
            'limit_num' => $limit,
            'create_date' => time(),
            'note' => $note,
        ));
        
        admin_audit_log('api_fastdb_prepare', array(
            'code' => $code, 
            'batch_id' => intval($batchId), 
            'limit' => $limit, 
            'update_mode' => $updateMode, 
            'update_scope' => $updateScope
        ));
        
        return [
            'batch_id' => $batchId,
            'resstr' => '分类已导入，准备开始第1页数据入库',
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
        $batch = api_import_batch_read($batchId);
        if (empty($batch)) {
            return ['error' => true, 'msg' => '采集批次不存在'];
        }
        
        $result = self::fastDbRun($code, $page, $limit, $batchId, $updateMode, $updateScope);
        
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
        
        $nextp = $page + 1;
        $resstr = '批次 #' . $batchId . ' 第' . $page . '页已处理，新增 ' . intval($result['inserted'] ?? 0) . ' 条，更新 ' . intval($result['updated'] ?? 0) . ' 条，跳过 ' . intval($result['duplicates'] ?? 0) . ' 条，即将开始第' . $nextp . '页';
        
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
     * 分类名经 buildCategoryTree 转义入库，随后按原名/转义名双查生成 $conf['bind'][$code]（分类名=>cid）并 kv_set。
     * 无返回值（产出全在 KV：api/vodplayer/bind 三键）。
     * @param string $code 采集源编码（调用点已限 [a-zA-Z0-9_-]）
     */
    public static function fastCat(string $code)
    {
        global $conf;
        // 修复：统一走 cloudPayload()（商店不加密 / legacy 解密自适应）
        $_codes = \App\Service\System\CloudOpsService::cloudPayload();
        $arrlist = $_codes['api'] ?? array();
        $api = isset($arrlist[$code]['api']) ? $arrlist[$code]['api'] : '';
        $_code = isset($arrlist[$code]['code']) ? $arrlist[$code]['code'] : '';
        $name = isset($arrlist[$code]['name']) ? $arrlist[$code]['name'] : '';
        $play = isset($arrlist[$code]['player']) ? $arrlist[$code]['player'] : '';
        $arr = $conf['api'];
        $arr[$code] = array(
            'api' => $api,
            'code' => $_code,
        );
        $conf['api'] = $arr;
        $vodplayer = $conf['vodplayer'];
        // 站内播放器保护闸：本函数会用采集接口自带的解析地址整条覆写本线路的播放器条目。
        // 站长若已把该线路改成站内本地播放器（parse 留空）或站内播放页（/ 开头路径），
        // 下一次「导入分类」就会把它悄悄写回第三方解析站——症状是「播放器设置没动过却又外链了」。
        // 因此：仅当现有条目是第三方完整地址（或本线路还没有条目）时才接受接口下发的解析地址。
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
        // 修复：分类来源三级回落，取不到就**明确报错**——旧实现拿可能为空的 `fast_type`
        // 直接 http_get('')，静默建 0 个分类，却在 prepare() 里回「分类已导入，准备开始第1页数据入库」。
        //   ① `fast_type`：商店 fast DB 的分类端点（带 type_pid/type_mid，可建父子树）
        //   ② `fast_down`：同源 fast DB 数据端点（class 同形状）
        //   ③ 普通 `api` 接口补 `ac=list`（新商店 api_sources.fast_* 实测 12/12 为空、且商店无
        //      /fast/<code>.json ⇒ 没有这条回落就永远建不出分类）
        $apiarr = array();
        foreach (array('fast_type', 'fast_down') as $_fk) {
            if (empty($arrlist[$code][$_fk])) continue;
            $_fu = \App\Service\System\CloudOpsService::storeUrl((string)$arrlist[$code][$_fk]);
            if ('' === $_fu) continue;
            $_fcls = xn_json_decode(http_get($_fu));
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
        });
        $catelist = \App\Service\Content\CateService::findFmt();
        $arrlista = arrlist_key_values($catelist, 'name', 'cid');
        $catelistarr = array();
        foreach ($apiarr as $value) {
            $sitetype = $value['type_name'];
            // 分类名现按转义值入库（buildCategoryTree），但存量分类可能仍是原始名，故原名/转义名双查取其一
            $tname = htmlspecialchars((string)$sitetype, ENT_QUOTES, 'UTF-8');
            $match_cid = $arrlista[$sitetype] ?? $arrlista[$tname] ?? '';
            if (!empty($match_cid)) {
                $catelistarr[] = array(
                    'cid' => $match_cid,
                    'name' => $sitetype,
                );
            }
        }
        $catelistarr = arrlist_key_values($catelistarr, 'name', 'cid');
        $apiconf = $conf['bind'];
        unset($apiconf[$code]);
        $apiconf[$code] = $catelistarr;
        $conf['bind'] = $apiconf;
        kv_set('bind', $conf['bind']);
    }

    /**
     * 按接口分类结构（arrlist_chunk 输出：0=>顶层, type_id=>子级）创建分类树
     * 供 fastCat / ApiMiscService::autoInto 复用
     * @param array $r 分类结构
     * @param array $typearr 分类基础字段（引用传递：model 覆盖会跨分类累积生效，保持原 fastCat 语义）
     * @param callable|null $modelResolver 可选：输入顶层分类 value，返回 model 覆盖值或 NULL 不覆盖
     */
    public static function buildCategoryTree($r, array &$typearr, $modelResolver = NULL): void
    {
        // 分类名入库转义（红线「输出到 HTML 的内容必须转义」入库侧落点，前台导航裸 echo 依赖此）；
        // 为兼容存量未转义的特殊字符分类，查重时原名/转义名双查，两查皆无才以转义名新建，避免重复分类
        $resolve_cate = function ($raw) {
            $esc = htmlspecialchars((string)$raw, ENT_QUOTES, 'UTF-8');
            $hit = \App\Repository\RepositoryFactory::cate()->findOne(array('name' => $esc));
            if (empty($hit) && (string)$raw !== $esc) {
                $hit = \App\Repository\RepositoryFactory::cate()->findOne(array('name' => (string)$raw));
            }
            return array($esc, $hit);
        };
        foreach (($r[0] ?? array()) as $value) {
            $tname = $resolve_cate($value['type_name']);
            $escName = $tname[0];
            $typeData = $tname[1];
            if (isset($r[$value['type_id']])) {
                if ($modelResolver) {
                    $m = call_user_func($modelResolver, $value);
                    $m !== NULL and $typearr['model'] = $m;
                }
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