<?php
/**
 * 牛牛CMS 后台入口（重构，替代原 admin/index.php）
 *
 * 【安全提示】`admin.php` 是众所周知的后台入口文件名，扫描器会主动探测。本项目**默认可直接访问**，
 * 加固靠三道纵深：① 登录限流（`login_rate_check()`，每 IP 10 次/300s + 账号+IP 组合 10 次/300s，L-2 消除纯账号锁定 DoS）；
 * ② 后台写操作三件套（强制 POST + 取参白名单 + `admin_bull_token_guard()` 场景令牌）；
 * ③ 未登录一律被 `admin_token_check()` 改写进登录页。**若想再隐去入口名**：把本文件改成随机名
 * （如 3x9k2m.php）并同步 `core/config/config.php` 的 `admin_entry` 即可，其余代码无需改动
 * （后台链接与表单统一经 `url()` 读 `admin_entry` 生成；升级时的入口同步见
 * `CloudOpsService::syncAdminEntry()`——它按 `admin_entry` 覆盖线上入口名对应的文件）。
 * 历史沿革：早期版本把 `admin.php` 自身 403 掉、强制先改名才能用；改为「可用 + 建议改名」后
 * 出厂默认（`config.default.php` 的 `admin_entry = 'admin.php'`）与安装器产物保持一致，开箱即用。
 *
 * 引导关系：本文件 include 'index.php'（入口桩），由其装载配置/函数层/容器与
 * index.inc.php 引导主体；因上面已定义 SKIP_ROUTE，前台路由分发被跳过，改由本文件末尾的
 * admin_include_route() 分发 core/admin/route/。即「后台复用前台引导，只换分发层」。
 *
 * 后台代码已迁移至 core/admin/（ADMIN_PATH 常量驱动），根目录仅保留本入口。
 */

define('SKIP_ROUTE', TRUE);
define('IN_ADMIN', TRUE); // 后台请求标记（misc.php 的 $_is_admin 判定，替代 admin_path 路径判断）
define('ADMIN_PATH', __DIR__ . '/core/admin/');
// 安全加固：后台响应统一防点击劫持/防 MIME 嗅探（不依赖 nginx 模板是否张贴）
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$url_access = TRUE;
// API AJAX 端点提前开启输出缓冲（框架加载前就用 $_GET 检测）
$is_api = ('api' === ($_GET[0] ?? '') && in_array($_GET[1] ?? '', ['cate', 'newcreate', 'setdel', 'auto', 'create'], TRUE));
$is_api and ob_start();
include 'index.php';
defined('ADMIN_URL') or define('ADMIN_URL', './');
include _include(ADMIN_PATH . 'admin.func.php');
// 后台登录/退出入口放行；已持有 admin_token 的会话放行（admin_token_check 会做有效性校验）
admin_token_check();
// 上线前审计修复（L-A3）：匿名请求会被 admin_token_check 改写进 index/login 分支，
// 此前按改写前参数判定 isLoginEntry 恒 false → 未登录 GET 后台入口也触发云端出网（cacheOnly=false）。
// 改为改写后重算：登录页请求走缓存不拉网，已登录管理页行为不变。
$isLoginEntry = 'index' == param(0, '') && in_array(param(1, ''), array('login', 'logout'), true);
// 会话 Cookie 滑动续期（第十五轮）：nncms_sid 只有 24h 且 PHP 仅在新建会话时下发，
// 而 admin_token 每超 1800s 由 admin_token_check 自动续签（admin.func.php 的
// `$time - $_time > 1800 AND admin_token_set()` 分支）——一边续一边不续，
// 于是后台登录满 24h 后 admin_token 还活着、会话 Cookie 已消失：页面照常打开，写操作全被
// bull_token 拒（表现为满屏「非法操作」）。这里让在用中的后台请求把 24h 计时拨回，
// 连续 24h 不用才失效（不延长闲置会话，安全窗口不变）。
// 登录/退出入口不续期：那里 session_regenerate_id 后 PHP 自己会下发新 Cookie；
// 未登录访客已被上面改写到登录页（param(1)==='login'），也不给扫描器反复发 Set-Cookie。
if (!$isLoginEntry && 'login' !== param(1, '')) {
    \App\Service\Account\SessionService::slideCookie();
}
// 审计修复（F-01）：入口引导阶段一律只读缓存，绝不在此发起出网请求。
// 此前 $cacheOnly=!$isLoginEntry 使「登录入口」在引导期同步拉取旧官方站点 example.com / 云端 code
// （https_request 超时 30s 且失败不缓存），而匿名 GET 会被 admin_token_check 改写成登录入口、
// 后台入口又被限流白名单豁免——未认证请求即可每发一个占住一个 FPM 工作进程 30s（两路=最多 60s），
// 并发即可打满 pm.max_children。云端数据在管理页（升级/采集等显式动作）仍按需拉取，此处仅省引导期出网。
$cacheOnly = TRUE;
$nnzs=\App\Service\System\CloudOpsService::niuniuzsCodes($cacheOnly);
$version=$conf['version'];
$rescode=\App\Service\System\CloudOpsService::codes($cacheOnly);
$cloud_version=is_array($rescode) ? ($rescode['cloud_version']??$version) : $version;
$update_log=is_array($rescode) ? ($rescode['update_log']??'') : '';
$route = param(0, 'index');
$route_map = array(
    'index' => 'index.php',
    'cate' => 'cate.php',
    'cat'  => 'cate.php',    // 兼容 cat → cate
    'art' => 'art.php',
    'vod' => 'vod.php',
    'link' => 'link.php',
    'api' => 'api.php',
    'caiji' => 'api.php',    // 兼容 caiji → api
    'set' => 'set.php',
    'tmpl' => 'tmpl.php',
    'user' => 'user.php',
    'hot' => 'hot.php',
    'ad' => 'ad.php',
    'task' => 'task.php',
    'recycle' => 'recycle.php',
    'addon' => 'addon.php',
);
// 后台入口不执行计划任务：前身遗留的 `//cron_run();`（同步阻塞形态）本仓已无 cron_run() 定义，永久废弃。
// 现行任务通道：api.php?route=cron（机器通道入队）+ TaskQueueService 请求尾消费（仅 DEBUG=0）；
// 后台「任务管理」页的 cron URL 由 TaskManageService 生成。口径见 CLAUDE.md「开发约定·远程下载/出网」。
admin_include_route(ADMIN_PATH . 'route/', $route, $route_map, 'index');
