<?php
!defined('DEBUG') and exit('Access Denied.');
FALSE === group_access($gid, 'manageset') and message(1, lang('user_group_insufficient_privilege'));
$action = param(1, 'list');
$attachment_revision = substr(hash_file('sha256', APP_PATH.'static/js/attachment.js'), 0, 16);
if ($method === 'POST') {
    $submitted_token = admin_param_text('safe_token', '');
    $next_token = bull_token_verify($uid, $submitted_token, 'admin_attachment');
    if ($next_token === false) admin_bull_token_guard($uid, 'admin_attachment', $submitted_token);
    // Verification rotates the session token. Send it before releasing the session lock,
    // including on operation failures, so long-running AJAX chains keep a valid token.
    header('X-NNCMS-CSRF-Token: ' . $next_token);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-NNCMS-Attachment-Revision: '.$attachment_revision);
    try {
        // Read original POST values: global HTML escaping must not alter FTP credentials.
        $input = array_intersect_key($_REQUEST, $_POST);
        $op = admin_param_text('op', '');
        $id = admin_param_int('id');
        $current_ui = admin_param_int('ui_protocol') >= 3;
        $input['legacy_states'] = !$current_ui;
        if ($current_ui && admin_param_text('ui_revision','') !== $attachment_revision) {
            echo json_encode(['code'=>1,'refresh'=>true,'message'=>'附件页面已更新，请刷新页面并恢复原任务，进度已保留'],JSON_UNESCAPED_UNICODE);
            exit;
        }
        switch ($op) {
            case 'save': \App\Service\System\AttachmentStorage::save($input); $result = ['message'=>'存储设置已保存，已有图片不会自动迁移']; break;
            case 'probe': $result = ['message'=>\App\Service\System\AttachmentStorage::probe($input, !empty($_POST['upload']))]; break;
            case 'preview': $result = \App\Service\System\AttachmentJobService::preview($input); break;
            case 'create': $result = \App\Service\System\AttachmentJobService::create($input); break;
            case 'step': $result = \App\Service\System\AttachmentJobService::step($id); break;
            case 'status': $result = \App\Service\System\AttachmentJobService::status($id); break;
            case 'details': $result = \App\Service\System\AttachmentJobService::details($id,$input); break;
            case 'references': $result = \App\Service\System\AttachmentService::references($id); break;
            case 'pause': case 'resume': case 'stop': case 'retry': $result = \App\Service\System\AttachmentJobService::control($id,$op,admin_param_int('item_id')); break;
            case 'delete': $result = \App\Service\System\AttachmentService::delete($id); break;
            case 'overview': $result = \App\Service\System\AttachmentJobService::overview($input); break;
            default: throw new \InvalidArgumentException('操作无效');
        }
        if (!in_array($op,['preview','step','status','details','references','overview'],true)) admin_audit_log('attachment_'.$op, ['id'=>$id]);
        if (!$current_ui) {
            // Tabs opened before the reuse state was introduced know only "skipped".
            // Preserve their progress totals and labels until the page is refreshed.
            $legacy = static function ($value) use (&$legacy) {
                if (!is_array($value)) return $value;
                if (($value['state']??'') === 'reused') $value['state']='skipped';
                if (isset($value['counts']['reused'])) {
                    $value['counts']['skipped'] += $value['counts']['reused'];
                    $value['counts']['reused']=0;
                    $value['work_total']=$value['total'];
                }
                foreach ($value as $key=>$item) if (is_array($item)) $value[$key]=$legacy($item);
                return $value;
            };
            $result=$legacy($result);
        }
        echo json_encode(['code'=>0,'data'=>$result],JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (\Throwable $e) {
        xn_log('attachment operation failed: '.$e->getMessage(),'attachment_error');
        echo json_encode(['code'=>1,'message'=>$e instanceof \PDOException ? '数据库操作失败，请查看服务器日志' : $e->getMessage()],JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}
bull_token_set($uid, 'admin_attachment');
$safe_token = bull_token_get($uid, 'admin_attachment');
$storage = \App\Service\System\AttachmentStorage::settings();
$attachment_data = \App\Service\System\AttachmentJobService::overview([]);
$header = ['title'=>'附件管理'];
include _include(ADMIN_PATH . 'html/attachment.html');
