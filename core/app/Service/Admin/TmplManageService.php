<?php
namespace App\Service\Admin;

class TmplManageService
{
    /**
     * 模板列表页视图数据（后台 tmpl.php?list，GET）。
     *
     * @param int $uid 当前管理员 uid，用于签发 admin_tmpl_post 场景 safe_token
     * @return array {header, tmpllist(TemplateService::templateList 前 500 套), safe_token}
     */
    public static function listPage(int $uid): array
    {
        return array(
            'header' => array(
                'title' => lang('tmpl_page'),
            ),
            'tmpllist' => \App\Service\System\TemplateService::templateList(array(), array(), 1, 500, 2),
            'safe_token' => bull_token_set($uid, 'admin_tmpl_post'),
        );
    }

    /**
     * 云端模板页视图数据（后台 tmpl.php?cloud，GET）。
     * 商店清单经 normalizeCloudTmpl() 归一 pic/downurl 到 cloud_api 基址后再展示。
     *
     * @param int $uid 当前管理员 uid，用于签发 post/down 两个场景的 safe_token
     * @return array {header, arrlist(云端模板行), tmpllist(本地已装模板), safe_token, safe_token_down}
     */
    public static function cloudPage(int $uid): array
    {
        // 修复：商店返回的 code 载荷**不加密**，原先的 core_decrypt 在 store 模式下
        // 恒返空 ⇒ 后台「云端模板」列表永远为空。统一走 cloudPayload()（store 不解密 / legacy 解密）。
        $cloudData = \App\Service\System\CloudOpsService::cloudPayload();

        return array(
            'header' => array(
                'title' => lang('tmpl_page_cloud'),
            ),
            'arrlist' => self::normalizeCloudTmpl($cloudData['tmpl'] ?? array()),
            'tmpllist' => \App\Service\System\TemplateService::templateList(array(), array(), 1, 500, 2),
            'safe_token' => bull_token_set($uid, 'admin_tmpl_post'),
            'safe_token_down' => bull_token_set($uid, 'admin_tmpl_down'),
        );
    }

    /**
     * 云端模板地址归一。
     *
     * 商店用它自己的 `site_url` 拼绝对 URL（`pic` 图标、`downurl` 下载），而该值**可能与 CMS 的
     * `cloud_api` 不同**（本机实况：商店写 `192.0.2.3:2651`＝不存在的设备）。不归一的症状是
     * 「云端模板列表非空但图标全裂图、下载地址不可达」——`TemplateService::download()` 之所以能用，
     * 正是因为它走了 `cloudUrl()/storeUrl()`；本页漏了同一道归一。
     *
     * `storeUrl()` 只在 URL 主机 == 商店自陈 site_url 主机（或 cloud_api 主机）时改写为 cloud_api 基址，
     * 路径/查询原样保留；非商店主机一律原样返回（不越权改写）。
     */
    private static function normalizeCloudTmpl($list): array
    {
        $out = array();
        foreach ((array)$list as $k => $row) {
            if (is_array($row)) {
                foreach (array('pic', 'downurl') as $_f) {
                    if (!empty($row[$_f]) && is_string($row[$_f])) {
                        $row[$_f] = \App\Service\System\CloudOpsService::storeUrl($row[$_f]);
                    }
                }
            }
            $out[$k] = $row;
        }
        return $out;
    }

    /**
     * 安装/卸载本地模板（后台 tmpl.php?post，POST）。
     * 写操作三件套之一：先过 admin_bull_token_guard（场景 admin_tmpl_post，校验不过 message 终止=fail-closed），
     * 取参用 param_word/admin_param_int，成功后写 admin_audit_log。
     *
     * @param int $uid 当前管理员 uid（token 校验主体）
     * @return array {code:0, message:string} 供路由层 message() 透出
     */
    public static function toggleInstall(int $uid): array
    {
        self::verifyToken($uid, 'admin_tmpl_post');
        $dir = param_word('dir');
        $type = admin_param_int('type');
        empty($dir) and message(1, lang('data_malformation'));

        if (1 == $type) {
            \App\Service\System\TemplateService::install($dir);
            admin_audit_log('tmpl_install', array('name' => $dir, 'type' => $type));
            return array('code' => 0, 'message' => lang('install_successfully'));
        }

        \App\Service\System\TemplateService::uninstall($dir);
        admin_audit_log('tmpl_uninstall', array('name' => $dir));
        return array('code' => 0, 'message' => lang('uninstall_successfully'));
    }

    /**
     * 从云端下载并安装模板包（后台 tmpl.php?down，POST）。
     * 场景 token admin_tmpl_down；downurl 经 admin_param_text 限长 1024 取原始值，
     * 交由 TemplateService::download() 完成归一/校验与落盘；成功写 tmpl_down 审计。
     *
     * @param int $uid 当前管理员 uid
     * @return array {code:0, message:string}
     */
    public static function download(int $uid): array
    {
        self::verifyToken($uid, 'admin_tmpl_down');
        $dir = param_word('dir');
        $url = admin_param_text('downurl', '', 1024);
        empty($url) and message(-1, lang('data_is_empty'));
        if (!class_exists('ZipArchive')) {
            return array('code' => -1, 'message' => lang('zip_ext_missing'));
        }

        if (!\App\Service\System\TemplateService::download($url, $dir)) {
            return array('code' => -1, 'message' => lang('down_err'));
        }
        admin_audit_log('tmpl_down', array('name' => $dir));
        return array('code' => 0, 'message' => lang('down_successfully'));
    }

