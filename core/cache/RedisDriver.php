<?php
/**
 * Redis 缓存驱动 — 完整数据结构封装
 * 支持：String / Hash / List / Set / Sorted Set / Bitmap / Lua / HyperLogLog
 */
class RedisDriver implements CacheInterface
{
    /** @var RedisPool|null */
    private $pool = null;
    /** @var Redis|null */
    private $connection = null;
    /** @var string */
    private $prefix;

    public function __construct(array $config = [])
    {
        $this->prefix = $config['cachepre'] ?? '';
        $this->pool   = new RedisPool($config, $config['persistent_id'] ?? '');
    }

    /**
     * 驱动标识：固定返回 'redis'。
     * MultiLayerCache 依赖该名称匹配熔断名单（redis 层故障会触发熔断）、共享层判定（sharedGet 跳过进程内层）与回填目标。
     * @return string
     */
    public function getName(): string { return 'redis'; }

    /** @return Redis|null */
    private function conn()
    {
        if ($this->connection && $this->ping()) return $this->connection;
        $this->connection = $this->pool !== null ? $this->pool->get() : null;
        return $this->connection;
    }

    /** @return void */
    private function release()
    {
        if ($this->connection) {
            if ($this->pool !== null) {
                $this->pool->put($this->connection);
            }
            $this->connection = null;
        }
    }

    // ==================== 基础接口 ====================

    /** @return mixed */
    public function get(string $key)
    {
        $redis = $this->conn(); if (!$redis) return null;
        try {
            $k = $this->prefix . $key;
            $val = $redis->get($k);
            return $val === false ? null : unserialize($val, ['allowed_classes' => false]);
        } finally { $this->release(); }
    }

