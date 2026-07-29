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
    private const BACKEND_PROBE_RETRY_INTERVAL = 5;
    private const BACKEND_PROBE_FAST_RETRY_LIMIT = 3;
    private const BACKEND_PROBE_READ_TIMEOUT = 3;
    private const FAILURE_WINDOW = 300;
    private const CIRCUIT_WINDOW = 900;
    private const CIRCUIT_THRESHOLD = 10;
    private const MAX_REQUEST_BYTES = 4194304;
    private const MIN_MUTATION_FREE_BYTES = 16_777_216;
    private const RECOVERY_RESERVE_BYTES = 8_388_608;
    private const TEST_RECOVERY_RESERVE_BYTES = 65_536;
    private const MAX_JOURNAL_BYTES = 67_108_864;
    private const MAX_SNAPSHOT_BYTES = 536_870_912;
    private const MAX_PUBLICATION_QUEUE = 1024;
    private const PUBLICATION_DEBOUNCE_SECONDS = 0.25;
    private const PUBLICATION_MIN_INTERVAL_SECONDS = 0.5;
    private const OPERATION_RETENTION_SECONDS = 86400;
    private const CLOCK_RECOVERY_STABLE_SECONDS = 30.0;
    private const SERVICE_TREE_RESTART_EXIT = 79;

    /** @var array<string,mixed> */
    private array $state = [];
    /**
     * @var array<string,array{seen_at:int,seen_monotonic:float,boot_id:string}>
     */
    private array $nonces = [];
    /** @var array<string,array{tokens:float,at:float}> */
    private array $rateWindows = [];
    /** @var array<string,mixed>|null */
    private ?array $publication = null;
    private bool $running = true;
    private bool $configDirty = false;
    private bool $deferPublication = false;
    private string $requestOperation = '';
    private string $requestPrincipal = '';
    private string $lastQueuedOperationId = '';
    private float $publicationDueAt = 0.0;
    private float $lastPublicationAt = 0.0;
    private string $lastShadowVerificationError = '';
    /** @var array<string,mixed>|null */
    private ?array $requestStateBeforeMutation = null;
    /** @var array<string,mixed>|null */
    private ?array $requestPublicationBeforeMutation = null;
    private bool $requestConfigDirtyBeforeMutation = false;
    private float $lastHealthAt = 0.0;
    private float $lastBackendProbeAt = 0.0;
    private int $clockWallAnchor = 0;
    private float $clockMonotonicAnchor = 0.0;
    private float $clockStableSinceMonotonic = 0.0;
    private string $hostBootId = '';
    private int $journalSequence = 0;
    private string $journalHead = '';
    private bool $journalTrusted = true;
    private bool $securityLedgerBootstrapRequired = false;
    /** @var resource|null */
    private $controlServer = null;
    /** @var resource|null */
    private $brokerExchange = null;
    /** @var array<string,mixed>|null */
    private ?array $activeBrokerPeer = null;
    /** @var array<string,resource> */
    private array $windowsNginxProcesses = [];

    public function __construct(
        private readonly string $home,
        private readonly ?string $brokerInternalEndpoint = null,
        private readonly ?string $brokerFencingPath = null,
        private readonly ?int $brokerAdoptedNginxPid = null,
    ) {
        $this->ensureDirectories();
        $diskPressureRecovery = $this->diskPressureMarkerActive();
        $this->hostBootId = $this->detectHostBootId();
        $this->state = $this->loadState(!$diskPressureRecovery);
        $bootstrapSecurityLedger = ($this->state['_security_ledger_bootstrap'] ?? false) === true;
        $this->securityLedgerBootstrapRequired = $bootstrapSecurityLedger;
        $persistRecoveredState = ($this->state['_state_rebuild_required'] ?? false) === true;
        unset(
            $this->state['_security_ledger_bootstrap'],
            $this->state['_state_rebuild_required'],
        );
        if (!$diskPressureRecovery && $bootstrapSecurityLedger) {
            $this->persistSecurityLedger();
            $this->securityLedgerBootstrapRequired = false;
        }
        if (!$diskPressureRecovery
            && ($bootstrapSecurityLedger || $persistRecoveredState)
        ) {
            $this->persistState();
        }
        $this->initializeJournalChain(!$diskPressureRecovery);
        if ($diskPressureRecovery) {
            $this->markDiskPressure(
                $this->journalTrusted
                    ? 'DISK_PRESSURE'
                    : 'DISK_PRESSURE_JOURNAL_UNTRUSTED',
                $this->journalTrusted
                    ? 'startup_recovery_suspended'
                    : 'startup_recovery_suspended_with_untrusted_journal',
            );
        } else {
            $this->reconcileActiveRuntimeSlot();
            $this->reconcileInterruptedPublication();
        }
        $persistedNonces = $this->state['security']['nonces'] ?? [];
        $this->nonces = $this->normalizePersistedNonces($persistedNonces);
        $this->pruneNonces();
        $this->clockWallAnchor = \time();
        $this->clockMonotonicAnchor = \hrtime(true) / 1_000_000_000;
    }

    public static function main(array $argv): int
    {
        if (\in_array('--self-test', $argv, true)) {
            $requiredFunctions = [
                'disk_free_space',
                'exec',
                'hrtime',
                'openssl_x509_read',
                'posix_geteuid',
                'posix_kill',
                'proc_open',
                'sodium_memzero',
                'stream_socket_server',
            ];
            $missingFunctions = \array_values(\array_filter(
                $requiredFunctions,
                static fn (string $function): bool => !\function_exists($function),
            ));
            $ok = $missingFunctions === [] && \PHP_INT_SIZE >= 8;
            echo \json_encode([
                'ok' => $ok,
                'protocol' => self::PROTOCOL,
                'implementation_level' => 'wls-2.0',
                'security_profile' => 'native-broker-v1',
                'broker_internal_transport' => true,
                'php' => \PHP_VERSION,
                'openssl' => \defined('OPENSSL_VERSION_TEXT') ? \OPENSSL_VERSION_TEXT : '',
                'missing_runtime_functions' => $missingFunctions,
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return $ok ? 0 : 1;
        }

        $home = '';
        $brokerInternal = null;
        $brokerFencing = null;
        $brokerAdoptedNginxPid = null;
        foreach ($argv as $arg) {
            if (\str_starts_with((string)$arg, '--home=')) {
                $home = \substr((string)$arg, 7);
            } elseif (\str_starts_with((string)$arg, '--broker-internal=')) {
                $brokerInternal = \substr((string)$arg, 18);
            } elseif (\str_starts_with((string)$arg, '--broker-fencing-file=')) {
                $brokerFencing = \substr((string)$arg, 22);
            } elseif (\str_starts_with((string)$arg, '--broker-adopted-nginx-pid=')) {
                $rawPid = \substr((string)$arg, 27);
                if (\preg_match('/\A[1-9][0-9]{0,9}\z/D', $rawPid) !== 1
                    || (int)$rawPid > 4294967295
                ) {
                    \fwrite(STDERR, "Invalid --broker-adopted-nginx-pid.\n");
                    return 2;
                }
                $brokerAdoptedNginxPid = (int)$rawPid;
            }
        }
        if ($home === '' || \str_contains($home, "\0")) {
            \fwrite(STDERR, "Missing --home for WLS Gateway Controller.\n");
            return 2;
        }
        $home = \rtrim($home, '/\\');
        $expectedRun = $home . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'run';
        if (\PHP_OS_FAMILY === 'Linux'
            && \hash_equals('/var/lib/weline-gateway', $home)
        ) {
            $expectedRun = '/run/weline-gateway';
        } elseif (\PHP_OS_FAMILY === 'Darwin'
            && \hash_equals('/Library/Application Support/WelineGateway', $home)
        ) {
            $expectedRun = '/var/run/weline-gateway';
        }
        $expectedUnixBroker = 'unix://' . $expectedRun
            . DIRECTORY_SEPARATOR . 'controller.sock';
        $expectedFencing = $expectedRun . DIRECTORY_SEPARATOR . 'fencing-token';
        $validUnixBroker = $brokerInternal !== null
            && \PHP_OS_FAMILY !== 'Windows'
            && \hash_equals($expectedUnixBroker, $brokerInternal);
        $validWindowsBroker = $brokerInternal !== null
            && \PHP_OS_FAMILY === 'Windows'
            && \preg_match('/\A(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/D', $home) === 1
            && \preg_match(
                '#\Atcp://127\.0\.0\.1:([1-9][0-9]{0,4})\z#D',
                $brokerInternal,
                $matches,
            ) === 1
            && (int)$matches[1] <= 65535
            && \is_string($brokerFencing)
            && $brokerFencing !== ''
            && !\str_contains($brokerFencing, "\0")
            && \strcasecmp(
                \str_replace('/', '\\', $expectedFencing),
                \str_replace('/', '\\', $brokerFencing),
            ) === 0;
        if ($brokerInternal !== null
            && ((!$validUnixBroker && !$validWindowsBroker)
                || \str_contains($brokerInternal, "\0"))
        ) {
            \fwrite(STDERR, "Invalid --broker-internal endpoint.\n");
            return 2;
        }
        if ($brokerAdoptedNginxPid !== null && !$validWindowsBroker) {
            \fwrite(STDERR, "Broker Nginx adoption requires the Windows native transport.\n");
            return 2;
        }

        try {
            return (new self(
                $home,
                $brokerInternal,
                $brokerFencing,
                $brokerAdoptedNginxPid,
            ))->run();
        } catch (\Throwable $throwable) {
            \fwrite(STDERR, '[wls-edge/2] fatal: ' . $throwable->getMessage() . "\n");
            return (int)$throwable->getCode() === self::SERVICE_TREE_RESTART_EXIT
                ? self::SERVICE_TREE_RESTART_EXIT
                : 1;
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
                $selected = @\stream_select($read, $write, $except, 0, 100000);
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
        $responseSecret = '';
        try {
            if (!\stream_set_blocking($client, true)) {
                throw new \RuntimeException(
                    'Unable to establish a blocking native Broker exchange.'
                );
            }
            \stream_set_timeout($client, 3);
            $brokerPeer = null;
            if ($this->brokerInternalEndpoint !== null) {
                $brokerLine = @\fgets($client, 8192);
                $brokerPeer = \is_string($brokerLine) ? \json_decode($brokerLine, true) : null;
                if (!\is_array($brokerPeer)) {
                    $this->writeResponse(
                        $client,
                        '',
                        false,
                        [],
                        'broker_identity_invalid',
                        'Native broker identity envelope is missing or invalid.',
                    );
                    return;
                }
            }
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
            if ($brokerPeer !== null) {
                $brokerError = $this->validateBrokerEnvelope($brokerPeer, \strlen($line));
                if ($brokerError !== '') {
                    $this->writeResponse(
                        $client,
                        (string)($request['request_id'] ?? ''),
                        false,
                        [],
                        'broker_identity_invalid',
                        $brokerError,
                    );
                    return;
                }
            }
            $requestId = (string)($request['request_id'] ?? '');
            if ($brokerPeer === null) {
                $this->writeResponse(
                    $client,
                    $requestId,
                    false,
                    [],
                    'broker_required',
                    'WLS Edge Protocol 2 requires the native platform broker.',
                );
                return;
            }
            $authentication = $this->authenticate($request, $brokerPeer);
            $responseSecret = (string)($authentication['secret'] ?? '');
            $authError = (string)($authentication['error'] ?? '');
            if ($authError !== '') {
                $this->writeResponse(
                    $client,
                    $requestId,
                    false,
                    [],
                    'unauthorized',
                    $authError,
                    $responseSecret,
                );
                return;
            }
            $operation = \strtolower(\trim((string)($request['operation'] ?? '')));
            $payload = \is_array($request['payload'] ?? null) ? $request['payload'] : [];
            $payload['_broker_peer'] = $brokerPeer;
            if ((string)$brokerPeer['channel'] === 'project') {
                $authenticatedProject = (string)($authentication['project_uuid'] ?? '');
                $payloadProject = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
                if ($authenticatedProject === ''
                    || !\hash_equals($authenticatedProject, $payloadProject)
                ) {
                    $this->writeResponse(
                        $client,
                        $requestId,
                        false,
                        [],
                        'forbidden',
                        'The project capability cannot act on another project identity.',
                        $responseSecret,
                    );
                    return;
                }
                $payload['_authenticated_project_uuid'] = $authenticatedProject;
            }
            try {
                $this->brokerExchange = $client;
                $this->activeBrokerPeer = $brokerPeer;
                $this->assertRateLimit(
                    (string)$brokerPeer['channel'],
                    (string)($authentication['project_uuid'] ?? 'admin'),
                    $operation,
                );
                $this->requestOperation = $operation;
                $this->requestPrincipal = (string)(
                    $authentication['project_uuid'] ?? 'admin'
                );
                $this->lastQueuedOperationId = '';
                $this->deferPublication = \in_array($operation, [
                    'register',
                    'renew',
                    'acme-challenge-sync',
                    'drain',
                    'unregister',
                ], true);
                $result = $this->dispatch($operation, $payload);
                if ($this->lastQueuedOperationId !== '') {
                    $operationState = $this->operationStatus(
                        $this->lastQueuedOperationId,
                        $this->requestPrincipal,
                        (string)$brokerPeer['channel'] === 'admin',
                    );
                    $result['operation_id'] = $this->lastQueuedOperationId;
                    $result['operation'] = $operationState;
                }
                $this->writeResponse($client, $requestId, true, $result, '', '', $responseSecret);
            } catch (\Throwable $throwable) {
                $code = $throwable instanceof \DomainException ? 'rejected' : 'operation_failed';
                $this->journal('request_rejected', [
                    'operation' => $operation,
                    'channel' => (string)$brokerPeer['channel'],
                    'uid' => $brokerPeer['uid'] ?? null,
                    'sid' => $brokerPeer['sid'] ?? null,
                    'reason' => $throwable->getMessage(),
                ]);
                $this->writeResponse(
                    $client,
                    $requestId,
                    false,
                    [],
                    $code,
                    $throwable->getMessage(),
                    $responseSecret,
                );
            }
        } finally {
            $this->deferPublication = false;
            $this->requestOperation = '';
            $this->requestPrincipal = '';
            $this->lastQueuedOperationId = '';
            $this->requestStateBeforeMutation = null;
            $this->requestPublicationBeforeMutation = null;
            $this->requestConfigDirtyBeforeMutation = false;
            $this->brokerExchange = null;
            $this->activeBrokerPeer = null;
            @\fclose($client);
        }
    }

    /**
     * @param array<string,mixed> $broker
     */
    private function validateBrokerEnvelope(array $broker, int $payloadLength): string
    {
        if ((int)($broker['broker_schema'] ?? 0) !== 1
            || !\in_array((string)($broker['channel'] ?? ''), ['admin', 'project'], true)
            || (int)($broker['payload_length'] ?? -1) !== $payloadLength
            || (isset($broker['action_protocol']) && (int)$broker['action_protocol'] !== 1)
        ) {
            return 'Native broker identity envelope fields are invalid.';
        }
        $hasPosixIdentity = (int)($broker['uid'] ?? -1) >= 0
            && (int)($broker['gid'] ?? -1) >= 0
            && (int)($broker['pid'] ?? -1) > 0;
        $hasWindowsIdentity = \preg_match(
            '/\AS-1-(?:[0-9]+-)+[0-9]+\z/D',
            (string)($broker['sid'] ?? ''),
        ) === 1
            && (int)($broker['pid'] ?? -1) > 0;
        if (!$hasPosixIdentity && !$hasWindowsIdentity) {
            return 'Native broker did not provide a verifiable OS peer identity.';
        }
        $token = \strtolower(\trim((string)($broker['fencing_token'] ?? '')));
        $fencingFile = $this->brokerFencingFile();
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
            || !\is_file($fencingFile)
            || \is_link($fencingFile)
        ) {
            return 'Native broker fencing token is unavailable.';
        }
        $active = \strtolower(\trim((string)@\file_get_contents($fencingFile)));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $active) !== 1
            || !\hash_equals($active, $token)
        ) {
            return 'Native broker fencing token does not own the active controller generation.';
        }
        return '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function dispatch(string $operation, array $payload): array
    {
        if ($this->publicationBlocksOperation($operation)) {
            throw new \DomainException(
                'Gateway publication is active; retry_after=1.'
            );
        }
        if (!\in_array(
            $operation,
            ['status', 'own-status', 'operation-status', 'routes', 'doctor', 'repair'],
            true,
        )) {
            $this->assertPersistentMutationAllowed($operation);
        }
        return match ($operation) {
            'status' => $this->status(),
            'own-status' => $this->ownStatus(
                (string)($payload['_authenticated_project_uuid'] ?? ''),
            ),
            'operation-status' => $this->operationStatus(
                (string)($payload['operation_id'] ?? ''),
                (string)($payload['_authenticated_project_uuid'] ?? 'admin'),
                (string)($payload['_broker_peer']['channel'] ?? '') === 'admin',
            ),
            'routes' => ['routes' => \array_values((array)($this->state['routes'] ?? []))],
            'doctor' => $this->doctor(),
            'register' => $this->register($payload, false),
            'renew' => $this->register($payload, true),
            'heartbeat' => $this->heartbeat($payload),
            'acme-challenge-sync' => $this->syncAcmeChallenges($payload),
            'transfer-stage' => $this->stageDomainTransfer($payload),
            'drain' => $this->drain($payload),
            'unregister' => $this->unregister($payload),
            'enroll' => $this->enroll($payload),
            'revoke' => $this->revoke($payload),
            'transfer' => $this->transferDomain($payload),
            'repair' => $this->repair($payload),
            'upgrade' => $this->upgradeSnapshot($payload),
            'stop' => $this->stopGateway($payload),
            default => throw new \DomainException('Unsupported wls-edge/2 operation: ' . $operation),
        };
    }

    private function publicationBlocksOperation(string $operation): bool
    {
        if ($this->publication === null
            || !\in_array(
                (string)($this->publication['phase'] ?? ''),
                ['PREPARED', 'SHADOW_VERIFIED', 'ACTIVATING'],
                true,
            )
        ) {
            return false;
        }

        // Publication verification deliberately keeps the authenticated
        // control plane responsive. Reads and fenced lease heartbeats cannot
        // change the candidate routing generation; every other mutation is
        // serialized behind the active publication transaction.
        return !\in_array(
            $operation,
            ['status', 'own-status', 'operation-status', 'routes', 'doctor', 'heartbeat'],
            true,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function status(): array
    {
        $nginx = $this->nginxStatus();
        $manifest = $this->slotManifestFromActiveFile();
        $recovery = (array)($this->state['recovery'] ?? []);
        $recovery['next_retry_in_seconds'] = \hash_equals(
            $this->hostBootId,
            (string)($recovery['circuit_boot_id'] ?? ''),
        ) ? \max(
            0,
            (int)\ceil(
                (float)($recovery['circuit_open_until_monotonic'] ?? 0.0)
                - $this->monotonicNow(),
            ),
        ) : 0;
        $brokerReady = $this->brokerInternalReady();
        $releaseReady = ($manifest['release_ready'] ?? false) === true
            && ($manifest['test_mode'] ?? true) === false
            && \hash_equals('wls-2.0', (string)($manifest['implementation_level'] ?? ''))
            && \hash_equals('native-broker-v1', (string)($manifest['security_profile'] ?? ''));
        $service = \json_decode((string)@\file_get_contents(
            $this->trustDir() . DIRECTORY_SEPARATOR . 'platform-service.json',
        ), true);
        $supervisorReady = \is_array($service)
            && ($service['test_mode'] ?? true) === false
            && \in_array(
                (string)($service['kind'] ?? ''),
                ['systemd-system', 'launchd-system', 'windows-service'],
                true,
            );
        $routeCounts = [];
        foreach ((array)($this->state['routes'] ?? []) as $route) {
            $status = (string)($route['status'] ?? 'UNKNOWN');
            $routeCounts[$status] = ($routeCounts[$status] ?? 0) + 1;
        }
        $operationCounts = [];
        foreach ((array)($this->state['operations'] ?? []) as $operation) {
            if (!\is_array($operation)) {
                continue;
            }
            $operationState = (string)($operation['state'] ?? 'UNKNOWN');
            $operationCounts[$operationState] = ($operationCounts[$operationState] ?? 0) + 1;
        }
        return [
            'ready' => (bool)($this->state['ready'] ?? false)
                && $brokerReady
                && $releaseReady
                && $supervisorReady
                && $this->journalTrusted
                && !($this->state['isolation_mode'] ?? false),
            'protocol' => self::PROTOCOL,
            'protocol_min' => 2,
            'protocol_max' => 2,
            'implementation_level' => (string)($manifest['implementation_level'] ?? 'checkpoint'),
            'security_profile' => (string)($manifest['security_profile'] ?? 'untrusted'),
            'release_ready' => $releaseReady,
            'broker_ready' => $brokerReady,
            'epoch' => (string)$this->state['epoch'],
            'generation' => (int)($this->state['active_config_generation'] ?? 0),
            'control_generation' => (int)$this->state['generation'],
            'state' => (string)$this->state['health_state'],
            'data_plane' => $nginx,
            'route_counts' => $routeCounts,
            'publication' => [
                'transaction_id' => (string)($this->publication['transaction_id'] ?? ''),
                'phase' => (string)($this->publication['phase'] ?? 'IDLE'),
                'desired_generation' => (int)($this->state['generation'] ?? 0),
                'candidate_generation' => (int)($this->publication['candidate_generation'] ?? 0),
                'active_generation' => (int)($this->state['active_config_generation'] ?? 0),
                'operation_counts' => $operationCounts,
            ],
            'public_http' => (int)$this->state['public_http'],
            'public_https' => (int)$this->state['public_https'],
            'h3_enabled' => (bool)($this->state['h3_enabled'] ?? false),
            'h3_reason' => (string)($this->state['h3_reason'] ?? ''),
            'active_slot' => (string)$this->state['active_slot'],
            'previous_slot' => (string)$this->state['previous_slot'],
            'runtime_generation' => (string)($manifest['runtime_generation'] ?? ''),
            'recovery' => $recovery,
            'journal' => [
                'trusted' => $this->journalTrusted,
                'sequence' => $this->journalSequence,
                'head_sha256' => $this->journalHead,
            ],
            'storage' => $this->storageStatus(),
            'supervisor_ready' => $supervisorReady,
            'test_mode' => (bool)($manifest['test_mode'] ?? false),
            'isolation_mode' => (bool)($this->state['isolation_mode'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ownStatus(string $projectUuid): array
    {
        if (!\preg_match('/\A[a-f0-9-]{36}\z/D', $projectUuid)
            || !\is_array($this->state['enrollments'][$projectUuid] ?? null)
        ) {
            throw new \DomainException('The project is not enrolled on this gateway.');
        }
        $status = $this->status();
        return [
            'ready' => (bool)$status['ready'],
            'protocol' => (string)$status['protocol'],
            'protocol_min' => (int)$status['protocol_min'],
            'protocol_max' => (int)$status['protocol_max'],
            'implementation_level' => (string)$status['implementation_level'],
            'security_profile' => (string)$status['security_profile'],
            'release_ready' => (bool)$status['release_ready'],
            'broker_ready' => (bool)$status['broker_ready'],
            'supervisor_ready' => (bool)$status['supervisor_ready'],
            'epoch' => (string)$status['epoch'],
            'generation' => (int)$status['generation'],
            'state' => (string)$status['state'],
            'data_plane' => [
                'running' => (bool)($status['data_plane']['running'] ?? false),
                'message' => (string)($status['data_plane']['message'] ?? ''),
            ],
            'public_http' => (int)$status['public_http'],
            'public_https' => (int)$status['public_https'],
            'project_uuid' => $projectUuid,
            'routes' => $this->projectRoutes($projectUuid),
            'instances' => $this->projectInstanceStatuses($projectUuid),
            'operations' => \array_values(\array_filter(
                (array)($this->state['operations'] ?? []),
                static fn (mixed $operation): bool => \is_array($operation)
                    && \hash_equals(
                        $projectUuid,
                        (string)($operation['project_uuid'] ?? ''),
                    ),
            )),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationStatus(
        string $operationId,
        string $principal,
        bool $administrator,
    ): array {
        $operationId = \strtolower(\trim($operationId));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $operationId) !== 1) {
            throw new \DomainException('Gateway operation ID is invalid.');
        }
        $operation = $this->state['operations'][$operationId] ?? null;
        if (!\is_array($operation)) {
            throw new \DomainException('Gateway operation was not found or has expired.');
        }
        if (!$administrator
            && !\hash_equals($principal, (string)($operation['project_uuid'] ?? ''))
        ) {
            throw new \DomainException('Gateway operation belongs to another project.');
        }
        return [
            'operation_id' => $operationId,
            'operation' => (string)($operation['operation'] ?? ''),
            'state' => (string)($operation['state'] ?? 'UNKNOWN'),
            'desired_generation' => (int)($operation['desired_generation'] ?? 0),
            'active_generation' => (int)($operation['active_generation'] ?? 0),
            'transaction_id' => (string)($operation['transaction_id'] ?? ''),
            'created_at' => (string)($operation['created_at'] ?? ''),
            'updated_at' => (string)($operation['updated_at'] ?? ''),
            'completed_at' => (string)($operation['completed_at'] ?? ''),
            'error' => (string)($operation['error'] ?? ''),
        ];
    }

    private function brokerInternalReady(): bool
    {
        if ($this->brokerInternalEndpoint === null) {
            return false;
        }
        $fencingFile = $this->brokerFencingFile();
        $token = \strtolower(\trim((string)@\file_get_contents($fencingFile)));
        return \is_file($fencingFile)
            && !\is_link($fencingFile)
            && \preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1;
    }

    private function brokerActionsRequired(): bool
    {
        return ($this->slotManifest()['test_mode'] ?? false) !== true;
    }

    private function brokerActionsAvailable(string $channel): bool
    {
        return \is_resource($this->brokerExchange)
            && \is_array($this->activeBrokerPeer)
            && (int)($this->activeBrokerPeer['action_protocol'] ?? 0) === 1
            && \hash_equals($channel, (string)($this->activeBrokerPeer['channel'] ?? ''));
    }

    /**
     * @param list<string> $fields
     */
    private function brokerAction(string $channel, array $fields): void
    {
        if (!$this->brokerActionsAvailable($channel)) {
            throw new \RuntimeException(
                'Native Broker action protocol is unavailable for this request.'
            );
        }
        foreach ($fields as $field) {
            if ($field === '' || \str_contains($field, "\0")
                || \str_contains($field, "\t") || \str_contains($field, "\n")
                || \str_contains($field, "\r")
            ) {
                throw new \DomainException('Native Broker action field is invalid.');
            }
        }
        $line = 'WLS-ACTION/1' . "\t" . \implode("\t", $fields) . "\n";
        $offset = 0;
        while ($offset < \strlen($line)) {
            $written = @\fwrite($this->brokerExchange, \substr($line, $offset));
            if (!\is_int($written) || $written < 1) {
                throw new \RuntimeException('Native Broker action request could not be written.');
            }
            $offset += $written;
        }
        $response = @\fgets($this->brokerExchange, 512);
        if (!\is_string($response)
            || !\hash_equals("WLS-ACTION/1\tOK\n", $response)
        ) {
            throw new \RuntimeException(
                'Native Broker rejected the restricted action: ' . \trim((string)$response)
            );
        }
        $this->state['broker_action_verified'] = true;
    }

    /**
     * @param array<string,string> $roots
     * @param array{kind:string,uid?:int,gid?:int,sid?:string} $owner
     */
    private function authorizeBrokerCertificateRoots(
        string $projectUuid,
        int $securityGeneration,
        string $projectRoot,
        array $roots,
        array $owner,
    ): void {
        if (!$this->brokerActionsAvailable('admin')) {
            if ($this->brokerActionsRequired()) {
                throw new \RuntimeException(
                    'Production enrollment requires the native Broker authorization action.'
                );
            }
            return;
        }
        $ownerKind = (string)($owner['kind'] ?? '');
        if ($ownerKind === 'posix' && isset($owner['uid'])) {
            $ownerIdentity = (string)(int)$owner['uid'];
        } elseif ($ownerKind === 'windows'
            && \preg_match(
                '/\AS-1-(?:[0-9]+-)+[0-9]+\z/Di',
                (string)($owner['sid'] ?? ''),
            ) === 1
        ) {
            $ownerIdentity = \strtoupper((string)$owner['sid']);
        } else {
            throw new \RuntimeException(
                'Native Broker certificate authorization requires a verified OS owner identity.'
            );
        }
        foreach ($roots as $alias => $root) {
            $this->brokerAction('admin', [
                'AUTH',
                $projectUuid,
                (string)$securityGeneration,
                $ownerIdentity,
                (string)$alias,
                \bin2hex($projectRoot),
                \bin2hex((string)$root),
            ]);
        }
    }

    /**
     * @param array{root_alias?:mixed,relative_path?:mixed} $reference
     */
    private function brokerSnapshotCertificateSource(
        string $projectUuid,
        int $securityGeneration,
        array $reference,
        string $snapshotDigest,
        string $leaf,
    ): string {
        if (!$this->brokerActionsAvailable('project')) {
            throw new \RuntimeException(
                'Production certificate snapshot requires the native Broker action protocol.'
            );
        }
        $alias = \strtolower(\trim((string)($reference['root_alias'] ?? '')));
        $relative = \trim((string)($reference['relative_path'] ?? ''));
        if (\preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $alias) !== 1
            || $relative === ''
        ) {
            throw new \DomainException('Certificate source reference is invalid.');
        }
        $this->brokerAction('project', [
            'SNAP',
            $projectUuid,
            (string)$securityGeneration,
            $alias,
            \bin2hex($relative),
            $snapshotDigest,
            $leaf,
        ]);
        $path = $this->home . DIRECTORY_SEPARATOR . 'snapshots'
            . DIRECTORY_SEPARATOR . $snapshotDigest . DIRECTORY_SEPARATOR . $leaf;
        if (!\is_file($path) || \is_link($path)) {
            throw new \RuntimeException('Native Broker did not publish the certificate source snapshot.');
        }
        return $path;
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
        $instanceGeneration = (int)($payload['instance_generation'] ?? 0);
        $instanceDigest = \strtolower(\trim((string)($payload['instance_digest'] ?? '')));
        $masterEpoch = (int)($payload['master_epoch'] ?? 0);
        $launchId = \strtolower(\trim((string)($payload['launch_id'] ?? '')));
        $gatewayEpoch = \trim((string)($payload['gateway_epoch'] ?? ''));
        if (!\preg_match('/\A[a-f0-9-]{36}\z/D', $projectUuid)
            || $instanceId === ''
            || $generation < 1
            || !\preg_match('/\A[a-f0-9]{64}\z/D', $digest)
            || $idempotencyKey === ''
            || $instanceGeneration < 1
            || !\preg_match('/\A[a-f0-9]{64}\z/D', $instanceDigest)
            || $masterEpoch < 1
            || !\preg_match('/\A[a-f0-9]{32}\z/D', $launchId)
        ) {
            throw new \DomainException('Registration identity or fencing fields are incomplete.');
        }
        if ($gatewayEpoch !== '' && !\hash_equals((string)$this->state['epoch'], $gatewayEpoch)) {
            throw new \DomainException('Gateway epoch changed; submit a full registration against epoch '
                . (string)$this->state['epoch'] . '.');
        }
        $peer = \is_array($payload['_broker_peer'] ?? null) ? $payload['_broker_peer'] : [];
        $this->assertEnrollment($projectUuid, $projectRoot, $peer);

        $projects = (array)($this->state['projects'] ?? []);
        $existing = \is_array($projects[$projectUuid] ?? null) ? $projects[$projectUuid] : [];
        $existingGeneration = (int)($existing['generation'] ?? 0);
        $existingDigest = (string)($existing['digest'] ?? '');
        if ($generation < $existingGeneration) {
            throw new \DomainException('Stale project generation rejected.');
        }
        if ($generation === $existingGeneration
            && $existingDigest !== ''
            && !\hash_equals($existingDigest, $digest)
        ) {
            throw new \DomainException('Same project generation has a different request digest.');
        }
        $projectChanged = $generation > $existingGeneration;

        $instances = (array)($this->state['instances'][$projectUuid] ?? []);
        $existingInstance = \is_array($instances[$instanceId] ?? null)
            ? $instances[$instanceId]
            : [];
        $existingInstanceGeneration = (int)($existingInstance['generation'] ?? 0);
        $existingInstanceDigest = (string)($existingInstance['digest'] ?? '');
        if ($instanceGeneration < $existingInstanceGeneration) {
            throw new \DomainException('Stale instance generation rejected.');
        }
        $sameGenerationInstanceDigestChanged = $instanceGeneration === $existingInstanceGeneration
            && $existingInstanceDigest !== ''
            && !\hash_equals($existingInstanceDigest, $instanceDigest);
        if ($sameGenerationInstanceDigestChanged
            && !$this->mayRefreshInstanceDigest(
                $existingInstance,
                $masterEpoch,
                $launchId,
            )
        ) {
            throw new \DomainException('Same instance generation has a different instance digest.');
        }
        $sameDesiredRegistration = $instanceGeneration === $existingInstanceGeneration
            && $existingInstanceDigest !== ''
            && \hash_equals($existingInstanceDigest, $instanceDigest)
            && $generation === $existingGeneration
            && $existingDigest !== ''
            && \hash_equals($existingDigest, $digest);
        if ($sameDesiredRegistration) {
            $pendingOperation = $this->pendingPublicationOperationForProject($projectUuid);
            if ($pendingOperation !== []) {
                $this->lastQueuedOperationId = (string)$pendingOperation['operation_id'];
                return [
                    'idempotent' => true,
                    'epoch' => (string)$this->state['epoch'],
                    'generation' => (int)$this->state['generation'],
                    'routes' => $this->projectRoutes($projectUuid),
                ];
            }
        }
        if ($sameDesiredRegistration
            && $this->registrationLeaseFullyActive($projectUuid, $instanceId)
        ) {
            $this->touchInstanceLease(
                $projectUuid,
                $instanceId,
                $masterEpoch,
                $launchId,
                $instanceGeneration,
            );
            return [
                'idempotent' => true,
                'epoch' => (string)$this->state['epoch'],
                'generation' => (int)$this->state['generation'],
                'routes' => $this->projectRoutes($projectUuid),
            ];
        }

        $routePayloads = \is_array($payload['routes'] ?? null) ? $payload['routes'] : [];
        if ($routePayloads === [] || \count($routePayloads) > 256) {
            throw new \DomainException('Registration must contain 1..256 routes.');
        }
        $otherRouteCount = \count(\array_filter(
            (array)($this->state['routes'] ?? []),
            static fn (mixed $route): bool => \is_array($route)
                && (string)($route['project_uuid'] ?? '') !== $projectUuid
                && (string)($route['status'] ?? '') !== 'REMOVED',
        ));
        if ($otherRouteCount + \count($routePayloads) > 2048) {
            throw new \DomainException('Gateway host route quota of 2048 would be exceeded.');
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
        $this->assertDomainsAuthorized($projectUuid, $candidateRoutes);
        $this->assertNoDomainConflicts($projectUuid, $candidateRoutes);
        if ($sameGenerationInstanceDigestChanged && !$projectChanged) {
            $this->assertCapabilityOnlyInstanceRefresh(
                $projectUuid,
                $instanceId,
                $existingInstance,
                $candidateRoutes,
            );
        }
        $this->beginRoutingMutation('register:' . $projectUuid . ':' . $instanceId);

        try {
            $incomingRouteIds = [];
            foreach ($candidateRoutes as $route) {
                $incomingRouteIds[(string)$route['route_id']] = true;
            }
            if ($projectChanged) {
                foreach ((array)($this->state['routes'] ?? []) as $routeId => $route) {
                    if (\is_array($route)
                        && (string)($route['project_uuid'] ?? '') === $projectUuid
                        && !isset($incomingRouteIds[(string)$routeId])
                    ) {
                        $this->state['routes'][$routeId]['status'] = 'REMOVED';
                        $this->state['routes'][$routeId]['removed_at'] = \time();
                    }
                }
            }
            $routingChanged = false;
            foreach ($candidateRoutes as $candidate) {
                $routeId = (string)$candidate['route_id'];
                $old = \is_array($this->state['routes'][$routeId] ?? null)
                    ? $this->state['routes'][$routeId]
                    : [];
                if ($old !== []
                    && !\hash_equals($projectUuid, (string)($old['project_uuid'] ?? ''))
                ) {
                    throw new \DomainException('Route ID is already owned by another project.');
                }
                if ($old !== []
                    && !$projectChanged
                    && (!\hash_equals((string)$old['domain'], (string)$candidate['domain'])
                        || !\hash_equals(
                            (string)($old['certificate']['source_digest'] ?? ''),
                            (string)($candidate['certificate']['source_digest'] ?? ''),
                        ))
                ) {
                    throw new \DomainException(
                        'Unchanged project generation attempted to modify route desired state.'
                    );
                }
                if ($old !== []
                    && \hash_equals(
                        (string)($old['certificate']['source_digest'] ?? ''),
                        (string)($candidate['certificate']['source_digest'] ?? ''),
                    )
                    && (int)($old['certificate']['generation'] ?? 0)
                        === (int)($candidate['certificate']['generation'] ?? 0)
                ) {
                    $candidate['certificate'] = $old['certificate'];
                }
                $routeInstances = \is_array($old['instances'] ?? null) ? $old['instances'] : [];
                $routeInstances[$instanceId] = [
                    'instance_id' => $instanceId,
                    'generation' => $instanceGeneration,
                    'digest' => $instanceDigest,
                    'master_epoch' => $masterEpoch,
                    'launch_id' => $launchId,
                    'backends' => $candidate['backends'],
                    'backend_identity' => $candidate['backend_identity'],
                    'backend_healthy' => (string)$candidate['status'] !== 'PENDING_BACKEND',
                    'status' => 'ACTIVE',
                    'last_heartbeat' => \time(),
                    'last_heartbeat_monotonic' => $this->monotonicNow(),
                    'lease_boot_id' => $this->hostBootId,
                    'drain_until' => null,
                    'drain_until_monotonic' => null,
                ];
                $candidate['instances'] = $routeInstances;
                $candidate['route_generation'] = (int)($old['route_generation'] ?? 0) + 1;
                $this->selectRouteBackends($candidate);
                $routingChanged = $routingChanged
                    || $this->routeRoutingDigest($old) !== $this->routeRoutingDigest($candidate);
                $this->state['routes'][$routeId] = $candidate;
            }
            $projects[$projectUuid] = [
                'project_uuid' => $projectUuid,
                'project_root' => $projectRoot,
                'generation' => $generation,
                'digest' => $digest,
                'idempotency_key' => $idempotencyKey,
                'route_ids' => \array_keys($incomingRouteIds),
                'registered_at' => \gmdate(DATE_ATOM),
            ];
            $this->state['projects'] = $projects;
            $instanceBackends = [];
            $instanceIdentity = [];
            foreach (\array_keys($incomingRouteIds) as $routeId) {
                $registeredRoute = $this->state['routes'][$routeId] ?? null;
                if (!\is_array($registeredRoute)) {
                    continue;
                }
                $instanceIdentity = $instanceIdentity !== []
                    ? $instanceIdentity
                    : (array)($registeredRoute['backend_identity'] ?? []);
                foreach ((array)($registeredRoute['instances'][$instanceId]['backends'] ?? []) as $backend) {
                    if (!\is_array($backend)) {
                        continue;
                    }
                    $key = (string)($backend['host'] ?? '') . ':'
                        . (int)($backend['port'] ?? 0);
                    $instanceBackends[$key] = $backend;
                }
            }
            $instances[$instanceId] = [
                'instance_id' => $instanceId,
                'generation' => $instanceGeneration,
                'digest' => $instanceDigest,
                'master_epoch' => $masterEpoch,
                'launch_id' => $launchId,
                'last_heartbeat' => \time(),
                'last_heartbeat_monotonic' => $this->monotonicNow(),
                'lease_boot_id' => $this->hostBootId,
                'registered_at' => \gmdate(DATE_ATOM),
                'backends' => \array_values($instanceBackends),
                'backend_identity' => $instanceIdentity,
                'status' => 'ACTIVE',
                'drain_until' => null,
                'drain_until_monotonic' => null,
            ];
            $this->state['instances'][$projectUuid] = $instances;
            $this->state['isolation_mode'] = false;
            $this->bumpGeneration('register', [
                'project_uuid' => $projectUuid,
                'instance_id' => $instanceId,
                'renew' => $renew,
                'project_changed' => $projectChanged,
            ]);
            $this->configDirty = $this->configDirty || $routingChanged || $projectChanged;
            if ($this->configDirty && !$this->publishIfDirty()) {
                throw new \DomainException(
                    'Gateway desired state was rejected; the previous active publication was retained.'
                );
            } else {
                $this->completePublication();
            }
            return [
                'idempotent' => false,
                'epoch' => (string)$this->state['epoch'],
                'generation' => (int)$this->state['generation'],
                'routes' => $this->projectRoutes($projectUuid),
            ];
        } catch (\Throwable $throwable) {
            $this->abortRoutingMutation(
                'Registration transaction aborted: ' . $throwable->getMessage()
            );
            throw $throwable;
        }
    }

    /**
     * Return the existing in-flight publication for an identical project
     * registration. A response can be lost after the desired state is
     * durably queued; retrying that same envelope must not increment route
     * generations or enqueue a second publication.
     *
     * @return array<string,mixed>
     */
    private function pendingPublicationOperationForProject(string $projectUuid): array
    {
        $operations = \array_reverse((array)($this->state['operations'] ?? []), true);
        foreach ($operations as $operationId => $operation) {
            if (!\is_array($operation)
                || !\in_array(
                    (string)($operation['state'] ?? ''),
                    ['PENDING_PUBLICATION', 'PREPARING', 'ACTIVATING'],
                    true,
                )
                || !\hash_equals(
                    $projectUuid,
                    (string)($operation['project_uuid'] ?? ''),
                )
                || !\in_array(
                    (string)($operation['operation'] ?? ''),
                    ['register', 'renew'],
                    true,
                )
            ) {
                continue;
            }
            $operation['operation_id'] = (string)($operation['operation_id'] ?? $operationId);
            if (\preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)$operation['operation_id'],
            ) === 1) {
                return $operation;
            }
        }
        return [];
    }

    /**
     * @param list<array<string,mixed>> $routes
     */
    private function assertDomainsAuthorized(string $projectUuid, array $routes): void
    {
        $enrollment = $this->state['enrollments'][$projectUuid] ?? null;
        $allowed = \is_array($enrollment)
            ? \array_values(\array_map('strval', (array)($enrollment['allowed_domains'] ?? [])))
            : [];
        foreach ($routes as $route) {
            $domain = (string)($route['domain'] ?? '');
            $authorized = false;
            foreach ($allowed as $pattern) {
                if (\hash_equals($pattern, $domain)) {
                    $authorized = true;
                    break;
                }
                if (\str_starts_with($pattern, '*.')
                    && !\str_starts_with($domain, '*.')
                    && \substr_count($pattern, '.') === \substr_count($domain, '.')
                    && \str_ends_with($domain, \substr($pattern, 1))
                ) {
                    $authorized = true;
                    break;
                }
            }
            if (!$authorized) {
                throw new \DomainException(
                    'Route domain is outside the project enrollment capability: ' . $domain
                );
            }
        }
    }

    private function registrationLeaseFullyActive(
        string $projectUuid,
        string $instanceId,
    ): bool {
        $instance = $this->state['instances'][$projectUuid][$instanceId] ?? null;
        $routeIds = (array)($this->state['projects'][$projectUuid]['route_ids'] ?? []);
        if (!\is_array($instance)
            || !\hash_equals('ACTIVE', (string)($instance['status'] ?? ''))
            || $routeIds === []
        ) {
            return false;
        }
        foreach ($routeIds as $routeId) {
            $route = $this->state['routes'][(string)$routeId] ?? null;
            $routeInstance = \is_array($route)
                ? ($route['instances'][$instanceId] ?? null)
                : null;
            if (!\is_array($route)
                || !\hash_equals('ACTIVE', (string)($route['status'] ?? ''))
                || !\is_array($routeInstance)
                || !\hash_equals('ACTIVE', (string)($routeInstance['status'] ?? ''))
                || ($routeInstance['backend_healthy'] ?? false) !== true
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Replace the authenticated project's complete HTTP-01 lease set.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function syncAcmeChallenges(array $payload): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $challengeGeneration = (int)($payload['challenge_generation'] ?? 0);
        if ($challengeGeneration < 1) {
            throw new \DomainException(
                'ACME HTTP-01 challenge generation must be positive.'
            );
        }
        $enrollment = $this->state['enrollments'][$projectUuid] ?? null;
        if (!\is_array($enrollment)
            || (($enrollment['capabilities']['acme_http_01'] ?? false) !== true)
        ) {
            throw new \DomainException(
                'The project enrollment does not grant the ACME HTTP-01 capability.'
            );
        }
        $incoming = \is_array($payload['challenges'] ?? null)
            ? $payload['challenges']
            : [];
        if (\count($incoming) > 32) {
            throw new \DomainException('A project may hold at most 32 ACME HTTP-01 leases.');
        }
        $now = \time();
        $leases = [];
        $desiredChallenges = [];
        foreach ($incoming as $challenge) {
            if (!\is_array($challenge)) {
                throw new \DomainException('ACME HTTP-01 lease must be an object.');
            }
            $domain = $this->normalizeDomain((string)($challenge['domain'] ?? ''));
            if (\str_starts_with($domain, '*.')) {
                throw new \DomainException('Wildcard domains cannot use ACME HTTP-01.');
            }
            $this->assertDomainsAuthorized($projectUuid, [['domain' => $domain]]);
            $routeOwned = false;
            foreach ((array)($this->state['routes'] ?? []) as $route) {
                if (\is_array($route)
                    && \hash_equals($projectUuid, (string)($route['project_uuid'] ?? ''))
                    && \hash_equals($domain, (string)($route['domain'] ?? ''))
                    && (string)($route['status'] ?? '') !== 'REMOVED'
                ) {
                    $routeOwned = true;
                    break;
                }
            }
            if (!$routeOwned) {
                throw new \DomainException(
                    'ACME HTTP-01 lease requires an existing project-owned route.'
                );
            }
            $token = \trim((string)($challenge['token'] ?? ''));
            $keyAuthorization = \trim((string)(
                $challenge['key_authorization'] ?? $challenge['keyAuth'] ?? ''
            ));
            $expiresAt = (int)($challenge['expires_at'] ?? 0);
            if (\preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token) !== 1
                || \preg_match(
                    '/\A[A-Za-z0-9_-]{1,256}\.[A-Za-z0-9_-]{20,256}\z/D',
                    $keyAuthorization,
                ) !== 1
                || !\str_starts_with($keyAuthorization, $token . '.')
                || $expiresAt < $now + 30
                || $expiresAt > $now + 900
            ) {
                throw new \DomainException('ACME HTTP-01 lease token, key authorization or expiry is invalid.');
            }
            $leaseId = \hash('sha256', $projectUuid . "\0" . $domain . "\0" . $token);
            if (isset($leases[$leaseId])) {
                throw new \DomainException('Duplicate ACME HTTP-01 lease is forbidden.');
            }
            $desiredChallenge = [
                'domain' => $domain,
                'token' => $token,
                'key_authorization' => $keyAuthorization,
                'expires_at' => $expiresAt,
            ];
            $desiredChallenges[] = $desiredChallenge;
            $leases[$leaseId] = [
                'lease_id' => $leaseId,
                'project_uuid' => $projectUuid,
                ...$desiredChallenge,
            ];
        }
        \ksort($leases, SORT_STRING);
        \usort(
            $desiredChallenges,
            static fn (array $left, array $right): int => [
                $left['domain'],
                $left['token'],
            ] <=> [
                $right['domain'],
                $right['token'],
            ],
        );
        $desiredDigest = \hash(
            'sha256',
            $this->canonicalJson($desiredChallenges),
        );
        $claimedDigest = \strtolower(\trim((string)(
            $payload['desired_digest'] ?? $desiredDigest
        )));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $claimedDigest) !== 1
            || !\hash_equals($desiredDigest, $claimedDigest)
        ) {
            throw new \DomainException('ACME HTTP-01 desired digest does not match its leases.');
        }
        $existingGeneration = \is_array(
            $this->state['acme_generations'][$projectUuid] ?? null,
        ) ? $this->state['acme_generations'][$projectUuid] : [];
        $currentGeneration = (int)($existingGeneration['generation'] ?? 0);
        $currentDigest = (string)($existingGeneration['digest'] ?? '');
        if ($challengeGeneration < $currentGeneration) {
            throw new \DomainException('ACME HTTP-01 challenge generation is stale.');
        }
        if ($challengeGeneration === $currentGeneration
            && $currentGeneration > 0
            && !\hash_equals($currentDigest, $desiredDigest)
        ) {
            throw new \DomainException(
                'ACME HTTP-01 challenge generation is ambiguous.'
            );
        }

        $this->beginRoutingMutation('acme-challenge-sync:' . $projectUuid);
        try {
            $current = (array)($this->state['acme_challenges'] ?? []);
            foreach ($current as $leaseId => $lease) {
                if (\is_array($lease)
                    && \hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                ) {
                    unset($current[$leaseId]);
                }
            }
            $current += $leases;
            \ksort($current, SORT_STRING);
            $before = $this->canonicalJson((array)($this->state['acme_challenges'] ?? []));
            $after = $this->canonicalJson($current);
            $this->state['acme_challenges'] = $current;
            $this->state['acme_generations'][$projectUuid] = [
                'generation' => $challengeGeneration,
                'digest' => $desiredDigest,
                'updated_at' => \gmdate(DATE_ATOM),
            ];
            if (!\hash_equals($before, $after)) {
                $this->configDirty = true;
                $this->bumpGeneration('acme_challenge_sync', [
                    'project_uuid' => $projectUuid,
                    'count' => \count($leases),
                ]);
                if (!$this->publishIfDirty()) {
                    throw new \DomainException(
                        'ACME challenge publication failed; the previous active config was retained.'
                    );
                }
            } else {
                // The Nginx bundle is unchanged, but the anti-replay fence is
                // durable protocol state and must survive Controller restart.
                $this->persistState();
                $this->completePublication();
            }
            return [
                'accepted' => true,
                'count' => \count($leases),
                'challenge_generation' => $challengeGeneration,
                'desired_digest' => $desiredDigest,
            ];
        } catch (\Throwable $throwable) {
            $this->abortRoutingMutation(
                'ACME challenge transaction aborted: ' . $throwable->getMessage()
            );
            throw $throwable;
        }
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
        if ($backends === [] || \count($backends) > 16) {
            throw new \DomainException('Route must contain 1..16 backends.');
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
        $backendHealthy = $this->probeBackends($normalizedBackends, $identity);

        $certificate = \is_array($route['certificate'] ?? null) ? $route['certificate'] : [];
        $snapshot = $this->snapshotCertificate($projectUuid, $projectRoot, $domain, $certificate);
        $enrollment = (array)($this->state['enrollments'][$projectUuid] ?? []);
        $status = $backendHealthy ? 'ACTIVE' : 'PENDING_BACKEND';
        if (!$snapshot['valid']) {
            $status = 'PENDING_CERTIFICATE';
        }
        $existing = $this->state['routes'][$routeId] ?? null;
        $routeGeneration = \is_array($existing)
            ? (int)($existing['route_generation'] ?? 0) + 1
            : 1;
        $domainOwnership = $this->state['security']['tombstones']['domain:' . $domain] ?? null;
        $domainSecurityGeneration = \is_array($domainOwnership)
            && \hash_equals(
                $projectUuid,
                (string)($domainOwnership['to_project_uuid'] ?? ''),
            )
                ? (int)($domainOwnership['generation'] ?? 0)
                : 0;
        return [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'enrollment_security_generation' => (int)($enrollment['security_generation'] ?? 0),
            'instance_id' => $instanceId,
            'domain' => $domain,
            'backends' => $normalizedBackends,
            'backend_identity' => $identity,
            'certificate' => $snapshot,
            'route_generation' => $routeGeneration,
            'domain_security_generation' => $domainSecurityGeneration,
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
        $endpointGateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $endpointLaunchId = \strtolower(\trim((string)(
            $endpointGateway['launch_id'] ?? $endpoint['launch_id'] ?? ''
        )));
        $endpointMasterEpoch = \max(
            (int)($endpoint['master_epoch'] ?? 0),
            (int)($endpoint['epoch'] ?? 0),
        );
        if ($endpointLaunchId === ''
            || !\hash_equals((string)($identity['launch_id'] ?? ''), $endpointLaunchId)
            || (int)($identity['master_epoch'] ?? 0) !== $endpointMasterEpoch
        ) {
            throw new \DomainException('Backend endpoint launch/epoch fencing identity does not match.');
        }
        $edgeSecret = \strtolower(\trim((string)($identity['edge_capability_secret'] ?? '')));
        $edgeDigest = \strtolower(\trim((string)($identity['edge_capability_digest'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $edgeSecret) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $edgeDigest) !== 1
            || !\hash_equals($edgeDigest, \hash('sha256', $edgeSecret))
        ) {
            throw new \DomainException('Backend edge capability is missing or invalid.');
        }
        $identityProjectUuid = \strtolower(\trim((string)($identity['project_uuid'] ?? '')));
        $identityGeneration = (int)($identity['generation'] ?? 0);
        if ($identityProjectUuid === ''
            || !\hash_equals(
                $identityProjectUuid,
                \strtolower(\trim((string)($endpointGateway['project_uuid'] ?? ''))),
            )
            || $identityGeneration < 1
            || $identityGeneration !== (int)($endpointGateway['instance_generation'] ?? 0)
        ) {
            throw new \DomainException(
                'Backend endpoint project/generation fencing identity does not match.'
            );
        }
        $this->validateBackendSessionCapability($identity, $endpoint);
        $endpointPort = (int)($endpoint['main_port'] ?? $endpoint['port'] ?? 0);
        $join = \is_array($endpointGateway['join_backend'] ?? null)
            ? $endpointGateway['join_backend']
            : [];
        $joinPort = 0;
        if ((string)($join['state'] ?? '') === 'ACTIVE'
            && (int)($join['master_pid'] ?? 0) === (int)($endpoint['master_pid'] ?? 0)
            && (int)($join['master_epoch'] ?? 0) === $endpointMasterEpoch
            && \hash_equals(
                $identityProjectUuid,
                (string)($join['project_uuid'] ?? ''),
            )
            && $identityGeneration === (int)($join['instance_generation'] ?? 0)
            && \hash_equals(
                $edgeDigest,
                (string)($join['edge_capability_digest'] ?? ''),
            )
            && $this->joinBackendHasLiveWorker($join)
        ) {
            $joinPort = (int)($join['port'] ?? 0);
        }
        $matchesPort = false;
        foreach ($backends as $backend) {
            $matchesPort = $matchesPort
                || $backend['port'] === $endpointPort
                || ($joinPort > 0 && $backend['port'] === $joinPort);
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
     * Runtime distribution evidence is accepted only when it is bound to the
     * exact project endpoint record. Legacy or unknown claims stay isolated.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $endpoint
     */
    private function validateBackendSessionCapability(array $identity, array $endpoint): void
    {
        $mode = \strtolower(\trim((string)($identity['session_capability'] ?? 'isolated')));
        if ($mode === '' || $mode === 'isolated') {
            return;
        }
        $evidence = \is_array($identity['session_capability_evidence'] ?? null)
            ? $identity['session_capability_evidence']
            : [];
        $evidenceDigest = \strtolower(\trim((string)(
            $identity['session_capability_evidence_digest'] ?? ''
        )));
        if ($evidence === []
            || \preg_match('/\A[a-f0-9]{64}\z/D', $evidenceDigest) !== 1
            || !\hash_equals(
                $evidenceDigest,
                \hash('sha256', $this->canonicalJson($evidence)),
            )
        ) {
            throw new \DomainException(
                'Backend session capability evidence is missing or invalid.'
            );
        }
        if ($mode === 'stateless') {
            $gateway = \is_array($endpoint['gateway'] ?? null)
                ? $endpoint['gateway']
                : [];
            $generation = (int)($identity['generation'] ?? 0);
            $validStateless = \hash_equals(
                    'wls-stateless-capability/1',
                    (string)($evidence['schema'] ?? ''),
                )
                && \hash_equals(
                    'project_endpoint',
                    (string)($evidence['runtime_source'] ?? ''),
                )
                && ($evidence['runtime_declared'] ?? false) === true
                && (int)($evidence['instance_generation'] ?? 0) === $generation
                && \hash_equals(
                    'declared_stateless_runtime',
                    (string)($evidence['reason'] ?? ''),
                )
                && \hash_equals(
                    'stateless',
                    (string)($gateway['backend_capability'] ?? ''),
                )
                && \hash_equals(
                    'runtime_config',
                    (string)($gateway['backend_capability_source'] ?? ''),
                )
                && (int)($gateway['backend_capability_generation'] ?? 0) === $generation;
            if (!$validStateless) {
                throw new \DomainException(
                    'Backend stateless capability evidence does not match the project runtime.'
                );
            }
            return;
        }
        if ($mode !== 'shared_session') {
            throw new \DomainException(
                'Backend session capability evidence is missing or invalid.'
            );
        }

        $sharedState = \is_array($endpoint['shared_state'] ?? null)
            ? $endpoint['shared_state']
            : [];
        $runtime = \is_array($sharedState['session'] ?? null)
            ? $sharedState['session']
            : [];
        $host = \strtolower(\trim((string)($runtime['host'] ?? '')));
        if ($host === 'localhost') {
            $host = '127.0.0.1';
        }
        $tokenFileName = \trim((string)($runtime['token_file_name'] ?? ''));
        $valid = \hash_equals(
                'wls-session-capability/1',
                (string)($evidence['schema'] ?? ''),
            )
            && \hash_equals('wls', (string)($evidence['storage'] ?? ''))
            && \hash_equals(
                'project_shared_state',
                (string)($evidence['runtime_source'] ?? ''),
            )
            && ($evidence['runtime_registered'] ?? false) === true
            && ($evidence['runtime_shared_service'] ?? false) === true
            && \hash_equals('healthy', (string)($evidence['probe'] ?? ''))
            && \hash_equals(
                'authenticated_session_runtime',
                (string)($evidence['reason'] ?? ''),
            )
            && \hash_equals('session_server', (string)($runtime['role'] ?? ''))
            && ($runtime['registered'] ?? false) === true
            && ($runtime['shared_service'] ?? false) === true
            && \in_array($host, ['127.0.0.1', '::1'], true)
            && \hash_equals($host, (string)($evidence['host'] ?? ''))
            && (int)($runtime['port'] ?? 0) > 0
            && (int)($runtime['port'] ?? 0) <= 65535
            && (int)($runtime['port'] ?? 0) === (int)($evidence['port'] ?? 0)
            && $tokenFileName !== ''
            && \hash_equals(
                \hash('sha256', $tokenFileName),
                (string)($evidence['token_scope_digest'] ?? ''),
            );
        if (!$valid) {
            throw new \DomainException(
                'Backend session capability evidence does not match the project runtime.'
            );
        }
    }

    /** @param array<string,mixed> $join */
    private function joinBackendHasLiveWorker(array $join): bool
    {
        foreach ((array)($join['workers'] ?? []) as $worker) {
            if (!\is_array($worker)
                || !\hash_equals('READY', \strtoupper((string)($worker['state'] ?? '')))
            ) {
                continue;
            }
            if ($this->pidRunning((int)($worker['pid'] ?? 0))) {
                return true;
            }
        }
        return $this->pidRunning((int)($join['worker_pid'] ?? 0));
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
        if (($certificate['pending'] ?? false) === true) {
            $sourceDigest = \strtolower(\trim((string)(
                $certificate['source_digest'] ?? ''
            )));
            if (\str_starts_with($domain, '*.')
                || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
                || (int)($certificate['generation'] ?? -1) !== 0
            ) {
                throw new \DomainException(
                    'Pending certificate routes require an exact domain and generation zero.'
                );
            }
            return [
                'valid' => false,
                'pending' => true,
                'source_digest' => $sourceDigest,
                'snapshot_digest' => '',
                'generation' => 0,
                'cert_path' => '',
                'key_path' => '',
                'chain_path' => '',
                'not_after' => 0,
            ];
        }
        $certReference = \is_array($certificate['cert'] ?? null)
            ? $certificate['cert']
            : [];
        $keyReference = \is_array($certificate['key'] ?? null)
            ? $certificate['key']
            : [];
        $chainReference = \is_array($certificate['chain'] ?? null)
            ? $certificate['chain']
            : null;
        $sourceDigest = \strtolower(\trim((string)($certificate['source_digest'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1) {
            throw new \DomainException('Certificate source digest is invalid.');
        }
        if ($this->snapshotStorageBytes() + (3 * 1024 * 1024) > self::MAX_SNAPSHOT_BYTES) {
            $this->collectSnapshots();
            if ($this->snapshotStorageBytes() + (3 * 1024 * 1024)
                > self::MAX_SNAPSHOT_BYTES
            ) {
                $this->markDiskPressure(
                    'SNAPSHOT_QUOTA',
                    'certificate_snapshot_quota_exhausted',
                );
                throw new \DomainException(
                    'Gateway certificate snapshot quota is exhausted; active and LKG snapshots were retained.'
                );
            }
        }
        $snapshotRoot = $this->home . DIRECTORY_SEPARATOR . 'snapshots';
        $stagingDigest = \hash('sha256', 'candidate:' . \random_bytes(32));
        $snapshotDir = $snapshotRoot . DIRECTORY_SEPARATOR . $stagingDigest;
        $brokerSnapshot = $this->brokerActionsAvailable('project');
        $stagingMode = $brokerSnapshot ? 0770 : 0700;
        if (!\is_dir($snapshotDir)
            && !@\mkdir($snapshotDir, $stagingMode, true)
            && !\is_dir($snapshotDir)
        ) {
            throw new \RuntimeException('Unable to create certificate snapshot directory.');
        }
        if (!@\chmod($snapshotDir, $stagingMode)) {
            throw new \RuntimeException(
                'Unable to seal certificate snapshot staging permissions.'
            );
        }
        try {
        $enrollment = \is_array($this->state['enrollments'][$projectUuid] ?? null)
            ? $this->state['enrollments'][$projectUuid]
            : [];
        $securityGeneration = (int)($enrollment['security_generation'] ?? 0);
        if ($brokerSnapshot) {
            if ($securityGeneration < 1) {
                throw new \DomainException('Certificate snapshot enrollment generation is unavailable.');
            }
            $certReal = $this->brokerSnapshotCertificateSource(
                $projectUuid,
                $securityGeneration,
                $certReference,
                $stagingDigest,
                'source-cert.pem',
            );
            $keyReal = $this->brokerSnapshotCertificateSource(
                $projectUuid,
                $securityGeneration,
                $keyReference,
                $stagingDigest,
                'source-key.pem',
            );
            $chainReal = $chainReference !== null
                ? $this->brokerSnapshotCertificateSource(
                    $projectUuid,
                    $securityGeneration,
                    $chainReference,
                    $stagingDigest,
                    'source-chain.pem',
                )
                : '';
        } elseif ($this->brokerActionsRequired()) {
            throw new \RuntimeException(
                'Production certificate snapshot requires a native Broker no-follow action.'
            );
        } else {
            $certReal = $this->resolveCertificateReference($projectUuid, $certReference);
            $keyReal = $this->resolveCertificateReference($projectUuid, $keyReference);
            $chainReal = $chainReference !== null
                ? $this->resolveCertificateReference($projectUuid, $chainReference)
                : '';
        }
        foreach ([$certReal, $keyReal, $chainReal] as $sourceFile) {
            if ($sourceFile === '') {
                continue;
            }
            $size = @\filesize($sourceFile);
            if (!\is_int($size) || $size < 1 || $size > 1024 * 1024) {
                throw new \DomainException('Certificate source must be between 1 byte and 1 MiB.');
            }
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $keyMode = @\fileperms($keyReal);
            if (!\is_int($keyMode) || ($keyMode & 0077) !== 0) {
                throw new \DomainException(
                    'Certificate private key must not grant group or other permissions.'
                );
            }
        }
        $beforeCert = $this->fileHash($certReal);
        $beforeKey = $this->fileHash($keyReal);
        $beforeChain = $chainReal !== '' ? $this->fileHash($chainReal) : '';
        if ($beforeCert === '' || $beforeKey === '' || ($chainReal !== '' && $beforeChain === '')) {
            throw new \DomainException('Certificate material cannot be hashed.');
        }
        $certPem = @\file_get_contents($certReal);
        $keyPem = @\file_get_contents($keyReal);
        $chainPem = $chainReal !== '' ? @\file_get_contents($chainReal) : '';
        if (!\is_string($certPem) || !\is_string($keyPem)) {
            throw new \DomainException('Certificate material cannot be read.');
        }
        if (!\is_string($chainPem)) {
            throw new \DomainException('Certificate chain cannot be read.');
        }
        if ($chainPem !== '') {
            $matches = [];
            if (\preg_match_all(
                '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
                $chainPem,
                $matches,
            ) < 1) {
                throw new \DomainException('Certificate chain does not contain a PEM certificate.');
            }
            foreach ((array)($matches[0] ?? []) as $chainCertificate) {
                if (@\openssl_x509_read((string)$chainCertificate) === false) {
                    throw new \DomainException('Certificate chain contains an invalid certificate.');
                }
            }
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
        $keyType = (int)($privateDetails['type'] ?? -1);
        $keyBits = (int)($privateDetails['bits'] ?? 0);
        if (($keyType === OPENSSL_KEYTYPE_RSA && $keyBits < 2048)
            || ($keyType === OPENSSL_KEYTYPE_EC && $keyBits < 256)
            || !\in_array($keyType, [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_EC], true)
        ) {
            throw new \DomainException('Certificate key algorithm or strength is not accepted.');
        }
        $parsed = @\openssl_x509_parse($x509, false);
        $now = \time();
        if (!\is_array($parsed)
            || (int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now
            || (int)($parsed['validTo_time_t'] ?? 0) <= \time()
            || !$this->certificateCoversDomain($parsed, $domain)
        ) {
            throw new \DomainException('Certificate SAN or validity does not cover route domain.');
        }
        $calculatedSourceDigest = \hash(
            'sha256',
            $beforeCert . ':' . $beforeKey . ':' . $beforeChain,
        );
        if (!\hash_equals($calculatedSourceDigest, $sourceDigest)) {
            throw new \DomainException('Certificate source digest does not match registered material.');
        }
        $snapshotDigest = $calculatedSourceDigest;
        $certCandidate = $snapshotDir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyCandidate = $snapshotDir . DIRECTORY_SEPARATOR . 'privkey.pem';
        $fullchain = \rtrim($certPem) . "\n";
        if ($chainPem !== '') {
            $fullchain .= \rtrim($chainPem) . "\n";
        }
        $this->atomicWrite($certCandidate, $fullchain, 0644);
        $this->atomicWrite($keyCandidate, $keyPem, 0600);
        if (!\hash_equals($beforeCert, $this->fileHash($certReal))
            || !\hash_equals($beforeKey, $this->fileHash($keyReal))
            || ($chainReal !== '' && !\hash_equals($beforeChain, $this->fileHash($chainReal)))
        ) {
            $this->removeSnapshotDirectory($snapshotDir);
            throw new \DomainException('Certificate source changed during snapshot; previous generation retained.');
        }
        foreach (['source-cert.pem', 'source-key.pem', 'source-chain.pem'] as $sourceLeaf) {
            @\unlink($snapshotDir . DIRECTORY_SEPARATOR . $sourceLeaf);
        }
        $manifestPayload = [
            'schema_version' => 1,
            'source_digest' => $snapshotDigest,
            'fullchain_sha256' => $this->fileHash($certCandidate),
            'private_key_sha256' => $this->fileHash($keyCandidate),
            'not_after' => (int)$parsed['validTo_time_t'],
            'created_at' => \gmdate(DATE_ATOM),
        ];
        $this->atomicWrite(
            $snapshotDir . DIRECTORY_SEPARATOR . 'manifest.json',
            (string)\json_encode([
                'payload' => $manifestPayload,
                'sha256' => \hash('sha256', $this->canonicalJson($manifestPayload)),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            0600,
        );
        if (!@\chmod($snapshotDir, 0700)) {
            throw new \RuntimeException(
                'Unable to seal certificate snapshot publication permissions.'
            );
        }
        $finalSnapshotDir = $snapshotRoot . DIRECTORY_SEPARATOR . $snapshotDigest;
        if (\is_dir($finalSnapshotDir)) {
            if (!$this->snapshotBundleValid($finalSnapshotDir, $snapshotDigest)) {
                $this->removeSnapshotDirectory($snapshotDir);
                throw new \RuntimeException(
                    'Existing content-addressed certificate snapshot is damaged; refusing to overwrite it.'
                );
            }
            $this->removeSnapshotDirectory($snapshotDir);
        } elseif (!@\rename($snapshotDir, $finalSnapshotDir)) {
            $this->removeSnapshotDirectory($snapshotDir);
            throw new \RuntimeException(
                'Unable to atomically publish the certificate snapshot bundle.'
            );
        }
        $certSnapshot = $finalSnapshotDir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keySnapshot = $finalSnapshotDir . DIRECTORY_SEPARATOR . 'privkey.pem';
        if (!$this->snapshotBundleValid($finalSnapshotDir, $snapshotDigest)) {
            throw new \RuntimeException(
                'Published certificate snapshot bundle failed verification.'
            );
        }
        return [
            'valid' => true,
            'source_digest' => $sourceDigest,
            'source_refs' => [
                'cert' => $certReference,
                'key' => $keyReference,
                'chain' => $chainReference,
            ],
            'generation' => \max(1, (int)($certificate['generation'] ?? 0)),
            'snapshot_digest' => $snapshotDigest,
            'cert_path' => $certSnapshot,
            'key_path' => $keySnapshot,
            'not_after' => (int)$parsed['validTo_time_t'],
        ];
        } finally {
            // The source validation and publication path has many intentional
            // rejection points. A rejected candidate must never accumulate
            // into the content-addressed snapshot quota.
            $this->removeSnapshotDirectory($snapshotDir);
        }
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
                try {
                    $names[] = $this->normalizeDomain(\substr($entry, 4));
                } catch (\Throwable) {
                }
            }
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
        $projectGeneration = (int)($payload['project_generation'] ?? 0);
        $instanceId = \trim((string)($payload['instance_id'] ?? ''));
        $instanceGeneration = (int)($payload['instance_generation'] ?? 0);
        $instanceDigest = \strtolower(\trim((string)(
            $payload['instance_digest'] ?? ''
        )));
        $masterEpoch = (int)($payload['master_epoch'] ?? 0);
        $launchId = \strtolower(\trim((string)($payload['launch_id'] ?? '')));
        $project = $this->state['projects'][$projectUuid] ?? null;
        if (!\is_array($project)
            || (int)($project['generation'] ?? 0) !== $projectGeneration
            || $instanceId === ''
        ) {
            throw new \DomainException('Heartbeat project generation is stale or unknown.');
        }
        if ($instanceDigest !== ''
            && \preg_match('/\A[a-f0-9]{64}\z/D', $instanceDigest) !== 1
        ) {
            throw new \DomainException('Heartbeat instance digest is invalid.');
        }
        $registeredInstance = $this->state['instances'][$projectUuid][$instanceId] ?? null;
        if (!\is_array($registeredInstance)
            || (int)($registeredInstance['generation'] ?? 0) !== $instanceGeneration
            || (int)($registeredInstance['master_epoch'] ?? 0) !== $masterEpoch
            || !\hash_equals(
                (string)($registeredInstance['launch_id'] ?? ''),
                $launchId,
            )
        ) {
            throw new \DomainException('Instance lease fencing identity is stale or unknown.');
        }
        if ($instanceDigest !== ''
            && (string)($registeredInstance['digest'] ?? '') !== ''
            && !\hash_equals(
                (string)$registeredInstance['digest'],
                $instanceDigest,
            )
        ) {
            // Heartbeat remains state-only. Do not extend a lease whose full
            // runtime identity changed; ask the Agent to replay register.
            return [
                'epoch' => (string)$this->state['epoch'],
                'generation' => (int)$this->state['generation'],
                'accepted' => true,
                're_register_required' => true,
            ];
        }
        $this->touchInstanceLease(
            $projectUuid,
            $instanceId,
            $masterEpoch,
            $launchId,
            $instanceGeneration,
            $this->normalizeDrainCounters($payload['drain_counters'] ?? null),
        );
        $matchedRoute = false;
        $reRegisterRequired = false;
        foreach ((array)$this->state['routes'] as $route) {
            if (!\is_array($route)
                || !\hash_equals(
                    $projectUuid,
                    (string)($route['project_uuid'] ?? ''),
                )
                || !\is_array($route['instances'][$instanceId] ?? null)
            ) {
                continue;
            }
            $matchedRoute = true;
            $routeStatus = (string)($route['status'] ?? '');
            $instanceStatus = (string)(
                $route['instances'][$instanceId]['status'] ?? ''
            );
            if (\in_array(
                $routeStatus,
                ['STALE', 'PENDING_BACKEND', 'PENDING_CERTIFICATE', 'REMOVED'],
                true,
            ) || $instanceStatus === 'STALE') {
                $reRegisterRequired = true;
                break;
            }
        }
        return [
            'epoch' => (string)$this->state['epoch'],
            'generation' => (int)$this->state['generation'],
            'accepted' => true,
            're_register_required' => !$matchedRoute || $reRegisterRequired,
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
        $seconds = \max(1, \min(
            self::DRAIN_SECONDS,
            (int)($payload['seconds'] ?? self::DRAIN_SECONDS),
        ));
        $instance = $this->state['instances'][$projectUuid][$instanceId] ?? null;
        if (!\is_array($instance)) {
            return ['accepted' => true, 'idempotent' => true, 'already_removed' => true];
        }
        $this->assertInstancePayloadFence($instance, $payload);
        $this->beginRoutingMutation('drain:' . $projectUuid . ':' . $instanceId);
        try {
            $deadline = \time() + $seconds;
            $monotonicDeadline = $this->monotonicNow() + $seconds;
            $this->state['instances'][$projectUuid][$instanceId]['status'] = 'DRAINING';
            $this->state['instances'][$projectUuid][$instanceId]['drain_until'] = $deadline;
            $this->state['instances'][$projectUuid][$instanceId]['drain_until_monotonic']
                = $monotonicDeadline;
            $routingChanged = false;
            foreach ((array)$this->state['routes'] as $routeId => $route) {
                if (!\is_array($route)
                    || (string)$route['project_uuid'] !== $projectUuid
                    || !\is_array($route['instances'][$instanceId] ?? null)
                ) {
                    continue;
                }
                $before = $this->routeRoutingDigest($route);
                $this->state['routes'][$routeId]['instances'][$instanceId]['status'] = 'DRAINING';
                $this->state['routes'][$routeId]['instances'][$instanceId]['drain_until'] = $deadline;
                $this->state['routes'][$routeId]['instances'][$instanceId]['drain_until_monotonic']
                    = $monotonicDeadline;
                $this->selectRouteBackends($this->state['routes'][$routeId]);
                $routingChanged = $routingChanged
                    || $before !== $this->routeRoutingDigest($this->state['routes'][$routeId]);
            }
            $this->bumpGeneration('drain', [
                'project_uuid' => $projectUuid,
                'instance_id' => $instanceId,
                'deadline' => $deadline,
            ]);
            $this->configDirty = $this->configDirty || $routingChanged;
            if ($this->configDirty && !$this->publishIfDirty()) {
                throw new \DomainException(
                    'Drain publication failed; the previous active routing state was retained.'
                );
            } else {
                $this->completePublication();
            }
            return ['accepted' => true, 'drain_seconds' => $seconds, 'drain_until' => $deadline];
        } catch (\Throwable $throwable) {
            $this->abortRoutingMutation(
                'Drain transaction aborted: ' . $throwable->getMessage()
            );
            throw $throwable;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function unregister(array $payload): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $instanceId = \trim((string)($payload['instance_id'] ?? ''));
        if (!\is_array($this->state['instances'][$projectUuid][$instanceId] ?? null)) {
            return ['accepted' => true, 'idempotent' => true, 'already_removed' => true];
        }
        $this->assertInstancePayloadFence(
            $this->state['instances'][$projectUuid][$instanceId],
            $payload,
        );
        $this->beginRoutingMutation('unregister:' . $projectUuid . ':' . $instanceId);
        try {
            unset($this->state['instances'][$projectUuid][$instanceId]);
            $routingChanged = false;
            foreach ((array)$this->state['routes'] as $routeId => $route) {
                if (\is_array($route)
                    && (string)$route['project_uuid'] === $projectUuid
                    && \is_array($route['instances'][$instanceId] ?? null)
                ) {
                    $before = $this->routeRoutingDigest($route);
                    unset($this->state['routes'][$routeId]['instances'][$instanceId]);
                    $this->selectRouteBackends($this->state['routes'][$routeId]);
                    $routingChanged = $routingChanged
                        || $before !== $this->routeRoutingDigest($this->state['routes'][$routeId]);
                }
            }
            if (($this->state['instances'][$projectUuid] ?? []) === []) {
                unset($this->state['instances'][$projectUuid]);
            }
            $this->bumpGeneration('unregister', [
                'project_uuid' => $projectUuid,
                'instance_id' => $instanceId,
            ]);
            $this->configDirty = $this->configDirty || $routingChanged;
            if ($this->configDirty && !$this->publishIfDirty()) {
                throw new \DomainException(
                    'Unregister publication failed; the previous active routing state was retained.'
                );
            } else {
                $this->completePublication();
            }
            return ['accepted' => true];
        } catch (\Throwable $throwable) {
            $this->abortRoutingMutation(
                'Unregister transaction aborted: ' . $throwable->getMessage()
            );
            throw $throwable;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function enroll(array $payload): array
    {
        if (($this->state['security_ledger_valid'] ?? true) !== true) {
            throw new \DomainException(
                'The host security ledger is untrusted; explicitly reset it before enrolling projects.'
            );
        }
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $projectRoot = $this->canonicalDirectory((string)($payload['project_root'] ?? ''));
        if (!\preg_match('/\A[a-f0-9-]{36}\z/D', $projectUuid)) {
            throw new \DomainException('Enrollment requires a valid project UUID.');
        }
        foreach ((array)($this->state['enrollments'] ?? []) as $enrolledUuid => $enrollment) {
            if (!\is_array($enrollment)) {
                continue;
            }
            if (\hash_equals((string)($enrollment['project_root'] ?? ''), $projectRoot)
                && !\hash_equals((string)$enrolledUuid, $projectUuid)
            ) {
                throw new \DomainException(
                    'This project root is already enrolled under another project UUID.'
                );
            }
        }
        $existing = $this->state['enrollments'][$projectUuid] ?? null;
        if (\is_array($existing)
            && !\hash_equals((string)($existing['project_root'] ?? ''), $projectRoot)
        ) {
            throw new \DomainException('The project UUID is already enrolled to another project root.');
        }
        if (!\is_array($existing)
            && \count((array)($this->state['enrollments'] ?? [])) >= 128
        ) {
            throw new \DomainException('Gateway enrollment quota of 128 projects is exhausted.');
        }
        $roots = [];
        foreach ((array)($payload['certificate_roots'] ?? []) as $alias => $root) {
            $alias = \strtolower(\trim((string)$alias));
            if (\preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $alias) !== 1) {
                throw new \DomainException(
                    'Enrollment certificate roots require explicit stable aliases.'
                );
            }
            $canonical = $this->canonicalDirectory((string)$root);
            if (!$this->pathInside($canonical, $projectRoot)) {
                throw new \DomainException('Enrollment certificate roots must stay inside the project root.');
            }
            $roots[$alias] = $canonical;
        }
        $defaultRoot = $this->canonicalDirectory($projectRoot . DIRECTORY_SEPARATOR
            . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl');
        if (!isset($roots['project_ssl'])
            || !\hash_equals($defaultRoot, (string)$roots['project_ssl'])
        ) {
            throw new \DomainException(
                'Enrollment must map project_ssl to the project app/etc/ssl directory.'
            );
        }
        $allowedDomains = [];
        foreach ((array)($payload['allowed_domains'] ?? []) as $domain) {
            $allowedDomains[] = $this->normalizeDomain((string)$domain);
        }
        $allowedDomains = \array_values(\array_unique($allowedDomains));
        if ($allowedDomains === [] || \count($allowedDomains) > 2048) {
            throw new \DomainException('Enrollment requires 1..2048 explicitly allowed domains.');
        }
        $owner = $this->projectRootOwner($projectRoot, $payload);
        $securityGeneration = (int)$this->state['generation'] + 1;
        $this->authorizeBrokerCertificateRoots(
            $projectUuid,
            $securityGeneration,
            $projectRoot,
            $roots,
            $owner,
        );
        $credentialId = \bin2hex(\random_bytes(16));
        $credentialSecret = \bin2hex(\random_bytes(32));
        $this->state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'certificate_roots' => $roots,
            'allowed_domains' => $allowedDomains,
            'owner' => $owner,
            'credential_id' => $credentialId,
            'credential_secret' => $credentialSecret,
            'credential_generation' => (int)($existing['credential_generation'] ?? 0) + 1,
            'security_generation' => $securityGeneration,
            'capabilities' => $this->normalizeEnrollmentCapabilities(
                (array)($payload['capabilities'] ?? []),
            ),
            'enrolled_at' => (string)($existing['enrolled_at'] ?? \gmdate(DATE_ATOM)),
            'updated_at' => \gmdate(DATE_ATOM),
        ];
        $this->persistSecurityLedger();
        $this->bumpGeneration('enroll', [
            'project_uuid' => $projectUuid,
            'credential_id' => $credentialId,
            'allowed_domains' => $allowedDomains,
        ]);
        return [
            'accepted' => true,
            'project_uuid' => $projectUuid,
            'security_generation' => $securityGeneration,
            'credential' => [
                'schema_version' => 1,
                'protocol' => self::PROTOCOL,
                'host_id' => $this->hostId(),
                'project_uuid' => $projectUuid,
                'credential_id' => $credentialId,
                'secret' => $credentialSecret,
                'issued_at' => \gmdate(DATE_ATOM),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{kind:string,uid?:int,gid?:int,sid?:string}
     */
    private function projectRootOwner(string $projectRoot, array $payload): array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $peer = \is_array($payload['_broker_peer'] ?? null) ? $payload['_broker_peer'] : [];
            $sid = \strtoupper(\trim((string)($peer['sid'] ?? '')));
            if (\preg_match('/\AS-1-(?:[0-9]+-)+[0-9]+\z/D', $sid) !== 1) {
                throw new \DomainException(
                    'Windows enrollment requires a broker-verified project owner SID.'
                );
            }
            return ['kind' => 'windows', 'sid' => $sid];
        }
        $status = @\lstat($projectRoot);
        if (\is_array($status) && !\is_link($projectRoot)) {
            return [
                'kind' => 'posix',
                'uid' => (int)$status['uid'],
                'gid' => (int)$status['gid'],
            ];
        }
        $uid = $payload['project_owner_uid'] ?? null;
        $gid = $payload['project_owner_gid'] ?? null;
        if ((! \is_int($uid) && !\is_string($uid))
            || (! \is_int($gid) && !\is_string($gid))
            || \preg_match('/\A[0-9]+\z/D', (string)$uid) !== 1
            || \preg_match('/\A[0-9]+\z/D', (string)$gid) !== 1
        ) {
            throw new \DomainException(
                'Enrollment requires a verifiable POSIX project owner.'
            );
        }
        return [
            'kind' => 'posix',
            'uid' => (int)$uid,
            'gid' => (int)$gid,
        ];
    }

    /**
     * @param array<string,mixed> $capabilities
     * @return array<string,bool>
     */
    private function normalizeEnrollmentCapabilities(array $capabilities): array
    {
        $allowed = ['stateless', 'shared_session', 'acme_http_01', 'acme_dns_01'];
        $normalized = [];
        foreach ($allowed as $capability) {
            $normalized[$capability] = ($capabilities[$capability] ?? false) === true;
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function revoke(array $payload): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $enrollment = $this->state['enrollments'][$projectUuid] ?? null;
        if (!\is_array($enrollment)) {
            return ['accepted' => true, 'idempotent' => true];
        }
        $this->beginRoutingMutation('revoke:' . $projectUuid);
        $securityLedgerCommitted = false;
        try {
            $tombstoneGeneration = (int)$this->state['generation'] + 1;
            $this->state['security']['tombstones']['project:' . $projectUuid] = [
                'kind' => 'project_revoke',
                'project_uuid' => $projectUuid,
                'credential_id' => (string)($enrollment['credential_id'] ?? ''),
                'generation' => $tombstoneGeneration,
                'created_at' => \gmdate(DATE_ATOM),
            ];
            foreach ((array)$this->state['routes'] as $routeId => $route) {
                if (\is_array($route)
                    && \hash_equals($projectUuid, (string)($route['project_uuid'] ?? ''))
                ) {
                    $this->enforceRemovedRoute(
                        $this->state['routes'][$routeId],
                        \time(),
                    );
                    $this->state['routes'][$routeId]['security_tombstone_generation']
                        = $tombstoneGeneration;
                }
            }
            unset($this->state['projects'][$projectUuid]);
            unset($this->state['instances'][$projectUuid]);
            unset($this->state['enrollments'][$projectUuid]);
            $this->markPublicationIrrevocableSecurity();
            $this->persistSecurityLedger();
            $securityLedgerCommitted = true;
            if ($this->brokerActionsAvailable('admin')) {
                try {
                    $this->brokerAction('admin', [
                        'REVOKE',
                        $projectUuid,
                        (string)$tombstoneGeneration,
                    ]);
                } catch (\Throwable $throwable) {
                    throw new \DomainException(
                        'Project access was revoked in the security ledger, but the native Broker '
                        . 'tombstone failed: ' . $throwable->getMessage()
                    );
                }
            } elseif ($this->brokerActionsRequired()) {
                throw new \DomainException(
                    'Project access was revoked in the security ledger, but the native Broker '
                    . 'action protocol is unavailable.'
                );
            }
            $this->bumpGeneration('revoke', [
                'project_uuid' => $projectUuid,
                'tombstone_generation' => $tombstoneGeneration,
            ]);
            $this->configDirty = true;
            if (!$this->publishIfDirty()) {
                // Revocation is a security decision and is never rolled back.
                // If a filtered config cannot be activated, fail closed instead
                // of allowing the revoked route to remain reachable through LKG.
                throw new \DomainException(
                    'Project access was revoked, but the filtered gateway config failed.'
                );
            }
            return [
                'accepted' => true,
                'tombstone_generation' => $tombstoneGeneration,
            ];
        } catch (\Throwable $throwable) {
            $reason = 'Revocation transaction failed closed: ' . $throwable->getMessage();
            try {
                $this->failClosedSecurityMutation(
                    $reason,
                    !$securityLedgerCommitted,
                );
            } catch (\Throwable $failClosedError) {
                $this->stopDataPlane();
                $this->completePublication();
                throw new \RuntimeException(
                    $reason . ' The fail-closed state could not be persisted: '
                    . $failClosedError->getMessage(),
                    0,
                    $throwable,
                );
            }
            throw new \DomainException(
                $reason . ' The data plane was stopped.',
                0,
                $throwable,
            );
        }
    }

    /**
     * Administrator side of the explicit three-step transfer transaction.
     *
     * The administrator fences the current owner, the target project proves
     * its live backend and certificate over its own channel, and the
     * administrator commits one routing generation.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function transferDomain(array $payload): array
    {
        $phase = \strtolower(\trim((string)($payload['phase'] ?? 'prepare')));
        return match ($phase) {
            'prepare' => $this->prepareDomainTransfer($payload),
            'commit' => $this->commitDomainTransfer($payload),
            'abort' => $this->abortDomainTransfer($payload),
            default => throw new \DomainException(
                'Domain transfer phase must be prepare, commit or abort.'
            ),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function prepareDomainTransfer(array $payload): array
    {
        if (($payload['confirm'] ?? false) !== true) {
            throw new \DomainException(
                'Domain transfer requires explicit administrator confirmation.'
            );
        }
        $domain = $this->normalizeDomain((string)($payload['domain'] ?? ''));
        $from = \strtolower(\trim((string)($payload['from_project_uuid'] ?? '')));
        $to = \strtolower(\trim((string)($payload['to_project_uuid'] ?? '')));
        if (!\preg_match('/\A[a-f0-9-]{36}\z/D', $from)
            || !\preg_match('/\A[a-f0-9-]{36}\z/D', $to)
            || \hash_equals($from, $to)
        ) {
            throw new \DomainException(
                'Domain transfer requires distinct valid source and target project UUIDs.'
            );
        }
        if (!\is_array($this->state['enrollments'][$from] ?? null)
            || !\is_array($this->state['enrollments'][$to] ?? null)
        ) {
            throw new \DomainException(
                'Both domain transfer projects must have active enrollments.'
            );
        }
        $this->assertDomainsAuthorized($to, [['domain' => $domain]]);
        $sourceRoute = null;
        foreach ((array)($this->state['routes'] ?? []) as $route) {
            if (!\is_array($route)
                || !\hash_equals($from, (string)($route['project_uuid'] ?? ''))
                || !\hash_equals($domain, (string)($route['domain'] ?? ''))
                || (string)($route['status'] ?? '') === 'REMOVED'
            ) {
                continue;
            }
            if ($sourceRoute !== null) {
                throw new \DomainException(
                    'Domain transfer source ownership is ambiguous.'
                );
            }
            $sourceRoute = $route;
        }
        if (!\is_array($sourceRoute) || !$this->routeAllowedBySecurity($sourceRoute)) {
            throw new \DomainException(
                'The source project does not own an active transferable route.'
            );
        }
        foreach ((array)($this->state['transfers'] ?? []) as $transfer) {
            if (\is_array($transfer)
                && \hash_equals($domain, (string)($transfer['domain'] ?? ''))
                && \in_array(
                    (string)($transfer['status'] ?? ''),
                    ['PREPARED', 'STAGED'],
                    true,
                )
                && $this->transferLeaseValid($transfer)
            ) {
                throw new \DomainException(
                    'Another live transfer already fences this domain.'
                );
            }
        }
        $transferId = \bin2hex(\random_bytes(16));
        $transfer = [
            'transfer_id' => $transferId,
            'domain' => $domain,
            'from_project_uuid' => $from,
            'to_project_uuid' => $to,
            'source_route_id' => (string)($sourceRoute['route_id'] ?? ''),
            'source_route_generation' => (int)($sourceRoute['route_generation'] ?? 0),
            'source_certificate_digest' => (string)(
                $sourceRoute['certificate']['snapshot_digest'] ?? ''
            ),
            'from_security_generation' => (int)(
                $this->state['enrollments'][$from]['security_generation'] ?? 0
            ),
            'to_security_generation' => (int)(
                $this->state['enrollments'][$to]['security_generation'] ?? 0
            ),
            'status' => 'PREPARED',
            'created_at' => \gmdate(DATE_ATOM),
            'expires_at' => \time() + 300,
            'expires_monotonic' => $this->monotonicNow() + 300.0,
            'lease_boot_id' => $this->hostBootId,
        ];
        $this->state['transfers'][$transferId] = $transfer;
        $this->persistState();
        $this->journal('domain_transfer_prepared', [
            'transfer_id' => $transferId,
            'domain' => $domain,
            'from_project_uuid' => $from,
            'to_project_uuid' => $to,
        ]);
        return [
            'accepted' => true,
            'transfer_id' => $transferId,
            'domain' => $domain,
            'expires_at' => (int)$transfer['expires_at'],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function stageDomainTransfer(array $payload): array
    {
        $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
        $transferId = \strtolower(\trim((string)($payload['transfer_id'] ?? '')));
        $registration = \is_array($payload['registration'] ?? null)
            ? $payload['registration']
            : [];
        $transfer = \is_array($this->state['transfers'][$transferId] ?? null)
            ? $this->state['transfers'][$transferId]
            : null;
        if (!\is_array($transfer)
            || !\preg_match('/\A[a-f0-9]{32}\z/D', $transferId)
            || !\hash_equals((string)($transfer['to_project_uuid'] ?? ''), $projectUuid)
            || !\in_array(
                (string)($transfer['status'] ?? ''),
                ['PREPARED', 'STAGED'],
                true,
            )
            || !$this->transferLeaseValid($transfer)
        ) {
            throw new \DomainException(
                'Domain transfer ticket is unknown, expired or belongs to another project.'
            );
        }
        $projectRoot = $this->canonicalDirectory(
            (string)($registration['project_root'] ?? '')
        );
        $instanceId = \trim((string)($registration['instance_id'] ?? ''));
        $projectGeneration = (int)($registration['project_generation'] ?? 0);
        $projectDigest = \strtolower(\trim((string)(
            $registration['request_digest'] ?? ''
        )));
        $idempotencyKey = \trim((string)($registration['idempotency_key'] ?? ''));
        $instanceGeneration = (int)($registration['instance_generation'] ?? 0);
        $instanceDigest = \strtolower(\trim((string)(
            $registration['instance_digest'] ?? ''
        )));
        $masterEpoch = (int)($registration['master_epoch'] ?? 0);
        $launchId = \strtolower(\trim((string)($registration['launch_id'] ?? '')));
        if (!\hash_equals(
            $projectUuid,
            \strtolower(\trim((string)($registration['project_uuid'] ?? ''))),
        )
            || $instanceId === ''
            || $projectGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $projectDigest) !== 1
            || $idempotencyKey === ''
            || $instanceGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $instanceDigest) !== 1
            || $masterEpoch < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
        ) {
            throw new \DomainException(
                'Transfer target registration identity is incomplete.'
            );
        }
        $gatewayEpoch = \trim((string)($registration['gateway_epoch'] ?? ''));
        if ($gatewayEpoch !== ''
            && !\hash_equals((string)$this->state['epoch'], $gatewayEpoch)
        ) {
            throw new \DomainException(
                'Gateway epoch changed during domain transfer staging.'
            );
        }
        $peer = \is_array($payload['_broker_peer'] ?? null)
            ? $payload['_broker_peer']
            : [];
        $this->assertEnrollment($projectUuid, $projectRoot, $peer);
        if ((int)($this->state['enrollments'][$projectUuid]['security_generation'] ?? 0)
            !== (int)($transfer['to_security_generation'] ?? -1)
        ) {
            throw new \DomainException(
                'Target enrollment changed after domain transfer preparation.'
            );
        }
        $existingProject = \is_array($this->state['projects'][$projectUuid] ?? null)
            ? $this->state['projects'][$projectUuid]
            : [];
        $existingProjectGeneration = (int)($existingProject['generation'] ?? 0);
        if ($projectGeneration < $existingProjectGeneration
            || ($projectGeneration === $existingProjectGeneration
                && (string)($existingProject['digest'] ?? '') !== ''
                && !\hash_equals((string)$existingProject['digest'], $projectDigest))
        ) {
            throw new \DomainException(
                'Transfer target project generation is stale or ambiguous.'
            );
        }
        $existingInstance = \is_array(
            $this->state['instances'][$projectUuid][$instanceId] ?? null
        ) ? $this->state['instances'][$projectUuid][$instanceId] : [];
        if ($instanceGeneration < (int)($existingInstance['generation'] ?? 0)
            || ($instanceGeneration === (int)($existingInstance['generation'] ?? 0)
                && (string)($existingInstance['digest'] ?? '') !== ''
                && !\hash_equals((string)$existingInstance['digest'], $instanceDigest)
                && !($projectGeneration > $existingProjectGeneration
                    && $this->mayRefreshInstanceDigest(
                        $existingInstance,
                        $masterEpoch,
                        $launchId,
                    )))
        ) {
            throw new \DomainException(
                'Transfer target instance generation is stale or ambiguous.'
            );
        }
        $matching = [];
        foreach ((array)($registration['routes'] ?? []) as $routePayload) {
            if (!\is_array($routePayload)) {
                continue;
            }
            if (\hash_equals(
                (string)$transfer['domain'],
                $this->normalizeDomain((string)($routePayload['domain'] ?? '')),
            )) {
                $matching[] = $routePayload;
            }
        }
        if (\count($matching) !== 1) {
            throw new \DomainException(
                'Transfer target registration must prove exactly one matching domain route.'
            );
        }
        $candidate = $this->validateRoute(
            $matching[0],
            $projectUuid,
            $projectRoot,
            $instanceId,
        );
        $this->assertDomainsAuthorized($projectUuid, [$candidate]);
        if ((string)$candidate['status'] !== 'ACTIVE'
            || !(bool)($candidate['certificate']['valid'] ?? false)
            || !$this->probeBackends(
                (array)$candidate['backends'],
                (array)$candidate['backend_identity'],
            )
        ) {
            throw new \DomainException(
                'Transfer target must prove a valid certificate and healthy authenticated backend.'
            );
        }
        $candidate['instances'] = [
            $instanceId => [
                'instance_id' => $instanceId,
                'generation' => $instanceGeneration,
                'digest' => $instanceDigest,
                'master_epoch' => $masterEpoch,
                'launch_id' => $launchId,
                'backends' => $candidate['backends'],
                'backend_identity' => $candidate['backend_identity'],
                'backend_healthy' => true,
                'status' => 'ACTIVE',
                'last_heartbeat' => \time(),
                'last_heartbeat_monotonic' => $this->monotonicNow(),
                'lease_boot_id' => $this->hostBootId,
                'drain_until' => null,
                'drain_until_monotonic' => null,
            ],
        ];
        $transfer['status'] = 'STAGED';
        $transfer['staged_at'] = \gmdate(DATE_ATOM);
        $transfer['candidate'] = $candidate;
        $transfer['target_project'] = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'generation' => $projectGeneration,
            'digest' => $projectDigest,
            'idempotency_key' => $idempotencyKey,
        ];
        $transfer['target_instance'] = [
            'instance_id' => $instanceId,
            'generation' => $instanceGeneration,
            'digest' => $instanceDigest,
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
            'last_heartbeat' => \time(),
            'last_heartbeat_monotonic' => $this->monotonicNow(),
            'lease_boot_id' => $this->hostBootId,
            'registered_at' => \gmdate(DATE_ATOM),
        ];
        $this->state['transfers'][$transferId] = $transfer;
        $this->persistState();
        $this->journal('domain_transfer_staged', [
            'transfer_id' => $transferId,
            'domain' => (string)$transfer['domain'],
            'to_project_uuid' => $projectUuid,
        ]);
        return [
            'accepted' => true,
            'transfer_id' => $transferId,
            'status' => 'STAGED',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function commitDomainTransfer(array $payload): array
    {
        if (($payload['confirm'] ?? false) !== true) {
            throw new \DomainException(
                'Domain transfer commit requires explicit administrator confirmation.'
            );
        }
        $transferId = \strtolower(\trim((string)($payload['transfer_id'] ?? '')));
        $transfer = \is_array($this->state['transfers'][$transferId] ?? null)
            ? $this->state['transfers'][$transferId]
            : null;
        if (\is_array($transfer)
            && (string)($transfer['status'] ?? '') === 'COMMITTED'
        ) {
            return [
                'accepted' => true,
                'idempotent' => true,
                'transfer_id' => $transferId,
                'generation' => (int)($transfer['committed_generation'] ?? 0),
            ];
        }
        if (!\is_array($transfer)
            || (string)($transfer['status'] ?? '') !== 'STAGED'
            || !$this->transferLeaseValid($transfer)
        ) {
            throw new \DomainException(
                'Domain transfer is not staged or its ticket expired.'
            );
        }
        $from = (string)$transfer['from_project_uuid'];
        $to = (string)$transfer['to_project_uuid'];
        $domain = (string)$transfer['domain'];
        $sourceRouteId = (string)$transfer['source_route_id'];
        $source = \is_array($this->state['routes'][$sourceRouteId] ?? null)
            ? $this->state['routes'][$sourceRouteId]
            : null;
        $candidate = \is_array($transfer['candidate'] ?? null)
            ? $transfer['candidate']
            : null;
        if (!\is_array($source)
            || !\is_array($candidate)
            || !\hash_equals($from, (string)($source['project_uuid'] ?? ''))
            || !\hash_equals($domain, (string)($source['domain'] ?? ''))
            || (int)($source['route_generation'] ?? 0)
                !== (int)($transfer['source_route_generation'] ?? -1)
            || (string)($source['status'] ?? '') === 'REMOVED'
            || (int)($this->state['enrollments'][$from]['security_generation'] ?? 0)
                !== (int)($transfer['from_security_generation'] ?? -1)
            || (int)($this->state['enrollments'][$to]['security_generation'] ?? 0)
                !== (int)($transfer['to_security_generation'] ?? -1)
        ) {
            throw new \DomainException(
                'Domain transfer ownership or enrollment fence changed before commit.'
            );
        }
        if (!$this->probeBackends(
            (array)($candidate['backends'] ?? []),
            (array)($candidate['backend_identity'] ?? []),
        ) || !$this->certificateSnapshotAvailable(
            (array)($candidate['certificate'] ?? [])
        )) {
            throw new \DomainException(
                'Domain transfer target proof is no longer healthy at commit.'
            );
        }

        $this->beginRoutingMutation('domain-transfer:' . $domain);
        $securityLedgerCommitted = false;
        try {
            $tombstoneGeneration = (int)$this->state['generation'] + 1;
            $this->applyCommittedDomainTransfer(
                $transferId,
                $transfer,
                $candidate,
                $tombstoneGeneration,
            );
            $this->markPublicationIrrevocableSecurity();
            $this->persistSecurityLedger();
            $securityLedgerCommitted = true;
            $this->bumpGeneration('domain_transfer_committed', [
                'transfer_id' => $transferId,
                'domain' => $domain,
                'from_project_uuid' => $from,
                'to_project_uuid' => $to,
                'tombstone_generation' => $tombstoneGeneration,
            ]);
            $this->configDirty = true;
            if (!$this->publishIfDirty()) {
                throw new \DomainException(
                    'Domain ownership changed, but the target gateway config failed.'
                );
            }
            return [
                'accepted' => true,
                'transfer_id' => $transferId,
                'domain' => $domain,
                'from_project_uuid' => $from,
                'to_project_uuid' => $to,
                'generation' => (int)$this->state['generation'],
            ];
        } catch (\Throwable $throwable) {
            $reason = 'Domain transfer failed closed: ' . $throwable->getMessage();
            try {
                $this->failClosedSecurityMutation(
                    $reason,
                    !$securityLedgerCommitted,
                );
            } catch (\Throwable $failClosedError) {
                $this->stopDataPlane();
                $this->completePublication();
                throw new \RuntimeException(
                    $reason . ' The fail-closed state could not be persisted: '
                        . $failClosedError->getMessage(),
                    0,
                    $throwable,
                );
            }
            throw new \DomainException(
                $reason . ' The data plane was stopped.',
                0,
                $throwable,
            );
        }
    }

    /**
     * Apply the already-proven transfer as one in-memory desired-state
     * generation. Persistence/publication is deliberately owned by the
     * caller so no observer can see an intermediate dual-owner state.
     *
     * @param array<string,mixed> $transfer
     * @param array<string,mixed> $candidate
     */
    private function applyCommittedDomainTransfer(
        string $transferId,
        array $transfer,
        array $candidate,
        int $tombstoneGeneration,
    ): void {
        $from = (string)$transfer['from_project_uuid'];
        $to = (string)$transfer['to_project_uuid'];
        $domain = (string)$transfer['domain'];
        $sourceRouteId = (string)$transfer['source_route_id'];
        $this->state['security']['tombstones']['domain:' . $domain] = [
            'kind' => 'domain_transfer',
            'domain' => $domain,
            'from_project_uuid' => $from,
            'to_project_uuid' => $to,
            'source_route_id' => $sourceRouteId,
            'generation' => $tombstoneGeneration,
            'created_at' => \gmdate(DATE_ATOM),
        ];
        $this->state['routes'][$sourceRouteId]['status'] = 'REMOVED';
        $this->state['routes'][$sourceRouteId]['removed_at'] = \time();
        $this->state['routes'][$sourceRouteId]['security_tombstone_generation']
            = $tombstoneGeneration;
        $candidate['domain_security_generation'] = $tombstoneGeneration;
        $candidate['route_generation'] = \max(
            1,
            (int)($candidate['route_generation'] ?? 0),
        );
        $candidate['updated_at'] = \gmdate(DATE_ATOM);
        $targetRouteId = (string)$candidate['route_id'];
        $this->state['routes'][$targetRouteId] = $candidate;

        $targetProject = (array)$transfer['target_project'];
        $existingTargetRouteIds = (array)(
            $this->state['projects'][$to]['route_ids'] ?? []
        );
        $targetProject['route_ids'] = \array_values(\array_unique([
            ...\array_map('strval', $existingTargetRouteIds),
            $targetRouteId,
        ]));
        $targetProject['registered_at'] = \gmdate(DATE_ATOM);
        $this->state['projects'][$to] = $targetProject;
        $targetInstance = (array)$transfer['target_instance'];
        $this->state['instances'][$to][(string)$targetInstance['instance_id']]
            = $targetInstance;
        if (\is_array($this->state['projects'][$from] ?? null)) {
            $sourceRouteIds = \array_map(
                'strval',
                (array)($this->state['projects'][$from]['route_ids'] ?? []),
            );
            $this->state['projects'][$from]['route_ids'] = \array_values(
                \array_filter(
                    $sourceRouteIds,
                    static fn (string $routeId): bool => $routeId !== $sourceRouteId,
                )
            );
        }
        foreach ((array)($this->state['acme_challenges'] ?? []) as $leaseId => $lease) {
            if (\is_array($lease)
                && \hash_equals($from, (string)($lease['project_uuid'] ?? ''))
                && \hash_equals($domain, (string)($lease['domain'] ?? ''))
            ) {
                unset($this->state['acme_challenges'][$leaseId]);
            }
        }
        $this->state['transfers'][$transferId]['status'] = 'COMMITTED';
        $this->state['transfers'][$transferId]['committed_generation']
            = $tombstoneGeneration;
        $this->state['transfers'][$transferId]['committed_at'] = \gmdate(DATE_ATOM);
        unset($this->state['transfers'][$transferId]['candidate']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function abortDomainTransfer(array $payload): array
    {
        $transferId = \strtolower(\trim((string)($payload['transfer_id'] ?? '')));
        $transfer = $this->state['transfers'][$transferId] ?? null;
        if (!\is_array($transfer)) {
            return ['accepted' => true, 'idempotent' => true];
        }
        if ((string)($transfer['status'] ?? '') === 'COMMITTED') {
            throw new \DomainException(
                'A committed domain transfer cannot be aborted.'
            );
        }
        unset($this->state['transfers'][$transferId]);
        $this->persistState();
        $this->journal('domain_transfer_aborted', ['transfer_id' => $transferId]);
        return ['accepted' => true, 'transfer_id' => $transferId];
    }

    /**
     * @param array<string,mixed> $transfer
     */
    private function transferLeaseValid(array $transfer): bool
    {
        return \hash_equals(
            $this->hostBootId,
            (string)($transfer['lease_boot_id'] ?? ''),
        ) && (float)($transfer['expires_monotonic'] ?? 0.0) > $this->monotonicNow();
    }

    /**
     * @param array<string,mixed> $certificate
     */
    private function certificateSnapshotAvailable(array $certificate): bool
    {
        $digest = \strtolower(\trim((string)(
            $certificate['snapshot_digest'] ?? ''
        )));
        $cert = (string)($certificate['cert_path'] ?? '');
        $key = (string)($certificate['key_path'] ?? '');
        $root = $this->home . DIRECTORY_SEPARATOR . 'snapshots'
            . DIRECTORY_SEPARATOR . $digest;
        return ($certificate['valid'] ?? false) === true
            && \preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1
            && $this->snapshotBundleValid($root, $digest)
            && \is_file($cert)
            && !\is_link($cert)
            && $this->pathInside($cert, $root)
            && \is_file($key)
            && !\is_link($key)
            && $this->pathInside($key, $root)
            && $this->fileHash($cert) !== ''
            && $this->fileHash($key) !== '';
    }

    private function snapshotBundleValid(string $directory, string $digest): bool
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\is_dir($directory)
            || \is_link($directory)
            || !$this->pathInside(
                $directory,
                $this->home . DIRECTORY_SEPARATOR . 'snapshots',
            )
        ) {
            return false;
        }
        $manifestFile = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        $cert = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $key = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        if (!\is_file($manifestFile)
            || \is_link($manifestFile)
            || !\is_file($cert)
            || \is_link($cert)
            || !\is_file($key)
            || \is_link($key)
        ) {
            return false;
        }
        $decoded = \json_decode((string)@\file_get_contents($manifestFile), true);
        $payload = \is_array($decoded['payload'] ?? null)
            ? $decoded['payload']
            : null;
        return \is_array($payload)
            && (int)($payload['schema_version'] ?? 0) === 1
            && \hash_equals($digest, (string)($payload['source_digest'] ?? ''))
            && \hash_equals(
                (string)($decoded['sha256'] ?? ''),
                \hash('sha256', $this->canonicalJson($payload)),
            )
            && \hash_equals(
                (string)($payload['fullchain_sha256'] ?? ''),
                $this->fileHash($cert),
            )
            && \hash_equals(
                (string)($payload['private_key_sha256'] ?? ''),
                $this->fileHash($key),
            );
    }

    private function removeSnapshotDirectory(string $directory): void
    {
        $root = $this->home . DIRECTORY_SEPARATOR . 'snapshots';
        if (!\is_dir($directory)
            || \is_link($directory)
            || !$this->pathInside($directory, $root)
        ) {
            return;
        }
        foreach (\scandir($directory) ?: [] as $leaf) {
            if ($leaf === '.' || $leaf === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $leaf;
            if (\is_file($path) && !\is_link($path)) {
                @\unlink($path);
            }
        }
        @\rmdir($directory);
    }

    /**
     * @return array<string,mixed>
     */
    private function repair(array $payload = []): array
    {
        $securityReset = ($payload['accept_security_reset'] ?? false) === true;
        if (($payload['accept_storage_recovery'] ?? false) === true) {
            $storage = $this->storageStatus();
            if ((int)($storage['free_bytes'] ?? -1)
                < self::MIN_MUTATION_FREE_BYTES
            ) {
                throw new \DomainException(
                    'Gateway storage is still below the safe mutation threshold.'
                );
            }
            $this->journalTrusted = true;
            $this->initializeJournalChain();
            if (!$this->journalTrusted) {
                throw new \DomainException(
                    'Gateway journal remains untrusted after storage recovery validation.'
                );
            }
            $marker = $this->diskPressureMarkerFile();
            $this->ensureRecoveryReserve();
            if ($this->securityLedgerBootstrapRequired && !$securityReset) {
                $this->persistSecurityLedger();
                $this->securityLedgerBootstrapRequired = false;
            }
            if ((\file_exists($marker) || \is_link($marker))
                && !@\unlink($marker)
            ) {
                throw new \DomainException(
                    'Gateway disk-pressure marker could not be cleared safely.'
                );
            }
            $this->reconcileActiveRuntimeSlot();
            $this->reconcileInterruptedPublication();
            $this->state['health_state'] = 'RECOVERING';
            $this->state['recovery']['stage'] = 'STORAGE_RECOVERED';
            $this->state['recovery']['last_failure'] = '';
        }
        if ($securityReset) {
            $this->state['enrollments'] = [];
            $this->state['security']['tombstones'] = [];
            $this->state['security_ledger_valid'] = true;
            $this->state['isolation_mode'] = true;
            $this->persistSecurityLedger();
            $this->securityLedgerBootstrapRequired = false;
            $this->journal('security_ledger_reset', [
                'reason' => 'administrator-confirmed-reset',
            ]);
        }
        if (($payload['accept_clock'] ?? false) === true) {
            unset($this->state['security']['clock_untrusted_since']);
            $this->clockWallAnchor = \time();
            $this->clockMonotonicAnchor = \hrtime(true) / 1_000_000_000;
        }
        if (($payload['retry_h3'] ?? false) === true) {
            $previousRuntime = (string)($this->state['h3_quarantined_runtime_generation'] ?? '');
            $this->state['h3_quarantined_runtime_generation'] = '';
            $this->state['h3_enabled'] = false;
            $this->state['h3_reason'] = 'Administrator requested a fresh H3 runtime probe.';
            $this->journal('h3_runtime_retry_requested', [
                'previous_runtime_generation' => $previousRuntime,
                'active_runtime_generation' => $this->activeRuntimeGeneration(),
            ]);
        }
        $this->state['recovery']['circuit_open_until'] = 0;
        $this->state['recovery']['circuit_open_until_monotonic'] = 0.0;
        $this->state['recovery']['circuit_boot_id'] = '';
        $this->state['recovery']['next_retry_at'] = 0;
        $this->state['recovery']['backoff_attempt'] = 0;
        $this->state['recovery']['stage'] = 'MANUAL_REPAIR';
        $this->beginRoutingMutation('manual-repair');
        try {
            $this->configDirty = true;
            $published = $this->publishIfDirty();
            if (!$published) {
                if ($securityReset) {
                    $this->stopDataPlane();
                } else {
                    $this->restartDataPlane('manual_repair');
                }
            }
            return $this->status();
        } catch (\Throwable $throwable) {
            $this->abortRoutingMutation(
                'Manual repair transaction aborted: ' . $throwable->getMessage()
            );
            if ($securityReset) {
                $this->stopDataPlane();
            }
            throw $throwable;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function upgradeSnapshot(array $payload = []): array
    {
        if (($payload['activate'] ?? false) === true) {
            throw new \DomainException(
                'A/B activation is a root-owned host transaction; use server:gateway:upgrade.'
            );
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
        if (($payload['confirm'] ?? false) !== true) {
            throw new \DomainException(
                'Gateway stop requires explicit administrator confirmation.'
            );
        }
        $active = \array_filter(
            (array)$this->state['routes'],
            static fn (mixed $route): bool => \is_array($route)
                && \in_array((string)($route['status'] ?? ''), ['ACTIVE', 'DRAINING', 'STALE'], true),
        );
        if ($active !== [] && !($payload['force'] ?? false)) {
            throw new \DomainException('Gateway has active routes; use explicit force after draining tenants.');
        }
        $this->writeAdminStoppedIntent();
        try {
            $result = $this->stopDataPlane();
            if (!($result['ok'] ?? false)) {
                throw new \RuntimeException(
                    (string)($result['message'] ?? 'Unable to stop gateway data plane.')
                );
            }
        } catch (\Throwable $throwable) {
            // The administrator's durable stop intent is authoritative even
            // when the data plane needs manual cleanup. Never silently clear
            // it and let the platform supervisor restart the gateway.
            throw $throwable;
        }
        $this->state['ready'] = false;
        $this->state['health_state'] = 'ADMIN_STOPPED';
        $this->state['admin_stopped_at'] = \gmdate(DATE_ATOM);
        $this->persistState();
        $this->journal('admin_stopped', [
            'force' => ($payload['force'] ?? false) === true,
        ]);
        $this->running = false;
        return ['accepted' => true, 'message' => 'Gateway controller and data plane are stopping.'];
    }

    private function writeAdminStoppedIntent(): void
    {
        if ($this->brokerActionsAvailable('admin')) {
            $this->brokerAction('admin', [
                'STOP',
                (string)$this->state['epoch'],
            ]);
            if (!\is_file($this->adminStoppedIntentFile())
                || \is_link($this->adminStoppedIntentFile())
            ) {
                throw new \RuntimeException(
                    'Native Broker accepted stop intent creation without publishing it.'
                );
            }
            return;
        }
        if ($this->brokerActionsRequired()) {
            throw new \RuntimeException(
                'Production gateway stop requires the native Broker stop-intent action.'
            );
        }
        $secret = \strtolower(\trim((string)@\file_get_contents(
            $this->adminTokenFile(),
        )));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $secret) !== 1) {
            throw new \RuntimeException(
                'Gateway administrator credential is unavailable for stop intent signing.'
            );
        }
        $key = \hex2bin($secret);
        if (!\is_string($key) || \strlen($key) !== 32) {
            throw new \RuntimeException(
                'Gateway administrator credential cannot sign the stop intent.'
            );
        }
        $payload = "WLS-ADMIN-STOPPED/1\n"
            . 'host_id=' . $this->hostId() . "\n"
            . 'epoch=' . (string)$this->state['epoch'] . "\n"
            . 'at=' . \time() . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        $signature = \hash_hmac('sha256', $payload, $key);
        \sodium_memzero($key);
        $this->atomicWrite(
            $this->adminStoppedIntentFile(),
            $payload . 'signature=' . $signature . "\n",
            0600,
        );
    }

    private function maintenance(): void
    {
        $now = \microtime(true);
        $storage = $this->storageStatus();
        if (!(bool)($storage['mutation_ready'] ?? false)) {
            $this->markDiskPressure(
                'DISK_PRESSURE',
                'durable_maintenance_suspended',
            );
            if ($now - $this->lastHealthAt >= self::HEALTH_INTERVAL) {
                $this->lastHealthAt = $now;
                $this->observeDataPlaneDuringDiskPressure();
                $this->pruneNonces();
                $this->collectSnapshots();
            }
            return;
        }
        $this->processPendingPublication();
        if ($now - $this->lastHealthAt >= self::HEALTH_INTERVAL) {
            $this->lastHealthAt = $now;
            try {
                $this->expireLeases();
                $this->publishIfDirty();
            } catch (\Throwable $throwable) {
                $reason = 'Lease maintenance transaction aborted: ' . $throwable->getMessage();
                $this->abortRoutingMutation($reason);
                $this->recordFailure($reason);
                $this->state['health_state'] = 'CONTROL_DEGRADED';
                $this->persistState();
            }
            $this->recoverDataPlane();
            $this->pruneNonces();
            $this->collectSnapshots();
        }
        if ($now - $this->lastBackendProbeAt >= self::BACKEND_PROBE_INTERVAL) {
            $this->lastBackendProbeAt = $now;
            try {
                $retryRequired = $this->probeActiveBackends();
                $this->publishIfDirty();
                if ($retryRequired) {
                    $this->lastBackendProbeAt = \microtime(true)
                        - self::BACKEND_PROBE_INTERVAL
                        + self::BACKEND_PROBE_RETRY_INTERVAL;
                }
            } catch (\Throwable $throwable) {
                $reason = 'Backend probe transaction aborted: ' . $throwable->getMessage();
                $this->abortRoutingMutation($reason);
                $this->recordFailure($reason);
                $this->state['health_state'] = 'CONTROL_DEGRADED';
                $this->persistState();
            }
        }
    }

    private function observeDataPlaneDuringDiskPressure(): void
    {
        $status = $this->nginxStatus();
        $coreHealthy = ($status['running'] ?? false)
            && $this->publicPortsReachable();
        $this->state['ready'] = $coreHealthy
            && $this->journalTrusted
            && !($this->state['isolation_mode'] ?? false);
        $this->state['health_state'] = 'DISK_PRESSURE';
        $this->state['recovery']['stage'] = $coreHealthy
            ? 'DISK_PRESSURE'
            : 'DISK_PRESSURE_DATA_PLANE_DOWN';
        if (!$coreHealthy) {
            $this->state['recovery']['last_failure']
                = (string)($status['message'] ?? 'data_plane_probe_failed_during_disk_pressure');
        }
    }

    private function expireLeases(): void
    {
        $this->beginRoutingMutation('lease-expiration');
        $now = \time();
        $monotonicNow = $this->monotonicNow();
        $changed = false;
        $stateOnlyChanged = false;
        foreach ((array)($this->state['instances'] ?? []) as $projectUuid => $instances) {
            foreach ((array)$instances as $instanceId => $instance) {
                if (!\is_array($instance)) {
                    continue;
                }
                $status = (string)($instance['status'] ?? 'ACTIVE');
                if ($status === 'DRAINING'
                    && (!$this->sameLeaseBoot($instance)
                        || (float)($instance['drain_until_monotonic'] ?? 0.0) <= $monotonicNow)
                ) {
                    unset($this->state['instances'][$projectUuid][$instanceId]);
                    foreach ((array)$this->state['routes'] as $routeId => $route) {
                        if (\is_array($route)
                            && (string)($route['project_uuid'] ?? '') === (string)$projectUuid
                        ) {
                            unset($this->state['routes'][$routeId]['instances'][$instanceId]);
                        }
                    }
                    $changed = true;
                    continue;
                }
                if ($status === 'ACTIVE'
                    && (!$this->sameLeaseBoot($instance)
                        || ($leaseAge = $monotonicNow
                            - (float)($instance['last_heartbeat_monotonic'] ?? 0.0)) < 0.0
                        || $leaseAge >= self::HEARTBEAT_TTL)
                ) {
                    $this->state['instances'][$projectUuid][$instanceId]['status'] = 'STALE';
                    foreach ((array)$this->state['routes'] as $routeId => $route) {
                        if (\is_array($route)
                            && (string)($route['project_uuid'] ?? '') === (string)$projectUuid
                            && \is_array($route['instances'][$instanceId] ?? null)
                        ) {
                            $this->state['routes'][$routeId]['instances'][$instanceId]['status'] = 'STALE';
                        }
                    }
                    $changed = true;
                }
            }
            if (($this->state['instances'][$projectUuid] ?? []) === []) {
                unset($this->state['instances'][$projectUuid]);
            }
        }
        foreach ((array)$this->state['routes'] as $routeId => $route) {
            if (!\is_array($route)) {
                continue;
            }
            $before = $this->routeRoutingDigest($route);
            $this->selectRouteBackends($this->state['routes'][$routeId]);
            $status = (string)$this->state['routes'][$routeId]['status'];
            if ($status === 'STALE'
                && $this->routeStaleDuration(
                    $this->state['routes'][$routeId],
                    $monotonicNow,
                ) >= self::STALE_RETENTION
            ) {
                $this->state['routes'][$routeId]['status'] = 'REMOVED';
                $this->state['routes'][$routeId]['removed_at'] = $now;
            }
            $changed = $changed
                || $before !== $this->routeRoutingDigest($this->state['routes'][$routeId]);
        }
        foreach ((array)($this->state['acme_challenges'] ?? []) as $leaseId => $lease) {
            if (!\is_array($lease) || (int)($lease['expires_at'] ?? 0) <= $now) {
                unset($this->state['acme_challenges'][$leaseId]);
                $changed = true;
            }
        }
        foreach ((array)($this->state['transfers'] ?? []) as $transferId => $transfer) {
            if (!\is_array($transfer)) {
                unset($this->state['transfers'][$transferId]);
                $stateOnlyChanged = true;
                continue;
            }
            if (\in_array(
                (string)($transfer['status'] ?? ''),
                ['PREPARED', 'STAGED'],
                true,
            ) && !$this->transferLeaseValid($transfer)) {
                unset($this->state['transfers'][$transferId]);
                $stateOnlyChanged = true;
            }
        }
        if ($changed) {
            $this->configDirty = true;
            $this->bumpGeneration('lease_transition');
        } else {
            if ($stateOnlyChanged) {
                $this->persistState();
                $this->journal('domain_transfer_expired');
            }
            $this->completePublication();
        }
    }

    private function probeActiveBackends(): bool
    {
        $this->beginRoutingMutation('backend-probe');
        $changed = false;
        $retryRequired = false;
        /** @var array<string,array{healthy:bool,failure_kind:string}> $probeCache */
        $probeCache = [];
        foreach ((array)$this->state['routes'] as $routeId => $route) {
            if (!\is_array($route)
                || \in_array((string)($route['status'] ?? ''), ['REMOVED'], true)
            ) {
                continue;
            }
            $before = $this->routeRoutingDigest($route);
            foreach ((array)($route['instances'] ?? []) as $instanceId => $instance) {
                if (!\is_array($instance)
                    || !\in_array((string)($instance['status'] ?? ''), ['ACTIVE', 'STALE'], true)
                ) {
                    continue;
                }
                $backends = (array)($instance['backends'] ?? []);
                $identity = (array)($instance['backend_identity'] ?? []);
                $probeKey = \hash('sha256', $this->canonicalJson([
                    'backends' => $backends,
                    'identity' => $identity,
                ]));
                if (!isset($probeCache[$probeKey])) {
                    $failureKind = null;
                    $probeCache[$probeKey] = [
                        'healthy' => $this->probeBackends(
                            $backends,
                            $identity,
                            false,
                            $failureKind,
                        ),
                        'failure_kind' => (string)$failureKind,
                    ];
                }
                $probe = $probeCache[$probeKey];
                $target = &$this->state['routes'][$routeId]['instances'][$instanceId];
                $retryRequired = $this->applyBackendProbeResult(
                    $target,
                    $probe['healthy'],
                    $probe['failure_kind'],
                ) || $retryRequired;
                unset($target);
            }
            $this->selectRouteBackends($this->state['routes'][$routeId]);
            $this->state['routes'][$routeId]['last_backend_probe'] = \time();
            $changed = $changed
                || $before !== $this->routeRoutingDigest($this->state['routes'][$routeId]);
        }
        if ($changed) {
            $this->bumpGeneration('backend_health_transition');
            $this->configDirty = true;
        } else {
            $this->persistState();
            $this->completePublication();
        }
        return $retryRequired;
    }

    /**
     * A transport timeout cannot revoke a previously authenticated backend
     * while its independently maintained project lease is still active.
     * Saturated dispatchers otherwise turn the health probe into a
     * self-inflicted host-wide outage. Explicit identity failures and
     * never-authenticated backends remain fail-closed.
     *
     * @param array<string,mixed> $instance
     */
    private function applyBackendProbeResult(
        array &$instance,
        bool $healthy,
        string $failureKind,
    ): bool {
        $instance['last_backend_probe'] = \time();
        if ($healthy) {
            $instance['backend_healthy'] = true;
            $instance['backend_probe_failures'] = 0;
            $instance['last_backend_probe_success'] = \time();
            $instance['last_backend_probe_failure_kind'] = '';
            return false;
        }
        $failures = (int)($instance['backend_probe_failures'] ?? 0) + 1;
        $instance['backend_probe_failures'] = $failures;
        $instance['last_backend_probe_failure_kind'] = $failureKind;
        if ($failureKind === 'identity'
            || !($instance['backend_healthy'] ?? false)
        ) {
            $instance['backend_healthy'] = false;
        }
        return $failureKind !== 'transport'
            || $failures < self::BACKEND_PROBE_FAST_RETRY_LIMIT;
    }

    private function recoverDataPlane(): void
    {
        $status = $this->nginxStatus();
        if (\PHP_OS_FAMILY === 'Windows'
            && ($status['service_tree_restart_required'] ?? false) === true
        ) {
            $reason = (string)($status['message'] ?? 'Windows Nginx process tree is unavailable.');
            $this->state['ready'] = false;
            $this->state['health_state'] = 'DATA_PLANE_DOWN';
            $this->state['recovery']['stage'] = 'SERVICE_TREE_RESTART';
            $this->recordFailure($reason);
            $this->persistState();
            throw new \RuntimeException(
                'Windows Nginx process tree requires platform service recovery: ' . $reason,
                self::SERVICE_TREE_RESTART_EXIT,
            );
        }
        $coreHealthy = ($status['running'] ?? false)
            && $this->publicPortsReachable();
        $routesHealthy = $coreHealthy && $this->publicRoutesReachable();
        if ($coreHealthy) {
            if (($this->state['isolation_mode'] ?? false) === true) {
                $this->state['ready'] = false;
                if (!\str_contains(
                    (string)($this->state['health_state'] ?? ''),
                    'STATE_REBUILD',
                )) {
                    $this->state['health_state'] = 'STATE_REBUILD';
                }
                $this->state['recovery']['stage'] = 'STATE_REBUILD';
                $this->persistState();
                return;
            }
            $storageReady = ($this->storageStatus()['mutation_ready'] ?? false) === true;
            if (!$storageReady) {
                $this->markDiskPressure(
                    'DISK_PRESSURE',
                    'data_plane_state_persistence_suspended',
                );
                $this->observeDataPlaneDuringDiskPressure();
                return;
            }
            $this->state['ready'] = $this->journalTrusted;
            $this->state['health_state'] = !$this->journalTrusted
                ? 'JOURNAL_UNTRUSTED'
                : ($routesHealthy ? 'HEALTHY' : 'ROUTE_DEGRADED');
            $this->state['recovery']['consecutive_failures'] = 0;
            $this->state['recovery']['backoff_attempt'] = 0;
            $this->clearRecoveryCircuit();
            if ($storageReady && $this->journalTrusted) {
                $this->state['recovery']['stage'] = $routesHealthy
                    ? 'NONE'
                    : 'ROUTE_DEGRADED';
            }
            if ($routesHealthy
                && (int)($this->state['pending_lkg_generation'] ?? 0) > 0
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
        $monotonicNow = $this->monotonicNow();
        $circuitAction = $this->recoveryCircuitAction($monotonicNow);
        if ($circuitAction === 'OPEN') {
            $this->state['recovery']['stage'] = 'CIRCUIT_OPEN';
            $this->persistState();
            return;
        }
        if ($circuitAction === 'RETRY') {
            $this->state['recovery']['stage'] = 'MAINTENANCE_RETRY';
            $this->restartDataPlane('circuit_maintenance_retry');
            return;
        }
        $this->recordFailure((string)($status['message'] ?? 'public probes failed'));
        if ($this->recoveryCircuitAction($monotonicNow) === 'OPEN') {
            $this->state['recovery']['stage'] = 'CIRCUIT_OPEN';
            $this->persistState();
            return;
        }
        $recovery = (array)$this->state['recovery'];
        if ((int)($recovery['consecutive_failures'] ?? 0) < 3) {
            $this->persistState();
            return;
        }

        $recentFive = \array_filter(
            (array)$this->state['failure_events'],
            fn (mixed $event): bool => \is_array($event)
                && \hash_equals(
                    $this->hostBootId,
                    (string)($event['boot_id'] ?? ''),
                )
                && ($age = $monotonicNow
                    - (float)($event['at_monotonic'] ?? 0.0)) >= 0.0
                && $age <= self::FAILURE_WINDOW,
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
        $monotonicNow = $this->monotonicNow();
        $events = (array)$this->state['failure_events'];
        $events[] = [
            'at' => $now,
            'at_monotonic' => $monotonicNow,
            'boot_id' => $this->hostBootId,
            'reason' => $reason,
        ];
        $events = \array_values(\array_filter(
            $events,
            fn (mixed $event): bool => \is_array($event)
                && \hash_equals(
                    $this->hostBootId,
                    (string)($event['boot_id'] ?? ''),
                )
                && ($age = $monotonicNow
                    - (float)($event['at_monotonic'] ?? 0.0)) >= 0.0
                && $age <= self::CIRCUIT_WINDOW,
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
            $this->state['recovery']['circuit_open_until_monotonic']
                = $monotonicNow + $delay;
            $this->state['recovery']['circuit_boot_id'] = $this->hostBootId;
            $this->state['recovery']['next_retry_at'] = $now + $delay;
        }
        $this->journal('recovery_failure', ['reason' => $reason]);
    }

    private function recoveryCircuitAction(?float $monotonicNow = null): string
    {
        $monotonicNow ??= $this->monotonicNow();
        $recovery = (array)($this->state['recovery'] ?? []);
        $until = (float)($recovery['circuit_open_until_monotonic'] ?? 0.0);
        if ($until <= 0.0) {
            if ((int)($recovery['circuit_open_until'] ?? 0) > 0
                || (string)($recovery['circuit_boot_id'] ?? '') !== ''
                || (int)($recovery['next_retry_at'] ?? 0) > 0
            ) {
                $this->clearRecoveryCircuit();
            }
            return 'CLOSED';
        }
        if (!\hash_equals(
            $this->hostBootId,
            (string)($recovery['circuit_boot_id'] ?? ''),
        )) {
            $this->clearRecoveryCircuit();
            return 'CLOSED';
        }
        if ($monotonicNow < $until) {
            return 'OPEN';
        }
        $this->clearRecoveryCircuit();
        return 'RETRY';
    }

    private function clearRecoveryCircuit(): void
    {
        $this->state['recovery']['circuit_open_until'] = 0;
        $this->state['recovery']['circuit_open_until_monotonic'] = 0.0;
        $this->state['recovery']['circuit_boot_id'] = '';
        $this->state['recovery']['next_retry_at'] = 0;
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

    private function beginRoutingMutation(string $operation): void
    {
        if ($this->publication !== null) {
            if ($this->deferPublication
                && (string)($this->publication['phase'] ?? '') === 'PENDING_PUBLICATION'
                && $this->requestStateBeforeMutation === null
            ) {
                $this->requestStateBeforeMutation = $this->state;
                $this->requestPublicationBeforeMutation = $this->publication;
                $this->requestConfigDirtyBeforeMutation = $this->configDirty;
            }
            return;
        }
        $this->publication = [
            'schema_version' => 1,
            'transaction_id' => \bin2hex(\random_bytes(16)),
            'operation' => $operation,
            'phase' => 'DESIRED',
            'created_at' => \gmdate(DATE_ATOM),
            'previous' => [
                'generation' => (int)($this->state['generation'] ?? 0),
                'projects' => (array)($this->state['projects'] ?? []),
                'instances' => (array)($this->state['instances'] ?? []),
                'routes' => (array)($this->state['routes'] ?? []),
                'acme_challenges' => (array)($this->state['acme_challenges'] ?? []),
                'acme_generations' => (array)($this->state['acme_generations'] ?? []),
                'isolation_mode' => (bool)($this->state['isolation_mode'] ?? false),
                'active_config_generation' => (int)($this->state['active_config_generation'] ?? 0),
                'pending_lkg_generation' => (int)($this->state['pending_lkg_generation'] ?? 0),
                'pending_lkg_since' => (int)($this->state['pending_lkg_since'] ?? 0),
                'config_dirty' => $this->configDirty,
            ],
            'candidate_generation' => 0,
            'candidate_file' => '',
            'candidate_digest' => '',
            'rollback_file' => '',
            'irrevocable_security' => false,
            'operation_ids' => [],
        ];
        $this->persistPublication();
    }

    private function persistPublication(): void
    {
        if ($this->publication === null) {
            return;
        }
        $payload = $this->publication;
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', $this->canonicalJson($payload)),
        ];
        $this->atomicWrite(
            $this->publicationFile(),
            \json_encode(
                $envelope,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR,
            ),
            0600,
        );
    }

    private function completePublication(): void
    {
        if ((string)($this->publication['phase'] ?? '') === 'PENDING_PUBLICATION') {
            return;
        }
        $this->publication = null;
        @\unlink($this->publicationFile());
    }

    private function markPublicationIrrevocableSecurity(): void
    {
        if ($this->publication === null) {
            throw new \RuntimeException(
                'A security mutation requires an active publication transaction.'
            );
        }
        $this->publication['irrevocable_security'] = true;
        $this->persistPublication();
    }

    private function abortRoutingMutation(string $reason): void
    {
        if ($this->requestStateBeforeMutation !== null
            && $this->requestPublicationBeforeMutation !== null
        ) {
            $this->state = $this->requestStateBeforeMutation;
            $this->publication = $this->requestPublicationBeforeMutation;
            $this->configDirty = $this->requestConfigDirtyBeforeMutation;
            $this->requestStateBeforeMutation = null;
            $this->requestPublicationBeforeMutation = null;
            $this->requestConfigDirtyBeforeMutation = false;
            $this->persistState();
            $this->persistPublication();
            $this->journal('publication_request_rolled_back', [
                'transaction_id' => (string)($this->publication['transaction_id'] ?? ''),
                'operation' => $this->requestOperation,
                'reason' => $reason,
            ]);
            return;
        }
        if ($this->publication === null) {
            return;
        }
        try {
            $this->rollbackRoutingMutation($reason);
        } finally {
            $this->completePublication();
        }
    }

    private function rollbackRoutingMutation(string $reason): void
    {
        if ($this->publication === null) {
            return;
        }
        $previous = \is_array($this->publication['previous'] ?? null)
            ? $this->publication['previous']
            : [];
        $irrevocableSecurity = ($this->publication['irrevocable_security'] ?? false) === true;
        if (!$irrevocableSecurity) {
            foreach ([
                'projects',
                'instances',
                'routes',
                'acme_challenges',
                'acme_generations',
            ] as $key) {
                if (\is_array($previous[$key] ?? null)) {
                    $this->state[$key] = $previous[$key];
                }
            }
            $this->state['generation'] = (int)($previous['generation'] ?? 0);
            $this->state['isolation_mode'] = (bool)($previous['isolation_mode'] ?? false);
            $this->state['pending_lkg_generation']
                = (int)($previous['pending_lkg_generation'] ?? 0);
            $this->state['pending_lkg_since'] = (int)($previous['pending_lkg_since'] ?? 0);
            $this->configDirty = (bool)($previous['config_dirty'] ?? false);
        } else {
            // A durable enrollment revocation or tombstone must never be
            // undone by publication rollback. Keep the filtered desired state
            // and force the data plane to remain unavailable until it can be
            // rendered and activated successfully.
            $this->state['ready'] = false;
            $this->state['isolation_mode'] = true;
            $this->configDirty = true;
        }
        $this->state['active_config_generation']
            = (int)($previous['active_config_generation'] ?? 0);
        $this->state['last_publication_error'] = $reason;
        $this->state['health_state'] = $irrevocableSecurity
            ? 'SECURITY_MUTATION_FAILED_CLOSED'
            : 'PUBLICATION_FAILED';
        $this->finishPublicationOperations('FAILED', $reason);
        if ($irrevocableSecurity) {
            $this->stopDataPlane();
        }
        $this->persistState();
    }

    private function queuePublicationOperation(): void
    {
        if ($this->publication === null) {
            throw new \RuntimeException('Unable to queue a publication without a transaction.');
        }
        $this->pruneOperations();
        $pending = 0;
        foreach ((array)($this->state['operations'] ?? []) as $operation) {
            if (\is_array($operation)
                && \in_array(
                    (string)($operation['state'] ?? ''),
                    ['PENDING_PUBLICATION', 'PREPARING', 'ACTIVATING'],
                    true,
                )
            ) {
                ++$pending;
            }
        }
        if ($pending >= self::MAX_PUBLICATION_QUEUE) {
            throw new \DomainException(
                'Gateway publication queue is full; retry after existing operations finish.'
            );
        }
        $operationId = \bin2hex(\random_bytes(16));
        $now = \time();
        $projectUuid = \preg_match(
            '/\A[a-f0-9-]{36}\z/D',
            $this->requestPrincipal,
        ) === 1 ? $this->requestPrincipal : '';
        $this->state['operations'][$operationId] = [
            'operation_id' => $operationId,
            'operation' => $this->requestOperation !== ''
                ? $this->requestOperation
                : (string)($this->publication['operation'] ?? 'publication'),
            'principal' => $this->requestPrincipal !== '' ? $this->requestPrincipal : 'controller',
            'project_uuid' => $projectUuid,
            'state' => 'PENDING_PUBLICATION',
            'desired_generation' => (int)($this->state['generation'] ?? 0),
            'active_generation' => 0,
            'transaction_id' => (string)$this->publication['transaction_id'],
            'created_at' => \gmdate(DATE_ATOM, $now),
            'created_unix' => $now,
            'updated_at' => \gmdate(DATE_ATOM, $now),
            'completed_at' => '',
            'completed_unix' => 0,
            'error' => '',
        ];
        $operationIds = \array_values(\array_unique(\array_filter(
            (array)($this->publication['operation_ids'] ?? []),
            static fn (mixed $id): bool => \is_string($id)
                && \preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1,
        )));
        $operationIds[] = $operationId;
        $this->publication['operation_ids'] = $operationIds;
        $this->publication['phase'] = 'PENDING_PUBLICATION';
        $this->publication['queued_at'] ??= \gmdate(DATE_ATOM, $now);
        $this->publication['due_at_unix'] = $now + self::PUBLICATION_DEBOUNCE_SECONDS;
        $this->lastQueuedOperationId = $operationId;
        $this->publicationDueAt = $this->monotonicNow() + self::PUBLICATION_DEBOUNCE_SECONDS;
        $this->persistState();
        $this->persistPublication();
        $this->journal('publication_queued', [
            'transaction_id' => (string)$this->publication['transaction_id'],
            'operation_id' => $operationId,
            'operation' => $this->requestOperation,
            'generation' => (int)($this->state['generation'] ?? 0),
        ]);
        $this->requestStateBeforeMutation = null;
        $this->requestPublicationBeforeMutation = null;
        $this->requestConfigDirtyBeforeMutation = false;
    }

    private function processPendingPublication(): void
    {
        if ($this->publication === null
            || (string)($this->publication['phase'] ?? '') !== 'PENDING_PUBLICATION'
        ) {
            return;
        }
        $now = $this->monotonicNow();
        if ($this->publicationDueAt <= 0.0) {
            $this->publicationDueAt = $now;
        }
        if ($now < $this->publicationDueAt
            || ($this->lastPublicationAt > 0.0
                && $now - $this->lastPublicationAt < self::PUBLICATION_MIN_INTERVAL_SECONDS)
        ) {
            return;
        }
        $this->deferPublication = false;
        $this->publication['phase'] = 'DESIRED';
        $this->updatePublicationOperations('PREPARING');
        $this->persistPublication();
        $this->persistState();
        try {
            $this->publishIfDirty();
        } catch (\Throwable $throwable) {
            $reason = 'Queued publication failed: ' . $throwable->getMessage();
            $this->recordFailure($reason);
            $this->abortRoutingMutation($reason);
        } finally {
            $this->lastPublicationAt = $this->monotonicNow();
            $this->publicationDueAt = 0.0;
        }
    }

    private function updatePublicationOperations(string $state): void
    {
        if ($this->publication === null) {
            return;
        }
        $now = \time();
        foreach ((array)($this->publication['operation_ids'] ?? []) as $operationId) {
            if (!\is_string($operationId)
                || !\is_array($this->state['operations'][$operationId] ?? null)
            ) {
                continue;
            }
            $this->state['operations'][$operationId]['state'] = $state;
            $this->state['operations'][$operationId]['updated_at'] = \gmdate(DATE_ATOM, $now);
        }
    }

    private function finishPublicationOperations(string $state, string $error = ''): void
    {
        if ($this->publication === null) {
            return;
        }
        $now = \time();
        foreach ((array)($this->publication['operation_ids'] ?? []) as $operationId) {
            if (!\is_string($operationId)
                || !\is_array($this->state['operations'][$operationId] ?? null)
            ) {
                continue;
            }
            $this->state['operations'][$operationId]['state'] = $state;
            $this->state['operations'][$operationId]['active_generation']
                = (int)($this->state['active_config_generation'] ?? 0);
            $this->state['operations'][$operationId]['updated_at'] = \gmdate(DATE_ATOM, $now);
            $this->state['operations'][$operationId]['completed_at'] = \gmdate(DATE_ATOM, $now);
            $this->state['operations'][$operationId]['completed_unix'] = $now;
            $this->state['operations'][$operationId]['error'] = $error;
        }
    }

    private function pruneOperations(): void
    {
        $now = \time();
        $operations = [];
        foreach ((array)($this->state['operations'] ?? []) as $operationId => $operation) {
            if (!\is_string($operationId) || !\is_array($operation)) {
                continue;
            }
            $terminal = \in_array(
                (string)($operation['state'] ?? ''),
                ['COMMITTED', 'FAILED'],
                true,
            );
            if ($terminal
                && (int)($operation['completed_unix'] ?? 0) > 0
                && $now - (int)$operation['completed_unix'] > self::OPERATION_RETENTION_SECONDS
            ) {
                continue;
            }
            $operations[$operationId] = $operation;
        }
        if (\count($operations) > self::MAX_PUBLICATION_QUEUE * 2) {
            \uasort(
                $operations,
                static fn (array $left, array $right): int =>
                    (int)($right['created_unix'] ?? 0) <=> (int)($left['created_unix'] ?? 0),
            );
            $pending = \array_filter(
                $operations,
                static fn (array $operation): bool => !\in_array(
                    (string)($operation['state'] ?? ''),
                    ['COMMITTED', 'FAILED'],
                    true,
                ),
            );
            $terminal = \array_filter(
                $operations,
                static fn (array $operation): bool => \in_array(
                    (string)($operation['state'] ?? ''),
                    ['COMMITTED', 'FAILED'],
                    true,
                ),
            );
            $operations = $pending + \array_slice(
                $terminal,
                0,
                self::MAX_PUBLICATION_QUEUE,
                true,
            );
        }
        $this->state['operations'] = $operations;
    }

    private function failClosedSecurityMutation(
        string $reason,
        bool $securityLedgerUntrusted = false,
    ): void
    {
        if ($securityLedgerUntrusted) {
            $this->state['security_ledger_valid'] = false;
            try {
                $this->atomicWrite(
                    $this->securityLedgerFile() . '.untrusted',
                    \gmdate(DATE_ATOM) . "\n",
                    0600,
                );
            } catch (\Throwable) {
                // The data plane still stops below. A failed marker write must
                // never turn a failed revocation into an availability path.
            }
        }
        $this->state['ready'] = false;
        $this->state['isolation_mode'] = true;
        $this->state['health_state'] = 'SECURITY_MUTATION_FAILED_CLOSED';
        $this->state['last_publication_error'] = $reason;
        $this->configDirty = true;
        $this->stopDataPlane();
        try {
            $this->persistState();
        } finally {
            $this->completePublication();
        }
        $this->journal('security_mutation_failed_closed', ['reason' => $reason]);
    }

    private function reconcileInterruptedPublication(): void
    {
        $file = $this->publicationFile();
        if (!\is_file($file) || \is_link($file)) {
            return;
        }
        $decoded = \json_decode((string)@\file_get_contents($file), true);
        $payload = \is_array($decoded['payload'] ?? null) ? $decoded['payload'] : null;
        if (!\is_array($payload)
            || !\hash_equals(
                (string)($decoded['sha256'] ?? ''),
                \hash('sha256', $this->canonicalJson($payload)),
            )
        ) {
            $quarantine = $file . '.corrupt.' . \gmdate('YmdHis');
            @\rename($file, $quarantine);
            $now = \time();
            foreach ((array)($this->state['operations'] ?? []) as $operationId => $operation) {
                if (!\is_string($operationId)
                    || !\is_array($operation)
                    || \in_array(
                        (string)($operation['state'] ?? ''),
                        ['COMMITTED', 'FAILED'],
                        true,
                    )
                ) {
                    continue;
                }
                $this->state['operations'][$operationId]['state'] = 'FAILED';
                $this->state['operations'][$operationId]['updated_at']
                    = \gmdate(DATE_ATOM, $now);
                $this->state['operations'][$operationId]['completed_at']
                    = \gmdate(DATE_ATOM, $now);
                $this->state['operations'][$operationId]['completed_unix'] = $now;
                $this->state['operations'][$operationId]['error']
                    = 'Publication journal was corrupt and quarantined.';
            }
            $this->state['ready'] = false;
            $this->state['isolation_mode'] = true;
            $this->state['health_state'] = 'STATE_REBUILD';
            $this->configDirty = true;
            $this->persistState();
            $this->journal('publication_corrupt_quarantined', [
                'quarantine' => $quarantine,
            ]);
            return;
        }
        $this->publication = $payload;
        if ((string)($payload['phase'] ?? '') === 'COMMITTED') {
            $this->finishPublicationOperations('COMMITTED');
            $this->persistState();
            $this->archivePublicationRollback($payload);
            $this->completePublication();
            return;
        }
        $phase = (string)($payload['phase'] ?? '');
        if (\in_array(
            $phase,
            ['PENDING_PUBLICATION', 'DESIRED', 'PREPARED', 'SHADOW_VERIFIED'],
            true,
        )) {
            $candidate = (string)($payload['candidate_file'] ?? '');
            if ($candidate !== ''
                && $this->pathInside($candidate, $this->configDir())
                && \is_file($candidate)
                && !\is_link($candidate)
            ) {
                @\unlink($candidate);
            }
            $this->publication['phase'] = 'PENDING_PUBLICATION';
            $this->publication['candidate_file'] = '';
            $this->publication['candidate_digest'] = '';
            $this->publication['rollback_file'] = '';
            $this->configDirty = true;
            $this->publicationDueAt = $this->monotonicNow();
            $this->updatePublicationOperations('PENDING_PUBLICATION');
            $this->state['health_state'] = 'PUBLICATION_RECOVERY';
            $this->persistState();
            $this->persistPublication();
            return;
        }
        if ($phase === 'ACTIVATING'
            && \is_file($this->configFile())
            && !\is_link($this->configFile())
            && \hash_equals(
                (string)($payload['candidate_digest'] ?? ''),
                $this->fileHash($this->configFile()),
            )
            && ($this->nginxStatus()['running'] ?? false) === true
            && $this->publicPortsReachable((int)($payload['candidate_generation'] ?? 0))
            && $this->publicRoutesReachable()
        ) {
            $this->state['active_config_generation']
                = (int)($payload['candidate_generation'] ?? 0);
            $this->state['pending_lkg_generation']
                = (int)($payload['candidate_generation'] ?? 0);
            $this->state['pending_lkg_since'] = \time();
            $this->configDirty = false;
            $this->finishPublicationOperations('COMMITTED');
            $this->persistRouteLkg();
            $this->persistState();
            $this->journal('publication_recovered_committed', [
                'transaction_id' => (string)($payload['transaction_id'] ?? ''),
                'generation' => (int)$this->state['active_config_generation'],
            ]);
            $this->archivePublicationRollback($payload);
            $this->completePublication();
            return;
        }
        $rollback = (string)($payload['rollback_file'] ?? '');
        if ($rollback !== ''
            && $this->pathInside($rollback, $this->configDir())
            && \is_file($rollback)
            && !\is_link($rollback)
        ) {
            $rejected = $this->configFile() . '.rejected.recovery';
            $active = @\file_get_contents($this->configFile());
            $rollbackConfig = @\file_get_contents($rollback);
            if (\is_string($active) && $active !== '') {
                try {
                    $this->atomicWrite($rejected, $active, 0600);
                } catch (\Throwable) {
                }
            }
            if (\is_string($rollbackConfig) && $rollbackConfig !== '') {
                try {
                    $this->atomicWrite($this->configFile(), $rollbackConfig, 0600);
                } catch (\Throwable) {
                    // The deterministic state rollback below will fail closed
                    // if the previous active config cannot be restored.
                }
            }
        } elseif ((string)($payload['phase'] ?? '') === 'ACTIVATING'
            && \is_file($this->configFile())
            && \hash_equals(
                (string)($payload['candidate_digest'] ?? ''),
                $this->fileHash($this->configFile()),
            )
        ) {
            @\unlink($this->configFile());
        }
        $this->rollbackRoutingMutation('Interrupted publication was conservatively rolled back.');
        $this->state['health_state'] = 'PUBLICATION_RECOVERY';
        $this->persistState();
        $this->completePublication();
        $this->discardPublicationRollback($rollback);
        // Never restart from a raw rollback file alone: a durable security
        // tombstone may have been committed before the interrupted config
        // activation. Re-render the recovered desired state with the current
        // security ledger overlay before the data plane can be adopted.
        $this->beginRoutingMutation('publication-recovery-security-overlay');
        $this->configDirty = true;
        if (!$this->publishIfDirty()) {
            $this->stopDataPlane();
            $this->state['isolation_mode'] = true;
            $this->state['health_state'] = 'PUBLICATION_RECOVERY_FAILED_CLOSED';
            $this->persistState();
        }
    }

    private function publishIfDirty(): bool
    {
        if (!$this->configDirty) {
            $this->completePublication();
            return true;
        }
        $this->beginRoutingMutation('implicit-publication');
        if ($this->publication === null) {
            throw new \RuntimeException('Unable to establish the gateway publication transaction.');
        }
        if ($this->deferPublication) {
            $this->queuePublicationOperation();
            return true;
        }
        $this->publication['phase'] = 'DESIRED';
        $this->updatePublicationOperations('PREPARING');
        $this->persistPublication();
        $this->persistState();
        $transactionId = (string)$this->publication['transaction_id'];
        $candidate = $this->configDir() . DIRECTORY_SEPARATOR . 'candidate-'
            . (int)$this->state['generation'] . '-' . $transactionId . '.conf';
        $config = $this->renderNginxConfig(true);
        $this->atomicWrite($candidate, $config, 0600);
        $this->publication['phase'] = 'PREPARED';
        $this->publication['candidate_generation'] = (int)$this->state['generation'];
        $this->publication['candidate_file'] = $candidate;
        $this->publication['candidate_digest'] = $this->fileHash($candidate);
        $this->persistPublication();
        $this->journal('publication_phase', [
            'transaction_id' => $transactionId,
            'phase' => 'PREPARED',
            'generation' => (int)$this->state['generation'],
        ]);
        $test = $this->runNginx(['-t', '-c', $candidate]);
        $candidateAttemptedH3 = (bool)($this->state['h3_enabled'] ?? false);
        if (($test['code'] ?? 1) !== 0 && $candidateAttemptedH3) {
            // H3 is an optional capability. A QUIC-only config failure retries
            // without QUIC and records the downgrade, preserving H2/H1.
            $this->quarantineH3ForActiveRuntime(
                'Candidate failed with H3; downgraded to H2/H1: '
                    . (string)($test['output'] ?? ''),
            );
            $this->atomicWrite($candidate, $this->renderNginxConfig(false), 0600);
            $this->publication['candidate_digest'] = $this->fileHash($candidate);
            $this->persistPublication();
            $test = $this->runNginx(['-t', '-c', $candidate]);
        }
        $this->lastShadowVerificationError = '';
        $shadowVerified = ($test['code'] ?? 1) === 0
            && $this->shadowVerifyRoutes($candidate, $transactionId);
        if (!$shadowVerified) {
            $reason = ($test['code'] ?? 1) !== 0
                ? 'nginx -t failed: ' . (string)($test['output'] ?? '')
                : ($this->lastShadowVerificationError !== ''
                    ? $this->lastShadowVerificationError
                    : 'Candidate route/backend/certificate shadow verification failed.');
            $this->recordFailure($reason);
            @\unlink($candidate);
            $this->rollbackRoutingMutation($reason);
            $this->completePublication();
            return false;
        }
        $this->publication['phase'] = 'SHADOW_VERIFIED';
        $this->persistPublication();
        $this->journal('publication_phase', [
            'transaction_id' => $transactionId,
            'phase' => 'SHADOW_VERIFIED',
        ]);
        $current = $this->configFile();
        $rollback = $current . '.rollback.' . $transactionId;
        if (\is_file($current)) {
            if (\is_link($current)
                || \file_exists($rollback)
            ) {
                @\unlink($candidate);
                $reason = 'Unable to preserve active Nginx config for publication rollback.';
                $this->recordFailure($reason);
                $this->rollbackRoutingMutation($reason);
                $this->completePublication();
                return false;
            }
            $activeConfig = @\file_get_contents($current);
            if (!\is_string($activeConfig) || $activeConfig === '') {
                @\unlink($candidate);
                $reason = 'Unable to read active Nginx config for publication rollback.';
                $this->recordFailure($reason);
                $this->rollbackRoutingMutation($reason);
                $this->completePublication();
                return false;
            }
            $this->atomicWrite($rollback, $activeConfig, 0600);
            $this->publication['rollback_file'] = $rollback;
            $this->persistPublication();
        }
        $this->publication['phase'] = 'ACTIVATING';
        $this->updatePublicationOperations('ACTIVATING');
        $this->persistPublication();
        $this->persistState();
        $this->journal('publication_phase', [
            'transaction_id' => $transactionId,
            'phase' => 'ACTIVATING',
        ]);
        $candidateConfig = @\file_get_contents($candidate);
        try {
            if (!\is_string($candidateConfig) || $candidateConfig === '') {
                throw new \RuntimeException('Candidate Nginx config became unreadable.');
            }
            // The active path is replaced in one atomic write. The previous
            // config remains addressable through the immutable rollback copy;
            // there is no interval where the active path is absent.
            $this->atomicWrite($current, $candidateConfig, 0600);
            @\unlink($candidate);
        } catch (\Throwable $throwable) {
            @\unlink($candidate);
            $reason = 'Atomic Nginx config publication failed: '
                . $throwable->getMessage();
            $this->recordFailure($reason);
            $this->rollbackRoutingMutation($reason);
            $this->discardPublicationRollback($rollback);
            $this->completePublication();
            return false;
        }
        @\chmod($current, 0600);
        $activation = $this->activateCurrentConfigAndProbe(
            (int)$this->state['generation'],
        );
        $result = $activation['result'];
        $publicVerified = $activation['verified'];
        if (!$publicVerified && (bool)($this->state['h3_enabled'] ?? false)) {
            // nginx -t and the isolated shadow cannot detect every QUIC
            // runtime or linked-library failure. If the real data plane fails
            // only after activation, retry the same desired routing without
            // H3 before touching the H2/H1 rollback state.
            $this->quarantineH3ForActiveRuntime(
                'Runtime activation failed with H3; downgraded to H2/H1 '
                    . 'until the gateway runtime changes or an administrator explicitly retries H3.',
            );
            $fallbackConfig = $this->renderNginxConfig(false);
            $this->atomicWrite($current, $fallbackConfig, 0600);
            $this->publication['candidate_digest'] = $this->fileHash($current);
            $this->persistPublication();
            $fallbackTest = $this->runNginx(['-t']);
            if (($fallbackTest['code'] ?? 1) === 0
                && $this->shadowVerifyRoutes($current, $transactionId)
            ) {
                $activation = $this->activateCurrentConfigAndProbe(
                    (int)$this->state['generation'],
                );
                $result = $activation['result'];
                $publicVerified = $activation['verified'];
                if ($publicVerified) {
                    $this->journal('h3_runtime_downgrade', [
                        'transaction_id' => $transactionId,
                        'generation' => (int)$this->state['generation'],
                        'reason' => (string)$this->state['h3_reason'],
                    ]);
                }
            } else {
                $result = [
                    'ok' => false,
                    'message' => 'H3 runtime fallback validation failed: '
                        . (string)($fallbackTest['output'] ?? ''),
                ];
            }
        }
        if (!$publicVerified) {
            $reason = ($result['ok'] ?? false)
                ? 'Published generation failed the public HTTP/HTTPS/health probe.'
                : (string)($result['message'] ?? 'Nginx publication failed.');
            $this->recordFailure($reason);
            $rejected = $current . '.rejected.' . $transactionId;
            $rejectedConfig = @\file_get_contents($current);
            if (\is_string($rejectedConfig) && $rejectedConfig !== '') {
                try {
                    $this->atomicWrite($rejected, $rejectedConfig, 0600);
                } catch (\Throwable) {
                    // The active rollback is more important than retaining the
                    // rejected diagnostic copy under disk pressure.
                }
            }
            if ($this->pathInside($rollback, $this->configDir())
                && \is_file($rollback)
                && !\is_link($rollback)
            ) {
                $rollbackConfig = @\file_get_contents($rollback);
                if (\is_string($rollbackConfig) && $rollbackConfig !== '') {
                    try {
                        $this->atomicWrite($current, $rollbackConfig, 0600);
                    } catch (\Throwable $throwable) {
                        $reason .= ' Atomic active-config rollback failed: '
                            . $throwable->getMessage();
                    }
                } else {
                    $reason .= ' Rollback config became unreadable.';
                }
            } else {
                @\unlink($current);
            }
            $this->rollbackRoutingMutation($reason);
            if (($this->publication['irrevocable_security'] ?? false) !== true
                && \is_file($current)
            ) {
                $rollbackStatus = $this->nginxStatus();
                ($rollbackStatus['running'] ?? false)
                    ? $this->reloadDataPlane()
                    : $this->startDataPlane();
            }
            $this->discardPublicationRollback($rollback);
            $this->completePublication();
            return false;
        }
        // A successful publication has just proved the selected ACTIVE
        // backend identity and certificate through both shadow and public
        // sentinels. Credit that proof as lease liveness so the Controller's
        // own mandatory observation window cannot expire a tenant that was
        // unable to deliver heartbeats while publication held the event loop.
        $this->refreshVerifiedPublishedLeases();
        $this->state['active_config_generation'] = (int)$this->state['generation'];
        $this->state['pending_lkg_generation'] = (int)$this->state['generation'];
        $this->state['pending_lkg_since'] = \time();
        $this->configDirty = false;
        $this->persistRouteLkg();
        $this->publication['phase'] = 'COMMITTED';
        $this->finishPublicationOperations('COMMITTED');
        $this->persistState();
        $this->persistPublication();
        $this->journal('publication_phase', [
            'transaction_id' => $transactionId,
            'phase' => 'COMMITTED',
            'generation' => (int)$this->state['active_config_generation'],
        ]);
        $this->archivePublicationRollback($this->publication);
        $this->completePublication();
        return true;
    }

    /**
     * @return array{verified:bool,result:array<string,mixed>}
     */
    private function activateCurrentConfigAndProbe(int $generation): array
    {
        $status = $this->nginxStatus();
        $result = ($status['running'] ?? false)
            ? $this->reloadDataPlane()
            : $this->startDataPlane();
        $verified = false;
        if (($result['ok'] ?? false) === true) {
            // A successful reload signal only means the master accepted the
            // command. Old workers may still answer the first health or route
            // probe while the new generation is becoming ready.
            $startupDeadline = \microtime(true) + 3.0;
            while (\microtime(true) < $startupDeadline) {
                if ($this->publicPortsReachable($generation, true)
                    && $this->publicRoutesReachable(true)
                ) {
                    $verified = true;
                    break;
                }
                $this->publicationProbePause();
            }
            if ($verified) {
                $observationSeconds = ($this->slotManifest()['test_mode'] ?? false) === true
                    ? 0.2
                    : 15.0;
                $observationDeadline = \microtime(true) + $observationSeconds;
                do {
                    if (!$this->publicPortsReachable($generation, true)
                        || !$this->publicRoutesReachable(true)
                    ) {
                        $verified = false;
                        break;
                    }
                    $this->publicationProbePause();
                } while (\microtime(true) < $observationDeadline);
            }
        }
        return ['verified' => $verified, 'result' => $result];
    }

    /**
     * Keep authenticated status, operation polling and lease heartbeats
     * responsive throughout mandatory publication stability windows.
     */
    private function publicationProbePause(): void
    {
        $this->serviceControlDuringPublicationProbe();
        $deadline = \microtime(true) + 0.1;
        if (!\is_resource($this->controlServer)) {
            $pair = \function_exists('stream_socket_pair')
                ? @\stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0)
                : false;
            if (\is_array($pair)) {
                $read = [$pair[0]];
                $write = [];
                $except = [];
                @\stream_select($read, $write, $except, 0, 100000);
                @\fclose($pair[0]);
                @\fclose($pair[1]);
            }
            return;
        }
        $remaining = \max(0.0, $deadline - \microtime(true));
        $read = [$this->controlServer];
        $write = [];
        $except = [];
        $selected = @\stream_select(
            $read,
            $write,
            $except,
            0,
            (int)\ceil($remaining * 1_000_000),
        );
        if ($selected === 1) {
            $this->serviceControlDuringPublicationProbe();
        }
    }

    private function serviceControlDuringPublicationProbe(): void
    {
        if (!\is_resource($this->controlServer)) {
            return;
        }
        // Heartbeats, status and operation polling commonly arrive together.
        // Drain a bounded batch so a publication probe cannot leave all but
        // the first request queued for another potentially blocking route
        // probe. The request-count and wall-clock fences prevent control
        // traffic from starving publication progress.
        $deadline = \microtime(true) + 0.025;
        for ($served = 0; $served < 8 && \microtime(true) < $deadline; ++$served) {
            $read = [$this->controlServer];
            $write = [];
            $except = [];
            $selected = @\stream_select($read, $write, $except, 0, 0);
            if ($selected !== 1) {
                return;
            }
            $client = @\stream_socket_accept($this->controlServer, 0);
            if (!\is_resource($client)) {
                return;
            }
            $this->serveClient($client);
        }
    }

    private function refreshVerifiedPublishedLeases(): void
    {
        $wall = \time();
        $monotonic = $this->monotonicNow();
        foreach ((array)($this->state['routes'] ?? []) as $routeId => $route) {
            if (!\is_array($route)
                || (string)($route['status'] ?? '') !== 'ACTIVE'
                || !$this->routeAllowedBySecurity($route)
            ) {
                continue;
            }
            $projectUuid = (string)($route['project_uuid'] ?? '');
            $selectedInstanceIds = \array_keys(
                \is_array($route['backend_instances'] ?? null)
                    ? $route['backend_instances']
                    : [],
            );
            if ($selectedInstanceIds === []
                && (string)($route['instance_id'] ?? '') !== ''
            ) {
                $selectedInstanceIds[] = (string)$route['instance_id'];
            }
            if ($projectUuid === '' || $selectedInstanceIds === []) {
                continue;
            }
            foreach ($selectedInstanceIds as $instanceId) {
                $instanceId = (string)$instanceId;
                $routeLease = $route['instances'][$instanceId] ?? null;
                if (!\is_array($routeLease)
                    || (string)($routeLease['status'] ?? '') !== 'ACTIVE'
                    || ($routeLease['backend_healthy'] ?? false) !== true
                ) {
                    continue;
                }
                $routeLease['last_heartbeat'] = $wall;
                $routeLease['last_heartbeat_monotonic'] = $monotonic;
                $routeLease['lease_boot_id'] = $this->hostBootId;
                $this->state['routes'][$routeId]['instances'][$instanceId] = $routeLease;
                $this->state['routes'][$routeId]['last_heartbeat'] = $wall;

                $instanceLease = $this->state['instances'][$projectUuid][$instanceId] ?? null;
                if (!\is_array($instanceLease)
                    || (int)($instanceLease['generation'] ?? 0)
                        !== (int)($routeLease['generation'] ?? 0)
                    || !\hash_equals(
                        (string)($instanceLease['launch_id'] ?? ''),
                        (string)($routeLease['launch_id'] ?? ''),
                    )
                ) {
                    continue;
                }
                $instanceLease['last_heartbeat'] = $wall;
                $instanceLease['last_heartbeat_monotonic'] = $monotonic;
                $instanceLease['lease_boot_id'] = $this->hostBootId;
                $this->state['instances'][$projectUuid][$instanceId] = $instanceLease;
            }
        }
    }

    /**
     * @param array<string,mixed> $publication
     */
    private function archivePublicationRollback(array $publication): void
    {
        $rollback = (string)($publication['rollback_file'] ?? '');
        $transactionId = (string)($publication['transaction_id'] ?? '');
        if ($rollback === ''
            || \preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1
            || !$this->pathInside($rollback, $this->configDir())
            || !\is_file($rollback)
            || \is_link($rollback)
        ) {
            return;
        }
        $generation = (int)($publication['candidate_generation'] ?? 0);
        $previous = $this->lkgDir() . DIRECTORY_SEPARATOR . 'pre-'
            . $generation . '-' . $transactionId . '.conf';
        if (\file_exists($previous) || \is_link($previous)) {
            if (\is_file($previous)
                && !\is_link($previous)
                && \hash_equals($this->fileHash($previous), $this->fileHash($rollback))
            ) {
                $this->discardPublicationRollback($rollback);
            }
            return;
        }
        @\rename($rollback, $previous);
    }

    private function discardPublicationRollback(string $rollback): void
    {
        if ($rollback !== ''
            && $this->pathInside($rollback, $this->configDir())
            && \is_file($rollback)
            && !\is_link($rollback)
        ) {
            @\unlink($rollback);
        }
    }

    private function shadowVerifyRoutes(string $candidate, string $transactionId): bool
    {
        if (!\is_file($candidate)
            || \is_link($candidate)
            || !$this->pathInside($candidate, $this->configDir())
            || \preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1
        ) {
            $this->lastShadowVerificationError =
                'Candidate shadow verification rejected an unsafe config artifact.';
            return false;
        }
        $backendReadinessSeconds = ($this->slotManifest()['test_mode'] ?? false) === true
            ? 0.5
            : 5.0;
        if (!$this->awaitActiveRouteBackendReadiness($backendReadinessSeconds)) {
            return false;
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $ports = [];
            for ($index = 0; $index < 3; ++$index) {
                $port = $this->allocateShadowPort($ports);
                if ($port < 1) {
                    $this->lastShadowVerificationError =
                        'Candidate shadow verification could not allocate isolated ports.';
                    return false;
                }
                $ports[] = $port;
            }
            $root = $this->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow'
                . DIRECTORY_SEPARATOR . $transactionId . '-' . $attempt;
            if (!@\mkdir($root, 0700, true) && !\is_dir($root)) {
                $this->lastShadowVerificationError =
                    'Candidate shadow verification could not create its isolated runtime.';
                return false;
            }
            if (!$this->createShadowPrefixDirectories($root)) {
                $this->removeShadowDirectory($root);
                $this->lastShadowVerificationError =
                    'Candidate shadow verification could not prepare Nginx runtime directories.';
                return false;
            }
            $config = $root . DIRECTORY_SEPARATOR . 'nginx.conf';
            $pidFile = $root . DIRECTORY_SEPARATOR . 'nginx.pid';
            $source = @\file_get_contents($candidate);
            if (!\is_string($source) || $source === '') {
                $this->removeShadowDirectory($root);
                $this->lastShadowVerificationError =
                    'Candidate shadow verification could not read the candidate config.';
                return false;
            }
            $shadow = $this->shadowConfig(
                $source,
                $ports[0],
                $ports[1],
                $ports[2],
                $pidFile,
                $root,
            );
            $this->atomicWrite($config, $shadow, 0600);
            $test = $this->runNginxConfig($config, $root, ['-t']);
            if (($test['code'] ?? 1) !== 0) {
                $this->lastShadowVerificationError =
                    'Candidate shadow nginx -t failed: ' . (string)($test['output'] ?? '');
                $this->removeShadowDirectory($root);
                continue;
            }
            $start = $this->runNginxConfig($config, $root, []);
            if (($start['code'] ?? 1) !== 0) {
                $this->lastShadowVerificationError =
                    'Candidate shadow Nginx failed to start: ' . (string)($start['output'] ?? '');
                $this->removeShadowDirectory($root);
                continue;
            }
            $verified = false;
            try {
                $startupDeadline = \microtime(true) + 3.0;
                while (\microtime(true) < $startupDeadline) {
                    if ($this->shadowHealthReachable(
                        $ports[0],
                        $ports[1],
                        $ports[2],
                        (int)$this->state['generation'],
                        true,
                    )) {
                        $verified = true;
                        break;
                    }
                    $this->publicationProbePause();
                }
                if (!$verified) {
                    $this->lastShadowVerificationError =
                        'Candidate shadow health endpoints did not become ready within 3 seconds.';
                    continue;
                }
                $observationSeconds = ($this->slotManifest()['test_mode'] ?? false) === true
                    ? 0.2
                    : 15.0;
                $observationDeadline = \microtime(true) + $observationSeconds;
                do {
                    if (!$this->shadowHealthReachable(
                        $ports[0],
                        $ports[1],
                        $ports[2],
                        (int)$this->state['generation'],
                        true,
                    )) {
                        $verified = false;
                        break;
                    }
                    foreach ((array)($this->state['routes'] ?? []) as $route) {
                        if (!\is_array($route)
                            || !$this->routeAllowedBySecurity($route)
                            || (string)($route['status'] ?? '') !== 'ACTIVE'
                        ) {
                            continue;
                        }
                        $this->serviceControlDuringPublicationProbe();
                        if (!$this->probePublicRoute($route, $ports[1], true)) {
                            $verified = false;
                            $this->lastShadowVerificationError =
                                'Candidate public route probe failed during the 15-second '
                                . 'stability window: ' . (string)($route['domain'] ?? 'unknown');
                            break 2;
                        }
                    }
                    $this->publicationProbePause();
                } while (\microtime(true) < $observationDeadline);
            } finally {
                $this->stopShadowDataPlane($config, $root, $pidFile);
                $this->removeShadowDirectory($root);
            }
            if ($verified) {
                $this->lastShadowVerificationError = '';
                return true;
            }
        }
        return false;
    }

    private function awaitActiveRouteBackendReadiness(float $timeoutSeconds): bool
    {
        $deadline = \microtime(true) + \max(0.1, $timeoutSeconds);
        $lastPending = '';
        do {
            $allReady = true;
            foreach ((array)($this->state['routes'] ?? []) as $route) {
                if (!\is_array($route)
                    || !$this->routeAllowedBySecurity($route)
                    || (string)($route['status'] ?? '') !== 'ACTIVE'
                ) {
                    continue;
                }
                $domain = (string)($route['domain'] ?? 'unknown');
                if (!(bool)($route['certificate']['valid'] ?? false)) {
                    $this->lastShadowVerificationError =
                        'Candidate route has no valid certificate snapshot: ' . $domain;
                    return false;
                }
                $backendInstances = $this->routeBackendInstances($route);
                if ($backendInstances === []) {
                    $this->lastShadowVerificationError =
                        'Candidate route has no active backend: ' . $domain;
                    return false;
                }
                foreach ($backendInstances as $instanceId => $backendInstance) {
                    $identity = (array)($backendInstance['backend_identity'] ?? []);
                    if (!$this->probeBackends(
                        (array)($backendInstance['backends'] ?? []),
                        $identity,
                        true,
                    )) {
                        $allReady = false;
                        $lastPending = $domain
                            . ' instance=' . (string)$instanceId
                            . ' generation=' . (int)($identity['generation'] ?? 0);
                        break 2;
                    }
                }
            }
            if ($allReady) {
                return true;
            }
            $this->publicationProbePause();
        } while (\microtime(true) < $deadline);

        $this->lastShadowVerificationError =
            'Candidate authenticated backend did not become ready within '
            . \number_format($timeoutSeconds, 1, '.', '')
            . ' seconds: ' . ($lastPending !== '' ? $lastPending : 'unknown route');
        return false;
    }

    /**
     * @param list<int> $excluded
     */
    private function allocateShadowPort(array $excluded): int
    {
        for ($attempt = 0; $attempt < 32; ++$attempt) {
            $socket = @\stream_socket_server(
                'tcp://127.0.0.1:0',
                $errno,
                $error,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            );
            if (!\is_resource($socket)) {
                continue;
            }
            $name = (string)@\stream_socket_get_name($socket, false);
            @\fclose($socket);
            $separator = \strrpos($name, ':');
            $port = $separator === false ? 0 : (int)\substr($name, $separator + 1);
            if ($port >= 1024 && !\in_array($port, $excluded, true)) {
                return $port;
            }
        }
        return 0;
    }

    private function shadowConfig(
        string $source,
        int $httpPort,
        int $httpsPort,
        int $healthPort,
        string $pidFile,
        string $root,
    ): string {
        $publicHttp = (int)$this->state['public_http'];
        $publicHttps = (int)$this->state['public_https'];
        $source = \str_replace(
            [
                'pid ' . $this->quote($this->nginxPidFile()) . ';',
                'error_log ' . $this->quote(
                    $this->logDir() . DIRECTORY_SEPARATOR . 'error.log'
                ) . ' warn;',
                'access_log ' . $this->quote(
                    $this->logDir() . DIRECTORY_SEPARATOR . 'access.log'
                ) . ';',
                'listen 127.0.0.1:' . $this->healthPort() . ';',
                'listen 0.0.0.0:' . $publicHttp,
                'listen 0.0.0.0:' . $publicHttps,
                'proxy_set_header X-Forwarded-Port ' . $publicHttps . ';',
            ],
            [
                'pid ' . $this->quote($pidFile) . ';',
                'error_log ' . $this->quote($root . DIRECTORY_SEPARATOR . 'error.log') . ' warn;',
                'access_log ' . $this->quote($root . DIRECTORY_SEPARATOR . 'access.log') . ';',
                'listen 127.0.0.1:' . $healthPort . ';',
                'listen 127.0.0.1:' . $httpPort,
                'listen 127.0.0.1:' . $httpsPort,
                'proxy_set_header X-Forwarded-Port ' . $httpsPort . ';',
            ],
            $source,
        );
        $source = (string)\preg_replace(
            '/^\s*listen \[::\]:(?:' . $publicHttp . '|' . $publicHttps
                . ')(?:\s+[^;]+)?;\R/m',
            '',
            $source,
        );
        $source = (string)\preg_replace(
            '/^\s*listen 127\.0\.0\.1:' . $httpsPort . '\s+quic(?:\s+reuseport)?;\R/m',
            '',
            $source,
        );
        $source = (string)\preg_replace(
            '/^\s*add_header Alt-Svc .*?;\R/m',
            '',
            $source,
        );
        return $source;
    }

    private function shadowHealthReachable(
        int $httpPort,
        int $httpsPort,
        int $healthPort,
        int $generation,
        bool $cooperative = false,
    ): bool {
        foreach ([$httpPort, $httpsPort] as $port) {
            if ($cooperative) {
                $this->serviceControlDuringPublicationProbe();
            }
            $socket = @\stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $error,
                0.5,
            );
            if (!\is_resource($socket)) {
                return false;
            }
            @\fclose($socket);
        }
        if ($cooperative) {
            $this->serviceControlDuringPublicationProbe();
        }
        $socket = @\stream_socket_client(
            'tcp://127.0.0.1:' . $healthPort,
            $errno,
            $error,
            0.5,
        );
        if (!\is_resource($socket)) {
            return false;
        }
        @\stream_set_timeout($socket, 1);
        @\fwrite(
            $socket,
            "GET /__wls_gateway_health HTTP/1.1\r\n"
                . "Host: localhost\r\nConnection: close\r\n\r\n",
        );
        $response = $this->readProbeResponse($socket, 262144, 1.0, $cooperative);
        @\fclose($socket);
        return \str_contains($response, '"generation":' . $generation);
    }

    private function stopShadowDataPlane(
        string $config,
        string $root,
        string $pidFile,
    ): void {
        $raw = \trim((string)@\file_get_contents($pidFile));
        if (\preg_match('/\A[0-9]+\z/D', $raw) !== 1) {
            $this->releaseWindowsNginxProcess($config);
            return;
        }
        $pid = (int)$raw;
        $tracked = $this->windowsTrackedNginxMatches($config, $pid);
        if ($tracked === null && !$this->pidRunning($pid)) {
            @\unlink($pidFile);
            $this->releaseWindowsNginxProcess($config);
            return;
        }
        $commandMatches = $tracked === true;
        if ($tracked === null) {
            $command = $this->processCommand($pid);
            if (\PHP_OS_FAMILY === 'Windows') {
                $normalizedCommand = \strtolower(\str_replace('/', '\\', $command));
                $commandMatches = $command !== ''
                    && \str_contains(
                        $normalizedCommand,
                        \strtolower(\str_replace('/', '\\', $this->nginxBinary())),
                    )
                    && \str_contains(
                        $normalizedCommand,
                        \strtolower(\str_replace('/', '\\', $config)),
                    );
            } else {
                $commandMatches = $command !== ''
                    && \str_contains($command, $this->nginxBinary())
                    && \str_contains($command, $config);
            }
        }
        if (!$commandMatches) {
            $this->terminateWindowsNginxProcess($config);
            if (!$this->pidRunning($pid)) {
                @\unlink($pidFile);
            }
            return;
        }
        $this->runNginxConfig($config, $root, ['-s', 'quit']);
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline && $this->pidRunning($pid)) {
            $this->publicationProbePause();
        }
        if (!$this->pidRunning($pid)) {
            @\unlink($pidFile);
            $this->releaseWindowsNginxProcess($config);
        } else {
            $this->terminateWindowsNginxProcess($config);
            if (!$this->pidRunning($pid)) {
                @\unlink($pidFile);
            }
        }
    }

    /**
     * @param array<string,mixed> $route
     * @return array<string,array{
     *     instance_id:string,
     *     backends:list<array<string,mixed>>,
     *     backend_identity:array<string,mixed>
     * }>
     */
    private function routeBackendInstances(array $route): array
    {
        $instances = \is_array($route['backend_instances'] ?? null)
            ? $route['backend_instances']
            : [];
        if ($instances !== []) {
            return $instances;
        }
        $instanceId = (string)($route['instance_id'] ?? '');
        $backends = \array_values((array)($route['backends'] ?? []));
        $identity = (array)($route['backend_identity'] ?? []);
        if ($instanceId === '' || $backends === [] || $identity === []) {
            return [];
        }
        return [
            $instanceId => [
                'instance_id' => $instanceId,
                'backends' => $backends,
                'backend_identity' => $identity,
            ],
        ];
    }

    private function removeShadowDirectory(string $root): void
    {
        $shadowRoot = $this->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow';
        if (!\is_dir($root)
            || \is_link($root)
            || !$this->pathInside($root, $shadowRoot)
        ) {
            return;
        }
        $pidFile = $root . DIRECTORY_SEPARATOR . 'nginx.pid';
        if (\is_file($pidFile)) {
            $raw = \trim((string)@\file_get_contents($pidFile));
            if (\preg_match('/\A[0-9]+\z/D', $raw) === 1
                && $this->pidRunning((int)$raw)
            ) {
                return;
            }
            @\unlink($pidFile);
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS,
                ),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $path = $item->getPathname();
                if ($item->isLink() || $item->isFile()) {
                    @\unlink($path);
                } elseif ($item->isDir()) {
                    @\rmdir($path);
                }
            }
        } catch (\UnexpectedValueException) {
            return;
        }
        @\rmdir($root);
        if (\is_dir($shadowRoot) && (\scandir($shadowRoot) ?: []) === ['.', '..']) {
            @\rmdir($shadowRoot);
        }
    }

    private function collectStaleShadowDirectories(): void
    {
        $shadowRoot = $this->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow';
        if (!\is_dir($shadowRoot) || \is_link($shadowRoot)) {
            return;
        }
        foreach (\scandir($shadowRoot) ?: [] as $leaf) {
            if (\preg_match('/\A[a-f0-9]{32}-[0-2]\z/D', $leaf) !== 1) {
                continue;
            }
            $this->removeShadowDirectory(
                $shadowRoot . DIRECTORY_SEPARATOR . $leaf,
            );
        }
    }

    private function createShadowPrefixDirectories(string $root): bool
    {
        $shadowRoot = $this->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow';
        if (!\is_dir($root)
            || \is_link($root)
            || !$this->pathInside($root, $shadowRoot)
        ) {
            return false;
        }
        foreach ([
            'logs',
            'temp',
            'temp' . DIRECTORY_SEPARATOR . 'client_body_temp',
            'temp' . DIRECTORY_SEPARATOR . 'proxy_temp',
            'temp' . DIRECTORY_SEPARATOR . 'fastcgi_temp',
            'temp' . DIRECTORY_SEPARATOR . 'uwsgi_temp',
            'temp' . DIRECTORY_SEPARATOR . 'scgi_temp',
        ] as $relative) {
            $directory = $root . DIRECTORY_SEPARATOR . $relative;
            if ((!@\mkdir($directory, 0700, true) && !\is_dir($directory))
                || \is_link($directory)
            ) {
                return false;
            }
            @\chmod($directory, 0700);
        }
        return true;
    }

    private function promoteLkg(): void
    {
        $generation = (int)$this->state['pending_lkg_generation'];
        if ($generation < 1 || !\is_file($this->configFile()) || \is_link($this->configFile())) {
            $this->recordFailure('Unable to persist LKG without a verified active generation/config.');
            return;
        }
        $config = @\file_get_contents($this->configFile());
        if (!\is_string($config) || $config === '') {
            $this->recordFailure('Unable to read the healthy active config for LKG promotion.');
            return;
        }
        $routes = (array)$this->state['routes'];
        $certificateDigests = $this->certificateDigestsFromRoutes($routes);
        $bundleDigest = \substr(\hash(
            'sha256',
            $generation . "\0" . \hash('sha256', $config) . "\0"
                . $this->canonicalJson($routes) . "\0" . \implode(',', $certificateDigests),
        ), 0, 16);
        $bundle = $this->lkgDir() . DIRECTORY_SEPARATOR
            . 'generation-' . $generation . '-' . $bundleDigest;
        $candidate = $this->lkgDir() . DIRECTORY_SEPARATOR
            . '.candidate-' . $generation . '-' . \bin2hex(\random_bytes(8));
        if (\file_exists($bundle) || \is_link($bundle)) {
            $existing = $this->loadLkgBundle([
                'generation' => $generation,
                'bundle_dir' => $bundle,
                'manifest_file' => $bundle . DIRECTORY_SEPARATOR . 'manifest.json',
            ]);
            if ($existing === null) {
                $this->recordFailure('Existing immutable LKG bundle failed digest verification.');
                return;
            }
        } else {
            if (!@\mkdir($candidate, 0700) || \is_link($candidate)) {
                $this->recordFailure('Unable to create the candidate LKG bundle.');
                return;
            }
            try {
                $target = $candidate . DIRECTORY_SEPARATOR . 'nginx.conf';
                $routeTarget = $candidate . DIRECTORY_SEPARATOR . 'routes.json';
                $manifestTarget = $candidate . DIRECTORY_SEPARATOR . 'manifest.json';
                $this->atomicWrite($target, $config, 0600);
                if (!$this->writeRouteSnapshot($routeTarget, $routes)) {
                    throw new \RuntimeException('Unable to persist the LKG route snapshot.');
                }
                $manifestPayload = [
                    'schema_version' => 2,
                    'generation' => $generation,
                    'config_file' => 'nginx.conf',
                    'route_file' => 'routes.json',
                    'config_sha256' => $this->fileHash($target),
                    'route_sha256' => $this->fileHash($routeTarget),
                    'certificate_digests' => $certificateDigests,
                    'created_at' => \gmdate(DATE_ATOM),
                ];
                $this->atomicWrite(
                    $manifestTarget,
                    (string)\json_encode([
                        'payload' => $manifestPayload,
                        'sha256' => \hash(
                            'sha256',
                            $this->canonicalJson($manifestPayload),
                        ),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    0600,
                );
                if (!@\rename($candidate, $bundle)) {
                    throw new \RuntimeException('Unable to atomically activate the LKG bundle.');
                }
            } catch (\Throwable $throwable) {
                $this->removeLkgBundle($candidate);
                $this->recordFailure(
                    'Unable to persist the healthy LKG bundle: ' . $throwable->getMessage()
                );
                return;
            }
        }
        $loaded = $this->loadLkgBundle([
            'generation' => $generation,
            'bundle_dir' => $bundle,
            'manifest_file' => $bundle . DIRECTORY_SEPARATOR . 'manifest.json',
        ]);
        if ($loaded === null) {
            $this->recordFailure('Activated LKG bundle failed post-publication verification.');
            return;
        }
        $lkg = (array)$this->state['lkg'];
        \array_unshift($lkg, [
            'generation' => $generation,
            'bundle_dir' => $bundle,
            'manifest_file' => $bundle . DIRECTORY_SEPARATOR . 'manifest.json',
            'file' => $bundle . DIRECTORY_SEPARATOR . 'nginx.conf',
            'route_file' => $bundle . DIRECTORY_SEPARATOR . 'routes.json',
            'certificate_digests' => $certificateDigests,
            'healthy_at' => \time(),
        ]);
        $deduplicated = [];
        foreach ($lkg as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $key = (string)($entry['bundle_dir'] ?? '')
                . ':' . (int)($entry['generation'] ?? 0);
            $deduplicated[$key] ??= $entry;
        }
        $lkg = \array_values($deduplicated);
        $discarded = \array_slice($lkg, 2);
        $this->state['lkg'] = \array_slice($lkg, 0, 2);
        foreach ($discarded as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $bundleDirectory = (string)($entry['bundle_dir'] ?? '');
            if ($bundleDirectory !== '') {
                $this->removeLkgBundle($bundleDirectory);
                continue;
            }
            // Remove checkpoint-era pairs only after they age out of the two
            // retained LKG generations.
            foreach (['file', 'route_file'] as $legacyKey) {
                $file = (string)($entry[$legacyKey] ?? '');
                if ($file !== '' && $this->pathInside($file, $this->lkgDir())) {
                    @\unlink($file);
                }
            }
        }
        $this->state['pending_lkg_generation'] = 0;
        $this->state['pending_lkg_since'] = 0;
        $this->persistState();
    }

    private function rollbackToLkg(): bool
    {
        foreach ((array)$this->state['lkg'] as $lkg) {
            if (!\is_array($lkg)) {
                continue;
            }
            $bundle = $this->loadLkgBundle($lkg);
            if ($bundle === null) {
                continue;
            }
            $routes = $bundle['routes'];
            $lkgGeneration = (int)$bundle['generation'];
            $currentRoutes = $this->state['routes'];
            $currentGeneration = $this->state['generation'];
            $activated = false;
            try {
                $this->state['routes'] = $routes;
                $this->state['generation'] = $lkgGeneration;
                $candidate = $this->configDir() . DIRECTORY_SEPARATOR . 'lkg-candidate-'
                    . \bin2hex(\random_bytes(8)) . '.conf';
                $this->atomicWrite($candidate, $this->renderNginxConfig(true), 0600);
                $test = $this->runNginx(['-t', '-c', $candidate]);
                if (($test['code'] ?? 1) !== 0) {
                    $this->atomicWrite($candidate, $this->renderNginxConfig(false), 0600);
                    $test = $this->runNginx(['-t', '-c', $candidate]);
                }
                if (($test['code'] ?? 1) !== 0) {
                    @\unlink($candidate);
                    continue;
                }
                if (@\rename($candidate, $this->configFile())) {
                    @\chmod($this->configFile(), 0600);
                    $activated = true;
                }
                @\unlink($candidate);
            } finally {
                $this->state['routes'] = $currentRoutes;
                $this->state['generation'] = $currentGeneration;
            }
            if ($activated) {
                $this->state['active_config_generation'] = $lkgGeneration;
                $this->state['last_lkg_rollback_generation'] = $lkgGeneration;
                $this->state['last_lkg_rollback_at'] = \gmdate(DATE_ATOM);
                $this->persistState();
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array{generation:int,routes:array<string,array<string,mixed>>,certificate_digests:list<string>}|null
     */
    private function loadLkgBundle(array $entry): ?array
    {
        $bundle = (string)($entry['bundle_dir'] ?? '');
        if ($bundle === '') {
            // Checkpoint compatibility: the pre-bundle LKG pair is still
            // readable for one upgrade window, but it has no cert closure and
            // is therefore never considered a fully verified bundle.
            $routes = $this->loadRouteSnapshot((string)($entry['route_file'] ?? ''));
            return $routes === null ? null : [
                'generation' => (int)($entry['generation'] ?? 0),
                'routes' => $routes,
                'certificate_digests' => $this->certificateDigestsFromRoutes($routes),
            ];
        }
        if (!\is_dir($bundle)
            || \is_link($bundle)
            || !$this->pathInside($bundle, $this->lkgDir())
        ) {
            return null;
        }
        $manifestFile = (string)($entry['manifest_file']
            ?? $bundle . DIRECTORY_SEPARATOR . 'manifest.json');
        if (!\is_file($manifestFile)
            || \is_link($manifestFile)
            || !$this->pathInside($manifestFile, $bundle)
        ) {
            return null;
        }
        $manifestEnvelope = \json_decode((string)@\file_get_contents($manifestFile), true);
        $manifest = \is_array($manifestEnvelope)
            && \is_array($manifestEnvelope['payload'] ?? null)
            ? $manifestEnvelope['payload']
            : null;
        if (!\is_array($manifest)
            || (int)($manifest['schema_version'] ?? 0) !== 2
            || !\hash_equals(
                (string)($manifestEnvelope['sha256'] ?? ''),
                \hash('sha256', $this->canonicalJson($manifest)),
            )
            || (int)($manifest['generation'] ?? 0) < 1
        ) {
            return null;
        }
        $configFile = $bundle . DIRECTORY_SEPARATOR
            . (string)($manifest['config_file'] ?? '');
        $routeFile = $bundle . DIRECTORY_SEPARATOR
            . (string)($manifest['route_file'] ?? '');
        if (!\is_file($configFile)
            || \is_link($configFile)
            || !\is_file($routeFile)
            || \is_link($routeFile)
            || !$this->pathInside($configFile, $bundle)
            || !$this->pathInside($routeFile, $bundle)
            || !\hash_equals(
                (string)($manifest['config_sha256'] ?? ''),
                $this->fileHash($configFile),
            )
            || !\hash_equals(
                (string)($manifest['route_sha256'] ?? ''),
                $this->fileHash($routeFile),
            )
        ) {
            return null;
        }
        $routes = $this->loadRouteSnapshot($routeFile);
        if ($routes === null) {
            return null;
        }
        $certificateDigests = [];
        foreach ((array)($manifest['certificate_digests'] ?? []) as $digest) {
            $digest = \strtolower(\trim((string)$digest));
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                return null;
            }
            $certificateDigests[] = $digest;
        }
        $certificateDigests = \array_values(\array_unique($certificateDigests));
        \sort($certificateDigests, SORT_STRING);
        if ($certificateDigests !== $this->certificateDigestsFromRoutes($routes)) {
            return null;
        }
        return [
            'generation' => (int)$manifest['generation'],
            'routes' => $routes,
            'certificate_digests' => $certificateDigests,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $routes
     * @return list<string>
     */
    private function certificateDigestsFromRoutes(array $routes): array
    {
        $digests = [];
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                continue;
            }
            $digest = \strtolower(\trim((string)(
                $route['certificate']['snapshot_digest'] ?? ''
            )));
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1) {
                $digests[$digest] = true;
            }
        }
        $result = \array_keys($digests);
        \sort($result, SORT_STRING);
        return $result;
    }

    private function removeLkgBundle(string $bundle): void
    {
        if ($bundle === ''
            || !\is_dir($bundle)
            || \is_link($bundle)
            || !$this->pathInside($bundle, $this->lkgDir())
        ) {
            return;
        }
        foreach (\scandir($bundle) ?: [] as $leaf) {
            if ($leaf === '.' || $leaf === '..') {
                continue;
            }
            $path = $bundle . DIRECTORY_SEPARATOR . $leaf;
            if (\is_file($path) && !\is_link($path)) {
                @\unlink($path);
            }
        }
        @\rmdir($bundle);
    }

    private function rollbackBinarySlot(): void
    {
        $previous = (string)($this->state['previous_slot'] ?? '');
        if (!\in_array($previous, ['A', 'B'], true)
            || !\is_file($this->slotDir($previous) . DIRECTORY_SEPARATOR
                . 'bin' . DIRECTORY_SEPARATOR . $this->nginxBinaryName())
        ) {
            return;
        }
        $current = (string)$this->state['active_slot'];
        $this->state['recovery']['stage'] = 'BINARY_ROLLBACK';
        $this->atomicWrite(
            $this->stateDir() . DIRECTORY_SEPARATOR . 'upgrade-rollback.request',
            "WLS-UPGRADE-ROLLBACK/1\nfrom={$current}\nto={$previous}\n"
                . 'at=' . \time() . "\nnonce=" . \bin2hex(\random_bytes(16)) . "\n",
            0600,
        );
        $this->journal('binary_rollback_requested', [
            'from' => $current,
            'to' => $previous,
        ]);
        $this->persistState();
        // The root stable launcher owns active-slot. Exiting causes the Broker
        // and platform supervisor to restart through the signed rollback
        // reconciliation path.
        $this->running = false;
    }

    private function reconcileActiveRuntimeSlot(): void
    {
        $active = \strtoupper(\trim((string)@\file_get_contents(
            $this->activeSlotFile(),
        )));
        if (!\in_array($active, ['A', 'B'], true)
            || !\is_file($this->slotDir($active) . DIRECTORY_SEPARATOR . 'manifest.json')
            || \is_link($this->slotDir($active) . DIRECTORY_SEPARATOR . 'manifest.json')
        ) {
            throw new \RuntimeException(
                'Root-owned active gateway slot is missing or invalid.'
            );
        }
        $previous = \strtoupper(\trim((string)@\file_get_contents(
            $this->trustDir() . DIRECTORY_SEPARATOR . 'previous-slot',
        )));
        if (!\in_array($previous, ['A', 'B'], true) || $previous === $active) {
            $previous = $active === 'A' ? 'B' : 'A';
        }
        if (\hash_equals($active, (string)($this->state['active_slot'] ?? ''))
            && \hash_equals($previous, (string)($this->state['previous_slot'] ?? ''))
        ) {
            return;
        }
        $from = (string)($this->state['active_slot'] ?? '');
        $this->state['active_slot'] = $active;
        $this->state['previous_slot'] = $previous;
        $this->state['binary_healthy_since'] = 0;
        $this->state['ready'] = false;
        $this->state['recovery']['stage'] = 'BINARY_UPGRADE';
        $this->persistState();
        if (!$this->journal('runtime_slot_reconciled', [
            'from' => $from,
            'to' => $active,
            'previous' => $previous,
        ])) {
            throw new \RuntimeException(
                'Unable to audit the root-owned runtime slot transition.'
            );
        }
    }

    private function renderNginxConfig(bool $allowH3): string
    {
        $this->ensureNeutralCertificate();
        $this->refreshH3CapabilityForActiveRuntime();
        $generation = (int)$this->state['generation'];
        $httpPort = (int)$this->state['public_http'];
        $httpsPort = (int)$this->state['public_https'];
        $pid = $this->quote($this->nginxPidFile());
        $errorLog = $this->quote($this->logDir() . DIRECTORY_SEPARATOR . 'error.log');
        $neutralCert = $this->quote($this->stateDir() . DIRECTORY_SEPARATOR . 'neutral-cert.pem');
        $neutralKey = $this->quote($this->stateDir() . DIRECTORY_SEPARATOR . 'neutral-key.pem');
        $h3 = $allowH3
            && (bool)($this->state['h3_capable'] ?? false)
            && !$this->h3QuarantinedForActiveRuntime();

        $lines = [
            'worker_processes auto;',
            ...(\PHP_OS_FAMILY === 'Windows'
                ? []
                : ['worker_rlimit_nofile 65536;']),
            'pid ' . $pid . ';',
            'error_log ' . $errorLog . ' warn;',
            'events { worker_connections 32768; multi_accept on; }',
            'http {',
            '  include ' . $this->quote($this->mimeTypesFile()) . ';',
            '  default_type application/octet-stream;',
            // Per-request host logs are unbounded and can take down the shared
            // edge by exhausting its filesystem. Control-plane audit and
            // warning/error logs remain enabled; request logging is opt-in at
            // an external bounded collector.
            '  access_log off;',
            // OpenSSL/Nginx can accept a TLS 1.3 ticket before the selected
            // virtual server's per-route key context forms a trustworthy
            // tenant boundary. Disable resumption globally until the edge can
            // prove route + certificate-generation binding at handshake time.
            '  ssl_session_cache off;',
            '  ssl_session_tickets off;',
            '  sendfile on;',
            '  keepalive_timeout 65;',
            // Preserve ordinary HTTP/1.1 upstream keepalive. Only WebSocket
            // handshakes may emit "Connection: upgrade"; sending "close" for
            // every non-upgrade request defeats the upstream keepalive pool
            // and creates a two-hop TCP connection storm under HTTP/2 bursts.
            '  map $http_upgrade $connection_upgrade { default upgrade; "" ""; }',
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

        /**
         * Each instance keeps a separate upstream and authenticated identity.
         * Sharing one upstream across instances would send the preferred
         * instance token/generation to every backend and either fail closed or
         * silently defeat the instance fence.
         *
         * @var array<string,list<array{
         *     instance_id:string,
         *     upstream:string,
         *     identity:array<string,mixed>
         * }>> $routeTransports
         */
        $routeTransports = [];
        /** @var array<string,list<array<string,mixed>>> $upstreamBackends */
        $upstreamBackends = [];
        foreach ((array)$this->state['routes'] as $route) {
            if (!\is_array($route)
                || !$this->routeAllowedBySecurity($route)
                || (string)($route['status'] ?? '') === 'REMOVED'
                || (array)($route['backends'] ?? []) === []
            ) {
                continue;
            }
            $routeId = (string)$route['route_id'];
            $backendInstances = \is_array($route['backend_instances'] ?? null)
                ? $route['backend_instances']
                : [];
            if ($backendInstances === []) {
                $backendInstances[(string)($route['instance_id'] ?? '')] = [
                    'instance_id' => (string)($route['instance_id'] ?? ''),
                    'backends' => (array)$route['backends'],
                    'backend_identity' => (array)($route['backend_identity'] ?? []),
                ];
            }
            foreach ($backendInstances as $instanceId => $backendInstance) {
                if (!\is_array($backendInstance)) {
                    continue;
                }
                $backends = \array_values((array)($backendInstance['backends'] ?? []));
                $backendIdentity = \is_array($backendInstance['backend_identity'] ?? null)
                    ? $backendInstance['backend_identity']
                    : [];
                if ($backends === [] || $backendIdentity === []) {
                    continue;
                }
                $instanceId = (string)$instanceId;
                $upstreamDigest = \hash('sha256', $this->canonicalJson([
                    'project_uuid' => (string)($route['project_uuid'] ?? ''),
                    'instance_id' => $instanceId,
                    'backends' => $backends,
                    'backend_identity' => [
                        'instance_id' => (string)($backendIdentity['instance_id'] ?? ''),
                        'generation' => (int)($backendIdentity['generation'] ?? 0),
                        'edge_capability_digest' => (string)(
                            $backendIdentity['edge_capability_digest'] ?? ''
                        ),
                    ],
                ]));
                $upstream = 'wls_backend_' . \substr($upstreamDigest, 0, 32);
                $routeTransports[$routeId][] = [
                    'instance_id' => $instanceId,
                    'upstream' => $upstream,
                    'identity' => $backendIdentity,
                ];
                $upstreamBackends[$upstream] ??= $backends;
            }
        }

        /**
         * Values used by each route's proxy directives. In distributed mode,
         * split_clients selects one authenticated transport per request, and
         * the maps switch all fencing fields as one tuple.
         *
         * @var array<string,array{
         *     upstream:string,
         *     token:string,
         *     instance_id:string,
         *     generation:string
         * }> $routeRoutingValues
         */
        $routeRoutingValues = [];
        foreach ($routeTransports as $routeId => $transports) {
            if (\count($transports) === 1) {
                $identity = (array)$transports[0]['identity'];
                $routeRoutingValues[$routeId] = [
                    'upstream' => (string)$transports[0]['upstream'],
                    'token' => $this->quote(
                        (string)($identity['edge_capability_secret'] ?? ''),
                    ),
                    'instance_id' => $this->quote(
                        (string)$transports[0]['instance_id'],
                    ),
                    'generation' => (string)(int)($identity['generation'] ?? 0),
                ];
                continue;
            }
            $prefix = 'wls_route_' . \substr(\hash('sha256', $routeId), 0, 16);
            $selector = '$' . $prefix . '_selector';
            $lines[] = '  split_clients "$request_id" ' . $selector . ' {';
            $percentage = \floor(10000 / \count($transports)) / 100;
            foreach ($transports as $index => $transport) {
                $bucket = '"' . $index . '"';
                $lines[] = $index === \array_key_last($transports)
                    ? '    * ' . $bucket . ';'
                    : '    ' . \number_format($percentage, 2, '.', '')
                        . '% ' . $bucket . ';';
            }
            $lines[] = '  }';
            foreach ([
                'upstream' => static fn (array $transport): string =>
                    (string)$transport['upstream'],
                'token' => fn (array $transport): string => $this->quote(
                    (string)($transport['identity']['edge_capability_secret'] ?? ''),
                ),
                'instance' => fn (array $transport): string => $this->quote(
                    (string)$transport['instance_id'],
                ),
                'generation' => static fn (array $transport): string =>
                    (string)(int)($transport['identity']['generation'] ?? 0),
            ] as $suffix => $value) {
                $variable = '$' . $prefix . '_' . $suffix;
                $lines[] = '  map ' . $selector . ' ' . $variable . ' {';
                $lines[] = '    default ' . $value($transports[0]) . ';';
                foreach ($transports as $index => $transport) {
                    $lines[] = '    "' . $index . '" ' . $value($transport) . ';';
                }
                $lines[] = '  }';
            }
            $routeRoutingValues[$routeId] = [
                'upstream' => '$' . $prefix . '_upstream',
                'token' => '$' . $prefix . '_token',
                'instance_id' => '$' . $prefix . '_instance',
                'generation' => '$' . $prefix . '_generation',
            ];
        }
        foreach ($upstreamBackends as $upstream => $backends) {
            $lines[] = '  upstream ' . $upstream . ' {';
            // Nginx keeps this cache per Worker. A route-sized pool of 256 can
            // permanently consume the POSIX Dispatcher's entire select() FD
            // budget. Share one bounded pool per authenticated backend
            // identity and retire idle connections promptly.
            $lines[] = '    keepalive 32;';
            $lines[] = '    keepalive_timeout 10s;';
            $lines[] = '    keepalive_requests 10000;';
            foreach ($backends as $backend) {
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
        }

        $h3ListenerDeclared = false;
        foreach ((array)$this->state['routes'] as $route) {
            if (!\is_array($route)
                || !$this->routeAllowedBySecurity($route)
                || (string)($route['status'] ?? '') === 'REMOVED'
            ) {
                continue;
            }
            $routeId = (string)$route['route_id'];
            $domain = (string)$route['domain'];
            $status = (string)$route['status'];
            $routing = $routeRoutingValues[$routeId] ?? [
                'upstream' => '',
                'token' => '""',
                'instance_id' => '""',
                'generation' => '0',
            ];
            $upstream = (string)$routing['upstream'];
            $lines[] = '  server {';
            $lines[] = '    listen 0.0.0.0:' . $httpPort . ';';
            $lines[] = '    listen [::]:' . $httpPort . ';';
            $lines[] = '    server_name ' . $domain . ';';
            foreach ($this->activeAcmeChallenges($route) as $challenge) {
                $lines[] = '    location = /.well-known/acme-challenge/'
                    . (string)$challenge['token'] . ' {';
                $lines[] = '      default_type text/plain;';
                $lines[] = '      return 200 '
                    . $this->quote((string)$challenge['key_authorization']) . ';';
                $lines[] = '    }';
            }
            if ($status === 'PENDING_CERTIFICATE') {
                $lines[] = '    location / { return 404; }';
            } elseif ($status === 'ACTIVE') {
                $lines[] = '    location / { return 308 https://$host$request_uri; }';
            } else {
                $lines[] = '    return 503;';
            }
            $lines[] = '  }';
            $certificate = (array)$route['certificate'];
            $certificatePath = (string)($certificate['cert_path'] ?? '');
            $privateKeyPath = (string)($certificate['key_path'] ?? '');
            if (!(bool)($certificate['valid'] ?? false)
                || $certificatePath === ''
                || $privateKeyPath === ''
                || !\is_file($certificatePath)
                || !\is_file($privateKeyPath)
                || \is_link($certificatePath)
                || \is_link($privateKeyPath)
            ) {
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
            $lines[] = '    ssl_early_data off;';
            $lines[] = '    ssl_certificate '
                . $this->quote($certificatePath) . ';';
            $lines[] = '    ssl_certificate_key '
                . $this->quote($privateKeyPath) . ';';
            $lines[] = '    server_name ' . $domain . ';';
            $lines[] = '    if ($ssl_server_name != $host) { return 421; }';
            if ($h3) {
                $lines[] = '    add_header Alt-Svc \'h3=":' . $httpsPort . '"; ma=3600\' always;';
            }
            if ($status !== 'ACTIVE') {
                $lines[] = '    return 503;';
            } else {
                // The public benchmark contract needs the two-byte liveness
                // response, but must never expose detail=1 identity metadata.
                // Drop all query parameters and pin the authenticated project
                // capability before forwarding the exact health path.
                $lines[] = '    location = /_wls/health {';
                $lines[] = '      if ($args != "") { return 404; }';
                $lines[] = '      proxy_http_version 1.1;';
                $lines[] = '      proxy_set_header Host localhost;';
                $lines[] = '      proxy_set_header Forwarded "";';
                $lines[] = '      proxy_set_header X-Forwarded-For $remote_addr;';
                $lines[] = '      proxy_set_header X-WLS-Edge-Token '
                    . (string)$routing['token'] . ';';
                $lines[] = '      proxy_set_header X-WLS-Client-Protocol $server_protocol;';
                $lines[] = '      proxy_set_header Connection "";';
                $lines[] = '      proxy_hide_header X-WLS-Project-UUID;';
                $lines[] = '      proxy_hide_header X-WLS-Instance-ID;';
                $lines[] = '      proxy_hide_header X-WLS-Backend-Generation;';
                $lines[] = '      proxy_hide_header X-WLS-Probe-Nonce;';
                $lines[] = '      proxy_pass http://' . $upstream . '/_wls/health?;';
                $lines[] = '    }';
                $lines[] = '    location = /__wls_gateway_sentinel {';
                $lines[] = '      if ($arg_nonce !~ "^[a-f0-9]{32}$") { return 404; }';
                $lines[] = '      proxy_http_version 1.1;';
                $lines[] = '      proxy_set_header Host localhost;';
                $lines[] = '      proxy_set_header Forwarded "";';
                $lines[] = '      proxy_set_header X-Forwarded-For $remote_addr;';
                $lines[] = '      proxy_set_header X-WLS-Edge-Token '
                    . (string)$routing['token'] . ';';
                $lines[] = '      proxy_set_header X-WLS-Client-Protocol $server_protocol;';
                $lines[] = '      proxy_hide_header X-WLS-Project-UUID;';
                $lines[] = '      proxy_hide_header X-WLS-Instance-ID;';
                $lines[] = '      proxy_hide_header X-WLS-Backend-Generation;';
                $lines[] = '      proxy_hide_header X-WLS-Probe-Nonce;';
                $lines[] = '      add_header X-WLS-Project-UUID '
                    . $this->quote((string)$route['project_uuid']) . ' always;';
                $lines[] = '      add_header X-WLS-Instance-ID '
                    . (string)$routing['instance_id'] . ' always;';
                $lines[] = '      add_header X-WLS-Backend-Generation '
                    . (string)$routing['generation'] . ' always;';
                $lines[] = '      add_header X-WLS-Probe-Nonce $arg_nonce always;';
                $lines[] = '      proxy_pass http://' . $upstream
                    . '/_wls/health?detail=1&gateway=1&nonce=$arg_nonce;';
                $lines[] = '    }';
                $lines[] = '    location / {';
                $lines[] = '      proxy_http_version 1.1;';
                $lines[] = '      proxy_set_header Host $host;';
                $lines[] = '      proxy_set_header Forwarded "";';
                $lines[] = '      proxy_set_header X-Forwarded-Proto https;';
                $lines[] = '      proxy_set_header X-Forwarded-For $remote_addr;';
                $lines[] = '      proxy_set_header X-Forwarded-Host $host;';
                $lines[] = '      proxy_set_header X-Forwarded-Port ' . $httpsPort . ';';
                $lines[] = '      proxy_set_header X-Real-IP $remote_addr;';
                $lines[] = '      proxy_set_header Upgrade $http_upgrade;';
                $lines[] = '      proxy_set_header Connection $connection_upgrade;';
                $lines[] = '      proxy_set_header X-WLS-Edge-Protocol wls-edge/2;';
                $lines[] = '      proxy_set_header X-WLS-Edge-Token '
                    . (string)$routing['token'] . ';';
                $lines[] = '      proxy_set_header X-WLS-Client-Protocol $server_protocol;';
                $lines[] = '      proxy_set_header X-WLS-Project-UUID '
                    . $this->quote((string)$route['project_uuid']) . ';';
                $lines[] = '      proxy_set_header X-WLS-Instance-ID '
                    . (string)$routing['instance_id'] . ';';
                $lines[] = '      proxy_set_header X-WLS-Backend-Generation '
                    . (string)$routing['generation'] . ';';
                $lines[] = '      proxy_buffering off;';
                $lines[] = '      proxy_read_timeout 3600s;';
                $lines[] = '      proxy_send_timeout 3600s;';
                $lines[] = '      proxy_pass http://' . $upstream . ';';
                $lines[] = '    }';
            }
            $lines[] = '  }';
        }
        $lines[] = '}';
        if ((string)($this->slotManifest()['listen_profile'] ?? 'default') === 'ipv4-only') {
            $lines = \array_values(\array_filter(
                $lines,
                static fn (string $line): bool => !\str_starts_with(
                    \ltrim($line),
                    'listen [::]:',
                ),
            ));
        }
        $this->state['h3_enabled'] = $h3;
        return \implode("\n", $lines) . "\n";
    }

    private function activeRuntimeGeneration(): string
    {
        return \strtolower(\trim((string)($this->slotManifest()['runtime_generation'] ?? '')));
    }

    private function refreshH3CapabilityForActiveRuntime(): void
    {
        $runtimeGeneration = $this->activeRuntimeGeneration();
        if ($runtimeGeneration === ''
            || \hash_equals(
                $runtimeGeneration,
                (string)($this->state['h3_capability_runtime_generation'] ?? ''),
            )
        ) {
            return;
        }
        $capabilities = $this->probeBinaryCapabilities($this->nginxBinary());
        $this->state['h3_capable'] = (bool)($capabilities['h3'] ?? false);
        $this->state['h3_capability_runtime_generation'] = $runtimeGeneration;
        $this->state['h3_enabled'] = false;
        if (!$this->h3QuarantinedForActiveRuntime()) {
            $this->state['h3_reason'] = (string)($capabilities['reason'] ?? '');
        }
    }

    private function h3QuarantinedForActiveRuntime(): bool
    {
        $active = $this->activeRuntimeGeneration();
        $quarantined = \strtolower(\trim(
            (string)($this->state['h3_quarantined_runtime_generation'] ?? ''),
        ));
        return $active !== ''
            && $quarantined !== ''
            && \hash_equals($active, $quarantined);
    }

    private function quarantineH3ForActiveRuntime(string $reason): void
    {
        $this->state['h3_enabled'] = false;
        $this->state['h3_reason'] = $reason;
        $this->state['h3_quarantined_runtime_generation'] = $this->activeRuntimeGeneration();
    }

    /**
     * @param array<string,mixed> $route
     * @return list<array<string,mixed>>
     */
    private function activeAcmeChallenges(array $route): array
    {
        $active = [];
        $now = \time();
        foreach ((array)($this->state['acme_challenges'] ?? []) as $challenge) {
            if (!\is_array($challenge)
                || (int)($challenge['expires_at'] ?? 0) <= $now
                || !\hash_equals(
                    (string)($route['project_uuid'] ?? ''),
                    (string)($challenge['project_uuid'] ?? ''),
                )
                || !\hash_equals(
                    (string)($route['domain'] ?? ''),
                    (string)($challenge['domain'] ?? ''),
                )
            ) {
                continue;
            }
            $active[] = $challenge;
        }
        \usort(
            $active,
            static fn (array $left, array $right): int => \strcmp(
                (string)($left['token'] ?? ''),
                (string)($right['token'] ?? ''),
            ),
        );
        return $active;
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
        $rawPid = \trim((string)@\file_get_contents($this->nginxPidFile()));
        if (\preg_match('/\A[0-9]+\z/D', $rawPid) === 1
            && $this->windowsTrackedNginxMatches($this->configFile(), (int)$rawPid) === true
        ) {
            $this->runNginx(['-s', 'quit']);
            $deadline = \microtime(true) + 5.0;
            while (\microtime(true) < $deadline
                && $this->windowsTrackedNginxMatches($this->configFile(), (int)$rawPid) === true
            ) {
                \usleep(100000);
            }
        }
        $this->terminateWindowsNginxProcess($this->configFile());
        @\unlink($this->nginxPidFile());
        return [
            'ok' => false,
            'message' => 'Nginx did not publish a verified PID: '
                . (string)($status['message'] ?? 'status unavailable'),
        ];
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
            $this->releaseWindowsNginxProcess($this->configFile());
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
                $this->releaseWindowsNginxProcess($this->configFile());
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
        if (\preg_match('/\A[0-9]+\z/D', $raw) !== 1) {
            return ['ok' => true, 'running' => false, 'pid' => null, 'message' => 'not running'];
        }
        $pid = (int)$raw;
        $tracked = $this->windowsTrackedNginxMatches($this->configFile(), $pid);
        $brokerAdopted = \PHP_OS_FAMILY === 'Windows'
            && $this->brokerAdoptedNginxPid !== null
            && $this->brokerAdoptedNginxPid === $pid;
        if ($tracked === null && !$brokerAdopted && !$this->pidRunning($pid)) {
            return ['ok' => true, 'running' => false, 'pid' => $pid, 'message' => 'stale pid'];
        }
        $binary = $this->nginxBinary();
        $expectedHash = (string)($this->slotManifest()['components'][
            'bin/' . $this->nginxBinaryName()
        ]['sha256'] ?? '');
        if ($expectedHash === '') {
            return ['ok' => false, 'running' => false, 'pid' => $pid, 'message' => 'binary digest missing from release manifest'];
        }
        $actualHash = $this->fileHash($binary);
        if ($actualHash === '') {
            return ['ok' => false, 'running' => false, 'pid' => $pid, 'message' => 'binary digest unavailable'];
        }
        if (!\hash_equals($expectedHash, $actualHash)) {
            return ['ok' => false, 'running' => false, 'pid' => $pid, 'message' => 'binary digest mismatch'];
        }
        if ($tracked === false) {
            return [
                'ok' => false,
                'running' => false,
                'pid' => $pid,
                'message' => 'tracked process PID mismatch',
                'service_tree_restart_required' => true,
            ];
        }
        if ($tracked === null && !$brokerAdopted) {
            $command = $this->processCommand($pid);
            if (\PHP_OS_FAMILY === 'Windows') {
                $normalizedCommand = \strtolower(\str_replace('/', '\\', $command));
                $identityMatches = $command !== ''
                    && \str_contains(
                        $normalizedCommand,
                        \strtolower(\str_replace('/', '\\', $binary)),
                    )
                    && \str_contains(
                        $normalizedCommand,
                        \strtolower(\str_replace('/', '\\', $this->configFile())),
                    );
            } else {
                $identityMatches = $command !== '' && \str_contains($command, $binary);
            }
            if (!$identityMatches) {
                return ['ok' => false, 'running' => false, 'pid' => $pid, 'message' => 'adopted process identity mismatch'];
            }
        }
        return ['ok' => true, 'running' => true, 'pid' => $pid, 'message' => 'running'];
    }

    private function publicPortsReachable(
        ?int $expectedGeneration = null,
        bool $cooperative = false,
    ): bool
    {
        $hosts = ['127.0.0.1'];
        if ((string)($this->slotManifest()['listen_profile'] ?? 'default') !== 'ipv4-only') {
            $hosts[] = '::1';
        }
        foreach ($hosts as $host) {
            foreach ([(int)$this->state['public_http'], (int)$this->state['public_https']] as $port) {
                if ($cooperative) {
                    $this->serviceControlDuringPublicationProbe();
                }
                $target = $host === '::1'
                    ? 'tcp://[::1]:' . $port
                    : 'tcp://127.0.0.1:' . $port;
                $socket = @\stream_socket_client($target, $errno, $error, 0.5);
                if (!\is_resource($socket)) {
                    return false;
                }
                @\fclose($socket);
            }
        }
        if ($cooperative) {
            $this->serviceControlDuringPublicationProbe();
        }
        $socket = @\stream_socket_client('tcp://127.0.0.1:' . $this->healthPort(), $errno, $error, 0.5);
        if (!\is_resource($socket)) {
            return false;
        }
        \fwrite($socket, "GET /__wls_gateway_health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $response = $this->readProbeResponse($socket, 262144, 1.0, $cooperative);
        @\fclose($socket);
        $expectedGeneration ??= (int)($this->state['active_config_generation'] ?? 0);
        return \str_contains($response, '"generation":' . $expectedGeneration);
    }

    private function publicRoutesReachable(bool $cooperative = false): bool
    {
        foreach ((array)($this->state['routes'] ?? []) as $route) {
            if (!\is_array($route)
                || !$this->routeAllowedBySecurity($route)
                || (string)($route['status'] ?? '') !== 'ACTIVE'
            ) {
                continue;
            }
            if ($cooperative) {
                $this->serviceControlDuringPublicationProbe();
            }
            if (!$this->probePublicRoute($route, null, $cooperative)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $route
     */
    private function probePublicRoute(
        array $route,
        ?int $httpsPort = null,
        bool $cooperative = false,
    ): bool
    {
        $domain = (string)($route['domain'] ?? '');
        if (\str_starts_with($domain, '*.')) {
            $domain = 'wls-probe.' . \substr($domain, 2);
        }
        $certificate = (array)($route['certificate'] ?? []);
        $expectedCertificate = @\openssl_x509_read((string)@\file_get_contents(
            (string)($certificate['cert_path'] ?? ''),
        ));
        if ($domain === ''
            || $expectedCertificate === false
            || (string)($route['project_uuid'] ?? '') === ''
            || $this->routeBackendInstances($route) === []
        ) {
            return false;
        }
        $context = \stream_context_create([
            'ssl' => [
                'peer_name' => $domain,
                'SNI_enabled' => true,
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'disable_compression' => true,
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);
        $socket = @\stream_socket_client(
            'tls://127.0.0.1:' . ($httpsPort ?? (int)$this->state['public_https']),
            $errno,
            $error,
            1.0,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!\is_resource($socket)) {
            return false;
        }
        if ($cooperative) {
            $this->serviceControlDuringPublicationProbe();
        }
        $params = \stream_context_get_params($socket);
        $peerCertificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $expectedFingerprint = (\is_resource($expectedCertificate)
            || $expectedCertificate instanceof \OpenSSLCertificate)
                ? (string)@\openssl_x509_fingerprint($expectedCertificate, 'sha256')
                : '';
        $peerFingerprint = (\is_resource($peerCertificate)
            || $peerCertificate instanceof \OpenSSLCertificate)
                ? (string)@\openssl_x509_fingerprint($peerCertificate, 'sha256')
                : '';
        if ($expectedFingerprint === ''
            || $peerFingerprint === ''
            || !\hash_equals(\strtolower($expectedFingerprint), \strtolower($peerFingerprint))
        ) {
            @\fclose($socket);
            return false;
        }
        $nonce = \bin2hex(\random_bytes(16));
        $request = "GET /__wls_gateway_sentinel?nonce={$nonce} HTTP/1.1\r\n"
            . "Host: {$domain}\r\nConnection: close\r\nCache-Control: no-store\r\n\r\n";
        if (@\fwrite($socket, $request) !== \strlen($request)) {
            @\fclose($socket);
            return false;
        }
        $response = $this->readProbeResponse($socket, 262144, 2.0, $cooperative);
        @\fclose($socket);
        [$headerBlock, $body] = \array_pad(\explode("\r\n\r\n", $response, 2), 2, '');
        if (!\str_starts_with($headerBlock, 'HTTP/1.1 200 ')) {
            return false;
        }
        $headers = [];
        foreach (\array_slice(\explode("\r\n", $headerBlock), 1) as $line) {
            if (!\str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = \explode(':', $line, 2);
            $headers[\strtolower(\trim($name))] = \trim($value);
        }
        $health = $body !== '' ? \json_decode($body, true) : null;
        $instanceId = (string)($headers['x-wls-instance-id'] ?? '');
        $backendInstance = $this->routeBackendInstances($route)[$instanceId] ?? null;
        $identity = \is_array($backendInstance)
            ? (array)($backendInstance['backend_identity'] ?? [])
            : [];
        return \is_array($health)
            && $identity !== []
            && \hash_equals(
                (string)$route['project_uuid'],
                (string)($headers['x-wls-project-uuid'] ?? ''),
            )
            && (int)($identity['generation'] ?? 0)
                === (int)($headers['x-wls-backend-generation'] ?? 0)
            && \hash_equals($nonce, (string)($headers['x-wls-probe-nonce'] ?? ''))
            && \hash_equals($instanceId, (string)($health['instance'] ?? ''))
            && \hash_equals((string)($identity['launch_id'] ?? ''), (string)($health['launch_id'] ?? ''))
            && (int)($identity['master_epoch'] ?? 0) === (int)($health['master_epoch'] ?? 0)
            && \hash_equals($nonce, (string)($health['nonce'] ?? ''));
    }

    /**
     * @param resource $socket
     */
    private function readProbeResponse(
        $socket,
        int $maximumBytes,
        float $timeoutSeconds,
        bool $cooperative,
    ): string {
        $maximumBytes = \max(1, $maximumBytes);
        $deadline = \microtime(true) + \max(0.1, $timeoutSeconds);
        $response = '';
        @\stream_set_blocking($socket, false);
        while (\strlen($response) < $maximumBytes && \microtime(true) < $deadline) {
            if ($cooperative) {
                $this->serviceControlDuringPublicationProbe();
            }
            $remaining = \max(0.0, $deadline - \microtime(true));
            $waitMicros = (int)\min(100000, \ceil($remaining * 1_000_000));
            $read = [$socket];
            $write = [];
            $except = [];
            $selected = @\stream_select($read, $write, $except, 0, $waitMicros);
            if ($selected === false) {
                break;
            }
            if ($selected === 0) {
                continue;
            }
            $chunk = @\fread($socket, \min(8192, $maximumBytes - \strlen($response)));
            if (!\is_string($chunk)) {
                break;
            }
            if ($chunk === '') {
                if (\feof($socket)) {
                    break;
                }
                continue;
            }
            $response .= $chunk;
        }
        return $response;
    }

    private function adoptOrRecoverDataPlane(): void
    {
        try {
            // A stopped data plane must never expose the previously rendered
            // ACTIVE config before persisted leases are reconciled. Otherwise
            // a controller restart after HEARTBEAT_TTL briefly serves stale
            // backends as 200, then flips to the required TLS/503 isolation.
            $this->expireLeases();
            if ($this->configDirty && !$this->publishIfDirty()) {
                throw new \RuntimeException(
                    'Gateway startup lease reconciliation could not publish its fail-closed config.'
                );
            }
        } catch (\Throwable $throwable) {
            $this->abortRoutingMutation(
                'Gateway startup lease reconciliation failed: ' . $throwable->getMessage(),
            );
            $this->stopDataPlane();
            $this->state['isolation_mode'] = true;
            $this->state['ready'] = false;
            $this->state['health_state'] = 'STARTUP_LEASE_RECONCILE_FAILED_CLOSED';
            $this->recordFailure($throwable->getMessage());
            $this->persistState();
            return;
        }
        if (($this->state['isolation_mode'] ?? false) === true
            || ($this->state['security_ledger_valid'] ?? true) !== true
        ) {
            $this->beginRoutingMutation('isolation-recovery');
            $this->configDirty = true;
            if (!$this->publishIfDirty()) {
                $this->stopDataPlane();
                $this->state['ready'] = false;
                $this->state['health_state'] = 'STATE_REBUILD_FAILED_CLOSED';
                $this->persistState();
            }
            return;
        }
        $status = $this->nginxStatus();
        if (($status['running'] ?? false) === true) {
            $this->state['health_state'] = 'CONTROL_DEGRADED';
            $this->state['ready'] = $this->publicPortsReachable()
                && $this->publicRoutesReachable();
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
     * @param array<string,mixed> $identity
     */
    private function probeBackends(
        array $backends,
        array $identity,
        bool $cooperative = false,
        ?string &$failureKind = null,
    ): bool
    {
        $hardFailure = $backends === [];
        $failureKind = 'identity';
        foreach ($backends as $backend) {
            if ($cooperative) {
                $this->serviceControlDuringPublicationProbe();
            }
            $backendFailureKind = null;
            if (\is_array($backend)
                && $this->probeBackendHealth(
                    $backend,
                    $identity,
                    $backendFailureKind,
                ) !== null
            ) {
                $failureKind = null;
                return true;
            }
            $hardFailure = $hardFailure || $backendFailureKind === 'identity';
        }
        $failureKind = $hardFailure ? 'identity' : 'transport';
        return false;
    }

    /**
     * @param array<string,mixed> $backend
     * @param array<string,mixed> $identity
     * @return array<string,mixed>|null
     */
    private function probeBackendHealth(
        array $backend,
        array $identity,
        ?string &$failureKind = null,
    ): ?array
    {
        $failureKind = 'identity';
        $secret = \strtolower(\trim((string)($identity['edge_capability_secret'] ?? '')));
        $expectedDigest = \strtolower(\trim((string)(
            $identity['edge_capability_digest'] ?? ''
        )));
        $expectedProject = \strtolower(\trim((string)(
            $identity['project_uuid'] ?? ''
        )));
        $expectedInstance = (string)($identity['instance_id'] ?? '');
        $expectedGeneration = (int)($identity['generation'] ?? 0);
        $expectedEpoch = (int)($identity['master_epoch'] ?? 0);
        $expectedLaunchId = \strtolower(\trim((string)($identity['launch_id'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $secret) !== 1
            || !\hash_equals($expectedDigest, \hash('sha256', $secret))
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $expectedProject,
            ) !== 1
            || $expectedInstance === ''
            || $expectedGeneration < 1
            || $expectedEpoch < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $expectedLaunchId) !== 1
        ) {
            return null;
        }
        $host = (string)($backend['host'] ?? '');
        $port = (int)($backend['port'] ?? 0);
        if (!\in_array($host, ['127.0.0.1', '::1'], true) || $port < 1 || $port > 65535) {
            return null;
        }
        $address = $host === '::1' ? 'tcp://[::1]:' . $port : 'tcp://' . $host . ':' . $port;
        $socket = @\stream_socket_client($address, $errno, $error, 1.0);
        if (!\is_resource($socket)) {
            $failureKind = 'transport';
            return null;
        }
        $nonce = \bin2hex(\random_bytes(16));
        \stream_set_timeout($socket, self::BACKEND_PROBE_READ_TIMEOUT);
        $request = "GET /_wls/health?detail=1&gateway=1&nonce={$nonce} HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-WLS-Edge-Token: {$secret}\r\n"
            . "X-WLS-Client-Protocol: HTTP/1.1\r\n"
            . "Connection: close\r\n\r\n";
        if (@\fwrite($socket, $request) !== \strlen($request)) {
            @\fclose($socket);
            $failureKind = 'transport';
            return null;
        }
        $response = '';
        while (!\feof($socket) && \strlen($response) <= 131072) {
            $chunk = @\fread($socket, 8192);
            if (!\is_string($chunk) || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        $socketMetadata = \stream_get_meta_data($socket);
        @\fclose($socket);
        [$headers, $body] = \array_pad(\explode("\r\n\r\n", $response, 2), 2, '');
        if (($socketMetadata['timed_out'] ?? false) === true
            || $headers === ''
        ) {
            $failureKind = 'transport';
            return null;
        }
        if (\preg_match('/\AHTTP\/1\.[01] ([0-9]{3}) /D', $headers, $status) !== 1) {
            return null;
        }
        if ((int)$status[1] !== 200) {
            $failureKind = 'transport';
            return null;
        }
        $health = $body !== '' ? \json_decode($body, true) : null;
        if (!\is_array($health)
            || ($health['edge_auth_required'] ?? false) !== true
            || !\hash_equals($expectedProject, (string)($health['project_uuid'] ?? ''))
            || !\hash_equals($expectedInstance, (string)($health['instance'] ?? ''))
            || $expectedGeneration !== (int)($health['instance_generation'] ?? 0)
            || $expectedEpoch !== (int)($health['master_epoch'] ?? 0)
            || !\hash_equals($expectedLaunchId, (string)($health['launch_id'] ?? ''))
            || !\hash_equals($expectedDigest, (string)($health['edge_capability_digest'] ?? ''))
            || !\hash_equals($nonce, (string)($health['nonce'] ?? ''))
        ) {
            return null;
        }
        $failureKind = null;
        return $health;
    }

    /**
     * @param list<array<string,mixed>> $candidateRoutes
     */
    private function assertNoDomainConflicts(string $projectUuid, array $candidateRoutes): void
    {
        foreach ($candidateRoutes as $candidate) {
            $domain = (string)$candidate['domain'];
            $ownership = $this->state['security']['tombstones']['domain:' . $domain] ?? null;
            if (\is_array($ownership)
                && !\hash_equals(
                    $projectUuid,
                    (string)($ownership['to_project_uuid'] ?? ''),
                )
            ) {
                throw new \DomainException(
                    'Domain ownership is fenced to project '
                        . (string)($ownership['to_project_uuid'] ?? '') . ': ' . $domain
                );
            }
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
    private function assertEnrollment(string $projectUuid, string $projectRoot, array $peer): void
    {
        $existing = $this->state['enrollments'][$projectUuid] ?? null;
        if (!\is_array($existing)) {
            throw new \DomainException(
                'Project registration requires an explicit administrator enrollment.'
            );
        }
        if (!\hash_equals((string)$existing['project_root'], $projectRoot)) {
            throw new \DomainException('Enrolled project UUID belongs to another project root.');
        }
        $tombstone = $this->state['security']['tombstones']['project:' . $projectUuid] ?? null;
        if (\is_array($tombstone)
            && (int)($tombstone['generation'] ?? 0)
                >= (int)($existing['security_generation'] ?? 0)
        ) {
            throw new \DomainException('Project enrollment is superseded by a security tombstone.');
        }
        $owner = \is_array($existing['owner'] ?? null) ? $existing['owner'] : [];
        if ((string)($owner['kind'] ?? '') === 'posix'
            && (int)($owner['uid'] ?? -1) !== (int)($peer['uid'] ?? -2)
        ) {
            throw new \DomainException('Project registration OS owner does not match enrollment.');
        }
        if ((string)($owner['kind'] ?? '') === 'windows'
            && !\hash_equals(
                \strtoupper((string)($owner['sid'] ?? '')),
                \strtoupper((string)($peer['sid'] ?? '')),
            )
        ) {
            throw new \DomainException('Project registration SID does not match enrollment.');
        }
    }

    /**
     * Security state is an overlay on every desired/LKG route. A route is
     * publishable only while its enrollment exists and no equal-or-newer
     * tombstone supersedes the enrollment captured at registration.
     *
     * @param array<string,mixed> $route
     */
    private function routeAllowedBySecurity(array $route): bool
    {
        if (($this->state['security_ledger_valid'] ?? true) !== true) {
            return false;
        }
        $projectUuid = \strtolower(\trim((string)($route['project_uuid'] ?? '')));
        $enrollment = $this->state['enrollments'][$projectUuid] ?? null;
        if ($projectUuid === '' || !\is_array($enrollment)) {
            return false;
        }
        $routeGeneration = (int)($route['enrollment_security_generation'] ?? 0);
        $enrollmentGeneration = (int)($enrollment['security_generation'] ?? 0);
        if ($routeGeneration > 0 && $routeGeneration !== $enrollmentGeneration) {
            return false;
        }
        $tombstone = $this->state['security']['tombstones']['project:' . $projectUuid] ?? null;
        if (\is_array($tombstone)) {
            // Legacy/LKG routes without an enrollment generation cannot prove
            // they were created after a revocation and therefore fail closed.
            if ($routeGeneration < 1
                || (int)($tombstone['generation'] ?? 0) >= $routeGeneration
            ) {
                return false;
            }
        }
        $domain = (string)($route['domain'] ?? '');
        $domainOwnership = $this->state['security']['tombstones']['domain:' . $domain] ?? null;
        if (!\is_array($domainOwnership)) {
            return true;
        }
        return \hash_equals(
            $projectUuid,
            (string)($domainOwnership['to_project_uuid'] ?? ''),
        ) && (int)($route['domain_security_generation'] ?? 0)
            >= (int)($domainOwnership['generation'] ?? PHP_INT_MAX);
    }

    private function touchInstanceLease(
        string $projectUuid,
        string $instanceId,
        int $masterEpoch,
        string $launchId,
        int $instanceGeneration,
        ?array $drainCounters = null,
    ): void
    {
        $instance = $this->state['instances'][$projectUuid][$instanceId] ?? null;
        if (!\is_array($instance)
            || (int)($instance['generation'] ?? 0) !== $instanceGeneration
            || (int)($instance['master_epoch'] ?? 0) !== $masterEpoch
            || !\hash_equals((string)($instance['launch_id'] ?? ''), $launchId)
        ) {
            throw new \DomainException('Instance lease fencing identity is stale or unknown.');
        }
        $now = \time();
        $monotonicNow = $this->monotonicNow();
        $this->state['instances'][$projectUuid][$instanceId]['last_heartbeat'] = $now;
        $this->state['instances'][$projectUuid][$instanceId]['last_heartbeat_monotonic']
            = $monotonicNow;
        $this->state['instances'][$projectUuid][$instanceId]['lease_boot_id'] = $this->hostBootId;
        if ($drainCounters !== null) {
            $drainCounters['reported_at'] = $now;
            $drainCounters['reported_monotonic'] = $monotonicNow;
            $drainCounters['lease_boot_id'] = $this->hostBootId;
            $this->state['instances'][$projectUuid][$instanceId]['drain_counters']
                = $drainCounters;
        }
        foreach ((array)$this->state['routes'] as $routeId => $route) {
            if (!\is_array($route)
                || (string)$route['project_uuid'] !== $projectUuid
                || !\is_array($route['instances'][$instanceId] ?? null)
            ) {
                continue;
            }
            $this->state['routes'][$routeId]['instances'][$instanceId]['last_heartbeat'] = $now;
            $this->state['routes'][$routeId]['instances'][$instanceId]['last_heartbeat_monotonic']
                = $monotonicNow;
            $this->state['routes'][$routeId]['instances'][$instanceId]['lease_boot_id']
                = $this->hostBootId;
        }
        // Heartbeat is deliberately state-only. It cannot reactivate STALE or
        // DRAINING routing, clear a drain fence, or trigger Nginx publication.
        // A fenced register/renew request is required for every route change.
        $this->persistState();
    }

    /**
     * @return array<string,int|bool>|null
     */
    private function normalizeDrainCounters(mixed $counters): ?array
    {
        if ($counters === null) {
            return null;
        }
        if (!\is_array($counters)
            || (int)($counters['version'] ?? 0) !== 1
            || !\is_bool($counters['counters_known'] ?? null)
        ) {
            throw new \DomainException('Heartbeat drain counters are invalid.');
        }
        $normalized = [
            'version' => 1,
            'counters_known' => (bool)$counters['counters_known'],
        ];
        foreach ([
            'worker_count',
            'reported_worker_count',
            'active_requests',
            'long_lived_connections',
            'sse_connections',
            'websocket_connections',
        ] as $field) {
            $value = $counters[$field] ?? null;
            if (!\is_int($value) || $value < 0 || $value > 1_000_000) {
                throw new \DomainException('Heartbeat drain counters are invalid.');
            }
            $normalized[$field] = $value;
        }
        if ($normalized['counters_known']) {
            if ($normalized['worker_count'] < 1
                || $normalized['reported_worker_count'] !== $normalized['worker_count']
                || $normalized['sse_connections'] > $normalized['long_lived_connections']
                || $normalized['websocket_connections'] > $normalized['long_lived_connections']
            ) {
                throw new \DomainException('Heartbeat drain counter topology is inconsistent.');
            }
        } elseif ($normalized['active_requests'] !== 0
            || $normalized['long_lived_connections'] !== 0
            || $normalized['sse_connections'] !== 0
            || $normalized['websocket_connections'] !== 0
        ) {
            throw new \DomainException('Unknown heartbeat drain counters must be zero.');
        }
        return $normalized;
    }

    /**
     * An unchanged project generation may update only the capability evidence
     * of the same live endpoint. Domain, certificate, backend, route set and
     * every other identity field remain fenced.
     *
     * @param array<string,mixed> $existingInstance
     * @param list<array<string,mixed>> $candidateRoutes
     */
    private function assertCapabilityOnlyInstanceRefresh(
        string $projectUuid,
        string $instanceId,
        array $existingInstance,
        array $candidateRoutes,
    ): void {
        $reject = static function (): never {
            throw new \DomainException(
                'Same instance generation may refresh capability evidence only.'
            );
        };
        $existingProject = \is_array($this->state['projects'][$projectUuid] ?? null)
            ? $this->state['projects'][$projectUuid]
            : [];
        $expectedRouteIds = \array_values(\array_map(
            'strval',
            (array)($existingProject['route_ids'] ?? []),
        ));
        $incomingRouteIds = \array_values(\array_map(
            static fn (array $route): string => (string)($route['route_id'] ?? ''),
            $candidateRoutes,
        ));
        \sort($expectedRouteIds, SORT_STRING);
        \sort($incomingRouteIds, SORT_STRING);
        if ($expectedRouteIds === [] || $expectedRouteIds !== $incomingRouteIds) {
            $reject();
        }

        $incomingIdentity = [];
        $incomingBackends = [];
        foreach ($candidateRoutes as $candidate) {
            $routeId = (string)($candidate['route_id'] ?? '');
            $existingRoute = \is_array($this->state['routes'][$routeId] ?? null)
                ? $this->state['routes'][$routeId]
                : [];
            $existingDesired = [
                'route_id' => $routeId,
                'domain' => (string)($existingRoute['domain'] ?? ''),
                'force_https' => (bool)($existingRoute['force_https'] ?? false),
                'certificate_source_digest' => (string)(
                    $existingRoute['certificate']['source_digest'] ?? ''
                ),
                'certificate_generation' => (int)(
                    $existingRoute['certificate']['generation'] ?? 0
                ),
            ];
            $incomingDesired = [
                'route_id' => $routeId,
                'domain' => (string)($candidate['domain'] ?? ''),
                'force_https' => (bool)($candidate['force_https'] ?? false),
                'certificate_source_digest' => (string)(
                    $candidate['certificate']['source_digest'] ?? ''
                ),
                'certificate_generation' => (int)(
                    $candidate['certificate']['generation'] ?? 0
                ),
            ];
            if (!\hash_equals(
                $this->canonicalJson($existingDesired),
                $this->canonicalJson($incomingDesired),
            )) {
                $reject();
            }
            $candidateIdentity = \is_array($candidate['backend_identity'] ?? null)
                ? $candidate['backend_identity']
                : [];
            if ($incomingIdentity === []) {
                $incomingIdentity = $candidateIdentity;
            } elseif (!\hash_equals(
                $this->canonicalJson($incomingIdentity),
                $this->canonicalJson($candidateIdentity),
            )) {
                $reject();
            }
            foreach ((array)($candidate['backends'] ?? []) as $backend) {
                if (!\is_array($backend)) {
                    $reject();
                }
                $key = (string)($backend['host'] ?? '') . ':'
                    . (int)($backend['port'] ?? 0) . ':'
                    . (int)($backend['weight'] ?? 1);
                $incomingBackends[$key] = $backend;
            }
        }
        \ksort($incomingBackends, SORT_STRING);
        $existingBackends = [];
        foreach ((array)($existingInstance['backends'] ?? []) as $backend) {
            if (!\is_array($backend)) {
                $reject();
            }
            $key = (string)($backend['host'] ?? '') . ':'
                . (int)($backend['port'] ?? 0) . ':'
                . (int)($backend['weight'] ?? 1);
            $existingBackends[$key] = $backend;
        }
        \ksort($existingBackends, SORT_STRING);
        if (!\hash_equals(
            $this->canonicalJson($existingBackends),
            $this->canonicalJson($incomingBackends),
        )) {
            $reject();
        }

        $existingIdentity = \is_array($existingInstance['backend_identity'] ?? null)
            ? $existingInstance['backend_identity']
            : [];
        $capabilityKeys = [
            'session_capability',
            'session_capability_evidence',
            'session_capability_evidence_digest',
        ];
        $existingCapability = [];
        $incomingCapability = [];
        foreach ($capabilityKeys as $key) {
            if (\array_key_exists($key, $existingIdentity)) {
                $existingCapability[$key] = $existingIdentity[$key];
            }
            if (\array_key_exists($key, $incomingIdentity)) {
                $incomingCapability[$key] = $incomingIdentity[$key];
            }
            unset($existingIdentity[$key], $incomingIdentity[$key]);
        }
        unset($existingIdentity['digest'], $incomingIdentity['digest']);
        if ($existingIdentity === []
            || !\hash_equals(
                $this->canonicalJson($existingIdentity),
                $this->canonicalJson($incomingIdentity),
            )
            || \hash_equals(
                $this->canonicalJson($existingCapability),
                $this->canonicalJson($incomingCapability),
            )
            || !\hash_equals(
                $instanceId,
                (string)($incomingIdentity['instance_id'] ?? ''),
            )
        ) {
            $reject();
        }
    }

    /**
     * A live launch may update endpoint-validated runtime identity evidence
     * without inventing a project or process generation. Both independent
     * process fences must still match exactly; route validation follows.
     *
     * @param array<string,mixed> $existingInstance
     */
    private function mayRefreshInstanceDigest(
        array $existingInstance,
        int $masterEpoch,
        string $launchId,
    ): bool {
        $existingMasterEpoch = (int)($existingInstance['master_epoch'] ?? 0);
        $existingLaunchId = \strtolower(\trim((string)($existingInstance['launch_id'] ?? '')));
        return $masterEpoch > 0
            && $existingMasterEpoch === $masterEpoch
            && \preg_match('/\A[a-f0-9]{32}\z/D', $existingLaunchId) === 1
            && \hash_equals($existingLaunchId, $launchId);
    }

    /**
     * @param array<string,mixed> $instance
     * @param array<string,mixed> $payload
     */
    private function assertInstancePayloadFence(array $instance, array $payload): void
    {
        if ((int)($instance['generation'] ?? 0) !== (int)($payload['instance_generation'] ?? 0)
            || (int)($instance['master_epoch'] ?? 0) !== (int)($payload['master_epoch'] ?? 0)
            || !\hash_equals(
                (string)($instance['launch_id'] ?? ''),
                \strtolower(\trim((string)($payload['launch_id'] ?? ''))),
            )
        ) {
            throw new \DomainException('Instance lifecycle request has stale fencing identity.');
        }
    }

    /**
     * @param array<string,mixed> $route
     */
    private function selectRouteBackends(array &$route): void
    {
        $projectUuid = (string)($route['project_uuid'] ?? '');
        $projectTombstone = $projectUuid === ''
            ? null
            : ($this->state['security']['tombstones']['project:' . $projectUuid] ?? null);
        if ((string)($route['status'] ?? '') === 'REMOVED'
            || (\is_array($projectTombstone)
                && \hash_equals(
                    $projectUuid,
                    (string)($projectTombstone['project_uuid'] ?? ''),
                ))
        ) {
            // A project revocation is an irreversible security decision. Lease
            // sweeps, backend probes and delayed lifecycle messages must never
            // repopulate routing secrets or reactivate the removed route.
            $this->enforceRemovedRoute($route);
            return;
        }

        $now = \time();
        $monotonicNow = $this->monotonicNow();
        $active = [];
        $draining = false;
        $backendPending = false;
        foreach ((array)($route['instances'] ?? []) as $instanceId => $instance) {
            if (!\is_array($instance)) {
                continue;
            }
            $status = (string)($instance['status'] ?? '');
            if ($status === 'DRAINING') {
                $draining = true;
                continue;
            }
            if ($status !== 'ACTIVE') {
                continue;
            }
            $leaseAge = $monotonicNow
                - (float)($instance['last_heartbeat_monotonic'] ?? 0.0);
            if (!$this->sameLeaseBoot($instance)
                || $leaseAge < 0.0
                || $leaseAge >= self::HEARTBEAT_TTL
            ) {
                continue;
            }
            if (!($instance['backend_healthy'] ?? false)) {
                $backendPending = true;
                continue;
            }
            $active[(string)$instanceId] = $instance;
        }
        \ksort($active, SORT_STRING);
        $selected = [];
        $selectedInstances = [];
        $preferred = '';
        $distributionMode = 'single';
        if ($active !== []) {
            $preferred = (string)\array_key_first($active);
            $distributionMode = $this->routeDistributionMode($route, $active);
            $eligibleInstances = $distributionMode === 'single'
                ? [$preferred => $active[$preferred]]
                : $active;
            foreach ($eligibleInstances as $instanceId => $instance) {
                $instanceBackends = [];
                foreach ((array)($instance['backends'] ?? []) as $backend) {
                    if (\is_array($backend)) {
                        $selected[] = $backend;
                        $instanceBackends[] = $backend;
                    }
                    if (\count($selected) >= 16) {
                        break;
                    }
                }
                if ($instanceBackends !== []) {
                    $selectedInstances[(string)$instanceId] = [
                        'instance_id' => (string)$instanceId,
                        'backends' => $instanceBackends,
                        'backend_identity' => (array)($instance['backend_identity'] ?? []),
                    ];
                }
                if (\count($selected) >= 16) {
                    break;
                }
            }
        }
        if (\count($selectedInstances) < 2) {
            $distributionMode = 'single';
        }
        $route['preferred_instance_id'] = $preferred;
        $route['instance_id'] = $preferred;
        $route['backend_identity'] = $preferred === ''
            ? []
            : (array)($active[$preferred]['backend_identity'] ?? []);
        $route['backends'] = $selected;
        $route['backend_instances'] = $selectedInstances;
        $route['distribution_mode'] = $distributionMode;
        $route['last_heartbeat'] = $active === []
            ? (int)($route['last_heartbeat'] ?? 0)
            : \max(\array_map(
                static fn (array $instance): int => (int)($instance['last_heartbeat'] ?? 0),
                $active,
            ));
        if (!(bool)($route['certificate']['valid'] ?? false)) {
            $route['status'] = 'PENDING_CERTIFICATE';
        } elseif ($selected !== []) {
            $route['status'] = 'ACTIVE';
            $route['stale_since'] = null;
            $route['stale_since_monotonic'] = null;
            $route['stale_boot_id'] = null;
        } elseif ($draining) {
            $route['status'] = 'DRAINING';
        } elseif ($backendPending) {
            $route['status'] = 'PENDING_BACKEND';
            $route['stale_since'] = null;
            $route['stale_since_monotonic'] = null;
            $route['stale_boot_id'] = null;
        } else {
            $route['status'] = 'STALE';
            if (!\hash_equals(
                $this->hostBootId,
                (string)($route['stale_boot_id'] ?? ''),
            ) || (float)($route['stale_since_monotonic'] ?? 0.0) > $monotonicNow) {
                $route['stale_since'] = $now;
                $route['stale_since_monotonic'] = $monotonicNow;
                $route['stale_boot_id'] = $this->hostBootId;
            } else {
                $route['stale_since'] ??= $now;
                $route['stale_since_monotonic'] ??= $monotonicNow;
            }
        }
    }

    /**
     * @param array<string,mixed> $route
     */
    private function enforceRemovedRoute(array &$route, ?int $removedAt = null): void
    {
        $route['status'] = 'REMOVED';
        if ((int)($route['removed_at'] ?? 0) <= 0) {
            $route['removed_at'] = $removedAt ?? \time();
        }
        $route['instances'] = [];
        $route['backends'] = [];
        $route['backend_instances'] = [];
        $route['preferred_instance_id'] = '';
        $route['instance_id'] = '';
        $route['backend_identity'] = [];
        $route['distribution_mode'] = 'single';
    }

    /**
     * Multiple WLS instances may share traffic only when the administrator
     * enrollment and every currently eligible runtime independently attest
     * the same session-safe capability. Any mixed or missing proof retains
     * the deterministic preferred-instance/hot-standby topology.
     *
     * @param array<string,mixed> $route
     * @param array<string,array<string,mixed>> $active
     */
    private function routeDistributionMode(array $route, array $active): string
    {
        if (\count($active) < 2) {
            return 'single';
        }
        $projectUuid = (string)($route['project_uuid'] ?? '');
        $enrollment = \is_array($this->state['enrollments'][$projectUuid] ?? null)
            ? $this->state['enrollments'][$projectUuid]
            : [];
        $capabilities = \is_array($enrollment['capabilities'] ?? null)
            ? $enrollment['capabilities']
            : [];
        $mode = '';
        $sharedEvidenceDigest = '';
        foreach ($active as $instance) {
            $identity = \is_array($instance['backend_identity'] ?? null)
                ? $instance['backend_identity']
                : [];
            $instanceMode = (string)($identity['session_capability'] ?? 'isolated');
            if (!\in_array($instanceMode, ['stateless', 'shared_session'], true)
                || ($capabilities[$instanceMode] ?? false) !== true
                || !$this->validStoredDistributionCapability($identity, $instanceMode)
            ) {
                return 'single';
            }
            if ($mode === '') {
                $mode = $instanceMode;
            } elseif (!\hash_equals($mode, $instanceMode)) {
                return 'single';
            }
            if ($instanceMode === 'shared_session') {
                $instanceEvidenceDigest = (string)(
                    $identity['session_capability_evidence_digest'] ?? ''
                );
                if ($sharedEvidenceDigest === '') {
                    $sharedEvidenceDigest = $instanceEvidenceDigest;
                } elseif (!\hash_equals($sharedEvidenceDigest, $instanceEvidenceDigest)) {
                    return 'single';
                }
            }
        }
        return $mode !== '' ? $mode : 'single';
    }

    /**
     * Rejects legacy, incomplete or corrupted capability claims after state
     * recovery. Shared Session instances are compared by the validated digest
     * in routeDistributionMode(), so different services fail closed.
     *
     * @param array<string,mixed> $identity
     * @param 'stateless'|'shared_session' $mode
     */
    private function validStoredDistributionCapability(array $identity, string $mode): bool
    {
        $evidence = \is_array($identity['session_capability_evidence'] ?? null)
            ? $identity['session_capability_evidence']
            : [];
        $digest = \strtolower(\trim((string)(
            $identity['session_capability_evidence_digest'] ?? ''
        )));
        if ($evidence === []
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals(
                $digest,
                \hash('sha256', $this->canonicalJson($evidence)),
            )
        ) {
            return false;
        }
        if ($mode === 'stateless') {
            return \hash_equals(
                    'wls-stateless-capability/1',
                    (string)($evidence['schema'] ?? ''),
                )
                && \hash_equals(
                    'project_endpoint',
                    (string)($evidence['runtime_source'] ?? ''),
                )
                && ($evidence['runtime_declared'] ?? false) === true
                && (int)($evidence['instance_generation'] ?? 0)
                    === (int)($identity['generation'] ?? 0)
                && \hash_equals(
                    'declared_stateless_runtime',
                    (string)($evidence['reason'] ?? ''),
                );
        }

        $host = \strtolower(\trim((string)($evidence['host'] ?? '')));
        $port = (int)($evidence['port'] ?? 0);
        return \hash_equals(
                'wls-session-capability/1',
                (string)($evidence['schema'] ?? ''),
            )
            && \hash_equals('wls', (string)($evidence['storage'] ?? ''))
            && \hash_equals(
                'project_shared_state',
                (string)($evidence['runtime_source'] ?? ''),
            )
            && ($evidence['runtime_registered'] ?? false) === true
            && ($evidence['runtime_shared_service'] ?? false) === true
            && \in_array($host, ['127.0.0.1', '::1'], true)
            && $port > 0
            && $port <= 65535
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($evidence['token_scope_digest'] ?? ''),
            ) === 1
            && \hash_equals('healthy', (string)($evidence['probe'] ?? ''))
            && \hash_equals(
                'authenticated_session_runtime',
                (string)($evidence['reason'] ?? ''),
            );
    }

    /**
     * @param array<string,mixed> $route
     */
    private function routeRoutingDigest(array $route): string
    {
        if ($route === []) {
            return '';
        }
        return \hash('sha256', $this->canonicalJson([
            'status' => (string)($route['status'] ?? ''),
            'domain' => (string)($route['domain'] ?? ''),
            'backends' => (array)($route['backends'] ?? []),
            'backend_instances' => (array)($route['backend_instances'] ?? []),
            'distribution_mode' => (string)($route['distribution_mode'] ?? 'single'),
            'preferred_instance_id' => (string)($route['preferred_instance_id'] ?? ''),
            'certificate' => [
                'snapshot_digest' => (string)($route['certificate']['snapshot_digest'] ?? ''),
                'generation' => (int)($route['certificate']['generation'] ?? 0),
            ],
        ]));
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
     * @return list<array<string,mixed>>
     */
    private function projectInstanceStatuses(string $projectUuid): array
    {
        $statuses = [];
        foreach ((array)($this->state['instances'][$projectUuid] ?? []) as $instanceId => $instance) {
            if (!\is_array($instance)) {
                continue;
            }
            $status = (string)($instance['status'] ?? 'ACTIVE');
            if ($status !== 'DRAINING') {
                $statuses[] = [
                    'instance_id' => (string)$instanceId,
                    'status' => $status,
                    'generation' => (int)($instance['generation'] ?? 0),
                    'drain_until' => (int)($instance['drain_until'] ?? 0),
                    'backend_count' => \count((array)($instance['backends'] ?? [])),
                    'reachable_backends' => 0,
                    'counters_known' => false,
                    'active_requests' => 0,
                    'long_lived_connections' => 0,
                    'sse_connections' => 0,
                    'websocket_connections' => 0,
                    'counter_source' => 'none',
                    'drain_complete' => false,
                ];
                continue;
            }
            $activeRequests = 0;
            $longLived = 0;
            $sse = 0;
            $webSocket = 0;
            $reachable = 0;
            $counterCapable = 0;
            $backends = (array)($instance['backends'] ?? []);
            if (\array_key_exists('drain_counters', $instance)) {
                $reported = \is_array($instance['drain_counters'])
                    ? $instance['drain_counters']
                    : [];
                $reportAge = $this->monotonicNow()
                    - (float)($reported['reported_monotonic'] ?? 0.0);
                $countersKnown = (int)($reported['version'] ?? 0) === 1
                    && ($reported['counters_known'] ?? false) === true
                    && $this->hostBootId === (string)($reported['lease_boot_id'] ?? '')
                    && $reportAge >= 0.0
                    && $reportAge <= 25.0
                    && (int)($reported['worker_count'] ?? 0) > 0
                    && (int)($reported['reported_worker_count'] ?? -1)
                        === (int)($reported['worker_count'] ?? 0);
                $activeRequests = $countersKnown
                    ? (int)($reported['active_requests'] ?? 0)
                    : 0;
                $longLived = $countersKnown
                    ? (int)($reported['long_lived_connections'] ?? 0)
                    : 0;
                $sse = $countersKnown
                    ? (int)($reported['sse_connections'] ?? 0)
                    : 0;
                $webSocket = $countersKnown
                    ? (int)($reported['websocket_connections'] ?? 0)
                    : 0;
                $statuses[] = [
                    'instance_id' => (string)$instanceId,
                    'status' => $status,
                    'generation' => (int)($instance['generation'] ?? 0),
                    'drain_until' => (int)($instance['drain_until'] ?? 0),
                    'backend_count' => \count($backends),
                    'reachable_backends' => 0,
                    'counters_known' => $countersKnown,
                    'active_requests' => $activeRequests,
                    'long_lived_connections' => $longLived,
                    'sse_connections' => $sse,
                    'websocket_connections' => $webSocket,
                    'counter_source' => 'master-heartbeat',
                    'drain_complete' => $countersKnown
                        && $activeRequests === 0
                        && $longLived === 0,
                ];
                continue;
            }
            $identity = (array)($instance['backend_identity'] ?? []);
            if ($backends === [] || $identity === []) {
                $legacyBackends = [];
                foreach ($this->projectRoutes($projectUuid) as $route) {
                    $routeInstance = $route['instances'][$instanceId] ?? null;
                    if (!\is_array($routeInstance)) {
                        continue;
                    }
                    $identity = $identity !== []
                        ? $identity
                        : (array)($routeInstance['backend_identity'] ?? []);
                    foreach ((array)($routeInstance['backends'] ?? []) as $backend) {
                        if (!\is_array($backend)) {
                            continue;
                        }
                        $legacyBackends[(string)($backend['host'] ?? '') . ':'
                            . (int)($backend['port'] ?? 0)] = $backend;
                    }
                }
                $backends = \array_values($legacyBackends);
            }
            foreach ($backends as $backend) {
                if (!\is_array($backend)) {
                    continue;
                }
                $health = $this->probeBackendHealth($backend, $identity);
                if ($health === null) {
                    continue;
                }
                ++$reachable;
                if ((int)($health['drain_counters_version'] ?? 0) === 1) {
                    ++$counterCapable;
                }
                $activeRequests += \max(0, (int)($health['active_requests'] ?? 0));
                $longLived += \max(0, (int)($health['long_lived_connections'] ?? 0));
                $sse += \max(0, (int)($health['sse_connections'] ?? 0));
                $webSocket += \max(0, (int)($health['websocket_connections'] ?? 0));
            }
            $countersKnown = $backends !== []
                && $reachable === \count($backends)
                && $counterCapable === \count($backends);
            $statuses[] = [
                'instance_id' => (string)$instanceId,
                'status' => $status,
                'generation' => (int)($instance['generation'] ?? 0),
                'drain_until' => (int)($instance['drain_until'] ?? 0),
                'backend_count' => \count($backends),
                'reachable_backends' => $reachable,
                'counters_known' => $countersKnown,
                'active_requests' => $activeRequests,
                'long_lived_connections' => $longLived,
                'sse_connections' => $sse,
                'websocket_connections' => $webSocket,
                'counter_source' => 'legacy-backend-probe',
                'drain_complete' => $status === 'DRAINING'
                    && $countersKnown
                    && $activeRequests === 0
                    && $longLived === 0,
            ];
        }
        return $statuses;
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
        string $responseSecret = '',
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
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $responseSecret) === 1) {
            $response['signature'] = \hash_hmac(
                'sha256',
                $this->canonicalJson($response),
                $responseSecret,
            );
        }
        @\fwrite(
            $client,
            (string)\json_encode(
                $response,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            ) . "\n",
        );
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $broker
     * @return array{error:string,secret:string,project_uuid:string}
     */
    private function authenticate(array $request, array $broker): array
    {
        if ((string)($request['protocol'] ?? '') !== self::PROTOCOL) {
            return ['error' => 'Protocol mismatch.', 'secret' => '', 'project_uuid' => ''];
        }
        $channel = (string)($broker['channel'] ?? '');
        if (!\hash_equals($channel, (string)($request['channel'] ?? ''))) {
            return [
                'error' => 'The request channel does not match the broker channel.',
                'secret' => '',
                'project_uuid' => '',
            ];
        }
        if (!\hash_equals($this->hostId(), (string)($request['host_id'] ?? ''))) {
            return [
                'error' => 'The request belongs to another gateway host.',
                'secret' => '',
                'project_uuid' => '',
            ];
        }
        $timestamp = (int)($request['timestamp'] ?? 0);
        $requestMonotonic = $request['monotonic_timestamp'] ?? null;
        $monotonicNow = $this->monotonicNow();
        if ((! \is_int($requestMonotonic) && !\is_float($requestMonotonic))
            || \abs($monotonicNow - (float)$requestMonotonic) > 60.0
        ) {
            return [
                'error' => 'Request monotonic timestamp is outside the accepted window.',
                'secret' => '',
                'project_uuid' => '',
            ];
        }
        if (\abs(\time() - $timestamp) > 60 && $this->observeWallClock()) {
            return [
                'error' => 'Request timestamp is outside the accepted window.',
                'secret' => '',
                'project_uuid' => '',
            ];
        }
        $nonce = \strtolower((string)($request['nonce'] ?? ''));
        if (!\preg_match('/\A[a-f0-9]{32}\z/D', $nonce) || isset($this->nonces[$nonce])) {
            return [
                'error' => 'Request nonce is invalid or replayed.',
                'secret' => '',
                'project_uuid' => '',
            ];
        }
        $operation = \strtolower(\trim((string)($request['operation'] ?? '')));
        $adminOperations = [
            'status',
            'routes',
            'doctor',
            'operation-status',
            'enroll',
            'revoke',
            'transfer',
            'repair',
            'upgrade',
            'stop',
        ];
        $projectOperations = [
            'own-status',
            'operation-status',
            'register',
            'renew',
            'heartbeat',
            'acme-challenge-sync',
            'transfer-stage',
            'drain',
            'unregister',
        ];
        $projectUuid = '';
        $secret = '';
        if ($channel === 'admin') {
            if (!\hash_equals('admin', (string)($request['credential_id'] ?? ''))
                || !$this->administratorPeerAllowed($broker)
            ) {
                return [
                    'error' => 'Administrator operation or OS identity is not authorized.',
                    'secret' => '',
                    'project_uuid' => '',
                ];
            }
            $secret = \strtolower(\trim((string)@\file_get_contents($this->adminTokenFile())));
            if (!\in_array($operation, $adminOperations, true)) {
                return [
                    'error' => 'The administrator channel does not support this operation.',
                    'secret' => $secret,
                    'project_uuid' => '',
                ];
            }
        } elseif ($channel === 'project') {
            $payload = \is_array($request['payload'] ?? null) ? $request['payload'] : [];
            $projectUuid = \strtolower(\trim((string)($payload['project_uuid'] ?? '')));
            $enrollment = $this->state['enrollments'][$projectUuid] ?? null;
            $tombstone = $this->state['security']['tombstones']['project:' . $projectUuid] ?? null;
            if (!\is_array($enrollment)
                || (\is_array($tombstone)
                    && (int)($tombstone['generation'] ?? 0)
                        >= (int)($enrollment['security_generation'] ?? 0))
                || !\hash_equals(
                    (string)($enrollment['credential_id'] ?? ''),
                    (string)($request['credential_id'] ?? ''),
                )
                || !$this->peerMatchesEnrollment($broker, $enrollment)
            ) {
                return [
                    'error' => 'Project capability or OS peer identity is not authorized.',
                    'secret' => '',
                    'project_uuid' => '',
                ];
            }
            $secret = \strtolower((string)($enrollment['credential_secret'] ?? ''));
            if (!\in_array($operation, $projectOperations, true)) {
                return [
                    'error' => 'The project channel cannot perform administrator operations.',
                    'secret' => $secret,
                    'project_uuid' => $projectUuid,
                ];
            }
        }
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $secret) !== 1) {
            return [
                'error' => 'The selected gateway credential is unavailable.',
                'secret' => '',
                'project_uuid' => '',
            ];
        }
        $requestDigest = \strtolower((string)($request['request_digest'] ?? ''));
        $expectedDigest = \hash('sha256', $this->canonicalJson([
            'operation' => $operation,
            'payload' => \is_array($request['payload'] ?? null) ? $request['payload'] : [],
        ]));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $requestDigest) !== 1
            || !\hash_equals($expectedDigest, $requestDigest)
        ) {
            return [
                'error' => 'Request digest is invalid.',
                'secret' => $secret,
                'project_uuid' => $projectUuid,
            ];
        }
        $signature = \strtolower((string)($request['signature'] ?? ''));
        unset($request['signature']);
        $expected = \hash_hmac('sha256', $this->canonicalJson($request), $secret);
        if (!\preg_match('/\A[a-f0-9]{64}\z/D', $signature) || !\hash_equals($expected, $signature)) {
            return [
                'error' => 'Request signature is invalid.',
                'secret' => $secret,
                'project_uuid' => $projectUuid,
            ];
        }
        $clockTrusted = $this->observeWallClock();
        $this->nonces[$nonce] = [
            'seen_at' => \time(),
            'seen_monotonic' => $monotonicNow,
            'boot_id' => $this->hostBootId,
        ];
        $this->state['security']['nonces'] = $this->nonces;
        $this->persistState();
        $requestPayload = \is_array($request['payload'] ?? null)
            ? $request['payload']
            : [];
        $clockAcknowledgement = $channel === 'admin'
            && $operation === 'repair'
            && $requestPayload === ['accept_clock' => true];
        if (!$clockTrusted
            && !$clockAcknowledgement
            && !\in_array(
                $operation,
                ['status', 'routes', 'doctor', 'own-status', 'operation-status', 'heartbeat'],
                true,
            )
        ) {
            return [
                'error' => 'Gateway wall clock is untrusted; security-sensitive mutation rejected.',
                'secret' => $secret,
                'project_uuid' => $projectUuid,
            ];
        }
        return ['error' => '', 'secret' => $secret, 'project_uuid' => $projectUuid];
    }

    /**
     * @param array<string,mixed> $broker
     */
    private function administratorPeerAllowed(array $broker): bool
    {
        if (($this->slotManifestFromActiveFile()['test_mode'] ?? false) === true) {
            return (int)($broker['uid'] ?? -1) >= 0
                || (string)($broker['sid'] ?? '') !== '';
        }
        if (isset($broker['uid'])) {
            return (int)$broker['uid'] === 0;
        }
        return ($broker['is_admin'] ?? false) === true;
    }

    /**
     * @param array<string,mixed> $broker
     * @param array<string,mixed> $enrollment
     */
    private function peerMatchesEnrollment(array $broker, array $enrollment): bool
    {
        $owner = \is_array($enrollment['owner'] ?? null) ? $enrollment['owner'] : [];
        if ((string)($owner['kind'] ?? '') === 'posix') {
            return (int)($owner['uid'] ?? -1) >= 0
                && (int)($owner['uid'] ?? -1) === (int)($broker['uid'] ?? -2);
        }
        if ((string)($owner['kind'] ?? '') === 'windows') {
            $expected = \strtoupper((string)($owner['sid'] ?? ''));
            $actual = \strtoupper((string)($broker['sid'] ?? ''));
            return $expected !== '' && \hash_equals($expected, $actual);
        }
        return false;
    }

    private function assertRateLimit(string $channel, string $principal, string $operation): void
    {
        $readOnly = \in_array(
            $operation,
            ['status', 'routes', 'doctor', 'own-status', 'operation-status'],
            true,
        );
        $rate = $readOnly ? 10.0 : ($channel === 'project' ? 1.0 : 2.0);
        $capacity = $readOnly ? 20.0 : ($channel === 'project' ? 5.0 : 10.0);
        $key = $channel . ':' . $principal . ':' . ($readOnly ? 'read' : 'update');
        $now = \hrtime(true) / 1_000_000_000;
        $window = $this->rateWindows[$key] ?? ['tokens' => $capacity, 'at' => $now];
        $tokens = \min($capacity, (float)$window['tokens'] + ($now - (float)$window['at']) * $rate);
        if ($tokens < 1.0) {
            $retryAfter = \max(1, (int)\ceil((1.0 - $tokens) / $rate));
            throw new \DomainException(
                'Gateway request rate limit exceeded; retry_after=' . $retryAfter . '.'
            );
        }
        $this->rateWindows[$key] = ['tokens' => $tokens - 1.0, 'at' => $now];
        if (\count($this->rateWindows) > 1024) {
            \uasort(
                $this->rateWindows,
                static fn (array $left, array $right): int => $right['at'] <=> $left['at'],
            );
            $this->rateWindows = \array_slice($this->rateWindows, 0, 1024, true);
        }
    }

    private function observeWallClock(): bool
    {
        $nowWall = \time();
        $nowMonotonic = \hrtime(true) / 1_000_000_000;
        $expectedWall = $this->clockWallAnchor
            + ($nowMonotonic - $this->clockMonotonicAnchor);
        if (\abs($nowWall - $expectedWall) > 5.0) {
            $this->state['security']['clock_untrusted_since'] ??= \gmdate(DATE_ATOM);
            $this->state['health_state'] = 'CLOCK_UNTRUSTED';
            $this->clockWallAnchor = $nowWall;
            $this->clockMonotonicAnchor = $nowMonotonic;
            $this->clockStableSinceMonotonic = 0.0;
            return false;
        }
        if (!isset($this->state['security']['clock_untrusted_since'])) {
            $this->clockStableSinceMonotonic = 0.0;
            return true;
        }
        if ($this->clockStableSinceMonotonic <= 0.0) {
            $this->clockStableSinceMonotonic = $nowMonotonic;
            return false;
        }
        if (($nowMonotonic - $this->clockStableSinceMonotonic)
            < self::CLOCK_RECOVERY_STABLE_SECONDS
        ) {
            return false;
        }

        unset($this->state['security']['clock_untrusted_since']);
        $this->state['security']['clock_retrusted_at'] = \gmdate(DATE_ATOM);
        if ((string)($this->state['health_state'] ?? '') === 'CLOCK_UNTRUSTED') {
            $this->state['health_state'] = 'RECOVERING';
        }
        $this->state['recovery']['stage'] = 'CLOCK_STABLE';
        $this->clockStableSinceMonotonic = 0.0;
        return true;
    }

    private function monotonicNow(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private function nullDevice(): string
    {
        return \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    private function detectHostBootId(): string
    {
        if (\PHP_OS_FAMILY === 'Linux') {
            $bootId = \strtolower(\trim((string)@\file_get_contents(
                '/proc/sys/kernel/random/boot_id',
            )));
            if (\preg_match('/\A[a-f0-9-]{36}\z/D', $bootId) === 1) {
                return \hash('sha256', 'linux:' . $bootId);
            }
            throw new \RuntimeException('Linux boot identity is unavailable.');
        }
        if (\PHP_OS_FAMILY === 'Darwin') {
            $bootTime = $this->boundedCommandOutput([
                '/usr/sbin/sysctl',
                '-n',
                'kern.boottime',
            ]);
            if (\preg_match(
                '/\A\{\s*sec\s*=\s*(\d+),\s*usec\s*=\s*(\d+)\s*\}/',
                $bootTime,
                $matches,
            ) === 1) {
                return \hash(
                    'sha256',
                    'darwin:' . $matches[1] . ':' . $matches[2],
                );
            }
            throw new \RuntimeException('macOS boot identity is unavailable.');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $systemRoot = \rtrim((string)\getenv('SystemRoot'), '\\/');
            $powershell = $systemRoot !== ''
                ? $systemRoot
                    . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe'
                : 'powershell.exe';
            $bootTime = $this->boundedCommandOutput([
                $powershell,
                '-NoLogo',
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-Command',
                'Get-CimInstance -ClassName Win32_OperatingSystem '
                    . '| Select-Object -ExpandProperty LastBootUpTime '
                    . '| Get-Date -UFormat %s',
            ]);
            if (\preg_match('/\A\d{9,12}(?:\.\d{1,9})?\z/D', $bootTime) === 1) {
                return \hash('sha256', 'windows:' . $bootTime);
            }
            throw new \RuntimeException('Windows boot identity is unavailable.');
        }
        throw new \RuntimeException(
            'Unsupported platform for WLS Gateway boot identity: ' . \PHP_OS_FAMILY
        );
    }

    /**
     * @param list<string> $command
     */
    private function boundedCommandOutput(array $command, float $timeoutSeconds = 3.0): string
    {
        $process = @\proc_open($command, [
            0 => ['file', $this->nullDevice(), 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start platform identity probe.');
        }
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = $this->monotonicNow() + \max(0.1, $timeoutSeconds);
        $status = \proc_get_status($process);
        while (($status['running'] ?? false) && $this->monotonicNow() < $deadline) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @\stream_select($read, $write, $except, 0, 100_000);
            foreach ($read as $stream) {
                $chunk = (string)@\stream_get_contents($stream);
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
            $status = \proc_get_status($process);
        }
        if ($status['running'] ?? false) {
            @\proc_terminate($process);
        }
        $stdout .= (string)@\stream_get_contents($pipes[1]);
        $stderr .= (string)@\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        $exitCode = @\proc_close($process);
        if (($status['running'] ?? false)
            || ($exitCode !== 0 && (int)($status['exitcode'] ?? -1) !== 0)
        ) {
            throw new \RuntimeException(
                'Platform identity probe failed: ' . \trim($stderr)
            );
        }
        return \trim($stdout);
    }

    /**
     * @param array<string,mixed> $instance
     */
    private function sameLeaseBoot(array $instance): bool
    {
        $bootId = (string)($instance['lease_boot_id'] ?? '');
        return $bootId !== '' && \hash_equals($this->hostBootId, $bootId);
    }

    /**
     * @param array<string,mixed> $route
     */
    private function routeStaleDuration(array $route, float $monotonicNow): float
    {
        $bootId = (string)($route['stale_boot_id'] ?? '');
        $started = (float)($route['stale_since_monotonic'] ?? 0.0);
        if ($bootId === ''
            || !\hash_equals($this->hostBootId, $bootId)
            || $started <= 0.0
            || $started > $monotonicNow
        ) {
            return 0.0;
        }
        return $monotonicNow - $started;
    }

    private function pruneNonces(): void
    {
        $now = $this->monotonicNow();
        $this->nonces = \array_filter(
            $this->nonces,
            function (array $record) use ($now): bool {
                if (!\hash_equals(
                    $this->hostBootId,
                    (string)($record['boot_id'] ?? ''),
                )) {
                    // Across a reboot the monotonic origin changes. Retain the
                    // bounded record conservatively so a wall-clock rollback
                    // cannot revive a recently signed request.
                    return true;
                }
                $age = $now - (float)($record['seen_monotonic'] ?? 0.0);
                return $age < 0.0 || $age <= 120.0;
            },
        );
        if (\count($this->nonces) > 2048) {
            $this->nonces = \array_slice($this->nonces, -2048, null, true);
        }
        $this->state['security']['nonces'] = $this->nonces;
    }

    /**
     * @return array<string,array{seen_at:int,seen_monotonic:float,boot_id:string}>
     */
    private function normalizePersistedNonces(mixed $persisted): array
    {
        if (!\is_array($persisted)) {
            return [];
        }
        $normalized = [];
        foreach ($persisted as $nonce => $record) {
            if (!\is_string($nonce)
                || \preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1
            ) {
                continue;
            }
            if (\is_int($record)) {
                $normalized[$nonce] = [
                    'seen_at' => $record,
                    'seen_monotonic' => 0.0,
                    'boot_id' => 'legacy',
                ];
                continue;
            }
            if (!\is_array($record)
                || !\is_int($record['seen_at'] ?? null)
                || (!\is_int($record['seen_monotonic'] ?? null)
                    && !\is_float($record['seen_monotonic'] ?? null))
                || !\is_string($record['boot_id'] ?? null)
                || (string)$record['boot_id'] === ''
            ) {
                continue;
            }
            $normalized[$nonce] = [
                'seen_at' => (int)$record['seen_at'],
                'seen_monotonic' => (float)$record['seen_monotonic'],
                'boot_id' => (string)$record['boot_id'],
            ];
        }
        return $normalized;
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
    private function loadState(bool $allowRepair = true): array
    {
        $defaults = $this->defaultState();
        $securityLedger = $this->loadSecurityLedger($allowRepair);
        $raw = @\file_get_contents($this->stateFile());
        if (!\is_string($raw) || $raw === '') {
            return $this->applySecurityLedger($defaults, $securityLedger);
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
            if ($allowRepair) {
                $quarantine = $this->stateFile() . '.corrupt-' . \gmdate('YmdHis');
                @\rename($this->stateFile(), $quarantine);
            }
            $lkg = $this->loadRouteLkg();
            $defaults['routes'] = $lkg;
            foreach ($defaults['routes'] as $routeId => $route) {
                $defaults['routes'][$routeId]['status'] = 'STALE';
                $defaults['routes'][$routeId]['stale_since'] = \time();
            }
            $defaults['isolation_mode'] = true;
            $defaults['health_state'] = 'STATE_REBUILD';
            $defaults['epoch'] = $this->newEpoch();
            $defaults['_state_rebuild_required'] = true;
            return $this->applySecurityLedger($defaults, $securityLedger);
        }
        $state = \array_replace_recursive($defaults, $payload);
        $state = $this->applySecurityLedger($state, $securityLedger);
        // Nonces are retained across controller restarts so a replay cannot
        // bypass the in-memory window by crashing the control plane.
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
            . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $this->nginxBinaryName());
        return [
            'protocol' => self::PROTOCOL,
            'epoch' => $this->newEpoch(),
            'generation' => 1,
            'active_config_generation' => 0,
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
            'instances' => [],
            'routes' => [],
            'operations' => [],
            'acme_challenges' => [],
            'acme_generations' => [],
            'transfers' => [],
            'enrollments' => [],
            'security_ledger_valid' => true,
            'security' => [
                'nonces' => [],
                'tombstones' => [],
            ],
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
                'circuit_open_until_monotonic' => 0.0,
                'circuit_boot_id' => '',
                'next_retry_at' => 0,
            ],
        ];
    }

    /**
     * @return array{status:string,payload?:array<string,mixed>}
     */
    private function loadSecurityLedger(bool $allowRepair = true): array
    {
        $file = $this->securityLedgerFile();
        $untrustedMarker = $file . '.untrusted';
        if (\is_file($untrustedMarker) || \is_link($untrustedMarker)) {
            return ['status' => 'invalid'];
        }
        if (!\file_exists($file) && !\is_link($file)) {
            return ['status' => 'missing'];
        }
        if (!\is_file($file) || \is_link($file)) {
            if ($allowRepair) {
                $this->atomicWrite($untrustedMarker, \gmdate(DATE_ATOM) . "\n", 0600);
            }
            return ['status' => 'invalid'];
        }
        $raw = @\file_get_contents($file);
        $envelope = \is_string($raw) ? \json_decode($raw, true) : null;
        $payload = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : null;
        $hash = \is_array($envelope) ? (string)($envelope['sha256'] ?? '') : '';
        if (!\is_array($payload)
            || (int)($payload['schema_version'] ?? 0) !== 1
            || !\is_array($payload['enrollments'] ?? null)
            || !\is_array($payload['tombstones'] ?? null)
            || !\preg_match('/\A[a-f0-9]{64}\z/D', $hash)
            || !\hash_equals($hash, \hash('sha256', $this->canonicalJson($payload)))
        ) {
            if ($allowRepair) {
                @\rename($file, $file . '.corrupt-' . \gmdate('YmdHis'));
                $this->atomicWrite($untrustedMarker, \gmdate(DATE_ATOM) . "\n", 0600);
            }
            return ['status' => 'invalid'];
        }
        return ['status' => 'valid', 'payload' => $payload];
    }

    /**
     * @param array<string,mixed> $state
     * @param array{status:string,payload?:array<string,mixed>} $ledger
     * @return array<string,mixed>
     */
    private function applySecurityLedger(array $state, array $ledger): array
    {
        $status = (string)($ledger['status'] ?? 'invalid');
        if ($status === 'missing') {
            $state['security_ledger_valid'] = true;
            $state['_security_ledger_bootstrap'] = true;
            return $state;
        }
        if ($status !== 'valid') {
            $state['enrollments'] = [];
            $state['security']['tombstones'] = [];
            $state['security_ledger_valid'] = false;
            $state['isolation_mode'] = true;
            $state['health_state'] = 'SECURITY_LEDGER_UNTRUSTED';
            return $state;
        }
        $payload = (array)($ledger['payload'] ?? []);
        $state['enrollments'] = (array)($payload['enrollments'] ?? []);
        $state['security']['tombstones'] = (array)($payload['tombstones'] ?? []);
        $state['security_ledger_valid'] = true;
        return $state;
    }

    private function persistSecurityLedger(): void
    {
        $payload = [
            'schema_version' => 1,
            'enrollments' => (array)($this->state['enrollments'] ?? []),
            'tombstones' => (array)($this->state['security']['tombstones'] ?? []),
            'updated_at' => \gmdate(DATE_ATOM),
        ];
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', $this->canonicalJson($payload)),
        ];
        $encoded = \json_encode(
            $envelope,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode the host security ledger.');
        }
        $this->atomicWrite($this->securityLedgerFile(), $encoded, 0600);
        @\unlink($this->securityLedgerFile() . '.untrusted');
    }

    private function persistState(): void
    {
        $payload = $this->state;
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', $this->canonicalJson($payload)),
        ];
        $encoded = \json_encode(
            $envelope,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        );
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
        if (!$this->journal(
            $event,
            $context + ['generation' => (int)$this->state['generation']],
        )) {
            throw new \RuntimeException(
                'Gateway mutation was persisted but its durable audit record failed.'
            );
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function journal(string $event, array $context = []): bool
    {
        $estimated = \json_encode(
            ['event' => $event, 'context' => $context],
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        );
        if (!\is_string($estimated)
            || !$this->rotateJournalIfNeeded(\strlen($estimated) + 1024)
        ) {
            $this->markJournalWriteFailure('rotation_or_estimate_failed');
            return false;
        }
        $entry = [
            'schema_version' => 2,
            'sequence' => $this->journalSequence + 1,
            'previous_sha256' => $this->journalHead !== ''
                ? $this->journalHead
                : \str_repeat('0', 64),
            'at' => \gmdate(DATE_ATOM),
            'epoch' => (string)($this->state['epoch'] ?? ''),
            'event' => $event,
            'context' => $context,
        ];
        $entry['sha256'] = \hash('sha256', $this->canonicalJson($entry));
        $encoded = \json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        );
        if (!\is_string($encoded)) {
            $this->markJournalWriteFailure('encode_failed');
            return false;
        }
        $stream = @\fopen($this->journalFile(), 'c+b');
        if (!\is_resource($stream) || !@\flock($stream, LOCK_EX)) {
            if (\is_resource($stream)) {
                @\fclose($stream);
            }
            $this->markJournalWriteFailure('open_or_lock_failed');
            return false;
        }
        $line = $encoded . "\n";
        $written = false;
        try {
            @\chmod($this->journalFile(), 0600);
            $written = @\fseek($stream, 0, SEEK_END) === 0
                && @\fwrite($stream, $line) === \strlen($line)
                && @\fflush($stream)
                && (!\function_exists('fsync') || @\fsync($stream));
        } finally {
            @\flock($stream, LOCK_UN);
            @\fclose($stream);
        }
        if (!$written) {
            $this->markJournalWriteFailure('append_or_fsync_failed');
            return false;
        }
        $this->journalSequence = (int)$entry['sequence'];
        $this->journalHead = (string)$entry['sha256'];
        return true;
    }

    private function initializeJournalChain(bool $allowRepair = true): void
    {
        $this->journalTrusted = true;
        $this->journalHead = \str_repeat('0', 64);
        $file = $this->journalFile();
        if (!\file_exists($file) && !\is_link($file)) {
            return;
        }
        if (!\is_file($file) || \is_link($file)) {
            $this->rejectOrQuarantineJournal('unsafe_journal_path', $allowRepair);
            return;
        }
        $raw = @\file_get_contents($file);
        if (!\is_string($raw)) {
            $this->rejectOrQuarantineJournal('journal_read_failed', $allowRepair);
            return;
        }
        if ($raw === '') {
            return;
        }
        if (\strlen($raw) > 64 * 1024 * 1024) {
            $this->rejectOrQuarantineJournal('journal_quota_exceeded', $allowRepair);
            return;
        }
        $hasFinalNewline = \str_ends_with($raw, "\n");
        $lines = \explode("\n", $raw);
        if ($hasFinalNewline) {
            \array_pop($lines);
        }
        $sequence = 0;
        $head = \str_repeat('0', 64);
        $legacy = false;
        $tailTruncated = false;
        foreach ($lines as $index => $line) {
            if ($line === '') {
                $this->rejectOrQuarantineJournal('empty_middle_record', $allowRepair);
                return;
            }
            $entry = \json_decode($line, true);
            if (!\is_array($entry)) {
                if (!$hasFinalNewline && $index === \array_key_last($lines)) {
                    if (!$allowRepair) {
                        $this->markJournalUntrustedInMemory('truncated_tail');
                        return;
                    }
                    $this->truncateJournalTail($raw);
                    $tailTruncated = true;
                    break;
                }
                $this->rejectOrQuarantineJournal('invalid_middle_record', $allowRepair);
                return;
            }
            if ((int)($entry['schema_version'] ?? 0) !== 2) {
                $legacyDigest = (string)($entry['sha256'] ?? '');
                unset($entry['sha256']);
                if (\preg_match('/\A[a-f0-9]{64}\z/D', $legacyDigest) !== 1
                    || !\hash_equals(
                        $legacyDigest,
                        \hash('sha256', $this->canonicalJson($entry)),
                    )
                ) {
                    $this->rejectOrQuarantineJournal(
                        'legacy_record_digest_mismatch',
                        $allowRepair,
                    );
                    return;
                }
                $legacy = true;
                continue;
            }
            if ($legacy
                || (int)($entry['sequence'] ?? 0) !== $sequence + 1
                || !\hash_equals($head, (string)($entry['previous_sha256'] ?? ''))
            ) {
                $this->rejectOrQuarantineJournal(
                    'sequence_or_previous_hash_mismatch',
                    $allowRepair,
                );
                return;
            }
            $digest = (string)($entry['sha256'] ?? '');
            unset($entry['sha256']);
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || !\hash_equals($digest, \hash('sha256', $this->canonicalJson($entry)))
            ) {
                if (!$hasFinalNewline && $index === \array_key_last($lines)) {
                    if (!$allowRepair) {
                        $this->markJournalUntrustedInMemory('truncated_tail_digest');
                        return;
                    }
                    $this->truncateJournalTail($raw);
                    $tailTruncated = true;
                    break;
                }
                $this->rejectOrQuarantineJournal('record_digest_mismatch', $allowRepair);
                return;
            }
            $sequence++;
            $head = $digest;
        }
        if ($legacy) {
            if (!$allowRepair) {
                $this->markJournalUntrustedInMemory('legacy_journal_requires_migration');
                return;
            }
            @\rename($file, $file . '.legacy-' . \gmdate('YmdHis'));
            $this->journalSequence = 0;
            $this->journalHead = \str_repeat('0', 64);
            return;
        }
        $this->journalSequence = $sequence;
        $this->journalHead = $head;
        if ($allowRepair && !$hasFinalNewline && !$tailTruncated && $lines !== []) {
            $stream = @\fopen($file, 'ab');
            if (\is_resource($stream)) {
                @\fwrite($stream, "\n");
                @\fflush($stream);
                if (\function_exists('fsync')) {
                    @\fsync($stream);
                }
                @\fclose($stream);
            }
        }
    }

    private function rejectOrQuarantineJournal(string $reason, bool $allowRepair): void
    {
        if ($allowRepair) {
            $this->quarantineJournal($reason);
            return;
        }
        $this->markJournalUntrustedInMemory($reason);
    }

    private function markJournalUntrustedInMemory(string $reason): void
    {
        $this->journalTrusted = false;
        $this->journalSequence = 0;
        $this->journalHead = \str_repeat('0', 64);
        $this->state['ready'] = false;
        $this->state['recovery']['stage'] = 'DISK_PRESSURE_JOURNAL_UNTRUSTED';
        $this->state['recovery']['last_failure'] = $reason;
    }

    private function truncateJournalTail(string $raw): void
    {
        $lastNewline = \strrpos($raw, "\n");
        $trusted = $lastNewline === false ? '' : \substr($raw, 0, $lastNewline + 1);
        try {
            $this->atomicWrite($this->journalFile(), $trusted, 0600);
        } catch (\Throwable) {
            $this->quarantineJournal('truncated_tail_repair_failed');
        }
    }

    private function quarantineJournal(string $reason): void
    {
        $file = $this->journalFile();
        if (\file_exists($file) || \is_link($file)) {
            @\rename($file, $file . '.corrupt-' . \gmdate('YmdHis'));
        }
        $this->journalTrusted = false;
        $this->journalSequence = 0;
        $this->journalHead = \str_repeat('0', 64);
        $this->state['isolation_mode'] = true;
        $this->state['health_state'] = 'JOURNAL_UNTRUSTED';
        $this->state['epoch'] = $this->newEpoch();
        foreach ((array)($this->state['routes'] ?? []) as $routeId => $route) {
            if (!\is_array($route) || (string)($route['status'] ?? '') === 'REMOVED') {
                continue;
            }
            $this->state['routes'][$routeId]['status'] = 'STALE';
            $this->state['routes'][$routeId]['backends'] = [];
            $this->state['routes'][$routeId]['stale_since'] = \time();
        }
        try {
            $this->persistState();
        } catch (\Throwable) {
        }
        $this->journal('journal_quarantined', ['reason' => $reason]);
    }

    private function markJournalWriteFailure(string $reason): void
    {
        $this->journalTrusted = false;
        $this->markDiskPressure('JOURNAL_WRITE_FAILED', $reason);
        \fwrite(STDERR, '[wls-edge/2] journal write failed: ' . $reason . "\n");
    }

    private function rotateJournalIfNeeded(int $incomingBytes): bool
    {
        $file = $this->journalFile();
        $size = \is_file($file) && !\is_link($file) ? (int)@\filesize($file) : 0;
        if ($size + $incomingBytes <= self::MAX_JOURNAL_BYTES) {
            return true;
        }
        $archive = $file . '.1';
        if ((\file_exists($archive) || \is_link($archive)) && !@\unlink($archive)) {
            $this->markDiskPressure('JOURNAL_ROTATION_FAILED', 'old_archive_remove_failed');
            return false;
        }
        if (!@\rename($file, $archive)) {
            $this->markDiskPressure('JOURNAL_ROTATION_FAILED', 'active_archive_rename_failed');
            return false;
        }
        @\chmod($archive, 0600);
        $this->journalSequence = 0;
        $this->journalHead = \str_repeat('0', 64);
        $this->state['journal_archive'] = [
            'path' => \basename($archive),
            'sha256' => $this->fileHash($archive),
            'rotated_at' => \gmdate(DATE_ATOM),
        ];
        return true;
    }

    private function assertPersistentMutationAllowed(string $operation): void
    {
        if (!$this->journalTrusted) {
            throw new \DomainException(
                'Gateway durable state is untrusted; refusing ' . $operation . '.'
            );
        }
        $storage = $this->storageStatus();
        if (!($storage['mutation_ready'] ?? false)) {
            $this->markDiskPressure(
                'DISK_PRESSURE',
                'insufficient_free_space_for_' . $operation,
            );
            throw new \DomainException(
                'Gateway storage reserve is below the safe mutation threshold; '
                . 'verified active traffic is retained but new durable operations are rejected.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function storageStatus(): array
    {
        $override = (string)\getenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES');
        $free = (string)\getenv('WLS_GATEWAY_TEST_MODE') === '1'
            && $override !== ''
            && \preg_match('/\A[0-9]+\z/D', $override) === 1
                ? (int)$override
                : @\disk_free_space($this->stateDir());
        $freeBytes = \is_int($free) || \is_float($free) ? (int)$free : -1;
        $pressureMarker = $this->diskPressureMarkerActive();
        $expectedReserveBytes = (string)\getenv('WLS_GATEWAY_TEST_MODE') === '1'
            ? self::TEST_RECOVERY_RESERVE_BYTES
            : self::RECOVERY_RESERVE_BYTES;
        $reserveBytes = \is_file($this->recoveryReserveFile())
            && !\is_link($this->recoveryReserveFile())
                ? (int)@\filesize($this->recoveryReserveFile())
                : 0;
        $reserveReady = $reserveBytes === $expectedReserveBytes;
        return [
            'free_bytes' => $freeBytes,
            'minimum_mutation_free_bytes' => self::MIN_MUTATION_FREE_BYTES,
            'reserve_bytes' => $reserveBytes,
            'reserve_expected_bytes' => $expectedReserveBytes,
            'recovery_reserve_ready' => $reserveReady,
            'pressure_marker' => $pressureMarker,
            'mutation_ready' => $freeBytes >= self::MIN_MUTATION_FREE_BYTES
                && !$pressureMarker
                && $reserveReady,
            'snapshot_bytes' => $this->snapshotStorageBytes(),
            'snapshot_quota_bytes' => self::MAX_SNAPSHOT_BYTES,
            'journal_bytes' => \is_file($this->journalFile())
                ? (int)@\filesize($this->journalFile())
                : 0,
            'journal_quota_bytes' => self::MAX_JOURNAL_BYTES,
        ];
    }

    private function markDiskPressure(string $stage, string $reason): void
    {
        $this->state['ready'] = (bool)($this->state['ready'] ?? false);
        $this->state['health_state'] = 'DISK_PRESSURE';
        $this->state['recovery']['stage'] = $stage;
        $this->state['recovery']['last_failure'] = $reason;
        $reserve = $this->recoveryReserveFile();
        if (\file_exists($reserve) || \is_link($reserve)) {
            @\unlink($reserve);
        }
        $marker = $this->diskPressureMarkerFile();
        if (\file_exists($marker) || \is_link($marker)) {
            return;
        }
        $handle = @\fopen($marker, 'xb');
        if (!\is_resource($handle)) {
            return;
        }
        try {
            $record = \gmdate(DATE_ATOM) . "\t" . $stage . "\t" . $reason . "\n";
            @\chmod($marker, 0600);
            @\fwrite($handle, $record);
            @\fflush($handle);
            if (\function_exists('fsync')) {
                @\fsync($handle);
            }
        } finally {
            @\fclose($handle);
        }
    }

    private function ensureRecoveryReserve(): void
    {
        $file = $this->recoveryReserveFile();
        $bytes = (string)\getenv('WLS_GATEWAY_TEST_MODE') === '1'
            ? self::TEST_RECOVERY_RESERVE_BYTES
            : self::RECOVERY_RESERVE_BYTES;
        $injectedFailure = (string)\getenv('WLS_GATEWAY_TEST_MODE') === '1'
            ? (string)\getenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE')
            : '';
        if ($injectedFailure === '1'
            || $injectedFailure === 'before_allocation'
        ) {
            throw new \RuntimeException(
                'Injected gateway recovery reserve allocation failure.'
            );
        }
        if (\is_file($file) && !\is_link($file) && (int)@\filesize($file) === $bytes) {
            return;
        }
        if (\file_exists($file) || \is_link($file)) {
            @\unlink($file);
        }
        $handle = @\fopen($file, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to establish the gateway recovery disk reserve.');
        }
        $remaining = $bytes;
        $ready = false;
        try {
            while ($remaining > 0) {
                $write = \min($remaining, 65_536);
                if (@\fwrite($handle, \random_bytes($write)) !== $write) {
                    throw new \RuntimeException(
                        'Unable to allocate the gateway recovery disk reserve.'
                    );
                }
                $remaining -= $write;
            }
            if ($injectedFailure === 'after_write') {
                throw new \RuntimeException(
                    'Injected gateway recovery reserve persistence failure.'
                );
            }
            if (!@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                throw new \RuntimeException(
                    'Unable to persist the gateway recovery disk reserve.'
                );
            }
            $ready = true;
        } finally {
            @\fclose($handle);
            if (!$ready) {
                @\unlink($file);
            }
        }
        @\chmod($file, 0600);
    }

    private function snapshotStorageBytes(): int
    {
        $root = $this->home . DIRECTORY_SEPARATOR . 'snapshots';
        if (!\is_dir($root) || \is_link($root)) {
            return 0;
        }
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $bytes += $item->getSize();
                if ($bytes > self::MAX_SNAPSHOT_BYTES) {
                    break;
                }
            }
        }
        return $bytes;
    }

    private function persistRouteLkg(): void
    {
        $this->writeRouteSnapshot($this->routeLkgFile(), (array)$this->state['routes']);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadRouteLkg(): array
    {
        return $this->loadRouteSnapshot($this->routeLkgFile()) ?? [];
    }

    /**
     * @param array<string,array<string,mixed>> $routes
     */
    private function writeRouteSnapshot(string $file, array $routes): bool
    {
        $encoded = \json_encode([
            'payload' => $routes,
            'sha256' => \hash('sha256', $this->canonicalJson($routes)),
        ], JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION);
        if (!\is_string($encoded)) {
            return false;
        }
        try {
            $this->atomicWrite($file, $encoded, 0600);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string,array<string,mixed>>|null
     */
    private function loadRouteSnapshot(string $file): ?array
    {
        if ($file === ''
            || !\is_file($file)
            || \is_link($file)
            || (!$this->pathInside($file, $this->stateDir())
                && !$this->pathInside($file, $this->lkgDir()))
        ) {
            return null;
        }
        $raw = @\file_get_contents($file);
        $envelope = \is_string($raw) ? \json_decode($raw, true) : null;
        $payload = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : [];
        $hash = \is_array($envelope) ? (string)($envelope['sha256'] ?? '') : '';
        return $hash !== '' && \hash_equals($hash, \hash('sha256', $this->canonicalJson($payload)))
            ? $payload
            : null;
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
                if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1) {
                    $referenced[$digest] = true;
                }
            }
        }
        foreach ((array)$this->state['lkg'] as $lkg) {
            if (!\is_array($lkg)) {
                continue;
            }
            $bundle = $this->loadLkgBundle($lkg);
            if ($bundle === null) {
                // A damaged retained LKG is a recovery incident. Avoid any
                // certificate GC until an administrator repairs or replaces it.
                return;
            }
            foreach ($bundle['certificate_digests'] as $digest) {
                $referenced[$digest] = true;
            }
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
            if (!@\chmod($this->socketFile(), 0660)) {
                @\fclose($server);
                @\unlink($this->socketFile());
                throw new \RuntimeException(
                    'Unable to restrict the native Broker controller socket.'
                );
            }
        }
        if ($this->brokerInternalEndpoint === null) {
            $this->atomicWrite(
                $this->endpointFile(),
                (string)\json_encode($endpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                0600,
            );
        }
        return $server;
    }

    /**
     * @return array{transport:string,address:string}
     */
    private function controlEndpoint(): array
    {
        if ($this->brokerInternalEndpoint !== null) {
            if (\str_starts_with($this->brokerInternalEndpoint, 'tcp://')) {
                return [
                    'transport' => 'tcp',
                    'address' => $this->brokerInternalEndpoint,
                ];
            }
            return [
                'transport' => 'unix',
                'address' => $this->brokerInternalEndpoint,
            ];
        }
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
        $opensslOptions = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
            foreach ([
                (string)\getenv('OPENSSL_CONF'),
                \dirname(\PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras'
                    . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
                \dirname(\PHP_BINARY) . DIRECTORY_SEPARATOR . 'openssl.cnf',
                'C:\\Program Files\\Common Files\\SSL\\openssl.cnf',
            ] as $configFile) {
                if ($configFile !== '' && \is_file($configFile)) {
                    $opensslOptions['config'] = $configFile;
                    break;
                }
            }
        }
        $key = @\openssl_pkey_new($opensslOptions);
        $csr = $key !== false
            ? @\openssl_csr_new(
                ['commonName' => 'unconfigured.wls.invalid'],
                $key,
                $opensslOptions,
            )
            : false;
        $cert = $csr !== false && $key !== false
            ? @\openssl_csr_sign($csr, null, $key, 3650, $opensslOptions)
            : false;
        if ($key === false || $cert === false) {
            throw new \RuntimeException('Unable to generate neutral gateway certificate.');
        }
        $certPem = '';
        $keyPem = '';
        if (!@\openssl_x509_export($cert, $certPem)
            || !@\openssl_pkey_export(
                $key,
                $keyPem,
                null,
                isset($opensslOptions['config'])
                    ? ['config' => $opensslOptions['config']]
                    : [],
            )
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
        return $this->runNginxConfig(
            $this->configFile(),
            $this->runtimeDir(),
            $arguments,
        );
    }

    /**
     * @param list<string> $arguments
     * @return array{code:int,output:string}
     */
    private function runNginxConfig(
        string $config,
        string $prefix,
        array $arguments,
    ): array {
        $binary = $this->nginxBinary();
        if (!\is_file($binary)) {
            return ['code' => 127, 'output' => 'Gateway Nginx binary is missing.'];
        }
        $command = \array_merge([
            $binary,
            '-p',
            \rtrim($prefix, '/\\') . DIRECTORY_SEPARATOR,
            '-c',
            $config,
        ], $arguments);
        $parts = \array_map(
            static fn (string $part): string => \escapeshellarg($part),
            $command,
        );
        $output = [];
        $code = 0;
        if (\PHP_OS_FAMILY === 'Windows' && $arguments === []) {
            $logDirectory = $prefix . DIRECTORY_SEPARATOR . 'logs';
            if ((!@\mkdir($logDirectory, 0700, true) && !\is_dir($logDirectory))
                || \is_link($logDirectory)
            ) {
                return ['code' => 1, 'output' => 'Unable to prepare Windows Nginx start log.'];
            }
            $this->releaseWindowsNginxProcess($config);
            if (isset($this->windowsNginxProcesses[$config])) {
                return ['code' => 1, 'output' => 'Windows Nginx process is already running.'];
            }
            $pipes = [];
            $process = @\proc_open(
                $command,
                [
                    0 => ['file', $this->nullDevice(), 'r'],
                    1 => ['file', $logDirectory . DIRECTORY_SEPARATOR . 'native-start.log', 'a'],
                    2 => ['file', $logDirectory . DIRECTORY_SEPARATOR . 'native-start.log', 'a'],
                ],
                $pipes,
                $prefix,
                null,
                [
                    'bypass_shell' => true,
                    'create_process_group' => false,
                    'create_new_console' => false,
                ],
            );
            if (!\is_resource($process)) {
                return ['code' => 1, 'output' => 'Unable to create Windows Nginx process.'];
            }
            \usleep(100000);
            $status = \proc_get_status($process);
            if (!($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? 1);
                @\proc_close($process);
                return [
                    'code' => $exitCode >= 0 ? $exitCode : 1,
                    'output' => 'Windows Nginx exited during startup.',
                ];
            }
            $this->windowsNginxProcesses[$config] = $process;
        } else {
            @\exec(\implode(' ', $parts) . ' 2>&1', $output, $code);
        }
        return ['code' => $code, 'output' => \implode("\n", $output)];
    }

    private function releaseWindowsNginxProcess(string $config): void
    {
        $process = $this->windowsNginxProcesses[$config] ?? null;
        if (!\is_resource($process)) {
            unset($this->windowsNginxProcesses[$config]);
            return;
        }
        $status = \proc_get_status($process);
        if ($status['running'] ?? false) {
            return;
        }
        @\proc_close($process);
        unset($this->windowsNginxProcesses[$config]);
    }

    private function windowsTrackedNginxMatches(string $config, int $pid): ?bool
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return null;
        }
        $process = $this->windowsNginxProcesses[$config] ?? null;
        if (!\is_resource($process)) {
            return null;
        }
        $status = \proc_get_status($process);
        return ($status['running'] ?? false) && (int)($status['pid'] ?? 0) === $pid;
    }

    private function terminateWindowsNginxProcess(string $config): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return;
        }
        $process = $this->windowsNginxProcesses[$config] ?? null;
        if (!\is_resource($process)) {
            unset($this->windowsNginxProcesses[$config]);
            return;
        }
        $status = \proc_get_status($process);
        if ($status['running'] ?? false) {
            @\proc_terminate($process);
            $deadline = \microtime(true) + 5.0;
            do {
                \usleep(100000);
                $status = \proc_get_status($process);
            } while (($status['running'] ?? false) && \microtime(true) < $deadline);
        }
        if (!($status['running'] ?? false)) {
            @\proc_close($process);
            unset($this->windowsNginxProcesses[$config]);
        }
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

    /**
     * @param array<string,mixed> $reference
     */
    private function resolveCertificateReference(string $projectUuid, array $reference): string
    {
        $alias = \strtolower(\trim((string)($reference['root_alias'] ?? '')));
        $relative = \trim((string)($reference['relative_path'] ?? ''));
        if (\preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $alias) !== 1
            || $relative === ''
            || \str_contains($relative, "\0")
            || \str_starts_with($relative, '/')
            || \str_starts_with($relative, '\\')
            || \preg_match('/\A[A-Za-z]:[\\\\\\/]/D', $relative) === 1
        ) {
            throw new \DomainException('Certificate source reference is invalid.');
        }
        $segments = \preg_split('#[\\\\/]+#', $relative) ?: [];
        if ($segments === []) {
            throw new \DomainException('Certificate source reference is empty.');
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \DomainException('Certificate source reference contains traversal.');
            }
        }
        $enrollment = $this->state['enrollments'][$projectUuid] ?? null;
        $roots = \is_array($enrollment)
            ? (array)($enrollment['certificate_roots'] ?? [])
            : [];
        $root = (string)($roots[$alias] ?? '');
        if ($root === '') {
            throw new \DomainException('Certificate source root alias is not enrolled.');
        }
        $candidate = \rtrim($root, '/\\') . DIRECTORY_SEPARATOR
            . \implode(DIRECTORY_SEPARATOR, $segments);
        return $this->authorizedRegularFile($candidate, [$root]);
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
        $path = \trim($path);
        if ($path === '' || \str_contains($path, "\0")) {
            throw new \DomainException('Project or certificate root cannot be resolved.');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $real = \realpath($path);
            if (!\is_string($real) || !\is_dir($real)) {
                throw new \DomainException('Project or certificate root cannot be resolved.');
            }
            return \rtrim($real, '/\\');
        }
        if (!\str_starts_with($path, '/')) {
            throw new \DomainException('Project or certificate root must be absolute.');
        }
        $segments = [];
        foreach (\explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new \DomainException(
                    'Project or certificate root cannot contain traversal segments.'
                );
            }
            $segments[] = $segment;
        }
        if ($segments === []) {
            throw new \DomainException('The filesystem root cannot be enrolled as a project.');
        }
        return '/' . \implode('/', $segments);
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create directory for atomic write: ' . $directory);
        }
        if (\is_link($directory) || \is_link($path)) {
            throw new \RuntimeException('Atomic gateway write path is unsafe.');
        }
        $temporary = $path . '.tmp-' . \bin2hex(\random_bytes(6));
        if ($this->injectedAtomicWriteFailure('temporary_open_failed')) {
            $this->markDiskPressure('ATOMIC_WRITE_FAILED', 'temporary_open_failed');
            throw new \RuntimeException('Unable to stage an atomic gateway file.');
        }
        $handle = @\fopen($temporary, 'xb');
        if (!\is_resource($handle)) {
            $this->markDiskPressure('ATOMIC_WRITE_FAILED', 'temporary_open_failed');
            throw new \RuntimeException('Unable to stage an atomic gateway file.');
        }
        $staged = false;
        try {
            if ($this->injectedAtomicWriteFailure('temporary_write_or_fsync_failed')
                || @\fwrite($handle, $contents) !== \strlen($contents)
                || !@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                $this->markDiskPressure('ATOMIC_WRITE_FAILED', 'temporary_write_or_fsync_failed');
                throw new \RuntimeException('Unable to persist a temporary gateway file.');
            }
            $staged = true;
        } finally {
            @\fclose($handle);
            if (!$staged) {
                @\unlink($temporary);
            }
        }
        @\chmod($temporary, $mode);
        if ($this->injectedAtomicWriteFailure('atomic_rename_failed')
            || !@\rename($temporary, $path)
        ) {
            @\unlink($temporary);
            $this->markDiskPressure('ATOMIC_WRITE_FAILED', 'atomic_rename_failed');
            throw new \RuntimeException('Unable to publish gateway file atomically.');
        }
        @\chmod($path, $mode);
        if (\PHP_OS_FAMILY !== 'Windows' && \function_exists('fsync')) {
            $directoryHandle = @\fopen($directory, 'rb');
            if (\is_resource($directoryHandle)) {
                @\fsync($directoryHandle);
                @\fclose($directoryHandle);
            }
        }
    }

    private function injectedAtomicWriteFailure(string $stage): bool
    {
        return (string)\getenv('WLS_GATEWAY_TEST_MODE') === '1'
            && \hash_equals(
                $stage,
                (string)\getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE'),
            );
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
            if (@\posix_kill($pid, 0)) {
                return true;
            }
            // POSIX signal 0 returns EPERM when the process exists but belongs
            // to another user. The controller is intentionally unprivileged,
            // so cross-user project Masters must not be reported as dead.
            return \function_exists('posix_get_last_error')
                && \posix_get_last_error() === 1;
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
            $systemRoot = \rtrim((string)\getenv('SystemRoot'), '\\/');
            $powershell = $systemRoot !== ''
                ? $systemRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe'
                : 'powershell.exe';
            try {
                return $this->boundedCommandOutput([
                    $powershell,
                    '-NoLogo',
                    '-NoProfile',
                    '-NonInteractive',
                    '-Command',
                    'Get-CimInstance -ClassName Win32_Process '
                        . '| Where-Object ProcessId -EQ ' . $pid . ' '
                        . '| Select-Object -ExpandProperty CommandLine',
                ]);
            } catch (\Throwable) {
                return '';
            }
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
        if (\preg_match('/\A[0-9]+\z/D', $value) !== 1
            || (int)$value < 1
            || (int)$value > 65535
        ) {
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
            $this->runtimeDir() . DIRECTORY_SEPARATOR . 'temp',
            $this->runtimeDir() . DIRECTORY_SEPARATOR . 'temp'
                . DIRECTORY_SEPARATOR . 'client_body_temp',
            $this->runtimeDir() . DIRECTORY_SEPARATOR . 'temp'
                . DIRECTORY_SEPARATOR . 'proxy_temp',
            $this->runtimeDir() . DIRECTORY_SEPARATOR . 'temp'
                . DIRECTORY_SEPARATOR . 'fastcgi_temp',
            $this->runtimeDir() . DIRECTORY_SEPARATOR . 'temp'
                . DIRECTORY_SEPARATOR . 'uwsgi_temp',
            $this->runtimeDir() . DIRECTORY_SEPARATOR . 'temp'
                . DIRECTORY_SEPARATOR . 'scgi_temp',
            $this->lkgDir(),
            $this->home . DIRECTORY_SEPARATOR . 'snapshots',
        ] as $directory) {
            if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
                throw new \RuntimeException('Unable to create gateway directory: ' . $directory);
            }
            @\chmod($directory, 0700);
        }
        if (!\is_dir($this->trustDir()) || \is_link($this->trustDir())) {
            throw new \RuntimeException(
                'Gateway trust directory is missing or unsafe.'
            );
        }
        $diskPressureRecovery = $this->diskPressureMarkerActive();
        if (!$diskPressureRecovery) {
            $this->collectStaleShadowDirectories();
            try {
                $this->ensureRecoveryReserve();
            } catch (\Throwable $exception) {
                $this->markDiskPressure(
                    'RECOVERY_RESERVE_FAILED',
                    'startup_reserve_allocation_failed',
                );
                if (!$this->diskPressureMarkerActive()) {
                    throw new \RuntimeException(
                        'Gateway recovery reserve failed and disk pressure could not be latched.',
                        0,
                        $exception,
                    );
                }
            }
        }
        if ($this->brokerInternalEndpoint === null) {
            throw new \RuntimeException('Gateway Controller must be launched through the native platform broker.');
        }
        $token = \strtolower(\trim((string)@\file_get_contents($this->adminTokenFile())));
        if (!\is_file($this->adminTokenFile())
            || \is_link($this->adminTokenFile())
            || \preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
        ) {
            throw new \RuntimeException('Gateway administrator credential is missing or invalid.');
        }
        $this->hostId();
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

    private function trustDir(): string
    {
        return $this->home . DIRECTORY_SEPARATOR . 'trust';
    }

    private function diskPressureMarkerActive(): bool
    {
        return \file_exists($this->diskPressureMarkerFile())
            || \is_link($this->diskPressureMarkerFile());
    }

    private function diskPressureMarkerFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'disk-pressure.marker';
    }

    private function recoveryReserveFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'recovery.reserve';
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

    private function adminTokenFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'admin.token';
    }

    private function adminStoppedIntentFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'admin-stopped.intent';
    }

    private function hostId(): string
    {
        $file = $this->trustDir() . DIRECTORY_SEPARATOR . 'host-id';
        if (!\is_file($file) || \is_link($file)) {
            throw new \RuntimeException('Gateway host identity is missing or unsafe.');
        }
        $hostId = \strtolower(\trim((string)@\file_get_contents($file)));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1) {
            throw new \RuntimeException('Gateway host identity is invalid.');
        }
        return $hostId;
    }

    private function endpointFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'control-endpoint.json';
    }

    private function socketFile(): string
    {
        if ($this->brokerInternalEndpoint !== null
            && \str_starts_with($this->brokerInternalEndpoint, 'unix://')
        ) {
            return \substr($this->brokerInternalEndpoint, 7);
        }
        return $this->runDir() . DIRECTORY_SEPARATOR . 'wls-edge-2.sock';
    }

    private function brokerFencingFile(): string
    {
        if (\is_string($this->brokerFencingPath)
            && $this->brokerFencingPath !== ''
            && !\str_contains($this->brokerFencingPath, "\0")
        ) {
            return $this->brokerFencingPath;
        }
        if (\is_string($this->brokerInternalEndpoint)
            && \str_starts_with($this->brokerInternalEndpoint, 'unix://')
        ) {
            return \dirname(\substr($this->brokerInternalEndpoint, 7))
                . DIRECTORY_SEPARATOR . 'fencing-token';
        }
        throw new \RuntimeException(
            'Native Broker fencing path is unavailable for the internal transport.'
        );
    }

    private function stateFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'gateway-state.json';
    }

    private function securityLedgerFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'security-ledger.json';
    }

    private function publicationFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'publication-current.json';
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
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'active-slot';
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
            . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $this->nginxBinaryName();
    }

    private function mimeTypesFile(): string
    {
        $file = $this->configDir() . DIRECTORY_SEPARATOR . 'mime.types';
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

if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
    exit(WlsEdgeGatewayController::main($argv));
}
