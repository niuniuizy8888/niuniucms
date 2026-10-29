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
/** 请求内 KV memo 的唯一存储，写入、删除后可同步失效。 */
function &kv_request_cache()
{
    static $cache = array();
    return $cache;
}

/** 读取 KV，长键归一；请求内 memo 由 kv_set/kv_delete 同步失效。 */
function kv_get($k)
{
    // hook model_kv_kv_get_start.php


    $static =& kv_request_cache();
    strlen($k) > 32 AND $k = md5($k);
    if (!array_key_exists($k, $static)) {
        $static[$k] = kv__get($k);
    }
    // hook model_kv_kv_get_end.php
    return $static[$k];
}
/** 写数据库成功后清理请求内 memo 和共享缓存；失败返回 false，成功可返回 0。 */
function kv_set($k, $v)
{
    // hook model_kv_kv_set_start.php


    strlen($k) > 32 AND $k = md5($k);
    $arr = array(
        'k' => $k,
        'v' => xn_json_encode($v),
    );
    $r = \App\Repository\RepositoryFactory::table('kv', 'k')->replace($arr);
    if ($r !== false) {
        $memo =& kv_request_cache();
        unset($memo[$k]);
        global $g_setting, $g_website;
        if ($k === 'setting') $g_setting = false;
        if ($k === 'website') $g_website = false;
        function_exists('cache_delete') and cache_delete($k);
    }
    // hook model_kv_kv_set_end.php
    return $r;
}
/** 删除成功后清理请求内 memo、命名空间 memo 和共享缓存；缺失键删除幂等。 */
function kv_delete($k)
{
    // hook model_kv_kv_delete_start.php


    strlen($k) > 32 AND $k = md5($k);
    $r = \App\Repository\RepositoryFactory::table('kv', 'k')->delete(array('k' => $k));
    if ($r !== false) {
        $memo =& kv_request_cache();
        unset($memo[$k]);
        global $g_setting, $g_website;
        if ($k === 'setting') $g_setting = false;
        if ($k === 'website') $g_website = false;
        function_exists('cache_delete') and cache_delete($k);
    }
    // hook model_kv_kv_delete_end.php
    return$r;
}
/** 共享缓存未命中时直接回库，不使用旧 memo 回填；false、0、空串等均为合法命中。 */
function kv_cache_get($k)
{
    // hook model_kv_kv_cache_get_start.php


    strlen($k) > 32 AND $k = md5($k);
    $r = cache_get($k);
    if (NULL === $r) {
        // 共享层失效后直接回库，不能用旧的请求内副本重新填充共享层。
        $r = kv__get($k);
        $memo =& kv_request_cache();
        $memo[$k] = $r;
        if ($r !== null) cache_set($k, $r);
    }
    // hook model_kv_kv_cache_get_end.php
    return $r;
}
/** 先持久化并失效旧副本，成功后按指定 TTL 写缓存；失败不发布候选值。 */
function kv_cache_set($k, $v, $life = 0)
{
    // hook model_kv_kv_cache_set_start.php


    strlen($k) > 32 AND $k = md5($k);
    $r = kv_set($k, $v);
    if ($r !== false) cache_set($k, $v, $life);
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
    $candidate = $g_setting;
    $candidate[$k] = $v;
    // hook model_kv_setting_set_end.php
    return kv_cache_set('setting', $candidate);
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
    $candidate = $g_website;
    $candidate[$k] = $v;
    // hook model_kv_website_set_end.php
    return kv_cache_set('website', $candidate);
}
