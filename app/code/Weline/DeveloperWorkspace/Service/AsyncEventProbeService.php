<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service;

use Weline\DeveloperWorkspace\Observer\AsyncEventProbeObserver;
use Weline\Framework\Api\Event\AsyncEventDeliveryMaintenanceInterface;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\Async\AsyncEventConfig;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Model\Event\Delivery;
use Weline\Framework\Model\Event\Outbox;

final class AsyncEventProbeService
{
    private const RESOURCE_TYPE = 'async_probe';
    private const OBSERVER_KEY = 'Weline_DeveloperWorkspace::' . AsyncEventProbeObserver::OBSERVER_NAME;

    public function __construct(
        private readonly AsyncEventConfig $config,
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly ResourceRevisionService $revisions,
        private readonly ResourceChangeFactory $changes,
        private readonly Outbox $outboxModel,
        private readonly Delivery $deliveryModel,
        private readonly AsyncEventDeliveryMaintenanceInterface $maintenance,
    ) {
    }

    /** @return array<string,mixed> */
    public function trigger(): array
    {
        if (!$this->config->producerEnabled()) {
            throw new \RuntimeException((string)__('异步事件 producer 未开启：event.async.producer_enabled=false'));
        }
        $probeId = 'webui-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $connection = $this->outboxModel->getConnection();
        $previousArea = WelineEnv::getArea();
        try {
            // Backend bin-query enters through the REST backend transport. The
            // persisted event contract deliberately records the business area.
            WelineEnv::setArea('backend');
            $change = $this->transactions->run($connection, function () use ($probeId) {
                $revision = $this->revisions->next(self::RESOURCE_TYPE, $probeId);
                $change = $this->changes->create(
                    resourceType: self::RESOURCE_TYPE,
                    resourceId: $probeId,
                    action: 'upsert',
                    revision: $revision,
                    websiteId: 0,
                    websiteCode: 'default',
                    before: [],
                    after: ['probe_marker' => $probeId],
                    changedFields: ['probe_marker'],
                    impact: [],
                    origin: ['entry' => 'developer.async-event-probe'],
                );
                w_changed($change);
                return $change;
            });
        } finally {
            WelineEnv::setArea($previousArea);
        }

        $status = $this->status($change->eventId());
        return [
            'event_id' => $change->eventId(),
            'probe_id' => $probeId,
            'request_boundary_proof' => [
                'outbox_committed' => ($status['outbox']['id'] ?? 0) > 0,
                'probe_delivery_absent_before_relay' => $status['probe_delivery'] === null,
                'observer_not_succeeded_before_response' => !$status['async_observer_succeeded'],
                'meaning' => (string)__('HTTP 请求返回时探针 Observer 尚未成功；Relay 可能已经并发创建 pending Delivery。'),
            ],
            'status' => $status,
        ];
    }

    /** @return array<string,mixed> */
    public function advance(string $eventId): array
    {
        $this->assertEventId($eventId);
        $result = [
            'relay' => $this->maintenance->relayOutbox(20),
            'provision' => $this->maintenance->provisionDue(20),
            'reconcile' => $this->maintenance->reconcileTransport(20),
        ];
        return ['maintenance' => $result, 'status' => $this->status($eventId)];
    }

    /** @return array<string,mixed> */
    public function status(string $eventId): array
    {
        $this->assertEventId($eventId);
        $outbox = $this->newOutbox();
        $outbox->where(Outbox::schema_fields_EVENT_ID, $eventId)->find()->fetch();
        $rows = $this->newDelivery()
            ->where(Delivery::schema_fields_EVENT_ID, $eventId)
            ->order(Delivery::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        $deliveries = [];
        $probeDelivery = null;
        foreach ((array)$rows as $row) {
            $item = [
                'id' => (int)($row[Delivery::schema_fields_ID] ?? 0),
                'observer_key' => (string)($row[Delivery::schema_fields_OBSERVER_KEY] ?? ''),
                'observer_name' => (string)($row[Delivery::schema_fields_OBSERVER_NAME] ?? ''),
                'status' => (string)($row[Delivery::schema_fields_STATUS] ?? ''),
                'attempt_no' => (int)($row[Delivery::schema_fields_ATTEMPT_NO] ?? 0),
                'transport' => (string)($row[Delivery::schema_fields_TRANSPORT_NAME] ?? ''),
                'queue_id' => isset($row[Delivery::schema_fields_QUEUE_ID])
                    ? (int)$row[Delivery::schema_fields_QUEUE_ID]
                    : null,
                'created_at' => $row[Delivery::schema_fields_CREATED_AT] ?? null,
                'started_at' => $row[Delivery::schema_fields_STARTED_AT] ?? null,
                'finished_at' => $row[Delivery::schema_fields_FINISHED_AT] ?? null,
                'error_code' => (string)($row[Delivery::schema_fields_LAST_ERROR_CODE] ?? ''),
                'error' => (string)($row[Delivery::schema_fields_LAST_ERROR] ?? ''),
            ];
            $deliveries[] = $item;
            if ($item['observer_key'] === self::OBSERVER_KEY) {
                $probeDelivery = $item;
            }
        }

        return [
            'event_id' => $eventId,
            'producer_enabled' => $this->config->producerEnabled(),
            'relay_enabled' => $this->config->relayEnabled(),
            'outbox' => $outbox->getId() ? [
                'id' => (int)$outbox->getData(Outbox::schema_fields_ID),
                'status' => (string)$outbox->getData(Outbox::schema_fields_STATUS),
                'created_at' => $outbox->getData(Outbox::schema_fields_CREATED_AT),
                'expanded_at' => $outbox->getData(Outbox::schema_fields_EXPANDED_AT),
            ] : null,
            'deliveries' => $deliveries,
            'probe_delivery' => $probeDelivery,
            'async_observer_succeeded' => ($probeDelivery['status'] ?? '') === 'succeeded',
        ];
    }

    private function assertEventId(string $eventId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $eventId) !== 1) {
            throw new \InvalidArgumentException((string)__('event_id 格式无效'));
        }
    }

    private function newOutbox(): Outbox
    {
        return (clone $this->outboxModel)->clearData()->clearQuery();
    }

    private function newDelivery(): Delivery
    {
        return (clone $this->deliveryModel)->clearData()->clearQuery();
    }
}
