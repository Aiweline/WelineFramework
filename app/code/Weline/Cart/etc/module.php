<?php

return [
    "name" => 'Weline_Cart',
    "version" => '1.3.122',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_Customer' => '*',
        'Weline_Widget' => '*',
        'Weline_Marketing' => '*',
        'Weline_Order' => '*',
        'Weline_Product' => '*',
    ],
    "provides" => [
        \Weline\Cart\Api\CartScopeResolverInterface::class
            => \Weline\Cart\Service\CartScopeResolver::class,
        \Weline\Cart\Api\CheckoutCartSnapshotInterface::class
            => \Weline\Cart\Service\CheckoutCartSnapshotService::class,
        \Weline\Cart\Api\CartStoreInterface::class
            => \Weline\Cart\Service\CartDbStore::class,
        // ProductTypeRequiresShippingResolverInterface：权威实现在 Weline_Product
        // Integration（Product/etc/module.php provides）。此处禁止再 provide Legacy，
        // 否则 ObjectManager 双实现冲突，整机 CLI/EventRegistry/店面 500。
    ],
];
