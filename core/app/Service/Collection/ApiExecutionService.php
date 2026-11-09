<?php
namespace App\Service\Collection;

class ApiExecutionService
{
    /**
     * 计划任务心跳（机器通道 api.php 与后台手动触发共用）：cron1 到期（或 $force）时把
     * conf['api'] 里每个启用源按源拆分入队 TaskQueueService（cron_source 任务，异步逐源执行，
     * cron_1_last_date=+1500s。cron2 分支把 cron_2_last_date 刷到明日 0 点实现每日一次
     * （⚠ 已修：原第三参 TRUE 被 int $ttl coerce 成 1 秒 TTL，日频形同虚设，现与 cron1 同样不传 TTL）。
     * 本身不直接出网采集。
     * @param int $force 非 0=忽略 cron_1_last_date 强制入队；0=按到期判定
     * @return array 入队结果；尚未到触发时间或其他请求持锁时为空。
     */
    public static function cronRun($force = 0, int $hours = 24)
    {
        $conf = _SERVER('conf');         $time = _SERVER('time');         global $runtime;
        $tasks = array();
        $cron1LastDate = cache_get('cron_1_last_date');
        $cron2LastDate = cache_get('cron_2_last_date');
        if ($time > $cron1LastDate || $force) {
            $lock = cache_get('cron_lock_1');
            if (NULL === $lock) {
                cache_set('cron_lock_1', 1, 10);
                try {
                    sess_gc($conf['online_hold_time']);
                    $runtime['onlines'] = max(1, online_count());
                    $apilist = $conf['api'];
                    // 队列化：计划任务按源拆分入队（异步逐源执行，避免同步全量超时）
                    if (is_array($apilist)) {
                        foreach ($apilist as $k => $v) {
                            if (empty($v['api']) || empty($conf['bind'][$k])) continue;
                            $tasks = array_merge($tasks, CollectionScheduleService::enqueue(array((string)$k), $hours));
                        }
                    }
                    cache_set('cron_1_last_date', ($time + 1500));
                } finally {
                    cache_delete('cron_lock_1');
                }
            }
        }
        if ($time > $cron2LastDate || $force) {
            $lock = cache_get('cron_lock_2');
            if (NULL === $lock) {
                cache_set('cron_lock_2', 1, 10);
                $today = strtotime(date('Ymd')) + 86400;
                // ⚠ 修正：原写 cache_set(..., TRUE)，但 cache_set 第三参是 int $life（TTL 秒），
                //   TRUE 被 coerce 成 **1 秒 TTL** ⇒ 键每秒即过期，上面的 `$time > $cron2LastDate`
                //   每请求都成立，「每日一次」实际不成立（代价只是每请求多一次 SET，所以一直没被发现）。
                //   现与同族 cron1 对齐：不传 TTL（TTL=0 ⇒ RedisDriver::set 走永不过期分支），
                //   「每日」由**值本身**（明日 0 点时间戳）+ 上面的时间比较实现。
                cache_set('cron_2_last_date', $today);
                cache_delete('cron_lock_2');
            }
        }
        return $tasks;
    }

    /**
     * 以机器通道令牌触发一次云端 rsync 启动请求（h=24 窗口、count=10、第 1 页），
     * 走 rsyncUrl() 拼商店端点、https_request 出网，响应不取回（fire-and-forget）。
     * @param string $fromCode 采集源编码（conf['api'] 的键）
     * @return void
     */
    /**
     * 后台「异步采集」动作分发（api.php 路由 case rsync 调用，动作类已由路由过 CSRF 闸）：
     * start/stop/restart 转发对应方法并写 admin_audit_log；see/seeall 查询任务状态；
     * 其余 $type 返回 mode=none。调用方按 mode 决定跳转（redirect）或渲染（view）。
     * @param string $type 动作：start|stop|restart|see|seeall|其他
     * @param string $from_code 采集源编码
     * @return array {mode: 'redirect', msg} 或 {mode: 'view', res} 或 {mode: 'none'}
     */
    public static function rsync(string $type, string $from_code): array
    {
        switch ($type) {
            case 'start':
                admin_audit_log('api_rsync_start', array('code' => $from_code));
                return array('mode' => 'redirect', 'msg' => self::rsyncMsg(self::rsyncStart($from_code), '启动'));
            case 'stop':
                admin_audit_log('api_rsync_stop', array('code' => $from_code));
                return array('mode' => 'redirect', 'msg' => self::rsyncMsg(self::rsyncStop($from_code), '停止'));
            case 'restart':
                admin_audit_log('api_rsync_restart', array('code' => $from_code));
                return array('mode' => 'redirect', 'msg' => self::rsyncMsg(self::rsyncRestart($from_code), '重启'));
            case 'see':
                return array('mode' => 'view', 'res' => self::rsyncInfoCode($from_code));
            case 'seeall':
                return array('mode' => 'view', 'res' => self::rsyncInfo($from_code));
            default:
                return array('mode' => 'none');
        }
    }

    /**
     * 异步动作结果消息：原实现 `$r['msg'] ?? ''` —— 失败路径（未配采集源、源站页数取不到、
     * 商店不可达/任务不存在）全部回空串，后台只是"静默跳回去"，看起来像按钮没反应。改为始终有话可说。
     */
    private static function rsyncMsg($r, string $action): string
    {
        if (is_array($r) && !empty($r['msg'])) return (string)$r['msg'];
        return $action . '失败：商店无响应或任务不存在（请检查 cloud_api 配置与商店 rsync 端点/worker）';
    }

