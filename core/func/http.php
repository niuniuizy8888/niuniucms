<?php
/**
 * HTTP/cURL 请求与出站安全闸（本文件实际函数：http_get / _curl_setup / https_post /
 * outbound_* 闸族 / http_url_resolve / https_request_multi / http_download_to_file）
 * 从 core/func/misc.php 拆分而来，由 core/core.php 在 misc.php 后加载。
 * 注：https_request() 仍在 core/func/misc.php（经 _curl_setup 过同一道闸），save_image()/http_post()/
 * https_get()/http_multi_get() 本仓不存在，勿再按旧清单去找。
 */
function http_get($url, $cookie = '', $timeout = 30, $times = 3)
{
    if (extension_loaded('curl')) return https_post($url, '', $cookie, $timeout, 'GET');
    // 仅允许 http/https 协议 + SSRF 内网拦截
    if (!outbound_url_safe($url)) return FALSE;
    $arr = array(
        'http' => array(
            'method' => 'GET',
            'timeout' => $timeout,
            // SSRF 加固：流上下文默认跟随 302 且无逐跳复查（可被重定向进内网），禁跟随
            'follow_location' => 0,
            'max_redirects' => 1,
        )
    );
    $stream = stream_context_create($arr);
    while ($times-- > 0) {
        $s = @file_get_contents($url, FALSE, $stream, 0, 4096000);
        if (FALSE !== $s) return $s;
    }
    return FALSE;
}
/**
 * HTTP POST请求（自动选择curl或file_get_contents）
 * @param string $url 请求地址
 * @param string|array $post POST数据
 * @param string $cookie Cookie字符串
 * @param int $timeout 超时时间（秒）
 * @param int $times 重试次数
 * @return string|false 响应内容
 */

/**
 * Shared cURL setup: initializes handle with common options.
 * Callers should set HEADER, USERAGENT, method-specific options, and execute.
 */
