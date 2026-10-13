<?php
namespace App\Repository;

/**
 * 保持与旧 db_* 函数一致的 cond/orderby/key/col 调用语义
 */
class DbTableRepository
{
    protected $table;
    protected $primaryKey;

    public function __construct(string $table, string $primaryKey = 'id')
    {
        $this->table = $table;
        $this->primaryKey = $primaryKey;
    }

    /**
     * 单行 INSERT（QueryBuilder 真·预编译路径）。
     * 列名由底层 safeColumns 白名单过滤（非法键静默剔除），空数组直接短路。
     * 表命中内容白名单（vod/art/tag 等）时调用 bumpContentPageCache 推进页缓存版本
     * （R6-04；底层失败以返回 0 表达而非 false，故推进不代表确有一行写入）。
     * @param array $arr 列=>值 的待插行
     * @param \PdoDriver|null $d 连接选择器（可传从库/指定句柄）；NULL 用全局 $_SERVER['db']
     * @return int|false 自增 ID（无自增表或语句失败=0）；空入参=false
     */
    public function insert(array $arr, $d = NULL)
    {
        if (empty($arr)) return FALSE;
        $r = $this->builder($d)->insert($arr);
        FALSE !== $r && $this->bumpContentPageCache();
        return $r;
    }

    /**
     * insert() 的历史别名转发（对齐旧 db_insert 系 create 语义），返回与副作用完全同 insert()。
     * @param array $arr 列=>值 的待插行
     * @param \PdoDriver|null $d 连接选择器
     * @return int|false 同 insert()
     */
    public function create(array $arr, $d = NULL)
    {
        return $this->insert($arr, $d);
    }

    /**
     * REPLACE INTO 写入（MySQL/SQLite 原语义；pgsql 方言下为主键内省 upsert，见 QueryBuilder::replaceSql）。
     * 主键撞则先删后插——整行覆盖，未提供的列回落到表默认值，调用方须传全量列。
     * @param array $arr 列=>值 的待写行（须含主键才有 replace 语义）
     * @param \PdoDriver|null $d 连接选择器
     * @return int|false lastInsertId（无自增/失败=0）；空入参=false
     */
    public function replace(array $arr, $d = NULL)
    {
        if (empty($arr)) return FALSE;
        $r = $this->builder($d)->replace($arr);
        FALSE !== $r && $this->bumpContentPageCache();
        return $r;
    }

    /**
     * 条件 UPDATE。$cond 走 applyCond（数组值=IN、['<' => x] 形态=比较符）；
     * $update 列名 +/− 后缀 = 列自增表达式（如 'views+' => 1）。
     * 空 $cond 直接返回 FALSE（QueryBuilder 对无 where 的 update 抛异常，防全表误写，勿绕过）。
     * @param array $cond
     * @param array $update
     * @param \PdoDriver|null $d
     * @return int|false 影响行数（值未变更时 MySQL 返回 0，属正常）；空参=false
     */
    public function update(array $cond, array $update, $d = NULL)
    {
        if (empty($cond) || empty($update)) return FALSE;
        $r = $this->builder($d)->applyCond($cond)->update($update);
        FALSE !== $r && $this->bumpContentPageCache();
        return $r;
    }

    /**
     * 按单列等值取一行（`WHERE $key = $id LIMIT 1`，参数化绑定）。
     * 未指定列清单 ⇒ SELECT *（QueryBuilder 默认列），热点宽表请改用 findOne 传显式 $col。
     * @param int|string $id 主键/查询列的值
     * @param string $key 查询列名（默认 'id'，实例主键列由构造注入但此处不自动套用）
     * @param \PdoDriver|null $d
     * @return array|null 行数据；无记录=NULL
     */
    public function read($id, string $key = 'id', $d = NULL)
    {
        return $this->builder($d)->where($key, '=', $id)->first();
    }

