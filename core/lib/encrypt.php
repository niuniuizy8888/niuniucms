<?php

/**
 * 取全站加解密的根密钥 conf['auth_key']（xn_encrypt/xn_decrypt 留空 $key 时的默认值，
 * 也是各类令牌派生的种子：指纹环密钥 md5(xn_key() . salt)、免登录 token 的 md5(xn_key())、
 * 后台 admin_token 的 key 拼接、api_wire_token() 的派生原币都从这里取值）。
 * **消费侧口径**：原注释写的「优先从扩展中获取」已上移到 core/core.php 的引导里——
 * 那里若存在外部函数 auth_key() 就优先采用，否则 auth_key 缺失时回退生成一个临时值；
 * 本函数只负责从容器视图读结果，读不到就是**空串**。
 * ⚠ 空串是有意义的失败信号（不是「随便拿个默认 key」）：
 *  - api_wire_token() 见 auth_key 为空直接返回空 token，api.php 随即不放行（fail-closed）；
 *  - 但走 xn_encrypt/xn_decrypt 的链路会拿着空 key 派生密钥 ⇒ 任何人可复算，属**危险降级面**，
 *    所以缺 key 的站必须由安装器写入 xn_rand(64)（审计 S-3 的弱熵每请求重生成回退
 *    会造成免登录 token / 指纹环 / admin_token 请求间漂移 = 周期性全员掉登录，不要依赖它跑生产）。
 * 轮换 auth_key 即同时吊销全部派生 token 与密文（旧密文解不开）。
 * ⚠ 返回值未经类型收敛：config 里存的是什么就返回什么（字符串），故按 string 对待即可，勿传数组。
 * @return string 站点 auth_key；配置缺失时为空串 ''
 */
function xn_key() {
	$conf = _SERVER('conf');
	return isset($conf['auth_key']) ? $conf['auth_key'] : '';
}

// 安全的加密 key，过期时间 100 秒

/**
 * AES-256-GCM 加密 — 提供机密性 + 完整性认证
 * 输出格式: base64_urlencode( iv(12字节) + ciphertext + tag(16字节) )
 * 向后兼容: xn_decrypt() 会自动检测旧版 XXTEA 格式
 */
function xn_encrypt($txt, $key = '') {
	empty($key) AND $key = xn_key();
	// 派生 256 位密钥（SHA-256 确保长度和熵）
	$enc_key = hash('sha256', $key, true);
	// 12 字节 IV (GCM 推荐)
	$iv = openssl_random_pseudo_bytes(12);
	$tag = '';
	$ciphertext = @openssl_encrypt($txt, 'aes-256-gcm', $enc_key, OPENSSL_RAW_DATA, $iv, $tag);
	if ($ciphertext === false) {
		// S-5（2026-10-10 审计修复）：加密失败不再静默降级 XXTEA（无认证加密，可篡改/重放）。
		// fail-closed 抛错——出现本异常即 openssl 扩展缺失/构建异常，应修环境而不是降级。
		// 旧版 XXTEA 密文的**解密**兼容路径仍保留（见 xn_decrypt，2026-12-31 迁移截止后删除）。
		throw new \RuntimeException('xn_encrypt: openssl_encrypt(aes-256-gcm) failed, refusing to downgrade to XXTEA');
	}
	// 格式: 前缀 \x01 + iv(12) + ciphertext + tag(16)
	$result = "\x01" . $iv . $ciphertext . $tag;
	return base64_encode($result);
}

/**
 * 解密入口（xn_encrypt 的逆）：base64_urlencode 兼容还原（空格回 +）后按首字节分流——
 * \x01 前缀走 AES-256-GCM（iv12+ct+tag16）；**tag 校验失败即 fail-closed 返回 FALSE**（密钥轮换/篡改都会被消费侧
 * 当 falsy 处理，绝不降级到 XXTEA——那会把完整性失败静默变成"换一种无认证加密再解一次"）；
 * 其余（无前缀或 \x00）走 XXTEA 存量兼容（S-5：仅解密方向保留，2026-12-31 迁移截止后随 xxtea_* 删除）。
 * $key 留空自动取 conf['auth_key']。
 * @param string $txt base64 密文（可带 URL 编码历史形态）
 * @param string $key 密钥
 * @return string|FALSE 明文；base64 解不出/长度不足返回空串 ''，GCM 校验失败或 XXTEA 尾部长度校验失败返回 FALSE——失败语义多样，调用方按 falsy 处理
 */
