<?php

declare(strict_types=1);

namespace Weline\Social\Queue;

use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Social\Service\SocialVideoComposer;

class SocialVideoRenderQueue implements QueueConsumerInterface
{
    public function name(): string
    {
        return (string)__('社媒视频合成队列');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('按 images + audio_path 调用 ffmpeg 合成 9:16 竖版视频，结果写回队列结果。');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $content = \json_decode((string)$queue->getContent(), true);
        if (!\is_array($content) || (int)($content['job_id'] ?? 0) <= 0) {
            $queue->setResult((string)__('缺少 job_id。'));
            return false;
        }
        if (!\is_array($content['images'] ?? null) || $content['images'] === []) {
            $queue->setResult((string)__('缺少 images。'));
            return false;
        }
        if (\trim((string)($content['audio_path'] ?? '')) === '') {
            $queue->setResult((string)__('缺少 audio_path。'));
            return false;
        }

        return true;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $content = \json_decode((string)$queue->getContent(), true);
        $payload = \is_array($content) ? $content : [];
        $images = \array_values(\array_filter((array)($payload['images'] ?? []), 'is_string'));
        $options = \is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $result = ObjectManager::getInstance(SocialVideoComposer::class)->compose(
            $images,
            (string)($payload['audio_path'] ?? ''),
            $options
        );
        $result['job_id'] = (int)($payload['job_id'] ?? 0);

        return \json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: (string)__('社媒视频合成完成');
    }
}
