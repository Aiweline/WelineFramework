<?php

declare(strict_types=1);

/**
 * ParamSchema: footer_link_items
 * 页脚链接分项：归属分组 + 文案 + URL + 可选新窗口。
 */
return [
    'base_type' => 'array',
    'item_schema' => [
        'group_key' => [
            'type' => 'string',
            'label' => '所属分组键',
            'placeholder' => '与分组的 key 一致',
        ],
        'label' => [
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
    'max_items' => 64,
    'add_label' => '添加链接',
];
