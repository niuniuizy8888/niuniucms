<?php
// 此处的 $db 是局部变量，要注意，它返回后在定义为全局变量，可以有多个实例。

// 加载新驱动
include_once __DIR__ . '/PdoDriver.php';
include_once __DIR__ . '/ConnectionPool.php';
include_once __DIR__ . '/ProxyDriver.php';
include_once __DIR__ . '/QueryBuilder.php';

/**
 * @deprecated 旧过程式 SQL helper（db_find/db_insert/db_update/db_delete/db_count 等）：
 * 业务代码已全部迁移至 App\Repository\RepositoryFactory（DbTableRepository/QueryBuilder 预编译路径）。
 * 当前仅剩三类调用方：
 *   1. core/cache/cache_mysql.php（MultiLayerCache 的 MySQL 缓存层，基础设施自用）；
 *   2. 表结构探测（db_find_table/db_find_field/db_find_index）；
 *   3. install/index.php 的安装流程（db_update 等，安装期独有入口）。
 * 新代码禁止调用本文件其余 helper；db_table() 为 QueryBuilder 门面，仍属新路径。
 */

function db_new($dbconf)
{
    global $errno, $errstr;
    if ($dbconf) {
        $type = $dbconf['type'] ?? '';
        // 归一：子键配置优先（config.default 的 mysql/pdo_mysql 子段），无子键用顶层（database.php 的 master 结构）
        $driverConf = isset($dbconf[$type]) && is_array($dbconf[$type]) ? $dbconf[$type] : $dbconf;
        // 子键结构（config.default）无顶层 type 键，注入使 PdoDriver::$this->type 与配置一致
        empty($driverConf['type']) and $driverConf['type'] = $type;
        switch ($type) {
            case 'mysql':
                return xn_error(-1, 'mysql 驱动已移除（PHP 7.0+ 不支持 mysql_* 扩展），请将 config.php 中 db.type 改为 pdo_mysql 或 proxy');
            case 'pdo_mysql':
                include_once __DIR__ . '/PdoDriver.php';
                $db = new PdoDriver($driverConf); break;
            case 'pdo':
                include_once __DIR__ . '/PdoDriver.php';
                $db = new PdoDriver($driverConf); break;
            case 'pgsql':   // 任务 3：PostgreSQL 方言层（QueryBuilder/PdoDriver 引用与分页已方言化）
            case 'postgres':
                include_once __DIR__ . '/PdoDriver.php';
                $db = new PdoDriver($driverConf); break;
            case 'proxy':
                include_once __DIR__ . '/ProxyDriver.php';
                include_once __DIR__ . '/PdoDriver.php';
                $db = new ProxyDriver($driverConf); break;
            default: $db = null; break;
        }
        if ($db === null) {
            return xn_error(-1, 'Not suppported db type:' . $type);
        }
        if ($db && $db->errstr) {
            $errno = -1;
            $errstr = $db->errstr;
            return FALSE;
        }
        return $db;
    }
    return NULL;
}

/**
 * 安全转义字符串值（PDO::quote 字符集感知，addslashes 降级）
 * 返回带引号的字符串，如 'value'
 */
function _db_escape($val, $d = null)
{
    if ($d === null) $d = $_SERVER['db'] ?? null;
    if ($d && method_exists($d, 'quote')) {
        $quoted = $d->quote((string)$val);
        if ($quoted !== false) return $quoted;
    }
    return "'" . addslashes($val) . "'";
}

/**
 * 安全转义标识符（表名/列名），用反引号包裹
 */
function _db_escape_ident($name, $d = null)
{
    if ($d === null) $d = $_SERVER['db'] ?? null;
    // 反引号内反引号加倍
    return '`' . str_replace('`', '``', $name) . '`';
}

/**
 * 校验 SQL 标识符，仅允许字母数字下划线，首字符必须是字母或下划线。
 */
function _db_is_valid_ident($name): bool
{
    return is_string($name) && preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $name);
}

/**
 * 快捷获取 QueryBuilder（文件头禁令里点名「db_table() 为 QueryBuilder 门面，仍属新路径」——
 * 它是本文件唯一鼓励新代码使用的入口，红线 §二.3 的「唯一正确姿势」）。
 * 从 `$_SERVER['db']` 取当前连接对象（PdoDriver/ProxyDriver，容器镜像键，§二.5），
 * 用 `$db->tablepre . $table` 拼出物理表名后 new QueryBuilder；**不执行任何 SQL**，
 * 取数/写入由调用方链式 where/limit/get/insert/update/delete 决定。
 * 安全口径：标识符与比较符走 QueryBuilder 的双白名单（`OPERATORS`/`JOIN_OPERATORS`/`qi()`），
 * 值一律参数绑定（`ATTR_EMULATE_PREPARES = false`，真·服务端预编译）；
 * ⚠ `update()`/`delete()` 未挂 where 会**直接抛异常**（防全表误写），不要绕过或把异常吞掉。
 * @param string $table 逻辑表名，**不含前缀**（取证：调用点全部传裸表名，如 'vod'、'user'）
 * @return QueryBuilder 绑定当前连接的查询构造器
 */
