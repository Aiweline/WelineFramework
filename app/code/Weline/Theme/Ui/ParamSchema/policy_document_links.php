<?php

declare(strict_types=1);

/**
 * ParamSchema: policy_document_links
 * 政策页英雄区相关链接（可加减）。
 */
return [
    'base_type' => 'array',
    'item_schema' => [
        'enabled' => [
            'type' => 'bool',
            'label' => '显示',
            'default' => true,
            'i18n' => false,
        ],
        'label' => [
            'type' => 'string',
            'label' => '链接文字',
            'i18n' => true,
        ],
        'url_key' => [
            'type' => 'string',
            'label' => '路由键',
            'placeholder' => '如 privacy / terms / faq（优先于 URL）',
            'i18n' => false,
        ],
        'url' => [
            'type' => 'url',
            'label' => '链接地址',
            'description' => '无路由键时使用；可为站内 path 或外链',
            'i18n' => false,
        ],
    ],
    'sortable' => true,
    'max_items' => 12,
    'add_label' => '添加相关链接',
];
