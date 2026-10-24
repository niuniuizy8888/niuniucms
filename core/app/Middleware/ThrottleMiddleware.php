<?php
namespace App\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 限流中间件（渐进式迁移版）
 * 防止暴力破解、DDoS 攻击、恶意爬虫
 * 
 * 功能：
 * 1. 基于 IP + Session 的多维度限流
 * 2. 静态资源白名单放行
 * 3. 后台/管理路由白名单
 * 4. 爬虫识别与验证
 * 5. 黑名单机制
 * 6. 阶梯惩罚（违规次数越多，封禁时间越长；penalty_max 封顶）
 * 
 * 渐进式兼容：
 * - 不强制返回 ResponseInterface（兼容 echo/exit）
 * - 通过 $_SERVER 传递限流状态
 * - 可配置开关（config/throttle.php）
 *
 * 修复轮（用户实测「刷新十几次后浏览器显示 HTTP ERROR 429」）：
 * ① 429 响应此前只调 xn_error()（该函数仅写 $errno/$errstr 全局，从不渲染），
 *    MiddlewarePipelineService::run() 收 false → index.inc.php `exit;` ⇒ **空响应体**
 *    （nginx 记 5 字节=分块编码终止符），浏览器对空体 4xx 显示自带错误页
 *    「该网页无法正常运作 HTTP ERROR 429」，用户误判为站点故障。今起直出错误内容：
 *    HTML 走独立页面 `429.html`（webroot 纯静态读取 + `{{SECONDS}}/{{REASON}}/{{SITENAME}}`
 *    占位符替换，页面内倒计时归零自动刷新；文件缺失回退内联最小页），
 *    XHR/format=json|xml/api.php（如 `?danmu-list.html?...&format=xml`）回 JSON 短体。
 * ② 阶梯惩罚此前**只写不读**（penalty: 键无任何读取点）＝死代码，而 Retry-After 头
 *    照 6^n*10 外发（实测 16796160 秒=194 天，纯误导）。今起真正生效并按 penalty_max 封顶，
 *    惩罚期内直接拒绝且**不再累加违规**（否则「被拒→违规+1→惩罚翻 6 倍」自锁死循环）。
 * ③ 删除 recordRequest() 热路径无效写（request_log: 键全仓无读取点，且值含当小时全部 IP
 *    列表，每请求读改写 6 层缓存=纯开销）。
 * 修复轮（异步采集被自己人限流）：`/api.php` 的 30 请求/600 秒窗口对**机器通道**是错的——
 *   商店 rsync-task worker 逐页回调，第 31 页必撞 429 ⇒ worker 重试 10 次后记 `page fail`，
 *   任务转 `异常（error）`（真机实测 `page 31: http 429 | page fail`）。现按持有**有效 wire 令牌**
 *   分流豁免（详见 process() 内注释与 hasValidWireToken()），匿名/错令牌的洪水防护口径不变。
 */
class ThrottleMiddleware implements MiddlewareInterface
{
    /** @var array */
    private $config;
    
