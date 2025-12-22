<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：Weline_Layout 模块扩展规约定义
 */

return [
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        'LayoutProvider' => [
            'path' => 'Extends/Weline_Layout',
            'type' => ['module'],
            'description' => '布局提供者扩展点，用于注册模块的布局类型和布局选项',
            'required' => false,
            'interface' => 'Weline\Layout\Api\LayoutProviderInterface',
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'Extends/Weline_Layout/{Provider文件名}.php',
                    'description' => 'LayoutProvider 文件位置，放在模块的 Extends/Weline_Layout 目录下',
                    'example' => 'app/code/WeShop/Product/Extends/Weline_Layout/ProductLayoutProvider.php'
                ],
                'implementation' => [
                    'interface' => 'Weline\Layout\Api\LayoutProviderInterface',
                    'description' => '必须实现 LayoutProviderInterface 接口的所有方法',
                    'required_methods' => [
                        'getModuleCode()' => '获取模块代码，如 "WeShop_Product"',
                        'getLayoutTypes()' => '获取支持的布局类型列表',
                        'getLayoutOptions(string $layoutType)' => '获取指定布局类型的可用布局选项',
                        'applyLayout(string $layoutType, string $layoutCode, mixed $entity)' => '应用布局到指定实体',
                        'getCurrentLayout(string $layoutType, mixed $entity)' => '获取当前使用的布局',
                        'getDefaultLayout(string $layoutType)' => '获取布局类型的默认布局',
                        'onLayoutSwitch(string $layoutType, string $oldLayout, string $newLayout)' => '布局切换时的回调'
                    ]
                ],
                'naming_convention' => [
                    'class_name' => '{模块名}LayoutProvider',
                    'example' => 'ProductLayoutProvider, StoreLayoutProvider',
                    'description' => '类名建议以模块名开头，以 LayoutProvider 结尾'
                ],
                'namespace' => [
                    'pattern' => '{Vendor}\{Module}\Extends\Weline_Layout',
                    'example' => 'WeShop\Product\Extends\Weline_Layout',
                    'description' => '命名空间遵循模块的 Extends/Weline_Layout 目录结构'
                ]
            ],
            'events' => [
                'Weline_Layout::layout_switch_before' => [
                    'description' => '布局切换前触发',
                    'data' => ['module_code', 'layout_type', 'old_layout', 'new_layout']
                ],
                'Weline_Layout::layout_switch_after' => [
                    'description' => '布局切换后触发',
                    'data' => ['module_code', 'layout_type', 'layout_code']
                ],
                'Weline_Layout::layout_schedule_trigger' => [
                    'description' => '布局计划触发时触发',
                    'data' => ['schedule_id', 'layout_id', 'module_code', 'layout_type', 'layout_code']
                ]
            ]
        ]
    ]
];

