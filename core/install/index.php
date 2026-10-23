<?php
// PHP 版本下限：唯一定义点，闸门与环境检查页都用它，避免两处漂移。
// 最低 PHP 8.0（与 README、商店页一致），推荐 8.2+。本文件只用 7.x 也能解析的语法，
// 低版本访问时给出明确提示（503），而不是解析错误或空白 500。
define('NNCMS_PHP_MIN', '8.0');
define('NNCMS_PHP_RECOMMEND', '8.2');
if (version_compare(PHP_VERSION, NNCMS_PHP_MIN, '<')) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    die('牛牛CMS 需要 PHP ' . NNCMS_PHP_MIN . ' 或更高版本（推荐 ' . NNCMS_PHP_RECOMMEND . '+）。当前版本：' . PHP_VERSION . '。请在主机面板中切换 PHP 版本后重试。');
}
// APP_PATH/INSTALL_PATH 由根目录 install.php 预定义；直接访问本文件时兜底（nginx 已 deny core/）
!defined('APP_PATH') AND define('APP_PATH', realpath(__DIR__ . '/../../') . '/');
!defined('INSTALL_PATH') AND define('INSTALL_PATH', __DIR__ . '/');
// 审计修复（F-04）：install.php 是公开入口。此前无条件 define('DEBUG', 2)，
// 使 core/core.php 打开 display_errors + error_reporting(E_ALL)；已安装站点访问 /install.php
// 时整条引导链在开调试态下执行（失败即向访客泄漏绝对路径/内部信息）。已安装（install.lock 或
// config.php 在位）时用生产态 DEBUG=0，安装向导阶段才保留调试态。
// 旧版 1.0.x 站点标记（根目录 config/config.php、install.lock、install/index.php）：存在即视为「已安装、待升级」，
// 安装向导一律拒绝（防止先覆盖新版程序包、尚未运行 upgrade.php 的窗口期被他人重装接管）。
define('NNCMS_LEGACY_MARK', is_file(APP_PATH . 'config/config.php') ? 'config/config.php' : (is_file(APP_PATH . 'install.lock') ? 'install.lock' : (is_file(APP_PATH . 'install/index.php') ? 'install/index.php' : '')));
define('DEBUG', (is_file(APP_PATH . 'core/install.lock') || is_file(APP_PATH . 'core/config/config.php') || '' !== NNCMS_LEGACY_MARK) ? 0 : 2);
define('MESSAGE_HTM_PATH', INSTALL_PATH . 'html/message.html');
$conf = (include APP_PATH . 'core/config/config.default.php');
// 安装向导阶段 config.php 尚未生成，出厂 auth_key 为空 ⇒ core.php 会走 xn_auth_key_fallback()：
// 每个向导页都往 auth_key_error 写一条「缺 key」并落一个 .auth_key_fallback.php——纯误报（用户报告日志里
// 5 条 auth_key_error 全是 /install.php 请求）。向导期给一个仅内存、跨请求稳定的临时值即可；
// 真正的站点密钥由下方 xn_rand(64) 写入 config.php 并回读校验。
if (empty($conf['auth_key'])) {
    $conf['auth_key'] = hash('sha256', 'nncms-installer|' . APP_PATH . '|' . @filemtime(APP_PATH . 'core/config/config.default.php') . '|' . php_uname('n'));
}
$conf['log_path'] = APP_PATH .'runtime/log/';
$conf['cache_path'] = APP_PATH . 'runtime/cache/';
include APP_PATH . 'core/core.php';
include APP_PATH . 'core/model/addons.php';
include APP_PATH . 'core/model/user.php';
$lang=include APP_PATH . 'core/lang/zh_cn.php';
$_SERVER['lang'] = $lang;
is_file(APP_PATH . 'core/install.lock') AND message(0, jump($lang['already_installed'], '../'));
is_file(APP_PATH . 'core/config/config.php') AND message(0, jump($lang['installed_tips'], '../'));
if ('' !== NNCMS_LEGACY_MARK) {
    http_response_code(403);
    message(-1, '检测到旧版牛牛CMS 的数据（' . NNCMS_LEGACY_MARK . '），这是一个待升级的站点，安装向导已禁用。请上传并运行 upgrade.php 完成升级；如确实要全新安装，请先删除旧版的 config/、install/ 目录与根目录 install.lock。');
}
// 安装入口文件名（修正：html 内硬编码 index.php 相对跳转在 install.php 入口下
// 解析为站点前台 index.php 导致"继续安装不跳转"；改为动态取当前脚本名，白名单过滤防注入）
$install_entry = basename($_SERVER['SCRIPT_NAME'] ?? 'install.php');
if (!preg_match('#^[A-Za-z0-9_.\-]+$#', $install_entry)) {
    $install_entry = 'install.php';
}
$action = param('action');
if (empty($action)) {
    if ($method == 'GET') {
       include INSTALL_PATH . "html/index.html"; 
}
}elseif ($action == 'chenk') { 
    $agree = param('agree', 0);
    if (1 != $agree) http_location($install_entry); 
    if ($method == 'GET') {
        $succeed = 1;
        $env = $write = $funclist = array();
        get_env($env, $write, $funclist);
        include INSTALL_PATH . "html/chenk.html";
    }
}elseif ($action == 'database') {
    if ($method == 'GET') { 
        $agree = param('agree', 0);
        if (1 != $agree) http_location($install_entry); 
        $succeed = 1; 
        $pdo_mysql_support = extension_loaded('pdo_mysql');
        $myisam_support = extension_loaded('pdo_mysql');
        $innodb_support = extension_loaded('pdo_mysql'); 
        !$pdo_mysql_support AND message(-1, lang('evn_not_support_php_mysql')); 
        // 跳过环境检测页直达本步时同样拦截（必需扩展 / 可写目录 / 核心文件）
        ($precheck = install_precheck_error()) AND message(-1, $precheck);
        include INSTALL_PATH . "html/database.html";
    } else {
        $type = 'pdo_mysql';
        $engine = param('engine');
        $host = param('host');
        $name = param('name');
        $user = param('user');
        $password = param('password');
        $tablepre = param('tablepre');
        $force = param('force');
        $adminemail = param('adminemail');
        $adminuser = param('adminuser');
        $adminpass = param('adminpass');
        $adminpassrepeat = param('adminpassrepeat');
        empty($host) AND message('host', lang('dbhost_is_empty'));
        empty($name) AND message('name', lang('dbname_is_empty'));
        empty($user) AND message('user', lang('dbuser_is_empty'));
        empty($adminpass) AND message('adminpass', lang('adminuser_is_empty'));
        $adminpassrepeat != $adminpass and message('adminpassrepeat', lang('password_incorrect'));
        empty($adminemail) AND message('adminemail', lang('adminpass_is_empty'));
        // 管理员用户名/密码强度校验（新增）
        if (!preg_match('#^[a-zA-Z0-9_]{2,32}$#', $adminuser)) {
            message('adminuser', '管理员用户名不合法：仅允许字母、数字、下划线，长度 2-32');
        }
        if (strlen($adminpass) < 6) {
            message('adminpass', '管理员密码至少 6 位');
        }
        // POST 直达时同样拦截缺少 pdo_mysql 的环境（GET 页已拦截，防绕过）
        !extension_loaded('pdo_mysql') AND message(-1, lang('evn_not_support_php_mysql'));
        // 写库前先做环境前置检查：缺必需扩展、目录不可写、核心文件缺失都在这里给出明确提示，
        // 避免写到一半才因权限失败（以前会冒出 array_merge() TypeError）
        ($precheck = install_precheck_error()) AND message(-1, $precheck);
        // 表前缀标识符校验（install_sql_file 将其拼接进建表 SQL，防反引号逃逸）
        if (!_db_is_valid_ident($tablepre)) {
            message('tablepre', '表前缀不合法：仅允许字母、数字、下划线，且以字母或下划线开头');
        }
        // 库名校验：MySQL 反引号包裹后支持数字开头（如 38_123_cms），
        // 这里只继续限制到字母/数字/下划线，避免把连字符、空格等字符带进 DSN / CREATE DATABASE。
        if (!_db_is_valid_database_name($name)) {
            message('name', '数据库名称不合法：仅允许字母、数字、下划线，可数字开头，例如 38_123_cms');
        }
        ini_set('mysql.connect_timeout', 5);
        ini_set('default_socket_timeout', 5);
        $conf['db']['type'] = $type;
        $conf['db']['mysql']['master']['host'] = $host;
        $conf['db']['mysql']['master']['name'] = $name;
        $conf['db']['mysql']['master']['user'] = $user;
        $conf['db']['mysql']['master']['password'] = $password;
        $conf['db']['mysql']['master']['tablepre'] = $tablepre;
        $conf['db']['mysql']['master']['engine'] = $engine;
        $conf['db']['pdo_mysql']['master']['host'] = $host;
        $conf['db']['pdo_mysql']['master']['name'] = $name;
        $conf['db']['pdo_mysql']['master']['user'] = $user;
        $conf['db']['pdo_mysql']['master']['password'] = $password;
        $conf['db']['pdo_mysql']['master']['tablepre'] = $tablepre;
        $conf['db']['pdo_mysql']['master']['engine'] = $engine;
        $pre = preg_replace('#[^a-zA-Z0-9_.\-]#', '', ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '_';
        $conf['cache']['memcached']['cachepre'] = $pre;
        $conf['cache']['yac']['cachepre'] = $pre;
        $conf['cache']['mysql']['cachepre'] = $pre;
        $_SERVER['db'] = $db = db_new($conf['db']);
        $r = db_connect($db);
        if (!$r) {
            if (1049 == $errno) {
                // 库不存在：自动建库（仅 1049 走建库；1045 认证失败等直接报错，避免误导；
                // 库名白名单已在字段校验区拦截）
                if (str_contains($host, ':')) {
                    $arr = explode(':', $host);
                    $host = $arr[0];
                    $port = $arr[1];
                } else {
                    $port = 3306;
                }
                $created = FALSE;
                try {
                    $attr = array(PDO::ATTR_TIMEOUT => 5);
                    $link = new PDO("mysql:host=$host;port=$port", $user, $password, $attr);
                    $created = $link->exec("CREATE DATABASE `$name`");
                } catch (PDOException $e) {
                    $errno = $e->getCode();
                    $errstr = $e->getMessage();
                }
                if (FALSE === $created) {
                    $error = isset($link) ? $link->errorInfo() : array();
                    $errno = $error[1] ?? $errno;
                    $errstr = $error[2] ?? $errstr;
                    message(-1, "$errstr (errno: $errno)");
                }
                // 建库成功后重建站点连接（修复：原 $db 指向尚不存在的库，
                // 建库后未重连导致后续 install.sql 全部执行失败）
                $_SERVER['db'] = $db = db_new($conf['db']);
                $db and $r = db_connect($db);
                if (!$r) {
                    message(-1, "$errstr (errno: $errno)");
                }
            } else {
                message(-1, "$errstr (errno: $errno)");
            }
        } 
        if (!@copy(APP_PATH . 'core/config/config.default.php', APP_PATH . 'core/config/config.php') || !is_file(APP_PATH . 'core/config/config.php')) {
            message(-1, '无法写入配置文件 core/config/config.php：请确认 core/config/ 目录对运行 PHP 的用户（如 www）可写后重试。');
        }
        $replace = array();
        $replace['db'] = $conf['db'];
        // 添加多层缓存配置（最快优先顺序）
        $conf['cache']['multi'] = array(
            'enable' => true,
            'layers' => array(
                array('driver' => 'static'),
                array('driver' => 'apcu',    'config' => array('cachepre' => $pre)),
                array('driver' => 'yac',     'config' => array('cachepre' => $pre)),
                array('driver' => 'redis',   'config' => array('host' => '127.0.0.1', 'port' => 6379, 'cachepre' => $pre)),
                array('driver' => 'memcached','config' => array('servers' => array(array('host' => '127.0.0.1', 'port' => 11211)))),
                array('driver' => 'mysql',   'config' => array('cachepre' => $pre)),
            ),
        );
        $replace['cache'] = $conf['cache'];
        $replace['cookie_pre'] = $tablepre;
        $rand = xn_rand(64);
        $replace['auth_key'] = $rand;
        $replace['installed'] = 1;
        $replace['cms_lang'] = 'zh_cn';
        // 后台入口名：**保持出厂默认 `admin.php`**，不做自动随机改名。
        // 历史沿革：旧实现安装成功时把 admin.php 随机改名并回写 admin_entry（当时入口文件对 admin.php
        // 自身返回 403，属"强制先改名才可用"的配套动作）。现改为「入口默认可直接访问 + 建议改名」：
        //   · 出厂 config.default.php 的 admin_entry 就是 admin.php，与包内文件一致 ⇒ 开箱即用；
        //   · 安全加固由登录限流、写操作场景令牌、未登录改写登录页三道纵深承担；
        //   · 仍想隐去入口名的站点，安装后手工把入口文件改成随机名并同步 admin_entry 即可
        //     （升级时的入口同步见 CloudOpsService::syncAdminEntry()，它按 admin_entry 覆盖对应文件）。
        // 显式写一次 admin_entry，避免与会随机改名的历史包产生混淆。
        $replace['admin_entry'] = 'admin.php';
        $conf['admin_entry'] = 'admin.php'; // done.html 展示实际入口名
        if (!file_replace_var(APP_PATH . 'core/config/config.php', $replace)) {
            @unlink(APP_PATH . 'core/config/config.php');
            message(-1, '写入配置文件 core/config/config.php 失败：请确认 core/config/ 目录及该文件对运行 PHP 的用户（如 www）可写后重试。');
        }
        // 回读校验 auth_key 确实落盘（64 位）：写入"成功"但内容未替换（模板格式被改/opcache 旧值）时站点会
        // 长期依赖回退密钥，安装阶段就拦下
        clearstatcache(TRUE, APP_PATH . 'core/config/config.php');
        function_exists('opcache_invalidate') && @opcache_invalidate(APP_PATH . 'core/config/config.php', TRUE);
        $writtenConf = call_user_func(function ($f) { return @include $f; }, APP_PATH . 'core/config/config.php'); // 隔离作用域，避免 config 顶部变量污染向导
        if (!is_array($writtenConf) || !isset($writtenConf['auth_key']) || $writtenConf['auth_key'] !== $rand || 64 !== strlen((string)$writtenConf['auth_key'])) {
            @unlink(APP_PATH . 'core/config/config.php');
            message(-1, '写入配置文件 core/config/config.php 后校验失败（auth_key 未写入）：请确认该文件可写且未被安全软件拦截后重新安装。');
        }
        unset($writtenConf);
        $conf['auth_key'] = $rand;
        // 安装完成后移除仓库自带的独立数据库配置（config/database.php 会整体覆盖写盘配置），确保安装填写的驱动/凭据生效
        $dbConfigFile = APP_PATH . 'core/config/database.php';
        is_file($dbConfigFile) and @rename($dbConfigFile, $dbConfigFile . '.installed.bak');
        $conf['cache']['mysql']['db'] = $db;  
        $_SERVER['cache'] = $cache = !empty($conf['cache']) ? cache_new($conf['cache']) : NULL;
        install_sql_file($tablepre, INSTALL_PATH . 'install.sql', $engine); 
        // 管理员密码
        $password = password_hash_custom($adminpass);
        $update = array('username' => $adminuser, 'email' => $adminemail, 'password' => $password, 'salt' => '', 'create_date' => $time);
        $r = db_update('user', array('uid' => 1), $update);
        if (!$r) {
            // 覆盖失败将保留 install.sql 内置默认 admin 凭据（公开已知散列），必须阻断上线
            message(-1, '管理员账号写入失败（请检查数据库权限）。默认凭据未被覆盖，安装已中止：请删除 core/config/config.php 后重新安装');
        }
        // 安装信息上报（用户需求）：表单默认勾选 report=1（database.html checked），
        // 取消勾选则 POST 不携带该字段（param 默认 0）；勾选即上报 token 至云端登记——
        // 云端异步采集依赖该登记，不上报则无法使用云端异步采集（云端 token 校验天然拦截）；
        // 上报失败静默不阻塞安装；以用户显式勾选为准，不再受 install_report_on 配置/IP 过滤限制
        $report = param('report', 0);
        $replace = array();
        $replace['install_report'] = !empty($report) ? 1 : 0;
        if (!empty($report)) {
            // 上报地址（B3）：只用 config.default 的 cloud_api（商店服务端，域名派生自 $nn_cloud_host）
            $defaults = include APP_PATH . 'core/config/config.default.php';
            $cloudApi = isset($defaults['cloud_api']) ? rtrim(trim((string)$defaults['cloud_api']), '/') : '';
            // token=api_wire_token('api') 派生值（md5(auth_key|nncms-api-token|api)）：云端 rsync 回调/
            // 替换推送回带该 token，CMS api.php 走主放行路径校验（auth_key 本体不再上报）
            $post = array(
                'token' => md5($rand . '|nncms-api-token|api'),
                'host' => _SERVER('HTTP_HOST'),
                'domain' => _SERVER('REQUEST_SCHEME').'://'._SERVER('HTTP_HOST'),
                'serverip' =>_SERVER('SERVER_ADDR'),
                'cms_version' => isset($defaults['version']) ? (string)$defaults['version'] : '',
                'php_version' => PHP_VERSION,
                // 站点名（安装向导无站点名输入，取出厂默认 sitename，后台改名不会回写云端）
                'sitename' => isset($defaults['sitename']) ? (string)$defaults['sitename'] : '',
                );
            // 上报地址：只用 config.default 的 cloud_api（域名派生自 $nn_cloud_host）。
            // 【已移除】旧的 `http://example.com/?cmsinstall.html` 回退：该官方云端已停用，
            // 且是明文 HTTP，保留回退只会把安装信息发到一个不再服务的地址（fail-closed 更安全）。
            // cloud_api 留空 ⇒ 直接跳过上报，不产生任何出网请求。
            $reportUrl = '' !== $cloudApi ? $cloudApi . '/index.php?api=install' : '';
            if ('' !== $reportUrl) {
                $json = https_request($reportUrl, $post, '', 500, 1, 8 * 1048576);
            } else {
                xn_log('install report skipped: cloud_api is empty', 'install_report');
            }
        }
        if (FALSE === @file_put_contents(APP_PATH . 'core/install.lock',  'install_time:'.date('Y-m-d H:i:s'))) {
            message(-1, '无法写入 core/install.lock：请确认 core/ 目录对运行 PHP 的用户（如 www）可写。数据库与配置已写入，修正权限后手动创建该文件（内容任意）即可完成安装。');
        }
        // 生成8位随机后台目录名
        /*$admin_path = strtolower(xn_rand(8));
        // 重命名后台目录（兼容交付包目录 x9m2k7qa 与开发/常规形态目录 admin）
        $old_dir_name = is_dir(APP_PATH . 'x9m2k7qa') ? 'x9m2k7qa' : (is_dir(APP_PATH . 'admin') ? 'admin' : '');
        $old_admin_path = APP_PATH . $old_dir_name;
        $new_admin_path = APP_PATH . $admin_path;
        if ($old_dir_name && is_dir($old_admin_path) && !is_dir($new_admin_path)) {
            if (!@rename($old_admin_path, $new_admin_path)) {
                // 重命名失败（权限/占用）：回滚 install.lock 让安装可重试，并明确报错
                @unlink(APP_PATH . 'core/install.lock');
                message(-1, '后台目录重命名失败（请检查目录权限）。注意：安装配置已写入，重试前需先删除 config/config.php');
            }
        }
        // 将新的 admin 路径写入配置
        $replace = array();
        $replace['admin_path'] = $admin_path;*/
        if (!file_replace_var(APP_PATH . 'core/config/config.php', $replace)) {
            message(-1, '写入配置文件 core/config/config.php 失败：请确认该文件对运行 PHP 的用户（如 www）可写。');
        }
        // 独立安装完成页（成功走专用完成页，风格与安装向导一致；失败仍走 message.html）
        include INSTALL_PATH . 'html/done.html';
        exit;
    }
}
/**
 * 安装向导「环境检测」页的数据装配（三个参数全部按引用回填，函数本身无返回值）。
 *
 *  · $env：系统/PHP 版本检查项，每项 {name,must,current,need,status}；status 由 version_compare
 *    对 PHP 下限（NNCMS_PHP_MIN，现 8.0）取值，os 项恒 status=1（仅展示 Unix-like 建议，不做实际阻断）。
 *  · $funclist：扩展/函数依赖清单 [{name,tip,msg,ok,must}]，来自 install_ext_list()：
 *    must=TRUE（pdo/pdo_mysql/mbstring/ctype/tokenizer/iconv/json/openssl/filter）缺失时 chenk.html
 *    置 $succeed=0 阻断下一步；其余（curl/gd/zip/fileinfo/glob/gzinflate）只提示不阻断。
 *  · $write：可写目录检测（来自 install_writable_dirs()：runtime/log、runtime/cache、upload、plugins、
 *    template、core/config、core 七项）。目录不存在时
 *    先 @mkdir(0755, recursive) 再判 xn_is_writable()；检测用 APP_PATH 绝对路径（原相对 CWD 写法在
 *    HTTP 下工作目录不可控会全部误报不可写，已修）。
 *
 * 仅在安装向导（install.lock 不存在时才可达）里被调用，不作为运行时 API。
 *
 * @param array $env      按引用回填：环境检查项表
 * @param array $write    按引用回填：目录标签 => 是否可写（bool）
 * @param array $funclist 按引用回填：扩展/函数依赖清单
 * @return void
 */
function get_env(&$env, &$write, &$funclist)
{
    $env['os']['name'] = lang('os');
    $env['os']['must'] = TRUE;
    $env['os']['current'] = PHP_OS;
    $env['os']['need'] = lang('unix_like');
    $env['os']['status'] = 1; 
    $env['php_version']['name'] = lang('php_version');
    $env['php_version']['must'] = TRUE;
    $env['php_version']['current'] = PHP_VERSION;
    $env['php_version']['need'] = NNCMS_PHP_MIN;
    $env['php_version']['status'] = version_compare(PHP_VERSION, NNCMS_PHP_MIN) >= 0;
    // 核心文件完整性（NNCMS_CORE_FILES_CHECK）：引导链直接引用的类文件缺一即全站 500。
    // 典型诱因是主机安全软件把含高危函数名黑名单的文件（TemplateService.php 等）误判隔离，
    // 或上传不完整；在安装前拦下并点名缺失文件，避免「安装完成后首页/后台 HTTP 500」。
    $coreMissing = install_missing_core_files();
    $env['core_files']['name'] = '核心文件完整性';
    $env['core_files']['must'] = TRUE;
    $env['core_files']['current'] = $coreMissing ? '缺失：' . implode('、', $coreMissing) . '（可能被主机安全软件隔离或上传不完整，请重新上传并加白名单）' : '完整';
    $env['core_files']['need'] = '完整';
    $env['core_files']['status'] = $coreMissing ? 0 : 1;
    // 函数/扩展依赖检查：清单见 install_ext_list()；must=TRUE 缺失时阻断安装（succeed=0），其余仅提示
    $funclist = install_ext_list();
    $writedir = install_writable_dirs();
    $write = array();
    foreach ($writedir as $label => $dir) {
        // 修正：原实现用相对 CWD 路径 './../xxx'，HTTP 下工作目录不可控导致
        // 全部误报不可写；改为 APP_PATH 绝对路径检测。目录不存在时自动创建（安装运行
        // 本来就依赖这些目录，父目录可写即视为可写）。
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, TRUE);
        }
        $write[$label] = xn_is_writable($dir);
    }
}
/**
 * 安装期扩展依赖清单（依据代码实际用法核对）：
 *  - 必需（缺失会在安装后前台/后台直接 fatal）：pdo + pdo_mysql（数据库）、mbstring（mb_* 无兜底，
 *    前台路由/登录限流/校验函数都用）、ctype（ctype_digit 用于登录态与路由校验）、tokenizer
 *    （模板/插件编译管线与安全闸用 token_get_all，缺失时首页空白、后台 500）、iconv（分词与截断）、
 *    json、openssl（加密与 Cookie，拒绝降级）、filter（filter_var，每个请求解析域名都用）。
 *  - 可选（缺失只影响对应功能）：curl（采集/云端/升级）、gd（缩略图）、zip（模板/插件安装与在线升级）、
 *    fileinfo（上传 MIME 检测，缺失降级为扩展名白名单）、glob / gzinflate 函数。
 * @return array[] [{name,tip,msg,ok,must}]
 */
