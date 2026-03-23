<?php

declare(strict_types=1);

namespace Weline\Bt_Center\Extends;

use Weline\Backend\Api\NotificationTopicProviderInterface;

class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public function getTopics(): array
    {
        return [
            [
                'code' => 'bt_server_health',
                'name' => __('BT 服务器健康检查'),
                'group' => 'infrastructure',
                'group_name' => __('基础设施'),
                'description' => __('BT 面板可访问性监控通知'),
                'icon' => 'ri-server-line',
                'color' => '#f46a6a',
                'default_channels' => ['backend'],
                'sort_order' => 100,
            ],
        ];
    }
}
