<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Model\Event\Delivery;

final class DeliveryStateMachine
{
    public const TERMINAL = ['succeeded', 'dead', 'superseded', 'skipped'];
    public const TERMINAL_REASON_TRANSPORT_TERMINATION_UNCONFIRMED = 'transport_termination_unconfirmed';

    public function __construct(
        private readonly Delivery $deliveryModel,
        private readonly DeliveryRetryPolicy $retryPolicy,
        private readonly AsyncErrorRedactor $errorRedactor,
    ) {
    }

    /** @return array{delivery:Delivery,candidate_attempt:int,lease_token:string}|null */
    public function claimProvisioning(int $deliveryId, string $owner, int $leaseSeconds = 60): ?array
    {
        $delivery = $this->find($deliveryId);
        if ($delivery === null) {
            return null;
        }
        $status = (string)$delivery->getData(Delivery::schema_fields_STATUS);
        $now = gmdate('Y-m-d H:i:s');
        if ($status === 'retry_wait'
            && (string)$delivery->getData(Delivery::schema_fields_NEXT_RETRY_AT) > $now) {
            return null;
        }
        if ($status === 'pending'
            && (string)$delivery->getData(Delivery::schema_fields_PROVISION_AVAILABLE_AT) > $now) {
            return null;
        }
        if ($status === 'provisioning') {
            $leaseUntil = (string)$delivery->getData(Delivery::schema_fields_LEASE_UNTIL);
            if ($leaseUntil !== '' && $leaseUntil > $now) {
                return null;
            }
        } elseif (!in_array($status, ['pending', 'retry_wait'], true)) {
            return null;
        }

        $leaseToken = bin2hex(random_bytes(32));
        $lockVersion = (int)$delivery->getData(Delivery::schema_fields_LOCK_VERSION);
        $expected = [
            Delivery::schema_fields_ID => $deliveryId,
            Delivery::schema_fields_STATUS => $status,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ];
        if (!$this->cas($expected, [
            Delivery::schema_fields_STATUS => 'provisioning',
            Delivery::schema_fields_LEASE_TOKEN => $leaseToken,
            Delivery::schema_fields_LEASE_OWNER => substr($owner, 0, 191),
            Delivery::schema_fields_LEASE_UNTIL => gmdate('Y-m-d H:i:s', time() + max(1, $leaseSeconds)),
            Delivery::schema_fields_NEXT_RETRY_AT => null,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ])) {
            return null;
        }
        $claimed = $this->find($deliveryId);
        return $claimed === null ? null : [
            'delivery' => $claimed,
            'candidate_attempt' => (int)$delivery->getData(Delivery::schema_fields_ATTEMPT_NO) + 1,
            'lease_token' => $leaseToken,
        ];
    }

    public function bindTransport(
        int $deliveryId,
        string $leaseToken,
        int $candidateAttempt,
        string $transportName,
        string $handle,
        string $idempotencyKey,
        ?int $queueId = null,
    ): bool {
        $delivery = $this->find($deliveryId);
        if ($delivery === null) {
            return false;
        }
        if ((string)$delivery->getData(Delivery::schema_fields_STATUS) === 'queued') {
            return (int)$delivery->getData(Delivery::schema_fields_ATTEMPT_NO) === $candidateAttempt
                && (string)$delivery->getData(Delivery::schema_fields_TRANSPORT_IDEMPOTENCY_KEY) === $idempotencyKey
                && (string)$delivery->getData(Delivery::schema_fields_TRANSPORT_HANDLE) === $handle;
        }
        $lockVersion = (int)$delivery->getData(Delivery::schema_fields_LOCK_VERSION);
        return $this->cas([
            Delivery::schema_fields_ID => $deliveryId,
            Delivery::schema_fields_STATUS => 'provisioning',
            Delivery::schema_fields_ATTEMPT_NO => $candidateAttempt - 1,
            Delivery::schema_fields_LEASE_TOKEN => $leaseToken,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ], [
            Delivery::schema_fields_STATUS => 'queued',
            Delivery::schema_fields_ATTEMPT_NO => $candidateAttempt,
            Delivery::schema_fields_TRANSPORT_NAME => substr($transportName, 0, 64),
            Delivery::schema_fields_TRANSPORT_HANDLE => substr($handle, 0, 255),
            Delivery::schema_fields_TRANSPORT_IDEMPOTENCY_KEY => substr($idempotencyKey, 0, 191),
            Delivery::schema_fields_QUEUE_ID => $queueId,
            Delivery::schema_fields_PROVISION_AVAILABLE_AT => null,
            Delivery::schema_fields_LEASE_TOKEN => null,
            Delivery::schema_fields_LEASE_OWNER => '',
            Delivery::schema_fields_LEASE_UNTIL => null,
            Delivery::schema_fields_TERMINATION_ATTEMPT_COUNT => 0,
            Delivery::schema_fields_TERMINATION_NEXT_AT => null,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ]);
    }

