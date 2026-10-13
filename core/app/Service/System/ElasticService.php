<?php
namespace App\Service\System;

class ElasticService
{
    const BREAKER_COOLDOWN = 30;
    const BREAKER_THRESHOLD = 3;
    const CONNECT_TIMEOUT = 1;
    const REQUEST_TIMEOUT = 3;
    const PING_TIMEOUT = 1;

    /**
     * 熔断闸：距上次达阈打开的冷却期（BREAKER_COOLDOWN=30s）内是否放行 ES 请求。
     * 状态缺失/形态非法时默认放行——是"探测优先"语义，非 fail-closed（真正故障由后续请求经 breakerFail 计入）。
     * @return bool true=允许发起请求；false=冷却期内，调用方应直接走 LIKE/降级路径
     */
    public static function breakerAllow(): bool
    {
        $state = cache_get('es_breaker_state');
        if (!$state || !is_array($state)) return true;
        $until = intval($state['until'] ?? 0);
        if ($until > time()) return false;
        return true;
    }

    /**
     * 记录一次 ES 失败：累加 'es_breaker_state' 计数，达 BREAKER_THRESHOLD(3) 次即打开熔断
     * （until=now+30s）；状态 TTL 90s（COOLDOWN+60），无人再失败则自然复位。
     * @return void
     */
    public static function breakerFail(): void
    {
        $state = cache_get('es_breaker_state');
        if (!$state || !is_array($state)) $state = array('fails' => 0, 'until' => 0);
        $state['fails'] = intval($state['fails'] ?? 0) + 1;
        if ($state['fails'] >= self::BREAKER_THRESHOLD) {
            $state['until'] = time() + self::BREAKER_COOLDOWN;
        }
        cache_set('es_breaker_state', $state, self::BREAKER_COOLDOWN + 60);
    }

    /**
     * 熔断复位：请求成功后把 fails/until 归零，立即恢复放行。
     * @return void
     */
    public static function breakerReset(): void
    {
        cache_set('es_breaker_state', array('fails' => 0, 'until' => 0), self::BREAKER_COOLDOWN + 60);
    }

