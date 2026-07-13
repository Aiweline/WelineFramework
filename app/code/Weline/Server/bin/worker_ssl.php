<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程 (SSL/HTTPS)
 * 
 * 用法: php worker_ssl.php <host> <port> <worker_id> <instance_name> <ssl_cert> <ssl_key>
 * 
 * 该 Worker 进程集成框架路由，支持完整的 HTTPS 请求处理
 * 包含健康检查接口 /_wls/health（仅本地访问）
 * 维护模式由框架自动处理
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'worker_runtime_common.php';
if (!\function_exists('wlsRuntimeEffectiveUserName')) {
    function wlsRuntimeEffectiveUserName(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $info = @\posix_getpwuid((int) \posix_geteuid());
            if (\is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        foreach (['USER', 'LOGNAME', 'USERNAME'] as $name) {
            $value = \getenv($name);
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
if (!\function_exists('wlsRuntimeEffectiveGroupName')) {
    function wlsRuntimeEffectiveGroupName(): string
    {
        if (\function_exists('posix_getegid') && \function_exists('posix_getgrgid')) {
            $info = @\posix_getgrgid((int) \posix_getegid());
            if (\is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        return '';
    }
}
if (!\function_exists('wlsEnsureRuntimeFileReadable')) {
    function wlsEnsureRuntimeFileReadable(string $path, int $mode = 0640): bool
    {
        $path = \trim($path);
        if ($path === '' || !\is_file($path)) {
            return false;
        }

        \clearstatcache(true, $path);
        if (\is_readable($path)) {
            return true;
        }

        @\chmod($path, $mode);
        \clearstatcache(true, $path);
        if (\is_readable($path)) {
            return true;
        }

        static $sudoAttempted = [];
        if (isset($sudoAttempted[$path]) || DIRECTORY_SEPARATOR === '\\') {
            return false;
        }
        $sudoAttempted[$path] = true;

        $user = wlsRuntimeEffectiveUserName();
        if ($user === '') {
            return false;
        }

        $group = wlsRuntimeEffectiveGroupName();
        $owner = $group !== '' ? $user . ':' . $group : $user;
        $script = 'chown -- "$2" "$1" && chmod u+r "$1"';
        $command = 'sudo -n sh -c ' . \escapeshellarg($script)
            . ' sh ' . \escapeshellarg($path)
            . ' ' . \escapeshellarg($owner)
            . ' 2>/dev/null';
        @\exec($command);

        \clearstatcache(true, $path);
        return \is_readable($path);
    }
}

$wlsMemoryLimit = '256M';
@\ini_set('memory_limit', $wlsMemoryLimit);

// 解析命令行参数
$processName = '';
$isFrontend = false;
$useReusePort = false;  // 是否使用 SO_REUSEPORT（Linux 直连模式）
$listenFd = 0;          // macOS direct: Master 预绑定的共享监听 FD
$deferSsl = false;      // 延迟 SSL 模式（用于 TCP 透传架构，先接受 TCP 连接，再手动启用 SSL）
                        // 注意：延迟 SSL 仅改变握手时机，不消除 TLS 问题。Windows 下若出现 TLS reset，
                        // 可改用 --no-ssl 或 wls.https=false 做 HTTP 验证；或安装 event 扩展后再测 HTTPS。
$wlsLoopDriver = 'auto';
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
$workerCount = 1;
$wlsRuntimeTopology = 'auto';
$masterLeaseFile = '';
$masterToken = '';
$publicOrigin = '';

// 先提取位置参数（跳过以 -- 开头的参数）
$positionalArgs = [];
foreach ($argv as $i => $arg) {
    if ($i === 0) continue; // 跳过脚本名
    if (!\str_starts_with($arg, '--') && !\str_starts_with($arg, '-')) {
        $positionalArgs[] = $arg;
    }
}

$host = $positionalArgs[0] ?? '127.0.0.1';
$port = (int) ($positionalArgs[1] ?? 9981);
$workerId = (int) ($positionalArgs[2] ?? 1);
$instanceName = $positionalArgs[3] ?? 'default';
$sslCert = $positionalArgs[4] ?? '';
$sslKey = $positionalArgs[5] ?? '';

// 解析命名参数
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend' || $arg === '-frontend' || $arg === '--win' || $arg === '-win') {
        $isFrontend = true;
    } elseif ($arg === '--reuseport' || $arg === '-reuseport') {
        $useReusePort = true;
    } elseif (\str_starts_with($arg, '--listen-fd=')) {
        $listenFd = (int)\substr($arg, 12);
    } elseif ($arg === '--defer-ssl' || $arg === '-defer-ssl') {
        $deferSsl = true;
    } elseif (\str_starts_with($arg, '--host=')) {
        $host = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--port=')) {
        $port = (int)\substr($arg, 7);
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif ($arg === '--maintenance') {
        $isMaintenanceWorker = true;
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = (string)\substr($arg, 12);
    } elseif (\str_starts_with($arg, '--master-lease-file=')) {
        $masterLeaseFile = (string)\substr($arg, 20);
    } elseif (\str_starts_with($arg, '--master-token=')) {
        $masterToken = (string)\substr($arg, 15);
    } elseif (\str_starts_with($arg, '--ssl-cert=')) {
        $sslCert = \substr($arg, 11);
    } elseif (\str_starts_with($arg, '--ssl-key=')) {
        $sslKey = \substr($arg, 10);
    } elseif (\str_starts_with($arg, '--wls-loop-driver=')) {
        $wlsLoopDriver = (string)\substr($arg, 18);
    } elseif (\str_starts_with($arg, '--memory-limit=')) {
        $wlsMemoryLimit = wlsNormalizeMemoryLimit(\substr($arg, 15));
    } elseif (\str_starts_with($arg, '--worker-count=')) {
        $workerCount = \max(1, (int)\substr($arg, 15));
    } elseif (\str_starts_with($arg, '--wls-runtime-topology=')) {
        $wlsRuntimeTopology = \strtolower(\trim((string)\substr($arg, 23)));
    } elseif (\str_starts_with($arg, '--public-origin=')) {
        $publicOrigin = (string)\substr($arg, 16);
    }
}
@\ini_set('memory_limit', $wlsMemoryLimit);

if ($listenFd > 0 && $useReusePort) {
    \fwrite(\STDERR, "--listen-fd and --reuseport are mutually exclusive.\n");
    exit(1);
}
if ($listenFd > 0 && ($listenFd < 3 || $wlsRuntimeTopology !== 'direct' || \PHP_OS_FAMILY === 'Windows')) {
    \fwrite(\STDERR, "--listen-fd requires POSIX direct topology and an inherited descriptor >= 3.\n");
    exit(1);
}

// 检测根目录
$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

// Autoload before resolving the Master bootstrap endpoint.
require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'worker_http_message.php';

\Weline\Server\Log\LogConfig::bootstrapVerboseFromInstanceFile($instanceName);

// Master PID / lease 先验检查要早于 endpoint resolve 与 listen/bind。
if (!isset($masterPid) || $masterPid <= 0) {
    $masterPid = 0;
}
if (!isset($isMaintenanceWorker)) {
    $isMaintenanceWorker = false;
}
$childMasterGuard = new \Weline\Server\IPC\ChildControl\ChildMasterGuard(
    $masterPid,
    $masterLeaseFile,
    $masterToken,
    ($isMaintenanceWorker ? 'MaintenanceSSLWorker' : 'SSLWorker') . "#{$workerId}",
    $instanceName,
    $orchestratorEpoch
);
$childMasterGuard->assertAliveOrExit('启动前 Master 自治检查');

// IPC control port. Prefer the explicit Master-provided argument; the endpoint
// file is only a bootstrap pointer when the argument is absent.
if (!isset($controlPort)) {
    $controlPort = 0;
}
$listenerHost = (string) $host;
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);
if ($controlPort <= 0 && !$supervisorEnabled) {
    $controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, $controlPort, 30);
}
if ($isMaintenanceWorker && !\defined('WLS_MAINTENANCE_WORKER')) {
    \define('WLS_MAINTENANCE_WORKER', true);
}
$_SERVER['WLS_PROCESS_ROLE'] = $isMaintenanceWorker ? 'maintenance' : 'worker';
$_ENV['WLS_PROCESS_ROLE'] = $_SERVER['WLS_PROCESS_ROLE'];
@\putenv('WLS_PROCESS_ROLE=' . $_SERVER['WLS_PROCESS_ROLE']);
$_SERVER['WLS_INSTANCE'] = $instanceName;
$_ENV['WLS_INSTANCE'] = $instanceName;
@\putenv('WLS_INSTANCE=' . $instanceName);
$_SERVER['WLS_WORKER_ID'] = (string)$workerId;
$_ENV['WLS_WORKER_ID'] = (string)$workerId;
@\putenv('WLS_WORKER_ID=' . (string)$workerId);
$_SERVER['WLS_WORKER_COUNT'] = (string)$workerCount;
$_ENV['WLS_WORKER_COUNT'] = (string)$workerCount;
@\putenv('WLS_WORKER_COUNT=' . (string)$workerCount);
$_SERVER['WLS_RUNTIME_TOPOLOGY'] = $wlsRuntimeTopology;
$_ENV['WLS_RUNTIME_TOPOLOGY'] = $wlsRuntimeTopology;
@\putenv('WLS_RUNTIME_TOPOLOGY=' . $wlsRuntimeTopology);
\Weline\Server\Service\Runtime\WorkerReadinessState::reset($wlsRuntimeTopology);
$_SERVER['WLS_PORT'] = (string)$port;
$_ENV['WLS_PORT'] = (string)$port;
@\putenv('WLS_PORT=' . (string)$port);
if ($publicOrigin !== '') {
    $_SERVER['WLS_PUBLIC_ORIGIN'] = $publicOrigin;
    $_ENV['WLS_PUBLIC_ORIGIN'] = $publicOrigin;
    @\putenv('WLS_PUBLIC_ORIGIN=' . $publicOrigin);
}

// 将相对路径转换为绝对路径
if ($sslCert && !\preg_match('/^[a-zA-Z]:[\\\\\\/]|^\//', $sslCert)) {
    $sslCert = $bp . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslCert);
}
if ($sslKey && !\preg_match('/^[a-zA-Z]:[\\\\\\/]|^\//', $sslKey)) {
    $sslKey = $bp . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslKey);
}


// 定义前端模式常量（供 WlsRuntime 使用）
wlsEnsureRuntimeFileReadable($sslCert, 0644);
wlsEnsureRuntimeFileReadable($sslKey, 0600);

if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}
// 预读 env.php 判断开发模式（在框架初始化前定义，供 WlsRequest 等使用）
$_wlsEnvFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
$_wlsEnvConfig = \is_file($_wlsEnvFile) ? @include $_wlsEnvFile : [];
$_wlsSystemConfig = \is_array($_wlsEnvConfig['system'] ?? null) ? $_wlsEnvConfig['system'] : [];
$_wlsDevMode = (($_wlsSystemConfig['deploy'] ?? $_wlsEnvConfig['deploy'] ?? '') === 'dev');
if (!\defined('WLS_DEV_MODE')) {
    \define('WLS_DEV_MODE', $_wlsDevMode);
}
unset($_wlsEnvFile, $_wlsEnvConfig, $_wlsSystemConfig, $_wlsDevMode);

(new \Weline\Server\Service\LongRunningPhpRuntime())->apply();

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;
use Weline\Server\Service\InternalRequestLabel;
use Weline\Server\Service\WorkerProcessLabel;

$processTag = WorkerProcessLabel::buildLogTag(true, $isMaintenanceWorker, $workerId, $port, $instanceName);
if (\function_exists('cli_set_process_title')) {
    @\cli_set_process_title(
        WorkerProcessLabel::buildProcessTitle(
            true,
            $isMaintenanceWorker,
            $workerId,
            $port,
            $instanceName,
            $orchestratorLaunchId
        )
    );
}

ErrorBootstrap::init($processTag, [
    'worker_id' => $workerId,
    'port' => $port,
    'instance' => $instanceName,
    'process_name' => $processName,
    'is_maintenance' => $isMaintenanceWorker,
    'ssl' => true,
]);

// ========== 进程日志文件（持久化，跨重启保留） ==========
// Worker 自身负责将错误和关键日志写入 var/process/{processName}.log
// 确保即使 Windows 隐藏窗口或 Linux 重定向丢失，日志也不会丢
$processLogFile = '';
if ($processName) {
    $processLogFile = \Weline\Server\Service\WlsLogService::prepareProcessLogFile($processName, $instanceName, $processTag);
    // 将 PHP error_log() 重定向到进程日志文件（追加模式）
}

// 预先读取 env.php 中的 deploy 配置（备用方案，用于在 App::init() 之前检测 DEV 模式）
$envConfig = null;
$envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
if (\is_file($envFile)) {
    $envConfig = @include $envFile;
}
$envConfig = \is_array($envConfig) ? $envConfig : [];
$sharedStateRuntime = \Weline\Server\Service\SharedStateRuntimeOptions::fromCliArgs($argv, $instanceName, $envConfig);

// 初始化 WLS Worker 全局状态
\Weline\Server\Service\WlsWorkerGlobals::setArgv($argv);
\Weline\Server\Service\WlsWorkerGlobals::resetStd();
$envOverrides = $sharedStateRuntime->toEnvOverrides();
$envConfig = \array_replace_recursive($envConfig, $envOverrides);
\Weline\Framework\App\Env::getInstance()->applyRuntimeConfig($envOverrides);
$sessionRuntime = $sharedStateRuntime->getSession();
$memoryRuntime = $sharedStateRuntime->getMemory();
$envLoopDriver = (string) (($envConfig['wls']['loop']['driver'] ?? 'auto'));
$wlsLoopDriver = $wlsLoopDriver !== '' ? $wlsLoopDriver : $envLoopDriver;
$wlsLoopDriver = \Weline\Server\EventLoop\EventLoopFactory::normalizeDriver($wlsLoopDriver);
$wlsEnv = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
$wlsSslConfig = \is_array($wlsEnv['ssl'] ?? null) ? $wlsEnv['ssl'] : [];
$sslHandshakeMaxAdvancePerLoop = \max(1, (int)($wlsSslConfig['handshake_max_advance_per_loop'] ?? 16));
$sslHandshakeQueueHighWatermark = \max(
    $sslHandshakeMaxAdvancePerLoop,
    (int)($wlsSslConfig['handshake_queue_high_watermark'] ?? 512)
);
$sslIdleSelectTimeoutUsec = \max(1000, \min(
    100000,
    (int)($wlsSslConfig['idle_select_timeout_usec'] ?? 5000)
));

WlsLogger::getInstance()
    ->setStdoutEnabled(\Weline\Server\Log\LogConfig::isStdoutEnabled($isFrontend, \Weline\Server\Log\LogConfig::isDevMode()))
    ->setProcessTag($processTag);

$workerStartupTraceFileEnabled = (bool)($wlsEnv['debug']['worker_startup_trace'] ?? false)
    || \in_array(\strtolower(\trim((string)(\getenv('WLS_WORKER_STARTUP_TRACE') ?: ''))), ['1', 'true', 'yes', 'on'], true);
