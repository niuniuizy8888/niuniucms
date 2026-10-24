<?php
!defined('DEBUG') and exit('Access Denied.');

// hook route_search_start.php

$action = param(1);
if ($action == 'hot') {
    $hot = \App\Service\Front\SearchService::hotPayload($conf);
    $payload = $hot['payload'];
    $ttl = $hot['ttl'];
    if ($json) {
        $api_json['items'] = array_value($payload, 'items', array());
        $api_json['list'] = array();
        if ($api_json['items'] && is_array($api_json['items'])) {
            foreach ($api_json['items'] as $it) {
                $api_json['list'][] = array_value($it, 'keyword', '');
            }
        }
        $api_json['ttl'] = $ttl;
        $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
    } else {
        // 统一结构 + api_on 门控（此前裸 payload 且绕过门控；审计修复）
        if (!$conf['api_on']) {
            http_response_code(403);
            message(-1, lang('closed'));
        }
        $api_json['items'] = array_value($payload, 'items', array());
        $api_json['list'] = array();
        foreach ((array)$api_json['items'] as $it) {
            $api_json['list'][] = array_value($it, 'keyword', '');
        }
        $api_json['ttl'] = $ttl;
        header('Content-Type: application/json; charset=utf-8');
        echo xn_json_encode($api_json) ?: '{}';
    }
    exit;
}

if ($conf['search_on']!=1) {
    $keyword = \App\Service\Front\SearchService::resolveKeyword();
    \App\Service\Front\SeoService::apply($conf['seo']['searchs'], [
    'name' => $keyword.'搜索关闭',
    'num' => 0,
    'sitename' => $conf['sitename'],
]);
$msg='暂不支持搜索';
include _file(view_load('message'));
exit;
}
 
$keyword = \App\Service\Front\SearchService::resolveKeyword();
if ($keyword) {
    \App\Service\Front\SearchService::recordKeyword($keyword);
    // 安全审查 U-3：搜索页缓存键只由「解析后的关键词 + 页码 + 用户组 + 伪静态档位」构成（与 build() 实际输入一致），
    // 不再取原始 URI——随机追加参数/编码变体不再各占一键；长关键词（>30 字）与深分页（>10）不缓存，键空间有界。
    $searchPage = \App\Service\Front\SearchService::resolvePage();
    $kwLen = function_exists('mb_strlen') ? mb_strlen((string)$keyword, 'UTF-8') : strlen((string)$keyword);
    if (!$json && DEBUG == 0 && $kwLen <= 30 && $searchPage <= 10) {
        $searchCacheKey = 'page_search_' . md5('kw:' . $keyword . '_p' . $searchPage . '_g' . $gid . '_r' . ($conf['url_rewrite_on'] ?? 0));
        if (page_cache_start($searchCacheKey)) exit;
        $search_cache_started = true;
    }
}
$data = \App\Service\Front\SearchService::build($keyword, $conf, \App\Service\Front\SearchService::resolvePage());
// 关键词本身以「-数字」结尾（如 26-27）：末段按页码解析后越界时，回退为整串关键词第 1 页
if (!empty($data['redirect']) && '' !== \App\Service\Front\SearchService::pathPageSegment()) {
    $_alt_kw = $keyword . '-' . \App\Service\Front\SearchService::pathPageSegment();
    $_alt = \App\Service\Front\SearchService::build($_alt_kw, $conf, 1);
    if (!empty($_alt['num'])) {
        $keyword = $_alt_kw;
        $data = $_alt;
    }
}
// 越界页 → 302 到末页
if (!empty($data['redirect']) && !$json) {
    http_location($data['redirect']);
}
extract($data);
$num = intval($num ?? 0);
$_SESSION['cid'] = 0;
\App\Service\Front\SeoService::apply($conf['seo']['searchs'], [
    'name' => $keyword,
    'num' => $num,
    'sitename' => $conf['sitename'],
], [
    'robots' => ($keyword && $num > 0) ? 'noindex,follow' : 'noindex,nofollow',
]);
if ($json) {
    $api_json['cid'] = 0;
    $api_json['seo'] = $seo;
    $api_json['keyword'] = $keyword;
    $api_json['keyword_decode'] = $keyword_decode;
    $api_json['search_type'] = $search_type;
    $api_json['count'] = $num;
    $api_json['videolist'] = $videolist;
    $api_json['adlist'] = $adlist;
    $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
} else {
    include _file(view_load('search', $cid));
    if (!empty($search_cache_started)) {
        page_cache_end($searchCacheKey, 300);
    }
}
// hook route_search_end.php

