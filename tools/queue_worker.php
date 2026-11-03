<?php
// CLI 单批消费；由进程管理器或计划任务重复调用，避免长期持有请求级配置和缓存。
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
chdir(dirname(__DIR__));
if (!is_file('data/install.lock') && !is_file('core/config/install.lock')
    && !is_file('core/install.lock')) exit(0);
define('SKIP_ROUTE', true);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'NiuNiuCMS queue worker';
try {
    require 'index.php';
    // worker 不需要浏览器会话，也不应在采集过程中持有会话锁。
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $options = getopt('', array('limit:'));
    $limit = max(1, min(20, (int)($options['limit'] ?? 1)));
    $results = \App\Service\System\TaskQueueService::processDetailed($limit);
    $failed = false;
    foreach ($results as $result) {
        if ($result['error'] !== '') {
            $failed = true;
            fwrite(STDERR, '任务 #' . $result['id'] . ' 采集失败：' . $result['error'] . '；重试 ' . $result['retry'] . '/3' . PHP_EOL);
        } else echo '任务 #' . $result['id'] . ($result['status'] === \App\Service\System\TaskQueueService::STATUS_FINISHED ? ' 采集完成' : ' 本批已采集，等待继续') . PHP_EOL;
    }
    if ($failed) exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Queue worker: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
