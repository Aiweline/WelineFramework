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
    ) {
    }

    /** @return array<string,mixed> */
    public function rotate(): array
    {
        $rotation = $this->identities->rotationState();
        if ($rotation === []) {
            // This also snapshots the old same-host claim while the active UUID
            // and credential remain untouched.
            $this->identities->projectUuid();
            $rotation = $this->identities->prepareRotation();
        }
        $rotationId = (string)$rotation['rotation_id'];
        $phase = (string)$rotation['phase'];

        if ($phase === 'LOCAL_PREPARED') {
            $request = self::prepareRequest($rotation);
            $response = $this->client->projectRequest('rotate-prepare', $request);
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
            );
            $rotation = $this->identities->recordRotationPrepared(
                $rotationId,
                (string)$request['request_digest'],
                (string)$request['idempotency_key'],
                (string)$credential['credential_id'],
                \hash('sha256', GatewayClient::canonicalJson($prepareReceipt)),
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
                $response = $this->client->projectRequest('rotate-commit', $commitRequest);
                $payload = self::successfulPayload($response, 'rotation commit');
            } catch (\Throwable $commitFailure) {
                // A lost commit acknowledgement is ambiguous. Query the
                // durable host transaction and only roll forward when it
                // returns the new-secret-signed COMMITTED receipt.
                try {
                    $statusResponse = $this->client->projectRequest(
                        'rotate-status',
                        self::rotationReference($rotation),
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
            $rotation = $this->identities->commitRotationIdentity($rotationId);
            $phase = 'IDENTITY_COMMITTED';
        }

        if ($phase === 'IDENTITY_COMMITTED') {
            try {
                $activeCredential = $this->credentials->commitPending(
                    $rotationId,
                    (string)$rotation['new_project_uuid'],
                );
            } catch (\Throwable $throwable) {
                // Atomic active publication may have completed before the
                // pending-file removal acknowledgement. Prove that after-image
                // before treating the local credential commit as complete.
                $activeCredential = $this->credentials->load(
                    (string)$rotation['new_project_uuid'],
                );
                if (!\hash_equals(
                    (string)$rotation['new_credential_id'],
                    (string)($activeCredential['credential_id'] ?? ''),
                )) {
                    throw $throwable;
                }
            }
            if (!\hash_equals(
                (string)$rotation['new_credential_id'],
                (string)($activeCredential['credential_id'] ?? ''),
            )) {
                throw new \RuntimeException(
                    'Committed project rotation credential does not match its journal.'
                );
            }
            $rotation = $this->identities->markRotationLocalCommitted($rotationId);
            $phase = 'LOCAL_COMMITTED';
        }

        if ($phase === 'LOCAL_COMMITTED') {
            $finalize = self::rotationReference($rotation, true);
            self::successfulPayload(
                $this->client->projectRequest('rotate-finalize', $finalize),
                'rotation finalize',
            );
            $finished = $this->identities->finalizeRotation($rotationId);
            return [
                'state' => 'FINALIZED',
                'rotation_id' => $rotationId,
                'previous_uuid' => (string)$finished['old_project_uuid'],
                'project_uuid' => (string)$finished['new_project_uuid'],
            ];
        }

        throw new \RuntimeException('WLS project identity rotation phase is unsupported.');
    }

    public function abort(): void
    {
        $rotation = $this->identities->rotationState();
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
            $this->client->projectRequest('rotate-abort', $reference),
            'rotation abort',
        );
        $this->credentials->abortPending((string)$rotation['rotation_id']);
        $this->identities->abortRotation((string)$rotation['rotation_id']);
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
}
