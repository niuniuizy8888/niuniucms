<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_art_start.php
FALSE === group_access($gid, 'manageart') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
switch ($action) {
    case 'list':
        if ('GET' == $method) {
            $data = \App\Service\Admin\ArtManageService::listPage(array(
                'cid' => admin_param_int('cid', 0, 0),
                'page' => admin_param_int('page', 1, 1),
                'pagesize' => admin_param_int('pagesize', 20, 10, 100),
                'range' => admin_param_text('range', '', 16),
            ), $uid);
            extract($data, EXTR_SKIP); // S-4：防覆盖路由局部变量
            include _include(ADMIN_PATH . 'html/art_list.html');
        }
        break;
    case 'search':
        if ('GET' == $method) {
            $keyword = admin_param_text('keyword', '', 100, TRUE);
            $data = \App\Service\Admin\ArtManageService::searchPage($keyword, $uid);
            extract($data, EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/art_list.html');
        }
        break;
    case 'del':
        if ('POST' != $method) message(-1, lang('method_error'));
        $result = \App\Service\Admin\ArtManageService::deleteAction($uid);
        message($result['code'], $result['message']);
        break;
    case 'batch':
        if ('POST' != $method) message(-1, lang('method_error'));
        $result = \App\Service\Admin\ArtManageService::batchAction($uid);
        message($result['code'], $result['message']);
        break;
    default:
        message(-1, lang('data_malformation'));
        break;
}

