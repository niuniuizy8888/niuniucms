<?php
namespace App\Service\System;

/**
 * 中间件管道服务
 * 负责按顺序执行注册的中间件
 */
class MiddlewarePipelineService
{
    /**
     * 执行中间件管道
     * 
     * @return bool true=通过；false=已被拦截
     */
    public static function run(): bool
    {
        // 0. 请求 ID 注入（日志/审计关联）
        self::runRequestId();

        // 1. XSS 过滤中间件
        if (!self::runXssFilter()) {
            return false;
        }
        
        // 2. 限流中间件
        if (!self::runThrottle()) {
            return false;
        }
        
        return true;
    }

    /**
     * 注入请求 ID（REQUEST_ID + X-Request-Id 响应头）
     */
    private static function runRequestId(): void
    {
        try {
            $middleware = new \App\Middleware\RequestIdMiddleware();
            $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
                /**
                 * 管道末端哨兵：RequestIdMiddleware 只做注入后透传，走到这里即视为放行；
                 * $request 不消费，恒返回 true（本管道以返回值而非响应对象表达放行与否）。
                 */
                public function handle(\Psr\Http\Message\RequestInterface $request) {
                    return true;
                }
            };
            $middleware->process(self::makeRequest(), $handler);
        } catch (\Throwable $e) {
            // 请求 ID 失败不阻断请求，仅记录日志（审计修复：logger 不存在，改走 xn_log）
            xn_log('RequestId failed: ' . $e->getMessage(), 'middleware_error');
        }
    }

    /**
     * 构造 PSR-7 请求对象（App\Request，适配超全局变量）。
     *
     * 修复前中间件管道传的是 `new \stdClass()` + 一个 `->route` 属性：
     * 那既不是 PSR-7 对象，也与 `MiddlewareInterface::process()` 的类型声明不符——
     * `core/app/Request.php` 于是成了「写了但零调用」的死代码。
     * 现统一构造真实请求对象，路由名放 request attribute（`$request->route` 亦保留兼容）。
     */
    private static function makeRequest(): \App\Request
    {
        $request = new \App\Request();
        $request->route = $_REQUEST[0] ?? 'index';
        return $request;
    }
    
    /**
     * 执行 XSS 过滤
     */
    private static function runXssFilter(): bool
    {
        $config = self::loadConfig('xss');
        if (empty($config['enable'])) {
            return true;
        }
        
        try {
            $middleware = new \App\Middleware\XssFilterMiddleware($config);

            // 虚拟处理器（管道末端的哨兵；本管道用返回值而非响应对象表达「是否放行」）
            $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
                /**
                 * 管道末端哨兵：XssFilterMiddleware 无短路语义（清洗后必透传），走到这里即放行；
                 * $request 不消费，恒返回 true。
                 */
                public function handle(\Psr\Http\Message\RequestInterface $request) {
                    return true;
                }
            };

            $middleware->process(self::makeRequest(), $handler);
            return true;
        } catch (\Throwable $e) {
            // XSS 过滤失败不阻断请求，仅记录日志（审计修复：logger 不存在，改走 xn_log）
            xn_log('XSS filter failed: ' . $e->getMessage(), 'middleware_error');
            return true;
        }
    }
    
    /**
     * 执行限流中间件
     */
    private static function runThrottle(): bool
    {
        $config = self::loadConfig('throttle');
        if (empty($config['enable'])) {
            return true;
        }
        
        try {
            $middleware = new \App\Middleware\ThrottleMiddleware($config);

            // 虚拟处理器（管道末端的哨兵）
            $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
                /**
                 * 管道末端哨兵：ThrottleMiddleware 拦截时不走到这里（直接回 false），
                 * 被调用即代表限流放行；$request 不消费，恒返回 true——上层据此区分放行/拦截。
                 */
                public function handle(\Psr\Http\Message\RequestInterface $request) {
                    return true;
                }
            };

            $result = $middleware->process(self::makeRequest(), $handler);
            
            // 如果返回 false，说明被限流拦截
            if ($result === false) {
                return false;
            }
            
            return true;
        } catch (\Throwable $e) {
            // 限流失败不阻断请求，仅记录日志（审计修复：logger 不存在，改走 xn_log）
            xn_log('Throttle failed: ' . $e->getMessage(), 'middleware_error');
            return true;
        }
    }
    
    /**
     * 加载配置文件
     */
    private static function loadConfig(string $name): array
    {
        $file = APP_PATH . 'core/config/' . $name . '.php';
        if (!is_file($file)) {
            return [];
        }
        
        $config = include $file;
        return is_array($config) ? $config : [];
    }
}
