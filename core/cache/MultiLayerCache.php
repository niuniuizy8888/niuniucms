<?php
/**
 * 多层缓存管理器
 * 核心功能：
 * 1. 按配置顺序逐层查询（Static → YAC → Redis → Memcached → DB）
 * 2. 低级命中的结果自动回填至所有高级缓存
 * 3. 防缓存击穿：分布式锁 + 双查 + 指数退避
 * 4. 故障隔离：单个驱动故障不影响整体
 * 5. 性能统计：响应时间、命中率
 */
class MultiLayerCache
{
    /** @var CacheInterface[] 按优先级排列的缓存驱动列表 */
    private $layers = [];

    /** @var string[] 驱动的层级名称 */
    private $layerNames = [];

    /** @var callable|null 最终数据源回调（缓存穿透时调用） */
    private $dataSource = null;

    /** @var string 数据源名称（用于日志/统计） */
    private $sourceName = 'db';

    /** @var int 防击穿最大重试次数 */
    private $maxAttempts;

    /** @var int 锁超时时间（秒） */
    private $lockTtl;

    /** @var array 性能统计 */
    private static $stats = [];

    /** @var array 事件日志 */
    private static $events = [];

    /** @var array<string,int> TTL 追踪：记录每个 key 的原始 TTL，用于 backfill 保持过期一致性 */
    private $ttlCache = [];

    /** @var string|null 空值标记（数据源返回 null 时以此标记写入缓存防击穿） */
    private const NULL_MARKER = "\0__NULL_CACHE__\0";

    /**
     * 跨进程共享的热层：只有这些层写成功，MySQL 层的副本才算冗余（set/setMultiple 才会改为删 MySQL 旧值）。
     * ⚠ 修复（MySQL 兜底层从不落盘）：原判定是「任一非 mysql 层写成功」，而 static 层（StaticCache，
     *   请求内数组）恒在且 set 恒返回 true ⇒ hotSet 每次都成立 ⇒ 无 APCu/Redis/Memcached 的主机上
     *   每次写都去**删** MySQL 行，缓存（页缓存、kv_cache、内容版本令牌）永远跨不过请求；Redis 中途熔断时同理。
     *   apcu/yac 也不算：sharedGet()（内容版本令牌 page_content_ver 等）只读 redis/memcached/mysql，
     *   若 APCu 写成功就删 MySQL，令牌对 sharedGet 永远不可见 ⇒ 内容更新后页缓存不失效。
     */
    private const SHARED_LAYERS = ['redis', 'memcached'];

    /** @var int 空值缓存 TTL（秒），数据源返回 null 时以此 TTL 缓存标记防止重复查询 */
    private $nullCacheTtl = 30;

    /** @var array<string,int> 驱动故障时间戳，用于熔断器（跳过不可用驱动，避免超时等待） */
    private static $breakerOpen = [];
    /** @var array<string,array<string,int>> 熔断窗口内漏删的键（driver => [key => 入队时间]），恢复后补删 */
    private static $breakerPending = [];
    /** @var int 待补删集合每驱动上限（超限按最早入队截断，极端流量下的妥协） */
    private const PENDING_DELETE_CAP = 500;
    /** @var int 熔断器冷却时间（秒） */
    private $breakerCooldown = 30;
    /** @var string|null 熔断器锁文件路径 */
    private $breakerLockFile = null;

    /**
     * @param array $layerConfig 层级配置 [['driver' => ..., 'config' => [...]], ...]
     * @param array $options     全局选项 [maxAttempts, lockTtl]
     */
    public function __construct(array $layerConfig = [], array $options = [])
    {
        $this->maxAttempts = $options['maxAttempts'] ?? 5;
        $this->lockTtl    = $options['lockTtl'] ?? 10;

        // 熔断器锁文件：跨请求共享 Redis/Memcached 故障状态
        $cachePath = _SERVER('conf')['cache_path'] ?? './runtime/cache/';
        $this->breakerLockFile = rtrim($cachePath, '/') . '/.breaker_state.php';
        $this->loadBreakerState();

        if (empty($layerConfig)) {
            // 默认配置：Static → YAC → Redis → Memcached
            $layerConfig = [
                ['driver' => 'static',    'config' => []],
                ['driver' => 'yac',       'config' => ['cachepre' => 'mlc_']],
                ['driver' => 'redis',     'config' => ['host' => '127.0.0.1', 'port' => 6379, 'cachepre' => 'mlc_']],
                ['driver' => 'memcached', 'config' => ['servers' => [['host' => '127.0.0.1', 'port' => 11211]]]],
            ];
        }

        foreach ($layerConfig as $cfg) {
            $driver = $this->createDriver($cfg['driver'], $cfg['config'] ?? []);
            if ($driver) {
                $this->layers[]     = $driver;
                $this->layerNames[] = $driver->getName();
            }
        }

        // Static 未配置时自动追加为最高优先级
        if (!$this->hasDriver('static')) {
            $static = new StaticCache();
            array_unshift($this->layers, $static);
            array_unshift($this->layerNames, 'static');
        }
    }

    /**
     * 数据源回源骨架（dataSource/sourceName/fetchWithAntiBreakdown）
     * 说明：原 setDataSource() 注入入口全项目 0 调用，已移除；
     * 内部回源逻辑保留，未来启用“缓存穿透回源”能力时需先恢复注入入口。
     */

    // ==================== 熔断器（Circuit Breaker） ====================

    /**
     * 从锁文件加载熔断器状态（跨请求共享）
     */
    private function loadBreakerState(): void
    {
        if (!$this->breakerLockFile) return;
        if (!file_exists($this->breakerLockFile)) return;
        $data = @include $this->breakerLockFile;
        if (is_array($data)) {
            if (isset($data['tripped'])) self::$breakerOpen = $data['tripped'];
            if (isset($data['pending'])) self::$breakerPending = $data['pending'];
        }
    }

