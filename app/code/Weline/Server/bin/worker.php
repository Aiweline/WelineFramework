<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程
 *
 * 用法: php worker.php <host> <port> <worker_id> [instance_name]
 *
 * 该 Worker 进程集成框架路由，支持完整的 HTTP 请求处理
 * 包含健康检查接口 /_wls/health（仅本地访问）
 * 维护模式由框架自动处理
 *
 * ┌─────────────────────────────────────────────────────────────────────────────┐
 * │  ⚠ WLS 默认仅监听 127.0.0.1，仅本机可访问                                      │
 * │  外网访问需用 Nginx/Caddy 等反向代理转发到 127.0.0.1:9090                        │
 * │  Nginx 示例：proxy_pass https://127.0.0.1:9090;                              │
 * │  需直连外网时：php bin/w server:start --host 0.0.0.0                          │
 * └─────────────────────────────────────────────────────────────────────────────┘
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

if (!\function_exists('wlsNormalizeMemoryLimit')) {
    function wlsNormalizeMemoryLimit(mixed $value, string $default = '256M'): string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string) (int) $value;
        }
        $value = \strtoupper(\trim((string) $value));
        $default = \strtoupper(\trim($default)) ?: '256M';
        if ($value === '') {
            return $default;
        }
        if ($value === '-1') {
            return '-1';
        }
        if (\preg_match('/^[1-9]\d*$/', $value)) {
            return $value . 'M';
        }
        if (\preg_match('/^[1-9]\d*(?:K|M|G)$/', $value)) {
            return $value;
        }
        return $default;
    }
}

if (!\function_exists('wlsMemoryLimitToBytes')) {
    function wlsMemoryLimitToBytes(mixed $value): int
    {
        $limit = \strtoupper(\trim((string) $value));
        if ($limit === '' || $limit === '-1' || $limit === '0') {
            return 0;
        }

        $unit = \substr($limit, -1);
        $number = (float) $limit;
        if ($number <= 0) {
            return 0;
        }

        return match ($unit) {
            'G' => (int) \round($number * 1024 * 1024 * 1024),
            'M' => (int) \round($number * 1024 * 1024),
            'K' => (int) \round($number * 1024),
            default => (int) \round($number),
        };
    }
}

if (!\function_exists('wlsClearFrameworkCachePools')) {
    /**
     * @return array<string, bool>
     */
    function wlsClearFrameworkCachePools(): array
    {
        $results = [];
        $pools = [
            'router',
            'fpc',
            'hook',
            'view',
            'phrase',
            'i18n',
            'config',
            'module_router',
            'theme',
            'url_rewrite',
            'website',
            'controller',
            'taglib',
            'system_config',
        ];

        try {
            if (\class_exists(\Weline\Framework\Cache\Adapter\WlsMemoryAdapter::class)) {
                \Weline\Framework\Cache\Adapter\WlsMemoryAdapter::clearAllMemory();
            }

            if (!\class_exists(\Weline\Framework\Manager\ObjectManager::class)
                || !\class_exists(\Weline\Framework\Cache\CacheManager::class)) {
                return $results;
            }

            $cacheManager = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Cache\CacheManager::class);
            foreach ($pools as $pool) {
                if (\method_exists($cacheManager, 'hasPool') && !$cacheManager->hasPool($pool)) {
                    continue;
                }

                $results[$pool] = (bool)$cacheManager->pool($pool)->clear();
            }
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[WLS] cache pool clear failed: ' . $throwable->getMessage(), [], 'wls_cache_clear');
            }
        }

        return $results;
    }
}

$wlsMemoryLimit = '256M';
@\ini_set('memory_limit', $wlsMemoryLimit);

// 获取参数
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 9981);
$workerId = (int) ($argv[3] ?? 1);
$instanceName = $argv[4] ?? 'default';

// 解析命令行参数
$processName = '';
$isFrontend = false;
$useReusePort = false;  // 是否使用 SO_REUSEPORT（Linux 直连模式）
$wlsLoopDriver = 'auto';
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
$workerCount = 1;

foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend' || $arg === '-frontend' || $arg === '--win' || $arg === '-win') {
        $isFrontend = true;
    } elseif ($arg === '--reuseport' || $arg === '-reuseport') {
        $useReusePort = true;
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
    } elseif (\str_starts_with($arg, '--wls-loop-driver=')) {
        $wlsLoopDriver = (string)\substr($arg, 18);
    } elseif (\str_starts_with($arg, '--memory-limit=')) {
        $wlsMemoryLimit = wlsNormalizeMemoryLimit(\substr($arg, 15));
    } elseif (\str_starts_with($arg, '--worker-count=')) {
        $workerCount = \max(1, (int)\substr($arg, 15));
    }
}
@\ini_set('memory_limit', $wlsMemoryLimit);

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

\Weline\Server\Log\LogConfig::bootstrapVerboseFromInstanceFile($instanceName);

// IPC control port. Prefer the explicit Master-provided argument; the endpoint
// file is only a bootstrap pointer when the argument is absent.
if (!isset($controlPort)) {
    $controlPort = 0;
}
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);
if ($controlPort <= 0 && !$supervisorEnabled) {
    $controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, $controlPort, 30);
}
// Master PID（用于孤儿检测）
if (!isset($masterPid) || $masterPid <= 0) {
    $masterPid = 0;
}
// 是否为维护 Worker
if (!isset($isMaintenanceWorker)) {
    $isMaintenanceWorker = false;
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
$_SERVER['WLS_PORT'] = (string)$port;
$_ENV['WLS_PORT'] = (string)$port;
@\putenv('WLS_PORT=' . (string)$port);

// 定义前端模式常量（供 WlsRuntime 使用）
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

if (!\function_exists('wlsResetLongRunningExecutionLimit')) {
    function wlsResetLongRunningExecutionLimit(): void
    {
        if (\function_exists('ini_set') && (string)@\ini_get('max_execution_time') !== '0') {
            @\ini_set('max_execution_time', '0');
        }
        if (\function_exists('set_time_limit')) {
            @\set_time_limit(0);
        }
    }
}

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;
use Weline\Server\Service\InternalRequestLabel;
use Weline\Server\Service\WorkerProcessLabel;

$processTag = WorkerProcessLabel::buildLogTag(false, $isMaintenanceWorker, $workerId, $port, $instanceName);
if (\function_exists('cli_set_process_title')) {
    @\cli_set_process_title(
        WorkerProcessLabel::buildProcessTitle(false, $isMaintenanceWorker, $workerId, $port, $instanceName)
    );
}

ErrorBootstrap::init($processTag, [
    'worker_id' => $workerId,
    'port' => $port,
    'instance' => $instanceName,
    'process_name' => $processName,
    'is_maintenance' => $isMaintenanceWorker,
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
$envOverrides = $sharedStateRuntime->toEnvOverrides();
$envConfig = \array_replace_recursive($envConfig, $envOverrides);
\Weline\Framework\App\Env::getInstance()->applyRuntimeConfig($envOverrides);
$sessionRuntime = $sharedStateRuntime->getSession();
$memoryRuntime = $sharedStateRuntime->getMemory();
$envLoopDriver = (string) (($envConfig['wls']['loop']['driver'] ?? 'auto'));
$wlsLoopDriver = $wlsLoopDriver !== '' ? $wlsLoopDriver : $envLoopDriver;
$wlsLoopDriver = \Weline\Server\EventLoop\EventLoopFactory::normalizeDriver($wlsLoopDriver);
$wlsEnv = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];

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
$mainLoopUnblockedLogEvery = \Weline\Server\Service\MainLoopUnblockedLogConfig::resolve($wlsEnv, ['worker']);
$mainLoopUnblockedLogIntervalSec = \Weline\Server\Service\MainLoopUnblockedLogConfig::resolveInterval($wlsEnv, ['worker']);
$lastMainLoopUnblockedLogAt = 0.0;

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
// stdout：默认显示子进程启动/操作日志；只有显式配置关闭时才静默
WlsLogger::getInstance()
    ->setStdoutEnabled(\Weline\Server\Log\LogConfig::isStdoutEnabled($isFrontend, \Weline\Server\Log\LogConfig::isDevMode()))
    ->setProcessTag($processTag);
// ========== 日志系统结束 ==========

// 注册 PID 到 Processer（启用快速 PID 查找）
if ($processName) {
    \Weline\Framework\System\Process\Processer::setPid('--name=' . $processName, \getmypid());
    // 注册监听端口（启用快速端口→PID 查找）
    if ($port > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts('--name=' . $processName, [$port]);
    }
}

// 初始化路由提示服务（用于 TCP 透传模式下的智能路由）
$kernel = null;
$ipcClient = null;
$ipcReceivedShutdown = false;
$ipcDraining = false;
$drainStartTime = 0;
$maxDrainTime = 10;
$waitingForAck = false;
$readySentTime = 0.0;
$ackRetryCount = 0;
$maxAckRetries = 0;
$ackTimeout = 10.0;
$ipcSelfTag = null;
$shouldExit = false;
$orphanGuard = new \Weline\Server\IPC\ChildControl\MasterOrphanGuard();
$ipcRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);
$earlyIpcHandler = null;

if ($controlPort > 0 || $supervisorEnabled) {
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
        WlsLogger::info_("[IPC] Registered with Master before worker bootstrap; READY deferred until socket/runtime is ready");
    } else {
        WlsLogger::warning_("[IPC] Early register failed (control port: {$controlPort}); will retry after worker bootstrap");
    }
}

\Weline\Server\Service\RouteHintService::init($port, true, 3600);

// 初始化框架运行时
$runtime = null;
$runtimeError = null;

