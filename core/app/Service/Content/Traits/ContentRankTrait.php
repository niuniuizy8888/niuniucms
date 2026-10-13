<?php
namespace App\Service\Content\Traits;

/**
 * 内容排行/热度族（主线 2 第二刀：Art/Video 同构收敛）
 *
 * incViews / recordTopView / hitRows / hitPage / clearTopHistory / rankByHit /
 * rankChannelByHit / topByCid + hitList / normalizeHitType / rankByHitBase
 * 原为 ArtService 与 VideoService（VideoRankTrait）双份近同构实现（差异仅
 * 表名/ID 列/缓存前缀/findAsc 轻量参数），收敛为单一实现 + 每类 7 个配置方法。
 *
 * 缓存键经配置保持逐字节不变（rankIpCachePrefix / rankHitCachePrefix），
 * 无缓存失效影响；Video 侧 findAsc 的 light 差异由 rankFindAsc 保留。
 */
trait ContentRankTrait
{
    /**
     * 排行明细表名（每次播放/浏览写一条流水，用于按时间窗聚合热度）：Video=vod_top，Art=art_top。
     * @return string
     */
    abstract protected static function rankTopTable(): string;
    /**
     * 排行明细表的内容主键列名：Video=vid，Art=aid。
     * @return string
     */
    abstract protected static function rankTopIdCol(): string;
    /**
     * 「同 IP 同内容 60s 去重」缓存键前缀（recordTopView 用）：Video=top_vod_ip_，Art=top_art_ip_。
     * 收敛自 Art/Video 双份实现时逐字节保持，避免存量缓存失效。
     * @return string
     */
    abstract protected static function rankIpCachePrefix(): string;
    /**
     * 时间窗热度榜结果缓存键前缀（hitList 用，TTL 300s）：Video=top_hit_cid_vod_，Art=top_hit_cid_art_。
     * @return string
     */
    abstract protected static function rankHitCachePrefix(): string;
    /**
     * 内容主表名（'hits' 全周期榜直接按主表 views 列排序时取数）：Video=vod，Art=art。
     * @return string
     */
    abstract protected static function rankContentTable(): string;
    /**
     * 内容主表 Repository（bigUpdate 批量回写 views 用）：Video=RepositoryFactory::vod()，Art=::art()。
     * @return \App\Repository\DbTableRepository
     */
    abstract protected static function rankViewsRepo();
    /**
     * 按 id 列表升序取内容条目（rankByHitBase / hitList 装配列表用）。
     * Video 侧传 light=true（省 play_url 等大字段），Art 侧不传——差异由该配置方法保留。
     * @param array $ids 主键数组
     * @param int $pagesize 取数上限
     * @return array|NULL 条目数组（无数据时 findAsc 内部返回 NULL）
     */
    abstract protected static function rankFindAsc(array $ids, int $pagesize);

    /**
     * 浏览量累加（请求级合并写）：先把增量记进 static $pending，首次调用时注册
     * register_shutdown_function，响应结束后对全部累积 id 做一次 bigUpdate（`views+` 表达式列，
     * 单条 CASE WHEN 批量 UPDATE 替代逐条写，N+1 红线口径）。不落缓存、不校验内容是否存在。
     * @param mixed $id 内容主键（内部 intval；<=0 直接跳过）
     * @param int $n 本次增量（内部 max(0, intval)，<=0 直接跳过）
     * @return void
     */
    public static function incViews($id, int $n = 1): void
    {
        $id = intval($id);
        $n = max(0, intval($n));
        if (empty($id) || empty($n)) return;
        static $pending = array();
        $pending[$id] = ($pending[$id] ?? 0) + $n;

        static $registered = false;
        if (!$registered) {
            $registered = true;
            register_shutdown_function(function () use (&$pending) {
                if (empty($pending)) return;
                $update = array();
                foreach ($pending as $_id => $cnt) {
                    $update[$_id] = array('views+' => intval($cnt));
                }
                $pending = array();
                static::rankViewsRepo()->bigUpdate(array(static::rankTopIdCol() => array_keys($update)), $update);
            });
        }
    }

