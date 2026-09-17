<?php

declare(strict_types=1);

/**
 * ParamSchema: header_policy_links
 * Header 关于我们 / 购物政策链接（可关闭默认项 + 可增自定义）。
 */
return [
    'base_type' => 'array',
    'item_schema' => [
        'key' => [
            'type' => 'string',
            'label' => '链接键',
            'placeholder' => '默认项稳定键；自定义可留空自动生成',
        ],
        'group' => [
            'type' => 'string',
            'label' => '分组',
            'placeholder' => 'about=顶栏直出；legal / fulfillment / policy=下拉细分',
            'default' => 'policy',
        ],
        'enabled' => [
            'type' => 'bool',
            'label' => '显示',
            'default' => true,
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
    'max_items' => 32,
    'add_label' => '添加链接',
];
