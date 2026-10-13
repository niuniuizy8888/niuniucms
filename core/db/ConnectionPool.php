<?php
/**
 * 数据库连接池（基础设施任务 4 重写）
 *
 * 支持 FPM 阻塞环境，按 FPM worker 进程复用连接（静态栈=每进程池）。
 * 读写分桶；读侧支持 slaves 配置（random/roundrobin/weight 策略）+ 主库降级；
 * 节点熔断（连续错误 N 次开断路，超时窗口后半开恢复）接入连接获取路径；
 * sqlite :memory: 每连接独立库——语义上不可池化，分配逻辑排除（每次新建）。
 *
 * 修复记录（任务 4，四缺陷逐项）：
 *   缺陷1 读桶恒连 master → 读候选节点 = slaves（按策略）+ master 兜底，按节点分桶
 *   缺陷2 熔断器写而不读 → getConnection 逐节点查熔断，开路节点跳过并降级到下一候选
 *   缺陷3 preWarm 无调用方 → 删除（FPM 池按需自预热：worker 首次取连接即建立，
 *          显式预热只会在低峰期白占连接；附带移除仅服务于它的 poolSize 字段与死字段 lastCheck）
 *   缺陷4 sqlite :memory: 池语义未定义 → 明确「不池化」：分配与归还均绕过池，每次新建连接
 */
class ConnectionPool
{
    private $config;
    private $type;
    private $tablepre;

    /** @var PDO[] 空闲连接栈（按节点键分桶：读/写各自独立） */
    private static $idleRead = [];
    /** @var PDO[] */
    private static $idleWrite = [];
    /** @var array 节点熔断状态：nodeKey => ['count'=>n,'time'=>t] */
    private static $circuitBreaker = [];
    /** @var array 连接 → 节点键（put 归还时定位分桶） */
    private static $pdoNode = [];

    /** @var int 熔断阈值：连续错误次数 */
    private const BREAK_ERRORS = 5;
    /** @var int 熔断开路窗口秒数（可经 config['breaker_seconds'] 覆盖，便于测试） */
    private $breakSeconds;