    /**
     * 列表查询（显式列优先）
     *
     * SQL 专项审计（R10 SELECT *）：`$col` 为空时落到 `array('*')`，全仓曾有
     * 38 处省略该参数 ⇒ 拉全表列（`vod` 40 列含 `play_url` 均值 5.4KB/最大 171KB、
     * `art` 含 longtext）。现策略：
     *  ① 热点表（vod/art/session/scheduler_tasks 等）调用方**必须**显式传列——已逐点补齐；
     *  ② 仍省略的调用点一律**留痕**（含真实调用方类::方法），便于持续收敛而非静默放过；
     *  ③ 保留 `array('*')` 兜底以兼容既有签名（强制必传会破坏 38 个既有调用点，
     *     属破坏性重构，按项目"不做无关重构"红线缓做）。
     * @param array $col 显式列清单；空数组=SELECT *（留痕）
     */
    public function find(array $cond = array(), array $orderby = array(), int $page = 1, int $pagesize = 10, string $key = '', array $col = array(), $d = NULL)
    {
        $qb = $this->builder($d);
        $cond and $qb->applyCond($cond);
        $orderby and $qb->applyOrderBy($orderby);
        if (empty($col)) {
            $this->logStarSelect(__FUNCTION__);
            $col = array('*');
        }
        $rows = $qb->select($col)->page(max(1, $page), $pagesize)->get();
        if (!$rows) return array();
        if ($key === '') return $rows;
        $arr = array();
        foreach ($rows as $row) {
            if (isset($row[$key])) $arr[$row[$key]] = $row;
        }
        return $arr;
    }

