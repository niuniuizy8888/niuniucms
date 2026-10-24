<?php
namespace App\Service\Account;

/**
 * 会话域服务：承接 model/session.php 的 sess_start/gc、在线统计与附件清理
 * 说明：SessionHandlerInterface 实现全部在 Handler/ 目录（OO 形态）：DualSessionHandler（现役：
 *   登录→MySQL、匿名→Redis）与 BullSessionHandler（Redis 不可用时的纯 MySQL 回退）。
 *   历史 Redis-only 形态 RedisBullSessionHandler 全仓 0 实例化，已在「死代码清理轮」删除
 *   （原文件在 core/app/Service/Account/Handler/，删除记录见 oldskills/security/CHANGESET-审查修复轮.md（与 web 根同级；死代码清理轮））。
 *   注：model/session.php 是**纯函数兼容壳**，不含任何类。
 */
class SessionService
{

    /**
     * SessionHandlerInterface::open 的空实现占位（回调链：sess_open 兼容壳 / 旧 Handler）。
     * 本项目会话不走 PHP 的 save_path 文件目录——数据经 write()/putRow() 落 nncms_session 表（或 DualSessionHandler 的 Redis），
     * 打开阶段没有任何资源要申请。
     * @param string $save_path    session.save_path，忽略
     * @param string $session_name 会话名，忽略
     * @return bool 恒 true——不要改成 false：处理器会把它当「open 失败」中止会话
     */
    public static function open($save_path, $session_name)
    {
        return true;
    }

    /**
     * SessionHandlerInterface::close 的空实现占位：落库已在 write() 回调完成，close 无资源要释放。
     * @return bool 恒 true
     */
    public static function close()
    {
        return true;
    }

    /**
     * 会话读取回调（sess_read 兼容壳；Redis 双存储形态下匿名流量走 DualSessionHandler::read，登录态回落到这里）。
     * 行为要点：$sid 为空或主行读不到时**就地 newSession() 新建会话并返回空串**（表现为「每次请求都像新会话」，
     * 不是报错）；self::$loadedFrom 同步记 'none'/'mysql'，供 bull_session_is_empty 区分「会话没存下来」与「令牌来自另一会话」。
     * 主行 bigdata=1 时真实数据从 session_data 表展开（老库该列可能为 NULL，强制 (string)——Handler::read 接口要求 string）。
     * @param string $sid 会话 ID（空则回退 session_id()）
     * @return string PHP 会话格式原文；无内容为空串
     */
    public static function read($sid)
    {
        $longip = _SERVER('longip');         $time = _SERVER('time');         global $g_session;
        if (empty($sid)) {
            $sid = session_id();
            self::newSession($sid);
            return '';
        }
        try {
            $arr = \App\Repository\RepositoryFactory::table('session', 'sid')->read($sid, 'sid');
        } catch (\Throwable $e) {
            $arr = null;
        }
        if (empty($arr)) {
            self::$loadedFrom = 'none';       // 服务端没有这份会话（被回收/从未落库）
            self::newSession($sid);
            return '';
        }
        self::$loadedFrom = 'mysql';          // 主行命中（真实数据可能在 session_data）
        if (1 == $arr['bigdata']) {
            $arr2 = \App\Repository\RepositoryFactory::table('session_data', 'sid')->read($sid, 'sid');
            // 老库升级等场景 session_data.data 可能为 NULL，强制转 string（Handler::read 要求 string 返回）
            $arr['data'] = (string)($arr2['data'] ?? '');
        }
        $g_session = $arr;
        return $arr ? (string)$arr['data'] : '';
    }

    /**
     * 新会话落地（read() 判无会话时调用，亦经 sess_new 兼容壳转发）。
     * cookie_test 探针流程：请求不带 cookie_test ⇒ 只下发 xn_encrypt(md5(UA+longip)) 探针 Cookie 并直接返回（**不建会话行**）；
     * 带探针 ⇒ hash_equals 恒时校验（S-2），不符置 $g_session_invalid=TRUE（后续判「非法操作」的依据之一），令探针 Cookie 过期；
     * 随后组装初始行（uid=0、data 空、url/useragent 原样）交 putRow() 落库——按真实列宽截断与 1406 留痕都在 putRow 内，不在本函数。
     * Cookie 属性口径：httponly + SameSite=Lax + HTTPS 自动 secure（§二.9 基线，勿裸 setcookie）。
     * @param string $sid 会话 ID
     * @return void（首访探针分支提前返回，不建行）
     */
    public static function newSession($sid)
    {
        $time = _SERVER('time');         $longip = _SERVER('longip');         $conf = _SERVER('conf');         global $g_session, $g_session_invalid;
        $agent = _SERVER('HTTP_USER_AGENT');
        // 干掉同 ip 的 sid，仅仅在遭受攻击的时候
        //session_delete(array('ip'=>$longip));
        $cookie_test = _COOKIE('cookie_test');
        $https = (!empty(_SERVER('HTTPS')) && strtolower(_SERVER('HTTPS')) != 'off') || (NULL !== _SERVER('HTTP_X_FORWARDED_PROTO') && _SERVER('HTTP_X_FORWARDED_PROTO') === 'https');
        $cookie_opt = array(
            'expires' => $time + 86400,
            'path' => $conf['cookie_path'],
            'secure' => $https,
            'httponly' => TRUE,
            'samesite' => 'Lax',
        );
        if (!empty($conf['cookie_domain'])) $cookie_opt['domain'] = $conf['cookie_domain'];
        if ($cookie_test) {
            $cookie_test_decode = xn_decrypt($cookie_test, $conf['auth_key']);
            $g_session_invalid = !hash_equals(md5($agent . $longip), (string)$cookie_test_decode); // S-2：恒时比较
            $cookie_opt['expires'] = $time - 86400;
            setcookie('cookie_test', '', $cookie_opt);
        } else {
            $cookie_test = xn_encrypt(md5($agent . $longip), $conf['auth_key']);
            setcookie('cookie_test', $cookie_test, $cookie_opt);
            $g_session_invalid = FALSE;
            return;
        }
        // 可能会暴涨
        $url = _SERVER('REQUEST_URI');
        $arr = array(
            'sid' => $sid,
            'uid' => 0,
            'cid' => 0,
            'url' => $url,
            'last_date' => $time,
            'data' => '',
            'ip' => $longip,
            'useragent' => $agent,
            'bigdata' => 0,
        );
        $g_session = $arr;
        self::putRow($sid, $arr);
    }

