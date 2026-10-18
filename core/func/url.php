<?php
/**
 * Url 函数
 * 从 misc.php 拆分
 */

function xn_urlencode($s)
{
    $s = urlencode($s);
    $s = str_replace('_', '_5f', $s);
    $s = str_replace('-', '_2d', $s);
    $s = str_replace('.', '_2e', $s);
    $s = str_replace('+', '_2b', $s);
    $s = str_replace('=', '_3d', $s);
    $s = str_replace('%', '_', $s);
    return $s;
}

/**
 * xn_urlencode() 的逆过程：先把 `_` 还原成 `%`，再交给 urldecode()。
 *
 * 配套编码把 `_` 当转义引导符（_5f=_、_2d=-、_2e=.、_2b=+、_3d==，其余 urlencode 结果原样），
 * 目的是让别名/筛选串里不出现 `%`、`-`、`.`、`=` 这些会撞路由分隔符的字符。解码是**整串一次性替换**，
 * 因此对未经 xn_urlencode() 编码的输入不安全：原文里本来就有的 `_`（如 my_alias）会被当成 `%` 前缀——
 * 紧跟两位十六进制时被解回对应字符，否则留下裸 `%`。只应对「确定由 xn_urlencode() 产出」的段调用。
 *
 * @param string $s 编码后的字符串（非字符串入参按 PHP 标量强转处理）
 * @return string 解码结果
 */
function xn_urldecode($s)
{
    $s = str_replace('_', '%', $s);
    $s = urldecode($s);
    return $s;
}

// 获取 http://xxx.com/path/
// ============================================================================
// [URL] URL 函数
// ============================================================================

function http_url_path()
{
    $conf = _SERVER('conf');
    $port = _SERVER('SERVER_PORT');
    $host = _SERVER('HTTP_HOST');
    // 审计修复（XSS-01）：HTTP_HOST 客户端可控且原样反射进 {$nncms.site_url}/head href。
    // 仅保留合法主机名字符（字母数字 . - : [ ]）；非法则回退 SERVER_NAME 并同样过滤。
    // 当前 nginx 对非法 Host 直接 400，但代理/通配 vhost 场景下原始 Host 会到达 PHP，属纵深。
    $host = preg_replace('#[^A-Za-z0-9._:\[\]-]#', '', (string)$host);
    if ('' === $host) $host = (string)_SERVER('SERVER_NAME');
    $host = preg_replace('#[^A-Za-z0-9._:\[\]-]#', '', (string)$host);
    $https_off = _SERVER('HTTPS', 'off');
    $https = $https_off ? strtolower($https_off) : '';
    $http_proto = _SERVER('HTTP_X_FORWARDED_PROTO');
    $proto = $http_proto ? strtolower(_SERVER('HTTP_X_FORWARDED_PROTO')) : '';
    $len = strrpos($_SERVER['PHP_SELF'], '//');
    FALSE === $len and $len = strrpos($_SERVER['PHP_SELF'], '/');
    $path = substr($_SERVER['PHP_SELF'], 0, $len);
    !isset($conf['url_rewrite_on']) and $conf['url_rewrite_on'] = 0;
    $conf['url_rewrite_on'] < 2 and $path = $path . '/';
    $http = (!empty($conf['force_https']) || (443 == $port) || 'https' == $proto || ($https && 'off' != $https)) ? 'https' : 'http';
    return "$http://$host$path";
}

// 将参数添加到 URL

/**
 * URL format: http://www.domain.com/demo/?user-login.htm?a=b&c=d
 * URL format: http://www.domain.com/demo/?user-login.htm&a=b&c=d
 * URL format: http://www.domain.com/demo/user-login.htm?a=b&c=d
 * URL format: http://www.domain.com/demo/user-login.htm&a=b&c=d
 * array(
 *     0 => user,
 *     1 => login
 *     a => b
 *     c => d
 * )
 */