function db_table(string $table): QueryBuilder
{
    $db = $_SERVER['db'];
    return new QueryBuilder($db, $db->tablepre . $table);
}
/**
 * 测试/建立数据库连接（原注释「测试连接」）：转发 `$d->connect()`，
 * 底层 PdoDriver::connect() 已有句柄直接复用（幂等），否则按 conf['master'] 建 PDO
 * （ERRMODE_EXCEPTION + 默认 FETCH_ASSOC + **关闭模拟预编译**，§二.3）。
 * $d 是**连接选择器**：留空取 `$_SERVER['db']` 主连接，传入对象则测该连接
 * （取证：cache_mysql 传自己持有的 PdoDriver、install/index.php 传 db_new() 新建的待验证连接）。
 * 失败信息经 db_errno_errstr() 落进全局 $errno/$errstr 并写 db_error 日志——
 * 注意 connect() 失败返回的是 NULL 而非 FALSE（该函数的修复注释正是为此），不要按 FALSE 判。
 * @param object|null $d 连接选择器（PdoDriver/ProxyDriver 实例）；NULL = 用 $_SERVER['db']
 * @return PDO|null 可用的 PDO 句柄；连不上为 NULL（安装器等调用方以此判成败）
 */
function db_connect($d = NULL)
{
    $db = $_SERVER['db'];
    $d = $d ? $d : $db;
    $r = $d->connect();
    db_errno_errstr($r, $d);
    return $r;
}
/**
 * @deprecated 旧过程式 helper（文件头禁令）：执行任意 SQL 并返回第一行。
 * $d 为空连接时返回 FALSE；底层 PdoDriver::sql_find_one：无行返回 NULL、连接/查询异常返回 FALSE（异常被吞、errno/errstr 经 db_errno_errstr 写全局并落 db_error 日志）。
 * 注意 SQL 由调用方拼接，标识符/值转义责任在调用方。
 * @param string $sql 完整 SQL
 * @param object|null $d 连接对象（NULL=用 $_SERVER['db']）
 * @return array|null|false 首行 / 无行 NULL / 失败 FALSE
 */
function db_sql_find_one($sql, $d = NULL)
{
    $db = $_SERVER['db'];
    $d = $d ? $d : $db;
    if (!$d) return FALSE;
    $arr = $d->sql_find_one($sql);
    // ⚠ 只在真失败时上报（审查修复）：sql_find_one 的 NULL 是**正常无行**，FALSE 才是
    //   连接/查询异常（此时 errno/errstr 刚被 error() 写入）。旧实现把 NULL 也传给
    //   db_errno_errstr ⇒ 表结构探测类调用（db_find_table 等）每次「不存在」都写一条
    //   db_error 噪声日志，并把全局 $errno/$errstr 覆盖成陈旧值/通用文案。
    if (false === $arr) {
        db_errno_errstr($arr, $d, $sql);
    }
    return $arr;
}
/**
 * @deprecated 旧过程式 helper（文件头禁令，现仅缓存层/本文件内 db_replace 等拼接路径在用）：
 * 执行一条完整 SQL 并返回驱动给的「影响数/自增 id」。新代码走 db_table()/RepositoryFactory。
 *
 * 返回语义（原注释逐条保留，源自 mysql_* 时代的口径，底层已换成 PDO 但语义沿用）：
 *  - INSERT / REPLACE ⇒ 返回自增 id（**非自增表插入后恒返回 0**，别拿 0 当失败）；
 *  - UPDATE / DELETE ⇒ 返回受影响行数（0 含「没有匹配行」，也含「值与原来相同」）；
 *  - 判断是否执行成功**只能用 `=== FALSE`**（原注释：判断是否执行成功: mysql_exec() === FALSE），
 *    0 是合法结果，用 falsy 判会把成功当失败。
 * 其他行为：无连接对象直接返回 FALSE；仅 DEBUG==2 时把整条 SQL 落 db_exec 日志通道；
 * 失败经 db_errno_errstr() 写全局 $errno/$errstr 并落 db_error 日志（异常被驱动层吞掉，不外抛）。
 * 底层 PdoDriver::exec() 还会按语句形态路由：以 SELECT 开头走读连接（rw_split 开启时即从库），
 * 非 SELECT 一律记为写操作（写后粘滞），所以本函数**不只是写语句入口**。
 * ⚠ $sql 是**调用方拼好的整句**，本层不做标识符白名单也不做参数绑定 ⇒ 只允许接收
 * 由常量/白名单拼出的语句（红线 §二.3）。
 * @param string $sql 完整 SQL 语句
 * @param object|null $d 连接对象/从库选择器（NULL = $_SERVER['db']；空连接返回 FALSE）
 * @return int|false 影响行数或自增 id；失败 FALSE
 */