    /**
     * 从存储重载会话进 $_SESSION：read(global $sid)（含 session_data 展开）后 session_decode 注入。
     * 会话未激活（CLI/冒烟环境没走 session_start）时跳过 decode，避免 PHP 告警。
     * @return void
     */
    public static function restart()
    {
        global $sid;
        $data = self::read($sid);
        // 会话未激活时跳过（CLI/冒烟环境无 session_start，session_decode 会告警；）
        if (session_status() === PHP_SESSION_ACTIVE) session_decode($data); // 直接存入了 $_SESSION
    }

    /**
     * 把当前 $_SESSION 落库：等价 write(global $sid, TRUE)——走 write() 全链路
     * （写前重读 + 按键合并防并发覆盖、bigdata>255 字符路由到 session_data、P-9 延迟更新短路、匿名无数据不建行）。
     * @return bool 恒 TRUE（write() 全路径只回 TRUE；写失败以 session_write_error 日志留痕表达，不能据此判定落库成功）
     */
    public static function save()
    {
        global $sid;
        self::write($sid, TRUE);
    }

    /**
     * 会话写入主实现（SessionHandlerInterface::write 的落库侧；模型壳 sess_write 与 save() 都转到这里）。
     *
     * 模拟加锁语义（原注释口径）：「如果发现写入的时候数据已经发生改变，则读取后合并数据再写入
     * ——合并总比删除安全一点」。修复记录必须保留：
     *  - 原实现只跟**请求开始时的快照 $g_session** 做 diff 后整行覆盖 ⇒ 无锁的读-改-写竞态；
     *  - 同一 sid 的并发请求（现场典型：后台采集自动翻页链 + 用户列表/创建表单页并行）会互相覆盖
     *    对端刚写入的 **safe_token 场景指纹键**（如 admin_create_user），导致表单提交恒「非法操作」；
     *  - 现在的做法：**写入前重新读当前行**（含 bigdata 行转读 session_data 表），按键合并——
     *    别的请求已写入的键保留，本请求改过/新增的键以本请求为准；合并结果与原字节相同就不重写
     *    （避免无谓写与序列化格式漂移）。
     * 其余三档关键行为（改这里前都要看到）：
     *  1) **$data 只当「真值开关」**：为真时实际内容取 session_encode()（当前 $_SESSION），
     *     会话未激活则退化成空串（否则 encode 会告警）；为假表示「只更新主行元数据」。
     *  2) **P-9 延迟更新短路**（conf['session_delay_update']，默认 60s）：窗口内剥掉
     *     cid/url/last_date/useragent 这些「不重要」字段不重写（在线列表会有秒级滞后，属预期），
     *     安全字段（safe_token 所在 data、uid）不受影响。
     *  3) **QPS 防护**：匿名 + 库里无行 + 本次无会话数据（纯浏览/压测/无 cookie 扫描器）⇒ 直接返回不建行。
     * 超长分流：data 长度大于 255 走 bigdata 形态（session.data 置空 + 真数据进 session_data 表），
     * 回落短行时会删掉 session_data 行；两张表都经 putRow()/putData() —— 按**真实列宽截断 + 失败重试 +
     * session_write_error 留痕**，杜绝「REPLACE 报 1406 → 驱动吞成 false → 会话静默丢失」的老路径。
     * ⚠ 写入前会 unset($_SESSION['uid'] / ['cid'])（两列单独建列，避免被 session_encode 重复塞进 data），
     *   之后读 $_SESSION 不要再假设这两个键还在原处。
     * ⚠ 顺带 chdir(APP_PATH)（历史实现依赖，勿删）；时间/IP/配置统一走容器门面（§二.5）。
     * @param string $sid 会话 ID（session_id）
     * @param mixed $data 真值 = 按当前会话 session_encode() 写入；空值 = 只更新主行元数据
     * @return bool 恒 TRUE —— **写失败以 session_write_error 日志留痕表达，不上抛也不回 FALSE**，
     *              调用方不能据返回值判定「真的落库了」
     */
    public static function write($sid, $data)
    {
        $time = _SERVER('time');         $longip = _SERVER('longip');         $conf = _SERVER('conf');         global $g_session, $g_session_invalid;
        $uid = intval(_SESSION('uid'));
        $cid = intval(_SESSION('cid'));
        unset($_SESSION['uid'], $_SESSION['cid']);
        // 会话未激活时 encode 会告警并返回 false；置空串保持 data 字段语义
        $data and $data = (session_status() === PHP_SESSION_ACTIVE) ? session_encode() : '';
        function_exists('chdir') and chdir(APP_PATH);
        $url = _SERVER('REQUEST_URI');
        $agent = _SERVER('HTTP_USER_AGENT');

        // 写入前重新读取当前行（含 bigdata 展开），按键合并，防并发写覆盖丢失
        $cur = null;
        $curData = '';
        try {
            $cur = \App\Repository\RepositoryFactory::table('session', 'sid')->read($sid, 'sid');
        } catch (\Throwable $e) {
            $cur = null;
        }
        if (is_array($cur)) {
            $curData = (string)($cur['data'] ?? '');
            if (1 == intval($cur['bigdata'] ?? 0)) {
                // bigdata 行：session.data 列留空，真实数据在 session_data 表
                $curData = '';
                try {
                    $curRow2 = \App\Repository\RepositoryFactory::table('session_data', 'sid')->read($sid, 'sid');
                    $curData = (string)($curRow2['data'] ?? '');
                } catch (\Throwable $e) {
                    $curData = '';
                }
            }
        }
        if ($data !== '') {
            $parsedCur = self::parseSessionData($curData);
            $merged = $parsedCur;
            foreach (self::parseSessionData($data) as $k => $v) {
                $merged[$k] = $v; // 本请求为准
            }
            // 无实际变化时保留原字节（避免无谓重写/格式化漂移）
            $data = $merged === $parsedCur ? $curData : self::buildSessionData($merged);
        }

        $arr = array(
            'uid' => $uid,
            'cid' => $cid,
            'url' => $url,
            'last_date' => $time,
            'data' => $data,
            'ip' => $longip,
            'useragent' => $agent,
            'bigdata' => 0,
        );
        // 开启 session 延迟更新，减轻压力，会导致不重要的数据(useragent,url)显示有些延迟，单位为秒。
        $session_delay_update_on = !empty($conf['session_delay_update']) && $time - (int)($g_session['last_date'] ?? 0) < $conf['session_delay_update'];
        if ($session_delay_update_on) unset($arr['cid'], $arr['url'], $arr['last_date'], $arr['useragent']);
        // diff 基准：写入前重新读取的当前行（无行时退回请求开始快照）
        $base = is_array($cur) ? $cur : (is_array($g_session) ? $g_session : array());
        // QPS 防护：匿名 + 行不存在 + 本请求无会话数据（纯浏览/压测/扫描器无 cookie）→ 不建行。
        // 真实浏览器第二请求起有 cookie_test/既有行，不受影响；在线统计中此类瞬态访客本无留存价值。
        if ($uid === 0 && !is_array($cur) && '' === $data && empty($_SESSION)) {
            return TRUE;
        }
        $baseBigdata = intval($base['bigdata'] ?? 0);
        // 判断数据是否超长
        $len = strlen($data);
        // 修复：两张表的写入统一走 putRow()/putData()——按真实列宽截断 + 失败重试 + 失败留痕，
        // 不再允许「 REPLACE 报 1406 → 驱动层吞成 false → 会话静默丢失」这条路径存在。
        if ($len > 255 && 0 == $baseBigdata) self::putData($sid, '', $time);
        if ($len <= 255) {
            $update = array_diff_value($arr, $base);
            self::putRow($sid, $arr, $update);
            if (!empty($base) && 1 == $baseBigdata) \App\Repository\RepositoryFactory::table('session_data', 'sid')->delete(array('sid' => $sid));
        } else {
            $arr['data'] = '';
            $arr['bigdata'] = 1;
            $update = array_diff_value($arr, $base);
            self::putRow($sid, $arr, $update);
            $arr2 = array('data' => $data, 'last_date' => $time);
            if ($session_delay_update_on) unset($arr2['last_date']);
            $update2 = array_diff_value($arr2, $base);
            self::putData($sid, $data, $time, $update2);
        }
        return TRUE;
    }

