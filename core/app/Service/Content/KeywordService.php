<?php
namespace App\Service\Content;

class KeywordService
{
    /**
     * 热搜词缓存载荷装配（前台 SearchService::hotWordsPage 等消费，供 cache_remember 形态写入）。
     * TTL 取 conf['search_cache_time'] 小时（非法/缺省回落 6h）。
     * @param array $conf 站点配置（$_SERVER['conf'] 形态）
     * @return array{payload:{items:array,ttl:int}, ttl:int}
     */
    public static function hotPayload($conf)
    {
        $hours = isset($conf['search_cache_time']) ? intval($conf['search_cache_time']) : 6;
        $hours <= 0 and $hours = 6;
        $ttl = $hours * 3600;
        $payload = array(
            'items' => self::hotItems($conf, 50, $ttl),
            'ttl' => $ttl,
        );
        return array('payload' => $payload, 'ttl' => $ttl);
    }

    /**
     * 热门搜索词列表：缓存键 hot_search_items_v3_<limit>_<版本令牌>_<HTTP_HOST>（版本令牌使
     * invalidateCache 可整体失效）；未命中查 search_hot（count/update_time 倒序）再 formatHotRows。
     * 表缺失（db_find_table 探测不过）时缓存空数组——降级为空词，不报错。
     * ⚠ 空结果只记 30s（「开发约定·缓存」：空数组不得按正 TTL 缓存）：原实现按 search_cache_time
     *   把空数组缓存**默认 6 小时**，正是「热搜总榜命中后莫名空白」这类症状的成因
     *   （db_find_table 的 true 结果另有 3600s 缓存，表被删后仍走查询分支 ⇒ 空词钉死整窗口）。
     * @param array $conf 站点配置（search_cache_time 决定缺省 TTL；透传给 formatHotRows 拼 URL）
     * @param int $limit 取词个数（max(1,intval)，hotPayload 用 50、hot_search 壳传 20）
     * @param int|null $ttl 缓存秒数；NULL 时按 search_cache_time 小时折算
     * @return array<int, array{rank,n,keyword,count,url}> 热词条目列表
     */
    public static function hotItems($conf, int $limit = 50, ?int $ttl = NULL): array
    {
        $limit = max(1, intval($limit));
        $hours = isset($conf['search_cache_time']) ? intval($conf['search_cache_time']) : 6;
        $hours <= 0 and $hours = 6;
        NULL === $ttl and $ttl = $hours * 3600;

        $cache_key = 'hot_search_items_v3_' . $limit . '_' . self::cacheVersion() . '_' . (_SERVER('HTTP_HOST') ?? 'default');
        $items = cache_get($cache_key);
        if (is_array($items)) {
            return $items;
        }

        $items = array();
        $table = _SERVER('db')->tablepre . 'search_hot';
        if (db_find_table($table)) {
            $rows = \App\Repository\RepositoryFactory::hotKeyword()->find(array(), array('count' => -1, 'update_time' => -1), 1, $limit, 'id');
            $items = self::formatHotRows($rows, $conf);
        }

        cache_set($cache_key, $items, empty($items) ? 30 : $ttl);
        return $items;
    }

    /**
     * 整体失效热词缓存：重写版本令牌（time_随机数）进缓存，令所有 hotItems 旧键即刻脱钩。
     * 走 cache_set 共享层写入，跨 FPM worker 生效。无返回值。
     */
    public static function invalidateCache(): void
    {
        cache_set(self::cacheVersionKey(), time() . '_' . mt_rand(1000, 9999));
    }

    /**
     * 热搜行 → 展示条目：跳过空词、按读取顺序重编 rank/n、逐词生成搜索 URL。
     * 转义边界：keyword 在入库链（SearchService::resolveKeyword，VULN-001 修复）已完成单次转义，
     * 本函数**不再补转义**（防双重转义，见 CLAUDE.md「安全红线·转义分层」）——模板输出直接消费即可。
     * @param array|false|null $rows search_hot 行列表（RepositoryFactory::hotKeyword()->find 产物）
     * @param array $conf 站点配置（url_rewrite_on 决定 URL 形态）
     * @return array<int, array{rank,n,keyword,count,url}> 空/非法输入返回空数组
     */
    public static function formatHotRows($rows, $conf): array
    {
        if (empty($rows) || !is_array($rows)) return array();

        $items = array();
        $rank = 1;
        foreach ($rows as $row) {
            $kw = trim((string)array_value($row, 'keyword', ''));
            if ($kw === '') continue;
            $items[] = array(
                'rank' => $rank,
                'n' => $rank,
                'keyword' => $kw,
                'count' => intval(array_value($row, 'count', 0)),
                'url' => self::searchUrl($kw, $conf),
            );
            $rank++;
        }
        return $items;
    }

