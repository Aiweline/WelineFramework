<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'title' => [
            'type' => 'string',
            'label' => '步骤标题',
        ],
        'text' => [
            'type' => 'textarea',
            'label' => '步骤说明',
        ],
    ],
    'sortable' => true,
    'max_items' => 24,
    'add_label' => '添加项目',
];
