<?php
namespace App\Service\Content;

class ArtService
{
    use \App\Service\Content\Traits\ListAccessFilterTrait;
    use \App\Service\Content\Traits\ContentRankTrait;
    use \App\Service\Content\Traits\ContentQueryTrait;

    // ---- ContentRankTrait 配置（主线 2 第二刀：Art/Video 排行族同构） ----
    protected static function rankTopTable(): string { return 'art_top'; }
    protected static function queryDescIndexCachePrefix(): string { return 'art_find_desc_'; }

    /** ContentRankTrait 配置点：排行明细表（art_top）与主表的内容主键列名，Art 侧恒为 'aid'（Video 侧 'vid'）。@return string */
    protected static function rankTopIdCol(): string { return 'aid'; }
    /** ContentRankTrait 配置点：recordTopView 的「同 IP + 同内容 60s 去重」缓存键前缀（Art 侧 'top_art_ip_'）。收敛 Art/Video 双份实现时逐字节保持，改动会使存量去重记录失效。@return string */
    protected static function rankIpCachePrefix(): string { return 'top_art_ip_'; }
    /** ContentRankTrait 配置点：时间窗热度榜结果缓存键前缀（hitList 用，TTL 300s，Art 侧 'top_hit_cid_art_'）。同属缓存键兼容口径，改动等于清空榜单结果缓存。@return string */
    protected static function rankHitCachePrefix(): string { return 'top_hit_cid_art_'; }
    /** ContentRankTrait 配置点：'hits' 全周期累计榜直接按主表 views 列排序取 id 时的内容主表名（Art 侧 'art'）。@return string */
    protected static function rankContentTable(): string { return 'art'; }
    /** ContentRankTrait 配置点：转发 RepositoryFactory::art()，供 incViews 请求尾 bigUpdate 批量回写 views 用。@return \App\Repository\DbTableRepository */
    protected static function rankViewsRepo() { return \App\Repository\RepositoryFactory::art(); }
    /** ContentRankTrait 配置点：按 aid 升序装配榜单条目，转发 ContentQueryTrait::findAsc()。Art 侧不传 light——queryFindCols 的清单本就不含大字段（Video 侧才需要裁 play_url）。@param array $ids aid 数组 @param int $pagesize 取数上限 @return array|NULL aid 为键的行集；无数据时底层返回 NULL */
    protected static function rankFindAsc(array $ids, int $pagesize) { return self::findAsc($ids, $pagesize); }

