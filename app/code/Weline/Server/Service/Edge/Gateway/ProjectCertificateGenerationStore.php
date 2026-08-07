<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Project-owned immutable TLS material with per-domain monotonic activation.
 *
 * Source certificates remain the project fact source. Both gateway
 * registration and the same-Master WLS fallback consume only a validated
 * content-addressed snapshot, so a renewal can never replace a live TLS
 * generation halfway through a read.
 */
final class ProjectCertificateGenerationStore
{
    public const SCHEMA_VERSION = 1;
    public const RETIREMENT_PHASE_PREPARED = 'prepared';
    public const RETIREMENT_PHASE_RUNTIME_PENDING = 'runtime_pending';
    public const RETIREMENT_PHASE_RUNTIME_RETIRED = 'runtime_retired';
    public const RETIREMENT_PHASE_LEGACY_RETIRED = 'legacy_retired';
    public const RETIREMENT_PHASE_ENDPOINT_RETIRED = 'endpoint_retired';
    public const RETIREMENT_PHASE_SOURCE_RETIRED = 'source_retired';
    public const RETIREMENT_PHASE_DATABASE_RETIRED = 'database_retired';
    public const RETIREMENT_PHASE_EVENT_DISPATCHED = 'event_dispatched';
    public const RETIREMENT_PHASE_COMPLETE = 'complete';
    public const RETIREMENT_OPERATION_PROJECTION = 'projection';
    public const RETIREMENT_OPERATION_DISABLE = 'disable';
    public const RETIREMENT_OPERATION_DELETE = 'delete';
    private const MAX_MATERIAL_BYTES = 1_048_576;
    private const MAX_STORED_SNAPSHOTS = 1024;
    private const MAX_STORED_SNAPSHOT_BYTES = 1_073_741_824;
    private const SNAPSHOT_RETENTION_SECONDS = 604_800;
    private const MAX_SNAPSHOT_ROOT_ENTRIES = 2048;
    private const MAX_ACTIVE_MANIFESTS = 1024;
    private const RETIREMENT_CURSOR_SCHEMA = 'wls-certificate-retirement-cursor/1';
    private const SNAPSHOT_RETIREMENT_SCHEMA = 'wls-certificate-snapshot-retirement/1';
    private const SNAPSHOT_RETIREMENT_MARKER_SCHEMA
        = 'wls-certificate-snapshot-retirement-marker/1';

    /** @var array<string,int> */
    private const RETIREMENT_PHASE_ORDER = [
        self::RETIREMENT_PHASE_PREPARED => 0,
        self::RETIREMENT_PHASE_RUNTIME_PENDING => 1,
        self::RETIREMENT_PHASE_RUNTIME_RETIRED => 2,
        self::RETIREMENT_PHASE_LEGACY_RETIRED => 3,
        self::RETIREMENT_PHASE_ENDPOINT_RETIRED => 4,
        self::RETIREMENT_PHASE_SOURCE_RETIRED => 5,
        self::RETIREMENT_PHASE_DATABASE_RETIRED => 6,
        self::RETIREMENT_PHASE_EVENT_DISPATCHED => 7,
        self::RETIREMENT_PHASE_COMPLETE => 8,
    ];

    private readonly string $projectRoot;
    private readonly string $storeRoot;
    private readonly int $projectOwner;
    private readonly int $projectGroup;
    private readonly ?\Closure $snapshotWallClock;
    private readonly ?\Closure $snapshotMonotonicClock;
    private readonly ?\Closure $snapshotBootIdentityResolver;

    /** @var array<string,array{owner:string,depth:int}> */
    private static array $heldLifecycleLocks = [];

    public function __construct(
        ?string $projectRoot = null,
        ?\Closure $snapshotWallClock = null,
        ?\Closure $snapshotMonotonicClock = null,
        ?\Closure $snapshotBootIdentityResolver = null,
    ) {
        $requestedRoot = $projectRoot ?? (string)BP;
        if ($requestedRoot === ''
            || \str_contains($requestedRoot, "\0")
            || \is_link($requestedRoot)
        ) {
            throw new \RuntimeException('Unable to resolve a safe WLS project root.');
        }
        $root = \realpath($requestedRoot);
        $rootStatus = \is_string($root) ? @\lstat($root) : false;
        if (!\is_string($root)
            || $root === ''
            || !\is_array($rootStatus)
            || \is_link($root)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || $this->isFilesystemRoot($root)
        ) {
            throw new \RuntimeException('Unable to resolve a safe WLS project root.');
        }
        $this->projectRoot = \rtrim($root, '/\\');
        $this->storeRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl'
            . DIRECTORY_SEPARATOR . '.wls-generations';
        $owner = @\lstat($this->projectRoot);
        $this->projectOwner = \is_array($owner) && \is_int($owner['uid'] ?? null)
            ? (int)$owner['uid']
            : -1;
        $this->projectGroup = \is_array($owner) && \is_int($owner['gid'] ?? null)
            ? (int)$owner['gid']
            : -1;
        $this->snapshotWallClock = $snapshotWallClock;
        $this->snapshotMonotonicClock = $snapshotMonotonicClock;
        $this->snapshotBootIdentityResolver = $snapshotBootIdentityResolver;
    }

    /**
     * Validate and atomically activate one domain's certificate material.
     *
     * If a newly supplied source is invalid but the currently active snapshot
     * is still valid, the current generation is returned with
     * retained_previous=true and remains active.
     *
     * @return array{
     *   domain:string,
     *   generation:int,
     *   source_digest:string,
     *   cert_path:string,
     *   key_path:string,
     *   chain_path:string,
     *   leaf_fingerprint_sha256:string,
     *   retained_previous:bool,
     *   activation_error:string
     * }
     */
    public function activate(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain = '',
        array $sourceRoots = [],
        ?float $deadlineMonotonic = null,
    ): array {
        $this->ensureStoreDirectories();
        return $this->withCertificateLifecycleLock(
            fn (): array => $this->activateWithinLifecycleLock(
                $domain,
                $certificate,
                $privateKey,
                $chain,
                $sourceRoots,
                $deadlineMonotonic,
            ),
            $this->retirementLockWaitTimeout($deadlineMonotonic, 10.0),
        );
    }

    /**
     * Share the lifecycle lock with explicit certificate transitions. Nested
     * activation in the same PHP process reuses the already-held authority
     * instead of deadlocking on a second file descriptor.
     */
    public function withCertificateLifecycleLock(
        callable $callback,
        float $waitTimeoutSeconds = 10.0,
    ): mixed
    {
        $this->ensureStoreDirectories();
        $path = $this->certificateLifecycleLockPath();
        $executionOwner = self::lifecycleExecutionOwner();
        $held = self::$heldLifecycleLocks[$path] ?? null;
        if (\is_array($held)
            && \hash_equals($executionOwner, (string)($held['owner'] ?? ''))
            && (int)($held['depth'] ?? 0) > 0
        ) {
            self::$heldLifecycleLocks[$path]['depth']++;
            try {
                return $callback();
            } finally {
                self::$heldLifecycleLocks[$path]['depth']--;
            }
        }
        if (\is_array($held) && (int)($held['depth'] ?? 0) > 0) {
            throw new \RuntimeException(
                'Certificate lifecycle lock is held by another execution context.',
            );
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $path,
            function () use ($path, $callback, $executionOwner): mixed {
                self::$heldLifecycleLocks[$path] = [
                    'owner' => $executionOwner,
                    'depth' => 1,
                ];
                try {
                    return $callback();
                } finally {
                    unset(self::$heldLifecycleLocks[$path]);
                }
            },
            fn ($handle, string $lockPath): mixed => $this->preserveProjectArtifactOwnership(
                $lockPath,
                $handle,
            ),
            waitTimeoutSeconds: $waitTimeoutSeconds,
        );
    }

    private static function lifecycleExecutionOwner(): string
    {
        $processId = \getmypid();
        $processIdentity = \is_int($processId) && $processId > 0
            ? (string)$processId
            : 'unknown';
        if (\class_exists(\Fiber::class, false)) {
            $fiber = \Fiber::getCurrent();
            if ($fiber instanceof \Fiber) {
                return 'process:' . $processIdentity . ':fiber:'
                    . \spl_object_id($fiber);
            }
        }
        if (\class_exists('Swoole\\Coroutine', false)) {
            try {
                $coroutineId = \Swoole\Coroutine::getCid();
                if (\is_int($coroutineId) && $coroutineId >= 0) {
                    return 'process:' . $processIdentity . ':swoole:'
                        . $coroutineId;
                }
            } catch (\Throwable) {
                // Fall through to the non-coroutine execution owner.
            }
        }
        return 'process:' . $processIdentity . ':main';
    }

    private function assertCertificateLifecycleLockHeld(): void
    {
        $held = self::$heldLifecycleLocks[$this->certificateLifecycleLockPath()] ?? null;
        if (!\is_array($held)
            || (int)($held['depth'] ?? 0) < 1
            || !\hash_equals(
                self::lifecycleExecutionOwner(),
                (string)($held['owner'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Explicit certificate re-enable requires the current lifecycle lock.',
            );
        }
    }

    private function retirementDeadlineRemaining(
        ?float $deadlineMonotonic,
    ): float {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException(
                'Certificate retirement state deadline is invalid.',
            );
        }
        $remaining = $deadlineMonotonic - $this->snapshotMonotonicNow();
        if ($remaining <= 0.0) {
            throw new \RuntimeException(
                'Certificate retirement state deadline was exhausted.',
            );
        }
        return $remaining;
    }

    private function retirementLockWaitTimeout(
        ?float $deadlineMonotonic,
        float $defaultSeconds = 300.0,
    ): float {
        if ($deadlineMonotonic === null) {
            return $defaultSeconds;
        }
        return \min(0.25, $this->retirementDeadlineRemaining($deadlineMonotonic));
    }

    private function withRetirementStateLock(
        string $path,
        \Closure $callback,
        ?float $deadlineMonotonic,
        float $defaultWaitSeconds = 300.0,
    ): mixed {
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $path,
            function () use ($callback, $deadlineMonotonic): mixed {
                $this->retirementDeadlineRemaining($deadlineMonotonic);
                // The deadline fences side effects that have not started. A
                // callback may atomically publish durable state, so crossing
                // the deadline during that write cannot turn its committed
                // result into a timeout and invite a conflicting retry.
                return $callback();
            },
            fn ($handle, string $lockPath): mixed => $this
                ->preserveProjectArtifactOwnership($lockPath, $handle),
            waitTimeoutSeconds: $this->retirementLockWaitTimeout(
                $deadlineMonotonic,
                $defaultWaitSeconds,
            ),
        );
    }

