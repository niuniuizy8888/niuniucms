<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_addon_start.php
// R0-4 权限收敛：插件启停 = hook 内容内联进编译产物 + overwrite 白名单内文件替换 = RCE 级权限，
// 原挂 managevod（视频管理位）属权限错配 → 专用 manageplugin（gid=1 管理员恒过，GroupService::access fail-closed）。
// 存量站点需先补列（部署侧，一次执行）：
//   ALTER TABLE nncms_group ADD COLUMN manageplugin int(10) unsigned NOT NULL DEFAULT '0';
// 未补列前：非 gid=1 管理员的插件管理入口为拒绝态（fail-closed，不会误放权）。
FALSE === group_access($gid, 'manageplugin') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
if ('GET' == $method) {
    if ('setting' === $action) {
        // R0-3：插件设置页（原 setting_url 生成指向不存在的 addons-setting 路由 = 死链）。
        // 设置页渲染在后台 head/foot 包裹内；插件需要全屏控制时自行输出并 exit()。
        $header = array('title' => '插件设置');
        $dir = admin_param_text('dir', '', 128);
        (is_string($dir) && 1 === preg_match('#^[A-Za-z0-9_-]+$#', $dir)) or message(-1, lang('data_malformation'));
        $settingfile = APP_PATH . 'plugins/' . $dir . '/setting.php';
        is_file($settingfile) or message(-1, lang('not_exists'));
        // 注：插件设置页渲染在后台 head/foot 包裹内，但各 plugin/setting.php 仅输出 <div class="card">，
        // 未套 .main-content.app-content/.side-app 容器 → 内容未按侧栏偏移、页脚/页头错位。
        // 与 addon.html 内容页同口径补容器（统一渲染边界，论坛/直播/公告三插件同修）。
        include _include(ADMIN_PATH . 'html/head.html');
        echo '<div class="main-content app-content">' . "\n" . '    <div class="side-app">' . "\n";
        include _include($settingfile);
        echo "\n" . '    </div>' . "\n" . '</div>' . "\n";
        include _include(ADMIN_PATH . 'html/foot.html');
    } else {
        extract(\App\Service\Admin\AddonManageService::listPage($uid), EXTR_SKIP);
        include _include(ADMIN_PATH . 'html/addon.html');
    }
} elseif ('POST' == $method) {
    if ('upload' === $action) {
        // R2-3 客户端插件上传安装链（zip 包，条目闸+verifyAddonCode，落位后未启用）
        \App\Service\Admin\AddonManageService::upload($uid);
    } elseif ('setting' === $action) {
        // N-03（上线前审计）：插件设置页保存通路——设置表单为空 action 回提交到
        // action=setting 的当前 URL，此前落入 toggle() 的 op 白名单被拒（fail-closed），三插件
        // 的 kv_set 永不可达。插件 setting.php 自持 POST 处理（fAct 分发 + 插件场景令牌 +
        // admin_audit_log，保存后 message/jump 退出），与 GET 分支同契约。
        $dir = admin_param_text('dir', '', 128);
        (is_string($dir) && 1 === preg_match('#^[A-Za-z0-9_-]+$#', $dir)) or message(-1, lang('data_malformation'));
        $settingfile = APP_PATH . 'plugins/' . $dir . '/setting.php';
        is_file($settingfile) or message(-1, lang('not_exists'));
        include _include($settingfile);
    } else {
        \App\Service\Admin\AddonManageService::toggle($uid);
    }
}

