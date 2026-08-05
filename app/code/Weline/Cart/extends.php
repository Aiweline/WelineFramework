<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/README.md',
    'extends' => [
        'CartItemSnapshotProvider' => [
            'path' => 'extends/module/Weline_Cart/CartItemSnapshotProvider',
            'interface' => 'Weline\Cart\Api\CartItemSnapshotProviderInterface',
            'description' => '业务模块向核心购物车提供商品名称、SKU、图片、价格、库存与可售状态快照。',
            'required' => true,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Cart/CartItemSnapshotProvider/{ProviderName}.php',
                ],
            ],
        ],
        'CartItemSnapshotProviderV2' => [
            'path' => 'extends/module/Weline_Cart/CartItemSnapshotProviderV2',
            'interface' => 'Weline\Cart\Api\CartItemSnapshotProviderV2Interface',
            'description' => 'Cart V2：按 provider code O(1) 映射；OfferIdentity + Scope + selection 快照。',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Cart/CartItemSnapshotProviderV2/{ProviderName}.php',
                ],
            ],
        ],
    ],
];
