<?php
/**
 * 缓存入口 — 前向兼容包装
 * 保留原有函数接口，新增 MultiLayerCache 多层缓存支持
 */

// 新缓存组件（始终加载 — 多层缓存配置独立于主 cache.type，需要所有驱动）
include_once __DIR__ . '/CacheInterface.php';
include_once __DIR__ . '/StaticCache.php';
include_once __DIR__ . '/ApcuDriver.php';
include_once __DIR__ . '/YacDriver.php';
include_once __DIR__ . '/RedisDriver.php';
include_once __DIR__ . '/MemcachedDriver.php';
include_once __DIR__ . '/pool/RedisPool.php';
include_once __DIR__ . '/pool/MemcachedPool.php';
// 注：cache_mysql.php（MySQL 持久降级层）**不在此清单**——它由 core.php 的「传统缓存驱动加载」段
// （include CORE_PATH . 'cache/cache_mysql.php'）在 cache.php
// 之后、cache_multi_init() 之前加载（该处注释即「MultiLayerCache MySQL 层依赖 cache_mysql 类」）。
// 排查记录：曾误判此处漏加载并补了一条 include_once，导致与 core.php 的 include
// 撞成「Cannot redeclare class cache_mysql」（任务队列消费路径立即炸）。真正原因是我当时的
// 探针绕过了 core.php 直接 include cache.php，属**观测方式错误**，代码本无缺陷。**请勿再加回。**
include_once __DIR__ . '/MultiLayerCache.php';

/**
 * 创建多层缓存管理器
 * @param array $layerConfig 层级配置
 * @param array $options     选项
 * @return MultiLayerCache
 */

/**
 * 单层缓存工厂（原有接口，兼容旧代码）
 */

function cache_new($cacheconf) {
	if ($cacheconf && !empty($cacheconf['enable'])) {
		$type = $cacheconf['type'] ?? 'mysql';
		switch ($type) {
			case 'redis':
				return new RedisDriver($cacheconf['redis']);
			case 'memcached':
				return new MemcachedDriver($cacheconf['memcached']);
			case 'apcu':
				return function_exists('apcu_fetch') ? new ApcuDriver($cacheconf['apcu']) : null;
			case 'pdo_mysql':
			case 'mysql':
				$cfg = is_array($cacheconf['mysql'] ?? null) ? $cacheconf['mysql'] : array();
				// 缓存无独立 db 配置时复用全局连接，避免 db_new 因缺 type 报错
				empty($cfg['db']) && !empty($_SERVER['db']) and $cfg['db'] = $_SERVER['db'];
				return new cache_mysql($cfg);
			case 'yac':
				return extension_loaded('yac') ? new YacDriver($cacheconf['yac']) : null;
			default:
				return xn_error(-1, '不支持的 cache type:' . $type);
		}
	}
	return NULL;
}
/** @var MultiLayerCache|null 全局多层缓存实例 */
$g_multi_cache = null;

/**
 * 初始化多层缓存（自动从配置加载）
 */
function cache_multi_init(): ?MultiLayerCache
{
    global $conf;
    try {
        if (empty($conf['cache']['multi']['enable'])) return null;
        $cfg = $conf['cache']['multi'];
        $layers = $cfg['layers'] ?? [];
        if (empty($layers)) return null;
        return new MultiLayerCache($layers);
    } catch (\Throwable $e) {
        xn_log("MultiLayerCache init error: " . $e->getMessage(), 'cache_error');
        return null;
    }
}

/**
 * 获取缓存（保留原接口，自动使用多层缓存）
 */
function _cache_key($k, $c = NULL) {
    $cache = isset($_SERVER['cache']) ? $_SERVER['cache'] : null;
    $c = $c ? $c : $cache;
    if (!$c) return [null, null];
    $k = $c->cachepre . $k;
    strlen($k) > 32 AND $k = md5($k);
    return [$c, $k];
}

/**
 * 读缓存（前向兼容接口）：多层缓存启用时恒走 MultiLayerCache::get（$c 参数被忽略）；
 * 单驱动模式经 _cache_key 拼前缀（超 32 字符取 md5）后读 $c 或 $_SERVER['cache']。
 *
 * @param string       $k 缓存键（不含驱动 cachepre）
 * @param object|null  $c 单驱动模式的缓存驱动实例；NULL 用全局默认驱动
 * @return mixed 命中值；未命中或缓存未装载返回 NULL
 */
