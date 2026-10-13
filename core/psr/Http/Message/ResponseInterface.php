<?php
/**
 * PSR-7 HTTP Message Interface (精简版)
 * 遵循 https://www.php-fig.org/psr/psr-7/
 */

namespace Psr\Http\Message;

interface ResponseInterface
{
    /** @return int HTTP 状态码 */
    public function getStatusCode(): int;
    /**
     * @return static
     */
    public function withStatus(int $code, string $reasonPhrase = '');
    /** @return array<string, array<int, string>> 全部响应头（键小写，值为列表） */
    public function getHeaders(): array;
    /** @return array 指定头的值列表；头名大小写不敏感，未设置返回空数组 */
    public function getHeader(string $name): array;
    /**
     * @return static
     */
    public function withHeader(string $name, $value);
    /** @return string 响应体字符串快照 */
    public function getBody(): string;
    /**
     * @return static
     */
    public function withBody(string $body);
}
