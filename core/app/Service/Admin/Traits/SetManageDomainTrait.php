<?php
namespace App\Service\Admin\Traits;

use App\Service\Admin\ConfigBootstrapService;
use App\Service\Admin\SystemMaintenanceService;

/**
 * 域名管理（domainPage/saveDomain/addDomain/deleteDomain）（从 SetManageService 拆分，Trait 组合）
 */
trait SetManageDomainTrait
{
    /**
     * 站群域名管理页视图数据（后台 set.php?domain，GET）。
     * 预签发 admin_domain_add/admin_domain_del 两个场景令牌写入会话指纹环
     * （同页多表单场景，见 bull_token_set 注释），保存场景令牌放 safe_token 键。
     *
     * @param int $uid 当前管理员 uid
     * @return array {header, domain(core/config/domain.php 全量), tmpllist, rewrite(伪静态选项表), site(域名=>站点配置), safe_token}
     */
    public static function domainPage(int $uid): array
    {
        $domain = self::domainConfig();
        $rewrite = array('' => '跟随主站', '0' => '默认', '1' => '伪静态1', '2' => '伪静态2', '3' => '伪静态3');
        bull_token_set($uid, 'admin_domain_add');
        bull_token_set($uid, 'admin_domain_del');

        return array(
            'header' => array(
                'title' => '站群设置',
            ),
            'domain' => $domain,
            'tmpllist' => \App\Service\System\TemplateService::templateList(array(), array(), 1, 500, 2),
            'rewrite' => $rewrite,
            'site' => array_value($domain, 'site', array()),
            'safe_token' => bull_token_set($uid, 'admin_domain'),
        );
    }

    /**
     * 保存/改名主站域名配置（后台 set.php?domain 保存，POST，场景令牌 admin_domain）。
     * 取参全部走 admin_param_text/int（长度与范围白名单）；核心校验与转义在
     * ConfigBootstrapService::saveDomainSite()：域名经 get_host_name() 取完整主机名（精确主机优先、主域名兜底）、
     * site_name 写侧 htmlspecialchars（XSS-03——该值原样进前台模板裸 echo）、path 仅留主题目录名字符。
     * 校验失败经 message() 终止（fail-closed）。
     *
     * @param int $uid 当前管理员 uid
     * @return array {code:0, message}（含 jump() 回域名页脚本；domain.php 属启动期配置，走物理写而非常规 KV 层）
     */
    public static function saveDomain(int $uid): array
    {
        self::verifyToken($uid, 'admin_domain');
        $payload = array(
            'site_name' => admin_param_text('site_name', '', 100),
            'site_domain' => admin_param_text('site_domain', '', 255),
            'path' => admin_param_text('path', '', 255),
            // '' = 跟随主站（第 2 轮 U-8）；其余在 ConfigBootstrapService::normalizeRewrite 钳 0-3
            'site_rewrite' => admin_param_text('site_rewrite', '', 2),
            'old_site_domain' => admin_param_text('old_site_domain', '', 255),
        );
        $result = ConfigBootstrapService::saveDomainSite(self::domainConfig(), $payload);
        $result['success'] || message(1, $result['msg']);
        // WEB-01：保存站群域名后，空 site_host 时自动写入当前 Host（不覆盖已有值）
        self::ensureSiteHostForHostGate();
        return array('code' => 0, 'message' => jump($result['msg'], url('set-domain', array(), TRUE), 3));
    }