    // ---- ContentQueryTrait 配置（主线 2 第三刀：Art/Video 查询族同构） ----
    protected static function queryIndexRepo() { return \App\Repository\RepositoryFactory::artAid(); }
    /** ContentQueryTrait 配置点：内容主键列名 'aid'，用于 cond/orderby 构造与 find() 的结果重键。@return string */
    protected static function queryIdCol(): string { return 'aid'; }
    /** ContentQueryTrait 配置点：indexIds 未显式传列时的默认列清单，Art 侧返回空数组 ⇒ 落到 DbTableRepository::find 的 $col 空分支走 SELECT *（索引表 art_aid 仅 aid/cid 两列，代价小）（底层 logStarSelect 仅 DEBUG>0 落盘 sql_star_select 留痕）；Video 侧返回 array('vid')。@return array 恒为空数组 */
    protected static function queryIndexIdsDefaultCol(): array { return array(); }
    /** ContentQueryTrait 配置点：转发 RepositoryFactory::art()，即 art 内容主表（全列）仓库，count()/maxId()/findPageByCond() 走它。@return \App\Repository\DbTableRepository */
    protected static function queryContentRepo() { return \App\Repository\RepositoryFactory::art(); }
    /** ContentQueryTrait 配置点：转发 ContentQueryRepository::artCountByCid()——走 art_aid 索引表按 cid 计数（非主表全扫），$cid 为空值时底层短路返回 0。@param int|array $cid 单分类或分类数组（数组转 IN） @param \PdoDriver|null $d 连接选择器；NULL 用默认连接 @return int 底层已声明 : int */
    protected static function queryCountByCidImpl($cid, $d) { return \App\Repository\RepositoryFactory::contentQuery()->artCountByCid($cid, $d); }
    /** ContentQueryTrait 配置点：listIndexByCid 的缓存键前缀（后接 cid_page_pagesize，TTL 600s），Art 侧 'art_find_aid_'，口径与历史逐字节一致。@return string */
    protected static function queryListIndexCachePrefix(): string { return 'art_find_aid_'; }
    /** ContentQueryTrait 配置点：descIndexByCid 的缓存键前缀（Art 侧 'art_find_desc_'），与 list 前缀分桶，两类结果互不污染。@return string */
    /**
     * ContentQueryTrait 配置点：art 主表写入实现。
     * ⚠ 批量写致命/改错行已修：原实现一律 `art__update(intval($id), $update)`，而 ContentQueryTrait::update()
     *   明确支持数组 $id（缓存按 id 逐个失效）——数组经 intval() 恒等于 1（PHP 8.5 实测），
     *   于是一次「批量改文章」会静默把 **aid=1** 那行改掉，且不报错。
     *   现与 Video 侧对齐：数组 id 走仓储 IN 条件批量更新（applyCond 对数组值转 IN），
     *   标量 id 仍走兼容壳（保住壳内的 hook 锚点，行为与从前一致）。
     * @param int|array $id 单个 aid 或 aid 列表（两者都支持）
     * @param array     $update SET 列（列名 +/− 后缀 = 自增表达式）
     * @return int|false 影响行数；空参、空 id 列表或底层失败为 FALSE
     */
    protected static function queryUpdateImpl($id, array $update)
    {
        if (is_array($id)) {
            $ids = array_values(array_map('intval', $id));
            if (empty($ids)) return FALSE;
            return \App\Repository\RepositoryFactory::art()->update(array('aid' => $ids), $update);
        }
        return art__update(intval($id), $update);
    }
    /** ContentQueryTrait 配置点：update()/read() 的整行内容缓存键前缀（后接 intval(aid)，Art 侧 'art_'）；delete()/deleteAll() 也用同一前缀清缓存。@return string */
    protected static function queryCachePrefix(): string { return 'art_'; }
    /**
     * 显式列清单（SQL 专项审计修复 R10）
     *
     * 修复前：queryFindShell 调 art__find() 未传 col ⇒ SELECT * 拉全 10 列，含
     * `content`（longtext）。art 侧 format() 完全不消费 content（其依赖仅
     * aid/cid/name/tag/pic/views/create_date/favorites/likes），列表装配纯浪费。
     * light 参数在 art 侧无语义（queryFormatRow 忽略），故本方法不区分 light。
     */
    protected static function queryFindCols(bool $light): array
    {
        return array('aid', 'cid', 'name', 'tag', 'pic', 'views', 'favorites', 'likes', 'create_date');
    }
    /** ContentQueryTrait 配置点：主表批量查询实现，转发兼容壳 art__find()（底层 RepositoryFactory::art()->find，$key='aid' ⇒ 结果以 aid 为键）。$col 由 queryFindCols() 给出显式清单，传空会退回 SELECT *。@return array aid 为键的行集；无结果为空数组（art__find 声明 : array） */
    protected static function queryFindShell(array $cond, array $orderby, int $page, int $pagesize, array $col = array()) { return art__find($cond, $orderby, $page, $pagesize, 'aid', $col); }