function install_ext_list()
{
    $req = '（必需）缺失将导致安装后页面 500 或空白，请在 PHP 设置中安装/启用该扩展后刷新本页';
    return array(
        array('name' => 'pdo',        'tip' => 'function_pdo_not_exists',       'msg' => 'PDO ' . $req,       'ok' => extension_loaded('pdo'),        'must' => TRUE),
        array('name' => 'pdo_mysql',  'tip' => 'function_pdo_mysql_not_exists', 'msg' => 'pdo_mysql ' . $req, 'ok' => extension_loaded('pdo_mysql'),  'must' => TRUE),
        array('name' => 'mbstring',   'tip' => 'function_mb_substr_not_exists', 'msg' => 'mbstring ' . $req,  'ok' => extension_loaded('mbstring'),   'must' => TRUE),
        array('name' => 'ctype',      'tip' => '',                              'msg' => 'ctype ' . $req,     'ok' => extension_loaded('ctype'),      'must' => TRUE),
        array('name' => 'tokenizer',  'tip' => '',                              'msg' => 'tokenizer ' . $req, 'ok' => function_exists('token_get_all'), 'must' => TRUE),
        array('name' => 'iconv',      'tip' => '',                              'msg' => 'iconv ' . $req,     'ok' => function_exists('iconv'),       'must' => TRUE),
        array('name' => 'json',       'tip' => '',                              'msg' => 'json ' . $req,      'ok' => function_exists('json_encode'), 'must' => TRUE),
        array('name' => 'openssl',    'tip' => 'function_openssl_not_exists',   'msg' => 'openssl ' . $req,   'ok' => extension_loaded('openssl'),    'must' => TRUE),
        array('name' => 'filter',     'tip' => '',                              'msg' => 'filter ' . $req,    'ok' => function_exists('filter_var'),  'must' => TRUE),
        array('name' => 'curl',       'tip' => 'function_curl_not_exists',      'msg' => '（可选）curl 未安装：采集、云端服务与在线升级不可用', 'ok' => extension_loaded('curl'), 'must' => FALSE),
        array('name' => 'gd',         'tip' => 'function_gd_not_exists',        'msg' => '（可选）gd 未安装：图片缩略功能不可用', 'ok' => extension_loaded('gd'), 'must' => FALSE),
        array('name' => 'zip',        'tip' => 'function_zip_not_exists',       'msg' => '（可选）zip 未安装：模板/插件下载安装与在线升级不可用', 'ok' => class_exists('ZipArchive'), 'must' => FALSE),
        array('name' => 'fileinfo',   'tip' => 'function_fileinfo_not_exists',  'msg' => '（可选）fileinfo 未安装：上传文件改用扩展名白名单校验', 'ok' => function_exists('finfo_open'), 'must' => FALSE),
        array('name' => 'glob',       'tip' => 'function_glob_not_exists',      'msg' => '（可选）glob 函数被禁用：部分目录扫描功能不可用（php.ini 的 disable_functions 中去掉 glob）', 'ok' => function_exists('glob'), 'must' => FALSE),
        array('name' => 'gzinflate',  'tip' => 'function_gzcompress_not_exists','msg' => '（可选）zlib 不可用：压缩相关功能不可用', 'ok' => function_exists('gzinflate'), 'must' => FALSE),
    );
}