    public function provisionFailed(int $deliveryId, string $leaseToken, string $errorCode, string $error): bool
    {
        $delivery = $this->find($deliveryId);
        if ($delivery === null || (string)$delivery->getData(Delivery::schema_fields_STATUS) !== 'provisioning') {
            return false;
        }
        $retryCount = (int)$delivery->getData(Delivery::schema_fields_TRANSPORT_RETRY_COUNT) + 1;
        $lockVersion = (int)$delivery->getData(Delivery::schema_fields_LOCK_VERSION);
        return $this->cas([
            Delivery::schema_fields_ID => $deliveryId,
            Delivery::schema_fields_STATUS => 'provisioning',
            Delivery::schema_fields_LEASE_TOKEN => $leaseToken,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ], [
            Delivery::schema_fields_STATUS => 'pending',
            Delivery::schema_fields_TRANSPORT_RETRY_COUNT => $retryCount,
            Delivery::schema_fields_PROVISION_AVAILABLE_AT => gmdate(
                'Y-m-d H:i:s',
                time() + $this->retryPolicy->transportDelaySeconds($retryCount),
            ),
            Delivery::schema_fields_LEASE_TOKEN => null,
            Delivery::schema_fields_LEASE_OWNER => '',
            Delivery::schema_fields_LEASE_UNTIL => null,
            Delivery::schema_fields_LAST_ERROR_CODE => substr($errorCode, 0, 64),
            Delivery::schema_fields_LAST_ERROR => $this->redact($error),
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ]);
    }

    public function claimExecution(
        int $deliveryId,
        int $attemptNo,
        string $transportHandle,
        string $fenceToken,
    ): ?Delivery {
        $delivery = $this->find($deliveryId);
        if ($delivery === null || (string)$delivery->getData(Delivery::schema_fields_STATUS) !== 'queued') {
            return null;
        }
        $lockVersion = (int)$delivery->getData(Delivery::schema_fields_LOCK_VERSION);
        $timeout = max(1, min(3600, (int)$delivery->getData(Delivery::schema_fields_TIMEOUT_SECONDS)));
        if (!$this->cas([
            Delivery::schema_fields_ID => $deliveryId,
            Delivery::schema_fields_STATUS => 'queued',
            Delivery::schema_fields_ATTEMPT_NO => $attemptNo,
            Delivery::schema_fields_TRANSPORT_HANDLE => $transportHandle,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ], [
            Delivery::schema_fields_STATUS => 'running',
            Delivery::schema_fields_LEASE_TOKEN => substr($fenceToken, 0, 64),
            Delivery::schema_fields_LEASE_OWNER => 'worker:' . getmypid(),
            Delivery::schema_fields_LEASE_UNTIL => gmdate('Y-m-d H:i:s', time() + $timeout),
            Delivery::schema_fields_TERMINATION_ATTEMPT_COUNT => 0,
            Delivery::schema_fields_TERMINATION_NEXT_AT => null,
            Delivery::schema_fields_STARTED_AT => gmdate('Y-m-d H:i:s'),
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ])) {
            return null;
        }
        return $this->find($deliveryId);
    }

    public function succeeded(int $deliveryId, int $attemptNo, string $handle, string $fenceToken): bool
    {
        return $this->finishRunning($deliveryId, $attemptNo, $handle, $fenceToken, 'succeeded', '', '', 'observer_succeeded');
    }

    public function failed(
        int $deliveryId,
        int $attemptNo,
        string $handle,
        string $fenceToken,
        string $errorCode,
        string $error,
        bool $retryable,
    ): string {
        $delivery = $this->find($deliveryId);
        if ($delivery === null) {
            return 'noop';
        }
        $policy = (string)$delivery->getData(Delivery::schema_fields_RETRY_POLICY);
        $retry = $retryable && $this->retryPolicy->shouldRetry($policy, $attemptNo);
        $targetStatus = $retry ? 'retry_wait' : 'dead';
        $lockVersion = (int)$delivery->getData(Delivery::schema_fields_LOCK_VERSION);
        $updated = [
            Delivery::schema_fields_STATUS => $targetStatus,
            Delivery::schema_fields_NEXT_RETRY_AT => $retry
                ? $this->retryPolicy->nextRetryAt($deliveryId, $attemptNo)
                : null,
            Delivery::schema_fields_LAST_ERROR_CODE => substr($errorCode, 0, 64),
            Delivery::schema_fields_LAST_ERROR => $this->redact($error),
            Delivery::schema_fields_TERMINAL_REASON => $retry ? '' : ($retryable ? 'attempts_exhausted' : $errorCode),
            Delivery::schema_fields_FINISHED_AT => $retry ? null : gmdate('Y-m-d H:i:s'),
            Delivery::schema_fields_LEASE_TOKEN => null,
            Delivery::schema_fields_LEASE_OWNER => '',
            Delivery::schema_fields_LEASE_UNTIL => null,
            Delivery::schema_fields_TERMINATION_ATTEMPT_COUNT => 0,
            Delivery::schema_fields_TERMINATION_NEXT_AT => null,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ];
        $ok = $this->cas([
            Delivery::schema_fields_ID => $deliveryId,
            Delivery::schema_fields_STATUS => 'running',
            Delivery::schema_fields_ATTEMPT_NO => $attemptNo,
            Delivery::schema_fields_TRANSPORT_HANDLE => $handle,
            Delivery::schema_fields_LEASE_TOKEN => $fenceToken,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ], $updated);
        return $ok ? $targetStatus : 'noop';
    }

