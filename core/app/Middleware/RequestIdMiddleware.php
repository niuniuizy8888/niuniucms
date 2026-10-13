<?php
namespace App\Middleware;

use App\Request;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求 ID 中间件：为每次请求注入唯一 REQUEST_ID（日志/审计关联用）
 */
class RequestIdMiddleware implements MiddlewareInterface
{
    /**
     * PSR-15 环节：注入/规范化请求 ID 后透传下游处理器。
     * @param \Psr\Http\Message\RequestInterface $request 传入请求（本管道实传 App\Request；实现签名未加类型，接口有声明）
     * @param RequestHandlerInterface            $handler 下一个处理器
     * @return mixed $handler->handle() 的管道结果（true=放行 / false=拦截，见 MiddlewareInterface 约定）
     */
    public function process($request, RequestHandlerInterface $handler)
    {
        // 安全加固（security-review）：上游 REQUEST_ID 仅接受合法 hex 且长度
        // 8-64 的值（白名单），否则随机生成——防部署层把客户端可控值映射进该变量后
        // 污染 X-Request-Id 响应头与日志审计链
        $incoming = isset($_SERVER['REQUEST_ID']) && is_string($_SERVER['REQUEST_ID'])
            ? $_SERVER['REQUEST_ID']
            : '';
        $requestId = preg_match('#^[a-f0-9]{8,64}$#i', $incoming)
            ? strtolower($incoming)
            : strtolower(bin2hex(random_bytes(8)));
        $_SERVER['REQUEST_ID'] = $requestId;
        if (function_exists('header')) {
            @header('X-Request-Id: ' . $requestId);
        }
        return $handler->handle($request);
    }
}
