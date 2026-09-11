<?php

declare(strict_types=1);

use Weline\SiteSetupAssistant\Api\SetupTaskStatusProviderInterface;
use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Extends\Module\Weline_SiteSetupAssistant\SetupTaskStatus\SmtpSetupTaskStatusProvider;

return [
    MailChannelProviderInterface::class => [
    ],
    SetupTaskStatusProviderInterface::class => [
        SmtpSetupTaskStatusProvider::class,
    ],
];
