<?php

declare(strict_types=1);

namespace Weline\Order\Queue;

use Weline\Order\Service\OrderRefundCoordinator;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

/**
 * 退款渠道提交、状态对账与到账后副作用的统一 durable consumer。
 */
final class OrderRefundOutboxConsumer implements QueueConsumerInterface
{
    public function __construct(
        private readonly OrderRefundCoordinator $coordinator,
    ) {
    }

    public function name(): string
    {
        return (string)__('订单退款 Outbox 消费');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('幂等处理退款渠道提交、状态对账及到账后的库存和通知任务');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $content = trim($queue->getContent());
        if ($content === '') {
            return true;
        }
        $payload = json_decode($content, true);

        return \is_array($payload)
            && (
                trim((string)($payload['outbox_code'] ?? '')) !== ''
                || (int)($payload['limit'] ?? 0) > 0
            );
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $content = trim($queue->getContent());
        $payload = $content === ''
            ? []
            : json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        $outboxCode = trim((string)($payload['outbox_code'] ?? ''));
        $results = $outboxCode !== ''
            ? [$this->coordinator->processOneOutbox($outboxCode)]
            : $this->coordinator->processPendingOutbox(
                max(1, min(100, (int)($payload['limit'] ?? 20))),
            );
        $failed = array_values(array_filter(
            $results,
            static fn (array $result): bool => empty($result['ok']),
        ));
        if ($failed !== []) {
            throw new \RuntimeException((string)(
                $failed[0]['error_code'] ?? 'refund_outbox_processing_failed'
            ));
        }

        $replayed = count(array_filter(
            $results,
            static fn (array $result): bool => !empty($result['replayed']),
        ));

        return sprintf(
            'QUEUE_DONE: order_refund_outbox processed=%d replayed=%d',
            count($results) - $replayed,
            $replayed,
        );
    }
}