    /**
     * SELECT * 违规留痕（R10 收敛哨兵，SQL 专项审计）
     * 记录真实调用方（类::方法）与目标表，便于后续按"是否消费大字段"逐点整改；
     * 同请求同点只记一次，避免日志放大。仅 DEBUG>0 时落盘——生产（DEBUG=0）不污染日志，
     * 盘点时可临时开 DEBUG=1，或用审计脚本静态扫描（见报告 §九 清单）。
     */
    private function logStarSelect(string $where): void
    {
        static $seen = array();
        $sig = $this->table . '|' . $where;
        if (isset($seen[$sig])) return;
        $seen[$sig] = TRUE;
        if (!defined('DEBUG') || DEBUG < 1) return;
        $caller = '?';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $fr) {
            if (isset($fr['class']) && str_contains($fr['class'], 'Repository')) continue;
            $caller = (isset($fr['class']) ? $fr['class'] . '::' : '') . ($fr['function'] ?? '?');
            break;
        }
        if (function_exists('xn_log')) {
            xn_log('SELECT * on `' . $this->table . '` via ' . $where . ' from ' . $caller, 'sql_star_select');
        }
    }

    /**
     * 条件取单行（LIMIT 1）。$col 空 ⇒ SELECT *（R10 留痕口径见 find() 注释，
     * ⚠ 本方法自身未调用 logStarSelect，热点宽表调用点请自觉传显式列）。
     * @param array $cond applyCond 口径（数组值=IN）
     * @param array $orderby db_find 风格 列=>1/-1
     * @param array $col 显式列清单，空=全列
     * @param \PdoDriver|null $d 连接选择器
     * @return array|null 行数据；无记录=NULL（QueryBuilder::first 对空结果统一给 null）
     */
    public function findOne(array $cond = array(), array $orderby = array(), array $col = array(), $d = NULL)
    {
        $qb = $this->builder($d);
        $cond and $qb->applyCond($cond);
        $orderby and $qb->applyOrderBy($orderby);
        return $qb->select($col ?: array('*'))->first();
    }

    /**
     * 条件 DELETE。空 $cond 返回 FALSE（QueryBuilder 无 where 直接抛异常，双保险防全表删）。
     * @param array $cond
     * @param \PdoDriver|null $d
     * @return int|false 影响行数（0=无匹配行）；空参=false
     */
    public function delete(array $cond, $d = NULL)
    {
        if (empty($cond)) return FALSE;
        $r = $this->builder($d)->applyCond($cond)->delete();
        FALSE !== $r && $this->bumpContentPageCache();
        return $r;
    }

    /**
     * COUNT(*)，空 $cond = 全表计数（InnoDB 下为估算/扫描，热点慎用）。
     * @param array $cond
     * @param \PdoDriver|null $d
     * @return int 签名已声明（语句失败按 0 计）
     */
    public function count(array $cond = array(), $d = NULL): int
    {
        $qb = $this->builder($d);
        $cond and $qb->applyCond($cond);
        return $qb->count();
    }

    /**
     * MAX($col) 聚合；列名走 QueryBuilder 标识符校验，非法列抛 InvalidArgumentException。
     * @param string $col
     * @param array $cond
     * @param \PdoDriver|null $d
     * @return int 无行/NULL 结果按 0（签名已声明 int）
     */
    public function maxId(string $col, array $cond = array(), $d = NULL): int
    {
        $qb = $this->builder($d);
        $cond and $qb->applyCond($cond);
        return $qb->maxId($col);
    }

    /**
     * 批量 INSERT：多行 VALUES 单语句、全参数化（QueryBuilder::bulkInsert）。
     * 列集合以第一行 safeColumns 后的键为准，后续行缺失列补 NULL、多余列丢弃。
     * @param array $arr 行数组的数组（list of array）
     * @param \PdoDriver|null $d
     * @param bool $ignore 透传给 bulkInsert：TRUE 用 INSERT IGNORE（撞唯一键的行单独跳过，不让整批失败）。
     *        仅用于带唯一键的关系表（如 tag_id）；主表写入口不要传 TRUE，理由见 QueryBuilder::bulkInsert。
     * @return int|false 实际影响行数；空入参=false
     */
    public function bigInsert(array $arr, $d = NULL, bool $ignore = false)
    {
        if (empty($arr)) return FALSE;
        $r = $this->builder($d)->bulkInsert($arr, $ignore);
        FALSE !== $r && $this->bumpContentPageCache();
        return $r;
    }

    /**
     * 按主键批量 UPDATE（CASE WHEN 单语句，QueryBuilder::bulkUpdate）。
     * ⚠ $cond 只取其**第一个键名**作为主键列，键值被忽略——实际更新范围是
     * array_keys($update) 的 IN 集合；$update 形如 array(主键值 => array(列=>值[, '列+' => n]))。
     * 非法列名在底层逐字段跳过（不抛异常），全部非法时返回 0。
     * @param array $cond 仅列名有意义（如 array('vid' => $ids)）
     * @param array $update
     * @param \PdoDriver|null $d
     * @return int|false 影响行数；$cond/$update 为空=false
     */
    public function bigUpdate(array $cond, array $update, $d = NULL)
    {
        if (empty($cond) || empty($update)) return FALSE;
        reset($cond);
        $condKey = key($cond);
        $r = $this->builder($d)->bulkUpdate((string)$condKey, $update);
        FALSE !== $r && $this->bumpContentPageCache();
        return $r;
    }

    /**
     * TRUNCATE TABLE 清表（DDL，自增归零，不走 where 保护）。
     * ✅ 语义已收紧：底层 PdoDriver::truncate 原无条件返回 true（失败只落 db_error 日志），
     * 现按 exec 结果如实返回 ⇒ 本方法的 false 有两种含义：拿不到连接句柄，或 TRUNCATE 语句失败。
     * 成功为 true（含"表本来就空"的 0 行影响）；失败时不再推进页缓存版本令牌。
     * @param \PdoDriver|null $d 连接句柄；NULL 回落 $_SERVER['db']
     * @return bool 语句是否执行成功
     */
    public function truncate($d = NULL)
    {
        $db = $d ?: ($_SERVER['db'] ?? NULL);
        if (!$db) return FALSE;
        $r = $db->truncate($db->tablepre . $this->table);
        FALSE !== $r && $this->bumpContentPageCache();
        return $r;
    }

    /**
     * 内容表写入后推进页缓存版本（R6-04）：跨 FPM worker 即时失效
     * page_index_/list_/type_/cate_/tag_ 等页缓存。仅内容相关表白名单触发，
     * 避免 user/group 等无关写入把页缓存打冷。
     */
    private function bumpContentPageCache(): void
    {
        static $contentTables = array(
            'vod' => 1, 'vod_vid' => 1, 'vod_sticky' => 1, 'vod_top' => 1,
            'art' => 1, 'art_aid' => 1, 'art_top' => 1,
            'cate' => 1, 'tag' => 1, 'tag_id' => 1, 'ad' => 1, 'link' => 1, 'recycle' => 1,
        );
        if (!isset($contentTables[$this->table])) return;
        if (function_exists('page_cache_bump')) {
            try { \page_cache_bump(); } catch (\Throwable $e) {}
        }
    }

    /**
     * 构造本表的 QueryBuilder（标识符与表前缀在底层拼装）。
     * $d 非空：以传入连接句柄直建（读写分离下调用方选从库的入口，$d 须为 PdoDriver 子类实例）；
     * $d 为 NULL：走 db_table() 全局句柄 $_SERVER['db']。
     * 每次调用返回**新实例**，链式条件不跨调用残留。
     * @param \PdoDriver|null $d
     * @return \QueryBuilder 签名已声明
     */
    public function builder($d = NULL): \QueryBuilder
    {
        if ($d) return new \QueryBuilder($d, $d->tablepre . $this->table);
        return db_table($this->table);
    }
}
