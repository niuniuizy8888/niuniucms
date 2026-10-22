<?php
namespace App\Service\Content\Traits;

/**
 * ContentQueryTrait — Art/Video 查询族同构（主线 2 第三刀）
 *
 * 收敛 ArtService 与 VideoService（原 VideoQueryTrait 内联 + VideoService::indexPageByCid）
 * 双份近同构的索引查询 + CRUD 查询族 18 方法：
 *   indexCount / indexIds / indexIdsByUid / indexIdsByCid / listIndexByCid /
 *   descIndexByCid / countByCid / count / maxId / update / updateAll / find /
 *   findAsc / findPageByCond / listByCid / findByKeyword / read
 * 以及私有 helper indexPageByCid（半页翻转优化，原 VideoService 私有方法搬入）。
 *
 * 差异点（表/ID 列/缓存前缀/兼容壳/格式化/统一拉取装配）经 16 个抽象配置方法注入，
 * 与 ContentRankTrait 同构模式；缓存键逐字节保持（无缓存失效影响）。
 */
trait ContentQueryTrait
{
    // ======================== 配置点（使用类实现，ContentRankTrait 同款模式） ========================
    /**
     * 索引表仓库配置点（art_aid / vod_vid，列面仅 (id, cid)）。
     * 实现转发 RepositoryFactory::artAid()/vodVid()。
     * @return \App\Repository\DbTableRepository
     */
    abstract protected static function queryIndexRepo();                          // RepositoryFactory::artAid()/vodVid()
    /**
     * 内容主键列名（'aid'/'vid'），用于 cond/orderby 构造与 find 的结果键列。
     * @return string
     */
    abstract protected static function queryIdCol(): string;                      // 'aid'/'vid'
    /**
     * indexIds 未显式传列时的默认列清单。
     * 注意 Art 实现返回空数组 ⇒ 落到 DbTableRepository::find 的 $col 为空分支，
     * 走 SELECT *（索引表仅 2 列，代价小）并记 sql_star_select 留痕；Video 实现返回 array('vid')。
     * @return array
     */
    abstract protected static function queryIndexIdsDefaultCol(): array;          // indexIds 未显式传列时的默认列
    /**
     * 内容主表仓库配置点（art / vod 全列表）。
     * 实现转发 RepositoryFactory::art()/vod()。
     * @return \App\Repository\DbTableRepository
     */
    abstract protected static function queryContentRepo();                        // RepositoryFactory::art()/vod()
    /**
     * 按分类计数实现配置点，contentQuery()->artCountByCid()/videoCountByCid() 转发目标。
     * @param int|array $cid 单分类或分类数组（数组经 applyCond 展开为 IN）
     * @param \PdoDriver|null $d 连接选择器，NULL 用默认连接
     * @return int
     */
    abstract protected static function queryCountByCidImpl($cid, $d);             // contentQuery()->artCountByCid/videoCountByCid
    /**
     * listIndexByCid 的缓存键前缀（后接 cid_page_pagesize），缓存键逐字节保持历史口径。
     * @return string
     */
    abstract protected static function queryListIndexCachePrefix(): string;       // 'art_find_aid_'/'video_find_vid_'
    /**
     * descIndexByCid 的缓存键前缀（后接 cid_page_pagesize），与 list 前缀分桶互不污染。
     * @return string
     */
    abstract protected static function queryDescIndexCachePrefix(): string;       // 'art_find_desc_'/'video_find_desc_'
    /**
     * 主表写入实现配置点（Art 侧为 art__update(intval($id))，仅单 id 口径；
     * Video 侧 video__update 透传原值，数组 $id 经 applyCond 变 IN 可批量）。
     * @param int|array $id
     * @param array $update SET 列（列名 + 后缀 = 自增表达式）
     * @return int|false 影响行数（0=未命中/底层语句失败）；false 仅当底层空参短路
     */
    abstract protected static function queryUpdateImpl($id, array $update);       // art__update(intval)/video__update
    /**
     * update()/read() 内容行缓存键前缀（后接 intval(id)，Art 'art_' / Video 'vod_'）。
     * @return string
     */
    abstract protected static function queryCachePrefix(): string;                // 'art_'/'vod_'（update/read 缓存键）
    /**
     * 主表批量查询兼容壳配置点（art__find/video__find），结果按 id 列为键。
     * @param array $cond
     * @param array $orderby db_find 风格（id 列 => 1/-1）
     * @param array $col 显式列清单（queryFindCols 产出，勿传空防 SELECT *）
     * @return array id 为键的行集
     */
    abstract protected static function queryFindShell(array $cond, array $orderby, int $page, int $pagesize, array $col = array());    // art__find/video__find
    /**
     * 显式取列清单（SQL 专项审计修复 R10 / P0-2）
     * 由使用类实现：按 light 决定是否包含 play_from/play_url 等大字段，
     * 从根上消除 `$col ?: array('*')` 造成的 SELECT * 与读取放大。
     */
    abstract protected static function queryFindCols(bool $light): array;
    /**
     * 行格式化桥配置点：真正的语义是按引用改 $row（补 url/cate_name/日期等展示字段），
     * 返回值无统一契约（Art 实现无返回、Video 实现返回装配后的行数组），调用方不得消费；
     * Art 侧忽略 $light（art 列表本就不含大字段）。
     * @param array $row 按引用原地修改
     * @param bool $light 轻量口径（Video 侧 FALSE 时装配 play_data）
     * @return mixed
     */
    abstract protected static function queryFormatRow(&$row, $light = FALSE);     // 格式化桥（Art 侧忽略 light）
    /**
     * 单行读取兼容壳配置点（art__read/video__read → findOne），不带格式化。
     * @param int|string $id
     * @return array|null 行数据；无记录为 NULL（Art 声明 ?array；Video 底层 first() 同为 array|null）
     */
    abstract protected static function queryReadShell($id);                       // art__read/video__read
    /**
     * read() 分布式缓存 TTL 秒数（Art 3600 / Video 1800）。
     * @return int
     */
    abstract protected static function queryReadTtl(): int;                       // 3600/1800
    /**
     * 索引表分页取 id 配置点（art_aid__find/video_vid__find），实现均显式传列消 SELECT *。
     * @param array $cond
     * @param array $orderby id 列 => 1/-1
     * @return array id 列为键的行集（空结果 ⇒ 空数组）
     */
    abstract protected static function queryIndexFindShell(array $cond, array $orderby, int $page, int $pagesize); // art_aid__find/video_vid__find
    /**
     * 列表统一拉取装配桥（unifiedPull）：按 id 集回主表、权限过滤（listAccessFilter）、
     * 按传入 idlist 顺序重排。Art 侧忽略 $light；Video 侧 light 透传给 find 决定大字段。
     * @param array $idlist
     * @param bool $light
     * @return array|'' 装配后的行列表；idlist 为空时经 array_value 缺省返回空字符串（调用方按 falsy 处理）
     */
    abstract protected static function queryPullForList($idlist, $light = TRUE);  // unifiedPull 装配桥（Art 侧忽略 light）
    /**
     * findByKeyword 传给 ContentQueryRepository::searchByKeyword 的表名标识（'art'/'vod'）。
     * @return string
     */
    abstract protected static function querySearchType(): string;                 // 'art'/'vod'

