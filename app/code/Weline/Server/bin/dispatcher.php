<?php
declare(strict_types=1);

/**
 * Weline Server - 统一 Dispatcher
 *
 * 将入口字节流转发给 Worker，实现「单入口 + 多 Worker 负载均衡」。
 * Nginx 模式接收 loopback HTTP/1.1 回源；纯 WLS 模式透传公网
 * TLS/HTTP，使 Worker 直接完成 TLS 1.3、ALPN HTTP/2/HTTP/1.1 协商。
 *
 * 用法: php dispatcher.php <host> <port> <worker_base_port> <worker_count> <instance_name> [--name=process_name] [--frontend]
 *
 * 架构:
 *   Nginx → Dispatcher:loopback (H1) → Worker:loopback
 *   Client → Dispatcher:public (TLS/H2/H1 passthrough) → SSL Worker:loopback
 *
 * @author Aiweline
 * @email aiweline@qq.com
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

$wlsMemoryLimit = '256M';
@\ini_set('memory_limit', $wlsMemoryLimit);

// ========== 参数解析 ==========
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 443);
// 注意：workerBasePort 应该由 Master 传入，这里的默认值仅作兜底
// 实际端口由 Master 通过 IPC 动态通知，不依赖此默认值
$workerBasePort = (int) ($argv[3] ?? 10000);
$workerCount = (int) ($argv[4] ?? 2);
$instanceName = $argv[5] ?? 'default';

if ($workerCount <= 0) {
    \fwrite(STDERR, "[Dispatcher] Worker count must be > 0\n");
    exit(1);
}

// 解析 --name、--frontend、--control-port 参数
$processName = '';
$isFrontend = false;
$controlPort = 0;  // 0 means use Master endpoint bootstrap if no argument is passed.
$masterPid = 0;
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
$masterLeaseFile = '';
$masterToken = '';
$protocolEdgeTokenFile = '';
$edgeAdapterName = 'nginx';
$listenFd = 0;
$gatewayHostLeaseId = '';
$slotId = '';
$slotLeaseId = '';
$slotGeneration = 0;
$windowsListenerHandoffPath = '';
$windowsListenerHandoffId = '';
$windowsListenerIntentDigest = '';
$windowsListenerLeaseInstance = '';
$windowsListenerWlsInstance = '';
$windowsListenerMasterLaunchId = '';
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend' || $arg === '-frontend' || $arg === '--win' || $arg === '-win') {
        $isFrontend = true;
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = (string)\substr($arg, 12);
    } elseif (\str_starts_with($arg, '--master-lease-file=')) {
        $masterLeaseFile = (string)\substr($arg, 20);
    } elseif (\str_starts_with($arg, '--master-token=')) {
        \fwrite(\STDERR, "[Dispatcher] --master-token is forbidden; use the protected child ledger.\n");
        exit(1);
    } elseif (\str_starts_with($arg, '--memory-limit=')) {
        $wlsMemoryLimit = wlsNormalizeMemoryLimit(\substr($arg, 15));
    } elseif (\str_starts_with($arg, '--protocol-edge-token-file=')) {
        $protocolEdgeTokenFile = (string)\substr($arg, 27);
    } elseif (\str_starts_with($arg, '--edge-adapter=')) {
        $edgeAdapterName = \strtolower(\trim((string)\substr($arg, 15)));
    } elseif (\str_starts_with($arg, '--listen-fd=')) {
        $listenFd = (int)\substr($arg, 12);
    } elseif (\str_starts_with($arg, '--gateway-host-lease-id=')) {
        $gatewayHostLeaseId = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--gateway-host-lease-id='),
        )));
    } elseif (\str_starts_with($arg, '--slot-id=')) {
        $slotId = \trim((string)\substr($arg, 10));
    } elseif (\str_starts_with($arg, '--lease-id=')) {
        $slotLeaseId = \strtolower(\trim((string)\substr($arg, 11)));
    } elseif (\str_starts_with($arg, '--slot-generation=')) {
        $slotGeneration = (int)\substr($arg, 18);
    } elseif (\str_starts_with($arg, '--windows-listener-handoff=')) {
        $windowsListenerHandoffPath = (string)\substr(
            $arg,
            \strlen('--windows-listener-handoff='),
        );
    } elseif (\str_starts_with($arg, '--windows-listener-handoff-id=')) {
        $windowsListenerHandoffId = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--windows-listener-handoff-id='),
        )));
    } elseif (\str_starts_with($arg, '--windows-listener-intent-digest=')) {
        $windowsListenerIntentDigest = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--windows-listener-intent-digest='),
        )));
    } elseif (\str_starts_with($arg, '--windows-listener-lease-instance=')) {
        $windowsListenerLeaseInstance = \trim((string)\substr(
            $arg,
            \strlen('--windows-listener-lease-instance='),
        ));
    } elseif (\str_starts_with($arg, '--windows-listener-wls-instance=')) {
        $windowsListenerWlsInstance = \trim((string)\substr(
            $arg,
            \strlen('--windows-listener-wls-instance='),
        ));
    } elseif (\str_starts_with($arg, '--windows-listener-master-launch-id=')) {
        $windowsListenerMasterLaunchId = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--windows-listener-master-launch-id='),
        )));
    }
}
@\ini_set('memory_limit', $wlsMemoryLimit);
if (!\in_array($edgeAdapterName, ['nginx', 'wls'], true)) {
    \fwrite(\STDERR, "[Dispatcher] --edge-adapter must be nginx or wls.\n");
    exit(1);
}
if ($listenFd !== 0
    && ($listenFd !== 3 || \PHP_OS_FAMILY === 'Windows')
) {
    \fwrite(\STDERR, "[Dispatcher] inherited listener requires POSIX FD 3.\n");
    exit(1);
}
if (($listenFd !== 0 || $gatewayHostLeaseId !== '')
    && \preg_match('/\A[a-f0-9]{32}\z/D', $gatewayHostLeaseId) !== 1
) {
    \fwrite(\STDERR, "[Dispatcher] inherited listener host lease identity is invalid.\n");
    exit(1);
}
$windowsHandoffValues = [
    $windowsListenerHandoffPath,
    $windowsListenerHandoffId,
    $windowsListenerIntentDigest,
    $windowsListenerLeaseInstance,
    $windowsListenerWlsInstance,
    $windowsListenerMasterLaunchId,
];
$windowsHandoffPresent = \count(\array_filter(
    $windowsHandoffValues,
    static fn (string $value): bool => $value !== '',
)) > 0;
if ($windowsHandoffPresent
    && (\PHP_OS_FAMILY !== 'Windows'
        || \in_array('', $windowsHandoffValues, true)
        || $listenFd !== 0
        || !\hash_equals($windowsListenerWlsInstance, (string)$instanceName)
        || \preg_match('/\A[a-f0-9]{32}\z/D', $windowsListenerHandoffId) !== 1
        || \preg_match('/\A[a-f0-9]{64}\z/D', $windowsListenerIntentDigest) !== 1
        || \preg_match('/\A[a-f0-9]{32}\z/D', $windowsListenerMasterLaunchId) !== 1
        || \preg_match('/\A[a-f0-9]{32}\z/D', $gatewayHostLeaseId) !== 1
        || \preg_match('/\A[a-f0-9]{32}\z/D', $orchestratorLaunchId) !== 1
        || \preg_match('/\A[a-f0-9]{32}\z/D', $slotLeaseId) !== 1
        || \preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $windowsListenerLeaseInstance) !== 1
        || \preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $windowsListenerWlsInstance) !== 1
        || \preg_match('/\Adispatcher#[1-9][0-9]*\z/D', $slotId) !== 1
        || $slotGeneration <= 0
        || !\hash_equals($orchestratorLaunchId, $slotLeaseId))
) {
    \fwrite(\STDERR, "[Dispatcher] Windows listener handoff identity is incomplete or inconsistent.\n");
    exit(1);
}
if (\PHP_OS_FAMILY === 'Windows'
    && $gatewayHostLeaseId !== ''
    && !$windowsHandoffPresent
) {
    \fwrite(\STDERR, "[Dispatcher] Windows host lease requires target-bound listener adoption.\n");
    exit(1);
}
if ($gatewayHostLeaseId !== '') {
    $_SERVER['WLS_GATEWAY_HOST_LEASE_ID'] = $gatewayHostLeaseId;
    $_ENV['WLS_GATEWAY_HOST_LEASE_ID'] = $gatewayHostLeaseId;
    @\putenv('WLS_GATEWAY_HOST_LEASE_ID=' . $gatewayHostLeaseId);
}
$normalizedBindHost = \strtolower(\trim((string)$host));
if ($edgeAdapterName === 'nginx' && $normalizedBindHost !== '127.0.0.1') {
    \fwrite(\STDERR, "[Dispatcher] Nginx-only backend must bind to 127.0.0.1.\n");
    exit(1);
}
if ($controlPort <= 0
    || $masterPid <= 0
    || $orchestratorEpoch <= 0
    || \trim($orchestratorLaunchId) === ''
    || \trim($masterLeaseFile) === ''
) {
    \fwrite(\STDERR, "[Dispatcher] authenticated Master identity is required.\n");
    exit(1);
}

// ========== 初始化 ==========
$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}
require_once __DIR__ . DS . 'windows_start_process_working_directory.php';

// 先完成自动加载；控制面解析与框架 bootstrap 可能较慢，主端口须尽快 listen，否则客户端会得到 ERR_CONNECTION_REFUSED（无法进入 503 启动页）。
require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

$masterLeaseManager = new \Weline\Server\Service\MasterLeaseManager();
$masterToken = $masterLeaseManager->resolveProtectedCredentialFromArguments(
        $argv,
        $instanceName,
        $masterPid,
        $orchestratorEpoch,
    );
$masterRuntimeCredential = $masterLeaseManager->resolveProtectedRuntimeCredentialFromArguments(
    $argv,
    $instanceName,
    $masterPid,
    $orchestratorEpoch,
);

$childMasterGuard = new \Weline\Server\IPC\ChildControl\ChildMasterGuard(
    $masterPid,
    $masterLeaseFile,
    $masterToken,
    'Dispatcher:' . $port,
    $instanceName,
    $orchestratorEpoch
);
$childMasterGuard->assertAliveOrExit('Dispatcher listen 前 Master 自治检查');

// ========== 主端口尽早 listen（先于控制面解析）==========
if (!\function_exists('wlsDispatcherIsIpBindAddress')) {
    function wlsDispatcherIsIpBindAddress(string $host): bool
    {
        $host = \trim($host);
        if ($host === '' || $host === '0.0.0.0' || $host === '::' || $host === '*') {
            return true;
        }

        return \filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}

if (!\function_exists('wlsDispatcherNormalizeLiteralBindHost')) {
    function wlsDispatcherNormalizeLiteralBindHost(string $host): string
    {
        $host = \trim($host, " \t\n\r\0\x0B[]");
        if ($host === '' || $host === '*') {
            return '0.0.0.0';
        }
        if (\strcasecmp($host, 'localhost') === 0) {
            return '127.0.0.1';
        }
        $packed = @\inet_pton($host);
        $normalized = \is_string($packed) ? @\inet_ntop($packed) : false;
        if (!\is_string($normalized) || $normalized === '') {
            throw new \InvalidArgumentException(
                'Dispatcher inherited listener requires a literal IPv4/IPv6 address.'
            );
        }
        return \strtolower($normalized);
    }
}

if (!\function_exists('wlsDispatcherBindListenSocket')) {
    /**
     * @param \Socket|resource $socket
     * @return array{success:bool,host:string,error_code:int,error_msg:string,fallback_used:bool}
     */
    function wlsDispatcherBindListenSocket($socket, string $host, int $port): array
    {
        $host = \trim($host);
        if ($host === '' || $host === '*') {
            $host = '0.0.0.0';
        }

        if (@\socket_bind($socket, $host, $port)) {
            return [
                'success' => true,
                'host' => $host,
                'error_code' => 0,
                'error_msg' => '',
                'fallback_used' => false,
            ];
        }

        $errorCode = \socket_last_error($socket);
        $errorMsg = \socket_strerror($errorCode);
        if (wlsDispatcherIsIpBindAddress($host)) {
            return [
                'success' => false,
                'host' => $host,
                'error_code' => $errorCode,
                'error_msg' => $errorMsg,
                'fallback_used' => false,
            ];
        }

        $fallbackHost = '127.0.0.1';
        if (@\socket_bind($socket, $fallbackHost, $port)) {
            return [
                'success' => true,
                'host' => $fallbackHost,
                'error_code' => $errorCode,
                'error_msg' => $errorMsg,
                'fallback_used' => true,
            ];
        }

        $fallbackErrorCode = \socket_last_error($socket);
        return [
            'success' => false,
            'host' => $host,
            'error_code' => $fallbackErrorCode,
            'error_msg' => $errorMsg . '; fallback 127.0.0.1 failed: (' . $fallbackErrorCode . ') ' . \socket_strerror($fallbackErrorCode),
            'fallback_used' => false,
        ];
    }
}

