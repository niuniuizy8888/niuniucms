<?php
namespace App\Service\System;

/**
 * 存储抽象服务（上传落盘、扩展名白名单与真实 MIME 校验）
 * 本地存储：upload/{subdir}/{Ym}/{rand}.{ext}
 * 上传校验：错误码/大小/扩展名白名单；扩展点：后续可加云驱动
 */
class StorageService
{
    const ALLOWED_EXT = array('jpg', 'jpeg', 'png', 'gif', 'webp');
    const MAX_PIXELS = 25000000;
    const MAX_DIM = 10000;
    const ALLOWED_IMAGETYPES = array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP);

    /**
     * 校验真实图片头与像素上限（不依赖 fileinfo，同时拦截非图/SVG/像素炸弹）
     */
    private static function isValidImageFile(string $path): bool
    {
        $info = @getimagesize($path);
        if (!is_array($info) || empty($info[0]) || empty($info[1]) || empty($info[2])) {
            return false;
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w < 1 || $h < 1 || $w > self::MAX_DIM || $h > self::MAX_DIM || ((float)$w * (float)$h) > self::MAX_PIXELS) {
            return false;
        }
        return in_array((int)$info[2], self::ALLOWED_IMAGETYPES, true);
    }

    private static function isValidImageBinary(string $binary): bool
    {
        $info = @getimagesizefromstring($binary);
        if (!is_array($info) || empty($info[0]) || empty($info[1]) || empty($info[2])) {
            return false;
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w < 1 || $h < 1 || $w > self::MAX_DIM || $h > self::MAX_DIM || ((float)$w * (float)$h) > self::MAX_PIXELS) {
            return false;
        }
        return in_array((int)$info[2], self::ALLOWED_IMAGETYPES, true);
    }

    /**
     * 保存上传文件
     * @param array $file $_FILES 单项（tmp_name/name/error/size/type）
     * @param string $subdir 子目录（如 poster）
     * @param int $maxBytes 大小上限（默认 5MB）
     * @param bool $isUpload true=move_uploaded_file（HTTP 上传）；false=copy（内部文件入库/测试）
     * @param string $extOverride 文件名无后缀时允许调用方提供白名单扩展名
     * @return array|false ['path'=>相对路径,'url'=>访问URL]
     */
    public static function saveUploadedFile(array $file, string $subdir = '', int $maxBytes = 5242880, bool $isUpload = true, string $extOverride = '')
    {
        if (empty($file['tmp_name']) || (isset($file['error']) && UPLOAD_ERR_OK !== (int)$file['error'])) {
            return false;
        }
        if (!is_file($file['tmp_name'])) {
            return false;
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            return false;
        }
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext === '' && $extOverride !== '') {
            $ext = strtolower($extOverride);
        }
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return false;
        }
        // 真实 MIME 校验（防伪造扩展名）
        $mime = '';
        $probeReady = false;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $probeReady = true;
                $mime = (string)finfo_file($finfo, $file['tmp_name']);
                if (PHP_VERSION_ID < 80500) finfo_close($finfo);
            }
        } elseif (function_exists('mime_content_type')) {
            $probeReady = true;
            $mime = (string)mime_content_type($file['tmp_name']);
        }
        if ('' !== $mime) {
            if (0 !== strpos($mime, 'image/') || 0 === stripos($mime, 'image/svg')) {
                return false;
            }
        } elseif ($probeReady) {
            return false;
        } else {
            error_log('storage: mime probe unavailable (fileinfo missing), relying on getimagesize verification');
        }
        // 落盘前校验图片头与像素上限（独立于 fileinfo，且防止先落入 upload/ 再校验）
        if (!self::isValidImageFile((string)$file['tmp_name'])) {
            return false;
        }

        $time = intval(_SERVER('time')) ?: time();
        $dir = 'upload/' . ($subdir !== '' ? $subdir . '/' : '') . date('Ym', $time);
        $absDir = APP_PATH . $dir;
        !is_dir($absDir) and @mkdir($absDir, 0755, true);
        $name = date('ymdHis', $time) . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
        $dest = $absDir . '/' . $name;
        if ($isUpload) {
            if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $dest)) {
                return false;
            }
        } else {
            if (!copy($file['tmp_name'], $dest)) {
                return false;
            }
        }
        $path = $dir . '/' . $name;
        try {
            return AttachmentService::uploaded($path);
        } catch (\Throwable $e) {
            @unlink($dest);
            if (function_exists('xn_log')) xn_log('storage upload finalize failed: ' . $e->getMessage(), 'attachment_error');
            return false;
        }
    }

    /**
     * 保存二进制数据（base64 解码后写入）
     * @param string $binary 原始二进制数据
     * @param string $ext 文件扩展名（jpg/png/gif/webp）
     * @param string $subdir 子目录（如 ad）
     * @return array|false ['path'=>相对路径,'url'=>访问URL]
     */
    public static function saveBinary(string $binary, string $ext, string $subdir = '')
    {
        if (empty($binary) || strlen($binary) > 5242880) {
            return false;
        }
        $ext = strtolower($ext);
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return false;
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (string)finfo_buffer($finfo, $binary) : '';
            if ($finfo && PHP_VERSION_ID < 80500) finfo_close($finfo);
            if ('' === $mime || 0 !== strpos($mime, 'image/') || 0 === stripos($mime, 'image/svg')) {
                return false;
            }
        } else {
            error_log('storage: mime probe unavailable (fileinfo missing), relying on getimagesizefromstring verification');
        }
        if (!self::isValidImageBinary($binary)) {
            return false;
        }

        $time = intval(_SERVER('time')) ?: time();
        $dir = 'upload/' . ($subdir !== '' ? $subdir . '/' : '') . date('Ymd', $time);
        $absDir = APP_PATH . $dir;
        !is_dir($absDir) and @mkdir($absDir, 0755, true);
        $name = $time . '-' . xn_rand(8) . '.' . $ext;
        $dest = $absDir . '/' . $name;
        if (false === file_put_contents_try($dest, $binary)) {
            return false;
        }
        $path = $dir . '/' . $name;
        try {
            return AttachmentService::uploaded($path);
        } catch (\Throwable $e) {
            @unlink($dest);
            if (function_exists('xn_log')) xn_log('storage binary finalize failed: ' . $e->getMessage(), 'attachment_error');
            return false;
        }
    }

    /**
     * 删除存储文件（仅限 upload/ 目录内，防路径穿越）
     */
    public static function delete(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (0 !== strpos($path, 'upload/') || false !== strpos($path, '..') || false !== strpos($path, "\0")) {
            return false;
        }
        $uploadRoot = realpath(APP_PATH . 'upload');
        $real = realpath(APP_PATH . $path);
        if ($uploadRoot === false || $real === false) {
            return false;
        }
        $prefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (0 !== strpos($real, $prefix) || !is_file($real)) {
            return false;
        }
        return @unlink($real);
    }

    /**
     * 拼接访问 URL
     */
    public static function url(string $path): string
    {
        return AttachmentStorage::localUrl(ltrim($path, '/'));
    }
}
