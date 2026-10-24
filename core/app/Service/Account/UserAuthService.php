<?php
namespace App\Service\Account;

class UserAuthService
{
    /**
     * 当前登录用户读取入口：会话 uid 为空时尝试免登录 cookie token 续登（成功则回写 $_SESSION['uid']）。
     * @return array 用户整行（UserService::read，含 1h 缓存）；未登录/续登失败返回空数组（guest 判定看空）
     */
    public static function rest()
    {
        $uid = intval(_SESSION('uid'));
        if (empty($uid)) {
            $fromToken = self::tokenGet();
            if (!empty($fromToken)) {
                $_SESSION['uid'] = $fromToken;
                // 上线前审计 AUTH-01：remember-me 提升登录态时轮换会话 ID，打断会话固定
                if (PHP_SESSION_ACTIVE === session_status()) {
                    @session_regenerate_id(true);
                }
                $uid = intval($fromToken);
            }
        }
        return user_read($uid);
    }

    /**
     * 免登录 cookie token 续登（对外口径）：tokenGetDo 校验失败时顺手 tokenClear() 清 cookie（止血脏/失效令牌）。
     * @return mixed 成功 = token 内的 uid（explode 出的字符串，调用方按 int 消费）；失败 = FALSE
     */
    public static function tokenGet()
    {
        $_uid = self::tokenGetDo();
        empty($_uid) and self::tokenClear();
        return $_uid;
    }

    /**
     * 免登录 token 解密 + 五元指纹校验（纯校验，不清 cookie；对外请用 tokenGet）。
     * 令牌形态：xn_decrypt(cookie[pre.'token'], key=md5(xn_key())) → "ip\ttime\tuid\tmd5(password)\tmd5(UA)"
     * （AES-256-GCM，见 tokenGen；旧空格分隔存量 token 有兼容重切分支）。校验链全部 fail-closed，任一不过即 FALSE：
     * ① 30 天硬时效（F-04：time 缺失/未来时间/超龄均拒，Cookie Max-Age 不再是唯一约束）；
     * ② conf.login_ip 开启时 IP 必须一致；③ conf.login_ua 开启时 UA 经 hash_equals 恒时比较（S-2）；
     * ④ 用户必须存在；⑤ conf.login_only 开启时要求 login_date==token.time（单设备登录）；
     * ⑥ md5(用户当前密码散列) 与 token 内指纹 hash_equals——改密即吊销全部旧 token。
     * @return mixed 通过 = uid（token 第 3 段的字符串形态）；任一环节不过 = FALSE
     */
    public static function tokenGetDo()
    {
        $conf = _SERVER('conf');         $ip = _SERVER('ip');         $useragent = _SERVER('useragent');         $time = _SERVER('time');
        $token = _COOKIE($conf['cookie_pre'] . 'token');
        if (empty($token)) return FALSE;
        $tokenkey = md5(xn_key());
        $s = xn_decrypt($token, $tokenkey);
        if (empty($s)) return FALSE;
        $arr = explode("\t", $s);
        if (count($arr) != 5) {
            // 兼容旧版空格分隔的存量 token（tokenGen 曾用空格拼接）
            $arr = preg_split('/\s+/', trim($s));
        }
        if (count($arr) != 5) return FALSE;
        list($_ip, $_time, $_uid, $_pwd, $ua_md5) = $arr;
        // 上线前安全审计修复（F-04）：服务端硬时效——此前解密出的 $_time 从未参与比较，
        // Cookie Max-Age 30 天只在浏览器侧生效，被窃取的令牌值可无限期重放（改密是唯一止血手段）。
        // 与 tokenSet 的 30 天（2592000s）对齐：正常用户无感；未来时间戳（时钟回拨/伪造）同样拒绝。
        if (!ctype_digit((string)$_time) || $_time > $time || $time - $_time > 2592000) return FALSE;
        if (array_value($conf, 'login_ip') && $ip != $_ip) return FALSE;
        if (array_value($conf, 'login_ua') && !hash_equals(md5((string)$useragent), (string)$ua_md5)) return FALSE; // S-2：恒时比较（禁松散 !=）
        $_user = user_read($_uid);
        if (empty($_user)) return FALSE;
        if (array_value($conf, 'login_only') && $_user['login_date'] != $_time) return FALSE;
        if (!hash_equals(md5((string)$_user['password']), (string)$_pwd)) return FALSE; // S-2：恒时比较
        return $_uid;
    }