    /**
     * 索引表（id 表）计数，cond 口径同 indexIds。
     * @param array $cond
     * @param \PdoDriver|null $d 连接选择器
     * @return int
     */
    public static function indexCount(array $cond = array(), $d = NULL)
    {
        return self::queryIndexRepo()->count($cond, $d);
    }

    /**
     * 索引表分页取行：按 $key 列（默认主键 id 列）排序取一页。
     * $key 为空回落 queryIdCol()，$col 为空回落 queryIndexIdsDefaultCol()；
     * 返回行以 $key 值为键（DbTableRepository::find 的 $key 重键语义）。
     * @param int $page 页码（1 起）
     * @param int $pagesize 每页条数
     * @param bool $desc TRUE=降序（最新优先）
     * @param string $key 排序/重键列，空=主键 id 列
     * @param array $col 显式列清单
     * @param array $cond
     * @param \PdoDriver|null $d
     * @return array $key 值为键的行集；无结果=空数组
     */
    public static function indexIds(int $page = 1, int $pagesize = 20, bool $desc = TRUE, string $key = '', array $col = array(), array $cond = array(), $d = NULL)
    {
        $orderby = $desc ? -1 : 1;
        $key = $key ?: self::queryIdCol();
        $col = $col ?: self::queryIndexIdsDefaultCol();
        return self::queryIndexRepo()->find($cond, array($key => $orderby), $page, $pagesize, $key, $col, $d);
    }

