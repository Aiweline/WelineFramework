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
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
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

// 解析 --frontend 参数
$isFrontend = \in_array('--frontend', $argv, true);

// 读取环境配置
$envConfig = null;
$_envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
if (\is_file($_envFile)) {
    $envConfig = require $_envFile;
}
unset($_envFile);

$isDev = (\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE)
    || ($envConfig !== null && isset($envConfig['deploy']) && $envConfig['deploy'] === 'dev');

// 日志函数（格式与 Dispatcher/Worker 保持一致）
$redirectLog = function (string $message, string $level = 'INFO') use ($isFrontend, $isDev, $httpPort) {
    $timestamp = \date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [Redirect] Port:{$httpPort} [{$level}] {$message}\n";

    $color = match($level) {
        'ERROR'   => "\033[91m",
        'WARN'    => "\033[33m",
        'INFO'    => "\033[36m",
        'IPC'     => "\033[95m",
        'DEBUG'   => "\033[90m",
        default   => "\033[0m",
    };

    $alwaysShow = \in_array($level, ['ERROR', 'WARN', 'INFO', 'IPC'], true);
    if ($alwaysShow || $isFrontend || $isDev) {
        if (\defined('STDOUT') && \is_resource(STDOUT)) {
            \fwrite(STDOUT, $color . $logMessage . "\033[0m");
            \fflush(STDOUT);
        } else {
            echo $color . $logMessage . "\033[0m";
            \flush();
        }
    }
};

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
    $redirectLog("Failed to bind {$host}:{$httpPort}: {$errstr}", 'ERROR');
    exit(1);
}

\stream_set_blocking($socket, false);

// 启动日志（与 Dispatcher/Worker 风格一致）
$redirectLog("Started on tcp://{$host}:{$httpPort}", 'INFO');
$redirectLog("Instance: {$instanceName}, HTTP→HTTPS 301 Redirect, Target port: {$httpsPort}, PID: " . \getmypid(), 'INFO');
$redirectLog("DEV=" . ($isDev ? 'ON' : 'OFF') . ", Frontend=" . ($isFrontend ? 'ON' : 'OFF'), 'INFO');

// ========== IPC 控制通道 ==========
$ipcClient = null;
$ipcReceivedShutdown = false;

// 获取控制端口
if ($controlPort <= 0) {
    $_instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
    if (\is_file($_instanceFile)) {
        $_instData = @\json_decode(\file_get_contents($_instanceFile), true);
        $controlPort = (int)($_instData['control_port'] ?? 0);
    }
    unset($_instanceFile, $_instData);
}

if ($controlPort > 0) {
    $ipcClient = new \Weline\Server\IPC\ControlClient();
    $ipcClient->setSelfTag('Redirect');
    $ipcClient->setLogger(function (string $line) use ($redirectLog) {
        $redirectLog($line, 'IPC');
    });
    // DEV 模式下输出详细 IPC SEND/RECV 明细
    $ipcClient->setVerboseLog($isDev);
    if ($ipcClient->connect('127.0.0.1', $controlPort)) {
        $ipcClient->register(
            \Weline\Server\IPC\ControlMessage::ROLE_REDIRECT,
            \getmypid(),
            $httpPort
        );
        
        // 上报就绪
        $ipcClient->sendReady(\Weline\Server\IPC\ControlMessage::ROLE_REDIRECT, 0, $httpPort);
        
        // 设置消息处理器
        $ipcClient->onMessage(function (array $msg, \Weline\Server\IPC\ControlClient $client) use (&$ipcReceivedShutdown) {
            if (($msg['type'] ?? '') === \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN) {
                $ipcReceivedShutdown = true;
            }
        });
        
        // 设置断开处理器（复活优先级 1 = HTTP Redirect，延迟 1 秒）
        $ipcClient->onDisconnect(function (bool $receivedShutdown, \Weline\Server\IPC\ControlClient $client) use ($instanceName, $controlPort, $redirectLog) {
            if ($receivedShutdown) {
                return;
            }
            $redirectLog('Master 连接意外断开，尝试复活（优先级 1，延迟 1 秒）...', 'WARN');
            $resurrector = new \Weline\Server\IPC\MasterResurrector(
                \Weline\Server\IPC\ControlMessage::RESURRECTION_REDIRECT,
                $instanceName,
                '127.0.0.1',
                $controlPort
            );
            if ($resurrector->shouldResurrect($receivedShutdown)) {
                $resurrector->attemptResurrect();
            }
            $client->tryReconnect();
        });
    } else {
        $ipcClient = null;
    }
}
// ========== IPC 控制通道结束 ==========

$connections = [];
$requestBuffers = [];

// 优雅退出函数（统一使用进程管理器清理）
$gracefulExit = function (string $reason = '') use ($socket, &$connections, $processName, &$ipcClient, $redirectLog) {
    if ($reason) {
        $redirectLog("退出: {$reason}", 'WARN');
    }
    
    // 关闭 IPC 客户端
    if ($ipcClient) {
        $ipcClient->close();
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

// 信号处理
if (\function_exists('pcntl_signal')) {
    \pcntl_signal(SIGTERM, function () use ($gracefulExit) {
        $gracefulExit('收到 SIGTERM 信号');
    });
}

// ========== 孤儿检测：Master PID 存活检查 ==========
$lastMasterCheck = \time();
$masterCheckInterval = 5;
$masterDeadCount = 0;
$masterDeadThreshold = 3;

while (true) {
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }
    
    // 检查 shutdown
    if ($ipcReceivedShutdown) {
        $gracefulExit('收到 IPC shutdown 命令');
    }
    
    // ========== 孤儿检测 ==========
    $now = \time();
    if ($masterPid > 0 && !$ipcReceivedShutdown && ($now - $lastMasterCheck) >= $masterCheckInterval) {
        $lastMasterCheck = $now;
        $masterAlive = false;
        if (\function_exists('posix_kill')) {
            $masterAlive = @\posix_kill($masterPid, 0);
        } elseif (!(\defined('IS_WIN') && IS_WIN)) {
            $masterAlive = @\file_exists("/proc/{$masterPid}");
            if (!$masterAlive) {
                @\exec("kill -0 {$masterPid} 2>/dev/null", $output, $code);
                $masterAlive = ($code === 0);
            }
        }
        if ($masterAlive) {
            $masterDeadCount = 0;
        } else {
            $masterDeadCount++;
            $redirectLog("Master PID {$masterPid} 不可达 ({$masterDeadCount}/{$masterDeadThreshold})", 'WARN');
            if ($masterDeadCount >= $masterDeadThreshold && (!$ipcClient || !$ipcClient->isConnected())) {
                $redirectLog("Master PID {$masterPid} 已死亡且 IPC 断开，自行退出（孤儿保护）", 'WARN');
                $gracefulExit('孤儿检测：Master 已死亡');
            }
        }
    }
    
    // IPC 重连
    if ($ipcClient && !$ipcClient->isConnected() && !$ipcReceivedShutdown) {
        $ipcClient->tryReconnect();
    }

    // 构建 stream_select 读数组
    $readSockets = \array_merge([$socket], $connections);
    
    // 加入 IPC 控制 socket
    $ipcSocket = ($ipcClient && $ipcClient->isConnected()) ? $ipcClient->getSocket() : null;
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
        if ($ipcClient) {
            $ipcClient->handleReadable();
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
