<?php

declare(strict_types=1);

use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;
use Weline\Currency\Extends\Module\Weline_SiteSetupAssistant\SetupTask\CurrencySetupTaskProvider;

return [
    SetupTaskProviderInterface::class => [
        CurrencySetupTaskProvider::class,
    ],
];