$ipcClient = $ipcClient ?? null;
$wlsStartupTraceLastStage = 'logger_bootstrap';
$wlsWorkerGracefulExitReason = '';
$wlsStartupTraceStartedAt = \microtime(true);
$wlsStartupTraceLastAt = $wlsStartupTraceStartedAt;
$wlsStartupTrace = static function (string $stage, array $context = []) use (&$wlsStartupTraceLastAt, &$wlsStartupTraceLastStage, $wlsStartupTraceStartedAt, $workerId, $port, $instanceName, $isMaintenanceWorker, $workerStartupTraceFileEnabled): void {
    $now = \microtime(true);
    $wlsStartupTraceLastStage = $stage;
    $context['delta_ms'] = (int)\round(($now - $wlsStartupTraceLastAt) * 1000);
    $context['total_ms'] = (int)\round(($now - $wlsStartupTraceStartedAt) * 1000);
    $context['memory_mb'] = \round(\memory_get_usage(true) / 1048576, 2);
    $wlsStartupTraceLastAt = $now;
    if ($workerStartupTraceFileEnabled) {
        $traceRow = [
            'ts' => \date('c'),
            'pid' => \getmypid(),
            'instance' => $instanceName,
            'role' => $isMaintenanceWorker ? 'maintenance' : 'worker',
            'worker_id' => $workerId,
            'port' => $port,
            'stage' => $stage,
            'data' => $context,
        ];
        @\file_put_contents(
            BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'wls-worker-startup-trace.log',
            (\json_encode($traceRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . PHP_EOL,
            FILE_APPEND
        );
    }
    WlsLogger::info_('[StartupTrace] ' . $stage . ' ' . (\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'));
};
$wlsWorkerExitTrace = static function (string $event, string $reason = '', array $context = []) use (&$ipcClient, &$wlsStartupTraceLastStage, $workerId, $port, $instanceName, $isMaintenanceWorker, $controlPort, $orchestratorLaunchId): void {
    $context = \array_merge([
        'pid' => \getmypid(),
        'instance' => $instanceName,
        'role' => $isMaintenanceWorker ? 'maintenance' : 'worker',
        'worker_id' => $workerId,
        'port' => $port,
        'control_port' => $controlPort,
        'launch_id' => $orchestratorLaunchId,
        'last_startup_stage' => $wlsStartupTraceLastStage,
        'ipc_connected' => $ipcClient !== null && $ipcClient->isConnected(),
        'memory_mb' => \round(\memory_get_usage(true) / 1048576, 2),
    ], $context);
    WlsLogger::error_('[WorkerExitTrace] ' . $event . ' reason=' . $reason . ' ' . (\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'));
    WlsLogger::flush_(true);
};
$wlsStartupTrace('logger_ready');

$ipcClient = $ipcClient ?? null;
$ipcSelfTag = $ipcSelfTag ?? null;
$ipcDraining = $ipcDraining ?? false;
$ipcReceivedShutdown = $ipcReceivedShutdown ?? false;
$drainStartTime = $drainStartTime ?? 0;
$shouldExit = $shouldExit ?? false;
$maxDrainTime = 10;
$maintenanceDrainState = new \Weline\Server\Service\Runtime\WorkerMaintenanceDrainState($isMaintenanceWorker);
$waitingForAck = $waitingForAck ?? false;
$readySentTime = $readySentTime ?? 0.0;
$ackRetryCount = $ackRetryCount ?? 0;
$maxAckRetries = $maxAckRetries ?? 0;
$ackTimeout = $ackTimeout ?? 10.0;
$exitBecauseMasterMissingAtStartup = $exitBecauseMasterMissingAtStartup ?? false;
$orphanGuard = $orphanGuard ?? new \Weline\Server\IPC\ChildControl\MasterOrphanGuard();
$maxMemoryBytes = $maxMemoryBytes ?? wlsMemoryLimitToBytes($wlsMemoryLimit);
if ($maxMemoryBytes <= 0) {
    $maxMemoryBytes = 256 * 1024 * 1024;
}
$memoryCheckInterval = $memoryCheckInterval ?? 5;
$lastMemoryCheck = $lastMemoryCheck ?? \time();
$memoryWarningThreshold = $memoryWarningThreshold ?? 0.80;
$memoryDrainThreshold = $memoryDrainThreshold ?? 0.88;
$maxRequestHeaderBytes = $maxRequestHeaderBytes ?? 65536;
$maxRequestBodyBytes = $maxRequestBodyBytes ?? (16 * 1024 * 1024);
$maxBufferedRequestBytes = $maxBufferedRequestBytes ?? ($maxRequestHeaderBytes + $maxRequestBodyBytes);
$ipcRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
$earlyIpcHandler = null;
$kernel = null;

if ($controlPort > 0 || $supervisorEnabled) {
    $wlsStartupTrace('ipc_register_begin', ['control_port' => $controlPort]);
    $ipcSelfTag = ($isMaintenanceWorker ? 'Maintenance' : 'Worker') . "#{$workerId}";
    $identity = new \Weline\Server\IPC\ChildControl\ChildProcessIdentity(
        $ipcRole,
        \getmypid(),
        $port,
        $workerId,
        $orchestratorEpoch,
        $orchestratorLaunchId
    );
    $earlyIpcHandler = new \Weline\Server\IPC\ChildControl\Handler\DelegatingControlHandler();
    $kernel = new \Weline\Server\IPC\ChildControl\SubprocessControlKernel(
        $identity,
        $earlyIpcHandler,
        $ipcSelfTag,
        (\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE),
        $instanceName
    );
    $ipcClient = $kernel->getClient();
    if ($kernel->connectAndRegister($controlPort, false)) {
        $ipcClient = $kernel->getClient();
        $wlsStartupTrace('ipc_registered_deferred_ready', ['control_port' => $controlPort]);
        WlsLogger::info_('[IPC] Registered with Master before SSL worker bootstrap; READY deferred until socket/runtime is ready');
    } else {
        $wlsStartupTrace('ipc_register_failed', ['control_port' => $controlPort]);
        WlsLogger::warning_("[IPC] Early register failed (control port: {$controlPort}); will retry after SSL worker bootstrap");
    }
}

// Origin Token 回源校验配置（可选安全增强）
$originToken = '';
$originTokenValidationEnabled = false;
$originTokenHeader = 'X-Weline-Origin-Token';
$originTokenAllowLocal = true;
if ($envConfig !== []) {
    $originToken = (string)($wlsEnv['origin_token'] ?? '');
    $originValidationConfig = $wlsEnv['origin_token_validation'] ?? [];
    if (\is_array($originValidationConfig)) {
        $originTokenValidationEnabled = (bool)($originValidationConfig['enabled'] ?? false);
        $originTokenHeader = (string)($originValidationConfig['header'] ?? $originTokenHeader);
        $originTokenAllowLocal = (bool)($originValidationConfig['allow_local'] ?? true);
    }
}
$mainLoopUnblockedLogEvery = \Weline\Server\Service\MainLoopUnblockedLogConfig::resolve($wlsEnv, ['worker', 'worker_ssl']);
$mainLoopUnblockedLogIntervalSec = \Weline\Server\Service\MainLoopUnblockedLogConfig::resolveInterval($wlsEnv, ['worker', 'worker_ssl']);
$lastMainLoopUnblockedLogAt = 0.0;
$hotPathLogsEnabled = (bool)($wlsEnv['debug']['hot_path_logs'] ?? false)
    || \Weline\Server\Log\LogConfig::isVerboseWlsLog();

/**
 * 从 ssl_certificate_map.json 加载 SNI 证书映射。
 * 在 Worker 启动时调用一次，并在收到 ssl_cert_reload IPC 命令时再次调用以热更新。
 *
 * @return array<string, array{local_cert: string, local_pk: string}>
 */
function _loadSniCertsFromMap(): array
{
    $mapFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'ssl_certificate_map.json';
    if (!\is_file($mapFile)) {
        return [];
    }
    try {
        \clearstatcache(true, $mapFile);
        $raw = (string)@\file_get_contents($mapFile);
        $map = \json_decode($raw, true);
        if (!\is_array($map)) {
            return [];
        }
        $certs = [];
        $policies = [];
        foreach ($map as $domain => $pair) {
            $certPath = (string)($pair['cert'] ?? '');
            $keyPath = (string)($pair['key'] ?? '');
            if ($domain !== '' && $certPath !== '' && $keyPath !== '') {
                wlsEnsureRuntimeFileReadable($certPath, 0644);
                wlsEnsureRuntimeFileReadable($keyPath, 0600);
            }
            if ($domain !== '' && $certPath !== '' && $keyPath !== '' && \is_file($certPath) && \is_file($keyPath)
                && \is_readable($certPath) && \is_readable($keyPath)) {
                $certs[(string)$domain] = [
                    'local_cert' => $certPath,
                    'local_pk' => $keyPath,
                ];
            }
            if ($domain !== '') {
                $policies[(string)$domain] = [
                    'force_https' => (int) ($pair['force_https'] ?? 1),
                    'force_root_to_www' => (int) ($pair['force_root_to_www'] ?? 0),
                ];
            }
        }
        \Weline\Server\Service\WlsWorkerGlobals::setDomainPolicies($policies);
        return $certs;
    } catch (\Throwable) {
        return [];
    }
}

/**
 * 获取指定域名的重定向策略。
 *
 * @return array{force_https: int, force_root_to_www: int}
 */
function _getDomainPolicy(string $domain): array
{
    return \Weline\Server\Service\WlsWorkerGlobals::getDomainPolicy($domain);
}

/**
 * 从 TLS ClientHello 数据中解析 SNI（Server Name Indication）域名。
 * 用于 defer-ssl 模式：PHP 的 SNI_server_certs 在 stream_socket_enable_crypto 时不生效，
 * 需手动解析 ClientHello 并在握手前设置对应域名的证书。
 *
 * @param string $data peek 到的原始 TCP 数据（至少需要 43+ 字节）
 * @return string|null 解析到的 SNI 主机名，失败返回 null
 */
function _parseSniHostFromClientHello(string $data): ?string
{
    // 与 Dispatcher\SniParser 对齐，避免两套解析不一致导致 SNI 取不到、选错默认证书进而触发 unrecognized_name
    $sni = \Weline\Server\Dispatcher\SniParser::extractSNI($data);
    if ($sni === null || $sni === '') {
        return null;
    }

    return $sni;
}

/**
 * defer-ssl：在不从内核移除字节的前提下偷看 TCP 首包（ClientHello）。
 * Windows 上部分 PHP 版本对 accept 后的 stream 仅 stream_socket_recvfrom 不可靠，故增加 socket_import_stream+MSG_PEEK 兜底。
 */
function wlsSslPeekTcpPrefixNoConsume($conn): string
{
    if (!\is_resource($conn)) {
        return '';
    }
    @\stream_set_blocking($conn, false);
    $peeked = @\stream_socket_recvfrom($conn, 65536, \STREAM_PEEK);
    if (\is_string($peeked) && $peeked !== '') {
        return $peeked;
    }
    if (!\function_exists('socket_import_stream')) {
        return '';
    }
    $sock = @\socket_import_stream($conn);
    if ($sock === false) {
        return '';
    }
    if (\function_exists('socket_set_nonblock')) {
        @\socket_set_nonblock($sock);
    }
    $buf = '';
    $flags = \defined('MSG_PEEK') ? \MSG_PEEK : 2;
    $n = @\socket_recv($sock, $buf, 65536, $flags);
    if ($n !== false && $n > 0 && \is_string($buf)) {
        return $buf;
    }

    return '';
}

/**
 * 将当前 SNI 映射写回 defer-ssl 选项与监听 socket 的 ssl 上下文。
 * 仅替换 $sniServerCerts 数组不会更新已拷贝到 $deferSslOptions 的映射，会导致 IPC/磁盘更新后握手仍用旧 SNI。
 */
function wlsSslApplySniOptionsToContexts(
    ?array &$deferSslOptions,
    $listenSocket,
    array $sniServerCerts,
    string $sslCert,
    string $sslKey,
    int $cryptoMethod
): void {
    if ($deferSslOptions !== null) {
        $deferSslOptions['SNI_enabled'] = !empty($sniServerCerts);
        $deferSslOptions['SNI_server_certs'] = $sniServerCerts;
        $deferSslOptions['local_cert'] = $sslCert;
        $deferSslOptions['local_pk'] = $sslKey;
        $deferSslOptions['crypto_method'] = $cryptoMethod;
    }
    if ($listenSocket && \is_resource($listenSocket)) {
        @\stream_context_set_option($listenSocket, 'ssl', 'SNI_enabled', !empty($sniServerCerts));
        @\stream_context_set_option($listenSocket, 'ssl', 'SNI_server_certs', $sniServerCerts);
        @\stream_context_set_option($listenSocket, 'ssl', 'local_cert', $sslCert);
        @\stream_context_set_option($listenSocket, 'ssl', 'local_pk', $sslKey);
    }
}

/**
 * 非空 SNI 映射时 OpenSSL 要求 ClientHello 的 SNI 必须在映射中有精确键或通配模式，否则返回 unrecognized_name。
 *
 * 兜底：扫描 app/etc/ssl/ 下所有证书目录、解析 CN/SAN，把所有可握手的主机名（含托管本地通配模式）补进映射；
 * 另外把当前 Worker 的监听主机也补进去。
 * 这样即便 ssl_certificate_map.json（基于 DB）暂未同步，磁盘上有的证书都能直接握手。
 */
function wlsSslMergeDefaultListenerHostnamesIntoSniMap(
    array &$sniServerCerts,
    string $sslCert,
    string $sslKey,
    string $defaultHost
): void {
    $add = static function (string $h, string $cert, string $key) use (&$sniServerCerts): void {
        $h = \strtolower(\trim($h));
        if ($h === '' || \filter_var($h, FILTER_VALIDATE_IP)) {
            return;
        }
        if (isset($sniServerCerts[$h])) {
            return;
        }
        if ($cert === '' || $key === '' || !\is_file($cert) || !\is_file($key)) {
            return;
        }
        $sniServerCerts[$h] = ['local_cert' => $cert, 'local_pk' => $key];
    };
    $extractHostnames = static function (string $certFile): array {
        $names = [];
        $pem = (string) @\file_get_contents($certFile);
        if ($pem === '' || !\preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $m)) {
            return $names;
        }
        $res = @\openssl_x509_read($m[0]);
        if ($res === false) {
            return $names;
        }
        $parsed = @\openssl_x509_parse($res, false);
        if (!\is_array($parsed)) {
            return $names;
        }
        $cn = $parsed['subject']['CN'] ?? '';
        if (\is_string($cn) && $cn !== '') {
            $names[] = \strtolower($cn);
        }
        $sanRaw = $parsed['extensions']['subjectAltName'] ?? '';
        if (\is_string($sanRaw) && $sanRaw !== '') {
            foreach (\preg_split('/,\s*/', $sanRaw) as $seg) {
                $seg = \trim($seg);
                if (\str_starts_with($seg, 'DNS:')) {
                    $names[] = \strtolower(\substr($seg, 4));
                }
            }
        }
        return \array_values(\array_unique($names));
    };

    $defaultHost = \strtolower(\trim($defaultHost));
    $instanceHost = $defaultHost;

    if ($sslCert !== '' && $sslKey !== '' && \is_file($sslCert) && \is_file($sslKey)) {
        if ($defaultHost !== '') {
            $add($defaultHost, $sslCert, $sslKey);
        }
        foreach ($extractHostnames($sslCert) as $name) {
            $add($name, $sslCert, $sslKey);
        }
    }

    $sslDir = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR;
    if (\is_dir($sslDir)) {
        $entries = @\scandir($sslDir) ?: [];
        foreach ($entries as $segment) {
            if ($segment === '.' || $segment === '..') {
                continue;
            }
            $dir = $sslDir . $segment . DIRECTORY_SEPARATOR;
            if (!\is_dir($dir)) {
                continue;
            }
            $certFile = $dir . 'fullchain.pem';
            $keyFile = $dir . 'privkey.pem';
            if (!\is_file($certFile) || !\is_file($keyFile)) {
                continue;
            }
            $logical = \Weline\Server\Service\SslCertificateService::logicalDomainFromStorageSegment($segment);
            $add($logical, $certFile, $keyFile);
            foreach ($extractHostnames($certFile) as $name) {
                $add($name, $certFile, $keyFile);
                if (\str_starts_with($name, '*.') && $instanceHost !== '') {
                    $root = \substr($name, 2);
                    if ($root !== '' && \str_ends_with($instanceHost, '.' . $root)) {
                        $add($instanceHost, $certFile, $keyFile);
                    }
                }
            }
        }
    }
}

// 域名策略缓存（force_https / force_root_to_www），由 _loadSniCertsFromMap() 填充
$_domainPolicies = [];

// 读取 SNI 证书映射（var/server/ssl_certificate_map.json）
// 使用 _loadSniCertsFromMap() 函数加载，后续收到 ssl_cert_reload IPC 命令时可热更新
$wlsStartupTrace('sni_map_load_begin');
$sniServerCerts = _loadSniCertsFromMap();
wlsSslMergeDefaultListenerHostnamesIntoSniMap($sniServerCerts, $sslCert, $sslKey, $listenerHost);
$wlsStartupTrace('sni_map_loaded', ['sni_count' => \count($sniServerCerts)]);
WlsLogger::info_('[SSL] 初始化 SNI 映射，共 ' . \count($sniServerCerts) . ' 项：' . \implode(', ', \array_keys($sniServerCerts)));

// ========== 日志系统：直接使用 WlsLogger ==========
// 检测模式（只检测一次）
$isDev = false;
if (\defined('DEV') && DEV) {
    $isDev = true;
} elseif ($envConfig !== null
    && (($envConfig['system']['deploy'] ?? $envConfig['deploy'] ?? '') === 'dev')
) {
    $isDev = true;
}

// 前台模式：启用控制台输出
if ($isFrontend) {
    WlsLogger::getInstance()
        ->setStdoutEnabled(\Weline\Server\Log\LogConfig::isStdoutEnabled($isFrontend, $isDev))
        ->setProcessTag($processTag);
}
// ========== 日志系统结束 ==========

// 注册 PID 到 Processer（启用快速 PID 查找）
if ($processName) {
    $managedProcessIdentity = '--name=' . $processName;
    if ($orchestratorLaunchId !== '') {
        $managedProcessIdentity .= ' --launch-id=' . $orchestratorLaunchId;
    }
    if ($orchestratorEpoch > 0) {
        $managedProcessIdentity .= ' --epoch=' . $orchestratorEpoch;
    }
    \Weline\Framework\System\Process\Processer::setPid($managedProcessIdentity, \getmypid());
    // 注册监听端口（启用快速端口→PID 查找）
    if ($port > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts($managedProcessIdentity, [$port]);
    }
}

// 路由提示只属于 Dispatcher 透传数据面；Direct 不需要逐响应改写。
\Weline\Server\Service\RouteHintService::init($port, $wlsRuntimeTopology === 'dispatcher', 3600);

// 初始化框架运行时
$runtime = null;
$runtimeError = null;
$fpcFastPath = null;

try {
    WlsLogger::info_("Worker 启动，监听 ssl://{$host}:{$port}");
    $wlsStartupTrace('runtime_bootstrap_begin');
    $runtime = new \Weline\Framework\Runtime\WlsRuntime();
    $runtime->bootstrap();
    $wlsStartupTrace('fpc_coordinator_preload_begin');
    $fpcFastPath = new \Weline\Server\Service\WorkerFullPageCacheFastPath(
        \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Router\FullPageCacheCoordinator::class
        ),
        $runtime,
    );
    $wlsStartupTrace('fpc_coordinator_preloaded');
    $wlsStartupTrace('runtime_bootstrap_done');
    WlsLogger::info_("框架运行时初始化成功");

    // 共享服务检查延迟到后台进行，不阻塞 IPC 连接
    // IPC 连接应该尽快建立，让 Master 能立即感知到 Worker
    // SharedState 的 session/memory 信息在首次请求时通过 ConnectionPool 自动获取
    // 不再在这里同步等待 SharedStateServiceManager::ensureRuntime()

    // Use only the Master/runtime-provided shared service addresses.
    $sessionHost = (string) ($sessionRuntime['host'] ?? '127.0.0.1');
    if ($sessionHost === '') {
        $sessionHost = '127.0.0.1';
    }
    $sessionPort = (int) ($sessionRuntime['port'] ?? 0);
    if ($sessionPort <= 0) {
        $sessionPort = 19970 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
    }
    $sessionTokenFileName = \trim((string) ($sessionRuntime['token_file_name'] ?? 'session_server.token'));
    if ($sessionTokenFileName === '') {
        $sessionTokenFileName = 'session_server.token';
    }
    $memoryHost = (string) ($memoryRuntime['host'] ?? '127.0.0.1');
    if ($memoryHost === '') {
        $memoryHost = '127.0.0.1';
    }
    $memoryPort = (int) ($memoryRuntime['port'] ?? 0);
    if ($memoryPort <= 0) {
        $memoryPort = 19971 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
    }
    $memoryTokenFileName = \trim((string) ($memoryRuntime['token_file_name'] ?? 'memory_server.token'));
    if ($memoryTokenFileName === '') {
        $memoryTokenFileName = 'memory_server.token';
    }

    // 注意：启动阶段不再进行服务发现或连接尝试，所有服务并发启动
    // Worker 将在主循环的 Fiber 阶段异步建立连接池
    WlsLogger::info_("[Session] Preconfigured session service address {$sessionHost}:{$sessionPort} (connection will start asynchronously in the main loop)");
    WlsLogger::info_("[Memory] Preconfigured memory service address {$memoryHost}:{$memoryPort} (connection will start asynchronously in the main loop)");
} catch (\Throwable $e) {
    $runtimeError = $e->getMessage();
    WlsLogger::error_("框架运行时初始化失败: " . $e->getMessage());
    if (\function_exists('w_log_error')) {
        w_log_error('[WLS Worker SSL] Bootstrap error: ' . $e->getMessage());
    }
}

// Bootstrap 失败时仍补齐地址，避免后续代码访问未定义变量（维护 Worker 不做 Session/Memory 预检）
if (!isset($sessionHost, $sessionPort, $memoryHost, $memoryPort)) {
    $sessionHost = (string) ($sessionRuntime['host'] ?? '127.0.0.1');
    $sessionPort = (int) ($sessionRuntime['port'] ?? 0);
    if ($sessionPort <= 0) {
        $sessionPort = 19970 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
    }
    $memoryHost = (string) ($memoryRuntime['host'] ?? '127.0.0.1');
    $memoryPort = (int) ($memoryRuntime['port'] ?? 0);
    if ($memoryPort <= 0) {
        $memoryPort = 19971 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
    }
}

// ========== Fiber 调度器初始化（确保 SSE/长任务不阻塞主循环） ==========
$fiberScheduler = new \Weline\Server\Scheduler\FiberScheduler();
$eventLoopMeta = \Weline\Server\EventLoop\EventLoopFactory::create($wlsLoopDriver);
$eventLoop = $eventLoopMeta['loop'];
$coroutineRuntime = new \Weline\Server\Runtime\CoroutineRuntime($eventLoop, $fiberScheduler);
$asyncBizAdapters = new \Weline\Server\Runtime\Async\AsyncBizAdapters();
\Weline\Server\Observer\SchedulerWaitObserver::setScheduler($fiberScheduler);
\Weline\Framework\Runtime\SchedulerSystem::enableScheduler();
$longLivedProtocolResolver = new \Weline\Server\Service\Protocol\LongLived\ProtocolResolver();
$activeFibers = [];
$fiberTickBudgetMs = (float)(\Weline\Framework\App\Env::get('wls.worker.fiber_tick_budget_ms', 8) ?: 8);
\Weline\Framework\Runtime\WlsConcurrency::setOtherSuspendedFiberCountProvider(
    static function () use (&$activeFibers): int {
        return \count($activeFibers);
    }
);
// Fiber 池与长连接治理（与 worker.php 对齐，供 Master IPC 与 Dispatcher 饱和策略使用）
$fiberIdleTtlSec = 0;
$fiberMaxActive = 0;
$fiberReleaseIdleRequested = false;
$lastFiberIdleCheck = \time();
$longLivedConnections = [];
$longLivedMaxActive = 0;
$longLivedSaturationReported = false;
$longLivedSaturationCleared = false;
$lastLongLivedSaturationReport = 0;
$longLivedSaturationInterval = 10;
WlsLogger::info_("Fiber 调度器已初始化");
WlsLogger::info_(
    "EventLoop 已初始化 requested={$eventLoopMeta['requested']} resolved={$eventLoopMeta['resolved']} backend={$coroutineRuntime->getLoopBackend()}"
);
$wlsStartupTrace('event_loop_ready', ['backend' => $coroutineRuntime->getLoopBackend()]);

$deferredWorkerBootstrapWarmupStarted = $deferredWorkerBootstrapWarmupStarted ?? false;

$configuredLongLivedMaxActive = (int)($wlsInstance['fiber']['long_lived_max_active'] ?? $wls['fiber']['long_lived_max_active'] ?? 4);
if ($configuredLongLivedMaxActive >= 0) {
    $longLivedMaxActive = $configuredLongLivedMaxActive;
}

// ========== WLS 内存缓存配置（智能模式） ==========
// 读取 env 配置中的 WLS 缓存配置
$wlsCacheConfig = [];
if ($envConfig !== null && isset(($envConfig['wls'] ?? [])['cache'])) {
    $wlsCacheConfig = $envConfig['wls']['cache'];
}

/**
 * 检查 shell_exec 函数是否可用
 * @return bool
 */
function isShellExecAvailable(): bool
{
    static $available = null;
    if ($available === null) {
        $available = \function_exists('shell_exec') 
            && !\in_array('shell_exec', \array_map('trim', \explode(',', \ini_get('disable_functions') ?: '')), true);
    }
    return $available;
}

/**
 * 获取系统可用内存（字节）
 * @return int 可用内存字节数，获取失败返回 0
 */
function getSystemFreeMemory(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        return 0;
        if (isShellExecAvailable()) {
            $output = '';
            if ($output && \preg_match('/FreePhysicalMemory=(\d+)/', $output, $matches)) {
                return (int)$matches[1] * 1024; // KB 转 bytes
            }
        }
        // 回退：返回 0（使用默认值）
        return 0;
    } else {
        // Linux/Mac: 读取 /proc/meminfo 或使用 free 命令
        if (\is_readable('/proc/meminfo')) {
            $meminfo = @\file_get_contents('/proc/meminfo');
            if ($meminfo && \preg_match('/MemAvailable:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                return (int)$matches[1] * 1024; // KB 转 bytes
            }
            // 回退：MemFree + Cached + Buffers
            if ($meminfo) {
                $free = 0;
                if (\preg_match('/MemFree:\s*(\d+)\s*kB/i', $meminfo, $m)) {
                    $free += (int)$m[1];
                }
                if (\preg_match('/Cached:\s*(\d+)\s*kB/i', $meminfo, $m)) {
                    $free += (int)$m[1];
                }
                if (\preg_match('/Buffers:\s*(\d+)\s*kB/i', $meminfo, $m)) {
                    $free += (int)$m[1];
                }
                if ($free > 0) {
                    return $free * 1024;
                }
            }
        }
        // Mac: vm_stat 仅 "Pages free" 偏小，需加上可回收的 inactive/speculative（与 Linux MemAvailable 语义一致）
        // 注意：macOS 可能输出千位逗号（如 "1,234,567"），需去掉逗号再转 int，否则会误判为内存严重不足
        if (isShellExecAvailable()) {
            $output = @\shell_exec('vm_stat 2>/dev/null');
            if ($output) {
                $pageSize = 4096; // macOS 页面大小（Intel/Apple Silicon 均为 4KB）
                $parse = static function (string $text, string $key): int {
                    if (!\preg_match('/' . \preg_quote($key, '/') . ':\s*([\d,\.]+)/', $text, $m)) {
                        return 0;
                    }
                    return (int)\str_replace([',', '.'], '', $m[1]);
                };
                $free = $parse($output, 'Pages free');
                $inactive = $parse($output, 'Pages inactive');
                $speculative = $parse($output, 'Pages speculative');
                $availablePages = $free + $inactive + $speculative;
                if ($availablePages > 0) {
                    return $availablePages * $pageSize;
                }
            }
        }
    }
    return 0;
}

/**
 * 获取系统总内存（字节）
 * @return int 总内存字节数
 */
function getSystemTotalMemory(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        return 4 * 1024 * 1024 * 1024;
        if (isShellExecAvailable()) {
            $output = '';
            if ($output && \preg_match('/TotalPhysicalMemory=(\d+)/', $output, $matches)) {
                return (int)$matches[1];
            }
        }
        // 回退：返回默认值
        return 4 * 1024 * 1024 * 1024; // 4GB
    } else {
        if (\is_readable('/proc/meminfo')) {
            $meminfo = @\file_get_contents('/proc/meminfo');
            if ($meminfo && \preg_match('/MemTotal:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                return (int)$matches[1] * 1024;
            }
        }
        // Mac（如果 shell_exec 可用）
        if (isShellExecAvailable()) {
            $output = @\shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if ($output) {
                return (int)\trim($output);
            }
        }
    }
    // 默认返回 4GB
    return 4 * 1024 * 1024 * 1024;
}

/**
 * 智能计算缓存大小
 * @param string $configValue 配置值：'auto'、'50M'、'100MB'、数字（字节）
 * @param int $defaultPercent 默认百分比（相对于系统内存）
 * @param int $defaultMin 默认最小值（字节）
 * @param int $defaultMax 默认最大值（字节）
 * @return int 缓存大小（字节）
 */
function calculateCacheSize(string|int $configValue, int $defaultPercent, int $defaultMin, int $defaultMax): int
{
    // 数字直接返回
    if (\is_int($configValue)) {
        return $configValue;
    }
    
    $configValue = \strtolower(\trim($configValue));
    
    // 'auto' 或空：智能计算
    if ($configValue === 'auto' || $configValue === '') {
        $totalMem = getSystemTotalMemory();
        $calculated = (int)($totalMem * $defaultPercent / 100);
        return \max($defaultMin, \min($defaultMax, $calculated));
    }
    
    // 解析带单位的值：50M, 100MB, 1G, 1GB
    if (\preg_match('/^(\d+(?:\.\d+)?)\s*(k|kb|m|mb|g|gb)?$/i', $configValue, $matches)) {
        $value = (float)$matches[1];
        $unit = \strtolower($matches[2] ?? '');
        
        return match($unit) {
            'k', 'kb' => (int)($value * 1024),
            'm', 'mb' => (int)($value * 1024 * 1024),
            'g', 'gb' => (int)($value * 1024 * 1024 * 1024),
            default => (int)$value,
        };
    }
    
    // 解析失败，返回默认最小值
    return $defaultMin;
}

// 计算静态文件缓存大小
// 默认：系统内存的 2%，最小 32MB，最大 256MB
$staticFileCacheMaxTotalConfig = $wlsCacheConfig['static_file_max_total'] ?? 'auto';
$WLS_STATIC_CACHE_MAX_TOTAL = calculateCacheSize($staticFileCacheMaxTotalConfig, 2, 32 * 1024 * 1024, 256 * 1024 * 1024);

// 单文件最大缓存大小（H13: 提高默认值到 2MB，支持大型 JS 库如 CKEditor）
$staticFileCacheMaxSizeConfig = $wlsCacheConfig['static_file_max_size'] ?? '2M';
$WLS_STATIC_CACHE_MAX_SIZE = calculateCacheSize($staticFileCacheMaxSizeConfig, 0, 512 * 1024, 10 * 1024 * 1024);

// 缓存淘汰临界值：剩余多少字节时开始淘汰
$WLS_CACHE_EVICTION_THRESHOLD = (int)($wlsCacheConfig['eviction_threshold'] ?? 5 * 1024 * 1024); // 默认 5MB

// 检查启动时内存是否足够
$freeMemory = getSystemFreeMemory();
$requiredMemory = $WLS_STATIC_CACHE_MAX_TOTAL + 50 * 1024 * 1024; // 缓存 + 50MB 预留

if ($freeMemory > 0 && $freeMemory < $requiredMemory) {
    $freeMB = \round($freeMemory / 1024 / 1024, 1);
    $requiredMB = \round($requiredMemory / 1024 / 1024, 1);
    $cacheMB = \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1);
    
    WlsLogger::warning_("内存不足警告：系统可用内存 {$freeMB}MB，WLS 需要 {$requiredMB}MB（缓存 {$cacheMB}MB + 50MB 预留）");
    
    // 如果严重不足（低于需求的 50%），报错退出
    if ($freeMemory < $requiredMemory * 0.5) {
        WlsLogger::error_("内存严重不足，无法启动。请增加系统内存或减少 env.php 中的 wls.cache.static_file_max_total 配置");
        exit(1);
    }
    
    // 自动缩减缓存大小
    $newCacheSize = (int)($freeMemory * 0.6); // 使用 60% 的可用内存
    $newCacheMB = \round($newCacheSize / 1024 / 1024, 1);
    WlsLogger::warning_("自动缩减静态文件缓存至 {$newCacheMB}MB");
    $WLS_STATIC_CACHE_MAX_TOTAL = $newCacheSize;
}

WlsLogger::info_("内存缓存配置：静态文件缓存上限 " . \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1) . "MB，单文件上限 " . \round($WLS_STATIC_CACHE_MAX_SIZE / 1024, 1) . "KB，淘汰阈值 " . \round($WLS_CACHE_EVICTION_THRESHOLD / 1024 / 1024, 1) . "MB");
// ========== 内存缓存配置结束 ==========

$wlsStartupTrace('cache_config_ready', [
    'static_cache_mb' => \round($WLS_STATIC_CACHE_MAX_TOTAL / 1048576, 1),
]);

$WLS_UOPZ_EXIT_GUARD = false;
if (\extension_loaded('uopz') && \function_exists('uopz_allow_exit')) {
    try {
        \uopz_allow_exit(false);
        $WLS_UOPZ_EXIT_GUARD = true;
        WlsLogger::info_('uopz 已启用：裸 exit()/die() 不结束 SSL Worker（请使用 System::exit）');
    } catch (\Throwable) {
    }
}

// Keep a final worker-side trace for fatal and non-graceful exits.
\register_shutdown_function(function() use (&$wlsWorkerGracefulExitReason, $wlsWorkerExitTrace) {
    $error = \error_get_last();
    $fatalErrorTypes = [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_RECOVERABLE_ERROR, \E_USER_ERROR];
    
    if ($error !== null && \in_array($error['type'], $fatalErrorTypes, true)) {
        $wlsWorkerExitTrace('fatal_shutdown', 'fatal_error', [
            'last_error' => [
                'type' => (int)($error['type'] ?? 0),
                'message' => (string)($error['message'] ?? ''),
                'file' => (string)($error['file'] ?? ''),
                'line' => (int)($error['line'] ?? 0),
            ],
        ]);
        return;
    }

    if ($wlsWorkerGracefulExitReason !== '') {
        return;
    }
    
    // 无致命错误但进程即将退出：多为业务代码 die()/exit() 或信号终止
    \Weline\Server\Service\AttackLogService::flushForShutdown();
    $wlsWorkerExitTrace('shutdown_without_graceful_reason', 'process_shutdown_without_worker_reason');
});

// Native WLS HTTPS is a development/runtime convenience path; keep the
// handshake surface modern and small instead of negotiating legacy protocols.
// Production can pin the server protocol list with wls.ssl.protocols, for
// example ['tls1.2'], when a TLS implementation path proves unstable.
$cryptoMethod = 0;
$wlsConfiguredSslProtocols = \array_key_exists('protocols', $wlsSslConfig)
    ? $wlsSslConfig['protocols']
    : (\array_key_exists('server_protocols', $wlsSslConfig)
        ? $wlsSslConfig['server_protocols']
        : ['tls1.2', 'tls1.3']);
if (\is_string($wlsConfiguredSslProtocols)) {
    $wlsConfiguredSslProtocols = \preg_split('/[\s,|]+/', $wlsConfiguredSslProtocols, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
if (!\is_array($wlsConfiguredSslProtocols) || $wlsConfiguredSslProtocols === []) {
    WlsLogger::error_('wls.ssl.protocols 必须是非空列表，且只允许 tls1.2/tls1.3');
    exit(1);
}
foreach ($wlsConfiguredSslProtocols as $wlsConfiguredSslProtocol) {
    if (!\is_string($wlsConfiguredSslProtocol)) {
        WlsLogger::error_('wls.ssl.protocols 只允许字符串 tls1.2/tls1.3');
        exit(1);
    }
    $wlsConfiguredSslProtocol = \strtolower(\trim($wlsConfiguredSslProtocol));
    $wlsConfiguredSslProtocol = \str_replace(['_', '-', ' '], ['.', '.', ''], $wlsConfiguredSslProtocol);
    $wlsConfiguredSslProtocol = \str_replace('tlsv', 'tls', $wlsConfiguredSslProtocol);
    if (\in_array($wlsConfiguredSslProtocol, ['1.2', 'tls1.2', 'tls12'], true)) {
        if (!\defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
            WlsLogger::error_('TLS 1.2 已配置，但当前 PHP/OpenSSL 不支持 TLS 1.2 server stream');
            exit(1);
        }
        $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
        continue;
    }
    if (\in_array($wlsConfiguredSslProtocol, ['1.3', 'tls1.3', 'tls13'], true)) {
        if (!\defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
            WlsLogger::error_('TLS 1.3 已配置，但当前 PHP/OpenSSL 不支持 TLS 1.3 server stream');
            exit(1);
        }
        $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
        continue;
    }

    WlsLogger::error_("wls.ssl.protocols 包含不支持的值 {$wlsConfiguredSslProtocol}；只允许 tls1.2/tls1.3");
    exit(1);
}
$wlsModernTlsCiphers = 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:!aNULL:!eNULL:!MD5:!RC4:!DES:!3DES:!DSS:!SHA1:!DHE';
$wlsModernTlsCurves = 'X25519:prime256v1';

// 验证 RuntimeSelection 下发的 SO_REUSEPORT 原语。
$isWindows = \PHP_OS_FAMILY === 'Windows';
$supportsReusePort = $useReusePort && (
    \defined('SO_REUSEPORT')
    && \extension_loaded('sockets')
    && \function_exists('socket_create')
    && \function_exists('socket_set_option')
);

// Master 只有在最终 RuntimeSelection=direct/reuseport 且真实 probe 通过后才会
// 下发 --reuseport。Worker 不再二次猜测 Linux 内核版本；以实际 set/bind/listen
// 与 READY 作为最终门禁。
if ($useReusePort && !$supportsReusePort) {
    WlsLogger::error_("RuntimeSelection 要求 SO_REUSEPORT，但当前 Worker 缺少 sockets/SO_REUSEPORT 原语");
    exit(1);
}

// ========== Socket 创建 ==========

$socket = null;
$reusePortBound = false;
$sharedListenerBound = false;
$sharedListenerSocket = null;

// 延迟 SSL 时共用：accept 后根据首包判断 HTTP 重定向或启用 SSL（同端口 http→https）
$deferSslOptions = null;
if ($deferSsl) {
    $deferSslOptions = [
        'local_cert' => $sslCert,
        'local_pk' => $sslKey,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
        'disable_compression' => true,
        'crypto_method' => $cryptoMethod,
        'ciphers' => $wlsModernTlsCiphers,
        'ecdh_curve' => $wlsModernTlsCurves,
        'single_dh_use' => true,
        'honor_cipher_order' => true,
        'SNI_enabled' => !empty($sniServerCerts),
        'SNI_server_certs' => $sniServerCerts,
    ];
}

// 特权端口权限检查（macOS/Linux）
if (\PHP_OS !== 'WINNT' && $port < 1024) {
    $euid = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : -1;
    if ($euid !== 0 && $euid !== -1) {
        WlsLogger::error_("错误：尝试绑定特权端口 {$port} 但当前进程不是 root (euid: {$euid})");
        WlsLogger::error_("请使用 sudo php bin/w server:start 启动服务器");
        exit(1);
    }
}

// macOS direct 使用 Master 预绑定的单个共享 accept queue。TLS 仍由 Worker accept 后处理。
if ($listenFd > 0) {
    if (!$deferSsl) {
        WlsLogger::error_('Inherited direct listener requires --defer-ssl for per-connection TLS/SNI handling');
        exit(1);
    }
    $socket = @\fopen('php://fd/' . $listenFd, 'r+');
    if (!\is_resource($socket)) {
        WlsLogger::error_("Unable to open inherited direct listener FD {$listenFd}");
        exit(1);
    }
    // php://fd produces a plain tcp_socket wrapper. Connections accepted via
    // stream_socket_accept inherit that wrapper and cannot be promoted to TLS
    // on macOS ("This stream does not support SSL/crypto"). Keep the stream for
    // event-loop readiness, but accept through sockets and export each client
    // so PHP creates the crypto-capable tcp_socket/ssl wrapper.
    if (!\function_exists('socket_import_stream')
        || !\function_exists('socket_accept')
        || !\function_exists('socket_export_stream')
    ) {
        @\fclose($socket);
        WlsLogger::error_('Inherited direct TLS listener requires the sockets extension');
        exit(1);
    }
    $sharedListenerSocket = @\socket_import_stream($socket);
    if (!$sharedListenerSocket instanceof \Socket) {
        @\fclose($socket);
        WlsLogger::error_('Unable to import the inherited direct listener as a native socket');
        exit(1);
    }
    $sharedListenerBound = true;
    WlsLogger::info_("Using inherited direct shared listener FD {$listenFd} on {$host}:{$port}");

// 方案1a：SO_REUSEPORT + 延迟 SSL（同端口 HTTP→HTTPS 重定向，与方案2b 行为一致）
} elseif ($useReusePort && $supportsReusePort && $deferSsl && \function_exists('socket_create')) {
    WlsLogger::info_("使用 SO_REUSEPORT + 延迟 SSL，监听 tcp://{$host}:{$port}（同端口 HTTP→HTTPS 重定向）");
    $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$rawSocket) {
        WlsLogger::error_("socket_create 失败: " . \socket_strerror(\socket_last_error()));
        exit(1);
    }
    if (!\Weline\Server\Socket\ListenSocketOptions::applyRawListenSocketReuseOption($rawSocket)['success']) {
        WlsLogger::warning_("设置 SO_REUSEADDR 失败");
    }
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
        WlsLogger::error_("设置 SO_REUSEPORT 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    if (!@\socket_bind($rawSocket, $host, $port)) {
        $errCode = \socket_last_error($rawSocket);
        $errMsg = \socket_strerror($errCode);
        WlsLogger::error_("socket_bind 失败: ({$errCode}) {$errMsg}");
        @\socket_close($rawSocket);
        exit(1);
    }
    if (!@\socket_listen($rawSocket, 102400)) {
        WlsLogger::error_("socket_listen 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    $socket = \socket_export_stream($rawSocket);
    if (!$socket) {
        WlsLogger::error_("socket_export_stream 失败");
        @\socket_close($rawSocket);
        exit(1);
    }
    $reusePortBound = true;
    // 不在此 socket 上启用 SSL，由 accept 后按首包处理
    WlsLogger::info_("SO_REUSEPORT + 延迟 SSL socket 创建成功，Worker #{$workerId} 监听 {$host}:{$port}");

// 方案1b：使用 socket 扩展创建支持 SO_REUSEPORT 的 socket（直接 SSL，无同端口重定向）
} elseif ($useReusePort && $supportsReusePort && \function_exists('socket_create')) {
    WlsLogger::info_("使用 socket 扩展创建 SO_REUSEPORT socket...");
    
    // 创建原始 socket
    $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$rawSocket) {
        WlsLogger::error_("socket_create 失败: " . \socket_strerror(\socket_last_error()));
        exit(1);
    }
    
    // 设置 SO_REUSEADDR
    if (!\Weline\Server\Socket\ListenSocketOptions::applyRawListenSocketReuseOption($rawSocket)['success']) {
        WlsLogger::warning_("设置 SO_REUSEADDR 失败");
    }
    
    // 设置 SO_REUSEPORT
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
        WlsLogger::error_("设置 SO_REUSEPORT 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 绑定地址
    if (!@\socket_bind($rawSocket, $host, $port)) {
        $errCode = \socket_last_error($rawSocket);
        $errMsg = \socket_strerror($errCode);
        WlsLogger::error_("socket_bind 失败: ({$errCode}) {$errMsg}");
        
        // 如果端口被占用，直接退出进程，不再重试
        if ($errCode === 10048 || $errCode === 98) { // Windows: 10048, Linux: 98
            @\socket_close($rawSocket);
            exit(1); // 退出码 1 通知 Master 启动失败
        }
        
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 开始监听
    if (!@\socket_listen($rawSocket, 102400)) {
        WlsLogger::error_("socket_listen 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 将 socket 资源转换为 stream
    $socket = \socket_export_stream($rawSocket);
    if (!$socket) {
        WlsLogger::error_("socket_export_stream 失败");
        @\socket_close($rawSocket);
        exit(1);
    }
    $reusePortBound = true;
    
    // 启用 SSL 加密（手动处理）
    $sslContext = \stream_context_create([
        'ssl' => [
            'local_cert' => $sslCert,
            'local_pk' => $sslKey,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'disable_compression' => true,
            'crypto_method' => $cryptoMethod,
            'ciphers' => $wlsModernTlsCiphers,
            'ecdh_curve' => $wlsModernTlsCurves,
            'single_dh_use' => true,
            'honor_cipher_order' => true,
            'SNI_enabled' => !empty($sniServerCerts),
            'SNI_server_certs' => $sniServerCerts,
        ]
    ]);
    \stream_context_set_params($socket, \stream_context_get_params($sslContext));
    
    WlsLogger::info_("SO_REUSEPORT socket 创建成功，Worker #{$workerId} 监听 {$host}:{$port}");
    
} elseif ($deferSsl && $useReusePort && !$isWindows && \function_exists('socket_create')) {
    // 方案2b-socket：仅 SO_REUSEPORT 直连模式才用 socket 扩展（socket_export_stream + stream_socket_accept 在 Dispatcher 模式下不可靠）
    // Dispatcher 模式（$useReusePort=false）直接 fallthrough 到方案2b 的 stream_socket_server，保证 stream_socket_accept 正常工作
    $maxBindRetries = 1;
    $bindRetryDelay = 0;
    $rawSocket = false;
    $lastErrno = 0;
    $lastErrstr = '';

    for ($attempt = 1; $attempt <= $maxBindRetries; $attempt++) {
        $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$rawSocket) {
            $lastErrno = \socket_last_error();
            $lastErrstr = \socket_strerror($lastErrno);
            WlsLogger::error_("Socket 创建失败 (defer-ssl): {$lastErrstr} (errno: {$lastErrno})");
            break;
        }
        if (!\Weline\Server\Socket\ListenSocketOptions::applyRawListenSocketReuseOption($rawSocket)['success']) {
            WlsLogger::warning_("设置 SO_REUSEADDR 失败");
        }
        if (\defined('SO_REUSEPORT') && !@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
            WlsLogger::warning_("设置 SO_REUSEPORT 失败（可忽略）");
        }
        if (@\socket_bind($rawSocket, $host, $port)) {
            break;
        }
        $lastErrno = \socket_last_error($rawSocket);
        $lastErrstr = \socket_strerror($lastErrno);
        @\socket_close($rawSocket);
        $rawSocket = false;
        if ($lastErrno !== 98) { // EADDRINUSE on Linux
            WlsLogger::error_("Socket 绑定失败 (defer-ssl): {$lastErrstr} (errno: {$lastErrno})");
            break;
        }
        WlsLogger::warning_("端口 {$port} 占用 (errno: {$lastErrno})，{$bindRetryDelay} 秒后重试 ({$attempt}/{$maxBindRetries})");
        if ($attempt < $maxBindRetries) {
            \Weline\Framework\Runtime\SchedulerSystem::sleep($bindRetryDelay);
        }
    }

    if (!$rawSocket) {
        WlsLogger::error_("Socket 创建失败 (defer-ssl): {$lastErrstr} (errno: {$lastErrno})");
        w_log_error("[WLS Worker SSL] Failed to create socket (defer-ssl): {$lastErrstr}");
        exit(1);
    }
    if (!@\socket_listen($rawSocket, 102400)) {
        WlsLogger::error_("socket_listen 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    $socket = \socket_export_stream($rawSocket);
    if (!$socket) {
        WlsLogger::error_("socket_export_stream 失败");
        @\socket_close($rawSocket);
        exit(1);
    }
    WlsLogger::info_("延迟 SSL 模式: 监听 tcp://{$host}:{$port}，accept 后手动启用 SSL");

} elseif ($deferSsl) {
    // 方案2b：Windows 或未走 2b-socket 时，保持原 stream_socket_server 逻辑不变
    // Windows 下可能出现 TLS reset（cURL 35），与延迟 SSL 无关，属 PHP stream+OpenSSL 兼容性。
    $socketOptions = [
        'backlog' => 102400,
    ];

    $socketOptions = \Weline\Server\Socket\ListenSocketOptions::streamContextOptions($socketOptions);

    $context = \stream_context_create([
        'socket' => $socketOptions,
        'ssl' => $deferSslOptions,
    ]);

    $socket = @\stream_socket_server(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );

    if (!$socket) {
        WlsLogger::error_("Socket 创建失败 (defer-ssl): {$errstr} (errno: {$errno})");
        w_log_error("[WLS Worker SSL] Failed to create socket (defer-ssl): {$errstr}");
        exit(1);
    }
    WlsLogger::info_("延迟 SSL 模式: 监听 tcp://{$host}:{$port}，accept 后手动启用 SSL");

} else {
    // 方案2：标准 stream_socket_server 方式
    $socketOptions = [
        'backlog' => 102400,  // 增大 backlog 提高并发
    ];

    $socketOptions = \Weline\Server\Socket\ListenSocketOptions::streamContextOptions($socketOptions);

    // Linux 下尝试启用 SO_REUSEPORT（通过 stream context，可能不被支持）
    if ($supportsReusePort && !$useReusePort) {
        $socketOptions['so_reuseport'] = true;
        WlsLogger::info_("尝试通过 stream_context 启用 SO_REUSEPORT");
    }

    $context = \stream_context_create([
        'socket' => $socketOptions,
        'ssl' => [
            'local_cert' => $sslCert,
            'local_pk' => $sslKey,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'disable_compression' => true,
            'crypto_method' => $cryptoMethod,
            'ciphers' => $wlsModernTlsCiphers,
            'ecdh_curve' => $wlsModernTlsCurves,
            'single_dh_use' => true,
            'honor_cipher_order' => true,
            'SNI_enabled' => !empty($sniServerCerts),
            'SNI_server_certs' => $sniServerCerts,
        ]
    ]);

    $socket = @\stream_socket_server(
        "ssl://{$host}:{$port}",
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );

    if (!$socket) {
        WlsLogger::error_("Socket 创建失败: {$errstr} (errno: {$errno})");
        w_log_error("[WLS Worker SSL] Failed to create socket: {$errstr}");
        exit(1);
    }
}

WlsLogger::info_("Socket 创建成功，开始监听连接");

$wlsStartupTrace('socket_listen_ready', ['port' => $port]);
\stream_set_blocking($socket, false);
\Weline\Server\Service\Runtime\WorkerReadinessState::markListenerBound(
    $reusePortBound,
    (string)($eventLoopMeta['resolved'] ?? $wlsLoopDriver),
    'stream',
    $sharedListenerBound ? 'shared_fd' : ($reusePortBound ? 'reuseport' : 'single'),
    $sharedListenerBound ? $listenFd : 0,
);

// ========== 上报 READY 前跳过 Session/Memory 验证（按需连接） ==========
// Session/Memory 是共享服务，连接在首次使用时自动建立，无需在启动时预验证
// 这样可以大幅缩短 Worker 启动时间，避免启动阶段阻塞
$sessionReadyVerified = false;
$memoryReadyVerified = false;

if ($isMaintenanceWorker) {
    WlsLogger::info_('[Session/Memory] 维护 Worker 跳过连接验证（按需连接）');
} else {
    // 业务 Worker 也跳过验证，Session/Memory 连接在 ConnectionPool 首次使用时自动建立
    WlsLogger::info_('[Session/Memory] 业务 Worker 跳过连接验证（连接在首次使用时自动建立）');
}

// ========== IPC 控制通道：连接 Master 并注册 + 上报就绪 ==========
$ipcClient = null;
\Weline\Server\Security\GlobalRateLimiter::setBanDeltaPublisher(
    static function (string $deltaInstance, string $ip, int $expiresAt) use (&$ipcClient): void {
        if ($ipcClient !== null && $ipcClient->isConnected()) {
            $ipcClient->send(\Weline\Server\IPC\ControlMessage::policyStateDelta($deltaInstance, $ip, $expiresAt), false);
        }
    }
);
$ipcSelfTag = null;
$ipcDraining = false;
$ipcReceivedShutdown = false;
$drainStartTime = 0;
$shouldExit = false;
$cacheClearEpoch = 0;
$maxDrainTime = 10;     // 由 Master drain/reload 消息或默认覆盖
$waitingForAck = false;
$readySentTime = 0.0;
$ackRetryCount = 0;
$maxAckRetries = 0;
$ackTimeout = 10.0;
$readyGateWorkerBootstrapWarmupCompleted = false;
$readyGateSharedRuntimeConnectionWarmupCompleted = false;
$runReadyGateWorkerBootstrapWarmup = static function () use (
    &$readyGateWorkerBootstrapWarmupCompleted,
    &$readyGateSharedRuntimeConnectionWarmupCompleted,
    &$runtime,
    &$runtimeError,
    $isMaintenanceWorker,
    $workerId,
    $instanceName,
    $sessionHost,
    $sessionPort,
    $sessionTokenFileName,
    $memoryHost,
    $memoryPort,
    $memoryTokenFileName
): void {
    if ($readyGateWorkerBootstrapWarmupCompleted) {
        return;
    }
    if ($isMaintenanceWorker) {
        \Weline\Server\Service\Runtime\WorkerReadinessState::markMaintenanceReady();
        $readyGateWorkerBootstrapWarmupCompleted = true;
        return;
    }
    if ($runtimeError !== null || !$runtime instanceof \Weline\Framework\Runtime\WlsRuntime) {
        return;
    }

    WlsLogger::info_("[WorkerWarmup] ready-gate bootstrap warmup start worker={$workerId}");
    $poolWarmup = \Weline\Server\Service\SharedRuntimeConnectionWarmup::warmWorkerPools(
        $workerId,
        $instanceName,
        [
            'session' => [
                'host' => $sessionHost,
                'port' => $sessionPort,
                'token_file_name' => $sessionTokenFileName,
            ],
            'memory' => [
                'host' => $memoryHost,
                'port' => $memoryPort,
                'token_file_name' => $memoryTokenFileName,
            ],
        ]
    );
    $poolWarmupErrors = \is_array($poolWarmup['errors'] ?? null) ? $poolWarmup['errors'] : [];
    if ($poolWarmupErrors !== []) {
        throw new \RuntimeException(
            'READY gate shared runtime connection warmup failed: '
            . (\json_encode($poolWarmupErrors, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}')
        );
    }
    $readyGateSharedRuntimeConnectionWarmupCompleted = true;
    $homepageFpcProof = $runtime->runReadyGateWorkerBootstrapWarmup();
    \Weline\Server\Service\Runtime\WorkerReadinessState::markBusinessHomepageHot($homepageFpcProof);
    \Weline\Server\Service\Runtime\WorkerReadinessState::markDynamicFirstRenderProof(
        $runtime->readyGateDynamicFirstRenderProof()
    );
    $readyGateWorkerBootstrapWarmupCompleted = true;
    WlsLogger::info_("[WorkerWarmup] ready-gate bootstrap warmup done worker={$workerId}");
};
$exitBecauseMasterMissingAtStartup = false;
$orphanGuard = new \Weline\Server\IPC\ChildControl\MasterOrphanGuard();
$lastMasterPidHardCheck = 0;
$maxMemoryBytes = wlsMemoryLimitToBytes($wlsMemoryLimit);
if ($maxMemoryBytes <= 0) {
    $maxMemoryBytes = 256 * 1024 * 1024;
}
$memoryCheckInterval = 5;
$lastMemoryCheck = \time();
$normalizeMemoryThreshold = static function (mixed $value, float $default): float {
    if (!\is_numeric($value)) {
        return $default;
    }

    $threshold = (float) $value;
    if ($threshold <= 0.0 || $threshold >= 1.0) {
        return $default;
    }

    return $threshold;
};
$memoryGuardConfig = \is_array($wlsEnv['memory_guard'] ?? null) ? $wlsEnv['memory_guard'] : [];
$configuredRequestGcInterval = $memoryGuardConfig['request_gc_interval'] ?? 512;
$requestGcInterval = \is_numeric($configuredRequestGcInterval)
    ? \max(64, \min(65536, (int)$configuredRequestGcInterval))
    : 512;
$lastRequestGcCount = 0;
$memoryWarningThreshold = $normalizeMemoryThreshold(
    $memoryGuardConfig['worker_memory_warning_threshold'] ?? 0.80,
    0.80
);
$baseMemoryDrainThreshold = $normalizeMemoryThreshold(
    $memoryGuardConfig['worker_memory_drain_threshold'] ?? 0.94,
    0.94
);
$memoryDrainJitter = $normalizeMemoryThreshold(
    $memoryGuardConfig['worker_memory_drain_jitter'] ?? 0.01,
    0.01
);
$memoryDrainThreshold = \min(
    0.98,
    \max(
        $memoryWarningThreshold + 0.02,
        $baseMemoryDrainThreshold + (\max(0, $workerId - 1) % 5) * $memoryDrainJitter
    )
);
$maxRequestHeaderBytes = 65536;
$maxRequestBodyBytes = 16 * 1024 * 1024;
$maxBufferedRequestBytes = $maxRequestHeaderBytes + $maxRequestBodyBytes;

// 如果启用了维护模式
if ($isMaintenanceWorker) {
    try {
        // Child-maintenance state must stay process-local and must not
        // re-enter the master maintenance IPC control queue.
        \Weline\Framework\App\Env::getInstance()->setRuntimeMaintenanceMode(true);
        WlsLogger::info_("维护 Worker 模式已启用");
    } catch (\Throwable $e) {
        WlsLogger::warning_("设置维护模式失败: " . $e->getMessage());
    }
} else {
    try {
        \Weline\Framework\App\Env::getInstance()->setRuntimeMaintenanceMode(false);
        WlsLogger::info_("业务 Worker 模式已固定为非维护");
    } catch (\Throwable $e) {
        WlsLogger::warning_("设置业务 Worker 非维护模式失败: " . $e->getMessage());
    }
}

try {
    $workerPolicyKernel = \Weline\Server\Security\WorkerPolicyKernel::boot(
        $instanceName,
        $wlsRuntimeTopology,
        $workerCount
    );
    $workerPolicyKernel->setMaintenanceMode($isMaintenanceWorker);
    \Weline\Server\Service\Runtime\WorkerReadinessState::markPolicyLoaded(
        $workerPolicyKernel->policyDigest()
    );
    $requestFramingLimits = $workerPolicyKernel->framingLimits();
    $maxRequestHeaderBytes = $requestFramingLimits['max_header_bytes'];
    $maxRequestBodyBytes = $requestFramingLimits['max_body_bytes'];
    $maxBufferedRequestBytes = $requestFramingLimits['max_buffer_bytes'];
    WlsLogger::info_('[PolicyKernel] ready topology=' . $wlsRuntimeTopology
        . ' digest=' . $workerPolicyKernel->policyDigest());
    if ($wlsRuntimeTopology === 'direct') {
        $workerOrdinal = ($workerId - 1) % \max(1, $workerCount);
        $workerPolicyKernel->bootConnectionAcceptGatePool(\max(0, $workerOrdinal));
        WlsLogger::info_('[AcceptGate] direct public accept enabled ordinal=' . \max(0, $workerOrdinal));
    }
} catch (\Throwable $policyError) {
    WlsLogger::error_('[PolicyKernel] bootstrap failed: ' . $policyError->getMessage());
    throw $policyError;
}
$workerTelemetryReporter = \Weline\Server\Service\Telemetry\WorkerTelemetryReporter::boot($instanceName);
$workerHealthAccessPolicy = \Weline\Server\Service\WorkerHealthAccessPolicy::boot($instanceName);

// 获取控制端口
if ($controlPort <= 0 && !$supervisorEnabled) {
    $controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, $controlPort);
}
$ipcRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);

if ($controlPort > 0 || $supervisorEnabled) {
    $wlsStartupTrace('ipc_connect_begin', ['control_port' => $controlPort]);
    $ipcSelfTag = ($isMaintenanceWorker ? 'Maintenance' : 'Worker') . "#{$workerId}";
    $identity = new \Weline\Server\IPC\ChildControl\ChildProcessIdentity(
        $ipcRole,
        \getmypid(),
        $port,
        $workerId,
        $orchestratorEpoch,
        $orchestratorLaunchId
    );
    $handler = new \Weline\Server\IPC\ChildControl\Handler\WorkerSslControlHandler(
        static function (array $msg) use (&$shouldExit, &$ipcDraining, &$ipcReceivedShutdown, &$socket, &$drainStartTime, &$maxDrainTime, &$waitingForAck, $workerId, &$sniServerCerts, &$ipcClient, $isMaintenanceWorker, &$activeFibers, &$fiberIdleTtlSec, &$fiberMaxActive, &$fiberReleaseIdleRequested, $port, &$deferSslOptions, $sslCert, $sslKey, $cryptoMethod, $instanceName, $listenerHost, $wlsRuntimeTopology, &$cacheClearEpoch, $maintenanceDrainState): void {
            $type = $msg['type'] ?? '';
            // 帝王令：shutdown 至高无上，一旦收到则不再处理其他 IPC（RELOAD/DRAIN/CACHE_CLEAR）
            if ($type !== \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN && $ipcReceivedShutdown) {
                return;
            }
            switch ($type) {
                case \Weline\Server\IPC\ControlMessage::TYPE_PING:
                    $pingTimestamp = (float) ($msg['timestamp'] ?? 0.0);
                    $stats = [
                        'active_fibers' => \count($activeFibers),
                        'memory_usage' => \memory_get_usage(true),
                    ];
                    if ($ipcClient !== null && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::pong($pingTimestamp, $stats));
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_ACK_READY:
                case \Weline\Server\IPC\ControlMessage::TYPE_READY_ACK:
                    $accepted = !\array_key_exists('accepted', $msg) || (bool)($msg['accepted'] ?? false);
                    if (!$accepted) {
                        $reason = (string)($msg['reason'] ?? 'ready_rejected');
                        $waitingForAck = false;
                        $shouldExit = true;
                        $ipcDraining = true;
                        $maxDrainTime = 1;
                        $drainStartTime = \time() - $maxDrainTime;
                        if ($socket && \is_resource($socket)) {
                            @\fclose($socket);
                            $socket = null;
                            \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                        }
                        if ($ipcClient !== null && $ipcClient->isConnected()) {
                            $ipcClient->send(\Weline\Server\IPC\ControlMessage::exitReason('master_rejected_ready:' . $reason, 0));
                        }
                        WlsLogger::warning_("Master ACK 确认结果：失败（reason={$reason}），SSL Worker 自毁退出");
                        break;
                    }
                    $waitingForAck = false;
                    $ackWorkerId = $msg['worker_id'] ?? 0;
                    $dispatcherConfirmed = (bool)($msg['dispatcher_confirmed'] ?? false);
                    $ackPort = (int)($msg['port'] ?? 0);
                    WlsLogger::info_(
                        "收到 Master ACK_READY 确认，Master ACK 确认结果：成功 (worker_id={$ackWorkerId}, dispatcher_confirmed="
                        . ($dispatcherConfirmed ? '1' : '0') . ", port={$ackPort})，SSL Worker 停止 READY 重报"
                    );
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_WORKER_POOL_ACK:
                    if (($msg['role'] ?? '') !== \Weline\Server\IPC\ControlMessage::ROLE_WORKER) {
                        break;
                    }
                    if ((bool)($msg['in_pool'] ?? false)) {
                        break;
                    }
                    $reason = (string)($msg['reason'] ?? 'dispatcher_not_in_pool');
                    $retrying = (bool)($msg['retrying'] ?? false);
                    $ackPort = (int)($msg['port'] ?? 0);
                    WlsLogger::warning_(
                        "Master ACK 确认结果：失败（reason={$reason}, port={$ackPort}）"
                        . ($retrying ? '，已触发自愈重试，继续等待闭环 ACK' : '')
                    );
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_RELOAD:
                    // 代码重载：先清 opcache（共享内存级），确保新 Worker 加载最新文件
                    if (\function_exists('opcache_reset')) {
                        \opcache_reset();
                    }
                    \clearstatcache(true);
                    $shouldExit = true;
                    $ipcDraining = true;
                    $drainStartTime = \time();
                    $dt = (int) ($msg['drain_timeout_sec'] ?? 0);
                    $maxDrainTime = $dt > 0 ? \max(1, \min(7200, $dt)) : 120;
                    // 关闭监听 socket（不再接受新连接）
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                    }
                    WlsLogger::info_("收到 reload 命令，已清除 opcache 并关闭监听 socket，开始排水（最多等待 {$maxDrainTime} 秒）...");
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_CACHE_CLEAR:
                    $requestedCacheEpoch = \max(0, (int)($msg['cache_epoch'] ?? 0));
                    if ($requestedCacheEpoch > 0 && $requestedCacheEpoch < $cacheClearEpoch) {
                        $ipcClient?->send(\Weline\Server\IPC\ControlMessage::cacheClearAck(
                            $requestedCacheEpoch,
                            false,
                            'stale_cache_epoch',
                            $workerId,
                            false,
                            $cacheClearEpoch,
                        ));
                        WlsLogger::warning_("拒绝过期 cache_clear 代际 {$requestedCacheEpoch}，当前代际 {$cacheClearEpoch}");
                        break;
                    }
                    if ($requestedCacheEpoch > 0 && $requestedCacheEpoch === $cacheClearEpoch) {
                        $ipcClient?->send(\Weline\Server\IPC\ControlMessage::cacheClearAck(
                            $requestedCacheEpoch,
                            true,
                            '',
                            $workerId,
                            false,
                            $cacheClearEpoch,
                        ));
                        break;
                    }

                    try {
                        // 缓存清理：只有全部 L1 reset 完成后才提交新代际。
                        if (\function_exists('opcache_reset')) {
                            \opcache_reset();
                        }
                        \clearstatcache(true);
                        $cachePoolResults = \Weline\Server\Service\Runtime\WorkerCachePoolResetter::clearFrameworkPools();
                        $failedCachePools = \Weline\Server\Service\Runtime\WorkerCachePoolResetter::failedPools(
                            $cachePoolResults
                        );
                        if ($failedCachePools !== []) {
                            throw new \RuntimeException(
                                'cache_pool_clear_failed:' . \implode(',', $failedCachePools)
                            );
                        }
                        \Weline\Framework\Manager\ObjectManager::clearInstances();
                        if (\class_exists(\Weline\Framework\Phrase\Parser::class)) {
                            \Weline\Framework\Phrase\Parser::clearWorkerCaches();
                        }
                        if (\class_exists(\Weline\Framework\Hook\Config\HookReader::class)) {
                            \Weline\Framework\Hook\Config\HookReader::clearStaticCache();
                        }
                        if (\class_exists(\Weline\Framework\View\Template::class)) {
                            \Weline\Framework\View\Template::clearStaticHookCaches();
                        }
                        \Weline\Framework\Manager\ObjectManager::getInstance(
                            \Weline\Framework\Runtime\ModuleProcessCacheResetterRegistry::class
                        )->reset(new \Weline\Framework\Runtime\ProcessCacheResetContext(
                            \Weline\Framework\Runtime\ProcessCacheResetContext::REASON_CACHE_CLEAR,
                            true
                        ));
                        if (\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class)) {
                            \Weline\Framework\Router\FullPageCacheCoordinator::clearProcessCache();
                        }
                        if (\function_exists('handleStaticFile')) {
                            handleStaticFile('__CLEAR_CACHE__', '');
                        }
                        if ($requestedCacheEpoch > 0) {
                            $cacheClearEpoch = $requestedCacheEpoch;
                            $ipcClient?->send(\Weline\Server\IPC\ControlMessage::cacheClearAck(
                                $requestedCacheEpoch,
                                true,
                                '',
                                $workerId,
                                true,
                                $cacheClearEpoch,
                            ));
                        }
                        WlsLogger::info_("收到 cache_clear 命令，已清理缓存，代际={$cacheClearEpoch}");
                    } catch (\Throwable $throwable) {
                        if ($requestedCacheEpoch > 0) {
                            $ipcClient?->send(\Weline\Server\IPC\ControlMessage::cacheClearAck(
                                $requestedCacheEpoch,
                                false,
                                'cache_reset_failed',
                                $workerId,
                                false,
                                $cacheClearEpoch,
                            ));
                        }
                        WlsLogger::error_("cache_clear 执行失败：" . $throwable->getMessage());
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SSL_CERT_RELOAD:
                    \clearstatcache(true);
                    $reloadDomains = isset($msg['domains']) && \is_array($msg['domains'])
                        ? \array_filter($msg['domains'], static fn($d) => \is_string($d) && $d !== '')
                        : [];
                    // 清除指定域名的内存正缓存（若无指定则全量替换）
                    if (!empty($reloadDomains)) {
                        foreach ($reloadDomains as $reloadDomain) {
                            unset($sniServerCerts[$reloadDomain]);
                        }
                    }
                    $oldCount = \count($sniServerCerts);
                    $newSniCerts = _loadSniCertsFromMap();
                    // 合并：新 map 为主，手动清除的域名若已在磁盘则会被 map 重新加入
                    $sniServerCerts = $newSniCerts;
                    wlsSslMergeDefaultListenerHostnamesIntoSniMap($sniServerCerts, $sslCert, $sslKey, $listenerHost);
                    $newCount = \count($sniServerCerts);
                    $domainsStr = $newCount > 0 ? \implode(', ', \array_keys($sniServerCerts)) : '(空)';
                    $targetStr = empty($reloadDomains) ? '全量重载' : ('域名：' . \implode(', ', $reloadDomains));
                    WlsLogger::info_("收到 ssl_cert_reload（{$targetStr}），已热更新 SNI 证书映射（{$oldCount} → {$newCount}）：{$domainsStr}");
                    wlsSslApplySniOptionsToContexts($deferSslOptions, $socket, $sniServerCerts, $sslCert, $sslKey, $cryptoMethod);
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_ROUTING_POLICY:
                    $policyData = $msg['data'] ?? [];
                    if (\is_array($policyData)) {
                        \Weline\Server\Service\Runtime\RoutingPolicyRegistry::update($policyData);
                        WlsLogger::info_('收到 routing_policy 命令，已更新进程内路由策略快照');
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_FIBER_SET_CONFIG:
                    $fiberIdleTtlSec = (int) ($msg['idle_ttl_sec'] ?? 0);
                    $fiberMaxActive = (int) ($msg['max_active'] ?? 0);
                    WlsLogger::info_("收到 fiber_set_config: idle_ttl_sec={$fiberIdleTtlSec}, max_active={$fiberMaxActive}");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_FIBER_RELEASE_IDLE:
                    $fiberReleaseIdleRequested = true;
                    WlsLogger::info_('收到 fiber_release_idle，下一轮循环执行释放');
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_FIBER_POOL_QUERY:
                    $requestId = $msg['request_id'] ?? '';
                    if ($requestId !== '' && $ipcClient !== null && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::fiberPoolStats(
                            $requestId,
                            $workerId,
                            \count($activeFibers),
                            (int) $fiberIdleTtlSec,
                            (int) $fiberMaxActive,
                            0
                        ));
                    }
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_DRAIN:
                    // 排水模式：停止接受新连接，完成现有请求后退出
                    $shouldExit = true;
                    $ipcDraining = true;
                    $drainStartTime = \time();
                    $dt = (int) ($msg['drain_timeout_sec'] ?? 0);
                    if ($dt > 0) {
                        $maxDrainTime = \max(1, \min(7200, $dt));
                    }
                    // 关闭监听 socket（不再接受新连接）
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                    }
                    WlsLogger::info_("收到 drain 命令，已关闭监听 socket，开始排水（最多 {$maxDrainTime}s）...");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SET_MAINTENANCE_MODE:
                    $mEnabled = (bool) ($msg['enabled'] ?? false);
                    $mReqId = (string) ($msg['request_id'] ?? '');
                    $effectiveMaintenance = $isMaintenanceWorker ? true : $mEnabled;
                    \Weline\Framework\App\Env::getInstance()->setRuntimeMaintenanceMode($effectiveMaintenance);
                    \Weline\Server\Security\WorkerPolicyKernel::instance()->setMaintenanceMode($effectiveMaintenance);
                    $maintenanceDrainState->modeApplied($effectiveMaintenance, $mReqId);
                    WlsLogger::info_(
                        "已应用 Worker 维护模式 enabled=" . ($effectiveMaintenance ? 'true' : 'false')
                        . " request_id={$mReqId}"
                        . " pinned_role=" . ($isMaintenanceWorker ? 'maintenance' : 'business')
                    );
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SECURITY_UNBLOCK:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_STATE_DELTA:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_PREPARE:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_ACTIVATE:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_COMMIT:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_ROLLBACK:
                    $policyReply = \Weline\Server\Service\Policy\WorkerPolicyControl::handle($msg, $wlsRuntimeTopology, $instanceName);
                    if ($policyReply !== null && $ipcClient !== null && $ipcClient->isConnected()) {
                        $ipcClient->send($policyReply);
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN:
                    // 主动终结：优雅退出
                    $ipcReceivedShutdown = true;
                    $shouldExit = true;
                    $ipcDraining = true;
                    $maxDrainTime = 1;
                    $drainStartTime = \time() - $maxDrainTime;
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                    }
                    WlsLogger::info_("收到 shutdown 命令，准备退出");
                    break;
            }
        },
        static function () use (&$ipcClient, $wlsWorkerExitTrace, &$shouldExit, &$ipcDraining, &$ipcReceivedShutdown): void {
            $wlsWorkerExitTrace('ipc_unexpected_disconnect', 'control_client_disconnected', [
                'should_exit' => (bool)$shouldExit,
                'ipc_draining' => (bool)$ipcDraining,
                'shutdown_received' => (bool)$ipcReceivedShutdown,
            ]);
            $ipcClient?->tryReconnect();
        }
    );
    if ($earlyIpcHandler instanceof \Weline\Server\IPC\ChildControl\Handler\DelegatingControlHandler) {
        $earlyIpcHandler->setDelegate($handler);
    }
    if (!$kernel instanceof \Weline\Server\IPC\ChildControl\SubprocessControlKernel) {
        $kernel = new \Weline\Server\IPC\ChildControl\SubprocessControlKernel(
            $identity,
            $handler,
            $ipcSelfTag,
            (\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE),
            $instanceName
        );
    }
    $ipcClient = $kernel->getClient();
    $wlsStartupTrace('ready_gate_warmup_begin', ['control_port' => $controlPort]);
    $runReadyGateWorkerBootstrapWarmup();
    $wlsStartupTrace('ready_gate_warmup_done', ['control_port' => $controlPort]);
    $wlsStartupTrace('ipc_ready_report_begin', [
        'control_port' => $controlPort,
        'connected' => $kernel->isConnected() ? 1 : 0,
        'readiness' => \Weline\Server\Service\Runtime\WorkerReadinessState::snapshot(),
    ]);
    $readyReported = $kernel->isConnected()
        ? $kernel->sendReady()
        : $kernel->connectAndRegister($controlPort);
    if ($readyReported) {
        $wlsStartupTrace('ipc_ready_sent', ['control_port' => $controlPort]);
        $ipcClient = $kernel->getClient();
        $ipcTransportLabel = $supervisorEnabled && $controlPort <= 0 ? 'Supervisor channel' : "控制端口: {$controlPort}";
        WlsLogger::info_("IPC 控制通道已连接 ({$ipcTransportLabel})");
        $waitingForAck = !($ipcClient?->isReadyStateConfirmed() ?? false);
        WlsLogger::info_(
            $waitingForAck
                ? "已上报就绪状态，等待 Master+Dispatcher 入池闭环 ACK（当前：等待中）"
                : "已上报就绪状态，Master ACK 确认结果：成功（控制面已同步确认 READY）"
        );
        $readySentTime = \microtime(true);
        if ((\Weline\Server\Log\LogConfig::isDevMode() || $isFrontend) && $ipcClient !== null) {
            WlsLogger::getInstance()->setIpcLogSink(static function (string $line, string $level, string $tag) use ($ipcClient): void {
                if ($ipcClient->isConnected()) {
                    $ipcClient->sendLogLine($line, $level, $tag);
                }
            });
        }
    } else {
        $wlsStartupTrace('ipc_connect_failed', ['control_port' => $controlPort]);
        // IPC 连接失败是严重问题，表明 Master 可能未正确启动
        // 不应该静默继续独立运行，而应该：
        // 1. 输出错误日志
        // 2. 标记重连标志，定期尝试重新连接 Master
        // 3. 如果超过阈值仍未连接，最终才允许独立运行
        WlsLogger::error_("[IPC] IPC 控制通道初始连接失败 (控制端口: {$controlPort})");
        WlsLogger::error_("[IPC] 可能原因: Master 未正确启动、IPC 服务故障或网络隔离");
        WlsLogger::warning_("[IPC] Worker 将标记为孤立模式，进入重连循环");
        $ipcClient = $kernel->getClient();
        $ipcReconnectAttempts = 0;
        $ipcReconnectMaxAttempts = 30;  // 最多重连30次（每次5秒业务循环 = 150秒）
        $ipcReconnectDueTime = \microtime(true) + 5.0;  // 5秒后第一次重连
        if ($masterPid > 0 && !\Weline\Framework\System\Process\Processer::isRunningByPid($masterPid)) {
            WlsLogger::error_("[IPC] Master PID {$masterPid} 已不存在，Worker 将在启动期孤儿保护中退出");
            $exitBecauseMasterMissingAtStartup = true;
        }
    }
}
// ========== IPC 控制通道结束 ==========

$connections = [];
$connectionPeerIps = [];
$requestCount = 0;
$activeRequests = 0; // 正在处理的请求数
$requestBuffers = [];
$connectionLastActivity = []; // 连接最后活动时间（用于超时清理）
$requestLogged = []; // 记录已输出日志的连接（前端模式使用）
$writeBuffers = [];
$writableConnections = [];
$pendingPeek = [];
$pendingPeekStartTimes = [];
$pendingHandshakes = [];
$postHandshakeReadPending = [];
$pendingClose = [];
$handshakeStartTimes = [];
/** SNI 解析失败或为空时，用当前监听主机再选证（避免默认 PEM 与浏览器访问主机不一致） */
$deferSslPreferredHost = \filter_var($host, \FILTER_VALIDATE_IP) ? '' : (string) $host;
$startTime = \time(); // 记录启动时间

// Keep-Alive 连接超时配置（秒）
$keepAliveTimeout = 60; // 默认 60 秒空闲超时
$connectionTimeoutCheckInterval = 5; // 每 5 秒检查一次超时连接
$lastTimeoutCheck = \time();
if (\defined('BP') && \is_file(BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php')) {
    $env = @include BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php';
    $env = \is_array($env) ? $env : [];
    $wls = \is_array($env['wls'] ?? null) ? $env['wls'] : [];
    $wlsServers = \is_array($wls['servers'] ?? null) ? $wls['servers'] : [];
    $wlsInstance = \is_array($wlsServers[$instanceName] ?? null) ? $wlsServers[$instanceName] : [];
    $configuredKeepAliveTimeout = $wlsInstance['keep_alive_timeout'] ?? $wls['keep_alive_timeout'] ?? null;
    if (\is_numeric($configuredKeepAliveTimeout)) {
        $configuredKeepAliveTimeout = (int)$configuredKeepAliveTimeout;
        if ($configuredKeepAliveTimeout > 0) {
            $keepAliveTimeout = $configuredKeepAliveTimeout;
        }
    }
}

// 重载日志输出函数
$logReload = function (string $method) use ($workerId, $instanceName) {
    $time = \date('Y-m-d H:i:s');
    // 根据方法类型显示不同消息
    if ($method === 'FLAG-CACHE' || $method === 'IPC-CACHE') {
        $message = "[{$time}] [WLS-SSL] Worker #{$workerId} ({$instanceName}) 已清理缓存（opcache + ObjectManager）[{$method}]";
    } else {
        $message = "[{$time}] [WLS-SSL] Worker #{$workerId} ({$instanceName}) 正在重载（优雅退出，由 Master 重启）[{$method}]";
    }
    w_log_info($message);
    // 前台模式时输出到控制台
    if (\defined('STDOUT') && \is_resource(STDOUT)) {
        \fwrite(STDOUT, "\033[33m{$message}\033[0m\n");
    }
};

// 是否需要优雅退出（重载时设置为 true）

// Worker 优雅退出函数
$gracefulExit = function (string $reason = '') use ($socket, &$connections, &$requestBuffers, &$connectionLastActivity, $processName, &$ipcClient, $workerId, $port, $isMaintenanceWorker, &$wlsWorkerGracefulExitReason) {
    $wlsWorkerGracefulExitReason = $reason !== '' ? $reason : 'graceful';
    // 刷新日志缓冲区
    WlsLogger::flush_(true);
    \Weline\Server\Service\AttackLogService::flushForShutdown();
    
    // 记录退出原因
    if ($reason) {
        w_log_info("[WLS-SSL Worker] 退出原因: {$reason}");
    }
    
    // 关闭所有连接（仅对有效 stream 调用 fclose，避免已关闭或无效 resource 导致 TypeError）
    foreach ($connections as $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            safeCloseStream($conn);
        }
    }
    if (\is_resource($socket) && \get_resource_type($socket) === 'stream') {
        @\fclose($socket);
        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
    }
    
    // 清理连接相关数据
    $connections = [];
    $requestBuffers = [];
    $connectionLastActivity = [];
    
    // 通知 Master 即将退出（先发送退出原因，再发送 exited）
    if ($ipcClient && $ipcClient->isConnected()) {
        $exitRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
        $exitReason = $reason !== '' ? $reason : 'graceful';
        @$ipcClient->send(\Weline\Server\IPC\ControlMessage::exitReason($exitReason, 0));
        $ipcClient->send(\Weline\Server\IPC\ControlMessage::exited($exitRole, \getmypid(), $port, $workerId));
        WlsLogger::info_("已发送 exit_reason + exited 消息给 Master");
    }
    
    // Master owns process-record cleanup; child exit must not block on shared
    // PID/name/port index locks.
    
    exit(0);
};

if ($exitBecauseMasterMissingAtStartup) {
    $gracefulExit('启动期孤儿检测：Master 已死亡');
}

// 信号处理（热更新支持，仅 Linux/Mac）
// 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
// Daemon 下向已关闭连接写数据会触发 SIGPIPE 导致进程退出，与 Nginx 一致忽略 SIGPIPE
if (\function_exists('pcntl_signal')) {
    if (\defined('SIGPIPE')) {
        \pcntl_signal(SIGPIPE, SIG_IGN);
    }
    \pcntl_signal(SIGINT, SIG_IGN);
    \pcntl_signal(SIGUSR1, function () use (&$shouldExit, &$ipcDraining, &$drainStartTime, &$socket, $logReload) {
        // 收到重载信号，标记优雅退出（Master 会重新启动新进程加载新代码）
        $shouldExit = true;
        $ipcDraining = true;
        $drainStartTime = \time();
        // 关闭监听 socket（不再接受新连接）
        if ($socket && \is_resource($socket)) {
            @\fclose($socket);
            $socket = null;
            \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
        }
        $logReload('SIGUSR1');
    });
    
    \pcntl_signal(SIGTERM, function () use ($gracefulExit) {
        $gracefulExit('收到 SIGTERM 信号');
    });
}

// Master 感知通过 IPC 控制通道（TCP 连接断开 = Master 死亡/重启，无需文件轮询）

// 连续错误计数器（Workerman 模式：避免单次错误导致进程退出）
$consecutiveErrors = 0;
$maxConsecutiveErrors = 100; // 连续 100 次错误才考虑重启（给予足够的恢复机会）

// 进入事件循环后向 Master 上报（略延迟，避免早于 register/ready 被 Master 处理）
$workerLoopStartedSent = false;
$workerLoopNotifyNotBefore = 0.0;
$eventLoopWaitTimeouts = 0;
$eventLoopLagWarnings = 0;
$eventLoopLastMetricsLogAt = \time();
$deferredWorkerBootstrapWarmupStarted = false;
$deferredWorkerBootstrapWarmupNotBefore = \microtime(true);
$sharedRuntimeConnectionWarmupStarted = $readyGateSharedRuntimeConnectionWarmupCompleted;
$sharedRuntimeConnectionWarmupNotBefore = \microtime(true);
$homepageKeepWarmFiber = null;
$attackLogNextFlushCheckAt = 0.0;
$darwinSharedAcceptCooldownEnabled = \PHP_OS_FAMILY === 'Darwin'
    && $sharedListenerBound
    && $coroutineRuntime->getLoopBackend() === 'event';
$darwinSharedAcceptBusyCooldownUsec = $darwinSharedAcceptCooldownEnabled
    ? \max(0, \min(1000, (int)($wlsSslConfig['darwin_shared_accept_busy_cooldown_usec'] ?? 500)))
    : 0;
$darwinSharedAcceptIdleCooldownUsec = $darwinSharedAcceptCooldownEnabled
    ? \max($darwinSharedAcceptBusyCooldownUsec, \min(
        5000,
        (int)($wlsSslConfig['darwin_shared_accept_idle_cooldown_usec'] ?? 5000)
    ))
    : 0;
$darwinSharedAcceptBusyHoldUsec = $darwinSharedAcceptCooldownEnabled
    ? \max(0, \min(100000, (int)($wlsSslConfig['darwin_shared_accept_busy_hold_usec'] ?? 20000)))
    : 0;
$darwinSharedAcceptCooldownUntilNs = 0;
$darwinSharedAcceptBusyUntilNs = 0;

// 事件循环（Workerman 模式：外层 try-catch 防止意外退出）
while (true) {
    try {
    wlsResetLongRunningExecutionLimit();
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }

    // Worker 主循环计数
    if (!isset($workerLoopCount)) {
        $workerLoopCount = 0;
    }
    $workerLoopCount++;
    $workerLoopHeartbeatNow = \microtime(true);
    if (
        \Weline\Server\Service\MainLoopUnblockedLogConfig::shouldEmit($workerLoopCount, $mainLoopUnblockedLogEvery)
        || \Weline\Server\Service\MainLoopUnblockedLogConfig::shouldEmitByInterval(
            $workerLoopHeartbeatNow,
            $lastMainLoopUnblockedLogAt,
            $mainLoopUnblockedLogIntervalSec
        )
    ) {
        $lastMainLoopUnblockedLogAt = $workerLoopHeartbeatNow;
        WlsLogger::info_("[Worker SSL] 主循环未被阻塞 #{$workerLoopCount}");
        // Preserve the legacy mojibake line in a dead branch to avoid risky re-encoding of this script.
        if (false) {
        WlsLogger::info_("[Worker SSL] 循环未被阻塞 #{$workerLoopCount} #{$workerLoopCount}");
        }
    }
    
    // 定期刷新日志缓冲区（避免日志堆积）
    WlsLogger::flush_(false);
    $workerTelemetryReporter->tick($ipcClient);
    if ($workerLoopHeartbeatNow >= $attackLogNextFlushCheckAt) {
        $attackLogNextFlushCheckAt = $workerLoopHeartbeatNow + 0.25;
        if ($activeRequests <= 0 && $writeBuffers === []) {
            \Weline\Server\Service\AttackLogService::flushIfDue();
        }
    }

    $connectionAcceptGates = \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull();
    if ($connectionAcceptGates !== null) {
        foreach ($connectionAcceptGates->sweep() as $directive) {
            $gateConnId = (int)$directive->connectionId;
            $gateConn = $connections[$gateConnId]
                ?? ($pendingHandshakes[$gateConnId]['conn'] ?? null)
                ?? ($pendingPeek[$gateConnId]['conn'] ?? null);
            if (\is_resource($gateConn)) {
                safeCloseStream($gateConn);
            }
            unset(
                $connections[$gateConnId],
                $pendingHandshakes[$gateConnId],
                $handshakeStartTimes[$gateConnId],
                $pendingPeek[$gateConnId],
                $pendingPeekStartTimes[$gateConnId],
                $requestBuffers[$gateConnId],
                $connectionLastActivity[$gateConnId],
                $requestLogged[$gateConnId],
                $connectionPeerIps[$gateConnId],
                $writeBuffers[$gateConnId],
                $writableConnections[$gateConnId],
                $pendingClose[$gateConnId]
            );
        }
        $connectionAcceptGates->reconcileMapsIfDue(
            $connections,
            $pendingHandshakes,
            $pendingPeek,
        );
    }

    $now = \time();

    // 注意：Worker 的主循环不进行连接池预热
    // 连接池将在首次需要时由请求 Fiber 按需初始化

    if ($childMasterGuard->shouldExit()) {
        WlsLogger::warning_('[Worker SSL] Master lease/PID 已失效，子进程自治退出: ' . $childMasterGuard->getLastExitReason());
        $gracefulExit('Master lease/PID 自治退出');
    }

    if (!$childMasterGuard->isEnabled() && $masterPid > 0 && ($now - $lastMasterPidHardCheck) >= 5) {
        $lastMasterPidHardCheck = $now;
        if (!\Weline\Framework\System\Process\Processer::isRunningByPid($masterPid)) {
            WlsLogger::warning_("Master PID {$masterPid} 已不存在，SSL Worker 自行退出");
            $gracefulExit('Master进程不存在');
        }
    }
    
    // ========== 孤儿检测（IPC 优先） ==========
    if (!$childMasterGuard->isEnabled() && $orphanGuard->shouldExit(
        $masterPid,
        $ipcClient && $ipcClient->isConnected(),
        $ipcReceivedShutdown,
        $ipcSelfTag ?? 'Worker'
    )) {
        WlsLogger::warning_("Master PID {$masterPid} 已死亡，Worker 自行退出（孤儿保护）");
        $gracefulExit('孤儿检测：Master 已死亡');
    }
    
    // ========== IPC 控制通道处理 ==========
    // 如果初始连接失败，定期尝试与 Master 重新连接（自愈机制）
    if (isset($ipcReconnectDueTime) && \microtime(true) >= $ipcReconnectDueTime && $ipcReconnectAttempts < $ipcReconnectMaxAttempts) {
        $ipcReconnectAttempts++;
        
        WlsLogger::warning_("[IPC] 第 {$ipcReconnectAttempts}/{$ipcReconnectMaxAttempts} 次尝试与 Master 重新连接 (端口: {$controlPort})");
        $runReadyGateWorkerBootstrapWarmup();
        if ($kernel->connectAndRegister($controlPort)) {
            $ipcClient = $kernel->getClient();
            unset($ipcReconnectDueTime, $ipcReconnectAttempts, $ipcReconnectMaxAttempts);
            $waitingForAck = !($ipcClient?->isReadyStateConfirmed() ?? false);
            WlsLogger::info_(
                $waitingForAck
                    ? "[IPC] 成功重新连接到 Master，已重新上报就绪状态，Master ACK 确认结果：等待中"
                    : "[IPC] 成功重新连接到 Master，已重新上报就绪状态，Master ACK 确认结果：成功"
            );
            $readySentTime = \microtime(true);
        } else {
            // 重连失败，设置下一次重连时间（指数退避：5秒 + attempt*1秒）
            $nextRetryDelay = 5 + \min($ipcReconnectAttempts, 10);  // 最多加10秒，即15秒
            $ipcReconnectDueTime = \microtime(true) + $nextRetryDelay;
        }
    }
    
    // 如果有 IPC 客户端且连接断开了，尝试重连
    if ($ipcClient && !$ipcClient->isConnected() && !$ipcReceivedShutdown) {
        $ipcClient->tryReconnect();
    }
    if ($ipcClient && !$ipcClient->isConnected()) {
        $workerLoopStartedSent = false;
        $workerLoopNotifyNotBefore = 0.0;
    }
    if ($waitingForAck && $ipcClient && $ipcClient->isConnected()) {
        $ackElapsed = \microtime(true) - $readySentTime;
        if ($ackElapsed >= $ackTimeout) {
            $ackRetryCount++;
            WlsLogger::warning_("Master ACK 确认结果：超时未确认（{$ackElapsed}s），第 {$ackRetryCount} 次重新发送 ready...");
            $ipcClient->sendReady($ipcRole, $workerId, $port, $orchestratorEpoch, $orchestratorLaunchId);
            $readySentTime = \microtime(true);
        }
    }
    if ($ipcClient && $ipcClient->isConnected() && !$waitingForAck && !$workerLoopStartedSent && !$ipcReceivedShutdown) {
        if ($workerLoopNotifyNotBefore <= 0.0) {
            $workerLoopNotifyNotBefore = \microtime(true) + 0.25;
        }
        if (\microtime(true) >= $workerLoopNotifyNotBefore) {
            $ipcClient->sendWorkerLoopStarted($workerId, $port, (int) \getmypid());
            $workerLoopStartedSent = true;
        }
    }
    if (!$sharedRuntimeConnectionWarmupStarted
        && !$isMaintenanceWorker
        && isset($sessionHost, $sessionPort, $memoryHost, $memoryPort)
        && $workerLoopStartedSent
        && !$ipcReceivedShutdown
        && \microtime(true) >= $sharedRuntimeConnectionWarmupNotBefore
    ) {
        $sharedRuntimeConnectionWarmupStarted = true;
        $fiberScheduler->registerFiber();
        $sharedRuntimeConnectionWarmupFiber = new \Fiber(static function () use (
            $workerId,
            $instanceName,
            $sessionHost,
            $sessionPort,
            $sessionTokenFileName,
            $memoryHost,
            $memoryPort,
            $memoryTokenFileName,
            $fiberScheduler
        ): void {
            try {
                WlsLogger::info_("[ConnectionPoolWarmup] async shared-state prewarm start worker={$workerId}");
                $stats = \Weline\Server\Service\SharedRuntimeConnectionWarmup::warmWorkerPools($workerId, $instanceName, [
                    'session' => [
                        'host' => $sessionHost,
                        'port' => $sessionPort,
                        'token_file_name' => $sessionTokenFileName,
                    ],
                    'memory' => [
                        'host' => $memoryHost,
                        'port' => $memoryPort,
                        'token_file_name' => $memoryTokenFileName,
                    ],
                ]);
                WlsLogger::info_('[ConnectionPoolWarmup] async shared-state prewarm done worker=' . $workerId . ' stats=' . \json_encode($stats, JSON_UNESCAPED_SLASHES));
            } catch (\Throwable $e) {
                WlsLogger::warning_("[ConnectionPoolWarmup] async shared-state prewarm failed worker={$workerId}: " . $e->getMessage());
            } finally {
                $fiberScheduler->unregisterFiber();
            }
        });
        try {
            $sharedRuntimeConnectionWarmupFiber->start();
        } catch (\Throwable $e) {
            $fiberScheduler->unregisterFiber();
            WlsLogger::warning_("[ConnectionPoolWarmup] async shared-state prewarm start failed worker={$workerId}: " . $e->getMessage());
        }
    }
    if (!$deferredWorkerBootstrapWarmupStarted
        && $runtime instanceof \Weline\Framework\Runtime\WlsRuntime
        && $workerLoopStartedSent
        && !$ipcReceivedShutdown
        && \microtime(true) >= $deferredWorkerBootstrapWarmupNotBefore
    ) {
        $deferredWorkerBootstrapWarmupStarted = true;
        $warmupIpcClient = $ipcClient;
        $fiberScheduler->registerFiber();
        $deferredWarmupFiber = new \Fiber(static function () use ($runtime, $workerId, $fiberScheduler, $warmupIpcClient): void {
            $warmupLog = static function (string $message, string $level = 'INFO') use ($workerId, $warmupIpcClient): void {
                if ($warmupIpcClient !== null && $warmupIpcClient->isConnected()) {
                    $warmupIpcClient->sendLogLine("[WorkerWarmup] Worker{$workerId} {$message}" . PHP_EOL, $level, "Worker#{$workerId}");
                }
            };
            try {
                $warmupLog('warmup_started');
                WlsLogger::info_("[WorkerWarmup] deferred bootstrap warmup start worker={$workerId}");
                $runtime->runDeferredWorkerBootstrapWarmup();
                WlsLogger::info_("[WorkerWarmup] deferred bootstrap warmup done worker={$workerId}");
                $warmupLog('warmup_success');
            } catch (\Throwable $e) {
                WlsLogger::warning_("[WorkerWarmup] deferred bootstrap warmup failed worker={$workerId}: " . $e->getMessage());
                $warmupLog('warmup_failed', 'WARNING');
            } finally {
                $fiberScheduler->unregisterFiber();
            }
        });
        try {
            $deferredWarmupFiber->start();
        } catch (\Throwable $e) {
            $fiberScheduler->unregisterFiber();
            WlsLogger::warning_("[WorkerWarmup] deferred bootstrap warmup start failed worker={$workerId}: " . $e->getMessage());
        }
    }

    // ========== Homepage keep-warm (idle, low priority) ==========
    $homepageMemoryPressure = $maxMemoryBytes > 0
        && \memory_get_usage(true) >= (int)($maxMemoryBytes * 0.70);
    if ($runtime instanceof \Weline\Framework\Runtime\WlsRuntime
        && $workerLoopStartedSent
        && !$isMaintenanceWorker
        && !$ipcReceivedShutdown
        && empty($pendingHandshakes)
        && \Weline\Server\Service\Policy\WorkerPolicyControl::isApplicationGateOpen()
        && !wlsWorkerHasPendingRequestWork($activeRequests, $requestBuffers, $writeBuffers, null)
        && $runtime->shouldScheduleHomepageKeepWarm($activeRequests, $ipcDraining, $homepageMemoryPressure)
    ) {
        $fiberScheduler->registerFiber();
        $homepageKeepWarmFiber = new \Fiber(static function () use ($runtime, $fiberScheduler): void {
            try {
                $runtime->runHomepageKeepWarmCycle();
            } finally {
                $fiberScheduler->unregisterFiber();
            }
        });
        try {
            $homepageKeepWarmFiber->start();
        } catch (\Throwable $e) {
            $fiberScheduler->unregisterFiber();
            WlsLogger::warning_('[WorkerWarmup] homepage keep-warm start failed: ' . $e->getMessage());
        }
    }

    // Maintenance mode is already applied before this barrier. Preconnects,
    // incomplete TLS handshakes and partial HTTP input are not dispatched work;
    // when they later complete they observe the new maintenance policy. Waiting
    // for them lets a browser preconnect/slowloris block restart for seconds.
    $maintenanceRequestWorkDrained = $activeFibers === [] && $activeRequests === 0;
    if ($maintenanceRequestWorkDrained) {
        foreach ($writeBuffers as $maintenanceWriteBuffer) {
            if (\is_string($maintenanceWriteBuffer) && $maintenanceWriteBuffer !== '') {
                $maintenanceRequestWorkDrained = false;
                break;
            }
        }
    }
    $maintenanceAckRequestId = $maintenanceDrainState->nextAcknowledgement($maintenanceRequestWorkDrained);
    if ($maintenanceAckRequestId !== null
        && $ipcClient !== null
        && $ipcClient->isConnected()
        && $ipcClient->send(\Weline\Server\IPC\ControlMessage::encode([
            'type' => \Weline\Server\IPC\ControlMessage::TYPE_MAINTENANCE_MODE_ACK,
            'request_id' => $maintenanceAckRequestId,
            'worker_id' => $workerId,
        ]))
    ) {
        $maintenanceDrainState->markAcknowledged($maintenanceAckRequestId);
        WlsLogger::info_(
            '维护排水已完成，已上报 Master ACK request_id=' . $maintenanceAckRequestId
        );
    }

    // 检查是否需要优雅退出（排水模式）
    if ($shouldExit) {
        if ($ipcDraining) {
            if (!empty($longLivedConnections)) {
                foreach (\array_keys($longLivedConnections) as $cid) {
                    if (isset($connections[$cid]) && \is_resource($connections[$cid])) {
                        safeCloseStream($connections[$cid]);
                    }
                    if (isset($activeFibers[$cid])) {
                        $fiberScheduler->cancelTimersForFiber($activeFibers[$cid]['fiber']);
                        \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($activeFibers[$cid]['fiber']);
                        $fiberScheduler->unregisterFiber();
                    }
                    unset(
                        $connections[$cid],
                        $requestBuffers[$cid],
                        $connectionLastActivity[$cid],
                        $requestLogged[$cid],
                        $writeBuffers[$cid],
                        $writableConnections[$cid],
                        $pendingClose[$cid],
                        $longLivedConnections[$cid],
                        $activeFibers[$cid]
                    );
                }
            }
            // 已 accept/已握手但 HTTP 首字节尚未到达的 fresh TLS 连接，
            // 与空闲 Keep-Alive 在缓冲层看起来相同。排水时不能立即
            // close 这些连接，否则客户端会在 reload 窗口收到 RST。
            // 完成响应的连接会因 ipcDraining 自动关闭，其余交给
            // 下方的总 drain deadline。

            $drainElapsed = $drainStartTime > 0 ? (\time() - $drainStartTime) : 0;
            
            // 1. 所有连接已清空 → 排水完成（帝王令：若已收 shutdown，做完排水仍以 shutdown 名义退出）
            if (empty($connections)
                && empty($pendingPeek)
                && empty($pendingHandshakes)
                && empty($postHandshakeReadPending)
                && empty($activeFibers)
                && empty($writeBuffers)) {
                if ($ipcClient && $ipcClient->isConnected()) {
                    $sslDrainReason = $ipcReceivedShutdown
                        ? "shutdown_command:worker={$workerId}"
                        : "drain_or_reload:worker={$workerId}";
                    $ipcClient->sendDrainingComplete($workerId, $port, '', $sslDrainReason);
                    $ipcClient->flushPendingWrites(0.2);
                }
                WlsLogger::info_("排水完成（{$drainElapsed}秒），Worker 退出");
                $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载');
            }
            
            // 2. 排水超时 → 强制关闭所有剩余连接
            if ($drainElapsed >= $maxDrainTime) {
                $remaining = \count($connections)
                    + \count($pendingPeek)
                    + \count($pendingHandshakes)
                    + \count($postHandshakeReadPending);
                WlsLogger::warning_("排水超时（{$drainElapsed}秒 >= {$maxDrainTime}秒），强制关闭剩余 {$remaining} 个连接");
                foreach ($connections as $cid => $cconn) {
                    @\fclose($cconn);
                }
                foreach ($pendingHandshakes as $cid => $hsInfo) {
                    if (\is_resource($hsInfo['conn'])) {
                        @\fclose($hsInfo['conn']);
                    }
                }
                foreach ($pendingPeek as $cid => $peekInfo) {
                    if (\is_resource($peekInfo['conn'] ?? null)) {
                        @\fclose($peekInfo['conn']);
                    }
                }
                $connections = [];
                $pendingPeek = [];
                $pendingPeekStartTimes = [];
                $pendingHandshakes = [];
                $requestBuffers = [];
                $connectionLastActivity = [];
                $requestLogged = [];
                $writeBuffers = [];
                $writableConnections = [];
                $pendingClose = [];
                $handshakeStartTimes = [];
                $postHandshakeReadPending = [];
                $connectionPeerIps = [];
                
                if ($ipcClient && $ipcClient->isConnected()) {
                    $sslDrainTimeoutReason = $ipcReceivedShutdown
                        ? "shutdown_command_timeout:worker={$workerId},remaining={$remaining}"
                        : "drain_or_reload_timeout:worker={$workerId},remaining={$remaining}";
                    $ipcClient->sendDrainingComplete($workerId, $port, '', $sslDrainTimeoutReason);
                    $ipcClient->flushPendingWrites(0.2);
                }
                $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载（超时强制退出）');
            }
        } elseif (empty($connections)
            && empty($pendingPeek)
            && empty($pendingHandshakes)
            && empty($postHandshakeReadPending)) {
            // 非排水模式退出（如 shutdown 命令）
            $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载');
        }
    }
    
    // Keep-Alive 连接超时清理（定期检查并关闭空闲连接）
    if ($now - $lastTimeoutCheck >= $connectionTimeoutCheckInterval) {
        $lastTimeoutCheck = $now;
        foreach ($connections as $connId => $conn) {
            $lastActivity = $connectionLastActivity[$connId] ?? $now;
            $idleTime = $now - $lastActivity;
            
            // 如果连接空闲时间超过超时时间，关闭连接
            if ($idleTime >= $keepAliveTimeout) {
                // 如果缓冲区有数据，说明还在发送响应，不能关闭连接
                $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';
                if ($hasBufferedData) {
                    // 缓冲区有数据，跳过关闭，等待数据发送完成
                    // 但更新超时时间，避免无限等待
                    if ($idleTime >= $keepAliveTimeout * 3) {
                        // 超过 3 倍超时时间仍未发送完成，强制关闭（防止僵尸连接）
                        WlsLogger::warning_("连接超时且缓冲区有数据，强制关闭 (connId: {$connId}, 剩余: " . \strlen($writeBuffers[$connId]) . " 字节)");
                        if (\is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
                            safeCloseStream($conn);
                        }
                        unset($connections[$connId]);
                        unset($requestBuffers[$connId]);
                        unset($connectionLastActivity[$connId]);
                        unset($requestLogged[$connId]);
                        unset($writeBuffers[$connId]);
                        unset($writableConnections[$connId]);
                        unset($pendingClose[$connId]);
                        if (isset($longLivedConnections[$connId])) {
                            unset($longLivedConnections[$connId]);
                        }
                        if (isset($activeFibers[$connId])) {
                            $fiberScheduler->cancelTimersForFiber($activeFibers[$connId]['fiber']);
                            \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($activeFibers[$connId]['fiber']);
                            $fiberScheduler->unregisterFiber();
                            unset($activeFibers[$connId]);
                        }
                    }
                    continue; // 跳过正常超时关闭
                }

                if (\is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
                    safeCloseStream($conn);
                }
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
                // 清理写缓冲区相关状态（虽然此时应该为空）
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);
                unset($pendingClose[$connId]);
                if (isset($longLivedConnections[$connId])) {
                    unset($longLivedConnections[$connId]);
                }
                if (isset($activeFibers[$connId])) {
                    $fiberScheduler->cancelTimersForFiber($activeFibers[$connId]['fiber']);
                    \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($activeFibers[$connId]['fiber']);
                    $fiberScheduler->unregisterFiber();
                    unset($activeFibers[$connId]);
                }
            }
        }
        
        // 定期记录 Worker 状态到数据库
        try {
            \Weline\Server\Service\StatusLogService::logWorkerStatus([
                'instance' => $instanceName,
                'worker_id' => $workerId,
                'port' => $port,
                'pid' => \getmypid(),
                'connections' => \count($connections),
                'active_requests' => $activeRequests,
                'total_requests' => $requestCount,
                'memory_usage' => \memory_get_usage(true),
                'memory_peak' => \memory_get_peak_usage(true),
                'uptime' => $now - $startTime,
                'ssl' => true,
            ]);
        } catch (\Throwable $e) {
            // 忽略日志记录失败
        }
    }
    
    if ($now - $lastMemoryCheck >= $memoryCheckInterval) {
        $lastMemoryCheck = $now;
        $currentMemory = \memory_get_usage(true);
        $currentMemoryUsed = \memory_get_usage(false);
        $memoryPercent = $maxMemoryBytes > 0 ? $currentMemoryUsed / $maxMemoryBytes : 0.0;

        if ($memoryPercent >= $memoryDrainThreshold) {
            $beforeMb = \round($currentMemoryUsed / 1024 / 1024, 1);
            $beforeAllocatedMb = \round($currentMemory / 1024 / 1024, 1);
            $compaction = wlsCompactWorkerMemoryCaches('ssl_drain_threshold', $maxMemoryBytes, 0.0, 0, true);
            $currentMemory = \memory_get_usage(true);
            $currentMemoryUsed = \memory_get_usage(false);
            $memoryPercent = $maxMemoryBytes > 0 ? $currentMemoryUsed / $maxMemoryBytes : 0.0;
            $afterMb = \round($currentMemoryUsed / 1024 / 1024, 1);
            $afterAllocatedMb = \round($currentMemory / 1024 / 1024, 1);

            if ($memoryPercent >= $memoryDrainThreshold) {
                WlsLogger::warning_(
                    "SSL Worker memory pressure {$afterMb}MB used ({$afterAllocatedMb}MB allocated) after compact "
                    . "(before={$beforeMb}MB used, before_allocated={$beforeAllocatedMb}MB), start drain to avoid OOM reset"
                );
                $shouldExit = true;
                $ipcDraining = true;
                $drainStartTime = \time();
                $maxDrainTime = \min($maxDrainTime, 10);
                if ($socket && \is_resource($socket)) {
                    @\fclose($socket);
                    $socket = null;
                    \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                }
            } elseif ($memoryPercent >= $memoryWarningThreshold) {
                WlsLogger::warning_(
                    "SSL Worker memory high {$afterMb}MB used ({$afterAllocatedMb}MB allocated) after compact "
                    . "(before={$beforeMb}MB used, before_allocated={$beforeAllocatedMb}MB, cycles="
                    . (int)($compaction['cycles'] ?? 0) . ")"
                );
            }
        } elseif ($memoryPercent >= $memoryWarningThreshold) {
            WlsLogger::warning_(
                "SSL Worker memory high: " . \round($currentMemoryUsed / 1024 / 1024, 1)
                . 'MB used (' . \round($currentMemory / 1024 / 1024, 1) . 'MB allocated)'
            );
        }
    }

    $pendingPeekConns = [];
    foreach ($pendingPeek as $connId => $info) {
        if (\is_resource($info['conn']) && \get_resource_type($info['conn']) === 'stream') {
            $pendingPeekConns[$connId] = $info['conn'];
        } else {
            unset($pendingPeek[$connId]);
            unset($pendingPeekStartTimes[$connId]);
        }
    }
    
    // 同时验证所有资源是否仍然有效（防止 stream_select 错误）
    $pendingConns = [];
    foreach ($pendingHandshakes as $connId => $info) {
        if (\is_resource($info['conn']) && \get_resource_type($info['conn']) === 'stream') {
            $pendingConns[$connId] = $info['conn'];
        } else {
            // 资源已无效，标记为需要清理
            unset($pendingHandshakes[$connId]);
            unset($handshakeStartTimes[$connId]);
        }
    }
    
    // 验证 $connections 中的资源
    $validConnections = [];
    foreach ($connections as $connId => $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            $validConnections[$connId] = $conn;
        } else {
            // 资源已无效，清理
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
            unset($writeBuffers[$connId]);
            unset($writableConnections[$connId]);
            unset($pendingClose[$connId]);
            unset($longLivedConnections[$connId]);
            if (isset($activeFibers[$connId])) {
                $fiberScheduler->cancelTimersForFiber($activeFibers[$connId]['fiber']);
                \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($activeFibers[$connId]['fiber']);
                $fiberScheduler->unregisterFiber();
                unset($activeFibers[$connId]);
            }
        }
    }
    
    // 验证 $writableConnections 中的资源
    $validWritableConnections = [];
    foreach ($writableConnections as $connId => $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            $validWritableConnections[$connId] = $conn;
        } else {
            unset($writableConnections[$connId]);
            unset($writeBuffers[$connId]);
        }
    }
    
    // 构建 stream_select 读数组
    $readSockets = [];
    $applicationAdmissionOpen = !$waitingForAck
        && \Weline\Server\Service\Policy\WorkerPolicyControl::isApplicationGateOpen();
    $darwinSharedAcceptCooldownRemainingUsec = 0;
    if ($darwinSharedAcceptCooldownEnabled) {
        $acceptNowNs = \hrtime(true);
        if ($darwinSharedAcceptCooldownUntilNs > $acceptNowNs) {
            $darwinSharedAcceptCooldownRemainingUsec = (int)\ceil(
                ($darwinSharedAcceptCooldownUntilNs - $acceptNowNs) / 1000
            );
        }
    }
    // Keep the shared listener registered in EventExtLoop for the full Worker
    // lifetime. Removing it during every Darwin fairness cooldown makes the
    // event backend del/free and recreate the watcher; a failed re-add leaves a
    // live Worker permanently unable to accept connections. The cooldown is
    // applied to this loop's ready result immediately before accept instead.
    if ($socket && \is_resource($socket)) {
        $readSockets[] = $socket; // 监听 socket（排水后已关闭则不加入）
    }
    $validConnectionsReadable = [];
    foreach ($validConnections as $connIdReadable => $connReadable) {
        if ($applicationAdmissionOpen && !isset($longLivedConnections[$connIdReadable])) {
            $validConnectionsReadable[$connIdReadable] = $connReadable;
        }
    }
    $readSockets = \array_merge($readSockets, $validConnectionsReadable, $pendingConns, $pendingPeekConns);
    
    // 加入 IPC 控制 socket
    $ipcSocket = ($ipcClient && $ipcClient->isConnected()) ? $ipcClient->getSocket() : null;
    if ($ipcSocket && \is_resource($ipcSocket)) {
        $readSockets[] = $ipcSocket;
    }
    
    $read = $readSockets;
    // SSL 握手需要双向通信，将 pendingHandshakes 也加入写数组
    $write = \array_merge($validWritableConnections, $pendingConns);
    if ($ipcSocket && $ipcClient && $ipcClient->hasPendingWrites()) {
        $write[] = $ipcSocket;
    }
    $except = [];
    
    // EventLoop + CoroutineRuntime：统一等待语义（select/event 后端可切换）
    $loopWaitUsec = $sslIdleSelectTimeoutUsec;
    if ($validConnectionsReadable !== []
        || $pendingConns !== []
        || $pendingPeekConns !== []
        || $validWritableConnections !== []
        || ($ipcSocket && $ipcClient && $ipcClient->hasPendingWrites())) {
        $loopWaitUsec = 1000;
    }
    if ($darwinSharedAcceptCooldownRemainingUsec > 0) {
        $loopWaitUsec = \min($loopWaitUsec, $darwinSharedAcceptCooldownRemainingUsec);
    }

    $waitStartedAt = \microtime(true);
    $changed = $coroutineRuntime->wait($read, $write, $except, $loopWaitUsec);
    $waitElapsedMs = (\microtime(true) - $waitStartedAt) * 1000;
    if ($waitElapsedMs >= 500) {
        $eventLoopLagWarnings++;
        WlsLogger::warning_(
            'EventLoop wait 慢调用 backend=' . $coroutineRuntime->getLoopBackend()
            . ' elapsed_ms=' . \round($waitElapsedMs, 2)
        );
    }
    if ($changed === 0) {
        $eventLoopWaitTimeouts++;
    }

    // 先 tick，避免 sleep/usleep 挂起的 Fiber 饿死
    $fiberScheduler->tick(
        function (\Fiber $fiber) use (&$activeFibers): void {
            \Weline\Server\Runtime\WorkerFiberContextTracker::restore($activeFibers, $fiber);
        },
        $fiberTickBudgetMs > 0.0 ? $fiberTickBudgetMs : null,
        function (\Fiber $fiber) use (&$activeFibers): void {
            $activeFibers = \Weline\Server\Runtime\WorkerFiberContextTracker::capture(
                $activeFibers,
                $fiber,
                static fn () => \Weline\Framework\Runtime\WlsFiberContext::capture()
            );
            wlsResetLongRunningExecutionLimit();
        }
    );
    foreach ($activeFibers as $afConnId => $afData) {
        $af = $afData['fiber'] ?? null;
        if (!($af instanceof \Fiber)) {
            unset($activeFibers[$afConnId]);
            continue;
        }
        if ($af->isTerminated()) {
            if (isset($afData['context'])) {
                // Fiber 终止时恢复其上下文（不恢复响应状态，因为响应已发送）
                $afData['context']->restore(false);
            }
            $afResponse = '';
            try {
                $afResponse = (string) ($af->getReturn() ?? '');
            } catch (\Throwable) {
            } finally {
                \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($af);
            }
            $fiberScheduler->unregisterFiber();
            $afDurationMs = (\microtime(true) - (float) ($afData['handleStartTime'] ?? \microtime(true))) * 1000;
            $afResponse = injectWlsProcessTimeHeader($afResponse, $afDurationMs);
            $afIsSse = (bool) ($afData['is_sse_protocol'] ?? false);
            if (isset($connections[$afConnId]) && \is_resource($afData['conn'] ?? null)) {
                sslFinalizeHttpResponseAfterHandle(
                    $afData['conn'],
                    $afConnId,
                    (string) ($afData['rawRequest'] ?? ''),
                    $afResponse,
                    (float) ($afData['handleStartTime'] ?? \microtime(true)),
                    $afIsSse,
                    $ipcDraining,
                    $connections,
                    $requestBuffers,
                    $connectionLastActivity,
                    $requestLogged,
                    $writeBuffers,
                    $writableConnections,
                    $pendingClose,
                    $longLivedConnections,
                    $ipcClient,
                    $instanceName,
                    $activeRequests
                );
                wlsDrainAfterResponseIfRequested($socket, $shouldExit, $ipcDraining, $drainStartTime, $maxDrainTime);
            } else {
                $activeRequests = \max(0, $activeRequests - 1);
                \Weline\Framework\Http\Sse\SseContext::reset();
            }
            unset($activeFibers[$afConnId]);
            continue;
        }
        if ($af->isSuspended()) {
            $activeFibers[$afConnId] = $afData;
        }
    }

    \Weline\Server\Runtime\WorkerFiberSnapshot::setSnapshot(\Weline\Server\Runtime\WorkerFiberHealthSnapshot::build($activeFibers));

    $nowFiberCheck = \time();
    $idleCheckIntervalSsl = 5;
    $doReleaseIdleSsl = $fiberReleaseIdleRequested
        || ($fiberIdleTtlSec > 0 && $nowFiberCheck - $lastFiberIdleCheck >= $idleCheckIntervalSsl);
    if ($doReleaseIdleSsl && $activeFibers !== []) {
        $lastFiberIdleCheck = $nowFiberCheck;
        $fiberReleaseIdleRequested = false;
        $releaseThresholdSsl = $fiberIdleTtlSec > 0 ? $fiberIdleTtlSec : 0;
        $toReleaseSsl = [];
        $fiberHeartbeatTimeoutSsl = 60;
        if (isset($envConfig['wls']['fiber']['heartbeat_timeout'])) {
            $fiberHeartbeatTimeoutSsl = (int) $envConfig['wls']['fiber']['heartbeat_timeout'];
        }
        foreach ($activeFibers as $afConnIdSsl => $afDataSsl) {
            $suspendedAtSsl = $afDataSsl['suspended_at'] ?? $nowFiberCheck;
            $lastActivitySsl = $afDataSsl['last_activity'] ?? $afDataSsl['handleStartTime'] ?? $nowFiberCheck;
            $inactiveTimeSsl = $nowFiberCheck - $lastActivitySsl;
            $isLongLivedAfSsl = $afDataSsl['is_long_lived'] ?? false;
            // 长连接（SSE 等）不参与心跳超时检查，由客户端/服务端正常断开管理其生命周期
            if (!$isLongLivedAfSsl && $fiberHeartbeatTimeoutSsl > 0 && $inactiveTimeSsl >= $fiberHeartbeatTimeoutSsl) {
                WlsLogger::warning_(
                    "Fiber 心跳超时: connId={$afConnIdSsl} inactive_time={$inactiveTimeSsl}s (超过 {$fiberHeartbeatTimeoutSsl}s 未续约)"
                );
                $toReleaseSsl[$afConnIdSsl] = $afDataSsl;
                continue;
            }
            // 非长连接的闲置回收
            if (!$isLongLivedAfSsl && $releaseThresholdSsl > 0 && ($nowFiberCheck - $suspendedAtSsl) >= $releaseThresholdSsl) {
                $toReleaseSsl[$afConnIdSsl] = $afDataSsl;
            }
        }
        foreach ($toReleaseSsl as $afConnIdSsl => $afDataSsl) {
            $fiberScheduler->cancelTimersForFiber($afDataSsl['fiber']);
            \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($afDataSsl['fiber']);
            if (isset($afDataSsl['conn']) && \is_resource($afDataSsl['conn'])) {
                @\fclose($afDataSsl['conn']);
            }
            unset(
                $connections[$afConnIdSsl],
                $requestBuffers[$afConnIdSsl],
                $connectionLastActivity[$afConnIdSsl],
                $requestLogged[$afConnIdSsl],
                $writeBuffers[$afConnIdSsl],
                $writableConnections[$afConnIdSsl],
                $pendingClose[$afConnIdSsl]
            );
            unset($activeFibers[$afConnIdSsl]);
            if (isset($longLivedConnections[$afConnIdSsl])) {
                unset($longLivedConnections[$afConnIdSsl]);
            }
            $activeRequests = \max(0, $activeRequests - 1);
            $fiberScheduler->unregisterFiber();
        }
        $releasedSsl = \count($toReleaseSsl);
        if ($releasedSsl > 0) {
            WlsLogger::info_("Fiber 池释放闲置: {$releasedSsl} 个 (connIds 已关闭)");
        }
    }

    if ($changed === false) {
        // EventLoop wait 失败，可能是资源问题，记录错误但继续
        $error = \error_get_last();
        WlsLogger::warning_("EventLoop wait 失败: " . ($error['message'] ?? 'unknown'));
        continue;
    }

    if (($now - $eventLoopLastMetricsLogAt) >= 30) {
        $eventLoopLastMetricsLogAt = $now;
        WlsLogger::info_(
            'EventLoop metrics backend=' . $coroutineRuntime->getLoopBackend()
            . ' active_fibers=' . \count($activeFibers)
            . ' wait_timeouts=' . $eventLoopWaitTimeouts
            . ' lag_warnings=' . $eventLoopLagWarnings
        );
    }

    // Fiber tick may enqueue SSE/static response bytes; drain writable
    // responses before reading more request data to avoid response head blocking.
    wlsSslFlushQueuedWrites(
        $activeRequests,
        $writableConnections,
        $writeBuffers,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $pendingClose,
        $longLivedConnections
    );

    // 处理 IPC 控制通道消息
    if ($ipcSocket && \in_array($ipcSocket, $read, true)) {
        if ($ipcClient) {
            $ipcClient->handleReadable();
        }
    }
    if ($ipcSocket && \in_array($ipcSocket, $write, true) && $ipcClient) {
        $ipcClient->handleWritable();
    }
    if ($ipcClient !== null && $ipcClient->isConnected()) {
        $policyTrackedFibers = \count($activeFibers);
        foreach ([$homepageKeepWarmFiber, $deferredWarmupFiber ?? null, $sharedRuntimeConnectionWarmupFiber ?? null] as $backgroundFiber) {
            if ($backgroundFiber instanceof \Fiber && !$backgroundFiber->isTerminated()) {
                $policyTrackedFibers++;
            }
        }
        $policyDrainReply = \Weline\Server\Service\Policy\WorkerPolicyControl::pollAfterApplicationDrain(
            $activeRequests,
            $policyTrackedFibers,
            \count($writeBuffers)
        );
        if ($policyDrainReply !== null) {
            $ipcClient->send($policyDrainReply);
        }
    }
    $applicationAdmissionOpen = !$waitingForAck
        && \Weline\Server\Service\Policy\WorkerPolicyControl::isApplicationGateOpen();
    
    // 处理连接
    // Advance accepts and TLS handshakes before starting request fibers. A cold
    // render can run synchronously until its first cooperative yield; if it
    // starts first, pending clients observe that stall as appconnect latency.
    // Preserve the persistent listener watcher while suppressing accept for
    // this one fairness window. A readable listener may wake the loop early;
    // the bounded timeout above guarantees the next iteration observes expiry.
    if ($darwinSharedAcceptCooldownEnabled
        && $darwinSharedAcceptCooldownUntilNs > \hrtime(true)
        && $socket
        && \is_resource($socket)
    ) {
        $listenerReadyKey = \array_search($socket, $read, true);
        if ($listenerReadyKey !== false) {
            unset($read[$listenerReadyKey]);
        }
    }

    $admittedConnections = wlsSslAcceptNewConnections(
        $socket,
        $read,
        $deferSsl,
        $pendingPeek,
        $pendingPeekStartTimes,
        \count($pendingHandshakes),
        $sslHandshakeQueueHighWatermark,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $hotPathLogsEnabled,
        $connectionPeerIps,
        $sharedListenerBound ? 1 : 64,
        $applicationAdmissionOpen,
        $sharedListenerSocket,
    );
    if ($darwinSharedAcceptCooldownEnabled && $admittedConnections > 0) {
        $acceptNowNs = \hrtime(true);
        if (wlsSslListenerHasPendingAccept($socket)) {
            $darwinSharedAcceptBusyUntilNs = $acceptNowNs
                + ($darwinSharedAcceptBusyHoldUsec * 1000);
        }
        $acceptCooldownUsec = $darwinSharedAcceptBusyUntilNs > $acceptNowNs
            ? $darwinSharedAcceptBusyCooldownUsec
            : $darwinSharedAcceptIdleCooldownUsec;
        $darwinSharedAcceptCooldownUntilNs = $acceptNowNs
            + ($acceptCooldownUsec * 1000);
    }

    wlsSslAdvancePeekState(
        $pendingPeek,
        $pendingPeekStartTimes,
        $pendingHandshakes,
        $handshakeStartTimes,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $postHandshakeReadPending,
        $read,
        $deferSsl ? $deferSslOptions : null,
        $cryptoMethod,
        $sslHandshakeMaxAdvancePerLoop,
        $sslHandshakeQueueHighWatermark,
        $hotPathLogsEnabled,
        $sniServerCerts,
        $sslCert,
        $sslKey,
        $port,
        $deferSslPreferredHost,
        $connectionPeerIps,
        $wlsRuntimeTopology,
        $masterToken
    );

    wlsSslAdvanceHandshakeState(
        $pendingHandshakes,
        $handshakeStartTimes,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $postHandshakeReadPending,
        $read,
        $write,
        $changed,
        $cryptoMethod,
        $sslHandshakeMaxAdvancePerLoop,
        $hotPathLogsEnabled
    );

    // ext-event observes kernel readiness only. OpenSSL may already hold the
    // first HTTP bytes in its user-space buffer after TLS completes, so newly
    // handshaken streams get a short bounded first-read pump. Ordinary
    // keep-alive connections never enter this map and pay no scan cost.
    $postHandshakeReadNow = \microtime(true);
    foreach ($postHandshakeReadPending as $postHandshakeConnId => $postHandshakeState) {
        $postHandshakeConn = $postHandshakeState['conn'] ?? null;
        if (($postHandshakeState['deadline'] ?? 0.0) < $postHandshakeReadNow
            || !\is_resource($postHandshakeConn)
            || !isset($connections[$postHandshakeConnId])
        ) {
            unset($postHandshakeReadPending[$postHandshakeConnId]);
            continue;
        }
        if (!\in_array($postHandshakeConn, $read, true)) {
            $read[] = $postHandshakeConn;
        }
    }

    // Preserve HTTP/1.1 pipelining across OpenSSL reads. When the previous
    // response has drained, a complete/error frame already held in PHP memory
    // must run without waiting for another kernel or ext-event readable edge.
    foreach ($requestBuffers as $bufferedConnId => $bufferedRequest) {
        if (!\is_string($bufferedRequest)
            || $bufferedRequest === ''
            || !isset($connections[$bufferedConnId])
            || isset($pendingPeek[$bufferedConnId])
            || isset($pendingHandshakes[$bufferedConnId])
            || isset($activeFibers[$bufferedConnId])
            || ($writeBuffers[$bufferedConnId] ?? '') !== ''
            || isset($pendingClose[$bufferedConnId])
        ) {
            continue;
        }
        $bufferedFrame = wlsParseHttpRequestFrame(
            $bufferedRequest,
            $maxRequestHeaderBytes,
            $maxRequestBodyBytes,
        );
        if (($bufferedFrame['status'] ?? '') !== 'incomplete'
            && !\in_array($connections[$bufferedConnId], $read, true)
        ) {
            $read[] = $connections[$bufferedConnId];
        }
    }

    foreach ($read as $conn) {
        $connId = \get_resource_id($conn);

        if (isset($pendingPeek[$connId])) {
            continue;
        }
        if (!$applicationAdmissionOpen && isset($connections[$connId])) {
            continue;
        }
        if (\Weline\Server\Service\ConnectionReadWriteGuard::shouldDeferRead(
            $writeBuffers,
            $pendingClose,
            $connId,
            isset($activeFibers[$connId])
        )) {
            continue;
        }

        // 注释掉 pendingHandshakes 检查
        /*
        if (isset($pendingHandshakes[$connId])) {
            continue;
        }

        // 跳过本轮刚完成握手的连接，等待下一轮再读取数据
        if (isset($justCompletedHandshakes[$connId])) {
            continue;
        }
        */

        if (!isset($connections[$connId])) {
            continue;
        }
        
        $bufferedFrame = null;
        if (isset($connectionPeerIps[$connId]) && ($requestBuffers[$connId] ?? '') !== '') {
            $bufferedFrame = wlsParseHttpRequestFrame(
                $requestBuffers[$connId],
                $maxRequestHeaderBytes,
                $maxRequestBodyBytes,
            );
        }

        $data = '';
        if (!\is_array($bufferedFrame) || ($bufferedFrame['status'] ?? '') === 'incomplete') {
            $data = @\fread($conn, 65535);
        }
        
        // fread 返回 false 表示错误
        // fread 返回空字符串只表示暂无数据（非阻塞模式），不是连接关闭
        // 需要用 feof() 检查连接是否真正关闭
        if ($data === false) {
            // 读取错误，关闭连接
            safeCloseStream($conn);
            unset($postHandshakeReadPending[$connId]);
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
            unset($writeBuffers[$connId]);
            unset($writableConnections[$connId]);
            if (isset($longLivedConnections[$connId])) {
                unset($longLivedConnections[$connId]);
                WlsLogger::info_(
                    '客户端断开，长连接已清理 (connId: ' . $connId . ', 剩余长连接数: ' . \count($longLivedConnections) . ')'
                );
            }
            if (isset($activeFibers[$connId])) {
                $fiberScheduler->cancelTimersForFiber($activeFibers[$connId]['fiber']);
                \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($activeFibers[$connId]['fiber']);
                $fiberScheduler->unregisterFiber();
                unset($activeFibers[$connId]);
                WlsLogger::info_(
                    '客户端断开，Fiber 已清理 (connId: ' . $connId . ', 剩余活跃 Fiber: ' . \count($activeFibers) . ')'
                );
            }
            $activeRequests = \max(0, $activeRequests - 1);
            continue;
        }
        
        if ($data === '' && (!\is_array($bufferedFrame) || ($bufferedFrame['status'] ?? '') === 'incomplete')) {
            if (@\feof($conn)) {
                safeCloseStream($conn);
                unset($postHandshakeReadPending[$connId]);
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);
                unset($pendingClose[$connId]);
                if (isset($longLivedConnections[$connId])) {
                    unset($longLivedConnections[$connId]);
                }
                if (isset($activeFibers[$connId])) {
                    $fiberScheduler->cancelTimersForFiber($activeFibers[$connId]['fiber']);
                    \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($activeFibers[$connId]['fiber']);
                    $fiberScheduler->unregisterFiber();
                    unset($activeFibers[$connId]);
                }
                $activeRequests = \max(0, $activeRequests - 1);
                continue;
            }
            // 暂无数据，不要立即检查 feof()，因为 SSL 连接上 feof() 不可靠
            // 让 Keep-Alive 超时机制来处理真正的空闲连接
            continue;
        }
        
        if ($data !== '') {
            // 更新连接最后活动时间
            $connectionLastActivity[$connId] = \time();
            \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->beginRequest((string)$connId);
            $requestBuffers[$connId] = ($requestBuffers[$connId] ?? '') . $data;
        } else {
            \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->beginRequest((string)$connId);
        }

        $bufferLength = \strlen($requestBuffers[$connId]);
        $tooLarge = $bufferLength > $maxBufferedRequestBytes;
        if ($tooLarge) {
            WlsLogger::warning_("SSL request body too large, reject connection (connId: {$connId}, buffered={$bufferLength})");
            @\fwrite($conn, wlsHttpFramingErrorResponse(413));
            safeCloseStream($conn);
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
            unset($writeBuffers[$connId]);
            unset($writableConnections[$connId]);
            unset($pendingClose[$connId]);
            if (isset($longLivedConnections[$connId])) {
                unset($longLivedConnections[$connId]);
            }
            continue;
        }
        
        // 开发模式：在接收到请求的第一行时立即输出路径日志（前台直接输出，后台通过 IPC 汇聚到 Master）
        if (($isDev || $hotPathLogsEnabled) && !isset($requestLogged[$connId])) {
            $firstLineEnd = \strpos($requestBuffers[$connId], "\r\n");
            if ($firstLineEnd !== false) {
                $requestLine = \substr($requestBuffers[$connId], 0, $firstLineEnd);
                if (\preg_match('/^(\w+)\s+([^\s]+)/', $requestLine, $matches)) {
                    $method = $matches[1];
                    $_p = \parse_url($matches[2], PHP_URL_PATH);
                    $uri = (\is_string($_p) && $_p !== '') ? $_p : '/';
                    $requestCount++;
                    $requestLogPrefix = InternalRequestLabel::buildLogPrefix($requestBuffers[$connId]);
                    if ($requestLogPrefix !== '') {
                        $method = $requestLogPrefix . $method;
                    }
                    if ($hotPathLogsEnabled) {
                        WlsLogger::info_("→ {$method} {$uri}");
                    }
                    $requestLogged[$connId] = true;
                }
            }
        }
        
        $frame = wlsParseHttpRequestFrame(
            $requestBuffers[$connId],
            $maxRequestHeaderBytes,
            $maxRequestBodyBytes,
        );
        if (($frame['status'] ?? '') === 'error') {
            WlsLogger::warning_(
                'Invalid SSL HTTP request framing, reject connection (connId=' . $connId
                . ', reason=' . (string)($frame['error'] ?? 'invalid_framing') . ')'
            );
            @\fwrite($conn, wlsHttpFramingErrorResponse((int)($frame['status_code'] ?? 400)));
            safeCloseStream($conn);
            unset(
                $postHandshakeReadPending[$connId],
                $connections[$connId],
                $requestBuffers[$connId],
                $connectionLastActivity[$connId],
                $requestLogged[$connId],
                $writeBuffers[$connId],
                $writableConnections[$connId],
                $pendingClose[$connId]
            );
            if (isset($longLivedConnections[$connId])) {
                unset($longLivedConnections[$connId]);
            }
            continue;
        }
        if (($frame['status'] ?? '') !== 'complete') {
            continue;
        }

        unset($postHandshakeReadPending[$connId]);

        \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->markRequestComplete((string)$connId);

        $rawRequest = (string)($frame['request'] ?? '');
        $requestBuffers[$connId] = \substr(
            $requestBuffers[$connId],
            (int)($frame['consumed'] ?? 0),
        );
        if (!isset($requestLogged[$connId])) {
            $requestCount++;
        }
        unset($requestLogged[$connId]); // 清理标记（如果不存在也不会报错）
        $activeRequests++;

        $transportPeerRaw = @\stream_socket_get_name($conn, true);
        $transportPeer = $connectionPeerIps[$connId]
            ?? (\is_string($transportPeerRaw) ? $transportPeerRaw : '');
        $policyStartedAt = \microtime(true);
        $policyDecision = \Weline\Server\Security\WorkerPolicyKernel::instance()->evaluate(
            $rawRequest,
            $transportPeer,
            $frame,
        );
        if (!$policyDecision->allowed) {
            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                (string)$policyDecision->response,
                $policyStartedAt,
                false,
                $ipcDraining,
                $connections,
                $requestBuffers,
                $connectionLastActivity,
                $requestLogged,
                $writeBuffers,
                $writableConnections,
                $pendingClose,
                $longLivedConnections,
                $ipcClient,
                $instanceName,
                $activeRequests
            );
            continue;
        }
        $uri = $policyDecision->path;
        $method = $policyDecision->method;

        // force_root_to_www：HTTPS 下根域 301 到 www 子域（在框架处理前拦截）
        $_reqHost = \strtolower(\trim((string)($policyDecision->headers['host'] ?? '')));
        if ($_reqHost !== '') {
            $_hostOnly = \explode(':', $_reqHost)[0];
            $_hostParts = \explode('.', $_hostOnly);
            if (\count($_hostParts) === 2) {
                $_p = _getDomainPolicy($_hostOnly);
                if ($_p['force_root_to_www'] === 1) {
                    $_reqPath = $policyDecision->path !== '' ? $policyDecision->path : '/';
                    $_wwwHost = 'www.' . $_hostOnly;
                    $_redirectPort = (int) $port;
                    $_wwwUrl = ($_redirectPort === 443)
                        ? "https://{$_wwwHost}{$_reqPath}"
                        : "https://{$_wwwHost}:{$_redirectPort}{$_reqPath}";
                    $_body = '';
                    $_resp = "HTTP/1.1 301 Moved Permanently\r\nLocation: {$_wwwUrl}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
                    @\fwrite($conn, $_resp);
                    safeCloseStream($conn);
                    unset($connections[$connId], $connectionLastActivity[$connId], $requestBuffers[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId]);
                    $activeRequests--;
                    continue;
                }
            }
        }

        // Mandatory policy and the HTTPS canonical-host redirect have already
        // completed. A process Static L1 hit must stop here, before protocol
        // detection, FPC, Fiber creation and Framework request state.
        $staticFastResponse = $policyDecision->staticProcessCacheEnabled()
            ? \Weline\Server\Service\WorkerStaticResponseL1::lookup($policyDecision)
            : null;
        if ($staticFastResponse !== null) {
            $staticFastResponse = injectWlsProcessTimeHeader(
                $staticFastResponse,
                (\microtime(true) - $policyStartedAt) * 1000
            );
            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                $staticFastResponse,
                $policyStartedAt,
                false,
                $ipcDraining,
                $connections,
                $requestBuffers,
                $connectionLastActivity,
                $requestLogged,
                $writeBuffers,
                $writableConnections,
                $pendingClose,
                $longLivedConnections,
                $ipcClient,
                $instanceName,
                $activeRequests,
                false,
                (string)($policyDecision->headers['host'] ?? ''),
                $policyDecision->keepAlive(),
            );
            continue;
        }

        // FPC executes immediately after mandatory policy/static gates. The
        // fast-path service rejects SSE/upgrades and client cache bypasses, so
        // a hit never creates request scope, a Fiber, Session or Router state.
        if ($policyDecision->fpcCacheEnabled()
            && $fpcFastPath instanceof \Weline\Server\Service\WorkerFullPageCacheFastPath
        ) {
            $fpcHit = $fpcFastPath->lookup($policyDecision, 'https');
            if ($fpcHit !== null) {
                $fastPathElapsedMs = (float)\round((\microtime(true) - $policyStartedAt) * 1000, 2);
                $fastPathResponse = wlsDecorateFormattedFpcFastResponseForPerformancePanel(
                    (string)$fpcHit['response'],
                    $rawRequest,
                    $fastPathElapsedMs,
                    $workerId,
                    $port,
                    (string)$fpcHit['source'],
                );
                sslFinalizeHttpResponseAfterHandle(
                    $conn,
                    $connId,
                    $rawRequest,
                    $fastPathResponse,
                    $policyStartedAt,
                    false,
                    $ipcDraining,
                    $connections,
                    $requestBuffers,
                    $connectionLastActivity,
                    $requestLogged,
                    $writeBuffers,
                    $writableConnections,
                    $pendingClose,
                    $longLivedConnections,
                    $ipcClient,
                    $instanceName,
                    $activeRequests,
                    false,
                    (string)($policyDecision->headers['host'] ?? ''),
                    $policyDecision->keepAlive(),
                    true,
                );
                continue;
            }
        }

        // Static L1 is immutable under the cache epoch, so static-only traffic
        // does not need request-scope GC/status JSON. Dynamic/cold requests keep
        // the existing bounded compaction cadence below the fast-path gate.
        if ($requestCount - $lastRequestGcCount >= $requestGcInterval) {
            $lastRequestGcCount = $requestCount;
            $compaction = wlsCompactWorkerMemoryCaches('ssl_request_interval', $maxMemoryBytes, 0.55, 16 * 1024 * 1024);
            $collected = (int)($compaction['cycles'] ?? 0);
            $currentMemory = \memory_get_usage(true);
            $memoryPeak = \memory_get_peak_usage(true);
            $staticCacheCompaction = (array)($compaction['static_file_cache'] ?? []);
            $staticCacheDebug = (($staticCacheCompaction['cleared'] ?? false) ? 'cleared' : 'kept')
                . ':' . (int)($staticCacheCompaction['count'] ?? 0)
                . ':' . (int)($staticCacheCompaction['size'] ?? 0);
            if ($staticCacheCompaction['cleared'] ?? false) {
                WlsLogger::debug_("GC static cache compact: worker=ssl requests={$requestCount} static={$staticCacheDebug}");
            }
            foreach (\array_keys($connectionPeerIps) as $peerConnId) {
                if (!isset($connections[$peerConnId])
                    && !isset($pendingPeek[$peerConnId])
                    && !isset($pendingHandshakes[$peerConnId])
                ) {
                    unset($connectionPeerIps[$peerConnId]);
                }
            }
            if (($compaction['cycles'] ?? 0) > 0 || ($compaction['trimmed_bytes'] ?? 0) > 0 || ($staticCacheCompaction['cleared'] ?? false) || $currentMemory > 150 * 1024 * 1024) {
                WlsLogger::debug_("GC 触发: 回收 {$collected} 个循环，内存: " . \round($currentMemory / 1024 / 1024, 1) . "MB，峰值: " . \round($memoryPeak / 1024 / 1024, 1) . "MB");
            }
            if ($currentMemory > 200 * 1024 * 1024) {
                WlsLogger::warning_("内存使用过高: " . \round($currentMemory / 1024 / 1024, 1) . "MB，请检查内存泄漏。当前请求: {$method} {$uri}");
            }
        }

        if (!$isDev) {
            $requestLogPrefix = InternalRequestLabel::buildLogPrefix($rawRequest);
            if ($requestLogPrefix !== '') {
                $method = $requestLogPrefix . $method;
            }
            WlsLogger::debug_("收到请求: {$method} {$uri} (connId: {$connId}, requestCount: {$requestCount})");
        }

        // 长连分层：is_long_lived 与 HTTP Worker 一致；protocol===sse 仅本文件用于 SseContext 写队列 + sslFinalize 分支（见 SseMatcher 注释）。
        $longLivedDetection = $longLivedProtocolResolver->detect($rawRequest);
        $isLongLived = ($longLivedDetection['is_long_lived'] ?? false) === true;
        $requestProtocol = (string) ($longLivedDetection['protocol'] ?? 'http');
        $isSseProtocolRequest = ($requestProtocol === 'sse');
        $applyLongLivedLimit = !$isSseProtocolRequest;

        if ($hotPathLogsEnabled) {
            $uriForLog = '/';
            if (\preg_match('/^\w+\s+([^\s]+)/', $rawRequest, $m)) {
                $uriForLog = \parse_url($m[1], \PHP_URL_PATH) ?: $m[1];
            }
            $requestLogPrefix = InternalRequestLabel::buildLogPrefix($rawRequest);
            if ($requestLogPrefix !== '') {
                $uriForLog = $requestLogPrefix . $uriForLog;
            }
            WlsLogger::info_(
                'Worker 开始处理请求 connId=' . $connId . ' uri='
                . (\strlen($uriForLog) > 80 ? \substr($uriForLog, 0, 80) . '...' : $uriForLog)
            );
        }
        $handleStartTime = \microtime(true);

        if ($isLongLived) {
            $layer = (string) ($longLivedDetection['layer'] ?? 'unknown');
            $protocol = (string) ($longLivedDetection['protocol'] ?? 'long-lived');
            WlsLogger::info_("长链分层命中: layer={$layer}, protocol={$protocol}, connId={$connId}");
            if ($applyLongLivedLimit && $longLivedMaxActive > 0 && \count($longLivedConnections) >= $longLivedMaxActive) {
                $isWorkspaceStreamSse = $isSseProtocolRequest && \str_contains($rawRequest, '/stream-sse');
                if ($isWorkspaceStreamSse) {
                    $waitDeadline = \microtime(true) + 1.2;
                    while (\microtime(true) < $waitDeadline && \count($longLivedConnections) >= $longLivedMaxActive) {
                        foreach (\array_keys($longLivedConnections) as $llConnId) {
                            $llConn = $connections[$llConnId] ?? null;
                            if (!$llConn || !\is_resource($llConn)) {
                                unset($longLivedConnections[$llConnId]);
                            }
                        }
                        if (\count($longLivedConnections) < $longLivedMaxActive) {
                            break;
                        }
                        \Weline\Framework\Runtime\SchedulerSystem::yieldDelay(50);
                    }
                }
            }
            if ($applyLongLivedLimit && $longLivedMaxActive > 0 && \count($longLivedConnections) >= $longLivedMaxActive) {
                $activeRequests--;
                $body = 'Too Many Long Connections - Retry Shortly';
                $resp = "HTTP/1.1 429 Too Many Requests\r\nContent-Type: text/plain; charset=utf-8\r\nRetry-After: 2\r\nContent-Length: "
                    . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
                @\fwrite($conn, $resp);
                @\fclose($conn);
                unset(
                    $connections[$connId],
                    $requestBuffers[$connId],
                    $connectionLastActivity[$connId],
                    $requestLogged[$connId],
                    $writeBuffers[$connId],
                    $writableConnections[$connId]
                );
                continue;
            }
            if ($applyLongLivedLimit) {
                $longLivedConnections[$connId] = [
                    'type' => $protocol,
                    'start' => \time(),
                ];
                WlsLogger::info_(
                    '长连接槽位已分配 (connId: ' . $connId . ', protocol: ' . $protocol
                    . ', 当前长连接数: ' . \count($longLivedConnections) . ')'
                );
            } else {
                WlsLogger::info_(
                    'SSE 长连接不参与 long_lived_max_active 限制 (connId: ' . $connId . ', protocol: ' . $protocol . ')'
                );
            }
        }

        $activeAdmissionFibers = wlsCountActiveFibersForAdmission($activeFibers);
        if (!$isSseProtocolRequest && $fiberMaxActive > 0 && $activeAdmissionFibers >= $fiberMaxActive) {
            $activeRequests--;
            $body = 'Service Unavailable';
            $resp = "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: "
                . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
            @\fwrite($conn, $resp);
            @\fclose($conn);
            unset(
                $connections[$connId],
                $requestBuffers[$connId],
                $connectionLastActivity[$connId],
                $requestLogged[$connId],
                $writeBuffers[$connId],
                $writableConnections[$connId]
            );
            if (isset($longLivedConnections[$connId])) {
                unset($longLivedConnections[$connId]);
            }
            WlsLogger::warning_("Fiber 池已满 (max_active={$fiberMaxActive})，拒绝请求 (connId: {$connId})");
            continue;
        }

        $fiberConnId = $connId;
        $fiberConn = $conn;
        $fiberRawRequest = $rawRequest;
        $requestFiber = new \Fiber(function () use (
            $fiberRawRequest,
            $runtime,
            $runtimeError,
            $instanceName,
            $workerId,
            $port,
            $requestCount,
            &$activeRequests,
            &$connections,
            $startTime,
            $originToken,
            $originTokenValidationEnabled,
            $originTokenHeader,
            $originTokenAllowLocal,
            $transportPeer,
            $policyDecision,
            $asyncBizAdapters,
            $WLS_UOPZ_EXIT_GUARD,
            $fiberConn,
            $fiberConnId,
            $isSseProtocolRequest,
            &$requestBuffers,
            &$connectionLastActivity,
            &$requestLogged,
            &$writeBuffers,
            &$writableConnections,
            &$pendingClose
        ) {
            wlsFiberRequestContextEnter($fiberConn, $fiberConnId);
            try {
                if ($isSseProtocolRequest) {
                    \Weline\Framework\Http\Sse\SseContext::setWriteCallback(
                        static function (string $data) use (
                            $fiberConnId,
                            $fiberConn,
                            &$connections,
                            &$requestBuffers,
                            &$connectionLastActivity,
                            &$requestLogged,
                            &$writeBuffers,
                            &$writableConnections,
                            &$pendingClose
                        ): bool {
                            return enqueueSseWriteAndAwaitDrain(
                                $fiberConnId,
                                $fiberConn,
                                $data,
                                $connections,
                                $requestBuffers,
                                $connectionLastActivity,
                                $requestLogged,
                                $writeBuffers,
                                $writableConnections,
                                $pendingClose
                            );
                        }
                    );
                    \Weline\Framework\Http\Sse\SseContext::setAliveCallback(
                        static function () use (
                            $fiberConnId,
                            $fiberConn,
                            &$connections,
                            &$pendingClose
                        ): bool {
                            return wlsSslIsSseClientConnected(
                                $fiberConnId,
                                $fiberConn,
                                $connections,
                                $pendingClose
                            );
                        }
                    );
                }
                return handleRequest(
                    $fiberRawRequest,
                    $runtime,
                    $runtimeError,
                    $asyncBizAdapters,
                    $instanceName,
                    $workerId,
                    $port,
                    $requestCount,
                    $activeRequests,
                    \count($connections),
                    $startTime,
                    $originToken,
                    $originTokenValidationEnabled,
                    $originTokenHeader,
                    $originTokenAllowLocal,
                    $transportPeer,
                    $policyDecision
                );
            } catch (\Weline\Framework\Runtime\RequestExitException $e) {
                throw $e;
            } catch (\Error $e) {
                if ($WLS_UOPZ_EXIT_GUARD && \str_contains($e->getMessage(), 'uopz')) {
                    WlsLogger::warning_('SSL Worker：exit()/die() 已由 uopz 拦截');
                    return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                        . "Connection: close\r\nContent-Length: 52\r\n\r\n"
                        . "Internal error: exit()/die() not allowed in WLS request\n";
                }
                throw $e;
            } finally {
                // 统一清台：无论正常/异常/提前返回，都清理请求级上下文，避免 Fiber 间串味。
                wlsFiberRequestContextLeave();
                wlsResetLongRunningExecutionLimit();
            }
        });

        $fiberScheduler->registerFiber();
        try {
            $requestFiber->start();
        } catch (\Weline\Framework\Runtime\RequestExitException) {
        } catch (\Throwable $e) {
            WlsLogger::error_('Fiber 启动异常: ' . $e->getMessage());
        }

        if ($requestFiber->isTerminated()) {
            $fiberScheduler->unregisterFiber();
            $fiberResponse = '';
            try {
                $fiberResponse = (string) ($requestFiber->getReturn() ?? '');
            } catch (\Throwable) {
            } finally {
                \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($requestFiber);
            }
            $handleDurationMs = (\microtime(true) - $handleStartTime) * 1000;
            $fiberResponse = injectWlsProcessTimeHeader($fiberResponse, $handleDurationMs);
            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                $fiberResponse,
                $handleStartTime,
                $isSseProtocolRequest,
                $ipcDraining,
                $connections,
                $requestBuffers,
                $connectionLastActivity,
                $requestLogged,
                $writeBuffers,
                $writableConnections,
                $pendingClose,
                $longLivedConnections,
                $ipcClient,
                $instanceName,
                $activeRequests
            );
            wlsDrainAfterResponseIfRequested($socket, $shouldExit, $ipcDraining, $drainStartTime, $maxDrainTime);
        } elseif ($requestFiber->isSuspended()) {
            $activeFibers[$fiberConnId] = [
                'fiber' => $requestFiber,
                'conn' => $fiberConn,
                'rawRequest' => $rawRequest,
                'handleStartTime' => $handleStartTime,
                'context' => \Weline\Framework\Runtime\WlsFiberContext::capture(),
                'suspended_at' => \time(),
                'last_activity' => \time(),
                'is_long_lived' => $isLongLived,
                'is_sse_protocol' => $isSseProtocolRequest,
            ];
            WlsLogger::info_("请求进入 Fiber 异步模式 (connId: {$connId})");
            $nowSat = \time();
            if ($longLivedMaxActive > 0) {
                $isSaturated = \count($longLivedConnections) >= $longLivedMaxActive;
                if (
                    $isSaturated
                    && !$longLivedSaturationReported
                    && ($nowSat - $lastLongLivedSaturationReport) >= $longLivedSaturationInterval
                ) {
                    if ($ipcClient && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::workerSaturation(
                            $workerId,
                            $port,
                            \count($longLivedConnections),
                            $longLivedMaxActive,
                            \count($activeFibers),
                            $fiberMaxActive
                        ));
                        $lastLongLivedSaturationReport = $nowSat;
                        $longLivedSaturationReported = true;
                        $longLivedSaturationCleared = false;
                        WlsLogger::warning_(
                            '长连接饱和上报 (long_lived_count=' . \count($longLivedConnections)
                            . ", max={$longLivedMaxActive})"
                        );
                    }
                } elseif (!$isSaturated && $longLivedSaturationReported && !$longLivedSaturationCleared) {
                    if ($ipcClient && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::workerSaturationCleared(
                            $workerId,
                            $port,
                            \count($longLivedConnections),
                            $longLivedMaxActive
                        ));
                        $longLivedSaturationReported = false;
                        $longLivedSaturationCleared = true;
                        WlsLogger::info_(
                            '长连接饱和解除 (long_lived_count=' . \count($longLivedConnections) . ')'
                        );
                    }
                }
            }
        } else {
            $fiberScheduler->unregisterFiber();
            $activeRequests = \max(0, $activeRequests - 1);
        }
        continue;
    }

    // 处理可写连接
    wlsSslFlushQueuedWrites(
        $activeRequests,
        $writableConnections,
        $writeBuffers,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $pendingClose,
        $longLivedConnections
    );

    // 重置连续错误计数（本轮循环成功完成）
    $consecutiveErrors = 0;
    
    } catch (\Throwable $loopException) {
        // Workerman 模式：捕获所有异常，防止 Worker 意外退出
        $consecutiveErrors++;
        $errorMessage = $loopException->getMessage();
        $errorFile = $loopException->getFile();
        $errorLine = $loopException->getLine();
        
        // 记录错误日志
        w_log_error("[WLS-SSL Worker #{$workerId}] 事件循环异常 ({$consecutiveErrors}/{$maxConsecutiveErrors}): {$errorMessage} in {$errorFile}:{$errorLine}");
        WlsLogger::error_("事件循环异常: {$errorMessage}");
        
        // 刷新日志缓冲区
        WlsLogger::flush_(true);
        
        // 如果连续错误过多，优雅退出让 Master 重启
        if ($consecutiveErrors >= $maxConsecutiveErrors) {
            w_log_error("[WLS-SSL Worker #{$workerId}] 连续错误过多，优雅退出");
            $gracefulExit("连续错误过多 ({$consecutiveErrors} 次)");
        }
        
        // 短暂休眠后继续（避免错误风暴）
        \Weline\Framework\Runtime\SchedulerSystem::usleep(10000); // 10ms
        continue;
    }
}

