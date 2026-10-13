<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_vod_start.php
FALSE === group_access($gid, 'managevod') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
switch ($action) {
    case 'list':
        if ('GET' == $method) {
            $data = \App\Service\Admin\VodManageService::listPage(array(
                'cid' => admin_param_int('cid', 0, 0),
                'page' => admin_param_int('page', 1, 1),
                'pagesize' => admin_param_int('pagesize', 20, 10, 100),
                'sticky' => admin_param_int('sticky', -1, -1, 1),
                'range' => admin_param_text('range', '', 16),
            ), $uid);
            extract($data, EXTR_SKIP); // S-4：防覆盖路由局部变量
            include _include(ADMIN_PATH . 'html/vod_list.html');
        }
        break;
    case 'search': 
        if ('GET' == $method) {
            $keyword = admin_param_text('keyword', '', 100, TRUE);
            $data = \App\Service\Admin\VodManageService::searchPage($keyword, $uid);
            extract($data, EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/vod_list.html');
        }
        break;
    case 'del':
        if ('POST' != $method) message(-1, lang('method_error'));
        $result = \App\Service\Admin\VodManageService::deleteAction($uid);
        message($result['code'], $result['message']);
        break;
    case 'batch':
        if ('POST' != $method) message(-1, lang('method_error'));
        $result = \App\Service\Admin\VodManageService::batchAction($uid);
        message($result['code'], $result['message']);
        break;
    case 'sticky':
        if ('POST' != $method) message(-1, lang('method_error'));
        $result = \App\Service\Admin\VodManageService::stickyAction($uid);
        message($result['code'], $result['message']);
        break; 
    case 'poster':
        if ('POST' != $method) message(-1, lang('illegal_operation'));
        $result = \App\Service\Admin\VodEditService::posterSyncAction($uid);
        message($result['code'], $result['message']);
        break;
     case 'posterlist':
         $data = \App\Service\Admin\VodEditService::posterListPage(admin_param_int('page', 1, 1), 20, $uid);
         extract($data, EXTR_SKIP);
         include _include(ADMIN_PATH . 'html/vod_poster.html');
        break;
    case 'duplicate':
        if ('GET' == $method) {
            $data = \App\Service\Admin\VodManageService::duplicatePage($uid);
            extract($data, EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/vod_duplicate.html');
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\VodManageService::duplicateAction($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'uploadposterpic':
        if ('POST' != $method) message(-1, lang('method_error'));
        $result = \App\Service\Admin\VodEditService::uploadPosterPicAction($conf, $uid);
        message($result['code'], $result['message'], $result['data']);
        break;
    case 'update':
         $vid = param('vid', 0);
        if ('GET' == $method) {
        $page = param('page', 0);
            $data = \App\Service\Admin\VodEditService::editPage(intval($vid), intval($page), $uid);
            extract($data, EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/vod_update.html');
         }elseif('POST' == $method){
            $result = \App\Service\Admin\VodEditService::updateAction(intval($vid), intval($uid));
            message($result['code'], $result['message']);
         }
         break;
    default:
        message(-1, lang('data_malformation'));
        break;
}
