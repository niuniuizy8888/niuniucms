<?php
namespace App\Service\Account\Handler;
class BullSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** @param string $savePath @param string $sessionName */
    public function open($savePath, $sessionName): bool
    {
        return sess_open($savePath, $sessionName);
    }
    /**
     * SessionHandlerInterface::close 契约实现 —— 转过程函数 sess_close()
     * （@deprecated 兼容壳，底层转发 SessionService::close()，现恒返回 true）。
     * @return bool
     */
    public function close(): bool
    {
        return sess_close();
    }
    #[\ReturnTypeWillChange]
    /** @param string $id */
    public function read($id): string
    {
        return sess_read($id);
    }
    /** @param string $id @param string $data */
    public function write($id, $data): bool
    {
        return sess_write($id, $data);
    }
    /** @param string $id */
    public function destroy($id): bool
    {
        return sess_destroy($id);
    }
    /** @param int $maxlifetime */
    public function gc($maxlifetime): int
    {
        $r = sess_gc($maxlifetime);
        return is_int($r) ? $r : 0;
    }

    /** 上线前审计 AUTH-01：仅当 MySQL 会话行存在时接受 sid */
    public function validateId($id): bool
    {
        if (!is_string($id) || $id === '' || strlen($id) > 128 || !preg_match('#^[A-Za-z0-9,-]+$#', $id)) {
            return false;
        }
        try {
            $arr = session_read($id);
            return is_array($arr) && !empty($arr);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function updateTimestamp($id, $data): bool
    {
        return $this->write($id, $data);
    }
}
