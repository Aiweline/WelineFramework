<?php

declare(strict_types=1);

/**
 * Tax frontend embeds for checkout buyer tax identity.
 */
return [
    'checkout-tax-identity' => [
        'name' => '结账税号（税收）',
        'description' => '由 Tax 嵌入结账采集买家税号（欧盟 VAT 示例）；禁止 Checkout EAV。',
        'type' => 'content',
        'code' => 'checkout-tax-identity',
        'area' => 'frontend',
        'template' => 'Weline_Tax::templates/frontend/widgets/checkout-tax-identity.phtml',
        'page_layouts' => ['checkout'],
        'position' => ['content'],
        'slot' => 'checkout-tax-identity',
        'supports' => [
            'checkout-tax-identity',
            'tax-identity',
            'buyer-tax',
            'tax',
        ],
        'default_injections' => [[
            'layout_type' => 'checkout',
            'layout_option' => 'default',
            'slot' => 'checkout-tax-identity',
            'area' => 'content',
            'sort_order' => 20,
            'required' => true,
            'reason' => '结账税号由 Tax 模块嵌入',
            'config' => [],
        ]],
        'params' => [],
    ],
];
