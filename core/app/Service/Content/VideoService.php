<?php
namespace App\Service\Content;

class VideoService
{
    use VideoStickyTrait, VideoQueryTrait, \App\Service\Content\Traits\ListAccessFilterTrait, \App\Service\Content\Traits\ContentRankTrait, \App\Service\Content\Traits\ContentQueryTrait;

    // ---- ContentRankTrait 配置（主线 2 第二刀：Art/Video 排行族同构） ----
    protected static function rankTopTable(): string { return 'vod_top'; }
    protected static function queryDescIndexCachePrefix(): string { return 'video_find_desc_'; }

    /** ContentRankTrait 配置点：排行明细表（vod_top）的内容主键列名。@return string */
    protected static function rankTopIdCol(): string { return 'vid'; }
    /** ContentRankTrait 配置点：「同 IP 同内容 60s 去重」缓存键前缀（逐字节保持历史口径，改动会令存量去重缓存失效）。@return string */
    protected static function rankIpCachePrefix(): string { return 'top_vod_ip_'; }
    /** ContentRankTrait 配置点：时间窗热度榜结果缓存键前缀（hitList 用，TTL 300s）。@return string */
    protected static function rankHitCachePrefix(): string { return 'top_hit_cid_vod_'; }
    /** ContentRankTrait 配置点：内容主表名（'hits' 全周期榜直接按主表 views 列取数时用）。@return string */
    protected static function rankContentTable(): string { return 'vod'; }
    /** ContentRankTrait 配置点：内容主表仓库（RepositoryFactory::vod()，incViews 攒批后 bigUpdate 回写 views 用）。@return \App\Repository\DbTableRepository */
    protected static function rankViewsRepo() { return \App\Repository\RepositoryFactory::vod(); }
    /** ContentRankTrait 配置点：按 id 列表升序装配条目；Video 侧固定 light=TRUE（排除 play_from/play_url 等大字段）。@return array|NULL 无数据返回 NULL */
    protected static function rankFindAsc(array $ids, int $pagesize) { return self::findAsc($ids, $pagesize, true); }

    // ---- ContentQueryTrait 配置（主线 2 第三刀：Art/Video 查询族同构） ----
    protected static function queryIndexRepo() { return \App\Repository\RepositoryFactory::vodVid(); }
    /** ContentQueryTrait 配置点：内容主键列名（cond/orderby 构造与 find 结果键列均用它）。@return string */
    protected static function queryIdCol(): string { return 'vid'; }
    /** ContentQueryTrait 配置点：indexIds 未显式传列时的默认列 array('vid')（Video 侧显式给列，天然规避 SELECT *；Art 侧返回空数组走全列留痕）。@return array */
    protected static function queryIndexIdsDefaultCol(): array { return array('vid'); }
    /** ContentQueryTrait 配置点：内容主表仓库（RepositoryFactory::vod()，vod 全列表）。@return \App\Repository\DbTableRepository */
    protected static function queryContentRepo() { return \App\Repository\RepositoryFactory::vod(); }
    /** ContentQueryTrait 配置点：按分类计数，转发 ContentQueryRepository::videoCountByCid()（数的是 vod_vid 索引表）。@return int */
    protected static function queryCountByCidImpl($cid, $d) { return \App\Repository\RepositoryFactory::contentQuery()->videoCountByCid($cid, $d); }
    /** ContentQueryTrait 配置点：listIndexByCid 缓存键前缀（后接 cid_page_pagesize；逐字节历史口径，改动会令存量缓存全量失效）。@return string */
    protected static function queryListIndexCachePrefix(): string { return 'video_find_vid_'; }
    /** ContentQueryTrait 配置点：descIndexByCid 缓存键前缀，与 list 前缀分桶互不污染。@return string */
    /** ContentQueryTrait 配置点：主表写入实现，转发兼容壳 video__update()→RepositoryFactory::vod()->update()（$id 传数组经 applyCond 变 IN 可批量）。@return int|false 影响行数；底层空参/失败为 false */
    protected static function queryUpdateImpl($id, array $update) { return video__update($id, self::fitColumns($update)); }

