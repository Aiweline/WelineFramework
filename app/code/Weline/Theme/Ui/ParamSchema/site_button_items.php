<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'label' => [
            'type' => 'string',
            'label' => '按钮文字',
        ],
        'link' => [
            'type' => 'url',
            'label' => '链接',
        ],
        'variant' => [
            'type' => 'select',
            'label' => '按钮风格',
            'default' => 'primary',
            'options' => [
                'primary' => '主按钮',
                'outline' => '描边按钮',
                'link' => '文字链接',
            ],
        ],
    ],
    'sortable' => true,
    'max_items' => 24,
    'add_label' => '添加项目',
];
