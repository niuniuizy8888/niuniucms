<?php
namespace App\Service\Admin;

class AdminIndexService
{
    /**
     * 后台登录页视图数据（admin route/index.php case 'login' GET）。
     *
     * @param int $uid 当前会话 uid（匿名传 0，登录令牌挂匿名指纹环）
     * @return array {header, safe_token}（safe_token=bull_token_set('admin_login')，签发失败可能为 FALSE）
     */
    public static function loginPage(int $uid): array
    {
        return array(
            'header' => array(
                'title' => lang('admin_login'),
            ),
            'safe_token' => bull_token_set($uid ?: 0, 'admin_login'),
        );
    }

    /**
     * 后台登录主流程（case 'login' POST）。安全链层层 fail-closed：
     *  ① admin_bull_token_guard（场景 admin_login，与 loginPage 签发同绑，堵任意 uid=0 令牌冒充——F-04）；
     *  ② 密码长度上限 4096 防 bcrypt DoS；
     *  ③ 双重限流：字符串 IP（非 longip，IPv6 下 longip≡0 会共享桶）+ 归一化小写邮箱键，各 10 次 / 300s；
     *  ④ findUserForLogin + verifyPassword（账号不存在与密码错统一文案 + 哑 bcrypt 拉平时序）；
     *  ⑤ 组权限闸：group_access(gid,'intoadmin') 为 FALSE 直接拒（防任意注册用户进后台）；
     *  ⑥ 通过后更新 login_date/ip、轮换会话 id、写会话 uid + admin_token（S-7 绑 uid）+ 前台 user_token。
     *
     * @param mixed $uid    当前会话 uid（通常 0=登录前），token 校验主体
     * @param int   $longip 登录 IP 的无符号整数形态，落库 user.login_ip
     * @param int   $time   请求时间戳，落库 user.login_date
     * @return array {code:0, message}（message 含 jump() 到 conf.admin_entry 的跳转脚本；失败经 message() 中断，不返回）
     */
    public static function login($uid, int $longip, int $time): array
    {
        global $conf; // 上线前安全审计修复（F-20）：此前未导入 $conf，登录跳转恒落回退 'admin.php'
        $safeToken = param('safe_token');
        // 审计修复（F-04）：与 loginPage 签发同场景绑定（此前传空场景跳过会话指纹环，
        // 任意 uid=0 场景令牌（user_login/create/danmu_send）在密码正确时均可当后台登录 CSRF 令牌）。
        admin_bull_token_guard($uid, 'admin_login', $safeToken);

        $email = filter_all_html((string)param('email'));
        $password = (string)param('password');
        strlen($password) > 4096 and message(-1, '密码长度不能超过4096个字符');
        empty($email) and message('email', lang('email_is_empty'));

        // 限流键用字符串 IP（IPv6 下 $longip≡0 会让全部 v6 出口共享一个桶；）
        if (!login_rate_check(ip(), 10, 300)) {
            message(-1, '登录尝试过于频繁，请5分钟后再试');
        }
        // 审计修复（F-06）：与前台同口径，邮箱限流键归一为小写（DB 大小写不敏感）。
        // L-2（R4）：账户桶改为「账号+IP 组合」键，消除纯账号锁定 DoS（换 IP 猛打某邮箱不再能全局锁死受害者账号）；无验证码体系故仅 账号+IP + 每IP 两桶。
        if ($email !== '' && !login_rate_check('uip_' . mb_strtolower($email, 'UTF-8') . '|' . ip(), 10, 300)) {
            message(-1, '该账户在本 IP 登录尝试过于频繁，请5分钟后再试');
        }

        $_user = self::findUserForLogin($email);
        self::verifyPassword($_user, $password);

        // 安全红线：仅发放后台 token 给具备 intoadmin 权限的用户组，防止任意注册用户进入后台
        if (FALSE === group_access(intval($_user['gid']), 'intoadmin')) {
            admin_audit_log('login_denied_no_admin', array('target_uid' => intval($_user['uid']), 'email' => $email), 'admin_login');
            message(-1, '该账户无后台管理权限');
        }

        user_update($_user['uid'], array(
            'login_date' => $time,
            'login_ip' => $longip,
        ));
        session_regenerate_id(true);
        // 与前台登录一致：写会话 uid（后台路由权限 group_access 基于 user_rest 读取的 uid）
        $_SESSION['uid'] = intval($_user['uid']);
        admin_token_set(intval($_user['uid'])); // S-7：显式绑定签发 uid
        // 同步前台登录态：后台路由权限（group_access managexxx）基于前台会话 gid，
        // 仅发 admin_token 会导致后台登录后所有管理页面"权限不足"
        user_token_set(intval($_user['uid']));
        admin_audit_log('login_success', array('target_uid' => intval($_user['uid'])), 'admin_login');

        return array(
            'code' => 0,
            'message' => jump(lang('login_successfully'), $conf['admin_entry'] ?? 'admin.php'),
        );
    }

