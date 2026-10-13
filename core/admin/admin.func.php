<?php 
define('XN_ADMIN_BIND_IP', array_value($conf, 'admin_bind_ip'));
/**
 * 生成后台Cookie安全选项（httponly/samesite/secure）
 * @param int $expires 过期时间戳
 * @return array Cookie选项数组
 */
function admin_cookie_opt(int $expires): array
{
    global $conf;
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) != 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $opt = [
        'expires' => $expires,
        'path' => $conf['cookie_path'],
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (!empty($conf['cookie_domain'])) $opt['domain'] = $conf['cookie_domain'];
    return $opt;
}
/**
 * 校验后台登录Token
 * 超时3600秒自动退出，1800秒自动续期
 */
function admin_token_check(): void
{
    global $longip, $time, $useragent, $conf;
    $useragent_md5 = md5($useragent);
    $key = md5((XN_ADMIN_BIND_IP ? $longip : '') . $useragent_md5 . xn_key());
    // 仅从 Cookie 读取（GET/POST 参数可伪造覆盖导致已登录管理员被强制登出）
    $admin_token = $_COOKIE[$conf['cookie_pre'] . 'admin_token'] ?? '';
    if (empty($admin_token)) {
        $_REQUEST[0] = 'index';
        $_REQUEST[1] = 'login';
    } else {
        $s = xn_decrypt($admin_token, $key);
        if (empty($s)) {
            setcookie($conf['cookie_pre'] . 'admin_token', '', admin_cookie_opt($time - 86400));
            message(-1, lang('admin_token_expiry'));
        }
        // S-7（2026-10-10 审计修复）：令牌绑定 uid + 管理员密码哈希。旧版 2 字段令牌（ip/time）
        // 视为失效（fail-closed），管理员需重新登录一次；改密码/换账号后旧令牌立即失效。
        list($_ip, $_time, $_uid, $_pwd) = array_pad(explode("\t", $s), 4, '');
        // 后台超过 3600 自动退出；若绑定 IP 则 IP 变化立即退出
        if ($time - $_time > 3600 || (XN_ADMIN_BIND_IP && $_ip != $longip)) {
            setcookie($conf['cookie_pre'] . 'admin_token', '', admin_cookie_opt($time - 86400));
            message(-1, lang('admin_token_expiry'));
        }
        // S-7：令牌 uid 必须等于当前会话 uid，且管理员仍存在、密码哈希未变（改密即吊销全部旧令牌）
        $admin_user = intval($_uid) > 0 ? user_read(intval($_uid)) : array();
        if (intval($_uid) < 1 || empty($admin_user) || intval($_uid) !== intval(_SESSION('uid'))
            || !hash_equals(md5((string)$admin_user['password']), (string)$_pwd)) {
            setcookie($conf['cookie_pre'] . 'admin_token', '', admin_cookie_opt($time - 86400));
            message(-1, lang('admin_token_expiry'));
        }
        $time - $_time > 1800 AND admin_token_set(intval($_uid));
    }
}
/**
 * 设置后台登录Token（有效期3600秒）
 */
function admin_token_set(int $uid = 0): void
{
    global $longip, $time, $useragent, $conf;
    // S-7：未显式传 uid（续签路径）时从会话读取；uid 不可用/用户不存在则不发令牌（fail-closed）
    if ($uid < 1) $uid = intval(_SESSION('uid'));
    $user = $uid > 0 ? user_read($uid) : array();
    if (empty($user) || empty($user['password'])) return;
    $useragent_md5 = md5($useragent);
    $key = md5((XN_ADMIN_BIND_IP ? $longip : '') . $useragent_md5 . xn_key());
    $s = "$longip	$time	$uid	" . md5($user['password']);
    $admin_token = xn_encrypt($s, $key);
    setcookie($conf['cookie_pre'] . 'admin_token', $admin_token, admin_cookie_opt($time + 3600));
}
/**
 * 清除后台登录Token
 */
function admin_token_clean(): void
{
    global $time, $conf;
    setcookie($conf['cookie_pre'] . 'admin_token', '', admin_cookie_opt($time - 86400));
}
/**
 * 安全获取整数参数（支持范围限制）
 * @param string $key 参数名
 * @param int $default 默认值
 * @param int|null $min 最小值
 * @param int|null $max 最大值
 * @return int 整数参数值
 */
