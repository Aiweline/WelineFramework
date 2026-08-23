<?php

declare(strict_types=1);

/*
 * Weline Storage 模块事件规约
 *
 * 事件命名规范：
 * - 格式：模块名::事件类型::事件名称
 * Driver 发现使用模块 provides 中的 storage.driver_provider.* 编译注册表，
 * WLS 请求期间不通过事件扫描或修改 Provider。
 */

return [
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