    /**
     * vod 表 varchar/char 列宽（字符数，utf8mb4；与 core/install/install.sql 及线上 DESCRIBE 一致）。
     * 采集源字段（演员/简介/编剧/副标等）常超长，严格模式下 MySQL 报 SQLSTATE[22001] Data too long 整行入库失败。
     */
    private const VOD_COLUMN_LIMITS = array(
        'alias' => 32, 'tag' => 255, 'uptime' => 255, 'name' => 255, 'sub' => 255, 'en' => 255, 'letter' => 1,
        'pic' => 1024, 'pic_slide' => 1024, 'actor' => 255, 'director' => 255, 'writer' => 100, 'behind' => 100,
        'blurb' => 255, 'remarks' => 100, 'pubdate' => 100, 'serial' => 20, 'area' => 20, 'lang' => 10,
        'year' => 10, 'version' => 30, 'play_from' => 255, 'play_server' => 255, 'play_note' => 255,
    );

    /**
     * 入库前按 vod 列宽截断字符串字段（多字节安全，按字符计）；非字符串值与未知列原样保留。
     * 采集（videoRowPayload / fastDbBuildVodRow）与 VideoService::update 统一经此收口。
     * @param array $row 列=>值
     * @return array 截断后的行
     */
    public static function fitColumns(array $row): array
    {
        foreach (self::VOD_COLUMN_LIMITS as $col => $max) {
            if (isset($row[$col]) && is_string($row[$col])) {
                $row[$col] = self::fitText($row[$col], $max);
            }
        }
        return $row;
    }

    /**
     * 字符串截断到 $max 个字符（UTF-8 多字节安全），并去掉截断造成的半截 HTML 实体
     * （采集字段入库前已 htmlspecialchars，`&amp;` 被切成 `&am` 会在前台显示乱码）。
     */
    public static function fitText(string $s, int $max): string
    {
        if (function_exists('mb_strlen')) {
            if (mb_strlen($s, 'UTF-8') <= $max) return $s;
            $cut = mb_substr($s, 0, $max, 'UTF-8');
        } else {
            if (strlen($s) <= $max) return $s;
            $cut = (string)@iconv('UTF-8', 'UTF-8//IGNORE', substr($s, 0, $max));
        }
        return (string)preg_replace('/&#?[A-Za-z0-9]{0,8}$/', '', $cut);
    }
    /** ContentQueryTrait 配置点：read()/update() 内容行缓存键前缀（后接 intval(vid)，缓存键 vod_<id> 的历史口径）。@return string */
    protected static function queryCachePrefix(): string { return 'vod_'; }
    /**
     * 显式列清单（SQL 专项审计修复 R10 / P0-2）
     *
     * 修复前：queryFindShell 调 video__find() **未传 col** ⇒ DbTableRepository::find 落到
     * `$col ?: array('*')` ⇒ SELECT * 拉全 40 列，含 `play_url`（实测均值 5,398 字节、
     * 最大 171,527 字节）与 `content`。而列表/区块热路径（listByCid→unifiedPull、
     * stickyVideoItems）传的 light=TRUE 根本不装配 play_data ⇒ 这些字节纯浪费：
     * 单次 20 行列表请求多读 ~120KB。
     * 现按 light 分层取列：轻量层只取 format()/listAccessFilter()/模板实际引用的列；
     * 仅在 light=FALSE（详情/编辑需要装配 play_data）时才补 play_from/play_url。
     * `content` 不进本方法：列表与 unifiedPull 均不消费（详情走 read()/video__read）。
     */
    protected static function queryFindCols(bool $light): array
    {
        $cols = array(
            'vid', 'cid', 'name', 'sub', 'en', 'letter', 'pic', 'pic_slide', 'alias',
            'tag', 'actor', 'director', 'writer', 'behind', 'blurb', 'remarks',
            'pubdate', 'total', 'serial', 'area', 'lang', 'year', 'version', 'level',
            'douban_id', 'douban_score', 'status', 'sticky', 'uptime', 'vod_id',
            'views', 'favorites', 'likes', 'create_date', 'cup', 'play_server', 'play_note',
        );
        if (!$light) {
            $cols[] = 'play_from';
            $cols[] = 'play_url';
        }
        return $cols;
    }
    /**
     * ContentQueryTrait 配置点：主表批量查询壳，转发兼容壳 video__find()→RepositoryFactory::vod()->find()，
     * 结果以 vid 为键；$col 须传 queryFindCols() 产出的显式列清单（传空数组会落 SELECT * 全 40 列，R10 留痕）。
     * @return array vid 为键的行集，无结果空数组
     */
    protected static function queryFindShell(array $cond, array $orderby, int $page, int $pagesize, array $col = array()) { return video__find($cond, $orderby, $page, $pagesize, 'vid', $col); }

