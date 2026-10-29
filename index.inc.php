<?php
/**
 * 前台引导主体（自 index.php 拆出：入口桩 + 引导主体两段式，见 CLAUDE.md 安全红线 §二.1）
 * 本文件经 _include() 编译管线加载：文件内的 // hook index_inc_*.php 埋点在编译期内联插件 hook 内容，
 * 插件因此可以干预引导生命周期（这正是入口埋点生效的前提——入口文件自身直接执行时 hook 注释是死点）。
 * 上游（index.php 入口桩）已完成：常量/致命捕获/静态文件直服/配置装载/站群域名/容器与函数层/PSR-4 autoloader；
 * 变量（$conf/$domain/$hosts/$siteconf）经 include 语义与本文件共享作用域。
 */

// 入口守卫：本文件是「引导主体」，只能由入口桩 index.php 装载（APP_PATH 由桩定义）。
// 缺此守卫时匿名直连 /index.inc.php 会带着残缺引导跑一遍（常量/函数层未装载）并 500，
// 同时把无意义条目写进 runtime/log/<年月>/php_fatal.php（可被用来刷日志/占盘）。
defined('APP_PATH') or exit('Access Denied.');

// hook index_inc_start.php

// ============================================================
// 模型加载（原 model.inc.php 合并）
// PSR-4: App\ → core/app/, Psr\ → core/psr/；兼容兜底 core/model/ 与 core/lib/
// ============================================================
$include_model_files = array(
    APP_PATH . 'core/model/runtime.php', APP_PATH . 'core/model/kv.php',
    APP_PATH . 'core/model/config_runtime.php',
    APP_PATH . 'core/model/user.php', APP_PATH . 'core/model/session.php',
    // 三个 SessionHandler 为 PSR-4 自动加载类（SessionService 以 FQCN 实例化，core.php autoloader 负责加载），
    // 不得加入本合并清单：namespace 无法作为非首语句拼接进 model.min.php，会导致生产模式解析致命错误（修复）
    APP_PATH . 'core/model/cate.php', APP_PATH . 'core/model/vod.php',
    APP_PATH . 'core/model/art.php', APP_PATH . 'core/model/tag.php',
    APP_PATH . 'core/model/like.php', APP_PATH . 'core/model/favorites.php',
    APP_PATH . 'core/model/link.php', APP_PATH . 'core/model/view.php',
    APP_PATH . 'core/model/ad.php',
    APP_PATH . 'core/model/api_import_batch.php',
    APP_PATH . 'core/model/block.php', APP_PATH . 'core/model/phpanalysis.php',
    APP_PATH . 'core/model/phpanalys.php', APP_PATH . 'core/model/top.php',
);

// 每个模型文件的「哨兵」定义（函数名；'class:' 前缀为类名）。用于：①model.min 构建时校验各段完整；
// ②include model.min 后运行期自检——缺任一哨兵（典型：杀毒把 core_model_view.php 清成 0 字节后被拼进 min，
// 报 Call to undefined function _file()）即删 min、补载缺失文件，站点当场自愈而不是永久 fatal。
$model_sentinels = array(
    'runtime.php' => 'runtime_init', 'kv.php' => 'kv_get', 'config_runtime.php' => 'config_runtime_all',
    'user.php' => 'user__create', 'session.php' => 'session_read', 'cate.php' => 'cate_create',
    'vod.php' => 'video__update', 'art.php' => 'art__update', 'tag.php' => 'tag__create',
    'like.php' => 'art_like', 'favorites.php' => 'art_favorites', 'link.php' => 'link_count',
    'view.php' => array('_file', 'view_load'), 'ad.php' => 'ad__create',
    'api_import_batch.php' => 'api_import_batch_table', 'phpanalysis.php' => 'class:PhpAnalysis',
    'phpanalys.php' => 'get_tag', 'top.php' => 'art_del',
);
$model_sentinel_missing = function ($file, $code = NULL) use ($model_sentinels) {
    $k = basename($file);
    if (empty($model_sentinels[$k])) return array();
    $miss = array();
    foreach ((array)$model_sentinels[$k] as $sym) {
        $isClass = (0 === strpos($sym, 'class:'));
        $name = $isClass ? substr($sym, 6) : $sym;
        if (NULL === $code) {
            $ok = $isClass ? class_exists($name, FALSE) : function_exists($name);
        } else {
            $ok = (bool)preg_match('#\\b' . ($isClass ? 'class' : 'function') . '\\s+' . preg_quote($name, '#') . '\\b#i', $code);
        }
        $ok or $miss[] = $name;
    }
    return $miss;
};

