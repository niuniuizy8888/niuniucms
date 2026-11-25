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
        $url = trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', (string)$url));
        if ('' === $url) return false;
        $httpsNow = function_exists('http_is_https')
            ? http_is_https()
            : (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
        // 浏览器会将 /\host、\\host 与 //host 同样视为协议相对外链
        if (preg_match('#^[/\\\\]{2}#', $url)) {
            $url = ($httpsNow ? 'https:' : 'http:') . '//' . ltrim(str_replace('\\', '/', $url), '/');
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http', 'https'), true)) return false;
        $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));
        if ('' === $host) return false;
        $targetPort = parse_url($url, PHP_URL_PORT);
        $targetPort = $targetPort !== null ? (int)$targetPort : ($scheme === 'https' ? 443 : 80);

        $selfRaw = (string)_SERVER('HTTP_HOST');
        if ('' === $selfRaw) return true;
        $selfParsed = @parse_url(($httpsNow ? 'https://' : 'http://') . $selfRaw);
        $selfHost = is_array($selfParsed) ? strtolower(trim((string)($selfParsed['host'] ?? ''), '[]')) : '';
        $selfPort = (is_array($selfParsed) && isset($selfParsed['port'])) ? (int)$selfParsed['port'] : ($httpsNow ? 443 : 80);
        if ('' === $selfHost) return true;
        return $host !== $selfHost || $targetPort !== $selfPort;
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
            . '<p style="margin:0;"><a href="' . $safe_url . '" rel="nofollow noopener noreferrer" target="_blank" style="margin-right:12px;">继续前往</a>'
            . '<a href="javascript:history.back();">返回上一页</a>（' . $delay . ' 秒后自动跳转）</p>'
            . '</div>' . $auto;
        return $safe_msg . $html;
    }
}