    /**
     * 「按作者 uid 取内容 ID」——**本功能在数据模型层面不存在，调用即抛异常**。
     *
     * 潜伏缺陷裁定（第十轮 SQL 审计遗留项，R20「代码必须与 schema 一致」）：
     * 原实现为 `indexIds(..., array('uid' => $uid))`，即对**索引表**下 `WHERE uid=?`：
     *   - 生产 `nncms_vod_vid` = (vid, cid)、`nncms_art_aid` = (aid, cid)，**都没有 uid 列**；
     *   - 内容主表 `nncms_vod`(40 列)/`nncms_art`(10 列) 同样**没有 uid 列**（逐列 SHOW COLUMNS 实测）；
     *   - 原版 `core/install/install.sql` 里出现的 uid 全属 `session`/`like`/`favorites`
     *     （收藏/点赞的「谁操作」），内容表与索引表从出厂起就没有「谁上传」这一维度；
     *   - 全仓亦无任何写入点（内容插入/采集/后台编辑均不写 uid）。
     * 因此这不是「列名写错」，而是**整个功能在生产 schema 里没有载体**——原先的代码一旦被调用，
     * 只会在 MySQL 侧得到 `Unknown column 'uid' in 'where clause'` 的 1054 错误（与
     * `descIndexByCid` 按不存在的 rank 排序同型的潜伏缺陷；本方法因兼容壳零调用而未暴露）。
     *
     * 处置：**不新增列**（为一个无产品定义、无写入方、无读取方的维度加列+回填属无据改动），
     * 改为在调用点**快速失败并给出可执行结论**——正常响应是返回数据，不是返回空数组，
     * 静默返回空数组会把「功能不存在」伪装成「该用户没有内容」，是更坏的失败模式。
     * 若将来上线「内容归属/用户投稿」，正确顺序是：先定产品口径 → 迁移加列并回填
     * → 写入侧接线 → 再放开本方法（届时删掉此闸并补索引 `(uid, id)`）。
     *
     * @throws \RuntimeException 恒抛出（该功能无 schema 支撑）
     */
    public static function indexIdsByUid($uid, int $page = 1, int $pagesize = 1000, bool $desc = TRUE, string $key = '', array $col = array(), $d = NULL)
    {
        throw new \RuntimeException(
            static::class . '::indexIdsByUid 不可用：内容表与索引表均无 uid 列（无「内容作者」维度），'
            . '裁定依据见本方法正上方的 docblock（第十轮 SQL 审计遗留项；该轮原始文档已在 2026-10-10 '
            . '文档整合轮删除，现存文档口径见 oldskills/README.md（与 web 根同级，已归档））。'
        );
    }