try {
    WlsLogger::info_("Worker 启动，监听 tcp://{$host}:{$port}");
    // 深橙色输出 WLS 配置提示
    if (\defined('STDOUT') && \is_resource(STDOUT)) {
        $tips = [
            "\033[38;5;208m⚠ WLS 默认仅监听 127.0.0.1，仅本机可访问\033[0m",
            "\033[38;5;208m外网访问需用 Nginx/Caddy 等反向代理转发到 127.0.0.1:9090\033[0m",
            "\033[38;5;208mNginx 示例：proxy_pass https://127.0.0.1:9090;\033[0m",
            "\033[38;5;208m需直连外网时：php bin/w server:start --host 0.0.0.0\033[0m",
        ];
        foreach ($tips as $tip) {
            \fwrite(STDOUT, $tip . "\n");
        }
    }
    $runtime = new \Weline\Framework\Runtime\WlsRuntime();
    $runtime->bootstrap();
    WlsLogger::info_("框架运行时初始化成功");

    // 共享服务检查延迟到后台进行，不阻塞 IPC 连接
    // IPC 连接应该尽快建立，让 Master 能立即感知到 Worker
    // SharedState 的 session/memory 信息在首次请求时通过 ConnectionPool 自动获取
    // 不再在这里同步等待 SharedStateServiceManager::ensureRuntime()

    // 从 env.php 读取共享服务地址，连接池在请求 Fiber 内按需建立
    $projectOffset = \Weline\Server\Service\MasterProcess::getProjectPortOffset();
    $wls = $_wlsEnvConfig['wls'] ?? [];

    // Session 配置
    $sessionConfig = \is_array($wls['session'] ?? null) ? $wls['session'] : [];
    $wlsServer = \is_array($sessionConfig['wls_server'] ?? null) ? $sessionConfig['wls_server'] : [];
    $sessionHost = \trim((string) ($wlsServer['host'] ?? $sessionConfig['host'] ?? '127.0.0.1'));
    if ($sessionHost === '') {
        $sessionHost = '127.0.0.1';
    }
    $sessionPort = (int) ($wlsServer['port'] ?? $sessionConfig['port'] ?? (19970 + $projectOffset));
    if ($sessionPort <= 0) {
        $sessionPort = 19970 + $projectOffset;
    }
    $sessionTokenFileName = \trim((string) ($wlsServer['token_file_name'] ?? $sessionConfig['token_file_name'] ?? 'session_server.token'));
    if ($sessionTokenFileName === '') {
        $sessionTokenFileName = 'session_server.token';
    }

    // Memory 配置
    $memoryService = \is_array($wls['memory_service'] ?? null) ? $wls['memory_service'] : [];
    $memoryHost = \trim((string) ($memoryService['host'] ?? '127.0.0.1'));
    if ($memoryHost === '') {
        $memoryHost = '127.0.0.1';
    }
    $memoryPort = (int) ($memoryService['port'] ?? (19971 + $projectOffset));
    if ($memoryPort <= 0) {
        $memoryPort = 19971 + $projectOffset;
    }
    $memoryTokenFileName = \trim((string) ($memoryService['token_file_name'] ?? 'memory_server.token'));
    if ($memoryTokenFileName === '') {
        $memoryTokenFileName = 'memory_server.token';
    }

    WlsLogger::info_("[Session] Session service address configured {$sessionHost}:{$sessionPort}");
    WlsLogger::info_("[Memory] Memory service address configured {$memoryHost}:{$memoryPort}");
    // 启动期禁止同步预热连接，避免阻塞 IPC READY；消费者令牌由 Master 管理。
    try {
        \Weline\Server\Service\SharedRuntimeConnectionWarmup::primeWorkerPools($workerId, $instanceName, [
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
        WlsLogger::info_('[ConnectionPool] Session/Memory pools primed without blocking; async prewarm runs after IPC loop starts');
    } catch (\Throwable $e) {
        WlsLogger::warning_('[ConnectionPool] 预热失败，将在首次请求时自动重试: ' . $e->getMessage());
    }
} catch (\Throwable $e) {
    $runtimeError = $e->getMessage();
    WlsLogger::error_("框架运行时初始化失败: " . $e->getMessage());
    w_log_error('[WLS Worker] Bootstrap error: ' . $e->getMessage());
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

// ========== Fiber 调度器初始化 ==========
$fiberScheduler = new \Weline\Server\Scheduler\FiberScheduler();
$eventLoopMeta = \Weline\Server\EventLoop\EventLoopFactory::create($wlsLoopDriver);
$eventLoop = $eventLoopMeta['loop'];
$coroutineRuntime = new \Weline\Server\Runtime\CoroutineRuntime($eventLoop, $fiberScheduler);
\Weline\Server\Observer\SchedulerWaitObserver::setScheduler($fiberScheduler);
\Weline\Framework\Runtime\SchedulerSystem::enableScheduler();
$longLivedProtocolResolver = new \Weline\Server\Service\Protocol\LongLived\ProtocolResolver();
WlsLogger::info_("Fiber 调度器已初始化");
WlsLogger::info_(
    "EventLoop 已初始化 requested={$eventLoopMeta['requested']} resolved={$eventLoopMeta['resolved']} backend={$coroutineRuntime->getLoopBackend()}"
);
$asyncBizAdapters = new \Weline\Server\Runtime\Async\AsyncBizAdapters();

// 活跃 Fiber 列表：connId => Fiber
$activeFibers = [];
$fiberTickBudgetMs = (float)(\Weline\Framework\App\Env::get('wls.worker.fiber_tick_budget_ms', 8) ?: 8);
\Weline\Framework\Runtime\WlsConcurrency::setOtherSuspendedFiberCountProvider(
    static function () use (&$activeFibers): int {
        return \count($activeFibers);
    }
);
// Fiber 关联的连接 ID（用于 Fiber 完成后的响应发送）
$fiberResults = [];

// ========== WLS 内存缓存配置（智能模式） ==========
$wlsCacheConfig = [];
if ($envConfig !== null && isset(($envConfig['wls'] ?? [])['cache'])) {
    $wlsCacheConfig = $envConfig['wls']['cache'];
}

// 内存检测函数（与 worker_ssl.php 共用逻辑）
if (!\function_exists('getSystemFreeMemory')) {
    function getSystemFreeMemory(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 0;
        } else {
            if (\is_readable('/proc/meminfo')) {
                $meminfo = @\file_get_contents('/proc/meminfo');
                if ($meminfo && \preg_match('/MemAvailable:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                    return (int)$matches[1] * 1024;
                }
                if ($meminfo) {
                    $free = 0;
                    if (\preg_match('/MemFree:\s*(\d+)\s*kB/i', $meminfo, $m)) $free += (int)$m[1];
                    if (\preg_match('/Cached:\s*(\d+)\s*kB/i', $meminfo, $m)) $free += (int)$m[1];
                    if (\preg_match('/Buffers:\s*(\d+)\s*kB/i', $meminfo, $m)) $free += (int)$m[1];
                    if ($free > 0) return $free * 1024;
                }
            }
            // macOS: vm_stat 仅 "Pages free" 偏小，需加上可回收的 inactive/speculative（与 Linux MemAvailable 语义一致）
            // 注意：macOS 可能输出千位逗号（如 "1,234,567"），需去掉逗号再转 int，否则会误判为内存严重不足
            $output = @\shell_exec('vm_stat 2>/dev/null');
            if ($output) {
                $pageSize = 4096;
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
        return 0;
    }
}

if (!\function_exists('getSystemTotalMemory')) {
    function getSystemTotalMemory(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 4 * 1024 * 1024 * 1024;
        } else {
            if (\is_readable('/proc/meminfo')) {
                $meminfo = @\file_get_contents('/proc/meminfo');
                if ($meminfo && \preg_match('/MemTotal:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                    return (int)$matches[1] * 1024;
                }
            }
            $output = @\shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if ($output) return (int)\trim($output);
        }
        return 4 * 1024 * 1024 * 1024;
    }
}

if (!\function_exists('calculateCacheSize')) {
    function calculateCacheSize(string|int $configValue, int $defaultPercent, int $defaultMin, int $defaultMax): int
    {
        if (\is_int($configValue)) return $configValue;
        $configValue = \strtolower(\trim($configValue));
        if ($configValue === 'auto' || $configValue === '') {
            $totalMem = getSystemTotalMemory();
            $calculated = (int)($totalMem * $defaultPercent / 100);
            return \max($defaultMin, \min($defaultMax, $calculated));
        }
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
        return $defaultMin;
    }
}

$staticFileCacheMaxTotalConfig = $wlsCacheConfig['static_file_max_total'] ?? 'auto';
$WLS_STATIC_CACHE_MAX_TOTAL = calculateCacheSize($staticFileCacheMaxTotalConfig, 2, 32 * 1024 * 1024, 256 * 1024 * 1024);
// 单文件最大缓存大小
$staticFileCacheMaxSizeConfig = $wlsCacheConfig['static_file_max_size'] ?? '2M';
$WLS_STATIC_CACHE_MAX_SIZE = calculateCacheSize($staticFileCacheMaxSizeConfig, 0, 512 * 1024, 10 * 1024 * 1024);
$WLS_CACHE_EVICTION_THRESHOLD = (int)($wlsCacheConfig['eviction_threshold'] ?? 5 * 1024 * 1024);

// 内存检查
$freeMemory = getSystemFreeMemory();
$requiredMemory = $WLS_STATIC_CACHE_MAX_TOTAL + 50 * 1024 * 1024;
if ($freeMemory > 0 && $freeMemory < $requiredMemory) {
    $freeMB = \round($freeMemory / 1024 / 1024, 1);
    $requiredMB = \round($requiredMemory / 1024 / 1024, 1);
    WlsLogger::warning_("内存不足警告：可用 {$freeMB}MB，需要 {$requiredMB}MB");
    if ($freeMemory < $requiredMemory * 0.5) {
        WlsLogger::error_("内存严重不足，无法启动");
        exit(1);
    }
    $WLS_STATIC_CACHE_MAX_TOTAL = (int)($freeMemory * 0.6);
    WlsLogger::warning_("自动缩减缓存至 " . \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1) . "MB");
}

WlsLogger::info_("内存缓存：上限 " . \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1) . "MB，单文件 " . \round($WLS_STATIC_CACHE_MAX_SIZE / 1024, 1) . "KB");
// ========== 内存缓存配置结束 ==========

// uopz：将请求内的 exit()/die() 转为异常，避免整 Worker 进程退出（需安装 uopz 扩展）
$WLS_UOPZ_EXIT_GUARD = false;
if (\extension_loaded('uopz') && \function_exists('uopz_allow_exit')) {
    try {
        // 通过 call_user_func 调用，避免静态分析误报未安装 uopz 扩展。
        \call_user_func('uopz_allow_exit', false);
        $WLS_UOPZ_EXIT_GUARD = true;
        WlsLogger::info_('uopz 已启用：业务代码中裸 exit()/die() 不再结束 Worker 进程（请优先使用 System::exit）');
    } catch (\Throwable) {
    }
}

// 注册补充 shutdown handler（检测 die()/exit() 非正常退出）
// 注：致命错误由 ErrorBootstrap 统一处理，此处仅处理非致命退出
\register_shutdown_function(function() use ($workerId, $port, $instanceName) {
    $error = \error_get_last();
    $fatalErrorTypes = [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_RECOVERABLE_ERROR, \E_USER_ERROR];
    
    // 致命错误由 ErrorBootstrap 处理，不在此重复
    if ($error !== null && \in_array($error['type'], $fatalErrorTypes, true)) {
        return;
    }
    
    // 无致命错误但进程即将退出：多为业务代码 die()/exit() 或信号终止
    $exitMsg = "Worker 非致命退出，可能为 die()/exit() 或信号终止";
    WlsLogger::warning_($exitMsg);
    WlsLogger::flush_(true);
});

// ========== 检测 SO_REUSEPORT 支持 ==========
$isWindows = \PHP_OS_FAMILY === 'Windows';
$supportsReusePort = false;

if (!$isWindows && \defined('SO_REUSEPORT')) {
    if (PHP_OS === 'Linux') {
        $release = \php_uname('r');
        if (\version_compare($release, '3.9', '>=')) {
            $supportsReusePort = true;
        }
    } elseif (PHP_OS === 'Darwin') {
        // macOS 也支持 SO_REUSEPORT
        $supportsReusePort = true;
    }
}

// 如果显式指定了 --reuseport 但平台不支持，报错
if ($useReusePort && !$supportsReusePort) {
    WlsLogger::error_("平台不支持 SO_REUSEPORT");
    exit(1);
}

// ========== Socket 创建 ==========
$socket = null;

// 方案1：使用 socket 扩展创建支持 SO_REUSEPORT 的 socket（更可靠）
if ($useReusePort && $supportsReusePort && \function_exists('socket_create')) {
    WlsLogger::info_("使用 socket 扩展创建 SO_REUSEPORT socket...");
    
    $rawSocket = false;
    $maxBindRetries = 1;
    $bindRetryDelay = 0;
    $lastErrno = 0;
    $lastErrstr = '';

    for ($attempt = 1; $attempt <= $maxBindRetries; $attempt++) {
        $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$rawSocket) {
            $lastErrno = \socket_last_error();
            $lastErrstr = \socket_strerror($lastErrno);
            WlsLogger::error_("socket_create 失败: {$lastErrstr}");
            break;
        }
        if (!\Weline\Server\Socket\ListenSocketOptions::applyRawListenSocketReuseOption($rawSocket)['success']) {
            WlsLogger::warning_("设置 SO_REUSEADDR 失败");
        }
        if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
            WlsLogger::error_("设置 SO_REUSEPORT 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
            @\socket_close($rawSocket);
            $rawSocket = false;
            break;
        }
        if (@\socket_bind($rawSocket, $host, $port)) {
            break;
        }
        $lastErrno = \socket_last_error($rawSocket);
        $lastErrstr = \socket_strerror($lastErrno);
        @\socket_close($rawSocket);
        $rawSocket = false;
        if ($lastErrno !== 98 && $lastErrno !== 10048) {
            WlsLogger::error_("socket_bind 失败: {$lastErrstr} (errno: {$lastErrno})");
            break;
        }
        WlsLogger::warning_("端口 {$port} 占用 (errno: {$lastErrno})，{$bindRetryDelay} 秒后重试 ({$attempt}/{$maxBindRetries})");
        if ($attempt < $maxBindRetries) {
            \Weline\Framework\Runtime\SchedulerSystem::sleep($bindRetryDelay);
        }
    }

    if (!$rawSocket) {
        WlsLogger::error_("Socket 创建失败: " . ($lastErrstr ?: \socket_strerror($lastErrno)));
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
    
    WlsLogger::info_("SO_REUSEPORT socket 创建成功，Worker #{$workerId} 监听 {$host}:{$port}");
    
} else {
    // 方案2：标准 stream_socket_server 方式
    $socketOptions = \Weline\Server\Socket\ListenSocketOptions::streamContextOptions([
        'backlog' => 102400,
    ]);
    
    // Linux 下尝试启用 SO_REUSEPORT（通过 stream context）
    if ($supportsReusePort && !$useReusePort) {
        $socketOptions['so_reuseport'] = true;
        WlsLogger::info_("尝试通过 stream_context 启用 SO_REUSEPORT");
    }
    
    $context = \stream_context_create([
        'socket' => $socketOptions
    ]);

    $maxStreamRetries = 1;
    $streamRetryDelay = 0;
    $socket = null;
    $streamErrno = 0;
    $streamErrstr = '';

    for ($attempt = 1; $attempt <= $maxStreamRetries; $attempt++) {
        $socket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
            $streamErrno,
            $streamErrstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if ($socket) {
            break;
        }
        if ($streamErrno !== 98 && $streamErrno !== 10048) {
            break;
        }
        WlsLogger::warning_("端口 {$port} 占用 (errno: {$streamErrno})，{$streamRetryDelay} 秒后重试 ({$attempt}/{$maxStreamRetries})");
        if ($attempt < $maxStreamRetries) {
            \Weline\Framework\Runtime\SchedulerSystem::sleep($streamRetryDelay);
        }
    }

    if (!$socket) {
        WlsLogger::error_("Socket 创建失败: {$streamErrstr} (errno: {$streamErrno})");
        w_log_error("[WLS Worker] Failed to create socket: {$streamErrstr}");
        exit(1);
    }
}

WlsLogger::info_("Socket 创建成功，开始监听连接");

\stream_set_blocking($socket, false);

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
$kernel = $kernel ?? null;
$ipcClient = $ipcClient ?? null;
$ipcReceivedShutdown = false;
$ipcDraining = false; // 是否正在排水
$drainStartTime = 0;   // 排水开始时间戳
$maxDrainTime = 10;     // 排水最大等待时间（秒），超时后强制关闭所有连接退出
$orphanGuard = new \Weline\Server\IPC\ChildControl\MasterOrphanGuard();

// 如果启用了维护模式
if ($isMaintenanceWorker) {
    try {
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

// 获取控制端口
if ($controlPort <= 0 && !$supervisorEnabled) {
    $controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, $controlPort);
}

$ipcRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);
$waitingForAck = $waitingForAck ?? false;
$readySentTime = $readySentTime ?? 0.0;
$ackRetryCount = $ackRetryCount ?? 0;
$maxAckRetries = $maxAckRetries ?? 0;
$ackTimeout = $ackTimeout ?? 10.0;

$ipcClient = $ipcClient ?? null;
$ipcSelfTag = $ipcSelfTag ?? null;
$ipcDraining = $ipcDraining ?? false;
$ipcReceivedShutdown = $ipcReceivedShutdown ?? false;
$drainStartTime = $drainStartTime ?? 0;
$shouldExit = $shouldExit ?? false;
$readyGateWorkerBootstrapWarmupCompleted = false;
$runReadyGateWorkerBootstrapWarmup = static function () use (
    &$readyGateWorkerBootstrapWarmupCompleted,
    &$runtime,
    &$runtimeError,
    $isMaintenanceWorker,
    $workerId
): void {
    if ($readyGateWorkerBootstrapWarmupCompleted
        || $isMaintenanceWorker
        || $runtimeError !== null
        || !$runtime instanceof \Weline\Framework\Runtime\WlsRuntime
    ) {
        return;
    }

    WlsLogger::info_("[WorkerWarmup] ready-gate bootstrap warmup start worker={$workerId}");
    $runtime->runReadyGateWorkerBootstrapWarmup();
    $readyGateWorkerBootstrapWarmupCompleted = true;
    WlsLogger::info_("[WorkerWarmup] ready-gate bootstrap warmup done worker={$workerId}");
};

if ($controlPort > 0 || $supervisorEnabled) {
    $ipcSelfTag = ($isMaintenanceWorker ? 'Maintenance' : 'Worker') . "#{$workerId}";
    $identity = new \Weline\Server\IPC\ChildControl\ChildProcessIdentity(
        $ipcRole,
        \getmypid(),
        $port,
        $workerId,
        $orchestratorEpoch,
        $orchestratorLaunchId
    );
    $handler = new \Weline\Server\IPC\ChildControl\Handler\WorkerControlHandler(
        static function (array $msg) use (&$shouldExit, &$ipcDraining, &$ipcReceivedShutdown, &$socket, &$drainStartTime, &$maxDrainTime, &$waitingForAck, $workerId, &$activeFibers, &$ipcClient, $port, &$fiberIdleTtlSec, &$fiberMaxActive, &$fiberReleaseIdleRequested, $isMaintenanceWorker): void {
            $type = $msg['type'] ?? '';
            // 帝王令：shutdown 至高无上，一旦收到则不再处理其他 IPC（RELOAD/DRAIN/CACHE_CLEAR 等）
            if ($type !== \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN && $ipcReceivedShutdown) {
                return;
            }
            switch ($type) {
                case \Weline\Server\IPC\ControlMessage::TYPE_PING:
                    // 健康检查：立即响应 pong
                    $pingTimestamp = (float)($msg['timestamp'] ?? 0.0);
                    $stats = [
                        'active_fibers' => \count($activeFibers),
                        'memory_usage' => \memory_get_usage(true),
                    ];
                    $ipcClient->send(\Weline\Server\IPC\ControlMessage::pong($pingTimestamp, $stats));
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_ACK_READY:
                case \Weline\Server\IPC\ControlMessage::TYPE_READY_ACK:
                    $accepted = !\array_key_exists('accepted', $msg) || (bool)($msg['accepted'] ?? false);
                    if (!$accepted) {
                        $reason = (string)($msg['reason'] ?? 'ready_rejected');
                        $waitingForAck = false;
                        $shouldExit = true;
                        $ipcDraining = true;
                        $drainStartTime = \time() - 10;
                        if ($socket && \is_resource($socket)) {
                            @\fclose($socket);
                            $socket = null;
                        }
                        if ($ipcClient !== null && $ipcClient->isConnected()) {
                            $ipcClient->send(\Weline\Server\IPC\ControlMessage::exitReason('master_rejected_ready:' . $reason, 0));
                        }
                        WlsLogger::warning_("Master ACK 确认结果：失败（reason={$reason}），Worker 自毁退出");
                        break;
                    }
                    // 收到 Master ACK 确认，启动完成
                    $waitingForAck = false;
                    $ackWorkerId = $msg['worker_id'] ?? 0;
                    $dispatcherConfirmed = (bool)($msg['dispatcher_confirmed'] ?? false);
                    $ackPort = (int)($msg['port'] ?? 0);
                    WlsLogger::info_(
                        "收到 Master ACK_READY 确认，Master ACK 确认结果：成功 (worker_id={$ackWorkerId}, dispatcher_confirmed="
                        . ($dispatcherConfirmed ? '1' : '0') . ", port={$ackPort})，停止 READY 重报"
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
                    if ($dt > 0) {
                        $maxDrainTime = \max(1, \min(7200, $dt));
                    }
                    // 关键修复：reload 时不立即关闭 socket，继续接受新连接并快速响应
                    // 这样可以避免连接在内核队列中自旋等待，直到新 Worker 启动
                    // socket 会在排水完成或超时后才关闭
                    WlsLogger::info_("收到 reload 命令，已清除 opcache，开始排水（继续接受新连接直到新 Worker 就绪，最多等待 10 秒）...");
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_CACHE_CLEAR:
                    if (\function_exists('opcache_reset')) {
                        \opcache_reset();
                    }
                    \clearstatcache(true);
                    \function_exists('wlsClearFrameworkCachePools') && wlsClearFrameworkCachePools();
                    \Weline\Framework\Manager\ObjectManager::clearInstances();
                    if (\class_exists(\Weline\Framework\Phrase\Parser::class)) {
                        \Weline\Framework\Phrase\Parser::clearWorkerCaches();
                    }
                    if (\class_exists(\Weline\I18n\Parser::class)) {
                        \Weline\I18n\Parser::clearWorkerCaches();
                    }
                    if (\class_exists(\Weline\ModuleRouter\Observer\ProcessUrlBefore::class)) {
                        \Weline\ModuleRouter\Observer\ProcessUrlBefore::clearCache();
                    }
                    if (\class_exists(\Weline\Framework\Hook\Config\HookReader::class)) {
                        \Weline\Framework\Hook\Config\HookReader::clearStaticCache();
                    }
                    if (\class_exists(\Weline\Framework\View\Template::class)) {
                        \Weline\Framework\View\Template::clearStaticHookCaches();
                    }
                    if (\class_exists(\Weline\Theme\Block\Partials::class)) {
                        \Weline\Theme\Block\Partials::clearMetaCache();
                    }
                    if (\class_exists(\Weline\Theme\Service\SlotRendererService::class)) {
                        \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Service\SlotRendererService::class)
                            ->clearCache();
                    }
                    if (\class_exists(\Weline\Theme\Observer\ControllerFetchFileBefore::class)) {
                        \Weline\Theme\Observer\ControllerFetchFileBefore::clearRuntimeCache();
                    }
                    if (\class_exists(\Weline\Theme\Helper\ThemeData::class)) {
                        \Weline\Theme\Helper\ThemeData::clearCache();
                    }
                    if (\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class)) {
                        \Weline\Framework\Router\FullPageCacheCoordinator::clearProcessCache();
                    }
                    if (\function_exists('handleStaticFile')) {
                        handleStaticFile('__CLEAR_CACHE__', '');
                    }
                    WlsLogger::info_("收到 cache_clear 命令，已清理缓存");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SSL_CERT_RELOAD:
                    // 非 SSL Worker 不处理证书重载，仅记录
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_ROUTING_POLICY:
                    $policyData = $msg['data'] ?? [];
                    if (\is_array($policyData)) {
                        \Weline\Server\Service\Runtime\RoutingPolicyRegistry::update($policyData);
                        WlsLogger::info_('收到 routing_policy 命令，已更新进程内路由策略快照');
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
                    }
                    WlsLogger::info_("收到 drain 命令，已关闭监听 socket，开始排水...");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_FIBER_SET_CONFIG:
                    $fiberIdleTtlSec = (int) ($msg['idle_ttl_sec'] ?? 0);
                    $fiberMaxActive = (int) ($msg['max_active'] ?? 0);
                    WlsLogger::info_("收到 fiber_set_config: idle_ttl_sec={$fiberIdleTtlSec}, max_active={$fiberMaxActive}");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_FIBER_RELEASE_IDLE:
                    $fiberReleaseIdleRequested = true;
                    WlsLogger::info_("收到 fiber_release_idle，下一轮循环执行释放");
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

                case \Weline\Server\IPC\ControlMessage::TYPE_SET_MAINTENANCE_MODE:
                    $mEnabled = (bool) ($msg['enabled'] ?? false);
                    $mReqId = (string) ($msg['request_id'] ?? '');
                    WlsLogger::warning_(
                        "忽略 Worker 维护模式信号 enabled=" . ($mEnabled ? 'true' : 'false')
                        . " request_id={$mReqId}"
                        . " pinned_role=" . ($isMaintenanceWorker ? 'maintenance' : 'business')
                    );
                    if ($mReqId !== '' && $ipcClient !== null && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::encode([
                            'type' => \Weline\Server\IPC\ControlMessage::TYPE_MAINTENANCE_MODE_ACK,
                            'request_id' => $mReqId,
                            'worker_id' => $workerId,
                        ]));
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN:
                    $ipcReceivedShutdown = true;
                    $shouldExit = true;
                    $ipcDraining = true;
                    $maxDrainTime = 1;
                    $drainStartTime = \time() - $maxDrainTime;
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                    }
                    WlsLogger::info_("收到 shutdown 命令，准备退出");
                    break;
            }
        },
        static function () use (&$ipcClient): void {
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
    $runReadyGateWorkerBootstrapWarmup();
    $readyReported = $kernel->isConnected()
        ? $kernel->sendReady()
        : $kernel->connectAndRegister($controlPort);
    if ($readyReported) {
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
        WlsLogger::error_("[IPC] IPC 控制通道初始连接失败 (控制端口: {$controlPort})");
        WlsLogger::error_("[IPC] 可能原因: Master 未正确启动、IPC 服务故障或网络隔离");
        WlsLogger::warning_("[IPC] Worker 将标记为孤立模式，进入重连循环");
        $ipcClient = $kernel->getClient();
        $ipcReconnectAttempts = 0;
        $ipcReconnectMaxAttempts = 30;
        $ipcReconnectDueTime = \microtime(true) + 5.0;
    }
}
// ========== IPC 控制通道结束 ==========

// ACK 等待状态变量（在外部作用域定义，确保主循环可访问）
// 如果 IPC 未连接，这些变量不会被使用
if (!isset($waitingForAck)) {
    $waitingForAck = false;
}
if (!isset($readySentTime)) {
    $readySentTime = 0.0;
}
if (!isset($ackRetryCount)) {
    $ackRetryCount = 0;
}
if (!isset($maxAckRetries)) {
    $maxAckRetries = 0;
}
if (!isset($ackTimeout)) {
    $ackTimeout = 10.0;
}
if (!isset($ipcRole)) {
    $ipcRole = \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
}
$connections = [];
/** @var array<int, string> SSE/大块响应非阻塞写队列（与 worker_ssl 语义对齐） */
$writeBuffers = [];
/** @var array<int, resource> */
$writableConnections = [];
/** @var array<int, true> 缓冲区排空后关闭 */
$pendingClose = [];
$requestCount = 0;
$activeRequests = 0; // 正在处理的请求数
$requestBuffers = [];
$connectionLastActivity = []; // 连接最后活动时间（用于超时清理）
$requestLogged = []; // 记录已输出日志的连接（前端模式使用）
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

// 内存监控配置（防止内存泄漏导致 OOM）
$maxMemoryBytes = wlsMemoryLimitToBytes($wlsMemoryLimit);
if ($maxMemoryBytes <= 0) {
    $maxMemoryBytes = 256 * 1024 * 1024;
}
$memoryCheckInterval = 5;
$lastMemoryCheck = \time();
$memoryWarningThreshold = 0.80;
$memoryDrainThreshold = 0.88;
$requestGcInterval = 50;
$lastRequestGcCount = 0;

// 最大请求数限制（可选的内存保护措施）
$maxRequests = 10000; // 处理 10000 个请求后优雅重启（0=禁用）

// Fiber 池配置（可由 IPC 下发：fiber_set_config）
$fiberIdleTtlSec = 0;   // 挂起超过此秒数视为闲置并释放，0=不自动释放
$fiberMaxActive = 0;    // 最大活跃挂起 Fiber 数，0=不限制
$fiberReleaseIdleRequested = false;  // IPC 请求立即释放闲置时置 true
$lastFiberIdleCheck = \time();       // 上次执行闲置检查的时间

// 长连接（长连接/SSE）独立计数与饱和机制
$longLivedConnections = [];          // connId => ['type' => 'sse'|'longpoll', 'start' => timestamp]
$longLivedMaxActive = 0;             // 长连接上限，0=不限制（可由 IPC 下发配置）
$longLivedSaturationReported = false; // 本次饱和已上报（避免重复上报）
$longLivedSaturationCleared = false; // 饱和解除已上报
$lastLongLivedSaturationReport = 0;  // 上次饱和上报时间（节流）
$longLivedSaturationInterval = 10;    // 饱和状态上报间隔（秒）

// 重载日志输出函数
$logReload = function (string $method) use ($workerId, $instanceName) {
    $time = \date('Y-m-d H:i:s');
    if ($method === 'FLAG-CACHE' || $method === 'IPC-CACHE') {
        $message = "[{$time}] [WLS] Worker #{$workerId} ({$instanceName}) 已清理缓存（opcache + ObjectManager）[{$method}]";
    } else {
        $message = "[{$time}] [WLS] Worker #{$workerId} ({$instanceName}) 正在重载（优雅退出，由 Master 重启）[{$method}]";
    }
    w_log_info($message);
    if (\defined('STDOUT') && \is_resource(STDOUT)) {
        \fwrite(STDOUT, "\033[33m{$message}\033[0m\n");
    }
};

$configuredLongLivedMaxActive = (int)($wlsInstance['fiber']['long_lived_max_active'] ?? $wls['fiber']['long_lived_max_active'] ?? 4);
if ($configuredLongLivedMaxActive >= 0) {
    $longLivedMaxActive = $configuredLongLivedMaxActive;
}

// 是否需要优雅退出（重载时设置为 true）

// ========== Session Server 自治连接状态 ==========
$sessionClient = null;
$sessionConnected = false;
$sessionConnecting = false;  // 是否正在连接中
$sessionReadyReported = false; // 是否已上报 Session 就绪
$sessionReadySentTime = 0.0;
$sessionReadyTimeout = 30.0;   // Session 连接超时（秒）

// Worker 优雅退出函数（统一使用进程管理器清理）
// 优雅关闭配置：热重载给足时间排水，停止服务器短超时（与 Windows 一致，不长时间等待）
$gracefulShutdownTimeout = 30; // 热重载时等待活跃请求的最大时间（秒）
$stopShutdownTimeout = 3;      // server:stop 时等待连接的最大时间（秒）

$plannedExitReason = '';
$exitReasonSent = false;
$buildWorkerRuntimeSnapshot = static function (string $event = 'runtime') use (
    &$connections,
    &$activeRequests,
    &$requestCount,
    &$longLivedConnections,
    &$activeFibers,
    &$writableConnections,
    &$pendingClose,
    &$plannedExitReason,
    &$shouldExit,
    &$ipcDraining,
    $workerId,
    $port,
    $startTime,
    $wlsMemoryLimit,
    &$maxMemoryBytes
): array {
    $memoryUsed = \memory_get_usage(false);
    $memoryAllocated = \memory_get_usage(true);
    $memoryPercent = $maxMemoryBytes > 0 ? \round($memoryUsed / $maxMemoryBytes, 4) : 0.0;

    return [
        'event' => $event,
        'worker_id' => $workerId,
        'port' => $port,
        'pid' => \getmypid(),
        'connections' => \count($connections),
        'active_requests' => $activeRequests,
        'requests' => $requestCount,
        'long_lived_connections' => \count($longLivedConnections),
        'active_fibers' => \count($activeFibers),
        'writable_connections' => \count($writableConnections),
        'pending_close' => \count($pendingClose),
        'memory_used' => $memoryUsed,
        'memory_allocated' => $memoryAllocated,
        'memory_peak' => \memory_get_peak_usage(true),
        'memory_peak_used' => \memory_get_peak_usage(false),
        'memory_percent' => $memoryPercent,
        'max_memory_bytes' => $maxMemoryBytes,
        'memory_limit' => $wlsMemoryLimit,
        'uptime' => \max(0, \time() - $startTime),
        'planned_exit_reason' => $plannedExitReason,
        'should_exit' => $shouldExit ? 1 : 0,
        'ipc_draining' => $ipcDraining ? 1 : 0,
        'ts' => \microtime(true),
    ];
};
$sendExitReasonToMaster = static function (string $reason, int $code = 0, array $context = []) use (&$ipcClient, &$exitReasonSent, $buildWorkerRuntimeSnapshot): void {
    $reason = \trim($reason);
    if ($exitReasonSent || $reason === '' || !$ipcClient || !$ipcClient->isConnected()) {
        return;
    }

    try {
        $ipcClient->send(\Weline\Server\IPC\ControlMessage::exitReason(
            \substr($reason, 0, 512),
            $code,
            \array_merge($buildWorkerRuntimeSnapshot('exit_reason'), $context)
        ));
        $ipcClient->flushPendingWrites(0.2);
        $exitReasonSent = true;
    } catch (\Throwable) {
        // Best-effort observability only; exit must not be blocked by IPC state.
    }
};

$gracefulExit = function (string $reason = '', bool $waitForRequests = true) use ($socket, &$connections, &$requestBuffers, &$connectionLastActivity, &$activeRequests, $processName, $gracefulShutdownTimeout, $stopShutdownTimeout, &$ipcClient, $workerId, $port, $isMaintenanceWorker, &$plannedExitReason, $sendExitReasonToMaster) {
    // 刷新日志缓冲区
    WlsLogger::flush_(true);
    
    // 记录退出原因
    $effectiveExitReason = $plannedExitReason !== '' ? $plannedExitReason : $reason;
    if ($effectiveExitReason) {
        $sendExitReasonToMaster($effectiveExitReason);
        w_log_info("[WLS Worker] 退出原因: {$effectiveExitReason}");
        WlsLogger::info_("优雅关闭: {$effectiveExitReason}");
    }
    
    // 停止接受新连接（关闭监听 socket；仅对有效 stream 调用 fclose，避免已关闭 resource 导致 TypeError）
    if (\is_resource($socket) && \get_resource_type($socket) === 'stream') {
        @\fclose($socket);
    }
    WlsLogger::info_("已停止接受新连接");
    
    // 停止服务器用短超时，热重载用长超时（与 Windows 一致）
    $waitTimeout = (\str_contains($reason, '热重载') || $reason === '') ? $gracefulShutdownTimeout : $stopShutdownTimeout;
    
    // 等待活跃请求完成
    if ($waitForRequests && !empty($connections)) {
        $waitStart = \time();
        WlsLogger::info_("等待 " . \count($connections) . " 个活跃连接完成...");
        
        while (!empty($connections) && (\time() - $waitStart) < $waitTimeout) {
            // 继续处理现有连接的数据
            $read = $connections;
            $write = [];
            $except = [];
            
            $changed = @\stream_select($read, $write, $except, 1, 0);
            if ($changed === false) {
                break;
            }
            
            foreach ($read as $conn) {
                $connId = \get_resource_id($conn);
                $data = @\fread($conn, 65535);
                
                if ($data === false || $data === '') {
                    // 连接已关闭
                    if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
                        @\fclose($conn);
                    }
                    unset($connections[$connId]);
                    unset($requestBuffers[$connId]);
                    unset($connectionLastActivity[$connId]);
                }
            }
            
            // 每秒检查一次
            if (!empty($connections)) {
                $remaining = \count($connections);
                $elapsed = \time() - $waitStart;
                WlsLogger::info_("等待中... 剩余 {$remaining} 个连接，已等待 {$elapsed}s");
            }
        }
        
        $elapsed = \time() - $waitStart;
        if (!empty($connections)) {
            WlsLogger::warning_("超时 ({$elapsed}s)，强制关闭 " . \count($connections) . " 个连接");
        } else {
            WlsLogger::info_("所有连接已完成 ({$elapsed}s)");
        }
    }
    
    // 关闭剩余连接（仅对有效 stream 调用 fclose，避免已关闭 resource 导致 TypeError）
    foreach ($connections as $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            @\fclose($conn);
        }
    }
    
    // 清理连接相关数据
    $connections = [];
    $requestBuffers = [];
    $connectionLastActivity = [];
    
    // 刷新异步日志缓冲
    \Weline\Server\Service\AsyncLogger::flushAll();
    
    // 通知 Master 即将退出（IPC exited 消息）
    if ($ipcClient && $ipcClient->isConnected()) {
        $exitRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
        $ipcClient->send(\Weline\Server\IPC\ControlMessage::exited($exitRole, \getmypid(), $port, $workerId));
        $ipcClient->flushPendingWrites(0.2);
        WlsLogger::info_("已发送 exited 消息给 Master");
    }
    
    // Master owns process-record cleanup; child exit must not block on shared
    // PID/name/port index locks.
    
    WlsLogger::info_("Worker 已退出");
    exit(0);
};

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
        // 关键修复：reload 时不立即关闭 socket，继续接受新连接并快速响应
        // 这样可以避免连接在内核队列中自旋等待，直到新 Worker 启动
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

// 进入事件循环后向 Master 上报一次（IPC 重连后会再次上报）
$workerLoopStartedSent = false;
$sharedRuntimeConnectionWarmupStarted = false;
$sharedRuntimeConnectionWarmupNotBefore = \microtime(true);
$deferredWorkerBootstrapWarmupStarted = false;
$deferredWorkerBootstrapWarmupNotBefore = \microtime(true);

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
        WlsLogger::info_("[Worker] 主循环未被阻塞 #{$workerLoopCount}");
        // Preserve the legacy mojibake line in a dead branch to avoid risky re-encoding of this script.
        if (false) {
        WlsLogger::info_("[Worker] 循环未被阻塞 #{$workerLoopCount} #{$workerLoopCount}");
        }
    }

    // 定期刷新日志缓冲区（避免日志堆积）
    WlsLogger::flush_(false);

    $now = \time();

    // ========== 定时GC触发（防止内存泄漏） ==========
    // 每60秒触发一次主动GC，减少内存占用
    if (!isset($lastGcTime)) {
        $lastGcTime = $now;
    }
    if ($now - $lastGcTime >= 60) {
        $lastGcTime = $now;
        $gcResult = wlsCompactWorkerMemoryCaches('timer', $maxMemoryBytes, 0.70, 32 * 1024 * 1024);
        if ($gcResult['cycles'] > 0 || $gcResult['trimmed_bytes'] > 0) {
            WlsLogger::debug_("[GC] cycles={$gcResult['cycles']}, trimmed={$gcResult['trimmed_bytes']} bytes");
        }
    }

    // ========== 孤儿检测（IPC 优先） ==========
    if ($orphanGuard->shouldExit(
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
            $nextRetryDelay = 5 + \min($ipcReconnectAttempts, 10);
            $ipcReconnectDueTime = \microtime(true) + $nextRetryDelay;
        }
    }
    
    if ($ipcClient && !$ipcClient->isConnected() && !$ipcReceivedShutdown) {
        $ipcClient->tryReconnect();
    }
    if ($ipcClient && !$ipcClient->isConnected()) {
        $workerLoopStartedSent = false;
    }
    if ($ipcClient && $ipcClient->isConnected() && !$waitingForAck && !$workerLoopStartedSent) {
        $ipcClient->sendWorkerLoopStarted($workerId, $port, (int) \getmypid());
        $workerLoopStartedSent = true;
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

    // ========== Deferred worker bootstrap warmup ==========
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

    // ========== ACK wait timeout check ==========
    if ($waitingForAck && $ipcClient && $ipcClient->isConnected()) {
        $ackElapsed = \microtime(true) - $readySentTime;
        if ($ackElapsed >= $ackTimeout) {
            $ackRetryCount++;
            // 直到收到闭环确认前持续重发 ready（不中断）
            WlsLogger::warning_("Master ACK 确认结果：超时未确认（{$ackElapsed}s），第 {$ackRetryCount} 次重发 ready...");
            $ipcClient->sendReady($ipcRole, $workerId, $port, $orchestratorEpoch, $orchestratorLaunchId);
            $readySentTime = \microtime(true);
        }
    }

    // ========== 连接延迟策略 ==========
    // Worker 不在主循环中进行 Session/Memory 连接
    // 所有连接由框架按需在首次使用时自动建立（Lazy Initialization）
    
    // 检查是否需要优雅退出（排水模式）
    if ($shouldExit) {
        if ($ipcDraining) {
            if (!empty($longLivedConnections)) {
                foreach (\array_keys($longLivedConnections) as $cid) {
                    if (isset($connections[$cid]) && \is_resource($connections[$cid])) {
                        @\fclose($connections[$cid]);
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
            // ========== 排水模式：快速清理连接，加速退出 ==========
            $drainElapsed = $drainStartTime > 0 ? (\time() - $drainStartTime) : 0;

            // 关键修复：reload 排水期间继续接受新连接，但立即返回 503 Service Unavailable
            // 这样可以避免连接在内核队列中自旋等待，客户端会收到明确的响应
            // 只有在排水超过 5 秒后才关闭 socket（给新 Worker 足够的启动时间）
            if ($drainElapsed >= 5 && $socket && \is_resource($socket)) {
                @\fclose($socket);
                $socket = null;
                WlsLogger::info_("排水超过 5 秒，已关闭监听 socket");
            }

            // 1. 立即关闭所有空闲 Keep-Alive 连接（无请求数据）
            foreach ($connections as $cid => $cconn) {
                $hasReqData = isset($requestBuffers[$cid]) && $requestBuffers[$cid] !== '';
                if (!$hasReqData) {
                    @\fclose($cconn);
                    unset($connections[$cid], $requestBuffers[$cid], $connectionLastActivity[$cid], $requestLogged[$cid]);
                }
            }

            // 2. 所有连接已清空 → 排水完成（帝王令：若已收 shutdown，做完排水仍以 shutdown 名义退出）
            if (empty($connections)) {
                if ($plannedExitReason === '') {
                    $plannedExitReason = $ipcReceivedShutdown
                        ? "shutdown_command:worker={$workerId}"
                        : "drain_or_reload:worker={$workerId}";
                }
                $sendExitReasonToMaster($plannedExitReason);
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->sendDrainingComplete($workerId, $port, '', $plannedExitReason);
                    $ipcClient->flushPendingWrites(0.2);
                }
                WlsLogger::info_("排水完成（{$drainElapsed}秒），Worker 退出");
                $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载');
            }
            
            // 3. 排水超时 → 强制关闭所有剩余连接
            if ($drainElapsed >= $maxDrainTime) {
                $remaining = \count($connections);
                WlsLogger::warning_("排水超时（{$drainElapsed}秒 >= {$maxDrainTime}秒），强制关闭剩余 {$remaining} 个连接");
                foreach ($connections as $cid => $cconn) {
                    @\fclose($cconn);
                }
                $connections = [];
                $requestBuffers = [];
                $connectionLastActivity = [];
                $requestLogged = [];
                $writeBuffers = [];
                $writableConnections = [];
                $pendingClose = [];
                $longLivedConnections = [];
                $activeFibers = [];
                
                if ($plannedExitReason === '') {
                    $plannedExitReason = $ipcReceivedShutdown
                        ? "shutdown_command_timeout:worker={$workerId},remaining={$remaining}"
                        : "drain_or_reload_timeout:worker={$workerId},remaining={$remaining}";
                }
                $sendExitReasonToMaster($plannedExitReason);
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->sendDrainingComplete($workerId, $port, '', $plannedExitReason);
                    $ipcClient->flushPendingWrites(0.2);
                }
                $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载（超时强制退出）');
            }
        } elseif (empty($connections)) {
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
                @\fclose($conn);
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
        }
    }
    
    // 内存监控：检测内存使用是否超限，超限则优雅重启
    if ($now - $lastMemoryCheck >= $memoryCheckInterval) {
        $lastMemoryCheck = $now;
        $currentMemory = \memory_get_usage(true);
        $currentMemoryUsed = \memory_get_usage(false);
        $memoryPercent = $maxMemoryBytes > 0 ? $currentMemoryUsed / $maxMemoryBytes : 0.0;

        if ($memoryPercent >= $memoryDrainThreshold) {
            $beforeMb = \round($currentMemoryUsed / 1024 / 1024, 1);
            $beforeAllocatedMb = \round($currentMemory / 1024 / 1024, 1);
            $compaction = wlsCompactWorkerMemoryCaches('drain_threshold', $maxMemoryBytes, 0.0, 0, true);
            $currentMemory = \memory_get_usage(true);
            $currentMemoryUsed = \memory_get_usage(false);
            $memoryPercent = $maxMemoryBytes > 0 ? $currentMemoryUsed / $maxMemoryBytes : 0.0;
            $afterMb = \round($currentMemoryUsed / 1024 / 1024, 1);
            $afterAllocatedMb = \round($currentMemory / 1024 / 1024, 1);

            if ($memoryPercent >= $memoryDrainThreshold) {
                WlsLogger::warning_(
                    "Worker memory pressure {$afterMb}MB used ({$afterAllocatedMb}MB allocated) after compact "
                    . "(before={$beforeMb}MB used, before_allocated={$beforeAllocatedMb}MB), start drain to avoid OOM reset"
                );
                $plannedExitReason = 'memory_pressure_drain'
                    . ":worker={$workerId}"
                    . ",memory={$afterMb}MB"
                    . ",before={$beforeMb}MB"
                    . ",allocated={$afterAllocatedMb}MB"
                    . ",before_allocated={$beforeAllocatedMb}MB"
                    . ",limit={$wlsMemoryLimit}"
                    . ',threshold=' . \round($memoryDrainThreshold * 100, 1) . '%'
                    . ",requests={$requestCount}";
                $sendExitReasonToMaster($plannedExitReason);
                $shouldExit = true;
                $ipcDraining = true;
                $drainStartTime = \time();
                $maxDrainTime = \min($maxDrainTime, 10);
                if ($socket && \is_resource($socket)) {
                    @\fclose($socket);
                    $socket = null;
                }
            } elseif ($memoryPercent >= $memoryWarningThreshold) {
                WlsLogger::warning_(
                    "Worker memory high {$afterMb}MB used ({$afterAllocatedMb}MB allocated) after compact "
                    . "(before={$beforeMb}MB used, before_allocated={$beforeAllocatedMb}MB, cycles="
                    . (int)($compaction['cycles'] ?? 0) . ")"
                );
            }
        } elseif ($memoryPercent >= $memoryWarningThreshold) {
            WlsLogger::warning_(
                "Worker memory high: " . \round($currentMemoryUsed / 1024 / 1024, 1)
                . 'MB used (' . \round($currentMemory / 1024 / 1024, 1) . 'MB allocated)'
            );
        }
        
        // 定期记录 Worker 状态到数据库
        if ($ipcClient && $ipcClient->isConnected()) {
            try {
                $ipcClient->send(\Weline\Server\IPC\ControlMessage::statusReport(
                    \count($connections),
                    $currentMemory,
                    $requestCount,
                    $buildWorkerRuntimeSnapshot('periodic_status')
                ), false);
            } catch (\Throwable) {
                // Runtime diagnostics are best-effort and must not affect traffic.
            }
        }

        try {
            \Weline\Server\Service\StatusLogService::logWorkerStatus([
                'instance' => $instanceName,
                'worker_id' => $workerId,
                'port' => $port,
                'pid' => \getmypid(),
                'connections' => \count($connections),
                'active_requests' => $activeRequests,
                'total_requests' => $requestCount,
                'memory_usage' => $currentMemory,
                'memory_peak' => \memory_get_peak_usage(true),
                'uptime' => $now - $startTime,
                'ssl' => false,
            ]);
        } catch (\Throwable $e) {
            // 忽略日志记录失败
        }
    }
    
    // 最大请求数限制：处理超过指定请求数后优雅重启（可选的内存保护）
    if ($maxRequests > 0 && $requestCount >= $maxRequests && empty($connections)) {
        WlsLogger::info_("已处理 {$requestCount} 个请求，达到上限 {$maxRequests}，触发优雅重启");
        $plannedExitReason = "max_requests_recycle:worker={$workerId},requests={$requestCount},limit={$maxRequests}";
        $sendExitReasonToMaster($plannedExitReason);
        $shouldExit = true;
    }
    
    // 构建 stream_select 读数组
    // 重要：长连接（SSE/WebSocket）不应该参与读事件检测，因为客户端不会发送数据
    // 如果把 SSE 连接放在读数组中，stream_select 会一直等到超时（最长100ms），造成延迟累积
    $readSockets = [];
    $readableClientCount = 0;
    if ($socket && \is_resource($socket)) {
        $readSockets[] = $socket;
    }
    // 只把普通连接加入读数组，排除长连接（它们只在 write 数组中等待可写状态）
    foreach ($connections as $connId => $conn) {
        if (!isset($longLivedConnections[$connId])) {
            $readSockets[] = $conn;
            $readableClientCount++;
        }
    }

    // 加入 IPC 控制 socket
    $ipcSocket = ($ipcClient && $ipcClient->isConnected()) ? $ipcClient->getSocket() : null;
    if ($ipcSocket && \is_resource($ipcSocket)) {
        $readSockets[] = $ipcSocket;
    }
    
    $read = $readSockets;
    $write = [];
    foreach ($writableConnections as $wc) {
        if (\is_resource($wc)) {
            $write[] = $wc;
        }
    }
    if ($ipcSocket && $ipcClient && $ipcClient->hasPendingWrites()) {
        $write[] = $ipcSocket;
    }
    $except = [];

    // EventLoop + CoroutineRuntime：统一等待语义（select/event 后端可切换）
    $loopWaitUsec = ($readableClientCount > 0 || $write !== []) ? 1000 : 100000;
    $changed = $coroutineRuntime->wait($read, $write, $except, $loopWaitUsec);
    // #endregion

    // 调度器 tick：处理到期定时器，resume 前恢复该 Fiber 的请求级上下文
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
    
    wlsProcessActiveFibersAfterTick(
        $fiberScheduler,
        $activeFibers,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $ipcClient,
        $instanceName,
        $activeRequests,
        $ipcDraining,
        $longLivedConnections,
        $writeBuffers,
        $writableConnections,
        $pendingClose
    );

    // 更新 Fiber 快照供健康检查 /_wls/health?detail=1&fibers=1 读取
    \Weline\Server\Runtime\WorkerFiberSnapshot::setSnapshot(\Weline\Server\Runtime\WorkerFiberHealthSnapshot::build($activeFibers));

    wlsReleaseIdleFibersStep(
        $fiberScheduler,
        $activeFibers,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $longLivedConnections,
        $activeRequests,
        $envConfig,
        $fiberIdleTtlSec,
        $fiberReleaseIdleRequested,
        $lastFiberIdleCheck,
        $writeBuffers,
        $writableConnections,
        $pendingClose
    );

    // Fiber tick 后尽早刷写队列，减轻 SSE 与同 Worker 其它请求之间的写方向头阻塞（与 worker_ssl 一致）
    wlsHttpFlushQueuedWrites(
        $writableConnections,
        $writeBuffers,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $pendingClose,
        $longLivedConnections
    );
    
    if ($changed === false) {
        continue;
    }
    
    // 处理 IPC 控制通道消息
    if ($ipcSocket && \in_array($ipcSocket, $read, true)) {
        if ($ipcClient) {
            $ipcClient->handleReadable();
        }
        // 从 $read 中移除 IPC socket，避免在后续的客户端连接处理循环中被误处理
        $ipcKey = \array_search($ipcSocket, $read, true);
        if ($ipcKey !== false) {
            unset($read[$ipcKey]);
        }
    }
    if ($ipcSocket && \in_array($ipcSocket, $write, true) && $ipcClient) {
        $ipcClient->handleWritable();
    }
    
    wlsAcceptHttpConnections(
        $socket,
        $read,
        $ipcDraining,
        $connections,
        $requestBuffers,
        $connectionLastActivity
    );
    
    // 处理连接
    foreach ($read as $conn) {
        $connId = \get_resource_id($conn);

        if (\Weline\Server\Service\ConnectionReadWriteGuard::shouldDeferRead(
            $writeBuffers,
            $pendingClose,
            $connId,
            isset($activeFibers[$connId])
        )) {
            continue;
        }

        $readStep = wlsHttpReadStep(
            $conn,
            $connId,
            $isFrontend,
            $fiberScheduler,
            $activeFibers,
            $longLivedConnections,
            $connections,
            $requestBuffers,
            $connectionLastActivity,
            $requestLogged,
            $requestCount,
            $activeRequests,
            $writeBuffers,
            $writableConnections,
            $pendingClose
        );
        if (($readStep['closed'] ?? false) === true) {
            continue;
        }
        if (($readStep['request_ready'] ?? false) !== true) {
            continue;
        }

        $rawRequest = (string) ($readStep['raw_request'] ?? '');
        $requestBuffers[$connId] = '';
        if (!isset($requestLogged[$connId])) {
            $requestCount++;
        }
        unset($requestLogged[$connId]); // 清理标记（如果不存在也不会报错）
        $activeRequests++;

        if ($requestGcInterval > 0 && $requestCount - $lastRequestGcCount >= $requestGcInterval) {
            $lastRequestGcCount = $requestCount;
            $compaction = wlsCompactWorkerMemoryCaches('request_interval', $maxMemoryBytes, 0.55, 16 * 1024 * 1024);
            $currentMemory = \memory_get_usage(true);
            $memoryPeak = \memory_get_peak_usage(true);
            $runtimeCompactions = (array)($compaction['runtime_cache_compactions'] ?? []);
            $staticCacheCompaction = (array)($compaction['static_file_cache'] ?? []);
            if (
                ($compaction['cycles'] ?? 0) > 0
                || ($compaction['trimmed_bytes'] ?? 0) > 0
                || (int)($runtimeCompactions['cleared_process_caches'] ?? 0) > 0
                || ($staticCacheCompaction['cleared'] ?? false)
                || $currentMemory > 150 * 1024 * 1024
            ) {
                WlsLogger::debug_(
                    '[GC] request_compact worker=http requests=' . $requestCount
                    . ' cycles=' . (int)($compaction['cycles'] ?? 0)
                    . ' trimmed=' . (int)($compaction['trimmed_bytes'] ?? 0)
                    . ' memory=' . \round($currentMemory / 1024 / 1024, 1) . 'MB'
                    . ' peak=' . \round($memoryPeak / 1024 / 1024, 1) . 'MB'
                    . ' static=' . (($staticCacheCompaction['cleared'] ?? false) ? 'cleared' : 'kept')
                    . ':' . (int)($staticCacheCompaction['count'] ?? 0)
                    . ':' . (int)($staticCacheCompaction['size'] ?? 0)
                    . ' runtime=' . (\json_encode($runtimeCompactions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')
                );
            }
        }

        // 解析请求 URI（用于日志，如果前端模式已输出则跳过）
        if (!$isFrontend) {
            $uri = '/';
            if (\preg_match('/^\w+\s+([^\s]+)/', $rawRequest, $matches)) {
                $uri = \parse_url($matches[1], PHP_URL_PATH) ?? '/';
            }
            $method = 'GET';
            if (\preg_match('/^(\w+)\s+/', $rawRequest, $matches)) {
                $method = $matches[1];
            }
            $requestLogPrefix = InternalRequestLabel::buildLogPrefix($rawRequest);
            if ($requestLogPrefix !== '') {
                $method = $requestLogPrefix . $method;
            }
            WlsLogger::debug_("收到请求: {$method} {$uri} (connId: {$connId}, requestCount: {$requestCount})");
        }
        
        wlsDispatchRequestFiberStep(
            $conn,
            $connId,
            $rawRequest,
            $fiberMaxActive,
            $longLivedMaxActive,
            $longLivedProtocolResolver,
            $activeFibers,
            $longLivedConnections,
            $connections,
            $requestBuffers,
            $connectionLastActivity,
            $requestLogged,
            $activeRequests,
            $fiberScheduler,
            $runtime,
            $runtimeError,
            $asyncBizAdapters,
            $instanceName,
            $workerId,
            $port,
            $requestCount,
            $startTime,
            $originToken,
            $originTokenValidationEnabled,
            $originTokenHeader,
            $originTokenAllowLocal,
            $ipcClient,
            $fiberResults,
            $WLS_UOPZ_EXIT_GUARD,
            $ipcDraining,
            $longLivedSaturationReported,
            $longLivedSaturationCleared,
            $lastLongLivedSaturationReport,
            $longLivedSaturationInterval,
            $writeBuffers,
            $writableConnections,
            $pendingClose
        );
        
    }
    
    // 重置连续错误计数（本轮循环成功完成）
    $consecutiveErrors = 0;
    
    } catch (\Throwable $loopException) {
        // Workerman 模式：捕获所有异常，防止 Worker 意外退出
        $consecutiveErrors++;
        $errorMessage = $loopException->getMessage();
        $errorFile = $loopException->getFile();
        $errorLine = $loopException->getLine();
        
        // 记录错误日志
        w_log_error("[WLS Worker #{$workerId}] 事件循环异常 ({$consecutiveErrors}/{$maxConsecutiveErrors}): {$errorMessage} in {$errorFile}:{$errorLine}");
        WlsLogger::error_("事件循环异常: {$errorMessage}");
        
        // 刷新日志缓冲区
        WlsLogger::flush_(true);
        
        // 如果连续错误过多，优雅退出让 Master 重启
        if ($consecutiveErrors >= $maxConsecutiveErrors) {
            w_log_error("[WLS Worker #{$workerId}] 连续错误过多，优雅退出");
            $gracefulExit("连续错误过多 ({$consecutiveErrors} 次)");
        }
        
        // 短暂休眠后继续（避免错误风暴）
        \Weline\Framework\Runtime\SchedulerSystem::usleep(10000); // 10ms
        continue;
    }
}

/**
 * Step-0.5: 调度器 tick 之后推进活跃 Fiber（已完成写回、挂起快照续约）。
 *
 * @param array<int, array<string, mixed>> $activeFibers
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<int, bool> $requestLogged
 * @param array<int, array<string, mixed>> $longLivedConnections
 */
/**
 * Step-0.4: 统一处理“已终止 Fiber”的响应回写与连接清理。
 *
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<int, bool> $requestLogged
 * @param array<int, array<string, mixed>> $longLivedConnections
 */
function wlsFinalizeTerminatedFiberResponseStep(
    \Fiber $fiber,
    mixed $conn,
    int $connId,
    string $rawRequest,
    float $handleStartTime,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    mixed $ipcClient,
    string $instanceName,
    int &$activeRequests,
    bool $ipcDraining,
    array &$longLivedConnections,
    bool $isSseProtocolRequest,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose
): void {
    $response = '';
    try {
        $response = $fiber->getReturn() ?? '';
    } catch (\Throwable) {
    } finally {
        \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($fiber);
    }

    $handleDuration = \round((\microtime(true) - $handleStartTime) * 1000, 2);
    $response = injectWlsProcessTimeHeader($response, $handleDuration);
    $currentConn = $connections[$connId] ?? null;
    $hasLiveCurrentConn = \is_resource($currentConn) && \in_array(\get_resource_type($currentConn), ['stream', 'Socket'], true);
    if ($hasLiveCurrentConn) {
        // 关键：Fiber 结束时优先使用连接表中的当前连接句柄，避免使用闭包捕获的陈旧资源。
        // 在高并发/复用边界下，陈旧句柄会导致收尾阶段误写/误关，触发 SSE 尾包丢失。
        $conn = $currentConn;
        sendResponseAndCleanup(
            $conn,
            $connId,
            $response,
            $rawRequest,
            $connections,
            $requestBuffers,
            $connectionLastActivity,
            $requestLogged,
            $ipcClient,
            $instanceName,
            $activeRequests,
            $handleDuration,
            $ipcDraining,
            $longLivedConnections,
            $isSseProtocolRequest,
            $writeBuffers,
            $writableConnections,
            $pendingClose
        );
        return;
    }

    $activeRequests--;
    \Weline\Framework\Http\Sse\SseContext::reset();
}

function wlsProcessActiveFibersAfterTick(
    \Weline\Server\Scheduler\FiberScheduler $fiberScheduler,
    array &$activeFibers,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    mixed $ipcClient,
    string $instanceName,
    int &$activeRequests,
    bool $ipcDraining,
    array &$longLivedConnections,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose
): void {
    foreach ($activeFibers as $afConnId => $afData) {
        $af = $afData['fiber'];
        if ($af->isTerminated()) {
            if (isset($afData['context'])) {
                // Fiber 终止时恢复其上下文（不恢复响应状态，因为响应已发送）
                $afData['context']->restore(false);
            }

            $fiberScheduler->unregisterFiber();
            wlsFinalizeTerminatedFiberResponseStep(
                $af,
                $afData['conn'] ?? null,
                $afConnId,
                (string) ($afData['rawRequest'] ?? ''),
                (float) ($afData['handleStartTime'] ?? \microtime(true)),
                $connections,
                $requestBuffers,
                $connectionLastActivity,
                $requestLogged,
                $ipcClient,
                $instanceName,
                $activeRequests,
                $ipcDraining,
                $longLivedConnections,
                (bool) ($afData['is_sse_protocol'] ?? false),
                $writeBuffers,
                $writableConnections,
                $pendingClose
            );
            unset($activeFibers[$afConnId]);
            continue;
        }

        if ($af->isSuspended()) {
            $activeFibers[$afConnId] = $afData;
        }
    }
}

/**
 * Step-0.6: 周期回收闲置/僵死 Fiber，释放连接与调度器定时器。
 *
 * @param array<int, array<string, mixed>> $activeFibers
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<int, bool> $requestLogged
 * @param array<int, array<string, mixed>> $longLivedConnections
 * @param array<string, mixed> $envConfig
 */
function wlsReleaseIdleFibersStep(
    \Weline\Server\Scheduler\FiberScheduler $fiberScheduler,
    array &$activeFibers,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$longLivedConnections,
    int &$activeRequests,
    array $envConfig,
    int $fiberIdleTtlSec,
    bool &$fiberReleaseIdleRequested,
    int &$lastFiberIdleCheck,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose
): void {
    $now = \time();
    $idleCheckInterval = 5;
    $doReleaseIdle = $fiberReleaseIdleRequested || ($fiberIdleTtlSec > 0 && $now - $lastFiberIdleCheck >= $idleCheckInterval);
    if (!$doReleaseIdle || $activeFibers === []) {
        return;
    }

    $lastFiberIdleCheck = $now;
    $fiberReleaseIdleRequested = false;
    $releaseThreshold = $fiberIdleTtlSec > 0 ? $fiberIdleTtlSec : 0;
    $toRelease = [];

    $fiberHeartbeatTimeout = 60;
    if (isset($envConfig['wls']['fiber']['heartbeat_timeout'])) {
        $fiberHeartbeatTimeout = (int)$envConfig['wls']['fiber']['heartbeat_timeout'];
    }

    foreach ($activeFibers as $afConnId => $afData) {
        $suspendedAt = $afData['suspended_at'] ?? $now;
        $lastActivity = $afData['last_activity'] ?? $afData['handleStartTime'] ?? $now;
        $inactiveTime = $now - $lastActivity;
        $isLongLived = $afData['is_long_lived'] ?? false;

        // 长连接（SSE 等）不参与心跳超时检查，由客户端/服务端正常断开管理其生命周期
        if (!$isLongLived && $fiberHeartbeatTimeout > 0 && $inactiveTime >= $fiberHeartbeatTimeout) {
            WlsLogger::warning_("Fiber 心跳超时: connId={$afConnId} inactive_time={$inactiveTime}s (超过 {$fiberHeartbeatTimeout}s 未续约)");
            $toRelease[$afConnId] = $afData;
            continue;
        }

        // 非长连接的闲置回收
        if (!$isLongLived && $releaseThreshold > 0 && ($now - $suspendedAt) >= $releaseThreshold) {
            $toRelease[$afConnId] = $afData;
        }
    }

    foreach ($toRelease as $afConnId => $afData) {
        $fiberScheduler->cancelTimersForFiber($afData['fiber']);
        \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($afData['fiber']);
        if (isset($afData['conn']) && \is_resource($afData['conn'])) {
            @\fclose($afData['conn']);
        }
        unset(
            $connections[$afConnId],
            $requestBuffers[$afConnId],
            $connectionLastActivity[$afConnId],
            $requestLogged[$afConnId],
            $writeBuffers[$afConnId],
            $writableConnections[$afConnId],
            $pendingClose[$afConnId],
            $activeFibers[$afConnId]
        );
        if (isset($longLivedConnections[$afConnId])) {
            unset($longLivedConnections[$afConnId]);
        }
        $activeRequests--;
        $fiberScheduler->unregisterFiber();
    }

    $released = \count($toRelease);
    if ($released > 0) {
        WlsLogger::info_("Fiber 池释放闲置: {$released} 个 (connIds 已关闭)");
    }
}

/**
 * Step-1: 接入普通 HTTP 连接（排水期直接 503）。
 *
 * @param resource|null $socket
 * @param array<int, resource> $read
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 */
function wlsAcceptHttpConnections(
    mixed $socket,
    array &$read,
    bool $ipcDraining,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity
): void {
    if (!$socket || !\is_resource($socket) || !\in_array($socket, $read, true)) {
        return;
    }

    $conn = @\stream_socket_accept($socket, 0);
    if ($conn) {
        \stream_set_blocking($conn, false);
        $connId = \get_resource_id($conn);
        if ($ipcDraining) {
            $drainBody = 'Server is reloading, please retry in a moment';
            $drainResponse = "HTTP/1.1 503 Service Unavailable\r\n";
            $drainResponse .= "Content-Type: text/plain; charset=utf-8\r\n";
            $drainResponse .= "Retry-After: 2\r\n";
            $drainResponse .= "Connection: close\r\n";
            $drainResponse .= "Content-Length: " . \strlen($drainBody) . "\r\n\r\n";
            $drainResponse .= $drainBody;
            @\fwrite($conn, $drainResponse);
            @\fclose($conn);
            WlsLogger::info_("排水期间拒绝新连接 (connId: {$connId})，已返回 503");
        } else {
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = \time();
        }
    }

    $key = \array_search($socket, $read, true);
    if ($key !== false) {
        unset($read[$key]);
    }
}

/**
 * 将 writeBuffers 中非阻塞写入 TCP 流（与 worker_ssl 的 wlsSslFlushQueuedWrites 同源策略）。
 *
 * @param array<int, resource> $writableConnections
 * @param array<int, string> $writeBuffers
 */
function wlsHttpFlushQueuedWrites(
    array &$writableConnections,
    array &$writeBuffers,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$pendingClose,
    array &$longLivedConnections
): void {
    $maxBytesPerConnectionPerLoop = 131072;
    $maxChunkPerWrite = 16384;
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
                @\fclose($conn);
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
                break;
            }
            $remainingBudget = $maxBytesPerConnectionPerLoop - $totalWrittenThisLoop;
            $writeLen = \min($bufferLen, $maxChunkPerWrite, $remainingBudget);
            if ($writeLen <= 0) {
                break;
            }

            $written = @\fwrite($conn, \substr($buffer, 0, $writeLen));

            if ($written === false) {
                WlsLogger::warning_("HTTP 写队列写入失败 (connId: {$connId}, 剩余: {$bufferLen} 字节)");
                @\fclose($conn);
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
                    @\fclose($conn);
                    unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $pendingClose[$connId]);
                    unset($longLivedConnections[$connId]);
                }
                if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($initialBufferLen)) {
                    \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                }
                if (\class_exists(\Weline\Framework\Runtime\PostResponseTaskQueue::class)) {
                    \Weline\Framework\Runtime\PostResponseTaskQueue::drain(
                        (float)(\Weline\Framework\App\Env::get('wls.post_response_task_budget_ms', 8) ?: 8)
                    );
                }
                break;
            }
        }
    }
}

