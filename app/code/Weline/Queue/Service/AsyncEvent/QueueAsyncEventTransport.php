<?php

declare(strict_types=1);

namespace Weline\Queue\Service\AsyncEvent;

use Weline\Framework\Api\Event\AsyncEventTransportInterface;
use Weline\Queue\Api\QueueStatus;
use Weline\Queue\Model\Queue;
use Weline\Queue\Queue\AsyncEventDeliveryQueue;
use Weline\Queue\Service\QueueDispatchService;

final class QueueAsyncEventTransport implements AsyncEventTransportInterface
{
    public function __construct(
        private readonly Queue $queueModel,
        private readonly QueueDispatchService $dispatchService,
    ) {
    }

    public function name(): string
    {
        return 'weline_queue';
    }

    public function provision(
        int $deliveryId,
        int $attemptNo,
        string $idempotencyKey,
        array $content,
    ): array {
        $expectedKey = 'delivery:' . $deliveryId . ':attempt:' . $attemptNo;
        if ($deliveryId < 1 || $attemptNo < 1 || !\hash_equals($expectedKey, $idempotencyKey)) {
            throw new \InvalidArgumentException('queue_transport_provision_identity_invalid');
        }
        if (\count($content) !== 2
            || !\array_key_exists('delivery_id', $content)
            || !\array_key_exists('attempt_no', $content)
            || $content['delivery_id'] !== $deliveryId
            || $content['attempt_no'] !== $attemptNo) {
            throw new \InvalidArgumentException('queue_transport_content_invalid');
        }

        $created = w_query('queue', 'createIfAbsent', [
            'class' => AsyncEventDeliveryQueue::class,
            'name' => (string)__('异步事件投递 #%{1} 尝试 %{2}', [$deliveryId, $attemptNo]),
            'module' => 'Weline_Queue',
            'content' => $content,
            'status' => QueueStatus::PENDING,
            // Delivery bind 前保持对普通 pending-auto scanner 不可见。
            'auto' => false,
            'biz_key' => 'async-event-delivery:' . $deliveryId,
            'idempotency_key' => $idempotencyKey,
            'dispatch' => false,
        ]);
        if (!\is_array($created)
            || ($created['success'] ?? null) !== true
            || !isset($created['queue_id'])
            || !\is_int($created['queue_id'])
            || $created['queue_id'] < 1
            || !\array_key_exists('created', $created)
            || !\is_bool($created['created'])
            || !\array_key_exists('dispatched', $created)
            || $created['dispatched'] !== false
            || !\is_string($created['status'] ?? null)) {
            throw new \UnexpectedValueException('queue_transport_provision_result_invalid');
        }
        $queue = $this->loadQueue($created['queue_id']);
        $expectedContent = \json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($queue === null
            || !$this->isTransportQueue($queue)
            || $queue->getModule() !== 'Weline_Queue'
            || !\is_string($expectedContent)
            || !\hash_equals($expectedContent, $queue->getContent())
            || $queue->getStatus() !== Queue::status_pending
            || $queue->getAuto()
            || $queue->isFinished()) {
            throw new \UnexpectedValueException('queue_transport_provisioned_queue_invalid');
        }

        return [
            'handle' => 'queue:' . $created['queue_id'],
            'metadata' => [
                'queue_id' => $created['queue_id'],
                'status' => \is_string($created['status'] ?? null) ? $created['status'] : '',
            ],
            'created' => $created['created'],
        ];
    }

    public function dispatch(string $handle): array
    {
        $queueId = $this->queueId($handle);
        $queue = $this->loadQueue($queueId);
        if ($queue === null || !$this->isTransportQueue($queue)) {
            return ['accepted' => false, 'operation_id' => '', 'error_code' => 'queue_not_found'];
        }
        if ($queue->getStatus() === Queue::status_error) {
            $this->dispatchService->requeueTransportAttempt($queueId, $queue->getDispatchToken());
            $queue = $this->loadQueue($queueId);
            if ($queue === null || !$this->isTransportQueue($queue)) {
                return ['accepted' => false, 'operation_id' => '', 'error_code' => 'queue_not_found'];
            }
        }
        if ($queue->getStatus() === Queue::status_pending && !$queue->getAuto()) {
            $this->dispatchService->activateTransportAttempt($queueId);
            $queue = $this->loadQueue($queueId);
            if ($queue === null || !$this->isTransportQueue($queue)) {
                return ['accepted' => false, 'operation_id' => '', 'error_code' => 'queue_not_found'];
            }
        }
        if ($queue->getStatus() === Queue::status_running && $queue->getDispatchToken() !== '') {
            return [
                'accepted' => true,
                'operation_id' => $queue->getDispatchToken(),
                'error_code' => '',
            ];
        }
        if ($queue->getStatus() !== Queue::status_pending || !$queue->getAuto() || $queue->isFinished()) {
            return ['accepted' => false, 'operation_id' => '', 'error_code' => 'queue_not_dispatchable'];
        }

        $this->dispatchService->dispatchQueueIfEligible($queue);
        $queue = $this->loadQueue($queueId);
        if ($queue !== null
            && $queue->getStatus() === Queue::status_running
            && $queue->getDispatchToken() !== '') {
            return [
                'accepted' => true,
                'operation_id' => $queue->getDispatchToken(),
                'error_code' => '',
            ];
        }

        return ['accepted' => false, 'operation_id' => '', 'error_code' => 'queue_dispatch_deferred'];
    }

    public function terminate(string $handle, string $fenceToken): array
    {
        $queueId = $this->queueId($handle);
        $queue = $this->loadQueue($queueId);
        if ($queue === null || !$this->isTransportQueue($queue)) {
            return ['confirmed' => false, 'retryable' => false, 'error_code' => 'queue_not_found'];
        }

        return $this->dispatchService->terminateClaimedQueue($queueId, $fenceToken);
    }

    private function queueId(string $handle): int
    {
        if (\preg_match('/^queue:([1-9]\d*)$/', $handle, $matches) !== 1) {
            throw new \InvalidArgumentException('queue_transport_handle_invalid');
        }

        return (int)$matches[1];
    }

    private function loadQueue(int $queueId): ?Queue
    {
        $queue = clone $this->queueModel;
        $queue->clearData()->clearQuery()
            ->where(Queue::schema_fields_ID, $queueId)
            ->find()
            ->fetch();

        return (int)$queue->getId() > 0 ? $queue : null;
    }

    private function isTransportQueue(Queue $queue): bool
    {
        try {
            return \ltrim((string)$queue->getType()->getClass(), '\\') === AsyncEventDeliveryQueue::class;
        } catch (\Throwable) {
            return false;
        }
    }
}
