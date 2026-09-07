<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'question' => [
            'type' => 'string',
            'label' => '问题',
        ],
        'answer' => [
            'type' => 'textarea',
            'label' => '回答',
        ],
    ],
    'sortable' => true,
    'max_items' => 24,
    'add_label' => '添加项目',
];
