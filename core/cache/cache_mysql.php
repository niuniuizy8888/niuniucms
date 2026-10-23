<?php
/**
 * MySQL 缓存驱动 — 持久化缓存层
 *
 * 作为 MultiLayerCache 的最后一个兜底层，提供跨进程持久化存储。
 * 完整实现 CacheInterface 接口，支持所有标准缓存操作。
 *
 * 使用 MySQL cache 表（自动走 db 表前缀），
 * value 使用 xn_json_encode/json_decode 存储（可读、不依赖 PHP 序列化）。
 */

class cache_mysql implements CacheInterface
{
    public $conf = [];
    public $db = NULL;
    public $table = 'cache';
    public $cachepre = '';
    public $errno = 0;
    public $errstr = '';

    public function __construct($dbconf = [])
    {
        if (is_object($dbconf['db'] ?? null)) {
            $this->db = $dbconf['db'];
        } else {
            $this->conf = $dbconf;
            $this->db = db_new($dbconf);
        }
        $this->cachepre = $dbconf['cachepre'] ?? 'pre_';
    }

    /**
     * 驱动标识（MultiLayerCache 记录层级名/按 type 定位层用，见其 getName() 比较点）。
     * @return string 恒 'mysql'
     */
    public function getName(): string
    {
        return 'mysql';
    }

    /**
     * 建立/复用底层 PDO 连接：转发 db_connect($this->db) → PdoDriver::connect（已有句柄直接复用）。
     * @return \PDO|null 成功返回 PDO 连接对象；PdoDriver::connect 建连失败返回 null，
     *         并由 db_errno_errstr 把驱动 errno/errstr 同步进全局 $errno/$errstr（安装器/健康检查读错误用）
     */
    public function connect()
    {
        return db_connect($this->db);
    }

    /**
     * 应用缓存前缀，超长 key 自动缩短（cache 表 k 列通常为 VARCHAR(32) 或 VARCHAR(64)）
     */
    private function prefixed(string $key): string
    {
        $k = $this->cachepre . $key;
        if (strlen($k) > 32) {
            $k = md5($k);
        }
        return $k;
    }

    // ==================== CacheInterface ====================

    /**
     * 读取缓存
     * @return mixed 找到返回数据，未找到或过期返回 null
     */
    /** @return mixed */
    public function get(string $key)
    {
        $time = time();
        $k = $this->prefixed($key);
        try {
            $arr = db_find_one($this->table, ['k' => $k], [], [], $this->db);
        } catch (\Throwable $e) {
            // 表不存在或查询错误 → 返回 null（cache miss）
            $this->errno = $e->getCode();
            $this->errstr = $e->getMessage();
            return null;
        }
        // 记录不存在 → miss
        if (!$arr || !is_array($arr)) return null;
        // 已过期 → miss
        if (!empty($arr['expiry']) && $time > (int)$arr['expiry']) return null;
        // 命中
        return xn_json_decode($arr['v'], 1);
    }