    /**
     * 记一条热度流水（播放/详情页访问时调用，供 hits_day/week/month 时间窗榜聚合）：
     * 同「内容 id + 访问者 IP」60s 内去重（缓存键 = rankIpCachePrefix + id_ips），命中去重不写库。
     * @param mixed $id 内容主键（intval 后 <=0 直接拒绝）
     * @param mixed $cid 所属分类 id（仅记录，不校验）
     * @return int|false 成功=插入 ID；FALSE=id 非法或 60s 去重命中
     */
    public static function recordTopView($id, $cid)
    {
        $id = intval($id);
        $cid = intval($cid);
        if (empty($id)) return FALSE;
        $ipstr = ip();
        $ips = ip2long($ipstr);
        if ($ips === false) $ips = 0;
        $cacheKey = static::rankIpCachePrefix() . $id . '_' . $ips;
        if (cache_get($cacheKey)) return FALSE;
        cache_set($cacheKey, 1, 60);
        $time = $GLOBALS['time'] ?? time();
        return \App\Repository\RepositoryFactory::table(static::rankTopTable(), static::rankTopIdCol())->insert(array(
            static::rankTopIdCol() => $id,
            'cid' => $cid,
            'create_date' => $time,
        ));
    }

    /**
     * 时间窗热度榜取行（不分页版）：经 ContentQueryRepository::hitRankRows 在排行明细表上按
     * 窗口起点（hits_day/week/month 的起算时间）分组计数、按次数降序取前 $limit。
     * @param string $type 榜单类型（hits_day/hits_week/hits_month，短形式 d/w/m 由 normalizeHitType 归一）
     * @param int $limit 取前 N 条
     * @param mixed $cid 分类过滤：NULL/''=全站；int=单分类；array=多分类（whereIn）
     * @return array 排行明细行数组（列为 内容id + COUNT(*) AS cnt，非内容整行）；无数据返回空数组
     */
    /**
     * 时间窗热度榜的分页封装（与 hitRows 同取数路径），返回带 total 的标准分页结构。
     * @param string $type 榜单类型（d/w/m 短形式自动归一）
     * @param mixed $cid 分类过滤（NULL/''=全站；int/array=限定）
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @return array ['list'=>排行明细行数组(内容 id + cnt，非内容整行), 'total'=>int(窗口内明细总数), 'page'=>int, 'pagesize'=>int]
     */
    /**
     * 清理排行历史流水：删除主明细表与 <表名>_ip 表 create_date 早于 $days 天前的行
     * （计划任务侧调用；QueryBuilder delete 带 where，符合防全表误写红线）。
     * @param int $days 保留窗口天数（默认 30）
     * @return void
     */
    public static function clearTopHistory(int $days = 30): void
    {
        $time = $GLOBALS['time'] ?? time();
        $expire = $time - $days * 86400;
        \App\Repository\RepositoryFactory::table(static::rankTopTable(), static::rankTopIdCol())->delete(array('create_date' => array('<' => $expire)));
        \App\Repository\RepositoryFactory::table(static::rankTopTable() . '_ip', static::rankTopIdCol())->delete(array('create_date' => array('<' => $expire)));
    }

    /**
     * 全周期累计榜取 id：直接按内容主表 views 列降序分页（不走流水表聚合）。
     * @param mixed $cid 分类过滤（int/array；空值返回空数组）
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @return array 以内容主键为键的行数组（仅含主键列，来自 ContentQueryRepository::topIdsByViews）；空 cid 或无数据返回空数组
     */
    public static function topByCid($cid, int $page = 1, int $pagesize = 1000)
    {
        if (empty($cid)) return array();
        return \App\Repository\RepositoryFactory::contentQuery()->topIdsByViews(static::rankContentTable(), static::rankTopIdCol(), $cid, $page, $pagesize);
    }

    /**
     * 单分类内容榜（前台榜单入口）：转发 rankByHitBase，$countEmpty=FALSE——
     * 'hits' 全周期榜空结果时 total 直接给 0，不额外 COUNT 主表。
     * @param string $type 'hits'=按主表 views 累计榜；hits_day/week/month（或 d/w/m）=流水表时间窗榜
     * @param mixed $cid 分类（int/array）
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @return array ['list'=>装配后的内容条目数组(带 n 序号), 'total'=>int, 'page'=>int, 'pagesize'=>int]
     */
    public static function rankByHit($type, $cid, int $page = 1, int $pagesize = 20)
    {
        return self::rankByHitBase($type, $cid, $page, $pagesize, FALSE);
    }

