<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_tmpl_start.php
FALSE === group_access($gid, 'managetmpl') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
switch ($action) {
    case 'list':
        if ('GET' == $method) {
            extract(\App\Service\Admin\TmplManageService::listPage($uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/tmpl_list.html');
        }
        break;
    case 'cloud':
        if ('GET' == $method) {
            extract(\App\Service\Admin\TmplManageService::cloudPage($uid), EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/tmpl_cloud.html');
        }
        break;
    case 'post':
        if ('POST' == $method) {
            $result = \App\Service\Admin\TmplManageService::toggleInstall($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'down':
        if ('POST' == $method) {
            $result = \App\Service\Admin\TmplManageService::download($uid);
            message($result['code'], $result['message']);
        }
        break;
    case 'file':
        if ('GET' == $method) {
            $view = \App\Service\Admin\TmplManageService::filePage($uid, param(2), (string)param('path', ''), (string)param('filename', ''));
            $viewTemplate = $view['view_template'];
            unset($view['view_template']);
            extract($view, EXTR_SKIP);
            include _include(ADMIN_PATH . 'html/' . $viewTemplate);
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\TmplManageService::saveFile($uid);
            message($result['code'], $result['message']);
        }
        break;
    default:
        message(-1, lang('data_malformation'));
        break;
}
