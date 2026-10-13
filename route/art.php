<?php
!defined('DEBUG') and exit('Access Denied.');


// hook route_art_start.php
$aid = intval(param(1, 0));
$alias_cid = $alias_cid ?? 0;
// 整页缓存 1800s（审查修复轮 D：内容页接入；仅 DEBUG=0，key 含 uid/gid 按人隔离——
// 会员专属内容的 403/404 早退分支不会走到 cache_end，无污染；内容版本令牌由
// page_cache_start 内部叠加，改内容/标签即失效）。
// ⚠ key 另含 alias_cid（复核修正）：别名 URL 与规范 URL 渲染的 `$seo['url']`（canonical/JSON-LD）
//   不同，两种形态必须各存一份；alias_cid 是有界整数（别名由后台维护）⇒ 不会像整条 URI 那样
//   被 ?keyword=… 之类白名单参数放大成无限键空间（VULN-M02「键材料必须有界」口径）。
$cacheKey = "page_art_" . md5($aid . '_u' . intval($uid) . '_g' . intval($gid) . '_a' . intval($alias_cid) . '_r' . ($conf['url_rewrite_on'] ?? 0) . '_j' . ($json ? 1 : 0));
if (page_cache_start($cacheKey)) {
    // ⚠ 命中缓存也要计数（复核修正）：浏览量/榜单是**业务数据**，若命中即 return，同一内容 1800s 内
    //   只记 1 次浏览，热榜与「浏览量」被系统性低估（属本批引入的行为回归）。这里用已带分布式缓存的
    //   单行读拿 cid（art_{id} 缓存命中时零查询），把 build() 里的两个计数副作用补齐后原样返回。
    $hitRow = art_read($aid);
    if (!empty($hitRow)) {
        \App\Service\Content\ArtService::incViews($aid);
        \App\Service\Content\ArtService::recordTopView($aid, intval($hitRow['cid'] ?? 0));
    }
    return;
}
$data = \App\Service\Front\ArtService::build($aid, intval($uid), intval($gid), $conf, $catelist, $cate_nav, intval($alias_cid));
if (!($data['found'] ?? false)) {
    if (($data['reason'] ?? '') === 'redirect') {
        http_location($data['url']);
        return;
    }
    if (($data['reason'] ?? '') === 'forbidden') {
        http_response_code(403);
        $header['title'] = '无权限';
        $message = '您无权访问此内容，请登录或升级会员';
        include _file(view_load('message'));
        return;
    }
    http_response_code(404);
    include _include(APP_PATH . '404.html');
    return;
}
extract($data);
\App\Service\Front\SeoService::apply($conf['seo']['art'], [
    'name' => $art['name'],
    'des' => xn_html_safe(filter_all_html(xn_substr($art['content'], 0, 120))),
    'cate_name' => $cate['name'],
    'sitename' => $conf['sitename'],
], [
    'url' => \App\Service\Front\SeoService::currentUrl(),
    'image' => (strncasecmp((string)($art['pic'] ?? ''), 'http', 4) === 0 ? $art['pic'] : http_url_path() . ltrim((string)($art['pic'] ?? ''), '/')),
]);
$_SESSION['cid'] = $cid;
if ($json) {
    $api_json['cid'] = $cid;
    $api_json['cate_name'] = $cate['name'];
    $api_json['cate_url'] = $cate['url'];
    $api_json['seo'] = $seo;
    $api_json['art'] = $art;
    $api_json['likelist'] = $likelist;
    $api_json['adlist'] = $adlist;
    $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
} else {
    include _file(view_load('art', $cid));
}
// hook route_art_end.php

page_cache_end($cacheKey);

