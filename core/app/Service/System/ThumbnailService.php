<?php
namespace App\Service\System;

/**
 * 远程海报本地缩略图（R7-P09）
 *
 * 背景：首页/列表 227 张远程海报来自第三方图床，客户端多域连接 + 无尺寸约束。
 * 设计（灰度安全、无运行时阻塞）：
 *   - url()      ：渲染期只查本地是否已有缩略图，有则返回本地 URL，无则**原样回退远程 URL**
 *                  （绝不在渲染路径出网，未预热时行为与改造前逐字节一致）。
 *   - generate() ：批量/后台调用——outbound_url_safe 统一 SSRF 网关 + finfo MIME 校验 +
 *                  体积上限 + GD 缩放，落 upload/thumb/<aa>/<sha1>.jpg；任何失败返回 null。
 * 缩略图键含源 URL 与目标宽度；同一源不同宽度各自缓存。
 */
class ThumbnailService
{
    const REL_DIR = 'upload/thumb/';
    const MAX_BYTES = 3145728;   // 源图体积上限 3MB
    const MAX_WIDTH = 300;       // 缩略最大宽度
    const JPEG_QUALITY = 82;

    /** 渲染期：本地有缩略图用本地，否则回退原图（远程）；相对/站内地址原样返回 */
    public static function url(string $src, int $w = self::MAX_WIDTH): string
    {
        $src = trim($src);
        if ($src === '' || 0 !== stripos($src, 'http')) return $src;
        $rel = self::cacheRel($src, $w);
        return is_file(\APP_PATH . $rel) ? self::publicUrl($rel) : $src;
    }

    /** 生成缩略图；成功返回相对路径（upload/thumb/...），失败返回 null（fail-safe） */
    public static function generate(string $src, int $w = self::MAX_WIDTH): ?string
    {
        $src = trim($src);
        if ($src === '' || 0 !== stripos($src, 'http')) return null;
        $w = max(60, min(intval($w), 600));
        $rel = self::cacheRel($src, $w);
        $dest = \APP_PATH . $rel;
        if (is_file($dest) && @filesize($dest) > 0) return $rel;

        // 统一出站 SSRF 网关（scheme + 全记录 DNS + 内网段 + IP 固定）；不通过直接放弃
        if (!function_exists('outbound_url_safe') || !function_exists('https_request')) return null;
        if (!outbound_url_safe($src)) return null;
        $data = @https_request($src, '', '', 8, 0, 8 * 1048576); // 批量场景 8s 足够（图床通常在 1s 内响应）
        if (!is_string($data) || $data === '' || strlen($data) > self::MAX_BYTES) return null;

        // 真实 MIME（fileinfo 必需；缺失 fail-closed）
        if (!function_exists('finfo_open')) return null;
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string)finfo_buffer($fi, $data) : '';
        $fi and (PHP_VERSION_ID < 80500) and finfo_close($fi);
        if (strpos($mime, 'image/') !== 0) return null;

        // GD 解码 + 缩放（imagescale 优先，回退 imagecopyresampled）
        if (!function_exists('imagecreatefromstring')) return null;
        $img = @imagecreatefromstring($data);
        if (!$img) return null;
        $ow = imagesx($img); $oh = imagesy($img);
        if ($ow < 1 || $oh < 1) { imagedestroy($img); return null; }
        if ($ow > $w) {
            $nh = max(1, (int)round($oh * $w / $ow));
            $scaled = function_exists('imagescale') ? @imagescale($img, $w, $nh) : null;
            if (!$scaled) {
                $scaled = imagecreatetruecolor($w, $nh);
                if ($scaled) imagecopyresampled($scaled, $img, 0, 0, 0, 0, $w, $nh, $ow, $oh);
            }
            if ($scaled) { imagedestroy($img); $img = $scaled; }
        }
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { imagedestroy($img); return null; }
        $ok = @imagejpeg($img, $dest, self::JPEG_QUALITY);
        imagedestroy($img);
        return $ok ? $rel : null;
    }

    /**
     * 缩略图落盘相对路径的纯函数映射：sha1("源URL|宽度") → upload/thumb/<哈希前2位>/<哈希>.jpg。
     * 源 URL 或宽度任一变化即换键（同源不同宽各自独立缓存）；两级目录摊平文件数。
     * 不判存在性、不出网——存在性检查在 url()/generate()。
     * @param string $src 远程源图 URL（调用方已保证非空且 http 前缀）
     * @param int $w 目标宽度（内部 intval 参与哈希）
     * @return string 相对 APP_PATH 的路径，签名已声明 string
     */
    private static function cacheRel(string $src, int $w): string
    {
        $h = sha1($src . '|' . intval($w));
        return self::REL_DIR . substr($h, 0, 2) . '/' . $h . '.jpg';
    }

    /**
     * 相对路径 → 浏览器可见 URL：剥离 rel 的 'upload/' 段后接 conf['upload_url'] 基址
     * （配置缺失/非数组回落 '/upload/'）。基址可指向 CDN/独立域，但拼接不做 URL 校验——
     * 私有方法现仅 url() 一处调用且喂 cacheRel 产物，新增调用方勿传外部输入。
     * @param string $rel cacheRel() 产出的相对路径
     * @return string 签名已声明 string
     */
    private static function publicUrl(string $rel): string
    {
        $conf = function_exists('_SERVER') ? _SERVER('conf') : null;
        $base = is_array($conf) ? (string)($conf['upload_url'] ?? '/upload/') : '/upload/';
        $path = ltrim($rel, '/');
        if (str_starts_with($path, 'upload/')) $path = substr($path, 7);
        return rtrim($base, '/') . '/' . $path;
    }
}
