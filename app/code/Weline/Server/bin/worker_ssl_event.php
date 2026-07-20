<?php
declare(strict_types=1);

/**
 * Experimental WLS HTTPS worker backed by libevent SSL bufferevents.
 *
 * This entry is intentionally separate from worker_ssl.php. It is selected only
 * by wls.ssl.engine=event_buffer and must not silently fall back to streams.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'worker_runtime_common.php';

if (!\function_exists('wlsEventMakeAbsolutePath')) {
    function wlsEventMakeAbsolutePath(string $path, string $basePath): string
    {
        $path = \trim($path);
        if ($path === '') {
            return '';
        }
        if (\preg_match('/^(?:[a-zA-Z]:[\\\\\\/]|[\\\\\\/]{2}|\/)/', $path)) {
            return $path;
        }

        return $basePath . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}

if (!\function_exists('wlsEventEnsureRuntimeFileReadable')) {
    function wlsEventEnsureRuntimeFileReadable(string $path, int $mode = 0640): bool
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

        return \is_readable($path);
    }
}

$wlsMemoryLimit = '256M';
@\ini_set('memory_limit', $wlsMemoryLimit);

$processName = '';
$isFrontend = false;
$isMaintenanceWorker = false;
$wlsListenerMode = '';
$wlsLoopDriver = 'auto';
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
$controlPort = 0;
$masterPid = 0;
$workerCount = 1;
$wlsRuntimeTopology = '';
$masterLeaseFile = '';
$masterToken = '';
$publicOrigin = '';

$positionalArgs = [];
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (!\str_starts_with($arg, '--') && !\str_starts_with($arg, '-')) {
        $positionalArgs[] = $arg;
    }
}

$host = $positionalArgs[0] ?? '127.0.0.1';
$port = (int)($positionalArgs[1] ?? 9981);
$workerId = (int)($positionalArgs[2] ?? 1);
$instanceName = $positionalArgs[3] ?? 'default';
$sslCert = $positionalArgs[4] ?? '';
$sslKey = $positionalArgs[5] ?? '';

foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend' || $arg === '-frontend') {
        $isFrontend = true;
    } elseif (\str_starts_with($arg, '--wls-listener-mode=')) {
        $wlsListenerMode = \strtolower(\trim((string)\substr($arg, 20)));
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
if (!\in_array($wlsRuntimeTopology, ['direct', 'dispatcher'], true)) {
    \fwrite(\STDERR, "--wls-runtime-topology must be direct or dispatcher.\n");
    exit(1);
}
if (!\in_array($wlsListenerMode, ['single', 'reuseport', 'shared_fd'], true)) {
    \fwrite(\STDERR, "--wls-listener-mode must be single, reuseport, or shared_fd.\n");
    exit(1);
}
if (($wlsRuntimeTopology === 'dispatcher' && $wlsListenerMode !== 'single')
    || ($wlsRuntimeTopology === 'direct' && $wlsListenerMode === 'single')
) {
    \fwrite(\STDERR, "Listener mode does not match the selected WLS topology.\n");
    exit(1);
}
@\ini_set('memory_limit', $wlsMemoryLimit);

$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'worker_http_message.php';

\Weline\Server\Log\LogConfig::bootstrapVerboseFromInstanceFile($instanceName);

$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string)$supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);
if ($controlPort <= 0 && !$supervisorEnabled) {
    $controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, $controlPort, 30);
}

$sslCert = wlsEventMakeAbsolutePath($sslCert, BP);
$sslKey = wlsEventMakeAbsolutePath($sslKey, BP);

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
if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

$_wlsEnvFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
$_wlsEnvConfig = \is_file($_wlsEnvFile) ? @include $_wlsEnvFile : [];
$_wlsEnvConfig = \is_array($_wlsEnvConfig) ? $_wlsEnvConfig : [];
if (!\defined('WLS_DEV_MODE')) {
    $_wlsSystemConfig = \is_array($_wlsEnvConfig['system'] ?? null) ? $_wlsEnvConfig['system'] : [];
    \define('WLS_DEV_MODE', (($_wlsSystemConfig['deploy'] ?? $_wlsEnvConfig['deploy'] ?? '') === 'dev'));
}
unset($_wlsEnvFile, $_wlsSystemConfig);

(new \Weline\Server\Service\LongRunningPhpRuntime())->apply();

$processTag = \Weline\Server\Service\WorkerProcessLabel::buildLogTag(true, $isMaintenanceWorker, $workerId, $port, $instanceName);
if (\function_exists('cli_set_process_title')) {
    @\cli_set_process_title(
        \Weline\Server\Service\WorkerProcessLabel::buildProcessTitle(
            true,
            $isMaintenanceWorker,
            $workerId,
            $port,
            $instanceName,
            $orchestratorLaunchId
        )
    );
}

\Weline\Server\Log\Error\ErrorBootstrap::init($processTag, [
    'worker_id' => $workerId,
    'port' => $port,
    'instance' => $instanceName,
    'process_name' => $processName,
    'is_maintenance' => $isMaintenanceWorker,
    'ssl_engine' => 'event_buffer',
]);

$processLogFile = '';
if ($processName !== '') {
    $processLogFile = \Weline\Server\Service\WlsLogService::prepareProcessLogFile($processName, $instanceName, $processTag);
}

$envConfig = $_wlsEnvConfig;
unset($_wlsEnvConfig);
$sharedStateRuntime = \Weline\Server\Service\SharedStateRuntimeOptions::fromCliArgs($argv, $instanceName, $envConfig);
$envOverrides = $sharedStateRuntime->toEnvOverrides();
$envConfig = \array_replace_recursive($envConfig, $envOverrides);
\Weline\Framework\App\Env::getInstance()->applyRuntimeConfig($envOverrides);
\Weline\Server\Service\WlsWorkerGlobals::setArgv($argv);
\Weline\Server\Service\WlsWorkerGlobals::resetStd();

$wlsEnv = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
$sslConfig = \is_array($wlsEnv['ssl'] ?? null) ? $wlsEnv['ssl'] : [];
$eventBufferEnabled = (bool)($sslConfig['event_buffer_enabled'] ?? false);
$sslEngine = \strtolower(\trim((string)($sslConfig['engine'] ?? 'stream')));

\Weline\Server\Log\WlsLogger::getInstance()
    ->setStdoutEnabled(\Weline\Server\Log\LogConfig::isStdoutEnabled($isFrontend, \Weline\Server\Log\LogConfig::isDevMode()))
    ->setProcessTag($processTag);

$childMasterGuard = new \Weline\Server\IPC\ChildControl\ChildMasterGuard(
    $masterPid,
    $masterLeaseFile,
    $masterToken,
    ($isMaintenanceWorker ? 'EventMaintenanceSSLWorker' : 'EventSSLWorker') . "#{$workerId}",
    $instanceName,
    $orchestratorEpoch
);
$childMasterGuard->assertAliveOrExit('Event SSL listen 前 Master 自治检查');
\Weline\Server\Service\Runtime\WorkerProcessLease::register(
    $processName,
    $orchestratorLaunchId,
    $orchestratorEpoch
);

if ($sslEngine !== 'event_buffer') {
    \Weline\Server\Log\WlsLogger::error_('worker_ssl_event.php requires wls.ssl.engine=event_buffer');
    exit(2);
}
if (!$eventBufferEnabled) {
    \Weline\Server\Log\WlsLogger::error_('EventBuffer SSL worker is disabled; set wls.ssl.event_buffer_enabled=true to run this experimental engine');
    exit(2);
}
if ($wlsRuntimeTopology === 'dispatcher') {
    \Weline\Server\Log\WlsLogger::error_(
        'EventBuffer SSL worker cannot consume the authenticated Dispatcher PROXY v2 preface before TLS. '
        . 'Use wls.ssl.engine=stream; WLS will not silently corrupt the TLS connection.'
    );
    exit(2);
}
if ($wlsRuntimeTopology === 'direct') {
    \Weline\Server\Log\WlsLogger::error_(
        'EventBuffer SSL worker is not supported in direct topology. Use wls.ssl.engine=stream.'
    );
    exit(2);
}
if (PHP_OS_FAMILY === 'Windows') {
    \Weline\Server\Log\WlsLogger::error_(
        'EventBuffer SSL worker is not supported on native Windows: PHP event SSL bufferevent server exits during TLS accept. '
        . 'Use wls.ssl.engine=stream or external TLS termination.'
    );
    \Weline\Server\Log\WlsLogger::flush_(true);
    exit(2);
}
if (!\extension_loaded('event')
    || !\class_exists(\EventBase::class)
    || !\class_exists(\EventListener::class)
    || !\class_exists(\EventBufferEvent::class)
    || !\class_exists(\EventSslContext::class)) {
    \Weline\Server\Log\WlsLogger::error_('PHP event extension with OpenSSL support is required for wls.ssl.engine=event_buffer');
    exit(2);
}
if (!wlsEventEnsureRuntimeFileReadable($sslCert, 0644) || !wlsEventEnsureRuntimeFileReadable($sslKey, 0600)) {
    \Weline\Server\Log\WlsLogger::error_('EventBuffer SSL worker certificate/key are not readable');
    exit(2);
}

// 子进程只发布脱敏的 generation lease；Master/IPC 仍是槽位、READY 与监听能力权威。

\Weline\Server\Service\RouteHintService::init($port, $wlsRuntimeTopology === 'dispatcher', 3600);

$runtime = null;
$runtimeError = null;
$fpcFastPath = null;
try {
    $runtime = new \Weline\Framework\Runtime\WlsRuntime();
    $runtime->bootstrap();
    $fpcFastPath = new \Weline\Server\Service\WorkerFullPageCacheFastPath(
        \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Router\FullPageCacheCoordinator::class
        ),
        $runtime,
    );
    \Weline\Server\Log\WlsLogger::info_("EventBuffer SSL worker runtime bootstrapped on {$host}:{$port}");
} catch (\Throwable $e) {
    $runtimeError = $e->getMessage();
    \Weline\Server\Log\WlsLogger::error_('EventBuffer SSL worker runtime bootstrap failed: ' . $e->getMessage());
}

$fiberScheduler = new \Weline\Server\Scheduler\FiberScheduler();
$eventLoopMeta = \Weline\Server\EventLoop\EventLoopFactory::create($wlsLoopDriver);
$eventLoop = $eventLoopMeta['loop'];
$coroutineRuntime = new \Weline\Server\Runtime\CoroutineRuntime($eventLoop, $fiberScheduler);
$asyncBizAdapters = new \Weline\Server\Runtime\Async\AsyncBizAdapters();
\Weline\Server\Observer\SchedulerWaitObserver::setScheduler($fiberScheduler);
\Weline\Framework\Runtime\SchedulerSystem::enableScheduler();
$activeFibers = [];
\Weline\Framework\Runtime\WlsConcurrency::setOtherSuspendedFiberCountProvider(
    static function () use (&$activeFibers): int {
        return \count($activeFibers);
    }
);
\Weline\Server\Log\WlsLogger::info_(
    "EventBuffer SSL worker coroutine loop requested={$eventLoopMeta['requested']} resolved={$eventLoopMeta['resolved']} backend={$coroutineRuntime->getLoopBackend()}"
);

$cacheConfig = \is_array($wlsEnv['cache'] ?? null) ? $wlsEnv['cache'] : [];
\Weline\Server\Service\WlsWorkerGlobals::configureStaticCache(
    wlsEventCalculateCacheSize($cacheConfig['static_file_max_total'] ?? 'auto', 2, 32 * 1024 * 1024, 256 * 1024 * 1024),
    wlsEventCalculateCacheSize($cacheConfig['static_file_max_size'] ?? '2M', 0, 512 * 1024, 10 * 1024 * 1024),
    (int)($cacheConfig['eviction_threshold'] ?? 5 * 1024 * 1024)
);

if ($isMaintenanceWorker) {
    \Weline\Framework\App\Env::getInstance()->setRuntimeMaintenanceMode(true);
} else {
    \Weline\Framework\App\Env::getInstance()->setRuntimeMaintenanceMode(false);
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
    $WLS_EVENT_MAX_REQUEST_HEADER_BYTES = $requestFramingLimits['max_header_bytes'];
    $WLS_EVENT_MAX_REQUEST_BODY_BYTES = $requestFramingLimits['max_body_bytes'];
    \Weline\Server\Log\WlsLogger::info_('[PolicyKernel] ready topology=' . $wlsRuntimeTopology
        . ' digest=' . $workerPolicyKernel->policyDigest());
    if ($wlsRuntimeTopology === 'direct') {
        $workerOrdinal = ($workerId - 1) % \max(1, $workerCount);
        $workerPolicyKernel->bootConnectionAcceptGatePool(\max(0, $workerOrdinal));
        \Weline\Server\Log\WlsLogger::info_(
            '[AcceptGate] direct public accept enabled ordinal=' . \max(0, $workerOrdinal)
        );
    }
} catch (\Throwable $policyError) {
    \Weline\Server\Log\WlsLogger::error_('[PolicyKernel] bootstrap failed: ' . $policyError->getMessage());
    throw $policyError;
}
$workerTelemetryReporter = \Weline\Server\Service\Telemetry\WorkerTelemetryReporter::boot($instanceName);
$workerHealthAccessPolicy = \Weline\Server\Service\WorkerHealthAccessPolicy::boot($instanceName);

$base = new \EventBase();
$sslContext = wlsEventBuildSslContext($sslCert, $sslKey);
$maxConnections = (int)($sslConfig['event_buffer_max_connections_per_worker'] ?? 0);
if ($maxConnections <= 0) {
    $maxConnections = (int)($wlsEnv['max_connections'] ?? 10000);
}
$readHighWatermark = \max(65536, (int)($sslConfig['event_buffer_read_high_watermark'] ?? 1048576));
$writeHighWatermark = \max(65536, (int)($sslConfig['event_buffer_write_high_watermark'] ?? 1048576));
$hotPathLogs = (bool)($wlsEnv['debug']['hot_path_logs'] ?? false);
$originToken = (string)($wlsEnv['origin_token'] ?? '');
$originValidationConfig = \is_array($wlsEnv['origin_token_validation'] ?? null) ? $wlsEnv['origin_token_validation'] : [];
$originTokenValidationEnabled = (bool)($originValidationConfig['enabled'] ?? false);
$originTokenHeader = (string)($originValidationConfig['header'] ?? 'X-Weline-Origin-Token');
$originTokenAllowLocal = (bool)($originValidationConfig['allow_local'] ?? true);

$connections = [];
$nextConnectionId = 0;
$requestCount = 0;
$activeRequests = 0;
$maintenanceDrainState = new \Weline\Server\Service\Runtime\WorkerMaintenanceDrainState($isMaintenanceWorker);
$waitingForAck = $controlPort > 0 || $supervisorEnabled;
$maxMemoryBytes = wlsMemoryLimitToBytes($wlsMemoryLimit);
if ($maxMemoryBytes <= 0) {
    $maxMemoryBytes = 256 * 1024 * 1024;
}
$startTime = \time();
$shouldExit = false;
$ipcDraining = false;
$ipcReceivedShutdown = false;
$drainStartTime = 0.0;
$maxDrainTime = 10.0;
$workerLoopStartedSent = false;
$stats = [
    'accepted' => 0,
    'handshake_connected' => 0,
    'closed' => 0,
    'errors' => 0,
    'requests' => 0,
];
$diagnosticLogBudget = 32;
$homepageKeepWarmFiber = null;

$listener = null;
$listener = new \EventListener(
    $base,
    static function (\EventListener $listener, mixed $socket, mixed $address) use (
        &$connections,
        &$nextConnectionId,
        &$stats,
        &$sslContext,
        $base,
        $maxConnections,
        $readHighWatermark,
        $writeHighWatermark,
        $hotPathLogs,
        &$requestCount,
        &$activeRequests,
        $runtime,
        $runtimeError,
        $fpcFastPath,
        $asyncBizAdapters,
        $instanceName,
        $workerId,
        $port,
        $startTime,
        $originToken,
        $originTokenValidationEnabled,
        $originTokenHeader,
        $originTokenAllowLocal,
        &$ipcDraining,
        &$ipcClient,
        &$diagnosticLogBudget,
        $workerTelemetryReporter,
    ): void {
        if (\count($connections) >= $maxConnections) {
            wlsEventCloseAcceptedSocket($socket);
            $stats['errors']++;
            return;
        }

        $id = ++$nextConnectionId;
        $acceptedAt = \microtime(true);
        $peer = wlsEventDescribeAddress($address);
        $acceptGates = \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull();
        if ($acceptGates !== null) {
            $decision = $acceptGates->accept((string)$id, $peer, $acceptedAt);
            if (!$decision->allowed) {
                wlsEventCloseAcceptedSocket($socket);
                $stats['errors']++;
                return;
            }
            $peer = $decision->peerIp;
        }
        if ($diagnosticLogBudget > 0) {
            $diagnosticLogBudget--;
            wlsEventDiagnosticLog(
                'EventBuffer accept conn=' . $id
                . ' socket=' . wlsEventDescribeSocket($socket)
                . ' address=' . wlsEventDescribeAddress($address)
            );
        }
        try {
            $bev = \EventBufferEvent::sslSocket(
                $base,
                $socket,
                $sslContext,
                \EventBufferEvent::SSL_ACCEPTING,
                \EventBufferEvent::OPT_CLOSE_ON_FREE | \EventBufferEvent::OPT_DEFER_CALLBACKS
            );
        } catch (\Throwable $e) {
            $stats['errors']++;
            wlsEventCloseAcceptedSocket($socket);
            \Weline\Server\Log\WlsLogger::warning_('EventBuffer SSL accept failed: ' . $e->getMessage());
            return;
        }

        $connections[$id] = [
            'bev' => $bev,
            'peer' => $peer,
            'buffer' => '',
            'accepted_at' => $acceptedAt,
            'first_read_at' => 0.0,
            'last_activity' => \time(),
            'close_after_write' => false,
            'requests' => 0,
        ];
        $stats['accepted']++;

        $bev->setWatermark(\EventBufferEvent::READING, 0, $readHighWatermark);
        $bev->setWatermark(\EventBufferEvent::WRITING, 0, $writeHighWatermark);
        $bev->setTimeouts(60.0, 60.0);
        $bev->setCallbacks(
            static function (\EventBufferEvent $bev) use (
                $id,
                &$connections,
                &$requestCount,
                &$activeRequests,
                &$stats,
                $runtime,
                $runtimeError,
                $fpcFastPath,
                $asyncBizAdapters,
                $instanceName,
                $workerId,
                $port,
                $startTime,
                $originToken,
                $originTokenValidationEnabled,
                $originTokenHeader,
                $originTokenAllowLocal,
                &$ipcDraining,
                &$ipcClient,
                $hotPathLogs,
                $workerTelemetryReporter,
            ): void {
                if (!isset($connections[$id])) {
                    return;
                }
                if (!\Weline\Server\Service\Policy\WorkerPolicyControl::isApplicationGateOpen()) {
                    $bev->disable(\EventBufferEvent::READING);
                    return;
                }
                $length = (int)($bev->getInput()->length ?? 0);
                if ($length <= 0) {
                    return;
                }
                $chunk = $bev->read(\min($length, 1048576));
                if (!\is_string($chunk) || $chunk === '') {
                    return;
                }

                if (($connections[$id]['first_read_at'] ?? 0.0) <= 0.0) {
                    $connections[$id]['first_read_at'] = \microtime(true);
                    if ($hotPathLogs) {
                        $latency = \round((($connections[$id]['first_read_at'] ?? 0.0) - ($connections[$id]['accepted_at'] ?? 0.0)) * 1000, 2);
                        \Weline\Server\Log\WlsLogger::debug_("EventBuffer first-read conn={$id} latency_ms={$latency}");
                    }
                }

                $connections[$id]['buffer'] .= $chunk;
                $connections[$id]['last_activity'] = \time();
                \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->beginRequest((string)$id);

                while (isset($connections[$id])) {
                    $frame = wlsEventExtractCompleteRequest((string)$connections[$id]['buffer']);
                    if (($frame['status'] ?? '') === 'incomplete') {
                        break;
                    }
                    if (($frame['status'] ?? '') === 'error') {
                        \Weline\Server\Log\WlsLogger::warning_(
                            'Invalid EventBuffer HTTP request framing, reject connection (connId=' . $id
                            . ', reason=' . (string)($frame['error'] ?? 'invalid_framing') . ')'
                        );
                        $connections[$id]['buffer'] = '';
                        $connections[$id]['close_after_write'] = true;
                        $stats['errors']++;
                        $bev->write(wlsHttpFramingErrorResponse((int)($frame['status_code'] ?? 400)));
                        $bev->disable(\EventBufferEvent::READING);
                        break;
                    }

                    $rawRequest = (string)($frame['request'] ?? '');
                    $remaining = \substr(
                        (string)$connections[$id]['buffer'],
                        (int)($frame['consumed'] ?? 0),
                    );
                    \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->markRequestComplete(
                        (string)$id
                    );
                    $connections[$id]['buffer'] = $remaining;
                    if ($remaining !== '') {
                        \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->beginRequest(
                            (string)$id
                        );
                    }
                    $requestCount++;
                    $activeRequests++;
                    $stats['requests']++;
                    $connections[$id]['requests'] = ((int)($connections[$id]['requests'] ?? 0)) + 1;
                    $handleStart = \microtime(true);
                    $servedFromFpc = false;

                    $response = wlsEventHandleRequest(
                        $rawRequest,
                        $runtime,
                        $runtimeError,
                        $fpcFastPath,
                        $handleStart,
                        $servedFromFpc,
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
                        $id,
                        (string)($connections[$id]['peer'] ?? ''),
                        $frame,
                    );

                    $durationMs = \round((\microtime(true) - $handleStart) * 1000, 2);
                    $isFpcHit = $servedFromFpc;
                    if (!$isFpcHit || wlsExplicitPerformanceDiagnosticsRequested($rawRequest)) {
                        $response = wlsEventInjectProcessTimeHeader($response, $durationMs);
                    }
                    $response = wlsDecorateFormattedBenchmarkWorkerIdentity($response, $rawRequest);
                    if ($ipcDraining) {
                        $response = \Weline\Server\Service\WorkerResponseMemoryGuard::forceConnectionCloseHeader($response);
                    }
                    $bev->write($response);

                    $activeRequests = \max(0, $activeRequests - 1);
                    $isStaticL1Hit = \str_contains($response, "\r\nX-WLS-Static-Cache: HIT\r\n");
                    if (!$isStaticL1Hit && !$isFpcHit) {
                        $workerTelemetryReporter->record(
                            $ipcClient instanceof \Weline\Server\IPC\ChildControl\ChildControlClientInterface ? $ipcClient : null,
                            wlsEventRequestHost($rawRequest),
                            wlsEventResponseStatus($response),
                            (int)$durationMs,
                            \strlen($response),
                        );
                    }

                    $close = $ipcDraining
                        || wlsEventResponseRequestsClose($response)
                        || (!$isStaticL1Hit && !$isFpcHit && !wlsEventIsKeepAlive($rawRequest));
                    if ($close) {
                        $connections[$id]['close_after_write'] = true;
                        $bev->disable(\EventBufferEvent::READING);
                        break;
                    }
                }
            },
            static function (\EventBufferEvent $bev) use ($id, &$connections, &$stats): void {
                if (!isset($connections[$id])) {
                    return;
                }
                $outputLength = (int)($bev->getOutput()->length ?? 0);
                if ($outputLength === 0 && !empty($connections[$id]['close_after_write'])) {
                    wlsEventCloseConnection($id, $connections, $stats);
                }
            },
            static function (\EventBufferEvent $bev, int $events) use ($id, &$connections, &$stats, $hotPathLogs, &$diagnosticLogBudget): void {
                if ($diagnosticLogBudget > 0) {
                    $diagnosticLogBudget--;
                    wlsEventDiagnosticLog(
                        'EventBuffer event conn=' . $id
                        . ' flags=' . wlsEventDescribeBufferEvents($events)
                    );
                }
                if (($events & \EventBufferEvent::CONNECTED) !== 0) {
                    $stats['handshake_connected']++;
                    if (isset($connections[$id])) {
                        $connections[$id]['handshake_at'] = \microtime(true);
                    }
                    return;
                }

                if (($events & (\EventBufferEvent::EOF | \EventBufferEvent::ERROR | \EventBufferEvent::TIMEOUT)) !== 0) {
                    if (($events & \EventBufferEvent::ERROR) !== 0) {
                        $stats['errors']++;
                        $error = wlsEventReadSslError($bev);
                        if ($hotPathLogs || $error !== '') {
                            \Weline\Server\Log\WlsLogger::warning_("EventBuffer SSL connection error conn={$id} error={$error}");
                        }
                    }
                    wlsEventCloseConnection($id, $connections, $stats);
                }
            }
        );
        $bev->enable(\EventBufferEvent::READING | \EventBufferEvent::WRITING);
    },
    null,
    \EventListener::OPT_CLOSE_ON_FREE | \EventListener::OPT_REUSEABLE,
    102400,
    "{$host}:{$port}"
);
$listener->setErrorCallback(static function () use (&$stats): void {
    $stats['errors']++;
    \Weline\Server\Log\WlsLogger::error_('EventBuffer SSL listener error');
});
$eventPolicyGateOpen = !$waitingForAck
    && \Weline\Server\Service\Policy\WorkerPolicyControl::isApplicationGateOpen();
if (!$eventPolicyGateOpen) {
    $listener->disable();
}
\Weline\Server\Service\Runtime\WorkerReadinessState::markListenerBound(
    false,
    (string)($eventLoopMeta['resolved'] ?? $wlsLoopDriver),
    'event_buffer',
    'single',
);

\Weline\Server\Log\WlsLogger::info_("EventBuffer SSL listener ready tcp://{$host}:{$port}");
\Weline\Server\Log\WlsLogger::flush_(true);

$kernel = null;
$ipcClient = null;
$cacheClearEpoch = 0;
\Weline\Server\Security\GlobalRateLimiter::setBanDeltaPublisher(
    static function (string $deltaInstance, string $ip, int $expiresAt) use (&$ipcClient): void {
        if ($ipcClient !== null && $ipcClient->isConnected()) {
            $ipcClient->send(\Weline\Server\IPC\ControlMessage::policyStateDelta($deltaInstance, $ip, $expiresAt), false);
        }
    }
);
$waitingForAck = $waitingForAck ?? false;
$readySentTime = 0.0;
$ackRetryCount = 0;
$ipcRole = $isMaintenanceWorker
    ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE
    : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;

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
        static function (array $msg) use (
            &$shouldExit,
            &$ipcDraining,
            &$ipcReceivedShutdown,
            &$drainStartTime,
            &$listener,
            &$sslContext,
            $sslCert,
            $sslKey,
            &$stats,
            &$ipcClient,
            &$waitingForAck,
            $workerId,
            $port,
            $isMaintenanceWorker,
            $wlsRuntimeTopology,
            $instanceName,
            &$cacheClearEpoch,
            $maintenanceDrainState,
        ): void {
            $type = (string)($msg['type'] ?? '');
            switch ($type) {
                case \Weline\Server\IPC\ControlMessage::TYPE_PING:
                    $pingTimestamp = (float)($msg['timestamp'] ?? 0.0);
                    $ipcClient?->send(\Weline\Server\IPC\ControlMessage::pong($pingTimestamp, [
                        'active_connections' => (int)($stats['accepted'] - $stats['closed']),
                        'accepted' => (int)$stats['accepted'],
                        'handshake_connected' => (int)$stats['handshake_connected'],
                        'closed' => (int)$stats['closed'],
                        'errors' => (int)$stats['errors'],
                        'requests' => (int)$stats['requests'],
                        'memory_usage' => \memory_get_usage(true),
                    ]));
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_ACK_READY:
                case \Weline\Server\IPC\ControlMessage::TYPE_READY_ACK:
                    $accepted = !\array_key_exists('accepted', $msg) || (bool)($msg['accepted'] ?? false);
                    if (!$accepted) {
                        $shouldExit = true;
                        $ipcDraining = true;
                        $drainStartTime = \microtime(true);
                        $listener?->disable();
                        \Weline\Server\Log\WlsLogger::warning_(
                            'EventBuffer SSL worker READY rejected: ' . (string)($msg['reason'] ?? 'ready_rejected')
                        );
                        break;
                    }
                    $waitingForAck = false;
                    \Weline\Server\Log\WlsLogger::info_("EventBuffer SSL worker READY acknowledged worker_id={$workerId} port={$port}");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_RELOAD:
                case \Weline\Server\IPC\ControlMessage::TYPE_DRAIN:
                case \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN:
                    if ($type === \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN) {
                        $ipcReceivedShutdown = true;
                    }
                    $shouldExit = true;
                    $ipcDraining = true;
                    $drainStartTime = \microtime(true);
                    if ($listener instanceof \EventListener) {
                        $listener->disable();
                        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                    }
                    \Weline\Server\Log\WlsLogger::info_("EventBuffer SSL worker entering drain for {$type}");
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
                        \Weline\Server\Log\WlsLogger::warning_(
                            "EventBuffer SSL worker rejected stale cache epoch {$requestedCacheEpoch}; current={$cacheClearEpoch}"
                        );
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
                        \Weline\Framework\Manager\ObjectManager::getInstance(
                            \Weline\Framework\Runtime\ModuleProcessCacheResetterRegistry::class
                        )->reset(new \Weline\Framework\Runtime\ProcessCacheResetContext(
                            \Weline\Framework\Runtime\ProcessCacheResetContext::REASON_CACHE_CLEAR,
                            true
                        ));
                        if (\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class)) {
                            \Weline\Framework\Router\FullPageCacheCoordinator::clearProcessCache();
                        }
                        \Weline\Server\Service\WorkerStaticResponseL1::clear();
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
                        \Weline\Server\Log\WlsLogger::info_(
                            "EventBuffer SSL worker cache cleared epoch={$cacheClearEpoch}"
                        );
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
                        \Weline\Server\Log\WlsLogger::error_(
                            'EventBuffer SSL worker cache clear failed: ' . $throwable->getMessage()
                        );
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SSL_CERT_RELOAD:
                    try {
                        $sslContext = wlsEventBuildSslContext($sslCert, $sslKey);
                        \Weline\Server\Log\WlsLogger::info_('EventBuffer SSL context reloaded from default certificate files');
                    } catch (\Throwable $e) {
                        \Weline\Server\Log\WlsLogger::error_('EventBuffer SSL context reload failed: ' . $e->getMessage());
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_ROUTING_POLICY:
                    $policyData = $msg['data'] ?? [];
                    if (\is_array($policyData)) {
                        \Weline\Server\Service\Runtime\RoutingPolicyRegistry::update($policyData);
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SET_MAINTENANCE_MODE:
                    $mEnabled = $isMaintenanceWorker ? true : (bool)($msg['enabled'] ?? false);
                    \Weline\Framework\App\Env::getInstance()->setRuntimeMaintenanceMode($mEnabled);
                    \Weline\Server\Security\WorkerPolicyKernel::instance()->setMaintenanceMode($mEnabled);
                    $requestId = (string)($msg['request_id'] ?? '');
                    $maintenanceDrainState->modeApplied($mEnabled, $requestId);
                    \Weline\Server\Log\WlsLogger::info_(
                        'EventBuffer Worker 已应用维护模式 enabled=' . ($mEnabled ? 'true' : 'false')
                        . ' request_id=' . $requestId
                        . ' pinned_role=' . ($isMaintenanceWorker ? 'maintenance' : 'business')
                    );
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SECURITY_UNBLOCK:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_STATE_DELTA:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_PREPARE:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_ACTIVATE:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_COMMIT:
                case \Weline\Server\IPC\ControlMessage::TYPE_POLICY_ROLLBACK:
                    $policyReply = \Weline\Server\Service\Policy\WorkerPolicyControl::handle($msg, $wlsRuntimeTopology, $instanceName);
                    if ($policyReply !== null) {
                        $ipcClient?->send($policyReply);
                    }
                    break;
            }
        },
        static function () use (&$ipcClient): void {
            $ipcClient?->tryReconnect();
        }
    );
    $kernel = new \Weline\Server\IPC\ChildControl\SubprocessControlKernel(
        $identity,
        $handler,
        $ipcSelfTag,
        (\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE),
        $instanceName
    );
    $registered = $kernel->connectAndRegister($controlPort, false);
    $readyReported = false;
    if ($registered) {
        $ipcClient = $kernel->getClient();
        if (!$isMaintenanceWorker
            && $runtimeError === null
            && $runtime instanceof \Weline\Framework\Runtime\WlsRuntime
        ) {
            \Weline\Server\Log\WlsLogger::info_("[WorkerWarmup] EventBuffer READY-gate warmup start worker={$workerId}");
            $homepageFpcProof = $runtime->runReadyGateWorkerBootstrapWarmup();
            \Weline\Server\Service\Runtime\WorkerReadinessState::markBusinessHomepageHot($homepageFpcProof);
            \Weline\Server\Service\Runtime\WorkerReadinessState::markDynamicFirstRenderProof(
                $runtime->readyGateDynamicFirstRenderProof()
            );
            \Weline\Server\Log\WlsLogger::info_("[WorkerWarmup] EventBuffer READY-gate warmup done worker={$workerId}");
        } elseif ($isMaintenanceWorker) {
            \Weline\Server\Service\Runtime\WorkerReadinessState::markMaintenanceReady();
        }
        $readyReported = $kernel->sendReady();
    }
    if ($registered && $readyReported) {
        $waitingForAck = !($ipcClient?->isReadyStateConfirmed() ?? false);
        $readySentTime = \microtime(true);
        \Weline\Server\Log\WlsLogger::info_("EventBuffer SSL worker registered and sent READY to Master control port {$controlPort}");
    } else {
        $failureStage = $registered ? 'send READY after bootstrap warmup' : 'register with Master';
        \Weline\Server\Log\WlsLogger::error_("EventBuffer SSL worker failed to {$failureStage} control_port={$controlPort} worker_id={$workerId} port={$port}");
        exit(2);
    }
}

$tickTimer = new \Event($base, -1, \Event::TIMEOUT | \Event::PERSIST, static function () use (
    &$kernel,
    &$ipcClient,
    &$workerLoopStartedSent,
    &$waitingForAck,
    &$readySentTime,
    &$ackRetryCount,
    $workerId,
    $port,
    $ipcRole,
    $orchestratorEpoch,
    $orchestratorLaunchId,
    &$shouldExit,
    &$ipcDraining,
    &$drainStartTime,
    $maxDrainTime,
    &$connections,
    &$stats,
    &$listener,
    &$eventPolicyGateOpen,
    $base,
    $masterPid,
    &$ipcReceivedShutdown,
    $childMasterGuard,
    $fiberScheduler,
    &$homepageKeepWarmFiber,
    $runtime,
    $runtimeError,
    $asyncBizAdapters,
    $instanceName,
    &$requestCount,
    $startTime,
    $originToken,
    $originTokenValidationEnabled,
    $originTokenHeader,
    $originTokenAllowLocal,
    $isMaintenanceWorker,
    &$activeRequests,
    $maxMemoryBytes,
    $workerTelemetryReporter,
    $maintenanceDrainState,
): void {
    if ($kernel !== null) {
        $kernel->tick();
        $kernel->flushWrites();
        $ipcClient = $kernel->getClient();
        if ($ipcClient !== null && $ipcClient->isConnected() && !$workerLoopStartedSent) {
            $ipcClient->sendWorkerLoopStarted($workerId, $port, (int)\getmypid());
            $workerLoopStartedSent = true;
        }
        if ($waitingForAck && $ipcClient !== null && $ipcClient->isConnected() && (\microtime(true) - $readySentTime) >= 10.0) {
            $ackRetryCount++;
            $ipcClient->sendReady($ipcRole, $workerId, $port, $orchestratorEpoch, $orchestratorLaunchId);
            $readySentTime = \microtime(true);
        }
    }
    $workerTelemetryReporter->tick($ipcClient);

    if ($maintenanceDrainState->isWaitingForRequestDrain() && $activeRequests === 0) {
        wlsEventDrainBufferedMaintenanceRequests(
            $connections,
            $stats,
            $runtime,
            $runtimeError,
            $fpcFastPath,
            $asyncBizAdapters,
            $instanceName,
            $workerId,
            $port,
            $requestCount,
            $activeRequests,
            $startTime,
            $originToken,
            $originTokenValidationEnabled,
            $originTokenHeader,
            $originTokenAllowLocal,
            $workerTelemetryReporter,
            $ipcClient,
        );
    }

    $maintenanceRequestWorkDrained = $activeRequests === 0;
    if ($maintenanceRequestWorkDrained) {
        foreach ($connections as $maintenanceConnection) {
            $maintenanceBev = $maintenanceConnection['bev'] ?? null;
            if ($maintenanceBev instanceof \EventBufferEvent
                && (int)($maintenanceBev->getOutput()->length ?? 0) > 0
            ) {
                $maintenanceRequestWorkDrained = false;
                break;
            }
            // A complete pipelined request already in the PHP-side buffer is
            // admitted work. Partial input remains pre-dispatch and cannot let
            // a slowloris connection hold the maintenance barrier.
            $maintenanceInput = (string)($maintenanceConnection['buffer'] ?? '');
            if ($maintenanceInput !== '') {
                $maintenanceFrame = wlsEventExtractCompleteRequest($maintenanceInput);
                if (($maintenanceFrame['status'] ?? '') !== 'incomplete') {
                    $maintenanceRequestWorkDrained = false;
                    break;
                }
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
        \Weline\Server\Log\WlsLogger::info_(
            'EventBuffer 维护排水已完成，已上报 Master ACK request_id=' . $maintenanceAckRequestId
        );
    }

    static $attackLogNextFlushCheckAt = 0.0;
    $attackLogNow = \microtime(true);
    if ($activeRequests <= 0 && $attackLogNow >= $attackLogNextFlushCheckAt) {
        $attackLogNextFlushCheckAt = $attackLogNow + 0.25;
        $attackLogOutputIdle = true;
        foreach ($connections as $attackLogConnection) {
            $attackLogBev = $attackLogConnection['bev'] ?? null;
            if ($attackLogBev instanceof \EventBufferEvent && (int)($attackLogBev->getOutput()->length ?? 0) > 0) {
                $attackLogOutputIdle = false;
                break;
            }
        }
        if ($attackLogOutputIdle) {
            \Weline\Server\Service\AttackLogService::flushIfDue();
        }
    }

    $connectionAcceptGates = \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull();
    if ($connectionAcceptGates !== null) {
        foreach ($connectionAcceptGates->sweep() as $directive) {
            wlsEventCloseConnection((int)$directive->connectionId, $connections, $stats);
        }
        $connectionAcceptGates->reconcileMapsIfDue($connections);
    }

    $applicationPolicyGateOpen = !$waitingForAck
        && \Weline\Server\Service\Policy\WorkerPolicyControl::isApplicationGateOpen();
    $desiredListenerGateOpen = $applicationPolicyGateOpen
        && !$shouldExit
        && !$ipcDraining;
    if ($desiredListenerGateOpen !== $eventPolicyGateOpen) {
        $eventPolicyGateOpen = $desiredListenerGateOpen;
        if ($listener instanceof \EventListener) {
            $eventPolicyGateOpen ? $listener->enable() : $listener->disable();
        }
        foreach ($connections as $connection) {
            $bev = $connection['bev'] ?? null;
            if (!$bev instanceof \EventBufferEvent) {
                continue;
            }
            // DRAIN closes admission only. Connections accepted before the
            // fence must finish instead of being frozen until timeout.
            if ($applicationPolicyGateOpen && empty($connection['close_after_write'])) {
                $bev->enable(\EventBufferEvent::READING);
            } else {
                $bev->disable(\EventBufferEvent::READING);
            }
        }
    }
    if ($ipcClient !== null && $ipcClient->isConnected()) {
        $pendingPolicyResponses = 0;
        foreach ($connections as $connection) {
            $bev = $connection['bev'] ?? null;
            if ($bev instanceof \EventBufferEvent && (int)($bev->getOutput()->length ?? 0) > 0) {
                $pendingPolicyResponses++;
            }
        }
        $policyDrainReply = \Weline\Server\Service\Policy\WorkerPolicyControl::pollAfterApplicationDrain(
            $activeRequests,
            $homepageKeepWarmFiber instanceof \Fiber && !$homepageKeepWarmFiber->isTerminated() ? 1 : 0,
            $pendingPolicyResponses
        );
        if ($policyDrainReply !== null) {
            $ipcClient->send($policyDrainReply);
        }
    }

    static $lastMasterProcessCheck = 0.0;
    $now = \microtime(true);
    if ($childMasterGuard->shouldExit()) {
        \Weline\Server\Log\WlsLogger::warning_('EventBuffer SSL worker Master lease/PID invalid; exiting: ' . $childMasterGuard->getLastExitReason());
        $shouldExit = true;
        $ipcDraining = true;
        $drainStartTime = $now;
        if ($listener instanceof \EventListener) {
            $listener->disable();
        }
    }

    if (!$childMasterGuard->isEnabled()
        && $masterPid > 0
        && !$ipcReceivedShutdown
        && ($now - $lastMasterProcessCheck) >= 2.0) {
        $lastMasterProcessCheck = $now;
        if (!wlsEventProcessExists($masterPid)) {
            \Weline\Server\Log\WlsLogger::warning_("EventBuffer SSL worker detected dead Master PID {$masterPid}; exiting");
            $shouldExit = true;
            $ipcDraining = true;
            $drainStartTime = $now;
        }
    }

    $homepageMemoryPressure = \memory_get_usage(true) >= (int)($maxMemoryBytes * 0.70);
    $homepageKeepWarmMayRun = !$shouldExit
        && !$isMaintenanceWorker
        && !$ipcDraining
        && !$ipcReceivedShutdown
        && $workerLoopStartedSent
        && $connections === []
        && $activeRequests === 0
        && !$homepageMemoryPressure;
    if ($homepageKeepWarmMayRun) {
        // EventBuffer owns the libevent loop. Advance cooperative timers only
        // while the worker remains idle so keep-warm never competes with traffic.
        $fiberScheduler->tick(null, 2.0);
        if ($homepageKeepWarmFiber instanceof \Fiber && $homepageKeepWarmFiber->isTerminated()) {
            $homepageKeepWarmFiber = null;
        }
    }
    if ($homepageKeepWarmMayRun
        && $homepageKeepWarmFiber === null
        && \Weline\Server\Service\Policy\WorkerPolicyControl::isApplicationGateOpen()
        && $runtime instanceof \Weline\Framework\Runtime\WlsRuntime
        && $runtime->shouldScheduleHomepageKeepWarm(0, false, false)
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
            $homepageKeepWarmFiber = null;
            \Weline\Server\Log\WlsLogger::warning_('[WorkerWarmup] EventBuffer homepage keep-warm start failed: ' . $e->getMessage());
        }
    }

    if (!$shouldExit) {
        return;
    }

    if ($connections !== []) {
        $drainElapsed = \microtime(true) - $drainStartTime;
        if ($drainElapsed < $maxDrainTime) {
            return;
        }

        $hasPendingApplicationWork = $activeRequests > 0;
        if (!$hasPendingApplicationWork) {
            foreach ($connections as $connection) {
                $bev = $connection['bev'] ?? null;
                if ($bev instanceof \EventBufferEvent && (int)($bev->getOutput()->length ?? 0) > 0) {
                    $hasPendingApplicationWork = true;
                    break;
                }
            }
        }
        if ($hasPendingApplicationWork) {
            static $lastEventDrainExtensionLogAt = 0.0;
            if (($now - $lastEventDrainExtensionLogAt) >= 1.0) {
                \Weline\Server\Log\WlsLogger::warning_(
                    'EventBuffer drain soft deadline reached; waiting for response output to flush'
                );
                $lastEventDrainExtensionLogAt = $now;
            }
            return;
        }
    }

    foreach (\array_keys($connections) as $id) {
        wlsEventCloseConnection((int)$id, $connections, $stats);
    }
    if ($kernel !== null) {
        $eventWorkerExitReason = $ipcReceivedShutdown
            ? "shutdown_command:worker={$workerId}"
            : "drain_or_reload:worker={$workerId}";
        $kernel->sendExitReason($eventWorkerExitReason);
        $kernel->sendDrainingComplete($eventWorkerExitReason);
        $kernel->sendExited();
        $kernel->flushWrites();
        $kernel->close();
    }
    \Weline\Server\Service\AttackLogService::flushForShutdown();
    \Weline\Server\Log\WlsLogger::flush_(true);
    $base->exit();
});
$tickTimer->add(0.05);

\Weline\Server\Log\WlsLogger::info_('EventBuffer SSL worker entering event loop');
\Weline\Server\Log\WlsLogger::flush_(true);
$base->loop();
\Weline\Server\Service\AttackLogService::flushForShutdown();
\Weline\Server\Log\WlsLogger::flush_(true);
exit(0);

function wlsEventBuildSslContext(string $sslCert, string $sslKey): \EventSslContext
{
    $options = [
        \EventSslContext::OPT_LOCAL_CERT => $sslCert,
        \EventSslContext::OPT_LOCAL_PK => $sslKey,
        \EventSslContext::OPT_VERIFY_PEER => false,
        \EventSslContext::OPT_ALLOW_SELF_SIGNED => true,
        \EventSslContext::OPT_CIPHERS => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:!aNULL:!eNULL:!MD5:!RC4:!DES:!3DES:!DSS:!SHA1:!DHE',
        \EventSslContext::OPT_NO_SSLv2 => true,
        \EventSslContext::OPT_NO_SSLv3 => true,
        \EventSslContext::OPT_NO_TLSv1 => true,
        \EventSslContext::OPT_NO_TLSv1_1 => true,
        \EventSslContext::OPT_CIPHER_SERVER_PREFERENCE => true,
    ];
    $context = new \EventSslContext(\EventSslContext::TLS_SERVER_METHOD, $options);
    if (\method_exists($context, 'setMinProtoVersion')) {
        @$context->setMinProtoVersion(\EventSslContext::TLS1_2_VERSION);
    }
    if (
        \method_exists($context, 'setMaxProtoVersion')
        && \defined('EventSslContext::TLS1_3_VERSION')
    ) {
        @$context->setMaxProtoVersion((int)\constant('EventSslContext::TLS1_3_VERSION'));
    }

    return $context;
}

function wlsEventCalculateCacheSize(string|int $value, int $defaultPercent, int $defaultMin, int $defaultMax): int
{
    if (\is_int($value)) {
        return $value;
    }
    $value = \strtolower(\trim($value));
    if ($value === '' || $value === 'auto') {
        $total = PHP_OS_FAMILY === 'Windows' ? 4 * 1024 * 1024 * 1024 : 8 * 1024 * 1024 * 1024;
        return \max($defaultMin, \min($defaultMax, (int)($total * $defaultPercent / 100)));
    }
    if (\preg_match('/^(\d+(?:\.\d+)?)\s*(k|kb|m|mb|g|gb)?$/i', $value, $m)) {
        $number = (float)$m[1];
        return match (\strtolower((string)($m[2] ?? ''))) {
            'k', 'kb' => (int)($number * 1024),
            'm', 'mb' => (int)($number * 1024 * 1024),
            'g', 'gb' => (int)($number * 1024 * 1024 * 1024),
            default => (int)$number,
        };
    }

    return $defaultMin;
}

function wlsEventCloseAcceptedSocket(mixed $socket): void
{
    if (\is_resource($socket)) {
        @\fclose($socket);
        return;
    }
    if ($socket instanceof \Socket) {
        @\socket_close($socket);
    }
}

function wlsEventDiagnosticLog(string $message): void
{
    \Weline\Server\Log\WlsLogger::info_($message);
    \Weline\Server\Log\WlsLogger::flush_(true);
}

function wlsEventDescribeSocket(mixed $socket): string
{
    if (\is_resource($socket)) {
        return 'resource:' . \get_resource_type($socket);
    }
    if ($socket instanceof \Socket) {
        return 'Socket';
    }
    return \get_debug_type($socket);
}

function wlsEventDescribeAddress(mixed $address): string
{
    if (\is_scalar($address) || $address === null) {
        return (string)$address;
    }
    if (\is_array($address)) {
        $host = (string)($address[0] ?? $address['host'] ?? $address['ip'] ?? '');
        $port = (int)($address[1] ?? $address['port'] ?? 0);
        if (\filter_var($host, FILTER_VALIDATE_IP)) {
            if ($port <= 0) {
                return $host;
            }
            return \str_contains($host, ':') ? '[' . $host . ']:' . $port : $host . ':' . $port;
        }
    }
    $encoded = @\json_encode($address, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return \is_string($encoded) ? $encoded : \get_debug_type($address);
}

function wlsEventDescribeBufferEvents(int $events): string
{
    $names = [];
    foreach ([
        'READING' => \EventBufferEvent::READING,
        'WRITING' => \EventBufferEvent::WRITING,
        'EOF' => \EventBufferEvent::EOF,
        'ERROR' => \EventBufferEvent::ERROR,
        'TIMEOUT' => \EventBufferEvent::TIMEOUT,
        'CONNECTED' => \EventBufferEvent::CONNECTED,
    ] as $name => $bit) {
        if (($events & $bit) !== 0) {
            $names[] = $name;
        }
    }

    return ($names !== [] ? \implode('|', $names) : 'none') . '(' . $events . ')';
}

function wlsEventReadSslError(\EventBufferEvent $bev): string
{
    try {
        $error = $bev->sslError();
    } catch (\Throwable $throwable) {
        return 'sslError failed: ' . $throwable->getMessage();
    }

    if (\is_scalar($error) || $error === null) {
        return (string)$error;
    }
    $encoded = @\json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return \is_string($encoded) ? $encoded : \get_debug_type($error);
}

function wlsEventCloseConnection(int $id, array &$connections, array &$stats): void
{
    \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->close((string)$id);
    if (!isset($connections[$id])) {
        return;
    }
    $bev = $connections[$id]['bev'] ?? null;
    unset($connections[$id]);
    if ($bev instanceof \EventBufferEvent) {
        try {
            $bev->close();
        } catch (\Throwable) {
        }
        try {
            $bev->free();
        } catch (\Throwable) {
        }
    }
    $stats['closed']++;
}

/**
 * EventBuffer uses the same authoritative request boundary as stream HTTP/TLS.
 *
 * @return array{status:string,consumed:int,request:string,error:string,status_code:int,header_bytes:int,content_length:int}
 */
