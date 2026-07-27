<?php

declare(strict_types=1);

/**
 * Standalone WLS 2.0 host gateway controller.
 *
 * This file intentionally has no framework dependency. GatewayHostManager
 * copies it into an immutable host A/B slot so the gateway can restart after
 * the project that initially installed it has moved away.
 */
final class WlsEdgeGatewayController
{
    private const PROTOCOL = 'wls-edge/2';
    private const HEARTBEAT_TTL = 45;
    private const DRAIN_SECONDS = 300;
    private const STALE_RETENTION = 86400;
    private const SNAPSHOT_RETENTION = 604800;
    private const HEALTH_INTERVAL = 5;
    private const BACKEND_PROBE_INTERVAL = 60;
    private const FAILURE_WINDOW = 300;
    private const CIRCUIT_WINDOW = 900;
    private const CIRCUIT_THRESHOLD = 10;
    private const MAX_REQUEST_BYTES = 4194304;

    /** @var array<string,mixed> */
    private array $state = [];
    /** @var array<string,int> */
    private array $nonces = [];
    private bool $running = true;
    private bool $configDirty = false;
    private float $lastHealthAt = 0.0;
    private float $lastBackendProbeAt = 0.0;
    /** @var resource|null */
    private $controlServer = null;

    public function __construct(private readonly string $home)
    {
        $this->ensureDirectories();
        $this->state = $this->loadState();
    }

