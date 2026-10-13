<?php
namespace App\Repository;

class ContentQueryRepository
{
    /**
     * 热度排行候选集上限（SQL 专项审计）
     * vod_top/art_top 的「按点击排行」是分片表、随浏览增长；候选 id 一次性取回用于
     * 程序侧分组计数的前提是**有界**。20000 远超任何真实页面的候选量（当前 vod_top 1191 行），
     * 超出即截断——宁可排行丢失长尾也不让单请求在 MySQL 侧无界聚合。
     */
    const HIT_RANK_CANDIDATE_CAP = 20000;

    /**
     * 按分类统计文章数：计数走 art_aid 索引表（RepositoryFactory::artAid()->count），不回 art 主表。
     * @param int|array $cid 分类 id 或 id 数组（数组经 applyCond 展开为 IN；空数组/空值短路）
     * @param \PdoDriver|null $d 连接选择器（NULL 用默认连接）
     * @return int 计数；$cid 为空（empty 判定，含 0/'')直接返回 0，不发 SQL
     */
    public function artCountByCid($cid, $d = NULL): int
    {
        if ($this->emptyCid($cid)) return 0;
        return intval(RepositoryFactory::artAid()->count(array('cid' => $cid), $d));
    }

    /**
     * 按分类统计视频数：计数走 vod_vid 索引表（RepositoryFactory::vodVid()->count），不回 vod 主表。
     * @param int|array $cid 分类 id 或 id 数组（数组经 applyCond 展开为 IN）
     * @param \PdoDriver|null $d 连接选择器
     * @return int 计数；$cid 为空直接返回 0，不发 SQL
     */
    public function videoCountByCid($cid, $d = NULL): int
    {
        if ($this->emptyCid($cid)) return 0;
        return intval(RepositoryFactory::vodVid()->count(array('cid' => $cid), $d));
    }

    /**
     * 按 views 降序取某分类的一页内容主键（「全部排行」链路，rankByViews 消费）。
     * 走主表（vod/art）+ (cid,views) 类索引；只取 $idCol 单列，id 为键。
     * @param string $table 主表名（RepositoryFactory::table 白名单校验，非法表名得空仓库→空结果）
     * @param string $idCol 主键列名（vid/aid）
     * @param int|array $cid 分类过滤；空（含空数组）短路返回空数组，不发 SQL
     * @param int $page 页码（1 起）
     * @param int $pagesize 页宽（默认 1000，≈整桶取回）
     * @param \PdoDriver|null $d 连接选择器
     * @return array id 为键、行仅含 [$idCol] 的数组；无结果空数组
     */
    public function topIdsByViews(string $table, string $idCol, $cid, int $page = 1, int $pagesize = 1000, $d = NULL): array
    {
        if ($this->emptyCid($cid)) return array();
        $repo = RepositoryFactory::table($table, $idCol);
        $rows = $repo->find(array('cid' => $cid), array('views' => -1), $page, $pagesize, $idCol, array($idCol), $d);
        return $rows ?: array();
    }

    /**
     * 关键词搜索（取整行）
     *
     * SQL 专项审计修复（R10 / P0）：原实现硬编码 `select(array('*'))`，
     * 在 `vod` 上等于拉全 40 列（`play_url` 实测均值 5,398 字节 / 最大 171,527 字节）。
     * 现由调用方传入显式列清单（`ContentQueryTrait::findByKeyword` 传 queryFindCols），
     * 默认退化为仅主键+名称，避免被未来新调用方误用成全列查询。
     * @param array $col 显式列清单，空=仅 [$idCol,'name']
     */
    public function searchByKeyword(string $table, string $idCol, string $keyword, int $limit = 60, $d = NULL, array $col = array())
    {
        $raw = trim((string)$keyword);
        if ($raw === '') return array();
        if (empty($col)) $col = array($idCol, 'name');
        // P-4（2026-10-10）：MySQL ngram FULLTEXT 可选通道（opt-in，见 config.search_use_fulltext）。
        // 未开启/未建索引/方言不支持时 fulltextSearch() 返回 NULL ⇒ 回退 LIKE（行为与旧路径一致）。
        if ('vod' === $table) {
            $ftRows = $this->fulltextSearch($table, $idCol, $raw, $limit, $col, $d);
            if (NULL !== $ftRows) {
                if (empty($ftRows)) return array();
                $items = array();
                foreach ($ftRows as $row) {
                    if (isset($row[$idCol])) $items[$row[$idCol]] = $row;
                }
                return $items;
            }
        }
        $keyword = $this->normalizeKeyword($raw);
        if ($keyword === '') return array();

        $rows = $this->tableBuilder($table, $d)
            ->select($col)
            ->where('name', 'LIKE', '%' . $keyword . '%')
            ->orderBy($idCol, 'DESC')
            ->limit($limit)
            ->get();
        if (empty($rows)) return array();

        $items = array();
        foreach ($rows as $row) {
            if (isset($row[$idCol])) {
                $items[$row[$idCol]] = $row;
            }
        }
        return $items;
    }

