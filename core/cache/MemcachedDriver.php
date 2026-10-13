<?php
/**
 * Memcached 缓存驱动
 * 基于 Memcached 扩展的标准键值操作
 */
class MemcachedDriver implements CacheInterface
{
    /** @var MemcachedPool|null */
    private $pool = null;
    /** @var Memcached|null */
    private $connection = null;
    /** @var string */
    private $prefix;
    /** @var bool 清缓存时是否 flush 整个 memcached 实例（默认关：共享实例上会误伤他人，见 clear()） */
    private $flushOnClear = false;

    public function __construct(array $config = [])
    {
        $this->prefix = $config['cachepre'] ?? '';
        $this->flushOnClear = !empty($config['flush_on_clear']);
        $this->pool   = new MemcachedPool($config, $config['persistent_id'] ?? '');
    }

    /** @return string 驱动名标识（'memcached'，工厂/熔断层按此键选路与记日志） */
    public function getName(): string { return 'memcached'; }

    /**
     * 物理键归一：memcached 协议键上限 **250 字节**，超长键的所有操作都会以结果码
     * `RES_BAD_KEY_PROVIDED`(33) 失败——而 33 既不是 SUCCESS 也不是 NOTFOUND ⇒ 被上层判成
     * 「驱动故障」：set 触发熔断、delete 触发熔断并**入 pending 补删**，冷却后半开补删同一坏键
     * 又失败 ⇒ 重新熔断+重新入队，退化成**永久自锁环**（复核真机实测：251 字节键三种操作全 false/码 33）。
     * 可达键：`KeywordService` 的键内嵌 `$_SERVER['HTTP_HOST']`（客户端可控，Host 超 ~204 字节即越限）。
     * 归一策略：`prefix + key` 超 250 时保留 prefix 与 key 前缀，尾部换成 md5(key)（确定性、无状态、
     * 跨进程一致；碰撞概率可忽略）。这样超长键照常读写，不再产生「坏键」。
     * @param string $key 逻辑键
     * @return string 物理键（长度 ≤ 250 字节）
     */
    private function physicalKey(string $key): string
    {
        $k = $this->prefix . $key;
        if (strlen($k) <= 250) return $k;
        // 250 = 前缀 + 分隔 + 32 位 md5；前缀本身超长时截断，保证总额不超限
        $suffix = '_' . md5($key);
        $head = substr($this->prefix, 0, max(0, 250 - strlen($suffix)));
        return $head . $suffix;
    }

