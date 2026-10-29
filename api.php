<?php
/**
 * 牛牛CMS 采集接口入口（依据 task/main.go 协议恢复，替代 SourceGuardian 混淆版）
 *
 * 调用方与协议：
 *   1. 异步采集调度服务（main.go，监听 8122）：
 *      - 健康检测：GET api.php?token={api_token}&route=api            → 200 响应体含 "ok"
 *      - 单页采集：GET api.php?route=rsync&token={api_token}&from_code={源code}&page={n}&h={窗口小时数}
 *        逐页回调执行视频+文章入库；成功响应含 "ok"（main.go 以 body 含 "ok" 判定成功，
 *        否则重试 10 次后标失败）
 *        **`h` 语义（修口径错位）**：`h` = 源站「最近 N 小时更新」窗口，商店
 *        rsync-task worker 直接透传任务行里的 `h`；`h` 为空 = **不限窗口（全量）**。
 *        此前空值被硬编码兜底成 `24`，而 CMS 的 `rsyncStart()` 用 `apiPageCount()`（`h=''`）
 *        算出的 `count` 是全量页数 ⇒ 「总页数/剩余页」按全量显示、每页却只拉 24h 窗口
 *        （实测 wsym3u8 全量 6288 页 vs 24h 仅 7 页），任务必然长期空跑且剩余页失真。
 *        现改为**空值即空值**（老 worker 显式传 h=24 时行为不变）。
 *   2. **本地地址替换执行器**：GET/POST api.php?route=replace&token={api_token}
 *        &old_domain=old.com&new_domain=new.com&type=play|pic&timeout=300
 *      把本站 `play_url`（type=play）或 `pic`（type=pic）里的老域名就地替换为新域名，
 *      回 JSON `{code:0,updated:N,…}`。**这是"替换"的唯一执行口径**：后台「设置→地址替换」
 *      的「执行替换」/「从商店拉取并应用」都调用同一个 `DomainReplaceService`（后台不 HTTP 回环
 *      打自己——本站地址多为内网/回环，会被出站闸 `outbound_url_safe()` 硬拦）。
 *      `timeout` 为**单次替换的 PHP 执行时限**（秒，5–1800，默认 300；大表 REPLACE 很慢）。
 *   3. 后台任务页 cron URL（TaskManageService 生成，宝塔计划任务直调）：
 *      GET api.php?route=cron&token={api_token}&code={源code}&h=24&froms={源code逗号分隔}
 *        按源入队采集（与 ApiExecutionService::cronRun 的队列化口径一致），响应 "ok"
 *
 * 安全（token 派生化）：{api_token} = api_wire_token('api')
 *   = md5( auth_key . '|nncms-api-token|' . 'api' )（派生式见 core/func/security.php::api_wire_token，
 *   auth_key 为空时该函数返回 ''，本入口随即不放行 ⇒ 未配 auth_key 的站点机器通道整体不可用）。
 * 校验是**两路或**（api.php 内 `$pass = ... || ...`），两路各自 hash_equals 恒时比较，但比较对象不同：
 *   ① 主路：把来路 token 与**派生值** api_wire_token('api') 比（auth_key 本体不上网络）；
 *   ② legacy 路：把来路 token 与 **auth_key 本体**比——仅当 conf['api_legacy_token'] 显式 === 1 才启用
 *     （上线前终检 N-03：出厂为 0；曾有的形态是「键缺失即放行」，与 fail-closed 口径相反，已改）。
 * auth_key 本体不得出现在任何 URL/access_log/对端存储；两路都不匹配一律 403。
 */
define('SKIP_ROUTE', TRUE); // 复用 index.php 框架引导链，跳过前台路由分发（admin.php 同模式）
!defined('DEBUG') AND define('DEBUG', 0);

// 机器通道跳过全局 XSS 过滤（XssFilterMiddleware 的既定开关 $_SERVER['XSS_SKIP']，见
// MiddlewarePipelineService::runXssFilter）。理由（真机实测）：该中间件对 $_POST 全量
// htmlspecialchars ⇒ store_push 的 `payload` JSON 里 `"` 变成 `&quot;`，json_decode 必然失败，
// 后台路由恒回 `{"code":1,"msg":"bad payload"}`（症状被上游 SSRF 闸遮蔽，放开内网后立刻暴露）。
// 本入口的请求体是结构化载荷（token/JSON/域名），安全边界由「出口 token 恒时校验 + 各路由自身
// 白名单校验」承担，而非全局转义；转义仍按红线在输出前最后一步做。
$_SERVER['XSS_SKIP'] = TRUE;

include __DIR__ . '/index.php';

// index.php 引导完成后 $conf 全局可用；兼容 _SERVER('conf') 门面
$conf = isset($conf) && is_array($conf) ? $conf : (_SERVER('conf') ?: array());

