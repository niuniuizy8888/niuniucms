<?php
/**
 * NiuNiuCMS 插件脚手架（CLI 工具）
 *
 * 一句话创建新插件骨架（含 conf.json / install.php / uninstall.php / hook 注入 / 后台设置页）：
 *
 *   php plugin.php new <目录名> [显示名]
 *   php plugin.php list        # 查看现有插件
 *   php plugin.php help
 *
 * 生成后到 后台 → 插件管理：安装 → 启用 → 设置，即可看到效果；再按需修改生成的文件。
 * 完整开发手册与契约权威：.skills/插件开发.md（发布前检查修复 E-5：原指向已不存在的 .agent/）。
 *
 * 安全：仅限 CLI 执行（Web 访问直接退出）；目录名白名单 [A-Za-z0-9_-]；已存在拒绝覆盖。
 */

// ---- 仅 CLI ----
// 发布前检查修复（D-Info2）：web 直访改 404（原 200+提示文本，消除扫描噪音）
if ('cli' !== PHP_SAPI) {
    http_response_code(404);
    exit("plugin.php 仅支持 CLI 执行（php plugin.php help）\n");
}

$ROOT = __DIR__ . '/';
$cmd = isset($argv[1]) ? strtolower(trim($argv[1])) : 'help';
$arg1 = isset($argv[2]) ? trim($argv[2]) : '';
$arg2 = isset($argv[3]) ? trim($argv[3]) : '';

if ('new' === $cmd) {
    plugin_scaffold_new($ROOT, $arg1, $arg2);
    exit(0);
}
if ('list' === $cmd) {
    plugin_scaffold_list($ROOT);
    exit(0);
}
if (in_array($cmd, array('install', 'enable', 'disable', 'uninstall'), TRUE)) {
    plugin_cli_lifecycle($ROOT, $cmd, $arg1);
    exit(0);
}
if ('thumbs' === $cmd) {
    plugin_cli_thumbs($ROOT, $arg1);
    exit(0);
}
echo plugin_scaffold_help();
exit(0);

/* ---------- 生命周期命令（install/enable/disable/uninstall） ---------- */

/**
 * 生命周期引导（最小装配）：conf + 容器 + 函数层 + 自动加载 + kv/runtime 模型层。
 * install/uninstall 脚本里的 kv_set/kv_delete 经 PSR-4 自动加载 RepositoryFactory → PdoDriver 落库。
 * 属主保护：root 运行时写出的 runtime/conf 文件会让 fpm(www) 无法覆写 → 站点空白；
 * 动作完成后把插件目录（含 conf.json）与 runtime/cache 编译产物属主回写为站点属主（取 plugins/ 目录属主）。
 */
function plugin_cli_boot($ROOT)
{
    define('DEBUG', 0);
    define('IN_CMD', 1);
    define('APP_PATH', $ROOT);
    define('CORE_PATH', $ROOT . 'core/');
    require_once $ROOT . 'core/paths.php'; // U-2
    $conf = include nncms_runtime_path('config.php');
    if (!is_array($conf)) exit("config 读取失败\n");
    foreach (array('log_path', 'cache_path', 'upload_path') as $p) {
        if (substr($conf[$p] ?? '', 0, 2) == './') $conf[$p] = APP_PATH . $conf[$p];
    }
    $_SERVER['conf'] = $conf;
    $GLOBALS['conf'] = $conf;
    require $ROOT . 'core/App.php';
    App::set('conf', $conf);
    include $ROOT . 'core/core.php'; // func 全模块 + db 层（PdoDriver/QueryBuilder）一站式装配
    spl_autoload_register(function (string $class): void {
        // 第 2 轮 U-6：与 core/core.php 同口径的类名严格校验（段式标识符，拒绝 ../NUL 等）
        $class = ltrim($class, '\\');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $class)) return;
        $prefixes = array('App\\' => APP_PATH . 'core/app/', 'Psr\\' => APP_PATH . 'core/psr/');
        foreach ($prefixes as $prefix => $baseDir) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) continue;
            $rel = substr($class, $len);
            if ($rel === '') return;
            $file = $baseDir . str_replace('\\', '/', $rel) . '.php';
            if (file_exists($file)) { include $file; return; }
            return;
        }
        if (str_contains($class, '\\')) return; // 下面的 model/lib 兜底只接受无命名空间的裸类名
        foreach (array(APP_PATH . 'core/model/', APP_PATH . 'core/lib/') as $dir) {
            $f = $dir . $class . '.php';
            if (file_exists($f)) { include $f; return; }
        }
    });
    include APP_PATH . 'core/model/runtime.php';
    include APP_PATH . 'core/model/kv.php';
    require $ROOT . 'core/app/Service/System/AddonService.php';
    require $ROOT . 'core/model/addons.php';
}