function xn_decrypt($txt, $key = '') {
	empty($key) AND $key = xn_key();
	// 兼容旧版 URL-encoded 格式（由 xn_urlencode 处理过的 XXTEA 密文）
	$txt = str_replace(' ', '+', $txt);
	$data = base64_decode($txt);
	if ($data === false || $data === '') {
		$data = base64_decode(xn_urldecode($txt));
	}
	if ($data === false || $data === '') return '';
	$prefix = $data[0] ?? '';
	if ($prefix === "\x01") {
		// AES-256-GCM 格式
		$raw = substr($data, 1);
		if (strlen($raw) < 29) return ''; // iv(12) + min ciphertext(1) + tag(16)
		$enc_key = hash('sha256', $key, true);
		$iv = substr($raw, 0, 12);
		$tag = substr($raw, -16);
		$ciphertext = substr($raw, 12, -16);
		$ret = @openssl_decrypt($ciphertext, 'aes-256-gcm', $enc_key, OPENSSL_RAW_DATA, $iv, $tag);
		// 项目审查补漏：`\x01` 是**本代加密侧的专属格式**，GCM tag 校验失败只有两种含义——
		// 密文被篡改，或密钥已轮换（应重新登录/重建令牌）。这里必须 fail-closed，
		// **不能**落到下面的 XXTEA 分支：那等于把一次完整性校验失败静默降级为「用无认证加密再解一次」，
		// 而消费侧（admin_token_check / user_token / bull_token / 会话载荷）把返回值当**可信明文**使用。
		return $ret !== false ? $ret : FALSE;
	}
	// S-5：仅解密方向保留 XXTEA 兼容（存量旧密文：cookie_test/旧 token/旧缓存），加密侧已不再产生新 XXTEA 密文；
	// 迁移截止 2026-12-31 后删除本段与 xxtea_* 函数。
	// 兼容旧版 XXTEA 格式（无前缀 或 \x00 前缀）
	if ($prefix === "\x00") {
		$data = substr($data, 1);
	}
	$ret = xxtea_decrypt($data, $key);
	return $ret;
}

// ---------------------> XXTEA (保留向后兼容)
/**
 * XXTEA 内部转换（xxtea_str2long 的逆）：把 uint32 数组按 **little-endian** 逐个 pack 回字节串；
 * $w 为 TRUE 时按「末元素记录的原始字节长度」截断输出（解密侧靠它还原真实长度），
 * 长度字段越界（小于 n-3 或大于 n）说明 pack 形态已损坏，**返回 FALSE** 而不是硬凑一个串。
 * 族属 S-5 修复后的**存量兼容路径**：加密侧已统一走 xn_encrypt 的 AES-256-GCM，
 * 本函数只被 xxtea_decrypt 调用，**2026-12-31 迁移截止后随 xxtea_* 一族整体删除**，
 * 不要在新代码里调用它，也不要拿它做「对称转换」自用（无认证加密，可被篡改/重放）。
 * @param array $v uint32 数组（xxtea_str2long 或 XXTEA 轮函数的输出；索引须连续）
 * @param bool $w TRUE = 读末元素当长度字段并截断（解密用）；FALSE = 整包原样输出（加密用）
 * @return string|FALSE 还原出的字节串；$w 形态下长度字段非法时 FALSE
 */
function xxtea_long2str($v, $w) {
    $len = count($v);
    $n = ($len - 1) << 2;
    if ($w) {
        $m = $v[$len - 1];
        if (($m < $n - 3) || ($m > $n)) return FALSE;
        $n = $m;
    }
    $s = array();
    for ($i = 0; $i < $len; $i++) {
        $s[$i] = pack("V", $v[$i]);
    }
    if ($w) {
        return substr(join('', $s), 0, $n);
    }
    else {
        return join('', $s);
    }
}

