<?php
/**
 * PSR-7 Request 实现（适配现有 $_SERVER / $_GET / $_POST 超全局变量）
 * 作为渐进式迁移基础，不破坏现有代码
 */

namespace App;



class Request implements \Psr\Http\Message\RequestInterface
{
    /**
     * @var string 命中的前台路由名（$_REQUEST[0]，如 index/vod/play）。
     * 声明为真实属性而非动态属性——PHP 8.2+ 对未声明属性赋值发 Deprecated，
     * 而中间件管道会写入它（见 MiddlewarePipelineService::makeRequest()）。
     */
    public $route = '';
    /** @var string */
    private $method;
    /** @var string */
    private $uri;
    /** @var array */
    private $headers;
    /** @var string */
    private $body;
    /** @var array */
    private $queryParams;
    /** @var array|null */
    private $parsedBody;
    /** @var array */
    private $serverParams;
    /** @var array */
    private $cookieParams;
    /** @var array */
    private $uploadedFiles;
    /** @var array */
    private $attributes = [];

    public function __construct()
    {
        $this->serverParams = $_SERVER;
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->queryParams = $_GET;
        $this->parsedBody = $_POST ?: null;
        $this->cookieParams = $_COOKIE;
        $this->uploadedFiles = $_FILES;
        $this->body = file_get_contents('php://input');
        $this->headers = $this->extractHeaders();
    }

    /**
     * 从 $_SERVER 提取请求头：仅收 HTTP_* 前缀键，名转小写、下划线转连字符，
     * 值包成 1 元素列表（对齐 PSR-7 getHeader 的多值形状）。
     * 精简版限制：无 HTTP_ 前缀的 CONTENT_TYPE/CONTENT_LENGTH 等键不在采集范围。
     * @return array<string, array<int, string>> 头名 => 值列表
     */
    private function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = [$v];
            }
        }
        return $headers;
    }

    /** @return string 请求方法（构造时取 $_SERVER['REQUEST_METHOD'] 并 strtoupper） */
    public function getMethod(): string { return $this->method; }
    /** @return string 命中的前台路由名（$_REQUEST[0]） */
    public function getRoute(): string { return (string)$this->route; }
    /** @return string 原始 REQUEST_URI 字符串（精简版差异：非 PSR-7 的 UriInterface 对象） */
    public function getUri(): string { return $this->uri; }
    /** @return array<string, array<int, string>> 全部请求头，键为小写头名、值为列表 */
    public function getHeaders(): array { return $this->headers; }
    /** @return array 指定头的值列表（头名大小写不敏感；未采集到返回空数组） */
    public function getHeader(string $name): array { return $this->headers[strtolower($name)] ?? []; }
    /** @return string 原始请求体（构造时 php://input 读入的字符串快照） */
    public function getBody(): string { return $this->body; }
    /** @return array 查询参数（构造时的 $_GET 快照） */
    public function getQueryParams(): array { return $this->queryParams; }
    /** @return array|null 表单解析体（$_POST 快照；$_POST 为空时为 null，非空数组） */
    public function getParsedBody(): ?array { return $this->parsedBody; }
    /** @return array $_SERVER 构造时刻的快照（后续容器 App::set 写透不回灌本对象，§二.5） */
    public function getServerParams(): array { return $this->serverParams; }
    /** @return array Cookie 参数（构造时的 $_COOKIE 快照） */
    public function getCookieParams(): array { return $this->cookieParams; }
    /** @return array 上传文件（$_FILES 原生嵌套数组形状；精简版差异：非 UploadedFileInterface 对象） */
    public function getUploadedFiles(): array { return $this->uploadedFiles; }

    /**
     * 不可变式修改方法名：clone 副本后 strtoupper 写入（本对象不变）。
     * @return static
     */
    public function withMethod(string $method)
    {
        $new = clone $this;
        $new->method = strtoupper($method);
        return $new;
    }

    /**
     * 不可变式修改 URI：clone 副本后写入（本对象不变）。
     * @param string $uri 新的 REQUEST_URI 字符串
     * @return static
     */
    public function withUri(string $uri)
    {
        $new = clone $this;
        $new->uri = $uri;
        return $new;
    }

    /**
     * 精简版差异实现：clone 副本仅为保持接口形状，真实副作用是直接
     * http_response_code($code) 修改全局响应状态；$reasonPhrase 被忽略（不存储）。
     * Request 对象上带响应侧行为是本项目裁剪 PSR-7 的既定形态。
     * @param int $code HTTP 状态码
     * @param string $reasonPhrase 未使用
     * @return static
     */
    public function withStatus(int $code, string $reasonPhrase = '')
    {
        $new = clone $this;
        http_response_code($code);
        return $new;
    }

    /**
     * @return int 当前响应状态码——直读全局 http_response_code()（非对象内属性，与 withStatus 同源）。
     * 边界：CLI SAPI 且未显式设置时该函数返回 false（实测），与本方法 int 返回声明冲突会抛 TypeError；
     * Web（FPM）路径正常返回 int。现行中间件管道未调用本方法。
     */
    public function getStatusCode(): int { return http_response_code(); }

    /**
     * 不可变式设置请求头，但**非纯**：clone 记录的同时立即 header() 真发响应头
     * （精简版兼容形态；头名在对象内以小写键存储，发送用原名）。
     * @param string $name 头名
     * @param string|array $value 值（标量或列表，内部 (array) 归一后逗号拼接发送）
     * @return static
     */
    public function withHeader(string $name, $value)
    {
        $new = clone $this;
        $new->headers[strtolower($name)] = (array)$value;
        header("$name: " . implode(', ', (array)$value));
        return $new;
    }

    /**
     * 不可变式替换请求体字符串：clone 副本后写入（本对象不变）。
     * @param string $body 新的原始体
     * @return static
     */
    public function withBody(string $body)
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    /**
     * 不可变式替换 Cookie 集合：clone 副本后写入（本对象与 $_COOKIE 均不受影响）。
     * @param array $cookies 键值对
     * @return static
     */
    public function withCookieParams(array $cookies)
    {
        $new = clone $this;
        $new->cookieParams = $cookies;
        return $new;
    }

    /**
     * 不可变式替换上传文件集合：clone 副本后写入（$_FILES 不受影响）。
     * @param array $files $_FILES 形状的嵌套数组
     * @return static
     */
    public function withUploadedFiles(array $files)
    {
        $new = clone $this;
        $new->uploadedFiles = $files;
        return $new;
    }
}
