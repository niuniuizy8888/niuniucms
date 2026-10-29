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
require_once APP_PATH . 'core/paths.php'; // U-2è¿è¡æ¶éç½®è§£æï¼data/ ä¼åï¼åé core/configï¼
// PHP 版本下限（与安装向导 NNCMS_PHP_MIN 一致）：低版本给出明确提示，而不是解析错误/空白 500。
// 本文件及其之前加载的文件只用 7.x 也能解析的语法，保证这道闸在低版本上能执行到。
if (PHP_VERSION_ID < 80000) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    exit('牛牛CMS 需要 PHP 8.0 或更高版本（推荐 8.2+），当前版本：' . PHP_VERSION . '。请在主机面板中切换 PHP 版本。');
}
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
    // 核心类文件缺失（NNCMS_MISSING_CLASS_HINT）：常见于主机安全软件（Imunify360 / ClamAV / 面板木马扫描）
    // 把含「高危函数名黑名单」的文件（如 TemplateService.php）误判为后门隔离删除，或程序包未完整上传。
    // 原先只会得到空白的 HTTP 500，这里改为输出明确的缺失文件提示（不含绝对路径），便于站长自查。
    if (preg_match('/Class ["\'](App\\\\[A-Za-z0-9_\\\\]+)["\'] not found/', $msg, $cm)) {
        $rel = 'core/app/' . str_replace('\\', '/', substr($cm[1], 4)) . '.php';
        if (!is_file(APP_PATH . $rel)) {
            if (!headers_sent()) {
                // 致命错误后引擎已写入 "HTTP/1.0 500" 状态行，http_response_code() 覆盖不了，须显式替换状态行
                header((isset($_SERVER['SERVER_PROTOCOL']) && is_string($_SERVER['SERVER_PROTOCOL']) && preg_match('#^HTTP/\d(\.\d)?$#', $_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1') . ' 503 Service Unavailable', true, 503);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!doctype html><meta charset="utf-8"><title>核心文件缺失</title>'
                . '<div style="max-width:680px;margin:60px auto;padding:0 16px;font:15px/1.8 sans-serif;color:#333">'
                . '<h2>牛牛CMS：核心文件缺失</h2>'
                . '<p>找不到程序文件 <code>' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') . '</code>。</p>'
                . '<p>常见原因：主机的安全/杀毒软件（如 Imunify360、ClamAV、面板的木马扫描）把该文件误判为后门并隔离或删除；或程序包没有完整上传。</p>'
                . '<p>处理方法：从原始安装包重新上传该文件，并在安全软件里把它加入白名单（或从隔离区恢复），然后刷新本页。</p></div>';
        }
    }
    // 核心函数未定义（NNCMS_MISSING_FUNC_HINT）：典型为编译缓存被清空/截断（如 core_model_view.php=0 字节后
    // 拼进 model.min.php ⇒ Call to undefined function _file()）。自动删除合并缓存与 0 字节模型产物，
    // 下个请求即重建；同时给出明确提示（不含绝对路径），而不是空白 500。
    elseif (preg_match('/Call to undefined function ([A-Za-z0-9_\\\\]+)\(\)/', $msg, $fm)) {
        $cacheDir = (isset($GLOBALS['conf']['cache_path']) && is_string($GLOBALS['conf']['cache_path']) && '' !== $GLOBALS['conf']['cache_path'])
            ? $GLOBALS['conf']['cache_path'] : APP_PATH . 'runtime/cache/';
        // 只认核心模型哨兵函数：模板里调用不存在的函数属模板问题，不应触发清缓存/误导提示
        $coreFns = array('_file', 'view_load', 'kv_get', 'runtime_init', 'cate_create', 'user__create', 'session_read', 'art__update', 'video__update', 'tag__create', 'get_tag');
        if (in_array($fm[1], $coreFns, true)) {
            $healed = 0;
            if (is_file($cacheDir . 'model.min.php') && @unlink($cacheDir . 'model.min.php')) $healed++;
            function_exists('opcache_invalidate') && @opcache_invalidate($cacheDir . 'model.min.php', TRUE);
            foreach ((array)@glob($cacheDir . 'core_model_*.php') as $cf) {
                if (is_file($cf) && 0 === (int)@filesize($cf) && @unlink($cf)) $healed++;
            }
            if (!headers_sent()) {
                header((isset($_SERVER['SERVER_PROTOCOL']) && is_string($_SERVER['SERVER_PROTOCOL']) && preg_match('#^HTTP/\d(\.\d)?$#', $_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1') . ' 503 Service Unavailable', true, 503);
                header('Retry-After: 5');
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!doctype html><meta charset="utf-8"><title>编译缓存异常</title>'
                . '<div style="max-width:680px;margin:60px auto;padding:0 16px;font:15px/1.8 sans-serif;color:#333">'
                . '<h2>牛牛CMS：编译缓存异常</h2>'
                . '<p>核心函数 <code>' . htmlspecialchars($fm[1], ENT_QUOTES, 'UTF-8') . '()</code> 未加载，通常是编译缓存文件被清空或截断。</p>'
                . ($healed ? '<p>已自动清理损坏的缓存（' . $healed . ' 个文件），请<b>刷新本页</b>。</p>' : '<p>请删除 <code>runtime/cache/</code> 目录下的文件后刷新本页。</p>')
                . '<p>如果刷新后仍反复出现：请检查 <code>core/model/view.php</code> 等核心文件是否被主机安全软件（Imunify360、ClamAV、面板木马扫描）清空或隔离，'
                . '从原始安装包重新上传并加入白名单；同时确认空间磁盘配额未满。</p></div>';
        }
    }
});

// 加载工具函数（get_domain / serveStaticFile 等）
include CORE_PATH . 'func/static.php';

// 静态文件直接服务
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$reqPath = $reqPath ? rawurldecode($reqPath) : '';
if ($reqPath && '/sitemap.xml' !== strtolower($reqPath) && strtolower(pathinfo($reqPath, PATHINFO_EXTENSION))) {
    serveStaticFile($reqPath);
}

// 加载配置
$configFile = nncms_runtime_path('config.php');
// 安装锁（安全审查第 2 轮 U-2）：新位置 core/config/install.lock（core/ 可整体只读、仅 core/config/ 可写）；
// 旧位置 core/install.lock 继续认作「已安装」，并在 core/config/ 可写时自动复制一份到新位置
// （保留旧文件：已按旧路径配置的 nginx `if (-f core/install.lock)` 规则继续生效）。锁换位置绝不会把站点当成未安装。
$lockFile = nncms_runtime_path('install.lock');
$legacyLockFile = APP_PATH . 'core/install.lock';
$installLocked = is_file($lockFile);
if (!$installLocked && is_file($legacyLockFile)) {
    $installLocked = TRUE;
    if (is_dir(APP_PATH . 'core/config') && is_writable(APP_PATH . 'core/config')) {
        @copy($legacyLockFile, $lockFile);
    }
}
unset($lockFile, $legacyLockFile);
// 未安装（两处 install.lock 都不在，或缺 core/config/config.php）直接跳转安装向导，不再使用 install_missing.php 引导页
if (!$installLocked || !is_file($configFile)) {
    // 旧版 1.0.x 数据仍在（新版程序包已覆盖、升级尚未完成）：不要把访客引向安装向导
    if (is_file(APP_PATH . 'config/config.php') || is_file(APP_PATH . 'install.lock')) {
        http_response_code(503);
        header('Retry-After: 600');
        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><meta charset="utf-8"><title>站点升级中</title><div style="font:16px/1.6 sans-serif;text-align:center;padding:80px 16px"><h2>站点升级维护中</h2><p>请稍后再访问。</p></div>');
    }
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
$domain = (@include nncms_runtime_path('domain.php'));
is_array($domain) or $domain = array('site' => array());
// 站群匹配：完整主机名（如 m.a.com）精确命中优先，未登记再回退主域名（a.com，覆盖其全部子域名）。
// 旧 domain.php 的键均为主域名，行为不变（安全审查第 2 轮 U-8）。
$hosts = '';
if (function_exists('get_host_name')) {
    $_nn_fullhost = get_host_name();
    if ('' !== $_nn_fullhost && isset($domain['site'][$_nn_fullhost])) $hosts = $_nn_fullhost;
    unset($_nn_fullhost);
}
'' === $hosts and $hosts = function_exists('get_domain') ? get_domain() : '';
$siteconf = array();
// 主站（config.php）伪静态档位留底：站群站点改写 url_rewrite_on 后，单行内容缓存键据此区分档位（ContentQueryTrait::read）
$conf['url_rewrite_base'] = intval($conf['url_rewrite_on'] ?? 0);
if ($hosts && isset($domain['site'][$hosts]) && is_array($domain['site'][$hosts])) {
    $siteconf = $domain['site'][$hosts];
    // 伪静态留空（''）= 跟随主站；仅 0-3 的整数档位才覆盖
    if (isset($siteconf['site_rewrite']) && '' !== (string)$siteconf['site_rewrite'] && ctype_digit((string)$siteconf['site_rewrite']) && intval($siteconf['site_rewrite']) <= 3) {
        $conf['url_rewrite_on'] = intval($siteconf['site_rewrite']);
        $conf['path'] = ($conf['url_rewrite_on'] >= 2) ? '/' : './';
        $conf['cookie_path'] = ($conf['url_rewrite_on'] >= 2) ? '/' : '';
    }
    // 站点名留空 = 沿用主站名称（此前空串也会覆盖，前台标题/LOGO 文字变空）
    if (isset($siteconf['site_name']) && '' !== (string)$siteconf['site_name']) {
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
// 上线前审计 WEB-01：函数层已装载后校验 Host（白名单空则放行，见 http_host_allowed）
if (PHP_SAPI !== 'cli' && function_exists('http_host_allowed') && !http_host_allowed(null, $domain, is_array($conf) ? $conf : array())) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
    }
    exit('Bad Request: invalid Host');
}
include APP_PATH . 'core/model/addons.php';

// 类自动加载：统一由 core/core.php 注册（App\ → core/app/、Psr\ → core/psr/，所有入口共用）。
// 这里原先另注册了一个带 core/model/、core/lib/ 兜底的加载器，但其类名校验正则
// '#^[A-Za-z_][A-Za-z0-9_\\]*$#' 在单引号里少一层转义，PCRE 编译失败 ⇒ 对任何类名都直接 return，
// 从未生效过（实际加载一直由 core.php 完成）。core/model/*.php 多为函数文件、用 include 加载，
// 若「修好」该兜底反而可能被 class_exists() 触发重复 include 而 fatal，故整段移除。

// 引导主体（模型加载/KV 配置/请求上下文/路由分发，含全部 index_inc_* 插件埋点）——经编译管线加载
include _include(APP_PATH . 'index.inc.php');
