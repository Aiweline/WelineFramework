<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\MasterProcess;

/**
 * Filesystem, port and immutable configuration helpers for the WLS-owned
 * public HTTP protocol edge. L7 policy remains exclusively in WorkerPolicyKernel.
 */
final class ProtocolEdgeRuntime
{
    public const ROLE = 'protocol_edge';
    public const PROCESS_NAME_PREFIX = 'weline-wls-protocol-edge';
    public const AUTH_HEADER = 'X-WLS-Edge-Token';
    public const CLIENT_PROTOCOL_HEADER = 'X-WLS-Client-Protocol';
    public const DIRECT_RELOAD_SURGE_MIN_CANONICAL_ID = 100;
    public const DIRECT_RELOAD_SURGE_ID_GAP = 1000;

    public static function selection(ServiceContext $context): HttpProtocolSelection
    {
        $http = $context->getConfig('wls.http', []);

        return HttpProtocolSelection::fromConfig(
            ['http' => \is_array($http) ? $http : []],
            $context->sslEnabled,
        );
    }

    public static function isEnabled(ServiceContext $context): bool
    {
        return self::selection($context)->isProtocolEdgeEnabled();
    }

    public static function runtimeDirectory(string $instanceName): string
    {
        $instanceName = self::normalizeInstanceName($instanceName);

        return Env::VAR_DIR . 'server' . DS . 'protocol-edge' . DS . $instanceName;
    }

    public static function tokenFile(string $instanceName): string
    {
        return self::runtimeDirectory($instanceName) . DS . 'edge.token';
    }

    public static function configFile(string $instanceName): string
    {
        return self::runtimeDirectory($instanceName) . DS . 'edge.conf';
    }

    public static function nativeConfigFile(string $instanceName): string
    {
        return self::runtimeDirectory($instanceName) . DS . 'caddy.json';
    }

    public static function sessionTicketStorageDirectory(string $instanceName): string
    {
        return self::runtimeDirectory($instanceName) . DS . 'stek';
    }

    public static function sessionTicketKeyFile(string $instanceName): string
    {
        return self::runtimeDirectory($instanceName) . DS . 'session-ticket-keys.json';
    }

    public static function pidFile(string $instanceName): string
    {
        return self::runtimeDirectory($instanceName) . DS . 'edge-child.pid';
    }

    public static function activeStateFile(string $instanceName): string
    {
        return self::runtimeDirectory($instanceName) . DS . 'active.json';
    }

    public static function ensureTokenFile(string $instanceName): string
    {
        $directory = self::runtimeDirectory($instanceName);
        self::ensurePrivateDirectory($directory);
        $path = self::tokenFile($instanceName);
        $existing = \is_file($path) ? \strtolower(\trim((string)@\file_get_contents($path))) : '';
        if (\preg_match('/^[a-f0-9]{64}$/D', $existing) === 1) {
            @\chmod($path, 0600);
            return $path;
        }

        self::writeAtomically($path, \bin2hex(\random_bytes(32)) . PHP_EOL, 0600);

        return $path;
    }

    /**
     * Persist the adapted Caddy JSON with an instance-isolated distributed
     * session-ticket key source. Caddy reloads provision a new TLS app even
     * for route-only changes; storing the STEK outside that app keeps TLS 1.3
     * resumable without sharing ticket keys between WLS instances.
     *
     * @param object $adaptedConfig Native JSON object; keeping object identity
     *        is required because Caddy distinguishes `{}` from `[]`.
     */
    public static function writeNativeConfig(
        string $instanceName,
        object $adaptedConfig,
        bool $tlsSessionResumption = true,
    ): string
    {
        $apps = $adaptedConfig->apps ?? null;
        $tls = \is_object($apps) ? ($apps->tls ?? null) : null;
        if (!\is_object($apps) || !\is_object($tls)) {
            throw new \RuntimeException('Adapted protocol-edge config does not contain the Caddy TLS app.');
        }

        self::ensurePrivateDirectory(self::runtimeDirectory($instanceName));
        if ($tlsSessionResumption) {
            $storageDirectory = self::sessionTicketStorageDirectory($instanceName);
            self::ensurePrivateDirectory($storageDirectory);
            $tls->session_tickets = (object)[
                'key_source' => (object)[
                    'provider' => 'distributed',
                    'storage' => (object)[
                        'module' => 'file_system',
                        'root' => $storageDirectory,
                    ],
                ],
                'rotation_interval' => '12h',
                'max_keys' => 4,
            ];
        } else {
            $tls->session_tickets = (object)['disabled' => true];
        }

        $payload = \json_encode(
            $adaptedConfig,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR,
        );
        $path = self::nativeConfigFile($instanceName);
        self::writeAtomically($path, $payload . PHP_EOL, 0600);

        return $path;
    }