function xn_url_parse($request_url, $conf = array(), $access = '')
{
    $url_access = GLOBALS('url_access');
    if ($url_access || 'manage' == $access) return $_GET;
    if ('access' == $access || !$access) {
        !isset($conf['url_rewrite_on']) and $conf['url_rewrite_on'] = 0;
        if ($conf['url_rewrite_on'] < 2) {
            0 == $conf['url_rewrite_on'] and $request_url = str_replace('/?', '/', $request_url);
            $arr = parse_url($request_url);
            $q = array_value($arr, 'path');
            substr_count($q, '/') > 2 && FALSE === stripos($q, '/install') and http_location('/');
            $pos = strrpos($q, '/');
            FALSE === $pos && $pos = -1;
            $q = substr($q, $pos + 1); // 截取最后一个 / 后面的内容
            // 查找第一个 ? & 进行分割
            $sep = !str_contains($q, '?') ? strpos($q, '&') : FALSE;
            if (FALSE !== $sep) {
                // 对后半部分截取，并且分析
                $front = substr($q, 0, $sep);
                $behind = substr($q, $sep + 1);
            } else {
                $front = $q;
                $behind = '';
            }
            if ('.html' == substr($front, -5)) $front = substr($front, 0, -5);
            // 入口脚本（index.php/admin 等）不作为路由段，避免覆盖查询串中的数字参数（?0=danmu）
            if ('.php' == substr($front, -4)) $front = '';
            $r = $front ? explode('-', $front) : array();
            // 将后半部分合并
            $arr1 = $arr2 = $arr3 = array();
            $behind and parse_str($behind, $arr1);
            // 将 xxx.htm?a=b&c=d 放到后面，并且修正 $_GET
            if (!empty($arr['query'])) {
                parse_str($arr['query'], $arr2);
            } else {
                !empty($_GET) and $_GET = array();
            }
            $arr3 = $arr1 + $arr2;
            if ($arr3) {
                //array_diff_key($arr3, $_GET) || array_diff_key($_GET, $arr3);
                count($arr3) != count($_GET) and $_GET = $arr3;
            } else {
                !empty($_GET) and $_GET = array();
            }
            $r += $arr3;
        } else {
            $r = xn_url_parse_path_format($_SERVER['REQUEST_URI']);
        }
        isset($r[0]) && ('admin' == $r[0] || 'index.php' == $r[0]) and $r[0] = 'index';
    }
    return $r;
}

/**
 * 支持 URL format: http://www.domain.com/user/login?a=1&b=2
 * array(
 *     0 => user,
 *     1 => login,
 *     a => 1,
 *     b => 2
 * )
 */
function xn_url_parse_path_format($s)
{
    $request_url = explode('?', $s);
    $url = str_replace('.html', '', $request_url[0]);
    $url = trim($url, '/');
    $get = explode('/', $url);
    if (!empty($request_url[1])) {
        parse_str($request_url[1], $arr2);
        $get = array_merge($get, $arr2);
    }
    return $get;
}

/**
 * 站内链接生成（前台路由 `页名-id-…` 形态 → 按伪静态开关拼 URL），返回**相对站点的路径串**。
 *
 * 按 conf['url_rewrite_on'] 四档分支：0=`路径?页名-参数.html`；1=`路径页名-参数.html`；
 * 2/3=取 conf['path'] 并把 `-` 换成 `/`（2 补 .html，3 不带后缀，即真·伪静态）；未配该键按 0 处理。
 * 后台链接覆盖：$url_access 为 TRUE（后台入口 admin.php 里置的布尔）且首段不在
 * operate/attach/read/category/list/my/forum/thread 白名单内，或 $url_access 显式为 3 时，
 * 一律改写成 `/后台入口文件?参数`——入口名取 conf['admin_entry']（安装时可随机改名，缺省 admin.php），
 * 且带前导 `/` 走绝对路径（这两点都是历史 404 修复，别改回相对或硬编码）。
 *
 * ⚠ 返回值不做转义：结果直接进模板 {$var}（裸 echo）。参数段应来自受控来源；
 * 含用户可控内容时由调用方按上下文自行 xn_h() / htmlspecialchars()。
 *
 * @param string $url 路由串（形如 'vod-read-123'；含 '/' 时最后一段前的部分当路径保留）
 * @param array $extra 追加查询参数（http_build_query 拼接，已有 ? 则用 &）
 * @param bool|int|null $url_access NULL=读 GLOBALS('url_access')；TRUE=后台；3=强制后台形态
 * @return string 相对路径 URL（可能以 / 开头，如后台链接与 2/3 档的 conf['path']）
 */
