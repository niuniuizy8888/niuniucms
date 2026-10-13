<?php
// hook model_block_base_start.php

/**
 * Block 统一缓存辅助函数
 * 消除 block 函数中重复的 static cache → cache_get → 计算 → cache_set 样板
 *
 * ⚠ **空结果不按正 TTL 缓存**（分类导入修复轮同源的「空结果被当正常值缓存」缺陷，实测血案）：
 *   旧实现只要回调返回的不是 null/false 就 `cache_set($key, $r, $ttl)`，而 `array()`（**空块位**）
 *   恰好不是 null/false ⇒ 空库/空分类时算出的空列表会被缓存 **3600s**：内容一入库，
 *   块位仍显示空白最长 1 小时（真机实测：采集 114 条视频后，视频页「热搜总榜」仍是空的，
 *   Redis 里 `block_vod_top_0_cid_n15 = array(0)`）。getWithSource 分支同理（`$data !== null`
 *   即按 $ttl 落盘）。现在空数组一律改记 **30s 短窗口**：既保留防击穿（与 MultiLayerCache 的
 *   `nullCacheTtl=30` 空值窗口同口径），又不会长时间遮挡新内容。
 *
 * @param string   $key   缓存键
 * @param int      $ttl   过期时间（秒），默认 3600
 * @param callable $fn    业务逻辑回调，返回数据或 null
 * @param mixed    $empty 回调返回 null/false/空数组时的返回值（null 表示原样返回 null，
 *                        array() 表示归一为空数组——注意：$empty 只管返回值，不再影响缓存时长）
 * @return mixed
 */
function block_cache(string $key, int $ttl, callable $fn, $empty = null)
{
    // hook model_block_base_block_cache_start.php


    static $cache = array();
    if (array_key_exists($key, $cache)) return $cache[$key];
    global $g_multi_cache;
    $r = cache_get($key);
    if (NULL === $r) {
        if ($g_multi_cache instanceof \MultiLayerCache && method_exists($g_multi_cache, 'getWithSource')) {
            // 防击穿：带锁回源（分布式锁 + 双查 + 退避 + 空值窗口；接线修复）
            $r = $g_multi_cache->getWithSource($key, $fn, $ttl);
            // getWithSource 会把非 null 结果按 $ttl 落盘（空数组也算非 null）⇒ 空结果改记 30s
            if (is_array($r) && $r === array()) {
                cache_set($key, $r, 30);
            }
        } else {
            $r = $fn();
            if (is_array($r) && $r === array()) {
                cache_set($key, $r, 30);            // 空结果：短窗口（防击穿，又不遮新内容）
            } elseif ($r !== null && $r !== false) {
                cache_set($key, $r, $ttl);
            }
        }
    }
    $cache[$key] = ($r !== null && $r !== false) ? $r : $empty;
    // hook model_block_base_block_cache_end.php
    return $cache[$key];
}

/**
 * 生成统一前缀的 block 缓存键
 * 自动包含 url_rewrite_on 以避免伪静态切换后的键冲突
 *
 * @param string $name  block 名称（如 'vod_top'）
 * @param string ...$parts 可变参数，键的各组成部分
 * @return string
 */
function block_key(string $name, ...$parts): string
{
    // hook model_block_base_block_key_start.php


    global $conf;
    // hook model_block_base_block_key_end.php
    return 'block_' . $name . '_' . $conf['url_rewrite_on'] . '_' . implode('_', $parts);
}