    /**
     * 按分类从索引表取一页内容 id 行（等价 indexIds + cond cid=$cid）。
     * $cid 空时直接返回空数组（不发查询）；$cid 可传数组，索引层 applyCond 转 IN。
     * @param int|array $cid
     * @param int $page
     * @param int $pagesize 默认 1000：调用方多为「整桶取回」场景
     * @param bool $desc
     * @param string $key
     * @param array $col
     * @param \PdoDriver|null $d
     * @return array $key 值为键的行集
     */
    public static function indexIdsByCid($cid, int $page = 1, int $pagesize = 1000, bool $desc = TRUE, string $key = '', array $col = array(), $d = NULL)
    {
        if (empty($cid)) return array();
        return self::indexIds($page, $pagesize, $desc, $key, $col, array('cid' => $cid), $d);
    }

    /**
     * 分类列表页索引（带缓存 + 大页码半页翻转，见私有 indexPageByCid）。
     * 取列仅 [id 列]、键为 id 列，缓存键 = queryListIndexCachePrefix()+cid_page_pagesize，TTL 600s。
     * @param int $cid
     * @param int $page
     * @param int $pagesize
     * @return array|NULL id 为键的行集；查询结果为空时返回 **NULL**（非空数组，调用方需 ?: 兜底）
     */
    public static function listIndexByCid($cid, int $page = 1, int $pagesize = 20)
    {
        return self::indexPageByCid(self::queryListIndexCachePrefix(), $cid, $page, $pagesize, function ($page, $pagesize, $desc) use ($cid) {
            return self::indexIdsByCid($cid, $page, $pagesize, $desc, self::queryIdCol(), array(self::queryIdCol()));
        });
    }

    /**
     * 「分类倒序索引」页：与 listIndexByCid 同构、独立缓存前缀分桶；
     * 排序口径与取列的修复论证见方法体内 R11/R10 注释（按主键 id 列倒序，勿改回 rank）。
     * @param int $cid
     * @param int $page
     * @param int $pagesize
     * @return array|NULL 同 listIndexByCid：空结果返回 NULL
     */
    // 半页翻转优化：大页码时从尾部倒查再反转（原 VideoService 私有方法搬入，Art 侧内联逻辑同源）
    /**
     * 分类索引分页的「半页翻转」实现：三层取数 = 请求内 static 缓存 → 分布式缓存 cache_get
     * （键 = $cachePrefix + cid_page_pagesize，TTL 600s）→ 回源 $queryFn。
     * 翻转策略只在 **$page 大于 100** 时干重活（C-12 修复：常态分页不再白付一次 COUNT）：
     *  - 取 total 算总页数；若**后半页深度超过 $limitpage(5000) 且当前页还没进尾部窗口**，
     *    把 page 钳到 5000 —— 深分页只给你第 5000 页的内容，属**刻意封顶**而非 bug（再深的页没有产品意义）；
     *  - 若 page 落在后半区，改用镜像页码 `totalpage - page + 1` **反向查**（升序取镜像页）再
     *    array_reverse 保留键翻回来 —— 用「小 offset 扫描 + PHP 反转」替代「大 offset 深扫」（技术债 #12 的缓解）。
     * 语义坑：**空结果返回 NULL 而不是空数组**（listIndexByCid / descIndexByCid 的「空= NULL」契约由此而来），
     * 且 NULL **不写缓存** ⇒ 空分类每次都会回源；缓存键只含 cid/page/pagesize，内容变化靠 TTL 600s 兜，
     * 发布新内容后前台列表页最多滞后 10 分钟（要即时生效请清缓存/升键版本，不要改这里加脏标记）。
     * @param string $cachePrefix 缓存键前缀（区分「最新索引」与「倒序索引」两条链，互不串数据）
     * @param int|string $cid 分类 ID（进缓存键，也决定回源条件）
     * @param int $page 页码（1 起；内部可能被钳制或改成镜像页）
     * @param int $pagesize 每页条数
     * @param callable $queryFn 回源函数，签名 (page, pagesize, desc) => 行集；desc 由本函数按正查/反查决定
     * @return array|null 以内容主键为键的索引行集；无数据 = NULL
     */
    private static function indexPageByCid(string $cachePrefix, $cid, int $page, int $pagesize, $queryFn)
    {
        $key = $cachePrefix . $cid . '_' . $page . '_' . $pagesize;
        static $cache = array();
        if (isset($cache[$key])) return $cache[$key];
        $rows = cache_get($key);
        if (NULL !== $rows) {
            $cache[$key] = $rows;
            return $rows;
        }

        $desc = TRUE;
        $limitpage = 5000;
        if ($page > 100) {
            // 发布前检查修复（C-12）：COUNT 移入 >100 分支——常态分页不再白付一次计数查询
            $total = self::countByCid($cid);
            $totalpage = ceil($total / $pagesize);
            $halfpage = ceil($totalpage / 2);
            if ($halfpage > $limitpage && $page < ($totalpage - $limitpage)) {
                $page = $limitpage;
            }
            if ($page > $halfpage) {
                $page = max(1, $totalpage - $page + 1);
                $rows = call_user_func($queryFn, $page, $pagesize, FALSE);
                $rows = array_reverse((array)$rows, TRUE);
                $desc = FALSE;
            }
        }
        if ($desc) {
            $rows = call_user_func($queryFn, $page, $pagesize, TRUE);
        }
        if (empty($rows)) return NULL;
        cache_set($key, $rows, 600);
        $cache[$key] = $rows;
        return $rows;
    }

