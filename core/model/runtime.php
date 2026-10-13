<?php
/**
 * 站点统计快照（runtime）：缓存键 'runtime'，由引导注入 global $runtime 供 runtime_get() 读取。
 */
/**
 * 取站点统计快照：users/vod/vod_day/art/art_day/onlines 等聚合值。
 * 缓存 'runtime'（重算路径 TTL 300s，内容增删方会 cache_delete('runtime') 立即失效）命中直接返回；
 * 未命中则重算全量并顺带 1/100 概率触发 art_del()/vod_del() 过期数据清理。
 * onlines 独立走 'runtime_onlines'（300s）刷新，避免整份快照冻结在线数。
 * ⚠ 调用方（index.inc.php）负责把返回值赋给 global $runtime——runtime_get/set/delete 读的是那个全局，不是这里。
 * @return array 统计快照（onlines 恒 ≥1）
 */
function runtime_init()
{
    global $conf;
    $runtime = cache_get("runtime");
    if (NULL === $runtime || empty($runtime['users'])) {
        $runtime = array();
        $runtime['users'] = user_count();
        $runtime['vod'] = video_count();
        $runtime['vod_day'] = video_count_day();
        $runtime['art'] = art_count();
        $runtime['art_day'] = art_count_day();
        $runtime['onlines'] = max(1, online_count());
        $runtime['cron_1_last_date'] = 0;
        $runtime['cron_2_last_date'] = 0;
        // Probabilistic cleanup: only run 1/100 of the time
        if (mt_rand(1, 100) === 1) {
            art_del();
            vod_del();
        }
        cache_set('runtime', $runtime, 300); // 300s TTL：内容增删经 cache_delete('runtime') 立即失效，同时防永久陈旧（审计修复）
    }
    // onlines 独立刷新（300s TTL），避免永久冻结
    $onlines = cache_get('runtime_onlines');
    if (NULL === $onlines) {
        $onlines = max(1, online_count());
        cache_set('runtime_onlines', $onlines, 300);
    }
    $runtime['onlines'] = $onlines;
    return $runtime;
}
/**
 * 读统计快照某键：取 global $runtime（由引导层 runtime_init() 返回值注入），非缓存直读。
 * @param string $k 键名
 * @return mixed 键不存在返回 NULL（array_value 缺省）
 */
/**
 * 写统计快照键（只改 global $runtime 进程内数组，请求尾由 register_shutdown_function 挂的 runtime_save() 落缓存）。
 * 键名以 '+'/'-' 结尾 = 相对增减（在现值上加减，缺现值按 0 起算），落库时去符号存净键——与 config_runtime 的 +/- 后缀同口径。
 * @param string $k 键名（可带 +/- 后缀）
 * @param mixed  $v 值（增减模式下参与算术）
 * @return bool 恒 TRUE
 */
function runtime_set($k, $v)
{
    global $conf, $runtime;
    $op = substr($k, -1);
    if ('+' == $op || '-' == $op) {
        $k = substr($k, 0, -1);
        isset($runtime[$k]) || $runtime[$k] = 0;
        $v = '+' == $op ? ($runtime[$k] + $v) : ($runtime[$k] - $v);
    }
    $runtime[$k] = $v;
    return TRUE;
}
/**
 * 删统计快照键并立即持久化（unset global $runtime 后直接 runtime_save()，不等请求尾）。
 * @param string $k 键名
 * @return bool 恒 TRUE
 */
/**
 * 把 global $runtime 落缓存 'runtime'（cache_set 不带 TTL = 覆盖 runtime_init 的 300s 重算值，此后一直有效直到被删除/重算）。
 * 进程内 md5 指纹去重：数据未变直接跳过（防每请求数百次 REPLACE INTO nncms_cache 的 P 类热点）。
 * 由文件尾 register_shutdown_function 在每请求结束时自动调用，一般无需手动调。
 * @return void
 */
function runtime_save()
{
    global $conf, $runtime;
    if (empty($runtime)) return;
    // 数据未变时不重复写入（避免每秒数百次 REPLACE INTO nncms_cache）
    static $saved_md5 = '';
    $current_md5 = md5(serialize($runtime));
    if ($current_md5 === $saved_md5) return;
    $saved_md5 = $current_md5;
    function_exists('chdir') and chdir(APP_PATH);
    cache_set('runtime', $runtime);
}
/**
 * 作废统计快照：直接删缓存键 'runtime'（内容批量增删/维护脚本后调用，下次 runtime_init() 全量重算）。
 * 注意不影响 global $runtime——本请求内 runtime_get 仍读到旧值。
 * @return void
 */
register_shutdown_function('runtime_save');
