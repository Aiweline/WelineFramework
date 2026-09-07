<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'title' => [
            'type' => 'string',
            'label' => '方案名称',
        ],
        'price' => [
            'type' => 'string',
            'label' => '价格文字',
        ],
        'period' => [
            'type' => 'string',
            'label' => '计费说明',
        ],
        'features' => [
            'type' => 'textarea',
            'label' => '功能清单',
            'description' => '每行一项',
        ],
        'label' => [
            'type' => 'string',
            'label' => '按钮文字',
        ],
        'link' => [
            'type' => 'url',
            'label' => '按钮链接',
        ],
        'badge' => [
            'type' => 'string',
            'label' => '标签',
        ],
        'featured' => [
            'type' => 'bool',
            'label' => '重点推荐',
            'default' => false,
        ],
    ],
    'sortable' => true,
    'max_items' => 24,
    'add_label' => '添加项目',
];
