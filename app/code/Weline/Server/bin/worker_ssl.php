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
        // Runtime serving code must never mutate certificate ownership or
        // permissions. Publication preflight owns those invariants.
        return $path !== '' && !\is_link($path) && \is_file($path) && \is_readable($path);
    }
}

$wlsMemoryLimit = '256M';
@\ini_set('memory_limit', $wlsMemoryLimit);

// 解析命令行参数
$processName = '';
$isFrontend = false;
$wlsListenerMode = '';
$listenFd = 0;          // POSIX direct: Master 预绑定的共享监听 FD
$deferSsl = false;      // 延迟 SSL 模式（用于 TCP 透传架构，先接受 TCP 连接，再手动启用 SSL）
                        // 注意：延迟 SSL 仅改变握手时机，不消除 TLS 问题。Windows 下若出现 TLS reset，
                        // 可改用 --no-ssl 或 wls.https=false 做 HTTP 验证；或安装 event 扩展后再测 HTTPS。
$wlsLoopDriver = 'auto';
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
$workerCount = 1;
$wlsRuntimeTopology = '';
$wlsHttp3Enabled = false;
$wlsHttp3Mode = '';
$wlsHttp3ExpectedNativeFingerprint = '';
$wlsHttp3ExpectedNativeDigest = '';
$wlsHttp3ExpectedTicketRingEpoch = 0;
$wlsHttp3ExpectedTicketRingDigest = '';
$wlsHttp3RouteSlot = -1;
$wlsHttp3RouteCount = 0;
$wlsHttp3RouteOwnerEpoch = 0;
$wlsHttp3RouteGeneration = 0;
$wlsHttp3RouteNamespace = '';
$wlsHttp3RouteEligibility = '';
$orchestratorSlotId = '';
$orchestratorLeaseId = '';
$orchestratorGeneration = 0;
$masterLeaseFile = '';
$masterToken = '';
$publicOrigin = '';
$isMaintenanceWorker = false;
$isGatewayFallbackWorker = false;
$gatewayHostLeaseId = '';
$gatewayMasterLaunchId = '';
$wlsHttpPolicyEncoded = '';
$wlsHttpPolicySha256 = '';
$windowsListenerHandoffPath = '';
$windowsListenerHandoffId = '';
$windowsListenerIntentDigest = '';
$windowsListenerLeaseInstance = '';
$windowsListenerWlsInstance = '';
$windowsListenerMasterLaunchId = '';
$masterPid = 0;
$servingManifestPath = '';
$servingManifestGeneration = 0;
$servingManifestDigest = '';
$servingInstanceGeneration = 0;
$gatewayInstanceGenerationArgument = 0;

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
    } elseif ($arg === '--frontend' || $arg === '-frontend') {
        $isFrontend = true;
    } elseif (\str_starts_with($arg, '--wls-listener-mode=')) {
        $wlsListenerMode = \strtolower(\trim((string)\substr($arg, 20)));
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
    } elseif ($arg === '--gateway-fallback') {
        $isGatewayFallbackWorker = true;
    } elseif (\str_starts_with($arg, '--gateway-host-lease-id=')) {
        $gatewayHostLeaseId = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--gateway-host-lease-id='),
        )));
    } elseif (\str_starts_with($arg, '--gateway-master-launch-id=')) {
        $gatewayMasterLaunchId = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--gateway-master-launch-id='),
        )));
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    } elseif (\str_starts_with($arg, '--serving-manifest=')) {
        $servingManifestPath = \trim((string)\substr(
            $arg,
            \strlen('--serving-manifest='),
        ));
    } elseif (\str_starts_with($arg, '--serving-manifest-generation=')) {
        $servingManifestGeneration = (int)\substr(
            $arg,
            \strlen('--serving-manifest-generation='),
        );
    } elseif (\str_starts_with($arg, '--serving-manifest-digest=')) {
        $servingManifestDigest = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--serving-manifest-digest='),
        )));
    } elseif (\str_starts_with($arg, '--serving-instance-generation=')) {
        $servingInstanceGeneration = (int)\substr(
            $arg,
            \strlen('--serving-instance-generation='),
        );
    } elseif (\str_starts_with($arg, '--gateway-instance-generation=')) {
        $gatewayInstanceGenerationArgument = (int)\substr(
            $arg,
            \strlen('--gateway-instance-generation='),
        );
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = (string)\substr($arg, 12);
    } elseif (\str_starts_with($arg, '--master-lease-file=')) {
        $masterLeaseFile = (string)\substr($arg, 20);
    } elseif (\str_starts_with($arg, '--master-token=')) {
        \fwrite(\STDERR, "SSL Worker --master-token is forbidden; use the protected child ledger.\n");
        exit(1);
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
    } elseif ($arg === '--wls-http3=1') {
        $wlsHttp3Enabled = true;
    } elseif (\str_starts_with($arg, '--wls-http3-mode=')) {
        $wlsHttp3Mode = \strtolower(\trim((string)\substr($arg, 17)));
    } elseif (\str_starts_with($arg, '--wls-http3-native-fingerprint=')) {
        $wlsHttp3ExpectedNativeFingerprint = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--wls-http3-native-fingerprint='),
        )));
    } elseif (\str_starts_with($arg, '--wls-http3-native-digest=')) {
        $wlsHttp3ExpectedNativeDigest = \strtolower(\trim((string)\substr($arg, 26)));
    } elseif (\str_starts_with($arg, '--wls-http3-ticket-ring-epoch=')) {
        $wlsHttp3ExpectedTicketRingEpoch = (int)\substr(
            $arg,
            \strlen('--wls-http3-ticket-ring-epoch='),
        );
    } elseif (\str_starts_with($arg, '--wls-http3-ticket-ring-digest=')) {
        $wlsHttp3ExpectedTicketRingDigest = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--wls-http3-ticket-ring-digest='),
        )));
    } elseif (\str_starts_with($arg, '--wls-http3-route-slot=')) {
        $wlsHttp3RouteSlot = (int)\substr($arg, \strlen('--wls-http3-route-slot='));
    } elseif (\str_starts_with($arg, '--wls-http3-route-count=')) {
        $wlsHttp3RouteCount = (int)\substr($arg, \strlen('--wls-http3-route-count='));
    } elseif (\str_starts_with($arg, '--wls-http3-route-owner-epoch=')) {
        $wlsHttp3RouteOwnerEpoch = (int)\substr($arg, \strlen('--wls-http3-route-owner-epoch='));
    } elseif (\str_starts_with($arg, '--wls-http3-route-generation=')) {
        $wlsHttp3RouteGeneration = (int)\substr($arg, \strlen('--wls-http3-route-generation='));
    } elseif (\str_starts_with($arg, '--wls-http3-route-namespace=')) {
        $wlsHttp3RouteNamespace = \strtolower(\trim((string)\substr(
            $arg,
            \strlen('--wls-http3-route-namespace='),
        )));
    } elseif (\str_starts_with($arg, '--wls-http3-route-eligible=')) {
        $wlsHttp3RouteEligibility = \trim((string)\substr(
            $arg,
            \strlen('--wls-http3-route-eligible='),
        ));
    } elseif (\str_starts_with($arg, '--slot-id=')) {
        $orchestratorSlotId = \trim((string)\substr($arg, 10));
    } elseif (\str_starts_with($arg, '--lease-id=')) {
        $orchestratorLeaseId = \trim((string)\substr($arg, 11));
    } elseif (\str_starts_with($arg, '--slot-generation=')) {
        $orchestratorGeneration = (int)\substr($arg, 18);
    } elseif (\str_starts_with($arg, '--wls-http-policy=')) {
        $wlsHttpPolicyEncoded = \trim((string)\substr($arg, \strlen('--wls-http-policy=')));
    } elseif (\str_starts_with($arg, '--wls-http-policy-sha256=')) {
        $wlsHttpPolicySha256 = \strtolower(\trim((string)\substr($arg, \strlen('--wls-http-policy-sha256='))));
    } elseif (\str_starts_with($arg, '--public-origin=')) {
        $publicOrigin = (string)\substr($arg, 16);
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

if (!\in_array($wlsRuntimeTopology, ['direct', 'dispatcher'], true)) {
    \fwrite(\STDERR, "--wls-runtime-topology must be direct or dispatcher.\n");
    exit(1);
}
if (!\in_array($wlsListenerMode, ['single', 'reuseport', 'shared_fd'], true)) {
    \fwrite(\STDERR, "--wls-listener-mode must be single, reuseport, or shared_fd.\n");
    exit(1);
}
$privateListenerHost = \strtolower(\trim((string)$host, " \t\n\r\0\x0B[]"));
if ($isMaintenanceWorker
    && ($wlsListenerMode !== 'single'
        || !\in_array($privateListenerHost, ['127.0.0.1', '::1'], true))
) {
    \fwrite(\STDERR, "Private SSL Worker requires a loopback single listener.\n");
    exit(1);
}
if ($isGatewayFallbackWorker
    && (!\in_array($wlsListenerMode, ['single', 'shared_fd'], true)
        || \filter_var($privateListenerHost, FILTER_VALIDATE_IP) === false)
) {
    \fwrite(
        \STDERR,
        "Gateway fallback requires a literal-IP single or Master-owned shared listener.\n",
    );
    exit(1);
}
if ($isGatewayFallbackWorker
    && \preg_match('/^[a-f0-9]{32}$/D', $gatewayHostLeaseId) !== 1
) {
    \fwrite(\STDERR, "Gateway fallback host lease identity is required.\n");
    exit(1);
}
if ($isGatewayFallbackWorker
    && \preg_match('/^[a-f0-9]{32}$/D', $gatewayMasterLaunchId) !== 1
) {
    \fwrite(\STDERR, "Gateway fallback Master launch identity is required.\n");
    exit(1);
}
if (!$isGatewayFallbackWorker && $gatewayMasterLaunchId !== '') {
    \fwrite(\STDERR, "Gateway Master launch identity is fallback-only.\n");
    exit(1);
}
if ($gatewayHostLeaseId !== '') {
    $_SERVER['WLS_GATEWAY_HOST_LEASE_ID'] = $gatewayHostLeaseId;
    $_ENV['WLS_GATEWAY_HOST_LEASE_ID'] = $gatewayHostLeaseId;
    @\putenv('WLS_GATEWAY_HOST_LEASE_ID=' . $gatewayHostLeaseId);
}
$windowsHandoffValues = [
    $windowsListenerHandoffPath,
    $windowsListenerHandoffId,
    $windowsListenerIntentDigest,
    $windowsListenerLeaseInstance,
    $windowsListenerWlsInstance,
    $windowsListenerMasterLaunchId,
];
$windowsListenerHandoffPresent = \count(\array_filter(
    $windowsHandoffValues,
    static fn (string $value): bool => $value !== '',
)) > 0;
if ($windowsListenerHandoffPresent
    && (\PHP_OS_FAMILY !== 'Windows'
        || \in_array('', $windowsHandoffValues, true)
        || !$isGatewayFallbackWorker
        || $listenFd !== 0
        || !\hash_equals((string)$instanceName, $windowsListenerWlsInstance)
        || !\hash_equals($orchestratorLaunchId, $orchestratorLeaseId)
        || \preg_match('/\A[a-f0-9]{32}\z/D', $gatewayHostLeaseId) !== 1
        || \preg_match('/\A[a-f0-9]{32}\z/D', $windowsListenerHandoffId) !== 1
        || \preg_match('/\A[a-f0-9]{64}\z/D', $windowsListenerIntentDigest) !== 1
        || \preg_match('/\A[a-f0-9]{32}\z/D', $windowsListenerMasterLaunchId) !== 1
        || !\hash_equals($gatewayMasterLaunchId, $windowsListenerMasterLaunchId)
        || \preg_match('/\A[a-f0-9]{32}\z/D', $orchestratorLaunchId) !== 1
        || \preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $windowsListenerLeaseInstance) !== 1
        || \preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $windowsListenerWlsInstance) !== 1
        || \preg_match('/\Agateway_fallback#[1-9][0-9]*\z/D', $orchestratorSlotId) !== 1
        || $orchestratorGeneration <= 0)
) {
    \fwrite(\STDERR, "Windows gateway fallback listener handoff identity is invalid.\n");
    exit(1);
}
if (\PHP_OS_FAMILY === 'Windows'
    && $isGatewayFallbackWorker
    && !$windowsListenerHandoffPresent
) {
    \fwrite(\STDERR, "Windows gateway fallback requires target-bound listener adoption.\n");
    exit(1);
}
$privateListenerRequired = $isMaintenanceWorker || $isGatewayFallbackWorker;
if (($wlsRuntimeTopology === 'dispatcher' && $wlsListenerMode !== 'single')
    || ($wlsRuntimeTopology === 'direct'
        && $wlsListenerMode === 'single'
        && !$privateListenerRequired)
) {
    \fwrite(\STDERR, "Listener mode does not match the selected WLS topology.\n");
    exit(1);
}
if ($wlsHttp3Enabled && ($wlsRuntimeTopology !== 'direct'
    || \preg_match('/^[a-f0-9]{32}$/D', $wlsHttp3ExpectedNativeFingerprint) !== 1
    || \preg_match('/^[a-f0-9]{64}$/D', $wlsHttp3ExpectedNativeDigest) !== 1
)) {
    \fwrite(\STDERR, "HTTP/3 requires Direct topology and a verified native fingerprint and digest.\n");
    exit(1);
}
if ($wlsHttp3Enabled) {
    $expectedHttp3Mode = \PHP_OS_FAMILY === 'Darwin'
        ? 'datagram-router'
        : (\PHP_OS_FAMILY === 'Linux' ? 'reuseport-ebpf' : '');
    if ($expectedHttp3Mode === '' || $wlsHttp3Mode !== $expectedHttp3Mode) {
        \fwrite(\STDERR, "HTTP/3 mode does not match the current platform data plane.\n");
        exit(1);
    }
    if ($wlsHttp3Mode === 'datagram-router'
        && ($orchestratorSlotId === ''
            || $orchestratorLeaseId === ''
            || $orchestratorGeneration <= 0)
    ) {
        \fwrite(\STDERR, "Darwin HTTP/3 datagram routing requires a complete Worker generation lease.\n");
        exit(1);
    }
    if ($wlsHttp3Mode === 'reuseport-ebpf'
        && ($orchestratorSlotId === ''
            || $orchestratorLeaseId === ''
            || $orchestratorGeneration <= 0
            || $wlsHttp3RouteSlot < 0
            || $wlsHttp3RouteCount < 1
            || $wlsHttp3RouteCount > 64
            || $wlsHttp3RouteSlot >= $wlsHttp3RouteCount
            || $wlsHttp3RouteOwnerEpoch <= 0
            || $wlsHttp3RouteOwnerEpoch !== $orchestratorEpoch
            || $wlsHttp3RouteGeneration <= 0
            || $wlsHttp3RouteGeneration !== $orchestratorGeneration
            || \preg_match('/^[a-f0-9]{64}$/D', $wlsHttp3RouteNamespace) !== 1
            || !\in_array($wlsHttp3RouteEligibility, ['0', '1'], true))
    ) {
        \fwrite(\STDERR, "Linux HTTP/3 eBPF routing requires a complete staged route identity.\n");
        exit(1);
    }
}
$wlsHttp3RouteEligible = $wlsHttp3RouteEligibility === '1';
$useReusePort = $wlsListenerMode === 'reuseport';
if ($wlsListenerMode === 'shared_fd') {
    if ($listenFd < 3 || $wlsRuntimeTopology !== 'direct' || \PHP_OS_FAMILY === 'Windows') {
        \fwrite(\STDERR, "shared_fd requires POSIX direct topology and an inherited descriptor >= 3.\n");
        exit(1);
    }
} elseif ($listenFd !== 0) {
    \fwrite(\STDERR, "--listen-fd is only valid with --wls-listener-mode=shared_fd.\n");
    exit(1);
}
if ($isGatewayFallbackWorker
    && \PHP_OS_FAMILY !== 'Windows'
    && ($wlsListenerMode !== 'shared_fd' || $listenFd !== 3)
) {
    \fwrite(\STDERR, "POSIX gateway fallback requires the Master-owned listener on FD 3.\n");
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
require_once __DIR__ . DS . 'windows_start_process_working_directory.php';

// Autoload before resolving the Master bootstrap endpoint.
require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'worker_http_message.php';

$masterLeaseManager = new \Weline\Server\Service\MasterLeaseManager();
$masterToken = $masterLeaseManager->resolveProtectedCredentialFromArguments(
        $argv,
        $instanceName,
        (int)($masterPid ?? 0),
        $orchestratorEpoch,
    );
$masterRuntimeCredential = $masterLeaseManager->resolveProtectedRuntimeCredentialFromArguments(
    $argv,
    $instanceName,
    (int)($masterPid ?? 0),
    $orchestratorEpoch,
);

$wlsEndpointEdge = '';
$wlsEndpointHttpSelection = null;
$wlsEndpointHttp3Activation = null;
try {
    if ($wlsHttpPolicyEncoded === ''
        || \strlen($wlsHttpPolicyEncoded) > 32768
        || \preg_match('/\A[A-Za-z0-9_-]+\z/D', $wlsHttpPolicyEncoded) !== 1
        || \preg_match('/\A[a-f0-9]{64}\z/D', $wlsHttpPolicySha256) !== 1
    ) {
        throw new \RuntimeException('Worker immutable HTTP policy argv is missing or invalid.');
    }
    $padding = (4 - (\strlen($wlsHttpPolicyEncoded) % 4)) % 4;
    $wlsHttpPolicyJson = \base64_decode(
        \strtr($wlsHttpPolicyEncoded, '-_', '+/') . \str_repeat('=', $padding),
        true,
    );
    if (!\is_string($wlsHttpPolicyJson)
        || !\hash_equals($wlsHttpPolicySha256, \hash('sha256', $wlsHttpPolicyJson))
    ) {
        throw new \RuntimeException('Worker immutable HTTP policy digest mismatch.');
    }
    $wlsHttpPolicy = \json_decode($wlsHttpPolicyJson, true, 32, JSON_THROW_ON_ERROR);
    if (!\is_array($wlsHttpPolicy)
        || (int)($wlsHttpPolicy['schema_version'] ?? 0) !== 1
        || !\hash_equals($instanceName, (string)($wlsHttpPolicy['instance_name'] ?? ''))
    ) {
        throw new \RuntimeException('Worker immutable HTTP policy identity is invalid.');
    }
    $wlsEndpointEdge = \strtolower(\trim((string)($wlsHttpPolicy['edge_adapter'] ?? '')));
    $selectionData = $wlsHttpPolicy['http_protocol_selection'] ?? null;
    $http3Activation = $wlsHttpPolicy['http3'] ?? null;
    if (!\in_array($wlsEndpointEdge, ['nginx', 'wls'], true)
        || !\is_array($selectionData)
        || !\is_array($http3Activation)
        || !\is_bool($http3Activation['enabled'] ?? null)
        || !\is_bool($http3Activation['runtime_verified'] ?? null)
    ) {
        throw new \RuntimeException('Worker immutable HTTP policy fields are invalid.');
    }
    $wlsEndpointHttpSelection =
        \Weline\Server\Service\Runtime\HttpProtocolSelection::fromArray($selectionData);
    $wlsEndpointHttpSelection->assertCompatibleEdgeAdapter($wlsEndpointEdge);
    if ($wlsEndpointHttpSelection->isCaddyProtocolEdge()) {
        throw new \RuntimeException('SSL Worker cannot own a Caddy protocol-edge endpoint.');
    }
    $wlsEndpointHttp3Activation = $http3Activation;
    $http3Activated = $http3Activation['enabled'] && $http3Activation['runtime_verified'];
    if ($http3Activation['enabled'] !== $http3Activation['runtime_verified']
        || $http3Activated !== $wlsHttp3Enabled
    ) {
        throw new \RuntimeException('Worker HTTP/3 argv does not match endpoint activation.');
    }
    if ($http3Activated) {
        $activationDigest = \strtolower(\trim((string)($http3Activation['native_digest'] ?? '')));
        $activationFingerprint = \strtolower(\trim((string)($http3Activation['fingerprint'] ?? '')));
        if ($wlsEndpointEdge !== 'wls'
            || !$wlsEndpointHttpSelection->isNativeProtocolEdge()
            || !$wlsEndpointHttpSelection->supports(
                \Weline\Server\Service\Runtime\HttpProtocolSelection::HTTP_3,
            )
            || !$wlsEndpointHttpSelection->altSvc
            || !\hash_equals($wlsHttp3ExpectedNativeDigest, $activationDigest)
            || !\hash_equals($wlsHttp3ExpectedNativeFingerprint, $activationFingerprint)
        ) {
            throw new \RuntimeException('Worker HTTP/3 activation is outside the endpoint protocol policy.');
        }
    } elseif (\trim((string)($http3Activation['reason'] ?? '')) === '') {
        throw new \RuntimeException('Disabled Worker HTTP/3 activation requires a reason.');
    }
} catch (\Throwable $throwable) {
    \fwrite(\STDERR, 'Worker refused immutable HTTP policy: ' . $throwable->getMessage() . "\n");
    exit(1);
}

if ($wlsHttp3Enabled) {
    $pinnedHttp3Manifest = \Weline\Server\Protocol\Http3\NativeTransportLibrary::pinManifest(
        $wlsHttp3ExpectedNativeFingerprint,
        $wlsHttp3ExpectedNativeDigest,
    );
    $loadedHttp3Native = \Weline\Server\Protocol\Http3\NativeTransportLibrary::load();
    if (!($loadedHttp3Native['available'] ?? false)
        || !\Weline\Server\Protocol\Http3\NativeTransportLibrary::hasVerifiedRuntimeEvidence($pinnedHttp3Manifest)
    ) {
        throw new \RuntimeException('HTTP/3 Worker could not load its pinned, runtime-verified component.');
    }
}

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
    ($isMaintenanceWorker
        ? 'MaintenanceSSLWorker'
        : ($isGatewayFallbackWorker ? 'GatewayFallbackSSLWorker' : 'SSLWorker')) . "#{$workerId}",
    $instanceName,
    $orchestratorEpoch
);
$childMasterGuard->assertAliveOrExit('启动前 Master 自治检查');
\Weline\Server\Service\Runtime\WorkerProcessLease::register(
    $processName,
    $orchestratorLaunchId,
    $orchestratorEpoch
);

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
$_SERVER['WLS_PROCESS_ROLE'] = $isMaintenanceWorker
    ? 'maintenance'
    : ($isGatewayFallbackWorker ? 'gateway_fallback' : 'worker');
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
$_SERVER['WLS_MASTER_EPOCH'] = (string)$orchestratorEpoch;
$_ENV['WLS_MASTER_EPOCH'] = (string)$orchestratorEpoch;
@\putenv('WLS_MASTER_EPOCH=' . (string)$orchestratorEpoch);
$_SERVER['WLS_LAUNCH_ID'] = $orchestratorLaunchId;
$_ENV['WLS_LAUNCH_ID'] = $orchestratorLaunchId;
@\putenv('WLS_LAUNCH_ID=' . $orchestratorLaunchId);
if (!\defined('WLS_WORKER_MASTER_EPOCH')) {
    \define('WLS_WORKER_MASTER_EPOCH', $orchestratorEpoch);
}
if (!\defined('WLS_WORKER_LAUNCH_ID')) {
    \define('WLS_WORKER_LAUNCH_ID', $orchestratorLaunchId);
}
if ($publicOrigin !== '') {
    $_SERVER['WLS_PUBLIC_ORIGIN'] = $publicOrigin;
    $_ENV['WLS_PUBLIC_ORIGIN'] = $publicOrigin;
    @\putenv('WLS_PUBLIC_ORIGIN=' . $publicOrigin);
}

// 将相对路径转换为绝对路径
if (\PHP_OS_FAMILY === 'Windows') {
    $sslCert = \Weline\Framework\System\Process\Processer::resolveWindowsPersistentPath($sslCert);
    $sslKey = \Weline\Framework\System\Process\Processer::resolveWindowsPersistentPath($sslKey);
}
if ($sslCert && !\preg_match('/^(?:[a-zA-Z]:[\\\\\\/]|[\\\\\\/]{2}|\/)/', $sslCert)) {
    $sslCert = $bp . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslCert);
}
if ($sslKey && !\preg_match('/^(?:[a-zA-Z]:[\\\\\\/]|[\\\\\\/]{2}|\/)/', $sslKey)) {
    $sslKey = $bp . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslKey);
}


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

try {
    $wlsTlsSessionCacheConfig = \Weline\Server\Service\Runtime\TlsSessionCacheConfig::fromSslConfig($wlsSslConfig);
} catch (\Throwable $tlsSessionConfigError) {
    WlsLogger::error_('wls.ssl.session_cache 配置无效：' . $tlsSessionConfigError->getMessage());
    exit(1);
}

$workerStartupTraceFileEnabled = (bool)($wlsEnv['debug']['worker_startup_trace'] ?? false)
    || \in_array(\strtolower(\trim((string)(\getenv('WLS_WORKER_STARTUP_TRACE') ?: ''))), ['1', 'true', 'yes', 'on'], true);
