<?php
namespace App\Service\Account\Handler;
class BullSessionHandler implements \SessionHandlerInterface
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
}