// 生产模式：使用合并缓存（仅加载 min 文件，不单独加载）
if (!DEBUG) {
    $model_min_file = $conf['cache_path'] . 'model.min.php';
    $model_min_mtime = is_file($model_min_file) ? filemtime($model_min_file) : 0;
    $need_build = empty($model_min_mtime);
    if (!$need_build && $model_min_mtime > 0) {
        foreach ($include_model_files as $model_files) {
            if (is_file($model_files) && filemtime($model_files) > $model_min_mtime) {
                $need_build = true; break;
            }
        }
    }
    // P1-14（上线审核轮）：min 内容是含 hook 内联的编译产物，模型源码 mtime 感知不到 hook 改动
    // ——必须并入签名失效门，否则 hook 变更后模型域永不刷新（DEBUG=0 下单文件产物无消费方）。
    if (!$need_build && $model_min_mtime > 0 && \App\Service\System\AddonService::hookSigStaleFor($model_min_file)) {
        $need_build = true;
    }
    if ($need_build) {
        $lockname = 'build_model_min';
        if (xn_lock_start($lockname, 30)) {
            $s = '';
            $build_bad = array();
            \App\Service\System\AddonService::$degraded = 0;
            foreach ($include_model_files as $model_files) {
                $t = @file_get_contents(_include($model_files));
                // 空段/读失败/缺哨兵定义 ⇒ 整次构建作废（原逻辑只跳过 false，空串照拼，
                // 拼出缺 _file() 的 min 并登记签名后永不重建 = 用户报告的永久 fatal）
                $emptyErr = \App\Service\System\AddonService::artifactEmptyError($t, $model_files);
                if (NULL !== $emptyErr) { $build_bad[] = basename($model_files) . ': ' . $emptyErr; continue; }
                $miss = $model_sentinel_missing($model_files, $t);
                if ($miss) { $build_bad[] = basename($model_files) . ': missing ' . implode(',', $miss); continue; }
                $t = trim($t);
                // ㉑-b：前缀化剥离——原 ltrim/rtrim 是字符集剥离（理论上会吞 php 关键字粘连形态的首字符）
                $t = preg_replace('/^<\?php\s?/', '', $t, 1);
                $t = preg_replace('/\?\>\s*$/', '', $t, 1);
                $s .= "<?php\r\n" . $t . "\r\n?>";
            }
            // 项目审查补闸：单文件产物各自过了 AddonService::artifactParseError()，但 19 合 1 的**拼接结果**
            // 此前**没有**任何语法闸，且写入返回值被忽略、写完立刻 hookSigSave() 登记签名再 include ——
            // 任一插件在 overwrite/core/model/（白名单前缀）留下未闭合结构，或某次 file_get_contents 读短，
            // 就会写出坏 model.min.php、签名还被"祝福"成当前版本（此后永不再重建）⇒ DEBUG=0 下全站白屏 500。
            // 现按「坏产物拒写 + 不登记签名」处理：跳过本次构建，直接走下面的单文件 include 兜底（站仍可用）。
            $parseErr = $build_bad ? ('incomplete parts: ' . implode(' | ', $build_bad)) : \App\Service\System\AddonService::artifactParseError($s);
            if (NULL !== $parseErr) {
                // 拒建时连同旧 min 一起删除（旧 min 可能正是缺段的坏产物），下面走单文件 include
                is_file($model_min_file) && @unlink($model_min_file);
                function_exists('opcache_invalidate') && @opcache_invalidate($model_min_file, TRUE);
                xn_log('index.inc.php: model.min.php build rejected :: ' . $parseErr, 'addon_compile_error');
                $need_build = false;
            } else {
                $written = file_put_contents_try($model_min_file, $s);
                // 同路径重写后让 opcache 立即丢弃旧编译结果（否则 revalidate_freq 窗口内仍执行旧的缺段 min）
                function_exists('opcache_invalidate') && @opcache_invalidate($model_min_file, TRUE);
                if (FALSE === $written || !is_file($model_min_file) || filesize($model_min_file) <= 0) {
                    // 写盘失败绝不登记签名：否则 is_file+filesize 判定落空，下次仍会重建（保持原语义）
                    xn_log('index.inc.php: model.min.php write failed', 'build_error');
                    $need_build = false;
                } elseif (\App\Service\System\AddonService::$degraded > 0) {
                    // 有段落降级为直接 include 源（未内联 hook）：产物可用，但不登记签名，有插件时下次请求重试编译
                    xn_log('index.inc.php: model.min.php built with degraded parts, signature not saved', 'addon_compile_error');
                    $need_build = false;
                }
            }
            xn_lock_end($lockname);
        } else {
            $wait = 20;
            while ($wait-- > 0) {
                if (is_file($model_min_file) && filesize($model_min_file) > 0) break;
                usleep(200000);
            }
        }
    }
    if (is_file($model_min_file) && filesize($model_min_file) > 0) {
        if ($need_build) { \App\Service\System\AddonService::hookSigSave($model_min_file); }   // P1-14：构建成功后登记签名
        include $model_min_file;
        // 运行期自检：旧版本可能已留下缺段的 min（如 view 段为空），此处发现即删 min + 清 0 字节单文件产物，
        // 并只补载缺哨兵的模型文件（其余已定义，重复 include 会 redeclare）
        $model_heal = array();
        foreach ($include_model_files as $model_files) {
            if ($model_sentinel_missing($model_files)) $model_heal[] = $model_files;
        }
        if ($model_heal) {
            xn_log('index.inc.php: model.min.php incomplete (' . implode(',', array_map('basename', $model_heal)) . '), self-healing', 'build_error');
            @unlink($model_min_file);
            function_exists('opcache_invalidate') && @opcache_invalidate($model_min_file, TRUE);
            foreach ((array)glob($conf['cache_path'] . 'core_model_*.php') as $model_cache_f) {
                if (is_file($model_cache_f) && 0 === (int)@filesize($model_cache_f)) @unlink($model_cache_f);
            }
            foreach ($model_heal as $model_files) {
                include _include($model_files);
            }
        }
        unset($model_heal, $model_cache_f);
    } else {
        // 开发模式或 min 文件不可用时：单独加载
        foreach ($include_model_files as $model_files) {
            include _include($model_files);
        }
    }
} else {
    // 开发模式或 min 文件不可用时：单独加载
    foreach ($include_model_files as $model_files) {
        include _include($model_files);
    }
}

