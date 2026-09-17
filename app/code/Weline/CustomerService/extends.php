<?php

declare(strict_types=1);

use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;
use Weline\CustomerService\Extends\Module\Weline_SiteSetupAssistant\SetupTask\CustomerServiceSetupTaskProvider;

use Weline\CustomerService\Extends\MailChannelProvider;
use Weline\Smtp\Api\MailChannelProviderInterface;

return [
    SetupTaskProviderInterface::class => [
        CustomerServiceSetupTaskProvider::class,
    ],

    MailChannelProviderInterface::class => [
        MailChannelProvider::class,
    ],
];
