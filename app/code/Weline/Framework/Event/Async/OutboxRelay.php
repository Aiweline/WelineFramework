<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Database\Exception\UniqueConstraintViolationDetector;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Model\Event\Delivery;
use Weline\Framework\Model\Event\Outbox;

final class OutboxRelay
{
    private const LEASE_SECONDS = 60;
    /** @var list<int> */
    private const RETRY_DELAYS = [5, 30, 120, 600];

    public function __construct(
        private readonly Outbox $outboxModel,
        private readonly Delivery $deliveryModel,
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly DeliveryCoalescer $coalescer,
        private readonly CanonicalJson $canonicalJson,
        private readonly ContextSnapshot $contextSnapshot,
        private readonly UniqueConstraintViolationDetector $uniqueViolation,
        private readonly AsyncEventConfig $config,
        private readonly AsyncErrorRedactor $errorRedactor,
    ) {
    }

    /** @return array{processed:int,expanded:int,dead:int,retried:int} */
    public function relayAvailable(int $limit = 50): array
    {
        $result = ['processed' => 0, 'expanded' => 0, 'dead' => 0, 'retried' => 0];
        if (!$this->config->relayEnabled()) {
            return $result;
        }
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException(__('Relay limit 必须是 1 到 1000 的整数'));
        }
        foreach ($this->availableIds($limit) as $outboxId) {
            $status = $this->relayId($outboxId);
            ++$result['processed'];
            if (isset($result[$status])) {
                ++$result[$status];
            }
        }
        return $result;
    }

    /** @return 'expanded'|'dead'|'retried'|'noop' */
    public function relayId(int $outboxId): string
    {
        if ($outboxId < 1 || !$this->config->relayEnabled()) {
            return 'noop';
        }
        try {
            return $this->transactions->run(
                $this->outboxModel->getConnection(),
                fn(): string => $this->expandInTransaction($outboxId),
            );
        } catch (AsyncEventValidationException $exception) {
            $this->recordFailure($outboxId, true, 'outbox_payload_invalid', $exception->getMessage());
            return 'dead';
        } catch (\Throwable $exception) {
            $this->recordFailure($outboxId, false, 'outbox_relay_failed', $exception->getMessage());
            return 'retried';
        }
    }

    private function expandInTransaction(int $outboxId): string
    {
        $outbox = $this->findOutbox($outboxId);
        if ($outbox === null) {
            return 'noop';
        }
        $status = (string)$outbox->getData(Outbox::schema_fields_STATUS);
        if (in_array($status, ['expanded', 'dead'], true)) {
            return 'noop';
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($status === 'pending'
            && (string)$outbox->getData(Outbox::schema_fields_AVAILABLE_AT) > $now) {
            return 'noop';
        }
        if ($status === 'relaying'
            && (string)$outbox->getData(Outbox::schema_fields_LEASE_EXPIRES_AT) > $now) {
            return 'noop';
        }
        if (!in_array($status, ['pending', 'relaying'], true)) {
            return 'noop';
        }

        $leaseToken = bin2hex(random_bytes(32));
        $lockVersion = (int)$outbox->getData(Outbox::schema_fields_LOCK_VERSION);
        if (!$this->updateOutbox([
            Outbox::schema_fields_ID => $outboxId,
            Outbox::schema_fields_STATUS => $status,
            Outbox::schema_fields_LOCK_VERSION => $lockVersion,
        ], [
            Outbox::schema_fields_STATUS => 'relaying',
            Outbox::schema_fields_LEASE_TOKEN => $leaseToken,
            Outbox::schema_fields_LEASE_EXPIRES_AT => gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS),
            Outbox::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ])) {
            return 'noop';
        }
        $outbox = $this->findOutbox($outboxId);
        if ($outbox === null) {
            throw new \RuntimeException(__('已 claim 的 Outbox 无法重读'));
        }

        $payload = $this->decodeObject((string)$outbox->getData(Outbox::schema_fields_PAYLOAD_JSON), 'payload');
        $context = $this->decodeObject((string)$outbox->getData(Outbox::schema_fields_CONTEXT_JSON), 'context');
        $targets = $this->decodeList((string)$outbox->getData(Outbox::schema_fields_OBSERVER_TARGETS_JSON), 'observer_targets');
        $this->contextSnapshot->validate($context);
        $expectedHash = $this->canonicalJson->hash([
            'payload' => $payload,
            'context' => $context,
            'observer_targets' => $targets,
        ]);
        if (!hash_equals((string)$outbox->getData(Outbox::schema_fields_PAYLOAD_SHA256), $expectedHash)) {
            throw new AsyncEventValidationException(__('Outbox 载荷指纹校验失败'));
        }

        foreach ($targets as $target) {
            if (!is_array($target)) {
                throw new AsyncEventValidationException(__('Outbox observer target 必须是 object'));
            }
            $delivery = $this->createDelivery($outbox, $payload, $context, $target);
            $this->coalescer->register($delivery, $target);
        }

        $claimedLockVersion = (int)$outbox->getData(Outbox::schema_fields_LOCK_VERSION);
        if (!$this->updateOutbox([
            Outbox::schema_fields_ID => $outboxId,
            Outbox::schema_fields_STATUS => 'relaying',
            Outbox::schema_fields_LEASE_TOKEN => $leaseToken,
            Outbox::schema_fields_LOCK_VERSION => $claimedLockVersion,
        ], [
            Outbox::schema_fields_STATUS => 'expanded',
            Outbox::schema_fields_EXPANDED_AT => gmdate('Y-m-d H:i:s'),
            Outbox::schema_fields_LEASE_TOKEN => null,
            Outbox::schema_fields_LEASE_EXPIRES_AT => null,
            Outbox::schema_fields_LAST_ERROR_CODE => '',
            Outbox::schema_fields_LAST_ERROR => null,
            Outbox::schema_fields_LOCK_VERSION => $claimedLockVersion + 1,
        ])) {
            throw new \RuntimeException(__('Outbox 展开终态 CAS 冲突'));
        }
        return 'expanded';
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $context @param array<string,mixed> $target */
    private function createDelivery(Outbox $outbox, array $payload, array $context, array $target): Delivery
    {
        foreach (['observer_key', 'module', 'name', 'instance_hash', 'retry', 'coalesce', 'timeout', 'max_attempts', 'coalesce_key'] as $key) {
            if (!array_key_exists($key, $target)) {
                throw new AsyncEventValidationException(__('Outbox observer target 缺少字段：%{1}', [$key]));
            }
        }
        if (isset($target['instance']) || isset($target['class'])) {
            throw new AsyncEventValidationException(__('Outbox observer target 不得包含可实例化类名'));
        }
        $eventId = (string)$outbox->getData(Outbox::schema_fields_EVENT_ID);
        $observerKey = (string)$target['observer_key'];
        $existing = $this->findDelivery($eventId, $observerKey);
        if ($existing !== null) {
            return $existing;
        }

        $payloadJson = $this->canonicalJson->encode($payload);
        $contextJson = $this->canonicalJson->encode($context);
        $resourceType = (string)($payload['resource']['type'] ?? '');
        $resourceId = (string)($payload['resource']['id'] ?? '');
        $resourceKey = $resourceType === '' || $resourceId === ''
            ? ''
            : hash('sha256', $resourceType . "\0" . $resourceId);
        $replayFields = [
            'replay_of_delivery_id',
            'replay_requested_by',
            'replay_requested_at',
        ];
        $presentReplayFields = array_values(array_filter(
            $replayFields,
            static fn(string $field): bool => array_key_exists($field, $target),
        ));
        if ($presentReplayFields !== [] && count($presentReplayFields) !== count($replayFields)) {
            throw new AsyncEventValidationException(__('Outbox 重放目标审计字段不完整'));
        }
        $replayOfDeliveryId = null;
        $replayRequestedBy = '';
        $replayRequestedAt = null;
        if ($presentReplayFields !== []) {
            $replayOfDeliveryId = (int)$target['replay_of_delivery_id'];
            $replayRequestedBy = trim((string)$target['replay_requested_by']);
            $replayTimestamp = strtotime((string)$target['replay_requested_at']);
            if ($replayOfDeliveryId < 1
                || !preg_match('/^admin:[1-9][0-9]*$/', $replayRequestedBy)
                || $replayTimestamp === false) {
                throw new AsyncEventValidationException(__('Outbox 重放目标审计字段无效'));
            }
            $replayRequestedAt = gmdate('Y-m-d H:i:s', $replayTimestamp);
        }
        $delivery = $this->newDelivery();
        $delivery->setData([
            Delivery::schema_fields_OUTBOX_ID => (int)$outbox->getData(Outbox::schema_fields_ID),
            Delivery::schema_fields_EVENT_ID => $eventId,
            Delivery::schema_fields_OBSERVER_KEY => $observerKey,
            Delivery::schema_fields_OBSERVER_MODULE => (string)$target['module'],
            Delivery::schema_fields_OBSERVER_NAME => (string)$target['name'],
            Delivery::schema_fields_OBSERVER_INSTANCE_HASH => (string)$target['instance_hash'],
            Delivery::schema_fields_PAYLOAD_SCHEMA_VERSION => (int)$outbox->getData(Outbox::schema_fields_PAYLOAD_SCHEMA_VERSION),
            Delivery::schema_fields_PAYLOAD_JSON => $payloadJson,
            Delivery::schema_fields_CONTEXT_JSON => $contextJson,
            Delivery::schema_fields_PAYLOAD_SHA256 => hash('sha256', $payloadJson),
            Delivery::schema_fields_RESOURCE_KEY => $resourceKey,
            Delivery::schema_fields_REVISION => (int)($payload['resource']['revision'] ?? 0),
            Delivery::schema_fields_RETRY_POLICY => (string)$target['retry'],
            Delivery::schema_fields_COALESCE_MODE => (string)$target['coalesce'],
            Delivery::schema_fields_COALESCE_KEY => (string)$target['coalesce_key'],
            Delivery::schema_fields_TIMEOUT_SECONDS => max(1, min(3600, (int)$target['timeout'])),
            Delivery::schema_fields_MAX_ATTEMPTS => max(1, (int)$target['max_attempts']),
            Delivery::schema_fields_STATUS => 'pending',
            Delivery::schema_fields_ATTEMPT_NO => 0,
            Delivery::schema_fields_LOCK_VERSION => 0,
            Delivery::schema_fields_REPLAY_OF_DELIVERY_ID => $replayOfDeliveryId,
            Delivery::schema_fields_REPLAY_REQUESTED_BY => $replayRequestedBy,
            Delivery::schema_fields_REPLAY_REQUESTED_AT => $replayRequestedAt,
        ]);
        try {
            $this->transactions->withSavepoint(
                $delivery->getConnection(),
                'event_delivery_create',
                static fn(): bool|int => $delivery->save(),
            );
            return $delivery;
        } catch (\Throwable $exception) {
            if (!$this->uniqueViolation->matches(
                $exception,
                'uk_event_delivery_target',
                $this->deliveryModel->getConnection()->getConfigProvider()->getPrefix() . Delivery::schema_table,
                Delivery::schema_fields_EVENT_ID,
            )) {
                throw $exception;
            }
            $existing = $this->findDelivery($eventId, $observerKey);
            if ($existing === null
                || !hash_equals(
                    (string)$existing->getData(Delivery::schema_fields_PAYLOAD_SHA256),
                    hash('sha256', $payloadJson),
                )) {
                throw new AsyncEventValidationException(__('幂等 Delivery 载荷不一致'), previous: $exception);
            }
            return $existing;
        }
    }

    private function recordFailure(int $outboxId, bool $dead, string $errorCode, string $error): void
    {
        try {
            $this->transactions->run($this->outboxModel->getConnection(), function () use ($outboxId, $dead, $errorCode, $error): void {
                $outbox = $this->findOutbox($outboxId);
                if ($outbox === null || in_array((string)$outbox->getData(Outbox::schema_fields_STATUS), ['expanded', 'dead'], true)) {
                    return;
                }
                $attempt = (int)$outbox->getData(Outbox::schema_fields_ATTEMPT_COUNT) + 1;
                $lockVersion = (int)$outbox->getData(Outbox::schema_fields_LOCK_VERSION);
                $status = (string)$outbox->getData(Outbox::schema_fields_STATUS);
                $delay = self::RETRY_DELAYS[max(0, min(count(self::RETRY_DELAYS) - 1, $attempt - 1))];
                $updated = $this->updateOutbox([
                    Outbox::schema_fields_ID => $outboxId,
                    Outbox::schema_fields_STATUS => $status,
                    Outbox::schema_fields_LOCK_VERSION => $lockVersion,
                ], [
                    Outbox::schema_fields_STATUS => $dead ? 'dead' : 'pending',
                    Outbox::schema_fields_ATTEMPT_COUNT => $attempt,
                    Outbox::schema_fields_AVAILABLE_AT => gmdate('Y-m-d H:i:s', time() + $delay),
                    Outbox::schema_fields_LEASE_TOKEN => null,
                    Outbox::schema_fields_LEASE_EXPIRES_AT => null,
                    Outbox::schema_fields_LAST_ERROR_CODE => substr($errorCode, 0, 64),
                    Outbox::schema_fields_LAST_ERROR => $this->errorRedactor->redact($error),
                    Outbox::schema_fields_LOCK_VERSION => $lockVersion + 1,
                ]);
                if ($updated) {
                    w_log_warning('event_async_relay_retry', [
                        'outbox_id' => $outboxId,
                        'error_code' => substr($errorCode, 0, 64),
                    ], 'event_async.log');
                }
            });
        } catch (\Throwable $recordFailure) {
            unset($recordFailure);
            w_log_error(
                'event_async_relay_retry',
                ['outbox_id' => $outboxId, 'error_code' => 'outbox_failure_persist_failed'],
                'event_async.log',
            );
        }
    }

    /** @return list<int> */
    private function availableIds(int $limit): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $buckets = [];
        foreach ([
            ['status' => 'pending', 'time_field' => Outbox::schema_fields_AVAILABLE_AT],
            ['status' => 'relaying', 'time_field' => Outbox::schema_fields_LEASE_EXPIRES_AT],
        ] as $scan) {
            $ids = [];
            $rows = $this->newOutbox()
                ->where(Outbox::schema_fields_STATUS, $scan['status'])
                ->where($scan['time_field'], $now, '<=')
                ->order(Outbox::schema_fields_ID, 'ASC')
                ->limit($limit)
                ->select(Outbox::schema_fields_ID)
                ->fetchArray();
            foreach ((array)$rows as $row) {
                $id = (int)($row[Outbox::schema_fields_ID] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $buckets[] = array_values($ids);
        }

        return $this->interleaveIdBuckets($buckets, $limit);
    }

    /** @param list<list<int>> $buckets @return list<int> */
    private function interleaveIdBuckets(array $buckets, int $limit): array
    {
        $result = [];
        for ($offset = 0; count($result) < $limit; $offset++) {
            $progress = false;
            foreach ($buckets as $bucket) {
                if (isset($bucket[$offset])) {
                    $result[] = $bucket[$offset];
                    $progress = true;
                    if (count($result) >= $limit) {
                        break 2;
                    }
                }
            }
            if (!$progress) {
                break;
            }
        }

        return $result;
    }

    private function findOutbox(int $outboxId): ?Outbox
    {
        $outbox = $this->newOutbox();
        $outbox->where(Outbox::schema_fields_ID, $outboxId)->find()->fetch();
        return $outbox->getId() ? $outbox : null;
    }

    private function findDelivery(string $eventId, string $observerKey): ?Delivery
    {
        $delivery = $this->newDelivery();
        $delivery->where(Delivery::schema_fields_EVENT_ID, $eventId)
            ->where(Delivery::schema_fields_OBSERVER_KEY, $observerKey)
            ->find()
            ->fetch();
        return $delivery->getId() ? $delivery : null;
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $updates */
    private function updateOutbox(array $expected, array $updates): bool
    {
        $query = $this->newOutbox();
        foreach ($expected as $field => $value) {
            $query->where((string)$field, $value);
        }
        $updates[Outbox::schema_fields_UPDATED_AT] = gmdate('Y-m-d H:i:s');
        $result = $query->getQuery()->update($updates)->fetch();
        return $result === true || (is_int($result) && $result === 1);
    }

    private function newOutbox(): Outbox
    {
        $model = clone $this->outboxModel;
        return $model->clearData()->clearQuery();
    }

    private function newDelivery(): Delivery
    {
        $model = clone $this->deliveryModel;
        return $model->clearData()->clearQuery();
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json, string $label): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AsyncEventValidationException(__('%{1} JSON 无效', [$label]), previous: $exception);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new AsyncEventValidationException(__('%{1} 必须是 JSON object', [$label]));
        }
        return $value;
    }

    /** @return list<mixed> */
    private function decodeList(string $json, string $label): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AsyncEventValidationException(__('%{1} JSON 无效', [$label]), previous: $exception);
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new AsyncEventValidationException(__('%{1} 必须是 JSON list', [$label]));
        }
        return $value;
    }
}