/**
 * Step-1: accept 连接并放入下一状态（plain/read 或 defer-ssl/peek）。
 *
 * @param resource|null $socket
 * @param array<int, resource> $read
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<int, array{conn: resource, peerName: string, buffer: string}> $pendingPeek
 * @param array<int, float> $pendingPeekStartTimes
 */
function wlsSslAcceptNewConnections(
    mixed $socket,
    array &$read,
    bool $deferSsl,
    array &$pendingPeek,
    array &$pendingPeekStartTimes,
    int $pendingHandshakeCount,
    int $handshakeQueueHighWatermark,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    bool $isDev,
    array &$connectionPeerIps,
    int $maxAcceptPerLoop = 64,
    bool $applicationAdmissionOpen = true,
    mixed $nativeListenerSocket = null,
): int {
    if (!$socket || !\is_resource($socket) || !\in_array($socket, $read, true)) {
        return 0;
    }

    if (!$applicationAdmissionOpen) {
        $key = \array_search($socket, $read, true);
        if ($key !== false) {
            unset($read[$key]);
        }
        return 0;
    }

    $accepted = 0;
    $admitted = 0;
    $maxAcceptPerLoop = \max(1, \min(64, $maxAcceptPerLoop));
    while ($accepted < $maxAcceptPerLoop) {
        if ($nativeListenerSocket instanceof \Socket) {
            $acceptedSocket = @\socket_accept($nativeListenerSocket);
            if (!$acceptedSocket instanceof \Socket) {
                break;
            }
            // Raw accept already consumed kernel work. Count it before export so
            // repeated export failures cannot bypass the per-loop CPU budget.
            $accepted++;
            $conn = @\socket_export_stream($acceptedSocket);
            if (!\is_resource($conn)) {
                @\socket_close($acceptedSocket);
                continue;
            }
        } else {
            $conn = @\stream_socket_accept($socket, 0);
            if (!$conn) {
                break;
            }
            $accepted++;
        }
        $connId = \get_resource_id($conn);
        unset($connectionPeerIps[$connId]);
        $peerNameRaw = @\stream_socket_get_name($conn, true);
        $peerName = \is_string($peerNameRaw) ? $peerNameRaw : 'unknown-peer';
        $acceptGates = \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull();
        if ($acceptGates !== null) {
            $decision = $acceptGates->accept((string)$connId, $peerName);
            if (!$decision->allowed) {
                safeCloseStream($conn);
                continue;
            }
            $connectionPeerIps[$connId] = $decision->peerIp;
        }
        if ($deferSsl && (\count($pendingPeek) + $pendingHandshakeCount) >= $handshakeQueueHighWatermark) {
            safeCloseStream($conn);
            if ($isDev) {
                WlsLogger::warning_('SSL handshake queue high watermark reached; closed new accepted connection');
            }
            break;
        }
        if ($isDev) {
            WlsLogger::info_("新连接: {$peerName} (connId: {$connId})");
        }

        \stream_set_blocking($conn, false);
        wlsSslTuneAcceptedStream($conn);
        if ($deferSsl) {
            $pendingPeek[$connId] = [
                'conn' => $conn,
                'peerName' => $peerName,
                'buffer' => '',
            ];
            $pendingPeekStartTimes[$connId] = \microtime(true);
        } else {
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = \time();
        }
        $admitted++;
    }

    $key = \array_search($socket, $read, true);
    if ($key !== false) {
        unset($read[$key]);
    }

    return $admitted;
}

