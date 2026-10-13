<?php
namespace App\Service\Admin;

class SystemMaintenanceService
{
    /**
     * 清缓存执行体（本方法不自带门禁；调用链 route/set.php case 'cache' POST → SetManageService::clearCache，
     * 组权限位 manageset + CSRF 场景 'admin_set_cache'）。
     * 动作序列：清 30 天前排行历史（art/vod）→（可选）cache_truncate + 页缓存版本强制推进（R6-04：
     * 防版本令牌回落 '0' 命中其他 worker 的旧 APCu 条目）→ 重置 website KV 的 grouplist 镜像 →
     * （可选）clearTmpDir 精确删编译产物（保留熔断/锁/签名状态文件）→ tmpl_prewarm 预热（异常仅记 cache_error，不影响返回值）→ 审计。
     * @param int $clear_tmp 1=同时删除编译产物（$cache_path）
     * @param int $clear_cache 1=清空 KV/多层缓存
     * @param string $cache_path 编译产物目录（$conf['cache_path']）
     * @return array{success:true, msg:string} 恒成功（各子步骤内部容错）
     */
    public static function clearCache(int $clear_tmp, int $clear_cache, string $cache_path): array
    {
        global $runtime;
        \App\Service\Content\ArtService::clearTopHistory(30);
        \App\Service\Content\VideoService::clearTopHistory(30);
        $clear_cache and cache_truncate();
        // R6-04：清空缓存会一并清掉版本令牌，若版本回落 '0'，其他 FPM worker 的
        // 旧 APCu 条目（_cv0）可能被重新命中——清空后强制推进一次版本，保持单调失效。
        $clear_cache and function_exists('page_cache_bump') and page_cache_bump(TRUE);
        $clear_cache and $runtime = NULL;
        $g_website = kv_cache_get('website');
        if (!is_array($g_website)) $g_website = array();
        $g_website['grouplist'] = '';
        kv_cache_set('website', $g_website);
        // 精确清编译产物（白名单扩名 + 跳过点文件与 lock_*）：见 AddonService::clearTmpDir() 注释——
        // 曾经的 rmdir_recusive($cache_path) 会连坐删除 .breaker_state.php（含熔断期补偿删除队列）、
        // .addon_hooksigs.json、.sess_col_limits.php 与活动互斥锁。
        $clear_tmp and \App\Service\System\AddonService::clearTmpDir($cache_path);
        // R7-P02：清缓存（尤其删编译产物）后预热模板编译，消除部署/清缓存后
        // 首访与并发首访的模板编译 + 冷渲染尖峰。tmpl_prewarm 为只读渲染，异常不影响清缓存结果。
        if (function_exists('tmpl_prewarm')) {
            try { tmpl_prewarm(false, true); } catch (\Throwable $e) {
                function_exists('xn_log') and xn_log('tmpl_prewarm after clearCache failed: ' . $e->getMessage(), 'cache_error');
            }
        }
        admin_audit_log('cache_cleanup', array('clear_tmp' => intval(!empty($clear_tmp)), 'clear_cache' => intval(!empty($clear_cache))));
        return array('success' => true, 'msg' => lang('admin_clear_successfully'));
    }

