<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Seo 模块扩展规约
 * 
 * 本文件定义了 Weline_Seo 模块提供的扩展点，其他模块可以通过这些扩展点来扩展 SEO 功能
 */
return [
    'type' => 'module', // module 或 theme
    'documentation' => 'doc/扩展规约说明.md', // 文档文件路径（相对于模块根目录）
    'extends' => [
        'FeedProvider' => [
            'path' => 'extends/module/Weline_Seo/FeedProvider',
            'interface' => 'Weline\Seo\Interface\FeedProviderInterface',
            'description' => 'SEO Feed 提供扩展点，允许其他模块向 SEO 模块上报 SEO 信息',
            'required' => true, // 是否必须实现接口
            'multiple' => true  // 是否允许多个实现
        ]
        // 可以定义多个扩展点目录
    ]
];