    /** 轻量取列清单公开门面（SQL 专项审计；与 VideoService::findColsLight 同形） */
    public static function findColsLight(): array { return self::queryFindCols(TRUE); }
    /** 完整取列清单公开门面（art 侧 content 不参与列表，故与 light 同集） */
    /** ContentQueryTrait 配置点：行格式化桥，转发 self::format(&$row)。语义是按引用原地补展示字段（create_date_fmt/cate_name/cate_url/url/tag_fmt/tag_url 并过 safeInfo）；$light 在 Art 侧忽略。@param array $row 按引用修改 @param bool $light 未使用 @return NULL format() 无返回值，调用方不得消费返回 */
    protected static function queryFormatRow(&$row, $light = FALSE) { return self::format($row); }
    /** ContentQueryTrait 配置点：单行读取实现，转发兼容壳 art__read(array('aid' => $id))（底层 findOne，不格式化、不缓存——缓存与格式化由 trait 的 read() 负责）。@param int|string $id aid；空值在 read() 已短路 @return array|null 行数据；无记录为 NULL（art__read 声明 : ?array） */
    protected static function queryReadShell($id) { return art__read(array('aid' => $id)); }
    /** ContentQueryTrait 配置点：read() 单行缓存的分布式缓存 TTL 秒数（Art 侧 3600，Video 侧 1800）。@return int */
    protected static function queryReadTtl(): int { return 3600; }
    /** ContentQueryTrait 配置点：索引表分页取 id，转发兼容壳 art_aid__find()（底层 RepositoryFactory::artAid()->find）。实现里显式传列 array('aid')，消除索引表的 SELECT *。@param array $cond 常用 array('cid' => int|array) @param array $orderby aid => 1/-1 @param int $page @param int $pagesize @return array aid 为键的行集（仅含 aid 列）；无结果为空数组 */
    protected static function queryIndexFindShell(array $cond, array $orderby, int $page, int $pagesize) { return art_aid__find($cond, $orderby, $page, $pagesize, 'aid', array('aid')); }
    /** ContentQueryTrait 配置点：列表统一拉取装配桥，转发 self::unifiedPull(array('aidlist' => $idlist)) 后取其 'artlist' 键（回主表 + 权限过滤 + 按传入顺序重排）。$light 在 Art 侧忽略。@param array $idlist 索引表取回的 id 行集 @param bool $light 未使用 @return array|'' 内容行列表；$idlist 空时 unifiedPull 短路返回 NULL，array_value 缺省给空字符串 ''（调用方按 falsy 兜底） */
    protected static function queryPullForList($idlist, $light = TRUE) { return array_value(self::unifiedPull(array('aidlist' => $idlist)), 'artlist'); }
    /** ContentQueryTrait 配置点：findByKeyword 传给 ContentQueryRepository::searchByKeyword 的表名标识（Art 侧 'art'，Video 侧 'vod'）。@return string */
    protected static function querySearchType(): string { return 'art'; }

    /**
     * 删除文章（软删口径：先写回收站快照，再物理删主表）
     *
     * 链路：art__find 取回待删行 → snapshotArt() 落回收站（含 art_aid 索引行）→ art__delete 删主表 →
     * 同步清 art_aid 索引、tag_video 关联并对命中的 tagid 做 count- 批量递减（审计修复：此前只删主表，
     * 索引与标签留孤儿）→ 逐 aid 清 'art_<aid>' 单行缓存；单 id 分支额外清 'runtime' 计数缓存键。
     * 全程不校验权限，调用方须自行完成后台权限位 + CSRF 门禁（见 core/admin/route/art.php）。
     *
     * ⚠ 批量已修（缺陷修复轮 E）：数组 $aid 走仓储 IN 批量删（与 deleteAll 同口径），标量仍走
     *   兼容壳（保住壳内 hook 锚点）。原实现传数组会在 art__delete(int $aid) 处抛 TypeError。
     *
     * @param int|array $aid 文章 aid 或 aid 数组；空值直接返回 FALSE
     * @return int|false FALSE=入参为空或主表删除失败；成功时为 art__delete 的 rowCount（0=未命中行）
     */
    public static function delete($aid)
    {
        if (empty($aid)) return FALSE;
        // 软删：先写回收站快照再物理删除；快照失败 ⇒ 中止（审查修复轮 C1，fail-closed）
        $n = is_array($aid) ? count($aid) : 1;
        $artlist = art__find(array('aid' => $aid), array('aid' => 1), 1, $n, 'aid');
        if (false === self::snapshotArt($artlist)) return FALSE;
        // ⚠ 批量致命已修：本方法一路按 is_array($aid) 分支写（取快照、清索引、清缓存都支持数组），
        //   但 art__delete() 的签名是 int $aid —— PHP 8 不做 array→int 强转，传数组在此抛
        //   TypeError（实测），等于「批量删文章」必崩；而标量分支的语义又要保住壳内的 hook 锚点。
        //   故：数组走仓储 IN 批量删（与 deleteAll() 同一口径），标量仍走兼容壳。
        $aids = is_array($aid) ? array_values(array_map('intval', $aid)) : array(intval($aid));
        $r = $aids === array()
            ? FALSE
            : (is_array($aid)
                ? \App\Repository\RepositoryFactory::art()->delete(array('aid' => $aids))
                : art__delete($aids[0]));
        if (FALSE === $r) return FALSE;
        // 同步清理索引与标签关联（与视频侧对齐；此前只删主表，art_aid/tag_id 孤儿残留，审计修复）
        if ($aids) {
            \App\Repository\RepositoryFactory::artAid()->delete(array('aid' => $aids));
        }
        $tagarr = array();
        $tagvids = array();
        foreach ($artlist as $art) {
            if (!empty($art['tag'])) {
                $tagvids[] = $art['aid'];
                $_tagarr = xn_json_decode($art['tag']);
                foreach ((array)$_tagarr as $_tagid => $tagname) {
                    isset($tagarr[$_tagid]) ? $tagarr[$_tagid] += 1 : $tagarr[$_tagid] = 1;
                }
            }
        }
        $relRowsByTag = array();
        if (!empty($tagvids)) {
            $arlist = \App\Service\Content\TagFlowService::videoFindByVidAll($tagvids); // 分页取尽（旧 pagesize 启发式上限清理不净）
            if ($arlist) {
                $ids = array();
                foreach ($arlist as $val) {
                    $ids[] = $val['id'];
                    $_t = intval($val['tagid']);
                    isset($relRowsByTag[$_t]) or $relRowsByTag[$_t] = 0;
                    ++$relRowsByTag[$_t];
                }
                \App\Repository\RepositoryFactory::tagVideo()->delete(array('id' => $ids));
            }
        }
        // 计数回补按**实际删掉的绑定行数**（存量库同一 (tagid,vid) 可能有多行，按内容标签数恒减 1 会虚高）；
        // 走 TagFlowService::decrementTagCounts()：同 delta 合并一条 UPDATE 且会失效 web_tag_{tagid} 缓存
        // （原 bigUpdate 不清该缓存，前台标签页会读到旧计数）。查不到行时退回 $tagarr 的期望值。
        !empty($tagarr) && \App\Service\Content\TagFlowService::decrementTagCounts($relRowsByTag, $tagarr);
        foreach ($aids as $_aid) content_cache_delete('art_', $_aid);
        // ⚠ 口径统一：原实现只在「单 id」分支清 runtime 计数缓存，批量删（含后台批量回收）不清 ⇒
        //   分类/站点内容计数最长滞后一个缓存 TTL。删除必然影响计数，与 id 个数无关，故移出分支恒清。
        cache_delete('runtime'); // 计数缓存失效（键错配修复）
        return $r;
    }

