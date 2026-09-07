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
            'label' => '替代文字',
        ],
        'text' => [
            'type' => 'string',
            'label' => '图片说明',
        ],
        'link' => [
            'type' => 'url',
            'label' => '链接',
        ],
    ],
    'sortable' => true,
    'max_items' => 24,
    'add_label' => '添加项目',
];