    /**
     * 按分类统计行数：底层经 queryCountByCidImpl 走**索引表**计数（art_aid/vod_vid 的 cid 列），
     * $cid 空（0/空数组）短路返回 0；$cid 支持数组（IN 口径）。
     * @param int|array $cid
     * @param \PdoDriver|null $d 连接选择器
     * @return int 签名已声明 int
     */
    public static function countByCid($cid, $d = NULL): int
    {
        return self::queryCountByCidImpl($cid, $d);
    }

    /**
     * 内容主表计数（走 queryContentRepo，全表口径，非索引表）。
     * @param array $cond
     * @param \PdoDriver|null $d
     * @return int DbTableRepository::count 声明 : int
     */
    public static function count(array $cond = array(), $d = NULL)
    {
        return self::queryContentRepo()->count($cond, $d);
    }

    /**
     * 取主表某列最大值（默认 id 列），用于 sitemap/容量探测一类「有无后续行」判断。
     * @param string $col 空=回落 queryIdCol()
     * @param array $cond
     * @param \PdoDriver|null $d
     * @return int DbTableRepository::maxId 声明 : int（无行=0）
     */
    public static function maxId($col = '', array $cond = array(), $d = NULL)
    {
        return self::queryContentRepo()->maxId($col ?: self::queryIdCol(), $cond, $d);
    }

    /**
     * 更新内容行并同步失效单行缓存（键 = queryCachePrefix()+id）。
     * $id 支持数组批量（缓存逐 id 清）；两侧写库现均已按 IN 生效：Video 侧数组 id 经 applyCond 转 IN，
     * Art 侧 queryUpdateImpl 原会 intval(数组)=1 而静默改错 aid=1 的行，已同样改为数组走仓储 IN 批量更新。
     * @param int|array $id
     * @param array $update SET 列（列名 +/− 后缀 = 自增表达式）
     * @return int|false FALSE=本层/底层空参短路；否则影响行数（SQL 层失败被 db_error 吞掉、计 0）
     */
    public static function update($id, array $update)
    {
        if (empty($id) || empty($update)) return FALSE;
        $r = self::queryUpdateImpl($id, $update);
        if (FALSE === $r) return FALSE;
        if (is_array($id)) {
            foreach ($id as $_id) cache_delete(self::queryCachePrefix() . $_id);
        } else {
            cache_delete(self::queryCachePrefix() . $id);
        }
        return $r;
    }

