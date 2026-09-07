<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'marker' => [
            'type' => 'string',
            'label' => '标记文字',
        ],
        'title' => [
            'type' => 'string',
            'label' => '标题',
        ],
        'text' => [
            'type' => 'textarea',
            'label' => '说明',
        ],
    ],
    'sortable' => true,
    'max_items' => 24,
    'add_label' => '添加项目',
];
