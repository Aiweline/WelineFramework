<?php

declare(strict_types=1);

/**
 * Checkout 前台部件：配送地址上下文默认注入 Theme header `delivery` 槽。
 * Theme layouts/partials 禁止内嵌本模块 <w:widget>；靠 default_injections 补空槽。
 *
 * `checkout-storefront-slots` 是结账模块页槽目录（对齐 Product `product-info` 嵌套槽），
 * 仅供 ThemeComponentCatalog::findSlot 发现，不默认注入到布局节点。
 */
return [
    'checkout-storefront-slots' => [
        'name' => '结账页模块槽位',
        'description' => '结账模块页模板槽目录：快捷支付、收货地址、优惠券、订单留言、批发信用、成功页访客转化；供 Payment/Shipping/Marketing/Order/B2B/Customer 的 required default_injections 发现，禁止当作布局内容部件放置。',
        'type' => 'container',
        'code' => 'checkout-storefront-slots',
        'area' => 'frontend',
        'template' => 'Weline_Checkout::templates/frontend/widgets/checkout-storefront-slots.phtml',
        'page_layouts' => ['checkout', 'checkout_success'],
        'position' => ['content'],
        'is_container' => true,
        'slots' => [
            'checkout-express-payment' => [
                'name' => '结账快捷支付',
                'accepts' => [
                    'checkout-express-payment',
                    'express-checkout',
                    'express-payment',
                    'payment',
                ],
                'max' => 1,
            ],
            'checkout-shipping-address' => [
                'name' => '结账收货地址',
                'accepts' => [
                    'checkout-shipping-address',
                    'shipping-address',
                    'delivery-address',
                    'address',
                ],
                'max' => 1,
            ],
            'checkout-summary-discount' => [
                'name' => '结账优惠券',
                'accepts' => [
                    'checkout-summary-discount',
                    'checkout-coupon',
                    'marketing',
                ],
                'max' => 1,
            ],
            'checkout-summary-note' => [
                'name' => '结账订单留言',
                'accepts' => [
                    'checkout-summary-note',
                    'order-notice',
                    'order',
                ],
                'max' => 1,
            ],
            'checkout-summary-credit' => [
                'name' => '结账批发信用',
                'accepts' => [
                    'checkout-summary-credit',
                    'b2b-checkout-credit',
                    'b2b-deposit-note',
                    'b2b',
                ],
                'max' => 1,
            ],
            'checkout-summary-help-pay' => [
                'name' => '结账帮我付',
                'accepts' => [
                    'checkout-summary-help-pay',
                    'help-pay',
                    'helppay',
                ],
                'max' => 1,
            ],
            'checkout-success-guest-account' => [
                'name' => '结账成功访客转化',
                'accepts' => [
                    'checkout-success-guest-account',
                    'guest-account-convert',
                    'layout-checkout-success-guest-account',
                ],
                'max' => 1,
            ],
        ],
        'supports' => [
            'checkout-storefront-slots',
            'checkout-page-slots',
        ],
        'params' => [],
    ],
    'product-buy-now' => [
        'name' => '立即结账',
        'description' => '产品主要信息购买操作槽：Cart 加购后跳转结账页。',
        'type' => 'product',
        'code' => 'product-buy-now',
        'area' => 'frontend',
        'template' => 'Weline_Checkout::templates/frontend/widgets/product-buy-now.phtml',
        'page_layouts' => ['product'],
        'position' => ['content'],
        'slot' => 'product-purchase-actions',
        'supports' => [
            'layout-product-purchase-actions',
            'product-purchase-actions',
            'product-buy-now',
            'buy-now',
        ],
        'default_injections' => [[
            'layout_type' => 'product',
            'layout_option' => 'default',
            'slot' => 'product-purchase-actions',
            'area' => 'content',
            'sort_order' => 10,
            'required' => true,
            'reason' => '产品主要信息默认由 Checkout 提供立即结账按钮',
            'config' => [],
        ]],
        'params' => [],
    ],
    'product-card-buy-now' => [
        'name' => '商品卡立即购买',
        'description' => '商品卡片购买操作槽：Cart 加购后跳转结账页。',
        'type' => 'product',
        'code' => 'product-card-buy-now',
        'area' => 'frontend',
        'template' => 'Weline_Checkout::templates/frontend/widgets/product-card-buy-now.phtml',
        'page_layouts' => ['*'],
        'position' => ['content'],
        'slot' => 'product-card-purchase-actions',
        'supports' => [
            'product-card-purchase-actions',
            'product-card-buy-now',
            'buy-now',
        ],
        'params' => [],
    ],
    'checkout-delivery-context' => [
        'name' => '结账配送地址',
        'description' => '支持国家搜索与地址选择；快速新增由 Shipping Hook 提供（含验证码与级联地址）。',
        'type' => 'header',
        'code' => 'checkout-delivery-context',
        'area' => 'frontend',
        'template' => 'Weline_Checkout::theme/frontend/widgets/header/checkout-delivery-context/default.phtml',
        'page_layouts' => ['*'],
        'position' => ['header'],
        'slot' => 'delivery',
        'supports' => [
            'delivery',
            'layout-header-delivery',
            'checkout-delivery-context',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'delivery',
            'area' => 'header',
            'sort_order' => 0,
            'required' => true,
            'reason' => '前台顶栏默认展示结账配送地址上下文（非 Theme 部件，禁止布局内嵌）',
            'config' => [
                'title' => '配送至',
                'enable_country_search' => true,
                'enable_auto_detect' => true,
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '配送至',
                'type' => 'string',
                'label' => '标题',
            ],
            'enable_country_search' => [
                'default' => true,
                'type' => 'bool',
                'label' => '启用国家搜索',
            ],
            'enable_auto_detect' => [
                'default' => true,
                'type' => 'bool',
                'label' => '启用自动定位（依赖 Weline_Location）',
            ],
        ],
    ],
];
