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
        $rewrite = array('0' => '默认', '1' => '伪静态1', '2' => '伪静态2', '3' => '伪静态3');
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
     * ConfigBootstrapService::saveDomainSite()：域名经 get_domain() 归一 eTLD+1、
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
            'site_rewrite' => admin_param_int('site_rewrite', 0, 0, 3),
            'old_site_domain' => admin_param_text('old_site_domain', '', 255),
        );
        $result = ConfigBootstrapService::saveDomainSite(self::domainConfig(), $payload);
        $result['success'] || message(1, $result['msg']);
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
        $result = SystemMaintenanceService::batchAddDomainSites(admin_param_text('batch_sites', '', 100000));
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
        return array('code' => 0, 'message' => jump($result['msg'], url('set-domain', array(), TRUE), 3));
    }
}