// ============================================================
// 注入 KV 运行期配置 (替换后台动态业务配置对 config.php 的写操作)
// ============================================================
if (function_exists('config_runtime_apply')) {
    $conf = config_runtime_apply($conf);
}

// ============================================================
// 注入 KV 动态配置 (替换原有的 config.php 物理文件写操作)
// ============================================================
$kv_api = kv_cache_get('api');
if (is_array($kv_api)) $conf['api'] = $kv_api;

$kv_bind = kv_cache_get('bind');
if (is_array($kv_bind)) $conf['bind'] = $kv_bind;

$kv_vodplayer = kv_cache_get('vodplayer');
if (is_array($kv_vodplayer)) $conf['vodplayer'] = $kv_vodplayer;

// 站群修复：后台「网站设置」保存的 sitename 存在 KV 运行时配置里，上面 config_runtime_apply 会把
// index.php 按访问域名注入的站点名覆盖回主站名称（站群各站显示的都是主站名）。这里按域名重新套用一次。
if (!empty($siteconf) && is_array($siteconf) && isset($siteconf['site_name']) && '' !== (string)$siteconf['site_name']) {
    $conf['site_name'] = $conf['sitename'] = $siteconf['site_name'];
}
// 容器方案 P1：kv 合并后的 conf 终值统一经容器写入口（写透镜像同步 $_SERVER）
if (class_exists('App')) App::set('conf', $conf);

// hook index_inc_conf_after.php

// ============================================================
// 请求上下文初始化（原 index.inc.php 合并）
// 全局变量为 route/model/模板共享的请求上下文，保持语义
// ============================================================
$sid = sess_start();

// 中间件管道（XSS 过滤 + 限流）
// 必须在 sess_start() 之后执行，因为限流中间件依赖 session_id()
if (class_exists('\App\Service\System\MiddlewarePipelineService')) {
    if (!\App\Service\System\MiddlewarePipelineService::run()) {
        exit; // 被中间件拦截
    }
}

$_SERVER['lang'] = $lang = include _include(APP_PATH . 'core/lang/zh_cn.php');
$grouplist = group_list_cache();
$user = user_rest();
$uid = array_value($user, 'uid', 0);
$gid = array_value($user, 'gid', 0);
// 前台会员会话 Cookie 滑动续期（与后台入口共用 SessionService::slideCookie()）。
// 症状与后台第十五轮同源：nncms_token 一次性写 30 天（UserAuthService::tokenSet），
// 而承载 bull_token 指纹环的 PHP 会话只活 24h 且从不续发 → 会员「看着还是登录的」，
// 点赞/收藏/退出却一律被门禁拒掉。只给已登录会员续，匿名访客没有写操作要过闸，
// 给他们发 Set-Cookie 纯属污染响应头与缓存键。
// login/create/logout 三个动作自己 session_regenerate_id(true) 下发新 Cookie，此处让开。
if ($uid > 0 && !('user' === param(0, '') && in_array(param(1, ''), array('login', 'create', 'logout'), TRUE))) {
    \App\Service\Account\SessionService::slideCookie();
}
$group = isset($grouplist[$gid]) ? $grouplist[$gid] : $grouplist[0];

