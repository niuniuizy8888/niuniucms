<?php
!defined('DEBUG') and exit('Access Denied.');
// 页面级缓存 1800s（仅 DEBUG=0 线上模式启用；key 含 REQUEST_URI 区分 json/筛选参数）
$cacheKey = "page_index_" . md5(page_cache_uri_key($_SERVER['REQUEST_URI']) . '_u' . intval($uid) . '_g' . $gid . '_r' . ($conf['url_rewrite_on'] ?? 0) . '_j' . ($json ? 1 : 0));
if (page_cache_start($cacheKey)) return;

// hook route_index_start.php

$page = param(1, 1);
// 发布前检查修复（C-3）：深分页钳制（route/list.php 同款）
$page = min(max(1, intval($page)), max(1, intval($conf['listsize'] ?? 50)));
extract(\App\Service\Front\IndexService::build($catelist, intval($cid_count), $conf, intval($page), $route));
$_SESSION['cid'] = $cid;
\App\Service\Front\SeoService::apply($conf['seo']['index'], [
    'sitename' => $conf['sitename'],
], [
    'url' => \App\Service\Front\SeoService::currentUrl(),
    'robots' => intval($page) > 1 ? 'noindex,follow' : '',
]);
if ($json) {
    $api_json['seo'] = $seo;
    $api_json['num'] = $num;
    $api_json['page'] = $page;
    $api_json['pagesize'] = $pagesize;
    $api_json['page_url'] = $page_url;
    $api_json['linklist'] = $linklist;
    $api_json['videolist'] = $videolist;
    $api_json['adlist'] = array();
    $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
} else {
    include _file(view_load('index'));
}
// hook route_index_end.php
page_cache_end($cacheKey, 1800);