    /**
     * 持久化熔断器状态到锁文件
     */
    private function saveBreakerState(): void
    {
        if (!$this->breakerLockFile) return;
        $dir = dirname($this->breakerLockFile);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $content = '<?php return ' . var_export(['tripped' => self::$breakerOpen, 'pending' => self::$breakerPending], true) . ';';
        @file_put_contents($this->breakerLockFile, $content, LOCK_EX);
    }

    /**
     * 判断驱动是否应被熔断跳过
     */
    private function isBreakerOpen(string $driverName): bool
    {
        if (!isset(self::$breakerOpen[$driverName])) return false;
        $trippedAt = self::$breakerOpen[$driverName];
        if (time() - $trippedAt > $this->breakerCooldown) {
            // 冷却期已过，允许重试
            unset(self::$breakerOpen[$driverName]);
            $this->saveBreakerState();
            // 恢复后先补删熔断窗口内漏删的键（审查修复）：窗口内 delete 被 skip，
            // 若只恢复不补删，这些键的旧值会「复活」被 get 读到（陈旧窗口=熔断时长+键 TTL）。
            $this->flushPendingDeletes($driverName);
            return false;
        }
        return true;
    }

    /**
     * 打开熔断器：标记驱动不可用，冷却期内跳过
     */
    private function tripBreaker(string $driverName): void
    {
        if (isset(self::$breakerOpen[$driverName])) return; // 已熔断
        self::$breakerOpen[$driverName] = time();
        $this->saveBreakerState();
        $this->log('breaker_trip', '', $driverName, 0, "Circuit breaker open for {$this->breakerCooldown}s");
    }

    /**
     * 按驱动名找层实例（pending 补删用）。
     * @param string $driverName 驱动名（redis/memcached）
     * @return CacheInterface|null 未配置该层为 null
     */
    private function layerByName(string $driverName)
    {
        $idx = array_search($driverName, $this->layerNames, true);
        return ($idx === false) ? null : $this->layers[$idx];
    }

    /**
     * 记录「熔断窗口内漏处理」的键（driver => key => 入队时间），跨进程随熔断状态一起落盘。
     * 两个来源：① delete/deleteMultiple 期间该层被熔断跳过（漏删）；② set/setMultiple 期间被跳过
     * （漏写 ⇒ 该层留着**旧值**，恢复后会遮蔽其它共享层的新值，见 set() 内注释）。两者处置相同：
     * 恢复（半开）时对这些键执行 delete，让下层新值立即生效。
     * 每驱动上限 PENDING_DELETE_CAP：超限按最早入队截断（极端流量下的妥协，被截断的键靠自身 TTL 收敛）。
     * @param string $driverName 驱动名
     * @param array  $keys       逻辑键列表
     * @return void
     */
    private function addPendingDeletes(string $driverName, array $keys): void
    {
        foreach ($keys as $k) {
            self::$breakerPending[$driverName][(string)$k] = time();
        }
        if (isset(self::$breakerPending[$driverName]) && count(self::$breakerPending[$driverName]) > self::PENDING_DELETE_CAP) {
            asort(self::$breakerPending[$driverName]); // 按入队时间升序
            self::$breakerPending[$driverName] = array_slice(self::$breakerPending[$driverName], -self::PENDING_DELETE_CAP, null, true);
        }
        $this->saveBreakerState();
    }

    /**
     * 熔断恢复后的补删：把 pending 里该驱动的键一次性 deleteMultiple 掉，防旧值复活。
     * 补删失败（驱动仍不可用）→ 重新熔断并把键放回 pending，等下一轮冷却后再试。
     * 只对 redis/memcached 生效（pending 只记录这两个驱动，见 delete/deleteMultiple）。
     * @param string $driverName 驱动名
     * @return void
     */
    private function flushPendingDeletes(string $driverName): void
    {
        if (empty(self::$breakerPending[$driverName])) return;
        $layer = $this->layerByName($driverName);
        if (!$layer) {
            unset(self::$breakerPending[$driverName]);
            $this->saveBreakerState();
            return;
        }
        $keys = array_keys(self::$breakerPending[$driverName]);
        unset(self::$breakerPending[$driverName]);
        try {
            $r = $layer->deleteMultiple($keys);
            if (!$r && in_array($driverName, ['redis', 'memcached'], true)) {
                $this->tripBreaker($driverName);
                $this->addPendingDeletes($driverName, $keys);
            }
        } catch (\Throwable $e) {
            if (in_array($driverName, ['redis', 'memcached'], true)) {
                $this->tripBreaker($driverName);
                $this->addPendingDeletes($driverName, $keys);
            }
            $this->log('error', 'pending_flush', $driverName, 0, $e->getMessage());
        }
        $this->saveBreakerState();
    }

    // ==================== 核心查询 ====================

    /** @var string|null 最近一次 get() 命中的缓存层名（调试用） */
    public static $lastHitLayer = null;
    /** @var float 最近一次 get() 耗时 ms */
    public static $lastHitMs = 0.0;

