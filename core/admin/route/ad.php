<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_ad_start.php
if (!db_find_table($db->tablepre . 'ad')) {
    // ad 表已集成进 core/install/install.sql（新装自带）。老库缺表时直接给出可执行 DDL，
    // 免去翻找已移除的归档升级脚本（原 .agent/archived/升级脚本_20260810/ad.php）。
    message(-1, '缺少数据表 ad。新装使用 core/install/install.sql；老库请手动执行：<br><code>'
        . 'CREATE TABLE `' . $db->tablepre . 'ad` ('
        . '`id` int(11) unsigned NOT NULL AUTO_INCREMENT,'
        . '`name` varchar(128) NOT NULL DEFAULT \'\','
        . '`url` varchar(255) NOT NULL DEFAULT \'\','
        . '`img_url` varchar(255) NOT NULL DEFAULT \'\','
        . '`area` varchar(32) NOT NULL DEFAULT \'\','
        . '`add_time` int(11) unsigned NOT NULL DEFAULT 0,'
        . '`end_time` int(11) unsigned NOT NULL DEFAULT 0,'
        . 'PRIMARY KEY (`id`), KEY `area` (`area`), KEY `add_time` (`add_time`), KEY `end_time` (`end_time`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
        . '</code>');
}
FALSE === group_access($gid, 'managead') and message(-1, lang('user_group_insufficient_privilege')); // 广告管理权限门禁（上线前补齐）
$action = param(1, 'list');
if ('GET' == $method) {
    extract(\App\Service\Admin\AdManageService::listPage(intval($uid)), EXTR_SKIP); // S-4：防覆盖路由局部变量
    include _include(ADMIN_PATH . 'html/ad.html');
} elseif ('POST' == $method) {
    $type = param('type', 0);
    if (1 == $type) {
        \App\Service\Admin\AdManageService::create(intval($uid), $time);
        message(0, lang('create_successfully'));
    } elseif (2 == $type) {
        \App\Service\Admin\AdManageService::update(intval($uid), $time);
        message(0, lang('update_successfully'));
    } elseif (3 == $type) {
        $result = \App\Service\Admin\AdManageService::uploadImage(intval($uid), $time, $conf);
        message(0, array('url' => $result['url']));
    } else {
        \App\Service\Admin\AdManageService::delete(intval($uid));
        message(0, lang('delete_successfully'));
    }
}