    /**
     * 写入单键：值先 serialize() 再落 Redis，键自动加 cachepre 前缀。
     * @param string $key   键（不含前缀）
     * @param mixed  $value 任意可序列化值
     * @param int    $ttl   过期秒数：>0 走 SETEX；<=0 走 SET（永不过期，与 MultiLayerCache 回填的 TTL 登记口径一致）
     * @return bool 连接取不到或 Redis 写失败返回 false——MultiLayerCache::set() 对 redis 层的 false 会触发熔断
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try {
            $k = $this->prefix . $key;
            $v = serialize($value);
            // TTL>0 → SETEX (有到期); TTL=0 → SET (永不过期)
            if ($ttl > 0) {
                return $redis->setex($k, $ttl, $v);
            }
            return $redis->set($k, $v);
        } finally { $this->release(); }
    }

    /**
     * 删除单键（DEL）。
     * ⚠ 语义修正：phpredis 的 del() 成功时返回**删掉的键数**（int），只有出错才返回 false。
     *   原实现写 `del() > 0`，把「键本就不存在（返回 0）」错报成失败；而 MultiLayerCache::delete()
     *   把 redis 层的 falsy 判为驱动故障并熔断 30s（状态还跨进程写 .breaker_state.php）——
     *   清缓存、删过期键这类常规动作即可短时打掉整站 Redis 层，属误伤放大故障。
     *   现按「miss ≠ failure」处理：删掉 0 个也算成功，只有 false/异常才是失败
     *   （与 StaticCache::delete 的既有约定一致：删除总是安全，不得污染上层 AND 链）。
     * @param string $key 键（不含前缀）
     * @return bool 连接不可用或 DEL 出错为 false；键不存在仍为 true
     */
    public function delete(string $key): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->del($this->prefix . $key) !== false; } finally { $this->release(); }
    }

    /**
     * 清空本前缀下全部键：SCAN（count=100）+ DEL 游标遍历，不用 KEYS/FLUSHDB。
     * @return bool 前缀为空时拒绝执行（防误清整库）并写 cache_error 日志返回 false；连接不可用 false；扫描完成返回 true
     */
    public function clear(): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try {
            // 前缀为空时无法安全限定范围，拒绝清空
            if ($this->prefix === '') {
                xn_log('RedisDriver::clear() called with empty prefix, refusing to SCAN *', 'cache_error');
                return false;
            }
            // 使用 SCAN+DEL 仅清除本前缀的 key（无 SCAN_RETRY 时 [] 也是有效中间结果）
            $it = null;
            $match = $this->prefix . '*';
            while (($keys = $redis->scan($it, $match, 100)) !== false) {
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            }
            return true;
        } finally { $this->release(); }
    }

    /**
     * 键存在性判断（EXISTS）。
     * @param string $key 键（不含前缀）
     * @return bool false 同时覆盖「键不存在」与「连接不可用」两种情况
     */
    public function has(string $key): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->exists($this->prefix . $key) > 0; } finally { $this->release(); }
    }

    /**
     * 批量读（单次 MGET，R7 性能项）。值逐个 unserialize(['allowed_classes'=>false])，与 get() 同口径。
     * @param string[] $keys 键列表（不含前缀）
     * @return array<string,mixed> key=>value，未命中为 null；连接不可用时全部键返回 null（不是抛异常——
     *         MultiLayerCache 只把 null 记为 miss，不因批量读失败触发熔断）
     */
    public function getMultiple(array $keys): array
    {
        $redis = $this->conn(); if (!$redis) return array_fill_keys($keys, null);
        try {
            $prefixed = array_map(function($k) { return $this->prefix . $k; }, $keys);
            $vals = $redis->mget($prefixed);
            if (!is_array($vals)) $vals = array_fill(0, count($keys), false); // MGET 失败守卫，避免下标越界
            $result = [];
            foreach ($keys as $i => $k) {
                $result[$k] = $vals[$i] === false ? null : unserialize($vals[$i], ['allowed_classes' => false]);
            }
            return $result;
        } finally { $this->release(); }
    }

    /**
     * 批量写：PIPELINE 事务一次提交所有 SET/SETEX，避免逐键 RTT。
     * @param array<string,mixed> $items key=>value（键不含前缀）
     * @param int                 $ttl   统一过期秒数（<=0 永不过期）
     * @return bool 只要连接可用并走完 exec() 即返回 true——pipeline 不逐条校验结果，
     *              单条命令失败也会报成功；仅连接不可用返回 false
     */
    public function setMultiple(array $items, int $ttl = 0): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try {
            $pipe = $redis->multi(Redis::PIPELINE);
            foreach ($items as $k => $v) {
                $kk = $this->prefix . $k;
                $sv = serialize($v);
                if ($ttl > 0) {
                    $pipe->setex($kk, $ttl, $sv);
                } else {
                    $pipe->set($kk, $sv);
                }
            }
            $pipe->exec();
            return true;
        } finally { $this->release(); }
    }

    /**
     * 批量删除：加前缀后单条 DEL 多键。
     * @param string[] $keys 键列表（不含前缀）
     * @return bool 空列表直接 true（无可删项不算故障）；「全部键本就不存在」也返回 true——
     *         DEL 返回 0 是 miss 不是故障，交回 false 会让 MultiLayerCache::deleteMultiple()
     *         误判 redis 故障并熔断 30s（与 delete() 同一修正口径）。只有连接不可用或 DEL 出错才 false
     */
    public function deleteMultiple(array $keys): bool
    {
        if (empty($keys)) return true; // 空列表：DEL([]) 会被 phpredis 判参数错误，这里短路成成功
        $redis = $this->conn(); if (!$redis) return false;
        try {
            $prefixed = array_map(function($k) { return $this->prefix . $k; }, $keys);
            return $redis->del($prefixed) !== false;
        } finally { $this->release(); }
    }

    /** @return int|false */
    public function increment(string $key, int $step = 1)
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->incrBy($this->prefix . $key, $step); } finally { $this->release(); }
    }

    /** @return int|false */
    public function decrement(string $key, int $step = 1)
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->decrBy($this->prefix . $key, $step); } finally { $this->release(); }
    }

    /**
     * 探测当前已持有连接是否存活（仅供 conn() 决定复用还是向 RedisPool 取新连接）。
     * 不新建连接：$this->connection 为空直接 false；ping 抛异常（连接被服务端掐断）也归一为 false。
     * @return bool
     */
    public function ping(): bool
    {
        try {
            if (!$this->connection) return false;
            return $this->connection->ping() !== false;
        } catch (\Throwable $e) { return false; }
    }

    // ==================== Hash 操作 ====================

    /** @return mixed */
    public function hGet(string $key, string $field)
    {
        $redis = $this->conn(); if (!$redis) return null;
        try { $v = $redis->hGet($this->prefix . $key, $field); return $v === false ? null : unserialize($v, ['allowed_classes' => false]); } finally { $this->release(); }
    }

    /**
     * Hash 写入（HSET）：值 serialize() 后存储；新建字段与覆盖已有字段都算成功（phpredis 返回 0/1 均非 false）。
     * @param string $key   Hash 键（不含前缀）
     * @param string $field 字段名（原样，不加前缀）
     * @param mixed  $value 任意可序列化值
     * @return bool 连接不可用或命令失败返回 false
     */
    public function hSet(string $key, string $field, $value): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->hSet($this->prefix . $key, $field, serialize($value)) !== false; } finally { $this->release(); }
    }

    /**
     * Hash 整取（HGETALL）：所有字段值逐个 unserialize(['allowed_classes'=>false])，与 hSet 的序列化对称。
     * @param string $key Hash 键（不含前缀）
     * @return array<string,mixed> field=>value；键不存在或连接不可用均返回空数组
     */
    public function hGetAll(string $key): array
    {
        $redis = $this->conn(); if (!$redis) return [];
        try {
            $raw = $redis->hGetAll($this->prefix . $key);
            // 审查修复轮 C9：连接中途断开的 hGetAll 返回 false，array_map(false) 直接 TypeError——
            //   归一为空数组（与连接不可用同语义，fail-closed）。
            if (!is_array($raw)) return [];
            return array_map(function($v) { return unserialize($v, ['allowed_classes' => false]); }, $raw);
        } finally { $this->release(); }
    }

    /**
     * Hash 删字段（HDEL，可多个）。
     * @param string   $key    Hash 键（不含前缀）
     * @param string[] $fields 变参：要删除的字段名
     * @return bool 实际删除 ≥1 字段才 true；字段不存在与连接不可用同为 false
     */
    public function hDel(string $key, string ...$fields): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->hDel($this->prefix . $key, ...$fields) > 0; } finally { $this->release(); }
    }

    // ==================== List 操作 ====================

    /**
     * List 左端入队（LPUSH）：值经 **serialize()** 后写入，与 rPop/lRange 的
     * `unserialize(..., ['allowed_classes' => false])` 读取对称（禁对象还原，防反序列化 gadget）。
     * 键自动补实例前缀 $this->prefix，调用方传逻辑键名。
     * ⚠ 无 TTL 参数：入队不会给键设过期，长驻列表要靠调用方另行 expire，否则会一直占内存。
     * @param string $key   List 键（不含前缀）
     * @param mixed  $value 队元素（会被 serialize，标量/数组皆可；资源/闭包等不可序列化对象会抛异常）
     * @return int|false 写入后的列表长度（phpredis 返回的计数）；连接不可用返回 FALSE
     */
    public function lPush(string $key, $value)
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->lPush($this->prefix . $key, serialize($value)); } finally { $this->release(); }
    }

    /** @return mixed */
    public function rPop(string $key)
    {
        $redis = $this->conn(); if (!$redis) return null;
        try { $v = $redis->rPop($this->prefix . $key); return $v === false ? null : unserialize($v, ['allowed_classes' => false]); } finally { $this->release(); }
    }

    /**
     * List 区间读取（LRANGE）：返回值逐个 unserialize，与 lPush 的 serialize 写入对称。
     * @param string $key   键（不含前缀）
     * @param int    $start 起始下标（支持负数，闭区间）
     * @param int    $stop  结束下标（支持 -1 表示末尾，闭区间）
     * @return array 元素列表；空区间、键不存在、连接不可用均为空数组
     */
    public function lRange(string $key, int $start, int $stop): array
    {
        $redis = $this->conn(); if (!$redis) return [];
        try {
            $raw = $redis->lRange($this->prefix . $key, $start, $stop);
            // 审查修复轮 C9：同 hGetAll——中途断开返回 false 时 array_map(false) 会 TypeError，归一空数组
            if (!is_array($raw)) return [];
            return array_map(function($v) { return unserialize($v, ['allowed_classes' => false]); }, $raw);
        } finally { $this->release(); }
    }

    /**
     * List 长度（LLEN）。
     * @param string $key 键（不含前缀）
     * @return int 键不存在为 0，连接不可用也返回 0，两者不可区分
     */
    public function lLen(string $key): int
    {
        $redis = $this->conn(); if (!$redis) return 0;
        try { return $redis->lLen($this->prefix . $key); } finally { $this->release(); }
    }

    // ==================== Set 操作 ====================

    /**
     * Set 添加成员（SADD，可变参多个成员）。
     * ⚠ 集合族（sAdd/sRem/sIsMember/sMembers）的口径是**成员原样存取、不做 serialize**，
     *   与同类的 lPush/zAdd（写入时 serialize）不一致 ⇒ 成员只适合标量/字符串；
     *   存数组对象再用 sMembers 读回会拿到 phpredis 强转出来的字符串，读不回原结构。
     * 键自动补实例前缀 $this->prefix。
     * @param string $key       Set 键（不含前缀）
     * @param mixed  ...$members 变参：待加成员（原值传递，成员须是字符串可表达的值）
     * @return int|false 实际新增的成员数（已存在的成员不计）；连接不可用返回 FALSE；
     *                   「全部重复」与「连接不可用」在真假上都是 0/false 同形，判成败要用 `=== false`
     */
    public function sAdd(string $key, ...$members)
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->sAdd($this->prefix . $key, ...$members); } finally { $this->release(); }
    }

    /**
     * Set 全成员读取（SMEMBERS）。集合操作族（sAdd/sRem/sIsMember/本函数）成员一律**原样存取、不经
     * serialize/unserialize**，族内自洽，但成员只适合标量/字符串。
     * @param string $key 键（不含前缀）
     * @return array 成员列表；键不存在与连接不可用均为空数组
     */
    public function sMembers(string $key): array
    {
        $redis = $this->conn(); if (!$redis) return [];
        try {
            $raw = $redis->sMembers($this->prefix . $key);
            // 审查修复轮 C9：中途断开返回 false 会撞 `: array` 返回类型 TypeError，归一空数组
            return is_array($raw) ? $raw : [];
        } finally { $this->release(); }
    }

    /**
     * 成员判定（SISMEMBER），成员按原值比较（不做 serialize）。
     * @param string $key    键（不含前缀）
     * @param mixed  $member 待查成员
     * @return bool 「不是成员」与「连接不可用」同为 false，不可区分
     */
    public function sIsMember(string $key, $member): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->sIsMember($this->prefix . $key, $member); } finally { $this->release(); }
    }

    /**
     * Set 移除成员（SREM，可多个），成员原值传递（与 sAdd 同口径，不 serialize）。
     * @param string $key      键（不含前缀）
     * @param mixed  ...$members 变参：待删成员
     * @return bool 实际移除 ≥1 成员才 true；成员不存在与连接不可用同为 false
     */
    public function sRem(string $key, ...$members)
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->sRem($this->prefix . $key, ...$members); } finally { $this->release(); }
    }

    // ==================== Sorted Set 操作 ====================

    /**
     * ZSet 添加/更新成员分值（ZADD）：**member 经 serialize() 后写入**，score 用浮点原值。
     * 同一 member 重复写入即改分（phpredis 语义），所以本函数可当 upsert 用。
     * ⚠ 与读取侧不对称，接手最容易踩：zRange/zRevRange **不做 unserialize**（返回的是序列化后的原字符串），
     *   zRem 也是**原值传递**——不给同一个串做 serialize 就删不中（详见这两处注释）。
     *   要按分值取回原结构，得调用方自己 unserialize(..., ['allowed_classes' => false])。
     * 键自动补实例前缀 $this->prefix；无 TTL 参数，榜单类键请另行 expire 或走 clearByPrefix。
     * @param string $key    ZSet 键（不含前缀）
     * @param float  $score  分值（排序权重，声明为 float，非数值串会在传参时就报错）
     * @param mixed  $member 成员（写入前 serialize；不可序列化对象会抛异常）
     * @return int|bool|false 新增成员返回 1、仅更新分值返回 0（phpredis 形态）；连接不可用返回 FALSE
     */
    public function zAdd(string $key, float $score, $member)
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->zAdd($this->prefix . $key, $score, serialize($member)); } finally { $this->release(); }
    }

    /**
     * ZSet 升序区间（ZRANGEBYSCORE 之外的 ZRANGE，按 score 从小到大）。
     * ⚠ 不对称：zAdd 写入时对 member 做了 serialize()，本函数不做 unserialize()，
     *   返回的 member 是序列化后的原始字符串形态（withScores=true 时为 member=>score 映射）。
     * @param string $key        键（不含前缀）
     * @param int    $start      起始下标（闭区间，支持负数）
     * @param int    $stop       结束下标（闭区间）
     * @param bool   $withScores 是否附带分值
     * @return array 结果列表；键不存在与连接不可用均为空数组
     */
    public function zRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        $redis = $this->conn(); if (!$redis) return [];
        try { return $redis->zRange($this->prefix . $key, $start, $stop, $withScores); } finally { $this->release(); }
    }

    /**
     * ZSet 降序区间（ZREVRANGE，榜单常用形态），member 不经 unserialize（同 zRange 的不对称口径）。
     * @param string $key        键（不含前缀）
     * @param int    $start      起始下标（闭区间）
     * @param int    $stop       结束下标（闭区间）
     * @param bool   $withScores 是否附带分值
     * @return array 结果列表；键不存在与连接不可用均为空数组
     */
    public function zRevRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        $redis = $this->conn(); if (!$redis) return [];
        try { return $redis->zRevRange($this->prefix . $key, $start, $stop, $withScores); } finally { $this->release(); }
    }

    /**
     * ZSet 移除成员（ZREM，可多个）。
     * ⚠ 成员原值传递、不 serialize，而 zAdd 存的是 serialize() 后的 member——除非调用方自行同形态传参，
     *   否则永远删不中（0 > 0 = false）。
     * @param string $key       键（不含前缀）
     * @param mixed  ...$members 变参：待删成员
     * @return bool 实际移除 ≥1 才 true；未删中与连接不可用同为 false
     */
    public function zRem(string $key, ...$members)
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->zRem($this->prefix . $key, ...$members); } finally { $this->release(); }
    }

    // ==================== Bitmap ====================

    /**
     * Bitmap 置位（SETBIT）：把键对应字符串的第 $offset 位写成 0/1，返回**改写前的旧位值**。
     * $value 是布尔，底层按 `? 1 : 0` 落整数，所以调用方不用自己换算 0/1。
     * 常见用法是按 uid/日期位做「今日活跃/是否签到」的位图统计（配 bitCount 出总量）。
     * ⚠ 语义坑（三类同形，判不出区别）：
     *  - 位原本是 0 ⇒ 返回 false，与「连接不可用」的 false 同形；
     *  - 偏移很大会把字符串**撑到 offset/8 字节**（Redis 上限 512MB），传用户可控的 offset 前先钳范围，
     *    否则一个请求就能写出巨型键（内存型拒绝服务）；
     *  - 键原本不存在也照样成功（先建空串再置位）。
     * 键自动补实例前缀 $this->prefix；无 TTL，位图键要靠调用方 expire 或 clearByPrefix 回收。
     * @param string $key    Bitmap 键（不含前缀）
     * @param int    $offset 位偏移（0 起，非负；声明 int，负数按 Redis 错误处理）
     * @param bool   $value  目标位值：TRUE 置 1，FALSE 置 0
     * @return bool 改写前的旧位值（true 表示原本是 1）；连接不可用同样返回 FALSE
     */
    public function setBit(string $key, int $offset, bool $value): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->setBit($this->prefix . $key, $offset, $value ? 1 : 0); } finally { $this->release(); }
    }

    /**
     * Bitmap 单位读取（GETBIT）。
     * @param string $key    键（不含前缀）
     * @param int    $offset 位偏移
     * @return bool 位为 1 返回 true；键不存在、位为 0、连接不可用均为 false
     */
    public function getBit(string $key, int $offset): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->getBit($this->prefix . $key, $offset) === 1; } finally { $this->release(); }
    }

    /**
     * Bitmap 置位统计（BITCOUNT，整串范围）。
     * @param string $key 键（不含前缀）
     * @return int 键不存在为 0，连接不可用也返回 0
     */
    public function bitCount(string $key): int
    {
        $redis = $this->conn(); if (!$redis) return 0;
        try { return $redis->bitCount($this->prefix . $key); } finally { $this->release(); }
    }

    // ==================== HyperLogLog ====================

    /**
     * HyperLogLog 添加元素（PFADD）：用于**基数估算**（如 UV 统计），标准误差约 0.81%，
     * 键固定占用（十几 KB 级），比 Set 存全量成员省内存得多——代价是**只能计数、取不回成员**。
     * 元素按原值传入（本族不 serialize，与 Set 族同口径），内部去重由 Redis 完成。
     * 键自动补实例前缀 $this->prefix；HLL 键本身无过期，需调用方 expire 或 clearByPrefix 清理。
     * @param string $key      HLL 键（不含前缀）
     * @param array  $elements 待添加元素列表（标量数组；空数组不调用 PHP 侧报错但无意义）
     * @return bool 新增成功或集合已有变化返回 true；元素全部已存在返回 false；**连接不可用同为 false**，
     *              两者同形 ⇒ 不要用返回值判「链路是否健康」（探活请走 ping）
     */
    public function pfAdd(string $key, array $elements): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try { return $redis->pfAdd($this->prefix . $key, $elements); } finally { $this->release(); }
    }

    /**
     * HyperLogLog 基数估算（PFCOUNT，可多键合并去重，标准误差约 0.81%）。键逐个加前缀。
     * @param string ...$keys 变参：HLL 键（不含前缀）
     * @return int 空/键不存在为 0，连接不可用也返回 0
     */
    public function pfCount(string ...$keys): int
    {
        $redis = $this->conn(); if (!$redis) return 0;
        try { $pf = array_map(function($k) { return $this->prefix . $k; }, $keys); return $redis->pfCount(...$pf); } finally { $this->release(); }
    }

    // ==================== 分布式锁 ====================

    /** @var array key=>token 本请求持有的锁（释放时用它做所有权校验） */
    private $lockTokens = [];

    /**
     * 加锁：SET key token NX EX ttl。token 唯一，释放时只删自己的锁。
     * （原实现写死值 1 且 unlock 无条件 DEL——锁 TTL 过期后如另一进程取得，本进程
     *   unlock 会误删他人锁，破坏互斥。）
     */
    public function lock(string $key, int $ttl = 10): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try {
            $token = bin2hex(random_bytes(8));
            $ok = (bool)$redis->set($this->prefix . 'lock:' . $key, $token, ['NX', 'EX' => $ttl]);
            if ($ok) $this->lockTokens[$key] = $token;
            return $ok;
        } finally { $this->release(); }
    }

    /**
     * 解锁：仅当当前值等于本请求 token 时删除（GET+compare+DEL）。
     * 说明：GET 与 DEL 之间理论上有极小竞态窗口；本进程持有的 token 保证绝大多数
     * “TTL 过期被他人接管”场景不会误删。需要严格原子可用固定 Lua CAS，但项目已移除
     * 任意 eval 面，此处保守沿用无 eval 实现。
     */
    public function unlock(string $key): bool
    {
        $redis = $this->conn(); if (!$redis) return false;
        try {
            $token = $this->lockTokens[$key] ?? '';
            if ($token === '') return false;
            $k = $this->prefix . 'lock:' . $key;
            $cur = $redis->get($k);
            if ($cur === false || $cur !== $token) { unset($this->lockTokens[$key]); return false; }
            $r = $redis->del($k);
            unset($this->lockTokens[$key]);
            return $r > 0;
        } finally { $this->release(); }
    }

    // ==================== SCAN 清理 ====================
    // 上线前审计修复（I-08）：移除 RedisDriver::eval 死代码（全仓无调用点），
    // 收窄任意 Lua 脚本执行面（Redis eval 是危险原语，按需再加）。

    /**
     * 按前缀批量清键：用 SCAN 游标（每跳 count 100）匹配 `<实例前缀><$prefix>星号` 的键，
     * 命中即成批 DEL，累加返回**删除的键数**。
     * 刻意用 SCAN 而不是 KEYS：KEYS 是 O(N) 阻塞命令，会卡住整个实例（大库上等同拒绝服务），
     * 别把它换回去。
     * ⚠ 边界与风险：
     *  - $prefix 是被拼进 **glob 模式**的，含 `*`、`?`、方括号的入参会扩大匹配范围 ⇒
     *    调用方只能传字面前缀，用户可控值必须先过滤，否则一次调用能删穿别人的键；
     *  - 只在本实例 `$this->prefix`（cachepre）命名空间内生效，不会跨前缀误删；
     *  - 键量大时仍要跑多轮 SCAN（非原子），期间新写入的同前缀键可能被删也可能漏删；
     *  - 连接不可用返回 0，与「确实没有匹配键」同形，调用方分不出失败。
     * ⚠ 当前全仓**无调用点**（后台清缓存走 MultiLayerCache/StaticCache 一侧），保留作运维入口；
     *    接进自动流程前请先确认上面的 glob 与无原子性两点。
     * @param string $prefix 待清理的键前缀（不含实例前缀；须是字面串，勿含 glob 通配符）
     * @return int 实际删除的键数；连接不可用为 0
     */
    // ==================== 集群支持 ====================

    }