/**
 * 属主回写（CLI 生命周期收尾防护）：仅 root 运行时执行（posix 缺失或非 root 直接返回）。
 * 把 plugins/<dir>、runtime/cache、runtime/log 递归 chown/chgrp 回站点属主（取 index.php 的属主/组），
 * 防 root 写出的文件让 fpm(www) 无法覆写导致白屏 / 日志轮转静默失败（E-5 修复注释在案）。
 * chown/chgrp 全部 @ 抑制失败——尽力而为，不回传错误。
 * @param string $ROOT 站点根（带尾斜杠）
 * @param string $dir 插件目录名（已过白名单校验的调用方传入）
 */
function plugin_cli_fix_owner($ROOT, $dir)
{
    if (!function_exists('posix_getuid') || 0 !== posix_getuid()) return;
    $siteOwner = fileowner($ROOT . 'index.php');
    $siteGroup = filegroup($ROOT . 'index.php');
    // 发布前检查修复（E-5）：补 runtime/log——root 跑 lifecycle 触发 xn_log（如构建失败）会留
    // root 属主日志文件，www 后续追加/轮转静默失败
    $paths = array($ROOT . 'plugins/' . $dir, $ROOT . 'runtime/cache', $ROOT . 'runtime/log');
    foreach ($paths as $p) {
        if (!file_exists($p)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if (fileowner($f->getPathname()) !== $siteOwner) @chown($f->getPathname(), $siteOwner);
            if (filegroup($f->getPathname()) !== $siteGroup) @chgrp($f->getPathname(), $siteGroup);
        }
        if (fileowner($p) !== $siteOwner) @chown($p, $siteOwner);
    }
}

/**
 * install/enable/disable/uninstall 四条生命周期命令的统一执行器：
 * 目录名白名单 [A-Za-z0-9_-]{1,64} + 目录存在校验 → plugin_cli_boot 最小装配 →
 * 转调 AddonService::install/enable/disable/uninstall（uninstall 特判）→ 属主回写 →
 * 回读 conf.json 打印真实 installed/enable 标志（展示层键即真实键，与后台列表口径一致）。
 * 成功/失败以 exit 0/1 表达（STDERR 输出 AddonService::$lastError），无返回值。
 * @param string $ROOT 站点根（带尾斜杠）
 * @param string $cmd 命令名（主脚本已用 in_array 白名单限定四值之一）
 * @param string $dir 插件目录名
 */