$requestedHost = (string)$host;
$inheritedListenerStream = null;
$windowsListenerProof = [];
$listenerWasInherited = $listenFd !== 0 || $windowsHandoffPresent;
if ($windowsHandoffPresent) {
    try {
        $importedListener = \Weline\Server\Service\Runtime\WindowsListenerHandoff::awaitChildSocket(
            $windowsListenerHandoffPath,
            [
                'handoff_id' => $windowsListenerHandoffId,
                'intent_digest' => $windowsListenerIntentDigest,
                'lease_id' => $gatewayHostLeaseId,
                'lease_instance' => $windowsListenerLeaseInstance,
                'wls_instance' => $windowsListenerWlsInstance,
                'bind_host' => wlsDispatcherNormalizeLiteralBindHost($requestedHost),
                'port' => $port,
                'master_launch_id' => $windowsListenerMasterLaunchId,
                'launch_id' => $orchestratorLaunchId,
                'slot_id' => $slotId,
                'generation' => $slotGeneration,
            ],
        );
        $socket = $importedListener['socket'];
        $windowsListenerProof = $importedListener['proof'];
        $host = (string)($windowsListenerProof['host'] ?? '');
    } catch (\Throwable $throwable) {
        \fwrite(\STDERR, '[Dispatcher] Windows listener adoption failed: '
            . $throwable->getMessage() . "\n");
        exit(1);
    }
} elseif ($listenerWasInherited) {
    $inheritedListenerStream = @\fopen('php://fd/' . $listenFd, 'r+');
    $socket = \is_resource($inheritedListenerStream)
        && \function_exists('socket_import_stream')
            ? @\socket_import_stream($inheritedListenerStream)
            : false;
    $actualHost = '';
    $actualPort = 0;
    $accepting = $socket !== false && \defined('SO_ACCEPTCONN')
        ? @\socket_get_option($socket, \SOL_SOCKET, \SO_ACCEPTCONN)
        : 1;
    if ($socket === false
        || !@\socket_getsockname($socket, $actualHost, $actualPort)
        || $accepting !== 1
    ) {
        if (\is_resource($inheritedListenerStream)) {
            @\fclose($inheritedListenerStream);
        }
        \fwrite(\STDERR, "[Dispatcher] inherited FD 3 is not a listening TCP socket.\n");
        exit(1);
    }
    try {
        $expectedHost = wlsDispatcherNormalizeLiteralBindHost($requestedHost);
        $actualHost = wlsDispatcherNormalizeLiteralBindHost((string)$actualHost);
    } catch (\Throwable $throwable) {
        @\fclose($inheritedListenerStream);
        \fwrite(\STDERR, '[Dispatcher] inherited listener address is invalid: '
            . $throwable->getMessage() . "\n");
        exit(1);
    }
    if ($actualHost !== $expectedHost || (int)$actualPort !== $port) {
        @\fclose($inheritedListenerStream);
        \fwrite(\STDERR, "[Dispatcher] inherited listener endpoint mismatch.\n");
        exit(1);
    }
    $host = $actualHost;
} else {
    $socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) {
        $errorCode = \socket_last_error();
        $errorMsg = \socket_strerror($errorCode);
        \fwrite(STDERR, "[Dispatcher] socket_create failed: ({$errorCode}) {$errorMsg}\n");
        exit(1);
    }
    $reuseResult = \Weline\Server\Socket\ListenSocketOptions::applyRawListenSocketReuseOption($socket);
    if (!$reuseResult['success']) {
        \fwrite(STDERR, "[Dispatcher] socket_set_option {$reuseResult['label']} failed: ({$reuseResult['errno']}) {$reuseResult['error']}\n");
        \socket_close($socket);
        exit(1);
    }
    $bindResult = wlsDispatcherBindListenSocket($socket, (string)$host, $port);
    if (!$bindResult['success']) {
        $errorCode = (int)$bindResult['error_code'];
        $errorMsg = (string)$bindResult['error_msg'];
        \fwrite(STDERR, "[Dispatcher] socket_bind failed on {$host}:{$port}: ({$errorCode}) {$errorMsg}\n");
        \socket_close($socket);
        exit(1);
    }
    $host = (string)$bindResult['host'];
    if ($bindResult['fallback_used']) {
        \fwrite(STDERR, "[Dispatcher] socket_bind fallback: {$requestedHost}:{$port} failed, listening on {$host}:{$port}\n");
    }
    if (@\socket_listen($socket, 1024) === false) {
        $errorCode = \socket_last_error($socket);
        $errorMsg = \socket_strerror($errorCode);
        \fwrite(STDERR, "[Dispatcher] socket_listen failed: ({$errorCode}) {$errorMsg}\n");
        \socket_close($socket);
        exit(1);
    }
}
if (!@\socket_set_nonblock($socket)) {
    \fwrite(\STDERR, "[Dispatcher] listener could not enter non-blocking mode.\n");
    if (\is_resource($inheritedListenerStream)) {
        @\fclose($inheritedListenerStream);
    } else {
        \socket_close($socket);
    }
    exit(1);
}
\Weline\Server\Service\Policy\DispatcherPolicyControl::setListenerReadiness(
    (string)$host,
    $port,
    $listenerWasInherited,
);
if ($windowsListenerProof !== []) {
    \Weline\Server\Service\Policy\DispatcherPolicyControl::setWindowsListenerHandoffProof(
        $windowsListenerProof,
    );
}

