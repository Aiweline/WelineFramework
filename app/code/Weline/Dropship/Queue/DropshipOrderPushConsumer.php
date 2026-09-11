<?php

declare(strict_types=1);

namespace Weline\Dropship\Queue;

use Weline\Dropship\Service\DropshipOutboxService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

class DropshipOrderPushConsumer implements QueueConsumerInterface
{
    public function name(): string
    {
        return (string)__('货源代发订单推送');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('处理 dropship push outbox');
    }

    public function validate(QueueTaskContextInterface $q): bool
    {
        $c = json_decode((string)$q->getContent(), true) ?: [];

        return isset($c['biz_key']) && is_string($c['biz_key']) && $c['biz_key'] !== '';
    }

    public function execute(QueueTaskContextInterface $q): string
    {
        $c = json_decode((string)$q->getContent(), true) ?: [];
        /** @var DropshipOutboxService $outbox */
        $outbox = ObjectManager::getInstance(DropshipOutboxService::class);
        $result = $outbox->processOne((string)$c['biz_key']);

        return ($result['ok'] ?? false) ? 'QUEUE_DONE' : ('QUEUE_ERROR:' . ($result['message'] ?? 'fail'));
    }
}
