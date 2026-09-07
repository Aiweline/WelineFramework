<?php

return [
    "name" => 'Weline_Cart',
    "version" => '1.3.22',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_Customer' => '*',
        'Weline_Widget' => '*',
        'Weline_Marketing' => '*',
        'Weline_Order' => '*',
    ],
    "provides" => [
        \Weline\Cart\Api\CartScopeResolverInterface::class
            => \Weline\Cart\Service\CartScopeResolver::class,
        \Weline\Cart\Api\CheckoutCartSnapshotInterface::class
            => \Weline\Cart\Service\CheckoutCartSnapshotService::class,
    ],
];
