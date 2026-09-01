<?php

declare(strict_types=1);

return [
    'product-add-to-cart' => [
        'name' => '加入购物车',
        'description' => '产品主要信息购买操作槽：Cart V2 addV2 加购按钮。',
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
