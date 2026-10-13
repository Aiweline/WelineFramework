<?php

declare(strict_types=1);

namespace Weline\Deploy\Extends;

use Weline\Backend\Api\NotificationTopicProviderInterface;

/**
 * Deploy release progress / webhook-miss topics for WLS notification subscriptions.
 */
class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public const TOPIC_DEPLOY_RELEASE = 'deploy_release';

    public function getTopics(): array
    {
        return [
            [
                'code' => self::TOPIC_DEPLOY_RELEASE,
                'name' => __('Deploy 发布进度'),
                'group' => 'deploy',
                'group_name' => __('部署'),
                'description' => __('Webhook 入队、发布各阶段进度、成功/失败与远程 tag 超时未见 Webhook 告警'),
                'icon' => 'cloud-upload',
                'color' => '#50a5f1',
                'default_channels' => ['backend'],
            ],
        ];
    }
}
