<?php
!defined('DEBUG') and exit('Access Denied.');
$cacheKey = "page_tag_" . md5(page_cache_uri_key($_SERVER['REQUEST_URI']) . '_g' . $gid . '_r' . ($conf['url_rewrite_on'] ?? 0) . '_j' . ($json ? 1 : 0));
if (page_cache_start($cacheKey)) return;

// hook route_tag_start.php

$action = param(1);
switch ($action) {
    case 'list':
        $data = \App\Service\Front\TagService::buildList(intval(param(2, 1)), $conf);
        extract($data);
        $_SESSION['cid'] = 0;
        $_SESSION['num'] = $num;
        $_SESSION['page'] = $page;
        $_SESSION['pagesize'] = $pagesize;
        $_SESSION['page_url'] = $page_url;
        \App\Service\Front\SeoService::apply($conf['seo']['tag_list'], [
            'page' => $page,
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
            $api_json['taglist'] = $taglist;
            $api_json['adlist'] = array();
            $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
        } else {
            include _file(view_load('tag_list'));
        }
        break;
    default:
        // 发布前检查修复（C-3）：深分页钳制（route/list.php 同款）
        $data = \App\Service\Front\TagService::buildDetail(intval(param(1, 0)), min(max(1, intval(param(2, 1))), max(1, intval($conf['listsize'] ?? 50))), $conf);
        if (!($data['found'] ?? false)) {
            http_response_code(404);
            include _include(APP_PATH . '404.html');
            return;
        }
        extract($data);
        $_SESSION['cid'] = 0;
        \App\Service\Front\SeoService::apply($conf['seo']['tag'], [
            'name' => $tags['name'],
            'page' => $page,
            'sitename' => $conf['sitename'],
        ], [
            'url' => \App\Service\Front\SeoService::currentUrl(),
            'robots' => intval($page) > 1 ? 'noindex,follow' : '',
        ]);
        if ($json) {
            $api_json['tagid'] = $tagid;
            $api_json['tag_name'] = $tags['name'];
            $api_json['seo'] = $seo;
            $api_json['num'] = $num;
            $api_json['page'] = $page;
            $api_json['pagesize'] = $pagesize;
            $api_json['page_url'] = $page_url;
            $api_json['videolist'] = $videolist;
            $api_json['adlist'] = $adlist;
            $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
        } else {
            include _file(view_load('tag', $cid));
        }
        break;
}
// hook route_tag_end.php
page_cache_end($cacheKey, 1800);
