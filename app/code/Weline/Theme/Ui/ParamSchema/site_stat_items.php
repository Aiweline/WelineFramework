<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'value' => [
            'type' => 'string',
            'label' => '数据值',
        ],
        'title' => [
            'type' => 'string',
            'label' => '数据名称',
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
