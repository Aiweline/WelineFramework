<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Meta 模块扩展规约
 * 
 * 本文件定义了 Weline_Meta 模块提供的扩展点，其他模块可以通过这些扩展点来定义元数据结构
 */
return [
    'type' => 'module', // module 或 theme
    'documentation' => 'doc/@meta.json规约文件说明.md', // 文档文件路径（相对于模块根目录）
    'extends' => [
        'MetaConvention' => [
            'path' => 'extends/Weline_Meta/{ModuleName}/@meta.json',
            'type' => ['module'], // 支持的扩展类型
            'description' => 'Meta 元数据规约文件扩展点，用于定义元数据的层级结构、默认值和选项列表。其他模块可以通过创建 @meta.json 文件来定义自己的元数据结构。',
            'required' => false, // 是否必须实现（Meta 规约文件是可选的）
            'multiple' => true,  // 是否允许多个实现（每个模块可以有多个命名空间）
            'details' => [
                'file_location' => [
                    'path' => 'extends/Weline_Meta/{ModuleName}/@meta.json',
                    'description' => 'Meta 规约文件位置，{ModuleName} 为扩展模块的名称',
                    'example' => 'app/code/Weline/Theme/extends/Weline_Meta/Weline_Theme/@meta.json',
                ],
                'file_format' => [
                    'format' => 'JSON',
                    'description' => '规约文件使用 JSON 格式，定义元数据的层级结构',
                    'required_fields' => [
                        'meta.base_path' => '基础扫描路径，格式：ModuleName::相对路径',
                        'meta.{namespace}' => '命名空间定义，包含默认值、名称、描述等',
                    ],
                ],
                'scanning' => [
                    'command' => 'php bin/w meta:collect',
                    'description' => '使用 meta:collect 命令扫描并存储元数据到数据库',
                    'auto_scan' => '系统升级后（setup:upgrade）会自动执行扫描',
                ],
                'usage' => [
                    'step1' => '在模块的 extends/Weline_Meta/{ModuleName}/ 目录下创建 @meta.json 文件',
                    'step2' => '定义 base_path 和命名空间结构',
                    'step3' => '运行 php bin/w meta:collect 扫描元数据',
                    'step4' => '在模板文件中使用 @meta:: 标记定义元数据',
                ],
            ],
        ],
    ],
];