function _curl_setup($url) {
    // SSRF 防护：出站 URL 统一校验（scheme + DNS 解析 + 内网 IP 拦截），失败返回 FALSE
    $pinned = NULL;
    if (!outbound_url_safe($url, TRUE, $pinned)) return FALSE;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!empty($pinned)) curl_setopt($ch, CURLOPT_RESOLVE, $pinned);
    if (class_exists('\CURLFile')) {
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
    } else {
        defined('CURLOPT_SAFE_UPLOAD') and curl_setopt($ch, CURLOPT_SAFE_UPLOAD, false);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    // SSRF 防护：禁止重定向到内网地址
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    if (strpos(strtolower($url), 'https://') === 0) {
        global $conf;
        $ssl_verify = !empty($conf['ssl_verify']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    }
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
    return $ch;
}

/**
 * HTTPS GET请求（委托给 https_post）
 * @param string $url 请求地址
 * @param string $cookie Cookie字符串
 * @param int $timeout 超时时间（秒）
 * @param int $times 重试次数
 * @return string|false 响应内容
 */
function https_post($url, $post = '', $cookie = '', $timeout = 30, $method = 'POST')
{
    // SSRF 加固：原「gethostbyname + FILTER_FLAG」预检对字面 IP 短路（host===ip 时整体跳过），
    // 且不查 AAAA 记录。改用统一入口（全记录解析 + v4/v6 内网拦截 + 字面 IP 直查），无 curl 回退分支同样受控
    if (!outbound_url_safe($url)) {
        return xn_error(-1, 'SSRF blocked: outbound URL not allowed');
    }
    $base_url = $url; // 相对 Location 解析基准

    $allow_url_fopen = strtolower(ini_get('allow_url_fopen'));
    $allow_url_fopen = (empty($allow_url_fopen) || 'off' == $allow_url_fopen) ? 0 : 1;
    $allow_get_contents = $allow_url_fopen && strtolower(ini_get('user_agent'));
    $allow_curl = extension_loaded('curl');
    if (!$allow_curl && !$allow_get_contents) return xn_error(-1, 'CURL and OpenSSL are not installed on the server.');
    is_array($post) and $post = http_build_query($post);
    is_array($cookie) and $cookie = http_build_query($cookie);
    //$w = stream_get_wrappers(); //  && in_array('https', $w)
    if (!$allow_curl) {
        if ('https://' == substr($url, 0, 8) && !extension_loaded('openssl')) return xn_error(-1, 'CURL and OpenSSL are not installed on the server.');
        // follow_location=0：流上下文默认跟随 302 无逐跳复查，禁用以堵住重定向内网跳板
        $stream = stream_context_create(array('http' => array('header' => "Content-type: application/x-www-form-urlencoded\r\nx-requested-with: XMLHttpRequest\r\nCookie: $cookie\r\n", 'method' => $method, 'content' => $post, 'timeout' => $timeout, 'follow_location' => 0, 'max_redirects' => 1)));
        $s = file_get_contents($url, NULL, $stream, 0, 4096000);
        return $s;
    }
    $ch = _curl_setup($url);
    if (FALSE === $ch) return xn_error(-1, 'SSRF blocked: outbound URL not allowed');
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, _SERVER('HTTP_USER_AGENT'));
    if ('POST' == $method) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $header = array('Content-type: application/x-www-form-urlencoded', 'X-Requested-With: XMLHttpRequest');
    $cookie and $header[] = "Cookie: $cookie";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    // SSRF 防护：禁 curl 自动跟随，改手动逐跳校验（每跳 outbound_url_safe）
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $data = curl_exec($ch);
    if (curl_errno($ch)) {
        return xn_error(-1, 'Errno' . curl_error($ch));
    }
    if (!$data) {
        return '';
    }
    list($header, $data) = explode("\r\n\r\n", $data, 2);
    // 处理 HTTP 100 Continue 中间响应
    if (str_contains($header, '100 Continue')) {
        $parts = explode("\r\n\r\n", $data, 2);
        if (count($parts) >= 2) {
            $header = $parts[0];
            $data = $parts[1];
        }
    }
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (301 == $http_code || 302 == $http_code || 303 == $http_code || 307 == $http_code || 308 == $http_code) {
        // 手动跟随（最多 4 跳），每跳 SSRF 校验
        $matches = array();
        preg_match('/Location:(.*?)\n/i', $header, $matches);
        $url = trim(array_pop($matches));
        if (!preg_match('#^https?://#i', $url)) {
            $url = http_url_resolve($base_url, $url);
        }
        curl_setopt($ch, CURLOPT_HEADER, false);
        $final = outbound_curl_exec($ch, $url, $timeout, 4);
        if (FALSE === $final) {
            return xn_error(-1, 'SSRF blocked: redirect target not allowed');
        }
        $data = $final;
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
    
    // 统一状态码处理，拦截 4xx 和 5xx 错误
    if ($http_code >= 400) {
        return xn_error(-1, "HTTP Error: {$http_code}");
    }
    
    return $data;
}
// 来源说明（收尾轮结案，原为 NOTICE.md §2 登记依据，署名现归口 .skills/开发规范.md 末节）：
// 本文件历史上有一个**来源不明**的多线程抓取函数（旧名 http_multi_get），注释自称「收集于互联网
// 并经实测整理」并附「会导致 CPU 100%，勿在高频路径调用」告警。**该函数已不在本仓**（全仓零定义、
// 零调用）。现存的并发实现是本文件下方的 https_request_multi()：
//   · 只使用本项目自研的 _curl_setup / outbound_url_safe / http_url_resolve 三个内部件；
//   · 循环用 curl_multi_select(0.5) 阻塞等待（非忙等，与旧告警描述不符）；
//   · 带逐跳重定向 SSRF 复查、CURLOPT_RESOLVE 固定已校验 IP、单响应体上限。
// repo 内可查证据：2026-10-08 变更集已把 https_request_multi 称作「项目既有」做法
// （oldskills/security/CHANGESET-2026-10-08.md（与 web 根同级，已归档））。⚠ 本仓 .git 为 0 提交、无历史可比，
// 故此处只做「旧来源不明代码已离树 + 现实现为本项目代码」的事实陈述，不再保留待核告警。

/**
 * ==================== 出站请求 SSRF 防护 ====================
 * 统一入口：outbound_url_safe()（scheme + DNS 解析 + 内网 IP 拦截）
 * 全部出站点（实测 grep 枚举，共 11 处直接调用；新增出网必须并入本清单）：
 *   本文件 http_get / _curl_setup / https_post / https_request_multi（入口+每跳）/
 *         outbound_curl_exec / http_download_to_file；
 *   core/func/misc.php https_request（经 _curl_setup 过闸，本身不直接调）；
 *   Service 侧 ApiExecutionService、ThumbnailService、ElasticService；
 *   plugins/live/route/live.php（后台可改的播放器地址）。
 * 重定向逐跳复查（outbound_curl_exec）。
 * 说明：CloudOpsService/SetManageService/DomainReplaceService/api.php 只在注释里提及本闸、
 *   并无直接调用（它们复用上面的取数函数）——旧清单里的 http_post/save_image/http_multi_get/
 *   https_get 本仓无定义，已剔除，别按旧名去找出口。
 * 注意（用户裁定）：内网/保留段**拦截默认关闭**，由 conf['outbound_block_private'] 控制
 * （0/缺省=不拦，1=恢复拦截）；低层谓词 outbound_ipv4_safe/outbound_ipv6_safe 语义不变，仍可用于
 * 需要单点判定"这是不是内网地址"的场景。
 */

/**
 * IPv4 出站安全：显式网段表拒绝内网/保留/链路本地/CGNAT/文档/组播/广播
 * （不依赖 filter_var NO_PRIV/NO_RES 的版本实现差异）
 */
function outbound_ipv4_safe($ip)
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return FALSE;
    $long = sprintf('%u', ip2long($ip));
    if ($long <= 0x00FFFFFF) return FALSE;                          // 0.0.0.0/8
    if ($long >= 0x0A000000 && $long <= 0x0AFFFFFF) return FALSE;   // 10.0.0.0/8
    if ($long >= 0x7F000000 && $long <= 0x7FFFFFFF) return FALSE;   // 127.0.0.0/8
    if ($long >= 0xA9FE0000 && $long <= 0xA9FEFFFF) return FALSE;   // 169.254.0.0/16 链路本地
    if ($long >= 0xAC100000 && $long <= 0xAC1FFFFF) return FALSE;   // 172.16.0.0/12
    if ($long >= 0xC0A80000 && $long <= 0xC0A8FFFF) return FALSE;   // 192.168.0.0/16
    if ($long >= 0x64400000 && $long <= 0x647FFFFF) return FALSE;   // 100.64.0.0/10 CGNAT
    if ($long >= 0xC0000200 && $long <= 0xC00002FF) return FALSE;   // 192.0.2.0/24 文档
    if ($long >= 0xC6120000 && $long <= 0xC613FFFF) return FALSE;   // 198.18.0.0/15 benchmark
    if ($long >= 0xC6336400 && $long <= 0xC63364FF) return FALSE;   // 198.51.100.0/24 文档
    if ($long >= 0xCB007100 && $long <= 0xCB0071FF) return FALSE;   // 203.0.113.0/24 文档
    if ($long >= 0xE0000000 && $long <= 0xFFFFFFFF) return FALSE;   // 224.0.0.0/4 组播 + 240.0.0.0/4 保留/广播
    return TRUE;
}

