<?php
/**
 * IP 地址处理（ip/safe_long2ip/ip2long_v6/long2ip_v6）
 * 从 core/func/misc.php 拆分而来，由 core/core.php 在 misc.php 后加载
 */
function ip()
{
    $conf = _SERVER('conf');
    if (empty($conf['cdn_on'])) {
        $ip = _SERVER('REMOTE_ADDR');
    } else {
        // 仅当直连 IP 属于受信代理白名单（cdn_trusted_ips）时才采信 CDN 转发头，
        // 否则回退 REMOTE_ADDR，防伪造 X-Forwarded-For 绕过登录限流/风控（审计修复）
        $remote = (string)_SERVER('REMOTE_ADDR');
        $trusted = array();
        if (!empty($conf['cdn_trusted_ips'])) {
            $raw = $conf['cdn_trusted_ips'];
            $trusted = array_filter(array_map('trim', is_array($raw) ? $raw : explode(',', (string)$raw)));
        }
        if (empty($trusted) || !in_array($remote, $trusted, TRUE)) {
            $ip = $remote;
        } elseif (isset($_SERVER['HTTP_CDN_SRC_IP'])) {
            $ip = $_SERVER['HTTP_CDN_SRC_IP'];
        } elseif (isset($_SERVER['HTTP_CLIENTIP'])) {
            $ip = $_SERVER['HTTP_CLIENTIP'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            $arr = array_filter(explode(',', $ip));
            $ip = trim(reset($arr));
        } else {
            $ip = _SERVER('REMOTE_ADDR');
        }
    }
    return $ip;
}
/**
 * 把库里存的「IP 长整型」还原成可显示的 IP 串，IPv4 / IPv6 通吃（原注释「转IP格式，支持IPV4 IPV6」）。
 *
 * 三个分支：
 *  1) **非数值**入参（历史脏数据、或调用方误传了点分十进制原文）：不报错，直接 htmlspecialchars
 *     （ENT_QUOTES）原样回显——所以本函数可以安全地用在「值可能是串」的展示位上，
 *     但它**不是转义函数**，数值分支的返回串没经过转义（点分/冒号形态本身无 HTML 元字符）。
 *  2) **位数大于 10**（IPv4 无符号长整型最多 4294967295，正好 10 位）⇒ 判为 IPv6 的大整数，转 long2ip_v6()。
 *  3) 其余按 IPv4：`long2ip(-(4294967295 - (值 - 1)))` 是「无符号 32 位 → PHP 有符号 int」的补码换算，
 *     与写入侧 bull_longip()（ip2long，32 位 OS 下负值再 sprintf 成无符号串）互为逆运算。
 *
 * 数据口径（取证：SessionService 在线列表的 ip_fmt、UserService 的 create_ip_fmt/login_ip_fmt）：
 * 列里存的是 bull_longip() 的结果，**IPv6 访客存的是 0**（bull_longip 刻意避开需要 GMP/BCMATH 的换算），
 * 因此 0 还原成 0.0.0.0、而不是真实 IPv6；显示 IPv6 只能靠别处的原始串。
 * @param string|int|float $longip 数据库里的 IP 长整型（取证：可能是 int，也可能是 char 列取出的数字串，
 *                                 以及缺失时调用方兜底传的 0；见 SessionService 的 `$arr['ip'] ?? 0`）
 * @return string 点分十进制 IPv4、冒号分段的 IPv6，或非数值入参的转义回显
 */
function safe_long2ip($longip)
{
    if (!is_numeric($longip)) return htmlspecialchars($longip, ENT_QUOTES);
    // IPV6
    if (strlen($longip) > 10) return long2ip_v6($longip);
    $longip = intval(4294967295 - ($longip - 1));
    return long2ip(-$longip);
    // IPV4转换32位
    /*$str = sprintf("%032s", decbin((float)$longip));
    $arr = array();
    for ($i = 0; $i < 4; ++$i) {
        $arr[] = bindec(substr($str, $i * 8, 8));
    }
    return implode('.', $arr);*/
}
/**
 * 把「IPv6 的大整数形态」还原成标准 IPv6 文本串（原注释写的两条：「IPV6 转数字 / 转 IPV6」，
 * 按代码实际方向只有后者成立——本函数是**数字转 IPv6**，IPv6 转数字（ip2long_v6）在全仓并无实现，
 * 文件头注释里的该名字属失效引用，需要时另补，勿据此以为库里能存 IPv6 大整数）。
 *
 * 做法：十进制大整数 → 二进制串（优先 GMP，其次 BCMath 逐位取模，两者都用 bignum，避开 64 位溢出）
 * → 左补零到 128 位 → 每 16 位一组 dechex → 冒号连接 → 交给 inet_pton + inet_ntop 做**规范化**
 * （顺带把前导零与连续零段压成标准形态）。
 * 失败面：两个扩展都没装时 trigger_error(E_USER_ERROR) —— 这是**致命错误**，页面直接中断
 * （DEBUG=0 时 error_handle() 返回 FALSE 走 PHP 内部处理），不是返回 FALSE，调用方别指望降级；
 * 入参超过 128 位（非法量级）时补零不生效， inet_pton 会解不出而返回 FALSE，最终在 inet_ntop 处报错。
 * 当前仓库里唯一的入口是 safe_long2ip() 的「位数大于 10」分支，而 bull_longip() 对 IPv6 一律写 0，
 * 所以这条链在现网数据形态下几乎不会被触发（保留是给 IPv6 落库改造用的）。
 * @param string|int $dec IPv6 的十进制大整数表示（取证：来自 safe_long2ip() 透传的数据库数字值，
 *                        BCMath 分支要求是可参与 bcmath 运算的数字串）
 * @return string 规范化后的 IPv6 文本（如 2001:db8::1）
 */
function long2ip_v6($dec)
{
    if (function_exists('gmp_init')) {
        $bin = gmp_strval(gmp_init($dec, 10), 2);
    } elseif (function_exists('bcadd')) {
        $bin = '';
        do {
            $bin = bcmod($dec, '2') . $bin;
            $dec = bcdiv($dec, '2', 0);
        } while (bccomp($dec, '0'));
    } else {
        trigger_error('GMP or BCMATH extension not installed!', E_USER_ERROR);
    }
    $bin = str_pad($bin, 128, '0', STR_PAD_LEFT);
    $ip = array();
    for ($bit = 0; $bit <= 7; $bit++) {
        $bin_part = substr($bin, $bit * 16, 16);
        $ip[] = dechex(bindec($bin_part));
    }
    $ip = implode(':', $ip);
    return inet_ntop(inet_pton($ip));
}
