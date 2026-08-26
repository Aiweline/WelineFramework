<?php

declare(strict_types=1);

/**
 * ParamSchema: all_menu_tree
 * 全部菜单嵌套导航树（页面/分类/自定义交叉，depth≤3）
 */
return [
    'base_type' => 'nav_tree',
    'i18n' => false,
    'max_depth' => 3,
    'item_schema' => [
        'name' => ['type' => 'string', 'label' => '名称', 'i18n' => true, 'translatable' => true],
        'url' => ['type' => 'url', 'label' => '链接'],
        'tag' => [
            'type' => 'select',
            'label' => '标签',
            'default' => 'custom',
            'options' => [
                'page' => '页面',
                'category' => '分类',
                'custom' => '自定义',
            ],
        ],
        'description' => ['type' => 'textarea', 'label' => '描述', 'i18n' => true, 'translatable' => true],
        'image' => [
            'type' => 'media_image',
            'label' => '图片',
            'media_options' => [
                'default_directory' => 'nav',
            ],
        ],
        'ref' => ['type' => 'string', 'label' => '引用'],
    ],
    'sortable' => true,
];
