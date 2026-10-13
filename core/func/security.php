<?php
/**
 * Security 函数
 * 从 misc.php 拆分
 */

// ============================================================================
// [SECURITY] 安全函数（密码/Token/XSS 过滤）
// ============================================================================

/**
 * 生成口令散列（红线 §二.9 唯一允许的口令原语）：直接透传 PHP password_hash() 的 bcrypt 分支，
 * 随机 salt 由算法内部生成并编进结果串，落库形态是 60 位的 $2y$ 串。
 * 本函数**只做「明文 → 散列」**，不做强度校验（形态校验见 validate.php 的口令检查）、
 * 都发生在验证侧 password_verify_custom() 及其调用方（前台 UserService、后台
 * UserManageService 的 userPayload 分支会把 salt 一并置空）；新写库路径一律用本函数，
 * 禁止再出现 md5/sha1 作密码存储原语。
 * 注意 bcrypt 只消费口令前 72 字节（PHP 原生行为，本层未做裁剪）；空串也会得到一个
 * 合法散列，因此调用方必须先拒绝空/弱口令，否则账号会被 password_verify_custom 的空值
 * 守卫永久锁死（改密流程已按此修过一处）。
 * @param string $password 口令明文，未经任何预散列（取证：调用点 UserManageService::userPayload()
 *                         的散列分支、安装器 core/install/index.php 的 `$adminpass` 装配段，
 *                         传的都是表单原文——此处用符号锚点不写行号，避免代码增删后漂移）
 * @return string 60 位 bcrypt 散列；底层 password_hash() 失败时返回 FALSE（PHP 文档语义，
 *                 正常入参下不会发生，调用点当前未判 FALSE）
 */
