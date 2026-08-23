<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'image' => [
            'type' => 'image',
            'label' => '图片',
            'media_options' => [
                'default_directory' => 'advertising',
            ],
        ],
        'link' => ['type' => 'url', 'label' => '链接'],
        'alt' => [
            'type' => 'string',
            'label' => '旧数据替代文本',
            'description' => '仅用于兼容旧 URL；新图片的 alt 保存在 file-image usage 中。',
        ],
        'open_new_tab' => ['type' => 'bool', 'label' => '新窗口打开'],
    ],
    'sortable' => true,
    'max_items' => 20,
];
