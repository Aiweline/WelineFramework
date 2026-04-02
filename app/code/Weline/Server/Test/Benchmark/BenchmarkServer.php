<?php
declare(strict_types=1);

/**
 * Weline Server 压测服务器（简化版）
 */

// 确保在 CLI 模式下运行
if (PHP_SAPI !== 'cli') {
    exit('Only CLI mode is supported');
}

// 加载框架
$baseDir = dirname(__DIR__, 6);
require_once $baseDir . '/vendor/autoload.php';

// 定义翻译函数（如果未定义）- 在全局命名空间下
if (!function_exists('__')) {
    function __(string $text, array $params = []): string {
        if (empty($params)) {
            return $text;
        }
        foreach ($params as $key => $value) {
            $text = str_replace('%{' . $key . '}', (string)$value, $text);
        }
        return $text;
    }
}

// 定义常量
if (!defined('BP')) {
    define('BP', $baseDir . DIRECTORY_SEPARATOR);
}

use Weline\Server\Worker;
use Weline\Server\Protocol\Response;

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 8888);
$count = (int) ($argv[3] ?? 1);

echo "启动压测服务器: http://{$host}:{$port} (Worker x{$count})\n";

// 设置 $argv 为 start 命令，让 Worker 正常启动
$argv = [$argv[0], 'start'];

// 创建 HTTP Worker
$worker = new Worker("http://{$host}:{$port}");
$worker->count = $count;
$worker->name = 'BenchmarkServer';

// 设置日志
Worker::$logFile = $baseDir . '/var/log/wls/benchmark_server.log';
Worker::$pidFile = $baseDir . '/var/run/benchmark_server.pid';

// 请求计数器
$requestCount = 0;

$worker->onWorkerStart = function(Worker $w) {
    Worker::log("Benchmark Worker #{$w->id} started");
};

$worker->onMessage = function($connection, $data) use (&$requestCount) {
    $requestCount++;
    
    // 简单返回 Hello 消息
    $body = 'Hello Weline Server! Request #' . $requestCount;
    $response = "HTTP/1.1 200 OK\r\n";
    $response .= "Content-Type: text/plain; charset=utf-8\r\n";
    $response .= "Content-Length: " . strlen($body) . "\r\n";
    $response .= "Connection: keep-alive\r\n";
    $response .= "\r\n";
    $response .= $body;
    
    $connection->send($response);
};

$worker->onWorkerStop = function(Worker $w) use (&$requestCount) {
    Worker::log("Worker #{$w->id} stopped. Total requests: {$requestCount}");
};

// 运行
Worker::runAll();
