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

$normalizeArgValue = static fn(string $value): string => \trim($value, " \t\n\r\0\x0B\"'");

$host = $normalizeArgValue((string)($argv[1] ?? '127.0.0.1'));
$port = (int)($argv[2] ?? 0);
$instanceName = $normalizeArgValue((string)($argv[3] ?? 'default'));

$processName = '';
$controlPort = 0;
$masterPid = 0;
$isFrontend = false;
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
$role = 'session_server';
$tokenFileName = '';
$bootstrapInstance = '';
$sharedService = false;
$logInstanceName = '';

foreach ($argv as $arg) {
    $arg = (string) $arg;
    if (\str_starts_with($arg, '--name=')) {
        $processName = $normalizeArgValue((string)\substr($arg, 7));
    } elseif (\str_starts_with($arg, '--instance-name=')) {
        $instanceName = $normalizeArgValue((string)\substr($arg, 16));
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = $normalizeArgValue((string)\substr($arg, 12));
    } elseif (\str_starts_with($arg, '--role=')) {
        $argRole = $normalizeArgValue((string)\substr($arg, 7));
        if ($argRole !== '') {
            $role = $argRole;
        }
    } elseif (\str_starts_with($arg, '--token-file-name=')) {
        $tokenFileName = $normalizeArgValue((string)\substr($arg, 18));
    } elseif (\str_starts_with($arg, '--bootstrap-instance=')) {
        $bootstrapInstance = $normalizeArgValue((string)\substr($arg, 21));
    } elseif (\str_starts_with($arg, '--log-instance-name=')) {
        $logInstanceName = $normalizeArgValue((string)\substr($arg, 20));
    } elseif ($arg === '--shared-service=1' || $arg === '--shared-service' || $arg === '-shared-service') {
        $sharedService = true;
    } elseif ($arg === '--frontend' || $arg === '-frontend' || $arg === '--win' || $arg === '-win' || $arg === '-f') {
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
\Weline\Server\Log\LogConfig::bootstrapVerboseFromInstanceFile($instanceName);
(new \Weline\Server\Service\LongRunningPhpRuntime())->apply();

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;

if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

$processLabel = $role === 'memory_server' ? 'MemoryService' : 'SessionServer';
$processTag = $processLabel . ':' . $port . '@' . $instanceName;

ErrorBootstrap::init($processTag, [
    'port' => $port,
    'instance' => $instanceName,
    'role' => $role,
    'process_name' => $processName,
    'shared_service' => $sharedService,
    'bootstrap_instance' => $bootstrapInstance,
]);

WlsLogger::getInstance()
    ->setStdoutEnabled(\Weline\Server\Log\LogConfig::isStdoutEnabled($isFrontend, \Weline\Server\Log\LogConfig::isDevMode()))
    ->setProcessTag($processTag);

if ($processName) {
    $processLogInstanceName = $logInstanceName !== '' ? $logInstanceName : $instanceName;
    \Weline\Server\Service\WlsLogService::prepareProcessLogFile($processName, $processLogInstanceName, $processTag);
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
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);

$sessionConfig = (\is_array($envConfig) && \is_array($envConfig['wls']['session'] ?? null))
    ? $envConfig['wls']['session'] : [];
$sharedServiceConfig = (\is_array($envConfig) && \is_array($envConfig['wls']['shared_service'] ?? null))
    ? $envConfig['wls']['shared_service'] : [];
foreach (['empty_token_exit_grace_sec', 'empty_token_check_interval_sec', 'startup_consumer_grace_sec'] as $sharedConfigKey) {
    if (\array_key_exists($sharedConfigKey, $sharedServiceConfig)
        && !\array_key_exists($sharedConfigKey, $sessionConfig)) {
        $sessionConfig[$sharedConfigKey] = $sharedServiceConfig[$sharedConfigKey];
    }
}
$sessionConfig['port'] = $port;
$sessionConfig['role'] = $role;
$sessionConfig['persist_path'] = BP . 'var' . DIRECTORY_SEPARATOR . 'session' . DIRECTORY_SEPARATOR;
$persistFileName = \trim((string)($sessionConfig['persist_file_name'] ?? ''));
if ($persistFileName === '') {
    $persistFileName = $role === 'memory_server' ? 'wls_memory_store.dat' : 'wls_session_store.dat';
}
$sessionConfig['persist_file_name'] = $persistFileName;
if ($role === 'memory_server') {
    $sessionConfig['persist_enabled'] = false;
}
$safeRole = \preg_replace('/[^a-z0-9_]/i', '_', (string)$role) ?: 'session_server';
$tokenFileName = \trim($tokenFileName);
if ($tokenFileName === '') {
    $tokenFileName = $safeRole . '.token';
}
$sessionConfig['token_file_name'] = $tokenFileName;

$server = new \Weline\Server\Session\Server\SessionServer($sessionConfig);

if (!$server->start($host, $port)) {
    $detail = $server->getLastBindError();
    WlsLogger::error_(
        "Failed to start Session Server on {$host}:{$port}" . ($detail !== null ? ": {$detail}" : '')
    );
    exit(1);
}

WlsLogger::info_("Started on tcp://{$host}:{$port}");
WlsLogger::info_("Instance: {$instanceName}, role={$role}, PID: " . \getmypid());
if ($bootstrapInstance !== '') {
    WlsLogger::info_("Bootstrap requester instance: {$bootstrapInstance}");
}
if ($sharedService) {
    WlsLogger::info_('Shared service mode: shared endpoint');
}
WlsLogger::info_("DEV=" . ($isDev ? 'ON' : 'OFF') . ", Frontend=" . ($isFrontend ? 'ON' : 'OFF'));
WlsLogger::info_("Config: max_sessions=" . ($sessionConfig['max_sessions'] ?? 50000) .
    ", persist_interval=" . ($sessionConfig['persist_interval'] ?? 60) . "s" .
    ", session_ttl=" . ($sessionConfig['session_ttl'] ?? 3600) . "s");
WlsLogger::info_("Persist file: " . ($sessionConfig['persist_file_name'] ?? 'wls_session_store.dat'));

$ipcReceivedShutdown = false;
$kernel = null;
$orphanGuard = new \Weline\Server\IPC\ChildControl\MasterOrphanGuard();
$lastSharedOrphanSuppressedAt = 0;
// IPC control port. Shared sidecars without an explicit control port stay
// token/consumer managed instead of publishing runtime state through files.
$shouldResolveControlPort = !$sharedService || $controlPort > 0 || $supervisorEnabled;
if ($shouldResolveControlPort && $controlPort <= 0) {
    $controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, 0, 30);
}
$controlPort = $shouldResolveControlPort
    ? \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, $controlPort)
    : 0;