    /** 模态框操作：暂停复用云端保留断点的 stop，启动会从断点继续。 */
    public static function cloudAction(string $action, string $code): array
    {
        if (!in_array($action, ['register','start','pause','stop','status'], true)
            || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $code)) {
            return ['code'=>1, 'message'=>'操作或采集源无效'];
        }
        $conf = _SERVER('conf');
        if (empty($conf['api'][$code]['api']) || empty($conf['bind'][$code])) {
            return ['code'=>1, 'message'=>'请先选择分类并保存采集配置'];
        }
        if (!outbound_url_safe(url_domain())) {
            return ['code'=>1, 'message'=>'当前站点地址无法供云端访问。请部署到支持海外访问的公网域名后，再注册和使用云端异步采集。'];
        }
        if ($action === 'register') {
            $r = \App\Service\System\CloudOpsService::storeInstallRegister(true);
            admin_audit_log('api_cloud_register', ['ok'=>!empty($r['ok'])]);
            return ['code'=>!empty($r['ok']) ? 0 : 1, 'message'=>!empty($r['ok']) ? '本站已注册到云端，可以启动采集。' : ($r['msg'] ?? '注册失败，请重试')];
        }
        if ($action === 'status') {
            $token = function_exists('store_wire_token') ? (string)store_wire_token() : (string)api_wire_token('api');
            $taskCode = md5($token . $code . 'all');
            $r = self::remoteSyncBase(self::rsyncUrl('tasks', 'status', 'token=' . urlencode($token) . '&code=' . $taskCode));
            if (!is_array($r) || (!isset($r['progress']) && !isset($r['total']))) {
                return ['code'=>1, 'message'=>(string)($r['msg'] ?? '未查询到任务，请先注册并启动采集')];
            }
            return ['code'=>0, 'message'=>'进度已更新', 'task'=>self::normalizeRsyncTask($r, $code)];
        }
        $r = $action === 'start' ? self::rsyncStart($code) : self::rsyncStop($code);
        $msg = is_array($r) ? trim((string)($r['msg'] ?? '')) : '';
        $ok = is_array($r) && (isset($r['code']) ? (int)$r['code'] === 0 : (bool)preg_match('/^(ok|success|running|pending|stopped)$/i', $msg));
        admin_audit_log('api_cloud_' . $action, ['source'=>$code, 'ok'=>$ok]);
        $labels = ['start'=>'已请求启动，已有断点时将继续采集。', 'pause'=>'已请求暂停，云端在分页结束后停下并保留断点。', 'stop'=>'已请求停止，云端保留采集进度。'];
        return ['code'=>$ok ? 0 : 1, 'message'=>$ok ? $labels[$action] : ($msg !== '' ? $msg : '云端未确认操作，请稍后重试或查看进度')];
    }

    /**
     * 异步任务行归一（真机实测形状差异）。
     *
     * 商店两个 status 视图**形状不同**，而模板只按一种写：
     *   列表 `?api=rsync/status&token=`        → `[{id,token,`**`From_code`**`,page,`**`count`**`,progress,`**`status`**`,fail,last_error}]`
     *   单个 `?api=rsync/status&token=&code=`  → `{progress,`**`msg`**`(=状态),`**`total`**`(=总页数),fail,last_error}`
     * 后果：`seeall` 的「资源来源」列恒空（大小写不符）；`see` 的 总页数=0、剩余页为负、状态列空白，
     * 且单任务对象被 `foreach` 当列表遍历 ⇒ 渲染出一堆垃圾行。这里统一成模板认识的形状。
     */
    private static function normalizeRsyncTask($row, string $code = ''): array
    {
        if (!is_array($row)) return array();
        return array(
            'from_code'  => (string)($row['from_code'] ?? ($row['From_code'] ?? $code)),
            'progress'   => intval($row['progress'] ?? 0),
            'count'      => intval($row['count'] ?? ($row['total'] ?? 0)),
            'status'     => (string)($row['status'] ?? ($row['msg'] ?? '')),
            'fail'       => intval($row['fail'] ?? 0),
            'last_error' => (string)($row['last_error'] ?? ''),
            'page'       => intval($row['page'] ?? 1),
        );
    }

    /**
     * 后台「今日/本周/全部采集」视频入口：写 api_collect_video 审计后转发 runPagedApi
     * （页执行器 videoApiPage、书签后缀 pg、成功回跳路由 api-list）。
     * @param string $type 采集窗口 day|week|all（其余值 runPagedApi 直接判 format error）
     * @param string $api 采集源 api 地址（core_decrypt 密文或明文 http(s)，见 fetchJson）
     * @param string $code 采集源编码
     * @param int $page 起始页码
     * @param int $uid 操作者 UID（下一页 safe_token 派生用）
     * @return array runPagedApi 的三态结果（error/done/待跳转 resstr+jump）
     */
    public static function videoApi(string $type, string $api, string $code, int $page, int $uid = 0, int $batch = 1): array
    {
        admin_audit_log('api_collect_video', array('type' => $type, 'code' => $code, 'page' => $page, 'batch' => $batch));
        return self::runPagedApi($type, $api, $code, $page, 'videoApiPage', 'pg', 'api-list', $uid, $batch);
    }

    /**
     * 后台图文分页采集入口：写 api_collect_art 审计后转发 runPagedApi
     * （页执行器 artApiPage、书签后缀 artpg、成功回跳路由 api-art_list）。
     * @param string $type 采集窗口 day|week|all
     * @param string $api 采集源 api 地址（密文或明文 http(s)）
     * @param string $code 采集源编码
     * @param int $page 起始页码
     * @param int $uid 操作者 UID（下一页 safe_token 派生用）
     * @return array runPagedApi 的三态结果
     */
    public static function artApi(string $type, string $api, string $code, int $page, int $uid = 0, int $batch = 1): array
    {
        admin_audit_log('api_collect_art', array('type' => $type, 'code' => $code, 'page' => $page, 'batch' => $batch));
        return self::runPagedApi($type, $api, $code, $page, 'artApiPage', 'artpg', 'api-art_list', $uid, $batch);
    }

    /**
     * 启动云端异步采集任务：读分页书签（pageBookmarkKey，越界钳回 1）+ apiPageCount 取全量总页数，
     * 以 taskCode=md5(token.源码.'all') 请求商店 rsync/start（空 h = 不限窗口，与 count 同口径，
     * 窗口化改造要求见体内注释）。所有失败路径都返回带 msg 的数组（不再回 FALSE/空 ⇒ 后台静默）。
     * @param string $code 采集源编码（conf['api'] 的键）
     * @return array 商店 JSON 响应原样透传，或本地兜底 array('msg' => string)
     */
    public static function rsyncStart(string $code)
    {
        $conf = _SERVER('conf');
        // token 派生化：出网 URL 一律用 api_wire_token（auth_key 不再出现在 URL/日志/云端存储）
        $token = function_exists('store_wire_token') ? (string)store_wire_token() : (string)api_wire_token('api');
        $fromCode = $code;
        $encodeurl = isset($conf['api'][$code]['api']) ? $conf['api'][$code]['api'] : '';
        // 修复：失败路径给**明确文案**（原返回 FALSE/array() ⇒ 后台静默跳回，像按钮没反应）
        if ('' === $encodeurl) {
            return array('msg' => '该采集源尚未加入本站（请先「一键采集」后再启动异步采集）');
        }
        // 上线前审计修复（H-A1）：$code 直拼缓存键与全站共享键空间碰撞，统一加前缀。
        // 发布前检查修复（A-6）：键口径收敛 pageBookmarkKey()（原此处丢 'pg' 后缀与写侧失配=续页恒空）
        $model = (int)($conf['api'][$code]['model'] ?? 0);
        $page = (int)cache_get(self::pageBookmarkKey($code, $model === 1 ? 'artpg' : 'pg'));
        $allPage = self::apiPageCount($encodeurl, $model);
        $taskCode = md5($token . $fromCode . 'all');
        if ($allPage != lang('api_err')) {
            // 修复：书签缺失时 cache_get 返回 null，旧实现直接拼出 `page=`（空串）⇒
            // 商店 `param_int('page')` 对"键存在但空"取 (int)''=0 ⇒ 403 `page 必须为正整数`，
            // 后台「异步采集」按钮 100% 失败。商店契约（API.md §6.1）要求 1 ≤ page ≤ count，故此处夹紧。
            if ($page < 1 || $page > (int)$allPage) $page = 1;
            // 口径统一：`apiPageCount()` 用 `h=''` 取的是**全量页数**，故此处**不传 `h`**
            // （商店存空 = 不限窗口），worker 回调时透传空 h ⇒ 每页覆盖范围与 count 同口径。
            // 若日后要把「异步采集」改成按窗口增量（如只拉最近 24h/168h），必须**同时**：
            //   ① 本查询带上 `&h=24`（写进 rsync_tasks.h，worker 回调透传）
            //   ② 用同一个窗口算 count（apiPageCount 需接受 h 参数），否则又会「分母全量/分子窗口」错位。
            $query = "token=$token&from_code=$fromCode&count=$allPage&page=$page&code=$taskCode";
            $r = self::remoteSyncBase(self::rsyncUrl('api', 'start', $query));
            return is_array($r) ? $r : array('msg' => '启动失败：商店 rsync 端点无响应');
        }
        return array('msg' => '启动失败：取不到源站总页数（源 API 不可达或返回异常），请确认采集源可用');
    }

    /**
     * 停止云端异步采集：按 taskCode=md5(token.源码.'all') 请求商店 rsync/stop。
     * 失败不拦截——响应直接透传给上层 rsyncMsg 兜底成可读文案。
     * @param string $code 采集源编码
     * @return mixed 商店 JSON 解码结果（期望 array；URL 非法/非 JSON 时为 array()/null）
     */
    public static function rsyncStop(string $code)
    {
        $conf = _SERVER('conf');
        // token 派生化：出网 URL 一律用 api_wire_token（auth_key 不再出现在 URL/日志/云端存储）
        $token = function_exists('store_wire_token') ? (string)store_wire_token() : (string)api_wire_token('api');
        $fromCode = $code;
        $taskCode = md5($token . $fromCode . 'all');
        return self::remoteSyncBase(self::rsyncUrl('api-stop', 'stop', "token=$token&code=$taskCode"));
    }

    /**
     * 重启云端异步采集：按 taskCode 请求商店 rsync/restart，并带上 from_code 供商店重建任务。
     * @param string $code 采集源编码
     * @return mixed remoteSyncBase 直传（期望 array；URL 非法/JSON 坏时可为空数组/null）
     */
    public static function rsyncRestart(string $code)
    {
        $conf = _SERVER('conf');
        // token 派生化：出网 URL 一律用 api_wire_token（auth_key 不再出现在 URL/日志/云端存储）
        $token = function_exists('store_wire_token') ? (string)store_wire_token() : (string)api_wire_token('api');
        $fromCode = $code;
        $taskCode = md5($token . $fromCode . 'all');
        return self::remoteSyncBase(self::rsyncUrl('api-restart', 'restart', "token=$token&code=$taskCode&from_code=$fromCode"));
    }

    /**
     * 异步任务**列表**（`?api=rsync/status&token=`，商店返回 JSON 数组）——归一到模板形状。
     * 无任务/端点异常时返回空数组（保证模板 foreach 安全）。
     */
    public static function rsyncInfo(string $code)
    {
        // token 派生化：出网 URL 一律用 api_wire_token（auth_key 不再出现在 URL/日志/云端存储）
        $token = function_exists('store_wire_token') ? (string)store_wire_token() : (string)api_wire_token('api');
        $res = self::remoteSyncBase(self::rsyncUrl('tasks', 'status', "token=$token"));
        if (!is_array($res)) return array();
        $out = array();
        foreach ($res as $row) {
            $n = self::normalizeRsyncTask($row, $code);
            if (!empty($n)) $out[] = $n;
        }
        return $out;
    }

    /**
     * 异步任务**单条**（`?api=rsync/status&token=&code=`）。
     * 商店返回的是**单个对象** `{progress,msg,total,fail,last_error}`，而模板按列表 foreach ——
     * 直接返回对象会把它当列表遍历出 5 行垃圾；故这里统一包成 1 元素列表并归一键名。
     */
    public static function rsyncInfoCode(string $code)
    {
        // token 派生化：出网 URL 一律用 api_wire_token（auth_key 不再出现在 URL/日志/云端存储）
        $token = function_exists('store_wire_token') ? (string)store_wire_token() : (string)api_wire_token('api');
        $taskCode = md5($token . $code . 'all');
        $res = self::remoteSyncBase(self::rsyncUrl('tasks', 'status', "token=$token&code=$taskCode"));
        if (!is_array($res)) return array();
        $n = self::normalizeRsyncTask($res, $code);
        return empty($n) ? array() : array($n);
    }

    /**
     * 云端异步采集基址（B3）：conf['cloud_api']（商店服务端，明文直连）优先；
     * 未配置时回落旧云端加密地址（core_decrypt）。store 模式下端点映射：
     * api→rsync/start、api-stop→rsync/stop、api-restart→rsync/restart、tasks→rsync/status。
     */
    private static function rsyncIsStore()
    {
        $conf = _SERVER('conf');
        return '' !== trim(isset($conf['cloud_api']) ? (string)$conf['cloud_api'] : '');
    }

    /**
     * 云端异步采集基址（B3）：取 conf['cloud_api'] 归一为带尾斜杠的 URL 并做 host 白名单
     * 校验——基址决定 api_wire_token('api') 派生令牌的出网去向（可无鉴权驱动 cron/rsync 写库），
     * scheme 仅放行 http(s)、host 必须等于 cloud_api 主机或其子域（审计 M-A3）。
     * 旧云端 legacy 分支已移除：未配置或校验失败一律 fail-closed 返回空串，调用方据此不发请求。
     * @return string 合法基址（形如 https://host/）；不可用为 ''
     */
    private static function rsyncBase()
    {
        $conf = _SERVER('conf');
        $cloudApi = trim(isset($conf['cloud_api']) ? (string)$conf['cloud_api'] : '');
        // ：legacy 分支（core_decrypt(codes()['rsync']) + 官方域名白名单）已移除——
        // 官方旧云端不再使用；未配 cloud_api 时直接 fail-closed 返回空（云采集不可用是显式的）。
        if ('' === $cloudApi) return '';
        $base = rtrim($cloudApi, '/') . '/';
        $allowedHosts = array(strtolower((string)parse_url($base, PHP_URL_HOST)));
        // 上线前审计修复（M-A3）：出网基址域名白名单——基址决定 api_wire_token('api')
        // 派生的命中去向（可无鉴权驱动 cron/rsync 写库）；白名单=管理员显式配置的 cloud_api 主机。
        // 校验失败 fail-closed 返回空。
        if ('' === trim((string)$base)) return '';
        $u = parse_url((string)$base);
        $scheme = strtolower((string)($u['scheme'] ?? ''));
        $host = strtolower((string)($u['host'] ?? ''));
        if (!in_array($scheme, array('http', 'https'), TRUE) || '' === $host) return '';
        $hostOk = FALSE;
        foreach ($allowedHosts as $allow) {
            if ('' === $allow) continue;
            if ($host === $allow || substr($host, -strlen('.' . $allow)) === '.' . $allow) { $hostOk = TRUE; break; }
        }
        if (!$hostOk) {
            if (function_exists('xn_log')) xn_log('rsync base host not allowed: ' . $host, 'api_rsync_error');
            return '';
        }
        return $base;
    }

    /** 由旧端点路径+查询串构造最终 URL（store 模式映射到 index.php?api=rsync/*，参数零改动） */
    private static function rsyncUrl($legacyPath, $storeEp, $query)
    {
        $base = self::rsyncBase();
        if ('' === trim($base)) return '';
        if (self::rsyncIsStore()) return $base . 'index.php?api=rsync/' . $storeEp . '&' . $query;
        return $base . $legacyPath . '?' . $query;
    }

    /** 明文基址请求（store 模式；来源基址在 fetchJson/fetchVideoJson 内经 core_decrypt 解出后直连） */
    private static function remoteSyncBase($url, bool $allowRepair = TRUE)
    {
        if ('' === trim((string)$url)) return array();
        $html = https_request($url, '', '', 30, 0, 8 * 1048576, 'cloud'); // Explicit cloud control request
        $arr = xn_json_decode($html);
        // token 自愈：商店返回「无效 token / 站点未启用」时，尝试按当前 auth_key 补登记一次并重试。
        // 场景：安装时漏登记、历史 auth_key 变更后商店里还是旧 token。只重试一次，避免坏配置死循环。
        if ($allowRepair && is_array($arr)) {
            $msg = trim((string)($arr['msg'] ?? ''));
            if ('' !== $msg && preg_match('#无效 token|站点未启用#u', $msg)) {
                $repair = \App\Service\System\CloudOpsService::storeInstallRegister();
                if (!empty($repair['ok'])) {
                    return self::remoteSyncBase($url, FALSE);
                }
            }
        }
        return $arr;
    }

    /**
     * 采集分页书签缓存键统一口径（发布前检查修复 A-6/F-Critical：H-A1 只改了 rsync 读侧且丢失
     * 'pg' 后缀，与写侧 runPagedApi（原裸键 $code.'pg'）及 api_list.html「继续采集」读侧三处
     * 键形不一致——rsync 续页恒读空。收敛单源：api_pg_ 前缀隔离共享键空间 + 后缀区分 vod/art）。
     */
    public static function pageBookmarkKey(string $code, string $suffix = 'pg'): string
    {
        return 'api_pg_' . preg_replace('#[^A-Za-z0-9_-]#', '_', $code) . $suffix;
    }

    /**
     * 分页采集通用驱动（videoApi/artApi 的共用体）：$type 映射小时窗口
     * （day→24/week→168/all→'' 全量，非法值直接回 error）；调 $runner 执行当页导入，
     * 结果行 implode('<br>') 后以「整串 == lang('api_ok')」判完成。
     * 未完成时：$type=all 把当前页写入分页书签 pageBookmarkKey($code,$cacheSuffix)
     * （api_err 页码原地不前进，可重试）；返回 resstr+jump=下一页 URL
     * （带 admin_api_exec 场景 safe_token，绑定 $uid）。
     * @param string $type 采集窗口 day|week|all
     * @param string $api 采集源 api 地址
     * @param string $code 采集源编码
     * @param int $page 当前页码
     * @param string $runner 页执行器（本类静态方法名 videoApiPage/artApiPage）
     * @param string $cacheSuffix 书签后缀 pg|artpg
     * @param string $successUrl 完成后回跳路由 api-list|api-art_list（同时决定 jump 路由名）
     * @param int $uid 操作者 UID
     * @return array 三态：{error,msg} | {done,msg,success_url} | {done:false,resstr,jump}
     */
    private static function runPagedApi(string $type, string $api, string $code, int $page, string $runner, string $cacheSuffix, string $successUrl, int $uid = 0, int $batch = 1): array
    {
        $hoursMap = array(
            'day' => '24',
            'week' => '168',
            'all' => '',
        );
        if (!array_key_exists($type, $hoursMap)) {
            return array('error' => true, 'msg' => lang('data_malformation'));
        }

        // 采集地址一律按 code 取已保存配置（ApiSourceService::set 已做协议校验），不信任 URL 参数里的 api——
        // 否则保存侧校验形同虚设，可直接 ?api=gopher://… 让服务端出网
        $conf = _SERVER('conf');
        $api = isset($conf['api'][$code]['api']) ? (string)$conf['api'][$code]['api'] : '';
        if ('' === ApiSourceService::resolveUrl($api)) {
            return array('error' => true, 'msg' => '采集源不存在或地址无效，请先在采集列表中设置该采集源');
        }

        $h = $hoursMap[$type];
        $t = '';
        // ⚠ 批处理（2026-10 采集提速）：逐页通路的真实瓶颈不是入库，而是**每页一次的浏览器往返**：
        //   跳转延迟固定 3s（jump 默认）＋整页加载。真机实测单页服务端仅 1.1s（远端取页 0.95s 占 87%、
        //   本地入库 20 条 0.14s），却因 3s 延迟变成 ~4.1s/页。这里改成**一次请求内连采 $batch 页**
        //   （服务端顺序循环，仍逐页请求源站，不并发、不改每页间隔），把 N-1 次延迟与页面加载省掉。
        //   $batch 由 route 的 pages 参数给出（1..10，默认 1=旧行为）。
        $batch = max(1, min(10, $batch));
        // 墙钟预算：单请求最多在服务端连采 ~20s 就收（后续页由 jump 在新请求里继续）。
        // 目的有二：① 不长时间占住一个 FPM worker（源站卡住时每页 curl 超时 30s）；
        // ② 让「连采」与「进度可见」兼得——用户每 ~20s 仍能看到一次结果页。
        $budget = 20.0;
        $t0 = microtime(true);
        $lines = array();
        $nextp = $page;
        $lastDone = $page;
        $err = false;
        for ($i = 0; $i < $batch; $i++) {
            if ($i > 0 && (microtime(true) - $t0) >= $budget) break;
            $curp = $page + $i;
            $res = call_user_func(array(__CLASS__, $runner), $api, $code, $t, $curp, $h);
            $resstr = implode('<br>', $res);
            $lastDone = $curp;
            if ($resstr == lang('api_ok')) {
                if ($type === 'all') cache_set(self::pageBookmarkKey($code, $cacheSuffix), $curp);
                return array(
                    'done' => true,
                    'msg' => lang('api_ok'),
                    'success_url' => $successUrl,
                );
            }
            if ($resstr == lang('api_err')) {
                // 源不可达/契约坏：停在本页（与旧逻辑一致），不再往下刷页
                $err = true;
                $nextp = $curp;
                $lines[] = '第 ' . $curp . ' 页：' . $resstr;
                break;
            }
            $lines[] = ($batch > 1 ? '第 ' . $curp . ' 页：' : '') . $resstr;
            $nextp = $curp + 1;
        }

        if ($type === 'all') {
            // 发布前检查修复（A-6）：书签键收敛 pageBookmarkKey()（读写侧单源）
            cache_set(self::pageBookmarkKey($code, $cacheSuffix), $err ? $nextp : $lastDone);
        }
        $resstr = implode('<hr>', $lines);
        if ($batch > 1 && !$err) {
            $resstr = '<div class="text-muted fs-13 mb-2">本次连采 ' . count($lines) . ' 页（' . $page . ' → ' . $lastDone . '）</div>' . $resstr;
        }

        return array(
            'done' => false,
            'resstr' => $resstr,
            'jump' => url(
                $successUrl === 'api-list' ? 'api-api' : 'api-art_api',
                array('api' => $api, 'p' => $nextp, 'h' => $h, 't' => $t, 'code' => $code, 'type' => $type, 'pages' => $batch, 'safe_token' => bull_token_get($uid, 'admin_api_exec')),
                TRUE
            ),
        );
    }

    /**
     * 视频采集单页执行器：fetchVideoJson 取页 → 无 pagecount 回 api_err（源不可达/契约坏）、
     * $pg 超总页数回 api_ok（完成信号，runPagedApi 据此判 done），否则进 importVideoData 入库。
     * 后台分页采集（runPagedApi）与机器通道 api.php 单页采集共用本入口。
     * @param string $encodeurl 采集源地址（core_decrypt 密文或明文 http(s)）
     * @param string $code 采集源编码（conf['bind'] 分类映射键）
     * @param string $t 源站时间参数（现行调用方恒传 ''）
     * @param int $pg 页码
     * @param string $h 小时窗口（''=全量，'24'/'168'）
     * @return array 结果行列表（展示用 HTML 片段，见 importVideoData）
     */
    public static function videoApiPage($encodeurl, $code, $t, $pg, $h)
    {
        $apidate = self::fetchVideoJson($encodeurl, $t, $pg, $h);
        if (!isset($apidate['page']['pagecount'])) {
            return array(lang('api_err'));
        }
        if ($pg > $apidate['page']['pagecount']) {
            return array(lang('api_ok'));
        }
        return self::importVideoData($apidate, $code);
    }

    /**
     * 视频全量 cron 采集：把 fetchVideoJson/importVideoData 交给 cronFetchLoop 从第 1 页跑到尾页。
     * @param string $encodeurl 采集源地址（密文或明文 http(s)）
     * @param string $code 采集源编码
     * @param string $h 小时窗口（旧任务默认 '24'）
     * @return string 逐页结果拼接文本（行以 <br> 分隔、页间 <hr>；异常含 lang('api_err')），仅供留痕，调用方不解析结构
     */
    public static function videoApiCron($encodeurl, $code, $h, bool $strict = false)
    {
        return self::cronFetchLoop(array(__CLASS__, 'fetchVideoJson'), array(__CLASS__, 'importVideoData'), $encodeurl, $code, $h, $strict);
    }

    /**
     * cron_source 任务处理器（TaskQueueService 注册回调；解耦后由组合根接线）
     * 单采集源增量拉取（h 为小时窗口，旧任务默认 24）。
     * 与机器通道 route=rsync 保持一致：向导保存的采集源按类型执行，
     * 未标注类型的旧配置仍兼容视频、文章两条入库链。
     * @param array $param 任务参数（code=采集源编码，h=可选小时窗口）
     */
    public static function taskCronSource(array $param, int $taskId = 0)
    {
        if ($taskId > 0) return CollectionScheduleService::collectPage($param, $taskId);
        $conf = _SERVER('conf');
        $code = (string)($param['code'] ?? '');
        $cfg = is_array($conf['api'] ?? null) ? ($conf['api'][$code] ?? array()) : array();
        if (empty($cfg['api'])) throw new \RuntimeException('采集源不存在: ' . $code);
        // bind 预检（审查修复）：importVideoData/importArtData 未绑定分类时 exit(lang('api_no_set'))，
        // 会越过 TaskQueueService::process() 的 try/catch 使任务永久卡 running；此处预检改为抛异常，
        // 走队列的失败→重试（上限 3）→failed 状态机，不再产生僵尸任务
        if (!isset($conf['bind'][$code])) throw new \RuntimeException('采集源未绑定分类: ' . $code);
        $hours = self::cronHours($param['h'] ?? 24);
        if ($hours === null) throw new \RuntimeException('采集时间范围无效');
        $encodeurl = (string)$cfg['api'];
        $model = (int)($cfg['model'] ?? -1);
        if ($model !== 1) self::videoApiCron($encodeurl, $code, (string)$hours, true);
        if ($model !== 0) self::artApiCron($encodeurl, $code, (string)$hours, true);
    }

    /** HTTP 入口和队列消费共用窗口校验，禁止负数、数组及异常超大范围。 */
    public static function cronHours($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        if (!preg_match('/^[0-9]{1,4}$/D', (string)$value)) return null;
        $hours = (int)$value;
        return $hours >= 1 && $hours <= 8760 ? $hours : null;
    }

    /**
     * 注册采集任务处理器（组合根 index.php 调用；Admin → System 方向，域依赖 DAG 保持无环）
     */
    public static function registerTaskHandlers(): void
    {
        \App\Service\System\TaskQueueService::registerHandler('cron_source', array(__CLASS__, 'taskCronSource'));
    }

    /**
     * 图文采集单页执行器：fetchArtJson 取页 → 无 pagecount 回 api_err、页码越界回 api_ok，
     * 否则进 importArtData 入库。与 videoApiPage 同构（后台分页 + api.php 机器通道共用）。
     * @param string $encodeurl 采集源地址（密文或明文 http(s)）
     * @param string $code 采集源编码
     * @param string $t 源站时间参数（现行调用方恒传 ''）
     * @param int $pg 页码
     * @param string $h 小时窗口（''=全量，'24'/'168'）
     * @return array 结果行列表（展示用 HTML 片段，见 importArtData）
     */
    public static function artApiPage($encodeurl, $code, $t, $pg, $h)
    {
        $apidate = self::fetchArtJson($encodeurl, $t, $pg, $h);
        if (!isset($apidate['page']['pagecount'])) {
            return array(lang('api_err'));
        }
        if ($pg > $apidate['page']['pagecount']) {
            return array(lang('api_ok'));
        }
        return self::importArtData($apidate, $code);
    }

    /**
     * 图文全量 cron 采集：fetchArtJson/importArtData 交给 cronFetchLoop 逐页跑完。
     * @param string $encodeurl 采集源地址（密文或明文 http(s)）
     * @param string $code 采集源编码
     * @param string $h 小时窗口
     * @return string 逐页结果拼接文本（同 videoApiCron，仅供留痕）
     */
    public static function artApiCron($encodeurl, $code, $h, bool $strict = false)
    {
        return self::cronFetchLoop(array(__CLASS__, 'fetchArtJson'), array(__CLASS__, 'importArtData'), $encodeurl, $code, $h, $strict);
    }

    /**
     * cron 逐页采集循环：先取一次页信息拿 pagecount，再 1..count 逐页 fetch+import；
     * 每页导入后触发 addon.hook.collect.page 事件（payload 仅摘要）。
     * ⚠ 注意 $import 对未绑定分类的源直接 exit(lang('api_no_set'))（非异常）——队列路径
     * 已由 taskCronSource 预检把该场景转成可重试的 RuntimeException。
     * @param callable $fetch 取页器 (encodeurl,t,pg,h)=>array（fetchVideoJson/fetchArtJson）
     * @param callable $import 入库器 (apidate,code)=>array（importVideoData/importArtData）
     * @param string $encodeurl 采集源地址（密文或明文 http(s)）
     * @param string $code 采集源编码
     * @param string $h 小时窗口
     * @return string 逐页结果拼接文本（页间 <hr>；源不可达段含 lang('api_err')）
     */
    private static function cronFetchLoop($fetch, $import, $encodeurl, $code, $h, bool $strict = false)
    {
        $t = $pg = '';
        $res = '';
        $apidate = call_user_func($fetch, $encodeurl, $t, $pg, $h);
        if (!isset($apidate['page']['pagecount'])) {
            if ($strict) throw new \RuntimeException('采集取页失败：' . $code . ' page=' . (int)$pg);
            $res .= lang('api_err');
        } else {
            for ($i = 1; $i <= $apidate['page']['pagecount']; $i++) {
                $pg = $i;
                $apires = call_user_func($fetch, $encodeurl, $t, $pg, $h);
                if (!isset($apires['page']['pagecount'])) {
                    if ($strict) throw new \RuntimeException('采集取页失败：' . $code . ' page=' . (int)$pg);
                    $res .= lang('api_err');
                } elseif ($pg > $apires['page']['pagecount']) {
                    $res .= lang('api_ok');
                } else {
                    $data = call_user_func($import, $apires, $code);
                    // 容器方案 P3：采集页导入完成事件（插件可 App::listen 注册；
                    // 0 监听时零行为变化。payload 仅摘要，不携带全量结果）
                    \App::fire('addon.hook.collect.page', array(
                        'code' => $code,
                        'page' => (int)$pg,
                        'lines' => is_array($data) ? count($data) : 1,
                    ));
                    $res .= (is_array($data) ? implode('<br>', $data) : $data) . "<hr>\n";
                }
            }
        }
        return $res;
    }

    /**
     * 视频采集页入库（importVideo 主流程）：按 conf['bind'][$code] 的「源类型名→cid」映射逐条处理——
     * 默认按「去除首尾空白后的片名 + 本地分类」取候选，再核对豆瓣 ID / 年份；
     * 自定义模式只核对用户选择的字段，未选分类时可跨分类匹配。
     * 同页新增/更新立即刷新内存索引。
     * 持站点级采集锁，在主库事务中重新查重并写入，避免不同源或异步请求并发重复插入。
     * 新条目 videoRowPayload 组装载荷 → RepositoryFactory::vod()->insert → video_vid_create
     * → 分类计数 +1 → applyPosterAndTag（海报/标签补充更新）。down_pic=1 且函数可用时先
     * https_request_multi 限量预取新增条目海报；失败或超限保留外链，后续可在附件管理补同步。
     * ⚠ 未绑定分类（conf['bind'][$code] 缺失）直接 exit(lang('api_no_set'))，不是返回值失败。
     * 入库转义边界在 videoRowPayload（htmlspecialchars/xn_html_safe/xn_url_strip），结果行输出已转义。
     * @param array $data fetchJson/fetchVideoJson 的返回结构（须含 'data' 源数据行列表）
     * @param string $code 采集源编码
     * @param array|null $fastOptions 内部快速入库策略；NULL 保持普通采集行为
     * @param callable|null $onCommit 内部批次回执，必须与视频及版本来源在同一事务保存
     * @return array 每条一行的展示文本（序号 + 名称 + 着色结果描述），供拼接输出
     */
    public static function importVideoData($data, $code, ?array $fastOptions = null, ?callable $onCommit = null)
    {
        \App\Service\System\ArticleTagSchema::ensureReady();
        $conf = _SERVER('conf');
        if ($fastOptions !== null) {
            if (!in_array($fastOptions['mode'] ?? '', ['skip', 'update'], true)
                || !array_key_exists($fastOptions['scope'] ?? '', ApiImportService::fastDbUpdateScopeOptions())) throw new \InvalidArgumentException('快速采集策略无效');
            $data['data'] = self::normalizeFastVideoRows($data['data']);
            // Fast imports keep their existing external-poster / lazy-tag behavior.
            $conf['down_pic'] = 0;
        }
        $matchRule = VideoMatchRuleService::settings($conf);
        $param = array('filter' => 0);
        $config = $conf['api_api']['vod'];
        $players = $conf['vodplayer'];
        if (!isset($conf['bind'][$code])) {
            exit(lang('api_no_set'));
        }
        $apicate = CollectionCategoryService::validBindings((array)$conf['bind'][$code], 0);
        $ress = array();

        // 海报预取只使用提示快照；真正入库在持锁后从主库重新查重。
        $existing_vods = !empty($conf['down_pic'])
            ? self::findExistingVideos($data['data'], $apicate, $matchRule) : array();

        // 采集性能专项：并发预取新增条目的海报，替代逐张串行下载（curl_multi，SSRF 逐跳校验）。
        // 条件与 applyPosterAndTag 一致（down_pic 开启）、仅新增条目（已存在的走 mergePlayUpdates 不下载）、
        // 键与 videoRowPayload 的 $arr['pic'] 同口径；失败/超限保留外链，禁止单发回退绕过整批预算。
        $poster_prefetch = array();
        if (!empty($conf['down_pic']) && $conf['down_pic'] == 1 && function_exists('https_request_multi')) {
            $prefetch_urls = array();
            foreach ($data['data'] as $pv) {
                if (!isset($apicate[$pv['type_name']]) || empty($pv['vod_name'])) continue;
                $candidates = $existing_vods[self::videoIdentity(self::storedVodName($pv['vod_name']), $apicate[$pv['type_name']], $matchRule)] ?? array();
                if (self::matchingVideo($candidates, VideoMatchRuleService::sourceMetadata($pv, (int)$apicate[$pv['type_name']]), $matchRule)) continue;
                if (!preg_match('#^https?://#i', (string)($pv['vod_pic'] ?? ''))) continue;
                $pu = xn_url_strip($pv['vod_pic']);
                if ('' !== $pu) $prefetch_urls[$pu] = $pu;
            }
            if ($prefetch_urls) {
                try {
                    $poster_prefetch = https_request_multi(array_values($prefetch_urls), 10, 5, 3145728);
                } catch (\Throwable $e) {
                    $poster_prefetch = array();
                    xn_log('collection image prefetch failed: ' . $e->getMessage(), 'attachment_error');
                }
                $ready = count(array_filter($poster_prefetch, static fn($bytes) => is_string($bytes) && $bytes !== ''));
                $deferred = count($prefetch_urls) - $ready;
                if ($deferred > 0) {
                    xn_log('collection image prefetch deferred ' . $deferred . ' URL(s): request failed or batch limit reached '
                        . '(4 concurrent, 24 MiB total, 100 URLs, 20 seconds). External URLs are preserved; retry in attachment management.', 'attachment_error');
                }
            }
        }

        $db = _SERVER('db');
        $pdo = $db->connect();
        if (!$pdo || $db->inTransaction()) throw new \RuntimeException('采集数据库暂不可用，请稍后重试');
        // 同站视频采集按页串行写入；远程取页、海报下载均不占用此锁。
        $lock = 'collect_vod_' . substr(hash('sha256', $db->tablepre . $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 30)');
        $stmt->execute(array($lock));
        if ((int)$stmt->fetchColumn() !== 1) throw new \RuntimeException('其他视频采集正在入库，请稍后重试当前页');
        $created = $categoryCounts = $touched = $movedCategories = $receipts = array();
        try {
            if (!$db->beginTransaction()) throw new \RuntimeException('无法开始采集事务');
            $apicate = CollectionCategoryService::validBindings((array)$conf['bind'][$code], 0);
            $existing_vods = self::findExistingVideos($data['data'], $apicate, $matchRule,
                $fastOptions !== null ? array_keys(ApiImportService::fastDbDiffLabels()) : []);
            $candidates = array();
            foreach ($existing_vods as $rows) foreach ($rows as $vid => $row) $candidates[$vid] = $row;
            $versionStates = VideoVersionService::load($candidates);
            $originalStates = $versionStates;
            foreach ($data['data'] as $k => $v) {
                $color = 'red';
                $des = '';
                $msg = '';
                $arr = array();
                $receipt = ['vid'=>0, 'cid'=>0, 'vod_name'=>self::storedVodName($v['vod_name'] ?? ''), 'action_type'=>'skip', 'result_status'=>'unbound', 'diff_fields'=>[], 'diff_snapshot'=>[]];
                if (!isset($apicate[$v['type_name']])) {
                    // 上线前审计修复（H-A2）：$v['type_name'] 来自远端采集接口，
                    // 原样拼入 $ress 后经 api_res.html 裸 echo（采集=任意第三方源的信任边界，存储型后台 XSS）。
                    $des = lang('api_type_ok') . htmlspecialchars((string)$v['type_name'], ENT_QUOTES, 'UTF-8');
                } elseif (self::storedVodName($v['vod_name'] ?? '') === '') {
                    $des = lang('api_url_err');
                } else {
                    $payload = self::videoRowPayload($v, $apicate[$v['type_name']], $players, $param);
                    $arr = $payload['arr'];
                    if ($fastOptions !== null) $arr['uptime'] = \App\Service\Content\VideoService::fitColumns(['uptime'=>(string)($v['vod_time'] ?? '')])['uptime'];
                    $cid = $arr['cid'];
                    $tags = $payload['tags'];
                    $cj_play_from_arr = $payload['cj_play_from'];
                    $cj_play_url_arr = $payload['cj_play_url'];
                    $cj_play_server_arr = $payload['cj_play_server'];
                    $cj_play_note_arr = $payload['cj_play_note'];
                    $identity = self::videoIdentity($arr['name'], $cid, $matchRule);
                    $owner = VideoVersionService::owner((string)$code, (int)$arr['vod_id']);
                    $info = self::matchingVideo($existing_vods[$identity] ?? array(), $arr, $matchRule);
                    if (!$info) {
                        $vid = \App\Repository\RepositoryFactory::vod()->insert($arr);
                        if (!$vid) throw new \RuntimeException('视频入库失败，本页已回滚，请重试');
                        $categoryCounts[$cid] = ($categoryCounts[$cid] ?? 0) + 1;
                        $color = 'green';
                        $des = lang('api_db_ok');
                        // 同一页后续条目必须看到刚插入的影片和最新播放组。
                        $existing_vods[$identity][$vid] = array_merge($arr, array('vid' => $vid));
                        $versionStates[$vid] = VideoVersionService::seed($arr, $owner);
                        $created[$vid] = array('vid' => $vid, 'cid' => $cid, 'tags' => $tags, 'arr' => $arr);
                        $receipt['action_type'] = $receipt['result_status'] = 'create';
                    } else {
                        $vid = $info['vid'];
                        if ($fastOptions !== null) {
                            $nextState = $versionStates[$vid];
                            $update = self::fastVideoUpdate($v, $payload, $info, $fastOptions['scope'], $config, $owner, $nextState);
                            $receipt['diff_fields'] = ApiImportService::fastDbDiffFields($info, $update);
                            $receipt['diff_snapshot'] = ApiImportService::fastDbDiffSnapshot($info, $update);
                            $receipt['result_status'] = !$receipt['diff_fields'] ? 'unchanged' : ($fastOptions['mode'] === 'skip' ? 'skipped_update' : 'update');
                            if ($fastOptions['mode'] === 'skip') $update = [];
                            else $versionStates[$vid] = $nextState;
                            if ($receipt['result_status'] === 'update') $receipt['action_type'] = 'update';
                        } else {
                            $update = self::mergePlayUpdates($info, $cj_play_from_arr, $cj_play_url_arr, $cj_play_server_arr, $cj_play_note_arr, $config, $des, $color, $owner, $versionStates[$vid]);
                            $update['create_date'] = time();
                            if ($info['name'] !== $arr['name']) $update['name'] = $arr['name'];
                            foreach (array('year', 'douban_id') as $field) {
                                if (empty($info[$field]) && !empty($arr[$field])) $update[$field] = $arr[$field];
                            }
                        }
                        if ($update && FALSE === video_update($vid, $update)) throw new \RuntimeException('视频更新失败，本页已回滚，请重试');
                        if (isset($update['cid']) && (int)$update['cid'] !== (int)$info['cid']) {
                            if (isset($created[$vid])) {
                                // A row created earlier in this page has no index yet: insert only its final category.
                                $oldCid = (int)$created[$vid]['cid'];
                                if (--$categoryCounts[$oldCid] === 0) unset($categoryCounts[$oldCid]);
                                $categoryCounts[(int)$update['cid']] = ($categoryCounts[(int)$update['cid']] ?? 0) + 1;
                                $created[$vid]['cid'] = (int)$update['cid'];
                                $created[$vid]['arr']['cid'] = (int)$update['cid'];
                            } else {
                                if (\App\Repository\RepositoryFactory::vodVid()->replace(['vid'=>(int)$vid,'cid'=>(int)$update['cid']]) === false) throw new \RuntimeException('视频分类关联更新失败');
                                if (\App\Repository\RepositoryFactory::table('vod_sticky', 'vid')->update(['vid'=>(int)$vid], ['cid'=>(int)$update['cid']]) === false) throw new \RuntimeException('视频推荐分类更新失败');
                            }
                            $movedCategories[(int)$info['cid']] = $movedCategories[(int)$update['cid']] = true;
                        }
                        $existing_vods[$identity][$vid] = array_merge($info, $update);
                    }
                    $receipt['vid'] = (int)$vid;
                    $receipt['cid'] = (int)($existing_vods[$identity][$vid]['cid'] ?? $cid);
                    $touched[$vid] = $vid;
                    // 本站无对应播放器的来源整组被剔除：显式提示，避免「采集完成」却丢了播放地址
                    if (!empty($payload['dropped_from'])) {
                        $msg .= " <font color='orange'>（未配置播放器，已跳过来源：" . htmlspecialchars(implode(',', array_unique($payload['dropped_from'])), ENT_QUOTES, 'UTF-8') . '）</font>';
                    }
                }
                if ($fastOptions !== null) $receipts[] = $receipt;
                $ress[] = ($k + 1) . '、' . htmlspecialchars($v['vod_name'], ENT_QUOTES, 'UTF-8') . " <font color='{$color}'>" . $des . '</font>' . $msg . '';
            }
            foreach (array_chunk($created, 500) as $chunk) {
                $indexes = array_map(static fn($item) => array('vid' => $item['vid'], 'cid' => $item['cid']), $chunk);
                if (\App\Repository\RepositoryFactory::vodVid()->bigInsert($indexes) !== count($indexes)) {
                    throw new \RuntimeException('视频分类关联写入失败，本页已回滚，请重试');
                }
            }
            foreach ($categoryCounts as $cid => $count) {
                if (!cate_update($cid, array('videos+' => $count))) throw new \RuntimeException('分类计数更新失败，本页已回滚，请重试');
            }
            if ($movedCategories) {
                $cateTable = $db->qi($db->tablepre . 'cate'); $indexTable = $db->qi($db->tablepre . 'vod_vid');
                $ids = array_keys($movedCategories);
                $countStmt = $pdo->prepare('UPDATE ' . $cateTable . ' c SET c.videos=(SELECT COUNT(*) FROM ' . $indexTable . ' v WHERE v.cid=c.cid) WHERE c.cid IN (' . implode(',', array_fill(0,count($ids),'?')) . ')');
                if (!$countStmt || !$countStmt->execute($ids)) throw new \RuntimeException('分类计数更新失败');
            }
            $changedStates = array();
            foreach ($versionStates as $vid => $state) {
                if (isset($originalStates[$vid]) ? $state !== $originalStates[$vid] : !empty($state['groups'])) $changedStates[$vid] = $state;
            }
            VideoVersionService::save($changedStates);
            if ($onCommit !== null) $onCommit($receipts);
            if (!$db->commit()) throw new \RuntimeException('采集提交失败，请重试当前页');
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        } finally {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute(array($lock));
        }
        // 提交后再次失效，避免事务期间其他请求缓存了旧播放组。
        foreach ($touched as $vid) content_cache_delete('vod_', $vid);
        if ($touched) page_cache_bump();
        if ($created) \App\Service\Front\TypeService::bumpFacets();
        if ($movedCategories) { cate_list_cache_delete(); \App\Service\Front\TypeService::bumpFacets(); }
        if ($fastOptions !== null) return $ress;
        foreach ($created as $item) {
            $vod_update = array();
            self::applyPosterAndTag($item['vid'], $item['cid'], $item['tags'], $vod_update, $item['arr'], $conf, $poster_prefetch);
            if (!empty($vod_update)) {
                try {
                    $save = static fn()=>video_update($item['vid'], $vod_update);
                    $saved = !empty($vod_update['pic']) ? \App\Service\System\AttachmentService::withUrlReference($vod_update['pic'], $save) : $save();
                    if ($saved === false) message(-1, lang('update_thread_failed'));
                } catch (\Throwable $e) {
                    // Image cleanup by another task must never replace a working external URL with a missing file.
                    xn_log('collection image reference deferred: '.$e->getMessage(), 'attachment_error');
                    unset($vod_update['pic']);
                    if ($vod_update) video_update($item['vid'], $vod_update);
                }
            }
            if (!empty($vod_update['pic'])) {
                try { \App\Service\System\AttachmentService::cleanupCollected($vod_update['pic']); }
                catch (\Throwable $e) { xn_log('collection image cleanup deferred: '.$e->getMessage(), 'attachment_error'); }
            }
        }
        return $ress;
    }

    private static function normalizeFastVideoRows(array $rows): array
    {
        $defaults = array_fill_keys(explode(',', 'type_name,vod_id,vod_name,vod_en,vod_sub,vod_letter,vod_status,vod_hits,vod_total,vod_serial,vod_actor,vod_director,vod_writer,vod_blurb,vod_remarks,vod_area,vod_lang,vod_year,vod_douban_id,vod_douban_score,vod_content,vod_pic,vod_play_from,vod_play_url,vod_play_server,vod_play_note'), '');
        foreach ($rows as &$row) {
            if (!is_array($row)) throw new \RuntimeException('快速入库数据格式错误');
            $row += $defaults;
        }
        return $rows;
    }

    /** The same calculation is used by the fast preview and the locked importer. */
    private static function fastVideoUpdate(array $source, array $payload, array $info, string $scope, array $config, string $owner, array &$state): array
    {
        $update = ApiImportService::fastDbFilterUpdateRowByScope(ApiImportService::fastDbBuildUpdateRow($source, $payload['arr']['cid']), $scope);
        foreach (['play_from','play_url','play_server','play_note'] as $field) unset($update[$field]);
        if (in_array($scope, ['all','play'], true)) {
            // Choosing fast "update play" authorizes updates, while shared ownership still protects other HD entries.
            $config['uprule'] = 'a'; $des = ''; $color = '';
            $update += self::mergePlayUpdates($info, $payload['cj_play_from'], $payload['cj_play_url'], $payload['cj_play_server'], $payload['cj_play_note'], $config, $des, $color, $owner, $state);
        }
        foreach ($update as $field => $value) {
            if (is_int($value) ? (int)($info[$field] ?? 0) === $value : (string)($info[$field] ?? '') === (string)$value) unset($update[$field]);
        }
        return $update;
    }

    /** Read-only fast preview, retaining every same-name candidate and the configured matching rule. */
    public static function previewFastVideoData(array $items, string $code, string $scope): array
    {
        $conf = _SERVER('conf'); $rule = VideoMatchRuleService::settings($conf);
        $bindings = CollectionCategoryService::validBindings((array)($conf['bind'][$code] ?? []), 0);
        $items = self::normalizeFastVideoRows($items);
        $candidates = self::findExistingVideos($items, $bindings, $rule, array_keys(ApiImportService::fastDbDiffLabels()));
        $rows = []; foreach ($candidates as $bucket) foreach ($bucket as $vid => $row) $rows[$vid] = $row;
        $states = VideoVersionService::load($rows); $result = []; $previewId = -1;
        foreach ($items as $source) {
            $cid = (int)($bindings[$source['type_name']] ?? 0);
            $name = self::storedVodName($source['vod_name']);
            $row = ['vod_name'=>(string)$source['vod_name'], 'type_name'=>$source['type_name'], 'vod_id'=>(int)$source['vod_id'], 'cid'=>$cid, 'vod_time'=>$source['vod_time'] ?? '', 'vid'=>0, 'diff_fields'=>[], 'diff_snapshot'=>[]];
            if ($cid < 1 || $name === '') { $row['status']='unbound'; $result[]=$row; continue; }
            $payload = self::videoRowPayload($source, $cid, $conf['vodplayer'] ?? [], ['filter'=>0]);
            $identity = self::videoIdentity($name, $cid, $rule);
            $info = self::matchingVideo($candidates[$identity] ?? [], $payload['arr'], $rule);
            $owner = VideoVersionService::owner($code, (int)$source['vod_id']);
            if ($info) {
                $vid = (int)$info['vid'];
                $update = self::fastVideoUpdate($source, $payload, $info, $scope, $conf['api_api']['vod'] ?? [], $owner, $states[$vid]);
                $row['vid'] = max(0, $vid);
                $row['diff_fields'] = ApiImportService::fastDbDiffFields($info, $update);
                $row['diff_snapshot'] = ApiImportService::fastDbDiffSnapshot($info, $update);
                $row['status'] = $row['diff_fields'] ? 'updatable' : 'unchanged';
                $candidates[$identity][$vid] = array_merge($info, $update);
            } else {
                $row['status'] = 'importable'; $vid = $previewId--;
                $candidates[$identity][$vid] = $payload['arr'] + ['vid'=>$vid];
                $states[$vid] = VideoVersionService::seed($payload['arr'], $owner);
            }
            $result[] = $row;
        }
        return $result;
    }

    /** 片名已经过 storedVodName()；只有启用分类条件时才按本地 cid 分桶。 */
    private static function videoIdentity(string $name, $cid, ?array $rule = null): string
    {
        $rule = $rule ?? VideoMatchRuleService::settings(_SERVER('conf'));
        return (VideoMatchRuleService::usesCategory($rule) ? (int)$cid : 0) . ':' . $name;
    }

    /** 默认规则核对元数据冲突；自定义规则按管理员选择的条件匹配。 */
    private static function matchingVideo(array $candidates, array $incoming, ?array $rule = null): ?array
    {
        $rule = $rule ?? VideoMatchRuleService::settings(_SERVER('conf'));
        if ($rule['mode'] === 'custom') return VideoMatchRuleService::matchCustom($candidates, $incoming, $rule);
        $id = max(0, (int)($incoming['douban_id'] ?? 0));
        $year = max(0, (int)($incoming['year'] ?? 0));
        $best = array(); $bestScore = -1;
        foreach ($candidates as $row) {
            $rowId = max(0, (int)($row['douban_id'] ?? 0));
            $rowYear = max(0, (int)($row['year'] ?? 0));
            if ($id && $rowId && $id !== $rowId) continue;
            $sameId = $id && $id === $rowId;
            if (!$sameId && $year && $rowYear && $year !== $rowYear) continue;
            // 相同豆瓣 ID 优先；缺失信息不作为冲突，但不能随意挑选同名重拍片。
            $score = $sameId ? 10 : (($id === $rowId && $year === $rowYear) ? 8 : (($year && $year === $rowYear) ? 4 : 0));
            if ($score > $bestScore) { $best = array(); $bestScore = $score; }
            if ($score === $bestScore) $best[] = $row;
        }
        if (!$best) return null;
        $ids = $years = array();
        foreach ($best as $row) {
            if (!empty($row['douban_id'])) $ids[(int)$row['douban_id']] = true;
            if (!empty($row['year'])) $years[(int)$row['year']] = true;
        }
        if (count($ids) > 1 || ($bestScore !== 10 && count($years) > 1)) return null;
        usort($best, static fn($a, $b) => (int)$a['vid'] <=> (int)$b['vid']);
        return $best[0];
    }

    /**
     * 分批查重，不使用默认分页上限；正式写入时由调用方的驱动事务保证读主库。
     * 对未命中的片名再兼容历史前后空格，避免升级后旧记录被当成新影片。
     * 保留同名候选，交 matchingVideo 核对作品信息，不在采集时删除用户已有内容。
     */
    private static function findExistingVideos(array $items, array $bindings, ?array $rule = null, array $extraFields = []): array
    {
        $rule = $rule ?? VideoMatchRuleService::settings(_SERVER('conf'));
        $useCategory = VideoMatchRuleService::usesCategory($rule);
        $wanted = $names = $cids = $found = array();
        foreach ($items as $item) {
            if (!isset($bindings[$item['type_name'] ?? ''])) continue;
            $name = self::storedVodName($item['vod_name'] ?? '');
            if ($name === '') continue;
            $cid = (int)$bindings[$item['type_name']];
            $wanted[self::videoIdentity($name, $cid, $rule)] = $name;
            $names[$name] = $name;
            $raw = \App\Service\Content\VideoService::fitColumns(array('name' => htmlspecialchars((string)$item['vod_name'], ENT_QUOTES, 'UTF-8')));
            $names[$raw['name']] = $raw['name'];
            $cids[$cid] = $cid;
        }
        if (!$wanted) return $found;
        $fields = array('vid', 'cid', 'alias', 'name', 'year', 'douban_id', 'play_from', 'play_url', 'play_server', 'play_note');
        $fields = array_values(array_unique(array_merge($fields, $extraFields)));
        if ($rule['mode'] === 'custom') $fields = array_values(array_unique(array_merge($fields, $rule['fields'])));
        $remember = static function (array $rows) use (&$found, $wanted, $rule) {
            foreach ($rows as $row) {
                $key = self::videoIdentity(self::storedVodName(htmlspecialchars_decode($row['name'], ENT_QUOTES)), $row['cid'], $rule);
                if (isset($wanted[$key])) $found[$key][$row['vid']] = $row;
            }
        };
        foreach (array_chunk(array_values($names), 500) as $chunk) {
            $query = \App\Repository\RepositoryFactory::vod()->builder()->select($fields)->whereIn('name', $chunk);
            if ($useCategory) $query->whereIn('cid', array_values($cids));
            $rows = $query->get();
            if ($rows === false) throw new \RuntimeException('视频查重失败，已停止本页采集');
            $remember($rows);
        }
        $missing = array();
        foreach ($items as $item) {
            if (!isset($bindings[$item['type_name'] ?? ''])) continue;
            $name = self::storedVodName($item['vod_name'] ?? '');
            $key = self::videoIdentity($name, $bindings[$item['type_name']], $rule);
            if ($name !== '' && !self::matchingVideo($found[$key] ?? array(), VideoMatchRuleService::sourceMetadata($item, (int)$bindings[$item['type_name']]), $rule)) $missing[$key] = true;
        }
        if ($missing) {
            $db = _SERVER('db');
            $prefix = $db->tablepre;
            if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) throw new \RuntimeException('无效的数据表前缀');
            // 只取轻量元数据，再按匹配 vid 取播放地址；兼容旧全角空格、NBSP 等首尾空白。
            $cidSql = implode(',', array_fill(0, count($cids), '?'));
            $categorySql = $useCategory ? "cid IN ($cidSql) AND " : '';
            $spaces = preg_split('//u', " \t\r\n\v\f\u{0085}\u{00A0}\u{1680}\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}\u{200B}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}\u{FEFF}", -1, PREG_SPLIT_NO_EMPTY);
            $patterns = array_map(static fn($space) => $space . '%', $spaces);
            foreach ($missing as $key => $_) $patterns[] = strtr($wanted[$key], array('=' => '==', '%' => '=%', '_' => '=_')) . '%';
            $legacyIds = array();
            foreach (array_chunk(array_unique($patterns), 100) as $chunk) {
                $prefixSql = implode(' OR ', array_fill(0, count($chunk), "name LIKE ? ESCAPE '='"));
                $params = array_merge($useCategory ? array_values($cids) : array(), $chunk);
                $stmt = $db->prepare_execute("SELECT vid,cid,name FROM `{$prefix}vod` WHERE {$categorySql}($prefixSql)", $params);
                if (!$stmt) throw new \RuntimeException('历史视频查重失败，已停止本页采集');
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $key = self::videoIdentity(self::storedVodName(htmlspecialchars_decode($row['name'], ENT_QUOTES)), $row['cid'], $rule);
                    if (isset($missing[$key])) $legacyIds[(int)$row['vid']] = (int)$row['vid'];
                }
            }
            foreach (array_chunk($legacyIds, 500) as $chunk) {
                $rows = \App\Repository\RepositoryFactory::vod()->builder()->select($fields)->whereIn('vid', $chunk)->get();
                if ($rows === false) throw new \RuntimeException('历史视频读取失败，已停止本页采集');
                $remember($rows);
            }
        }
        return $found;
    }

    /**
     * 采集源单行（MacCMS 字段）→ vod 入库载荷：数值字段 intval/floatval、文本字段
     * htmlspecialchars(ENT_QUOTES)、content 过 xn_html_safe、pic 仅认 http(s) 且过 xn_url_strip。
     * play 族（vod_play_from/url/server/note）按 '$$$' 拆组，剔除空组与本站无播放器（$players）的组，
     * play_url/server/note 重join时再过 xn_url_strip 字符闸（防前台属性逃逸，入库侧落点）。
     * @param array $v 源数据行（vod_name/vod_pic/vod_play_from… 原始 JSON 行）
     * @param int|string $cid 目标分类 ID（conf['bind'][$code][$type_name]）
     * @param array $players 本站播放器表 conf['vodplayer']，键=播放器名（不在表内的播放组被剔除）
     * @param array $param 采集选项（filter>0 时按 filter_from 名单跳过播放组；现行调用恒 filter=0）
     * @return array {arr: vod 插入载荷, tags: 标签串, cj_play_from/cj_play_url/cj_play_server/cj_play_note: 拆组后的播放组数组}
     */
    private static function videoRowPayload($v, $cid, $players, $param): array
    {
        $arr = array();
        $arr['vod_id'] = intval($v['vod_id']);
        $arr['name'] = self::storedVodName($v['vod_name']);
        $arr['en'] = htmlspecialchars($v['vod_en'], ENT_QUOTES);
        $arr['sub'] = htmlspecialchars($v['vod_sub'], ENT_QUOTES);
        $arr['letter'] = htmlspecialchars($v['vod_letter'], ENT_QUOTES);
        $arr['status'] = intval($v['vod_status']);
        $arr['views'] = intval($v['vod_hits']);
        $arr['total'] = intval($v['vod_total']);
        $arr['serial'] = intval($v['vod_serial']);
        $arr['actor'] = htmlspecialchars(bull_format_text($v['vod_actor'], true), ENT_QUOTES);
        $arr['director'] = htmlspecialchars(bull_format_text($v['vod_director'], true), ENT_QUOTES);
        $arr['writer'] = htmlspecialchars(bull_format_text($v['vod_writer'], true), ENT_QUOTES);
        $arr['blurb'] = htmlspecialchars($v['vod_blurb'], ENT_QUOTES);
        $arr['remarks'] = htmlspecialchars(bull_format_text($v['vod_remarks'], true), ENT_QUOTES);
        $arr['area'] = htmlspecialchars($v['vod_area'], ENT_QUOTES);
        $arr['lang'] = htmlspecialchars($v['vod_lang'], ENT_QUOTES);
        $arr['year'] = intval($v['vod_year']);
        $arr['douban_id'] = intval($v['vod_douban_id']);
        $arr['douban_score'] = floatval($v['vod_douban_score']);
        $arr['content'] = xn_html_safe($v['vod_content'] ?? '');
        $arr['pic'] = preg_match('#^https?://#i', $v['vod_pic']) ? xn_url_strip($v['vod_pic']) : '';
        $arr['create_date'] = time();
        $arr['cid'] = intval($cid);
        $get_tag = get_tag($v['vod_name']);
        $tags = htmlspecialchars($v['vod_area'], ENT_QUOTES) . "," . htmlspecialchars($v['vod_lang'], ENT_QUOTES) . "," . intval($v['vod_year']) . ',' . htmlspecialchars($v['type_name'], ENT_QUOTES) . ',' . htmlspecialchars($get_tag, ENT_QUOTES);
        $arr['alias'] = uniq_id();
        if (empty($v['vod_play_url'])) {
            $v['vod_play_url'] = '';
        }

        $sources = explode('$$$', $v['vod_play_from']);
        $sourceUrls = explode('$$$', $v['vod_play_url']);
        $sourceServers = explode('$$$', $v['vod_play_server']);
        $sourceNotes = explode('$$$', $v['vod_play_note']);
        $cj_play_from_arr = $cj_play_url_arr = $cj_play_server_arr = $cj_play_note_arr = $positions = array();
        $dropped_from = array();
        foreach ($sources as $kk => $vv) {
            if (empty($vv) || empty($players[$vv])) {
                '' !== trim((string)$vv) and $dropped_from[] = (string)$vv;
                continue;
            }
            if (($param['filter'] ?? 0) > 0 && in_array($vv, explode(',', $param['filter_from'] ?? ''), true)) {
                continue;
            }
            $url = self::filterPlayUrlScheme(rtrim((string)($sourceUrls[$kk] ?? ''), '#'));
            if (isset($positions[$vv])) {
                // 同一条源数据中的重复播放组是并列资源，不是先后更新；保留全部不同地址。
                $index = $positions[$vv];
                $entries = array_merge(explode('#', $cj_play_url_arr[$index]), explode('#', $url));
                $cj_play_url_arr[$index] = implode('#', array_unique(array_filter($entries, static fn($entry) => $entry !== '')));
                continue;
            }
            $positions[$vv] = count($cj_play_from_arr);
            $cj_play_from_arr[] = $vv;
            $cj_play_url_arr[] = $url;
            $cj_play_server_arr[] = $sourceServers[$kk] ?? '';
            $cj_play_note_arr[] = $sourceNotes[$kk] ?? '';
        }
        $arr['play_from'] = (string)join('$$$', (array)$cj_play_from_arr);
        // play 族入库字符闸：剔除引号/尖括号/控制字符（防前台 iframe src 属性逃逸，红线「输出必须转义」入库侧落点）
        $arr['play_url'] = xn_url_strip((string)join('$$$', (array)$cj_play_url_arr));
        $arr['play_server'] = xn_url_strip((string)join('$$$', (array)$cj_play_server_arr));
        $arr['play_note'] = xn_url_strip((string)join('$$$', (array)$cj_play_note_arr));

        return array(
            // 按 vod 列宽截断（防 SQLSTATE[22001] Data too long：actor/blurb/writer/sub 等采集字段超长）
            'arr' => \App\Service\Content\VideoService::fitColumns($arr),
            'tags' => $tags,
            'cj_play_from' => $cj_play_from_arr,
            'cj_play_url' => $cj_play_url_arr,
            'cj_play_server' => $cj_play_server_arr,
            'cj_play_note' => $cj_play_note_arr,
            'dropped_from' => $dropped_from,
        );
    }

    /**
     * 远端片名 → 去除首尾空白（保留片名内部空格）→ 转义 → vod 列宽截断。
     * 入库（videoRowPayload）与查重（importVideoData whereIn / 预取跳过）必须共用本函数，否则口径漂移即重复入库。
     * @param mixed $name 远端 vod_name 原文
     * @return string 与 vod.name 存储值逐字节一致的文本
     */
    private static function storedVodName($name): string
    {
        $name = preg_replace('/^[\\s\\p{Z}\\x{200B}\\x{FEFF}]+|[\\s\\p{Z}\\x{200B}\\x{FEFF}]+$/u', '', (string)$name) ?? trim((string)$name);
        $row = \App\Service\Content\VideoService::fitColumns(array('name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8')));
        return (string)$row['name'];
    }

    /**
     * 单个播放组（`集名$地址#集名$地址`）逐集协议闸：地址带 scheme 时只放行 http/https，
     * javascript:/data:/vbscript: 等整集剔除；无 scheme 的解析 ID（如 `第1集$abc123`）原样保留，交播放器解析。
     * @param string $group 单个播放组原文
     * @return string 过滤后的播放组
     */
    private static function filterPlayUrlScheme(string $group): string
    {
        if ('' === $group) return '';
        $out = array();
        foreach (explode('#', $group) as $ep) {
            $pos = strpos($ep, '$');
            $url = trim(false === $pos ? $ep : substr($ep, $pos + 1));
            // 去控制字符/空白后再判 scheme（防 `java\tscript:` 之类分隔绕过）
            $probe = preg_replace('/[\x00-\x20\x7F]/', '', $url);
            if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $probe, $m) && !in_array(strtolower($m[1]), array('http', 'https'), true)) {
                continue;
            }
            $out[] = $ep;
        }
        return implode('#', $out);
    }

    /** Download new posters through the shared storage service; failures preserve the source URL. */
    private static function applyPosterAndTag($vid, $cid, $tags, array &$vod_update, array $arr, array $conf, array $prefetch = array()): void
    {
        $bytes = !empty($arr['pic']) ? ($prefetch[$arr['pic']] ?? null) : null;
        // Only consume successful bounded prefetches; null/false/empty would trigger another network download.
        if (!empty($conf['down_pic']) && is_string($bytes) && $bytes !== '') {
            try {
                $storage = \App\Service\System\AttachmentStorage::settings(true);
                $asset = \App\Service\System\AttachmentService::transfer($arr['pic'], $storage['backend'], $storage, $bytes);
                $vod_update['pic'] = $asset['url'];
            } catch (\Throwable $e) {
                xn_log('collection image sync failed: ' . $e->getMessage(), 'attachment_error');
            }
        }
        $tag_json = \App\Service\Content\TagFlowService::post($vid, $cid, $tags);
        if (xn_strlen($tag_json) >= 120) {
            $s = xn_substr($tag_json, -1, NULL);
            if ('}' != $s) {
                $len = mb_strripos($tag_json, ',', 0, 'UTF-8');
                $tag_json = $len ? xn_substr($tag_json, 0, $len) . '}' : '';
            }
        }
        !empty($tag_json) and $vod_update['tag'] = $tag_json;
    }

    /**
     * 重名视频的播放组合并（仅当 conf['api_api']['vod']['uprule'] 含 'a' 才动作）：
     * 采集组名库里没有 → 整组追加；已有且 play_url 不同 → 替换该组
     * （urlrole=1 时按 '#' 分集与旧值合并去重）。电影的 HD / HD粤语等版本入口单独保护：
     * 同一来源条目的入口更新地址，其他资源/版本保留；普通剧集仍遵循原有合并/覆盖规则。
     * 每次操作都基于已合并的最新播放组。
     * 仅在真发生合并时返回 play_from/play_url/play_server/play_note
     * （后三者出 xn_url_strip 字符闸；play_from 原样）。
     * @param array $info 库中既有 vod 行（取 play_from/play_url/play_server/play_note）
     * @param array $cj_play_from_arr 采集侧播放组名数组（videoRowPayload 产出）
     * @param array $cj_play_url_arr 采集侧播放地址数组（与上同键位）
     * @param array $cj_play_server_arr 采集侧 server 数组
     * @param array $cj_play_note_arr 采集侧 note 数组
     * @param array $config conf['api_api']['vod']（uprule/urlrole 升级规则）
     * @param string $des 引用出参：追加结果描述（无需更新/新增组/更新组等 lang 文案）
     * @param string $color 引用出参：发生合并时改写为 'green'
     * @return array 待 video_update 的字段集；无需更新时为空数组
     */
    private static function mergePlayUpdates(array $info, array $cj_play_from_arr, array $cj_play_url_arr, array $cj_play_server_arr, array $cj_play_note_arr, array $config, &$des, &$color, string $owner, array &$versionState): array
    {
        if (strpos(',' . ($config['uprule'] ?? ''), 'a') === false || !$cj_play_from_arr) return array();
        $from = empty($info['play_from']) ? array() : explode('$$$', $info['play_from']);
        $urls = explode('$$$', $info['play_url']);
        $servers = explode('$$$', $info['play_server']);
        $notes = explode('$$$', $info['play_note']);
        $changed = false;
        foreach ($cj_play_from_arr as $k => $source) {
            if ($source === '') { $des .= lang('api_play_err'); continue; }
            $url = xn_url_strip($cj_play_url_arr[$k] ?? '');
            // 依据当前播放组查找；即使两个来源地址相同，也应保留各自的线路。
            $index = array_search($source, $from, true);
            $groupState = $versionState['groups'][$source] ?? array();
            // 即使地址未变，也要认领完全一致的历史入口，后续换链才能按来源更新。
            $versions = VideoVersionService::merge($index === false ? '' : ($urls[$index] ?? ''), $url, $owner, $groupState);
            if ($groupState) $versionState['groups'][$source] = $groupState;
            else unset($versionState['groups'][$source]);
            if ($index === false) {
                $index = count($from);
                $from[$index] = $source;
                $urls[$index] = $url;
                $servers[$index] = $cj_play_server_arr[$k] ?? '';
                $notes[$index] = $cj_play_note_arr[$k] ?? '';
                $changed = true;
                $color = 'green';
                $des .= lang('api_code_ok');
            } elseif ($url !== '' && $url !== ($urls[$index] ?? '')) {
                if ($versions !== null) {
                    $url = $versions;
                } elseif (($config['urlrole'] ?? 0) == 1) {
                    $url = implode('#', array_unique(array_merge(explode('#', $urls[$index] ?? ''), explode('#', $url))));
                }
                $urls[$index] = $url;
                $changed = true;
                $color = 'green';
                $des .= lang('api_code_up');
            } else {
                $des .= lang('api_code_nup');
            }
        }
        if (!$changed) return array();
        // 旧数据可能省略末尾空 server/note，逐组补齐以免追加线路后错位。
        $aligned = static function (array $values) use ($from): string {
            $out = array();
            foreach ($from as $i => $_) $out[] = $values[$i] ?? '';
            return implode('$$$', $out);
        };
        return array(
            'play_from' => implode('$$$', $from),
            'play_url' => xn_url_strip($aligned($urls)),
            'play_server' => xn_url_strip($aligned($servers)),
            'play_note' => xn_url_strip($aligned($notes)),
        );
    }

    /**
     * 图文采集页入库：按 conf['bind'][$code]「源类型名→cid」逐条处理——
     * 名称 htmlspecialchars(ENT_QUOTES)、pic 仅认 http(s) 且 html_entity_decode 后过 xn_url_strip、
     * content html_entity_decode + mac://→https:// 替换后过 xn_html_safe（富文本入库白名单，红线 §二.4）；
     * 按名查重不存在 → RepositoryFactory::art()->insert → art_aid_create → 分类计数+1 →
     * 文章专用标签（ArticleTagService::post + 120 字符截断）经 art_update 补写；已存在则跳过。
     * 定时采集使用 strict 模式：文章、分类关联与计数按页提交，数据库失败抛异常供任务重试。
     * @param array $apidate fetchJson/fetchArtJson 的返回结构（须含 'data' 源数据行列表）
     * @param string $code 采集源编码
     * @return array 每条一行的展示文本（名称已转义），供拼接输出
     */
    public static function importArtData($apidate, $code, bool $strict = false)
    {
        \App\Service\System\ArticleTagSchema::ensureReady();
        $conf = _SERVER('conf');
        if (!isset($conf['bind'][$code])) {
            if ($strict) throw new \RuntimeException(lang('api_no_set'));
            exit(lang('api_no_set'));
        }
        $apicate = [];
        $ress = array();
        $db = _SERVER('db');
        $touched = $tagCache = array();
        if ($strict && ($db->inTransaction() || !$db->beginTransaction())) {
            throw new \RuntimeException('无法开始文章采集事务，请重试');
        }
        try {
            $apicate = CollectionCategoryService::validBindings((array)$conf['bind'][$code], 1);
            foreach ($apidate['data'] as $k => $v) {
                $color = 'red';
                $des = '';
                $msg = '';
                $arr = array();
                if (!isset($apicate[$v['type_name']])) {
                    // 上线前审计修复（H-A2）：$v['type_name'] 来自远端采集接口，
                    // 原样拼入 $ress 后经 api_res.html 裸 echo（采集=任意第三方源的信任边界，存储型后台 XSS）。
                    $des = lang('api_type_ok') . htmlspecialchars((string)$v['type_name'], ENT_QUOTES, 'UTF-8');
                } elseif (empty($v['art_name'])) {
                    $des = '名称不得为空';
                } else {
                    $arr['name'] = htmlspecialchars($v['art_name'], ENT_QUOTES, 'UTF-8');
                    $arr['views'] = intval($v['art_hits']);
                    $arr['pic'] = preg_match('#^https?://#i', $v['art_pic']) ? xn_url_strip(html_entity_decode($v['art_pic'])) : '';
                    $arr['create_date'] = time();
                    $cid = $apicate[$v['type_name']];
                    $arr['cid'] = $cid;
                    $get_tag = get_tag($v['art_name']);
                    $tags = $v['type_name'] . ',' . $get_tag;
                    $arr['content'] = self::sanitizeImportedArtContent($v['art_content'] ?? '');
                    // 查重口径同入库（转义后文本），原文比对会让带 & < > " ' 的标题重复入库
                    $rows = \App\Repository\RepositoryFactory::art()->builder()
                        ->select(array('aid', 'content', 'pic'))->where('name', '=', $arr['name'])->limit(1)->get();
                    if ($strict && $rows === false) throw new \RuntimeException('文章查重失败，本页已回滚，请重试');
                    $info = $rows[0] ?? null;
                    if (!$info) {
                        $aid = \App\Repository\RepositoryFactory::art()->insert($arr);
                        if ($strict && !$aid) throw new \RuntimeException('文章入库失败，本页已回滚，请重试');
                        if ($aid) $touched[$aid] = $aid;
                        $art_aid = array('aid' => $aid, 'cid' => $cid);
                        $indexed = art_aid_create($art_aid);
                        if ($strict && $indexed === false) throw new \RuntimeException('文章分类关联写入失败，本页已回滚，请重试');
                        $counted = cate_update($cid, array('arts+' => 1));
                        if ($strict && !$counted) throw new \RuntimeException('文章分类计数更新失败，本页已回滚，请重试');
                        if ($aid > 0) {
                            $color = 'green';
                            $des = lang('api_db_ok');
                        } else {
                            $color = 'red';
                            $des = lang('api_db_err');
                        }
                        $vod_update = array();
                        $tag_json = \App\Service\Content\ArticleTagService::post($aid, $cid, $tags);
                        $tagCache += (array)json_decode($tag_json, true);
                        if (xn_strlen($tag_json) >= 120) {
                            $s = xn_substr($tag_json, -1, NULL);
                            if ('}' != $s) {
                                $len = mb_strripos($tag_json, ',', 0, 'UTF-8');
                                $tag_json = $len ? xn_substr($tag_json, 0, $len) . '}' : '';
                            }
                        }
                        !empty($tag_json) and $vod_update['tag'] = $tag_json;
                        if (!empty($vod_update) && FALSE === art_update($aid, $vod_update)) {
                            if ($strict) throw new \RuntimeException('文章标签保存失败，本页已回滚，请重试');
                            message(-1, lang('update_thread_failed'));
                        }
                    } else {
                        $art_update = array();
                        if (!self::artContentHasText($info['content'] ?? '') && self::artContentHasText($arr['content'] ?? '')) {
                            $art_update['content'] = $arr['content'];
                        }
                        if (empty($info['pic']) && !empty($arr['pic'])) {
                            $art_update['pic'] = $arr['pic'];
                        }
                        if (!empty($art_update)) {
                            $aid = intval($info['aid']);
                            $touched[$aid] = $aid;
                            if (FALSE === art_update($aid, $art_update)) {
                                if ($strict) throw new \RuntimeException('文章更新失败，本页已回滚，请重试');
                                message(-1, lang('update_thread_failed'));
                            }
                            $des .= '内容补全成功。';
                        } else {
                            $des .= '内容存在 跳过。';
                        }
                    }
                }
                $ress[] = ($k + 1) . '、' . htmlspecialchars($v['art_name'], ENT_QUOTES, 'UTF-8') . " <font color='{$color}'>" . $des . '</font>' . $msg . '';
            }
            if ($strict && !$db->commit()) throw new \RuntimeException('文章采集提交失败，请重试当前页');
        } catch (\Throwable $e) {
            if ($strict) $db->rollBack();
            throw $e;
        } finally {
            if ($strict && $touched) {
                // 事务结束后再次失效，避免其他请求在提交前重新缓存旧文章或分类计数。
                foreach ($touched as $aid) content_cache_delete('art_', $aid);
                foreach ($tagCache as $tagid => $name) {
                    cache_delete('web_tag_' . $tagid);
                    cache_delete('web_tag_' . md5($name));
                }
                cache_delete('cate-alias-cache');
                cache_delete('catelist');
                page_cache_bump();
                \App\Service\Front\TypeService::bumpFacets();
            }
        }
        if (!empty($conf['down_pic']) && $touched) self::syncArticlePosters($touched);
        return $ress;
    }

    /** Bound source-image downloads for the whole page; deferred images keep their external URLs. */
    private static function syncArticlePosters(array $touched): void
    {
        $deadline = microtime(true) + 20;
        $images = array();
        foreach (array_chunk(array_values($touched), 500) as $ids) {
            $rows = \App\Repository\RepositoryFactory::art()->builder()->select(['aid','pic'])->whereIn('aid', $ids)->get();
            if ($rows === false) { xn_log('article image candidates could not be read; external URLs are preserved', 'attachment_error'); return; }
            foreach ($rows as $image) {
                if (!empty($image['pic']) && preg_match('~^https?://~i', $image['pic'])) $images[$image['pic']][] = (int)$image['aid'];
            }
        }
        if (!$images) return;
        $prefetch = array();
        try {
            if (microtime(true) < $deadline && function_exists('https_request_multi')) {
                // Keep the article image size allowance; total bytes, URL count and deadline are shared per page.
                $prefetch = https_request_multi(array_keys($images), 10, 5, \App\Service\System\AttachmentStorage::MAX_BYTES);
            }
        } catch (\Throwable $e) { xn_log('article image prefetch failed: ' . $e->getMessage(), 'attachment_error'); }
        $ready = count(array_filter($prefetch, static fn($bytes) => is_string($bytes) && $bytes !== ''));
        if (count($images) > $ready) {
            xn_log('article image prefetch deferred ' . (count($images) - $ready) . ' URL(s): request failed or batch limit reached '
                . '(4 concurrent, 24 MiB total, 100 URLs, 20 seconds). External URLs are preserved; retry in attachment management.', 'attachment_error');
        }
        $processed = 0;
        $referencesChanged = false;
        foreach ($images as $source => $aids) {
            $bytes = $prefetch[$source] ?? null;
            // Empty/failed/missing entries must never trigger an unbounded single-image fallback.
            if (!is_string($bytes) || $bytes === '') continue;
            // FTP transfer/verification also share the page deadline. An in-flight transfer
            // may finish under its own timeout, but it cannot start an unlimited series.
            if ($processed >= 100 || microtime(true) >= $deadline) {
                xn_log('article image storage deferred ' . ($ready - $processed) . ' prefetched URL(s): page time/count limit reached. '
                    . 'External URLs are preserved; retry in attachment management.', 'attachment_error');
                break;
            }
            $processed++;
            try {
                $storage = \App\Service\System\AttachmentStorage::settings(true);
                $asset = \App\Service\System\AttachmentService::transfer($source, $storage['backend'], $storage, $bytes);
            } catch (\Throwable $e) { xn_log('article image sync failed: ' . $e->getMessage(), 'attachment_error'); continue; }
            foreach ($aids as $aid) {
                if ($asset['url'] === $source) continue;
                try {
                    $updated = \App\Service\System\AttachmentService::withReference($asset, static fn()=>\App\Repository\RepositoryFactory::art()->update(['aid'=>$aid,'pic'=>$source], ['pic'=>$asset['url']]));
                    if ($updated === false) throw new \RuntimeException('文章图片引用保存失败，已保留原链接');
                    if ($updated) { content_cache_delete('art_', $aid); $referencesChanged = true; }
                } catch (\Throwable $e) { xn_log('article image reference deferred: ' . $e->getMessage(), 'attachment_error'); }
            }
            if (!$storage['keep_local']) {
                try { \App\Service\System\AttachmentService::cleanupLocal($asset); }
                catch (\Throwable $e) { xn_log('article image cleanup deferred: ' . $e->getMessage(), 'attachment_error'); }
            }
        }
        // Pages may have been rendered while posters were downloading after the content commit.
        if ($referencesChanged) page_cache_bump();
    }

    /** 资讯采集补正文：已有文章只在正文确实为空时才补，避免把人工编辑内容覆盖掉。 */
    private static function artContentHasText($html): bool
    {
        $plain = html_entity_decode((string)$html, ENT_QUOTES, 'UTF-8');
        $plain = strip_tags($plain);
        $plain = preg_replace('#(?:&nbsp;|\s|　)+#u', '', $plain);
        return '' !== (string)$plain;
    }

    /**
     * 资讯源正文常带整页 HTML 包裹（<html><body><article>…），旧口径 xn_html_safe() 在这类输入上会整段清空，
     * 导致 art.content 入库为 ''。这里收口为“保留常见正文标签 + 属性审计”，只用于第三方资讯采集入库。
     */
    private static function sanitizeImportedArtContent($html): string
    {
        $html = html_entity_decode((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace('mac://', 'https://', $html);
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html);
        if ('' === trim((string)$html)) return '';

        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link|base)[^>]*>.*?</\\1>#is', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $allowedTags = '<img><p><br><b><strong><i><em><u><ul><ol><li><a><span><div><blockquote><code><pre><h1><h2><h3><h4><h5><h6>'
            . '<hr><table><thead><tbody><tr><th><td>'
            . '<section><article><aside><nav><header><footer><figure><figcaption>';
        $html = strip_tags($html, $allowedTags);

        $html = preg_replace_callback('/<([a-z1-6]+)\s*([^>]*)>/i', function ($matches) {
            $tagName = strtolower((string)$matches[1]);
            $attrs = self::parseImportedArtAttributes((string)$matches[2]);
            $allowedCommon = array('class', 'id', 'style', 'title');
            $allowedMap = array(
                'a'   => array('href', 'target', 'rel'),
                'img' => array('src', 'alt', 'width', 'height'),
                'ol'  => array('type', 'start'),
                'ul'  => array('type'),
            );
            $allowedList = array_merge($allowedCommon, $allowedMap[$tagName] ?? array());
            $cleanAttrs = array();
            foreach ($attrs as $name => $val) {
                if (!in_array($name, $allowedList, TRUE)) continue;
                $val = self::sanitizeImportedArtAttributeValue($tagName, $name, $val);
                if (NULL !== $val) {
                    $cleanAttrs[] = sprintf('%s="%s"', $name, htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                }
            }
            return '<' . $tagName . (empty($cleanAttrs) ? '' : ' ' . implode(' ', $cleanAttrs)) . '>';
        }, $html);

        return trim((string)$html);
    }

    /** 解析 HTML 开始标签上的属性。 */
    private static function parseImportedArtAttributes(string $attrStr): array
    {
        $attrs = array();
        if (preg_match_all('/([a-z0-9_-]+)\s*=\s*(?:["\']([^"\']*)["\']|([^\s>]+))/i', $attrStr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = strtolower((string)$m[1]);
                $value = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
                $attrs[$name] = (string)$value;
            }
        }
        return $attrs;
    }

    /** 资讯正文属性值白名单。 */
    private static function sanitizeImportedArtAttributeValue(string $tag, string $name, string $val): ?string
    {
        if ('src' === $name && preg_match('#^data:image/(?:jpg|jpeg|gif|png);base64,[A-Za-z0-9+/=]+$#i', $val)) {
            return $val;
        }
        if (in_array($name, array('href', 'src', 'action'), TRUE) && self::isDangerousImportedArtProtocol($val)) {
            return '#';
        }
        if ('style' === $name) {
            return self::auditImportedArtInlineStyle($val);
        }
        if ('id' === $name) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-_:.]*\z/', $val)) return NULL;
            if (in_array(strtolower($val), array('location', 'closed', 'frames', 'length', 'name', 'opener', 'status'), TRUE)) return NULL;
        }
        return $val;
    }

    /** 危险协议拦截。 */
    private static function isDangerousImportedArtProtocol(string $val): bool
    {
        $normalized = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/[\s\x00-\x1F\x7F-\x9F\xAD]/u', '', $normalized);
        $normalized = strtolower((string)$normalized);
        foreach (array('javascript:', 'data:', 'vbscript:', 'mocha:', 'livescript:') as $prefix) {
            if (0 === strpos($normalized, $prefix)) return TRUE;
        }
        return FALSE;
    }

    /** 行内样式审计：拒绝脚本式样与非 http(s) 的 url()。 */
    private static function auditImportedArtInlineStyle(string $style): ?string
    {
        $normalized = strtolower($style);
        foreach (array('expression', 'javascript', 'behavior', 'vmlframe', '-moz-binding', '@import') as $word) {
            if (FALSE !== strpos($normalized, $word)) return NULL;
        }
        if (FALSE !== strpos($normalized, 'url')) {
            $n = preg_match_all('#url\s*\(\s*([\'"]?)(.*?)\1\s*\)#is', $style, $matches);
            if (!$n) return NULL;
            foreach ((array)$matches[2] as $u) {
                $u = trim((string)$u);
                if ('' === $u || !preg_match('#^https?://#i', $u)) return NULL;
            }
        }
        return $style;
    }

    /**
     * 取采集源全量总页数（h='' 不限窗口口径，rsyncStart 的分母）：
     * 按内容类型取页，拿不到 pagecount 时原样返回 lang('api_err') 字符串——
     * 调用方用 `$allPage != lang('api_err')` 区分成败，不是 int|false 语义。
     * @param string $encodeurl 采集源地址（core_decrypt 密文或明文 http(s)）
     * @return mixed 源站 pagecount（JSON 解出的 int/float/string 原样透传）或错误文案字符串
     */
    private static function apiPageCount($encodeurl, int $model = 0)
    {
        $apidate = $model === 1 ? self::fetchArtJson($encodeurl, '', '', '') : self::fetchVideoJson($encodeurl, '', '', '');
        if (!isset($apidate['page']['pagecount'])) {
            return lang('api_err');
        }
        return $apidate['page']['pagecount'];
    }


    /**
     * 视频通道取页：转发 fetchJson(..., ac='videolist')（MacCMS 协议）。
     * @param string $encodeurl 采集源地址（密文或明文 http(s)）
     * @param string $t 源站时间参数（urlencode 透传）
     * @param int|string $pg 页码
     * @param string $h 小时窗口（''=全量）
     * @return array 结构同 fetchJson（成功含 page/data；失败 code=1002）
     */
    public static function fetchVideoJson($encodeurl, $t, $pg, $h): array
    {
        return self::fetchJson($encodeurl, $t, $pg, $h, 'videolist');
    }

    /**
     * 图文通道取页：转发 fetchJson(..., ac='detail')（MacCMS 协议）。
     * @param string $encodeurl 采集源地址（密文或明文 http(s)）
     * @param string $t 源站时间参数（urlencode 透传）
     * @param int|string $pg 页码
     * @param string $h 小时窗口（''=全量）
     * @return array 结构同 fetchJson
     */
    public static function fetchArtJson($encodeurl, $t, $pg, $h): array
    {
        return self::fetchJson($encodeurl, $t, $pg, $h, 'detail');
    }

    /**
     * MacCMS 协议底层取页：core_decrypt($encodeurl) 解出基址，解不出但原值本身是 http(s)
     * 明文时直通（新商店下发明文 URL，见体内注释）；GET `?ac=$ac&t=&pg=&h=&ids=&wd=`。
     * 响应非 JSON → array(code=1002, msg 含 url+响应前 150 字摘要)——失败也是数组，
     * 调用方统一以 isset(page.pagecount) 判成败。
     * 成功归一为 {code:1, msg, page:{page,pagecount,pagesize,recordcount,url}, type:[], data:[行]}；
     * ac=videolist 且行带 dl（下载源分组对象）时展开成 vod_play_from/url/server/note
     * （'$$$' 分隔，server 填 'no'、note 留空）。
     * @param string $encodeurl 采集源地址（core_decrypt 密文或明文 http(s)）
     * @param string $t 源站时间参数（urlencode）
     * @param int|string $pg 页码（urlencode）
     * @param string $h 小时窗口（urlencode，''=全量）
     * @param string $ac 协议动作 videolist|detail
     * @return array 归一结构（键 data/page/code/msg）
     */
    private static function fetchJson($encodeurl, $t, $pg, $h, $ac): array
    {
        $decodeurl = core_decrypt($encodeurl);
        // 新商店对接：商店下发的是**明文** URL（不加密），core_decrypt 解不出即返回空串，
        // 旧实现据此把整条链判死（症状：后台「今日/本周/全部采集」报 `JSON格式错误, url: ?ac=…`——
        // base URL 为空）。仅当原值本身就是 http(s) 时才直通；出站仍全程过 outbound_url_safe。
        if ('' === $decodeurl && preg_match('#^https?://#i', (string)$encodeurl)) {
            $decodeurl = (string)$encodeurl;
        }
        $uri = "?ac=" . $ac . "&t=" . urlencode($t) . "&pg=" . urlencode($pg) . "&h=" . urlencode($h) . "&ids=&wd=";
        $url = "$decodeurl" . $uri;
        $html = https_request($url, '', '', 30, 0, 8 * 1048576); // 传输中 8MB 上限（审查修复：采集页整包入内存面）
        $json = json_decode((string)$html, true);
        if (!is_array($json) || !isset($json['page'], $json['pagecount'], $json['limit'], $json['total'], $json['list'])
            || !is_array($json['list']) || !is_numeric($json['pagecount']) || (int)$json['pagecount'] < 0) {
            // 源不可达 / 被出站闸拦截（https_request 返回 FALSE）/ 非 JSON：留错误日志，不再静默
            xn_log('collect fetch failed: ' . (FALSE === $html ? 'request blocked or unreachable' : 'invalid json') . ', url: ' . $url, 'error');
            return array('code' => 1002, 'msg' => 'JSON格式错误' . ', url: ' . $url . ', response: ' . mb_substr((string)$html, 0, 150));
        }
        $array_page = array();
        $array_page['page'] = $json['page'];
        $array_page['pagecount'] = $json['pagecount'];
        $array_page['pagesize'] = $json['limit'];
        $array_page['recordcount'] = $json['total'];
        $array_page['url'] = $url;
        $array_data = array();
        foreach ($json['list'] as $key => $v) {
            $array_data[$key] = $v;
            if ($ac === 'videolist' && !empty($v['dl'])) {
                $array_from = array();
                $array_url = array();
                $array_server = array();
                $array_note = array();
                foreach ($v['dl'] as $k2 => $v2) {
                    $array_from[] = $k2;
                    $array_url[] = $v2;
                    $array_server[] = 'no';
                    $array_note[] = '';
                }
                $array_data[$key]['vod_play_from'] = implode('$$$', $array_from);
                $array_data[$key]['vod_play_url'] = implode('$$$', $array_url);
                $array_data[$key]['vod_play_server'] = implode('$$$', $array_server);
                $array_data[$key]['vod_play_note'] = implode('$$$', $array_note);
            }
        }
        return array('code' => 1, 'msg' => $ac === 'videolist' ? 'json' : 'ok', 'page' => $array_page, 'type' => array(), 'data' => $array_data);
    }

    /**
     * HTTP GET 拉取（原 core.php get_url 下沉）
     * @param string $encurl 目标 URL（历史为 core_decrypt 密文；亦接受明文 http(s)）
     * @return string 响应内容，失败返回空串
     */
    public static function httpGet(string $encurl): string
    {
        $url = core_decrypt($encurl);
        // 新商店对接：商店 `cloudPayload()['list'][*]['api']` 是**明文** URL，
        // 而本函数原先只认密文 ⇒ 明文一律解出空串 ⇒ 后台「自动绑定」拿不到源分类、
        // 却仍把 kv('bind') 写成空数组并报成功（绑定被静默清空）。明文直通不放松出站闸：
        // 下面 outbound_url_safe() + outbound_curl_exec() 逐跳复查照旧全跑。
        if ('' === $url && preg_match('#^https?://#i', $encurl)) {
            $url = $encurl;
        }
        if (!$url) return '';
        $res = https_request($url, '', '', 10, 0, 8 * 1048576);
        return $res === false ? '' : $res;
    }

    /**
     * 取采集源**分类列表**（`class`）——新商店对接修复（原先 4 处各自为政）。
     *
     * 真凶是「明文被 `core_decrypt` 判死」（见 httpGet 注释），本方法只是把取 class 的口径收成一处，
     * 并加一条防御性次级请求：先按原 URL 取一次；**若拿不到 class 且 URL 上还没有 `ac=` 参数**，
     * 补 `ac=list` 再取一次。
     *
     * 关于 `ac=list`（口径已实测纠偏，勿再照抄旧说法）：本站测试源 `example.com/.../vod/`
     * **裸 URL 本身就返回 `class`×60**（`ac=videolist` 那种才不带 class）；补 `ac=list` 只是为
     * 「根路径不返回 class」的其它 MacCMS 源留的兜底，属无害冗余，不是本站故障原因。
     *
     * 两次都拿不到返回空数组——调用方据此 fail-closed（不得把空结果写库/缓存/清空既有绑定）。
     *
     * @param string $api 采集源 api 地址（明文或历史密文均可）
     * @return array class 数组（失败为空数组）
     */
    public static function fetchSourceClassList(string $api): array
    {
        $api = trim($api);
        if ('' === $api) return array();
        $cands = array($api);
        if (!preg_match('#[?&]ac=#i', $api)) {
            $cands[] = $api . (false === strpos($api, '?') ? '?' : '&') . 'ac=list';
        }
        foreach ($cands as $u) {
            $raw = self::httpGet($u);
            if ('' === trim((string)$raw)) continue;
            $arr = is_array($raw) ? $raw : xn_json_decode($raw);
            if (is_array($arr) && !empty($arr['class']) && is_array($arr['class'])) {
                return $arr['class'];
            }
        }
        xn_log('collect class list failed: ' . $api, 'error');
        return array();
    }

    /**
     * 带缓存的分类列表（键 `api_cls_<code>`，与全站共享键空间隔离——H-A1 结论）。
     *
     * **空结果不写缓存**：分类取空多为网络/契约问题（可自愈），旧实现把空数组也 `cache_set` 1 小时，
     * 一次失败即把后续 1 小时全部毒化成「源无分类」。命中空缓存视为未命中。
     *
     * @param string $code 采集源编码
     * @param string $api  采集源 api 地址（非空时优先；为空则回退 conf['api'][$code]['api']）
     * @return array
     */
    public static function sourceClassList(string $code, string $api = ''): array
    {
        $cacheKey = 'api_cls_' . preg_replace('#[^A-Za-z0-9_-]#', '_', $code);
        $cached = cache_get($cacheKey);
        if (is_array($cached) && !empty($cached)) return $cached;

        // 已保存的源以配置为准；未保存（向导首次绑定）才用传入地址，且同样过协议闸
        $conf = _SERVER('conf');
        $stored = isset($conf['api'][$code]['api']) ? (string)$conf['api'][$code]['api'] : '';
        $api = '' !== $stored ? $stored : trim($api);
        if ('' === ApiSourceService::resolveUrl($api)) return array();
        $cls = self::fetchSourceClassList($api);
        if (!empty($cls)) cache_set($cacheKey, $cls, 3600);
        return $cls;
    }
}
