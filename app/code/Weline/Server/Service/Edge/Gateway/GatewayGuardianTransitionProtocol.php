<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Durable PHP side of the Recovery Guardian generation transition.
 *
 * PHP may request an exact transition, but it cannot declare that transition
 * committed. Only a Guardian-authenticated acknowledgement bound to a STABLE
 * generation-head after-image releases the rebootstrap terminal receipt.
 */
final class GatewayGuardianTransitionProtocol
{
    private const MAX_BYTES = 4096;
    private const MAX_INVENTORY_BYTES = 4_194_304;
    private const ZERO_64 = '0000000000000000000000000000000000000000000000000000000000000000';
    private const RECOVERY_CATEGORIES = [
        'state' => [
            'policy' => 'restore',
            'root_id' => 'host/state',
            'authority_profile' => 'controller-private-v2',
            'authority_policy' => 'controller-private-v2-preserve-identity',
            'parent_authority_profile' => 'host-root-controller-search-v2',
            'parent_authority_policy' => 'host-root-controller-search-v2-fixed-parent',
        ],
        'trust' => [
            'policy' => 'restore',
            'root_id' => 'host/trust',
            'authority_profile' => 'root-controller-read-v2',
            'authority_policy' => 'root-controller-read-v2-preserve-identity',
            'parent_authority_profile' => 'host-root-controller-search-v2',
            'parent_authority_policy' => 'host-root-controller-search-v2-fixed-parent',
        ],
        'snapshots' => [
            'policy' => 'restore',
            'root_id' => 'host/snapshots-v1',
            'authority_profile' => 'controller-data-plane-search-v2',
            'authority_policy' => 'controller-data-plane-search-v2-recreate-sealed',
            'parent_authority_profile' => 'host-root-controller-search-v2',
            'parent_authority_policy' => 'host-root-controller-search-v2-fixed-parent',
        ],
        'snapshots-v2' => [
            'policy' => 'restore',
            'root_id' => 'host/snapshots-v2',
            'authority_profile' => 'root-data-plane-search-v2',
            'authority_policy' => 'root-data-plane-search-v2-recreate-sealed',
            'parent_authority_profile' => 'host-root-controller-search-v2',
            'parent_authority_policy' => 'host-root-controller-search-v2-fixed-parent',
        ],
        'snapshot-candidates-v2' => [
            'policy' => 'restore',
            'root_id' => 'host/snapshot-candidates-v2',
            'authority_profile' => 'controller-snapshot-candidates-private-v2',
            'authority_policy' => 'controller-snapshot-candidates-private-v2-recreate-sealed',
            'parent_authority_profile' => 'host-root-controller-search-v2',
            'parent_authority_policy' => 'host-root-controller-search-v2-fixed-parent',
        ],
        'runtime-conf' => [
            'policy' => 'restore',
            'root_id' => 'host/runtime/conf',
            'authority_profile' => 'controller-runtime-child-v2',
            'authority_policy' => 'controller-runtime-child-v2-recreate-sealed',
            'parent_authority_profile' => 'controller-data-plane-runtime-v2',
            'parent_authority_policy' => 'controller-data-plane-runtime-v2-fixed-parent',
        ],
        'runtime-temp' => [
            'policy' => 'ephemeral',
            'root_id' => 'host/runtime/temp',
            'authority_profile' => 'controller-runtime-child-v2',
            'authority_policy' => 'controller-runtime-child-v2-recreate-sealed',
            'parent_authority_profile' => 'controller-data-plane-runtime-v2',
            'parent_authority_policy' => 'controller-data-plane-runtime-v2-fixed-parent',
        ],
        'runtime-shadow' => [
            'policy' => 'ephemeral',
            'root_id' => 'host/runtime/shadow',
            'authority_profile' => 'controller-runtime-child-v2',
            'authority_policy' => 'controller-runtime-child-v2-recreate-sealed',
            'parent_authority_profile' => 'controller-data-plane-runtime-v2',
            'parent_authority_policy' => 'controller-data-plane-runtime-v2-fixed-parent',
        ],
        'runtime-run' => [
            'policy' => 'ephemeral',
            'root_id' => 'host/runtime/run',
            'authority_profile' => 'controller-runtime-child-v2',
            'authority_policy' => 'controller-runtime-child-v2-recreate-sealed',
            'parent_authority_profile' => 'controller-data-plane-runtime-v2',
            'parent_authority_policy' => 'controller-data-plane-runtime-v2-fixed-parent',
        ],
    ];

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly ?GatewayGuardianGenerationHead $head = null,
    ) {
    }

    /**
     * Publish the exact signed transition before the candidate observation
     * becomes externally committable. Replays are idempotent.
     *
     * @param array<string,mixed> $journal
     * @return array<string,mixed>
     */
    public function beginCandidateObservation(array $journal): array
    {
        if (!\hash_equals('OBSERVING', (string)($journal['phase'] ?? ''))) {
            throw new \RuntimeException(
                'Recovery Guardian candidate observation requires OBSERVING.',
            );
        }
        return $this->ensureCommitRequest($journal);
    }

    /** @param array<string,mixed> $journal */
    public function assertCommitAcknowledged(
        array $journal,
        ?float $deadlineMonotonic = null,
    ): void
    {
        $requestFile = $this->paths->guardianTransitionRequestFile();
        $ackFile = $this->paths->guardianTransitionAcknowledgementFile();
        $phase = (string)($journal['phase'] ?? '');
        if (\hash_equals('OBSERVING', $phase)) {
            $request = $this->ensureCommitRequest($journal);
            $requestRaw = GatewayProjectStateFilesystem::read(
                $requestFile,
                self::MAX_BYTES,
                'Recovery Guardian transition request',
            );
        } elseif (\hash_equals('COMMITTED', $phase)) {
            $this->recoverHandshakeArtifacts();
            $requestRaw = GatewayProjectStateFilesystem::read(
                $requestFile,
                self::MAX_BYTES,
                'Recovery Guardian transition request',
            );
            $request = $this->decodeRequest($requestRaw);
            $immutable = $this->requestGenerationDescriptor($journal);
            unset($immutable['journal_sha256']);
            foreach ($immutable as $field => $expected) {
                if (!\hash_equals((string)$expected, (string)($request[$field] ?? ''))) {
                    throw new \RuntimeException(
                        'Recovery Guardian terminal replay belongs to another generation.',
                    );
                }
            }
        } else {
            throw new \RuntimeException(
                'Recovery Guardian commit validation requires OBSERVING or COMMITTED.',
            );
        }
        $deadlineMonotonic ??= self::monotonicNow() + 310.0;
        for (;;) {
            $this->recoverHandshakeArtifacts();
            $ackRaw = GatewayProjectStateFilesystem::readOptional(
                $ackFile,
                self::MAX_BYTES,
                'Recovery Guardian transition acknowledgement',
            );
            if ($ackRaw === null) {
                $head = $this->generationHead()->read();
                $headPhase = (string)($head['phase'] ?? '');
                if (\in_array($headPhase, [
                    'ROLLBACK_PENDING',
                    'ROLLBACK_OBSERVING',
                    'FAILED_CLOSED',
                ], true)) {
                    throw new \RuntimeException(
                        'Recovery Guardian rejected the candidate generation with phase '
                            . $headPhase . '.',
                    );
                }
                if (\hash_equals('COMMITTED', $phase)
                    || self::monotonicNow() >= $deadlineMonotonic
                ) {
                    throw new \RuntimeException(
                        'Recovery Guardian did not acknowledge a stable generation before the bounded deadline.',
                    );
                }
                \usleep(200_000);
                continue;
            }
            $ack = $this->decodeAcknowledgement($ackRaw);
            $candidateGeneration = (string)$request['candidate_generation_id'];
            foreach ([
                'host_id' => (string)$request['host_id'],
                'nonce' => (string)$request['nonce'],
                'request_sha256' => \hash('sha256', $requestRaw),
                'purpose' => 'commit',
                'phase' => 'STABLE',
                'active_generation_id' => $candidateGeneration,
            ] as $field => $expected) {
                if (!\hash_equals($expected, (string)($ack[$field] ?? ''))) {
                    throw new \RuntimeException(
                        'Recovery Guardian acknowledgement does not bind the requested generation.',
                    );
                }
            }
            $head = $this->generationHead()->read();
            if ($head === null
                || !\hash_equals('STABLE', (string)($head['phase'] ?? ''))
                || !\hash_equals(
                    $candidateGeneration,
                    (string)($head['active_generation_id'] ?? ''),
                )
                || (int)($head['sequence'] ?? 0)
                    !== (int)$ack['committed_head_sequence']
                || !\hash_equals(
                    (string)($head['record_sha256'] ?? ''),
                    (string)$ack['committed_head_sha256'],
                )
                || (int)$ack['committed_head_sequence']
                    <= (int)$request['expected_head_sequence']
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian acknowledgement has no exact stable generation-head after-image.',
                );
            }
            if (!\hash_equals(
                    $requestRaw,
                    GatewayProjectStateFilesystem::read(
                        $requestFile,
                        self::MAX_BYTES,
                        'Recovery Guardian transition request recheck',
                    ),
                )
                || !\hash_equals(
                    $ackRaw,
                    GatewayProjectStateFilesystem::read(
                        $ackFile,
                        self::MAX_BYTES,
                        'Recovery Guardian transition acknowledgement recheck',
                    ),
                )
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian transition evidence changed during commit validation.',
                );
            }
            return;
        }
    }

    /**
     * Durably revoke the candidate before PHP starts any destructive rollback
     * work.  If PHP subsequently disappears, the immutable Guardian can
     * replay the exact authorization and finish restoring the retained image.
     *
     * @param array<string,mixed> $journal
     */
    public function requestRollback(array $journal): void
    {
        if (!\in_array((string)($journal['phase'] ?? ''), [
            'ROLLING_BACK',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
        ], true)) {
            throw new \RuntimeException(
                'Recovery Guardian rollback requests require an active rollback.',
            );
        }
        $nonce = $this->normalizeHex(
            (string)($journal['nonce'] ?? ''),
            32,
            'rebootstrap nonce',
        );
        GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->guardianGenerationHeadLockFile(),
            function () use ($nonce): void {
                $this->recoverRecoveryEvidenceArtifactsWhileLocked($nonce);
            },
        );
        $this->recoverHandshakeArtifacts();
        $requestRaw = GatewayProjectStateFilesystem::readOptional(
            $this->paths->guardianTransitionRequestFile(),
            self::MAX_BYTES,
            'Recovery Guardian transition request',
        );
        if ($requestRaw === null) {
            if ((string)($journal['old_derived_manifest_sha256'] ?? '') === '') {
                // No old generation has been stashed; the current stable head
                // and live files are already the rollback after-image.
                return;
            }
            $this->ensureCommitRequest($journal);
            $requestRaw = GatewayProjectStateFilesystem::read(
                $this->paths->guardianTransitionRequestFile(),
                self::MAX_BYTES,
                'Recovery Guardian transition request',
            );
        }
        $request = $this->decodeRequest($requestRaw);
        $immutable = $this->requestGenerationDescriptor($journal);
        unset($immutable['journal_sha256']);
        foreach ($immutable as $field => $expected) {
            if (!\hash_equals(
                (string)$expected,
                (string)($request[$field] ?? ''),
            )) {
                throw new \RuntimeException(
                    'Recovery Guardian rollback request belongs to another generation.',
                );
            }
        }
        $this->assertRecoveryEvidence($request);

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $head = $this->generationHead()->read();
            if ($head === null
                || !\hash_equals(
                    (string)$request['host_id'],
                    (string)($head['host_id'] ?? ''),
                )
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian generation head is unavailable for rollback.',
                );
            }
            $phase = (string)$head['phase'];
            if (\hash_equals('FAILED_CLOSED', $phase)) {
                throw new \RuntimeException(
                    'Recovery Guardian is failed closed for this rollback.',
                );
            }
            if (\in_array($phase, [
                'ROLLBACK_PENDING',
                'ROLLBACK_OBSERVING',
            ], true)) {
                $this->assertHeadRecoveryBinding($head, $request);
                return;
            }
            if (\hash_equals('STABLE', $phase)
                && (int)$head['sequence'] > (int)$request['expected_head_sequence']
                && \hash_equals(
                    (string)$request['recovery_generation_id'],
                    (string)$head['active_generation_id'],
                )
            ) {
                return;
            }
            if (!\in_array($phase, [
                'STABLE',
                'PROBATIONARY_COMMITTED',
            ], true)) {
                throw new \RuntimeException(
                    'Recovery Guardian generation head cannot enter rollback from '
                        . $phase . '.',
                );
            }
            if (\hash_equals('PROBATIONARY_COMMITTED', $phase)) {
                $this->assertHeadCandidateBinding($head, $request);
            } elseif (!((int)$head['sequence'] === (int)$request['expected_head_sequence']
                    && \hash_equals(
                        (string)$request['expected_head_sha256'],
                        (string)($head['record_sha256'] ?? ''),
                    )
                    && \hash_equals(
                        (string)$request['recovery_generation_id'],
                        (string)$head['active_generation_id'],
                    ))
                && !\hash_equals(
                    (string)$request['candidate_generation_id'],
                    (string)$head['active_generation_id'],
                )
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian stable head is not the requested candidate or recovery generation.',
                );
            }
            try {
                $this->generationHead()->transition((int)$head['sequence'], [
                    'host_id' => (string)$request['host_id'],
                    'phase' => 'ROLLBACK_PENDING',
                    'active_generation_id' => (string)$request['candidate_generation_id'],
                    'active_launcher_sha256' => (string)$request['candidate_launcher_sha256'],
                    'active_ca_sha256' => (string)$request['candidate_ca_sha256'],
                    'active_runtime_generation' => (string)$request['candidate_runtime_generation'],
                    'recovery_generation_id' => (string)$request['recovery_generation_id'],
                    'recovery_nonce' => (string)$request['nonce'],
                    'recovery_authorization_sha256'
                        => (string)$request['recovery_authorization_sha256'],
                    'host_boot_id' => (string)$head['host_boot_id'],
                    'probation_started_monotonic_ms' => 0,
                    'probation_deadline_monotonic_ms' => 0,
                ]);
                return;
            } catch (\RuntimeException $throwable) {
                if ($attempt === 3
                    || !\str_contains($throwable->getMessage(), 'stale expected sequence')
                ) {
                    throw $throwable;
                }
            }
        }
    }

    /** @param array<string,mixed> $journal */
    public function assertRollbackAcknowledged(
        array $journal,
        ?float $deadlineMonotonic = null,
    ): void {
        if (!\in_array((string)($journal['phase'] ?? ''), [
            'ROLLBACK_OBSERVING',
            'ROLLED_BACK',
        ], true)) {
            throw new \RuntimeException(
                'Recovery Guardian rollback validation requires ROLLBACK_OBSERVING or ROLLED_BACK.',
            );
        }
        $this->recoverHandshakeArtifacts();
        $requestRaw = GatewayProjectStateFilesystem::readOptional(
            $this->paths->guardianTransitionRequestFile(),
            self::MAX_BYTES,
            'Recovery Guardian transition request',
        );
        if ($requestRaw === null) {
            $oldSlot = (string)($journal['old_active_slot'] ?? '');
            $old = \is_array($journal['old_slots'][$oldSlot] ?? null)
                ? $journal['old_slots'][$oldSlot]
                : null;
            $head = $this->generationHead()->read();
            $expected = $old === null ? ''
                : GatewayGuardianGenerationHead::generationId(
                    (string)($journal['old_launcher_sha256'] ?? ''),
                    (string)($journal['old_ca_bundle_sha256'] ?? ''),
                    (string)($old['runtime_generation'] ?? ''),
                );
            if ($expected === ''
                || $head === null
                || !\hash_equals('STABLE', (string)($head['phase'] ?? ''))
                || !\hash_equals(
                    $expected,
                    (string)($head['active_generation_id'] ?? ''),
                )
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian has no stable old-generation rollback after-image.',
                );
            }
            return;
        }
        $request = $this->decodeRequest($requestRaw);
        $this->assertRecoveryEvidence($request);
        $deadlineMonotonic ??= self::monotonicNow() + 310.0;
        for (;;) {
            $this->recoverHandshakeArtifacts();
            $ackRaw = GatewayProjectStateFilesystem::readOptional(
                $this->paths->guardianTransitionAcknowledgementFile(),
                self::MAX_BYTES,
                'Recovery Guardian transition acknowledgement',
            );
            if ($ackRaw !== null) {
                $ack = $this->decodeAcknowledgement($ackRaw);
                foreach ([
                    'host_id' => (string)$request['host_id'],
                    'nonce' => (string)$request['nonce'],
                    'request_sha256' => \hash('sha256', $requestRaw),
                    'purpose' => 'rollback',
                    'phase' => 'STABLE',
                    'active_generation_id'
                        => (string)$request['recovery_generation_id'],
                ] as $field => $expected) {
                    if (!\hash_equals($expected, (string)($ack[$field] ?? ''))) {
                        throw new \RuntimeException(
                            'Recovery Guardian rollback acknowledgement is not exact.',
                        );
                    }
                }
                $head = $this->generationHead()->read();
                if ($head !== null
                    && \hash_equals('STABLE', (string)$head['phase'])
                    && \hash_equals(
                        (string)$request['recovery_generation_id'],
                        (string)$head['active_generation_id'],
                    )
                    && (int)$head['sequence'] === (int)$ack['committed_head_sequence']
                    && \hash_equals(
                        (string)($head['record_sha256'] ?? ''),
                        (string)$ack['committed_head_sha256'],
                    )
                    && (int)$ack['committed_head_sequence']
                        > (int)$request['expected_head_sequence']
                ) {
                    return;
                }
                throw new \RuntimeException(
                    'Recovery Guardian rollback acknowledgement has no exact stable recovery after-image.',
                );
            }
            $head = $this->generationHead()->read();
            if (\hash_equals(
                'FAILED_CLOSED',
                (string)($head['phase'] ?? ''),
            )) {
                throw new \RuntimeException(
                    'Recovery Guardian failed closed during rollback.',
                );
            }
            if ((string)$journal['phase'] === 'ROLLED_BACK'
                || self::monotonicNow() >= $deadlineMonotonic
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian did not acknowledge the stable recovery generation before the bounded deadline.',
                );
            }
            \usleep(200_000);
        }
    }

    /** @param array<string,mixed> $journal */
    public function retireHandshake(array $journal): void
    {
        $hostId = $this->normalizeHex(
            (string)($journal['host_id'] ?? ''),
            32,
            'host identity',
        );
        $nonce = $this->normalizeHex(
            (string)($journal['nonce'] ?? ''),
            32,
            'rebootstrap nonce',
        );
        GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->guardianGenerationHeadLockFile(),
            function () use ($hostId, $nonce): void {
                $this->recoverHandshakeArtifactsWhileLocked();
                if ($this->resumeHandshakeRetirementWhileLocked(
                    $hostId,
                    $nonce,
                )) {
                    return;
                }
                $requestRaw = GatewayProjectStateFilesystem::readOptional(
                    $this->paths->guardianTransitionRequestFile(),
                    self::MAX_BYTES,
                    'Recovery Guardian transition request',
                );
                if ($requestRaw === null) {
                    $this->retireOrphanedTerminalHandshakeWhileLocked(
                        $hostId,
                        $nonce,
                    );
                    return;
                }
                $request = $this->decodeRequest($requestRaw);
                if (!\hash_equals($hostId, (string)$request['host_id'])
                    || !\hash_equals($nonce, (string)$request['nonce'])
                ) {
                    throw new \RuntimeException(
                        'Recovery Guardian handshake belongs to another transaction.',
                    );
                }
                $retirement = $this->terminalHandshakeRetirement(
                    $requestRaw,
                    $request,
                );
                $this->publishHandshakeRetirementWhileLocked($retirement);
                if (!$this->resumeHandshakeRetirementWhileLocked(
                    $hostId,
                    $nonce,
                )) {
                    throw new \RuntimeException(
                        'Recovery Guardian terminal handshake retirement was not durable.',
                    );
                }
            },
        );
    }

    /** @param array<string,mixed> $journal @return array<string,mixed> */
    private function ensureCommitRequest(array $journal): array
    {
        if (!\in_array((string)($journal['phase'] ?? ''), [
            'OBSERVING',
            'ROLLING_BACK',
        ], true)) {
            throw new \RuntimeException(
                'Recovery Guardian transition requests require OBSERVING or ROLLING_BACK.',
            );
        }
        $immutable = $this->requestGenerationDescriptor($journal);
        $file = $this->paths->guardianTransitionRequestFile();
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->guardianGenerationHeadLockFile(),
            function () use ($file, $immutable, $journal): array {
                $this->recoverRecoveryEvidenceArtifactsWhileLocked(
                    (string)$immutable['nonce'],
                );
                $this->recoverHandshakeArtifactsWhileLocked();
                $this->resumeHandshakeRetirementWhileLocked();
                $existing = GatewayProjectStateFilesystem::readOptional(
                    $file,
                    self::MAX_BYTES,
                    'Recovery Guardian transition request',
                );
                if ($existing !== null) {
                    $request = $this->decodeRequest($existing);
                    $sameRequest = true;
                    foreach ($immutable as $field => $expected) {
                        if (!\hash_equals(
                            (string)$expected,
                            (string)($request[$field] ?? ''),
                        )) {
                            $sameRequest = false;
                            break;
                        }
                    }
                    if ($sameRequest) {
                        $this->assertRecoveryEvidence($request);
                        return $request;
                    }
                    if (!$this->retireCompletedHandshakeForNextRequestWhileLocked()) {
                        throw new \RuntimeException(
                            'Recovery Guardian transition request changed before publication.',
                        );
                    }
                    $existing = GatewayProjectStateFilesystem::readOptional(
                        $file,
                        self::MAX_BYTES,
                        'Retired Recovery Guardian transition request',
                    );
                    if ($existing !== null) {
                        throw new \RuntimeException(
                            'Recovery Guardian terminal transition request was not retired.',
                        );
                    }
                }
                $this->retireOrphanedTerminalHandshakeWhileLocked();
                $head = $this->generationHead()->readWhileLocked();
                if ($head === null
                    || !\hash_equals('STABLE', (string)($head['phase'] ?? ''))
                    || !\hash_equals(
                        (string)$immutable['recovery_generation_id'],
                        (string)($head['active_generation_id'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$immutable['recovery_launcher_sha256'],
                        (string)($head['active_launcher_sha256'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$immutable['recovery_ca_sha256'],
                        (string)($head['active_ca_sha256'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$immutable['recovery_runtime_generation'],
                        (string)($head['active_runtime_generation'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'Recovery Guardian generation head cannot authorize this rebootstrap.',
                    );
                }
                $request = $immutable + [
                    'expected_head_sequence' => (int)$head['sequence'],
                    'expected_head_sha256' => $this->normalizeHex(
                        (string)($head['record_sha256'] ?? ''),
                        64,
                        'generation-head digest',
                    ),
                ];
                $inventory = $this->ensureRecoveryInventory(
                    $journal,
                    $request,
                );
                $request['recovery_inventory_sha256'] = \hash(
                    'sha256',
                    $inventory,
                );
                $request['request_binding_sha256'] = \hash(
                    'sha256',
                    $this->encodeRequestBinding($request),
                );
                $authorization = $this->ensureRecoveryAuthorization(
                    $request,
                );
                $request['recovery_authorization_sha256'] = \hash(
                    'sha256',
                    $authorization,
                );
                $unsigned = $this->encodeRequestUnsigned($request);
                $request['signature'] = $this->signature($unsigned);
                $encoded = $unsigned . 'signature=' . $request['signature'] . "\n";
                if (\strlen($encoded) > self::MAX_BYTES) {
                    throw new \RuntimeException(
                        'Recovery Guardian transition request exceeds its fixed limit.',
                    );
                }
                GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600);
                $published = GatewayProjectStateFilesystem::read(
                    $file,
                    self::MAX_BYTES,
                    'Published Recovery Guardian transition request',
                );
                if (!\hash_equals($encoded, $published)) {
                    throw new \RuntimeException(
                        'Recovery Guardian transition request changed during publication.',
                    );
                }
                $decoded = $this->decodeRequest($published);
                $this->assertRecoveryEvidence($decoded);
                return $decoded;
            },
        );
    }

    private function recoverHandshakeArtifacts(): void
    {
        GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->guardianGenerationHeadLockFile(),
            function (): void {
                $this->recoverHandshakeArtifactsWhileLocked();
            },
        );
    }

    private function recoverHandshakeArtifactsWhileLocked(): void
    {
        foreach ([
            $this->paths->guardianTransitionRequestFile() => [
                'label' => 'Recovery Guardian transition request',
                'maximum' => self::MAX_BYTES,
                'validator' => function (string $raw): void {
                    $this->decodeRequest($raw);
                },
            ],
            $this->paths->guardianTransitionAcknowledgementFile() => [
                'label' => 'Recovery Guardian transition acknowledgement',
                'maximum' => self::MAX_BYTES,
                'validator' => function (string $raw): void {
                    $this->decodeAcknowledgement($raw);
                },
            ],
            $this->paths->guardianRecoveryTransactionFile() => [
                'label' => 'Recovery Guardian recovery transaction',
                'maximum' => self::MAX_BYTES,
                'validator' => function (string $raw): void {
                    $this->decodeRecoveryTransaction($raw);
                },
            ],
            $this->paths->guardianTransitionRetirementFile() => [
                'label' => 'Recovery Guardian handshake retirement',
                'maximum' => self::MAX_BYTES,
                'validator' => function (string $raw): void {
                    $this->decodeHandshakeRetirement($raw);
                },
            ],
        ] as $file => $definition) {
            $label = (string)$definition['label'];
            $maximum = (int)$definition['maximum'];
            if (!GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $file,
                $maximum,
                $label,
            )) {
                continue;
            }
            if (!\file_exists($file) && !\is_link($file)) {
                GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                    $file,
                    $maximum,
                    $label,
                );
                continue;
            }
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $file,
                $maximum,
                $label,
                $definition['validator'],
            );
        }
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function terminalHandshakeRetirement(
        string $requestRaw,
        array $request,
    ): array {
        $ackRaw = GatewayProjectStateFilesystem::read(
            $this->paths->guardianTransitionAcknowledgementFile(),
            self::MAX_BYTES,
            'Recovery Guardian transition acknowledgement',
        );
        $ack = $this->decodeAcknowledgement($ackRaw);
        $head = $this->generationHead()->readWhileLocked();
        $purpose = (string)$ack['purpose'];
        $expectedGeneration = $purpose === 'rollback'
            ? (string)$request['recovery_generation_id']
            : (string)$request['candidate_generation_id'];
        if ($head === null
            || !\hash_equals('STABLE', (string)($head['phase'] ?? ''))
            || !\hash_equals(
                (string)$request['host_id'],
                (string)($head['host_id'] ?? ''),
            )
            || !\hash_equals(
                (string)$request['host_id'],
                (string)$ack['host_id'],
            )
            || !\hash_equals(
                (string)$request['nonce'],
                (string)$ack['nonce'],
            )
            || !\hash_equals(
                \hash('sha256', $requestRaw),
                (string)$ack['request_sha256'],
            )
            || !\hash_equals(
                $expectedGeneration,
                (string)$ack['active_generation_id'],
            )
            || !\hash_equals(
                $expectedGeneration,
                (string)($head['active_generation_id'] ?? ''),
            )
            || (int)$ack['committed_head_sequence']
                <= (int)$request['expected_head_sequence']
            || (int)$ack['committed_head_sequence']
                !== (int)($head['sequence'] ?? 0)
            || !\hash_equals(
                (string)$ack['committed_head_sha256'],
                (string)($head['record_sha256'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Recovery Guardian handshake is not an exact stable terminal generation.',
            );
        }

        $transactionFile = $this->paths->guardianRecoveryTransactionFile();
        $transactionRaw = GatewayProjectStateFilesystem::readOptional(
            $transactionFile,
            self::MAX_BYTES,
            'Recovery Guardian recovery transaction',
        );
        if ($purpose === 'rollback') {
            if ($transactionRaw === null) {
                throw new \RuntimeException(
                    'Recovery Guardian stable rollback transaction is missing.',
                );
            }
            $transaction = $this->decodeRecoveryTransaction($transactionRaw);
            $this->assertTerminalRecoveryTransaction(
                $transaction,
                $request,
                \hash('sha256', $requestRaw),
            );
        } elseif ($transactionRaw !== null) {
            throw new \RuntimeException(
                'Recovery Guardian candidate commit conflicts with a recovery transaction.',
            );
        }

        return [
            'host_id' => (string)$request['host_id'],
            'nonce' => (string)$request['nonce'],
            'request_sha256' => \hash('sha256', $requestRaw),
            'ack_sha256' => \hash('sha256', $ackRaw),
            'transaction_sha256' => $transactionRaw === null
                ? self::ZERO_64
                : \hash('sha256', $transactionRaw),
            'committed_head_sequence' => (int)$ack['committed_head_sequence'],
            'committed_head_sha256' => (string)$ack['committed_head_sha256'],
            'purpose' => $purpose,
            'active_generation_id' => $expectedGeneration,
        ];
    }

    /** @param array<string,mixed> $retirement */
    private function publishHandshakeRetirementWhileLocked(array $retirement): void
    {
        $unsigned = $this->encodeHandshakeRetirementUnsigned($retirement);
        $encoded = $unsigned . 'signature=' . $this->signature($unsigned) . "\n";
        $file = $this->paths->guardianTransitionRetirementFile();
        $existing = GatewayProjectStateFilesystem::readOptional(
            $file,
            self::MAX_BYTES,
            'Recovery Guardian handshake retirement',
        );
        if ($existing === null) {
            GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600);
            $existing = GatewayProjectStateFilesystem::read(
                $file,
                self::MAX_BYTES,
                'Published Recovery Guardian handshake retirement',
            );
        }
        if (!\hash_equals($encoded, $existing)) {
            throw new \RuntimeException(
                'Recovery Guardian handshake retirement conflicts with another terminal generation.',
            );
        }
        $this->decodeHandshakeRetirement($existing);
    }

    private function resumeHandshakeRetirementWhileLocked(
        ?string $expectedHostId = null,
        ?string $expectedNonce = null,
    ): bool {
        $file = $this->paths->guardianTransitionRetirementFile();
        $raw = GatewayProjectStateFilesystem::readOptional(
            $file,
            self::MAX_BYTES,
            'Recovery Guardian handshake retirement',
        );
        if ($raw === null) {
            return false;
        }
        $retirement = $this->decodeHandshakeRetirement($raw);
        if (($expectedHostId !== null
                && !\hash_equals(
                    $expectedHostId,
                    (string)$retirement['host_id'],
                ))
            || ($expectedNonce !== null
                && !\hash_equals(
                    $expectedNonce,
                    (string)$retirement['nonce'],
                ))
        ) {
            throw new \RuntimeException(
                'Recovery Guardian retirement belongs to another handshake.',
            );
        }
        $head = $this->generationHead()->readWhileLocked();
        if ($head === null
            || !\hash_equals('STABLE', (string)($head['phase'] ?? ''))
            || !\hash_equals(
                (string)$retirement['host_id'],
                (string)($head['host_id'] ?? ''),
            )
            || (int)$retirement['committed_head_sequence']
                !== (int)($head['sequence'] ?? 0)
            || !\hash_equals(
                (string)$retirement['committed_head_sha256'],
                (string)($head['record_sha256'] ?? ''),
            )
            || !\hash_equals(
                (string)$retirement['active_generation_id'],
                (string)($head['active_generation_id'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Recovery Guardian retirement has no exact stable head.',
            );
        }

        $definitions = [
            $this->paths->guardianTransitionRequestFile() => [
                'label' => 'Recovery Guardian transition request',
                'sha256' => (string)$retirement['request_sha256'],
                'validator' => function (string $artifactRaw) use ($retirement): void {
                    $request = $this->decodeRequest($artifactRaw);
                    if (!\hash_equals(
                            (string)$retirement['host_id'],
                            (string)$request['host_id'],
                        )
                        || !\hash_equals(
                            (string)$retirement['nonce'],
                            (string)$request['nonce'],
                        )
                    ) {
                        throw new \RuntimeException(
                            'Recovery Guardian retirement request binding is invalid.',
                        );
                    }
                },
            ],
            $this->paths->guardianTransitionAcknowledgementFile() => [
                'label' => 'Recovery Guardian transition acknowledgement',
                'sha256' => (string)$retirement['ack_sha256'],
                'validator' => function (string $artifactRaw) use ($retirement): void {
                    $ack = $this->decodeAcknowledgement($artifactRaw);
                    if (!\hash_equals(
                            (string)$retirement['host_id'],
                            (string)$ack['host_id'],
                        )
                        || !\hash_equals(
                            (string)$retirement['nonce'],
                            (string)$ack['nonce'],
                        )
                        || !\hash_equals(
                            (string)$retirement['request_sha256'],
                            (string)$ack['request_sha256'],
                        )
                        || !\hash_equals(
                            (string)$retirement['purpose'],
                            (string)$ack['purpose'],
                        )
                        || (int)$retirement['committed_head_sequence']
                            !== (int)$ack['committed_head_sequence']
                        || !\hash_equals(
                            (string)$retirement['committed_head_sha256'],
                            (string)$ack['committed_head_sha256'],
                        )
                        || !\hash_equals(
                            (string)$retirement['active_generation_id'],
                            (string)$ack['active_generation_id'],
                        )
                    ) {
                        throw new \RuntimeException(
                            'Recovery Guardian retirement acknowledgement binding is invalid.',
                        );
                    }
                },
            ],
            $this->paths->guardianRecoveryTransactionFile() => [
                'label' => 'Recovery Guardian recovery transaction',
                'sha256' => (string)$retirement['transaction_sha256'],
                'validator' => function (string $artifactRaw) use ($retirement): void {
                    if (\hash_equals(
                        self::ZERO_64,
                        (string)$retirement['transaction_sha256'],
                    )) {
                        throw new \RuntimeException(
                            'Recovery Guardian commit retirement contains a recovery transaction.',
                        );
                    }
                    $transaction = $this->decodeRecoveryTransaction($artifactRaw);
                    if (!\hash_equals(
                            (string)$retirement['host_id'],
                            (string)$transaction['host_id'],
                        )
                        || !\hash_equals(
                            (string)$retirement['nonce'],
                            (string)$transaction['nonce'],
                        )
                        || !\hash_equals(
                            (string)$retirement['request_sha256'],
                            (string)$transaction['request_sha256'],
                        )
                        || !\hash_equals('STABLE', (string)$transaction['phase'])
                    ) {
                        throw new \RuntimeException(
                            'Recovery Guardian retirement transaction binding is invalid.',
                        );
                    }
                },
            ],
        ];
        foreach ($definitions as $artifact => $definition) {
            $artifactRaw = GatewayProjectStateFilesystem::readOptional(
                $artifact,
                self::MAX_BYTES,
                (string)$definition['label'],
            );
            if ($artifactRaw === null) {
                continue;
            }
            if (!\hash_equals(
                    (string)$definition['sha256'],
                    \hash('sha256', $artifactRaw),
                )
            ) {
                throw new \RuntimeException(
                    (string)$definition['label'] . ' changed during retirement.',
                );
            }
            $definition['validator']($artifactRaw);
        }
        foreach (\array_keys($definitions) as $artifact) {
            if (!GatewayProjectStateFilesystem::removeRegular(
                $artifact,
                (string)$definitions[$artifact]['label'],
            )) {
                throw new \RuntimeException(
                    (string)$definitions[$artifact]['label']
                        . ' was not retired.',
                );
            }
        }
        if (!GatewayProjectStateFilesystem::removeRegular(
            $file,
            'Recovery Guardian handshake retirement',
        )) {
            throw new \RuntimeException(
                'Recovery Guardian handshake retirement was not collected.',
            );
        }
        return true;
    }

    private function retireCompletedHandshakeForNextRequestWhileLocked(): bool
    {
        if ($this->resumeHandshakeRetirementWhileLocked()) {
            return true;
        }
        $requestRaw = GatewayProjectStateFilesystem::readOptional(
            $this->paths->guardianTransitionRequestFile(),
            self::MAX_BYTES,
            'Recovery Guardian previous transition request',
        );
        if ($requestRaw === null) {
            return false;
        }
        $request = $this->decodeRequest($requestRaw);
        try {
            $retirement = $this->terminalHandshakeRetirement(
                $requestRaw,
                $request,
            );
        } catch (\Throwable) {
            return false;
        }
        $this->publishHandshakeRetirementWhileLocked($retirement);
        return $this->resumeHandshakeRetirementWhileLocked();
    }

    private function retireOrphanedTerminalHandshakeWhileLocked(
        ?string $expectedHostId = null,
        ?string $expectedNonce = null,
    ): bool {
        if (GatewayProjectStateFilesystem::readOptional(
            $this->paths->guardianTransitionRequestFile(),
            self::MAX_BYTES,
            'Recovery Guardian transition request',
        ) !== null) {
            return false;
        }
        $head = $this->generationHead()->readWhileLocked();
        if ($head === null
            || !\hash_equals('STABLE', (string)($head['phase'] ?? ''))
        ) {
            return false;
        }
        $ackFile = $this->paths->guardianTransitionAcknowledgementFile();
        $transactionFile = $this->paths->guardianRecoveryTransactionFile();
        $ackRaw = GatewayProjectStateFilesystem::readOptional(
            $ackFile,
            self::MAX_BYTES,
            'Recovery Guardian orphaned acknowledgement',
        );
        $transactionRaw = GatewayProjectStateFilesystem::readOptional(
            $transactionFile,
            self::MAX_BYTES,
            'Recovery Guardian orphaned recovery transaction',
        );
        if ($ackRaw === null && $transactionRaw === null) {
            return false;
        }
        $ack = $ackRaw === null ? null : $this->decodeAcknowledgement($ackRaw);
        $transaction = $transactionRaw === null
            ? null
            : $this->decodeRecoveryTransaction($transactionRaw);
        $hostId = $ack === null
            ? (string)$transaction['host_id']
            : (string)$ack['host_id'];
        $nonce = $ack === null
            ? (string)$transaction['nonce']
            : (string)$ack['nonce'];
        if (($expectedHostId !== null && !\hash_equals($expectedHostId, $hostId))
            || ($expectedNonce !== null && !\hash_equals($expectedNonce, $nonce))
            || !\hash_equals($hostId, (string)($head['host_id'] ?? ''))
            || ($ack !== null
                && ((int)$ack['committed_head_sequence']
                        !== (int)($head['sequence'] ?? 0)
                    || !\hash_equals(
                        (string)$ack['committed_head_sha256'],
                        (string)($head['record_sha256'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$ack['active_generation_id'],
                        (string)($head['active_generation_id'] ?? ''),
                    )))
            || ($transaction !== null
                && (!\hash_equals('STABLE', (string)$transaction['phase'])
                    || !\hash_equals($hostId, (string)$transaction['host_id'])
                    || !\hash_equals($nonce, (string)$transaction['nonce'])
                    || ($ack !== null
                        && !\hash_equals(
                            (string)$ack['request_sha256'],
                            (string)$transaction['request_sha256'],
                        ))))
        ) {
            throw new \RuntimeException(
                'Recovery Guardian orphaned terminal handshake is not exact.',
            );
        }
        if ($ackRaw !== null) {
            GatewayProjectStateFilesystem::removeRegular(
                $ackFile,
                'Recovery Guardian orphaned acknowledgement',
            );
        }
        if ($transactionRaw !== null) {
            GatewayProjectStateFilesystem::removeRegular(
                $transactionFile,
                'Recovery Guardian orphaned recovery transaction',
            );
        }
        return true;
    }

    private function recoverRecoveryEvidenceArtifactsWhileLocked(
        string $nonce,
    ): void {
        foreach ([
            $this->paths->guardianRecoveryAuthorizationFile($nonce) => [
                'label' => 'Recovery Guardian recovery authorization',
                'maximum' => self::MAX_BYTES,
                'prefix' => "WLS-GUARDIAN-RECOVERY-AUTHORIZATION/1\n",
            ],
            $this->paths->guardianRecoveryInventoryFile($nonce) => [
                'label' => 'Recovery Guardian recovery inventory',
                'maximum' => self::MAX_INVENTORY_BYTES,
                'prefix' => "WLS-GUARDIAN-RECOVERY-INVENTORY/2\n",
            ],
        ] as $file => $definition) {
            $label = (string)$definition['label'];
            $maximum = (int)$definition['maximum'];
            if (!GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $file,
                $maximum,
                $label,
            )) {
                continue;
            }
            if (!\file_exists($file) && !\is_link($file)) {
                GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                    $file,
                    $maximum,
                    $label,
                );
                continue;
            }
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $file,
                $maximum,
                $label,
                function (string $raw) use ($definition, $label): void {
                    $this->assertStandaloneSignedDocument(
                        $raw,
                        (string)$definition['prefix'],
                        $label,
                    );
                },
            );
        }
    }

    private function assertStandaloneSignedDocument(
        string $raw,
        string $prefix,
        string $label,
    ): void {
        $signatureOffset = \strrpos($raw, "signature=");
        if (!\str_starts_with($raw, $prefix)
            || $signatureOffset === false
            || $signatureOffset < \strlen($prefix)
        ) {
            throw new \RuntimeException($label . ' is malformed.');
        }
        $unsigned = \substr($raw, 0, $signatureOffset);
        $line = \substr($raw, $signatureOffset);
        if (\preg_match(
            '/\Asignature=([a-f0-9]{64})\n\z/D',
            $line,
            $matches,
        ) !== 1
            || !\hash_equals($this->signature($unsigned), $matches[1])
        ) {
            throw new \RuntimeException($label . ' signature is invalid.');
        }
    }

    /** @param array<string,mixed> $journal @return array<string,string> */
    private function requestGenerationDescriptor(array $journal): array
    {
        $oldSlot = (string)($journal['old_active_slot'] ?? '');
        $old = \is_array($journal['old_slots'][$oldSlot] ?? null)
            ? $journal['old_slots'][$oldSlot]
            : null;
        if ($old === null) {
            throw new \RuntimeException(
                'Recovery Guardian request lacks the retained active generation.',
            );
        }
        $candidateLauncher = $this->normalizeHex(
            (string)($journal['candidate_launcher_sha256'] ?? ''),
            64,
            'candidate launcher digest',
        );
        $candidateCa = $this->normalizeHex(
            (string)($journal['candidate_ca_bundle_sha256'] ?? ''),
            64,
            'candidate CA digest',
        );
        $candidateRuntime = $this->normalizeHex(
            (string)($journal['runtime_generation'] ?? ''),
            64,
            'candidate runtime generation',
        );
        $recoveryLauncher = $this->normalizeHex(
            (string)($journal['old_launcher_sha256'] ?? ''),
            64,
            'recovery launcher digest',
        );
        $recoveryCa = $this->normalizeHex(
            (string)($journal['old_ca_bundle_sha256'] ?? ''),
            64,
            'recovery CA digest',
        );
        $recoveryRuntime = $this->normalizeHex(
            (string)($old['runtime_generation'] ?? ''),
            64,
            'recovery runtime generation',
        );
        $previousSlot = (string)($journal['old_previous_slot'] ?? '');
        if (!\in_array($oldSlot, ['A', 'B'], true)
            || !\in_array($previousSlot, ['', 'A', 'B'], true)
            || $previousSlot === $oldSlot
        ) {
            throw new \RuntimeException(
                'Recovery Guardian request has invalid retained slot pointers.',
            );
        }
        $slotGenerations = [];
        foreach (['A', 'B'] as $slot) {
            $closure = $journal['old_slots'][$slot] ?? null;
            $slotGenerations[$slot] = \is_array($closure)
                ? $this->normalizeHex(
                    (string)($closure['runtime_generation'] ?? ''),
                    64,
                    'recovery slot ' . $slot . ' runtime generation',
                )
                : self::ZERO_64;
        }
        if (!\hash_equals($recoveryRuntime, $slotGenerations[$oldSlot])) {
            throw new \RuntimeException(
                'Recovery Guardian active recovery slot generation is inconsistent.',
            );
        }
        $platform = $journal['platform_snapshot'] ?? null;
        if (!\is_array($platform)
            || !\in_array((string)($platform['kind'] ?? ''), [
                'test-session',
                'launchd-system',
                'systemd-system',
                'windows-service',
            ], true)
            || !\in_array((string)($platform['profile'] ?? ''), [
                'default',
                'ipv4-only',
            ], true)
        ) {
            throw new \RuntimeException(
                'Recovery Guardian request lacks its platform snapshot.',
            );
        }
        return [
            'host_id' => $this->normalizeHex(
                (string)($journal['host_id'] ?? ''),
                32,
                'host identity',
            ),
            'nonce' => $this->normalizeHex(
                (string)($journal['nonce'] ?? ''),
                32,
                'rebootstrap nonce',
            ),
            'journal_sha256' => \hash(
                'sha256',
                GatewayClient::canonicalJson($journal) . "\n",
            ),
            'candidate_generation_id' => GatewayGuardianGenerationHead::generationId(
                $candidateLauncher,
                $candidateCa,
                $candidateRuntime,
            ),
            'candidate_launcher_sha256' => $candidateLauncher,
            'candidate_launcher_size' => $this->positiveDecimal(
                $journal['candidate_launcher_size'] ?? null,
                'candidate launcher size',
            ),
            'candidate_launcher_mode' => $this->modeDecimal(
                $journal['candidate_launcher_mode'] ?? null,
                'candidate launcher mode',
            ),
            'candidate_ca_sha256' => $candidateCa,
            'candidate_runtime_generation' => $candidateRuntime,
            'recovery_generation_id' => GatewayGuardianGenerationHead::generationId(
                $recoveryLauncher,
                $recoveryCa,
                $recoveryRuntime,
            ),
            'recovery_launcher_sha256' => $recoveryLauncher,
            'recovery_launcher_size' => $this->positiveDecimal(
                $journal['old_launcher_size'] ?? null,
                'recovery launcher size',
            ),
            'recovery_launcher_mode' => $this->modeDecimal(
                $journal['old_launcher_mode'] ?? null,
                'recovery launcher mode',
            ),
            'recovery_ca_sha256' => $recoveryCa,
            'recovery_runtime_generation' => $recoveryRuntime,
            'recovery_active_slot' => $oldSlot,
            'recovery_previous_slot' => $previousSlot === '' ? 'NONE' : $previousSlot,
            'recovery_slot_a_generation' => $slotGenerations['A'],
            'recovery_slot_b_generation' => $slotGenerations['B'],
            'derived_manifest_sha256' => $this->normalizeHex(
                (string)($journal['old_derived_manifest_sha256'] ?? ''),
                64,
                'derived-state manifest digest',
            ),
            'derived_policy_sha256' => $this->normalizeHex(
                (string)($journal['derived_policy_sha256'] ?? ''),
                64,
                'derived-state policy digest',
            ),
            'platform_kind' => (string)$platform['kind'],
            'platform_profile' => (string)$platform['profile'],
            'platform_definition_sha256' => $this->normalizeHex(
                (string)($platform['definition_sha256'] ?? ''),
                64,
                'platform definition digest',
            ),
            'platform_metadata_sha256' => $this->normalizeHex(
                (string)($platform['metadata_sha256'] ?? ''),
                64,
                'platform metadata digest',
            ),
            'trust_rotation' => ($journal['trust_rotation'] ?? null) === true ? '1' : '0',
        ];
    }

    /**
     * Publish the immutable, native-readable list of every old derived-state
     * top-level closure.  Leaf names are hex encoded so control characters
     * cannot create an ambiguous line protocol.
     *
     * @param array<string,mixed> $journal
     * @param array<string,mixed> $request
     */
    private function ensureRecoveryInventory(
        array $journal,
        array $request,
    ): string {
        if (!\hash_equals(
            (string)$request['journal_sha256'],
            \hash('sha256', GatewayClient::canonicalJson($journal) . "\n"),
        )) {
            throw new \RuntimeException(
                'Recovery Guardian journal changed before inventory authorization.',
            );
        }
        $manifestRaw = GatewayProjectStateFilesystem::read(
            $this->paths->rebootstrapDerivedManifestFile(
                (string)$request['nonce'],
            ),
            self::MAX_INVENTORY_BYTES,
            'Recovery Guardian derived-state manifest',
        );
        if (!\hash_equals(
            (string)$request['derived_manifest_sha256'],
            \hash('sha256', $manifestRaw),
        )) {
            throw new \RuntimeException(
                'Recovery Guardian derived-state manifest changed before authorization.',
            );
        }
        $manifest = \json_decode($manifestRaw, true);
        if (!\is_array($manifest)
            || \array_is_list($manifest)
            || !\hash_equals(
                GatewayClient::canonicalJson($manifest) . "\n",
                $manifestRaw,
            )
            || ($manifest['schema_version'] ?? null) !== 4
            || !\hash_equals(
                'wls-rebootstrap-derived-state',
                (string)($manifest['operation'] ?? ''),
            )
            || !\hash_equals(
                (string)$request['host_id'],
                (string)($manifest['host_id'] ?? ''),
            )
            || !\hash_equals(
                (string)$request['nonce'],
                (string)($manifest['nonce'] ?? ''),
            )
            || !\hash_equals(
                (string)$request['recovery_ca_sha256'],
                (string)($manifest['old_ca_bundle_sha256'] ?? ''),
            )
            || !\hash_equals(
                (string)$request['derived_policy_sha256'],
                (string)($manifest['derived_policy_sha256'] ?? ''),
            )
            || !\is_array($manifest['categories'] ?? null)
            || \array_is_list($manifest['categories'])
        ) {
            throw new \RuntimeException(
                'Recovery Guardian derived-state manifest binding is invalid.',
            );
        }
        $categoryNames = \array_keys($manifest['categories']);
        $expectedCategories = \array_keys(self::RECOVERY_CATEGORIES);
        \sort($categoryNames, SORT_STRING);
        \sort($expectedCategories, SORT_STRING);
        if ($categoryNames !== $expectedCategories) {
            throw new \RuntimeException(
                'Recovery Guardian derived-state categories are incomplete.',
            );
        }

        $categoryLines = [];
        $entries = [];
        foreach (self::RECOVERY_CATEGORIES as $category => $contract) {
            $policy = $contract['policy'];
            $definition = $manifest['categories'][$category] ?? null;
            $definitionKeys = \is_array($definition)
                ? \array_keys($definition)
                : [];
            \sort($definitionKeys, SORT_STRING);
            if (!\is_array($definition)
                || \array_is_list($definition)
                || $definitionKeys !== [
                    'authority_profile',
                    'entries',
                    'policy',
                    'preserved',
                    'root',
                    'root_id',
                ]
                || !\hash_equals(
                    $contract['root_id'],
                    (string)($definition['root_id'] ?? ''),
                )
                || !\hash_equals(
                    $policy,
                    (string)($definition['policy'] ?? ''),
                )
                || !\hash_equals(
                    $contract['authority_profile'],
                    (string)($definition['authority_profile'] ?? ''),
                )
                || !\is_array($definition['preserved'] ?? null)
                || !\array_is_list($definition['preserved'])
                || $definition['preserved']
                    !== $this->recoveryPreservedLeaves($category, $request)
                || !\is_array($definition['root'] ?? null)
                || \array_is_list($definition['root'])
                || !\is_array($definition['entries'] ?? null)
                || ($definition['entries'] !== []
                    && \array_is_list($definition['entries']))
                || (!(bool)($definition['root']['present'] ?? false)
                    && $definition['entries'] !== [])
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian derived-state category is invalid: '
                        . $category . '.',
                );
            }
            $this->assertRecoveryRootProof(
                $category,
                $definition['root'],
            );
            $categoryLines[$category] = $this->encodeRecoveryCategoryLine(
                $category,
                $definition['root'],
            );
            foreach ($definition['entries'] as $leaf => $closure) {
                if (!\is_string($leaf)
                    || !$this->derivedLeafValid($leaf)
                    || !\is_array($closure)
                    || \array_is_list($closure)
                    || !\in_array(
                        (string)($closure['kind'] ?? ''),
                        ['directory', 'file'],
                        true,
                    )
                ) {
                    throw new \RuntimeException(
                        'Recovery Guardian derived-state inventory entry is invalid.',
                    );
                }
                $key = $category . "\0" . $leaf;
                if (isset($entries[$key])) {
                    throw new \RuntimeException(
                        'Recovery Guardian derived-state inventory entry is duplicated.',
                    );
                }
                $entries[$key] = 'entry=' . $category . "\t" . $policy
                    . "\t" . \bin2hex($leaf)
                    . "\t" . ((string)$closure['kind'] === 'directory' ? 'd' : 'f')
                    . "\t" . $this->nativeClosureDigest($closure) . "\n";
            }
        }
        \ksort($categoryLines, SORT_STRING);
        \ksort($entries, SORT_STRING);
        $unsigned = "WLS-GUARDIAN-RECOVERY-INVENTORY/2\n"
            . 'host_id=' . $request['host_id'] . "\n"
            . 'nonce=' . $request['nonce'] . "\n"
            . 'journal_sha256=' . $request['journal_sha256'] . "\n"
            . 'derived_manifest_sha256=' . $request['derived_manifest_sha256'] . "\n"
            . 'derived_policy_sha256=' . $request['derived_policy_sha256'] . "\n"
            . 'category_count=' . \count($categoryLines) . "\n"
            . 'entry_count=' . \count($entries) . "\n"
            . \implode('', $categoryLines)
            . \implode('', $entries);
        $encoded = $unsigned . 'signature=' . $this->signature($unsigned) . "\n";
        if (\strlen($encoded) > self::MAX_INVENTORY_BYTES) {
            throw new \RuntimeException(
                'Recovery Guardian inventory exceeds its fixed size limit.',
            );
        }
        $file = $this->paths->guardianRecoveryInventoryFile(
            (string)$request['nonce'],
        );
        $existing = GatewayProjectStateFilesystem::readOptional(
            $file,
            self::MAX_INVENTORY_BYTES,
            'Recovery Guardian recovery inventory',
        );
        if ($existing === null) {
            GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600);
            $existing = GatewayProjectStateFilesystem::read(
                $file,
                self::MAX_INVENTORY_BYTES,
                'Published Recovery Guardian recovery inventory',
            );
        }
        if (!\hash_equals($encoded, $existing)) {
            throw new \RuntimeException(
                'Recovery Guardian recovery inventory conflicts with its immutable backup.',
            );
        }
        return $existing;
    }

    /**
     * @param array<string,mixed> $request
     * @return list<string>
     */
    private function recoveryPreservedLeaves(
        string $category,
        array $request,
    ): array {
        if (\hash_equals('state', $category)) {
            $platformKind = (string)($request['platform_kind'] ?? '');
            if (\hash_equals('test-session', $platformKind)) {
                return ['recovery.reserve', 'service-definition.test'];
            }
            if (\hash_equals('windows-service', $platformKind)) {
                return ['recovery.reserve', 'windows-service.json'];
            }
            return ['recovery.reserve'];
        }
        if (!\hash_equals('trust', $category)) {
            return [];
        }
        return [
            'active-slot',
            'admin-stopped.intent',
            'admin.token',
            'guardian-generation-head.0',
            'guardian-generation-head.1',
            'guardian-generation-head.lock',
            'guardian-recovery.transaction',
            'guardian-transition.ack',
            'guardian-transition.request',
            'guardian-transition.retirement',
            'guardian.sha256',
            'host-id',
            'package-install.lock',
            'package-stage-a.lock',
            'package-stage-b.lock',
            'platform-definition.transaction',
            'platform-removal.pending',
            'platform-service.json',
            'previous-slot',
            'rebootstrap-start.authorization',
            'rebootstrap.transaction',
            'stable-launcher.sha256',
            'systemd-layout-migration.transaction',
        ];
    }

    /** @param array<string,mixed> $proof */
    private function assertRecoveryRootProof(
        string $category,
        array $proof,
    ): void {
        $contract = self::RECOVERY_CATEGORIES[$category] ?? null;
        $keys = \array_keys($proof);
        \sort($keys, SORT_STRING);
        $present = $proof['present'] ?? null;
        $identityValid = static function (mixed $value, bool $allowEmpty): bool {
            if (!\is_string($value)) {
                return false;
            }
            if ($allowEmpty && $value === '') {
                return true;
            }
            return \PHP_OS_FAMILY === 'Windows'
                ? \preg_match('/\A[a-f0-9]{8,32}\z/D', $value) === 1
                : \preg_match('/\A[0-9]{1,20}\z/D', $value) === 1;
        };
        if (!\is_array($contract)
            || $keys !== [
                'authority_policy',
                'authority_sha256',
                'device',
                'gid',
                'inode',
                'mode',
                'parent_authority_policy',
                'parent_authority_sha256',
                'parent_device',
                'parent_gid',
                'parent_inode',
                'parent_mode',
                'parent_uid',
                'parent_windows_sddl_b64',
                'present',
                'uid',
                'windows_sddl_b64',
            ]
            || !\is_bool($present)
            || !\hash_equals(
                $contract['authority_policy'],
                (string)($proof['authority_policy'] ?? ''),
            )
            || !\hash_equals(
                $contract['parent_authority_policy'],
                (string)($proof['parent_authority_policy'] ?? ''),
            )
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($proof['authority_sha256'] ?? ''),
            ) !== 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($proof['parent_authority_sha256'] ?? ''),
            ) !== 1
            || !$identityValid($proof['parent_device'] ?? null, false)
            || !$identityValid($proof['parent_inode'] ?? null, false)
            || ($present === true
                && (!$identityValid($proof['device'] ?? null, false)
                    || !$identityValid($proof['inode'] ?? null, false)))
            || ($present === false
                && (!$identityValid($proof['device'] ?? null, true)
                    || !$identityValid($proof['inode'] ?? null, true)
                    || (string)$proof['device'] !== ''
                    || (string)$proof['inode'] !== ''))
        ) {
            throw new \RuntimeException(
                'Recovery Guardian derived root proof is invalid: '
                    . $category . '.',
            );
        }
        foreach ([
            'uid',
            'gid',
            'mode',
            'parent_uid',
            'parent_gid',
            'parent_mode',
        ] as $field) {
            if (!\is_int($proof[$field] ?? null)
                || (int)$proof[$field] < 0
                || (int)$proof[$field] > 4_294_967_295
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian derived root numeric authority is invalid: '
                        . $category . '.',
                );
            }
        }
        $mode = (int)$proof['mode'];
        $uid = (int)$proof['uid'];
        $gid = (int)$proof['gid'];
        $parentMode = (int)$proof['parent_mode'];
        $parentUid = (int)$proof['parent_uid'];
        $parentGid = (int)$proof['parent_gid'];
        $rootSddl = (string)($proof['windows_sddl_b64'] ?? '');
        $parentSddl = (string)($proof['parent_windows_sddl_b64'] ?? '');
        if ($present === false
            && ($mode !== 0
                || $uid !== 0
                || $gid !== 0
                || $rootSddl !== ''
                || \str_ends_with(
                    $contract['authority_policy'],
                    '-preserve-identity',
                )
                || !\hash_equals(
                    (string)$proof['authority_sha256'],
                    \hash(
                        'sha256',
                        $contract['authority_policy'] . "\nabsent\n",
                    ),
                ))
        ) {
            throw new \RuntimeException(
                'Recovery Guardian absent derived root proof is invalid: '
                    . $category . '.',
            );
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            if ($mode !== 0
                || $uid !== 0
                || $gid !== 0
                || $parentMode !== 0
                || $parentUid !== 0
                || $parentGid !== 0
                || !$this->recoverySddlProofValid(
                    $parentSddl,
                    (string)$proof['parent_authority_sha256'],
                )
                || ($present === true
                    && !$this->recoverySddlProofValid(
                        $rootSddl,
                        (string)$proof['authority_sha256'],
                    ))
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian Windows derived authority is invalid: '
                        . $category . '.',
                );
            }
            return;
        }
        if ($rootSddl !== ''
            || $parentSddl !== ''
            || $parentMode > 0777
            || ($parentMode & 0700) !== 0700
            || ($parentMode & 0022) !== 0
            || !\hash_equals(
                (string)$proof['parent_authority_sha256'],
                \hash(
                    'sha256',
                    GatewayClient::canonicalJson([
                        'policy' => $contract['parent_authority_policy'],
                        'scope' => 'parent',
                        'mode' => $parentMode,
                        'uid' => $parentUid,
                        'gid' => $parentGid,
                    ]),
                ),
            )
            || ($present === true
                && ($mode > 0777
                    || ($mode & 0700) !== 0700
                    || ($mode & 0022) !== 0
                    || !\hash_equals(
                        (string)$proof['authority_sha256'],
                        \hash(
                            'sha256',
                            GatewayClient::canonicalJson([
                                'policy' => $contract['authority_policy'],
                                'mode' => $mode,
                                'uid' => $uid,
                                'gid' => $gid,
                            ]),
                        ),
                    )))
        ) {
            throw new \RuntimeException(
                'Recovery Guardian POSIX derived authority is invalid: '
                    . $category . '.',
            );
        }
    }

    private function recoverySddlProofValid(
        string $encoded,
        string $expectedSha256,
    ): bool {
        $sddl = \base64_decode($encoded, true);
        return \is_string($sddl)
            && $sddl !== ''
            && \strlen($sddl) <= 8192
            && !\str_contains($sddl, "\0")
            && \hash_equals(\base64_encode($sddl), $encoded)
            && \hash_equals($expectedSha256, \hash('sha256', $sddl));
    }

    /** @param array<string,mixed> $proof */
    private function encodeRecoveryCategoryLine(
        string $category,
        array $proof,
    ): string {
        $contract = self::RECOVERY_CATEGORIES[$category];
        $token = static fn (string $value): string => $value === ''
            ? '-'
            : $value;
        return 'category=' . \implode("\t", [
            $category,
            $contract['policy'],
            $contract['authority_profile'],
            $contract['authority_policy'],
            (bool)$proof['present'] ? '1' : '0',
            $token((string)$proof['device']),
            $token((string)$proof['inode']),
            (string)$proof['uid'],
            (string)$proof['gid'],
            (string)$proof['mode'],
            (string)$proof['authority_sha256'],
            $token((string)$proof['windows_sddl_b64']),
            $contract['parent_authority_profile'],
            $contract['parent_authority_policy'],
            (string)$proof['parent_device'],
            (string)$proof['parent_inode'],
            (string)$proof['parent_uid'],
            (string)$proof['parent_gid'],
            (string)$proof['parent_mode'],
            (string)$proof['parent_authority_sha256'],
            $token((string)$proof['parent_windows_sddl_b64']),
        ]) . "\n";
    }

    /** @param array<string,mixed> $closure */
    private function nativeClosureDigest(array $closure): string
    {
        $records = $closure['records'] ?? null;
        if (!\is_array($records)
            || !\array_is_list($records)
            || $records === []
            || \count($records) > 16_384
            || (int)($closure['entry_count'] ?? -1) !== \count($records)
        ) {
            throw new \RuntimeException(
                'Recovery Guardian derived closure records are invalid.',
            );
        }
        $context = \hash_init('sha256');
        $previous = null;
        foreach ($records as $record) {
            if (!\is_array($record) || \array_is_list($record)) {
                throw new \RuntimeException(
                    'Recovery Guardian derived closure record is invalid.',
                );
            }
            $path = (string)($record['path'] ?? '');
            $kind = (string)($record['kind'] ?? '');
            $mode = $record['mode'] ?? null;
            $uid = $record['uid'] ?? null;
            $gid = $record['gid'] ?? null;
            if (!$this->derivedRelativePathValid($path)
                || !\in_array($kind, ['directory', 'file'], true)
                || !\is_int($mode) || $mode < 0 || $mode > 0777
                || !\is_int($uid) || $uid < 0
                || !\is_int($gid) || $gid < 0
                || ($previous !== null && \strcmp($previous, $path) >= 0)
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian derived closure metadata is invalid.',
                );
            }
            $size = 0;
            $sha256 = self::ZERO_64;
            if ($kind === 'file') {
                $size = $record['size'] ?? null;
                $sha256 = (string)($record['sha256'] ?? '');
                if (!\is_int($size)
                    || $size < 0
                    || $size > 536_870_912
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
                ) {
                    throw new \RuntimeException(
                        'Recovery Guardian derived file closure is invalid.',
                    );
                }
            }
            \hash_update(
                $context,
                'record=' . \bin2hex($path) . "\t"
                    . ($kind === 'directory' ? 'd' : 'f') . "\t"
                    . $mode . "\t" . $uid . "\t" . $gid . "\t"
                    . $size . "\t" . $sha256 . "\n",
            );
            $previous = $path;
        }
        return \hash_final($context);
    }

    /** @param array<string,mixed> $request */
    private function ensureRecoveryAuthorization(array $request): string
    {
        $unsigned = $this->encodeRecoveryAuthorizationUnsigned($request);
        $encoded = $unsigned . 'signature=' . $this->signature($unsigned) . "\n";
        if (\strlen($encoded) > self::MAX_BYTES) {
            throw new \RuntimeException(
                'Recovery Guardian authorization exceeds its fixed size limit.',
            );
        }
        $file = $this->paths->guardianRecoveryAuthorizationFile(
            (string)$request['nonce'],
        );
        $existing = GatewayProjectStateFilesystem::readOptional(
            $file,
            self::MAX_BYTES,
            'Recovery Guardian recovery authorization',
        );
        if ($existing === null) {
            GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600);
            $existing = GatewayProjectStateFilesystem::read(
                $file,
                self::MAX_BYTES,
                'Published Recovery Guardian recovery authorization',
            );
        }
        if (!\hash_equals($encoded, $existing)) {
            throw new \RuntimeException(
                'Recovery Guardian recovery authorization conflicts with its immutable backup.',
            );
        }
        return $existing;
    }

    /** @param array<string,mixed> $request */
    private function assertRecoveryEvidence(array $request): void
    {
        $inventory = GatewayProjectStateFilesystem::read(
            $this->paths->guardianRecoveryInventoryFile(
                (string)$request['nonce'],
            ),
            self::MAX_INVENTORY_BYTES,
            'Recovery Guardian recovery inventory',
        );
        if (!\hash_equals(
            (string)$request['recovery_inventory_sha256'],
            \hash('sha256', $inventory),
        )) {
            throw new \RuntimeException(
                'Recovery Guardian recovery inventory digest is invalid.',
            );
        }
        $this->assertRecoveryInventoryEnvelope($inventory, $request);
        $authorization = GatewayProjectStateFilesystem::read(
            $this->paths->guardianRecoveryAuthorizationFile(
                (string)$request['nonce'],
            ),
            self::MAX_BYTES,
            'Recovery Guardian recovery authorization',
        );
        $expectedUnsigned = $this->encodeRecoveryAuthorizationUnsigned($request);
        $expected = $expectedUnsigned
            . 'signature=' . $this->signature($expectedUnsigned) . "\n";
        if (!\hash_equals($expected, $authorization)
            || !\hash_equals(
                (string)$request['recovery_authorization_sha256'],
                \hash('sha256', $authorization),
            )
        ) {
            throw new \RuntimeException(
                'Recovery Guardian recovery authorization is invalid.',
            );
        }
    }

    /** @param array<string,mixed> $request */
    private function assertRecoveryInventoryEnvelope(
        string $inventory,
        array $request,
    ): void {
        $signatureOffset = \strrpos($inventory, "signature=");
        if ($signatureOffset === false) {
            throw new \RuntimeException(
                'Recovery Guardian recovery inventory signature is missing.',
            );
        }
        $unsigned = \substr($inventory, 0, $signatureOffset);
        $signatureLine = \substr($inventory, $signatureOffset);
        if (\preg_match(
            '/\Asignature=([a-f0-9]{64})\n\z/D',
            $signatureLine,
            $signature,
        ) !== 1
            || !\hash_equals($this->signature($unsigned), $signature[1])
            || \preg_match(
                '/\AWLS-GUARDIAN-RECOVERY-INVENTORY\/2\n'
                    . 'host_id=([a-f0-9]{32})\n'
                    . 'nonce=([a-f0-9]{32})\n'
                    . 'journal_sha256=([a-f0-9]{64})\n'
                    . 'derived_manifest_sha256=([a-f0-9]{64})\n'
                    . 'derived_policy_sha256=([a-f0-9]{64})\n'
                    . 'category_count=([0-9]{1,2})\n'
                    . 'entry_count=([0-9]{1,5})\n/',
                $unsigned,
                $header,
            ) !== 1
        ) {
            throw new \RuntimeException(
                'Recovery Guardian recovery inventory envelope is invalid.',
            );
        }
        foreach ([
            1 => 'host_id',
            2 => 'nonce',
            3 => 'journal_sha256',
            4 => 'derived_manifest_sha256',
            5 => 'derived_policy_sha256',
        ] as $index => $field) {
            if (!\hash_equals(
                (string)$request[$field],
                (string)$header[$index],
            )) {
                throw new \RuntimeException(
                    'Recovery Guardian recovery inventory binding is invalid.',
                );
            }
        }
        $categoryCount = (int)$header[6];
        $entryCount = (int)$header[7];
        $body = \substr($unsigned, \strlen($header[0]));
        $lines = $body === '' ? [] : \explode("\n", \rtrim($body, "\n"));
        if ($categoryCount !== \count(self::RECOVERY_CATEGORIES)
            || $entryCount < 0
            || $entryCount > 16_384
            || \count($lines) !== $categoryCount + $entryCount
        ) {
            throw new \RuntimeException(
                'Recovery Guardian recovery inventory count is invalid.',
            );
        }
        $expectedCategories = \array_keys(self::RECOVERY_CATEGORIES);
        \sort($expectedCategories, SORT_STRING);
        foreach ($expectedCategories as $index => $category) {
            $this->decodeRecoveryCategoryLine($lines[$index], $category);
        }
        $previous = null;
        foreach (\array_slice($lines, $categoryCount) as $line) {
            if (\preg_match(
                '/\Aentry=([a-z0-9-]+)\t(restore|ephemeral)\t([a-f0-9]{2,510})\t([df])\t([a-f0-9]{64})\z/D',
                $line,
                $entry,
            ) !== 1
                || !isset(self::RECOVERY_CATEGORIES[$entry[1]])
                || !\hash_equals(
                    self::RECOVERY_CATEGORIES[$entry[1]]['policy'],
                    $entry[2],
                )
                || (\strlen($entry[3]) & 1) !== 0
                || ($previous !== null && \strcmp($previous, $line) >= 0)
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian recovery inventory entry is invalid.',
                );
            }
            $leaf = \hex2bin($entry[3]);
            if (!\is_string($leaf) || !$this->derivedLeafValid($leaf)) {
                throw new \RuntimeException(
                    'Recovery Guardian recovery inventory leaf is invalid.',
                );
            }
            $previous = $line;
        }
    }

    /** @return array<string,mixed> */
    private function decodeRecoveryCategoryLine(
        string $line,
        string $expectedCategory,
    ): array {
        if (!\str_starts_with($line, 'category=')) {
            throw new \RuntimeException(
                'Recovery Guardian recovery category prefix is invalid.',
            );
        }
        $fields = \explode("\t", \substr($line, 9));
        $contract = self::RECOVERY_CATEGORIES[$expectedCategory] ?? null;
        if (\count($fields) !== 21
            || !\is_array($contract)
            || !\hash_equals($expectedCategory, $fields[0])
            || !\hash_equals($contract['policy'], $fields[1])
            || !\hash_equals($contract['authority_profile'], $fields[2])
            || !\hash_equals($contract['authority_policy'], $fields[3])
            || !\in_array($fields[4], ['0', '1'], true)
            || !\hash_equals(
                $contract['parent_authority_profile'],
                $fields[12],
            )
            || !\hash_equals(
                $contract['parent_authority_policy'],
                $fields[13],
            )
            || \preg_match('/\A[a-f0-9]{64}\z/D', $fields[10]) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $fields[19]) !== 1
        ) {
            throw new \RuntimeException(
                'Recovery Guardian recovery category contract is invalid.',
            );
        }
        foreach ([5, 6, 14, 15] as $index) {
            if ($fields[$index] !== '-'
                && \preg_match('/\A[a-f0-9]{1,32}\z/D', $fields[$index]) !== 1
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian recovery category identity is invalid.',
                );
            }
        }
        foreach ([11, 20] as $index) {
            if ($fields[$index] !== '-'
                && (\strlen($fields[$index]) > 10_924
                    || \preg_match(
                        '/\A[A-Za-z0-9+\/]+={0,2}\z/D',
                        $fields[$index],
                    ) !== 1)
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian recovery category SDDL is invalid.',
                );
            }
        }
        $proof = [
            'authority_policy' => $fields[3],
            'authority_sha256' => $fields[10],
            'device' => $fields[5] === '-' ? '' : $fields[5],
            'gid' => $this->recoveryDecimalToken($fields[8]),
            'inode' => $fields[6] === '-' ? '' : $fields[6],
            'mode' => $this->recoveryDecimalToken($fields[9]),
            'parent_authority_sha256' => $fields[19],
            'parent_authority_policy' => $fields[13],
            'parent_device' => $fields[14] === '-' ? '' : $fields[14],
            'parent_gid' => $this->recoveryDecimalToken($fields[17]),
            'parent_inode' => $fields[15] === '-' ? '' : $fields[15],
            'parent_mode' => $this->recoveryDecimalToken($fields[18]),
            'parent_uid' => $this->recoveryDecimalToken($fields[16]),
            'parent_windows_sddl_b64' => $fields[20] === '-'
                ? ''
                : $fields[20],
            'present' => $fields[4] === '1',
            'uid' => $this->recoveryDecimalToken($fields[7]),
            'windows_sddl_b64' => $fields[11] === '-' ? '' : $fields[11],
        ];
        $this->assertRecoveryRootProof($expectedCategory, $proof);
        return $proof;
    }

    private function recoveryDecimalToken(string $value): int
    {
        if (\preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value) !== 1) {
            throw new \RuntimeException(
                'Recovery Guardian recovery category number is invalid.',
            );
        }
        $decoded = (int)$value;
        if ($decoded < 0
            || $decoded > 4_294_967_295
            || !\hash_equals((string)$decoded, $value)
        ) {
            throw new \RuntimeException(
                'Recovery Guardian recovery category number is out of range.',
            );
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function decodeRequest(string $raw): array
    {
        $matches = [];
        if (\preg_match(
            '/\AWLS-GUARDIAN-TRANSITION-REQUEST\/1\n'
                . 'host_id=([a-f0-9]{32})\n'
                . 'nonce=([a-f0-9]{32})\n'
                . 'expected_head_sequence=([0-9]{1,20})\n'
                . 'expected_head_sha256=([a-f0-9]{64})\n'
                . 'journal_sha256=([a-f0-9]{64})\n'
                . 'candidate_generation_id=([a-f0-9]{64})\n'
                . 'candidate_launcher_sha256=([a-f0-9]{64})\n'
                . 'candidate_launcher_size=([0-9]{1,20})\n'
                . 'candidate_launcher_mode=([0-9]{1,4})\n'
                . 'candidate_ca_sha256=([a-f0-9]{64})\n'
                . 'candidate_runtime_generation=([a-f0-9]{64})\n'
                . 'recovery_generation_id=([a-f0-9]{64})\n'
                . 'recovery_launcher_sha256=([a-f0-9]{64})\n'
                . 'recovery_launcher_size=([0-9]{1,20})\n'
                . 'recovery_launcher_mode=([0-9]{1,4})\n'
                . 'recovery_ca_sha256=([a-f0-9]{64})\n'
                . 'recovery_runtime_generation=([a-f0-9]{64})\n'
                . 'recovery_active_slot=([AB])\n'
                . 'recovery_previous_slot=(NONE|[AB])\n'
                . 'recovery_slot_a_generation=([a-f0-9]{64})\n'
                . 'recovery_slot_b_generation=([a-f0-9]{64})\n'
                . 'derived_manifest_sha256=([a-f0-9]{64})\n'
                . 'derived_policy_sha256=([a-f0-9]{64})\n'
                . 'platform_kind=(test-session|launchd-system|systemd-system|windows-service)\n'
                . 'platform_profile=(default|ipv4-only)\n'
                . 'platform_definition_sha256=([a-f0-9]{64})\n'
                . 'platform_metadata_sha256=([a-f0-9]{64})\n'
                . 'trust_rotation=([01])\n'
                . 'recovery_inventory_sha256=([a-f0-9]{64})\n'
                . 'request_binding_sha256=([a-f0-9]{64})\n'
                . 'recovery_authorization_sha256=([a-f0-9]{64})\n'
                . 'signature=([a-f0-9]{64})\n\z/D',
            $raw,
            $matches,
        ) !== 1) {
            throw new \RuntimeException(
                'Recovery Guardian transition request is malformed.',
            );
        }
        $request = [
            'host_id' => $matches[1],
            'nonce' => $matches[2],
            'expected_head_sequence' => $this->decimalToInt(
                $matches[3],
                'expected head sequence',
            ),
            'expected_head_sha256' => $matches[4],
            'journal_sha256' => $matches[5],
            'candidate_generation_id' => $matches[6],
            'candidate_launcher_sha256' => $matches[7],
            'candidate_launcher_size' => $this->decimalToInt(
                $matches[8],
                'candidate launcher size',
            ),
            'candidate_launcher_mode' => $this->decimalToInt(
                $matches[9],
                'candidate launcher mode',
            ),
            'candidate_ca_sha256' => $matches[10],
            'candidate_runtime_generation' => $matches[11],
            'recovery_generation_id' => $matches[12],
            'recovery_launcher_sha256' => $matches[13],
            'recovery_launcher_size' => $this->decimalToInt(
                $matches[14],
                'recovery launcher size',
            ),
            'recovery_launcher_mode' => $this->decimalToInt(
                $matches[15],
                'recovery launcher mode',
            ),
            'recovery_ca_sha256' => $matches[16],
            'recovery_runtime_generation' => $matches[17],
            'recovery_active_slot' => $matches[18],
            'recovery_previous_slot' => $matches[19],
            'recovery_slot_a_generation' => $matches[20],
            'recovery_slot_b_generation' => $matches[21],
            'derived_manifest_sha256' => $matches[22],
            'derived_policy_sha256' => $matches[23],
            'platform_kind' => $matches[24],
            'platform_profile' => $matches[25],
            'platform_definition_sha256' => $matches[26],
            'platform_metadata_sha256' => $matches[27],
            'trust_rotation' => $matches[28],
            'recovery_inventory_sha256' => $matches[29],
            'request_binding_sha256' => $matches[30],
            'recovery_authorization_sha256' => $matches[31],
            'signature' => $matches[32],
        ];
        if ((int)$request['expected_head_sequence'] < 1
            || (int)$request['candidate_launcher_size'] < 1
            || (int)$request['recovery_launcher_size'] < 1
            || (int)$request['candidate_launcher_mode'] < 1
            || (int)$request['candidate_launcher_mode'] > 0777
            || (int)$request['recovery_launcher_mode'] < 1
            || (int)$request['recovery_launcher_mode'] > 0777
            || !\hash_equals(
                GatewayGuardianGenerationHead::generationId(
                    $request['candidate_launcher_sha256'],
                    $request['candidate_ca_sha256'],
                    $request['candidate_runtime_generation'],
                ),
                $request['candidate_generation_id'],
            )
            || !\hash_equals(
                GatewayGuardianGenerationHead::generationId(
                    $request['recovery_launcher_sha256'],
                    $request['recovery_ca_sha256'],
                    $request['recovery_runtime_generation'],
                ),
                $request['recovery_generation_id'],
            )
            || !\hash_equals(
                $request['recovery_runtime_generation'],
                $request['recovery_active_slot'] === 'A'
                    ? $request['recovery_slot_a_generation']
                    : $request['recovery_slot_b_generation'],
            )
            || ($request['recovery_previous_slot'] !== 'NONE'
                && $request['recovery_previous_slot'] === $request['recovery_active_slot'])
            || !\hash_equals(
                \hash('sha256', $this->encodeRequestBinding($request)),
                $request['request_binding_sha256'],
            )
            || !\hash_equals(
                $this->signature($this->encodeRequestUnsigned($request)),
                $request['signature'],
            )
        ) {
            throw new \RuntimeException(
                'Recovery Guardian transition request authentication failed.',
            );
        }
        return $request;
    }

    /** @return array<string,mixed> */
    private function decodeAcknowledgement(string $raw): array
    {
        $matches = [];
        if (\preg_match(
            '/\AWLS-GUARDIAN-TRANSITION-ACK\/1\n'
                . 'host_id=([a-f0-9]{32})\n'
                . 'nonce=([a-f0-9]{32})\n'
                . 'request_sha256=([a-f0-9]{64})\n'
                . 'committed_head_sequence=([0-9]{1,20})\n'
                . 'committed_head_sha256=([a-f0-9]{64})\n'
                . 'purpose=(commit|rollback)\n'
                . 'phase=(STABLE)\n'
                . 'active_generation_id=([a-f0-9]{64})\n'
                . 'signature=([a-f0-9]{64})\n\z/D',
            $raw,
            $matches,
        ) !== 1) {
            throw new \RuntimeException(
                'Recovery Guardian transition acknowledgement is malformed.',
            );
        }
        $ack = [
            'host_id' => $matches[1],
            'nonce' => $matches[2],
            'request_sha256' => $matches[3],
            'committed_head_sequence' => $this->decimalToInt(
                $matches[4],
                'committed head sequence',
            ),
            'committed_head_sha256' => $matches[5],
            'purpose' => $matches[6],
            'phase' => $matches[7],
            'active_generation_id' => $matches[8],
            'signature' => $matches[9],
        ];
        if ((int)$ack['committed_head_sequence'] < 1
            || !\hash_equals(
                $this->signature($this->encodeAcknowledgementUnsigned($ack)),
                $ack['signature'],
            )
        ) {
            throw new \RuntimeException(
                'Recovery Guardian transition acknowledgement authentication failed.',
            );
        }
        return $ack;
    }

    /** @return array<string,mixed> */
    private function decodeRecoveryTransaction(string $raw): array
    {
        $matches = [];
        if (\preg_match(
            '/\AWLS-GUARDIAN-RECOVERY-TRANSACTION\/1\n'
                . 'host_id=([a-f0-9]{32})\n'
                . 'nonce=([a-f0-9]{32})\n'
                . 'request_sha256=([a-f0-9]{64})\n'
                . 'authorization_sha256=([a-f0-9]{64})\n'
                . 'inventory_sha256=([a-f0-9]{64})\n'
                . 'sequence=([0-9]{1,20})\n'
                . 'phase=(AUTHORIZED|RUNTIME|DERIVED|PLATFORM|RESTORED|OBSERVING|STABLE)\n'
                . 'cursor=([0-9]{1,20})\n'
                . 'previous_record_sha256=([a-f0-9]{64})\n'
                . 'signature=([a-f0-9]{64})\n\z/D',
            $raw,
            $matches,
        ) !== 1) {
            throw new \RuntimeException(
                'Recovery Guardian recovery transaction is malformed.',
            );
        }
        $sequence = $this->decimalToInt(
            $matches[6],
            'recovery transaction sequence',
        );
        $cursor = $this->decimalToInt(
            $matches[8],
            'recovery transaction cursor',
        );
        $transaction = [
            'host_id' => $matches[1],
            'nonce' => $matches[2],
            'request_sha256' => $matches[3],
            'authorization_sha256' => $matches[4],
            'inventory_sha256' => $matches[5],
            'sequence' => $sequence,
            'phase' => $matches[7],
            'cursor' => $cursor,
            'previous_record_sha256' => $matches[9],
            'signature' => $matches[10],
            'record_sha256' => \hash('sha256', $raw),
        ];
        if ($sequence < 1
            || $sequence > 26
            || !\hash_equals((string)$sequence, $matches[6])
            || !\hash_equals((string)$cursor, $matches[8])
            || !\hash_equals(
                $this->recoveryTransactionRawAtSequence(
                    $transaction,
                    $sequence,
                ),
                $raw,
            )
        ) {
            throw new \RuntimeException(
                'Recovery Guardian recovery transaction chain is invalid.',
            );
        }
        return $transaction;
    }

    /**
     * @param array<string,mixed> $transaction
     * @param array<string,mixed> $request
     */
    private function assertTerminalRecoveryTransaction(
        array $transaction,
        array $request,
        string $requestSha256,
    ): void {
        if (!\hash_equals('STABLE', (string)$transaction['phase'])
            || (int)$transaction['sequence'] !== 26
            || (int)$transaction['cursor'] !== 0
            || !\hash_equals(
                (string)$request['host_id'],
                (string)$transaction['host_id'],
            )
            || !\hash_equals(
                (string)$request['nonce'],
                (string)$transaction['nonce'],
            )
            || !\hash_equals(
                $requestSha256,
                (string)$transaction['request_sha256'],
            )
            || !\hash_equals(
                (string)$request['recovery_authorization_sha256'],
                (string)$transaction['authorization_sha256'],
            )
            || !\hash_equals(
                (string)$request['recovery_inventory_sha256'],
                (string)$transaction['inventory_sha256'],
            )
        ) {
            throw new \RuntimeException(
                'Recovery Guardian stable recovery transaction binding is invalid.',
            );
        }
    }

    /** @param array<string,mixed> $transaction */
    private function recoveryTransactionRawAtSequence(
        array $transaction,
        int $targetSequence,
    ): string {
        if ($targetSequence < 1 || $targetSequence > 26) {
            throw new \RuntimeException(
                'Recovery Guardian recovery transaction sequence is invalid.',
            );
        }
        $previous = self::ZERO_64;
        $encoded = '';
        for ($sequence = 1; $sequence <= $targetSequence; ++$sequence) {
            [$phase, $cursor] = $this->recoveryTransactionPosition($sequence);
            $unsigned = "WLS-GUARDIAN-RECOVERY-TRANSACTION/1\n"
                . 'host_id=' . $transaction['host_id'] . "\n"
                . 'nonce=' . $transaction['nonce'] . "\n"
                . 'request_sha256=' . $transaction['request_sha256'] . "\n"
                . 'authorization_sha256=' . $transaction['authorization_sha256'] . "\n"
                . 'inventory_sha256=' . $transaction['inventory_sha256'] . "\n"
                . 'sequence=' . $sequence . "\n"
                . 'phase=' . $phase . "\n"
                . 'cursor=' . $cursor . "\n"
                . 'previous_record_sha256=' . $previous . "\n";
            $encoded = $unsigned
                . 'signature=' . $this->signature($unsigned) . "\n";
            $previous = \hash('sha256', $encoded);
        }
        return $encoded;
    }

    /** @return array{0:string,1:int} */
    private function recoveryTransactionPosition(int $sequence): array
    {
        return match (true) {
            $sequence === 1 => ['AUTHORIZED', 0],
            $sequence <= 9 => ['RUNTIME', $sequence - 2],
            $sequence <= 19 => ['DERIVED', $sequence - 10],
            $sequence <= 23 => ['PLATFORM', $sequence - 20],
            $sequence === 24 => ['RESTORED', 0],
            $sequence === 25 => ['OBSERVING', 0],
            $sequence === 26 => ['STABLE', 0],
            default => throw new \RuntimeException(
                'Recovery Guardian recovery transaction position is invalid.',
            ),
        };
    }

    /** @param array<string,mixed> $retirement */
    private function encodeHandshakeRetirementUnsigned(array $retirement): string
    {
        return "WLS-GUARDIAN-TRANSITION-RETIREMENT/1\n"
            . 'host_id=' . $retirement['host_id'] . "\n"
            . 'nonce=' . $retirement['nonce'] . "\n"
            . 'request_sha256=' . $retirement['request_sha256'] . "\n"
            . 'ack_sha256=' . $retirement['ack_sha256'] . "\n"
            . 'transaction_sha256=' . $retirement['transaction_sha256'] . "\n"
            . 'committed_head_sequence=' . $retirement['committed_head_sequence'] . "\n"
            . 'committed_head_sha256=' . $retirement['committed_head_sha256'] . "\n"
            . 'purpose=' . $retirement['purpose'] . "\n"
            . 'active_generation_id=' . $retirement['active_generation_id'] . "\n";
    }

    /** @return array<string,mixed> */
    private function decodeHandshakeRetirement(string $raw): array
    {
        $matches = [];
        if (\preg_match(
            '/\AWLS-GUARDIAN-TRANSITION-RETIREMENT\/1\n'
                . 'host_id=([a-f0-9]{32})\n'
                . 'nonce=([a-f0-9]{32})\n'
                . 'request_sha256=([a-f0-9]{64})\n'
                . 'ack_sha256=([a-f0-9]{64})\n'
                . 'transaction_sha256=([a-f0-9]{64})\n'
                . 'committed_head_sequence=([0-9]{1,20})\n'
                . 'committed_head_sha256=([a-f0-9]{64})\n'
                . 'purpose=(commit|rollback)\n'
                . 'active_generation_id=([a-f0-9]{64})\n'
                . 'signature=([a-f0-9]{64})\n\z/D',
            $raw,
            $matches,
        ) !== 1) {
            throw new \RuntimeException(
                'Recovery Guardian handshake retirement is malformed.',
            );
        }
        $sequence = $this->decimalToInt(
            $matches[6],
            'retired committed head sequence',
        );
        $retirement = [
            'host_id' => $matches[1],
            'nonce' => $matches[2],
            'request_sha256' => $matches[3],
            'ack_sha256' => $matches[4],
            'transaction_sha256' => $matches[5],
            'committed_head_sequence' => $sequence,
            'committed_head_sha256' => $matches[7],
            'purpose' => $matches[8],
            'active_generation_id' => $matches[9],
            'signature' => $matches[10],
        ];
        if ($sequence < 1
            || !\hash_equals((string)$sequence, $matches[6])
            || ($retirement['purpose'] === 'commit'
                && !\hash_equals(
                    self::ZERO_64,
                    (string)$retirement['transaction_sha256'],
                ))
            || ($retirement['purpose'] === 'rollback'
                && \hash_equals(
                    self::ZERO_64,
                    (string)$retirement['transaction_sha256'],
                ))
            || !\hash_equals(
                $this->signature(
                    $this->encodeHandshakeRetirementUnsigned($retirement),
                ),
                (string)$retirement['signature'],
            )
        ) {
            throw new \RuntimeException(
                'Recovery Guardian handshake retirement authentication failed.',
            );
        }
        return $retirement;
    }

    /** @param array<string,mixed> $request */
    private function encodeRequestUnsigned(array $request): string
    {
        return "WLS-GUARDIAN-TRANSITION-REQUEST/1\n"
            . 'host_id=' . $request['host_id'] . "\n"
            . 'nonce=' . $request['nonce'] . "\n"
            . 'expected_head_sequence=' . $request['expected_head_sequence'] . "\n"
            . 'expected_head_sha256=' . $request['expected_head_sha256'] . "\n"
            . 'journal_sha256=' . $request['journal_sha256'] . "\n"
            . 'candidate_generation_id=' . $request['candidate_generation_id'] . "\n"
            . 'candidate_launcher_sha256=' . $request['candidate_launcher_sha256'] . "\n"
            . 'candidate_launcher_size=' . $request['candidate_launcher_size'] . "\n"
            . 'candidate_launcher_mode=' . $request['candidate_launcher_mode'] . "\n"
            . 'candidate_ca_sha256=' . $request['candidate_ca_sha256'] . "\n"
            . 'candidate_runtime_generation=' . $request['candidate_runtime_generation'] . "\n"
            . 'recovery_generation_id=' . $request['recovery_generation_id'] . "\n"
            . 'recovery_launcher_sha256=' . $request['recovery_launcher_sha256'] . "\n"
            . 'recovery_launcher_size=' . $request['recovery_launcher_size'] . "\n"
            . 'recovery_launcher_mode=' . $request['recovery_launcher_mode'] . "\n"
            . 'recovery_ca_sha256=' . $request['recovery_ca_sha256'] . "\n"
            . 'recovery_runtime_generation=' . $request['recovery_runtime_generation'] . "\n"
            . 'recovery_active_slot=' . $request['recovery_active_slot'] . "\n"
            . 'recovery_previous_slot=' . $request['recovery_previous_slot'] . "\n"
            . 'recovery_slot_a_generation=' . $request['recovery_slot_a_generation'] . "\n"
            . 'recovery_slot_b_generation=' . $request['recovery_slot_b_generation'] . "\n"
            . 'derived_manifest_sha256=' . $request['derived_manifest_sha256'] . "\n"
            . 'derived_policy_sha256=' . $request['derived_policy_sha256'] . "\n"
            . 'platform_kind=' . $request['platform_kind'] . "\n"
            . 'platform_profile=' . $request['platform_profile'] . "\n"
            . 'platform_definition_sha256=' . $request['platform_definition_sha256'] . "\n"
            . 'platform_metadata_sha256=' . $request['platform_metadata_sha256'] . "\n"
            . 'trust_rotation=' . $request['trust_rotation'] . "\n"
            . 'recovery_inventory_sha256=' . $request['recovery_inventory_sha256'] . "\n"
            . 'request_binding_sha256=' . $request['request_binding_sha256'] . "\n"
            . 'recovery_authorization_sha256='
                . $request['recovery_authorization_sha256'] . "\n";
    }

    /** @param array<string,mixed> $request */
    private function encodeRequestBinding(array $request): string
    {
        return "WLS-GUARDIAN-REQUEST-BINDING/1\n"
            . 'host_id=' . $request['host_id'] . "\n"
            . 'nonce=' . $request['nonce'] . "\n"
            . 'expected_head_sequence=' . $request['expected_head_sequence'] . "\n"
            . 'expected_head_sha256=' . $request['expected_head_sha256'] . "\n"
            . 'journal_sha256=' . $request['journal_sha256'] . "\n"
            . 'candidate_generation_id=' . $request['candidate_generation_id'] . "\n"
            . 'candidate_launcher_sha256=' . $request['candidate_launcher_sha256'] . "\n"
            . 'candidate_launcher_size=' . $request['candidate_launcher_size'] . "\n"
            . 'candidate_launcher_mode=' . $request['candidate_launcher_mode'] . "\n"
            . 'candidate_ca_sha256=' . $request['candidate_ca_sha256'] . "\n"
            . 'candidate_runtime_generation=' . $request['candidate_runtime_generation'] . "\n"
            . 'recovery_generation_id=' . $request['recovery_generation_id'] . "\n"
            . 'recovery_launcher_sha256=' . $request['recovery_launcher_sha256'] . "\n"
            . 'recovery_launcher_size=' . $request['recovery_launcher_size'] . "\n"
            . 'recovery_launcher_mode=' . $request['recovery_launcher_mode'] . "\n"
            . 'recovery_ca_sha256=' . $request['recovery_ca_sha256'] . "\n"
            . 'recovery_runtime_generation=' . $request['recovery_runtime_generation'] . "\n"
            . 'recovery_active_slot=' . $request['recovery_active_slot'] . "\n"
            . 'recovery_previous_slot=' . $request['recovery_previous_slot'] . "\n"
            . 'recovery_slot_a_generation=' . $request['recovery_slot_a_generation'] . "\n"
            . 'recovery_slot_b_generation=' . $request['recovery_slot_b_generation'] . "\n"
            . 'derived_manifest_sha256=' . $request['derived_manifest_sha256'] . "\n"
            . 'derived_policy_sha256=' . $request['derived_policy_sha256'] . "\n"
            . 'platform_kind=' . $request['platform_kind'] . "\n"
            . 'platform_profile=' . $request['platform_profile'] . "\n"
            . 'platform_definition_sha256=' . $request['platform_definition_sha256'] . "\n"
            . 'platform_metadata_sha256=' . $request['platform_metadata_sha256'] . "\n"
            . 'trust_rotation=' . $request['trust_rotation'] . "\n"
            . 'recovery_inventory_sha256=' . $request['recovery_inventory_sha256'] . "\n";
    }

    /** @param array<string,mixed> $request */
    private function encodeRecoveryAuthorizationUnsigned(array $request): string
    {
        return "WLS-GUARDIAN-RECOVERY-AUTHORIZATION/1\n"
            . \substr(
                $this->encodeRequestBinding($request),
                \strlen("WLS-GUARDIAN-REQUEST-BINDING/1\n"),
            )
            . 'request_binding_sha256=' . $request['request_binding_sha256'] . "\n";
    }

    /** @param array<string,mixed> $ack */
    private function encodeAcknowledgementUnsigned(array $ack): string
    {
        return "WLS-GUARDIAN-TRANSITION-ACK/1\n"
            . 'host_id=' . $ack['host_id'] . "\n"
            . 'nonce=' . $ack['nonce'] . "\n"
            . 'request_sha256=' . $ack['request_sha256'] . "\n"
            . 'committed_head_sequence=' . $ack['committed_head_sequence'] . "\n"
            . 'committed_head_sha256=' . $ack['committed_head_sha256'] . "\n"
            . 'purpose=' . $ack['purpose'] . "\n"
            . 'phase=' . $ack['phase'] . "\n"
            . 'active_generation_id=' . $ack['active_generation_id'] . "\n";
    }

    /** @param array<string,mixed> $head @param array<string,mixed> $request */
    private function assertHeadCandidateBinding(array $head, array $request): void
    {
        foreach ([
            'active_generation_id' => 'candidate_generation_id',
            'active_launcher_sha256' => 'candidate_launcher_sha256',
            'active_ca_sha256' => 'candidate_ca_sha256',
            'active_runtime_generation' => 'candidate_runtime_generation',
            'recovery_generation_id' => 'recovery_generation_id',
            'recovery_nonce' => 'nonce',
            'recovery_authorization_sha256' => 'recovery_authorization_sha256',
        ] as $headField => $requestField) {
            if (!\hash_equals(
                (string)$request[$requestField],
                (string)($head[$headField] ?? ''),
            )) {
                throw new \RuntimeException(
                    'Recovery Guardian candidate head binding is invalid.',
                );
            }
        }
    }

    /** @param array<string,mixed> $head @param array<string,mixed> $request */
    private function assertHeadRecoveryBinding(array $head, array $request): void
    {
        foreach ([
            'recovery_generation_id' => 'recovery_generation_id',
            'recovery_nonce' => 'nonce',
            'recovery_authorization_sha256' => 'recovery_authorization_sha256',
        ] as $headField => $requestField) {
            if (!\hash_equals(
                (string)$request[$requestField],
                (string)($head[$headField] ?? ''),
            )) {
                throw new \RuntimeException(
                    'Recovery Guardian recovery head binding is invalid.',
                );
            }
        }
        if (\hash_equals('ROLLBACK_OBSERVING', (string)$head['phase'])) {
            foreach ([
                'active_generation_id' => 'recovery_generation_id',
                'active_launcher_sha256' => 'recovery_launcher_sha256',
                'active_ca_sha256' => 'recovery_ca_sha256',
                'active_runtime_generation' => 'recovery_runtime_generation',
            ] as $headField => $requestField) {
                if (!\hash_equals(
                    (string)$request[$requestField],
                    (string)($head[$headField] ?? ''),
                )) {
                    throw new \RuntimeException(
                        'Recovery Guardian observing head is not the exact recovery generation.',
                    );
                }
            }
        }
    }

    private function signature(string $unsigned): string
    {
        $key = GatewayProjectStateFilesystem::read(
            $this->paths->adminTokenFile(),
            65,
            'Recovery Guardian administrator credential',
        );
        $key = \preg_match('/\A[a-f0-9]{64}\z/D', \strtolower(\trim($key))) === 1
            ? \hex2bin(\strtolower(\trim($key)))
            : false;
        if (!\is_string($key) || \strlen($key) !== 32) {
            throw new \RuntimeException(
                'Recovery Guardian administrator credential is invalid.',
            );
        }
        try {
            return \hash_hmac('sha256', $unsigned, $key);
        } finally {
            \sodium_memzero($key);
        }
    }

    private function generationHead(): GatewayGuardianGenerationHead
    {
        return $this->head ?? new GatewayGuardianGenerationHead($this->paths);
    }

    private function normalizeHex(string $value, int $length, string $label): string
    {
        $value = \strtolower(\trim($value));
        if (\preg_match('/\A[a-f0-9]{' . $length . '}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'Recovery Guardian ' . $label . ' is invalid.',
            );
        }
        return $value;
    }

    private function positiveDecimal(mixed $value, string $label): string
    {
        if (!\is_int($value) || $value < 1) {
            throw new \InvalidArgumentException(
                'Recovery Guardian ' . $label . ' is invalid.',
            );
        }
        return (string)$value;
    }

    private function modeDecimal(mixed $value, string $label): string
    {
        if (!\is_int($value) || $value < 1 || $value > 0777) {
            throw new \InvalidArgumentException(
                'Recovery Guardian ' . $label . ' is invalid.',
            );
        }
        return (string)$value;
    }

    private function derivedLeafValid(string $leaf): bool
    {
        return $leaf !== ''
            && $leaf !== '.'
            && $leaf !== '..'
            && \strlen($leaf) <= 255
            && !\str_contains($leaf, "\0")
            && !\str_contains($leaf, '/')
            && !\str_contains($leaf, '\\');
    }

    private function derivedRelativePathValid(string $relative): bool
    {
        if ($relative === '.') {
            return true;
        }
        if ($relative === ''
            || \strlen($relative) > 32_768
            || \str_contains($relative, "\0")
            || \str_contains($relative, '\\')
            || \str_starts_with($relative, '/')
        ) {
            return false;
        }
        foreach (\explode('/', $relative) as $segment) {
            if (!$this->derivedLeafValid($segment)) {
                return false;
            }
        }
        return true;
    }

    private function decimalToInt(string $value, string $label): int
    {
        $normalized = \ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string)PHP_INT_MAX;
        if (\strlen($normalized) > \strlen($maximum)
            || (\strlen($normalized) === \strlen($maximum)
                && \strcmp($normalized, $maximum) > 0)
        ) {
            throw new \RuntimeException(
                'Recovery Guardian ' . $label . ' exceeds this runtime.',
            );
        }
        return (int)$normalized;
    }

    private static function monotonicNow(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
