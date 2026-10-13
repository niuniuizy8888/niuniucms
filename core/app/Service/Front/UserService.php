<?php
namespace App\Service\Front;

class UserService
{
    /**
     * 前台会员审计留痕（上线前审计修复 VULN-L08）
     *
     * 修复前：后台写操作有 admin_audit_log 全覆盖（103 处调用），而**前台会员**的
     * 登录/登录失败/注册/改密/登出零留痕 ⇒ 针对会员账号的撞库在事件响应里完全不可见。
     * 通道名以 `_audit` 结尾，命中 LoggerService 恒写白名单（DEBUG=0 也落盘），
     * 载荷格式与 admin_audit_log 对齐（action/uid/ip/route + 附加字段），便于统一检索。
     */
    public static function memberAudit(string $action, array $data = array()): void
    {
        if ('' === $action || !function_exists('xn_log')) return;
        $payload = array(
            'action' => $action,
            'uid'    => intval(isset($_SESSION['uid']) ? $_SESSION['uid'] : 0),
            'ip'     => function_exists('ip') ? ip() : '',
            'route'  => function_exists('param') ? (string)param(0, '') : '',
        );
        foreach ($data as $k => $v) {
            if (is_scalar($v) || $v === NULL) $payload[$k] = $v;
        }
        xn_log(xn_json_encode($payload), 'member_audit');
    }

    /**
     * 装配前台登录表单视图数据（route/user.php case 'login' GET）。
     * safe_token 场景固定 user_login，与 handleLogin 的校验同键；调用方已签发则复用。
     *
     * @param array  $conf       站点配置（路由全局 $conf 传入）
     * @param string $safe_token 已签发的令牌，空则内部 bull_token_set(0,'user_login') 现签
     * @return array {header, safe_token, referer, extra, form_action}
     */
    public static function buildLoginView($conf, $safe_token = '')
    {
        empty($safe_token) and $safe_token = bull_token_set(0, 'user_login');
        return array(
            'header' => array('title' => lang('user_login')),
            'safe_token' => $safe_token,
            'referer' => \App\Service\Account\UserAuthService::httpReferer(),
            'extra' => array('safe_token' => $safe_token),
            'form_action' => url('user-login'),
        );
    }

