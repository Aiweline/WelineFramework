<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/README.md',
    'extends' => [
        'CheckoutType' => [
            'path' => 'extends/module/Weline_Checkout/CheckoutType',
            'interface' => 'Weline\\Checkout\\Api\\CheckoutTypeProviderInterface',
            'description' => '结账 Type SPI（standard 内置；tob 等由业务模块扩展）。禁止注册 continue_pay。',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Checkout/CheckoutType/{TypeName}.php',
                ],
            ],
        ],
    ],
];