    /**
     * 轻量取列清单的公开门面（SQL 专项审计修复 R10）
     * 供区块/列表等直接调用 Repository::find() 的旁路复用同一口径，
     * 避免各自手写列清单造成漂移（queryFindCols 为 protected 抽象配置点）。
     */
    public static function findColsLight(): array
    {
        return self::queryFindCols(TRUE);
    }

    /** 完整取列清单（含 play_from/play_url）的公开门面，供需要装配 play_data 的旁路使用 */
    /**
     * ContentQueryTrait 配置点：行格式化桥，转发 self::format(&$row, $light)。
     * 语义是按引用原地补展示字段（url/cate_name/日期/tag_fmt 等）；$light=FALSE 时装配 play_data。
     * @param array $row 按引用修改
     * @return array|NULL format() 空行早退无返回（NULL）、正常路径返回装配后的行数组；
     *         返回值无统一契约，调用方不得消费，以 $row 的引用修改为准
     */
    protected static function queryFormatRow(&$row, $light = FALSE) { return self::format($row, $light); }
    /**
     * ContentQueryTrait 配置点：单行读取壳，转发兼容壳 video__read(array('vid' => $id))→vod()->findOne()，
     * 返回原始行（未做 format 加工、未转义处理）。
     * @return array|NULL 行数据；无命中 NULL
     */
    protected static function queryReadShell($id) { return video__read(array('vid' => $id)); }
    /** ContentQueryTrait 配置点：read() 内容行缓存 TTL 秒数（Art 侧 3600，Video 侧 1800）。@return int */
    protected static function queryReadTtl(): int { return 1800; }
    /**
     * ContentQueryTrait 配置点：索引表（vod_vid）分页取 id 壳，转发兼容壳 video_vid__find()，
     * 固定只取 vid 列（显式列消 SELECT *）；结果以 vid 为键。
     * @return array vid 为键的行集，空结果空数组
     */
    protected static function queryIndexFindShell(array $cond, array $orderby, int $page, int $pagesize) { return video_vid__find($cond, $orderby, $page, $pagesize, 'vid', array('vid')); }
    /**
     * ContentQueryTrait 配置点：列表统一拉取装配桥——经 self::unifiedPull() 回主表、
     * 会员权限过滤（listAccessFilter）、按传入 idlist 顺序重排后取 'videolist' 键。
     * @param array $idlist vid 集合
     * @param bool $light 透传给 find，决定取不取 play_from/play_url 大字段
     * @return array|'' 行列表；idlist 为空时 unifiedPull 短路 NULL，array_value 缺省返回空字符串（调用方按 falsy 处理）
     */
    protected static function queryPullForList($idlist, $light = TRUE) { return array_value(self::unifiedPull(array('vidlist' => $idlist, 'light' => $light)), 'videolist'); }
    /** ContentQueryTrait 配置点：findByKeyword 传给 ContentQueryRepository::searchByKeyword 的表名标识。@return string */
    protected static function querySearchType(): string { return 'vod'; }

