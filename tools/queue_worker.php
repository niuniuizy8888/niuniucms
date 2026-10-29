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
    $done = \App\Service\System\TaskQueueService::process($limit);
    if ($done) echo 'Processed: ' . $done . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, 'Queue worker: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