    /**
     * 解析 PHP 会话格式字符串为键值数组（key|serialize(value) 链）
     * 不依赖活动会话（session_decode 在无会话时不可用），CLI/测试亦可用。
     * @return array
     */
    public static function parseSessionData(string $str): array
    {
        $out = array();
        $len = strlen($str);
        $offset = 0;
        while ($offset < $len) {
            $bar = strpos($str, '|', $offset);
            if ($bar === false) break;
            $key = substr($str, $offset, $bar - $offset);
            if ($key === '') break;
            $rest = substr($str, $bar + 1);
            $val = @unserialize($rest, array('allowed_classes' => false));
            if ($val === false && 0 !== strncmp($rest, 'b:0;', 4)) break; // 损坏数据停止解析，保留已解析部分（b:0; 为合法 false 值）
            $out[$key] = $val;
            $offset = $bar + 1 + strlen(@serialize($val));
        }
        return $out;
    }

    /**
     * 键值数组构建 PHP 会话格式字符串（与 PHP session_encode 输出格式一致）
     */
    public static function buildSessionData(array $arr): string
    {
        $s = '';
        foreach ($arr as $k => $v) {
            $s .= $k . '|' . serialize($v);
        }
        return $s;
    }

    /**
     * 销毁会话（SessionHandler destroy 回调；DualSessionHandler 的登录态销毁也回落到此）。
     * nncms_session 主行与 session_data 行**必须一起删**——只删一侧会留下「有 data 无主行」的孤儿行
     * （read() 看不到孤儿 data，会话表现为永远为空；历史现场 91 个 data 行对 66 个主行）。
     * @param string $sid 会话 ID
     * @return bool 恒 TRUE（不看底层 rowCount，删除失败不回吐）
     */
    public static function destroy($sid)
    {
        \App\Repository\RepositoryFactory::table('session', 'sid')->delete(array('sid' => $sid));
        \App\Repository\RepositoryFactory::table('session_data', 'sid')->delete(array('sid' => $sid));
        return TRUE;
    }

    /**
     * 会话 Cookie 滑动续期（第十五轮，用户选定方案）
     *
     * start() 里 session.cookie_lifetime=86400，而 PHP 只在**新建会话**时下发
     * Set-Cookie，寿命不随活动滑动；后台登录态（admin_token，cookie_lifetime 默认 100 天）
     * 却还在，于是登录满 24h 后：页面照常打开、写操作一律被 bull_token 拒（满屏「非法操作」）。
     * 本方法在已鉴权的后台请求上重发一次同名 Cookie，把 24h 计时重新拨回——
     * 在用就不掉线，连续 24h 不用才失效（不延长闲置会话，安全窗口不变）。
     *
     * 属性一律取 session_get_cookie_params()（PHP 自己下发会话 Cookie 用的就是它），
     * 绝不在这里另拼 path/domain：两者不一致会让浏览器同时存下两个 nncms_sid
     * （不同 path 允许同名共存），PHP 取到哪个不确定，等于把问题变成随机失效。
     *
     * 每请求最多发一行：同一次请求可能命中两处调用点——前台引导（index.inc.php 的会员续期）
     * 与后台入口（admin.php 的改名副本 include index.php 后，自身再接一处），
     * 只发一次靠调用方约定不可靠，故在本方法内做幂等。
     * @return bool 是否成功重发
     */
    public static function slideCookie()
    {
        static $slid = FALSE;
        if ($slid) return FALSE;
        if ('' === session_id() || headers_sent()) return FALSE;
        // 浏览器没带会话 Cookie = PHP 本次自己新建了会话，紧接着就会下发一份，
        // 再发一次只是重复响应头（同值同属性，浏览器仍只存一份，但没必要）。
        if (empty($_COOKIE[session_name()])) return FALSE;
        $p = session_get_cookie_params();
        $ttl = (int) ini_get('session.cookie_lifetime');
        $ttl > 0 or $ttl = 86400;
        $slid = TRUE;
        return setcookie(session_name(), session_id(), array(
            'expires' => _SERVER('time') + $ttl,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => !empty($p['secure']),
            'httponly' => !empty($p['httponly']),
            'samesite' => isset($p['samesite']) && '' !== $p['samesite'] ? $p['samesite'] : 'Lax',
        ));
    }