/**
 * IPv6 出站安全：拒绝回环/ULA/链路本地/组播/文档段/IPv4 内嵌
 */
function outbound_ipv6_safe($ip)
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return FALSE;
    $ip = strtolower(trim($ip, '[]'));
    if ($ip === '::' || $ip === '::1') return FALSE;
    // IPv4 内嵌（::ffff:x.x.x.x）转 IPv4 校验
    if (preg_match('/::ffff:(\d+\.\d+\.\d+\.\d+)$/', $ip, $m)) {
        return outbound_ipv4_safe($m[1]);
    }
    $bin = @inet_pton($ip);
    if (FALSE === $bin || strlen($bin) !== 16) return FALSE;
    // 修复（SSRF 绕过）：此前仅拦点分 v4-mapped 文本形态，hex 形态（::ffff:7f00:1）与
    // IPv4-兼容/::/96、NAT64 64:ff9b::/96、6to4 2002::/16、Teredo 2001::/32 全部穿透。统一在字节层解码内嵌 v4。
    $embedded = outbound_v6_embedded4($bin);
    if (NULL !== $embedded) {
        return outbound_ipv4_safe($embedded);
    }
    $b0 = ord($bin[0]);
    if (($b0 & 0xFE) === 0xFC) return FALSE;                                  // fc00::/7 ULA
    if ($b0 === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) return FALSE;         // fe80::/10 链路本地
    if ($b0 === 0xFF) return FALSE;                                           // ff00::/8 组播
    if ($b0 === 0x20 && ord($bin[1]) === 0x01 && ord($bin[2]) === 0x0D && ord($bin[3]) === 0xB8) return FALSE; // 2001:db8::/32 文档
    return TRUE;
}

/**
 * 从 16 字节 IPv6 地址解码内嵌 IPv4（无内嵌形态返回 NULL）
 * 覆盖：::ffff:a.b.c.d/96 mapped、::a.b.c.d/96 compat、64:ff9b::/96 NAT64、2002::/16 6to4、2001::/32 Teredo
 */
function outbound_v6_embedded4($bin)
{
    $o = array();
    for ($i = 0; $i < 16; $i++) {
        $o[$i] = ord($bin[$i]);
    }
    $zeroRun = function ($from, $to) use ($o) {
        for ($i = $from; $i <= $to; $i++) {
            if ($o[$i] !== 0) return FALSE;
        }
        return TRUE;
    };
    // IPv4-mapped（::ffff:x）与 IPv4-compatible（::x，已废弃但解析器仍接受）
    if ($o[0] === 0x00 && $zeroRun(1, 9) && (($o[10] === 0xFF && $o[11] === 0xFF) || ($o[10] === 0x00 && $o[11] === 0x00))) {
        return sprintf('%d.%d.%d.%d', $o[12], $o[13], $o[14], $o[15]);
    }
    // NAT64 well-known 前缀 64:ff9b::/96
    if ($o[0] === 0x00 && $o[1] === 0x64 && $o[2] === 0xFF && $o[3] === 0x9B && $zeroRun(4, 11)) {
        return sprintf('%d.%d.%d.%d', $o[12], $o[13], $o[14], $o[15]);
    }
    // 6to4 2002::/16：字节 2-5 即内嵌 IPv4
    if ($o[0] === 0x20 && $o[1] === 0x02) {
        return sprintf('%d.%d.%d.%d', $o[2], $o[3], $o[4], $o[5]);
    }
    // Teredo 2001::/32：末尾 4 字节按位取反为客户端 IPv4
    if ($o[0] === 0x20 && $o[1] === 0x01 && $o[2] === 0x00 && $o[3] === 0x00) {
        return sprintf('%d.%d.%d.%d', 255 - $o[12], 255 - $o[13], 255 - $o[14], 255 - $o[15]);
    }
    return NULL;
}

