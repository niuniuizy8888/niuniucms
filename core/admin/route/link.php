<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_link_start.php
FALSE === group_access($gid, 'managelink') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
if ('GET' == $method) {
    extract(\App\Service\Admin\LinkManageService::listPage($uid), EXTR_SKIP);
    include _include(ADMIN_PATH . 'html/link.html');
} elseif ('POST' == $method) {
    $type = admin_param_int('type');
    if (1 == $type) {
        \App\Service\Admin\LinkManageService::create($uid, $time);
        message(0, lang('create_successfully'));
    } elseif (2 == $type) {
        \App\Service\Admin\LinkManageService::sort($uid);
        message(0, lang('update_successfully'));
    } else {
        \App\Service\Admin\LinkManageService::delete($uid);
        message(0, lang('delete_successfully'));
    }
}