    /**
     * 本次请求的会话是从哪里读到的：
     *   NULL=没有走过本类的 read()（CLI/单测）；none=服务端根本没有这份会话（新会话/被回收/没落库）；
     *   mysql=nncms_session 主行命中；redis=DualSessionHandler 的 Redis 命中。
     * 这是只属于读取侧的事实，不会被同一请求后面的写入（渲染时写 _bull_token / user_logout 环）污染，
     * 因此 bull_session_is_empty() 用它来区分「会话没存下来」与「令牌来自另一个会话」。
     */
    public static $loadedFrom = NULL;

    /** 服务端确实没读到这份会话（新会话 / 被回收 / 写入失败） */
    /**
     * 会话主行文本列的列宽（字符数）。默认按 install.sql 迁移后的宽度；
     * 探测到真实列宽后以真实值为准——见 columnLimits()。
     */
    public static $softLimits = array('url' => 255, 'useragent' => 255);

    /** 已生效的列宽（NULL = 本进程尚未探测 / 尚未读文件缓存） */
    public static $probedLimits = NULL;

    /** 列宽文件缓存路径（测试注入缝；NULL = 按 cache_path 计算） */
    public static $limitsFile = NULL;

    /** 带前缀的会话主表名（列宽探测用） */
    public static function tableName()
    {
        $db = isset($_SERVER['db']) ? $_SERVER['db'] : (function_exists('_SERVER') ? _SERVER('db') : NULL);
        $pre = (is_object($db) && isset($db->tablepre)) ? (string)$db->tablepre : '';
        return $pre . 'session';
    }

    /** 列宽文件缓存位置（与 .sess_breaker_state.php 同目录同套路） */
    public static function limitsFile()
    {
        if (NULL !== self::$limitsFile) return self::$limitsFile;
        $conf = function_exists('_SERVER') ? _SERVER('conf') : (isset($GLOBALS['conf']) ? $GLOBALS['conf'] : array());
        $dir = !empty($conf['cache_path']) ? $conf['cache_path'] : (defined('APP_PATH') ? APP_PATH . 'runtime/cache/' : './runtime/cache/');
        return rtrim($dir, '/') . '/.sess_col_limits.php';
    }

    /**
     * 从 DESCRIBE 的 Type 原文取字符列宽度：'varchar(255)' => 255；非字符列/未知 => 0
     * @return int
     */
    public static function parseColumnLen($type)
    {
        if (!is_string($type)) return 0;
        if (!preg_match('#^(?:char|varchar)[(]([0-9]+)[)]$#i', trim($type), $m)) return 0;
        $n = (int)$m[1];
        return $n > 0 ? $n : 0;
    }

    /**
     * 生效列宽：进程内记忆 -> 文件缓存 -> DESCRIBE 探测（$force=TRUE 时跳过前两级重探）。
     * 探测不可用时退回 $softLimits（等于不做强截断，与已迁移 schema 的行为一致）。
     * @return array
     */
    public static function columnLimits($force = FALSE)
    {
        if (!$force && is_array(self::$probedLimits) && self::$probedLimits) return self::$probedLimits;
        $file = self::limitsFile();
        if (!$force && $file && is_readable($file)) {
            $cached = @include $file;
            if (is_array($cached) && $cached) {
                self::$probedLimits = $cached + self::$softLimits;
                return self::$probedLimits;
            }
        }
        $limits = self::$softLimits;
        $table = self::tableName();
        $probed = FALSE;
        if ($table && function_exists('db_column_type')) {
            foreach (array_keys($limits) as $col) {
                $len = self::parseColumnLen(db_column_type($table, $col));
                if ($len > 0) { $limits[$col] = $len; $probed = TRUE; }
            }
        }
        self::$probedLimits = $limits;
        if ($probed && $file) {
            $dir = dirname($file);
            if (!is_dir($dir)) @mkdir($dir, 0777, TRUE);
            @file_put_contents($file, "<?php return " . var_export($limits, TRUE) . ";", LOCK_EX);
        }
        return $limits;
    }

