<?php
namespace App\Service\Account\Handler;
class DualSessionHandler implements \SessionHandlerInterface
{
    private $ttl;

    public function __construct()
    {
        $conf = _SERVER('conf');
        $this->ttl = (int)($conf['online_hold_time'] ?? 1800);
    }

    /** @param string $path @param string $name */
    public function open($path, $name): bool { return true; }
    /**
     * SessionHandlerInterface::close 契约实现 —— 本处理器无每请求连接需释放
     * （Redis 句柄由 _sess_redis() 全局复用），恒 TRUE。
     * @return bool
     */
    public function close(): bool { return true; }

    /**
     * SessionHandlerInterface::read 实现 —— **双存储三级取数**（本处理器是会话读取的主入口）：
     *  1) Redis 快路径：键 'sess_<id>' 命中即取（匿名会话留在 Redis，省一次 MySQL 查询）；
     *     值经 @unserialize（allowed_classes 为 false，**禁对象还原**，防反序列化 gadget），
     *     不是数组就当未命中继续往下；整段包 try/catch，**Redis 挂了不影响走 MySQL**。
     *  2) MySQL 慢路径：session_read($id) 裸查主行、**不自动建行**（auto-create 会给扫描器留垃圾行）；
     *     bigdata 行再从 session_data 表取真实 data（主行的 data 列是空的）。查询异常按「无行」处理。
     *  3) 全新会话：不调 sess_new 预建 MySQL 记录，**由 write() 首次写入时决定存储位置**；
     *     同时把一份全零骨架放进 $GLOBALS['g_session']。
     * 每级都会写 SessionService::$loadedFrom（'redis' / 'mysql' / 'none'）——
     * 这是 bull_session_is_empty() 与后台「非法操作」失败分类器的**判据来源**（§五 会话排障口径：
     * session_empty 要指向会话存储本身），删掉或写错这个打点会让运维方向从第一步就错。
     * 命中时同步还原 $_SESSION['uid'] / ['cid']（这两列单独建列，不进 session_encode 的 data），
     * 并把整行原始数组放进 $GLOBALS['g_session'] 供写库短路/diff 使用；**返回值只是 data 段**（给 PHP decode 用）。
     * @param string $id 会话 ID（PHP 传入）
     * @return string 会话数据原始串（session 序列化格式）；各级都没取到 = 空串（PHP 视为新会话）
     */
    #[\ReturnTypeWillChange]
    public function read($id): string
    {
        // 1. Redis 快路径（匿名 session）
        $redis = _sess_redis();
        if ($redis) {
            try {
                $val = $redis->get('sess_' . $id);
                if ($val !== false) {
                    $arr = @unserialize($val, ['allowed_classes' => false]);
                    if (is_array($arr)) {
                        \App\Service\Account\SessionService::$loadedFrom = 'redis';
                        if (isset($arr['uid'])) $_SESSION['uid'] = $arr['uid'];
                        if (isset($arr['cid'])) $_SESSION['cid'] = $arr['cid'];
                        $GLOBALS['g_session'] = $arr;
                        return $arr['data'] ?? '';
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 2. MySQL 慢路径（登录 session）
        try {
            $arr = session_read($id); // 裸 DB 查询，不 auto-create
        } catch (\Throwable $e) {
            $arr = null;
        }
        if ($arr && is_array($arr)) {
            \App\Service\Account\SessionService::$loadedFrom = 'mysql';
            if (!empty($arr['bigdata'])) {
                $arr2 = session_data_read($id);
                $arr['data'] = $arr2['data'] ?? $arr['data'];
            }
            $GLOBALS['g_session'] = $arr;
            if (isset($arr['uid'])) $_SESSION['uid'] = $arr['uid'];
            if (isset($arr['cid'])) $_SESSION['cid'] = $arr['cid'];
            return $arr['data'] ?? '';
        }

        // 3. 全新 session：不调用 sess_new（避免预建 MySQL 记录），
        //    由 write() 首次写入时决定存储位置
        \App\Service\Account\SessionService::$loadedFrom = 'none'; // 服务端没有这份会话（分类器要用这个事实）
        $GLOBALS['g_session'] = [
            'sid'       => $id,
            'uid'       => 0,
            'cid'       => 0,
            'data'      => '',
            'ip'        => 0,
            'last_date' => 0,
            'url'       => '',
            'useragent' => '',
            'bigdata'   => 0,
        ];
        return '';
    }

    #[\ReturnTypeWillChange]
    /** @param string $id @param string $data */
    public function write($id, $data): bool
    {
        $time = _SERVER('time');         $longip = _SERVER('longip');         global $g_session;
        $uid = (int)_SESSION('uid');

        if ($uid > 0) {
            // 已登录 → 写入 MySQL，清理 Redis
            $this->delRedis($id);
            $cid = (int)_SESSION('cid');
            unset($_SESSION['uid'], $_SESSION['cid']);
            $data and $data = session_encode();
            // 并发写覆盖竞态维护：键级合并（与 SessionService::write 同口径）
            $curData = '';
            try {
                $cur = session_read($id);
                if (is_array($cur)) {
                    $curData = (string)($cur['data'] ?? '');
                    if (1 == intval($cur['bigdata'] ?? 0)) {
                        $curRow2 = session_data_read($id);
                        $curData = (string)($curRow2['data'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
                $curData = '';
            }
            if ($data !== '') {
                $parsedCur = \App\Service\Account\SessionService::parseSessionData($curData);
                $merged = $parsedCur;
                foreach (\App\Service\Account\SessionService::parseSessionData($data) as $k => $v) {
                    $merged[$k] = $v;
                }
                $data = $merged === $parsedCur ? $curData : \App\Service\Account\SessionService::buildSessionData($merged);
            }
            // P-9（2026-10-10 性能修复）：会话数据未变化时短路写库。
            // 登录态每请求原为 1 次合并读 + 1~2 次写（putRow/putData），只读页面也照写。
            // session_delay_update>0 且数据与库内一致且 last_date 在窗口内 ⇒ 跳过写；
            // safe_token 指纹环等安全字段变化必然使 $data 与 $curData 不一致 ⇒ 恒写，不受影响。
            $confArr = (array)_SERVER('conf');
            $delay = intval($confArr['session_delay_update'] ?? 0);
            $lastDate = is_array($cur) ? intval($cur['last_date'] ?? 0) : 0;
            $skipWrite = $delay > 0 && is_array($cur) && $data === $curData && ($time - $lastDate) < $delay;
            $arr = [
                'sid'       => $id,
                'uid'       => $uid,
                'cid'       => $cid,
                'url'       => _SERVER('REQUEST_URI') ?? '',
                'last_date' => $time,
                'data'      => $data,
                'ip'        => $longip,
                'useragent' => _SERVER('HTTP_USER_AGENT') ?? '',
                'bigdata'   => 0,
            ];
            if ($skipWrite) {
                // 只读页快速路径：url/useragent/last_date 等「不重要数据」允许延迟 $delay 秒
                // （与 config 注释口径一致）；内存 $g_session 照常更新，返回成功。
                $g_session = $arr;
                return true;
            }
            // data 字段超长处理：超过 255 字符时存入 session_data 表
            // 修复：统一走 SessionService::putRow()/putData()（列宽截断 + 失败重试 + 留痕），
            // 不再直接 session_create()——nncms_session.url char(32)/useragent char(128) 在严格模式下
            // 会 1406 静默丢主行，导致 safe_token 场景环永远读不回来（全站写操作被拒）。
            $datalen = strlen($data);
            if ($datalen > 255) {
                $arr['data'] = '';
                $arr['bigdata'] = 1;
                \App\Service\Account\SessionService::putRow($id, $arr);
                \App\Service\Account\SessionService::putData($id, $data, $time);
            } else {
                \App\Service\Account\SessionService::putRow($id, $arr);
            }
            $g_session = $arr;
        } else {
            // 匿名 → 写入 Redis，清理 MySQL
            $this->delMySQL($id);
            $cid = (int)_SESSION('cid');
            unset($_SESSION['uid'], $_SESSION['cid']);
            $data and $data = session_encode();
            $redis = _sess_redis();
            if ($redis) {
                $all = [
                    'uid'       => 0,
                    'cid'       => $cid,
                    'data'      => $data,
                    'ip'        => $longip,
                    'last_date' => $time,
                    'url'       => _SERVER('REQUEST_URI') ?? '',
                    'useragent' => _SERVER('HTTP_USER_AGENT') ?? '',
                ];
                $redis->setex('sess_' . $id, $this->ttl, serialize($all));
                $g_session = $all;
            } elseif ('' !== $data) {
                // ：Redis 不可用时原实现是「什么都不写」——本请求往 $_SESSION 里登记的
                // bull_token 场景环就此蒸发，下一请求读不到 ⇒ 该标签页每次写操作都被判非法操作。
                // 兜底落 MySQL（仅当本请求确有会话数据：纯浏览的匿名访客 $data 为空，照旧不落库，
                // 与 SessionService::write() 的 QPS 防护同口径），并留痕提醒恢复 Redis。
                \App\Service\Account\SessionService::putRow($id, [
                    'uid'       => 0,
                    'cid'       => $cid,
                    'url'       => _SERVER('REQUEST_URI') ?? '',
                    'last_date' => $time,
                    'data'      => $data,
                    'ip'        => $longip,
                    'useragent' => _SERVER('HTTP_USER_AGENT') ?? '',
                    'bigdata'   => 0,
                ]);
                if (function_exists('xn_log')) xn_log('session_redis_unavailable_fallback_mysql sid=' . $id, 'session_write_error');
            }
        }
        return true;
    }

    #[\ReturnTypeWillChange]
    /** @param string $id */
    public function destroy($id): bool
    {
        $this->delRedis($id);
        return \App\Service\Account\SessionService::destroy($id);
    }

    #[\ReturnTypeWillChange]
    /** @param int $maxlifetime */
    public function gc($maxlifetime): int
    {
        // Redis 匿名 session 由 TTL 自动过期，无需 GC
        // MySQL 登录 session 由 PHP GC 或 sess_gc 清理
        return \App\Service\Account\SessionService::gc($maxlifetime);
    }

    /**
     * 删除 Redis 侧匿名会话键 sess_<id>；Redis 不可用或命令异常一律吞掉（销毁正确性由 MySQL 侧
     * 与 Redis TTL 兜底，此处失败不阻断登出流程）。
     * @param string $id 会话 ID
     * @return void
     */
    private function delRedis(string $id): void
    {
        $redis = _sess_redis();
        if ($redis) {
            try { $redis->del('sess_' . $id); } catch (\Throwable $e) {}
        }
    }

    /**
     * 删 MySQL 侧会话。
     * 修复：原来只删主行，session_data 行留在表里成孤儿（gc 以主行为基准，永远扫不到它们），
     * 现场 91 个 data 行对 66 个主行。两张表必须一起删（与 SessionService::destroy() 同口径）。
     */
    private function delMySQL(string $id): void
    {
        try { session_delete(['sid' => $id]); } catch (\Throwable $e) {}
        try { session_data_delete(['sid' => $id]); } catch (\Throwable $e) {}
    }
}