function admin_param_int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $val = intval(param($key, $default));
    $min !== null && $val < $min and $val = $min;
    $max !== null && $val > $max and $val = $max;
    return $val;
}
/**
 * 安全获取文本参数（自动去空白/控制字符/截断）
 * @param string $key 参数名
 * @param string $default 默认值
 * @param int $max 最大长度
 * @param bool $decode 是否进行URL解码
 * @return string 过滤后的文本
 */
function admin_param_text(string $key, string $default = '', int $max = 255, bool $decode = false): string
{
    $val = param($key, $default, false);
    $decode and $val = xn_urldecode($val);
    $val = trim((string)$val);
    $val = str_replace(["\0", "\r", "\n", "\t"], '', $val);
    $max and $val = substr($val, 0, $max);
    return $val;
}

/**
 * 后台替换记录统一过滤：只保留最近 N 天内、且 addtime 可解析的记录。
 * 口径集中在后台入口，避免「地址替换页 / 顶部通知 / 首页通知卡片」各写一份时间窗口逻辑。
 *
 * @param array $rows 商店 ?macapi 返回的 replist
 * @param int $days 保留天数窗口，默认 60
 * @return array 过滤后的记录（保持原顺序）
 */
function admin_filter_recent_replist(array $rows, int $days = 60): array
{
    $days = $days > 0 ? $days : 60;
    $cutoff = time() - ($days * 86400);
    $out = array();
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $ts = strtotime((string)($row['addtime'] ?? ''));
        if (false === $ts || $ts < $cutoff) continue;
        $out[] = $row;
    }
    return $out;
}
/**
 * 安全获取ID数组（支持逗号分隔或数组）
 * @param string $key 参数名
 * @return array ID整型数组
 */
function admin_param_ids(string $key = 'ids'): array
{
    $ids = param($key, [0]);
    if (!is_array($ids)) {
        $ids = explode(',', (string)$ids);
    } elseif (empty($ids)) {
        $single = param($key, '', false);
        $single !== '' and $ids = explode(',', (string)$single);
    }
    $ids = array_map('intval', $ids);
    $ids = array_values(array_filter(array_unique($ids)));
    // 上线前审计修复（VULN-M09）：**条数上限**。
    // 原实现无上限：单个逗号串变量（max_input_vars 拦不住，8MB body 内可塞 ~10^6 个 id）直达
    // VideoService::deleteAll/bulkUpdate —— 那里 `set_time_limit(0)`、IN(N)+LIMIT N、
    // CASE-WHEN 批量 UPDATE 与 N× cache_delete，单请求即可拖垮 FPM/MySQL/Redis。
    // 现统一截断（后台单次批量 500 条已远超正常运维用量）。
    if (count($ids) > 500) {
        $ids = array_slice($ids, 0, 500);
    }
    return $ids;
}
/**
 * 写入后台操作审计日志
 * @param string $action 操作名称
 * @param array $data 附加数据
 * @param string $file 日志文件名
 */
function admin_audit_log(string $action, array $data = [], string $file = 'admin_audit'): void
{
    if (empty($action)) return;
    $uid = intval(G('uid'));
    $route = param(0, '');
    $route_action = param(1, '');
    $payload = [
        'action' => $action,
        'uid' => $uid,
        'route' => $route,
        'route_action' => $route_action,
    ];
    if (!empty($data) && is_array($data)) {
        foreach ($data as $k => $v) {
            $payload[$k] = $v;
        }
    }
    xn_log(xn_json_encode($payload), $file);
}
/**
 * 校验后台 bull_token（safe_token），失败直接 message 终止
 * 路由层样板收敛：替代重复的 param + bull_token_verify 两行组合
 * 失败原因的三种说法统一由 admin_bull_token_guard()（core/func/security.php）负责：
 * 会话 Cookie 掉了 / 页面挂太久令牌过期，都不该被当成 CSRF 报「非法操作」。
 */
function admin_require_bull_token(int $uid, string $scene): void
{
    admin_bull_token_guard($uid, $scene, param('safe_token'));
}

/**
 * 输出 JSON 并终止（统一 ob 清理与响应头）
 */