function wlsSslTuneAcceptedStream(mixed $conn): void
{
    if (!\is_resource($conn)) {
        return;
    }

    @\stream_set_read_buffer($conn, 0);
    @\stream_set_write_buffer($conn, 0);

    if (!\function_exists('socket_import_stream')) {
        return;
    }

    $socket = @\socket_import_stream($conn);
    if (!$socket instanceof \Socket) {
        return;
    }

    @\socket_set_option($socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
    if (\defined('TCP_NODELAY') && \defined('SOL_TCP')) {
        @\socket_set_option($socket, \SOL_TCP, (int) \TCP_NODELAY, 1);
    }
}

/**
 * Zero-wait kernel readiness probe used only after a successful Darwin shared
 * accept. A remaining readable listener means a burst is still queued, so the
 * Worker uses the short cooldown; an empty queue gets a longer low-load yield.
 */
function wlsSslListenerHasPendingAccept(mixed $socket): bool
{
    if (!\is_resource($socket)) {
        return false;
    }

    $read = [$socket];
    $write = null;
    $except = null;

    return @\stream_select($read, $write, $except, 0, 0) > 0;
}

/**
 * defer-ssl：按 ClientHello 中的 SNI 选择证书（内存映射 / 通配 / 默认 PEM）。
 *
 * @param array<string, array{local_cert: string, local_pk: string}> $sniServerCerts
 * @return array{local_cert: string, local_pk: string}
 */
function wlsSslPickCertificatePairForDeferSni(
    ?string $sniHost,
    array $sniServerCerts,
    string $defaultCert,
    string $defaultKey
): array {
    $fallback = ['local_cert' => $defaultCert, 'local_pk' => $defaultKey];
    if ($defaultCert === '' || $defaultKey === '') {
        return $fallback;
    }
    $h = $sniHost !== null ? \strtolower(\trim($sniHost)) : '';
    if ($h === '' || \filter_var($h, \FILTER_VALIDATE_IP)) {
        return $fallback;
    }
    if (isset($sniServerCerts[$h])) {
        $p = $sniServerCerts[$h];
        if (($p['local_cert'] ?? '') !== '' && ($p['local_pk'] ?? '') !== '') {
            return $p;
        }
    }
    foreach ($sniServerCerts as $mappedName => $pair) {
        if (!\is_string($mappedName) || !\str_starts_with($mappedName, '*.')) {
            continue;
        }
        $root = \substr($mappedName, 2);
        if ($root !== '' && \str_ends_with($h, '.' . $root)) {
            if (($pair['local_cert'] ?? '') !== '' && ($pair['local_pk'] ?? '') !== '') {
                return $pair;
            }
        }
    }
    return $fallback;
}

/**
 * defer-ssl accept 连接：对单流设置「单证书」SSL 上下文。
 * PHP 在 stream_socket_enable_crypto 上对 SNI_server_certs 多映射支持不可靠，会触发 unrecognized_name；
 * 此处关闭 SNI 多域映射，改用与本连接 ClientHello SNI 匹配的一对 PEM。
 *
 * @param array<string, mixed>|null $deferSslOptionsTemplate
 */
function wlsSslApplyPerConnectionSslForDeferHandshake(
    $conn,
    array $pair,
    ?array $deferSslOptionsTemplate,
    int $cryptoMethod
): void {
    $cipherSuite = \is_array($deferSslOptionsTemplate)
        ? (string) ($deferSslOptionsTemplate['ciphers'] ?? '')
        : '';
    if ($cipherSuite === '') {
        $cipherSuite = 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:!aNULL:!eNULL:!MD5:!RC4:!DES:!3DES:!DSS:!SHA1:!DHE';
    }
    $ecdhCurve = \is_array($deferSslOptionsTemplate)
        ? (string) ($deferSslOptionsTemplate['ecdh_curve'] ?? '')
        : '';
    if ($ecdhCurve === '') {
        $ecdhCurve = 'X25519:prime256v1';
    }
    $opts = [
        'local_cert' => $pair['local_cert'],
        'local_pk' => $pair['local_pk'],
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
        'disable_compression' => true,
        'crypto_method' => $cryptoMethod,
        'ciphers' => $cipherSuite,
        'ecdh_curve' => $ecdhCurve,
        'single_dh_use' => true,
        'honor_cipher_order' => true,
        'SNI_enabled' => false,
    ];
    foreach ($opts as $k => $v) {
        @\stream_context_set_option($conn, 'ssl', (string) $k, $v);
    }
    @\stream_context_set_option($conn, 'ssl', 'SNI_server_certs', []);
}

/**
 * Step-2: defer-ssl peek 状态推进（STREAM_PEEK 解析 SNI → 单连接证书 → 握手，失败进入 pendingHandshakes 重试）。
 *
 * @param array<int, array{conn: resource, peerName: string, buffer: string}> $pendingPeek
 * @param array<int, float> $pendingPeekStartTimes
 * @param array<int, array{conn: resource, peerName: string, phase?: string, started?: bool}> $pendingHandshakes
 * @param array<int, float> $handshakeStartTimes
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<string, array{local_cert: string, local_pk: string}> $sniServerCerts
 */
function wlsSslAdvancePeekState(
    array &$pendingPeek,
    array &$pendingPeekStartTimes,
    array &$pendingHandshakes,
    array &$handshakeStartTimes,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$postHandshakeReadPending,
    array $read,
    ?array $deferSslOptions,
    int $cryptoMethod,
    int $maxAdvancePerLoop,
    int $handshakeQueueHighWatermark,
    bool $isDev,
    array $sniServerCerts,
    string $sslCert,
    string $sslKey,
    int $publicTcpPort,
    string $deferSslPreferredHost = '',
    array &$connectionPeerIps = [],
    string $runtimeTopology = 'direct',
    string $proxyAuthenticationSecret = ''
): void {
    if ($pendingPeek === []) {
        return;
    }
    if ($deferSslOptions === null) {
        return;
    }

    $peekTimeout = 5.0;
    $completedPeeks = [];
    $failedPeeks = [];
    $advanced = 0;
    $readyPeekIds = [];
    foreach ($read as $readyConn) {
        if (\is_resource($readyConn)) {
            $readyPeekIds[\get_resource_id($readyConn)] = true;
        }
    }

    foreach ($pendingPeek as $connId => $peekInfo) {
        if ($advanced >= $maxAdvancePerLoop) {
            break;
        }
        if ((\count($pendingPeek) + \count($pendingHandshakes)) > $handshakeQueueHighWatermark) {
            $failedPeeks[] = $connId;
            continue;
        }
        $conn = $peekInfo['conn'];
        $peerName = $peekInfo['peerName'];
        $startTime = $pendingPeekStartTimes[$connId] ?? \microtime(true);
        $elapsed = \microtime(true) - $startTime;
        if ($elapsed > $peekTimeout) {
            $failedPeeks[] = $connId;
            WlsLogger::warning_("Peek 超时: {$peerName} (connId: {$connId})");
            continue;
        }

        if (!isset($readyPeekIds[$connId])) {
            continue;
        }

        $trustedDispatcherBackend = $runtimeTopology === 'dispatcher'
            && \Weline\Server\Protocol\ProxyProtocolV2::isLoopbackPeer($peerName);
        if ($trustedDispatcherBackend) {
            try {
                $proxy = \Weline\Server\Protocol\ProxyProtocolV2::consumeFromStream(
                    $conn,
                    $proxyAuthenticationSecret,
                    true
                );
            } catch (\Throwable $proxyError) {
                $failedPeeks[] = $connId;
                WlsLogger::warning_('Invalid authenticated PROXY v2 preface: ' . $proxyError->getMessage());
                continue;
            }
            if (!($proxy['complete'] ?? false)) {
                continue;
            }
            $proxyIp = (string)($proxy['source_ip'] ?? '');
            if ($proxyIp !== '' && \filter_var($proxyIp, FILTER_VALIDATE_IP)) {
                $peerName = $proxyIp;
                $pendingPeek[$connId]['peerName'] = $proxyIp;
                $connectionPeerIps[$connId] = $proxyIp;
            }
        }

        $peeked = wlsSslPeekTcpPrefixNoConsume($conn);
        if ($peeked === '') {
            continue;
        }

        // 同端口 HTTP→HTTPS：首包非 TLS 时发 301（不解 peek，数据仍留给对端关闭后重试）
        if (\ord($peeked[0]) !== 0x16) {
            if (\preg_match('/^[A-Z][A-Z0-9-]*\s/', $peeked) === 1) {
                $_host = '127.0.0.1';
                if (\preg_match('/\r\nHost:\s*([^\r\n]+)/i', $peeked, $_hm)) {
                    $_host = \strtolower(\trim(\explode(':', $_hm[1], 2)[0]));
                }
                $_loc = 'https://' . $_host;
                if ($publicTcpPort !== 443) {
                    $_loc .= ':' . $publicTcpPort;
                }
                $_path = '/';
                if (\preg_match('/^[A-Z][A-Z0-9-]*\s+(\S+)/', $peeked, $_pm)) {
                    $_pu = \parse_url($_pm[1], \PHP_URL_PATH);
                    if (\is_string($_pu) && $_pu !== '') {
                        $_path = $_pu;
                    }
                }
                $_loc .= $_path;
                $_resp = 'HTTP/1.1 301 Moved Permanently' . "\r\n"
                    . 'Location: ' . $_loc . "\r\n"
                    . 'Content-Length: 0' . "\r\n"
                    . 'Connection: close' . "\r\n\r\n";
                @\fwrite($conn, $_resp);
            }
            safeCloseStream($conn);
            $completedPeeks[] = $connId;
            continue;
        }

        if (\strlen($peeked) < 5) {
            continue;
        }
        $tlsPayloadLen = (\ord($peeked[3]) << 8) | \ord($peeked[4]);
        $recordNeed = 5 + $tlsPayloadLen;
        if ($recordNeed > 65536 || $tlsPayloadLen < 0) {
            $failedPeeks[] = $connId;
            WlsLogger::warning_("Peek TLS 记录长度异常: {$peerName} (connId: {$connId})");
            continue;
        }
        if (\strlen($peeked) < $recordNeed) {
            continue;
        }

        $sniRaw = _parseSniHostFromClientHello($peeked);
        $sniHostNorm = $sniRaw !== null && $sniRaw !== '' ? \strtolower(\trim((string) $sniRaw)) : null;
        $effectiveHost = $sniHostNorm;
        if ($effectiveHost === null || $effectiveHost === '') {
            $ph = \strtolower(\trim($deferSslPreferredHost));
            if ($ph !== '' && !\filter_var($ph, \FILTER_VALIDATE_IP)) {
                $effectiveHost = $ph;
            }
        }
        $pair = wlsSslPickCertificatePairForDeferSni(
            $effectiveHost,
            $sniServerCerts,
            $sslCert,
            $sslKey
        );
        wlsSslApplyPerConnectionSslForDeferHandshake($conn, $pair, $deferSslOptions, $cryptoMethod);
        $advanced++;

        if ($isDev) {
            $sniLog = $sniHostNorm ?? '(none)';
            $effLog = $effectiveHost ?? '(none)';
            WlsLogger::info_("[SSL defer] ClientHello SNI={$sniLog} effective={$effLog} cert=" . $pair['local_cert']);
        }

        $cryptoResult = @\stream_socket_enable_crypto($conn, true, $cryptoMethod);
        if ($isDev) {
            WlsLogger::info_("SSL 握手尝试: {$peerName} (connId: {$connId}), result: " . \var_export($cryptoResult, true));
        }

        if ($cryptoResult === true) {
            if ($isDev) {
                WlsLogger::info_("SSL 握手成功: {$peerName} (connId: {$connId})");
            }
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = \time();
            $postHandshakeReadPending[$connId] = [
                'conn' => $conn,
                'deadline' => \microtime(true) + 0.20,
            ];
            $completedPeeks[] = $connId;
            continue;
        }

        $pendingHandshakes[$connId] = [
            'conn' => $conn,
            'peerName' => $peerName,
            'phase' => 'pending',
        ];
        $handshakeStartTimes[$connId] = \microtime(true);
        $completedPeeks[] = $connId;

        if ($isDev && $cryptoResult === false) {
            $error = \error_get_last();
            $errorMsg = $error['message'] ?? 'unknown';
            WlsLogger::info_("SSL 握手首次返回 false，加入重试队列: {$peerName} (connId: {$connId}) - {$errorMsg}");
        }
    }

    foreach ($completedPeeks as $connId) {
        unset($pendingPeek[$connId], $pendingPeekStartTimes[$connId]);
    }

    foreach ($failedPeeks as $connId) {
        if (isset($pendingPeek[$connId]['conn'])) {
            safeCloseStream($pendingPeek[$connId]['conn']);
        }
        unset($pendingPeek[$connId], $pendingPeekStartTimes[$connId]);
    }
}

/**
 * Step-3: 握手重试状态推进（读写就绪触发 retry，成功后进入可读 connections）。
 *
 * @param array<int, array{conn: resource, peerName: string, phase?: string, started?: bool}> $pendingHandshakes
 * @param array<int, float> $handshakeStartTimes
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<int, bool> $requestLogged
 * @param array<int, resource> $read
 * @param array<int, resource> $write
 */
function wlsSslAdvanceHandshakeState(
    array &$pendingHandshakes,
    array &$handshakeStartTimes,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$postHandshakeReadPending,
    array &$read,
    array $write,
    int|false $changed,
    int $cryptoMethod,
    int $maxAdvancePerLoop,
    bool $isDev
): void {
    if ($pendingHandshakes === []) {
        return;
    }

    $handshakeTimeout = 5.0;
    $completedHandshakes = [];
    $failedHandshakes = [];
    $advanced = 0;

    if ($isDev) {
        static $lastPendingHandshakeLogAt = 0.0;
        static $lastPendingHandshakeCount = -1;
        $pendingCount = \count($pendingHandshakes);
        $now = \microtime(true);
        // 节流：数量变化立即记录；数量不变时最多每秒记录一次，避免日志风暴淹没关键事件。
        if ($pendingCount !== $lastPendingHandshakeCount || ($now - $lastPendingHandshakeLogAt) >= 1.0) {
            WlsLogger::info_('握手循环待处理连接数: ' . $pendingCount);
            $lastPendingHandshakeLogAt = $now;
            $lastPendingHandshakeCount = $pendingCount;
        }
    }

    foreach ($pendingHandshakes as $connId => $handshakeInfo) {
        if ($advanced >= $maxAdvancePerLoop) {
            break;
        }
        $conn = $handshakeInfo['conn'];
        $peerName = $handshakeInfo['peerName'];
        $startTime = $handshakeStartTimes[$connId] ?? \microtime(true);
        $elapsed = \microtime(true) - $startTime;
        if ($elapsed > $handshakeTimeout) {
            $failedHandshakes[] = $connId;
            WlsLogger::warning_("SSL 握手超时: {$peerName} (connId: {$connId})");
            continue;
        }

        $shouldAttempt = !isset($handshakeInfo['started']);
        if (!$shouldAttempt && $changed !== false) {
            foreach ($read as $r) {
                if (\is_resource($r) && \get_resource_id($r) === $connId) {
                    $shouldAttempt = true;
                    break;
                }
            }
            if (!$shouldAttempt) {
                foreach ($write as $w) {
                    if (\is_resource($w) && \get_resource_id($w) === $connId) {
                        $shouldAttempt = true;
                        break;
                    }
                }
            }
        }

        if (!$shouldAttempt) {
            continue;
        }

        $pendingHandshakes[$connId]['started'] = true;
        $advanced++;
        $cryptoResult = @\stream_socket_enable_crypto($conn, true, $cryptoMethod);

        if ($cryptoResult === true) {
            $completedHandshakes[] = $connId;
            if ($isDev) {
                WlsLogger::info_("SSL 握手成功: {$peerName} (connId: {$connId})");
            }
            continue;
        }

        if ($cryptoResult === 0) {
            continue;
        }

        $error = \error_get_last();
        $errorMsg = $error['message'] ?? 'unknown';
        $failedHandshakes[] = $connId;
        logSslHandshakeFailure($peerName, $connId, $errorMsg);
    }

    foreach ($completedHandshakes as $connId) {
        if (!isset($pendingHandshakes[$connId])) {
            continue;
        }
        $conn = $pendingHandshakes[$connId]['conn'];
        $connections[$connId] = $conn;
        $requestBuffers[$connId] = '';
        $connectionLastActivity[$connId] = \time();
        $postHandshakeReadPending[$connId] = [
            'conn' => $conn,
            'deadline' => \microtime(true) + 0.20,
        ];
        // OpenSSL may consume the first HTTP bytes into its user-space buffer
        // while finishing a handshake on WRITE readiness. libevent then sees
        // no kernel READ edge, so force one immediate non-blocking read in the
        // current loop instead of leaving the request parked until timeout.
        if (!\in_array($conn, $read, true)) {
            $read[] = $conn;
        }
        unset($pendingHandshakes[$connId], $handshakeStartTimes[$connId]);
    }

    foreach ($failedHandshakes as $connId) {
        if (isset($pendingHandshakes[$connId]['conn'])) {
            safeCloseStream($pendingHandshakes[$connId]['conn']);
        }
        unset(
            $pendingHandshakes[$connId],
            $handshakeStartTimes[$connId],
            $connections[$connId],
            $requestBuffers[$connId],
            $connectionLastActivity[$connId],
            $requestLogged[$connId]
        );
    }
}

/**
 * 将 writeBuffers 中的数据写入 SSL 流（非阻塞 fwrite，单连接每轮最多尝试若干次）。
 * 供事件循环在 Fiber tick 之后及早调用，减轻 SSE 与同 Worker 其它 HTTP 请求之间的写方向头阻塞。
 *
 * @param array<int|string, resource> $writableConnections
 * @param array<int|string, string> $writeBuffers
 */
function wlsSslFlushQueuedWrites(
    int $activeRequests,
    array &$writableConnections,
    array &$writeBuffers,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$pendingClose,
    array &$longLivedConnections
): void {
    $maxBytesPerConnectionPerLoop = 131072; // 128KB，分片推进上限
    // OpenSSL 会在内部按 TLS record 分片；PHP 层一次提交 64KB，避免
    // 中等响应反复 substr/复制剩余缓冲。每连接每轮仍受 128KB 总预算限制。
    $maxChunkPerWrite = 65536;
    foreach ($writableConnections as $connId => $conn) {
        if (!isset($writeBuffers[$connId]) || $writeBuffers[$connId] === '') {
            continue;
        }
        if (!\is_resource($conn) || !\in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
            unset($writeBuffers[$connId], $writableConnections[$connId]);
            continue;
        }

        $initialBufferLen = \strlen($writeBuffers[$connId]);
        $totalWrittenThisLoop = 0;
        $maxWriteAttempts = 16;
        $writeAttempts = 0;

        while (isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '' && $writeAttempts < $maxWriteAttempts) {
            $writeAttempts++;
            if (!\is_resource($conn) || !\in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
                safeCloseStream($conn);
                unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $pendingClose[$connId]);
                unset($longLivedConnections[$connId]);
                if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($initialBufferLen)) {
                    \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                }
                break;
            }
            $buffer = $writeBuffers[$connId];
            $bufferLen = \strlen($buffer);
            if ($totalWrittenThisLoop >= $maxBytesPerConnectionPerLoop) {
                // 单连接本轮写预算耗尽，交回事件循环，避免写阶段头阻塞。
                break;
            }
            $remainingBudget = $maxBytesPerConnectionPerLoop - $totalWrittenThisLoop;
            $writeLen = \min($bufferLen, $maxChunkPerWrite, $remainingBudget);
            if ($writeLen <= 0) {
                break;
            }

            $written = @\fwrite($conn, \substr($buffer, 0, $writeLen));

            if ($written === false) {
                WlsLogger::warning_("缓冲区写入失败 (connId: {$connId}, 剩余: {$bufferLen} 字节)");
                safeCloseStream($conn);
                unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $pendingClose[$connId]);
                unset($longLivedConnections[$connId]);
                if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($initialBufferLen)) {
                    \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                }
                break;
            }

            $connectionLastActivity[$connId] = \time();

            if ($written === 0) {
                break;
            }

            $totalWrittenThisLoop += $written;
            $writeBuffers[$connId] = \substr($buffer, $written);

            if ($writeBuffers[$connId] === '' || $writeBuffers[$connId] === false) {
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);

                if (isset($pendingClose[$connId])) {
                    safeCloseStream($conn);
                    unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $pendingClose[$connId]);
                    unset($longLivedConnections[$connId]);
                }
                if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($initialBufferLen)) {
                    \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                }
                wlsDrainPostResponseTasks($activeRequests, $requestBuffers, $writeBuffers, $connId);
                break;
            }
        }

    }
}

