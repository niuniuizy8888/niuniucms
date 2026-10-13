<?php
namespace App\Service\System;

/**
 * 采集任务队列（基于 MySQL 队列表的 CAS 抢占 + 重试 + 僵尸回收）
 *
 * 触发方式：
 * - 入队：后台采集动作（cronRun 全源拆分入队 / rsync 等）写 scheduler_tasks
 * - 消费：请求尾触发（index.php，每请求 1 条，DEBUG=0）+ 后台任务页手动"执行队列"
 * - 重试：失败自动重试，上限 3 次后置 failed
 */
class TaskQueueService
{
    const STATUS_PENDING  = 0;
    const STATUS_RUNNING  = 1;
    const STATUS_FINISHED = 2;
    const STATUS_FAILED   = 3;

    const MAX_RETRY = 3;

    /**
     * 队列空闲标志（性能轮）：队列为空时置缓存标志，process() 直接返回，
     * 免去每请求「僵尸扫描 + pending 探测」2 个 DB 查询。enqueue() 时删除标志即时恢复消费；
     * TTL 60s 自愈任何旁路写入（如后台直接改库重置任务）造成的漏失效。
     */
    const EMPTY_FLAG = 'task_queue_empty_flag';
    const EMPTY_FLAG_TTL = 60;

    /**
     * 活跃任务计数（status ∈ pending/running 且 type+param 完全匹配；用于入队去重，审计修复）
     */
    public static function countActive(string $type, array $param): int
    {
        $repo = \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id');
        // SQL 专项审计修复（R10）：判重只需 id/status，排除 param(text)
        $rows = $repo->find(array('type' => $type, 'param' => xn_json_encode($param)), array(), 1, 50, 'id', array('id', 'status'));
        $n = 0;
        foreach ((array)$rows as $row) {
            if (in_array((int)$row['status'], array(self::STATUS_PENDING, self::STATUS_RUNNING), TRUE)) $n++;
        }
        return $n;
    }

    /**
     * 入队一个任务
     * @param string $type 任务类型（cron_source 等）
     * @param array $param 执行参数
     * @return int 任务 ID（0=失败）
     */
    public static function enqueue(string $type, array $param): int
    {
        $time = time();
        $row = array(
            'type' => $type,
            'param' => xn_json_encode($param),
            'status' => self::STATUS_PENDING,
            'retry' => 0,
            'error' => '',
            'create_date' => $time,
            'run_date' => 0,
            'finish_date' => 0,
        );
        $r = \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id')->create($row);
        if ($r) {
            // 新任务入队：清除空闲标志，下一请求尾消费立即恢复（性能轮）
            cache_delete(self::EMPTY_FLAG);
        }
        return $r ? (int)$r : 0;
    }

