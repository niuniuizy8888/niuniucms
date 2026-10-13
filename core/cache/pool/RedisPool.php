<?php
/**
 * Redis 连接池
 * 支持：
 * - FPM 环境下数组栈管理空闲连接
 * - 消极心跳检测（空闲 >60s 时 ping() 校验）
 * - 按 host+port+db+persistent_id+maxSize 的 MD5 分桶隔离
 * - 持久连接支持
 */
class RedisPool
{
    /** @var array */
    private static $pools = [];
    /** @var array */
    private static $lastCheck = [];

    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var int */
    private $db;
    /** @var string */
    private $persistentId;
    /** @var int */
    private $maxSize;
    /** @var int */
    private $timeout;
    /** @var string */
    private $auth;
    /** @var string */
    private $bucketKey;

    public function __construct(array $config, string $persistentId = '')
    {
        $this->host         = $config['host'] ?? '127.0.0.1';
        $this->port         = (int)($config['port'] ?? 6379);
        $this->db           = (int)($config['db'] ?? 0);
        $this->persistentId = $persistentId;
        $this->maxSize      = (int)($config['pool_max'] ?? 10);
        $this->timeout      = (float)($config['timeout'] ?? 1.0);
        $this->auth         = $config['auth'] ?? '';
        // 分桶 key
        $this->bucketKey = md5("{$this->host}:{$this->port}:{$this->db}:{$this->persistentId}:{$this->maxSize}");
    }

    /**
     * 从连接池获取连接
     */
    public function get(): ?Redis
    {
        $now = time();
        // 优先从空闲栈获取
        if (!empty(self::$pools[$this->bucketKey])) {
            $redis = array_pop(self::$pools[$this->bucketKey]);
            if ($redis instanceof Redis) {
                // 消极心跳检测：空闲超过 60 秒时 ping
                $lc = self::$lastCheck[$this->bucketKey] ?? 0;
                if ($now - $lc > 60) {
                    try {
                        // ⚠ 审查修复轮 C8：老 phpredis（<3.1.4）的 ping() 返回字面量 '+PONG' 而非 true，
                        //    `!== true` 会把健康连接误判坏丢弃（每 60s 重建一次连接）。
                        //    RedisDriver::ping() 的口径是 `!== false`，此处对齐：字符串形态只认 '+PONG'。
                        $pong = $redis->ping();
                        // 口径与 RedisDriver::ping() 完全统一（复核修正）：`!== false` ——
                        // phpredis 新版返回 true、老版（<3.1.4）返回 '+PONG'、Predis 返回 'PONG'，
                        // 三者都是健康；只有 false/异常才算坏（原白名单会把 'PONG' 与 multi/pipeline 态
                        // 的 Redis 对象误判为坏连接并丢弃）。
                        $ok = (false !== $pong);
                        if (!$ok) { $this->discard($redis); return $this->create(); }
                    } catch (\Throwable $e) {
                        $this->discard($redis);
                        return $this->create();
                    }
                    self::$lastCheck[$this->bucketKey] = $now;
                }
                return $redis;
            }
        }
        return $this->create();
    }

    /**
     * 归还连接到连接池
     */
    public function put(?Redis $redis): void
    {
        if (!$redis) return;
        if (count(self::$pools[$this->bucketKey] ?? []) >= $this->maxSize) {
            $redis->close();
            return;
        }
        self::$pools[$this->bucketKey][] = $redis;
    }

    /**
     * 创建新连接（优先持久连接）
     */
    private function create(): ?Redis
    {
        try {
            $redis = new Redis();
            if ($this->persistentId) {
                $redis->pconnect($this->host, $this->port, $this->timeout, $this->persistentId);
            } else {
                $redis->connect($this->host, $this->port, $this->timeout);
            }
            if ($this->auth) $redis->auth($this->auth);
            if ($this->db > 0) $redis->select($this->db);
            self::$lastCheck[$this->bucketKey] = time();
            return $redis;
        } catch (\Throwable $e) {
            DEBUG && xn_log("RedisPool create error: " . $e->getMessage(), 'cache_error');
            return null;
        }
    }

    /** 丢弃脏连接：close() 后不做任何回收（本就不回栈，调用方紧接着会 create() 新连接）。⚠ close() 前不校验连接是否仍活着——close 在连接已断时会抛异常，正是要吞掉的对象；空 catch 是刻意的，池的自愈靠「下次 get() 拿到新连接」而非留痕。仅 get() 的失败分支（心跳 ping 非 true 或抛 Throwable）调用。@param Redis $redis 待弃连接 @return void */
    private function discard(Redis $redis): void
    {
        try { $redis->close(); } catch (\Throwable $e) {}
    }

    /**
     * 获取当前连接池统计
     */
    public function stats(): array
    {
        return [
            'bucket'    => $this->bucketKey,
            'target'    => "{$this->host}:{$this->port}/{$this->db}",
            'idle'      => count(self::$pools[$this->bucketKey] ?? []),
            'max_size'  => $this->maxSize,
        ];
    }
}
