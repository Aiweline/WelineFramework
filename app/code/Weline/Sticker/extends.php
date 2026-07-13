<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Sticker 模块扩展规约
 * 
 * 本文件定义了 Weline_Sticker 模块提供的扩展点，其他模块可以通过这些扩展点来非侵入式地修改模板和代码
 */
return [
    'type' => 'module', // module 或 theme
    'documentation' => 'extends.md', // 文档文件路径（相对于模块根目录）
    'scanner' => [
        'target_shape' => 'nested_vendor_module',
        'metadata' => ['is_sticker_extension' => true],
        'type_field' => 'sticker_type',
    ],
    'extends' => [
        'Sticker' => [
            'path' => 'extends/module/Weline_Sticker',
            'type' => ['module', 'theme'], // 支持的扩展类型
            'description' => 'Sticker 扩展点，用于非侵入式修改其他模块的模板文件。支持模块级和主题级两种扩展模式。',
            'required' => false, // 是否必须实现接口（Sticker 不需要实现接口）
            'multiple' => true,  // 是否允许多个实现
            'details' => [
                'module_mode' => [
                    'path' => 'extends/module/Weline_Sticker/{目标模块名}/{文件路径}',
                    'description' => '模块级 Sticker，直接修改指定模块的指定文件',
                    'example' => 'extends/module/Weline_Sticker/Weline_Demo/view/templates/Backend/index.phtml'
                ],
                'theme_mode' => [
                    'path' => 'extends/theme/{主题名}/Weline_Sticker/{目标模块名}/{文件路径}',
                    'description' => '主题级 Sticker，基于主题覆盖其他模块的文件',
                    'example' => 'extends/theme/default/Weline_Sticker/Weline_Demo/view/templates/Backend/index.phtml'
                ],
                'rule_syntax' => [
                    'tags' => '<w:sticker action="replace|before|after" position="all|1|2-3">',
                    'target' => '<w:sticker:target>目标代码</w:sticker:target>',
                    'code' => '<w:sticker:code>修改代码</w:sticker:code>',
                    'description' => '使用 w:sticker 标签定义修改规则，支持替换、前插、后插操作'
                ]
            ]
        ]
        // 可以定义多个扩展点目录
    ]
];
