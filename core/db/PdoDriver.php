<?php
/**
 * 通用 PDO 数据库驱动 — 支持 MySQL / PostgreSQL / SQLite
 * 单驱动同时承载两种拓扑：直连（db.type=pdo_mysql）与经 ProxyDriver + ConnectionPool 的
 * 读写分离（db.type=proxy，见 .skills/读写分离使用说明.md）；本类是共用的执行层。
 * 既定纪律：ATTR_EMULATE_PREPARES=false（真·服务端预编译）、严格 sql_mode 不旁路（1406 属特性）。
 */
class PdoDriver
{
    /** @var array */
    public $conf = [];
    public $wlink = null;
    public $rlink = null;
    public $link = null;
    /**
     * @var int|string 最近一次错误码，类型随失败阶段两套（本机 PHP 8.5 实测）：
     *  - 连接期（connect/connectSlave 的 PDOException）= **int** 驱动码（如 2002/1049/1045），
     *    因此 db_errstr_safe() 的 1049/2003/1024/1045 友好文案映射在这一路是生效的；
     *  - 查询期（query/fetch 等异常直传 $e->getCode()）= SQLSTATE **字符串**（如 'HY000'/'42S22'），
     *    与上面那组 int 恒不相等（PHP 8 的「数字字符串 vs int」比较语义已实测确认）。
     *    ⚠ 这一形态曾让 db_errstr_safe() 的定向表必然失配、把驱动原文（含库名/表名/SQL 片段）当
     *    对外提示返回；现由 db_err_code()（core/db/db.php）归一——先从 errstr 抽四位驱动码，
     *    抽不到再按 SQLSTATE 语义映射，因此这一路的友好文案现在也生效。
     *      该原文的可见出口只有安装器（core/install/index.php 的 message("... errstr: $errstr")，
     *      受 install.lock 保护）；生产前台/后台不读全局 $errstr，故不构成线上信息泄露面。
     * 消费方（db_errno_errstr → 全局 $errno）只做字符串拼接，勿按 int 语义比较本属性。
     */
    public $errno = 0;
    /** @var string */
    public $errstr = '';
    /** @var array */
    public $sqls = [];
    /** @var string */
    public $tablepre = '';
    /** @var string */
    public $type = 'mysql';
    /** @var array 从库连接列表（负载均衡）*/
    private $slaveLinks = [];
    /** @var int 轮询计数器 */
    private static $roundRobin = 0;

    // ===== 任务 5：读写分离路由（全开关默认关闭 = 回滚即全走主库） =====
    /** @var bool 读写分离开关（conf 'rw_split'）：SELECT 走读连接 */
    public $rwSplit = false;
    /** @var bool 影子模式（conf 'rw_shadow'）：只记录路由决策，执行恒主库 */
    public $rwShadow = false;
    /** @var int 写后粘滞秒数（conf 'rw_sticky_seconds'，0=关闭）：写后 N 秒内读走主库 */
    public $rwStickySeconds = 0;
    /** @var int 写后粘滞截止时间戳 */
    protected $stickyUntil = 0;
    /** @var array 影子路由日志（rwShadow=true 时记录，上限 100 条） */
    protected static $shadowLog = array();
    /** @var int 影子落盘采样序号（进程内计数；观察期采样用） */
    private static $shadowSeq = 0;
    /** @var array 从库健康状态：nodeKey => ['fails'=>n,'until'=>t]（任务 5 步骤⑤） */
    private static $slaveHealth = array();
    /** @var int 从库连败摘除阈值 */
    private const SLAVE_MAX_FAILS = 3;
    /** @var int 从库连败冷却秒数 */
    private const SLAVE_COOLDOWN = 10;
    /** 跨请求写后粘滞载体 cookie 名（rw_sticky_seconds>0 时随写操作下发，HttpOnly+SameSite=Lax） */
    const STICKY_COOKIE = 'nncms_rw_sticky';
    /** @var int 事务嵌套深度（0=不在事务；>0=事务内全部查询含 SELECT 恒走写连接） */
    protected $txDepth = 0;
    /** @var bool 嵌套事务内层已回滚（外层 commit 不得真提交） */
    protected $txRollbackPending = false;
    /** @var bool 本请求粘滞 cookie 已下发（防重复 Set-Cookie） */
    private $stickyCookieSent = false;

    public function __construct($conf)
    {
        $this->conf = $conf;
        $this->tablepre = $conf['master']['tablepre'] ?? '';
        $this->type = $conf['type'] ?? 'mysql';
        $this->rwSplit = !empty($conf['rw_split']);
        $this->rwShadow = !empty($conf['rw_shadow']);
        $this->rwStickySeconds = max(0, (int)($conf['rw_sticky_seconds'] ?? 0));
    }

    /** @var array 测试钩子：表主键覆盖（供离线/方言测试注入；生产恒走 primaryKeys() 内省） */
    public static $pkOverride = array();