    /** @var int 空闲栈上限（每节点） */
    private $maxSize;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->type = $config['type'] ?? 'mysql';
        $this->tablepre = $config['master']['tablepre'] ?? '';
        $this->breakSeconds = (int)($config['breaker_seconds'] ?? 30);
        $this->maxSize = (int)($config['pool_max'] ?? 10);
    }

    /**
     * 获取读连接（slaves 按策略选择 + 主库兜底；熔断开路节点自动跳过）
     */
    public function getRead(): ?PDO
    {
        return $this->getConnection(false);
    }

    /**
     * 获取写连接（恒主库；主库熔断开路时返回 null 由调用方降级）
     */
    public function getWrite(): ?PDO
    {
        return $this->getConnection(true);
    }

    /**
     * 归还连接（按建连时的节点键入桶；:memory: sqlite 不入池）。
     * ⚠ 归还前事务收尾（审查修复）：调用方异常路径漏 commit/rollBack 时，连接可能带着
     * 未提交事务（行锁/next-key 锁未释放）回来——直接入栈会污染下一个借入者。先尝试回滚：
     * 成功则连接回到干净态可复用；回滚抛错（连接已坏/驱动状态不可信）则**不入池**，交给 GC 关闭。
     */
    public function put(?PDO $pdo, bool $isWrite = false): void
    {
        if (!$pdo) return;
        $oid = spl_object_id($pdo);
        $nodeKey = self::$pdoNode[$oid] ?? $this->masterNodeKey($isWrite);
        unset(self::$pdoNode[$oid]);
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable $e) {
            return; // 连接状态不可信：不入池，随 GC 关闭
        }
        $stack = &$this->stackRef($nodeKey);
        if (count($stack) < $this->maxSize) {
            $stack[] = $pdo;
        }
    }

    /**
     * 节点熔断是否开路（超过窗口自动半开恢复）
     */
    public function isCircuitBreaker(string $nodeKey): bool
    {
        if (!isset(self::$circuitBreaker[$nodeKey])) return false;
        $data = self::$circuitBreaker[$nodeKey];
        if ($data['count'] >= self::BREAK_ERRORS) {
            if (time() - $data['time'] < $this->breakSeconds) return true;
            unset(self::$circuitBreaker[$nodeKey]); // 半开恢复
        }
        return false;
    }

    /**
     * 上报节点连接错误（累计达阈值即开断路）
     */
    public function reportError(string $nodeKey): void
    {
        if (!isset(self::$circuitBreaker[$nodeKey])) {
            self::$circuitBreaker[$nodeKey] = ['count' => 0, 'time' => time()];
        }
        self::$circuitBreaker[$nodeKey]['count']++;
        self::$circuitBreaker[$nodeKey]['time'] = time();
    }

    /** 获取连接：逐候选节点尝试（熔断开路跳过；空闲复用优先；ping 失败重建并上报） */
    private function getConnection(bool $isWrite): ?PDO
    {
        // 缺陷 4：sqlite :memory: 每连接独立库，语义上不可池化——绕过池，每次新建
        if ($this->isMemorySqlite()) {
            return $this->createConnectionFor($this->config['master'], $isWrite);
        }
        foreach ($this->nodeCandidates($isWrite) as $node) {
            $nodeKey = $this->nodeKey($node, $isWrite);
            // 缺陷 2：熔断开路节点跳过（读侧降级到下一候选/主库；写侧返回 null 由调用方处理）
            if ($this->isCircuitBreaker($nodeKey)) continue;
            $pdo = $this->takeIdle($nodeKey);
            if ($pdo !== null) {
                if ($this->ping($pdo)) return $this->track($pdo, $nodeKey);
                $this->reportError($nodeKey); // ping 失败视作节点异常
            }
            $pdo = $this->createConnectionFor($node);
            if ($pdo !== null) return $this->track($pdo, $nodeKey);
            $this->reportError($nodeKey);
        }
        return null;
    }

    /**
     * 候选节点有序列表（缺陷 1：读侧 slaves 按策略选择 + 主库兜底；写侧恒主库）
     * @return array[] 节点配置数组
     */
    private function nodeCandidates(bool $isWrite): array
    {
        $master = $this->config['master'] ?? [];
        if ($isWrite) return array($master);
        $slaves = $this->config['slaves'] ?? [];
        if (empty($slaves) || !is_array($slaves)) return array($master);
        $candidates = array($this->selectSlaveNode(array_values($slaves)));
        $candidates[] = $master; // 从库失败/熔断 → 主库兜底
        return $candidates;
    }

    /** 从库策略选择（与 PdoDriver 同口径：random 默认 / roundrobin / weight） */
    private function selectSlaveNode(array $slaves): array
    {
        $strategy = $this->config['slave_strategy'] ?? 'random';
        // roundrobin 语义说明：static 计数器=请求内/进程内轮询，FPM 每 worker 独立计数——
        // 全局近似均匀（各 worker 分摊起点）；跨 worker 严格全局轮询需共享计数器（APCu/Redis），
        // 收益低于成本，不引入。
        if ($strategy === 'weight') {
            $pool = array();
            foreach ($slaves as $s) {
                $w = max(1, (int)($s['weight'] ?? 1));
                for ($i = 0; $i < $w; $i++) $pool[] = $s;
            }
            return $pool[array_rand($pool)] ?? $slaves[0];
        }
        if ($strategy === 'roundrobin') {
            static $rr = 0;
            $idx = (++$rr) % count($slaves);
            return $slaves[$idx] ?? $slaves[0];
        }
        return $slaves[array_rand($slaves)] ?? $slaves[0];
    }

    /**
     * 建连接（按节点配置）；失败返回 null（调用方上报熔断）。
     * ⚠ 选项集已对齐父类：原先这里自己拼 ERRMODE/FETCH_ASSOC/EMULATE_PREPARES 三项，缺
     *   FOUND_ROWS 与 SQLite 的 STRINGIFY_FETCHES=false ⇒ db.type='proxy' 站点上回归
     *   「同值 UPDATE rowCount=0 ⇒ 上层走多余 REPLACE 兜底」，且数值列取回类型与主连接不一致。
     *   现统一走 PdoDriver::connOptions()（单一事实源）。FOUND_ROWS 对只读从库是无害的
     *   （它只影响 UPDATE 的 rowCount 语义），故主/从一律同一套选项，不再分叉。
     */
    private function createConnectionFor(array $cfg): ?PDO
    {
        $dsn = $this->buildDsn($cfg);
        $user = $cfg['user'] ?? $this->config['master']['user'] ?? '';
        $pass = $cfg['password'] ?? $this->config['master']['password'] ?? '';
        $opts = \PdoDriver::connOptions(
            (string)$this->type,
            !empty($this->config['persistent']) || !empty($cfg['persistent'])
        );
        try {
            return new PDO($dsn, $user, $pass, $opts);
        } catch (PDOException $e) {
            // 口径统一（P1-4）：建连失败不再只在 DEBUG 下留痕——读写分离站点的库连接失败属
            // 必须可观测的故障，与 PdoDriver::error() 的恒写 db_error 保持一致。
            function_exists('xn_log') && xn_log("Pool connect error: host=" . ($cfg['host'] ?? '?')
                . " type=" . $this->type . " : " . $e->getMessage(), 'db_error');
            return null;
        }
    }

    /**
     * 按实例方言拼 DSN：pgsql（默认端口 5432）/ sqlite（name 即库路径，可为 :memory:）/
     * 其余一律按 mysql（默认端口 3306，charset=utf8mb4）。$cfg 缺 host/port/name 键会拼出残缺 DSN，
     * 建连时由 PDO 抛错兜底（调用方 createConnectionFor catch 后交熔断计数）。
     * @param array $cfg 节点配置（host/port/name 等）
     * @return string 签名已声明 string
     */
    private function buildDsn(array $cfg): string
    {
        switch ($this->type) {
            case 'pgsql':
                $port = $cfg['port'] ?? 5432;
                return "pgsql:host={$cfg['host']};port={$port};dbname={$cfg['name']}";
            case 'sqlite': return "sqlite:{$cfg['name']}";
            default:
                $port = $cfg['port'] ?? 3306;
                return "mysql:host={$cfg['host']};port={$port};dbname={$cfg['name']};charset=utf8mb4";
        }
    }

    /**
     * 空闲连接复用前的活性探测：SELECT 1 恒真只用来暴露断连。
     * PDO 异常（服务端重启/连接被回收）⇒ false，调用方丢弃该连接并 reportError 计入节点熔断。
     * @param PDO $pdo
     * @return bool 签名已声明
     */
    private function ping(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** :memory: sqlite 判定（缺陷 4：每连接独立库，不可池化） */
    private function isMemorySqlite(): bool
    {
        if ($this->type !== 'sqlite') return false;
        if (($this->config['master']['name'] ?? '') === ':memory:') return true;
        foreach (($this->config['slaves'] ?? array()) as $s) {
            if (($s['name'] ?? '') === ':memory:') return true;
        }
        return false;
    }

    /**
     * 取节点空闲栈的**引用**（put/takeIdle 共用，桶不存在先建空数组）。
     * 分桶规则：节点键尾字符 'W' ⇒ 写桶 idleWrite，否则读桶 idleRead——同节点读写永互不串用。
     * @param string $nodeKey
     * @return PDO[] 引用的数组桶
     */
    private function &stackRef(string $nodeKey)
    {
        if (substr($nodeKey, -1) === 'W') {
            if (!isset(self::$idleWrite[$nodeKey])) self::$idleWrite[$nodeKey] = array();
            return self::$idleWrite[$nodeKey];
        }
        if (!isset(self::$idleRead[$nodeKey])) self::$idleRead[$nodeKey] = array();
        return self::$idleRead[$nodeKey];
    }

    /**
     * 从节点空闲栈弹出一个连接（LIFO，array_pop）；栈空返回 null ⇒ 调用方新建。
     * 注意取出≠可用，必须再经 ping 活性探测。
     * @param string $nodeKey
     * @return PDO|null 签名已声明
     */
    private function takeIdle(string $nodeKey): ?PDO
    {
        $stack = &$this->stackRef($nodeKey);
        return empty($stack) ? null : array_pop($stack);
    }

    /**
     * 登记 连接对象 → 节点键 映射（spl_object_id 为键），供 put() 归还时找回原桶，
     * 并原样返回该连接以便 getConnection 链式 return。
     * ⚠ 映射在进程静态表里跨请求累积，对象销毁后其槽位不回收（归还需同 id 命中）。
     * @param PDO $pdo
     * @param string $nodeKey
     * @return PDO 签名已声明（同一实例）
     */
    private function track(PDO $pdo, string $nodeKey): PDO
    {
        self::$pdoNode[spl_object_id($pdo)] = $nodeKey;
        return $pdo;
    }

    /**
     * 主库在当前读写角色下的桶键（put() 遇无映射连接时的兜底定位；
     * 与 nodeKey 同口径，master 配置缺失时按空节点计算）。
     * @param bool $isWrite TRUE=写桶 W，FALSE=读桶 R
     * @return string 签名已声明
     */
    private function masterNodeKey(bool $isWrite): string
    {
        return $this->nodeKey($this->config['master'] ?? array(), $isWrite);
    }

    /**
     * 节点桶键：md5(host|port|name) + 读写标记（'W'/'R' 作为**尾字符**拼在散列外）——
     * 同一节点读写不同键，且 stackRef 依赖尾字符把写连接归入 idleWrite 桶。
     * ⚠ 历史缺陷（审查修复）：旧实现把 W/R 标记拼在 md5 **输入里** ⇒ 键尾恒为十六进制字符，
     * stackRef 的 `substr(-1)==='W'` 永远不成立 ⇒ idleWrite 是死桶，读写连接全落 idleRead
     * （功能靠键不同凑合正确，但写桶永远空、违背注释口径）。
     * @param array $cfg 节点配置（缺失键按空串参与拼接）
     * @param bool $isWrite 是否写连接
     * @return string 节点键（32 位 md5 + 'W'/'R'）
     */
    private function nodeKey(array $cfg, bool $isWrite): string
    {
        return md5(($cfg['host'] ?? '') . '|' . ($cfg['port'] ?? '') . '|' . ($cfg['name'] ?? '')) . ($isWrite ? 'W' : 'R');
    }
}