    /**
     * 消费队列（每请求最多 $limit 条）
     * @return int 实际执行条数
     */
    public static function process(int $limit = 1): int
    {
        // 队列空闲标志：60s 内已确认无 pending/running 时跳过全部 DB 探测（性能轮，
        // 省 2 查询/请求；enqueue() 会即时删标志，任务消费延迟为零）
        if (cache_get(self::EMPTY_FLAG)) return 0;
        $time = time();
        $done = 0;
        $hadPending = FALSE;
        $hadRunning = FALSE;
        $repo = \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id');
        // 僵尸任务回收：running 超过 1800s（进程被 SIGKILL/内存耗尽）重置回 pending 并计重试，达上限置 failed（审计修复）
        // SQL 专项审计修复（R10）：只需 id/run_date/retry/status
        $stale = $repo->find(array('status' => self::STATUS_RUNNING), array(), 1, 100, 'id', array('id', 'run_date', 'retry', 'status'));
        $hadRunning = count((array)$stale) > 0;
        foreach ((array)$stale as $srow) {
            if (intval($srow['run_date']) > 0 && ($time - intval($srow['run_date'])) > 1800) {
                $retry = (int)$srow['retry'] + 1;
                if ($retry >= self::MAX_RETRY) {
                    $repo->update(array('id' => intval($srow['id'])), array('status' => self::STATUS_FAILED, 'retry' => $retry, 'error' => 'zombie timeout'));
                } else {
                    $repo->update(array('id' => intval($srow['id'])), array('status' => self::STATUS_PENDING, 'retry' => $retry, 'run_date' => 0));
                }
            }
        }
        for ($i = 0; $i < $limit; $i++) {
            $row = $repo->findOne(array('status' => self::STATUS_PENDING), array('id' => 1));
            if (empty($row) || empty($row['id'])) break;
            $hadPending = TRUE;
            $id = (int)$row['id'];
            // CAS 抢占：仅 pending → running 成功才执行（并发安全）
            $upd = $repo->update(
                array('id' => $id, 'status' => self::STATUS_PENDING),
                array('status' => self::STATUS_RUNNING, 'run_date' => $time)
            );
            if (!$upd) continue; // 已被其他请求取走
            $err = '';
            try {
                self::dispatch((string)$row['type'], xn_json_decode((string)$row['param']));
            } catch (\Throwable $e) {
                $err = substr(str_replace(array("\r", "\n"), ' ', (string)$e->getMessage()), 0, 480);
            }
            $finish = time();
            if ($err === '') {
                $repo->update(
                    array('id' => $id),
                    array('status' => self::STATUS_FINISHED, 'finish_date' => $finish, 'error' => '', 'run_date' => $time)
                );
            } else {
                $retry = (int)$row['retry'] + 1;
                if ($retry >= self::MAX_RETRY) {
                    $repo->update(
                        array('id' => $id),
                        array('status' => self::STATUS_FAILED, 'retry' => $retry, 'error' => $err, 'finish_date' => $finish)
                    );
                } else {
                    // 重试：回 pending（run_date 清 0）
                    $repo->update(
                        array('id' => $id),
                        array('status' => self::STATUS_PENDING, 'retry' => $retry, 'error' => $err, 'run_date' => 0)
                    );
                }
            }
            $done++;
        }
        // 本轮确认队列全空（无 running 残留、无 pending）：置空闲标志，后续请求免 DB 探测
        if ($done === 0 && !$hadPending && !$hadRunning) {
            cache_set(self::EMPTY_FLAG, 1, self::EMPTY_FLAG_TTL);
        }
        return $done;
    }

    /** @var array<string, callable> 任务类型处理器注册表（组合根注册，System 域不直接依赖业务域） */
    private static $handlers = array();

    /**
     * 注册任务类型处理器（由组合根 index.php 接线；业务域 → System 域方向注册，保持域间 DAG 无环）
     * @param string $type 任务类型
     * @param callable $handler 处理器（接收 array $param）
     */
    public static function registerHandler(string $type, $handler): void
    {
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException('任务处理器不可调用: ' . $type);
        }
        self::$handlers[$type] = $handler;
    }

    /**
     * 任务类型是否已注册处理器
     */
    /**
     * 任务分发执行
     * @throws \RuntimeException 执行失败
     */
    private static function dispatch(string $type, $param)
    {
        $param = is_array($param) ? $param : array();
        $handler = self::$handlers[$type] ?? null;
        if ($handler === null) {
            throw new \RuntimeException('未知任务类型: ' . $type);
        }
        $handler($param);
    }

    /**
     * 队列状态统计
     */
    public static function stats(): array
    {
        $repo = \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id');
        return array(
            'pending'  => (int)$repo->count(array('status' => self::STATUS_PENDING)),
            'running'  => (int)$repo->count(array('status' => self::STATUS_RUNNING)),
            'finished' => (int)$repo->count(array('status' => self::STATUS_FINISHED)),
            'failed'   => (int)$repo->count(array('status' => self::STATUS_FAILED)),
        );
    }

    /**
     * 任务列表（倒序分页）
     */
    public static function listPage(int $page = 1, int $pagesize = 20): array
    {
        $repo = \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id');
        return array(
            // SQL 专项审计修复（R10）：列表排除 param(text)
            'list' => $repo->find(array(), array('id' => -1), max(1, $page), $pagesize, 'id', array('id', 'type', 'status', 'retry', 'error', 'create_date', 'run_date', 'finish_date')),
            'total' => (int)$repo->count(array()),
        );
    }

    /**
     * 清理已完成任务（保留最近 N 条）
     */
    public static function cleanFinished(int $keep = 200): int
    {
        $repo = \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id');
        $rows = $repo->find(
            array('status' => array(self::STATUS_FINISHED, self::STATUS_FAILED)),
            array('id' => -1),
            1,
            $keep + 100,
            'id',
            array('id')
        );
        $ids = array_values(arrlist_values((array)$rows, 'id'));
        if (count($ids) <= $keep) return 0;
        $del = array_slice($ids, $keep);
        foreach ($del as $did) {
            $repo->delete(array('id' => (int)$did));
        }
        return count($del);
    }
}
