<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/README.md',
    'extends' => [
        'CommerceCartType' => [
            'path' => 'extends/module/Weline_Cart/CommerceCartType',
            'interface' => 'Weline\\Cart\\Api\\CommerceCartTypeInterface',
            'description' => '购物车售卖类型 SPI（toc 内置；tob 等由业务模块扩展）。',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Cart/CommerceCartType/{TypeName}.php',
                ],
            ],
        ],
        'CartItemSnapshotProvider' => [
            'path' => 'extends/module/Weline_Cart/CartItemSnapshotProvider',
            'interface' => 'Weline\\Cart\\Api\\CartItemSnapshotProviderInterface',
            'description' => '按 provider code O(1) 映射；OfferIdentity + Scope + selection 快照。',
            'required' => true,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Cart/CartItemSnapshotProvider/{ProviderName}.php',
                ],
            ],
        ],
    ],
];