/**
 * 主机解析为全部 A/AAAA 记录（防 gethostbyname 单记录漏检）
 * @return array|false IP 列表；解析失败返回 false（fail-closed）
 */
function outbound_host_ips($host)
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) return array($host);
    $ips = array();
    if (function_exists('dns_get_record')) {
        foreach (array('A', 'AAAA') as $type) {
            $recs = @dns_get_record($host, constant('DNS_' . $type));
            if (!is_array($recs)) continue;
            foreach ($recs as $r) {
                if ('A' === $type && !empty($r['ip'])) $ips[] = $r['ip'];
                if ('AAAA' === $type && !empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }
    }
    if (empty($ips)) {
        $ip = @gethostbyname($host);
        if (is_string($ip) && $ip !== $host) $ips[] = $ip;
    }
    return empty($ips) ? FALSE : $ips;
}

/**
 * 出站内网/保留段拦截开关（按用户裁定：**默认不拦**）
 *
 * 取值来源（任一命中即生效）：conf['outbound_block_private']（config.php / config.default.php）。
 *   · 未设或 0（默认）⇒ 内网/回环/链路本地/保留段**一律放行**——本系统常见部署就是局域网
 *     （商店 api.example.com→192.0.2.x、内网 ES、内网采集源、内网 API），一律拦会打断正常业务，
 *     尤其商店替换推送与采集回调这类"重要的 curl"。
 *   · 设为 1 ⇒ 恢复 安全审查口径的内网拦截（见 outbound_ipv4_safe/outbound_ipv6_safe
 *     与 outbound_trusted_hosts 豁免）。
 * **不随开关变化的硬约束**：scheme 只允许 http/https、必须含 host、DNS 解析失败 fail-closed、
 * 重定向逐跳复查、CURLOPT_RESOLVE 固定已校验 IP；所有出站仍必须经 outbound_url_safe() 统一入口
 * （红线不变：不得新增绕过本入口的出站点）。
 */
function outbound_block_private()
{
    $conf = function_exists('_SERVER') ? _SERVER('conf') : NULL;
    if (!is_array($conf) || !array_key_exists('outbound_block_private', $conf)) {
        // 兼容：容器未注入 conf 时（CLI 探针/单元用例）回落到全局 $conf
        $conf = (isset($GLOBALS['conf']) && is_array($GLOBALS['conf'])) ? $GLOBALS['conf'] : NULL;
    }
    return (is_array($conf) && !empty($conf['outbound_block_private']));
}

/**
 * 出站可信主机（商店服务端对接）：管理员在 conf['cloud_api'] 显式配置的商店主机。
 * 信任模型与 rsyncBase 域名白名单（M-A3）同款——商店允许部署在内网/局域网（如 http://192.0.2.3:2651/），
 * 该名单内主机豁免内网保留段拦截；scheme 闸、DNS 解析 fail-closed、CURLOPT_RESOLVE IP 固定、
 * 重定向逐跳复查全部保持（商店 302 到非可信内网地址仍被拒），采集源等其它用户可配 URL 不在豁免范围。
 * 说明：`outbound_block_private` 默认关闭时本豁免无实际作用（本来就不拦），仅当运维把开关改回 1
 * （恢复拦截）时，本名单才决定"哪个内网主机仍然可达"。
 * @return string[] 小写主机名列表（不含端口/方括号）
 */
function outbound_trusted_hosts()
{
    static $hosts = NULL;
    if (NULL !== $hosts) return $hosts;
    $hosts = array();
    $conf = function_exists('_SERVER') ? _SERVER('conf') : NULL;
    if (is_array($conf) && isset($conf['cloud_api'])) {
        $u = trim((string)$conf['cloud_api']);
        if ('' !== $u) {
            $h = strtolower((string)parse_url($u, PHP_URL_HOST));
            if ('' !== $h) $hosts[] = trim($h, '[]');
        }
    }
    // 上线前审计（VULN-H03）**刻意不**把 es.host 加入本豁免名单。
    // 理由：cloud_api 是本系统自有的固定部署目标（内网商店为既定架构），而 es.host 是
    // 后台可写的自由字段；若把它列为可信主机，等于让该字段自己声明「我可访问内网」，
    // 反而抹平出站闸的意义。ES 出站现一律过 outbound_url_safe（见 ElasticService::esCurlInit）。
    // 用户裁定：内网拦截默认关闭（outbound_block_private=0）⇒ 内网 ES 无需登记即可用；
    // 若把开关改回 1（恢复拦截），内网/回环 ES 会被 fail-closed 拦下，届时需在此显式登记主机名。
    return $hosts;
}

/**
 * 出站 URL 安全校验（SSRF 防护统一入口）
 * 内网/保留段拦截由 outbound_block_private() 决定是否生效（**默认不拦**，用户裁定）；
 * 无论开关如何，scheme/host 形状闸与 DNS 解析 fail-closed 都恒生效，并回填 $resolved 供 IP 固定。
 * @param string $url 目标 URL
 * @param bool $resolve_dns 是否解析 DNS 校验 IP（默认 true）
 * @return bool true=安全放行；false=拒绝（scheme 非法/host 缺失/解析失败/开关开启时命中内网保留地址且非可信主机）
 */
function outbound_url_safe($url, $resolve_dns = TRUE, &$resolved = NULL)
{
    if (!is_string($url) || $url === '') return FALSE;
    $parsed = @parse_url($url);
    if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) return FALSE;
    if (!in_array(strtolower($parsed['scheme']), array('http', 'https'), TRUE)) return FALSE;
    if (!$resolve_dns) return TRUE;
    $ips = outbound_host_ips($parsed['host']);
    if (FALSE === $ips) return FALSE; // 解析失败 fail-closed
    // 收集 host:port:ip 映射供 curl CURLOPT_RESOLVE 固定已校验 IP，消除校验与请求之间的 DNS 重绑定窗口（审计修复）
    $port = isset($parsed['port']) ? intval($parsed['port']) : ('https' === strtolower($parsed['scheme']) ? 443 : 80);
    $hostname = trim($parsed['host'], '[]');
    // 内网段拦截开关：关闭时不校验网段（$trusted 恒真⇒整段跳过），
    // 开启时才走原口径——cloud_api 可信主机豁免 + 其余内网/保留段 fail-closed（见 outbound_trusted_hosts）
    $block_private = outbound_block_private();
    $trusted = $block_private ? in_array(strtolower($hostname), outbound_trusted_hosts(), TRUE) : TRUE;
    $resolved = array();
    $v4 = array();
    $v6 = array();
    foreach ($ips as $ip) {
        if (!$trusted) {
            if (!str_contains($ip, ':')) {
                if (!outbound_ipv4_safe($ip)) return FALSE;
            } else {
                if (!outbound_ipv6_safe($ip)) return FALSE;
            }
        }
        if (!str_contains($ip, ':')) {
            $v4[] = $ip;
        } else {
            $v6[] = $ip;
        }
    }
    // 修复（双栈出站全灭）：原实现把**全部**解析 IP（含 AAAA）都塞进 CURLOPT_RESOLVE。
    // 本机（与多数内网服务器）无 IPv6 出口时 curl 直接失败且**不回落 IPv4**——实测
    // `https_request('https://www.baidu.com/')`=FALSE（pin=3）、第三方采集源=FALSE（pin=4），
    // 而 IPv4-only 的商店正常（pin=1）。症状是「所有双栈第三方源都采不到、商店却通」。
    // 口径：**优先只 pin IPv4**（有 A 记录时），仅当域名只有 AAAA 时才 pin IPv6。
    // 安全属性不变：上面已把**全部**解析 IP（v4+v6）逐个过了内网/保留段校验，pin 只是把
    // 校验过的地址固定给 curl，不再给它二次解析的机会——pin 子集不会放宽任何一条拦截。
    $pick = !empty($v4) ? $v4 : $v6;
    foreach ($pick as $ip) {
        // CURLOPT_RESOLVE 的 IPv6 地址必须写成 [addr]（裸 AAAA 语法非法——v6-only 域名会解析失败）
        $resolved[] = $hostname . ':' . $port . ':' . (!str_contains($ip, ':') ? $ip : '[' . trim($ip, '[]') . ']');
    }
    return TRUE;
}