    /**
     * 统一出站闸（上线前审计修复 VULN-H03）
     *
     * 修复前状态：本服务三处出站（request/ping/bulkInsert）各自 curl_init，唯一守卫是
     * `^https?://` **scheme 正则**——不解析也不校验目标 IP，因此 `http://127.0.0.1:9200`、
     * `http://169.254.169.254/`（云元数据）、任意内网 host:port 全部放行；而 es.host 可经
     * KV/缓存写入，es_on=1 时由**前台匿名搜索/详情页**触发。这是全站唯一绕过
     * `outbound_url_safe` 的活跃出站面（采集/商店/云端均已过闸）。
     *
     * 现全部出站改经本方法：走统一网关（scheme + 全记录 DNS + 内网保留段拦截 +
     * `CURLOPT_RESOLVE` 固定已校验 IP 消除 DNS 重绑定窗口）；并对**配置的 ES 主机**做
     * 与 cloud_api 同款的**精确主机豁免**（见 `outbound_trusted_hosts()`）——ES 常部署在
     * 内网/本机（如 127.0.0.1:9200），豁免只按主机名精确匹配，其余内网段仍拦。
     *
     * @return resource|\CurlHandle|false false=被闸拒绝或 curl 不可用
     */
    private static function esCurlInit(string $url)
    {
        if (!function_exists('curl_init') || !function_exists('outbound_url_safe')) return false;
        $pinned = NULL;
        if (!outbound_url_safe($url, TRUE, $pinned)) {
            xn_log('ES request blocked by outbound gate: ' . $url, 'es_error');
            return false;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (!empty($pinned)) curl_setopt($ch, CURLOPT_RESOLVE, $pinned);
        curl_setopt($ch, CURLOPT_URL, $url);
        // 协议锁定（纵深，与闸同口径）
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        // 重定向逐跳复查由调用方经 outbound_curl_exec 处理；ES 不跟随重定向更安全
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // TLS 校验：修复前硬编码 VERIFYPEER=false（MITM 面）。改为跟随全局 ssl_verify，
        // 仅当管理员显式关闭时才不校验（自签内网 ES 的兼容出口）。
        // 注意：_SERVER() 可能返回 NULL，PHP8 下不可直接取下标（会告警），故先归一为数组。
        $isHttps = (0 === stripos($url, 'https://'));
        $gConf = function_exists('_SERVER') ? _SERVER('conf') : NULL;
        $verify = ($isHttps && is_array($gConf) && !empty($gConf['ssl_verify']));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
        return $ch;
    }

    /**
     * ES REST 通用请求：经 esCurlInit 统一出站闸（SSRF 内网拦截 + IP 固定 + TLS 口径），
     * ApiKey 优先、否则 Basic Auth；connect 1s / 总 3s 超时。失败/被闸拒绝时 breakerFail() 计入熔断。
     * @param string      $method HTTP 方法（调用点实参：'PUT'/'POST'/'GET'）
     * @param string      $url    完整请求 URL（含 es.host 前缀）
     * @param array|null  $data   请求体，json_encode 后发送；null=无体
     * @return array|null ES 返回 JSON 解码后的对象结构（assoc array）；被闸拒绝、curl 失败
     *                    或响应非 JSON 时为 NULL——调用方须把 NULL 当"不可用"降级处理
     */
    public static function request($method, $url, $data = null)
    {
        // ⚠ 熔断闸补全（复核修正）：request() 是 count/stats/clearIndex/createIndex 的公共出口，旧实现
        //   从不查 breakerAllow ⇒ 熔断窗口内后台 ES 面板与清索引仍每次真实出网（白等 3s×N）。
        if (!self::breakerAllow()) return null;
        $conf = _SERVER('conf');
        $apiKey = $conf['es']['api_key'];
        $esUser = $conf['es']['user'];
        $esPass = $conf['es']['pass'];

        // SSRF 加固 + 修复（VULN-H03）：出站统一过 esCurlInit
        // （scheme + DNS + 内网段 + IP 固定 + TLS 校验），非法目标直接失败走熔断
        $ch = self::esCurlInit((string)$url);
        if ($ch === false) {
            self::breakerFail();
            return null;
        }
        $headers = array('Content-Type: application/json');

        if (!empty($apiKey)) {
            $headers[] = 'Authorization: ApiKey ' . $apiKey;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);

        if (empty($apiKey)) {
            curl_setopt($ch, CURLOPT_USERPWD, "$esUser:$esPass");
        }

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $res = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $errno !== 0) {
            xn_log("ES request failed: [$errno] $error — $method $url", 'es_error');
            self::markAvailability(false);
            self::breakerFail();
            return null;
        }

        // ⚠ HTTP 状态码判定（审查修复，真机验证）：旧实现只要 curl 层成功（errno=0）就 breakerReset
        //   ——ES 返回 401/403/5xx 时熔断器被持续复位，认证错误/服务端故障**永不触发熔断**，
        //   每次搜索都真实出网占住 worker。现按状态码分类：
        //   2xx → 复位；401/403/≥500 → 计入熔断并返回 null（调用方降级 LIKE/面板异常）；
        //   其余 4xx（400/404：索引不存在、查询语法错、映射不合法等）→ 原样返回响应体、
        //   不复位不熔断（createIndex 靠 400 错误体报「已存在/分析器缺失」，search 靠 404 回退 LIKE）。
        if ($httpCode >= 500 || 401 === $httpCode || 403 === $httpCode) {
            xn_log("ES request HTTP $httpCode: $method $url — " . substr((string)$res, 0, 300), 'es_error');
            self::markAvailability(false);
            self::breakerFail();
            return null;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            self::markAvailability(true);
            self::breakerReset();
        }
        // 其余 4xx（400/404 等）= ES 进程可达（只是索引/语法问题）⇒ 可用性置真，别误伤人
        if ($httpCode >= 400 && $httpCode < 500) {
            self::markAvailability(true);
        }
        return json_decode($res, true);
    }

    /**
     * 探活：GET {es.host}/ 带凭据（PING_TIMEOUT=1s）。先过 breakerAllow，失败 breakerFail。
     * @return bool true=ES 根路径可达且响应成功（不看响应体内容）；被闸拒绝（如 curl 缺失）同样 FALSE
     */
    public static function ping(): bool
    {
        $conf = _SERVER('conf');
        $esHost = $conf['es']['host'];

        if (!self::breakerAllow()) return false;

        // 修复（VULN-H03）：ping 同样过统一出站闸（原仅 scheme 正则）
        $ch = self::esCurlInit((string)$esHost . '/');
        if ($ch === false) return false;
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::PING_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::PING_TIMEOUT);

