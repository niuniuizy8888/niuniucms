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
        $raw_domain = trim((string)($payload['site_domain'] ?? ''));
        if ($raw_domain === '') {
            return array('success' => false, 'msg' => '域名不能为空');
        }
        $site_domain = self::normalizeSiteDomain($raw_domain);
        if ($site_domain === '') {
            return array('success' => false, 'msg' => '域名格式不正确：只能填写域名或 IP（如 a.com、www.a.com），不要带引号、空格等字符');
        }
        // 用户裁定：内网/保留 IP 限制**默认关闭**（LAN 部署的站点域名本就常是内网 IP，
        // 如测试站 192.0.2.100:8744）；仅当 conf['outbound_block_private']=1 时恢复拒绝。
        if (outbound_block_private() && filter_var($site_domain, FILTER_VALIDATE_IP)) {
            if (!filter_var($site_domain, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return array('success' => false, 'msg' => '禁止使用内网/保留IP地址作为站点域名');
            }
        }

        $site = isset($domain['site']) && is_array($domain['site']) ? $domain['site'] : array();
        $old_raw = trim((string)($payload['old_site_domain'] ?? ''));
        $old_domain = $old_raw === '' ? '' : get_domain($old_raw);
        // 修复：单个添加（无 old_site_domain）遇到已存在的域名时此前直接覆盖原站点配置；
        // 改名撞到另一个已存在站点同理。站群按主域名（eTLD+1）匹配，www.a.com 与 a.com 是同一个键。
        if (isset($site[$site_domain]) && ($old_domain === '' || $old_domain !== $site_domain)) {
            return array('success' => false, 'msg' => '站点 ' . $site_domain . ' 已存在（站群按主域名匹配，www.a.com、m.a.com 与 a.com 视为同一站点），请在列表中直接修改');
        }
        $path = (string)($payload['path'] ?? '');
        $clean_path = self::sanitizeSitePath($path);
        if (trim($path) !== '' && $clean_path === '') {
            return array('success' => false, 'msg' => '模板目录不存在：' . preg_replace('#[^A-Za-z0-9_.-]#', '', $path));
        }
        if ($old_domain !== '' && $old_domain !== $site_domain && isset($site[$old_domain])) {
            unset($site[$old_domain]);
        }
        // 审计修复（XSS-03）：site_name/path 会经 $conf['sitename']/$path_tpl 原样输出到
        // 前台模板，此前未转义——站群管理员可存储 XSS。写侧转义 site_name，path 仅允许已安装主题目录名。
        $site[$site_domain] = array(
            'site_name' => self::escapeSiteName((string)($payload['site_name'] ?? '')),
            'site_domain' => $site_domain,
            'path' => $clean_path,
            'site_rewrite' => max(0, min(3, intval($payload['site_rewrite'] ?? 0))),
        );
        $domain['site'] = $site;
        // 站群域名属启动期配置（index.php 入口在 KV 注入前读取 domain.php 覆盖 $conf），保留物理写
        if (!self::writeDomainConfig($domain)) {
            return array('success' => false, 'msg' => '保存失败：core/config/domain.php 无法写入，请检查文件权限（需运行 PHP 的用户可写）');
        }
        admin_audit_log('domain_update', array('site_domain' => $site_domain));
        $msg = $old_domain === '' ? '添加成功' : '更新成功';
        if (strtolower($raw_domain) !== $site_domain && get_domain($raw_domain) === $site_domain && strpos($raw_domain, '/') === false) {
            $msg .= '（站群按主域名匹配，已保存为 ' . $site_domain . '）';
        }
        return array('success' => true, 'msg' => $msg);
    }

    /**
     * 批量添加站群站点（每行 site_domain|site_name|path|site_rewrite，后三项可省略）。
     * 与 saveDomainSite 同一套校验与转义（此前批量通道 site_name 未转义 = 存储型 XSS、path 只挡 ../、
     * 域名无格式校验、少于 4 段整行静默丢弃、site_rewrite 填 0 也被当「未填」随机化）。
     * path 为空/不存在 ⇒ 从已安装主题随机挑一个（原行为）；site_rewrite 为空/非 0-3 ⇒ 随机 0-3（原行为）。
     * @param array  $domain     当前 domain.php 全量数组
     * @param string $input_text 多行文本
     * @return array {success:bool, msg:string, added:int}
     */
    public static function batchAddDomainSites(array $domain, string $input_text): array
    {
        $site = isset($domain['site']) && is_array($domain['site']) ? $domain['site'] : array();
        $dirs = \App\Service\System\TemplateService::availableDirs();
        $added = 0;
        $dup = array();
        $bad = array();
        foreach (preg_split('/\r\n|\r|\n/', trim($input_text)) as $no => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $parts = array_map('trim', array_pad(explode('|', $line, 4), 4, ''));
            list($raw_domain, $site_name, $path, $site_rewrite) = $parts;
            $site_domain = self::normalizeSiteDomain($raw_domain);
            if ($site_domain === '' || (outbound_block_private() && filter_var($site_domain, FILTER_VALIDATE_IP)
                && !filter_var($site_domain, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
                $bad[] = $no + 1;
                continue;
            }
            if (isset($site[$site_domain])) {
                $dup[] = $site_domain;
                continue;
            }
            $path = self::sanitizeSitePath($path);
            if ($path === '') {
                $path = !empty($dirs) ? $dirs[array_rand($dirs)] : 'default';
            }
            $site_rewrite = ($site_rewrite !== '' && ctype_digit($site_rewrite) && intval($site_rewrite) <= 3) ? intval($site_rewrite) : rand(0, 3);
            $site[$site_domain] = array(
                'site_name' => self::escapeSiteName($site_name),
                'site_domain' => $site_domain,
                'path' => $path,
                'site_rewrite' => $site_rewrite,
            );
            $added++;
        }
        if ($added > 0) {
            $domain['site'] = $site;
            if (!self::writeDomainConfig($domain)) {
                return array('success' => false, 'added' => 0, 'msg' => '保存失败：core/config/domain.php 无法写入，请检查文件权限');
            }
        }
        $msg = '批量添加完成，共添加 ' . $added . ' 条记录';
        $dup and $msg .= '；已存在跳过 ' . count($dup) . ' 条（' . implode('、', array_slice(array_unique($dup), 0, 10)) . '）';
        $bad and $msg .= '；域名格式错误跳过 ' . count($bad) . ' 行（第 ' . implode('、', array_slice($bad, 0, 10)) . ' 行）';
        return array('success' => $added > 0 || (empty($dup) && empty($bad)), 'added' => $added, 'msg' => $msg);
    }

    /**
     * 站点域名归一 + 格式校验：get_domain() 折叠到主域名（eTLD+1，与 index.php 的匹配口径一致），
     * 再只放行 IP 或合法主机名（字母/数字/连字符分段，IDN 未转 punycode 时允许 Unicode 字母）。
     * 修复：此前不校验，`a"><x` 这类值会写进 domain.php 并作为表单 id 渲染到后台页面。
     * @return string 归一后的域名；不合法返回 ''
     */
    public static function normalizeSiteDomain(string $input): string
    {
        $d = get_domain(trim($input));
        if ($d === '') return '';
        if (filter_var($d, FILTER_VALIDATE_IP)) return $d;
        if (strlen($d) > 253) return '';
        $label = '[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?';
        return preg_match('/^' . $label . '(?:\.' . $label . ')*$/u', $d) ? $d : '';
    }

    /**
     * 站点模板目录：只保留目录名字符，并要求是本地已存在的主题（availableDirs 为空时只做字符清洗）。
     * @return string 合法目录名；空或不存在返回 ''
     */
    public static function sanitizeSitePath(string $path): string
    {
        $path = preg_replace('#[^A-Za-z0-9_-]#', '', trim($path));
        if ($path === '') return '';
        $dirs = \App\Service\System\TemplateService::availableDirs();
        return (empty($dirs) || in_array($path, $dirs, TRUE)) ? $path : '';
    }

    /**
     * 站点名写侧转义（XSS-03 口径）。先还原一次实体再转义：后台编辑框回显的是解码后的原文，
     * 防止「保存一次多一层 &amp;」的逐次叠加。
     */
    private static function escapeSiteName(string $name): string
    {
        $name = str_replace(array("\0", "\r", "\n", "\t"), '', trim($name));
        $name = htmlspecialchars_decode($name, ENT_QUOTES);
        return htmlspecialchars(mb_substr($name, 0, 100, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 写 core/config/domain.php（file_replace_var 返回写入字节数，失败为 FALSE/0）。 */
    private static function writeDomainConfig(array $domain): bool
    {
        $r = file_replace_var(APP_PATH . 'core/config/domain.php', $domain);
        return $r !== FALSE && intval($r) > 0;
    }

    /**
     * 删除站群站点域名（unset site[归一域名] 后整表回写 core/config/domain.php 的 site 键）。
     * 域名同样先过 get_domain() 归一（与写入侧一致，否则带子域/协议的入参删不掉）。
     * @param array  $domain      当前 domain.php 全量数组
     * @param string $site_domain 待删域名
     * @return array {success:bool, msg:string}
     */
    public static function deleteDomainSite(array $domain, string $site_domain): array
    {
        $site_domain = get_domain($site_domain);
        $site = isset($domain['site']) && is_array($domain['site']) ? $domain['site'] : array();
        if ($site_domain === '' || !isset($site[$site_domain])) {
            return array('success' => false, 'msg' => '站点不存在或已删除');
        }
        unset($site[$site_domain]);
        $domain['site'] = $site;
        // 启动期配置，同上：保留物理写
        if (!self::writeDomainConfig($domain)) {
            return array('success' => false, 'msg' => '删除失败：core/config/domain.php 无法写入，请检查文件权限');
        }
        admin_audit_log('domain_delete', array('domain' => $site_domain));
        return array('success' => true, 'msg' => '删除成功');
    }
}
