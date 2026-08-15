<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;


/**
 * Project-owned WLS identity and monotonic desired/certificate generations.
 *
 * The JSON file moves with the project. Host claims and fallback leases are
 * derived state used only to reject a live same-host clone.
 */
final class ProjectIdentityStore
{
    public const SCHEMA_VERSION = 1;

    private readonly string $projectRoot;
    private readonly string $identityFile;
    private readonly string $hostStateRoot;
    private readonly string $legacyDesiredStateFile;

    public function __construct(
        ?string $projectRoot = null,
        ?string $hostStateRoot = null,
        ?string $legacyDesiredStateFile = null,
    ) {
        $root = $projectRoot ?? (string)BP;
        if ($root === ''
            || \strlen($root) > 4096
            || \str_contains($root, "\0")
            || \is_link($root)
        ) {
            throw new \RuntimeException('Unable to resolve a safe WLS project root.');
        }
        $realRoot = \realpath($root);
        $rootStatus = \is_string($realRoot) ? @\lstat($realRoot) : false;
        if (!\is_string($realRoot)
            || $realRoot === ''
            || $this->isFilesystemRoot($realRoot)
            || !\is_array($rootStatus)
            || \is_link($realRoot)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Unable to resolve a safe WLS project root.');
        }
        $this->projectRoot = \rtrim($realRoot, '/\\');
        $this->identityFile = $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'wls-project.json';
        $this->hostStateRoot = $hostStateRoot === null
            ? $this->defaultHostStateRoot()
            : $this->normalizeAbsolutePath($hostStateRoot, 'WLS edge host state root');
        $this->legacyDesiredStateFile = $this->normalizeAbsolutePath(
            $legacyDesiredStateFile
                ?? $this->projectRoot . DIRECTORY_SEPARATOR . 'var'
                    . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR
                    . 'gateway-v2' . DIRECTORY_SEPARATOR . 'desired-generation.json',
            'WLS legacy desired-state file',
        );
    }

    public function projectUuid(?float $deadlineMonotonic = null): string
    {
        $state = $this->ensure($deadlineMonotonic);
        $rotation = \is_array($state['rotation'] ?? null) ? $state['rotation'] : [];
        if (\in_array((string)($rotation['phase'] ?? ''), [
            'HOST_COMMITTED',
            'IDENTITY_COMMITTED',
        ], true)) {
            throw new \RuntimeException(
                'WLS project identity rotation is host-committed and requires roll-forward recovery.'
            );
        }
        $this->claimHostIdentity(
            (string)$state['project_uuid'],
            $deadlineMonotonic,
        );
        return (string)$state['project_uuid'];
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * @return array<string,mixed>
     */
    public function ensure(?float $deadlineMonotonic = null): array
    {
        return $this->withProjectLock(
            static fn (array $state): array => [$state, $state],
            $deadlineMonotonic,
        );
    }

    /**
     * Serialize the complete project desired-state build from source discovery
     * through certificate activation, identity generation and renewal intent.
     * Acquiring this lock before reading facts prevents delayed builders from
     * turning an old A snapshot into a newer generation after B committed.
     *
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    public function withDesiredStateBuildLock(
        callable $callback,
        float $waitTimeoutSeconds = 300.0,
    ): mixed
    {
        $directory = $this->ensureProjectIdentityDirectory();
        $lockFile = $directory . DIRECTORY_SEPARATOR . '.wls-desired-state-build.lock';
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockFile,
            static fn (): mixed => $callback(),
            fn ($handle, string $path): mixed => $this->preserveProjectIdentityOwnership(
                $path,
                $handle,
            ),
            waitTimeoutSeconds: $waitTimeoutSeconds,
        );
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}
     */
    public function advanceDesiredState(
        string $digest,
        ?float $deadlineMonotonic = null,
    ): array
    {
        return $this->advanceGeneration('desired', $digest, $deadlineMonotonic);
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}
     */
    public function advanceCertificateState(
        string $digest,
        ?float $deadlineMonotonic = null,
    ): array
    {
        return $this->advanceGeneration('certificate', $digest, $deadlineMonotonic);
    }

    /**
     * Allocate one strictly increasing project-owned instance generation.
     * The durable project counter is the sole authority. The optional second
     * argument is retained only for source compatibility with pre-release WLS
     * 2.0 callers and is deliberately ignored: endpoint/receipt caches are
     * host-derived observations and must never advance project-owned state.
     * The third argument bounds the complete project-lock transaction.
     *
     * @return array{project_uuid:string,instance_id:string,generation:int}
     */
    public function advanceInstanceGeneration(
        string $instanceId,
        int $untrustedObservedFloor = 0,
        ?float $deadlineMonotonic = null,
    ): array {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceId) !== 1) {
            throw new \InvalidArgumentException(
                'WLS project instance generation identity is invalid.',
            );
        }