    /**
     * 签发免登录 token 并落 Cookie（30 天 = 2592000s，与 tokenGetDo 的硬时效同值；
     * httponly + SameSite=Lax + HTTPS 自动 secure，§二.9 Cookie 基线，与后台 admin_cookie_opt 同款）。
     * ⚠ S-5：tokenGen 底层 xn_encrypt 加密失败会抛 RuntimeException（fail-closed，不降级）。
     * @param mixed $uid 用户 ID（空值短路：不签发、不发 Cookie）
     * @return string 签发出的 token 密文；$uid 为空返回空串
     */
    public static function tokenSet($uid)
    {
        $conf = _SERVER('conf');         $time = _SERVER('time');
        if (empty($uid)) return '';
        $token = self::tokenGen($uid);
        $https = (!empty(_SERVER('HTTPS')) && strtolower(_SERVER('HTTPS')) != 'off') || (NULL !== _SERVER('HTTP_X_FORWARDED_PROTO') && _SERVER('HTTP_X_FORWARDED_PROTO') === 'https');
        $opt = array(
            'expires' => $time + 2592000,
            'path' => $conf['cookie_path'],
            'secure' => $https,
            'httponly' => TRUE,
            'samesite' => 'Lax',
        );
        if (!empty($conf['cookie_domain'])) $opt['domain'] = $conf['cookie_domain'];
        setcookie($conf['cookie_pre'] . 'token', $token, $opt);
        return $token;
    }

    /**
     * 吊销免登录 cookie：同名同 path/domain 写空值 + expires 置过去（-100 天）使浏览器删除。
     * 与 tokenSet 的选项口径一致（httponly/SameSite=Lax/HTTPS secure），否则属性不匹配删不掉。
     * @return void
     */
    public static function tokenClear()
    {
        $conf = _SERVER('conf');         $time = _SERVER('time');
        $https = (!empty(_SERVER('HTTPS')) && strtolower(_SERVER('HTTPS')) != 'off') || (NULL !== _SERVER('HTTP_X_FORWARDED_PROTO') && _SERVER('HTTP_X_FORWARDED_PROTO') === 'https');
        $opt = array(
            'expires' => $time - 8640000,
            'path' => $conf['cookie_path'],
            'secure' => $https,
            'httponly' => TRUE,
            'samesite' => 'Lax',
        );
        if (!empty($conf['cookie_domain'])) $opt['domain'] = $conf['cookie_domain'];
        setcookie($conf['cookie_pre'] . 'token', '', $opt);
    }

    /**
     * 组装免登录 token 明文并加密："ip \t time \t uid \t md5(用户密码散列) \t md5(UA)"
     * → xn_encrypt(AES-256-GCM，密钥 md5(xn_key()))（S-5：加密失败抛异常，不降级 XXTEA）。
     * 密码只以 md5 指纹入 token（改密即令旧 token 全部失效），不落原文。
     * 请求级 static 记忆化：同一 uid 同请求内返回同一 token（time/IP 一致，防重复签发漂移）。
     * @param mixed $uid 用户 ID；对不存在的 uid 调用会触发 undefined array key 告警且指纹坍缩为 md5('')——调用方应保证 uid 有效
     * @return string token 密文（base64）
     */
    public static function tokenGen($uid)
    {
        $time = _SERVER('time');         $ip = _SERVER('ip');         $useragent = _SERVER('useragent');
        $key = 'user_token' . $uid;
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        $user = user_read($uid);
        $pwd = md5($user['password']);
        $ua_md5 = md5($useragent);
        $tokenkey = md5(xn_key());
        $cache[$key] = xn_encrypt("$ip\t$time\t$uid\t$pwd\t$ua_md5", $tokenkey);
        return $cache[$key];
    }

    /**
     * 前台登录门禁（member.php 全局守卫，route/user 系复用）：global $user 为空（未登录）
     * 即 302 跳登录页并 exit——无返回值，通过则静默放行。
     * @return void
     */
    public static function loginCheck()
    {
        global $user;
        if (empty($user)) {
            http_location(url('user-login'));
            exit;
        }
    }