    /**
     * 关键词搜索——仅回主键列表（searchByKeyword 的轻量版，前台 search 路由取 id 用）。
     * 通道优先级同 searchByKeyword：vod 表先试 ngram FULLTEXT（P-4 opt-in，不可用返回 NULL 则回退）；
     * LIKE 通道是**前导通配 `%kw%`，必然全表扫**（CLAUDE.md「开发约定·N+1 红线」已裁定为技术债），keyword 先经
     * normalizeKeyword 转义通配符（值仍走参数绑定，无注入面）。
     * @param string $table 表名（白名单校验在 tableBuilder，非法名落到空表得零结果）
     * @param string $idCol 主键列名
     * @param string $keyword 原始关键词（空/trim 后为空 ⇒ 空数组，不发 SQL）
     * @param int $limit 条数上限（默认 60）
     * @param \PdoDriver|null $d 连接选择器
     * @return array<int> 命中的主键数组（按 idCol 降序，非相关度排序）；无结果空数组
     */
    public function searchIdsByKeyword(string $table, string $idCol, string $keyword, int $limit = 60, $d = NULL): array
    {
        $raw = trim((string)$keyword);
        if ($raw === '') return array();
        // P-4：同 searchByKeyword，优先走 ngram FULLTEXT，失败回退 LIKE
        if ('vod' === $table) {
            $ftRows = $this->fulltextSearch($table, $idCol, $raw, $limit, array($idCol), $d);
            if (NULL !== $ftRows) {
                $items = array();
                foreach ($ftRows as $row) {
                    if (isset($row[$idCol])) $items[] = intval($row[$idCol]);
                }
                return $items;
            }
        }
        $keyword = $this->normalizeKeyword($raw);
        if ($keyword === '') return array();

        $rows = $this->tableBuilder($table, $d)
            ->select(array($idCol))
            ->where('name', 'LIKE', '%' . $keyword . '%')
            ->orderBy($idCol, 'DESC')
            ->limit($limit)
            ->get();
        if (empty($rows)) return array();

        $items = array();
        foreach ($rows as $row) {
            if (isset($row[$idCol])) {
                $items[] = intval($row[$idCol]);
            }
        }
        return $items;
    }

    /**
     * 时间窗热度榜——主键版（hitRankRows 的投影壳）。
     * @param string $table 明细表（vod_top/art_top，经 tableBuilder 白名单）
     * @param string $idCol 主键列（vid/aid）
     * @param int|array|null $cid 分类过滤（语义同 hitRankRows）
     * @param string $type 榜单类型（hits_day/hits_week/hits_month/其他=全周期，经 hitStartTime 换算）
     * @param int $page 页码（1 起）
     * @param int $pagesize 页宽
     * @return array ['ids' => 本页主键数组, 'total' => int 明细总行数]；无数据时 ids 为空数组、total 保留计数值
     */
    public function hitRankIds(string $table, string $idCol, $cid, string $type, int $page, int $pagesize): array
    {
        $rows = $this->hitRankRows($table, $idCol, $cid, $type, $page, $pagesize);
        if (empty($rows['list'])) {
            return array('ids' => array(), 'total' => intval($rows['total'] ?? 0));
        }

        return array(
            'ids' => array_column($rows['list'], $idCol),
            'total' => intval($rows['total'] ?? 0),
        );
    }