// hook index_inc_user_after.php

$config = setting_get('conf');
$cid = 0;
$catelist = cate_list_cache();
$cid_count = is_array($catelist) ? count($catelist) : 0;
$catearr = arrlist_key_values($catelist, 'cid', 'name');
$catelist_show = $catelist;
$cate_nav = category_tree($catelist);
$nav_active = 0; $nav_route = ''; $nav_home = 0; $nav_pku = 0;
$api_json['catelist'] = $cate_nav;
$runtime = runtime_init();
check_runlevel();

// hook index_inc_cate_after.php
$txt_len = strpos($_SERVER['REQUEST_URI'], '.txt');
if (str_contains($_SERVER['REQUEST_URI'], 'sitemap') && $txt_len) {
    $request_url = mb_substr($_SERVER['REQUEST_URI'], 0, $txt_len, 'UTF-8');
    $request_url = trim($request_url, '/');
    if ($request_url) {
        $request_url = str_replace('/', '-', $request_url);
        $requestlist = explode('-', $request_url);
        if (empty($requestlist) || !is_array($requestlist)) exit('Access Error.');
        // Preserve existing query params; only map route segments
        foreach ($requestlist as $k => $v) {
            $_REQUEST[$k] = $v;
        }
        $_REQUEST[0] = 'sitemap';
    }
}
// /sitemap.xml → sitemap 路由的 xml 分支（index.php 已放行该路径，不走静态文件通道）
if ('/sitemap.xml' === strtolower((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH))) {
    $_REQUEST[0] = 'sitemap';
    $_REQUEST[1] = 'xml';
}
$areaarray = explode(',', $conf['area']);
$yeararray = explode(',', $conf['year']);
$langarray = explode(',', $conf['lang']);
$route_type = $conf['route']['type'];
$route_list = $conf['route']['list'];
$route_cate = $conf['route']['cate'];
$route_vod = $conf['route']['vod'];
$route_play = $conf['route']['play'];
$route_tag = $conf['route']['tag'];
$route_art = $conf['route']['art'];
$route = param(0, 'index');

