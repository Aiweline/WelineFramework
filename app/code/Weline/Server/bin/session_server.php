<?php

declare(strict_types=1);

/**
 * WLS Session Server 入口脚本
 *
 * 独立的 Session 存储服务进程，为所有 Worker 提供共享 Session 存储。
 * 由 Master 进程启动和管理，支持优雅关闭和自动复活。
 *
 * 用法: php session_server.php <host> <port> [instance_name] [--name=xxx] [--control-port=xxx] [--master-pid=xxx]
 *
 * @author Aiweline
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19970);
$instanceName = $argv[3] ?? 'default';

$processName = '';
$controlPort = 0;
$masterPid = 0;
$isFrontend = false;
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
    } elseif ($arg === '--frontend' || $arg === '-f') {
        $isFrontend = true;
    }
}

$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;

if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

ErrorBootstrap::init('SessionServer:' . $port, [
    'port' => $port,
    'instance' => $instanceName,
    'process_name' => $processName,
]);

// 前台模式：启用控制台输出
if ($isFrontend) {
    WlsLogger::getInstance()
        ->setStdoutEnabled(true)
        ->setProcessTag('SessionServer:' . $port);
}

if ($processName) {
    $processLogDir = BP . 'var' . DIRECTORY_SEPARATOR . 'process';
    if (!\is_dir($processLogDir)) {
        @\mkdir($processLogDir, 0777, true);
    }
    $processLogFile = $processLogDir . DIRECTORY_SEPARATOR . $processName . '.log';
    \ini_set('error_log', $processLogFile);
}

if ($processName) {
    \Weline\Framework\System\Process\Processer::setPid('--name=' . $processName, \getmypid());
    if ($port > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts('--name=' . $processName, [$port]);
    }
}

$envConfig = null;
$_envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
if (\is_file($_envFile)) {
    $envConfig = require $_envFile;
}
unset($_envFile);

$isDev = (\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE)
    || ($envConfig !== null && isset($envConfig['deploy']) && $envConfig['deploy'] === 'dev');

$sessionConfig = $envConfig['session']['drivers']['wls'] ?? $envConfig['session']['wls']['wls_server'] ?? [];
$sessionConfig['port'] = $port;
$sessionConfig['persist_path'] = BP . 'var' . DIRECTORY_SEPARATOR . 'session' . DIRECTORY_SEPARATOR;

$server = new \Weline\Server\Session\Server\SessionServer($sessionConfig);

if (!$server->start($host, $port)) {
    WlsLogger::error_("Failed to start Session Server on {$host}:{$port}");
    exit(1);
}

WlsLogger::info_("Started on tcp://{$host}:{$port}");
WlsLogger::info_("Instance: {$instanceName}, PID: " . \getmypid());
WlsLogger::info_("DEV=" . ($isDev ? 'ON' : 'OFF') . ", Frontend=" . ($isFrontend ? 'ON' : 'OFF'));
WlsLogger::info_("Config: max_sessions=" . ($sessionConfig['max_sessions'] ?? 50000) .
    ", persist_interval=" . ($sessionConfig['persist_interval'] ?? 60) . "s" .
    ", session_ttl=" . ($sessionConfig['session_ttl'] ?? 3600) . "s");

$ipcClient = null;
$ipcReceivedShutdown = false;

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
    $ipcClient->setSelfTag('SessionServer');

    if ($ipcClient->connect('127.0.0.1', $controlPort)) {
        $ipcClient->register(\Weline\Server\IPC\ControlMessage::ROLE_SESSION_SERVER, \getmypid(), $port, 0, $orchestratorEpoch, $orchestratorLaunchId);
        $ipcClient->sendReady(\Weline\Server\IPC\ControlMessage::ROLE_SESSION_SERVER, 0, $port, $orchestratorEpoch, $orchestratorLaunchId);

        $ipcClient->onMessage(function (array $msg) use ($server, &$ipcReceivedShutdown, $ipcClient, $port) {
            $type = $msg['type'] ?? '';

            switch ($type) {
                case \Weline\Server\IPC\ControlMessage::TYPE_DRAIN:
                    // Session server 没有 HTTP 请求需要排水，直接完成
                    WlsLogger::info_('Received drain signal, completing immediately...');
                    $ipcClient->sendDrainingComplete(0, $port);
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN:
                    WlsLogger::info_('Received shutdown signal, stopping...');
                    $ipcReceivedShutdown = true;
                    $server->setRunning(false);
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_CACHE_CLEAR:
                    WlsLogger::info_('Received cache_clear, persisting sessions...');
                    $server->getStore()->forcePersist();
                    break;

                default:
                    WlsLogger::debug_("Received unknown message type: {$type}");
            }
        });

        $ipcClient->onDisconnect(function (bool $receivedShutdown) use ($server, $masterPid, $instanceName, $controlPort, &$ipcReceivedShutdown) {
            if ($receivedShutdown || $ipcReceivedShutdown) {
                WlsLogger::info_('收到 Master shutdown 信号，Session Server 优雅退出');
                $server->setRunning(false);
                return;
            }

            WlsLogger::warning_("Master PID {$masterPid} 已死亡，Session Server 自行退出（孤儿保护）");
            $server->setRunning(false);
        });

        WlsLogger::info_("Connected to Master IPC on port {$controlPort}");
    } else {
        WlsLogger::warning_("Failed to connect to Master IPC on port {$controlPort}");
        $ipcClient = null;
    }
}

// 信号处理（仅 Linux/Mac）
// 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
if (\function_exists('pcntl_signal')) {
    \pcntl_signal(SIGTERM, function () use ($server) {
        WlsLogger::info_('收到 SIGTERM 信号，执行优雅退出');
        $server->setRunning(false);
    });
}

// 孤儿保护：主动检测 Master 存活状态
$lastMasterCheck = \time();
$masterCheckInterval = 5; // 每 5 秒检查一次
$masterDeadCount = 0;     // Master 连续不可达计数
$masterDeadThreshold = 3; // 连续 3 次（15 秒）确认 Master 死亡后自行退出

while ($server->isRunning()) {
    // 信号派发（Linux/macOS）
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }
    // 处理 Session Server 客户端请求
    $server->tick(50000); // 50ms
    
    // 每次循环都检查 IPC 消息（确保及时响应 shutdown 等命令）
    if ($ipcClient !== null && $ipcClient->isConnected()) {
        $ipcClient->handleReadable();
    }
    
    // 孤儿保护：定期检查 Master 是否存活
    $now = \time();
    if ($now - $lastMasterCheck >= $masterCheckInterval) {
        $lastMasterCheck = $now;
        
        // IPC 连接正常则 Master 存活
        if ($ipcClient !== null && $ipcClient->isConnected()) {
            $masterDeadCount = 0;
        } else {
            // IPC 断开，检查 Master 进程是否存在
            $masterAlive = \Weline\Framework\System\Process\Processer::processExists($masterPid);
            if ($masterAlive) {
                // Master 存活但 IPC 断开，可能是网络问题，尝试重连
                $masterDeadCount = 0;
            } else {
                $masterDeadCount++;
                WlsLogger::warning_("Master PID {$masterPid} 不可达且 IPC 断开 ({$masterDeadCount}/{$masterDeadThreshold})");
                if ($masterDeadCount >= $masterDeadThreshold) {
                    WlsLogger::warning_("Master PID {$masterPid} 已死亡，Session Server 自行退出（孤儿保护）");
                    $server->setRunning(false);
                }
            }
        }
    }
}

WlsLogger::info_('Shutting down...');
$server->stop();

if ($processName) {
    \Weline\Framework\System\Process\Processer::removePidFile('--name=' . $processName);
}

WlsLogger::info_('Session Server stopped');
exit(0);