    /**
     * 时间窗热度榜明细：播放流水表（vod_top/art_top）按内容主键分组计 cnt，cnt 降序分页。
     * 全程 QueryBuilder 参数化（cid 走 whereIn/where，无手拼）。R05/P0-3 拆三步消临时表：
     *  ① countQb 统计窗口内明细行数 = total（注意：是流水条数，不是去重内容数）；
     *  ② candQb 走 (cid,create_date) 覆盖索引取候选主键集——排序键刻意用 create_date
     *     （按 $idCol 排序实测产生 filesort；候选集只要求和口径，方向不影响分组计数）,
     *     上限 HIT_RANK_CANDIDATE_CAP=20000，超出截断（宁丢长尾不无界聚合）;
     *  ③ listQb 对有界候选 IN + GROUP BY 计数，cnt DESC 取本页。
     * 时间基准取 $_SERVER['time']（请求上下文镜像），未装载时退 time()。
     * @param string $table 明细表名（rankTopTable 常量口径）
     * @param string $idCol 主键列名
     * @param int|array|null|string $cid 分类：NULL/'' = 全部不限；数组 = intval 过滤后 IN
     *        （过滤后为空 ⇒ 直接空结果）；标量 = intval 等值（⚠ cid=0 会被当等值查询而非「全部」）
     * @param string $type 榜单类型（hitStartTime 换算窗口起点）
     * @param int $page 页码（内部 max(1,..) 钳制）
     * @param int $pagesize 页宽（内部 max(1,..) 钳制）
     * @return array ['list' => 行数组(仅 $idCol + cnt 两列；候选集空/无命中时为空数组), 'total' => int]
     */
    public function hitRankRows(string $table, string $idCol, $cid, string $type, int $page, int $pagesize): array
    {
        $time = $_SERVER['time'] ?? time();
        $start = $this->hitStartTime($type, intval($time));
        $page = max(1, intval($page));
        $pagesize = max(1, intval($pagesize));
        $start_row = ($page - 1) * $pagesize;

        // 构建 cid 条件（QueryBuilder 参数化路径，替代原手拼 "AND cid IN (...)"）
        $cidBuilder = function (\QueryBuilder $qb) use ($cid) {
            if ($cid === NULL || $cid === '') return TRUE;
            if (is_array($cid)) {
                $cid = array_filter(array_map('intval', $cid));
                if (empty($cid)) return FALSE;
                $qb->whereIn('cid', $cid);
                return TRUE;
            }
            $qb->where('cid', intval($cid));
            return TRUE;
        };

        $countQb = $this->tableBuilder($table, NULL);
        if (!$cidBuilder($countQb)) {
            return array('list' => array(), 'total' => 0);
        }
        $countQb->where('create_date', '>=', $start);
        $total = $countQb->count();

        // SQL 专项审计修复（R05/P0-3）：拆两步，消除 `GROUP BY + COUNT(*) + ORDER BY 聚合别名`
        // 产生的 Using temporary; Using filesort（原为单条聚合查询，EXPLAIN 实测见审计报告 §8.4 ③）。
        // ① 先按覆盖索引取「命中的候选 id 集合」——ORDER BY $idCol DESC 走 (cid,create_date,$idCol)
        //    覆盖索引，只回表 id，无聚合无排序临时表；
        // ② 再对**有界**候选集做分组计数并取前 N 页（IN 走主键，候选集上限 20000）。
        // 语义等价性：原查询按 $idCol 分组计数、按 cnt DESC 排序后 LIMIT 分页；
        // 现先取全部候选 id（同一 WHERE），分组计数结果与排序口径完全一致。
        $candQb = $this->tableBuilder($table, NULL);
        if (!$cidBuilder($candQb)) {
            return array('list' => array(), 'total' => 0);
        }
        $candQb->where('create_date', '>=', $start);
        // 排序键刻意选 create_date 而非 $idCol：vod_top 现有索引为 (cid, create_date) 与 (cid, vid)
        // 之外的单列索引，按 create_date 排序可让本查询走 (cid,create_date) 覆盖索引（EXPLAIN: Using index，
        // 无 filesort）；按 $idCol 排序则实测额外产生 Using filesort。候选集语义是"时间窗内命中的
        // 全部条目"，排序方向不影响后续分组计数结果，故此处以索引友好为优先。
        $candRows = $candQb
            ->select(array($idCol))
            ->orderBy('create_date', 'DESC')
            ->limit(self::HIT_RANK_CANDIDATE_CAP)
            ->get();
        $candIds = array();
        foreach ((array) $candRows as $_r) {
            $__id = intval($_r[$idCol] ?? 0);
            if ($__id > 0) $candIds[$__id] = TRUE;
        }
        if (empty($candIds)) {
            return array('list' => array(), 'total' => $total);
        }

        $listQb = $this->tableBuilder($table, NULL);
        $listQb->whereIn($idCol, array_keys($candIds));
        $rows = $listQb
            ->select(array($idCol, 'COUNT(*) AS cnt'))
            ->groupBy($idCol)
            ->orderBy('cnt', 'DESC')
            ->limit($pagesize, $start_row)
            ->get();

        return array(
            'list' => $rows ?: array(),
            'total' => $total,
        );
    }

