<?php

declare(strict_types=1);

use Weline\Backend\Api\NotificationTopicProviderInterface;
use Weline\Bt_Center\Extends\NotificationTopicProvider;

return [
    NotificationTopicProviderInterface::class => [
        NotificationTopicProvider::class,
    ],
];
