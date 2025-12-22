<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Marketing 模块扩展规约
 * 
 * 本文件定义了 Weline_Marketing 模块提供的扩展点，其他模块可以通过这些扩展点来扩展营销功能
 */
return [
    'type' => 'module', // module 或 theme
    'documentation' => 'doc/扩展开发文档.md', // 文档文件路径（相对于模块根目录）
    'extends' => [
        'Condition' => [
            'path' => 'extends/module/Weline_Marketing/Condition',
            'interface' => 'Weline\Marketing\Interface\Rule\ConditionInterface',
            'description' => '条件扩展点，用于扩展营销规则的条件判断功能',
            'required' => true, // 是否必须实现接口
            'multiple' => true  // 是否允许多个实现
        ],
        'Action' => [
            'path' => 'extends/module/Weline_Marketing/Action',
            'interface' => 'Weline\Marketing\Interface\Rule\ActionInterface',
            'description' => '动作扩展点，用于扩展营销规则的动作执行功能',
            'required' => true, // 是否必须实现接口
            'multiple' => true  // 是否允许多个实现
        ]
    ]
];

