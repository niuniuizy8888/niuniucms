<?php
!defined('DEBUG') and exit('Access Denied.');
// 越界分片 → 404（不进页缓存）
if (\App\Service\Front\SitemapService::shardOutOfRange(param(1), param(2), $conf)) {
    http_response_code(404);
    header('Content-type: text/plain');
    exit;
}
$cacheKey = "page_sitemap_" . md5(page_cache_uri_key($_SERVER['REQUEST_URI']) . '_g' . $gid . '_r' . ($conf['url_rewrite_on'] ?? 0));
if (page_cache_start($cacheKey, function () { header('Content-type: ' . ('xml' === param(1) ? 'application/xml; charset=utf-8' : 'text/plain')); })) return;

// hook route_sitemap_start.php

$action = param(1);
$data = \App\Service\Front\SitemapService::build($action, $conf);
header('Content-type: ' . $data['content_type']);
echo $data['body'];
// hook route_sitemap_end.php
page_cache_end($cacheKey, 1800);
