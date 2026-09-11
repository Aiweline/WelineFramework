<?php

declare(strict_types=1);

namespace Weline\Dropship\Queue;

use Weline\Dropship\Service\DropshipWebhookInboxService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

class DropshipWebhookInboxConsumer implements QueueConsumerInterface
{
    public function name(): string
    {
        return (string)__('货源代发 Webhook Inbox');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('推进 dropship webhook inbox 到履约投影');
    }

    public function validate(QueueTaskContextInterface $q): bool
    {
        $c = json_decode((string)$q->getContent(), true) ?: [];

        return isset($c['inbox_id']) && (int)$c['inbox_id'] > 0;
    }

    public function execute(QueueTaskContextInterface $q): string
    {
        $c = json_decode((string)$q->getContent(), true) ?: [];
        /** @var DropshipWebhookInboxService $svc */
        $svc = ObjectManager::getInstance(DropshipWebhookInboxService::class);
        $result = $svc->processOne((int)$c['inbox_id']);

        return ($result['ok'] ?? false) ? 'QUEUE_DONE' : ('QUEUE_ERROR:' . ($result['message'] ?? 'fail'));
    }
}