    /**
     * 标识符引用（任务 3 方言层）：mysql=反引号（含转义）；pgsql/sqlite=双引号。
     * mysql 输出与历史逐字节一致。
     */
    public function qi(string $name): string
    {
        // 上线审核轮 P1-3：与 QueryBuilder::qi() 同口径——MySQL 族（mysql/pdo_mysql/未知默认）
        // 反引号；仅 pg/sqlite 双引号。原精确匹配会让生产 pdo_mysql 落双引号分支（1064 隐患）。
        $t = (string)$this->type;
        if ('pgsql' === $t || 'postgres' === $t || 'sqlite' === $t) {
        return '"' . str_replace('"', '""', $name) . '"';
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * 分页 LIMIT 子句：mysql=LIMIT o,p（历史形态不变）；pgsql=LIMIT p OFFSET o；
     * sqlite 按既定决策保留 LIMIT o,p 现状（任务 3 范围外）。
     */
    public function limitPageSql(int $offset, int $pagesize): string
    {
        if ($this->type === 'pgsql') return "LIMIT {$pagesize} OFFSET {$offset}";
        return "LIMIT {$offset},{$pagesize}";
    }

    /**
     * 表主键列内省（pgsql upsert 冲突目标；按 type+table 静态缓存一次）。
     * mysql 不需要（REPLACE INTO 原生）；sqlite 不需要（REPLACE INTO 原生）。
     */
    public function primaryKeys(string $table): array
    {
        static $cache = array();
        $key = $this->type . ':' . $this->tablepre . $table;
        if (isset(self::$pkOverride[$key])) return self::$pkOverride[$key];
        if (isset($cache[$key])) return $cache[$key];
        $pk = array();
        if ($this->type === 'pgsql' && $this->wlink) {
            try {
                $stmt = $this->wlink->prepare("SELECT a.attname FROM pg_index i JOIN pg_class c ON c.oid = i.indrelid JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY(i.indkey) WHERE i.indisprimary AND c.relname = ?");
                $stmt->execute(array($this->tablepre . $table));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $pk[] = $r['attname'];
                }
            } catch (\Throwable $e) {
                $pk = array();
            }
        }
        return $cache[$key] = $pk;
    }

    /**
     * 按驱动类型拼 PDO DSN：pgsql=host/port/dbname；sqlite=文件路径（name 键直接作路径）；
     * 其余（mysql/pdo_mysql/未知默认）按 MySQL 形态并固定 charset=utf8mb4。
     * port 缺省：pgsql 5432、MySQL 3306。connect()/connectSlave() 建连前调用。
     * @param array $cfg 节点配置（host/port/name 键）
     * @param string $type 驱动类型（PdoDriver::$type 口径）
     * @return string DSN
     */
    protected function buildDsn(array $cfg, string $type): string
    {
        switch ($type) {
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
     * 建连选项工厂（**单一事实源**）：connect() 与 ConnectionPool::createConnectionFor() 都用它，
     * 避免池侧漏配造成两种拓扑行为分叉。
     * 历史缺陷：池建连只设了 ERRMODE / FETCH_ASSOC / EMULATE_PREPARES，缺下面两项，
     * 于是 db.type='proxy'（读写分离）站点上
     *  ① 缺 FOUND_ROWS ⇒ 同值 UPDATE 的 rowCount 返回「改动行数」而非「匹配行数」= 0，
     *     上层判定走多余的 REPLACE 兜底（P1-3 当年在主连接上就是为这个加的）；
     *  ② 缺 SQLite 的 STRINGIFY_FETCHES=false ⇒ 数值列以字符串回，测试/比对口径与主连接不一致。
     * ⚠ 有意不套用到 connectSlave()：只读节点不做写，FOUND_ROWS 无意义，那里保留自己的精简集
     *   （见其方法头说明），故本方法的 $foundRows 形参留给主连接/池使用。
     * @param string $type      驱动类型（conf['db']['type']：mysql / pdo_mysql / pgsql / postgres / sqlite）
     * @param bool   $persistent 是否持久连接（默认关闭，理由见下）
     * @param bool   $foundRows  是否设 FOUND_ROWS（MySQL 族才生效）
     * @return array PDO 构造选项数组
     */
    public static function connOptions(string $type, bool $persistent = false, bool $foundRows = true): array
    {
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        // 性能项（可选）：PDO 持久连接——FPM 每 worker 跨请求复用 TCP+握手。
        // 默认关闭：持久连接会跨请求保留会话状态（未提交事务/临时表/SET SESSION），须确认无此类写入后
        // 由 config 显式开启（db.persistent=1）。
        if ($persistent) {
            $opts[PDO::ATTR_PERSISTENT] = true;
        }
        // SQLite 测试环境：关闭字符串化取回，让数值列返回原生类型
        // （PHP 7.4 的 PDO_SQLITE 忽略该选项仍返回字符串，测试断言已用宽松比较兼容）
        if ($type === 'sqlite') {
            $opts[PDO::ATTR_STRINGIFY_FETCHES] = false;
        }
        // P1-3：MySQL 族（含生产 pdo_mysql）都要 FOUND_ROWS——否则同值 UPDATE 返回 0，
        // rowCount=改动行非匹配行，判定路径走多余 REPLACE 兜底（会话写有回读，其它写没有）
        if ($foundRows && !in_array($type, array('pgsql', 'postgres', 'sqlite'), TRUE)) {
            // （PHP 8.5 交叉版本实测）：`PDO::MYSQL_ATTR_FOUND_ROWS` 自 8.5 起弃用
            // （Deprecated: Constant PDO::MYSQL_ATTR_FOUND_ROWS is deprecated since 8.5,
            //  use Pdo\Mysql::ATTR_FOUND_ROWS instead）——每次建连都刷一条 Deprecated。
            // 二者**取值相同**，故按版本择一：8.5+ 且新类常量存在时用新名，8.0～8.4 用旧名。
            // （项目下限现为 8.0，见 core/install/index.php 的 NNCMS_PHP_MIN，故不再考虑 7.x 形态。）
            $foundRowsAttr = (PHP_VERSION_ID >= 80500 && defined('Pdo\Mysql::ATTR_FOUND_ROWS'))
                ? constant('Pdo\Mysql::ATTR_FOUND_ROWS')
                : PDO::MYSQL_ATTR_FOUND_ROWS;
            $opts[$foundRowsAttr] = true;
        }
        return $opts;
    }

    /**
     * 连接数据库
     */
    public function connect(): ?PDO
    {
        if ($this->wlink) return $this->wlink;
        $cfg = $this->conf['master'];
        $dsn = $this->buildDsn($cfg, $this->type);
        $user = $cfg['user'] ?? '';
        $pass = $cfg['password'] ?? '';
        // 建连选项统一由 connOptions() 提供（与 ConnectionPool 共用，避免池侧漏配导致行为分叉——
        // 历史缺陷：池建连缺 FOUND_ROWS / SQLite STRINGIFY_FETCHES，读写分离站点回归「同值 UPDATE
        // rowCount=0 ⇒ 多余 REPLACE 兜底」）。$this->type 与持久连接开关在此传入。
        $opts = self::connOptions((string)$this->type, !empty($this->conf['persistent']) || !empty($cfg['persistent']));
        try {
            $link = new PDO($dsn, $user, $pass, $opts);
            $this->wlink = $this->rlink = $link;
            return $link;
        } catch (PDOException $e) {
            $this->error($e->getCode(), '连接数据库失败:' . $e->getMessage());
            return null;
        }
    }

    /**
     * 关闭驱动引用：把写/读/当前连接句柄置 null（PDO 对象随引用计数释放；
     * 持久连接场景下底层连接由 PDO 扩展自行管理，不代表物理断开）。
     * @return bool 恒 true（兼容旧 db 接口签名，无失败路径）
     */
    public function close(): bool
    {
        $this->wlink = $this->rlink = $this->link = null;
        return true;
    }

    /**
     * 查询路由（任务 5 + 第十七批补强）：
     * 事务内恒走写连接；锁定读（FOR UPDATE/FOR SHARE/LOCK IN SHARE MODE）恒走写连接；
     * SELECT 且读写分离开启且不在写后粘滞窗口（请求内属性/跨请求 cookie）→ 读连接；其余 → 写连接。
     * rw_shadow=true：仅记录「本应路由」的决策（执行恒主库），sticky_src 记录粘滞来源（request/cookie），供切流量前验证。
     * 开关全关 = 全走主库（回滚语义）。
     */
    protected function routeLink(string $sql): ?PDO
    {
        // 事务感知（任务5 补强）：事务必须整体跑在同一连接上——
        // 从库看到的是事务前快照，事务内 SELECT 走从库=读不到自己未提交的写
        if ($this->txDepth > 0) return $this->getWriteLink();
        $isSelect = (bool)preg_match('/^\s*\(?\s*SELECT\b/i', $sql);
        // 锁定读（SELECT...FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE）必须走写连接：
        // 在从库上锁不到主库行（read_only=ON 报 1290；未设 read_only=静默无锁竞态）。
        // 宽松匹配（字符串字面量可能误报）——误报代价=多走主库（保守方向），漏报代价=竞态。
        $isLockingRead = (bool)preg_match('/\bFOR\s+(?:UPDATE|SHARE)\b/i', $sql)
            || (bool)preg_match('/\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $sql);
        $stickyReq = $this->stickyUntil > time();
        // 跨请求粘滞（cookie 载体）：PHP-FPM 每请求重建驱动实例，实例属性只覆盖请求内；
        // 写后下发的短 TTL cookie 让下一个请求（任意 worker）仍走主库，read-your-writes 跨请求生效
        $stickyCk = $this->hasStickyCookie();
        $sticky = $stickyReq || $stickyCk;
        $stickySrc = $stickyReq ? 'request' : ($stickyCk ? 'cookie' : '');
        $wouldSplit = $this->rwShadow ? true : $this->rwSplit; // 影子=预览「如果开启」的路由
        $route = ($isSelect && !$isLockingRead && $wouldSplit && !$sticky) ? 'read' : 'write';
        if ($this->rwShadow) {
            $sqlCompact = substr(trim((string)preg_replace('/\s+/', ' ', $sql)), 0, 120);
            self::$shadowLog[] = array(
                'sql' => $sqlCompact,
                'route' => $route,
                'sticky' => $sticky,
                'sticky_src' => $stickySrc,
            );
            if (count(self::$shadowLog) > 100) array_shift(self::$shadowLog);
            self::shadowSink($this->conf, $sqlCompact, $route, $stickySrc);
            return $this->getWriteLink(); // 影子模式：执行恒主库
        }
        return $route === 'read' ? $this->getReadLink() : $this->getWriteLink();
    }

    /** 影子路由日志（测试/验证用） */
    public static function shadowLog(): array
    {
        return self::$shadowLog;
    }

    /**
     * 影子日志落盘（第十七批附属三）：rw_shadow=1 时每条路由决策写专用文件，
     * 供观察期核对路由分布与 sticky_src 构成（此前 shadowLog 仅内存静态数组，请求即销毁=生产无出口）。
     * 不走 xn_log/LoggerService——info 通道被级别过滤（永久教训：日志必须确认真落盘），观察数据直写文件。
     * 开关：rw_shadow=1 即启用（观察模式隐含要数据）；rw_shadow_sink=false 显式关闭（测试/静音）。
     * 采样：rw_shadow_sample（1-100，默认 100=全量；观察期 QPS 不高→全量）；N<100 按计数器取模均匀采样，
     * 实际采样率 ≥ 配置值（间隔向下取整，宁多勿漏）。
     * 体积护栏：单文件 >64MB 轮转 .prev（磁盘占用上限 128MB）。
     * 行格式：Y-m-d H:i:s|R或W|src=-|request|cookie|rid=请求ID|SQL(紧凑化,≤120)
     */
    private static function shadowSink(array $conf, string $sql, string $route, string $stickySrc): void
    {
        if (isset($conf['rw_shadow_sink']) && !$conf['rw_shadow_sink']) return;
        $sample = max(1, (int)($conf['rw_shadow_sample'] ?? 100));
        self::$shadowSeq++;
        if ($sample < 100) {
            $every = max(1, (int)floor(100 / $sample));
            if ($every > 1 && self::$shadowSeq % $every !== 0) return;
        }
        $path = isset($conf['rw_shadow_log']) && $conf['rw_shadow_log'] !== ''
            ? (string)$conf['rw_shadow_log']
            : (defined('APP_PATH') ? APP_PATH . 'runtime/log/rw_shadow.log' : sys_get_temp_dir() . '/nncms_rw_shadow.log');
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $size = @filesize($path);
        if ($size !== false && $size > 67108864) @rename($path, $path . '.prev');
        $rid = isset($_SERVER['REQUEST_ID']) ? (string)$_SERVER['REQUEST_ID'] : '-';
        $line = date('Y-m-d H:i:s') . '|' . ($route === 'read' ? 'R' : 'W') . '|src=' . ($stickySrc !== '' ? $stickySrc : '-') . '|rid=' . $rid . '|' . $sql . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 清空进程内影子路由日志数组（self::$shadowLog，测试/验证用；上限 100 条的内存缓冲）。
     * 只清内存视图，已落盘的 rw_shadow.log 不受影响。
     * @return void
     */
    /** 写操作成功后标记粘滞窗口（读你的写）：请求内走实例属性，跨请求走短 TTL cookie */
    private function markWrite(): void
    {
        if ($this->rwStickySeconds > 0) {
            $this->stickyUntil = time() + $this->rwStickySeconds;
            $this->sendStickyCookie();
        }
    }

    /** 读侧：跨请求粘滞 cookie 是否有效（粘滞总开关 rw_sticky_seconds=0 时不生效） */
    protected function hasStickyCookie(): bool
    {
        return $this->rwStickySeconds > 0
            && isset($_COOKIE[self::STICKY_COOKIE])
            && $_COOKIE[self::STICKY_COOKIE] === '1';
    }

    /**
     * 写侧：下发跨请求粘滞 cookie（TTL=rw_sticky_seconds，随写操作滑动续期）。
     * path/domain/secure 取 session_get_cookie_params()（禁止自拼 path/domain——
     * 两份同名不同 path 会让失效变随机事件）；CLI 静默跳过；headers 已发出时跳过并记
     * rw_sticky 日志一次（=本写操作的跨请求粘滞失效，rw_shadow 观察期据此发现环境问题，
     * 如反向代理/CDN 剥 Set-Cookie、渲染中写且无输出缓冲）。客户端伪造最多把自己的读
     * 钉在主库（负载放大方向），无正确性影响。
     */
    protected function sendStickyCookie(): void
    {
        if ($this->stickyCookieSent) return;
        if (PHP_SAPI === 'cli') return;
        if (headers_sent()) {
            // 写执行时输出已开始（无输出缓冲的渲染中写）：cookie 无法下发，
            // 本写操作的跨请求粘滞将失效——记一次性日志，rw_shadow 观察期据此发现环境问题
            $this->stickyCookieSent = true;
            function_exists('xn_log') and xn_log('rw_sticky cookie skipped: headers already sent at write time (mid-render write without output buffering)', 'rw_sticky');
            return;
        }
        $p = function_exists('session_get_cookie_params') ? session_get_cookie_params() : array();
        $opt = array(
            'expires' => time() + $this->rwStickySeconds,
            'path' => isset($p['path']) && $p['path'] !== '' ? $p['path'] : '/',
            'secure' => !empty($p['secure']),
            'httponly' => true,
            'samesite' => 'Lax',
        );
        if (!empty($p['domain'])) $opt['domain'] = $p['domain'];
        setcookie(self::STICKY_COOKIE, '1', $opt);
        $this->stickyCookieSent = true;
    }

    // ===== 事务 API（任务5 补强：routeLink 感知事务，事务内恒写连接）=====

    /**
     * 开启事务（嵌套计数：实际 BEGIN 只在最外层发出）。
     * 事务内全部查询（含 SELECT）经 routeLink 恒走写连接。
     */
    public function beginTransaction(): bool
    {
        $link = $this->getWriteLink();
        if (!$link) return false;
        if ($this->txDepth === 0) {
            try {
                $link->beginTransaction();
            } catch (PDOException $e) {
                $this->error($e->getCode(), $e->getMessage());
                return false;
            }
            $this->txRollbackPending = false;
        }
        $this->txDepth++;
        return true;
    }

    /**
     * 提交（最外层才真正 commit；提交后开启写后粘滞窗口——同请求内后续读见得到提交数据）。
     * 嵌套内层已回滚时，外层 commit 整体回滚并返回 false。
     */
    public function commit(): bool
    {
        if ($this->txDepth === 0) return false;
        $this->txDepth--;
        if ($this->txDepth > 0) return true;
        $link = $this->getWriteLink();
        if (!$link) return false;
        try {
            if ($this->txRollbackPending || !$link->inTransaction()) {
                $wasRollbackPending = $this->txRollbackPending;   // 先留档：下面会复位，日志要按复位前的成因分类
                if ($link->inTransaction()) $link->rollBack();
                $this->txRollbackPending = false;
                // ⚠ 留痕区分两种「已不在事务中」（收尾轮修复 S2 的可诊断性）：
                //   ① txRollbackPending：内层已标记回滚 ⇒ 本次提交被整体放弃（真失败，写入未落库）；
                //   ② 仅 !inTransaction()：驱动侧事务已结束且我们没标记过回滚——最可能是**回调内执行了
                //      DDL**（MySQL 隐式提交，见 QueryBuilder::transaction() docblock 的禁令），
                //      此时写入其实**已经落库**，返回 false 只是「无法再 commit」。两种情况都保持返回
                //      false（保守：宁可让调用方以为失败，也不谎报成功），但日志里必须能分辨，
                //      否则接线时会把「已提交」误判成「已回滚」。
                function_exists('xn_log') and xn_log('commit(): 事务已不在活动状态（'
                    . ($wasRollbackPending ? 'txRollbackPending=内层已回滚，写入未落库' : 'inTransaction()=false，多为回调内 DDL 隐式提交，写入可能已落库')
                    . '）', 'db_error');
                return false;
            }
            $link->commit();
        } catch (PDOException $e) {
            // 提交失败兜底：连接若仍留在事务中先回滚——防止带开事务的连接归还连接池污染复用
            if ($link->inTransaction()) {
                try { $link->rollBack(); } catch (\Throwable $ignored) {}
            }
            $this->error($e->getCode(), $e->getMessage());
            return false;
        }
        $this->markWrite();
        return true;
    }

    /**
     * 回滚（最外层才真正 rollback；嵌套内层回滚=标记放弃，外层 commit 时整体回滚）。
     */
    public function rollBack(): bool
    {
        if ($this->txDepth === 0) return false;
        $this->txDepth--;
        if ($this->txDepth > 0) {
            $this->txRollbackPending = true;
            return true;
        }
        $link = $this->getWriteLink();
        if (!$link) return false;
        try {
            if ($link->inTransaction()) $link->rollBack();
        } catch (PDOException $e) {
            $this->error($e->getCode(), $e->getMessage());
            return false;
        }
        $this->txRollbackPending = false;
        return true;
    }

    /** 是否在事务内（routeLink 事务感知依据） */
    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    /**
     * 执行 SQL 返回第一行
     */
    public function sql_find_one($sql)
    {
        $link = $this->routeLink($sql);
        if (!$link) return false;
        $this->logSql($sql);
        try {
            $stmt = $link->query($sql);
            $r = $stmt->fetch();
            return $r === false ? null : $r;
        } catch (PDOException $e) {
            $this->error($e->getCode(), $e->getMessage());
            return false;
        }
    }

    /**
     * 执行 SQL 返回所有行
     */
    public function sql_find($sql, $key = null)
    {
        $link = $this->routeLink($sql);
        if (!$link) return false;
        $this->logSql($sql);
        try {
            $stmt = $link->query($sql);
            $rows = $stmt->fetchAll();
            if ($key && $rows) {
                $arr = [];
                foreach ($rows as $r) {
                    $arr[$r[$key]] = $r;
                }
                return $arr;
            }
            return $rows;
        } catch (PDOException $e) {
            $this->error($e->getCode(), $e->getMessage());
            return false;
        }
    }

    /**
     * 执行写操作 SQL，返回影响行数或 lastInsertId
     */
    public function exec($sql)
    {
        $link = $this->routeLink($sql);
        if (!$link) return false;
        try {
            $this->logSql($sql);
            $isSelect = (bool)preg_match('/^\s*\(?\s*SELECT\b/i', $sql);
            $isInsert = preg_match('#^\s*(INSERT|REPLACE)#i', $sql);
            $n = $link->exec($sql);
            if ($n === false) return false;
            if (!$isSelect) $this->markWrite();
            return $isInsert ? (int)$link->lastInsertId() : $n;
        } catch (PDOException $e) {
            $this->error($e->getCode(), $e->getMessage());
            return false;
        }
    }

    /**
     * 预编译执行
     */
    /** @return PDOStatement|false */
    public function prepare_execute(string $sql, array $params = [])
    {
        $link = $this->routeLink($sql);
        if (!$link) return false;
        try {
            $this->logSql($sql);
            $stmt = $link->prepare($sql);
            $stmt->execute($params);
            // 写后粘滞：非 SELECT 成功执行即开启写后窗口（读你的写）
            if (!preg_match('/^\s*\(?\s*SELECT\b/i', $sql)) $this->markWrite();
            return $stmt;
        } catch (PDOException $e) {
            $this->error($e->getCode(), $e->getMessage());
            return false;
        }
    }

    /**
     * 安全转义字符串值（PDO::quote，字符集感知）
     * @return string|false 带引号的转义字符串，如 'value'；无连接时返回 false
     */
    public function quote(string $val)
    {
        $link = $this->getWriteLink();
        if (!$link) return false;
        return $link->quote($val);
    }

    // ========== 高级查询 ==========

    /**
     * 按「表 + 条件 + 排序 + 分页」拼一条 SELECT 取回多行（本驱动的高级查询入口；
     * 上层 db_find() 过程式 helper 与 DbTableRepository::find 都转发到这里）。
     * 语句形态 `SELECT 列清单 FROM 前缀+表 条件 排序 LIMIT/OFFSET`，**整句拼好后交 sql_find()**，
     * 不是参数绑定路径（区别于 QueryBuilder 预编译链，红线 §二.3）：
     *  - 条件由 db_cond_to_sqladd() 生成（值走连接层 quote、`列 => 数组` 展开成 IN，支持比较符写法）；
     *  - 排序由 db_orderby_to_sqladd() 生成；
     *  - 列清单交 selectColsSql()：纯列名/`tbl.col` 加方言引号、表达式原样直出；
     *    非法元素跳过，**留空或全非法退化成 `SELECT *`**
     *    （裸星号会被 DbTableRepository 记 sql_star_select 日志，性能红线 §三.2）；
     *  - 分页是 `LIMIT offset,size` 形态（limitPageSql），深分页 OFFSET 仍是技术债，新列表优先 keyset 游标；
     *  - $page 只钳下限到 1，**不钳上限**，越界页只是查不到数据。
     * 读路径经 routeLink() 走读写分离（事务内、锁定读、写后粘滞窗口内恒走写连接）。
     * @param string $table 逻辑表名（不含前缀，内部自动补 $this->tablepre）
     * @param array $cond 条件数组（db_cond_to_sqladd 语法）；留空 = 无条件全表
     * @param array $orderby 排序数组（db_orderby_to_sqladd 语法）
     * @param int $page 页码，1 起
     * @param int $pagesize 每页条数，默认 10
     * @param string $key 以该列值作结果外层键（等价 array_change_key）；留空 = 连续下标
     * @param array $col 列清单（白名单过滤）；留空 = 星号全列
     * @return array|false 行数组（$key 非空且有数据时为「列值 => 行」映射；无数据是空数组）；
     *                     无连接或 SQL 异常为 FALSE（异常被吞，细节进 error()/日志）
     */
    public function find($table, $cond = [], $orderby = [], $page = 1, $pagesize = 10, $key = '', $col = [])
    {
        $page = max(1, $page);
        $condadd = db_cond_to_sqladd($cond);
        $orderadd = db_orderby_to_sqladd($orderby);
        $offset = ($page - 1) * $pagesize;
        $cols = $this->selectColsSql($col);
        $sql = "SELECT $cols FROM {$this->tablepre}$table $condadd $orderadd " . $this->limitPageSql($offset, $pagesize);
        return $this->sql_find($sql, $key);
    }

    /**
     * 按条件取单行：拼 SELECT（表名自动带 tablepre 前缀，$col 列清单交 selectColsSql()）
     * 后走 sql_find_one（经 routeLink 读写分离路由，LIMIT 1）。
     * @param string $table 逻辑表名（不含前缀）
     * @param array $cond db_cond_to_sqladd 条件数组
     * @param array $orderby db_orderby_to_sqladd 排序数组
     * @param array $col 列清单（纯列名/`tbl.col`/表达式三形态；非法元素跳过，全非法回退 '*'）
     * @return array|null|false 行关联数组；无数据 null；连接失败或 SQL 异常 false
     */
    public function find_one($table, $cond = [], $orderby = [], $col = [])
    {
        $condadd = db_cond_to_sqladd($cond);
        $orderadd = db_orderby_to_sqladd($orderby);
        $cols = $this->selectColsSql($col);
        $sql = "SELECT $cols FROM {$this->tablepre}$table $condadd $orderadd LIMIT 1";
        return $this->sql_find_one($sql);
    }

    /**
     * 把 find()/find_one() 的列清单拼成 SQL 片段。
     * ⚠ 必须引用纯列名：MySQL 8.0 起 `rank` 是保留字（窗口函数），`SELECT rank FROM ...` 直接 1064
     *   （实测 8.0.45）；`nncms_cate.rank` / `nncms_link.rank` 都在本库。
     * 三种形态（与 QueryBuilder::qiColumn() 同口径）：
     *   · 纯列名、`tbl.col` → 各自加引号；
     *   · 表达式（`COUNT(*) AS n` / `GROUP_CONCAT(vid) as vids`）→ **原样直出**（受 safeColumns 的
     *     宽松白名单约束：仅字母数字下划线星号括号逗号点空格，且禁引号/反引号/分号/注释）；
     *   · 空清单 → `*`。
     * 项目审查修复：原实现只认纯列名、**静默丢弃**表达式与 `tbl.col` ——
     *   `['COUNT(*) AS n']` 会退化成 `SELECT *`（调用方读 $r['n'] 恒 null），
     *   `['cid','COUNT(*) AS n']` 更会退化成只查 `cid`（少列不报错，最坏的一种失败）。
     * @param array $col 列清单（空数组 = 全列）
     * @return string 可拼入 SELECT 的列片段（至少为 '*'）
     */
    protected function selectColsSql(array $col): string
    {
        if (empty($col)) return '*';
        $parts = array();
        foreach ($col as $c) {
            if (!is_string($c) || '' === $c) continue;
            if (preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $c)) {
                $parts[] = $this->qi($c);
            } elseif (preg_match('#^([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)$#', $c, $m)) {
                $parts[] = $this->qi($m[1]) . '.' . $this->qi($m[2]);
            } elseif ('*' === $c) {
                $parts[] = '*';
            } elseif ($this->isSafeColumnExpr($c)) {
                $parts[] = $c;   // 表达式原样直出，安全边界＝下面的宽松白名单
            }
        }
        return $parts ? implode(',', $parts) : '*';
    }

    /**
     * 表达式列白名单（M3 加固的补面）：允许函数/别名形态，拒绝引号与注入字符。
     * 口径与 QueryBuilder::select() 一致——只允许字母数字下划线星号括号逗号点空格，
     * 且再拒 `--` / `/*` / `#` / `;`。
     * @param string $c 列表达式
     * @return bool 合法为 TRUE
     */
    protected function isSafeColumnExpr(string $c): bool
    {
        return (bool)preg_match('#^[a-zA-Z0-9_*(),. ]+$#', $c) && !preg_match('~(--|/\*|#|;)~', $c);
    }

    /**
     * 行数统计：`SELECT COUNT(*)` + 条件。**$table 直接用全名、不自动拼 tablepre**
     * （与 find/find_one 不同口径，调用方须自带前缀；主链路统计现走 QueryBuilder）。
     * @param string $table 完整表名（含前缀）
     * @param array $cond db_cond_to_sqladd 条件数组
     * @return int 计数；查询失败/无结果为 0（不区分失败与空表）
     */
    public function count($table, $cond = [])
    {
        $condadd = db_cond_to_sqladd($cond);
        $r = $this->sql_find_one("SELECT COUNT(*) AS n FROM {$table} $condadd");
        return $r ? (int)$r['n'] : 0;
    }

    /**
     * 清空表：`TRUNCATE TABLE`（$table 全名直拼反引号，调用方如 db_truncate/Repository
     * 均显式带 tablepre）。
     * ⚠ 返回值已修：原实现丢弃 exec() 结果恒 return true ⇒ 上层（DbTableRepository::truncate、
     *   后台「清空全站数据」）无法感知失败，只能事后翻 db_error 日志。
     *   判据必须用**严格比较**：exec() 只在出错时返回 false，而 TRUNCATE 成功时返回受影响行数 0，
     *   写成 `return (bool)$r` 会把成功误判为失败。
     * @param string $table 完整表名（含前缀）
     * @return bool 语句执行成功为 true（含"本来就空"的 0 行）；连接不可用/SQL 报错为 false
     */
    public function truncate($table)
    {
        return FALSE !== $this->exec("TRUNCATE TABLE `$table`");
    }

    /**
     * 数据库服务端版本串：读连接 PDO::ATTR_SERVER_VERSION。
     * @return string 版本（如 8.0.x / 14.x / SQLite 版本）；无连接或异常时 ''（不抛错）
     */
    public function version(): string
    {
        $link = $this->getReadLink();
        if (!$link) return '';
        try {
            return $link->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (PDOException $e) {
            return '';
        }
    }

    // ========== 辅助方法 ==========

    /**
     * 取读连接（读写分离的读分流入口，routeLink()/version() 等只读路径经此拿句柄）。
     * 顺序：已有 $this->rlink 直接复用 ⇒ 一个请求内只会粘在同一个从库上（不会逐条查询换库）。
     *  - conf['slaves'] 为空（**出厂默认，rw_split 未开**）⇒ 跳过从库逻辑，直接 connect() 回主库；
     *  - 有从库时先经 healthySlaves() 过滤（任务 5 步骤⑤：连败达阈值的从库在冷却窗口内被摘除），
     *    再 selectSlave() 做负载均衡选一台；connectSlave() 建连成功就缓存进 $this->rlink
     *    并 slaveHealthy() 记成功；建连失败 slaveFailed() 记一笔、**不缓存失败句柄**；
     *  - 从库全不可用时**自动降级主库**（读可用性优先，不抛错、不返回 null）。
     * 注意：事务、锁定读与写后粘滞窗口由 routeLink()/getWriteLink() 负责，它们**不会**走到这里；
     * 影子模式（rw_shadow=1）也只记录决策不切流量，见 .skills/读写分离使用说明.md。
     * @return PDO|null 读连接句柄（从库或降级后的主库）；主库也连不上时为 null（connect() 已留痕）
     */
    protected function getReadLink(): ?PDO
    {
        // 如果已有读连接，直接使用
        if ($this->rlink) return $this->rlink;

        // 尝试从从库获取（负载均衡）
        $slaves = $this->conf['slaves'] ?? [];
        if (!empty($slaves)) {
            // 任务 5 步骤⑤：从库健康摘除——连败达阈值的从库在冷却窗口内被跳过
            $slaves = self::healthySlaves($slaves);
        }
        if (!empty($slaves)) {
            $slave = $this->selectSlave($slaves);
            if ($slave) {
                $pdo = $this->connectSlave($slave);
                if ($pdo) {
                    $this->rlink = $pdo;
                    self::slaveHealthy($slave);
                    return $pdo;
                }
                self::slaveFailed($slave);
            }
        }
        // 无从库或从库不可用，降级到主库
        return $this->connect();
    }

    /**
     * 取写连接：已有 $wlink 直接复用，否则 connect() 建主库连接。
     * 事务、锁定读、影子模式、写后粘滞与一切非「读分流」路径都经此取连接（routeLink 的兜底分支）。
     * @return PDO|null 连接句柄；建连失败为 null（connect 已 error() 留痕）
     */
    protected function getWriteLink(): ?PDO
    {
        if ($this->wlink) return $this->wlink;
        return $this->connect();
    }

    /**
     * 从库健康状态表（self::$slaveHealth）的节点键：md5(host|port|name)。
     * @param array $cfg 从库节点配置（host/port/name 键，缺省按空串参与散列）
     * @return string 32 位十六进制键
     */
    private static function slaveKey(array $cfg): string
    {
        return md5(($cfg['host'] ?? '') . '|' . ($cfg['port'] ?? '') . '|' . ($cfg['name'] ?? ''));
    }

    /** 摘除冷却中的从库（任务 5 步骤⑤） */
    private static function healthySlaves(array $slaves): array
    {
        $out = array();
        foreach ($slaves as $s) {
            $k = self::slaveKey($s);
            $h = self::$slaveHealth[$k] ?? null;
            if ($h !== null && $h['fails'] >= self::SLAVE_MAX_FAILS && time() < $h['until']) continue;
            $out[] = $s;
        }
        return $out;
    }

    /**
     * 记一次从库连接失败（任务 5 步骤⑤，进程内静态健康表——FPM worker 跨请求有效、不跨进程）：fails 自增，
     * 达 SLAVE_MAX_FAILS(3) 连败即置 until=now+SLAVE_COOLDOWN(10s) 摘除窗口，
     * 由 healthySlaves() 在冷却期内跳过该节点。
     * @param array $cfg 失败节点配置（host/port/name）
     * @return void
     */
    private static function slaveFailed(array $cfg): void
    {
        $k = self::slaveKey($cfg);
        $h = self::$slaveHealth[$k] ?? array('fails' => 0, 'until' => 0);
        $h['fails']++;
        if ($h['fails'] >= self::SLAVE_MAX_FAILS) $h['until'] = time() + self::SLAVE_COOLDOWN;
        self::$slaveHealth[$k] = $h;
    }

    /**
     * 记一次从库连接成功：直接清除该节点的健康记录（连败计数与摘除窗口一并复位）。
     * @param array $cfg 成功节点配置（host/port/name）
     * @return void
     */
    private static function slaveHealthy(array $cfg): void
    {
        unset(self::$slaveHealth[self::slaveKey($cfg)]);
    }

    /**
     * 负载均衡选择从库
     * 支持策略: random(默认), roundrobin, weight
     */
    private function selectSlave(array $slaves): ?array
    {
        $strategy = $this->conf['slave_strategy'] ?? 'random';
        switch ($strategy) {
            case 'roundrobin': return $this->selectRoundRobin($slaves);
            case 'weight': return $this->selectWeighted($slaves);
            default: return $this->selectRandom($slaves);
        }
    }

    /** 随机分配 */
    private function selectRandom(array $slaves): ?array
    {
        return $slaves[array_rand($slaves)] ?? null;
    }

    /** 轮询分配 */
    private function selectRoundRobin(array $slaves): ?array
    {
        self::$roundRobin++;
        $idx = self::$roundRobin % count($slaves);
        return $slaves[$idx] ?? null;
    }

    /** 权重分配 */
    private function selectWeighted(array $slaves): ?array
    {
        $pool = [];
        foreach ($slaves as $s) {
            $w = max(1, (int)($s['weight'] ?? 1));
            for ($i = 0; $i < $w; $i++) $pool[] = $s;
        }
        return $pool[array_rand($pool)] ?? null;
    }

    /**
     * 建从库连接：与 connect() 同套选项（异常模式/ASSOC 取回/真·服务端预编译，可选持久连接），
     * 但**不设** FOUND_ROWS、不做 SQLite 反字符串化（只读节点不需要）；
     * user/password 缺省回落主库配置。失败仅在 DEBUG 下记 db_error 日志并回 null（供健康摘除计数）。
     * @param array $cfg 从库节点配置（host/port/name/user/password/persistent）
     * @return PDO|null 连接句柄；失败 null
     */
    private function connectSlave(array $cfg): ?PDO
    {
        $dsn = $this->buildDsn($cfg, $this->type);
        $user = $cfg['user'] ?? $this->conf['master']['user'] ?? '';
        $pass = $cfg['password'] ?? $this->conf['master']['password'] ?? '';
        try {
            $sopts = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            if (!empty($this->conf['persistent']) || !empty($cfg['persistent'])) {
                $sopts[PDO::ATTR_PERSISTENT] = true;
            }
            $pdo = new PDO($dsn, $user, $pass, $sopts);
            return $pdo;
        } catch (PDOException $e) {
            DEBUG && xn_log("Slave connect error: " . $e->getMessage(), 'db_error');
            return null;
        }
    }

    /**
     * SQL 留痕：仅 DEBUG==2 时写 db_exec 日志；并追加进进程内 $sqls 调试数组（1000 条封顶，
     * 供后台调试面板回看）。线上 DEBUG=0 不落 SQL 明细，避免把 runtime/log 撑大。仅记录，不影响执行。
     * @param string $sql 即将执行的完整 SQL
     * @return void
     */
    protected function logSql(string $sql): void
    {
        if (2 == DEBUG) xn_log($sql, 'db_exec');
        if (count($this->sqls) < 1000) {
            $this->sqls[] = $sql;
        }
    }

    /**
     * 记录数据库错误：写实例属性 errno/errstr（旧 db 接口兼容面），并恒写 db_error 日志通道
     * （P1-4：DEBUG=0 也不再零日志，通道名含 error 按 LoggerService 约定恒写）；
     * DEBUG 下额外 trigger_error 走异常/错误处理器。
     * @param mixed $errno 错误码（PDOException::getCode() 直传，可能是 SQLSTATE 字符串）
     * @param string $errstr 错误描述；空串时不写日志（仍设属性）
     * @return void
     */
    public function error($errno = 0, $errstr = '')
    {
        $this->errno = $errno;
        $this->errstr = $errstr;
        // P1-4（上线审核轮）：DEBUG=0 不再零日志——旧路径 db_errno_errstr 对失败恒写
        // db_error，新路径此前静默=同一故障两种可观测性。通道名含 error 按 LoggerService 约定恒写。
        if ('' !== $errstr && function_exists('xn_log')) {
            xn_log('errno=' . $errno . ' errstr=' . $errstr, 'db_error');
        }
        if (DEBUG) trigger_error('DB Error:' . $errstr);
    }
}