function wlsEventExtractCompleteRequest(string $buffer): array
{
    global $WLS_EVENT_MAX_REQUEST_HEADER_BYTES, $WLS_EVENT_MAX_REQUEST_BODY_BYTES;

    return wlsParseHttpRequestFrame(
        $buffer,
        (int)($WLS_EVENT_MAX_REQUEST_HEADER_BYTES ?? 65536),
        (int)($WLS_EVENT_MAX_REQUEST_BODY_BYTES ?? 16 * 1024 * 1024),
    );
}

/**
 * Finish complete pipelined requests that were already copied out of libevent
 * before maintenance was applied. The budget bounds control-plane work per
 * tick; partial input is deliberately ignored so slow clients cannot hold ACK.
 *
 * @param array<int, array<string, mixed>> $connections
 * @param array<string, int> $stats
 */
function wlsEventDrainBufferedMaintenanceRequests(
    array &$connections,
    array &$stats,
    ?\Weline\Framework\Runtime\WlsRuntime $runtime,
    ?string $runtimeError,
    ?\Weline\Server\Service\WorkerFullPageCacheFastPath $fpcFastPath,
    \Weline\Server\Runtime\Async\AsyncBizAdapters $asyncBizAdapters,
    string $instanceName,
    int $workerId,
    int $port,
    int &$requestCount,
    int &$activeRequests,
    int $startTime,
    string $originToken,
    bool $originTokenValidationEnabled,
    string $originTokenHeader,
    bool $originTokenAllowLocal,
    \Weline\Server\Service\Telemetry\WorkerTelemetryReporter $workerTelemetryReporter,
    ?\Weline\Server\IPC\ChildControl\ChildControlClientInterface $ipcClient,
    int $budget = 64,
): int {
    $processed = 0;
    $budget = \max(1, \min(256, $budget));

    foreach (\array_keys($connections) as $connectionId) {
        if ($processed >= $budget || !isset($connections[$connectionId])) {
            break;
        }
        $bev = $connections[$connectionId]['bev'] ?? null;
        if (!$bev instanceof \EventBufferEvent) {
            continue;
        }

        $processedForConnection = 0;
        while ($processed < $budget && isset($connections[$connectionId])) {
            $buffer = (string)($connections[$connectionId]['buffer'] ?? '');
            $frame = wlsEventExtractCompleteRequest($buffer);
            if (($frame['status'] ?? '') === 'incomplete') {
                break;
            }
            if (($frame['status'] ?? '') === 'error') {
                $connections[$connectionId]['buffer'] = '';
                $connections[$connectionId]['close_after_write'] = true;
                $bev->write(wlsHttpFramingErrorResponse((int)($frame['status_code'] ?? 400)));
                $bev->disable(\EventBufferEvent::READING);
                $stats['errors'] = ((int)($stats['errors'] ?? 0)) + 1;
                $processed++;
                break;
            }

            $rawRequest = (string)($frame['request'] ?? '');
            $remaining = \substr($buffer, (int)($frame['consumed'] ?? 0));
            $connections[$connectionId]['buffer'] = $remaining;
            \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->markRequestComplete(
                (string)$connectionId
            );
            if ($remaining !== '') {
                \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->beginRequest(
                    (string)$connectionId
                );
            }
            $requestCount++;
            $activeRequests++;
            $stats['requests'] = ((int)($stats['requests'] ?? 0)) + 1;
            $connections[$connectionId]['requests'] = ((int)($connections[$connectionId]['requests'] ?? 0)) + 1;
            $handleStartedAt = \microtime(true);
            $servedFromFpc = false;

            try {
                $response = wlsEventHandleRequest(
                    $rawRequest,
                    $runtime,
                    $runtimeError,
                    $fpcFastPath,
                    $handleStartedAt,
                    $servedFromFpc,
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
                    (int)$connectionId,
                    (string)($connections[$connectionId]['peer'] ?? ''),
                    $frame,
                );
            } catch (\Throwable $throwable) {
                \Weline\Server\Log\WlsLogger::error_(
                    'EventBuffer maintenance drain request failed: ' . $throwable->getMessage()
                );
                $body = 'Service Unavailable';
                $response = "HTTP/1.1 503 Service Unavailable\r\n"
                    . "Content-Type: text/plain; charset=utf-8\r\n"
                    . 'Content-Length: ' . \strlen($body) . "\r\n"
                    . "Connection: close\r\n\r\n"
                    . $body;
            } finally {
                $activeRequests = \max(0, $activeRequests - 1);
            }

            $durationMs = \round((\microtime(true) - $handleStartedAt) * 1000, 2);
            $isFpcHit = $servedFromFpc;
            if (!$isFpcHit || wlsExplicitPerformanceDiagnosticsRequested($rawRequest)) {
                $response = wlsEventInjectProcessTimeHeader($response, $durationMs);
            }
            $response = wlsDecorateFormattedBenchmarkWorkerIdentity($response, $rawRequest);
            $response = \Weline\Server\Service\WorkerResponseMemoryGuard::forceConnectionCloseHeader($response);
            $bev->write($response);
            if (!\str_contains($response, "\r\nX-WLS-Static-Cache: HIT\r\n") && !$isFpcHit) {
                $workerTelemetryReporter->record(
                    $ipcClient,
                    wlsEventRequestHost($rawRequest),
                    wlsEventResponseStatus($response),
                    (int)$durationMs,
                    \strlen($response),
                );
            }
            $processed++;
            $processedForConnection++;
        }

        if ($processedForConnection > 0 && isset($connections[$connectionId])) {
            $remaining = (string)($connections[$connectionId]['buffer'] ?? '');
            if (($remaining === '' || (wlsEventExtractCompleteRequest($remaining)['status'] ?? '') === 'incomplete')) {
                $connections[$connectionId]['close_after_write'] = true;
                $bev->disable(\EventBufferEvent::READING);
            }
        }
    }

    return $processed;
}

