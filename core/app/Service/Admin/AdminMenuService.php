<?php
namespace App\Service\Admin;

/**
 * 后台侧边栏菜单注册表（容器方案 P3.2 落地）
 *
 * 取代 core/admin/html/partials/sidebar.html 的硬编码菜单：
 * - items() 构建结构化菜单（含 active/visible 解析），经 App::fire('admin.menu.items', $items)
 *   暴露给插件/代码追加与修改（0 监听 = 与原硬编码模板逐字节一致）；
 * - render() 机械拼接为与原模板逐字节等价的 HTML（改动拼接规则时必须保持这一等价性，
 *   否则后台菜单会出现样式/结构回归）。
 *
 * 监听器约定（信任边界 = 受信代码，与 AddonService 编译钩子一致）：
 *   App::listen('admin.menu.items', function (array $items) {
 *       $items[] = array(
 *           'comment' => '<!-- 我的插件 -->',
 *           'li_class' => 'slide',
 *           // ⚠ 路由名必须是后台入口（admin.php）的 $route_map 里真实存在的键；url() 不校验路由名，
 *           //    写个不存在的名字（如曾在此示例里的 myplugin-list）不会报错，只会渲染出 404 链接。
 *           'anchor_open' => '<a class="side-menu__item has-link" href="' . url('addon-setting', array('dir' => 'myplugin'), TRUE) . '" data-label="我的插件">',
 *           'icon' => '<svg ...></svg>',
 *           'label' => '我的插件',
 *       );
 *       return $items;
 *   });
 * 字段：comment（可选，前置 HTML 注释）/ li_class（li 的 class 全量，含 active 态）/ li_style（可选）
 *      / anchor_open（已解析的 <a …> 开标签，含 class/active）/ icon（svg）/ label / angle（bool，折叠箭头）
 *      / badge（可选，label 后追加的 HTML，如徽标）/ children（可选：anchor_open + label）/ visible（bool，默认 true）
 * ⚠️ anchor_open/label 由监听方自行转义——菜单注册表属受信代码边界（同后台模板现状），不接入用户输入。
 */
class AdminMenuService
{
    /** @var string 折叠箭头（toggle 项专有） */
    private static $angle = '<svg class="angle" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>';

    /**
     * 渲染侧边栏（sidebar.html 唯一出口）
     * @return string 与原硬编码模板逐字节等价的 HTML
     */
    public static function sidebar()
    {
        global $route, $action, $conf, $version, $cloud_version, $nnzs, $uid;
        $items = self::items($route, $action, $conf, $version, $cloud_version, $nnzs, $uid);
        return self::render($items, $route, $action);
    }

    /**
     * 构建菜单项（active/visible 在此解析为终值；经事件过滤后返回）
     */
    public static function items($route, $action, $conf, $version, $cloud_version, $nnzs, $uid)
    {
        $items = array();

        $items[] = array('type' => 'label', 'label' => '主菜单', 'blank' => false);

        // 仪表盘
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 仪表盘 -->',
            'li_class' => 'slide ' . ($route == 'index' ? 'is-expanded active' : ''),
            'anchor_open' => '<a class="side-menu__item ' . ($route == 'index' ? 'active' : '') . ' has-link"' . "\n" . '                   href="' . ADMIN_URL . '" data-label="' . lang('index') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
            'label' => lang('index'),
        );

