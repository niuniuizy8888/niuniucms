<?php

/**
 * 运行时动态配置（KV 层）使用的聚合存储键名——后台改设置写这里，不再物理改写 config.php。
 * @return string 固定 'runtime_conf'
 */
function config_runtime_cache_key()
{
    return 'runtime_conf';
}

/**
 * 读取全量运行时动态配置：kv_cache_get('runtime_conf')（缓存优先，未命中回落 kv 表并回填）。
 * @return array 配置数组；未设置或值非数组时为空数组（调用方无需判空值）
 */
function config_runtime_all()
{
    $conf = kv_cache_get(config_runtime_cache_key());
    return is_array($conf) ? $conf : array();
}

/**
 * 按顶层键取单项运行时配置。
 * @param string $key     配置键（顶层精确匹配，不做点号路径下钻）
 * @param mixed  $default 键缺失时的回退值
 * @return mixed 存的什么类型就返回什么类型（kv JSON 解码产物），故为 mixed
 */
/**
 * 写单键运行时配置：整表读-改-写（读全量、覆一个键、kv_cache_set 回写），非原子，并发写会互相覆盖。
 * 敏感键的最后防线在读侧：config_runtime_apply() 每次装配都会按禁覆盖黑名单剥除（正常后台表单不在可保存白名单内，写不到这里）。
 * @param string $key   配置键
 * @param mixed  $value 任意可 JSON 化的值
 * @return int|FALSE kv_cache_set→kv_set REPLACE 写 kv 表的结果（数据非空时恒为 int）
 */
function config_runtime_set($key, $value)
{
    $conf = config_runtime_all();
    $conf[$key] = $value;
    return kv_cache_set(config_runtime_cache_key(), $conf);
}

/**
 * 批量合并写运行时配置：与存量整表做 array_replace_recursive 深合并后一次性回写（嵌套组按键路径合并，非整组替换）。
 * @param array $values 键=>值（值可为嵌套数组）
 * @return int|FALSE 非数组或空数组直接 FALSE；否则 kv_cache_set→kv_set REPLACE 写入结果
 */
function config_runtime_set_values($values)
{
    if (!is_array($values) || empty($values)) return FALSE;
    $conf = config_runtime_all();
    $conf = array_replace_recursive($conf, $values);
    return kv_cache_set(config_runtime_cache_key(), $conf);
}

/**
 * 删除单键运行时配置：整表读-改-写（键不存在也照常回写整表，非静默跳过）。
 * @param string $key 配置键
 * @return int|FALSE kv_cache_set→kv_set REPLACE 写 kv 表的结果
 */
/**
 * 批量删除运行时配置键：逐 unset 后整表回写（config_runtime_delete() 的多键版本）。
 * @param array $keys 待删的顶层键列表
 * @return int|FALSE 非数组或空数组直接 FALSE；否则 kv_cache_set→kv_set REPLACE 写入结果
 */
/**
 * 把运行时 KV 配置叠加到基础配置之上：array_replace_recursive($conf, $runtime_conf)，运行时值优先。
 * 安全（S8）：叠加前先过 config_runtime_strip_forbidden() 黑名单——缓存层（尤其无认证 Redis）
 * 被写入即等价全站配置注入，auth_key/tpl_allow_php/cloud_api 等 RCE/凭据级键永远进不来。
 * @param array $conf 基础配置（config.default.php + config.php 合成结果）
 * @return array 叠加后的生效配置；runtime 层为空（或全部被剥除）时原样返回 $conf
 */
function config_runtime_apply($conf)
{
    $runtime_conf = config_runtime_all();
    if (empty($runtime_conf)) return $conf;
    // 安全修复（S8）：runtime_conf 是缓存键值（kv），缓存层（尤其无认证 Redis/共享 Memcached）
    // 被写入即等价全站配置注入。此处按黑名单剥除敏感键，任何注入值不进入 array_replace_recursive。
    $runtime_conf = config_runtime_strip_forbidden($runtime_conf);
    if (empty($runtime_conf)) return $conf;
    return array_replace_recursive($conf, $runtime_conf);
}