    /**
     * LIKE 关键词归一（仅影响模式语义，不承担注入防护——值始终参数绑定）：
     * trim → 剥控制符（\0\r\n\t）→ like_escape。VULN-L01 修复：先转义反斜杠本身再转义 % 与 _，
     * 保证用户输入的 %/_/\% 按字面匹配，不漂移成通配符（扩展名闸等 LIKE 场景共用同一实现）。
     * @param mixed $keyword 关键词（先强转 string）
     * @return string 转义后的字面模式主体；trim 后为空返回 ''
     */
    protected function normalizeKeyword($keyword): string
    {
        $keyword = trim((string)$keyword);
        if ($keyword === '') return '';
        $keyword = str_replace(array("\0", "\r", "\n", "\t"), '', $keyword);
        // 修复（VULN-L01）：先转义转义符本身，再转义通配符（原实现只做后者）
        return function_exists('like_escape') ? like_escape($keyword) : str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $keyword);
    }

    /**
     * 榜单时间窗起点（unix 秒），配合 create_date >= start 过滤播放/浏览明细流水。
     * @param string $type 'hits_day'=服务器本地今天 0 点（strtotime('today')）；
     *        'hits_week'=time-7*86400、'hits_month'=time-30*86400（固定秒数窗口，非自然周/月）；
     *        其余（含 'hits' 全周期榜）=0，即不加时间条件
     * @param int $time 当前时间基准（调用方传 $_SERVER['time'] 或 time()）
     * @return int 窗口起点
     */
    protected function hitStartTime(string $type, int $time): int
    {
        if ('hits_day' == $type) return strtotime('today');
        if ('hits_week' == $type) return $time - 7 * 86400;
        if ('hits_month' == $type) return $time - 30 * 86400;
        return 0;
    }

    /**
     * 「分类条件为空」统一判定：数组/标量都走 empty()（两分支实现相同，is_array 分叉无实际差异）。
     * 注意 empty 口径：cid=0、'0'、null、''、空数组均判空 ⇒ 统计/取数短路返回 0/空集。
     * @param mixed $cid 分类条件（int|array|其他标量）
     * @return bool 空条件 TRUE
     */
    protected function emptyCid($cid): bool
    {
        return is_array($cid) ? empty($cid) : empty($cid);
    }

    /**
     * 为本类查询构造带前缀的 QueryBuilder：$d 省略时取 $_SERVER['db']（容器镜像，连接句柄）。
     * 表名走 [a-z_][a-z0-9_]* 白名单（与 RepositoryFactory::table 同口径的纵深防御）：
     * 非法表名**不抛错**，而是构造只带前缀的空表名 builder，让后续 SQL 自然命中零结果——
     * ⚠ 两条分支都解引用 $db->tablepre，$db 未装载（如 CLI 未引导）时此处会致命错误，调用方须先完成引导。
     * @param string $table 不含前缀的表名
     * @param \PdoDriver|null $d 连接选择器
     * @return \QueryBuilder
     */
    private function tableBuilder(string $table, $d): \QueryBuilder
    {
        $db = $d ?: ($_SERVER['db'] ?? NULL);
        // 标识符白名单（纵深防御）：与 RepositoryFactory::table 同口径
        if (!preg_match('#^[a-z_][a-z0-9_]*$#', $table)) {
            return new \QueryBuilder($db, $db->tablepre . '');
        }
        return new \QueryBuilder($db, $db->tablepre . $table);
    }


    /**
     * P-4（2026-10-10）：MySQL ngram 全文搜索通道（opt-in，config.search_use_fulltext=1）。
     *
     * 返回 NULL = 通道不可用/失败（开关关闭、未建 ft_name 索引、MariaDB 无 ngram、
     * 表缺失、驱动异常等），调用方一律回退 LIKE——失败语义与旧路径完全一致。
     * 词长 <2 字符（ngram 最小 token=2）也返回 NULL 走 LIKE。
     * 列/表/主键均为调用方内部常量 + 正则白名单，关键字走 ? 绑定，无注入面。
     */
    private function fulltextSearch(string $table, string $idCol, string $keyword, int $limit, array $col, $d = NULL)
    {
        $conf = (array)_SERVER('conf');
        if (empty($conf['search_use_fulltext'])) return NULL;
        if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $table) || !preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $idCol)) return NULL;
        foreach ($col as $c) {
            if (!is_string($c) || !preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $c)) return NULL;
        }
        $db = $d ?: ($_SERVER['db'] ?? NULL);
        if (!$db || !method_exists($db, 'prepare_execute')) return NULL;
        $len = function_exists('mb_strlen') ? mb_strlen($keyword, 'UTF-8') : strlen($keyword);
        if ($len < 2) return NULL;
        $sql = 'SELECT ' . implode(', ', array_map(function ($c) { return '`' . $c . '`'; }, $col))
            . ' FROM `' . $db->tablepre . $table . '` WHERE MATCH(`name`) AGAINST (? IN NATURAL LANGUAGE MODE)'
            . ' ORDER BY `' . $idCol . '` DESC LIMIT ' . max(1, intval($limit));
        try {
            $st = $db->prepare_execute($sql, array($keyword));
            if (!$st) return NULL;
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : NULL;
        } catch (\Throwable $e) {
            return NULL;
        }
    }
}