\Weline\Server\Log\LogConfig::bootstrapVerboseFromInstanceFile($instanceName);

// IPC control port. Prefer the explicit Master-provided argument; the endpoint
// file is only a bootstrap pointer when the argument is absent.
if ($controlPort <= 0) {
    $controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, 0, 30);
}
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);

// 定义前端模式常量
if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

// 预读 env.php 判断开发模式
$_wlsEnvFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
$_wlsEnvConfig = \is_file($_wlsEnvFile) ? @include $_wlsEnvFile : [];
$_wlsSystemConfig = \is_array($_wlsEnvConfig['system'] ?? null) ? $_wlsEnvConfig['system'] : [];
$_wlsDevMode = (($_wlsSystemConfig['deploy'] ?? $_wlsEnvConfig['deploy'] ?? '') === 'dev');
if (!\defined('WLS_DEV_MODE')) {
    \define('WLS_DEV_MODE', $_wlsDevMode);
}
unset($_wlsSystemConfig, $_wlsDevMode);
$_SERVER['WLS_PROCESS_ROLE'] = 'dispatcher';
$_ENV['WLS_PROCESS_ROLE'] = 'dispatcher';
@\putenv('WLS_PROCESS_ROLE=dispatcher');

(new \Weline\Server\Service\LongRunningPhpRuntime())->apply();

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;