function password_hash_custom($password)
{
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * 验证密码，兼容 MD5+salt 旧格式（全站唯一验密实现：登录/改密/兼容迁移共用）
 * bcrypt（$2y$/$2a$ 60 位）→ password_verify；旧式 MD5 兼容 md5(md5(pw).salt) 与 md5(pw.salt) 两种历史格式
 * @param string $password 用户输入的明文密码
 * @param string $hash 数据库中存储的哈希
 * @param string $salt 旧版 MD5 的 salt（仅当 hash 是 MD5 格式时需要）
 * @return bool
 */
function password_verify_custom($password, $hash, $salt = '')
{
    $password = (string)$password;
    $hash = (string)$hash;
    if ($hash === '' || $password === '') return false;
    if (strlen($hash) === 60 && (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$'))) {
        return password_verify($password, $hash);
    }
    // 兼容旧版 MD5(md5($password) . $salt) 与 MD5($password . $salt) 两种历史格式（32位 hex）
    // 项目审查修复：凭据比较一律恒时（红线「认证与加密」）——原 `===` 是短路逐字节比较。
    if (strlen($hash) === 32 && ctype_xdigit($hash)) {
        return hash_equals($hash, md5(md5($password) . (string)$salt))
            || hash_equals($hash, md5($password . (string)$salt));
    }
    return false;
}

/**
 * 登录频率限制检查
 * @param string $key 限流标识（IP或用户名）
 * @param int $max_attempts 最大尝试次数
 * @param int $window 时间窗口（秒）
 * @return bool true=允许通过, false=已被限流
 */
function login_rate_check(string $key, int $max_attempts = 5, int $window = 300): bool
{
    return \App\Service\System\ThrottleService::check('login', $key, $max_attempts, $window);
}

/**
 * 富文本白名单过滤（红线 §二.4 入库层，所有可存 HTML 的字段必须过此闸）：
 * 标签/属性/CSS 三重白名单（HTML_White 内联库，署名见 .skills/开发规范.md 末节「第三方代码与署名」）——协议判定走 PCRE，
 * href 仅 http(s)/ed2k 系，src 仅 http(s) 或 data:image/{jpg,gif,jpeg,png};base64（svg 不在内）。
 * ⚠ conf['img_base64']==1 的覆盖分支与默认 img_url 正则逐字节相同，实测是 no-op（base64 图
 * 恒放行，与键值无关）——此处仅如实记录，勿据此键推断行为；且本函数读**物理 config.php**
 * 而非 KV 运行时层。script/style 标签及其内容整体删除，table 宽度默认钳 746。
 * @param string $doc 原始富文本
 * @param array $arg 覆盖参数（table_max_width 等，透传 HTML_White）
 * @return string 过滤后的安全 XHTML（白名单外标签被剥或转义）
 */
function xn_html_safe($doc, $arg = array())
{
    $conf = include APP_PATH . 'core/config/config.php';
    empty($arg['table_max_width']) and $arg['table_max_width'] = 746; // 这个宽度为 回帖宽度
    $pattern = array(
        //'img_url'=>'#^(https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*$#is',
        'img_url' => '#^(((https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*)|(data:image/jpg;base64,[\w/+=\/+]+)|(data:image/gif;base64,[\w/+=\/+]+)|(data:image/jpeg;base64,[\w/+=\/+]+)|(data:image/png;base64,[\w/+=\/+]+))$#is',
        'url' => '#^(https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*$#is', // '#https?://[\w\-/%?.=]+#is'
        'mailto' => '#^mailto:([\w%\-\.]+)@([\w%\-\.]+)(\.[\w%\-\.]+?)+$#is',
        'ftp_url' => '#^ftp:([\w%\-\.]+)@([\w%\-\.]+)(\.[\w%\-\.]+?)+$#is',
        'ed2k_url' => '#^(?:ed2k|thunder|qvod|magnet)://[^\s\'\"\\\\<>]+$#is',
        'color' => '#^(\#\w{3,6})|(rgb\(\d+,\s*\d+,\s*\d+\)|(\w{3,10}))$#is',
        'safe' => '#^[\w\-:;\.\s\x7f-\xff]+$#is',
        'css' => '#^[\(,\)\#;\w\-\.\s\x7f-\xff]+$#is',
        'word' => '#^[\w\-\x7f-\xff]+$#is',
    );
    if (1 == array_value($conf, 'img_base64')) $pattern['img_url'] = '#^(((https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*)|(data:image/jpg;base64,[\w/+=\/+]+)|(data:image/gif;base64,[\w/+=\/+]+)|(data:image/jpeg;base64,[\w/+=\/+]+)|(data:image/png;base64,[\w/+=\/+]+))$#is';
    $white_tag = array(
        'a',
        'b',
        'i',
        'u',
        'font',
        'strong',
        'em',
        'span',
        'table',
        'tr',
        'td',
        'th',
        'tbody',
        'thead',
        'tfoot',
        'caption',
        'ol',
        'ul',
        'li',
        'dl',
        'dt',
        'dd',
        'menu',
        'multicol',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'hr',
        'p',
        'div',
        'pre',
        'br',
        'img',
        'area',
        'embed',
        'code',
        'blockquote',
        'iframe',
        'section',
        'fieldset',
        'legend'
    );
    $white_value = array(
        'href' => array('pcre', '', array($pattern['url'], $pattern['ed2k_url'])),
        'src' => array('pcre', '', array($pattern['img_url'])),
        'width' => array('range', '', array(0, 4096)),
        'height' => array('range', 'auto', array(0, 80000)),
        'size' => array('range', 4, array(-10, 10)),
        'border' => array('range', 0, array(0, 10)),
        'family' => array('pcre', '', array($pattern['word'])),
        'class' => array('pcre', '', array($pattern['safe'])),
        'face' => array('pcre', '', array($pattern['word'])),
        'color' => array('pcre', '', array($pattern['color'])),
        'alt' => array('pcre', '', array($pattern['safe'])),
        'label' => array('pcre', '', array($pattern['safe'])),
        'title' => array('pcre', '', array($pattern['safe'])),
        'target' => array('list', '_self', array('_blank', '_self')),
        'type' => array('pcre', '', array('#^[\w/\-]+$#')),
        'wmode' => array('list', 'transparent', array('transparent', '')),
        'allowscriptaccess' => array('list', 'never', array('never')),
        'value' => array('list', '', array('#^[\w+/\-]$#')),
        'cellspacing' => array('range', 0, array(0, 10)),
        'cellpadding' => array('range', 0, array(0, 10)),
        'frameborder' => array('range', 0, array(0, 10)),
        'allowfullscreen' => array('list', 'true', array('true', '1', 'on'), 'range', 0, array(0, 10)),
        'align' => array('list', 'left', array('left', 'center', 'right')),
        'valign' => array('list', 'middle', array('middle', 'top', 'bottom')),
        'name' => array('pcre', '', array($pattern['word'])),
    );
    $white_css = array(
        'font' => array('pcre', 'none', array($pattern['safe'])),
        'font-style' => array('pcre', 'none', array($pattern['safe'])),
        'font-weight' => array('pcre', 'none', array($pattern['safe'])),
        'font-family' => array('pcre', 'none', array($pattern['word'])),
        'font-size' => array('range', 12, array(6, 48)),
        'width' => array('range', '100%', array(1, 1800)),
        'height' => array('range', '', array(1, 80000)),
        'min-width' => array('range', 1, array(1, 80000)),
        'min-height' => array('range', 400, array(1, 80000)),
        'max-width' => array('range', 1800, array(1, 80000)),
        'max-height' => array('range', 80000, array(1, 80000)),
        'line-height' => array('range', '14px', array(1, 50)),
        'color' => array('pcre', '#000000', array($pattern['color'])),
        'background' => array('pcre', 'none', array($pattern['color'], '#url\((https?://[^\'"\\\\<>]+?:?\d?)?([^\'"\\\\<>:]+?)*\)[\w\s\-]*$#')),
        'background-color' => array('pcre', 'none', array($pattern['color'])),
        'background-image' => array('pcre', 'none', array($pattern['img_url'])),
        'background-position' => array('pcre', 'none', array($pattern['safe'])),
        'border' => array('pcre', 'none', array($pattern['css'])),
        'border-left' => array('pcre', 'none', array($pattern['css'])),
        'border-right' => array('pcre', 'none', array($pattern['css'])),
        'border-top' => array('pcre', 'none', array($pattern['css'])),
        'border-left-color' => array('pcre', 'none', array($pattern['css'])),
        'border-right-color' => array('pcre', 'none', array($pattern['css'])),
        'border-top-color' => array('pcre', 'none', array($pattern['css'])),
        'border-bottom-color' => array('pcre', 'none', array($pattern['css'])),
        'border-left-width' => array('pcre', 'none', array($pattern['css'])),
        'border-right-width' => array('pcre', 'none', array($pattern['css'])),
        'border-top-width' => array('pcre', 'none', array($pattern['css'])),
        'border-bottom-width' => array('pcre', 'none', array($pattern['css'])),
        'border-bottom-style' => array('pcre', 'none', array($pattern['css'])),
        'margin-left' => array('range', 0, array(0, 100)),
        'margin-right' => array('range', 0, array(0, 100)),
        'margin-top' => array('range', 0, array(0, 100)),
        'margin-bottom' => array('range', 0, array(0, 100)),
        'margin' => array('pcre', '', array($pattern['safe'])),
        'padding' => array('pcre', '', array($pattern['safe'])),
        'padding-left' => array('range', 0, array(0, 100)),
        'padding-right' => array('range', 0, array(0, 100)),
        'padding-top' => array('range', 0, array(0, 100)),
        'padding-bottom' => array('range', 0, array(0, 100)),
        'zoom' => array('range', 1, array(1, 10)),
        'list-style' => array('list', 'none', array('disc', 'circle', 'square', 'decimal', 'lower-roman', 'upper-roman', 'none')),
        'text-align' => array('list', 'left', array('left', 'right', 'center', 'justify')),
        'text-indent' => array('range', 0, array(0, 100)),
        'display' => array('range', 0, array(0, 100)),
    );
    $safehtml = new HTML_White($white_tag, $white_value, $white_css, $arg);
    $result = $safehtml->parse($doc);
    return $result;
}

/**
 * 会话内活跃 token 的复用窗口（秒）。必须 < bull_token_verify 的 $life(3600)：
 * 窗口内派发出的 token 至少还剩 3600-1800=1800 秒有效期。
 */
!defined('XN_BULL_TOKEN_REUSE') and define('XN_BULL_TOKEN_REUSE', 1800);

/**
 * 取会话内仍可用的活跃 token（修复「退出登录恒报非法操作」的根因）
 *
 * 旧行为：每次整页渲染都调用 xn_encrypt（随机 IV）派生一个全新 token 并登记进
 * 场景指纹列表，而列表上限只有 6 条（bull_session_token_push）。后台只要再打开
 * 第 7 个页面，先前页面上那条「退出登录」链接里的 token 就被挤出列表 → 点击时
 * bull_session_token_has 判假 → 操作警告/非法操作。挂一条采集自动翻页链
 * （?0=api&1=api&p=N，每 3 秒整页渲染一次）时，20 秒即可把别的标签页清空，
 * 现场日志实证：用户 12:54 打开的页面，12:59 点退出时该 token 早已不在列表内。
 *
 * 现在同一会话（同 uid/同 IP/同 UA/同密码哈希盐）在复用窗口内固定复用同一个
 * token：渲染时该 token 的 md5 会被重新推到列表首位，因此旧链接/多标签页/
 * 长链采集期间都不会再被挤出，同时保留「每次写操作后轮换」的一次性语义。
 * 加密格式、校验链（解密 + ip + uid + UA + 时效）与后台 token 协议均未改动。
 *
 * @return string|false
 */
function bull_token_reuse_get($uid, $pwd)
{
    $row = isset($_SESSION['_bull_token']) ? $_SESSION['_bull_token'] : NULL;
    if (!is_array($row) || empty($row['token']) || empty($row['sig'])) return FALSE;
    // 盐（密码哈希）/IP/UA 任一变化都必须重新派生：token 明文里绑了这三项
    if ($row['sig'] !== bull_token_reuse_sig($uid, $pwd)) return FALSE;
    if (time() - (int)$row['time'] > XN_BULL_TOKEN_REUSE) return FALSE;
    return (string)$row['token'];
}

/**
 * 记录会话内活跃 token
 */
function bull_token_reuse_set($uid, $pwd, $token)
{
    $_SESSION['_bull_token'] = array(
        'token' => $token,
        'sig'   => bull_token_reuse_sig($uid, $pwd),
        'time'  => time(),
    );
}

/**
 * 活跃 token 的上下文指纹（不落敏感原文，仅存摘要）
 */
function bull_token_reuse_sig($uid, $pwd)
{
    return md5($pwd . '|' . $uid . '|' . ip() . '|' . _SERVER('HTTP_USER_AGENT') . '|' . xn_key());
}

/**
 * 会话内 token 登记（多标签页/后退页面兼容）
 * session 中保留最近 N 个 token 的 md5（历史单值存储自动兼容），
 * 避免「新页面刷新 token 覆盖旧值 → 旧页面提交误报非法操作」
 * @param string $safe_key 会话安全键名
 * @param string $token token 原始值
 */
function bull_session_token_push($safe_key, $token)
{
    $list = isset($_SESSION[$safe_key]) ? $_SESSION[$safe_key] : array();
    if (!is_array($list)) $list = array($list); // 兼容历史单值存储
    $list = array_values(array_unique(array_merge(array(md5($token)), $list)));
    // 12 条窗口：活跃 token 每次渲染都会重新置顶，正常不会用到后半段；
    // 放宽是为了兼容「跨版本升级时会话里已有 6 条轮换 token」的过渡期
    if (count($list) > 12) $list = array_slice($list, 0, 12);
    $_SESSION[$safe_key] = $list;
}

/**
 * 会话内 token 校验（兼容单值与集合存储）
 */
function bull_session_token_has($safe_key, $token)
{
    if (empty($_SESSION[$safe_key])) return FALSE;
    if (is_array($_SESSION[$safe_key])) return in_array(md5($token), $_SESSION[$safe_key], TRUE);
    return hash_equals((string)$_SESSION[$safe_key], md5((string)$token)); // S-2：恒时比较
}

/**
 * 服务端会话里是否「一个安全指纹环都没有」
 *
 * 判定只看事实：本次请求带上了会话 Cookie，但会话内容里连一个 *_token 环都没有 =>
 * 服务端会话是空的（新建 / 被回收 / **根本没落库**）。第三项才是重点：
 * 当天现场就是 nncms_session.url char(32) 撞 MySQL 严格模式 1406 ⇒ 主行永远写不进去 ⇒
 * 每个请求都是空会话 ⇒ 全站写操作被判「令牌来自上一个会话」且刷新也不自愈。
 * 提示必须把矛头指向会话存储，否则运维方向从第一步就错。
 *
 * 只读，不写会话；uid/cid（主行标志位）与 _bull_token（同请求派生的活跃 token）不算环。
 * @return bool
 */
function bull_session_is_empty()
{
    // 首选服务端事实：本次请求到底有没有读到一份会话。只看 $_SESSION 内容会被同一请求的
    // 渲染污染（后台请求也会先写 _bull_token / user_logout 环——第十五轮踩过一次）；
    // loadedFrom 只在 read() 里打点，写入侧改不动它。
    if (class_exists('App\\Service\\Account\\SessionService')) {
        $from = \App\Service\Account\SessionService::$loadedFrom;
        if (NULL !== $from) return ('none' === $from);
    }
    if (!is_array($_SESSION) || empty($_SESSION)) return TRUE;
    foreach ($_SESSION as $k => $v) {
        if ('_bull_token' === $k || 'uid' === $k || 'cid' === $k) continue;
        if (is_array($v) && !empty($v)) return FALSE; // 任一场景环有值 => 会话里存着指纹
    }
    return TRUE;
}

/**
 * bull_token_verify 失败种类判定（只读会话/Cookie，不改变任何校验语义）
 *
 * 后台写操作失败时，"被攻击" 与 "用户自己会话/页面状态过期" 必须分开说：
 * 会话 Cookie（nncms_sid：Max-Age 24h，且 PHP 只在**新建会话**时下发，不做滑动续期）与
 * token 时效（3600s）都比后台登录态（admin_token，cookie_lifetime 默认 100 天）先死，
 * 于是用户看到"页面照常打开、一点就非法操作"的墙（现场复现：六条后台写链全挂）。
 *
 *  - 'stale_token'     ：该场景环里就有这枚 token（确为本会话渲染出去）却仍校验失败
 *                        => 只剩时效/上下文漂移，属于"页面挂太久"
 *  - 'session_expired' ：请求根本没带会话 Cookie => 服务端会话必然是本次新建的，
 *                        场景环不可能命中，属于会话丢失而非 CSRF
 *  - 'session_empty'   ：带 Cookie、token 本身合法，但服务端会话里一个指纹环都没有
 *                        => 会话没内容（未落库/被回收/刚新建），提示要指向会话存储本身；
 *                        现场：列宽不足导致 nncms_session 主行写失败即属此类。
 *  - 'other_session'   ：带了会话 Cookie、环里没有这枚 token，但 token 本身对当前
 *                        uid/IP/UA/时效全部合法 => 它是在**另一个会话**里渲染出来的
 *                        （服务端会话被清/另设备登录/退出后旧标签页）。同样是"会话
 *                        状态过期不是被攻击"，且即使不防也不该叫「非法操作」。
 *  - 'illegal'         ：其余（空 token、伪造、跨场景使用、环内已被挤出去等）
 *
 * 注意不能用"会话里有没有环"来判会话丢失：同一请求的渲染/前台登出等流程会先写入
 * _bull_token 与 user_logout 环（实测），会把新建会话误判成"会话活着"。
 * @param int $uid 校验上下文（用于 other_session 的纯解密判定；0 表示匿名）
 * @return string
 */
function bull_token_failure_kind($safe_key, $token, $uid = 0)
{
    if (!$safe_key || !is_string($token) || '' === $token) return 'illegal';
    if (bull_session_token_has($safe_key, $token)) return 'stale_token';
    $name = session_name();
    if (empty($_COOKIE[$name]) && empty($_COOKIE['nncms_sid'])) return 'session_expired';
    // 环里没有、cookie 也带了：解密一把看这枚令牌本身合不合法。合法 =>
    // 令牌是从另一个会话的页面拿来的（当前会话没有它的指纹环），归 other_session；
    // 不合法（伪造/过期/跨 IP-UA/别的 uid）=> 原 illegal。纯只读，不写会话。
    if (TRUE !== bull_token_validate($uid, $token)) return 'illegal';
    // 合法但当前会话里一个环都没有 => 不是「别的会话的令牌」，而是「当前会话什么都没存下来」
    return bull_session_is_empty() ? 'session_empty' : 'other_session';
}

/**
 * 后台写操作 token 校验统一入口：校验失败即 message 终止（不返回）
 *
 * 只做「失败原因怎么说」的分支，校验链 bull_token_verify（解密 + ip + uid + UA + 时效 +
 * 场景指纹环）一字未改；三种类别见 bull_token_failure_kind()。
 * 放在 security.php 而非 admin.func.php：Service 层也要调用，且不依赖后台入口的加载顺序。
 */
function admin_bull_token_guard($uid, $scene = '', $token = '')
{
    if (NULL === $token || '' === $token) $token = param('safe_token');
    if (FALSE !== bull_token_verify($uid, $token, $scene)) return;
    $kind = bull_token_failure_kind($scene, $token, $uid);
    if ('session_expired' === $kind) {
        if (function_exists('admin_audit_log')) admin_audit_log('admin_session_expired', array('scene' => $scene, 'target_uid' => $uid));
        message(-1, '登录会话已失效（浏览器会话 Cookie 未保存或已过期）：刷新页面后重试，若仍失败请重新登录后台');
    }
    if ('stale_token' === $kind) {
        if (function_exists('admin_audit_log')) admin_audit_log('admin_token_stale', array('scene' => $scene, 'target_uid' => $uid));
        message(1, '页面停留过久，安全校验令牌已过期，请刷新页面后重新操作');
    }
    if ('session_empty' === $kind) {
        if (function_exists('admin_audit_log')) admin_audit_log('admin_token_session_empty', array('scene' => $scene, 'target_uid' => $uid, 'sess_keys' => array_slice(array_keys($_SESSION), 0, 12)));
        message(1, '服务端会话为空（本次操作没有可校验的安全指纹环）：请刷新本页面后重试；若每个页面、每次操作都这样，是会话没能存进服务器——查 runtime/log/当月/session_write_error.php（MySQL 严格模式下列宽/字符集不足最常见，修复语句见该日志）');
    }
    if ('other_session' === $kind) {
        if (function_exists('admin_audit_log')) admin_audit_log('admin_token_other_session', array('scene' => $scene, 'target_uid' => $uid, 'sess_keys' => array_slice(array_keys($_SESSION), 0, 12)));
        message(1, '该操作的安全校验令牌来自上一个会话（服务端会话被清理/另设备登录/退出后，旧标签页令牌即失效）：请刷新当前页面后再操作');
    }
    message(1, lang('illegal_operation'));
}

/**
 * 前台会员写操作 token 校验统一入口（与 admin_bull_token_guard 同一分类逻辑，
 * 只是面向会员的措辞，且不写 admin_audit 通道——前台没有管理员审计语义）
 *
 * 校验链 bull_token_verify（解密 + ip + uid + UA + 时效 + 场景指纹环）一字未改，
 * 只改"失败时说什么"。替换原 [FALSE === bull_token_verify(...) and message(-1, illegal_operation)] 裸写法。
 */
function user_bull_token_guard($uid, $scene = '', $token = '')
{
    if (NULL === $token || '' === $token) $token = param('safe_token');
    if (FALSE !== bull_token_verify($uid, $token, $scene)) return;
    $kind = bull_token_failure_kind($scene, $token, $uid);
    if ('session_expired' === $kind) {
        message(-1, '登录会话已失效（浏览器会话 Cookie 未保存或已过期）：刷新页面后重试，若仍失败请重新登录');
    }
    if ('stale_token' === $kind) {
        message(1, '页面停留过久，安全校验令牌已过期，请刷新页面后重新操作');
    }
    if ('session_empty' === $kind) {
        message(1, '登录会话内容未能保存（没有可校验的安全指纹）：请刷新本页面后重试，若仍失败请重新登录');
    }
    if ('other_session' === $kind) {
        message(1, '该操作的安全校验令牌来自上一个登录会话（重新登录/退出或更换浏览器后，旧页面令牌即失效）：请刷新当前页面后再操作');
    }
    message(1, lang('illegal_operation'));
}

/**
 * 获取/生成会话 token（补实现：等价 bull_token_set 的读取语义，
 * 复用同请求 static 缓存——后台 listPage 的 set 后 get 返回同一 token）
 */
function bull_token_get($uid = 0, $safe_key = '')
{
    return bull_token_set($uid, $safe_key);
}

/**
 * 会话场景指纹环的签发入口：取（或派生）当前上下文的安全 token，并按 $safe_key 登记进会话环。
 *
 * 口径要点（红线 §二.6「写操作三件套」的签发侧，校验侧见 bull_token_verify）：
 *  - 同一请求内按 safe_token_(uid) 做 static 复用，一个请求里多次渲染表单拿到的是同一枚 token；
 *    复用分支**仍要给本 $safe_key 写会话环**，否则一个页面渲染多个不同 KEY 的表单时，
 *    只有首个 KEY 进了环、其余表单提交必被判「非法操作」——原「设置 token」注释记录的就是这个坑。
 *  - 加解密盐口径与校验侧严格一致：$uid>0 取该用户密码散列的 md5（改密即令全部旧 token 漂移失效），
 *    匿名（$uid=0）取 UA 的 md5。
 *  - fail-closed：$uid>0 但 user_read_cache() 取不到用户时直接返回 FALSE，不签发任何 token；
 *    调用方拿到 FALSE 不应继续渲染可写表单。
 *  - 会话内活跃 token 在 XN_BULL_TOKEN_REUSE（1800s）窗口内固定复用（失效根因见
 *    bull_token_reuse_get 的注释：旧实现每次整页渲染都新派生一枚，而当时场景环只留 6 条
 *    （现已放宽到 12 条窗口，见 bull_session_token_push），采集自动翻页链能在 20 秒内把别的
 *    标签页挤空 →「退出登录恒报非法操作」），
 *    窗口外或上下文（uid/密码散列/IP/UA/xn_key）变化才重新派生并重新登记。
 * @param int $uid 绑定用户 UID；0 = 匿名访客（按 UA 绑定）
 * @param string $safe_key 会话场景键名（如 admin_vod、user_create）；留空 = 只派生不登记场景环
 * @return string|false 可写入表单/URL 的 token 串；签发前置条件不满足（用户不存在）时 FALSE
 */
function bull_token_set($uid = 0, $safe_key = '')
{
    $key = 'safe_token_' . $uid;
    static $cache = array();
    if (isset($cache[$key])) {
        // 同一请求内复用 token；但仍要为本 $safe_key 写入 session，
        // 否则一个页面渲染多个不同 KEY 的表单时，只有首个 KEY 会写 session，其余表单校验必失败。
        $safe_key and bull_session_token_push($safe_key, $cache[$key]);
        return $cache[$key];
    }
    if ($uid) {
        $user = user_read_cache($uid);
        if (empty($user)) return FALSE;
        $pwd = md5($user['password']);
    } else {
        $useragent = _SERVER('HTTP_USER_AGENT');
        $pwd = md5($useragent);
    }
    // 会话内复用活跃 token（见 bull_token_reuse_get 注释的失效根因），窗口外才重新派生
    $reuse = bull_token_reuse_get($uid, $pwd);
    $cache[$key] = ($reuse !== FALSE && $reuse !== '') ? $reuse : bull_token_gen($uid, $pwd);
    if ($reuse === FALSE || $reuse === '') bull_token_reuse_set($uid, $pwd, $cache[$key]);
    $safe_key and bull_session_token_push($safe_key, $cache[$key]);
    return $cache[$key];
}

/**
 * 安全令牌校验（fail-closed）：空 token 直接 FALSE；$safe_key 非空时先要求该 token
 * 已登记在会话场景指纹环（bull_session_token_has），否则视为跨会话/伪造拒绝。
 * 校验密钥口径与签发一致：$uid>0 用该用户密码散列 md5（改密即令牌漂移），
 * 匿名（$uid=0）用 UA md5 且 UA 为空拒绝。最终交 bull_token_decrypt 做
 * ip/uid/UA/时效($life，默认 3600s) 比对——**通过后有轮换副作用**：派生新 token
 * 并推入场景环，返回该新 token 串。调用方只判 `!== FALSE`，不消费返回值本身。
 * @param int $uid 绑定用户 UID（0=访客，按 UA 绑定）
 * @param string $token 待校验令牌
 * @param string $safe_key 会话场景键名（'' = 不做场景环前置校验）
 * @param int $life 令牌有效期秒数
 * @return string|false 成功=轮换出的新 token；任何校验环节失败=FALSE
 */
function bull_token_verify($uid, $token, $safe_key = '', $life = 3600)
{
    if (empty($token)) return FALSE;
    if ($safe_key && !bull_session_token_has($safe_key, $token)) return FALSE;
    $useragent = _SERVER('HTTP_USER_AGENT');
    if ($uid) {
        $user = user_read_cache($uid);
        if (empty($user)) return FALSE;
        $pwd = md5($user['password']);
    } else {
        if (empty($useragent)) return FALSE;
        $pwd = md5($useragent);
    }
    return bull_token_decrypt($token, $uid, $pwd, $safe_key, $life);
}

/**
 * 派生安全 token 的密文（纯签发原语，无任何会话读写副作用；带副作用的登记入口是 bull_token_set）。
 *
 * 明文是「上下文指纹四元组」ip / uid / time / md5(UA)，以制表符连接；
 * 密钥 = md5(xn_key() . $salt)。原注释里的「salt 混淆码」就是这里的 $salt——
 * 它是**加解密用的密钥碎片**，不是随机噪声：$uid>0 传该用户密码散列的 md5，匿名传 UA 的 md5，
 * 因此改密、换 IP、换 UA、轮换 auth_key 任一项发生，旧 token 都解不开（fail-closed）。
 * 底层 xn_encrypt() 是 AES-256-GCM（随机 IV，格式 \x01+iv+ct+tag 后 base64），
 * 每次调用的密文都不同，所以 token 不能拿密文串直接比对，只能解密后比上下文；
 * 会话环里存的是它的 md5（见 bull_session_token_push）。
 * ⚠ S-5：openssl 不可用时 xn_encrypt 抛 RuntimeException，**不降级 XXTEA**，调用方别指望拿到 FALSE。
 * @param int $uid 绑定用户 UID（0 = 匿名访客；取证：bull_token_set/bull_token_decrypt 透传的 $uid）
 * @param string $salt 加解密密钥碎片（md5 摘要串，取证：bull_token_set 传 md5($user['password']) 或 md5($useragent)）
 * @return string base64 形态的 AES-256-GCM 密文 token
 */
function bull_token_gen($uid, $salt = '')
{
    $token_key = md5(xn_key() . $salt);
    $useragent = _SERVER('HTTP_USER_AGENT');
    $ua_md5 = md5($useragent);
    $ip = ip();
    $time = time();
    $token = xn_encrypt("$ip	$uid	$time	$ua_md5", $token_key);
    return $token;
}

/**
 * 纯令牌校验：解密 + ip/uid/UA/时效四项比对，**没有任何会话副作用**（不轮换、不登记）。
 *
 * 与 bull_token_decrypt 共用同一条校验链；decrypt 在本函数之上多了「校验通过后派生新令牌并推入
 * 场景环」这一副作用。失败分类器 bull_token_failure_kind() 在「场景环里没有这枚令牌」时用本函数
 * 区分两类失败：令牌本身不合法（伪造/跨上下文/过期）归 illegal，
 * 令牌完全合法但属于另一个会话才归 other_session——所以本函数是只读探针，可以安全地在报错路径上调用。
 *
 * 全链 fail-closed，任一不过即 FALSE：空 token；$uid>0 但 user_read_cache() 取不到用户；
 * $uid=0 但 UA 为空（匿名令牌没有 UA 就无法复现密钥碎片）；密文解不开或段数不等于 4；
 * UA 指纹不等（S-2 修复：改 hash_equals 恒时比较，禁松散 ==）；时效超窗；uid/IP 不一致。
 * uid/IP 仍是松散比较，属既有口径（值来自解密后的服务端上下文，非攻击者可控的凭据比对）。
 * @param int $uid 绑定用户 UID（0 = 匿名访客，按 UA 绑定；取证：bull_token_verify 与 admin_bull_token_guard 透传）
 * @param string $token 待校验的密文 token
 * @param int $life 有效期秒数；⚠ 传值小于 10 会被就地改成 1800（防误传 0 变成「永久有效」）
 * @return bool TRUE = 令牌自身完全合法；FALSE = 伪造/跨上下文/过期/前置数据缺失
 */
function bull_token_validate($uid, $token, $life = 3600)
{
    if (empty($token)) return FALSE;
    $ip = ip();
    $time = time();
    $useragent = _SERVER('HTTP_USER_AGENT');
    if ($uid) {
        $user = user_read_cache($uid);
        if (empty($user)) return FALSE;
        $salt = md5($user['password']);
    } else {
        if (empty($useragent)) return FALSE;
        $salt = md5($useragent);
    }
    $token_key = md5(xn_key() . $salt);
    $s = xn_decrypt($token, $token_key);
    if (empty($s)) return FALSE;
    $arr = explode("\t", $s);
    if (count($arr) != 4) return FALSE;
    list($_ip, $_uid, $_time, $ua_md5) = $arr;
    $life < 10 and $life = 1800;
    return !(!hash_equals((string)$ua_md5, md5((string)$useragent)) || $time - $_time > $life || $uid != $_uid || $ip != $_ip); // S-2：UA 指纹恒时比较
}

/**
 * 解密校验 + 一次性轮换（写操作校验的实际执行体）：token 合法则**当场派生一枚新 token 顶替它**，
 * 非法则返回 FALSE。原「解密 token，正确则返回新 token，错误返回 FALSE」的语义即此。
 *
 * 校验部分完全委托 bull_token_validate()（解密 + ip/uid/UA/时效），本函数只加两件事：
 *  1) 轮换：$uid 通过校验后立即 gen 新令牌 ⇒ 同一枚 token 用第二次就不在场景环里了（一次性语义）；
 *  2) 登记：$safe_key 非空时把新 token 的 md5 推入会话场景环（bull_session_token_push，12 条窗口）。
 * 注意 $salt **只用于生成新 token 的密钥碎片**，校验用的密钥碎片由 validate 内部按 $uid 重新取；
 * 两者必须是同一口径（调用方 bull_token_verify 传的都是 md5(密码散列) 或 md5(UA)），
 * 传错会得到「旧 token 校验通过、新 token 下一次解不开」的隐蔽故障。
 * 调用方只应判 `!== FALSE`，不要把返回的 token 串再回填给本次表单（它是下一次请求的凭据）。
 * @param string $token 待校验的密文 token（注意本函数参数顺序与 gen/verify 不同，token 在前）
 * @param int $uid 绑定用户 UID（0 = 匿名访客）
 * @param string $salt 新 token 的密钥碎片（md5 摘要串，须与签发侧同口径）
 * @param string $safe_key 会话场景键名；留空 = 只轮换不登记场景环（则新 token 无法通过后续环校验）
 * @param int $life 有效期秒数，透传给 bull_token_validate（小于 10 按 1800 处理）
 * @return string|false 通过 = 轮换出的新 token 串；任何校验环节失败 = FALSE
 */
function bull_token_decrypt($token, $uid, $salt = '', $safe_key = '', $life = 3600)
{
    if (FALSE === bull_token_validate($uid, $token, $life)) return FALSE;
    $new_token = bull_token_gen($uid, $salt);
    if ($safe_key) bull_session_token_push($safe_key, $new_token);
    return $new_token;
}

// 清理token

// 过滤用户昵称里面的特殊字符

// emoji过滤

// 直接传message 也可以传数组$arr = array('message' => message, 'doctype' => 1, 'gid' => $gid)
// 格式转换: 类型，0: html, 1: txt; 2: markdown; 3: ubb
// 入库时进行转换，编辑时再转码

/**
 * 把任意富文本压成「纯文本短串」：trim → stripslashes → strip_tags（剥掉全部 HTML 标签）→
 * 删除实体占位 &nbsp;、正斜杠、制表符、换行（\r\n、\r、\n）、连续 2/3/4 个空格与制表符。
 * 用于昵称、邮箱、标签名、分类名、广告名等**不该带任何标记与换行的短字段**（取证调用点：
 * UserService 登录/注册的 email 与 username、TagFlowService 的标签名、AdManageService 的
 * name/area、CategoryManageService 的 type_name）。
 *
 * ⚠ 三个必须知道的边界：
 *  1) **它不是 XSS 转义函数**，只做「去标签」。行尾保留的历史注释写明了本库的分工——
 *     入库前保持干净文本，转码在入库时做，因此输出侧无需 htmlspecialchars_decode()；
 *     模板 `{$var}` 是裸 echo（审计 S-1），前台直出前仍要按上下文 xn_h()（红线 §二.4）。
 *  2) stripslashes() 会把合法的反斜杠一并吃掉（`\` → 空），别用在需要保留反斜杠的值上。
 *  3) 正斜杠是全量删除的，所以**绝不能用在 URL、日期、路径**上（会变 form 之类的残串）。
 * @param string $text 原始文本（调用点多为 param()/admin_param_text() 的结果，已带一层转义）
 * @return string 去标签去空白后的单行纯文本；入参为 NULL 时按空串处理（PHP 8 对 trim(null) 只发弃用通知）
 */
function filter_all_html($text)
{
    $text = trim($text);
    $text = stripslashes($text);
    $text = strip_tags($text);
    $text = str_replace(array('&nbsp;', '/', "\t", "\r\n", "\r", "\n", '  ', '   ', '    ', '	'), '', $text);
    //$text = htmlspecialchars($text, ENT_QUOTES); // 入库前保留干净，入库时转码 输出时无需htmlspecialchars_decode()
    return $text;
}


// filter keyword

// 私钥生成签名

// 公钥验证签名

/**
 * 输出转义辅助：HTML 正文与属性值统一转义口径（模板输出侧通用，替代裸 echo）。
 * 只做 htmlspecialchars(ENT_QUOTES, UTF-8)，把 & < > " ' 全部转义——因此**两种上下文都可安全使用**
 * （属性双引号内、正文文本内），但不可用于 JS 字符串、CSS、URL query 成分（那是另一套口径）。
 * 定位（红线 §二.4）：这是「转义必须是数据流最后一步」的输出层。模板 `{$var}` 编译成裸 echo
 * （审计 S-1，长期修复未落地），后台 html 里凡拼接用户可控值都必须显式过本函数；
 * 反过来，param() 取回的值**默认已经转义过一次**，再套本函数就是 `&amp;amp;` 双重转义事故（§二.4）。
 * @param mixed $text 待转义值，内部强制 (string) 转换（取证：调用点传表单值、配置数组元素、
 *                    date() 串等标量，见 core/admin/route/api.php 与 plugins/rss、plugins/live 的 setting.php）；
 *                    传 array/object 会触发 Array to string conversion 并输出残值，调用方自行保证是标量
 * @return string 转义后的安全文本（空值进、空串出）
 */
function xn_h($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

/**
 * 外链输出辅助：白名单式校验 + 转义，用在 href/src 这类「URL 直接进属性」的位置。
 * 判定顺序：trim 后为空、scheme 不是 http/https（javascript、data、vbscript、协议相对串等一律拒）、
 * 或串内出现控制字符/引号/反引号/尖括号，三者任一命中都**原样返回 $fallback**（不转义，
 * 所以 $fallback 必须是调用方给的硬编码安全字面量，默认值是「什么都不做」的 javascript 空动作）；
 * 全部通过才返回 htmlspecialchars(ENT_QUOTES, UTF-8) 的结果。
 * 口径来源：「外部资源 URL 必须校验 scheme」红线，与 xn_html_safe() 的 href 协议判定同向。
 * ⚠ 调用面（复核更正）：**后台 PHP 视图里有 4 处真实调用**——
 *   `core/admin/html/api_art_list.html`（2 处）、`api_fastapi.html`、`tmpl_cloud.html`。
 *   收口批次 A5 曾以「`--include='*.php'` 全仓零引用」为由删除本函数，**漏扫了 `core/admin/html/*.html`
 *   这类含 PHP 代码的视图**，导致这三个后台页 500；现已恢复，并作为「删除面核验必须覆盖 .html 视图」
 *   的反例记录在案（oldskills/security/CHANGESET-审查修复轮.md §10（与 web 根同级，已归档））。
 * @param string $url 待输出的 URL（取证：语义要求字符串；传 NULL 在 PHP 8.5 只发弃用通知）
 * @param string $fallback 校验不过时的回退值，默认是空动作的伪协议串
 * @return string 通过校验 = 转义后的 URL；否则 = $fallback 原文
 */
function xn_url_h($url, $fallback = 'javascript:void(0);')
{
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url) || preg_match('/[\x00-\x1F\x7F"\'`<>]/', $url)) {
        return $fallback;
    }
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

/**
 * URL 危险字符剥离（入库/读侧通用的净化闸）：只把引号（双单）、尖括号、反引号与全部控制字符
 * （含 0x00-0x1F、0x7F）**删掉**，其余字符原样保留。
 * 刻意不碰美元符、井号、与号、空格——它们是采集 play_url「名美元符址井号名美元符址」多线路串的结构字符，
 * 一并转义或删掉会把播放地址解析链整条打断；也不校验 scheme（那是 xn_url_h() / xn_html_safe() 的职责），
 * 因此**它不是转义函数，也不做协议白名单**，只保证「属性上下文里无法闭合标签、无法起新属性」。
 * 用途（红线「输出必须转义」的入库侧落点）：采集/编辑写入 play_url、pic、play_server、play_note 等
 * 外部 URL 字段——模板 `{$x}` 编译成裸 echo（审计 S-1，输出层不转义），这一闸是这类字段唯一的逃逸防线，
 * **新增同类 URL 入库字段必须过它**（取证调用点：VideoService、VodEditService、ApiExecutionService 多处）。
 * 注意调用方有的会在本函数之前先做一次控制字符清洗（VodEditService 传参前已 preg_replace 过一轮），
 * 属重复加固，不产生副作用。
 * @param string $url 原始 URL 或多线路串（内部强制 (string) 转换，取证：调用点传采集字段与 param() 原值）
 * @return string 剥离危险字符后的串（入参为空则返回空串；不会返回 FALSE，preg_replace 失败也只回 NULL，正常入参不会发生）
 */
function xn_url_strip($url)
{
    return preg_replace('/[\x00-\x1F\x7F<>"\'`]/', '', (string)$url);
}

/**
 * LIKE 模式转义（上线前审计修复 VULN-L01）
 *
 * 修复前四处实现（ContentQueryRepository::normalizeKeyword / QueryBuilder::applyCond /
 * db_cond_to_sqladd / HotManageService::findPage）都是
 *   str_replace(array('%','_'), array('\\%','\\_'), $kw)
 * ——**只转义通配符，不先转义转义符本身**。MySQL LIKE 默认转义符为 `\`，
 * 因此输入 `\%` 会变成 `\\%` 三个字节：`\\` 被解释为**字面反斜杠**、后面的 `%` 重新成为
 * 通配符，用户"按字面搜索"的契约被破坏（`\%` 语义漂移，且可能诱导更大范围扫描）。
 *
 * 正确顺序：先把 `\` 自身加倍，再转义 `%` 与 `_`。
 * 值仍走参数绑定，本函数只影响 LIKE 的**模式语义**，不承担注入防护。
 * 与 `ESCAPE '\\'` 搭配使用即完全确定（MySQL 默认即 `\`，无需改写既有 SQL）。
 */
function like_escape($kw)
{
    return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string)$kw);
}

