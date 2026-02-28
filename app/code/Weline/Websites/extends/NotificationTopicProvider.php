<?php

declare(strict_types=1);

namespace Weline\Websites\Extends;

use Weline\Backend\Api\NotificationTopicProviderInterface;

class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public function getTopics(): array
    {
        return [
            [
                'code' => 'domain_expiring',
                'name' => __('域名到期提醒'),
                'group' => 'domain_management',
                'group_name' => __('域名管理'),
                'description' => __('域名即将到期的提醒通知'),
                'icon' => 'ri-time-line',
                'color' => '#f1b44c',
                'default_channels' => ['backend', 'email'],
            ],
            [
                'code' => 'domain_sync',
                'name' => __('域名同步通知'),
                'group' => 'domain_management',
                'group_name' => __('域名管理'),
                'description' => __('域名同步完成或异常的通知'),
                'icon' => 'ri-refresh-line',
                'color' => '#50a5f1',
                'default_channels' => ['backend'],
            ],
            [
                'code' => 'domain_transfer',
                'name' => __('域名转移通知'),
                'group' => 'domain_management',
                'group_name' => __('域名管理'),
                'description' => __('域名转入/转出状态变更通知'),
                'icon' => 'ri-arrow-left-right-line',
                'color' => '#34c38f',
                'default_channels' => ['backend', 'email'],
            ],
            [
                'code' => 'domain_renewal',
                'name' => __('域名续费通知'),
                'group' => 'domain_management',
                'group_name' => __('域名管理'),
                'description' => __('域名续费成功或失败的通知'),
                'icon' => 'ri-money-dollar-circle-line',
                'color' => '#34c38f',
                'default_channels' => ['backend'],
            ],
        ];
    }
}
