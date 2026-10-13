<?php
namespace App\Service\Content;

class QueryService
{
    /**
     * 首页视频列表：$cids 为空直接返回空数组，否则转 VideoService::listByCid()（desc=TRUE 新在前、light=TRUE 轻量装配）。
     * 底层空结果会回传空字符串（unifiedPull 短路 → array_value 缺省 ''），此处用 `?: array()` 归一。
     * ⚠ 装配链内已按 $gid 做栏目访问过滤（VideoService::unifiedPull → listAccessFilter），行内字段由 format() 装配且不做输出转义。
     * @param array $cids    分类 ID 列表（支持多分类并集；空数组=不查库）
     * @param int   $page    页码（从 1 起）
     * @param int   $pagesize 每页条数
     * @return array vid 为键的视频行列表；无结果为空数组
     */
    public static function homeVideoList(array $cids, int $page, int $pagesize): array
    {
        if (empty($cids)) return array();
        $items = VideoService::listByCid($cids, $page, $pagesize, true, true);
        return $items ?: array();
    }

    /**
     * 首页文章列表：$cids 为空直接返回空数组，否则转 ArtService::listByCid()（desc=TRUE；light 走目标默认值 TRUE）。
     * 与 homeVideoList 同：底层空结果回传空字符串，此处 `?: array()` 归一为数组。
     * @param array $cids    分类 ID 列表（空数组=不查库）
     * @param int   $page    页码（从 1 起）
     * @param int   $pagesize 每页条数
     * @return array aid 为键的文章行列表；无结果为空数组
     */
    public static function homeArtList(array $cids, int $page, int $pagesize): array
    {
        if (empty($cids)) return array();
        $items = ArtService::listByCid($cids, $page, $pagesize, true);
        return $items ?: array();
    }

    /**
     * 分类文章分页列表：$page/$pagesize 先 intval 再转 ArtService::listByCid($cid, page, pagesize, desc=TRUE)。
     * 权限过滤与格式化在底层 unifiedPull 内完成（读 global $gid / $catelist）。
     * @param mixed $cid      分类 ID 或 ID 数组（底层 empty($cid) ⇒ 空数组短路）
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array|string 文章行列表；⚠ 无结果时目标返回空字符串 ''（非空数组），调用方须按 falsy 判断
     */
    public static function listArtByCid($cid, $page, $pagesize)
    {
        return ArtService::listByCid($cid, intval($page), intval($pagesize), true);
    }

    /**
     * 分类视频分页列表：$page/$pagesize 先 intval 再转 VideoService::listByCid($cid, page, pagesize, desc=TRUE, light=TRUE)。
     * light=TRUE 表示不装配 play_data（列表/区块热路径，审计口径）。
     * @param mixed $cid      分类 ID 或 ID 数组（底层 empty($cid) ⇒ 空数组短路）
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array|string 视频行列表；⚠ 无结果时目标返回空字符串 ''（非空数组），调用方须按 falsy 判断
     */
    public static function listVideoByCid($cid, $page, $pagesize)
    {
        return VideoService::listByCid($cid, intval($page), intval($pagesize), true, true);
    }

    /**
     * 文章点排行：转 ArtService::rankByHit()，并把目标的 {list,total,page,pagesize} 结构改写为 {items,total}。
     * $hit='hits' 走主表 views 累计榜；其余（hits_day/week/month）走排行明细表时间窗聚合（300s 缓存）。
     * @param mixed $hit      榜单类型（白名单在调用侧 ListService/CategoryService 校验）
     * @param mixed $cid      分类 ID 或 ID 数组
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array {items: 排行条目列表, total: int 该分类内容总数}；total 由 intval 归一
     *         （items 只有 'hits' 分支会补 n 序号；时间窗分支不补，模板不要依赖 n 恒存在）
     */
    public static function rankArtByHit($hit, $cid, $page, $pagesize)
    {
        $result = ArtService::rankByHit($hit, $cid, intval($page), intval($pagesize));
        return array('items' => $result['list'], 'total' => intval($result['total']));
    }

    /**
     * 视频点排行：转 VideoService::rankByHit()，把目标的 {list,total,page,pagesize} 改写为 {items,total}。
     * 语义同 rankArtByHit（'hits'=主表 views 榜，其余=排行明细表时间窗）。
     * @param mixed $hit      榜单类型
     * @param mixed $cid      分类 ID 或 ID 数组
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array {items: 排行条目列表, total: int}；total 由 intval 归一（n 序号口径同 rankArtByHit）
     */
    public static function rankVideoByHit($hit, $cid, $page, $pagesize)
    {
        $result = VideoService::rankByHit($hit, $cid, intval($page), intval($pagesize));
        return array('items' => $result['list'], 'total' => intval($result['total']));
    }