/**
 * SSE 写入接入 HTTP Worker 写缓冲，并协作等待排空（与 worker_ssl enqueueSseWriteAndAwaitDrain 对齐）。
 */
function wlsHttpIsSseClientConnected(
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

    if (\function_exists('socket_import_stream') && \function_exists('socket_recv')) {
        $socket = @\socket_import_stream($conn);
        if ($socket !== false) {
            $peekBuffer = '';
            $peekFlag = \defined('MSG_PEEK') ? (int) \constant('MSG_PEEK') : 2;
            $peek = @\socket_recv($socket, $peekBuffer, 1, $peekFlag);
            if ($peek === 0) {
                return false;
            }
            if ($peek === false) {
                $error = \socket_last_error($socket);
                if (\function_exists('socket_clear_error')) {
                    \socket_clear_error($socket);
                }
                // EAGAIN/EWOULDBLOCK/WSAEWOULDBLOCK：无数据可读，连接仍可能正常
                if (\in_array($error, [11, 35, 10035], true)) {
                    return true;
                }
                // 其它 errno 下 MSG_PEEK 不可靠（TLS/平台差异），勿直接判死；
                // 误判会导致 SSE 仍在上游吐 token 时 isAlive=false → 业务停写、浏览器像「发包被掐断」。
                return !@\feof($conn);
            }
        }
    }

    return !@\feof($conn);
}

