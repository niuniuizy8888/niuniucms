<?php
/**
 * 超全局访问器（_GET/_POST/_COOKIE/_REQUEST/_ENV/_SERVER/GLOBALS/G/_SESSION）
 * 从 core/func/misc.php 拆分而来，由 core/core.php 在 misc.php 后加载
 */
function _GET($k, $def = NULL)
{
    return isset($_GET[$k]) ? $_GET[$k] : $def;
}
/**
 * 读 $_POST[$k] 的原始值（不经 param() 的 htmlspecialchars 默认收口——主输入面请用 param()，
 * 用本函数即自愿承担未转义数据，§二.4；且 XssFilterMiddleware 若清洗过 $_POST 则读到的是清洗值）。
 * 键缺失返回 $def（默认 NULL）。值可能是字符串或数组，类型以来源为准（mixed）。
 * @param string $k 参数名
 * @param mixed $def 缺省值
 * @return mixed
 */
function _POST($k, $def = NULL)
{
    return isset($_POST[$k]) ? $_POST[$k] : $def;
}
/**
 * 读 $_COOKIE[$k] 的原始值（不转义不校验——cookie 值客户端可控，消费前自行判型/校验）。
 * 键缺失返回 $def（默认 NULL）。
 * @param string $k cookie 名
 * @param mixed $def 缺省值
 * @return mixed
 */
function _COOKIE($k, $def = NULL)
{
    return isset($_COOKIE[$k]) ? $_COOKIE[$k] : $def;
}
/**
 * 全局注册表读取门面（§二.5 的读入口）：优先查 App 容器（写入口唯一 App::set() 白名单键，
 * 并同步写透 $_SERVER 同名镜像），容器缺失或值为 null 时回落原生 $_SERVER[$k]，再无则 $def。
 * 业务侧的 db/cache/conf/time/method/json 等既可能来自容器也可能来自原生超全局，返回形态随键不同。
 * @param string $k 键名（容器白名单键或原生 $_SERVER 键）
 * @param mixed $def 缺省值
 * @return mixed
 */
function _SERVER($k, $def = NULL)
{
    // 优先从 App 容器获取（渐进式替代 $_SERVER 注册表）
    if (class_exists('App', false) && ($v = App::get($k)) !== null) {
        return $v;
    }
    return isset($_SERVER[$k]) ? $_SERVER[$k] : $def;
}
/**
 * 读 $GLOBALS[$k]（历史全局变量门面，如 $db/$catelist/$g_session 等引导期装载物），
 * 键缺失返回 $def（默认 NULL）。与 G() 完全同实现，保留两个名字属历史兼容。
 * @param string $k 全局键名
 * @param mixed $def 缺省值
 * @return mixed
 */
function GLOBALS($k, $def = NULL)
{
    return isset($GLOBALS[$k]) ? $GLOBALS[$k] : $def;
}
/**
 * GLOBALS() 的短名同实现（读 $GLOBALS[$k]，缺失返回 $def）；两者可互换，见彼处说明。
 * @param string $k 全局键名
 * @param mixed $def 缺省值
 * @return mixed
 */
function G($k, $def = NULL)
{
    return isset($GLOBALS[$k]) ? $GLOBALS[$k] : $def;
}
/**
 * 读会话值：$_SESSION[$k] 优先（PHP 原生会话，经自定义 handler 落 Redis/MySQL），
 * 缺失时回落全局 $g_session——会话兼容壳（core/model/session.php）装载的行数据镜像数组。
 * 两处都无则返回 $def。用于区分「原生会话面」与「会话表镜像面」的取值。
 * @param string $k 会话键
 * @param mixed $def 缺省值
 * @return mixed
 */
function _SESSION($k, $def = NULL)
{
    global $g_session;
    return isset($_SESSION[$k]) ? $_SESSION[$k] : (isset($g_session[$k]) ? $g_session[$k] : $def);
}