/**
 * 手动跟随重定向执行（每跳复查目标 URL 的 SSRF 安全性）
 * @param resource $ch 已初始化的 curl handle（FOLLOWLOCATION 应为 false）
 * @param string $url 起始 URL（按引用更新为最终 URL）
 * @param int $timeout 每跳超时（秒）
 * @param int $max_redirects 最大跟随跳数（默认 5）
 * @param bool $msMode TRUE=调用方已用 CURLOPT_TIMEOUT_MS 设定**毫秒**级超时（如安装上报 500ms），
 *                     此时本函数不再写 CURLOPT_TIMEOUT（复核修正：旧实现无条件写秒级超时，会把
 *                     调用方的毫秒设置覆盖掉——`core/install/index.php` 传 `$ms=1` 意图 500ms，
 *                     实际最坏每跳 500s，共 5 跳可挂住 FPM worker 40 分钟）。
 * @return string|false 响应体；校验失败返回 false
 */
function outbound_curl_exec(&$ch, &$url, $timeout, $max_redirects = 5, $msMode = FALSE)
{
    $output = '';
    for ($i = 0; $i <= $max_redirects; $i++) {
        $pinned = NULL;
        if (!outbound_url_safe($url, TRUE, $pinned)) return FALSE; // 每跳入口校验（含初始）+ IP 固定
        if (!empty($pinned)) curl_setopt($ch, CURLOPT_RESOLVE, $pinned);
        curl_setopt($ch, CURLOPT_URL, $url);
        if (empty($msMode)) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }
        $output = curl_exec($ch);
        if (FALSE === $output) return FALSE;
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $next = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if (!in_array($code, array(301, 302, 303, 307, 308), TRUE) || empty($next)) break;
        // 相对 Location 解析（基于当前 URL）
        if (!preg_match('#^https?://#i', $next)) {
            $next = http_url_resolve($url, $next);
        }
        $url = $next;
    }
    return $output;
}

