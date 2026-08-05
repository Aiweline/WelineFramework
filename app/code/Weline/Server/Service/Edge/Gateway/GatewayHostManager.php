<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Installs and controls the project-independent host gateway runtime.
 */
final class GatewayHostManager
{
    public const LEASE_RECEIPT_SCHEMA_VERSION = 2;

    public const ENROLLMENT_RECEIPT_FIELDS = [
        'schema_version',
        'protocol',
        'host_id',
        'gateway_epoch',
        'tx_id',
        'project_uuid',
        'security_generation',
        'credential_generation',
        'credential_id',
        'domains_digest',
        'capabilities_digest',
        'request_digest',
        'authenticated_desired_digest',
        'idempotency_key',
        'state',
        'issued_at',
        'signature',
    ];

    public const LEASE_RECEIPT_FIELDS = [
        'schema_version',
        'protocol',
        'host_id',
        'project_uuid',
        'gateway_epoch',
        'project_generation',
        'instance_id',
        'instance_generation',
        'instance_digest',
        'master_epoch',
        'launch_id',
        'request_digest',
        'idempotency_key',
        'active_config_generation',
        'active_config_digest',
        'host_boot_id',
        'issued_monotonic',
        'lease_sequence',
        'lease_ttl_seconds',
        'route_generations',
        'routes_digest',
        'issued_at',
        'signature',
    ];