$processTag = 'Dispatcher:' . $port . '@' . $instanceName;

ErrorBootstrap::init($processTag, [
    'port' => $port,
    'worker_base_port' => $workerBasePort,
    'worker_count' => $workerCount,
    'instance' => $instanceName,
    'process_name' => $processName,
]);

WlsLogger::getInstance()
    ->setStdoutEnabled(\Weline\Server\Log\LogConfig::isStdoutEnabled($isFrontend, \Weline\Server\Log\LogConfig::isDevMode()))
    ->setProcessTag($processTag);

if ($processName) {
    \Weline\Server\Service\WlsLogService::prepareProcessLogFile($processName, $instanceName, $processTag);
    // Publish generation fences with the managed lease so Master public-port
    // confirmTransferred can match launch_id (name-only setPid left records
    // without launch_id and blocked Dispatcher READY on Linux).
    $managedIdentity = '--name=' . $processName;
    if (\preg_match('/\A[a-f0-9]{32}\z/D', $orchestratorLaunchId) === 1) {
        $managedIdentity .= ' --launch-id=' . $orchestratorLaunchId;
    }
    if ($orchestratorEpoch > 0) {
        $managedIdentity .= ' --epoch=' . $orchestratorEpoch;
    }
    \Weline\Framework\System\Process\Processer::setPid($managedIdentity, \getmypid());
    if ($port > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts('--name=' . $processName, [$port]);
    }
}