// ---- token 校验（恒时比较；派生化：主放行 api_wire_token，legacy auth_key 可关兼容） ----
$authKey = isset($conf['auth_key']) ? (string)$conf['auth_key'] : '';
// N-02（上线前审计）：商店替换推送 worker（rsync_task pushOne）把 token 放 POST body，
// 此前仅读 $_GET 导致 store_push 恒 403（fail-closed 死路）——GET/POST 双通道兼容读取，恒时比较逻辑不变。
// L-4（R4）：token 读取优先级 —— ① 请求头 X-NNCMS-Token（推荐，不进 access_log/URL）② POST body
//            ③ ?token=（**已弃用**，仅为老 cron/老 worker 兼容；会进 access_log，升级后请改 header 形态）。
$hdrTok = isset($_SERVER['HTTP_X_NNCMS_TOKEN']) ? (string)$_SERVER['HTTP_X_NNCMS_TOKEN'] : '';
$token  = '' !== $hdrTok ? $hdrTok
    : (isset($_POST['token']) ? (string)$_POST['token']
    : (isset($_GET['token']) ? (string)$_GET['token'] : ''));
$tokFromGet = ('' === $hdrTok) && !(isset($_POST['token']) && '' !== (string)$_POST['token']) && isset($_GET['token']) && '' !== (string)$_GET['token'];
$apiTok = api_wire_token('api');
// M-2 part2：专用商店令牌（store_report_token，与 auth_key 解耦）亦放行——商店 worker 的
// rsync 回调 / push-replacements 回带的正是安装上报登记的这枚令牌；缺省态该键为空 ⇒
// store_wire_token() 退化为 api_wire_token，与改造前逐字一致（恒时比较，fail-closed）。
$storeTok = function_exists('store_wire_token') ? (string)store_wire_token() : '';
// 上线前终检修复（N-03，Medium）：原判定 `!isset(...) || === 1` ⇒ **未配置=开启 legacy**，
// 而出厂 config.default.php 里根本没有该键（安装器原样 copy）⇒ 新装站默认接受**原始 auth_key** 当 token，
// 与「fail-closed / auth_key 不出现在任何 URL」的口径相反。现改为**显式开启才放行**。
$legacyOk = isset($conf['api_legacy_token']) && intval($conf['api_legacy_token']) === 1;
$isApiTok = ('' !== $apiTok && hash_equals($apiTok, $token));
$isStoreTok = ('' !== $storeTok && hash_equals($storeTok, $token));
$isLegacy = ($legacyOk && '' !== $authKey && hash_equals($authKey, $token));
$pass = $isApiTok || $isStoreTok || $isLegacy;
if (!$pass) {
    http_response_code(403);
    exit('invalid token');
}

// L-4（R4）：鉴权通过且用了已弃用的 ?token= 查询串 ⇒ 记一条脱敏弃用告警（token 永不原样落日志；每小时至多一条，避免刷屏）。
if ($tokFromGet && function_exists('xn_log')) {
    $__m = strlen($token) > 8 ? substr($token, 0, 4) . str_repeat('*', max(0, strlen($token) - 8)) . substr($token, -4) : '****';
    if (!class_exists('\App\Service\System\ThrottleService') || \App\Service\System\ThrottleService::check('api_token_dep', 'g', 1, 3600)) {
        xn_log('api.php 使用了已弃用的 ?token= 查询串鉴权（token 已脱敏：' . $__m . '）；请改用 header X-NNCMS-Token', 'api_token_deprecated');
    }
}

$route = isset($_GET['route']) ? (string)$_GET['route'] : 'api';

// R3-4：商店令牌（store_report_token）只放行健康检测与 rsync；replace / cron / store_push 需站点 api 令牌（store_push 另需开关+签名）。
if ($isStoreTok && !$isApiTok && !$isLegacy) {
    if (!in_array($route, array('api', 'rsync'), TRUE)) {
        http_response_code(403);
        exit('store token not allowed for this route');
    }
}