/**
 * 安全关闭 stream/socket 资源，避免重复关闭触发 warning。
 */
function safeCloseStream(mixed $conn): void
{
    if (!\is_resource($conn)) {
        return;
    }
    if (!\in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
        return;
    }

    $connId = \get_resource_id($conn);
    \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->close((string)$connId);
    $backtrace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    $caller = $backtrace[1] ?? [];
    $callerLine = ($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? '?');
    $peerName = @\stream_socket_get_name($conn, true);
    if (!\is_string($peerName) || $peerName === '') {
        $peerName = 'unknown-peer';
    }
    WlsLogger::debug_("关闭连接 {$connId} peer={$peerName} caller={$callerLine}");

    try {
        \fclose($conn);
    } catch (\Throwable $e) {
        // 连接关闭存在竞态（另一处分支已关闭），这里静默兜底，避免打断事件循环。
        if (!\str_contains($e->getMessage(), 'supplied resource is not a valid stream resource')) {
            throw $e;
        }
    }
}

/**
 * 统一记录握手失败日志：可预期失败降级为 info，保留关键告警信噪比。
 */
function logSslHandshakeFailure(string $peerName, int $connId, string $errorMsg): void
{
    // Scope: server-side inbound TLS handshakes only; Dispatcher/PassthroughCore TLS failures are outbound backend probes.
    $classification = \Weline\Server\Service\SslHandshakeFailureClassifier::classify($peerName, $connId, $errorMsg);
    if ($classification['level'] === 'info') {
        WlsLogger::info_($classification['message']);
        return;
    }

    WlsLogger::warning_($classification['message']);
}

