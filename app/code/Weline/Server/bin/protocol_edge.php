<?php

declare(strict_types=1);

/**
 * WLS public protocol edge supervisor.
 *
 * Caddy owns public TLS/QUIC, ALPN, session resumption and multiplexed client
 * connections. This wrapper keeps it inside the WLS IPC/lease lifecycle.
 */

if (PHP_SAPI !== 'cli') {
    return;
}

$instanceName = \trim((string)($argv[1] ?? 'default')) ?: 'default';
$publicPort = (int)($argv[2] ?? 0);
$options = [
    'caddy-binary' => '',
    'config' => '',
    'pid-file' => '',
    'token-file' => '',
    'public-host' => 'localhost',
    'admin-address' => '',
    'control-port' => '0',
    'master-pid' => '0',
    'epoch' => '0',
    'launch-id' => '',
    'master-lease-file' => '',
    'master-token' => '',
    'name' => '',
];
$upstreams = [];
$windowMode = false;
foreach ($argv as $argument) {
    if ($argument === '--win' || $argument === '-win') {
        $windowMode = true;
        continue;
    }
    if (!\str_starts_with($argument, '--') || !\str_contains($argument, '=')) {
        continue;
    }
    [$name, $value] = \explode('=', \substr($argument, 2), 2);
    if ($name === 'upstream') {
        $value = \trim($value);
        if ($value !== '') {
            $upstreams[] = $value;
        }
        continue;
    }
    if (\array_key_exists($name, $options)) {
        $options[$name] = $value;
    }
}

$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}
require_once BP . 'app' . DS . 'autoload.php';

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Runtime\System;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\IPC\ChildControl\ChildProcessIdentity;
use Weline\Server\IPC\ChildControl\Handler\ProtocolEdgeControlHandler;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\LogConfig;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\LongRunningPhpRuntime;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;
use Weline\Server\Service\WlsLogService;

LogConfig::bootstrapVerboseFromInstanceFile($instanceName);
(new LongRunningPhpRuntime())->apply();

$processName = \trim((string)$options['name']);
$caddyBinary = \trim((string)$options['caddy-binary']);
$configFile = \trim((string)$options['config']);
$pidFile = \trim((string)$options['pid-file']);
$tokenFile = \trim((string)$options['token-file']);
$publicHost = \trim((string)$options['public-host']) ?: 'localhost';
$adminAddress = \trim((string)$options['admin-address']);
$controlPort = (int)$options['control-port'];
$masterPid = (int)$options['master-pid'];
$epoch = (int)$options['epoch'];
$launchId = (string)$options['launch-id'];
$masterLeaseFile = (string)$options['master-lease-file'];
$masterToken = (string)$options['master-token'];

ErrorBootstrap::init('ProtocolEdge:' . $publicPort . '@' . $instanceName, [
    'instance' => $instanceName,
    'port' => $publicPort,
    'process_name' => $processName,
]);
WlsLogger::getInstance()
    ->setStdoutEnabled(LogConfig::isStdoutEnabled($windowMode, LogConfig::isDevMode()))
    ->setProcessTag('ProtocolEdge:' . $publicPort . '@' . $instanceName);

if ($processName !== '') {
    WlsLogService::prepareProcessLogFile($processName, $instanceName, 'ProtocolEdge:' . $publicPort);
    Processer::setPid('--name=' . $processName, (int)\getmypid());
    if ($publicPort > 0) {
        Processer::setProcessPorts('--name=' . $processName, [$publicPort]);
    }
}

$fail = static function (string $message, int $code = 1): never {
    WlsLogger::error_('[ProtocolEdge] ' . $message);
    WlsLogger::flush_(true);
    System::exit($code);
};

if ($publicPort <= 0 || $publicPort > 65535) {
    $fail('Invalid public port.');
}
if ($caddyBinary === '' || !\is_file($caddyBinary) || !\is_executable($caddyBinary)) {
    $fail('Verified Caddy binary is missing or not executable.');
}
if ($configFile === '' || !\is_file($configFile) || $pidFile === '' || $tokenFile === '') {
    $fail('Protocol-edge runtime files are incomplete.');
}
$edgeToken = \strtolower(\trim((string)@\file_get_contents($tokenFile)));
if (\preg_match('/^[a-f0-9]{64}$/D', $edgeToken) !== 1) {
    $fail('Protocol-edge token is invalid.');
}
if ($upstreams === []) {
    $fail('No Worker/Dispatcher upstream was supplied.');
}
ProtocolEdgeRuntime::clearActiveState($instanceName);