/**
 * XXTEA 内部转换：字节串按 4 字节补零切包为 little-endian uint32 数组（unpack V*）。
 * @param string $s 输入字节串
 * @param bool $w TRUE 时把原始字节长度追加为末元素（解密侧还原真实长度用）
 * @return array int 数组
 */
function xxtea_str2long($s, $w) {
    $v = unpack("V*", $s. str_repeat("\0", (4 - strlen($s) % 4) & 3));
    $v = array_values($v);
    if ($w) {
        $v[count($v)] = strlen($s);
    }
    return $v;
}

/**
 * XXTEA 内部转换：把可能溢出为 float 的运算结果回绕到 [-2^31, 2^31) 有符号 32 位整型。
 * @param int|float $n 运算中间值
 * @return int 32 位回绕结果
 */
function xxtea_int32($n) {
    while ($n >= 2147483648) $n -= 4294967296;
    while ($n <= -2147483649) $n += 4294967296;
    return (int)$n;
}

/**
 * XXTEA 原始加密（二进制输出，密钥不足 4 个 uint32 时补零）。S-5 后加密侧已改走 xn_encrypt 的
 * AES-256-GCM，本函数无加密调用点，仅与 xxtea_decrypt 同批待 2026-12-31 删除。
 * @param string $str 明文（空串直接返回空串）
 * @param string $key 密钥字节串
 * @return string 密文字节串
 */
function xxtea_encrypt($str, $key) {
    if('' == $str) return '';
    $v = xxtea_str2long($str, TRUE);
    $k = xxtea_str2long($key, FALSE);
    if (count($k) < 4) {
        for ($i = count($k); $i < 4; $i++) {
            $k[$i] = 0;
        }
    }
    $n = count($v) - 1;
    $z = $v[$n];
    $y = $v[0];
    $delta = 0x9E3779B9;
    $q = floor(6 + 52 / ($n + 1));
    $sum = 0;
    while (0 < $q--) {
        $sum = xxtea_int32($sum + $delta);
        $e = $sum >> 2 & 3;
        for ($p = 0; $p < $n; $p++) {
            $y = $v[$p + 1];
            $mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
            $z = $v[$p] = xxtea_int32($v[$p] + $mx);
        }
        $y = $v[0];
        $mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
        $z = $v[$n] = xxtea_int32($v[$n] + $mx);
    }
    return xxtea_long2str($v, FALSE);
}

/**
 * XXTEA 原始解密（long2str 带 w 标志还原长度）。存量密文兼容专用（由 xn_decrypt 兜底调用），
 * 结构性密钥变更/篡改数据下可能解出乱码或 FALSE，不保证完整性——AES-GCM 的 tag 校验无此弱点。
 * @param string $str 密文字节串（空串直接返回空串）
 * @param string $key 密钥字节串
 * @return string|FALSE 明文；尾部长度字段越界（pack 形态损坏）时 FALSE
 */
function xxtea_decrypt($str, $key) {
    if('' == $str) return '';
    $v = xxtea_str2long($str, FALSE);
    $k = xxtea_str2long($key, FALSE);
    if(count($k) < 4) {
        for ($i = count($k); $i < 4; $i++) {
            $k[$i] = 0;
        }
    }
    $n = count($v) - 1;
    $z = $v[$n];
    $y = $v[0];
    $delta = 0x9E3779B9;
    $q = floor(6 + 52 / ($n + 1));
    $sum = xxtea_int32($q * $delta);
    while ($sum != 0) {
        $e = $sum >> 2 & 3;
        for ($p = $n; $p > 0; $p--) {
            $z = $v[$p - 1];
            $mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
            $y = $v[$p] = xxtea_int32($v[$p] - $mx);
        }
        $z = $v[$n];
        $mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
        $y = $v[0] = xxtea_int32($v[0] - $mx);
        $sum = xxtea_int32($sum - $delta);
    }
    return xxtea_long2str($v, TRUE);
}

