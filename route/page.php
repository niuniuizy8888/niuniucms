<?php
!defined('DEBUG') and exit('Access Denied.');

// 单页路由（审查修复轮 A4）：category=2 的栏目按 seo_des 匹配渲染独立内容页，
// 替代此前「page- 落首页」的 index 兼容分支。整页缓存 1800s（仅 DEBUG=0）。
// hook route_page_start.php
$slug = trim((string)param(1, ''));
// slug 匹配口径（复核修正）：URL 侧可能带 urlencode/xn_urlencode 编码，库值可能经 htmlspecialchars
// 入库 ⇒ 双向解码后比较，避免 seo_des 含非 ASCII / `&` / 引号 时链接 404。
$slugDecoded = xn_urldecode(rawurldecode($slug));
$pageCate = null;
if ('' !== $slug) {
    foreach ((array)$catelist as $_c) {
        if (2 !== intval($_c['category'] ?? 0)) continue;
        $stored = (string)($_c['seo_des'] ?? '');
        if ('' === $stored) continue;
        $storedDecoded = html_entity_decode(xn_urldecode(rawurldecode($stored)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($stored === $slug || $storedDecoded === $slugDecoded) {
            $pageCate = $_c;
            break;
        }
    }
}
if (null === $pageCate) {
    http_response_code(404);
    include _include(APP_PATH . '404.html');
    exit;
}

// 整页缓存键（复核修正）：与 art/vod/play 同口径带 uid/gid/json/rewrite——
// 少了 uid/gid 会把「管理员视角」的整页（public/head.html 的 intoadmin 块含后台入口名与登出令牌）
// 写进全站共享缓存并回放给游客；少了 json 会让 HTML 与 JSON 分支互相串键。
$cacheKey = "page_single_" . md5($slugDecoded . '_u' . intval($uid) . '_g' . intval($gid) . '_r' . ($conf['url_rewrite_on'] ?? 0) . '_j' . ($json ? 1 : 0));
if (page_cache_start($cacheKey)) return;

// ⚠ 正文按需补读：全局 $catelist 是列表口径（不含 content 大列，见 CateService::findFmt），
//   单页需要正文 ⇒ 用 cate_read($cid, TRUE) 取全行；取不到（并发删除等）则回落到列表行，
//   模板对空 content 会回落显示 seo_des（不会输出空白页）。
$cate = $pageCate;
$full = cate_read(intval($pageCate['cid']), TRUE);
if (is_array($full) && !empty($full)) {
    $cate = $full + $cate;   // 全行优先（含 content），列表行补齐 format 前的缺键
}
\App\Service\Content\CateService::format($cate);   // 补 create_date_fmt 等展示字段
// 单页 SEO：栏目自身 seo_title/key/des（入库侧已转义；apply 无变量替换时原样透出）
\App\Service\Front\SeoService::apply(array(
    'seo_title' => !empty($cate['seo_title']) ? $cate['seo_title'] : $cate['name'],
    'seo_key' => $cate['seo_key'] ?? '',
    'seo_des' => $cate['seo_des'] ?? '',
), array(), array(
    'url' => \App\Service\Front\SeoService::currentUrl(),
    'robots' => 'index,follow',
));
if ($json) {
    $api_json['seo'] = $seo;
    $api_json['cate'] = $cate;
    $conf['api_on'] ? message(0, $api_json) : (http_response_code(403) and message(-1, lang('closed')));
} else {
    // 模板走编译管线：_file(view_load(...))（与 art.php/vod.php/play.php 同款）。
    // ⚠ 复核修正：原写法 `include _include(template('page.html'))` 调用了**不存在的 template()**
    //   函数（_include 又是 PHP 源编译管线、编译不了模板语法）⇒ 单页 HTML 主路径必然 fatal。
    include _file(view_load('page', intval($cate['cid'])));
}

// hook route_page_end.php
page_cache_end($cacheKey);
