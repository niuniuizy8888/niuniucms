<?php
/**
 * 静态缓存驱动 — 单次请求内的进程级缓存
 * 特性：
 * - 基于 $static 数组，同一请求周期内避免重复读取远程缓存
 * - 请求生命周期内的缓存隔离与自动清理
 * - 零网络开销，毫秒级响应
 */
class StaticCache implements CacheInterface
{
    /** @var array */
    private static $static = [];
    /** @var int */
    private static $hit = 0;
    /** @var int */
    private static $miss = 0;

    /** @return string 驱动名标识（'static'，MultiLayerCache 层级选路用） */
    public function getName(): string { return 'static'; }

    /** @return mixed */
    public function get(string $key)
    {
        if (array_key_exists($key, self::$static)) {
            self::$hit++;
            // R7 性能项：本层是「单请求内内存」，不跨请求、不需持久化，直接按值返回即可。
            // 原实现对每次 get 做 unserialize、每次 set 做 serialize——40KB 块数组一次往返约
            // 82μs 的纯 CPU 浪费（实测），页缓存命中/块回填路径无谓放大。PHP 数组为写时复制值语义，
            // 直接存取不会产生调用方误改缓存原值的问题（标量/数组/字符串均安全）。
            return self::$static[$key];
        }
        self::$miss++;
        return null;
    }

    /** @return bool */
    public function set(string $key, $value, int $ttl = 0)
    {
        self::$static[$key] = $value;
        return true;
    }

    /**
     * 删除进程内键：unset 对不存在键无害，**恒返回 true**——刻意不用 array_key_exists
     * 判定，以免污染 MultiLayerCache::delete() 的逐层 AND 链（体内注释）。
     * @param string $key
     * @return bool 恒 true
     */
    public function delete(string $key): bool
    {
        // 删除总是安全（key 不存在时 unset 无害），始终返回 true
        // 不返回 array_key_exists() 以免污染 MultiLayerCache::delete() 的 AND 链
        unset(self::$static[$key]);
        return true;
    }

    /**
     * 清空整个进程级缓存并复位 hit/miss 统计（self::$static 是 static 属性，
     * 作用于当前 PHP 进程；不触及其他 FPM worker）。
     * @return bool 恒 true
     */
    public function clear(): bool
    {
        self::$static = [];
        self::$hit = self::$miss = 0;
        return true;
    }

    /**
     * 键存在性：array_key_exists 判定——能区分「存了 null」与「没这个键」（异于 has() 通用语义）。
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, self::$static);
    }

    /**
     * 批量读：逐键走 get()（纯内存，无网络放大问题；同步累计 hit/miss 统计）。
     * @param array $keys 键列表
     * @return array 键 => 值；未命中为 null
     */
    public function getMultiple(array $keys): array
    {
        $result = [];
        foreach ($keys as $k) {
            $result[$k] = $this->get($k);
        }
        return $result;
    }

    /**
     * 批量写：逐键 set()。**$ttl 被忽略**——进程级缓存随请求/进程结束消亡，无过期语义。
     * @param array $items 键 => 值
     * @param int $ttl 未使用
     * @return bool 恒 true
     */
    public function setMultiple(array $items, int $ttl = 0): bool
    {
        foreach ($items as $k => $v) {
            $this->set($k, $v, $ttl);
        }
        return true;
    }

    /**
     * 批量删：逐键 delete()（同 delete 的「恒 true」口径）。
     * @param array $keys 键列表
     * @return bool 恒 true
     */
    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->delete($k);
        }
        return true;
    }

    /** @return int */
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

    /** @return bool 恒 true——内存层无外部依赖，无需探测 */
    public function ping(): bool { return true; }

    /** 获取当前请求周期内的统计 */
    public static function stats(): array
    {
        return [
            'items' => count(self::$static),
            'hit'   => self::$hit,
            'miss'  => self::$miss,
            'ratio' => (self::$hit + self::$miss) > 0
                ? round(self::$hit / (self::$hit + self::$miss) * 100, 2)
                : 0,
        ];
    }
}
