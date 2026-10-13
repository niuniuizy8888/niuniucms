<?php
// hook model_kv_start.php
/**
 * @deprecated 兼容壳（CLAUDE.md「目录规则」）：kv 表的**裸读**——按 k 列 findOne 取一行，
 * 命中则把 v 列经 xn_json_decode 还原成 PHP 值，否则返回 NULL。
 * ⚠ 与本文件带 memo 的 kv_get() 差两件事：**本函数没有请求内 static 缓存**（每次都发一条查询，
 *   别放进循环，性能红线 §三.2），也**没有长键归一**——kv 表 k 列有宽度约束，
 *   kv_get/kv_set/kv_delete 一族对 strlen 大于 32 的键先 md5 归一后再存取，
 *   ⇒ **用原始长键直接调本函数会查不到行**（返回 NULL，看起来像「键不存在」）。
 * 解码口径沿用 xn_json_decode：先剥 BOM 再 json_decode，非法 JSON 得到 NULL
 *   （与「键不存在」同形，调用方分不出两者）。
 * 紧邻的原始注释是一条待办式说明：「如果环境支持，可以直接改为 redis get()/set() 持久存储相关 API 提高速度」
 *   ——现状仍是 MySQL kv 表，不要据此以为已经走 Redis。
 * @param string $k kv 键（须与写入侧同口径：短键传原文，长键要自己先 md5 归一，或改用 kv_get()）
 * @return mixed 解码后的值（数组/标量）；键不存在或值不是合法 JSON = NULL
 */
function kv__get($k)
{
    // hook model_kv_kv__get_start.php


    $arr = \App\Repository\RepositoryFactory::table('kv', 'k')->findOne(array('k' => $k));
    // hook model_kv_kv__get_end.php
    return $arr ? xn_json_decode($arr['v']) : NULL;
}
/**
 * 兼容壳（CLAUDE.md「目录规则」（兼容壳），kv 层实现体）：读 kv 表并 JSON 解码，带请求内 static memo。
 * 长键口径：strlen>32 先 md5 归一（kv 表 k 列宽度约束；get/set/delete 同口径，
 * 直连 kv__get 或 cache_* 层传原始长键会查不到行）。
 * ⚠ static memo 不感知其他进程/后续写入，同请求内 kv_set 后再 kv_get 读到的是旧值。
 * @param string $k 键名（超长自动 md5）
 * @return mixed 解码后的任意 JSON 值；无记录/坏 JSON ⇒ NULL
 */
function kv_get($k)
{
    // hook model_kv_kv_get_start.php


    static $static = array();
    strlen($k) > 32 AND $k = md5($k);
    if (!isset($static[$k])) {
        $static[$k] = kv__get($k);
    }
    // hook model_kv_kv_get_end.php
    return $static[$k];
}
/**
 * 兼容壳：写 kv 表（REPLACE 整行覆盖，值 JSON 编码入 v 列）。
 * 先 cache_delete 失效分布式缓存同键（kv_cache_get 读路径），长键同 kv_get 做 md5 归一。
 * ⚠ kv_get 的请求内 static memo 不在此清除——同请求内先读后写再读仍见旧值（跨请求不受影响）。
 * @param string $k 键名
 * @param mixed $v 可被 xn_json_encode 的值
 * @return int|false 透传 DbTableRepository::replace 的 lastInsertId；kv 表无自增列成功通常为 0——
 *                   **不可当成败信号**；$arr 恒非空 ⇒ 本壳实际恒为 int（false 分支到不了）
 */
function kv_set($k, $v)
{
    // hook model_kv_kv_set_start.php


    strlen($k) > 32 AND $k = md5($k);
    // 性能优化：写库后失效分布式缓存（kv_cache_get 读取路径），保证一致性
    function_exists('cache_delete') and cache_delete($k);
    $arr = array(
        'k' => $k,
        'v' => xn_json_encode($v),
    );
    $r = \App\Repository\RepositoryFactory::table('kv', 'k')->replace($arr);
    // hook model_kv_kv_set_end.php
    return $r;
}
/**
 * 兼容壳：删除 kv 表行（按 k 等值），并同步失效分布式缓存同键；长键 md5 归一。
 * ⚠ 不清 kv_get 的请求内 static memo。
 * @param string $k 键名
 * @return int|false 影响行数（0=行不存在，幂等）；cond 恒为单键数组，正常路径不会走 false 短路
 */
function kv_delete($k)
{
    // hook model_kv_kv_delete_start.php


    strlen($k) > 32 AND $k = md5($k);
    function_exists('cache_delete') and cache_delete($k);
    $r = \App\Repository\RepositoryFactory::table('kv', 'k')->delete(array('k' => $k));
    // hook model_kv_kv_delete_end.php
    return$r;
}
/**
 * 兼容壳：多级缓存优先读，NULL/FALSE 视为 miss ⇒ 回落 kv_get（DB）并回填缓存。
 * 回填条件是 truthy（`$r and cache_set`）：**falsy 值（0/''/false/空数组）不回写缓存**，
 * 这类值的键每次都会打到 DB。回填用 cache_set 默认 TTL。
 * 注：kv_get 命中路径自带 static memo，本函数不做第二层进程内 memo。
 * @param string $k 键名（透传 kv_get 的长键 md5 口径）
 * @return mixed 缓存/DB 值；均无记录 ⇒ NULL
 */