    /**
     * 处理前台会员登录（route/user.php case 'login' POST）。
     * 全链 fail-closed：safe_token 校验（场景 user_login）→ IP + 按账户双限流 →
     * 邮箱/用户名分流取人 → 枚举防护（哑 bcrypt + 统一「密码错误」文案）→
     * password_verify_custom 统一验密（旧式 32 位 MD5 散列命中即自动迁移 bcrypt）→
     * 更新登录信息、轮换会话 id、写会话 uid、UserAuthService::tokenSet 发免登录令牌。
     * 全程 member_audit 留痕（撞库可见）。
     *
     * @param array $conf   站点配置（读 cookie_pre 组装令牌 cookie 名）
     * @param int   $longip 登录 IP 无符号整数形态，落库 login_ip
     * @return void 不返回：成功/失败均以 message() 渲染响应并终止请求
     */
    public static function handleLogin($conf, $longip)
    {
        $safe_token = param('safe_token');
        FALSE === bull_token_verify(0, $safe_token, 'user_login') and message(-1, lang('illegal_operation'));
        login_rate_check(ip()) or message(-1, lang('login_too_frequent'));

        $email = filter_all_html(param('email'));
        $password = param('password');
        empty($email) and message(-1, lang('email_is_empty'));
        // 按账户限流（防轮换 IP 绕过全局限流爆破单账户；与后台登录同口径 10/300，审计修复）
        // 审计修复（F-06）：DB email 列为 utf8mb4_general_ci（大小写不敏感），
        // 而限流键此前保留大小写——大小写轮换即可给同一账号换桶。归一为小写。
        login_rate_check('user_' . mb_strtolower($email, 'UTF-8'), 10, 300) or message(-1, lang('login_too_frequent'));

        if (is_email($email, $err)) {
            $_user = \App\Service\Account\UserService::readByEmail($email);
            // 枚举防护：与后台登录同口径——不存在账户与密码错误统一文案 + 哑 bcrypt 拉平时序
            if (empty($_user)) {
                password_verify($password, '$2y$12$qjmeqFrQYlWr7xeVfBY7ZOXeHzhCyOA7I6beMFOxnbLzDlM9QtdMe');
                self::memberAudit('login_failed', array('account' => $email, 'reason' => 'no_such_account'));
                message(-1, lang('password_incorrect'));
            }
        } else {
            $_user = \App\Service\Account\UserService::readByUsername($email);
            if (empty($_user)) {
                password_verify($password, '$2y$12$qjmeqFrQYlWr7xeVfBY7ZOXeHzhCyOA7I6beMFOxnbLzDlM9QtdMe');
                self::memberAudit('login_failed', array('account' => $email, 'reason' => 'no_such_account'));
                message(-1, lang('password_incorrect'));
            }
        }

        $stored_hash = $_user['password'];
        $stored_salt = $_user['salt'];
        // 统一验密（bcrypt + MD5 两种历史格式，与后台登录同口径）
        $check = password_verify_custom($password, $stored_hash, $stored_salt);
        if ($check && strlen($stored_hash) === 32 && ctype_xdigit($stored_hash)) {
            $new_hash = password_hash($password, PASSWORD_BCRYPT);
            \App\Service\Account\UserService::update($_user['uid'], array('password' => $new_hash, 'salt' => ''));
        }
        if (empty($check)) {
            self::memberAudit('login_failed', array('account' => $email, 'reason' => 'bad_password'));
            message(-1, lang('password_incorrect'));
        }

        $update = array('login_ip' => $longip, 'login_date' => time(), 'logins+' => 1);
        \App\Service\Account\UserService::update($_user['uid'], $update);
        session_regenerate_id(true);
        $_SESSION['uid'] = $_user['uid'];
        $token = \App\Service\Account\UserAuthService::tokenSet($_user['uid']);
        $_user = \App\Service\Account\UserService::safeInfo($_user);
        self::memberAudit('login_success', array('account' => $email));
        $extra = array('user' => $_user, 'token_key' => $conf['cookie_pre'] . 'token', 'token' => $token);
        message(0, lang('login_successfully'), $extra);
    }

    /**
     * 装配前台注册表单视图数据（route/user.php case 'create' GET）。
     * safe_token 场景必须为 user_create，与 handleCreate 校验同键，否则注册恒「非法操作」。
     *
     * @param array $conf 站点配置（当前实现未消费，保留与其余 build* 一致的签名）
     * @return array {header, referer, safe_token, extra, form_action}
     */
    public static function buildCreateView($conf)
    {
        // safe_key 必须与 handleCreate 的 bull_token_verify(...,'user_create') 一致，
        // 否则会话键失配导致注册永远「非法操作」（上线前审计修复）
        $safe_token = bull_token_set(0, 'user_create');
        return array(
            'header' => array('title' => lang('create_user')),
            'referer' => \App\Service\Account\UserAuthService::httpReferer(),
            'safe_token' => $safe_token,
            'extra' => array('safe_token' => $safe_token),
            'form_action' => url('user-create'),
        );
    }

