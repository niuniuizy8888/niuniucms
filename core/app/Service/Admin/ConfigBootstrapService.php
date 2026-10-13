<?php
namespace App\Service\Admin;

class ConfigBootstrapService
{
    /**
     * 保存伪静态档位——**启动期配置例外**：请求入口在 KV 注入前就依赖 url_rewrite_on/path/cookie_path，
     * 故本方法物理改写 core/config/config.php（file_replace_var），不走 config_runtime_* 的 KV 运行时层（CLAUDE.md「当前架构·配置分层」）。
     * 档位同时决定站点 path 与 cookie 域：0/1 ⇒ './' + cookie_path 置空；2/3 ⇒ '/' + cookie_path '/'。
     * 改档后旧 URL 形态立即失效（前台链接全部按新档位重建），需 nginx 伪静态同步跟上（见 .skills/deploy）。
     * @param int $rewrite 档位 0-3（调用方 SetManageService::saveRewrite 已用 admin_param_int 钳在 0..3）
     * @return array {success:true, msg:string}（恒成功——file_replace_var 的写失败只回布尔、此处未判）
     */
    public static function saveRewrite(int $rewrite): array
    {
        $replace = array();
        $replace['url_rewrite_on'] = $rewrite;
        if ($rewrite == 0 || $rewrite == 1) {
            $replace['path'] = './';
            $replace['cookie_path'] = '';
        }
        if ($rewrite == 2 || $rewrite == 3) {
            $replace['path'] = '/';
            $replace['cookie_path'] = '/';
        }
        // 启动期配置（伪静态/路径/cookie 域）：请求入口在 KV 注入前即依赖这些值，必须物理写入 config/config.php
        file_replace_var(APP_PATH . 'core/config/config.php', $replace);
        admin_audit_log('rewrite_update', array('rewrite' => $rewrite));
        return array('success' => true, 'msg' => lang('modify_successfully'));
    }

    /**
     * 新增/更新站群站点域名（写 core/config/domain.php 的 site 映射，物理写同 saveRewrite 理由）。
     * 链路：get_domain() 归一（PSL 三源回退、全程不出网；可注册域收敛到 eTLD+1，IP 与单段主机名原样返回）
     * → 空域拒绝 → 内网/保留 IP 判定
     * **默认关闭**（仅 conf['outbound_block_private']=1 时恢复拒绝，用户裁定 LAN 部署用）→
     * 有 old_site_domain 且与归一结果不同则删旧键 → 写 site[域名] 四项。
     * 转义口径：site_name 入库前 htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE)（审计 XSS-03，该值经
     * $conf['sitename'] 原样进前台模板，模板层裸 echo）；path 只允许主题目录名字符集 [A-Za-z0-9_-]。
     * ⚠ 写的是 conf['site_domain'] **归一后**的值：带协议/子域/路径的输入会被折叠成 eTLD+1。
     * @param array $domain  当前 domain.php 全量数组（调用方 SetManageEsTrait::domainConfig() 现读）
     * @param array $payload 表单数据（读 site_domain/site_name/path/site_rewrite/old_site_domain）
     * @return array {success:bool, msg:string} 域名为空或命中内网闸时 success=false
     */
    public static function saveDomainSite(array $domain, array $payload): array
    {
        $site_domain = get_domain(trim($payload['site_domain'] ?? ''));
        if ($site_domain == '') {
            return array('success' => false, 'msg' => '域名不能为空');
        }
        // 用户裁定：内网/保留 IP 限制**默认关闭**（LAN 部署的站点域名本就常是内网 IP，
        // 如测试站 192.0.2.100:8744）；仅当 conf['outbound_block_private']=1 时恢复拒绝。
        if (outbound_block_private() && filter_var($site_domain, FILTER_VALIDATE_IP)) {
            if (!filter_var($site_domain, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return array('success' => false, 'msg' => '禁止使用内网/保留IP地址作为站点域名');
            }
        }

        $site = isset($domain['site']) && is_array($domain['site']) ? $domain['site'] : array();
        $old_domain = trim($payload['old_site_domain'] ?? '');
        if ($old_domain && $old_domain !== $site_domain && isset($site[$old_domain])) {
            unset($site[$old_domain]);
        }
        // 审计修复（XSS-03）：site_name/path 会经 $conf['sitename']/$path_tpl 原样输出到
        // 前台模板，此前未转义——站群管理员可存储 XSS。写侧转义 site_name，path 仅允许主题目录名。
        $site[$site_domain] = array(
            'site_name' => htmlspecialchars((string)($payload['site_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'site_domain' => $site_domain,
            'path' => preg_replace('#[^A-Za-z0-9_-]#', '', (string)($payload['path'] ?? '')),
            'site_rewrite' => intval($payload['site_rewrite'] ?? 0),
        );
        $domain['site'] = $site;
        // 站群域名属启动期配置（index.php 入口在 KV 注入前读取 domain.php 覆盖 $conf），保留物理写
        file_replace_var(APP_PATH . 'core/config/domain.php', $domain);
        admin_audit_log('domain_update', array('site_domain' => $site_domain));
        return array('success' => true, 'msg' => '更新/添加成功');
    }

    /**
     * 删除站群站点域名（unset site[归一域名] 后整表回写 core/config/domain.php 的 site 键）。
     * 口径提示：域名同样先过 get_domain() 归一（与写入侧一致，否则带子域/协议的入参删不掉）；
     * 键不存在时也照样回写（无「未找到」提示，恒返回删除成功）。
     * @param array  $domain      当前 domain.php 全量数组
     * @param string $site_domain 待删域名（签名已声明 string）
     * @return array {success:true, msg:string} 恒成功
     */
    public static function deleteDomainSite(array $domain, string $site_domain): array
    {
        $site_domain = get_domain($site_domain);
        $site = isset($domain['site']) && is_array($domain['site']) ? $domain['site'] : array();
        $replace = array();
        if (isset($site[$site_domain])) {
            unset($site[$site_domain]);
        }
        $replace['site'] = $site;
        // 启动期配置，同上：保留物理写
        file_replace_var(APP_PATH . 'core/config/domain.php', $replace);
        admin_audit_log('domain_delete', array('domain' => $site_domain));
        return array('success' => true, 'msg' => '删除成功');
    }
}