    /**
     * 频道文章列表（自算子分类）：先用 channelArtChildCids() 从 $catelist 挑出该频道下的文章子分类，
     * 再取列表 + 计数，并附 CateService::channelArtCache() 的频道聚合缓存（1200s TTL，命中不了为 NULL）。
     * @param mixed $cid      频道（父）分类 ID
     * @param array $catelist 全部分类行（通常传 global $catelist；空 ⇒ child_cids 为空 ⇒ 底层返回空数组）
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array {items, total(int), child_cids(int 列表), channel_cache(array|null)}
     */
    public static function channelArtList($cid, $catelist, $page, $pagesize)
    {
        $cids = self::channelArtChildCids($cid, $catelist);
        $items = ArtService::listByCid($cids, intval($page), intval($pagesize), true);
        return array(
            'items' => $items,
            'total' => ArtService::countByCid($cids),
            'child_cids' => $cids,
            'channel_cache' => CateService::channelArtCache($cid, $pagesize),
        );
    }

    /**
     * 频道视频列表（自算子分类）：channelVideoChildCids() 挑子分类 → 列表 + countByCid + 频道聚合缓存。
     * 与 channelArtList 同构，差别在子分类筛选条件（见 channelVideoChildCids）与缓存键 channel_video_*。
     * @param mixed $cid      频道（父）分类 ID
     * @param array $catelist 全部分类行（通常传 global $catelist）
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array {items, total(int), child_cids(int 列表), channel_cache(array|null)}
     */
    public static function channelVideoList($cid, $catelist, $page, $pagesize)
    {
        $cids = self::channelVideoChildCids($cid, $catelist);
        $items = VideoService::listByCid($cids, intval($page), intval($pagesize), true, true);
        return array(
            'items' => $items,
            'total' => VideoService::countByCid($cids),
            'child_cids' => $cids,
            'channel_cache' => CateService::channelVideoCache($cid, $pagesize),
        );
    }

    /**
     * 频道文章列表（调用方给定分类集合版）：$cids 由 CategoryService 组装（含频道自身），本方法只 normalizeCidList() 归一。
     * 与 channelArtList 的区别：不从 $catelist 现算子分类，因此 $cid 仅用于取 channel_cache。
     * @param mixed $cid      频道（父）分类 ID，只用于 channel_art_{cid}_{pagesize} 缓存键
     * @param array $cids     分类 ID 集合（签名已声明 array；归一后为空 ⇒ items 空数组、total 0）
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array {items, total(int), child_cids(int 列表), channel_cache(array|null)}
     */
    public static function channelArtListByCids($cid, array $cids, $page, $pagesize)
    {
        $cids = self::normalizeCidList($cids);
        $items = ArtService::listByCid($cids, intval($page), intval($pagesize), true);
        return array(
            'items' => $items,
            'total' => ArtService::countByCid($cids),
            'child_cids' => $cids,
            'channel_cache' => CateService::channelArtCache($cid, $pagesize),
        );
    }

    /**
     * 频道视频列表（调用方给定分类集合版）：$cids 经 normalizeCidList() 归一后取列表 + 计数 + channel_video 缓存。
     * 与 channelVideoList 的区别同 channelArtListByCids：不现算子分类，$cid 仅用于缓存键。
     * @param mixed $cid      频道（父）分类 ID，只用于 channel_video_{cid}_{pagesize} 缓存键
     * @param array $cids     分类 ID 集合（归一后为空 ⇒ items 空数组、total 0）
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array {items, total(int), child_cids(int 列表), channel_cache(array|null)}
     */
    public static function channelVideoListByCids($cid, array $cids, $page, $pagesize)
    {
        $cids = self::normalizeCidList($cids);
        $items = VideoService::listByCid($cids, intval($page), intval($pagesize), true, true);
        return array(
            'items' => $items,
            'total' => VideoService::countByCid($cids),
            'child_cids' => $cids,
            'channel_cache' => CateService::channelVideoCache($cid, $pagesize),
        );
    }

    /**
     * 频道（多分类并集）文章榜：转 ArtService::rankChannelByHit()，结构改写为 {items,total}。
     * 与 rankArtByHit 的差别在底层 $countEmpty=TRUE：'hits' 榜空结果时仍用 countByCid 回填真实 total。
     * @param mixed $hit      榜单类型（'hits' 或时间窗）
     * @param array $cidarr   频道分类 ID 集合
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array {items: 条目列表, total: int}
     */
    public static function rankChannelArtByHit($hit, $cidarr, $page, $pagesize)
    {
        $result = ArtService::rankChannelByHit($hit, $cidarr, intval($page), intval($pagesize));
        return array('items' => $result['list'], 'total' => intval($result['total']));
    }