function plugin_cli_lifecycle($ROOT, $cmd, $dir)
{
    if ('' === $dir || !preg_match('#^[A-Za-z0-9_-]{1,64}$#', $dir)) {
        fwrite(STDERR, "用法：php plugin.php {$cmd} <目录名>（目录名仅 [A-Za-z0-9_-]）\n");
        exit(1);
    }
    if (!is_dir($ROOT . 'plugins/' . $dir)) {
        fwrite(STDERR, "plugins/{$dir}/ 不存在\n");
        exit(1);
    }
    plugin_cli_boot($ROOT);
    $map = array(
        'install' => array('m' => 'install', 'ok' => '已安装并启用（installed=1 enable=1，install.php 已执行）'),
        'enable' => array('m' => 'enable', 'ok' => '已启用（hook 即时生效，无需清缓存）'),
        'disable' => array('m' => 'disable', 'ok' => '已停用'),
        'uninstall' => array('m' => 'uninstall', 'ok' => '已卸载（uninstall.php 已执行，kv 数据已清理）'),
    );
    $m = $map[$cmd]['m'];
    $result = $cmd === 'uninstall'
        ? \App\Service\System\AddonService::uninstall($dir)
        : \App\Service\System\AddonService::$m($dir);
    plugin_cli_fix_owner($ROOT, $dir);
    $err = (string)\App\Service\System\AddonService::$lastError;
    if (TRUE !== $result) {
        fwrite(STDERR, "失败" . ('' !== $err ? "：{$err}" : '') . "\n");
        exit(1);
    }
    echo "plugins/{$dir} {$map[$cmd]['ok']}\n";
    $conf = json_decode((string)file_get_contents($ROOT . "plugins/{$dir}/conf.json"), TRUE);
    echo "installed=" . (int)($conf['installed'] ?? 0) . " enable=" . (int)($conf['enable'] ?? 0) . "\n";
}

/* ---------- 命令实现 ---------- */

/**
 * 远程海报缩略图批量生成（R7-P09）。
 * 用法：php plugin.php thumbs [数量上限，默认 500]
 * 说明：仅处理 pic 为 http(s) 的 vod 行；已生成则跳过（幂等）；单张失败只计数不中断；
 *       出站经 outbound_url_safe + finfo MIME + 体积上限 + GD 缩放，失败原样回退远程图。
 * 属主：请以站点属主运行（su www -s /bin/bash -c '...'），避免 root 属主文件。
 */
function plugin_cli_thumbs($ROOT, $arg1)
{
    plugin_cli_boot($ROOT);
    $limit = ctype_digit((string)$arg1) ? max(1, intval($arg1)) : 500;
    $repo = \App\Repository\RepositoryFactory::vod();
    $page = 1; $processed = 0; $ok = 0; $fail = 0; $skip = 0; $seen = array(); $hostFails = array(); $skipHosts = array();
    echo "thumbs: limit={$limit}\n";
    while ($processed < $limit) {
        $rows = $repo->find(array('pic' => array('LIKE' => 'http')), array('vid' => 1), $page, 100, 'vid', array('vid', 'pic'));
        if (empty($rows)) break;
        foreach ($rows as $row) {
            $vid = intval($row['vid']);
            if (isset($seen[$vid])) continue;
            $seen[$vid] = 1;
            if ($processed >= $limit) break 2;
            $processed++;
            $pic = (string)($row['pic'] ?? '');
            $host = strtolower((string)parse_url($pic, PHP_URL_HOST));
            // 熔断：同一图床连续失败达阈值即跳过其剩余条目，避免被不可达图床拖垮整批
            if ($host !== '' && isset($skipHosts[$host])) { $skip++; continue; }
            $rel = \App\Service\System\ThumbnailService::generate($pic);
            if ($rel) {
                $ok++; echo '.'; content_cache_delete('vod_', $vid);
                if ($host !== '') $hostFails[$host] = 0;
            } else {
                $fail++; echo 'x';
                if ($host !== '') {
                    $hostFails[$host] = (int)($hostFails[$host] ?? 0) + 1;
                    if ($hostFails[$host] >= 5) { $skipHosts[$host] = 1; echo "  [skip host {$host} after 5 fails]\n"; }
                }
            }
            if (0 === $processed % 50) echo "  {$processed} (ok={$ok} fail={$fail} skip={$skip})\n";
            usleep(80000); // 温和节流，避免对第三方图床突发压测
        }
        $page++;
    }
    echo "\nthumbs done: processed={$processed} ok={$ok} fail={$fail} skip={$skip}\n";
}

