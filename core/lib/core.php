<?php

/**
 * 核心函数库
 *
 * 本文件包含活跃基础设施函数：
 * - crypto：AES 加解密（core_decrypt）
 * - bull_*：文本/文件/XSS 工具族
 * - URL：url_page / url_domain
 * - block 分页渲染已迁移至 core/func/pagination.php
 * - 其他：nn_message / block_load / fast_db_diff_labels
 *
 * @version
 */

// AES 密钥：优先读取 config.php。安装向导阶段尚未生成 config.php 属正常状态，
// 此时不要打 CRITICAL，避免在部分 FPM/面板环境里把 stderr 混入响应导致安装页异常空白。
$secret_value = '';
$config_file = defined('APP_PATH') ? APP_PATH . 'core/config/config.php' : '';
$install_lock_file = defined('APP_PATH') ? APP_PATH . 'core/install.lock' : '';
if ($config_file && is_file($config_file)) {
    $secret_conf = include $config_file;
    if (is_array($secret_conf)) {
        $secret_value = (string)($secret_conf['auth_key'] ?? '');
    }
}
defined('SECRET') or define('SECRET', $secret_value);
if (empty(SECRET)) {
    $is_installing = $config_file && !is_file($config_file) && $install_lock_file && !is_file($install_lock_file);
    if (!$is_installing) {
        error_log('[CRITICAL] SECRET not configured — missing auth_key in config/config.php');
    }
}

/**
 * base64 解码兼容函数（支持标准和 URL-safe）
 */
function _base64_flexible_decode(string $s)
{
    $s = trim($s);
    $s = str_replace(' ', '+', $s);

    $decoded = base64_decode($s, true);
    if ($decoded !== false) return $decoded;

    // URL-safe base64
    $s2 = str_replace(['-', '_'], ['+', '/'], $s);
    $pad = strlen($s2) % 4;
    if ($pad > 0) $s2 .= str_repeat('=', 4 - $pad);
    return base64_decode($s2, true);
}

/**
 * AES-256-CBC 解密（自动 PKCS#7 填充去除）
 */
function core_decrypt(string $encoded): string
{
    if ($encoded === '') return '';

    $ciphertext = _base64_flexible_decode($encoded);
    if ($ciphertext === false) return '';

    $iv  = str_repeat("\0", 16);

    // 先用当前密钥解密
    $key = hash('sha256', SECRET, true);
    $decrypted = @openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted !== false && $decrypted !== '') {
        // PKCS#7 padding removal
        $len = strlen($decrypted);
        if ($len > 0) {
            $pad = ord($decrypted[$len - 1]);
            if ($pad > 0 && $pad <= 16 && substr($decrypted, -$pad) === str_repeat(chr($pad), $pad)) {
                return substr($decrypted, 0, -$pad);
            }
        }
        return $decrypted;
    }

    // fallback: 用旧版硬编码密钥解密（兼容已加密的存量数据）
    // SECURITY: 此为旧版回退密钥，所有新数据使用 SECRET 常量加密。
    // TODO: 确认所有存量加密数据已迁移后移除此回退路径。
    /** @deprecated 仅用于兼容旧数据，禁止用于新加密 */
    $legacy_secret = '12codeikqu981212uyjOPdfqB73iuKHJ';
    if (SECRET !== $legacy_secret) {
        $old_key = hash('sha256', $legacy_secret, true);
        $decrypted = @openssl_decrypt($ciphertext, 'AES-256-CBC', $old_key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted !== false && $decrypted !== '') {
            $len = strlen($decrypted);
            if ($len > 0) {
                $pad = ord($decrypted[$len - 1]);
                if ($pad > 0 && $pad <= 16 && substr($decrypted, -$pad) === str_repeat(chr($pad), $pad)) {
                    return substr($decrypted, 0, -$pad);
                }
            }
            return $decrypted;
        }
    }

    return '';
}

/**
 * AES-256-CBC 加密（仅测试用）
 */


/**
 * get_url() — 解密后的 URL 执行 HTTP GET
 */
/**
 * @deprecated 已下沉 ApiExecutionService::httpGet，此函数仅做兼容委托
 */
/**
 * @deprecated 已下沉 ApiExecutionService::fetchVideoJson，此函数仅做兼容委托
 */
/**
 * 输出JSON消息（委托给 xn_message）
 * @param int $code 状态码
 * @param string $message 消息内容
 */
function nn_message($code, $message)
{
    // 上线前安全审计修复（F-14）：补 Content-Type 头 + JSON_HEX 四旗——
    // 此前 echo xn_json_encode 直出落 text/html，一旦未来文案掺入用户数据即成潜伏 XSS 面，
    // 且与 route/danmu.php / message() 的 JSON 口径三套并存。向统一口径收敛。
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('code' => $code, 'message' => $message), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}
// 删除 get_code()（**零调用方** · `cloud_api` 为唯一云端来源）。
// 原实现读 `conf['auth_salt']`（历史命名，实际是 URL）取密文、留空则回退**函数体内嵌的 ~4KB
// 加密密文**，再交 CloudOpsService 的 legacy 分支 core_decrypt。官方 niuniuzs/niuniucms 云端
// 已按裁定退出后，该函数既无调用方、又让出厂包带着一份指向已停用云端的密文，故整体移除。
// 云端数据现一律经 CloudOpsService：storeFetch() 取 {cloud_api}api.php（**不加密**）。
function bull_mkdirss($path, $mode = 0777)
{
    if (!is_dir(dirname($path))) {
        bull_mkdirss(dirname($path));
    }
    if (!file_exists($path)) {
        return mkdir($path, $mode);
    }
    return true;
}
/**
 * 采集字段分隔符归一：把 `/`、`，`、`|`、`、`、`,,`、`,,,` 统一替换为半角逗号
 * （$allow_space=FALSE 时单个空格也一并替换）——供 actor/director/writer 类多值字段拆分/入库前清洗。
 * ⚠ 纯文本替换、不做转义：入库前由调用方自行 htmlspecialchars（现网调用点均如此，
 *   如 ApiExecutionService 对 vod_actor/director/writer/remarks 的装配）。
 * @param string $str 原始值
 * @param bool $allow_space 是否保留空格（FALSE=空格也当分隔符）
 * @return string 归一后的字符串
 */
function bull_format_text($str, $allow_space = false)
{
    $finder = array('/', '，', '|', '、', ',,', ',,,');
    if ($allow_space === false) {
        $finder[] = ' ';
    }
    return str_replace($finder, ',', $str);
}

/**
 * 拼当前请求的站点根 URL「scheme://host」：$_SERVER['HTTPS'] 非空且 !== 'off' 判 https，否则 http；
 * host 直取 $_SERVER['HTTP_HOST']（未做白名单校验——依赖 nginx server_name 分发约束，站群多域下
 * 随请求域变化）。消费点如后台计划任务 URL 渲染（TaskManageService）。
 * ⚠ 含令牌/敏感拼接的 URL 慎用本函数结果：HTTP_HOST 是客户端可控头，Host 头投毒场景下产物不可信。
 * @return string 形如 https://example.com 的站点根（无末尾斜杠）
 */
function url_domain()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . $host;
}
// block_*_page 分页渲染函数已迁移至 core/func/pagination.php
