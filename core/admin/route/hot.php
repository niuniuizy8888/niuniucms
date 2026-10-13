<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_hot_start.php
FALSE === group_access($gid, 'managehot') and message(1, lang('user_group_insufficient_privilege'));

$action = param(1, 'search');

if ('GET' == $method) {
    $filters = array(
        'page' => param('page', 1),
        'keyword' => trim(param('keyword')),
    );
    extract(\App\Service\Admin\HotManageService::searchPage($filters, $uid), EXTR_SKIP);
    include _include(ADMIN_PATH . 'html/hot_search.html');
    exit;
}

if ('POST' == $method) {
    $type = admin_param_int('type');
    if (1 == $type) {
        \App\Service\Admin\HotManageService::delete($uid);
        message(0, lang('delete_successfully'));
    } elseif (2 == $type) {
        \App\Service\Admin\HotManageService::truncate($uid);
        message(0, '已清空');
    } else {
        message(-1, lang('parameter_error'));
    }
}