    /**
     * 系统升级执行体（门禁在 SetManageService::upgrade：CSRF 场景 'admin_set_upgrade' + manageset；
     * 版本由该层以 cloudVersionFresh() 现查后传入）。
     * 升级包地址只认云端 down_path 并经 cloudUrl() 归一（旧键名不再兜底）；cloud_api 未配置 =
     * fail-closed message(-1)。成功路径：CloudOpsService::updateDown → 全套缓存清理（同 clearCache 语义）
     * → 审计记「config.php 实际回读版本」（updateDown 以包内自陈版本落盘，与传入值可能不同）。
     * @param string $cloud_version 期望升级到的版本号（调用层已现查刷新）
     * @param string $cache_path 编译产物目录（升级后强制清理）
     * @return array{success:bool, msg:string} success=false 附排查方向（权限/属主不可写是常见根因，细节在 upgrade_error 日志）
     */
    public static function upgrade(string $cloud_version, string $cache_path): array
    {
        global $runtime;
        $down_meta = \App\Service\System\CloudOpsService::codes();
        if (!is_array($down_meta)) {
            message(-1, '未配置云端地址（cloud_api），无法获取升级包');
        }
        // 升级包地址**只认 API.md §0.1 的 `down_path`**（`cloud_api` 为唯一源）。
        // 旧官方键名（upgrade/down/down_url/package/zip）已随官方云端一并退出，不再兜底。
        // 走 cloudUrl() 归一是必须的：商店下发的绝对 URL 用商店自己的 site_url，不归一会指向不可达主机。
        $down_path = (!empty($down_meta['down_path']) && is_string($down_meta['down_path']))
            ? \App\Service\System\CloudOpsService::cloudUrl($down_meta['down_path'])
            : '';
        empty($down_path) and message(-1, '未获取到升级包地址（商店 api.php 未提供 down_path）');
        $r = \App\Service\System\CloudOpsService::updateDown($down_path, $cloud_version);
        if (false === $r) {
            // 注：失败原因不止"下载/解压"——站点内存在非 www 属主/不可写文件时，
            // 升级会**预检中止或覆盖不完整**（旧实现静默跳过＝升级假成功）。文案里给出排查方向，
            // 细节见 runtime/log 的 upgrade_error（xn_log 恒落盘通道）。
            return array('success' => false, 'msg' => '升级失败：更新包下载/解压失败，或站点内有文件不可写（属主/权限）导致覆盖不完整'
                . '——详见 runtime/log 下的 upgrade_error，可执行 chown -R www:www 站点根后重试');
        }
        cache_truncate();
        function_exists('page_cache_bump') and page_cache_bump(TRUE);
        $runtime = NULL;
        $g_website = kv_cache_get('website');
        if (!is_array($g_website)) $g_website = array();
        $g_website['grouplist'] = '';
        kv_cache_set('website', $g_website);
        \App\Service\System\AddonService::clearTmpDir($cache_path);
        // 审计记"实际落盘版本"：updateDown 以**包内自陈版本**为准写回 config.php
        // （传入值可能来自 600s 缓存/本站版本），从配置里回读一次，审计才与站点真实状态一致。
        $applied = $cloud_version;
        $cfgFile = APP_PATH . 'core/config/config.php';
        if (is_file($cfgFile)) {
            $cfgSrc = (string)@file_get_contents($cfgFile);
            if (preg_match("#^[ \t]*'version'[ \t]*=>[ \t]*'([0-9]+(?:\.[0-9]+){1,3})'#m", $cfgSrc, $mV)) {
                $applied = $mV[1];
            }
        }
        admin_audit_log('system_upgrade', array('version' => $applied, 'requested_version' => $cloud_version));
        return array('success' => true, 'msg' => '更新版本成功');
    }

    /**
     * 清空站点数据执行体——**破坏性最高**的维护入口（门禁在 SetManageService::clearData：
     * CSRF 场景 'admin_rm' + manageset）。必须显式勾选 $clear_data=1 才动作（防误触）。
     * truncate 白名单 16 表（内容/分类/标签/会话/排行/缓存，逐表 RepositoryFactory::table()->truncate()）；
     * **不动** user/group/config/audit 等账号与配置表。随后清空 vodplayer/api/bind 三组 KV、
     * 重置 tg/email/agg 运行时配置、全套缓存清理与编译产物删除，审计 data_clear。
     * @param int $clear_data 确认开关（0=拒绝执行）
     * @param string $cache_path 编译产物目录
     * @return array{success:bool, msg:string} success=false 有两种：未勾选确认，或**部分表 truncate 失败**
     *         （msg 会列出失败表名；底层 PdoDriver::truncate 已修成如实返回，故此处可感知）。
     *         注意失败时其余表已清空，属部分完成状态，msg 已提示需重试/核对。
     */
    public static function clearAllData(int $clear_data, string $cache_path): array
    {
        $conf = _SERVER('conf');         global $runtime;
        if (!$clear_data) {
            return array('success' => false, 'msg' => '请勾选"清空所有数据"确认框');
        }
        $clearTables = array(
            'art' => 'aid', 'art_aid' => 'aid', 'cache' => 'id', 'cate' => 'cid',
            'link' => 'id', 'session' => 'sid', 'session_data' => 'id', 'tag' => 'tagid',
            'tag_id' => 'id', 'vod' => 'vid', 'vod_sticky' => 'vid', 'vod_vid' => 'vid',
            'vod_top' => 'vid', 'vod_top_ip' => 'id', 'art_top' => 'aid', 'art_top_ip' => 'id',
        );
        $failed = array();
        foreach ($clearTables as $_table => $_pk) {
            // ⚠ 不再忽略返回值：PdoDriver::truncate 修正后可如实报失败，而这是破坏性最高的入口，
            //   静默"清空成功"会让运营以为站点已清空、实际残留半截数据（比失败本身更坏）。
            false === \App\Repository\RepositoryFactory::table($_table, $_pk)->truncate() and $failed[] = $_table;
        }

        config_runtime_set_values(array('tg' => '', 'email' => '', 'agg' => ''));
        $conf['vodplayer'] = array();
        $conf['api'] = array();
        $conf['bind'] = array();
        kv_set('vodplayer', $conf['vodplayer']);
        kv_set('api', $conf['api']);
        kv_set('bind', $conf['bind']);

        cache_truncate();
        function_exists('page_cache_bump') and page_cache_bump(TRUE);
        $runtime = NULL;
        $g_website = kv_cache_get('website');
        if (!is_array($g_website)) $g_website = array();
        $g_website['grouplist'] = '';
        kv_cache_set('website', $g_website);
        \App\Service\System\AddonService::clearTmpDir($cache_path);
        admin_audit_log('data_clear', array('clear_data' => $clear_data, 'failed_tables' => $failed));
        if (!empty($failed)) {
            // 如实报失败：此时其余表已清空，属**部分完成**状态，必须让运营看到并去核对
            return array('success' => false, 'msg' => '部分表清空失败：' . implode(', ', $failed)
                . '（其余表已清理；失败原因见 runtime/log 的 db_error 通道，请重试或手工核对残留）');
        }
        return array('success' => true, 'msg' => '数据清理成功');
    }

