<?php

declare(strict_types=1);

use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;
use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Extends\Module\Weline_SiteSetupAssistant\SetupTask\SmtpSetupTaskProvider;

return [
    MailChannelProviderInterface::class => [
    ],
    SetupTaskProviderInterface::class => [
        SmtpSetupTaskProvider::class,
    ],
];