function cache_get($k, $c = NULL) {
    global $g_multi_cache;
    if ($g_multi_cache !== null) return $g_multi_cache->get($k);
    list($c, $k) = _cache_key($k, $c);
    if (!$c) return null;
    return $c->get($k);
}

/**
 * 设置缓存（保留原接口，自动使用多层缓存）
 */
function cache_set($k, $v, $life = 0, $c = NULL) {
    global $g_multi_cache;
    if ($g_multi_cache !== null) return $g_multi_cache->set($k, $v, $life);
    list($c, $k) = _cache_key($k, $c);
    if (!$c) return null;
    return $c->set($k, $v, $life);
}

/**
 * 删除缓存（保留原接口）
 */
function cache_delete($k, $c = NULL) {
    global $g_multi_cache;
    if ($g_multi_cache !== null) return $g_multi_cache->delete($k);
    list($c, $k) = _cache_key($k, $c);
    if (!$c) return null;
    return $c->delete($k);
}

/**
 * 批量获取（R7）：多层缓存走 facade::getMultiple（每层一次 MGET/getMulti/multi-fetch）；
 * 单驱动模式回退逐键（语义一致）。
 * @return array<string,mixed> key=>value（未命中 null）
 */
/**
 * 批量写入（R7）
 */
/**
 * 批量删除（R7）
 */
/**
 * 读取「跨进程共享层」值（内容缓存失效专项）：跳过 static/apcu/yac，
 * 直接读 redis/memcached/mysql——用于内容版本令牌（APCu 是各 FPM worker 独立的，看不到别的 worker 写入）。
 * 单驱动模式回退 cache_get（语义一致）。
 * @return mixed 未命中 null
 */
function cache_shared_get($k) {
    global $g_multi_cache;
    if ($g_multi_cache !== null && method_exists($g_multi_cache, 'sharedGet')) return $g_multi_cache->sharedGet($k);
    return cache_get($k);
}

/**
 * 基于缓存驱动的分布式锁（R7）：多层缓存优先 Redis `SET NX EX` + 唯一 token CAS 释放；
 * 无 Redis 驱动时退回 xn_lock_start 文件锁（仅单节点有效）。
 * 用法：if (cache_lock('k', 30)) { try { ... } finally { cache_unlock('k'); } }
 */
/**
 * 释放 cache_lock() 取得的分布式锁（配对使用，建议放 finally）。
 * 多层模式经 MultiLayerCache::unlock（Redis 下仅删自己 token 的键，CAS 语义，不会误释放他人锁）；
 * 无多层时退回 xn_lock_end 删本机文件锁。
 *
 * @param string $key 加锁时使用的键
 * @return mixed 多层模式为 bool（是否真正释放）；文件锁模式无返回值（NULL）
 */
/**
 * 清空整个缓存空间（后台「设置→缓存」与维护动作使用）。
 * 多层模式调 MultiLayerCache::clear()（$c 被忽略）；单驱动模式兼容新版 clear() 与旧版 truncate()。
 * 注意：这是逻辑清空，勿与运维口径「删 runtime/cache/* 文件」混淆——后者会清掉熔断/并发闸状态文件（见 CLAUDE.md「开发约定·缓存」）。
 *
 * @param object|null $c 单驱动模式的缓存驱动实例；NULL 用 $_SERVER['cache'] 默认驱动
 * @return bool|null 驱动清空结果；缓存未装载或驱动无清空方法时返回 NULL
 */
function cache_truncate($c = NULL) {
	global $g_multi_cache;
	// 多层缓存模式：使用 clear()
	if ($g_multi_cache !== null) {
		return $g_multi_cache->clear();
	}
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return null;
	// 新版驱动使用 clear()，旧版驱动可能使用 truncate()
	$r = method_exists($c, 'clear') ? $c->clear() : (method_exists($c, 'truncate') ? $c->truncate() : null);
	return $r;
}