/**
 * 安装与运行必须可写的目录（标签 => 绝对路径）。core/config/ 要写 config.php，core/ 要写 install.lock。
 * @return array
 */
function install_writable_dirs()
{
    return array(
        '../runtime/log/'   => APP_PATH . 'runtime/log/',
        '../runtime/cache/' => APP_PATH . 'runtime/cache/',
        '../upload/'        => APP_PATH . 'upload/',
        '../plugins/'       => APP_PATH . 'plugins/',
        '../template/'      => APP_PATH . 'template/',
        '../core/config/'   => APP_PATH . 'core/config/',
        '../core/'          => APP_PATH . 'core/',
    );
}

/**
 * 核心文件完整性：引导链直接引用的类文件与模板安全规则数据，缺一即全站 500 或模板全部被拒。
 * 典型诱因是主机安全软件误判隔离或上传不完整。
 * @return string[] 缺失（或为空）的相对路径
 */
function install_missing_core_files()
{
    $coreFiles = array(
        'core/app/Service/System/TemplateService.php',
        'core/app/Service/System/LexicalSafetyService.php',
        'core/app/Service/System/AddonService.php',
        'core/app/Service/System/MiddlewarePipelineService.php',
        'core/app/Service/System/TaskQueueService.php',
        'core/app/Service/System/CloudOpsService.php',
        'core/app/Service/Account/SessionService.php',
        'core/app/Service/Collection/ApiExecutionService.php',
        'core/app/Service/Front/RouteMetaService.php',
        'core/app/Service/Front/TypeService.php',
        'core/config/template_guard.json',
        // 入口引导链与模型层：index.inc.php 合并加载 core/model/*.php，任一被清空/隔离即全站 fatal
        // （用户实测：core/model/view.php 含安全注释样例被杀毒清空 ⇒ Call to undefined function _file()）
        'index.inc.php',
        'core/core.php',
        'core/func/misc.php',
        'core/func/file.php',
        'core/model/addons.php',
    );
    foreach (array('runtime', 'kv', 'config_runtime', 'user', 'session', 'cate', 'vod', 'art', 'tag', 'like',
                   'favorites', 'link', 'view', 'ad', 'api_import_batch', 'block', 'phpanalysis', 'phpanalys', 'top') as $m) {
        $coreFiles[] = 'core/model/' . $m . '.php';
    }
    $missing = array();
    foreach ($coreFiles as $cf) {
        if (!is_file(APP_PATH . $cf) || filesize(APP_PATH . $cf) <= 0) $missing[] = $cf;
    }
    // 关键函数定义抽检：文件在但内容被杀毒「清洗」（截断/挖空）时 filesize>0 也会漏判
    $mustDefine = array(
        'core/model/view.php' => array('_file', 'view_load'),
        'core/model/kv.php' => array('kv_get'),
        'core/model/runtime.php' => array('runtime_init'),
        'core/model/addons.php' => array('_include'),
    );
    foreach ($mustDefine as $cf => $fns) {
        if (in_array($cf, $missing, TRUE)) continue;
        $code = (string)@file_get_contents(APP_PATH . $cf);
        foreach ($fns as $fn) {
            if (!preg_match('#\bfunction\s+' . preg_quote($fn, '#') . '\s*\(#i', $code)) {
                $missing[] = $cf . '（内容不完整，缺 ' . $fn . '()）';
                break;
            }
        }
    }
    if (!in_array('core/config/template_guard.json', $missing, TRUE)
        && class_exists('\\App\\Service\\System\\TemplateService')
        && !\App\Service\System\TemplateService::guardAvailable()) {
        $missing[] = 'core/config/template_guard.json（内容损坏）';
    }
    return $missing;
}