    /**
     * update() 的别名转发（历史调用面兼容）：语义、返回、缓存失效完全同 update()，
     * 名字里的 All 指「按 id 集批量」而非「全表无条件更新」。
     * @param int|array $id
     * @param array $update
     * @return int|false 同 update()
     */
    /**
     * 按 id 集回表取一页内容行：显式列（queryFindCols 按 $light 裁剪大字段）、
     * 结果以 id 列为键，逐行补递增序号字段 $row['i'] 并经 queryFormatRow 引用格式化。
     * 注意 find() 不重排为入参顺序（顺序重排是 VideoQueryTrait::findByIdsOrdered 的职责）。
     * @param array $idarr id 集合（转 cond array(idCol => $idarr)，applyCond 数组值=IN）
     * @param int $pagesize 取回上限
     * @param bool $desc TRUE=id 降序（新→旧）
     * @param bool $light TRUE=轻量列（排除 play_url/content 等大字段）
     * @return array|NULL id 为键的格式化行集；无结果返回 **NULL**（非空数组）
     */
    public static function find($idarr, int $pagesize = 20, bool $desc = TRUE, bool $light = FALSE)
    {
        $orderby = $desc ? -1 : 1;
        // SQL 专项审计修复（R10 / P0-2）：显式传列，杜绝 SELECT *。
        // light=TRUE 时排除 play_from/play_url/content 等大字段（vod.play_url 实测均值 5.4KB）。
        $list = self::queryFindShell(array(self::queryIdCol() => $idarr), array(self::queryIdCol() => $orderby), 1, $pagesize, self::queryFindCols($light));
        if (!$list) return NULL;
        $i = 0;
        foreach ($list as &$row) {
            ++$i;
            $row['i'] = $i;
            self::queryFormatRow($row, $light);
        }
        return $list;
    }

    /**
     * find() 的升序包装（旧→新）：供 unifiedPull 一类「按 id 从小到大装配列表」链使用。
     * @param array $idarr
     * @param int $pagesize
     * @param bool $light
     * @return array|NULL 同 find()：无结果返回 NULL
     */
    public static function findAsc($idarr, int $pagesize = 20, bool $light = FALSE)
    {
        return self::find($idarr, $pagesize, FALSE, $light);
    }

    /**
     * 主表条件分页查询（后台列表/回收站一类）：$orderby 空=默认 id 降序；
     * 显式列走 queryFindCols（R10，原省略曾致 SELECT * 全 40 列）；结果以 id 列为键并格式化。
     * @param array $cond
     * @param int $page 1 起
     * @param int $pagesize
     * @param array $orderby db_find 风格列=>1/-1
     * @param bool $light 默认 TRUE：调用方需要大字段须显式传 FALSE
     * @return array|NULL id 为键的行集；无结果返回 **NULL**
     */
    public static function findPageByCond(array $cond = array(), int $page = 1, int $pagesize = 20, array $orderby = array(), bool $light = TRUE)
    {
        $orderby = $orderby ?: array(self::queryIdCol() => -1);
        // SQL 专项审计修复（R10）：补显式列（原省略 ⇒ SELECT * 全 40 列含 play_url）
        $list = self::queryContentRepo()->find($cond, $orderby, $page, $pagesize, self::queryIdCol(), self::queryFindCols($light));
        if (!$list) return NULL;
        foreach ($list as &$row) {
            self::queryFormatRow($row, $light);
        }
        unset($row);
        return $list;
    }

