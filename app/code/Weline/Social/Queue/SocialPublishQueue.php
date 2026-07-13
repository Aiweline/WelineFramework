<?php

declare(strict_types=1);

namespace Weline\Social\Queue;

use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Social\Service\SocialPublishService;

class SocialPublishQueue implements QueueConsumerInterface
{
    public function name(): string
    {
        return (string)__('社媒发布队列');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('按 SocialPublishTarget 执行单个平台发布。');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $content = \json_decode((string)$queue->getContent(), true);
        if (!\is_array($content) || (int)($content['target_id'] ?? 0) <= 0) {
            $queue->setResult((string)__('缺少 target_id。'));
            return false;
        }

        return true;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $content = \json_decode((string)$queue->getContent(), true);
        $targetId = (int)($content['target_id'] ?? 0);
        $result = ObjectManager::getInstance(SocialPublishService::class)->processTarget($targetId);

        return \json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: (string)__('社媒发布完成');
    }
}