/**
 * 帮助文本（纯字符串工厂，由顶层 echo 输出；无副作用）。
 * @return string 用法清单文本
 */
function plugin_scaffold_help()
{
    return <<<TXT
NiuNiuCMS 插件脚手架
  php plugin.php new <目录名> [显示名]   一句话创建插件骨架（conf.json/install/uninstall/hook/设置页）
  php plugin.php list                    列出现有插件（目录/名称/状态）
  php plugin.php install <目录名>        安装并启用（执行 install.php，写 kv 默认配置）
  php plugin.php enable <目录名>         启用（hook 即时生效）
  php plugin.php disable <目录名>        停用
  php plugin.php uninstall <目录名>      卸载（执行 uninstall.php，清理 kv 数据）
  php plugin.php help                    本帮助
生成后：后台 → 插件管理 → 安装 → 启用 → 设置（或直接用上面的 install 命令）。开发手册见 .skills/插件开发.md

TXT;
}

/**
 * 列出 plugins/ 下各插件的 目录/名称/installed/enable 四列状态（直接打印到 stdout，不 boot 框架）。
 * conf.json 不可解析的目录单行占位报错，不中断遍历。展示口径即 conf.json 真实键（后台列表的
 * enabled 映射不在此处）。
 * @param string $ROOT 站点根（带尾斜杠）
 */
function plugin_scaffold_list($ROOT)
{
    $dirs = glob($ROOT . 'plugins/*', GLOB_ONLYDIR);
    if (!is_array($dirs) || !count($dirs)) {
        echo "（plugins/ 下暂无插件）\n";
        return;
    }
    printf("%-22s %-16s %-10s %-8s\n", '目录', '名称', 'installed', 'enable');
    foreach ($dirs as $d) {
        $conf = json_decode((string)file_get_contents($d . '/conf.json'), TRUE);
        if (!is_array($conf)) {
            printf("%-22s %-16s %-10s %-8s\n", basename($d), '(conf.json 不可解析)', '-', '-');
            continue;
        }
        printf("%-22s %-16s %-10s %-8s\n", basename($d), (string)$conf['name'], (string)$conf['installed'], (string)$conf['enable']);
    }
}

/**
 * 新插件骨架生成器：目录名白名单校验（与 AddonService 路径穿越防护同口径）+ 拒绝覆盖已存在目录，
 * 落盘 conf.json / install.php / uninstall.php / hook/foot_end.php / setting.php 五件套
 * （示例形态=kv 配置 + 页脚注入 + 后台设置页，与 .skills/插件开发.md 手册示例同构）。
 * 生成后立即自检：conf.json 可解析、hook 文件带 exit 头、三个 PHP 脚本 token_get_all(TOKEN_PARSE) 语法检查——
 * 任一项告警则 exit 1 提示修复。骨架内含的注入位置/双写口径等约定见各 heredoc 内注释。
 * @param string $ROOT 站点根（带尾斜杠）
 * @param string $dir 插件目录名（[A-Za-z0-9_-]{1,64}，非法即 exit 1）
 * @param string $name 显示名（空则回落目录名）
 */
