<?php

declare(strict_types=1);

namespace Weline\Payment\Queue;

use Weline\Payment\Service\PaymentAssetEffectConsumer;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

/** Queue adapter for durable Payment asset terminal effects. */
final class PaymentAssetEffectQueueConsumer implements QueueConsumerInterface
{
    public function __construct(
        private readonly PaymentAssetEffectConsumer $effects,
    ) {
    }

    public function name(): string
    {
        return (string) __('Payment 资产终态消费');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string) __('独立重试资产 commit/release，不重复调用现金 Provider');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $content = trim($queue->getContent());
        if ($content === '') {
            return true;
        }
        $payload = json_decode($content, true);

        return is_array($payload)
            && (
                trim((string) ($payload['outbox_code'] ?? '')) !== ''
                || (int) ($payload['limit'] ?? 0) > 0
            );
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $content = trim($queue->getContent());
        $payload = $content === ''
            ? []
            : json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        $outboxCode = trim((string) ($payload['outbox_code'] ?? ''));
        $results = $outboxCode !== ''
            ? [$this->effects->processOne($outboxCode)]
            : $this->effects->processPending(
                max(1, min(100, (int) ($payload['limit'] ?? 20))),
            );
        $failed = array_values(array_filter(
            $results,
            static fn (array $result): bool => empty($result['ok']),
        ));
        if ($failed !== []) {
            throw new \RuntimeException(
                (string) ($failed[0]['error_code'] ?? 'payment_asset_effect_failed'),
            );
        }

        return sprintf(
            'QUEUE_DONE: payment_asset_effect processed=%d replayed=%d',
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
