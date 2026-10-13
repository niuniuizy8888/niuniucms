<?php exit;
/* 体育直播 · 前台路由注册（hook/index_route_default.php）
 * 注入位置：index.inc.php 路由分发 else 分支（未知路由 404 之前）——该埋点的设计用途：
 * 插件在此拦截自己的路由前缀并 include 插件内路由文件，无需触碰核心 route/ 目录。
 * 上下文（引导主体作用域）：$route、$uid、$conf 均已就绪；与其它插件同点钩子共存（各自判参，互不干扰）。 */
if ('live' === param(0, '')) {
    include _include(APP_PATH . 'plugins/live/route/live.php');
    exit;
}
