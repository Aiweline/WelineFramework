<?php

declare(strict_types=1);

namespace Weline\Seo\Extends;

use Weline\Backend\Api\NotificationTopicProviderInterface;

class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public function getTopics(): array
    {
        return [
            [
                'code' => 'seo_duplicate_content',
                'name' => __('SEO 站内重复内容'),
                'group' => 'seo',
                'group_name' => __('SEO'),
                'description' => __('站内正文近重复检测报告（消息内含报告查看地址）'),
                'icon' => 'warning',
                'color' => '#f1b44c',
                'default_channels' => ['backend'],
            ],
            [
                'code' => 'seo_sitemap',
                'name' => __('SEO Sitemap'),
                'group' => 'seo',
                'group_name' => __('SEO'),
                'description' => __('Sitemap 提交与账户绑定相关提示'),
                'icon' => 'list',
                'color' => '#50a5f1',
                'default_channels' => ['backend'],
            ],
        ];
    }
}