function plugin_scaffold_new($ROOT, $dir, $name)
{
    // 目录名白名单（与 AddonService 路径穿越防护同口径）
    if ('' === $dir || !preg_match('#^[A-Za-z0-9_-]{1,64}$#', $dir)) {
        fwrite(STDERR, "目录名仅允许 [A-Za-z0-9_-]（1-64 位），如：php plugin.php new myplugin 我的插件\n");
        exit(1);
    }
    $target = $ROOT . 'plugins/' . $dir . '/';
    if (is_dir($target)) {
        fwrite(STDERR, "已存在 plugins/{$dir}/——拒绝覆盖。如需重建请先备份并删除该目录。\n");
        exit(1);
    }
    $name = '' !== $name ? $name : $dir;
    $kvKey = 'plugin_' . $dir;          // 插件 kv 键统一加目录前缀防冲突

    // ---- 骨架模板（与 .skills/插件开发.md 手册示例同构：kv 配置 + 页脚钩子 + 设置页） ----
    $conf = <<<JSON
{
    "name": "{$name}",
    "version": "0.1.0",
    "software_version": "",
    "brief": "{$name}（脚手架生成：页脚信息条示例，后台可配置开关与文案）",
    "author": "",
    "installed": 0,
    "enable": 0,
    "hooks_rank": {"foot_end.php": 0}
}
JSON;

    $install = <<<PHP
<?php
// {$name} · 安装脚本（install 标志写入后执行一次；异常自动回滚安装标志）
// install.php 是直接执行脚本：不带 hook 的 `<?php exit;` 头（那是 hook/*.php 专属约定）。

// 默认配置写入 kv（键名统一加插件前缀防冲突；kv_set 自带缓存失效）
if (NULL === kv_get('{$kvKey}')) {
    kv_set('{$kvKey}', array(
        'on'    => 1,
        'text'  => '{$name} 已启用——后台插件列表点「设置」修改本条文案。',
        'bg'    => '#1d4ed8',
        'color' => '#ffffff',
    ));
}
PHP;

    $uninstall = <<<PHP
<?php
// {$name} · 卸载脚本（uninstall 标志写入后执行一次；清理插件写入的 kv 数据）
kv_delete('{$kvKey}');
PHP;

    $hook = <<<PHP
<?php exit; ?>
<?php
/* {$name} · 页脚注入（foot_end.php）
 * HTML 上下文 hook 约定：首行 = exit 头带闭合标签（编译期剥头连闭合一并剥掉），
 * body 从 php 开标签重新进入代码态；裸语句格式（exit 头不带闭合）仅限 PHP 上下文 hook（core/model、route）。
 * 铁律：本注释与字符串内严禁出现 php 开/闭标签字面序列——编译期 balancePhpMode 按字面计数配平模式态，
 * 计数失衡会在产物尾部补游离闭合标签、以纯文本输出到页尾（sitenotice 实测教训）。
 * 改本文件无需任何操作（.hooksig 签名自动重建编译产物）。 */
if (function_exists('kv_get')) {
    \$cfg = kv_get('{$kvKey}');
    if (!empty(\$cfg['on']) && '' !== trim((string)\$cfg['text'])) {
        \$text = htmlspecialchars((string)\$cfg['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // 色值渲染端白名单兜底（存量脏值不得以未加引号形态直插 style 注入 CSS 声明）
        \$bg = (string)(\$cfg['bg'] ?? '');
        if (!preg_match('~^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?\$~', \$bg)) \$bg = '#1d4ed8';
        \$color = (string)(\$cfg['color'] ?? '');
        if (!preg_match('~^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?\$~', \$color)) \$color = '#ffffff';
?>
<div style="position:fixed;left:0;right:0;bottom:0;z-index:999;background:<?php echo \$bg; ?>;color:<?php echo \$color; ?>;text-align:center;padding:8px 14px;font-size:13px;line-height:1.6">
    <?php echo \$text; ?>
</div>
<?php } } ?>
PHP;

    $setting = <<<PHP
<?php
// {$name} · 后台设置页（addon-setting&dir={$dir}，渲染于后台 head/foot 包裹内）
// 数据存 kv；写操作走 bull token（admin_require_bull_token 校验 safe_token）。

\$cfg = kv_get('{$kvKey}');
if (!is_array(\$cfg)) {
    \$cfg = array('on' => 1, 'text' => '', 'bg' => '#1d4ed8', 'color' => '#ffffff');
}

if ('POST' == \$method) {
    admin_require_bull_token(\$uid, 'plugin_{$dir}_set');
    // 色值白名单（type=color 本意 #rgb/#rrggbb）：任意串会以未加引号形态直插 style 注入 CSS；文案按字符截断防多字节切碎
    \$bg = trim((string)admin_param_text('bg', '#1d4ed8', 9));
    if (!preg_match('~^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\$~', \$bg)) \$bg = '#1d4ed8';
    \$color = trim((string)admin_param_text('color', '#ffffff', 9));
    if (!preg_match('~^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\$~', \$color)) \$color = '#ffffff';
    \$cfg = array(
        'on'    => admin_param_int('on', 0, 0, 1),
        'text'  => mb_substr(trim((string)admin_param_text('text', '', 255)), 0, 200),
        'bg'    => \$bg,
        'color' => \$color,
    );
    kv_set('{$kvKey}', \$cfg);
    admin_audit_log('{$dir}_savecfg', array('on' => \$cfg['on'], 'text_len' => strlen((string)\$cfg['text'])));
    message(0, jump('设置已保存', url('addon-setting', array('dir' => '{$dir}'), TRUE), 2));
}
?>
<div class="card" style="max-width:760px">
    <h2 style="margin-top:0">{$name} · 设置</h2>
    <form method="post">
        <input type="hidden" name="safe_token" value="<?= bull_token_get(\$uid, 'plugin_{$dir}_set') ?>">
        <p><label><input type="checkbox" name="on" value="1" <?= !empty(\$cfg['on']) ? 'checked' : '' ?>> 启用</label></p>
        <p>文案：<br><textarea name="text" rows="4" maxlength="200" style="width:100%"><?= xn_h(\$cfg['text']) ?></textarea></p>
        <p>背景色 <input type="color" name="bg" value="<?= xn_h(\$cfg['bg']) ?>">
           文字色 <input type="color" name="color" value="<?= xn_h(\$cfg['color']) ?>"></p>
        <p><button type="submit">保存设置</button></p>
    </form>
    <p class="small" style="color:var(--text-secondary)">注入位置：页脚（hook/foot_end.php → 模板 public/foot.html 末尾钩子点）。</p>
</div>
PHP;

    // ---- 落盘 ----
    @mkdir($target . 'hook', 0755, TRUE);
    file_put_contents($target . 'conf.json', $conf . "\n");
    file_put_contents($target . 'install.php', $install);
    file_put_contents($target . 'uninstall.php', $uninstall);
    file_put_contents($target . 'hook/foot_end.php', $hook);
    file_put_contents($target . 'setting.php', $setting);

    // ---- 生成即自检（conf.json 可解析 / hook 头齐备 / php 语法） ----
    $ok = TRUE;
    if (NULL === json_decode((string)file_get_contents($target . 'conf.json'), TRUE)) {
        fwrite(STDERR, "⚠ conf.json 不可解析\n");
        $ok = FALSE;
    }
    $hookSrc = (string)file_get_contents($target . 'hook/foot_end.php');
    if (!preg_match('#^\s*<\?php\s*exit;#is', $hookSrc)) {
        fwrite(STDERR, "⚠ hook/foot_end.php 缺少 <?php exit; 头\n");
        $ok = FALSE;
    }
    foreach (array('install.php', 'uninstall.php', 'setting.php') as $pf) {
        // 进程内语法自检（token_get_all + TOKEN_PARSE 遇语法错误抛 ParseError），
        // 不再 shell 出去跑 `php -l`：免依赖 PATH 中的 php 版本，也让发行包里不含进程执行调用
        try {
            token_get_all((string)file_get_contents($target . $pf), TOKEN_PARSE);
        } catch (\ParseError $e) {
            fwrite(STDERR, "⚠ {$pf} 语法错误：" . $e->getMessage() . '（第 ' . $e->getLine() . " 行）\n");
            $ok = FALSE;
        }
    }
    echo "✅ 插件骨架已生成：plugins/{$dir}/（conf.json · install.php · uninstall.php · hook/foot_end.php · setting.php）\n";
    echo $ok ? "下一步：后台 → 插件管理 → 安装 → 启用 → 设置；开发手册 .skills/插件开发.md\n"
             : "生成内容有告警，请按上方提示修复后再安装。\n";
    $ok or exit(1);
}
