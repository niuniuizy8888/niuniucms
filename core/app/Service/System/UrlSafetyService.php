<?php
namespace App\Service\System;

/**
 * URL 安全服务：外链识别与安全跳转确认（出站域名判定与跳转中间页）
 */
class UrlSafetyService
{
    /**
     * 判断 URL 是否为外部链接（http/https 且 host 非本站）
     * 相对路径、javascript:/data: 等非 http(s) 目标一律视为站内，不触发确认
     */
    public static function isExternal($url): bool
    {
        $url = trim((string)$url);
        if ('' === $url) return false;
        // 协议相对 //host/path：按请求协议补全判断
        if (str_starts_with($url, '//')) {
            $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) != 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            $url = ($https ? 'https:' : 'http:') . $url;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http', 'https'), true)) return false;
        $host = (string)parse_url($url, PHP_URL_HOST);
        $self = (string)_SERVER('HTTP_HOST');
        if ('' === $host) return false;
        return '' === $self || strcasecmp($host, $self) !== 0;
    }

    /**
     * 渲染外链确认块：展示目标 URL + 5 秒自动跳转 + 前往/返回
     * 供 jump() 在目标为外部 URL 时使用，防止系统跳转直接离站（防钓鱼）
     */
    public static function renderJumpConfirm($message, $url, $delay = 5): string
    {
        $safe_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safe_msg = htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
        $delay = max(1, min(30, intval($delay)));
        $auto = '<script>setTimeout(function() {window.location=' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '}, ' . ($delay * 1000) . ');</script>';
        $html = '<div class="jump-external" style="padding:12px 0;word-break:break-all;">'
            . '<p style="margin:0 0 8px;color:#856404;">即将离开本站，前往外部链接：</p>'
            . '<p style="margin:0 0 12px;font-size:13px;color:#666;">' . $safe_url . '</p>'
            . '<p style="margin:0;"><a href="' . $safe_url . '" rel="nofollow noopener" target="_blank" style="margin-right:12px;">继续前往</a>'
            . '<a href="javascript:history.back();">返回上一页</a>（' . $delay . ' 秒后自动跳转）</p>'
            . '</div>' . $auto;
        return $safe_msg . $html;
    }
}