/**
 * 剥除不允许经缓存层覆盖的配置键（顶层精确匹配 + 嵌套键路径，大小写不敏感）
 * 涵盖：主密钥/入口/模板引擎开关/DB/缓存层自身/会话 Cookie 参数/URL 规则/后台 API 桥接参数
 */
function config_runtime_strip_forbidden($values)
{
    if (!is_array($values)) return array();
    // 顶层禁覆盖键
    // 上线前审计修复（VULN-M06）：补齐「一旦经 KV/缓存写入即成 RCE / SSRF / 删站」的键。
    // 原名单已含 auth_key/admin_entry/tpl_allow_php 等，但漏掉：
    //  - tpl_allow_expr：开启后 {if:}/{@EXPR} 表达式照常编译，与模板写原语叠加即 RCE；
    //  - cloud_api：决定 outbound_trusted_hosts() 豁免名单（内网出网豁免 + wire 令牌外发目标）；
    //  - cache_path/log_path/upload_path：rmdir_recusive($conf['cache_path'],1) 可递归删站；
    //  - auth_salt：参与凭据派生。
    $forbidden_top = array(
        'auth_key', 'auth_salt', 'key', 'admin_entry', 'tpl_allow_php', 'tpl_allow_expr',
        'api', 'db', 'database', 'cache', 'session', 'cache_path', 'log_path', 'upload_path',
        'cookie_pre', 'cookie_domain', 'cookie_path', 'ssl_verify', 'url_rewrite_on', 'cloud_api',
        'install', 'path', 'theme', 'template', 'path_tpl', 'debug',
    );
    // 嵌套键仅对「无合法运行时写入方」的组生效（es.* 由后台 saveEsConfig 合法写入，不能在读侧拦截；
    // 其出网安全由 ElasticService 统一过 outbound_url_safe 兜底——见 VULN-H03 修复）
    $forbidden_nested = array(
        'setting' => array('tpl_allow_php', 'tpl_allow_expr', 'cloud_api', 'cache_path', 'log_path', 'upload_path', 'auth_key', 'auth_salt'),
    );
    foreach ($values as $k => $v) {
        $lk = strtolower((string) $k);
        if (in_array($lk, $forbidden_top, TRUE)) {
            unset($values[$k]);
            config_runtime_audit_forbidden($k);
            continue;
        }
        if (isset($forbidden_nested[$lk]) && is_array($v)) {
            foreach ($forbidden_nested[$lk] as $fk) {
                foreach ($v as $sk => $sv) {
                    if (strtolower((string) $sk) === $fk) {
                        unset($values[$k][$sk]);
                        config_runtime_audit_forbidden($k . '.' . $sk);
                    }
                }
            }
            if (empty($values[$k])) unset($values[$k]);
        }
    }
    return $values;
}

/**
 * 禁覆盖键「被写入尝试」的审计留痕。
 *
 * 安全审计要求：`tpl_allow_php` / `tpl_allow_expr` 这类 RCE 级开关的开启动作**必须有告警**，
 * 不能静默剥除。原实现直接 unset 返回，事后无从得知「有人试过开」还是「根本没人碰」。
 *
 * 触发面：任何经运行时配置写入这些键的调用。正常后台表单走不到这里（这些键不在可保存白名单内），
 * 因此**一旦出现本记录，即代表有代码/插件/被篡改的请求在尝试开启 RCE 开关，应按安全事件排查**。
 *
 * 只记键名不记值（值可能含密钥）；通道名含 `_audit` ⇒ LoggerService 恒写，不受 DEBUG 级别过滤。
 *
 * @param string $path 被剥除的键路径（顶层为键名，嵌套为 `组.键`）
 */
function config_runtime_audit_forbidden($path)
{
    $path = (string) $path;
    if (function_exists('xn_log')) {
        xn_log('forbidden config key write attempt blocked: ' . $path, 'config_audit');
    }
    if (function_exists('admin_audit_log')) {
        admin_audit_log('config_forbidden_key_blocked', array('key' => $path));
    }
}

