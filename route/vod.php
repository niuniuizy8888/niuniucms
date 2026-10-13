<?php
!defined('DEBUG') and exit('Access Denied.');


// hook route_vod_start.php
$vid = intval(param(1, 0));
$alias_cid = $alias_cid ?? 0;
// 整页缓存 1800s（审查修复轮 D：内容页接入；同 art.php 口径——uid/gid 按人隔离、
// 403/404 早退不落缓存、内容版本令牌自动失效；alias_cid 入键区分别名/规范 URL 的 canonical 输出）
$cacheKey = "page_vod_" . md5($vid . '_u' . intval($uid) . '_g' . intval($gid) . '_a' . intval($alias_cid) . '_r' . ($conf['url_rewrite_on'] ?? 0) . '_j' . ($json ? 1 : 0));
if (page_cache_start($cacheKey)) {
    // 同 art.php：命中缓存也补记浏览量/榜单（video_read 自带分布式缓存，命中时零查询）
    $hitRow = video_read($vid);
    if (!empty($hitRow)) {
        \App\Service\Content\VideoService::incViews($vid);
        \App\Service\Content\VideoService::recordTopView($vid, intval($hitRow['cid'] ?? 0));
    }
    return;
}
$data = \App\Service\Front\VodService::build($vid, intval($uid), intval($gid), $conf, $catelist, $cate_nav, intval($alias_cid));


// hook route_vod_data_after.php
if (!($data['found'] ?? false)) {
    if (($data['reason'] ?? '') === 'redirect') {
        http_location($data['url']);
        return;
    }
    http_response_code(404);
    include _include(APP_PATH . '404.html');
    return;
}
extract($data);
if (!$user_access) {
    // JSON/API 分支同样拦截（此前仅 HTML 分支跳登录，JSON 分支泄漏完整视频数据含播放地址）
    if ($json) {
        (http_response_code(403) and message(-1, lang('forbidden')));
    }
    http_location(url('user-login'));
}
\App\Service\Front\SeoService::apply($conf['seo']['vod'], [
    'name' => $video['name'],
    'lang' => $video['lang'],
    'area' => $video['area'],
    'year' => $video['year'],
    'remarks' => $video['remarks'],
    'des' => xn_html_safe(filter_all_html(xn_substr($video['content'], 0, 120))),
    'cate_name' => $cate['name'],
    'sitename' => $conf['sitename'],
], [
    'url' => \App\Service\Front\SeoService::currentUrl(),
    'image' => (strncasecmp((string)($video['pic'] ?? ''), 'http', 4) === 0 ? $video['pic'] : http_url_path() . ltrim((string)($video['pic'] ?? ''), '/')),
]);
$_SESSION['cid'] = $cid;
if ($json) {
    $api_json['cid'] = $cid;
    $api_json['cate_name'] = $cate['name'];
    $api_json['cate_url'] = $cate['url'];
    $api_json['seo'] = $seo;
    $api_json['video'] = $video;
    $api_json['likelist'] = empty($likelist) ? array() : $likelist;
    $api_json['adlist'] = $adlist;
    $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
} else {
    include _file(view_load('video', $cid));
}
// hook route_vod_end.php

page_cache_end($cacheKey);

