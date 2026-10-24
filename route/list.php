<?php
!defined('DEBUG') and exit('Access Denied.');
// 页面级缓存 1800s（仅 DEBUG=0 线上模式启用）
$cacheKey = "page_list_" . md5(page_cache_uri_key($_SERVER['REQUEST_URI']) . '_u' . intval($uid) . '_g' . $gid . '_r' . ($conf['url_rewrite_on'] ?? 0) . '_j' . ($json ? 1 : 0));
if (page_cache_start($cacheKey)) return;

// hook route_list_start.php

$cid = intval(param(1, 0));
empty($cid) and message(1, lang('data_malformation'));
$page = intval(param(2, 1));
// 发布前检查修复（C-3）：深分页钳制——合法最大页码恒=listsize（$num 封顶=pagesize*listsize），
// 消除超大 OFFSET 索引行走与无界分页缓存键空间
$page = min(max(1, $page), max(1, intval($conf['listsize'] ?? 50)));
$hit = param(3);
$data = \App\Service\Front\ListService::build($cid, $page, $hit, $conf, $catelist, $cate_nav, $route_list, $areaarray, $yeararray, $langarray);


// hook route_list_data_after.php
if (!($data['found'] ?? false)) {
    http_response_code(404);
    include _include(APP_PATH . '404.html');
    return;
}

route_list_page(
    $data['type'],
    $data['cid'],
    $data['cate'],
    $data['num'],
    $data['page'],
    $data['pagesize'],
    $data['page_url'],
    $data['items'],
    $conf,
    $data['extra']
);
// hook route_list_end.php
page_cache_end($cacheKey, 1800);