$childMasterGuard = new ChildMasterGuard(
    $masterPid,
    $masterLeaseFile,
    $masterToken,
    'ProtocolEdge:' . $publicPort,
    $instanceName,
    $epoch,
);
$childMasterGuard->assertAliveOrExit('协议边缘启动前 Master 自治检查');
if ($controlPort <= 0) {
    $controlPort = SubprocessControlKernel::resolveControlPort($instanceName, 0, 30);
}

$shutdownRequested = false;
$certificateReloadRequested = false;
$handler = new ProtocolEdgeControlHandler(
    static function () use (&$shutdownRequested): void {
        $shutdownRequested = true;
    },
    static function () use (&$certificateReloadRequested): void {
        $certificateReloadRequested = true;
    },
);
$kernel = new SubprocessControlKernel(
    new ChildProcessIdentity(
        ProtocolEdgeRuntime::ROLE,
        (int)\getmypid(),
        $publicPort,
        0,
        $epoch,
        $launchId,
    ),
    $handler,
    'ProtocolEdge',
    LogConfig::isDevMode(),
    $instanceName,
);
if (!$kernel->connectAndRegister($controlPort, false)) {
    $fail('Unable to register with Master control plane.');
}

/** @return array{success:bool,exit_code:int,output:string} */
$runCommand = static function (array $command, float $timeoutSec) use (&$kernel): array {
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = @\proc_open($command, [
        0 => ['file', $null, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, BP, null, ['bypass_shell' => true]);
    if (!\is_resource($process)) {
        return ['success' => false, 'exit_code' => 126, 'output' => 'Unable to launch process.'];
    }
    foreach ([1, 2] as $index) {
        if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
            \stream_set_blocking($pipes[$index], false);
        }
    }
    $deadline = \microtime(true) + \max(0.1, $timeoutSec);
    $output = '';
    $status = ['running' => true, 'exitcode' => -1];
    while (($status['running'] ?? false) && \microtime(true) < $deadline) {
        $kernel->tick();
        $kernel->flushWrites();
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                $chunk = (string)(@\stream_get_contents($pipes[$index]) ?: '');
                if ($chunk !== '' && \strlen($output) < 262144) {
                    $output .= \substr($chunk, 0, 262144 - \strlen($output));
                }
            }
        }
        $status = \proc_get_status($process);
        if ($status['running'] ?? false) {
            SchedulerSystem::usleep(20000);
        }
    }
    if ($status['running'] ?? false) {
        @\proc_terminate($process);
        $exitCode = 124;
    } else {
        $exitCode = (int)($status['exitcode'] ?? -1);
    }
    foreach ([1, 2] as $index) {
        if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
            $output .= (string)(@\stream_get_contents($pipes[$index]) ?: '');
            @\fclose($pipes[$index]);
        }
    }
    $closeCode = @\proc_close($process);
    if ($exitCode < 0) {
        $exitCode = (int)$closeCode;
    }
    return ['success' => $exitCode === 0, 'exit_code' => $exitCode, 'output' => \trim($output)];
};

$validation = $runCommand([$caddyBinary, 'validate', '--config', $configFile, '--adapter', 'caddyfile'], 10.0);
if (!$validation['success']) {
    $fail('Caddy config validation failed: ' . $validation['output']);
}

// A stale Caddy child may survive an ungraceful wrapper crash. Terminate only
// when both its executable and this exact private config path match.
if (\is_file($pidFile)) {
    $stalePid = (int)\trim((string)@\file_get_contents($pidFile));
    if ($stalePid > 0 && Processer::isRunningByPid($stalePid)) {
        $commandLine = Processer::getProcessCommandLine($stalePid, true);
        if ($commandLine !== ''
            && \str_contains($commandLine, $caddyBinary)
            && \str_contains($commandLine, $configFile)
        ) {
            Processer::killProcessTreeByPid($stalePid, true);
        } else {
            $fail('Stale pid file points to an unverified process; refusing destructive cleanup.');
        }
    }
    @\unlink($pidFile);
}

