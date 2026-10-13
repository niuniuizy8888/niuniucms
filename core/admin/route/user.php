<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_user_start.php
FALSE === group_access($gid, 'manageuser') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
$system_group = array(0, 1, 2); 
switch ($action) {
    case 'list':
        $filters = array(
            'page' => param('page', 1),
            'srchtype' => param('srchtype'),
            'keyword' => trim(param('keyword')),
        );
        extract(\App\Service\Admin\UserManageService::listPage($filters, $grouplist, $uid), EXTR_SKIP);
        include _include(ADMIN_PATH . 'html/user_list.html');
        break;
    case 'create': 
        if ('GET' == $method) { 
            extract(\App\Service\Admin\UserManageService::createPage($grouplist, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/user_create.html');
        } elseif ('POST' == $method) {
            \App\Service\Admin\UserManageService::create($uid, $gid, $longip, $time);
            message(0, lang('create_successfully'));
        }
        break;
    case 'update':
        $_uid = param('uid', 0);
        if ('GET' == $method) {
            extract(\App\Service\Admin\UserManageService::updatePage($_uid, $grouplist, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/user_update.html');
        } elseif ('POST' == $method) {
            \App\Service\Admin\UserManageService::update($_uid, $uid, $gid);
            message(0, lang('update_successfully'));
        }
        break;
    case 'delete':
        if ('POST' != $method) message(-1, lang('method_error'));
        $_uid = param('uid', 0);
        \App\Service\Admin\UserManageService::delete($_uid, $uid, $gid);
        message(0, lang('delete_successfully'));
        break;
     case 'group_list': 
        if ('GET' == $method) { 
            extract(\App\Service\Admin\UserManageService::groupListPage($grouplist, $system_group, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/user_group_list.html');
        } elseif ('POST' == $method) {
            \App\Service\Admin\UserManageService::saveGroups($grouplist, $system_group, $uid, $gid);
            message(0, lang('save_successfully'));
        }
        break;
    case 'group_update':
        $_gid = param('gid', 0);
        if ('GET' == $method) {
            extract(\App\Service\Admin\UserManageService::groupUpdatePage($_gid, $uid, $gid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/user_group_update.html');
        } elseif ('POST' == $method) {
            \App\Service\Admin\UserManageService::updateGroup($_gid, $uid, $gid);
            message(0, lang('edit_successfully'));
        }
        break; 
    default:
        message(-1, lang('data_malformation'));
        break;
}
// hook admin_user_end.php