    /**
     * 模板文件管理/编辑页视图数据（后台 tmpl.php?file，GET）。
     * 路径安全链：sanitizePath/sanitizeFilename 白名单字符校验 → realpath 解析 →
     * 统一分隔符后强制前缀必须落在当前主题根内（防 ../ 与符号链接逃逸）→ 编辑模式再验扩展名。
     * 任一校验不过 message(-1) 终止（fail-closed）。
     *
     * @param int    $uid      当前管理员 uid，签发 admin_tmpl_file 场景 safe_token
     * @param string $editor   路由段 param(2)；等于 'editor' 时返回编辑器视图（读文件内容进 s 键）
     * @param string $path     主题内子目录（相对路径，可空）
     * @param string $filename 文件名（编辑模式必填且扩展名须在 allowExt 内）
     * @return array 视图键值（含 view_template 指定渲染 file.html 或 editor.html，由路由层取出后 unset）
     */
    public static function filePage(int $uid, $editor, string $path, string $filename): array
    {
        $base = \App\Service\System\TemplateService::currentThemeBase();
        $allowExt = self::allowExt();
        $safeToken = bull_token_set($uid, 'admin_tmpl_file');
        $path = self::sanitizePath($path);
        $filename = self::sanitizeFilename($filename, FALSE);

        $file = $base . ($path ? $path : '');
        $fileReal = realpath($file);
        // Windows 下 realpath 返回反斜杠、$base 拼接正斜杠，统一分隔符后做前缀检查
        $normFile = str_replace('\\', '/', rtrim($fileReal, '/\\')) . '/';
        $normBase = str_replace('\\', '/', rtrim($base, '/\\')) . '/';
        if (empty($fileReal) || strpos($normFile, $normBase) !== 0) {
            message(-1, lang('data_malformation'));
        }

        $arrs = \App\Service\System\TemplateService::dirFile($fileReal);
        if ($path == '') {
            $arrlist = \App\Service\System\TemplateService::topLevelDirs($arrs);
        } else {
            // 修复：$arrs 已是 <主题>/<path> 目录本身的内容，原先再过 topLevelDirs 会剔除全部文件
            // （只剩子目录），且 isset($arrlist[$path]) 永不命中 ⇒ 进 html 只看到目录看不到文件。
            // 现列出当前目录：子目录（可进入）+ 允许扩展名的文件（可编辑）；编辑器侧栏只列文件。
            $arrlist = self::dirListing($arrs, $allowExt, $editor == 'editor');
        }

        if ($editor == 'editor') {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (empty($ext) || !in_array($ext, $allowExt)) {
                message(-1, lang('data_malformation'));
            }
            $datafile = $base . ($path ? $path . '/' : '') . $filename;
            $datafileReal = realpath($datafile);
            $normData = str_replace('\\', '/', rtrim($datafileReal, '/\\')) . '/';
            if (empty($datafileReal) || strpos($normData, $normBase) !== 0 || !is_file($datafileReal)) {
                message(-1, lang('data_malformation'));
            }

            return array(
                'header' => array(
                    'title' => lang('tmpl_editor'),
                ),
                'safe_token' => $safeToken,
                'path' => $path,
                'filename' => $filename,
                'arrs' => $arrs,
                'arrlist' => isset($arrlist) ? $arrlist : NULL,
                's' => file_get_contents($datafileReal),
                'view_template' => 'editor.html',
            );
        }

        return array(
            'header' => array(
                'title' => lang('tmpl_file'),
            ),
            'safe_token' => $safeToken,
            'path' => $path,
            'filename' => $filename,
            'arrs' => $arrs,
            'arrlist' => isset($arrlist) ? $arrlist : NULL,
            'view_template' => 'file.html',
        );
    }