    public static function readToken(string $instanceName): string
    {
        $path = self::ensureTokenFile($instanceName);
        $token = \strtolower(\trim((string)@\file_get_contents($path)));
        if (\preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \RuntimeException('WLS protocol-edge token file is invalid.');
        }

        return $token;
    }

    /**
     * Canonical Worker ports behind the protocol edge.
     *
     * @return list<int>
     */
    public static function workerPorts(ServiceContext $context): array
    {
        $count = $context->getWorkerCount();
        $count = $count === 'auto' ? 1 : \max(1, (int)$count);
        $start = $context->getWorkerPort();
        if ($start <= 0) {
            throw new \RuntimeException('Protocol-edge Worker port range is unavailable.');
        }

        return \range($start, $start + $count - 1);
    }

    public static function workerPort(ServiceContext $context, int $instanceId): int
    {
        $start = $context->getWorkerPort();
        if ($start <= 0) {
            throw new \RuntimeException('Protocol-edge Worker port range is unavailable.');
        }
        if ($instanceId > 100) {
            return $start + $instanceId - 1;
        }

        return $start + \max(0, $instanceId - 1);
    }

    public static function directReloadSurgeStartInstanceId(int $maxExistingWorkerId): int
    {
        return \max(self::DIRECT_RELOAD_SURGE_MIN_CANONICAL_ID, $maxExistingWorkerId)
            + self::DIRECT_RELOAD_SURGE_ID_GAP;
    }

    /**
     * Reserve the same deterministic private port range used by the first
     * direct new-first reload generation. Canonical Worker IDs are 1..count.
     *
     * @return list<int>
     */
    public static function directReloadSurgePortsFromWorkerRange(int $workerPort, int $workerCount): array
    {
        if ($workerPort <= 0) {
            return [];
        }

        $workerCount = \max(1, $workerCount);
        $firstSurgeId = self::directReloadSurgeStartInstanceId($workerCount);
        $firstSurgePort = $workerPort + $firstSurgeId - 1;

        return \range($firstSurgePort, $firstSurgePort + $workerCount - 1);
    }

    public static function dispatcherPort(ServiceContext $context): int
    {
        $count = $context->getWorkerCount();
        return self::dispatcherPortFromWorkerRange(
            $context->getWorkerPort(),
            $count === 'auto' ? 1 : (int)$count,
        );
    }

    public static function dispatcherPortFromWorkerRange(int $workerPort, int $workerCount): int
    {
        $port = $workerPort + \max(1, $workerCount) - 1 + 64;
        if ($port <= 0 || $port > 65535) {
            throw new \RuntimeException('Protocol-edge internal Dispatcher port is outside the valid range.');
        }

        return $port;
    }

    public static function adminPort(ServiceContext $context): int
    {
        return self::adminPortForInstance($context->instanceName, $context->mainPort);
    }

    public static function adminPortForInstance(string $instanceName, int $mainPort): int
    {
        $offset = MasterProcess::getProjectPortOffset();
        // Keep Caddy's loopback-only control socket below the default dynamic
        // client-port ranges used by Linux (32768+), macOS and Windows
        // (49152+). The previous 30k-50k formula intermittently collided with
        // a short-lived local connection and made an otherwise healthy WLS
        // cold start fail with EADDRINUSE. Instance, public port and project
        // offset keep the mapping deterministic across config generation,
        // process launch and reload.
        $hash = (int)\sprintf('%u', \crc32($instanceName . ':' . $mainPort . ':' . $offset));
        $candidate = 10000 + ($hash % 7000);
        if ($candidate === $mainPort) {
            $candidate = 10000 + (($candidate - 9999) % 7000);
        }

        return $candidate;
    }