if ($controlPort > 0 || $supervisorEnabled) {
    $identity = new \Weline\Server\IPC\ChildControl\ChildProcessIdentity(
        $role,
        \getmypid(),
        $port,
        0,
        $orchestratorEpoch,
        $orchestratorLaunchId
    );
    $handler = new \Weline\Server\IPC\ChildControl\Handler\SessionServerControlHandler(
        static function () use (&$kernel): void {
            $kernel?->sendDrainingComplete();
        },
        static function () use (&$ipcReceivedShutdown, $server): void {
            $ipcReceivedShutdown = true;
            $server->setRunning(false);
        },
        static function () use ($server): void {
            $server->getStore()->forcePersist();
        }
    );
    $kernel = new \Weline\Server\IPC\ChildControl\SubprocessControlKernel(
        $identity,
        $handler,
        'SessionServer',
        $isDev,
        $instanceName
    );
    if ($kernel->connectAndRegister($controlPort)) {
        WlsLogger::info_("Connected to Master IPC on port {$controlPort}");
        if (\Weline\Server\Log\LogConfig::isDevMode() || $isFrontend) {
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
        WlsLogger::warning_("Failed to connect to Master IPC on port {$controlPort}");
    }
}

// 信号处理（仅 Linux/Mac）
// 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
// Daemon 下向已关闭连接写数据会触发 SIGPIPE 导致进程退出，与 Nginx 一致忽略 SIGPIPE
if (\function_exists('pcntl_signal')) {
    if (\defined('SIGPIPE')) {
        \pcntl_signal(SIGPIPE, SIG_IGN);
    }
    \pcntl_signal(SIGINT, SIG_IGN);
    \pcntl_signal(SIGTERM, function () use ($server) {
        WlsLogger::info_('收到 SIGTERM 信号，执行优雅退出');
        $server->setRunning(false);
    });
}

while ($server->isRunning()) {
    // 信号派发（Linux/macOS）
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }
    // 处理 Session Server 客户端请求
    $server->tick(50000); // 50ms
    
    // 每次循环都检查 IPC 消息（确保及时响应 shutdown 等命令）
    if ($kernel !== null && $kernel->isConnected()) {
        $kernel->tick();
        $kernel->flushWrites();
    }
    $server->maintainSharedConsumerTokens();

    if ($kernel !== null && !$kernel->isConnected() && !$ipcReceivedShutdown) {
        $kernel->reconnect();
    }
    if ($orphanGuard->shouldExit(
        $masterPid,
        $kernel !== null && $kernel->isConnected(),
        $ipcReceivedShutdown,
        'SessionServer'
    )) {
        $sharedServiceHasConsumers = $sharedService && $server->hasActiveConsumers();
        $sharedServiceIdleWindowOpen = $sharedService && $server->isSharedConsumerIdleWindowOpen();
        if ($sharedServiceHasConsumers || $sharedServiceIdleWindowOpen) {
            $now = \time();
            if (($now - $lastSharedOrphanSuppressedAt) >= 30) {
                $reason = $sharedServiceHasConsumers ? '仍有有效令牌' : '仍在启动/空令牌共识窗口内';
                WlsLogger::warning_("Master PID {$masterPid} 已死亡，但共享服务{$reason}，继续运行并等待令牌自治清场");
                $lastSharedOrphanSuppressedAt = $now;
            }
            continue;
        }
        WlsLogger::warning_("Master PID {$masterPid} 已死亡，Session Server 自行退出（孤儿保护）");
        $server->setRunning(false);
    }
}

WlsLogger::info_('Shutting down...');
$server->stop();

if ($processName) {
    \Weline\Framework\System\Process\Processer::removePidFile('--name=' . $processName);
}

WlsLogger::info_('Session Server stopped');
exit(0);
