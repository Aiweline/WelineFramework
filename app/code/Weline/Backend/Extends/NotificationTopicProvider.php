<?php

declare(strict_types=1);

namespace Weline\Backend\Extends;

use Weline\Backend\Api\NotificationTopicProviderInterface;

class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public function getTopics(): array
    {
        return [
            [
                'code' => 'system_info',
                'name' => __('系统信息'),
                'group' => 'system',
                'group_name' => __('系统'),
                'description' => __('一般系统信息通知'),
                'icon' => 'ri-information-line',
                'color' => '#50a5f1',
                'default_channels' => ['backend'],
            ],
            [
                'code' => 'system_warning',
                'name' => __('系统警告'),
                'group' => 'system',
                'group_name' => __('系统'),
                'description' => __('系统警告通知'),
                'icon' => 'ri-alert-line',
                'color' => '#f1b44c',
                'default_channels' => ['backend'],
            ],
            [
                'code' => 'system_alert',
                'name' => __('系统告警'),
                'group' => 'system',
                'group_name' => __('系统'),
                'description' => __('系统错误告警'),
                'icon' => 'ri-error-warning-line',
                'color' => '#f46a6a',
                'default_channels' => ['backend', 'email'],
            ],
            [
                'code' => 'security_alert',
                'name' => __('安全告警'),
                'group' => 'security',
                'group_name' => __('安全'),
                'description' => __('安全相关告警通知'),
                'icon' => 'ri-shield-keyhole-line',
                'color' => '#c92a2a',
                'default_channels' => ['backend', 'email'],
            ],
            [
                'code' => 'user_activity',
                'name' => __('用户活动'),
                'group' => 'user',
                'group_name' => __('用户'),
                'description' => __('用户登录、操作等活动通知'),
                'icon' => 'ri-user-line',
                'color' => '#50a5f1',
                'default_channels' => ['backend'],
            ],
        ];
    }
}
