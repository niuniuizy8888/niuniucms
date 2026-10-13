<?php
/**
 * Memcached 连接池
 * 支持：
 * - FPM 环境下数组栈管理连接
 * - 持久化连接（persistent_id）
 * - 多节点集群管理
 */
class MemcachedPool
{
    /** @var array */
    private static $pools = [];
    /** @var array */
    private static $lastCheck = [];

    /** @var array */
    private $servers;
    /** @var string */
    private $persistentId;
    /** @var int */
    private $maxSize;
    /** @var string */
    private $bucketKey;

    public function __construct(array $config, string $persistentId = '')
    {
        $this->servers       = $config['servers'] ?? [['host' => '127.0.0.1', 'port' => 11211]];
        $this->maxSize       = (int)($config['pool_max'] ?? 10);
        $serverStr = implode(',', array_map(function($s) { return "{$s['host']}:{$s['port']}"; }, $this->servers));
        // ⚠ 持久连接 ID 必须绑定**服务器集合**（审查修复）：php-memcached 按 persistent_id 复用
        //   底层连接，不同服务器配置的两个驱动若共用同一 ID，后建者的 getServerList() 非空会跳过
        //   addServers ⇒ 静默复用先建者的服务器列表（实测：指向死端口的驱动实际打到活服务器上）。
        //   现把服务器集合散列拼进 ID：改服务器 = 换 ID = 换连接，互不串扰。
        $this->persistentId  = ($persistentId ?: 'nncms_pool') . '_' . md5($serverStr);
        $this->bucketKey     = md5($serverStr . '|' . $this->persistentId . '|' . $this->maxSize);
    }

    /**
     * 从连接池获取连接
     */
    public function get(): ?Memcached
    {
        $now = time();
        if (!empty(self::$pools[$this->bucketKey])) {
            $mem = array_pop(self::$pools[$this->bucketKey]);
            if ($mem instanceof Memcached) {
                $lc = self::$lastCheck[$this->bucketKey] ?? 0;
                if ($now - $lc > 60) {
                    try {
                        $vers = @$mem->getVersion();
                        if ($mem->getResultCode() !== Memcached::RES_SUCCESS) {
                            $this->discard($mem);
                            return $this->create();
                        }
                    } catch (\Throwable $e) {
                        $this->discard($mem);
                        return $this->create();
                    }
                    self::$lastCheck[$this->bucketKey] = $now;
                }
                return $mem;
            }
        }
        return $this->create();
    }

    /**
     * 归还连接到连接池
     */
    public function put(?Memcached $mem): void
    {
        if (!$mem) return;
        if (count(self::$pools[$this->bucketKey] ?? []) >= $this->maxSize) {
            $mem->quit();
            return;
        }
        self::$pools[$this->bucketKey][] = $mem;
    }

    /**
     * 新建（或复用持久化的）Memcached 连接并配好集群节点。
     * 安全：显式 OPT_SERIALIZER=JSON，消除 php-memcached 默认 PHP 序列化在扩展层
     * unserialize 的对象注入面（切换后存量 PHP 序列化值按未命中处理，缓存层容错）。
     * persistent_id 桶下 getServerList 非空则跳过 addServers（持久连接复用时幂等）。
     * 成功顺带刷新本桶心跳时间戳。
     * @return Memcached|null 扩展异常时返回 null 并记 cache_error（仅 DEBUG）
     */
    private function create(): ?Memcached
    {
        try {
            $mem = new Memcached($this->persistentId);
            // 上线前安全审计加固（反序列化面）：显式 JSON 序列化。
            // php-memcached 默认 SERIALIZER_PHP 在读取时经扩展层 unserialize，
            // 不施加 allowed_classes=false——缓存值若被投毒可触发对象注入（__wakeup/__destruct）。
            // JSON 序列化彻底消除对象实例化面（切换后旧 PHP 序列化值按未命中处理，缓存层天然容错）。
            // ⚠ 编译期能力判定（审查修复）：本机 php-memcached 3.4.0 **未编译 JSON 序列化器**
            //   （SERIALIZER_JSON 常量在、HAVE_JSON_SERIALIZER 未定义，setOption 被拒并回落
            //   SERIALIZER_PHP，且每次建连刷一条 Warning）——旧实现只判常量存在 ⇒ 硬化静默失效。
            //   现按 HAVE_JSON_SERIALIZER 判定；不支持时如实退 SERIALIZER_PHP（本机现状）。
            if (defined('Memcached::HAVE_JSON_SERIALIZER') && constant('Memcached::HAVE_JSON_SERIALIZER')
                && defined('Memcached::SERIALIZER_JSON')) {
                $mem->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_JSON);
            }
            // 检查是否已添加过服务器（持久连接可能已存在）
            $existing = $mem->getServerList();
            if (empty($existing)) {
                $nodes = [];
                foreach ($this->servers as $s) {
                    $nodes[] = [$s['host'] ?? '127.0.0.1', (int)($s['port'] ?? 11211), (int)($s['weight'] ?? 1)];
                }
                $mem->addServers($nodes);
            }
            self::$lastCheck[$this->bucketKey] = time();
            return $mem;
        } catch (\Throwable $e) {
            DEBUG && xn_log("MemcachedPool create error: " . $e->getMessage(), 'cache_error');
            return null;
        }
    }

    /**
     * 丢弃失效连接：quit() 关闭与 memcached 服务端的传输；异常吞掉——
     * 本方法只在心跳（getVersion）已判定连接坏的清道路径调用，再抛只会掩盖原故障。
     * @param Memcached $mem
     * @return void
     */
    private function discard(Memcached $mem): void
    {
        try { $mem->quit(); } catch (\Throwable $e) {}
    }

    /**
     * 本桶运维观测数据（非实时探活，纯静态账目）：桶键、服务器清单、当前空闲连接数、池上限。
     * @return array {bucket, servers, idle, max_size}
     */
    public function stats(): array
    {
        return [
            'bucket'   => $this->bucketKey,
            'servers'  => $this->servers,
            'idle'     => count(self::$pools[$this->bucketKey] ?? []),
            'max_size' => $this->maxSize,
        ];
    }
}