// 别名路由解析（伪静态 ≥2 时按分类别名映射路由）
// 'page' 必须在列（复核修正）：单页路由前缀若被分类别名占用，别名解析（本文件下方）会先于路由
// 分发把它改写成 list/vod 路由，整条单页路由被劫持（/page-about.html → list.php 404）。
$alias_reservation = array('index', 'admin', 'user', 'search', 'sitemap', 'page');
if ($conf['url_rewrite_on'] > 1 && !in_array($route, $alias_reservation)) {
    $aliaslist = cate_alias_cache();
    $alias_cid = array_value($aliaslist, xn_urldecode($route), 0); // 别名 URL 经 xn_urlencode 编码（_ → _5f），此处解码回原始 alias（审计修复）
    if ($alias_cid) {
        $alias_cate = cate_read($alias_cid);
        $alias_param1 = param(1);
        $alias_param = explode('_', $alias_param1);
        if (!$alias_param1 || ('list'  == $alias_param[0] && is_numeric($alias_param[1]))) {
            $route = $_REQUEST[0] = 1 == $alias_cate['category'] ? $route_cate : $route_list;
            $_REQUEST[1] = $alias_cid;
            isset($alias_param[1]) and $_REQUEST[2] = $alias_param[1];
        } else {
            if ($alias_cate['model'] == 1) {
                $route = $_REQUEST[0] = $route_art;
            } else {
                $param2 = param(2, '');
                $param3 = param(3, '');
                if (str_contains($param2, '_')) {
                    $route = $_REQUEST[0] = $route_type;
                } elseif ($param3 == '') {
                    $route = $_REQUEST[0] = $route_vod;
                } else {
                    $route = $_REQUEST[0] = $route_play;
                }
            }
        }
    }
}
$path_tpl = \App\Service\System\TemplateService::themePublicPath($config['theme'] ?? '');
$maccms =$nncms = array(
    'site_name' => $conf['sitename'],
    'path_tpl' => $path_tpl,
    'v'=>findcate($catelist, 0),
    'a'=>findcate($catelist, 1),
    'site_url' => http_url_path(),
    'site_email' => $conf['email'],
    'logo' => $conf['logo_pc'],
    'path' => http_url_path(),
    'tj' => $conf['agg'],
    'type'=>$catelist,
);
$nncms +=$runtime;
$cacheDriver = class_exists("MultiLayerCache", false) ? "MultiLayerCache" : (isset($_SERVER["cache"]) ? get_class($_SERVER["cache"]) : "none");
$nncms["debug_mode"] = "DEBUG=" . DEBUG . " | Cache: " . $cacheDriver;
if (0 == $conf['url_rewrite_on']) {
    $search_url = '/' . url('search');
} elseif (1 == $conf['url_rewrite_on']) {
    $search_url = '/' . url('search');
} elseif (2 == $conf['url_rewrite_on'] || 3 == $conf['url_rewrite_on']) {
    $search_url = url('search');
}
$user_like = url('member-like');
$user_favorite = url('member-favorite');
$user_login = url('user-login');
$user_create = url('user-create');
$user_index = url('member-index');
$user_logout = url('user-logout', array('safe_token' => bull_token_set($uid, 'user_logout')));
$sitemap_url = url('sitemap-list');
$danmu_list = url('danmu-list');
$danmu_send = url('danmu-send');
// 弹幕令牌的「现签」端点（POST）。匿名访客在 L0 缓存页面上拿不到会话 Cookie，
// 页面内嵌的令牌对其浏览器无效，播放器需在该端点上取一次才发得出弹幕（第二十二轮）
$danmu_token_api = url('danmu-token');
// 手机端底部导航「片库」链接（修复：此前 $pku_url 从未被赋值 → href 恒空）
$pku_url = url($conf['route']['type'] . '-' . intval(\App\Service\Front\TypeService::defaultCid($catelist)) . '___', '', FALSE);
// 后台入口模板变量：前台模板编译期剥离原生 PHP（tpl_allow_php=0，view.php 编译规则），
// 「后台管理」等入口链接统一走模板变量（修复：此前模板内裸写 PHP 输出指令，编译后被清空导致链接为空）
$admin_entry = !empty($conf['admin_entry']) ? $conf['admin_entry'] : 'admin.php';
if (isset($domain['site'][$hosts]) && is_array($domain['site'][$hosts])) {
    if (!empty($siteconf['path'])) {
        $config['theme'] = $siteconf['path'];
        $nncms['path_tpl'] = $maccms['path_tpl'] = $path_tpl = \App\Service\System\TemplateService::themePublicPath($siteconf['path']);
    }
}
// 队列仅由独立 CLI worker 或后台显式执行，普通网页请求不消费任务。
\App\Service\Collection\ApiExecutionService::registerTaskHandlers();
// ============================================================
// 插件运行期引导（R3-1）：enabled 插件的 bootstrap.php 在路由分发前装载，
// 插件经 App::listen 注册运行期事件监听；完成后触发 'addon.boot' 事件。
// disabled_addons=1 或无启用插件时零行为（enabledPaths 一次 glob）。
// 位于 SKIP_ROUTE 判定之前：后台入口（SKIP_ROUTE=1）同样获得引导。
// ============================================================
\App\Service\System\AddonService::boot();

// hook index_inc_route_before.php

if (!defined('SKIP_ROUTE')) {
    $route_map = array(
        'index' => 'index.php',
        $route_type => 'type.php',
        $route_cate => 'category.php',
        $route_list => 'list.php',
        $route_vod => 'vod.php',
        $route_play => 'play.php',
        $route_tag => 'tag.php',
        $route_art => 'art.php',
        'search' => 'search.php',
        'sitemap' => 'sitemap.php',
        'user' => 'user.php',
        'member' => 'member.php',
        'danmu' => 'danmu.php',
        'page' => 'page.php',   // category=2 单页（审查修复轮 A4：正式路由，取代原先的 index 兼容分支）
    );
    // hook index_route_map_after.php

    // 未知路由 → 404（此前一律落首页 200；审计修复）
    if (isset($route_map[$route])) {
        $route_file = $route_map[$route];
    } else {
        // hook index_route_default.php
        http_response_code(404);
        include _include(APP_PATH . '404.html');
        exit;
    }
    // P1-8 路由 meta 声明式前置校验（auth/throttle），不改变既有业务校验
    $route_action = param(1, '');
    if (defined('DEBUG') && class_exists('\App\Service\Front\RouteMetaService')) {
        \App\Service\Front\RouteMetaService::check($route, (string)$route_action, intval($uid ?? 0), $method);
    }
    include _include(APP_PATH . 'route/' . $route_file);
}

// hook index_inc_end.php