$normalizeHost = static function (string $value): string {
    if (\str_contains($value, '://')) {
        $parsed = \parse_url($value, PHP_URL_HOST);
        return \is_string($parsed) && $parsed !== '' ? $parsed : 'localhost';
    }
    if ($value !== '' && $value[0] === '[') {
        $end = \strpos($value, ']');
        return $end === false ? \trim($value, '[]') : \substr($value, 1, $end - 1);
    }
    return \substr_count($value, ':') === 1 ? \explode(':', $value, 2)[0] : $value;
};
$publicHost = $normalizeHost($publicHost);
$healthHost = $publicHost . ($publicPort === 443 ? '' : ':' . $publicPort);

$probePlainHttp = static function (string $upstream) use ($healthHost, $edgeToken): bool {
    $socket = @\stream_socket_client('tcp://' . $upstream, $errno, $errstr, 0.25, STREAM_CLIENT_CONNECT);
    if (!\is_resource($socket)) {
        return false;
    }
    \stream_set_timeout($socket, 0, 500000);
    @\fwrite($socket, "GET /_wls/health HTTP/1.1\r\nHost: {$healthHost}\r\n"
        . ProtocolEdgeRuntime::AUTH_HEADER . ": {$edgeToken}\r\n"
        . ProtocolEdgeRuntime::CLIENT_PROTOCOL_HEADER . ": HTTP/1.1\r\nConnection: close\r\n\r\n");
    $status = (string)(@\fgets($socket, 256) ?: '');
    @\fclose($socket);
    return \preg_match('#^HTTP/1\.[01] 2\d\d\b#', $status) === 1;
};

$upstreamDeadline = \microtime(true) + 45.0;
$upstreamReady = false;
while (!$shutdownRequested && \microtime(true) < $upstreamDeadline) {
    $kernel->tick();
    $kernel->flushWrites();
    if ($childMasterGuard->shouldExit()) {
        $shutdownRequested = true;
        break;
    }
    foreach ($upstreams as $upstream) {
        if ($probePlainHttp($upstream)) {
            $upstreamReady = true;
            break 2;
        }
    }
    SchedulerSystem::usleep(50000);
}
if (!$upstreamReady || $shutdownRequested) {
    $fail($shutdownRequested ? 'Startup cancelled by Master.' : 'No policy-protected upstream became healthy before deadline.');
}