// Daemon 下向已关闭连接写数据会触发 SIGPIPE 导致进程退出，与 Nginx 一致忽略 SIGPIPE
if (\function_exists('pcntl_signal') && \defined('SIGPIPE')) {
    \pcntl_signal(SIGPIPE, SIG_IGN);
}

$dispatcher = new \Weline\Server\Dispatcher\Dispatcher(
    $socket,
    '127.0.0.1',
    $workerBasePort,
    $workerCount,
    $instanceName,
    $processName,
    $port
);
$dispatcher->setLifecycleTokens($orchestratorEpoch, $orchestratorLaunchId);
$dispatcher->setHostLeaseId($gatewayHostLeaseId);
$dispatcher->setHelloAuthSecret($masterToken);
if ($masterPid > 0) {
    $dispatcher->setMasterPid($masterPid);
}
$dispatcher->setMasterGuard($childMasterGuard);
if ($controlPort > 0 || $supervisorEnabled) {
    $dispatcher->connectIpc($controlPort, false);
}

// 读取 env 配置
$envConfig = $_wlsEnvConfig;
unset($_wlsEnvFile, $_wlsEnvConfig);
$dispatcherAlreadyBootstrapped = isset($dispatcher);
if (!$dispatcherAlreadyBootstrapped) {

// ========== 启动 Dispatcher（主端口已在上方 listen）==========
$dispatcher = new \Weline\Server\Dispatcher\Dispatcher(
    $socket,
    '127.0.0.1', // Worker 主机地址（内网）
    $workerBasePort,
    $workerCount,
    $instanceName,
    $processName,
    $port
);

// 配置
}
$wlsConfig = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
$startupProtectionConfig = \is_array($wlsConfig['startup_protection'] ?? null) ? $wlsConfig['startup_protection'] : [];
$dispatcherConfig = \is_array($wlsConfig['dispatcher'] ?? null) ? $wlsConfig['dispatcher'] : [];
$protocolEdgeToken = '';
if ($protocolEdgeTokenFile !== '') {
    if (!\is_file($protocolEdgeTokenFile) || !\is_readable($protocolEdgeTokenFile)) {
        throw new \RuntimeException('Dispatcher protocol-edge token file is not readable.');
    }
    $protocolEdgeToken = \strtolower(\trim((string)@\file_get_contents($protocolEdgeTokenFile)));
    if (\preg_match('/^[a-f0-9]{64}$/D', $protocolEdgeToken) !== 1) {
        throw new \RuntimeException('Dispatcher protocol-edge token is invalid.');
    }
}
$warmupHosts = [];
$addWarmupHost = static function (string $candidate) use (&$warmupHosts, $port): void {
    $candidate = \trim($candidate);
    if ($candidate === '' || $candidate === '0.0.0.0' || $candidate === '::') {
        return;
    }
    if (\str_contains($candidate, '://')) {
        $parsedHost = \parse_url($candidate, PHP_URL_HOST);
        $candidate = \is_string($parsedHost) ? $parsedHost : '';
    }
    $candidate = \preg_replace('/[\/\s]+.*$/', '', $candidate) ?? '';
    $candidate = \trim($candidate, '[] ');
    if ($candidate === '') {
        return;
    }
    $hostHeader = \in_array($port, [80, 443], true) || \str_contains($candidate, ':')
        ? $candidate
        : $candidate . ':' . $port;
    $warmupHosts[$hostHeader] = $hostHeader;
};
$configuredWarmupHosts = $dispatcherConfig['homepage_warmup_hosts']
    ?? $wlsConfig['homepage_warmup_hosts']
    ?? [];