function kv_cache_get($k)
{
    // hook model_kv_kv_cache_get_start.php


    $r = cache_get($k);
    if (NULL === $r || FALSE === $r) {
        $r = kv_get($k);
        $r and cache_set($k, $r);
 }
    // hook model_kv_kv_cache_get_end.php
    return $r;
}
/**
 * 兼容壳：写缓存 + 写 kv 表。
 * ⚠ 顺序陷阱：先 cache_set($k,$v,$life)，随后 kv_set 内部又 cache_delete($k)——
 * 刚写入的缓存键立即被清，实际效果≈只落库（下次 kv_cache_get miss 再回填），
 * 一致性优先、缓存写属白付；改此函数前勿把该行为「优化」掉。
 * @param string $k 键名
 * @param mixed $v 值（JSON 可编码）
 * @param int $life 缓存 TTL 秒（0=驱动默认；见上方陷阱，当前调用下基本不生效）
 * @return int|false 透传 kv_set 的返回值（成功通常为 0，不可当布尔成败用）
 */
function kv_cache_set($k, $v, $life = 0)
{
    // hook model_kv_kv_cache_set_start.php


    cache_set($k, $v, $life);
    $r = kv_set($k, $v);
    // hook model_kv_kv_cache_set_end.php
    return $r;
}
/**
 * 兼容壳：删分布式缓存 + 删 kv 表行（kv_delete 内部还会再 cache_delete 同键，幂等重复无害）。
 * 不清 setting_get/website_get 的全局 memo——同请求内 $g_setting/$g_website 仍见旧值。
 * @param string $k 键名
 * @return int|false 透传 kv_delete：影响行数（0=无此行）
 */
$g_setting = FALSE;
/**
 * 兼容壳：setting 命名空间单键读。全局 $g_setting 为整档 memo，首访经
 * kv_cache_get('setting') 装载（miss 回落 kv 表并回填；空档视作空数组）。
 * 键缺失返回 NULL（array_value 显式传 NULL 缺省，不用其默认 ''）。
 * @param string $k setting 内键名
 * @return mixed 值；无此键=NULL
 */
function setting_get($k)
{
    // hook model_kv_setting_get_start.php


    global $g_setting;
    FALSE === $g_setting AND $g_setting= kv_cache_get('setting');
    empty($g_setting) AND $g_setting = array();
    // hook model_kv_setting_get_end.php
    return array_value($g_setting, $k, NULL);
}
/**
 * 兼容壳：setting 单键写=读整档→改内存→kv_cache_set **整档 REPLACE 回库**。
 * ⚠ 非原子：并发写不同键会互相覆盖（后写者带旧档快照丢对方更新）；
 * 现主要消费点是设置链以 setting_set('conf', $config) 整体落运行时配置
 * （ConfigRuntimeService/TemplateService/CloudOpsService 等），新键值先想清楚是否该进 conf。
 * @param string $k setting 内键名
 * @param mixed $v 值
 * @return int|false 透传 kv_cache_set（成功通常为 0）
 */
function setting_set($k, $v)
{
    // hook model_kv_setting_set_start.php


    global $g_setting;
    FALSE === $g_setting AND $g_setting = kv_cache_get('setting');
    empty($g_setting) AND $g_setting = array();
    $g_setting[$k] = $v;
    // hook model_kv_setting_set_end.php
    return kv_cache_set('setting', $g_setting);
}
/**
 * 兼容壳：setting 命名空间删键（全局 memo 里 unset 后整档写回）。
 * ⚠ 恒返 TRUE——kv_cache_set 的落库成败被吞掉，不能据此判断删除成功。
 * @param string $k setting 内键名（不存在也返回 TRUE，幂等）
 * @return true
 */
$g_website = FALSE;
/**
 * 兼容壳：website 命名空间单键读。全局 $g_website 为整档 memo，首访经
 * kv_cache_get('website') 装载（空档视作空数组）。键缺失返回 NULL。
 * 现主要消费点如 grouplist 缓存回写（GroupService::listCache*），整档存储。
 * @param string $k website 内键名
 * @return mixed 值；无此键=NULL
 */
function website_get($k)
{
    // hook model_kv_website_get_start.php


    global $g_website;
    FALSE === $g_website AND $g_website = kv_cache_get('website');
    empty($g_website) AND $g_website = array();
    // hook model_kv_website_get_end.php
    return array_value($g_website, $k, NULL);
}
/**
 * 兼容壳：website 单键写=读整档→改内存→kv_cache_set 整档 REPLACE 回库。
 * 与 setting_set 同款非原子覆盖风险（整档快照写回），group 列表缓存等大块数据走此路径。
 * @param string $k website 内键名
 * @param mixed $v 值
 * @return int|false 透传 kv_cache_set（成功通常为 0，不可当布尔成败用）
 */
function website_set($k, $v)
{
    // hook model_kv_website_set_start.php


    global $g_website;
    FALSE === $g_website AND $g_website = kv_cache_get('website');
    empty($g_website) AND $g_website = array();
    $g_website[$k] = $v;
    // hook model_kv_website_set_end.php
    return kv_cache_set('website', $g_website);
}
