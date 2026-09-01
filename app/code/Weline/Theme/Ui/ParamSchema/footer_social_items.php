<?php

declare(strict_types=1);

/**
 * ParamSchema: footer_social_items
 * 页脚社交媒体入口。
 */
return [
    'base_type' => 'array',
    'item_schema' => [
        'name' => [
            'type' => 'string',
            'label' => '名称',
            'i18n' => true,
        ],
        'icon' => [
            'type' => 'string',
            'label' => '图标 class',
            'placeholder' => 'fab fa-weixin',
        ],
        'url' => [
            'type' => 'url',
            'label' => '链接地址',
        ],
    ],
    'sortable' => true,
    'max_items' => 16,
    'add_label' => '添加社交入口',
];