    /**
     * @return list<string>
     */
    public static function upstreams(ServiceContext $context): array
    {
        if ($context->isDispatcherEnabled()) {
            return ['127.0.0.1:' . self::dispatcherPort($context)];
        }

        return \array_map(
            static fn (int $port): string => '127.0.0.1:' . $port,
            self::workerPorts($context),
        );
    }

    /**
     * Build an immutable, private Caddyfile and return its path.
     */
    public static function writeConfig(ServiceContext $context, ?array $publishedUpstreams = null): string
    {
        $selection = self::selection($context);
        if (!$selection->isProtocolEdgeEnabled()) {
            throw new \RuntimeException('Protocol-edge config requested for a disabled instance.');
        }
        if (!$context->sslEnabled || !\is_file($context->sslCert) || !\is_file($context->sslKey)) {
            throw new \RuntimeException('HTTP/2 and HTTP/3 protocol edge requires readable TLS certificate and key files.');
        }

        $directory = self::runtimeDirectory($context->instanceName);
        self::ensurePrivateDirectory($directory);
        if ($selection->isNativeProtocolEdge()) {
            return self::writeWlsNativeConfig($context, $selection, $publishedUpstreams);
        }
        $token = self::readToken($context->instanceName);
        $serverName = self::normalizeServerName($context->publicHost ?: $context->host);
        $authority = self::formatAuthority($serverName, $context->mainPort);
        $protocols = \implode(' ', $selection->caddyProtocols());
        $resolvedUpstreams = self::normalizePublishedUpstreams($context, $publishedUpstreams);
        $upstreams = \implode(' ', $resolvedUpstreams);
        $adminAddress = '127.0.0.1:' . self::adminPort($context);
        $healthHost = self::formatAuthority($serverName, $context->mainPort);
        $idlePerHost = \max(64, \count($resolvedUpstreams) * 64);
        $maxPerHost = \max(256, \count($resolvedUpstreams) * 128);
        $sslConfig = $context->getConfig('wls.ssl', []);
        $tlsSelection = (new TlsProcessProfileConfigurator())->resolveConfiguration([
            'ssl' => \is_array($sslConfig) ? $sslConfig : [],
        ]);
        $tlsProtocols = $tlsSelection['protocols'];
        $tlsMinimum = \in_array('tls1.2', $tlsProtocols, true) ? 'tls1.2' : 'tls1.3';
        $tlsMaximum = \in_array('tls1.3', $tlsProtocols, true) ? 'tls1.3' : 'tls1.2';
        $tlsPolicyLines = [
            '    tls ' . self::quote($context->sslCert) . ' ' . self::quote($context->sslKey) . ' {',
            '        protocols ' . $tlsMinimum . ' ' . $tlsMaximum,
        ];
        if ($tlsSelection['requested'] === TlsProcessProfileConfigurator::PROFILE_PERFORMANCE) {
            // Caddy terminates public TLS when h2/h3 is enabled, so the PHP
            // OPENSSL_CONF alone cannot enforce the WLS performance profile.
            $tlsPolicyLines[] = '        curves x25519 secp256r1';
        }
        $tlsPolicyLines[] = '    }';

        $lines = [
            '{',
            '    admin ' . $adminAddress,
            '    auto_https disable_redirects',
            '    servers {',
            '        protocols ' . $protocols,
            '        max_header_size 64KB',
            '        timeouts {',
            '            read_header 5s',
            '            read_body 30s',
            '            write 30s',
            '            idle 2m',
            '        }',
            '        keepalive_interval 30s',
            '    }',
            '    grace_period 10s',
            '}',
            '',
            'https://' . $authority . ' {',
            ...$tlsPolicyLines,
            '',
            '    reverse_proxy ' . $upstreams . ' {',
            '        lb_policy least_conn',
            '        lb_try_duration 500ms',
            '        lb_try_interval 10ms',
            '        health_uri /_wls/health',
            '        health_interval 2s',
            '        health_timeout 500ms',
            '        health_status 2xx',
            '        health_headers {',
            '            Host ' . self::quote($healthHost),
            '            ' . self::AUTH_HEADER . ' ' . self::quote($token),
            '            ' . self::CLIENT_PROTOCOL_HEADER . ' HTTP/1.1',
            '        }',
            '        fail_duration 5s',
            '        max_fails 1',
            // Preserve the original authority, including non-default ports. The
            // Worker FPC/warmup key is authority-sensitive; stripping the port
            // here forces every edge request through the slower full pipeline.
            '        header_up Host {http.request.hostport}',
            '        header_up ' . self::AUTH_HEADER . ' ' . self::quote($token),
            '        header_up ' . self::CLIENT_PROTOCOL_HEADER . ' {http.request.proto}',
            '        header_up X-Forwarded-For {http.request.remote.host}',
            '        header_up X-Forwarded-Proto {http.request.scheme}',
            '        transport http {',
            '            versions 1.1',
            // Do not synthesize Accept-Encoding:gzip for clients which did not
            // request it. Otherwise the Worker misses its identity Process-FPC
            // warmup and Caddy immediately decompresses the response again.
            '            compression off',
            '            dial_timeout 250ms',
            '            response_header_timeout 30s',
            '            keepalive 2m',
            '            keepalive_idle_conns 1024',
            '            keepalive_idle_conns_per_host ' . $idlePerHost,
            '            max_conns_per_host ' . $maxPerHost,
            '        }',
            '    }',
            '}',
            '',
        ];

        $path = self::configFile($context->instanceName);
        self::writeAtomically($path, \implode(PHP_EOL, $lines), 0600);

        return $path;
    }

