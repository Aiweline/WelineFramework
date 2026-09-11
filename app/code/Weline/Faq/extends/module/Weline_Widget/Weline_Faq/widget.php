<?php

declare(strict_types=1);

return [
    'product-faq' => [
        'name' => '商品 FAQ',
        'description' => '商品详情问答手风琴；默认注入商品详情 product-faq 槽。',
        'type' => 'content',
        'code' => 'product-faq',
        'area' => 'frontend',
        'template' => 'Weline_Faq::templates/frontend/widgets/product-faq.phtml',
        'page_layouts' => ['product'],
        'position' => ['content'],
        'slot' => 'product-faq',
        'supports' => [
            'layout-product-faq',
            'product-faq',
            'faq',
        ],
        'default_injections' => [[
            'layout_type' => 'product',
            'layout_option' => 'default',
            'slot' => 'product-faq',
            'area' => 'content',
            'sort_order' => 0,
            'required' => true,
            'reason' => '商品详情默认在 FAQ 槽展示万能 FAQ 部件',
            'config' => [
                'title' => '常见问题',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '常见问题',
                'type' => 'string',
                'label' => '标题',
            ],
        ],
    ],
];