    public static function main(array $argv): int
    {
        if (\in_array('--self-test', $argv, true)) {
            $ok = \extension_loaded('openssl')
                && \function_exists('stream_socket_server')
                && \PHP_INT_SIZE >= 8;
            echo \json_encode([
                'ok' => $ok,
                'protocol' => self::PROTOCOL,
                'php' => \PHP_VERSION,
                'openssl' => \defined('OPENSSL_VERSION_TEXT') ? \OPENSSL_VERSION_TEXT : '',
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return $ok ? 0 : 1;
        }

        $home = '';
        foreach ($argv as $arg) {
            if (\str_starts_with((string)$arg, '--home=')) {
                $home = \substr((string)$arg, 7);
            }
        }
        if ($home === '' || \str_contains($home, "\0")) {
            \fwrite(STDERR, "Missing --home for WLS Gateway Controller.\n");
            return 2;
        }

        try {
            return (new self(\rtrim($home, '/\\')))->run();
        } catch (\Throwable $throwable) {
            \fwrite(STDERR, '[wls-edge/2] fatal: ' . $throwable->getMessage() . "\n");
            return 1;
        }
    }

    public function run(): int
    {
        // Start/adopt Nginx before opening the control listener. Otherwise a
        // daemonized Nginx master can inherit the Unix listening descriptor
        // and impersonate a live-but-silent controller after a controller exit.
        $this->adoptOrRecoverDataPlane();
        $this->controlServer = $this->openControlServer();
        $this->writePid();
        try {
            while ($this->running) {
                if (!\is_resource($this->controlServer)) {
                    $this->controlServer = $this->openControlServer();
                }
                $read = [$this->controlServer];
                $write = [];
                $except = [];
                $selected = @\stream_select($read, $write, $except, 1, 0);
                if ($selected === false) {
                    \usleep(100000);
                } elseif ($selected > 0) {
                    $client = @\stream_socket_accept($this->controlServer, 0);
                    if (\is_resource($client)) {
                        $this->serveClient($client);
                    }
                }
                $this->maintenance();
            }
        } finally {
            if (\is_resource($this->controlServer)) {
                @\fclose($this->controlServer);
            }
            $this->controlServer = null;
            if (\PHP_OS_FAMILY !== 'Windows') {
                @\unlink($this->socketFile());
            }
            @\unlink($this->controllerPidFile());
        }
        return 0;
    }

    /**
     * @param resource $client
     */
    private function serveClient($client): void
    {
        try {
            \stream_set_timeout($client, 3);
            $line = @\fgets($client, self::MAX_REQUEST_BYTES + 1);
            if (!\is_string($line) || \strlen($line) > self::MAX_REQUEST_BYTES) {
                $this->writeResponse($client, '', false, [], 'invalid_request', 'Request is empty or too large.');
                return;
            }
            $request = \json_decode($line, true);
            if (!\is_array($request)) {
                $this->writeResponse($client, '', false, [], 'invalid_json', 'Request is not valid JSON.');
                return;
            }
            $requestId = (string)($request['request_id'] ?? '');
            $authError = $this->authenticate($request);
            if ($authError !== '') {
                $this->writeResponse($client, $requestId, false, [], 'unauthorized', $authError);
                return;
            }
            $operation = \strtolower(\trim((string)($request['operation'] ?? '')));
            $payload = \is_array($request['payload'] ?? null) ? $request['payload'] : [];
            try {
                $result = $this->dispatch($operation, $payload);
                $this->writeResponse($client, $requestId, true, $result);
            } catch (\Throwable $throwable) {
                $code = $throwable instanceof \DomainException ? 'rejected' : 'operation_failed';
                $this->journal('request_rejected', [
                    'operation' => $operation,
                    'reason' => $throwable->getMessage(),
                ]);
                $this->writeResponse($client, $requestId, false, [], $code, $throwable->getMessage());
            }
        } finally {
            @\fclose($client);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function dispatch(string $operation, array $payload): array
    {
        return match ($operation) {
            'status' => $this->status(),
            'routes' => ['routes' => \array_values((array)($this->state['routes'] ?? []))],
            'doctor' => $this->doctor(),
            'register' => $this->register($payload, false),
            'renew' => $this->register($payload, true),
            'heartbeat' => $this->heartbeat($payload),
            'drain' => $this->drain($payload),
            'unregister' => $this->unregister($payload),
            'enroll' => $this->enroll($payload),
            'revoke' => $this->revoke($payload),
            'repair' => $this->repair(),
            'upgrade' => $this->upgradeSnapshot($payload),
            'stop' => $this->stopGateway($payload),
            default => throw new \DomainException('Unsupported wls-edge/2 operation: ' . $operation),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function status(): array
    {
        $nginx = $this->nginxStatus();
        $routeCounts = [];
        foreach ((array)($this->state['routes'] ?? []) as $route) {
            $status = (string)($route['status'] ?? 'UNKNOWN');
            $routeCounts[$status] = ($routeCounts[$status] ?? 0) + 1;
        }
        return [
            'ready' => (bool)($this->state['ready'] ?? false),
            'protocol' => self::PROTOCOL,
            'protocol_min' => 2,
            'protocol_max' => 2,
            'epoch' => (string)$this->state['epoch'],
            'generation' => (int)$this->state['generation'],
            'state' => (string)$this->state['health_state'],
            'data_plane' => $nginx,
            'route_counts' => $routeCounts,
            'public_http' => (int)$this->state['public_http'],
            'public_https' => (int)$this->state['public_https'],
            'h3_enabled' => (bool)($this->state['h3_enabled'] ?? false),
            'h3_reason' => (string)($this->state['h3_reason'] ?? ''),
            'active_slot' => (string)$this->state['active_slot'],
            'previous_slot' => (string)$this->state['previous_slot'],
            'recovery' => (array)$this->state['recovery'],
            'supervisor_ready' => (bool)($this->state['supervisor_ready'] ?? false),
            'isolation_mode' => (bool)($this->state['isolation_mode'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function doctor(): array
    {
        $status = $this->status();
        $status['paths'] = [
            'home' => $this->home,
            'state' => $this->stateFile(),
            'config' => $this->configFile(),
            'journal' => $this->journalFile(),
        ];
        $status['binary'] = [
            'path' => $this->nginxBinary(),
            'sha256' => $this->fileHash($this->nginxBinary()),
            'expected_sha256' => (string)($this->slotManifest()['binary_sha256'] ?? ''),
        ];
        $status['lkg'] = (array)($this->state['lkg'] ?? []);
        $status['failures'] = (array)($this->state['failure_events'] ?? []);
        return $status;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function register(array $payload, bool $renew): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $projectRoot = $this->canonicalDirectory((string)($payload['project_root'] ?? ''));
        $instanceId = \trim((string)($payload['instance_id'] ?? ''));
        $generation = (int)($payload['project_generation'] ?? 0);
        $digest = \strtolower(\trim((string)($payload['request_digest'] ?? '')));
        $idempotencyKey = \trim((string)($payload['idempotency_key'] ?? ''));
        $gatewayEpoch = \trim((string)($payload['gateway_epoch'] ?? ''));
        if (!\preg_match('/^[a-f0-9-]{36}$/D', $projectUuid)
            || $instanceId === ''
            || $generation < 1
            || !\preg_match('/^[a-f0-9]{64}$/D', $digest)
            || $idempotencyKey === ''
        ) {
            throw new \DomainException('Registration identity or fencing fields are incomplete.');
        }
        if ($gatewayEpoch !== '' && !\hash_equals((string)$this->state['epoch'], $gatewayEpoch)) {
            throw new \DomainException('Gateway epoch changed; submit a full registration against epoch '
                . (string)$this->state['epoch'] . '.');
        }
        $peer = \is_array($payload['peer_identity'] ?? null) ? $payload['peer_identity'] : [];
        $this->validatePeerIdentity($peer);
        $this->assertEnrollment($projectUuid, $projectRoot, $peer);

        $projects = (array)($this->state['projects'] ?? []);
        $existing = \is_array($projects[$projectUuid] ?? null) ? $projects[$projectUuid] : [];
        $existingGeneration = (int)($existing['generation'] ?? 0);
        $existingDigest = (string)($existing['digest'] ?? '');
        if ($generation < $existingGeneration) {
            throw new \DomainException('Stale project generation rejected.');
        }
        if ($generation === $existingGeneration) {
            if ($existingDigest !== '' && \hash_equals($existingDigest, $digest)) {
                $this->touchProjectRoutes($projectUuid);
                return [
                    'idempotent' => true,
                    'epoch' => (string)$this->state['epoch'],
                    'generation' => (int)$this->state['generation'],
                    'routes' => $this->projectRoutes($projectUuid),
                ];
            }
            throw new \DomainException('Same project generation has a different request digest.');
        }

        $routePayloads = \is_array($payload['routes'] ?? null) ? $payload['routes'] : [];
        if ($routePayloads === [] || \count($routePayloads) > 2048) {
            throw new \DomainException('Registration must contain 1..2048 routes.');
        }
        if ($renew) {
            $expected = \is_array($payload['expected_route_generations'] ?? null)
                ? $payload['expected_route_generations']
                : [];
            foreach ($expected as $routeId => $expectedGeneration) {
                $current = $this->state['routes'][(string)$routeId] ?? null;
                if (!\is_array($current)
                    || (int)($current['route_generation'] ?? 0) !== (int)$expectedGeneration
                ) {
                    throw new \DomainException('Certificate renew route generation fence was rejected.');
                }
            }
        }

        $candidateRoutes = [];
        foreach ($routePayloads as $routePayload) {
            if (!\is_array($routePayload)) {
                throw new \DomainException('Route payload must be an object.');
            }
            $candidateRoutes[] = $this->validateRoute(
                $routePayload,
                $projectUuid,
                $projectRoot,
                $instanceId,
            );
        }
        $this->assertNoDomainConflicts($projectUuid, $candidateRoutes);

        foreach ((array)($this->state['routes'] ?? []) as $routeId => $route) {
            if (\is_array($route) && (string)($route['project_uuid'] ?? '') === $projectUuid) {
                unset($this->state['routes'][$routeId]);
            }
        }
        foreach ($candidateRoutes as $route) {
            $this->state['routes'][(string)$route['route_id']] = $route;
        }
        $projects[$projectUuid] = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'instance_id' => $instanceId,
            'generation' => $generation,
            'digest' => $digest,
            'idempotency_key' => $idempotencyKey,
            'last_heartbeat' => \time(),
            'registered_at' => \gmdate(DATE_ATOM),
        ];
        $this->state['projects'] = $projects;
        $this->state['isolation_mode'] = false;
        $this->bumpGeneration('register', ['project_uuid' => $projectUuid, 'renew' => $renew]);
        $this->configDirty = true;
        $this->publishIfDirty();
        return [
            'idempotent' => false,
            'epoch' => (string)$this->state['epoch'],
            'generation' => (int)$this->state['generation'],
            'routes' => $this->projectRoutes($projectUuid),
        ];
    }

    /**
     * @param array<string,mixed> $route
     * @return array<string,mixed>
     */
    private function validateRoute(
        array $route,
        string $projectUuid,
        string $projectRoot,
        string $instanceId,
    ): array {
        $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
        $domain = $this->normalizeDomain((string)($route['domain'] ?? ''));
        if (!\preg_match('/^[a-f0-9]{32}$/D', $routeId)) {
            throw new \DomainException('Route ID must be a 32-character lowercase hex digest.');
        }
        $backends = \is_array($route['backends'] ?? null) ? $route['backends'] : [];
        if ($backends === [] || \count($backends) > 32) {
            throw new \DomainException('Route must contain 1..32 backends.');
        }
        $normalizedBackends = [];
        foreach ($backends as $backend) {
            if (!\is_array($backend)) {
                throw new \DomainException('Backend must be an object.');
            }
            $host = \strtolower(\trim((string)($backend['host'] ?? '')));
            $port = (int)($backend['port'] ?? 0);
            if (!\in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
                || $port < 1 || $port > 65535
            ) {
                throw new \DomainException('Gateway backends must be valid loopback endpoints.');
            }
            $normalizedBackends[] = [
                'host' => $host === 'localhost' ? '127.0.0.1' : $host,
                'port' => $port,
                'weight' => \max(1, \min(1000, (int)($backend['weight'] ?? 1))),
            ];
        }

        $identity = \is_array($route['backend_identity'] ?? null)
            ? $route['backend_identity']
            : [];
        if (!\hash_equals($projectUuid, (string)($identity['project_uuid'] ?? ''))
            || !\hash_equals($instanceId, (string)($identity['instance_id'] ?? ''))
            || (int)($identity['generation'] ?? 0) < 1
        ) {
            throw new \DomainException('Backend identity does not match the registering project.');
        }
        $this->validateBackendEndpointIdentity($identity, $projectRoot, $normalizedBackends);
        $backendHealthy = $this->probeBackends($normalizedBackends);

        $certificate = \is_array($route['certificate'] ?? null) ? $route['certificate'] : [];
        $snapshot = $this->snapshotCertificate($projectUuid, $projectRoot, $domain, $certificate);
        $status = $backendHealthy ? 'ACTIVE' : 'PENDING_BACKEND';
        if (!$snapshot['valid']) {
            $status = 'PENDING_CERTIFICATE';
        }
        $existing = $this->state['routes'][$routeId] ?? null;
        $routeGeneration = \is_array($existing)
            ? (int)($existing['route_generation'] ?? 0) + 1
            : 1;
        return [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'instance_id' => $instanceId,
            'domain' => $domain,
            'backends' => $normalizedBackends,
            'backend_identity' => $identity,
            'certificate' => $snapshot,
            'route_generation' => $routeGeneration,
            'status' => $status,
            'last_heartbeat' => \time(),
            'last_backend_probe' => \time(),
            'stale_since' => null,
            'drain_until' => null,
            'force_https' => (bool)($route['force_https'] ?? true),
            'updated_at' => \gmdate(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $identity
     * @param list<array{host:string,port:int,weight:int}> $backends
     */
    private function validateBackendEndpointIdentity(array $identity, string $projectRoot, array $backends): void
    {
        $endpointFile = (string)($identity['endpoint_file'] ?? '');
        $endpointReal = \realpath($endpointFile);
        $expectedRoot = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'server';
        if (!\is_string($endpointReal)
            || !$this->pathInside($endpointReal, $expectedRoot)
            || $this->pathHasSymlink($endpointReal, $expectedRoot)
        ) {
            throw new \DomainException('Backend identity endpoint is outside the project runtime.');
        }
        $raw = @\file_get_contents($endpointReal);
        $endpoint = \is_string($raw) ? \json_decode($raw, true) : null;
        if (!\is_array($endpoint)
            || !\hash_equals((string)$identity['instance_id'], (string)($endpoint['instance_name'] ?? $endpoint['name'] ?? ''))
        ) {
            throw new \DomainException('Backend endpoint identity cannot be verified.');
        }
        $endpointPort = (int)($endpoint['main_port'] ?? $endpoint['port'] ?? 0);
        $matchesPort = false;
        foreach ($backends as $backend) {
            $matchesPort = $matchesPort || $backend['port'] === $endpointPort;
        }
        if (!$matchesPort) {
            throw new \DomainException('Backend port is not owned by the registered instance endpoint.');
        }
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        if ($masterPid < 1 || !$this->pidRunning($masterPid)) {
            throw new \DomainException('Backend Master identity is not running.');
        }
    }

    /**
     * @param array<string,mixed> $certificate
     * @return array<string,mixed>
     */
    private function snapshotCertificate(
        string $projectUuid,
        string $projectRoot,
        string $domain,
        array $certificate,
    ): array {
        $certPath = (string)($certificate['cert_path'] ?? '');
        $keyPath = (string)($certificate['key_path'] ?? '');
        $allowedRoots = [$projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc'
            . DIRECTORY_SEPARATOR . 'ssl'];
        $enrollment = $this->state['enrollments'][$projectUuid] ?? null;
        if (\is_array($enrollment)) {
            foreach ((array)($enrollment['certificate_roots'] ?? []) as $root) {
                $allowedRoots[] = (string)$root;
            }
        }
        $certReal = $this->authorizedRegularFile($certPath, $allowedRoots);
        $keyReal = $this->authorizedRegularFile($keyPath, $allowedRoots);
        $beforeCert = $this->fileHash($certReal);
        $beforeKey = $this->fileHash($keyReal);
        if ($beforeCert === '' || $beforeKey === '') {
            throw new \DomainException('Certificate material cannot be hashed.');
        }
        $certPem = @\file_get_contents($certReal);
        $keyPem = @\file_get_contents($keyReal);
        if (!\is_string($certPem) || !\is_string($keyPem)) {
            throw new \DomainException('Certificate material cannot be read.');
        }
        $x509 = @\openssl_x509_read($certPem);
        $private = @\openssl_pkey_get_private($keyPem);
        $public = $x509 !== false ? @\openssl_pkey_get_public($x509) : false;
        if ($x509 === false || $private === false || $public === false) {
            throw new \DomainException('Certificate or private key is invalid.');
        }
        $privateDetails = @\openssl_pkey_get_details($private);
        $publicDetails = @\openssl_pkey_get_details($public);
        if (!\is_array($privateDetails)
            || !\is_array($publicDetails)
            || !\hash_equals((string)($privateDetails['key'] ?? ''), (string)($publicDetails['key'] ?? ''))
        ) {
            throw new \DomainException('Certificate private key does not match.');
        }
        $parsed = @\openssl_x509_parse($x509, false);
        if (!\is_array($parsed)
            || (int)($parsed['validTo_time_t'] ?? 0) <= \time()
            || !$this->certificateCoversDomain($parsed, $domain)
        ) {
            throw new \DomainException('Certificate SAN or validity does not cover route domain.');
        }
        $snapshotDigest = \hash('sha256', $beforeCert . ':' . $beforeKey);
        $snapshotDir = $this->home . DIRECTORY_SEPARATOR . 'snapshots'
            . DIRECTORY_SEPARATOR . $snapshotDigest;
        if (!\is_dir($snapshotDir) && !@\mkdir($snapshotDir, 0700, true) && !\is_dir($snapshotDir)) {
            throw new \RuntimeException('Unable to create certificate snapshot directory.');
        }
        $certSnapshot = $snapshotDir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keySnapshot = $snapshotDir . DIRECTORY_SEPARATOR . 'privkey.pem';
        $this->atomicWrite($certSnapshot, $certPem, 0644);
        $this->atomicWrite($keySnapshot, $keyPem, 0600);
        if (!\hash_equals($beforeCert, $this->fileHash($certReal))
            || !\hash_equals($beforeKey, $this->fileHash($keyReal))
        ) {
            throw new \DomainException('Certificate source changed during snapshot; previous generation retained.');
        }
        return [
            'valid' => true,
            'source_digest' => (string)($certificate['source_digest'] ?? ''),
            'generation' => (string)($certificate['generation'] ?? $snapshotDigest),
            'snapshot_digest' => $snapshotDigest,
            'cert_path' => $certSnapshot,
            'key_path' => $keySnapshot,
            'not_after' => (int)$parsed['validTo_time_t'],
        ];
    }

    /**
     * @param array<string,mixed> $parsed
     */
    private function certificateCoversDomain(array $parsed, string $domain): bool
    {
        $names = [];
        $san = (string)($parsed['extensions']['subjectAltName'] ?? '');
        foreach (\explode(',', $san) as $entry) {
            $entry = \trim($entry);
            if (\str_starts_with($entry, 'DNS:')) {
                $names[] = \strtolower(\substr($entry, 4));
            }
        }
        $commonName = \strtolower(\trim((string)($parsed['subject']['CN'] ?? '')));
        if ($commonName !== '') {
            $names[] = $commonName;
        }
        foreach (\array_unique($names) as $name) {
            if ($name === $domain) {
                return true;
            }
            if (\str_starts_with($name, '*.')
                && \substr_count($domain, '.') === \substr_count($name, '.')
                && \str_ends_with($domain, \substr($name, 1))
            ) {
                return true;
            }
            if (\str_starts_with($domain, '*.') && $name === \substr($domain, 2)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function heartbeat(array $payload): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $generation = (int)($payload['project_generation'] ?? 0);
        $project = $this->state['projects'][$projectUuid] ?? null;
        if (!\is_array($project) || (int)($project['generation'] ?? 0) !== $generation) {
            throw new \DomainException('Heartbeat project generation is stale or unknown.');
        }
        $now = \time();
        $this->state['projects'][$projectUuid]['last_heartbeat'] = $now;
        foreach ((array)$this->state['routes'] as $routeId => $route) {
            if (\is_array($route) && (string)$route['project_uuid'] === $projectUuid) {
                $this->state['routes'][$routeId]['last_heartbeat'] = $now;
            }
        }
        $this->persistState();
        return [
            'epoch' => (string)$this->state['epoch'],
            'generation' => (int)$this->state['generation'],
            'accepted' => true,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function drain(array $payload): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $instanceId = \trim((string)($payload['instance_id'] ?? ''));
        $found = false;
        foreach ((array)$this->state['routes'] as $routeId => $route) {
            if (!\is_array($route)
                || (string)$route['project_uuid'] !== $projectUuid
                || ($instanceId !== '' && (string)$route['instance_id'] !== $instanceId)
            ) {
                continue;
            }
            $found = true;
            $this->state['routes'][$routeId]['status'] = 'DRAINING';
            $this->state['routes'][$routeId]['drain_until'] = \time()
                + \max(1, \min(self::DRAIN_SECONDS, (int)($payload['seconds'] ?? self::DRAIN_SECONDS)));
        }
        if (!$found) {
            return ['accepted' => true, 'idempotent' => true, 'already_removed' => true];
        }
        $this->bumpGeneration('drain', ['project_uuid' => $projectUuid]);
        $this->configDirty = true;
        $this->publishIfDirty();
        return ['accepted' => true, 'drain_seconds' => self::DRAIN_SECONDS];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function unregister(array $payload): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $instanceId = \trim((string)($payload['instance_id'] ?? ''));
        foreach ((array)$this->state['routes'] as $routeId => $route) {
            if (\is_array($route)
                && (string)$route['project_uuid'] === $projectUuid
                && ($instanceId === '' || (string)$route['instance_id'] === $instanceId)
            ) {
                $this->state['routes'][$routeId]['status'] = 'REMOVED';
                $this->state['routes'][$routeId]['removed_at'] = \time();
            }
        }
        unset($this->state['projects'][$projectUuid]);
        $this->bumpGeneration('unregister', ['project_uuid' => $projectUuid]);
        $this->configDirty = true;
        $this->publishIfDirty();
        return ['accepted' => true];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function enroll(array $payload): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $projectRoot = $this->canonicalDirectory((string)($payload['project_root'] ?? ''));
        if (!\preg_match('/^[a-f0-9-]{36}$/D', $projectUuid)) {
            throw new \DomainException('Enrollment requires a valid project UUID.');
        }
        $roots = [];
        foreach ((array)($payload['certificate_roots'] ?? []) as $root) {
            $canonical = $this->canonicalDirectory((string)$root);
            if (!$this->pathInside($canonical, $projectRoot)) {
                throw new \DomainException('Enrollment certificate roots must stay inside the project root.');
            }
            $roots[] = $canonical;
        }
        $this->state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'certificate_roots' => \array_values(\array_unique($roots)),
            'uid' => \function_exists('posix_geteuid') ? \posix_geteuid() : null,
            'enrolled_at' => \gmdate(DATE_ATOM),
        ];
        $this->persistState();
        $this->journal('enroll', ['project_uuid' => $projectUuid]);
        return ['accepted' => true, 'project_uuid' => $projectUuid];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function revoke(array $payload): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $this->unregister(['project_uuid' => $projectUuid]);
        unset($this->state['enrollments'][$projectUuid]);
        $this->persistState();
        $this->journal('revoke', ['project_uuid' => $projectUuid]);
        return ['accepted' => true];
    }

    /**
     * @return array<string,mixed>
     */
    private function repair(): array
    {
        $this->state['recovery']['circuit_open_until'] = 0;
        $this->state['recovery']['stage'] = 'MANUAL_REPAIR';
        $this->configDirty = true;
        $published = $this->publishIfDirty();
        if (!$published) {
            $this->restartDataPlane('manual_repair');
        }
        return $this->status();
    }

    /**
     * @return array<string,mixed>
     */
    private function upgradeSnapshot(array $payload = []): array
    {
        if (($payload['activate'] ?? false) === true) {
            $slot = \strtoupper(\trim((string)($payload['slot'] ?? '')));
            $activeFileSlot = \strtoupper(\trim((string)@\file_get_contents($this->activeSlotFile())));
            if (!\in_array($slot, ['A', 'B'], true)
                || !\hash_equals($slot, $activeFileSlot)
                || !\is_file($this->slotDir($slot) . DIRECTORY_SEPARATOR . 'manifest.json')
                || !\is_file($this->slotDir($slot) . DIRECTORY_SEPARATOR . $this->nginxBinaryName())
            ) {
                throw new \DomainException('Gateway A/B candidate slot is missing or does not match active-slot intent.');
            }
            $manifestRaw = @\file_get_contents($this->slotDir($slot) . DIRECTORY_SEPARATOR . 'manifest.json');
            $manifest = \is_string($manifestRaw) ? \json_decode($manifestRaw, true) : null;
            $binary = $this->slotDir($slot) . DIRECTORY_SEPARATOR . $this->nginxBinaryName();
            if (!\is_array($manifest)
                || !\hash_equals((string)($manifest['binary_sha256'] ?? ''), $this->fileHash($binary))
            ) {
                throw new \DomainException('Gateway candidate binary digest verification failed.');
            }
            $previous = (string)$this->state['active_slot'];
            $this->state['previous_slot'] = $previous;
            $this->state['active_slot'] = $slot;
            $this->state['binary_healthy_since'] = 0;
            $this->state['recovery']['stage'] = 'BINARY_UPGRADE';
            $this->persistState();
            $this->restartDataPlane('binary_upgrade');
        }
        return [
            'active_slot' => (string)$this->state['active_slot'],
            'previous_slot' => (string)$this->state['previous_slot'],
            'binary' => $this->nginxBinary(),
            'binary_sha256' => $this->fileHash($this->nginxBinary()),
            'healthy_since' => (int)($this->state['binary_healthy_since'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function stopGateway(array $payload): array
    {
        $active = \array_filter(
            (array)$this->state['routes'],
            static fn (mixed $route): bool => \is_array($route)
                && \in_array((string)($route['status'] ?? ''), ['ACTIVE', 'DRAINING', 'STALE'], true),
        );
        if ($active !== [] && !($payload['force'] ?? false)) {
            throw new \DomainException('Gateway has active routes; use explicit force after draining tenants.');
        }
        $result = $this->stopDataPlane();
        if (!($result['ok'] ?? false)) {
            throw new \RuntimeException((string)($result['message'] ?? 'Unable to stop gateway data plane.'));
        }
        $this->running = false;
        return ['accepted' => true, 'message' => 'Gateway controller and data plane are stopping.'];
    }

    private function maintenance(): void
    {
        $now = \microtime(true);
        if ($now - $this->lastHealthAt >= self::HEALTH_INTERVAL) {
            $this->lastHealthAt = $now;
            $this->expireLeases();
            $this->publishIfDirty();
            $this->recoverDataPlane();
            $this->pruneNonces();
            $this->collectSnapshots();
        }
        if ($now - $this->lastBackendProbeAt >= self::BACKEND_PROBE_INTERVAL) {
            $this->lastBackendProbeAt = $now;
            $this->probeActiveBackends();
        }
    }

    private function expireLeases(): void
    {
        $now = \time();
        foreach ((array)$this->state['routes'] as $routeId => $route) {
            if (!\is_array($route)) {
                continue;
            }
            $status = (string)($route['status'] ?? '');
            if ($status === 'DRAINING' && (int)($route['drain_until'] ?? 0) <= $now) {
                $this->state['routes'][$routeId]['status'] = 'REMOVED';
                $this->state['routes'][$routeId]['removed_at'] = $now;
                $this->configDirty = true;
                continue;
            }
            if (\in_array($status, ['ACTIVE', 'PENDING_BACKEND', 'PENDING_CERTIFICATE'], true)
                && $now - (int)($route['last_heartbeat'] ?? 0) >= self::HEARTBEAT_TTL
            ) {
                $this->state['routes'][$routeId]['status'] = 'STALE';
                $this->state['routes'][$routeId]['stale_since'] = $now;
                $this->configDirty = true;
                continue;
            }
            if ($status === 'STALE'
                && $now - (int)($route['stale_since'] ?? $now) >= self::STALE_RETENTION
            ) {
                $this->state['routes'][$routeId]['status'] = 'REMOVED';
                $this->state['routes'][$routeId]['removed_at'] = $now;
                $this->configDirty = true;
            }
        }
        if ($this->configDirty) {
            $this->bumpGeneration('lease_transition');
        }
    }

    private function probeActiveBackends(): void
    {
        $changed = false;
        foreach ((array)$this->state['routes'] as $routeId => $route) {
            if (!\is_array($route)
                || !\in_array((string)($route['status'] ?? ''), ['ACTIVE', 'PENDING_BACKEND'], true)
            ) {
                continue;
            }
            $healthy = $this->probeBackends((array)$route['backends']);
            $next = $healthy ? 'ACTIVE' : 'PENDING_BACKEND';
            if ((string)$route['status'] !== $next) {
                $this->state['routes'][$routeId]['status'] = $next;
                $changed = true;
            }
            $this->state['routes'][$routeId]['last_backend_probe'] = \time();
        }
        if ($changed) {
            $this->bumpGeneration('backend_health_transition');
            $this->configDirty = true;
        } else {
            $this->persistState();
        }
    }

    private function recoverDataPlane(): void
    {
        $status = $this->nginxStatus();
        $healthy = ($status['running'] ?? false) && $this->publicPortsReachable();
        if ($healthy) {
            $this->state['ready'] = true;
            $this->state['health_state'] = 'HEALTHY';
            $this->state['recovery']['consecutive_failures'] = 0;
            $this->state['recovery']['stage'] = 'NONE';
            if ((int)($this->state['pending_lkg_generation'] ?? 0) > 0
                && \time() - (int)($this->state['pending_lkg_since'] ?? 0) >= 15
            ) {
                $this->promoteLkg();
            }
            if ((int)($this->state['binary_healthy_since'] ?? 0) <= 0) {
                $this->state['binary_healthy_since'] = \time();
            }
            $this->persistState();
            return;
        }

        $this->state['ready'] = false;
        $this->state['health_state'] = 'DATA_PLANE_DOWN';
        $this->recordFailure((string)($status['message'] ?? 'public probes failed'));
        $recovery = (array)$this->state['recovery'];
        if ((int)($recovery['circuit_open_until'] ?? 0) > \time()) {
            $this->state['recovery']['stage'] = 'CIRCUIT_OPEN';
            $this->persistState();
            return;
        }
        if ((int)($recovery['consecutive_failures'] ?? 0) < 3) {
            $this->persistState();
            return;
        }

        $recentFive = \array_filter(
            (array)$this->state['failure_events'],
            static fn (mixed $event): bool => \is_array($event)
                && (int)($event['at'] ?? 0) >= \time() - self::FAILURE_WINDOW,
        );
        if (\count($recentFive) >= 3 && $this->rollbackToLkg()) {
            $this->state['recovery']['stage'] = 'CONFIG_ROLLBACK';
            $this->restartDataPlane('lkg_rollback');
            return;
        }
        $this->state['recovery']['stage'] = 'FAST_RESTART';
        $this->restartDataPlane('health_failure');
    }

    private function recordFailure(string $reason): void
    {
        $now = \time();
        $events = (array)$this->state['failure_events'];
        $events[] = ['at' => $now, 'reason' => $reason];
        $events = \array_values(\array_filter(
            $events,
            static fn (mixed $event): bool => \is_array($event)
                && (int)($event['at'] ?? 0) >= $now - self::CIRCUIT_WINDOW,
        ));
        $this->state['failure_events'] = $events;
        $this->state['recovery']['consecutive_failures'] =
            (int)($this->state['recovery']['consecutive_failures'] ?? 0) + 1;
        $this->state['recovery']['last_failure'] = $reason;
        if (\count($events) >= self::CIRCUIT_THRESHOLD) {
            $attempt = \max(1, (int)($this->state['recovery']['backoff_attempt'] ?? 0) + 1);
            $delay = \min(300, 5 * (2 ** \min(6, $attempt - 1)));
            $this->state['recovery']['backoff_attempt'] = $attempt;
            $this->state['recovery']['circuit_open_until'] = $now + $delay;
            $this->state['recovery']['next_retry_at'] = $now + $delay;
        }
        $this->journal('recovery_failure', ['reason' => $reason]);
    }

    private function restartDataPlane(string $reason): void
    {
        $status = $this->nginxStatus();
        if (($status['running'] ?? false) === true) {
            $this->stopDataPlane();
        }
        $start = $this->startDataPlane();
        if (!($start['ok'] ?? false)) {
            $this->recordFailure((string)($start['message'] ?? 'Nginx restart failed.'));
            if ((int)($this->state['binary_healthy_since'] ?? 0) > 0
                && \time() - (int)$this->state['binary_healthy_since'] < self::FAILURE_WINDOW
            ) {
                $this->rollbackBinarySlot();
            }
        }
        $this->journal('data_plane_restart', ['reason' => $reason, 'result' => $start]);
        $this->persistState();
    }

    private function publishIfDirty(): bool
    {
        if (!$this->configDirty) {
            return true;
        }
        $candidate = $this->configDir() . DIRECTORY_SEPARATOR . 'candidate-'
            . (int)$this->state['generation'] . '.conf';
        $config = $this->renderNginxConfig(true);
        $this->atomicWrite($candidate, $config, 0600);
        $test = $this->runNginx(['-t', '-c', $candidate]);
        if (($test['code'] ?? 1) !== 0) {
            // H3 is an optional capability. A QUIC-only config failure retries
            // without QUIC and records the downgrade, preserving H2/H1.
            $this->state['h3_enabled'] = false;
            $this->state['h3_reason'] = 'Candidate failed with H3; downgraded to H2/H1: '
                . (string)($test['output'] ?? '');
            $this->atomicWrite($candidate, $this->renderNginxConfig(false), 0600);
            $test = $this->runNginx(['-t', '-c', $candidate]);
        }
        if (($test['code'] ?? 1) !== 0) {
            $this->recordFailure('nginx -t failed: ' . (string)($test['output'] ?? ''));
            @\unlink($candidate);
            $this->persistState();
            return false;
        }
        $current = $this->configFile();
        if (\is_file($current)) {
            $previous = $this->lkgDir() . DIRECTORY_SEPARATOR . 'pre-'
                . (int)$this->state['generation'] . '.conf';
            @\copy($current, $previous);
        }
        if (!@\rename($candidate, $current)) {
            @\unlink($candidate);
            $this->recordFailure('Atomic Nginx config publication failed.');
            return false;
        }
        @\chmod($current, 0600);
        $status = $this->nginxStatus();
        $result = ($status['running'] ?? false)
            ? $this->reloadDataPlane()
            : $this->startDataPlane();
        if (!($result['ok'] ?? false)) {
            $this->recordFailure((string)($result['message'] ?? 'Nginx publication failed.'));
            if ($this->rollbackToLkg()) {
                $this->restartDataPlane('publication_rollback');
            }
            return false;
        }
        $this->state['pending_lkg_generation'] = (int)$this->state['generation'];
        $this->state['pending_lkg_since'] = \time();
        $this->configDirty = false;
        $this->persistRouteLkg();
        $this->persistState();
        return true;
    }

    private function promoteLkg(): void
    {
        $generation = (int)$this->state['pending_lkg_generation'];
        $target = $this->lkgDir() . DIRECTORY_SEPARATOR . 'lkg-' . $generation . '.conf';
        if (\is_file($this->configFile())) {
            @\copy($this->configFile(), $target);
        }
        $lkg = (array)$this->state['lkg'];
        \array_unshift($lkg, ['generation' => $generation, 'file' => $target, 'healthy_at' => \time()]);
        $this->state['lkg'] = \array_slice($lkg, 0, 2);
        $this->state['pending_lkg_generation'] = 0;
        $this->state['pending_lkg_since'] = 0;
        $this->persistState();
    }

    private function rollbackToLkg(): bool
    {
        foreach ((array)$this->state['lkg'] as $lkg) {
            $file = \is_array($lkg) ? (string)($lkg['file'] ?? '') : '';
            if ($file !== '' && \is_file($file) && @\copy($file, $this->configFile())) {
                return true;
            }
        }
        return false;
    }

    private function rollbackBinarySlot(): void
    {
        $previous = (string)($this->state['previous_slot'] ?? '');
        if (!\in_array($previous, ['A', 'B'], true)
            || !\is_file($this->slotDir($previous) . DIRECTORY_SEPARATOR . $this->nginxBinaryName())
        ) {
            return;
        }
        $current = (string)$this->state['active_slot'];
        $this->state['active_slot'] = $previous;
        $this->state['previous_slot'] = $current;
        $this->state['binary_healthy_since'] = 0;
        $this->atomicWrite($this->activeSlotFile(), $previous . "\n", 0600);
        $this->state['recovery']['stage'] = 'BINARY_ROLLBACK';
        $this->journal('binary_rollback', ['from' => $current, 'to' => $previous]);
    }

    private function renderNginxConfig(bool $allowH3): string
    {
        $this->ensureNeutralCertificate();
        $generation = (int)$this->state['generation'];
        $httpPort = (int)$this->state['public_http'];
        $httpsPort = (int)$this->state['public_https'];
        $pid = $this->quote($this->nginxPidFile());
        $errorLog = $this->quote($this->logDir() . DIRECTORY_SEPARATOR . 'error.log');
        $accessLog = $this->quote($this->logDir() . DIRECTORY_SEPARATOR . 'access.log');
        $neutralCert = $this->quote($this->stateDir() . DIRECTORY_SEPARATOR . 'neutral-cert.pem');
        $neutralKey = $this->quote($this->stateDir() . DIRECTORY_SEPARATOR . 'neutral-key.pem');
        $h3 = $allowH3 && (bool)($this->state['h3_capable'] ?? false);

        $lines = [
            'worker_processes auto;',
            'pid ' . $pid . ';',
            'error_log ' . $errorLog . ' warn;',
            'events { worker_connections 32768; multi_accept on; }',
            'http {',
            '  include ' . $this->quote($this->mimeTypesFile()) . ';',
            '  default_type application/octet-stream;',
            '  access_log ' . $accessLog . ';',
            '  sendfile on;',
            '  keepalive_timeout 65;',
            '  map $http_upgrade $connection_upgrade { default upgrade; "" close; }',
            '  server {',
            '    listen 127.0.0.1:' . $this->healthPort() . ';',
            '    server_name _;',
            '    location = /__wls_gateway_health {',
            '      default_type application/json;',
            '      add_header X-WLS-Gateway-Generation "' . $generation . '" always;',
            '      return 200 \'{"protocol":"wls-edge/2","generation":' . $generation . '}\';',
            '    }',
            '    location / { return 404; }',
            '  }',
            '  server {',
            '    listen 0.0.0.0:' . $httpPort . ' default_server;',
            '    listen [::]:' . $httpPort . ' default_server;',
            '    server_name _;',
            '    return 404;',
            '  }',
            '  server {',
            '    listen 0.0.0.0:' . $httpsPort . ' ssl default_server;',
            '    listen [::]:' . $httpsPort . ' ssl default_server;',
            '    http2 on;',
            '    ssl_protocols TLSv1.3;',
            '    ssl_certificate ' . $neutralCert . ';',
            '    ssl_certificate_key ' . $neutralKey . ';',
            '    server_name _;',
            '    return 421;',
            '  }',
        ];

        $h3ListenerDeclared = false;
        foreach ((array)$this->state['routes'] as $route) {
            if (!\is_array($route) || (string)($route['status'] ?? '') === 'REMOVED') {
                continue;
            }
            $routeId = (string)$route['route_id'];
            $domain = (string)$route['domain'];
            $status = (string)$route['status'];
            $upstream = 'wls_' . $routeId;
            $lines[] = '  upstream ' . $upstream . ' {';
            $lines[] = '    keepalive 256;';
            foreach ((array)$route['backends'] as $backend) {
                if (!\is_array($backend)) {
                    continue;
                }
                $host = (string)$backend['host'];
                if ($host === '::1') {
                    $host = '[::1]';
                }
                $lines[] = '    server ' . $host . ':' . (int)$backend['port']
                    . ' weight=' . (int)$backend['weight'] . ' max_fails=2 fail_timeout=5s;';
            }
            $lines[] = '  }';
            $lines[] = '  server {';
            $lines[] = '    listen 0.0.0.0:' . $httpPort . ';';
            $lines[] = '    listen [::]:' . $httpPort . ';';
            $lines[] = '    server_name ' . $domain . ';';
            if ($status === 'PENDING_CERTIFICATE') {
                $lines[] = '    location ^~ /.well-known/acme-challenge/ {';
                $lines[] = '      proxy_pass http://' . $upstream . ';';
                $lines[] = '    }';
                $lines[] = '    location / { return 404; }';
            } elseif ($status === 'ACTIVE') {
                $lines[] = '    return 308 https://$host$request_uri;';
            } else {
                $lines[] = '    return 503;';
            }
            $lines[] = '  }';
            $certificate = (array)$route['certificate'];
            if (!(bool)($certificate['valid'] ?? false)) {
                continue;
            }
            $lines[] = '  server {';
            $lines[] = '    listen 0.0.0.0:' . $httpsPort . ' ssl;';
            $lines[] = '    listen [::]:' . $httpsPort . ' ssl;';
            if ($h3) {
                $reusePort = $h3ListenerDeclared ? '' : ' reuseport';
                $lines[] = '    listen 0.0.0.0:' . $httpsPort . ' quic' . $reusePort . ';';
                $lines[] = '    listen [::]:' . $httpsPort . ' quic' . $reusePort . ';';
                $h3ListenerDeclared = true;
            }
            $lines[] = '    http2 on;';
            $lines[] = '    ssl_protocols TLSv1.3;';
            $lines[] = '    ssl_certificate ' . $this->quote((string)$certificate['cert_path']) . ';';
            $lines[] = '    ssl_certificate_key ' . $this->quote((string)$certificate['key_path']) . ';';
            $lines[] = '    server_name ' . $domain . ';';
            $lines[] = '    if ($ssl_server_name != $host) { return 421; }';
            if ($h3) {
                $lines[] = '    add_header Alt-Svc \'h3=":' . $httpsPort . '"; ma=3600\' always;';
            }
            if ($status !== 'ACTIVE') {
                $lines[] = '    return 503;';
            } else {
                $identity = (array)$route['backend_identity'];
                $lines[] = '    location / {';
                $lines[] = '      proxy_http_version 1.1;';
                $lines[] = '      proxy_set_header Host $host;';
                $lines[] = '      proxy_set_header X-Forwarded-Proto https;';
                $lines[] = '      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
                $lines[] = '      proxy_set_header Upgrade $http_upgrade;';
                $lines[] = '      proxy_set_header Connection $connection_upgrade;';
                $lines[] = '      proxy_set_header X-WLS-Edge-Protocol wls-edge/2;';
                $lines[] = '      proxy_set_header X-WLS-Project-UUID '
                    . $this->quote((string)$route['project_uuid']) . ';';
                $lines[] = '      proxy_set_header X-WLS-Instance-ID '
                    . $this->quote((string)$route['instance_id']) . ';';
                $lines[] = '      proxy_set_header X-WLS-Backend-Generation '
                    . (int)($identity['generation'] ?? 0) . ';';
                $lines[] = '      proxy_buffering off;';
                $lines[] = '      proxy_read_timeout 3600s;';
                $lines[] = '      proxy_send_timeout 3600s;';
                $lines[] = '      proxy_pass http://' . $upstream . ';';
                $lines[] = '    }';
            }
            $lines[] = '  }';
        }
        $lines[] = '}';
        $this->state['h3_enabled'] = $h3;
        return \implode("\n", $lines) . "\n";
    }

    /**
     * @return array{ok:bool,message:string,pid?:int}
     */
    private function startDataPlane(): array
    {
        if (!\is_file($this->configFile())) {
            return ['ok' => false, 'message' => 'Gateway Nginx config is missing.'];
        }
        $test = $this->runNginx(['-t']);
        if (($test['code'] ?? 1) !== 0) {
            return ['ok' => false, 'message' => 'nginx -t failed: ' . (string)$test['output']];
        }
        $reopenControl = \is_resource($this->controlServer);
        if ($reopenControl) {
            @\fclose($this->controlServer);
            $this->controlServer = null;
            if (\PHP_OS_FAMILY !== 'Windows') {
                @\unlink($this->socketFile());
            }
        }
        try {
            $start = $this->runNginx([]);
        } finally {
            if ($reopenControl) {
                $this->controlServer = $this->openControlServer();
            }
        }
        if (($start['code'] ?? 1) !== 0) {
            return ['ok' => false, 'message' => 'Nginx start failed: ' . (string)$start['output']];
        }
        for ($attempt = 0; $attempt < 30; $attempt++) {
            \usleep(100000);
            $status = $this->nginxStatus();
            if (($status['running'] ?? false) === true) {
                return ['ok' => true, 'message' => 'started', 'pid' => (int)$status['pid']];
            }
        }
        return ['ok' => false, 'message' => 'Nginx did not publish a verified PID.'];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function reloadDataPlane(): array
    {
        $status = $this->nginxStatus();
        if (!($status['running'] ?? false)) {
            return $this->startDataPlane();
        }
        $reload = $this->runNginx(['-s', 'reload']);
        return ($reload['code'] ?? 1) === 0
            ? ['ok' => true, 'message' => 'reloaded']
            : ['ok' => false, 'message' => 'reload failed: ' . (string)$reload['output']];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function stopDataPlane(): array
    {
        $status = $this->nginxStatus();
        if (!($status['running'] ?? false)) {
            @\unlink($this->nginxPidFile());
            return ['ok' => true, 'message' => 'not running'];
        }
        $pid = (int)$status['pid'];
        $quit = $this->runNginx(['-s', 'quit']);
        if (($quit['code'] ?? 1) !== 0) {
            return ['ok' => false, 'message' => 'graceful stop failed: ' . (string)$quit['output']];
        }
        $deadline = \microtime(true) + 30.0;
        while (\microtime(true) < $deadline) {
            if (!$this->pidRunning($pid)) {
                @\unlink($this->nginxPidFile());
                return ['ok' => true, 'message' => 'stopped'];
            }
            \usleep(100000);
        }
        return ['ok' => false, 'message' => 'Nginx graceful stop timed out; no unverified signal was sent.'];
    }

    /**
     * @return array<string,mixed>
     */
    private function nginxStatus(): array
    {
        $raw = \trim((string)@\file_get_contents($this->nginxPidFile()));
        if ($raw === '' || !\ctype_digit($raw)) {
            return ['ok' => true, 'running' => false, 'pid' => null, 'message' => 'not running'];
        }
        $pid = (int)$raw;
        if (!$this->pidRunning($pid)) {
            return ['ok' => true, 'running' => false, 'pid' => $pid, 'message' => 'stale pid'];
        }
        $command = $this->processCommand($pid);
        $binary = $this->nginxBinary();
        $expectedHash = (string)($this->slotManifest()['binary_sha256'] ?? '');
        if ($command === ''
            || !\str_contains($command, $binary)
            || $expectedHash === ''
            || !\hash_equals($expectedHash, $this->fileHash($binary))
        ) {
            return ['ok' => false, 'running' => false, 'pid' => $pid, 'message' => 'PID or binary identity mismatch'];
        }
        return ['ok' => true, 'running' => true, 'pid' => $pid, 'message' => 'running'];
    }

    private function publicPortsReachable(): bool
    {
        foreach ([(int)$this->state['public_http'], (int)$this->state['public_https']] as $port) {
            $socket = @\stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.5);
            if (!\is_resource($socket)) {
                return false;
            }
            @\fclose($socket);
        }
        $socket = @\stream_socket_client('tcp://127.0.0.1:' . $this->healthPort(), $errno, $error, 0.5);
        if (!\is_resource($socket)) {
            return false;
        }
        \fwrite($socket, "GET /__wls_gateway_health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $response = (string)@\stream_get_contents($socket);
        @\fclose($socket);
        return \str_contains($response, '"generation":' . (int)$this->state['generation']);
    }

    private function adoptOrRecoverDataPlane(): void
    {
        $status = $this->nginxStatus();
        if (($status['running'] ?? false) === true) {
            $this->state['health_state'] = 'CONTROL_DEGRADED';
            $this->state['ready'] = $this->publicPortsReachable();
            $this->state['recovery']['stage'] = 'ADOPTED';
            $this->persistState();
            return;
        }
        if (!\is_file($this->configFile())) {
            $this->configDirty = true;
            $this->publishIfDirty();
            return;
        }
        $this->startDataPlane();
    }

    /**
     * @param list<array{host:string,port:int,weight?:int}> $backends
     */
    private function probeBackends(array $backends): bool
    {
        foreach ($backends as $backend) {
            $host = (string)($backend['host'] ?? '');
            $port = (int)($backend['port'] ?? 0);
            if ($host === '' || $port < 1) {
                continue;
            }
            $address = $host === '::1' ? 'tcp://[::1]:' . $port : 'tcp://' . $host . ':' . $port;
            $socket = @\stream_socket_client($address, $errno, $error, 0.5);
            if (\is_resource($socket)) {
                @\fclose($socket);
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $candidateRoutes
     */
    private function assertNoDomainConflicts(string $projectUuid, array $candidateRoutes): void
    {
        foreach ($candidateRoutes as $candidate) {
            foreach ((array)$this->state['routes'] as $existing) {
                if (!\is_array($existing)
                    || (string)($existing['status'] ?? '') === 'REMOVED'
                    || (string)$existing['project_uuid'] === $projectUuid
                ) {
                    continue;
                }
                if ($this->domainsOverlap((string)$candidate['domain'], (string)$existing['domain'])) {
                    throw new \DomainException(
                        'Domain conflict with project ' . (string)$existing['project_uuid']
                        . ': ' . (string)$candidate['domain']
                    );
                }
            }
        }
    }

    private function domainsOverlap(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        foreach ([[$left, $right], [$right, $left]] as [$wildcard, $exact]) {
            if (\str_starts_with($wildcard, '*.')
                && \substr_count($wildcard, '.') === \substr_count($exact, '.')
                && \str_ends_with($exact, \substr($wildcard, 1))
            ) {
                return true;
            }
        }
        return false;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\rtrim(\trim($domain), '.'));
        $wildcard = \str_starts_with($domain, '*.');
        $body = $wildcard ? \substr($domain, 2) : $domain;
        if (\function_exists('idn_to_ascii')) {
            $ascii = @\idn_to_ascii($body, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (\is_string($ascii) && $ascii !== '') {
                $body = \strtolower($ascii);
            }
        }
        if (\strlen($body) > 253
            || \preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/D', $body) !== 1
        ) {
            throw new \DomainException('Route domain is not a valid IDNA ASCII hostname.');
        }
        return $wildcard ? '*.' . $body : $body;
    }

    /**
     * @param array<string,mixed> $peer
     */
    private function validatePeerIdentity(array $peer): void
    {
        if (\PHP_OS_FAMILY !== 'Windows' && \function_exists('posix_geteuid')) {
            if ((int)($peer['uid'] ?? -1) !== \posix_geteuid()) {
                throw new \DomainException('Registration OS peer identity does not match the gateway user.');
            }
        }
    }

    /**
     * @param array<string,mixed> $peer
     */
    private function assertEnrollment(string $projectUuid, string $projectRoot, array $peer): void
    {
        $existing = $this->state['enrollments'][$projectUuid] ?? null;
        if (\is_array($existing)) {
            if (!\hash_equals((string)$existing['project_root'], $projectRoot)) {
                throw new \DomainException('Enrolled project UUID belongs to another project root.');
            }
            return;
        }
        $this->state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'certificate_roots' => [],
            'uid' => $peer['uid'] ?? null,
            'enrolled_at' => \gmdate(DATE_ATOM),
            'automatic_standard_root_only' => true,
        ];
    }

    private function touchProjectRoutes(string $projectUuid): void
    {
        $now = \time();
        $this->state['projects'][$projectUuid]['last_heartbeat'] = $now;
        foreach ((array)$this->state['routes'] as $routeId => $route) {
            if (\is_array($route) && (string)$route['project_uuid'] === $projectUuid) {
                $this->state['routes'][$routeId]['last_heartbeat'] = $now;
            }
        }
        $this->persistState();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function projectRoutes(string $projectUuid): array
    {
        return \array_values(\array_filter(
            (array)$this->state['routes'],
            static fn (mixed $route): bool => \is_array($route)
                && (string)($route['project_uuid'] ?? '') === $projectUuid,
        ));
    }

    /**
     * @param resource $client
     * @param array<string,mixed> $payload
     */
    private function writeResponse(
        $client,
        string $requestId,
        bool $ok,
        array $payload = [],
        string $errorCode = '',
        string $message = '',
    ): void {
        $response = [
            'protocol' => self::PROTOCOL,
            'request_id' => $requestId,
            'ok' => $ok,
            'epoch' => (string)($this->state['epoch'] ?? ''),
            'payload' => $payload,
        ];
        if (!$ok) {
            $response['error'] = ['code' => $errorCode, 'message' => $message];
        }
        @\fwrite(
            $client,
            (string)\json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
    }

    /**
     * @param array<string,mixed> $request
     */
    private function authenticate(array $request): string
    {
        if ((string)($request['protocol'] ?? '') !== self::PROTOCOL) {
            return 'Protocol mismatch.';
        }
        $timestamp = (int)($request['timestamp'] ?? 0);
        if (\abs(\time() - $timestamp) > 60) {
            return 'Request timestamp is outside the accepted window.';
        }
        $nonce = \strtolower((string)($request['nonce'] ?? ''));
        if (!\preg_match('/^[a-f0-9]{32}$/D', $nonce) || isset($this->nonces[$nonce])) {
            return 'Request nonce is invalid or replayed.';
        }
        $signature = \strtolower((string)($request['signature'] ?? ''));
        unset($request['signature']);
        $token = \trim((string)@\file_get_contents($this->tokenFile()));
        $expected = \hash_hmac('sha256', $this->canonicalJson($request), $token);
        if (!\preg_match('/^[a-f0-9]{64}$/D', $signature) || !\hash_equals($expected, $signature)) {
            return 'Request signature is invalid.';
        }
        $this->nonces[$nonce] = \time();
        return '';
    }

    private function pruneNonces(): void
    {
        $cutoff = \time() - 120;
        $this->nonces = \array_filter($this->nonces, static fn (int $at): bool => $at >= $cutoff);
        if (\count($this->nonces) > 2048) {
            \asort($this->nonces, SORT_NUMERIC);
            $this->nonces = \array_slice($this->nonces, -2048, null, true);
        }
    }

    private function canonicalJson(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!\is_array($item)) {
                return $item;
            }
            if (!\array_is_list($item)) {
                \ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        return (string)\json_encode(
            $normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function loadState(): array
    {
        $defaults = $this->defaultState();
        $raw = @\file_get_contents($this->stateFile());
        if (!\is_string($raw) || $raw === '') {
            return $defaults;
        }
        $envelope = \json_decode($raw, true);
        $payload = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : null;
        $hash = \is_array($envelope) ? (string)($envelope['sha256'] ?? '') : '';
        if (!\is_array($payload)
            || !\preg_match('/^[a-f0-9]{64}$/D', $hash)
            || !\hash_equals($hash, \hash('sha256', $this->canonicalJson($payload)))
        ) {
            $quarantine = $this->stateFile() . '.corrupt-' . \gmdate('YmdHis');
            @\rename($this->stateFile(), $quarantine);
            $lkg = $this->loadRouteLkg();
            $defaults['routes'] = $lkg;
            foreach ($defaults['routes'] as $routeId => $route) {
                $defaults['routes'][$routeId]['status'] = 'STALE';
                $defaults['routes'][$routeId]['stale_since'] = \time();
            }
            $defaults['isolation_mode'] = true;
            $defaults['health_state'] = 'STATE_REBUILD';
            $defaults['epoch'] = $this->newEpoch();
            return $defaults;
        }
        $state = \array_replace_recursive($defaults, $payload);
        // A controller restart never reuses nonce or controller-process state.
        $state['controller_started_at'] = \time();
        return $state;
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultState(): array
    {
        $manifest = $this->slotManifestFromActiveFile();
        $activeSlot = \in_array((string)($manifest['slot'] ?? ''), ['A', 'B'], true)
            ? (string)$manifest['slot']
            : 'A';
        $capabilities = $this->probeBinaryCapabilities($this->slotDir($activeSlot)
            . DIRECTORY_SEPARATOR . $this->nginxBinaryName());
        return [
            'protocol' => self::PROTOCOL,
            'epoch' => $this->newEpoch(),
            'generation' => 1,
            'controller_started_at' => \time(),
            'public_http' => $this->environmentPort('WLS_GATEWAY_LISTEN_HTTP', 80),
            'public_https' => $this->environmentPort('WLS_GATEWAY_LISTEN_HTTPS', 443),
            'health_port' => $this->environmentPort('WLS_GATEWAY_HEALTH_PORT', 27643),
            'active_slot' => $activeSlot,
            'previous_slot' => $activeSlot === 'A' ? 'B' : 'A',
            'binary_healthy_since' => 0,
            'h3_capable' => (bool)($capabilities['h3'] ?? false),
            'h3_enabled' => false,
            'h3_reason' => (string)($capabilities['reason'] ?? ''),
            'ready' => false,
            'supervisor_ready' => $this->supervisorReady(),
            'health_state' => 'STARTING',
            'isolation_mode' => false,
            'projects' => [],
            'routes' => [],
            'enrollments' => [],
            'lkg' => [],
            'pending_lkg_generation' => 0,
            'pending_lkg_since' => 0,
            'failure_events' => [],
            'recovery' => [
                'stage' => 'NONE',
                'consecutive_failures' => 0,
                'last_failure' => '',
                'backoff_attempt' => 0,
                'circuit_open_until' => 0,
                'next_retry_at' => 0,
            ],
        ];
    }

    private function persistState(): void
    {
        $payload = $this->state;
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', $this->canonicalJson($payload)),
        ];
        $encoded = \json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode gateway state.');
        }
        $this->atomicWrite($this->stateFile(), $encoded, 0600);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function bumpGeneration(string $event, array $context = []): void
    {
        $this->state['generation'] = (int)$this->state['generation'] + 1;
        $this->persistState();
        $this->journal($event, $context + ['generation' => (int)$this->state['generation']]);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function journal(string $event, array $context = []): void
    {
        $entry = [
            'at' => \gmdate(DATE_ATOM),
            'epoch' => (string)($this->state['epoch'] ?? ''),
            'event' => $event,
            'context' => $context,
        ];
        $entry['sha256'] = \hash('sha256', $this->canonicalJson($entry));
        @\file_put_contents(
            $this->journalFile(),
            (string)\json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX,
        );
        @\chmod($this->journalFile(), 0600);
    }

    private function persistRouteLkg(): void
    {
        $payload = (array)$this->state['routes'];
        $encoded = \json_encode([
            'payload' => $payload,
            'sha256' => \hash('sha256', $this->canonicalJson($payload)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (\is_string($encoded)) {
            $this->atomicWrite($this->routeLkgFile(), $encoded, 0600);
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadRouteLkg(): array
    {
        $raw = @\file_get_contents($this->routeLkgFile());
        $envelope = \is_string($raw) ? \json_decode($raw, true) : null;
        $payload = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : [];
        $hash = \is_array($envelope) ? (string)($envelope['sha256'] ?? '') : '';
        return $hash !== '' && \hash_equals($hash, \hash('sha256', $this->canonicalJson($payload)))
            ? $payload
            : [];
    }

    private function collectSnapshots(): void
    {
        $root = $this->home . DIRECTORY_SEPARATOR . 'snapshots';
        if (!\is_dir($root)) {
            return;
        }
        $referenced = [];
        foreach ((array)$this->state['routes'] as $route) {
            if (\is_array($route)) {
                $digest = (string)($route['certificate']['snapshot_digest'] ?? '');
                if ($digest !== '') {
                    $referenced[$digest] = true;
                }
            }
        }
        foreach ((array)$this->state['lkg'] as $lkg) {
            unset($lkg);
            // Route LKG retains its own snapshot references in route-lkg.json.
        }
        foreach (\glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $digest = \basename($dir);
            if (isset($referenced[$digest]) || \time() - (int)@\filemtime($dir) < self::SNAPSHOT_RETENTION) {
                continue;
            }
            foreach (\glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (\is_file($file)) {
                    @\unlink($file);
                }
            }
            @\rmdir($dir);
        }
    }

    private function openControlServer()
    {
        $endpoint = $this->controlEndpoint();
        if ($endpoint['transport'] === 'unix') {
            $existing = @\stream_socket_client($endpoint['address'], $errno, $error, 0.2);
            if (\is_resource($existing)) {
                @\fclose($existing);
                $existingPid = (int)\trim((string)@\file_get_contents($this->controllerPidFile()));
                if ($existingPid > 0 && $existingPid !== \getmypid() && $this->pidRunning($existingPid)) {
                    throw new \RuntimeException('Another WLS Gateway controller already owns the control socket.');
                }
            }
            @\unlink($this->socketFile());
        }
        $server = @\stream_socket_server(
            $endpoint['address'],
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        if (!\is_resource($server)) {
            throw new \RuntimeException('Unable to bind gateway control endpoint: ' . $error);
        }
        \stream_set_blocking($server, false);
        if ($endpoint['transport'] === 'unix') {
            @\chmod($this->socketFile(), 0600);
        }
        $this->atomicWrite(
            $this->endpointFile(),
            (string)\json_encode($endpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            0600,
        );
        return $server;
    }

    /**
     * @return array{transport:string,address:string}
     */
    private function controlEndpoint(): array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return [
                'transport' => 'tcp',
                'address' => 'tcp://127.0.0.1:' . $this->environmentPort('WLS_GATEWAY_CONTROL_PORT', 27642),
            ];
        }
        return ['transport' => 'unix', 'address' => 'unix://' . $this->socketFile()];
    }

    private function ensureNeutralCertificate(): void
    {
        $certFile = $this->stateDir() . DIRECTORY_SEPARATOR . 'neutral-cert.pem';
        $keyFile = $this->stateDir() . DIRECTORY_SEPARATOR . 'neutral-key.pem';
        if (\is_file($certFile) && \is_file($keyFile)) {
            return;
        }
        $key = @\openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = $key !== false ? @\openssl_csr_new(['commonName' => 'unconfigured.wls.invalid'], $key, [
            'digest_alg' => 'sha256',
        ]) : false;
        $cert = $csr !== false && $key !== false
            ? @\openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'])
            : false;
        if ($key === false || $cert === false) {
            throw new \RuntimeException('Unable to generate neutral gateway certificate.');
        }
        $certPem = '';
        $keyPem = '';
        if (!@\openssl_x509_export($cert, $certPem)
            || !@\openssl_pkey_export($key, $keyPem)
        ) {
            throw new \RuntimeException('Unable to export neutral gateway certificate.');
        }
        $this->atomicWrite($certFile, $certPem, 0644);
        $this->atomicWrite($keyFile, $keyPem, 0600);
    }

    /**
     * @param list<string> $arguments
     * @return array{code:int,output:string}
     */
    private function runNginx(array $arguments): array
    {
        $binary = $this->nginxBinary();
        if (!\is_file($binary)) {
            return ['code' => 127, 'output' => 'Gateway Nginx binary is missing.'];
        }
        $command = \array_merge([
            $binary,
            '-p',
            $this->runtimeDir() . DIRECTORY_SEPARATOR,
            '-c',
            $this->configFile(),
        ], $arguments);
        $parts = \array_map(
            static fn (string $part): string => \escapeshellarg($part),
            $command,
        );
        $output = [];
        $code = 0;
        @\exec(\implode(' ', $parts) . ' 2>&1', $output, $code);
        return ['code' => $code, 'output' => \implode("\n", $output)];
    }

    /**
     * @return array{h3:bool,reason:string}
     */
    private function probeBinaryCapabilities(string $binary): array
    {
        if (!\is_file($binary)) {
            return ['h3' => false, 'reason' => 'Gateway Nginx binary is missing.'];
        }
        $output = [];
        $code = 0;
        @\exec(\escapeshellarg($binary) . ' -V 2>&1', $output, $code);
        $text = \implode("\n", $output);
        $h3 = $code === 0
            && \PHP_OS_FAMILY !== 'Windows'
            && \str_contains($text, '--with-http_v3_module');
        return [
            'h3' => $h3,
            'reason' => $h3
                ? 'ngx_http_v3_module is available; candidate validation still gates activation.'
                : 'HTTP/3 unavailable; gateway uses HTTP/2 and HTTP/1.1.',
        ];
    }

    /**
     * @param list<string> $allowedRoots
     */
    private function authorizedRegularFile(string $path, array $allowedRoots): string
    {
        $real = \realpath($path);
        if (!\is_string($real) || !\is_file($real)) {
            throw new \DomainException('Certificate source file is missing.');
        }
        foreach ($allowedRoots as $root) {
            $rootReal = \realpath($root);
            if (!\is_string($rootReal) || !$this->pathInside($real, $rootReal)) {
                continue;
            }
            if ($this->pathHasSymlink($real, $rootReal)) {
                throw new \DomainException('Certificate source contains a symbolic-link traversal.');
            }
            return $real;
        }
        throw new \DomainException('Certificate source is outside enrolled roots.');
    }

    private function pathHasSymlink(string $path, string $root): bool
    {
        $relative = \ltrim(\substr($path, \strlen(\rtrim($root, '/\\'))), '/\\');
        $current = \rtrim($root, '/\\');
        foreach (\preg_split('#[\\\\/]+#', $relative) ?: [] as $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= DIRECTORY_SEPARATOR . $segment;
            $stat = @\lstat($current);
            if (\is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0120000) {
                return true;
            }
        }
        return false;
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        $root = \str_replace('\\', '/', \rtrim($root, '/\\'));
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function canonicalDirectory(string $path): string
    {
        $real = \realpath($path);
        if (!\is_string($real) || !\is_dir($real)) {
            throw new \DomainException('Project or certificate root cannot be resolved.');
        }
        return \rtrim($real, '/\\');
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create directory for atomic write: ' . $directory);
        }
        $temporary = $path . '.tmp-' . \bin2hex(\random_bytes(6));
        if (@\file_put_contents($temporary, $contents, LOCK_EX) !== \strlen($contents)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to write temporary gateway file.');
        }
        @\chmod($temporary, $mode);
        if (!@\rename($temporary, $path)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish gateway file atomically.');
        }
        @\chmod($path, $mode);
    }

    private function fileHash(string $file): string
    {
        $hash = @\hash_file('sha256', $file);
        return \is_string($hash) ? \strtolower($hash) : '';
    }

    private function pidRunning(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }
        if (\PHP_OS_FAMILY !== 'Windows' && \function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $output = [];
            $code = 0;
            @\exec('tasklist /FI ' . \escapeshellarg('PID eq ' . $pid) . ' /NH 2>NUL', $output, $code);
            return $code === 0 && \str_contains(\implode("\n", $output), (string)$pid);
        }
        return \is_dir('/proc/' . $pid);
    }

    private function processCommand(int $pid): string
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $output = [];
            @\exec('wmic process where processid=' . $pid . ' get CommandLine /value 2>NUL', $output);
            return \implode(' ', $output);
        }
        $output = [];
        @\exec('ps -p ' . $pid . ' -o command= 2>/dev/null', $output);
        return \trim(\implode("\n", $output));
    }

    private function environmentPort(string $name, int $default): int
    {
        $raw = \getenv($name);
        if ($raw === false || \trim((string)$raw) === '') {
            return $default;
        }
        $value = \trim((string)$raw);
        if (!\ctype_digit($value) || (int)$value < 1 || (int)$value > 65535) {
            throw new \RuntimeException($name . ' must be an integer port in 1..65535.');
        }
        return (int)$value;
    }

    private function supervisorReady(): bool
    {
        if ((string)\getenv('WLS_GATEWAY_TEST_MODE') === '1') {
            return true;
        }
        return \is_file($this->stateDir() . DIRECTORY_SEPARATOR . 'platform-supervisor.ready');
    }

    private function writePid(): void
    {
        $this->atomicWrite($this->controllerPidFile(), (string)\getmypid() . "\n", 0600);
    }

    private function newEpoch(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    private function quote(string $value): string
    {
        return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * @return array<string,mixed>
     */
    private function slotManifest(): array
    {
        $raw = @\file_get_contents($this->slotDir((string)$this->state['active_slot'])
            . DIRECTORY_SEPARATOR . 'manifest.json');
        $manifest = \is_string($raw) ? \json_decode($raw, true) : null;
        return \is_array($manifest) ? $manifest : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function slotManifestFromActiveFile(): array
    {
        $slot = \strtoupper(\trim((string)@\file_get_contents($this->activeSlotFile())));
        if (!\in_array($slot, ['A', 'B'], true)) {
            $slot = 'A';
        }
        $raw = @\file_get_contents($this->slotDir($slot) . DIRECTORY_SEPARATOR . 'manifest.json');
        $manifest = \is_string($raw) ? \json_decode($raw, true) : null;
        return \is_array($manifest) ? $manifest : ['slot' => $slot];
    }

    private function ensureDirectories(): void
    {
        foreach ([
            $this->runtimeDir(),
            $this->runDir(),
            $this->logDir(),
            $this->stateDir(),
            $this->configDir(),
            $this->lkgDir(),
            $this->home . DIRECTORY_SEPARATOR . 'snapshots',
        ] as $directory) {
            if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
                throw new \RuntimeException('Unable to create gateway directory: ' . $directory);
            }
            @\chmod($directory, 0700);
        }
        $token = \trim((string)@\file_get_contents($this->tokenFile()));
        if (!\preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \RuntimeException('Gateway control token is missing or invalid.');
        }
    }

    private function runtimeDir(): string
    {
        return $this->home . DIRECTORY_SEPARATOR . 'runtime';
    }

    private function runDir(): string
    {
        return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'run';
    }

    private function logDir(): string
    {
        return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'logs';
    }

    private function stateDir(): string
    {
        return $this->home . DIRECTORY_SEPARATOR . 'state';
    }

    private function configDir(): string
    {
        return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'conf';
    }

    private function lkgDir(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'lkg';
    }

    private function configFile(): string
    {
        return $this->configDir() . DIRECTORY_SEPARATOR . 'nginx.conf';
    }

    private function nginxPidFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'nginx.pid';
    }

    private function controllerPidFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'controller.pid';
    }

    private function tokenFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'control.token';
    }

    private function endpointFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'control-endpoint.json';
    }

    private function socketFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'wls-edge-2.sock';
    }

    private function stateFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'gateway-state.json';
    }

    private function routeLkgFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'route-lkg.json';
    }

    private function journalFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'journal.jsonl';
    }

    private function activeSlotFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'active-slot';
    }

    private function slotDir(string $slot): string
    {
        return $this->home . DIRECTORY_SEPARATOR . 'slots' . DIRECTORY_SEPARATOR . $slot;
    }

    private function nginxBinaryName(): string
    {
        return \PHP_OS_FAMILY === 'Windows' ? 'nginx.exe' : 'nginx';
    }

    private function nginxBinary(): string
    {
        return $this->slotDir((string)$this->state['active_slot'])
            . DIRECTORY_SEPARATOR . $this->nginxBinaryName();
    }

    private function mimeTypesFile(): string
    {
        $file = $this->slotDir((string)$this->state['active_slot']) . DIRECTORY_SEPARATOR . 'mime.types';
        if (!\is_file($file)) {
            $this->atomicWrite($file, "types {\n  text/html html htm;\n  text/css css;\n  application/javascript js;\n  application/json json;\n}\n", 0644);
        }
        return $file;
    }

    private function healthPort(): int
    {
        return (int)$this->state['health_port'];
    }
}

exit(WlsEdgeGatewayController::main($argv));