/**
 * 相对 URL 解析（RFC 3986 简化版：路径/查询拼接）
 */
function http_url_resolve($base, $relative)
{
    $bp = @parse_url($base);
    if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return $relative;
    if ($relative === '' || $relative[0] === '#') return $base;
    if (preg_match('#^https?://#i', $relative)) return $relative;
    $scheme = $bp['scheme'] . '://' . $bp['host'];
    if (!empty($bp['port'])) $scheme .= ':' . $bp['port'];
    if ($relative[0] === '/') return $scheme . $relative;
    $path = isset($bp['path']) ? $bp['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    if ($dir === '') $dir = '/';
    return $scheme . $dir . $relative;
}

/**
 * 并发 HTTP(S) 请求（采集性能专项）：curl_multi 并行下载多个 URL。
 * SSRF 语义与 https_request 完全一致——初始与**每个重定向跳**均经 outbound_url_safe 校验，
 * 并以 CURLOPT_RESOLVE 固定已校验 IP（消除 DNS 重绑定窗口）；禁自动跟随重定向。
 * 用途：采集导入时并发预取海报，替代逐张串行（每批数十张，网络 RTT 线性叠加 → 并发收敛）。
 *
 * @param string[] $urls
 * @param int $timeout       单请求超时（秒）
 * @param int $max_redirects 最大重定向跳数
 * @param int $max_bytes     单响应体上限（超出即中止并记为失败，防内存膨胀）
 * @return array<string,string|false> 原始 URL => 响应体；被拒/失败为 false
 */
function https_request_multi(array $urls, $timeout = 10, $max_redirects = 5, $max_bytes = 3145728)
{
    $result = array();
    $urls = array_values(array_unique(array_filter(array_map('strval', $urls), function ($u) {
        return '' !== $u && preg_match('#^https?://#i', $u);
    })));
    if (empty($urls)) return $result;
    foreach ($urls as $u) $result[$u] = false;
    if (!extension_loaded('curl')) return $result;
    $mh = curl_multi_init();
    if (!$mh) return $result;

    $timeout   = max(1, intval($timeout));
    $max_bytes = max(1024, intval($max_bytes));
    $keyOf = function ($ch) { return is_object($ch) ? spl_object_id($ch) : (int)$ch; };
    // key => ['orig','url','redirects','body','too_big']
    $meta = array();

    $spawn = function ($orig, $url, $redirects) use ($mh, &$meta, $timeout, $max_bytes, $keyOf) {
        $pinned = NULL;
        if (!outbound_url_safe($url, TRUE, $pinned)) return;
        $ch = _curl_setup($url);
        if (FALSE === $ch) return;
        if (!empty($pinned)) curl_setopt($ch, CURLOPT_RESOLVE, $pinned);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
        curl_setopt($ch, CURLOPT_USERAGENT, _SERVER('HTTP_USER_AGENT'));
        $k = $keyOf($ch);
        $meta[$k] = array('orig' => $orig, 'url' => $url, 'redirects' => (int)$redirects, 'body' => '', 'too_big' => false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch2, $chunk) use (&$meta, $keyOf, $max_bytes) {
            $k2 = $keyOf($ch2);
            if (!isset($meta[$k2])) return 0;
            if (strlen($meta[$k2]['body']) + strlen($chunk) > $max_bytes) {
                $meta[$k2]['too_big'] = true;
                return -1; // 中止该请求
            }
            $meta[$k2]['body'] .= $chunk;
            return strlen($chunk);
        });
        curl_multi_add_handle($mh, $ch);
    };
    foreach ($urls as $u) $spawn($u, $u, 0);

    $deadline = microtime(true) + $timeout + 10;
    do {
        curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 0.5);
        while (FALSE !== ($info = curl_multi_info_read($mh))) {
            $ch = $info['handle'];
            $k  = $keyOf($ch);
            if (!isset($meta[$k])) { curl_multi_remove_handle($mh, $ch); continue; }
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $next = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $isRedirect = in_array($code, array(301, 302, 303, 307, 308), true) && !empty($next);
            if ($isRedirect && $meta[$k]['redirects'] < $max_redirects) {
                if (!preg_match('#^https?://#i', $next)) $next = http_url_resolve($meta[$k]['url'], $next);
                $pinned = NULL;
                if (!outbound_url_safe($next, TRUE, $pinned)) {
                    $result[$meta[$k]['orig']] = false;
                    curl_multi_remove_handle($mh, $ch);
                    unset($meta[$k]);
                    continue;
                }
                // 复用同一 handle 跟随下一跳：重置 body、重新固定 IP
                curl_multi_remove_handle($mh, $ch);
                $meta[$k]['url']       = $next;
                $meta[$k]['redirects']++;
                $meta[$k]['body']      = '';
                $meta[$k]['too_big']   = false;
                if (!empty($pinned)) curl_setopt($ch, CURLOPT_RESOLVE, $pinned);
                curl_setopt($ch, CURLOPT_URL, $next);
                curl_multi_add_handle($mh, $ch);
                continue;
            }
            // 仅 CURLE_OK 且未超限、HTTP<400 视为成功；超时/传输错误/超限/4xx+ 一律 false（与单发语义区分明确）
            if (0 === $info['result'] && !$meta[$k]['too_big'] && $code > 0 && $code < 400) {
                $result[$meta[$k]['orig']] = $meta[$k]['body'];
            } else {
                $result[$meta[$k]['orig']] = false;
            }
            curl_multi_remove_handle($mh, $ch);
            unset($meta[$k]);
        }
        if (microtime(true) > $deadline) break;
    } while ($active || !empty($meta));

    curl_multi_close($mh);
    return $result;
}

