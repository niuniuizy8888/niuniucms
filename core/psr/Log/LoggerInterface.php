<?php
/**
 * PSR-3 Logger Interface（裁剪版）
 *
 * 说明：本项目不引入 composer，故按 PSR-3 规范裁剪实现本接口，仅保留规范定义的
 * 8 个级别方法与 `log()`。`LoggerService` implements 本接口，使「日志门面符合 PSR-3」
 * 这一说法有实际依据（此前只有形似的方法名，未实现任何接口）。
 *
 * 与规范的差异（有意裁剪，已在方法签名上保持兼容）：
 *  - `$message` 未强制 `string|Stringable`（本项目 PHP 版本兼容优先，允许传标量）；
 *  - `$context` 未做 `{placeholder}` 插值（本项目的结构化上下文直接以 JSON 附加到日志行尾，
 *    插值反而会破坏审计日志的可机读性）。
 */

namespace Psr\Log;

interface LoggerInterface
{
    /**
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function emergency($message, array $context = array());

    /** @return void */
    public function alert($message, array $context = array());

    /** @return void */
    public function critical($message, array $context = array());

    /** @return void */
    public function error($message, array $context = array());

    /** @return void */
    public function warning($message, array $context = array());

    /** @return void */
    public function notice($message, array $context = array());

    /** @return void */
    public function info($message, array $context = array());

    /** @return void */
    public function debug($message, array $context = array());

    /**
     * 任意级别
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function log($level, $message, array $context = array());
}
