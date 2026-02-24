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
 * 本文件定义了 Weline_Cdn 模块提供的扩展点：
 * - WarmupProvider: CDN缓存预热URL提供者
 * - Adapter: CDN适配器扩展点，用于扩展缓存清理和规则管理功能
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
        ],
        // CDN适配器扩展点
        'Adapter' => [
            'path' => 'extends/module/Weline_Cdn/Adapter',
            'type' => ['module'], // 支持模块级扩展
            'description' => 'CDN适配器扩展点，用于扩展CDN缓存清理和规则管理功能。适配器可响应缓存清理、规则更新等操作。',
            'required' => true, // 是否必须实现接口
            'interface' => 'Weline\\Cdn\\Api\\AdapterInterface', // 必须实现的接口
            'multiple' => true,  // 是否允许多个实现
            'details' => [
                'module_mode' => [
                    'path' => 'extends/module/Weline_Cdn/Adapter/{AdapterName}.php',
                    'description' => 'CDN适配器实现类，在模块的 extends/module/Weline_Cdn/Adapter/ 目录下创建PHP文件',
                    'example' => 'extends/module/Weline_Cdn/Adapter/WlsMemory.php'
                ],
                'implementation' => [
                    'interface' => 'Weline\\Cdn\\Api\\AdapterInterface',
                    'description' => '必须实现 AdapterInterface 接口',
                    'required_methods' => [
                        'getAdapterCode' => '返回适配器唯一标识',
                        'getAdapterName' => '返回适配器显示名称',
                        'getDescription' => '返回适配器描述',
                        'getVersion' => '返回适配器版本',
                        'purgeEverything' => '清理所有缓存',
                        'purgeUrls' => '按URL清理缓存',
                        'purgeHosts' => '按Host清理缓存',
                        'purgeTags' => '按Tag清理缓存',
                        'purgeCacheKeys' => '按Cache Key清理缓存',
                        'getRules' => '获取缓存规则',
                        'putRules' => '推送缓存规则',
                        'ensureZone' => '确保Zone存在',
                        'getRealIpHeaderKeys' => '返回用于解析真实IP的 $_SERVER keys，无则返回 []'
                    ]
                ],
                'use_case' => [
                    'description' => '适用于需要接入CDN模块统一管理的缓存服务，如本地内存缓存、Redis缓存等',
                    'example' => 'WLS内存缓存适配器可响应CDN模块的缓存清理请求，清理WLS Dispatcher内存中的页面缓存'
                ]
            ]
        ]
    ]
];