    /**
     * 批量添加站群域名：委托 CloudOpsService::siteBatchAdd（逐行域名格式校验 + 白名单落盘在彼处）。
     * 门禁在 SetManageDomainTrait::addDomain（CSRF 场景 'admin_domain_add' + manageset）；
     * 入参已由调用点 admin_param_text('batch_sites', '', 100000) 限长 100KB。
     * @param string $input 多行域名清单原始文本
     * @return array{success:true, msg:string} msg 为 siteBatchAdd 的结果摘要（success 恒为 true，失败信息看 msg）
     */
    public static function batchAddDomainSites(string $input): array
    {
        $res = \App\Service\System\CloudOpsService::siteBatchAdd($input);
        admin_audit_log('domain_batch_add', array());
        return array('success' => true, 'msg' => $res);
    }

    /**
     * 创建 ES 搜索索引（门禁在 SetManageEsTrait::createEs，场景 'admin_set_es' + manageset）。
     * 实际请求与错误处理在 ElasticService::createIndex；本方法只记审计并包装返回。
     * @return array{success:true, msg:string}
     */
    public static function esCreate(): array
    {
        admin_audit_log('es_create', array());
        \App\Service\System\ElasticService::createIndex();
        return array('success' => true, 'msg' => 'es索引创建成功');
    }

    /**
     * 删除 ES 搜索索引（门禁在 SetManageEsTrait::deleteEs，场景 'admin_set_es'）。
     * 不可逆操作但本层无二次确认开关——确认逻辑在后台页面/调用层。
     * @return array{success:true, msg:string}
     */
    public static function esDelete(): array
    {
        admin_audit_log('es_del', array());
        \App\Service\System\ElasticService::clearIndex();
        return array('success' => true, 'msg' => 'es索引删除成功');
    }

    /**
     * 全量视频名批量灌入 ES：LIMIT 分页（每页 100）扫描 vod 的 vid/name 两列（显式列清单，
     * 避开 play_url 大字段），逐页 ElasticService::bulkInsert 并按响应 items 的 HTTP 状态计数。
     * 深分页 OFFSET 形态（技术债 #12），量大时靠 set_time_limit(0) 续命；
     * 失败条目只计数不重试，审计 es_bulk 记 total/success/failed 三口径。
     * @param object $db 数据库连接对象（仅取其 tablepre 拼表名；调用点传路由层 $_SERVER['db']）
     * @return array{success:true, msg:string} msg 为多行统计文本；success 恒 true（failed>0 也如此，看 msg 区分）
     */
    public static function esBulkImport($db): array
    {
        set_time_limit(0);
        $page_size = 100;
        $page = 1;
        $total_success = 0;
        $total_failed = 0;
        $tablepre = $db->tablepre;
        $t_vod = $tablepre . 'vod';
        $countRow = \App\Repository\RepositoryFactory::vod()->builder()->select(array('COUNT(*) AS total'))->first();
        $total = intval(array_value($countRow, 'total', 0));
        while (true) {
            $offset = ($page - 1) * $page_size;
            $rows = \App\Repository\RepositoryFactory::vod()->builder()->select(array('vid', 'name'))->limit($page_size, $offset)->get();
            if (empty($rows) || !is_array($rows)) {
                break;
            }
            $bulk_data = array();
            foreach ($rows as $row) {
                $vid = intval(array_value($row, 'vid', 0));
                $name = trim((string)array_value($row, 'name', ''));
                if ($vid > 0 && $name) {
                    $bulk_data[] = array('vid' => $vid, 'name' => $name);
                }
            }
            if (!empty($bulk_data)) {
                $result = \App\Service\System\ElasticService::bulkInsert($bulk_data);
                $success = 0;
                $failed = 0;
                if (!empty($result['items']) && is_array($result['items'])) {
                    foreach ($result['items'] as $item) {
                        if (isset($item['index']['status']) && $item['index']['status'] >= 200 && $item['index']['status'] < 300) {
                            $success++;
                        } else {
                            $failed++;
                        }
                    }
                }
                $total_success += $success;
                $total_failed += $failed;
            }
            $page++;
            if (count($rows) < $page_size) {
                break;
            }
        }
        admin_audit_log('es_bulk', array('total' => $total, 'success' => $total_success, 'failed' => $total_failed));
        $msg = "批量写入完成！\n";
        $msg .= "总数据量: {$total}\n";
        $msg .= "成功: {$total_success}\n";
        $msg .= "失败: {$total_failed}\n";
        return array('success' => true, 'msg' => $msg);
    }
}
