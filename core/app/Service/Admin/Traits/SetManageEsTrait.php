<?php
namespace App\Service\Admin\Traits;

use App\Service\Admin\ConfigRuntimeService;
use App\Service\Admin\SystemMaintenanceService;

/**
 * ES 运维（esPage/saveEs/toggleEs/createEs/deleteEs/bulkEs + layersInfo/domainConfig/esSummary）（从 SetManageService 拆分，Trait 组合）
 */
trait SetManageEsTrait
{
    /**
     * 「设置 → ES」页数据装配（GET）：ElasticService::summary() 健康面板 + 标题 + 场景令牌 admin_set_es。
     * @param array $conf 站点配置（summary 仅需 conf['es']['host'] 等 ES 连接参数）
     * @param int   $uid  当前管理员 uid
     * @return array summary 的固定骨架（health/es_stats/es_count/doc_count/cluster_name/cluster_status/
     *         indices_count/node_count）再叠加 header 与 safe_token（string|FALSE）
     *         ⚠ 面板值来自 ES 集群响应（cluster_name 等由远端决定）：现有消费点 core/admin/html/set_es.html
     *           用 xn_h() 转义、计数走 intval()，新增展示位必须同样显式转义（模板层本身不转义，CLAUDE.md「安全红线·转义分层」）
     */
    public static function esPage(array $conf, int $uid): array
    {
        $summary = self::esSummary($conf);
        $summary['header'] = array(
            'title' => 'ES设置',
        );
        $summary['safe_token'] = bull_token_set($uid, 'admin_set_es');
        return $summary;
    }

    /**
     * 保存 ES 连接参数（POST）：verifyToken(admin_set_es) → 取 5 个字段（admin_param_text 限长）
     * → ConfigRuntimeService::saveEsConfig() 写 KV 运行时层 es 键（不物理改写 config.php）。
     * 主机格式闸在底层（只允许 http/https + 主机字符，SSRF 纵深仍由 ElasticService 请求层承担）；
     * 底层 host 非法时返回 success=false，但本层丢弃 success 只取 msg ⇒ 页面看到提示语、code 仍为 0。
     * @param int $uid 当前管理员 uid
     * @return array {code: 0, message: string} message 为 jump() 结果（3 秒后回 set-es 页）
     */
    public static function saveEs(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_es');
        $payload = array(
            'host' => admin_param_text('host', '', 255),
            'index' => admin_param_text('index', '', 100),
            'user' => admin_param_text('user', '', 100),
            'pass' => admin_param_text('pass', '', 255),
            'api_key' => admin_param_text('apikey', '', 255),
        );
        $result = ConfigRuntimeService::saveEsConfig($payload);
        return array('code' => 0, 'message' => jump($result['msg'], url('set-es', array(), TRUE), 3));
    }

    /**
     * 开/关 ES 搜索（POST）：verifyToken(**admin_set_es_on**，与保存配置的场景令牌不同)
     * → ConfigRuntimeService::setEsSwitch(intval(param('es_on',0))) 写 KV 运行时层单键 es_on。
     * es_on=0 时前台检索回退 MySQL 前导通配 LIKE（CLAUDE.md「开发约定·N+1 红线」的性能口径）。
     * @param int $uid 当前管理员 uid
     * @return array {code: 0, message: string}（底层恒 success=true）
     */
    public static function toggleEs(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_es_on');
        $result = ConfigRuntimeService::setEsSwitch(intval(param('es_on', 0)));
        return array('code' => 0, 'message' => jump($result['msg'], url('set-es', array(), TRUE), 3));
    }

    /**
     * 创建 ES 索引（POST）：verifyToken(admin_set_es) → SystemMaintenanceService::esCreate()
     * → ElasticService::createIndex()（PUT 建索引，1 分片 0 副本，ik 分词）。
     * 出网失败/索引已存在都不抛错，结果只看 ES 响应体；本层 success 恒 true（错误信息不落 code）。
     * @param int $uid 当前管理员 uid
     * @return array {code: 0, message: string}
     */
    public static function createEs(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_es');
        $result = SystemMaintenanceService::esCreate();
        return array('code' => 0, 'message' => jump($result['msg'], url('set-es', array(), TRUE), 3));
    }

