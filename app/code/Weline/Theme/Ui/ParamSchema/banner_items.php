<?php

declare(strict_types=1);

/**
 * ParamSchema: banner_items
 * 轮播横幅项目列表（含图片、标题、副标题、链接、按钮文字、文字位置）
 */
return [
    'base_type' => 'array',
    'item_schema' => [
        'image' => [
            'type' => 'media_image',
            'label' => '图片',
            'media_options' => [
                'default_directory' => 'banner',
                'recommend_width' => '1920',
                'recommend_height' => '600',
            ],
        ],
        'title' => ['type' => 'string', 'label' => '标题'],
        'subtitle' => ['type' => 'string', 'label' => '副标题'],
        'link' => ['type' => 'url', 'label' => '链接'],
        'button_text' => ['type' => 'string', 'label' => '按钮文字'],
        'text_position' => [
            'type' => 'select',
            'label' => '文字位置',
            'default' => 'left',
            'options' => [
                'left' => '左侧',
                'center' => '居中',
                'right' => '右侧',
            ],
        ],
    ],
    'sortable' => true,
    'max_items' => 10,
];
