<?php
namespace App\Service\Admin;

/**
 * 后台任务页服务：定时采集入口 / 异步任务状态 / 手动触发（token + 审计）
 */
class TaskManageService
{
    /**
     * 任务管理页视图数据（GET）。三块拼装，任一块异常都降级不阻断整页：
     *  ① 采集源配置入口——共用定时任务弹窗生成自动续采 curl 命令；
     *     ⚠ 本页面渲染含可用调度令牌，路由侧已把可见性收紧到 manageset；
     *  ② rsync 异步采集状态（ApiExecutionService::rsyncInfo('seeall')，类不存在/异常⇒空数组）；
     *  ③ 采集队列概览：访问即顺带 cleanFinished(200) 清历史完成项，再 stats + 前 10 条列表
     *     （异常显示读取失败，避免把故障当成空队列）。
     * safe_token：先 bull_token_set($uid,'admin_task') 把场景指纹写进会话，再 bull_token_get 取回
     *（同请求 static 缓存保证 set/get 同值，供本页两个手动触发按钮的 POST 表单）。
     * @param int $uid 当前管理员 uid
     * @return array input(空)/cronLinks/rsyncList/queueStats/queueList/queueTotal/safe_token/header
     */
    public static function listPage(int $uid): array
    {
        $conf = _SERVER('conf');
        // 定时采集入口（按源生成宝塔 curl 命令）
        $cronLinks = array();
        $apis = $conf['api'] ?? array();
        if (is_array($apis)) {
            foreach ($apis as $code => $cfg) {
                if (empty($cfg['api'])) continue;
                $cronLinks[] = array(
                    'code' => $code,
                    'name' => $cfg['api_name'] ?? $code,
                    'ready' => !empty($conf['bind'][$code]),
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
        $queueError = '';
        $oldestPending = 0;
        // 采集任务队列概览（队列化）；访问任务页时顺带清理历史完成项
        try {
            \App\Service\System\TaskQueueService::cleanFinished(200);
            $queueStats = \App\Service\System\TaskQueueService::stats();
            $queueList = \App\Service\System\TaskQueueService::listPage(1, 10);
            $oldest = \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id')->findOne(array('status' => 0), array('create_date' => 1), array('id', 'create_date'));
            $ids = array_keys($queueList['list']);
            if ($oldest) $ids[] = (int)$oldest['id'];
            $progress = \App\Service\System\TaskQueueService::progressList(array_unique($ids));
            // 分批任务持续推进时不能仅因创建时间较早就提示停滞。
            $oldestPending = $oldest ? (int)($progress[(int)$oldest['id']]['updated_at'] ?? $oldest['create_date']) : 0;
            foreach ($queueList['list'] as &$item) {
                $param = xn_json_decode($item['param']);
                $code = (string)($param['code'] ?? '');
                $item['source_name'] = (string)($apis[$code]['api_name'] ?? $code);
                $item['hours'] = (int)($param['h'] ?? 24);
                $item['progress_text'] = \App\Service\Collection\CollectionScheduleService::progressText($progress[(int)$item['id']] ?? array());
            }
            unset($item);
        } catch (\Throwable $e) {
            $queueStats = array('pending' => 0, 'running' => 0, 'finished' => 0, 'failed' => 0);
            $queueList = array('list' => array(), 'total' => 0);
            $queueError = '采集任务读取失败，请检查服务器日志。';
            xn_log('task queue overview failed: ' . $e->getMessage(), 'task_error');
        }
        return array(
            'input' => array(),
            'cronLinks' => $cronLinks,
            'rsyncList' => is_array($rsyncList) ? $rsyncList : array(),
            'queueStats' => $queueStats,
            'queueList' => is_array($queueList['list'] ?? null) ? $queueList['list'] : array(),
            'queueTotal' => intval($queueList['total'] ?? 0),
            'queueError' => $queueError,
            'queueWaitMinutes' => $oldestPending ? max(0, (int)floor((time() - $oldestPending) / 60)) : 0,
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
        $results = \App\Service\System\TaskQueueService::processDetailed(20);
        $failed = count(array_filter($results, static fn($row) => $row['error'] !== ''));
        $finished = count(array_filter($results, static fn($row) => $row['status'] === \App\Service\System\TaskQueueService::STATUS_FINISHED));
        $continuing = count($results) - $failed - $finished;
        admin_audit_log('task_queue_run', array('finished' => $finished, 'failed' => $failed));
        message($failed ? 1 : 0, jump("本次完成 {$finished} 个任务，{$continuing} 个任务已保存进度等待继续，{$failed} 个任务采集失败", url('task-list', array(), TRUE), 2));
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
        $tasks = \App\Service\Collection\ApiExecutionService::cronRun(1);
        $cost = round(microtime(true) - $t0, 2);
        admin_audit_log('task_cron_run', array('cost' => $cost));
        $count = count($tasks);
        $reused = count(array_filter($tasks, static fn($task) => $task['reused']));
        message(0, jump("已准备 {$count} 个采集任务（{$reused} 个为已有任务）。请执行对应的宝塔命令，或点击「继续采集」（耗时 {$cost}s）", url('task-list', array(), TRUE), 3));
    }
}
