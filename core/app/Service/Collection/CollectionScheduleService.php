<?php
namespace App\Service\Collection;

use App\Service\System\TaskQueueService;

class CollectionScheduleService
{
    /** 先校验整批，再入队；不能把无效来源或未绑定分类静默当作成功。 */
    public static function enqueue(array $codes, int $hours): array
    {
        if (ApiExecutionService::cronHours($hours) === null) throw new \InvalidArgumentException('采集窗口必须为 1–8760 小时');
        $conf = _SERVER('conf');
        $codes = array_values(array_unique(array_map('trim', $codes)));
        if (!$codes) throw new \InvalidArgumentException('没有可采集的来源');
        foreach ($codes as $code) {
            if ($code === '' || empty($conf['api'][$code]['api'])) throw new \InvalidArgumentException('采集源不存在：' . $code);
            if (empty($conf['bind'][$code])) throw new \InvalidArgumentException('采集源尚未绑定分类：' . $code);
        }
        $tasks = array();
        foreach ($codes as $code) {
            $param = array('code' => $code);
            if ($hours !== 24) $param['h'] = $hours;
            $task = TaskQueueService::enqueueDetailed('cron_source', $param);
            $task['source'] = $code;
            $task['hours'] = $hours;
            $task['status_text'] = self::statusText($task['status']);
            $tasks[] = $task;
        }
        return $tasks;
    }

    public static function statusText(int $status): string
    {
        return array('等待采集', '正在采集', '采集完成', '采集失败')[$status] ?? '未知';
    }

    /** 一次请求只处理一页，curl 跟随本站跳转继续；不依赖 shell/PHP 路径或常驻进程。 */
    public static function runHttp(array $input): array
    {
        if (isset($input['task'])) {
            $id = filter_var($input['task'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            if (!$id) throw new \InvalidArgumentException('任务编号无效');
        } else {
            $code = $input['code'] ?? '';
            $hours = ApiExecutionService::cronHours($input['h'] ?? 24);
            if (!is_string($code) || trim($code) === '') throw new \InvalidArgumentException('请选择采集来源');
            if ($hours === null) throw new \InvalidArgumentException('采集范围必须为 1–8760 小时');
            $id = self::enqueue(array($code), $hours)[0]['id'];
        }
        $task = TaskQueueService::readTask($id);
        if ($task['type'] !== 'cron_source') throw new \InvalidArgumentException('该任务不是定时采集任务');
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $result = TaskQueueService::processTask($id);
        $prefix = '任务 #' . $id . '：';
        if ($result['error'] !== '' && ($result['attempted'] || $result['status'] === TaskQueueService::STATUS_FAILED)) {
            return array('status' => 500, 'id' => $id, 'message' => $prefix . '采集失败，' . $result['error'] . '。请在后台任务管理查看详情。');
        }
        if ($result['status'] === TaskQueueService::STATUS_FINISHED) {
            return array('status' => 200, 'id' => $id, 'message' => $prefix . '采集完成。' . self::progressText(TaskQueueService::progressList(array($id))[$id] ?? array()));
        }
        if (!$result['attempted']) {
            return array('status' => 202, 'id' => $id, 'message' => $prefix . '已有采集任务正在处理，本次未重复启动；这不代表采集完成，请在后台查看状态。');
        }
        return array('status' => 302, 'id' => $id, 'message' => $prefix . '本批已完成，继续采集下一批。');
    }

    /** 在队列连接锁内执行。页内容提交后才推进断点，失败后重试同一页。 */
    public static function collectPage(array $param, int $id): bool
    {
        $conf = _SERVER('conf');
        $code = (string)($param['code'] ?? '');
        $cfg = $conf['api'][$code] ?? array();
        if (empty($cfg['api'])) throw new \RuntimeException('采集源不存在：' . $code);
        if (empty($conf['bind'][$code])) throw new \RuntimeException('请先选择需要采集的分类：' . $code);
        $hours = ApiExecutionService::cronHours($param['h'] ?? 24);
        if ($hours === null) throw new \RuntimeException('采集时间范围无效');
        $model = (int)($cfg['model'] ?? -1);
        $models = $model === 0 ? array(0) : ($model === 1 ? array(1) : array(0, 1));
        $signature = hash('sha256', $cfg['api'] . '|' . $model);
        $progress = TaskQueueService::progressList(array($id))[$id] ?? array();
        if (!$progress || ($progress['signature'] ?? '') !== $signature) {
            $progress = array('signature' => $signature, 'phase' => 0, 'page' => 1, 'total' => 0, 'done' => 0, 'complete' => false);
        }
        $phase = (int)($progress['phase'] ?? -1);
        $page = (int)($progress['page'] ?? 0);
        if ($phase < 0 || $phase > count($models) || $page < 1 || !isset($progress['done'])) throw new \RuntimeException('采集进度无效，请检查任务记录');
        if (!empty($progress['complete'])) return true;
        if (!isset($models[$phase])) throw new \RuntimeException('采集阶段无效');
        $article = $models[$phase] === 1;
        $data = $article ? ApiExecutionService::fetchArtJson($cfg['api'], '', $page, (string)$hours)
            : ApiExecutionService::fetchVideoJson($cfg['api'], '', $page, (string)$hours);
        if (!isset($data['page']['pagecount'])) throw new \RuntimeException('获取来源内容失败：' . $code . '，第 ' . $page . ' 页');
        $total = (int)$data['page']['pagecount'];
        if ($page <= $total) {
            $lines = $article ? ApiExecutionService::importArtData($data, $code, true) : ApiExecutionService::importVideoData($data, $code);
            \App::fire('addon.hook.collect.page', array('code' => $code, 'page' => $page, 'lines' => count((array)$lines)));
            $progress['done']++;
        }
        $progress['total'] = $total;
        $progress['page'] = $page + 1;
        if ($page >= $total) {
            $progress['phase']++;
            $progress['page'] = 1;
            $progress['total'] = 0;
        }
        $progress['complete'] = $progress['phase'] >= count($models);
        $progress['updated_at'] = time();
        TaskQueueService::saveProgress($id, $progress);
        return $progress['complete'];
    }

    public static function progressText(array $progress): string
    {
        if (!$progress) return '';
        return '已采集 ' . (int)($progress['done'] ?? 0) . ' 页'
            . (!empty($progress['complete']) ? '。' : '，下次从第 ' . (int)($progress['page'] ?? 1) . ' 页继续。');
    }
}