    /**
     * 多层查询：从高到低逐层查询，命中后回填
     */
    /** @return mixed */
    public function get(string $key)
    {
        $start = microtime(true);
        $found = null;
        $hitLayer = -1;

        // 1. 逐层查询
        foreach ($this->layers as $i => $layer) {
            $name = $layer->getName();
            // 熔断器：跳过已知不可用的驱动（Redis/Memcached 等网络层）
            if ($this->isBreakerOpen($name)) {
                $this->log('breaker_skip', $key, $name, 0);
                continue;
            }
            $lStart = microtime(true);
            try {
                $val = $layer->get($key);
                // null 表示未命中/驱动故障；false 按「合法缓存值」对待，不得归一成 null（语义上 false≠miss）。
                // 注：当前全仓无 cache_set(..., false) 写入点——LikeService::check 已把结果归一成 1/0 入缓存，
                //     早期注释拿它当例子已过时；此处的 false 分支属防御性契约，勿当现行数据流读。
                // 本 get() 路径的熔断只在下面的 catch(\Throwable) 触发，不因 falsy 值误触发。
                // ⚠ 这不是全局结论：delete()/deleteMultiple()/setMultiple() 对 redis/memcached 层的
                //    falsy 返回**也会** tripBreaker。⚠ 已修（缺陷修复轮 A）：三个驱动已把「键不存在」
                //    （miss）与「真故障」分开返回（Redis `del() !== false`、Memcached 本地结果码、
                //    Apcu delete||!exists）——删除缺失键不再误熔断；这里的 falsy 判定现只吃到真故障。
                $elapsed = (microtime(true) - $lStart) * 1000;
                if ($val !== null) {
                    // 命中（含 false 等合法 falsy 值）
                    $found = $val;
                    $hitLayer = $i;
                    self::$lastHitLayer = $name;
                    self::$lastHitMs = $elapsed;
                    $this->log('hit', $key, $name, $elapsed);
                    break;
                }
                $this->log('miss', $key, $name, $elapsed);
            } catch (\Throwable $e) {
                // 网络驱动异常 → 触发熔断
                if (in_array($name, ['redis', 'memcached'], true)) {
                    $this->tripBreaker($name);
                }
                $this->log('error', $key, $name, 0, $e->getMessage());
            }
        }

        // 2. 空值标记命中 → 直接返回 null（不限层级），不回填
        if ($found === self::NULL_MARKER) {
            $total = (microtime(true) - $start) * 1000;
            $this->recordStat(true, $total);
            $this->log('null_hit', $key, self::$lastHitLayer);
            return null;
        }

        // 3. 命中 → 回填所有未命中层级
        if ($found !== null && $hitLayer > 0) {
            $this->backfill($key, $found, $hitLayer);
        }

        // 4. 未命中 → 防击穿查询数据源（降级：查询失败返回 null，不抛出异常）
        if ($found === null && $this->dataSource !== null) {
            try {
                $found = $this->fetchWithAntiBreakdown($key);
            } catch (\Throwable $e) {
                $this->log('error', $key, 'datasource', 0, $e->getMessage());
            }
        }

        $total = (microtime(true) - $start) * 1000;
        $this->recordStat($found !== null, $total);
        return $found;
    }

