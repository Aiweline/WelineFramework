<?php

declare(strict_types=1);

use Weline\CustomerService\Extends\MailChannelProvider;
use Weline\Smtp\Api\MailChannelProviderInterface;

return [
    MailChannelProviderInterface::class => [
        MailChannelProvider::class,
    ],
];