    /**
     * 频道（多分类并集）视频榜：转 VideoService::rankChannelByHit()，结构改写为 {items,total}。
     * 语义同 rankChannelArtByHit（空结果仍回填真实 total）。
     * @param mixed $hit      榜单类型（'hits' 或时间窗）
     * @param array $cidarr   频道分类 ID 集合
     * @param mixed $page     页码（内部 intval）
     * @param mixed $pagesize 每页条数（内部 intval）
     * @return array {items: 条目列表, total: int}
     */
    public static function rankChannelVideoByHit($hit, $cidarr, $page, $pagesize)
    {
        $result = VideoService::rankChannelByHit($hit, $cidarr, intval($page), intval($pagesize));
        return array('items' => $result['list'], 'total' => intval($result['total']));
    }

    /**
     * 类型（筛选项）页视频列表：转 VideoService::findByType($where, page)（vid 倒序分页 + 逐行 format），
     * 再用 VideoService::listAccessFilter() 按 global $gid 就剔除受限栏目行（引用改参，返回同一个数组）。
     * 每页条数不在本层指定，由 findByType 回落 conf['pagesize']（默认 20）。
     * @param array $where 查询条件（列=>值，由 TypeService 组装的筛选条件）
     * @param mixed $page  页码（内部 intval；findByType 声明 int，非数值字符串在 strict 关闭下按 int 强转）
     * @return array vid 为键的视频行列表；无结果为空数组（受限栏目行已被 unset，键位不连续）
     */
    public static function typeVideoList(array $where, $page)
    {
        global $gid;
        $videolist = VideoService::findByType($where, intval($page));
        // 受限栏目过滤（与 list/category 链同口径；上线前审计修复）
        VideoService::listAccessFilter($videolist, $gid);
        return $videolist;
    }

    /**
     * 视频关键词检索（ES 优先 + MySQL 前导通配 LIKE 回退，CLAUDE.md「开发约定·N+1 红线」）：
     *  - $keyword 空 ⇒ 不发查询，直接返回空结果；
     *  - conf['es_on']==1：先 ElasticService::search()（ES 不可用/熔断/零命中一律回 '[]' ⇒ 本层判空）；
     *    ES 零命中 ⇒ 全量走 LIKE（searchKeywordIds，上限 60 条）并标记 mysql_fallback；
     *    ES 命中不足 10 条 ⇒ mergeUniqueIds 补一批 LIKE 结果（ES 顺序在前）；
     *  - es_on 非 1 ⇒ 直接 LIKE。⚠ LIKE 是 `%kw%` 前导通配，必然全表扫（search_use_fulltext=1 时底层改走 ngram FULLTEXT）。
     * 三条出口都以 VideoService::findByIdsOrdered() 回表 + listAccessFilter() 按 $gid 剔除受限栏目；
     * num 恒为**过滤后的返回条数**，不是全库命中总数（单通道上限 60：ES size 60、LIKE limit 60，
     * ES 与 LIKE 合并时可到 ~120）。
     * @param mixed $keyword 关键词（SearchService::resolveKeyword 出口=已 htmlspecialchars 的单转义文本，勿再转义）
     * @param array $conf    站点配置（读 es_on 键，松散比较 ==1 ⇒ TRUE/'1' 同效）
     * @return array {search_type: 'like'|'mysql_fallback', videolist: 顺序数组, num: int}
     *         ⚠ ES 正常命中（≥10 条）时 search_type 仍是 'like' ⇒ 该字段只能区分「LIKE 兜底」与「其它」，
     *           不能当「本次是否用了 ES」的判据（字段语义与实现不一致，改动前先与前台模板/SearchService 消费点对齐）
     */
    public static function searchVideos($keyword, $conf)
    {
        global $gid;
        $search_type = 'like';
        $videolist = array();
        $vidarr = array();

        if (!$keyword) {
            return array('search_type' => $search_type, 'videolist' => $videolist, 'num' => 0);
        }

        if ($conf['es_on'] == 1) {
            $vidarr = self::elasticSearchIds($keyword);
            if (empty($vidarr)) {
                $search_type = 'mysql_fallback';
                $vidarr = VideoService::searchKeywordIds($keyword);
                $videolist = VideoService::findByIdsOrdered($vidarr, TRUE);
                VideoService::listAccessFilter($videolist, $gid);
                return array('search_type' => $search_type, 'videolist' => $videolist, 'num' => count($videolist));
            }

            if (count($vidarr) < 10) {
                $vidarr = self::mergeUniqueIds($vidarr, VideoService::searchKeywordIds($keyword));
            }
            $videolist = VideoService::findByIdsOrdered($vidarr, TRUE);
            VideoService::listAccessFilter($videolist, $gid);
            return array('search_type' => $search_type, 'videolist' => $videolist, 'num' => count($videolist));
        }

        $vidarr = VideoService::searchKeywordIds($keyword);
        $videolist = VideoService::findByIdsOrdered($vidarr, TRUE);
        VideoService::listAccessFilter($videolist, $gid);
        return array('search_type' => $search_type, 'videolist' => $videolist, 'num' => count($videolist));
    }