function db_exec($sql, $d = NULL)
{
    $db = $_SERVER['db'];
    $d = $d ? $d : $db;
    if (!$d) return FALSE;
    2 == DEBUG and xn_log($sql, 'db_exec');
    $n = $d->exec($sql);
    db_errno_errstr($n, $d, $sql);
    return $n;
}
/**
 * @deprecated 旧过程式 helper（文件头禁令）：拼 `REPLACE INTO 表 (列) VALUES (值)` 交 db_exec 执行。
 * 原注释「NO SQL 封装，可以支持 MySQL / MariaDB / PG / MongoDB」指它把 SQL 细节收在 db_array_to_insert_sqladd
 * 与驱动里，调用方只给数据；现网唯一调用方是 core/cache/cache_mysql.php 的缓存写入（文件头允许的三类
 * 合法调用方之一）。新代码请用 db_table()->insert()/QueryBuilder 或 RepositoryFactory（§二.3）。
 *
 * 转义/白名单口径（不是参数绑定，别搞混）：列名过 _db_is_valid_ident，**非法列名被静默跳过**；
 * 值经 _db_escape()（PDO::quote，跟随连接的字符集）后整句拼接 ⇒ 列全非法或数组为空时
 * db_array_to_insert_sqladd 返回空串，本函数随即返回 FALSE 且不执行。
 * ⚠ REPLACE 的语义是「命中主键/唯一键则先删再插」：自增 id 会被换掉、DELETE 触发器与级联会走一遍，
 *   表必须有主键/唯一键才等价于 upsert，否则会不断插入新行。
 * ⚠ 底层 db_exec 的返回值语义照搬：成功时返回自增 id（非自增表恒 0），失败才是 FALSE ⇒ 判成败用 `=== FALSE`。
 * @param string $table 表名（不含前缀，前缀由 $d->tablepre 补）
 * @param array $arr 列名 => 值 的一维关联数组
 * @param object|null $d 连接对象/从库选择器（NULL = $_SERVER['db']；空连接返回 FALSE）
 * @return int|false 自增 id（非自增表为 0）；无列可写、空数组、无连接或执行失败时为 FALSE
 */
function db_replace($table, $arr, $d = NULL)
{
    $db = $_SERVER['db'];
    $d = $d ? $d : $db;
    if (!$d) return FALSE;
    $sqladd = db_array_to_insert_sqladd($arr);
    if (!$sqladd) return FALSE;
    return db_exec("REPLACE INTO {$d->tablepre}$table $sqladd", $d);
}
/**
 * @deprecated 旧过程式 helper（文件头禁令，现仅安装器等在用）：拼 UPDATE 语句交 db_exec 执行。
 * 列名经 _db_is_valid_ident 白名单剥非法项、值经 PDO::quote；列名 +/- 后缀=自增表达式（db_array_to_update_sqladd）。
 * ⚠ 与 QueryBuilder::update 不同：$cond 为空时 WHERE 段为空串 = **全表更新，不抛异常**——新代码必须走 db_table()/RepositoryFactory。
 * @param string $table 表名（不含前缀）
 * @param array $cond 条件数组（db_cond_to_sqladd 语法）
 * @param array $update 更新数组（全部列非法致 SET 空串时短路返回 FALSE）
 * @param object|null $d 连接对象（NULL=$_SERVER['db']；无连接返回 FALSE）
 * @return int|false 影响行数（0 含「无匹配行」）；失败/空 SET 返回 FALSE
 */
