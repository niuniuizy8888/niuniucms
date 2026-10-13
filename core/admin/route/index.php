<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_index_start.php
$action = param(1);
switch ($action) {
    case 'login':
        if ('GET' == $method) {
            extract(\App\Service\Admin\AdminIndexService::loginPage(intval($uid ?: 0)), EXTR_SKIP);
            include _include(ADMIN_PATH . "html/index_login.html");
        } elseif ('POST' == $method) {
            $result = \App\Service\Admin\AdminIndexService::login($uid, intval($longip), intval($time));
            message($result['code'], $result['message']);
        }
        break;
    case 'logout':
        // GET/POST 均接受：登出按钮为链接（GET），token 由页面渲染时注入 URL；POST 兼容历史调用
        $result = \App\Service\Admin\AdminIndexService::logout($uid, $conf);
        message($result['code'], $result['message']);
        break;
    default:
        // 门禁补齐：此前是 14 个后台路由中唯一无权限位分支——token 有效但所属组已被
        // 收回 intoadmin（登录后降权/改组）的账号仍可直读仪表盘（PHP 版本/最新用户/站点统计）
        FALSE === group_access($gid, 'intoadmin') and message(1, lang('user_group_insufficient_privilege'));
        extract(\App\Service\Admin\AdminIndexService::dashboardPage($db, (array)$runtime), EXTR_SKIP);
        include _include(ADMIN_PATH . 'html/index.html');
        break;
}