    /**
     * 后台登出（case 'logout'，GET/POST 均接受）。
     * 过 admin_logout 场景 token 校验后清 admin_token + 前台 user_token、会话 uid 归 0 并轮换会话 id。
     *
     * @param mixed $uid  当前会话 uid（token 校验主体）
     * @param array $conf 站点配置（读 conf['path'] 作登出跳转目标）
     * @return array {code:0, message}（含 jump() 跳转脚本）
     */
    public static function logout($uid, array $conf): array
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, 'admin_logout', $safeToken);

        admin_audit_log('logout');
        admin_token_clean();
        user_token_clear();
        // 与前台登出对称：清除会话 uid 并轮换会话 id
        $_SESSION['uid'] = 0;
        session_regenerate_id(true);
        return array(
            'code' => 0,
            'message' => jump(lang('logout_successfully'), $conf['path']),
        );
    }

    /**
     * 后台仪表盘视图数据（默认路由，须过 intoadmin 门禁）。
     * 先清理 art/vod 的 Top 历史（30 天窗口），再把「趋势 + 最近动态」两块只读聚合走
     * 120s 多层缓存键 admin_dashboard_series_v1（P-11/P-3；改结构须升键版本）；
     * 汇总 partial_failed 交模板提示，避免失败模块被当成「本来就是 0」。
     *
     * @param object $db      数据库驱动（PdoDriver），仅取 ->version() 展示
     * @param array  $runtime runtime_init() 的全局计数（读 users 键，缺省 0）
     * @return array {header, recent, trend, partial_failed, info(环境项), stat(站点计数)}
     */
    public static function dashboardPage($db, array $runtime): array
    {
        \App\Service\Content\ArtService::clearTopHistory(30);
        \App\Service\Content\VideoService::clearTopHistory(30);

        // 仪表盘数据装配：趋势与最近动态各自返回「是否部分失败」，
        // 汇总成 partial_failed 交给模板提示，避免失败模块被当成「本来就是 0」（代码审查 #4）
        // P-11/P-3（2026-10-10）：两块只读聚合（趋势 14 条 COUNT + 最近动态 3 次列表查询）
        // 走 120s 多层缓存——后台数据可容忍 2 分钟滞后，冷命中路径与原逻辑完全一致。
        $seriesKey = 'admin_dashboard_series_v1';
        $series = cache_get($seriesKey);
        if (is_array($series) && 4 === count($series)) {
            list($trend, $trendPartial, $recent, $recentPartial) = $series;
        } else {
            list($trend, $trendPartial) = self::trendSeries(7);
            list($recent, $recentPartial) = self::recentActivities(8);
            cache_set($seriesKey, array($trend, $trendPartial, $recent, $recentPartial), 120);
        }

        return array(
            'header' => array(
                'title' => lang('admin_page'),
            ),
            'recent' => $recent,
            'trend' => $trend,
            'partial_failed' => ($trendPartial || $recentPartial),
            'info' => array(
                'disable_functions' => self::disableFunctions(),
                'allow_url_fopen' => ini_get('allow_url_fopen') ? lang('yes') : lang('no'),
                'safe_mode' => ini_get('safe_mode') ? lang('yes') : lang('no'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'dbversion' => $db->version(),
                'SERVER_SOFTWARE' => _SERVER('SERVER_SOFTWARE'),
                'HTTP_X_FORWARDED_FOR' => _SERVER('HTTP_X_FORWARDED_FOR'),
                'REMOTE_ADDR' => _SERVER('REMOTE_ADDR'),
            ),
            'stat' => array(
                'art' => art_count(),
                'vod' => video_count(),
                'tag' => tag_count(),
                'users' => isset($runtime['users']) ? $runtime['users'] : 0,
                'disk_free_space' => function_exists('disk_free_space') ? humansize(disk_free_space(\APP_PATH)) : lang('unknown'),
            ),
        );
    }

    /**
     * 仪表盘「最近动态」：最新视频/文章/新注册用户按时间合并
     */
    /**
     * 最近动态（视频/文章/用户各取最近几条，按时间归并）。
     *
     * 代码审查 #4 修复：三个来源原先都是空 `catch (\Throwable $e) {}`——任何一个模块出错
     * （表缺失、权限、SQL 方言问题）都会被**静默吞掉**，后台看到的只是「这项没有数据」，
     * 排障信息为零。现在保留「单模块失败不拖垮首页」的设计，但补上日志留痕，
     * 并把 partial 标记回传调用方。
     *
     * @param int $limit
     * @return array{0:array,1:bool} 动态列表 + 是否部分失败
     */
    private static function recentActivities(int $limit = 8): array
    {
        $out = array();
        $v = 5;
        $a = 4;
        $partial = FALSE;
        $fail = function (string $src, \Throwable $e) use (&$partial) {
            $partial = TRUE;
            if (function_exists('xn_log')) {
                xn_log('dashboard recent query failed [' . $src . ']: ' . $e->getMessage(), 'dashboard_error');
            }
        };
        try {
            // SQL 专项审计修复（R10/P0-2）：仪表盘只需 vid/name/create_date，排除 play_url(均值5.4KB)+content
            $vods = \App\Repository\RepositoryFactory::table('vod', 'vid')->find(array(), array('create_date' => -1), 1, $v, 'vid', array('vid', 'name', 'create_date'));
            if (is_array($vods)) {
                foreach ($vods as $_v) {
                    $out[] = array('type' => 'vod', 'name' => (string)($_v['name'] ?? ''), 'time' => intval($_v['create_date'] ?? 0));
                }
            }
        } catch (\Throwable $e) { $fail('vod', $e); }
        try {
            // SQL 专项审计修复（R10）：仪表盘只需 aid/name/create_date，排除 content(longtext)
            // 注：下方消费键为 $_a['title']，而 art 表列名为 name ⇒ 一并修正为 name（原读 title 恒空）
            $arts = \App\Repository\RepositoryFactory::table('art', 'aid')->find(array(), array('create_date' => -1), 1, $a, 'aid', array('aid', 'name', 'create_date'));
            if (is_array($arts)) {
                foreach ($arts as $_a) {
                    $out[] = array('type' => 'art', 'name' => (string)($_a['name'] ?? ''), 'time' => intval($_a['create_date'] ?? 0));
                }
            }
        } catch (\Throwable $e) { $fail('art', $e); }
        try {
            // SQL 专项审计修复（R10）：显式列（原 SELECT * 拉 17 列）
            $users = \App\Repository\RepositoryFactory::user()->find(array(), array('uid' => -1), 1, 3, 'uid', array('uid', 'username', 'create_date'));
            if (is_array($users)) {
                foreach ($users as $_u) {
                    $out[] = array('type' => 'user', 'name' => (string)($_u['username'] ?? ''), 'time' => intval($_u['create_date'] ?? 0));
                }
            }
        } catch (\Throwable $e) { $fail('user', $e); }
        usort($out, function ($x, $y) { return $y['time'] <=> $x['time']; });
        return array(array_slice($out, 0, $limit), $partial);
    }

    /**
     * 仪表盘「近 N 天内容入库」趋势。
     *
     * 代码审查 #2 修复：原实现是「每表拉最多 10000 行（仅 create_date）→ PHP 内存分组计数」。
     * 两个问题：① 大站每天入库上千时 10000 行会被静默截断，图表数字偏小且**无任何提示**；
     * ② 把 N 天全量行搬到 PHP，纯属浪费（这些计数数据库用索引就能算）。
     *
     * 现改为**按天开区间计数**：`create_date` 是 int 时间戳且有单列索引
     * （install.sql 的 KEY `create_date`），每天一个 `COUNT(*)` 走索引区间，天然**精确**——
     * 既不存在截断上限，也不需要 GROUP BY + 日期函数（后者还得分 MySQL/PG/SQLite 三套方言写法）。
     * 代价是每表 N 次轻量查询（N≤30，即最多 60 次索引计数），远低于搬 10000 行的开销。
     *
     * 单天查询失败不再静默：记日志并置 partial=true，前端据此提示「数据不完整」，
     * 避免把真实故障伪装成「那天本来就 0 条」。
     *
     * @param int $days 3..30
     * @return array{0:array,1:bool} 趋势序列 + 是否部分失败
     */
    private static function trendSeries(int $days = 7): array
    {
        $days = max(3, min(30, $days));
        $today = strtotime(date('Y-m-d'));
        $start = $today - ($days - 1) * 86400;
        $week = array('日', '一', '二', '三', '四', '五', '六');
        $map = array();
        $bounds = array(); // 键 => array(日起点, 日终点)
        for ($i = 0; $i < $days; ++$i) {
            $ts = $start + $i * 86400;
            $k = date('m-d', $ts);
            $map[$k] = array('d' => $k, 'w' => $week[intval(date('w', $ts))], 'vod' => 0, 'art' => 0);
            $bounds[$k] = array($ts, $ts + 86399);
        }

        $partial = FALSE;
        // 表名 => 趋势字段（vod 表用 vid 主键、art 表用 aid；此处只需计数，主键仅用于表定位）
        foreach (array('vod' => 'vod', 'art' => 'art') as $table => $field) {
            try {
                $repo = \App\Repository\RepositoryFactory::table($table, $table === 'vod' ? 'vid' : 'aid');
                foreach ($bounds as $k => $rg) {
                    // 闭区间 [日起点, 日终点]：whereBetween 由 applyCond 的 >= / <= 组合表达
                    $map[$k][$field] = $repo->count(array(
                        'create_date' => array('>=' => $rg[0], '<=' => $rg[1]),
                    ));
                }
            } catch (\Throwable $e) {
                // 不再空 catch：真实故障要留痕，并让前端知道数据不完整
                $partial = TRUE;
                if (function_exists('xn_log')) {
                    xn_log('dashboard trend query failed [' . $table . ']: ' . $e->getMessage(), 'dashboard_error');
                }
            }
        }

        $maxv = 0;
        foreach ($map as $m) {
            $maxv = max($maxv, $m['vod'], $m['art']);
        }
        foreach ($map as $k => $m) {
            $map[$k]['p_vod'] = $maxv ? (int)round($m['vod'] / $maxv * 100) : 0;
            $map[$k]['p_art'] = $maxv ? (int)round($m['art'] / $maxv * 100) : 0;
        }
        return array(array_values($map), $partial);
    }

    /**
     * 登录取用户：入参既可能是邮箱也可能是用户名（is_email 判定分流 user_read_by_email / by_username）。
     * 枚举防护：查无此人时以哑 bcrypt 比对拉平「不存在 vs 密码错」时序，并统一报「密码错误」终止，
     * 不向攻击者暴露账号是否存在。
     *
     * @param string $email 邮箱或用户名（已由 login() 侧 filter_all_html 转义）
     * @return array 命中的用户整行；未命中经 message() 中断请求（不返回空数组）
     */
    private static function findUserForLogin(string $email): array
    {
        // 枚举防护：账号不存在与密码错误统一为「密码错误」文案，并以哑 bcrypt 比对拉平时序
        if (is_email($email, $err)) {
            $_user = user_read_by_email($email);
            if (empty($_user)) {
                password_verify($email, '$2y$12$qjmeqFrQYlWr7xeVfBY7ZOXeHzhCyOA7I6beMFOxnbLzDlM9QtdMe');
                admin_audit_log('login_failed', array('target_uid' => 0), 'admin_login_error');
                message('password', lang('password_incorrect'));
            }
            return $_user;
        }

        $_user = user_read_by_username($email);
        if (empty($_user)) {
            password_verify($email, '$2y$12$qjmeqFrQYlWr7xeVfBY7ZOXeHzhCyOA7I6beMFOxnbLzDlM9QtdMe');
            admin_audit_log('login_failed', array('target_uid' => 0), 'admin_login_error');
            message('password', lang('password_incorrect'));
        }
        return $_user;
    }

    /**
     * 校验用户密码（password_verify_custom，兼容旧式散列）。
     * 命中旧式 MD5 散列（32 位十六进制）且验通过时，顺带自动迁移为 bcrypt 并清 salt（与前台登录同口径）；
     * 校验失败写 admin_login_error 审计并以「密码错误」终止（fail-closed，不降级放行）。
     *
     * @param array  $_user  findUserForLogin 取到的用户整行（读 password/salt/uid）
     * @param string $password 明文口令
     * @return void 校验通过静默返回（迁移为副作用）
     */
    private static function verifyPassword(array $_user, string $password): void
    {
        $storedHash = (string)$_user['password'];
        $storedSalt = (string)$_user['salt'];
        $check = password_verify_custom($password, $storedHash, $storedSalt);

        // 旧式 MD5 登录成功后自动迁移 bcrypt（与前台登录同口径，统一验密函数）
        if ($check && strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            user_update($_user['uid'], array('password' => $newHash, 'salt' => ''));
        }

        if (!$check) {
            admin_audit_log('login_failed', array('target_uid' => intval($_user['uid'])), 'admin_login_error');
            message('password', lang('password_incorrect'));
        }
    }

    /**
     * 读取 PHP disable_functions 配置项供仪表盘展示。
     *
     * @return string 已禁用函数清单原样；ini 为空串时返回 lang('none')
     */
    private static function disableFunctions(): string
    {
        $disableFunctions = ini_get('disable_functions');
        return $disableFunctions !== '' ? $disableFunctions : lang('none');
    }
}
