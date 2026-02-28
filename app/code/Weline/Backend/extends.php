<?php

declare(strict_types=1);

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Api\NotificationTopicProviderInterface;
use Weline\Backend\Adapter\Notification\FeishuAdapter;
use Weline\Backend\Adapter\Notification\DingtalkAdapter;
use Weline\Backend\Adapter\Notification\EmailAdapter;
use Weline\Backend\Adapter\Notification\WebhookAdapter;
use Weline\Backend\Extends\NotificationTopicProvider;

return [
    ChannelAdapterInterface::class => [
        FeishuAdapter::class,
        DingtalkAdapter::class,
        EmailAdapter::class,
        WebhookAdapter::class,
    ],
    NotificationTopicProviderInterface::class => [
        NotificationTopicProvider::class,
    ],
];