    /**
     * 记录一次搜索热词：mb_substr 截 64 字符后方言化 upsert（mysql=ON DUPLICATE KEY /
     * pgsql=ON CONFLICT(keyword)，PG 部署需唯一索引配套，注释在案），SQL 全绑定参数。
     * 写放大抑制（R7-P05②）：同 IP + 同词 60s 内只落一次，ThrottleService APCu 原子计数，
     * 被限流时静默 return——不报错、不重试。表缺失或词为空同样静默跳过。
     * @param mixed $keyword 搜索词（内部 trim 强转 string；唯一链路 route/search.php → recordKeyword，
     *        实参是 SearchService::resolveKeyword 的**已单层转义**文本——此处再转义会双重编码，禁止）
     */
    public static function record($keyword)
    {
        $db = _SERVER('db');
        $table = $db->tablepre . 'search_hot';
        $kw = trim((string)$keyword);
        if ($kw !== '' && db_find_table($table)) {
            $kw = mb_substr($kw, 0, 64);
            // R7-P05②：同一 IP 对同一关键词 60s 内只落库一次，抑制重复搜索对 search_hot 的写放大。
            // 更正（原注释误称 MyISAM 表级写锁）：本表出厂即 InnoDB（install.sql 的 search_hot
            // ENGINE=InnoDB；安装器亦以「全库 InnoDB」为基线），所以代价不是表锁，而是每次搜索
            // 都要在 keyword 唯一索引上做一次 upsert 写入（行锁 + 二级索引维护 + binlog），
            // 高并发重复搜索时写 QPS 与搜索词数成正比、与访客数无关。
            // 限流位用 ThrottleService 的 APCu 原子计数，自身无读改写竞态，不引入额外表写。
            $throttle_key = (string)(_SERVER('longip') ?: ip());
            if (!\App\Service\System\ThrottleService::check('search_hot', $throttle_key . '|' . md5($kw), 1, 60)) {
                return;
            }
            $now = time();
            // 任务 3：upsert 方言化——mysql=ON DUPLICATE KEY（原样）；pgsql=ON CONFLICT (keyword)
            // （PG 部署需配套唯一索引：CREATE UNIQUE INDEX ON {table}(keyword)，见基础设施审计报告 §5.2）
            if ($db->type === 'pgsql') {
                $db->prepare_execute(
                    "INSERT INTO {$table} (keyword,count,create_time,update_time) VALUES (?,1,?,?) ON CONFLICT (keyword) DO UPDATE SET count = {$table}.count + 1, update_time = ?",
                    array($kw, $now, $now, $now)
                );
            } else {
                $db->prepare_execute(
                    "INSERT INTO `{$table}` (`keyword`,`count`,`create_time`,`update_time`) VALUES (?,1,?,?) ON DUPLICATE KEY UPDATE `count`=`count`+1,`update_time`=?",
                    array($kw, $now, $now, $now)
                );
            }
        }
    }

    /**
     * 读取热词缓存版本令牌（键见 cacheVersionKey）；未设置过返回 '0'。
     * @return string 版本令牌
     */
    protected static function cacheVersion(): string
    {
        $version = cache_get(self::cacheVersionKey());
        return $version ? (string)$version : '0';
    }

    /**
     * 版本令牌的缓存键名：按 HTTP_HOST 分域（站群各域独立失效）。
     * @return string 键名 hot_search_items_v3_ver_<host>
     */
    protected static function cacheVersionKey(): string
    {
        return 'hot_search_items_v3_ver_' . (_SERVER('HTTP_HOST') ?? 'default');
    }

    /**
     * 按伪静态档位（url_rewrite_on 0~3）生成搜索页 URL，其余档位回退 url('search') 路由装配。
     * keyword 走 urlencode，天然防注入到 query 位；模板消费此 URL 时仍属裸 echo 上下文（S-1 口径）。
     * @param string $keyword 搜索词（内部 trim）
     * @param array $conf 站点配置
     * @return string URL；空词返回 ''
     */
    protected static function searchUrl(string $keyword, $conf): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') return '';

        if ($conf['url_rewrite_on'] == 0) {
            return '/?search.html&keyword=' . urlencode($keyword);
        } elseif ($conf['url_rewrite_on'] == 1) {
            return '/search-' . urlencode($keyword) . '.html';
        } elseif ($conf['url_rewrite_on'] == 2) {
            return '/search/' . urlencode($keyword) . '.html';
        } elseif ($conf['url_rewrite_on'] == 3) {
            return '/search/' . urlencode($keyword) . '/';
        }

        return url('search', array('keyword' => $keyword), false);
    }
}
