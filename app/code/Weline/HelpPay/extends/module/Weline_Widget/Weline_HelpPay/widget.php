<?php

declare(strict_types=1);

/**
 * HelpPay widgets: inject into Cart/Checkout help-pay slots; never hardcode in host templates.
 */
return [
    'cart-summary-help-pay' => [
        'name' => '购物车帮我付',
        'description' => '购物车摘要旁帮我付 CTA；placement.cart 可关；空车隐藏。',
        'type' => 'content',
        'code' => 'cart-summary-help-pay',
        'area' => 'frontend',
        'template' => 'Weline_HelpPay::templates/frontend/widgets/cart-summary-help-pay.phtml',
        'page_layouts' => ['cart'],
        'position' => ['content'],
        'slot' => 'cart-summary-help-pay',
        'supports' => [
            'cart-summary-help-pay',
            'help-pay',
            'helppay',
        ],
        'default_injections' => [[
            'layout_type' => 'cart',
            'layout_option' => 'default',
            'slot' => 'cart-summary-help-pay',
            'area' => 'content',
            'sort_order' => 20,
            'required' => true,
            'reason' => '购物车摘要默认由 HelpPay 注入帮我付（可关）',
            'config' => [
                'enabled' => true,
                'placement' => 'cart',
            ],
        ]],
        'params' => [
            'enabled' => [
                'default' => true,
                'type' => 'bool',
                'label' => '展示购物车帮我付',
            ],
        ],
    ],
    'checkout-summary-help-pay' => [
        'name' => '结账帮我付',
        'description' => '结账摘要旁帮我付 CTA；placement.checkout 可关。',
        'type' => 'content',
        'code' => 'checkout-summary-help-pay',
        'area' => 'frontend',
        'template' => 'Weline_HelpPay::templates/frontend/widgets/checkout-summary-help-pay.phtml',
        'page_layouts' => ['checkout'],
        'position' => ['content'],
        'slot' => 'checkout-summary-help-pay',
        'supports' => [
            'checkout-summary-help-pay',
            'help-pay',
            'helppay',
        ],
        'default_injections' => [[
            'layout_type' => 'checkout',
            'layout_option' => 'default',
            'slot' => 'checkout-summary-help-pay',
            'area' => 'content',
            'sort_order' => 20,
            'required' => true,
            'reason' => '结账摘要默认由 HelpPay 注入帮我付（可关）',
            'config' => [
                'enabled' => true,
                'placement' => 'checkout',
            ],
        ]],
        'params' => [
            'enabled' => [
                'default' => true,
                'type' => 'bool',
                'label' => '展结账帮我付',
            ],
        ],
    ],
    'product-selection-share' => [
        'name' => '商品纯分享',
        'description' => 'PDP 纯分享（selection_share）：预选规格分享给朋友灌入对方购物车。',
        'type' => 'product',
        'code' => 'product-selection-share',
        'area' => 'frontend',
        'template' => 'Weline_HelpPay::templates/frontend/widgets/product-selection-share.phtml',
        'page_layouts' => ['product'],
        'position' => ['content'],
        'slot' => 'product-purchase-actions',
        'supports' => [
            'product-purchase-actions',
            'selection-share',
            'helppay',
        ],
        'default_injections' => [[
            'layout_type' => 'product',
            'layout_option' => 'default',
            'slot' => 'product-purchase-actions',
            'area' => 'content',
            'sort_order' => 40,
            'required' => true,
            'reason' => '商品页默认提供纯分享入口（可关）',
            'config' => [
                'enabled' => true,
            ],
        ]],
        'params' => [
            'enabled' => [
                'default' => true,
                'type' => 'bool',
                'label' => '展示纯分享',
            ],
        ],
    ],
    'product-quick-pay' => [
        'name' => '商品快捷购买',
        'description' => 'PDP 本人快捷买：地址齐全后生成 /q/ 短链与二维码。',
        'type' => 'product',
        'code' => 'product-quick-pay',
        'area' => 'frontend',
        'template' => 'Weline_HelpPay::templates/frontend/widgets/product-quick-pay.phtml',
        'page_layouts' => ['product'],
        'position' => ['content'],
        'slot' => 'product-purchase-actions',
        'supports' => [
            'product-purchase-actions',
            'quick-pay',
            'helppay',
        ],
        'default_injections' => [[
            'layout_type' => 'product',
            'layout_option' => 'default',
            'slot' => 'product-purchase-actions',
            'area' => 'content',
            'sort_order' => 45,
            'required' => true,
            'reason' => '商品页默认提供快捷购买入口（可关）',
            'config' => [
                'enabled' => true,
            ],
        ]],
        'params' => [
            'enabled' => [
                'default' => true,
                'type' => 'bool',
                'label' => '展示快捷购买',
            ],
        ],
    ],
    'help-pay-share-result' => [
        'name' => '出链结果（链接+二维码）',
        'description' => '统一出链双形态：复制链接、复制二维码图、页内预览。',
        'type' => 'content',
        'code' => 'help-pay-share-result',
        'area' => 'frontend',
        'template' => 'Weline_HelpPay::templates/frontend/widgets/help-pay-share-result.phtml',
        'page_layouts' => ['*'],
        'position' => ['content'],
        'supports' => [
            'help-pay-share-result',
            'share-result',
            'helppay',
        ],
        'params' => [
            'url' => [
                'default' => '',
                'type' => 'string',
                'label' => '分享 URL',
            ],
        ],
    ],
];