/**
 * Fiber 请求开始前清理并初始化请求级上下文，避免前一请求残留污染当前 Fiber。
 */
function wlsFiberRequestContextEnter(mixed $conn, int|string|null $connectionId = null): void
{
    // 关键修复：Fiber 启动时必须完全重置所有请求级状态，防止复用上一个 Fiber 的残留状态
    // 这是 WLS 多 Fiber 并发的核心隔离点：每个新 Fiber 必须从干净的全局状态开始
    \Weline\Framework\Runtime\StateManager::reset();

    \Weline\Framework\Runtime\RequestContext::cleanup();
    \Weline\Framework\Http\Url::resetWlsFiberInterleavedParserScratch();
    \Weline\Framework\Http\Sse\SseContext::reset();
    \Weline\Framework\Http\Sse\SseContext::setConnection($conn);
    \Weline\Framework\Http\Sse\SseContext::clearWriteCallback();
    \Weline\Framework\Http\Sse\SseContext::clearAliveCallback();

    $resolvedConnectionId = $connectionId;
    if ($resolvedConnectionId === null && \is_resource($conn)) {
        $resolvedConnectionId = \get_resource_id($conn);
    }

    $context = \Weline\Framework\Context::current();
    $context->set('meta.type', 'request');
    $context->set('meta.mode', 'wls');
    $context->set('runtime.connection_id', $resolvedConnectionId === null ? '' : (string)$resolvedConnectionId);
    $context->set('runtime.chain_id', $resolvedConnectionId === null ? '' : (string)$resolvedConnectionId);
    $context->setRuntimeAttr('connection_id', $resolvedConnectionId === null ? '' : (string)$resolvedConnectionId);
    $context->setRuntimeAttr('chain_id', $resolvedConnectionId === null ? '' : (string)$resolvedConnectionId);
    \Weline\Framework\Runtime\RequestContext::setConnectionId($resolvedConnectionId === null ? null : (string)$resolvedConnectionId);
}

