<?php
/**
 * PHP 7.4+ 兼容 polyfill。
 *
 * 说明：
 * - PHP 8+ 已内建 str_contains/str_starts_with/str_ends_with，这里仅在缺失时补定义。
 * - 必须在核心函数与业务代码之前加载，保证 7.4 运行时不因字符串辅助函数缺失而 fatal。
 */

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        if ($needle === '') return true;
        return strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        if ($needle === '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        if ($needle === '') return true;
        $len = strlen($needle);
        if ($len > strlen($haystack)) return false;
        return substr($haystack, -$len) === $needle;
    }
}
