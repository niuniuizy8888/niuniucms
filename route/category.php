<?php
!defined('DEBUG') and exit('Access Denied.');
// 页面级缓存 1800s（仅 DEBUG=0 线上模式启用）
$cacheKey = "page_cate_" . md5(page_cache_uri_key($_SERVER['REQUEST_URI']) . '_g' . $gid . '_r' . ($conf['url_rewrite_on'] ?? 0) . '_j' . ($json ? 1 : 0));
if (page_cache_start($cacheKey)) return;

// hook route_category_start.php

$cid = intval(param(1, 0));
$page = intval(param(2, 1));
// 上线前安全审计修复（VULN-M01）：深分页钳制——与 route/list.php 的
// `$page = min(max(1, $page), max(1, intval($conf['listsize'] ?? 50)))` 同口径（用符号锚点，勿写行号）。
// 本文件此前漏加（list/index/type/tag 均已加），`/cates-1-999999999` 会生成 OFFSET≈2×10^10
// 的超深分页并把结果缓存 1800s，未认证即可驱动深度索引扫描（资源耗尽）。
$page = min(max(1, $page), max(1, intval($conf['listsize'] ?? 50)));
$hit = param(3);
$data = \App\Service\Front\CategoryService::build($cid, $page, $hit, $conf, $catelist, $cate_nav, $route_cate);


// hook route_category_data_after.php
if (!($data['found'] ?? false)) {
    if (($data['reason'] ?? '') === 'data_malformation') {
        message(1, lang('data_malformation'));
    }
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
// hook route_category_end.php
page_cache_end($cacheKey, 1800);