    /**
     * 处理前台会员注册（route/user.php case 'create' POST）。
     * 全链 fail-closed：注册限流（读 throttle.php 的 register_max/window）→ safe_token（场景 user_create）→
     * 邮箱/用户名/密码非空校验 → 可选邮件验证码（开关 user_create_email_on，严格 !== 比较，F-21）→
     * is_email/is_username/is_password 格式校验 + 邮箱/用户名唯一性 → bcrypt 落库（gid=2 普通会员）→
     * 写会话 uid、发免登录令牌、清注册验证码会话键。全程 member_audit 留痕。
     *
     * @param array $conf   站点配置（读 user_create_email_on / cookie_pre）
     * @param int   $longip 注册 IP 无符号整数，落库 create_ip/login_ip
     * @param int   $time   请求时间戳，落库 create_date/login_date
     * @return void 不返回：成功/失败均以 message() 终止请求
     */
    public static function handleCreate($conf, $longip, $time)
    {
        // 注册限流：从配置读取，防批量注册
        $throttleFile = APP_PATH . 'core/config/throttle.php';
        $throttle = is_file($throttleFile) ? include $throttleFile : array();
        $regMax = is_array($throttle) ? intval($throttle['register_max'] ?? 10) : 10;
        $regWindow = is_array($throttle) ? intval($throttle['register_window'] ?? 3600) : 3600;
        if (!\App\Service\System\ThrottleService::check('register', ip(), $regMax, $regWindow)) {
            message(-1, lang('login_too_frequent'));
        }
        $safe_token = param('safe_token');
        FALSE === bull_token_verify(0, $safe_token, 'user_create') and message(-1, lang('illegal_operation'));
        $email = filter_all_html(param('email'));
        $username = filter_all_html(param('username'));
        $password = param('password');
        $code = param('code');
        empty($email) and message('email', lang('please_input_email'));
        empty($username) and message('username', lang('please_input_username'));
        empty($password) and message('password', lang('please_input_password'));

        if ($conf['user_create_email_on']) {
            $sess_email = _SESSION('user_create_email');
            $sess_code = _SESSION('user_create_code');
            empty($sess_code) and message('code', lang('click_to_get_verify_code'));
            empty($sess_email) and message('code', lang('click_to_get_verify_code'));
            // 上线前安全审计修复（F-21）：严格比较——松散 == 下纯数字验证码存在
            // "047562"=="47562e0" 类数值等价绕过面（该特性当前为休眠死代码，加固防未来启用）。
            $email !== $sess_email and message('code', lang('verify_code_incorrect'));
            $code !== $sess_code and message('code', lang('verify_code_incorrect'));
        }

        is_email($email, $err) || message('email', $err);
        $_user = \App\Service\Account\UserService::readByEmail($email);
        $_user and message('email', lang('email_is_in_use'));
        is_username($username, $err) || message('username', $err);
        $_user = \App\Service\Account\UserService::readByUsername($username);
        $_user and message('username', lang('username_is_in_use'));
        is_password($password, $err) || message('password', $err);

        $_user = array(
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'salt' => '',
            'gid' => 2,
            'create_ip' => $longip,
            'create_date' => $time,
            'logins' => 1,
            'login_date' => $time,
            'login_ip' => $longip,
        );
        $uid = \App\Service\Account\UserService::create($_user);
        if (FALSE === $uid) {
            // 审查修复轮 C11：并发同 email/username 双注册撞唯一键与真写失败在此都是 FALSE，
            // 预查挡不住竞态——留痕账号串以便区分定位（email/username 经 param() 已转义）。
            xn_log('register create failed: email=' . $email . ' username=' . $username, 'register_error');
            message(-1, lang('user_create_failed'));
        }

        $user = \App\Service\Account\UserService::read($uid);
        unset($_SESSION['user_create_email'], $_SESSION['user_create_code']);
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        $token = \App\Service\Account\UserAuthService::tokenSet($uid);
        $user = \App\Service\Account\UserService::safeInfo($user);
        self::memberAudit('register_success', array('account' => isset($user['email']) ? $user['email'] : '', 'new_uid' => $uid));
        $extra = array('user' => $user, 'token_key' => $conf['cookie_pre'] . 'token', 'token' => $token);
        message(0, lang('user_create_successfully'), $extra);
    }

