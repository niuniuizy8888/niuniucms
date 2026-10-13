<?php
!defined('DEBUG') and exit('Access Denied.');

// hook route_play_start.php

$vid = intval(param(1, 0));
$play_from = intval(param(2));
$ep = intval(param(3));
$alias_cid = $alias_cid ?? 0;
// 整页缓存 1800s（审查修复轮 D：播放页接入；仅 DEBUG=0。key 含 uid/gid 按人隔离——匿名
// （uid=0/gid=0）共享同一份缓存；403/404 早退不落缓存；内容版本令牌自动失效；
// alias_cid 入键区分别名/规范 URL 的 canonical 输出）
$cacheKey = "page_play_" . md5($vid . '_' . $play_from . '_' . $ep . '_u' . intval($uid) . '_g' . intval($gid) . '_a' . intval($alias_cid) . '_r' . ($conf['url_rewrite_on'] ?? 0) . '_j' . ($json ? 1 : 0));
if (page_cache_start($cacheKey)) {
    // 同 vod.php：命中缓存也补记浏览量/榜单，避免播放页 1800s 内只计一次
    $hitRow = video_read($vid);
    if (!empty($hitRow)) {
        \App\Service\Content\VideoService::incViews($vid);
        \App\Service\Content\VideoService::recordTopView($vid, intval($hitRow['cid'] ?? 0));
    }
    return;
}
$data = \App\Service\Front\PlayService::build($vid, $play_from, $ep, intval($uid), intval($gid), $conf, $catelist, $cate_nav, intval($alias_cid));
if (!($data['found'] ?? false)) {
    if (($data['reason'] ?? '') === 'redirect') {
        http_location($data['url']);
        return;
    }
    if (($data['reason'] ?? '') === 'forbidden') {
        http_response_code(403);
        $header['title'] = '无权限';
        $message = '您无权观看此内容，请登录或升级会员';
        include _file(view_load('message'));
        return;
    }
    http_response_code(404);
    include _include(APP_PATH . '404.html');
    return;
}
extract($data);
$header['title'] = $video['name'];
$header['mobile_link'] = $video['url'];
\App\Service\Front\SeoService::apply($conf['seo']['play'], [
    'play_name' =>  $play_name,
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
    'robots' => 'noindex,follow',
]);
$_SESSION['cid'] = $cid;
$adlist = array();
if ($json) {
    $api_json['cid'] = $cid;
    $api_json['cate_name'] = $cate['name'];
    $api_json['cate_url'] = $cate['url'];
    $api_json['play_name'] = $play_name;
    $api_json['play_m3u8'] = $play_url;
    $api_json['seo'] = $seo;
    $api_json['video'] = $video;
    $api_json['likelist']=$likelist ?: array();
    $api_json['adlist'] = $adlist;
    $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
} else {
    // player_urls JSON（转义 </ 防 </script> 逃逸脚本标签；由模板 <head> 输出）
    $play_json_safe = str_replace('</', '<\/', (string)($play_json ?: '[]'));
    // 弹幕发送 CSRF token（游客 UA 绑定；danmu.html 的 beforeEmit 携带校验，审计修复）
    // ⚠ 审查修复轮 D（整页缓存配套）：匿名流量（uid=0）恒下发空串——游客 token 是按 UA 派生的
    // （bull_token_set(0,·) 内 md5(User-Agent)），共享缓存页里烧进去的 token 只对「生成该页的 UA」
    // 有效，其他 UA 的访客拿着它发弹幕必被恒时校验拒绝；空串则播放器走 POST 现签端点取会话级新签。
    // 会员（uid>0）不走共享缓存（key 按 uid 隔离），token 按人派生，继续直发。
    $danmu_token = ($uid > 0 && !_SERVER('NNCMS_L0')) ? bull_token_set(0, 'danmu_send') : '';
    include _file(view_load('player', $cid));
}

// hook route_play_end.php

page_cache_end($cacheKey);
