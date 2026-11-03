<?php
// 宝塔单条命令：入队/复用后定向执行同一任务，不依赖另一个常驻 worker。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$options = getopt('', array('source:', 'hours:', 'help'));
if (isset($options['help'])) { echo "php tools/collect.php --source=来源编码 --hours=24\n"; exit(0); }
try {
    if (empty($options['source']) || !is_string($options['source'])) throw new \InvalidArgumentException('请指定 --source=来源编码');
    chdir(dirname(__DIR__));
    if (!is_file('data/install.lock') && !is_file('core/config/install.lock') && !is_file('core/install.lock')) throw new \RuntimeException('本站尚未安装');
    define('SKIP_ROUTE', true);
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'NiuNiuCMS scheduled collection';
    require 'index.php';
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $hours = \App\Service\Collection\ApiExecutionService::cronHours($options['hours'] ?? 24);
    if ($hours === null) throw new \InvalidArgumentException('采集窗口必须为 1–8760 小时');
    $task = \App\Service\Collection\CollectionScheduleService::enqueue(array($options['source']), $hours)[0];
    echo ($task['reused'] ? '复用已有任务 #' : '新建任务 #') . $task['id'] . '，来源 ' . $task['source'] . '，最近 ' . $hours . " 小时\n";
    do {
        $result = \App\Service\System\TaskQueueService::processTask($task['id']);
    } while ($result['attempted'] && $result['status'] === \App\Service\System\TaskQueueService::STATUS_PENDING && $result['error'] === '');
    if ($result['attempted'] && $result['error'] !== '') {
        throw new \RuntimeException('任务 #' . $task['id'] . ' 采集失败：' . $result['error'] . '；重试 ' . $result['retry'] . '/3，请查看后台任务管理');
    }
    if ($result['status'] === \App\Service\System\TaskQueueService::STATUS_FINISHED) echo '任务 #' . $task['id'] . " 采集完成\n";
    else echo '任务 #' . $task['id'] . ' 当前状态：' . \App\Service\Collection\CollectionScheduleService::statusText($result['status']) . "，本次未重复启动；请在后台任务管理查看进度\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '采集任务：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