    /**
     * 频道（多分类并集）内容榜：转发 rankByHitBase，$countEmpty=TRUE——
     * 'hits' 全周期榜空结果时仍用 countByCid 回填真实 total（频道页要显示总量）。
     * @param string $type 榜单类型（同 rankByHit）
     * @param array $cidarr 频道下多个分类 id
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @return array ['list','total','page','pagesize']（同 rankByHit 结构）
     */
    public static function rankChannelByHit($type, $cidarr, int $page = 1, int $pagesize = 20)
    {
        return self::rankByHitBase($type, $cidarr, $page, $pagesize, TRUE);
    }

    /**
     * 内容榜统一实现（rankByHit/rankChannelByHit 的底座）：
     * · $type=='hits'：走主表 views 累计（topByCid 取 id → rankFindAsc 装配 → 按 views 降序 → 补 n 序号）；
     *   空结果时 total 按 $countEmpty 决定是否再 countByCid（频道版要真实总量）。
     * · 其余 $type（hits_day/week/month 等）：转 hitList，走排行明细表时间窗聚合 + 300s 缓存。
     * @param string $type 榜单类型（'hits' 或时间窗；此处不做 d/w/m 归一，由上游决定）
     * @param mixed $cidarr 分类：单值或数组（频道传并集）
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @param bool $countEmpty 'hits' 分支空结果时是否用 countByCid 填 total（FALSE=直接置 0）
     * @return array ['list'=>条目数组, 'total'=>int, 'page'=>int, 'pagesize'=>int]
     */
    protected static function rankByHitBase($type, $cidarr, int $page, int $pagesize, bool $countEmpty = FALSE)
    {
        if ('hits' == $type) {
            $rows = self::topByCid($cidarr, $page, $pagesize);
            if (!$rows) {
                return array('list' => array(), 'total' => $countEmpty ? self::countByCid($cidarr) : 0, 'page' => $page, 'pagesize' => $pagesize);
            }
            $ids = array_column($rows, static::rankTopIdCol());
            $items = static::rankFindAsc($ids, $pagesize);
            $items = arrlist_multisort($items, 'views', false);
            $i = 0;
            foreach ($items as &$item) {
                $i++;
                $item['n'] = $i;
            }
            unset($item);
            return array('list' => $items, 'total' => self::countByCid($cidarr), 'page' => $page, 'pagesize' => $pagesize);
        }
        return self::hitList($type, $cidarr, $page, $pagesize, static::rankTopTable(), static::rankTopIdCol(), static::rankHitCachePrefix());
    }

    /**
     * 时间窗热度榜装配（带 300s 结果缓存）：hitRankIds 从排行明细表按窗口取本页 id 集 + total，
     * 再经 rankFindAsc 拉内容、按 views 降序整理。缓存键 = cachePrefix + type + cid(md5) + page + pagesize。
     * @param string $type 时间窗类型（原样拼入缓存键与查询，调用方应已归一）
     * @param mixed $cid 分类（数组则 md5 归并成缓存键片段）
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @param string $table 排行明细表名（rankTopTable）
     * @param string $idCol 明细表内容主键列（rankTopIdCol）
     * @param string $cachePrefix 缓存键前缀（rankHitCachePrefix）
     * @return array ['list'=>条目数组(无数据为空数组), 'total'=>int(时间窗内明细总数), 'page'=>int, 'pagesize'=>int]
     *         缓存命中（含负缓存）原样返回，避免每请求重算
     */
    protected static function hitList($type, $cid, int $page, int $pagesize, string $table, string $idCol, string $cachePrefix)
    {
        $cid_key = is_array($cid) ? md5(implode(',', $cid)) : (string)$cid;
        $cache_key = $cachePrefix . $type . '_' . $cid_key . '_' . $page . '_' . $pagesize;
        $r = cache_get($cache_key);
        if (NULL !== $r) return $r;

        $ranked = \App\Repository\RepositoryFactory::contentQuery()->hitRankIds($table, $idCol, $cid, $type, $page, $pagesize);
        $list = array();
        if (!empty($ranked['ids'])) {
            $ids = $ranked['ids'];
            $items = static::rankFindAsc($ids, $pagesize);
            $items = arrlist_multisort($items, 'views', false);
            $list = $items ?: array();
        }

        $r = array('list' => $list ?: array(), 'total' => intval($ranked['total']), 'page' => $page, 'pagesize' => $pagesize);
        cache_set($cache_key, $r, 300);
        return $r;
    }

    }
