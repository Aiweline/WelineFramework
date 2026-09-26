<?php

declare(strict_types=1);

use Weline\Mail\Extends\Module\Weline_SiteSetupAssistant\SetupTask\MailSetupTaskProvider;
use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;

return [
    SetupTaskProviderInterface::class => [
        MailSetupTaskProvider::class,
    ],
];
