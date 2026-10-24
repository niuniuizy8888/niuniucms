<?php
// core/paths.php — U-2 可写运行时配置解析层（第 4 轮 U-2 plan B）
// 目的：让 core/ 可整包覆盖升级/只读部署而不丢站点配置。可写运行时配置迁至 APP_PATH/data/。
// 读解析器 nncms_runtime_path()：无副作用 —— data/ 不存在时回退 core/config，故仅部署本层代码即 no-op。
// 写解析器 nncms_runtime_path_w()：确保 data/ 可写；首次写且 data/<name> 缺失时，把旧位置文件复制到 data/
//   （自动迁移，保留旧文件以便回滚）。
// 载入前提：APP_PATH 已定义；未定义则直接返回（兜底，由调用方保证载入时序）。
if (!defined('APP_PATH')) {
    return;
}
if (!defined('NNCMS_DATA_DIR')) {
    define('NNCMS_DATA_DIR', APP_PATH . 'data/');
}
if (!function_exists('nncms_runtime_path')) {
    /**
     * 读解析器（无副作用）：优先 data/<name>；否则回退 core/config/<name>；
     * install.lock 额外回退旧位置 core/install.lock；都不存在时返回 data/<name> 作默认。
     * @param string $name 扁平文件名，如 config.php / domain.php / throttle.php / install.lock
     * @return string 绝对路径
     */
    function nncms_runtime_path($name)
    {
        $data = NNCMS_DATA_DIR . $name;
        if (is_file($data)) {
            return $data;
        }
        $legacy = APP_PATH . 'core/config/' . $name;
        if (is_file($legacy)) {
            return $legacy;
        }
        if ('install.lock' === $name) {
            $legacy2 = APP_PATH . 'core/install.lock';
            if (is_file($legacy2)) {
                return $legacy2;
            }
        }
        return $data;
    }
}
if (!function_exists('nncms_runtime_path_w')) {
    /**
     * 写解析器：确保 data/ 存在且可写（mkdir 0755）；data/<name> 缺失而旧位置存在时复制过来
     *   （首次写自动迁移，保留旧文件便于 rm -rf data/ 回滚）；返回 data/<name>。
     * data/ 不可建/不可写时回退读解析结果，不因新层引入新的失败面（与旧行为一致）。
     * @param string $name 扁平文件名
     * @return string 绝对路径
     */
    function nncms_runtime_path_w($name)
    {
        $dir = NNCMS_DATA_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // R3-3：建目录时写入 Apache 拒绝规则（与随包 data/.htaccess 同口径）
        if (is_dir($dir)) {
            $hta = $dir . '.htaccess';
            if (!is_file($hta)) {
                @file_put_contents($hta, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
                @chmod($hta, 0644);
            }
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            // R3-3：data/ 不可写时回退到可写的 core/config/，避免返回死路径 data/<name>
            $legacyDir = APP_PATH . 'core/config/';
            if (is_dir($legacyDir) && is_writable($legacyDir)) {
                return $legacyDir . $name;
            }
            return nncms_runtime_path($name);
        }
        $data = $dir . $name;
        if (!is_file($data)) {
            $legacy = APP_PATH . 'core/config/' . $name;
            if (is_file($legacy)) {
                @copy($legacy, $data);
            } elseif ('install.lock' === $name) {
                $legacy2 = APP_PATH . 'core/install.lock';
                if (is_file($legacy2)) {
                    @copy($legacy2, $data);
                }
            }
        }
        return $data;
    }
}