    /**
     * 彻底删除文章（回收站/物理清除口径）：不做「软删留档」的差别在于取不到行即直接失败返回，
     * 且不回收单行 'runtime' 计数缓存、不依赖 art__delete 而是直接经仓库按 aid 集合删主表。
     *
     * 链路：art__find 取回待删行（取不到 ⇒ FALSE）→ snapshotArt() 落回收站快照 → 逐 aid 清 'art_<aid>'
     * 缓存 → RepositoryFactory::art()/artAid() 批量删主表与索引（数组 aid 经 applyCond 转 IN，支持真批量）→
     * 清 tag_video 关联并按 tagid 做 count- 批量递减。开头 set_time_limit(0) 解除本请求时限（批量删耗时）。
     * 同 delete()：函数本身不含权限判定，调用方负责后台权限位 + CSRF 门禁。
     *
     * @param int|array $aid 文章 aid 或 aid 数组；空值返回 FALSE
     * @return bool TRUE=删除链路执行完毕（行不存在时不代表成功，见上）；FALSE=入参为空或库中无对应行。
     *         注意返回的是布尔，不反映各步 DELETE 的 rowCount，各步失败不回滚也不报错
     */
    public static function deleteAll($aid)
    {
        if (empty($aid)) return FALSE;
        set_time_limit(0);
        $n = is_array($aid) ? count($aid) : 1;
        $artlist = art__find(array('aid' => $aid), array('aid' => 1), 1, $n, 'aid');
        if (empty($artlist)) return FALSE;
        $aids = array();
        foreach ($artlist as $art) {
            $aids[] = $art['aid'];
        }
        if ($aids) {
            // 快照失败 ⇒ 中止删除（审查修复轮 C1：不可恢复的内容不删，fail-closed）
            if (false === self::snapshotArt($artlist)) return FALSE;
            foreach ($aids as $_aid) {
                content_cache_delete('art_', $_aid);
            }
            \App\Repository\RepositoryFactory::art()->delete(array('aid' => $aids));
            \App\Repository\RepositoryFactory::artAid()->delete(array('aid' => $aids));
            // 标签计数与 tag_id 清理（与视频侧对齐，审计修复）
            $tagarr = array();
            $tagvids = array();
            foreach ($artlist as $art) {
                if (!empty($art['tag'])) {
                    $tagvids[] = $art['aid'];
                    $_tagarr = xn_json_decode($art['tag']);
                    foreach ((array)$_tagarr as $_tagid => $tagname) {
                        isset($tagarr[$_tagid]) ? $tagarr[$_tagid] += 1 : $tagarr[$_tagid] = 1;
                    }
                }
            }
            $relRowsByTag = array();
            if (!empty($tagvids)) {
                $arlist = \App\Service\Content\TagFlowService::videoFindByVidAll($tagvids); // 分页取尽（旧 pagesize 启发式上限清理不净）
                if ($arlist) {
                    $ids = array();
                    foreach ($arlist as $val) {
                        $ids[] = $val['id'];
                        $_t = intval($val['tagid']);
                        isset($relRowsByTag[$_t]) or $relRowsByTag[$_t] = 0;
                        ++$relRowsByTag[$_t];
                    }
                    \App\Repository\RepositoryFactory::tagVideo()->delete(array('id' => $ids));
                }
            }
            // 与 delete() 同口径：按实际删掉的绑定行数回补，并失效 web_tag_{tagid} 缓存
            !empty($tagarr) && \App\Service\Content\TagFlowService::decrementTagCounts($relRowsByTag, $tagarr);
            // 与 delete() 同口径：彻底删除同样影响内容计数，需清 runtime 计数缓存
            cache_delete('runtime');
        }
        return TRUE;
    }

