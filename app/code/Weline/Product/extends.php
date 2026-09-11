<?php

declare(strict_types=1);

use Weline\Product\Extends\MailChannelProvider;
use Weline\Smtp\Api\MailChannelProviderInterface;

return [
    MailChannelProviderInterface::class => [
        MailChannelProvider::class,
    ],
    'type' => 'module',
    'documentation' => 'doc/provider-guide.md',
    'extends' => [
        'CatalogSpace' => [
            'path' => 'extends/module/Weline_Catalog/Space',
            'interface' => \Weline\Catalog\Api\CatalogSpaceProviderInterface::class,
            'description' => 'Product catalog space provider for Weline_Catalog hub.',
            'required' => false,
            'multiple' => false,
        ],
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
        'StorefrontPriceAdjustmentProvider' => [
            'path' => 'extends/module/Weline_Product/StorefrontPriceAdjustmentProvider',
            'type' => ['module'],
            'description' => '前台成交价调整 SPI：Promotion/Marketing/会员价等注册 unit-layer 优惠；Product Assembler 合并为 StorefrontOfferPriceView',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Product/StorefrontPriceAdjustmentProvider/{Name}Provider.php',
                    'description' => '实现 StorefrontPriceAdjustmentProviderInterface；禁止模板内私算折扣',
                    'example' => 'app/code/Weline/Promotion/extends/module/Weline_Product/StorefrontPriceAdjustmentProvider/PromotionThemeDealPriceAdjustmentProvider.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface',
                    'required_methods' => [
                        'getCode' => '唯一 Provider code',
                        'getPriority' => '收集优先级（高者先）',
                        'collectAdjustments' => '按 StorefrontPriceContext 返回调整列表（含活动名/URL）',
                    ],
                ],
            ],
        ],
        'StorefrontShippingProfileCatalogProvider' => [
            'path' => 'extends/module/Weline_Product/StorefrontShippingProfileCatalogProvider',
            'type' => ['module'],
            'description' => '配送方案目录 SPI：Shipping 等模块提供可绑定 service_code；Product 仅存 opaque code',
            'required' => false,
            'multiple' => false,
            'interface' => 'Weline\Product\Api\Storefront\StorefrontShippingProfileCatalogProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Product/StorefrontShippingProfileCatalogProvider/{Name}Provider.php',
                    'description' => '实现 StorefrontShippingProfileCatalogProviderInterface',
                    'example' => 'app/code/Weline/Shipping/extends/module/Weline_Product/StorefrontShippingProfileCatalogProvider/ShippingServiceProfileCatalogProvider.php',
                ],
            ],
        ],
    ],
];