    /** @return 'retry_wait'|'dead'|'noop' */
    public function timeoutTerminated(
        int $deliveryId,
        int $attemptNo,
        string $handle,
        string $fenceToken,
    ): string {
        return $this->failed(
            $deliveryId,
            $attemptNo,
            $handle,
            $fenceToken,
            'observer_timeout',
            __('Observer 执行超时，Transport 已确认终止 Worker'),
            true,
        );
    }

    /** @return 'running'|'dead'|'noop' */
    public function terminationUnconfirmed(
        int $deliveryId,
        int $attemptNo,
        string $handle,
        string $fenceToken,
        string $errorCode,
        string $error,
    ): string {
        $delivery = $this->find($deliveryId);
        if ($delivery === null) {
            return 'noop';
        }
        $terminationAttempt = (int)$delivery->getData(Delivery::schema_fields_TERMINATION_ATTEMPT_COUNT) + 1;
        $dead = $terminationAttempt >= 3;
        $lockVersion = (int)$delivery->getData(Delivery::schema_fields_LOCK_VERSION);
        $updated = [
            Delivery::schema_fields_STATUS => $dead ? 'dead' : 'running',
            Delivery::schema_fields_TERMINATION_ATTEMPT_COUNT => $terminationAttempt,
            Delivery::schema_fields_TERMINATION_NEXT_AT => $dead
                ? null
                : gmdate('Y-m-d H:i:s', time() + 10),
            Delivery::schema_fields_LAST_ERROR_CODE => substr($errorCode, 0, 64),
            Delivery::schema_fields_LAST_ERROR => $this->redact($error),
            Delivery::schema_fields_TERMINAL_REASON => $dead
                ? self::TERMINAL_REASON_TRANSPORT_TERMINATION_UNCONFIRMED
                : '',
            Delivery::schema_fields_FINISHED_AT => $dead ? gmdate('Y-m-d H:i:s') : null,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ];
        if ($dead) {
            $updated[Delivery::schema_fields_LEASE_TOKEN] = null;
            $updated[Delivery::schema_fields_LEASE_OWNER] = '';
            $updated[Delivery::schema_fields_LEASE_UNTIL] = null;
        }
        $ok = $this->cas([
            Delivery::schema_fields_ID => $deliveryId,
            Delivery::schema_fields_STATUS => 'running',
            Delivery::schema_fields_ATTEMPT_NO => $attemptNo,
            Delivery::schema_fields_TRANSPORT_HANDLE => $handle,
            Delivery::schema_fields_LEASE_TOKEN => $fenceToken,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ], $updated);

        return $ok ? ($dead ? 'dead' : 'running') : 'noop';
    }

    public function supersedePending(int $deliveryId, int $successorId): bool
    {
        $delivery = $this->find($deliveryId);
        if ($delivery === null) {
            return false;
        }
        $status = (string)$delivery->getData(Delivery::schema_fields_STATUS);
        if (!in_array($status, ['pending', 'retry_wait', 'provisioning'], true)) {
            return false;
        }
        $lockVersion = (int)$delivery->getData(Delivery::schema_fields_LOCK_VERSION);
        return $this->cas([
            Delivery::schema_fields_ID => $deliveryId,
            Delivery::schema_fields_STATUS => $status,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ], [
            Delivery::schema_fields_STATUS => 'superseded',
            Delivery::schema_fields_SUPERSEDED_BY => $successorId,
            Delivery::schema_fields_FINISHED_AT => gmdate('Y-m-d H:i:s'),
            Delivery::schema_fields_TERMINAL_REASON => 'coalesced_into_successor',
            Delivery::schema_fields_LEASE_TOKEN => null,
            Delivery::schema_fields_LEASE_OWNER => '',
            Delivery::schema_fields_LEASE_UNTIL => null,
            Delivery::schema_fields_TERMINATION_ATTEMPT_COUNT => 0,
            Delivery::schema_fields_TERMINATION_NEXT_AT => null,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ]);
    }

