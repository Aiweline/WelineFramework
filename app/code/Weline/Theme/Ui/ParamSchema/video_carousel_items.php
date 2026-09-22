<?php

declare(strict_types=1);

/**
 * ParamSchema: video_carousel_items
 * 视频轮播项列表（平台、地址/嵌入、作者简介、封面、关联商品）
 */
return [
    'base_type' => 'array',
    'item_schema' => [
        'video_type' => [
            'type' => 'select',
            'label' => '视频类型',
            'default' => 'youtube',
            'options' => [
                'youtube' => 'YouTube',
                'vimeo' => 'Vimeo',
                'bilibili' => '哔哩哔哩',
                'self' => '自托管',
                'embed' => '嵌入代码',
            ],
            'i18n' => false,
        ],
        'video_url' => [
            'type' => 'url',
            'label' => '视频地址',
            'placeholder' => 'https://www.youtube.com/watch?v=…',
            'description' => 'YouTube / Vimeo / 哔哩哔哩页面链接，或自托管 mp4 地址',
            'i18n' => false,
        ],
        'embed_code' => [
            'type' => 'textarea',
            'label' => '嵌入代码',
            'placeholder' => '<iframe src="https://www.youtube.com/embed/…"></iframe>',
            'description' => '仅「嵌入代码」类型必填；误贴平台链接也会尝试识别',
            'i18n' => false,
        ],
        'title' => [
            'type' => 'string',
            'label' => '单项标题',
        ],
        'author' => [
            'type' => 'string',
            'label' => '作者',
        ],
        'description' => [
            'type' => 'textarea',
            'label' => '简介',
        ],
        'poster' => [
            'type' => 'media_image',
            'label' => '封面图片',
            'description' => '自托管等场景的封面图',
            'media_options' => [
                'default_directory' => 'video',
                'aspect_ratio' => '16:9',
            ],
            'i18n' => false,
        ],
        'product_ids' => [
            'type' => 'product_picker',
            'label' => '关联商品',
            'description' => '可多选；前台「查看关联商品」对话框展示',
            'i18n' => false,
        ],
    ],
    'sortable' => true,
    'max_items' => 12,
    'add_label' => '添加视频',
];
