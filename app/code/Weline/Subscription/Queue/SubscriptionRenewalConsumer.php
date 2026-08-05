<?php

declare(strict_types=1);

namespace Weline\Subscription\Queue;

use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Subscription\Service\SubscriptionSchedulerService;

/** Official Queue adapter for Subscription tick/recover operations. */
final class SubscriptionRenewalConsumer implements QueueConsumerInterface
{
    public function __construct(
        private readonly SubscriptionSchedulerService $scheduler,
    ) {
    }

    public function name(): string
    {
        return (string) __('Subscription 周期续费');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string) __('按 Subscription lease 执行单周期续费或 missed 恢复');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $payload = json_decode(trim($queue->getContent()), true);
        if (!\is_array($payload)) {
            $queue->setResult((string) __('Subscription renewal queue content 必须是 JSON object'));
            return false;
        }
        $operation = strtolower(trim((string) ($payload['operation'] ?? 'tick')));
        $valid = trim((string) ($payload['subscription_id'] ?? '')) !== ''
            && \in_array($operation, ['tick', 'recover'], true)
            && ($operation !== 'recover' || (int) ($payload['period_index'] ?? 0) > 0);
        if (!$valid) {
            $queue->setResult((string) __('Subscription renewal queue 参数非法'));
        }
        return $valid;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $payload = json_decode(trim($queue->getContent()), true, 32, JSON_THROW_ON_ERROR);
        $subscriptionId = trim((string) ($payload['subscription_id'] ?? ''));
        $operation = strtolower(trim((string) ($payload['operation'] ?? 'tick')));
        $workerId = trim((string) ($payload['worker_id'] ?? ''));
        if ($workerId === '') {
            $workerId = 'queue:' . ($queue->getBizKey() !== ''
                ? $queue->getBizKey()
                : (string) $queue->getId());
        }
        $result = $operation === 'recover'
            ? $this->scheduler->recover(
                $subscriptionId,
                $workerId,
                (int) ($payload['period_index'] ?? 0),
            )
            : $this->scheduler->tick($subscriptionId, $workerId);

        return json_encode([
            'marker' => 'QUEUE_DONE',
            'operation' => $operation,
            'subscription_id' => $subscriptionId,
            'period_key' => $result['period']['period_key'] ?? null,
            'order_ref' => $result['order_ref'] ?? null,
            'attempt_status' => $result['attempt_status'] ?? null,
            'replayed' => !empty($result['replayed']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'QUEUE_DONE';
    }
}