    public function skipPending(int $deliveryId, string $reason): bool
    {
        $delivery = $this->find($deliveryId);
        if ($delivery === null || (string)$delivery->getData(Delivery::schema_fields_STATUS) !== 'pending') {
            return false;
        }
        $lockVersion = (int)$delivery->getData(Delivery::schema_fields_LOCK_VERSION);
        return $this->cas([
            Delivery::schema_fields_ID => $deliveryId,
            Delivery::schema_fields_STATUS => 'pending',
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ], [
            Delivery::schema_fields_STATUS => 'skipped',
            Delivery::schema_fields_TERMINAL_REASON => substr($reason, 0, 64),
            Delivery::schema_fields_FINISHED_AT => gmdate('Y-m-d H:i:s'),
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ]);
    }

    public function skipRunning(
        int $deliveryId,
        int $attemptNo,
        string $handle,
        string $fenceToken,
        string $reason,
    ): bool {
        return $this->finishRunning(
            $deliveryId,
            $attemptNo,
            $handle,
            $fenceToken,
            'skipped',
            '',
            '',
            substr($reason, 0, 64),
        );
    }

    public function find(int $deliveryId): ?Delivery
    {
        if ($deliveryId < 1) {
            return null;
        }
        $delivery = $this->newModel();
        $delivery->where(Delivery::schema_fields_ID, $deliveryId)->find()->fetch();
        return $delivery->getId() ? $delivery : null;
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $updates */
    public function cas(array $expected, array $updates): bool
    {
        $query = $this->newModel();
        foreach ($expected as $field => $value) {
            $query->where((string)$field, $value);
        }
        $updates[Delivery::schema_fields_UPDATED_AT] = gmdate('Y-m-d H:i:s');
        $result = $query->getQuery()->update($updates)->fetch();
        $updated = $result === true || (is_int($result) && $result === 1);
        $this->logCasResult($expected, $updates, $updated);
        return $updated;
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $updates */
    private function logCasResult(array $expected, array $updates, bool $updated): void
    {
        $deliveryId = (int)($expected[Delivery::schema_fields_ID] ?? 0);
        if ($deliveryId < 1) {
            return;
        }
        $status = strtolower(trim((string)($updates[Delivery::schema_fields_STATUS]
            ?? $expected[Delivery::schema_fields_STATUS]
            ?? 'unknown')));
        $errorCode = preg_match('/^[a-z0-9_]{1,48}$/', $status)
            ? 'state_' . $status
            : 'state_unknown';
        $context = [
            'delivery_id' => $deliveryId,
            'attempt_no' => (int)($updates[Delivery::schema_fields_ATTEMPT_NO]
                ?? $expected[Delivery::schema_fields_ATTEMPT_NO]
                ?? 0),
            'error_code' => $errorCode,
        ];
        if ($updated) {
            w_log_info('event_async_delivery_state_changed', $context, 'event_async.log');
            return;
        }
        w_log_info('event_async_stale_fence_noop', $context, 'event_async.log');
    }

    private function finishRunning(
        int $deliveryId,
        int $attemptNo,
        string $handle,
        string $fenceToken,
        string $status,
        string $errorCode,
        string $error,
        string $reason,
    ): bool {
        $delivery = $this->find($deliveryId);
        if ($delivery === null) {
            return false;
        }
        $lockVersion = (int)$delivery->getData(Delivery::schema_fields_LOCK_VERSION);
        return $this->cas([
            Delivery::schema_fields_ID => $deliveryId,
            Delivery::schema_fields_STATUS => 'running',
            Delivery::schema_fields_ATTEMPT_NO => $attemptNo,
            Delivery::schema_fields_TRANSPORT_HANDLE => $handle,
            Delivery::schema_fields_LEASE_TOKEN => $fenceToken,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ], [
            Delivery::schema_fields_STATUS => $status,
            Delivery::schema_fields_LAST_ERROR_CODE => $errorCode,
            Delivery::schema_fields_LAST_ERROR => $error === '' ? null : $this->redact($error),
            Delivery::schema_fields_TERMINAL_REASON => $reason,
            Delivery::schema_fields_FINISHED_AT => gmdate('Y-m-d H:i:s'),
            Delivery::schema_fields_LEASE_TOKEN => null,
            Delivery::schema_fields_LEASE_OWNER => '',
            Delivery::schema_fields_LEASE_UNTIL => null,
            Delivery::schema_fields_TERMINATION_ATTEMPT_COUNT => 0,
            Delivery::schema_fields_TERMINATION_NEXT_AT => null,
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ]);
    }

    private function newModel(): Delivery
    {
        $model = clone $this->deliveryModel;
        return $model->clearData()->clearQuery();
    }

    private function redact(string $error): string
    {
        return $this->errorRedactor->redact($error);
    }
}
