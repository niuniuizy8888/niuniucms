<?php
/**
 * 缓存驱动统一接口
 * 所有缓存驱动必须实现此接口，确保上层调用与底层驱动解耦
 */
interface CacheInterface
{
    /** @return mixed */
    public function get(string $key);
    /** @return bool */
    public function set(string $key, $value, int $ttl = 0);
    /** @return bool */
    public function delete(string $key);
    /** @return bool */
    public function clear();
    /** @return bool */
    public function has(string $key);
    /** @return array */
    public function getMultiple(array $keys);
    /** @return bool */
    public function setMultiple(array $items, int $ttl = 0);
    /** @return bool */
    public function deleteMultiple(array $keys);
    /** @return int|false */
    public function increment(string $key, int $step = 1);
    /** @return int|false */
    public function decrement(string $key, int $step = 1);
    /** @return bool */
    public function ping();
    /** @return string */
    public function getName();
}
