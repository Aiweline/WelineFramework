<?php

declare(strict_types=1);

/**
 * ParamSchema: policy_document_sections
 * 政策页可排序条款（目录由 title 自动生成）。
 */
return [
    'base_type' => 'array',
    'item_schema' => [
        'id' => [
            'type' => 'string',
            'label' => '锚点 ID',
            'placeholder' => '留空则自动生成 section-n',
            'i18n' => false,
        ],
        'title' => [
            'type' => 'string',
            'label' => '条款标题（目录）',
            'i18n' => true,
        ],
        'body' => [
            'type' => 'html',
            'label' => '条款正文',
            'description' => '支持段落与列表 HTML；可视化可加减整条条款',
            'i18n' => true,
        ],
    ],
    'sortable' => true,
    'max_items' => 40,
    'add_label' => '添加条款',
];