function url($url, $extra = array(), $url_access = NULL)
{
    $conf = _SERVER('conf');
    NULL === $url_access and $url_access = GLOBALS('url_access');
    !isset($conf['url_rewrite_on']) and $conf['url_rewrite_on'] = 0;
    $r = $path = $query = '';
    if ($url && str_contains($url, '/')) {
        $path = substr($url, 0, strrpos($url, '/') + 1);
        $query = substr($url, strrpos($url, '/') + 1);
    } else {
        $path = '';
        $query = $url;
    }
    if (0 == $conf['url_rewrite_on']) {
        $r = $path . '?' . $query . '.html';
    } elseif (1 == $conf['url_rewrite_on']) {
        $r = $path . $query . '.html';
    } elseif (2 == $conf['url_rewrite_on'] || 3 == $conf['url_rewrite_on']) {
        if (!str_contains($query, '-')) {
            $r = $conf['path'] . $query;
        } else {
            $r = $conf['path'] . str_replace('-', '/', $query) . (2 == $conf['url_rewrite_on'] ? '.html' : '');
        }
    }
    $arr = explode('-', $query);
    $filter = array('operate', 'attach', 'read', 'category', 'list', 'my', 'forum', 'thread');
    // 后台链接：基底用后台入口文件（admin_entry，安装时可自动随机化），
    // 此前硬编码 index.php 导致改名后后台导航/表单全部落到前台入口（上线前审计修复）
    // 修复：补前导 / 改为绝对路径，避免从 /<入口改名后的文件名>/index/xxx 页面渲染时
    // 被浏览器解析为相对路径 /<入口改名后的文件名>/index/<入口改名后的文件名>?... 而导致表单/API 全部 404
    if ((TRUE === $url_access && !in_array($arr[0], $filter, TRUE)) || 3 === $url_access) {
        $adminBase = !empty($conf['admin_entry']) ? $conf['admin_entry'] : 'admin.php';
        $r = '/' . $adminBase . '?' . http_build_query($arr);
    }
    /*$json = param('json', 0);
    if ($json) {
        empty($extra) and $extra = array();
        $extra['json'] = $json;
    }*/
    // 附加参数
    if ($extra) {
        $args = http_build_query($extra);
        $sep = !str_contains($r, '?') ? '?' : '&';
        $r .= $sep . $args;
    }
    return $r;
}

/**
 * sitemap 专用链接生成：与 url() 同一套四档伪静态分支，但有四处刻意不同，不能互相替换——
 *  ① 后缀一律 .txt（0/1 档补 .txt，2 档补 .txt，3 档不带后缀）；
 *  ② 后台改写白名单只留 operate（前台页面 id 全部允许）；
 *  ③ 后台基底硬编码 index.php 且不加前导 `/`（ sitemap 要的是可被爬虫直接抓的站点根相对形态，
 *     不走 admin_entry 改名逻辑）；
 *  ④ 其余路径切分/参数拼接与 url() 一致。
 *
 * @param string $url 路由串（同 url()）
 * @param array $extra 追加查询参数
 * @param bool|int|null $url_access NULL=读 GLOBALS('url_access')；语义同 url()
 * @return string sitemap 用相对路径（.txt 形态）
 */
