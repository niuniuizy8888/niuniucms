<?php
/**
 * PSR-15 HTTP Server Middleware Interface
 * 自 HandlerInterface.php 拆分：原两接口同文件导致
 * autoload 按类名查找 MiddlewareInterface.php 时失败（顺序依赖脆弱），
 * 拆分后 PSR-4 文件命名与类名一致。
 */

namespace Psr\Http\Server;

use Psr\Http\Message\RequestInterface;

interface MiddlewareInterface
{
    /**
     * @param RequestInterface        $request 传入请求（本管道传 `App\Request`，它实现 RequestInterface）
     * @param RequestHandlerInterface $handler 下一个处理器
     * @return mixed 本管道用「返回值」表达结果：返回 true = 放行，false = 拦截（调用方据此 exit）。
     *               故不声明 `ResponseInterface` 返回类型——那会与既有的布尔语义冲突。
     */
    public function process(RequestInterface $request, RequestHandlerInterface $handler);
}