function db_update($table, $cond, $update, $d = NULL)
{
    $db = $_SERVER['db'];
    $d = $d ? $d : $db;
    if (!$d) return FALSE;
    $condadd = db_cond_to_sqladd($cond);
    $sqladd = db_array_to_update_sqladd($update);
    if (!$sqladd) return FALSE;
    return db_exec("UPDATE {$d->tablepre}$table SET $sqladd $condadd", $d);
}
/**
 * @deprecated 旧过程式 helper（文件头禁令）：拼 DELETE 语句交 db_exec 执行。
 * ⚠ $cond 为空 ⇒ 无 WHERE **全表删除，不抛异常**（对比 QueryBuilder::delete 的防误写抛异常）——调用方必须自带非空条件。
 * @param string $table 表名（不含前缀）
 * @param array $cond 条件数组（非法列名被静默跳过，可能把条件收窄成更宽的范围）
 * @param object|null $d 连接对象（NULL=$_SERVER['db']）
 * @return int|false 影响行数；无连接/执行失败 FALSE
 */
function db_delete($table, $cond, $d = NULL)
{
    $db = $_SERVER['db'];
    $d = $d ? $d : $db;
    if (!$d) return FALSE;
    $condadd = db_cond_to_sqladd($cond);
    return db_exec("DELETE FROM {$d->tablepre}$table $condadd", $d);
}
/*
 * 批量创建数据格式
 * $arr = array(
    array('uid' => '1', 'create_date' => $time),
    array('uid' => '2', 'create_date' => $time),
    array('uid' => '3', 'create_date' => $time),
);*/
// 大数据量一次更新拼接sql字串 建议一次5000-10000再大需要调整php配置
/*
 * 格式
更新条件限主键，不支持多条件
$cond = array('tid' => array(1,2,3,4));
$update = array('更新条件tid值' => array('更新字段，可+或-' => '更新数据'))
$update = array(
    '1' => array('views' => 10),
    '2' => array('views' => 100),
    '3' => array('views+' => 200, 'mods' => 20),
    '4' => array('views+' => 1000, 'mods+' => 50)
);
*/
function db_truncate($table, $d = NULL)
{
    $db = $_SERVER['db'];
    $d = $d ? $d : $db;
    if (!$d) return FALSE;
    return $d->truncate($d->tablepre . $table);
}
/**
 * @deprecated 旧过程式 helper（文件头禁令，现仅缓存层/安装器类调用方在用）：按条件取单行（LIMIT 1，SELECT $col ?: *）。
 * $col 交驱动 selectColsSql()：纯列名/`tbl.col` 加引号、表达式（`COUNT(*) AS n`）原样直出，
 * 非法元素跳过、全非法回退 '*'。cond/orderby 语法见 db_cond_to_sqladd/db_orderby_to_sqladd（非参数绑定路径）。
 * @param string $table 表名（不含前缀）
 * @param array $cond 条件数组 @param array $orderby 排序 @param array $col 列清单
 * @param object|null $d 连接对象（NULL=$_SERVER['db']；无连接返回 FALSE）
 * @return array|null|false 行数组 / 无行 NULL（驱动语义）/ 失败 FALSE
 */
function db_find_one($table, $cond = array(), $orderby = array(), $col = array(), $d = NULL)
{
    $db = $_SERVER['db'];
    // 高效写法，定参有利于编译器优化
    $d = $d ? $d : $db;
    if (!$d) return FALSE;
    return $d->find_one($table, $cond, $orderby, $col);
}
/**
 * 保存 $db 错误到全局（原注释口径）：把驱动对象上的 errno/errstr 写进全局 $errno/$errstr，
 * 并把 `SQL: … / errno / errstr` 落 db_error 日志通道（该通道名含 error ⇒ 线上 DEBUG=0 也恒写，见 xn_log）。
 * 触发条件走修复后的严格判定：原实现只认 `FALSE === $r`，**漏掉了 connect() 失败返回的 null**，
 * 导致 errno/errstr 永不设置、安装向导显示空白错误；现同时接受 false 与 null。
 * 刻意保留严格比较：UPDATE 影响 0 行是合法结果（0），不能被当成失败——调用方传进来的 $r
 * 就是 db_exec/db_sql_find_one 那一族的返回值本身。
 * 只读地记录，不改变 $r 也不中断流程；$errstr 一律先过 db_errstr_safe() 做线上脱敏（防库名/账号/路径外泄）。
 * @param mixed $r 被检查的驱动返回值（FALSE 或 NULL 视为失败）
 * @param object|null $d 连接对象（读它的 errno/errstr 属性；传 NULL 会在失败分支上炸出属性访问错误，调用方须给对象）
 * @param string $sql 相关 SQL 原文，仅用于日志定位（可省略）
 * @return void 无返回值（结果写全局 $errno/$errstr 与 db_error 日志）
 */