function url_sitemap($url, $extra = array(), $url_access = NULL)
{
    $conf = _SERVER('conf');
    NULL === $url_access and $url_access = GLOBALS('url_access');
    !isset($conf['url_rewrite_on']) and $conf['url_rewrite_on'] = 0;
    $r = $path = $query = '';
    if ($url && str_contains($url, '/')) {
        $path = substr($url, 0, strrpos($url, '/') + 1);
        $query = substr($url, strrpos($url, '/') + 1);
    } else {
        $path = '';
        $query = $url;
    }
    if (0 == $conf['url_rewrite_on']) {
        $r = $path . '?' . $query . '.txt';
    } elseif (1 == $conf['url_rewrite_on']) {
        $r = $path . $query . '.txt';
    } elseif (2 == $conf['url_rewrite_on'] || 3 == $conf['url_rewrite_on']) {
        if (!str_contains($query, '-')) {
            $r = $conf['path'] . $query;
        } else {
            $r = $conf['path'] . str_replace('-', '/', $query) . (2 == $conf['url_rewrite_on'] ? '.txt' : '');
        }
    }
    $arr = explode('-', $query);
    $filter = array('operate');
    if ((TRUE === $url_access && !in_array($arr[0], $filter, TRUE)) || 3 === $url_access) {
        $r = 'index.php?' . http_build_query($arr);
    }
    if ($extra) {
        $args = http_build_query($extra);
        $sep = !str_contains($r, '?') ? '?' : '&';
        $r .= $sep . $args;
    }
    return $r;
}

// ============================================================================
// 播放器解析地址形态（vodplayer[*]['parse']）
// ============================================================================
// 播放器条目只有「解析地址前缀」这一个字段，而它历史上必须是 http/https 完整地址，
// 于是「用本站自己的播放器」根本没法配置：留空会被前台判 player_not_found（整页 404）、
// 填站内路径会被同一套 scheme 校验挡掉、填本站绝对地址又被后台的内网地址闸拦下。
// 本函数把三种形态收敛成一个口径，前台（PlayService）与后台保存（PlayerConfigService）共用，
// 避免两处各写一份规则、日后放宽时漏改一边。
//   inline —— 空串 / '@local'：站内内置播放器（artplayer + 弹幕，模板内联渲染，不 iframe）
//   same   —— 以单个 '/' 开头的站内路径：同源 iframe 播放页（可用 {vid}/{sid}/{nid}/{url} 占位符）
//   remote —— http/https 完整地址：第三方解析站（与历史行为逐字节一致）
//   bad    —— 其它一律拒绝（javascript: / data: / //host 协议相对 / 反斜杠 / 空白 / 控制字符）
function player_parse_kind($url)
{
    $url = trim((string) $url);
    if ('' === $url || '@local' === $url) {
        return 'inline';
    }
    if ('/' === $url[0] && 0 !== strpos($url, '//') && preg_match('#^/[A-Za-z0-9_./?&=%+~{}-]*$#', $url)) {
        return 'same';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    // 审计修复（XSS-02）：remote 前缀会原样拼进 <iframe src="{$player}">，
    // 仅校验 scheme 会放行 http://x/"><script>…（属性闭合注入）。补字符闸。
    if (('http' === $scheme || 'https' === $scheme) && !preg_match('#[\s<>"\'`\\\\\x00-\x1F\x7F]#', $url)) {
        return 'remote';
    }
    return 'bad';
}

// 播放器解析地址里的占位符展开：{vid}/{sid}/{nid} 取本站整数，{url} 取播放直链。
// 没有任何占位符时保持老契约「前缀 + 播放直链」直接拼接（第三方解析站走这条，语义不变）。
function player_parse_build($parse, $vid, $sid, $nid, $play_url)
{
    $parse = (string) $parse;
    $r = str_replace(
        array('{vid}', '{sid}', '{nid}'),
        array(intval($vid), intval($sid), intval($nid)),
        $parse
    );
    if (str_contains($r, '{url}')) {
        return str_replace('{url}', (string) $play_url, $r);
    }
    return $r . $play_url;
}

// return http://domain.com OR https://domain.com