$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$caddyProcess = @\proc_open([
    $caddyBinary,
    'run',
    '--config',
    $configFile,
    '--adapter',
    'caddyfile',
    '--pidfile',
    $pidFile,
], [
    0 => ['file', $null, 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $caddyPipes, BP, null, ['bypass_shell' => true]);
if (!\is_resource($caddyProcess)) {
    $fail('Unable to launch Caddy data plane.');
}
foreach ([1, 2] as $index) {
    if (isset($caddyPipes[$index]) && \is_resource($caddyPipes[$index])) {
        \stream_set_blocking($caddyPipes[$index], false);
    }
}
$caddyStatus = \proc_get_status($caddyProcess);
$caddyPid = (int)($caddyStatus['pid'] ?? 0);
$terminated = false;
$terminateCaddy = static function (bool $force = false) use (&$caddyProcess, &$caddyPipes, &$terminated, $caddyPid, $pidFile): void {
    if ($terminated) {
        return;
    }
    $terminated = true;
    if (\is_resource($caddyProcess)) {
        @\proc_terminate($caddyProcess);
        $deadline = \microtime(true) + ($force ? 0.2 : 5.0);
        do {
            $status = \proc_get_status($caddyProcess);
            if (!($status['running'] ?? false)) {
                break;
            }
            SchedulerSystem::usleep(20000);
        } while (\microtime(true) < $deadline);
        $status = \proc_get_status($caddyProcess);
        if (($status['running'] ?? false) && $caddyPid > 0) {
            Processer::killProcessTreeByPid($caddyPid, true);
        }
    }
    foreach ([1, 2] as $index) {
        if (isset($caddyPipes[$index]) && \is_resource($caddyPipes[$index])) {
            @\fclose($caddyPipes[$index]);
        }
    }
    if (\is_resource($caddyProcess)) {
        @\proc_close($caddyProcess);
    }
    @\unlink($pidFile);
};
\register_shutdown_function(static function () use ($terminateCaddy, $instanceName): void {
    $terminateCaddy(true);
    ProtocolEdgeRuntime::clearActiveState($instanceName, (int)\getmypid());
});
if (\function_exists('pcntl_signal')) {
    if (\defined('SIGPIPE')) {
        \pcntl_signal(SIGPIPE, SIG_IGN);
    }
    \pcntl_signal(SIGINT, static function () use (&$shutdownRequested): void {
        $shutdownRequested = true;
    });
    \pcntl_signal(SIGTERM, static function () use (&$shutdownRequested): void {
        $shutdownRequested = true;
    });
}

$lastPublicProbeError = 'not_attempted';
$probePublic = static function () use (
    $publicHost,
    $publicPort,
    $healthHost,
    &$lastPublicProbeError,
): bool {
    $context = \stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'peer_name' => $publicHost,
            'SNI_enabled' => true,
            'alpn_protocols' => 'http/1.1',
        ],
    ]);
    $socket = @\stream_socket_client(
        'tls://127.0.0.1:' . $publicPort,
        $errno,
        $errstr,
        0.5,
        STREAM_CLIENT_CONNECT,
        $context,
    );
    if (!\is_resource($socket)) {
        $lastPublicProbeError = 'tls_connect_failed errno=' . $errno . ' error=' . $errstr;
        return false;
    }
    \stream_set_timeout($socket, 1);
    @\fwrite($socket, "GET /_wls/health HTTP/1.1\r\nHost: {$healthHost}\r\nConnection: close\r\n\r\n");
    $status = (string)(@\fgets($socket, 256) ?: '');
    $metadata = @\stream_get_meta_data($socket);
    @\fclose($socket);
    $healthy = \preg_match('#^HTTP/1\.[01] 2\d\d\b#', $status) === 1;
    $lastPublicProbeError = $healthy
        ? ''
        : 'status=' . (\trim($status) !== '' ? \trim($status) : '(empty)')
            . ', timed_out=' . (!empty($metadata['timed_out']) ? 'true' : 'false')
            . ', eof=' . (!empty($metadata['eof']) ? 'true' : 'false');

    return $healthy;
};

/** @return list<string> */
$readConfiguredUpstreams = static function () use ($configFile): array {
    $config = (string)@\file_get_contents($configFile);
    if ($config === ''
        || \preg_match('/^\s*reverse_proxy\s+(.+?)\s+\{\s*$/m', $config, $matches) !== 1
    ) {
        return [];
    }
    $values = \preg_split('/\s+/', \trim((string)$matches[1]), -1, \PREG_SPLIT_NO_EMPTY);

    return \is_array($values) ? \array_values($values) : [];
};

$publicDeadline = \microtime(true) + 15.0;
$publicReady = false;
while (!$shutdownRequested && \microtime(true) < $publicDeadline) {
    $kernel->tick();
    $kernel->flushWrites();
    foreach ([1, 2] as $index) {
        if (isset($caddyPipes[$index]) && \is_resource($caddyPipes[$index])) {
            $chunk = (string)(@\stream_get_contents($caddyPipes[$index]) ?: '');
            if ($chunk !== '') {
                WlsLogger::debug_('[ProtocolEdge/Caddy] ' . \trim($chunk));
            }
        }
    }
    $caddyStatus = \proc_get_status($caddyProcess);
    if (!($caddyStatus['running'] ?? false)) {
        break;
    }
    if ($probePublic()) {
        $publicReady = true;
        break;
    }
    SchedulerSystem::usleep(50000);
}
if (!$publicReady) {
    $terminateCaddy();
    $fail('Public TLS protocol edge failed readiness probe: ' . $lastPublicProbeError);
}
$activeConfigDigest = ProtocolEdgeRuntime::configDigest($instanceName);
if ($activeConfigDigest === '') {
    $terminateCaddy();
    $fail('Unable to fingerprint active Caddy configuration.');
}
ProtocolEdgeRuntime::markConfigActive(
    $instanceName,
    $activeConfigDigest,
    $readConfiguredUpstreams() ?: $upstreams,
);
if (!$kernel->sendReady()) {
    $terminateCaddy();
    $fail('Unable to publish protocol-edge READY.');
}
$failedConfigDigest = '';
$nextConfigCheckAt = \microtime(true) + 0.25;

