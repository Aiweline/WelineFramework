<?php

declare(strict_types=1);

use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;
use Weline\Shipping\Extends\Module\Weline_SiteSetupAssistant\SetupTask\ShippingSetupTaskProvider;

return [
    SetupTaskProviderInterface::class => [
        ShippingSetupTaskProvider::class,
    ],
];