function wlsEventHeader(string $rawRequest, string $headerName): ?string
{
    return getHeaderValue($rawRequest, $headerName);
}

function wlsEventIsKeepAlive(string $rawRequest): bool
{
    return isKeepAlive($rawRequest);
}

function wlsEventRequestHost(string $rawRequest): string
{
    $host = (string)(wlsEventHeader($rawRequest, 'Host') ?? '');
    if (\str_contains($host, ':')) {
        $host = (string)\explode(':', $host, 2)[0];
    }

    return $host;
}

function wlsEventResponseStatus(string $response): int
{
    if (\preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/', $response, $m)) {
        return (int)$m[1];
    }

    return 200;
}

function wlsEventResponseRequestsClose(string $response): bool
{
    $headerEnd = \strpos($response, "\r\n\r\n");
    $headers = $headerEnd === false ? $response : \substr($response, 0, $headerEnd);

    return \preg_match('/^Connection:\s*close\b/im', $headers) === 1;
}

function wlsEventInjectProcessTimeHeader(string $response, float $durationMs): string
{
    $pos = \strpos($response, "\r\n\r\n");
    if ($pos === false) {
        return $response;
    }
    $ms = \round($durationMs, 2);
    $headers = "X-WLS-SSL-Engine: event_buffer\r\nX-WLS-Process-Time: {$ms}\r\nServer-Timing: wls;dur={$ms};desc=\"WLS Process\"\r\n";

    return \substr_replace($response, $headers, $pos + 2, 0);
}