/**
 * 将 SSE 数据接入 worker 现有的非阻塞写缓冲，并协作等待缓冲区排空。
 */
function wlsSslIsSseClientConnected(
    int $connId,
    mixed $conn,
    array &$connections,
    array &$pendingClose
): bool {
    if (isset($pendingClose[$connId])) {
        return false;
    }
    if (!isset($connections[$connId]) || $connections[$connId] !== $conn) {
        return false;
    }
    if (!\is_resource($conn) || !\in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
        return false;
    }

    $meta = @\stream_get_meta_data($conn);
    if ($meta === false || ($meta['eof'] ?? false) || ($meta['timed_out'] ?? false)) {
        return false;
    }

    if (!\function_exists('stream_select')) {
        return true;
    }

    $read = [$conn];
    $write = [];
    $except = [$conn];
    $changed = @\stream_select($read, $write, $except, 0, 0);
    if ($changed === false) {
        return true;
    }
    if ($except !== []) {
        return false;
    }
    if ($changed === 0 || $read === []) {
        return true;
    }

    return !@\feof($conn);
}

function enqueueSseWriteAndAwaitDrain(
    int $connId,
    mixed $conn,
    string $data,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose
): bool {
    if ($data === '') {
        return true;
    }

    // 防止响应污染：验证 $connections[$connId] 仍然是本 Fiber 持有的原始连接。
    // PHP 回收 stream 后 resource ID 可被新连接复用，若不校验同一性，
    // SSE Fiber 的写回调会把数据追加到新请求的 writeBuffer，导致正常页面收到 SSE 流。
    if (isset($connections[$connId]) && $connections[$connId] !== $conn) {
        return false;
    }

    $streamOk = isset($connections[$connId])
        && \is_resource($conn)
        && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true);

    if (!$streamOk) {
        if (\is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
            safeCloseStream($conn);
        }
        unset(
            $connections[$connId],
            $requestBuffers[$connId],
            $connectionLastActivity[$connId],
            $requestLogged[$connId],
            $writeBuffers[$connId],
            $writableConnections[$connId],
            $pendingClose[$connId]
        );
        return false;
    }

    $currentBuffered = \strlen($writeBuffers[$connId] ?? '');
    $appendLen = \strlen($data);
    if (\Weline\Server\Service\WorkerResponseMemoryGuard::sseWriteBufferWouldExceed($currentBuffered, $appendLen)) {
        WlsLogger::warning_(
            'SSE 写缓冲超限，关闭连接 (connId: ' . $connId
            . ', buffered=' . $currentBuffered . ', append=' . $appendLen . ')'
        );
        safeCloseStream($conn);
        unset(
            $connections[$connId],
            $requestBuffers[$connId],
            $connectionLastActivity[$connId],
            $requestLogged[$connId],
            $writeBuffers[$connId],
            $writableConnections[$connId],
            $pendingClose[$connId]
        );

        return false;
    }

    $writeBuffers[$connId] = ($writeBuffers[$connId] ?? '') . $data;
    $writableConnections[$connId] = $conn;
    $connectionLastActivity[$connId] = \time();
    return true;
}

/**
 * SSL Worker：请求处理完成后写回响应（与同步路径一致，供 Fiber 同步完成与 tick 恢复后调用）。
 * $response 须已含 injectWlsProcessTimeHeader。
 *
 * @param mixed $ipcClient Control client 或 null
 */
function wlsDrainAfterResponseIfRequested(
    mixed &$socket,
    bool &$shouldExit,
    bool &$ipcDraining,
    int &$drainStartTime,
    int &$maxDrainTime
): void {
    $reason = \Weline\Server\Service\WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
    if ($reason === null) {
        return;
    }

    WlsLogger::warning_("Worker requested drain after response: {$reason}");
    $shouldExit = true;
    $ipcDraining = true;
    $drainStartTime = \time();
    $maxDrainTime = \min($maxDrainTime, 10);
    if ($socket && \is_resource($socket)) {
        @\fclose($socket);
        $socket = null;
        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
    }
}

function sslFinalizeHttpResponseAfterHandle(
    mixed $conn,
    int $connId,
    string $rawRequest,
    string $response,
    float $handleStartTime,
    bool $isSseProtocolRequest,
    bool $ipcDraining,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose,
    array &$longLivedConnections,
    mixed $ipcClient,
    string $instanceName,
    int &$activeRequests,
    bool $recordObservability = true,
    ?string $precomputedRequestHost = null,
    ?bool $precomputedKeepAlive = null,
    bool $trustedCacheHit = false,
): void {
    $response = wlsDecorateFormattedBenchmarkWorkerIdentity($response, $rawRequest);

    $responseStatus = 200;
    if (!$trustedCacheHit && \preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $response, $statusMatches)) {
        $responseStatus = (int) $statusMatches[1];
    }

    if ($responseStatus === 400) {
        $requestLine = '';
        if (\preg_match('/^([^\r\n]+)/', $rawRequest, $lineMatches)) {
            $requestLine = (string) ($lineMatches[1] ?? '');
        }
        WlsLogger::warning_("HTTP 400 响应 (connId: {$connId}, 请求: {$requestLine})");
    }
    if ($responseStatus >= 500) {
        $requestLine = '';
        if (\preg_match('/^([^\r\n]+)/', $rawRequest, $lineMatches)) {
            $requestLine = (string) ($lineMatches[1] ?? '');
        }
    }
    $responseBytes = 0;
    $requestHost = $precomputedRequestHost ?? (getHeaderValue($rawRequest, 'Host') ?? '');
    if (\str_contains($requestHost, ':')) {
        $requestHost = (string) \explode(':', $requestHost, 2)[0];
    }

    $activeRequests = \max(0, $activeRequests - 1);

    $responseLenPre = \strlen($response);
    if ($recordObservability) {
        WlsLogger::debug_("Worker 即将写回响应 connId={$connId} len={$responseLenPre}");
    }

    $hasQueuedSsePayload = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';
    $actualSseStarted = $isSseProtocolRequest
        && (
            \Weline\Framework\Http\Sse\SseContext::isSseEnabled()
            || \Weline\Framework\Http\Sse\SseContext::isHeadersSent()
        );
    if ($isSseProtocolRequest && !$actualSseStarted && $response !== '') {
        $statusLine = \trim((string) (\strtok($response, "\r\n") ?: ''));
        WlsLogger::warning_(
            'SSE 路径未实际启动流式响应，普通响应将按 HTTP 回写 (connId: '
            . $connId . ', status: ' . $statusLine . ', len: ' . \strlen($response) . ')'
        );
    }
    // SSE 收尾兜底：上下文标记可能先于写队列排空被重置，此时仍必须按 SSE 分支处理。
    $isSseMode = $actualSseStarted || ($isSseProtocolRequest && $hasQueuedSsePayload);
    $keepAlive = $precomputedKeepAlive ?? isKeepAlive($rawRequest);
    $bufferedBytesBeforeWrite = isset($writeBuffers[$connId]) ? \strlen($writeBuffers[$connId]) : 0;
    $forceCloseAfterResponse = \Weline\Server\Service\WorkerResponseMemoryGuard::shouldForceConnectionClose(
        $keepAlive,
        $isSseMode,
        $responseLenPre,
        $bufferedBytesBeforeWrite
    );
    if ($forceCloseAfterResponse && !$isSseMode) {
        $response = \Weline\Server\Service\WorkerResponseMemoryGuard::forceConnectionCloseHeader($response);
    }

    $responseFullyWritten = false;
    if (!$isSseMode) {
        $responseLen = \strlen($response);
        $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';

        if ($hasBufferedData) {
            // 非 SSE 响应遇到缓冲区有残留数据时：直接覆盖，不再追加。
            // 防止前一个 SSE 连接关闭后缓冲区残留 SSE 数据碎片被拼到普通 HTTP 响应前面。
            $writeBuffers[$connId] = $response;
            $writableConnections[$connId] = $conn;
            if ($recordObservability) {
                WlsLogger::debug_("Worker 响应覆盖缓冲区（替换残留） connId={$connId} len={$responseLen}");
            }
            goto ssl_finalize_skip_write;
        }

        $totalWritten = 0;
        $streamOk = \is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true);
        if (!$streamOk) {
            unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId]);
            \Weline\Framework\Http\Sse\SseContext::reset();

            return;
        }

        $headerEnd = \strpos($response, "\r\n\r\n");
        $headerBytes = $headerEnd === false ? 0 : $headerEnd + 4;
        $configuredImmediateBytes = $recordObservability
            ? (int)(\Weline\Framework\App\Env::get('wls.ssl.immediate_response_write_bytes', 32768) ?: 32768)
            // 可信 Static/FPC 快路径允许在既有公平上限内一次写完常见缓存页，
            // 避免 64 KiB 边界把约 70 KiB 的首页人为拆到下一轮事件循环。
            : 131072;
        $immediateBudget = \max(8192, \min(131072, $configuredImmediateBytes));
        if ($headerBytes > 0) {
            $immediateBudget = \max($immediateBudget, \min($responseLen, $headerBytes + 8192));
        }
        $immediateBudget = \min($responseLen, $immediateBudget);
        // Submit a large contiguous buffer to OpenSSL. The TLS layer already
        // splits it into protocol records; forcing 16 KiB PHP writes makes a
        // 70-128 KiB cached page pay four to eight userland write cycles and,
        // on Darwin direct sockets, can expose delayed-ACK sized gaps between
        // records. Keep the fairness ceiling at 128 KiB while matching the
        // queued-write path's 64 KiB submission size.
        $maxImmediateChunk = 65536;

        while ($totalWritten < $immediateBudget) {
            $remainingBudget = $immediateBudget - $totalWritten;
            $writeLen = \min($maxImmediateChunk, $remainingBudget);
            $written = @\fwrite($conn, \substr($response, $totalWritten, $writeLen));

            if ($written === false) {
                safeCloseStream($conn);
                unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId]);
                \Weline\Framework\Http\Sse\SseContext::reset();

                return;
            }

            if ($written === 0) {
                break;
            }

            $totalWritten += $written;
        }

        if ($totalWritten >= $responseLen) {
            if ($recordObservability) {
                WlsLogger::debug_("Worker 已写完响应 connId={$connId} written={$totalWritten}");
            }
            $responseBytes = $totalWritten;
            $responseFullyWritten = true;
            goto ssl_finalize_skip_write;
        }

        $responseBytes = $totalWritten;
        $writeBuffers[$connId] = \substr($response, $totalWritten);
        $writableConnections[$connId] = $conn;
        if ($recordObservability) {
            WlsLogger::debug_(
                'Worker 响应入队 connId=' . $connId . ' written=' . $totalWritten . ' total=' . $responseLen
                . ' remaining=' . ($responseLen - $totalWritten)
            );
        }

        ssl_finalize_skip_write:
    } else {
        WlsLogger::info_("SSE 流式响应完成 (connId: {$connId})");
    }

    \Weline\Framework\Http\Sse\SseContext::reset();
    $connectionLastActivity[$connId] = \time();
    $handleDurationMs = (float) \round((\microtime(true) - $handleStartTime) * 1000, 2);

    if ($recordObservability) {
        // 浏览器文档请求慢时输出明确慢日志（method/uri/status/耗时），便于直接对照 DevTools waterfall。
        $slowThresholdMs = (float) (\Weline\Framework\App\Env::get('wls.slow_request_threshold_ms', 1000) ?: 1000);
        if ($handleDurationMs >= $slowThresholdMs) {
            $requestLine = '';
            if (\preg_match('/^([A-Z]+)\s+([^\s]+)\s+HTTP\/\d\.\d/i', $rawRequest, $matches)) {
                $requestLine = (string) ($matches[1] ?? '') . ' ' . (string) ($matches[2] ?? '');
            }
            WlsLogger::warning_(
                "Slow request detected (worker=https, connId={$connId}, status={$responseStatus}, "
                . "duration_ms={$handleDurationMs}, host={$requestHost}, request=\"{$requestLine}\")"
            );
        }

        \Weline\Server\Service\Telemetry\WorkerTelemetryReporter::instance($instanceName)->record(
            $ipcClient instanceof \Weline\Server\IPC\ChildControl\ChildControlClientInterface ? $ipcClient : null,
            $requestHost,
            $responseStatus,
            (int)$handleDurationMs,
            $responseBytes,
        );

        WlsLogger::tick_();
    }

    if ($recordObservability && !$isSseMode && $responseFullyWritten) {
        wlsDrainPostResponseTasks($activeRequests, $requestBuffers, $writeBuffers, $connId);
    }

    $responseRequestsClose = !$trustedCacheHit
        && \Weline\Server\Service\WorkerResponseMemoryGuard::responseRequestsConnectionClose($response);
    $shouldClose = $isSseMode || !$keepAlive || $ipcDraining || $forceCloseAfterResponse || $responseRequestsClose;
    if ($shouldClose) {
        $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';

        if ($hasBufferedData) {
            $pendingClose[$connId] = true;
        } else {
            safeCloseStream($conn);
            unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId]);
            if (isset($longLivedConnections[$connId])) {
                unset($longLivedConnections[$connId]);
            }
            if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($responseLenPre)) {
                \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
            }
        }
    }
}

