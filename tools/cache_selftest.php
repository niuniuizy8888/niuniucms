<?php
// CLI 回归：只使用内存替身，不读取站点配置或写入站点数据库。
namespace App\Repository {
    class RepositoryFactory {
        public static $rows = array();
        public static $fail = false;
        public static function table($table, $key) { return new self(); }
        public function findOne($cond) { return self::$rows[$cond['k']] ?? null; }
        public function replace($row) { if (self::$fail) return false; self::$rows[$row['k']] = $row; return 0; }
        public function delete($cond) { if (self::$fail) return false; unset(self::$rows[$cond['k']]); return 1; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
    define('DEBUG', 0);
    define('APP_PATH', dirname(__DIR__) . '/');
    define('CORE_PATH', APP_PATH . 'core/');
    require APP_PATH . 'core/cache/CacheInterface.php';
    require APP_PATH . 'core/cache/StaticCache.php';
    require APP_PATH . 'core/cache/MultiLayerCache.php';
    require APP_PATH . 'core/func/misc.php';
    require APP_PATH . 'core/model/kv.php';
    require APP_PATH . 'core/model/runtime.php';
    $conf = array('cache_path' => sys_get_temp_dir() . '/nncms-cache-test-' . bin2hex(random_bytes(6)) . '/');
    mkdir($conf['cache_path'], 0700, true);
    function _SERVER($k, $default = null) { return $k === 'conf' ? $GLOBALS['conf'] : $default; }
    function array_value($arr, $k, $default = '') { return $arr[$k] ?? $default; }
    function cache_get($k) { return $GLOBALS['suiteCache']->get($k); }
    function cache_set($k, $v, $ttl = 0) { return $GLOBALS['suiteCache']->set($k, $v, $ttl); }
    function cache_delete($k) { return $GLOBALS['suiteCache']->delete($k); }
    function cache_shared_get($k) { return $GLOBALS['suiteCache']->sharedGet($k); }
    function user_count() { return 1; }
    function video_count() { return 0; }
    function video_count_day() { return 0; }
    function art_count() { return 0; }
    function art_count_day() { return 0; }
    function online_count() { return 1; }
    function art_del() {}
    function vod_del() {}

    class TestLayer implements \CacheInterface {
        public $rows = array();
        public $ttls = array();
        public $writes = 0;
        public $reads = 0;
        private $name;
        public function __construct($name) { $this->name = $name; }
        public function getName() { return $this->name; }
        public function get(string $key) { ++$this->reads; return $this->rows[$key] ?? null; }
        public function set(string $key, $value, int $ttl = 0) { ++$this->writes; $this->rows[$key] = $value; $this->ttls[$key] = $ttl; return true; }
        public function delete(string $key) { unset($this->rows[$key]); return true; }
        public function clear() { $this->rows = array(); return true; }
        public function has(string $key) { return array_key_exists($key, $this->rows); }
        public function getMultiple(array $keys) { $out = array(); foreach ($keys as $k) $out[$k] = $this->get($k); return $out; }
        public function setMultiple(array $items, int $ttl = 0) { foreach ($items as $k => $v) $this->set($k, $v, $ttl); return true; }
        public function deleteMultiple(array $keys) { foreach ($keys as $k) $this->delete($k); return true; }
        public function increment(string $key, int $step = 1) { $v = ($this->get($key) ?? 0) + $step; $this->set($key, $v); return $v; }
        public function decrement(string $key, int $step = 1) { return $this->increment($key, -$step); }
        public function ping() { return true; }
    }
    function layers($drivers) {
        (new StaticCache())->clear();
        $cache = new MultiLayerCache(array(array('driver' => 'static')));
        $all = array_merge(array(new StaticCache()), $drivers);
        (new \ReflectionProperty(MultiLayerCache::class, 'layers'))->setValue($cache, $all);
        (new \ReflectionProperty(MultiLayerCache::class, 'layerNames'))->setValue($cache, array_map(function ($d) { return $d->getName(); }, $all));
        return $cache;
    }
    $pass = $fail = 0;
    function check($name, $condition) {
        global $pass, $fail;
        if ($condition) { ++$pass; } else { ++$fail; echo 'FAIL: ' . $name . PHP_EOL; }
    }
    try {
        foreach (array(false, true) as $batch) {
            $upper = new TestLayer('apcu'); $lower = new TestLayer('redis');
            $lower->set('legacy', 'value', 1); $c = layers(array($upper, $lower));
            $value = $batch ? $c->getMultiple(array('legacy'))['legacy'] : $c->get('legacy');
            check('unknown TTL value readable', $value === 'value');
            check('unknown TTL not promoted', !$upper->has('legacy'));
            $reads = $lower->reads;
            if ($batch) $c->getMultiple(array('legacy')); else $c->get('legacy');
            check('unknown TTL request memo retained', $lower->reads === $reads);
            $c->set('known', 'value', 10);
            (new \ReflectionProperty(MultiLayerCache::class, 'expiryCache'))->setValue($c, array('known' => time() + 2));
            $upper->delete('known'); (new StaticCache())->clear();
            if ($batch) $c->getMultiple(array('known')); else $c->get('known');
            check('read hit not promoted even with local deadline', !$upper->has('known'));
            $remaining = (new \ReflectionMethod(MultiLayerCache::class, 'backfillTtl'))->invoke($c, 'known');
            check('known deadline reduced', $remaining > 0 && $remaining <= 2);
            $c->set('permanent', 'value', 0); $upper->delete('permanent'); (new StaticCache())->clear();
            if ($batch) $c->getMultiple(array('permanent')); else $c->get('permanent');
            check('cached value not promoted using possibly stale permanent metadata', !$upper->has('permanent') && $lower->get('permanent') === 'value');
        }
        $lower = new TestLayer('mysql'); $suiteCache = layers(array($lower));
        $sourceCalls = 0;
        $source = function () use (&$sourceCalls) { ++$sourceCalls; return 'source'; };
        $suiteCache->getWithSource('short_source', $source, 1);
        $suiteCache->getWithSource('short_source', $source, 1);
        check('one second source cache', $sourceCalls === 1 && $lower->ttls['short_source'] === 1);

        kv_set('test_kv', 'old'); kv_get('test_kv'); kv_set('test_kv', 'new');
        check('KV read after write', kv_get('test_kv') === 'new');
        check('KV shared refill current', kv_cache_get('test_kv') === 'new');
        (new StaticCache())->clear(); check('KV subsequent shared read', kv_cache_get('test_kv') === 'new');
        kv_delete('test_kv'); check('KV read after delete', kv_get('test_kv') === null && kv_cache_get('test_kv') === null);
        foreach (array(0, false, '', array()) as $i => $v) { kv_cache_set('falsy_' . $i, $v, 3); check('KV falsy hit ' . $i, kv_cache_get('falsy_' . $i) === $v); }
        $long = str_repeat('x', 40); kv_cache_set($long, 'long', 3); check('KV long key', kv_cache_get($long) === 'long'); kv_delete($long); check('KV long delete', kv_cache_get($long) === null);
        kv_cache_set('fail', 'old', 10); kv_get('fail'); \App\Repository\RepositoryFactory::$fail = true;
        check('KV failed write reported', kv_cache_set('fail', 'bad') === false);
        check('KV failed write keeps cache', kv_get('fail') === 'old' && kv_cache_get('fail') === 'old');
        check('KV failed delete keeps cache', kv_delete('fail') === false && kv_get('fail') === 'old');
        \App\Repository\RepositoryFactory::$fail = false;
        kv_set('setting', array('key' => 'old')); setting_get('key'); kv_set('setting', array('key' => 'new')); check('setting memo cleared', setting_get('key') === 'new');
        \App\Repository\RepositoryFactory::$fail = true; setting_set('key', 'bad'); check('setting failure keeps memo', setting_get('key') === 'new'); \App\Repository\RepositoryFactory::$fail = false;

        $v = null;
        for ($i = 0; $i < 4; ++$i) { page_cache_bump(); $next = cache_shared_get('page_content_ver'); check('every mutation bumps', $next !== null && $next !== $v); $v = $next; }
        cache_set('runtime', array('users' => 1), 0); $runtime = runtime_init();
        $snapshot = $lower->get('runtime'); $deadline = $snapshot['_expires_at'];
        check('legacy runtime obtains deadline', $deadline > time());
        $writes = $lower->writes; runtime_save(); check('read-only runtime not rewritten', $writes === $lower->writes);
        runtime_set('users+', 1); runtime_save(); $changed = $lower->get('runtime');
        check('runtime mutation persisted', $changed['users'] === 2);
        check('runtime deadline not extended', $changed['_expires_at'] === $deadline && $lower->ttls['runtime'] > 0 && $lower->ttls['runtime'] <= 300);
        $writes = $lower->writes; runtime_save(); check('unchanged runtime deduplicated', $writes === $lower->writes);
        cache_delete('runtime'); runtime_save(); check('read does not resurrect invalidated runtime', cache_get('runtime') === null);
        $state =& runtime_cache_state(); $state['expires'] = time() - 1; runtime_set('users+', 1); runtime_save(); check('expired snapshot not republished', cache_get('runtime') === null);
    } finally {
        $runtime = array();
        @unlink($conf['cache_path'] . '.breaker_state.php');
        @rmdir($conf['cache_path']);
    }
    echo 'RESULT: pass=' . $pass . ' fail=' . $fail . PHP_EOL;
    exit($fail ? 1 : 0);
}
