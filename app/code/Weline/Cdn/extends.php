<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Cdn 模块扩展规约
 * 
 * 本文件定义了 Weline_Cdn 模块提供的扩展点，其他模块可以通过这些扩展点来提供CDN缓存预热URL
 */
return [
    'type' => 'module', // module 或 theme
    'documentation' => 'extends.md', // 文档文件路径（相对于模块根目录）
    'extends' => [
        'WarmupProvider' => [
            'path' => 'extends/module/Weline_Cdn',
            'type' => ['module'], // 支持的扩展类型（WarmupProvider只支持模块级）
            'description' => 'CDN缓存预热URL提供者扩展点，用于收集需要预热的URL列表。支持模块级扩展。',
            'required' => true, // 是否必须实现接口
            'interface' => 'Weline\\Cdn\\Api\\WarmupProviderInterface', // 必须实现的接口
            'multiple' => true,  // 是否允许多个实现
            'details' => [
                'module_mode' => [
                    'path' => 'extends/module/Weline_Cdn/{Provider文件名}.php',
                    'description' => '模块级 WarmupProvider，在模块的 extends/module/Weline_Cdn/ 目录下创建PHP文件',
                    'example' => 'extends/module/Weline_Cdn/ProductUrls.php'
                ],
                'implementation' => [
                    'interface' => 'Weline\\Cdn\\Api\\WarmupProviderInterface',
                    'description' => '必须实现 WarmupProviderInterface 接口，包含 execute() 静态方法',
                    'method' => 'public static function execute(): array',
                    'return_format' => [
                        'simple' => "['https://example.com/page1', 'https://example.com/page2']",
                        'detailed' => "[['url' => 'https://example.com/page3', 'site_id' => 1]]"
                    ]
                ]
            ]
        ]
        // 可以定义多个扩展点目录
    ]
];

