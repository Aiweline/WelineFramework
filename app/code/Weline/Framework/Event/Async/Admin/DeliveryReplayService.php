<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async\Admin;

use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\Async\AsyncPayloadMapperResolver;
use Weline\Framework\Event\Async\CanonicalJson;
use Weline\Framework\Event\Async\ContextSnapshot;
use Weline\Framework\Event\Async\ObserverInvoker;
use Weline\Framework\Event\Async\OutboxRelayScheduler;
use Weline\Framework\Event\Async\OutboxRepository;
use Weline\Framework\Model\Event\Delivery;
use Weline\Framework\Model\Event\Outbox;

final class DeliveryReplayService
{
    public function __construct(
        private readonly Delivery $deliveryModel,
        private readonly Outbox $outboxModel,
        private readonly DeliveryAccessPolicy $accessPolicy,
        private readonly ObserverInvoker $observerInvoker,
        private readonly AsyncPayloadMapperResolver $mapperResolver,
        private readonly CanonicalJson $canonicalJson,
        private readonly ContextSnapshot $contextSnapshot,
        private readonly OutboxRepository $outboxRepository,
        private readonly OutboxRelayScheduler $relayScheduler,
        private readonly TransactionCoordinatorInterface $transactions,
    ) {
    }

    /** @return array{event_id:string,outbox_id:int,replay_of_delivery_id:int,replay_requested_by:string,replay_requested_at:string} */
    public function replay(int $deliveryId, int $websiteId, string $reason): array
    {
        if ($deliveryId < 1) {
            throw new \InvalidArgumentException((string)__('delivery_id 必须是正整数'));
        }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw new \InvalidArgumentException((string)__('重放原因必须是 1–500 字节'));
        }
        $this->accessPolicy->requirePermission(DeliveryAccessPolicy::ACL_VIEW);
        $actor = $this->accessPolicy->requirePermission(DeliveryAccessPolicy::ACL_REPLAY);

