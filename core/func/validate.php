<?php
/**
 * 表单/字段校验函数（is_word/is_mobile/is_email/is_username/is_password）
 * 从 core/func/misc.php 拆分而来，由 core/core.php 在 misc.php 后加载
 */
function is_email($email, &$err)
{
    $len = mb_strlen($email, 'UTF-8');
    if ($len > 32) {
        $err = lang('email_too_long', array('length' => $len));
        return FALSE;
    } elseif (!preg_match('/^[\w\-\.]+@[\w\-\.]+(\.\w+)+$/i', $email)) {
        $err = lang('email_format_mismatch');
        return FALSE;
    }
    return TRUE;
}
/**
 * 用户名校验（长度 + 空格 + 字符集三道闸），错误文案按引用写回 $err（取 lang() 键，可直接 message 输出）。
 * 规则：mb_strlen(UTF-8) ≤16（超长文案带实际长度）；不得含半角/全角空格；
 * 只允许「单词字符 + CJK 汉字 + 韩文音节/字母/兼容字母」，正则带 u 修饰符按 UTF-8 码点判定。
 * ⚠ 调用方传来的值通常已过 param() 默认 htmlspecialchars(ENT_QUOTES)，含 & 等转义残留会被本闸判为格式不符。
 * @param mixed $username 待校验用户名（无类型声明，按 strlen/正则消费 ⇒ 需字符串形态）
 * @param mixed $err      引用输出：校验失败时置为错误文案；通过时不写（保持调用方原值）
 * @return bool TRUE=通过；FALSE=失败（原因见 $err）
 */
function is_username($username, &$err = '')
{
    $len = mb_strlen($username, 'UTF-8');
    if ($len > 16) {
        $err = lang('username_too_long', array('length' => $len));
        return FALSE;
    } elseif (str_contains($username, ' ') || str_contains($username, '　')) {
        $err = lang('username_cant_include_cn_space');
        return FALSE;
    } elseif (!preg_match('#^[\w\x{4E00}-\x{9FA5}\x{1100}-\x{11FF}\x{3130}-\x{318F}\x{AC00}-\x{D7AF}]+$#u', $username)) { 
        $err = lang('username_format_mismatch');
        return FALSE;
    }
    return TRUE;
}
/**
 * 密码强度/形态校验：非空 + 长度 6-64 + 拒绝「空串 MD5」与「32 位纯 hex 裸摘要」两种历史误传形态。
 * 长度用的是 strlen（**字节数**，非 mb_strlen）⇒ 中文密码按 UTF-8 每字 3 字节计，最少 2 字即达 6。
 * 背景：旧规则要求恰好 32 字符（前端预 MD5 时代遗留），现行注册/改密页直传原文，故改成区间并显式拒裸摘要。
 * 本函数只做形态校验，不做强度评估；落库散列由 password_hash_custom() 负责（CLAUDE.md「安全红线·认证与加密」）。
 * @param mixed $password 待校验密码（无类型声明，按 strlen/正则消费 ⇒ 需字符串形态）
 * @param mixed $err      引用输出：失败时置错误文案；通过时不写
 * @return bool TRUE=通过；FALSE=失败（原因见 $err）
 */
function is_password($password, &$err = '')
{
    $len = strlen($password);
    if (0 == $len) {
        $err = lang('password_is_empty');
        return FALSE;
    }
    // 修复：旧规则要求恰好 32 字符（前端预 MD5 时代遗留），而现行注册/改密页均直传原文，
    // 导致普通密码恒被拒。改为 6-64 长度带，并拒绝空串 MD5 与 32 位纯 hex 裸摘要（历史误传形态）。
    if ($len < 6 || $len > 64) {
        $err = lang('password_length_incorrect');
        return FALSE;
    } elseif ($password == 'd41d8cd98f00b204e9800998ecf8427e') {
        $err = lang('password_is_empty');
        return FALSE;
    } elseif (preg_match('/^[a-f0-9]{32}$/i', $password)) {
        $err = lang('password_length_incorrect');
        return FALSE;
    }
    return TRUE;
}
