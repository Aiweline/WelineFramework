<?php

declare(strict_types=1);

namespace Weline\Order\Queue;

use Weline\Order\Service\PaymentEffectConsumer;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

/** Queue adapter for durable post-payment Order effects. */
final class OrderPaymentEffectConsumer implements QueueConsumerInterface
{
    public function __construct(
        private readonly PaymentEffectConsumer $effects,
    ) {
    }

    public function name(): string
    {
        return (string)__('订单支付后副作用消费');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('幂等生成最小发票并准备履约动作');
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
            ? [$this->effects->processOne($outboxCode)]
            : $this->effects->processPending(
                max(1, min(100, (int)($payload['limit'] ?? 20))),
            );

        return sprintf(
            'QUEUE_DONE: order_payment_effect processed=%d replayed=%d',
            count(array_filter(
                $results,
                static fn (array $result): bool => empty($result['replayed']),
            )),
            count(array_filter(
                $results,
                static fn (array $result): bool => !empty($result['replayed']),
            )),
        );
    }
}
