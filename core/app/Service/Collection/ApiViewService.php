<?php
namespace App\Service\Collection;

class ApiViewService
{
    /**
     * 采集 API 管理页数据：云码表 payload 的 list 段 + 一次性预签发 8 个写场景的 safe_token
     * （bull_token_set 同请求复用同一 token、逐场景写入会话指纹环；仅 admin_api_set 的回执下发视图，其余登记后丢弃返回值）。
     * 另回显 cate/vod/art 三表 COUNT(*)（「清空分类」按钮的破坏范围提示）。
     * @param int $uid 当前管理员 uid
     * @return array {arrlist, safe_token, cate_total, vod_total, art_total}
     */
    public static function listPage(int $uid): array
    {
        $decoded = self::decodedCodePayload();
        $safe_token = bull_token_set($uid, 'admin_api_set');
        bull_token_set($uid, 'admin_api_setdel');
        bull_token_set($uid, 'admin_api_create');
        bull_token_set($uid, 'admin_api_cate');
        bull_token_set($uid, 'admin_api_newcreate');
        bull_token_set($uid, 'admin_api_catedel');
        bull_token_set($uid, 'admin_api_import');
        bull_token_set($uid, 'admin_api_auto');

        return array(
            'arrlist' => isset($decoded['list']) ? $decoded['list'] : array(),
            'safe_token' => $safe_token,
            // 「清空分类」按钮的破坏范围提示（栏目/视频/文章总量，仅 COUNT(*)）
            'cate_total' => \App\Repository\RepositoryFactory::cate()->count(),
            'vod_total' => \App\Repository\RepositoryFactory::vod()->count(),
            'art_total' => \App\Repository\RepositoryFactory::art()->count(),
        );
    }

    /**
     * 文章采集 API 管理页数据：云码表 payload 的 artapi 段 + 预签发 5 个写场景令牌（admin_api_set_art 回执视图，其余仅入指纹环）。
     * @param int $uid 当前管理员 uid
     * @return array {arrlist, safe_token}
     */
    public static function artListPage(int $uid): array
    {
        $decoded = self::decodedCodePayload();
        $safe_token = bull_token_set($uid, 'admin_api_set_art');
        bull_token_set($uid, 'admin_api_artdel');
        bull_token_set($uid, 'admin_api_art_auto');
        bull_token_set($uid, 'admin_api_art_create');
        bull_token_set($uid, 'admin_api_import');

        return array(
            'arrlist' => isset($decoded['artapi']) ? $decoded['artapi'] : array(),
            'safe_token' => $safe_token,
        );
    }

    /**
     * 快速导入（fastdb）页数据：云码表 list 段 + admin_api_fastdb 场景令牌，
     * 并回显采集批次表就绪状态与缺失提示（经 api_import_batch_* 兼容壳取 ApiBatchService 状态，视图据此提示建表）。
     * @param int $uid 当前管理员 uid
     * @return array {arrlist, safe_token, batch_tables_ready(bool), batch_tables_hint(string)}
     */
    public static function fastApiPage(int $uid): array
    {
        $decoded = self::decodedCodePayload();
        return array(
            'arrlist' => isset($decoded['list']) ? $decoded['list'] : array(),
            'safe_token' => bull_token_set($uid, 'admin_api_fastdb'),
            'batch_tables_ready' => api_import_batch_tables_ready(),
            'batch_tables_hint' => api_import_batch_tables_hint(),
        );
    }

    /**
     * 采集源分类绑定页数据：conf.api[code].api 缺失时返回 {status:'error', msg:'采集源未配置'}（不抛错）；
     * 成功时返回源分类树（apiClass）、本站分类 name→cid 映射、现有绑定 conf.bind[code]，
     * 并预签发 admin_api_newcreate（入环）与 admin_api_cate（回执视图）场景令牌。
     * @param string $code 采集源编码
     * @param int $uid 当前管理员 uid
     * @param array $conf 站点配置（api/bind 段由调用方传入，本方法不读容器）
     * @return array {status, api_type, cate, bind, safe_token} 或 {status:'error', msg}
     */
    public static function cateView(string $code, int $uid, array $conf): array
    {
        if (!isset($conf['api'][$code]['api'])) {
            return array('status' => 'error', 'msg' => '采集源未配置');
        }

        $api_type = self::apiClass($code, $conf);
        $typelist = cate_list_cache();
        $cate = arrlist_key_values($typelist, 'name', 'cid');

        bull_token_set($uid, 'admin_api_newcreate');
        return array(
            'status' => 'success',
            'api_type' => $api_type,
            'cate' => $cate,
            'bind' => $conf['bind'][$code] ?? array(),
            'safe_token' => bull_token_set($uid, 'admin_api_cate'),
        );
    }

