<?php
// PHP 版本下限：唯一定义点，闸门与环境检查页都用它，避免两处漂移。
// 2026-10 兼容收口：
// - 站点代码已补 `str_contains()/str_starts_with()/str_ends_with()` polyfill；
// - `#[\ReturnTypeWillChange]` 在 PHP 7.4 中按注释处理，不构成语法错误；
// - 已用 PHP 7.4、8.3、8.5 对全仓做过 lint 回归。
// 结论：最低支持版本调整为 PHP 7.4，推荐 8.2+。
define('NNCMS_PHP_MIN', '7.4');
define('NNCMS_PHP_RECOMMEND', '8.2');
if (version_compare(PHP_VERSION, NNCMS_PHP_MIN, '<')) {
    die('牛牛CMS 需要 PHP ' . NNCMS_PHP_MIN . ' 或更高版本（推荐 ' . NNCMS_PHP_RECOMMEND . '+）。当前版本：' . PHP_VERSION);
}
// APP_PATH/INSTALL_PATH 由根目录 install.php 预定义；直接访问本文件时兜底（nginx 已 deny core/）
!defined('APP_PATH') AND define('APP_PATH', realpath(__DIR__ . '/../../') . '/');
!defined('INSTALL_PATH') AND define('INSTALL_PATH', __DIR__ . '/');
// 审计修复（F-04）：install.php 是公开入口。此前无条件 define('DEBUG', 2)，
// 使 core/core.php 打开 display_errors + error_reporting(E_ALL)；已安装站点访问 /install.php
// 时整条引导链在开调试态下执行（失败即向访客泄漏绝对路径/内部信息）。已安装（install.lock 或
// config.php 在位）时用生产态 DEBUG=0，安装向导阶段才保留调试态。
define('DEBUG', (is_file(APP_PATH . 'core/install.lock') || is_file(APP_PATH . 'core/config/config.php')) ? 0 : 2);
define('MESSAGE_HTM_PATH', INSTALL_PATH . 'html/message.html');
$conf = (include APP_PATH . 'core/config/config.default.php');
$conf['log_path'] = APP_PATH .'runtime/log/';
$conf['cache_path'] = APP_PATH . 'runtime/cache/';
include APP_PATH . 'core/core.php';
include APP_PATH . 'core/model/addons.php';
include APP_PATH . 'core/model/user.php';
$lang=include APP_PATH . 'core/lang/zh_cn.php';
$_SERVER['lang'] = $lang;
is_file(APP_PATH . 'core/install.lock') AND message(0, jump($lang['already_installed'], '../'));
is_file(APP_PATH . 'core/config/config.php') AND message(0, jump($lang['installed_tips'], '../'));
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
        copy(APP_PATH . 'core/config/config.default.php', APP_PATH . 'core/config/config.php');
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
        file_replace_var(APP_PATH . 'core/config/config.php', $replace); 
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
        file_put_contents(APP_PATH . 'core/install.lock',  'install_time:'.date('Y-m-d H:i:s'));
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
        file_replace_var(APP_PATH . 'core/config/config.php', $replace);
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
 *  · $funclist：扩展/函数依赖清单 [{name,tip,ok,must}]，pdo_mysql 是唯一 must=TRUE 的硬依赖，
 *    缺失时 chenk.html 置 $succeed=0 阻断下一步；其余（curl/pdo/openssl/gd/glob/gzinflate/mb_substr）
 *    只提示不阻断。
 *  · $write：可写目录检测（runtime/log、runtime/cache、upload、plugins、template 五项）。目录不存在时
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
    // 函数/扩展依赖检查（迁移：原扩展检查从运行环境区移至此，
    // 与既有 curl/pdo/pdo_mysql/glob/gzinflate/mb_substr 硬编码行合并为数组驱动；
    // must=TRUE 缺失时阻断安装（succeed=0），其余缺失仅提示不阻断）
    $funclist = array(
        array('name' => 'curl',       'tip' => 'function_curl_not_exists',     'ok' => extension_loaded('curl'),       'must' => FALSE),
        array('name' => 'pdo',        'tip' => 'function_pdo_not_exists',      'ok' => extension_loaded('pdo'),        'must' => FALSE),
        array('name' => 'pdo_mysql',  'tip' => 'function_pdo_mysql_not_exists','ok' => extension_loaded('pdo_mysql'),  'must' => TRUE),
        array('name' => 'openssl',    'tip' => 'function_openssl_not_exists',  'ok' => extension_loaded('openssl'),    'must' => FALSE),
        array('name' => 'gd',         'tip' => 'function_gd_not_exists',       'ok' => extension_loaded('gd'),         'must' => FALSE),
        array('name' => 'glob',       'tip' => 'function_glob_not_exists',     'ok' => function_exists('glob'),        'must' => FALSE),
        array('name' => 'gzinflate',  'tip' => 'function_gzcompress_not_exists','ok' => function_exists('gzinflate'),   'must' => FALSE),
        array('name' => 'mb_substr',  'tip' => 'function_mb_substr_not_exists','ok' => function_exists('mb_substr'),    'must' => FALSE),
    );
    $writedir = array(
        '../runtime/log/'   => APP_PATH . 'runtime/log/',
        '../runtime/cache/' => APP_PATH . 'runtime/cache/',
        '../upload/'        => APP_PATH . 'upload/',
        '../plugins/'        => APP_PATH . 'plugins/',
        '../template/'      => APP_PATH . 'template/',
    );
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
