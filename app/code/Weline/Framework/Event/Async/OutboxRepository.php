<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Database\Exception\UniqueConstraintViolationDetector;
use Weline\Framework\Database\Transaction\Exception\UnsupportedAsyncTransactionConnectionException;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Model\Event\Outbox;

final class OutboxRepository
{
    public function __construct(
        private readonly Outbox $outboxModel,
        private readonly CanonicalJson $canonicalJson,
        private readonly UniqueConstraintViolationDetector $uniqueViolation,
        private readonly TransactionCoordinatorInterface $transactions,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $context
     * @param list<array<string,mixed>> $observerTargets
     */
    public function append(
        string $eventName,
        int $schemaVersion,
        array $payload,
        array $context,
        array $observerTargets,
    ): Outbox {
        $connector = $this->outboxModel->getConnection()->getConnector();
        if (!TransactionContext::isSoleActiveConnector($connector)) {
            throw new UnsupportedAsyncTransactionConnectionException(
                __('可靠异步事件要求 Framework 默认主库是唯一活动事务连接'),
            );
        }
        $eventId = (string)($payload['event_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $eventId)) {
            throw new AsyncEventValidationException(__('异步 Outbox 缺少合法 event_id'));
        }
        $payloadJson = $this->canonicalJson->encode($payload);
        $contextJson = $this->canonicalJson->encode($context);
        $targetsJson = $this->canonicalJson->encode($observerTargets, false);
        $payloadSha256 = $this->canonicalJson->hash([
            'payload' => $payload,
            'context' => $context,
            'observer_targets' => $observerTargets,
        ]);

        $outbox = $this->newOutbox();
        $outbox->setData([
            Outbox::schema_fields_EVENT_ID => $eventId,
            Outbox::schema_fields_EVENT_NAME => $eventName,
            Outbox::schema_fields_PAYLOAD_SCHEMA_VERSION => $schemaVersion,
            Outbox::schema_fields_PAYLOAD_JSON => $payloadJson,
            Outbox::schema_fields_CONTEXT_JSON => $contextJson,
            Outbox::schema_fields_OBSERVER_TARGETS_JSON => $targetsJson,
            Outbox::schema_fields_PAYLOAD_SHA256 => $payloadSha256,
            Outbox::schema_fields_STATUS => 'pending',
            Outbox::schema_fields_ATTEMPT_COUNT => 0,
            Outbox::schema_fields_LOCK_VERSION => 0,
            Outbox::schema_fields_OCCURRED_AT => $this->databaseTimestamp((string)($payload['occurred_at'] ?? '')),
        ]);

        try {
            $this->transactions->withSavepoint(
                $outbox->getConnection(),
                'event_outbox_append',
                static fn(): bool|int => $outbox->save(),
            );
            w_log_info('event_async_outbox_created', [
                'event_id' => $eventId,
                'outbox_id' => (int)$outbox->getData(Outbox::schema_fields_ID),
            ], 'event_async.log');
            return $outbox;
        } catch (\Throwable $exception) {
            if (!$this->uniqueViolation->matches(
                $exception,
                'uk_event_outbox_event',
                $this->outboxModel->getConnection()->getConfigProvider()->getPrefix() . Outbox::schema_table,
                Outbox::schema_fields_EVENT_ID,
            )) {
                throw $exception;
            }
            $existing = $this->findByEventId($eventId);
            if ($existing === null || (string)$existing->getData(Outbox::schema_fields_PAYLOAD_SHA256) !== $payloadSha256) {
                throw new AsyncEventValidationException(
                    __('Outbox event_id 重复且载荷指纹不一致'),
                    previous: $exception,
                );
            }
            return $existing;
        }
    }

    public function findByEventId(string $eventId): ?Outbox
    {
        $outbox = $this->newOutbox();
        $outbox->where(Outbox::schema_fields_EVENT_ID, $eventId)->find()->fetch();
        return $outbox->getId() ? $outbox : null;
    }

    public function findById(int $outboxId): ?Outbox
    {
        if ($outboxId < 1) {
            return null;
        }
        $outbox = $this->newOutbox();
        $outbox->where(Outbox::schema_fields_ID, $outboxId)->find()->fetch();
        return $outbox->getId() ? $outbox : null;
    }

    private function newOutbox(): Outbox
    {
        $model = clone $this->outboxModel;
        return $model->clearData()->clearQuery();
    }

    private function databaseTimestamp(string $occurredAt): string
    {
        $seconds = strtotime($occurredAt);
        return $seconds === false ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s', $seconds);
    }
}
