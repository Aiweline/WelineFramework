<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/provider-guide.md',
    'extends' => [
        'ProductProvider' => [
            'path' => 'extends/module/Weline_Product/ProductProvider',
            'type' => ['module'],
            'description' => 'Product type Provider SPI：小接口 capability（pricing/inventory/renderer metadata）；code 与 type 唯一，重复硬失败',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Product\Api\ProductProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Product/ProductProvider/{Name}Provider.php',
                    'description' => '实现 ProductProviderInterface；勿暴露 Product 内部 Service/Model',
                    'example' => 'app/code/Vendor/Module/extends/module/Weline_Product/ProductProvider/SubscriptionProvider.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Product\Api\ProductProviderInterface',
                    'required_methods' => [
                        'getCode' => '唯一 Provider code',
                        'getType' => '唯一 product type',
                        'getRequiredAttributes' => '发布/可售必填属性（非空）',
                        'getCapabilityMap' => 'capability 发现',
                        'getMetadata' => '注册元数据（禁止触发 Renderer）',
                    ],
                ],
                'capabilities' => [
                    'pricing' => 'Weline\Product\Api\Capability\ProductPricingCapabilityInterface',
                    'inventory' => 'Weline\Product\Api\Capability\ProductInventoryCapabilityInterface',
                    'renderer' => 'Weline\Product\Api\Capability\ProductRendererCapabilityInterface（P2A 仅 metadata）',
                ],
            ],
        ],
    ],
];