WlsLogger::info_(
    '[ProtocolEdge] READY public=' . $publicHost . ':' . $publicPort
    . ' protocols=h3,h2,h1 tls_session_resumption=enabled upstreams=' . \implode(',', $upstreams)
);

while (!$shutdownRequested) {
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }
    $kernel->tick();
    $kernel->flushWrites();
    if ($childMasterGuard->shouldExit()) {
        $shutdownRequested = true;
        break;
    }
    if (!$kernel->isConnected()) {
        $kernel->reconnect();
    }
    foreach ([1, 2] as $index) {
        if (isset($caddyPipes[$index]) && \is_resource($caddyPipes[$index])) {
            $chunk = (string)(@\stream_get_contents($caddyPipes[$index]) ?: '');
            if ($chunk !== '') {
                WlsLogger::debug_('[ProtocolEdge/Caddy] ' . \trim($chunk));
            }
        }
    }
    $caddyStatus = \proc_get_status($caddyProcess);
    if (!($caddyStatus['running'] ?? false)) {
        $kernel->sendExitReason('caddy_data_plane_exited', (int)($caddyStatus['exitcode'] ?? 1));
        break;
    }
    $now = \microtime(true);
    $observedConfigDigest = $activeConfigDigest;
    if ($now >= $nextConfigCheckAt) {
        $nextConfigCheckAt = $now + 0.25;
        $observedConfigDigest = ProtocolEdgeRuntime::configDigest($instanceName);
    }
    $configChanged = $observedConfigDigest !== ''
        && !\hash_equals($activeConfigDigest, $observedConfigDigest);
    if ($certificateReloadRequested || ($configChanged && $observedConfigDigest !== $failedConfigDigest)) {
        $certificateOnlyReload = $certificateReloadRequested && !$configChanged;
        $certificateReloadRequested = false;
        if ($configChanged) {
            $validation = $runCommand([
                $caddyBinary,
                'validate',
                '--config',
                $configFile,
                '--adapter',
                'caddyfile',
            ], 10.0);
            if (!$validation['success']) {
                $failedConfigDigest = $observedConfigDigest;
                WlsLogger::error_('[ProtocolEdge] Candidate route configuration rejected: ' . $validation['output']);
                SchedulerSystem::usleep(20000);
                continue;
            }
        }
        $reload = $runCommand([
            $caddyBinary,
            'reload',
            '--config',
            $configFile,
            '--adapter',
            'caddyfile',
            '--address',
            $adminAddress,
            '--force',
        ], 10.0);
        if ($reload['success']) {
            $activeConfigDigest = $observedConfigDigest;
            $failedConfigDigest = '';
            ProtocolEdgeRuntime::markConfigActive(
                $instanceName,
                $activeConfigDigest,
                $readConfiguredUpstreams(),
            );
            WlsLogger::info_(
                $certificateOnlyReload
                    ? '[ProtocolEdge] TLS certificate configuration reloaded without dropping connections.'
                    : '[ProtocolEdge] Worker upstream configuration activated without dropping client connections.'
            );
        } else {
            if ($configChanged) {
                $failedConfigDigest = $observedConfigDigest;
            }
            WlsLogger::error_('[ProtocolEdge] Caddy configuration reload failed: ' . $reload['output']);
        }
    }
    SchedulerSystem::usleep(20000);
}

$terminateCaddy();
ProtocolEdgeRuntime::clearActiveState($instanceName, (int)\getmypid());
$kernel->sendExited();
$kernel->close();
WlsLogger::flush_(true);
System::exit(0);
