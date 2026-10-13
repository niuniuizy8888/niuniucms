<?php
/**
 * NiuNiuCms 统一入口（入口桩；两段式拆分见 CLAUDE.md 安全红线 §二.1）
 * 本文件只做常量/配置/函数层装载，自身不参与插件编译管线（直接执行的文件里写 hook 标记注释是死点，
 * 插件系统改造轮实测定案；埋点契约与可用 hook 名清单见 .skills/插件开发.md）；全部带 index_inc_*
 * 埋点的引导逻辑在 index.inc.php，经 _include() 编译管线加载后埋点才生效。原 index.php +
 * index.inc.php + model.inc.php 三文件合并
 * 在引导主体拆回独立文件后仍保留 model.min.php 合并缓存（在 index.inc.php 内）。
 * 后台入口（admin.php 的改名副本）同样 include 本文件——差异只在它先定义 SKIP_ROUTE/IN_ADMIN/ADMIN_PATH，
 * 使 index.inc.php 跳过前台路由分发（该文件的 `if (!defined('SKIP_ROUTE'))` 分支），
 * 改走 admin_include_route，并追加后台引导段。
 */

// DEBUG 模式:0=线上（默认；页缓存/model.min/任务尾消费/错误抑制全生效）1=调试 2=开发。
// 上线前审核裁定：无 getenv 机制则恒值即生产值——禁止把调试态常量留在入口出厂。
!defined('DEBUG') AND define('DEBUG', 0);
!defined('APP_NAME') AND define('APP_NAME', 'NiuNiuCms');
define('APP_PATH', __DIR__ . '/');
!defined('CORE_PATH') AND define('CORE_PATH', APP_PATH . 'core/');
// 致命错误捕获
register_shutdown_function(function () {
    $e = error_get_last();
    if (empty($e) || empty($e['type'])) return;
    $t = (int)$e['type'];
    if (!in_array($t, array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR), true)) return;
    $now = date('Y-m-d H:i:s');
    $msg = str_replace(["\r","\n","\t"], ' ', (string)($e['message'] ?? ''));
    $uri = str_replace(["\r","\n","\t"], ' ', $_SERVER['REQUEST_URI'] ?? '');
    $logDir = APP_PATH . 'runtime/log/' . date('Ym') . '/';
    is_dir($logDir) or @mkdir($logDir, 0777, true);
    @file_put_contents($logDir . 'php_fatal.php',
        "<?php exit;?>\t$now\t" . ($_SERVER['REMOTE_ADDR'] ?? '') . "\t$uri\t0\t$t\t$msg\t" . ($e['file'] ?? '') . "\t" . ($e['line'] ?? 0) . "\n",
        FILE_APPEND | LOCK_EX);
});

// 加载工具函数（get_domain / serveStaticFile 等）
include CORE_PATH . 'func/static.php';

// 静态文件直接服务
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$reqPath = $reqPath ? rawurldecode($reqPath) : '';
if ($reqPath && strtolower(pathinfo($reqPath, PATHINFO_EXTENSION))) {
    serveStaticFile($reqPath);
}

// 加载配置
$configFile = APP_PATH . 'core/config/config.php';
// 未安装（缺少 core/install.lock 或 core/config/config.php）直接跳转安装向导，不再使用 install_missing.php 引导页
if (!is_file(APP_PATH . 'core/install.lock') || !is_file($configFile)) {
    // 以入口脚本目录构造站内绝对路径，兼容子目录部署与伪静态 URL
    $installUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/install.php';
    http_response_code(302);
    header('Location: ' . $installUrl);
    exit;
}
$conf = include $configFile;
!defined('APP_VERSION') AND define('APP_VERSION', isset($conf['version']) ? (string)$conf['version'] : '1.0.0');
!defined('ADMIN_PATH') AND define('ADMIN_PATH', APP_PATH . 'core/admin/'); // 后台代码已迁移 core/admin/

// 站群域名处理
$domain = (@include APP_PATH . 'core/config/domain.php');
$hosts = function_exists('get_domain') ? get_domain() : '';
if ($hosts && isset($domain['site'][$hosts])) {
    $siteconf = $domain['site'][$hosts];
    if (isset($siteconf['site_rewrite'])) {
        $conf['url_rewrite_on'] = intval($siteconf['site_rewrite']);
        $conf['path'] = ($conf['url_rewrite_on'] >= 2) ? '/' : './';
        $conf['cookie_path'] = ($conf['url_rewrite_on'] >= 2) ? '/' : '';
    }
    if (isset($siteconf['site_name'])) {
        $conf['site_name'] = $conf['sitename'] = $siteconf['site_name'];
    }
}

// 路径修正
foreach (['log_path', 'cache_path', 'upload_path'] as $p) {
    if (substr($conf[$p] ?? '', 0, 2) == './') $conf[$p] = APP_PATH . $conf[$p];
}

// 容器方案 P1：App 尚未由 core.php 装载，此处提前装载并以容器为写入口（写透镜像）
include_once CORE_PATH . 'App.php';
App::set('conf', $conf);
include CORE_PATH . 'core.php';
include APP_PATH . 'core/model/addons.php';

// ============================================================
// PSR-4 自动加载（App\ → core/app/, Psr\ → core/psr/；兼容兜底 core/model/ 与 core/lib/）
// ============================================================
spl_autoload_register(function (string $class): void {
    // S-6（2026-10-10 审计修复）：类名仅允许 [A-Za-z0-9_\\] 字符集。class_exists() 等入口
    // 若被不可信字符串触达，旧实现会将其拼进文件路径（legacy 兜底分支存在 ../ 穿越面）。
    if (!preg_match('#^[A-Za-z_][A-Za-z0-9_\\]*$#', $class)) {
        return;
    }
    $prefixes = array(
        'App\\'   => APP_PATH . 'core/app/',
        'Psr\\'   => APP_PATH . 'core/psr/',
    );
    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) continue;
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) { include $file; return; }
    }
    foreach (array(APP_PATH . 'core/model/', APP_PATH . 'core/lib/') as $dir) {
        $legacyFile = $dir . $class . '.php';
        if (file_exists($legacyFile)) { include $legacyFile; return; }
    }
});

// 引导主体（模型加载/KV 配置/请求上下文/路由分发，含全部 index_inc_* 插件埋点）——经编译管线加载
include _include(APP_PATH . 'index.inc.php');
