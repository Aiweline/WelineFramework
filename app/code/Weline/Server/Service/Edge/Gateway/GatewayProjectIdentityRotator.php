<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/** Recoverable old+new-proof project identity transfer. */
final class GatewayProjectIdentityRotator
{
    public const COMMIT_RECEIPT_FIELDS = [
        'schema_version',
        'protocol',
        'host_id',
        'gateway_epoch',
        'rotation_id',
        'old_project_uuid',
        'new_project_uuid',
        'project_root',
        'request_digest',
        'idempotency_key',
        'security_generation',
        'new_credential_id',
        'state',
        'issued_at',
        'signature',
    ];

    public function __construct(
        private readonly ProjectIdentityStore $identities = new ProjectIdentityStore(),
        private readonly GatewayCredentialStore $credentials = new GatewayCredentialStore(),
        private readonly GatewayClient $client = new GatewayClient(),
        private readonly ?\Closure $projectRequestResolver = null,
        private readonly ?\Closure $credentialRetirer = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function rotate(
        bool $allowSameRootTransfer = false,
        ?float $deadlineMonotonic = null,
    ): array {
        $cloneConflict = $this->identities->clonedIdentityConflict(
            $deadlineMonotonic,
        );
        if ($cloneConflict !== []) {
            $oldProjectUuid = (string)$cloneConflict['old_project_uuid'];
            // The credential file was copied with the clone. Remove only this
            // clone-local capability before publishing its new UUID; the
            // source project and its host credential are separate files.
            $this->retireClonedCredential(
                $oldProjectUuid,
                null,
                $deadlineMonotonic,
            );
            $freshEnrollment = $this->identities->prepareFreshCloneEnrollment(
                $cloneConflict,
                $deadlineMonotonic,
            );
            return [
                'state' => 'FRESH_ENROLLMENT_REQUIRED',
                'previous_uuid' => $oldProjectUuid,
                'project_uuid' => (string)$freshEnrollment['project_uuid'],
                'source_project_root' => (string)(
                    $freshEnrollment['source_project_root'] ?? ''
                ),
            ];
        }

        $freshEnrollment = $this->identities->freshEnrollmentState(
            $deadlineMonotonic,
        );
        if ($freshEnrollment !== []) {
            $this->retireClonedCredential(
                (string)($freshEnrollment['previous_project_uuid'] ?? ''),
                (string)($freshEnrollment['project_uuid'] ?? ''),
                $deadlineMonotonic,
            );
            $projectUuid = $this->identities->projectUuid($deadlineMonotonic);
            if (!\hash_equals(
                (string)($freshEnrollment['project_uuid'] ?? ''),
                $projectUuid,
            )) {
                throw new \RuntimeException(
                    'WLS fresh clone enrollment identity changed during recovery.',
                );
            }
            return [
                'state' => 'FRESH_ENROLLMENT_REQUIRED',
                'previous_uuid' => (string)$freshEnrollment['previous_project_uuid'],
                'project_uuid' => $projectUuid,
                'source_project_root' => (string)(
                    $freshEnrollment['source_project_root'] ?? ''
                ),
            ];
        }

        $rotation = $this->identities->rotationState($deadlineMonotonic);
        $lastFreshEnrollment = $rotation === []
            ? $this->identities->lastFreshEnrollmentState($deadlineMonotonic)
            : [];
        if ($lastFreshEnrollment !== [] && !$allowSameRootTransfer) {
            return [
                'state' => 'FRESH_ENROLLMENT_ALREADY_COMMITTED',
                'previous_uuid' => (string)(
                    $lastFreshEnrollment['previous_project_uuid'] ?? ''
                ),
                'project_uuid' => (string)(
                    $lastFreshEnrollment['project_uuid'] ?? ''
                ),
                'completed_at' => (string)(
                    $lastFreshEnrollment['completed_at'] ?? ''
                ),
            ];
        }

        if ($rotation === []) {
            // This also snapshots the old same-host claim while the active UUID
            // and credential remain untouched.
            $this->identities->projectUuid($deadlineMonotonic);
            $rotation = $this->identities->prepareRotation($deadlineMonotonic);
        }
        $rotationId = (string)$rotation['rotation_id'];
        $phase = (string)$rotation['phase'];

        if ($phase === 'LOCAL_PREPARED') {
            $request = self::prepareRequest($rotation);
            $response = $this->projectRequest(
                'rotate-prepare',
                $request,
                $deadlineMonotonic,
            );
            $payload = self::successfulPayload($response, 'rotation prepare');
            $credential = \is_array($payload['credential'] ?? null)
                ? $payload['credential']
                : [];
            $prepareReceipt = \is_array($payload['rotation_receipt'] ?? null)
                ? $payload['rotation_receipt']
                : [];
            self::validatePrepareReceipt(
                $prepareReceipt,
                $credential,
                $request,
            );
            $this->credentials->installPending(
                $credential,
                (string)$rotation['new_project_uuid'],
                $rotationId,
                $deadlineMonotonic,
            );
            $rotation = $this->identities->recordRotationPrepared(
                $rotationId,
                (string)$request['request_digest'],
                (string)$request['idempotency_key'],
                (string)$credential['credential_id'],
                \hash('sha256', GatewayClient::canonicalJson($prepareReceipt)),
                $deadlineMonotonic,
            );
            $phase = 'CONTROLLER_PREPARED';
        }

        if ($phase === 'CONTROLLER_PREPARED') {
            $pending = $this->credentials->loadPending(
                $rotationId,
                (string)$rotation['new_project_uuid'],
            );
            $commitRequest = self::commitRequest($rotation, $pending);
            try {
                $response = $this->projectRequest(
                    'rotate-commit',
                    $commitRequest,
                    $deadlineMonotonic,
                );
                $payload = self::successfulPayload($response, 'rotation commit');
            } catch (\Throwable $commitFailure) {
                // A lost commit acknowledgement is ambiguous. Query the
                // durable host transaction and only roll forward when it
                // returns the new-secret-signed COMMITTED receipt.
                try {
                    $statusResponse = $this->projectRequest(
                        'rotate-status',
                        self::rotationReference($rotation),
                        $deadlineMonotonic,
                    );
                    $payload = self::successfulPayload(
                        $statusResponse,
                        'rotation status',
                    );
                    if ((string)($payload['state'] ?? '') !== 'CONTROLLER_COMMITTED'
                        && (string)($payload['rotation_receipt']['state'] ?? '')
                            !== 'CONTROLLER_COMMITTED'
                    ) {
                        throw $commitFailure;
                    }
                } catch (\Throwable $statusFailure) {
                    throw new \RuntimeException(
                        'Gateway rotation commit acknowledgement is ambiguous; '
                            . 'rerun the same rotate command for recovery.',
                        0,
                        $statusFailure,
                    );
                }
            }
            $receipt = \is_array($payload['rotation_receipt'] ?? null)
                ? $payload['rotation_receipt']
                : [];
            self::validateCommitReceipt($receipt, $rotation, $pending);
            $rotation = $this->identities->markRotationHostCommitted(
                $rotationId,
                $receipt,
                $deadlineMonotonic,
            );
            $phase = 'HOST_COMMITTED';
        }

        if ($phase === 'HOST_COMMITTED') {
            // The project journal is writable project state. Re-prove the
            // stored host commit with the still-pending new secret before the
            // irreversible local UUID switch, including after a process crash.
            $pending = $this->credentials->loadPending(
                $rotationId,
                (string)$rotation['new_project_uuid'],
            );
            self::validateCommitReceipt(
                \is_array($rotation['commit_receipt'] ?? null)
                    ? $rotation['commit_receipt']
                    : [],
                $rotation,
                $pending,
            );
            $rotation = $this->identities->commitRotationIdentity(
                $rotationId,
                $deadlineMonotonic,
            );
            $phase = 'IDENTITY_COMMITTED';
        }

        if ($phase === 'IDENTITY_COMMITTED') {
            $credentialCommitFailure = null;
            try {
                $activeCredential = $this->credentials->commitPending(
                    $rotationId,
                    (string)$rotation['new_project_uuid'],
                    $deadlineMonotonic,
                );
            } catch (\Throwable $throwable) {
                // Atomic active publication may have completed before the
                // pending-file removal acknowledgement. Prove that after-image
                // before treating the local credential commit as complete.
                $activeCredential = $this->credentials->load(
                    (string)$rotation['new_project_uuid'],
                );
                $credentialCommitFailure = $throwable;
            }
            try {
                self::validateCommittedCredential($rotation, $activeCredential);
            } catch (\Throwable $proofFailure) {
                if ($credentialCommitFailure instanceof \Throwable) {
                    throw new \RuntimeException(
                        $proofFailure->getMessage(),
                        0,
                        $credentialCommitFailure,
                    );
                }
                throw $proofFailure;
            }
            $rotation = $this->identities->markRotationLocalCommitted(
                $rotationId,
                $deadlineMonotonic,
            );
            $phase = 'LOCAL_COMMITTED';
        }

        if ($phase === 'LOCAL_COMMITTED') {
            $finalize = self::rotationReference($rotation, true);
            self::successfulPayload(
                $this->projectRequest(
                    'rotate-finalize',
                    $finalize,
                    $deadlineMonotonic,
                ),
                'rotation finalize',
            );
            $finished = $this->identities->finalizeRotation(
                $rotationId,
                $deadlineMonotonic,
            );
            return [
                'state' => 'FINALIZED',
                'rotation_id' => $rotationId,
                'previous_uuid' => (string)$finished['old_project_uuid'],
                'project_uuid' => (string)$finished['new_project_uuid'],
            ];
        }

        throw new \RuntimeException('WLS project identity rotation phase is unsupported.');
    }

    public function abort(?float $deadlineMonotonic = null): void
    {
        if ($this->identities->freshEnrollmentState($deadlineMonotonic) !== []) {
            throw new \RuntimeException(
                'A fresh clone identity cannot be aborted into the copied project UUID; '
                    . 'complete its new enrollment instead.',
            );
        }
        $rotation = $this->identities->rotationState($deadlineMonotonic);
        if ($rotation === []) {
            return;
        }
        if (!\in_array((string)($rotation['phase'] ?? ''), [
            'LOCAL_PREPARED',
            'CONTROLLER_PREPARED',
        ], true)) {
            throw new \RuntimeException(
                'Host-committed WLS project rotation cannot be aborted; rerun recovery.'
            );
        }
        $reference = (string)$rotation['phase'] === 'LOCAL_PREPARED'
            ? self::prepareRequest($rotation)
            : self::rotationReference($rotation);
        // Always ask the Controller, including LOCAL_PREPARED: the prepare
        // acknowledgement may have been lost just before the local journal
        // advanced. Controller abort is idempotent and fences a concurrent or
        // already committed transfer instead of orphaning it.
        self::successfulPayload(
            $this->projectRequest(
                'rotate-abort',
                $reference,
                $deadlineMonotonic,
            ),
            'rotation abort',
        );
        $this->credentials->abortPending(
            (string)$rotation['rotation_id'],
            $deadlineMonotonic,
        );
        $this->identities->abortRotation(
            (string)$rotation['rotation_id'],
            $deadlineMonotonic,
        );
    }

    private function retireClonedCredential(
        string $oldProjectUuid,
        ?string $preserveProjectUuid = null,
        ?float $deadlineMonotonic = null,
    ): void
    {
        if ($this->credentialRetirer !== null) {
            ($this->credentialRetirer)($oldProjectUuid);
            return;
        }
        $this->credentials->purgeForFreshCloneIdentity(
            $preserveProjectUuid,
            $deadlineMonotonic,
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function projectRequest(
        string $operation,
        array $payload,
        ?float $deadlineMonotonic = null,
    ): array {
        $response = $this->projectRequestResolver !== null
            ? ($this->projectRequestResolver)($operation, $payload)
            : $this->client->projectRequest(
                $operation,
                $payload,
                $deadlineMonotonic,
            );
        if (!\is_array($response)) {
            throw new \RuntimeException(
                'Gateway project identity rotation returned an invalid response.',
            );
        }
        return $response;
    }

    /** @param array<string,mixed> $rotation @return array<string,mixed> */
    private static function prepareRequest(array $rotation): array
    {
        $facts = [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'rotation_id' => (string)$rotation['rotation_id'],
            'old_project_uuid' => (string)$rotation['old_project_uuid'],
            'new_project_uuid' => (string)$rotation['new_project_uuid'],
            'project_root' => (string)$rotation['project_root'],
        ];
        $digest = \hash('sha256', GatewayClient::canonicalJson($facts));
        $request = [
            'project_uuid' => (string)$rotation['old_project_uuid'],
            'rotation_id' => (string)$rotation['rotation_id'],
            'old_project_uuid' => (string)$rotation['old_project_uuid'],
            'new_project_uuid' => (string)$rotation['new_project_uuid'],
            'project_root' => (string)$rotation['project_root'],
            'request_digest' => $digest,
        ];
        $request['idempotency_key'] = \substr(\hash(
            'sha256',
            (string)$rotation['old_project_uuid'] . ':rotate:'
                . (string)$rotation['new_project_uuid'] . ':' . $digest,
        ), 0, 40);
        return $request;
    }

    /**
     * @param array<string,mixed> $rotation
     * @param array<string,mixed> $pending
     * @return array<string,mixed>
     */
    private static function commitRequest(array $rotation, array $pending): array
    {
        $request = self::rotationReference($rotation);
        $request['new_credential_id'] = (string)$pending['credential_id'];
        $proofFacts = [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'operation' => 'rotate-commit',
            'rotation_id' => (string)$rotation['rotation_id'],
            'old_project_uuid' => (string)$rotation['old_project_uuid'],
            'new_project_uuid' => (string)$rotation['new_project_uuid'],
            'project_root' => (string)$rotation['project_root'],
            'request_digest' => (string)$rotation['request_digest'],
            'idempotency_key' => (string)$rotation['idempotency_key'],
            'new_credential_id' => (string)$pending['credential_id'],
        ];
        $request['new_proof'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($proofFacts),
            (string)$pending['secret'],
        );
        return $request;
    }

    /** @param array<string,mixed> $rotation @return array<string,mixed> */
    private static function rotationReference(
        array $rotation,
        bool $newAuthentication = false,
    ): array {
        return [
            'project_uuid' => (string)$rotation[
                $newAuthentication ? 'new_project_uuid' : 'old_project_uuid'
            ],
            'rotation_id' => (string)$rotation['rotation_id'],
            'old_project_uuid' => (string)$rotation['old_project_uuid'],
            'new_project_uuid' => (string)$rotation['new_project_uuid'],
            'project_root' => (string)$rotation['project_root'],
            'request_digest' => (string)($rotation['request_digest'] ?? ''),
            'idempotency_key' => (string)($rotation['idempotency_key'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private static function successfulPayload(array $response, string $operation): array
    {
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                'Gateway ' . $operation . ' failed: '
                    . (string)($response['error']['message'] ?? 'unknown failure')
            );
        }
        return \is_array($response['payload'] ?? null) ? $response['payload'] : [];
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $credential
     * @param array<string,mixed> $request
     */
    private static function validatePrepareReceipt(
        array $receipt,
        array $credential,
        array $request,
    ): void {
        $signature = \strtolower(\trim((string)($receipt['signature'] ?? '')));
        $expectedFields = self::COMMIT_RECEIPT_FIELDS;
        $actualFields = \array_keys($receipt);
        \sort($expectedFields, SORT_STRING);
        \sort($actualFields, SORT_STRING);
        $signed = $receipt;
        unset($signed['signature']);
        $secret = \strtolower(\trim((string)($credential['secret'] ?? '')));
        $expected = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? \hash_hmac('sha256', GatewayClient::canonicalJson($signed), $secret)
            : '';
        if ($actualFields !== $expectedFields
            || ($receipt['schema_version'] ?? null) !== 1
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)($receipt['protocol'] ?? ''))
            || !\hash_equals((string)($credential['host_id'] ?? ''), (string)(
                $receipt['host_id'] ?? ''
            ))
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $receipt['gateway_epoch'] ?? ''
            )) !== 1
            || !\hash_equals((string)$request['rotation_id'], (string)(
                $receipt['rotation_id'] ?? ''
            ))
            || !\hash_equals((string)$request['old_project_uuid'], (string)(
                $receipt['old_project_uuid'] ?? ''
            ))
            || !\hash_equals((string)$request['new_project_uuid'], (string)(
                $receipt['new_project_uuid'] ?? ''
            ))
            || !\hash_equals((string)$request['project_root'], (string)(
                $receipt['project_root'] ?? ''
            ))
            || !\hash_equals((string)$request['request_digest'], (string)(
                $receipt['request_digest'] ?? ''
            ))
            || !\hash_equals((string)$request['idempotency_key'], (string)(
                $receipt['idempotency_key'] ?? ''
            ))
            || !\hash_equals((string)($credential['credential_id'] ?? ''), (string)(
                $receipt['new_credential_id'] ?? ''
            ))
            || !\is_int($receipt['security_generation'] ?? null)
            || (int)$receipt['security_generation'] < 1
            || !\hash_equals('ROTATION_PREPARED', (string)($receipt['state'] ?? ''))
            || !\is_string($receipt['issued_at'] ?? null)
            || \strlen((string)$receipt['issued_at']) > 128
            || \strtotime((string)$receipt['issued_at']) === false
            || \preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
            || $expected === ''
            || !\hash_equals($expected, $signature)
        ) {
            throw new \RuntimeException('Gateway rotation prepare receipt is invalid.');
        }
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $rotation
     * @param array<string,mixed> $pending
     */
    public static function validateCommitReceipt(
        array $receipt,
        array $rotation,
        array $pending,
    ): void {
        $expectedFields = self::COMMIT_RECEIPT_FIELDS;
        $actualFields = \array_keys($receipt);
        \sort($expectedFields, SORT_STRING);
        \sort($actualFields, SORT_STRING);
        $signature = \strtolower(\trim((string)($receipt['signature'] ?? '')));
        $signed = $receipt;
        unset($signed['signature']);
        $secret = \strtolower(\trim((string)($pending['secret'] ?? '')));
        $expected = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? \hash_hmac('sha256', GatewayClient::canonicalJson($signed), $secret)
            : '';
        if ($actualFields !== $expectedFields
            || ($receipt['schema_version'] ?? null) !== 1
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)($receipt['protocol'] ?? ''))
            || !\hash_equals((string)($pending['host_id'] ?? ''), (string)(
                $receipt['host_id'] ?? ''
            ))
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $receipt['gateway_epoch'] ?? ''
            )) !== 1
            || !\hash_equals((string)$rotation['rotation_id'], (string)(
                $receipt['rotation_id'] ?? ''
            ))
            || !\hash_equals((string)$rotation['old_project_uuid'], (string)(
                $receipt['old_project_uuid'] ?? ''
            ))
            || !\hash_equals((string)$rotation['new_project_uuid'], (string)(
                $receipt['new_project_uuid'] ?? ''
            ))
            || !\hash_equals((string)$rotation['project_root'], (string)(
                $receipt['project_root'] ?? ''
            ))
            || !\hash_equals((string)$rotation['request_digest'], (string)(
                $receipt['request_digest'] ?? ''
            ))
            || !\hash_equals((string)$rotation['idempotency_key'], (string)(
                $receipt['idempotency_key'] ?? ''
            ))
            || !\is_int($receipt['security_generation'] ?? null)
            || (int)$receipt['security_generation'] < 1
            || !\hash_equals((string)$rotation['new_credential_id'], (string)(
                $receipt['new_credential_id'] ?? ''
            ))
            || !\hash_equals('CONTROLLER_COMMITTED', (string)($receipt['state'] ?? ''))
            || !\is_string($receipt['issued_at'] ?? null)
            || \strlen((string)$receipt['issued_at']) > 128
            || \strtotime((string)$receipt['issued_at']) === false
            || \preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
            || $expected === ''
            || !\hash_equals($expected, $signature)
        ) {
            throw new \RuntimeException('Gateway rotation commit receipt is invalid.');
        }
    }

    /**
     * Prove the exact local active credential against the durable new-secret
     * signed host commit before deleting the recovery phase from the journal.
     * A matching credential ID alone is not an after-image proof: a corrupted
     * or partially restored secret would strand the project after finalize.
     *
     * @param array<string,mixed> $rotation
     * @param array<string,mixed> $credential
     */
    public static function validateCommittedCredential(
        array $rotation,
        array $credential,
    ): void {
        try {
            if (($credential['schema_version'] ?? null) !== 1
                || !\hash_equals(
                    GatewayPaths::PROTOCOL,
                    (string)($credential['protocol'] ?? ''),
                )
                || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                    $credential['host_id'] ?? ''
                )) !== 1
                || !\hash_equals(
                    (string)($rotation['new_project_uuid'] ?? ''),
                    (string)($credential['project_uuid'] ?? ''),
                )
                || !\hash_equals(
                    (string)($rotation['new_credential_id'] ?? ''),
                    (string)($credential['credential_id'] ?? ''),
                )
                || !\is_int($credential['credential_generation'] ?? null)
                || (int)$credential['credential_generation'] < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                    $credential['secret'] ?? ''
                )) !== 1
                || !\is_string($credential['issued_at'] ?? null)
                || \strlen((string)$credential['issued_at']) > 128
                || \strtotime((string)$credential['issued_at']) === false
            ) {
                throw new \RuntimeException(
                    'Committed project rotation credential shape is invalid.',
                );
            }
            self::validateCommitReceipt(
                \is_array($rotation['commit_receipt'] ?? null)
                    ? $rotation['commit_receipt']
                    : [],
                $rotation,
                $credential,
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Committed project rotation credential does not prove the committed host rotation.',
                0,
                $throwable,
            );
        }
    }
}
