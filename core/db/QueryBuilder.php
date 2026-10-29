<?php
/**
 * 链式查询构造器 — 支持 JOIN / GROUP BY / HAVING / 子查询 / 事务
 */
class QueryBuilder
{
    /** @var PdoDriver */
    private $db;
    /** @var string */
    private $table = '';
    /** @var array */
    private $columns = ['*'];
    /** @var array */
    private $wheres = [];
    /** @var array */
    private $joins = [];
    /** @var array */
    private $orderBy = [];
    /** @var array */
    private $groupBy = [];
    /** @var string|null */
    private $having = null;
    /** @var int|null */
    private $limit = null;
    /** @var int|null */
    private $offset = null;
    /** @var array */
    private $params = [];
    /** @var string */
    private $type = 'mysql';
    /** @var int */
    private $retryCount = 0;
    /** @var bool 悲观行锁（SELECT ... FOR UPDATE；R04 配套） */
    private $forUpdate = FALSE;

    private const OPERATORS = ['=', '>', '<', '>=', '<=', '!=', '<>', 'LIKE', 'NOT LIKE', 'NOT IN'];

    /**
     * 标识符引用（任务 3 方言层）：mysql=反引号（含转义）；pgsql/sqlite=双引号。
     * mysql 输出与历史内联反引号逐字节一致（方言回归的金样基线）。
     */
    /** @var array 测试钩子：表主键覆盖（键 type:table；供离线/方言测试注入，生产恒走 db->primaryKeys 内省） */
    public static $pkOverride = array();

