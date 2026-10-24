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
	// L-3（R4）：未认证 XXTEA 解密分支已删除。无 \x01 前缀的旧 XXTEA 密文一律视为无效、返回 FALSE，
	// 由各消费方（admin_token / user_token / bull_token / cookie_test）按 falsy 处理触发重新登录；
	// 不再保留「GCM 完整性失败后用无认证算法再解一次」的降级面。XXTEA 函数族已随本次整体移除。
	return FALSE;
}
