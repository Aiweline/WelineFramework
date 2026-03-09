<?php
declare(strict_types=1);

/**
 * Weline Server - HTTP 重定向 Worker（仅 HTTPS 启用时由 Master 启动）
 *
 * 监听 HTTP 端口（默认 80），将请求 301 重定向到 HTTPS。
 * 用法: php http_redirect_worker.php <host> <http_port> <https_port> [instance_name] [--name=xxx]
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$host = $argv[1] ?? '127.0.0.1';
$httpPort = (int) ($argv[2] ?? 80);
$httpsPort = (int) ($argv[3] ?? 443);
$instanceName = $argv[4] ?? 'default';

// 解析命令行参数
$processName = '';
$controlPort = 0;
$masterPid = 0;
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = (string)\substr($arg, 12);
    }
}

// 检测根目录
$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

// 统一自动加载
require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

// 解析 --frontend 参数
$isFrontend = \in_array('--frontend', $argv, true);

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;

if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

ErrorBootstrap::init('HttpRedirect:' . $httpPort, [
    'http_port' => $httpPort,
    'https_port' => $httpsPort,
    'instance' => $instanceName,
    'process_name' => $processName,
]);

// 前台模式：启用控制台输出
if ($isFrontend) {
    WlsLogger::getInstance()
        ->setStdoutEnabled(true)
        ->setProcessTag('HttpRedirect:' . $httpPort);
}

// 进程日志文件（持久化，跨重启保留）
if ($processName) {
    $processLogDir = BP . 'var' . DIRECTORY_SEPARATOR . 'process';
    if (!\is_dir($processLogDir)) {
        @\mkdir($processLogDir, 0777, true);
    }
    $processLogFile = $processLogDir . DIRECTORY_SEPARATOR . $processName . '.log';
    \ini_set('error_log', $processLogFile);
}

// 注册 PID 到进程管理器
if ($processName) {
    \Weline\Framework\System\Process\Processer::setPid('--name=' . $processName, \getmypid());
    // 注册监听端口（启用快速端口→PID 查找）
    if ($httpPort > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts('--name=' . $processName, [$httpPort]);
    }
}

// 读取环境配置
$envConfig = null;
$_envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
if (\is_file($_envFile)) {
    $envConfig = require $_envFile;
}
unset($_envFile);

$isDev = (\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE)
    || ($envConfig !== null && isset($envConfig['deploy']) && $envConfig['deploy'] === 'dev');


$context = \stream_context_create([
    'socket' => [
        'backlog' => 256,
        'so_reuseaddr' => true,
    ]
]);

$socket = @\stream_socket_server(
    "tcp://{$host}:{$httpPort}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if (!$socket) {
    WlsLogger::error_("Failed to bind {$host}:{$httpPort}: {$errstr}");
    exit(1);
}

\stream_set_blocking($socket, false);

// 启动日志（与 Dispatcher/Worker 风格一致）
WlsLogger::info_("Started on tcp://{$host}:{$httpPort}");
WlsLogger::info_("Instance: {$instanceName}, HTTP→HTTPS 301 Redirect, Target port: {$httpsPort}, PID: " . \getmypid());
WlsLogger::info_("DEV=" . ($isDev ? 'ON' : 'OFF') . ", Frontend=" . ($isFrontend ? 'ON' : 'OFF'));

// ========== IPC 控制通道 ==========
$ipcReceivedShutdown = false;
$orphanGuard = new \Weline\Server\IPC\ChildControl\MasterOrphanGuard();
$kernel = null;
$controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, $controlPort);
if ($controlPort > 0) {
    $identity = new \Weline\Server\IPC\ChildControl\ChildProcessIdentity(
        \Weline\Server\IPC\ControlMessage::ROLE_REDIRECT,
        \getmypid(),
        $httpPort,
        0,
        $orchestratorEpoch,
        $orchestratorLaunchId
    );
    $handler = new \Weline\Server\IPC\ChildControl\Handler\RedirectControlHandler(
        static function (bool $shutdown) use (&$ipcReceivedShutdown): void {
            $ipcReceivedShutdown = $shutdown;
        }
    );
    $kernel = new \Weline\Server\IPC\ChildControl\SubprocessControlKernel(
        $identity,
        $handler,
        'Redirect',
        $isDev
    );
    if ($kernel->connectAndRegister($controlPort)) {
        if ($isDev) {
            $client = $kernel->getClient();
            if ($client !== null) {
                WlsLogger::getInstance()->setIpcLogSink(static function (string $line, string $level, string $tag) use ($client): void {
                    if ($client->isConnected()) {
                        $client->sendLogLine($line, $level, $tag);
                    }
                });
            }
        }
    } else {
        $kernel = null;
    }
}
// ========== IPC 控制通道结束 ==========

$connections = [];
$requestBuffers = [];

// 优雅退出函数（统一使用进程管理器清理）
$gracefulExit = function (string $reason = '') use ($socket, &$connections, $processName, &$kernel, $httpPort) {
    if ($reason) {
        WlsLogger::warning_("退出: {$reason}");
    }
    
    // 通知 Master 即将退出（IPC exited 消息）
    if ($kernel && $kernel->isConnected()) {
        $kernel->sendExited();
        WlsLogger::info_("已发送 exited 消息给 Master");
    }
    
    // 关闭 IPC 客户端
    if ($kernel) {
        $kernel->close();
    }
    
    foreach ($connections as $conn) {
        @\fclose($conn);
    }
    @\fclose($socket);
    
    // 使用进程管理器清理 PID 文件
    if ($processName) {
        \Weline\Framework\System\Process\Processer::destroy('--name=' . $processName);
    }
    
    exit(0);
};

// 信号处理（仅 Linux/Mac）
// 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
if (\function_exists('pcntl_signal')) {
    \pcntl_signal(SIGINT, SIG_IGN);
    \pcntl_signal(SIGTERM, function () use ($gracefulExit) {
        $gracefulExit('收到 SIGTERM 信号');
    });
}

while (true) {
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }
    
    // 检查 shutdown
    if ($ipcReceivedShutdown) {
        $gracefulExit('收到 IPC shutdown 命令');
    }
    
    // ========== 孤儿检测（IPC 优先） ==========
    if ($orphanGuard->shouldExit(
        $masterPid,
        $kernel !== null && $kernel->isConnected(),
        $ipcReceivedShutdown,
        'Redirect'
    )) {
        WlsLogger::warning_("Master PID {$masterPid} 已死亡，自行退出（孤儿保护）");
        $gracefulExit('孤儿检测：Master 已死亡');
    }
    
    // IPC 重连
    if ($kernel && !$kernel->isConnected() && !$ipcReceivedShutdown) {
        $kernel->reconnect();
    }

    // 构建 stream_select 读数组
    $readSockets = \array_merge([$socket], $connections);
    
    // 加入 IPC 控制 socket
    $ipcSocket = ($kernel && $kernel->isConnected()) ? $kernel->getSocket() : null;
    if ($ipcSocket && \is_resource($ipcSocket)) {
        $readSockets[] = $ipcSocket;
    }
    
    $read = $readSockets;
    $write = [];
    $except = [];
    $changed = @\stream_select($read, $write, $except, 0, 100000);

    if ($changed === false) {
        continue;
    }

    // 处理 IPC 控制通道消息（处理后从 $read 移除，防止被 HTTP 连接循环误关闭）
    if ($ipcSocket && \in_array($ipcSocket, $read, true)) {
        if ($kernel) {
            $kernel->tick();
        }
        $ipcKey = \array_search($ipcSocket, $read, true);
        if ($ipcKey !== false) {
            unset($read[$ipcKey]);
        }
    }
    
    if (\in_array($socket, $read, true)) {
        $conn = @\stream_socket_accept($socket, 0);
        if ($conn) {
            \stream_set_blocking($conn, false);
            $connId = \get_resource_id($conn);
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
        }
        $key = \array_search($socket, $read);
        unset($read[$key]);
    }

    foreach ($read as $conn) {
        $connId = \get_resource_id($conn);
        $data = @\fread($conn, 65535);

        if ($data === false || $data === '') {
            @\fclose($conn);
            unset($connections[$connId], $requestBuffers[$connId]);
            continue;
        }

        $requestBuffers[$connId] = ($requestBuffers[$connId] ?? '') . $data;
        $buf = $requestBuffers[$connId];

        if (\strpos($buf, "\r\n\r\n") === false) {
            continue;
        }

        $requestHeaders = \explode("\r\n", $buf);
        $requestLine = $requestHeaders[0] ?? '';
        $path = '/';
        $hostHeader = $host . ':' . $httpPort;

        if (\preg_match('/^\w+\s+(\S+)/', $requestLine, $m)) {
            $path = \parse_url($m[1], PHP_URL_PATH) ?: '/';
        }
        foreach ($requestHeaders as $line) {
            if (\stripos($line, 'Host:') === 0) {
                $hostHeader = \trim(\substr($line, 5));
                break;
            }
        }

        $redirectHost = $hostHeader;
        if (\strpos($redirectHost, ':') !== false) {
            $redirectHost = \explode(':', $redirectHost, 2)[0];
        }
        $redirectPort = $httpsPort;
        $redirectUrl = ($redirectPort === 443)
            ? "https://{$redirectHost}{$path}"
            : "https://{$redirectHost}:{$redirectPort}{$path}";

        $body = '';
        $response = "HTTP/1.1 301 Moved Permanently\r\n";
        $response .= "Location: {$redirectUrl}\r\n";
        $response .= "Content-Type: text/html; charset=utf-8\r\n";
        $response .= "Content-Length: " . \strlen($body) . "\r\n";
        $response .= "Connection: close\r\n\r\n";
        $response .= $body;

        @\fwrite($conn, $response);
        @\fclose($conn);
        unset($connections[$connId], $requestBuffers[$connId]);
    }
}