    /**
     * 播放源 URL 装配（原 core.php video_play 下沉）
     * @param string $play_from 播放源（$$$ 分隔多源）
     * @param string $play_url 播放地址（$$$ 分隔，组内 # 分隔、$ 分隔名称与地址）
     * @param string $alias 栏目别名（伪静态路由前缀）
     * @param int $vid 视频 ID
     * @return array 播放源列表
     */
    public static function playData(string $play_from, string $play_url, string $alias, int $vid): array
    {
        $conf = _SERVER('conf');
        $route_page_vodplay = $conf['route']['play'];
        $from_list = explode('$$$', $play_from);
        $url_list = explode('$$$', $play_url);

        // 构建 URL 模板（外层一次，避免内层循环反复调 url()）
        $rewrite = $conf['url_rewrite_on'] ?? 0;
        $path    = $conf['path'] ?? './';
        $useAlias = $rewrite > 1 && !empty($alias);
        $routePrefix = $useAlias ? urlencode($alias) : $route_page_vodplay;
        /** @var string|null sprintf 模板，null 时降级调 url() */
        switch ($rewrite) {
            case 0: $urlTpl = "?{$routePrefix}-%d-%d-%d.html"; break;
            case 1: $urlTpl = "{$routePrefix}-%d-%d-%d.html"; break;
            case 2: $urlTpl = "{$path}{$routePrefix}/%d/%d/%d.html"; break;
            case 3: $urlTpl = "{$path}{$routePrefix}/%d/%d/%d/"; break;
            default: $urlTpl = null; break;
        }

        $result = [];
        $vp_cfg = $conf['vodplayer'] ?? array();
        foreach ($from_list as $i => $from) {
            $info = [];
            if (!isset($url_list[$i])) continue;
            $group_urls = explode('#', $url_list[$i]);
            foreach ($group_urls as $j => $ep) {
                if (!str_contains($ep, '$')) continue;
                [$title, $real_url] = explode('$', $ep, 2);
                $rwurl = $urlTpl !== null
                    ? sprintf($urlTpl, $vid, $i + 1, $j + 1)
                    : url($route_page_vodplay . '-' . $vid . '-' . ($i + 1) . '-' . ($j + 1), '', false);
                $info[] = [
                    'n' => ($j + 1),
                    'name' => htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8'),
                    'url' => $rwurl,
                    // 读侧字符闸：防存量库行中的引号/尖括号经 iframe src 逃逸（入库闸见 xn_url_strip 注释）
                    'play_url' => xn_url_strip($real_url),
                ];
            }
            $result[] = [
                'n'    => ($i + 1),
                'name' => htmlspecialchars((string)($vp_cfg[$from]['show'] ?? $vp_cfg['default']['show'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'url'  => $info[0]['url'] ?? '',
                'from' => $from,
                'info' => $info,
            ];
        }
        return $result;
    }

    /**
     * 重复视频分组（GROUP BY name + HAVING count > 1）
     * @return array [['group_vid','name','vids'=>array,'count'], ...]
     */
    public static function findDuplicates(): array
    {
        $db = _SERVER('db') ?? NULL;
        if (!$db) return array();
        $rows = \App\Repository\RepositoryFactory::vod()->builder()
            ->select(array('name', 'GROUP_CONCAT(vid) as vids', 'COUNT(*) as count'))
            ->groupBy('name')
            ->having('count', '>', 1)
            ->orderBy('count', 'DESC')
            ->get();
        if (empty($rows)) return array();

        $duplicates = array();
        foreach ($rows as $row) {
            $vids = array_map('intval', explode(',', (string)$row['vids']));
            $videos = self::findAsc($vids, 20, true);
            $videos = $videos ? array_values($videos) : array();
            if (!$videos) continue;
            $duplicates[] = array(
                'group_vid' => $vids[0],
                'name' => $row['name'],
                'vids' => self::findAscVids($vids, 20),
                'count' => intval($row['count']),
            );
        }
        return $duplicates;
    }

    /**
     * 取 vid 列表的真实在库键集（vid 升序、上限 $limit，只查 vid 列）。
     * findDuplicates() 专用：把 GROUP_CONCAT 得到的原始 vids 规整为 vod 表实际存在且按 vid 升序的键数组。
     * @param array $vids 候选 vid 数组
     * @param int $limit 条数上限
     * @return array vid 键数组；查询无结果时回退返回入参 $vids（不返回空数组）
     */
    private static function findAscVids(array $vids, int $limit): array
    {
        $rows = \App\Repository\RepositoryFactory::vod()->find(array('vid' => $vids), array('vid' => 1), 1, $limit, 'vid', array('vid'));
        if (!$rows) return $vids;
        $keys = array_keys($rows);
        return $keys ?: $vids;
    }

    /**
     * 合并重复视频：把来源视频的播放地址追加到目标视频，成功后删除来源
     */
    public static function mergeVideos($target_vid, array $source_vids)
    {
        if (empty($target_vid) || empty($source_vids)) return FALSE;
        $target = video__read(array('vid' => $target_vid));
        if (empty($target)) return FALSE;

        $play_urls = array();
        $play_froms = array();
        $play_servers = array();
        $play_notes = array();
        $source_list = video__find(array('vid' => $source_vids), array('vid' => 1), 1, count($source_vids), 'vid');
        if ($source_list) {
            foreach ($source_list as $video) {
                if (empty($video['play_url'])) continue;
                $play_urls[] = $video['play_url'];
                $play_froms[] = $video['play_from'] ?? '';
                $play_servers[] = $video['play_server'] ?? '';
                $play_notes[] = $video['play_note'] ?? '';
            }
        }

        $r = FALSE;
        if (!empty($play_urls)) {
            $update = array();
            if (!empty($target['play_url'])) {
                $update['play_url'] = $target['play_url'] . '$$$' . implode('$$$', $play_urls);
                $update['play_from'] = $target['play_from'] . '$$$' . implode('$$$', $play_froms);
                $update['play_server'] = $target['play_server'] . '$$$' . implode('$$$', $play_servers);
                $update['play_note'] = $target['play_note'] . '$$$' . implode('$$$', $play_notes);
            } else {
                $update['play_url'] = implode('$$$', $play_urls);
                $update['play_from'] = implode('$$$', $play_froms);
                $update['play_server'] = implode('$$$', $play_servers);
                $update['play_note'] = implode('$$$', $play_notes);
            }
            $r = self::update($target_vid, $update);
        }

        if ($r) {
            foreach ($source_vids as $source_vid) {
                self::deleteAll($source_vid);
            }
        }
        return $r;
    }

    /**
     * 彻底删除视频（单 id 或数组批量）：回收站快照 + 全部关联面清理。
     * 链路：set_time_limit(0) → video__find 取回整行（取不到行 ⇒ FALSE）→ tag_video 关联删除 +
     * tag.count 按引用次数 bigUpdate 递减 → 涉置顶时清 sticky_video_list、推进 sticky_video_ver、
     * 删 vod_sticky 行 → snapshotVod() 落回收站（含 vod_vid 关联行，供恢复重建）→ 逐 vid 失效 vod_<id>
     * 行缓存 → 删 vod 主表与 vod_vid 索引表 → 清 runtime 计数缓存。
     * ⚠ 函数本身不做权限/CSRF 判定，调用方（后台路由）负责；返回值不反映各步 DELETE 的 rowCount。
     * @param int|array $vid vid 或 vid 数组
     * @return bool TRUE=删除链路执行完毕；FALSE=入参为空或库中无对应行
     */
    public static function deleteAll($vid)
    {
        \App\Service\Front\TypeService::bumpFacets(); // 筛选统计缓存失效
        if (empty($vid)) return FALSE;
        set_time_limit(0);
        $n = is_array($vid) ? count($vid) : 1;
        $videolist = video__find(array('vid' => $vid), array('vid' => 1), 1, $n, 'vid');
        if (empty($videolist)) return FALSE;

        $vids = array();
        $tagarr = array();
        $tagvids = array();
        $sticky_vids = array();
        foreach ($videolist as $video) {
            $vids[] = $video['vid'];
            if (!empty($video['tag'])) {
                $tagvids[] = $video['vid'];
                $_tagarr = xn_json_decode($video['tag']);
                foreach ((array)$_tagarr as $_tagid => $tagname) {
                    isset($tagarr[$_tagid]) ? $tagarr[$_tagid] += 1 : $tagarr[$_tagid] = 1;
                }
            }
            if (!empty($video['sticky'])) {
                $sticky_vids[] = $video['vid'];
            }
        }

        // ⚠ 快照必须最先做（复核修正）：旧顺序把「标签解绑 + tag.count 递减 + 推荐位清理」放在
        //   snapshotVod() 之前，而快照失败会中止删除 ⇒ 内容还在、绑定已删、计数已减、推荐已撤，
        //   形成永久漂移（ArtService 侧顺序本来就对，此处对齐）。先快照，失败即早退，零副作用。
        if ($vids && false === self::snapshotVod($videolist)) return FALSE;   // 快照失败 ⇒ 中止删除（C1：不可恢复的内容不删）

        $relRowsByTag = array();
        if (!empty($tagvids)) {
            $arlist = \App\Service\Content\TagFlowService::videoFindByVidAll($tagvids); // 分页取尽（旧 pagesize 启发式上限清理不净）
            if ($arlist) {
                $ids = array();
                foreach ($arlist as $val) {
                    $ids[] = $val['id'];
                    // 顺带统计每个 tagid 实际删了几行绑定（重复绑定时恒减 1 会让 tag.count 虚高）
                    $_t = intval($val['tagid']);
                    isset($relRowsByTag[$_t]) or $relRowsByTag[$_t] = 0;
                    ++$relRowsByTag[$_t];
                }
                \App\Repository\RepositoryFactory::tagVideo()->delete(array('id' => $ids));
            }
        }

        // 标签计数回补按**实际删掉的绑定行数**（存量库同一 (tagid,vid) 可能多行），
        // 且走 TagFlowService::decrementTagCounts() 以失效 web_tag_{tagid} 缓存（原 bigUpdate 不清）。
        !empty($tagarr) && \App\Service\Content\TagFlowService::decrementTagCounts($relRowsByTag, $tagarr);
        if (!empty($sticky_vids)) {
            cache_delete('sticky_video_list');
            cache_set('sticky_video_ver', time(), 86400);
            \App\Repository\RepositoryFactory::stickyVod()->delete(array('vid' => $sticky_vids));
        }

        if ($vids) {
            foreach ($vids as $_vid) content_cache_delete('vod_', $_vid);
            \App\Repository\RepositoryFactory::vod()->delete(array('vid' => $vids));
            \App\Repository\RepositoryFactory::vodVid()->delete(array('vid' => $vids));
        }
        cache_delete('runtime'); // 计数缓存失效：彻底删除同样影响内容计数（与 ArtService::deleteAll 同口径，审计修复）
        return TRUE;
    }

    /**
     * 删除视频（单 id 或数组批量）：软删口径——先 snapshotVod() 写回收站快照，再经 video__delete 删 vod 主表行，
     * 成功后同步清理 vod_vid 索引行、tag_video 关联与 tag.count 递减、置顶行及 sticky 缓存
     * （与 deleteAll 对齐；此前软删只删主表致孤儿残留，审计修复），并失效受影响行的 vod_<id> 缓存。
     * 'runtime' 计数缓存恒清（与 ArtService 同口径：批量删同样影响内容计数，不因 id 个数而异）。
     * ⚠ 函数本身不做权限/CSRF 判定，调用方负责。
     * @param int|array $vid vid 或 vid 数组
     * @return int|false video__delete 的影响行数（无匹配行为 0）；入参为空或底层返回 FALSE 时为 FALSE
     */
    public static function delete($vid)
    {
        \App\Service\Front\TypeService::bumpFacets(); // 筛选统计缓存失效
        if (empty($vid)) return FALSE;
        // 软删：先写回收站快照再物理删除；快照失败 ⇒ 中止（审查修复轮 C1，fail-closed）
        $n = is_array($vid) ? count($vid) : 1;
        $videolist = video__find(array('vid' => $vid), array('vid' => 1), 1, $n, 'vid');
        if (false === self::snapshotVod($videolist)) return FALSE;
        $r = video__delete($vid);
        if (FALSE === $r) return FALSE;
        // 同步清理索引/标签/置顶关联（与 deleteAll 对齐；此前软删只删主表，vod_vid/tag_id/sticky 孤儿残留，审计修复）
        $vids = is_array($vid) ? array_values($vid) : array($vid);
        $tagarr = array();
        $tagvids = array();
        $sticky_vids = array();
        foreach ($videolist as $video) {
            if (!empty($video['tag'])) {
                $tagvids[] = $video['vid'];
                $_tagarr = xn_json_decode($video['tag']);
                foreach ((array)$_tagarr as $_tagid => $tagname) {
                    isset($tagarr[$_tagid]) ? $tagarr[$_tagid] += 1 : $tagarr[$_tagid] = 1;
                }
            }
            if (!empty($video['sticky'])) {
                $sticky_vids[] = $video['vid'];
            }
        }
        $relRowsByTag = array();
        if (!empty($tagvids)) {
            $arlist = \App\Service\Content\TagFlowService::videoFindByVidAll($tagvids); // 分页取尽（旧 pagesize 启发式上限清理不净）
            if ($arlist) {
                $ids = array();
                foreach ($arlist as $val) {
                    $ids[] = $val['id'];
                    // 顺带统计每个 tagid 实际删了几行绑定（重复绑定时恒减 1 会让 tag.count 虚高）
                    $_t = intval($val['tagid']);
                    isset($relRowsByTag[$_t]) or $relRowsByTag[$_t] = 0;
                    ++$relRowsByTag[$_t];
                }
                \App\Repository\RepositoryFactory::tagVideo()->delete(array('id' => $ids));
            }
        }
        // 标签计数回补按**实际删掉的绑定行数**（存量库同一 (tagid,vid) 可能多行），
        // 且走 TagFlowService::decrementTagCounts() 以失效 web_tag_{tagid} 缓存（原 bigUpdate 不清）。
        !empty($tagarr) && \App\Service\Content\TagFlowService::decrementTagCounts($relRowsByTag, $tagarr);
        if (!empty($sticky_vids)) {
            cache_delete('sticky_video_list');
            cache_set('sticky_video_ver', time(), 86400);
            \App\Repository\RepositoryFactory::stickyVod()->delete(array('vid' => $sticky_vids));
        }
        if ($vids) {
            \App\Repository\RepositoryFactory::vodVid()->delete(array('vid' => $vids));
        }
        if (is_array($vid)) {
            foreach ($vid as $_vid) content_cache_delete('vod_', $_vid);
        } else {
            content_cache_delete('vod_', $vid);
        }
        cache_delete('runtime'); // 计数缓存失效（此前写 'videos-' 键与 runtime_init 的 'vod' 键错配，总数永不更新；审计修复；现恒清，与 ArtService 同口径）
        return $r;
    }

    // 视频快照入回收站（软删前置；含 vod_vid 关联行，恢复时重建）。
    // ⚠ 审查修复轮 C1：任一条 put 失败返回 FALSE——调用方据此**中止删除**（fail-closed：
    // 快照写不进去就绝不能删主表，否则内容不可恢复且无留痕；旧实现无视返回值照删）。
    private static function snapshotVod(array $videolist)
    {
        // P-1（2026-10-10 性能修复）：单次 whereIn 分块取回 + PHP 分桶（原：每 vid 一次 SELECT，
        // 批量上限 500 条时 = 500 次往返）。块大小 200 与 admin_param_ids 口径一致。
        $bucket = array();
        $vids = array();
        foreach ($videolist as $video) $vids[] = intval($video['vid']);
        foreach (array_chunk($vids, 200) as $chunk) {
            $rows = db_table('vod_vid')->whereIn('vid', $chunk)->get();
            foreach ((array)$rows as $r) $bucket[intval($r['vid'])][] = $r;
        }
        $done = array();
        foreach ($videolist as $video) {
            $vid = intval($video['vid']);
            if (false === \App\Service\Content\RecycleService::put(\App\Service\Content\RecycleService::TYPE_VOD, $vid, array('row' => $video, 'vod_vid' => $bucket[$vid] ?? array()))) {
                xn_log('snapshotVod: recycle put failed for vid=' . $vid . ', delete aborted', 'recycle_error');
                // ⚠ 补偿清理（复核修正）：失败前已写入的 k-1 条是「半快照」——内容仍在主表却挂在回收站里，
                //   既恢复不了（恢复会因主键已存在而失败）又只能 purge，属幻影条目。这里按本批 vid 回删。
                if (!empty($done)) {
                    \App\Repository\RepositoryFactory::table('recycle', 'id')->delete(array('type' => \App\Service\Content\RecycleService::TYPE_VOD, 'target_id' => $done));
                    xn_log('snapshotVod: rolled back ' . count($done) . ' partial snapshot(s): ' . implode(',', $done), 'recycle_error');
                }
                return false;
            }
            $done[] = $vid;
        }
        return true;
    }
}