    // 文章快照入回收站（软删前置）。
    // ⚠ 审查修复轮 C1：任一条 put 失败返回 FALSE——调用方据此**中止删除**（fail-closed，
    // 与 VideoService::snapshotVod 同口径；旧实现无视返回值照删）。
    private static function snapshotArt(array $artlist)
    {
        // P-1（2026-10-10 性能修复）：同 snapshotVod，whereIn 分块取回 + PHP 分桶去 N+1
        $bucket = array();
        $aids = array();
        foreach ($artlist as $art) $aids[] = intval($art['aid']);
        foreach (array_chunk($aids, 200) as $chunk) {
            $rows = db_table('art_aid')->whereIn('aid', $chunk)->get();
            foreach ((array)$rows as $r) $bucket[intval($r['aid'])][] = $r;
        }
        $done = array();
        foreach ($artlist as $art) {
            $aid = intval($art['aid']);
            if (false === \App\Service\Content\RecycleService::put(\App\Service\Content\RecycleService::TYPE_ART, $aid, array('row' => $art, 'aid' => $bucket[$aid] ?? array()))) {
                xn_log('snapshotArt: recycle put failed for aid=' . $aid . ', delete aborted', 'recycle_error');
                // 同 snapshotVod：回删本批已写入的半快照，避免「内容还在主表却挂回收站」的幻影条目
                if (!empty($done)) {
                    \App\Repository\RepositoryFactory::table('recycle', 'id')->delete(array('type' => \App\Service\Content\RecycleService::TYPE_ART, 'target_id' => $done));
                    xn_log('snapshotArt: rolled back ' . count($done) . ' partial snapshot(s): ' . implode(',', $done), 'recycle_error');
                }
                return false;
            }
            $done[] = $aid;
        }
        return true;
    }

    //------------------------ 展示格式化（原 model/art.php art_format 等搬入） ------------------------
    public static function format(&$art)
    {
        global $gid, $uid, $catelist;
        $conf = _SERVER('conf');
        if (empty($art)) return;
        $art['create_date_fmt'] = humandate($art['create_date']);
        $art['create_date_fmt_ymd'] = date('Y-m-d', $art['create_date']);

        $cate = array_value($catelist, $art['cid']);
        $art['cate_name'] = array_value($cate, 'name');
        $art['cate_url'] = array_value($cate, 'url');
        $route_page_art = $conf['route']['art'];
        $art['url'] = url($route_page_art . '-' . $art['aid'], '', FALSE);
        if ($conf['url_rewrite_on'] > 1) {
            !empty($cate['alias']) and $art['url'] = url(urlencode($cate['alias']) . '-' . $art['aid'], '', FALSE);
        }
        $art['tag_fmt'] = $art['tag'] ? xn_json_decode($art['tag']) : array();
        if (!is_array($art['tag_fmt'])) $art['tag_fmt'] = array();
        $art['tag_url'] = array();
        if (!empty($art['tag_fmt'])) {
            $tagarr = array();
            foreach ($art['tag_fmt'] as $_tag => $_tagname) {
                // 修复死链：原硬编码 'tag-' 不在 $route_map（该形态 404），与 TagService 同口径走 $conf['route']['tag']
                $tagurl = url(_SERVER('conf')['route']['tag'] . '-' . $_tag);
                $tagarr[] = array(
                    'name' => $_tagname,
                    'url' => $tagurl
                );
            }
            $art['tag_url'] = $tagarr;
        }
        $art = self::safeInfo($art);
    }

