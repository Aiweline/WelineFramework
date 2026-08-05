<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Tax',
    'version' => '2.1.3',
    'requires' => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
    ],
    'optional' => [
        'Weline_Checkout' => '*',
        'Weline_Order' => '*',
    ],
    'provides' => [
        \Weline\Tax\Api\TaxEngineInterface::class
            => \Weline\Tax\Service\TaxEngine::class,
        \Weline\Tax\Api\CheckoutTaxAdvisorInterface::class
            => \Weline\Tax\Service\CheckoutTaxAdvisor::class,
    ],
];
