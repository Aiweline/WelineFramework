<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'image' => [
            'type' => 'media_image',
            'label' => '人物照片',
        ],
        'title' => [
            'type' => 'string',
            'label' => '姓名',
        ],
        'role' => [
            'type' => 'string',
            'label' => '职务',
        ],
        'text' => [
            'type' => 'textarea',
            'label' => '简介',
        ],
        'link' => [
            'type' => 'url',
            'label' => '详情链接',
        ],
    ],
    'sortable' => true,
    'max_items' => 24,
    'add_label' => '添加项目',
];