function db_errno_errstr($r, $d = NULL, $sql = '')
{
    global $errno, $errstr;
    // 修复：原 FALSE === $r 严格比较漏掉 connect() 失败返回的 null
    // （PdoDriver::connect 失败 return null），导致 errno/errstr 永不设置、安装等
    // 调用方显示空错误。严格匹配两种失败值，避免把 UPDATE 影响 0 行的合法 0 误判。
    if (false === $r || null === $r) { //  && $d->errno != 0
        $errno = $d->errno;
        // ⚠ 日志记**原文**：原先 $s 里拼的是脱敏后的 $errstr，一旦命中定向改写，
        //   db_error 日志就只剩中文提示、丢掉驱动原文，故障定位反而变难（脱敏是给"对外面"的，
        //   日志属内部面，红线 §二.10 的口径正是「详情进日志、提示页不给细节」）。
        xn_log('SQL:' . $sql . "\r\nerrno: " . $errno . ", errstr: " . $d->errstr, 'db_error');
        $errstr = db_errstr_safe($errno, $d->errstr);
    }
}
/**
 * 安全的错误信息：把驱动原始 errstr 换成**给运维看得懂、又不泄露连接细节**的中文提示。
 *  - DEBUG 非 0：原样返回真实 errstr（本地排查用）；
 *  - 线上（DEBUG=0）：先归一错误码再查定向表——1049 数据库名不存在、2003/2002/2005 连不上服务器、
 *    1024 连接数据库失败、1045 账号密码错误、1146 表不存在；
 *  - **未命中定向表返回通用文案**（不再原样透出驱动原文）。
 * ⚠ 归一这一步是本轮补的：查询期 PdoDriver::$errno 收到的是 PDOException::getCode()，
 *   那是 **SQLSTATE 字符串**（如 '42S02'/'HY000'），而 MySQL 真正的驱动码（1146/1045…）只出现在
 *   errstr 文本里。原先用 `1049 == $errno` 直接比整数，在 PHP 8 里 "整数==非数字字符串" 会转成
 *   字符串比较 ⇒ 查询期**必然走 default 分支**，把含库名/表名/SQL 片段的驱动原文当成对外提示返回，
 *   定向改写等于没生效。现由 db_err_code() 归一，映射才真正命中。
 * 唯一调用方是 db_errno_errstr()（写全局 $errstr 前过一道；日志另行记录原文）。
 * @param int|string $errno 驱动错误码（取证：PdoDriver::$errno，可能是 PDO 的字符串 SQLSTATE）
 * @param string $errstr 驱动原始错误文本
 * @return string 面向运维的错误提示（未命中定向表时为通用文案）
 */
function db_errstr_safe($errno, $errstr)
{
    if (DEBUG) return $errstr;
    switch (db_err_code($errno, $errstr)) {
        case 1049: return '数据库名不存在，请手工创建';
        case 1045: return '数据库账户密码错误';
        case 1146: return '数据表不存在，请检查是否已完整导入安装 SQL';
        case 1054: return '数据表列不完整，请核对安装 SQL 或补做升级迁移';
        case 2002:
        case 2003:
        case 2005: return '连接数据库服务器失败，请检查IP是否正确，或者防火墙设置';
        case 1024: return '连接数据库失败';
    }
    return '数据库操作失败，详情见 runtime/log 的 db_error 通道';
}

/**
 * 错误码归一：从 SQLSTATE 字符串 / errstr 文本里提取 MySQL 驱动码，供 db_errstr_safe() 定向匹配。
 * 取证：PDOException 的 getCode() 在查询期给 SQLSTATE（'42S02'、'28000'、'3D000'…），
 * 驱动码只出现在 message 文本中（形如 `SQLSTATE[42S02]: Base table or view not found: 1146 Table ...`）；
 * 连接期则多为 2002/2003/1045 这类整数（或写在文本里的 [2002]）。
 * @param int|string $errno 原始错误码
 * @param string $errstr 原始错误文本
 * @return int 归一后的驱动码；解析不出来返回 0（调用方走通用文案）
 */
