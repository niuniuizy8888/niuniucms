<?php
namespace App\Service\System;

/**
 * 采集任务队列（基于 MySQL 队列表的 CAS 抢占 + 重试 + 僵尸回收）
 *
 * 触发方式：
 * - 入队：后台采集动作（cronRun 全源拆分入队 / rsync 等）写 scheduler_tasks
 * - 消费：宝塔直接采集命令、独立 CLI worker + 后台任务页手动"执行队列"
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
        return \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id')->count(array(
            'type' => $type, 'param' => xn_json_encode($param),
            'status' => array(self::STATUS_PENDING, self::STATUS_RUNNING),
        ));
    }
    /**
     * 入队一个任务
     * @param string $type 任务类型（cron_source 等）
     * @param array $param 执行参数
     * @return int 任务 ID（0=失败）
     */
    public static function enqueue(string $type, array $param): int
    {
        return self::enqueueDetailed($type, $param)['id'];
    }

    /** 返回任务 ID、状态和复用标记，供计划任务输出真实的入队结果。 */
    public static function enqueueDetailed(string $type, array $param): array
    {
        // 主库连接级锁使检查与写入串行化；所有入队入口共用，不受历史条数影响。
        $db = _SERVER('db');
        $pdo = $db->connect();
        if (!$pdo) throw new \RuntimeException('任务入队失败：数据库不可用');
        $json = xn_json_encode($param);
        $lock = 'nnq_' . hash('sha224', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()
            . '|' . $db->tablepre . '|' . $type . '|' . $json);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->execute(array($lock));
        if ((int)$stmt->fetchColumn() !== 1) throw new \RuntimeException('任务入队锁超时');
        try {
            $table = $db->qi($db->tablepre . 'scheduler_tasks');
            $stmt = $pdo->prepare('SELECT id,status FROM ' . $table
                . ' WHERE type = ? AND BINARY param = BINARY ? AND status IN (0, 1) LIMIT 1');
            $stmt->execute(array($type, $json));
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
            cache_delete(self::EMPTY_FLAG);
            if ($existing) return array('id' => (int)$existing['id'], 'status' => (int)$existing['status'], 'reused' => true);
            $stmt = $pdo->prepare('INSERT INTO ' . $table
                . ' (type, param, status, retry, error, create_date, run_date, finish_date) VALUES (?, ?, 0, 0, ?, ?, 0, 0)');
            $stmt->execute(array($type, $json, '', time()));
            $id = (int)$pdo->lastInsertId();
            cache_delete(self::EMPTY_FLAG);
            return array('id' => $id, 'status' => self::STATUS_PENDING, 'reused' => false);
        } finally {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute(array($lock));
        }
    }
    /**
     * 消费队列（每请求最多 $limit 条）
     * @return int 实际执行条数
     */
    public static function process(int $limit = 1): int
    {
        return count(self::processDetailed($limit));
    }

    /** 每条任务每轮只尝试一次；返回真实结果，失败不能按执行条数误报成功。 */
    public static function processDetailed(int $limit = 1): array
    {
        if (cache_get(self::EMPTY_FLAG)) return array();
        $limit = max(1, min(20, $limit));
        $results = array();
        $repo = \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id');
        $running = $repo->builder()->select(array('id', 'run_date'))->where('status', '=', self::STATUS_RUNNING)->limit(100)->get();
        if ($running === false) throw new \RuntimeException('执行中任务读取失败');
        foreach ($running as $row) {
            if (count($results) >= $limit) break;
            if ((int)$row['run_date'] > 0 && time() - (int)$row['run_date'] > 1800) {
                $result = self::processTask((int)$row['id']);
                if ($result['attempted']) $results[] = $result;
            }
        }
        $pending = $repo->builder()->select(array('id'))->where('status', '=', self::STATUS_PENDING)->orderBy('id', 'ASC')->limit($limit)->get();
        if ($pending === false) throw new \RuntimeException('待执行任务读取失败');
        $attempted = array_column($results, 'id');
        foreach ($pending as $row) {
            if (count($results) >= $limit) break;
            if (in_array((int)$row['id'], $attempted, true)) continue;
            $result = self::processTask((int)$row['id']);
            if ($result['attempted']) $results[] = $result;
        }
        if (!$results && !$running && !$pending) cache_set(self::EMPTY_FLAG, 1, self::EMPTY_FLAG_TTL);
        return $results;
    }

    /** 主库读取，避免刚入队后从副本读不到任务。 */
    public static function readTask(int $id): array
    {
        $db = _SERVER('db');
        $stmt = $db->connect()->prepare('SELECT id,type,param,status,retry,error,create_date,run_date,finish_date FROM '
            . $db->qi($db->tablepre . 'scheduler_tasks') . ' WHERE id=?');
        $stmt->execute(array($id));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('任务不存在：' . $id);
        foreach (array('id','status','retry','create_date','run_date','finish_date') as $field) $row[$field] = (int)$row[$field];
        return $row;
    }

    /** 进度不进入任务参数，避免分页推进破坏同源同窗口去重；主库批量读防止续采倒退。 */
    public static function progressList(array $ids): array
    {
        if (!$ids) return array();
        $keys = array_map(static fn($id) => 'task_progress_' . (int)$id, $ids);
        $db = _SERVER('db');
        $stmt = $db->connect()->prepare('SELECT k,v FROM ' . $db->qi($db->tablepre . 'kv')
            . ' WHERE k IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $stmt->execute($keys);
        $result = array();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $value = json_decode($row['v'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($value)) throw new \RuntimeException('采集进度数据无效');
            $result[(int)substr($row['k'], strlen('task_progress_'))] = $value;
        }
        return $result;
    }

    public static function saveProgress(int $id, array $progress): void
    {
        if (kv_set('task_progress_' . $id, $progress) === false) throw new \RuntimeException('采集进度保存失败');
    }

    /** 定向执行；连接锁保护活跃的长任务，避免仅凭开始时间把它当成僵尸。 */
    public static function processTask(int $id): array
    {
        $db = _SERVER('db');
        $pdo = $db->connect();
        $lock = 'nnrun_' . hash('sha224', $pdo->query('SELECT DATABASE()')->fetchColumn() . '|' . $db->tablepre . '|' . $id);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute(array($lock));
        $acquired = (int)$stmt->fetchColumn() === 1;
        try {
            $row = self::readTask($id);
            $row['attempted'] = false;
            if (!$acquired) return $row;
            $repo = \App\Repository\RepositoryFactory::table('scheduler_tasks', 'id');
            if ($row['status'] === self::STATUS_RUNNING && $row['run_date'] > 0 && time() - $row['run_date'] > 1800) {
                $retry = $row['retry'] + 1;
                $changes = array('retry' => $retry, 'status' => $retry >= self::MAX_RETRY ? self::STATUS_FAILED : self::STATUS_PENDING,
                    'error' => '执行进程中断或超时', 'run_date' => 0);
                if ($retry >= self::MAX_RETRY) $changes['finish_date'] = time();
                if (!$repo->update(array('id' => $id, 'status' => self::STATUS_RUNNING), $changes)) throw new \RuntimeException('任务状态恢复失败');
                $row = array_merge($row, $changes);
                if ($row['status'] === self::STATUS_FAILED) { $row['attempted'] = true; return $row; }
            }
            if ($row['status'] !== self::STATUS_PENDING) return $row;
            $started = time();
            $claimed = $repo->update(array('id' => $id, 'status' => self::STATUS_PENDING), array('status' => self::STATUS_RUNNING, 'run_date' => $started));
            if ($claimed === false) throw new \RuntimeException('任务执行状态写入失败');
            if (!$claimed) return array_merge(self::readTask($id), array('attempted' => false));
            $error = '';
            $complete = false;
            try {
                $complete = self::dispatch($row['type'], xn_json_decode($row['param']), $id);
            } catch (\Throwable $e) {
                $error = mb_substr(str_replace(array("\r", "\n"), ' ', $e->getMessage()), 0, 480, 'UTF-8');
                if (trim($error) === '') $error = '任务执行异常：' . get_class($e);
            }
            $retry = $row['retry'] + ($error !== '' ? 1 : 0);
            $status = $error === '' ? ($complete ? self::STATUS_FINISHED : self::STATUS_PENDING)
                : ($retry >= self::MAX_RETRY ? self::STATUS_FAILED : self::STATUS_PENDING);
            $changes = array('status' => $status, 'retry' => $retry, 'error' => $error, 'run_date' => $status === self::STATUS_PENDING ? 0 : $started,
                'finish_date' => $status === self::STATUS_PENDING ? 0 : time());
            if (!$repo->update(array('id' => $id, 'status' => self::STATUS_RUNNING), $changes)) throw new \RuntimeException('任务结果保存失败，请检查任务状态');
            return array_merge($row, $changes, array('attempted' => true));
        } finally {
            if ($acquired) { $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute(array($lock)); }
        }
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
    private static function dispatch(string $type, $param, int $id): bool
    {
        $param = is_array($param) ? $param : array();
        $handler = self::$handlers[$type] ?? null;
        if ($handler === null) {
            throw new \RuntimeException('未知任务类型: ' . $type);
        }
        // false 表示本批完成、任务尚有后续分页；旧 void 处理器仍视为整项完成。
        return $handler($param, $id) !== false;
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
        $rows = $repo->builder()->select(array('id', 'type', 'param', 'status', 'retry', 'error', 'create_date', 'run_date', 'finish_date'))
            ->orderBy('id', 'DESC')->page(max(1, $page), $pagesize)->get();
        if ($rows === false) throw new \RuntimeException('任务列表读取失败');
        return array(
            // 本页需从 param 显示采集源和小时窗口，限页读取。
            'list' => array_column($rows, null, 'id'),
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
            if ($repo->delete(array('id' => (int)$did))) kv_delete('task_progress_' . (int)$did);
        }
        return count($del);
    }
}