function wlsEventBadRequestResponse(): string
{
    $body = 'Bad Request';
    return "HTTP/1.1 400 Bad Request\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: "
        . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
}

function wlsEventHandleRequest(
    string $rawRequest,
    ?\Weline\Framework\Runtime\WlsRuntime $runtime,
    ?string $runtimeError,
    ?\Weline\Server\Service\WorkerFullPageCacheFastPath $fpcFastPath,
    float $requestStartedAt,
    bool &$servedFromFpc,
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
    int $connectionId,
    string $transportPeer = '',
    ?array $parsedFrame = null,
): string {
    $servedFromFpc = false;
    $policyDecision = \Weline\Server\Security\WorkerPolicyKernel::instance()->evaluate(
        $rawRequest,
        $transportPeer,
        $parsedFrame,
    );
    if (!$policyDecision->allowed) {
        return (string)$policyDecision->response;
    }
    $policyServerInfo = $policyDecision->requestServerInfo();

    $method = $policyDecision->method;
    $uri = $policyDecision->path;
    $requestTarget = $policyDecision->target;
    if (\Weline\Server\Service\WlsStaticUriPathResolver::resolvePath($requestTarget) === null) {
        return wlsEventBadRequestResponse();
    }

    if ($method === 'GET' && $uri === '/_wls/health') {
        $keepAlive = $policyDecision->keepAlive();
        if (!\Weline\Server\Service\WorkerHealthAccessPolicy::instance($instanceName)->allowsClient(
            $policyDecision->clientIp,
            $policyDecision->headers,
        )) {
            return "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: "
                . ($keepAlive ? 'keep-alive' : 'close') . "\r\n\r\nForbidden";
        }
        if (\str_contains($rawRequest, 'detail=1') || \str_contains($rawRequest, 'detail=true')) {
            $body = \json_encode([
                'status' => 'healthy',
                'engine' => 'event_buffer',
                'instance' => $instanceName,
                'worker_id' => $workerId,
                'port' => $port,
                'connections' => $connectionCount,
                'active_requests' => \max(0, $activeRequests - 1),
                'total_requests' => $requestCount,
                'memory_usage' => \memory_get_usage(true),
                'memory_peak' => \memory_get_peak_usage(true),
                'uptime' => \time() - $startTime,
                'ssl' => true,
                'timestamp' => \time(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $body = \is_string($body) ? $body : '{}';

            return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . \strlen($body)
                . "\r\nConnection: " . ($keepAlive ? 'keep-alive' : 'close') . "\r\n\r\n" . $body;
        }

        return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: " . ($keepAlive ? 'keep-alive' : 'close') . "\r\n\r\nOK";
    }

    $staticResponse = $policyDecision->staticProcessCacheEnabled()
        ? \Weline\Server\Service\WorkerStaticResponseL1::lookup($policyDecision)
        : null;
    if ($staticResponse !== null) {
        return $staticResponse;
    }

    $fpcHit = $policyDecision->fpcCacheEnabled()
        ? $fpcFastPath?->lookup($policyDecision, 'https')
        : null;
    if ($fpcHit !== null) {
        $servedFromFpc = true;
        return wlsDecorateFormattedFpcFastResponseForPerformancePanel(
            (string)$fpcHit['response'],
            $rawRequest,
            (\microtime(true) - $requestStartedAt) * 1000,
            $workerId,
            $port,
            (string)$fpcHit['source'],
        );
    }

    // Keep the EventBuffer transport aligned with stream HTTP/TLS: only the
    // immutable Static L1 lookup precedes FPC. Filesystem fallback runs after
    // an FPC miss so a hot page never performs candidate is_file/filemtime IO.
    $staticResponse = $policyDecision->staticProcessCacheEnabled()
        ? wlsEventHandleStaticFile($requestTarget, $policyDecision)
        : null;
    if ($staticResponse !== null) {
        return $staticResponse;
    }

    if ($runtime === null) {
        return \Weline\Server\Service\Runtime\WorkerRuntimeFailureResponse::create($runtimeError, [
            'instance' => $instanceName,
            'worker_id' => $workerId,
            'port' => $port,
            'transport' => 'https_event_buffer',
        ]);
    }

    wlsEventRequestContextEnter($connectionId);
    try {
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
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }

        if (\is_string($result) && \str_starts_with($result, 'HTTP/')) {
            $result = wlsEventMergeRuntimeCookies($result, $runtime);
            $sni = \Weline\Server\Service\RouteHintService::extractSniFromHeaders($policyDecision->headers);
            $result = \Weline\Server\Service\RouteHintService::addHintToResponse($result, $sni);
            if (\strtoupper($method) === 'HEAD') {
                $headerEnd = \strpos($result, "\r\n\r\n");
                if ($headerEnd !== false) {
                    $result = \substr($result, 0, $headerEnd + 4);
                }
            }

            return $result;
        }

        $result = \is_string($result) ? $result : (string)$result;
        $pendingStatus = $runtime->consumePendingResponseStatus();
        $statusCode = (new \Weline\Server\Service\ResponseStatusResolver())->resolve(
            $result,
            $pendingStatus['status_code'] ?? null,
            (bool)($pendingStatus['explicit'] ?? false)
        );
        $response = \Weline\Framework\Http\Response::fromContent($result, $statusCode);
        foreach ($runtime->consumePendingCookies() as $cookie) {
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
        foreach ($runtime->consumePendingHeaders() as $name => $value) {
            if (\is_string($value)) {
                $response->setHeader($name, $value);
            }
        }
        $sni = \Weline\Server\Service\RouteHintService::extractSniFromHeaders($policyDecision->headers);
        \Weline\Server\Service\RouteHintService::addHintToFrameworkResponse($response, $sni);
        $acceptEncoding = $request->getHeader('Accept-Encoding');
        if (\is_string($acceptEncoding) && $acceptEncoding !== '') {
            $response->compress($acceptEncoding);
        }
        if (\strtoupper($method) === 'HEAD') {
            $httpString = $response->toHttpString($request->isKeepAlive());
            $headerEnd = \strpos($httpString, "\r\n\r\n");
            return $headerEnd === false ? $httpString : \substr($httpString, 0, $headerEnd + 4);
        }

        return $response->toHttpString($request->isKeepAlive());
    } catch (\Throwable $e) {
        \Weline\Server\Log\WlsLogger::error_('EventBuffer SSL request error: ' . $e->getMessage());
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
        $body = \json_encode([
            'error' => true,
            'message' => ((\defined('DEV') && DEV) || (\defined('DEBUG') && DEBUG)) ? $e->getMessage() : 'Internal Server Error',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $body = \is_string($body) ? $body : '{"error":true}';

        return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: "
            . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    } finally {
        wlsEventRequestContextLeave();
    }
}

function wlsEventRequestContextEnter(int $connectionId): void
{
    \Weline\Framework\Runtime\StateManager::reset();
    \Weline\Framework\Runtime\RequestContext::cleanup();
    \Weline\Framework\Http\Url::resetWlsFiberInterleavedParserScratch();
    \Weline\Framework\Http\Sse\SseContext::reset();
    $context = \Weline\Framework\Context::current();
    $context->set('meta.type', 'request');
    $context->set('meta.mode', 'wls');
    $context->set('runtime.connection_id', (string)$connectionId);
    $context->set('runtime.chain_id', (string)$connectionId);
    $context->setRuntimeAttr('connection_id', (string)$connectionId);
    $context->setRuntimeAttr('chain_id', (string)$connectionId);
    \Weline\Framework\Runtime\RequestContext::setConnectionId((string)$connectionId);
}

function wlsEventRequestContextLeave(): void
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

function wlsEventMergeRuntimeCookies(string $response, \Weline\Framework\Runtime\WlsRuntime $runtime): string
{
    $headerEnd = \strpos($response, "\r\n\r\n");
    if ($headerEnd === false) {
        return $response;
    }
    $alreadyHasSetCookie = \stripos(\substr($response, 0, $headerEnd), 'Set-Cookie:') !== false;
    $pendingCookies = $runtime->consumePendingCookies();
    if ($pendingCookies === [] || $alreadyHasSetCookie) {
        return $response;
    }

    $cookieHeaders = '';
    foreach ($pendingCookies as $cookie) {
        $parts = [\urlencode((string)$cookie['name']) . '=' . \urlencode((string)$cookie['value'])];
        if (isset($cookie['expire']) && (int)$cookie['expire'] !== 0) {
            $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', (int)$cookie['expire']);
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

    $headers = \rtrim(\substr($response, 0, $headerEnd), "\r\n") . "\r\n" . \rtrim($cookieHeaders, "\r\n");
    $body = \substr($response, $headerEnd + 4);

    return $headers . "\r\n\r\n" . $body;
}

function wlsEventHandleStaticFile(
    string $requestTarget,
    \Weline\Server\Security\WorkerPolicyDecision $decision,
): ?string {
    if (!\in_array($decision->method, ['GET', 'HEAD'], true)) {
        return null;
    }

    $path = \Weline\Server\Service\WlsStaticUriPathResolver::resolvePath($requestTarget);
    if ($path === null) {
        return wlsEventBadRequestResponse();
    }
    if ($path === '/') {
        return null;
    }
    $relative = \ltrim(\str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $candidates = [
        BP . $relative,
        BP . 'pub' . DIRECTORY_SEPARATOR . $relative,
        BP . 'generated' . DIRECTORY_SEPARATOR . $relative,
    ];
    foreach ($candidates as $candidate) {
        if (!\is_file($candidate) || !\is_readable($candidate)) {
            continue;
        }

        $mtime = @\filemtime($candidate);
        if (!\is_int($mtime)) {
            return null;
        }
        $lastModified = \gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $etag = '"' . \md5($candidate . $mtime) . '"';
        $keepAlive = $decision->keepAlive();
        $connection = $keepAlive ? 'keep-alive' : 'close';
        $ifNoneMatch = \trim((string)($decision->headers['if-none-match'] ?? ''));
        $ifModifiedSince = \trim((string)($decision->headers['if-modified-since'] ?? ''));
        if (($ifNoneMatch !== '' && $ifNoneMatch === $etag)
            || ($ifNoneMatch === '' && $ifModifiedSince !== '' && $ifModifiedSince === $lastModified)
        ) {
            return "HTTP/1.1 304 Not Modified\r\nETag: {$etag}\r\n"
                . "Last-Modified: {$lastModified}\r\nAccept-Ranges: bytes\r\n"
                . "X-WLS-Static-Cache: MISS\r\n"
                . "Connection: {$connection}\r\n\r\n";
        }

        $fileSize = @\filesize($candidate);
        if (!\is_int($fileSize)) {
            return null;
        }
        $range = wlsResolveStaticByteRange(
            (string)($decision->headers['range'] ?? ''),
            (string)($decision->headers['if-range'] ?? ''),
            $fileSize,
            $etag,
            $mtime,
        );
        if ($range['status'] === 'unsatisfiable') {
            return "HTTP/1.1 416 Range Not Satisfiable\r\n"
                . "Content-Range: bytes */{$fileSize}\r\nContent-Length: 0\r\n"
                . "Accept-Ranges: bytes\r\nConnection: {$connection}\r\n\r\n";
        }
        $mime = wlsEventMimeType($candidate);
        if ($range['status'] === 'range') {
            $content = $decision->method === 'HEAD'
                ? ''
                : wlsReadStaticFileSlice($candidate, $range['start'], $range['length']);
            if ($content === false) {
                return null;
            }
            return "HTTP/1.1 206 Partial Content\r\n"
                . "Content-Range: bytes {$range['start']}-{$range['end']}/{$fileSize}\r\n"
                . "Content-Type: {$mime}\r\nContent-Length: {$range['length']}\r\n"
                . "Cache-Control: public, max-age=31536000\r\nETag: {$etag}\r\n"
                . "Last-Modified: {$lastModified}\r\nAccept-Ranges: bytes\r\n"
                . "X-WLS-Static-Cache: DISK\r\nConnection: {$connection}\r\n\r\n"
                . ($decision->method === 'HEAD' ? '' : $content);
        }

        $content = $decision->method === 'HEAD' ? '' : @\file_get_contents($candidate);
        if (!\is_string($content)) {
            return null;
        }
        $response = "HTTP/1.1 200 OK\r\nContent-Type: {$mime}\r\nContent-Length: {$fileSize}"
            . "\r\nCache-Control: public, max-age=31536000\r\nETag: {$etag}\r\n"
            . "Last-Modified: {$lastModified}\r\nAccept-Ranges: bytes\r\n"
            . "X-WLS-Static-Cache: MISS\r\n"
            . "Connection: {$connection}\r\n\r\n" . $content;
        if ($decision->method === 'GET') {
            \Weline\Server\Service\WorkerStaticResponseL1::publish(
                $requestTarget,
                $response,
                $candidate,
                $etag,
                $lastModified,
                \time(),
                31_536_000,
            );
        }

        return $response;
    }

    return null;
}

function wlsEventMimeType(string $path): string
{
    return match (\strtolower((string)\pathinfo($path, PATHINFO_EXTENSION))) {
        'css' => 'text/css; charset=utf-8',
        'js', 'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'html', 'htm' => 'text/html; charset=utf-8',
        default => 'application/octet-stream',
    };
}

function wlsEventProcessExists(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (\PHP_OS_FAMILY === 'Windows') {
        return \class_exists(\Weline\Framework\System\Process\Processer::class)
            ? \Weline\Framework\System\Process\Processer::processExists($pid)
            : false;
    }
    if (\function_exists('posix_kill')) {
        return @\posix_kill($pid, 0);
    }

    return \is_dir('/proc/' . $pid);
}