function wlsHttpEnqueueSseWriteAndAwaitDrain(
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

    $streamOk = isset($connections[$connId])
        && \is_resource($conn)
        && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true);

    if (!$streamOk) {
        if (\is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
            @\fclose($conn);
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

    // 防止资源 ID 复用污染：验证 $conn 是否仍是 $connections[$connId] 的同一资源
    // 如果 SSE 连接已关闭且 ID 被新连接复用，$conn !== $connections[$connId]，跳过写入
    if ($conn !== $connections[$connId]) {
        return false;
    }

    $currentBuffered = \strlen($writeBuffers[$connId] ?? '');
    $appendLen = \strlen($data);
    if (\Weline\Server\Service\WorkerResponseMemoryGuard::sseWriteBufferWouldExceed($currentBuffered, $appendLen)) {
        WlsLogger::warning_(
            'SSE 写缓冲超限，关闭连接 (connId: ' . $connId
            . ', buffered=' . $currentBuffered . ', append=' . $appendLen . ')'
        );
        @\fclose($conn);
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
 * Step-2: 普通 HTTP 连接读阶段推进。
 *
 * @param array<int, array<string, mixed>> $activeFibers
 * @param array<int, array<string, mixed>> $longLivedConnections
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<int, bool> $requestLogged
 * @return array{closed: bool, request_ready: bool, raw_request?: string}
 */
function wlsHttpReadStep(
    mixed $conn,
    int $connId,
    bool $isFrontend,
    \Weline\Server\Scheduler\FiberScheduler $fiberScheduler,
    array &$activeFibers,
    array &$longLivedConnections,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    int &$requestCount,
    int &$activeRequests,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose
): array {
    if (!\is_resource($conn) || !isset($connections[$connId]) || $connections[$connId] !== $conn) {
        unset(
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
        if (isset($activeFibers[$connId])) {
            $fiberScheduler->cancelTimersForFiber($activeFibers[$connId]['fiber']);
            \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($activeFibers[$connId]['fiber']);
            $fiberScheduler->unregisterFiber();
            unset($activeFibers[$connId]);
        }
        $activeRequests = \max(0, $activeRequests - 1);
        return ['closed' => true, 'request_ready' => false];
    }

    if (\Weline\Server\Service\ConnectionReadWriteGuard::shouldDeferRead(
        $writeBuffers,
        $pendingClose,
        $connId,
        isset($activeFibers[$connId])
    )) {
        return ['closed' => false, 'request_ready' => false];
    }

    $data = @\fread($conn, 65535);
    if ($data === false) {
        @\fclose($conn);
        unset(
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
            WlsLogger::info_("客户端断开，长连接已清理 (connId: {$connId}, 剩余长连接数: " . \count($longLivedConnections) . ")");
        }
        if (isset($activeFibers[$connId])) {
            $fiberScheduler->cancelTimersForFiber($activeFibers[$connId]['fiber']);
            \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($activeFibers[$connId]['fiber']);
            $fiberScheduler->unregisterFiber();
            unset($activeFibers[$connId]);
            WlsLogger::info_("客户端断开，Fiber 已清理 (connId: {$connId}, 剩余活跃 Fiber: " . \count($activeFibers) . ")");
        }
        $activeRequests = \max(0, $activeRequests - 1);
        return ['closed' => true, 'request_ready' => false];
    }
    if ($data === '') {
        // 非阻塞 socket 空读只表示“当前无数据可读”，并不等于连接断开。
        // 若在此直接关闭，会导致 SSE 长连接被误杀并触发浏览器重连。
        return ['closed' => false, 'request_ready' => false];
    }

    $connectionLastActivity[$connId] = \time();
    $requestBuffers[$connId] = ($requestBuffers[$connId] ?? '') . $data;

    $maxRequestSize = 10 * 1024 * 1024;
    if (\strlen($requestBuffers[$connId]) > $maxRequestSize) {
        WlsLogger::warning_("请求体过大，拒绝连接 (connId: {$connId}, size: " . \strlen($requestBuffers[$connId]) . ")");
        $errorResponse = "HTTP/1.1 413 Request Entity Too Large\r\n";
        $errorResponse .= "Content-Type: text/plain; charset=utf-8\r\n";
        $errorResponse .= "Connection: close\r\n";
        $errorResponse .= "Content-Length: 24\r\n\r\nRequest Entity Too Large";
        @\fwrite($conn, $errorResponse);
        @\fclose($conn);
        unset(
            $connections[$connId],
            $requestBuffers[$connId],
            $connectionLastActivity[$connId],
            $requestLogged[$connId],
            $writeBuffers[$connId],
            $writableConnections[$connId],
            $pendingClose[$connId]
        );
        return ['closed' => true, 'request_ready' => false];
    }

    if ($isFrontend && !isset($requestLogged[$connId])) {
        $firstLineEnd = \strpos($requestBuffers[$connId], "\r\n");
        if ($firstLineEnd !== false) {
            $requestLine = \substr($requestBuffers[$connId], 0, $firstLineEnd);
            if (\preg_match('/^(\w+)\s+([^\s]+)/', $requestLine, $matches)) {
                $method = $matches[1];
                $uri = \parse_url($matches[2], PHP_URL_PATH) ?? '/';
                $requestCount++;
                $requestLogPrefix = InternalRequestLabel::buildLogPrefix($requestBuffers[$connId]);
                if ($requestLogPrefix !== '') {
                    $method = $requestLogPrefix . $method;
                }
                WlsLogger::info_("→ {$method} {$uri}");
                if (\defined('STDOUT') && \is_resource(STDOUT)) {
                    \fflush(STDOUT);
                } elseif (\defined('STDERR') && \is_resource(STDERR)) {
                    \fflush(STDERR);
                }
                $requestLogged[$connId] = true;
            }
        }
    }

    if (!isRequestComplete($requestBuffers[$connId])) {
        return ['closed' => false, 'request_ready' => false];
    }

    return [
        'closed' => false,
        'request_ready' => true,
        'raw_request' => $requestBuffers[$connId],
    ];
}

/**
 * Step-3: 将完整 HTTP 请求投递到 Fiber，并根据同步/挂起分支推进状态。
 *
 * @param object $longLivedProtocolResolver
 * @param array<int, array<string, mixed>> $activeFibers
 * @param array<int, array<string, mixed>> $longLivedConnections
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<int, bool> $requestLogged
 * @param mixed $ipcClient
 * @param array<string, mixed> $fiberResults
 */
/**
 * @param array<int, array<string, mixed>> $activeFibers
 */
function wlsCountActiveFibersForAdmission(array $activeFibers): int
{
    $count = 0;
    foreach ($activeFibers as $fiberState) {
        if (($fiberState['is_sse_protocol'] ?? false) === true) {
            continue;
        }
        $count++;
    }

    return $count;
}

function wlsDispatchRequestFiberStep(
    mixed $conn,
    int $connId,
    string $rawRequest,
    int $fiberMaxActive,
    int $longLivedMaxActive,
    object $longLivedProtocolResolver,
    array &$activeFibers,
    array &$longLivedConnections,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    int &$activeRequests,
    \Weline\Server\Scheduler\FiberScheduler $fiberScheduler,
    mixed $runtime,
    mixed $runtimeError,
    \Weline\Server\Runtime\Async\AsyncBizAdapters $asyncBizAdapters,
    string $instanceName,
    int $workerId,
    int $port,
    int $requestCount,
    int|float $startTime,
    string $originToken,
    bool $originTokenValidationEnabled,
    string $originTokenHeader,
    bool $originTokenAllowLocal,
    mixed $ipcClient,
    array &$fiberResults,
    bool $WLS_UOPZ_EXIT_GUARD,
    bool $ipcDraining,
    bool &$longLivedSaturationReported,
    bool &$longLivedSaturationCleared,
    int &$lastLongLivedSaturationReport,
    int $longLivedSaturationInterval,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose
): void {
    $longLivedDetection = $longLivedProtocolResolver->detect($rawRequest);
    $isLongLived = ($longLivedDetection['is_long_lived'] ?? false) === true;
    $requestProtocol = (string) ($longLivedDetection['protocol'] ?? 'http');
    $isSseProtocolRequest = ($requestProtocol === 'sse');
    $applyLongLivedLimit = !$isSseProtocolRequest;

    $activeAdmissionFibers = wlsCountActiveFibersForAdmission($activeFibers);
    if (!$isSseProtocolRequest && $fiberMaxActive > 0 && $activeAdmissionFibers >= $fiberMaxActive) {
        $activeRequests--;
        $body = 'Service Unavailable';
        $resp = "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: " . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
        @\fwrite($conn, $resp);
        @\fclose($conn);
        unset(
            $connections[$connId],
            $requestBuffers[$connId],
            $connectionLastActivity[$connId],
            $requestLogged[$connId],
            $writeBuffers[$connId],
            $writableConnections[$connId],
            $pendingClose[$connId]
        );
        WlsLogger::warning_("Fiber 池已满 (max_active={$fiberMaxActive})，拒绝请求 (connId: {$connId})");
        return;
    }

    // 长连分层：见 SseMatcher / ProtocolResolver；protocol===sse 时与 worker_ssl 一样走写队列 + SseContext 回调。
    if ($isLongLived) {
        $layer = (string)($longLivedDetection['layer'] ?? 'unknown');
        $protocol = (string)($longLivedDetection['protocol'] ?? 'long-lived');
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
            $resp = "HTTP/1.1 429 Too Many Requests\r\nContent-Type: text/plain; charset=utf-8\r\nRetry-After: 2\r\nContent-Length: " . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
            @\fwrite($conn, $resp);
            @\fclose($conn);
            unset(
                $connections[$connId],
                $requestBuffers[$connId],
                $connectionLastActivity[$connId],
                $requestLogged[$connId],
                $writeBuffers[$connId],
                $writableConnections[$connId],
                $pendingClose[$connId]
            );
            WlsLogger::warning_("长连接池已满 (max_long_lived={$longLivedMaxActive})，拒绝长连接请求 (connId: {$connId})");
            return;
        }

        if ($applyLongLivedLimit) {
            $longLivedConnections[$connId] = [
                'type' => $protocol,
                'start' => \time(),
            ];
            WlsLogger::info_("长连接槽位已分配 (connId: {$connId}, protocol: {$protocol}, 当前长连接数: " . \count($longLivedConnections) . ")");
        } else {
            WlsLogger::info_("SSE 长连接不参与 long_lived_max_active 限制 (connId: {$connId}, protocol: {$protocol})");
        }
    }

    $handleStartTime = \microtime(true);
    $fiberConnId = $connId;
    $fiberConn = $conn;
    $requestFiber = new \Fiber(function () use (
        $rawRequest, $runtime, $runtimeError, $asyncBizAdapters, $instanceName, $workerId, $port,
        $requestCount, &$activeRequests, &$connections, $startTime,
        $originToken, $originTokenValidationEnabled, $originTokenHeader, $originTokenAllowLocal,
        $fiberConn, $fiberConnId, &$connectionLastActivity, &$requestBuffers, &$requestLogged,
        $ipcClient, &$fiberResults, $WLS_UOPZ_EXIT_GUARD,
        $isSseProtocolRequest,
        &$writeBuffers,
        &$writableConnections,
        &$pendingClose
    ) {
        wlsFiberRequestContextEnter($fiberConn, $fiberConnId);
        try {
            \Weline\Framework\Runtime\SchedulerSystem::yield();
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
                        return wlsHttpEnqueueSseWriteAndAwaitDrain(
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
                        return wlsHttpIsSseClientConnected(
                            $fiberConnId,
                            $fiberConn,
                            $connections,
                            $pendingClose
                        );
                    }
                );
            }
            return handleRequest(
                $rawRequest,
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
                $originTokenAllowLocal
            );
        } catch (\Weline\Framework\Runtime\RequestExitException $e) {
            throw $e;
        } catch (\Error $e) {
            if ($WLS_UOPZ_EXIT_GUARD && \str_contains($e->getMessage(), 'uopz')) {
                WlsLogger::warning_(
                    '请求内 exit()/die() 已由 uopz 拦截，返回 500（请改用 \\Weline\\Framework\\Runtime\\System::exit）'
                );
                return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                    . "Connection: close\r\nContent-Length: 52\r\n\r\n"
                    . "Internal error: exit()/die() not allowed in WLS request\n";
            }
            throw $e;
        } finally {
            wlsFiberRequestContextLeave();
            wlsResetLongRunningExecutionLimit();
        }
    });

    $fiberScheduler->registerFiber();
    try {
        $requestFiber->start();
    } catch (\Weline\Framework\Runtime\RequestExitException) {
    } catch (\Throwable $e) {
        WlsLogger::error_("Fiber 启动异常: " . $e->getMessage());
    }

    if ($requestFiber->isTerminated()) {
        $fiberScheduler->unregisterFiber();
        wlsFinalizeTerminatedFiberResponseStep(
            $requestFiber,
            $conn,
            $connId,
            $rawRequest,
            $handleStartTime,
            $connections,
            $requestBuffers,
            $connectionLastActivity,
            $requestLogged,
            $ipcClient,
            $instanceName,
            $activeRequests,
            $ipcDraining,
            $longLivedConnections,
            $isSseProtocolRequest,
            $writeBuffers,
            $writableConnections,
            $pendingClose
        );
        return;
    }

    if (!$requestFiber->isSuspended()) {
        return;
    }

    $activeFibers[$connId] = [
        'fiber' => $requestFiber,
        'conn' => $conn,
        'rawRequest' => $rawRequest,
        'handleStartTime' => $handleStartTime,
        'context' => \Weline\Framework\Runtime\WlsFiberContext::capture(),
        'suspended_at' => \time(),
        'last_activity' => \time(),
        'is_long_lived' => $isLongLived,
        'is_sse_protocol' => $isSseProtocolRequest,
    ];
    WlsLogger::info_("请求进入 Fiber 异步模式 (connId: {$connId})");

    $now = \time();
    if ($longLivedMaxActive <= 0) {
        return;
    }

    $isSaturated = \count($longLivedConnections) >= $longLivedMaxActive;
    if ($isSaturated && !$longLivedSaturationReported && ($now - $lastLongLivedSaturationReport) >= $longLivedSaturationInterval) {
        if ($ipcClient && $ipcClient->isConnected()) {
            $ipcClient->send(\Weline\Server\IPC\ControlMessage::workerSaturation(
                $workerId,
                $port,
                \count($longLivedConnections),
                $longLivedMaxActive,
                \count($activeFibers),
                $fiberMaxActive
            ));
            $lastLongLivedSaturationReport = $now;
            $longLivedSaturationReported = true;
            $longLivedSaturationCleared = false;
            WlsLogger::warning_("长连接饱和上报 (long_lived_count={$longLivedConnections}, max={$longLivedMaxActive})");
        }
        return;
    }

    if (!$isSaturated && $longLivedSaturationReported && !$longLivedSaturationCleared) {
        if ($ipcClient && $ipcClient->isConnected()) {
            $ipcClient->send(\Weline\Server\IPC\ControlMessage::workerSaturationCleared(
                $workerId,
                $port,
                \count($longLivedConnections),
                $longLivedMaxActive
            ));
            $longLivedSaturationReported = false;
            $longLivedSaturationCleared = true;
            WlsLogger::info_("长连接饱和解除 (long_lived_count={$longLivedConnections})");
        }
    }
}

function isRequestComplete(string $data): bool
{
    $headerEnd = \strpos($data, "\r\n\r\n");
    if ($headerEnd === false) {
        return false;
    }
    
    if (\preg_match('/Content-Length:\s*(\d+)/i', $data, $matches)) {
        $contentLength = (int) $matches[1];
        $bodyStart = $headerEnd + 4;
        $currentBodyLength = \strlen($data) - $bodyStart;
        return $currentBodyLength >= $contentLength;
    }
    
    return true;
}

function isKeepAlive(string $rawRequest): bool
{
    // HTTP/1.1 默认启用 Keep-Alive，除非显式指定 Connection: close
    $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
    
    // 检查 Connection 头
    if (\preg_match('/Connection:\s*(\S+)/i', $rawRequest, $matches)) {
        $connection = \strtolower(\trim($matches[1]));
        // 如果显式指定 close，则关闭连接
        if ($connection === 'close') {
            return false;
        }
        // 如果显式指定 keep-alive，则保持连接
        if ($connection === 'keep-alive') {
            return true;
        }
    }
    
    // HTTP/1.1 默认 Keep-Alive，HTTP/1.0 默认关闭
    return $isHttp11;
}

function getHeaderValue(string $rawRequest, string $headerName): ?string
{
    $pattern = '/^' . \preg_quote($headerName, '/') . ':\s*([^\r\n]+)/im';
    if (\preg_match($pattern, $rawRequest, $matches)) {
        $value = \trim($matches[1]);
        return $value === '' ? null : $value;
    }
    return null;
}

/**
 * 从 Cookie 头中解析指定 name 的值
 */
function getCookieValue(string $cookieHeader, string $name): ?string
{
    if ($cookieHeader === '') {
        return null;
    }
    $name = \preg_quote($name, '/');
    if (\preg_match('/\b' . $name . '=([^;\s]+)/', $cookieHeader, $m)) {
        $v = \trim($m[1], '"');
        return $v === '' ? null : $v;
    }
    return null;
}

/**
 * 校验“开发模式+后台登录”下发放的健康检查放行 Cookie（与 PHP 端生成逻辑一致）
 * 仅当 env 中配置了 wls.health_cookie_secret 时生效。
 */
function isHealthAllowCookieValid(string $cookieValue, array $env): bool
{
    $secret = $env['wls']['health_cookie_secret'] ?? null;
    if ($secret === null || $secret === '') {
        return false;
    }
    $slot = \floor(\time() / 3600);
    $expected = \hash_hmac('sha256', 'wls_health_' . $slot, (string) $secret);
    if (\hash_equals($expected, $cookieValue)) {
        return true;
    }
    $expectedPrev = \hash_hmac('sha256', 'wls_health_' . ($slot - 1), (string) $secret);
    return \hash_equals($expectedPrev, $cookieValue);
}

/**
 * 注入 WLS 处理耗时响应头。
 * 仅添加 header，不修改 body / Content-Length，避免 Content-Length mismatch 导致浏览器 loading 挂死。
 * 前端通过 Server-Timing API 读取：performance.getEntriesByType('navigation')[0].serverTiming
 */
function injectWlsProcessTimeHeader(string $response, float $durationMs): string
{
    if ($durationMs < 500 && !\Weline\Server\Log\LogConfig::isVerboseWlsLog()) {
        return $response;
    }

    $pos = \strpos($response, "\r\n\r\n");
    if ($pos === false) {
        return $response;
    }
    $ms = \round($durationMs, 2);
    // Insert inside the original header/body separator so the previous header keeps its CRLF.
    $headers = "X-WLS-Process-Time: {$ms}\r\nServer-Timing: wls;dur={$ms};desc=\"WLS Process\"\r\n";
    return \substr_replace($response, $headers, $pos + 2, 0);
}

function wlsCompressFormattedHttpResponse(string $response, string $acceptEncoding): string
{
    if ($response === ''
        || \stripos($acceptEncoding, 'gzip') === false
        || !\function_exists('gzencode')) {
        return $response;
    }

    $headerEnd = \strpos($response, "\r\n\r\n");
    if ($headerEnd === false) {
        return $response;
    }

    $headersPart = \substr($response, 0, $headerEnd);
    $bodyPart = \substr($response, $headerEnd + 4);
    if ($bodyPart === '' || \strlen($bodyPart) < 1024) {
        return $response;
    }

    if (\preg_match('/^HTTP\/\d(?:\.\d)?\s+(204|205|304)\b/i', $headersPart)
        || \preg_match('/^Content-Encoding:/mi', $headersPart)) {
        return $response;
    }

    $contentType = '';
    if (\preg_match('/^Content-Type:\s*([^\r\n]+)/mi', $headersPart, $typeMatch)) {
        $contentType = \strtolower(\trim((string)$typeMatch[1]));
    }
    if ($contentType !== ''
        && !\str_starts_with($contentType, 'text/')
        && !\str_contains($contentType, 'application/json')
        && !\str_contains($contentType, 'application/javascript')
        && !\str_contains($contentType, 'application/xml')
        && !\str_contains($contentType, 'application/xhtml+xml')
        && !\str_contains($contentType, 'image/svg+xml')) {
        return $response;
    }

    $compressed = \gzencode($bodyPart, 6);
    if ($compressed === false) {
        return $response;
    }

    $headersPart .= "\r\nContent-Encoding: gzip";
    $headersPart = wlsSetFormattedHeader($headersPart, 'Content-Length', (string)\strlen($compressed));
    $headersPart = wlsAddFormattedVaryAcceptEncoding($headersPart);

    return $headersPart . "\r\n\r\n" . $compressed;
}

function wlsSetFormattedHeader(string $headersPart, string $name, string $value): string
{
    $replacement = $name . ': ' . $value;
    $lines = \preg_split('/\r\n|\n|\r/', $headersPart);
    if ($lines === false) {
        return \rtrim($headersPart, "\r\n") . "\r\n" . $replacement;
    }

    $prefix = \strtolower($name) . ':';
    $kept = [];
    foreach ($lines as $line) {
        if (\str_starts_with(\strtolower(\ltrim($line)), $prefix)) {
            continue;
        }
        $kept[] = $line;
    }

    return \rtrim(\implode("\r\n", $kept), "\r\n") . "\r\n" . $replacement;
}

function wlsSetFormattedHttpResponseHeader(string $response, string $name, string $value): string
{
    $headerEnd = \strpos($response, "\r\n\r\n");
    if ($headerEnd === false) {
        return $response;
    }

    $headersPart = \substr($response, 0, $headerEnd);
    $bodyPart = \substr($response, $headerEnd + 4);

    return wlsSetFormattedHeader($headersPart, $name, $value) . "\r\n\r\n" . $bodyPart;
}

function wlsDecorateFormattedStaticResponseForPerformancePanel(
    string $response,
    string $rawRequest,
    float $elapsedMs,
    int $workerId,
    int $port,
    array $cacheInfo = []
): string {
    if (!wlsPerformancePanelAllowed($rawRequest)) {
        return $response;
    }

    $cacheStatus = \strtolower((string)($cacheInfo['status'] ?? 'miss'));
    $source = match ($cacheStatus) {
        'hit' => 'static_memory',
        'missing' => 'static_missing',
        default => 'static_file',
    };
    $requestId = wlsPerformancePanelRequestId($rawRequest);
    $response = wlsSetFormattedHttpResponseHeader($response, 'X-Weline-Request-Id', $requestId);
    wlsRecordFormattedFpcFastResponseForPerformancePanel(
        $rawRequest,
        $requestId,
        $elapsedMs,
        $workerId,
        $port,
        $source,
        wlsFormattedHttpStatusCode($response)
    );

    return $response;
}

function wlsFormattedHttpStatusCode(string $response): int
{
    if (\preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $response, $matches) === 1) {
        return (int)$matches[1];
    }

    return 200;
}

