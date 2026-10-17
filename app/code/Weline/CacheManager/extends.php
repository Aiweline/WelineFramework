<?php

declare(strict_types=1);

use Weline\CacheManager\Extends\Module\Weline_SiteSetupAssistant\SetupTask\CacheManagerSetupTaskProvider;
use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;

return [
    SetupTaskProviderInterface::class => [
        CacheManagerSetupTaskProvider::class,
    ],
];