function db_err_code($errno, $errstr)
{
    $errstr = (string)$errstr;
    // ① 整数 errno 直接用
    if (is_int($errno) && $errno > 0) return $errno;
    $code = is_string($errno) ? trim($errno) : '';
    // ② PDO 的双括号形态 `SQLSTATE[28000] [1045] Access denied…`：**第二个**括号才是驱动码。
    //    必须先匹配这个整体，否则会把 SQLSTATE 本身（28000/HY000…）误当驱动码。
    if (preg_match('/SQLSTATE\[[^\]]+\]\s*\[(\d{3,5})\]/i', $errstr, $m)) return (int)$m[1];
    // ③ 只有单个括号时才是驱动码（如 `PDOConnection: [2002] …` 之类的非标准包装）。
    //   ⚠ 修复（审查）：pgsql 的 `SQLSTATE[28000]: Invalid authorization…` 单括号形态也会被
    //   该正则截胡（把 SQLSTATE 类码当驱动码返回 ⇒ 28000 永不落到语义映射、认证失败拿不到
    //   「账户密码错误」定向文案）。用负向后顾排除紧跟 `SQLSTATE` 的括号，让该形态落 ④。
    if (preg_match('/(?<!SQLSTATE)\[(\d{3,5})\]/', $errstr, $m)) return (int)$m[1];
    // ④ MySQL 文本里的 `... not found: 1146 ...`（表/列不存在常见形态）
    if (preg_match('/not found:\s*(\d{4})\b/i', $errstr, $m)) return (int)$m[1];
    // ⑤ 数字字符串 errno：只接受 3~4 位（真实驱动码形态）。⚠ 修复（审查）：5 位纯数字串
    //    几乎都是 SQLSTATE 类码（如 28000/3D000 的纯数字变体），旧实现直接返回会让语义映射
    //    永远轮不到——pgsql 认证失败（errno='28000'）拿不到 1045 定向文案。
    if ($code !== '' && ctype_digit($code) && strlen($code) <= 4) return (int)$code;
    // ④ 只剩 SQLSTATE：按语义映射到既有定向表
    switch (strtoupper($code)) {
        case '3D000': return 1049;   // INVALID CATALOG NAME（库名不存在）
        case '28000': return 1045;   // INVALID AUTHORIZATION SPECIFICATION（账号/密码）
        case '42S02': return 1146;   // BASE TABLE OR VIEW NOT FOUND
        case '08001':                 // SQLCLIENT UNABLE TO ESTABLISH CONNECTION
        case '08004':
        case 'HY000':
            // HY000 是通用码，只能靠文本关键词分辨
            if (stripos($errstr, 'Unknown database') !== false) return 1049;
            if (stripos($errstr, 'Access denied') !== false) return 1045;
            if (stripos($errstr, 'php_network_getaddresses') !== false
                || stripos($errstr, 'Connection refused') !== false
                || stripos($errstr, 'No such file or directory') !== false) return 2002;
            return 0;
    }
    return 0;
}
//----------------------------------->  表结构和索引相关 end
/*
$cond = array('id'=>123, 'groupid'=>array('>'=>100, 'LIKE'=>'\'jack'));
$s = db_cond_to_sqladd($cond);
echo $s;
WHERE id=123 AND groupid>100 AND groupid LIKE '%\'jack%' 
// 格式：
array('id'=>123, 'groupid'=>123)
array('id'=>array(1,2,3,4,5))
array('id'=>array('>' => 100, '<' => 200))
array('username'=>array('LIKE' => 'jack'))
*/
function db_cond_to_sqladd($cond, $d = null)
{
    if ($d === null) $d = $_SERVER['db'] ?? null;
    $wherearr = array();
    if (!empty($cond)) {
        foreach ($cond as $k => $v) {
            // 校验列名格式：只允许字母数字下划线
            if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $k)) continue;
            if (!is_array($v)) {
                if ($v === null) {
                    $wherearr[] = "`$k` IS NULL";
                } else {
                    $v = (is_int($v) || is_float($v)) ? $v : _db_escape($v, $d);
                    $wherearr[] = "`$k`=$v";
                }
            } elseif (_db_is_list_cond($v)) {
                // 项目审查修复：判据由 `isset($v[0])` 改为「全整数键」（对齐 QueryBuilder::isListCond）。
                // 原判据对空数组与「非 0 起始键」的列表恒假 ⇒ 落到操作符分支零次迭代：
                // 空数组条件被静默丢弃（find(['x'=>[]]) 退化成全表查询），[2=>5,3=>7] 被当成
                // `x=5 AND x=7`（恒假）。原 1=0 分支因 isset 为真时 empty 恒假而是死代码。
                if (empty($v)) { $wherearr[] = '1=0'; continue; }
                $in = array();
                foreach ($v as $v1) {
                    $v1 = isset($v1) ? $v1 : '';
                    $in[] = (is_int($v1) || is_float($v1)) ? $v1 : _db_escape($v1, $d);
                }
                $wherearr[] = "`$k` IN (" . implode(',', $in) . ')';
            } else {
                foreach ($v as $k1 => $v1) {
                    $v1 = isset($v1) ? $v1 : '';
                    // 校验操作符格式
                    $k1 = in_array(trim($k1), array('<', '>', '<=', '>=', '!=', '<>', 'LIKE', 'NOT LIKE'), true) ? " $k1 " : '=';
                    if (str_contains($k1, 'LIKE')) {
                        // 修复（VULN-L01）：先转义转义符本身，再转义通配符（原实现只做后者）
                        $v1 = function_exists('like_escape') ? like_escape($v1) : str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $v1);
                        $v1 = "%$v1%";
                    }
                    $v1 = (is_int($v1) || is_float($v1)) ? $v1 : _db_escape($v1, $d);
                    $wherearr[] = "`$k`$k1$v1";
                }
            }
        }
    }
    // 审查修复（同类）：原来恒 `substr($s, 0, -4)` —— 当**没有任何条件被追加**时（$cond 全为非法键、
    // 或值全为空列表走 continue），会把 ' WHERE ' 砍成 `' WH'` 拼进 SQL ⇒ 1064，且被 prepare_execute
    // 吞成空结果集（症状＝列表恒空、导入"没反应"）。现改为「有子句才加 WHERE，用 implode 收尾」。
    return $wherearr ? (' WHERE ' . implode(' AND ', $wherearr)) : '';
}

