<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_cate_start.php
FALSE === group_access($gid, 'managecate') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
$header['title'] = lang('cate_page');
$catelist = cate_list_cache();
switch ($action) {
    case 'list':
        if ('GET' == $method) {
            extract(\App\Service\Admin\CategoryManageService::listPage($catelist, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/cate_list.html');
        }
        break;
    case 'rank':
        if ('POST' != $method) message(-1, lang('method_error'));
        \App\Service\Admin\CategoryManageService::rank($uid);
        message(0, lang('save_successfully'));
        break;
    case 'create':
        if ('POST' == $method) {
            \App\Service\Admin\CategoryManageService::create($uid);
            message(0, jump('分类创建成功', url('cate-list', array(), TRUE), 3));
        }
        break;
    case 'update':
        if ('GET' == $method) {
            $_cid = admin_param_int('cid', 0, 0);
            extract(\App\Service\Admin\CategoryManageService::editPage($_cid, $catelist, $uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/cate_post.html');
        } elseif ('POST' == $method) {
            \App\Service\Admin\CategoryManageService::update($uid);
            message(0, lang('edit_successfully'));
        }
        break;
    case 'del':
        if ('POST' != $method) message(-1, lang('method_error'));
        \App\Service\Admin\CategoryManageService::delete($uid, $conf);
        message(0, lang('cate_delete_successfully'));
        break;
    default:
        message(-1, lang('data_malformation'));
        break;
}