    /**
     * 多层批量查询（R7 性能项：批量 MGET/MSET）——每层一次 getMultiple（Redis=MGET、Memcached=getMulti、
     * APCu/YAC=multi-fetch），命中层再批量回填更高层。语义与 get() 对齐：null=未命中/空值标记，
     * false 等合法 falsy 值照常返回。
     * @param string[] $keys
     * @return array<string,mixed> key=>value（未命中为 null）
     */
    public function getMultiple(array $keys): array
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        $result = array_fill_keys($keys, null);
        if (empty($keys)) return $result;
        $remaining = array_fill_keys($keys, true);
        foreach ($this->layers as $i => $layer) {
            if (empty($remaining)) break;
            $name = $layer->getName();
            if ($this->isBreakerOpen($name)) continue;
            $batch = array_keys($remaining);
            $lStart = microtime(true);
            try {
                $vals = $layer->getMultiple($batch);
            } catch (\Throwable $e) {
                if (in_array($name, ['redis', 'memcached'], true)) $this->tripBreaker($name);
                $this->log('error', 'multi', $name, 0, $e->getMessage());
                continue;
            }
            $found = array();
            foreach ($batch as $k) {
                $v = (is_array($vals) && array_key_exists($k, $vals)) ? $vals[$k] : null;
                if ($v === null) continue;
                unset($remaining[$k]);
                if ($v === self::NULL_MARKER) { $result[$k] = null; continue; }
                $result[$k] = $v;
                $found[$k] = $v;
            }
            $elapsed = (microtime(true) - $lStart) * 1000;
            if (!empty($found)) {
                $this->log('hit', 'multi(' . count($found) . ')', $name, $elapsed);
                if ($i > 0) $this->backfillMultiple($found, $i);
            } else {
                $this->log('miss', 'multi(' . count($batch) . ')', $name, $elapsed);
            }
        }
        return $result;
    }

    /**
     * 批量回填（按 TTL 分组，保持与单键 backfill 的过期一致性）
     */
    private function backfillMultiple(array $items, int $hitLayer): void
    {
        $groups = array();
        foreach ($items as $k => $v) {
            $ttl = $this->ttlCache[$k] ?? 0;
            $groups[$ttl][$k] = $v;
        }
        $limit = ($hitLayer < 0) ? count($this->layers) : $hitLayer;
        for ($i = 0; $i < $limit; $i++) {
            if ($this->isBreakerOpen($this->layers[$i]->getName())) continue;
            foreach ($groups as $ttl => $chunk) {
                try {
                    $this->layers[$i]->setMultiple($chunk, (int)$ttl);
                    $this->log('backfill', 'multi(' . count($chunk) . ')', $this->layers[$i]->getName());
                } catch (\Throwable $e) {
                    $this->log('error', 'multi', $this->layers[$i]->getName(), 0, $e->getMessage());
                }
            }
        }
    }

    /**
     * 多层批量写入（R7 性能项）：每层一次 setMultiple（Redis=pipeline、Memcached=setMulti）。
     * MySQL 层写懒惰与 set() 同口径：共享热层（redis/memcached）写成功即删除 MySQL 旧值防脏读复活；
     * 否则（只有 static/apcu/yac，或共享层熔断/写失败）照常写 MySQL。
     * @return bool
     */
    public function setMultiple(array $items, int $ttl = 0)
    {
        if (empty($items)) return true;
        foreach ($items as $k => $v) $this->ttlCache[$k] = $ttl;
        $ok = true;
        $hotSet = false;
        foreach ($this->layers as $layer) {
            $name = $layer->getName();
            if ($name === 'mysql' && $hotSet) {
                try { $layer->deleteMultiple(array_keys($items)); } catch (\Throwable $e) {}
                continue;
            }
            if ($this->isBreakerOpen($name)) {
                // 同 set()：共享层被熔断跳过 ⇒ 登记整批键，恢复后失效其陈旧副本（防旧值遮蔽新值）
                if (in_array($name, ['redis', 'memcached'], true)) $this->addPendingDeletes($name, array_keys($items));
                continue;
            }
            try {
                $r = $layer->setMultiple($items, $ttl);
                if ($r && in_array($name, self::SHARED_LAYERS, true)) {
                    $hotSet = true;
                } elseif (!$r && in_array($name, ['redis', 'memcached'], true)) {
                    // 写失败 ⇒ 该层可能留着旧值：熔断 + 登记补删（同 delete() 失败口径）；MySQL 随后照常写入
                    $this->tripBreaker($name);
                    $this->addPendingDeletes($name, array_keys($items));
                }
                $ok = $ok && $r;
            } catch (\Throwable $e) {
                if (in_array($name, ['redis', 'memcached'], true)) {
                    $this->tripBreaker($name);
                    $this->addPendingDeletes($name, array_keys($items));
                }
                $this->log('error', 'multi', $name, 0, $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * 多层批量删除（熔断窗口补偿同 delete()：跳过的网络层键记入 pending 待补删）
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        if (empty($keys)) return true;
        foreach ($keys as $k) unset($this->ttlCache[$k]);
        $ok = true;
        foreach ($this->layers as $layer) {
            $name = $layer->getName();
            if ($this->isBreakerOpen($name)) {
                if (in_array($name, ['redis', 'memcached'], true)) $this->addPendingDeletes($name, $keys);
                continue;
            }
            try {
                $r = $layer->deleteMultiple($keys);
                if (!$r && in_array($name, ['redis', 'memcached'], true)) {
                    $this->tripBreaker($name);
                    $this->addPendingDeletes($name, $keys);
                }
                $ok = $ok && $r;
            } catch (\Throwable $e) {
                if (in_array($name, ['redis', 'memcached'], true)) {
                    $this->tripBreaker($name);
                    $this->addPendingDeletes($name, $keys);
                }
                $this->log('error', 'multi', $name, 0, $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * 读取「跨进程共享层」的值（内容缓存失效专项）：
     * 跳过 static/apcu/yac 这些**进程内**层——各 FPM worker 有独立 APCu 段，
     * 对别的 worker 的写入不可见。取第一个命中的共享驱动（redis → memcached → mysql），
     * 供跨 worker 的内容版本令牌读取。
     * @return mixed 未命中 null
     */
    public function sharedGet(string $key)
    {
        foreach ($this->layers as $layer) {
            $name = $layer->getName();
            if (in_array($name, array('static', 'apcu', 'yac'), true)) continue;
            if ($this->isBreakerOpen($name)) continue;
            try {
                $v = $layer->get($key);
                if ($v !== null) return $v;
            } catch (\Throwable $e) {
                if (in_array($name, array('redis', 'memcached'), true)) $this->tripBreaker($name);
            }
        }
        return null;
    }

    /**
     * 带防击穿的按调用回源：未命中时经 fetchWithAntiBreakdown（分布式锁 + 双查 + 退避）回源
     * 并回填；数据源返回 null 时缓存空值标记（nullTtl 窗口内不再重复回源）。
     * 接线修复：原 setDataSource 注入入口已移除致 dataSource 恒 null、防击穿成为死代码，
     * 现将回源改为每次调用传入，供 block_cache 等热键路径使用。
     * @param string   $key     缓存键
     * @param callable $source  数据源回调（返回数据；null=空值）
     * @param int      $ttl     正常数据缓存秒数（0=默认）
     * @param int      $nullTtl 空值标记秒数（默认 30）
     * @return mixed
     */
    public function getWithSource(string $key, callable $source, int $ttl = 0, int $nullTtl = 30)
    {
        $found = $this->get($key);
        if ($found !== null) return $found;

        // 空值标记窗口内直接返回 null，不重复回源（get() 已将标记归一为 null，这里回查原始层）
        foreach ($this->layers as $layer) {
            if ($this->isBreakerOpen($layer->getName())) continue; // 熔断层不再探测（避免每次回源都去连故障 Redis）
            try {
                if ($layer->get($key) === self::NULL_MARKER) return null;
            } catch (\Throwable $e) {}
        }

        $prevSource   = $this->dataSource;
        $prevNullTtl  = $this->nullCacheTtl;
        $this->ttlCache[$key] = $ttl; // 登记 TTL，backfill 按此回填正常数据
        $this->dataSource   = $source;
        $this->nullCacheTtl = $nullTtl;
        try {
            return $this->fetchWithAntiBreakdown($key);
        } catch (\Throwable $e) {
            $this->log('error', $key, 'datasource', 0, $e->getMessage());
            return null;
        } finally {
            $this->dataSource   = $prevSource;
            $this->nullCacheTtl = $prevNullTtl;
        }
    }

    /**
     * 设置缓存（写入所有层级）
     *
     * MySQL 层写懒惰：仅当**跨进程共享热层**（redis/memcached，见 SHARED_LAYERS）写入成功后，
     * 才删除 MySQL 旧值而不写；static/apcu/yac 写成功不算（请求内 / sharedGet 不可见），
     * 共享层熔断或写失败时同样照常写 MySQL——保证无 Redis 主机与 Redis 故障窗口内缓存仍能跨请求持久。
     */
    /** @return bool */
    public function set(string $key, $value, int $ttl = 0)
    {
        $this->ttlCache[$key] = $ttl;
        $ok = true;
        $hotSet = false;
        foreach ($this->layers as $layer) {
            $name = $layer->getName();
            if ($name === 'mysql' && $hotSet) {
                // 热层写入成功后，清除 MySQL 旧值而非跳过，防止脏数据复活
                try { $layer->delete($key); } catch (\Throwable $e) {}
                continue;
            }
            // 熔断器：跳过已知不可用的驱动
            if ($this->isBreakerOpen($name)) {
                // ⚠ 恢复后必须让本层的**陈旧副本**失效（复核补修 S1「令牌遮蔽」）：
                //   窗口内 set 只落到其它共享层（如 redis 熔断 → 只写 memcached），而 sharedGet()
                //   取「第一个命中的共享层」（redis 优先）⇒ 恢复瞬间 redis 里的旧令牌会遮蔽
                //   memcached 里的新令牌，页缓存失效判定被推迟到下一次 bump。
                //   复用 pending 补删队列（键级、有上限、有跨进程落盘、恢复时自动 flush）：
                //   恢复后删掉本层的旧副本，新值由下层立即可见，下一次 set 会重新填充本层。
                if (in_array($name, ['redis', 'memcached'], true)) $this->addPendingDeletes($name, array($key));
                continue;
            }
            try {
                $r = $layer->set($key, $value, $ttl);
                if ($r && in_array($name, self::SHARED_LAYERS, true)) {
                    $hotSet = true;
                } elseif (!$r && in_array($name, ['redis', 'memcached'], true)) {
                    // 写失败（Redis 中途故障）⇒ 熔断 + 登记补删防旧值复活；hotSet 保持 false ⇒ MySQL 照常写入
                    $this->tripBreaker($name);
                    $this->addPendingDeletes($name, array($key));
                }
                $ok = $ok && $r;
            } catch (\Throwable $e) {
                if (in_array($name, ['redis', 'memcached'], true)) {
                    $this->tripBreaker($name);
                    $this->addPendingDeletes($name, array($key));
                }
                $this->log('error', $key, $name, 0, $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * 删除缓存（所有层级）
     *
     * 始终删除所有层级（包括 MySQL），$hotDel 优化已被移除：
     * MySQL 层可能残留在回填阶段写入的数据，跳过会导致脏读复活。
     * ⚠ 熔断窗口补偿（审查修复）：redis/memcached 熔断期间本层被跳过 ⇒ 该层旧值未删，
     *   恢复后会被 get 读到（脏数据复活）。现把跳过的键记入 pending 待补删集合
     *   （随 .breaker_state.php 跨进程落盘），恢复时由 flushPendingDeletes 补删；
     *   删除失败（触发熔断）同样记录。
     */
    public function delete(string $key): bool
    {
        $ok = true;
        unset($this->ttlCache[$key]);
        foreach ($this->layers as $layer) {
            $name = $layer->getName();
            if ($this->isBreakerOpen($name)) {
                // 熔断窗口内跳过网络层删除：记入待补删，防恢复后旧值复活
                if (in_array($name, ['redis', 'memcached'], true)) $this->addPendingDeletes($name, array($key));
                continue;
            }
            try {
                $r = $layer->delete($key);
                if (!$r && in_array($name, ['redis', 'memcached'], true)) {
                    $this->tripBreaker($name);
                    $this->addPendingDeletes($name, array($key)); // 删除失败即待补删
                }
                $ok = $ok && $r;
            } catch (\Throwable $e) {
                if (in_array($name, ['redis', 'memcached'], true)) {
                    $this->tripBreaker($name);
                    $this->addPendingDeletes($name, array($key));
                }
                $this->log('error', $key, $name, 0, $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * 清空所有缓存
     */
    public function clear(): bool
    {
        $ok = true;
        foreach ($this->layers as $layer) {
            $name = $layer->getName();
            if ($this->isBreakerOpen($name)) continue;
            try { $ok = $layer->clear() && $ok; } catch (\Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    // ==================== 防缓存击穿 ====================

    /**
     * 防击穿查询流程：
     * 1. 尝试获取分布式锁
     * 2. 双查：获取锁后再查一次缓存（防止其他请求已回填）
     * 3. 仍未命中则查数据源并回填
     * 4. 释放锁
     */
    /** @return mixed */
    private function fetchWithAntiBreakdown(string $key)
    {
        // 上线前审计修复（VULN-H01，线上首页挂死根因）：**请求级回源重入保护**。
        // 现场：CateService::channelVideoCache('channel_video_1_18') 未命中 → 加锁 → 调
        // channelVideo() → ... → QueryService::channelVideoList() → 再次 channelVideoCache()
        // 请求**同一个键**；分布式锁不可重入 ⇒ 第二次拿不到锁 → 退避重试 maxAttempts 次 →
        // giveup 分支再调一次 dataSource → 递归放大。单请求即耗尽
        // request_terminate_timeout=100s 并占住一个 FPM worker，并发约 200 即打满
        // pm.max_children（实测 slow.log 栈帧 + 计数器：get=48 fetch=28 giveup=23 全部卡在
        // 同一 key 的自锁等待上，无 DS 完成输出=数据源从未跑完）。
        // 语义：同一个键在**本请求内**已处于回源中时，嵌套调用不得再次抢锁/回源，
        // 直接返回 null（调用方自行计算或降级），从根上消除自死锁与递归放大。
        static $inFlight = array();
        if (isset($inFlight[$key])) {
            $this->log('reentry_skip', $key, 'datasource');
            return null;
        }
        $inFlight[$key] = true;
        try {
            return $this->fetchWithAntiBreakdownInner($key);
        } finally {
            unset($inFlight[$key]);
        }
    }

    /** @return mixed */
    private function fetchWithAntiBreakdownInner(string $key)
    {
        $lockKey = '_lock:' . $key;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->log('attempt', $key, 'lock', 0, "attempt #{$attempt}");

            // 获取 Redis 锁（优先用 RedisDriver）
            if ($this->acquireLock($lockKey)) {
                try {
                    // 双查：获取锁后再查一次（跳过空值标记）
                    foreach ($this->layers as $layer) {
                        if ($this->isBreakerOpen($layer->getName())) continue;
                        try {
                            $val = $layer->get($key);
                            // falsy 中的 false 按合法缓存值对待，不归一成 null（false≠miss；
                            // 现行写入点无 false，LikeService::check 已归一 1/0，早期例证已过时）
                            if ($val !== null && $val !== self::NULL_MARKER) {
                                $this->log('double_check_hit', $key, $layer->getName());
                                return $val;
                            }
                        } catch (\Throwable $e) {}
                    }

                    // 查数据源
                    if ($this->dataSource === null) return null;

                    $this->log('fetch', $key, $this->sourceName);
                    $data = ($this->dataSource)();

                    if ($data !== null) {
                        // 回填所有层级
                        $this->backfill($key, $data, -1);
                        return $data;
                    }

                    // 数据源返回 null → 缓存空值标记（30s TTL）防止重复查询
                    $this->log('null_cache', $key, $this->sourceName);
                    $this->backfill($key, self::NULL_MARKER, -1, $this->nullCacheTtl);
                    return null;
                } finally {
                    $this->releaseLock($lockKey);
                }
            }

            // 锁被占用 → 指数退避 + 随机抖动
            $baseSleep = min(50 * (1 << ($attempt - 1)), 200); // 50ms → 100ms → 200ms cap
            $jitter    = mt_rand(5, 50);                       // 5~50ms 随机
            usleep(($baseSleep + $jitter) * 1000);
        }

        $this->log('giveup', $key, 'lock', 0, "max attempts {$this->maxAttempts} exhausted");
        // R7 修复：锁竞争超时不再直接返回 null（调用方会拿到空块）——无锁回源一次（best-effort），
        // 并发下可能重复回源，但优于返回空；数据源返回 null 时写空值标记防重复回源。
        if ($this->dataSource !== null) {
            try {
                $data = ($this->dataSource)();
                if ($data !== null) { $this->backfill($key, $data, -1); return $data; }
                $this->backfill($key, self::NULL_MARKER, -1, $this->nullCacheTtl);
            } catch (\Throwable $e) {
                $this->log('error', $key, 'datasource', 0, $e->getMessage());
            }
        }
        return null;
    }

    // ==================== Lock 管理 ====================

    /**
     * 抢「回源防击穿锁」（非阻塞）：抢到才允许本进程去查数据源，抢不到走等待/无锁兜底路径。
     * 两档实现，优先级不可调换：
     *  - 只要层级里存在 RedisDriver 就一律用它加锁（`SET key token NX EX ttl`），
     *    并且**无论本次抢到与否**都把 lockMode[$key] 登记成 redis —— 本实例若此前已持有该锁，
     *    后续 unlock() 必须走 RedisDriver 的 token CAS 才删得掉自己的锁；
     *    越权释放由 unlock 的 token 比对兜底（非持有者拿不到 token → false）。
     *    刻意**不回退文件锁**：跨节点会双持（两边都以为拿到了锁），防击穿形同虚设。
     *  - 完全没有 Redis 层时才回退本机 flock（`LOCK_EX | LOCK_NB`，锁文件落系统临时目录、
     *    按 key 的 md5 命名），**只有单节点语义**，多机部署下不互斥。
     * 拿不到锁不等待、不重试、不抛错，直接返回 false；文件锁句柄留在 $this->lockFp 里等 unlock/析构释放。
     * @param string $key 缓存键（同时决定锁名）
     * @param int|null $ttl 锁存活秒数；传 NULL 或小于 1 用实例默认 $this->lockTtl
     * @return bool TRUE = 抢到（Redis 档下含「本实例已持有」由 token 语义判定）；FALSE = 未抢到或加锁失败
     */
    private function acquireLock(string $key, ?int $ttl = null): bool
    {
        $ttl = ($ttl !== null && $ttl > 0) ? $ttl : $this->lockTtl;
        // 存在 Redis 驱动则一律使用 Redis 锁（不可回退文件锁：跨节点会双持）
        foreach ($this->layers as $layer) {
            if ($layer instanceof RedisDriver) {
                $ok = $layer->lock($key, $ttl);
                // 注意：无论本次是否抢到，都保持 mode=redis 登记——本实例此前若已持有该锁，
                // 之后调用 unlock() 仍需走 RedisDriver 的 token CAS 才能释放自己的锁。
                // 越权释放由 RedisDriver::unlock 的 token 比对兜底（非持有者无 token → false）。
                $this->lockMode[$key] = 'redis';
                return $ok;
            }
        }
        // 无 Redis：回退本机 flock（单节点语义）
        $tmp = sys_get_temp_dir() . '/mlc_' . md5($key) . '.lock';
        $fp = @fopen($tmp, 'w');
        if ($fp && @flock($fp, LOCK_EX | LOCK_NB)) {
            $this->lockFp[$key] = $fp;
            $this->lockMode[$key] = 'file';
            return true;
        }
        return false;
    }

    /** @var array 锁资源跟踪 */
    private $lockFp = [];
    /** @var array 每个锁的获取机制（redis/file），释放必须与获取同机制 */
    private $lockMode = [];

    /**
     * 释放内部防击穿锁：必须与获取时同机制——acquireLock() 已把 lockMode 登记为 redis 的键，
     * 走 RedisDriver::unlock() 的 token CAS（只删自己 token 的键，越权释放返回 false）；
     * 登记为 file 的键则 flock 解锁并关闭删除临时文件。无论成否都注销 lockMode/lockFp 登记。
     * @param string $key 锁键（含 '_lock:' 前缀的内部键）
     * @return bool 是否真正释放；redis unlock 失败时锁残留在 Redis 上等 TTL 自然过期
     */
    private function releaseLock(string $key): bool
    {
        $mode = $this->lockMode[$key] ?? '';
        $released = false;
        if ($mode === 'redis') {
            foreach ($this->layers as $layer) {
                if ($layer instanceof RedisDriver) { $released = $layer->unlock($key); break; }
            }
        } elseif (isset($this->lockFp[$key])) {
            @flock($this->lockFp[$key], LOCK_UN);
            @fclose($this->lockFp[$key]);
            @unlink(sys_get_temp_dir() . '/mlc_' . md5($key) . '.lock');
            unset($this->lockFp[$key]);
            $released = true;
        }
        unset($this->lockMode[$key]);
        return $released;
    }

    /**
     * 基于缓存驱动的分布式锁（R7 能力补全）：优先 Redis 的 `SET NX EX` + 唯一 token CAS 释放；
     * 无 Redis 驱动时退回本机 flock（仅单节点有效）。
     * 用法：if (cache_lock('build_x', 30)) { try { ... } finally { cache_unlock('build_x'); } }
     * @return bool true=已加锁
     */
    public function lock(string $key, int $ttl = 0): bool
    {
        return $this->acquireLock('_mlock:' . $key, $ttl > 0 ? $ttl : $this->lockTtl);
    }

    /** 释放本请求持有的缓存锁（仅删自己 token 对应的键，见 RedisDriver::unlock）；返回是否真正释放 */
    public function unlock(string $key): bool
    {
        return $this->releaseLock('_mlock:' . $key);
    }

    // ==================== 回填机制 ====================

    /**
     * 将数据回填至 hitLayer 之前的所有层级
     * @param string $key
     * @param mixed  $value
     * @param int    $hitLayer 命中层索引（-1 表示回填所有层级）
     */
    /** @param mixed $value @return void */
    private function backfill(string $key, $value, int $hitLayer, ?int $ttlOverride = null)
    {
        // ttlOverride 优先（用于空值标记等特殊场景），否则使用原始写入 TTL 保持一致性
        $ttl = $ttlOverride ?? ($this->ttlCache[$key] ?? 0);
        $limit = ($hitLayer < 0) ? count($this->layers) : $hitLayer;
        for ($i = 0; $i < $limit; $i++) {
            // 熔断窗口内不回填故障层（回填值来自下层，窗口内的 set/delete 已登记 pending 补删）
            if ($this->isBreakerOpen($this->layers[$i]->getName())) continue;
            try {
                $this->layers[$i]->set($key, $value, $ttl);
                $this->log('backfill', $key, $this->layers[$i]->getName());
            } catch (\Throwable $e) {
                $this->log('error', $key, $this->layers[$i]->getName(), 0, $e->getMessage());
            }
        }
    }

    // ==================== 工厂 ====================

    /**
     * 按驱动名 + 配置造一个缓存驱动实例（构造期建层级用的私有工厂）。
     * 支持的类型：static（StaticCache，进程内层，无扩展依赖）、apcu、yac、redis、memcached、mysql。
     * **fail-soft 是这里的契约**：除 static 与 mysql 外都先 `extension_loaded()` 探扩展，
     * 扩展没装就返回 NULL，由调用方（层级装配处）跳过该层 ⇒ 配置里写了 redis 但机器没装 phpredis
     * 时站点照常跑，只是少一层（对应 §三.1 的驱动故障熔断口径，别改成抛异常）。
     * 未知类型（含拼错的驱动名）同样静默返回 NULL——**配错不会有任何提示**，
     * 排查层级是否符合预期要看 getReport()/调试日志，不要以为「没报错就是生效了」。
     * mysql 层走 createMysqlDriver()：复用已就绪的 `$_SERVER['db']` 连接，建不出来也只记 cache_error 日志并返回 NULL。
     * @param string $type 驱动名（小写，取证：来自 conf['cache']['multi']['layers'] 的 driver 键）
     * @param array $config 该层的配置（cachepre、host/port 等，原样透传给驱动构造函数）
     * @return CacheInterface|null 可用驱动实例；扩展缺失、类型不认识或底层连接不可用时 NULL
     */
    private function createDriver(string $type, array $config)
    {
        switch ($type) {
            case 'static':
                return new StaticCache();
            case 'apcu':
                return extension_loaded('apcu') ? new ApcuDriver($config) : null;
            case 'yac':
                return extension_loaded('yac') ? new YacDriver($config) : null;
            case 'redis':
                return extension_loaded('redis') ? new RedisDriver($config) : null;
            case 'memcached':
                return extension_loaded('memcached') ? new MemcachedDriver($config) : null;
            case 'mysql':
                return $this->createMysqlDriver($config);
            default:
                return null;
        }
    }

    /**
     * 创建 MySQL 缓存驱动（无扩展依赖，作为持久降级层）
     */
    private function createMysqlDriver(array $config)
    {
        try {
            $db = $_SERVER['db'] ?? null;
            if (!$db) return null;
            $config['db'] = $db;
            return new cache_mysql($config);
        } catch (\Throwable $e) {
            xn_log("MultiLayerCache MySQL driver create error: " . $e->getMessage(), 'cache_error');
            return null;
        }
    }

    /**
     * 判断某驱动是否已装入层级列表（按 getName() 字符串精确比对）。
     * 构造期用于：未配置 static 层时自动前插 StaticCache（§三.1 层级口径）。
     * @param string $type 驱动名（'static'/'redis'/'mysql' 等）
     * @return bool
     */
    private function hasDriver(string $type): bool
    {
        foreach ($this->layers as $layer) {
            if ($layer->getName() === $type) return true;
        }
        return false;
    }

    // ==================== 日志与统计 ====================

    /**
     * 记录一次缓存事件（命中/回填/错误/锁…），两个出口，**都受 DEBUG 门控**：
     *  1. DEBUG > 0：把事件追加进进程内静态环形数组 self::$events（超 10000 条先丢最旧一条），
     *     只给 getReport() 调试汇总消费，FPM worker 重启即清零；
     *  2. DEBUG > 1 且传了 $msg：才经 xn_log() 落 cache 通道日志（通道名不含 error ⇒ 线上 DEBUG=0 本来也不写）。
     *
     * 保留的修复记录（第六轮复审 R6-02）：原实现在**生产（DEBUG=0）下也逐事件 append**，
     * 满 10000 后每次都 array_shift（O(n) 搬移），长驻 FPM worker 每请求数百次调用累计成毫秒级无谓 CPU，
     * 而 getReport() 本来就只调试用 ⇒ 现整个记录动作挪进 DEBUG>0 分支。
     * 另：本函数原先与上层 `if ($val === null)` 各记一次同一 miss，重复分支已在上层删除。
     * ⚠ 因此**线上调用本函数是纯 no-op**，别指望靠它留审计痕迹（审计要走 LoggerService 的审计通道）。
     * @param string $event 事件名（hit/miss/set/backfill/error/giveup…，仅用于调试视图与日志文案）
     * @param string $key 缓存键
     * @param string $layer 层名（驱动 getName() 或 'lock'/'datasource' 等伪层名）
     * @param float $ms 本次操作耗时（毫秒，入数组时 round 到 2 位）
     * @param string $msg 附加说明；**非空才会写 cache 日志**（DEBUG>1 前提仍需满足）
     * @return void 无返回值
     */
    private function log(string $event, string $key, string $layer, float $ms = 0, string $msg = ''): void
    {
        // 第六轮复审修复（R6-02）：事件环形数组只在调试态（DEBUG>0）记录。
        // 生产（DEBUG=0）下原实现仍逐事件 append + 满 10000 后每次 array_shift（O(n) 搬移），
        // 长驻 FPM worker 上每个请求数百次调用累计成毫秒级无谓 CPU；且 getReport() 仅调试用。
        // 另：本行原与上层 `if ($val === null)` 重复记账同一 miss，已在上层删除重复分支。
        if (DEBUG > 0) {
            if (count(self::$events) > 10000) array_shift(self::$events);
            self::$events[] = [
                't'     => microtime(true),
                'event' => $event,
                'key'   => $key,
                'layer' => $layer,
                'ms'    => round($ms, 2),
                'msg'   => $msg,
            ];
        }
        if (DEBUG > 1 && $msg) {
            xn_log("[MultiLayerCache] {$event} {$key}@{$layer}: {$msg}", 'cache');
        }
    }

    /**
     * 记录一次 get() 命中/未命中与耗时到按秒分桶的进程内静态统计 self::$stats（跨实例、跨请求内共享，
     * 但非持久——FPM worker 重启即清零）。仅供 getReport() 调试汇总消费，不落盘。
     * @param bool  $hit 是否命中（null 视为 miss）
     * @param float $ms  本次操作耗时（毫秒）
     * @return void
     */
    private function recordStat(bool $hit, float $ms): void
    {
        $ts = time();
        if (!isset(self::$stats[$ts])) {
            self::$stats[$ts] = ['hit' => 0, 'miss' => 0, 'total_ms' => 0, 'count' => 0];
        }
        self::$stats[$ts][$hit ? 'hit' : 'miss']++;
        self::$stats[$ts]['total_ms'] += $ms;
        self::$stats[$ts]['count']++;
    }

    /**
     * 获取统计报告
     */
    public function getReport(): array
    {
        $totalHit = $totalMiss = $totalMs = $totalCount = 0;
        foreach (self::$stats as $ts => $s) {
            $totalHit   += $s['hit'];
            $totalMiss  += $s['miss'];
            $totalMs    += $s['total_ms'];
            $totalCount += $s['count'];
        }
        return [
            'layers'      => $this->layerNames,
            'total_ops'   => $totalCount,
            'hit'         => $totalHit,
            'miss'        => $totalMiss,
            'hit_ratio'   => $totalCount > 0 ? round($totalHit / $totalCount * 100, 2) : 0,
            'avg_ms'      => $totalCount > 0 ? round($totalMs / $totalCount, 2) : 0,
            'events'      => array_slice(self::$events, -100),
            'data_source' => $this->sourceName,
        ];
    }
}