    /**
     * 处理前台会员登出（route/user.php case 'logout'）。
     * 经 user_bull_token_guard（场景 user_logout，校验不过即终止）后：会话 uid 归 0、清免登录令牌，
     * 并主动吊销同终端的后台 admin_token cookie（前台不加载 admin.func.php，故内联同款 cookie 参数清除，
     * 否则滑动续期窗口内可免密重进后台）；轮换会话 id 后 message() 终止。
     *
     * @param int $uid 当前会话 uid（路由全局 $uid=array_value($user,'uid',0)；令牌校验主体 + 审计字段）
     * @return void 不返回
     */
    public static function handleLogout($uid)
    {
        $safe_token = param('safe_token');
        user_bull_token_guard($uid, 'user_logout', $safe_token);

        self::memberAudit('logout', array('uid' => $uid));
        $_SESSION['uid'] = 0;
        \App\Service\Account\UserAuthService::tokenClear();
        // 安全修复：前台登出须同时吊销后台 admin_token（否则共享终端「登出」后
        // 滑动续期窗口内仍可免密重进后台框架；前台不加载 admin.func.php，内联同款参数清除）
        $conf = _SERVER('conf');
        if (isset($_COOKIE[$conf['cookie_pre'] . 'admin_token'])) {
            $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) != 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            setcookie($conf['cookie_pre'] . 'admin_token', '', array(
                'expires' => time() - 86400,
                'path' => $conf['cookie_path'],
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
            unset($_COOKIE[$conf['cookie_pre'] . 'admin_token']);
        }
        session_regenerate_id(true);
        message(0, jump(lang('logout_successfully'), url('/'), 1));
    }

    /**
     * 装配前台改密表单视图数据（route/user.php case 'passwd' GET）。
     *
     * @param int $uid 当前登录会员 uid（令牌签发主体，场景 user_passwd）
     * @return array {safe_token}（bull_token_set 结果，签发失败可能为 FALSE）
     */
    public static function buildPasswdView($uid)
    {
        return array(
            'safe_token' => bull_token_set($uid, 'user_passwd'),
        );
    }

    /**
     * 处理前台会员改密（route/user.php case 'passwd' POST）。
     * 全链 fail-closed：user_passwd 场景令牌 → 新密码非空 + is_password 强度 + 二次确认一致 →
     * password_verify_custom 验旧密 → bcrypt 更新（salt 置空）。
     * 成功后敏感处理两项：memberAudit('password_changed') 留痕（VULN-L08）；
     * 轮换会话 id（VULN-005）——旧 sid 作废，且因 safe_token 指纹环与免登录令牌签名均含密码哈希，
     * 全部历史令牌随新哈希生效一并失效。
     *
     * @param int   $uid  当前登录会员 uid
     * @param array $user 当前用户整行（读 password/salt 验旧密）
     * @return void 不返回：以 message() 终止请求
     */
    public static function handlePasswd($uid, $user)
    {
        $safe_token = param('safe_token');
        user_bull_token_guard($uid, 'user_passwd', $safe_token);
        $password_old = param('password_old');
        $password_new = param('password_new');
        $password_new_repeat = param('password_new_repeat');
        // 修复：空/弱新密码会导致 password_hash('') 入库后被 password_verify_custom 空值拒绝，账号永久锁死
        $password_new === '' and message('password', lang('please_input_password'));
        is_password($password_new, $err) or message('password', $err);
        $password_new_repeat != $password_new and message(-1, lang('repeat_password_incorrect'));
        password_verify_custom($password_old, $user['password'], (string)($user['salt'] ?? '')) or message(-1, lang('old_password_incorrect'));
        $password_new_hash = password_hash($password_new, PASSWORD_BCRYPT);
        FALSE === \App\Service\Account\UserService::update($uid, array('password' => $password_new_hash, 'salt' => '')) and message(-1, lang('password_modify_failed'));
        // 上线前审计修复（VULN-L08）：改密是敏感凭据事件，必须留痕（原零审计）
        self::memberAudit('password_changed', array('uid' => $uid));
        // 上线前安全审计修复（VULN-005）：改密成功后轮换会话 ID。
        // 旧 sid 即刻作废；历史会话里的 bull_token 指纹因绑定密码哈希（md5(password)）
        // 在新哈希生效后一并失效，remember-me 亦因令牌签名含密码哈希而作废。
        session_regenerate_id(true);
        message(0, lang('password_modify_successfully'));
    }
}