/**
 * 条件值是否为「IN 值列表」形态：**全部键为整数**即成立（含空数组）。
 * 与 QueryBuilder::isListCond() 同口径——两处判据必须一致，否则新旧两条通路的条件语义会分叉。
 * PHP 8.0 兼容：不用 8.1 的 array_is_list。
 * @param array $v 条件值
 * @return bool
 */
function _db_is_list_cond($v)
{
    foreach ($v as $k => $_) {
        if (!is_int($k)) return false;
    }
    return true;
}
/**
 * @deprecated 旧 SQL 片段拼装器（DbTableRepository 保持同语义复用）。orderby 转 ` ORDER BY ...` 子句：
 * 键为列名（_db_is_valid_ident 白名单，非法列**静默跳过**），值 1（松散 ==）= ASC，其余一律 DESC。
 * @param array $orderby array(列名 => 1|-1)
 * @return string 以空格开头的子句；空入参或全部列非法返回 ''（调用方拼接安全）
 */
function db_orderby_to_sqladd($orderby)
{
    $s = '';
    if (!empty($orderby)) {
        $s .= ' ORDER BY ';
        $comma = '';
        foreach ($orderby as $k => $v) {
            if (!_db_is_valid_ident($k)) continue;
            $s .= $comma . "`$k` " . (1 == $v ? ' ASC ' : ' DESC ');
            $comma = ',';
        }
        if ($comma === '') return '';
    }
    return $s;
}
/*
	$arr = array(
		'name'=>'abc',
		'stocks+'=>1,
		'date'=>12345678900,
	)
	db_array_to_update_sqladd($arr);
*/
function db_array_to_update_sqladd($arr, $d = null)
{
    if ($d === null) $d = $_SERVER['db'] ?? null;
    $s = '';
    foreach ($arr as $k => $v) {
        $v = isset($v) ? $v : '';
        $op = substr($k, -1);
        if ('+' == $op || '-' == $op) {
            $k = substr($k, 0, -1);
            if (!_db_is_valid_ident($k)) continue;
            $v = (is_int($v) || is_float($v)) ? $v : _db_escape($v, $d);
            $ident = _db_escape_ident($k, $d);
            $s .= $ident . '=' . $ident . $op . $v . ',';
        } else {
            if (!_db_is_valid_ident($k)) continue;
            $v = (is_int($v) || is_float($v)) ? $v : _db_escape($v, $d);
            $s .= _db_escape_ident($k, $d) . '=' . $v . ',';
        }
    }
    return $s === '' ? '' : substr($s, 0, -1);
}
/*
	$arr = array(
		'name'=>'abc',
		'date'=>12345678900,
	)
	db_array_to_insert_sqladd($arr);
*/
function db_array_to_insert_sqladd($arr, $d = null)
{
    if ($d === null) $d = $_SERVER['db'] ?? null;
    $keys = array();
    $values = array();
    foreach ($arr as $k => $v) {
        if (!_db_is_valid_ident($k)) continue;
        $v = isset($v) ? $v : '';
        $keys[] = _db_escape_ident($k, $d);
        $v = (is_int($v) || is_float($v)) ? $v : _db_escape($v, $d);
        $values[] = $v;
    }
    if (empty($keys)) return '';
    $keystr = implode(',', $keys);
    $valstr = implode(',', $values);
    $sqladd = "($keystr) VALUES ($valstr)";
    return $sqladd;
}
/**
 * 表存在性探测（文件头 @deprecated 名单里保留的三类合法调用方之一：结构探测）。
 * SHOW TABLES LIKE 后逐列值全等复核（LIKE 的 `_` 是通配符，防近似名误判）；
 * 请求内 static + 跨请求 cache（键 db_tbl_exists_md5，TTL 3600s）**只缓存 true**（新表不被旧 false 误挡），缓存不可用自动退回实时探测。
 * @param string $table 含前缀的完整表名（空返回 FALSE）
 * @return bool 表是否存在
 */
