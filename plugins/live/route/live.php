<?php
!defined('DEBUG') and exit('Access Denied.');

/* 体育直播 · 前台路由（经 hook/index_route_default.php 拦截 /?live 进入）
 *
 * 页面定位：整块官方播放器嵌入（站点头尾壳内）。
 * 数据不缓存：页面本身只是静态外壳——赛事列表/比分/直播流由内嵌播放器页面
 * 每次在访客浏览器内直连 okzhibo 接口（api.okzhibo.live 的 /live/api/live/，硬编码于
 * plugins/live/player/player.html）实时拉取，
 * 本插件与本站服务端不落地、不缓存任何直播数据。
 *
 * 上下文（引导主体已就绪）：$conf/$uid/$gid/$user 等请求级全局。
 */

$lvCfg = function_exists('kv_get') ? kv_get('plugin_live') : NULL;
if (!is_array($lvCfg)) {
    $lvCfg = array(
        'on' => 1, 'name' => '体育直播',
        'seo_title' => '{$name}-免费体育直播在线观看-{$sitename}',
        'seo_key' => '体育直播,足球直播,篮球直播,免费直播',
        'seo_des' => '{$name}：高清足球、篮球赛事免费在线直播。播放数据由播放器每次直连直播源获取，本站不做缓存。',
        'player_url' => '', 'width' => '100%', 'height' => '640',
    );
}
// 关闭态：直接 404（顶栏入口已隐藏；防直接访问展示空壳）
if (empty($lvCfg['on'])) {
    http_response_code(404);
    include _include(APP_PATH . '404.html');
    exit;
}

/* ---- 播放器地址：留空=插件自带；自定义仅允许站内路径或 http(s) 绝对地址（属性注入防护） ---- */
$lvPlayerRaw = trim((string)(isset($lvCfg['player_url']) ? $lvCfg['player_url'] : ''));
if ('' !== $lvPlayerRaw) {
    // '\evil.com' 形态被浏览器按协议相对（//evil.com）解析，反斜杠必须一并拦截
    $lvPathOk = (str_starts_with($lvPlayerRaw, '/') && FALSE === strpos($lvPlayerRaw, '//', 1) && !str_contains($lvPlayerRaw, '\\'))
        || 1 === preg_match('#^https?://#i', $lvPlayerRaw);
    if (!$lvPathOk
        || str_contains($lvPlayerRaw, '<')
        || str_contains($lvPlayerRaw, '>')
        || str_contains($lvPlayerRaw, '"')
        || str_contains($lvPlayerRaw, "'")
        || str_contains($lvPlayerRaw, '`')) {
        $lvPlayerRaw = ''; // 非法形态一律回退插件自带播放器
    }
}
if ('' === $lvPlayerRaw) {
    // 形态修复：内置播放器走站内根相对路径——绝对域名 http_url_path() 会把当前访问域名烙进
    // iframe src 与页面缓存，换域名/内网 IP/HTTPS 迁移时播放器直接白屏。根相对恒等同源。
    $lvPlayerRaw = '/plugins/live/player/player.html';
}
// 自定义绝对地址走站点 outbound 口径二次闸（仅 http/https 已在上面正则限定；此处仅为纵深防御）
if (1 === preg_match('#^https?://#i', $lvPlayerRaw)) {
    if (FALSE === outbound_url_safe($lvPlayerRaw, FALSE)) {
        $lvPlayerRaw = '/plugins/live/player/player.html';
    }
}

/* ---- iframe 尺寸白名单（属性注入防护）：宽=整数px或百分比（裸数字补 px 供 CSS 直用）；高=整数px 200–2000 ---- */
$lvW = trim((string)(isset($lvCfg['width']) ? $lvCfg['width'] : '100%'));
if ('' === $lvW || !preg_match('#^\d{1,4}(px|%)?$#i', $lvW)) {
    $lvW = '100%';
} elseif (preg_match('#^\d+$#', $lvW)) {
    $lvW = $lvW . 'px';
}
$lvH = (int)(isset($lvCfg['height']) ? $lvCfg['height'] : 640);
$lvH = max(200, min(2000, $lvH));

/* ---- 展示名 / SEO ---- */
$lvName = trim((string)(isset($lvCfg['name']) ? $lvCfg['name'] : ''));
'' === $lvName && $lvName = '体育直播';
$lvSeoTitle = trim((string)(isset($lvCfg['seo_title']) ? $lvCfg['seo_title'] : ''));
'' === $lvSeoTitle && $lvSeoTitle = '{$name}-体育直播在线观看-{$sitename}';
\App\Service\Front\SeoService::apply(array(
    'seo_title' => $lvSeoTitle,
    'seo_key'   => trim((string)(isset($lvCfg['seo_key']) ? $lvCfg['seo_key'] : '')),
    'seo_des'   => trim((string)(isset($lvCfg['seo_des']) ? $lvCfg['seo_des'] : '')),
), array(
    'name'    => $lvName,
    'sitename' => isset($conf['sitename']) ? $conf['sitename'] : '',
), array(
    'url'    => \App\Service\Front\SeoService::currentUrl(),
    'robots' => 'noindex,follow', // 内容动态实时，不收录
));
// 出口转义：{$seo.t/k/d} 在 include.html 内为裸 echo（<title>/meta 属性上下文），
// 配置为后台可改字段，统一在此收口转义防属性注入/断标签。
// ENT_SUBSTITUTE：后台 substr 按字节截断可能把多字节字符切碎（非法 UTF-8），
// 不加该标记时 PHP 的 htmlspecialchars 会整体返回空串（标题/描述凭空消失）。
$lvEnt = ENT_QUOTES | ENT_SUBSTITUTE;
if (isset($seo['t'])) $seo['t'] = htmlspecialchars((string)$seo['t'], $lvEnt, 'UTF-8');
if (isset($seo['k'])) $seo['k'] = htmlspecialchars((string)$seo['k'], $lvEnt, 'UTF-8');
if (isset($seo['d'])) $seo['d'] = htmlspecialchars((string)$seo['d'], $lvEnt, 'UTF-8');

/* ---- 模板变量（服务端赋值处收口转义：{$var} 为裸 echo） ---- */
$lv_player_src = htmlspecialchars($lvPlayerRaw, $lvEnt, 'UTF-8');
$lv_player_width = htmlspecialchars($lvW, $lvEnt, 'UTF-8');
$lv_player_height = htmlspecialchars((string)$lvH, $lvEnt, 'UTF-8');
$lv_page_name = htmlspecialchars($lvName, $lvEnt, 'UTF-8');

include _file(APP_PATH . 'plugins/live/template/live.html');
exit;
