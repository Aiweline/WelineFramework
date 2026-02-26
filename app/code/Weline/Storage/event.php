<?php

declare(strict_types=1);

/*
 * Weline Storage 模块事件规约
 *
 * 事件命名规范：
 * - 格式：模块名::事件类型::事件名称
 * - 示例：Weline_Storage::integration::register_drivers
 */

return [
    // ========== Integration Events (集成事件) ==========
    
    /**
     * 注册存储驱动
     * 允许其他模块注册自定义存储驱动
     */
    'Weline_Storage::integration::register_drivers' => [
        'name' => __('注册存储驱动'),
        'description' => __('允许其他模块注册自定义存储驱动。Observer 可向 drivers 数组添加驱动类。'),
        'doc' => 'integration/register_drivers.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'drivers' => [
                'type' => 'array',
                'required' => true,
                'description' => '驱动数组（引用传递），格式：[driver_name => AdapterClass::class]',
            ],
        ],
    ],
    
    /**
     * 存储配置变更
     */
    'Weline_Storage::domain::config_changed' => [
        'name' => __('存储配置变更'),
        'description' => __('存储配置新增、修改或删除后触发。'),
        'doc' => 'domain/config_changed.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'action' => [
                'type' => 'string',
                'required' => true,
                'description' => '操作类型：create/update/delete',
            ],
            'config' => [
                'type' => 'array',
                'required' => true,
                'description' => '配置数据',
            ],
        ],
    ],
];
