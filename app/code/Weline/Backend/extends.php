<?php

declare(strict_types=1);

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Api\NotificationTopicProviderInterface;
use Weline\Backend\Adapter\Notification\FeishuAdapter;
use Weline\Backend\Adapter\Notification\DingtalkAdapter;
use Weline\Backend\Adapter\Notification\EmailAdapter;
use Weline\Backend\Adapter\Notification\TelegramAdapter;
use Weline\Backend\Adapter\Notification\WebhookAdapter;
use Weline\Backend\Extends\NotificationTopicProvider;
use Weline\Backend\Extends\MailChannelProvider;
use Weline\Smtp\Api\MailChannelProviderInterface;

return [
    ChannelAdapterInterface::class => [
        FeishuAdapter::class,
        DingtalkAdapter::class,
        EmailAdapter::class,
        TelegramAdapter::class,
        WebhookAdapter::class,
    ],
    NotificationTopicProviderInterface::class => [
        NotificationTopicProvider::class,
    ],
    MailChannelProviderInterface::class => [
        MailChannelProvider::class,
    ],
];