    /**
     * 保存后台模板编辑内容（后台 tmpl.php?file，POST）。
     * 安全链：场景 token admin_tmpl_file → sanitizePath/sanitizeFilename → 扩展名白名单 →
     * html_entity_decode 还原 param() 的入参转义（编辑器存的是转义后文本，落盘前解一次，
     * 避免双重转义污染模板）→ assertSafeEditableCode 内容闸（含 php 代码即拒绝写入）→
     * realpath 目录前缀必须落在主题根内 → file_put_contents_try 落盘 + template_edit 审计。
     *
     * @param int $uid 当前管理员 uid
     * @return array {code:0, message:'模板修改成功'}
     */
    public static function saveFile(int $uid): array
    {
        self::verifyToken($uid, 'admin_tmpl_file');
        $base = \App\Service\System\TemplateService::currentThemeBase();
        $allowExt = self::allowExt();
        $path = self::sanitizePath((string)param('path'));
        $filename = self::sanitizeFilename((string)param('filename'), TRUE);
        $code = param('code');

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (empty($ext) || !in_array($ext, $allowExt)) {
            message(-1, lang('data_malformation'));
        }

        $codes = html_entity_decode($code);
        $r = \App\Service\System\TemplateService::assertSafeEditableCode($codes);
        if ($r === false) {
            exit(message(0, '模板中包含php代码禁止在后台编辑'));
        }

        $target = $base . ($path ? $path . '/' : '') . $filename;
        $targetDirReal = realpath(dirname($target));
        // Windows 下 realpath 返回反斜杠、$base 拼接正斜杠，统一分隔符后做前缀检查
        $normDir = str_replace('\\', '/', rtrim($targetDirReal, '/\\')) . '/';
        $normBase = str_replace('\\', '/', rtrim($base, '/\\')) . '/';
        if (empty($targetDirReal) || strpos($normDir, $normBase) !== 0) {
            message(-1, lang('data_malformation'));
        }
        file_put_contents_try($target, $codes);
        admin_audit_log('template_edit', array('file' => $filename, 'path' => $path));
        return array('code' => 0, 'message' => '模板修改成功');
    }

    /**
     * 统一 CSRF/会话场景令牌校验入口：取 param('safe_token') 交 admin_bull_token_guard。
     * 校验不过由 guard 内部 message() 终止请求（fail-closed），本函数从不静默放行。
     *
     * @param int    $uid   管理员 uid（令牌绑定主体）
     * @param string $scene 会话场景指纹环键名（如 admin_tmpl_post）
     * @return void
     */
    private static function verifyToken(int $uid, string $scene): void
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, $scene, $safeToken);
    }

    /**
     * 当前目录列表（模板编辑页）：目录名 => 子树（可进入），文件名 => 文件名（可编辑）；
     * 只保留能通过 sanitizePath/sanitizeFilename 与扩展名白名单的条目，目录在前、各自自然排序。
     * $filesOnly=TRUE（编辑器侧栏）时只返回文件。
     */
    private static function dirListing(array $arrs, array $allowExt, bool $filesOnly): array
    {
        $dirs = array();
        $files = array();
        foreach ($arrs as $k => $v) {
            if (is_array($v)) {
                // 目录：名字须能通过 sanitizePath（字符白名单），否则点进去也会被拒
                if (!$filesOnly && is_string($k) && preg_match('#^[a-zA-Z0-9_\\-]+$#', $k)) $dirs[$k] = $v;
                continue;
            }
            $v = (string)$v;
            $ext = strtolower(pathinfo($v, PATHINFO_EXTENSION));
            // 文件：名字须能通过 sanitizeFilename，且扩展名在编辑白名单内
            if ($v === '' || $v[0] === '.' || !preg_match('#^[a-zA-Z0-9_\\-.]+$#', $v) || !in_array($ext, $allowExt, TRUE)) continue;
            $files[$v] = $v;
        }
        ksort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $dirs + $files;
    }

    /**
     * 后台模板编辑器允许的扩展名白名单（不含 php/phtml，配合内容闸挡 RCE 级写入）。
     *
     * @return array<string> 小写扩展名清单
     */
    private static function allowExt(): array
    {
        return array('html', 'htm', 'css', 'js', 'json', 'txt', 'xml', 'map', 'svg');
    }

    /**
     * 主题内子目录路径校验：去首尾斜杠后仅允许 [a-zA-Z0-9_\-/]，拒 .. 与 NUL；
     * 非法即 message(-1) 终止（fail-closed）。
     *
     * @param string $path 相对目录路径，空串代表主题根
     * @return string 规范化后的路径（可空）
     */
    private static function sanitizePath(string $path): string
    {
        $path = trim($path, '/');
        if ($path !== '' && (!preg_match('#^[a-zA-Z0-9_\\-/]+$#', $path) || str_contains($path, '..') || str_contains($path, "\0"))) {
            message(-1, lang('data_malformation'));
        }
        return $path;
    }

    /**
     * 模板文件名校验：仅允许 [a-zA-Z0-9_\-.]，拒路径分隔符/.. /NUL/点开头；
     * 非法即 message(-1) 终止（fail-closed）。
     *
     * @param string $filename 文件名（含扩展名）
     * @param bool   $required 为 TRUE 时空名也拒绝；FALSE 时空名合法（浏览目录页可不带文件名）
     * @return string 合法文件名或空串
     */
    private static function sanitizeFilename(string $filename, bool $required): string
    {
        if ($filename === '') {
            $required and message(-1, lang('data_malformation'));
            return '';
        }
        if (!preg_match('#^[a-zA-Z0-9_\\-.]+$#', $filename) || str_contains($filename, '/') || str_contains($filename, '..') || str_contains($filename, "\0") || $filename[0] === '.') {
            message(-1, lang('data_malformation'));
        }
        return $filename;
    }
}