        return $this->transactions->run(
            $this->outboxModel->getConnection(),
            function () use ($deliveryId, $websiteId, $reason, $actor): array {
                $delivery = $this->findDelivery($deliveryId, true);
                if ($delivery === null || (string)$delivery->getData(Delivery::schema_fields_STATUS) !== 'dead') {
                    throw new \RuntimeException((string)__('仅允许重放仍处于 dead 终态的 Delivery'));
                }
                if (!$this->canReplay($delivery)) {
                    throw new \RuntimeException(
                        (string)__('Transport 终止状态未确认，禁止重放该 Delivery'),
                    );
                }

                $payloadJson = (string)$delivery->getData(Delivery::schema_fields_PAYLOAD_JSON);
                if (!hash_equals(
                    (string)$delivery->getData(Delivery::schema_fields_PAYLOAD_SHA256),
                    hash('sha256', $payloadJson),
                )) {
                    throw new \RuntimeException((string)__('Delivery 载荷指纹校验失败'));
                }
                $payload = $this->decodeObject($payloadJson, 'payload');
                $context = $this->decodeObject(
                    (string)$delivery->getData(Delivery::schema_fields_CONTEXT_JSON),
                    'context',
                );
                $this->contextSnapshot->validate($context);
                $this->accessPolicy->assertPayloadWebsite($payload, $websiteId);

                $outbox = $this->findOutbox((int)$delivery->getData(Delivery::schema_fields_OUTBOX_ID));
                $eventName = (string)($payload['event_name'] ?? '');
                if ($outbox === null
                    || $eventName === ''
                    || (string)$outbox->getData(Outbox::schema_fields_EVENT_NAME) !== $eventName
                    || (string)$outbox->getData(Outbox::schema_fields_EVENT_ID)
                        !== (string)$delivery->getData(Delivery::schema_fields_EVENT_ID)) {
                    throw new \RuntimeException((string)__('Delivery 与原 Outbox 审计链不一致'));
                }

                $schemaVersion = (int)$delivery->getData(Delivery::schema_fields_PAYLOAD_SCHEMA_VERSION);
                $observerKey = (string)$delivery->getData(Delivery::schema_fields_OBSERVER_KEY);
                $observer = $this->observerInvoker->resolve($eventName, $observerKey, $schemaVersion);
                if ((int)($observer['event_schema_version'] ?? 0) !== $schemaVersion) {
                    throw new \RuntimeException((string)__('当前 Observer 的事件 schema 已与原 Delivery 不兼容'));
                }
                $mapper = $this->mapperResolver->resolve(
                    (string)($observer['event_async_mapper'] ?? ''),
                    $eventName,
                    $schemaVersion,
                );

                $newEventId = bin2hex(random_bytes(16));
                $requestedAt = $this->utcMicrotime();
                $requestedBy = 'admin:' . $actor['user_id'];
                $payload['event_id'] = $newEventId;
                $payload['occurred_at'] = $requestedAt;
                $payload['origin'] = is_array($payload['origin'] ?? null) ? $payload['origin'] : [];
                $payload['origin']['replay'] = [
                    'delivery_id' => $deliveryId,
                    'requested_by' => $requestedBy,
                    'requested_at' => $requestedAt,
                    'reason' => $reason,
                ];
                if (is_array($payload['context'] ?? null)
                    && !hash_equals(
                        $this->canonicalJson->hash($payload['context']),
                        $this->canonicalJson->hash($context),
                    )) {
                    throw new \RuntimeException((string)__('Delivery payload 与 context 审计链不一致'));
                }
                $mapper->validate($payload);

                $retry = (string)($observer['retry'] ?? 'standard');
                $coalesce = (string)($observer['coalesce'] ?? 'none');
                $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
                $resourceType = (string)($resource['type'] ?? '');
                $resourceId = (string)($resource['id'] ?? '');
                $target = [
                    'observer_key' => $observerKey,
                    'module' => (string)($observer['module'] ?? ''),
                    'name' => (string)($observer['name'] ?? ''),
                    'instance_hash' => (string)($observer['instance_hash'] ?? ''),
                    'retry' => $retry,
                    'coalesce' => $coalesce,
                    'timeout' => (int)($observer['timeout'] ?? 30),
                    'max_attempts' => $retry === 'none' ? 1 : 6,
                    'coalesce_key' => $coalesce === 'latest' && $resourceType !== '' && $resourceId !== ''
                        ? $resourceType . ':' . $resourceId
                        : '',
                    'replay_of_delivery_id' => $deliveryId,
                    'replay_requested_by' => $requestedBy,
                    'replay_requested_at' => $requestedAt,
                ];
                $newOutbox = $this->outboxRepository->append(
                    $eventName,
                    $schemaVersion,
                    $payload,
                    $context,
                    [$target],
                );
                $newOutboxId = (int)$newOutbox->getData(Outbox::schema_fields_ID);
                $this->relayScheduler->afterCommit($newOutboxId);

                w_log_info('event_async_replay_requested', [
                    'event_id' => $newEventId,
                    'outbox_id' => $newOutboxId,
                    'delivery_id' => $deliveryId,
                    'observer_key' => $observerKey,
                ], 'event_async.log');

                return [
                    'event_id' => $newEventId,
                    'outbox_id' => $newOutboxId,
                    'replay_of_delivery_id' => $deliveryId,
                    'replay_requested_by' => $requestedBy,
                    'replay_requested_at' => $requestedAt,
                ];
            },
        );
    }

    public function canReplay(Delivery $delivery): bool
    {
        return (string)$delivery->getData(Delivery::schema_fields_STATUS) === 'dead'
            && (string)$delivery->getData(Delivery::schema_fields_TERMINAL_REASON)
                !== \Weline\Framework\Event\Async\DeliveryStateMachine::TERMINAL_REASON_TRANSPORT_TERMINATION_UNCONFIRMED;
    }

    private function findDelivery(int $deliveryId, bool $lockingRead = false): ?Delivery
    {
        $delivery = clone $this->deliveryModel;
        $delivery->clearData()->clearQuery()
            ->where(Delivery::schema_fields_ID, $deliveryId);
        if ($lockingRead && $this->supportsForUpdate()) {
            $delivery->additional('FOR UPDATE');
        }
        $delivery->find()->fetch();
        return $delivery->getId() ? $delivery : null;
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->deliveryModel->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());

        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function findOutbox(int $outboxId): ?Outbox
    {
        if ($outboxId < 1) {
            return null;
        }
        $outbox = clone $this->outboxModel;
        $outbox->clearData()->clearQuery()
            ->where(Outbox::schema_fields_ID, $outboxId)
            ->find()
            ->fetch();
        return $outbox->getId() ? $outbox : null;
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json, string $label): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException((string)__('%{1} JSON 无效', [$label]), previous: $exception);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException((string)__('%{1} 必须是 JSON object', [$label]));
        }
        return $value;
    }

    private function utcMicrotime(): string
    {
        $now = microtime(true);
        $seconds = (int)$now;
        $micros = (int)round(($now - $seconds) * 1_000_000);
        if ($micros >= 1_000_000) {
            ++$seconds;
            $micros = 0;
        }
        return gmdate('Y-m-d\TH:i:s', $seconds) . '.' . sprintf('%06d', $micros) . 'Z';
    }
}