/**
 * 流式下载到文件（远程 ZIP 链路的统一下载器）。
 *
 * 背景（代码审查 #1）：模板包 / 插件包 / 整包升级三条链路原先都是
 * `https_request()` 把整个响应体读进内存，**之后**才 `strlen()` 判 200MB 上限。
 * 那个上限在内存维度上毫无意义——被判超限时 200MB 已经驻留 PHP 内存，
 * 并发几个请求即打满 memory_limit；且 `fwrite(fopen(...))` 无错误检查，
 * 写盘失败会静默留下空文件，把下游的「不是 zip」伪装成下载成功。
 *
 * 本下载器把上限前置到**传输过程**：写回调按块累加，一旦超过 $max_bytes 立即
 * 返回 -1 中止该次 curl 传输（对齐 https_request_multi 的既有做法），
 * 因此内存峰值只有单块大小（约 16KB × 并发数），与包体大小无关。
 *
 * 安全语义与 https_request 完全一致：
 *   - 初始与**每个重定向跳**均经 outbound_url_safe()（scheme + DNS + 内网段）
 *   - CURLOPT_RESOLVE 固定已校验 IP，消除「校验与实际请求之间」的 DNS 重绑定窗口
 *   - scheme 限 http/https；禁自动跟随重定向，改手动逐跳复核
 *   - ssl_verify 生效（与 _curl_setup 同口径）
 *
 * 写盘采用「写临时文件 → 成功后 rename」：失败路径不会留下半截文件被误当成包；
 * 临时文件与目标同目录，保证 rename 原子（同一文件系统）。
 *
 * @param string $url           下载地址（内部仍会过 SSRF 闸）
 * @param string $destPath      落盘目标路径（需已确保父目录存在）
 * @param int    $maxBytes      包体上限（字节）；超出即中止并为该次传输记为 too_big
 * @param int    $timeout       单请求超时（秒）
 * @param int    $maxRedirects  最大重定向跳数
 * @param string $expectPrefix  期望的响应体起始字节（如 'PK'）；不匹配即失败。'' = 不检查
 * @return array{ok:bool,bytes:int,sha256:string,error:string,http_code:int}
 *         error 取值：ssrf / open_failed / http_error / empty / too_big / prefix_mismatch
 *                     / transfer_failed / write_failed / rename_failed / ok
 */
