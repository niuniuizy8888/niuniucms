<?php
!defined('DEBUG') and exit('Access Denied.');

// hook admin_recycle_start.php
// ⚠ 权限位修复（审查）：原仅 managevod ⇒ 能删文章（manageart）的管理员打不开回收站，
//   而 managevod 管理员却能 restore/purge **文章**快照（跨内容域写）。现入口放行两者任一，
//   单条操作在下方按记录类型复核对应权限，清空全表要求两者兼备（见 POST 分支）。
(FALSE === group_access($gid, 'managevod') && FALSE === group_access($gid, 'manageart'))
    and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
if ('GET' == $method) {
    extract(\App\Service\Admin\RecycleManageService::listPage($uid), EXTR_SKIP);
    include _include(ADMIN_PATH . 'html/recycle.html');
} elseif ('POST' == $method) {
    $type = admin_param_int('type');
    $id = admin_param_int('id');
    if ($id > 0) {
        // 单条操作：按快照记录类型复核权限位（文章→manageart、视频→managevod），防跨内容域操作
        $recType = \App\Service\Content\RecycleService::readType($id);
        if ('vod' === $recType) {
            FALSE === group_access($gid, 'managevod') and message(1, lang('user_group_insufficient_privilege'));
        } elseif ('art' === $recType) {
            FALSE === group_access($gid, 'manageart') and message(1, lang('user_group_insufficient_privilege'));
        } else {
            message(-1, lang('data_is_empty')); // 记录不存在（已被清/恢复）
        }
    } else {
        // 清空整个回收站 = 跨内容域破坏性操作，需两个权限位同时在手
        (FALSE === group_access($gid, 'managevod') || FALSE === group_access($gid, 'manageart'))
            and message(1, lang('user_group_insufficient_privilege'));
    }
    if (1 == $type) {
        \App\Service\Admin\RecycleManageService::restore($uid);
    } else {
        \App\Service\Admin\RecycleManageService::purge($uid);
    }
}