    /** @var array 静态资源扩展名 */
    private $staticExt = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'woff', 'woff2', 'ico'];
    
    /** @var array URL 前缀白名单 */
    private $urlPrefixAllow = ['admin', 'api'];
    
    /** @var array 爬虫 User-Agent 关键词 */
    private $botList = ['Googlebot', 'Baiduspider', 'bingbot', 'YandexBot', 'Sogou'];
    
    /** @var bool 是否启用爬虫验证 */
    private $verifyRobot = false;
    
    /** @var bool 是否启用 DNS 反查验证爬虫 */
    private $verifyDns = false;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable' => false,              // 总开关
            'session_limit' => 30,          // 单 Session 每分钟最大请求数
            'session_window' => 60,         // Session 时间窗口（秒）
            'ip_limit' => 10,               // 单 IP 每分钟最大请求数
            'ip_window' => 600,             // IP 时间窗口（秒）
            'api_limit' => 30,              // /api.php 未持有效令牌时的洪水窗口：请求数
            'api_window' => 600,            // /api.php 未持有效令牌时的洪水窗口：秒
            'api_token_exempt' => true,     // 持有效 wire 令牌的机器流量（cron/异步采集回调/商店推送）不限流
            'penalty_base' => 60,           // 阶梯惩罚基数（秒）：首次违规封禁时长
            'penalty_max' => 300,           // 阶梯惩罚上限（秒）：6^n 增长封顶，防「194 天 Retry-After」
            'verify_blacklist' => true,     // 启用黑名单检查
            'verify_robot' => false,        // 启用爬虫验证
            'verify_dns' => false,          // 启用 DNS 反查
            'bot_list' => [],               // 自定义爬虫列表
            'url_prefix_allow' => [],       // 自定义白名单
            'static_ext' => [],             // 自定义静态资源扩展名
        ], $config);
        
        if (!empty($this->config['bot_list'])) {
            $this->botList = $this->config['bot_list'];
        }
        if (!empty($this->config['url_prefix_allow'])) {
            $this->urlPrefixAllow = $this->config['url_prefix_allow'];
        }
        if (!empty($this->config['static_ext'])) {
            $this->staticExt = $this->config['static_ext'];
        }
        $this->verifyRobot = (bool)$this->config['verify_robot'];
        $this->verifyDns = (bool)$this->config['verify_dns'];
    }

    /**
     * 限流主入口（PSR-15 形状 + 渐进式兼容：不强制 Response，拦截时自行 echo 响应体后返回 FALSE）。
     * 判定顺序（enable=FALSE 总开关最先）：
     *  ① URL 白名单（admin_entry 后台入口、url_prefix_allow 段边界）或静态扩展名 ⇒ 放行；
     *  ② /api.php 机器通道：持**有效 wire 令牌**（hasValidWireToken，hash_equals 恒时）整体豁免限流，
     *     匿名/错令牌走独立洪水窗口（api_limit/api_window，超限 reason=1）；
     *  ③ 通用面按序：空 UA(1) → 黑名单(0) → 阶梯惩罚期内直接拒(2，不累加违规) →
     *     爬虫验证失败(5，需 verify_robot) → Session 超限(3) → IP 超限(5)，任一命中进 restrictAccess/deny 落 429。
     * 放行则透传 $handler->handle()；拦截返回 FALSE（上层 run() 收 false ⇒ index.inc.php exit）。
     * @param mixed $request 请求对象（管道传入的 PSR-7 形状 App\Request；签名刻意未声明类型）
     * @param RequestHandlerInterface $handler 下游处理器
     * @return mixed 下游 handle() 返回值；被拒时为 false
     */
    public function process($request, RequestHandlerInterface $handler)
    {
        // 限流未启用，直接放行
        if (!$this->config['enable']) {
            return $handler->handle($request);
        }
        
        // 白名单：后台路由 & 静态资源
        if ($this->isUrlAllowed() || $this->isStaticResource()) {
            return $handler->handle($request);
        }

        // 修复：api.php（采集/计划任务，token 鉴权入口）此前被 '/api' 前缀整体误豁免、零防护。
        // 改走独立宽松窗口——合法 cron 基线 ≤2 请求/分（余量充足），洪水/枚举仍可拦截。
        //
        // 修复（用户实测「异步采集 异常（error）：page 31: http 429 | page fail」）：
        // 原口径对**机器通道**是错的——商店 rsync-task worker 逐页回调 `?route=rsync`，一次采集动辄
        // 数百上千页（实测第 31 页撞 429，worker 重试 10 次后记 fail ⇒ 任务转 error）。现按「是否持
        // **有效 wire 令牌**」分流：令牌校验通过＝已鉴权的机器流量（本机 cron / 商店 worker / 商店推送）
        // ⇒ **不限流**；无令牌或令牌错 ⇒ 维持原 30/600s 洪水防护（匿名扫描的拦截口径**不降低**）。
        // 注意：豁免的只是"限流"，令牌校验本身仍在 api.php 内 fail-closed 403 —— 未鉴权请求一步也走不了。
        if ('api.php' === $this->requestBasename()) {
            if (!empty($this->config['api_token_exempt']) && $this->hasValidWireToken()) {
                return $handler->handle($request);
            }
            $apiIp = $this->getClientIp();
            $apiLimit = isset($this->config['api_limit']) ? (int)$this->config['api_limit'] : 30;
            $apiWindow = isset($this->config['api_window']) ? (int)$this->config['api_window'] : 600;
            if ($this->isOverLimit('throttle:apiip:' . $apiIp, $apiLimit, $apiWindow)) {
                return $this->restrictAccess(1, $apiIp, 'api', time());
            }
            return $handler->handle($request);
        }
        
        $now = time();
        $ip = $this->getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $sessionId = session_id() ?: 'no_session';
        
        // 空 UA 限制
        if (empty($userAgent)) {
            return $this->restrictAccess(1, $ip, $sessionId, $now);
        }
        
        // 黑名单检查
        if ($this->config['verify_blacklist'] && $this->inBlacklist($ip)) {
            return $this->restrictAccess(0, $ip, $sessionId, $now);
        }
        
        // 阶梯惩罚期内：直接拒绝，且不再累加违规（惩罚时长已定，续刷不升级）
        $penaltyWait = $this->penaltyRemaining($ip, $now);
        if ($penaltyWait > 0) {
            return $this->deny(2, $penaltyWait);
        }
        
        // 爬虫验证
        if ($this->verifyRobot && !$this->isCrawler($userAgent, $ip)) {
            return $this->restrictAccess(5, $ip, $sessionId, $now);
        }
        
        // Session 限流
        if ($this->isOverLimit('throttle:session:' . $sessionId, $this->config['session_limit'], $this->config['session_window'])) {
            return $this->restrictAccess(3, $ip, $sessionId, $now);
        }
        
        // IP 限流
        if ($this->isOverLimit('throttle:ip:' . $ip, $this->config['ip_limit'], $this->config['ip_window'])) {
            return $this->restrictAccess(5, $ip, $sessionId, $now);
        }
        
        return $handler->handle($request);
    }
    
    /**
     * 是否持**有效** wire 令牌（机器通道豁免限流的唯一依据）
     *
     * 与 api.php 入口的鉴权口径**逐字对齐**（派生令牌优先 + legacy `auth_key` 受 `api_legacy_token` 开关约束），
     * 保证"被豁免限流的集合" ⊆ "api.php 会放行的集合"：令牌错/缺失 ⇒ 不豁免，照旧走 30/600s 洪水窗口。
     * 恒时比较（hash_equals）；GET/POST 双通道读取（商店 worker 的 store_push 把 token 放 POST body）。
     */
    private function hasValidWireToken(): bool
    {
        // L-4（R4）：与 api.php 逐字对齐 —— header X-NNCMS-Token 优先，其次 POST body，最后 ?token=（弃用）。
        $hdrTok = isset($_SERVER['HTTP_X_NNCMS_TOKEN']) ? (string)$_SERVER['HTTP_X_NNCMS_TOKEN'] : '';
        $token = '' !== $hdrTok ? $hdrTok
            : (isset($_POST['token']) ? (string)$_POST['token']
            : (isset($_GET['token']) ? (string)$_GET['token'] : ''));
        if ('' === $token) return false;

        if (function_exists('api_wire_token')) {
            $derived = (string)api_wire_token('api');
            if ('' !== $derived && hash_equals($derived, $token)) return true;
        }

        // M-2 part2：专用商店令牌（store_report_token）同样豁免——与 api.php 入口逐字对齐，
        // 仍满足「被豁免集合 ⊆ api.php 放行集合」不变量（缺省态该键为空 ⇒ 退化为派生令牌）。
        if (function_exists('store_wire_token')) {
            $storeTok = (string)store_wire_token();
            if ('' !== $storeTok && hash_equals($storeTok, $token)) return true;
        }

        $conf = function_exists('_SERVER') ? _SERVER('conf') : null;
        $conf = is_array($conf) ? $conf : array();
        // 项目审查修复：原式 `!isset(...) || intval(...) === 1` 是 **fail-open** 形态——
        // 存量站点 config.php 没有该键时（升级不重写站点配置），中间件会把「拿原始 auth_key 当 token」
        // 的请求判为有效并豁免 30/600s 洪水窗口，而 api.php 同口径是 `isset(...) && === 1`（N-03 修复）
        // ⇒ 打破本方法自述不变量「被豁免限流的集合 ⊆ api.php 会放行的集合」。此处**逐字对齐 api.php**。
        $legacyOk = isset($conf['api_legacy_token']) && intval($conf['api_legacy_token']) === 1;
        $authKey = isset($conf['auth_key']) ? (string)$conf['auth_key'] : '';
        return $legacyOk && '' !== $authKey && hash_equals($authKey, $token);
    }

    /**
     * 检查 URL 是否在白名单
     */
    private function isUrlAllowed(): bool
    {
        $path = $this->requestPath();
        $allow = $this->urlPrefixAllow;
        // 修复失配：后台真实入口是随机改名文件（admin_entry），旧 '/admin' 前缀从未命中过
        $conf = _SERVER('conf');
        if (!empty($conf['admin_entry'])) {
            $allow[] = basename((string)$conf['admin_entry']);
        }
        foreach ($allow as $prefix) {
            $prefix = (string)$prefix;
            if ($prefix === '') continue;
            $prefix = trim($prefix, '/');
            // 子目录部署下 REQUEST_URI 可能是 /subdir/admin.php 或 /subdir/admin/...，
            // 这里按 path 段边界匹配，不再要求命中站点根。
            if (preg_match('#(?:^|/)' . preg_quote($prefix, '#') . '(?:$|/)#', $path)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 检查是否为静态资源
     */
    private function isStaticResource(): bool
    {
        $path = $this->requestPath();
        if (!$path) return false;
        
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $this->staticExt);
    }

    /**
     * 当前请求的 path（去掉 query string），保留子目录前缀。
     */
    private function requestPath(): string
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }

    /**
     * 当前请求脚本名（例如 api.php / admin.php）。
     */
    private function requestBasename(): string
    {
        $path = $this->requestPath();
        return '' !== $path ? basename($path) : '';
    }
    
    /**
     * 获取客户端真实 IP
     */
    private function getClientIp(): string
    {
        // 复用全局 ip()：仅在 cdn_on 且直连 IP 命中 cdn_trusted_ips 白名单时才采信转发头，防伪造 IP 绕过限流
        if (function_exists('ip')) {
            return (string)ip();
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * 检查是否超过限流阈值
     *
     * 上线前审计修复（VULN-M07）：原实现是 cache_get → cache_set 的**读改写**，
     * 属 TOCTOU——并发突发时所有请求读到同一计数、各自 +1 覆盖，上限形同虚设。
     * 现委托 ThrottleService::check()：APCu add/inc 原子通道 + flock 文件回退 + 缓存兜底，
     * 与登录/注册/弹幕/论坛/API 各场景同一实现（此前只有本中间件未收口）。
     * 键名沿用调用方传入的限流键（保持既有键语义与运维可读性），仅由 ThrottleService
     * 统一加 `throttle_<scene>_` 前缀命名空间。
     */
    private function isOverLimit(string $cacheKey, int $limit, int $window): bool
    {
        if (class_exists('\\App\\Service\\System\\ThrottleService')) {
            // check() 语义：返回 true=放行（count <= max）；本方法返回 true=超限，故取反
            return !\App\Service\System\ThrottleService::check('mw', $cacheKey, $limit, $window);
        }
        // 极端兜底（类不可用）：保留原读改写行为，避免限流整体失效
        if (!function_exists('cache_get') || !function_exists('cache_set')) {
            return false; // 缓存不可用时跳过限流
        }

        $count = (int)cache_get($cacheKey);
        if ($count >= $limit) {
            return true;
        }

        cache_set($cacheKey, $count + 1, $window);
        return false;
    }
    
    /**
     * 检查是否在黑名单
     */
    private function inBlacklist(string $ip): bool
    {
        if (!function_exists('cache_get')) return false;
        
        $key = 'ip_blacklist:' . $ip;
        return (bool)cache_get($key);
    }
    
    /**
     * 惩罚剩余秒数（0=无惩罚）
     *
     * 修复轮：超长惩罚（> penalty_max）一律判为历史/异常值并清除——旧实现
     * 按 6^n*10 写入过 5~194 天的 penalty 键（实测 penalty:192.0.2.12=466560s、
     * penalty:192.0.2.9=16796160s），若照读照封＝IP 被锁数天且重置 Redis 也无效
     * （MultiLayerCache 每层独立，APCu 按 FPM worker 各自持有）。收敛口径：惩罚永不超过
     * penalty_max，来源可疑即失效放行。
     */
    private function penaltyRemaining(string $ip, int $now): int
    {
        if (!function_exists('cache_get')) return 0;
        
        $expire = (int)cache_get('penalty:' . $ip);
        if ($expire <= $now) return 0;
        
        $max = max(1, (int)$this->config['penalty_max']);
        $wait = $expire - $now;
        if ($wait > $max) {
            if (function_exists('cache_delete')) cache_delete('penalty:' . $ip);
            return 0;
        }
        
        return $wait;
    }
    
    /**
     * 检测是否为爬虫
     */
    private function isCrawler(string $userAgent, string $ip): bool
    {
        // 内网 IP 放行
        if ($this->isPrivateIp($ip)) return true;
        
        // 空 UA 视为爬虫
        if (empty($userAgent)) return false;
        
        // 检查已知爬虫
        foreach ($this->botList as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                // DNS 反查验证
                if ($this->verifyDns && !$this->verifyBotDns($bot, $ip)) {
                    return false; // 伪造爬虫
                }
                return true; // 真实爬虫放行
            }
        }
        
        // 未知 UA 视为爬虫（可根据需求调整）
        return false;
    }
    
    /**
     * DNS 反查验证爬虫
     */
    private function verifyBotDns(string $bot, string $ip): bool
    {
        $host = gethostbyaddr($ip);
        if ($host === $ip) return false; // 反查失败
        
        // 检查域名是否匹配
        $domains = [
            'Googlebot' => 'googlebot.com',
            'Baiduspider' => 'baidu.com',
            'bingbot' => 'bing.com',
        ];
        
        $expected = $domains[$bot] ?? '';
        if (empty($expected)) return true; // 未知爬虫不验证
        
        return substr($host, -strlen($expected)) === $expected;
    }
    
    /**
     * 检查是否为内网 IP
     */
    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
    
    /**
     * 限制访问（统一响应）
     *
     * 修复轮：惩罚时长 6^n 增长并 penalty_max 封顶；Retry-After 与页面提示
     * 一律用本次实际封禁秒数（旧实现外发 6^8*10=16796160 秒，却从不真正封禁）。
     */
    private function restrictAccess(int $reason, string $ip, string $sessionId, int $now)
    {
        $base = max(1, (int)$this->config['penalty_base']);
        
        // 仅「速率型」违规（3=Session 超限 / 5=IP·爬虫超限）才落粘性阶梯惩罚：
        // 黑名单(0)/空 UA·api 洪水(1) 是逐请求判定、请求本身已在渲染前被拒，
        // 若也落 60s+ 粘性 IP 封禁，内网 NAT 后的正常用户会被无 UA 监控脚本一次误伤（实测口径）
        if (!in_array($reason, [3, 5], true)) {
            return $this->deny($reason, $base);
        }
        
        // 记录违规（24h 窗口，供阶梯惩罚升级）
        $this->recordViolation($ip, $sessionId, $reason);
        
        // 阶梯惩罚：base * 6^(违规次数-1)，封顶 penalty_max
        $violationKey = 'violation:' . $ip;
        $violations = (int)(function_exists('cache_get') ? cache_get($violationKey) : 0);
        $max = max($base, (int)$this->config['penalty_max']);
        $penaltyTime = (int)min($base * pow(6, max(0, min($violations, 8) - 1)), $max);
        
        if (function_exists('cache_set')) {
            cache_set('penalty:' . $ip, $now + $penaltyTime, $penaltyTime);
        }
        
        return $this->deny($reason, $penaltyTime);
    }
    
    /**
     * 输出 429 响应（修复轮：必须有响应体——空体 4xx 会被浏览器替换成
     * 自带错误页「该网页无法正常运作 HTTP ERROR 429」，用户读不到任何原因与重试提示）
     */
    private function deny(int $reason, int $retryAfter)
    {
        $retryAfter = max(1, (int)$retryAfter);
        
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        
        $msg = '访问过于频繁，请稍后再试。';
        if ($this->isDataRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['code' => 0, 'msg' => $msg, 'retry_after' => $retryAfter],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
            );
            return FALSE;
        }
        
        header('Content-Type: text/html; charset=utf-8');
        echo $this->errorPageHtml($retryAfter, $reason);
        
        return FALSE;
    }
    
    /**
     * 429 页面渲染：读 webroot/429.html（纯静态读取 + 占位符替换，不走模板编译与主题）
     *
     * 用户裁定：429 用独立页面显示——倒计时归零后自动刷新。页面内 JS 按
     * 截止时间戳（Date.now）+ 250ms 轮询计算剩余秒数，后台标签页计时器被节流也不会多等。
     * 文件缺失/不可读时回退内联最小页，保证「429 绝不空响应体」不依赖磁盘文件。
     */
    private function errorPageHtml(int $retryAfter, int $reason): string
    {
        $html = '';
        if (defined('APP_PATH')) {
            $file = APP_PATH . '429.html';
            if (is_file($file)) {
                $html = (string)@file_get_contents($file);
            }
        }
        if (trim($html) === '') {
            return $this->fallbackErrorHtml($retryAfter, $reason);
        }
        
        $conf = function_exists('_SERVER') ? _SERVER('conf') : null;
        $sitename = (is_array($conf) && !empty($conf['sitename'])) ? (string)$conf['sitename'] : '本站';
        
        return str_replace(
            array('{{SECONDS}}', '{{REASON}}', '{{SITENAME}}'),
            array((string)$retryAfter, (string)$reason, htmlspecialchars($sitename, ENT_QUOTES, 'UTF-8')),
            $html
        );
    }
    
    /**
     * 内联兜底错误页（429.html 缺失时使用）
     */
    private function fallbackErrorHtml(int $retryAfter, int $reason): string
    {
        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>429 - 访问过于频繁</title>'
            . '<meta http-equiv="refresh" content="' . $retryAfter . '"></head>'
            . '<body style="margin:0;font:16px/1.7 system-ui,-apple-system,\'Microsoft YaHei\',sans-serif;'
            . 'background:#0f1218;color:#e8ecf3"><div style="max-width:520px;margin:12vh auto;padding:28px 32px;'
            . 'text-align:center"><h1 style="font-size:20px;margin:0 0 12px">访问过于频繁</h1>'
            . '<p style="margin:0 0 8px;color:#8a93a6">请稍后再试，本页将自动刷新。</p>'
            . '<p style="margin:0;color:#6f7889;font-size:14px">剩余 <b style="color:#ffb454">'
            . $retryAfter . '</b> 秒</p>'
            . '<p style="margin:14px 0 0;color:#4d5567;font-size:12px">T' . $reason . '</p>'
            . '</div></body></html>';
    }
    
    /**
     * 是否数据请求（XHR / format=json|xml / Accept: application/json）
     * 播放页弹幕 ?danmu-list.html?...&format=xml 走此分支，避免前端把整页 HTML 当弹幕解析
     */
    private function isDataRequest(): bool
    {
        // api.php（cron/rsync/商店推送，token 鉴权）协议面一律 JSON，不吃 HTML 错误页
        if ('api.php' === $this->requestBasename()) return true;
        
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xrw === 'xmlhttprequest') return true;
        
        $format = strtolower((string)($_GET['format'] ?? $_REQUEST['format'] ?? ''));
        if ($format === 'json' || $format === 'xml') return true;
        
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return $accept !== '' && str_contains($accept, 'application/json');
    }
    
    /**
     * 记录违规
     */
    private function recordViolation(string $ip, string $sessionId, int $reason): void
    {
        if (!function_exists('cache_get') || !function_exists('cache_set')) return;
        
        $violationKey = 'violation:' . $ip;
        $count = (int)cache_get($violationKey);
        cache_set($violationKey, $count + 1, 86400); // 24小时有效
    }
}