function wlsPerformancePanelAllowed(string $rawRequest = ''): bool
{
    if (\class_exists(\Weline\DeveloperWorkspace\Service\PanelAccessService::class)) {
        try {
            return (new \Weline\DeveloperWorkspace\Service\PanelAccessService())->canAccessRawHttp($rawRequest);
        } catch (\Throwable) {
        }
    }

    if ((\defined('DEV') && DEV) || (\defined('DEBUG') && DEBUG)) {
        return true;
    }
    if (!wlsPanelEnvTruthy(\Weline\Framework\App\Env::get('dev_tool.panel.enable_in_prod', false))) {
        return false;
    }

    $token = \trim((string)\Weline\Framework\App\Env::get('dev_tool.panel.token', ''));
    $tokenHash = \trim((string)\Weline\Framework\App\Env::get('dev_tool.panel.token_hash', ''));
    if ($token === '' && $tokenHash === '') {
        return false;
    }
    $cookieName = (string)\Weline\Framework\App\Env::get('dev_tool.panel.cookie_name', 'w_weline_panel');
    if ($cookieName === '') {
        return false;
    }
    $cookieHeader = (string)(getHeaderValue($rawRequest, 'Cookie') ?? '');

    return wlsPanelSessionCookieValid((string)(getCookieValue($cookieHeader, $cookieName) ?? ''));
}

