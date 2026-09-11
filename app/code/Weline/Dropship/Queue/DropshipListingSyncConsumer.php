<?php

declare(strict_types=1);

namespace Weline\Dropship\Queue;

use Weline\Dropship\Service\DropshipFollowService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

class DropshipListingSyncConsumer implements QueueConsumerInterface
{
    public function name(): string
    {
        return (string)__('货源商品跟随同步');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('同步单个 listing 的远程价库存上下架');
    }

    public function validate(QueueTaskContextInterface $q): bool
    {
        $c = json_decode((string)$q->getContent(), true) ?: [];

        return isset($c['listing_id']) && (int)$c['listing_id'] > 0;
    }

    public function execute(QueueTaskContextInterface $q): string
    {
        $c = json_decode((string)$q->getContent(), true) ?: [];
        /** @var DropshipFollowService $follow */
        $follow = ObjectManager::getInstance(DropshipFollowService::class);
        $result = $follow->syncListing((int)$c['listing_id'], (string)($c['storage_scope'] ?? 'default.default.default'));

        return ($result['ok'] ?? false) ? 'QUEUE_DONE' : ('QUEUE_ERROR:' . ($result['message'] ?? 'fail'));
    }
}