    /**
     * 标识符引用（方言层）：MySQL 族（mysql/pdo_mysql 及未知驱动默认）用反引号（内嵌 ` 转义为 ``），
     * 仅 pgsql/postgres/sqlite 用双引号（内嵌 " 转义为 ""）。mysql 输出与历史内联反引号逐字节一致。
     * ⚠ 这是 §二.3「标识符白名单」的落地点：所有拼入 SQL 的表名/列名都经本方法引用，
     *   而调用方（select/where/join/orderBy/groupBy/having）在引用前先做正则白名单校验。
     * @param string $name 已通过列名白名单校验的标识符
     * @return string 引用后的标识符（带方言引号）
     */
    private function qi(string $name): string
    {
        // 修正：MySQL 族含 pdo_mysql（生产 config db.type='pdo_mysql'，此前落双引号分支
        // → MySQL 无 ANSI_QUOTES 时全部引用名 SQL 1064 静默失败：kv/会话/登录态写入全断）。
        // 仅 PG/SQLite 族用双引号，其余（mysql/pdo_mysql 及未知驱动默认）按 MySQL 反引号。
        $t = (string)$this->type;
        if ('pgsql' === $t || 'postgres' === $t || 'sqlite' === $t) {
            return '"' . str_replace('"', '""', $name) . '"';
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** 表名引用（同 qi 语义） */
    private function qTable(string $name): string
    {
        return $this->qi($name);
    }
    private const JOIN_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', '<>', 'LIKE', 'NOT LIKE'];

    public function __construct(PdoDriver $db, string $table)
    {
        $this->db = $db;
        $this->table = $table;
        $this->type = $db->type;
    }

    /**
     * 悲观行锁：本查询改为 `SELECT ... FOR UPDATE`（SQL 专项审计 R04 配套）
     *
     * 用途：R04 要求唯一性在程序层实现（查询 + 事务/锁）而非依赖 UNIQUE 索引。
     * 程序层幂等的正确姿势是「事务内先锁后查、无则插入」，锁必须让 DB 串行化同键并发，
     * 否则两个并发请求会同时查空并各插一行（唯一性反而丢失）。
     *
     * 方言：MySQL/PG 支持 FOR UPDATE；**SQLite 不支持该语法**（单写者模型本身已串行化写，
     * 不需要行锁），故 sqlite/pgsql 各自按能力处理——sqlite 直接忽略，不生成非法 SQL
     * （回归用例以 sqlite 内存库运行，必须保持可用）。
     * 约束：行锁仅在事务内有效；调用方必须包在 QueryBuilder::transaction() 内，
     * 且 SELECT 需命中索引，否则会退化为锁全表。
     */
    public function lock(): self
    {
        $this->forUpdate = TRUE;
        return $this;
    }

    /**
     * SELECT 列表达式引用（§二.3 标识符白名单在 SELECT 列的落地点，与 where/orderBy 的 qi() 同口径）。
     *  · 纯列名（`cid`/`rank`/`display`）→ 加方言引号。**必须引用**：MySQL 8.0 起 `rank` 是保留字
     *    （窗口函数 RANK()），裸写 `SELECT ...,rank,display,...` 会被解析成函数名而直接 1064
     *    （实测 8.0.45 报 near ',display,...'，且被 prepare_execute 吞成「空结果集」——
     *    症状是栏目列表恒为空、前台导航空白）；`link.rank` 同雷，故 `tbl.col` 两段也各自引用。
     *  · `tbl.col` 点号形态 → 两段各自引用。
     *  · 其余表达式形态（`COUNT(*) AS n`、`GROUP_CONCAT(vid) as vids`、`*`）→ 原样直出，
     *    安全边界是 select() 的字面量白名单（禁引号/反引号/分号/注释）。
     * @param string $col 已经过 select() 白名单校验的列/表达式
     * @return string 可直接拼入 SELECT 的片段
     */
    private function qiColumn(string $col): string
    {
        if (preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $col)) return $this->qi($col);
        if (preg_match('#^([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)$#', $col, $m)) {
            return $this->qi($m[1]) . '.' . $this->qi($m[2]);
        }
        return $col;
    }

    /**
     * 设置 SELECT 列清单（覆盖默认的 ['*']；DbTableRepository 会记 sql_star_select 日志劝退 *）。
     * 宽松标识符校验：仅允许字母数字下划线星号括号逗号点空格，且再拒 -- /\* # ; 注释与分号——
     * 因此函数形态列（COUNT(*) AS total）合法、任何引号/反引号注入被拒（InvalidArgumentException）。
     * 拼入 SQL 前由 qiColumn() 分流：纯列名/`tbl.col` 加方言引号（保留字列必需，见 qiColumn 注释），
     * 表达式形态原样直出——安全边界仍是本校验。
     * @param array $columns 列表达式字符串数组
     * @return $this 链式
     * @throws \InvalidArgumentException 非法列表达式
     */
    public function select(array $columns = ['*'])
    {
        // 宽松安全校验：允许函数/别名（COUNT(*) AS total），拒绝反引号/分号/引号/注释等危险字符
        foreach ($columns as $col) {
            if (!is_string($col) || !preg_match('#^[a-zA-Z0-9_*(),. ]+$#', $col) || preg_match('~(--|/\*|#|;)~', $col)) {
                throw new \InvalidArgumentException("Invalid select column: " . (is_string($col) ? $col : gettype($col)));
            }
        }
        $this->columns = $columns;
        return $this;
    }

    /**
     * 追加一个 WHERE 条件（多条件之间 AND）。值一律 ? 占位符绑定，不拼进 SQL。
     * 双白名单口径（§二.3）：列名必须匹配 ^[a-zA-Z_][a-zA-Z0-9_]*$，违规**抛异常**（fail-fast）；
     * 操作符不在 OPERATORS 白名单时**静默降级为 '='**（fail-safe，不抛）。
     * @param string $column 列名（严格标识符，不接受 tbl.col）
     * @param string $operator 三参形态为比较操作符；**两参形态 where(col, value) 时本参数即值**，操作符取 '='
     * @param mixed $value 绑定值；传 null 走两参形态语义
     * @return $this 链式
     * @throws \InvalidArgumentException 列名非法
     */
    public function where(string $column, string $operator, $value = null)
    {
        // 列名白名单（防 SQL 注入，与 orderBy/having 口径一致）
        if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $column)) {
            throw new \InvalidArgumentException("Invalid where column: " . $column);
        }
        if ($value === null) { $value = $operator; $operator = '='; }
        $op = in_array(strtoupper($operator), self::OPERATORS, true) ? strtoupper($operator) : '=';
        $this->wheres[] = [$column, $op, $value];
        return $this;
    }

    /**
     * 追加 WHERE col IN (...) 条件（批量取数入口，§三.2 要求配合 array_chunk ≤200/块使用）。
     * 列名同 where 严格校验（违规抛异常）；占位符由 count($values) 生成、值走绑定，
     * RAW 模板直拼的部分只有已校验的列名与 ? 数量。
     * @param string $column 列名
     * @param array $values 值列表（非空）。**空数组降级为恒假条件 1=0**（查空而不是漏掉条件放大结果集）
     * @return $this 链式
     * @throws \InvalidArgumentException 列名非法
     */
    public function whereIn(string $column, array $values)
    {
        // 列名白名单（RAW 模板直拼反引号，必须校验）
        if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $column)) {
            throw new \InvalidArgumentException("Invalid whereIn column: " . $column);
        }
        if (empty($values)) {
            $this->wheres[] = [$column, 'IN', [], 'RAW' => '1=0'];
            return $this;
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = [$column, 'IN', $values, 'RAW' => $this->qi($column) . " IN ($placeholders)"];
        return $this;
    }

    /**
     * 批量应用 db_find 风格条件数组（兼容 db_cond_to_sqladd 语义）：
     * 等值 / NULL / IN 数组 / 关联操作符数组（如 array('>' => 100, 'LIKE' => 'x')）
     */
    public function applyCond(array $cond): self
    {
        foreach ($cond as $k => $v) {
            if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $k)) continue;
            if (!is_array($v)) {
                if ($v === null) {
                    $this->wheres[] = [$k, 'IS NULL', null, 'RAW' => $this->qi($k) . ' IS NULL'];
                } else {
                    $this->where($k, '=', $v);
                }
            } elseif (self::isListCond($v)) {
                // 数值键数组 = IN 值列表（含空数组 → whereIn 自带 1=0）。
                // ⚠ 判据修复（审查）：旧实现 `isset($v[0])` 对空数组 / 非 0 起始键（如 [2=>5]）
                //   为假 ⇒ 落到下面的操作符分支做**零次迭代**（空数组条件被静默丢弃，
                //   find(['x'=>[]]) 退化全表查询）或把数值键当操作符生成错误等值条件；
                //   而 isset 为真时 empty 恒假，原 1=0 分支是死代码。
                $this->whereIn($k, array_values($v));
            } else {
                foreach ($v as $k1 => $v1) {
                    $k1 = trim($k1);
                    if (!in_array($k1, array('<', '>', '<=', '>=', '!=', '<>', 'LIKE', 'NOT LIKE'), true)) $k1 = '=';
                    if (str_contains($k1, 'LIKE')) {
                        // 修复（VULN-L01）：先转义转义符本身（原实现只转义通配符）
                        $v1 = function_exists('like_escape') ? like_escape($v1) : str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $v1);
                        $v1 = "%$v1%";
                    }
                    $this->where($k, $k1, $v1);
                }
            }
        }
        return $this;
    }

    /**
     * 条件数组的「IN 值列表」判定：全部键为整数 ⇒ 列表语义（空数组也成立）；
     * 含字符串键 ⇒ 操作符映射语义（'<' / 'LIKE' / ...）。供 applyCond 分支使用
     * （PHP 8.0 兼容：不用 8.1 的 array_is_list）。
     * @param array $v 待判定条件值
     * @return bool
     */
    private static function isListCond(array $v): bool
    {
        foreach ($v as $k => $_) {
            if (!is_int($k)) return false;
        }
        return true;
    }

    /**
     * 批量应用 db_find 风格排序数组（array('vid' => -1) 降序 / 1 升序）
     */
    public function applyOrderBy(array $orderby): self
    {
        foreach ($orderby as $k => $v) {
            if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $k)) continue;
            $this->orderBy($k, (1 == $v) ? 'ASC' : 'DESC');
        }
        return $this;
    }

    /**
     * 批量插入（多行 VALUES，全参数化），返回影响行数。
     * @param array $rows 行列表（列集合取自首行，缺失列补 NULL）
     * @param bool $ignore TRUE ⇒ 生成 `INSERT IGNORE INTO`：撞唯一键的行**单独跳过**而不是整条语句失败。
     *        给"目标表有唯一键、且重复属可容忍的正常情况"的写入口用（如 tag_id 绑定表——
     *        没有它时一次并发保存会让多值 INSERT 因 1062 整批失败，同批其它标签一起丢，
     *        而 prepare_execute 只把错误吞成 rowCount=0，调用方看不到任何异常）。
     *        ⚠ 代价：IGNORE 同时压制其它错误（降级为 warning），所以只对**列简单、值已归一**的关系表用；
     *        业务主表写入口不要顺手加这个参数。
     * @return int 实际插入行数（被跳过的不计入）
     */
    public function bulkInsert(array $rows, bool $ignore = false): int
    {
        if (empty($rows)) return 0;
        $cols = array_keys($this->safeColumns((array) reset($rows)));
        if (empty($cols)) return 0;
        $params = array();
        $valueGroups = array();
        foreach ($rows as $row) {
            $valueGroups[] = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            foreach ($cols as $c) {
                $params[] = array_key_exists($c, $row) ? $row[$c] : null;
            }
        }
        // ⚠ 方言分支（审查修复）：INSERT IGNORE 是 MySQL 专有——sqlite 用 INSERT OR IGNORE、
        //   pgsql 用 ON CONFLICT DO NOTHING 后缀，否则 $ignore=TRUE 会在另两个方言下生成非法
        //   SQL 被 prepare_execute 吞成 rowCount=0（静默丢整批）。当前 $ignore 仅 tag_id 使用
        //   （MySQL 站），此分支属防御性完备。
        $prefix = 'INSERT INTO ';
        $suffix = '';
        if ($ignore) {
            if ($this->type === 'sqlite') {
                $prefix = 'INSERT OR IGNORE INTO ';
            } elseif (in_array($this->type, array('pgsql', 'postgres'), true)) {
                $suffix = ' ON CONFLICT DO NOTHING';
            } else {
                $prefix = 'INSERT IGNORE INTO ';
            }
        }
        $sql = $prefix . $this->qTable($this->table) . " (" . implode(',', array_map(array($this, 'qi'), $cols)) . ") VALUES " . implode(',', $valueGroups) . $suffix;
        $stmt = $this->db->prepare_execute($sql, $params);
        return $stmt ? $stmt->rowCount() : 0;
    }

    /**
     * 按主键批量更新（CASE WHEN，全参数化）。
     * ⚠ 参数顺序（审查修复轮 C12 实测修复）：SQL 是**按字段分组**拼的（每列一个 CASE，含该列在
     * 全部主键上的 WHEN），旧实现却把绑定值按**行主序**（外层主键、内层字段）append ⇒ 只要
     * 一条语句里出现 ≥2 个字段，占位符与值就整体错位：实测 `array(1=>array('views+'=>5,'name'=>'x'),
     * 2=>array('views-'=>3))` 把 name 写成 '3'、views 写成 NULL（数据损坏，非报错）。
     * 现按字段分别收集再按字段顺序展平，与 SQL 结构一一对应；单字段路径与旧行为完全一致。
     * @param string $condKey 主键列名
     * @param array $updates array(主键值 => array(字段 => 值, 'views+' => 1, ...))；
     *                       **主键值必须是整数字面量或纯数字串**（复核修正）——非数字键旧实现会被
     *                       `intval()` 静默变成 0，若表里恰有 id=0 的行就会被误写（真机 SQLite 实测）。
     *                       现改为**跳过非数字键**（fail-closed：宁可不写，不误写）。
     */
    public function bulkUpdate(string $condKey, array $updates): int
    {
        if (empty($updates) || !preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $condKey)) return 0;
        // 主键归一（复核修正）：只接受纯数字键；非数字键整条跳过（含其字段值），避免 intval 后撞 id=0
        $cleanUpdates = array();
        foreach ($updates as $_k => $_v) {
            if (is_int($_k) || (is_string($_k) && ctype_digit($_k))) {
                $cleanUpdates[intval($_k)] = $_v;
            } else {
                xn_log('bulkUpdate: non-numeric primary key skipped: ' . var_export($_k, true), 'db_error');
            }
        }
        $updates = $cleanUpdates;
        if (empty($updates)) return 0;
        $fields = array();        // real => CASE 片段（按首次出现顺序）
        $fieldParams = array();   // real => 该字段自己的 (WHEN 值, THEN 值) 参数序列
        foreach ($updates as $_cond => $_arr) {
            if (!is_array($_arr)) continue;
            foreach ($_arr as $field => $val) {
                $op = substr($field, -1);
                $real = ($op == '+' || $op == '-') ? substr($field, 0, -1) : $field;
                // 先剥离 +/- 后缀再做列名校验（原 db_big_update 语义）
                if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $real)) continue;
                $then = ($op == '+') ? $this->qi($real) . " + ? " : (($op == '-') ? $this->qi($real) . " - ? " : "? "); // '-' 后缀此前误用加法（审计修复）
                $fields[$real] = isset($fields[$real]) ? $fields[$real] : $this->qi($real) . " = CASE " . $this->qi($condKey) . " ";
                $fields[$real] .= "WHEN ? THEN $then";
                $fieldParams[$real][] = intval($_cond);
                $fieldParams[$real][] = $val;   // +/-/直接赋值三路参数绑定完全相同（C10 死分支已合并）
            }
        }
        if (empty($fields)) return 0;
        $sql = "UPDATE " . $this->qTable($this->table) . " SET ";
        $sets = array();
        $params = array();
        foreach ($fields as $real => $caseSql) {
            // ⚠ 必须带 ELSE 保原值（复核修正）：CASE 无 ELSE 时，**未在该字段 WHEN 列表里的主键行
            //   会落到 NULL**。混字段批量更新（如 id=1 只改 views、id=2 只改 name）会把另一侧的
            //   列整列写成 NULL（实测复现）。加 `ELSE <本列>` 后：该行没被显式赋值 ⇒ 保持原值，
            //   语义与「只更新给定的 列/行 组合」一致；WHERE IN 仍限定更新范围。
            $sets[] = $caseSql . ' ELSE ' . $this->qi($real) . ' END';
            foreach ($fieldParams[$real] as $p) $params[] = $p;   // 与 SET 的字段分组顺序严格对齐
        }
        $sql .= implode(', ', $sets);
        // 原 db_big_update 语义：以 cond 主键值列表限定更新范围，防止无匹配行被 CASE 置 NULL
        $ids = array_map('intval', array_keys($updates));
        $sql .= " WHERE " . $this->qi($condKey) . " IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $params = array_merge($params, $ids);
        $stmt = $this->db->prepare_execute($sql, $params);
        return $stmt ? $stmt->rowCount() : 0;
    }

    /**
     * 追加 JOIN 子句（列名直拼反引号，故三重校验全部 **fail-fast 抛异常**，比 where 的
     * 操作符静默降级更严）：表名/两个列名走标识符白名单，操作符须属 JOIN_OPERATORS，
     * 连接类型须属 INNER/LEFT/RIGHT/FULL/CROSS。
     * @param string $table 被连接表名
     * @param string $first 本侧列名
     * @param string $operator 比较操作符（JOIN_OPERATORS 白名单内）
     * @param string $second 对侧列名
     * @param string $type 连接类型（大小写不敏感）
     * @return $this 链式
     * @throws \InvalidArgumentException 任一校验不过
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER')
    {
        // 表名/列名白名单（全部直拼反引号，必须校验）
        if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $table) || !preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $first) || !preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $second)) {
            throw new \InvalidArgumentException("Invalid join table/column");
        }
        $op = strtoupper($operator);
        if (!in_array($op, self::JOIN_OPERATORS, true)) {
            throw new \InvalidArgumentException("Invalid join operator: $operator");
        }
        $typeUpper = strtoupper($type);
        if (!in_array($typeUpper, ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'], true)) {
            throw new \InvalidArgumentException("Invalid join type: $type");
        }
        $this->joins[] = $typeUpper . " JOIN " . $this->qTable($table) . " ON " . $this->qi($first) . " $op " . $this->qi($second);
        return $this;
    }

    /**
     * join() 的 LEFT 连接快捷方式（其余参数语义/校验同 join）。
     * @return $this 链式
     */
    /**
     * 追加 ORDER BY 段（多段按调用顺序叠加）。列名走标识符白名单，**fail-fast 抛异常**（§二.3
     * 标识符不入库只入 ORDER BY 直拼位，故与 applyOrderBy 同口径校验）；方向仅认 DESC，
     * 其余（含非法值）一律归 ASC，不做注入风险。
     * @param string $column 列名
     * @param string $direction 'ASC'/'DESC'（大小写不敏感）
     * @return $this 链式
     * @throws \InvalidArgumentException 列名非法
     */
    public function orderBy(string $column, string $direction = 'ASC')
    {
        // 列名白名单（与 applyOrderBy 单段口径一致）：防任意标识符拼入 ORDER BY
        if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $column)) {
            throw new \InvalidArgumentException("Invalid orderBy column: $column");
        }
        $this->orderBy[] = $this->qi($column) . " " . (strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
        return $this;
    }

    /**
     * 设置 GROUP BY 列（变参）。⚠ 每次调用**整体覆盖**既有分组，不是叠加。
     * 列名白名单比 where 略宽：允许 tbl.col 点号形态；违规列 fail-fast 抛异常。
     * 拼入 SQL 前经 qiColumn() 加方言引号（本方法不接受函数/表达式形态，故恒为引用形态——
     * 保留字列如 `rank` 在 GROUP BY 裸写同样 1064，与 SELECT 列同一处理）。
     * @param string ...$columns 列名列表
     * @return $this 链式
     * @throws \InvalidArgumentException 任一列非法
     */
    public function groupBy(string ...$columns)
    {
        foreach ($columns as $col) {
            if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_.]*$#', $col)) {
                throw new \InvalidArgumentException("Invalid groupBy column: $col");
            }
        }
        $this->groupBy = $columns;
        return $this;
    }

    /**
     * 设置 HAVING 条件（仅支持**单个**条件，重复调用覆盖；与 wheres 的多条 AND 语义不同）。
     * 双白名单口径同 where：列名违规抛异常，操作符不在 OPERATORS 白名单静默降级 '='；值走 ? 绑定。
     * @param string $column 列名/聚合别名（严格标识符；不接受函数括号形态）
     * @param string $operator 比较操作符
     * @param mixed $value 绑定值
     * @return $this 链式
     * @throws \InvalidArgumentException 列名非法
     */
    public function having(string $column, string $operator, $value)
    {
        if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $column)) {
            throw new \InvalidArgumentException("Invalid having column: $column");
        }
        $op = in_array(strtoupper($operator), self::OPERATORS, true) ? strtoupper($operator) : '=';
        $this->having = [$column, $op, $value];
        return $this;
    }

    /**
     * 设置 LIMIT/OFFSET（覆盖式）。int 类型参数本身挡下字符串注入。
     * ⚠ 边界：toSql 用真值判断拼接，limit(0) 视为「不限制」不生成 LIMIT 子句（非 LIMIT 0）；
     *   负数不被拦截，会拼进 SQL。调用方需自行保证非负。
     * @param int $limit 行数上限
     * @param int $offset 偏移（默认 0）
     * @return $this 链式
     */
    public function limit(int $limit, int $offset = 0)
    {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    /**
     * 以页码语义设置分页：limit=pageSize、offset=(page-1)*pageSize。
     * ⚠ 不做 page≥1 钳制——page<=0 会算出负 offset（toSql 真值判断下负数仍拼入，生成非法 SQL），
     *   调用方须先 max(1,$page)（DbTableRepository::find 已在内部钳制）。
     * @param int $page 页码（1 起）
     * @param int $pageSize 每页条数
     * @return $this 链式
     */
    public function page(int $page, int $pageSize = 20)
    {
        $this->limit = $pageSize;
        $this->offset = ($page - 1) * $pageSize;
        return $this;
    }

    /**
     * 组装 SELECT SQL 串（不执行）。子句顺序：SELECT→FROM→JOIN→WHERE→GROUP BY→HAVING→ORDER BY→LIMIT/OFFSET→FOR UPDATE。
     * ⚠ 副作用：开头**清空 $this->params**，随后按 SQL 中 ? 出现顺序重建绑定数组
     *   （WHERE 各条件、含 IN 的展开值 → 最后 HAVING）——
     *   执行侧必须先调本方法再经 getParams() 取参（get()/count()/first() 已内建该顺序）。
     * ⚠ 本方法无 where 也照常出全表 SELECT（防误写红线只在 update()/delete() 处强制）。
     * @return string 参数化 SQL（标识符已按方言引用、值全部 ? 占位）
     */
    public function toSql(): string
    {
        // Reset params to prevent duplication on repeated calls
        $this->params = [];
        $cols = implode(',', array_map(array($this, 'qiColumn'), $this->columns));
        $sql = "SELECT $cols FROM " . $this->qTable($this->table);
        foreach ($this->joins as $join) { $sql .= " $join"; }
        $sql .= $this->buildWhereSql();
        if ($this->groupBy) $sql .= ' GROUP BY ' . implode(',', array_map(array($this, 'qiColumn'), $this->groupBy));
        if ($this->having) {
            $sql .= " HAVING " . $this->qi($this->having[0]) . " {$this->having[1]} ?";
            $this->params[] = $this->having[2];
        }
        if ($this->orderBy) $sql .= ' ORDER BY ' . implode(',', $this->orderBy);
        if ($this->limit) $sql .= " LIMIT {$this->limit}";
        if ($this->offset) $sql .= " OFFSET {$this->offset}";
        // 悲观行锁（R04 配套）：仅 mysql/pgsql 生成；sqlite 无此语法，忽略以免非法 SQL
        if ($this->forUpdate && 'sqlite' !== $this->type) $sql .= ' FOR UPDATE';
        return $sql;
    }

    /**
     * 组装 WHERE 子句（参数追加到 $this->params）
     */
    private function buildWhereSql(): string
    {
        if (!$this->wheres) return '';
        $conditions = [];
        foreach ($this->wheres as $w) {
            if (isset($w['RAW'])) {
                $conditions[] = $w['RAW'];
                // RAW 条件（whereIn）的值同样需要参数化绑定
                if (!empty($w[2]) && is_array($w[2])) {
                    foreach ($w[2] as $pv) $this->params[] = $pv;
                }
                continue;
            }
            $conditions[] = $this->qi($w[0]) . " {$w[1]} ?";
            $this->params[] = $w[2];
        }
        return ' WHERE ' . implode(' AND ', $conditions);
    }

    /**
     * 安全修复：列名位标识符校验（与 update()/applyCond() 同口径白名单正则）。
     * 此前 insert/replace/bulkInsert 直接把数组键拼入反引号列名位（update 却有校验——同文件矛盾），
     * 含反引号的恶意列名（如回收站 JSON 快照反序列化列）可逃逸引号注入 SQL。非法列跳过丢弃。
     */
    private function safeColumns(array $data): array
    {
        $out = array();
        foreach ($data as $k => $v) {
            if (!is_string($k) || !preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $k)) continue;
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * 写入：INSERT，返回自增 ID（无自增返回 0）
     */
    public function insert(array $data): int
    {
        if (empty($data)) return 0;
        $data = $this->safeColumns($data);
        if (empty($data)) return 0;
        $sql = $this->insertSql($data);
        $stmt = $this->db->prepare_execute($sql, array_values($data));
        if (!$stmt) return 0;
        $pdo = $this->db->wlink;
        return $pdo ? (int)$pdo->lastInsertId() : 0;
    }

    /**
     * INSERT SQL 纯构建器（任务 3 方言层：不执行，供方言单测）
     */
    public function insertSql(array $data): string
    {
        $cols = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        return "INSERT INTO " . $this->qTable($this->table) . " (" . implode(',', array_map(array($this, 'qi'), $cols)) . ") VALUES ($placeholders)";
    }

    /**
     * REPLACE SQL 纯构建器（任务 3 方言层）：
     * mysql/sqlite=REPLACE INTO（原语义）；pgsql=主键内省 + ON CONFLICT DO UPDATE（EXCLUDED 取新值），
     * 主键不可知或数据不含主键时退化为纯 INSERT（等价于无冲突 REPLACE）。
     * $pkCols 由调用方传入（replace() 从 db->primaryKeys() 内省；测试可经静态钩子注入）。
     */
    public function replaceSql(array $data, array $pkCols = array()): string
    {
        $cols = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $qiCols = implode(',', array_map(array($this, 'qi'), $cols));
        $table = $this->qTable($this->table);
        if ($this->type === 'pgsql') {
            $pkInData = array_intersect($pkCols, $cols);
            if (!empty($pkInData)) {
                $updates = array();
                foreach ($cols as $c) {
                    if (!in_array($c, $pkCols, true)) $updates[] = $this->qi($c) . ' = EXCLUDED.' . $this->qi($c);
                }
                if (empty($updates)) return "INSERT INTO $table ($qiCols) VALUES ($placeholders)";
                return "INSERT INTO $table ($qiCols) VALUES ($placeholders) ON CONFLICT (" . implode(',', array_map(array($this, 'qi'), $pkCols)) . ') DO UPDATE SET ' . implode(', ', $updates);
            }
            return "INSERT INTO $table ($qiCols) VALUES ($placeholders)";
        }
        return "REPLACE INTO $table ($qiCols) VALUES ($placeholders)";
    }

    /**
     * 写入：REPLACE，返回自增 ID（无自增返回 0）
     * 任务 3：pgsql 下为主键内省 upsert（ON CONFLICT DO UPDATE，EXCLUDED 取新值）
     */
    public function replace(array $data): int|false
    {
        if (empty($data)) return 0;
        $data = $this->safeColumns($data);
        if (empty($data)) return 0;
        $sql = $this->replaceSql($data, self::$pkOverride[$this->type . ':' . $this->table] ?? $this->db->primaryKeys($this->table));
        $stmt = $this->db->prepare_execute($sql, array_values($data));
        if (!$stmt) return false;
        $pdo = $this->db->wlink;
        return $pdo ? (int)$pdo->lastInsertId() : 0;
    }

    /**
     * 写入：UPDATE，返回影响行数；列名以 + 结尾视为自增表达式（如 son+ => son = son + 1）
     * 未设置 where 时抛异常，防止全表更新
     */
    public function update(array $data): int|false
    {
        if (empty($data)) return 0;
        if (!$this->wheres) throw new \RuntimeException('QueryBuilder::update requires where conditions');
        $sql = $this->updateSql($data);
        $stmt = $this->db->prepare_execute($sql, $this->params);
        return $stmt ? $stmt->rowCount() : false;
    }

    /**
     * UPDATE SQL 纯构建器（任务 3 方言层；列名 + / - 后缀=自增表达式）
     * where 参数经 $this->params 返回（与 toSql 同模式），调用方 prepare_execute 时取用
     */
    public function updateSql(array $data): string
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            // 列名白名单：普通标识符（含 + - 自增后缀），防任意标识符拼入 SET
            $real = (is_string($col) && (substr($col, -1) === '+' || substr($col, -1) === '-')) ? substr($col, 0, -1) : $col;
            if (!is_string($real) || !preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $real)) {
                throw new \InvalidArgumentException("Invalid update column: " . (is_string($real) ? $real : gettype($real)));
            }
            if (is_string($col) && (substr($col, -1) === '+' || substr($col, -1) === '-')) {
                $op = substr($col, -1);
                // ⚠ 参数化（审查修复）：旧实现 intval($val) 直拼 SQL——无注入面（intval 强转），
                //   但数组值会静默变成 0/1、非数字串被截断成错误增量，且与同文件 bulkUpdate
                //   的「? 占位」口径不一致。现同口径绑定：增量 intval 后走参数，语义与旧一致。
                $sets[] = $this->qi($real) . " = " . $this->qi($real) . " $op ?";
                $params[] = intval($val);
                continue;
            }
            $sets[] = $this->qi($col) . " = ?";
            $params[] = $val;
        }
        $this->params = $params;
        return "UPDATE " . $this->qTable($this->table) . " SET " . implode(', ', $sets) . $this->buildWhereSql();
    }

    /**
     * 写入：DELETE，返回影响行数；未设置 where 时抛异常，防止全表删除
     */
    public function delete(): int|false
    {
        if (!$this->wheres) throw new \RuntimeException('QueryBuilder::delete requires where conditions');
        $this->params = [];
        $sql = $this->deleteSql();
        $stmt = $this->db->prepare_execute($sql, $this->params);
        return $stmt ? $stmt->rowCount() : false;
    }

    /**
     * DELETE SQL 纯构建器（任务 3 方言层）
     */
    public function deleteSql(): string
    {
        return "DELETE FROM " . $this->qTable($this->table) . $this->buildWhereSql();
    }

    /**
     * 聚合：MAX，返回最大值（无行返回 0）
     */
    public function maxId(string $column): int
    {
        if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $column)) {
            throw new \InvalidArgumentException("Invalid maxId column: $column");
        }
        $cols = $this->columns;
        $this->columns = ["MAX(" . $this->qi($column) . ") AS n"];
        $sql = $this->toSql();
        $this->columns = $cols;
        $stmt = $this->db->prepare_execute($sql, $this->params);
        if (!$stmt) return 0;
        $r = $stmt->fetch();
        return (int)($r['n'] ?? 0);
    }

    /**
     * 聚合：SUM，返回合计值
     */
    public function sum(string $column): int
    {
        if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $column)) {
            throw new \InvalidArgumentException("Invalid sum column: $column");
        }
        $cols = $this->columns;
        $this->columns = ["COALESCE(SUM(" . $this->qi($column) . "),0) AS n"];
        $sql = $this->toSql();
        $this->columns = $cols;
        $stmt = $this->db->prepare_execute($sql, $this->params);
        if (!$stmt) return 0;
        $r = $stmt->fetch();
        return (int)($r['n'] ?? 0);
    }

    /**
     * @return array|false
     */
    public function get()
    {
        $sql = $this->toSql();
        $params = $this->params;
        $attempts = max(1, $this->retryCount + 1);
        for ($i = 0; $i < $attempts; $i++) {
            $stmt = $this->db->prepare_execute($sql, $params);
            if ($stmt) return $stmt->fetchAll();
            if ($i < $attempts - 1) usleep(100 * ($i + 1));
        }
        return false;
    }

    /**
     * @return array|null|false
     */
    public function first()
    {
        $this->limit(1);
        $rows = $this->get();
        return $rows ? $rows[0] : null;
    }

    /**
     * 执行 COUNT(*) 聚合（临时把 SELECT 列换成 COUNT(*) AS n、取完再还原，不影响后续链式取数据列）。
     * 带 retryCount+1 次尝试（100µs 递增退避；但 retryCount 无外部写入路径，当前恒为 0 ⇒ 实为单次）；
     * **查询失败返回 0**（无法区分「空表」与「库错」，
     * 调用方若需感知失败应另用 get()）。深分页仍是 LIMIT offset 口径（技术债 #12）。
     * @return int 匹配行数（失败/空均为 0）
     */
    public function count(): int
    {
        $cols = $this->columns;
        $this->columns = ['COUNT(*) AS n'];
        $sql = $this->toSql();
        $this->columns = $cols;
        $params = $this->params;
        $attempts = max(1, $this->retryCount + 1);
        for ($i = 0; $i < $attempts; $i++) {
            $stmt = $this->db->prepare_execute($sql, $params);
            if ($stmt) { $r = $stmt->fetch(); return (int)($r['n'] ?? 0); }
            if ($i < $attempts - 1) usleep(100 * ($i + 1));
        }
        return 0;
    }

    /**
     * 取**最近一次 toSql()/updateSql() 生成**的绑定参数数组（与 SQL 中 ? 一一对应）。
     * ⚠ 必须先调 toSql()/updateSql() 再取——单独调用本方法拿到的是上次构建的残留或空数组。
     * @return array 按占位符顺序排列的参数值（供 prepare_execute 使用）
     */
    /**
     * 事务（任务5 补强：改走驱动事务 API 委托）。
     * 旧实现裸抓 $db->wlink 手动 BEGIN/COMMIT/ROLLBACK——驱动不知情，读写分离开启时
     * 事务内 SELECT 会被 routeLink 路由到从库（看到事务前快照，读不到自己未提交的写）。
     * 现经 beginTransaction/commit/rollBack 委托，routeLink 感知 txDepth 恒走写连接。
     * 重试语义：回调抛异常 → 回滚 → 重试；commit 失败不重试（防回调副作用重复执行）。
     * ⚠ **回调内禁止执行 DDL**（收尾轮补记）：MySQL 遇 DDL 会隐式提交当前事务，此后再 commit()
     *   会因 `!inTransaction()` 返回 false ⇒ 本方法把「其实已落库」报成失败（保守方向，宁可误报失败
     *   也不谎报成功）。PdoDriver::commit() 已按上述两种成因分别写 `db_error` 日志（txRollbackPending /
     *   inTransaction=false），接线前先确认回调里没有 CREATE/ALTER/DROP/TRUNCATE。
     * @return mixed
     */
    public static function transaction(callable $callback, int $retries = 0)
    {
        $db = $_SERVER['db'] ?? null;
        if (!$db) return false;
        if (!method_exists($db, 'beginTransaction')) return false;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            if (!$db->beginTransaction()) return false;
            try {
                $qb = new QueryBuilder($db, '');
                $result = $callback($qb);
                // ⚠ commit 失败必须如实上报（审查修复）：PdoDriver::commit() 返回 false 时事务
                // 未落库（其内部已按 txRollbackPending 标记/提交异常两条路径回滚）。旧实现
                // 无视返回值直接 return $result，会把「已回滚的写入」当成功返回，调用方
                // 据此 bump 页缓存并上报成功。不重试：
                // 回调副作用可能已发生，重复执行风险大于收益（docblock 口径一致）。
                if (!$db->commit()) {
                    DEBUG && xn_log('Transaction commit failed (rolled back), giving up', 'db_error');
                    return false;
                }
                return $result;
            } catch (\Throwable $e) {
                $db->rollBack();
                if ($attempt >= $retries) {
                    DEBUG && xn_log("Transaction failed: " . $e->getMessage(), 'db_error');
                    return false;
                }
                usleep(100 * ($attempt + 1));
            }
        }
        return false;
    }
}
