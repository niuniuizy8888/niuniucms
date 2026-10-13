<?php
/* 体育直播 · 运行期引导（bootstrap.php，enabled 时由 AddonService::boot() 装载）
 * 职责：向后台侧栏菜单注册表注入「直播设置」直达链接（与论坛管理同机制）。
 * 事件契约：admin.menu.items（字段说明见 AdminMenuService 类头注释）。 */
if (!defined('APP_PATH')) {
    exit;
}

\App::listen('admin.menu.items', function (array $items) {
    $cfg = kv_get('plugin_live');
    if (empty($cfg['on'])) {
        return $items;
    }
    $gid = isset($GLOBALS['gid']) ? intval($GLOBALS['gid']) : 0;
    // 管理端渲染时模型函数必然在位；防御性 function_exists 兜底（异常装配环境下宁可不显示菜单，不可白屏）
    if (1 !== $gid && function_exists('group_access') && FALSE === group_access($gid, 'manageplugin')) {
        return $items;
    }
    $isHere = ('addon' === param(0, '') && 'setting' === param(1, '') && 'live' === param('dir', ''));
    $items[] = array(
        'blank' => true,
        'comment' => '<!-- 体育直播（插件注入） -->',
        'li_class' => 'slide ' . ($isHere ? 'active' : ''),
        'anchor_open' => '<a class="side-menu__item ' . ($isHere ? 'active' : '') . ' has-link" href="' . url('addon-setting', array('dir' => 'live'), TRUE) . '" data-label="直播设置">',
        'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>',
        'label' => '直播设置',
    );
    return $items;
});
