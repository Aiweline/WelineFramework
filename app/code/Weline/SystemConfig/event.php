<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_SystemConfig 模块事件规约
 *
 * 系统配置模块的事件定义，用于通知其他模块配置的读取和变更
 *
 * 事件命名规范：
 * - 格式：Weline_SystemConfig::事件类型::事件名称
 * - 示例：Weline_SystemConfig::domain::config_set
 */

return [
    // ========== Domain Events (领域事件) ==========

    /**
     * 配置读取事件
     * 当配置被读取时触发，允许其他模块拦截或修改返回值
     */
    'Weline_SystemConfig::domain::config_get' => [
        'name' => __('配置读取'),
        'description' => __('当系统配置被读取时触发，允许其他模块拦截或修改配置值。'),
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'key' => [
                'type' => 'string',
                'required' => true,
                'description' => '配置键名',
            ],
            'module' => [
                'type' => 'string',
                'required' => true,
                'description' => '模块名称',
            ],
            'area' => [
                'type' => 'string',
                'required' => true,
                'description' => '区域（backend/frontend）',
            ],
            'value' => [
                'type' => 'mixed',
                'required' => false,
                'description' => '配置值（可被观察者修改）',
            ],
        ],
    ],

    /**
     * 配置设置事件（前置）
     * 在配置被写入数据库前触发，允许其他模块验证或修改配置值
     */
    'Weline_SystemConfig::domain::config_set_before' => [
        'name' => __('配置设置前'),
        'description' => __('在配置写入数据库前触发，允许其他模块验证或修改配置值。'),
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'key' => [
                'type' => 'string',
                'required' => true,
                'description' => '配置键名',
            ],
            'value' => [
                'type' => 'string',
                'required' => true,
                'description' => '配置值（可被观察者修改）',
            ],
            'module' => [
                'type' => 'string',
                'required' => true,
                'description' => '模块名称',
            ],
            'area' => [
                'type' => 'string',
                'required' => true,
                'description' => '区域（backend/frontend）',
            ],
        ],
    ],

    /**
     * 配置设置事件（后置）
     * 在配置成功写入数据库后触发，通知其他模块配置已变更
     */
    'Weline_SystemConfig::domain::config_set_after' => [
        'name' => __('配置设置后'),
        'description' => __('在配置成功写入数据库后触发，通知其他模块配置已变更。Server 模块监听此事件以感知配置变化。'),
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'key' => [
                'type' => 'string',
                'required' => true,
                'description' => '配置键名',
            ],
            'value' => [
                'type' => 'string',
                'required' => true,
                'description' => '配置值',
            ],
            'module' => [
                'type' => 'string',
                'required' => true,
                'description' => '模块名称',
            ],
            'area' => [
                'type' => 'string',
                'required' => true,
                'description' => '区域（backend/frontend）',
            ],
            'old_value' => [
                'type' => 'mixed',
                'required' => false,
                'description' => '旧配置值（如果存在）',
            ],
        ],
    ],
];
