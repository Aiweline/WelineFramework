<?php

declare(strict_types=1);

/**
 * 默认头部部件规约文件
 */
return [
    'name' => '默认头部',
    'description' => '标准网站头部部件，包含 Logo 和导航菜单',
    'type' => 'header',
    'version' => '1.0.0',
    'author' => 'Weline Team',
    'template' => 'Weline_Widget::widgets/header/default.phtml',
    'params' => [
        'title' => [
            'type' => 'string',
            'label' => '标题',
            'default' => '网站标题',
            'required' => true,
            'description' => '网站主标题'
        ],
        'logo' => [
            'type' => 'image',
            'label' => 'Logo',
            'default' => '',
            'required' => false,
            'description' => '网站 Logo 图片 URL'
        ],
        'show_search' => [
            'type' => 'bool',
            'label' => '显示搜索',
            'default' => true,
            'required' => false
        ],
        'nav_items' => [
            'type' => 'array',
            'label' => '导航项',
            'default' => [],
            'required' => false,
            'description' => '导航菜单项数组，格式：[{"label":"首页","url":"/"}]'
        ]
    ]
];