function handleRequest(
    string $rawRequest,
    ?\Weline\Framework\Runtime\WlsRuntime $runtime,
    ?string $runtimeError,
    \Weline\Server\Runtime\Async\AsyncBizAdapters $asyncBizAdapters,
    string $instanceName,
    int $workerId,
    int $port,
    int $requestCount,
    int $activeRequests,
    int $connectionCount,
    int $startTime,
    string $originToken,
    bool $originTokenValidationEnabled,
    string $originTokenHeader,
    bool $originTokenAllowLocal,
    string $transportPeer = '',
    ?\Weline\Server\Security\WorkerPolicyDecision $precomputedPolicyDecision = null
): string {
    $policyDecision = $precomputedPolicyDecision
        ?? \Weline\Server\Security\WorkerPolicyKernel::instance()->evaluate($rawRequest, $transportPeer);
    if (!$policyDecision->allowed) {
        return (string)$policyDecision->response;
    }
    $policyServerInfo = $policyDecision->requestServerInfo();

    $uri = $policyDecision->path;
    $method = $policyDecision->method;
    
    $clientIp = $policyDecision->clientIp;
    
    // ========== 健康检查接口（仅本地访问，不受维护模式影响） ==========
    if ($method === 'GET' && $uri === '/_wls/health') {
        $keepAlive = $policyDecision->keepAlive();
        if (!\Weline\Server\Service\WorkerHealthAccessPolicy::instance($instanceName)->allowsClient(
            $clientIp,
            $policyDecision->headers,
        )) {
            // 非本地请求且未配置允许且无有效放行 Cookie：返回 403（极简响应）
            return $keepAlive
                ? "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: keep-alive\r\n\r\nForbidden"
                : "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: close\r\n\r\nForbidden";
        }
        
        // 高性能健康检查：使用极简响应，避免 json_encode/memory_get_usage 开销
        // 完整信息可通过 /_wls/health?detail=1 获取
        $wantsDetail = \strpos($rawRequest, 'detail=1') !== false || \strpos($rawRequest, 'detail=true') !== false;
        $wantsMemory = \strpos($rawRequest, 'memory=1') !== false || \strpos($rawRequest, 'memory=true') !== false;
        $wantsStaticMemory = \strpos($rawRequest, 'static=1') !== false || \strpos($rawRequest, 'static=true') !== false;
        $wantsObjectMemory = \strpos($rawRequest, 'objects=1') !== false || \strpos($rawRequest, 'objects=true') !== false;
        
        if ($wantsDetail) {
            // 详细模式：返回完整信息
            $health = [
                'status' => 'healthy',
                'instance' => $instanceName,
                'worker_id' => $workerId,
                'port' => $port,
                'connections' => $connectionCount,
                'active_requests' => $activeRequests - 1,
                'total_requests' => $requestCount,
                'memory_usage' => \memory_get_usage(true),
                'memory_usage_used' => \memory_get_usage(false),
                'memory_peak' => \memory_get_peak_usage(true),
                'memory_peak_used' => \memory_get_peak_usage(false),
                'uptime' => \time() - $startTime,
                'php_version' => PHP_VERSION,
                'ssl' => true,
                'timestamp' => \time(),
            ];
            if ($wantsMemory) {
                $health['memory_diagnostics'] = wlsWorkerMemoryHealthDiagnostics($wantsStaticMemory, $wantsObjectMemory);
            }
            $body = \json_encode($health, JSON_UNESCAPED_UNICODE);
            $len = \strlen($body);
            return $keepAlive
                ? "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$len}\r\nConnection: keep-alive\r\n\r\n{$body}"
                : "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$len}\r\nConnection: close\r\n\r\n{$body}";
        }
        
        // 极简模式（默认）：直接返回静态字符串，最大性能
        return $keepAlive
            ? "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nOK"
            : "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK";
    }
    // ========== 健康检查接口结束 ==========

    // ========== ACME HTTP-01 校验（WLS 虚拟：从 generated/acme-http01 按域名返回 keyAuth，验证完由证书流程删除） ==========
    if ($method === 'GET' && \preg_match('#^/\.well-known/acme-challenge/([^/]+)/?$#', $uri, $acmeMatches)) {
        $requestToken = $acmeMatches[1];
        $hostHeader = \trim((string)(getHeaderValue($rawRequest, 'Host') ?? ''));
        if (\strpos($hostHeader, ':') !== false) {
            $hostHeader = \trim((string)\explode(':', $hostHeader, 2)[0]);
        }
        $safeDomain = \preg_replace('/[^a-z0-9_]/', '', \str_replace('.', '_', \strtolower($hostHeader)));
        $safeDomain = $safeDomain !== '' ? $safeDomain : 'default';
        if (\defined('BP')) {
            $acmeFile = \rtrim(BP, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'generated' . \DIRECTORY_SEPARATOR . 'acme-http01' . \DIRECTORY_SEPARATOR . $safeDomain . '.json';
            if (\is_file($acmeFile)) {
                $json = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($acmeFile);
                if ($json !== false) {
                    $data = \json_decode($json, true);
                    if (\is_array($data) && isset($data['keyAuth']) && \is_string($data['keyAuth'])
                        && (string)($data['token'] ?? '') === (string)$requestToken) {
                        $body = $data['keyAuth'];
                        $len = \strlen($body);
                        $keepAlive = $policyDecision->keepAlive();
                        return $keepAlive
                            ? "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=UTF-8\r\nCache-Control: no-store\r\nContent-Length: {$len}\r\nConnection: keep-alive\r\n\r\n{$body}"
                            : "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=UTF-8\r\nCache-Control: no-store\r\nContent-Length: {$len}\r\nConnection: close\r\n\r\n{$body}";
                    }
                }
            }
        }
        $notFoundBody = 'ACME challenge not found';
        return "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Length: " . \strlen($notFoundBody) . "\r\nConnection: close\r\n\r\n{$notFoundBody}";
    }
    // ========== ACME HTTP-01 校验结束 ==========

    // ========== 静态文件处理（WLS 模式特有） ==========
    $staticFileStart = \microtime(true);
    $staticResponse = $policyDecision->staticProcessCacheEnabled()
        ? handleStaticFile($uri, $rawRequest)
        : null;
    if ($staticResponse !== null) {
        $cacheInfo = \Weline\Server\Service\WlsWorkerGlobals::getLastStaticCache();
        $cacheStatus = $cacheInfo['status'] ?? 'miss';
        $cacheUri = $cacheInfo['uri'] ?? $uri;
        WlsLogger::info_(__('静态文件缓存: %{1} %{2}', [\strtoupper($cacheStatus), $cacheUri]));
        if (\function_exists('wlsDecorateFormattedStaticResponseForPerformancePanel')) {
            $staticResponse = wlsDecorateFormattedStaticResponseForPerformancePanel(
                $staticResponse,
                $rawRequest,
                (\microtime(true) - $staticFileStart) * 1000,
                $workerId,
                $port,
                \is_array($cacheInfo) ? $cacheInfo : []
            );
        }
        return $staticResponse;
    }
    // ========== 静态文件处理结束 ==========
    
    // 如果运行时初始化失败，返回错误
    if ($runtime === null) {
        return \Weline\Server\Service\Runtime\WorkerRuntimeFailureResponse::create($runtimeError, [
            'instance' => $instanceName,
            'worker_id' => $workerId,
            'port' => $port,
            'transport' => 'https_stream',
        ]);
    }
    
    WlsLogger::info_("准备进入框架处理: {$method} {$uri}");
    try {
        // 创建 WLS 请求对象（框架会自动处理维护模式）
        $request = \Weline\Framework\Http\WlsRequest::fromEnvelope($policyDecision->requestEnvelope(), $policyServerInfo + [
            'WLS_INSTANCE' => $instanceName,
            'WLS_WORKER_ID' => $workerId,
            'WLS_PORT' => $port,
            'WLS_REQUEST_COUNT' => $requestCount,
            'HTTPS' => 'on',
            'REQUEST_SCHEME' => 'https',
        ]);
        $result = $asyncBizAdapters->dispatch(
            static fn() => $runtime->handle($request)
        );
        wlsResetLongRunningExecutionLimit();
        
        // 释放 PHP Session 文件锁
        // 在 WLS 常驻进程模式下，session_start() 会锁定 session 文件
        // 必须在请求处理完成后立即释放锁，否则同一 session 的并发请求会被阻塞
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
        
        // WLS 模式下控制器通过 return 返回 body；对 body trim 并可从 JSON 的 code 解析出状态码
        if (\is_string($result) && \str_starts_with($result, 'HTTP/')) {
            // 合并 Runtime 保存的 Cookie（在 StateManager reset 前提取的副本）
            // 若 302 已在 WlsRuntime 中带上了 Set-Cookie，则不再合并，避免重复头导致浏览器异常
            $headerEnd = \strpos($result, "\r\n\r\n");
            $alreadyHasSetCookie = $headerEnd !== false && \stripos(\substr($result, 0, $headerEnd), 'Set-Cookie:') !== false;
            $pendingCookies = $runtime->consumePendingCookies();
            if (!empty($pendingCookies) && !$alreadyHasSetCookie && $headerEnd !== false) {
                $cookieHeaders = '';
                foreach ($pendingCookies as $cookie) {
                    $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];
                    if (isset($cookie['expire']) && $cookie['expire'] !== 0) { $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']); }
                    if (!empty($cookie['path']))     { $parts[] = 'Path=' . $cookie['path']; }
                    if (!empty($cookie['domain']))   { $parts[] = 'Domain=' . $cookie['domain']; }
                    if (!empty($cookie['secure']))   { $parts[] = 'Secure'; }
                    if (!empty($cookie['httpOnly'])) { $parts[] = 'HttpOnly'; }
                    if (!empty($cookie['sameSite'])) { $parts[] = 'SameSite=' . $cookie['sameSite']; }
                    $cookieHeaders .= 'Set-Cookie: ' . \implode('; ', $parts) . "\r\n";
                }
                $bodyPart = \substr($result, $headerEnd + 4);
                $headerPart = \rtrim(\substr($result, 0, $headerEnd), "\r\n");
                $cookieHeaders = \rtrim($cookieHeaders, "\r\n");
                if ($cookieHeaders !== '') {
                    $headerPart .= "\r\n" . $cookieHeaders;
                }
                $result = $headerPart . "\r\n\r\n" . $bodyPart;
            }
            $sni = \Weline\Server\Service\RouteHintService::extractSniFromHeaders($policyDecision->headers);
            $result = \Weline\Server\Service\RouteHintService::addHintToResponse($result, $sni);
            $headerEnd = \strpos($result, "\r\n\r\n");
            if ($headerEnd !== false) {
                $headersPart = \substr($result, 0, $headerEnd);
                $bodyPart = \substr($result, $headerEnd + 4);
                if (\preg_match('/^Content-Length:\s*(\d+)/mi', $headersPart, $m)) {
                    $contentLength = (int)($m[1] ?? 0);
                    $bodyLen = \strlen($bodyPart);
                    if ($bodyLen > $contentLength) {
                        if (\str_starts_with($bodyPart, "\r\n") && ($bodyLen - 2) === $contentLength) {
                            $bodyPart = \substr($bodyPart, 2);
                        } elseif (\str_starts_with($bodyPart, "\n") && ($bodyLen - 1) === $contentLength) {
                            $bodyPart = \substr($bodyPart, 1);
                        }
                        $result = $headersPart . "\r\n\r\n" . $bodyPart;
                    }
                }
            }
            // HEAD 请求只返回头，不返回 body
            $acceptEncoding = $request->getHeader('Accept-Encoding');
            if ($acceptEncoding && \is_string($acceptEncoding)) {
                $result = wlsCompressFormattedHttpResponse($result, $acceptEncoding);
            }
            if (\strtoupper($method) === 'HEAD') {
                $headerEnd = \strpos($result, "\r\n\r\n");
                if ($headerEnd !== false) {
                    $result = \substr($result, 0, $headerEnd + 4);
                }
            }
            return $result;
        }
        $result = \is_string($result) ? $result : (string) $result;
        $pendingResponseStatus = $runtime->consumePendingResponseStatus();
        $statusCode = (new \Weline\Server\Service\ResponseStatusResolver())->resolve(
            $result,
            $pendingResponseStatus['status_code'] ?? null,
            (bool) ($pendingResponseStatus['explicit'] ?? false)
        );
        $response = \Weline\Framework\Http\Response::fromContent($result, $statusCode);
        
        // WLS 模式核心：将 Runtime 保存的 Cookie/Header 合并进 HTTP 响应
        // 框架内部（Session、Cookie 类等）通过 HeaderCollector 设置响应头和 Cookie，
        // 但 WLS 模式下 PHP 内置的 header()/setcookie() 无效。
        // WlsRuntime 在 StateManager 重置前将 HeaderCollector 副本保存到 pendingCookies/pendingHeaders。
        $pendingCookies2 = $runtime->consumePendingCookies();
        foreach ($pendingCookies2 as $cookie) {
            $response->setCookie(
                (string)$cookie['name'],
                (string)$cookie['value'],
                (int)($cookie['expire'] ?? 0),
                (string)($cookie['path'] ?? '/'),
                (string)($cookie['domain'] ?? ''),
                (bool)($cookie['secure'] ?? false),
                (bool)($cookie['httpOnly'] ?? true),
                (string)($cookie['sameSite'] ?? 'Lax')
            );
        }
        $pendingHeaders2 = $runtime->consumePendingHeaders();
        foreach ($pendingHeaders2 as $name => $value) {
            if (\is_string($value)) { $response->setHeader($name, $value); }
        }
        
        // 添加路由提示头（用于 TCP 透传模式下的智能路由）
        $sni = \Weline\Server\Service\RouteHintService::extractSniFromHeaders($policyDecision->headers);
        \Weline\Server\Service\RouteHintService::addHintToFrameworkResponse($response, $sni);

        $acceptEncoding = $request->getHeader('Accept-Encoding');
        if ($acceptEncoding && \is_string($acceptEncoding)) {
            $response->compress($acceptEncoding);
        }

        $responseLocation = (string)($response->getHeader('Location') ?? '');
        if ($responseLocation !== '') {
            $response->setHeader('Location', appendBackendLoginReturnUrl(
                $responseLocation,
                $request,
                $method,
                $policyDecision->target,
            ));
        }

        $responseBody = (string)$response->getBody();
        $responseContentType = \strtolower((string)($response->getHeader('Content-Type') ?? ''));
        $responseLocation = (string)($response->getHeader('Location') ?? '');
        $isExpectedEmptyResponse = \strtoupper($method) === 'HEAD'
            || \in_array($statusCode, [204, 205, 304], true)
            || $responseLocation !== ''
            || \str_contains($responseContentType, 'text/event-stream');
        if ($responseBody === '' && !$isExpectedEmptyResponse) {
            $responseRequestId = (string)($response->getHeader('X-Weline-Request-Id') ?? '');
            $responseContentLength = (string)($response->getHeader('Content-Length') ?? '');
            $requestAccept = $request->getHeader('Accept');
            $requestAccept = \is_array($requestAccept) ? \implode(',', $requestAccept) : (string)$requestAccept;
            $router = \method_exists($request, 'getRouter') ? (array)$request->getRouter() : [];
            $lang = '';
            $langLocal = '';
            $currency = '';
            try {
                $lang = (string)\Weline\Framework\App\State::getLang();
                $langLocal = (string)\Weline\Framework\App\State::getLangLocal();
                $currency = (string)\Weline\Framework\App\State::getCurrency();
            } catch (\Throwable) {
            }
            WlsLogger::error_(
                '[UnexpectedEmptyResponse] method=' . $method
                . ' uri=' . ($request->getUri() ?: ($request->getServer('REQUEST_URI') ?? ''))
                . ' status=' . $statusCode
                . ' request_id=' . ($responseRequestId !== '' ? $responseRequestId : '(empty)')
                . ' body_len=' . \strlen($responseBody)
                . ' content_length=' . ($responseContentLength !== '' ? $responseContentLength : '(empty)')
                . ' content_type=' . ($responseContentType !== '' ? $responseContentType : '(empty)')
                . ' location=' . ($responseLocation !== '' ? $responseLocation : '(none)')
                . ' lang=' . ($lang !== '' ? $lang : '(empty)')
                . ' lang_local=' . ($langLocal !== '' ? $langLocal : '(empty)')
                . ' currency=' . ($currency !== '' ? $currency : '(empty)')
                . ' router_module=' . (string)($router['module'] ?? '')
                . ' router_controller=' . (string)($router['controller'] ?? '')
                . ' router_action=' . (string)($router['action'] ?? '')
                . ' accept=' . ($requestAccept !== '' ? $requestAccept : '(empty)')
                . ' worker_id=' . $workerId
                . ' worker_port=' . $port
            );
        }
        
        $httpString = $response->toHttpString($request->isKeepAlive());
        
        // HTTP 规范：HEAD 请求应该返回与 GET 请求相同的响应头，但不返回响应体
        // Content-Length 头部应该保留，告知客户端如果是 GET 请求会返回多大的内容
        if (\strtoupper($method) === 'HEAD') {
            $headerEnd = \strpos($httpString, "\r\n\r\n");
            if ($headerEnd !== false) {
                // 只保留响应头部分（包括末尾的 \r\n\r\n）
                $httpString = \substr($httpString, 0, $headerEnd + 4);
            }
        }
        
        return $httpString;
        
    } catch (\Throwable $e) {
        // 302 等响应终止为正常控制流，不记错误
        if (!$e instanceof \Weline\Framework\Http\ResponseTerminateException) {
            WlsLogger::error_("请求处理错误: " . $e->getMessage() . " (文件: " . $e->getFile() . ":" . $e->getLine() . ")");
            w_log_error('[WLS Worker SSL] Request error: ' . $e->getMessage());
        }

        $statusCode = 500;
        $errorMessage = $e->getMessage() ?: 'Internal Server Error';
        
        if ($e instanceof \Weline\Framework\App\Exception) {
            $code = $e->getCode();
            if ($code >= 400 && $code < 600) {
                $statusCode = $code;
            }
        }
        
        $isDev = \defined('DEV') && DEV;
        if ($isDev || (\defined('DEBUG') && DEBUG)) {
            $errorBody = \json_encode([
                'error' => true,
                'message' => $errorMessage,
                'exception' => \get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => \explode("\n", $e->getTraceAsString()),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } else {
            // 生产模式：非 App\Exception 不暴露内部错误细节
            $safeMessage = ($e instanceof \Weline\Framework\App\Exception) ? $errorMessage : 'Internal Server Error';
            $errorBody = \json_encode([
                'error' => true,
                'message' => $safeMessage,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        if ($errorBody === false) {
            $errorBody = '{"error":true,"message":"JSON encode failed"}';
        }
        
        $response = \Weline\Framework\Http\Response::fromContent($errorBody, $statusCode, 'application/json; charset=utf-8');
        
        // 异常情况下也要释放 Session 锁
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
        
        return $response->toHttpString(false);
    } finally {
        wlsResetLongRunningExecutionLimit();
    }
}

/**
 * 处理静态文件请求（WLS 模式特有）
 * 
 * 在 WLS 模式下，PHP 的 header() 和 readfile() 不起作用，
 * 需要在 Worker 层面直接读取文件并返回 HTTP 响应字符串。
 * 
 * 内存缓存策略：
 * - 小于配置阈值的文件缓存到内存，避免重复读取磁盘
 * - 缓存有效期 7 天（基于文件修改时间验证）
 * - 大于配置阈值的文件直接从磁盘读取（避免内存占用过大）
 * 
 * H13: 修复 Content-Length mismatch
 * - 根据客户端请求设置正确的 Connection 头
 * - 支持 Range 请求用于大文件断点续传
 * 
 * @param string $uri 请求 URI
 * @param string $rawRequest 原始请求（用于获取 If-Modified-Since 等头部）
 * @return string|null 如果是静态文件则返回 HTTP 响应字符串，否则返回 null
 */
function appendBackendLoginReturnUrl(
    string $redirectUrl,
    \Weline\Framework\Http\Request $request,
    string $method,
    string $requestTarget,
): string
{
    $method = \strtoupper($method);
    if ($method !== 'GET' && $method !== 'HEAD') {
        return $redirectUrl;
    }

    $redirectPath = (string)(\parse_url($redirectUrl, PHP_URL_PATH) ?: '');
    $normalizedRedirectPath = \strtolower($redirectPath);
    if ($normalizedRedirectPath === ''
        || !\str_ends_with($normalizedRedirectPath, '/admin/login')
    ) {
        return $redirectUrl;
    }

    $uri = $requestTarget;
    if ($uri === '') {
        $uri = (string)($request->getServer('WELINE_ORIGIN_REQUEST_URI') ?: $request->getServer('REQUEST_URI'));
    }
    $queryString = (string)$request->getServer('QUERY_STRING');
    if ($queryString !== '' && !\str_contains($uri, '?')) {
        $uri .= '?' . $queryString;
    }
    if ($uri === '') {
        return $redirectUrl;
    }

    $currentPath = \strtolower((string)(\parse_url($uri, PHP_URL_PATH) ?: ''));
    if ($currentPath === ''
        || \str_ends_with($currentPath, '/admin/login')
        || \str_ends_with($currentPath, '/admin/login/post')
        || \str_ends_with($currentPath, '/admin/login/logout')
    ) {
        return $redirectUrl;
    }

    $backendPrefix = \substr($redirectPath, 0, -\strlen('/admin/login'));
    $uriPath = (string)(\parse_url($uri, PHP_URL_PATH) ?: '');
    if ($backendPrefix !== '' && $uriPath !== '' && !\str_starts_with($uriPath, $backendPrefix . '/')) {
        $uri = $backendPrefix . (\str_starts_with($uri, '/') ? $uri : '/' . $uri);
    }
    $uri = normalizeBackendReturnUri($uri);

    $scheme = $request->isSecure() ? 'https' : 'http';
    $host = (string)($request->getServer('HTTP_HOST') ?: $request->getServer('SERVER_NAME') ?: 'localhost');
    $returnUrl = $scheme . '://' . $host . (\str_starts_with($uri, '/') ? $uri : '/' . $uri);
    $query = [
        'no_access_reason' => 'not_logged_in',
        'return_url' => $returnUrl,
    ];

    $redirectUrl = removeBackendLoginReturnParams($redirectUrl);
    return $redirectUrl . (\str_contains($redirectUrl, '?') ? '&' : '?') . \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function normalizeBackendReturnUri(string $uri): string
{
    $path = (string)(\parse_url($uri, PHP_URL_PATH) ?: '');
    if ($path === '') {
        return $uri;
    }

    $segments = \explode('/', \trim($path, '/'));
    $firstSegment = (string)($segments[0] ?? '');
    if (!isset($segments[1], $segments[2], $segments[3])
        || $firstSegment === ''
        || !isBackendReturnCurrencySegment($segments[1])
        || !isBackendReturnLocaleSegment($segments[2])
        || $segments[3] !== $firstSegment
    ) {
        return $uri;
    }

    \array_splice($segments, 3, 1);
    $normalized = '/' . \implode('/', $segments);
    $query = (string)(\parse_url($uri, PHP_URL_QUERY) ?: '');
    $fragment = (string)(\parse_url($uri, PHP_URL_FRAGMENT) ?: '');
    return $normalized . ($query !== '' ? '?' . $query : '') . ($fragment !== '' ? '#' . $fragment : '');
}

function isBackendReturnCurrencySegment(string $segment): bool
{
    return \Weline\Framework\App\State::isAllowedCurrencyCode($segment);
}

function isBackendReturnLocaleSegment(string $segment): bool
{
    return (bool)\preg_match('/^[a-z]{2}(?:[_-][A-Za-z0-9]{2,8}){1,3}$/', $segment);
}

function removeBackendLoginReturnParams(string $url): string
{
    $parts = \parse_url($url);
    if (!\is_array($parts) || empty($parts['query'])) {
        return $url;
    }

    \parse_str((string)$parts['query'], $params);
    unset($params['no_access_reason'], $params['return_url']);
    $query = \http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
    if (isset($parts['port'])) {
        $base .= ':' . $parts['port'];
    }
    $base .= $parts['path'] ?? '';
    return $query === '' ? $base : $base . '?' . $query;
}

function handleStaticFile(string $uri, string $rawRequest): ?string
{
    \Weline\Server\Service\WlsWorkerGlobals::setLastStaticCache(null);
    $requestTarget = $uri;
    $requestLine = \explode("\r\n", $rawRequest, 2)[0] ?? '';
    if (\is_string($requestLine) && $requestLine !== '') {
        $requestLineParts = \explode(' ', $requestLine, 3);
        if (isset($requestLineParts[1]) && \trim((string)$requestLineParts[1]) !== '') {
            $requestTarget = (string)$requestLineParts[1];
        }
    }
    $keepAlive = isKeepAlive($rawRequest);
    $connectionHeader = $keepAlive ? 'keep-alive' : 'close';

    // ========== 静态文件内存缓存（冷热淘汰策略） ==========
    // 缓存格式：[filepath => ['content' => string, 'mtime' => int, 'size' => int, 'cached_at' => int, 'hits' => int, 'last_access' => int]]
    static $staticFileCache = [];
    static $staticFileCacheTotalSize = 0;
    static $staticFileCacheMaxAge = 86400 * 7;  // 缓存有效期：7 天

    // 使用 WlsWorkerGlobals 配置
    $maxTotal = \Weline\Server\Service\WlsWorkerGlobals::getStaticCacheMaxTotal();
    $maxSize = \Weline\Server\Service\WlsWorkerGlobals::getStaticCacheMaxSize();
    $evictionThreshold = \Weline\Server\Service\WlsWorkerGlobals::getCacheEvictionThreshold();
    
    // 特殊命令：清理内存缓存
    if ($uri === '__CLEAR_CACHE__') {
        $count = \count($staticFileCache);
        $size = $staticFileCacheTotalSize;
        $staticFileCache = [];
        $staticFileCacheTotalSize = 0;
        \Weline\Server\Service\WorkerStaticResponseL1::clear();
        return "cleared:{$count}:{$size}";
    }
    
    // 特殊命令：获取缓存状态
    if ($uri === '__CACHE_STATUS__') {
        return \json_encode([
            'count' => \count($staticFileCache),
            'size' => $staticFileCacheTotalSize,
            'max_total' => $maxTotal,
            'max_size' => $maxSize,
            'eviction_threshold' => $evictionThreshold,
            'response_l1' => \Weline\Server\Service\WorkerStaticResponseL1::status(),
        ]);
    }

    /**
     * 冷热淘汰：当缓存接近上限时，淘汰最冷的缓存项
     * 评分公式：score = hits * 10 + recency_bonus
     * recency_bonus = max(0, 100 - (now - last_access) / 60) // 最近访问加分
     */
    $evictColdCache = static function (int $neededSpace) use (&$staticFileCache, &$staticFileCacheTotalSize, $maxTotal, $evictionThreshold): void {
        // 计算需要释放多少空间
        $targetSize = $maxTotal - $evictionThreshold - $neededSpace;
        if ($staticFileCacheTotalSize <= $targetSize) {
            return; // 空间足够，无需淘汰
        }
        
        $now = \time();
        $candidates = [];
        
        // 计算每个缓存项的冷热分数
        foreach ($staticFileCache as $path => $item) {
            $hits = $item['hits'] ?? 0;
            $lastAccess = $item['last_access'] ?? $item['cached_at'];
            $age = $now - $lastAccess;
            
            // 分数越低越冷（优先淘汰）
            $recencyBonus = \max(0, 100 - (int)($age / 60)); // 每分钟减 1 分
            $score = $hits * 10 + $recencyBonus;
            
            $candidates[] = [
                'path' => $path,
                'score' => $score,
                'size' => $item['size'],
            ];
        }
        
        // 按分数升序排序（最冷的在前）
        \usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
        
        // 淘汰最冷的缓存项直到空间足够
        foreach ($candidates as $candidate) {
            if ($staticFileCacheTotalSize <= $targetSize) {
                break;
            }
            
            $path = $candidate['path'];
            if (isset($staticFileCache[$path])) {
                $staticFileCacheTotalSize -= $staticFileCache[$path]['size'];
                unset($staticFileCache[$path]);
            }
        }
    };
    
    // 静态文件扩展名列表
    static $staticExtensions = [
        'css', 'js', 'map',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'eot', 'ttf', 'otf',
        'mp4', 'mp3', 'webm', 'ogg', 'm3u8',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'json', 'xml',
        'zip', 'rar', '7z', 'gz', 'tar',
    ];
    
    // MIME 类型映射
    static $mimeTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'map' => 'application/json',
        'json' => 'application/json; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'eot' => 'application/vnd.ms-fontobject',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'm3u8' => 'application/vnd.apple.mpegurl',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
    ];
    
    // 解析文件扩展名（去除查询字符串；URL 解码以支持中文等非 ASCII 文件名）
    $uriPath = \Weline\Server\Service\WlsStaticUriPathResolver::resolvePath($requestTarget);
    if ($uriPath === null) {
        \Weline\Server\Service\WlsWorkerGlobals::setLastStaticCache([
            'status' => 'rejected',
            'uri' => $requestTarget,
        ]);
        $body = 'Bad Request';
        return "HTTP/1.1 400 Bad Request\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: "
            . \strlen($body)
            . "\r\nConnection: close\r\n\r\n{$body}";
    }
    $extension = \strtolower(\pathinfo($uriPath, PATHINFO_EXTENSION));
    
    // 不是静态文件，交给框架处理
    if (empty($extension) || !\in_array($extension, $staticExtensions, true)) {
        return null;
    }
    
    // URI resolver 已按 segment 完成单次解码和目录边界校验。
    $normalizedUri = \trim($uriPath, '/');
    if ($normalizedUri === '') {
        return null;
    }

    $candidateUris = [];
    $addCandidateUri = static function (string $candidate) use (&$candidateUris): void {
        $candidate = \trim(\str_replace('\\', '/', $candidate), '/');
        if ($candidate === '') {
            return;
        }
        $candidateUris[] = $candidate;
        if (\str_starts_with($candidate, 'pub/')) {
            $stripped = \substr($candidate, 4);
            if ($stripped !== '') {
                $candidateUris[] = $stripped;
            }
        }
    };
    $isCurrencySegment = static fn(string $segment): bool => \Weline\Framework\App\State::isAllowedCurrencyCode($segment);
    $isLocaleSegment = static fn(string $segment): bool => \preg_match('/^[a-z]{2}_[A-Za-z]{2,4}(?:_[A-Z]{2})?$/', $segment) === 1;

    $addCandidateUri($normalizedUri);
    $segments = \array_values(\array_filter(\explode('/', $normalizedUri), static fn(string $segment): bool => $segment !== ''));
    $segmentCount = \count($segments);

    if ($segmentCount >= 2 && $segments[0] === 'pub') {
        $addCandidateUri(\implode('/', \array_slice($segments, 1)));
    }
    if ($segmentCount >= 3 && $isCurrencySegment($segments[1]) && $isLocaleSegment($segments[2])) {
        $addCandidateUri(\implode('/', \array_slice($segments, 3)));
    }
    if ($segmentCount >= 2 && $isCurrencySegment($segments[0]) && $isLocaleSegment($segments[1])) {
        $addCandidateUri(\implode('/', \array_slice($segments, 2)));
    }
    if ($segmentCount >= 1 && $isLocaleSegment($segments[0])) {
        $addCandidateUri(\implode('/', \array_slice($segments, 1)));
    }
    if ($segmentCount >= 2
        && !\str_contains($segments[0], '.')
        && \in_array($segments[1], ['pub', 'statics', 'theme_previews', 'media', '.well-known', 'errors'], true)
    ) {
        $addCandidateUri(\implode('/', \array_slice($segments, 1)));
    }

    $candidateUris = \array_values(\array_unique($candidateUris));

    foreach ($candidateUris as $candidateUri) {
        if (\Weline\Server\Service\StaticRequestBypassDecider::shouldDeferToFramework($candidateUri, $requestTarget)) {
            return null;
        }
    }

    // 查找文件位置（按优先级）
    $filename = null;
    foreach ($candidateUris as $candidateUri) {
        $searchPaths = [];
        $searchPaths[] = BP . 'pub' . DS . \str_replace('/', DS, $candidateUri);
        $searchPaths[] = BP . 'app' . DS . 'code' . DS . \str_replace('/', DS, $candidateUri);
        $searchPaths[] = BP . 'vendor' . DS . \str_replace('/', DS, $candidateUri);
        $searchPaths[] = BP . \str_replace('/', DS, $candidateUri);

        foreach ($searchPaths as $path) {
            $path = \str_replace([DS . DS, '//'], DS, $path);
            if (\is_file($path) && \is_readable($path)) {
                $filename = $path;
                break 2;
            }
        }
    }
    
    // 文件不存在，交给框架处理（可能是动态生成的资源）
    if ($filename === null) {
        foreach ($candidateUris as $candidateUri) {
            if (\Weline\Server\Service\StaticRequestBypassDecider::shouldReturnFastMissingStatic($candidateUri)) {
                \Weline\Server\Service\WlsWorkerGlobals::setLastStaticCache([
                    'status' => 'missing',
                    'uri' => $uriPath,
                    'candidate' => $candidateUri,
                ]);
                $body = 'Static file not found';
                $bodyLength = \strlen($body);
                return "HTTP/1.1 404 Not Found\r\n" .
                    "Content-Type: text/plain; charset=utf-8\r\n" .
                    "Content-Length: {$bodyLength}\r\n" .
                    "Cache-Control: no-store\r\n" .
                    "Connection: close\r\n" .
                    "X-WLS-Static-Missing: fastpath\r\n" .
                    "\r\n" .
                    $body;
            }
        }
        return null;
    }
    
    // 默认标记为 MISS（非内存缓存命中）
    \Weline\Server\Service\WlsWorkerGlobals::setLastStaticCache([
        'status' => 'miss',
        'uri' => $uriPath,
        'path' => $filename,
    ]);

    $validatedCached = null;
    $cacheHeaderStatus = 'MISS';
    $now = \time();
    if (isset($staticFileCache[$filename])) {
        $cached = $staticFileCache[$filename];
        if (($cached['mtime'] ?? null) === \filemtime($filename)
            && ($now - (int)($cached['cached_at'] ?? 0)) < $staticFileCacheMaxAge
        ) {
            $validatedCached = $cached;
            $cacheHeaderStatus = 'HIT';
            $cacheInfo = \Weline\Server\Service\WlsWorkerGlobals::getLastStaticCache() ?: [];
            $cacheInfo['status'] = 'hit';
            \Weline\Server\Service\WlsWorkerGlobals::setLastStaticCache($cacheInfo);
            $staticFileCache[$filename]['hits'] = ($cached['hits'] ?? 0) + 1;
            $staticFileCache[$filename]['last_access'] = $now;
        } else {
            $staticFileCacheTotalSize -= $cached['size'];
            unset($staticFileCache[$filename]);
        }
    }
    
    // 获取文件修改时间
    $mtime = \filemtime($filename);
    $lastModified = \gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    $etag = '"' . \md5($filename . $mtime) . '"';
    
    // If-None-Match takes precedence over If-Modified-Since.
    $ifNoneMatch = getHeaderValue($rawRequest, 'If-None-Match');
    $ifModifiedSince = getHeaderValue($rawRequest, 'If-Modified-Since');
    if (($ifNoneMatch !== null && $ifNoneMatch === $etag)
        || ($ifNoneMatch === null && $ifModifiedSince === $lastModified)
    ) {
        return "HTTP/1.1 304 Not Modified\r\nETag: {$etag}\r\n"
            . "Last-Modified: {$lastModified}\r\nAccept-Ranges: bytes\r\n"
            . "X-WLS-Static-Cache: {$cacheHeaderStatus}\r\nConnection: {$connectionHeader}\r\n\r\n";
    }
    
    // 获取文件大小
    $fileSize = \filesize($filename);
    
    // 获取 MIME 类型
    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    // 缓存控制（静态资源可以长期缓存）
    $maxAge = 86400 * 7; // 7 天

    $method = wlsStaticRequestMethod($rawRequest);
    $range = wlsResolveStaticByteRange(
        getHeaderValue($rawRequest, 'Range'),
        getHeaderValue($rawRequest, 'If-Range'),
        $fileSize,
        $etag,
        $mtime,
    );
    if ($range['status'] === 'unsatisfiable') {
        return "HTTP/1.1 416 Range Not Satisfiable\r\n"
            . "Content-Range: bytes */{$fileSize}\r\nContent-Length: 0\r\n"
            . "Accept-Ranges: bytes\r\nConnection: {$connectionHeader}\r\n\r\n";
    }
    if ($range['status'] === 'range') {
        $content = $method === 'HEAD'
            ? ''
            : wlsReadStaticFileSlice($filename, $range['start'], $range['length']);
        if ($content === false) {
            return null;
        }
        $response = "HTTP/1.1 206 Partial Content\r\n"
            . "Content-Range: bytes {$range['start']}-{$range['end']}/{$fileSize}\r\n"
            . "Content-Type: {$mimeType}\r\nContent-Length: {$range['length']}\r\n"
            . "Cache-Control: public, max-age={$maxAge}\r\nETag: {$etag}\r\n"
            . "Last-Modified: {$lastModified}\r\nAccept-Ranges: bytes\r\n"
            . "Connection: {$connectionHeader}\r\nX-WLS-Static-Cache: DISK\r\n"
            . "X-WLS-File-Size: {$fileSize}\r\n\r\n";
        return $method === 'HEAD' ? $response : $response . $content;
    }
    
    // ========== 内存缓存策略（冷热淘汰） ==========
    $content = $validatedCached['content'] ?? null;
    $fromCache = $validatedCached !== null;
    $now = $now ?? \time();
    
    // 只有小于配置阈值的文件才缓存到内存
    if ($fileSize <= $maxSize) {
        // 检查缓存是否存在且有效
        if (!$fromCache && isset($staticFileCache[$filename])) {
            $cached = $staticFileCache[$filename];
            // 验证：文件修改时间一致 且 缓存未过期
            if ($cached['mtime'] === $mtime && ($now - $cached['cached_at']) < $staticFileCacheMaxAge) {
                $content = $cached['content'];
                    $fromCache = true;
                    $cacheInfo = \Weline\Server\Service\WlsWorkerGlobals::getLastStaticCache() ?: [];
                    $cacheInfo['status'] = 'hit';
                    \Weline\Server\Service\WlsWorkerGlobals::setLastStaticCache($cacheInfo);
                // 更新访问统计（冷热计数）
                $staticFileCache[$filename]['hits'] = ($cached['hits'] ?? 0) + 1;
                $staticFileCache[$filename]['last_access'] = $now;
            } else {
                // 缓存失效，移除旧缓存
                $staticFileCacheTotalSize -= $cached['size'];
                unset($staticFileCache[$filename]);
            }
        }
        
        // 缓存未命中，从磁盘读取并缓存
        if ($content === null) {
            $content = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($filename);
            if ($content === false) {
                return null; // 读取失败，交给框架处理
            }
            
            // 检查是否需要淘汰：剩余空间不足时启动冷热淘汰
            $remainingSpace = $maxTotal - $staticFileCacheTotalSize;
            if ($remainingSpace - $fileSize < $evictionThreshold) {
                // 剩余空间低于阈值，启动冷热淘汰
                $evictColdCache($fileSize);
            }
            
            // 再次检查空间是否足够（淘汰后）
            if ($staticFileCacheTotalSize + $fileSize <= $maxTotal) {
                // 添加到缓存
                $staticFileCache[$filename] = [
                    'content' => $content,
                    'mtime' => $mtime,
                    'size' => $fileSize,
                    'cached_at' => $now,
                    'hits' => 1,
                    'last_access' => $now,
                ];
                $staticFileCacheTotalSize += $fileSize;
            }
            // 如果空间仍不足，不缓存该文件（但仍返回内容）
        }
    } else {
        // 大于配置阈值的文件不缓存，直接读取
        $content = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($filename);
        if ($content === false) {
            return null; // 读取失败，交给框架处理
        }
    }
    
    // 计算内容长度
    $contentLength = \strlen($content);
    
    if ($contentLength !== $fileSize && !$fromCache) {
        // 文件可能在读取过程中被修改，重新读取
        $content = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($filename);
        if ($content === false) {
            return null;
        }
        $contentLength = \strlen($content);
    }
    
    // 构建精简的 HTTP 响应（静态文件不需要 cookie、server 等冗余头部）
    $response = "HTTP/1.1 200 OK\r\n";
    $response .= "Content-Type: {$mimeType}\r\n";
    $response .= "Content-Length: {$contentLength}\r\n";
    $response .= "Cache-Control: public, max-age={$maxAge}\r\n";
    $response .= "ETag: {$etag}\r\n";
    $response .= "Last-Modified: {$lastModified}\r\n";
    $response .= "Accept-Ranges: bytes\r\n";
    $response .= "Connection: {$connectionHeader}\r\n";
    // WLS 内存缓存状态标识（HIT=内存缓存命中, MISS=磁盘读取）
    $response .= "X-WLS-Static-Cache: " . ($fromCache ? 'HIT' : 'MISS') . "\r\n";
    $response .= "X-WLS-File-Size: {$fileSize}\r\n";
    $response .= "X-WLS-Content-Length: {$contentLength}\r\n";
    $response .= "\r\n";
    $response .= $method === 'HEAD' ? '' : $content;
    
    $expectedResponseLen = \strlen($response);
    $headerEndPos = \strpos($response, "\r\n\r\n");
    $actualBodyLen = $expectedResponseLen - $headerEndPos - 4;
    if ($method !== 'HEAD' && $actualBodyLen !== $contentLength) {
        // 响应构建错误，返回错误响应
        return "HTTP/1.1 500 Internal Server Error\r\n" .
               "Content-Type: text/plain\r\n" .
               "Content-Length: 32\r\n" .
               "Connection: close\r\n" .
               "\r\n" .
               "Response construction error: {$actualBodyLen} != {$contentLength}";
    }


    if ($method === 'GET') {
        \Weline\Server\Service\WorkerStaticResponseL1::publish(
            $requestTarget,
            $response,
            $filename,
            $etag,
            $lastModified,
            $now,
            $staticFileCacheMaxAge,
        );
    }
    
    return $response;
}
