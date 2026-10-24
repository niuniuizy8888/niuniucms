<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_art_start.php
FALSE === group_access($gid, 'manageart') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
switch ($action) {
    case 'list':
        if ('GET' == $method) {
            $filters = array(
                'cid' => admin_param_int('cid', 0, 0),
                'page' => admin_param_int('page', 1, 1),
                'pagesize' => admin_param_int('pagesize', 20, 10, 100),
                'range' => admin_param_text('range', '', 16),
            );
            // 修复：同 vod-list，工具栏关键词表单提交到 art-list，此前关键词被忽略
            $keyword = admin_param_text('keyword', '', 100);
            $data = '' !== $keyword
                ? \App\Service\Admin\ArtManageService::searchPage($keyword, $uid, $filters)
                : \App\Service\Admin\ArtManageService::listPage($filters, $uid);
            extract($data, EXTR_SKIP); // S-4：防覆盖路由局部变量
            include _include(ADMIN_PATH . 'html/art_list.html');
        }
        break;
    case 'search':
        if ('GET' == $method) {
            $keyword = admin_param_text('keyword', '', 100, TRUE);
            // 第 2 轮 U-5：搜索页同样接收分页/筛选参数（分页链接统一指向 art-list?keyword=…）
            $data = \App\Service\Admin\ArtManageService::searchPage($keyword, $uid, array(
                'cid' => admin_param_int('cid', 0, 0),
                'page' => admin_param_int('page', 1, 1),
                'pagesize' => admin_param_int('pagesize', 20, 10, 100),
                'range' => admin_param_text('range', '', 16),
            ));
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

