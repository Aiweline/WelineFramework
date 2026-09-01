<?php

declare(strict_types=1);

/**
 * ParamSchema: footer_legal_links
 * 页脚法律/政策链接。
 */
return [
    'base_type' => 'array',
    'item_schema' => [
        'text' => [
            'type' => 'string',
            'label' => '链接文字',
            'i18n' => true,
        ],
        'url' => [
            'type' => 'url',
            'label' => '链接地址',
        ],
        'open_in_new' => [
            'type' => 'bool',
            'label' => '新窗口打开',
            'default' => false,
        ],
    ],
    'sortable' => true,
    'max_items' => 20,
    'add_label' => '添加法律链接',
];