        unset($untrustedObservedFloor);
        // Start is a serialized lifecycle operation. Unlike ordinary identity
        // reads/mutations it may legitimately wait behind a preceding start
        // until the caller's operation deadline. Do not widen the 250ms cap
        // used by every other project-identity path.
        $operationLockWait = $this->identityDeadlineRemaining($deadlineMonotonic);
        return $this->withProjectLock(function (array $state) use ($instanceId): array {
            $instances = \is_array($state['instances'] ?? null)
                ? $state['instances']
                : [];
            if (!isset($instances[$instanceId]) && \count($instances) >= 256) {
                throw new \RuntimeException(
                    'WLS project instance generation registry reached its fixed limit.',
                );
            }
            $entry = \is_array($instances[$instanceId] ?? null)
                ? $instances[$instanceId]
                : [];
            $current = $entry['generation'] ?? 0;
            if (!\is_int($current) || $current < 0) {
                throw new \RuntimeException(
                    'WLS project instance generation counter is malformed.',
                );
            }
            if ($current >= PHP_INT_MAX) {
                throw new \RuntimeException(
                    'WLS project instance generation exhausted its integer range.',
                );
            }
            $generation = $current + 1;
            $now = \gmdate(DATE_ATOM);
            $instances[$instanceId] = [
                'generation' => $generation,
                'updated_at' => $now,
            ];
            \ksort($instances, SORT_STRING);
            $state['instances'] = $instances;
            $state['updated_at'] = $now;

            return [$state, [
                'project_uuid' => (string)$state['project_uuid'],
                'instance_id' => $instanceId,
                'generation' => $generation,
            ]];
        }, $deadlineMonotonic, false, $operationLockWait);
    }

    /**
     * Explicitly replace a cloned/moved project's identity.
     *
     * @return array{previous_uuid:string,project_uuid:string}
     */
    public function rotate(): array
    {
        throw new \RuntimeException(
            'Direct WLS project identity rotation is retired; use transactional gateway enrollment.'
        );
    }

    /**
     * Serialize enrollment and every project-identity transition. Clone
     * re-keying deliberately removes the copied host credential before the
     * fresh enrollment installs a new one, so no second enrollment/rotation
     * may interleave between those two durable operations.
     *
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    public function withEnrollmentTransitionLock(
        callable $callback,
        float $waitTimeoutSeconds = 5.0,
        ?float $deadlineMonotonic = null,
    ): mixed {
        if (!\is_finite($waitTimeoutSeconds) || $waitTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'WLS enrollment transition lock wait must be positive and finite.',
            );
        }
        $directory = $this->ensureProjectIdentityDirectory();
        $lockFile = $directory . DIRECTORY_SEPARATOR
            . '.wls-enrollment-transition.lock';
        $waitTimeoutSeconds = \min(300.0, $waitTimeoutSeconds);
        if ($deadlineMonotonic !== null) {
            $waitTimeoutSeconds = \min(
                $waitTimeoutSeconds,
                $this->identityDeadlineRemaining($deadlineMonotonic),
            );
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockFile,
            function () use ($callback, $deadlineMonotonic): mixed {
                $this->identityDeadlineRemaining($deadlineMonotonic);
                // The callback may commit host or local state. Never turn a
                // successful after-image into a timeout by checking again on
                // its way out; each nested mutation owns its own deadline.
                return $callback();
            },
            fn ($handle, string $path): mixed => $this->preserveProjectIdentityOwnership(
                $path,
                $handle,
            ),
            waitTimeoutSeconds: $waitTimeoutSeconds,
        );
    }

    /** @return array<string,mixed> */
    public function freshEnrollmentState(
        ?float $deadlineMonotonic = null,
    ): array {
        $state = $this->ensure($deadlineMonotonic);
        return \is_array($state['fresh_enrollment'] ?? null)
            ? $state['fresh_enrollment']
            : [];
    }

    /** @return array<string,mixed> */
    public function lastFreshEnrollmentState(
        ?float $deadlineMonotonic = null,
    ): array {
        $state = $this->ensure($deadlineMonotonic);
        return \is_array($state['last_fresh_enrollment'] ?? null)
            ? $state['last_fresh_enrollment']
            : [];
    }

    /**
     * Authorize cleanup of an atomic credential recovery artifact from durable
     * project facts. Active credentials may belong to any explicitly retained
     * current/transition identity. Pending credentials additionally require an
     * exact current or last-completed rotation ID and its new UUID.
     */
    public function authorizesCredentialRecovery(
        string $projectUuid,
        ?string $rotationId = null,
        ?float $deadlineMonotonic = null,
    ): bool {
        $projectUuid = \strtolower(\trim($projectUuid));
        $rotationId = $rotationId === null
            ? null
            : \strtolower(\trim($rotationId));
        if (!$this->isUuidV4($projectUuid)
            || ($rotationId !== null
                && \preg_match('/\A[a-f0-9]{32}\z/D', $rotationId) !== 1)
        ) {
            throw new \InvalidArgumentException(
                'WLS credential recovery identity fence is invalid.',
            );
        }
        $identityStatus = @\lstat($this->identityFile);
        if (!\is_array($identityStatus)) {
            throw new \RuntimeException(
                'WLS credential recovery requires an existing project identity fact.',
            );
        }

        return $this->withProjectLock(
            static function (array $state) use ($projectUuid, $rotationId): array {
                if ($rotationId !== null) {
                    foreach (['rotation', 'last_rotation'] as $section) {
                        $rotation = \is_array($state[$section] ?? null)
                            ? $state[$section]
                            : [];
                        if ($rotation !== []
                            && \hash_equals(
                                $rotationId,
                                (string)($rotation['rotation_id'] ?? ''),
                            )
                            && \hash_equals(
                                $projectUuid,
                                (string)($rotation['new_project_uuid'] ?? ''),
                            )
                        ) {
                            return [$state, true];
                        }
                    }
                    return [$state, false];
                }

                $authorized = [(string)$state['project_uuid']];
                foreach (['fresh_enrollment', 'last_fresh_enrollment'] as $section) {
                    $transition = \is_array($state[$section] ?? null)
                        ? $state[$section]
                        : [];
                    if ($transition !== []) {
                        $authorized[] = (string)($transition['previous_project_uuid'] ?? '');
                        $authorized[] = (string)($transition['project_uuid'] ?? '');
                    }
                }
                foreach (['rotation', 'last_rotation'] as $section) {
                    $rotation = \is_array($state[$section] ?? null)
                        ? $state[$section]
                        : [];
                    if ($rotation !== []) {
                        $authorized[] = (string)($rotation['old_project_uuid'] ?? '');
                        $authorized[] = (string)($rotation['new_project_uuid'] ?? '');
                    }
                }
                foreach ($authorized as $candidate) {
                    if (\hash_equals($projectUuid, $candidate)) {
                        return [$state, true];
                    }
                }
                return [$state, false];
            },
            $deadlineMonotonic,
            true,
        );
    }

    /**
     * Return a live same-host clone conflict without claiming or mutating the
     * copied UUID. An absent old root is a move, not a clone, and therefore
     * remains eligible to retain the stable identity.
     *
     * @return array<string,mixed>
     */
    public function clonedIdentityConflict(
        ?float $deadlineMonotonic = null,
    ): array {
        // A clone may have copied a same-root rotation journal whose old-claim
        // root is (correctly) foreign to this directory. Read that one field in
        // tolerant mode only long enough to prove the live clone conflict; no
        // ordinary identity or generation operation uses this mode.
        $state = $this->withProjectLock(
            static fn (array $state): array => [$state, $state],
            $deadlineMonotonic,
            true,
        );
        if (\is_array($state['fresh_enrollment'] ?? null)) {
            return [];
        }
        $projectUuid = (string)$state['project_uuid'];
        $claim = $this->hostClaimSnapshot($projectUuid, $deadlineMonotonic);
        if (($claim['exists'] ?? false) !== true
            || $this->sameHostPath(
                (string)($claim['project_root'] ?? ''),
                $this->projectRoot,
            )
        ) {
            return [];
        }
        $claimedRoot = (string)$claim['project_root'];
        $claimedStatus = @\lstat($claimedRoot);
        if (!\is_array($claimedStatus)) {
            if (\file_exists($claimedRoot) || \is_link($claimedRoot)) {
                throw new \RuntimeException(
                    'WLS cloned identity source root is indeterminate or unsafe.',
                );
            }
            return [];
        }
        $this->assertLiveCloneSourceRoot($claimedRoot, $claimedStatus);
        return [
            'schema_version' => 1,
            'old_project_uuid' => $projectUuid,
            'clone_project_root' => $this->projectRoot,
            'source_project_root' => $claimedRoot,
            'source_claim_digest' => (string)$claim['digest'],
        ];
    }

    /**
     * Atomically replace only the clone-local project identity. This operation
     * never releases or rewrites the old host claim and never contacts the
     * Gateway Controller, so the source project's enrollment and routes remain
     * untouched. The recovery marker is cleared only after a fresh enrollment
     * credential has been installed locally.
     *
     * @param array<string,mixed> $conflict
     * @return array<string,mixed>
     */
    public function prepareFreshCloneEnrollment(
        array $conflict,
        ?float $deadlineMonotonic = null,
    ): array {
        $oldProjectUuid = \strtolower(\trim((string)(
            $conflict['old_project_uuid'] ?? ''
        )));
        $sourceProjectRoot = \trim((string)(
            $conflict['source_project_root'] ?? ''
        ));
        $sourceClaimDigest = \strtolower(\trim((string)(
            $conflict['source_claim_digest'] ?? ''
        )));
        if (!$this->isUuidV4($oldProjectUuid)
            || !\hash_equals(
                $this->projectRoot,
                (string)($conflict['clone_project_root'] ?? ''),
            )
            || !$this->validClaimedProjectRoot($sourceProjectRoot)
            || $this->sameHostPath($sourceProjectRoot, $this->projectRoot)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceClaimDigest) !== 1
        ) {
            throw new \InvalidArgumentException(
                'WLS fresh clone enrollment conflict fence is invalid.',
            );
        }
        $prepared = $this->withProjectLock(
            function (array $state) use (
                $oldProjectUuid,
                $sourceProjectRoot,
                $sourceClaimDigest,
                $deadlineMonotonic,
            ): array {
                $existing = \is_array($state['fresh_enrollment'] ?? null)
                    ? $state['fresh_enrollment']
                    : [];
                if ($existing !== []) {
                    if (!\hash_equals(
                            $oldProjectUuid,
                            (string)($existing['previous_project_uuid'] ?? ''),
                        )
                        || !\hash_equals(
                            $sourceClaimDigest,
                            (string)($existing['source_claim_digest'] ?? ''),
                        )
                        || !\hash_equals(
                            $sourceProjectRoot,
                            (string)($existing['source_project_root'] ?? ''),
                        )
                    ) {
                        throw new \RuntimeException(
                            'WLS fresh clone enrollment recovery fence changed.',
                        );
                    }
                    return [$state, $existing];
                }
                if (!\hash_equals(
                        $oldProjectUuid,
                        (string)($state['project_uuid'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'WLS cloned identity changed before fresh enrollment preparation.',
                    );
                }
                $claim = $this->hostClaimSnapshot(
                    $oldProjectUuid,
                    $deadlineMonotonic,
                );
                $claimedStatus = @\lstat($sourceProjectRoot);
                if (($claim['exists'] ?? false) !== true
                    || !\hash_equals(
                        $sourceProjectRoot,
                        (string)($claim['project_root'] ?? ''),
                    )
                    || !\hash_equals(
                        $sourceClaimDigest,
                        (string)($claim['digest'] ?? ''),
                    )
                    || !\is_array($claimedStatus)
                ) {
                    throw new \RuntimeException(
                        'WLS cloned identity source claim changed before local re-key.',
                    );
                }
                $this->assertLiveCloneSourceRoot($sourceProjectRoot, $claimedStatus);
                $now = \gmdate(DATE_ATOM);
                $newProjectUuid = self::uuidV4();
                $freshEnrollment = [
                    'schema_version' => 1,
                    'state' => 'REQUIRED',
                    'previous_project_uuid' => $oldProjectUuid,
                    'project_uuid' => $newProjectUuid,
                    'source_claim_digest' => $sourceClaimDigest,
                    'source_project_root' => $sourceProjectRoot,
                    'prepared_at' => $now,
                    'updated_at' => $now,
                ];
                $next = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'project_uuid' => $newProjectUuid,
                    'desired' => self::emptyGeneration(),
                    'certificate' => self::emptyGeneration(),
                    'instances' => [],
                    'fresh_enrollment' => $freshEnrollment,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                return [$next, $freshEnrollment];
            },
            $deadlineMonotonic,
            true,
        );
        // A crash before this derived claim is harmless: the durable marker
        // makes the next rotate retry claim the same new UUID and continue the
        // fresh enrollment instead of entering host transfer.
        $this->claimHostIdentity(
            (string)$prepared['project_uuid'],
            $deadlineMonotonic,
        );
        return $prepared;
    }

    /** @return array<string,mixed> */
    public function completeFreshEnrollment(
        string $projectUuid,
        ?float $deadlineMonotonic = null,
    ): array {
        $projectUuid = \strtolower(\trim($projectUuid));
        if (!$this->isUuidV4($projectUuid)) {
            throw new \InvalidArgumentException(
                'WLS fresh enrollment project UUID is invalid.',
            );
        }
        return $this->withProjectLock(
            static function (array $state) use ($projectUuid): array {
                $fresh = \is_array($state['fresh_enrollment'] ?? null)
                    ? $state['fresh_enrollment']
                    : [];
                if ($fresh === []) {
                    return [$state, []];
                }
                if (!\hash_equals(
                        $projectUuid,
                        (string)($state['project_uuid'] ?? ''),
                    )
                    || !\hash_equals(
                        $projectUuid,
                        (string)($fresh['project_uuid'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'WLS fresh enrollment completion identity changed.',
                    );
                }
                $completedAt = \gmdate(DATE_ATOM);
                $completed = $fresh;
                $completed['state'] = 'COMMITTED';
                $completed['completed_at'] = $completedAt;
                $completed['updated_at'] = $completedAt;
                unset($state['fresh_enrollment']);
                $state['last_fresh_enrollment'] = $completed;
                $state['updated_at'] = $completedAt;
                return [$state, $completed];
            },
            $deadlineMonotonic,
        );
    }

    /** @return array<string,mixed> */
    public function prepareRotation(?float $deadlineMonotonic = null): array
    {
        return $this->withProjectLock(function (array $state) use (
            $deadlineMonotonic,
        ): array {
            $existing = \is_array($state['rotation'] ?? null) ? $state['rotation'] : [];
            if ($existing !== []) {
                return [$state, $existing];
            }
            $oldUuid = (string)$state['project_uuid'];
            $rotation = [
                'schema_version' => 1,
                'rotation_id' => \bin2hex(\random_bytes(16)),
                'old_project_uuid' => $oldUuid,
                'new_project_uuid' => self::uuidV4(),
                'project_root' => $this->projectRoot,
                'phase' => 'LOCAL_PREPARED',
                'request_digest' => '',
                'idempotency_key' => '',
                'new_credential_id' => '',
                'prepare_receipt_digest' => '',
                'commit_receipt' => null,
                'old_claim' => $this->hostClaimSnapshot(
                    $oldUuid,
                    $deadlineMonotonic,
                ),
                'created_at' => \gmdate(DATE_ATOM),
                'updated_at' => \gmdate(DATE_ATOM),
            ];
            $state['rotation'] = $rotation;
            $state['updated_at'] = (string)$rotation['updated_at'];
            return [$state, $rotation];
        }, $deadlineMonotonic);
    }

    /** @return array<string,mixed> */
    public function recordRotationPrepared(
        string $rotationId,
        string $requestDigest,
        string $idempotencyKey,
        string $newCredentialId,
        string $prepareReceiptDigest,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->updateRotation(
            $rotationId,
            ['LOCAL_PREPARED', 'CONTROLLER_PREPARED'],
            static function (array $rotation) use (
                $requestDigest,
                $idempotencyKey,
                $newCredentialId,
                $prepareReceiptDigest,
            ): array {
                $rotation['phase'] = 'CONTROLLER_PREPARED';
                $rotation['request_digest'] = \strtolower(\trim($requestDigest));
                $rotation['idempotency_key'] = \strtolower(\trim($idempotencyKey));
                $rotation['new_credential_id'] = \strtolower(\trim($newCredentialId));
                $rotation['prepare_receipt_digest'] = \strtolower(\trim(
                    $prepareReceiptDigest,
                ));
                return $rotation;
            },
            $deadlineMonotonic,
        );
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    public function markRotationHostCommitted(
        string $rotationId,
        array $receipt,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->updateRotation(
            $rotationId,
            ['CONTROLLER_PREPARED', 'HOST_COMMITTED'],
            static function (array $rotation) use ($receipt): array {
                $rotation['phase'] = 'HOST_COMMITTED';
                $rotation['commit_receipt'] = $receipt;
                return $rotation;
            },
            $deadlineMonotonic,
        );
    }

    /** @return array<string,mixed> */
    public function commitRotationIdentity(
        string $rotationId,
        ?float $deadlineMonotonic = null,
    ): array {
        $rotation = $this->rotationState($deadlineMonotonic);
        if (!\hash_equals($rotationId, (string)($rotation['rotation_id'] ?? ''))
            || !\in_array((string)($rotation['phase'] ?? ''), [
                'HOST_COMMITTED',
                'IDENTITY_COMMITTED',
                'LOCAL_COMMITTED',
            ], true)
        ) {
            throw new \RuntimeException('WLS project rotation is not host-committed.');
        }
        $newUuid = (string)$rotation['new_project_uuid'];
        $this->claimHostIdentity($newUuid, $deadlineMonotonic);
        return $this->withProjectLock(function (array $state) use (
            $rotationId,
            $newUuid,
        ): array {
            $current = \is_array($state['rotation'] ?? null) ? $state['rotation'] : [];
            $this->assertRotationIdentity($current, $rotationId);
            $phase = (string)($current['phase'] ?? '');
            if (!\in_array($phase, [
                'HOST_COMMITTED',
                'IDENTITY_COMMITTED',
                'LOCAL_COMMITTED',
            ], true)) {
                throw new \RuntimeException(
                    'WLS project rotation cannot switch identity before host commit.'
                );
            }
            if ($phase === 'HOST_COMMITTED') {
                if (!\hash_equals(
                    (string)$current['old_project_uuid'],
                    (string)$state['project_uuid'],
                )) {
                    throw new \RuntimeException(
                        'WLS project rotation old identity changed before local commit.'
                    );
                }
                $state['project_uuid'] = $newUuid;
                $lastFreshEnrollment = \is_array(
                    $state['last_fresh_enrollment'] ?? null
                ) ? $state['last_fresh_enrollment'] : [];
                if ($lastFreshEnrollment !== []) {
                    $lastFreshEnrollment['project_uuid'] = $newUuid;
                    $lastFreshEnrollment['updated_at'] = \gmdate(DATE_ATOM);
                    $state['last_fresh_enrollment'] = $lastFreshEnrollment;
                }
                // Controller transfers anti-rollback generation/digest floors
                // to the new UUID. Preserve the project-owned facts and only
                // re-sign their idempotency key under the new identity.
                foreach (['desired', 'certificate'] as $section) {
                    $generationState = \is_array($state[$section] ?? null)
                        ? $state[$section]
                        : self::emptyGeneration();
                    $generation = (int)($generationState['generation'] ?? 0);
                    $digest = (string)($generationState['digest'] ?? '');
                    $state[$section] = $generation > 0
                        ? [
                            'generation' => $generation,
                            'digest' => $digest,
                            'idempotency_key' => \substr(\hash(
                                'sha256',
                                $newUuid . ':' . $section . ':'
                                    . $generation . ':' . $digest,
                            ), 0, 40),
                        ]
                        : self::emptyGeneration();
                }
                $current['phase'] = 'IDENTITY_COMMITTED';
                $current['identity_committed_at'] = \gmdate(DATE_ATOM);
                $current['updated_at'] = $current['identity_committed_at'];
                $state['rotation'] = $current;
                $state['updated_at'] = $current['updated_at'];
            } elseif (!\hash_equals($newUuid, (string)$state['project_uuid'])) {
                throw new \RuntimeException(
                    'WLS project rotation committed identity is inconsistent.'
                );
            }
            return [$state, $current];
        }, $deadlineMonotonic);
    }

    /** @return array<string,mixed> */
    public function markRotationLocalCommitted(
        string $rotationId,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->updateRotation(
            $rotationId,
            ['IDENTITY_COMMITTED', 'LOCAL_COMMITTED'],
            static function (array $rotation): array {
                $rotation['phase'] = 'LOCAL_COMMITTED';
                $rotation['local_committed_at'] ??= \gmdate(DATE_ATOM);
                return $rotation;
            },
            $deadlineMonotonic,
        );
    }

    /** @return array<string,mixed> */
    public function finalizeRotation(
        string $rotationId,
        ?float $deadlineMonotonic = null,
    ): array {
        $pending = $this->rotationState($deadlineMonotonic);
        $this->assertRotationIdentity($pending, $rotationId);
        if ((string)($pending['phase'] ?? '') !== 'LOCAL_COMMITTED') {
            throw new \RuntimeException(
                'WLS project rotation cannot finalize before local commit.'
            );
        }
        // Release first while the durable rotation journal still exists. A
        // crash after release can safely replay this idempotent step; clearing
        // the journal first would lose the only old-claim recovery fact.
        $this->releaseHostClaim(
            (string)$pending['old_project_uuid'],
            $deadlineMonotonic,
        );
        $result = $this->withProjectLock(function (array $state) use ($rotationId): array {
            $rotation = \is_array($state['rotation'] ?? null) ? $state['rotation'] : [];
            $this->assertRotationIdentity($rotation, $rotationId);
            if ((string)($rotation['phase'] ?? '') !== 'LOCAL_COMMITTED'
                || !\hash_equals(
                    (string)$rotation['new_project_uuid'],
                    (string)$state['project_uuid'],
                )
            ) {
                throw new \RuntimeException(
                    'WLS project rotation cannot finalize before local commit.'
                );
            }
            $finishedAt = \gmdate(DATE_ATOM);
            $state['last_rotation'] = [
                'rotation_id' => (string)$rotation['rotation_id'],
                'old_project_uuid' => (string)$rotation['old_project_uuid'],
                'new_project_uuid' => (string)$rotation['new_project_uuid'],
                'finalized_at' => $finishedAt,
            ];
            unset($state['rotation']);
            $state['updated_at'] = $finishedAt;
            return [$state, $rotation];
        }, $deadlineMonotonic);
        return $result;
    }

    public function abortRotation(
        string $rotationId,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withProjectLock(function (array $state) use ($rotationId): array {
            $rotation = \is_array($state['rotation'] ?? null) ? $state['rotation'] : [];
            $this->assertRotationIdentity($rotation, $rotationId);
            if (!\in_array((string)($rotation['phase'] ?? ''), [
                'LOCAL_PREPARED',
                'CONTROLLER_PREPARED',
            ], true)) {
                throw new \RuntimeException(
                    'Host-committed WLS project rotation can only roll forward.'
                );
            }
            unset($state['rotation']);
            $state['updated_at'] = \gmdate(DATE_ATOM);
            return [$state, null];
        }, $deadlineMonotonic);
    }

    /** @return array<string,mixed> */
    public function rotationState(?float $deadlineMonotonic = null): array
    {
        $state = $this->ensure($deadlineMonotonic);
        return \is_array($state['rotation'] ?? null) ? $state['rotation'] : [];
    }

    public function hostStateRoot(): string
    {
        return $this->hostStateRoot;
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}
     */
    private function advanceGeneration(
        string $section,
        string $digest,
        ?float $deadlineMonotonic,
    ): array
    {
        $digest = \strtolower(\trim($digest));
        if (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \InvalidArgumentException('WLS project state digest must be SHA-256 hexadecimal.');
        }

        return $this->withProjectLock(function (array $state) use ($section, $digest): array {
            $current = \is_array($state[$section] ?? null)
                ? $state[$section]
                : self::emptyGeneration();
            $generation = \max(0, (int)($current['generation'] ?? 0));
            if (!\hash_equals((string)($current['digest'] ?? ''), $digest)) {
                if ($generation >= \PHP_INT_MAX) {
                    throw new \RuntimeException(
                        'WLS project generation exhausted its integer range.'
                    );
                }
                $generation++;
            }
            $idempotencyKey = \substr(\hash(
                'sha256',
                (string)$state['project_uuid'] . ':' . $section . ':' . $generation . ':' . $digest,
            ), 0, 40);
            $next = [
                'generation' => $generation,
                'digest' => $digest,
                'idempotency_key' => $idempotencyKey,
            ];
            if ($current !== $next) {
                $state[$section] = $next;
                $state['updated_at'] = \gmdate(DATE_ATOM);
            }
            return [$state, $next];
        }, $deadlineMonotonic);
    }

    /**
     * @param list<string> $allowedPhases
     * @param callable(array<string,mixed>):array<string,mixed> $mutation
     * @return array<string,mixed>
     */
    private function updateRotation(
        string $rotationId,
        array $allowedPhases,
        callable $mutation,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withProjectLock(function (array $state) use (
            $rotationId,
            $allowedPhases,
            $mutation,
        ): array {
            $rotation = \is_array($state['rotation'] ?? null) ? $state['rotation'] : [];
            $this->assertRotationIdentity($rotation, $rotationId);
            if (!\in_array((string)($rotation['phase'] ?? ''), $allowedPhases, true)) {
                throw new \RuntimeException(
                    'WLS project rotation phase does not allow this transition.'
                );
            }
            $rotation = $mutation($rotation);
            $rotation['updated_at'] = \gmdate(DATE_ATOM);
            $state['rotation'] = $rotation;
            $state['updated_at'] = $rotation['updated_at'];
            return [$state, $rotation];
        }, $deadlineMonotonic);
    }

    /** @param array<string,mixed> $rotation */
    private function assertRotationIdentity(array $rotation, string $rotationId): void
    {
        $rotationId = \strtolower(\trim($rotationId));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $rotationId) !== 1
            || !\hash_equals($rotationId, (string)($rotation['rotation_id'] ?? ''))
        ) {
            throw new \RuntimeException('WLS project rotation identity does not match.');
        }
    }

    /** @return array{exists:bool,digest:string,project_root:string} */
    private function hostClaimSnapshot(
        string $projectUuid,
        ?float $deadlineMonotonic = null,
    ): array {
        $this->identityDeadlineRemaining($deadlineMonotonic);
        $claims = $this->ensureHostStateDirectory('project-identities');
        $claim = $claims . DIRECTORY_SEPARATOR . $projectUuid . '.json';
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $claim . '.lock',
            function () use (
                $claim,
                $projectUuid,
                $deadlineMonotonic,
            ): array {
                $this->identityDeadlineRemaining($deadlineMonotonic);
                $this->cleanupHostClaimRecoveryBackups($claim, $projectUuid);
                $this->identityDeadlineRemaining($deadlineMonotonic);
                $raw = GatewayProjectStateFilesystem::readOptional(
                    $claim,
                    65_536,
                    'WLS host identity claim',
                );
                if ($raw === null) {
                    return ['exists' => false, 'digest' => '', 'project_root' => ''];
                }
                $decoded = $this->decodeHostClaim($raw, $projectUuid);
                return [
                    'exists' => true,
                    'digest' => \hash(
                        'sha256',
                        GatewayClient::canonicalJson($decoded),
                    ),
                    'project_root' => (string)$decoded['project_root'],
                ];
            },
            waitTimeoutSeconds: $this->identityLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    private function cleanupHostClaimRecoveryBackups(
        string $claim,
        string $projectUuid,
    ): void {
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $claim,
            65_536,
            'WLS host identity claim',
            function (string $raw) use ($projectUuid): void {
                $this->decodeHostClaim($raw, $projectUuid);
            },
        );
    }

    /** @return array<string,mixed> */
    private function decodeHostClaim(string $raw, string $projectUuid): array
    {
        $decoded = \json_decode($raw, true);
        $expected = [
            'schema_version',
            'project_uuid',
            'project_root',
            'claimed_at',
            'last_seen_at',
        ];
        $actual = \is_array($decoded) ? \array_keys($decoded) : [];
        \sort($expected, SORT_STRING);
        \sort($actual, SORT_STRING);
        if (!\is_array($decoded)
            || $actual !== $expected
            || ($decoded['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !\is_string($decoded['project_uuid'] ?? null)
            || !\hash_equals($projectUuid, (string)$decoded['project_uuid'])
            || !$this->validClaimedProjectRoot($decoded['project_root'] ?? null)
            || !\is_string($decoded['claimed_at'] ?? null)
            || \strlen((string)$decoded['claimed_at']) > 128
            || \strtotime((string)$decoded['claimed_at']) === false
            || !\is_string($decoded['last_seen_at'] ?? null)
            || \strlen((string)$decoded['last_seen_at']) > 128
            || \strtotime((string)$decoded['last_seen_at']) === false
        ) {
            throw new \RuntimeException('WLS host identity claim is malformed.');
        }
        return $decoded;
    }

    /**
     * @template TResult
     * @param callable(array<string,mixed>):array{0:array<string,mixed>,1:TResult} $callback
     * @return TResult
     */
    private function withProjectLock(
        callable $callback,
        ?float $deadlineMonotonic = null,
        bool $allowForeignRotationClaim = false,
        ?float $lockWaitTimeoutSeconds = null,
    ): mixed
    {
        $directory = $this->ensureProjectIdentityDirectory();
        if (!\is_writable($directory)) {
            throw new \RuntimeException(
                'WLS project identity is missing or not writable: ' . $this->identityFile
            );
        }
        $lockFile = $directory . DIRECTORY_SEPARATOR . '.wls-project.lock';
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockFile,
            function () use (
                $callback,
                $deadlineMonotonic,
                $allowForeignRotationClaim,
            ): mixed {
                $this->identityDeadlineRemaining($deadlineMonotonic);
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $this->identityFile,
                    1_048_576,
                    'WLS project identity',
                    function (string $raw) use ($allowForeignRotationClaim): void {
                        $state = \json_decode($raw, true);
                        if (!\is_array($state)) {
                            throw new \RuntimeException(
                                'WLS project identity recovery target contains invalid JSON.'
                            );
                        }
                        $this->validateState($state, $allowForeignRotationClaim);
                    },
                );
                $identityStatus = @\lstat($this->identityFile);
                $exists = \is_array($identityStatus);
                if (!$exists && (\file_exists($this->identityFile) || \is_link($this->identityFile))) {
                    throw new \RuntimeException('WLS project identity path is indeterminate or unsafe.');
                }
                if ($exists) {
                    $this->preserveProjectIdentityOwnership($this->identityFile);
                }
                $state = $exists ? $this->readStateFile($this->identityFile) : $this->newState();
                $this->identityDeadlineRemaining($deadlineMonotonic);
                // Never let an ordinary generation advance silently normalize a
                // corrupted or weakly typed project identity.  The persisted
                // facts are the protocol authority and must be valid before a
                // caller is allowed to derive the next generation from them.
                $this->validateState($state, $allowForeignRotationClaim);
                [$next, $result] = $callback($state);
                $this->identityDeadlineRemaining($deadlineMonotonic);
                $this->validateState($next, $allowForeignRotationClaim);
                if (!$exists || $next !== $state) {
                    // Deadline gates may prevent a write that has not started;
                    // once atomic publication succeeds the durable generation
                    // is the result and must not be reported as a timeout.
                    $this->identityDeadlineRemaining($deadlineMonotonic);
                    $this->publishJson($this->identityFile, $next);
                }
                return $result;
            },
            fn ($handle, string $path): mixed => $this->preserveProjectIdentityOwnership(
                $path,
                $handle,
            ),
            waitTimeoutSeconds: $lockWaitTimeoutSeconds
                ?? $this->identityLockWaitTimeout($deadlineMonotonic),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function newState(): array
    {
        $now = \gmdate(DATE_ATOM);
        $state = [
            'schema_version' => self::SCHEMA_VERSION,
            'project_uuid' => self::uuidV4(),
            'desired' => self::emptyGeneration(),
            'certificate' => self::emptyGeneration(),
            'instances' => [],
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $legacy = $this->readLegacyDesiredState();
        if ($legacy !== null) {
            $legacy['idempotency_key'] = \substr(\hash(
                'sha256',
                (string)$state['project_uuid'] . ':desired:'
                    . (int)$legacy['generation'] . ':' . (string)$legacy['digest'],
            ), 0, 40);
            $state['desired'] = $legacy;
            $state['migrated_from'] = 'var/server/gateway-v2/desired-generation.json';
        }
        return $state;
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}|null
     */
    private function readLegacyDesiredState(): ?array
    {
        $status = @\lstat($this->legacyDesiredStateFile);
        if (!\is_array($status)) {
            return null;
        }
        $legacy = $this->readStateFile($this->legacyDesiredStateFile, false);
        $generation = \max(0, (int)($legacy['generation'] ?? 0));
        $digest = \strtolower(\trim((string)($legacy['digest'] ?? '')));
        if ($generation < 1 || \preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            return null;
        }
        return [
            'generation' => $generation,
            'digest' => $digest,
            'idempotency_key' => \trim((string)($legacy['idempotency_key'] ?? '')),
        ];
    }

    private function claimHostIdentity(
        string $projectUuid,
        ?float $deadlineMonotonic = null,
    ): void
    {
        $claims = $this->ensureHostStateDirectory('project-identities');
        $claimsStatus = @\lstat($claims);
        if (!\is_array($claimsStatus)
            || \is_link($claims)
            || ((((int)($claimsStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($claims, 0700))
        ) {
            throw new \RuntimeException('WLS host identity claims directory is unsafe.');
        }
        $claim = $claims . DIRECTORY_SEPARATOR . $projectUuid . '.json';
        $lockFile = $claim . '.lock';
        GatewayProjectStateFilesystem::withExclusiveLock(
            $lockFile,
            function () use ($claim, $projectUuid, $deadlineMonotonic): void {
                $this->identityDeadlineRemaining($deadlineMonotonic);
                $this->cleanupHostClaimRecoveryBackups($claim, $projectUuid);
                $raw = GatewayProjectStateFilesystem::readOptional(
                    $claim,
                    65_536,
                    'WLS host identity claim',
                );
                $this->identityDeadlineRemaining($deadlineMonotonic);
                $existing = $raw !== null
                    ? $this->decodeHostClaim($raw, $projectUuid)
                    : [];
                $claimedRoot = \trim((string)($existing['project_root'] ?? ''));
                if ($claimedRoot !== ''
                    && !$this->sameHostPath($claimedRoot, $this->projectRoot)
                ) {
                    $claimedStatus = @\lstat($claimedRoot);
                    if (\is_array($claimedStatus)) {
                        if (\is_link($claimedRoot)
                            || ((((int)($claimedStatus['mode'] ?? 0)) & 0170000) !== 0040000)
                        ) {
                            throw new \RuntimeException(
                                'WLS host identity claim points to a linked or special project root.'
                            );
                        }
                        throw new \RuntimeException(
                            'WLS project UUID ' . $projectUuid . ' is already active at ' . $claimedRoot
                            . '; this copy must use explicit project identity rotation before starting.'
                        );
                    }
                    if (\file_exists($claimedRoot) || \is_link($claimedRoot)) {
                        throw new \RuntimeException(
                            'WLS host identity claim project root is indeterminate or unsafe.'
                        );
                    }
                }
                $now = \gmdate(DATE_ATOM);
                $this->identityDeadlineRemaining($deadlineMonotonic);
                $this->publishJson($claim, [
                    'schema_version' => self::SCHEMA_VERSION,
                    'project_uuid' => $projectUuid,
                    'project_root' => $this->projectRoot,
                    'claimed_at' => (string)($existing['claimed_at'] ?? $now),
                    'last_seen_at' => $now,
                ]);
            },
            waitTimeoutSeconds: $this->identityLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    private function identityDeadlineRemaining(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException('WLS project identity deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException('WLS project identity deadline was exhausted.');
        }
        return $remaining;
    }

    private function identityLockWaitTimeout(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return 0.25;
        }
        return \min(0.25, $this->identityDeadlineRemaining($deadlineMonotonic));
    }

    private function releaseHostClaim(
        string $projectUuid,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->identityDeadlineRemaining($deadlineMonotonic);
        $claims = $this->ensureHostStateDirectory('project-identities');
        $claim = $claims
            . DIRECTORY_SEPARATOR . $projectUuid . '.json';
        $lockFile = $claim . '.lock';
        GatewayProjectStateFilesystem::withExclusiveLock(
            $lockFile,
            function () use ($claim, $projectUuid, $deadlineMonotonic): void {
                $this->identityDeadlineRemaining($deadlineMonotonic);
                $this->cleanupHostClaimRecoveryBackups($claim, $projectUuid);
                $raw = GatewayProjectStateFilesystem::readOptional(
                    $claim,
                    65_536,
                    'WLS host identity claim',
                );
                if ($raw === null) {
                    return;
                }
                $existing = $this->decodeHostClaim($raw, $projectUuid);
                if ($this->sameHostPath(
                    (string)$existing['project_root'],
                    $this->projectRoot,
                )) {
                    GatewayProjectStateFilesystem::removeRegular(
                        $claim,
                        'WLS host identity claim',
                    );
                }
            },
            waitTimeoutSeconds: $this->identityLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function readStateFile(string $file, bool $requireObject = true): array
    {
        $raw = GatewayProjectStateFilesystem::read(
            $file,
            1_048_576,
            'WLS project state',
        );
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            if (!$requireObject) {
                return [];
            }
            throw new \RuntimeException('WLS project state contains invalid JSON: ' . $file);
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function validateState(
        array $state,
        bool $allowForeignRotationClaim = false,
    ): void
    {
        if (($state['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !\is_string($state['project_uuid'] ?? null)
            || \preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                (string)$state['project_uuid'],
            ) !== 1
        ) {
            throw new \RuntimeException('WLS project identity schema or UUID is invalid.');
        }
        foreach (['desired', 'certificate'] as $section) {
            $generation = \is_array($state[$section] ?? null) ? $state[$section] : [];
            $number = $generation['generation'] ?? null;
            $rawDigest = $generation['digest'] ?? null;
            $rawIdempotencyKey = $generation['idempotency_key'] ?? null;
            $digest = \is_string($rawDigest) ? \strtolower(\trim($rawDigest)) : '';
            $idempotencyKey = \is_string($rawIdempotencyKey)
                ? \strtolower(\trim($rawIdempotencyKey))
                : '';
            if (!\is_int($number) || $number < 0
                || !\is_string($rawDigest)
                || !\hash_equals($rawDigest, $digest)
                || !\is_string($rawIdempotencyKey)
                || !\hash_equals($rawIdempotencyKey, $idempotencyKey)
                || ($number === 0 && ($digest !== '' || $idempotencyKey !== ''))
                || ($number > 0
                    && (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                        || \preg_match('/^[a-f0-9]{40}$/D', $idempotencyKey) !== 1))
            ) {
                throw new \RuntimeException('WLS project generation state is invalid: ' . $section);
            }
        }
        $instances = $state['instances'] ?? [];
        if (!\is_array($instances)
            || ($instances !== [] && \array_is_list($instances))
            || \count($instances) > 256
        ) {
            throw new \RuntimeException('WLS project instance generation registry is invalid.');
        }
        foreach ($instances as $instanceId => $entry) {
            if (!\is_string($instanceId)
                || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceId) !== 1
                || !\is_array($entry)
                || \array_keys($entry) !== ['generation', 'updated_at']
                || !\is_int($entry['generation'] ?? null)
                || (int)$entry['generation'] < 1
                || !\is_string($entry['updated_at'] ?? null)
                || \strlen((string)$entry['updated_at']) > 128
                || \strtotime((string)$entry['updated_at']) === false
            ) {
                throw new \RuntimeException(
                    'WLS project instance generation entry is invalid.',
                );
            }
        }
        if (\array_key_exists('fresh_enrollment', $state)) {
            $fresh = $state['fresh_enrollment'];
            $expectedFields = [
                'schema_version',
                'state',
                'previous_project_uuid',
                'project_uuid',
                'source_claim_digest',
                'source_project_root',
                'prepared_at',
                'updated_at',
            ];
            $actualFields = \is_array($fresh) ? \array_keys($fresh) : [];
            \sort($expectedFields, SORT_STRING);
            \sort($actualFields, SORT_STRING);
            if (!\is_array($fresh)
                || $actualFields !== $expectedFields
                || ($fresh['schema_version'] ?? null) !== 1
                || !\hash_equals('REQUIRED', (string)($fresh['state'] ?? ''))
                || !$this->isUuidV4((string)(
                    $fresh['previous_project_uuid'] ?? ''
                ))
                || !$this->isUuidV4((string)($fresh['project_uuid'] ?? ''))
                || \hash_equals(
                    (string)$fresh['previous_project_uuid'],
                    (string)$fresh['project_uuid'],
                )
                || !\hash_equals(
                    (string)$state['project_uuid'],
                    (string)$fresh['project_uuid'],
                )
                || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                    $fresh['source_claim_digest'] ?? ''
                )) !== 1
                || !$this->validClaimedProjectRoot(
                    $fresh['source_project_root'] ?? null,
                )
                || $this->sameHostPath(
                    (string)$fresh['source_project_root'],
                    $this->projectRoot,
                )
                || !\is_string($fresh['prepared_at'] ?? null)
                || \strlen((string)$fresh['prepared_at']) > 128
                || \strtotime((string)$fresh['prepared_at']) === false
                || !\is_string($fresh['updated_at'] ?? null)
                || \strlen((string)$fresh['updated_at']) > 128
                || \strtotime((string)$fresh['updated_at']) === false
                || \array_key_exists('rotation', $state)
            ) {
                throw new \RuntimeException(
                    'WLS fresh clone enrollment recovery state is invalid.',
                );
            }
        }
        if (\array_key_exists('last_fresh_enrollment', $state)) {
            $completed = $state['last_fresh_enrollment'];
            $expectedFields = [
                'schema_version',
                'state',
                'previous_project_uuid',
                'project_uuid',
                'source_claim_digest',
                'source_project_root',
                'prepared_at',
                'completed_at',
                'updated_at',
            ];
            $actualFields = \is_array($completed) ? \array_keys($completed) : [];
            \sort($expectedFields, SORT_STRING);
            \sort($actualFields, SORT_STRING);
            if (!\is_array($completed)
                || $actualFields !== $expectedFields
                || ($completed['schema_version'] ?? null) !== 1
                || !\hash_equals('COMMITTED', (string)(
                    $completed['state'] ?? ''
                ))
                || !$this->isUuidV4((string)(
                    $completed['previous_project_uuid'] ?? ''
                ))
                || !$this->isUuidV4((string)(
                    $completed['project_uuid'] ?? ''
                ))
                || \hash_equals(
                    (string)$completed['previous_project_uuid'],
                    (string)$completed['project_uuid'],
                )
                || !\hash_equals(
                    (string)$state['project_uuid'],
                    (string)$completed['project_uuid'],
                )
                || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                    $completed['source_claim_digest'] ?? ''
                )) !== 1
                || !$this->validClaimedProjectRoot(
                    $completed['source_project_root'] ?? null,
                )
                || $this->sameHostPath(
                    (string)$completed['source_project_root'],
                    $this->projectRoot,
                )
                || !\is_string($completed['prepared_at'] ?? null)
                || \strlen((string)$completed['prepared_at']) > 128
                || \strtotime((string)$completed['prepared_at']) === false
                || !\is_string($completed['completed_at'] ?? null)
                || \strlen((string)$completed['completed_at']) > 128
                || \strtotime((string)$completed['completed_at']) === false
                || !\is_string($completed['updated_at'] ?? null)
                || \strlen((string)$completed['updated_at']) > 128
                || \strtotime((string)$completed['updated_at']) === false
                || \array_key_exists('fresh_enrollment', $state)
            ) {
                throw new \RuntimeException(
                    'WLS completed fresh clone enrollment state is invalid.',
                );
            }
        }
        if (\array_key_exists('last_rotation', $state)) {
            $lastRotation = $state['last_rotation'];
            $expectedFields = [
                'rotation_id',
                'old_project_uuid',
                'new_project_uuid',
                'finalized_at',
            ];
            $actualFields = \is_array($lastRotation)
                ? \array_keys($lastRotation)
                : [];
            \sort($expectedFields, SORT_STRING);
            \sort($actualFields, SORT_STRING);
            if (!\is_array($lastRotation)
                || $actualFields !== $expectedFields
                || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                    $lastRotation['rotation_id'] ?? ''
                )) !== 1
                || !$this->isUuidV4((string)(
                    $lastRotation['old_project_uuid'] ?? ''
                ))
                || !$this->isUuidV4((string)(
                    $lastRotation['new_project_uuid'] ?? ''
                ))
                || \hash_equals(
                    (string)$lastRotation['old_project_uuid'],
                    (string)$lastRotation['new_project_uuid'],
                )
                || !\is_string($lastRotation['finalized_at'] ?? null)
                || \strlen((string)$lastRotation['finalized_at']) > 128
                || \strtotime((string)$lastRotation['finalized_at']) === false
            ) {
                throw new \RuntimeException(
                    'WLS completed project identity rotation fact is invalid.',
                );
            }
        }
        if (\array_key_exists('rotation', $state)) {
            $rotation = $state['rotation'];
            $phase = \is_array($rotation) ? (string)($rotation['phase'] ?? '') : '';
            $oldUuid = \is_array($rotation)
                ? (string)($rotation['old_project_uuid'] ?? '')
                : '';
            $newUuid = \is_array($rotation)
                ? (string)($rotation['new_project_uuid'] ?? '')
                : '';
            $prepared = \in_array($phase, [
                'CONTROLLER_PREPARED',
                'HOST_COMMITTED',
                'IDENTITY_COMMITTED',
                'LOCAL_COMMITTED',
            ], true);
            $identityCommitted = \in_array($phase, [
                'IDENTITY_COMMITTED',
                'LOCAL_COMMITTED',
            ], true);
            if (!\is_array($rotation)
                || ($rotation['schema_version'] ?? null) !== 1
                || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                    $rotation['rotation_id'] ?? ''
                )) !== 1
                || !$this->isUuidV4($oldUuid)
                || !$this->isUuidV4($newUuid)
                || \hash_equals($oldUuid, $newUuid)
                || !$this->validClaimedProjectRoot(
                    $rotation['project_root'] ?? null,
                )
                || (!$allowForeignRotationClaim
                    && !$this->sameHostPath(
                        (string)$rotation['project_root'],
                        $this->projectRoot,
                    ))
                || !\in_array($phase, [
                    'LOCAL_PREPARED',
                    'CONTROLLER_PREPARED',
                    'HOST_COMMITTED',
                    'IDENTITY_COMMITTED',
                    'LOCAL_COMMITTED',
                ], true)
                || ($identityCommitted
                    ? !\hash_equals($newUuid, (string)$state['project_uuid'])
                    : !\hash_equals($oldUuid, (string)$state['project_uuid']))
                || !\is_array($rotation['old_claim'] ?? null)
                || !\is_bool($rotation['old_claim']['exists'] ?? null)
                || !\is_string($rotation['old_claim']['digest'] ?? null)
                || !\is_string($rotation['old_claim']['project_root'] ?? null)
                || (($rotation['old_claim']['exists'] ?? false) === true
                    && (\preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                        $rotation['old_claim']['digest'] ?? ''
                    )) !== 1
                        || !$this->validClaimedProjectRoot(
                            $rotation['old_claim']['project_root'] ?? null,
                        )
                        || (!$allowForeignRotationClaim
                            && !$this->sameHostPath(
                                (string)$rotation['old_claim']['project_root'],
                                $this->projectRoot,
                            ))
                        || ($allowForeignRotationClaim
                            && !$this->sameHostPath(
                                (string)$rotation['old_claim']['project_root'],
                                (string)$rotation['project_root'],
                            ))))
                || (($rotation['old_claim']['exists'] ?? true) === false
                    && ((string)($rotation['old_claim']['digest'] ?? '') !== ''
                        || (string)($rotation['old_claim']['project_root'] ?? '') !== ''))
                || ($prepared
                    && (\preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                        $rotation['request_digest'] ?? ''
                    )) !== 1
                        || \preg_match('/\A[a-f0-9]{40}\z/D', (string)(
                            $rotation['idempotency_key'] ?? ''
                        )) !== 1
                        || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                            $rotation['new_credential_id'] ?? ''
                        )) !== 1
                        || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                            $rotation['prepare_receipt_digest'] ?? ''
                        )) !== 1))
                || (\in_array($phase, [
                    'HOST_COMMITTED',
                    'IDENTITY_COMMITTED',
                    'LOCAL_COMMITTED',
                ], true) && !\is_array($rotation['commit_receipt'] ?? null))
            ) {
                throw new \RuntimeException('WLS project identity rotation journal is invalid.');
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function publishJson(string $file, array $data): void
    {
        $payload = \json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $seal = \hash_equals($this->identityFile, $file)
            ? fn ($handle, string $path): mixed => $this->preserveProjectIdentityOwnership(
                $path,
                $handle,
            )
            : null;
        GatewayProjectStateFilesystem::atomicWrite($file, $payload, 0600, $seal);
    }

    /**
     * Project facts must remain usable by the project owner even when an
     * administrator performs enrollment or legacy promotion. Restrict root
     * ownership repair to the identity file and its private lock/candidate.
     *
     * @param resource|null $handle
     */
    private function preserveProjectIdentityOwnership(string $file, mixed $handle = null): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $lockFile = \dirname($this->identityFile) . DIRECTORY_SEPARATOR
            . '.wls-project.lock';
        $buildLockFile = \dirname($this->identityFile) . DIRECTORY_SEPARATOR
            . '.wls-desired-state-build.lock';
        $enrollmentTransitionLockFile = \dirname($this->identityFile)
            . DIRECTORY_SEPARATOR . '.wls-enrollment-transition.lock';
        if (!\hash_equals($this->identityFile, $file)
            && !\hash_equals($lockFile, $file)
            && !\hash_equals($buildLockFile, $file)
            && !\hash_equals($enrollmentTransitionLockFile, $file)
            && !\str_starts_with($file, $this->identityFile . '.tmp')
        ) {
            throw new \RuntimeException(
                'Refusing to apply project ownership outside WLS project facts.'
            );
        }
        $owner = @\lstat($this->projectRoot);
        $fileStatus = @\lstat($file);
        if (!\is_array($owner)
            || \is_link($this->projectRoot)
            || !\is_int($owner['uid'] ?? null)
            || !\is_int($owner['gid'] ?? null)
            || !\is_array($fileStatus)
            || \is_link($file)
            || ((((int)($fileStatus['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($fileStatus['nlink'] ?? 0) !== 1
        ) {
            throw new \RuntimeException(
                'Unable to establish safe WLS project fact ownership.'
            );
        }
        $uid = (int)$owner['uid'];
        $gid = (int)$owner['gid'];
        $ownerApplied = \is_resource($handle)
            && \function_exists('fchown')
            && @\fchown($handle, $uid);
        if (!$ownerApplied) {
            $ownerApplied = \function_exists('lchown')
                ? @\lchown($file, $uid)
                : @\chown($file, $uid);
        }
        $groupApplied = \is_resource($handle)
            && \function_exists('fchgrp')
            && @\fchgrp($handle, $gid);
        if (!$groupApplied) {
            $groupApplied = \function_exists('lchgrp')
                ? @\lchgrp($file, $gid)
                : @\chgrp($file, $gid);
        }
        $actual = @\lstat($file);
        if (!$ownerApplied
            || !$groupApplied
            || !\is_array($actual)
            || ((((int)($actual['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($actual['nlink'] ?? 0) !== 1
            || (int)($actual['uid'] ?? -1) !== $uid
            || (int)($actual['gid'] ?? -1) !== $gid
        ) {
            throw new \RuntimeException(
                'Unable to preserve the project owner on WLS project facts.'
            );
        }
    }

    private function ensureProjectIdentityDirectory(): string
    {
        $current = $this->projectRoot;
        foreach (['app', 'etc'] as $leaf) {
            $next = $current . DIRECTORY_SEPARATOR . $leaf;
            if (!\is_dir($next) && !@\mkdir($next, 0755) && !\is_dir($next)) {
                throw new \RuntimeException(
                    'WLS project identity directory is unavailable: ' . $next
                );
            }
            $status = @\lstat($next);
            $real = \realpath($next);
            if (!\is_array($status)
                || \is_link($next)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || !\is_string($real)
                || !$this->pathInsideProject($real)
            ) {
                throw new \RuntimeException(
                    'WLS project identity directory escapes the project root: ' . $next
                );
            }
            $current = \rtrim($real, '/\\');
        }
        return $current;
    }

    private function pathInsideProject(string $path): bool
    {
        $normalize = static function (string $value): string {
            $value = \rtrim(\str_replace('\\', '/', $value), '/');
            return \PHP_OS_FAMILY === 'Windows' ? \strtolower($value) : $value;
        };
        $root = $normalize($this->projectRoot);
        $candidate = $normalize($path);
        return $candidate === $root || \str_starts_with($candidate, $root . '/');
    }

    private function validClaimedProjectRoot(mixed $root): bool
    {
        if (!\is_string($root)
            || $root === ''
            || \str_contains($root, "\0")
            || \strlen($root) > 4096
        ) {
            return false;
        }
        return \str_starts_with($root, '/')
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $root) === 1
            || \str_starts_with($root, '\\\\');
    }

    private function sameHostPath(string $left, string $right): bool
    {
        $left = \rtrim(\str_replace('\\', '/', $left), '/');
        $right = \rtrim(\str_replace('\\', '/', $right), '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            $left = \strtolower($left);
            $right = \strtolower($right);
        }
        return \hash_equals($left, $right);
    }

    /** @param array<string,mixed> $status */
    private function assertLiveCloneSourceRoot(string $root, array $status): void
    {
        if ($root === ''
            || !$this->validClaimedProjectRoot($root)
            || $this->sameHostPath($root, $this->projectRoot)
            || \is_link($root)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'WLS cloned identity source root is linked, special, or not foreign.',
            );
        }
    }

    private function defaultHostStateRoot(): string
    {
        $override = \getenv('WLS_EDGE_STATE_HOME');
        if ($override !== false && \trim((string)$override) !== '') {
            return $this->normalizeAbsolutePath((string)$override, 'WLS_EDGE_STATE_HOME');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $base = (string)(\getenv('LOCALAPPDATA') ?: \getenv('PROGRAMDATA') ?: '');
            if (\trim($base) === '') {
                throw new \RuntimeException('WLS edge state requires LOCALAPPDATA or PROGRAMDATA.');
            }
            return \rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'Weline'
                . DIRECTORY_SEPARATOR . 'WlsEdge' . DIRECTORY_SEPARATOR . 'v2';
        }
        $stateHome = (string)(\getenv('XDG_STATE_HOME') ?: '');
        if (\trim($stateHome) === '') {
            $userHome = (string)(\getenv('HOME') ?: '');
            if (\trim($userHome) === '') {
                throw new \RuntimeException('WLS edge state requires HOME or XDG_STATE_HOME.');
            }
            $stateHome = \rtrim($userHome, '/\\') . DIRECTORY_SEPARATOR . '.local'
                . DIRECTORY_SEPARATOR . 'state';
        }
        return \rtrim($stateHome, '/\\') . DIRECTORY_SEPARATOR . 'weline'
            . DIRECTORY_SEPARATOR . 'wls-edge' . DIRECTORY_SEPARATOR . 'v2';
    }

    private function normalizeAbsolutePath(string $path, string $label): string
    {
        if (\str_contains($path, "\0") || \strlen($path) > 4096) {
            throw new \RuntimeException($label . ' contains a null byte.');
        }
        $path = \trim($path);
        $absolute = \str_starts_with($path, '/')
            || \preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || \str_starts_with($path, '\\\\');
        if (!$absolute
            || $this->isFilesystemRoot($path)
            || \in_array('..', \preg_split('#[\\\\/]+#', $path) ?: [], true)
        ) {
            throw new \RuntimeException($label . ' must be absolute and must not contain traversal.');
        }
        return \rtrim($path, '/\\');
    }

    private function isFilesystemRoot(string $path): bool
    {
        $normalized = \str_replace('\\', '/', \trim($path));
        if (\preg_match('#\A/+\z#D', $normalized) === 1) {
            return true;
        }
        $normalized = \rtrim($normalized, '/');
        return \preg_match('/\A[A-Za-z]:\z/D', $normalized) === 1
            || \preg_match('#\A//(?![?.](?:/|\z))[^/]+(?:/[^/]+)?\z#D', $normalized) === 1
            || \preg_match('#\A//[?.]/[A-Za-z]:\z#Di', $normalized) === 1
            || \preg_match('#\A//[?.]/UNC(?:/[^/]+(?:/[^/]+)?)?\z#Di', $normalized) === 1
            || \preg_match('#\A//[?.]/Volume\{[0-9A-Fa-f-]+\}\z#Di', $normalized) === 1;
    }

    private function ensureHostStateDirectory(string $leaf): string
    {
        if (\preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $leaf) !== 1) {
            throw new \InvalidArgumentException('WLS host state directory name is invalid.');
        }
        $root = $this->ensureAbsoluteDirectory($this->hostStateRoot);
        $directory = $root . DIRECTORY_SEPARATOR . $leaf;
        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory)
                || \is_link($directory)
                || !@\mkdir($directory, 0700)
            ) {
                throw new \RuntimeException('Unable to create WLS host identity claims directory.');
            }
            $status = @\lstat($directory);
        }
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('WLS host identity claims directory is unsafe.');
        }
        return $directory;
    }

    private function ensureAbsoluteDirectory(string $directory): string
    {
        $directory = $this->normalizeAbsolutePath($directory, 'WLS edge host state root');
        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException('WLS edge host state root is unsafe.');
            }
            $parent = \dirname($directory);
            if ($parent === $directory) {
                throw new \RuntimeException('WLS edge host state root has no safe parent.');
            }
            $this->ensureAbsoluteDirectory($parent);
            if (!@\mkdir($directory, 0700)) {
                throw new \RuntimeException('Unable to create WLS edge host state root.');
            }
            $status = @\lstat($directory);
        }
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('WLS edge host state root is unsafe.');
        }
        $real = \realpath($directory);
        if (!\is_string($real)
            || $real === ''
            || !$this->sameHostPath($directory, $real)
        ) {
            throw new \RuntimeException('Unable to resolve WLS edge host state root.');
        }
        return \rtrim($real, '/\\');
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}
     */
    private static function emptyGeneration(): array
    {
        return ['generation' => 0, 'digest' => '', 'idempotency_key' => ''];
    }

    private function isUuidV4(string $uuid): bool
    {
        return \preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            \strtolower(\trim($uuid)),
        ) === 1;
    }

    private static function uuidV4(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $hex = \bin2hex($bytes);
        return \substr($hex, 0, 8) . '-' . \substr($hex, 8, 4) . '-'
            . \substr($hex, 12, 4) . '-' . \substr($hex, 16, 4) . '-'
            . \substr($hex, 20, 12);
    }
}
