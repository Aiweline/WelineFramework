<?php

declare(strict_types=1);

/**
 * ParamSchema: footer_link_groups
 * 页脚链接分组（key / 是否渲染 / 标题）；标准 key 带固定扩展槽。
 */
return [
    'base_type' => 'array',
    'item_schema' => [
        'key' => [
            'type' => 'string',
            'label' => '分组键',
            'placeholder' => 'about / partner / payment / help 或自定义',
        ],
        'enabled' => [
            'type' => 'bool',
            'label' => '渲染此分组',
            'default' => true,
        ],
        'title' => [
            'type' => 'string',
            'label' => '分组标题',
            'i18n' => true,
        ],
    ],
    'sortable' => true,
    'max_items' => 12,
    'add_label' => '添加分组',
];