    /**
     * 登录后回跳地址清洗：param('referer')（已 htmlspecialchars）优先，空则回落 HTTP_REFERER；
     * 再剥引号/尖括号/空白等字符后只放行**同站**地址（安全审查 L-1：站内相对路径 `/xxx`，
     * 或 http(s) 且主机==本站 HTTP_HOST 的绝对地址；`//evil`、`/\evil`、外站主机一律拒，见 refererIsSameSite），
     * 并显式排除登录/登出/注册/改密等站内环节点（防回跳成环）；任一不过 → 回退站点根 $conf['path']。
     * @return string 安全回跳 URL（永不返回用户原样输入）
     */
    public static function httpReferer()
    {
        $conf = _SERVER('conf');
        $referer = param('referer');
        empty($referer) and $referer = array_value($_SERVER, 'HTTP_REFERER', '');
        $referer = str_replace(array('\"', '"', '<', '>', ' ', '*', "\t", "\r", "\n"), '', (string)$referer);
        if (
            !self::refererIsSameSite($referer)
            || FALSE !== strpos($referer, url('user-login'))
            || FALSE !== strpos($referer, url('user-logout'))
            || FALSE !== strpos($referer, url('user-create'))
            || FALSE !== strpos($referer, url('user-setpw'))
            || FALSE !== strpos($referer, url('user-resetpw_complete'))
        ) {
            $referer = $conf['path'];
        }
        return $referer;
    }

    /**
     * 回跳地址同站判定（安全审查 L-1，2026-10-24；旧实现只要 http(s)://+词符即放行 ⇒ 开放重定向）。
     * 放行：① 以单个 `/` 开头的站内相对路径（排除协议相对 `//host` 与 `/\host`）；
     *       ② http(s) 绝对地址且 host[:port] 与本次请求 HTTP_HOST 完全一致（大小写不敏感）。
     * 路径字符沿用旧白名单 [\w\-=/.%#?]。
     */
    public static function refererIsSameSite($referer): bool
    {
        $referer = (string)$referer;
        if ('' === $referer) return FALSE;
        if (preg_match('#^/(?![/\\\\])[\w\-=/.%\#?]*$#', $referer)) return TRUE;
        if (!preg_match('#^https?://([\w\-.]+(?::\d{1,5})?)(?:/[\w\-=/.%\#?]*)?$#i', $referer, $m)) return FALSE;
        $host = strtolower((string)array_value($_SERVER, 'HTTP_HOST', ''));
        return '' !== $host && strtolower($m[1]) === $host;
    }

    /**
     * 找回密码 auth 链接校验（前台 user-resetpw 流程）：优先使用传入形参 `$token`，
     * 为空时兼容回落 `param(2)`；xn_decrypt（默认 auth_key）得 "ip-time-uid-md5(password)" 四段。
     * 校验全部 fail-closed，任一不过即 message(-1) 渲染提示页终止：解密失败 / 段数≠4 / 用户不存在 /
     * hash_equals(md5(当前密码), token 内指纹)（改密即旧链接失效，S-2 恒时）/ 超 3600s 时效。
     * ⚠ 解密出的 ip 段仅解析未参与比较。
     * @param mixed $token 可显式传入 auth 串；为空时兼容回落 param(2)
     * @return array 校验通过的用户整行
     */
    public static function authCheck($token)
    {
        $time = _SERVER('time');
        $auth = is_string($token) && '' !== $token ? $token : (string)param(2);
        $s = xn_decrypt($auth);
        empty($s) and message(-1, lang('decrypt_failed'));
        $arr = explode('-', $s);
        count($arr) != 4 and message(-1, lang('encrypt_failed'));
        list($_ip, $_time, $_uid, $_pwd) = $arr;
        $_user = user_read($_uid);
        empty($_user) and message(-1, lang('user_not_exists'));
        // 校验密码哈希：修改密码后旧 auth 链接立即失效
        hash_equals(md5((string)$_user['password']), (string)$_pwd) or message(-1, lang('illegal_operation')); // S-2：恒时比较
        $time - $_time > 3600 and message(-1, lang('link_has_expired'));
        return $_user;
    }
}
