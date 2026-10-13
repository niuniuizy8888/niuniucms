<?php
namespace App\Service\Account\Handler;
class RedisBullSessionHandler implements \SessionHandlerInterface
{
    private $redis = null;
    private $ttl;

    public function __construct()
    {
        $conf = _SERVER('conf');
        $this->ttl = $conf['online_hold_time'] ?? 3600;
    }

    private function conn()
    {
        if ($this->redis) {
            try { $this->redis->ping(); return $this->redis; } catch (\Throwable $e) { $this->redis = null; }
        }
        $this->redis = _sess_redis();
        return $this->redis;
    }

    /** @param string $path @param string $name */
    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }

    #[\ReturnTypeWillChange]
    public function read($id): string
    {
        $redis = $this->conn();
        if (!$redis) return '';
        $val = $redis->get('sess_' . $id);
        if ($val === false) return '';
        $arr = @unserialize($val, ['allowed_classes' => false]);
        if (!is_array($arr)) return '';
        // 还原 uid/cid 到 $_SESSION（write 时会从 $_SESSION 取并剥离）
        if (isset($arr['uid'])) $_SESSION['uid'] = $arr['uid'];
        if (isset($arr['cid'])) $_SESSION['cid'] = $arr['cid'];
        $GLOBALS['g_session'] = $arr;
        return $arr['data'] ?? '';
    }

    /** @param string $id @param string $data */
    public function write($id, $data): bool
    {
        $time = _SERVER('time');         $longip = _SERVER('longip');         global $g_session;
        $redis = $this->conn();
        if (!$redis) return false;
        $uid = _SESSION('uid');
        $cid = _SESSION('cid');
        // 移除 uid/cid 防止 session_encode 包含它们
        unset($_SESSION['uid'], $_SESSION['cid']);
        $all = [
            'uid'       => $uid,
            'cid'       => $cid,
            'data'      => $data ?: session_encode(),
            'ip'        => $longip,
            'last_date' => $time,
            'url'       => _SERVER('REQUEST_URI') ?? '',
            'useragent' => _SERVER('HTTP_USER_AGENT') ?? '',
        ];
        $redis->setex('sess_' . $id, $this->ttl, serialize($all));
        $g_session = $all;
        return true;
    }

    /** @param string $id */
    public function destroy($id): bool
    {
        $redis = $this->conn();
        if (!$redis) return false;
        $redis->del('sess_' . $id);
        return true;
    }

    /** @param int $maxlifetime */
    public function gc($maxlifetime): int
    {
        // Redis TTL 自动过期，无需 GC
        return 0;
    }
}

/**
 * 双存储 Session 处理器
 *
 * 登录 Session (uid > 0) → MySQL （持久化，不受 Redis 重启影响）
 * 匿名 Session (uid = 0) → Redis（快速读写，30 分钟 TTL 自动过期）
 *
 * 读顺序：Redis（匿名快路径） → MySQL（登录持久路径）→ 新建
 * 写自动迁移：登录时从 Redis 迁移至 MySQL，退出时从 MySQL 迁移至 Redis
 */
