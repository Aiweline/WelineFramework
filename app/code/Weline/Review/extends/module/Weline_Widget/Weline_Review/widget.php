<?php

declare(strict_types=1);

return [
    'product-reviews' => [
        'name' => '商品评论',
        'description' => '万能评论大部件：列表、星级表单与图文视频提交；默认注入商品详情评论容器。',
        'type' => 'comment',
        'code' => 'product-reviews',
        'area' => 'frontend',
        'template' => 'Weline_Review::templates/frontend/widgets/product-reviews.phtml',
        'page_layouts' => ['product'],
        'position' => ['content'],
        'slot' => 'product-reviews',
        'supports' => [
            'layout-product-reviews',
            'product-reviews',
            'review',
            'reviews',
        ],
        'default_injections' => [[
            'layout_type' => 'product',
            'layout_option' => 'default',
            'slot' => 'product-reviews',
            'area' => 'content',
            'sort_order' => 0,
            'required' => true,
            'reason' => '商品详情默认在评论容器槽展示万能评论大部件',
            'config' => [
                'title' => '商品评论',
                'intro' => '支持文字、图片与视频，内容审核后公开。',
                'page_size' => 10,
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '商品评论',
                'type' => 'string',
                'label' => '标题',
            ],
            'intro' => [
                'default' => '支持文字、图片与视频，内容审核后公开。',
                'type' => 'string',
                'label' => '简介',
            ],
            'page_size' => [
                'default' => 10,
                'type' => 'number',
                'label' => '列表每页条数',
            ],
        ],
    ],
];