    /** @return Memcached|null */
    private function conn()
    {
        if ($this->connection) return $this->connection;
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

    /**
     * 读单键（带 prefix）。
     * ⚠ 故障归一修复（审查）：旧实现只把 RES_NOTFOUND 归 miss，连接失败/超时等其它错误码
     *   会返回扩展给的 false ⇒ 上层 MultiLayerCache 把 false 当「合法缓存值」**命中并回填**
     *   （可回填进 apcu 且永不过期）——全站读到假值、读路径还不触发熔断。现只有
     *   RES_SUCCESS 才算命中（false 是合法缓存值，结果码可区分），其余一切归 null=miss。
     * @param string $key 逻辑键
     * @return mixed 命中值（可为 false）；未命中或任何驱动故障为 null
     */
    public function get(string $key)
    {
        $mem = $this->conn(); if (!$mem) return null;
        try {
            $k = $this->physicalKey($key);
            $val = $mem->get($k);
            return $mem->getResultCode() === \Memcached::RES_SUCCESS ? $val : null;
        } finally { $this->release(); }
    }

    /**
     * 写键值（cachepre 前缀拼接后交 Memcached 扩展序列化存储）。
     * @param string $key 逻辑键（自动加 prefix）
     * @param mixed $value 任意可序列化值
     * @param int $ttl 存活秒数；<=0 归一为 0=永不过期（memcached 语义，>30 天会被服务端按 Unix 时间戳理解）
     * @return bool 取不到池连接或 set 失败 false
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        $mem = $this->conn(); if (!$mem) return false;
        try {
            $exp = $ttl > 0 ? $ttl : 0;
            return $mem->set($this->physicalKey($key), $value, $exp);
        } finally { $this->release(); }
    }

    /**
     * 删除单键（带 prefix）。
     * ⚠ 语义修正：扩展层 delete() 在「键不存在」时也返回 false，原实现直接透传会让
     *   MultiLayerCache::delete() 把 miss 判成驱动故障并熔断 30s（与 RedisDriver::delete 同一问题）。
     *   现用**本地结果码**区分（不额外发请求）：RES_NOTFOUND 属「没有可删」= 成功，
     *   其余非 SUCCESS 结果才是真故障。miss ≠ failure 也与 StaticCache::delete 的既有约定一致。
     * @param string $key 逻辑键
     * @return bool 连接不可用或服务端报错为 false；键不存在仍为 true
     */
    public function delete(string $key): bool
    {
        $mem = $this->conn(); if (!$mem) return false;
        try {
            if ($mem->delete($this->physicalKey($key))) return true;
            $code = $mem->getResultCode();
            return $code === \Memcached::RES_NOTFOUND || $code === \Memcached::RES_SUCCESS;
        } finally { $this->release(); }
    }

    /**
     * 清空缓存。**默认不 flush 整个实例**（复核修正）：`flush()` 不受 cachepre 约束，A3 把 memcached
     * 纳入默认层级后，「设置→缓存」会连带清掉同机其它应用在该实例里的全部键（真机 memcached
     * 通常被多站点/多应用共用，属默认行为不可接受）。本站键靠 TTL 自然过期与显式 delete 失效，
     * 不清也不会读到脏值；确需整实例清空时用配置显式开启：
     *   `cache.multi.layers[memcached].config.flush_on_clear = true`（须确认实例为本站独占）。
     * @return bool TRUE=操作成功（跳过 flush 也视为成功）；无连接或 flush 失败 FALSE
     */
    public function clear(): bool
    {
        if (empty($this->flushOnClear)) return true;
        $mem = $this->conn(); if (!$mem) return false;
        try { return $mem->flush(); } finally { $this->release(); }
    }

    /**
     * 键存在性判断——实现为 get() !== null：多发一次网络往返，且值为 NULL 的键会被判不存在。
     * @param string $key 逻辑键
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 批量读（getMulti 一次往返，键经 physicalKey 归一、结果映射回原键名）。
     * ⚠ 项目审查修复：读回映射原来用 `$this->prefix . $k` 拼键，而请求用的是 `physicalKey($k)` ——
     *   键长 >250 字节时 physicalKey 会换成 `prefix+'_'+md5` 的短键，两者**不是同一个物理键**，
     *   于是批量读恒 miss（且 setMultiple 那侧更会被服务端按 RES_BAD_KEY_PROVIDED 拒掉）。
     * @param array $keys 逻辑键列表
     * @return array 原键 => 值；未命中的键值为 null；无连接时全部 null（array_fill_keys）
     */
    public function getMultiple(array $keys): array
    {
        $mem = $this->conn(); if (!$mem) return array_fill_keys($keys, null);
        try {
            $map = array();
            foreach ($keys as $k) {
                $map[$k] = $this->physicalKey($k);
            }
            $vals = $mem->getMulti(array_values($map));
            $result = [];
            foreach ($map as $k => $pk) {
                $result[$k] = isset($vals[$pk]) ? $vals[$pk] : null;
            }
            return $result;
        } finally { $this->release(); }
    }

    /**
     * 批量写（setMulti 一次往返，键经 physicalKey 归一）。
     * @param array $items 逻辑键 => 值
     * @param int $ttl 存活秒数；<=0 归一 0=永不过期（同 set）
     * @return bool 无连接或 setMulti 失败 false
     */
    public function setMultiple(array $items, int $ttl = 0): bool
    {
        $mem = $this->conn(); if (!$mem) return false;
        try {
            $prefixed = [];
            foreach ($items as $k => $v) {
                $prefixed[$this->physicalKey($k)] = $v;   // 同 get()/set()/delete() 的键口径
            }
            return $mem->setMulti($prefixed, $ttl > 0 ? $ttl : 0);
        } finally { $this->release(); }
    }

    /**
     * 批量删（deleteMulti，键加 prefix）。
     * ⚠ 返回值形态修复（审查）：php-memcached ≥3.0 的 deleteMulti 返回「键 => true|RES_*」**数组**
     *   （本机 3.4.0 实测），旧实现 `!== false` 对数组恒真 ⇒ 真实故障（连接失败等）被吞、
     *   不熔断、该删的键残留；2.x 返回 bool 无法区分 miss 与故障。
     *   现按逐键结果码判定：true=已删、RES_NOTFOUND=本就不存在，二者都是成功，其余为故障。
     * @param array $keys 逻辑键列表
     * @return bool 全部成功（含键不存在）为 true；无连接或任一结果码为故障 false；空列表短路 true
     */
    public function deleteMultiple(array $keys): bool
    {
        if (empty($keys)) return true; // 空列表短路（与 RedisDriver 同口径，省一次往返）
        $mem = $this->conn(); if (!$mem) return false;
        try {
            $prefixed = array_map(function($k) { return $this->physicalKey($k); }, $keys);
            $r = $mem->deleteMulti($prefixed);
            if (is_array($r)) {
                foreach ($r as $code) {
                    if ($code !== true && $code !== \Memcached::RES_NOTFOUND) return false;
                }
                return true;
            }
            return (bool)$r; // 2.x bool：false 保守按失败（该版本已绝迹，仅兼容分支）
        } finally { $this->release(); }
    }

    /**
     * 原子自增（服务端操作，键经 physicalKey 归一）。
     * @param string $key 逻辑键（须已存在且值为数值，否则扩展返回 false——无「不存在则初始化」语义）
     * @param int $step 增量
     * @return int|false 新值；无连接或键不可增为 false
     */
    public function increment(string $key, int $step = 1)
    {
        $mem = $this->conn(); if (!$mem) return false;
        try { return $mem->increment($this->physicalKey($key), $step); } finally { $this->release(); }
    }

    /**
     * 原子自减（服务端操作，键经 physicalKey 归一；键须已存在，同 increment 的失败语义）。
     * @param string $key 逻辑键
     * @param int $step 减量
     * @return int|false 新值；无连接或键不可减为 false
     */
    public function decrement(string $key, int $step = 1)
    {
        $mem = $this->conn(); if (!$mem) return false;
        try { return $mem->decrement($this->physicalKey($key), $step); } finally { $this->release(); }
    }

    /**
     * 健康探测：借连接发 getVersion，按结果码 RES_SUCCESS 判定服务可达。
     * 任何 Throwable（连接池异常等）吞掉返回 false。
     * ⚠ 连接泄漏已修：原实现借了 $this->conn() 却**没有** finally release()（本类其余方法都有），
     *   每 ping 一次就从池里吞掉一条连接；当前全仓无调用点（MultiLayerCache 熔断走异常计数），
     *   故未爆——一旦接入健康检查就会耗尽池。现与同族一致归还。
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $mem = $this->conn(); if (!$mem) return false;
            $vers = $mem->getVersion();
            return $mem->getResultCode() === Memcached::RES_SUCCESS;
        } catch (\Throwable $e) { return false; } finally { $this->release(); }
    }
}
