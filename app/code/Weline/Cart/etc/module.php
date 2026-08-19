<?php

return [
    "name" => 'Weline_Cart',
    "version" => '1.2.1',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_Customer' => '*',
    ],
    "provides" => [
        \Weline\Cart\Api\CartScopeResolverInterface::class
            => \Weline\Cart\Service\CartScopeResolver::class,
        \Weline\Cart\Api\CheckoutCartSnapshotInterface::class
            => \Weline\Cart\Service\CheckoutCartSnapshotService::class,
    ],
];
