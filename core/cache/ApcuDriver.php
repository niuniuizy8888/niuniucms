<?php
/**
 * APCu 缓存驱动 — PHP 本地共享内存缓存
 * 比 Redis 快（无网络开销），比 StaticCache 持久（跨请求）
 */
class ApcuDriver implements CacheInterface
{
    /** @var string */
    private $prefix;

    public function __construct(array $config = [])
    {
        $this->prefix = $config['cachepre'] ?? '';
    }

    /**
     * 驱动标识（MultiLayerCache 按名选层/熔断登记用）。
     * @return string 恒 'apcu'
     */
    public function getName(): string { return 'apcu'; }

    /** @return mixed */
    public function get(string $key)
    {
        if (!function_exists('apcu_fetch')) return null;
        $val = apcu_fetch($this->prefix . $key);
        return $val === false ? null : $val;
    }

    /** @return bool */
    public function set(string $key, $value, int $ttl = 0)
    {
        if (!function_exists('apcu_store')) return false;
        return apcu_store($this->prefix . $key, $value, $ttl ?: 0);
    }

    /**
     * 删除单键（带 cachepre 前缀）。
     * ⚠ 语义修正：apcu_delete() 在「键不存在」时也返回 false，原实现把 miss 与故障混为一谈，
     *   会污染 MultiLayerCache::delete() 的 AND 链（与 Redis/Memcached 层同一「miss ≠ failure」口径）。
     *   现只在「删不掉且键确实还在」时判失败；扩展缺失仍是失败（功能不可用，值得暴露）。
     *   补的 apcu_exists 只发生在 delete 已返回 false 的分支，且是进程内共享内存查询，无网络代价。
     * @param string $key 逻辑键（不带前缀）
     * @return bool 扩展缺失或键仍存在为 false；删除成功或键本就不存在为 true
     */
    public function delete(string $key): bool
    {
        if (!function_exists('apcu_delete')) return false;
        $full = $this->prefix . $key;
        return apcu_delete($full) || !apcu_exists($full);
    }

    /**
     * 清空整个 APCu 用户缓存（apcu_clear_cache 是全局动作，**不受本驱动 prefix 限制**——
     * 同机其他应用共用 APCu 时会被一并清掉，MultiLayerCache 的「清缓存」操作即走此处）。
     * @return bool 扩展缺失或清空失败返回 FALSE
     */
    public function clear(): bool
    {
        if (!function_exists('apcu_clear_cache')) return false;
        return apcu_clear_cache();
    }

    /**
     * 键存在性：以 get()!==null 判定 ⇒ 存了 NULL 值的键视为不存在（与 MultiLayerCache 上层约定一致）。
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 批量读（单进程内一次 apcu_fetch(数组) 取回，非逐键循环）。
     * @param array $keys 键列表（不带前缀）
     * @return array 原始键 => 值；未命中/扩展缺失的键以 NULL 占位（键集与入参一致）
     */
    public function getMultiple(array $keys): array
    {
        if (!function_exists('apcu_fetch')) return array_fill_keys($keys, null);
        $prefixed = array_map(function($k) { return $this->prefix . $k; }, $keys);
        $vals = apcu_fetch($prefixed);
        if (!is_array($vals)) $vals = array(); // 整体失败守卫
        $result = [];
        foreach ($keys as $k) {
            $pk = $this->prefix . $k;
            $result[$k] = array_key_exists($pk, $vals) ? $vals[$pk] : null;
        }
        return $result;
    }

    /**
     * 批量写：无原生批量接口，逐键循环 set()。
     * ⚠ 恒返回 TRUE——个别键写失败（如内存满）不向上反馈，调用方勿以返回值判可靠性。
     * @param array $items 键=>值 映射
     * @param int $ttl 存活秒数（0=永不过期）
     * @return bool 恒 TRUE
     */
    public function setMultiple(array $items, int $ttl = 0): bool
    {
        foreach ($items as $k => $v) {
            $this->set($k, $v, $ttl);
        }
        return true;
    }

    /**
     * 批量删：逐键循环 delete()（键不存在/扩展缺失的具体失败不反馈）。
     * @param string[] $keys 键列表（不带前缀）
     * @return bool 恒 TRUE
     */
    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->delete($k);
        }
        return true;
    }

    /** @return int|false */
    public function increment(string $key, int $step = 1)
    {
        if (!function_exists('apcu_inc')) return false;
        return apcu_inc($this->prefix . $key, $step);
    }

    /** @return int|false */
    public function decrement(string $key, int $step = 1)
    {
        if (!function_exists('apcu_dec')) return false;
        return apcu_dec($this->prefix . $key, $step);
    }

    /**
     * 驱动探活：仅检测 apcu_fetch 函数是否可用（扩展装载判定，不实际读写共享内存）。
     * CacheInterface 契约方法；当前仓内无生产调用点（MultiLayerCache 熔断走异常计数而非 ping）。
     * @return bool
     */
    public function ping(): bool
    {
        return function_exists('apcu_fetch');
    }
}
