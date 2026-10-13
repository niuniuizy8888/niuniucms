<?php
/**
 * PSR-15 HTTP Server Request Handler Interface
 * 渐进式适配：现有代码无独立 Response 对象（echo/exit 模式），
 * 接口方法不强制 ResponseInterface 返回类型
 */

namespace Psr\Http\Server;

use Psr\Http\Message\RequestInterface;

interface RequestHandlerInterface
{
    /**
     * @param RequestInterface $request 传入请求（本管道传 `App\Request`，它实现 RequestInterface）
     * @return mixed 现有代码以 echo/exit 输出，允许返回任意值透传
     *
     * 注意：把参数类型**写进接口**（而非只写 @param）是刻意的——PHP 不允许实现方
     * **收窄**参数类型（实测 `Declaration of ... must be compatible`），
     * 只写注释会让「实现方加类型」变成致命错误。类型写在接口上，实现方才能安全声明。
     */
    public function handle(RequestInterface $request);
}