    /**
     * 写入缓存
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        $time = time();
        $k = $this->prefixed($key);
        $expiry = $ttl ? $time + $ttl : 0;
        try {
            $arr = [
                'k' => $k,
                'v' => xn_json_encode($value),
                'expiry' => $expiry,
            ];
            $r = db_replace($this->table, $arr, $this->db);
            if ($r !== false) {
                $this->gcExpired();
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            $this->errno = $e->getCode();
            $this->errstr = $e->getMessage();
            return false;
        }
    }

    /**
     * 过期行机会式回收（缓存深挖专项）
     *
     * 缺陷（实测）：本驱动的 get() 读到过期行只是**返回 null**（`$time > expiry` ⇒ miss），
     * 从不删除该行；set() 又只按主键 `db_replace` 覆盖**同键**。于是键空间随内容版本推进
     * 单调增长，而页缓存键含 `_cv<版本>`——每次内容更新都留下整批再也读不到的旧键。
     * 生产实测：nncms_cache 表 11 行 / 736,974 字节中，**已过期 6 行 / 601,408 字节（82%）**，
     * 即 MySQL 兜底层的绝大部分存储是永远读不到的死重（页缓存条目单条可达 100KB+）。
     *
     * 处置：在写成功后做**有界、低频、可失败**的机会式回收——
     *   ① 频率门：本进程 5 分钟最多一次，且叠加 1/50 概率，避免每次写都扫表；
     *      若一次删满 200 行（有积压），撤掉 5 分钟门、仅保留 1/50 概率，直到积压清完——
     *      多层缓存修复后无 Redis/Memcached 主机的每次 cache_set 都会真正落本表，回收速率须能追上写入速率；
     *   ② 有界：单次最多删 200 行，绝不长事务/大删除阻塞写入；
     *   ③ 失败静默：回收异常绝不影响 set() 的返回值（缓存写入的成败与 GC 无关）。
     *
     * 规模边界（诚实记录）：`nncms_cache` 无 expiry 索引（CREATE TABLE 仅 PRIMARY KEY(k)，
     * 实测 `EXPLAIN DELETE ... WHERE expiry < now` 为 type=ALL），故行数很大时此扫描会变贵。
     * 当前表为十几行量级、单次成本可忽略；若将来该表进入万行量级，正确做法是加
     * `KEY expiry (expiry)`（需独立迁移批次）或改为定期 CLI 回收（plugin.php 子命令）。
     */
    private function gcExpired(): void
    {
        static $lastGc = 0;
        $now = time();
        if ($now - $lastGc < 300) return;          // 本进程 5 分钟内已回收过
        if (mt_rand(1, 50) !== 1) return;          // 叠加概率，进一步压低频率
        $lastGc = $now;
        try {
            // 用既有助手（自带表名前缀 + 列名白名单校验 + 字面量转义），条件形态由
            // db_cond_to_sqladd 归一：array('expiry' => array('>' => 0, '<' => $now))
            //   ⇒ `DELETE FROM {pre}cache WHERE \`expiry\` > 0 AND \`expiry\` < N AND 1=1 LIMIT 200`
            $n = db_exec('DELETE FROM ' . $this->db->tablepre . $this->table
                . db_cond_to_sqladd(array('expiry' => array('>' => 0, '<' => $now)), $this->db)
                . ' AND 1=1 LIMIT 200', $this->db);
            // 积压自适应：删满 200 行说明还有过期积压 ⇒ 撤掉 5 分钟门，只留 1/50 概率，
            // 回收能力变为「平均每 50 次写删 200 行」，恒大于写入速率 ⇒ 表行数有界（无 Redis 主机 MySQL 层成为主力后必需）
            if (is_int($n) && $n >= 200) $lastGc = 0;
        } catch (\Throwable $e) {
            // 静默：回收失败不影响缓存读写
        }
    }

    /**
     * 删除缓存
     * ⚠ miss≠failure 口径对齐（审查修复）：旧实现 `$r > 0` 把「键不存在（0 行）」当失败，
     *   与 StaticCache 约定及 redis/apcu/memcached 三驱动（miss 恒成功）不一致——mysql 层
     *   绝大多数键本就不存在（有 Redis/Memcached 时热层短路写），`cache_delete()` 对常规键恒返 false，污染
     *   MultiLayerCache::delete() 的 AND 链。现 0 行=成功，仅语句失败（FALSE/异常）为 false。
     */
    public function delete(string $key): bool
    {
        $k = $this->prefixed($key);
        try {
            $r = db_delete($this->table, ['k' => $k], $this->db);
            return $r !== false;
        } catch (\Throwable $e) {
            $this->errno = $e->getCode();
            $this->errstr = $e->getMessage();
            return false;
        }
    }

    /**
     * 清空所有 MySQL 缓存
     */
    public function clear(): bool
    {
        try {
            $r = db_truncate($this->table, $this->db);
            return $r !== false;
        } catch (\Throwable $e) {
            $this->errno = $e->getCode();
            $this->errstr = $e->getMessage();
            return false;
        }
    }