    /**
     * 剔除文章行里不该出到前台的敏感字段：删除 userip 与 user 关联内的计数/资产列
     * （arts/posts/credits/golds/money），并对 user 子数组再走一道 user_safe_info()（底层
     * UserService::safeInfo）剥散列/盐一类凭据列。
     *
     * 值语义：入参按值拷贝处理，不改引用，必须使用返回值；无 user 关联时原样返回（仅少了上述列）。
     * 这是入库/取出边界上的字段面收敛，不做转义——输出转义仍由模板/调用方负责（安全红线 §二.4，
     * 模板 {$var} 编译成裸 echo，未转义内容直出即成 XSS）。
     *
     * @param array $art 文章行（可带 user 关联）
     * @return array 剥敏后的行数组
     */
    public static function safeInfo($art)
    {
        unset($art['userip'], $art['user']['arts'], $art['user']['posts'], $art['user']['credits'], $art['user']['golds'], $art['user']['money']);
        empty($art['user']) || $art['user'] = user_safe_info($art['user']);
        return $art;
    }

    // 过滤安全数据
    // 注（插件系统改造）：原此处有 // hook art_filter_start/end.php 注释，为死点——
    // Service 类走 PSR-4 autoloader 直载（index.php 的 spl_autoload_register 注册的 App\ → core/app/），
    // 不经 _include() 编译管线，编译期钩子永不生效。
    // 钩子点只允许放在经 _include 编译的文件（core/model、route、core/admin/route、模板）。
    public static function filter(&$val)
    {
        unset($val['userip'], $val['cid'], $val['type'], $val['user'], $val['create_date']);
    }

    // 集合文章aid，统一拉取，避免多次查询art表
    public static function unifiedPull($arr)
    {
        global $gid, $cid;
        $stickylist = array_value($arr, 'stickylist', array());
        $aidlist = array_value($arr, 'aidlist', array());
        $aidarrlist = $aidlist = $stickylist + $aidlist;
        $aidarr = empty($aidarrlist) ? array() : arrlist_values($aidarrlist, 'aid');
        if (empty($aidarr)) return NULL;
        $aidarr = array_unique($aidarr);
        $arrlist = self::find($aidarr, count($aidarr));
        self::listAccessFilter($arrlist, $gid);
        $artlist = array();
        foreach ($arrlist as $_aid => &$_art) {
            $_art = self::safeInfo($_art);
            isset($aidlist[$_art['aid']]) and $artlist[$_aid] = $_art;
        }
        $artlist = array2_sort_key($artlist, $aidlist, 'aid');
        unset($arrlist, $aidlist);
        $arr = array('artlist' => $artlist);
        return $arr;
    }

    //------------------------ sitemap 行（SEO 优化：文章分片,与 VideoService::sitemapRows 同构） ------------------------
    public static function sitemapRows(int $page = 1, int $pagesize = 20): array
    {
        $arrlist = self::indexIds($page, $pagesize, FALSE);
        $aidarr = arrlist_values($arrlist, 'aid');
        if (empty($aidarr)) return array();

        $rows = \App\Repository\RepositoryFactory::art()->find(array('aid' => $aidarr), array('aid' => 1), 1, count($aidarr), 'aid', array('aid', 'cid'));
        if (empty($rows)) return array();

        global $catelist;
        $conf = _SERVER('conf');
        $route_page_art = is_array($conf) && isset($conf['route']['art']) ? $conf['route']['art'] : 'arts';
        foreach ($rows as &$art) {
            $cate = array_value($catelist, $art['cid']);
            $art['url'] = url($route_page_art . '-' . $art['aid'], '', FALSE);
            if (is_array($conf) && intval($conf['url_rewrite_on'] ?? 0) > 1 && !empty($cate['alias'])) {
                $art['url'] = url(urlencode($cate['alias']) . '-' . $art['aid'], '', FALSE);
            }
        }
        unset($art);

        return $rows;
    }
}