    /**
     * 净化要写进 char/varchar 列的文本：去控制字符 + 去非 BMP 字符（本站列字符集是 utf8 三字节，
     * 4 字节 emoji 会触发 1366 Incorrect string value）+ 丢弃非法 UTF-8 序列。
     * url / useragent 都是对端可控数据，必须假定它什么都可能是。
     */
    public static function sanitizeText($s)
    {
        $s = (string)$s;
        if ('' === $s) return $s;
        $clean = preg_replace('#[[:cntrl:]]#u', '', $s);
        if (!is_string($clean)) $clean = preg_replace('#[[:cntrl:]]#', '', $s); // /u 失败=本身非合法 UTF-8，退回按字节处理
        $s = (string)$clean;
        $no4 = preg_replace('#[\x{10000}-\x{10FFFF}]#u', '', $s); // 4 字节字符：utf8(三字节) 列装不下
        if (is_string($no4)) $s = $no4;
        if (function_exists('mb_check_encoding') && !@mb_check_encoding($s, 'UTF-8')) {
            $fixed = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $s) : FALSE;
            $s = is_string($fixed) ? $fixed : preg_replace('#[^[:print:]]#', '', $s);
        }
        return $s;
    }

    /**
     * 按列宽截断会话行的文本列（MySQL 的 char(N)/varchar(N) 按「字符数」计，故用 mb 口径截断）
     * @param array $row
     * @param array|null $limits 指定列宽（默认取 columnLimits()）
     * @return array
     */
    public static function fitColumns(array $row, $limits = NULL)
    {
        $limits = (is_array($limits) && $limits) ? $limits : self::columnLimits();
        foreach ($limits as $col => $len) {
            $len = (int)$len;
            if ($len < 1 || !isset($row[$col]) || !is_string($row[$col]) || '' === $row[$col]) continue;
            if (strlen($row[$col]) <= $len && !preg_match('#[^\x00-\x7F]#', $row[$col])) continue; // 纯 ASCII 且不超长：免处理
            $s = self::sanitizeText($row[$col]);
            $n = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
            if ($n > $len) $s = function_exists('xn_substr') ? xn_substr($s, 0, $len) : substr($s, 0, $len);
            $row[$col] = $s;
        }
        return $row;
    }

    /**
     * 回读确认：库里这一行是否已经等于目标值
     *
     * 为什么不能看返回值：QueryBuilder::replace() 返回的是 lastInsertId()（nncms_session 没有自增列，
     * 永远 0 或拿到别的表的旧值）；update() 在 MySQL 下开了 CLIENT_FOUND_ROWS（命中即计数，可信），
     * 在 SQLite 测试环境下「值没变化」也返回 0。三种「0」语义完全不同，只有回读才是事实。
     * 只比对本次要写的列，且不做类型强转（decimal 的 ip 回读是字符串）。
     */
    public static function confirmed($repo, $sid, array $want)
    {
        try {
            $stored = $repo->read($sid, 'sid');
        } catch (\Throwable $e) {
            return FALSE;
        }
        if (!is_array($stored) || empty($stored)) return FALSE;
        foreach ($want as $k => $v) {
            if (!array_key_exists($k, $stored)) continue;
            if (trim((string)$stored[$k]) !== trim((string)$v)) return FALSE;
        }
        return TRUE;
    }

    /**
     * session_data 表落库（会话数据超过 255 字符时的真实去处）
     *
     * 主行 + data 行必须同时成功：只有 data 行没有主行时，read() 根本看不到它
     * （主行的 bigdata 标志才是入口），于是会话看起来「存在」却每次都是空的——
     * 本次事故的现场形态（25 条孤儿 data 行）。所以这条路径也要能留痕。
     */
    public static function putData($sid, $data, $time, $diff = NULL)
    {
        $repo = \App\Repository\RepositoryFactory::table('session_data', 'sid');
        $full = array('sid' => $sid, 'data' => $data, 'last_date' => $time);
        if (NULL !== $diff) {
            if (empty($diff)) return TRUE; // 调用方算出「与库中一致」：本就无需写
            if ($repo->update(array('sid' => $sid), $diff) > 0) return TRUE;
        }
        $repo->replace($full);
        $ok = self::confirmed($repo, $sid, $full);
        if (!$ok && function_exists('xn_log')) xn_log('session_data_write_failed sid=' . $sid . ' len=' . strlen($data), 'session_write_error');
        return $ok;
    }

    /**
     * 会话主行统一落库口（写不进去必须留痕，绝不再静默）
     *
     * 根因修复：nncms_session.url char(32) / useragent char(128) 装不下后台 URI 与
     * 新版浏览器 UA（现场：/yg2twb.php?0=tmpl&1=down&dir=03&downurl=… = 44 字符；Quark UA = 129 字符），
     * MySQL 5.7+/8 默认 sql_mode 含 STRICT_TRANS_TABLES ⇒ REPLACE 报 1406 Data too long，
     * PdoDriver::error() 又把 PDOException 吞成 false ⇒ 主行永远写不进去；而下一请求 read() 只看主行，
     * 于是每一次请求都被当成全新会话 ⇒ bull_token 场景指纹环永远为空 ⇒ 后台/前台每一次写操作都被判
     * 「非法操作 / 令牌来自上一个会话」，重新登录、刷新都不自愈（现场 08-30 00:49 用户 192.0.2.197：
     * 00:49:26 登录 → 00:49:27 渲染 → 00:49:37/41/46 三次提交全部失败）。
     * session_data 表没有这两列、写成功了，于是留下一堆「有 data 无主行」的孤儿行（read() 读不到）。
     *
     * 三级策略：① 按已知列宽截断后写（diff 非空先走增量 UPDATE）；② 未命中则整行 REPLACE 并回读确认；
     * ③ 回读仍不符（列宽假设不成立 = 存量站点没跑迁移）⇒ 强制重探真实列宽 + 文本净化后再写一次，
     * 并把过程与修复用 ALTER 语句记进 session_write_error 日志。
     *
     * @param string $sid 会话 ID
     * @param array $row 完整行（须含 sid）
     * @param array|null $diff 增量列（NULL = 整行 REPLACE）
     * @return bool 是否已落库（含「与库中行一致、无需写」）
     */
    public static function putRow($sid, array $row, $diff = NULL)
    {
        $sid = (string)$sid;
        if ('' === $sid) return FALSE;
        $repo = \App\Repository\RepositoryFactory::table('session', 'sid');
        $limits = self::columnLimits();
        $row = self::fitColumns($row, $limits);
        $row['sid'] = $sid;
        if (NULL !== $diff) {
            $update = self::fitColumns($diff, $limits);
            unset($update['sid']);                              // 增量里不接受改主键
            if (empty($update)) return TRUE;                    // 与库中行完全一致：本就无需写
            if ($repo->update(array('sid' => $sid), $update) > 0) return TRUE;
        }
        $repo->replace($row);
        if (self::confirmed($repo, $sid, $row)) return TRUE;     // 回读确认（返回值不可信）
        self::$probedLimits = NULL;
        $retry = self::fitColumns($row, self::columnLimits(TRUE)); // 真实列宽 + 净化后再试一次
        $repo->replace($retry);
        $ok = self::confirmed($repo, $sid, $retry);
        self::logWriteFailure($sid, $limits, $ok);
        return $ok;
    }

    /** 会话主行写入失败留痕（通道名含 error => LoggerService 恒写，与第十四轮审计通道同口径） */
    public static function logWriteFailure($sid, $limits, $recovered)
    {
        if (!function_exists('xn_log')) return FALSE;
        $fix = "ALTER TABLE " . self::tableName() . " MODIFY url varchar(255) NOT NULL DEFAULT '', MODIFY useragent varchar(255) NOT NULL DEFAULT '';"; // 可直接执行
        return xn_log('session_row_write_failed sid=' . $sid
            . ' limits=' . json_encode($limits)
            . ' probed=' . json_encode(self::$probedLimits)
            . ' retry=' . ($recovered ? 'ok' : 'fail')
            . '; 严格模式(1406/1366)下多为列宽/字符集不足，修复：' . $fix,
            'session_write_error');
    }

    /**
     * 会话启动总入口（sess_start 兼容壳转发；引导在 sess_start() 之后跑中间件——限流依赖 session_id）。
     * 做四件事：① ini 设定 Cookie 口径——名称 cookie_pre+'sid'、httponly、SameSite=Lax、HTTPS 自动 secure、
     *   寿命 86400s（24h 定长，**不随活动滑动**；在用续命由 slideCookie() 重发同名 Cookie 解决，
     *   与 admin_token 100 天寿命的不对称即「后台登录满 24h 写操作被拒」根因，见 slideCookie 注释，勿移除）；
     *   ② GC 概率 1/1000、maxlifetime=online_hold_time；
     *   ③ 双存储处理器选型：redis 扩展在且 SessionService::redis() 可用 ⇒ DualSessionHandler（匿名→Redis、登录→MySQL），
     *   否则 BullSessionHandler（纯 MySQL）；④ session_start 失败（自定义处理器故障）降级默认文件处理器重试，不外抛。
     * 另有 register_shutdown_function('session_write_close')（防 Zend 提前释放 $db 资源）；
     * url_rewrite_on>1 时先 chdir(APP_PATH)——register_shutdown_function 回调里工作目录会丢失，write() 依赖相对路径资源。
     * @return string session_id()（失败链路上也可能为空串——调用方按 falsy 兜底）
     */
    public static function start()
    {
        $conf = _SERVER('conf');         global $sid, $g_session;
        $https = (!empty(_SERVER('HTTPS')) && strtolower(_SERVER('HTTPS')) != 'off') || (NULL !== _SERVER('HTTP_X_FORWARDED_PROTO') && _SERVER('HTTP_X_FORWARDED_PROTO') === 'https');
        ini_set('session.name', $conf['cookie_pre'] . 'sid');
        ini_set('session.use_cookies', TRUE);
        ini_set('session.use_only_cookies', TRUE);
        ini_set('session.use_strict_mode', TRUE);
        ini_set('session.use_trans_sid', FALSE);
        ini_set('session.cookie_domain', $conf['cookie_domain']);
        // 为空则表示当前目录和子目录
        ini_set('session.cookie_path', $conf['cookie_path']);
        // 打开后只有通过 https 才有效
        ini_set('session.cookie_secure', $https ? TRUE : FALSE);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_lifetime', 86400); // 24小时
        // 打开后 js 获取不到 HTTP 设置的 cookie, 有效防止 XSS
        ini_set('session.cookie_httponly', TRUE);
        // 活动时间
        ini_set('session.gc_maxlifetime', $conf['online_hold_time']);
        // 垃圾回收概率 = gc_probability/gc_divisor
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_divisor', 1000);
        // Save path for file-based session fallback（自定义处理器失效时防止空路径错误）
        ini_set('session.save_path', $conf['cache_path'] ?: APP_PATH . 'runtime/tmp/');
        // 双存储处理器：登录→MySQL，匿名→Redis
        if (extension_loaded("redis") && self::redis()) {
            $sessionHandler = new \App\Service\Account\Handler\DualSessionHandler();
        } else {
            $sessionHandler = new \App\Service\Account\Handler\BullSessionHandler();
        }
        session_set_save_handler($sessionHandler);
        // register_shutdown_function 会丢失当前目录，需要 chdir(APP_PATH)
        $conf['url_rewrite_on'] > 1 and function_exists('chdir') and chdir(APP_PATH);
        // 这个必须有，否则 ZEND 会提前释放 $db 资源
        register_shutdown_function('session_write_close');
        // 带失败回退的 session_start：自定义处理器失败时降级为默认文件处理器
        if (@session_start()) {
            self::stripL0LimiterHeaders();
            $sid = session_id();
            return $sid;
        }
        // 降级：移除自定义处理器，使用文件处理器重试
        session_set_save_handler(new \SessionHandler(), true);
        @session_start();
        self::stripL0LimiterHeaders();
        $sid = session_id();
        return $sid;
    }

    /**
     * nginx L0 页面缓存（fastcgi_cache）适配：
     * session_cache_limiter 在「无 cookie 请求」上无条件发送 no-store/no-cache + Pragma: no-cache +
     * Expires:1981 拒绝缓存三件套，nginx upstream 判定见之拒绝存储（Pragma 无法用 ignore_headers 豁免）。
     * 仅当请求带 NNCMS_L0 标记（nginx 只在「匿名 GET 可缓存」location 注入该 fastcgi_param；POST/带会话
     * 请求走 @nncms_php 无标记、头保持原样）时剥除之——被剥三件套的响应本就只服务可缓存流量，无副作用。
     * （QPS 轮，配合 extension/www.example.com/perf.conf）
     */
    public static function stripL0LimiterHeaders()
    {
        if (empty($_SERVER['NNCMS_L0'])) {
            return;
        }
        header_remove('Cache-Control');
        header_remove('Pragma');
        header_remove('Expires');
    }

    /**
     * MySQL 侧会话垃圾回收（SessionHandler gc 回调，概率 1/1000 触发；Redis 层靠 TTL 自动过期，这里是兜底）。
     * 一次最多扫 10000 条 last_date < now-$maxlifetime 的主行；「6 小时内活跃且 bigdata=1」的行跳过删除
     * （保护正在进行的上传附件），其余 sid 同删 session 与 session_data 两表（不留孤儿 data 行）。
     * @param int $maxlifetime 存活秒数（PHP 传入，配置源 conf['online_hold_time']）
     * @return bool 恒 TRUE
     */
    public static function gc($maxlifetime)
    {
        $time = _SERVER('time');
        $expiry = $time - $maxlifetime;
        $arrlist = \App\Repository\RepositoryFactory::table('session', 'sid')->find(array('last_date' => array('<' => $expiry)), array(), 1, 10000, '', array('sid', 'bigdata', 'last_date'));
        if (!$arrlist) return TRUE;
        $expiry = $time - 21600; // 超6小时未提交丢弃上传图片和附件
        $sidarr = array();
        foreach ($arrlist as $val) {
            if ($val['last_date'] > $expiry && $val['bigdata']) continue;
            $sidarr[] = $val['sid'];
        }
        if (empty($sidarr)) return TRUE;
        \App\Repository\RepositoryFactory::table('session', 'sid')->delete(array('sid' => $sidarr));
        \App\Repository\RepositoryFactory::table('session_data', 'sid')->delete(array('sid' => $sidarr));
        return TRUE;
    }

    /**
     * 用后即焚超大会话附件：当前会话（global $sid）主行 bigdata=1 时复位标志（bigdata=0 + 刷 last_date）并删 session_data 行。
     * 主行不存在或本就非 bigdata 行时不动。
     * @return bool 恒 TRUE
     */
    /**
     * 获取 Redis 配置（从多层缓存配置中提取 redis 层）
     */
    public static function redisConfig(): array
    {
        $conf = _SERVER('conf');
        if (!empty($conf['cache']['multi']['layers'])) {
            foreach ($conf['cache']['multi']['layers'] as $layer) {
                if (($layer['driver'] ?? '') === 'redis') {
                    return $layer['config'] ?? [];
                }
            }
        }
        return $conf['cache']['redis'] ?? [];
    }

    /**
     * 获取 Redis 连接（用于 session 在线统计、DualSessionHandler 匿名会话存储等）
     * 修复三连：
     *  ① 返回类型原为 ?Redis——命名空间内解析成不存在的 App\Service\Account\Redis，实际返回全局 \Redis
     *    即抛 TypeError → 调用方永远静默回落 MySQL（DualSessionHandler 自接入起从未生效的根因）→ 改 ?\Redis；
     *  ② 原实现 ping 失败即永久钉死 static $failed（该 FPM worker 直到重启前都不再尝试）→ 改为死连接直接重建，
     *    仅建连真实失败时进入 30s 失败冷却（冷却后自动重试，可恢复）；
     *  ③ 熔断文件与 MultiLayerCache 的 .breaker_state.php 同名共享（缓存层波动会连坐会话、互相覆写状态）
     *    → 独立 .sess_breaker_state.php。
     * 使用 pconnect + 独立 persistent_id 避免与主缓存连接冲突
     * @return \Redis|null
     */
    public static function redis(): ?\Redis
    {
        static $redis = null;
        static $failedAt = 0;
        if ($failedAt && time() - $failedAt < 30) return null;

        // 跨请求熔断器（会话独立文件，不与缓存层互相污染）
        $conf = _SERVER('conf');
        $breakerFile = ($conf['cache_path'] ?? './runtime/cache/') . '.sess_breaker_state.php';
        if (file_exists($breakerFile)) {
            $breakerData = @include $breakerFile;
            if (is_array($breakerData) && isset($breakerData['tripped']['redis'])) {
                $trippedAt = $breakerData['tripped']['redis'];
                if (time() - $trippedAt < 30) {
                    $failedAt = time();
                    return null;
                }
            }
        }

        if ($redis) {
            try {
                $redis->ping();
                return $redis;
            } catch (\Throwable $e) {
                $redis = null; // 死连接：直接走下方重建，不记失败、不写熔断
            }
        }
        $cfg = self::redisConfig();
        if (empty($cfg)) { $failedAt = time(); return null; }
        try {
            $r = new \Redis();
            $pid = !empty($cfg['persistent_id']) ? $cfg['persistent_id'] . '_sess' : 'nncms_sess';
            $r->pconnect($cfg['host'] ?? '127.0.0.1', (int)($cfg['port'] ?? 6379), 1, $pid);
            if (!empty($cfg['auth'])) $r->auth($cfg['auth']);
            $r->select((int)($cfg['db'] ?? 2));
            $redis = $r;
            return $redis;
        } catch (\Throwable $e) {
            $failedAt = time();
            try {
                $breakerDir = dirname($breakerFile);
                if (!is_dir($breakerDir)) @mkdir($breakerDir, 0777, true);
                $content = '<?php return ' . var_export(['tripped' => ['redis' => time()]], true) . ';';
                @file_put_contents($breakerFile, $content, LOCK_EX);
            } catch (\Throwable $e2) {}
            return null;
        }
    }

    /**
     * 扫描 Redis 中全部 session（sess_* 键 -> unserialize 数组，含匿名 uid=0）
     * 三个在线统计入口共用该骨架（SCAN + mget + 反序列化）
     */
    private static function scanRedisSessions()
    {
        $sessions = array();
        $redis = self::redis();
        if (!$redis) return $sessions;
        $it = null;
        while ($keys = $redis->scan($it, 'sess_*', 1000)) {
            if (empty($keys)) continue;
            $vals = $redis->mget($keys);
            foreach ($vals as $v) {
                if ($v === false || $v === null) continue;
                $arr = @unserialize($v, ['allowed_classes' => false]);
                if (is_array($arr)) {
                    $sessions[] = $arr;
                }
            }
        }
        return $sessions;
    }

    /**
     * 在线用户数
     * ⚠ 名实更正：原注释写「优先 Redis / 降级 MySQL」，实现其实是**两路相加**——
     *   Redis SCAN 出的 sess_* 数 + MySQL 里 uid>0 的会话行数。
     *   口径依据：DualSessionHandler 按登录态分流（登录写 MySQL 并清 Redis、匿名只留 Redis），
     *   所以常态下两个集合不重叠；但 Redis 侧 SCAN 不按 uid 过滤，若残留登录态键（降级/过渡期双写）
     *   会**双计**同一访客，这不是主备切换、不存在"Redis 挂了才查 MySQL"的语义。
     * @return int 在线数；实现带 max(1, ·) 下限 ⇒ 零访客也返回 1，勿当"是否有人在线"的布尔判据。
     */
    public static function onlineCount()
    {
        $total = count(self::scanRedisSessions());
        // 双存储模式下，MySQL 还有登录 session
        $total += \App\Repository\RepositoryFactory::table('session', 'sid')->count(array('uid' => array('>' => 0)));
        return max(1, $total);
    }

    /**
     * 在线用户详细列表（含用户名、分组、IP、最后活跃时间）
     * ⚠ 名实更正：原注释写「优先 Redis SCAN + mget / 降级 MySQL session_find」，实现不是主备降级，
     *   而是**两路合并 + 按 uid 去重**：先收 Redis 侧 sess_* 里有 uid 的会话（匿名跳过，Redis 先到先占位），
     *   再用 MySQL 中 uid>0 的会话行补齐未出现过的人（登录态在双存储模式下的权威存储是 MySQL）。
     *   ⇒ Redis 不可用（无 redis 扩展/连不上）时 scanRedisSessions() 返回空集，列表仍由 MySQL 撑起，
     *     这才是原「降级」二字想表达但说错了的行为；两侧都有同一 uid 时以 Redis 记录为准。
     *   注：实现里内联注释的步骤编号是 1→3（2 号步骤已不在代码中），按代码顺序读，勿按编号找代码。
     * @return array 顺序列表，每行 uid/username/gid/ip_fmt/last_date_fmt/url/useragent；上限受 MySQL 侧 1000 行约束
     */
    /**
     * 在线用户 UID 集合（用于判断用户是否在线）
     * ⚠ 名实更正：原注释写「优先 Redis / 降级 MySQL」，实现是**两路合并 + 按 uid 去重**——
     *   先取 Redis sess_* 中带 uid 的会话，再用 MySQL 里 uid>0 的行补齐未见过的 uid。
     *   Redis 不可用时第一路为空集，结果仍由 MySQL 撑起（这才是"降级"的实际形态）；
     *   同一 uid 两侧都有时保留 Redis 侧的行，不做字段合并。
     * @return array 以 uid 为键的集合，值统一为 ['uid'=>uid]（Redis 路手拼、MySQL 路靠显式列 array('uid')
     *               取同一列，两路形态一致）；调用方只应把它当"是否在线"的键集合用。
     */
    public static function onlineUserListCache()
    {
        static $cache = array();
        $key = 'online_user_list';
        if (isset($cache[$key])) return $cache[$key];
        $cache[$key] = cache_get($key);
        if (NULL === $cache[$key]) {
            $ulist = [];
            $seen_uid = [];

            // 1. Redis 登录 session（旧遗留数据）
            $redis_sessions = self::scanRedisSessions();
            foreach ($redis_sessions as $arr) {
                if (empty($arr['uid'])) continue;
                $uid = $arr['uid'];
                $seen_uid[$uid] = true;
                $ulist[$uid] = ['uid' => $uid];
            }

            // 2. MySQL 登录 session（权威存储）
            $mysql_ulist = \App\Repository\RepositoryFactory::table('session', 'sid')->find(array('uid' => array('>' => 0)), array(), 1, 1000, 'uid', array('uid'));
            foreach ($mysql_ulist as $uid => $row) {
                if (isset($seen_uid[$uid])) continue;
                $ulist[$uid] = $row;
            }

            $cache[$key] = $ulist ?: [];
            cache_set('online_user_list', $cache[$key], 300);
        }
        return $cache[$key];
    }

    /**
     * 上线前审计 AUTH-02：按 uid 销毁该用户全部会话（MySQL 主行+data 行，以及 Redis sess_*）。
     * 改密后调用，使其它设备/浏览器上的登录态立即失效（不仅轮换当前 sid）。
     * @param int $uid 用户 ID
     * @param string $exceptSid 保留的会话 ID（通常传当前 session_id()，避免本请求立刻失会话）
     * @return int 销毁条数（近似）
     */
    public static function destroyByUid(int $uid, string $exceptSid = ''): int
    {
        if ($uid <= 0) return 0;
        $n = 0;
        $exceptSid = (string)$exceptSid;
        try {
            $rows = \App\Repository\RepositoryFactory::table('session', 'sid')->find(
                array('uid' => $uid),
                array(),
                1,
                10000,
                '',
                array('sid')
            );
            foreach ((array)$rows as $row) {
                $sid = is_array($row) ? (string)($row['sid'] ?? '') : '';
                if ($sid === '' || ($exceptSid !== '' && hash_equals($exceptSid, $sid))) continue;
                self::destroy($sid);
                $n++;
            }
        } catch (\Throwable $e) {}
        $redis = function_exists('_sess_redis') ? _sess_redis() : null;
        if ($redis) {
            try {
                $it = null;
                do {
                    $keys = $redis->scan($it, 'sess_*', 100);
                    if (!is_array($keys)) break;
                    foreach ($keys as $key) {
                        $sid = (strpos((string)$key, 'sess_') === 0) ? substr((string)$key, 5) : '';
                        if ($exceptSid !== '' && $sid !== '' && hash_equals($exceptSid, $sid)) continue;
                        $val = $redis->get($key);
                        if ($val === false) continue;
                        $arr = @unserialize($val, array('allowed_classes' => false));
                        if (is_array($arr) && intval($arr['uid'] ?? 0) === $uid) {
                            $redis->del($key);
                            $n++;
                        }
                    }
                } while ($it > 0);
            } catch (\Throwable $e) {}
        }
        return $n;
    }
}
