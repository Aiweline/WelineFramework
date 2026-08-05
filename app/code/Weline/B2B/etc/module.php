<?php

declare(strict_types=1);

return [
    'name' => 'Weline_B2B',
    'version' => '2.3.0',
    'requires' => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
    ],
    'optional' => [
        'Weline_Customer' => '*',
        'Weline_Product' => '*',
        'Weline_Cart' => '*',
        'Weline_Checkout' => '*',
        'Weline_Order' => '*',
    ],
    'provides' => [
        \Weline\B2B\Api\B2BPriceCandidateInterface::class
            => \Weline\B2B\Service\B2BPriceEngine::class,
        \Weline\B2B\Api\B2BCheckoutRecheckInterface::class
            => \Weline\B2B\Service\B2BCheckoutRecheckService::class,
    ],
];