$ipcClient = $ipcClient ?? null;
$wlsStartupTraceLastStage = 'logger_bootstrap';
$wlsWorkerGracefulExitReason = '';
$wlsStartupTraceStartedAt = wlsWorkerMonotonicNow();
$wlsStartupTraceLastAt = $wlsStartupTraceStartedAt;
$wlsStartupTrace = static function (string $stage, array $context = []) use (&$wlsStartupTraceLastAt, &$wlsStartupTraceLastStage, $wlsStartupTraceStartedAt, $workerId, $port, $instanceName, $isMaintenanceWorker, $isGatewayFallbackWorker, $workerStartupTraceFileEnabled): void {
    $now = wlsWorkerMonotonicNow();
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
            'role' => $isMaintenanceWorker
                ? 'maintenance'
                : ($isGatewayFallbackWorker ? 'gateway_fallback' : 'worker'),
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
$wlsWorkerExitTrace = static function (string $event, string $reason = '', array $context = []) use (&$ipcClient, &$wlsStartupTraceLastStage, $workerId, $port, $instanceName, $isMaintenanceWorker, $isGatewayFallbackWorker, $controlPort, $orchestratorLaunchId): void {
    $context = \array_merge([
        'pid' => \getmypid(),
        'instance' => $instanceName,
        'role' => $isMaintenanceWorker
            ? 'maintenance'
            : ($isGatewayFallbackWorker ? 'gateway_fallback' : 'worker'),
        'worker_id' => $workerId,
        'port' => $port,
        'control_port' => $controlPort,
        'launch_id' => $orchestratorLaunchId,
        'last_startup_stage' => $wlsStartupTraceLastStage,
        'ipc_connected' => $ipcClient !== null && $ipcClient->isConnected(),
        'memory_mb' => \round(\memory_get_usage(true) / 1048576, 2),
    ], $context);
    $payload = [
        'ts' => \date('c'),
        'event' => $event,
        'reason' => $reason,
        'data' => $context,
    ];
    // Durable fallback: process log redirects can stay empty when workers die
    // during READY gate before logger sinks flush; keep an append-only file.
    if (\defined('BP')) {
        @\file_put_contents(
            BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'wls-worker-exit-trace.log',
            (\json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . PHP_EOL,
            FILE_APPEND
        );
    }
    WlsLogger::error_('[WorkerExitTrace] ' . $event . ' reason=' . $reason . ' ' . (\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'));
    WlsLogger::flush_(true);
};
$wlsStartupTrace('logger_ready');

$ipcClient = $ipcClient ?? null;
$ipcSelfTag = $ipcSelfTag ?? null;
$ipcDraining = $ipcDraining ?? false;
$ipcReceivedShutdown = $ipcReceivedShutdown ?? false;
$drainStartTime = $drainStartTime ?? 0.0;
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
$lastMemoryCheck = $lastMemoryCheck ?? wlsWorkerMonotonicNow();
$memoryWarningThreshold = $memoryWarningThreshold ?? 0.80;
$memoryDrainThreshold = $memoryDrainThreshold ?? 0.88;
$maxRequestHeaderBytes = $maxRequestHeaderBytes ?? 65536;
$maxRequestBodyBytes = $maxRequestBodyBytes ?? (16 * 1024 * 1024);
$maxBufferedRequestBytes = $maxBufferedRequestBytes ?? ($maxRequestHeaderBytes + $maxRequestBodyBytes);
$ipcRole = $isMaintenanceWorker
    ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE
    : ($isGatewayFallbackWorker
        ? \Weline\Server\IPC\ControlMessage::ROLE_GATEWAY_FALLBACK
        : \Weline\Server\IPC\ControlMessage::ROLE_WORKER);
$earlyIpcHandler = null;
$kernel = null;

if ($controlPort > 0 || $supervisorEnabled) {
    $wlsStartupTrace('ipc_register_begin', ['control_port' => $controlPort]);
    $ipcSelfTag = ($isMaintenanceWorker
        ? 'Maintenance'
        : ($isGatewayFallbackWorker ? 'GatewayFallback' : 'Worker')) . "#{$workerId}";
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
if (!\defined('WLS_WORKER_HOT_PATH_LOGS_ENABLED')) {
    \define('WLS_WORKER_HOT_PATH_LOGS_ENABLED', $hotPathLogsEnabled);
}

/**
 * Load one exact immutable generation supplied by the launcher.
 *
 * @return array{manifest:array<string,mixed>,certs:array<string,array{local_cert:string,local_pk:string}>,routes:array<string,array<string,mixed>>,http_routes:array<string,array<string,mixed>>,policies:array<string,array{force_https:int,force_root_to_www:int,root_to_www_target:string,root_to_www_target_ready:int}>}
 */
function wlsLoadServingManifestSnapshot(
    string $path,
    int $generation,
    string $digest,
    string $instanceName,
    int $instanceGeneration,
    int $masterPid,
    int $masterEpoch,
): array {
    $store = new \Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore((string)BP);
    $fence = [
        'instance_id' => $instanceName,
        'instance_generation' => $instanceGeneration,
        'master_pid' => $masterPid,
        'master_epoch' => $masterEpoch,
    ];
    $manifest = $store->readBound($path, $generation, $digest, $fence);
    $current = $store->currentForFence($fence);
    if ((int)$current['generation'] !== $generation
        || !\hash_equals((string)$current['digest'], $digest)
        || !\hash_equals((string)$current['path'], $path)
    ) {
        throw new \RuntimeException(
            'WLS serving manifest is not the current generation for this instance fence.',
        );
    }
    $payload = (array)$manifest['payload'];
    $routes = [];
    $httpRoutes = [];
    $certs = [];
    $policies = [];
    foreach ((array)$payload['routes'] as $route) {
        if (!\is_array($route)) {
            throw new \RuntimeException('WLS serving manifest contains a malformed route.');
        }
        $domain = (string)$route['domain'];
        $certificate = (array)$route['certificate'];
        $privateKey = (array)$route['private_key'];
        $policy = (array)$route['policy'];
        $certs[$domain] = [
            'local_cert' => (string)$certificate['path'],
            'local_pk' => (string)$privateKey['path'],
        ];
        $policies[$domain] = [
            'force_https' => ($policy['force_https'] ?? false) === true ? 1 : 0,
            'force_root_to_www' => ($policy['force_root_to_www'] ?? false) === true ? 1 : 0,
            'root_to_www_target' => (string)($policy['root_to_www_target'] ?? ''),
            'root_to_www_target_ready' =>
                ($policy['root_to_www_target_ready'] ?? false) === true ? 1 : 0,
        ];
        $routes[$domain] = $route;
    }
    foreach ((array)($payload['desired_routes'] ?? []) as $desiredRoute) {
        if (!\is_array($desiredRoute)) {
            throw new \RuntimeException(
                'WLS serving manifest contains a malformed desired HTTP route.',
            );
        }
        $domain = (string)($desiredRoute['domain'] ?? '');
        $state = (string)($desiredRoute['certificate_state'] ?? '');
        if ($domain === ''
            || !\in_array($state, ['active', 'pending', 'disabled'], true)
            || !\is_bool($desiredRoute['force_https'] ?? null)
            || !\is_bool($desiredRoute['force_root_to_www'] ?? null)
            || !\is_bool($desiredRoute['root_to_www_target_ready'] ?? null)
            || isset($httpRoutes[$domain])
        ) {
            throw new \RuntimeException(
                'WLS serving manifest desired HTTP route facts are inconsistent.',
            );
        }
        $httpRoutes[$domain] = $desiredRoute;
        $policies[$domain] = [
            'force_https' => $desiredRoute['force_https'] ? 1 : 0,
            'force_root_to_www' => $desiredRoute['force_root_to_www'] ? 1 : 0,
            'root_to_www_target' => (string)(
                $desiredRoute['root_to_www_target'] ?? ''
            ),
            'root_to_www_target_ready' => $desiredRoute[
                'root_to_www_target_ready'
            ] ? 1 : 0,
        ];
    }
    if (\count($httpRoutes) !== (int)($payload['desired_route_count'] ?? -1)) {
        throw new \RuntimeException(
            'WLS serving manifest lacks the complete desired HTTP route set.',
        );
    }
    return [
        'manifest' => $manifest,
        'certs' => $certs,
        'routes' => $routes,
        'http_routes' => $httpRoutes,
        'policies' => $policies,
    ];
}

/**
 * Reconstruct the listener/process tuple independently inside the fallback
 * Worker. The Master sends the same tuple, but its copy is not authority for
 * facts this process can observe itself.
 *
 * @param array<string,mixed> $servingSnapshot
 * @param array<string,mixed> $windowsListenerProof
 * @return array<string,mixed>
 */
function wlsBuildGatewayFallbackTransitionIdentity(
    array $servingSnapshot,
    string $instanceName,
    string $slotId,
    string $serviceLeaseId,
    int $serviceGeneration,
    string $workerLaunchId,
    int $masterPid,
    int $masterEpoch,
    string $masterLaunchId,
    string $hostLeaseId,
    string $bindHost,
    int $port,
    int $listenFd,
    bool $windowsListenerAdopted,
    array $windowsListenerProof,
): array {
    $runtimeIdentity = new \Weline\Server\Service\MasterLeaseRuntimeIdentity();
    $workerIdentity = $runtimeIdentity->captureProcessIdentity((int)\getmypid());
    $masterIdentity = $runtimeIdentity->captureOwner($masterPid);
    $manifestPayload = \is_array($servingSnapshot['manifest']['payload'] ?? null)
        ? $servingSnapshot['manifest']['payload']
        : [];
    $projectUuid = (string)($manifestPayload['project_uuid'] ?? '');
    $normalisedBindHost = \strtolower(\trim($bindHost, " \t\n\r\0\x0B[]"));
    $packedBindHost = @\inet_pton($normalisedBindHost);
    $normalisedBindHost = \is_string($packedBindHost)
        ? (string)@\inet_ntop($packedBindHost)
        : '';
    if ($projectUuid === ''
        || $normalisedBindHost === ''
        || $masterPid <= 0
        || $masterEpoch <= 0
    ) {
        throw new \RuntimeException(
            'Gateway fallback transition identity lacks immutable startup facts.',
        );
    }

    $listenerTransport = 'posix_inherited_fd';
    $listenerReceiptDigest = '';
    if ($windowsListenerAdopted) {
        $listenerTransport = \Weline\Server\Service\Runtime\WindowsListenerHandoff::TRANSPORT;
        $listenerReceiptDigest = (string)($windowsListenerProof['envelope_digest'] ?? '');
        if (!\hash_equals($hostLeaseId, (string)($windowsListenerProof['host_lease_id'] ?? ''))
            || !\hash_equals($masterLaunchId, (string)($windowsListenerProof['master_launch_id'] ?? ''))
            || !\hash_equals($workerLaunchId, (string)($windowsListenerProof['launch_id'] ?? ''))
            || !\hash_equals($slotId, (string)($windowsListenerProof['slot_id'] ?? ''))
            || $serviceGeneration !== (int)($windowsListenerProof['generation'] ?? 0)
            || (int)\getmypid() !== (int)($windowsListenerProof['target_pid'] ?? 0)
            || !\hash_equals(
                (string)$workerIdentity['birth'],
                (string)($windowsListenerProof['target_process_birth'] ?? ''),
            )
            || !\hash_equals(
                (string)$masterIdentity['birth'],
                (string)($windowsListenerProof['source_process_birth'] ?? ''),
            )
            || !\hash_equals(
                $normalisedBindHost,
                \strtolower((string)($windowsListenerProof['host'] ?? '')),
            )
            || $port !== (int)($windowsListenerProof['port'] ?? 0)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $listenerReceiptDigest) !== 1
        ) {
            throw new \RuntimeException(
                'Windows fallback transition identity diverges from its adopted socket proof.',
            );
        }
    } else {
        if (\PHP_OS_FAMILY === 'Windows' || $listenFd !== 3) {
            throw new \RuntimeException(
                'POSIX fallback transition identity requires inherited listener FD 3.',
            );
        }
        $listenerReceiptDigest = \hash('sha256', \implode("\0", [
            $hostLeaseId,
            (string)\getmypid(),
            (string)$workerIdentity['birth'],
            (string)$workerIdentity['pid_namespace_id'],
            $workerLaunchId,
            (string)$serviceGeneration,
            (string)$listenFd,
        ]));
    }

    $adoption = [
        'schema' => 'wls-listener-adoption/2',
        'lease_id' => $hostLeaseId,
        'lease_state' => 'ACTIVE',
        'owner_pid' => (int)\getmypid(),
        'owner_process_birth' => (string)$workerIdentity['birth'],
        'owner_pid_namespace_id' => (string)$workerIdentity['pid_namespace_id'],
        'owner_launch_id' => $workerLaunchId,
        'slot_id' => $slotId,
        'generation' => $serviceGeneration,
        'host' => $normalisedBindHost,
        'port' => $port,
        'transport' => $listenerTransport,
        'receipt_digest' => $listenerReceiptDigest,
    ];
    $listenerProofDigest = \hash('sha256', \json_encode(
        $adoption,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ));

    return [
        'schema' => \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
        'project_uuid' => $projectUuid,
        'wls_instance' => $instanceName,
        'role' => \Weline\Server\IPC\ControlMessage::ROLE_GATEWAY_FALLBACK,
        'slot_id' => $slotId,
        'service_generation' => $serviceGeneration,
        'service_lease_id' => $serviceLeaseId,
        'worker_pid' => (int)\getmypid(),
        'worker_process_birth' => (string)$workerIdentity['birth'],
        'worker_pid_namespace_id' => (string)$workerIdentity['pid_namespace_id'],
        'worker_launch_id' => $workerLaunchId,
        'master_pid' => $masterPid,
        'master_epoch' => $masterEpoch,
        'master_launch_id' => $masterLaunchId,
        'master_process_birth' => (string)$masterIdentity['birth'],
        'master_pid_namespace_id' => (string)$masterIdentity['pid_namespace_id'],
        'port' => $port,
        'host_lease_instance' => \Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity::forRole(
            $instanceName,
            \Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity::ROLE_FALLBACK,
        ),
        'host_lease_id' => $hostLeaseId,
        'host_boot_id' => $runtimeIdentity->hostBootId(),
        'bind_host' => $normalisedBindHost,
        'listener_proof_digest' => $listenerProofDigest,
        'listener_transport' => $listenerTransport,
        'listener_receipt_digest' => $listenerReceiptDigest,
    ];
}

/** @param array<string,mixed> $received @param array<string,mixed> $expected */
function wlsGatewayFallbackTransitionIdentityMatches(array $received, array $expected): bool
{
    return \hash_equals(
        \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson($expected),
        \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson($received),
    );
}

/** @param array<string,mixed> $left @param array<string,mixed> $right */
function wlsGatewayFallbackTransitionMatches(array $left, array $right): bool
{
    foreach ([
        'action',
        'target_listener_state',
        'transition_id',
        'action_digest',
        'predecessor_action_digest',
    ] as $field) {
        if (!\is_string($left[$field] ?? null)
            || !\is_string($right[$field] ?? null)
            || !\hash_equals((string)$left[$field], (string)$right[$field])
        ) {
            return false;
        }
    }

    return \is_array($left['identity'] ?? null)
        && \is_array($right['identity'] ?? null)
        && wlsGatewayFallbackTransitionIdentityMatches(
            $left['identity'],
            $right['identity'],
        );
}

/**
 * Re-prove the exact project-owned certificate generation before reopening
 * listener admission. The Master performs the same proof, but the Worker must
 * not turn an expired, revoked, replaced, or partially reloaded TLS context
 * back into an accepting endpoint merely because an old UNDRAIN was replayed.
 *
 * @param array<string,array<string,mixed>> $routes
 * @param array<string,array{local_cert:string,local_pk:string}> $sniServerCerts
 */
function wlsGatewayFallbackTlsContextIsUsable(
    array $routes,
    array $sniServerCerts,
    string $sslCert,
    string $sslKey,
): bool {
    if ($routes === [] || $sniServerCerts === [] || $sslCert === '' || $sslKey === '') {
        return false;
    }

    try {
        $firstPair = \reset($sniServerCerts);
        if (!\is_array($firstPair)
            || !\hash_equals($sslCert, (string)($firstPair['local_cert'] ?? ''))
            || !\hash_equals($sslKey, (string)($firstPair['local_pk'] ?? ''))
        ) {
            return false;
        }
        $deadline = \Weline\Server\IPC\ControlMessage::monotonicSeconds() + 0.5;
        $generations = new \Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore(
            (string)BP,
        );
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                return false;
            }
            $domain = (string)($route['domain'] ?? '');
            $generation = (int)($route['certificate_generation'] ?? 0);
            $sourceDigest = (string)($route['certificate_source_digest'] ?? '');
            $certificate = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            $privateKey = \is_array($route['private_key'] ?? null)
                ? $route['private_key']
                : [];
            $snapshot = \is_array($route['certificate_snapshot'] ?? null)
                ? $route['certificate_snapshot']
                : [];
            $pair = $sniServerCerts[$domain] ?? null;
            $active = $generations->active($domain, $deadline);
            if (!\is_array($pair)
                || !\is_array($active)
                || $generation < 1
                || (int)($active['generation'] ?? 0) !== $generation
                || !\hash_equals(
                    $sourceDigest,
                    (string)($active['source_digest'] ?? ''),
                )
                || !\hash_equals(
                    (string)($certificate['path'] ?? ''),
                    (string)($pair['local_cert'] ?? ''),
                )
                || !\hash_equals(
                    (string)($privateKey['path'] ?? ''),
                    (string)($pair['local_pk'] ?? ''),
                )
                || !\hash_equals(
                    (string)($certificate['path'] ?? ''),
                    (string)($active['cert_path'] ?? ''),
                )
                || !\hash_equals(
                    (string)($privateKey['path'] ?? ''),
                    (string)($active['key_path'] ?? ''),
                )
                || !\hash_equals(
                    (string)($snapshot['leaf_fingerprint_sha256'] ?? ''),
                    (string)($active['leaf_fingerprint_sha256'] ?? ''),
                )
            ) {
                return false;
            }
        }
    } catch (\Throwable) {
        return false;
    }

    return true;
}

/**
 * Apply only the in-process admission state. The caller owns ACK delivery and
 * restores its snapshot only if an UNDRAIN ACK cannot enter the write queue.
 *
 * @param array<string,mixed> $transition
 * @param array<string,mixed> $expectedIdentity
 * @param array<string,mixed>|null $drainTransition
 * @param array<string,mixed>|null $undrainTransition
 * @param array<string,array{drain:string,undrain:string}> $retiredTransitions
 * @return array{success:bool,reason:string,listener_state:string,idempotent:bool,action_applied:bool}
 */
function wlsApplyGatewayFallbackListenerTransition(
    array $transition,
    array $expectedIdentity,
    string &$listenerState,
    bool &$listenerDraining,
    bool $terminal,
    bool $undrainAllowed,
    bool &$drainAcknowledged,
    ?array &$drainTransition,
    ?array &$undrainTransition,
    array &$retiredTransitions,
): array {
    $actualState = $terminal
        ? \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_TERMINAL
        : $listenerState;
    if (!\is_array($transition['identity'] ?? null)
        || !wlsGatewayFallbackTransitionIdentityMatches(
            $transition['identity'],
            $expectedIdentity,
        )
    ) {
        return [
            'success' => false,
            'reason' => 'transition_identity_mismatch',
            'listener_state' => $actualState,
            'idempotent' => false,
            'action_applied' => false,
        ];
    }
    if ($terminal) {
        return [
            'success' => false,
            'reason' => 'listener_terminal',
            'listener_state' => $actualState,
            'idempotent' => false,
            'action_applied' => false,
        ];
    }

    $action = (string)$transition['action'];
    $transitionId = (string)$transition['transition_id'];
    if ($action === \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN) {
        if ($listenerState === \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING) {
            $same = \is_array($drainTransition)
                && wlsGatewayFallbackTransitionMatches($transition, $drainTransition);
            return [
                'success' => $same,
                'reason' => $same ? '' : 'drain_transition_conflict',
                'listener_state' => $listenerState,
                'idempotent' => $same,
                'action_applied' => false,
            ];
        }
        $transitionHistoryExhausted = \count($retiredTransitions) >= 32;
        if ($listenerState !== \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE
            || isset($retiredTransitions[$transitionId])
            || $transitionHistoryExhausted
        ) {
            return [
                'success' => false,
                'reason' => isset($retiredTransitions[$transitionId])
                    ? 'drain_transition_replay'
                    : ($transitionHistoryExhausted
                        ? 'transition_history_exhausted'
                        : 'listener_not_active'),
                'listener_state' => $listenerState,
                'idempotent' => false,
                'action_applied' => false,
            ];
        }
        $listenerState = \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING;
        $listenerDraining = true;
        $drainAcknowledged = false;
        $drainTransition = $transition;
        $undrainTransition = null;
        return [
            'success' => true,
            'reason' => '',
            'listener_state' => $listenerState,
            'idempotent' => false,
            'action_applied' => true,
        ];
    }

    if (!$undrainAllowed) {
        return [
            'success' => false,
            'reason' => 'undrain_tls_context_unavailable',
            'listener_state' => $listenerState,
            'idempotent' => false,
            'action_applied' => false,
        ];
    }

    if ($listenerState === \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE) {
        $same = \is_array($undrainTransition)
            && wlsGatewayFallbackTransitionMatches($transition, $undrainTransition);
        return [
            'success' => $same,
            'reason' => $same ? '' : 'undrain_transition_replay',
            'listener_state' => $listenerState,
            'idempotent' => $same,
            'action_applied' => false,
        ];
    }
    if ($listenerState !== \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING
        || !$drainAcknowledged
        || !\is_array($drainTransition)
        || !\hash_equals($transitionId, (string)($drainTransition['transition_id'] ?? ''))
        || !\hash_equals(
            (string)($transition['predecessor_action_digest'] ?? ''),
            (string)($drainTransition['action_digest'] ?? ''),
        )
        || !wlsGatewayFallbackTransitionIdentityMatches(
            (array)$transition['identity'],
            (array)($drainTransition['identity'] ?? []),
        )
    ) {
        return [
            'success' => false,
            'reason' => 'undrain_predecessor_not_acknowledged',
            'listener_state' => $listenerState,
            'idempotent' => false,
            'action_applied' => false,
        ];
    }

    $listenerState = \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE;
    $listenerDraining = false;
    $undrainTransition = $transition;
    $retiredTransitions[$transitionId] = [
        'drain' => (string)$drainTransition['action_digest'],
        'undrain' => (string)$transition['action_digest'],
    ];
    return [
        'success' => true,
        'reason' => '',
        'listener_state' => $listenerState,
        'idempotent' => false,
        'action_applied' => true,
    ];
}

/**
 * @param array<string,mixed> $transition
 * @return array{enqueued:bool,flushed:bool}
 */
function wlsSendGatewayFallbackListenerAck(
    mixed $ipcClient,
    array $transition,
    string $actualState,
    bool $success,
    string $reason = '',
): array {
    if ($ipcClient === null || !$ipcClient->isConnected()) {
        return ['enqueued' => false, 'flushed' => false];
    }
    $ack = \Weline\Server\IPC\ControlMessage::gatewayFallbackListenerAck(
        (string)$transition['action'],
        (string)$transition['target_listener_state'],
        $actualState,
        (string)$transition['transition_id'],
        (string)$transition['action_digest'],
        (string)$transition['predecessor_action_digest'],
        (array)$transition['identity'],
        $success,
        $reason,
    );

    $enqueued = $ipcClient->send($ack);
    return [
        'enqueued' => $enqueued,
        'flushed' => $enqueued && $ipcClient->flushPendingWrites(0.2),
    ];
}

/**
 * Build the immutable TLS-context identity set used by the unload barrier.
 * A same-domain certificate rotation is a retirement too: an established
 * connection may still own the old SSL_CTX even though the route key remains.
 *
 * @param array<string,array<string,mixed>> $routes
 * @return array<string,array{domain:string,generation:int,source_digest:string}>
 */
function wlsServingManifestContextIdentitySet(array $routes): array
{
    $identities = [];
    foreach ($routes as $route) {
        if (!\is_array($route)) {
            throw new \RuntimeException('TLS serving route context identity is malformed.');
        }
        $domain = (string)($route['domain'] ?? '');
        $generation = (int)($route['certificate_generation'] ?? 0);
        $sourceDigest = \strtolower(\trim((string)(
            $route['certificate_source_digest'] ?? ''
        )));
        if ($domain === ''
            || $generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
        ) {
            throw new \RuntimeException('TLS serving route context identity is incomplete.');
        }
        $contextId = \hash(
            'sha256',
            $domain . "\0" . $generation . "\0" . $sourceDigest,
        );
        if (isset($identities[$contextId])) {
            throw new \RuntimeException('TLS serving route context identity is duplicated.');
        }
        $identities[$contextId] = [
            'domain' => $domain,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
        ];
    }
    \ksort($identities, SORT_STRING);
    return $identities;
}

/**
 * @param array<string,array<string,mixed>> $oldRoutes
 * @param array<string,array<string,mixed>> $nextRoutes
 * @return array{count:int,digest:string,domains:array<string,true>}
 */
function wlsServingManifestRetirementFacts(array $oldRoutes, array $nextRoutes): array
{
    $old = wlsServingManifestContextIdentitySet($oldRoutes);
    $next = wlsServingManifestContextIdentitySet($nextRoutes);
    $retired = \array_diff_key($old, $next);
    $retiredIds = \array_keys($retired);
    \sort($retiredIds, SORT_STRING);
    $domains = [];
    foreach ($retired as $identity) {
        $domains[(string)$identity['domain']] = true;
    }
    return [
        'count' => \count($retiredIds),
        'digest' => \hash(
            'sha256',
            \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson(
                $retiredIds,
            ),
        ),
        'domains' => $domains,
    ];
}

/**
 * 获取指定域名的重定向策略。
 *
 * @return array{force_https:int,force_root_to_www:int,root_to_www_target:string,root_to_www_target_ready:int}
 */
function _getDomainPolicy(string $domain, array $servingRoutes): array
{
    $route = wlsServingManifestRouteForHost($domain, $servingRoutes);
    if (!\is_array($route)) {
        return [
            'force_https' => 1,
            'force_root_to_www' => 0,
            'root_to_www_target' => '',
            'root_to_www_target_ready' => 1,
        ];
    }
    // TLS-active routes keep policy in a nested envelope; desired HTTP route
    // facts are already the digest-bound policy envelope themselves.
    $policy = \is_array($route['policy'] ?? null)
        ? (array)$route['policy']
        : $route;
    return [
        'force_https' => ($policy['force_https'] ?? false) === true ? 1 : 0,
        'force_root_to_www' => ($policy['force_root_to_www'] ?? false) === true ? 1 : 0,
        'root_to_www_target' => (string)($policy['root_to_www_target'] ?? ''),
        'root_to_www_target_ready' =>
            ($policy['root_to_www_target_ready'] ?? false) === true ? 1 : 0,
    ];
}

/** Return the token only for the one exact HTTP-01 transport request shape. */
function wlsAcmeHttp01RequestToken(string $method, string $target): ?string
{
    if ($method !== 'GET'
        || \preg_match(
            '#\A/\.well-known/acme-challenge/([A-Za-z0-9_-]{1,256})\z#D',
            $target,
            $matches,
        ) !== 1
    ) {
        return null;
    }

    return (string)$matches[1];
}

function wlsAcmeHttp01ChallengeResponse(
    \Weline\Server\Security\WorkerPolicyDecision $decision,
): ?string {
    $token = wlsAcmeHttp01RequestToken($decision->method, $decision->target);
    if ($token === null) {
        return null;
    }

    $host = wlsServingManifestNormalizeAuthority(
        (string)($decision->headers['host'] ?? ''),
    );
    $body = null;
    if ($host !== null && \defined('BP')) {
        $acmeDirectory = \rtrim(BP, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR . 'generated'
            . \DIRECTORY_SEPARATOR . 'acme-http01';
        $body = \Weline\Server\Service\Edge\Gateway\ProjectAcmeHttp01ChallengeStore::resolvePublishedChallenge(
            $acmeDirectory,
            $host,
            $token,
        );
    }
    if ($body === null) {
        $notFoundBody = 'ACME challenge not found';
        return "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Length: "
            . \strlen($notFoundBody) . "\r\nConnection: close\r\n\r\n{$notFoundBody}";
    }

    $len = \strlen($body);
    return $decision->keepAlive()
        ? "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=UTF-8\r\nCache-Control: no-store\r\nContent-Length: {$len}\r\nConnection: keep-alive\r\n\r\n{$body}"
        : "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=UTF-8\r\nCache-Control: no-store\r\nContent-Length: {$len}\r\nConnection: close\r\n\r\n{$body}";
}

/**
 * Re-authorize a cleartext keep-alive request against the current complete
 * desired-route set. The Host observed during the initial non-consuming peek
 * is an immutable connection authority, but it is never sufficient by itself:
 * every parsed request must repeat that exact authority and resolve to the
 * same digest-bound route identity.
 *
 * @return 'allow'|'acme_http01'|'redirect_https'|'not_found'|'misdirected'
 */
function wlsServingManifestPlaintextRequestAction(
    array $frame,
    string $connectionHost,
    array $httpRoutes,
): string {
    $headers = \is_array($frame['headers'] ?? null) ? $frame['headers'] : [];
    $host = wlsServingManifestNormalizeAuthority((string)($headers['host'] ?? ''));
    $expectedHost = wlsServingManifestNormalizeAuthority($connectionHost);
    if ($host === null
        || $expectedHost === null
        || !\hash_equals($expectedHost, $host)
    ) {
        return 'misdirected';
    }
    $route = wlsServingManifestRouteForHost($host, $httpRoutes);
    $expectedRoute = wlsServingManifestRouteForHost($expectedHost, $httpRoutes);
    $routeId = \is_array($route) ? (string)($route['route_id'] ?? '') : '';
    $expectedRouteId = \is_array($expectedRoute)
        ? (string)($expectedRoute['route_id'] ?? '')
        : '';
    if ($routeId === ''
        || $expectedRouteId === ''
        || !\hash_equals($expectedRouteId, $routeId)
    ) {
        return 'misdirected';
    }

    $certificateState = (string)($route['certificate_state'] ?? '');
    $isExactHttp01Challenge = wlsAcmeHttp01RequestToken(
        (string)($frame['method'] ?? ''),
        (string)($frame['target'] ?? ''),
    ) !== null;
    if ($isExactHttp01Challenge
        && \in_array($certificateState, ['active', 'pending'], true)
    ) {
        // Renewal challenges for an active force-HTTPS route must reach the
        // project-owned authoritative challenge store before any redirect or
        // application dispatch. A store miss is answered with 404 there.
        return 'acme_http01';
    }

    return match ($certificateState) {
        'active' => ($route['force_https'] ?? true) === true
            ? 'redirect_https'
            : 'allow',
        'disabled' => 'allow',
        'pending' => 'not_found',
        default => 'misdirected',
    };
}

function wlsServingManifestRedirectRequestTarget(string $target): string
{
    try {
        $path = \parse_url($target, PHP_URL_PATH);
        $query = \parse_url($target, PHP_URL_QUERY);
    } catch (\ValueError) {
        return '/';
    }
    if (!\is_string($path) || $path === '' || \preg_match('/[\r\n]/', $path) === 1) {
        $path = '/';
    }
    if (!\is_string($query) || $query === '' || \preg_match('/[\r\n]/', $query) === 1) {
        return $path;
    }
    return $path . '?' . $query;
}

function wlsServingManifestHttpsRedirectResponse(
    string $host,
    string $target,
    int $publicTcpPort,
): string {
    $location = 'https://' . $host;
    if ($publicTcpPort !== 443) {
        $location .= ':' . $publicTcpPort;
    }
    $location .= wlsServingManifestRedirectRequestTarget($target);
    return "HTTP/1.1 308 Permanent Redirect\r\n"
        . 'Location: ' . $location . "\r\n"
        . "Content-Length: 0\r\nConnection: close\r\n\r\n";
}

function wlsServingManifestNotFoundResponse(): string
{
    return "HTTP/1.1 404 Not Found\r\n"
        . "Content-Length: 9\r\nConnection: close\r\n\r\nNot Found";
}

function wlsServingManifestNormalizeAuthority(string $authority): ?string
{
    $authority = \strtolower(\trim($authority));
    if ($authority === '' || \str_contains($authority, "\0") || \str_contains($authority, '@')) {
        return null;
    }
    if (\str_starts_with($authority, '[')) {
        return null;
    }
    $colon = \strrpos($authority, ':');
    if ($colon !== false) {
        $port = \substr($authority, $colon + 1);
        if ($port === '' || \preg_match('/\A[0-9]{1,5}\z/D', $port) !== 1) {
            return null;
        }
        $portNumber = (int)$port;
        if ($portNumber < 1 || $portNumber > 65535) {
            return null;
        }
        $authority = \substr($authority, 0, $colon);
    }
    try {
        return \Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore::normalizeHost(
            $authority,
            false,
        );
    } catch (\Throwable) {
        return null;
    }
}

/** @return array<string,mixed>|null */
function wlsServingManifestRouteForHost(string $host, array $routes): ?array
{
    $host = wlsServingManifestNormalizeAuthority($host);
    if ($host === null) {
        return null;
    }

    return \Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore::routeForHost(
        $host,
        $routes,
    );
}

function wlsServingManifestHostMatchesSni(
    string $host,
    string $sni,
    array $routes,
): bool {
    $hostRoute = wlsServingManifestRouteForHost($host, $routes);
    $sniRoute = wlsServingManifestRouteForHost($sni, $routes);
    return \is_array($hostRoute)
        && \is_array($sniRoute)
        && \hash_equals(
            (string)($hostRoute['route_id'] ?? ''),
            (string)($sniRoute['route_id'] ?? ''),
        );
}

function wlsServingManifestFramingErrorResponse(array $frame): string
{
    if ((string)($frame['error'] ?? '') === 'missing_host') {
        return wlsServingManifestMisdirectedResponse();
    }
    return wlsHttpFramingErrorResponse((int)($frame['status_code'] ?? 400));
}

function wlsServingManifestMisdirectedResponse(): string
{
    return "HTTP/1.1 421 Misdirected Request\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Length: 19\r\nConnection: close\r\n\r\nMisdirected Request";
}

function wlsServingManifestRedirectTargetUnavailableResponse(): string
{
    return "HTTP/1.1 503 Service Unavailable\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Length: 19\r\nConnection: close\r\n\r\nService Unavailable";
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
    // 与 Dispatcher\SniParser 对齐；解析失败必须在选择任何租户证书前关闭连接。
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
    int $cryptoMethod,
    ?bool &$rollbackSucceeded = null,
): bool {
    $rollbackSucceeded = true;
    $previousOptions = $deferSslOptions;
    $nextOptions = $deferSslOptions;
    if ($nextOptions !== null) {
        $nextOptions['SNI_enabled'] = !empty($sniServerCerts);
        $nextOptions['SNI_server_certs'] = $sniServerCerts;
        $nextOptions['local_cert'] = $sslCert;
        $nextOptions['local_pk'] = $sslKey;
        $nextOptions['crypto_method'] = $cryptoMethod;
    }
    if ($listenSocket && \is_resource($listenSocket)) {
        $replacement = [
            'SNI_enabled' => !empty($sniServerCerts),
            'SNI_server_certs' => $sniServerCerts,
            'local_cert' => $sslCert,
            'local_pk' => $sslKey,
        ];
        foreach ($replacement as $option => $value) {
            if (@\stream_context_set_option($listenSocket, 'ssl', $option, $value)) {
                continue;
            }
            $rollback = \is_array($previousOptions) ? [
                'SNI_enabled' => (bool)($previousOptions['SNI_enabled'] ?? false),
                'SNI_server_certs' => \is_array(
                    $previousOptions['SNI_server_certs'] ?? null,
                ) ? $previousOptions['SNI_server_certs'] : [],
                'local_cert' => (string)($previousOptions['local_cert'] ?? ''),
                'local_pk' => (string)($previousOptions['local_pk'] ?? ''),
            ] : [];
            if ($rollback === []) {
                $rollbackSucceeded = false;
            } else {
                foreach ($rollback as $rollbackOption => $rollbackValue) {
                    if (!@\stream_context_set_option(
                        $listenSocket,
                        'ssl',
                        $rollbackOption,
                        $rollbackValue,
                    )) {
                        $rollbackSucceeded = false;
                    }
                }
            }
            return false;
        }
    }

    $deferSslOptions = $nextOptions;
    return true;
}

if ($servingInstanceGeneration === 0 && $gatewayInstanceGenerationArgument > 0) {
    $servingInstanceGeneration = $gatewayInstanceGenerationArgument;
}
if ($gatewayInstanceGenerationArgument > 0
    && $gatewayInstanceGenerationArgument !== $servingInstanceGeneration
) {
    throw new \RuntimeException('TLS Worker instance generation arguments disagree.');
}
if ($servingManifestPath === ''
    || $servingManifestGeneration < 1
    || $servingInstanceGeneration < 1
    || \preg_match('/\A[a-f0-9]{64}\z/D', $servingManifestDigest) !== 1
) {
    throw new \RuntimeException(
        'TLS Worker requires a serving manifest triple and --serving-instance-generation.',
    );
}
if (!$deferSsl) {
    throw new \RuntimeException(
        'Manifest-bound TLS Worker requires defer-ssl for per-connection SNI enforcement.',
    );
}
if ($wlsHttp3Enabled) {
    // The current native H3 request ABI does not expose the connection SNI to
    // PHP. Serving it would make an exact Host/SNI route equality check
    // impossible, so degrade H3 only and retain H2/H1 TLS service.
    $wlsHttp3Enabled = false;
    $wlsHttp3Mode = 'disabled_manifest_sni_unavailable';
}
$wlsStartupTrace('serving_manifest_load_begin');
$servingSnapshot = wlsLoadServingManifestSnapshot(
    $servingManifestPath,
    $servingManifestGeneration,
    $servingManifestDigest,
    $instanceName,
    $servingInstanceGeneration,
    $masterPid,
    $orchestratorEpoch,
);
$sniServerCerts = $servingSnapshot['certs'];
$servingManifestRoutes = $servingSnapshot['routes'];
$servingManifestHttpRoutes = $servingSnapshot['http_routes'];
\Weline\Server\Service\Runtime\WorkerReadinessState::markServingManifest(
    $servingManifestGeneration,
    $servingManifestDigest,
    \count($servingManifestRoutes),
);
\Weline\Server\Service\WlsWorkerGlobals::setDomainPolicies(
    $servingSnapshot['policies'],
);
$firstServingPair = \reset($sniServerCerts);
$sslCert = \is_array($firstServingPair)
    ? (string)$firstServingPair['local_cert']
    : '';
$sslKey = \is_array($firstServingPair)
    ? (string)$firstServingPair['local_pk']
    : '';
$wlsStartupTrace('serving_manifest_loaded', [
    'generation' => $servingManifestGeneration,
    'route_count' => \count($servingManifestRoutes),
]);
WlsLogger::info_('[SSL] 已绑定不可变 serving manifest generation='
    . $servingManifestGeneration . '，routes=' . \count($servingManifestRoutes));

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

// 子进程只发布脱敏的 generation lease；Master/IPC 仍是槽位、READY 与监听能力权威。

// 路由提示只属于 Dispatcher 透传数据面；Direct 不需要逐响应改写。
\Weline\Server\Service\RouteHintService::init($port, $wlsRuntimeTopology === 'dispatcher', 3600);

// 初始化框架运行时
$runtime = null;
$runtimeError = null;
$fpcFastPath = null;

try {
    WlsLogger::info_("Worker 启动，监听 ssl://{$host}:{$port}");
    $wlsStartupTrace('runtime_bootstrap_begin');
    $runtime = wlsBootstrapFrameworkRuntime();
    $wlsStartupTrace('fpc_coordinator_preload_begin');
    $fpcFastPath = wlsCreateWorkerFullPageCacheFastPath($runtime);
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
    $defaultSessionTokenFileName = \Weline\Server\Service\SharedStateRuntimeScope::defaultTokenFileNameForRole('session_server', $sessionPort);
    $sessionTokenFileName = \trim((string) ($sessionRuntime['token_file_name'] ?? $defaultSessionTokenFileName));
    if ($sessionTokenFileName === '') {
        $sessionTokenFileName = $defaultSessionTokenFileName;
    }
    $memoryHost = (string) ($memoryRuntime['host'] ?? '127.0.0.1');
    if ($memoryHost === '') {
        $memoryHost = '127.0.0.1';
    }
    $memoryPort = (int) ($memoryRuntime['port'] ?? 0);
    if ($memoryPort <= 0) {
        $memoryPort = 19971 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
    }
    $defaultMemoryTokenFileName = \Weline\Server\Service\SharedStateRuntimeScope::defaultTokenFileNameForRole('memory_server', $memoryPort);
    $memoryTokenFileName = \trim((string) ($memoryRuntime['token_file_name'] ?? $defaultMemoryTokenFileName));
    if ($memoryTokenFileName === '') {
        $memoryTokenFileName = $defaultMemoryTokenFileName;
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
if (!isset($sessionTokenFileName)) {
    $sessionTokenFileName = \Weline\Server\Service\SharedStateRuntimeScope::defaultTokenFileNameForRole(
        'session_server',
        $sessionPort,
    );
}
if (!isset($memoryTokenFileName)) {
    $memoryTokenFileName = \Weline\Server\Service\SharedStateRuntimeScope::defaultTokenFileNameForRole(
        'memory_server',
        $memoryPort,
    );
}

// ========== Fiber 调度器初始化（确保 SSE/长任务不阻塞主循环） ==========
$fiberScheduler = new \Weline\Server\Scheduler\FiberScheduler();
$eventLoopMeta = \Weline\Server\EventLoop\EventLoopFactory::create($wlsLoopDriver);
$eventLoop = $eventLoopMeta['loop'];
$coroutineRuntime = new \Weline\Server\Runtime\CoroutineRuntime($eventLoop, $fiberScheduler);
$asyncBizAdapters = new \Weline\Server\Runtime\Async\AsyncBizAdapters();
\Weline\Server\Observer\SchedulerWaitObserver::setScheduler($fiberScheduler);
\Weline\Framework\Runtime\SchedulerSystem::enableScheduler();
\Weline\Framework\Runtime\SchedulerSystem::enableIoWait();
$longLivedProtocolResolver = new \Weline\Server\Service\Protocol\LongLived\ProtocolResolver();
$activeFibers = [];
$fiberTickBudgetMs = (float)(\Weline\Framework\App\Env::get('wls.worker.fiber_tick_budget_ms', 8) ?: 8);
\Weline\Server\Log\WlsLogger::info_("Fiber I/O await 已启用（CoroutineRuntime 驱动）");
\Weline\Framework\Runtime\WlsConcurrency::setOtherSuspendedFiberCountProvider(
    static function () use (&$activeFibers): int {
        return \count($activeFibers);
    }
);
// Fiber 池与长连接治理（与 worker.php 对齐，供 Master IPC 与 Dispatcher 饱和策略使用）
$fiberIdleTtlSec = 0;
$fiberMaxActive = 0;
$fiberReleaseIdleRequested = false;
$lastFiberIdleCheck = \Weline\Server\Runtime\WorkerFiberContextTracker::monotonicNowNs();
$longLivedConnections = [];
$longLivedMaxActive = 0;
$longLivedSaturationReported = false;
$longLivedSaturationCleared = false;
$lastLongLivedSaturationReport = 0.0; // monotonic 秒
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
 * 获取系统可用内存（字节）
 * @return int 可用内存字节数，获取失败返回 0
 */
function getSystemFreeMemory(): int
{
    return \Weline\Server\Service\Runtime\SystemMemoryProbe::freeBytes();
}

/**
 * 获取系统总内存（字节）
 * @return int 总内存字节数
 */
function getSystemTotalMemory(): int
{
    return \Weline\Server\Service\Runtime\SystemMemoryProbe::totalBytes();
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
// 下发 --wls-listener-mode=reuseport。Worker 不再二次猜测内核能力；以实际 set/bind/listen
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
$windowsListenerProof = [];
$windowsListenerAdopted = false;
try {
    $wlsHttpProtocolCapabilities =
        (new \Weline\Server\Service\Runtime\HttpProtocolCapabilityProbe())->snapshot(
            $wlsEndpointEdge,
            $wlsEndpointHttpSelection,
            true,
            'running_endpoint',
            $wlsEndpointHttp3Activation,
        );
} catch (\Throwable $throwable) {
    WlsLogger::error_('Worker refused immutable HTTP protocol policy: ' . $throwable->getMessage());
    exit(1);
}
$wlsHttpAdapters = \is_array($wlsHttpProtocolCapabilities['wls_adapters'] ?? null)
    ? $wlsHttpProtocolCapabilities['wls_adapters']
    : [];
$wlsConfiguredTcpProtocols = [
    $wlsEndpointHttpSelection->preferred,
    ...\array_values(\array_filter(
        $wlsEndpointHttpSelection->protocols,
        static fn(string $protocol): bool =>
            $protocol !== $wlsEndpointHttpSelection->preferred,
    )),
];
$wlsTlsAlpnList = [];
foreach ($wlsConfiguredTcpProtocols as $configuredProtocol) {
    if ($configuredProtocol === \Weline\Server\Service\Runtime\HttpProtocolSelection::HTTP_2
        && (bool)($wlsHttpAdapters['http2']['enabled'] ?? false)
    ) {
        $wlsTlsAlpnList[] = 'h2';
    } elseif ($configuredProtocol === \Weline\Server\Service\Runtime\HttpProtocolSelection::HTTP_1
        && (bool)($wlsHttpAdapters['http1']['enabled'] ?? false)
    ) {
        $wlsTlsAlpnList[] = 'http/1.1';
    }
}
$wlsHttp2NegotiationEnabled = \in_array('h2', $wlsTlsAlpnList, true);
$wlsHttp1NegotiationEnabled = \in_array('http/1.1', $wlsTlsAlpnList, true);
$wlsHttp3NegotiationEnabled = (bool)($wlsHttpAdapters['http3']['enabled'] ?? false);
if ($wlsTlsAlpnList === [] && !$wlsHttp3Enabled) {
    WlsLogger::error_('Worker endpoint has no verified active HTTP protocol.');
    exit(1);
}
$wlsTlsAlpnProtocols = $wlsTlsAlpnList !== []
    ? \implode(',', $wlsTlsAlpnList)
    : 'wls-no-tcp-http';
if ($wlsHttp3Enabled && !$wlsHttp3NegotiationEnabled) {
    WlsLogger::error_('Worker HTTP/3 activation failed the current native capability gate.');
    exit(1);
}
if ($wlsHttp3Enabled && $wlsHttp3NegotiationEnabled) {
    WlsLogger::info_(
        'HTTP/3 native capability verified; TCP ALPN=' . $wlsTlsAlpnProtocols
        . ' and QUIC h3 will bind after warmup.'
    );
}
$wlsTlsSessionCacheRuntime = null;
if ($wlsTlsSessionCacheConfig->enabled()) {
    if (!$deferSsl) {
        WlsLogger::error_(
            'wls.ssl.session_cache=external 首期仅支持 defer-SSL 单连接 SNI 路径；未经验证的原生 SNI SSL_CTX 不会自动启用。'
        );
        exit(1);
    }
    try {
        $tlsSessionPolicyIdentity = \hash('sha256', \json_encode([
            'protocols' => $wlsConfiguredSslProtocols,
            'crypto_method' => $cryptoMethod,
            'alpn' => $wlsTlsAlpnProtocols,
            'ciphers' => $wlsModernTlsCiphers,
            'curves' => $wlsModernTlsCurves,
            'verify_peer' => false,
            'client_auth' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $wlsTlsSessionCacheRuntime = new \Weline\Server\Service\Runtime\TlsSessionCacheRuntime(
            $wlsTlsSessionCacheConfig,
            $memoryHost,
            $memoryPort,
            \Weline\Server\Service\SharedStateRuntimeScope::tokenFilePath($memoryTokenFileName),
            $instanceName,
            $publicOrigin,
            $tlsSessionPolicyIdentity,
        );
        // Parse certificate/public-key identities before bind so the first ClientHello
        // cannot pay file parsing cost or discover an invalid context on the hot path.
        if ($sslCert !== '' && $sslKey !== '') {
            $wlsTlsSessionCacheRuntime->streamContextOptions($listenerHost, [
                'local_cert' => $sslCert,
                'local_pk' => $sslKey,
            ]);
        }
        foreach ($sniServerCerts as $tlsSessionSni => $tlsSessionPair) {
            if (\is_string($tlsSessionSni) && \is_array($tlsSessionPair)) {
                $wlsTlsSessionCacheRuntime->streamContextOptions($tlsSessionSni, $tlsSessionPair);
            }
        }
        WlsLogger::info_(
            '[TLS Session Cache] PHP 8.6 external stateful cache configured; runtime reuse remains unverified until live gates pass.'
        );
    } catch (\Throwable $tlsSessionRuntimeError) {
        WlsLogger::error_('[TLS Session Cache] 启动前门禁失败：' . $tlsSessionRuntimeError->getMessage());
        exit(1);
    }
}
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
        'alpn_protocols' => $wlsTlsAlpnProtocols,
        'SNI_enabled' => !empty($sniServerCerts),
        'SNI_server_certs' => $sniServerCerts,
    ];
}

// 特权端口权限检查（macOS/Linux）
if (\PHP_OS !== 'WINNT' && $port < 1024) {
    $euid = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : -1;
    if ($euid !== 0 && $euid !== -1) {
        WlsLogger::error_(
            "拒绝绑定特权端口 {$port}：当前 WLS Worker 未获得平台服务授予的端口权限 "
            . "(euid: {$euid})；请改用非特权端口或由已安装的平台服务提供公共入口。",
        );
        exit(1);
    }
}

// Windows supplemental fallback consumes the exact target-PID duplicate. The
// Master retains its source copy until drain/stop, so no numeric re-bind occurs.
if ($windowsListenerHandoffPresent) {
    try {
        $importedListener = \Weline\Server\Service\Runtime\WindowsListenerHandoff::awaitChildSocket(
            $windowsListenerHandoffPath,
            [
                'handoff_id' => $windowsListenerHandoffId,
                'intent_digest' => $windowsListenerIntentDigest,
                'lease_id' => $gatewayHostLeaseId,
                'lease_instance' => $windowsListenerLeaseInstance,
                'wls_instance' => $windowsListenerWlsInstance,
                'bind_host' => $privateListenerHost,
                'port' => $port,
                'master_launch_id' => $windowsListenerMasterLaunchId,
                'launch_id' => $orchestratorLaunchId,
                'slot_id' => $orchestratorSlotId,
                'generation' => $orchestratorGeneration,
            ],
        );
        $sharedListenerSocket = $importedListener['socket'];
        $windowsListenerProof = $importedListener['proof'];
        $socket = @\socket_export_stream($sharedListenerSocket);
        if (!\is_resource($socket)) {
            @\socket_close($sharedListenerSocket);
            throw new \RuntimeException(
                'Windows adopted fallback listener could not be exported as a stream.'
            );
        }
        $windowsListenerAdopted = true;
        WlsLogger::info_("Using target-bound Windows fallback listener on {$host}:{$port}");
    } catch (\Throwable $throwable) {
        WlsLogger::error_('Windows fallback listener adoption failed: ' . $throwable->getMessage());
        exit(1);
    }

// macOS direct 使用 Master 预绑定的单个共享 accept queue。TLS 仍由 Worker accept 后处理。
} elseif ($listenFd > 0) {
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
    $rawSocket = false;
    $maxBindRetries = \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_ATTEMPTS;
    for ($attempt = 1; $attempt <= $maxBindRetries; $attempt++) {
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
        if (@\socket_bind($rawSocket, $host, $port)) {
            break;
        }

        $errCode = \socket_last_error($rawSocket);
        $errMsg = \socket_strerror($errCode);
        @\socket_close($rawSocket);
        $rawSocket = false;
        if (!\Weline\Server\Socket\ListenSocketOptions::isAddressInUseError($errCode, $errMsg)
            || $attempt >= $maxBindRetries
        ) {
            WlsLogger::error_("socket_bind 失败: ({$errCode}) {$errMsg}");
            exit(1);
        }
        WlsLogger::warning_(
            "socket_bind 遇到临时端口竞争，{$attempt}/{$maxBindRetries} 次重试: ({$errCode}) {$errMsg}"
        );
        \usleep(\Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_DELAY_MICROSECONDS);
    }
    if (!$rawSocket) {
        WlsLogger::error_("socket_bind 重试后仍未获得 {$host}:{$port}");
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

    $rawSocket = false;
    $maxBindRetries = \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_ATTEMPTS;
    for ($attempt = 1; $attempt <= $maxBindRetries; $attempt++) {
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
        if (@\socket_bind($rawSocket, $host, $port)) {
            break;
        }

        $errCode = \socket_last_error($rawSocket);
        $errMsg = \socket_strerror($errCode);
        @\socket_close($rawSocket);
        $rawSocket = false;
        if (!\Weline\Server\Socket\ListenSocketOptions::isAddressInUseError($errCode, $errMsg)
            || $attempt >= $maxBindRetries
        ) {
            WlsLogger::error_("socket_bind 失败: ({$errCode}) {$errMsg}");
            exit(1);
        }
        WlsLogger::warning_(
            "socket_bind 遇到临时端口竞争，{$attempt}/{$maxBindRetries} 次重试: ({$errCode}) {$errMsg}"
        );
        \usleep(\Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_DELAY_MICROSECONDS);
    }
    if (!$rawSocket) {
        WlsLogger::error_("socket_bind 重试后仍未获得 {$host}:{$port}");
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
            'alpn_protocols' => $wlsTlsAlpnProtocols,
            'SNI_enabled' => !empty($sniServerCerts),
            'SNI_server_certs' => $sniServerCerts,
        ]
    ]);
    \stream_context_set_params($socket, \stream_context_get_params($sslContext));
    
    WlsLogger::info_("SO_REUSEPORT socket 创建成功，Worker #{$workerId} 监听 {$host}:{$port}");
    
} elseif ($deferSsl && $useReusePort && !$isWindows && \function_exists('socket_create')) {
    // 方案2b-socket：仅 SO_REUSEPORT 直连模式才用 socket 扩展（socket_export_stream + stream_socket_accept 在 Dispatcher 模式下不可靠）
    // Dispatcher 模式（$useReusePort=false）直接 fallthrough 到方案2b 的 stream_socket_server，保证 stream_socket_accept 正常工作
    $maxBindRetries = \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_ATTEMPTS;
    $bindRetryDelay = \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_DELAY_MICROSECONDS;
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
        if (!\Weline\Server\Socket\ListenSocketOptions::isAddressInUseError(
            $lastErrno,
            $lastErrstr,
        )) {
            WlsLogger::error_("Socket 绑定失败 (defer-ssl): {$lastErrstr} (errno: {$lastErrno})");
            break;
        }
        WlsLogger::warning_("端口 {$port} 瞬时占用 (errno: {$lastErrno})，50 毫秒后重试 ({$attempt}/{$maxBindRetries})");
        if ($attempt < $maxBindRetries) {
            \Weline\Framework\Runtime\SchedulerSystem::usleep($bindRetryDelay);
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

    $socket = null;
    $errno = 0;
    $errstr = '';
    for (
        $attempt = 1;
        $attempt <= \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_ATTEMPTS;
        $attempt++
    ) {
        $socket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if ($socket) {
            break;
        }
        if (!\Weline\Server\Socket\ListenSocketOptions::isAddressInUseError($errno, $errstr)) {
            break;
        }
        WlsLogger::warning_(
            "端口 {$port} 瞬时占用 (errno: {$errno})，50 毫秒后重试 "
            . "({$attempt}/"
            . \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_ATTEMPTS
            . ')'
        );
        if ($attempt < \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_ATTEMPTS) {
            \Weline\Framework\Runtime\SchedulerSystem::usleep(
                \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_DELAY_MICROSECONDS,
            );
        }
    }

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
            'alpn_protocols' => $wlsTlsAlpnProtocols,
            'SNI_enabled' => !empty($sniServerCerts),
            'SNI_server_certs' => $sniServerCerts,
        ]
    ]);

    $socket = null;
    $errno = 0;
    $errstr = '';
    for (
        $attempt = 1;
        $attempt <= \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_ATTEMPTS;
        $attempt++
    ) {
        $socket = @\stream_socket_server(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if ($socket) {
            break;
        }
        if (!\Weline\Server\Socket\ListenSocketOptions::isAddressInUseError($errno, $errstr)) {
            break;
        }
        WlsLogger::warning_(
            "端口 {$port} 瞬时占用 (errno: {$errno})，50 毫秒后重试 "
            . "({$attempt}/"
            . \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_ATTEMPTS
            . ')'
        );
        if ($attempt < \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_ATTEMPTS) {
            \Weline\Framework\Runtime\SchedulerSystem::usleep(
                \Weline\Server\Socket\ListenSocketOptions::BIND_RETRY_DELAY_MICROSECONDS,
            );
        }
    }

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
if ($windowsListenerAdopted) {
    \Weline\Server\Service\Runtime\WorkerReadinessState::setWindowsListenerHandoffProof(
        $windowsListenerProof,
    );
}

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
$http3Runtime = null;
\Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3Closed();
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
$drainStartTime = 0.0;
$shouldExit = false;
$gatewayFallbackListenerState = $isGatewayFallbackWorker
    ? \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE
    : '';
$gatewayFallbackListenerDraining = false;
$gatewayFallbackDrainAcknowledged = false;
$gatewayFallbackDrainTransition = null;
$gatewayFallbackUndrainTransition = null;
$gatewayFallbackRetiredTransitions = [];
$gatewayFallbackExpectedTransitionIdentity = [];
if ($isGatewayFallbackWorker) {
    try {
        $gatewayFallbackExpectedTransitionIdentity =
            wlsBuildGatewayFallbackTransitionIdentity(
                $servingSnapshot,
                $instanceName,
                $orchestratorSlotId,
                $orchestratorLeaseId,
                $orchestratorGeneration,
                $orchestratorLaunchId,
                $masterPid,
                $orchestratorEpoch,
                $gatewayMasterLaunchId,
                $gatewayHostLeaseId,
                $privateListenerHost,
                $port,
                $listenFd,
                $windowsListenerAdopted,
                $windowsListenerProof,
            );
    } catch (\Throwable $throwable) {
        WlsLogger::error_(
            'Gateway fallback exact listener identity could not be reconstructed: '
            . $throwable->getMessage(),
        );
        if ($socket && \is_resource($socket)) {
            @\fclose($socket);
            $socket = null;
        }
        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
        exit(1);
    }
}
$cacheClearEpoch = 0;
$maxDrainTime = 10;     // 由 Master drain/reload 消息或默认覆盖
$waitingForAck = false;
$http3AvailabilityEpoch = 0;
$http3AvailabilityRouteEpoch = 0;
$http3AvailabilitySignature = '';
$http3AvailabilityEnabled = false;
$http3ActivationId = '';
$http3RouteActivationReceiptSent = false;
$readySentTime = 0.0;
$ackRetryCount = 0;
$maxAckRetries = 0;
$ackTimeout = \Weline\Server\IPC\ControlMessage::READY_RETRY_INTERVAL_SEC;
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
    $memoryTokenFileName,
    $wlsTlsSessionCacheRuntime,
    $wlsEnv
): void {
    if ($readyGateWorkerBootstrapWarmupCompleted) {
        if ($runtimeError === null && $runtime instanceof \Weline\Framework\Runtime\WlsRuntime) {
            $runtime->assertFrontendWorkerCredentialStoreReady();
        }
        return;
    }
    if ($isMaintenanceWorker) {
        if ($wlsTlsSessionCacheRuntime !== null && !$wlsTlsSessionCacheRuntime->ready()) {
            throw new \RuntimeException('READY gate TLS session-cache channel warmup failed.');
        }
        \Weline\Server\Service\Runtime\WorkerReadinessState::markMaintenanceReady();
        $readyGateWorkerBootstrapWarmupCompleted = true;
        return;
    }
    if ($runtimeError !== null || !$runtime instanceof \Weline\Framework\Runtime\WlsRuntime) {
        return;
    }

    WlsLogger::info_("[WorkerWarmup] ready-gate bootstrap warmup start worker={$workerId}");
    $wlsReadyGateStageEnabled = (bool)($wlsEnv['debug']['worker_startup_trace'] ?? false)
        || \in_array(
            \strtolower(\trim((string)(\getenv('WLS_WORKER_STARTUP_TRACE') ?: ''))),
            ['1', 'true', 'yes', 'on'],
            true
        );
    $wlsReadyGateStage = static function (string $stage, array $context = []) use ($workerId, $wlsReadyGateStageEnabled): void {
        if (!$wlsReadyGateStageEnabled) {
            return;
        }
        $row = [
            'ts' => \date('c'),
            'pid' => \getmypid(),
            'worker_id' => $workerId,
            'stage' => $stage,
            'data' => $context,
        ];
        @\file_put_contents(
            BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'wls-ready-gate-stage.log',
            (\json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . PHP_EOL,
            FILE_APPEND
        );
    };
    $wlsReadyGateStage('pool_warmup_begin');
    $poolWarmup = \Weline\Server\Service\SharedRuntimeConnectionWarmup::warmReadyMemory(
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
    $wlsReadyGateStage('pool_warmup_done', [
        'errors' => $poolWarmupErrors,
        'skipped' => $poolWarmup['skipped'] ?? null,
    ]);
    if ($poolWarmupErrors !== []) {
        throw new \RuntimeException(
            'READY gate memory connection warmup failed: '
            . (\json_encode($poolWarmupErrors, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}')
        );
    }
    $wlsReadyGateStage('tls_session_cache_check_begin', [
        'runtime_present' => $wlsTlsSessionCacheRuntime !== null,
    ]);
    if ($wlsTlsSessionCacheRuntime !== null && !$wlsTlsSessionCacheRuntime->ready()) {
        throw new \RuntimeException('READY gate TLS session-cache channel warmup failed.');
    }
    $wlsReadyGateStage('tls_session_cache_check_done');
    $readyGateSharedRuntimeConnectionWarmupCompleted = true;
    $wlsReadyGateStage('homepage_fpc_retry_begin');
    [$homepageFpcProof, $dynamicFirstRenderProof]
        = \Weline\Server\Service\Runtime\WorkerReadyGateRetry::run(
            static function () use ($runtime, $wlsReadyGateStage): array {
                $wlsReadyGateStage('homepage_fpc_runtime_begin');
                $homepage = $runtime->runReadyGateWorkerBootstrapWarmup();
                $wlsReadyGateStage('homepage_fpc_runtime_done', [
                    'http_status' => (int)($homepage['http_status'] ?? 0),
                    'hit' => (bool)($homepage['hit'] ?? false),
                    'reason' => (string)($homepage['reason'] ?? ''),
                ]);
                $dynamic = $runtime->readyGateDynamicFirstRenderProof();
                $wlsReadyGateStage('dynamic_first_render_proof_done');
                return [$homepage, $dynamic];
            },
            $workerId,
            static function (
                int $attempt,
                \Throwable $throwable,
                int $delay,
            ) use ($workerId, $wlsReadyGateStage): void {
                $wlsReadyGateStage('homepage_fpc_retry', [
                    'attempt' => $attempt,
                    'delay_us' => $delay,
                    'error' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
                WlsLogger::warning_(
                    '[WorkerWarmup] transient database contention; retrying READY gate '
                    . "worker={$workerId}, attempt={$attempt}, delay_us={$delay}, "
                    . 'error=' . $throwable::class
                );
            },
        );
    $wlsReadyGateStage('homepage_fpc_retry_done', [
        'http_status' => (int)($homepageFpcProof['http_status'] ?? 0),
        'hit' => (bool)($homepageFpcProof['hit'] ?? false),
    ]);
    \Weline\Server\Service\Runtime\WorkerReadinessState::markBusinessHomepageHot($homepageFpcProof);
    \Weline\Server\Service\Runtime\WorkerReadinessState::markDynamicFirstRenderProof(
        $dynamicFirstRenderProof
    );
    $readyGateWorkerBootstrapWarmupCompleted = true;
    $wlsReadyGateStage('ready_gate_warmup_complete');
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
$lastMemoryCheck = wlsWorkerMonotonicNow();
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
    $memoryGuardConfig['worker_memory_drain_threshold'] ?? 0.92,
    0.92
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
$configuredMaxRequests = $wlsEnv['worker_max_requests']
    ?? $wlsEnv['max_request']
    ?? 0;
$maxRequestsBase = \is_numeric($configuredMaxRequests) ? \max(0, (int)$configuredMaxRequests) : 0;
$configuredRecycleStagger = $wlsEnv['worker_recycle_stagger_requests'] ?? 10000;
$recycleStaggerRequests = \is_numeric($configuredRecycleStagger)
    ? \max(0, (int)$configuredRecycleStagger)
    : 10000;
$maxRequests = $maxRequestsBase > 0
    ? $maxRequestsBase + (\max(0, $workerId - 1) * $recycleStaggerRequests)
    : 0;
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
$ipcRole = $isMaintenanceWorker
    ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE
    : ($isGatewayFallbackWorker
        ? \Weline\Server\IPC\ControlMessage::ROLE_GATEWAY_FALLBACK
        : \Weline\Server\IPC\ControlMessage::ROLE_WORKER);
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);
$servingManifestReloadError = '';

if ($controlPort > 0 || $supervisorEnabled) {
    $wlsStartupTrace('ipc_connect_begin', ['control_port' => $controlPort]);
    $ipcSelfTag = ($isMaintenanceWorker
        ? 'Maintenance'
        : ($isGatewayFallbackWorker ? 'GatewayFallback' : 'Worker')) . "#{$workerId}";
    $identity = new \Weline\Server\IPC\ChildControl\ChildProcessIdentity(
        $ipcRole,
        \getmypid(),
        $port,
        $workerId,
        $orchestratorEpoch,
        $orchestratorLaunchId
    );
    $handler = new \Weline\Server\IPC\ChildControl\Handler\WorkerSslControlHandler(
        static function (array $msg) use (&$shouldExit, &$ipcDraining, &$ipcReceivedShutdown, &$socket, &$drainStartTime, &$maxDrainTime, &$waitingForAck, $workerId, &$sniServerCerts, &$ipcClient, &$kernel, $isMaintenanceWorker, $isGatewayFallbackWorker, &$gatewayFallbackListenerState, &$gatewayFallbackListenerDraining, &$gatewayFallbackDrainAcknowledged, &$gatewayFallbackDrainTransition, &$gatewayFallbackUndrainTransition, &$gatewayFallbackRetiredTransitions, $gatewayFallbackExpectedTransitionIdentity, &$activeFibers, $fiberScheduler, &$activeRequests, &$fiberIdleTtlSec, &$fiberMaxActive, &$fiberReleaseIdleRequested, $port, &$deferSslOptions, &$sslCert, &$sslKey, $cryptoMethod, $instanceName, $listenerHost, $wlsRuntimeTopology, &$cacheClearEpoch, $maintenanceDrainState, $wlsHttp3Enabled, $wlsHttp3Mode, $wlsHttp3ExpectedNativeDigest, $wlsHttp3RouteSlot, $wlsHttp3RouteCount, $wlsHttp3RouteOwnerEpoch, $wlsHttp3RouteGeneration, $wlsHttp3RouteNamespace, $wlsHttp3RouteEligible, $orchestratorEpoch, $orchestratorLaunchId, $orchestratorSlotId, $orchestratorLeaseId, $orchestratorGeneration, &$http3Runtime, &$http3ActivationId, &$http3RouteActivationReceiptSent, &$http3AvailabilityEpoch, &$http3AvailabilityRouteEpoch, &$http3AvailabilitySignature, &$http3AvailabilityEnabled, $wlsTlsSessionCacheRuntime, &$servingManifestRoutes, &$servingManifestHttpRoutes, &$servingManifestPath, &$servingManifestGeneration, &$servingManifestDigest, &$servingManifestReloadError, $servingInstanceGeneration, $masterPid, &$connections, &$connectionPeerIps, &$requestBuffers, &$connectionLastActivity, &$requestLogged, &$writeBuffers, &$writableConnections, &$writeZeroProgress, &$connectionProtocols, &$connectionSniHosts, &$connectionPlaintextHosts, &$http2ConnectionAdapters, &$http2PendingRequests, &$pendingPeek, &$pendingPeekStartTimes, &$pendingHandshakes, &$postHandshakeReadPending, &$pendingClose, &$handshakeStartTimes, &$longLivedConnections): void {
            $type = $msg['type'] ?? '';
            // 帝王令：shutdown 至高无上，一旦收到则不再处理其他 IPC（RELOAD/DRAIN/CACHE_CLEAR）
            if ($type !== \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN && $ipcReceivedShutdown) {
                return;
            }
            switch ($type) {
                case \Weline\Server\IPC\ControlMessage::TYPE_PING:
                    $stats = [
                        'active_fibers' => \count($activeFibers),
                        'memory_usage' => \memory_get_usage(true),
                        'serving_manifest_generation' => $servingManifestGeneration,
                        'serving_manifest_digest' => $servingManifestDigest,
                        'serving_manifest_route_count' => \count($servingManifestRoutes),
                        'tls_context_state' => $servingManifestRoutes === []
                            ? 'disabled'
                            : 'active',
                        'serving_manifest_reload_error' => $servingManifestReloadError,
                    ];
                    if ($ipcClient !== null && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::pongForPing($msg, $stats));
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_ACK_READY:
                case \Weline\Server\IPC\ControlMessage::TYPE_READY_ACK:
                    $accepted = !\array_key_exists('accepted', $msg) || (bool)($msg['accepted'] ?? false);
                    $reason = !$accepted ? (string)($msg['reason'] ?? 'ready_rejected') : '';
                    $rejectReady = static function (string $rejectReason, array $diagnostic = []) use (
                        &$waitingForAck,
                        &$shouldExit,
                        &$ipcDraining,
                        &$maxDrainTime,
                        &$drainStartTime,
                        &$socket,
                        &$ipcClient,
                    ): void {
                        $waitingForAck = false;
                        $shouldExit = true;
                        $ipcDraining = true;
                        $maxDrainTime = 1;
                        $drainStartTime = (\hrtime(true) / 1_000_000_000) - $maxDrainTime;
                        if ($socket && \is_resource($socket)) {
                            @\fclose($socket);
                            $socket = null;
                            \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                        }
                        if ($ipcClient !== null && $ipcClient->isConnected()) {
                            $ipcClient->send(\Weline\Server\IPC\ControlMessage::exitReason(
                                'master_rejected_ready:' . $rejectReason,
                                0,
                                $diagnostic,
                            ));
                        }
                        WlsLogger::warning_("Master ACK 确认结果：失败（reason={$rejectReason}），SSL Worker 自毁退出");
                    };
                    if ($reason !== '') {
                        $rejectReady($reason);
                        break;
                    }

                    $ackWorkerId = (int)($msg['worker_id'] ?? 0);
                    $dispatcherConfirmed = (bool)($msg['dispatcher_confirmed'] ?? false);
                    $ackPort = (int)($msg['port'] ?? 0);
                    $ackMsgId = \trim((string)($msg['msg_id'] ?? ''));
                    $ackSlotId = \trim((string)($msg['slot_id'] ?? ''));
                    $ackLeaseId = \trim((string)($msg['lease_id'] ?? ''));
                    $ackGeneration = (int)($msg['generation'] ?? 0);
                    $readyPhase = \strtolower(\trim((string)($msg['ready_phase'] ?? 'final')));
                    $identityValid = $ackWorkerId === $workerId
                        && $ackPort === $port
                        && $ackMsgId !== ''
                        && \hash_equals($orchestratorLaunchId, $ackMsgId)
                        && $ackSlotId !== ''
                        && \hash_equals($orchestratorSlotId, $ackSlotId)
                        && $ackLeaseId !== ''
                        && \hash_equals($orchestratorLeaseId, $ackLeaseId)
                        && $ackGeneration === $orchestratorGeneration;
                    if (!$identityValid) {
                        $identityDiagnostic = [
                            'ack_worker_id' => $ackWorkerId,
                            'expected_worker_id' => $workerId,
                            'ack_port' => $ackPort,
                            'expected_port' => $port,
                            'ack_generation' => $ackGeneration,
                            'expected_generation' => $orchestratorGeneration,
                            'msg_id_match' => $ackMsgId !== ''
                                && \hash_equals($orchestratorLaunchId, $ackMsgId),
                            'slot_id_match' => $ackSlotId !== ''
                                && \hash_equals($orchestratorSlotId, $ackSlotId),
                            'lease_id_match' => $ackLeaseId !== ''
                                && \hash_equals($orchestratorLeaseId, $ackLeaseId),
                        ];
                        WlsLogger::warning_(
                            'Master READY ACK identity mismatch '
                            . (\json_encode($identityDiagnostic, JSON_UNESCAPED_SLASHES) ?: '{}')
                        );
                        $rejectReady('ready_ack_identity_mismatch', $identityDiagnostic);
                        break;
                    }

                    if ($wlsHttp3Enabled && $wlsHttp3Mode === 'reuseport-ebpf') {
                        $routeCommand = \is_array($msg['http3_route'] ?? null)
                            ? $msg['http3_route']
                            : [];
                        $action = \strtolower(\trim((string)($routeCommand['action'] ?? '')));
                        $activationId = \strtolower(\trim((string)(
                            $routeCommand['activation_id'] ?? $msg['activation_id'] ?? ''
                        )));
                        if ($readyPhase === 'activate') {
                            $namespaceDigest = \hash('sha256', $wlsHttp3RouteNamespace);
                            $routeIdentityValid = $action === 'activate'
                                && $wlsHttp3RouteEligible
                                && \preg_match('/^[a-f0-9]{64}$/D', $activationId) === 1
                                && (int)($routeCommand['slot'] ?? -1) === $wlsHttp3RouteSlot
                                && (int)($routeCommand['slot_count'] ?? 0) === $wlsHttp3RouteCount
                                && (int)($routeCommand['owner_epoch'] ?? 0) === $wlsHttp3RouteOwnerEpoch
                                && (int)($routeCommand['generation'] ?? 0) === $wlsHttp3RouteGeneration
                                && \hash_equals(
                                    $wlsHttp3ExpectedNativeDigest,
                                    \strtolower(\trim((string)($routeCommand['native_digest'] ?? ''))),
                                )
                                && \hash_equals(
                                    $namespaceDigest,
                                    \strtolower(\trim((string)($routeCommand['namespace_digest'] ?? ''))),
                                );
                            if (!$routeIdentityValid
                                || !$http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime
                                || ($http3ActivationId !== '' && !\hash_equals($http3ActivationId, $activationId))
                            ) {
                                $rejectReady('http3_route_activation_identity_mismatch');
                                break;
                            }
                            try {
                                $routeStatus = $http3Runtime->isLinuxRouteActivated()
                                    ? $http3Runtime->linuxRouteStatus()
                                    : $http3Runtime->activateLinuxRoute();
                                \Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3LinuxRouteActivated(
                                    $routeStatus,
                                    $activationId,
                                );
                                $http3ActivationId = $activationId;
                                $currentIpcClient = $kernel instanceof \Weline\Server\IPC\ChildControl\SubprocessControlKernel
                                    ? $kernel->getClient()
                                    : $ipcClient;
                                if ($currentIpcClient !== null) {
                                    $ipcClient = $currentIpcClient;
                                }
                                $http3RouteActivationReceiptSent = $currentIpcClient !== null
                                    && $currentIpcClient->isConnected()
                                    && $currentIpcClient->send(\Weline\Server\IPC\ControlMessage::http3RouteActivated(
                                        $workerId,
                                        $port,
                                        $orchestratorLaunchId,
                                        $orchestratorSlotId,
                                        $orchestratorLeaseId,
                                        $orchestratorGeneration,
                                        $wlsHttp3RouteOwnerEpoch,
                                        $activationId,
                                        $wlsHttp3ExpectedNativeDigest,
                                        $routeStatus,
                                    ));
                            } catch (\Throwable $throwable) {
                                $rejectReady('http3_route_activation_failed:' . $throwable->getMessage());
                                break;
                            }
                            WlsLogger::info_(
                                '[HTTP3] Linux eBPF route activated; waiting for final READY ACK'
                                . ' slot=' . $wlsHttp3RouteSlot
                                . ' generation=' . $wlsHttp3RouteGeneration
                                . ' receipt=' . ($http3RouteActivationReceiptSent ? 'sent' : 'pending')
                            );
                            break;
                        }

                        $finalValid = $readyPhase === 'final'
                            && (($wlsHttp3RouteEligible
                                && $http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime
                                && $http3Runtime->isLinuxRouteActivated()
                                && $http3ActivationId !== ''
                                && \hash_equals($http3ActivationId, $activationId))
                                || (!$wlsHttp3RouteEligible && $action === 'hold'));
                        if (!$finalValid) {
                            $rejectReady('http3_final_ready_ack_invalid');
                            break;
                        }
                    } elseif ($readyPhase !== 'final') {
                        $rejectReady('unexpected_ready_ack_phase');
                        break;
                    }

                    $waitingForAck = false;
                    WlsLogger::info_(
                        "收到 Master 最终 READY 确认 (worker_id={$ackWorkerId}, dispatcher_confirmed="
                        . ($dispatcherConfirmed ? '1' : '0') . ", port={$ackPort})，SSL Worker 开放请求准入"
                    );
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_HTTP3_AVAILABILITY:
                    if (!$wlsHttp3Enabled || $isMaintenanceWorker) {
                        break;
                    }
                    $availabilityEpoch = (int)($msg['availability_epoch'] ?? 0);
                    $availabilityEnabled = (bool)($msg['enabled'] ?? false);
                    $availabilityPort = (int)($msg['port'] ?? 0);
                    $ownerEpoch = (int)($msg['owner_epoch'] ?? 0);
                    $routeEpoch = (int)($msg['route_epoch'] ?? 0);
                    $nativeDigest = \strtolower(\trim((string)($msg['native_digest'] ?? '')));
                    $signature = \hash('sha256', (string)\json_encode([
                        $availabilityEpoch,
                        $availabilityEnabled,
                        $availabilityPort,
                        $ownerEpoch,
                        $routeEpoch,
                        $nativeDigest,
                    ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
                    if ($availabilityEpoch < $http3AvailabilityEpoch) {
                        WlsLogger::warning_(
                            '[HTTP3] ignored stale availability epoch=' . $availabilityEpoch
                            . ', current=' . $http3AvailabilityEpoch
                        );
                        break;
                    }
                    $invalidAvailability = $availabilityEpoch <= 0
                        || $ownerEpoch !== $orchestratorEpoch
                        || $routeEpoch <= 0
                        || !\hash_equals($wlsHttp3ExpectedNativeDigest, $nativeDigest)
                        || ($availabilityEnabled && ($availabilityPort !== $port || $waitingForAck))
                        || (!$availabilityEnabled && $availabilityPort !== 0)
                        || ($availabilityEpoch === $http3AvailabilityEpoch
                            && $http3AvailabilitySignature !== ''
                            && !\hash_equals($http3AvailabilitySignature, $signature));
                    if ($invalidAvailability) {
                        \Weline\Server\Protocol\Http3\AltSvcResponsePolicy::configure(false, 0);
                        \Weline\Server\Protocol\Http3\WorkerQuicRuntime::shutdownActive();
                        \Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3Closed();
                        $http3Runtime = null;
                        $http3AvailabilityEnabled = false;
                        $shouldExit = true;
                        $ipcDraining = true;
                        $maxDrainTime = \min($maxDrainTime, 3);
                        $drainStartTime = \hrtime(true) / 1_000_000_000;
                        if ($socket && \is_resource($socket)) {
                            @\fclose($socket);
                            $socket = null;
                            \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                        }
                        WlsLogger::error_(
                            '[HTTP3] rejected invalid/conflicting availability; Worker is draining'
                        );
                        break;
                    }

                    if ($availabilityEnabled
                        && !($http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime)
                    ) {
                        $shouldExit = true;
                        $ipcDraining = true;
                        $drainStartTime = \hrtime(true) / 1_000_000_000;
                        WlsLogger::error_('[HTTP3] availability enabled without a live native runtime');
                        break;
                    }
                    \Weline\Server\Protocol\Http3\AltSvcResponsePolicy::configure(
                        $availabilityEnabled,
                        $availabilityEnabled ? $availabilityPort : 0,
                        300,
                        $availabilityEnabled
                            ? \Weline\Server\Protocol\Http3\WorkerQuicRuntime::certificateHostPatterns($sslCert)
                            : [],
                    );
                    $http3AvailabilityEpoch = $availabilityEpoch;
                    $http3AvailabilityRouteEpoch = $routeEpoch;
                    $http3AvailabilitySignature = $signature;
                    $http3AvailabilityEnabled = $availabilityEnabled;
                    WlsLogger::info_(
                        '[HTTP3] availability applied enabled=' . ($availabilityEnabled ? '1' : '0')
                        . ', epoch=' . $availabilityEpoch
                        . ', route_epoch=' . $routeEpoch
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
                    $drainStartTime = \hrtime(true) / 1_000_000_000;
                    $dt = (int) ($msg['drain_timeout_sec'] ?? 0);
                    $maxDrainTime = $dt > 0 ? \max(1, \min(7200, $dt)) : 120;
                    if ($http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime) {
                        try {
                            $http3Runtime->beginDrain();
                        } catch (\Throwable $exception) {
                            WlsLogger::error_('[HTTP3] failed to start graceful reload drain: '
                                . $exception->getMessage());
                        }
                    }
                    // 关闭监听 socket（不再接受新连接）
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                    }
                    WlsLogger::info_("收到 reload 命令，已清除 opcache 并关闭监听 socket，开始排水（最多等待 {$maxDrainTime} 秒）...");
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_CACHE_NAMESPACE_INVALIDATE_V1:
                    \Weline\Server\Service\Runtime\WorkerNamespaceInvalidationControlHandler::handle(
                        $msg,
                        $ipcClient,
                        $isMaintenanceWorker
                            ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE
                            : \Weline\Server\IPC\ControlMessage::ROLE_WORKER,
                        $workerId,
                    );
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_MEMORY_PRESSURE:
                    $hostLevel = (string)($msg['level'] ?? 'green');
                    $staggerMs = \max(0, (int)($msg['stagger_ms'] ?? 0));
                    $slotDelay = $staggerMs > 0 ? ($workerId * $staggerMs) : 0;
                    \Weline\Server\Service\Memory\WorkerHostPressureApplier::apply($hostLevel, $slotDelay);
                    $reclaimBytes = \Weline\Server\Service\Memory\WorkerHostPressureApplier::consumeReclaimBytes();
                    $ipcClient?->send(\Weline\Server\IPC\ControlMessage::memoryReclaimReport(
                        $reclaimBytes,
                        \Weline\Server\Service\Memory\WorkerHostPressureApplier::getHostLevel(),
                        [
                            'worker_id' => $workerId,
                            'skip_count' => \Weline\Server\Service\Memory\WorkerHostPressureApplier::getReclaimSkipCount(),
                        ]
                    ));
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
                    $reloadOperationId = \strtolower(\trim((string)($msg['operation_id'] ?? '')));
                    $expectedManifestGeneration = (int)($msg['expected_manifest_generation'] ?? 0);
                    $expectedManifestDigest = \strtolower(\trim((string)(
                        $msg['expected_manifest_digest'] ?? ''
                    )));
                    $expectedTlsRouteCount = (int)(
                        $msg['expected_tls_route_count'] ?? -1
                    );
                    $expectedRetiredContextCount = (int)(
                        $msg['expected_retired_context_count'] ?? -1
                    );
                    $expectedRetiredContextDigest = \strtolower(\trim((string)(
                        $msg['expected_retired_context_digest'] ?? ''
                    )));
                    $reloadListenerMutationStarted = false;
                    $reloadStateCommitted = false;
                    $emptyRetirementFacts = wlsServingManifestRetirementFacts([], []);
                    $retiredContextCount = (int)$emptyRetirementFacts['count'];
                    $retiredContextDigest = (string)$emptyRetirementFacts['digest'];
                    try {
                        if (\preg_match('/\A[a-f0-9]{32}\z/D', $reloadOperationId) !== 1
                            || $expectedManifestGeneration < 1
                            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedManifestDigest) !== 1
                            || $expectedTlsRouteCount < 0
                            || $expectedTlsRouteCount
                                > \Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore::MAX_ROUTES
                            || $expectedRetiredContextCount < 0
                            || $expectedRetiredContextCount
                                > \Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore::MAX_ROUTES
                            || \preg_match(
                                '/\A[a-f0-9]{64}\z/D',
                                $expectedRetiredContextDigest,
                            ) !== 1
                        ) {
                            throw new \RuntimeException(
                                'SSL reload request is missing an exact operation/manifest fence.',
                            );
                        }
                        $currentManifest = (new \Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore(
                            (string)BP,
                        ))->currentForFence([
                            'instance_id' => $instanceName,
                            'instance_generation' => $servingInstanceGeneration,
                            'master_pid' => $masterPid,
                            'master_epoch' => $orchestratorEpoch,
                        ]);
                        if ((int)$currentManifest['generation'] !== $expectedManifestGeneration
                            || !\hash_equals(
                                $expectedManifestDigest,
                                (string)$currentManifest['digest'],
                            )
                        ) {
                            throw new \RuntimeException(
                                'Current serving manifest no longer matches the requested reload fence.',
                            );
                        }
                        $nextSnapshot = wlsLoadServingManifestSnapshot(
                            (string)$currentManifest['path'],
                            $expectedManifestGeneration,
                            $expectedManifestDigest,
                            $instanceName,
                            $servingInstanceGeneration,
                            $masterPid,
                            $orchestratorEpoch,
                        );
                        $nextCerts = $nextSnapshot['certs'];
                        $nextRoutes = $nextSnapshot['routes'];
                        $nextHttpRoutes = $nextSnapshot['http_routes'];
                        if (\count($nextRoutes) !== $expectedTlsRouteCount) {
                            throw new \RuntimeException(
                                'Replacement serving manifest TLS route count changed.',
                            );
                        }
                        $nextDefault = \reset($nextCerts);
                        $nextSslCert = \is_array($nextDefault)
                            ? (string)$nextDefault['local_cert']
                            : '';
                        $nextSslKey = \is_array($nextDefault)
                            ? (string)$nextDefault['local_pk']
                            : '';
                        if (($nextSslCert === '') !== ($nextSslKey === '')
                            || ($expectedTlsRouteCount > 0
                                && ($nextSslCert === '' || $nextSslKey === ''))
                        ) {
                            throw new \RuntimeException(
                                'Replacement serving manifest TLS context is incomplete.',
                            );
                        }
                        $retirementFacts = wlsServingManifestRetirementFacts(
                            $servingManifestRoutes,
                            $nextRoutes,
                        );
                        $retiredContextCount = (int)$retirementFacts['count'];
                        $retiredContextDigest = (string)$retirementFacts['digest'];
                        if ($retiredContextCount !== $expectedRetiredContextCount
                            || !\hash_equals(
                                $expectedRetiredContextDigest,
                                $retiredContextDigest,
                            )
                        ) {
                            throw new \RuntimeException(
                                'Replacement serving manifest retirement fence changed.',
                            );
                        }
                        if ($wlsTlsSessionCacheRuntime !== null) {
                            // Context keys bind SNI + certificate digest. Stage
                            // the complete replacement without evicting the
                            // live generation, so any preflight failure leaves
                            // the old TLS/session context cache untouched.
                            foreach ($nextCerts as $tlsSessionSni => $tlsSessionPair) {
                                if (\is_string($tlsSessionSni) && \is_array($tlsSessionPair)) {
                                    $wlsTlsSessionCacheRuntime->streamContextOptions(
                                        $tlsSessionSni,
                                        $tlsSessionPair,
                                    );
                                }
                            }
                        }
                        // All disk/file/policy/TLS-context preflight above
                        // completed before any live routing state changes. The
                        // event loop is single-threaded, so this is one switch.
                        // The copied listener context is updated first and its
                        // return value is authoritative; a suppressed option
                        // failure must never produce a successful reload ACK.
                        $contextRollbackSucceeded = false;
                        if (!wlsSslApplySniOptionsToContexts(
                            $deferSslOptions,
                            $socket,
                            $nextCerts,
                            $nextSslCert,
                            $nextSslKey,
                            $cryptoMethod,
                            $contextRollbackSucceeded,
                        )) {
                            if (!$contextRollbackSucceeded) {
                                // A partially changed listener which could not
                                // restore its LKG is removed from service. IPC
                                // remains alive long enough to return failure.
                                if ($socket && \is_resource($socket)) {
                                    @\fclose($socket);
                                    $socket = null;
                                    \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                                }
                                $shouldExit = true;
                                $ipcDraining = true;
                                $drainStartTime = \hrtime(true) / 1_000_000_000;
                            }
                            throw new \RuntimeException(
                                $contextRollbackSucceeded
                                    ? 'TLS listener rejected the replacement context; LKG restored.'
                                    : 'TLS listener rejected the replacement context and LKG rollback failed.',
                            );
                        }
                        $reloadListenerMutationStarted = true;
                        $retiredRouteDomains = $retirementFacts['domains'];
                        if ($retiredRouteDomains !== []) {
                            // A partial multi-domain revocation has the same
                            // connection-level security boundary as an empty
                            // tombstone. Resolve each live SNI through the old
                            // manifest (including wildcard routes), then retire
                            // only connections belonging to removed routes.
                            $retiredConnectionIds = [];
                            foreach ($connectionSniHosts as $connectionId => $sniHost) {
                                $oldRoute = wlsServingManifestRouteForHost(
                                    (string)$sniHost,
                                    $servingManifestRoutes,
                                );
                                $oldDomain = \is_array($oldRoute)
                                    ? (string)($oldRoute['domain'] ?? '')
                                    : '';
                                if ($oldDomain !== '' && isset($retiredRouteDomains[$oldDomain])) {
                                    $retiredConnectionIds[(int)$connectionId] = true;
                                }
                            }
                            // Every accepted or handshaking TLS stream which
                            // could already own an SSL_CTX must have immutable
                            // SNI provenance. Missing provenance makes a
                            // selective revocation unverifiable and therefore
                            // fail-stops this Worker before ACK.
                            foreach (\array_keys($connections + $pendingHandshakes) as $connectionId) {
                                if (!\array_key_exists((int)$connectionId, $connectionSniHosts)
                                    && !\array_key_exists(
                                        (int)$connectionId,
                                        $connectionPlaintextHosts,
                                    )
                                ) {
                                    throw new \RuntimeException(
                                        'Selective TLS route retirement found a stream without SNI provenance.',
                                    );
                                }
                            }
                            foreach (\array_keys($retiredConnectionIds) as $retiredConnectionId) {
                                wlsCancelActiveFibersForConnection(
                                    $activeFibers,
                                    (int)$retiredConnectionId,
                                    $fiberScheduler,
                                    $activeRequests,
                                );
                                $retiredConnection = $connections[$retiredConnectionId]
                                    ?? ($pendingHandshakes[$retiredConnectionId]['conn'] ?? null)
                                    ?? ($pendingPeek[$retiredConnectionId]['conn'] ?? null);
                                if (\is_resource($retiredConnection)) {
                                    safeCloseStream($retiredConnection);
                                }
                                unset(
                                    $connections[$retiredConnectionId],
                                    $connectionPeerIps[$retiredConnectionId],
                                    $requestBuffers[$retiredConnectionId],
                                    $connectionLastActivity[$retiredConnectionId],
                                    $requestLogged[$retiredConnectionId],
                                    $writeBuffers[$retiredConnectionId],
                                    $writableConnections[$retiredConnectionId],
                                    $writeZeroProgress[$retiredConnectionId],
                                    $connectionProtocols[$retiredConnectionId],
                                    $connectionSniHosts[$retiredConnectionId],
                                    $http2ConnectionAdapters[$retiredConnectionId],
                                    $http2PendingRequests[$retiredConnectionId],
                                    $pendingPeek[$retiredConnectionId],
                                    $pendingPeekStartTimes[$retiredConnectionId],
                                    $pendingHandshakes[$retiredConnectionId],
                                    $postHandshakeReadPending[$retiredConnectionId],
                                    $pendingClose[$retiredConnectionId],
                                    $handshakeStartTimes[$retiredConnectionId],
                                    $longLivedConnections[$retiredConnectionId],
                                );
                                if (\Weline\Server\Protocol\Http2\MultiplexScheduler::keysForConnection(
                                    $activeFibers,
                                    (int)$retiredConnectionId,
                                ) !== []) {
                                    throw new \RuntimeException(
                                        'Selective TLS route retirement left an active request Fiber.',
                                    );
                                }
                            }
                            foreach ($connectionSniHosts as $sniHost) {
                                $oldRoute = wlsServingManifestRouteForHost(
                                    (string)$sniHost,
                                    $servingManifestRoutes,
                                );
                                if (\is_array($oldRoute)
                                    && isset($retiredRouteDomains[(string)($oldRoute['domain'] ?? '')])
                                ) {
                                    throw new \RuntimeException(
                                        'Selective TLS route retirement left an old SNI context reachable.',
                                    );
                                }
                            }
                        }
                        if ($nextRoutes === []) {
                            // Native H3 is currently disabled for manifest SNI
                            // isolation. If it ever appears here without a
                            // connection provenance map, fail-stop rather than
                            // acknowledge an unverifiable retired QUIC context.
                            if ($http3Runtime !== null) {
                                throw new \RuntimeException(
                                    'Neutral TLS manifest found an unverifiable active H3 runtime.',
                                );
                            }
                            \Weline\Server\Protocol\Http3\AltSvcResponsePolicy::configure(false, 0);
                            \Weline\Server\Protocol\Http3\WorkerQuicRuntime::shutdownActive();
                            \Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3Closed();
                            $http3Runtime = null;
                            $http3AvailabilityEnabled = false;
                        }
                        $sniServerCerts = $nextCerts;
                        $servingManifestRoutes = $nextRoutes;
                        $servingManifestHttpRoutes = $nextHttpRoutes;
                        \Weline\Server\Service\WlsWorkerGlobals::setDomainPolicies(
                            $nextSnapshot['policies'],
                        );
                        $sslCert = $nextSslCert;
                        $sslKey = $nextSslKey;
                        $servingManifestPath = (string)$currentManifest['path'];
                        $servingManifestGeneration = (int)$currentManifest['generation'];
                        $servingManifestDigest = (string)$currentManifest['digest'];
                        \Weline\Server\Service\Runtime\WorkerReadinessState::markServingManifest(
                            $servingManifestGeneration,
                            $servingManifestDigest,
                            \count($servingManifestRoutes),
                        );
                        $wlsTlsSessionCacheRuntime?->clearContextCache();
                        $servingManifestReloadError = '';
                        $reloadStateCommitted = true;
                        $ipcClient?->send(\Weline\Server\IPC\ControlMessage::sslCertReloadAck(
                            $reloadOperationId,
                            true,
                            $servingManifestGeneration,
                            $servingManifestDigest,
                            \count($servingManifestRoutes),
                            $servingManifestRoutes === [] ? 'neutral' : 'routes',
                            $servingManifestRoutes === [] ? 'disabled' : 'active',
                            $retiredContextCount,
                            $retiredContextDigest,
                            $workerId,
                        ));
                        WlsLogger::info_('已原子切换 serving manifest generation='
                            . $servingManifestGeneration . '，routes='
                            . \count($servingManifestRoutes));
                    } catch (\Throwable $throwable) {
                        if ($reloadListenerMutationStarted && !$reloadStateCommitted) {
                            // Once the listener SSL_CTX has changed there is no
                            // safe in-process rollback for already accepted
                            // streams, H3 state, policy globals, and the TLS
                            // session cache as one unit. Remove this process
                            // from service and let Master replace the exact
                            // frozen identity instead of serving a mixed era.
                            if ($socket && \is_resource($socket)) {
                                @\fclose($socket);
                                $socket = null;
                            }
                            \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                            $shouldExit = true;
                            $ipcDraining = true;
                            $drainStartTime = \hrtime(true) / 1_000_000_000;
                        }
                        // Invalid new facts never replace the live in-memory LKG.
                        $servingManifestReloadError = \substr(
                            \str_replace(["\r", "\n"], ' ', $throwable->getMessage()),
                            0,
                            512,
                        );
                        if ($reloadStateCommitted) {
                            // Delivery/logging failed after the exact in-memory
                            // commit. Never lie with a rejection receipt; the
                            // Master will timeout/retry and revalidate the same
                            // immutable generation.
                            try {
                                $ipcClient?->send(\Weline\Server\IPC\ControlMessage::sslCertReloadAck(
                                    $reloadOperationId,
                                    true,
                                    $servingManifestGeneration,
                                    $servingManifestDigest,
                                    \count($servingManifestRoutes),
                                    $servingManifestRoutes === [] ? 'neutral' : 'routes',
                                    $servingManifestRoutes === [] ? 'disabled' : 'active',
                                    $retiredContextCount ?? 0,
                                    $retiredContextDigest
                                        ?? (string)$emptyRetirementFacts['digest'],
                                    $workerId,
                                ));
                            } catch (\Throwable) {
                                // The frozen Master transaction remains red
                                // without a delivered success receipt.
                            }
                        } else {
                            try {
                                $ipcClient?->send(\Weline\Server\IPC\ControlMessage::sslCertReloadAck(
                                    $reloadOperationId,
                                    false,
                                    $servingManifestGeneration,
                                    $servingManifestDigest,
                                    \count($servingManifestRoutes),
                                    $servingManifestRoutes === [] ? 'neutral' : 'routes',
                                    $servingManifestRoutes === [] ? 'disabled' : 'active',
                                    $retiredContextCount,
                                    $retiredContextDigest,
                                    $workerId,
                                    $reloadListenerMutationStarted
                                        ? 'reload_partial_commit_fail_closed'
                                        : 'reload_rejected',
                                    $servingManifestReloadError,
                                ));
                            } catch (\Throwable) {
                                // Master observes timeout/disconnect as a
                                // terminal failure for the frozen identity.
                            }
                        }
                        WlsLogger::error_('serving manifest 重载被拒绝，保留当前代：'
                            . $throwable->getMessage());
                    }
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
                    if ($isGatewayFallbackWorker
                        && \hash_equals(
                            \Weline\Server\IPC\ControlMessage::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
                            (string)($msg['protocol'] ?? ''),
                        )
                    ) {
                        try {
                            $transition = \Weline\Server\IPC\ControlMessage::
                                validateGatewayFallbackListenerTransition($msg);
                            $result = wlsApplyGatewayFallbackListenerTransition(
                                $transition,
                                $gatewayFallbackExpectedTransitionIdentity,
                                $gatewayFallbackListenerState,
                                $gatewayFallbackListenerDraining,
                                $shouldExit
                                    || $ipcReceivedShutdown
                                    || !$socket
                                    || !\is_resource($socket),
                                true,
                                $gatewayFallbackDrainAcknowledged,
                                $gatewayFallbackDrainTransition,
                                $gatewayFallbackUndrainTransition,
                                $gatewayFallbackRetiredTransitions,
                            );
                            $ackDelivery = wlsSendGatewayFallbackListenerAck(
                                $ipcClient,
                                $transition,
                                $result['listener_state'],
                                $result['success'],
                                $result['reason'],
                            );
                            if ($result['success'] && $ackDelivery['enqueued']) {
                                $gatewayFallbackDrainAcknowledged = true;
                                WlsLogger::info_(
                                    'Gateway fallback listener entered reversible DRAINING '
                                    . 'transition=' . $transition['transition_id']
                                    . ($ackDelivery['flushed'] ? '' : ' ack=pending'),
                                );
                            } elseif ($result['success']) {
                                WlsLogger::warning_(
                                    'Gateway fallback DRAIN applied fail-closed but exact ACK '
                                    . 'could not be enqueued; awaiting idempotent retry.',
                                );
                            } else {
                                WlsLogger::warning_(
                                    'Gateway fallback DRAIN rejected: ' . $result['reason'],
                                );
                            }
                        } catch (\Throwable $throwable) {
                            WlsLogger::warning_(
                                'Gateway fallback DRAIN envelope rejected: '
                                . $throwable->getMessage(),
                            );
                        }
                        break;
                    }
                    // 排水模式：停止接受新连接，完成现有请求后退出
                    $shouldExit = true;
                    $ipcDraining = true;
                    $drainStartTime = \hrtime(true) / 1_000_000_000;
                    $dt = (int) ($msg['drain_timeout_sec'] ?? 0);
                    if ($dt > 0) {
                        $maxDrainTime = \max(1, \min(7200, $dt));
                    }
                    if ($http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime) {
                        try {
                            $http3Runtime->beginDrain();
                        } catch (\Throwable $exception) {
                            WlsLogger::error_('[HTTP3] failed to start graceful drain: '
                                . $exception->getMessage());
                        }
                    }
                    // 关闭监听 socket（不再接受新连接）
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
                    }
                    WlsLogger::info_("收到 drain 命令，已关闭监听 socket，开始排水（最多 {$maxDrainTime}s）...");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_UNDRAIN:
                    if (!$isGatewayFallbackWorker) {
                        break;
                    }
                    try {
                        $transition = \Weline\Server\IPC\ControlMessage::
                            validateGatewayFallbackListenerTransition($msg);
                        $beforeUndrain = [
                            'listener_state' => $gatewayFallbackListenerState,
                            'listener_draining' => $gatewayFallbackListenerDraining,
                            'drain_acknowledged' => $gatewayFallbackDrainAcknowledged,
                            'drain_transition' => $gatewayFallbackDrainTransition,
                            'undrain_transition' => $gatewayFallbackUndrainTransition,
                            'retired_transitions' => $gatewayFallbackRetiredTransitions,
                        ];
                        $result = wlsApplyGatewayFallbackListenerTransition(
                            $transition,
                            $gatewayFallbackExpectedTransitionIdentity,
                            $gatewayFallbackListenerState,
                            $gatewayFallbackListenerDraining,
                            $shouldExit
                                || $ipcReceivedShutdown
                                || !$socket
                                || !\is_resource($socket),
                            wlsGatewayFallbackTlsContextIsUsable(
                                $servingManifestRoutes,
                                $sniServerCerts,
                                $sslCert,
                                $sslKey,
                            ),
                            $gatewayFallbackDrainAcknowledged,
                            $gatewayFallbackDrainTransition,
                            $gatewayFallbackUndrainTransition,
                            $gatewayFallbackRetiredTransitions,
                        );
                        $ackDelivery = wlsSendGatewayFallbackListenerAck(
                            $ipcClient,
                            $transition,
                            $result['listener_state'],
                            $result['success'],
                            $result['reason'],
                        );
                        if ($result['success']
                            && !$ackDelivery['enqueued']
                            && $result['action_applied']
                        ) {
                            // The Master may only reactivate credential/lease
                            // state after receiving the exact ACK. Restore only
                            // when the ACK could not enter the write queue. A
                            // queued partial write must retain ACTIVE because it
                            // may complete later; rolling back would split the
                            // delayed success ACK from the local listener state.
                            $gatewayFallbackListenerState = $beforeUndrain['listener_state'];
                            $gatewayFallbackListenerDraining = $beforeUndrain['listener_draining'];
                            $gatewayFallbackDrainAcknowledged = $beforeUndrain['drain_acknowledged'];
                            $gatewayFallbackDrainTransition = $beforeUndrain['drain_transition'];
                            $gatewayFallbackUndrainTransition = $beforeUndrain['undrain_transition'];
                            $gatewayFallbackRetiredTransitions = $beforeUndrain['retired_transitions'];
                            WlsLogger::warning_(
                                'Gateway fallback UNDRAIN ACK was not enqueued; listener remains DRAINING.',
                            );
                            break;
                        }
                        if ($result['success'] && $ackDelivery['enqueued']) {
                            $gatewayFallbackListenerDraining = false;
                            WlsLogger::info_(
                                'Gateway fallback listener resumed admission for exact transition='
                                . $transition['transition_id']
                                . ($ackDelivery['flushed'] ? '' : ' ack=pending'),
                            );
                        } elseif (!$result['success']) {
                            WlsLogger::warning_(
                                'Gateway fallback UNDRAIN rejected: ' . $result['reason'],
                            );
                        }
                    } catch (\Throwable $throwable) {
                        WlsLogger::warning_(
                            'Gateway fallback UNDRAIN envelope rejected: '
                            . $throwable->getMessage(),
                        );
                    }
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
                    $drainStartTime = \hrtime(true) / 1_000_000_000;
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
    $kernel->setBeforeReadyGuard(
        static function (string $role) use (&$runtime, &$runtimeError): void {
            if ($role !== \Weline\Server\IPC\ControlMessage::ROLE_WORKER) {
                return;
            }
            if ($runtimeError !== null || !$runtime instanceof \Weline\Framework\Runtime\WlsRuntime) {
                throw new \RuntimeException('Worker runtime is unavailable for the READY credential-store guard.');
            }
            $runtime->assertFrontendWorkerCredentialStoreReady();
        }
    );
    $ipcClient = $kernel->getClient();
    $wlsStartupTrace('ready_gate_warmup_begin', ['control_port' => $controlPort]);
    $runReadyGateWorkerBootstrapWarmup();
    $wlsStartupTrace('ready_gate_warmup_done', ['control_port' => $controlPort]);
    if ($wlsHttp3Enabled && !$isMaintenanceWorker) {
        $nativeManifest = \Weline\Server\Protocol\Http3\NativeTransportLibrary::manifest();
        $actualNativeDigest = \strtolower(\trim((string)($nativeManifest['library_sha256'] ?? '')));
        if (!($nativeManifest['runtime_verified'] ?? false)
            || !\hash_equals($wlsHttp3ExpectedNativeDigest, $actualNativeDigest)
        ) {
            throw new \RuntimeException('HTTP/3 native digest or runtime verification does not match the control plane.');
        }
        if ($wlsHttp3ExpectedTicketRingEpoch <= 0
            || \preg_match('/^[a-f0-9]{64}$/D', $wlsHttp3ExpectedTicketRingDigest) !== 1
        ) {
            throw new \RuntimeException('HTTP/3 TLS ticket-ring metadata is missing or invalid.');
        }
        if ($masterRuntimeCredential === '' || $orchestratorEpoch <= 0) {
            throw new \RuntimeException('HTTP/3 requires the authenticated Master generation secret.');
        }
        $http3RetrySecret = \Weline\Server\Protocol\Http3\DarwinHttp3RuntimeIdentity::retrySecret(
            $masterRuntimeCredential,
            $instanceName,
            $orchestratorEpoch,
        );
        $http3Limits = [
            'max_header_bytes' => $maxRequestHeaderBytes,
            'max_body_bytes' => $maxRequestBodyBytes,
            'initial_max_data' => 64 * 1024 * 1024,
            'max_streams_bidi' => 128,
            'max_connections' => 2048,
            'max_active_streams' => 4096,
            'max_idle_timeout_ms' => 30000,
            'retry_token_lifetime_ms' => 1000,
        ];
        $http3ChannelKey = '';
        try {
            if ($wlsHttp3Mode === 'datagram-router') {
                $http3ChannelKey = \Weline\Server\Protocol\Http3\DarwinHttp3RuntimeIdentity::channelKey(
                    $masterRuntimeCredential,
                    $instanceName,
                    $orchestratorEpoch,
                    $workerId,
                    $orchestratorSlotId,
                    $orchestratorLeaseId,
                    $orchestratorGeneration,
                );
                $http3Runtime = \Weline\Server\Protocol\Http3\WorkerQuicRuntime::bootDatagramWorker(
                    $wlsRuntimeTopology,
                    $isMaintenanceWorker,
                    $host,
                    $port,
                    $sslCert,
                    $sslKey,
                    $http3Limits,
                    $http3RetrySecret,
                    $instanceName,
                    $wlsHttp3ExpectedTicketRingEpoch,
                    $wlsHttp3ExpectedTicketRingDigest,
                    [
                        'worker_id' => $workerId,
                        'generation' => $orchestratorGeneration,
                        'channel_path' => \Weline\Server\Protocol\Http3\DarwinHttp3RuntimeIdentity::workerChannelPath(
                            $instanceName,
                            $workerId,
                            $orchestratorLeaseId,
                            $orchestratorGeneration,
                        ),
                        'channel_key' => $http3ChannelKey,
                    ],
                );
            } else {
                $http3Runtime = \Weline\Server\Protocol\Http3\WorkerQuicRuntime::bootReusePort(
                    $wlsRuntimeTopology,
                    $isMaintenanceWorker,
                    $host,
                    $port,
                    $sslCert,
                    $sslKey,
                    $http3Limits,
                    $http3RetrySecret,
                    $instanceName,
                    $wlsHttp3ExpectedTicketRingEpoch,
                    $wlsHttp3ExpectedTicketRingDigest,
                    [
                        'slot' => $wlsHttp3RouteSlot,
                        'slot_count' => $wlsHttp3RouteCount,
                        'owner_epoch' => $wlsHttp3RouteOwnerEpoch,
                        'generation' => $wlsHttp3RouteGeneration,
                        'namespace_key' => $wlsHttp3RouteNamespace,
                        'flags' => 1,
                    ],
                );
            }
        } finally {
            if (\function_exists('sodium_memzero')) {
                if ($http3ChannelKey !== '') {
                    \sodium_memzero($http3ChannelKey);
                }
                \sodium_memzero($http3RetrySecret);
            }
        }
        if (!$http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime
            || $http3Runtime->port() !== $port
        ) {
            throw new \RuntimeException('HTTP/3 runtime did not attach to the Direct public port.');
        }
        $http3TicketRing = $http3Runtime->tlsTicketRingStatus();
        if (!($http3TicketRing['active'] ?? false)
            || !($http3TicketRing['early_data_disabled'] ?? false)
            || (int)($http3TicketRing['epoch'] ?? 0) !== $wlsHttp3ExpectedTicketRingEpoch
            || !\hash_equals(
                $wlsHttp3ExpectedTicketRingDigest,
                (string)($http3TicketRing['digest'] ?? ''),
            )
        ) {
            throw new \RuntimeException('HTTP/3 TLS ticket ring did not acknowledge the Master snapshot.');
        }
        \Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3TlsTicketRingReady(
            $http3TicketRing,
        );
        if ($wlsHttp3Mode === 'datagram-router') {
            \Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3DatagramWorkerReady(
                $http3Runtime->port(),
                $http3Runtime->nativeDigest(),
                true,
            );
        } else {
            \Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3LinuxRouteStaged(
                $http3Runtime->port(),
                $http3Runtime->nativeDigest(),
                true,
                $http3Runtime->linuxRouteStatus(),
            );
        }
        // The native endpoint is process-ready, but it is not globally
        // advertisable until Master has atomically activated the route and
        // acknowledged this exact generation.
        \Weline\Server\Protocol\Http3\AltSvcResponsePolicy::configure(false, 0);
        \register_shutdown_function(static function (): void {
            \Weline\Server\Protocol\Http3\WorkerQuicRuntime::shutdownActive();
            \Weline\Server\Protocol\Http3\AltSvcResponsePolicy::configure(false, 0);
            \Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3Closed();
        });
        WlsLogger::info_('[HTTP3] native runtime ready mode=' . $wlsHttp3Mode
            . ' port=' . $http3Runtime->port()
            . ' native=' . \substr($http3Runtime->nativeDigest(), 0, 12));
    }
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
        $readySentTime = wlsWorkerMonotonicNow();
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
        $ipcReconnectDueTime = wlsWorkerMonotonicNow() + 5.0;  // 5秒后第一次重连
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
$connectionProtocols = [];
$connectionSniHosts = [];
$connectionPlaintextHosts = [];
$http2ConnectionAdapters = [];
$http2PendingRequests = [];
$http2ParsedAdmissionBudget = \max(16, \min(32, (int)(
    \Weline\Framework\App\Env::get('wls.http2.parsed_admission_budget', 32) ?: 32
)));
$http2AdmissionWriteHighWatermark = \max(131072, \min(2097152, (int)(
    \Weline\Framework\App\Env::get('wls.http2.admission_write_high_watermark_bytes', 524288) ?: 524288
)));
$http2AdmissionLastConnId = 0;
/** @var array<int|string, array{connection: resource, started_at: float, retry_at: float, attempts: int}> */
$writeZeroProgress = [];
$pendingPeek = [];
$pendingPeekStartTimes = [];
$pendingHandshakes = [];
$postHandshakeReadPending = [];
$pendingClose = [];
$handshakeStartTimes = [];
$startTime = wlsWorkerMonotonicNow(); // 进程内 uptime 的 monotonic 起点

// Keep-Alive 连接超时配置（秒）
$keepAliveTimeout = 60; // 默认 60 秒空闲超时
$connectionTimeoutCheckInterval = 5; // 每 5 秒检查一次超时连接
$lastTimeoutCheck = wlsWorkerMonotonicNow();
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
$gracefulExit = function (string $reason = '') use (
    $socket,
    &$connections,
    &$requestBuffers,
    &$connectionLastActivity,
    $processName,
    &$ipcClient,
    $workerId,
    $port,
    $isMaintenanceWorker,
    $isGatewayFallbackWorker,
    &$wlsWorkerGracefulExitReason,
    $wlsTlsSessionCacheRuntime,
) {
    $wlsWorkerGracefulExitReason = $reason !== '' ? $reason : 'graceful';
    // 刷新日志缓冲区
    WlsLogger::flush_(true);
    \Weline\Server\Service\AttackLogService::flushForShutdown();
    \Weline\Server\Protocol\Http3\WorkerQuicRuntime::shutdownActive();
    \Weline\Server\Protocol\Http3\AltSvcResponsePolicy::configure(false, 0);
    \Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3Closed();
    
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
    if ($wlsTlsSessionCacheRuntime !== null) {
        $tlsSessionDrainDeadline = wlsWorkerMonotonicNow() + 0.02;
        while ($wlsTlsSessionCacheRuntime->hasPendingWrites()
            && wlsWorkerMonotonicNow() < $tlsSessionDrainDeadline
        ) {
            $wlsTlsSessionCacheRuntime->maintain(\max(
                0.0001,
                $tlsSessionDrainDeadline - wlsWorkerMonotonicNow(),
            ));
        }
        $wlsTlsSessionCacheRuntime->disconnect();
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
        $exitRole = $isMaintenanceWorker
            ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE
            : ($isGatewayFallbackWorker
                ? \Weline\Server\IPC\ControlMessage::ROLE_GATEWAY_FALLBACK
                : \Weline\Server\IPC\ControlMessage::ROLE_WORKER);
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
    \pcntl_signal(SIGUSR1, function () use (&$shouldExit, &$ipcDraining, &$drainStartTime, &$socket, &$http3Runtime, $logReload) {
        // 收到重载信号，标记优雅退出（Master 会重新启动新进程加载新代码）
        $shouldExit = true;
        $ipcDraining = true;
        $drainStartTime = \hrtime(true) / 1_000_000_000;
        if ($http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime) {
            try {
                $http3Runtime->beginDrain();
            } catch (\Throwable $exception) {
                WlsLogger::error_('[HTTP3] failed to start SIGUSR1 graceful drain: '
                    . $exception->getMessage());
            }
        }
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
$eventLoopLastMetricsLogAt = wlsWorkerMonotonicNow();
$deferredWorkerBootstrapWarmupStarted = false;
$deferredWorkerBootstrapWarmupNotBefore = wlsWorkerMonotonicNow();
$sharedRuntimeConnectionWarmupStarted = false;
$sharedRuntimeConnectionWarmupNotBefore = wlsWorkerMonotonicNow()
    + 0.10
    + ((($workerId * 53) % 700) / 1000);
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
$tlsSessionCacheNextMaintainAt = 0.0;
$tlsSessionCacheTokenReloadNotBefore = 0.0;
$tlsSessionCacheTokenReloadRequiredSince = 0.0;

/**
 * 取消一个连接上的全部请求 Fiber；HTTP/1 使用 connId，HTTP/2 使用 (connId, streamId)。
 *
 * @param array<int|string,array<string,mixed>> $activeFibers
 */
if (!\function_exists('wlsCancelActiveFibersForConnection')) {
function wlsCancelActiveFibersForConnection(
    array &$activeFibers,
    int $connectionId,
    \Weline\Server\Scheduler\FiberScheduler $fiberScheduler,
    int &$activeRequests
): int {
    $cancelled = 0;
    foreach (\Weline\Server\Protocol\Http2\MultiplexScheduler::keysForConnection(
        $activeFibers,
        $connectionId
    ) as $fiberKey) {
        if (!\array_key_exists($fiberKey, $activeFibers)) {
            continue;
        }
        $state = $activeFibers[$fiberKey];
        unset($activeFibers[$fiberKey]);
        $fiber = \is_array($state) ? ($state['fiber'] ?? null) : null;
        if ($fiber instanceof \Fiber) {
            $fiberScheduler->cancelTimersForFiber($fiber);
            wlsUnwindRequestFiberForCancellation(
                $fiber,
                \is_array($state) ? ($state['context'] ?? null) : null,
                'tls_connection_closed',
            );
            \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($fiber);
            $fiberScheduler->unregisterFiber();
        }
        $activeRequests = \max(0, $activeRequests - 1);
        $cancelled++;
    }

    return $cancelled;
}
}

/**
 * Exact TLS tombstones and a Worker hard-drain deadline retire every suspended
 * request before the owning transport disappears. HTTP/3 Fiber keys
 * intentionally do not share the TCP connection-id namespace, so a
 * connection-only sweep is not a complete retirement barrier.
 *
 * @param array<int|string,array<string,mixed>> $activeFibers
 */
if (!\function_exists('wlsCancelAllActiveFibersForTlsRetirement')) {
function wlsCancelAllActiveFibersForTlsRetirement(
    array &$activeFibers,
    \Weline\Server\Scheduler\FiberScheduler $fiberScheduler,
    int &$activeRequests,
    string $cancellationReason = 'tls_manifest_retired',
): int {
    $cancelled = 0;
    foreach (\array_keys($activeFibers) as $fiberKey) {
        if (!\array_key_exists($fiberKey, $activeFibers)) {
            continue;
        }
        $state = $activeFibers[$fiberKey];
        unset($activeFibers[$fiberKey]);
        $fiber = \is_array($state) ? ($state['fiber'] ?? null) : null;
        if ($fiber instanceof \Fiber) {
            $fiberScheduler->cancelTimersForFiber($fiber);
            wlsUnwindRequestFiberForCancellation(
                $fiber,
                \is_array($state) ? ($state['context'] ?? null) : null,
                $cancellationReason,
            );
            \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($fiber);
            $fiberScheduler->unregisterFiber();
        }
        $activeRequests = \max(0, $activeRequests - 1);
        $cancelled++;
    }

    return $cancelled;
}
}

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
    $workerLoopHeartbeatNow = wlsWorkerMonotonicNow();
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
            wlsCancelActiveFibersForConnection(
                $activeFibers,
                $gateConnId,
                $fiberScheduler,
                $activeRequests
            );
            unset(
                $connections[$gateConnId],
                $pendingHandshakes[$gateConnId],
                $handshakeStartTimes[$gateConnId],
                $pendingPeek[$gateConnId],
                $pendingPeekStartTimes[$gateConnId],
                $postHandshakeReadPending[$gateConnId],
                $requestBuffers[$gateConnId],
                $connectionLastActivity[$gateConnId],
                $requestLogged[$gateConnId],
                $connectionPeerIps[$gateConnId],
                $writeBuffers[$gateConnId],
                $writableConnections[$gateConnId],
                $writeZeroProgress[$gateConnId],
                $pendingClose[$gateConnId],
                $longLivedConnections[$gateConnId],
                $connectionProtocols[$gateConnId],
                $http2ConnectionAdapters[$gateConnId],
                $http2PendingRequests[$gateConnId]
            );
        }
        $connectionAcceptGates->reconcileMapsIfDue(
            $connections,
            $pendingHandshakes,
            $pendingPeek,
        );
    }

    $now = $workerLoopHeartbeatNow;

    // 注意：Worker 的主循环不进行连接池预热
    // 连接池将在首次需要时由请求 Fiber 按需初始化

    if ($childMasterGuard->shouldExit()) {
        $leaseExitReason = $childMasterGuard->getLastExitReason();
        WlsLogger::warning_('[Worker SSL] Master lease/PID 已失效，子进程自治退出: ' . $leaseExitReason);
        $gracefulExit(
            $leaseExitReason !== ''
                ? ('Master lease/PID 自治退出: ' . $leaseExitReason)
                : 'Master lease/PID 自治退出'
        );
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
    if (isset($ipcReconnectDueTime) && wlsWorkerMonotonicNow() >= $ipcReconnectDueTime && $ipcReconnectAttempts < $ipcReconnectMaxAttempts) {
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
            $readySentTime = wlsWorkerMonotonicNow();
        } else {
            // 重连失败，设置下一次重连时间（指数退避：5秒 + attempt*1秒）
            $nextRetryDelay = 5 + \min($ipcReconnectAttempts, 10);  // 最多加10秒，即15秒
            $ipcReconnectDueTime = wlsWorkerMonotonicNow() + $nextRetryDelay;
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
        $ackElapsed = wlsWorkerMonotonicNow() - $readySentTime;
        if ($ackElapsed >= $ackTimeout) {
            $ackRetryCount++;
            WlsLogger::warning_("Master ACK 确认结果：超时未确认（{$ackElapsed}s），第 {$ackRetryCount} 次重新发送 ready...");
            $ipcClient->sendReady($ipcRole, $workerId, $port, $orchestratorEpoch, $orchestratorLaunchId);
            $readySentTime = wlsWorkerMonotonicNow();
        }
    }
    if ($ipcClient && $ipcClient->isConnected() && !$waitingForAck && !$workerLoopStartedSent && !$ipcReceivedShutdown) {
        if ($workerLoopNotifyNotBefore <= 0.0) {
            $workerLoopNotifyNotBefore = wlsWorkerMonotonicNow() + 0.25;
        }
        if (wlsWorkerMonotonicNow() >= $workerLoopNotifyNotBefore) {
            $ipcClient->sendWorkerLoopStarted($workerId, $port, (int) \getmypid());
            $workerLoopStartedSent = true;
        }
    }
    if (!$sharedRuntimeConnectionWarmupStarted
        && !$isMaintenanceWorker
        && isset($sessionHost, $sessionPort, $memoryHost, $memoryPort)
        && $workerLoopStartedSent
        && !$ipcReceivedShutdown
        && wlsWorkerMonotonicNow() >= $sharedRuntimeConnectionWarmupNotBefore
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
        && wlsWorkerMonotonicNow() >= $deferredWorkerBootstrapWarmupNotBefore
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

    $tlsSessionCacheTokenReloadNeeded = $wlsTlsSessionCacheRuntime !== null
        && $wlsTlsSessionCacheRuntime->needsTokenReload();
    $tlsSessionCacheTokenReloadWorkerStagger = (\max(0, $workerId - 1) % 4) * 0.15;
    if ($tlsSessionCacheTokenReloadNeeded && $tlsSessionCacheTokenReloadNotBefore <= 0.0) {
        if ($tlsSessionCacheTokenReloadRequiredSince <= 0.0) {
            $tlsSessionCacheTokenReloadRequiredSince = $workerLoopHeartbeatNow;
        }
        // Stagger Workers so a slow Windows UNC token refresh cannot pause the
        // whole pool at once after a shared-sidecar restart.
        $tlsSessionCacheTokenReloadNotBefore = $workerLoopHeartbeatNow
            + $tlsSessionCacheTokenReloadWorkerStagger;
    } elseif (!$tlsSessionCacheTokenReloadNeeded) {
        $tlsSessionCacheTokenReloadNotBefore = 0.0;
        $tlsSessionCacheTokenReloadRequiredSince = 0.0;
    }
    $tlsSessionCacheTokenReloadDue = $tlsSessionCacheTokenReloadNeeded
        && $workerLoopHeartbeatNow >= $tlsSessionCacheTokenReloadNotBefore;
    $tlsSessionCacheTokenReloadForceDue = $tlsSessionCacheTokenReloadDue
        && $tlsSessionCacheTokenReloadRequiredSince > 0.0
        && ($workerLoopHeartbeatNow - $tlsSessionCacheTokenReloadRequiredSince)
            >= (2.0 + $tlsSessionCacheTokenReloadWorkerStagger);
    $tlsSessionCacheIdleMaintainDue = !$tlsSessionCacheTokenReloadNeeded
        && empty($pendingPeek)
        && !wlsWorkerHasPendingRequestWork(
            $activeRequests,
            $requestBuffers,
            $writeBuffers,
            null,
            $http2PendingRequests,
        );
    if ($wlsTlsSessionCacheRuntime !== null
        && $workerLoopHeartbeatNow >= $tlsSessionCacheNextMaintainAt
        && (empty($pendingHandshakes) || $tlsSessionCacheTokenReloadForceDue)
        && ($tlsSessionCacheTokenReloadDue || $tlsSessionCacheIdleMaintainDue)
    ) {
        $tlsSessionCacheNextMaintainAt = $workerLoopHeartbeatNow + 1.0;
        if ($tlsSessionCacheTokenReloadDue) {
            $tlsSessionCacheTokenReloadNotBefore = $workerLoopHeartbeatNow
                + 1.0
                + $tlsSessionCacheTokenReloadWorkerStagger;
        }
        // Ordinary reconnect stays idle-only. Token rotation recovery is an
        // explicit, staggered low-frequency lane and never runs in the 500us
        // post-handshake/request maintenance call.
        $wlsTlsSessionCacheRuntime->maintain(0.005, $tlsSessionCacheTokenReloadDue);
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
        && !wlsWorkerHasPendingRequestWork($activeRequests, $requestBuffers, $writeBuffers, null, $http2PendingRequests)
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
            // 已 accept/已握手但 HTTP 首字节尚未到达的 fresh TLS 连接，
            // 与空闲 Keep-Alive 在缓冲层看起来相同。排水时不能立即
            // close 这些连接，否则客户端会在 reload 窗口收到 RST。
            // 完成响应的连接会因 ipcDraining 自动关闭，其余交给
            // 下方的 soft/hard drain deadline。SSE/WebSocket/H2 在 hard
            // deadline 前仍由事件循环推进，不在进入 DRAIN 时截断。

            $drainDeadlines = \Weline\Server\IPC\ControlMessage::drainDeadlines((float)$maxDrainTime);
            $drainNow = \hrtime(true) / 1_000_000_000;
            $drainElapsed = $drainStartTime > 0.0 ? \max(0.0, $drainNow - $drainStartTime) : 0.0;
            // 先发 GOAWAY，再做 deadline 判定。这样即使首次 drain tick
            // 已到 hard deadline，H2 也会先被明确通知后再强制收敛。
            foreach ($http2ConnectionAdapters as $http2DrainConnectionId => $http2DrainAdapter) {
                if (!$http2DrainAdapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter) {
                    continue;
                }
                $http2DrainConnectionId = (int)$http2DrainConnectionId;
                $http2DrainConnection = $connections[$http2DrainConnectionId] ?? null;
                if (!\is_resource($http2DrainConnection)) {
                    unset(
                        $http2ConnectionAdapters[$http2DrainConnectionId],
                        $http2PendingRequests[$http2DrainConnectionId],
                    );
                    continue;
                }
                $http2GoawayFrame = $http2DrainAdapter->initiateGoaway();
                if ($http2GoawayFrame !== '') {
                    $writeBuffers[$http2DrainConnectionId] =
                        ($writeBuffers[$http2DrainConnectionId] ?? '') . $http2GoawayFrame;
                    $writableConnections[$http2DrainConnectionId] = $http2DrainConnection;
                }
            }
            if ($drainElapsed >= $drainDeadlines['soft']) {
                foreach ($longLivedConnections as $drainConnectionId => &$drainConnectionState) {
                    if (!wlsSslIsActiveWebSocketConnection($drainConnectionState)
                        || !isset($connections[$drainConnectionId])
                        || !\is_resource($connections[$drainConnectionId])
                    ) {
                        continue;
                    }
                    $closeResult = \Weline\Server\IPC\ControlMessage::webSocketInitiateServerClose(
                        (array)($drainConnectionState['websocket_state'] ?? []),
                        1001,
                    );
                    $drainConnectionState['websocket_state'] = $closeResult['state'];
                    if ($closeResult['outbound'] !== []) {
                        $writeBuffers[$drainConnectionId] = ($writeBuffers[$drainConnectionId] ?? '')
                            . \implode('', $closeResult['outbound']);
                        $writableConnections[$drainConnectionId] = $connections[$drainConnectionId];
                    }
                    if (($closeResult['close_transport'] ?? false) === true) {
                        $pendingClose[$drainConnectionId] = true;
                    }
                }
                unset($drainConnectionState);
            }
            $http3ActiveConnections = 0;
            $http3ActiveStreams = 0;
            $http3QueuedRequests = 0;
            $http3RetryGraceElapsed = !$wlsHttp3Enabled || $drainElapsed >= 2;
            if ($http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime) {
                try {
                    $http3DrainStats = $http3Runtime->stats();
                    $http3ActiveConnections = \max(0, (int)($http3DrainStats['active_connections'] ?? 0));
                    $http3ActiveStreams = \max(0, (int)($http3DrainStats['active_streams'] ?? 0));
                    $http3QueuedRequests = \max(0, (int)($http3DrainStats['queued_requests'] ?? 0));
                } catch (\Throwable $exception) {
                    $http3ActiveConnections = 1;
                    WlsLogger::error_('[HTTP3] drain stats failed closed: ' . $exception->getMessage());
                }
            }
            $http2PendingRequestCount = 0;
            foreach ($http2PendingRequests as $http2DrainRequests) {
                $http2PendingRequestCount += \is_array($http2DrainRequests)
                    ? \count($http2DrainRequests)
                    : 0;
            }
            $http2PendingResponseConnections = 0;
            $http2DrainingConnectionCount = 0;
            foreach ($http2ConnectionAdapters as $http2DrainConnectionId => $http2DrainAdapter) {
                if (!$http2DrainAdapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter) {
                    continue;
                }
                if (isset($connections[(int)$http2DrainConnectionId])
                    && \is_resource($connections[(int)$http2DrainConnectionId])
                ) {
                    $http2DrainingConnectionCount++;
                }
                if ($http2DrainAdapter->hasPendingResponseData()) {
                    $http2PendingResponseConnections++;
                }
            }

            $transportConnectionIds = [];
            foreach ([
                \array_keys($connections),
                \array_keys($pendingPeek),
                \array_keys($pendingHandshakes),
                \array_keys($postHandshakeReadPending),
            ] as $transportIds) {
                foreach ($transportIds as $transportId) {
                    $transportConnectionIds[(int)$transportId] = true;
                }
            }
            $tcpConnectionCount = \count($transportConnectionIds);
            $drainConnectionCount = $tcpConnectionCount + $http3ActiveConnections;
            $drainCounters = wlsDrainConnectionCounters($longLivedConnections);
            $pendingApplicationWork = \count($activeFibers)
                + $http2PendingRequestCount
                + $http2PendingResponseConnections
                + $http3ActiveStreams
                + $http3QueuedRequests;
            foreach ($writeBuffers as $drainWriteBuffer) {
                if (\is_string($drainWriteBuffer) && $drainWriteBuffer !== '') {
                    ++$pendingApplicationWork;
                }
            }
            if ($wlsTlsSessionCacheRuntime !== null && $wlsTlsSessionCacheRuntime->hasPendingWrites()) {
                ++$pendingApplicationWork;
            }
            if (!$http3RetryGraceElapsed) {
                ++$pendingApplicationWork;
            }
            $drainObserved = [
                'connections' => $drainConnectionCount,
                'active_requests' => $activeRequests,
                'long_lived_connections' => $drainCounters['long_lived_connections'],
                'sse_connections' => $drainCounters['sse_connections'],
                'websocket_connections' => $drainCounters['websocket_connections'],
                'http2_connections' => $http2DrainingConnectionCount,
            ];
            $drainAction = \Weline\Server\IPC\ControlMessage::drainLifecycleDecision(
                elapsedSeconds: $drainElapsed,
                softDeadlineSeconds: $drainDeadlines['soft'],
                hardDeadlineSeconds: $drainDeadlines['hard'],
                connectionCount: $drainConnectionCount,
                activeRequests: $activeRequests,
                pendingApplicationWork: $pendingApplicationWork,
                longLivedConnections: $drainCounters['long_lived_connections'],
                http2Connections: $http2DrainingConnectionCount,
            );

            if ($drainAction === \Weline\Server\IPC\ControlMessage::DRAIN_ACTION_COMPLETE) {
                $sslDrainReason = $ipcReceivedShutdown
                    ? "shutdown_command:worker={$workerId}"
                    : "drain_or_reload:worker={$workerId}";
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->send(\Weline\Server\IPC\ControlMessage::drainingComplete(
                        workerId: $workerId,
                        port: $port,
                        reason: $sslDrainReason,
                        drainReport: \Weline\Server\IPC\ControlMessage::drainCompletionReport(
                            outcome: \Weline\Server\IPC\ControlMessage::DRAIN_OUTCOME_NATURAL,
                            elapsedSeconds: $drainElapsed,
                            softDeadlineSeconds: $drainDeadlines['soft'],
                            hardDeadlineSeconds: $drainDeadlines['hard'],
                            observed: $drainObserved,
                        ),
                    ));
                    $ipcClient->flushPendingWrites(0.2);
                }
                WlsLogger::info_("排水完成（{$drainElapsed}秒），Worker 退出");
                $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载');
            }

            if (\in_array($drainAction, [
                \Weline\Server\IPC\ControlMessage::DRAIN_ACTION_CLOSE_IDLE,
                \Weline\Server\IPC\ControlMessage::DRAIN_ACTION_FORCE,
            ], true)) {
                $forcedDrain = $drainAction === \Weline\Server\IPC\ControlMessage::DRAIN_ACTION_FORCE;
                $terminatedLongLived = [];
                if ($forcedDrain) {
                    foreach ($longLivedConnections as $longLivedConnectionId => $longLivedState) {
                        if (isset($connections[$longLivedConnectionId])
                            && \is_resource($connections[$longLivedConnectionId])
                        ) {
                            $terminatedLongLived[$longLivedConnectionId] = $longLivedState;
                        }
                    }
                }
                $terminatedDrainCounters = wlsDrainConnectionCounters($terminatedLongLived);
                $closedResourceIds = [];
                $closeDrainResource = static function (mixed $drainConnection) use (&$closedResourceIds): void {
                    if (!\is_resource($drainConnection)) {
                        return;
                    }
                    $resourceId = \get_resource_id($drainConnection);
                    if (isset($closedResourceIds[$resourceId])) {
                        return;
                    }
                    $closedResourceIds[$resourceId] = true;
                    safeCloseStream($drainConnection);
                };
                foreach ($connections as $drainConnection) {
                    $closeDrainResource($drainConnection);
                }
                foreach ($pendingHandshakes as $handshakeInfo) {
                    $closeDrainResource($handshakeInfo['conn'] ?? null);
                }
                foreach ($pendingPeek as $peekInfo) {
                    $closeDrainResource($peekInfo['conn'] ?? null);
                }
                $terminatedRequests = $forcedDrain
                    ? wlsCancelAllActiveFibersForTlsRetirement(
                        $activeFibers,
                        $fiberScheduler,
                        $activeRequests,
                        'drain_hard_deadline',
                    )
                    : 0;
                \Weline\Server\Protocol\Http3\WorkerQuicRuntime::shutdownActive();
                $terminatedConnections = \count($closedResourceIds) + $http3ActiveConnections;

                $connections = [];
                $pendingPeek = [];
                $pendingPeekStartTimes = [];
                $pendingHandshakes = [];
                $requestBuffers = [];
                $connectionLastActivity = [];
                $requestLogged = [];
                $writeBuffers = [];
                $writableConnections = [];
                $writeZeroProgress = [];
                $pendingClose = [];
                $handshakeStartTimes = [];
                $postHandshakeReadPending = [];
                $connectionPeerIps = [];
                $connectionProtocols = [];
                $connectionSniHosts = [];
                $connectionPlaintextHosts = [];
                $http2ConnectionAdapters = [];
                $http2PendingRequests = [];
                $longLivedConnections = [];
                $activeFibers = [];

                $sslDrainReason = $forcedDrain
                    ? ($ipcReceivedShutdown
                        ? "shutdown_command_hard_deadline:worker={$workerId},remaining={$terminatedConnections}"
                        : "drain_or_reload_hard_deadline:worker={$workerId},remaining={$terminatedConnections}")
                    : ($ipcReceivedShutdown
                        ? "shutdown_command_idle_cleanup:worker={$workerId},remaining={$terminatedConnections}"
                        : "drain_or_reload_idle_cleanup:worker={$workerId},remaining={$terminatedConnections}");
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->send(\Weline\Server\IPC\ControlMessage::drainingComplete(
                        workerId: $workerId,
                        port: $port,
                        reason: $sslDrainReason,
                        drainReport: \Weline\Server\IPC\ControlMessage::drainCompletionReport(
                            outcome: $forcedDrain
                                ? \Weline\Server\IPC\ControlMessage::DRAIN_OUTCOME_FORCED
                                : \Weline\Server\IPC\ControlMessage::DRAIN_OUTCOME_IDLE_CLEANUP,
                            elapsedSeconds: $drainElapsed,
                            softDeadlineSeconds: $drainDeadlines['soft'],
                            hardDeadlineSeconds: $drainDeadlines['hard'],
                            observed: $drainObserved,
                            terminated: [
                                'connections' => $terminatedConnections,
                                'active_requests' => $terminatedRequests,
                                'long_lived_connections' => $terminatedDrainCounters['long_lived_connections'],
                                'sse_connections' => $terminatedDrainCounters['sse_connections'],
                                'websocket_connections' => $terminatedDrainCounters['websocket_connections'],
                                'http2_connections' => $forcedDrain ? $http2DrainingConnectionCount : 0,
                            ],
                        ),
                    ));
                    $ipcClient->flushPendingWrites(0.2);
                }
                $sslDrainLogMessage =
                    '[WorkerSslDrain] phase=' . ($forcedDrain ? 'hard_deadline_force_close' : 'idle_cleanup')
                    . ', elapsed_sec=' . $drainElapsed
                    . ', connections=' . $terminatedConnections
                    . ', active_requests=' . $terminatedRequests
                    . ', h2=' . $http2DrainingConnectionCount
                    . ', long_lived=' . $terminatedDrainCounters['long_lived_connections'];
                if ($forcedDrain) {
                    WlsLogger::warning_($sslDrainLogMessage);
                } else {
                    WlsLogger::info_($sslDrainLogMessage);
                }
                $gracefulExit($ipcReceivedShutdown
                    ? ($forcedDrain ? 'shutdown命令（硬期限）' : 'shutdown命令')
                    : ($forcedDrain ? '热重载（硬期限）' : '热重载（空闲连接清理）'));
            }

            static $lastSslDrainWaitLogAt = 0.0;
            if ($drainElapsed >= $drainDeadlines['soft']
                && ($drainNow - $lastSslDrainWaitLogAt) >= 1.0
            ) {
                WlsLogger::warning_(
                    '[WorkerSslDrain] phase=application_wait'
                    . ', elapsed_sec=' . $drainElapsed
                    . ', hard_deadline_sec=' . $drainDeadlines['hard']
                    . ', active=' . $activeRequests
                    . ', fibers=' . \count($activeFibers)
                    . ', h2=' . $http2DrainingConnectionCount
                    . ', h2_queued=' . $http2PendingRequestCount
                    . ', h2_pending_write=' . $http2PendingResponseConnections
                    . ', h3_streams=' . $http3ActiveStreams
                    . ', long_lived=' . $drainCounters['long_lived_connections']
                );
                $lastSslDrainWaitLogAt = $drainNow;
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
            $http2IdleAdapter = $http2ConnectionAdapters[$connId] ?? null;
            if ($http2IdleAdapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter) {
                // HTTP/2 connections are persistent multiplexed sessions. A completed
                // END_STREAM can still have encrypted bytes queued below PHP; closing
                // it with the HTTP/1.1 idle policy truncates slow clients. GOAWAY and
                // peer FIN own the normal H2 shutdown path.
                continue;
            }
            
            // 如果连接空闲时间超过超时时间，关闭连接
            if ($idleTime >= $keepAliveTimeout) {
                // HTTP/2 may have no transport bytes queued while DATA is waiting
                // for the peer's flow-control WINDOW_UPDATE. That is still an
                // active response and must not be mistaken for idle Keep-Alive.
                $http2TimeoutAdapter = $http2ConnectionAdapters[$connId] ?? null;
                $hasHttp2FlowControlledResponse =
                    $http2TimeoutAdapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
                    && $http2TimeoutAdapter->hasPendingResponseData();
                $hasBufferedData = (isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '')
                    || $hasHttp2FlowControlledResponse;
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
                        wlsCancelActiveFibersForConnection(
                            $activeFibers,
                            $connId,
                            $fiberScheduler,
                            $activeRequests
                        );
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
                wlsCancelActiveFibersForConnection(
                    $activeFibers,
                    $connId,
                    $fiberScheduler,
                    $activeRequests
                );
            }
        }
        
        // 定期记录 Worker 状态到数据库
        try {
            $http3Status = $http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime
                ? $http3Runtime->stats()
                : [];
            $drainCounters = wlsDrainConnectionCounters($longLivedConnections);
            $http2StatusConnectionCount = wlsHttp2LiveConnectionCount(
                $http2ConnectionAdapters,
                $connections,
            );
            $statusContext = [
                'active_requests' => $activeRequests,
                'long_lived_connections' => $drainCounters['long_lived_connections'],
                'sse_connections' => $drainCounters['sse_connections'],
                'websocket_connections' => $drainCounters['websocket_connections'],
                'http2_connections' => $http2StatusConnectionCount,
                'drain_counters_version' => 1,
            ];
            foreach ($http3Status as $http3Metric => $http3Value) {
                if (!\is_string($http3Metric) || !\is_int($http3Value)) {
                    continue;
                }
                $statusContext['http3_' . $http3Metric] = $http3Value;
            }
            if ($ipcClient !== null && $ipcClient->isConnected()) {
                $ipcClient->send(\Weline\Server\IPC\ControlMessage::statusReport(
                    \count($connections) + (int)($http3Status['active_connections'] ?? 0),
                    \memory_get_usage(true),
                    $requestCount,
                    $statusContext
                ));
            }
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
                'uptime' => \max(0, (int)\floor($now - $startTime)),
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
                $plannedExitReason = 'memory_pressure_drain'
                    . ":worker={$workerId}"
                    . ",memory={$afterMb}MB"
                    . ",before={$beforeMb}MB"
                    . ",allocated={$afterAllocatedMb}MB"
                    . ",before_allocated={$beforeAllocatedMb}MB"
                    . ",limit={$wlsMemoryLimit}"
                    . ',threshold=' . \round($memoryDrainThreshold * 100, 1) . '%'
                    . ",requests={$requestCount}";
                $wlsWorkerGracefulExitReason = $plannedExitReason;
                if ($ipcClient && $ipcClient->isConnected()) {
                    @$ipcClient->send(\Weline\Server\IPC\ControlMessage::exitReason($plannedExitReason, 0));
                }
                $shouldExit = true;
                $ipcDraining = true;
                $drainStartTime = \hrtime(true) / 1_000_000_000;
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

    if ($maxRequests > 0 && $requestCount >= $maxRequests && !$shouldExit) {
        WlsLogger::info_("SSL Worker 已处理 {$requestCount} 个请求，达到上限 {$maxRequests}，触发优雅重启");
        $plannedExitReason = "max_requests_recycle:worker={$workerId},requests={$requestCount},limit={$maxRequests}";
        $wlsWorkerGracefulExitReason = $plannedExitReason;
        if ($ipcClient && $ipcClient->isConnected()) {
            @$ipcClient->send(\Weline\Server\IPC\ControlMessage::exitReason($plannedExitReason, 0));
        }
        $shouldExit = true;
        $ipcDraining = true;
        $drainStartTime = \hrtime(true) / 1_000_000_000;
        if ($socket && \is_resource($socket)) {
            @\fclose($socket);
            $socket = null;
            \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
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
            wlsCancelActiveFibersForConnection(
                $activeFibers,
                $connId,
                $fiberScheduler,
                $activeRequests
            );
        }
    }
    
    // 验证 $writableConnections 中的资源，并将零进展写入暂时移出 write interest。
    // 否则已断开的 TLS stream 可能一直被报告为可写，使 event loop 空转。
    foreach ($writeZeroProgress as $connId => $state) {
        if (!isset($writableConnections[$connId])
            || ($state['connection'] ?? null) !== $writableConnections[$connId]
        ) {
            unset($writeZeroProgress[$connId]);
        }
    }
    $validWritableConnections = [];
    $queuedWriteNow = wlsWorkerMonotonicNow();
    $queuedWriteRetryUsec = null;
    foreach ($writableConnections as $connId => $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            $retryAt = (float) ($writeZeroProgress[$connId]['retry_at'] ?? 0.0);
            if ($retryAt > $queuedWriteNow) {
                $retryUsec = (int) \ceil(($retryAt - $queuedWriteNow) * 1_000_000);
                $queuedWriteRetryUsec = $queuedWriteRetryUsec === null
                    ? $retryUsec
                    : \min($queuedWriteRetryUsec, $retryUsec);
                continue;
            }
            $validWritableConnections[$connId] = $conn;
        } else {
            unset($writableConnections[$connId]);
            unset($writeBuffers[$connId]);
            unset($writeZeroProgress[$connId]);
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
    $http3SelectStream = $http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime
        ? $http3Runtime->selectStream()
        : null;
    if (\is_resource($http3SelectStream)) {
        $readSockets[] = $http3SelectStream;
    }
    $validConnectionsReadable = [];
    foreach ($validConnections as $connIdReadable => $connReadable) {
        $isDrainingHttp2ControlConnection = $ipcDraining
            && ($connectionProtocols[$connIdReadable] ?? '') === 'h2';
        $longLivedState = $longLivedConnections[$connIdReadable] ?? null;
        if ((($applicationAdmissionOpen || $isDrainingHttp2ControlConnection)
                && $longLivedState === null)
            || wlsSslIsActiveWebSocketConnection($longLivedState)
        ) {
            // GOAWAY already rejects new streams. The read side must remain live
            // for WINDOW_UPDATE/RST/PING so admitted streams can finish safely.
            $validConnectionsReadable[$connIdReadable] = $connReadable;
        }
    }
    $pendingHttp2ReadyConnections = [];
    foreach ($http2PendingRequests as $http2ReadyConnId => $queuedHttp2Requests) {
        $http2QueuedWriteBytes = \strlen((string)($writeBuffers[$http2ReadyConnId] ?? ''));
        $http2ReadyAdapter = $http2ConnectionAdapters[$http2ReadyConnId] ?? null;
        $http2QueuedResponseBytes = $http2QueuedWriteBytes
            + ($http2ReadyAdapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
                ? $http2ReadyAdapter->pendingResponseBytes()
                : 0);
        if ($queuedHttp2Requests !== []
            && isset($validConnections[$http2ReadyConnId])
            && \is_resource($validConnections[$http2ReadyConnId])
            && $applicationAdmissionOpen
            && $http2QueuedResponseBytes < $http2AdmissionWriteHighWatermark
            && \Weline\Server\Protocol\Http2\MultiplexScheduler::activeStreamCount(
                $activeFibers,
                (int)$http2ReadyConnId
            ) < \Weline\Server\Protocol\Http2\ConnectionAdapter::MAX_CONCURRENT_STREAMS
            && !isset($pendingPeek[$http2ReadyConnId])
            && !isset($pendingHandshakes[$http2ReadyConnId])
            && !isset($longLivedConnections[$http2ReadyConnId])
        ) {
            $pendingHttp2ReadyConnections[$http2ReadyConnId] = $validConnections[$http2ReadyConnId];
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
        || $pendingHttp2ReadyConnections !== []
        || $pendingConns !== []
        || $pendingPeekConns !== []
        || $validWritableConnections !== []
        || ($ipcSocket && $ipcClient && $ipcClient->hasPendingWrites())) {
        $loopWaitUsec = $pendingHttp2ReadyConnections !== [] ? 0 : 1000;
    }
    if ($darwinSharedAcceptCooldownRemainingUsec > 0) {
        $loopWaitUsec = \min($loopWaitUsec, $darwinSharedAcceptCooldownRemainingUsec);
    }
    if ($queuedWriteRetryUsec !== null) {
        $loopWaitUsec = \min($loopWaitUsec, \max(1000, $queuedWriteRetryUsec));
    }

    $waitStartedAt = wlsWorkerMonotonicNow();
    $changed = $coroutineRuntime->wait($read, $write, $except, $loopWaitUsec);
    $waitElapsedMs = (wlsWorkerMonotonicNow() - $waitStartedAt) * 1000;
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

    if ($http3Runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime) {
        try {
            $http3Runtime->poll(0);
            if ($applicationAdmissionOpen) {
                foreach ($http3Runtime->nextRequests(64) as $http3Request) {
                    $http3Token = (int)($http3Request['token'] ?? 0);
                    $rawRequest = (string)($http3Request['raw_request'] ?? '');
                    $transportPeer = (string)($http3Request['peer'] ?? '');
                    $http3ConnectionId = (int)($http3Request['connection_id'] ?? 0);
                    $http3StreamId = (int)($http3Request['stream_id'] ?? -1);
                    $policyStartedAt = wlsWorkerMonotonicNow();
                    $activeRequests++;
                    $requestCount++;

                    $frame = wlsParseHttpRequestFrame(
                        $rawRequest,
                        $maxRequestHeaderBytes,
                        $maxRequestBodyBytes,
                    );
                    if (($frame['status'] ?? '') !== 'complete') {
                        wlsHttp3SubmitResponse(
                            $http3Runtime,
                            $http3Token,
                            $rawRequest,
                            wlsServingManifestFramingErrorResponse($frame),
                            $policyStartedAt,
                            $activeRequests,
                        );
                        continue;
                    }
                    $frame['protocol'] = 'h3';
                    $frame['connection_id'] = $http3ConnectionId;
                    $frame['stream_id'] = $http3StreamId;
                    $policyDecision = \Weline\Server\Security\WorkerPolicyKernel::instance()->evaluate(
                        $rawRequest,
                        $transportPeer,
                        $frame,
                    );
                    if (!$policyDecision->allowed) {
                        wlsHttp3SubmitResponse(
                            $http3Runtime,
                            $http3Token,
                            $rawRequest,
                            (string)$policyDecision->response,
                            $policyStartedAt,
                            $activeRequests,
                        );
                        continue;
                    }

                    $uri = $policyDecision->path;
                    $method = $policyDecision->method;
                    $requestHost = \strtolower(\trim((string)($policyDecision->headers['host'] ?? '')));
                    $hostOnly = wlsServingManifestNormalizeAuthority($requestHost);
                    if ($hostOnly === null
                        || !\is_array(wlsServingManifestRouteForHost(
                            $hostOnly,
                            $servingManifestRoutes,
                        ))
                    ) {
                        wlsHttp3SubmitResponse(
                            $http3Runtime,
                            $http3Token,
                            $rawRequest,
                            "HTTP/1.1 421 Misdirected Request\r\nContent-Length: 19\r\n\r\nMisdirected Request",
                            $policyStartedAt,
                            $activeRequests,
                        );
                        continue;
                    }
                    if ($hostOnly !== '') {
                        $domainPolicy = _getDomainPolicy(
                            (string)$hostOnly,
                            $servingManifestRoutes,
                        );
                        if (($domainPolicy['force_root_to_www'] ?? 0) === 1) {
                            if (($domainPolicy['root_to_www_target_ready'] ?? 0) !== 1) {
                                wlsHttp3SubmitResponse(
                                    $http3Runtime,
                                    $http3Token,
                                    $rawRequest,
                                    wlsServingManifestRedirectTargetUnavailableResponse(),
                                    $policyStartedAt,
                                    $activeRequests,
                                );
                                continue;
                            }
                            $redirectHost = (string)$domainPolicy['root_to_www_target'];
                            $redirectUrl = $port === 443
                                ? 'https://' . $redirectHost . $uri
                                : 'https://' . $redirectHost . ':' . $port . $uri;
                            $redirectResponse = "HTTP/1.1 301 Moved Permanently\r\nLocation: {$redirectUrl}\r\n"
                                . "Content-Type: text/html; charset=utf-8\r\nContent-Length: 0\r\n\r\n";
                            wlsHttp3SubmitResponse(
                                $http3Runtime,
                                $http3Token,
                                $rawRequest,
                                $redirectResponse,
                                $policyStartedAt,
                                $activeRequests,
                            );
                            continue;
                        }
                    }

                    if ($method === 'GET' && $uri === '/_wls/health'
                        && $policyDecision->target === '/_wls/health'
                    ) {
                        $allowedHealth = $workerHealthAccessPolicy->allowsClient(
                            $policyDecision->clientIp,
                            $policyDecision->headers,
                        );
                        $body = $allowedHealth ? 'OK' : 'Forbidden';
                        $status = $allowedHealth ? '200 OK' : '403 Forbidden';
                        wlsHttp3SubmitResponse(
                            $http3Runtime,
                            $http3Token,
                            $rawRequest,
                            'HTTP/1.1 ' . $status . "\r\nContent-Type: text/plain\r\nContent-Length: "
                                . \strlen($body) . "\r\n\r\n" . $body,
                            $policyStartedAt,
                            $activeRequests,
                        );
                        continue;
                    }

                    $staticFastResponse = $policyDecision->staticProcessCacheEnabled()
                        ? \Weline\Server\Service\WorkerStaticResponseL1::lookup($policyDecision)
                        : null;
                    if ($staticFastResponse !== null) {
                        wlsHttp3SubmitResponse(
                            $http3Runtime,
                            $http3Token,
                            $rawRequest,
                            $staticFastResponse,
                            $policyStartedAt,
                            $activeRequests,
                        );
                        continue;
                    }

                    if ($policyDecision->fpcCacheEnabled()
                        && $fpcFastPath instanceof \Weline\Server\Service\WorkerFullPageCacheFastPath
                    ) {
                        $fpcHit = $fpcFastPath->lookup($policyDecision, 'https');
                        if ($fpcHit !== null) {
                            $fastPathResponse = wlsDecorateFormattedFpcFastResponseForPerformancePanel(
                                (string)$fpcHit['response'],
                                $rawRequest,
                                (float)\round((wlsWorkerMonotonicNow() - $policyStartedAt) * 1000, 2),
                                $workerId,
                                $port,
                                (string)$fpcHit['source'],
                            );
                            wlsHttp3SubmitResponse(
                                $http3Runtime,
                                $http3Token,
                                $rawRequest,
                                $fastPathResponse,
                                $policyStartedAt,
                                $activeRequests,
                            );
                            continue;
                        }
                    }

                    $longLivedDetection = $longLivedProtocolResolver->detect($rawRequest);
                    if (($longLivedDetection['is_long_lived'] ?? false) === true) {
                        $body = 'HTTP/3 streaming is not available';
                        wlsHttp3SubmitResponse(
                            $http3Runtime,
                            $http3Token,
                            $rawRequest,
                            "HTTP/1.1 501 Not Implemented\r\nContent-Type: text/plain; charset=utf-8\r\n"
                                . 'Content-Length: ' . \strlen($body) . "\r\n\r\n" . $body,
                            $policyStartedAt,
                            $activeRequests,
                        );
                        continue;
                    }

                    if ($fiberMaxActive > 0
                        && wlsCountActiveFibersForAdmission($activeFibers) >= $fiberMaxActive
                    ) {
                        $body = 'Service Unavailable';
                        wlsHttp3SubmitResponse(
                            $http3Runtime,
                            $http3Token,
                            $rawRequest,
                            "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain; charset=utf-8\r\n"
                                . 'Content-Length: ' . \strlen($body) . "\r\n\r\n" . $body,
                            $policyStartedAt,
                            $activeRequests,
                        );
                        continue;
                    }

                    $http3FiberKey = 'h3:' . $http3ConnectionId . ':' . $http3StreamId;
                    $http3ConnectionCount = (int)($http3Runtime->stats()['active_connections'] ?? 0);
                    $requestFiber = new \Fiber(function () use (
                        $rawRequest,
                        $runtime,
                        $runtimeError,
                        $asyncBizAdapters,
                        $instanceName,
                        $workerId,
                        $port,
                        $requestCount,
                        &$activeRequests,
                        $http3ConnectionCount,
                        $startTime,
                        $originToken,
                        $originTokenValidationEnabled,
                        $originTokenHeader,
                        $originTokenAllowLocal,
                        $transportPeer,
                        $policyDecision,
                        $WLS_UOPZ_EXIT_GUARD,
                        $http3FiberKey,
                        &$longLivedConnections,
                        &$http2ConnectionAdapters,
                        &$connections,
                    ): string {
                        wlsFiberRequestContextEnter(null, $http3FiberKey);
                        try {
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
                                $http3ConnectionCount,
                                $startTime,
                                $originToken,
                                $originTokenValidationEnabled,
                                $originTokenHeader,
                                $originTokenAllowLocal,
                                $transportPeer,
                                $policyDecision,
                                $longLivedConnections,
                                $http2ConnectionAdapters,
                                $connections,
                            );
                        } catch (\Weline\Framework\Runtime\RequestExitException $exception) {
                            throw $exception;
                        } catch (\Error $exception) {
                            if ($WLS_UOPZ_EXIT_GUARD && \str_contains($exception->getMessage(), 'uopz')) {
                                $body = 'Internal error: exit()/die() not allowed in WLS request';
                                return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain; charset=utf-8\r\n"
                                    . 'Content-Length: ' . \strlen($body) . "\r\n\r\n" . $body;
                            }
                            throw $exception;
                        } finally {
                            wlsFiberRequestContextLeave();
                            wlsResetLongRunningExecutionLimit();
                        }
                    });

                    $fiberScheduler->registerFiber();
                    try {
                        $requestFiber->start();
                    } catch (\Weline\Framework\Runtime\RequestExitException) {
                    } catch (\Throwable $exception) {
                        WlsLogger::error_('[HTTP3] Fiber start failed: ' . $exception->getMessage());
                    }

                    if ($requestFiber->isTerminated()) {
                        $fiberScheduler->unregisterFiber();
                        $response = '';
                        try {
                            $response = (string)($requestFiber->getReturn() ?? '');
                        } catch (\Throwable) {
                        } finally {
                            \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($requestFiber);
                        }
                        wlsHttp3SubmitResponse(
                            $http3Runtime,
                            $http3Token,
                            $rawRequest,
                            $response,
                            $policyStartedAt,
                            $activeRequests,
                        );
                    } elseif ($requestFiber->isSuspended()) {
                        $fiberActivityWall = \time();
                        $fiberActivityMonotonicNs =
                            \Weline\Server\Runtime\WorkerFiberContextTracker::monotonicNowNs();
                        $activeFibers[$http3FiberKey] = [
                            'fiber' => $requestFiber,
                            'transport' => 'http3',
                            'http3_token' => $http3Token,
                            'http3_connection_id' => $http3ConnectionId,
                            'http3_stream_id' => $http3StreamId,
                            'rawRequest' => $rawRequest,
                            'handleStartTime' => $policyStartedAt,
                            'context' => wlsCaptureSuspendedRequestFiberOrQuarantine($requestFiber),
                            'suspended_at' => $fiberActivityWall,
                            'last_activity' => $fiberActivityWall,
                            'suspended_at_monotonic_ns' => $fiberActivityMonotonicNs,
                            'last_activity_monotonic_ns' => $fiberActivityMonotonicNs,
                            'is_long_lived' => false,
                            'is_sse_protocol' => false,
                        ];
                    } else {
                        $fiberScheduler->unregisterFiber();
                        wlsHttp3SubmitResponse(
                            $http3Runtime,
                            $http3Token,
                            $rawRequest,
                            '',
                            $policyStartedAt,
                            $activeRequests,
                        );
                    }
                }
            }
        } catch (\Throwable $http3Error) {
            WlsLogger::error_('[HTTP3] native runtime failed; draining Worker: ' . $http3Error->getMessage());
            \Weline\Server\Protocol\Http3\WorkerQuicRuntime::shutdownActive();
            \Weline\Server\Protocol\Http3\AltSvcResponsePolicy::configure(false, 0);
            \Weline\Server\Service\Runtime\WorkerReadinessState::markHttp3Closed();
            $http3Runtime = null;
            $shouldExit = true;
            $ipcDraining = true;
            $drainStartTime = \hrtime(true) / 1_000_000_000;
            $maxDrainTime = \min($maxDrainTime, 3);
            if ($socket && \is_resource($socket)) {
                @\fclose($socket);
                $socket = null;
                \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
            }
        }
    }

    // 连接已经关闭时，先按 (connId, streamId) 清理全部 Fiber，避免失效流在 tick 中再次恢复。
    $orphanFiberConnections = [];
    foreach ($activeFibers as $orphanFiberKey => $orphanFiberState) {
        $orphanConnId = \Weline\Server\Protocol\Http2\MultiplexScheduler::connectionId(
            $orphanFiberKey,
            $orphanFiberState
        );
        if ($orphanConnId > 0 && !isset($connections[$orphanConnId])) {
            $orphanFiberConnections[$orphanConnId] = true;
        }
    }
    foreach (\array_keys($orphanFiberConnections) as $orphanConnId) {
        wlsCancelActiveFibersForConnection(
            $activeFibers,
            (int)$orphanConnId,
            $fiberScheduler,
            $activeRequests
        );
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
                static fn (\Fiber $targetFiber) => \Weline\Framework\Runtime\WlsFiberContext::captureForFiber(
                    $targetFiber
                )
            );
            wlsResetLongRunningExecutionLimit();
        },
        static function (\Fiber $fiber, \Throwable $failure): void {
            \Weline\Server\Service\WorkerResponseMemoryGuard::requestDrainAfterResponse(
                'request_fiber_resume_failure'
            );
            WlsLogger::error_(
                'TLS Request Fiber resume/capture failed; Worker quarantine requested: '
                . $failure->getMessage()
                . ' fiber=' . \spl_object_id($fiber)
            );
        }
    );
    wlsDrainAfterResponseIfRequested($socket, $shouldExit, $ipcDraining, $drainStartTime, $maxDrainTime);
    foreach ($activeFibers as $afKey => $afData) {
        $af = $afData['fiber'] ?? null;
        if (!($af instanceof \Fiber)) {
            unset($activeFibers[$afKey]);
            continue;
        }
        $afConnId = \Weline\Server\Protocol\Http2\MultiplexScheduler::connectionId($afKey, $afData);
        $afStreamId = \Weline\Server\Protocol\Http2\MultiplexScheduler::streamId($afKey, $afData);
        if ($af->isTerminated()) {
            $afFinishedAt = wlsWorkerMonotonicNow();
            $afStartedAt = \Weline\Server\Runtime\WorkerFiberContextTracker::normalizeMonotonicStartSeconds(
                $afData['handleStartTime'] ?? null,
                $afFinishedAt,
            );
            $afResponse = '';
            try {
                $afResponse = (string)($af->getReturn() ?? '');
            } catch (\Throwable) {
            } finally {
                \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($af);
            }
            $fiberScheduler->unregisterFiber();
            if (($afData['transport'] ?? '') === 'http3') {
                wlsHttp3SubmitResponse(
                    $http3Runtime,
                    (int)($afData['http3_token'] ?? 0),
                    (string)($afData['rawRequest'] ?? ''),
                    $afResponse,
                    $afStartedAt,
                    $activeRequests,
                );
                unset($activeFibers[$afKey]);
                continue;
            }
            $afDurationMs = \max(0.0, $afFinishedAt - $afStartedAt) * 1000;
            $afResponse = injectWlsProcessTimeHeader($afResponse, $afDurationMs);
            if ($afConnId > 0 && isset($connections[$afConnId]) && \is_resource($afData['conn'] ?? null)) {
                $afHttp2Adapter = $afData['http2_adapter'] ?? null;
                sslFinalizeHttpResponseAfterHandle(
                    $afData['conn'],
                    $afConnId,
                    (string)($afData['rawRequest'] ?? ''),
                    $afResponse,
                    $afStartedAt,
                    (bool)($afData['is_sse_protocol'] ?? false),
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
                    true,
                    null,
                    null,
                    false,
                    $afHttp2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter ? $afHttp2Adapter : null,
                    $afStreamId,
                );
                wlsDrainAfterResponseIfRequested($socket, $shouldExit, $ipcDraining, $drainStartTime, $maxDrainTime);
            } else {
                $activeRequests = \max(0, $activeRequests - 1);
                \Weline\Framework\Http\Sse\SseContext::reset();
            }
            unset($activeFibers[$afKey]);
            continue;
        }
        if ($af->isSuspended()) {
            $activeFibers[$afKey] = $afData;
        }
    }

    \Weline\Server\Runtime\WorkerFiberSnapshot::setSnapshot(\Weline\Server\Runtime\WorkerFiberHealthSnapshot::build($activeFibers));

    $nowFiberCheckMonotonicNs = \Weline\Server\Runtime\WorkerFiberContextTracker::monotonicNowNs();
    $idleCheckIntervalSsl = 5;
    $doReleaseIdleSsl = $fiberReleaseIdleRequested
        || ($fiberIdleTtlSec > 0
            && ($nowFiberCheckMonotonicNs - $lastFiberIdleCheck)
                >= ($idleCheckIntervalSsl * 1_000_000_000));
    if ($doReleaseIdleSsl && $activeFibers !== []) {
        $lastFiberIdleCheck = $nowFiberCheckMonotonicNs;
        $fiberReleaseIdleRequested = false;
        $releaseThresholdSsl = $fiberIdleTtlSec > 0 ? $fiberIdleTtlSec : 0;
        $toReleaseSsl = [];
        $fiberHeartbeatTimeoutSsl = 60;
        if (isset($envConfig['wls']['fiber']['heartbeat_timeout'])) {
            $fiberHeartbeatTimeoutSsl = (int) $envConfig['wls']['fiber']['heartbeat_timeout'];
        }
        foreach ($activeFibers as $afKeySsl => $afDataSsl) {
            $afConnIdSsl = \Weline\Server\Protocol\Http2\MultiplexScheduler::connectionId($afKeySsl, $afDataSsl);
            $idleDecisionSsl = \Weline\Server\Runtime\WorkerFiberContextTracker::idleReleaseDecision(
                $afDataSsl,
                $nowFiberCheckMonotonicNs,
                $fiberHeartbeatTimeoutSsl,
                $releaseThresholdSsl,
            );
            if (($idleDecisionSsl['release'] ?? false) !== true) {
                continue;
            }
            if (($idleDecisionSsl['reason'] ?? '') === 'heartbeat_timeout') {
                $inactiveTimeSsl = (int)\floor((float)($idleDecisionSsl['inactive_seconds'] ?? 0.0));
                WlsLogger::warning_(
                    "Fiber 心跳超时: connId={$afConnIdSsl} inactive_time={$inactiveTimeSsl}s (超过 {$fiberHeartbeatTimeoutSsl}s 未续约)"
                );
            }
            $toReleaseSsl[$afKeySsl] = $afDataSsl;
        }
        $releasedHttp3Fibers = 0;
        foreach ($toReleaseSsl as $releaseFiberKeySsl => $releaseStateSsl) {
            if (($releaseStateSsl['transport'] ?? '') !== 'http3') {
                continue;
            }
            $releaseFiberSsl = $releaseStateSsl['fiber'] ?? null;
            if ($releaseFiberSsl instanceof \Fiber) {
                $fiberScheduler->cancelTimersForFiber($releaseFiberSsl);
                wlsUnwindRequestFiberForCancellation(
                    $releaseFiberSsl,
                    $releaseStateSsl['context'] ?? null,
                    'http3_idle_or_heartbeat_timeout',
                );
                \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($releaseFiberSsl);
                $fiberScheduler->unregisterFiber();
            }
            try {
                $http3Runtime?->closeRequest((int)($releaseStateSsl['http3_token'] ?? 0));
            } catch (\Throwable) {
            }
            $activeRequests = \max(0, $activeRequests - 1);
            unset($activeFibers[$releaseFiberKeySsl], $toReleaseSsl[$releaseFiberKeySsl]);
            $releasedHttp3Fibers++;
        }
        $releaseConnectionsSsl = [];
        foreach ($toReleaseSsl as $afKeySsl => $afDataSsl) {
            $afConnIdSsl = \Weline\Server\Protocol\Http2\MultiplexScheduler::connectionId($afKeySsl, $afDataSsl);
            if ($afConnIdSsl > 0) {
                $releaseConnectionsSsl[$afConnIdSsl] = true;
            }
        }
        foreach (\array_keys($releaseConnectionsSsl) as $releaseConnIdSsl) {
            foreach (\Weline\Server\Protocol\Http2\MultiplexScheduler::keysForConnection($activeFibers, (int)$releaseConnIdSsl) as $releaseFiberKeySsl) {
                $releaseStateSsl = $activeFibers[$releaseFiberKeySsl] ?? null;
                $releaseFiberSsl = \is_array($releaseStateSsl) ? ($releaseStateSsl['fiber'] ?? null) : null;
                if ($releaseFiberSsl instanceof \Fiber) {
                    $fiberScheduler->cancelTimersForFiber($releaseFiberSsl);
                    wlsUnwindRequestFiberForCancellation(
                        $releaseFiberSsl,
                        \is_array($releaseStateSsl) ? ($releaseStateSsl['context'] ?? null) : null,
                        'tls_idle_or_heartbeat_timeout',
                    );
                    \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($releaseFiberSsl);
                    $fiberScheduler->unregisterFiber();
                    $activeRequests = \max(0, $activeRequests - 1);
                }
                unset($activeFibers[$releaseFiberKeySsl]);
            }
            if (isset($connections[$releaseConnIdSsl]) && \is_resource($connections[$releaseConnIdSsl])) {
                safeCloseStream($connections[$releaseConnIdSsl]);
            }
            unset(
                $connections[$releaseConnIdSsl],
                $requestBuffers[$releaseConnIdSsl],
                $connectionLastActivity[$releaseConnIdSsl],
                $requestLogged[$releaseConnIdSsl],
                $writeBuffers[$releaseConnIdSsl],
                $writableConnections[$releaseConnIdSsl],
                $pendingClose[$releaseConnIdSsl],
                $longLivedConnections[$releaseConnIdSsl],
                $connectionProtocols[$releaseConnIdSsl],
                $http2ConnectionAdapters[$releaseConnIdSsl],
                $http2PendingRequests[$releaseConnIdSsl]
            );
        }
        $releasedSsl = \count($toReleaseSsl) + $releasedHttp3Fibers;
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

    if ($pendingHttp2ReadyConnections !== []) {
        // HTTP/2 多路复用：同一 TLS 连接内已解析出的 stream 是用户态待处理工作，
        // 不能等待下一次 kernel readable edge；否则并发 stream 会被额外延迟一轮甚至卡住。
        // 已有 pending stream 的连接统一移到 ready 队列尾部，并从上次准入连接的下一个
        // connId 开始轮转；这样超过单轮预算时不会固定饿死排序靠后的连接。
        $pendingHttp2ConnIds = \array_map('intval', \array_keys($pendingHttp2ReadyConnections));
        if ($http2AdmissionLastConnId > 0 && \count($pendingHttp2ConnIds) > 1) {
            $http2CursorOffset = 0;
            $http2LastCursorIndex = \array_search($http2AdmissionLastConnId, $pendingHttp2ConnIds, true);
            if ($http2LastCursorIndex !== false) {
                $http2CursorOffset = ($http2LastCursorIndex + 1) % \count($pendingHttp2ConnIds);
            } else {
                $http2NextConnId = \PHP_INT_MAX;
                foreach ($pendingHttp2ConnIds as $http2CursorIndex => $pendingHttp2ConnId) {
                    if ($pendingHttp2ConnId > $http2AdmissionLastConnId
                        && $pendingHttp2ConnId < $http2NextConnId
                    ) {
                        $http2NextConnId = $pendingHttp2ConnId;
                        $http2CursorOffset = $http2CursorIndex;
                    }
                }
            }
            if ($http2CursorOffset > 0) {
                $pendingHttp2ConnIds = \array_merge(
                    \array_slice($pendingHttp2ConnIds, $http2CursorOffset),
                    \array_slice($pendingHttp2ConnIds, 0, $http2CursorOffset),
                );
            }
        }
        $pendingHttp2ConnIdSet = \array_fill_keys($pendingHttp2ConnIds, true);
        foreach ($read as $readyIndex => $readyConn) {
            if (\is_resource($readyConn)
                && isset($pendingHttp2ConnIdSet[\get_resource_id($readyConn)])
            ) {
                unset($read[$readyIndex]);
            }
        }
        $read = \array_values($read);
        $http2ReadyConnectionCount = \count($pendingHttp2ConnIds);
        $http2PerConnectionQuantum = $http2ReadyConnectionCount > 0
            ? \max(1, \min(8, (int)\ceil($http2ParsedAdmissionBudget / $http2ReadyConnectionCount)))
            : 1;
        foreach ($pendingHttp2ConnIds as $pendingHttp2ConnId) {
            $pendingHttp2Conn = $pendingHttp2ReadyConnections[$pendingHttp2ConnId] ?? null;
            if (!\is_resource($pendingHttp2Conn)) {
                continue;
            }
            $http2QueuedStreamCount = \count($http2PendingRequests[$pendingHttp2ConnId] ?? []);
            $http2ActiveStreamCount = \Weline\Server\Protocol\Http2\MultiplexScheduler::activeStreamCount(
                $activeFibers,
                $pendingHttp2ConnId,
            );
            $http2AvailableStreamSlots = \max(
                0,
                \Weline\Server\Protocol\Http2\ConnectionAdapter::MAX_CONCURRENT_STREAMS - $http2ActiveStreamCount,
            );
            $http2ConnectionAdmissions = \min(
                $http2PerConnectionQuantum,
                $http2QueuedStreamCount,
                $http2AvailableStreamSlots,
            );
            for ($http2AdmissionIndex = 0; $http2AdmissionIndex < $http2ConnectionAdmissions; $http2AdmissionIndex++) {
                // Repeating the resource deliberately schedules multiple already-parsed streams
                // from one multiplexed connection in this event-loop turn. The existing per-item
                // gates still enforce the global budget, Fiber capacity and write backpressure.
                $read[] = $pendingHttp2Conn;
            }
        }
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
        $writeZeroProgress,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $pendingClose,
        $longLivedConnections,
        $http2ConnectionAdapters
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
    if ($ipcDraining && $http2ConnectionAdapters !== []) {
        foreach ($http2ConnectionAdapters as $http2DrainConnectionId => $http2DrainAdapter) {
            if (!$http2DrainAdapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter) {
                continue;
            }
            $http2DrainConnectionId = (int)$http2DrainConnectionId;
            $http2DrainConnection = $connections[$http2DrainConnectionId] ?? null;
            if (!\is_resource($http2DrainConnection)) {
                unset(
                    $http2ConnectionAdapters[$http2DrainConnectionId],
                    $http2PendingRequests[$http2DrainConnectionId]
                );
                continue;
            }
            $http2GoawayFrame = $http2DrainAdapter->initiateGoaway();
            if ($http2GoawayFrame !== '') {
                $writeBuffers[$http2DrainConnectionId] =
                    ($writeBuffers[$http2DrainConnectionId] ?? '') . $http2GoawayFrame;
                $writableConnections[$http2DrainConnectionId] = $http2DrainConnection;
            }
            // initiateGoaway() is idempotent. Keep the TLS stream readable
            // until the peer closes after consuming every pre-GOAWAY response byte;
            // PHP/OpenSSL cannot portably observe the kernel send queue here.
        }
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
        $connectionSniHosts,
        $connectionPlaintextHosts,
        \Weline\Server\Service\WorkerResponseMemoryGuard::listenerAcceptBatchLimit(
            $sharedListenerBound,
            \PHP_OS_FAMILY,
            (string)($eventLoopMeta['resolved'] ?? $wlsLoopDriver),
        ),
        $applicationAdmissionOpen && !$gatewayFallbackListenerDraining,
        $sharedListenerSocket,
        rejectWithoutAdmission: $gatewayFallbackListenerDraining,
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
        $servingManifestRoutes,
        $servingManifestHttpRoutes,
        $port,
        $connectionPeerIps,
        $wlsRuntimeTopology,
        $masterRuntimeCredential,
        $connectionProtocols,
        $http2ConnectionAdapters,
        $wlsTlsSessionCacheRuntime,
        $connectionSniHosts,
        $connectionPlaintextHosts,
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
        $hotPathLogsEnabled,
        $wlsTlsSessionCacheRuntime,
    );
    foreach (\array_keys($connectionSniHosts) as $sniConnectionId) {
        if (!isset($connections[$sniConnectionId])
            && !isset($pendingPeek[$sniConnectionId])
            && !isset($pendingHandshakes[$sniConnectionId])
            && !isset($postHandshakeReadPending[$sniConnectionId])
        ) {
            unset($connectionSniHosts[$sniConnectionId]);
        }
    }
    foreach (\array_keys($connectionPlaintextHosts) as $plaintextConnectionId) {
        if (!isset($connections[$plaintextConnectionId])) {
            unset($connectionPlaintextHosts[$plaintextConnectionId]);
        }
    }

    if ($wlsTlsSessionCacheRuntime !== null && $wlsTlsSessionCacheRuntime->needsMaintenance()) {
        // new/remove callbacks only enqueue. Send on the dedicated writer channel
        // after OpenSSL returns, before application dispatch, without waiting for replies.
        $wlsTlsSessionCacheRuntime->maintain(0.0005);
    }

    // ext-event observes kernel readiness only. OpenSSL may already hold the
    // first HTTP bytes in its user-space buffer after TLS completes, so newly
    // handshaken streams get a short bounded first-read pump. Ordinary
    // keep-alive connections never enter this map and pay no scan cost.
    $postHandshakeReadNow = wlsWorkerMonotonicNow();
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
        if (wlsSslIsActiveWebSocketConnection($longLivedConnections[$bufferedConnId] ?? null)) {
            if (!\in_array($connections[$bufferedConnId], $read, true)) {
                $read[] = $connections[$bufferedConnId];
            }
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
    foreach ($longLivedConnections as $webSocketConnId => $webSocketState) {
        if (!wlsSslIsActiveWebSocketConnection($webSocketState)
            || (($webSocketState['resume_pending'] ?? false) !== true
                && ($webSocketState['read_pump_pending'] ?? false) !== true)
            || !isset($connections[$webSocketConnId])
            || isset($activeFibers[$webSocketConnId])
            || ($writeBuffers[$webSocketConnId] ?? '') !== ''
            || isset($pendingClose[$webSocketConnId])
            || \in_array($connections[$webSocketConnId], $read, true)
        ) {
            continue;
        }
        $read[] = $connections[$webSocketConnId];
    }

    // `$read` 同时作为本轮公平工作队列：每个 H2 连接处理一个 parsed stream 后
    // 只会被追加到队尾一次。HTTP/1.1 不追加，仍保持原有一次 readable 处理语义。
    $read = \array_values($read);
    $http2ParsedAdmissionsThisLoop = 0;
    $readWorkCount = \count($read);
    for ($readIndex = 0; $readIndex < $readWorkCount; $readIndex++) {
        $conn = $read[$readIndex];
        if (!\is_resource($conn)) {
            continue;
        }
        $connId = \get_resource_id($conn);
        $activeWebSocket = wlsSslIsActiveWebSocketConnection($longLivedConnections[$connId] ?? null);

        if (isset($pendingPeek[$connId])) {
            continue;
        }
        if (!$applicationAdmissionOpen && isset($connections[$connId]) && !$activeWebSocket) {
            continue;
        }
        $isHttp2Read = ($connectionProtocols[$connId] ?? 'http/1.1') === 'h2';
        if ((!$isHttp2Read && \Weline\Server\Service\ConnectionReadWriteGuard::shouldDeferRead(
            $writeBuffers,
            $pendingClose,
            $connId,
            isset($activeFibers[$connId])
        )) || ($isHttp2Read && isset($pendingClose[$connId]))) {
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

        if ($activeWebSocket) {
            wlsSslWebSocketReadStep(
                $conn,
                $connId,
                $connections,
                $requestBuffers,
                $connectionLastActivity,
                $requestLogged,
                $writeBuffers,
                $writableConnections,
                $pendingClose,
                $longLivedConnections,
                $writeZeroProgress,
                $postHandshakeReadPending,
                $connectionPeerIps,
                $connectionProtocols,
                $connectionSniHosts,
                $connectionPlaintextHosts,
                $http2ConnectionAdapters,
                $http2PendingRequests,
            );
            continue;
        }
        
        if (($connectionProtocols[$connId] ?? 'pending') === 'pending') {
            $streamMetadata = @\stream_get_meta_data($conn);
            $cryptoMetadata = \is_array($streamMetadata['crypto'] ?? null)
                ? $streamMetadata['crypto']
                : [];
            $negotiatedAlpn = \strtolower(\trim((string)($cryptoMetadata['alpn_protocol'] ?? '')));

            if ($negotiatedAlpn === 'h2' && $wlsHttp2NegotiationEnabled) {
                $connectionProtocols[$connId] = 'h2';
                $http2ConnectionAdapters[$connId] ??= new \Weline\Server\Protocol\Http2\ConnectionAdapter();
            } elseif (($negotiatedAlpn === 'http/1.1' || $negotiatedAlpn === '')
                && $wlsHttp1NegotiationEnabled
            ) {
                // A TLS client that omits ALPN may use the HTTP/1.1 fallback
                // only when the immutable endpoint policy explicitly enables it.
                $connectionProtocols[$connId] = 'http/1.1';
            } else {
                // Never infer HTTP/2 from decrypted bytes. The application
                // protocol is fixed by the completed TLS ALPN negotiation.
                safeCloseStream($conn);
                unset(
                    $connections[$connId],
                    $requestBuffers[$connId],
                    $connectionLastActivity[$connId],
                    $connectionProtocols[$connId],
                    $http2ConnectionAdapters[$connId],
                    $http2PendingRequests[$connId]
                );
                continue;
            }
        }
        $bufferedFrame = null;
        $isHttp2Connection = ($connectionProtocols[$connId] ?? 'http/1.1') === 'h2';
        if (!$isHttp2Connection
            && ($connectionProtocols[$connId] ?? '') === 'http/1.1'
            && isset($connectionPeerIps[$connId])
            && ($requestBuffers[$connId] ?? '') !== '') {
            $bufferedFrame = wlsParseHttpRequestFrame(
                $requestBuffers[$connId],
                $maxRequestHeaderBytes,
                $maxRequestBodyBytes,
            );
        }

        $data = '';
        $hasPendingHttp2Request = $isHttp2Connection && !empty($http2PendingRequests[$connId]);
        $http2ReadAdapter = $isHttp2Connection ? ($http2ConnectionAdapters[$connId] ?? null) : null;
        $http2NeedsFlowControlRead = $http2ReadAdapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
            && $http2ReadAdapter->hasPendingResponseData();
        if ((!$hasPendingHttp2Request || $http2NeedsFlowControlRead)
            && (!\is_array($bufferedFrame) || ($bufferedFrame['status'] ?? '') === 'incomplete')
        ) {
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
            if (wlsCancelActiveFibersForConnection(
                $activeFibers,
                $connId,
                $fiberScheduler,
                $activeRequests
            ) > 0) {
                WlsLogger::info_(
                    '客户端断开，Fiber 已清理 (connId: ' . $connId . ', 剩余活跃 Fiber: ' . \count($activeFibers) . ')'
                );
            }
            continue;
        }
        
        if (!$isHttp2Connection && $data === '' && (!\is_array($bufferedFrame) || ($bufferedFrame['status'] ?? '') === 'incomplete')) {
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
                wlsCancelActiveFibersForConnection(
                    $activeFibers,
                    $connId,
                    $fiberScheduler,
                    $activeRequests
                );
                continue;
            }
            // 暂无数据，不要立即检查 feof()，因为 SSL 连接上 feof() 不可靠
            // 让 Keep-Alive 超时机制来处理真正的空闲连接
            continue;
        }
        
        if ($isHttp2Connection) {
            if ($data === '') {
                if (@\feof($conn)) {
                    safeCloseStream($conn);
                    wlsCancelActiveFibersForConnection(
                        $activeFibers,
                        $connId,
                        $fiberScheduler,
                        $activeRequests,
                    );
                    unset(
                        $postHandshakeReadPending[$connId],
                        $connections[$connId],
                        $requestBuffers[$connId],
                        $connectionLastActivity[$connId],
                        $requestLogged[$connId],
                        $writeBuffers[$connId],
                        $writableConnections[$connId],
                        $pendingClose[$connId],
                        $longLivedConnections[$connId],
                        $connectionProtocols[$connId],
                        $http2ConnectionAdapters[$connId],
                        $http2PendingRequests[$connId]
                    );
                    continue;
                }
                if (empty($http2PendingRequests[$connId])) {
                    continue;
                }
            }

            $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
            // L4 slowloris accounting covers accept through the first complete H2
            // request. Subsequent DATA/WINDOW_UPDATE/PING frames are connection
            // control traffic, not new requests; per-stream framing limits belong
            // to ConnectionAdapter and must not re-arm a connection-wide deadline.
            $http2Adapter = $http2ConnectionAdapters[$connId] ?? null;
            if (!$http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter) {
                $http2Adapter = new \Weline\Server\Protocol\Http2\ConnectionAdapter();
                $http2ConnectionAdapters[$connId] = $http2Adapter;
            }
            if ($ipcDraining) {
                $http2GoawayFrame = $http2Adapter->initiateGoaway();
                if ($http2GoawayFrame !== '') {
                    $writeBuffers[$connId] = ($writeBuffers[$connId] ?? '') . $http2GoawayFrame;
                    $writableConnections[$connId] = $conn;
                }
            }
            if ($data !== '') {
                try {
                    $http2Result = $http2Adapter->receive($data);
                } catch (\Throwable $http2Error) {
                    $http2Result = [
                        'status' => 'error',
                        'write' => $http2Adapter->initiateGoaway(
                            \Weline\Server\Protocol\Http2\FrameCodec::ERROR_INTERNAL_ERROR,
                            $http2Error->getMessage()
                        ),
                        'requests' => [],
                        'reset_streams' => [],
                        'error' => $http2Error->getMessage(),
                    ];
                }
                $http2AcceptGate = \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull();
                if ($http2Adapter->hasIncompleteRequestInput()) {
                    $http2AcceptGate?->beginRequest((string)$connId);
                } elseif ($http2Adapter->hasEmittedRequest()) {
                    // Framing is complete even when application admission is queued.
                    $http2AcceptGate?->markRequestComplete((string)$connId);
                }
                $http2Write = (string)($http2Result['write'] ?? '');
                if ($http2Write !== '') {
                    $writeBuffers[$connId] = ($writeBuffers[$connId] ?? '') . $http2Write;
                    $writableConnections[$connId] = $conn;
                }

                $http2ConnectionDrainComplete = ($ipcDraining || $http2Adapter->peerGoawayReceived())
                    && !$http2Adapter->hasActiveStreams()
                    && empty($http2PendingRequests[$connId])
                    && !$http2Adapter->hasPendingResponseData();
                if ($http2ConnectionDrainComplete) {
                    $goawayFrame = $http2Adapter->initiateGoaway();
                    if ($goawayFrame !== '') {
                        $writeBuffers[$connId] = ($writeBuffers[$connId] ?? '') . $goawayFrame;
                        $writableConnections[$connId] = $conn;
                    }
                    // Wait for peer FIN. Local fclose can discard encrypted bytes
                    // already accepted by OpenSSL but not consumed by a slow client.
                    continue;
                }

                foreach ((array)($http2Result['reset_streams'] ?? []) as $resetStreamId) {
                    $resetStreamId = (int)$resetStreamId;
                    if ($resetStreamId <= 0) {
                        continue;
                    }
                    if (isset($http2PendingRequests[$connId])) {
                        $http2PendingRequests[$connId] = \array_values(\array_filter(
                            $http2PendingRequests[$connId],
                            static fn (array $pending): bool => (int)($pending['stream_id'] ?? 0) !== $resetStreamId
                        ));
                    }
                    foreach (\Weline\Server\Protocol\Http2\MultiplexScheduler::keysForConnection(
                        $activeFibers,
                        $connId,
                        $resetStreamId
                    ) as $resetFiberKey) {
                        $resetState = $activeFibers[$resetFiberKey] ?? null;
                        $resetFiber = \is_array($resetState) ? ($resetState['fiber'] ?? null) : null;
                        if ($resetFiber instanceof \Fiber) {
                            $fiberScheduler->cancelTimersForFiber($resetFiber);
                            wlsUnwindRequestFiberForCancellation(
                                $resetFiber,
                                \is_array($resetState) ? ($resetState['context'] ?? null) : null,
                                'http2_stream_reset',
                            );
                            \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($resetFiber);
                            $fiberScheduler->unregisterFiber();
                            $activeRequests = \max(0, $activeRequests - 1);
                        }
                        unset($activeFibers[$resetFiberKey]);
                    }
                }

                if (($http2Result['status'] ?? '') === 'error') {
                    WlsLogger::warning_(
                        'HTTP/2 connection error; GOAWAY queued connId=' . $connId
                        . ' error=' . (string)($http2Result['error'] ?? 'unknown')
                    );
                    foreach (\Weline\Server\Protocol\Http2\MultiplexScheduler::keysForConnection(
                        $activeFibers,
                        $connId
                    ) as $closingFiberKey) {
                        $closingState = $activeFibers[$closingFiberKey] ?? null;
                        $closingFiber = \is_array($closingState) ? ($closingState['fiber'] ?? null) : null;
                        if ($closingFiber instanceof \Fiber) {
                            $fiberScheduler->cancelTimersForFiber($closingFiber);
                            wlsUnwindRequestFiberForCancellation(
                                $closingFiber,
                                \is_array($closingState) ? ($closingState['context'] ?? null) : null,
                                'http2_connection_error',
                            );
                            \Weline\Framework\Manager\ObjectManager::clearRequestScopeForFiber($closingFiber);
                            $fiberScheduler->unregisterFiber();
                            $activeRequests = \max(0, $activeRequests - 1);
                        }
                        unset($activeFibers[$closingFiberKey]);
                    }
                    $http2PendingRequests[$connId] = [];
                    if (($writeBuffers[$connId] ?? '') !== '') {
                        $pendingClose[$connId] = true;
                        $writableConnections[$connId] = $conn;
                    } else {
                        safeCloseStream($conn);
                        unset(
                            $connections[$connId],
                            $requestBuffers[$connId],
                            $connectionLastActivity[$connId],
                            $requestLogged[$connId],
                            $pendingClose[$connId]
                        );
                    }
                    unset($connectionProtocols[$connId], $http2ConnectionAdapters[$connId]);
                    continue;
                }

                foreach ((array)($http2Result['requests'] ?? []) as $http2Request) {
                    if (\is_array($http2Request)) {
                        $http2PendingRequests[$connId][] = $http2Request;
                    }
                }
            }
            if (empty($http2PendingRequests[$connId])) {
                continue;
            }
            $http2QueuedResponseBytes = \strlen((string)($writeBuffers[$connId] ?? ''))
                + $http2Adapter->pendingResponseBytes();
            if ($http2ParsedAdmissionsThisLoop >= $http2ParsedAdmissionBudget
                || $http2QueuedResponseBytes >= $http2AdmissionWriteHighWatermark
            ) {
                continue;
            }
            $http2Request = \array_shift($http2PendingRequests[$connId]);
            $http2ParsedAdmissionsThisLoop++;
            $http2AdmissionLastConnId = $connId;
            if (!empty($http2PendingRequests[$connId])
                && $http2ParsedAdmissionsThisLoop < $http2ParsedAdmissionBudget
                && isset($connections[$connId])
                && !isset($pendingClose[$connId])
            ) {
                // 尾插而非原地 while：先让本轮其它 ready 连接各处理一次，再回到该连接。
                $read[] = $conn;
                $readWorkCount++;
            }
            $rawRequest = (string)($http2Request['raw_request'] ?? '');
            $http2StreamId = (int)($http2Request['stream_id'] ?? 0);
            if ($rawRequest === '' || $http2StreamId <= 0) {
                continue;
            }
            $frame = wlsParseHttpRequestFrame(
                $rawRequest,
                $maxRequestHeaderBytes,
                $maxRequestBodyBytes,
            );
            if (($frame['status'] ?? '') !== 'complete'
                || (int)($frame['consumed'] ?? 0) !== \strlen($rawRequest)
            ) {
                // A decoded H2 stream is already one complete request unit.
                // If the shared HTTP frame parser cannot prove that exact
                // unit complete, answer only this stream with a fixed framing
                // error (missing authority is 421). Never manufacture a
                // complete frame or let unparsed bytes reach application code.
                unset($postHandshakeReadPending[$connId]);
                \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->markRequestComplete(
                    (string)$connId,
                );
                if (!isset($requestLogged[$connId])) {
                    $requestCount++;
                }
                unset($requestLogged[$connId]);
                $activeRequests++;
                sslFinalizeHttpResponseAfterHandle(
                    $conn,
                    $connId,
                    $rawRequest,
                    wlsServingManifestFramingErrorResponse($frame),
                    wlsWorkerMonotonicNow(),
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
                    true,
                    null,
                    null,
                    false,
                    $http2Adapter,
                    $http2StreamId,
                );
                continue;
            }
            $frame['protocol'] = 'h2';
            $frame['stream_id'] = $http2StreamId;
            unset($postHandshakeReadPending[$connId]);
            \Weline\Server\Security\ConnectionAcceptGatePool::instanceOrNull()?->markRequestComplete((string)$connId);
            if (!isset($requestLogged[$connId])) {
                $requestCount++;
            }
            unset($requestLogged[$connId]);
            $activeRequests++;
            goto wls_ssl_request_frame_complete;
        }

        if ($data !== '') {
            // 更新连接最后活动时间
            $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
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
            @\fwrite($conn, wlsServingManifestFramingErrorResponse($frame));
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

        wls_ssl_request_frame_complete:
        $http2ResponseStreamId = (($frame['protocol'] ?? '') === 'h2')
            ? (int)($frame['stream_id'] ?? 0)
            : 0;
        $http2ResponseAdapter = $http2ResponseStreamId > 0
            ? ($http2ConnectionAdapters[$connId] ?? null)
            : null;
        if (!$http2ResponseAdapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter) {
            $http2ResponseAdapter = null;
            $http2ResponseStreamId = 0;
        }
        $transportPeerRaw = @\stream_socket_get_name($conn, true);
        $transportPeer = $connectionPeerIps[$connId]
            ?? (\is_string($transportPeerRaw) ? $transportPeerRaw : '');
        $policyStartedAt = wlsWorkerMonotonicNow();
        $frameHasHeaders = \is_array($frame['headers'] ?? null);
        $frameHeaders = $frameHasHeaders
            ? $frame['headers']
            : [];
        $plaintextHost = $connectionPlaintextHosts[$connId] ?? null;
        $plaintextAction = \is_string($plaintextHost)
            ? wlsServingManifestPlaintextRequestAction(
                $frame,
                $plaintextHost,
                $servingManifestHttpRoutes,
            )
            : null;
        $mustRejectMisdirected = !$frameHasHeaders
            || ($plaintextAction === null
                ? !wlsServingManifestHostMatchesSni(
                    (string)($frameHeaders['host'] ?? ''),
                    (string)($connectionSniHosts[$connId] ?? ''),
                    $servingManifestRoutes,
                )
                : $plaintextAction === 'misdirected');
        if ($mustRejectMisdirected) {
            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                wlsServingManifestMisdirectedResponse(),
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
                true,
                null,
                null,
                false,
                $http2ResponseAdapter,
                $http2ResponseStreamId,
            );
            continue;
        }
        if ($plaintextAction === 'redirect_https') {
            $redirectHost = wlsServingManifestNormalizeAuthority(
                (string)($frameHeaders['host'] ?? ''),
            );
            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                wlsServingManifestHttpsRedirectResponse(
                    (string)$redirectHost,
                    (string)($frame['target'] ?? '/'),
                    (int)$port,
                ),
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
                true,
                (string)$redirectHost,
                null,
                false,
                $http2ResponseAdapter,
                $http2ResponseStreamId,
            );
            continue;
        }
        if ($plaintextAction === 'not_found') {
            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                wlsServingManifestNotFoundResponse(),
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
                true,
                null,
                null,
                false,
                $http2ResponseAdapter,
                $http2ResponseStreamId,
            );
            continue;
        }
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
                $activeRequests,
                true,
                null,
                null,
                false,
                $http2ResponseAdapter,
                $http2ResponseStreamId,
            );
            continue;
        }

        // ACME HTTP-01 is a transport-owned response. Resolve it after the
        // mandatory request policy and exact cleartext Host/route fence,
        // but before canonical redirects, Static/FPC or Framework dispatch.
        $acmeHttp01Response = $plaintextAction === 'acme_http01'
            ? wlsAcmeHttp01ChallengeResponse($policyDecision)
            : null;
        if ($acmeHttp01Response !== null) {
            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                $acmeHttp01Response,
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
                false,
                $http2ResponseAdapter,
                $http2ResponseStreamId,
            );
            continue;
        }

        $uri = $policyDecision->path;
        $method = $policyDecision->method;

        // force_root_to_www：HTTPS 下根域 301 到 www 子域（在框架处理前拦截）
        $_reqHost = (string)($policyDecision->headers['host'] ?? '');
        $_hostOnly = wlsServingManifestNormalizeAuthority($_reqHost);
        if ($_hostOnly !== null && $plaintextAction === null) {
                $_p = _getDomainPolicy((string)$_hostOnly, $servingManifestRoutes);
                if ($_p['force_root_to_www'] === 1) {
                    if ($_p['root_to_www_target_ready'] !== 1) {
                        sslFinalizeHttpResponseAfterHandle(
                            $conn,
                            $connId,
                            $rawRequest,
                            wlsServingManifestRedirectTargetUnavailableResponse(),
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
                            true,
                            $_hostOnly,
                            null,
                            false,
                            $http2ResponseAdapter,
                            $http2ResponseStreamId,
                        );
                        continue;
                    }
                    $_reqPath = $policyDecision->path !== '' ? $policyDecision->path : '/';
                    $_wwwHost = (string)$_p['root_to_www_target'];
                    $_redirectPort = (int)$port;
                    $_wwwUrl = ($_redirectPort === 443)
                        ? "https://{$_wwwHost}{$_reqPath}"
                        : "https://{$_wwwHost}:{$_redirectPort}{$_reqPath}";
                    $_resp = "HTTP/1.1 301 Moved Permanently\r\nLocation: {$_wwwUrl}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
                    sslFinalizeHttpResponseAfterHandle(
                        $conn,
                        $connId,
                        $rawRequest,
                        $_resp,
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
                        true,
                        $_hostOnly,
                        null,
                        false,
                        $http2ResponseAdapter,
                        $http2ResponseStreamId,
                    );
                    continue;
                }
        }

        // The mandatory policy kernel and canonical-host redirect have already
        // completed. Exact, non-diagnostic HTTP/1.1 health requests do not need
        // Static/FPC/Fiber/request-scope work, but still use the normal response
        // finalizer for benchmark identity, short writes and keep-alive cleanup.
        if (($frame['protocol'] ?? '') !== 'h2'
            && $method === 'GET'
            && $uri === '/_wls/health'
            && $policyDecision->target === '/_wls/health'
        ) {
            $keepAlive = $policyDecision->keepAlive();
            $allowedHealth = $workerHealthAccessPolicy->allowsClient(
                $policyDecision->clientIp,
                $policyDecision->headers,
            );
            if ($allowedHealth) {
                $healthResponse = $keepAlive
                    ? "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nOK"
                    : "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK";
            } else {
                $healthResponse = $keepAlive
                    ? "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: keep-alive\r\n\r\nForbidden"
                    : "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: close\r\n\r\nForbidden";
            }

            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                $healthResponse,
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
                $keepAlive,
                false,
            );
            continue;
        }

        if (($frame['protocol'] ?? '') === 'h2'
            && $method === 'GET'
            && $uri === '/_wls/health'
            && $policyDecision->target === '/_wls/health'
            && !\str_contains($rawRequest, 'detail=1')
            && !\str_contains($rawRequest, 'detail=true')
            && !\str_contains($rawRequest, 'memory=1')
            && !\str_contains($rawRequest, 'memory=true')
            && !\str_contains($rawRequest, 'static=1')
            && !\str_contains($rawRequest, 'static=true')
            && !\str_contains($rawRequest, 'objects=1')
            && !\str_contains($rawRequest, 'objects=true')
        ) {
            $http2Adapter = $http2ConnectionAdapters[$connId] ?? null;
            $http2StreamId = (int)($frame['stream_id'] ?? 0);
            if ($http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter && $http2StreamId > 0) {
                $allowedHealth = $workerHealthAccessPolicy->allowsClient(
                    $policyDecision->clientIp,
                    $policyDecision->headers,
                );
                $body = $allowedHealth ? 'OK' : 'Forbidden';
                $statusCode = $allowedHealth ? 200 : 403;
                $h2Response = $http2Adapter->encodeSimpleResponse($http2StreamId, $statusCode, [
                    'content-length' => \strlen($body),
                    'x-wls-worker-id' => $workerId,
                    'x-wls-worker-port' => $port,
                    'x-wls-worker-pid' => \getmypid(),
                ], $body);
                $activeRequests = \max(0, $activeRequests - 1);
                $existingBuffer = (string)($writeBuffers[$connId] ?? '');
                if ($existingBuffer !== '') {
                    $writeBuffers[$connId] = $existingBuffer . $h2Response;
                    $writableConnections[$connId] = $conn;
                    continue;
                }
                $written = @\fwrite($conn, $h2Response);
                if ($written === false) {
                    safeCloseStream($conn);
                    unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $pendingClose[$connId]);
                    unset($longLivedConnections[$connId]);
                    continue;
                }
                if ($written < \strlen($h2Response)) {
                    $writeBuffers[$connId] = \substr($h2Response, \max(0, (int)$written));
                    $writableConnections[$connId] = $conn;
                }
                $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
                continue;
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
                (wlsWorkerMonotonicNow() - $policyStartedAt) * 1000
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
                false,
                $http2ResponseAdapter,
                $http2ResponseStreamId,
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
                $fastPathElapsedMs = (float)\round((wlsWorkerMonotonicNow() - $policyStartedAt) * 1000, 2);
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
                    $http2ResponseAdapter,
                    $http2ResponseStreamId,
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
        $isWebSocketProtocolRequest = \in_array(\strtolower($requestProtocol), ['websocket', 'ws'], true);
        $applyLongLivedLimit = !$isSseProtocolRequest;

        // RFC 8441 WebSocket-over-H2 尚未实现，必须保持显式失败；SSE 则由
        // ConnectionAdapter 转换为原生 HEADERS + 增量 DATA，不走 H1 字节流。
        if ($http2ResponseStreamId > 0 && $isWebSocketProtocolRequest) {
            $body = 'HTTP/2 WebSocket upgrade is not available';
            $response = "HTTP/1.1 501 Not Implemented\r\n"
                . "Content-Type: text/plain; charset=utf-8\r\n"
                . 'Content-Length: ' . \strlen($body) . "\r\n\r\n"
                . $body;
            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                $response,
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
                true,
                null,
                true,
                false,
                $http2ResponseAdapter,
                $http2ResponseStreamId,
            );
            continue;
        }

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
        $handleStartTime = wlsWorkerMonotonicNow();

        // H1 长连接以 TCP 连接为生命周期单位；H2 SSE 以 stream 为单位，
        // 不能写入 longLivedConnections，否则该 TLS 连接会停止读取
        // WINDOW_UPDATE/RST_STREAM，也会阻断同连接其它普通 H2 stream。
        if ($isLongLived && $http2ResponseStreamId <= 0) {
            $layer = (string) ($longLivedDetection['layer'] ?? 'unknown');
            $protocol = (string) ($longLivedDetection['protocol'] ?? 'long-lived');
            WlsLogger::info_("长链分层命中: layer={$layer}, protocol={$protocol}, connId={$connId}");
            $quotaLongLivedCount = wlsDrainConnectionCounters(
                $longLivedConnections,
            )['quota_connections'];
            if ($applyLongLivedLimit
                && $longLivedMaxActive > 0
                && $quotaLongLivedCount >= $longLivedMaxActive
            ) {
                $isWorkspaceStreamSse = $isSseProtocolRequest && \str_contains($rawRequest, '/stream-sse');
                if ($isWorkspaceStreamSse) {
                    $waitDeadline = wlsWorkerMonotonicNow() + 1.2;
                    while (wlsWorkerMonotonicNow() < $waitDeadline
                        && $quotaLongLivedCount >= $longLivedMaxActive
                    ) {
                        foreach (\array_keys($longLivedConnections) as $llConnId) {
                            $llConn = $connections[$llConnId] ?? null;
                            if (!$llConn || !\is_resource($llConn)) {
                                unset($longLivedConnections[$llConnId]);
                            }
                        }
                        $quotaLongLivedCount = wlsDrainConnectionCounters(
                            $longLivedConnections,
                        )['quota_connections'];
                        if ($quotaLongLivedCount < $longLivedMaxActive) {
                            break;
                        }
                        \Weline\Framework\Runtime\SchedulerSystem::yieldDelay(50);
                    }
                }
            }
            if ($applyLongLivedLimit
                && $longLivedMaxActive > 0
                && $quotaLongLivedCount >= $longLivedMaxActive
            ) {
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
            $longLivedConnections[$connId] = [
                'type' => $protocol,
                'start' => \time(),
                'quota_exempt' => !$applyLongLivedLimit,
            ];
            if (\in_array(\strtolower($protocol), ['websocket', 'ws'], true)) {
                $longLivedConnections[$connId]['upgraded'] = false;
                $longLivedConnections[$connId]['websocket_state'] =
                    \Weline\Server\IPC\ControlMessage::webSocketInitialState();
                $longLivedConnections[$connId]['resume_pending'] = false;
                $longLivedConnections[$connId]['read_pump_pending'] = false;
            }
            if ($applyLongLivedLimit) {
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
            if ($http2ResponseStreamId > 0
                && $http2ResponseAdapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
            ) {
                $body = 'Service Unavailable';
                $resp = "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: "
                    . \strlen($body) . "\r\n\r\n" . $body;
                sslFinalizeHttpResponseAfterHandle(
                    $conn,
                    $connId,
                    $rawRequest,
                    $resp,
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
                    true,
                    null,
                    true,
                    false,
                    $http2ResponseAdapter,
                    $http2ResponseStreamId,
                );
                WlsLogger::warning_(
                    "Fiber 池已满 (max_active={$fiberMaxActive})，拒绝 HTTP/2 stream "
                    . "(connId: {$connId}, streamId: {$http2ResponseStreamId})"
                );
                continue;
            }
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
        $fiberHttp2StreamId = $http2ResponseStreamId;
        $fiberHttp2Adapter = $http2ResponseAdapter;
        $fiberKey = $fiberHttp2StreamId > 0
            ? \Weline\Server\Protocol\Http2\MultiplexScheduler::key($fiberConnId, $fiberHttp2StreamId)
            : $fiberConnId;
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
            $fiberHttp2StreamId,
            $fiberHttp2Adapter,
            $isSseProtocolRequest,
            &$ipcDraining,
            &$drainStartTime,
            &$maxDrainTime,
            &$requestBuffers,
            &$connectionLastActivity,
            &$requestLogged,
            &$writeBuffers,
            &$writableConnections,
            &$pendingClose,
            &$longLivedConnections,
            &$http2ConnectionAdapters,
        ) {
            wlsFiberRequestContextEnter($fiberConn, $fiberConnId);
            try {
                if ($isSseProtocolRequest) {
                    if ($fiberHttp2StreamId > 0
                        && $fiberHttp2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
                    ) {
                        \Weline\Framework\Http\Sse\SseContext::setWriteCallback(
                            static function (string $data) use (
                                $fiberConnId,
                                $fiberConn,
                                $fiberHttp2StreamId,
                                $fiberHttp2Adapter,
                                &$connections,
                                &$http2ConnectionAdapters,
                                &$connectionLastActivity,
                                &$writeBuffers,
                                &$writableConnections,
                                &$pendingClose,
                            ): bool {
                                return enqueueHttp2SseWrite(
                                    $fiberConnId,
                                    $fiberConn,
                                    $fiberHttp2StreamId,
                                    $fiberHttp2Adapter,
                                    $data,
                                    $connections,
                                    $http2ConnectionAdapters,
                                    $connectionLastActivity,
                                    $writeBuffers,
                                    $writableConnections,
                                    $pendingClose,
                                );
                            },
                        );
                        \Weline\Framework\Http\Sse\SseContext::setAliveCallback(
                            static function () use (
                                $fiberConnId,
                                $fiberConn,
                                $fiberHttp2StreamId,
                                $fiberHttp2Adapter,
                                &$connections,
                                &$http2ConnectionAdapters,
                                &$pendingClose,
                                &$ipcDraining,
                                &$drainStartTime,
                                &$maxDrainTime,
                            ): bool {
                                return wlsSslIsHttp2SseStreamAlive(
                                    $fiberConnId,
                                    $fiberConn,
                                    $fiberHttp2StreamId,
                                    $fiberHttp2Adapter,
                                    $connections,
                                    $http2ConnectionAdapters,
                                    $pendingClose,
                                    $ipcDraining,
                                    $drainStartTime,
                                    $maxDrainTime,
                                );
                            },
                        );
                    } else {
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
                    $policyDecision,
                    $longLivedConnections,
                    $http2ConnectionAdapters,
                    $connections,
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
            $handleDurationMs = (wlsWorkerMonotonicNow() - $handleStartTime) * 1000;
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
                $activeRequests,
                true,
                null,
                null,
                false,
                $fiberHttp2Adapter,
                $fiberHttp2StreamId,
            );
            wlsDrainAfterResponseIfRequested($socket, $shouldExit, $ipcDraining, $drainStartTime, $maxDrainTime);
        } elseif ($requestFiber->isSuspended()) {
            $fiberActivityWall = \time();
            $fiberActivityMonotonicNs =
                \Weline\Server\Runtime\WorkerFiberContextTracker::monotonicNowNs();
            $activeFibers[$fiberKey] = [
                'fiber' => $requestFiber,
                'conn' => $fiberConn,
                'conn_id' => $fiberConnId,
                'http2_stream_id' => $fiberHttp2StreamId,
                'http2_adapter' => $fiberHttp2Adapter,
                'rawRequest' => $rawRequest,
                'handleStartTime' => $handleStartTime,
                'context' => wlsCaptureSuspendedRequestFiberOrQuarantine($requestFiber),
                'suspended_at' => $fiberActivityWall,
                'last_activity' => $fiberActivityWall,
                'suspended_at_monotonic_ns' => $fiberActivityMonotonicNs,
                'last_activity_monotonic_ns' => $fiberActivityMonotonicNs,
                'is_long_lived' => $isLongLived,
                'is_sse_protocol' => $isSseProtocolRequest,
            ];
            if (WLS_WORKER_HOT_PATH_LOGS_ENABLED) {
                WlsLogger::info_("请求进入 Fiber 异步模式 (connId: {$connId})");
            }
            // A normal suspended request did not mutate the long-lived
            // registry. Sample only for a long-lived request or while a
            // previously reported saturation still needs its cleared event.
            $shouldSampleLongLivedSaturation = $isLongLived
                || ($longLivedSaturationReported && !$longLivedSaturationCleared);
            if ($shouldSampleLongLivedSaturation && $longLivedMaxActive > 0) {
                $nowSat = wlsWorkerMonotonicNow();
                $quotaLongLivedCount = wlsDrainConnectionCounters(
                    $longLivedConnections,
                )['quota_connections'];
                $isSaturated = $quotaLongLivedCount >= $longLivedMaxActive;
                if (
                    $isSaturated
                    && !$longLivedSaturationReported
                    && ($nowSat - $lastLongLivedSaturationReport) >= $longLivedSaturationInterval
                ) {
                    if ($ipcClient && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::workerSaturation(
                            $workerId,
                            $port,
                            $quotaLongLivedCount,
                            $longLivedMaxActive,
                            \count($activeFibers),
                            $fiberMaxActive
                        ));
                        $lastLongLivedSaturationReport = $nowSat;
                        $longLivedSaturationReported = true;
                        $longLivedSaturationCleared = false;
                        WlsLogger::warning_(
                            '长连接饱和上报 (long_lived_count=' . $quotaLongLivedCount
                            . ", max={$longLivedMaxActive})"
                        );
                    }
                } elseif (!$isSaturated && $longLivedSaturationReported && !$longLivedSaturationCleared) {
                    if ($ipcClient && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::workerSaturationCleared(
                            $workerId,
                            $port,
                            $quotaLongLivedCount,
                            $longLivedMaxActive
                        ));
                        $longLivedSaturationReported = false;
                        $longLivedSaturationCleared = true;
                        WlsLogger::info_(
                            '长连接饱和解除 (long_lived_count=' . $quotaLongLivedCount . ')'
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
        $writeZeroProgress,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $pendingClose,
        $longLivedConnections,
        $http2ConnectionAdapters
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
 * @param array<int, float> $connectionLastActivity
 * @param array<int, array{conn: resource, peerName: string, buffer: string}> $pendingPeek
 * @param array<int, float> $pendingPeekStartTimes
 */
/**
 * @param array<int, array<string, mixed>> $activeFibers
 */
if (!\function_exists('wlsCountActiveFibersForAdmission')) {
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
}

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
    array &$connectionSniHosts,
    array &$connectionPlaintextHosts,
    int $maxAcceptPerLoop = 64,
    bool $applicationAdmissionOpen = true,
    mixed $nativeListenerSocket = null,
    bool $rejectWithoutAdmission = false,
): int {
    if (!$socket || !\is_resource($socket) || !\in_array($socket, $read, true)) {
        return 0;
    }

    if (!$applicationAdmissionOpen && !$rejectWithoutAdmission) {
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
            if ($rejectWithoutAdmission) {
                @\socket_close($acceptedSocket);
                continue;
            }
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
            if ($rejectWithoutAdmission) {
                safeCloseStream($conn);
                continue;
            }
        }
        $connId = \get_resource_id($conn);
        // PHP may reuse a closed stream resource id before the next event-loop
        // cleanup sweep. Never let the previous connection's transport
        // authority classify a newly accepted socket.
        unset(
            $connectionPeerIps[$connId],
            $connectionSniHosts[$connId],
            $connectionPlaintextHosts[$connId],
        );
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
            $pendingPeekStartTimes[$connId] = wlsWorkerMonotonicNow();
        } else {
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
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
 * defer-ssl：只按当前 manifest 中的 SNI 路由选择证书。
 *
 * @param array<string, array{local_cert: string, local_pk: string}> $sniServerCerts
 * @return array{local_cert:string,local_pk:string}|null
 */
function wlsSslPickCertificatePairForDeferSni(
    ?string $sniHost,
    array $sniServerCerts,
    array $servingRoutes,
): ?array {
    if ($sniHost === null || $sniHost === '') {
        return null;
    }
    $route = wlsServingManifestRouteForHost($sniHost, $servingRoutes);
    if (!\is_array($route)) {
        return null;
    }
    $domain = (string)($route['domain'] ?? '');
    $pair = $sniServerCerts[$domain] ?? null;
    return \is_array($pair) ? $pair : null;
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
    int $cryptoMethod,
    ?\Weline\Server\Service\Runtime\TlsSessionCacheRuntime $tlsSessionCacheRuntime = null,
    string $effectiveSni = ''
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
        'alpn_protocols' => \is_array($deferSslOptionsTemplate) ? (string)($deferSslOptionsTemplate['alpn_protocols'] ?? 'http/1.1') : 'http/1.1',
        'SNI_enabled' => false,
    ];
    if ($tlsSessionCacheRuntime !== null) {
        $opts = \array_replace(
            $opts,
            $tlsSessionCacheRuntime->streamContextOptions($effectiveSni, $pair),
        );
    }
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
 * @param array<int, float> $connectionLastActivity
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
    array $servingRoutes,
    array $servingHttpRoutes,
    int $publicTcpPort,
    array &$connectionPeerIps = [],
    string $runtimeTopology = 'direct',
    string $proxyAuthenticationSecret = '',
    array &$connectionProtocols = [],
    array &$http2ConnectionAdapters = [],
    ?\Weline\Server\Service\Runtime\TlsSessionCacheRuntime $tlsSessionCacheRuntime = null,
    array &$connectionSniHosts = [],
    array &$connectionPlaintextHosts = [],
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
        $startTime = $pendingPeekStartTimes[$connId] ?? wlsWorkerMonotonicNow();
        $elapsed = wlsWorkerMonotonicNow() - $startTime;
        if ($elapsed > $peekTimeout) {
            $failedPeeks[] = $connId;
            WlsLogger::warning_("Peek 超时: {$peerName} (connId: {$connId})");
            continue;
        }

        $trustedDispatcherBackend = $runtimeTopology === 'dispatcher'
            && \Weline\Server\Protocol\ProxyProtocolV2::isLoopbackPeer($peerName);
        $proxyAlreadyConsumed = ($peekInfo['phase'] ?? '') === 'proxy_consumed';
        if (!isset($readyPeekIds[$connId]) && !$proxyAlreadyConsumed) {
            continue;
        }

        if ($trustedDispatcherBackend && !$proxyAlreadyConsumed) {
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
            $pendingPeek[$connId]['phase'] = 'proxy_consumed';
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

        // Same-port cleartext admission is governed by the complete desired
        // HTTP route set, never by the TLS-active certificate subset.
        if (\ord($peeked[0]) !== 0x16) {
            // A non-consuming peek may contain only one byte of an HTTP
            // method. Keep the connection pending until the complete request
            // head is available; admission from a partial Host would make
            // normal TCP segmentation observable as a 421/close.
            $_looksLikeHttpPrefix = \preg_match(
                '/\A[A-Z][A-Z0-9-]{0,31}(?:[ \t]|$)/D',
                $peeked,
            ) === 1;
            $_headerEnd = \strpos($peeked, "\r\n\r\n");
            if ($_looksLikeHttpPrefix && $_headerEnd === false && \strlen($peeked) < 65536) {
                continue;
            }
            $_requestLineValid = $_headerEnd !== false
                && \preg_match(
                    '/\A([A-Z][A-Z0-9-]{0,31})\s+(\S{1,65535})\s+HTTP\/(1\.0|1\.1)\r\n/D',
                    $peeked,
                    $_requestLine,
                ) === 1;
            $_hostMatches = [];
            $_hostCount = $_requestLineValid
                ? \preg_match_all(
                    '/(?:\A|\r\n)Host:[ \t]*([^\r\n]*)/i',
                    \substr($peeked, 0, (int)$_headerEnd),
                    $_hostMatches,
                )
                : 0;
            $_host = $_hostCount === 1
                ? wlsServingManifestNormalizeAuthority(
                    (string)($_hostMatches[1][0] ?? ''),
                )
                : null;
            if ($_requestLineValid && $_host !== null) {
                $_preflightFrame = [
                    'headers' => ['host' => $_host],
                    'method' => (string)$_requestLine[1],
                    'target' => (string)$_requestLine[2],
                ];
                $_plaintextAction = wlsServingManifestPlaintextRequestAction(
                    $_preflightFrame,
                    $_host,
                    $servingHttpRoutes,
                );
                if (\in_array(
                    $_plaintextAction,
                    ['allow', 'acme_http01'],
                    true,
                )) {
                    $connections[$connId] = $conn;
                    $requestBuffers[$connId] = '';
                    $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
                    $connectionProtocols[$connId] = 'http/1.1';
                    $connectionPlaintextHosts[$connId] = (string)$_host;
                    unset($connectionSniHosts[$connId]);
                    $completedPeeks[] = $connId;
                    continue;
                }
                if ($_plaintextAction === 'redirect_https') {
                    $_resp = wlsServingManifestHttpsRedirectResponse(
                        $_host,
                        (string)$_requestLine[2],
                        $publicTcpPort,
                    );
                } elseif ($_plaintextAction === 'not_found') {
                    $_resp = wlsServingManifestNotFoundResponse();
                } else {
                    $_resp = wlsServingManifestMisdirectedResponse();
                }
                @\fwrite($conn, $_resp);
            }
            safeCloseStream($conn);
            $completedPeeks[] = $connId;
            continue;
        }

        $clientHelloInspection =
            \Weline\Server\Dispatcher\SniParser::inspectClientHelloFlight($peeked);
        $clientHelloStatus = (string)($clientHelloInspection['status'] ?? 'invalid');
        if ($clientHelloStatus === 'incomplete') {
            continue;
        }
        if ($clientHelloStatus !== 'complete') {
            $failedPeeks[] = $connId;
            WlsLogger::warning_(
                'Peek TLS ClientHello flight invalid: '
                . (string)($clientHelloInspection['reason'] ?? 'unknown')
                . " ({$peerName}, connId: {$connId})"
            );
            continue;
        }

        $sniRaw = _parseSniHostFromClientHello($peeked);
        // ClientHello only lists what the client offered; it is not the final
        // negotiated protocol. Classify after TLS from the decrypted H2 preface.
        $connectionProtocols[$connId] = 'pending';
        unset($http2ConnectionAdapters[$connId]);
        $sniHostNorm = $sniRaw !== null
            ? wlsServingManifestNormalizeAuthority((string)$sniRaw)
            : null;
        $effectiveHost = $sniHostNorm;
        $pair = wlsSslPickCertificatePairForDeferSni(
            $effectiveHost,
            $sniServerCerts,
            $servingRoutes,
        );
        if (!\is_array($pair)
            && ($effectiveHost === null || $effectiveHost === '')
        ) {
            // Browsers/clients opening https://127.0.0.1 often omit SNI for IP
            // literals. Reuse only an explicit loopback tenant route
            // (localhost/127.0.0.1/::1) — never a public wildcard default.
            $pair = wlsSslPickCertificatePairForDeferSni(
                '127.0.0.1',
                $sniServerCerts,
                $servingRoutes,
            );
            if (\is_array($pair)) {
                $effectiveHost = '127.0.0.1';
            }
        }
        if (!\is_array($pair)) {
            // Empty or unknown SNI must not receive a default tenant
            // certificate. Close before OpenSSL sees any private-key context.
            safeCloseStream($conn);
            unset($connectionSniHosts[$connId]);
            $completedPeeks[] = $connId;
            continue;
        }
        $connectionSniHosts[$connId] = (string)$effectiveHost;
        wlsSslApplyPerConnectionSslForDeferHandshake(
            $conn,
            $pair,
            $deferSslOptions,
            $cryptoMethod,
            $tlsSessionCacheRuntime,
            (string)($effectiveHost ?? ''),
        );
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
            $tlsSessionCacheRuntime?->recordHandshakeResult($conn);
            if ($isDev) {
                WlsLogger::info_("SSL 握手成功: {$peerName} (connId: {$connId})");
            }
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
            $postHandshakeReadPending[$connId] = [
                'conn' => $conn,
                'deadline' => wlsWorkerMonotonicNow() + 0.20,
            ];
            $completedPeeks[] = $connId;
            continue;
        }

        $pendingHandshakes[$connId] = [
            'conn' => $conn,
            'peerName' => $peerName,
            'phase' => 'pending',
        ];
        $handshakeStartTimes[$connId] = wlsWorkerMonotonicNow();
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
 * @param array<int, float> $connectionLastActivity
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
    bool $isDev,
    ?\Weline\Server\Service\Runtime\TlsSessionCacheRuntime $tlsSessionCacheRuntime = null
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
        $now = wlsWorkerMonotonicNow();
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
        $startTime = $handshakeStartTimes[$connId] ?? wlsWorkerMonotonicNow();
        $elapsed = wlsWorkerMonotonicNow() - $startTime;
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
            $tlsSessionCacheRuntime?->recordHandshakeResult($conn);
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
        $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
        $postHandshakeReadPending[$connId] = [
            'conn' => $conn,
            'deadline' => wlsWorkerMonotonicNow() + 0.20,
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

function wlsSslIsActiveWebSocketConnection(mixed $state): bool
{
    return \is_array($state)
        && ($state['upgraded'] ?? false) === true
        && \in_array(\strtolower((string)($state['type'] ?? '')), ['websocket', 'ws'], true);
}

/**
 * Advance one non-blocking WebSocket read turn on the production TLS worker.
 * WebSocket remains an HTTP/1.1 upgrade; ALPN-selected HTTP/2 never enters here.
 */
function wlsSslWebSocketReadStep(
    mixed $conn,
    int $connId,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose,
    array &$longLivedConnections,
    array &$writeZeroProgress,
    array &$postHandshakeReadPending,
    array &$connectionPeerIps,
    array &$connectionProtocols,
    array &$connectionSniHosts,
    array &$connectionPlaintextHosts,
    array &$http2ConnectionAdapters,
    array &$http2PendingRequests,
): void {
    $closeConnection = static function () use (
        $conn,
        $connId,
        &$connections,
        &$requestBuffers,
        &$connectionLastActivity,
        &$requestLogged,
        &$writeBuffers,
        &$writableConnections,
        &$pendingClose,
        &$longLivedConnections,
        &$writeZeroProgress,
        &$postHandshakeReadPending,
        &$connectionPeerIps,
        &$connectionProtocols,
        &$connectionSniHosts,
        &$connectionPlaintextHosts,
        &$http2ConnectionAdapters,
        &$http2PendingRequests,
    ): void {
        safeCloseStream($conn);
        unset(
            $connections[$connId],
            $requestBuffers[$connId],
            $connectionLastActivity[$connId],
            $requestLogged[$connId],
            $writeBuffers[$connId],
            $writableConnections[$connId],
            $pendingClose[$connId],
            $longLivedConnections[$connId],
            $writeZeroProgress[$connId],
            $postHandshakeReadPending[$connId],
            $connectionPeerIps[$connId],
            $connectionProtocols[$connId],
            $connectionSniHosts[$connId],
            $connectionPlaintextHosts[$connId],
            $http2ConnectionAdapters[$connId],
            $http2PendingRequests[$connId],
        );
    };

    if (!\is_resource($conn)
        || !isset($connections[$connId])
        || $connections[$connId] !== $conn
        || ($connectionProtocols[$connId] ?? '') !== 'http/1.1'
        || !wlsSslIsActiveWebSocketConnection($longLivedConnections[$connId] ?? null)
    ) {
        $closeConnection();
        return;
    }

    $registration = $longLivedConnections[$connId];
    $bytes = (string)($requestBuffers[$connId] ?? '');
    $requestBuffers[$connId] = '';
    $frameResumePending = ($registration['resume_pending'] ?? false) === true;
    $readPumpPending = ($registration['read_pump_pending'] ?? false) === true
        || $frameResumePending
        || $bytes !== '';
    $readFromStream = false;
    if ($bytes === '' && !$frameResumePending) {
        $bytes = @\fread($conn, 65535);
        $readFromStream = true;
        if ($bytes === false || ($bytes === '' && @\feof($conn))) {
            $closeConnection();
            return;
        }
        if ($bytes === '') {
            $longLivedConnections[$connId]['read_pump_pending'] = false;
            return;
        }
    }

    $result = \Weline\Server\IPC\ControlMessage::webSocketConsumeClientBytes(
        (array)($registration['websocket_state'] ?? []),
        $bytes,
    );
    $longLivedConnections[$connId]['websocket_state'] = $result['state'];
    $longLivedConnections[$connId]['resume_pending'] =
        ($result['frame_budget_exhausted'] ?? false) === true;
    $longLivedConnections[$connId]['read_pump_pending'] = $readFromStream
        ? \strlen($bytes) === 65535
        : $readPumpPending;
    if ($bytes !== '') {
        $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
    }

    if ($result['outbound'] !== []) {
        $writeBuffers[$connId] = ($writeBuffers[$connId] ?? '') . \implode('', $result['outbound']);
        $writableConnections[$connId] = $conn;
    }
    if (($result['error_code'] ?? null) !== null) {
        WlsLogger::warning_(
            'TLS WebSocket 协议错误，关闭连接 (connId: ' . $connId
            . ', close_code: ' . (int)$result['error_code'] . ')'
        );
    }
    if (($result['close_transport'] ?? false) !== true) {
        return;
    }

    if (($writeBuffers[$connId] ?? '') !== '') {
        $pendingClose[$connId] = true;
        return;
    }

    $closeConnection();
}

/**
 * 将 writeBuffers 中的数据写入 SSL 流（非阻塞 fwrite，单连接每轮最多尝试若干次）。
 * 供事件循环在 Fiber tick 之后及早调用，减轻 SSE 与同 Worker 其它 HTTP 请求之间的写方向头阻塞。
 *
 * @param array<int|string, resource> $writableConnections
 * @param array<int|string, string> $writeBuffers
 * @param array<int|string, array{connection: resource, started_at: float, retry_at: float, attempts: int}> $writeZeroProgress
 * @param array<int|string, \Weline\Server\Protocol\Http2\ConnectionAdapter> $http2ConnectionAdapters
 */
function wlsSslFlushQueuedWrites(
    int $activeRequests,
    array &$writableConnections,
    array &$writeBuffers,
    array &$writeZeroProgress,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$pendingClose,
    array &$longLivedConnections,
    array &$http2ConnectionAdapters
): void {
    $maxBytesPerConnectionPerLoop = 131072; // 128KB，分片推进上限
    $zeroProgressTimeoutSeconds = 5.0;
    $maxZeroProgressBackoffUsec = 50_000;
    // OpenSSL 会在内部按 TLS record 分片；PHP 层一次提交 64KB，避免
    // 中等响应反复 substr/复制剩余缓冲。每连接每轮仍受 128KB 总预算限制。
    $maxChunkPerWrite = 65536;
    foreach ($writableConnections as $connId => $conn) {
        $http2Adapter = $http2ConnectionAdapters[$connId] ?? null;
        if (!isset($writeBuffers[$connId]) || $writeBuffers[$connId] === '') {
            $nextHttp2Batch = $http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
                ? $http2Adapter->drainPendingResponseData()
                : '';
            if ($nextHttp2Batch !== '') {
                $writeBuffers[$connId] = $nextHttp2Batch;
                $writableConnections[$connId] = $conn;
            } else {
                unset($writeBuffers[$connId], $writableConnections[$connId], $writeZeroProgress[$connId]);
                $http2FlowControlledResponsePending =
                    $http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
                    && $http2Adapter->hasPendingResponseData();
                if (isset($pendingClose[$connId]) && !$http2FlowControlledResponsePending) {
                    safeCloseStream($conn);
                    unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $pendingClose[$connId]);
                    unset($longLivedConnections[$connId]);
                }
                wlsDrainPostResponseTasks($activeRequests, $requestBuffers, $writeBuffers, $connId);
                continue;
            }
        }
        if (!\is_resource($conn) || !\in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
            unset($writeBuffers[$connId], $writableConnections[$connId], $writeZeroProgress[$connId]);
            continue;
        }
        $retryState = $writeZeroProgress[$connId] ?? null;
        if (\is_array($retryState) && ($retryState['connection'] ?? null) !== $conn) {
            unset($writeZeroProgress[$connId]);
            $retryState = null;
        }
        if ((float) ($retryState['retry_at'] ?? 0.0) > wlsWorkerMonotonicNow()) {
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
                unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $writeZeroProgress[$connId], $pendingClose[$connId]);
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

            if (\is_int($written) && $written > 0) {
                // Keep-Alive is an inactivity budget, not an absolute response deadline.
                // Slow HTTP/2 clients can keep TLS/TCP back-pressured for longer than
                // the nominal idle timeout while bytes are still making progress.
                $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
            }

            if ($written === false) {
                WlsLogger::warning_("缓冲区写入失败 (connId: {$connId}, 剩余: {$bufferLen} 字节)");
                safeCloseStream($conn);
                unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $writeZeroProgress[$connId], $pendingClose[$connId]);
                unset($longLivedConnections[$connId]);
                if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($initialBufferLen)) {
                    \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                }
                break;
            }

            if ($written === 0) {
                $zeroNow = wlsWorkerMonotonicNow();
                $zeroState = $writeZeroProgress[$connId] ?? null;
                if (!\is_array($zeroState) || ($zeroState['connection'] ?? null) !== $conn) {
                    $zeroState = [
                        'connection' => $conn,
                        'started_at' => $zeroNow,
                        'retry_at' => $zeroNow,
                        'attempts' => 0,
                    ];
                }
                $zeroStartedAt = (float) ($zeroState['started_at'] ?? $zeroNow);
                $zeroAttempts = (int) ($zeroState['attempts'] ?? 0) + 1;
                $streamMeta = \get_resource_type($conn) === 'stream'
                    ? (@\stream_get_meta_data($conn) ?: [])
                    : [];
                // Read-side EOF may be a legal TCP half-close: the peer can still
                // receive this response. Only an explicit stream timeout or the
                // bounded zero-progress deadline makes the write side terminal.
                $streamTimedOut = (bool) ($streamMeta['timed_out'] ?? false);
                $zeroElapsed = $zeroNow - $zeroStartedAt;
                $zeroProgressBudgetSeconds = $zeroProgressTimeoutSeconds;
                if ($http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter) {
                    // A TLS stream can legitimately return zero while the kernel send
                    // queue drains. Size the H2 stall budget at a conservative 32 KiB/s,
                    // bounded to prevent a peer from pinning a Worker indefinitely.
                    $zeroProgressBudgetSeconds = \max(
                        $zeroProgressTimeoutSeconds,
                        \min(120.0, 5.0 + ($bufferLen / 32768.0))
                    );
                }

                if ($streamTimedOut || $zeroElapsed >= $zeroProgressBudgetSeconds) {
                    $reason = $streamTimedOut ? 'stream_timeout' : 'zero_progress_timeout';
                    WlsLogger::warning_(
                        "SSL 写队列无进展，关闭连接 (connId: {$connId}, reason: {$reason}, "
                        . 'elapsed_ms: ' . (int) \round($zeroElapsed * 1000)
                        . ", attempts: {$zeroAttempts}, 剩余: {$bufferLen} 字节)"
                    );
                    safeCloseStream($conn);
                    unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $writeZeroProgress[$connId], $pendingClose[$connId]);
                    unset($longLivedConnections[$connId]);
                    if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($initialBufferLen)) {
                        \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                    }
                    break;
                }

                $backoffUsec = \min(
                    $maxZeroProgressBackoffUsec,
                    1000 * (1 << \min(6, \max(0, $zeroAttempts - 1)))
                );
                $writeZeroProgress[$connId] = [
                    'connection' => $conn,
                    'started_at' => $zeroStartedAt,
                    'retry_at' => $zeroNow + ($backoffUsec / 1_000_000),
                    'attempts' => $zeroAttempts,
                ];
                break;
            }

            unset($writeZeroProgress[$connId]);
            $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
            $totalWrittenThisLoop += $written;
            $writeBuffers[$connId] = \substr($buffer, $written);

            if ($writeBuffers[$connId] === '' || $writeBuffers[$connId] === false) {
                $nextHttp2Batch = $http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
                    ? $http2Adapter->drainPendingResponseData()
                    : '';
                if ($nextHttp2Batch !== '') {
                    $writeBuffers[$connId] = $nextHttp2Batch;
                    $writableConnections[$connId] = $conn;
                    continue;
                }

                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);
                unset($writeZeroProgress[$connId]);

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
    wlsSharedFiberRequestContextEnter($conn, $connectionId);
}

/**
 * Fiber 请求结束后统一清台（成功/异常均执行）。
 */
function wlsFiberRequestContextLeave(): void
{
    wlsSharedFiberRequestContextLeave();
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

/**
 * H2 SSE stays alive only while the exact TLS connection, adapter and stream
 * identity are current. During Worker drain it receives the soft-deadline
 * budget to finish cooperatively; the existing hard deadline remains the
 * fail-safe that cancels a stubborn Fiber and retires the transport.
 *
 * @param array<int,resource> $connections
 * @param array<int,\Weline\Server\Protocol\Http2\ConnectionAdapter> $http2ConnectionAdapters
 */
function wlsSslIsHttp2SseStreamAlive(
    int $connId,
    mixed $conn,
    int $streamId,
    \Weline\Server\Protocol\Http2\ConnectionAdapter $adapter,
    array &$connections,
    array &$http2ConnectionAdapters,
    array &$pendingClose,
    bool $ipcDraining,
    float $drainStartTime,
    int $maxDrainTime,
): bool {
    if (($http2ConnectionAdapters[$connId] ?? null) !== $adapter
        || !$adapter->isStreamActive($streamId)
        || !wlsSslIsSseClientConnected($connId, $conn, $connections, $pendingClose)
    ) {
        return false;
    }

    if (!$ipcDraining) {
        return true;
    }

    $deadlines = \Weline\Server\IPC\ControlMessage::drainDeadlines((float)$maxDrainTime);
    $elapsed = $drainStartTime > 0.0
        ? \max(0.0, (\hrtime(true) / 1_000_000_000) - $drainStartTime)
        : 0.0;

    return $elapsed < $deadlines['soft'];
}

/**
 * Convert the existing SseWriter output into native per-stream H2 frames.
 * The first callback carries the HTTP response head; later callbacks become
 * incremental DATA. Flow-controlled bytes remain in ConnectionAdapter rather
 * than being misreported as a failed application write.
 *
 * @param array<int,resource> $connections
 * @param array<int,\Weline\Server\Protocol\Http2\ConnectionAdapter> $http2ConnectionAdapters
 * @param array<int,string> $writeBuffers
 * @param array<int,resource> $writableConnections
 */
function enqueueHttp2SseWrite(
    int $connId,
    mixed $conn,
    int $streamId,
    \Weline\Server\Protocol\Http2\ConnectionAdapter $adapter,
    string $data,
    array &$connections,
    array &$http2ConnectionAdapters,
    array &$connectionLastActivity,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose,
): bool {
    if ($data === '') {
        return true;
    }
    if (isset($pendingClose[$connId])
        || !isset($connections[$connId])
        || $connections[$connId] !== $conn
        || ($http2ConnectionAdapters[$connId] ?? null) !== $adapter
        || !\is_resource($conn)
        || !\in_array(\get_resource_type($conn), ['stream', 'Socket'], true)
        || !$adapter->isStreamActive($streamId)
    ) {
        return false;
    }

    $bufferedBytes = \strlen((string)($writeBuffers[$connId] ?? ''))
        + $adapter->pendingResponseBytes();
    $appendBytes = \strlen($data) + 64;
    if (\Weline\Server\Service\WorkerResponseMemoryGuard::sseWriteBufferWouldExceed(
        $bufferedBytes,
        $appendBytes,
    )) {
        WlsLogger::warning_(
            'HTTP/2 SSE 写缓冲超限，取消 stream (connId: ' . $connId
            . ', streamId=' . $streamId . ', buffered=' . $bufferedBytes
            . ', append=' . \strlen($data) . ')'
        );
        $reset = $adapter->abortStream($streamId, \Weline\Server\Protocol\Http2\FrameCodec::ERROR_CANCEL);
        if ($reset !== '') {
            $writeBuffers[$connId] = ($writeBuffers[$connId] ?? '') . $reset;
            $writableConnections[$connId] = $conn;
        }
        return false;
    }

    if (!$adapter->isStreamingResponse($streamId)) {
        $frames = $adapter->beginStreamingResponse($streamId, $data);
        if (!$adapter->isStreamingResponse($streamId)) {
            return false;
        }
    } else {
        $frames = $adapter->appendStreamingData($streamId, $data);
    }

    if ($frames !== '') {
        $writeBuffers[$connId] = ($writeBuffers[$connId] ?? '') . $frames;
        $writableConnections[$connId] = $conn;
    }
    $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();

    return $adapter->isStreamActive($streamId);
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
    $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
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
    float &$drainStartTime,
    int &$maxDrainTime
): void {
    $reason = \Weline\Server\Service\WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
    if ($reason === null) {
        return;
    }

    WlsLogger::warning_("Worker requested drain after response: {$reason}");
    $shouldExit = true;
    $ipcDraining = true;
    $drainStartTime = \hrtime(true) / 1_000_000_000;
    $maxDrainTime = \min($maxDrainTime, 10);
    if ($socket && \is_resource($socket)) {
        @\fclose($socket);
        $socket = null;
        \Weline\Server\Service\Runtime\WorkerReadinessState::markListenerClosed();
    }
}

function wlsDrainPostResponseTasks(
    int $activeRequests = 0,
    array $requestBuffers = [],
    array $writeBuffers = [],
    ?int $currentConnId = null
): void
{
    if (!\class_exists(\Weline\Framework\Runtime\PostResponseTaskQueue::class)) {
        return;
    }

    $deferWhenBusy = (bool)(\Weline\Framework\App\Env::get('wls.post_response_task_defer_when_busy', true) ?? true);
    if ($deferWhenBusy && wlsWorkerHasPendingRequestWork($activeRequests, $requestBuffers, $writeBuffers, $currentConnId)) {
        return;
    }

    $maxTasks = (int)(\Weline\Framework\App\Env::get('wls.post_response_task_max_per_drain', 1) ?: 1);
    \Weline\Framework\Runtime\PostResponseTaskQueue::drain(
        (float)(\Weline\Framework\App\Env::get('wls.post_response_task_budget_ms', 8) ?: 8),
        \max(1, $maxTasks)
    );
}

function wlsWorkerHasPendingRequestWork(
    int $activeRequests,
    array $requestBuffers,
    array $writeBuffers,
    ?int $currentConnId,
    array $http2PendingRequests = []
): bool {
    if ($activeRequests > 0) {
        return true;
    }

    foreach ($requestBuffers as $connId => $buffer) {
        if ($currentConnId !== null && (int)$connId === $currentConnId) {
            continue;
        }
        if (\is_string($buffer) && $buffer !== '') {
            return true;
        }
    }

    foreach ($writeBuffers as $connId => $buffer) {
        if ($currentConnId !== null && (int)$connId === $currentConnId) {
            continue;
        }
        if (\is_string($buffer) && $buffer !== '') {
            return true;
        }
    }

    foreach ($http2PendingRequests as $connId => $queuedRequests) {
        if ($currentConnId !== null && (int)$connId === $currentConnId) {
            continue;
        }
        if (\is_array($queuedRequests) && $queuedRequests !== []) {
            return true;
        }
    }

    return false;
}

function wlsHttp3SubmitResponse(
    ?\Weline\Server\Protocol\Http3\WorkerQuicRuntime $runtime,
    int $token,
    string $rawRequest,
    string $response,
    float $startedAt,
    int &$activeRequests,
): void {
    try {
        if (!$runtime instanceof \Weline\Server\Protocol\Http3\WorkerQuicRuntime || $token <= 0) {
            return;
        }
        if ($response === '') {
            $body = 'Internal Server Error';
            $response = "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain; charset=utf-8\r\n"
                . 'Content-Length: ' . \strlen($body) . "\r\n\r\n" . $body;
        }
        $response = wlsDecorateFormattedBenchmarkWorkerIdentity($response, $rawRequest);
        $response = injectWlsProcessTimeHeader(
            $response,
            (wlsWorkerMonotonicNow() - $startedAt) * 1000,
        );
        $requestHost = \trim((string)(getHeaderValue($rawRequest, 'Host') ?? ''));
        if ($requestHost !== '') {
            $parsedHost = \parse_url('https://' . $requestHost, \PHP_URL_HOST);
            if (\is_string($parsedHost) && $parsedHost !== '') {
                $requestHost = $parsedHost;
            }
        }
        $response = \Weline\Server\Protocol\Http3\AltSvcResponsePolicy::decorate($response, $requestHost);
        $runtime->respond($token, $response);
    } catch (\Throwable $exception) {
        try {
            $runtime?->closeRequest($token);
        } catch (\Throwable) {
        }
        WlsLogger::warning_('[HTTP3] response submit failed token=' . $token . ': ' . $exception->getMessage());
    } finally {
        $activeRequests = \max(0, $activeRequests - 1);
        \Weline\Framework\Http\Sse\SseContext::reset();
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
    ?\Weline\Server\Protocol\Http2\ConnectionAdapter $http2Adapter = null,
    int $http2StreamId = 0,
): void {
    $response = wlsDecorateFormattedBenchmarkWorkerIdentity($response, $rawRequest);

    $responseStatus = 200;
    if (!$trustedCacheHit && \preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $response, $statusMatches)) {
        $responseStatus = (int) $statusMatches[1];
    }

    $webSocketRegistration = $longLivedConnections[$connId] ?? null;
    $isWebSocketRequest = \is_array($webSocketRegistration)
        && \in_array(\strtolower((string)($webSocketRegistration['type'] ?? '')), ['websocket', 'ws'], true);
    $isWebSocketMode = false;
    if ($responseStatus === 101) {
        if ($http2Adapter === null
            && $http2StreamId === 0
            && $isWebSocketRequest
            && \Weline\Server\IPC\ControlMessage::webSocketUpgradeAccepted($rawRequest, $response)
        ) {
            $longLivedConnections[$connId]['upgraded'] = true;
            $longLivedConnections[$connId]['websocket_state'] =
                \Weline\Server\IPC\ControlMessage::webSocketInitialState();
            $longLivedConnections[$connId]['resume_pending'] = false;
            $longLivedConnections[$connId]['read_pump_pending'] = false;
            $isWebSocketMode = true;
        } else {
            $body = 'Invalid WebSocket Upgrade';
            $response = "HTTP/1.1 502 Bad Gateway\r\n"
                . "Content-Type: text/plain; charset=utf-8\r\n"
                . 'Content-Length: ' . \strlen($body) . "\r\n"
                . "Connection: close\r\n\r\n"
                . $body;
            $responseStatus = 502;
            unset($longLivedConnections[$connId]);
            WlsLogger::warning_("WebSocket 101 握手校验失败，已拒绝升级 (connId: {$connId})");
        }
    } elseif ($isWebSocketRequest) {
        unset($longLivedConnections[$connId]);
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
    $response = \Weline\Server\Protocol\Http3\AltSvcResponsePolicy::decorate($response, $requestHost);

    $activeRequests = \max(0, $activeRequests - 1);

    $responseLenPre = \strlen($response);
    if ($recordObservability) {
        WlsLogger::debug_("Worker 即将写回响应 connId={$connId} len={$responseLenPre}");
    }

    $isHttp2Stream = $http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
        && $http2StreamId > 0;
    $http2StreamingStarted = $isHttp2Stream
        && $http2Adapter->isStreamingResponse($http2StreamId);
    $hasQueuedSsePayload = !$isHttp2Stream
        && isset($writeBuffers[$connId])
        && $writeBuffers[$connId] !== '';
    $actualSseStarted = $isSseProtocolRequest
        && ($isHttp2Stream
            ? $http2StreamingStarted
            : (\Weline\Framework\Http\Sse\SseContext::isSseEnabled()
                || \Weline\Framework\Http\Sse\SseContext::isHeadersSent()));
    if ($isSseProtocolRequest && !$actualSseStarted && $response !== '') {
        $statusLine = \trim((string) (\strtok($response, "\r\n") ?: ''));
        WlsLogger::warning_(
            'SSE 路径未实际启动流式响应，普通响应将按 HTTP 回写 (connId: '
            . $connId . ', status: ' . $statusLine . ', len: ' . \strlen($response) . ')'
        );
    }
    // SSE 收尾兜底：上下文标记可能先于写队列排空被重置，此时仍必须按 SSE 分支处理。
    $isSseMode = $actualSseStarted || ($isSseProtocolRequest && $hasQueuedSsePayload);
    $isHttp2SseMode = $isSseMode && $http2StreamingStarted;
    $isHttp1SseMode = $isSseMode && !$isHttp2SseMode;
    $runtimeDrainPending = \Weline\Server\Service\WorkerResponseMemoryGuard::hasDrainAfterResponseRequest();
    $drainRequestedBeforeResponse = $ipcDraining || $runtimeDrainPending;
    $isHttp2Response = false;
    if ($isHttp2SseMode && $http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter) {
        $response = $http2Adapter->endStreamingResponse($http2StreamId);
        if ($drainRequestedBeforeResponse) {
            $response .= $http2Adapter->initiateGoaway();
        }
        $responseLenPre = \strlen($response);
        $trustedCacheHit = true;
        $precomputedKeepAlive = true;
        $isHttp2Response = true;
    } elseif (!$isSseMode && $isHttp2Stream) {
        $response = $http2Adapter->encodeResponse($http2StreamId, $response);
        if ($response === '') {
            \Weline\Framework\Http\Sse\SseContext::reset();
            $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
            return;
        }
        if ($drainRequestedBeforeResponse) {
            $response .= $http2Adapter->initiateGoaway();
        }
        $responseLenPre = \strlen($response);
        $trustedCacheHit = true;
        $precomputedKeepAlive = true;
        $isHttp2Response = true;
    }
    $keepAlive = $isWebSocketMode ? true : ($precomputedKeepAlive ?? isKeepAlive($rawRequest));
    if ($drainRequestedBeforeResponse && !$isSseMode && !$isHttp2Response && !$isWebSocketMode) {
        // H1 advertises retirement before any immediate or queued write. Keep
        // the transport readable until the client acknowledges with FIN.
        $response = \Weline\Server\Service\WorkerResponseMemoryGuard::forceConnectionCloseHeader($response);
        $keepAlive = false;
        $responseLenPre = \strlen($response);
    }
    $bufferedBytesBeforeWrite = isset($writeBuffers[$connId]) ? \strlen($writeBuffers[$connId]) : 0;
    $forceCloseAfterResponse = !$isHttp2Response
        && \Weline\Server\Service\WorkerResponseMemoryGuard::shouldForceConnectionClose(
            $keepAlive,
            $isSseMode || $isWebSocketMode,
            $responseLenPre,
            $bufferedBytesBeforeWrite
        );
    if ($forceCloseAfterResponse && !$isSseMode && !$isWebSocketMode) {
        $response = \Weline\Server\Service\WorkerResponseMemoryGuard::forceConnectionCloseHeader($response);
    }

    $responseFullyWritten = false;
    if (!$isHttp1SseMode) {
        $responseLen = \strlen($response);
        $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';

        if ($hasBufferedData) {
            $isHttp2BufferedConnection = $isHttp2Response;
            if ($isHttp2BufferedConnection) {
                // HTTP/2 缓冲区内通常是连接级 SETTINGS/ACK，响应帧必须追加在其后。
                $writeBuffers[$connId] .= $response;
                $writableConnections[$connId] = $conn;
                if ($recordObservability) {
                    WlsLogger::debug_("Worker HTTP/2 响应追加到控制帧缓冲 connId={$connId} len={$responseLen}");
                }
                goto ssl_finalize_skip_write;
            }
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
    $connectionLastActivity[$connId] = wlsWorkerMonotonicNow();
    $handleDurationMs = (float) \round((wlsWorkerMonotonicNow() - $handleStartTime) * 1000, 2);

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

    if ($recordObservability && !$isSseMode && !$isWebSocketMode && $responseFullyWritten) {
        wlsDrainPostResponseTasks($activeRequests, $requestBuffers, $writeBuffers, $connId);
    }

    $responseRequestsClose = !$trustedCacheHit
        && \Weline\Server\Service\WorkerResponseMemoryGuard::responseRequestsConnectionClose($response);
    $http2DrainRequested = $isHttp2Response
        && $http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
        && ($drainRequestedBeforeResponse || $http2Adapter->peerGoawayReceived());
    $awaitPeerCloseAfterDrain = \Weline\Server\Service\WorkerResponseMemoryGuard::shouldAwaitPeerCloseAfterDrainResponse(
        $drainRequestedBeforeResponse,
        $isSseMode,
        $isHttp2Response,
    );
    $shouldClose = !$isWebSocketMode
        && !$awaitPeerCloseAfterDrain
        && (
            ($isSseMode && !$isHttp2Response)
            || !$keepAlive
            || (!$isHttp2Response && $drainRequestedBeforeResponse)
            || $forceCloseAfterResponse
            || $responseRequestsClose
            || $http2DrainRequested
        );
    if ($isHttp2Response
        && $http2Adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
        && ($http2DrainRequested
            || $http2Adapter->hasActiveStreams()
            || $http2Adapter->hasPendingResponseData())
    ) {
        // A completed application Fiber can still have megabytes waiting behind
        // the peer's HTTP/2 flow-control window. Keep the connection readable so
        // WINDOW_UPDATE can release every queued DATA frame before close.
        $shouldClose = false;
    }
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

/**
 * @param array<int,array{type?:string,quota_exempt?:bool}> $connections
 * @return array{long_lived_connections:int,sse_connections:int,websocket_connections:int,quota_connections:int}
 */
function wlsDrainConnectionCounters(array $connections): array
{
    $counters = [
        'long_lived_connections' => 0,
        'sse_connections' => 0,
        'websocket_connections' => 0,
        'quota_connections' => 0,
    ];
    foreach ($connections as $connection) {
        if (!\is_array($connection)) {
            continue;
        }
        ++$counters['long_lived_connections'];
        $protocol = \strtolower((string)($connection['type'] ?? ''));
        if ($protocol === 'sse') {
            ++$counters['sse_connections'];
        } elseif (\in_array($protocol, ['websocket', 'ws'], true)) {
            ++$counters['websocket_connections'];
        }
        if (($connection['quota_exempt'] ?? false) !== true) {
            ++$counters['quota_connections'];
        }
    }
    return $counters;
}

/**
 * @param array<int|string,mixed> $adapters
 * @param array<int|string,mixed> $connections
 */
function wlsHttp2LiveConnectionCount(array $adapters, array $connections): int
{
    $count = 0;
    foreach ($adapters as $connectionId => $adapter) {
        $connection = $connections[(int)$connectionId] ?? null;
        if ($adapter instanceof \Weline\Server\Protocol\Http2\ConnectionAdapter
            && \is_resource($connection)
        ) {
            ++$count;
        }
    }
    return $count;
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
    float $startTime,
    string $originToken,
    bool $originTokenValidationEnabled,
    string $originTokenHeader,
    bool $originTokenAllowLocal,
    string $transportPeer = '',
    ?\Weline\Server\Security\WorkerPolicyDecision $precomputedPolicyDecision = null,
    array $longLivedConnections = [],
    array $http2ConnectionAdapters = [],
    array $liveConnections = [],
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
        $healthOptions = [];
        $healthQuery = \parse_url($policyDecision->target, PHP_URL_QUERY);
        if (\is_string($healthQuery) && $healthQuery !== '') {
            \parse_str($healthQuery, $healthOptions);
        }
        $healthOptionEnabled = static function (string $name) use ($healthOptions): bool {
            $value = $healthOptions[$name] ?? null;
            return \is_scalar($value)
                && \in_array(\strtolower(\trim((string)$value)), ['1', 'true'], true);
        };
        $wantsDetail = $healthOptionEnabled('detail');
        $wantsMemory = $healthOptionEnabled('memory');
        $wantsStaticMemory = $healthOptionEnabled('static');
        $wantsObjectMemory = $healthOptionEnabled('objects');
        
        if ($wantsDetail) {
            // 详细模式：返回完整信息
            $drainCounters = wlsDrainConnectionCounters($longLivedConnections);
            $http2ConnectionCount = wlsHttp2LiveConnectionCount(
                $http2ConnectionAdapters,
                $liveConnections,
            );
            $fiberSnapshot = \Weline\Server\Runtime\WorkerFiberSnapshot::getSnapshot();
            $health = [
                'status' => 'healthy',
                'instance' => $instanceName,
                'master_epoch' => (int)WLS_WORKER_MASTER_EPOCH,
                'launch_id' => (string)WLS_WORKER_LAUNCH_ID,
                'worker_id' => $workerId,
                'port' => $port,
                'connections' => $connectionCount,
                'active_requests' => $activeRequests - 1,
                'long_lived_connections' => \max(
                    0,
                    (int)($drainCounters['long_lived_connections'] ?? 0),
                ),
                'sse_connections' => \max(
                    0,
                    (int)($drainCounters['sse_connections'] ?? 0),
                ),
                'websocket_connections' => \max(
                    0,
                    (int)($drainCounters['websocket_connections'] ?? 0),
                ),
                'http2_connections' => \max(0, $http2ConnectionCount),
                'drain_counters_version' => 1,
                'total_requests' => $requestCount,
                'memory_usage' => \memory_get_usage(true),
                'memory_usage_used' => \memory_get_usage(false),
                'memory_peak' => \memory_get_peak_usage(true),
                'memory_peak_used' => \memory_get_peak_usage(false),
                'uptime' => \max(0, (int)\floor(wlsWorkerMonotonicNow() - $startTime)),
                'php_version' => PHP_VERSION,
                'ssl' => true,
                'tls_session_reuse' => \Weline\Server\Service\WlsWorkerGlobals::getTlsSessionReuse(),
                'tls_session_cache' => ($GLOBALS['wlsTlsSessionCacheRuntime'] ?? null)
                    instanceof \Weline\Server\Service\Runtime\TlsSessionCacheRuntime
                    ? $GLOBALS['wlsTlsSessionCacheRuntime']->counters()
                    : [],
                'timestamp' => \time(),
                'fiber_count' => \count($fiberSnapshot),
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

    // ========== 静态文件处理（WLS 模式特有） ==========
    $staticFileStart = wlsWorkerMonotonicNow();
    $staticResponse = $policyDecision->staticProcessCacheEnabled()
        ? handleStaticFile($uri, $rawRequest)
        : null;
    if ($staticResponse !== null) {
        $cacheInfo = \Weline\Server\Service\WlsWorkerGlobals::getLastStaticCache();
        $cacheStatus = $cacheInfo['status'] ?? 'miss';
        $cacheUri = $cacheInfo['uri'] ?? $uri;
        if (WLS_WORKER_HOT_PATH_LOGS_ENABLED) {
            WlsLogger::info_(__('静态文件缓存: %{1} %{2}', [\strtoupper($cacheStatus), $cacheUri]));
        }
        if (\function_exists('wlsDecorateFormattedStaticResponseForPerformancePanel')) {
            $staticResponse = wlsDecorateFormattedStaticResponseForPerformancePanel(
                $staticResponse,
                $rawRequest,
                (wlsWorkerMonotonicNow() - $staticFileStart) * 1000,
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
    
    if (WLS_WORKER_HOT_PATH_LOGS_ENABLED) {
        WlsLogger::info_("准备进入框架处理: {$method} {$uri}");
    }
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
            $response->setHeader('Location', wlsAppendBackendLoginReturnUrl(
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
    $keepAlive = isKeepAlive($rawRequest);
    $connectionHeader = $keepAlive ? 'keep-alive' : 'close';

    // ========== 静态文件内存缓存（冷热淘汰策略） ==========
    // 缓存格式：[filepath => ['content' => string, 'mtime' => int, 'size' => int, 'cached_at' => float, 'hits' => int, 'last_access' => float]]
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
        
        $now = wlsWorkerMonotonicNow();
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
    $now = wlsWorkerMonotonicNow();
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
    $now = $now ?? wlsWorkerMonotonicNow();
    
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
            $staticFileCacheMaxAge,
        );
    }
    
    return $response;
}
