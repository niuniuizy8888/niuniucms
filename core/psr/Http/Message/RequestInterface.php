<?php
/**
 * PSR-7 HTTP Message Interface (精简版)
 * 遵循 https://www.php-fig.org/psr/psr-7/
 */

namespace Psr\Http\Message;

interface RequestInterface
{
    /** 取 HTTP 请求方法。约定返回全大写形态（GET/POST/PUT/DELETE…），实现方负责归一大小写。@return string */
    public function getMethod(): string;
    /** 取请求 URI。⚠ 与 PSR-7 原文不同：本裁剪版返回**原始字符串**而非 UriInterface 对象（项目内没有 Uri 值对象），调用方要 scheme/host/query 分段请自行 parse_url()。@return string */
    public function getUri(): string;
    /** 取全部请求头。键为小写头名（HTTP_ 前缀剥离并下划线转连字符后统一小写），值为字符串数组——对齐 PSR-7 的多值形状，即使只有一个值也是 1 元素列表。@return array<string, array<int, string>> */
    public function getHeaders(): array;
    /** 取指定头的值列表，头名大小写不敏感（实现侧统一按小写查表）。缺失时返回空数组而**不是 null**，与 PSR-7 一致，故消费前判 empty() 而不是 === null。@param string $name 头名 @return array<int, string> 值列表 */
    public function getHeader(string $name): array;
    /** 取原始请求体。⚠ 与 PSR-7 不同：返回字符串而非 StreamInterface（实现方在构造时一次性读 php://input 存快照，重复调用拿到的是同一份内存字符串，不可 seek）。@return string */
    public function getBody(): string;
    /** 取 URL 查询参数（实现方构造时的 $_GET 快照）。注意项目主输入面是 param() 读的 $_REQUEST（且默认 htmlspecialchars），本方法给的是**未经 param() 转义**的原始 $_GET，两者不是同一份数据。@return array */
    public function getQueryParams(): array;
    /** 取解析后的请求体参数（实现方构造时的 $_POST 快照）。无 POST 数据时返回 null（不是空数组），消费前需判空。与 getBody() 互斥使用：一个是结构化参数、一个是原始字节。@return array|null */
    public function getParsedBody(): ?array;
    /**
     * @return static
     */
    public function withMethod(string $method);
    /**
     * @return static
     */
    public function withUri(string $uri);
}
