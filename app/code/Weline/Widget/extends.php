<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Widget 模块扩展规约
 * 
 * 本文件定义了 Weline_Widget 模块提供的扩展点，其他模块可以通过这些扩展点来定义可复用的页面部件
 */
return [
    'type' => 'module', // module 或 theme
    'documentation' => 'extends.md', // 文档文件路径（相对于模块根目录）
    'extends' => [
        'Widget' => [
            'path' => 'extends/Weline_Widget/Weline_Widget/{type}/{name}',
            'type' => ['module', 'theme'], // 支持的扩展类型
            'description' => 'Widget 部件扩展点，用于定义可复用的页面部件。支持模块级和主题级两种扩展模式。',
            'required' => false, // 是否必须实现接口（Widget 不需要实现接口）
            'multiple' => true,  // 是否允许多个实现
            'details' => [
                'file_structure' => [
                    'widget.php' => '部件规约文件（必需），定义部件的基本信息、参数配置和模板路径',
                    'template.phtml' => '部件模板文件（必需，如果未提供 block_class）',
                    'Block.php' => 'Block 类（可选），用于处理复杂的部件逻辑',
                    'doc.md' => '部件文档（可选），说明部件的使用方法'
                ],
                'widget_types' => [
                    'header', 'footer', 'sidebar', 'content', 'banner',
                    'carousel', 'card', 'form', 'list', 'grid', 'navigation',
                    'breadcrumb', 'pagination', 'modal', 'tabs', 'accordion',
                    'slider', 'gallery', 'testimonial', 'pricing', 'team',
                    'blog', 'product', 'category', 'search', 'filter', 'map',
                    'video', 'audio', 'social', 'newsletter', 'faq', 'timeline',
                    'stats', 'counter', 'progress', 'chart', 'table', 'calendar',
                    'chat', 'comment'
                ],
                'path_example' => [
                    'module_mode' => 'app/code/YourModule/extends/Weline_Widget/Weline_Widget/header/default/',
                    'theme_mode' => 'app/design/frontend/YourTheme/extends/Weline_Widget/Weline_Widget/header/default/'
                ],
                'usage' => [
                    'step1' => '在模块的 extends/Weline_Widget/Weline_Widget/{type}/{name}/ 目录下创建部件文件',
                    'step2' => '创建 widget.php 规约文件，定义部件信息',
                    'step3' => '创建 template.phtml 模板文件或 Block.php 类',
                    'step4' => '在可视化编辑器中使用部件，或通过 w:widget 标签调用'
                ]
            ]
        ]
    ]
];

