<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Ai 模块扩展规约
 * 
 * 本文件定义了 Weline_Ai 模块提供的扩展点，其他模块可以通过这些扩展点来扩展 AI 功能
 */
return [
    'type' => 'module', // module 或 theme
    'documentation' => 'extends.md', // 文档文件路径（相对于模块根目录）
    'extends' => [
        'Adapter' => [
            'path' => 'extends/module/Weline_Ai/Adapter',
            'interface' => 'Weline\Ai\Interface\ScenarioAdapterInterface',
            'description' => '场景适配器扩展点，用于扩展 AI 场景适配功能',
            'required' => true, // 是否必须实现接口
            'multiple' => true  // 是否允许多个实现
        ],
        'Agent' => [
            'path' => 'extends/module/Weline_Ai/Agent',
            'interface' => 'Weline\Ai\Interface\AgentInterface',
            'description' => '智能体扩展点，用于扩展 AI 智能体功能（支持 Tool 调用编排）',
            'required' => true,
            'multiple' => true
        ],
        'Skill' => [
            'path' => 'extends/module/Weline_Ai/Skill',
            'interface' => 'Weline\Ai\Interface\SkillProviderInterface',
            'description' => 'AI skill provider extension point for governed prompt skills',
            'required' => false,
            'multiple' => true
        ],
        'Style' => [
            'path' => 'extends/module/Weline_Ai/Style',
            'interface' => 'Weline\Ai\Interface\StyleProviderInterface',
            'description' => 'AI style provider extension point for governed website style directions',
            'required' => false,
            'multiple' => true
        ]
    ]
];