function wlsPanelEnvTruthy(mixed $value): bool
{
    if (\is_bool($value)) {
        return $value;
    }
    if (\is_int($value) || \is_float($value)) {
        return (bool)$value;
    }
    if (\is_string($value)) {
        return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    return false;
}

function wlsPanelSessionCookieValid(string $cookieValue): bool
{
    if ($cookieValue === '') {
        return false;
    }
    $decoded = wlsPanelBase64UrlDecode($cookieValue);
    $parts = \explode('.', $decoded);
    if (\count($parts) !== 4) {
        return false;
    }
    [$issuedAt, $expiresAt, $nonce, $signature] = $parts;
    if (!\ctype_digit($issuedAt) || !\ctype_digit($expiresAt) || $nonce === '' || $signature === '') {
        return false;
    }
    if ((int)$expiresAt < \time()) {
        return false;
    }
    $payload = $issuedAt . '.' . $expiresAt . '.' . $nonce;
    $expected = \hash_hmac('sha256', $payload, wlsPanelSessionSigningKey());

    return \hash_equals($expected, $signature);
}

function wlsPanelSessionSigningKey(): string
{
    $tokenHash = \trim((string)\Weline\Framework\App\Env::get('dev_tool.panel.token_hash', ''));
    $token = \trim((string)\Weline\Framework\App\Env::get('dev_tool.panel.token', ''));
    $material = $tokenHash !== '' ? $tokenHash : $token;
    $salt = \defined('BP') ? (string)BP : __DIR__;

    return \hash('sha256', $material . '|' . $salt);
}

function wlsPanelBase64UrlDecode(string $value): string
{
    $value = \strtr($value, '-_', '+/');
    $padding = \strlen($value) % 4;
    if ($padding > 0) {
        $value .= \str_repeat('=', 4 - $padding);
    }
    $decoded = \base64_decode($value, true);

    return \is_string($decoded) ? $decoded : '';
}

function wlsPerformancePanelRequestId(string $rawRequest): string
{
    foreach (['X-Weline-Request-Id', 'X-Request-Id'] as $header) {
        $value = getHeaderValue($rawRequest, $header);
        if (\is_string($value) && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $value)) {
            return $value;
        }
    }

    try {
        return \bin2hex(\random_bytes(8)) . '-' . \dechex((int)(\microtime(true) * 1000000));
    } catch (\Throwable) {
        return \str_replace('.', '', \uniqid('wls', true));
    }
}

function wlsRecordFormattedFpcFastResponseForPerformancePanel(
    string $rawRequest,
    string $requestId,
    float $elapsedMs,
    int $workerId,
    int $port,
    string $source = 'worker_fastpath',
    int $status = 200
): void {
    try {
        if (!\class_exists(\Weline\Server\Service\WlsPerformanceTraceStore::class)) {
            return;
        }
        [$method, $target] = wlsPerformancePanelRequestLine($rawRequest);
        $host = \trim((string)(getHeaderValue($rawRequest, 'Host') ?? ''));
        $path = '/';
        $parsedPath = \parse_url($target, PHP_URL_PATH);
        if (\is_string($parsedPath) && $parsedPath !== '') {
            $path = $parsedPath;
        }
        $query = \parse_url($target, PHP_URL_QUERY);
        if (\is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }
        \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Server\Service\WlsPerformanceTraceStore::class)
            ->record([], [
                'request_id' => $requestId,
                'method' => $method,
                'uri' => $path,
                'host' => $host,
                'status' => $status,
                'total_ms' => \round($elapsedMs, 2),
                'pre_telemetry_total_ms' => \round($elapsedMs, 2),
                'fpc_hit' => $source === 'static_memory',
                'fpc_source' => $source,
                'worker_id' => (string)$workerId,
                'worker_port' => (string)$port,
                'pid' => \getmypid() ?: 0,
            ]);
    } catch (\Throwable) {
    }
}

function wlsPerformancePanelRequestLine(string $rawRequest): array
{
    if (\preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d(?:\.\d)?/i', $rawRequest, $matches)) {
        return [\strtoupper((string)$matches[1]), (string)$matches[2]];
    }

    return ['GET', '/'];
}

function wlsAddFormattedVaryAcceptEncoding(string $headersPart): string
{
    if (!\preg_match('/^Vary:\s*([^\r\n]*)$/mi', $headersPart, $match)) {
        return \rtrim($headersPart, "\r\n") . "\r\nVary: Accept-Encoding";
    }

    $varyValue = (string)($match[1] ?? '');
    foreach (\array_map('trim', \explode(',', $varyValue)) as $part) {
        if (\strcasecmp($part, 'Accept-Encoding') === 0) {
            return $headersPart;
        }
    }

    $newValue = \trim($varyValue) === '' ? 'Accept-Encoding' : $varyValue . ', Accept-Encoding';
    return (string)\preg_replace('/^Vary:\s*[^\r\n]*$/mi', 'Vary: ' . $newValue, $headersPart, 1);
}

/**
 * Fiber 请求开始前清理并初始化请求级上下文。
 */
function wlsFiberRequestContextEnter(mixed $conn, int|string|null $connectionId = null): void
{
    // 关键修复：多 Fiber 并发时，新请求进入不能做“全量 reset”，否则会清掉其他挂起 Fiber 的请求级状态。
    // 与 WlsRuntime::reset() 一致：存在并发挂起 Fiber 时，跳过会影响其他请求上下文的回调。
    $omitCallbacks = null;
    if (
        \Weline\Framework\Runtime\Runtime::isPersistent()
        && \Weline\Framework\Runtime\WlsConcurrency::getOtherSuspendedRequestFiberCount() > 0
    ) {
        $omitCallbacks = \Weline\Framework\Runtime\WlsConcurrency::callbackNamesOmittableWithPeerFibers();
    }
    \Weline\Framework\Runtime\StateManager::reset($omitCallbacks);

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
 * Fiber 请求结束后统一清台（响应已完成/连接已关闭后调用）。
 */
function wlsFiberRequestContextLeave(): void
{
    if (\session_status() === PHP_SESSION_ACTIVE) {
        @\session_write_close();
    }
    \Weline\Framework\Http\Sse\SseContext::reset();
    \Weline\Framework\Runtime\RequestContext::cleanup();
    \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\Http\Request::class);
    try {
        $resolvedClass = \Weline\Framework\Manager\ObjectManager::parserClass(\Weline\Framework\Http\Request::class);
        if ($resolvedClass !== \Weline\Framework\Http\Request::class) {
            \Weline\Framework\Manager\ObjectManager::removeInstance($resolvedClass);
        }
    } catch (\Throwable) {
    }
}

function wlsDrainPostResponseTasks(): void
{
    if (!\class_exists(\Weline\Framework\Runtime\PostResponseTaskQueue::class)) {
        return;
    }

    \Weline\Framework\Runtime\PostResponseTaskQueue::drain(
        (float)(\Weline\Framework\App\Env::get('wls.post_response_task_budget_ms', 8) ?: 8)
    );
}

/**
 * 发送 HTTP 响应并清理连接状态
 *
 * 从 Fiber 化后的主循环中提取，同时用于同步路径和异步路径（Fiber resume 后）。
 */
