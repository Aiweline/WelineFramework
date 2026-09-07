<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'image' => [
            'type' => 'media_image',
            'label' => '图片',
        ],
        'title' => [
            'type' => 'string',
            'label' => '标题',
        ],
        'text' => [
            'type' => 'textarea',
            'label' => '说明',
        ],
        'link' => [
            'type' => 'url',
            'label' => '链接',
        ],
        'link_label' => [
            'type' => 'string',
            'label' => '链接文字',
        ],
    ],
    'sortable' => true,
    'max_items' => 24,
    'add_label' => '添加项目',
];