    /**
     * 批量追加站群域名（POST，场景令牌 admin_domain_add）。
     * 入参整块交 CloudOpsService::siteBatchAdd() 解析入库（域名格式校验在底层），并记 domain_batch_add 审计。
     * 注：本层不检查 $result['success']——siteBatchAdd 恒返成功汇总串，异常路径靠其自身抛错/逐条校验。
     *
     * @param int $uid 当前管理员 uid
     * @return array {code:0, message}（含 jump() 回域名页脚本，message 为「共添加 N 条」汇总）
     */
    public static function addDomain(int $uid): array
    {
        self::verifyToken($uid, 'admin_domain_add');
        // 修复：原 trim 剥了 \n 以外的控制符却也剥了换行（admin_param_text 会删 \r\n\t），多行文本被拼成一行，
        // 批量添加只认第一条；这里按原值取参后自行限长，逐行校验交 ConfigBootstrapService
        $text = (string)param('batch_sites', '', FALSE);
        $text = substr(str_replace("\0", '', $text), 0, 100000);
        // 留空项默认跟随主站；仅勾选「随机」时才随机（第 2 轮 U-8）
        $opts = array(
            'random_path' => admin_param_int('random_path', 0, 0, 1) === 1,
            'random_rewrite' => admin_param_int('random_rewrite', 0, 0, 1) === 1,
        );
        $result = SystemMaintenanceService::batchAddDomainSites(self::domainConfig(), $text, $opts);
        $result['success'] || message(1, $result['msg']);
        // WEB-01：加站群会写入 domain.php；若未配 site_host，自动写入当前 Host，避免主站被 Host 闸误杀
        if (!empty($result['added'])) {
            self::ensureSiteHostForHostGate();
        }
        return array('code' => 0, 'message' => jump($result['msg'], url('set-domain', array(), TRUE), 3));
    }

    /**
     * 删除站群中一个域名配置（POST，场景令牌 admin_domain_del）。
     * 域名先经 get_domain() 归一再 unset，属启动期配置物理写 domain.php；记 domain_delete 审计。
     *
     * @param int $uid 当前管理员 uid
     * @return array {code:0, message:'删除成功'}（含 jump() 回域名页脚本；删不存在的域名亦静默成功）
     */
    public static function deleteDomain(int $uid): array
    {
        self::verifyToken($uid, 'admin_domain_del');
        $result = ConfigBootstrapService::deleteDomainSite(self::domainConfig(), admin_param_text('site_domain', '', 255));
        $result['success'] || message(1, $result['msg']);
        return array('code' => 0, 'message' => jump($result['msg'], url('set-domain', array(), TRUE), 3));
    }

    /**
     * WEB-01：首次启用站群且主站未填 site_host 时，把当前后台请求 Host 写入 config.php，
     * 使 Host 硬闸与主站+站群白名单一并生效；已有非空 site_host 不覆盖。
     */
    private static function ensureSiteHostForHostGate(): void
    {
        $conf = function_exists('_SERVER') ? _SERVER('conf') : null;
        if (!is_array($conf)) {
            $conf = (isset($GLOBALS['conf']) && is_array($GLOBALS['conf'])) ? $GLOBALS['conf'] : array();
        }
        if (!empty($conf['site_host']) && trim((string)$conf['site_host']) !== '') {
            return;
        }
        $host = '';
        if (function_exists('get_host_name')) {
            $host = (string)get_host_name();
        } else {
            $raw = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
            $host = strtolower(rtrim(preg_replace('/:\d+$/', '', trim($raw)), '.'));
        }
        $host = preg_replace('#[^A-Za-z0-9._-]#', '', (string)$host);
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return;
        }
        if (!function_exists('file_replace_var') || !function_exists('nncms_runtime_path_w')) {
            return;
        }
        file_replace_var(\nncms_runtime_path_w('config.php'), array('site_host' => $host));
        if (function_exists('_SERVER') && is_array(_SERVER('conf'))) {
            $c = _SERVER('conf');
            $c['site_host'] = $host;
            _SERVER('conf', $c);
        }
        if (isset($GLOBALS['conf']) && is_array($GLOBALS['conf'])) {
            $GLOBALS['conf']['site_host'] = $host;
        }
        if (function_exists('admin_audit_log')) {
            admin_audit_log('site_host_auto_set', array('site_host' => $host, 'reason' => 'domain_enable_host_gate'));
        }
    }

}