    /**
     * Sign the only durable authority that may cross the current disabled
     * tombstone. The explicit HTTPS lifecycle owns this API; PEM files,
     * database rows and ordinary startup/import paths are not re-enable
     * authority. The intent is exact to both the tombstone and target material.
     *
     * The caller must already hold certificateLifecycleLockPath().
     *
     * @return array{required:bool,domain:string,source_digest:string,intent_id:string}
     */
    public function issueExplicitReenableIntent(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain = '',
        array $sourceRoots = [],
        ?float $deadlineMonotonic = null,
    ): array {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        $this->assertCertificateLifecycleLockHeld();
        return $this->withRetirementStateLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
            function () use (
                $domain,
                $certificate,
                $privateKey,
                $chain,
                $sourceRoots,
                $deadlineMonotonic,
            ): array {
                $active = $this->readActiveUnlocked($domain, false);
                $disabled = $this->readDisabledUnlocked($domain);
                $this->assertExplicitRetirementAllowsReenable($disabled);
                $this->assertSourcesInsideRoots(
                    [$certificate, $privateKey, $chain],
                    $sourceRoots,
                );
                $material = $this->validateSourceMaterial(
                    $domain,
                    $certificate,
                    $privateKey,
                    $chain,
                );
                $snapshot = $this->publishSnapshot(
                    $material,
                    $deadlineMonotonic,
                );
                $sourceDigest = (string)$snapshot['source_digest'];
                if ($disabled === null
                    || ($active !== null
                        && (int)$active['generation'] > (int)$disabled['generation'])
                ) {
                    $this->removeReenableIntentUnlocked($domain);
                    return [
                        'required' => false,
                        'domain' => $domain,
                        'source_digest' => $sourceDigest,
                        'intent_id' => '',
                    ];
                }
                $intentId = $this->reenableIntentId(
                    $domain,
                    (int)$disabled['generation'],
                    (string)$disabled['source_digest'],
                    $sourceDigest,
                );
                $intent = [
                    'schema' => 'wls-project-certificate-reenable/1',
                    'state' => 'authorized',
                    'domain' => $domain,
                    'disabled_generation' => (int)$disabled['generation'],
                    'disabled_source_digest' => (string)$disabled['source_digest'],
                    'target_source_digest' => $sourceDigest,
                    'intent_id' => $intentId,
                    'issued_at' => \gmdate(DATE_ATOM),
                ];
                $this->publishManifest($this->reenableIntentFile($domain), $intent);
                $verified = $this->readReenableIntentUnlocked($domain);
                if ($verified === null
                    || !\hash_equals($intentId, (string)$verified['intent_id'])
                ) {
                    throw new \RuntimeException(
                        'Explicit certificate re-enable intent was not durably published.',
                    );
                }
                return [
                    'required' => true,
                    'domain' => $domain,
                    'source_digest' => $sourceDigest,
                    'intent_id' => $intentId,
                ];
            },
            $deadlineMonotonic,
            10.0,
        );
    }

    /** @return array<string,mixed> */
    private function activateWithinLifecycleLock(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain = '',
        array $sourceRoots = [],
        ?float $deadlineMonotonic = null,
    ): array {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        $lockPath = $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock';
        return $this->withRetirementStateLock(
            $lockPath,
            function () use (
                $domain,
                $certificate,
                $privateKey,
                $chain,
                $sourceRoots,
                $deadlineMonotonic,
            ): array {
            // An expired generation is still the historical generation floor,
            // but it must not prevent a valid replacement from being activated.
            $active = $this->readActiveUnlocked($domain, false);
            $disabled = $this->readDisabledUnlocked($domain);
            $this->assertExplicitRetirementAllowsReenable($disabled);
            if (\is_array($disabled)
                && \is_array($active)
                && (int)$active['generation'] > (int)$disabled['generation']
            ) {
                $disabled = $this->supersedeRetirementIntentUnlocked(
                    $disabled,
                    $active,
                );
            }
            try {
                $this->assertSourcesInsideRoots(
                    [$certificate, $privateKey, $chain],
                    $sourceRoots,
                );
                $material = $this->validateSourceMaterial(
                    $domain,
                    $certificate,
                    $privateKey,
                    $chain,
                );
                $snapshot = $this->publishSnapshot(
                    $material,
                    $deadlineMonotonic,
                );
                $reenableIntent = null;
                if ($disabled !== null
                    && ($active === null
                        || (int)$active['generation'] <= (int)$disabled['generation'])
                ) {
                    $reenableIntent = $this->readReenableIntentUnlocked($domain);
                    if ($reenableIntent === null
                        || (int)$reenableIntent['disabled_generation']
                            !== (int)$disabled['generation']
                        || !\hash_equals(
                            (string)$disabled['source_digest'],
                            (string)$reenableIntent['disabled_source_digest'],
                        )
                        || !\hash_equals(
                            (string)$snapshot['source_digest'],
                            (string)$reenableIntent['target_source_digest'],
                        )
                    ) {
                        throw new \RuntimeException(
                            'Certificate is disabled; only an exact explicit re-enable intent may cross its tombstone.',
                        );
                    }
                }
                if ($active !== null
                    && ($disabled === null
                        || (int)$active['generation'] > (int)$disabled['generation'])
                    && \hash_equals(
                        (string)$active['source_digest'],
                        (string)$snapshot['source_digest'],
                    )
                ) {
                    $this->transitionCertificateSnapshotReferences(
                        $this->activeManifestSnapshotDigestSet($active),
                        [],
                        $deadlineMonotonic,
                    );
                    return $active + [
                        'retained_previous' => false,
                        'activation_error' => '',
                    ];
                }
                // Generation is allocated from a project-wide durable floor.
                // Deactivation removes the mutable per-domain selector, so the
                // selector alone cannot prevent generation reuse when the same
                // domain is later imported or enabled again.
                $generation = $this->allocateCertificateGeneration(\max(
                    0,
                    (int)($active['generation'] ?? 0),
                    (int)($disabled['generation'] ?? 0),
                ));
                $next = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'domain' => $domain,
                    'generation' => $generation,
                    'source_digest' => (string)$snapshot['source_digest'],
                    'cert_path' => (string)$snapshot['cert_path'],
                    'key_path' => (string)$snapshot['key_path'],
                    'chain_path' => (string)$snapshot['chain_path'],
                    'leaf_fingerprint_sha256'
                        => (string)$snapshot['leaf_fingerprint_sha256'],
                    'cert_sha256' => (string)$snapshot['cert_sha256'],
                    'key_sha256' => (string)$snapshot['key_sha256'],
                    'chain_sha256' => (string)$snapshot['chain_sha256'],
                    'activated_at' => \gmdate(DATE_ATOM),
                    'previous' => $active === null ? null : [
                        'generation' => (int)$active['generation'],
                        'source_digest' => (string)$active['source_digest'],
                        'cert_path' => (string)$active['cert_path'],
                        'key_path' => (string)$active['key_path'],
                        'chain_path' => (string)$active['chain_path'],
                    ],
                ];
                $previousSnapshotReferences = $this
                    ->activeManifestSnapshotDigestSet($active);
                $nextSnapshotReferences = $this
                    ->activeManifestSnapshotDigestSet($next);
                $this->transitionCertificateSnapshotReferences(
                    $nextSnapshotReferences,
                    \array_diff_key(
                        $previousSnapshotReferences,
                        $nextSnapshotReferences,
                    ),
                    $deadlineMonotonic,
                );
                $this->publishManifest($this->activeManifestFile($domain), $next);
                if ($disabled !== null
                    && $generation > (int)$disabled['generation']
                ) {
                    $disabled = $this->supersedeRetirementIntentUnlocked(
                        $disabled,
                        $next,
                    );
                }
                if ($reenableIntent !== null) {
                    // The new active generation is already durably above the
                    // exact tombstone, so a cleanup failure cannot authorize a
                    // second crossing. A later deactivate allocates a newer
                    // tombstone before it removes the selector.
                    try {
                        $this->removeReenableIntentUnlocked($domain);
                    } catch (\Throwable) {
                        // Keep activation recoverable after a post-commit disk
                        // failure; the consumed tombstone generation is the
                        // authoritative one-time fence.
                    }
                }
                return $next + [
                    'retained_previous' => false,
                    'activation_error' => '',
                ];
            } catch (\Throwable $throwable) {
                $retained = null;
                if ($active !== null) {
                    try {
                        $candidate = $this->readActiveUnlocked($domain, true);
                        if ($candidate !== null
                            && ($disabled === null
                                || (int)$candidate['generation']
                                    > (int)$disabled['generation'])
                            && \hash_equals(
                                (string)$active['source_digest'],
                                (string)$candidate['source_digest'],
                            )
                        ) {
                            $retained = $candidate;
                        }
                    } catch (\Throwable) {
                        // An expired or concurrently replaced generation is not
                        // a safe serving fallback. Surface the activation error.
                    }
                }
                if ($retained !== null) {
                    return \array_replace($retained, [
                        'retained_previous' => true,
                        'activation_error' => $throwable->getMessage(),
                    ]);
                }
                throw new \RuntimeException(
                    'No valid active certificate generation is available for ' . $domain
                    . ': ' . $throwable->getMessage(),
                    0,
                    $throwable,
                );
            }
            },
            $deadlineMonotonic,
        );
    }

    /**
     * @param list<string> $sources
     * @param array<int|string,string> $sourceRoots
     */
    private function assertSourcesInsideRoots(array $sources, array $sourceRoots): void
    {
        if ($sourceRoots === []) {
            $sourceRoots = [
                $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
                    . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl',
            ];
        }
        $roots = [];
        foreach ($sourceRoots as $root) {
            $candidate = (string)$root;
            if ($candidate !== '' && !$this->isAbsolutePath($candidate)) {
                $candidate = $this->projectRoot . DIRECTORY_SEPARATOR . $candidate;
            }
            $canonical = \realpath($candidate);
            $status = @\lstat($candidate);
            if (!\is_string($canonical)
                || $canonical === ''
                || !\is_array($status)
                || \is_link($candidate)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || !$this->samePath($candidate, $canonical)
                || $this->isFilesystemRoot($canonical)
            ) {
                throw new \RuntimeException(
                    'Enrolled certificate source root must be a canonical directory.'
                );
            }
            $rootOwner = \is_int($status['uid'] ?? null)
                ? (int)$status['uid']
                : -1;
            $this->assertEnrolledDirectoryComponents(
                $canonical,
                $canonical,
                $rootOwner,
            );
            $roots[$this->pathKey($canonical)] = [
                'path' => $canonical,
                'owner' => $rootOwner,
            ];
        }
        if ($roots === []) {
            throw new \RuntimeException('No enrolled certificate source root is available.');
        }
        foreach ($sources as $source) {
            if ($source === '') {
                continue;
            }
            $real = \realpath($source);
            if (!\is_string($real)
                || !\is_file($real)
                || \is_link($source)
                || !$this->samePath($source, $real)
            ) {
                throw new \RuntimeException('Certificate material file is unavailable.');
            }
            foreach ($roots as $enrollment) {
                $root = (string)$enrollment['path'];
                if ($this->pathInside($real, $root)) {
                    $this->assertEnrolledDirectoryComponents(
                        $root,
                        \dirname($real),
                        (int)$enrollment['owner'],
                    );
                    if (\PHP_OS_FAMILY !== 'Windows'
                        && (int)$enrollment['owner'] >= 0
                    ) {
                        $sourceStatus = @\lstat($real);
                        if (!\is_array($sourceStatus)
                            || (int)($sourceStatus['uid'] ?? -1)
                                !== (int)$enrollment['owner']
                        ) {
                            throw new \RuntimeException(
                                'Certificate source owner differs from its enrolled root owner.'
                            );
                        }
                    }
                    continue 2;
                }
            }
            throw new \RuntimeException(
                'Certificate source is outside every enrolled certificate root: ' . $source
            );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function active(
        string $domain,
        ?float $deadlineMonotonic = null,
    ): ?array
    {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        return $this->withRetirementStateLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
            function () use ($domain): ?array {
                $active = $this->readActiveUnlocked($domain);
                if ($active === null) {
                    return null;
                }
                $disabled = $this->readDisabledUnlocked($domain);
                if ($disabled !== null
                    && (int)$active['generation'] <= (int)$disabled['generation']
                ) {
                    // deactivate() durably publishes the tombstone before it
                    // removes the mutable selector. Holding the same lock and
                    // comparing both generations makes that crash window an
                    // effective revocation instead of reviving the old PEM.
                    return null;
                }
                return $active;
            },
            $deadlineMonotonic,
        );
    }

    /**
     * Read the durable, monotonic disabled-certificate tombstone for a domain.
     *
     * @return array<string,mixed>|null
     */
    public function disabled(
        string $domain,
        ?float $deadlineMonotonic = null,
    ): ?array
    {
        $this->retirementDeadlineRemaining($deadlineMonotonic);
        $domain = $this->normalizeDomain($domain);
        $disabled = $this->readDisabledUnlocked($domain);
        $this->retirementDeadlineRemaining($deadlineMonotonic);
        return $disabled;
    }

    /**
     * Enumerate the complete durable disabled-certificate authority.
     *
     * The certificate table may legitimately omit a revoked/deleted row. The
     * tombstone store is therefore the only project-owned fact capable of
     * proving that an absent final certificate is an intentional transition,
     * rather than a transient empty database result.
     *
     * @return array<string,array<string,mixed>>
     */
    public function disabledCertificates(?float $deadlineMonotonic = null): array
    {
        $this->ensureStoreDirectories();
        return $this->withRetirementStateLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
            fn (): array => $this->disabledCertificatesUnlocked(
                $deadlineMonotonic,
            ),
            $deadlineMonotonic,
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function disabledCertificatesUnlocked(
        ?float $deadlineMonotonic,
    ): array {
        $root = $this->storeRoot . DIRECTORY_SEPARATOR . 'disabled';
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate disabled certificate tombstones.',
            );
        }
        $facts = [];
        $recoveries = [];
        $validatedTargets = [];
        $count = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                $this->retirementDeadlineRemaining($deadlineMonotonic);
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$count > self::MAX_ACTIVE_MANIFESTS) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone set is malformed or outside bounds.',
                    );
                }
                $recovery = $this->selectorAtomicCrashArtifact(
                    $root,
                    $leaf,
                    'disabled',
                );
                if ($recovery !== null) {
                    $recoveries[] = $recovery;
                    continue;
                }
                if (\preg_match('/\A[a-f0-9]{32}\.json\z/D', $leaf) !== 1) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone set is malformed or outside bounds.',
                    );
                }
                $path = $root . DIRECTORY_SEPARATOR . $leaf;
                $this->preserveProjectArtifactOwnership($path);
                $status = @\lstat($path);
                if (!\is_array($status)) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone is indeterminate.',
                    );
                }
                $before = $this->assertAtomicRecoveryFile(
                    $path,
                    $status,
                    'selector target',
                );
                $manifest = $this->readManifest($path);
                $domain = $this->normalizeDomain((string)(
                    $manifest['domain'] ?? ''
                ));
                if (!\hash_equals(
                        \substr(\hash('sha256', $domain), 0, 32) . '.json',
                        $leaf,
                    )
                    || isset($facts[$domain])
                ) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone identity is inconsistent.',
                    );
                }
                $fact = $this->readDisabledUnlocked($domain);
                if ($fact === null) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone disappeared during enumeration.',
                    );
                }
                \clearstatcache(true, $path);
                $afterStatus = @\lstat($path);
                if (!\is_array($afterStatus)) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone changed during enumeration.',
                    );
                }
                $after = $this->assertAtomicRecoveryFile(
                    $path,
                    $afterStatus,
                    'selector target',
                );
                if (!$this->sameAtomicRecoveryState($before, $after)) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone changed during enumeration.',
                    );
                }
                $validatedTargets[$leaf] = $after;
                $facts[$domain] = $fact;
            }
        } finally {
            @\closedir($handle);
        }
        $this->reclaimSelectorAtomicCrashArtifacts(
            $recoveries,
            $validatedTargets,
            'disabled',
        );
        \ksort($facts, SORT_STRING);
        return $facts;
    }

    /**
     * Return only new-format pending retirement intents. Historical disabled
     * tombstones deliberately have no embedded intent and are never replayed.
     *
     * @return array<string,array<string,mixed>> domain => pending intent
     */
    public function pendingRetirementIntents(?float $deadlineMonotonic = null): array
    {
        $pending = [];
        foreach ($this->disabledCertificates($deadlineMonotonic) as $domain => $_disabled) {
            $intent = $this->retirementIntent(
                (string)$domain,
                $deadlineMonotonic,
            );
            if (\is_array($intent)
                && \hash_equals('pending', (string)($intent['state'] ?? ''))
            ) {
                $pending[(string)$domain] = $intent;
            }
        }
        \ksort($pending, SORT_STRING);
        return $pending;
    }

    /**
     * Return one bounded, rotating replay batch. The cursor is project-global,
     * so several WLS instances cannot permanently starve later domains behind
     * one repeatedly failing retirement.
     *
     * @return array<string,array<string,mixed>>
     */
    public function pendingRetirementBatch(
        int $limit = 8,
        ?float $deadlineMonotonic = null,
    ): array
    {
        if ($limit < 1 || $limit > 64) {
            throw new \InvalidArgumentException(
                'Certificate retirement replay batch must be within [1, 64].',
            );
        }
        $pending = $this->pendingRetirementIntents($deadlineMonotonic);
        if ($pending === []) {
            return [];
        }
        $cursor = $this->readRetirementReplayCursor();
        $cursorDomain = (string)($cursor['domain'] ?? '');
        $after = [];
        $before = [];
        foreach ($pending as $domain => $intent) {
            if ($cursorDomain !== '' && \strcmp((string)$domain, $cursorDomain) <= 0) {
                $before[(string)$domain] = $intent;
            } else {
                $after[(string)$domain] = $intent;
            }
        }
        return \array_slice($after + $before, 0, $limit, true);
    }

    public function advanceRetirementReplayCursor(
        array $intent,
        ?float $deadlineMonotonic = null,
    ): void
    {
        $domain = $this->normalizeDomain((string)($intent['domain'] ?? ''));
        $intentId = \strtolower(\trim((string)($intent['intent_id'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $intentId) !== 1) {
            throw new \RuntimeException('Certificate retirement replay cursor identity is invalid.');
        }
        $this->ensureStoreDirectories();
        $this->withRetirementStateLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'retirement-cursor.lock',
            function () use ($domain, $intentId): void {
                $updatedAt = \gmdate(DATE_ATOM);
                $identity = [
                    'schema' => self::RETIREMENT_CURSOR_SCHEMA,
                    'domain' => $domain,
                    'intent_id' => $intentId,
                    'updated_at' => $updatedAt,
                ];
                $payload = $identity + [
                    'digest' => \hash(
                        'sha256',
                        GatewayClient::canonicalJson($identity),
                    ),
                ];
                $this->discardRebuildableAtomicCrashArtifacts(
                    $this->retirementReplayCursorFile(),
                    'certificate retirement replay cursor recovery artifact',
                );
                GatewayProjectStateFilesystem::atomicWrite(
                    $this->retirementReplayCursorFile(),
                    GatewayClient::canonicalJson($payload) . "\n",
                    0600,
                    fn ($handle, string $path): mixed => $this
                        ->preserveProjectArtifactOwnership($path, $handle),
                );
            },
            $deadlineMonotonic,
            // Replay already owns the project-global lease, so cursor lock
            // contention is exceptional and must not overrun the caller's
            // bounded retirement budget.
            0.05,
        );
    }

    /**
     * Serialize replay across every project instance while keeping the lock
     * wait outside the caller's total work budget.
     */
    public function withRetirementReplayLease(callable $callback): mixed
    {
        $this->ensureStoreDirectories();
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'retirement-replay.lock',
            fn (): mixed => $callback(),
            fn ($handle, string $path): mixed => $this->preserveProjectArtifactOwnership(
                $path,
                $handle,
            ),
            waitTimeoutSeconds: 0.25,
        );
    }

    /**
     * Persist the fail-closed tombstone/outbox before the certificate row is
     * changed. Repeated calls for the same operation return the exact intent.
     *
     * @return array<string,mixed>
     */
    public function prepareCertificateRetirement(
        string $domain,
        string $operation,
        int $certificateId,
        string $reason,
        string $expectedRowDigest,
        ?float $deadlineMonotonic = null,
    ): array {
        $domain = $this->normalizeDomain($domain);
        $operation = \strtolower(\trim($operation));
        $expectedRowDigest = \strtolower(\trim($expectedRowDigest));
        $reason = $this->normalizeRetirementReason($reason);
        if (!\in_array($operation, [
                self::RETIREMENT_OPERATION_DISABLE,
                self::RETIREMENT_OPERATION_DELETE,
            ], true)
            || $certificateId < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedRowDigest) !== 1
        ) {
            throw new \RuntimeException('Explicit certificate retirement metadata is invalid.');
        }
        return $this->withCertificateLifecycleLock(
            fn (): array => $this->withRetirementStateLock(
                $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
                function () use (
                    $domain,
                    $operation,
                    $certificateId,
                    $reason,
                    $expectedRowDigest,
                    $deadlineMonotonic,
                ): array {
                    $active = $this->readActiveUnlocked($domain, false);
                    $disabled = $this->readDisabledUnlocked($domain);
                    $existing = \is_array($disabled['retirement_intent'] ?? null)
                        ? $disabled['retirement_intent']
                        : null;
                    if (\is_array($existing)
                        && \hash_equals('pending', (string)($existing['state'] ?? ''))
                        && \hash_equals($operation, (string)($existing['operation'] ?? ''))
                        && (int)($existing['certificate_id'] ?? 0) === $certificateId
                        && \hash_equals(
                            $expectedRowDigest,
                            (string)($existing['expected_row_digest'] ?? ''),
                        )
                    ) {
                        return $existing;
                    }
                    if (\is_array($existing)
                        && \hash_equals('pending', (string)($existing['state'] ?? ''))
                    ) {
                        throw new \RuntimeException(
                            'Another certificate retirement is already pending for this domain.',
                        );
                    }
                    $generation = $this->allocateCertificateGeneration(\max(
                        (int)($active['generation'] ?? 0),
                        (int)($disabled['generation'] ?? 0),
                    ));
                    $disabledAt = \gmdate(DATE_ATOM);
                    $sourceDigest = $this->disabledSourceDigest($domain, $generation);
                    $intent = $this->newRetirementIntent(
                        $domain,
                        $generation,
                        $sourceDigest,
                        $disabledAt,
                        self::RETIREMENT_PHASE_PREPARED,
                        $operation,
                        $certificateId,
                        $reason,
                        $expectedRowDigest,
                    );
                    $next = [
                        'schema' => 'wls-project-certificate-disabled/1',
                        'state' => 'disabled',
                        'domain' => $domain,
                        'generation' => $generation,
                        'source_digest' => $sourceDigest,
                        'disabled_at' => $disabledAt,
                        'retirement_intent' => $intent,
                    ];
                    if ($disabled === null) {
                        $this->assertDisabledManifestCapacity();
                    }
                    $this->publishManifest($this->disabledManifestFile($domain), $next);
                    $this->removeReenableIntentUnlocked($domain);
                    $this->removeActiveSelectorUnlocked(
                        $domain,
                        $deadlineMonotonic,
                    );
                    $verified = $this->readDisabledUnlocked($domain);
                    $verifiedIntent = \is_array($verified['retirement_intent'] ?? null)
                        ? $verified['retirement_intent']
                        : null;
                    if (!\is_array($verifiedIntent)
                        || !$this->sameRetirementIdentity($intent, $verifiedIntent)
                        || !\hash_equals(
                            self::RETIREMENT_PHASE_PREPARED,
                            (string)($verifiedIntent['phase'] ?? ''),
                        )
                    ) {
                        throw new \RuntimeException(
                            'Certificate retirement prepare intent was not durable.',
                        );
                    }
                    return $verifiedIntent;
                },
                $deadlineMonotonic,
                10.0,
            ),
            $this->retirementLockWaitTimeout($deadlineMonotonic, 10.0),
        );
    }

    /**
     * Read one embedded retirement intent and durably supersede it before it
     * can be replayed when a newer active generation already exists.
     *
     * @return array<string,mixed>|null
     */
    public function retirementIntent(
        string $domain,
        ?float $deadlineMonotonic = null,
    ): ?array
    {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        return $this->withRetirementStateLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
            function () use ($domain): ?array {
                $disabled = $this->readDisabledUnlocked($domain);
                if (!\is_array($disabled)
                    || !\is_array($disabled['retirement_intent'] ?? null)
                ) {
                    return null;
                }
                $active = $this->readActiveUnlocked($domain, false);
                if (\is_array($active)
                    && (int)$active['generation'] > (int)$disabled['generation']
                ) {
                    $disabled = $this->supersedeRetirementIntentUnlocked(
                        $disabled,
                        $active,
                    );
                }
                return \is_array($disabled['retirement_intent'] ?? null)
                    ? $disabled['retirement_intent']
                    : null;
            },
            $deadlineMonotonic,
        );
    }

    /**
     * Persist the runtime-retired stage only after the edge coordinator
     * supplies a proof bound to both shared-gateway and native-TLS outcomes.
     * Later compatibility, endpoint, source, database and event stages remain
     * pending in the same outbox entry.
     *
     * @param array<string,mixed> $expectedIntent
     * @param array<string,mixed> $proof
     */
    public function completeRetirementIntent(
        array $expectedIntent,
        array $proof,
        ?float $deadlineMonotonic = null,
    ): bool {
        $domain = $this->normalizeDomain((string)($expectedIntent['domain'] ?? ''));
        return $this->withCertificateLifecycleLock(
            fn (): bool => $this->withRetirementStateLock(
                $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
                function () use ($domain, $expectedIntent, $proof): bool {
                    $disabled = $this->readDisabledUnlocked($domain);
                    $stored = \is_array($disabled)
                        && \is_array($disabled['retirement_intent'] ?? null)
                        ? $disabled['retirement_intent']
                        : null;
                    if (!\is_array($disabled)
                        || !\is_array($stored)
                        || !$this->sameRetirementIdentity($stored, $expectedIntent)
                    ) {
                        throw new \RuntimeException(
                            'Certificate retirement intent changed before completion.',
                        );
                    }
                    $active = $this->readActiveUnlocked($domain, false);
                    if (\is_array($active)
                        && (int)$active['generation'] > (int)$disabled['generation']
                    ) {
                        $this->supersedeRetirementIntentUnlocked($disabled, $active);
                        return false;
                    }
                    $normalizedProof = $this->normalizeRetirementProof($proof, $stored);
                    $proofDigest = \hash(
                        'sha256',
                        GatewayClient::canonicalJson($normalizedProof),
                    );
                    if (\hash_equals('completed', (string)$stored['state'])) {
                        if (!\hash_equals(
                            $proofDigest,
                            (string)($stored['completion_proof_digest'] ?? ''),
                        )) {
                            throw new \RuntimeException(
                                'Certificate retirement completion proof changed.',
                            );
                        }
                        return true;
                    }
                    if (!\hash_equals('pending', (string)$stored['state'])) {
                        return false;
                    }
                    $phase = (string)($stored['phase'] ?? self::RETIREMENT_PHASE_RUNTIME_PENDING);
                    if ($this->retirementPhaseAtLeast(
                        $phase,
                        self::RETIREMENT_PHASE_RUNTIME_RETIRED,
                    )) {
                        if (!\hash_equals(
                            $proofDigest,
                            (string)($stored['completion_proof_digest'] ?? ''),
                        )) {
                            throw new \RuntimeException(
                                'Certificate retirement runtime proof changed.',
                            );
                        }
                        return true;
                    }
                    if (!\hash_equals(self::RETIREMENT_PHASE_RUNTIME_PENDING, $phase)) {
                        throw new \RuntimeException(
                            'Certificate retirement runtime proof arrived out of phase.',
                        );
                    }
                    $completed = \array_replace($stored, [
                        'phase' => self::RETIREMENT_PHASE_RUNTIME_RETIRED,
                        'phase_updated_at' => \gmdate(DATE_ATOM),
                        'completion_proof_digest' => $proofDigest,
                        'completion_proof' => $normalizedProof,
                    ]);
                    $this->publishDisabledManifest($disabled, $completed);
                    $verified = $this->readDisabledUnlocked($domain);
                    $verifiedIntent = \is_array($verified['retirement_intent'] ?? null)
                        ? $verified['retirement_intent']
                        : [];
                    if (!\hash_equals('pending', (string)($verifiedIntent['state'] ?? ''))
                        || !\hash_equals(
                            self::RETIREMENT_PHASE_RUNTIME_RETIRED,
                            (string)($verifiedIntent['phase'] ?? ''),
                        )
                        || !\hash_equals(
                            $proofDigest,
                            (string)($verifiedIntent['completion_proof_digest'] ?? ''),
                        )
                    ) {
                        throw new \RuntimeException(
                            'Certificate retirement completion was not durable.',
                        );
                    }
                    return true;
                },
                $deadlineMonotonic,
            ),
            $this->retirementLockWaitTimeout($deadlineMonotonic, 10.0),
        );
    }

    /**
     * Advance one exact pending outbox stage with a content-bound receipt.
     *
     * @param array<string,mixed> $expectedIntent
     * @return array<string,mixed>|null
     */
    public function advanceRetirementPhase(
        array $expectedIntent,
        string $expectedPhase,
        string $nextPhase,
        string $receiptDigest,
        ?float $deadlineMonotonic = null,
    ): ?array {
        $domain = $this->normalizeDomain((string)($expectedIntent['domain'] ?? ''));
        $receiptDigest = \strtolower(\trim($receiptDigest));
        if (!isset(self::RETIREMENT_PHASE_ORDER[$expectedPhase])
            || !isset(self::RETIREMENT_PHASE_ORDER[$nextPhase])
            || self::RETIREMENT_PHASE_ORDER[$nextPhase]
                !== self::RETIREMENT_PHASE_ORDER[$expectedPhase] + 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $receiptDigest) !== 1
        ) {
            throw new \RuntimeException('Certificate retirement phase transition is invalid.');
        }
        return $this->withCertificateLifecycleLock(
            fn (): ?array => $this->withRetirementStateLock(
                $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
                function () use (
                    $domain,
                    $expectedIntent,
                    $expectedPhase,
                    $nextPhase,
                    $receiptDigest,
                ): ?array {
                    $disabled = $this->readDisabledUnlocked($domain);
                    $stored = \is_array($disabled)
                        && \is_array($disabled['retirement_intent'] ?? null)
                        ? $disabled['retirement_intent']
                        : null;
                    if (!\is_array($disabled)
                        || !\is_array($stored)
                        || !$this->sameRetirementIdentity($stored, $expectedIntent)
                    ) {
                        throw new \RuntimeException(
                            'Certificate retirement intent changed before phase advancement.',
                        );
                    }
                    $active = $this->readActiveUnlocked($domain, false);
                    if (\is_array($active)
                        && (int)$active['generation'] > (int)$disabled['generation']
                    ) {
                        $this->supersedeRetirementIntentUnlocked($disabled, $active);
                        return null;
                    }
                    if (!\hash_equals('pending', (string)($stored['state'] ?? ''))) {
                        return null;
                    }
                    $currentPhase = (string)($stored['phase'] ?? '');
                    $receipts = \is_array($stored['phase_receipts'] ?? null)
                        ? $stored['phase_receipts']
                        : [];
                    if ($this->retirementPhaseAtLeast($currentPhase, $nextPhase)) {
                        if (isset($receipts[$nextPhase])
                            && !\hash_equals((string)$receipts[$nextPhase], $receiptDigest)
                        ) {
                            throw new \RuntimeException(
                                'Certificate retirement phase receipt changed.',
                            );
                        }
                        return $stored;
                    }
                    if (!\hash_equals($expectedPhase, $currentPhase)) {
                        throw new \RuntimeException(
                            'Certificate retirement phase changed before advancement.',
                        );
                    }
                    $receipts[$nextPhase] = $receiptDigest;
                    $advanced = \array_replace($stored, [
                        'phase' => $nextPhase,
                        'phase_updated_at' => \gmdate(DATE_ATOM),
                        'phase_receipts' => $receipts,
                    ]);
                    $this->publishDisabledManifest($disabled, $advanced);
                    $verified = $this->readDisabledUnlocked($domain);
                    $verifiedIntent = \is_array($verified['retirement_intent'] ?? null)
                        ? $verified['retirement_intent']
                        : [];
                    if (!\hash_equals($nextPhase, (string)($verifiedIntent['phase'] ?? ''))
                        || !\hash_equals(
                            $receiptDigest,
                            (string)($verifiedIntent['phase_receipts'][$nextPhase] ?? ''),
                        )
                    ) {
                        throw new \RuntimeException(
                            'Certificate retirement phase advancement was not durable.',
                        );
                    }
                    return $verifiedIntent;
                },
                $deadlineMonotonic,
            ),
            $this->retirementLockWaitTimeout($deadlineMonotonic, 10.0),
        );
    }

    /** @param array<string,mixed> $expectedIntent */
    public function finishRetirementIntent(
        array $expectedIntent,
        ?float $deadlineMonotonic = null,
    ): bool
    {
        $domain = $this->normalizeDomain((string)($expectedIntent['domain'] ?? ''));
        return $this->withCertificateLifecycleLock(
            fn (): bool => $this->withRetirementStateLock(
                $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
                function () use ($domain, $expectedIntent): bool {
                    $disabled = $this->readDisabledUnlocked($domain);
                    $stored = \is_array($disabled)
                        && \is_array($disabled['retirement_intent'] ?? null)
                        ? $disabled['retirement_intent']
                        : null;
                    if (!\is_array($disabled)
                        || !\is_array($stored)
                        || !$this->sameRetirementIdentity($stored, $expectedIntent)
                    ) {
                        throw new \RuntimeException(
                            'Certificate retirement intent changed before final completion.',
                        );
                    }
                    if (\hash_equals('completed', (string)($stored['state'] ?? ''))) {
                        return true;
                    }
                    if (!\hash_equals('pending', (string)($stored['state'] ?? ''))
                        || !\hash_equals(
                            self::RETIREMENT_PHASE_EVENT_DISPATCHED,
                            (string)($stored['phase'] ?? ''),
                        )
                    ) {
                        throw new \RuntimeException(
                            'Certificate retirement cannot complete before every durable stage.',
                        );
                    }
                    $completed = \array_replace($stored, [
                        'state' => 'completed',
                        'phase' => self::RETIREMENT_PHASE_COMPLETE,
                        'phase_updated_at' => \gmdate(DATE_ATOM),
                        'completed_at' => \gmdate(DATE_ATOM),
                    ]);
                    $this->publishDisabledManifest($disabled, $completed);
                    $verified = $this->readDisabledUnlocked($domain);
                    return \hash_equals(
                        'completed',
                        (string)($verified['retirement_intent']['state'] ?? ''),
                    ) && \hash_equals(
                        self::RETIREMENT_PHASE_COMPLETE,
                        (string)($verified['retirement_intent']['phase'] ?? ''),
                    );
                },
                $deadlineMonotonic,
            ),
            $this->retirementLockWaitTimeout($deadlineMonotonic, 10.0),
        );
    }

    /**
     * Remove only the mutable per-domain selector after the project fact source
     * deletes a certificate. Immutable snapshots remain available to current,
     * authority and recent-LKG serving manifests until ordinary seven-day GC.
     * Historical tombstones remain passive unless an explicit certificate
     * lifecycle transition asks to commit a new retirement intent.
     */
    public function deactivate(
        string $domain,
        bool $ensureRetirementIntent = false,
        ?float $deadlineMonotonic = null,
    ): void
    {
        $this->withCertificateLifecycleLock(
            fn (): mixed => $this->deactivateWithinLifecycleLock(
                $domain,
                $ensureRetirementIntent,
                $deadlineMonotonic,
            ),
            $this->retirementLockWaitTimeout($deadlineMonotonic, 10.0),
        );
    }

    private function deactivateWithinLifecycleLock(
        string $domain,
        bool $ensureRetirementIntent,
        ?float $deadlineMonotonic,
    ): void {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        $this->withRetirementStateLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
            function () use (
                $domain,
                $ensureRetirementIntent,
                $deadlineMonotonic,
            ): void {
                $active = $this->readActiveUnlocked($domain, false);
                $disabled = $this->readDisabledUnlocked($domain);
                $hasRetirementIntent = \is_array(
                    $disabled['retirement_intent'] ?? null,
                );
                if ($active === null
                    && $disabled !== null
                    && (!$ensureRetirementIntent || $hasRetirementIntent)
                ) {
                    $this->removeReenableIntentUnlocked($domain);
                    return;
                }
                if ($active !== null
                    && $disabled !== null
                    && (int)$disabled['generation'] > (int)$active['generation']
                    && (!$ensureRetirementIntent || $hasRetirementIntent)
                ) {
                    $this->removeReenableIntentUnlocked($domain);
                    $this->removeActiveSelectorUnlocked(
                        $domain,
                        $deadlineMonotonic,
                    );
                    return;
                }

                // Allocate and persist the revocation fact before removing the
                // active selector. A crash can temporarily retain the old
                // selector, but can never leave an unversioned or reusable
                // certificate retirement behind.
                $generation = $this->allocateCertificateGeneration(\max(
                    (int)($active['generation'] ?? 0),
                    (int)($disabled['generation'] ?? 0),
                ));
                $disabledAt = \gmdate(DATE_ATOM);
                $sourceDigest = $this->disabledSourceDigest($domain, $generation);
                $next = [
                    'schema' => 'wls-project-certificate-disabled/1',
                    'state' => 'disabled',
                    'domain' => $domain,
                    'generation' => $generation,
                    'source_digest' => $sourceDigest,
                    'disabled_at' => $disabledAt,
                    // The retirement outbox is part of the tombstone payload,
                    // so one atomic rename commits both or neither.
                    'retirement_intent' => $this->newRetirementIntent(
                        $domain,
                        $generation,
                        $sourceDigest,
                        $disabledAt,
                    ),
                ];
                if ($disabled === null) {
                    $this->assertDisabledManifestCapacity();
                }
                $this->publishManifest($this->disabledManifestFile($domain), $next);
                $verified = $this->readDisabledUnlocked($domain);
                if ($verified === null
                    || (int)$verified['generation'] !== $generation
                    || !\hash_equals(
                        (string)$next['source_digest'],
                        (string)$verified['source_digest'],
                    )
                ) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone publication was not durable.',
                    );
                }
                // Any prior re-enable authority is exact to an older tombstone
                // and must not remain as misleading recoverable state.
                $this->removeReenableIntentUnlocked($domain);
                $this->removeActiveSelectorUnlocked(
                    $domain,
                    $deadlineMonotonic,
                );
            },
            $deadlineMonotonic,
        );
    }

    private function removeActiveSelectorUnlocked(
        string $domain,
        ?float $deadlineMonotonic = null,
    ): void {
        $path = $this->activeManifestFile($domain);
        if (@\lstat($path) === false
            && !\file_exists($path)
            && !\is_link($path)
        ) {
            return;
        }
        $active = $this->readActiveUnlocked($domain, false);
        if ($active === null) {
            return;
        }
        $this->preserveCertificateGenerationFloor((int)$active['generation']);
        $this->transitionCertificateSnapshotReferences(
            [],
            $this->activeManifestSnapshotDigestSet($active),
            $deadlineMonotonic,
        );
        GatewayProjectStateFilesystem::removeRegular(
            $path,
            'deactivated project certificate generation',
        );
        GatewayProjectStateFilesystem::syncDirectory(\dirname($path));
    }

    /**
     * @param array<string,mixed>|null $active
     * @return array<string,true>
     */
    private function activeManifestSnapshotDigestSet(?array $active): array
    {
        if ($active === null) {
            return [];
        }
        $references = [];
        $current = \strtolower(\trim((string)($active['source_digest'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $current) !== 1) {
            throw new \RuntimeException(
                'Active certificate snapshot reference is invalid.',
            );
        }
        $references[$current] = true;
        $previous = $active['previous'] ?? null;
        if ($previous !== null) {
            $previousDigest = \strtolower(\trim((string)(
                \is_array($previous) ? ($previous['source_digest'] ?? '') : ''
            )));
            if (!\is_array($previous)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $previousDigest) !== 1
                || \hash_equals($current, $previousDigest)
            ) {
                throw new \RuntimeException(
                    'Previous certificate snapshot reference is invalid.',
                );
            }
            $references[$previousDigest] = true;
        }
        \ksort($references, SORT_STRING);
        return $references;
    }

    /**
     * @return array{
     *   source_digest:string,
     *   cert_pem:string,
     *   key_pem:string,
     *   chain_pem:string,
     *   leaf_fingerprint_sha256:string,
     *   cert_sha256:string,
     *   key_sha256:string,
     *   chain_sha256:string
     * }
     */
    private function validateSourceMaterial(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain,
    ): array {
        $certificatePem = $this->readStableFile($certificate, false);
        $keyPem = $this->readStableFile($privateKey, true);
        $chainPem = $chain === '' ? '' : $this->readStableFile($chain, false);
        return $this->validateMaterial($domain, $certificatePem, $keyPem, $chainPem);
    }

    /**
     * @return array{
     *   source_digest:string,
     *   cert_pem:string,
     *   key_pem:string,
     *   chain_pem:string,
     *   leaf_fingerprint_sha256:string,
     *   cert_sha256:string,
     *   key_sha256:string,
     *   chain_sha256:string
     * }
     */
    private function validateMaterial(
        string $domain,
        string $certificatePem,
        string $keyPem,
        string $chainPem,
        bool $requireCurrentValidity = true,
    ): array {
        if (!\preg_match(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $certificatePem,
            $leafMatch,
        )) {
            throw new \RuntimeException('Certificate source contains no PEM certificate.');
        }
        $leaf = @\openssl_x509_read((string)$leafMatch[0]);
        $private = @\openssl_pkey_get_private($keyPem);
        $public = $leaf !== false ? @\openssl_pkey_get_public($leaf) : false;
        $parsed = $leaf !== false ? @\openssl_x509_parse($leaf, false) : false;
        if ($leaf === false
            || $private === false
            || $public === false
            || !\is_array($parsed)
        ) {
            throw new \RuntimeException('Certificate or private key PEM is invalid.');
        }
        $privateDetails = @\openssl_pkey_get_details($private);
        $publicDetails = @\openssl_pkey_get_details($public);
        if (!\is_array($privateDetails)
            || !\is_array($publicDetails)
            || !\hash_equals(
                (string)($privateDetails['key'] ?? ''),
                (string)($publicDetails['key'] ?? ''),
            )
            || !@\openssl_x509_check_private_key($leaf, $private)
        ) {
            throw new \RuntimeException('Certificate and private key do not match.');
        }
        $keyType = (int)($privateDetails['type'] ?? -1);
        $keyBits = (int)($privateDetails['bits'] ?? 0);
        if (($keyType === OPENSSL_KEYTYPE_RSA && $keyBits < 2048)
            || ($keyType === OPENSSL_KEYTYPE_EC && $keyBits < 256)
            || !\in_array(
                $keyType,
                [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_EC],
                true,
            )
        ) {
            throw new \RuntimeException(
                'Certificate key algorithm or strength is not accepted.'
            );
        }
        $leafFingerprint = \strtolower(\str_replace(
            ':',
            '',
            (string)@\openssl_x509_fingerprint($leaf, 'sha256'),
        ));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $leafFingerprint) !== 1) {
            throw new \RuntimeException('Unable to derive the certificate leaf fingerprint.');
        }
        $now = \time();
        if ($requireCurrentValidity
            && ((int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now
                || (int)($parsed['validTo_time_t'] ?? 0) <= $now)
        ) {
            throw new \RuntimeException('Certificate is not currently valid.');
        }
        if (!$this->certificateCoversDomain($parsed, $domain)) {
            throw new \RuntimeException('Certificate SAN does not cover ' . $domain . '.');
        }
        $canonicalChain = $this->canonicalCertificateChain(
            $certificatePem,
            $chainPem,
            $now,
        );
        $fullchain = (string)$canonicalChain['fullchain_pem'];
        $normalizedChainPem = (string)$canonicalChain['chain_pem'];
        $certHash = \hash('sha256', $fullchain);
        $keyHash = \hash('sha256', $keyPem);
        // The protocol publishes fullchain.pem as the certificate source and
        // therefore sends no separate chain reference. Keep this digest
        // byte-for-byte compatible with the Controller's source fence.
        $sourceDigest = \hash('sha256', $certHash . ':' . $keyHash . ':');
        return [
            'source_digest' => $sourceDigest,
            'cert_pem' => $fullchain,
            'key_pem' => $keyPem,
            'chain_pem' => $normalizedChainPem,
            'leaf_fingerprint_sha256' => $leafFingerprint,
            'cert_sha256' => $certHash,
            'key_sha256' => $keyHash,
            'chain_sha256' => $normalizedChainPem === '' ? '' : \hash(
                'sha256',
                $normalizedChainPem,
            ),
        ];
    }

    private function validateCertificateBundle(string $pem): void
    {
        if (!\preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $pem,
            $matches,
        ) || $matches[0] === []) {
            throw new \RuntimeException('Certificate bundle contains no PEM certificate.');
        }
        foreach ($matches[0] as $certificate) {
            if (@\openssl_x509_read((string)$certificate) === false) {
                throw new \RuntimeException('Certificate bundle contains an invalid certificate.');
            }
        }
    }

    /**
     * Normalize a leaf-first bundle by DER fingerprint. Repeated intermediates
     * from `fullchain.pem` + `chain.pem` are coalesced, while a leaf repeated in
     * the chain is rejected. Every retained issuer must be a currently valid CA
     * authorized for certificate signing and must verify the preceding child.
     *
     * @return array{fullchain_pem:string,chain_pem:string}
     */
    private function canonicalCertificateChain(
        string $certificatePem,
        string $chainPem,
        int $now,
    ): array {
        $bundles = [$certificatePem];
        if ($chainPem !== '') {
            $bundles[] = $chainPem;
        }
        $pemBlocks = [];
        foreach ($bundles as $bundle) {
            if (!\preg_match_all(
                '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
                $bundle,
                $matches,
            ) || $matches[0] === []) {
                throw new \RuntimeException('Certificate bundle contains no PEM certificate.');
            }
            foreach ($matches[0] as $block) {
                $pemBlocks[] = (string)$block;
            }
        }
        if ($pemBlocks === [] || \count($pemBlocks) > 16) {
            throw new \RuntimeException('Certificate chain exceeds the bounded certificate count.');
        }

        $certificates = [];
        $seen = [];
        $leafFingerprint = '';
        foreach ($pemBlocks as $index => $block) {
            $certificate = @\openssl_x509_read($block);
            $parsed = $certificate !== false ? @\openssl_x509_parse($certificate, false) : false;
            $fingerprint = $certificate !== false
                ? \strtolower(\str_replace(':', '', (string)@\openssl_x509_fingerprint(
                    $certificate,
                    'sha256',
                )))
                : '';
            $canonicalPem = '';
            if ($certificate === false
                || !\is_array($parsed)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1
                || !@\openssl_x509_export($certificate, $canonicalPem, false)
                || $canonicalPem === ''
            ) {
                throw new \RuntimeException('Certificate bundle contains an invalid certificate.');
            }
            $canonicalPem = \rtrim($canonicalPem) . "\n";
            if ($index === 0) {
                $leafFingerprint = $fingerprint;
            } elseif (\hash_equals($leafFingerprint, $fingerprint)) {
                throw new \RuntimeException(
                    'Certificate chain must not contain the leaf certificate again.'
                );
            }
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            if ($index > 0) {
                $validFrom = (int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX);
                $validTo = (int)($parsed['validTo_time_t'] ?? 0);
                $basicConstraints = (string)(
                    $parsed['extensions']['basicConstraints'] ?? ''
                );
                $keyUsage = $parsed['extensions']['keyUsage'] ?? null;
                if ($validFrom > $now
                    || $validTo <= $now
                    || \preg_match('/(?:^|[,\s])CA\s*:\s*TRUE(?:$|[,\s])/i', $basicConstraints) !== 1
                    || ($keyUsage !== null
                        && \preg_match(
                            '/Certificate Sign|keyCertSign/i',
                            (string)$keyUsage,
                        ) !== 1)
                ) {
                    throw new \RuntimeException(
                        'Certificate chain contains an expired or unauthorized CA certificate.'
                    );
                }
            }
            $certificates[] = [
                'certificate' => $certificate,
                'parsed' => $parsed,
                'pem' => $canonicalPem,
            ];
        }

        for ($index = 0, $last = \count($certificates) - 1; $index < $last; $index++) {
            $child = $certificates[$index];
            $issuer = $certificates[$index + 1];
            $issuerPublicKey = @\openssl_pkey_get_public($issuer['certificate']);
            if ($issuerPublicKey === false
                || !\hash_equals(
                    GatewayClient::canonicalJson((array)($child['parsed']['issuer'] ?? [])),
                    GatewayClient::canonicalJson((array)($issuer['parsed']['subject'] ?? [])),
                )
                || @\openssl_x509_verify($child['certificate'], $issuerPublicKey) !== 1
            ) {
                throw new \RuntimeException(
                    'Certificate chain order or issuer signature is invalid.'
                );
            }
        }
        $leafPem = (string)$certificates[0]['pem'];
        $chain = '';
        foreach (\array_slice($certificates, 1) as $certificate) {
            $chain .= (string)$certificate['pem'];
        }
        return [
            'fullchain_pem' => $leafPem . $chain,
            'chain_pem' => $chain,
        ];
    }

    /**
     * @param array<string,mixed> $parsed
     */
    private function certificateCoversDomain(array $parsed, string $domain): bool
    {
        $san = \trim((string)($parsed['extensions']['subjectAltName'] ?? ''));
        if ($san === '') {
            return false;
        }
        foreach (\explode(',', $san) as $entry) {
            $entry = \trim($entry);
            if (\filter_var($domain, FILTER_VALIDATE_IP) !== false
                && \str_starts_with(\strtoupper($entry), 'IP ADDRESS:')
            ) {
                $candidate = \trim(\substr($entry, \strlen('IP Address:')));
                if (\filter_var($candidate, FILTER_VALIDATE_IP) !== false
                    && \hash_equals(
                        (string)@\inet_pton($domain),
                        (string)@\inet_pton($candidate),
                    )
                ) {
                    return true;
                }
                continue;
            }
            if (!\str_starts_with(\strtoupper($entry), 'DNS:')) {
                continue;
            }
            try {
                $pattern = $this->normalizeDomain(\substr($entry, 4));
            } catch (\Throwable) {
                continue;
            }
            if ($this->domainPatternMatches($pattern, $domain)) {
                return true;
            }
        }
        return false;
    }

    private function domainPatternMatches(string $pattern, string $domain): bool
    {
        if (!\str_starts_with($pattern, '*.')) {
            return \hash_equals($pattern, $domain);
        }
        if (\str_starts_with($domain, '*.')) {
            return \hash_equals($pattern, $domain);
        }
        if (\substr_count($pattern, '.') !== \substr_count($domain, '.')) {
            return false;
        }
        return \str_ends_with($domain, \substr($pattern, 1));
    }

    /**
     * @param array<string,string> $material
     * @return array<string,string>
     */
    private function publishSnapshot(
        array $material,
        ?float $deadlineMonotonic = null,
    ): array {
        $digest = (string)$material['source_digest'];
        $snapshots = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots';
        $target = $snapshots . DIRECTORY_SEPARATOR . $digest;
        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)) {
            if (\is_link($target)
                || ((((int)($targetStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException(
                    'Existing certificate snapshot path is linked or special.',
                );
            }
            $this->inspectSnapshotDirectory($target, $digest);
            return $this->verifySnapshot($target, $material);
        }
        if (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException('Existing certificate snapshot path is unsafe.');
        }
        $this->assertSnapshotStoreCapacity(
            \strlen((string)$material['cert_pem'])
                + \strlen((string)$material['key_pem'])
                + \strlen((string)$material['chain_pem'])
                + 16_384,
            $deadlineMonotonic,
        );
        $temporary = $snapshots . DIRECTORY_SEPARATOR . '.tmp-'
            . \bin2hex(\random_bytes(12));
        if (!@\mkdir($temporary, 0700) || \is_link($temporary)) {
            throw new \RuntimeException('Unable to create certificate snapshot staging directory.');
        }
        // When an administrator performs enrollment, keep the staging
        // directory root-owned and mode 0700 until its complete immutable tree
        // is renamed. Chowning this path early would let the project owner race
        // privileged cleanup with path replacement.
        try {
            $this->atomicWrite(
                $temporary . DIRECTORY_SEPARATOR . 'fullchain.pem',
                (string)$material['cert_pem'],
                0600,
            );
            $this->atomicWrite(
                $temporary . DIRECTORY_SEPARATOR . 'privkey.pem',
                (string)$material['key_pem'],
                0600,
            );
            if ((string)$material['chain_pem'] !== '') {
                $this->atomicWrite(
                    $temporary . DIRECTORY_SEPARATOR . 'chain.pem',
                    (string)$material['chain_pem'],
                    0600,
                );
            }
            $this->publishManifest(
                $temporary . DIRECTORY_SEPARATOR . 'snapshot.json',
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'source_digest' => $digest,
                    'leaf_fingerprint_sha256' => (string)$material['leaf_fingerprint_sha256'],
                    'cert_sha256' => (string)$material['cert_sha256'],
                    'key_sha256' => (string)$material['key_sha256'],
                    'chain_sha256' => (string)$material['chain_sha256'],
                    'created_at' => \gmdate(DATE_ATOM),
                ],
            );
            if (!@\rename($temporary, $target)) {
                if (!\is_dir($target) || \is_link($target)) {
                    throw new \RuntimeException('Unable to publish immutable certificate snapshot.');
                }
                $this->removeDirectory($temporary);
            } else {
                GatewayProjectStateFilesystem::syncDirectory($snapshots);
            }
        } catch (\Throwable $throwable) {
            $this->removeDirectory($temporary);
            throw $throwable;
        }
        return $this->verifySnapshot($target, $material);
    }

    /**
     * Persist a reference transition before the corresponding active or
     * serving pointer mutation. Referenced snapshots clear any retirement
     * marker; every reference being removed receives a fresh, unconditional
     * full-width grace window. Publishing the marker first is crash-safe: a
     * failed pointer mutation can only retain material longer.
     *
     * @param array<int,string>|array<string,true> $referencedDigests
     * @param array<int,string>|array<string,true> $retiringDigests
     */
    public function transitionCertificateSnapshotReferences(
        array $referencedDigests,
        array $retiringDigests = [],
        ?float $deadlineMonotonic = null,
    ): void {
        $this->retirementDeadlineRemaining($deadlineMonotonic);
        $this->ensureStoreDirectories();
        $referenced = $this->normalizeSnapshotDigestSet($referencedDigests);
        $retiring = $this->normalizeSnapshotDigestSet($retiringDigests);
        $retiring = \array_diff_key($retiring, $referenced);
        if ($referenced === [] && $retiring === []) {
            return;
        }
        $this->withSnapshotRetirementLock(
            function () use ($referenced, $retiring): void {
                try {
                    $clock = $this->snapshotRetirementClock();
                    $state = $this->readSnapshotRetirementStateUnlocked();
                    $markers = $this->snapshotRetirementMarkersForClock(
                        $state,
                        $clock,
                    );
                    foreach ($retiring as $digest => $_) {
                        $markers[$digest] = $this->newSnapshotRetirementMarker(
                            $digest,
                            $clock,
                        );
                    }
                    foreach ($referenced as $digest => $_) {
                        unset($markers[$digest]);
                    }
                    \ksort($markers, SORT_STRING);
                    $this->publishSnapshotRetirementStateUnlocked($markers, $clock);
                } catch (\Throwable) {
                    // Losing the whole derived marker set is conservative: the
                    // next GC observation starts a new seven-day window for every
                    // unreferenced snapshot. Never leave a stale marker that could
                    // authorize early deletion after this transition.
                    try {
                        $this->invalidateSnapshotRetirementStateUnlocked();
                    } catch (\Throwable $invalidationFailure) {
                        throw new \RuntimeException(
                            'Certificate snapshot retirement state could not be invalidated safely.',
                            0,
                            $invalidationFailure,
                        );
                    }
                }
            },
            $deadlineMonotonic,
        );
    }

    /**
     * @param array<string,array{digest:string,path:string,bytes:int,mtime:int,cert_sha256:string,key_sha256:string,chain_sha256:string}> $inventory
     * @param array<string,true> $references
     * @return list<string>
     */
    private function collectableSnapshotRetirementDigests(
        array $inventory,
        array $references,
        ?float $deadlineMonotonic = null,
    ): array {
        $this->ensureStoreDirectories();
        if (\count($inventory) > self::MAX_STORED_SNAPSHOTS) {
            throw new \RuntimeException(
                'Certificate snapshot retirement inventory exceeds its bound.',
            );
        }
        foreach ($inventory as $digest => $entry) {
            if (\preg_match('/\A[a-f0-9]{64}\z/D', (string)$digest) !== 1
                || !\is_array($entry)
                || !\hash_equals((string)$digest, (string)($entry['digest'] ?? ''))
            ) {
                throw new \RuntimeException(
                    'Certificate snapshot retirement inventory is malformed.',
                );
            }
        }
        $references = $this->normalizeSnapshotDigestSet($references);
        return $this->withSnapshotRetirementLock(
            function () use ($inventory, $references): array {
                try {
                    $clock = $this->snapshotRetirementClock();
                    $state = $this->readSnapshotRetirementStateUnlocked();
                    $markers = $this->snapshotRetirementMarkersForClock(
                        $state,
                        $clock,
                    );
                    foreach (\array_keys($markers) as $digest) {
                        if (!isset($inventory[$digest]) || isset($references[$digest])) {
                            unset($markers[$digest]);
                        }
                    }

                    $collectable = [];
                    foreach ($inventory as $digest => $_entry) {
                        if (isset($references[$digest])) {
                            continue;
                        }
                        $marker = \is_array($markers[$digest] ?? null)
                            ? $markers[$digest]
                            : null;
                        $age = $marker === null
                            ? null
                            : $this->snapshotRetirementMarkerAge($marker, $digest, $clock);
                        if ($age === null) {
                            // Missing, old-format, damaged, cross-boot, future or
                            // clock-regressed state always rebuilds the complete
                            // grace window. Snapshot directory mtime is never
                            // deletion authority.
                            $markers[$digest] = $this->newSnapshotRetirementMarker(
                                $digest,
                                $clock,
                            );
                            continue;
                        }
                        if ($age >= self::SNAPSHOT_RETENTION_SECONDS) {
                            $collectable[$digest] = (float)(
                                $marker['unreferenced_since_monotonic'] ?? 0.0
                            );
                        }
                    }
                    \ksort($markers, SORT_STRING);
                    $this->publishSnapshotRetirementStateUnlocked($markers, $clock);
                    \uksort(
                        $collectable,
                        static function (string $left, string $right) use ($collectable): int {
                            $order = $collectable[$left] <=> $collectable[$right];
                            return $order !== 0 ? $order : $left <=> $right;
                        },
                    );
                    return \array_keys($collectable);
                } catch (\Throwable) {
                    try {
                        $this->invalidateSnapshotRetirementStateUnlocked();
                    } catch (\Throwable $invalidationFailure) {
                        throw new \RuntimeException(
                            'Certificate snapshot retirement state could not fail closed.',
                            0,
                            $invalidationFailure,
                        );
                    }
                    // No trustworthy current-boot monotonic marker means no
                    // snapshot is collectable. Capacity enforcement below remains
                    // hard and will report exhaustion instead of shortening grace.
                    return [];
                }
            },
            $deadlineMonotonic,
        );
    }

    /** @param list<string> $digests */
    private function forgetSnapshotRetirementDigests(
        array $digests,
        ?float $deadlineMonotonic = null,
    ): void {
        if ($digests === []) {
            return;
        }
        $digests = $this->normalizeSnapshotDigestSet($digests);
        $this->withSnapshotRetirementLock(function () use ($digests): void {
            try {
                $clock = $this->snapshotRetirementClock();
                $state = $this->readSnapshotRetirementStateUnlocked();
                $markers = $this->snapshotRetirementMarkersForClock(
                    $state,
                    $clock,
                );
                foreach ($digests as $digest => $_) {
                    unset($markers[$digest]);
                }
                \ksort($markers, SORT_STRING);
                $this->publishSnapshotRetirementStateUnlocked($markers, $clock);
            } catch (\Throwable) {
                $this->invalidateSnapshotRetirementStateUnlocked();
            }
        }, $deadlineMonotonic);
    }

    /**
     * @param array<int,string>|array<string,true> $digests
     * @return array<string,true>
     */
    private function normalizeSnapshotDigestSet(array $digests): array
    {
        if (\count($digests) > self::MAX_STORED_SNAPSHOTS) {
            throw new \RuntimeException(
                'Certificate snapshot reference set exceeds its bound.',
            );
        }
        $normalized = [];
        $list = \array_is_list($digests);
        foreach ($digests as $key => $value) {
            $digest = $list && \is_string($value)
                ? $value
                : (\is_string($key) && $value === true ? $key : '');
            $digest = \strtolower(\trim($digest));
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                throw new \RuntimeException(
                    'Certificate snapshot reference digest is invalid.',
                );
            }
            $normalized[$digest] = true;
        }
        \ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /**
     * @return array{wall_unix:int,monotonic:float,host_boot_id:string}
     */
    private function snapshotRetirementClock(): array
    {
        $wall = $this->snapshotWallClock !== null
            ? ($this->snapshotWallClock)()
            : \time();
        $monotonic = $this->snapshotMonotonicNow();
        $bootIdentity = $this->snapshotBootIdentityResolver !== null
            ? ($this->snapshotBootIdentityResolver)()
            : GatewayHostBootIdentity::current();
        if (!\is_int($wall)
            || $wall < 1
            || (!\is_int($monotonic) && !\is_float($monotonic))
            || !\is_finite((float)$monotonic)
            || (float)$monotonic < 0.0
            || !\is_string($bootIdentity)
        ) {
            throw new \RuntimeException(
                'Certificate snapshot retirement clock is invalid.',
            );
        }
        return [
            'wall_unix' => $wall,
            'monotonic' => (float)$monotonic,
            'host_boot_id' => GatewayHostBootIdentity::validate($bootIdentity),
        ];
    }

    private function snapshotMonotonicNow(): float
    {
        $monotonic = $this->snapshotMonotonicClock !== null
            ? ($this->snapshotMonotonicClock)()
            : (\hrtime(true) / 1_000_000_000);
        if ((!\is_int($monotonic) && !\is_float($monotonic))
            || !\is_finite((float)$monotonic)
            || (float)$monotonic < 0.0
        ) {
            throw new \RuntimeException(
                'Certificate snapshot retirement monotonic clock is invalid.',
            );
        }
        return (float)$monotonic;
    }

    /**
     * @param array{wall_unix:int,monotonic:float,host_boot_id:string} $clock
     * @return array<string,mixed>
     */
    private function newSnapshotRetirementMarker(string $digest, array $clock): array
    {
        return [
            'schema' => self::SNAPSHOT_RETIREMENT_MARKER_SCHEMA,
            'snapshot_digest' => $digest,
            'host_boot_id' => $clock['host_boot_id'],
            'unreferenced_since_unix' => $clock['wall_unix'],
            'unreferenced_since_monotonic' => $clock['monotonic'],
        ];
    }

    /**
     * @param array<string,mixed> $marker
     * @param array{wall_unix:int,monotonic:float,host_boot_id:string} $clock
     */
    private function snapshotRetirementMarkerAge(
        array $marker,
        string $digest,
        array $clock,
    ): ?float {
        $sinceWall = $marker['unreferenced_since_unix'] ?? null;
        $sinceMonotonic = $marker['unreferenced_since_monotonic'] ?? null;
        if (!\hash_equals(
            self::SNAPSHOT_RETIREMENT_MARKER_SCHEMA,
            (string)($marker['schema'] ?? ''),
        )
            || !\hash_equals($digest, (string)($marker['snapshot_digest'] ?? ''))
            || !\hash_equals(
                $clock['host_boot_id'],
                (string)($marker['host_boot_id'] ?? ''),
            )
            || !\is_int($sinceWall)
            || $sinceWall < 1
            || $sinceWall > $clock['wall_unix']
            || (!\is_int($sinceMonotonic) && !\is_float($sinceMonotonic))
            || !\is_finite((float)$sinceMonotonic)
            || (float)$sinceMonotonic < 0.0
            || (float)$sinceMonotonic > $clock['monotonic']
        ) {
            return null;
        }
        $age = $clock['monotonic'] - (float)$sinceMonotonic;
        return \is_finite($age) && $age >= 0.0 ? $age : null;
    }

    /**
     * @param array<string,mixed> $state
     * @param array{wall_unix:int,monotonic:float,host_boot_id:string} $clock
     * @return array<string,array<string,mixed>>
     */
    private function snapshotRetirementMarkersForClock(
        array $state,
        array $clock,
    ): array {
        $updatedWall = $state['updated_unix'] ?? null;
        $updatedMonotonic = $state['updated_monotonic'] ?? null;
        if (!\is_int($updatedWall)
            || $updatedWall < 1
            || $updatedWall > $clock['wall_unix']
            || (!\is_int($updatedMonotonic) && !\is_float($updatedMonotonic))
            || !\is_finite((float)$updatedMonotonic)
            || (float)$updatedMonotonic < 0.0
            || (float)$updatedMonotonic > $clock['monotonic']
            || !\hash_equals(
                $clock['host_boot_id'],
                (string)($state['host_boot_id'] ?? ''),
            )
            || !\is_array($state['markers'] ?? null)
        ) {
            // Missing/legacy metadata, a host reboot, a monotonic regression,
            // or a wall-clock rollback all restart every unreferenced grace
            // window. No persisted wall duration is ever trusted across boot.
            return [];
        }
        return $state['markers'];
    }

    /**
     * @return array{
     *   schema:string,
     *   markers:array<string,array<string,mixed>>,
     *   updated_unix?:int,
     *   updated_monotonic?:float,
     *   host_boot_id?:string
     * }
     */
    private function readSnapshotRetirementStateUnlocked(): array
    {
        $path = $this->snapshotRetirementStateFile();
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'Certificate snapshot retirement state path is unsafe.',
                );
            }
            return ['schema' => self::SNAPSHOT_RETIREMENT_SCHEMA, 'markers' => []];
        }
        if (\is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0600
                    || ($this->projectOwner >= 0
                        && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException(
                'Certificate snapshot retirement state is unsafe.',
            );
        }
        try {
            $state = $this->readManifest($path);
        } catch (\Throwable) {
            return ['schema' => self::SNAPSHOT_RETIREMENT_SCHEMA, 'markers' => []];
        }
        $markers = $state['markers'] ?? null;
        if (!\hash_equals(
            self::SNAPSHOT_RETIREMENT_SCHEMA,
            (string)($state['schema'] ?? ''),
        )
            || !\is_array($markers)
            || ($markers !== [] && \array_is_list($markers))
            || \count($markers) > self::MAX_STORED_SNAPSHOTS
            || !\is_int($state['updated_unix'] ?? null)
            || (int)$state['updated_unix'] < 1
            || (!\is_int($state['updated_monotonic'] ?? null)
                && !\is_float($state['updated_monotonic'] ?? null))
            || !\is_finite((float)$state['updated_monotonic'])
            || (float)$state['updated_monotonic'] < 0.0
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($state['host_boot_id'] ?? ''),
            ) !== 1
        ) {
            return ['schema' => self::SNAPSHOT_RETIREMENT_SCHEMA, 'markers' => []];
        }
        $stateWall = (int)$state['updated_unix'];
        $stateMonotonic = (float)$state['updated_monotonic'];
        $stateBootIdentity = (string)$state['host_boot_id'];
        $normalized = [];
        foreach ($markers as $digest => $marker) {
            if (!\is_string($digest)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || !\is_array($marker)
                || !\hash_equals($digest, (string)($marker['snapshot_digest'] ?? ''))
                || !\hash_equals(
                    self::SNAPSHOT_RETIREMENT_MARKER_SCHEMA,
                    (string)($marker['schema'] ?? ''),
                )
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)($marker['host_boot_id'] ?? ''),
                ) !== 1
                || !\hash_equals(
                    $stateBootIdentity,
                    (string)($marker['host_boot_id'] ?? ''),
                )
                || !\is_int($marker['unreferenced_since_unix'] ?? null)
                || (int)$marker['unreferenced_since_unix'] < 1
                || (int)$marker['unreferenced_since_unix'] > $stateWall
                || (!\is_int($marker['unreferenced_since_monotonic'] ?? null)
                    && !\is_float($marker['unreferenced_since_monotonic'] ?? null))
                || !\is_finite((float)$marker['unreferenced_since_monotonic'])
                || (float)$marker['unreferenced_since_monotonic'] < 0.0
                || (float)$marker['unreferenced_since_monotonic'] > $stateMonotonic
            ) {
                return ['schema' => self::SNAPSHOT_RETIREMENT_SCHEMA, 'markers' => []];
            }
            $normalized[$digest] = $marker;
        }
        \ksort($normalized, SORT_STRING);
        return [
            'schema' => self::SNAPSHOT_RETIREMENT_SCHEMA,
            'markers' => $normalized,
            'updated_unix' => $stateWall,
            'updated_monotonic' => $stateMonotonic,
            'host_boot_id' => $stateBootIdentity,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $markers
     * @param array{wall_unix:int,monotonic:float,host_boot_id:string} $clock
     */
    private function publishSnapshotRetirementStateUnlocked(
        array $markers,
        array $clock,
    ): void {
        if (\count($markers) > self::MAX_STORED_SNAPSHOTS) {
            throw new \RuntimeException(
                'Certificate snapshot retirement marker set exceeds its bound.',
            );
        }
        $this->discardRebuildableAtomicCrashArtifacts(
            $this->snapshotRetirementStateFile(),
            'certificate snapshot retirement recovery artifact',
        );
        $this->publishManifest($this->snapshotRetirementStateFile(), [
            'schema' => self::SNAPSHOT_RETIREMENT_SCHEMA,
            'markers' => $markers,
            'updated_at' => \gmdate(DATE_ATOM, $clock['wall_unix']),
            'updated_unix' => $clock['wall_unix'],
            'updated_monotonic' => $clock['monotonic'],
            'host_boot_id' => $clock['host_boot_id'],
        ]);
    }

    private function invalidateSnapshotRetirementStateUnlocked(): void
    {
        $path = $this->snapshotRetirementStateFile();
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'Certificate snapshot retirement state path is unsafe.',
                );
            }
            return;
        }
        GatewayProjectStateFilesystem::removeRegular(
            $path,
            'certificate snapshot retirement state',
        );
        GatewayProjectStateFilesystem::syncDirectory(\dirname($path));
    }

    private function snapshotRetirementStateFile(): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshot-retirement.json';
    }

    private function withSnapshotRetirementLock(
        \Closure $callback,
        ?float $deadlineMonotonic = null,
    ): mixed {
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshot-retirement.lock',
            function () use ($callback, $deadlineMonotonic): mixed {
                $this->retirementDeadlineRemaining($deadlineMonotonic);
                return $callback();
            },
            fn ($handle, string $path): mixed => $this
                ->preserveProjectArtifactOwnership($path, $handle),
            waitTimeoutSeconds: $this->retirementLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    private function assertSnapshotStoreCapacity(
        int $prospectiveBytes,
        ?float $deadlineMonotonic = null,
    ): void {
        if ($prospectiveBytes < 1
            || $prospectiveBytes > (self::MAX_MATERIAL_BYTES * 3) + 16_384
        ) {
            throw new \RuntimeException('Certificate snapshot size is outside its quota.');
        }
        $inventory = $this->storedSnapshotInventory();
        $bytes = \array_sum(\array_column($inventory, 'bytes'));
        $activeReferences = $this->activeSnapshotReferences($inventory);
        $servingStore = new ProjectServingManifestStore($this->projectRoot);
        $servingStore->withCertificateSnapshotReferences(
            function (array $servingReferences) use (
                $inventory,
                $activeReferences,
                $bytes,
                $prospectiveBytes,
                $deadlineMonotonic,
            ): void {
                $references = $activeReferences + $servingReferences;
                foreach ($references as $digest => $_) {
                    if (!isset($inventory[$digest])) {
                        throw new \RuntimeException(
                            'Certificate snapshot reference targets a missing immutable generation.',
                        );
                    }
                }
                $remainingCount = \count($inventory);
                $remainingBytes = $bytes;
                $collectable = $this->collectableSnapshotRetirementDigests(
                    $inventory,
                    $references,
                    $deadlineMonotonic,
                );
                $removedDigests = [];
                foreach ($collectable as $digest) {
                    $this->retirementDeadlineRemaining($deadlineMonotonic);
                    $entry = $inventory[$digest];
                    $this->removeSnapshotDirectory(
                        (string)$entry['path'],
                        (string)$entry['digest'],
                    );
                    $removedDigests[] = $digest;
                    $remainingCount--;
                    $remainingBytes -= (int)$entry['bytes'];
                }
                $this->retirementDeadlineRemaining($deadlineMonotonic);
                $this->forgetSnapshotRetirementDigests(
                    $removedDigests,
                    $deadlineMonotonic,
                );
                if ($remainingCount >= self::MAX_STORED_SNAPSHOTS
                    || $remainingBytes + $prospectiveBytes
                        > self::MAX_STORED_SNAPSHOT_BYTES
                ) {
                    throw new \RuntimeException(
                        'Certificate snapshot store has no capacity for another generation.',
                    );
                }
            },
            $deadlineMonotonic,
        );
    }

    /**
     * @return array<string,array{digest:string,path:string,bytes:int,mtime:int,cert_sha256:string,key_sha256:string,chain_sha256:string}>
     */
    private function storedSnapshotInventory(): array
    {
        $root = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots';
        $rootStatus = @\lstat($root);
        $canonical = \realpath($root);
        if (!\is_array($rootStatus)
            || \is_link($root)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || !\is_string($canonical)
            || !$this->samePath($root, $canonical)
            || !$this->pathInside($canonical, $this->storeRoot)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$rootStatus['mode']) & 0777) !== 0700
                    || ($this->projectOwner >= 0
                        && (int)($rootStatus['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException('Certificate snapshot root is unsafe.');
        }
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate certificate snapshots.');
        }
        $inventory = [];
        $rawEntries = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawEntries > self::MAX_SNAPSHOT_ROOT_ENTRIES) {
                    throw new \RuntimeException(
                        'Certificate snapshot root exceeds its bounded entry count.',
                    );
                }
                $path = $root . DIRECTORY_SEPARATOR . $leaf;
                if (\preg_match('/\A\.tmp-[a-f0-9]{24}\z/D', $leaf) === 1) {
                    // activation.lock excludes a live publisher here. Any
                    // exact staging tree therefore belongs to an interrupted
                    // publication and is removed with the bounded no-follow
                    // cleanup path before quota accounting.
                    $this->removeDirectory($path);
                    continue;
                }
                if (\preg_match('/\A[a-f0-9]{64}\z/D', $leaf) !== 1
                    || isset($inventory[$leaf])
                ) {
                    throw new \RuntimeException(
                        'Certificate snapshot root contains an invalid entry.',
                    );
                }
                $inventory[$leaf] = $this->inspectSnapshotDirectory($path, $leaf);
            }
        } finally {
            @\closedir($handle);
        }
        return $inventory;
    }

    /**
     * @return array{digest:string,path:string,bytes:int,mtime:int,cert_sha256:string,key_sha256:string,chain_sha256:string}
     */
    private function inspectSnapshotDirectory(string $directory, string $digest): array
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals($digest, \basename($directory))
            || !$this->pathInside(
                $directory,
                $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots',
            )
        ) {
            throw new \RuntimeException('Certificate snapshot identity is invalid.');
        }
        $records = GatewayBoundedTreeWalker::collect($directory, true, false);
        if (\count($records) < 4 || \count($records) > 5) {
            throw new \RuntimeException('Certificate snapshot file set is invalid.');
        }
        $allowed = [
            'fullchain.pem' => true,
            'privkey.pem' => true,
            'chain.pem' => true,
            'snapshot.json' => true,
        ];
        $seen = [];
        $bytes = 0;
        $mtime = 0;
        foreach ($records as $record) {
            $status = GatewayBoundedTreeWalker::revalidate($record);
            if ((int)$record['depth'] === 0) {
                if (!$record['directory']
                    || !$this->samePath((string)$record['path'], $directory)
                    || (\PHP_OS_FAMILY !== 'Windows'
                        && ((((int)$status['mode']) & 0777) !== 0700
                            || ($this->projectOwner >= 0
                                && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
                ) {
                    throw new \RuntimeException(
                        'Certificate snapshot directory owner or mode is unsafe.',
                    );
                }
                $mtime = (int)($status['mtime'] ?? 0);
                continue;
            }
            $leaf = \basename((string)$record['path']);
            if ((int)$record['depth'] !== 1
                || $record['directory']
                || !isset($allowed[$leaf])
                || isset($seen[$leaf])
                || (int)($status['size'] ?? -1) < 1
                || (int)$status['size'] > self::MAX_MATERIAL_BYTES
                || (\PHP_OS_FAMILY !== 'Windows'
                    && ((((int)$status['mode']) & 0777) !== 0600
                        || ($this->projectOwner >= 0
                            && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
            ) {
                throw new \RuntimeException(
                    'Certificate snapshot contains an unsafe or unexpected file.',
                );
            }
            $seen[$leaf] = true;
            $bytes += (int)$status['size'];
        }
        foreach (['fullchain.pem', 'privkey.pem', 'snapshot.json'] as $required) {
            if (!isset($seen[$required])) {
                throw new \RuntimeException('Certificate snapshot is incomplete.');
            }
        }
        $manifest = $this->readManifest(
            $directory . DIRECTORY_SEPARATOR . 'snapshot.json',
        );
        $cert = $this->readStableFile(
            $directory . DIRECTORY_SEPARATOR . 'fullchain.pem',
            false,
        );
        $key = $this->readStableFile(
            $directory . DIRECTORY_SEPARATOR . 'privkey.pem',
            true,
        );
        $chain = isset($seen['chain.pem'])
            ? $this->readStableFile($directory . DIRECTORY_SEPARATOR . 'chain.pem', false)
            : '';
        $certHash = \hash('sha256', $cert);
        $keyHash = \hash('sha256', $key);
        $chainHash = $chain === '' ? '' : \hash('sha256', $chain);
        if ((int)($manifest['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals($digest, (string)($manifest['source_digest'] ?? ''))
            || !\hash_equals($certHash, (string)($manifest['cert_sha256'] ?? ''))
            || !\hash_equals($keyHash, (string)($manifest['key_sha256'] ?? ''))
            || !\hash_equals($chainHash, (string)($manifest['chain_sha256'] ?? ''))
            || !\hash_equals($digest, \hash('sha256', $certHash . ':' . $keyHash . ':'))
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $manifest['leaf_fingerprint_sha256'] ?? ''
            )) !== 1
        ) {
            throw new \RuntimeException(
                'Certificate snapshot manifest integrity validation failed.',
            );
        }
        return [
            'digest' => $digest,
            'path' => $directory,
            'bytes' => $bytes,
            'mtime' => $mtime,
            'cert_sha256' => $certHash,
            'key_sha256' => $keyHash,
            'chain_sha256' => $chainHash,
        ];
    }

    /**
     * @param array<string,array{digest:string,path:string,bytes:int,mtime:int,cert_sha256:string,key_sha256:string,chain_sha256:string}> $inventory
     * @return array<string,true>
     */
    private function activeSnapshotReferences(array $inventory): array
    {
        $activeRoot = $this->storeRoot . DIRECTORY_SEPARATOR . 'active';
        $rootStatus = @\lstat($activeRoot);
        $canonical = \realpath($activeRoot);
        if (!\is_array($rootStatus)
            || \is_link($activeRoot)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || !\is_string($canonical)
            || !$this->samePath($activeRoot, $canonical)
            || !$this->pathInside($canonical, $this->storeRoot)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$rootStatus['mode']) & 0777) !== 0700
                    || ($this->projectOwner >= 0
                        && (int)($rootStatus['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException('Active certificate generation root is unsafe.');
        }
        $handle = @\opendir($activeRoot);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate active certificate generations.');
        }
        $references = [];
        $recoveries = [];
        $validatedTargets = [];
        $count = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$count > self::MAX_ACTIVE_MANIFESTS) {
                    throw new \RuntimeException(
                        'Active certificate generation manifest set is invalid.',
                    );
                }
                $recovery = $this->selectorAtomicCrashArtifact(
                    $activeRoot,
                    $leaf,
                    'active',
                );
                if ($recovery !== null) {
                    $recoveries[] = $recovery;
                    continue;
                }
                if (\preg_match('/\A[a-f0-9]{32}\.json\z/D', $leaf) !== 1) {
                    throw new \RuntimeException(
                        'Active certificate generation manifest set is invalid.',
                    );
                }
                $path = $activeRoot . DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($path);
                if (!\is_array($status)
                    || \is_link($path)
                    || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
                    || (int)($status['nlink'] ?? 0) !== 1
                    || (\PHP_OS_FAMILY !== 'Windows'
                        && ((((int)$status['mode']) & 0777) !== 0600
                            || ($this->projectOwner >= 0
                                && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
                ) {
                    throw new \RuntimeException(
                        'Active certificate generation manifest is unsafe.',
                    );
                }
                $before = $this->assertAtomicRecoveryFile(
                    $path,
                    $status,
                    'selector target',
                );
                $manifest = $this->readManifest($path);
                $domain = $this->normalizeDomain((string)($manifest['domain'] ?? ''));
                $current = \strtolower(\trim((string)(
                    $manifest['source_digest'] ?? ''
                )));
                if (!\hash_equals(
                    \substr(\hash('sha256', $domain), 0, 32) . '.json',
                    $leaf,
                )
                    || (int)($manifest['generation'] ?? 0) < 1
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $current) !== 1
                ) {
                    throw new \RuntimeException(
                        'Active certificate generation reference is corrupt.',
                    );
                }
                $snapshot = $inventory[$current] ?? null;
                if (!\is_array($snapshot)
                    || !\hash_equals(
                        (string)$snapshot['cert_sha256'],
                        (string)($manifest['cert_sha256'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$snapshot['key_sha256'],
                        (string)($manifest['key_sha256'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$snapshot['chain_sha256'],
                        (string)($manifest['chain_sha256'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'Active certificate generation does not match its snapshot.',
                    );
                }
                $references[$current] = true;
                $previous = $manifest['previous'] ?? null;
                if ($previous !== null) {
                    $previousDigest = \strtolower(\trim((string)(
                        \is_array($previous) ? ($previous['source_digest'] ?? '') : ''
                    )));
                    if (!\is_array($previous)
                        || (int)($previous['generation'] ?? 0) < 1
                        || (int)$previous['generation'] >= (int)$manifest['generation']
                        || \preg_match('/\A[a-f0-9]{64}\z/D', $previousDigest) !== 1
                        || \hash_equals($current, $previousDigest)
                    ) {
                        throw new \RuntimeException(
                            'Previous certificate generation reference is corrupt.',
                        );
                    }
                    $references[$previousDigest] = true;
                }
                \clearstatcache(true, $path);
                $afterStatus = @\lstat($path);
                if (!\is_array($afterStatus)) {
                    throw new \RuntimeException(
                        'Active certificate generation manifest changed during enumeration.',
                    );
                }
                $after = $this->assertAtomicRecoveryFile(
                    $path,
                    $afterStatus,
                    'selector target',
                );
                if (!$this->sameAtomicRecoveryState($before, $after)) {
                    throw new \RuntimeException(
                        'Active certificate generation manifest changed during enumeration.',
                    );
                }
                $validatedTargets[$leaf] = $after;
            }
        } finally {
            @\closedir($handle);
        }
        foreach ($references as $digest => $_) {
            if (!isset($inventory[$digest])) {
                throw new \RuntimeException(
                    'Active certificate generation references a missing snapshot.',
                );
            }
        }
        $this->reclaimSelectorAtomicCrashArtifacts(
            $recoveries,
            $validatedTargets,
            'active',
        );
        return $references;
    }

    private function removeSnapshotDirectory(string $directory, string $digest): void
    {
        $this->inspectSnapshotDirectory($directory, $digest);
        $records = GatewayBoundedTreeWalker::collect($directory, true, true);
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record);
        }
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record);
            $removed = $record['directory']
                ? @\rmdir((string)$record['path'])
                : @\unlink((string)$record['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove an expired unreferenced certificate snapshot.',
                );
            }
        }
        GatewayProjectStateFilesystem::syncDirectory(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots',
        );
    }

    /**
     * @param array<string,string> $material
     * @return array<string,string>
     */
    private function verifySnapshot(string $directory, array $material): array
    {
        $cert = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $key = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        $chain = $directory . DIRECTORY_SEPARATOR . 'chain.pem';
        $this->preserveProjectArtifactOwnership($directory);
        $this->preserveProjectArtifactOwnership($cert);
        $this->preserveProjectArtifactOwnership($key);
        if (\is_file($chain)) {
            $this->preserveProjectArtifactOwnership($chain);
        }
        $certHash = $this->safeHashFile($cert);
        $keyHash = $this->safeHashFile($key);
        $chainHash = \is_file($chain) && !\is_link($chain) ? $this->safeHashFile($chain) : '';
        if (!\hash_equals((string)$material['cert_sha256'], $certHash)
            || !\hash_equals((string)$material['key_sha256'], $keyHash)
            || !\hash_equals((string)$material['chain_sha256'], $chainHash)
        ) {
            throw new \RuntimeException('Existing certificate snapshot failed content verification.');
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $mode = @\fileperms($key);
            if (!\is_int($mode) || ($mode & 0077) !== 0) {
                throw new \RuntimeException('Certificate snapshot private key permissions are unsafe.');
            }
        }
        return [
            'source_digest' => (string)$material['source_digest'],
            'leaf_fingerprint_sha256' => (string)$material['leaf_fingerprint_sha256'],
            'cert_path' => $cert,
            'key_path' => $key,
            'chain_path' => $chainHash === '' ? '' : $chain,
            'cert_sha256' => $certHash,
            'key_sha256' => $keyHash,
            'chain_sha256' => $chainHash,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readActiveUnlocked(
        string $domain,
        bool $requireCurrentValidity = true,
    ): ?array
    {
        $file = $this->activeManifestFile($domain);
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        $this->preserveProjectArtifactOwnership($file);
        $manifest = $this->readManifest($file);
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !\hash_equals($domain, (string)($manifest['domain'] ?? ''))
            || !\is_int($manifest['generation'] ?? null)
            || (int)$manifest['generation'] < 1
            || !\is_string($manifest['source_digest'] ?? null)
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($manifest['source_digest'] ?? ''),
            ) !== 1
        ) {
            throw new \RuntimeException('Active certificate generation manifest is invalid.');
        }
        $directory = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots'
            . DIRECTORY_SEPARATOR . (string)$manifest['source_digest'];
        // Snapshot locations are derived from the content digest under the
        // current project root. Persisted absolute paths describe the host
        // that activated the generation and must not make a copied project
        // unusable or authorize reads outside the migrated project.
        $certPath = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        $chainPath = (string)($manifest['chain_sha256'] ?? '') === ''
            ? ''
            : $directory . DIRECTORY_SEPARATOR . 'chain.pem';
        $cert = $this->readStableFile($certPath, false);
        $key = $this->readStableFile($keyPath, true);
        $chain = $chainPath === '' ? '' : $this->readStableFile($chainPath, false);
        if (!$this->pathInside($certPath, $directory)
            || !$this->pathInside($keyPath, $directory)
            || ($chainPath !== '' && !$this->pathInside($chainPath, $directory))
            || !\hash_equals((string)$manifest['cert_sha256'], \hash('sha256', $cert))
            || !\hash_equals((string)$manifest['key_sha256'], \hash('sha256', $key))
            || !\hash_equals(
                (string)($manifest['chain_sha256'] ?? ''),
                $chain === '' ? '' : \hash('sha256', $chain),
            )
        ) {
            throw new \RuntimeException('Active certificate snapshot integrity check failed.');
        }
        $validated = $this->validateMaterial(
            $domain,
            $cert,
            $key,
            '',
            $requireCurrentValidity,
        );
        if (!\hash_equals(
            (string)$manifest['source_digest'],
            (string)$validated['source_digest'],
        )) {
            throw new \RuntimeException('Active certificate source digest is invalid.');
        }
        $manifestFingerprint = \strtolower(\trim(
            (string)($manifest['leaf_fingerprint_sha256'] ?? ''),
        ));
        if ($manifestFingerprint !== ''
            && (\preg_match('/\A[a-f0-9]{64}\z/D', $manifestFingerprint) !== 1
                || !\hash_equals(
                    (string)$validated['leaf_fingerprint_sha256'],
                    $manifestFingerprint,
                ))
        ) {
            throw new \RuntimeException('Active certificate leaf fingerprint is invalid.');
        }
        return \array_replace($manifest, [
            'leaf_fingerprint_sha256' => (string)$validated['leaf_fingerprint_sha256'],
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'chain_path' => $chainPath,
            'retained_previous' => false,
            'activation_error' => '',
        ]);
    }

    /** @return array<string,mixed>|null */
    private function readDisabledUnlocked(string $domain): ?array
    {
        $file = $this->disabledManifestFile($domain);
        $status = @\lstat($file);
        if (!\is_array($status)) {
            if (\file_exists($file) || \is_link($file)) {
                throw new \RuntimeException(
                    'Disabled certificate tombstone is indeterminate.',
                );
            }
            return null;
        }
        if (\is_link($file)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0600
                    || ($this->projectOwner >= 0
                        && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException(
                'Disabled certificate tombstone is unsafe.',
            );
        }
        $this->preserveProjectArtifactOwnership($file);
        $manifest = $this->readManifest($file);
        $rawGeneration = $manifest['generation'] ?? null;
        $generation = \is_int($rawGeneration) ? $rawGeneration : 0;
        $sourceDigest = \strtolower(\trim((string)(
            $manifest['source_digest'] ?? ''
        )));
        $disabledAt = \trim((string)($manifest['disabled_at'] ?? ''));
        if (!\hash_equals(
                'wls-project-certificate-disabled/1',
                (string)($manifest['schema'] ?? ''),
            )
            || !\hash_equals('disabled', (string)($manifest['state'] ?? ''))
            || !\hash_equals($domain, (string)($manifest['domain'] ?? ''))
            || !\is_int($rawGeneration)
            || $generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
            || !\hash_equals(
                $this->disabledSourceDigest($domain, $generation),
                $sourceDigest,
            )
            || $disabledAt === ''
            || \strlen($disabledAt) > 128
            || \strtotime($disabledAt) === false
        ) {
            throw new \RuntimeException(
                'Disabled certificate tombstone integrity validation failed.',
            );
        }
        $result = [
            'state' => 'disabled',
            'domain' => $domain,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
            'disabled_at' => $disabledAt,
        ];
        if (\array_key_exists('retirement_intent', $manifest)) {
            $result['retirement_intent'] = $this->normalizeRetirementIntent(
                $manifest['retirement_intent'],
                $domain,
                $generation,
                $sourceDigest,
            );
        }
        return $result;
    }

    private function disabledSourceDigest(string $domain, int $generation): string
    {
        if ($generation < 1) {
            throw new \RuntimeException(
                'Disabled certificate generation is invalid.',
            );
        }
        return \hash(
            'sha256',
            "wls-disabled-certificate\0" . $domain . "\0" . $generation,
        );
    }

    /** @return array<string,mixed> */
    private function newRetirementIntent(
        string $domain,
        int $generation,
        string $sourceDigest,
        string $createdAt,
        string $phase = self::RETIREMENT_PHASE_RUNTIME_PENDING,
        string $operation = self::RETIREMENT_OPERATION_PROJECTION,
        int $certificateId = 0,
        string $reason = '',
        string $expectedRowDigest = '',
    ): array {
        $reason = $this->normalizeRetirementReason($reason);
        $expectedRowDigest = $expectedRowDigest === ''
            ? \str_repeat('0', 64)
            : \strtolower(\trim($expectedRowDigest));
        if (!isset(self::RETIREMENT_PHASE_ORDER[$phase])
            || !\in_array($operation, [
                self::RETIREMENT_OPERATION_PROJECTION,
                self::RETIREMENT_OPERATION_DISABLE,
                self::RETIREMENT_OPERATION_DELETE,
            ], true)
            || $certificateId < 0
            || ($operation !== self::RETIREMENT_OPERATION_PROJECTION
                && $certificateId < 1)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedRowDigest) !== 1
        ) {
            throw new \RuntimeException('Certificate retirement metadata is invalid.');
        }
        $intentId = $this->retirementIntentId(
            $domain,
            $generation,
            $sourceDigest,
        );
        $eventId = \hash(
            'sha256',
            "wls-certificate-retirement-event\0" . $intentId . "\0" . $operation,
        );
        $metadata = [
            'operation' => $operation,
            'certificate_id' => $certificateId,
            'reason' => $reason,
            'expected_row_digest' => $expectedRowDigest,
            'event_id' => $eventId,
        ];
        return [
            'schema' => 'wls-project-certificate-retirement/1',
            'state' => 'pending',
            'phase' => $phase,
            'intent_id' => $intentId,
            'domain' => $domain,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
            'created_at' => $createdAt,
            ...$metadata,
            'metadata_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($metadata),
            ),
            'phase_receipts' => [],
        ];
    }

    private function retirementIntentId(
        string $domain,
        int $generation,
        string $sourceDigest,
    ): string {
        if ($generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
        ) {
            throw new \RuntimeException('Certificate retirement identity is invalid.');
        }
        return \hash(
            'sha256',
            "wls-certificate-retirement\0" . $domain . "\0"
                . $generation . "\0" . $sourceDigest,
        );
    }

    private function normalizeRetirementReason(string $reason): string
    {
        $reason = \trim(\preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason) ?? '');
        if (\strlen($reason) > 2048) {
            $reason = \substr($reason, 0, 2048);
        }
        return $reason;
    }

    private function retirementPhaseAtLeast(string $phase, string $minimum): bool
    {
        return isset(
            self::RETIREMENT_PHASE_ORDER[$phase],
            self::RETIREMENT_PHASE_ORDER[$minimum],
        ) && self::RETIREMENT_PHASE_ORDER[$phase]
            >= self::RETIREMENT_PHASE_ORDER[$minimum];
    }

    private function retirementReplayCursorFile(): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR
            . 'retirement-replay.cursor.json';
    }

    /** @return array{domain:string,intent_id:string}|array{} */
    private function readRetirementReplayCursor(): array
    {
        $file = $this->retirementReplayCursorFile();
        $status = @\lstat($file);
        if (!\is_array($status)) {
            if (\file_exists($file) || \is_link($file)) {
                return [];
            }
            return [];
        }
        try {
            $decoded = \json_decode(
                GatewayProjectStateFilesystem::read(
                    $file,
                    16_384,
                    'certificate retirement replay cursor',
                ),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (!\is_array($decoded) || \array_is_list($decoded)) {
                return [];
            }
            $domain = $this->normalizeDomain((string)($decoded['domain'] ?? ''));
            $intentId = \strtolower(\trim((string)($decoded['intent_id'] ?? '')));
            $updatedAt = \trim((string)($decoded['updated_at'] ?? ''));
            $identity = [
                'schema' => self::RETIREMENT_CURSOR_SCHEMA,
                'domain' => $domain,
                'intent_id' => $intentId,
                'updated_at' => $updatedAt,
            ];
            if (!\hash_equals(
                    self::RETIREMENT_CURSOR_SCHEMA,
                    (string)($decoded['schema'] ?? ''),
                )
                || \preg_match('/\A[a-f0-9]{64}\z/D', $intentId) !== 1
                || $updatedAt === ''
                || \strlen($updatedAt) > 128
                || \strtotime($updatedAt) === false
                || !\hash_equals(
                    \hash('sha256', GatewayClient::canonicalJson($identity)),
                    \strtolower(\trim((string)($decoded['digest'] ?? ''))),
                )
            ) {
                return [];
            }
            return ['domain' => $domain, 'intent_id' => $intentId];
        } catch (\Throwable) {
            // Cursor loss changes only fairness, never retirement authority.
            // Restart safely from the first pending domain and overwrite the
            // cursor after the next bounded attempt.
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function normalizeRetirementIntent(
        mixed $candidate,
        string $domain,
        int $generation,
        string $sourceDigest,
    ): array {
        if (!\is_array($candidate) || \array_is_list($candidate)) {
            throw new \RuntimeException('Certificate retirement intent is malformed.');
        }
        $state = \strtolower(\trim((string)($candidate['state'] ?? '')));
        $intentId = \strtolower(\trim((string)($candidate['intent_id'] ?? '')));
        $createdAt = \trim((string)($candidate['created_at'] ?? ''));
        $phase = \strtolower(\trim((string)($candidate['phase'] ?? '')));
        if ($phase === '') {
            $phase = $state === 'completed'
                ? self::RETIREMENT_PHASE_COMPLETE
                : self::RETIREMENT_PHASE_RUNTIME_PENDING;
        }
        $operation = \strtolower(\trim((string)(
            $candidate['operation'] ?? self::RETIREMENT_OPERATION_PROJECTION
        )));
        $certificateId = $candidate['certificate_id'] ?? 0;
        $reason = $this->normalizeRetirementReason((string)($candidate['reason'] ?? ''));
        $expectedRowDigest = \strtolower(\trim((string)(
            $candidate['expected_row_digest'] ?? \str_repeat('0', 64)
        )));
        $expectedEventId = \hash(
            'sha256',
            "wls-certificate-retirement-event\0" . $intentId . "\0" . $operation,
        );
        $eventId = \strtolower(\trim((string)(
            $candidate['event_id'] ?? $expectedEventId
        )));
        $metadata = [
            'operation' => $operation,
            'certificate_id' => (int)$certificateId,
            'reason' => $reason,
            'expected_row_digest' => $expectedRowDigest,
            'event_id' => $eventId,
        ];
        $metadataDigest = \strtolower(\trim((string)(
            $candidate['metadata_digest']
                ?? \hash('sha256', GatewayClient::canonicalJson($metadata))
        )));
        if (!\hash_equals(
                'wls-project-certificate-retirement/1',
                (string)($candidate['schema'] ?? ''),
            )
            || !\in_array($state, ['pending', 'completed', 'superseded'], true)
            || !isset(self::RETIREMENT_PHASE_ORDER[$phase])
            || !\in_array($operation, [
                self::RETIREMENT_OPERATION_PROJECTION,
                self::RETIREMENT_OPERATION_DISABLE,
                self::RETIREMENT_OPERATION_DELETE,
            ], true)
            || !\is_int($certificateId)
            || $certificateId < 0
            || ($operation !== self::RETIREMENT_OPERATION_PROJECTION
                && $certificateId < 1)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedRowDigest) !== 1
            || !\hash_equals($expectedEventId, $eventId)
            || !\hash_equals(
                \hash('sha256', GatewayClient::canonicalJson($metadata)),
                $metadataDigest,
            )
            || !\hash_equals($domain, (string)($candidate['domain'] ?? ''))
            || !\is_int($candidate['generation'] ?? null)
            || (int)$candidate['generation'] !== $generation
            || !\hash_equals(
                $sourceDigest,
                \strtolower(\trim((string)($candidate['source_digest'] ?? ''))),
            )
            || !\hash_equals(
                $this->retirementIntentId($domain, $generation, $sourceDigest),
                $intentId,
            )
            || $createdAt === ''
            || \strlen($createdAt) > 128
            || \strtotime($createdAt) === false
        ) {
            throw new \RuntimeException(
                'Certificate retirement intent integrity validation failed.',
            );
        }
        $normalized = [
            'schema' => 'wls-project-certificate-retirement/1',
            'state' => $state,
            'phase' => $phase,
            'intent_id' => $intentId,
            'domain' => $domain,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
            'created_at' => $createdAt,
            ...$metadata,
            'metadata_digest' => $metadataDigest,
        ];
        $phaseUpdatedAt = \trim((string)($candidate['phase_updated_at'] ?? ''));
        if ($phaseUpdatedAt !== '') {
            if (\strlen($phaseUpdatedAt) > 128 || \strtotime($phaseUpdatedAt) === false) {
                throw new \RuntimeException(
                    'Certificate retirement phase timestamp is invalid.',
                );
            }
            $normalized['phase_updated_at'] = $phaseUpdatedAt;
        }
        $phaseReceipts = $candidate['phase_receipts'] ?? [];
        if (!\is_array($phaseReceipts)
            || ($phaseReceipts !== [] && \array_is_list($phaseReceipts))
        ) {
            throw new \RuntimeException('Certificate retirement phase receipts are invalid.');
        }
        $normalizedReceipts = [];
        foreach ($phaseReceipts as $receiptPhase => $receiptDigest) {
            $receiptPhase = \strtolower(\trim((string)$receiptPhase));
            $receiptDigest = \strtolower(\trim((string)$receiptDigest));
            if (!isset(self::RETIREMENT_PHASE_ORDER[$receiptPhase])
                || \preg_match('/\A[a-f0-9]{64}\z/D', $receiptDigest) !== 1
                || self::RETIREMENT_PHASE_ORDER[$receiptPhase]
                    > self::RETIREMENT_PHASE_ORDER[$phase]
            ) {
                throw new \RuntimeException(
                    'Certificate retirement phase receipt integrity validation failed.',
                );
            }
            $normalizedReceipts[$receiptPhase] = $receiptDigest;
        }
        \ksort($normalizedReceipts, SORT_STRING);
        $normalized['phase_receipts'] = $normalizedReceipts;

        $proofFields = [];
        if ($this->retirementPhaseAtLeast(
            $phase,
            self::RETIREMENT_PHASE_RUNTIME_RETIRED,
        )) {
            $proofDigest = \strtolower(\trim((string)(
                $candidate['completion_proof_digest'] ?? ''
            )));
            $proof = $candidate['completion_proof'] ?? null;
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $proofDigest) !== 1
                || !\is_array($proof)
                || \array_is_list($proof)
            ) {
                throw new \RuntimeException(
                    'Certificate retirement runtime proof is invalid.',
                );
            }
            $normalizedProof = $this->normalizeRetirementProof($proof, $normalized);
            if (!\hash_equals(
                $proofDigest,
                \hash('sha256', GatewayClient::canonicalJson($normalizedProof)),
            )) {
                throw new \RuntimeException(
                    'Certificate retirement runtime proof digest is invalid.',
                );
            }
            $proofFields = [
                'completion_proof_digest' => $proofDigest,
                'completion_proof' => $normalizedProof,
            ];
        }
        if ($state === 'pending') {
            if ($phase === self::RETIREMENT_PHASE_COMPLETE) {
                throw new \RuntimeException(
                    'Pending certificate retirement cannot be in the complete phase.',
                );
            }
            return $normalized + $proofFields;
        }
        if ($state === 'completed') {
            $completedAt = \trim((string)($candidate['completed_at'] ?? ''));
            if ($completedAt === ''
                || \strlen($completedAt) > 128
                || \strtotime($completedAt) === false
                || !\hash_equals(self::RETIREMENT_PHASE_COMPLETE, $phase)
            ) {
                throw new \RuntimeException(
                    'Completed certificate retirement intent is invalid.',
                );
            }
            return $normalized + [
                'completed_at' => $completedAt,
            ] + $proofFields;
        }
        $supersededAt = \trim((string)($candidate['superseded_at'] ?? ''));
        $supersededGeneration = $candidate['superseded_by_generation'] ?? null;
        $supersededDigest = \strtolower(\trim((string)(
            $candidate['superseded_by_source_digest'] ?? ''
        )));
        if ($supersededAt === ''
            || \strlen($supersededAt) > 128
            || \strtotime($supersededAt) === false
            || !\is_int($supersededGeneration)
            || $supersededGeneration <= $generation
            || \preg_match('/\A[a-f0-9]{64}\z/D', $supersededDigest) !== 1
        ) {
            throw new \RuntimeException(
                'Superseded certificate retirement intent is invalid.',
            );
        }
        return $normalized + $proofFields + [
            'superseded_at' => $supersededAt,
            'superseded_by_generation' => $supersededGeneration,
            'superseded_by_source_digest' => $supersededDigest,
        ];
    }

    /**
     * @param array<string,mixed> $proof
     * @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
    private function normalizeRetirementProof(array $proof, array $intent): array
    {
        $gateway = \is_array($proof['gateway'] ?? null)
            && !\array_is_list($proof['gateway'])
            ? $proof['gateway']
            : [];
        $native = \is_array($proof['native'] ?? null)
            && !\array_is_list($proof['native'])
            ? $proof['native']
            : [];
        $gatewayStatus = \strtolower(\trim((string)($gateway['status'] ?? '')));
        $nativeStatus = \strtolower(\trim((string)($native['status'] ?? '')));
        $gatewayDigest = \strtolower(\trim((string)(
            $gateway['evidence_digest'] ?? ''
        )));
        $nativeDigest = \strtolower(\trim((string)(
            $native['evidence_digest'] ?? ''
        )));
        $verifiedAt = \trim((string)($proof['verified_at'] ?? ''));
        if (!\hash_equals(
                'wls-certificate-retirement-proof/1',
                (string)($proof['schema'] ?? ''),
            )
            || !$this->sameRetirementIdentity($intent, $proof)
            || !\in_array($gatewayStatus, ['retired', 'not_observed'], true)
            || !\in_array($nativeStatus, ['retired', 'not_observed'], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $gatewayDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $nativeDigest) !== 1
            || $verifiedAt === ''
            || \strlen($verifiedAt) > 128
            || \strtotime($verifiedAt) === false
        ) {
            throw new \RuntimeException(
                'Certificate retirement proof is not bound to the exact intent.',
            );
        }
        return [
            'schema' => 'wls-certificate-retirement-proof/1',
            'intent_id' => (string)$intent['intent_id'],
            'domain' => (string)$intent['domain'],
            'generation' => (int)$intent['generation'],
            'source_digest' => (string)$intent['source_digest'],
            'gateway' => [
                'status' => $gatewayStatus,
                'evidence_digest' => $gatewayDigest,
            ],
            'native' => [
                'status' => $nativeStatus,
                'evidence_digest' => $nativeDigest,
            ],
            'verified_at' => $verifiedAt,
        ];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameRetirementIdentity(array $left, array $right): bool
    {
        return \hash_equals(
            \strtolower(\trim((string)($left['intent_id'] ?? ''))),
            \strtolower(\trim((string)($right['intent_id'] ?? ''))),
        )
            && \hash_equals(
                (string)($left['domain'] ?? ''),
                (string)($right['domain'] ?? ''),
            )
            && \is_int($left['generation'] ?? null)
            && \is_int($right['generation'] ?? null)
            && (int)$left['generation'] === (int)$right['generation']
            && \hash_equals(
                \strtolower(\trim((string)($left['source_digest'] ?? ''))),
                \strtolower(\trim((string)($right['source_digest'] ?? ''))),
            )
            && (\trim((string)($left['metadata_digest'] ?? '')) === ''
                || \trim((string)($right['metadata_digest'] ?? '')) === ''
                || \hash_equals(
                    \strtolower(\trim((string)$left['metadata_digest'])),
                    \strtolower(\trim((string)$right['metadata_digest'])),
                ));
    }

    /**
     * @param array<string,mixed> $disabled
     * @param array<string,mixed> $active
     * @return array<string,mixed>
     */
    private function supersedeRetirementIntentUnlocked(
        array $disabled,
        array $active,
    ): array {
        $intent = \is_array($disabled['retirement_intent'] ?? null)
            ? $disabled['retirement_intent']
            : null;
        if (!\is_array($intent)
            || !\hash_equals('pending', (string)($intent['state'] ?? ''))
        ) {
            return $disabled;
        }
        $generation = $active['generation'] ?? null;
        $sourceDigest = \strtolower(\trim((string)(
            $active['source_digest'] ?? ''
        )));
        if (!\is_int($generation)
            || $generation <= (int)$disabled['generation']
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
        ) {
            return $disabled;
        }
        $superseded = \array_replace($intent, [
            'state' => 'superseded',
            'superseded_at' => \gmdate(DATE_ATOM),
            'superseded_by_generation' => $generation,
            'superseded_by_source_digest' => $sourceDigest,
        ]);
        $this->publishDisabledManifest($disabled, $superseded);
        $verified = $this->readDisabledUnlocked((string)$disabled['domain']);
        $verifiedIntent = \is_array($verified['retirement_intent'] ?? null)
            ? $verified['retirement_intent']
            : [];
        if (!\hash_equals('superseded', (string)($verifiedIntent['state'] ?? ''))
            || (int)($verifiedIntent['superseded_by_generation'] ?? 0) !== $generation
            || !\hash_equals(
                $sourceDigest,
                (string)($verifiedIntent['superseded_by_source_digest'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Certificate retirement supersession was not durable.',
            );
        }
        return $verified;
    }

    /** @param array<string,mixed>|null $disabled */
    private function assertExplicitRetirementAllowsReenable(?array $disabled): void
    {
        $intent = \is_array($disabled['retirement_intent'] ?? null)
            ? $disabled['retirement_intent']
            : null;
        if (!\is_array($intent)
            || !\hash_equals('pending', (string)($intent['state'] ?? ''))
            || \hash_equals(
                self::RETIREMENT_OPERATION_PROJECTION,
                (string)($intent['operation'] ?? ''),
            )
        ) {
            return;
        }
        throw new \RuntimeException(
            'Explicit certificate retirement must finish its generation-bound event '
                . 'before the domain can be re-enabled.',
        );
    }

    /**
     * @param array<string,mixed> $disabled
     * @param array<string,mixed> $retirementIntent
     */
    private function publishDisabledManifest(
        array $disabled,
        array $retirementIntent,
    ): void {
        $this->publishManifest(
            $this->disabledManifestFile((string)$disabled['domain']),
            [
                'schema' => 'wls-project-certificate-disabled/1',
                'state' => 'disabled',
                'domain' => (string)$disabled['domain'],
                'generation' => (int)$disabled['generation'],
                'source_digest' => (string)$disabled['source_digest'],
                'disabled_at' => (string)$disabled['disabled_at'],
                'retirement_intent' => $retirementIntent,
            ],
        );
    }

    /**
     * @return array{
     *   domain:string,
     *   disabled_generation:int,
     *   disabled_source_digest:string,
     *   target_source_digest:string,
     *   intent_id:string,
     *   issued_at:string
     * }|null
     */
    private function readReenableIntentUnlocked(string $domain): ?array
    {
        $file = $this->reenableIntentFile($domain);
        $status = @\lstat($file);
        if (!\is_array($status)) {
            if (\file_exists($file) || \is_link($file)) {
                throw new \RuntimeException(
                    'Certificate re-enable intent is indeterminate.',
                );
            }
            return null;
        }
        if (\is_link($file)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0600
                    || ($this->projectOwner >= 0
                        && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException('Certificate re-enable intent is unsafe.');
        }
        $this->preserveProjectArtifactOwnership($file);
        $intent = $this->readManifest($file);
        $generation = (int)($intent['disabled_generation'] ?? 0);
        $disabledDigest = \strtolower(\trim((string)(
            $intent['disabled_source_digest'] ?? ''
        )));
        $targetDigest = \strtolower(\trim((string)(
            $intent['target_source_digest'] ?? ''
        )));
        $intentId = \strtolower(\trim((string)($intent['intent_id'] ?? '')));
        if (!\hash_equals(
                'wls-project-certificate-reenable/1',
                (string)($intent['schema'] ?? ''),
            )
            || !\hash_equals('authorized', (string)($intent['state'] ?? ''))
            || !\hash_equals($domain, (string)($intent['domain'] ?? ''))
            || $generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $disabledDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $targetDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $intentId) !== 1
            || !\hash_equals(
                $this->disabledSourceDigest($domain, $generation),
                $disabledDigest,
            )
            || !\hash_equals(
                $this->reenableIntentId(
                    $domain,
                    $generation,
                    $disabledDigest,
                    $targetDigest,
                ),
                $intentId,
            )
            || \trim((string)($intent['issued_at'] ?? '')) === ''
        ) {
            throw new \RuntimeException(
                'Certificate re-enable intent integrity validation failed.',
            );
        }
        return [
            'domain' => $domain,
            'disabled_generation' => $generation,
            'disabled_source_digest' => $disabledDigest,
            'target_source_digest' => $targetDigest,
            'intent_id' => $intentId,
            'issued_at' => (string)$intent['issued_at'],
        ];
    }

    private function reenableIntentId(
        string $domain,
        int $disabledGeneration,
        string $disabledSourceDigest,
        string $targetSourceDigest,
    ): string {
        if ($disabledGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $disabledSourceDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $targetSourceDigest) !== 1
        ) {
            throw new \RuntimeException('Certificate re-enable authority is invalid.');
        }
        return \hash(
            'sha256',
            "wls-certificate-reenable\0" . $domain . "\0"
                . $disabledGeneration . "\0" . $disabledSourceDigest . "\0"
                . $targetSourceDigest,
        );
    }

    private function removeReenableIntentUnlocked(string $domain): void
    {
        $path = $this->reenableIntentFile($domain);
        if (@\lstat($path) === false
            && !\file_exists($path)
            && !\is_link($path)
        ) {
            return;
        }
        GatewayProjectStateFilesystem::removeRegular(
            $path,
            'certificate re-enable intent',
        );
        GatewayProjectStateFilesystem::syncDirectory(\dirname($path));
    }

    private function readStableFile(string $path, bool $privateKey): string
    {
        if ($path === '' || \str_contains($path, "\0") || \is_link($path)) {
            throw new \RuntimeException('Certificate material path is unsafe.');
        }
        $real = \realpath($path);
        if (!\is_string($real) || !\is_file($real) || \is_link($real)) {
            throw new \RuntimeException('Certificate material file is unavailable.');
        }
        if (!$this->samePath($path, $real)) {
            throw new \RuntimeException('Symbolic-link or non-canonical certificate paths are forbidden.');
        }
        $before = @\lstat($real);
        $stream = @\fopen($real, 'rb');
        if (!\is_array($before) || !\is_resource($stream)) {
            throw new \RuntimeException('Unable to open certificate material safely.');
        }
        try {
            $opened = @\fstat($stream);
            if (!\is_array($opened)
                || ((int)($opened['mode'] ?? 0) & 0170000) !== 0100000
                || (int)($opened['nlink'] ?? 0) !== 1
                || (int)($opened['size'] ?? -1) < 1
                || (int)$opened['size'] > self::MAX_MATERIAL_BYTES
            ) {
                throw new \RuntimeException('Certificate material size or file type is invalid.');
            }
            if (\PHP_OS_FAMILY !== 'Windows') {
                if ($privateKey && ((int)$opened['mode'] & 0077) !== 0) {
                    throw new \RuntimeException(
                        'Private key source must not grant group or other permissions.'
                    );
                }
            }
            $contents = \stream_get_contents($stream, self::MAX_MATERIAL_BYTES + 1);
            $after = @\fstat($stream);
        } finally {
            @\fclose($stream);
        }
        $latest = @\lstat($real);
        if (!\is_string($contents)
            || \strlen($contents) < 1
            || \strlen($contents) > self::MAX_MATERIAL_BYTES
            || !\is_array($after)
            || !\is_array($latest)
        ) {
            throw new \RuntimeException('Certificate material read was incomplete.');
        }
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if ((int)($before[$field] ?? -1) !== (int)($opened[$field] ?? -2)
                || (int)($opened[$field] ?? -1) !== (int)($after[$field] ?? -2)
                || (int)($after[$field] ?? -1) !== (int)($latest[$field] ?? -2)
            ) {
                throw new \RuntimeException('Certificate material changed while being read.');
            }
        }
        return $contents;
    }

    private function safeHashFile(string $path): string
    {
        return \hash('sha256', $this->readStableFile($path, \str_ends_with($path, 'privkey.pem')));
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\rtrim(\trim($domain), '.'));
        $wildcard = \str_starts_with($domain, '*.');
        $base = $wildcard ? \substr($domain, 2) : $domain;
        if (!$wildcard && \filter_var($base, FILTER_VALIDATE_IP) !== false) {
            $packed = @\inet_pton($base);
            if (!\is_string($packed)) {
                throw new \InvalidArgumentException('Invalid TLS IP address: ' . $domain);
            }
            return (string)@\inet_ntop($packed);
        }
        if (\function_exists('idn_to_ascii')) {
            $variant = \defined('INTL_IDNA_VARIANT_UTS46')
                ? \constant('INTL_IDNA_VARIANT_UTS46')
                : 0;
            $ascii = @\idn_to_ascii($base, IDNA_DEFAULT, $variant);
            if (\is_string($ascii) && $ascii !== '') {
                $base = \strtolower($ascii);
            }
        }
        if (\strlen($base) > 253
            || \preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
                    . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
                $base,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid TLS domain name: ' . $domain);
        }
        return $wildcard ? '*.' . $base : $base;
    }

    private function ensureStoreDirectories(): void
    {
        $current = $this->projectRoot;
        foreach (['app', 'etc', 'ssl', '.wls-generations', 'snapshots'] as $index => $leaf) {
            $directory = $current . DIRECTORY_SEPARATOR . $leaf;
            $mode = $index < 2 ? 0755 : 0700;
            $status = @\lstat($directory);
            $created = false;
            if (!\is_array($status)) {
                if (\file_exists($directory)
                    || \is_link($directory)
                    || !@\mkdir($directory, $mode)
                ) {
                    throw new \RuntimeException(
                        'Project certificate generation directory is unavailable: ' . $directory
                    );
                }
                $created = true;
            }
            if ($created && $index < 3) {
                $this->preserveCreatedProjectDirectory($directory);
            }
            if (!\is_dir($directory)) {
                throw new \RuntimeException(
                    'Project certificate generation directory is unavailable: ' . $directory
                );
            }
            $status = @\lstat($directory);
            $real = \realpath($directory);
            if (!\is_array($status)
                || \is_link($directory)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || !\is_string($real)
                || !$this->pathInside($real, $this->projectRoot)
                || (\PHP_OS_FAMILY !== 'Windows'
                    && $index < 3
                    && ((((int)($status['mode'] ?? 0)) & 0022) !== 0))
                || (\PHP_OS_FAMILY !== 'Windows'
                    && $index >= 3
                    && (!@\chmod($directory, 0700)
                        || (((int)(@\fileperms($directory) ?: 0)) & 0777) !== 0700))
            ) {
                throw new \RuntimeException(
                    'Project certificate generation directory is unsafe: ' . $directory
                );
            }
            $current = \rtrim($real, '/\\');
            if ($index >= 3) {
                $this->preserveProjectArtifactOwnership($current);
            }
        }
        foreach (['active', 'disabled', 'reenable-intents'] as $selectorDirectory) {
            $selectorRoot = $this->storeRoot . DIRECTORY_SEPARATOR . $selectorDirectory;
            if (!\is_dir($selectorRoot)
                && !@\mkdir($selectorRoot, 0700)
                && !\is_dir($selectorRoot)
            ) {
                throw new \RuntimeException(
                    'Project certificate generation directory is unavailable: '
                        . $selectorRoot,
                );
            }
            $selectorStatus = @\lstat($selectorRoot);
            if (!\is_array($selectorStatus)
                || \is_link($selectorRoot)
                || ((((int)($selectorStatus['mode'] ?? 0)) & 0170000) !== 0040000)
                || (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($selectorRoot, 0700))
            ) {
                throw new \RuntimeException(
                    'Project certificate generation selector directory is unsafe.',
                );
            }
            $this->preserveProjectArtifactOwnership($selectorRoot);
        }
    }

    private function activeManifestFile(string $domain): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'active'
            . DIRECTORY_SEPARATOR . \substr(\hash('sha256', $domain), 0, 32) . '.json';
    }

    private function disabledManifestFile(string $domain): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'disabled'
            . DIRECTORY_SEPARATOR . \substr(\hash('sha256', $domain), 0, 32) . '.json';
    }

    private function reenableIntentFile(string $domain): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'reenable-intents'
            . DIRECTORY_SEPARATOR . \substr(\hash('sha256', $domain), 0, 32) . '.json';
    }

    /**
     * Select one exact same-directory atomic-write artifact without allowing
     * an arbitrary reserved-looking leaf to bypass selector validation.
     * Cleanup is deferred until the encoded committed target has passed its
     * complete manifest and snapshot/tombstone validation.
     *
     * @return array{path:string,target:string,target_leaf:string,identity:array<string|int,mixed>}|null
     */
    private function selectorAtomicCrashArtifact(
        string $root,
        string $leaf,
        string $selector,
    ): ?array {
        if (!\in_array(
            $selector,
            ['active', 'disabled', 'reenable-intents'],
            true,
        )) {
            throw new \RuntimeException(
                'Certificate selector atomic recovery scope is invalid.',
            );
        }
        $matches = [];
        if (\preg_match(
            '/\A([a-f0-9]{32}\.json)\.(?:tmp-[a-f0-9]{24}|wls-backup-[a-f0-9]{16})\z/D',
            $leaf,
            $matches,
        ) !== 1) {
            return null;
        }
        $expectedRoot = $this->storeRoot . DIRECTORY_SEPARATOR . $selector;
        if (!$this->samePath($root, $expectedRoot)) {
            throw new \RuntimeException(
                'Certificate selector atomic recovery root is invalid.',
            );
        }
        $artifact = $root . DIRECTORY_SEPARATOR . $leaf;
        $artifactStatus = @\lstat($artifact);
        if (!\is_array($artifactStatus)) {
            throw new \RuntimeException(
                'Certificate selector atomic recovery artifact is indeterminate.',
            );
        }
        $artifactIdentity = $this->assertAtomicRecoveryFile(
            $artifact,
            $artifactStatus,
            'artifact',
        );

        $target = $root . DIRECTORY_SEPARATOR . (string)$matches[1];
        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)) {
            $this->assertAtomicRecoveryFile(
                $target,
                $targetStatus,
                'selector target',
            );
        } elseif (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException(
                'Certificate selector atomic recovery target is indeterminate.',
            );
        }
        return [
            'path' => $artifact,
            'target' => $target,
            'target_leaf' => (string)$matches[1],
            'identity' => $artifactIdentity,
        ];
    }

    /**
     * @param array<string|int,mixed> $status
     * @return array<string|int,mixed>
     */
    private function assertAtomicRecoveryFile(
        string $path,
        array $status,
        string $role,
    ): array {
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Certificate atomic recovery ' . $role . ' is unsafe.',
            );
        }
        $opened = null;
        try {
            $opened = @\fstat($handle);
            $pathAfter = @\lstat($path);
            $stable = \is_array($opened) && \is_array($pathAfter);
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
                $stable = $stable
                    && \array_key_exists($field, $status)
                    && \array_key_exists($field, $opened)
                    && \array_key_exists($field, $pathAfter)
                    && (int)$status[$field] === (int)$opened[$field]
                    && (int)$opened[$field] === (int)$pathAfter[$field];
            }
            $size = \is_array($opened) ? ($opened['size'] ?? null) : null;
            if (!$stable
                || \is_link($path)
                || ((((int)($opened['mode'] ?? 0)) & 0170000) !== 0100000)
                || (int)($opened['nlink'] ?? 0) !== 1
                || !\is_int($size)
                || $size < 0
                || $size > self::MAX_MATERIAL_BYTES
                || (\PHP_OS_FAMILY !== 'Windows'
                    && (((((int)$opened['mode']) & 0777) !== 0600)
                        || ($this->projectOwner >= 0
                            && (int)($opened['uid'] ?? -1) !== $this->projectOwner)))
            ) {
                throw new \RuntimeException(
                    'Certificate atomic recovery ' . $role . ' is unsafe.',
                );
            }
        } finally {
            @\fclose($handle);
        }
        if (!\is_array($opened)) {
            throw new \RuntimeException(
                'Certificate atomic recovery ' . $role . ' is indeterminate.',
            );
        }
        return $opened;
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameAtomicRecoveryState(array $before, array $after): bool
    {
        foreach (
            ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']
            as $field
        ) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<array{path:string,target:string,target_leaf:string,identity:array<string|int,mixed>}> $recoveries
     * @param array<string,array<string|int,mixed>> $validatedTargets
     */
    private function reclaimSelectorAtomicCrashArtifacts(
        array $recoveries,
        array $validatedTargets,
        string $selector,
    ): void {
        foreach ($recoveries as $recovery) {
            $targetIdentity = $validatedTargets[$recovery['target_leaf']] ?? null;
            if (!\is_array($targetIdentity)) {
                throw new \RuntimeException(
                    'Certificate ' . $selector . ' selector recovery requires repair; '
                        . 'its committed target is missing or unverified.',
                );
            }
            $this->reclaimAtomicCrashArtifact(
                $recovery,
                $targetIdentity,
                'certificate ' . $selector . ' selector atomic recovery artifact',
            );
        }
    }

    /** @param array<string,mixed> $payload */
    private function reclaimSelectorAtomicCrashArtifactsBeforePublication(
        string $path,
        array $payload,
    ): void {
        $selector = null;
        foreach (['active', 'disabled', 'reenable-intents'] as $candidate) {
            if ($this->samePath(
                \dirname($path),
                $this->storeRoot . DIRECTORY_SEPARATOR . $candidate,
            )) {
                $selector = $candidate;
                break;
            }
        }
        if ($selector === null) {
            return;
        }
        $domain = $this->normalizeDomain((string)($payload['domain'] ?? ''));
        $expectedTarget = match ($selector) {
            'active' => $this->activeManifestFile($domain),
            'disabled' => $this->disabledManifestFile($domain),
            'reenable-intents' => $this->reenableIntentFile($domain),
        };
        if (!$this->samePath($path, $expectedTarget)) {
            throw new \RuntimeException(
                'Certificate selector publication target is inconsistent.',
            );
        }
        // Windows enforces both per-target and per-directory backup quotas.
        // Reclaim the complete selector directory so abandoned backups for a
        // quiet domain cannot block another domain's publication.
        match ($selector) {
            'active' => $this->activeSnapshotReferences(
                $this->storedSnapshotInventory(),
            ),
            'disabled' => $this->disabledCertificatesUnlocked(null),
            'reenable-intents' => $this
                ->reclaimReenableIntentAtomicCrashArtifacts(),
        };
        $targetLeaf = \basename(\str_replace('\\', '/', $expectedTarget));
        $recoveries = $this->selectorAtomicCrashArtifactsForTarget(
            $selector,
            $targetLeaf,
        );
        if ($recoveries === []) {
            return;
        }
        $this->preserveProjectArtifactOwnership($expectedTarget);
        \clearstatcache(true, $expectedTarget);
        $status = @\lstat($expectedTarget);
        if (!\is_array($status)) {
            throw new \RuntimeException(
                'Certificate ' . $selector . ' selector recovery requires repair; '
                    . 'its committed target is missing.',
            );
        }
        $before = $this->assertAtomicRecoveryFile(
            $expectedTarget,
            $status,
            'selector publication target',
        );
        $validated = match ($selector) {
            'active' => $this->readActiveUnlocked($domain, false),
            'disabled' => $this->readDisabledUnlocked($domain),
            'reenable-intents' => $this->readReenableIntentUnlocked($domain),
        };
        if (!\is_array($validated)) {
            throw new \RuntimeException(
                'Certificate selector recovery target disappeared during validation.',
            );
        }
        \clearstatcache(true, $expectedTarget);
        $afterStatus = @\lstat($expectedTarget);
        if (!\is_array($afterStatus)) {
            throw new \RuntimeException(
                'Certificate selector recovery target changed during validation.',
            );
        }
        $after = $this->assertAtomicRecoveryFile(
            $expectedTarget,
            $afterStatus,
            'selector publication target',
        );
        if (!$this->sameAtomicRecoveryState($before, $after)) {
            throw new \RuntimeException(
                'Certificate selector recovery target changed during validation.',
            );
        }
        $this->reclaimSelectorAtomicCrashArtifacts(
            $recoveries,
            [$targetLeaf => $after],
            $selector,
        );
    }

    /**
     * @return list<array{path:string,target:string,target_leaf:string,identity:array<string|int,mixed>}>
     */
    private function selectorAtomicCrashArtifactsForTarget(
        string $selector,
        string $targetLeaf,
    ): array {
        if (!\in_array(
                $selector,
                ['active', 'disabled', 'reenable-intents'],
                true,
            )
            || \preg_match('/\A[a-f0-9]{32}\.json\z/D', $targetLeaf) !== 1
        ) {
            throw new \RuntimeException(
                'Certificate selector recovery target is invalid.',
            );
        }
        $root = $this->storeRoot . DIRECTORY_SEPARATOR . $selector;
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate certificate selector recovery state.',
            );
        }
        $recoveries = [];
        $count = 0;
        $pattern = '/\A' . \preg_quote($targetLeaf, '/')
            . '\.(?:tmp-[a-f0-9]{24}|wls-backup-[a-f0-9]{16})\z/D';
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$count > self::MAX_ACTIVE_MANIFESTS) {
                    throw new \RuntimeException(
                        'Certificate selector recovery directory exceeds its bound.',
                    );
                }
                if (\preg_match($pattern, $leaf) !== 1) {
                    continue;
                }
                $recovery = $this->selectorAtomicCrashArtifact(
                    $root,
                    $leaf,
                    $selector,
                );
                if ($recovery === null) {
                    throw new \RuntimeException(
                        'Certificate selector recovery artifact is invalid.',
                    );
                }
                $recoveries[] = $recovery;
            }
        } finally {
            @\closedir($handle);
        }
        return $recoveries;
    }

    private function reclaimReenableIntentAtomicCrashArtifacts(): void
    {
        $selector = 'reenable-intents';
        $root = $this->storeRoot . DIRECTORY_SEPARATOR . $selector;
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate certificate re-enable recovery state.',
            );
        }
        $recoveries = [];
        $validatedTargets = [];
        $domains = [];
        $count = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$count > self::MAX_ACTIVE_MANIFESTS) {
                    throw new \RuntimeException(
                        'Certificate re-enable recovery directory exceeds its bound.',
                    );
                }
                $recovery = $this->selectorAtomicCrashArtifact(
                    $root,
                    $leaf,
                    $selector,
                );
                if ($recovery !== null) {
                    $recoveries[] = $recovery;
                    continue;
                }
                if (\preg_match('/\A[a-f0-9]{32}\.json\z/D', $leaf) !== 1) {
                    throw new \RuntimeException(
                        'Certificate re-enable recovery directory is malformed.',
                    );
                }
                $path = $root . DIRECTORY_SEPARATOR . $leaf;
                $this->preserveProjectArtifactOwnership($path);
                $status = @\lstat($path);
                if (!\is_array($status)) {
                    throw new \RuntimeException(
                        'Certificate re-enable intent is indeterminate.',
                    );
                }
                $before = $this->assertAtomicRecoveryFile(
                    $path,
                    $status,
                    're-enable selector target',
                );
                $manifest = $this->readManifest($path);
                $domain = $this->normalizeDomain((string)(
                    $manifest['domain'] ?? ''
                ));
                if (!\hash_equals(
                        \substr(\hash('sha256', $domain), 0, 32) . '.json',
                        $leaf,
                    )
                    || isset($domains[$domain])
                    || !\is_array($this->readReenableIntentUnlocked($domain))
                ) {
                    throw new \RuntimeException(
                        'Certificate re-enable intent identity is inconsistent.',
                    );
                }
                \clearstatcache(true, $path);
                $afterStatus = @\lstat($path);
                if (!\is_array($afterStatus)) {
                    throw new \RuntimeException(
                        'Certificate re-enable intent changed during validation.',
                    );
                }
                $after = $this->assertAtomicRecoveryFile(
                    $path,
                    $afterStatus,
                    're-enable selector target',
                );
                if (!$this->sameAtomicRecoveryState($before, $after)) {
                    throw new \RuntimeException(
                        'Certificate re-enable intent changed during validation.',
                    );
                }
                $domains[$domain] = true;
                $validatedTargets[$leaf] = $after;
            }
        } finally {
            @\closedir($handle);
        }
        $this->reclaimSelectorAtomicCrashArtifacts(
            $recoveries,
            $validatedTargets,
            $selector,
        );
    }

    /**
     * @return list<array{path:string,target:string,target_leaf:string,identity:array<string|int,mixed>}>
     */
    private function atomicCrashArtifactsForTarget(
        string $target,
        int $maxDirectoryEntries,
        string $role,
    ): array {
        if ($maxDirectoryEntries < 1
            || $maxDirectoryEntries > 16_384
            || \str_contains($target, "\0")
        ) {
            throw new \RuntimeException(
                'Certificate atomic recovery target scope is invalid.',
            );
        }
        $root = \dirname($target);
        $targetLeaf = \basename(\str_replace('\\', '/', $target));
        if ($targetLeaf === '' || $targetLeaf === '.' || $targetLeaf === '..') {
            throw new \RuntimeException(
                'Certificate atomic recovery target leaf is invalid.',
            );
        }
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate certificate atomic recovery state.',
            );
        }
        $recoveries = [];
        $count = 0;
        $pattern = '/\A' . \preg_quote($targetLeaf, '/')
            . '\.(?:tmp-[a-f0-9]{24}|wls-backup-[a-f0-9]{16})\z/D';
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$count > $maxDirectoryEntries) {
                    throw new \RuntimeException(
                        'Certificate atomic recovery directory exceeds its bound.',
                    );
                }
                if (\preg_match($pattern, $leaf) !== 1) {
                    continue;
                }
                $artifact = $root . DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($artifact);
                if (!\is_array($status)) {
                    throw new \RuntimeException(
                        'Certificate atomic recovery artifact is indeterminate.',
                    );
                }
                $recoveries[] = [
                    'path' => $artifact,
                    'target' => $target,
                    'target_leaf' => $targetLeaf,
                    'identity' => $this->assertAtomicRecoveryFile(
                        $artifact,
                        $status,
                        $role,
                    ),
                ];
            }
        } finally {
            @\closedir($handle);
        }
        return $recoveries;
    }

    private function discardRebuildableAtomicCrashArtifacts(
        string $target,
        string $label,
    ): void {
        foreach ($this->atomicCrashArtifactsForTarget(
            $target,
            self::MAX_SNAPSHOT_ROOT_ENTRIES,
            $label,
        ) as $recovery) {
            GatewayProjectStateFilesystem::removeRegular(
                $recovery['path'],
                $label,
                $recovery['identity'],
            );
        }
    }

    /**
     * @param array{path:string,target:string,target_leaf:string,identity:array<string|int,mixed>} $recovery
     * @param array<string|int,mixed> $validatedTargetIdentity
     */
    private function reclaimAtomicCrashArtifact(
        array $recovery,
        array $validatedTargetIdentity,
        string $label,
    ): void {
        $target = $recovery['target'];
        \clearstatcache(true, $target);
        $targetStatus = @\lstat($target);
        if (!\is_array($targetStatus)) {
            throw new \RuntimeException(
                $label . ' target disappeared before recovery cleanup.',
            );
        }
        $currentTargetIdentity = $this->assertAtomicRecoveryFile(
            $target,
            $targetStatus,
            'committed target',
        );
        if (!$this->sameAtomicRecoveryState(
            $validatedTargetIdentity,
            $currentTargetIdentity,
        )) {
            throw new \RuntimeException(
                $label . ' target changed before recovery cleanup.',
            );
        }
        GatewayProjectStateFilesystem::removeRegular(
            $recovery['path'],
            $label,
            $recovery['identity'],
        );
    }

    private function certificateLifecycleLockPath(): string
    {
        return \dirname($this->storeRoot) . DIRECTORY_SEPARATOR
            . '.wls-certificate-lifecycle.lock';
    }

    private function assertDisabledManifestCapacity(): void
    {
        // This method is called under activation.lock. Reclaim only artifacts
        // paired with tombstones that pass their complete integrity checks
        // before applying the raw selector-entry quota.
        $this->disabledCertificatesUnlocked(null);
        $root = $this->storeRoot . DIRECTORY_SEPARATOR . 'disabled';
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate disabled certificate tombstones.',
            );
        }
        $count = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $path = $root . DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($path);
                if (++$count > self::MAX_ACTIVE_MANIFESTS) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone store is malformed or full.',
                    );
                }
                if ($this->selectorAtomicCrashArtifact(
                    $root,
                    $leaf,
                    'disabled',
                ) !== null) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone recovery requires repair.',
                    );
                }
                if (\preg_match('/\A[a-f0-9]{32}\.json\z/D', $leaf) !== 1
                    || !\is_array($status)
                    || \is_link($path)
                    || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
                    || (int)($status['nlink'] ?? 0) !== 1
                ) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone store is malformed or full.',
                    );
                }
            }
        } finally {
            @\closedir($handle);
        }
        if ($count >= self::MAX_ACTIVE_MANIFESTS) {
            throw new \RuntimeException(
                'Disabled certificate tombstone store has reached its quota.',
            );
        }
    }

    private function certificateGenerationFloorFile(): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'generation-floor.txt';
    }

    private function allocateCertificateGeneration(int $activeGeneration): int
    {
        $floor = \max($activeGeneration, $this->readCertificateGenerationFloor());
        if ($floor >= PHP_INT_MAX) {
            throw new \RuntimeException('Certificate generation authority is exhausted.');
        }
        $next = $floor + 1;
        $this->atomicWrite(
            $this->certificateGenerationFloorFile(),
            (string)$next . "\n",
            0600,
        );
        return $next;
    }

    private function preserveCertificateGenerationFloor(int $generation): void
    {
        if ($generation < 1) {
            throw new \RuntimeException('Certificate generation floor is invalid.');
        }
        if ($generation <= $this->readCertificateGenerationFloor()) {
            return;
        }
        $this->atomicWrite(
            $this->certificateGenerationFloorFile(),
            (string)$generation . "\n",
            0600,
        );
    }

    private function readCertificateGenerationFloor(): int
    {
        $path = $this->certificateGenerationFloorFile();
        $recoveries = $this->certificateGenerationFloorAtomicCrashArtifacts();
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'Certificate generation floor is indeterminate or unsafe.',
                );
            }
            if ($recoveries !== []) {
                throw new \RuntimeException(
                    'Certificate generation floor recovery requires repair; '
                        . 'the committed target is missing.',
                );
            }
            return 0;
        }
        if (\is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0600
                    || ($this->projectOwner >= 0
                        && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException('Certificate generation floor is unsafe.');
        }
        $before = $this->assertAtomicRecoveryFile(
            $path,
            $status,
            'generation floor target',
        );
        $encoded = GatewayProjectStateFilesystem::read(
            $path,
            64,
            'certificate generation floor',
        );
        $value = \trim($encoded);
        $maximum = (string)PHP_INT_MAX;
        if (\preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value) !== 1
            || \strlen($value) > \strlen($maximum)
            || (\strlen($value) === \strlen($maximum)
                && \strcmp($value, $maximum) > 0)
        ) {
            throw new \RuntimeException('Certificate generation floor is corrupt.');
        }
        \clearstatcache(true, $path);
        $afterStatus = @\lstat($path);
        if (!\is_array($afterStatus)) {
            throw new \RuntimeException(
                'Certificate generation floor changed during validation.',
            );
        }
        $after = $this->assertAtomicRecoveryFile(
            $path,
            $afterStatus,
            'generation floor target',
        );
        if (!$this->sameAtomicRecoveryState($before, $after)) {
            throw new \RuntimeException(
                'Certificate generation floor changed during validation.',
            );
        }
        foreach ($recoveries as $recovery) {
            $this->reclaimAtomicCrashArtifact(
                $recovery,
                $after,
                'certificate generation floor atomic recovery artifact',
            );
        }
        return (int)$value;
    }

    /**
     * @return list<array{path:string,target:string,target_leaf:string,identity:array<string|int,mixed>}>
     */
    private function certificateGenerationFloorAtomicCrashArtifacts(): array
    {
        $root = $this->storeRoot;
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate certificate generation floor recovery state.',
            );
        }
        $targetLeaf = 'generation-floor.txt';
        $target = $root . DIRECTORY_SEPARATOR . $targetLeaf;
        $recoveries = [];
        $count = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$count > self::MAX_SNAPSHOT_ROOT_ENTRIES) {
                    throw new \RuntimeException(
                        'Certificate generation floor recovery directory exceeds its bound.',
                    );
                }
                if (\preg_match(
                    '/\Ageneration-floor\.txt\.(?:tmp-[a-f0-9]{24}'
                        . '|wls-backup-[a-f0-9]{16})\z/D',
                    $leaf,
                ) !== 1) {
                    continue;
                }
                $artifact = $root . DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($artifact);
                if (!\is_array($status)) {
                    throw new \RuntimeException(
                        'Certificate generation floor recovery artifact is indeterminate.',
                    );
                }
                $recoveries[] = [
                    'path' => $artifact,
                    'target' => $target,
                    'target_leaf' => $targetLeaf,
                    'identity' => $this->assertAtomicRecoveryFile(
                        $artifact,
                        $status,
                        'generation floor artifact',
                    ),
                ];
            }
        } finally {
            @\closedir($handle);
        }
        return $recoveries;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function publishManifest(string $path, array $payload): void
    {
        // Active and disabled selector writers all run under activation.lock.
        // Reclaim paired crash leaves before Windows allocates its next bounded
        // backup slot, but only after validating the exact committed target.
        $this->reclaimSelectorAtomicCrashArtifactsBeforePublication(
            $path,
            $payload,
        );
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', GatewayClient::canonicalJson($payload)),
        ];
        $encoded = \json_encode(
            $envelope,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode certificate generation manifest.');
        }
        $this->atomicWrite($path, $encoded, 0600);
    }

    /**
     * @return array<string,mixed>
     */
    private function readManifest(string $path): array
    {
        $encoded = $this->readStableFile($path, false);
        $envelope = \json_decode($encoded, true);
        $payload = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : null;
        if (!\is_array($payload)
            || !\hash_equals(
                (string)($envelope['sha256'] ?? ''),
                \hash('sha256', GatewayClient::canonicalJson($payload)),
            )
        ) {
            throw new \RuntimeException('Certificate generation manifest failed integrity validation.');
        }
        return $payload;
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $this->assertSafeTarget($path);
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            $contents,
            $mode,
            fn ($handle, string $candidate): mixed => $this->preserveProjectArtifactOwnership(
                $candidate,
                $handle,
            ),
        );
    }

    /**
     * Root may coordinate enrollment/promotion, but every derived certificate
     * generation remains a project-owned fact. Never apply this repair to the
     * original certificate sources or to paths outside the private store.
     *
     * @param resource|null $handle
     */
    private function preserveProjectArtifactOwnership(string $path, mixed $handle = null): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || $this->projectOwner < 0
            || $this->projectGroup < 0
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $store = \realpath($this->storeRoot);
        $real = \realpath($path);
        $status = @\lstat($path);
        if (!\is_string($store)
            || !\is_string($real)
            || !$this->pathInside($real, $store)
            || \is_link($path)
            || !\is_array($status)
            || (!\is_file($path) && !\is_dir($path))
        ) {
            throw new \RuntimeException(
                'Certificate generation ownership target is unsafe.'
            );
        }
        $ownerApplied = \is_resource($handle)
            && \function_exists('fchown')
            && @\fchown($handle, $this->projectOwner);
        if (!$ownerApplied) {
            $ownerApplied = \function_exists('lchown')
                ? @\lchown($path, $this->projectOwner)
                : @\chown($path, $this->projectOwner);
        }
        $groupApplied = \is_resource($handle)
            && \function_exists('fchgrp')
            && @\fchgrp($handle, $this->projectGroup);
        if (!$groupApplied) {
            $groupApplied = \function_exists('lchgrp')
                ? @\lchgrp($path, $this->projectGroup)
                : @\chgrp($path, $this->projectGroup);
        }
        $actual = @\lstat($path);
        if (!$ownerApplied
            || !$groupApplied
            || !\is_array($actual)
            || (int)($actual['uid'] ?? -1) !== $this->projectOwner
            || (int)($actual['gid'] ?? -1) !== $this->projectGroup
        ) {
            throw new \RuntimeException(
                'Unable to preserve the project owner on certificate generations.'
            );
        }
    }

    private function assertSafeTarget(string $path): void
    {
        if (\is_link($path)) {
            throw new \RuntimeException('Symbolic-link certificate generation targets are forbidden.');
        }
        $parent = \realpath(\dirname($path));
        if (!\is_string($parent) || !$this->pathInside($parent, $this->storeRoot)) {
            throw new \RuntimeException('Certificate generation target escapes the project store.');
        }
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        $root = \str_replace('\\', '/', \rtrim($root, '/\\'));
        if ($root === '' || $this->isFilesystemRoot($root)) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function isFilesystemRoot(string $path): bool
    {
        $path = \str_replace('\\', '/', \trim($path));
        if (\preg_match('#\A/+\z#D', $path) === 1) {
            return true;
        }
        $path = \rtrim($path, '/');
        return \preg_match('/\A[A-Za-z]:\z/D', $path) === 1
            || \preg_match('#\A//(?![?.](?:/|\z))[^/]+(?:/[^/]+)?\z#D', $path) === 1
            || \preg_match('#\A//[?.]/[A-Za-z]:\z#Di', $path) === 1
            || \preg_match('#\A//[?.]/UNC(?:/[^/]+(?:/[^/]+)?)?\z#Di', $path) === 1
            || \preg_match('#\A//[?.]/Volume\{[0-9A-Fa-f-]+\}\z#Di', $path) === 1;
    }

    private function samePath(string $path, string $real): bool
    {
        $absolute = \str_starts_with($path, '/')
            || \preg_match('/\A[A-Za-z]:[\\\\\\/]/D', $path) === 1
            ? $path
            : $this->projectRoot . DIRECTORY_SEPARATOR . $path;
        $absolute = \str_replace('\\', '/', \rtrim($absolute, '/\\'));
        $real = \str_replace('\\', '/', \rtrim($real, '/\\'));
        if (\PHP_OS_FAMILY === 'Windows') {
            $absolute = \strtolower($absolute);
            $real = \strtolower($real);
        }
        return $absolute === $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return \str_starts_with($path, '/')
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || \str_starts_with($path, '\\\\');
    }

    private function pathKey(string $path): string
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
    }

    private function assertProjectPathComponents(string $path): void
    {
        if (!$this->pathInside($path, $this->projectRoot)) {
            throw new \RuntimeException('Certificate path escapes the project root.');
        }
        $relative = \ltrim(\substr(
            $this->pathKey($path),
            \strlen($this->pathKey($this->projectRoot)),
        ), '/');
        $current = $this->projectRoot;
        foreach ($relative === '' ? [] : \explode('/', $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            $status = @\lstat($current);
            if (!\is_array($status)
                || \is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || (\PHP_OS_FAMILY !== 'Windows'
                    && (((int)($status['mode'] ?? 0)) & 0022) !== 0)
            ) {
                throw new \RuntimeException(
                    'Certificate source path contains a linked, special or group/world-writable directory.'
                );
            }
        }
    }

    /**
     * Validate only the explicit enrollment boundary and its descendants. Host
     * ancestors such as /tmp are not implicitly trusted or rejected; enrolling
     * /tmp itself still fails because the enrolled root is writable. Every
     * component below a secure /tmp/project-certificates enrollment remains
     * subject to the same owner and permission policy.
     */
    private function assertEnrolledDirectoryComponents(
        string $root,
        string $directory,
        int $expectedOwner,
    ): void
    {
        $root = \rtrim($root, '/\\');
        $directory = \rtrim($directory, '/\\');
        if (!$this->pathInside($directory, $root)) {
            throw new \RuntimeException(
                'Certificate source directory escapes its enrolled root.'
            );
        }
        $relative = \ltrim(\substr(
            $this->pathKey($directory),
            \strlen($this->pathKey($root)),
        ), '/');
        $segments = $relative === '' ? [] : \explode('/', $relative);
        if (\count($segments) > 256) {
            throw new \RuntimeException(
                'Certificate source path exceeds the 256-segment limit.'
            );
        }
        $current = $root;
        foreach ([null, ...$segments] as $segment) {
            if (\is_string($segment)) {
                $current .= DIRECTORY_SEPARATOR . $segment;
            }
            $status = @\lstat($current);
            if (!\is_array($status)
                || \is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || (\PHP_OS_FAMILY !== 'Windows'
                    && ((((int)($status['mode'] ?? 0)) & 0022) !== 0
                        || ($expectedOwner >= 0
                            && (int)($status['uid'] ?? -1) !== $expectedOwner)))
            ) {
                throw new \RuntimeException(
                    'Certificate source enrollment contains a linked, special, '
                    . 'foreign-owned or group/world-writable directory.'
                );
            }
        }
    }

    private function preserveCreatedProjectDirectory(string $directory): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || $this->projectOwner < 0
            || $this->projectGroup < 0
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        if (!@\chown($directory, $this->projectOwner)
            || !@\chgrp($directory, $this->projectGroup)
        ) {
            throw new \RuntimeException(
                'Unable to preserve project ownership on certificate directories.'
            );
        }
    }

    private function removeDirectory(string $directory): void
    {
        $snapshotRoot = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots';
        $basename = \basename($directory);
        if (!\is_dir($directory)
            || \is_link($directory)
            || !$this->pathInside($directory, $snapshotRoot)
            || \preg_match('/\A\.tmp-[a-f0-9]{24}\z/D', $basename) !== 1
        ) {
            return;
        }
        $entries = GatewayBoundedTreeWalker::collect($directory, true, true);
        foreach ($entries as $entry) {
            GatewayBoundedTreeWalker::revalidate($entry);
        }
        foreach ($entries as $entry) {
            GatewayBoundedTreeWalker::revalidate($entry);
            $removed = $entry['directory']
                ? @\rmdir((string)$entry['path'])
                : @\unlink((string)$entry['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove a verified certificate snapshot staging entry.'
                );
            }
        }
    }

}
