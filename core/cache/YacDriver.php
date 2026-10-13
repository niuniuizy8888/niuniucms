<?php
/**
 * YAC 缓存驱动 — PHP 本地共享内存缓存
 * Yac（Yet Another Cache）是基于共享内存的键值存储，无网络开销
 * 适用于同一进程池内的数据共享
 */
class YacDriver implements CacheInterface
{
    /** @var \Yac|null */
    private $yac = null;
    /** @var string */
    private $prefix;

    public function __construct(array $config = [])
    {
        $this->prefix = $config['cachepre'] ?? '';
    }

    /**
     * 驱动标识名（CacheInterface 契约），恒返回 'yac'。
     */
    public function getName(): string { return 'yac'; }

    /**
     * 懒建 Yac 实例（带本驱动 prefix）：实例非空直接复用；yac 扩展未装载返回 FALSE（各读写方法据此降级为未命中/失败）。
     * @return bool TRUE=yac 可用；FALSE=扩展缺失
     */
    private function connect(): bool
    {
        if ($this->yac !== null) return true;
        if (!extension_loaded('yac')) return false;
        $this->yac = new \Yac($this->prefix);
        return true;
    }

    /** @return mixed */
    public function get(string $key)
    {
        if (!$this->connect()) return null;
        $val = $this->yac->get($key);
        return $val === false ? null : $val;
    }

    /** @return bool */
    public function set(string $key, $value, int $ttl = 0)
    {
        if (!$this->connect()) return false;
        return $this->yac->set($key, $value, $ttl ?: 0);
    }

    /**
     * 删除单键 —— 转发 Yac::delete()；yac 扩展不可用返回 FALSE。
     * ⚠ miss 与 failure 不分：按 yac 扩展源码（yac_storage_delete 对空槽 return 0），
     *   键不存在时同样返回 false——与 redis/apcu/memcached 三驱动的「miss≠failure」口径不同。
     *   本驱动不在默认层级、也不在 MultiLayerCache 熔断白名单（仅 redis/memcached），
     *   故 miss→false 最多污染 delete 的 AND 链返回值，不会误熔断。
     * @param string $key 键名
     * @return bool true=已删除；false=键不存在（miss）或扩展不可用或删除失败
     */
    public function delete(string $key): bool
    {
        if (!$this->connect()) return false;
        return $this->yac->delete($key);
    }

    /**
     * 清空本实例 prefix 下的全部条目 —— 转发 Yac::flush()（共享内存级操作，影响同 prefix 的其他进程）。
     * @return bool 是否成功；扩展不可用返回 FALSE
     */
    public function clear(): bool
    {
        if (!$this->connect()) return false;
        return $this->yac->flush();
    }

    /**
     * 存在性判定 —— 走 get()：把 Yac 未命中的 FALSE 归一为 NULL 后比较，等价「值非 NULL 即存在」。
     * 注意存入 NULL 会被判为不存在；批量场景勿循环调用（每次一回读）。
     * @param string $key 键名
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 批量读 —— 单次 Yac::get(数组)。扩展不可用或 Yac 返回值非数组时整体降级为「键=>null」映射。
     * @param array $keys 键列表
     * @return array 键值映射；未命中键的形态以 Yac 扩展返回为准，调用方对缺键保持容错
     */
    public function getMultiple(array $keys): array
    {
        if (!$this->connect()) return array_fill_keys($keys, null);
        $result = $this->yac->get($keys);
        return is_array($result) ? $result : array_fill_keys($keys, null);
    }

    /**
     * 批量写 —— 逐键调 set()（无原生批量接口），失败不中断、全部尝试完再汇总。
     * @param array $items 键=>值 映射
     * @param int $ttl 过期秒数（0=不过期，沿用 set 口径）
     * @return bool 所有键均写成功才 TRUE
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
     * 批量删 —— 逐键调 delete()（无原生批量接口），失败不中断、全部尝试完再汇总。
     * @param array $keys 键列表
     * @return bool 所有键均删成功才 TRUE
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
     * 递增（read-modify-write，非原子）
     * Yac 无原生原子递增，多进程并发时可能丢失更新。
     * 适用于低竞争场景（热度统计等），高精度计数推荐 RedisDriver。
     * @return int
     */
    public function increment(string $key, int $step = 1)
    {
        $val = $this->get($key);
        if ($val === null) $val = 0;
        $val += $step;
        $this->set($key, $val);
        return $val;
    }

    /** @return int */
    public function decrement(string $key, int $step = 1)
    {
        return $this->increment($key, -$step);
    }

    /**
     * 可用性探测 —— 仅检查 yac 扩展是否装载，不做真实读写往返。
     * @return bool
     */
    public function ping(): bool
    {
        return extension_loaded('yac');
    }
}
