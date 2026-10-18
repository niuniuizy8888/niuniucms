<?php
!defined('DEBUG') and exit('Access Denied.');
// 页面级缓存 1800s（仅 DEBUG=0 线上模式启用）
$cacheKey = "page_type_" . md5(page_cache_uri_key($_SERVER['REQUEST_URI']) . '_g' . $gid . '_r' . ($conf['url_rewrite_on'] ?? 0) . '_j' . ($json ? 1 : 0));
if (page_cache_start($cacheKey)) return;

// hook route_type_start.php

$page = param(2, 1);
// 发布前检查修复（C-3）：深分页钳制（route/list.php 同款）
$page = max(1, intval($page)); // 越界由 TypeService 按真实总页数 302 钳制
$type = param(1, '');
$fallbackCid = intval(param('cid', 0));
$data = \App\Service\Front\TypeService::build($type, intval($page), $fallbackCid, $conf, $catelist, $areaarray, $yeararray, $langarray);


// hook route_type_data_after.php
// 非法 cid / 选项值 ⇒ 404（不写页缓存）；越界页 ⇒ 302 末页（search 同款）
if (!empty($data['not_found'])) {
    http_response_code(404);
    include _include(APP_PATH . '404.html');
    return;
}
if (!empty($data['redirect']) && !$json) {
    http_location($data['redirect']);
}
extract($data);
$_SESSION['cid'] = $cid;
\App\Service\Front\SeoService::apply($conf['seo']['type'], [
    'name' => isset($cate['name']) ? $cate['name'] : '片库',
    'page' => $page,
    'area' => $req_area,
    'lang' => $req_lang,
    'year' => $req_year,
    'sitename' => $conf['sitename'],
], [
    'url' => $filter_canonical,
    'robots' => $filter_noindex ? 'noindex,follow' : '',
]);
if ($json) {
    $api_json['cid'] = $cid;
    $api_json['cate_name'] = isset($cate['name']) ? $cate['name'] : '片库';
    $api_json['cate_url'] = isset($cate['url']) ? $cate['url'] : '';
    $api_json['area'] = $req_area;
    $api_json['lang'] = $req_lang;
    $api_json['year'] = $req_year;
    $api_json['type_urls'] = $type_urls;
    $api_json['sonlist'] = $sonlist;
    $api_json['seo'] = $seo; 
    $api_json['num'] = $num;
    $api_json['page'] = $page;
    $api_json['pagesize'] = $pagesize; 
    $api_json['page_url'] = $page_url;
    $api_json['videolist'] = $videolist;
    $api_json['adlist'] = $adlist = array();
    $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
} else {
    include _file(view_load('type', $cid));
}
// hook route_type_end.php
page_cache_end($cacheKey, 1800);