if (\is_string($configuredWarmupHosts)) {
    $decodedWarmupHosts = \json_decode($configuredWarmupHosts, true);
    $configuredWarmupHosts = \is_array($decodedWarmupHosts)
        ? $decodedWarmupHosts
        : (\preg_split('/[,\s]+/', $configuredWarmupHosts, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
}
if (\is_array($configuredWarmupHosts)) {
    foreach ($configuredWarmupHosts as $configuredWarmupHost) {
        if (\is_scalar($configuredWarmupHost)) {
            $addWarmupHost((string)$configuredWarmupHost);
        }
    }
}
foreach ([
    $wlsConfig['host'] ?? null,
    $envConfig['server']['host'] ?? null,
    $requestedHost ?? null,
    $host ?? null,
] as $detectedWarmupHost) {
    if (\is_scalar($detectedWarmupHost)) {
        $addWarmupHost((string)$detectedWarmupHost);
    }
}
$includeLoopbackWarmupHosts = (bool)(
    $dispatcherConfig['include_loopback_homepage_warmup_hosts']
    ?? $wlsConfig['include_loopback_homepage_warmup_hosts']
    ?? true
);
if ($includeLoopbackWarmupHosts || $warmupHosts === []) {
    $addWarmupHost('127.0.0.1');
}
if ($warmupHosts === []) {
    $addWarmupHost('localhost');
}
if (!\function_exists('wlsDispatcherNormalizeWarmupPaths')) {
    /**
     * @return list<string>
     */
    function wlsDispatcherNormalizeWarmupPaths(mixed $paths): array
    {
        if (\is_string($paths)) {
            $decoded = \json_decode($paths, true);
            $paths = \is_array($decoded) ? $decoded : (\preg_split('/[,\s]+/', $paths) ?: []);
        }
        if (!\is_array($paths)) {
            return [];
        }

        $normalized = [];
        foreach ($paths as $path) {
            if (!\is_scalar($path)) {
                continue;
            }
            $path = \str_replace(["\r", "\n", "\t"], '', \trim((string)$path));
            if ($path === '') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            $normalized[$path] = $path;
        }

        return \array_values($normalized);
    }
}
if (!\function_exists('wlsDispatcherApplyWarmupPathObservers')) {
    /**
     * @param list<string> $paths
     * @param list<string> $hosts
     * @return list<string>
     */
    function wlsDispatcherApplyWarmupPathObservers(array $paths, string $instanceName, int $port, array $hosts): array
    {
        $eventName = 'Weline_Server::dispatcher::warmup_paths';

        try {
            $eventsManager = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $data = new \Weline\Framework\DataObject\DataObject([
                'paths' => $paths,
                'instance_name' => $instanceName,
                'port' => $port,
                'hosts' => $hosts,
            ]);

            $events = $eventsManager->scanEvents();
            $observers = \is_array($events[$eventName] ?? null) ? $events[$eventName] : [];
            if ($observers === []) {
                $eventsManager->dispatch($eventName, $data);
            } else {
                $event = new \Weline\Framework\Event\Event([
                    'data' => &$data,
                    'observers' => $observers,
                ]);
                $event->setName($eventName)->dispatch();
            }

            $eventPaths = wlsDispatcherNormalizeWarmupPaths($data->getData('paths'));
            return $eventPaths !== [] ? $eventPaths : $paths;
        } catch (\Throwable $e) {
            if (\class_exists(WlsLogger::class, false)) {
                WlsLogger::getInstance()->warning('Dispatcher warmup path observers failed: ' . $e->getMessage());
            }
            return $paths;
        }
    }
}
$homepageWarmupEnabled = (bool)($dispatcherConfig['homepage_warmup_enabled'] ?? false);
$homepageWarmupPaths = ['/'];
$homepageWarmupVariants = [];
if ($homepageWarmupEnabled) {
    $homepageWarmupPaths = wlsDispatcherNormalizeWarmupPaths(
        $dispatcherConfig['homepage_warmup_paths']
            ?? $wlsConfig['homepage_warmup_paths']
            ?? ['/']
    );
    $homepageWarmupVariants = $dispatcherConfig['homepage_warmup_variants']
        ?? $wlsConfig['homepage_warmup_variants']
        ?? [];
}
$warmupPathObserversEnabled = $homepageWarmupEnabled && (bool)(
    $dispatcherConfig['warmup_path_observers_enabled']
        ?? $wlsConfig['dispatcher_warmup_path_observers_enabled']
        ?? true
);
if ($warmupPathObserversEnabled) {
    $homepageWarmupPaths = wlsDispatcherApplyWarmupPathObservers(
        $homepageWarmupPaths,
        $instanceName,
        $port,
        \array_values($warmupHosts)
    );
}
$dispatcher->configure([
    'sni_routing_enabled' => true,
    'protocol_edge_ingress_enabled' => $protocolEdgeToken !== '',
    'proxy_protocol_v2_enabled' => true,
    'proxy_protocol_v2_secret' => $masterRuntimeCredential,
    'proxy_protocol_v2_require_auth' => true,
    'worker_protocol_edge_token' => $protocolEdgeToken,
    'learning_mode_enabled' => true,
    'connection_timeout' => 300,
    'main_loop_unblocked_log_every' => \Weline\Server\Service\MainLoopUnblockedLogConfig::resolve($wlsConfig, ['dispatcher']),
    'main_loop_unblocked_log_interval_sec' => \Weline\Server\Service\MainLoopUnblockedLogConfig::resolveInterval($wlsConfig, ['dispatcher']),
    'startup_protection_enabled' => (bool)($startupProtectionConfig['enabled'] ?? true),
    'startup_protection_window_sec' => (float)($startupProtectionConfig['window_sec'] ?? 45.0),
    'startup_protection_ready_ratio' => (float)($startupProtectionConfig['ready_ratio'] ?? 0.0),
    'startup_protection_min_ready' => (int)($startupProtectionConfig['min_ready'] ?? 1),
    'spin_wait_max_seconds' => (float)($dispatcherConfig['spin_wait_max_seconds'] ?? 0.0),
    'max_handle_new_connection_spin_budget_sec' => (float)($dispatcherConfig['max_handle_new_connection_spin_budget_sec'] ?? 0.0),
    'backend_route_wait_timeout_sec' => (float)($dispatcherConfig['backend_route_wait_timeout_sec'] ?? 0.0),
    'worker_health_connect_timeout_sec' => (float)($dispatcherConfig['worker_health_connect_timeout_sec'] ?? 2.0),
    'worker_health_response_timeout_sec' => (float)($dispatcherConfig['worker_health_response_timeout_sec'] ?? 2.0),
    'worker_health_audit_enabled' => (bool)($dispatcherConfig['worker_health_audit_enabled'] ?? false),
    'fast_tls_path_enabled' => (bool)($dispatcherConfig['fast_tls_path_enabled'] ?? true),
    // Keep the executable fallback aligned with env.sample.php. Accepting 64
    // sockets before returning to the proxy loop can create a second loopback
    // connection burst and transiently make every healthy Worker look down.
    'max_accept_per_loop' => (int)($dispatcherConfig['max_accept_per_loop'] ?? 16),
    'worker_connect_select_timeout_sec' => (float)($dispatcherConfig['worker_connect_select_timeout_sec'] ?? 0.02),
    'worker_busy_penalty_after_ms' => (float)($dispatcherConfig['worker_busy_penalty_after_ms'] ?? 120),
    'ssl_backend_preconnect_per_worker' => (int)($dispatcherConfig['ssl_backend_preconnect_per_worker'] ?? 0),
    'homepage_warmup_enabled' => $homepageWarmupEnabled,
    'homepage_warmup_hosts' => \array_values($warmupHosts),
    'homepage_warmup_paths' => $homepageWarmupPaths,
    'homepage_warmup_variants' => $homepageWarmupVariants,
    'homepage_warmup_route_gate_targets' => (int)($dispatcherConfig['homepage_warmup_route_gate_targets'] ?? $wlsConfig['homepage_warmup_route_gate_targets'] ?? 1),
    'cache' => [
        'default_ttl' => 3600,
        'connection_ttl' => 120,
    ],
]);

// DEV 模式：通过 WLS_DEV_MODE 常量或 DEV 常量判断
$_dispatcherDevMode = (\defined('WLS_DEV_MODE') && WLS_DEV_MODE) || (\defined('DEV') && DEV);
$dispatcher->setDevMode($_dispatcherDevMode);
unset($_dispatcherDevMode);

WlsLogger::info_("Dispatcher 启动，监听 tcp://{$host}:{$port}，预计 Worker 数: {$workerCount}（实际端口由 Master 动态通知）");

// 连接 IPC 控制通道
$dispatcher->setLifecycleTokens($orchestratorEpoch, $orchestratorLaunchId);
$dispatcher->setMasterGuard($childMasterGuard);

if ($controlPort > 0 || $supervisorEnabled) {
    if ($dispatcherAlreadyBootstrapped) {
        if (!$dispatcher->sendIpcReady()) {
            $dispatcher->connectIpc($controlPort);
        }
    } else {
        $dispatcher->connectIpc($controlPort);
    }
}

// 传入 Master PID 用于孤儿检测
if ($masterPid > 0) {
    $dispatcher->setMasterPid($masterPid);
}

$dispatcher->run();
