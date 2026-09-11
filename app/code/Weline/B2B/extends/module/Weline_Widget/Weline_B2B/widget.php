<?php

declare(strict_types=1);

return [
    'b2b-checkout-credit' => [
        'name' => '批发信用',
        'description' => '结账摘要：批发信用抵扣定金（资产类，非营销券；批发订单说明在顶栏）。',
        'type' => 'content',
        'code' => 'b2b-checkout-credit',
        'area' => 'frontend',
        'template' => 'Weline_B2B::templates/frontend/widgets/checkout-tob-deposit-note.phtml',
        'page_layouts' => ['checkout'],
        'position' => ['summary', 'content'],
        'slot' => 'checkout-summary-credit',
        'supports' => [
            'checkout-summary-credit',
            'b2b-checkout-credit',
            'b2b-deposit-note',
            'b2b',
        ],
        'default_injections' => [[
            'layout_type' => 'checkout',
            'layout_option' => 'default',
            'slot' => 'checkout-summary-credit',
            'area' => 'content',
            'sort_order' => 15,
            'required' => true,
            'reason' => '结账摘要默认展示批发信用页签（与优惠券/订单留言同构）',
            'config' => [
                'title' => '批发信用',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '批发信用',
                'type' => 'string',
                'label' => '页签标题',
            ],
        ],
    ],
];