    /**
     * Compile the WLS-owned protocol engine's immutable listener, TLS and
     * upstream contract. The engine only terminates protocols and reuses
     * connections; WorkerPolicyKernel remains authoritative for every L7 rule.
     *
     * @param list<int|string>|null $publishedUpstreams
     */
    private static function writeWlsNativeConfig(
        ServiceContext $context,
        HttpProtocolSelection $selection,
        ?array $publishedUpstreams,
    ): string {
        $resolvedUpstreams = self::normalizePublishedUpstreams($context, $publishedUpstreams);
        $serverName = self::normalizeServerName($context->publicHost ?: $context->host);
        $listenHost = self::normalizeListenHost($context->host);
        $healthHost = self::formatAuthority($serverName, $context->mainPort);
        $sslConfig = $context->getConfig('wls.ssl', []);
        $tlsSelection = (new TlsProcessProfileConfigurator())->resolveConfiguration([
            'ssl' => \is_array($sslConfig) ? $sslConfig : [],
        ]);
        $tlsProtocols = $tlsSelection['protocols'];
        $tlsMinimum = \in_array('tls1.2', $tlsProtocols, true) ? 'tls1.2' : 'tls1.3';
        $tlsMaximum = \in_array('tls1.3', $tlsProtocols, true) ? 'tls1.3' : 'tls1.2';
        $upstreamCount = \count($resolvedUpstreams);

        $payload = \json_encode([
            'schema_version' => 1,
            'instance' => $context->instanceName,
            'public' => [
                'address' => self::formatAuthority($listenHost, $context->mainPort, true),
                'host' => $serverName,
                'port' => $context->mainPort,
            ],
            'admin_address' => '127.0.0.1:' . self::adminPort($context),
            'protocols' => $selection->protocols,
            'preferred' => $selection->preferred,
            'alt_svc' => $selection->altSvc,
            'tls' => [
                'certificate_file' => $context->sslCert,
                'private_key_file' => $context->sslKey,
                'minimum_version' => $tlsMinimum,
                'maximum_version' => $tlsMaximum,
                'key_exchange_profile' => $tlsSelection['requested'],
                'session_resumption' => $selection->tlsSessionResumption,
                'session_ticket_key_file' => self::sessionTicketKeyFile($context->instanceName),
                'session_ticket_rotation' => '12h',
                'session_ticket_max_keys' => 4,
            ],
            'proxy' => [
                'upstreams' => $resolvedUpstreams,
                'token_file' => self::ensureTokenFile($context->instanceName),
                'health_host' => $healthHost,
                'health_path' => '/_wls/health',
                'health_interval' => '2s',
                'health_timeout' => '500ms',
                'dial_timeout' => '250ms',
                'response_header_timeout' => '30s',
                'idle_connection_timeout' => '2m',
                'max_idle_connections' => \max(1024, $upstreamCount * 256),
                'max_idle_per_upstream' => \max(128, $upstreamCount * 64),
                'max_connections_per_upstream' => \max(512, $upstreamCount * 128),
            ],
            'timeouts' => [
                'read_header' => '5s',
                'read_body' => '30s',
                'write' => '30s',
                'idle' => '2m',
            ],
            'limits' => [
                'max_header_bytes' => 65536,
            ],
            'keep_alive' => [
                'tcp_interval' => '30s',
                'quic_idle' => '2m',
            ],
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        $path = self::configFile($context->instanceName);
        self::writeAtomically($path, $payload . PHP_EOL, 0600);

        return $path;
    }

    public static function configDigest(string $instanceName): string
    {
        $path = self::configFile($instanceName);
        if (!\is_file($path)) {
            return '';
        }

        $digest = @\hash_file('sha256', $path);

        return \is_string($digest) && \preg_match('/^[a-f0-9]{64}$/D', $digest) === 1
            ? $digest
            : '';
    }

    /**
     * Publish the exact Caddy configuration digest that is active in the data
     * plane. Master waits for this acknowledgement before draining an old
     * Worker generation, so a filesystem write alone can never remove capacity.
     *
     * @param list<string> $upstreams
     */
    public static function markConfigActive(string $instanceName, string $digest, array $upstreams): void
    {
        $digest = \strtolower(\trim($digest));
        if (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \InvalidArgumentException('Protocol-edge active config digest is invalid.');
        }

        $directory = self::runtimeDirectory($instanceName);
        self::ensurePrivateDirectory($directory);
        $payload = \json_encode([
            'schema_version' => 1,
            'config_digest' => $digest,
            'upstreams' => \array_values($upstreams),
            'pid' => (int)\getmypid(),
            'activated_at' => \date('c'),
            'activated_timestamp' => \microtime(true),
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        if (!\is_string($payload)) {
            throw new \RuntimeException('Unable to encode protocol-edge active state.');
        }
        self::writeAtomically(self::activeStateFile($instanceName), $payload . PHP_EOL, 0600);
    }

    public static function clearActiveState(string $instanceName, ?int $expectedPid = null): void
    {
        $path = self::activeStateFile($instanceName);
        if (!\is_file($path)) {
            return;
        }
        if ($expectedPid !== null && $expectedPid > 0) {
            $state = \json_decode((string)@\file_get_contents($path), true);
            if (!\is_array($state) || (int)($state['pid'] ?? 0) !== $expectedPid) {
                return;
            }
        }
        @\unlink($path);
    }

    public static function isConfigActive(string $instanceName, string $expectedDigest): bool
    {
        $expectedDigest = \strtolower(\trim($expectedDigest));
        if (\preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1) {
            return false;
        }
        $path = self::activeStateFile($instanceName);
        if (!\is_file($path)) {
            return false;
        }
        $state = \json_decode((string)@\file_get_contents($path), true);
        if (!\is_array($state)) {
            return false;
        }
        $pid = (int)($state['pid'] ?? 0);
        if ($pid <= 0 || !Processer::isRunningByPid($pid)) {
            return false;
        }
        $activeDigest = \strtolower(\trim((string)($state['config_digest'] ?? '')));

        return \preg_match('/^[a-f0-9]{64}$/D', $activeDigest) === 1
            && \hash_equals($expectedDigest, $activeDigest);
    }

    public static function resolveBinary(ServiceContext|array|null $source = null): string
    {
        $configured = '';
        $edge = HttpProtocolSelection::EDGE_NATIVE;
        if ($source instanceof ServiceContext) {
            $configured = \trim((string)$source->getConfig('wls.http.protocol_edge_binary', ''));
            $edge = self::selection($source)->edge;
        } elseif (\is_array($source)) {
            $http = \is_array($source['http'] ?? null) ? $source['http'] : [];
            $configured = \trim((string)($http['protocol_edge_binary'] ?? ''));
            $protocols = $http['protocols'] ?? HttpProtocolSelection::DEFAULT_PROTOCOLS;
            $edge = HttpProtocolSelection::fromArray([
                'protocols' => $protocols,
                'preferred' => $http['preferred'] ?? HttpProtocolSelection::HTTP_3,
                'edge' => $http['protocol_edge'] ?? $http['edge'] ?? HttpProtocolSelection::EDGE_NATIVE,
            ])->edge;
        }
        $native = $edge === HttpProtocolSelection::EDGE_NATIVE;
        $environment = \trim((string)(\getenv($native ? 'WLS_PROTOCOL_EDGE_BINARY' : 'WLS_CADDY_BINARY') ?: ''));
        foreach ([$configured, $environment] as $candidate) {
            if (self::isRunnableBinary($candidate)) {
                return $candidate;
            }
        }

        $binaryName = $native
            ? (PHP_OS_FAMILY === 'Windows' ? 'wls-protocol-edge.exe' : 'wls-protocol-edge')
            : (PHP_OS_FAMILY === 'Windows' ? 'caddy.exe' : 'caddy');
        $path = (string)(\getenv('PATH') ?: '');
        foreach (\array_filter(\explode(PATH_SEPARATOR, $path), 'strlen') as $directory) {
            $candidate = \rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binaryName;
            if (self::isRunnableBinary($candidate)) {
                return $candidate;
            }
        }
        foreach (self::commonBinaryPaths($binaryName, $native) as $candidate) {
            if (self::isRunnableBinary($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * PHP running under Windows x64 emulation reports native ARM64 PE files as
     * non-executable even though CreateProcess can launch them. Capability
     * probes remain the final authority on Windows; POSIX still requires the
     * executable permission bit.
     */
    public static function isRunnableBinary(string $candidate): bool
    {
        return $candidate !== ''
            && \is_file($candidate)
            && (PHP_OS_FAMILY === 'Windows' || \is_executable($candidate));
    }

    /**
     * Project-local dependency path used by the verified Windows installer.
     * Keeping the binary under var avoids administrator-only Program Files
     * writes and makes the selected version stable across service accounts.
     */
    public static function managedBinaryPath(): string
    {
        $binaryName = PHP_OS_FAMILY === 'Windows' ? 'caddy.exe' : 'caddy';

        return Env::VAR_DIR . 'server' . DS . 'runtime' . DS . 'caddy' . DS . $binaryName;
    }

    /**
     * Project-local WLS-native protocol engine. It is built or installed
     * before Master creates any child process and is never resolved through a
     * third-party web-server package.
     */
    public static function managedNativeBinaryPath(): string
    {
        $binaryName = PHP_OS_FAMILY === 'Windows' ? 'wls-protocol-edge.exe' : 'wls-protocol-edge';

        return Env::VAR_DIR . 'server' . DS . 'runtime' . DS . 'protocol-edge' . DS . $binaryName;
    }

    /**
     * @return list<string>
     */
    private static function commonBinaryPaths(string $binaryName, bool $native): array
    {
        if ($native) {
            return [self::managedNativeBinaryPath()];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $programFiles = \rtrim((string)(\getenv('ProgramFiles') ?: 'C:\\Program Files'), '/\\');
            $localAppData = \rtrim((string)(\getenv('LOCALAPPDATA') ?: ''), '/\\');

            return \array_values(\array_filter([
                self::managedBinaryPath(),
                $programFiles . '\\Caddy\\' . $binaryName,
                $localAppData !== '' ? $localAppData . '\\Microsoft\\WinGet\\Links\\' . $binaryName : '',
            ]));
        }

        return [
            '/opt/homebrew/bin/' . $binaryName,
            '/usr/local/bin/' . $binaryName,
            '/usr/bin/' . $binaryName,
            '/snap/bin/' . $binaryName,
        ];
    }

    /**
     * @param list<int|string>|null $publishedUpstreams
     * @return list<string>
     */
    private static function normalizePublishedUpstreams(
        ServiceContext $context,
        ?array $publishedUpstreams,
    ): array {
        if ($context->isDispatcherEnabled() || $publishedUpstreams === null) {
            return self::upstreams($context);
        }

        $normalized = [];
        foreach ($publishedUpstreams as $upstream) {
            if (\is_int($upstream) || (\is_string($upstream) && \ctype_digit($upstream))) {
                $port = (int)$upstream;
                if ($port > 0 && $port <= 65535) {
                    $normalized['127.0.0.1:' . $port] = true;
                }
                continue;
            }
            $upstream = \trim((string)$upstream);
            if (\preg_match('/^127\.0\.0\.1:([1-9][0-9]{0,4})$/D', $upstream, $matches) !== 1) {
                continue;
            }
            $port = (int)$matches[1];
            if ($port > 0 && $port <= 65535) {
                $normalized[$upstream] = true;
            }
        }
        if ($normalized === []) {
            throw new \RuntimeException('Protocol-edge cannot publish an empty Worker upstream set.');
        }
        $upstreams = \array_keys($normalized);
        \sort($upstreams, \SORT_NATURAL);

        return $upstreams;
    }

    private static function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \preg_replace('/[^A-Za-z0-9_.-]+/', '-', \trim($instanceName)) ?? '';

        return $instanceName !== '' ? $instanceName : 'default';
    }

    private static function normalizeServerName(string $host): string
    {
        $host = \trim($host);
        if (\str_contains($host, '://')) {
            $parsed = \parse_url($host, PHP_URL_HOST);
            $host = \is_string($parsed) ? $parsed : '';
        } elseif ($host !== '' && $host[0] === '[') {
            $end = \strpos($host, ']');
            $host = $end === false ? $host : \substr($host, 1, $end - 1);
        } elseif (\substr_count($host, ':') === 1) {
            [$host] = \explode(':', $host, 2);
        }
        $host = \trim($host, '[]');
        if ($host === '' || $host === '0.0.0.0' || $host === '::' || $host === '*') {
            return 'localhost';
        }

        return \strtolower($host);
    }

    private static function normalizeListenHost(string $host): string
    {
        $host = \trim($host);
        if (\str_contains($host, '://')) {
            $parsed = \parse_url($host, PHP_URL_HOST);
            $host = \is_string($parsed) ? $parsed : '';
        } elseif ($host !== '' && $host[0] === '[') {
            $end = \strpos($host, ']');
            $host = $end === false ? \trim($host, '[]') : \substr($host, 1, $end - 1);
        } elseif (\substr_count($host, ':') === 1) {
            [$host] = \explode(':', $host, 2);
        }
        $host = \trim($host, '[]');
        if ($host === '' || $host === '*') {
            return '0.0.0.0';
        }

        return $host;
    }

    private static function formatAuthority(string $host, int $port, bool $alwaysPort = false): string
    {
        $authority = \filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)
            ? '[' . $host . ']'
            : $host;
        if ($alwaysPort || $port !== 443) {
            $authority .= ':' . $port;
        }

        return $authority;
    }

    private static function quote(string $value): string
    {
        return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private static function ensurePrivateDirectory(string $directory): void
    {
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create WLS protocol-edge runtime directory.');
        }
        @\chmod($directory, 0700);
    }

    private static function writeAtomically(string $path, string $contents, int $mode): void
    {
        $temporary = $path . '.tmp.' . \bin2hex(\random_bytes(6));
        if (@\file_put_contents($temporary, $contents, \LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write WLS protocol-edge runtime file.');
        }
        @\chmod($temporary, $mode);
        if (!@\rename($temporary, $path)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish WLS protocol-edge runtime file.');
        }
        @\chmod($path, $mode);
    }
}