/**
 * 写库前的环境前置检查（数据库步骤 GET/POST 共用，防止跳过环境检测页直达）。
 * @return string 空串=通过；否则为给站长看的错误说明
 */
function install_precheck_error()
{
    $missingExt = array();
    foreach (install_ext_list() as $e) {
        if ($e['must'] && !$e['ok']) $missingExt[] = $e['name'];
    }
    if ($missingExt) {
        return '缺少必需的 PHP 扩展：' . implode('、', $missingExt) . '。请在主机面板的 PHP 设置中安装/启用后重试（缺失会导致安装后页面 500 或空白）。';
    }
    $bad = array();
    foreach (install_writable_dirs() as $label => $dir) {
        is_dir($dir) or @mkdir($dir, 0755, TRUE);
        if (!is_dir($dir) || !xn_is_writable($dir)) $bad[] = substr($label, 3);
    }
    if ($bad) {
        return '以下目录不可写：' . implode('、', $bad) . '。请把站点目录的属主改为运行 PHP 的用户（如 www），或授予写权限后重试。';
    }
    $core = install_missing_core_files();
    if ($core) {
        return '核心文件缺失：' . implode('、', $core) . '。可能被主机安全软件隔离或上传不完整，请重新上传并加入白名单后重试。';
    }
    return '';
}