    /**
     * 取采集源的分类树（远程源接口 class 数据）：$conf 为空回落 _SERVER('conf')；源未配置返回空 array。
     * 实现委托 ApiExecutionService::sourceClassList()（缓存键 api_cls_ 前缀，出网路径；渲染热路径慎调）。
     * @param string $code 采集源编码
     * @param array|null $conf 站点配置（NULL=读容器）
     * @return array 源分类条目列表（含 type_name 等，形态以 sourceClassList 为准）
     */
    public static function apiClass(string $code, ?array $conf = NULL): array
    {
        $conf = $conf ?: (_SERVER('conf') ?: array());
        if (!isset($conf['api'][$code]['api'])) {
            return array();
        }
        // 修复：取源分类统一走 ApiExecutionService::sourceClassList()（缓存键 api_cls_ 前缀保留，
        // 另修掉"根路径无 class 需补 ac=list"与"空结果被缓存 1 小时"两个坑）。
        return \App\Service\Collection\ApiExecutionService::sourceClassList($code, (string)$conf['api'][$code]['api']);
    }

    /**
     * 求「源分类名在本站 cate 表中无同名分类」的差集清单（绑定页提示未映射栏目用）。
     * @param string $code 采集源编码
     * @param array|null $conf 站点配置（透传 apiClass，NULL=读容器）
     * @return array string[] 未绑定的源分类名；源分类为空返回空 array（条目缺 type_name 会触发 undefined key 警告，依赖 sourceClassList 契约）
     */
    public static function apiNoBind(string $code, ?array $conf = NULL): array
    {
        $apiType = self::apiClass($code, $conf);
        $typelist = cate_list_cache();
        $arrlista = arrlist_key_values($typelist, 'name', 'cid');
        $arrlist = array();
        foreach ($apiType as $value) {
            $sitetype = $value['type_name'];
            if (empty($arrlista[$sitetype])) {
                $arrlist[] = $sitetype;
            }
        }
        return $arrlist;
    }

    /**
     * 云码表数据（商店 manifest）：转发 CloudOpsService::cloudPayload() → codes()（600s `code` 缓存；商店返回明文 JSON 直接解析）。
     * cloud_api 未配置或取数失败归一为空 array（调用方按段 isset 兜底）。
     * @return array 解码后的商店负载（含 list/artapi 等段）
     */
    private static function decodedCodePayload(): array
    {
        // 修复：统一走 cloudPayload()——商店返回**不加密**（直接解析），legacy 才 core_decrypt。
        return \App\Service\System\CloudOpsService::cloudPayload();
    }

    /**
     * fastdb 三阶段视图装配（preview/prepare/import）
     * 路由层只负责 include 对应模板；本方法统一生成模板变量与跳转参数
     * @param string $stage preview|prepare|import
     * @return array preview/prepare 返回可直接 extract 的模板变量；
     *               import 返回 array('error','msg','done','view')，view 为模板变量
     */
    public static function fastDbStage($stage, $code, $update_scope = 'all', $uid = 0, $limit = 5000, $update_mode = 'skip', $page = 1, $batch_id = 0)
    {
        $post_params = array(
            'safe_token' => bull_token_set($uid, 'admin_api_fastdb'),
            'code' => $code,
            'limit' => $limit,
            'p' => 1,
            'stage' => 'import',
            'batch_id' => $batch_id,
            'update_mode' => $update_mode,
            'update_scope' => $update_scope,
        );
        $post_action = url('api-fastdb', '', TRUE);
        $batch_link = url('api-batch', array('batch_id' => $batch_id), TRUE);

        if ('preview' === $stage) {
            $previewData = \App\Service\Collection\ApiImportService::preview($code, $update_scope);
            return array(
                'preview' => $previewData['preview'],
                'update_scope_options' => $previewData['update_scope_options'],
                'safe_token' => bull_token_set($uid, 'admin_api_fastdb'),
            );
        }
        if ('prepare' === $stage) {
            $prepareData = \App\Service\Collection\ApiImportService::prepare($code, $limit, $update_mode, $update_scope, intval($uid));
            $post_params['batch_id'] = $prepareData['batch_id'];
            $batch_link = url('api-batch', array('batch_id' => $prepareData['batch_id']), TRUE);
            return array(
                'batch_id' => $prepareData['batch_id'],
                'resstr' => $prepareData['resstr'],
                'post_params' => $post_params,
                'post_action' => $post_action,
                'batch_link' => $batch_link,
            );
        }
        // import 阶段
        $importData = \App\Service\Collection\ApiImportService::import($code, $page, $limit, $batch_id, $update_mode, $update_scope);
        if (!empty($importData['error'])) {
            return array('error' => TRUE, 'msg' => isset($importData['msg']) ? $importData['msg'] : '', 'done' => FALSE, 'view' => array());
        }
        if (!empty($importData['done'])) {
            return array('error' => FALSE, 'msg' => $importData['msg'], 'done' => TRUE, 'view' => array());
        }
        $post_params['p'] = $importData['next_page'];
        $post_params['batch_id'] = $batch_id;
        return array(
            'error' => FALSE,
            'msg' => '',
            'done' => FALSE,
            'view' => array(
                'nextp' => $importData['next_page'],
                'resstr' => $importData['resstr'],
                'post_params' => $post_params,
                'post_action' => $post_action,
                'batch_link' => $batch_link,
            ),
        );
    }
}