/**
 * 机器通道（api.php）对外链路的**派生令牌**：`md5( auth_key . 固定分隔串 . $purpose )`。
 * 用户裁定「auth_key 一钥三用不可行」后的落地口径——凡是会出现在 URL、access_log、
 * 对端存储里的凭据，一律用本函数派生，**auth_key 本体不再出服务器**；
 * 派生串中间加了固定域分隔片段，用途之间不互相碰撞（同一 auth_key 下 'api' 与将来其他
 * 用途的 token 不是同一个值）。轮换 auth_key 即同时吊销全部派生 token。
 * 校验侧（api.php）与派生值做 hash_equals 恒时比较，两路都不匹配直接 403：
 *  ① 主路 = 本函数的派生值；
 *  ② legacy 路 = 原始 auth_key 本体，**仅当 conf['api_legacy_token'] 显式取值为 1 才启用**
 *    （出厂为 0；上线前终检 N-03 把「键缺失即放行」改成了「显式开启才放行」，与 fail-closed 同口径。
 *    本函数上方原注释写的「未显式置 0 时仍接受原始 auth_key」是 N-03 **之前**的旧口径，已过期，以代码为准）。
 * fail-closed：conf['auth_key'] 缺失/为空时本函数直接返回空串，调用方（api.php）随即不放行
 * ⇒ 没配 auth_key 的站点机器通道整体不可用，而不是退化成弱凭据。
 * @param string $purpose 用途标识（域隔离前缀，取证：全仓调用点当前只传 'api'——
 *                        api.php 主入口、TaskManageService 的 cron URL 渲染、ApiExecutionService 出网请求）
 * @return string 32 位十六进制派生 token；auth_key 缺失/为空时返回空串（调用方据此拒绝放行）
 */
function api_wire_token($purpose = 'api')
{
    $conf = _SERVER('conf');
    $authKey = is_array($conf) && isset($conf['auth_key']) ? (string)$conf['auth_key'] : '';
    if ('' === $authKey) {
        return '';
    }
    return md5($authKey . '|nncms-api-token|' . $purpose);
}

