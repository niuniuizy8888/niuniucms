<?php
namespace App\Service\Admin;

/**
 * 后台任务页服务：定时采集入口 / 异步任务状态 / 手动触发（token + 审计）
 */
class TaskManageService
{
    /**
     * 任务管理页视图数据（GET）。三块拼装，任一块异常都降级不阻断整页：
     *  ① 定时采集 cron URL——按 conf['api'] 逐源生成 api.php?route=cron 完整链接，
     *     token 用 api_wire_token('api') 派生（auth_key 不入 URL/access_log）；
     *     ⚠ 本页面渲染含可用调度令牌，路由侧已把可见性收紧到 manageset；
     *  ② rsync 异步采集状态（ApiExecutionService::rsyncInfo('seeall')，类不存在/异常⇒空数组）；
     *  ③ 采集队列概览：访问即顺带 cleanFinished(200) 清历史完成项，再 stats + 前 10 条列表
     *     （异常⇒全 0 统计 + 空列表）。
     * safe_token：先 bull_token_set($uid,'admin_task') 把场景指纹写进会话，再 bull_token_get 取回
     *（同请求 static 缓存保证 set/get 同值，供本页两个手动触发按钮的 POST 表单）。
     * @param int $uid 当前管理员 uid
     * @return array input(空)/cronLinks/rsyncList/queueStats/queueList/queueTotal/safe_token/header
     */
    public static function listPage(int $uid): array
    {
        $conf = _SERVER('conf');
        // 定时采集链接（各采集源 cron URL，供宝塔计划任务）
        $cronLinks = array();
        $apis = $conf['api'] ?? array();
        if (is_array($apis)) {
            foreach ($apis as $code => $cfg) {
                if (empty($cfg['api'])) continue;
                $cronLinks[] = array(
                    'code' => $code,
                    'name' => $cfg['api_name'] ?? $code,
                    // token 派生化：cron URL 用 api_wire_token，auth_key 不再入 URL/access_log
                    'url' => url_domain() . '/api.php?route=cron&token=' . api_wire_token('api') . '&code=' . rawurlencode($code) . '&h=24&froms=' . rawurlencode($code),
                );
            }
        }
        // 异步任务（rsync）状态概览（采集服务未就绪时降级为空）
        $rsyncList = array();
        if (class_exists('\App\Service\Collection\ApiExecutionService')) {
            try {
                $rsyncList = \App\Service\Collection\ApiExecutionService::rsyncInfo('seeall');
            } catch (\Throwable $e) {
                $rsyncList = array();
            }
        }
        bull_token_set($uid, 'admin_task');
        // 采集任务队列概览（队列化）；访问任务页时顺带清理历史完成项
        try {
            \App\Service\System\TaskQueueService::cleanFinished(200);
            $queueStats = \App\Service\System\TaskQueueService::stats();
            $queueList = \App\Service\System\TaskQueueService::listPage(1, 10);
        } catch (\Throwable $e) {
            $queueStats = array('pending' => 0, 'running' => 0, 'finished' => 0, 'failed' => 0);
            $queueList = array('list' => array(), 'total' => 0);
        }
        return array(
            'input' => array(),
            'cronLinks' => $cronLinks,
            'rsyncList' => is_array($rsyncList) ? $rsyncList : array(),
            'queueStats' => $queueStats,
            'queueList' => is_array($queueList['list'] ?? null) ? $queueList['list'] : array(),
            'queueTotal' => intval($queueList['total'] ?? 0),
            'safe_token' => bull_token_get($uid, 'admin_task'),
            'header' => array('title' => '任务'),
        );
    }

    /**
     * 手动执行采集队列：单次最多消费 20 条
     */
    public static function queueRunOnce(int $uid)
    {
        // 发布前检查修复（E-4）：admin_param_text 第二参为默认值——原误传 64（被当默认串 "64"，maxlen 落回 255）
        $safe_token = admin_param_text('safe_token', '');
        admin_bull_token_guard($uid, 'admin_task', $safe_token);
        $done = \App\Service\System\TaskQueueService::process(20);
        admin_audit_log('task_queue_run', array('done' => $done));
        message(0, jump("队列执行完成（{$done} 条）", url('task-list', array(), TRUE), 2));
    }

    /**
     * 后台手动触发一次计划任务（POST，type=1）：先过 'admin_task' 场景 token 闸
     * （admin_bull_token_guard fail-closed，失败即 message 终止）→
     * ApiExecutionService::cronRun(1)（$force=1 忽略 cron_1_last_date 到期判定，
     * 启用源按源拆入 TaskQueueService 队列，**本身不出网采集**）→ 审计耗时。
     * 收尾 message(0, jump(...)) 渲染提示页并结束请求——本函数无正常返回路径。
     * @param int $uid 操作者 uid
     * @return void 实际不可达（message 内部 exit）
     */
    public static function cronRunOnce(int $uid)
    {
        $safe_token = admin_param_text('safe_token', '');
        admin_bull_token_guard($uid, 'admin_task', $safe_token);
        $t0 = microtime(true);
        \App\Service\Collection\ApiExecutionService::cronRun(1);
        $cost = round(microtime(true) - $t0, 2);
        admin_audit_log('task_cron_run', array('cost' => $cost));
        message(0, jump("已触发一次定时任务（耗时 {$cost}s）", url('task-list', array(), TRUE), 3));
    }
}