    /**
     * Canonical schema/field-set gate shared by every consumer of an already
     * authenticated lease receipt. Cryptographic, freshness and credential
     * checks remain in assertLeaseReceipt(); consumers must obtain receipts
     * through validatedLeaseReceiptForInstance().
     *
     * @param array<string,mixed> $receipt
     */
    public static function assertLeaseReceiptContract(array $receipt): void
    {
        $expectedFields = self::LEASE_RECEIPT_FIELDS;
        $actualFields = \array_keys($receipt);
        \sort($expectedFields, SORT_STRING);
        \sort($actualFields, SORT_STRING);
        if ($actualFields !== $expectedFields
            || ($receipt['schema_version'] ?? null)
                !== self::LEASE_RECEIPT_SCHEMA_VERSION
            || !\hash_equals(
                GatewayPaths::PROTOCOL,
                (string)($receipt['protocol'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: gateway lease receipt contract is incompatible.'
            );
        }
    }

    private const EXPLICIT_START_READY_TIMEOUT_SECONDS = 60.0;
    private const PROMOTION_ENROLLMENT_READY_TIMEOUT_SECONDS = 5.0;
    private const UPGRADE_SHADOW_READY_SECONDS = 15.0;
    private const UPGRADE_ACTIVATION_READY_SECONDS = 15.0;
    private const UPGRADE_CONTROL_HANDOFF_SECONDS = 15.0;
    private const UPGRADE_IDENTITY_PROBE_MARGIN_SECONDS = 15.0;
    private const DRAIN_LEASE_HEARTBEAT_SECONDS = 10.0;
    private const DRAIN_LEASE_HEARTBEAT_FAILURE_SECONDS = 30.0;

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly GatewayClient $client = new GatewayClient(),
        private readonly HostGatewayPackageManager $packages = new HostGatewayPackageManager(),
        private readonly GatewayPlatformServiceInstaller $platform = new GatewayPlatformServiceInstaller(),
        private readonly ?\Closure $progressCallback = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function status(float $transientRetrySeconds = 0.0): array
    {
        $deadline = \hrtime(true) / 1_000_000_000
            + \max(0.0, $transientRetrySeconds);
        while (true) {
            try {
                $response = $this->client->status();
                if (!($response['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'ready' => false,
                        'reason' => (string)($response['error']['message'] ?? 'Gateway status rejected.'),
                    ];
                }
                $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
                return ['ok' => true] + $payload;
            } catch (\Throwable $throwable) {
                if ($transientRetrySeconds > 0.0
                    && $throwable instanceof \RuntimeException
                    && $this->publicationStatusTransportFailureRetryable($throwable)
                    && \hrtime(true) / 1_000_000_000 < $deadline
                ) {
                    $this->waitForPublicationDelay(0.2, $deadline);
                    continue;
                }
                return [
                    'ok' => false,
                    'ready' => false,
                    'reason' => $throwable->getMessage(),
                    'home' => $this->paths->home(),
                ];
            }
        }
    }

    /**
     * Administrator-only full host status.
     *
     * @return array<string,mixed>
     */
    public function administratorStatus(): array
    {
        try {
            $response = $this->client->administratorStatus();
            if (!($response['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'ready' => false,
                    'reason' => (string)($response['error']['message'] ?? 'Gateway status rejected.'),
                ];
            }
            $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
            return ['ok' => true] + $payload;
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'ready' => false,
                'reason' => $throwable->getMessage(),
                'home' => $this->paths->home(),
            ];
        }
    }

    /**
     * Establish or adopt a trusted WLS 2.0 gateway.
     *
     * @return array<string,mixed>
     */
    public function prepare(?array $observedStatus = null): array
    {
        $atomicWrite = GatewayProjectStateFilesystem::atomicWriteRuntimeCapability();
        if (($atomicWrite['ready'] ?? false) !== true) {
            return [
                'ok' => false,
                'ready' => false,
                'state' => 'RUNTIME_UNSUPPORTED',
                'reason' => (string)($atomicWrite['reason']
                    ?? 'WLS project-state atomic publication is unavailable.'),
                'project_state_atomic_write' => $atomicWrite,
            ];
        }
        $status = $observedStatus ?? $this->status(5.0);
        if (($status['ok'] ?? false)
            && ($status['protocol'] ?? '') === GatewayPaths::PROTOCOL
            && ($status['ready'] ?? false)
            && ($status['supervisor_ready'] ?? false)
        ) {
            return $status + ['established' => false];
        }
        $profile = $this->installedListenProfileOrDefault();
        $availability = $this->classifyPublicPortsReadOnly($profile);
        if (($availability['state'] ?? '') !== 'AVAILABLE') {
            return [
                'ok' => false,
                'ready' => false,
                'state' => (string)$availability['state'],
                'reason' => (string)$availability['reason'],
                'owner' => (string)($availability['owner'] ?? 'unknown'),
                'listen_profile' => $profile,
                'port_diagnostics' => $availability['diagnostics'] ?? [],
            ];
        }
        return [
            'ok' => false,
            'ready' => false,
            'state' => 'INSTALL_REQUIRED',
            'reason' => 'WLS 2.0 Gateway is not ready. Installation is an explicit administrator action; run server:gateway:install.',
            'listen_profile' => $profile,
            'port_diagnostics' => $availability['diagnostics'] ?? [],
        ];
    }

    /**
     * Explicit administrator-only initial installation.
     *
     * @return array<string,mixed>
     */
    public function install(string $packageDirectory, string $profile = 'default'): array
    {
        $atomicWrite = GatewayProjectStateFilesystem::atomicWriteRuntimeCapability();
        if (($atomicWrite['ready'] ?? false) !== true) {
            return [
                'ok' => false,
                'ready' => false,
                'state' => 'RUNTIME_UNSUPPORTED',
                'reason' => (string)($atomicWrite['reason']
                    ?? 'WLS project-state atomic publication is unavailable.'),
                'project_state_atomic_write' => $atomicWrite,
            ];
        }
        $portCheck = $this->publicPortsAvailable($profile);
        if (!($portCheck['ok'] ?? false)) {
            return [
                'ok' => false,
                'ready' => false,
                'state' => (string)($portCheck['state'] ?? 'PORT_TAKEN'),
                'reason' => (string)$portCheck['reason'],
                'owner' => (string)($portCheck['owner'] ?? 'unknown'),
                'listen_profile' => $profile,
                'port_diagnostics' => $portCheck['diagnostics'] ?? [],
            ];
        }

        $staged = null;
        $service = null;
        $activated = false;
        try {
            $staged = $this->packages->stage($packageDirectory, $profile);
            $service = $this->platform->installDefinition($profile);
            $this->packages->activate((string)$staged['slot']);
            $activated = true;
            $this->platform->start((string)$service['kind']);
        } catch (\Throwable $throwable) {
            $cleanupFailures = [];
            if (\is_array($service)) {
                try {
                    $this->platform->removeDefinition((string)$service['kind']);
                } catch (\Throwable $cleanup) {
                    $cleanupFailures[] = 'platform definition: '
                        . GatewayBoundedText::singleLine(
                            $cleanup->getMessage(),
                            512,
                            'cleanup failed',
                        );
                }
            }
            if (\is_array($staged)) {
                try {
                    if ($activated) {
                        $this->packages->rollbackActivation(
                            (string)$staged['slot'],
                            (string)$staged['previous_active_slot'],
                        );
                    } else {
                        $this->packages->discardStaged((string)$staged['slot']);
                    }
                } catch (\Throwable $cleanup) {
                    $cleanupFailures[] = 'runtime slot: '
                        . GatewayBoundedText::singleLine(
                            $cleanup->getMessage(),
                            512,
                            'rollback failed',
                        );
                }
            }
            return [
                'ok' => false,
                'ready' => false,
                'state' => $cleanupFailures === []
                    ? 'INSTALL_FAILED'
                    : 'INSTALL_ROLLBACK_FAILED',
                'reason' => GatewayBoundedText::singleLine(
                    $throwable->getMessage(),
                    2048,
                    'Gateway installation failed.',
                ) . ($cleanupFailures === []
                    ? ''
                    : ' Cleanup also failed: ' . \implode('; ', $cleanupFailures)),
            ];
        }

        if ($this->paths->isTestMode()) {
            return [
                'ok' => true,
                'ready' => false,
                'state' => 'TEST_PACKAGE_INSTALLED',
                'reason' => 'The isolated test package is installed but is never a production-trusted gateway.',
                'slot' => $staged['slot'],
                'runtime_generation' => $staged['runtime_generation'],
                'service' => $service,
                'release_ready' => false,
                'test_mode' => true,
            ];
        }

        // Initial publication includes a mandatory 15-second candidate probe.
        // Leave enough budget for platform startup, native Broker fencing and
        // that probe without weakening any readiness condition.
        $deadline = self::monotonicNow() + 45.0;
        do {
            \usleep(100000);
            $status = $this->administratorStatus();
            if (($status['ok'] ?? false)
                && ($status['ready'] ?? false)
                && ($status['supervisor_ready'] ?? false)
                && ($status['broker_ready'] ?? false)
                && ($status['release_ready'] ?? false)
            ) {
                return $status + [
                    'installed' => true,
                    'slot' => $staged['slot'],
                    'runtime_generation' => $staged['runtime_generation'],
                ];
            }
        } while (self::monotonicNow() < $deadline);

        $rollbackReason = '';
        try {
            $this->platform->removeDefinition((string)$service['kind']);
            $this->packages->rollbackActivation(
                (string)$staged['slot'],
                (string)$staged['previous_active_slot'],
            );
        } catch (\Throwable $throwable) {
            $rollbackReason = ' Rollback requires attention: ' . $throwable->getMessage();
        }
        return [
            'ok' => false,
            'ready' => false,
            'state' => 'START_TIMEOUT',
            'reason' => (string)($status['reason'] ?? 'Gateway did not prove release readiness within 45 seconds.')
                . $rollbackReason,
            'slot' => $staged['slot'],
        ];
    }

    /**
     * Stage a self-contained gateway and its disabled system definition while
     * the verified legacy owner continues serving 80/443.
     *
     * @return array<string,mixed>
     */
    public function stageLegacyPromotion(
        string $packageDirectory,
        string $profile = 'default',
    ): array {
        GatewayProjectStateFilesystem::assertAtomicWriteRuntimeCapability();
        try {
            $installed = $this->platform->installedDefinition();
            throw new \RuntimeException(
                'A host gateway platform definition already exists: '
                . (string)$installed['kind']
            );
        } catch (\RuntimeException $exception) {
            if (!\str_contains(
                $exception->getMessage(),
                'platform service metadata is unavailable',
            )) {
                throw $exception;
            }
        }
        $staged = null;
        $service = null;
        try {
            $staged = $this->packages->stage($packageDirectory, $profile);
            $service = $this->platform->installDefinition($profile);
            $this->platform->secureInstalledRuntime();
            return $staged + ['service' => $service, 'promotion_staged' => true];
        } catch (\Throwable $throwable) {
            $cleanupFailures = [];
            if (\is_array($service)) {
                try {
                    $this->platform->removeDefinition((string)$service['kind']);
                } catch (\Throwable $cleanup) {
                    $cleanupFailures[] = 'platform definition: '
                        . GatewayBoundedText::singleLine(
                            $cleanup->getMessage(),
                            512,
                            'cleanup failed',
                        );
                }
            }
            if (\is_array($staged)) {
                try {
                    $this->packages->discardStaged((string)$staged['slot']);
                } catch (\Throwable $cleanup) {
                    $cleanupFailures[] = 'runtime slot: '
                        . GatewayBoundedText::singleLine(
                            $cleanup->getMessage(),
                            512,
                            'cleanup failed',
                        );
                }
            }
            if ($cleanupFailures !== []) {
                throw new \RuntimeException(
                    GatewayBoundedText::singleLine(
                        $throwable->getMessage(),
                        2048,
                        'Gateway promotion staging failed.',
                    ) . ' Cleanup also failed: ' . \implode('; ', $cleanupFailures),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    /**
     * Activate a previously staged promotion only after the legacy owner has
     * drained and released the public ports.
     *
     * @param array<string,mixed> $staged
     * @return array<string,mixed>
     */
    public function activateLegacyPromotion(array $staged): array
    {
        GatewayProjectStateFilesystem::assertAtomicWriteRuntimeCapability();
        $service = \is_array($staged['service'] ?? null)
            ? $staged['service']
            : [];
        $slot = (string)($staged['slot'] ?? '');
        $kind = (string)($service['kind'] ?? '');
        if (!\in_array($slot, ['A', 'B'], true) || $kind === '') {
            throw new \InvalidArgumentException(
                'Legacy promotion staging receipt is invalid.'
            );
        }
        $ports = $this->publicPortsAvailable(
            (string)($staged['profile'] ?? 'default'),
        );
        if (!($ports['ok'] ?? false)) {
            throw new \RuntimeException(
                'Legacy owner did not release the gateway ports: '
                . (string)($ports['reason'] ?? 'port unavailable')
            );
        }
        $this->packages->activate($slot);
        $this->platform->start($kind);
        if ($this->paths->isTestMode()) {
            return [
                'ok' => true,
                'ready' => false,
                'state' => 'TEST_PROMOTION_ACTIVATED',
                'slot' => $slot,
            ];
        }
        $timeoutSeconds = self::legacyPromotionReadinessTimeoutSeconds();
        $deadline = self::monotonicNow() + $timeoutSeconds;
        do {
            \usleep(100000);
            $status = $this->administratorStatus();
            if (($status['ok'] ?? false) && ($status['ready'] ?? false)) {
                return $status + ['promotion_activated' => true, 'slot' => $slot];
            }
        } while (self::monotonicNow() < $deadline);
        throw new \RuntimeException(
            'Promoted host gateway did not become ready within '
                . \number_format($timeoutSeconds, 0, '.', '') . ' seconds.'
        );
    }

    /**
     * A production cold publication includes both the candidate shadow probe
     * and the public stability observation. Reuse the explicit-start budget so
     * promotion cannot time out on the exact boundary of those mandatory
     * windows and roll a healthy gateway back to legacy.
     */
    private static function legacyPromotionReadinessTimeoutSeconds(): float
    {
        return self::EXPLICIT_START_READY_TIMEOUT_SECONDS;
    }

    /**
     * @param array<string,mixed> $staged
     */
    public function abortLegacyPromotion(array $staged, bool $activated): void
    {
        $service = \is_array($staged['service'] ?? null)
            ? $staged['service']
            : [];
        $kind = (string)($service['kind'] ?? '');
        $slot = (string)($staged['slot'] ?? '');
        if ($kind !== '') {
            // Package rollback must never remove the executable behind a
            // service definition whose durable removal has not completed.
            // removeDefinition owns an idempotent pending-removal fence, so
            // surface failures and let the promotion journal retry them.
            $this->platform->removeDefinition($kind);
        }
        if (!\in_array($slot, ['A', 'B'], true)) {
            return;
        }
        $activeSlotFile = $this->paths->activeSlotFile();
        $actualActive = (\file_exists($activeSlotFile) || \is_link($activeSlotFile))
            ? $this->paths->activeSlot()
            : '';
        // Activation can complete before the readiness wait throws. The
        // caller cannot set its boolean after an exception, so the durable
        // active-slot pointer is the authoritative rollback signal.
        $activated = $activated || \hash_equals($slot, $actualActive);
        if ($activated) {
            $previous = \strtoupper(\trim((string)(
                $staged['previous_active_slot'] ?? ''
            )));
            if (\hash_equals($slot, $actualActive)) {
                $this->packages->rollbackActivation($slot, $previous);
            } elseif (($previous !== '' && \hash_equals($previous, $actualActive))
                || ($previous === '' && $actualActive === '')
            ) {
                // A crash can occur after the package manager completed its
                // durable pointer/slot rollback but before the promotion
                // journal reached ROLLED_BACK. Treat that exact end state as
                // an idempotent success; any other active slot remains fatal.
                return;
            } else {
                throw new \RuntimeException(
                    'Gateway active slot no longer matches promotion rollback.'
                );
            }
        } else {
            $slotDirectory = $this->paths->slotDir($slot);
            if (!\file_exists($slotDirectory) && !\is_link($slotDirectory)) {
                return;
            }
            $this->packages->discardStaged($slot);
        }
    }

    /**
     * Stop only the promoted data plane and retain its definition and slot.
     * Rollback callers use this reversible fence to release 80/443 before
     * proving the legacy owner has recovered; irreversible package/service
     * cleanup must happen only after that proof succeeds.
     *
     * @param array<string,mixed> $staged
     */
    public function quiesceLegacyPromotion(array $staged): void
    {
        $service = \is_array($staged['service'] ?? null)
            ? $staged['service']
            : [];
        $kind = \trim((string)($service['kind'] ?? ''));
        if ($kind === '' || \strlen($kind) > 191) {
            throw new \InvalidArgumentException(
                'Legacy promotion service identity is invalid.'
            );
        }
        $this->platform->stop($kind);
    }

    /** @return array<string,mixed> */
    public function enrollCurrentProjectForPromotion(
        GatewayRegistrationBuilder $builder,
        string $projectRoot,
    ): array {
        $projectFacts = $this->withPromotionProjectOwnerIdentity(
            $projectRoot,
            static fn (): array => [
                'project_uuid' => $builder->projectUuid(),
                'certificate_roots' => $builder->enrollmentCertificateRoots($projectRoot),
                'allowed_domains' => $builder->desiredDomains(),
            ],
        );
        $projectUuid = (string)$projectFacts['project_uuid'];
        $ownerProof = [];
        if (\PHP_OS_FAMILY !== 'Windows') {
            $rootStatus = @\lstat($projectRoot);
            if (!\is_array($rootStatus) || \is_link($projectRoot)) {
                throw new \RuntimeException(
                    'Unable to verify the promoted project root owner.'
                );
            }
            $ownerProof = [
                'project_owner_uid' => (int)$rootStatus['uid'],
                'project_owner_gid' => (int)$rootStatus['gid'],
            ];
        }
        $enrollment = self::enrollmentRequestEnvelope([
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'certificate_roots' => (array)$projectFacts['certificate_roots'],
            'allowed_domains' => (array)$projectFacts['allowed_domains'],
            'capabilities' => ['acme_http_01' => true],
            ...$ownerProof,
        ]);
        $response = $this->client->request('enroll', $enrollment);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Promotion enrollment failed.')
            );
        }
        $payload = \is_array($response['payload'] ?? null)
            ? $response['payload']
            : [];
        $credential = \is_array($payload['credential'] ?? null)
            ? $payload['credential']
            : [];
        $receipt = self::validateCredentialReceipt(
            $credential,
            \is_array($payload['credential_receipt'] ?? null)
                ? $payload['credential_receipt']
                : [],
            $enrollment,
        );
        (new GatewayCredentialStore())->install($credential, $projectUuid);
        if (!$this->paths->isTestMode()) {
            $this->awaitPromotionEnrollmentDurability(
                (int)$receipt['security_generation'],
            );
        }
        unset($payload['credential']);
        $payload['credential_installed'] = true;
        $payload['certificate_access'] = 'broker_auth_snap';
        return $payload;
    }

    /**
     * Add a stable idempotency fence to the complete project enrollment facts.
     * Replaying this exact envelope lets a project finish a host-committed but
     * locally interrupted credential install without minting new credentials.
     *
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    public static function enrollmentRequestEnvelope(array $facts): array
    {
        $projectUuid = \strtolower(\trim((string)($facts['project_uuid'] ?? '')));
        $projectRoot = \trim((string)($facts['project_root'] ?? ''));
        $roots = \is_array($facts['certificate_roots'] ?? null)
            ? $facts['certificate_roots']
            : [];
        $domains = \is_array($facts['allowed_domains'] ?? null)
            ? $facts['allowed_domains']
            : [];
        $capabilities = \is_array($facts['capabilities'] ?? null)
            ? $facts['capabilities']
            : [];
        if (\preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $projectUuid,
        ) !== 1
            || $projectRoot === ''
            || \strlen($projectRoot) > 4096
            || \str_contains($projectRoot, "\0")
            || $roots === []
            || \count($roots) > 32
            || $domains === []
            || \count($domains) > 256
        ) {
            throw new \RuntimeException('Gateway enrollment facts are outside protocol bounds.');
        }
        $normalizedRoots = [];
        foreach ($roots as $alias => $path) {
            $alias = \is_string($alias) ? $alias : '';
            $path = \is_string($path) ? \trim($path) : '';
            if (\preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $alias) !== 1
                || $path === ''
                || \strlen($path) > 4096
                || \str_contains($path, "\0")
                || isset($normalizedRoots[$alias])
            ) {
                throw new \RuntimeException('Gateway enrollment certificate root is invalid.');
            }
            $normalizedRoots[$alias] = $path;
        }
        \ksort($normalizedRoots, SORT_STRING);
        $normalizedDomains = [];
        foreach ($domains as $domain) {
            $domain = \strtolower(\rtrim(\trim((string)$domain), '.'));
            if ($domain === '' || \strlen($domain) > 253) {
                throw new \RuntimeException('Gateway enrollment domain is invalid.');
            }
            $normalizedDomains[$domain] = true;
        }
        $normalizedDomains = \array_keys($normalizedDomains);
        \sort($normalizedDomains, SORT_STRING);
        $normalizedCapabilities = [];
        foreach ($capabilities as $name => $enabled) {
            if (!\is_string($name)
                || \preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $name) !== 1
                || !\is_bool($enabled)
            ) {
                throw new \RuntimeException('Gateway enrollment capability is invalid.');
            }
            $normalizedCapabilities[$name] = $enabled;
        }
        \ksort($normalizedCapabilities, SORT_STRING);
        $envelope = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'certificate_roots' => $normalizedRoots,
            'allowed_domains' => $normalizedDomains,
            'capabilities' => $normalizedCapabilities,
        ];
        foreach (['project_owner_uid', 'project_owner_gid'] as $ownerField) {
            if (\array_key_exists($ownerField, $facts)) {
                if (!\is_int($facts[$ownerField]) || (int)$facts[$ownerField] < 0) {
                    throw new \RuntimeException('Gateway enrollment owner proof is invalid.');
                }
                $envelope[$ownerField] = (int)$facts[$ownerField];
            }
        }
        // request_digest is deliberately limited to the canonical wire facts
        // known by the project. The Controller derives a separate
        // authenticated_desired_digest after binding the OS peer and Broker
        // owner. Conflating the two makes POSIX and Windows enrollment
        // impossible because a project cannot predict Broker-derived facts.
        $requestDigest = self::enrollmentRequestDigest($envelope);
        $envelope['request_digest'] = $requestDigest;
        $envelope['idempotency_key'] = \substr(\hash(
            'sha256',
            $projectUuid . ':enroll:' . $requestDigest,
        ), 0, 40);
        return $envelope;
    }

    /** @param array<string,mixed> $wireFacts */
    public static function enrollmentRequestDigest(array $wireFacts): string
    {
        unset($wireFacts['request_digest'], $wireFacts['idempotency_key']);
        return \hash('sha256', GatewayClient::canonicalJson($wireFacts));
    }

    /**
     * Verify the Controller's durable enrollment commit with the newly minted
     * project secret before that secret is installed locally.
     *
     * @param array<string,mixed> $credential
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $enrollment
     * @return array<string,mixed>
     */
    public static function validateCredentialReceipt(
        array $credential,
        array $receipt,
        array $enrollment,
    ): array {
        $expectedFields = self::ENROLLMENT_RECEIPT_FIELDS;
        $actualFields = \array_keys($receipt);
        \sort($expectedFields, SORT_STRING);
        \sort($actualFields, SORT_STRING);
        $projectUuid = (string)($enrollment['project_uuid'] ?? '');
        $secret = \strtolower(\trim((string)($credential['secret'] ?? '')));
        $hostId = \strtolower(\trim((string)($credential['host_id'] ?? '')));
        $credentialId = \strtolower(\trim((string)($credential['credential_id'] ?? '')));
        $domainsDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson((array)($enrollment['allowed_domains'] ?? [])),
        );
        $capabilitiesDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson((array)($enrollment['capabilities'] ?? [])),
        );
        $signature = \strtolower(\trim((string)($receipt['signature'] ?? '')));
        $signed = $receipt;
        unset($signed['signature']);
        $expectedSignature = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? \hash_hmac('sha256', GatewayClient::canonicalJson($signed), $secret)
            : '';
        if ($actualFields !== $expectedFields
            || ($credential['schema_version'] ?? null) !== 1
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)($credential['protocol'] ?? ''))
            || \preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1
            || !\hash_equals($projectUuid, (string)($credential['project_uuid'] ?? ''))
            || \preg_match('/\A[a-f0-9]{32}\z/D', $credentialId) !== 1
            || $expectedSignature === ''
            || ($receipt['schema_version'] ?? null) !== 1
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)($receipt['protocol'] ?? ''))
            || !\hash_equals($hostId, (string)($receipt['host_id'] ?? ''))
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($receipt['gateway_epoch'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($receipt['tx_id'] ?? '')) !== 1
            || !\hash_equals($projectUuid, (string)($receipt['project_uuid'] ?? ''))
            || !\is_int($receipt['security_generation'] ?? null)
            || (int)$receipt['security_generation'] < 1
            || !\is_int($receipt['credential_generation'] ?? null)
            || (int)$receipt['credential_generation'] < 1
            || !\is_int($credential['credential_generation'] ?? null)
            || (int)$credential['credential_generation']
                !== (int)$receipt['credential_generation']
            || !\hash_equals($credentialId, (string)($receipt['credential_id'] ?? ''))
            || !\hash_equals($domainsDigest, (string)($receipt['domains_digest'] ?? ''))
            || !\hash_equals($capabilitiesDigest, (string)($receipt['capabilities_digest'] ?? ''))
            || !\hash_equals(
                (string)($enrollment['request_digest'] ?? ''),
                (string)($receipt['request_digest'] ?? ''),
            )
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($receipt['authenticated_desired_digest'] ?? ''),
            ) !== 1
            || !\hash_equals(
                (string)($enrollment['idempotency_key'] ?? ''),
                (string)($receipt['idempotency_key'] ?? ''),
            )
            || !\hash_equals('COMMITTED', (string)($receipt['state'] ?? ''))
            || !\is_string($receipt['issued_at'] ?? null)
            || \strlen((string)$receipt['issued_at']) > 128
            || \strtotime((string)$receipt['issued_at']) === false
            || \preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
            || !\hash_equals($expectedSignature, $signature)
        ) {
            throw new \RuntimeException(
                'Gateway enrollment returned no valid durable credential receipt.'
            );
        }
        return $receipt;
    }

    private function awaitPromotionEnrollmentDurability(
        int $expectedSecurityGeneration,
    ): void
    {
        if ($expectedSecurityGeneration < 1) {
            throw new \RuntimeException(
                'Promotion enrollment did not acknowledge a durable security generation.'
            );
        }
        $timeoutSeconds = self::PROMOTION_ENROLLMENT_READY_TIMEOUT_SECONDS;
        $deadline = \hrtime(true) / 1_000_000_000 + $timeoutSeconds;
        $lastStatus = [];
        do {
            $this->serviceProgressCallback();
            $status = $this->administratorStatus();
            $lastStatus = $status;
            $controlGeneration = (int)($status['control_generation'] ?? 0);
            if (($status['ok'] ?? false)
                && ($status['ready'] ?? false)
                && ($status['supervisor_ready'] ?? false)
                && ($status['broker_ready'] ?? false)
                && ($status['release_ready'] ?? false)
                && $controlGeneration >= $expectedSecurityGeneration
            ) {
                return;
            }
            \usleep(100000);
        } while (\hrtime(true) / 1_000_000_000 < $deadline);
        throw new \RuntimeException(
            'Promotion enrollment was not durably observable on a ready gateway within '
                . \number_format($timeoutSeconds, 0, '.', '') . ' seconds: '
                . (string)($lastStatus['reason']
                    ?? $lastStatus['state']
                    ?? 'status unavailable')
        );
    }

    /**
     * Promotion is an administrator transaction, while project protocol calls
     * must retain the enrolled project's OS peer identity. On POSIX, scope the
     * registration to the verified project owner and always restore the
     * administrator identity before rollback or cleanup can run.
     *
     * @return array<string,mixed>
     */
    public function registerCurrentProjectForPromotion(
        string $instanceName,
        string $projectRoot,
    ): array {
        return $this->withPromotionProjectOwnerIdentity(
            $projectRoot,
            function () use ($instanceName): array {
                $registration = (new GatewayRegistrationBuilder())->build($instanceName);
                return $this->submitRegistration($registration);
            },
        );
    }

    /**
     * A legacy promotion is committed only after the project-owned Agent has
     * published the authenticated join backend, the expected routes are
     * ACTIVE, and real SNI/Host/TLS/backend identity probes remain healthy
     * across a complete heartbeat interval.
     *
     * @return array<string,mixed>
     */
    public function awaitPromotionProjectActivation(
        string $instanceName,
        string $projectRoot,
        float $timeoutSeconds = 120.0,
        float $stableSeconds = 12.0,
    ): array {
        return $this->withPromotionProjectOwnerIdentity(
            $projectRoot,
            function () use ($instanceName, $timeoutSeconds, $stableSeconds): array {
                $timeoutSeconds = \max(1.0, $timeoutSeconds);
                $stableSeconds = \max(1.0, \min(30.0, $stableSeconds));
                $deadline = \hrtime(true) / 1_000_000_000 + $timeoutSeconds;
                $healthySince = 0.0;
                $lastReason = 'project Agent has not published an active route';
                $lastStatus = [];
                $builder = new GatewayRegistrationBuilder();
                $probe = new GatewayPublicRouteProbe();

                while (true) {
                    $this->serviceProgressCallback();
                    $now = \hrtime(true) / 1_000_000_000;
                    $status = $this->status(1.0);
                    $lastStatus = $status;
                    $registration = null;
                    try {
                        if (($status['ok'] ?? false) && ($status['ready'] ?? false)) {
                            $registration = $builder->build($instanceName);
                        }
                    } catch (\Throwable $throwable) {
                        $lastReason = $throwable->getMessage();
                    }

                    $routesHealthy = \is_array($registration);
                    $expectedRoutes = [];
                    if (\is_array($registration)) {
                        foreach ((array)($registration['routes'] ?? []) as $route) {
                            if (\is_array($route)) {
                                $routeId = (string)($route['route_id'] ?? '');
                                if ($routeId !== '') {
                                    $expectedRoutes[$routeId] = true;
                                }
                            }
                        }
                    }
                    $observedRoutes = [];
                    foreach ((array)($status['active_routes'] ?? []) as $route) {
                        if (\is_array($route)) {
                            $observedRoutes[(string)($route['route_id'] ?? '')] = $route;
                        }
                    }
                    if ($expectedRoutes === []) {
                        $routesHealthy = false;
                    }
                    foreach (\array_keys($expectedRoutes) as $routeId) {
                        $route = $observedRoutes[$routeId] ?? null;
                        $lease = \is_array($route)
                            && \is_array($route['instances'][$instanceName] ?? null)
                                ? $route['instances'][$instanceName]
                                : null;
                        if (!\is_array($route)
                            || !\hash_equals('ACTIVE', (string)($route['status'] ?? ''))
                            || !\is_array($lease)
                            || !\hash_equals('ACTIVE', (string)($lease['status'] ?? ''))
                        ) {
                            $routesHealthy = false;
                            $lastReason = 'one or more expected routes are not ACTIVE';
                            break;
                        }
                    }

                    $instanceLease = \is_array($registration)
                        ? self::promotionInstanceLease(
                            (array)($status['instances'] ?? []),
                            (string)($registration['project_uuid'] ?? ''),
                            $instanceName,
                        )
                        : null;
                    $instanceHealthy = \is_array($instanceLease)
                        && \hash_equals(
                            'ACTIVE',
                            (string)($instanceLease['status'] ?? ''),
                        )
                        && (int)($instanceLease['generation'] ?? 0)
                            === (int)($registration['instance_generation'] ?? -1);
                    if (!$instanceHealthy && \is_array($registration)) {
                        $lastReason = 'the promoted project instance lease is not ACTIVE';
                    }

                    $publicHealthy = false;
                    if ($routesHealthy && $instanceHealthy && \is_array($registration)) {
                        try {
                            $publicHealthy = $probe->registrationIsHealthy(
                                $registration,
                                (int)($status['public_https'] ?? 0),
                            );
                        } catch (\Throwable $throwable) {
                            $lastReason = $throwable->getMessage();
                        }
                        if (!$publicHealthy) {
                            $lastReason = 'the public SNI/Host/TLS/backend identity probe failed';
                        }
                    }
                    $coreHealthy = ($status['ok'] ?? false)
                        && ($status['ready'] ?? false)
                        && ($status['supervisor_ready'] ?? false)
                        && ($status['data_plane']['running'] ?? false)
                        && (string)($status['state'] ?? '') !== 'DATA_PLANE_DOWN';
                    if (!$coreHealthy) {
                        $lastReason = (string)($status['reason']
                            ?? $status['state']
                            ?? 'gateway core is not ready');
                    }

                    if ($coreHealthy && $routesHealthy && $instanceHealthy && $publicHealthy) {
                        $healthySince = $healthySince > 0.0 ? $healthySince : $now;
                        $stableElapsed = $now - $healthySince;
                        if ($stableElapsed >= $stableSeconds) {
                            return [
                                'state' => 'ACTIVE',
                                'instance_id' => $instanceName,
                                'route_count' => \count($expectedRoutes),
                                'stable_seconds' => \round($stableElapsed, 3),
                                'gateway_epoch' => (string)($status['epoch'] ?? ''),
                                'gateway_generation' => (int)($status['generation'] ?? 0),
                                'public_http' => (int)($status['public_http'] ?? 0),
                                'public_https' => (int)($status['public_https'] ?? 0),
                            ];
                        }
                        $lastReason = 'the public route is healthy but has completed only '
                            . \number_format($stableElapsed, 1, '.', '') . ' of '
                            . \number_format($stableSeconds, 1, '.', '')
                            . ' required stability seconds';
                    } else {
                        $healthySince = 0.0;
                    }
                    if (self::promotionActivationTimedOut(
                        $now,
                        $deadline,
                        $stableSeconds,
                    )) {
                        throw new \RuntimeException(
                            'Promoted project did not become durably reachable through its '
                                . 'Gateway Agent within '
                                . \number_format($timeoutSeconds, 0, '.', '')
                                . ' seconds: ' . $lastReason
                                . '; gateway_state=' . (string)($lastStatus['state'] ?? 'unavailable')
                        );
                    }
                    \usleep(200000);
                }
            },
        );
    }

    /**
     * Convergence and durability are independent promotion gates. The caller
     * receives the full convergence budget and then one complete stability
     * window, so a route that becomes healthy near the convergence deadline
     * is not rejected before it can prove durable health.
     */
    private static function promotionActivationTimedOut(
        float $now,
        float $convergenceDeadline,
        float $stableSeconds,
    ): bool {
        return $now >= $convergenceDeadline + \max(0.0, $stableSeconds);
    }

    /**
     * Controller status stores instances under project UUID so two projects
     * may safely reuse the same local instance name. Older flat status payloads
     * remain accepted only when they identify one unambiguous matching row.
     *
     * @param array<mixed> $instances
     * @return array<string,mixed>|null
     */
    private static function promotionInstanceLease(
        array $instances,
        string $projectUuid,
        string $instanceName,
    ): ?array {
        $projectUuid = \strtolower(\trim($projectUuid));
        $instanceName = \trim($instanceName);
        if ($projectUuid === '' || $instanceName === '') {
            return null;
        }
        foreach ($instances as $candidateProjectUuid => $bucket) {
            if (!\is_string($candidateProjectUuid)
                || !\hash_equals(
                    $projectUuid,
                    \strtolower(\trim($candidateProjectUuid)),
                )
                || !\is_array($bucket)
            ) {
                continue;
            }
            $candidate = $bucket[$instanceName] ?? null;
            if (\is_array($candidate)
                && \hash_equals(
                    $instanceName,
                    (string)($candidate['instance_id'] ?? ''),
                )
            ) {
                return $candidate;
            }
            foreach ($bucket as $row) {
                if (\is_array($row)
                    && \hash_equals(
                        $instanceName,
                        (string)($row['instance_id'] ?? ''),
                    )
                ) {
                    return $row;
                }
            }
            return null;
        }

        $matches = [];
        foreach ($instances as $row) {
            if (!\is_array($row)
                || !\hash_equals(
                    $instanceName,
                    (string)($row['instance_id'] ?? ''),
                )
            ) {
                continue;
            }
            $rowProjectUuid = \strtolower(\trim((string)(
                $row['project_uuid'] ?? ''
            )));
            if ($rowProjectUuid !== '' && !\hash_equals($projectUuid, $rowProjectUuid)) {
                continue;
            }
            $matches[] = $row;
        }
        return \count($matches) === 1 ? $matches[0] : null;
    }

    private function withPromotionProjectOwnerIdentity(
        string $projectRoot,
        \Closure $operation,
    ): mixed {
        if (\PHP_OS_FAMILY === 'Windows') {
            return $operation();
        }
        foreach ([
            'posix_geteuid',
            'posix_getegid',
            'posix_getgroups',
            'posix_getpwuid',
            'posix_initgroups',
            'posix_seteuid',
            'posix_setegid',
        ] as $function) {
            if (!\function_exists($function)) {
                throw new \RuntimeException(
                    'POSIX effective identity support is required for legacy promotion.'
                );
            }
        }
        $canonical = \realpath($projectRoot);
        $owner = @\lstat($projectRoot);
        if (!\is_string($canonical)
            || !\is_dir($canonical)
            || \is_link($projectRoot)
            || !\is_array($owner)
            || !\is_int($owner['uid'] ?? null)
            || !\is_int($owner['gid'] ?? null)
        ) {
            throw new \RuntimeException(
                'Unable to establish the promoted project owner identity.'
            );
        }
        $targetUid = (int)$owner['uid'];
        $targetGid = (int)$owner['gid'];
        $account = \posix_getpwuid($targetUid);
        $targetUser = \is_array($account) ? \trim((string)($account['name'] ?? '')) : '';
        $ownerHome = \is_array($account)
            ? \realpath((string)($account['dir'] ?? ''))
            : false;
        if ($targetUser === ''
            || !\is_string($ownerHome)
            || $ownerHome === ''
            || !\is_dir($ownerHome)
            || \is_link((string)($account['dir'] ?? ''))
        ) {
            throw new \RuntimeException(
                'Unable to establish the promoted project owner HOME.'
            );
        }
        $originalUid = \posix_geteuid();
        $originalGid = \posix_getegid();
        $originalAccount = \posix_getpwuid($originalUid);
        $originalUser = \is_array($originalAccount)
            ? \trim((string)($originalAccount['name'] ?? ''))
            : '';
        $originalGroups = \posix_getgroups();
        if ($originalUser === '' || !\is_array($originalGroups)) {
            throw new \RuntimeException(
                'Unable to capture the administrator supplementary identity.'
            );
        }
        \sort($originalGroups, SORT_NUMERIC);
        if ($targetUid !== $originalUid && $originalUid !== 0) {
            throw new \RuntimeException(
                'Legacy promotion cannot assume the enrolled project owner identity.'
            );
        }
        $originalEnvironment = [];
        foreach (['HOME', 'XDG_STATE_HOME', 'WLS_EDGE_STATE_HOME'] as $name) {
            $originalEnvironment[$name] = \getenv($name);
        }

        $gidChanged = false;
        $uidChanged = false;
        $supplementaryChanged = false;
        try {
            if (!\putenv('HOME=' . $ownerHome)
                || !\putenv('XDG_STATE_HOME')
                || !\putenv('WLS_EDGE_STATE_HOME')
            ) {
                throw new \RuntimeException(
                    'Unable to assume the promoted project owner state environment.'
                );
            }
            if ($targetUid !== $originalUid || $targetGid !== $originalGid) {
                // initgroups may have changed part of the supplementary set
                // even when it reports failure. Mark the restoration duty
                // before invoking it so the administrator identity is always
                // rebuilt in finally.
                $supplementaryChanged = true;
                if (!@\posix_initgroups($targetUser, $targetGid)) {
                    throw new \RuntimeException(
                        'Unable to assume the promoted project supplementary groups.'
                    );
                }
            }
            if ($targetGid !== $originalGid) {
                if (!@\posix_setegid($targetGid)) {
                    throw new \RuntimeException(
                        'Unable to assume the promoted project owner group.'
                    );
                }
                $gidChanged = true;
            }
            if ($targetUid !== $originalUid) {
                if (!@\posix_seteuid($targetUid)) {
                    throw new \RuntimeException(
                        'Unable to assume the promoted project owner user.'
                    );
                }
                $uidChanged = true;
            }
            return $operation();
        } finally {
            $uidRestored = !$uidChanged || @\posix_seteuid($originalUid);
            $supplementaryRestored = !$supplementaryChanged
                || ($uidRestored && @\posix_initgroups($originalUser, $originalGid));
            $gidRestored = !$gidChanged
                || ($uidRestored
                    && $supplementaryRestored
                    && @\posix_setegid($originalGid));
            $restoredGroups = \posix_getgroups();
            if (\is_array($restoredGroups)) {
                \sort($restoredGroups, SORT_NUMERIC);
            }
            $supplementaryRestored = $supplementaryRestored
                && \is_array($restoredGroups)
                && $restoredGroups === $originalGroups;
            $environmentRestored = true;
            foreach ($originalEnvironment as $name => $value) {
                $environmentRestored = \putenv(
                    $value === false ? $name : $name . '=' . $value,
                ) && $environmentRestored;
            }
            if (!$uidRestored
                || !$gidRestored
                || !$supplementaryRestored
                || !$environmentRestored
            ) {
                throw new \RuntimeException(
                    'Unable to restore the administrator identity or environment after project registration.'
                );
            }
        }
    }

    /**
     * Stage a signed inactive slot for the explicit upgrade transaction.
     *
     * @return array<string,mixed>
     */
    public function stagePackage(string $packageDirectory, string $profile = 'default'): array
    {
        return $this->packages->stage($packageDirectory, $profile);
    }

    /**
     * Activate a complete inactive package through the stable platform
     * launcher. The five-minute observation continues in the root Broker; this
     * command only returns after the new Controller and data plane prove their
     * identity and readiness.
     *
     * @return array<string,mixed>
     */
    public function upgrade(string $packageDirectory, string $profile = 'default'): array
    {
        $service = $this->platform->installedDefinition();
        $before = null;
        if (!$this->paths->isTestMode()) {
            $before = $this->administratorStatus();
            if (!($before['ok'] ?? false) || !($before['ready'] ?? false)) {
                throw new \RuntimeException(
                    'Gateway must be healthy before an A/B package upgrade: '
                    . (string)($before['reason'] ?? 'status unavailable')
                );
            }
        }
        $staged = null;
        $observation = null;
        try {
            $staged = $this->packages->stage($packageDirectory, $profile);
            // Platform limits and sandbox policy are part of the gateway
            // release contract too. Refresh only the immutable supervisor
            // definition: installation-time permission sealing must not race
            // the running Controller's atomic state publications.
            $service = $this->platform->refreshDefinition($profile);
            $observation = $this->packages->beginUpgradeActivation($staged);
            $this->platform->restartControlPlane((string)$service['kind']);
            if ($this->paths->isTestMode()) {
                return [
                    'accepted' => true,
                    'ready' => false,
                    'state' => 'TEST_UPGRADE_OBSERVING',
                    'platform_service' => (string)$service['kind'],
                    'slot' => (string)$staged['slot'],
                    'runtime_generation' => (string)$staged['runtime_generation'],
                    'observation' => $observation,
                ];
            }
            $status = $this->awaitUpgradeIdentity(
                (string)$staged['slot'],
                (string)$staged['runtime_generation'],
            );
            if ($status !== null) {
                return $status + [
                    'accepted' => true,
                    'platform_service' => (string)$service['kind'],
                    'observation' => $observation,
                ];
            }
            throw new \RuntimeException(
                'The candidate gateway package did not become identity-verified and ready within '
                . (int)self::upgradeReadinessTimeoutSeconds() . ' seconds.'
            );
        } catch (\Throwable $throwable) {
            if (\is_array($observation)) {
                try {
                    $previousSlot = (string)$staged['previous_active_slot'];
                    $previousGeneration = \is_array($before)
                        ? (string)($before['runtime_generation'] ?? '')
                        : '';
                    $this->packages->rollbackUpgradeActivation(
                        (string)$staged['slot'],
                        $previousSlot,
                    );
                    $this->platform->restartControlPlane((string)$service['kind']);
                    if (!$this->paths->isTestMode()
                        && $this->awaitUpgradeIdentity($previousSlot, $previousGeneration) === null
                    ) {
                        throw new \RuntimeException(
                            'The previous gateway slot did not recover its verified identity after rollback.'
                        );
                    }
                    $this->packages->discardStaged((string)$staged['slot']);
                } catch (\Throwable $rollback) {
                    throw new \RuntimeException(
                        $throwable->getMessage()
                        . ' Automatic A/B rollback also failed: '
                        . $rollback->getMessage(),
                        0,
                        $throwable,
                    );
                }
            } elseif (\is_array($staged)) {
                try {
                    $this->packages->discardStaged((string)$staged['slot']);
                } catch (\Throwable $cleanup) {
                    throw new \RuntimeException(
                        GatewayBoundedText::singleLine(
                            $throwable->getMessage(),
                            2048,
                            'Gateway upgrade failed.',
                        ) . ' Staged-slot cleanup also failed: '
                            . GatewayBoundedText::singleLine(
                                $cleanup->getMessage(),
                                512,
                                'cleanup failed',
                            ),
                        0,
                        $throwable,
                    );
                }
            }
            throw $throwable;
        }
    }

    /** @return array<string,mixed>|null */
    private function awaitUpgradeIdentity(string $slot, string $runtimeGeneration): ?array
    {
        $deadline = \hrtime(true) / 1_000_000_000
            + self::upgradeReadinessTimeoutSeconds();
        do {
            \usleep(100000);
            $status = $this->administratorStatus();
            if (($status['ok'] ?? false)
                && ($status['ready'] ?? false)
                && \hash_equals($slot, (string)($status['active_slot'] ?? ''))
                && ($runtimeGeneration === '' || \hash_equals(
                    $runtimeGeneration,
                    (string)($status['runtime_generation'] ?? ''),
                ))
            ) {
                return $status;
            }
        } while (\hrtime(true) / 1_000_000_000 < $deadline);
        return null;
    }

    private static function upgradeReadinessTimeoutSeconds(): float
    {
        return self::UPGRADE_SHADOW_READY_SECONDS
            + self::UPGRADE_ACTIVATION_READY_SECONDS
            + self::UPGRADE_CONTROL_HANDOFF_SECONDS
            + self::UPGRADE_IDENTITY_PROBE_MARGIN_SECONDS;
    }

    /**
     * @return array<string,mixed>
     */
    public function register(string $instanceName): array
    {
        $builder = new GatewayRegistrationBuilder();
        return $this->submitRegistration($builder->build($instanceName));
    }

    /**
     * Submit an already-built registration while its caller still holds the
     * project desired-state and serving-publication transaction locks. This is
     * used by certificate revocation to keep the signed host publication and
     * native Worker ACK inside one project convergence transaction; rebuilding
     * here would release that fence and permit an interleaving publisher.
     *
     * @param array<string,mixed> $registration
     * @return array<string,mixed>
     */
    public function submitBuiltRegistration(array $registration): array
    {
        return $this->submitRegistration($registration);
    }

    /**
     * @param array<string,mixed> $registration
     * @return array<string,mixed>
     */
    private function submitRegistration(array $registration): array
    {
        $status = $this->status(5.0);
        if (!self::controlPlaneAcceptsRegistration($status)) {
            throw new \RuntimeException(
                'WLS Gateway control plane is not ready for project registration: '
                    . (string)($status['reason'] ?? $status['state'] ?? 'status unavailable')
            );
        }
        $registration['gateway_epoch'] = (string)($status['epoch'] ?? '');
        $registration['host_boot_id'] = self::currentAuthenticatedHostBootId($status);
        $renewals = new ProjectCertificateRenewalIntentStore();
        $intentId = $renewals->recordAttempt(
            $registration,
            (string)$registration['instance_id'],
            [
                'action' => 'register',
                'gateway_epoch' => (string)$registration['gateway_epoch'],
                'expected_route_generations' => [],
            ],
        );
        $attempted = $intentId !== null;
        $intentId ??= '';
        $lifecycle = new GatewayRegistrationLifecycle();
        $lifecycleAttempt = $lifecycle->beginMutation(
            (string)$registration['instance_id'],
            'register',
        );
        try {
            $response = $this->idempotentProjectMutation('register', $registration);
            if (!($response['ok'] ?? false)) {
                throw new \RuntimeException(
                    (string)($response['error']['message'] ?? 'Gateway registration failed.')
                );
            }
            $published = $this->awaitPublication(
                $response,
                false,
                (string)$registration['project_uuid'],
            );
            $leaseReceipt = self::leaseReceiptFromPublication($published);
            self::assertRegistrationSecurityRetirement(
                $registration,
                $published,
                $leaseReceipt,
            );
            $this->persistLeaseReceipt(
                (string)$registration['instance_id'],
                $leaseReceipt,
            );
            if ($attempted) {
                $renewals->acknowledge(
                    $registration,
                    (string)$registration['instance_id'],
                    'register',
                    $this->status(5.0),
                );
            }
            if (!$lifecycle->markRegistered(
                (string)$registration['instance_id'],
                (int)$lifecycleAttempt['attempt_sequence'],
                'register',
            )) {
                throw new \RuntimeException(
                    'Gateway registration completed after this WLS launch was retired.',
                );
            }
            return $published;
        } catch (\Throwable $throwable) {
            $recoveryFailures = [];
            try {
                $lifecycle->markUncertain(
                    (string)$registration['instance_id'],
                    (int)$lifecycleAttempt['attempt_sequence'],
                    'register',
                    $throwable->getMessage(),
                );
            } catch (\Throwable $lifecycleFailure) {
                $recoveryFailures[] = 'registration lifecycle: '
                    . GatewayBoundedText::singleLine(
                        $lifecycleFailure->getMessage(),
                        512,
                        'state write failed',
                    );
            }
            if ($intentId !== '') {
                try {
                    $renewals->recordFailure($intentId, $throwable->getMessage());
                } catch (\Throwable $renewalFailure) {
                    $recoveryFailures[] = 'certificate replay intent: '
                        . GatewayBoundedText::singleLine(
                            $renewalFailure->getMessage(),
                            512,
                            'state write failed',
                        );
                }
            }
            if ($recoveryFailures !== []) {
                throw new \RuntimeException(
                    GatewayBoundedText::singleLine(
                        $throwable->getMessage(),
                        1024,
                        'Gateway registration failed.',
                    ) . ' Local recovery state update also failed: '
                        . \implode('; ', $recoveryFailures),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    /**
     * A host restart deliberately restores persisted routes as STALE and
     * serves TLS/503 until each project replays its complete desired state.
     * Overall ready therefore cannot be a registration prerequisite: ACTIVE
     * routes are an output of registration. The authenticated release,
     * Broker and supervisor tree remains the fail-closed admission boundary.
     *
     * @param array<string,mixed> $status
     */
    public static function controlPlaneAcceptsRegistration(array $status): bool
    {
        $epoch = \strtolower(\trim((string)($status['epoch'] ?? '')));
        $hostBootId = \strtolower(\trim((string)($status['host_boot_id'] ?? '')));
        $publicHttp = (int)($status['public_http'] ?? 0);
        $publicHttps = (int)($status['public_https'] ?? 0);
        return ($status['ok'] ?? false) === true
            && ($status['control_plane_ready'] ?? false) === true
            && ($status['release_ready'] ?? false) === true
            && ($status['broker_ready'] ?? false) === true
            && ($status['supervisor_ready'] ?? false) === true
            && \hash_equals(
                GatewayPaths::PROTOCOL,
                (string)($status['protocol'] ?? ''),
            )
            && \hash_equals(
                GatewayPaths::IMPLEMENTATION_LEVEL,
                (string)($status['implementation_level'] ?? ''),
            )
            && \hash_equals(
                GatewayPaths::SECURITY_PROFILE,
                (string)($status['security_profile'] ?? ''),
            )
            && (int)($status['protocol_min'] ?? 0) <= 2
            && (int)($status['protocol_max'] ?? 0) >= 2
            && \preg_match('/\A[a-f0-9]{32}\z/D', $epoch) === 1
            && \preg_match('/\A[a-f0-9]{64}\z/D', $hostBootId) === 1
            && $publicHttp >= 1
            && $publicHttp <= 65535
            && $publicHttps >= 1
            && $publicHttps <= 65535
            && $publicHttp !== $publicHttps;
    }

    /** @param array<string,mixed> $status */
    private static function currentAuthenticatedHostBootId(array $status): string
    {
        $observed = \strtolower(\trim((string)($status['host_boot_id'] ?? '')));
        try {
            $current = GatewayHostBootIdentity::current();
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'WLS Gateway current host boot identity is unavailable.',
                0,
                $throwable,
            );
        }
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $observed) !== 1
            || !\hash_equals($current, $observed)
        ) {
            throw new \RuntimeException(
                'WLS Gateway authenticated status belongs to another host boot.'
            );
        }
        return $observed;
    }

    /**
     * @return array<string,mixed>
     */
    public function renew(
        string $instanceName,
        string $expectedGatewayEpoch = '',
        array $expectedRouteGenerations = [],
    ): array
    {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $status = $this->status(5.0);
        if (!self::controlPlaneAcceptsRegistration($status)) {
            throw new \RuntimeException(
                (string)($status['reason'] ?? 'Gateway status failed before certificate renew.')
            );
        }
        $submitted = [];
        foreach ((array)($registration['routes'] ?? []) as $route) {
            if (!\is_array($route)
                || \preg_match(
                    '/\A[a-f0-9]{32}\z/D',
                    (string)($route['route_id'] ?? ''),
                ) !== 1
            ) {
                throw new \RuntimeException('Certificate renew contains an invalid route ID.');
            }
            $submitted[(string)$route['route_id']] = true;
        }
        $expected = [];
        foreach ((array)($status['desired_routes'] ?? []) as $route) {
            if (!\is_array($route)
                || (string)($route['project_uuid'] ?? '') !== (string)$registration['project_uuid']
            ) {
                continue;
            }
            if (\hash_equals('REMOVED', (string)($route['status'] ?? ''))) {
                continue;
            }
            $routeId = (string)($route['route_id'] ?? '');
            if (!isset($submitted[$routeId])) {
                throw new \RuntimeException(
                    'Certificate renew local desired routes do not match authenticated own-status.'
                );
            }
            if (isset($expected[$routeId])) {
                throw new \RuntimeException('Gateway own-status returned a duplicate route ID.');
            }
            $routeGeneration = (int)($route['route_generation'] ?? 0);
            if ($routeGeneration < 1) {
                throw new \RuntimeException('Gateway own-status returned an invalid route generation.');
            }
            $expected[$routeId] = $routeGeneration;
        }
        if (\count($expected) !== \count($submitted)) {
            throw new \RuntimeException(
                'Certificate renew requires the exact registered route set; run register first.'
            );
        }
        $renewals = new ProjectCertificateRenewalIntentStore();
        $pending = $renewals->pendingReplay();
        if (\is_array($pending)) {
            $plan = $renewals->replayPlan($pending, $status);
            if (!\hash_equals('renew', (string)$plan['action'])) {
                throw new \RuntimeException(
                    'REGISTER_REPLAY_REQUIRED: certificate route set or gateway epoch requires full registration.'
                );
            }
            if ($expectedGatewayEpoch !== ''
                && (!\hash_equals(
                    \strtolower(\trim($expectedGatewayEpoch)),
                    (string)$plan['gateway_epoch'],
                )
                    || $expectedRouteGenerations !== $plan['expected_route_generations'])
            ) {
                throw new \RuntimeException(
                    'Certificate renew expected route-generation fence changed before submission.'
                );
            }
            if ($expected !== $plan['expected_route_generations']) {
                throw new \RuntimeException(
                    'Certificate renew authenticated route generations changed before submission.'
                );
            }
        }
        $registration['gateway_epoch'] = (string)($status['epoch'] ?? '');
        $registration['host_boot_id'] = self::currentAuthenticatedHostBootId($status);
        $registration['expected_route_generations'] = $expected;
        $intentId = $renewals->recordAttempt(
            $registration,
            $instanceName,
            [
                'action' => 'renew',
                'gateway_epoch' => (string)$registration['gateway_epoch'],
                'expected_route_generations' => $expected,
            ],
        );
        $attempted = $intentId !== null;
        $intentId ??= '';
        $lifecycle = new GatewayRegistrationLifecycle();
        $lifecycleAttempt = $lifecycle->beginMutation($instanceName, 'renew');
        try {
            $response = $this->idempotentProjectMutation('renew', $registration);
            if (!($response['ok'] ?? false)) {
                throw new \RuntimeException(
                    (string)($response['error']['message'] ?? 'Gateway certificate renew failed.')
                );
            }
            $published = $this->awaitPublication(
                $response,
                false,
                (string)$registration['project_uuid'],
            );
            $this->persistLeaseReceipt(
                $instanceName,
                self::leaseReceiptFromPublication($published),
            );
            if ($attempted) {
                $renewals->acknowledge(
                    $registration,
                    $instanceName,
                    'renew',
                    $this->status(5.0),
                );
            }
            if (!$lifecycle->markRegistered(
                $instanceName,
                (int)$lifecycleAttempt['attempt_sequence'],
                'renew',
            )) {
                throw new \RuntimeException(
                    'Gateway renew completed after this WLS launch was retired.',
                );
            }
            return $published;
        } catch (\Throwable $throwable) {
            $recoveryFailures = [];
            try {
                $lifecycle->markUncertain(
                    $instanceName,
                    (int)$lifecycleAttempt['attempt_sequence'],
                    'renew',
                    $throwable->getMessage(),
                );
            } catch (\Throwable $lifecycleFailure) {
                $recoveryFailures[] = 'registration lifecycle: '
                    . GatewayBoundedText::singleLine(
                        $lifecycleFailure->getMessage(),
                        512,
                        'state write failed',
                    );
            }
            if ($intentId !== '') {
                try {
                    $renewals->recordFailure($intentId, $throwable->getMessage());
                } catch (\Throwable $renewalFailure) {
                    $recoveryFailures[] = 'certificate replay intent: '
                        . GatewayBoundedText::singleLine(
                            $renewalFailure->getMessage(),
                            512,
                            'state write failed',
                        );
                }
            }
            if ($recoveryFailures !== []) {
                throw new \RuntimeException(
                    GatewayBoundedText::singleLine(
                        $throwable->getMessage(),
                        1024,
                        'Gateway certificate renewal failed.',
                    ) . ' Local recovery state update also failed: '
                        . \implode('; ', $recoveryFailures),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function heartbeat(string $instanceName, array $drainCounters = []): array
    {
        $registration = $this->loadLeaseReceipt($instanceName);
        $payload = [
            'project_uuid' => (string)$registration['project_uuid'],
            'gateway_epoch' => (string)$registration['gateway_epoch'],
            'host_boot_id' => (string)$registration['host_boot_id'],
            'project_generation' => (int)$registration['project_generation'],
            'instance_id' => (string)$registration['instance_id'],
            'instance_generation' => (int)$registration['instance_generation'],
            'instance_digest' => (string)$registration['instance_digest'],
            'master_epoch' => (int)$registration['master_epoch'],
            'launch_id' => (string)$registration['launch_id'],
        ];
        if ($drainCounters !== []) {
            $payload['drain_counters'] = $drainCounters;
        }
        $response = $this->client->projectRequest('heartbeat', $payload);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway heartbeat failed.')
            );
        }
        $result = (array)($response['payload'] ?? []);
        if (\is_array($result['lease_receipt'] ?? null)) {
            // Heartbeat receipts are short-lived replay authority. Persist the
            // freshly signed Controller fence before exposing success so Stop,
            // ACME and the next heartbeat never rely on an expired registration
            // receipt.
            $this->persistLeaseReceipt($instanceName, $result['lease_receipt']);
        }
        return $result;
    }

    /**
     * @param list<array{domain:string,token:string,key_authorization:string,expires_at:int}> $challenges
     * @return array<string,mixed>
     */
    public function syncAcmeChallenges(
        string $projectUuid,
        int $challengeGeneration,
        array $challenges,
        string $desiredDigest,
    ): array {
        $computedDigest = \hash('sha256', GatewayClient::canonicalJson($challenges));
        if ($challengeGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $desiredDigest) !== 1
            || !\hash_equals($computedDigest, $desiredDigest)
        ) {
            throw new \InvalidArgumentException(
                'Gateway ACME desired generation or digest is invalid.'
            );
        }
        $response = $this->client->projectRequest('acme-challenge-sync', [
            'project_uuid' => $projectUuid,
            'challenge_generation' => $challengeGeneration,
            'desired_digest' => $desiredDigest,
            'challenges' => $challenges,
        ]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway ACME challenge sync failed.')
            );
        }
        return $this->awaitPublication($response, false, $projectUuid);
    }

    /**
     * @return array<string,mixed>
     */
    public function drain(
        string $instanceName,
        int $seconds = 300,
        bool $waitForConnections = false,
    ): array
    {
        $registration = $this->loadLeaseReceipt($instanceName);
        $drainOperationId = self::drainOperationId($registration, $instanceName);
        $response = $this->idempotentProjectMutation(
            'drain',
            [
                'project_uuid' => (string)$registration['project_uuid'],
                'gateway_epoch' => (string)$registration['gateway_epoch'],
                'host_boot_id' => (string)$registration['host_boot_id'],
                'instance_id' => $instanceName,
                'instance_generation' => (int)$registration['instance_generation'],
                'master_epoch' => (int)$registration['master_epoch'],
                'launch_id' => (string)$registration['launch_id'],
                'drain_operation_id' => $drainOperationId,
                'seconds' => \max(1, \min(300, $seconds)),
            ],
            (float)\min(90, \max(1, $seconds)),
        );
        if (!($response['ok'] ?? false)) {
            if (\str_contains(
                \strtolower((string)($response['error']['message'] ?? '')),
                'no registered route',
            )) {
                return ['accepted' => true, 'idempotent' => true, 'already_removed' => true];
            }
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway drain failed.')
            );
        }
        $payload = $this->awaitPublication(
            $response,
            false,
            (string)$registration['project_uuid'],
        );
        $operation = \is_array($payload['operation'] ?? null)
            ? $payload['operation']
            : [];
        $operationResult = \is_array($operation['result'] ?? null)
            ? $operation['result']
            : [];
        $terminalDrain = ($payload['already_removed'] ?? false) === true
            ? $payload
            : ((($operationResult['already_removed'] ?? false) === true)
                ? $operationResult
                : []);
        if ($terminalDrain !== []) {
            $terminalOperationId = \strtolower(\trim((string)(
                $terminalDrain['drain_operation_id'] ?? ''
            )));
            $terminalGeneration = $terminalDrain['instance_generation'] ?? null;
            if (($terminalOperationId !== ''
                    && !\hash_equals($drainOperationId, $terminalOperationId))
                || ($terminalGeneration !== null
                    && (!\is_int($terminalGeneration)
                        || $terminalGeneration
                            !== (int)$registration['instance_generation']))
            ) {
                throw new \RuntimeException(
                    'Gateway committed drain terminal result violates its launch fence.',
                );
            }
            return $terminalDrain + $payload;
        }
        // A drain may spend longer than the ordinary 45 second registration
        // lease in mutation throttling and publication. The committed drain
        // result therefore carries a fresh, launch-bound lifecycle receipt;
        // persist it before either returning or entering the 300 second wait.
        $this->persistLeaseReceipt(
            $instanceName,
            self::leaseReceiptFromPublication($payload),
        );
        if (!$waitForConnections) {
            return $payload;
        }
        return $this->awaitInstanceDrain(
            $instanceName,
            (string)$registration['project_uuid'],
            $payload,
            \max(1, \min(300, $seconds)),
        );
    }

    /**
     * A drain is irreversible for one exact instance launch. Transport retries
     * and later Stop invocations therefore share one operation identity; the
     * Controller persists the first deadline and must never extend it.
     *
     * @param array<string,mixed> $receipt
     */
    public static function drainOperationId(array $receipt, string $instanceName): string
    {
        $facts = [
            'operation' => 'drain',
            'project_uuid' => \strtolower(\trim((string)(
                $receipt['project_uuid'] ?? ''
            ))),
            'instance_id' => \trim($instanceName),
            'instance_generation' => (int)($receipt['instance_generation'] ?? 0),
            'master_epoch' => (int)($receipt['master_epoch'] ?? 0),
            'launch_id' => \strtolower(\trim((string)($receipt['launch_id'] ?? ''))),
        ];
        if (\preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $facts['project_uuid'],
        ) !== 1
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $facts['instance_id']) !== 1
            || $facts['instance_generation'] < 1
            || $facts['master_epoch'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $facts['launch_id']) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway drain operation identity is incomplete.',
            );
        }

        return \hash('sha256', GatewayClient::canonicalJson($facts));
    }

    /**
     * @return array<string,mixed>
     */
    public function unregister(string $instanceName): array
    {
        $registration = $this->loadLeaseReceipt($instanceName);
        $response = $this->idempotentProjectMutation('unregister', [
            'project_uuid' => (string)$registration['project_uuid'],
            'gateway_epoch' => (string)$registration['gateway_epoch'],
            'host_boot_id' => (string)$registration['host_boot_id'],
            'instance_id' => $instanceName,
            'instance_generation' => (int)$registration['instance_generation'],
            'master_epoch' => (int)$registration['master_epoch'],
            'launch_id' => (string)$registration['launch_id'],
        ]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway unregister failed.')
            );
        }
        return $this->awaitPublication(
            $response,
            false,
            (string)$registration['project_uuid'],
        );
    }

    /**
     * Atomically transfer one domain to the current project/instance.
     *
     * The public command remains one administrator-confirmed action while the
     * protocol uses a short-lived prepare → project proof → commit ticket so
     * the target private key and backend capability stay on the target
     * project's authenticated channel.
     *
     * @return array<string,mixed>
     */
    public function transferDomain(
        string $instanceName,
        string $domain,
        string $fromProjectUuid,
        string $toProjectUuid,
    ): array {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $currentProject = \strtolower((string)$registration['project_uuid']);
        $toProjectUuid = \strtolower(\trim($toProjectUuid));
        if (!\hash_equals($currentProject, $toProjectUuid)) {
            throw new \InvalidArgumentException(
                'The transfer target must be the current project identity.'
            );
        }
        $status = $this->administratorStatus();
        if (!($status['ok'] ?? false) || !($status['ready'] ?? false)) {
            throw new \RuntimeException(
                (string)($status['reason'] ?? 'WLS Gateway is not ready for domain transfer.')
            );
        }
        $registration['gateway_epoch'] = (string)($status['epoch'] ?? '');
        $prepared = $this->client->request('transfer', [
            'phase' => 'prepare',
            'domain' => $domain,
            'from_project_uuid' => \strtolower(\trim($fromProjectUuid)),
            'to_project_uuid' => $toProjectUuid,
            'confirm' => true,
        ]);
        if (!($prepared['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($prepared['error']['message'] ?? 'Domain transfer preparation failed.')
            );
        }
        $transferId = \strtolower(\trim((string)(
            $prepared['payload']['transfer_id'] ?? ''
        )));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $transferId) !== 1) {
            throw new \RuntimeException(
                'Gateway did not return a valid domain transfer ticket.'
            );
        }
        try {
            $staged = $this->client->projectRequest('transfer-stage', [
                'project_uuid' => $toProjectUuid,
                'transfer_id' => $transferId,
                'registration' => $registration,
            ]);
            if (!($staged['ok'] ?? false)) {
                throw new \RuntimeException(
                    (string)($staged['error']['message'] ?? 'Domain transfer target proof failed.')
                );
            }
            $this->awaitPublication($staged, false, $toProjectUuid);
            $committed = $this->client->request('transfer', [
                'phase' => 'commit',
                'transfer_id' => $transferId,
                'confirm' => true,
            ]);
            if (!($committed['ok'] ?? false)) {
                throw new \RuntimeException(
                    (string)($committed['error']['message'] ?? 'Domain transfer commit failed.')
                );
            }
            return $this->awaitPublication($committed, true);
        } catch (\Throwable $throwable) {
            $abortFailure = null;
            try {
                $aborted = $this->client->request('transfer', [
                    'phase' => 'abort',
                    'transfer_id' => $transferId,
                ]);
                if (($aborted['ok'] ?? false) !== true) {
                    throw new \RuntimeException((string)(
                        $aborted['error']['message']
                        ?? 'Gateway rejected the transfer abort.'
                    ));
                }
            } catch (\Throwable $abort) {
                $abortFailure = $abort;
            }
            if ($abortFailure instanceof \Throwable) {
                throw new \RuntimeException(
                    GatewayBoundedText::singleLine(
                        $throwable->getMessage(),
                        2048,
                        'Gateway domain transfer failed.',
                    ) . ' Transfer abort also failed: '
                        . GatewayBoundedText::singleLine(
                            $abortFailure->getMessage(),
                            512,
                            'abort failed',
                        ),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    /**
     * Persistently stop the shared host service after the Controller has
     * durably signed ADMIN_STOPPED.
     *
     * @return array<string,mixed>
     */
    public function stopGateway(bool $force = false): array
    {
        $service = $this->platform->installedDefinition();
        $response = $this->client->request('stop', [
            'force' => $force,
            'confirm' => true,
        ]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway stop failed.')
            );
        }
        $this->platform->stop((string)$service['kind']);
        return (array)($response['payload'] ?? []) + [
            'platform_service' => (string)$service['kind'],
            'persistent' => true,
        ];
    }

    /**
     * Clear a verified ADMIN_STOPPED intent and explicitly re-enable the host
     * platform service.
     *
     * @return array<string,mixed>
     */
    public function startGateway(): array
    {
        GatewayProjectStateFilesystem::assertAtomicWriteRuntimeCapability();
        $service = $this->platform->installedDefinition();
        $intent = $this->clearAdminStoppedIntent();
        try {
            $this->platform->start((string)$service['kind']);
            if ($this->paths->isTestMode()) {
                return [
                    'accepted' => true,
                    'ready' => false,
                    'state' => 'TEST_SERVICE_STARTED',
                    'platform_service' => (string)$service['kind'],
                ];
            }
            // Cold recovery may need to validate LKG/state, start Nginx and
            // complete the mandatory 15-second continuous probe window. The
            // previous 15-second command deadline raced that safety gate and
            // stopped an otherwise healthy service just before it became ready.
            $deadline = self::monotonicNow()
                + self::EXPLICIT_START_READY_TIMEOUT_SECONDS;
            do {
                \usleep(100000);
                $status = $this->administratorStatus();
                if (($status['ok'] ?? false) && ($status['ready'] ?? false)) {
                    return $status + [
                        'accepted' => true,
                        'platform_service' => (string)$service['kind'],
                    ];
                }
            } while (self::monotonicNow() < $deadline);
            throw new \RuntimeException(
                (string)($status['reason']
                    ?? 'Gateway did not become ready after explicit administrator start.')
            );
        } catch (\Throwable $throwable) {
            $stopFailure = null;
            try {
                $this->platform->stop((string)$service['kind']);
            } catch (\Throwable $stop) {
                $stopFailure = $stop;
            }
            if ($intent !== null) {
                $this->restoreAdminStoppedIntent($intent);
            }
            if ($stopFailure instanceof \Throwable) {
                throw new \RuntimeException(
                    GatewayBoundedText::singleLine(
                        $throwable->getMessage(),
                        2048,
                        'Gateway explicit start failed.',
                    ) . ' Platform stop also failed: '
                        . GatewayBoundedText::singleLine(
                            $stopFailure->getMessage(),
                            512,
                            'stop failed',
                        ),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    private function clearAdminStoppedIntent(): ?string
    {
        $file = $this->paths->adminStoppedIntentFile();
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        $contents = $this->readStableRegularFile(
            $file,
            4096,
            'ADMIN_STOPPED intent',
        );
        $secret = \strtolower(\trim($this->readStableRegularFile(
            $this->paths->adminTokenFile(),
            65,
            'Gateway administrator credential',
        )));
        $key = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? \hex2bin($secret)
            : false;
        if (!\is_string($key)
            || \strlen($key) !== 32
            || \preg_match(
                '/\A(WLS-ADMIN-STOPPED\\/1\\n'
                    . 'host_id=[a-f0-9]{32}\\n'
                    . 'epoch=[a-f0-9]{32}\\n'
                    . 'at=[0-9]+\\n'
                    . 'nonce=[a-f0-9]{32}\\n)'
                    . 'signature=([a-f0-9]{64})\\n\z/D',
                $contents,
                $matches,
            ) !== 1
            || !\hash_equals(
                (string)$matches[2],
                \hash_hmac('sha256', (string)$matches[1], $key),
            )
        ) {
            if (\is_string($key)) {
                \sodium_memzero($key);
            }
            throw new \RuntimeException(
                'ADMIN_STOPPED signature is invalid; refusing to re-enable the service.'
            );
        }
        \sodium_memzero($key);
        GatewayProjectStateFilesystem::removeRegular(
            $file,
            'verified ADMIN_STOPPED intent',
        );
        return $contents;
    }

    private function restoreAdminStoppedIntent(string $contents): void
    {
        $file = $this->paths->adminStoppedIntentFile();
        GatewayProjectStateFilesystem::atomicWrite($file, $contents, 0600);
        $published = @\lstat($file);
        if (!\is_array($published)
            || ((((int)($published['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($published['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)($published['mode'] ?? 0)) & 0777) !== 0600)
        ) {
            throw new \RuntimeException('Restored ADMIN_STOPPED intent is unsafe.');
        }
        $this->syncDirectory(\dirname($file));
    }

    private function readStableRegularFile(
        string $path,
        int $maximumBytes,
        string $label,
    ): string {
        $before = @\lstat($path);
        if (!\is_array($before)
            || \is_link($path)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)($before['size'] ?? -1) > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' is missing, linked, or special.');
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_array($opened)
                || !\is_string($contents)
                || \strlen($contents) > $maximumBytes
                || !\is_array($after)
                || !\is_array($pathAfter)
                || !$this->sameFileState($before, $opened)
                || !$this->sameFileState($opened, $after)
                || !$this->sameFileState($after, $pathAfter)
            ) {
                throw new \RuntimeException($label . ' changed while being read.');
            }
            return $contents;
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameFileState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (!\array_key_exists($key, $before)
                || !\array_key_exists($key, $after)
                || (int)$before[$key] !== (int)$after[$key]
            ) {
                return false;
            }
        }
        return true;
    }

    private function syncDirectory(string $directory): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('fsync')) {
            return;
        }
        $handle = @\fopen($directory, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open gateway trust directory for sync.');
        }
        try {
            if (!@\fsync($handle)) {
                throw new \RuntimeException('Unable to durably update gateway trust state.');
            }
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function request(string $operation, array $payload = []): array
    {
        return $this->client->request($operation, $payload);
    }

    /**
     * Read-only project lifecycle proof for consumers such as ACME publication.
     * The returned receipt has already passed credential signature validation
     * and the exact current endpoint launch/generation fence.
     *
     * @return array<string,mixed>
     */
    public function validatedLeaseReceiptForInstance(string $instanceName): array
    {
        self::assertLeaseReceiptInstanceName($instanceName);
        $receipt = $this->loadLeaseReceipt($instanceName);
        $status = $this->status(1.0);
        self::assertLeaseReceiptMatchesAuthenticatedStatus($receipt, $status);
        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    private function persistLeaseReceipt(
        string $instanceName,
        array $receipt,
    ): void {
        self::assertLeaseReceiptInstanceName($instanceName);
        $credential = (new GatewayCredentialStore())->load(
            (string)($receipt['project_uuid'] ?? ''),
        );
        self::assertLeaseReceipt($receipt, $instanceName, $credential);
        $instances = new ServerInstanceManager();
        $file = $instances->getInstanceFile($instanceName);
        $updated = ServerInstanceManager::updateJsonFileAtomically(
            $file,
            static function (array $endpoint) use ($instanceName, $receipt): array {
                self::assertEndpointMatchesLeaseReceipt(
                    $endpoint,
                    $instanceName,
                    $receipt,
                );
                $gateway = \is_array($endpoint['gateway'] ?? null)
                    ? $endpoint['gateway']
                    : [];
                $existingReceipt = \is_array($gateway['lease_receipt'] ?? null)
                    ? $gateway['lease_receipt']
                    : [];
                self::assertLeaseReceiptMayReplace($existingReceipt, $receipt);
                $gateway['lease_receipt'] = $receipt;
                unset($gateway['lease_envelope']);
                $endpoint['gateway'] = $gateway;
                return $endpoint;
            },
        );
        if (!$updated) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: committed gateway lease receipt could not be persisted.'
            );
        }
    }

    /**
     * Fence inverse completion of concurrent register/heartbeat operations.
     *
     * The endpoint file lock serializes writes, but it cannot order Controller
     * responses that were created at different active generations. For the
     * same host epoch and Master launch, a delayed response may refresh a
     * receipt only when it is at least as new and cannot reinterpret an
     * already-observed active generation.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $incoming
     */
    private static function assertLeaseReceiptMayReplace(
        array $existing,
        array $incoming,
    ): void {
        if ($existing === []) {
            return;
        }
        foreach (['project_uuid', 'gateway_epoch', 'instance_id', 'launch_id'] as $field) {
            $left = (string)($existing[$field] ?? '');
            $right = (string)($incoming[$field] ?? '');
            if ($left === '' || $right === '' || !\hash_equals($left, $right)) {
                // A new endpoint launch is already fenced by
                // assertEndpointMatchesLeaseReceipt(); its first receipt may
                // replace the previous launch's cached proof.
                return;
            }
        }

        $existingConfigGeneration = $existing['active_config_generation'] ?? null;
        $incomingConfigGeneration = $incoming['active_config_generation'] ?? null;
        $existingProjectGeneration = $existing['project_generation'] ?? null;
        $incomingProjectGeneration = $incoming['project_generation'] ?? null;
        $existingBootId = \strtolower(\trim((string)(
            $existing['host_boot_id'] ?? ''
        )));
        $incomingBootId = \strtolower(\trim((string)(
            $incoming['host_boot_id'] ?? ''
        )));
        $existingSequence = $existing['lease_sequence'] ?? null;
        $incomingSequence = $incoming['lease_sequence'] ?? null;
        $existingIssuedMonotonic = $existing['issued_monotonic'] ?? null;
        $incomingIssuedMonotonic = $incoming['issued_monotonic'] ?? null;
        $existingTtl = $existing['lease_ttl_seconds'] ?? null;
        $incomingTtl = $incoming['lease_ttl_seconds'] ?? null;
        if (!\is_int($existingConfigGeneration)
            || !\is_int($incomingConfigGeneration)
            || !\is_int($existingProjectGeneration)
            || !\is_int($incomingProjectGeneration)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $existingBootId) !== 1
            || !\hash_equals($existingBootId, $incomingBootId)
            || !\is_int($existingSequence)
            || !\is_int($incomingSequence)
            || $existingSequence < 1
            || $incomingSequence < $existingSequence
            || !(\is_int($existingIssuedMonotonic)
                || \is_float($existingIssuedMonotonic))
            || !(\is_int($incomingIssuedMonotonic)
                || \is_float($incomingIssuedMonotonic))
            || !\is_finite((float)$existingIssuedMonotonic)
            || !\is_finite((float)$incomingIssuedMonotonic)
            || (float)$existingIssuedMonotonic < 0.0
            || (float)$incomingIssuedMonotonic < (float)$existingIssuedMonotonic
            || !\is_int($existingTtl)
            || !\is_int($incomingTtl)
            || $existingConfigGeneration < 1
            || $incomingConfigGeneration < $existingConfigGeneration
            || $existingProjectGeneration < 1
            || $incomingProjectGeneration < $existingProjectGeneration
            || $existingTtl < 1
            || $incomingTtl < 1
            || (float)$incomingIssuedMonotonic + $incomingTtl
                < (float)$existingIssuedMonotonic + $existingTtl
            || (int)($incoming['instance_generation'] ?? 0)
                !== (int)($existing['instance_generation'] ?? -1)
            || (int)($incoming['master_epoch'] ?? 0)
                !== (int)($existing['master_epoch'] ?? -1)
            || !\hash_equals(
                (string)($existing['instance_digest'] ?? ''),
                (string)($incoming['instance_digest'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: stale gateway lease receipt completion was rejected.'
            );
        }

        if ($incomingSequence === $existingSequence) {
            try {
                if (!\hash_equals(
                    GatewayClient::canonicalJson($existing),
                    GatewayClient::canonicalJson($incoming),
                )) {
                    throw new \RuntimeException(
                        'REGISTER_REPLAY_REQUIRED: one lease sequence returned conflicting signed facts.'
                    );
                }
            } catch (\Throwable $throwable) {
                if ($throwable instanceof \RuntimeException
                    && \str_starts_with(
                        $throwable->getMessage(),
                        'REGISTER_REPLAY_REQUIRED:',
                    )
                ) {
                    throw $throwable;
                }
                throw new \RuntimeException(
                    'REGISTER_REPLAY_REQUIRED: cached gateway lease receipt is invalid.',
                    0,
                    $throwable,
                );
            }
            return;
        }

        if ($incomingConfigGeneration !== $existingConfigGeneration) {
            return;
        }
        try {
            $sameRouteClosure = \hash_equals(
                GatewayClient::canonicalJson((array)($existing['route_generations'] ?? [])),
                GatewayClient::canonicalJson((array)($incoming['route_generations'] ?? [])),
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: cached gateway lease route closure is invalid.',
                0,
                $throwable,
            );
        }
        foreach ([
            'active_config_digest',
            'request_digest',
            'idempotency_key',
            'routes_digest',
        ] as $field) {
            if (!\hash_equals(
                (string)($existing[$field] ?? ''),
                (string)($incoming[$field] ?? ''),
            )) {
                $sameRouteClosure = false;
                break;
            }
        }
        if (!$sameRouteClosure
            || $incomingProjectGeneration !== $existingProjectGeneration
        ) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: one active config generation returned conflicting lease facts.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function loadLeaseReceipt(string $instanceName): array
    {
        self::assertLeaseReceiptInstanceName($instanceName);
        $endpoint = (new GatewayProjectEndpointReader())->read($instanceName);
        if (!\is_array($endpoint)) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: WLS instance endpoint is missing.'
            );
        }
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $receipt = \is_array($gateway['lease_receipt'] ?? null)
            ? $gateway['lease_receipt']
            : [];
        if ($receipt === []) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: signed gateway lease receipt is missing.'
            );
        }
        try {
            $credential = (new GatewayCredentialStore())->load(
                (string)($receipt['project_uuid'] ?? ''),
            );
            self::assertLeaseReceipt($receipt, $instanceName, $credential);
            self::assertEndpointMatchesLeaseReceipt($endpoint, $instanceName, $receipt);
        } catch (\Throwable $throwable) {
            if (\str_starts_with($throwable->getMessage(), 'REGISTER_REPLAY_REQUIRED:')) {
                throw $throwable;
            }
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: signed gateway lease receipt is invalid.',
                0,
                $throwable,
            );
        }
        return $receipt;
    }

    private static function assertLeaseReceiptInstanceName(string $instanceName): void
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException(
                'Gateway lease receipt instance name is invalid.'
            );
        }
    }

    /**
     * @param array<string,mixed> $published
     * @return array<string,mixed>
     */
    private static function leaseReceiptFromPublication(array $published): array
    {
        $operation = \is_array($published['operation'] ?? null)
            ? $published['operation']
            : [];
        $result = \is_array($operation['result'] ?? null)
            ? $operation['result']
            : [];
        foreach ([
            $published['lease_receipt'] ?? null,
            $operation['lease_receipt'] ?? null,
            $result['lease_receipt'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }
        throw new \RuntimeException(
            'REGISTER_REPLAY_REQUIRED: committed registration returned no signed lease receipt.'
        );
    }

    /**
     * A candidate/new-connection probe is not revocation convergence: an old
     * Nginx worker can retain the previous SSL_CTX on an existing H2,
     * WebSocket, SSE, or keepalive session. Every disabled route therefore
     * requires a host receipt proving the old attested master generation exited
     * before this exact active config generation was acknowledged.
     *
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $published
     * @param array<string,mixed> $leaseReceipt
     */
    private static function assertRegistrationSecurityRetirement(
        array $registration,
        array $published,
        array $leaseReceipt,
    ): void {
        $required = [];
        $projectUuid = (string)($registration['project_uuid'] ?? '');
        foreach ((array)($registration['routes'] ?? []) as $route) {
            if (!\is_array($route)) {
                continue;
            }
            $certificate = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            if (!\hash_equals(
                'disabled',
                \strtolower(\trim((string)($certificate['state'] ?? ''))),
            )) {
                continue;
            }
            $domain = \strtolower(\rtrim(\trim((string)($route['domain'] ?? '')), '.'));
            $generation = $certificate['generation'] ?? null;
            $sourceDigest = \strtolower(\trim((string)(
                $certificate['source_digest'] ?? ''
            )));
            if (!\is_int($generation)
                || $generation < 1
                || !\hash_equals(
                    \hash(
                        'sha256',
                        "wls-disabled-certificate\0" . $domain . "\0" . $generation,
                    ),
                    $sourceDigest,
                )
            ) {
                throw new \RuntimeException(
                    'Gateway revocation registration has no exact project tombstone.',
                );
            }
            $required[$projectUuid . ':' . $domain] = [
                'domain' => $domain,
                'generation' => $generation,
                'source_digest' => $sourceDigest,
            ];
        }
        if ($required === []) {
            return;
        }
        $operation = \is_array($published['operation'] ?? null)
            ? $published['operation']
            : [];
        $operationResult = \is_array($operation['result'] ?? null)
            ? $operation['result']
            : [];
        $receipts = $operationResult['security_retirements']
            ?? $published['security_retirements']
            ?? null;
        if (!\is_array($receipts) || !\array_is_list($receipts)) {
            throw new \RuntimeException(
                'Shared gateway returned no old-generation TLS retirement receipt.',
            );
        }
        $observed = [];
        foreach ($receipts as $receipt) {
            if (!\is_array($receipt)) {
                throw new \RuntimeException(
                    'Shared gateway TLS retirement receipt is malformed.',
                );
            }
            $receiptProject = (string)($receipt['project_uuid'] ?? '');
            $domain = (string)($receipt['domain'] ?? '');
            $key = $receiptProject . ':' . $domain;
            $expected = $required[$key] ?? null;
            $retirementId = \strtolower(\trim((string)(
                $receipt['retirement_id'] ?? ''
            )));
            if (!\is_array($expected)
                || isset($observed[$key])
                || ($receipt['schema_version'] ?? null) !== 1
                || !\hash_equals($projectUuid, $receiptProject)
                || !\hash_equals((string)$expected['domain'], $domain)
                || !\is_int($receipt['generation'] ?? null)
                || (int)$receipt['generation'] !== (int)$expected['generation']
                || !\hash_equals(
                    (string)$expected['source_digest'],
                    (string)($receipt['source_digest'] ?? ''),
                )
                || !\hash_equals(
                    (string)($leaseReceipt['gateway_epoch'] ?? ''),
                    (string)($receipt['gateway_epoch'] ?? ''),
                )
                || !\is_int($receipt['active_config_generation'] ?? null)
                || (int)$receipt['active_config_generation']
                    !== (int)($leaseReceipt['active_config_generation'] ?? -1)
                || !\hash_equals(
                    (string)($leaseReceipt['active_config_digest'] ?? ''),
                    (string)($receipt['active_config_digest'] ?? ''),
                )
                || !\is_int($receipt['old_master_pid'] ?? null)
                || (int)$receipt['old_master_pid'] < 1
                || \trim((string)($receipt['old_master_start_id'] ?? '')) === ''
                || !\is_int($receipt['new_master_pid'] ?? null)
                || (int)$receipt['new_master_pid'] < 1
                || \trim((string)($receipt['new_master_start_id'] ?? '')) === ''
                || ((int)$receipt['old_master_pid'] === (int)$receipt['new_master_pid']
                    && \hash_equals(
                        (string)$receipt['old_master_start_id'],
                        (string)$receipt['new_master_start_id'],
                    ))
                || ($receipt['old_generation_retired'] ?? false) !== true
                || \preg_match('/\A[a-f0-9]{64}\z/D', $retirementId) !== 1
                || !\is_string($receipt['issued_at'] ?? null)
                || \strlen((string)$receipt['issued_at']) > 128
                || \strtotime((string)$receipt['issued_at']) === false
            ) {
                throw new \RuntimeException(
                    'Shared gateway TLS retirement receipt does not match the exact tombstone.',
                );
            }
            $signedFields = $receipt;
            unset($signedFields['retirement_id']);
            if (!\hash_equals(
                \hash('sha256', GatewayClient::canonicalJson($signedFields)),
                $retirementId,
            )) {
                throw new \RuntimeException(
                    'Shared gateway TLS retirement receipt digest is invalid.',
                );
            }
            $observed[$key] = true;
        }
        if (\array_keys($required) !== \array_keys($observed)) {
            \ksort($required, SORT_STRING);
            \ksort($observed, SORT_STRING);
            if (\array_keys($required) !== \array_keys($observed)) {
                throw new \RuntimeException(
                    'Shared gateway TLS retirement receipt set is incomplete.',
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $credential
     */
    private static function assertLeaseReceipt(
        array $receipt,
        string $instanceName,
        array $credential,
    ): void {
        self::assertLeaseReceiptContract($receipt);
        $projectUuid = (string)($receipt['project_uuid'] ?? '');
        $generation = (int)($receipt['project_generation'] ?? 0);
        $gatewayEpoch = (string)($receipt['gateway_epoch'] ?? '');
        $requestDigest = (string)($receipt['request_digest'] ?? '');
        $idempotencyKey = (string)($receipt['idempotency_key'] ?? '');
        $activeConfigGeneration = $receipt['active_config_generation'] ?? null;
        $activeConfigDigest = \strtolower(\trim((string)(
            $receipt['active_config_digest'] ?? ''
        )));
        $hostBootId = \strtolower(\trim((string)(
            $receipt['host_boot_id'] ?? ''
        )));
        $issuedMonotonic = $receipt['issued_monotonic'] ?? null;
        $leaseSequence = $receipt['lease_sequence'] ?? null;
        $expectedIdempotencyKey = \substr(\hash(
            'sha256',
            $projectUuid . ':desired:' . $generation . ':' . $requestDigest,
        ), 0, 40);
        $routeGenerations = \is_array($receipt['route_generations'] ?? null)
            ? $receipt['route_generations']
            : [];
        $routeKeys = \array_keys($routeGenerations);
        $sortedRouteKeys = $routeKeys;
        \sort($sortedRouteKeys, SORT_STRING);
        $routesValid = $routeGenerations !== [] && \count($routeGenerations) <= 256;
        foreach ($routeGenerations as $routeId => $routeGeneration) {
            if (!\is_string($routeId)
                || \preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || !\is_int($routeGeneration)
                || $routeGeneration < 1
            ) {
                $routesValid = false;
                break;
            }
        }
        $routesDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson($routeGenerations),
        );
        $signature = \strtolower(\trim((string)($receipt['signature'] ?? '')));
        $signed = $receipt;
        unset($signed['signature']);
        $secret = \strtolower(\trim((string)($credential['secret'] ?? '')));
        $expectedSignature = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? \hash_hmac('sha256', GatewayClient::canonicalJson($signed), $secret)
            : '';
        // issued_at is signed diagnostic text only. Lease freshness is bound
        // to the current host boot and monotonic clock so an NTP wall-clock
        // correction cannot invalidate (or extend) lifecycle authority.
        $issuedTimestamp = \strtotime((string)($receipt['issued_at'] ?? ''));
        $monotonicNow = self::monotonicNow();
        $currentHostBootId = GatewayHostBootIdentity::current();
        if (!\hash_equals(
                (string)($credential['host_id'] ?? ''),
                (string)($receipt['host_id'] ?? ''),
            )
            || !\hash_equals(
                (string)($credential['project_uuid'] ?? ''),
                $projectUuid,
            )
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $projectUuid,
            ) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $gatewayEpoch) !== 1
            || !\hash_equals($instanceName, (string)($receipt['instance_id'] ?? ''))
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1
            || $generation < 1
            || !\is_int($receipt['project_generation'] ?? null)
            || !\is_int($receipt['instance_generation'] ?? null)
            || (int)($receipt['instance_generation'] ?? 0) < 1
            || !\is_int($receipt['master_epoch'] ?? null)
            || (int)($receipt['master_epoch'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $requestDigest) !== 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($receipt['instance_digest'] ?? ''),
            ) !== 1
            || \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($receipt['launch_id'] ?? ''),
            ) !== 1
            || \preg_match('/\A[a-f0-9]{40}\z/D', $idempotencyKey) !== 1
            || !\hash_equals($expectedIdempotencyKey, $idempotencyKey)
            || !\is_int($activeConfigGeneration)
            || $activeConfigGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $activeConfigDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $hostBootId) !== 1
            || !\hash_equals($currentHostBootId, $hostBootId)
            || !(\is_int($issuedMonotonic) || \is_float($issuedMonotonic))
            || !\is_finite((float)$issuedMonotonic)
            || (float)$issuedMonotonic < 0.0
            || (float)$issuedMonotonic > $monotonicNow + 1.0
            || !\is_int($leaseSequence)
            || $leaseSequence < 1
            || !\is_int($receipt['lease_ttl_seconds'] ?? null)
            || (int)$receipt['lease_ttl_seconds'] < 1
            || (int)$receipt['lease_ttl_seconds'] > 3600
            || !$routesValid
            || $routeKeys !== $sortedRouteKeys
            || !\hash_equals($routesDigest, (string)($receipt['routes_digest'] ?? ''))
            || !\is_string($receipt['issued_at'] ?? null)
            || \strlen((string)$receipt['issued_at']) > 128
            || !\is_int($issuedTimestamp)
            || (float)$issuedMonotonic
                + (int)($receipt['lease_ttl_seconds'] ?? 0)
                + 5.0 < $monotonicNow
            || \preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
            || $expectedSignature === ''
            || !\hash_equals($expectedSignature, $signature)
        ) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: signed gateway lease receipt failed validation.'
            );
        }
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $receipt
     */
    private static function assertEndpointMatchesLeaseReceipt(
        array $endpoint,
        string $instanceName,
        array $receipt,
    ): void {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $projectUuid = (string)$receipt['project_uuid'];
        $endpointProjectUuid = \strtolower(\trim((string)($gateway['project_uuid'] ?? '')));
        $instanceGeneration = (int)($gateway['instance_generation'] ?? 0);
        $launchId = \strtolower(\trim((string)($gateway['launch_id'] ?? '')));
        $masterEpoch = (int)($endpoint['master_epoch'] ?? 0);
        if (!\hash_equals($projectUuid, $endpointProjectUuid)
            || !\hash_equals($instanceName, (string)($gateway['instance_id'] ?? ''))
            || $instanceGeneration < 1
            || $instanceGeneration !== (int)$receipt['instance_generation']
            || $masterEpoch < 1
            || $masterEpoch !== (int)$receipt['master_epoch']
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || !\hash_equals($launchId, (string)$receipt['launch_id'])
        ) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: signed gateway lease receipt belongs to another WLS launch.'
            );
        }
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $status authenticated project own-status
     */
    public static function assertLeaseReceiptMatchesAuthenticatedStatus(
        array $receipt,
        array $status,
    ): void {
        self::assertLeaseReceiptContract($receipt);
        $projectUuid = (string)($receipt['project_uuid'] ?? '');
        $gatewayEpoch = (string)($receipt['gateway_epoch'] ?? '');
        $activeConfigDigest = \strtolower(\trim((string)(
            $status['active_config_digest'] ?? ''
        )));
        $statusBootId = \strtolower(\trim((string)(
            $status['host_boot_id'] ?? ''
        )));
        if (!self::controlPlaneAcceptsRegistration($status)
            || ($status['publication_exact'] ?? false) !== true
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
            )
            || !\hash_equals(
                $gatewayEpoch,
                \strtolower(\trim((string)($status['epoch'] ?? ''))),
            )
            || (int)($status['project_generation'] ?? 0)
                !== (int)($receipt['project_generation'] ?? -1)
            || !\hash_equals(
                (string)($receipt['request_digest'] ?? ''),
                \strtolower(\trim((string)($status['request_digest'] ?? ''))),
            )
            || !\hash_equals(
                (string)($receipt['idempotency_key'] ?? ''),
                \strtolower(\trim((string)($status['idempotency_key'] ?? ''))),
            )
            || (int)($status['active_config_generation'] ?? 0)
                !== (int)($receipt['active_config_generation'] ?? -1)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $activeConfigDigest) !== 1
            || !\hash_equals(
                (string)($receipt['active_config_digest'] ?? ''),
                $activeConfigDigest,
            )
            || \preg_match('/\A[a-f0-9]{64}\z/D', $statusBootId) !== 1
            || !\hash_equals(
                (string)($receipt['host_boot_id'] ?? ''),
                $statusBootId,
            )
        ) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: gateway lease receipt is not current for this host epoch.'
            );
        }
        $instanceMatches = 0;
        foreach ((array)($status['instances'] ?? []) as $instance) {
            if (!\is_array($instance)
                || !\hash_equals(
                    (string)($receipt['instance_id'] ?? ''),
                    (string)($instance['instance_id'] ?? ''),
                )
            ) {
                continue;
            }
            if ((int)($instance['generation'] ?? 0)
                    !== (int)($receipt['instance_generation'] ?? -1)
                || \in_array(
                    (string)($instance['status'] ?? ''),
                    ['STALE', 'REMOVED'],
                    true,
                )
                || !\hash_equals(
                    (string)($receipt['instance_digest'] ?? ''),
                    \strtolower(\trim((string)($instance['digest'] ?? ''))),
                )
                || (int)($instance['master_epoch'] ?? 0)
                    !== (int)($receipt['master_epoch'] ?? -1)
                || !\hash_equals(
                    (string)($receipt['launch_id'] ?? ''),
                    \strtolower(\trim((string)($instance['launch_id'] ?? ''))),
                )
            ) {
                throw new \RuntimeException(
                    'REGISTER_REPLAY_REQUIRED: gateway instance generation is stale.'
                );
            }
            $instanceMatches++;
        }
        $expectedRoutes = \is_array($receipt['route_generations'] ?? null)
            ? $receipt['route_generations']
            : [];
        $observedRoutes = [];
        foreach ((array)($status['active_routes'] ?? []) as $route) {
            if (!\is_array($route)
                || !\hash_equals($projectUuid, (string)($route['project_uuid'] ?? ''))
            ) {
                continue;
            }
            $routeId = (string)($route['route_id'] ?? '');
            $routeStatus = \strtoupper(\trim((string)($route['status'] ?? '')));
            if (isset($observedRoutes[$routeId])
                || \preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || (int)($route['route_generation'] ?? 0) < 1
                || !\in_array($routeStatus, ['ACTIVE', 'PENDING_CERTIFICATE'], true)
            ) {
                throw new \RuntimeException(
                    'REGISTER_REPLAY_REQUIRED: authenticated gateway route closure is invalid.'
                );
            }
            $observedRoutes[$routeId] = (int)$route['route_generation'];
        }
        \ksort($observedRoutes, SORT_STRING);
        if ($instanceMatches !== 1 || $expectedRoutes !== $observedRoutes) {
            throw new \RuntimeException(
                'REGISTER_REPLAY_REQUIRED: gateway lease route closure has changed.'
            );
        }
    }

    /**
     * Wait for an accepted asynchronous publication to become active.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function awaitPublication(
        array $response,
        bool $administrator,
        string $projectUuid = '',
        float $timeoutSeconds = 90.0,
    ): array {
        $payload = \is_array($response['payload'] ?? null)
            ? $response['payload']
            : [];
        $operationId = \strtolower(\trim((string)($payload['operation_id'] ?? '')));
        if ($operationId === '') {
            return $payload;
        }
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $operationId) !== 1) {
            throw new \RuntimeException('Gateway returned an invalid publication operation ID.');
        }
        $deadline = \hrtime(true) / 1_000_000_000 + \max(1.0, $timeoutSeconds);
        $operation = \is_array($payload['operation'] ?? null)
            ? $payload['operation']
            : [];
        while (true) {
            $this->serviceProgressCallback();
            $state = (string)($operation['state'] ?? '');
            if ($state === 'COMMITTED') {
                $payload['operation'] = $operation;
                return $payload;
            }
            if ($state === 'FAILED') {
                $message = \trim((string)($operation['error'] ?? ''));
                throw new \RuntimeException(
                    $message !== ''
                        ? 'Gateway publication failed: ' . $message
                        : 'Gateway publication failed.'
                );
            }
            $now = \hrtime(true) / 1_000_000_000;
            if ($now >= $deadline) {
                throw new \RuntimeException(
                    'Gateway publication did not complete within the bounded operation deadline.'
                );
            }
            $this->waitForPublicationDelay(0.2, $deadline);
            $statusPayload = ['operation_id' => $operationId];
            try {
                $status = $administrator
                    ? $this->client->request('operation-status', $statusPayload)
                    : $this->client->projectRequest('operation-status', $statusPayload + [
                        'project_uuid' => $projectUuid,
                    ]);
            } catch (\RuntimeException $exception) {
                if (!$this->publicationStatusTransportFailureRetryable($exception)
                    || \hrtime(true) / 1_000_000_000 >= $deadline
                ) {
                    throw $exception;
                }
                continue;
            }
            if (!($status['ok'] ?? false)) {
                $retryAfter = $this->projectMutationRetryAfterSeconds($status);
                if ($retryAfter !== null
                    && (\hrtime(true) / 1_000_000_000) + $retryAfter < $deadline
                ) {
                    $this->waitForPublicationDelay(
                        (float)$retryAfter,
                        $deadline,
                    );
                    continue;
                }
                throw new \RuntimeException(
                    (string)(
                        $status['error']['message']
                        ?? 'Gateway publication operation status failed.'
                    )
                );
            }
            $operation = \is_array($status['payload'] ?? null)
                ? $status['payload']
                : [];
        }
    }

    private function waitForPublicationDelay(
        float $seconds,
        float $deadline,
    ): void {
        $until = \min(
            $deadline,
            \hrtime(true) / 1_000_000_000 + \max(0.0, $seconds),
        );
        do {
            $this->serviceProgressCallback();
            $remaining = $until - \hrtime(true) / 1_000_000_000;
            if ($remaining <= 0.0) {
                break;
            }
            \usleep((int)\max(
                1_000,
                \min(200_000, \ceil($remaining * 1_000_000)),
            ));
        } while (true);
        $this->serviceProgressCallback();
    }

    private function serviceProgressCallback(): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)();
        }
    }

    /**
     * Retry only an exactly identical generation/idempotency envelope when
     * the local transport cannot prove whether the Controller accepted it.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function idempotentProjectMutation(
        string $operation,
        array $payload,
        float $timeoutSeconds = 120.0,
    ): array {
        $deadline = \hrtime(true) / 1_000_000_000 + \max(1.0, $timeoutSeconds);
        while (true) {
            try {
                $response = $this->client->projectRequest($operation, $payload);
                $retryAfter = $this->projectMutationRetryAfterSeconds($response);
                if ($retryAfter === null) {
                    return $response;
                }
                if ((\hrtime(true) / 1_000_000_000) + $retryAfter >= $deadline) {
                    return $response;
                }
                $this->waitForPublicationDelay((float)$retryAfter, $deadline);
            } catch (\RuntimeException $exception) {
                if (!$this->publicationStatusTransportFailureRetryable($exception)
                    || \hrtime(true) / 1_000_000_000 >= $deadline
                ) {
                    throw $exception;
                }
                $this->waitForPublicationDelay(0.2, $deadline);
            }
        }
    }

    /**
     * The Controller rejects before mutation when its project update bucket is
     * empty. Replaying the exact generation/idempotency envelope after the
     * advertised delay is therefore both safe and required for bursty
     * start/drain/restart sequences.
     *
     * @param array<string,mixed> $response
     */
    private function projectMutationRetryAfterSeconds(array $response): ?int
    {
        if (($response['ok'] ?? false) === true) {
            return null;
        }
        $code = (string)($response['error']['code'] ?? '');
        $message = \trim((string)($response['error']['message'] ?? ''));
        if (\hash_equals(
            'Gateway wall clock is untrusted; security-sensitive mutation rejected.',
            $message,
        ) && (\hash_equals('unauthorized', $code) || \hash_equals('rejected', $code))) {
            // Do not bypass the Controller's monotonic stability gate. Keep
            // replaying the identical envelope until the Controller declares
            // the clock stable or this method's bounded deadline expires.
            return 1;
        }
        if (!\hash_equals('rejected', $code)
            || \preg_match(
                '/\A(?:Gateway request rate limit exceeded|Gateway publication is active); '
                    . 'retry_after=([1-9][0-9]*)\.\z/D',
                $message,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return \max(1, \min(5, (int)$matches[1]));
    }

    private function publicationStatusTransportFailureRetryable(\RuntimeException $exception): bool
    {
        $message = $exception->getMessage();
        return \str_starts_with($message, 'WLS Gateway admin endpoint unavailable:')
            || \str_starts_with($message, 'WLS Gateway project endpoint unavailable:')
            || \hash_equals('WLS Gateway returned an empty response.', $message)
            || \hash_equals('Unable to send WLS Gateway request.', $message);
    }

    /**
     * @param array<string,mixed> $drain
     * @return array<string,mixed>
     */
    private function awaitInstanceDrain(
        string $instanceName,
        string $projectUuid,
        array $drain,
        int $seconds,
    ): array {
        $now = \hrtime(true) / 1_000_000_000;
        $deadline = $now + $seconds;
        $nextLeaseHeartbeatAt = $now;
        $lastLeaseHeartbeatSuccessAt = $now;
        $lastLeaseHeartbeatFailure = '';
        $lastCounters = [
            'counters_known' => false,
            'active_requests' => 0,
            'long_lived_connections' => 0,
            'sse_connections' => 0,
            'websocket_connections' => 0,
        ];
        while (true) {
            $now = \hrtime(true) / 1_000_000_000;
            if ($now >= $nextLeaseHeartbeatAt) {
                try {
                    $heartbeat = $this->heartbeat($instanceName);
                    if (($heartbeat['accepted'] ?? false) !== true
                        || !\is_array($heartbeat['lease_receipt'] ?? null)
                    ) {
                        throw new \RuntimeException(
                            'Gateway did not return a signed DRAINING lifecycle receipt.'
                        );
                    }
                    $lastLeaseHeartbeatSuccessAt = $now;
                    $lastLeaseHeartbeatFailure = '';
                    $nextLeaseHeartbeatAt = $now
                        + self::DRAIN_LEASE_HEARTBEAT_SECONDS;
                } catch (\Throwable $throwable) {
                    $failedAt = \hrtime(true) / 1_000_000_000;
                    $lastLeaseHeartbeatFailure = \trim($throwable->getMessage());
                    // Retry transient control-plane failures quickly, but stop
                    // before the last signed receipt can reach its 45 second
                    // expiry. The exact launch remains irreversibly DRAINING
                    // and a later Stop can safely resume the same operation.
                    $nextLeaseHeartbeatAt = $failedAt + 1.0;
                    if ($failedAt - $lastLeaseHeartbeatSuccessAt
                        >= self::DRAIN_LEASE_HEARTBEAT_FAILURE_SECONDS
                    ) {
                        throw new \RuntimeException(
                            'Gateway drain lease heartbeat could not be refreshed within '
                                . 'the bounded safety window'
                                . ($lastLeaseHeartbeatFailure !== ''
                                    ? ': ' . $lastLeaseHeartbeatFailure
                                    : '.'),
                            0,
                            $throwable,
                        );
                    }
                }
            }
            $now = \hrtime(true) / 1_000_000_000;
            if ($now >= $deadline) {
                $unregistered = $this->unregister($instanceName);
                return $drain + $lastCounters
                    + self::forcedDrainSummary($lastCounters) + [
                        'drain_complete' => false,
                        'drain_timed_out' => true,
                        'unregistered' => (bool)($unregistered['accepted'] ?? false),
                    ];
            }
            try {
                $response = $this->client->projectRequest('own-status', [
                    'project_uuid' => $projectUuid,
                ]);
            } catch (\RuntimeException $exception) {
                if (!$this->publicationStatusTransportFailureRetryable($exception)
                    || $now - $lastLeaseHeartbeatSuccessAt
                        >= self::DRAIN_LEASE_HEARTBEAT_FAILURE_SECONDS
                ) {
                    throw $exception;
                }
                $this->waitForPublicationDelay(0.25, $deadline);
                continue;
            }
            if (!($response['ok'] ?? false)) {
                $retryAfter = $this->projectMutationRetryAfterSeconds($response);
                if ($retryAfter !== null
                    && $now + $retryAfter < $deadline
                ) {
                    $this->waitForPublicationDelay((float)$retryAfter, $deadline);
                    continue;
                }
                throw new \RuntimeException(
                    (string)(
                        $response['error']['message']
                        ?? 'Gateway drain status failed.'
                    )
                );
            }
            $publishedInstances = $response['payload']['instances'] ?? null;
            if (!\is_array($publishedInstances)
                || !\array_is_list($publishedInstances)
                || \count($publishedInstances) > 64
            ) {
                throw new \RuntimeException(
                    'Gateway drain status returned an invalid instance collection.',
                );
            }
            $instance = null;
            $instanceMatches = 0;
            foreach ($publishedInstances as $candidate) {
                if (\is_array($candidate)
                    && \hash_equals(
                        $instanceName,
                        (string)($candidate['instance_id'] ?? ''),
                    )
                ) {
                    $instanceMatches++;
                    $instance = $candidate;
                }
            }
            if ($instanceMatches > 1) {
                throw new \RuntimeException(
                    'Gateway drain status duplicated the target instance identity.',
                );
            }
            if ($instance === null) {
                return $drain + [
                    'drain_complete' => true,
                    'forced_connections' => 0,
                    'forced_connections_known' => true,
                    'unregistered' => true,
                ];
            }
            $lastCounters = [
                'counters_known' => ($instance['counters_known'] ?? false) === true,
                'active_requests' => \max(0, (int)($instance['active_requests'] ?? 0)),
                'long_lived_connections' => \max(
                    0,
                    (int)($instance['long_lived_connections'] ?? 0),
                ),
                'sse_connections' => \max(0, (int)($instance['sse_connections'] ?? 0)),
                'websocket_connections' => \max(
                    0,
                    (int)($instance['websocket_connections'] ?? 0),
                ),
            ];
            if ($lastCounters['counters_known']
                && $lastCounters['active_requests'] === 0
                && $lastCounters['long_lived_connections'] === 0
            ) {
                $unregistered = $this->unregister($instanceName);
                return $drain + $lastCounters + [
                    'drain_complete' => true,
                    'forced_connections' => 0,
                    'forced_connections_known' => true,
                    'unregistered' => (bool)($unregistered['accepted'] ?? false),
                ];
            }
            \usleep(250000);
        }
    }

    /**
     * @param array<string,mixed> $counters
     * @return array<string,int|bool>
     */
    private static function forcedDrainSummary(array $counters): array
    {
        $known = ($counters['counters_known'] ?? false) === true;
        $activeRequests = \max(0, (int)($counters['active_requests'] ?? 0));
        $longLived = \max(0, (int)($counters['long_lived_connections'] ?? 0));
        return [
            // A long-lived request may appear in both counters. The maximum is
            // a safe non-duplicating connection count; both raw counters stay
            // visible for diagnostics.
            'forced_connections' => $known
                ? \max($activeRequests, $longLived)
                : 0,
            'forced_connections_known' => $known,
            'forced_active_requests' => $activeRequests,
            'forced_long_lived_connections' => $longLived,
        ];
    }

    /**
     * Compatibility facade for the old prototype upgrade command.
     *
     * @return array<string,mixed>
     */
    public function installInactiveSlot(
        ?string $packageDirectory = null,
        string $profile = 'default',
    ): array {
        if ($packageDirectory === null || \trim($packageDirectory) === '') {
            throw new \RuntimeException(
                'WLS 2.0 no longer seeds a host gateway from the project; provide a signed self-contained package.'
            );
        }
        return $this->stagePackage($packageDirectory, $profile);
    }

    /**
     * @return array<string,mixed>
     */
    private function publicPortsAvailable(string $profile = 'default'): array
    {
        $profile = self::normalizeListenProfile($profile);
        $sockets = [];
        $diagnostics = [];
        try {
            foreach ($this->publicListenTargets($profile) as $target) {
                $address = (string)$target['address'];
                $context = ($target['family'] ?? '') === 'ipv6'
                    ? \stream_context_create(['socket' => ['ipv6_v6only' => true]])
                    : \stream_context_create();
                $socket = @\stream_socket_server(
                    $address,
                    $errno,
                    $error,
                    \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                    $context,
                );
                if (!\is_resource($socket)) {
                    $state = self::classifyBindFailure((int)$errno, (string)$error);
                    $diagnostics[] = $target + [
                        'available' => false,
                        'state' => $state,
                        'errno' => (int)$errno,
                        'error' => GatewayBoundedText::singleLine(
                            (string)$error,
                            256,
                            'bind failed',
                        ),
                    ];
                    return [
                        'ok' => false,
                        'state' => $state,
                        'reason' => $address . ' is unavailable: ' . $error,
                        'owner' => $state === 'PORT_TAKEN' ? 'unknown' : '',
                        'diagnostics' => $diagnostics,
                    ];
                }
                $sockets[] = $socket;
                $diagnostics[] = $target + [
                    'available' => true,
                    'state' => 'AVAILABLE',
                    'errno' => 0,
                    'error' => '',
                ];
            }
            return [
                'ok' => true,
                'state' => 'AVAILABLE',
                'reason' => $profile === 'ipv4-only'
                    ? 'public IPv4 ports are available'
                    : 'public IPv4 and IPv6 ports are available',
                'diagnostics' => $diagnostics,
            ];
        } finally {
            foreach ($sockets as $socket) {
                @\fclose($socket);
            }
        }
    }

    /**
     * Classify public-port availability without binding sockets, changing a
     * service definition, requesting credentials, or touching another process.
     * Numeric LISTEN ownership is authoritative; address-family targets are
     * retained separately so dual-stack policy remains visible in diagnostics.
     *
     * @return array<string,mixed>
     */
    private function classifyPublicPortsReadOnly(string $profile): array
    {
        $profile = self::normalizeListenProfile($profile);
        $ports = \array_values(\array_unique([
            $this->paths->publicHttpPort(),
            $this->paths->publicHttpsPort(),
        ]));
        $numericOwner = [];
        foreach ($ports as $port) {
            try {
                $numericOwner[$port] = Processer::getProcessIdByPort($port);
            } catch (\Throwable) {
                $numericOwner[$port] = -1;
            }
        }
        $diagnostics = [];
        foreach ($this->publicListenTargets($profile) as $target) {
            $port = (int)$target['port'];
            $pid = (int)($numericOwner[$port] ?? -1);
            $familyObservation = $this->readOnlyLoopbackListenerProbe(
                (string)$target['family'],
                $port,
            );
            $portState = $pid > 0 || $familyObservation === true
                ? true
                : ($pid < 0 && $familyObservation === null ? null : false);
            $diagnostics[] = $target + [
                'check' => 'read_only_listener_table_and_connect',
                'occupied' => $portState,
                'owner_pid_visible' => $pid > 0,
            ];
        }
        $occupiedPorts = [];
        $unknown = false;
        foreach ($diagnostics as $diagnostic) {
            if (($diagnostic['occupied'] ?? null) === true) {
                $occupiedPorts[(int)$diagnostic['port']] = true;
            } elseif (($diagnostic['occupied'] ?? null) === null) {
                $unknown = true;
            }
        }
        $occupiedPorts = \array_keys($occupiedPorts);
        if ($occupiedPorts !== []) {
            return [
                'state' => 'PORT_TAKEN',
                'reason' => 'Unknown listener owns public port(s) '
                    . \implode(', ', \array_map('strval', $occupiedPorts))
                    . '; WLS will not stop or modify it.',
                'owner' => 'unknown',
                'diagnostics' => $diagnostics,
            ];
        }
        if ($unknown) {
            return [
                'state' => 'PORT_PERMISSION',
                'reason' => 'Public listener ownership could not be inspected with the current host permissions; WLS will not probe by binding during startup.',
                'owner' => 'unknown',
                'diagnostics' => $diagnostics,
            ];
        }
        return [
            'state' => 'AVAILABLE',
            'reason' => 'No public listener was observed by the read-only host check.',
            'owner' => '',
            'diagnostics' => $diagnostics,
        ];
    }

    private function readOnlyLoopbackListenerProbe(string $family, int $port): ?bool
    {
        $address = $family === 'ipv6'
            ? 'tcp://[::1]:' . $port
            : 'tcp://127.0.0.1:' . $port;
        $socket = @\stream_socket_client(
            $address,
            $errno,
            $error,
            0.15,
            \STREAM_CLIENT_CONNECT,
        );
        if (\is_resource($socket)) {
            @\fclose($socket);
            return true;
        }
        if (\in_array((int)$errno, [61, 111, 10061], true)) {
            return false;
        }
        return null;
    }

    private function installedListenProfileOrDefault(): string
    {
        try {
            $manifest = $this->packages->installedManifest($this->paths->activeSlot());
            return self::normalizeListenProfile((string)($manifest['listen_profile'] ?? 'default'));
        } catch (\Throwable) {
            return 'default';
        }
    }

    /** @return list<array{family:string,address:string,port:int}> */
    private function publicListenTargets(string $profile): array
    {
        $targets = [];
        foreach ([
            $this->paths->publicHttpPort(),
            $this->paths->publicHttpsPort(),
        ] as $port) {
            $targets[] = [
                'family' => 'ipv4',
                'address' => 'tcp://0.0.0.0:' . $port,
                'port' => $port,
            ];
            if ($profile !== 'ipv4-only') {
                $targets[] = [
                    'family' => 'ipv6',
                    'address' => 'tcp://[::]:' . $port,
                    'port' => $port,
                ];
            }
        }
        return $targets;
    }

    private static function normalizeListenProfile(string $profile): string
    {
        $profile = \strtolower(\trim($profile));
        if (!\in_array($profile, ['default', 'ipv4-only'], true)) {
            throw new \InvalidArgumentException(
                'Gateway listen profile must be default or ipv4-only.'
            );
        }
        return $profile;
    }

    private static function classifyBindFailure(int $errno, string $error): string
    {
        if (\in_array($errno, [13, 10013], true)
            || \preg_match('/permission denied|access.*denied/i', $error) === 1
        ) {
            return 'PORT_PERMISSION';
        }
        if (\in_array($errno, [48, 98, 10048], true)
            || \preg_match('/address already in use|only one usage/i', $error) === 1
        ) {
            return 'PORT_TAKEN';
        }
        if (\in_array($errno, [47, 97, 10047], true)) {
            return 'PORT_PROFILE_UNAVAILABLE';
        }
        return 'PORT_UNAVAILABLE';
    }

    private static function monotonicNow(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

}