switch ($route) {

    // ---- 健康检测（main.go /check 调用） ----
    case 'api':
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ok';
        exit;

    // ---- 计划任务采集（后台任务页 cron URL，入队异步逐源执行） ----
    case 'cron':
        $code = isset($_GET['froms']) && '' !== (string)$_GET['froms']
            ? (string)$_GET['froms']
            : (isset($_GET['code']) ? (string)$_GET['code'] : '');
        $apis = isset($conf['api']) && is_array($conf['api']) ? $conf['api'] : array();
        if ('' !== $code) {
            // froms 逗号分隔多源，逐源入队（TaskQueueService，队列化口径）
            foreach (explode(',', $code) as $c) {
                $c = trim($c);
                if ('' !== $c && !empty($apis[$c]['api']) && class_exists('\App\Service\System\TaskQueueService')) {
                    // 同源已有 pending/running 任务时跳过，防计划任务高频调用重复入队（审计修复）
                    \App\Service\System\TaskQueueService::enqueue('cron_source', array('code' => $c));
                }
            }
        } else {
            // 未指定源：全源入队（与 ApiExecutionService::cronRun 一致）
            \App\Service\Collection\ApiExecutionService::cronRun(0);
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ok';
        exit;

    // ---- 单页异步采集（main.go runTask 每页回调） ----
    case 'rsync':
        $fromCode = isset($_GET['from_code']) ? (string)$_GET['from_code'] : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        // 修口径错位：`h` 原为「空即兜底 24h」，但 rsyncStart() 下发的 count 是
        // **全量页数**（apiPageCount 用 h=''）⇒ 每页只拉 24h 窗口而分母是全量，剩余页失真、
        // 任务长期空跑。改为**空值透传为空**（= 源站不限窗口/全量），与 count 口径一致；
        // 只允许数字（源站窗口参数），异常值一律按空处理（防注入源站查询串）。
        $h = isset($_GET['h']) ? preg_replace('#[^0-9]#', '', (string)$_GET['h']) : '';
        $apis = isset($conf['api']) && is_array($conf['api']) ? $conf['api'] : array();
        if ('' === $fromCode || empty($apis[$fromCode]['api'])) {
            http_response_code(400);
            exit('unknown from_code');
        }
        if (!isset($conf['bind'][$fromCode])) {
            // 未绑定分类：直接报错（importVideoData/importArtData 内部会 exit，预检避免假成功）
            http_response_code(400);
            exit('from_code not bind');
        }
        $encodeurl = (string)$apis[$fromCode]['api'];
        try {
            // 视频 + 文章同页采集（采集源为混合站，各自对响应结构做判断）
            $resV = \App\Service\Collection\ApiExecutionService::videoApiPage($encodeurl, $fromCode, '', $page, $h);
            $resA = \App\Service\Collection\ApiExecutionService::artApiPage($encodeurl, $fromCode, '', $page, $h);
        } catch (\Throwable $e) {
            // 单页失败不返回 "ok" → main.go 侧重试（最多 10 次）
            if (function_exists('xn_log')) {
                xn_log('api rsync failed: ' . $e->getMessage(), 'api_rsync_error');
            }
            http_response_code(500);
            exit('rsync error');
        }
        // fetch 失败时 videoApiPage/artApiPage 返回 api_err 数组（不抛异常）——
        // 两路均失败才报错，否则 main.go 会误判成功
        $errText = lang('api_err');
        $strV = implode('|', (array)$resV);
        $strA = implode('|', (array)$resA);
        $vFailed = '' !== $errText && str_contains($strV, $errText);
        $aFailed = '' !== $errText && str_contains($strA, $errText);
        if ($vFailed && $aFailed) {
            http_response_code(500);
            exit('rsync fetch error');
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ok';
        exit;

    // ---- 本地地址替换执行器（新增；token 已在入口恒时校验）----
    // 与后台「地址替换」共用 DomainReplaceService：列名白名单 / 域名格式闸 / 预编译 REPLACE /
    // 单次替换可单独放宽 PHP 执行时限（timeout 秒，5–1800，默认 300）。
    case 'replace':
        header('Content-Type: application/json; charset=utf-8');
        $rr = \App\Service\Content\DomainReplaceService::run(array(
            'type'       => isset($_REQUEST['type']) ? (string)$_REQUEST['type'] : 'play',
            'old_domain' => isset($_REQUEST['old_domain']) ? (string)$_REQUEST['old_domain'] : '',
            'new_domain' => isset($_REQUEST['new_domain']) ? (string)$_REQUEST['new_domain'] : '',
            'timeout'    => isset($_REQUEST['timeout']) ? intval($_REQUEST['timeout']) : 0,
        ));
        if (0 !== $rr['code']) {
            http_response_code(400);
        }
        echo xn_json_encode($rr);
        exit;

    // ---- 商店替换推送接收（rsync_task /push-replacements → 本路由）----
    // POST token=同上（安装上报登记的站点令牌，恒时校验已在入口完成）+ payload=JSON
    // {source, replacements:[{type:play|pic, old_domain, new_domain}], pushed_at}
    // 应用：play → vod.play_url；pic → vod.pic（预编译 REPLACE 原子替换；幂等：已替换过=0 行）
    case 'store_push':
        header('Content-Type: application/json; charset=utf-8');
        // R3-4：入站推送双保险——站点显式开启 store_push_on，且清单带商店 Ed25519 签名（同 NNCMS_STORE_SIGN_PUBKEY）。
        if (1 !== intval($conf['store_push_on'] ?? 0)) {
            http_response_code(403);
            echo json_encode(array('code' => 1, 'msg' => 'store_push disabled (store_push_on!=1)'));
            exit;
        }
        $pushPayload = isset($_POST['payload']) ? (string)$_POST['payload'] : '';
        $pushSig = isset($_SERVER['HTTP_X_STORE_SIG']) ? trim((string)$_SERVER['HTTP_X_STORE_SIG']) : '';
        if ('' === $pushSig && isset($_POST['sig'])) $pushSig = trim((string)$_POST['sig']);
        $pushExp = isset($_SERVER['HTTP_X_STORE_SIG_EXP']) ? intval($_SERVER['HTTP_X_STORE_SIG_EXP']) : (isset($_POST['sig_exp']) ? intval($_POST['sig_exp']) : 0);
        $payloadSha = hash('sha256', $pushPayload);
        $pushMsg = 'store_push|' . $payloadSha . '|' . $pushExp;
        $pushChk = \App\Service\System\CloudOpsService::verifyStoreSignature($pushMsg, $pushSig);
        if (!$pushChk['ok'] && $pushExp > 0) {
            // 兼容无过期字段的清单
            $pushChk = \App\Service\System\CloudOpsService::verifyStoreSignature('store_push|' . $payloadSha, $pushSig);
        }
        if (!$pushChk['ok']) {
            http_response_code(403);
            echo json_encode(array('code' => 1, 'msg' => 'store_push signature rejected: ' . $pushChk['msg']));
            exit;
        }
        if ($pushExp > 0 && $pushExp < time()) {
            http_response_code(403);
            echo json_encode(array('code' => 1, 'msg' => 'store_push signature expired'));
            exit;
        }
        $pushData = json_decode($pushPayload, TRUE);
        // 兼容未升级站点：旧入口未置 XSS_SKIP，全局 XSS 过滤把 payload 里的
        // `"` 转成 `&quot;` ⇒ json_decode 失败。此处还原实体后重试一次（域名仍由下方 $okDomain
        // 白名单正则复核，还原不会放宽任何校验）。
        if (!is_array($pushData) && '' !== $pushPayload) {
            $pushData = json_decode(htmlspecialchars_decode($pushPayload, ENT_QUOTES), TRUE);
        }
        $pushReps = is_array($pushData) && isset($pushData['replacements']) && is_array($pushData['replacements'])
            ? $pushData['replacements'] : array();
        if (!count($pushReps)) {
            echo json_encode(array('code' => 1, 'msg' => 'bad payload'));
            exit;
        }
        $okDomain = function ($d) {
            return is_string($d) && '' !== $d && 1 === preg_match('#^[A-Za-z0-9.-]+(:\d+)?$#', $d);
        };
        $pushDb = _SERVER('db');
        $pushVod = $pushDb->tablepre . 'vod';
        $pushDone = 0;
        foreach ($pushReps as $pr) {
            $pushType = isset($pr['type']) && 'pic' === $pr['type'] ? 'pic' : 'play';
            $pushOld = isset($pr['old_domain']) ? trim((string)$pr['old_domain']) : '';
            $pushNew = isset($pr['new_domain']) ? trim((string)$pr['new_domain']) : '';
            if (!$okDomain($pushOld) || !$okDomain($pushNew) || $pushOld === $pushNew) continue;
            $pushCol = 'play' === $pushType ? 'play_url' : 'pic';   // 白名单列，插值安全
            $pushStmt = $pushDb->prepare_execute(
                "UPDATE `{$pushVod}` SET `{$pushCol}` = REPLACE(`{$pushCol}`, ?, ?) WHERE `{$pushCol}` LIKE ?",
                array($pushOld, $pushNew, '%' . $pushOld . '%')
            );
            if ($pushStmt) $pushDone += $pushStmt->rowCount();
        }
        // 发布前检查修复（A-5）：敏感批量替换审计留痕——'_audit' 后缀通道按 LoggerService
        // 永久结论恒落盘（isAuditChannel），补「谁在何时替换了什么」的溯源面
        xn_log(xn_json_encode(array(
            'source' => isset($pushData['source']) ? substr((string)$pushData['source'], 0, 64) : '',
            'replacements' => count($pushReps),
            'updated' => $pushDone,
            'pushed_at' => isset($pushData['pushed_at']) ? substr((string)$pushData['pushed_at'], 0, 32) : '',
        )), 'store_push_audit');
        echo json_encode(array('code' => 0, 'msg' => 'ok', 'updated' => $pushDone));
        exit;

    default:
        http_response_code(400);
        exit('unknown route');
}