function sendResponseAndCleanup(
    mixed $conn,
    int $connId,
    string $response,
    string $rawRequest,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    $ipcClient,
    string $instanceName,
    int &$activeRequests,
    float $handleDuration,
    bool $ipcDraining,
    array &$longLivedConnections,
    bool $isSseProtocolRequest,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose
): void {
    // 防御性修正：避免响应里出现 header/body 分隔后多出 leading CRLF，
    // 从而导致 Content-Length 与实际 body 字节数不一致（curl/浏览器会超时等待）。
    $headerEnd = \strpos($response, "\r\n\r\n");
    if ($headerEnd !== false) {
        $headersPart = \substr($response, 0, $headerEnd);
        $bodyPart = \substr($response, $headerEnd + 4);
        if (\preg_match('/^Content-Length:\s*(\d+)/mi', $headersPart, $m)) {
            $contentLength = (int)($m[1] ?? 0);
            $bodyLen = \strlen($bodyPart);
            if ($bodyLen > $contentLength) {
                if (\str_starts_with($bodyPart, "\r\n") && ($bodyLen - 2) === $contentLength) {
                    $bodyPart = \substr($bodyPart, 2);
                    $response = $headersPart . "\r\n\r\n" . $bodyPart;
                } elseif (\str_starts_with($bodyPart, "\n") && ($bodyLen - 1) === $contentLength) {
                    $bodyPart = \substr($bodyPart, 1);
                    $response = $headersPart . "\r\n\r\n" . $bodyPart;
                }
            }
        }
    }

    $responseStatus = 200;
    if (\preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $response, $statusMatches)) {
        $responseStatus = (int) $statusMatches[1];
    }

    // 防御性策略：错误响应不复用连接，避免异常包在 keep-alive 链路中影响后续请求。
    if ($responseStatus >= 400) {
        $headerEndErr = \strpos($response, "\r\n\r\n");
        if ($headerEndErr !== false) {
            $headersPartErr = \substr($response, 0, $headerEndErr);
            $bodyPartErr = \substr($response, $headerEndErr + 4);
            if (\preg_match('/^Connection:\s*[^\r\n]*/mi', $headersPartErr)) {
                $headersPartErr = (string)\preg_replace('/^Connection:\s*[^\r\n]*/mi', 'Connection: close', $headersPartErr);
            } else {
                $headersPartErr .= "\r\nConnection: close";
            }
            $response = $headersPartErr . "\r\n\r\n" . $bodyPartErr;
        }
    }

    if ($responseStatus === 400) {
        $requestLine = '';
        if (\preg_match('/^([^\r\n]+)/', $rawRequest, $lineMatches)) {
            $requestLine = (string) ($lineMatches[1] ?? '');
        }
        WlsLogger::warning_("HTTP 400 响应 (connId: {$connId}, 请求: {$requestLine})");
    }

    $responseBytes = 0;
    $requestHost = getHeaderValue($rawRequest, 'Host') ?? '';
    if (\str_contains($requestHost, ':')) {
        $requestHost = (string) \explode(':', $requestHost, 2)[0];
    }

    $activeRequests = \max(0, $activeRequests - 1);

    $responseLenPre = \strlen($response);
    WlsLogger::debug_("Worker 即将写回响应 connId={$connId} len={$responseLenPre}");

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
    // SSE 收尾兜底：即便当前上下文标记已经被重置，只要该连接仍有 SSE 写队列待排空，仍按 SSE 模式处理，
    // 禁止回退到普通 HTTP 分支导致提前关连。
    $isSseMode = $actualSseStarted || ($isSseProtocolRequest && $hasQueuedSsePayload);
    $keepAlive = isKeepAlive($rawRequest);
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
            // 这样可以避免：前一个 SSE 连接关闭后缓冲区残留 SSE 数据碎片，
            // 而后同一个 connId 的普通 HTTP 响应把 SSE 碎片拼在前面，导致浏览器解析出错。
            // 如果确实需要追加（如分块 Transfer-Encoding），应在 Controller 层用
            // chunked encoding 处理，而不在 Worker 侧做跨请求拼接。
            $writeBuffers[$connId] = $response;
            $writableConnections[$connId] = $conn;
            WlsLogger::debug_("Worker 响应覆盖缓冲区（替换残留） connId={$connId} len={$responseLen}");
            goto http_finalize_skip_write;
        }

        $totalWritten = 0;
        $streamOk = \is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true);
        if (!$streamOk) {
            unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $pendingClose[$connId]);
            \Weline\Framework\Http\Sse\SseContext::reset();

            return;
        }

        $immediateRetries = 0;
        $maxImmediateRetries = 10;

        while ($totalWritten < $responseLen && $immediateRetries < $maxImmediateRetries) {
            $remaining = \substr($response, $totalWritten);
            $written = @\fwrite($conn, $remaining);

            if ($written === false) {
                @\fclose($conn);
                unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $pendingClose[$connId]);
                \Weline\Framework\Http\Sse\SseContext::reset();

                return;
            }

            if ($written === 0) {
                break;
            }

            $totalWritten += $written;
            $immediateRetries++;
        }

        if ($totalWritten >= $responseLen) {
            WlsLogger::debug_("Worker 已写完响应 connId={$connId} written={$totalWritten}");
            $responseBytes = $totalWritten;
            $responseFullyWritten = true;
            goto http_finalize_skip_write;
        }

        $responseBytes = $totalWritten;
        $writeBuffers[$connId] = \substr($response, $totalWritten);
        $writableConnections[$connId] = $conn;
        WlsLogger::debug_(
            'Worker 响应入队 connId=' . $connId . ' written=' . $totalWritten . ' total=' . $responseLen
            . ' remaining=' . ($responseLen - $totalWritten)
        );

        http_finalize_skip_write:
    } else {
        $responseLength = \strlen($response);
        if ($responseLength > 0) {
            WlsLogger::warning_("SSE 模式收到响应体: {$responseLength} bytes，可能未调用 \$sse->complete() (connId: {$connId})");
        } else {
            WlsLogger::info_("SSE 流式响应完成 (connId: {$connId})");
        }
    }

    \Weline\Framework\Http\Sse\SseContext::reset();
    $connectionLastActivity[$connId] = \time();

    // 浏览器侧出现 7~8s 文档请求时，需要在 Worker 侧直接落点，避免只看前端 waterfall 无法分辨
    // 是业务处理慢、队列等待，还是网络写回慢。默认阈值 1000ms，可由 env.php 配置覆盖。
    $slowThresholdMs = (float) (\Weline\Framework\App\Env::get('wls.slow_request_threshold_ms', 1000) ?: 1000);
    if ($handleDuration >= $slowThresholdMs) {
        $requestLine = '';
        if (\preg_match('/^([A-Z]+)\s+([^\s]+)\s+HTTP\/\d\.\d/i', $rawRequest, $matches)) {
            $requestLine = (string) ($matches[1] ?? '') . ' ' . (string) ($matches[2] ?? '');
        }
        WlsLogger::warning_(
            "Slow request detected (worker=http, connId={$connId}, status={$responseStatus}, "
            . "duration_ms={$handleDuration}, host={$requestHost}, request=\"{$requestLine}\")"
        );
    }

    if ($ipcClient && $ipcClient->isConnected()) {
        $ipcClient->send(\Weline\Server\IPC\ControlMessage::telemetry(
            $instanceName,
            $requestHost,
            $responseStatus,
            (int) $handleDuration,
            $responseBytes
        ));
    }

    WlsLogger::tick_();

    $responseRequestsClose = \Weline\Server\Service\WorkerResponseMemoryGuard::responseRequestsConnectionClose($response);
    unset($response, $rawRequest);

    if (!$isSseMode && $responseFullyWritten) {
        wlsDrainPostResponseTasks();
    }

    $shouldClose = $isSseMode || !$keepAlive || $ipcDraining || $forceCloseAfterResponse || $responseRequestsClose;
    if ($shouldClose) {
        $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';

        if ($hasBufferedData) {
            $pendingClose[$connId] = true;
        } else {
            @\fclose($conn);
            unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $pendingClose[$connId]);
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
    bool $originTokenAllowLocal
): string {
    // ========== 域名白名单验证（防止旧域名格式串台） ==========
    $hostHeader = \trim((string)(getHeaderValue($rawRequest, 'Host') ?? ''));
    if ($hostHeader !== '') {
        // 分离域名和端口
        $domain = $hostHeader;
        if (\str_contains($hostHeader, ':')) {
            [$domain, ] = \explode(':', $hostHeader, 2);
        }

        // 拒绝旧格式域名 weline-p[hash].local
        if (\preg_match('/^weline-p[0-9a-f]{8}\.local$/i', $domain)) {
            return "HTTP/1.1 403 Forbidden\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Connection: close\r\n"
                . "Content-Length: 110\r\n\r\n"
                . "Legacy domain format is no longer supported. Please use: p[hash].weline.test or p[hash].weline.localhost";
        }

        // 允许标准格式域名 p[hash].weline.test / p[hash].weline.localhost
        $isStandardDomain = \Weline\Server\Service\LocalDomainPolicy::isStandardProjectHost($domain);
        // 允许本地开发域名
        $isLocalDomain = \in_array($domain, ['127.0.0.1', 'localhost', '::1'], true);
        $isManagedLocalDomain = \Weline\Server\Service\LocalDomainPolicy::isManagedSingleLabelSubdomain($domain);

        // 检查是否为 env 配置的自定义域名
        $isConfiguredDomain = false;
        if (!$isStandardDomain && !$isLocalDomain && !$isManagedLocalDomain) {
            $env = [];
            if (\defined('BP') && \is_file(BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php')) {
                $env = @include BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php';
                $env = \is_array($env) ? $env : [];
                $wlsConfig = $env['wls'] ?? [];

                // 检查主配置的 host 和 ssl_domain
                $configuredHost = $wlsConfig['host'] ?? '';
                $sslDomain = $wlsConfig['ssl_domain'] ?? '';
                if (($configuredHost !== '' && \strcasecmp($configuredHost, $domain) === 0)
                    || ($sslDomain !== '' && \strcasecmp($sslDomain, $domain) === 0)) {
                    $isConfiguredDomain = true;
                }

                // 检查多实例配置
                if (!$isConfiguredDomain) {
                    $servers = $wlsConfig['servers'] ?? [];
                    foreach ($servers as $serverConfig) {
                        if (!\is_array($serverConfig)) {
                            continue;
                        }
                        $serverHost = $serverConfig['host'] ?? '';
                        $serverSslDomain = $serverConfig['ssl_domain'] ?? '';
                        if (($serverHost !== '' && \strcasecmp($serverHost, $domain) === 0)
                            || ($serverSslDomain !== '' && \strcasecmp($serverSslDomain, $domain) === 0)) {
                            $isConfiguredDomain = true;
                            break;
                        }
                    }
                }
            }
        }

        // 拒绝未知域名
        if (!$isStandardDomain && !$isLocalDomain && !$isManagedLocalDomain && !$isConfiguredDomain) {
            return "HTTP/1.1 403 Forbidden\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Connection: close\r\n"
                . "Content-Length: 60\r\n\r\n"
                . "Domain not configured. Please check your server configuration.";
        }
    }

    // 解析请求 URI
    $uri = '/';
    if (\preg_match('/^\w+\s+([^\s]+)/', $rawRequest, $matches)) {
        $uri = \parse_url($matches[1], PHP_URL_PATH) ?? '/';
    }
    $method = 'GET';
    if (\preg_match('/^(\w+)\s+/', $rawRequest, $matches)) {
        $method = $matches[1];
    }

    // 获取客户端 IP
    $clientIp = '127.0.0.1';
    $cfConnectingIp = getHeaderValue($rawRequest, 'CF-Connecting-IP');
    if ($cfConnectingIp !== null) {
        $clientIp = $cfConnectingIp;
    } elseif (\preg_match('/X-Real-IP:\s*([^\r\n]+)/i', $rawRequest, $matches)) {
        $clientIp = \trim($matches[1]);
    } elseif (\preg_match('/X-Forwarded-For:\s*([^\r\n,]+)/i', $rawRequest, $matches)) {
        $clientIp = \trim($matches[1]);
    }
    
    // 判断是否本地请求
    $localIps = ['127.0.0.1', '::1', 'localhost'];
    $isLocal = \in_array($clientIp, $localIps, true) || \strpos($clientIp, '192.168.') === 0 || \strpos($clientIp, '10.') === 0;
    
    // ========== 健康检查接口（仅本地访问，不受维护模式影响） ==========
    if ($uri === '/_wls/health') {
        // 检查请求是否要求 Keep-Alive（HTTP/1.1 默认 keep-alive）
        $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
        $hasClose = \stripos($rawRequest, 'Connection: close') !== false;
        $keepAlive = $isHttp11 && !$hasClose;
        // 可选：允许外网访问健康检查（仅测试/内网环境建议开启，生产建议关闭）
        $healthAllowRemote = false;
        $env = [];
        if (\defined('BP') && \is_file(BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php')) {
            $env = @include BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php';
            $env = \is_array($env) ? $env : [];
            $w = $env['wls'] ?? [];
            $wlsServers = \is_array($w['servers'] ?? null) ? $w['servers'] : [];
            $healthAllowRemote = (bool)(($wlsServers[$instanceName]['health_allow_remote'] ?? null)
                ?? $w['health_allow_remote'] ?? false);
        }
        // 非本地且未全局放行时：若带有“开发模式+后台登录”下发放的签名 Cookie 则放行
        $healthAllowedByCookie = false;
        $healthAllowedBySameOrigin = false;
        if (!$isLocal && !$healthAllowRemote) {
            $cookieHeader = getHeaderValue($rawRequest, 'Cookie') ?? '';
            $allowCookie = getCookieValue($cookieHeader, 'wls_health_allow');
            if ($allowCookie !== null && isHealthAllowCookieValid($allowCookie, $env)) {
                $healthAllowedByCookie = true;
            }
            // 同源请求放行：开发工具面板 fetch 有 Origin；直接导航有 Referer 同站点
            $hostHeader = \trim((string)(getHeaderValue($rawRequest, 'Host') ?? ''));
            $originHeader = \trim((string)(getHeaderValue($rawRequest, 'Origin') ?? ''));
            if ($hostHeader !== '') {
                if ($originHeader !== '' && \preg_match('#^https?://([^/]+)#i', $originHeader, $om)) {
                    if (\strcasecmp($om[1], $hostHeader) === 0) {
                        $healthAllowedBySameOrigin = true;
                    }
                } else {
                    $refererHeader = \trim((string)(getHeaderValue($rawRequest, 'Referer') ?? ''));
                    if ($refererHeader !== '' && \preg_match('#^https?://([^/]+)#i', $refererHeader, $rm)) {
                        if (\strcasecmp($rm[1], $hostHeader) === 0) {
                            $healthAllowedBySameOrigin = true;
                        }
                    }
                }
            }
        }
        if (!$isLocal && !$healthAllowRemote && !$healthAllowedByCookie && !$healthAllowedBySameOrigin) {
            // 非本地请求且未配置允许且无有效放行 Cookie：返回 403（极简响应）
            return $keepAlive
                ? "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: keep-alive\r\n\r\nForbidden"
                : "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: close\r\n\r\nForbidden";
        }
        
        // 高性能健康检查：使用极简响应，避免 json_encode/memory_get_usage 开销
        // 完整信息可通过 /_wls/health?detail=1 获取；fibers=1 时附带每个 Fiber 的闲忙与协议
        $wantsDetail = \strpos($rawRequest, 'detail=1') !== false || \strpos($rawRequest, 'detail=true') !== false;
        $wantsFibers = \strpos($rawRequest, 'fibers=1') !== false || \strpos($rawRequest, 'fibers=true') !== false;
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
                'timestamp' => \time(),
                'fiber_count' => \Weline\Server\Runtime\WorkerFiberSnapshot::getFiberCount(),
            ];
            if ($wantsFibers) {
                $health['fibers'] = \Weline\Server\Runtime\WorkerFiberSnapshot::getSnapshot();
            }
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

    // ========== Origin Token 回源校验（可选）==========
    if ($originTokenValidationEnabled && $originToken !== '') {
        $isLocalClient = $isLocal;
        if (!$originTokenAllowLocal || !$isLocalClient) {
            $receivedToken = getHeaderValue($rawRequest, $originTokenHeader) ?? '';
            if (!\hash_equals($originToken, $receivedToken)) {
                $forbiddenBody = '{"error":true,"message":"Origin token validation failed"}';
                return "HTTP/1.1 403 Forbidden\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . \strlen($forbiddenBody) . "\r\nConnection: close\r\n\r\n{$forbiddenBody}";
            }
        }
    }
    // ========== Origin Token 回源校验结束 ==========

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
                        $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
                        $hasClose = \stripos($rawRequest, 'Connection: close') !== false;
                        $keepAlive = $isHttp11 && !$hasClose;
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
    $staticResponse = handleStaticFile($uri, $rawRequest);
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
        WlsLogger::error_("运行时未初始化，返回错误: {$runtimeError}");
        $errorBody = \json_encode([
            'error' => true,
            'message' => 'Runtime initialization failed',
            'detail' => $runtimeError,
        ], JSON_UNESCAPED_UNICODE);
        
        return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . \strlen($errorBody) . "\r\nConnection: close\r\n\r\n" . $errorBody;
    }
    
    WlsLogger::info_("准备进入框架处理: {$method} {$uri}");
    try {
        // 创建 WLS 请求对象（框架会自动处理维护模式）
        try {
            WlsLogger::info_("开始创建 WlsRequest 对象");
            $request = \Weline\Framework\Http\WlsRequest::fromRaw($rawRequest, [
                'WLS_INSTANCE' => $instanceName,
                'WLS_WORKER_ID' => $workerId,
                'WLS_PORT' => $port,
                'WLS_REQUEST_COUNT' => $requestCount,
            ]);
            WlsLogger::info_("WlsRequest 对象创建成功: " . \get_class($request));
        } catch (\Throwable $reqE) {
            WlsLogger::error_("创建 WlsRequest 失败: " . $reqE->getMessage() . " (" . $reqE->getFile() . ":" . $reqE->getLine() . ")");
            throw $reqE;
        }
        
        WlsLogger::info_("调用 runtime->handle()，URI: {$uri}, 后端: " . ($request->isBackend() ? 'YES' : 'NO'));
        $handleStartTime = \microtime(true);
        try {
            $result = $asyncBizAdapters->dispatch(
                static fn() => $runtime->handle($request)
            );
            $handleEndTime = \microtime(true);
            $handleDuration = \round(($handleEndTime - $handleStartTime) * 1000, 2);
            WlsLogger::info_("runtime->handle() 完成，耗时: {$handleDuration}ms，结果类型: " . \gettype($result));
        } catch (\Throwable $handleE) {
            // 302 等响应终止为正常控制流，不记错误
            if (!$handleE instanceof \Weline\Framework\Http\ResponseTerminateException) {
                WlsLogger::error_("runtime->handle() 异常: " . $handleE->getMessage() . " (" . $handleE->getFile() . ":" . $handleE->getLine() . ")");
            }
            throw $handleE;
        }
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
        
        // 检查 $result 是否已经是 HTTP 响应字符串（例如重定向响应）
        // 如果以 "HTTP/" 开头，说明已经是完整的 HTTP 响应，直接返回
        if (\is_string($result) && \str_starts_with($result, 'HTTP/')) {
            WlsLogger::info_("返回已格式化的 HTTP 响应（可能是重定向）");
            // 合并 Runtime 保存的 Cookie（登录 302 等必须随响应发送 Set-Cookie，与 worker_ssl 一致）
            $pendingCookies = $runtime->consumePendingCookies();
            if (!empty($pendingCookies)) {
                $headerEnd = \strpos($result, "\r\n\r\n");
                if ($headerEnd !== false) {
                    $cookieHeaders = '';
                    foreach ($pendingCookies as $cookie) {
                        $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];
                        if (isset($cookie['expire']) && $cookie['expire'] !== 0) {
                            $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']);
                        }
                        if (!empty($cookie['path'])) {
                            $parts[] = 'Path=' . $cookie['path'];
                        }
                        if (!empty($cookie['domain'])) {
                            $parts[] = 'Domain=' . $cookie['domain'];
                        }
                        if (!empty($cookie['secure'])) {
                            $parts[] = 'Secure';
                        }
                        if (!empty($cookie['httpOnly'])) {
                            $parts[] = 'HttpOnly';
                        }
                        if (!empty($cookie['sameSite'])) {
                            $parts[] = 'SameSite=' . $cookie['sameSite'];
                        }
                        $cookieHeaders .= 'Set-Cookie: ' . \implode('; ', $parts) . "\r\n";
                    }
                    // 可靠拆分 header/body，避免拼接时产生多余 CRLF 导致 Content-Length 与实际收到字节不一致。
                    $bodyPart = \substr($result, $headerEnd + 4);
                    $headerPart = \substr($result, 0, $headerEnd);

                    $cookieHeaders = \rtrim($cookieHeaders, "\r\n");
                    $headerPart = \rtrim($headerPart, "\r\n");

                    if ($cookieHeaders !== '') {
                        $headerPart .= "\r\n" . $cookieHeaders;
                    }

                    $result = $headerPart . "\r\n\r\n" . $bodyPart;
                }
            }
            $sni = \Weline\Server\Service\RouteHintService::extractSniFromRawRequest($rawRequest);
            $result = \Weline\Server\Service\RouteHintService::addHintToResponse($result, $sni);

            // 防御性修正：避免已格式化 HTTP 响应在 header/body 分隔后多出 leading CRLF，
            // 导致 Content-Length 与实际收到字节不一致（curl/浏览器会因此等待到超时）。
            $headerEnd = \strpos($result, "\r\n\r\n");
            if ($headerEnd !== false) {
                $headersPart = \substr($result, 0, $headerEnd);
                $bodyPart = \substr($result, $headerEnd + 4);
                if (\preg_match('/^Content-Length:\s*(\d+)/mi', $headersPart, $m)) {
                    $contentLength = (int)($m[1] ?? 0);
                    $bodyLen = \strlen($bodyPart);
                    if ($bodyLen > $contentLength) {
                        // 常见异常：body 实际只多了 2 字节前导 CRLF
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
        
        // WLS 模式下控制器通过 return 返回 body，header() 无效；需对 body trim 并可从 JSON 的 code 解析出状态码
        $result = \is_string($result) ? $result : (string) $result;
        $pendingResponseStatus = $runtime->consumePendingResponseStatus();
        $statusCode = (new \Weline\Server\Service\ResponseStatusResolver())->resolve(
            $result,
            $pendingResponseStatus['status_code'] ?? null,
            (bool) ($pendingResponseStatus['explicit'] ?? false)
        );

        $response = \Weline\Framework\Http\Response::fromContent($result, $statusCode);
        
        // 合并应用通过 HeaderCollector 设置的响应头（否则 WLS 会“吞掉”页面/控制器设置的 header）
        $pendingHeaders = $runtime->consumePendingHeaders();
        foreach ($pendingHeaders as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $value = \is_array($value) ? \implode(', ', $value) : (string) $value;
            $response->setHeader($name, $value);
        }
        // 合并应用通过 HeaderCollector 设置的 Cookie（与“HTTP/” 分支行为一致）
        $pendingCookies = $runtime->consumePendingCookies();
        foreach ($pendingCookies as $cookie) {
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
        
        // 添加路由提示头（用于 TCP 透传模式下的智能路由）
        $sni = \Weline\Server\Service\RouteHintService::extractSniFromRawRequest($rawRequest);
        \Weline\Server\Service\RouteHintService::addHintToFrameworkResponse($response, $sni);
        
        // 添加 WLS 调试响应头（供开发工具面板使用）
        $response->setHeader('X-WLS-Worker-Id', (string) $workerId);
        $response->setHeader('X-WLS-Worker-Port', (string) $port);
        $response->setHeader('X-WLS-Worker-PID', (string) \getmypid());
        $response->setHeader('X-WLS-Instance', $instanceName);
        $response->setHeader('X-WLS-Request-Count', (string) $requestCount);
        $response->setHeader('X-WLS-Memory', (string) \round(\memory_get_usage(true) / 1024 / 1024, 2));
        $response->setHeader('X-WLS-Uptime', (string) (\time() - $startTime));
        
        // 添加性能数据到响应头（便于在浏览器开发者工具中查看）
        if ($handleDuration >= 500 || \Weline\Server\Log\LogConfig::isVerboseWlsLog()) {
            $response->setHeader('X-WLS-Performance-Total', (string) \round($handleDuration, 2));
            $response->setHeader('X-WLS-Performance-Warning', $handleDuration >= 1000 ? 'SLOW' : 'OK');
        }
        
        // 临时禁用 gzip 压缩以排除压缩问题
        $responseLocation = (string)($response->getHeader('Location') ?? '');
        if ($responseLocation !== '') {
            $response->setHeader('Location', appendBackendLoginReturnUrl($responseLocation, $request, $method, $rawRequest));
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

        $acceptEncoding = $request->getHeader('Accept-Encoding');
        if ($acceptEncoding && \is_string($acceptEncoding)) {
            $response->compress($acceptEncoding);
        }
        
        $httpResponse = $response->toHttpString($request->isKeepAlive());
        
        // HTTP 规范：HEAD 请求应该返回与 GET 请求相同的响应头，但不返回响应体
        // Content-Length 头部应该保留，告知客户端如果是 GET 请求会返回多大的内容
        if (\strtoupper($method) === 'HEAD') {
            $headerEnd = \strpos($httpResponse, "\r\n\r\n");
            if ($headerEnd !== false) {
                // 只保留响应头部分（包括末尾的 \r\n\r\n）
                $httpResponse = \substr($httpResponse, 0, $headerEnd + 4);
            }
        }
        
        return $httpResponse;
    } catch (\Throwable $e) {
        // 302 等响应终止为正常控制流，不记错误
        if (!$e instanceof \Weline\Framework\Http\ResponseTerminateException) {
            WlsLogger::error_("请求处理错误: " . $e->getMessage() . " (文件: " . $e->getFile() . ":" . $e->getLine() . ")");
            w_log_error('[WLS Worker] Request error: ' . $e->getMessage());
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
 * - 小于 1MB 的文件缓存到内存，避免重复读取磁盘
 * - 缓存有效期 7 天（基于文件修改时间验证）
 * - 大于 1MB 的文件直接从磁盘读取（避免内存占用过大）
 * 
 * @param string $uri 请求 URI
 * @param string $rawRequest 原始请求（用于获取 If-Modified-Since 等头部）
 * @return string|null 如果是静态文件则返回 HTTP 响应字符串，否则返回 null
 */
function appendBackendLoginReturnUrl(string $redirectUrl, \Weline\Framework\Http\Request $request, string $method, string $rawRequest): string
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

    $uri = extractUriFromRawRequest($rawRequest);
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
    $host = resolveBackendLoginReturnHost($request, $scheme);
    $returnUrl = $scheme . '://' . $host . (\str_starts_with($uri, '/') ? $uri : '/' . $uri);
    $query = [
        'no_access_reason' => 'not_logged_in',
        'return_url' => $returnUrl,
    ];

    $redirectUrl = removeBackendLoginReturnParams($redirectUrl);
    return $redirectUrl . (\str_contains($redirectUrl, '?') ? '&' : '?') . \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function resolveBackendLoginReturnHost(\Weline\Framework\Http\Request $request, string $scheme): string
{
    $host = \trim((string)($request->getServer('HTTP_HOST') ?: $request->getServer('SERVER_NAME') ?: 'localhost'));
    if ($host === '' || \str_contains($host, ':') || \str_starts_with($host, '[')) {
        return $host !== '' ? $host : 'localhost';
    }

    $port = \trim((string)($request->getServer('HTTP_WELINE_ORIGINAL_PORT') ?: ''));
    if ($port === '' || !\ctype_digit($port)) {
        return $host;
    }

    if (($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443')) {
        return $host;
    }

    return $host . ':' . $port;
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

function extractUriFromRawRequest(string $rawRequest): string
{
    $requestLine = \strtok($rawRequest, "\r\n");
    if (!\is_string($requestLine) || $requestLine === '') {
        return '';
    }

    $parts = \explode(' ', $requestLine, 3);
    if (\count($parts) < 2) {
        return '';
    }

    return (string)$parts[1];
}

function wlsGetStaticFileCacheStatus(): array
{
    if (!\function_exists('handleStaticFile')) {
        return [];
    }

    $rawStatus = handleStaticFile('__CACHE_STATUS__', '');
    if (!\is_string($rawStatus) || $rawStatus === '') {
        return [];
    }

    $decoded = \json_decode($rawStatus, true);
    return \is_array($decoded) ? $decoded : [];
}

function wlsCompactWorkerMemoryCaches(
    string $reason,
    int $maxMemoryBytes = 0,
    float $staticClearPressure = 0.55,
    int $staticClearMinBytes = 16777216,
    bool $forceStaticClear = false
): array {
    $beforeMemory = \memory_get_usage(true);
    $pressure = $maxMemoryBytes > 0 ? $beforeMemory / $maxMemoryBytes : 0.0;
    $status = wlsGetStaticFileCacheStatus();
    $staticSize = (int)($status['size'] ?? 0);
    $staticCount = (int)($status['count'] ?? 0);
    $staticClear = [
        'cleared' => false,
        'reason' => $reason,
        'count' => $staticCount,
        'size' => $staticSize,
        'pressure' => $pressure,
    ];

    if (
        \function_exists('handleStaticFile')
        && ($forceStaticClear || $staticSize >= $staticClearMinBytes || $pressure >= $staticClearPressure)
        && ($staticSize > 0 || $staticCount > 0 || $forceStaticClear)
    ) {
        $rawClear = handleStaticFile('__CLEAR_CACHE__', '');
        if (\is_string($rawClear) && \preg_match('/^cleared:(\d+):(\d+)$/', $rawClear, $matches) === 1) {
            $staticClear['count'] = (int)$matches[1];
            $staticClear['size'] = (int)$matches[2];
        }
        $staticClear['cleared'] = true;
    }

    $compaction = \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
    $compaction['static_file_cache'] = $staticClear;
    $compaction['memory_before_bytes'] = $beforeMemory;
    $compaction['memory_after_bytes'] = \memory_get_usage(true);

    return $compaction;
}

function wlsWorkerMemoryHealthDiagnostics(bool $includeStaticProperties = false, bool $includeObjectProperties = false): array
{
    $diagnostics = [
        'memory_usage_allocated' => \memory_get_usage(true),
        'memory_usage_used' => \memory_get_usage(false),
        'memory_peak_allocated' => \memory_get_peak_usage(true),
        'memory_peak_used' => \memory_get_peak_usage(false),
        'static_file_cache' => wlsGetStaticFileCacheStatus(),
        'gc_status' => \function_exists('gc_status') ? \gc_status() : [],
        'object_manager' => [],
        'state_manager' => [],
    ];

    if (\class_exists(\Weline\Framework\Manager\ObjectManager::class, false)) {
        try {
            $diagnostics['object_manager'] = \Weline\Framework\Manager\ObjectManager::getRuntimeMemoryDiagnostics(12, $includeObjectProperties);
        } catch (\Throwable $throwable) {
            $diagnostics['object_manager_error'] = $throwable->getMessage();
        }
    }

    if (\class_exists(\Weline\Framework\Runtime\StateManager::class, false)) {
        try {
            $diagnostics['state_manager'] = \Weline\Framework\Runtime\StateManager::getStats();
        } catch (\Throwable $throwable) {
            $diagnostics['state_manager_error'] = $throwable->getMessage();
        }
    }

    if ($includeStaticProperties) {
        $diagnostics['static_properties'] = wlsWorkerStaticPropertyDiagnostics();
    }

    return $diagnostics;
}

function wlsWorkerStaticPropertyDiagnostics(int $limit = 25, int $thresholdBytes = 8192): array
{
    $limit = \max(1, \min(100, $limit));
    $thresholdBytes = \max(0, $thresholdBytes);
    $items = [];
    $classesScanned = 0;
    $propertiesScanned = 0;

    foreach (\get_declared_classes() as $className) {
        if (!\str_starts_with($className, 'Weline\\') && !\str_starts_with($className, 'GuoLaiRen\\')) {
            continue;
        }

        try {
            $reflection = new \ReflectionClass($className);
            $properties = $reflection->getStaticProperties();
        } catch (\Throwable) {
            continue;
        }

        $classesScanned++;
        foreach ($properties as $propertyName => $value) {
            $propertiesScanned++;
            $approxBytes = wlsApproxMemoryValueSize($value);
            if ($approxBytes < $thresholdBytes) {
                continue;
            }
            $items[] = [
                'property' => $className . '::$' . (string)$propertyName,
                'type' => \get_debug_type($value),
                'count' => \is_countable($value) ? \count($value) : null,
                'approx_bytes' => $approxBytes,
            ];
        }
    }

    \usort(
        $items,
        static fn(array $a, array $b): int => ((int)$b['approx_bytes']) <=> ((int)$a['approx_bytes'])
    );

    return [
        'classes_scanned' => $classesScanned,
        'properties_scanned' => $propertiesScanned,
        'threshold_bytes' => $thresholdBytes,
        'top' => \array_slice($items, 0, $limit),
    ];
}

function wlsApproxMemoryValueSize(mixed $value, int $depth = 0, int &$visited = 0): int
{
    if ($visited > 50000) {
        return 0;
    }
    $visited++;

    if (\is_string($value)) {
        return \strlen($value);
    }
    if (\is_int($value) || \is_float($value) || \is_bool($value) || $value === null) {
        return 16;
    }
    if (\is_object($value)) {
        return 128;
    }
    if (\is_resource($value)) {
        return 32;
    }
    if (!\is_array($value)) {
        return 0;
    }
    if ($depth >= 5) {
        return \count($value) * 32;
    }

    $size = 16;
    foreach ($value as $key => $item) {
        $size += \is_string($key) ? \strlen($key) : 16;
        $size += wlsApproxMemoryValueSize($item, $depth + 1, $visited);
        if ($visited > 50000) {
            break;
        }
    }

    return $size;
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

    // ========== 静态文件内存缓存（冷热淘汰策略） ==========
    static $staticFileCache = [];
    static $staticFileCacheTotalSize = 0;
    static $staticFileCacheMaxAge = 86400 * 7;

    $maxTotal = \Weline\Server\Service\WlsWorkerGlobals::getStaticCacheMaxTotal();
    $maxSize = \Weline\Server\Service\WlsWorkerGlobals::getStaticCacheMaxSize();
    $evictionThreshold = \Weline\Server\Service\WlsWorkerGlobals::getCacheEvictionThreshold();
    
    // 特殊命令：清理内存缓存
    if ($uri === '__CLEAR_CACHE__') {
        $count = \count($staticFileCache);
        $size = $staticFileCacheTotalSize;
        $staticFileCache = [];
        $staticFileCacheTotalSize = 0;
        return "cleared:{$count}:{$size}";
    }
    
    // 特殊命令：获取缓存状态
    if ($uri === '__CACHE_STATUS__') {
        return \json_encode([
            'count' => \count($staticFileCache),
            'size' => $staticFileCacheTotalSize,
            'max_total' => $maxTotal,
        ]);
    }
    
    // 冷热淘汰函数
    $evictColdCache = static function (int $neededSpace) use (&$staticFileCache, &$staticFileCacheTotalSize, $maxTotal, $evictionThreshold): void {
        $targetSize = $maxTotal - $evictionThreshold - $neededSpace;
        if ($staticFileCacheTotalSize <= $targetSize) return;
        
        $now = \time();
        $candidates = [];
        foreach ($staticFileCache as $path => $item) {
            $hits = $item['hits'] ?? 0;
            $lastAccess = $item['last_access'] ?? $item['cached_at'];
            $age = $now - $lastAccess;
            $recencyBonus = \max(0, 100 - (int)($age / 60));
            $score = $hits * 10 + $recencyBonus;
            $candidates[] = ['path' => $path, 'score' => $score, 'size' => $item['size']];
        }
        \usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
        foreach ($candidates as $c) {
            if ($staticFileCacheTotalSize <= $targetSize) break;
            if (isset($staticFileCache[$c['path']])) {
                $staticFileCacheTotalSize -= $staticFileCache[$c['path']]['size'];
                unset($staticFileCache[$c['path']]);
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
    
    // 解析文件扩展名（去除查询字符串）
    $uriPath = \parse_url($uri, PHP_URL_PATH) ?? $uri;
    $extension = \strtolower(\pathinfo($uriPath, PATHINFO_EXTENSION));
    
    // 不是静态文件，交给框架处理
    if (empty($extension) || !\in_array($extension, $staticExtensions, true)) {
        return null;
    }
    
    // 安全检查：防止目录遍历，并兼容带 backend key / 货币 / 语言前缀的静态资源 URL
    $normalizedUri = \str_replace(['../', '..\\'], '', $uriPath);
    $normalizedUri = \trim(\str_replace('\\', '/', $normalizedUri), '/\\');
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
    
    // 检查缓存验证（304 Not Modified）- 精简响应头
    if (\preg_match('/If-Modified-Since:\s*([^\r\n]+)/i', $rawRequest, $matches)) {
        $ifModifiedSince = \trim($matches[1]);
        if ($ifModifiedSince === $lastModified) {
            return "HTTP/1.1 304 Not Modified\r\nETag: {$etag}\r\nX-WLS-Static-Cache: {$cacheHeaderStatus}\r\nConnection: keep-alive\r\n\r\n";
        }
    }
    
    if (\preg_match('/If-None-Match:\s*([^\r\n]+)/i', $rawRequest, $matches)) {
        $ifNoneMatch = \trim($matches[1]);
        if ($ifNoneMatch === $etag) {
            return "HTTP/1.1 304 Not Modified\r\nETag: {$etag}\r\nX-WLS-Static-Cache: {$cacheHeaderStatus}\r\nConnection: keep-alive\r\n\r\n";
        }
    }
    
    // 获取文件大小
    $fileSize = \filesize($filename);
    
    // 获取 MIME 类型
    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    // 缓存控制（静态资源可以长期缓存）
    $maxAge = 86400 * 7; // 7 天
    $expires = \gmdate('D, d M Y H:i:s', \time() + $maxAge) . ' GMT';
    
    // 大文件阈值（超过此大小使用流式传输标记）
    $largeFileThreshold = 2 * 1024 * 1024; // 2MB
    
    // 检查 Range 请求（用于视频/音频断点续传）
    $rangeStart = 0;
    $rangeEnd = $fileSize - 1;
    $isRangeRequest = false;
    
    if (\preg_match('/Range:\s*bytes=(\d*)-(\d*)/i', $rawRequest, $matches)) {
        $isRangeRequest = true;
        if ($matches[1] !== '') {
            $rangeStart = (int) $matches[1];
        }
        if ($matches[2] !== '') {
            $rangeEnd = (int) $matches[2];
        }
        // 验证范围
        if ($rangeStart > $rangeEnd || $rangeStart >= $fileSize) {
            return "HTTP/1.1 416 Range Not Satisfiable\r\n" .
                   "Content-Range: bytes */{$fileSize}\r\n" .
                   "Connection: close\r\n" .
                   "\r\n";
        }
        $rangeEnd = \min($rangeEnd, $fileSize - 1);
    }
    
    $contentLength = $rangeEnd - $rangeStart + 1;
    
    // 小文件：直接读取并返回（精简响应头，移除冗余信息）
    if ($fileSize <= $largeFileThreshold && !$isRangeRequest) {
        $content = $validatedCached['content'] ?? null;
        $fromCache = $validatedCached !== null;
        $now = $now ?? \time();
        
        // ========== 内存缓存策略（冷热淘汰） ==========
        if ($fileSize <= $maxSize) {
            // 检查缓存是否存在且有效
            if (!$fromCache && isset($staticFileCache[$filename])) {
                $cached = $staticFileCache[$filename];
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
                    $staticFileCacheTotalSize -= $cached['size'];
                    unset($staticFileCache[$filename]);
                }
            }
            
            if ($content === null) {
                $content = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($filename);
                if ($content === false) {
                    return null;
                }
                
                // 剩余空间不足时启动冷热淘汰
                $remainingSpace = $maxTotal - $staticFileCacheTotalSize;
                if ($remainingSpace - $fileSize < $evictionThreshold) {
                    $evictColdCache($fileSize);
                }
                
                if ($staticFileCacheTotalSize + $fileSize <= $maxTotal) {
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
            }
        } else {
            $content = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($filename);
            if ($content === false) {
                return null;
            }
        }
        
        // 使用实际内容长度，避免 Content-Length 与实际内容不匹配
        $actualContentLength = \strlen($content);
        
        // 验证内容长度与文件大小
        if ($actualContentLength !== $fileSize && !$fromCache) {
            // 文件可能在读取过程中被修改，重新读取
            $content = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($filename);
            if ($content === false) {
                return null;
            }
            $actualContentLength = \strlen($content);
        }
        
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: {$mimeType}\r\n";
        $response .= "Content-Length: {$actualContentLength}\r\n";
        $response .= "Cache-Control: public, max-age={$maxAge}\r\n";
        $response .= "ETag: {$etag}\r\n";
        $response .= "Accept-Ranges: bytes\r\n";
        $response .= "Connection: keep-alive\r\n";
        // WLS 内存缓存状态标识（HIT=内存缓存命中, MISS=磁盘读取）
        $response .= "X-WLS-Static-Cache: " . ($fromCache ? 'HIT' : 'MISS') . "\r\n";
        $response .= "X-WLS-File-Size: {$fileSize}\r\n";
        $response .= "X-WLS-Content-Length: {$actualContentLength}\r\n";
        $response .= "\r\n";
        $response .= $content;
        
        // 验证响应完整性
        $headerEndPos = \strpos($response, "\r\n\r\n");
        $actualBodyLen = \strlen($response) - $headerEndPos - 4;
        if ($actualBodyLen !== $actualContentLength) {
            return "HTTP/1.1 500 Internal Server Error\r\n" .
                   "Content-Type: text/plain\r\n" .
                   "Content-Length: 25\r\n" .
                   "Connection: close\r\n" .
                   "\r\n" .
                   "Response build error: {$actualBodyLen}/{$actualContentLength}";
        }
        
        return $response;
    }
    
    // 大文件或 Range 请求：使用分块读取
    $fp = @\fopen($filename, 'rb');
    if ($fp === false) {
        return null;
    }
    
    // 定位到起始位置
    if ($rangeStart > 0) {
        \fseek($fp, $rangeStart);
    }
    
    // 构建精简响应头
    if ($isRangeRequest) {
        $response = "HTTP/1.1 206 Partial Content\r\n";
        $response .= "Content-Range: bytes {$rangeStart}-{$rangeEnd}/{$fileSize}\r\n";
    } else {
        $response = "HTTP/1.1 200 OK\r\n";
    }
    
    // 根据客户端请求设置 Connection 头
    $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
    $hasCloseHeader = \stripos($rawRequest, 'Connection: close') !== false;
    $keepAlive = $isHttp11 && !$hasCloseHeader;
    $connectionHeader = $keepAlive ? 'keep-alive' : 'close';
    
    $response .= "Content-Type: {$mimeType}\r\n";
    $response .= "Content-Length: {$contentLength}\r\n";
    $response .= "Cache-Control: public, max-age={$maxAge}\r\n";
    $response .= "ETag: {$etag}\r\n";
    $response .= "Accept-Ranges: bytes\r\n";
    $response .= "Connection: {$connectionHeader}\r\n";
    // WLS 内存缓存状态标识（DISK=大文件/Range请求，不缓存直接磁盘读取）
    $response .= "X-WLS-Static-Cache: DISK\r\n";
    $response .= "X-WLS-File-Size: {$fileSize}\r\n";
    $response .= "\r\n";
    
    // 分块读取文件内容
    // 注意：必须确保实际读取的字节数与 Content-Length 匹配
    $chunkSize = 64 * 1024;
    $remaining = $contentLength;
    $totalRead = 0;
    
    while ($remaining > 0 && !\feof($fp)) {
        $readSize = \min($chunkSize, $remaining);
        $chunk = \fread($fp, $readSize);
        if ($chunk === false) {
            // 读取失败，关闭文件并返回错误
            \fclose($fp);
            return "HTTP/1.1 500 Internal Server Error\r\n" .
                   "Content-Type: text/plain\r\n" .
                   "Content-Length: 21\r\n" .
                   "Connection: close\r\n" .
                   "\r\n" .
                   "File read error: {$uri}";
        }
        $chunkLen = \strlen($chunk);
        if ($chunkLen === 0) {
            // EOF 提前到达，可能是文件在读取过程中被修改
            break;
        }
        $response .= $chunk;
        $remaining -= $chunkLen;
        $totalRead += $chunkLen;
    }
    
    \fclose($fp);
    
    // 验证实际读取字节数与 Content-Length
    // 如果不匹配，需要修正响应或返回错误
    if ($totalRead !== $contentLength) {
        // 文件可能在读取过程中被修改，返回错误而不是不完整的响应
        return "HTTP/1.1 500 Internal Server Error\r\n" .
               "Content-Type: text/plain\r\n" .
               "Content-Length: 35\r\n" .
               "Connection: close\r\n" .
               "\r\n" .
               "File changed during read: {$totalRead}/{$contentLength}";
    }
    
    return $response;
}