    /**
     * 分类内容列表（两步取数）：① 索引表按 cid 取一页 id（queryIndexFindShell，无 N+1）；
     * ② 经 queryPullForList 批量回主表装配（unifiedPull：权限过滤 + 按索引顺序重排 + 格式化）。
     * $cid 支持数组（跨分类列表，channelArtList 一类）。
     * @param int|array $cid 空 ⇒ 直接返回空数组
     * @param int $page 1 起
     * @param int $pagesize 默认 1000=「整桶取回」口径
     * @param bool $desc id 排序方向
     * @param bool $light 轻量装配（Video 侧排除大字段装配）
     * @return array|'' 内容行列表；$cid 空=空数组；正常链路无结果=空数组
     *         （unifiedPull 短路返回 NULL 时 array_value 缺省为 ''——调用方统一按 falsy 兜底即可）
     */
    public static function listByCid($cid, int $page = 1, int $pagesize = 1000, bool $desc = TRUE, bool $light = TRUE)
    {
        if (empty($cid)) return array();
        $orderby = $desc ? -1 : 1;
        $idlist = self::queryIndexFindShell(array('cid' => $cid), array(self::queryIdCol() => $orderby), $page, $pagesize);
        return self::queryPullForList($idlist, $light);
    }

    /**
     * 按名称关键词搜索内容行（上限 60 条）：走 ContentQueryRepository::searchByKeyword，
     * 前导通配 LIKE '%kw%'（开 search_use_fulltext 且 MySQL 时走 ngram FULLTEXT、自动回退），
     * 取列走 queryFindCols(TRUE) 轻量清单；命中后按 id 降序重排并逐行格式化。
     * ⚠ 关键词入 SQL 前已在仓储层做 like_escape + 控制符剥离，输出仍受模板裸 echo 红线约束。
     * @param string $keyword 空 ⇒ NULL（不发查询）
     * @param \PdoDriver|null $d
     * @return array|NULL id 为键的命中行集（无命中=空数组）；$keyword 空=NULL
     */
    public static function findByKeyword($keyword, $d = NULL)
    {
        if (empty($keyword)) return NULL;
        // SQL 专项审计修复（R10/P0）：显式传列，替代仓储层的 select(array('*'))
        $list = \App\Repository\RepositoryFactory::contentQuery()->searchByKeyword(self::querySearchType(), self::queryIdCol(), $keyword, 60, $d, self::queryFindCols(TRUE));
        if ($list) {
            $list = arrlist_multisort($list, self::queryIdCol(), FALSE);
            foreach ($list as &$row) {
                self::queryFormatRow($row);
            }
            unset($row);
        }
        return $list;
    }

    /**
     * 单行读取（两级缓存）：请求内 static memo ⇒ 分布式缓存（键 queryCachePrefix()+intval(id)，
     * TTL=queryReadTtl）⇒ miss 时 queryReadShell 回表 + format + 回填。
     * 注意缓存的是**格式化后**的行；update() 会主动清对应键。
     * @param int|string $id 空 ⇒ NULL（缓存键按 intval 归一，'12abc' 与 12 同键）
     * @return array|NULL 格式化后的行；不存在=NULL（不缓存空值，miss 每次回表）
     */
    public static function read($id)
    {
        if (empty($id)) return NULL;
        static $cache = array();
        if (isset($cache[$id])) return $cache[$id];
        $key = self::queryCachePrefix() . intval($id);
        // 站群修复：缓存的是格式化后的行（含按伪静态档位生成的 url/cate_url/tag_url），站群站点的
        // site_rewrite 与主站不同时不能共用主站的键，否则链接形态串站。主站档位键名不变；
        // 其他档位另起键并缩短 TTL（各处 cache_delete 只删主键，短 TTL 兜底内容更新的可见延迟）。
        $conf = (array)_SERVER('conf');
        $rw = intval($conf['url_rewrite_on'] ?? 0);
        $variant = isset($conf['url_rewrite_base']) && $rw !== intval($conf['url_rewrite_base']);
        $variant and $key .= '_rw' . $rw;
        $r = cache_get($key);
        if (NULL === $r) {
            $r = self::queryReadShell($id);
            if ($r) {
                self::queryFormatRow($r);
                cache_set($key, $r, $variant ? min(300, self::queryReadTtl()) : self::queryReadTtl());
            }
        }
        $cache[$id] = $r ? $r : NULL;
        return $cache[$id];
    }
}
