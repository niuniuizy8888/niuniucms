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

    /**
     * 保存上传文件
     * @param array $file $_FILES 单项（tmp_name/name/error/size/type）
     * @param string $subdir 子目录（如 poster）
     * @param int $maxBytes 大小上限（默认 5MB）
     * @param bool $isUpload true=move_uploaded_file（HTTP 上传）；false=copy（内部文件入库/测试）
     * @return array|false ['path'=>相对路径,'url'=>访问URL]
     */
    public static function saveUploadedFile(array $file, string $subdir = '', int $maxBytes = 5242880, bool $isUpload = true)
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
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return false;
        }
        // 真实 MIME 校验（防伪造扩展名）——发布前检查修复（B-3/B-4）：
        // 统一 finfo 主口径；探测函数在位但结果为空=文件异常，fail-closed 拒绝；
        // fileinfo 扩展缺失（本机 PHP 8.5 configure --disable-fileinfo）时降级为扩展名白名单并留痕
        //（通道含 error 恒落盘），部署侧安装 fileinfo 后自动恢复强校验。
        $mime = '';
        $probeReady = false;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $probeReady = true;
                $mime = (string)finfo_file($finfo, $file['tmp_name']);
                if (PHP_VERSION_ID < 80500) finfo_close($finfo);   // 8.5 起弃用（8.1+ 自动释放）
            }
        } elseif (function_exists('mime_content_type')) {
            $probeReady = true;
            $mime = (string)mime_content_type($file['tmp_name']);
        }
        if ('' !== $mime) {
            // 上线前审计修复（VULN-L09）：改为**前缀锚定**。
            // 原 `false === strpos($mime, 'image/')` 只判「任意位置含 image/」，
            // 形如 application/x-image/foo 或 text/image/svg 也会被放行。
            // 上线前终检（N-04，Low）：`image/` 前缀锚定挡不住 `image/svg+xml`（SVG 内可嵌脚本），
            // 而本站上传白名单不含 svg ⇒ 显式拒绝该类型（纵深：即使扩展名被绕过也不接受其内容）。
            if (0 !== strpos($mime, 'image/') || 0 === stripos($mime, 'image/svg')) {
                return false;
            }
        } elseif ($probeReady) {
            return false;
        } else {
            // error_log 口径与 Service 层诊断惯例一致（CloudOps/TemplateService 同款），零框架依赖
            error_log('storage: mime probe unavailable (fileinfo missing), degraded to extension whitelist');
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
        return array('path' => $path, 'url' => self::url($path));
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
        // 真实 MIME 校验——发布前检查修复（B-3）：fclose→finfo_close（PHP≥8.1 finfo_open 返回
        // Finfo 对象，fclose 传对象必抛 TypeError=装上 fileinfo 后此链 100% 致命的潜伏缺陷）；
        //（B-4）探测在位但结果为空=fail-closed 拒绝；扩展缺失时降级并留痕。
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (string)finfo_buffer($finfo, $binary) : '';
            (PHP_VERSION_ID < 80500) and finfo_close($finfo);
            // 上线前审计修复（VULN-L09）：前缀锚定（同上）
            if ('' === $mime || 0 !== strpos($mime, 'image/') || 0 === stripos($mime, 'image/svg')) {
                return false;
            }
        } else {
            // error_log 口径与 Service 层诊断惯例一致（CloudOps/TemplateService 同款），零框架依赖
            error_log('storage: mime probe unavailable (fileinfo missing), degraded to extension whitelist');
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
        return array('path' => $path, 'url' => self::url($path));
    }

    /**
     * 删除存储文件（仅限 upload/ 目录内，防路径穿越）
     */
    public static function delete(string $path): bool
    {
        $path = ltrim($path, '/');
        if (0 !== strpos($path, 'upload/')) {
            return false;
        }
        if (false !== strpos($path, '..')) {
            return false;
        }
        $abs = APP_PATH . $path;
        return is_file($abs) && @unlink($abs);
    }

    /**
     * 拼接访问 URL
     */
    public static function url(string $path): string
    {
        $conf = _SERVER('conf');
        $base = (string)($conf['upload_url'] ?? 'upload/');
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'upload/')) {
            $path = substr($path, 7);
        }
        return rtrim($base, '/') . '/' . $path;
    }
}
