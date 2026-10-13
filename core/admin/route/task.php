<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_task_start.php
// 本页渲染含 api.php/cron 完整调度 URL（token 敏感）与服务器信息，可见性收紧到配置域（原 managevod 过宽；安全修复）
FALSE === group_access($gid, 'manageset') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
if ('GET' == $method) {
    extract(\App\Service\Admin\TaskManageService::listPage($uid), EXTR_SKIP);
    include _include(ADMIN_PATH . 'html/task.html');
} elseif ('POST' == $method) {
    $type = admin_param_int('type');
    if (1 == $type) {
        \App\Service\Admin\TaskManageService::cronRunOnce($uid);
    } elseif (2 == $type) {
        // 队列化：手动执行采集队列（最多 20 条）
        \App\Service\Admin\TaskManageService::queueRunOnce($uid);
    }
}

