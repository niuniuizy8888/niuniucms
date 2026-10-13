<?php
!defined('DEBUG') and define('DEBUG', 0); // 0: 线上（默认兜底，生产禁用错误显示）；1: 开发，2: 线上调试日志；各入口显式定义
!defined('APP_PATH') and define('APP_PATH', './');
!defined('CORE_PATH') and define('CORE_PATH', __DIR__ . '/');
include_once CORE_PATH . 'func/polyfill.php';

// ============================================================
// App\ / Psr\ 命名空间自动加载（所有入口共用：index.php / admin / install / CLI）
// install 等入口不加载 model.inc.php，必须在此注册，否则 xn_log() 等首次使用 Service 类会失败
// ============================================================
spl_autoload_register(function (string $class): void {
    $prefixes = array(
        'App\\' => APP_PATH . 'core/app/',
        'Psr\\' => APP_PATH . 'core/psr/',
    );
    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) continue;
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) { include_once $file; return; }
    }
});

function_exists('ini_set') and ini_set('display_errors', DEBUG ? '1' : '0');
error_reporting(DEBUG ? E_ALL : 0);
$starttime = microtime(1);
$time = time();
define('IN_CMD', !empty($_SERVER['SHELL']) || empty($_SERVER['REMOTE_ADDR']));
if (IN_CMD) {
    !isset($_SERVER['REMOTE_ADDR']) and $_SERVER['REMOTE_ADDR'] = '';
    !isset($_SERVER['REQUEST_URI']) and $_SERVER['REQUEST_URI'] = '';
    !isset($_SERVER['REQUEST_METHOD']) and $_SERVER['REQUEST_METHOD'] = 'GET';
} else {
    header("Content-type: text/html; charset=utf-8"); 
    // 上线前安全审计加固：全站基线安全响应头（代码侧兜底，不依赖 nginx 是否张贴）。
    // 与后台入口的同名头一致（后台入口 = admin.php 的改名副本，其 include 本文件后重复 set 同值无副作用）；
    // CSP 需逐资源核对，留待部署侧按需配置。
    header('X-Content-Type-Options: nosniff');      // 防 MIME 嗅探
    header('X-Frame-Options: SAMEORIGIN');          // 防点击劫持（防本站被第三方 iframe 套壳）
    header('Referrer-Policy: same-origin');         // 跨站不外泄 Referer（含 token 的 URL 不泄露给第三方）
}
// ============================================================
// 核心函数库（无配置依赖，始终加载）
// ============================================================
include CORE_PATH . 'func/array.php';
include CORE_PATH . 'lib/core.php';
include CORE_PATH . 'lib/encrypt.php';
include CORE_PATH . 'func/misc.php';
include CORE_PATH . 'func/form.php';
include CORE_PATH . 'func/ip.php';
include CORE_PATH . 'func/http.php';
include CORE_PATH . 'func/globals.php';
include CORE_PATH . 'func/validate.php';

// ============================================================
// 数据库驱动
// PdoDriver/ProxyDriver/ConnectionPool/QueryBuilder 按需加载
// db_mysql / db_pdo_mysql 已删除（由 PdoDriver 取代）
// ============================================================
include CORE_PATH . 'db/db.php';  // 包含 PdoDriver + ProxyDriver + ConnectionPool + QueryBuilder

// ============================================================
// 缓存驱动
// 新驱动（RedisDriver/MemcachedDriver 等）由 cache.php 管理
// 传统驱动（cache_mysql / cache_redis 等）按需加载
// ============================================================
include CORE_PATH . 'cache/cache.php';  // cache_* 函数 + 新缓存驱动
empty($conf) and $conf = array('db' => array(), 'cache' => array(), 'cache_path' => './', 'log_path' => './', 'timezone' => 'Asia/Shanghai');
// 加载独立数据库配置（如果存在则合并）
$dbConfigFile = APP_PATH . 'core/config/database.php';
if (is_file($dbConfigFile)) {
    $dbConf = include $dbConfigFile;
    if (is_array($dbConf) && !empty($dbConf['master'])) {
        $conf['db'] = $dbConf;
    }
}
// ============================================================
// 按需加载传统数据库驱动（配置合并后再判断）
// ============================================================
$dbType = $conf['db']['type'] ?? 'proxy';
if ($dbType === 'pdo_mysql' || $dbType === 'pdo' || $dbType === 'proxy') {
    include_once CORE_PATH . 'db/PdoDriver.php';
}

// ============================================================
// 传统缓存驱动加载 — MultiLayerCache MySQL 层依赖 cache_mysql 类
// ============================================================
include CORE_PATH . 'cache/cache_mysql.php';