        // 视频管理
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 视频管理 -->',
            'li_class' => 'slide ' . ($route == 'vod' ? 'is-expanded' : ''),
            'anchor_open' => '<a class="side-menu__item ' . ($route == 'vod' ? 'active' : '') . '" data-bs-toggle="slide" href="javascript:void(0)" data-label="' . lang('vod') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>',
            'label' => lang('vod'),
            'angle' => true,
            'children' => array(
                array('anchor_open' => '<a href="' . url('vod-list', '', TRUE) . '" class="slide-item ' . ($action == 'list' ? 'active' : '') . '">', 'label' => lang('vod_page')),
                array('anchor_open' => '<a href="' . url('vod-posterlist', '', TRUE) . '" class="slide-item ' . ($action == 'posterlist' ? 'active' : '') . '">', 'label' => lang('vod_poster')),
                array('anchor_open' => '<a href="' . url('vod-duplicate', '', TRUE) . '" class="slide-item ' . ($action == 'duplicate' ? 'active' : '') . '">', 'label' => '重复检测'),
            ),
        );

        // 文章管理
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 文章管理 -->',
            'li_class' => 'slide ' . ($route == 'art' ? 'is-expanded' : ''),
            'anchor_open' => '<a class="side-menu__item ' . ($route == 'art' ? 'active' : '') . '" data-bs-toggle="slide" href="javascript:void(0)" data-label="' . lang('art') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
            'label' => lang('art'),
            'angle' => true,
            'children' => array(
                array('anchor_open' => '<a href="' . url('art-list', '', TRUE) . '" class="slide-item ' . ($action == 'list' ? 'active' : '') . '">', 'label' => lang('art_page')),
            ),
        );

        // 分类管理
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 分类管理 -->',
            'li_class' => 'slide ' . ($route == 'cate' ? 'is-expanded active' : ''),
            'anchor_open' => '<a class="side-menu__item has-link ' . ($route == 'cate' ? 'active' : '') . '"' . "\n" . '                   href="' . url('cate-list', '', TRUE) . '" data-label="' . lang('cate') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
            'label' => lang('cate'),
        );

        // 采集管理
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 采集管理 -->',
            'li_class' => 'slide ' . ($route == 'api' ? 'is-expanded' : ''),
            'anchor_open' => '<a class="side-menu__item ' . ($route == 'api' ? 'active' : '') . '" data-bs-toggle="slide" href="javascript:void(0)" data-label="' . lang('api') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
            'label' => lang('api'),
            'angle' => true,
            'children' => array(
                array('anchor_open' => '<a href="' . url('api-list', '', TRUE) . '" class="slide-item ' . ($action == 'list' ? 'active' : '') . '">', 'label' => '视频' . lang('api')),
                array('anchor_open' => '<a href="' . url('api-art_list', '', TRUE) . '" class="slide-item ' . ($action == 'art_list' ? 'active' : '') . '">', 'label' => '文章' . lang('api')),
            ),
        );

        $items[] = array('type' => 'label', 'label' => '数据管理', 'blank' => true);

        // 用户管理
        $items[] = array(
            'comment' => '<!-- 用户管理 -->',
            'li_class' => 'slide ' . ($route == 'user' ? 'is-expanded' : ''),
            'anchor_open' => '<a class="side-menu__item ' . ($route == 'user' ? 'active' : '') . '" data-bs-toggle="slide" href="javascript:void(0)" data-label="' . lang('user') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'label' => lang('user'),
            'angle' => true,
            'children' => array(
                array('anchor_open' => '<a href="' . url('user-list', '', TRUE) . '" class="slide-item ' . (($action == 'list' || $action == 'update') ? 'active' : '') . '">', 'label' => lang('user_admin')),
                array('anchor_open' => '<a href="' . url('user-create', '', TRUE) . '" class="slide-item ' . ($action == 'create' ? 'active' : '') . '">', 'label' => lang('admin_user_create')),
                array('anchor_open' => '<a href="' . url('user-group_list', '', TRUE) . '" class="slide-item ' . ($action == 'group_list' ? 'active' : '') . '">', 'label' => lang('group_admin')),
            ),
        );

        // 友情链接
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 友情链接 -->',
            'li_class' => 'slide ' . ($route == 'link' ? 'is-expanded active' : ''),
            'anchor_open' => '<a class="side-menu__item has-link ' . ($route == 'link' ? 'active' : '') . '"' . "\n" . '                   href="' . url('link', '', TRUE) . '" data-label="' . lang('friends__link') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
            'label' => lang('friends__link'),
        );

        // 广告管理（补入口）
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 广告管理（补入口） -->',
            'li_class' => 'slide ' . ($route == 'ad' ? 'is-expanded active' : ''),
            'anchor_open' => '<a class="side-menu__item has-link ' . ($route == 'ad' ? 'active' : '') . '"' . "\n" . '                   href="' . url('ad-list', '', TRUE) . '" data-label="广告管理">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><polyline points="16 21 16 11 8 21"/></svg>',
            'label' => '广告管理',
        );

        // 回收站（补入口）
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 回收站（补入口） -->',
            'li_class' => 'slide ' . ($route == 'recycle' ? 'is-expanded active' : ''),
            'anchor_open' => '<a class="side-menu__item has-link ' . ($route == 'recycle' ? 'active' : '') . '"' . "\n" . '                   href="' . url('recycle-list', '', TRUE) . '" data-label="回收站">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
            'label' => '回收站',
        );

        // 模板管理
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 模板管理 -->',
            'li_class' => 'slide ' . ($route == 'tmpl' ? 'is-expanded' : ''),
            'anchor_open' => '<a class="side-menu__item ' . ($route == 'tmpl' ? 'active' : '') . '" data-bs-toggle="slide" href="javascript:void(0)" data-label="' . lang('tmpl') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
            'label' => lang('tmpl'),
            'angle' => true,
            'children' => array(
                array('anchor_open' => '<a href="' . url('tmpl-cloud', '', TRUE) . '" class="slide-item ' . (($route == 'tmpl' && $action == 'cloud') ? 'active' : '') . '">', 'label' => lang('tmpl_page_cloud')),
                array('anchor_open' => '<a href="' . url('tmpl-list', '', TRUE) . '" class="slide-item ' . (($route == 'tmpl' && $action == 'list') ? 'active' : '') . '">', 'label' => lang('tmpl_page')),
                array('anchor_open' => '<a href="' . url('tmpl-file', '', TRUE) . '" class="slide-item ' . (($route == 'tmpl' && $action == 'file') ? 'active' : '') . '">', 'label' => lang('tmpl_editor')),
            ),
        );

        $items[] = array('type' => 'label', 'label' => '系统', 'blank' => true);

        // 系统设置
        $items[] = array(
            'comment' => '<!-- 系统设置 -->',
            'li_class' => 'slide ' . ($route == 'set' ? 'is-expanded' : ''),
            'anchor_open' => '<a class="side-menu__item ' . ($route == 'set' ? 'active' : '') . '" data-bs-toggle="slide" href="javascript:void(0)" data-label="' . lang('setting') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'label' => lang('setting'),
            'angle' => true,
            'children' => array(
                array('anchor_open' => '<a href="' . url('set-set', '', TRUE) . '" class="slide-item ' . ($action == 'set' ? 'active' : '') . '">', 'label' => lang('set_web')),
                array('anchor_open' => '<a href="' . url('set-seo', '', TRUE) . '" class="slide-item ' . ($action == 'seo' ? 'active' : '') . '">', 'label' => lang('set_seo')),
                array('anchor_open' => '<a href="' . url('set-player', '', TRUE) . '" class="slide-item ' . ($action == 'player' ? 'active' : '') . '">', 'label' => lang('player_set')),
                array('anchor_open' => '<a href="' . url('set-danmu', '', TRUE) . '" class="slide-item ' . ($action == 'danmu' ? 'active' : '') . '">', 'label' => lang('danmu_set')),
                array('anchor_open' => '<a href="' . url('set-domain', '', TRUE) . '" class="slide-item ' . ($action == 'domain' ? 'active' : '') . '">', 'label' => lang('set_domain')),
                array('anchor_open' => '<a href="' . url('set-route', '', TRUE) . '" class="slide-item ' . ($action == 'route' ? 'active' : '') . '">', 'label' => lang('route_set')),
                array('anchor_open' => '<a href="' . url('set-rw', '', TRUE) . '" class="slide-item ' . ($action == 'rw' ? 'active' : '') . '">', 'label' => lang('rw_set')),
                array('anchor_open' => '<a href="' . url('set-op', '', TRUE) . '" class="slide-item ' . ($action == 'op' ? 'active' : '') . '">', 'label' => lang('set_op')),
                array('anchor_open' => '<a href="' . url('set-cache', '', TRUE) . '" class="slide-item ' . ($action == 'cache' ? 'active' : '') . '">', 'label' => lang('set_cache')),
                array('anchor_open' => '<a href="' . url('set-throttle', '', TRUE) . '" class="slide-item ' . ($action == 'throttle' ? 'active' : '') . '">', 'label' => '限速设置'),
                array('anchor_open' => '<a href="' . url('set-es', '', TRUE) . '" class="slide-item ' . ($action == 'es' ? 'active' : '') . '">', 'label' => lang('es_set')),
                array('anchor_open' => '<a href="' . url('set-rm', '', TRUE) . '" class="slide-item ' . ($action == 'rm' ? 'active' : '') . '">', 'label' => '清空数据'),
            ),
        );

        // 热门搜索
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 热门搜索 -->',
            'li_class' => 'slide ' . ($route == 'hot' ? 'is-expanded active' : ''),
            'anchor_open' => '<a class="side-menu__item has-link ' . ($route == 'hot' ? 'active' : '') . '" href="' . url('hot-search', '', TRUE) . '" data-label="热门搜索">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
            'label' => '热门搜索',
        );

        // 任务管理（补入口，含采集队列）
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 任务管理（补入口，含采集队列） -->',
            'li_class' => 'slide ' . ($route == 'task' ? 'is-expanded active' : ''),
            'anchor_open' => '<a class="side-menu__item has-link ' . ($route == 'task' ? 'active' : '') . '"' . "\n" . '                   href="' . url('task-list', '', TRUE) . '" data-label="任务管理">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
            'label' => '任务管理',
        );

        // 插件管理（补入口）
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 插件管理（补入口） -->',
            'li_class' => 'slide ' . ($route == 'addon' ? 'is-expanded active' : ''),
            'anchor_open' => '<a class="side-menu__item has-link ' . ($route == 'addon' ? 'active' : '') . '"' . "\n" . '                   href="' . url('addon-list', '', TRUE) . '" data-label="插件管理">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
            'label' => '插件管理',
        );

        // 快速采集（条件显示：$conf['fast_api']==0，与原模板同为松散比较）
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 快速采集（条件显示）-->',
            'blank' => true,
            'li_class' => 'slide',
            'anchor_open' => '<a class="side-menu__item has-link" href="' . url('api-fastapi', '', TRUE) . '" data-label="快速采集">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            'label' => '快速采集',
            'li_pre' => '            ',
            'tail_ws' => '            ',
            'visible' => ($conf['fast_api'] == 0),
        );

        // 在线升级（条件显示：$version != $cloud_version）
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 在线升级（条件显示）-->',
            'blank' => true,
            'li_class' => 'slide',
            'anchor_open' => '<a class="side-menu__item has-link" href="' . url('set-upgrade', '', TRUE) . '" data-label="' . lang('online_upgrade') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
            'label' => lang('online_upgrade'),
            'li_pre' => '            ',
            'tail_ws' => '            ',
            'visible' => ($version != $cloud_version),
        );

        // 替换信息（条件显示：$nnzs['replist'] 计数 > 0，带徽标）
        $repn = count($nnzs['replist'] ?? array());
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 替换信息（条件显示）-->',
            'blank' => true,
            'li_class' => 'slide',
            'anchor_open' => '<a class="side-menu__item has-link" href="' . url('set-rep', '', TRUE) . '" data-label="' . lang('rep') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
            'label' => lang('rep'),
            'badge' => $repn > 0 ? '<span class="badge bg-danger side-badge">' . $repn . '</span>' : '',
            'li_pre' => '            ',
            'tail_ws' => '            ',
            'visible' => ($repn > 0),
        );

        // 退出登录
        $items[] = array(
            'blank' => true,
            'comment' => '<!-- 退出登录 -->',
            'blank' => true,
            'li_class' => 'slide',
            'li_style' => ' style="margin-top:auto;"',
            'anchor_open' => '<a class="side-menu__item has-link" href="' . url('index-logout', array('safe_token' => bull_token_set($uid, 'admin_logout')), TRUE) . '" data-label="' . lang('logout') . '">',
            'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
            'label' => lang('logout'),
        );

        return \App::fire('admin.menu.items', $items);
    }

    /**
     * 机械渲染（拼接规则必须与原模板逐字节对齐——改这里就要同步核对后台菜单渲染结果）
     */
    public static function render($items, $route, $action)
    {
        $html = "<!-- APP-SIDEBAR -->\n";
        $html .= "<div class=\"app-sidebar\">\n";
        $html .= "    <div class=\"side-header\">\n";
        $html .= "        <a class=\"header-brand1\" href=\"" . ADMIN_URL . "\">\n";
        $html .= "            <img src=\"/static/logo.png\" alt=\"logo\" style=\"height:30px;\">\n";
        $html .= "            <span class=\"side-brand-text\">" . APP_NAME . "</span>\n";
        $html .= "        </a>\n";
        $html .= "    </div>\n";
        $html .= "    <div class=\"main-sidemenu\">\n";
        $html .= "        <ul class=\"side-menu\">\n";
        foreach ((array)$items as $it) {
            if (!empty($it['blank'])) $html .= "\n";
            if (($it['type'] ?? 'item') === 'label') {
                $html .= "            <li class=\"side-label\">" . $it['label'] . "</li>\n";
                continue;
            }
            if (isset($it['visible']) && $it['visible'] === false) {
                // 隐藏态：注释行在原模板 if 块之外（恒渲染），if 行与收尾行各遗留 12 空格（换行被 PHP 吞掉）
                if (!empty($it['comment'])) $html .= "            " . $it['comment'] . "\n";
                $html .= '            '; // 仅 if 行前导空格；if 体内（含收尾行缩进）整体被跳过
                continue;
            }
            if (!empty($it['comment'])) $html .= "            " . $it['comment'] . "\n";
            $html .= isset($it['li_pre']) ? $it['li_pre'] : ''; // 条件项 if 行前导 12 空格（换行被 PHP 吞掉）
            $style = isset($it['li_style']) ? $it['li_style'] : '';
            $html .= '            <li class="' . $it['li_class'] . '"' . $style . ">\n";
            $html .= '                ' . $it['anchor_open'] . "\n";
            $html .= "                    <span class=\"side-menu__icon\">\n";
            $html .= '                        ' . $it['icon'] . "\n";
            $html .= "                    </span>\n";
            $html .= '                    <span class="side-menu__label">' . $it['label'] . "</span>\n";
            if (!empty($it['badge'])) $html .= '                    ' . $it['badge'] . "\n";
            if (!empty($it['angle'])) $html .= '                    ' . self::$angle . "\n";
            $html .= "                </a>\n";
            if (!empty($it['children'])) {
                $html .= "                <ul class=\"slide-menu\">\n";
                foreach ($it['children'] as $ch) {
                    $html .= '                    <li>' . $ch['anchor_open'] . $ch['label'] . "</a></li>\n";
                }
                $html .= "                </ul>\n";
            }
            $html .= "            </li>\n";
            $html .= isset($it['tail_ws']) ? $it['tail_ws'] : ''; // 条件项收尾行前导 12 空格（换行被 PHP 吞掉，不得在注释写关闭标签）
        }
        $html .= "\n        </ul>\n"; // 原模板 </ul> 前的空行（250 行）
        $html .= "    </div>\n";
        $html .= "</div>\n";
        return $html;
    }
}