function http_download_to_file($url, $destPath, $maxBytes = 209715200, $timeout = 60, $maxRedirects = 5, $expectPrefix = '')
{
    $fail = function ($error, $bytes = 0, $code = 0) {
        return array('ok' => FALSE, 'bytes' => (int)$bytes, 'sha256' => '', 'error' => $error, 'http_code' => (int)$code);
    };

    $url = trim((string)$url);
    if ('' === $url || !preg_match('~^https?://~i', $url)) return $fail('ssrf');
    if (!extension_loaded('curl')) return $fail('ssrf');

    $maxBytes = max(1024, (int)$maxBytes);
    $timeout  = max(1, (int)$timeout);
    $expectPrefix = (string)$expectPrefix;

    $tmpPath = $destPath . '.' . bin2hex(random_bytes(6)) . '.part';
    $fp = @fopen($tmpPath, 'wb');
    if (!$fp) return $fail('open_failed');

    $bytes     = 0;
    $tooBig    = FALSE;
    $writeFail = FALSE;
    $hashCtx   = hash_init('sha256');
    $prefix    = '';
    $code      = 0;
    $current   = $url;
    $success   = FALSE;

    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        $pinned = NULL;
        if (!outbound_url_safe($current, TRUE, $pinned)) break;

        $ch = curl_init();
        if (FALSE === $ch) break;
        $opts = array(
            CURLOPT_URL => $current,
            CURLOPT_RETURNTRANSFER => FALSE,
            CURLOPT_HEADER => FALSE,
            CURLOPT_FOLLOWLOCATION => FALSE,   // 逐跳手动校验
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_NOSIGNAL => TRUE,
            CURLOPT_USERAGENT => 'NiuNiuCms/1.0 (+package-download)',
            CURLOPT_WRITEFUNCTION => function ($ch2, $chunk) use (&$bytes, &$tooBig, &$writeFail, &$hashCtx, &$prefix, $fp, $maxBytes) {
                $len = strlen($chunk);
                if ($bytes + $len > $maxBytes) { $tooBig = TRUE; return -1; } // 中止传输
                hash_update($hashCtx, $chunk);
                if (strlen($prefix) < 8) $prefix .= substr($chunk, 0, 8 - strlen($prefix));
                $w = @fwrite($fp, $chunk);
                if ($w === FALSE || $w < $len) { $writeFail = TRUE; return -1; }
                $bytes += $len;
                return $len;
            },
        );
        if (!empty($pinned)) $opts[CURLOPT_RESOLVE] = $pinned;
        if (0 === strpos(strtolower($current), 'https://')) {
            $conf = function_exists('_SERVER') ? _SERVER('conf') : (isset($_SERVER['conf']) ? $_SERVER['conf'] : NULL);
            $verify = is_array($conf) ? !empty($conf['ssl_verify']) : TRUE;
            $opts[CURLOPT_SSL_VERIFYPEER] = $verify;
            $opts[CURLOPT_SSL_VERIFYHOST] = $verify ? 2 : 0;
            $opts[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }
        curl_setopt_array($ch, $opts);

        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $next = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $errno = curl_errno($ch);
        // 不调用 curl_close()：PHP 8.0 起它已无副作用，8.5 起发 Deprecated（每次下载刷一条告警）。
        // handle 由 GC 回收，与 outbound_curl_exec 的做法一致。

        // 主动中止（超限 / 写失败）：不是网络故障，按对应原因返回
        if ($tooBig || $writeFail) break;

        // HTTP 层错误：明确判失败。注意 curl 对 404/500 会把错误页当 body 写入，
        // 若不查状态码就会把错误页当成「下载成功」交给下游（原 https_request 即有此问题）。
        if ($code >= 400 || 0 === $code) {
            if ($ok === FALSE || $errno) break;
            $success = FALSE;
            break;
        }
        if (in_array($code, array(301, 302, 303, 307, 308), TRUE) && !empty($next)) {
            if (!preg_match('~^https?://~i', $next)) $next = http_url_resolve($current, $next);
            $current = $next;
            continue; // 下一跳重新校验（含 DNS 与内网段）
        }
        if ($ok === FALSE || $errno) break;

        $success = TRUE;
        break;
    }

    if (!@fclose($fp)) {
        @unlink($tmpPath);
        return $fail('write_failed', $bytes, $code);
    }
    if ($tooBig) {
        @unlink($tmpPath);
        return $fail('too_big', $bytes, $code);
    }
    if ($writeFail) {
        @unlink($tmpPath);
        return $fail('write_failed', $bytes, $code);
    }
    if (!$success) {
        @unlink($tmpPath);
        return $fail($code >= 400 ? 'http_error' : 'transfer_failed', $bytes, $code);
    }
    if (0 === $bytes) {
        @unlink($tmpPath);
        return $fail('empty', 0, $code);
    }
    if ('' !== $expectPrefix && 0 !== strpos($prefix, $expectPrefix)) {
        @unlink($tmpPath);
        return $fail('prefix_mismatch', $bytes, $code);
    }
    if (!@rename($tmpPath, $destPath)) {
        @unlink($tmpPath);
        return $fail('rename_failed', $bytes, $code);
    }

    return array(
        'ok' => TRUE,
        'bytes' => (int)$bytes,
        'sha256' => hash_final($hashCtx),
        'error' => 'ok',
        'http_code' => (int)$code,
    );
}