empty($conf['cache_path']) and $conf['cache_path'] = ini_get('upload_tmp_dir');
empty($conf['log_path']) and $conf['log_path'] = './';
// 必须在 ip()/bull_longip() 之前写入：两者内部读 _SERVER('conf') 的 cdn_on
$_SERVER['conf'] = $conf;
$ip = ip();
$longip = bull_longip();
$useragent = _SERVER('HTTP_USER_AGENT');
!isset($lang) and $lang = array();
$errno = 0;
$errstr = '';
DEBUG and set_exception_handler('exception_handler');
DEBUG and set_error_handler('error_handle', -1);
empty($conf['timezone']) and $conf['timezone'] = 'Asia/Shanghai';
date_default_timezone_set($conf['timezone']);
// 超级全局变量
!empty($_SERVER['HTTP_X_REWRITE_URL']) and $_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_REWRITE_URL'];
!isset($_SERVER['REQUEST_URI']) and $_SERVER['REQUEST_URI'] = ''; 
$_REQUEST = array_merge(xn_url_parse($_SERVER['REQUEST_URI'], $conf), $_GET, $_POST);
// 注：$_COOKIE 不再并入 $_REQUEST（M2）——此前 Cookie 可覆盖 param() 读取的任何参数
// 且绕过 XssFilterMiddleware（其仅清洗 $_GET/$_POST）；Cookie 读取统一走 _COOKIE() 门面
// IP 地址
!isset($_SERVER['REMOTE_ADDR']) and $_SERVER['REMOTE_ADDR'] = '';
!isset($_SERVER['SERVER_ADDR']) and $_SERVER['SERVER_ADDR'] = ''; 
$json = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower(trim($_SERVER['HTTP_X_REQUESTED_WITH'])) == 'xmlhttprequest') || param('json');
$method = $_SERVER['REQUEST_METHOD']; 
$_SERVER['starttime'] = $starttime;
$_SERVER['time'] = $time;
$_SERVER['ip'] = $ip;
$_SERVER['longip'] = $longip;
$_SERVER['useragent'] = $useragent;
$_SERVER['lang'] = $lang;
$_SERVER['errno'] = $errno;
$_SERVER['errstr'] = $errstr;
$_SERVER['method'] = $method;
$_SERVER['json'] = $json;
// S-8（2026-10-10 审计修复）：删除 get_magic_quotes_gpc 死遗留（全仓零读取方，PHP 8 无此语义）
$db = !empty($conf['db']) ? db_new($conf['db']) : NULL;
// 先注册全局 db 引用：cache_new/cache_multi_init 的 mysql 层需要复用连接（须在 cache 初始化之前）
// 容器方案 P1：App 容器提前装载，注册键统一经 App::set 写透镜像（$_SERVER 为兼容视图）
include_once CORE_PATH . 'App.php';
App::set('db', $db);
// 多层缓存已启用时跳过单驱动，由 cache_multi_init 接管
if (!empty($conf['cache']['multi']['enable'])) {
    $cache = null;
} else {
    $cache = !empty($conf['cache']) ? cache_new($conf['cache']) : NULL;
}
// auth_key 兜底：外部扩展若提供 auth_key() 则优先采用，否则缺失时取**稳定**回退值。
// ✅ 收尾轮修复审计 S-3：旧回退是 `md5(__FILE__ . __DIR__ . php_uname() . uniqid(mt_rand(), true))`
//   ——弱熵且**每请求重生成**，表现为免登录 token / 指纹环 / admin_token 请求间漂移（周期性全员掉登录）
//   且日志无痕、极难诊断。现走 xn_auth_key_fallback()：优先持久化复用
//   `<cache_path>/.auth_key_fallback.php`（点文件，clearTmpDir 不删），落盘不可用时退化为
//   基于 config 内容的确定性 sha256 派生（跨请求稳定、不可公网猜测），并在 auth_key_error 通道留痕。
//   正常站点由安装器 xn_rand(64) 写入 config.php ⇒ 本回退不会被触发；仍不建议依赖它跑生产
//   （删 cache_path / 迁移服务器会换 key ⇒ 需重新登录一次）。
!empty($conf) and (function_exists('auth_key') ? ($conf['auth_key'] = auth_key()) : (empty($conf['auth_key']) ? ($conf['auth_key'] = xn_auth_key_fallback($conf)) : NULL));
$_SERVER['cache'] = $cache;

// 多层缓存初始化（配置存在时自动启用）
if (!empty($conf['cache']['multi']['enable'])) {
    $g_multi_cache = cache_multi_init();
    if ($g_multi_cache) {
        $_SERVER['cache'] = $g_multi_cache;
        $cache = $g_multi_cache; // 容器方案 P0：$cache 持终值，消除「容器 null 靠 migrate 巧合自愈」的脆弱依赖
    }
}

// App 容器注册（写透镜像：$_SERVER 注册键由 App::set 统一维护）
App::set('cache', $cache);
App::set('conf', $conf);
App::set('time', $time);
App::set('longip', $longip);
App::set('method', $method);
App::set('json', $json);
App::set('useragent', $useragent);
App::set('ip', $ip);
App::migrateFromServer();