/**
 * 执行安装 SQL 文件（建表 + 初始数据），安装器专用。
 *
 * 三步文本替换后逐条 db_exec：① 统一换行（;\r\n → ;\n，随后按 ;\n 切句）；② 把 install.sql 里
 * 固定的 `niuniucms_` 前缀换成用户填写的 $tablepre；③ 把 ENGINE=MyISAM / ENGINE=InnoDB 一律改写成
 * 选定的引擎。$engine 走 myisam/innodb 白名单，非法或缺失值**回退 innodb**（产品基线是全库 InnoDB；
 * MyISAM 仍可显式选，但默认安装出的站不落在 MyISAM 上）。
 *
 * ⚠ 这里是全库少有的「直接执行拼好的 SQL」的地方（非 QueryBuilder 路径）：SQL 文本来自仓库内的
 * install.sql，注入面只在表前缀标识符上，而调用方在进入本函数前已用 _db_is_valid_ident() 校验过
 * $tablepre（仅字母数字下划线、字母/下划线开头），新代码不要照抄这个写法。
 * 任一语句失败即 message(-1, ...) 终止安装（带上 sql 片段与 $errno/$errstr 全局变量），不做续跑。
 *
 * @param string $tablepre 表前缀（须已通过标识符校验）
 * @param string $sqlfile  install.sql 的绝对路径
 * @param string $engine   目标存储引擎，白名单 myisam/innodb，其余归一为 innodb
 * @return void
 */