    /**
     * 检查 key 是否存在且未过期
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 批量读取
     */
    public function getMultiple(array $keys): array
    {
        $result = array_fill_keys($keys, null);
        if (empty($keys)) return $result;
        // R7 性能项：单次 `WHERE k IN (...)` 取代逐键查询（MySQL 兜底层批量读）
        $map = [];
        foreach ($keys as $k) { $map[$this->prefixed($k)] = $k; }
        $time = time();
        try {
            $rows = db_table($this->table)->whereIn('k', array_keys($map))->get();
        } catch (\Throwable $e) {
            return $result;
        }
        if (!is_array($rows)) return $result;
        foreach ($rows as $row) {
            if (!empty($row['expiry']) && $time > (int)$row['expiry']) continue;
            $orig = $map[$row['k']] ?? null;
            if ($orig !== null) $result[$orig] = xn_json_decode($row['v'], 1);
        }
        return $result;
    }

    /**
     * 批量写入
     */
    public function setMultiple(array $items, int $ttl = 0): bool
    {
        $ok = true;
        foreach ($items as $k => $v) {
            $ok = $this->set($k, $v, $ttl) && $ok;
        }
        return $ok;
    }

    /**
     * 批量删除
     */
    public function deleteMultiple(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $k) {
            $ok = $this->delete($k) && $ok;
        }
        return $ok;
    }

    /**
     * 递增（原子自增）
     *
     * P-5（2026-10-10 性能修复）：原实现为读-改-写（2 次往返 + 并发丢更新，限流/计数场景
     * 计数偏低=防线形同虚设）。现改为单语句 UPDATE 原子自增：
     *   - v 列为 JSON 编码，但本方法路径写入的均为裸整数（xn_json_encode(int)），CAST 安全；
     *   - 行不存在时先用 set() 初始化 0（复用 REPLACE 语义与过期字段），再自增；
     *   - expiry 语义与 set() 保持一致（$ttl=0 永不过期）；step=0 时 MySQL affected=0
     *     会误判行不存在并初始化——step=0 调用无意义，容忍该退化（值仍正确）。
     */
    /** @return int|false */
    public function increment(string $key, int $step = 1, int $ttl = 0)
    {
        $time = time();
        $k = $this->prefixed($key);
        $expiry = $ttl ? $time + $ttl : 0;
        $sql = 'UPDATE ' . $this->db->tablepre . $this->table . ' SET v = CAST(v AS SIGNED) + ?, expiry = ? WHERE k = ?';
        try {
            $st = $this->db->prepare_execute($sql, array($step, $expiry, $k));
            if (!$st || $st->rowCount() < 1) {
                $this->set($key, 0);
                $st = $this->db->prepare_execute($sql, array($step, $expiry, $k));
                if (!$st || $st->rowCount() < 1) return false;
            }
        } catch (\Throwable $e) {
            $this->errno = $e->getCode();
            $this->errstr = $e->getMessage();
            return false;
        }
        $row = $this->get($key);
        return is_numeric($row) ? (int)$row : 0;
    }

    /**
     * 递减
     */
    /** @return int|false */
    public function decrement(string $key, int $step = 1)
    {
        return $this->increment($key, -$step);
    }

    /**
     * 健康检查
     */
    public function ping(): bool
    {
        try {
            return $this->db !== null && db_connect($this->db);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ==================== 遗留兼容 ====================

    /**
     * 遗留兼容用的错误记录器（旧过程式缓存驱动的形态：`error($errno, $errstr)` 把错误码与文本
     * 存进实例公有属性 $errno/$errstr 供调用方事后读）；本类**内部不调用它**——
     * 各方法是在 catch 里直接赋值这两个属性，所以它只是留给外部按旧驱动接口取错的兼容入口，
     * 不参与 CacheInterface 契约（该接口没有 error 方法）。
     * 无日志、无抛错、无返回值，也不判断错误级别 ⇒ 别把它当日志通道用（留痕走 LoggerService/xn_log）。
     * @param int|string $errno 错误码（取证：同类 catch 分支赋的是异常 getCode()，PDO 可能是 SQLSTATE 字符串）
     * @param string $errstr 错误文本（同类 catch 分支赋的是 getMessage()）
     * @return void 无返回值（只写实例属性）
     */
    public function error($errno, $errstr): void
    {
        $this->errno = $errno;
        $this->errstr = $errstr;
    }

    public function __destruct()
    {
    }
}