function db_find_table($table)
{
    static $memo = array();
    if (!$table) return FALSE;
    $key = (string)$table;
    if (array_key_exists($key, $memo)) return $memo[$key];
    // R7-P05①：跨请求缓存「表存在」探测（表结构极少变化；只缓存 true，规避新表被误判；
    // 缓存不可用时自动退回实时探测）。消除搜索热词等写路径每次 `SHOW TABLES LIKE` 的元数据查询。
    $ck = 'db_tbl_exists_' . md5($key);
    if (function_exists('cache_get')) {
        $hit = cache_get($ck);
        if (1 == $hit || '1' === $hit) { $memo[$key] = TRUE; return TRUE; }
    }
    $exists = FALSE;
    $table_safe = _db_escape($table);
    $arr = db_sql_find_one("SHOW TABLES LIKE $table_safe");
    if (!empty($arr)) {
        foreach ($arr as $v) {
            if ($v == $table) { $exists = TRUE; break; }
        }
    }
    $memo[$key] = $exists;
    if ($exists && function_exists('cache_set')) cache_set($ck, 1, 3600);
    return $exists;
}
/**
 * 检查字段是否存在（原注释「检查字段 $table 表 $field 字段」）：属文件头 @deprecated 禁令里
 * **保留的合法调用方之一「表结构探测」**（升级/建列前的存在性判定），不是业务取数入口。
 * 实现是对 `DESCRIBE 表名 + 反引号包裹的字段名` 发一条元数据查询：
 *  - $table 或 $field 为空（falsy）直接 FALSE，不发查询；
 *  - **字段名走标识符白名单**（必须是字母/下划线开头 + 字母数字下划线），不合规立即 FALSE——
 *    这行是防 `$field` 用反引号逃逸成注入的闸（原代码内注释已标注），别放宽；
 *  - 表名走 _db_escape_ident()；
 *  - DESCRIBE 结果非空**且 Field 列与传入字段名相等**才算存在（防大小写/近似名误判）。
 * 无结果集缓存（区别于同文件的 db_find_table()，那个有 static + 跨请求缓存且只缓存 true），
 * 每次调用一条元数据查询 ⇒ 不要放进循环（性能红线 §三.2）。
 * 注：MySQL 的 DESCRIBE 对不存在字段返回空集（不是报错），所以失败路径就是 FALSE 而不是异常。
 * @param string $table 表名（取证：调用点传**含前缀**的完整表名，与 db_find_table 同口径；非法标识符由转义层处理）
 * @param string $field 字段名（必须过标识符白名单）
 * @return bool 字段存在 = TRUE；空值/非法名/不存在/查询失败 = FALSE
 */
function db_find_field($table, $field)
{
    if ($table && $field) {
        // 字段名白名单（防 `$field` 反引号逃逸注入）
        if (!is_string($field) || !preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $field)) return FALSE;
        $table_ident = _db_escape_ident($table);
        $r = db_sql_find_one("DESCRIBE $table_ident `$field`");
        if (!empty($r) && $r['Field'] == $field) return TRUE;
    }
    return FALSE;
}
/**
 * 取字段的类型定义原文（表结构探测）；表/字段名非法或对象不存在返回 FALSE
 *
 * 背景：会话落库需要知道 nncms_session.url / useragent 的**真实列宽**。
 * 存量站点这两列是 char(32)/char(128)，而后台 URI 普遍 >32 字符、新版浏览器 UA 普遍 >128 字符，
 * MySQL 5.7+/8 默认 sql_mode 含 STRICT_TRANS_TABLES ⇒ REPLACE 直接报 1406 Data too long，
 * 驱动层又把 PDOException 吞成 false ⇒ 会话主行永远写不进去（bull_token 场景指纹环全丢，
 * 后台每一次写操作都被判「非法操作 / 令牌来自上一个会话」，刷新也不自愈）。见 SessionService::putRow()。
 */
function db_column_type($table, $field)
{
    if (!is_string($table) || '' === $table || !preg_match('#^[A-Za-z0-9_]+$#', $table)) return FALSE;
    if (!is_string($field) || !preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $field)) return FALSE;
    $r = db_sql_find_one("DESCRIBE " . _db_escape_ident($table) . " `$field`");
    return (!empty($r) && !empty($r['Type'])) ? (string)$r['Type'] : FALSE;
}

// 检查索引 $table表 $index索引