        $apiKey = $conf['es']['api_key'];
        if (!empty($apiKey)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: ApiKey ' . $apiKey));
        } else {
            $esUser = $conf['es']['user'];
            $esPass = $conf['es']['pass'];
            curl_setopt($ch, CURLOPT_USERPWD, "$esUser:$esPass");
        }

        $res = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // ⚠ 状态码判定（审查修复）：旧实现 curl errno=0 即算可达——认证失败（401）也会
        //   breakerReset 并报 true。现 HTTP ≥400 一律按故障计（熔断 + false）。
        if ($res === false || $errno !== 0 || $httpCode >= 400) {
            self::breakerFail();
            return false;
        }

        self::breakerReset();
        return true;
    }

    /**
     * 带缓存的可用性检查：结果存 'es_available_check' 30s，避免每请求都 ping 出网。
     * @return bool 是否可用（等价 ping() 的 30s 缓存视图）
     */
    /**
     * 记录「最近一次出网结果」到可用性缓存（30s TTL）。
     * 复核修正（C7 补完）：可用性短路的**写方**此前只有 available()，而它全仓零调用 ⇒
     * insertOne/bulkInsert 里 `cache_get('es_available_check') === false` 永远是假，短路形同不存在。
     * 现由 request()/bulkInsert()/ping() 在真实出网后写入，短路才真正生效（近期失败 ⇒ 30s 内跳过写入）。
     * @param bool $ok 本次出网是否可用
     * @return void
     */
    private static function markAvailability(bool $ok): void
    {
        try {
            cache_set('es_available_check', $ok, 30);
        } catch (\Throwable $e) {
            // 缓存层异常不影响主流程
        }
    }

    public static function available(): bool
    {
        $cached = cache_get('es_available_check');
        if ($cached !== null) return (bool)$cached;

        $available = self::ping();
        cache_set('es_available_check', $available, 30);
        return $available;
    }

    /**
     * 建索引：PUT {es.host}/{es.index}，固定 1 分片 0 副本，name 用 ik 分词（ik_max_word 建库 / ik_smart 查询）。
     * 索引已存在时 ES 返回错误体（不抛异常，由调用方看返回）。
     * ⚠ ik 插件缺失自动降级（审查修复，真机验证）：ES 未安装 analysis-ik 时 ik 映射必失败
     *   （400：analyzer [ik_smart] has not been configured…），此前会**永远建不成索引**而搜索
     *   只能走 LIKE 回退。现检测到分析器类错误自动改 standard 映射重建——英文/数字搜索正常，
     *   中文按单字分词（宽泛但可用）；需要高质量中文分词请安装 ik 插件后删索引重建。
     * @return array|null request('PUT') 的解码响应；出站失败为 NULL（形参无声明，类型取自 request() 约定）
     */
    public static function createIndex()
    {
        $conf = _SERVER('conf');
        $esHost = $conf['es']['host'];
        $index = $conf['es']['index'];
        $url = "$esHost/$index";

        $body = array(
            'settings' => array(
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
            ),
            'mappings' => array(
                'properties' => array(
                    'vid' => array('type' => 'integer'),
                    'name' => array(
                        'type' => 'text',
                        'analyzer' => 'ik_max_word',
                        'search_analyzer' => 'ik_smart',
                    ),
                ),
            ),
        );

        $res = self::request('PUT', $url, $body);
        if (is_array($res) && isset($res['error'])
            && preg_match('/analyzer\s*\[?\s*(ik_smart|ik_max_word)/i', (string)($res['error']['reason'] ?? ''))) {
            // ik 不可用：降级 standard 分析器重建（索引在失败的 PUT 后不会残留半成品）
            xn_log('ES analyzer ik unavailable, falling back to standard mapping', 'es_error');
            $body['mappings']['properties']['name'] = array('type' => 'text');
            return self::request('PUT', $url, $body);
        }
        return $res;
    }

    /**
     * 单条写入搜索文档：PUT /{index}/_doc/{vid}（覆盖式）。熔断打开或
     * 'es_available_check' 缓存已判定 false 时短路返回 NULL，不出网。
     * @param int    $vid  视频 ID（签名已声明）
     * @param string $name 视频名（签名已声明）
     * @return array|null request 解码响应；短路/失败为 NULL
     */
    public static function insertOne(int $vid, string $name)
    {
        $conf = _SERVER('conf');
        if (!self::breakerAllow()) {
            return null;
        }

        $cached = cache_get('es_available_check');
        if ($cached === false) {
            return null;
        }

        $esHost = $conf['es']['host'];
        $index = $conf['es']['index'];
        $url = "$esHost/$index/_doc/$vid";
        $body = array('vid' => $vid, 'name' => $name);

        return self::request('PUT', $url, $body);
    }

    /**
     * 批量写入：拼 NDJSON 后 POST {es.host}/_bulk（元素 vid/name 逐条 intval/(string) 归一，
     * _id=vid 覆盖式）。总超时放宽到 30s（区别于 request 的 3s）。
     * ⚠ 前置闸已补：原实现不走 breakerAllow()（docblock 也这么写），而同方法自己的 @return 又声明
     *   「被闸拒绝…为 NULL」——文档与实现互相矛盾。缺闸的真实代价：ES 故障期间每次 bulk 都会真的
     *   发请求并占住一个 FPM worker 最长 30s（CURLOPT_TIMEOUT=30），把熔断保护变成故障放大器。
     *   现与 insertOne()/ping() 同口径：先过 breakerAllow()，冷却期内直接返回 null
     *   （被拒不计 breakerFail——被拒本身不是新故障）。
     * @param array $list 行列表 array(array('vid'=>..,'name'=>..), ...)（按循环内取值取证）
     * @return array|null ES 批量响应解码（含 errors/items）；被闸拒绝或 curl 失败为 NULL
     */
    public static function bulkInsert(array $list)
    {
        if (!self::breakerAllow()) return null;   // 与 insertOne() 同口径：冷却期内直接放弃，不占 worker
        // ⚠ 审查修复轮 C7：补上 insertOne() 同款的 30s 可用性缓存短路——旧实现漏了这一层，
        //   近期 ping 失败（es_available 缓存 false）时 bulkInsert 仍真实出网。
        if (cache_get('es_available_check') === false) {
            return null;
        }
        $conf = _SERVER('conf');
        $esHost = $conf['es']['host'];
        $index = $conf['es']['index'];
        $esUser = $conf['es']['user'];
        $esPass = $conf['es']['pass'];
        $apiKey = $conf['es']['api_key'];
        $body = '';

        foreach ($list as $item) {
            $vid = intval($item['vid'] ?? 0);
            $name = (string)($item['name'] ?? '');
            $body .= json_encode(array('index' => array('_index' => $index, '_id' => $vid))) . "\n";
            $body .= json_encode(array('vid' => $vid, 'name' => $name), JSON_UNESCAPED_UNICODE) . "\n";
        }

        // 修复（VULN-H03）：bulk 同样过统一出站闸（原仅 scheme 正则）
        $ch = self::esCurlInit((string)$esHost . '/_bulk');
        if ($ch === false) {
            self::breakerFail();
            return null;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = array('Content-Type: application/x-ndjson');
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: ApiKey ' . $apiKey;
        } else {
            curl_setopt($ch, CURLOPT_USERPWD, "$esUser:$esPass");
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $res = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $errno !== 0) {
            xn_log("ES bulk_insert failed: [$errno] $error", 'es_error');
            self::breakerFail();
            return null;
        }

        // 与 request() 同口径的状态码分类（审查修复）：2xx 复位；401/403/≥500 熔断 + null；
        // 其余 4xx 原样返回（bulk 响应体内的逐条 errors 由调用方自行判读）。
        if ($httpCode >= 500 || 401 === $httpCode || 403 === $httpCode) {
            xn_log("ES bulk_insert HTTP $httpCode: " . substr((string)$res, 0, 300), 'es_error');
            self::breakerFail();
            return null;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            self::breakerReset();
        }
        return json_decode($res, true);
    }

    /**
     * 关键词搜索：POST /{index}/_search，match name，size 上限 60。
     * 任何失败路径（熔断、请求失败、零命中）都收敛为字符串 '[]'——不抛错，调用方按空结果回退 LIKE。
     * @param string $keyword 关键词（签名已声明）
     * @return string JSON 数组字符串，元素为命中行 _source（即 vid/name），JSON_UNESCAPED_UNICODE
     */
    public static function search(string $keyword): string
    {
        $conf = _SERVER('conf');
        if (!self::breakerAllow()) {
            return '[]';
        }

        $esHost = $conf['es']['host'];
        $index = $conf['es']['index'];
        $url = "$esHost/$index/_search";
        $body = array(
            'size' => 60,
            'query' => array(
                'match' => array(
                    'name' => $keyword,
                ),
            ),
        );
        $res = self::request('POST', $url, $body);
        if ($res === null) {
            return '[]';
        }
        $result = array();
        if (!empty($res['hits']['hits'])) {
            foreach ($res['hits']['hits'] as $hit) {
                $result[] = $hit['_source'];
            }
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 清空索引数据：POST /{index}/_delete_by_query + match_all（保留索引与 mapping，只删文档）。
     * @return array|null request 解码响应；失败为 NULL
     */
    public static function clearIndex()
    {
        $conf = _SERVER('conf');
        $esHost = $conf['es']['host'];
        $index = $conf['es']['index'];
        $url = "$esHost/$index/_delete_by_query";
        return self::request('POST', $url, array(
            'query' => array('match_all' => (object)array()),
        ));
    }

    /**
     * 集群统计：GET {es.host}/_cluster/stats（后台健康面板用）。
     * @return array|null 解码响应（status/cluster_name/nodes.count/indices.count 等）；失败为 NULL
     */
    public static function stats()
    {
        $conf = _SERVER('conf');
        $esHost = $conf['es']['host'];
        return self::request('GET', "$esHost/_cluster/stats");
    }

    /**
     * 文档计数：POST /{index}/_count + match_all（后台健康面板用）。
     * @return array|null 解码响应（含 count 字段）；失败为 NULL
     */
    public static function count()
    {
        $conf = _SERVER('conf');
        $esHost = $conf['es']['host'];
        $index = $conf['es']['index'];
        $url = "$esHost/$index/_count";
        $body = array(
            'query' => array(
                'match_all' => (object)array(),
            ),
        );
        return self::request('POST', $url, $body);
    }

    /**
     * 健康面板聚合：调 stats()+count()，把 ES 响应摊平成后台展示字段。
     * status 为 green/yellow 判"正常"，其余（含请求失败）恒"异常"——展示层 fail-closed。
     * @param array $conf 站点配置数组（签名已声明；仅需 conf['es']['host']）
     * @return array 固定骨架：health/es_stats/es_count/doc_count/cluster_name/cluster_status/indices_count/node_count
     */
    public static function summary(array $conf): array
    {
        $summary = array(
            'health' => '异常',
            'es_stats' => null,
            'es_count' => null,
            'doc_count' => 0,
            'cluster_name' => '',
            'cluster_status' => '',
            'indices_count' => 0,
            'node_count' => 0,
        );

        if (!isset($conf['es']['host'])) {
            return $summary;
        }

        $summary['es_stats'] = self::stats();
        $summary['es_count'] = self::count();
        if (!empty($summary['es_stats']) && isset($summary['es_stats']['status'])) {
            $summary['cluster_status'] = $summary['es_stats']['status'];
            if ('green' == $summary['cluster_status'] || 'yellow' == $summary['cluster_status']) {
                $summary['health'] = '正常';
            }
        }
        if (isset($summary['es_stats']['cluster_name'])) {
            $summary['cluster_name'] = $summary['es_stats']['cluster_name'];
        }
        if (isset($summary['es_stats']['indices']['count'])) {
            $summary['indices_count'] = $summary['es_stats']['indices']['count'];
        }
        if (isset($summary['es_stats']['nodes']['count']['total'])) {
            $summary['node_count'] = $summary['es_stats']['nodes']['count']['total'];
        }
        if (!empty($summary['es_count']) && isset($summary['es_count']['count'])) {
            $summary['doc_count'] = intval($summary['es_count']['count']);
        }

        return $summary;
    }
}
