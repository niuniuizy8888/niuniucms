<?php
!defined('DEBUG') and exit('Access Denied.');
$action = (string)param(1, '');
$sub = (string)param(2, '');
// 越界分片 → 404（不进页缓存）
if (\App\Service\Front\SitemapService::shardOutOfRange($action, $sub, $conf)) {
    http_response_code(404);
    header('Content-type: text/plain');
    exit;
}
$canonicalShard = ('art' === $action) ? ('art_' . intval($sub)) : ('' === $action ? '0' : $action);
$cacheKey = "page_sitemap_public_" . md5($canonicalShard . '_r' . ($conf['url_rewrite_on'] ?? 0)
    . '_access' . \App\Service\Content\SitemapVisibilityService::fingerprint());
if (page_cache_start($cacheKey, function () use ($action) { header('Content-type: ' . ('xml' === $action ? 'application/xml; charset=utf-8' : 'text/plain')); })) return;

// hook route_sitemap_start.php

$data = \App\Service\Front\SitemapService::build($action, $conf);
header('Content-type: ' . $data['content_type']);
echo $data['body'];
// hook route_sitemap_end.php
page_cache_end($cacheKey, 1800);