function install_sql_file($tablepre, $sqlfile, $engine = 'innodb')
{
    global $errno, $errstr;
    // 引擎白名单（表单 radio 值 myisam/innodb），替换 install.sql 内的 ENGINE=。
    // 修正：非法/缺失值时原回退 'myisam'（向导默认值也是 myisam），
    // 与产品基线（全库 InnoDB、事务、3072B 索引上限）矛盾；现回退 InnoDB（MyISAM 仍可显式选）。
    if (!in_array(strtolower((string)$engine), array('myisam', 'innodb'), TRUE)) {
        $engine = 'innodb';
    }
    $engine = strtoupper($engine);
    $s = file_get_contents($sqlfile);
    $s = str_replace(";\r\n", ";\n", $s);
    $s = str_replace("`niuniucms_", "`$tablepre", $s);
    $s = str_replace('ENGINE=MyISAM', 'ENGINE=' . $engine, $s);
    $s = str_replace('ENGINE=InnoDB', 'ENGINE=' . $engine, $s);
    $arr = explode(";\n", $s);
    foreach ($arr as $sql) {
        $sql = trim($sql);
        if (empty($sql)) continue;
        FALSE === db_exec($sql) AND message(-1, "sql: $sql, errno: $errno, errstr: $errstr");
    }
}

/**
 * 安装器数据库名校验：允许数字开头，但仍只接受字母/数字/下划线。
 * 口径与表前缀分开：表前缀继续走 _db_is_valid_ident()（首字符须字母/下划线），
 * 数据库名则放宽以兼容诸如 38_123_cms 这类主机商默认命名。
 *
 * @param mixed $name 用户提交的数据库名
 * @return bool
 */
function _db_is_valid_database_name($name): bool
{
    return is_string($name) && preg_match('#^[A-Za-z0-9_]+$#', $name);
}
