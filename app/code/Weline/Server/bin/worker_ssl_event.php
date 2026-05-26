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

if (!\function_exists('wlsEventNormalizeMemoryLimit')) {
    function wlsEventNormalizeMemoryLimit(mixed $value, string $default = '256M'): string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string)(int)$value;
        }
        $value = \strtoupper(\trim((string)$value));
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

if (!\function_exists('wlsEventMakeAbsolutePath')) {
    function wlsEventMakeAbsolutePath(string $path, string $basePath): string
    {
        $path = \trim($path);
        if ($path === '') {
            return '';
        }
        if (\preg_match('/^[a-zA-Z]:[\\\\\\/]|^\//', $path)) {
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
$useReusePort = false;
$wlsLoopDriver = 'auto';
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
$controlPort = 0;
$masterPid = 0;
$workerCount = 1;

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
    } elseif ($arg === '--frontend' || $arg === '-frontend' || $arg === '--win' || $arg === '-win') {
        $isFrontend = true;
    } elseif ($arg === '--reuseport' || $arg === '-reuseport') {
        $useReusePort = true;
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
    } elseif (\str_starts_with($arg, '--ssl-cert=')) {
        $sslCert = \substr($arg, 11);
    } elseif (\str_starts_with($arg, '--ssl-key=')) {
        $sslKey = \substr($arg, 10);
    } elseif (\str_starts_with($arg, '--wls-loop-driver=')) {
        $wlsLoopDriver = (string)\substr($arg, 18);
    } elseif (\str_starts_with($arg, '--memory-limit=')) {
        $wlsMemoryLimit = wlsEventNormalizeMemoryLimit(\substr($arg, 15));
    } elseif (\str_starts_with($arg, '--worker-count=')) {
        $workerCount = \max(1, (int)\substr($arg, 15));
    }
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
$_SERVER['WLS_PORT'] = (string)$port;
$_ENV['WLS_PORT'] = (string)$port;
@\putenv('WLS_PORT=' . (string)$port);
if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

$_wlsEnvFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
$_wlsEnvConfig = \is_file($_wlsEnvFile) ? @include $_wlsEnvFile : [];
$_wlsEnvConfig = \is_array($_wlsEnvConfig) ? $_wlsEnvConfig : [];
if (!\defined('WLS_DEV_MODE')) {
    \define('WLS_DEV_MODE', ($_wlsEnvConfig['deploy'] ?? '') === 'dev');
}

(new \Weline\Server\Service\LongRunningPhpRuntime())->apply();

$processTag = \Weline\Server\Service\WorkerProcessLabel::buildLogTag(true, $isMaintenanceWorker, $workerId, $port, $instanceName);
if (\function_exists('cli_set_process_title')) {
    @\cli_set_process_title(
        \Weline\Server\Service\WorkerProcessLabel::buildProcessTitle(true, $isMaintenanceWorker, $workerId, $port, $instanceName)
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

if ($sslEngine !== 'event_buffer') {
    \Weline\Server\Log\WlsLogger::error_('worker_ssl_event.php requires wls.ssl.engine=event_buffer');
    exit(2);
}
if (!$eventBufferEnabled) {
    \Weline\Server\Log\WlsLogger::error_('EventBuffer SSL worker is disabled; set wls.ssl.event_buffer_enabled=true to run this experimental engine');
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

if ($processName !== '') {
    \Weline\Framework\System\Process\Processer::setPid('--name=' . $processName, \getmypid());
    if ($port > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts('--name=' . $processName, [$port]);
    }
}

\Weline\Server\Service\RouteHintService::init($port, true, 3600);

$runtime = null;
$runtimeError = null;
try {
    $runtime = new \Weline\Framework\Runtime\WlsRuntime();
    $runtime->bootstrap();
    try {
        \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Router\FullPageCacheCoordinator::class);
    } catch (\Throwable $fpcPreloadError) {
        \Weline\Server\Log\WlsLogger::warning_('EventBuffer SSL worker FPC coordinator preload failed: ' . $fpcPreloadError->getMessage());
    }
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
        &$diagnosticLogBudget
    ): void {
        if (\count($connections) >= $maxConnections) {
            wlsEventCloseAcceptedSocket($socket);
            $stats['errors']++;
            return;
        }

        $id = ++$nextConnectionId;
        $acceptedAt = \microtime(true);
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
                $hotPathLogs
            ): void {
                if (!isset($connections[$id])) {
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

                $processedThisCallback = 0;
                while (isset($connections[$id])) {
                    $extracted = wlsEventExtractCompleteRequest((string)$connections[$id]['buffer']);
                    if ($extracted === null) {
                        break;
                    }

                    [$rawRequest, $remaining] = $extracted;
                    $connections[$id]['buffer'] = $remaining;
                    $requestCount++;
                    $activeRequests++;
                    $stats['requests']++;
                    $connections[$id]['requests'] = ((int)($connections[$id]['requests'] ?? 0)) + 1;
                    $handleStart = \microtime(true);

                    if ($ipcDraining) {
                        $body = 'WLS worker is draining';
                        $response = "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: "
                            . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
                    } else {
                        $response = wlsEventHandleRequest(
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
                            $originTokenAllowLocal,
                            $id
                        );
                    }

                    $durationMs = \round((\microtime(true) - $handleStart) * 1000, 2);
                    $response = wlsEventInjectProcessTimeHeader($response, $durationMs);
                    $bev->write($response);

                    $activeRequests = \max(0, $activeRequests - 1);
                    if ($ipcClient !== null && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::telemetry(
                            $instanceName,
                            wlsEventRequestHost($rawRequest),
                            wlsEventResponseStatus($response),
                            (int)$durationMs,
                            \strlen($response)
                        ));
                    }

                    $close = $ipcDraining
                        || !wlsEventIsKeepAlive($rawRequest)
                        || wlsEventResponseRequestsClose($response);
                    if ($close) {
                        $connections[$id]['close_after_write'] = true;
                        $bev->disable(\EventBufferEvent::READING);
                        break;
                    }

                    $processedThisCallback++;
                    if ($processedThisCallback >= 4) {
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

\Weline\Server\Log\WlsLogger::info_("EventBuffer SSL listener ready tcp://{$host}:{$port}");
\Weline\Server\Log\WlsLogger::flush_(true);

$kernel = null;
$ipcClient = null;
$waitingForAck = false;
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
            $workerId,
            $port
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
                    }
                    \Weline\Server\Log\WlsLogger::info_("EventBuffer SSL worker entering drain for {$type}");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_CACHE_CLEAR:
                    if (\function_exists('opcache_reset')) {
                        \opcache_reset();
                    }
                    \clearstatcache(true);
                    wlsEventClearFrameworkCachePools();
                    \Weline\Framework\Manager\ObjectManager::clearInstances();
                    if (\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class)) {
                        \Weline\Framework\Router\FullPageCacheCoordinator::clearProcessCache();
                    }
                    \Weline\Server\Log\WlsLogger::info_('EventBuffer SSL worker cache cleared');
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
    if ($kernel->connectAndRegister($controlPort)) {
        $ipcClient = $kernel->getClient();
        $waitingForAck = !($ipcClient?->isReadyStateConfirmed() ?? false);
        $readySentTime = \microtime(true);
        \Weline\Server\Log\WlsLogger::info_("EventBuffer SSL worker registered with Master control port {$controlPort}");
    } else {
        \Weline\Server\Log\WlsLogger::error_("EventBuffer SSL worker failed to register with Master control port {$controlPort}");
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
    $base,
    $masterPid,
    &$ipcReceivedShutdown
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

    static $lastMasterProcessCheck = 0.0;
    $now = \microtime(true);
    $ipcConnected = $ipcClient !== null && $ipcClient->isConnected();
    if ($masterPid > 0
        && !$ipcReceivedShutdown
        && !$ipcConnected
        && ($now - $lastMasterProcessCheck) >= 2.0) {
        $lastMasterProcessCheck = $now;
        if (!wlsEventProcessExists($masterPid)) {
            \Weline\Server\Log\WlsLogger::warning_("EventBuffer SSL worker detected dead Master PID {$masterPid}; exiting");
            $shouldExit = true;
            $ipcDraining = true;
            $drainStartTime = $now;
        }
    }

    if (!$shouldExit) {
        return;
    }

    if ($connections !== [] && (\microtime(true) - $drainStartTime) < $maxDrainTime) {
        return;
    }

    foreach (\array_keys($connections) as $id) {
        wlsEventCloseConnection((int)$id, $connections, $stats);
    }
    if ($kernel !== null) {
        $kernel->sendDrainingComplete();
        $kernel->sendExited();
        $kernel->flushWrites();
        $kernel->close();
    }
    \Weline\Server\Log\WlsLogger::flush_(true);
    $base->exit();
});
$tickTimer->add(0.05);

\Weline\Server\Log\WlsLogger::info_('EventBuffer SSL worker entering event loop');
\Weline\Server\Log\WlsLogger::flush_(true);
$base->loop();
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

    return $context;
}

function wlsEventClearFrameworkCachePools(): void
{
    try {
        if (\class_exists(\Weline\Framework\Cache\Adapter\WlsMemoryAdapter::class)) {
            \Weline\Framework\Cache\Adapter\WlsMemoryAdapter::clearAllMemory();
        }
        if (!\class_exists(\Weline\Framework\Manager\ObjectManager::class)
            || !\class_exists(\Weline\Framework\Cache\CacheManager::class)) {
            return;
        }
        $cacheManager = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Cache\CacheManager::class);
        foreach (['router', 'fpc', 'hook', 'view', 'phrase', 'i18n', 'config', 'module_router', 'theme', 'url_rewrite', 'website', 'controller', 'taglib', 'system_config'] as $pool) {
            if (\method_exists($cacheManager, 'hasPool') && !$cacheManager->hasPool($pool)) {
                continue;
            }
            $cacheManager->pool($pool)->clear();
        }
    } catch (\Throwable $throwable) {
        \Weline\Server\Log\WlsLogger::warning_('[EventBufferSSL] cache pool clear failed: ' . $throwable->getMessage());
    }
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
 * @return array{0:string,1:string}|null
 */
function wlsEventExtractCompleteRequest(string $buffer): ?array
{
    $headerEnd = \strpos($buffer, "\r\n\r\n");
    if ($headerEnd === false) {
        return null;
    }
    $totalLength = $headerEnd + 4;
    $headers = \substr($buffer, 0, $headerEnd);
    if (\preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $m)) {
        $totalLength += (int)$m[1];
    }
    if (\strlen($buffer) < $totalLength) {
        return null;
    }

    return [\substr($buffer, 0, $totalLength), \substr($buffer, $totalLength)];
}

function wlsEventHeader(string $rawRequest, string $headerName): ?string
{
    $pattern = '/^' . \preg_quote($headerName, '/') . ':\s*([^\r\n]+)/im';
    if (!\preg_match($pattern, $rawRequest, $m)) {
        return null;
    }
    $value = \trim((string)$m[1]);

    return $value === '' ? null : $value;
}

function wlsEventIsKeepAlive(string $rawRequest): bool
{
    if (\preg_match('/^Connection:\s*([^\r\n]+)/im', $rawRequest, $m)) {
        $connection = \strtolower(\trim((string)$m[1]));
        if ($connection === 'close') {
            return false;
        }
        if ($connection === 'keep-alive') {
            return true;
        }
    }

    return \strpos($rawRequest, 'HTTP/1.1') !== false;
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

function wlsEventHandleRequest(
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
    int $connectionId
): string {
    $method = 'GET';
    $uri = '/';
    if (\preg_match('/^([A-Z]+)\s+([^\s]+)\s+HTTP\/\d(?:\.\d)?/i', $rawRequest, $m)) {
        $method = \strtoupper((string)$m[1]);
        $path = \parse_url((string)$m[2], PHP_URL_PATH);
        $uri = \is_string($path) && $path !== '' ? $path : '/';
    }

    if ($uri === '/_wls/health') {
        $keepAlive = wlsEventIsKeepAlive($rawRequest);
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

    if ($originTokenValidationEnabled && $originToken !== '') {
        $clientIp = (string)(wlsEventHeader($rawRequest, 'X-Real-IP') ?? '127.0.0.1');
        $isLocal = \in_array($clientIp, ['127.0.0.1', '::1', 'localhost'], true);
        if (!$originTokenAllowLocal || !$isLocal) {
            $receivedToken = (string)(wlsEventHeader($rawRequest, $originTokenHeader) ?? '');
            if (!\hash_equals($originToken, $receivedToken)) {
                $body = '{"error":true,"message":"Origin token validation failed"}';

                return "HTTP/1.1 403 Forbidden\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: "
                    . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
            }
        }
    }

    $staticResponse = wlsEventHandleStaticFile($uri, $rawRequest);
    if ($staticResponse !== null) {
        return $staticResponse;
    }

    $fastPathResponse = wlsEventTryServeFormattedFpcFastResponse($rawRequest, wlsEventIsKeepAlive($rawRequest));
    if ($fastPathResponse !== null) {
        return $fastPathResponse;
    }

    if ($runtime === null) {
        $body = \json_encode([
            'error' => true,
            'message' => 'Runtime initialization failed',
            'detail' => $runtimeError,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $body = \is_string($body) ? $body : '{"error":true}';

        return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: "
            . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    }

    wlsEventRequestContextEnter($connectionId);
    try {
        $request = \Weline\Framework\Http\WlsRequest::fromRaw($rawRequest, [
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
            $sni = \Weline\Server\Service\RouteHintService::extractSniFromRawRequest($rawRequest);
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
        $sni = \Weline\Server\Service\RouteHintService::extractSniFromRawRequest($rawRequest);
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

function wlsEventTryServeFormattedFpcFastResponse(string $rawRequest, bool $keepAlive): ?string
{
    if (!\preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d(?:\.\d)?/i', $rawRequest, $m)) {
        return null;
    }
    $method = \strtoupper((string)$m[1]);
    if ($method !== 'GET' && $method !== 'HEAD') {
        return null;
    }
    $target = (string)$m[2];
    $host = \trim((string)(wlsEventHeader($rawRequest, 'Host') ?? ''));
    if ($host === '') {
        return null;
    }
    $targetParts = \parse_url($target);
    if (\is_array($targetParts) && !empty($targetParts['scheme']) && !empty($targetParts['host'])) {
        $fullUri = $target;
    } else {
        $requestUri = $target === '' ? '/' : $target;
        if (!\str_starts_with($requestUri, '/')) {
            $requestUri = '/' . $requestUri;
        }
        $fullUri = 'https://' . $host . $requestUri;
    }

    try {
        $coordinator = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Router\FullPageCacheCoordinator::class
        );
        if (!$coordinator instanceof \Weline\Framework\Router\FullPageCacheCoordinator) {
            return null;
        }
        $cached = $coordinator->getFormattedCachedResponseForFullUri(
            $fullUri,
            $method,
            (string)(wlsEventHeader($rawRequest, 'Accept') ?? ''),
            (string)(wlsEventHeader($rawRequest, 'Accept-Encoding') ?? ''),
            (string)(wlsEventHeader($rawRequest, 'Cookie') ?? ''),
            $keepAlive
        );

        return \is_array($cached) ? ((string)($cached['response'] ?? '') ?: null) : null;
    } catch (\Throwable) {
        return null;
    }
}

function wlsEventHandleStaticFile(string $uri, string $rawRequest): ?string
{
    if ($uri === '/' || $uri === '') {
        return null;
    }
    $path = \rawurldecode((string)\parse_url($uri, PHP_URL_PATH));
    if ($path === '' || \str_contains($path, "\0") || \str_contains($path, '..')) {
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
        $content = @\file_get_contents($candidate);
        if (!\is_string($content)) {
            return null;
        }
        $mime = wlsEventMimeType($candidate);
        $keepAlive = wlsEventIsKeepAlive($rawRequest);

        return "HTTP/1.1 200 OK\r\nContent-Type: {$mime}\r\nContent-Length: " . \strlen($content)
            . "\r\nCache-Control: public, max-age=31536000\r\nConnection: " . ($keepAlive ? 'keep-alive' : 'close')
            . "\r\n\r\n" . $content;
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