function admin_json_out($data): void
{
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 后台路由分发：初始化模板公共全局 → 定名路由文件 → 复核 intoadmin → 经 _include() 编译管线加载（保住路由文件内的插件 hook 锚点）。
 * 关键行为：
 *  - 路由名支持 route-action 形态（vod-list→vod.php、set-cache→set.php），未命中映射表时按 '-' 首段回退，再落 $default_route；
 *  - M-A2：分发前统一复核 group_access(gid,'intoadmin')（fail-closed），撤权即时生效；index 的 login/logout 动作放行；
 *  - F-01 例外补偿：仅当请求携带 admin_token Cookie（即已过 admin_token_check）才现场刷新 $nnzs（niuniuzsCodes(FALSE) 允许出网），未登录请求口径不变；
 *  - $header 刻意不 global（历史缺陷：预存符号使路由文件 EXTR_SKIP 跳过 header，页标题恒残缺）；
 *  - 历史维护记录：本函数原有一段脱落的注释块（「根据路由映射表包含对应路由文件」及其 @param）
 *    误贴在 admin_require_bull_token() 的 docblock 之前，注释整理轮已删除，要点全部以本块为准。
 * @param string $base_path 路由文件目录前缀（如 core/admin/route/）
 * @param string $route 当前路由名
 * @param array $route_map 路由名→文件名映射表
 * @param string $default_route 默认路由键（未命中时取 route_map[$default_route]）
 * @return void
 */
function admin_include_route(string $base_path, string $route, array $route_map, string $default_route = 'index'): void
{
    global $db, $conf, $config, $time, $longip, $runtime, $uid, $user;
    // 模板公共变量初始化（admin/html/header.html / sidebar.html / footer.html 中使用）
    global $nnzs, $version, $cloud_version, $gid, $grouplist, $alias_reservation;
    // 修复：$header 不在此声明/保留全局——此前 global $header 使符号预先存在，
    // 路由文件的 extract(..., EXTR_SKIP) 跳过 header，全部后台页标题恒为「-NiuNiuCms」
    unset($header);
    // 修复（「替换信息」菜单/徽标/页面/通知全空）：后台入口的 F-01 加固（admin.php 的 `$cacheOnly = TRUE`）把入口取数
    // 改成**只读缓存**（防匿名请求触发 30s 出网，必须保留），但 `niuniuzs_code` 的唯一写入者
    // `niuniuzsCodes(FALSE)` 自此全仓无人调用 ⇒ 缓存恒冷 ⇒ `$nnzs` 恒 null ⇒
    // AdminMenuService 的 replist 徽标、set-rep 页、仪表盘与通知下拉全部为空
    // （商店 `?macapi` 实有 55 条 replist，纯取数链路断）。
    // 这里只对**已通过 admin_token 校验的后台请求**补一次刷新：登录页/未登录请求在
    // admin_token_check() 里已被改写成 index/login 且不带该 Cookie ⇒ "入口不出网"的口径不变。
    if ('' !== (string)($_COOKIE[($conf['cookie_pre'] ?? '') . 'admin_token'] ?? '') && (!isset($nnzs) || !is_array($nnzs))) {
        $nnzs = \App\Service\System\CloudOpsService::niuniuzsCodes(FALSE);
    }
    if (!isset($nnzs) || !is_array($nnzs)) $nnzs = array('replist' => array(), 'apilist' => array());
    $nnzs['replist'] = admin_filter_recent_replist(is_array($nnzs['replist'] ?? NULL) ? $nnzs['replist'] : array(), 60);
    if (!isset($version)) $version = $conf['version'] ?? '1.0.0';
    if (!isset($cloud_version)) $cloud_version = $version;
    if (!isset($alias_reservation) || !is_array($alias_reservation)) $alias_reservation = array('index', 'admin', 'user', 'search', 'sitemap', 'page');   // 'page'=单页路由前缀，防别名劫持（复核修正，与 index.inc.php 同口径）
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // 支持 route-action 格式（如 vod-list → vod.php, set-cache → set.php）
    $base_route = $route;
    if (!isset($route_map[$route])) {
        $parts = explode('-', $route, 2);
        $base_route = $parts[0];
    }
    $route_file = isset($route_map[$base_route]) ? $route_map[$base_route] : $route_map[$default_route];
    // 上线前审计修复（M-A2）：撤权即时生效——admin_token 滚动续签无服务端吊销，
    // 此前仅 route/index.php 查 intoadmin，被收回后台权限的账号凭残余 manage* 位可无限期写操作。
    // 统一入口复核（登录/登出动作放行，未登录访客已被 admin_token_check 改写进 login 分支）。
    $is_login_action = ('index' === $base_route && in_array(param(1, ''), array('login', 'logout'), TRUE));
    if (!$is_login_action && FALSE === group_access(intval($gid), 'intoadmin')) {
        message(1, lang('user_group_insufficient_privilege'));
    }
    include _include($base_path . $route_file);
}
