<?php
/**
 * PSR-7 HTTP Message Interface (精简版)
 * 遵循 https://www.php-fig.org/psr/psr-7/
 */

namespace Psr\Http\Message;

interface ServerRequestInterface extends RequestInterface
{
    public function getServerParams(): array;
    public function getCookieParams(): array;
    /**
     * @return static
     */
    public function withCookieParams(array $cookies);
    public function getUploadedFiles(): array;
    /**
     * @return static
     */
    public function withUploadedFiles(array $files);
}
