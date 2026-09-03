<?php

declare(strict_types=1);

/**
 * Cart 前台部件。
 *
 * `cart-storefront-slots` 是购物车模块页槽目录（对齐 Checkout `checkout-storefront-slots`），
 * 仅供 ThemeComponentCatalog::findSlot 发现，不默认注入到布局节点。
 */
return [
    'cart-storefront-slots' => [
        'name' => '购物车页模块槽位',
        'description' => '购物车模块页模板槽目录：摘要优惠券、订单留言；供 Marketing/Order required default_injections 发现，禁止当作布局内容部件放置。',
        'type' => 'container',
        'code' => 'cart-storefront-slots',
        'area' => 'frontend',
        'template' => 'Weline_Cart::templates/frontend/widgets/cart-storefront-slots.phtml',
        'page_layouts' => ['cart'],
        'position' => ['content'],
        'is_container' => true,
        'slots' => [
            'cart-summary-discount' => [
                'name' => '购物车优惠券',
                'accepts' => [
                    'cart-summary-discount',
                    'cart-coupon',
                    'checkout-coupon',
                    'marketing',
                ],
                'max' => 1,
            ],
            'cart-summary-note' => [
                'name' => '购物车订单留言',
                'accepts' => [
                    'cart-summary-note',
                    'order-notice',
                    'order',
                ],
                'max' => 1,
            ],
        ],
        'supports' => [
            'cart-storefront-slots',
            'cart-page-slots',
        ],
        'params' => [],
    ],
    'product-add-to-cart' => [
        'name' => '加入购物车',
        'description' => '产品主要信息购买操作槽：Cart add 加购按钮。',
        'type' => 'product',
        'code' => 'product-add-to-cart',
        'area' => 'frontend',
        'template' => 'Weline_Cart::templates/frontend/widgets/product-add-to-cart.phtml',
        'page_layouts' => ['product'],
        'position' => ['content'],
        'slot' => 'product-purchase-actions',
        'supports' => [
            'layout-product-purchase-actions',
            'product-purchase-actions',
            'product-add-to-cart',
            'add-to-cart',
        ],
        'default_injections' => [[
            'layout_type' => 'product',
            'layout_option' => 'default',
            'slot' => 'product-purchase-actions',
            'area' => 'content',
            'sort_order' => 0,
            'required' => true,
            'reason' => '产品主要信息默认由 Cart 提供加购按钮',
            'config' => [],
        ]],
        'params' => [],
    ],
];