    /**
     * 合并两组 vid：ES 命中在前、LIKE 补齐在后，逐条 intval、丢弃 <=0、以值作键去重后重排为顺序数组。
     * 语义要点：只去重，**不截断**，所以 LIKE 补进来的量等于其全部上限（60 条）。
     * @param array $vidarr      ES 命中的 vid 列表（优先保留顺序）
     * @param array $extraVidarr LIKE 补齐的 vid 列表
     * @return array 去重后的 int vid 列表
     */
    protected static function mergeUniqueIds(array $vidarr, array $extraVidarr): array
    {
        $merged = array();
        foreach (array_merge($vidarr, $extraVidarr) as $vid) {
            $vid = intval($vid);
            if ($vid > 0) {
                $merged[$vid] = $vid;
            }
        }
        return array_values($merged);
    }

    /**
     * ES 关键词检索取 vid 列表：转 ElasticService::search()（返回 JSON 字符串），解码后取每行 vid 列，
     * intval 归一并去 0 去重。ES 侧任何失败路径（熔断/请求失败/零命中）都被目标收敛为 '[]' ⇒ 本方法返回空数组，
     * 由 searchVideos 走 LIKE 回退；解码失败（非 JSON/非法结构）同样返回空数组。
     * 命中上限由 ElasticService::search 内部 size=60 决定，本层不再限流。
     * @param mixed $keyword 关键词（目标声明 string ⇒ 传非字符串需调用方保证可强转）
     * @return array int 型 vid 列表（顺序即 ES 相关性顺序）
     */
    protected static function elasticSearchIds($keyword): array
    {
        $esjson = \App\Service\System\ElasticService::search($keyword);
        $arrlist = xn_json_decode($esjson);
        if (!is_array($arrlist)) return array();

        $vidarr = arrlist_values($arrlist, 'vid');
        if (empty($vidarr)) return array();
        return array_values(array_unique(array_filter(array_map('intval', $vidarr))));
    }

    /**
     * 从全部分类行里挑出「挂在 $cid 下、可直接列表取文章」的子分类：cup==$cid 且 type==1（内容栏目）
     * 且 model==1（文章模型）且 category==0（列表页型，非频道/专题/阅读页）。
     * @param mixed $cid      父（频道）分类 ID（松散比较 == ⇒ 传字符串 id 也可匹配）
     * @param array $catelist 全部分类行；空 ⇒ 结果为空数组
     * @return array 归一化后的 int 子分类 ID 列表（见 normalizeCidList）
     */
    protected static function channelArtChildCids($cid, $catelist): array
    {
        $cids = array();
        if ($catelist) {
            foreach ($catelist as $val) {
                if ($val['cup'] == $cid && 1 == $val['type'] && 1 == $val['model'] && 0 == $val['category']) {
                    $cids[] = $val['cid'];
                }
            }
        }
        return self::normalizeCidList($cids);
    }

    /**
     * 从全部分类行里挑出「挂在 $cid 下的可列表子分类」：cup==$cid 且 type==1 且 category==0。
     * ⚠ 与 channelArtChildCids 不同，**不加 model 条件** ⇒ 文章型子分类也会进集合；
     *   实际影响有限（视频索引表按文章分类 cid 取不到行），但 child_cids 与 total 的口径与文章侧不一致。
     * @param mixed $cid      父（频道）分类 ID
     * @param array $catelist 全部分类行；空 ⇒ 结果为空数组
     * @return array 归一化后的 int 子分类 ID 列表
     */
    protected static function channelVideoChildCids($cid, $catelist): array
    {
        $cids = array();
        if ($catelist) {
            foreach ($catelist as $val) {
                if ($val['cup'] == $cid && 1 == $val['type'] && 0 == $val['category']) {
                    $cids[] = $val['cid'];
                }
            }
        }
        return self::normalizeCidList($cids);
    }

    /**
     * 分类 ID 集合归一三件套：逐条 intval ⇒ array_filter 丢 falsy（0 与 '0' 被剔除）⇒ array_unique ⇒ 重排为 0..n 顺序键。
     * 注意副作用：0/负数/非数字串一律被丢弃，所以本方法既做清洗也做有效性过滤。
     * @param array $cids 原始 ID 列表（值可为字符串形态）
     * @return array int 型、去重、顺序键的 ID 列表
     */
    protected static function normalizeCidList(array $cids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $cids))));
    }
}