    /**
     * 删除 ES 索引数据（POST）：verifyToken(admin_set_es) → SystemMaintenanceService::esDelete()
     * → ElasticService::clearIndex()（_delete_by_query + match_all：清文档、保留索引与 mapping）。
     * 不可逆，二次确认在页面/JS 侧，本层无开关；审计只记 es_del 事件本身。
     * @param int $uid 当前管理员 uid
     * @return array {code: 0, message: string}
     */
    public static function deleteEs(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_es');
        $result = SystemMaintenanceService::esDelete();
        return array('code' => 0, 'message' => jump($result['msg'], url('set-es', array(), TRUE), 3));
    }

    /**
     * 全量视频名灌入 ES（POST）：verifyToken(admin_set_es) → SystemMaintenanceService::esBulkImport($db)。
     * 底层是 set_time_limit(0) + LIMIT/OFFSET 分页扫 vod 的 vid/name 两列、逐页 _bulk（深分页技术债 #12），
     * 失败条目只计数不重试，且 success 恒 true（看 msg 里的 total/success/failed 区分）。
     * @param int    $uid 当前管理员 uid
     * @param object $db  数据库连接对象（路由层传 _SERVER('db')，底层仅取 $db->tablepre 拼表名）
     * @return array {code: 0, message: string} message 为多行统计文本（经 jump() 包装）
     */
    public static function bulkEs(int $uid, $db): array
    {
        self::verifyToken($uid, 'admin_set_es');
        $result = SystemMaintenanceService::esBulkImport($db);
        return array('code' => 0, 'message' => jump($result['msg'], url('set-es', array(), TRUE), 3));
    }

    /**
     * 渲染「缓存层级」提示条 HTML（opPage 用）：多层缓存启用时列出 conf['cache']['multi']['layers'] 的 driver 顺序，
     * 未启用时给黄色警示。仅展示，配置改动口径是编辑 config/config.php（缓存层级属出厂/站点层，非 KV 运行时层）。
     * @param array $conf 站点配置
     * @return string Bootstrap alert HTML（仅含 config/config.php 里的驱动名与固定文案，非请求可控数据）
     */
    private static function layersInfo(array $conf): string
    {
        if (!empty($conf['cache']['multi']['enable'])) {
            $layers = $conf['cache']['multi']['layers'] ?? array();
            $names = array_column($layers, 'driver');
            return '<div class="alert alert-info mb-0">'
                . '<strong>多层缓存已启用</strong><br>'
                . '缓存层级顺序：' . implode(' → ', $names)
                . '<br><small class="text-secondary">如需修改缓存配置，请编辑 config/config.php</small>'
                . '</div>';
        }

        return '<div class="alert alert-warning mb-0">'
            . '<strong>多层缓存未启用</strong><br>'
            . '当前使用传统单驱动缓存模式。'
            . '<br><small class="text-secondary">建议在 config/config.php 中配置 cache.multi 启用多层缓存</small>'
            . '</div>';
    }

    /**
     * 载入站群域名配置：include APP_PATH . 'core/config/domain.php' 取其 return 数组。
     * 该文件属启动期配置（index.php 在 KV 注入前读取覆盖 $conf），写侧由 SetManageDomainTrait
     * 经 ConfigBootstrapService 物理改写，**不走 KV 运行时层**。
     * ⚠ 每次调用都重新 include（无 static 缓存），domain.php 缺失/语法坏会直接抛错。
     * @return array domain.php 返回的配置数组（键含 site 站群映射等）
     */
    private static function domainConfig(): array
    {
        return include \APP_PATH . 'core/config/domain.php';
    }

    /**
     * ES 健康面板数据装配转发：ElasticService::summary($conf)（调 stats()+count() 摊平成展示字段）。
     * status 非 green/yellow（含请求失败）一律显示「异常」——展示层 fail-closed。
     * @param array $conf 站点配置（读 conf['es']）
     * @return array {health, es_stats, es_count, doc_count, cluster_name, cluster_status, indices_count, node_count}
     */
    private static function esSummary(array $conf): array
    {
        return \App\Service\System\ElasticService::summary($conf);
    }
}
