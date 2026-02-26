<?php

declare(strict_types=1);

/*
 * Weline MediaManager 模块事件规约
 *
 * 事件命名规范：
 * - 格式：模块名::事件类型::事件名称
 * - 示例：Weline_MediaManager::integration::supported_preview_formats
 *
 * 事件类型：
 * - domain: 领域事件（Domain Events）业务领域内的事件
 * - integration: 集成事件（Integration Events）跨模块/系统的事件
 * - application: 应用事件（Application Events）应用层事件
 */

return [
    // ========== Integration Events (集成事件) ==========
    
    /**
     * 支持的预览格式
     * 允许其他模块注册可预览的文件格式及其处理器
     */
    'Weline_MediaManager::integration::supported_preview_formats' => [
        'name' => __('支持的预览格式'),
        'description' => __('允许其他模块注册可预览的文件格式。Observer 可向 formats 数组添加 MIME 类型。'),
        'doc' => 'integration/supported_preview_formats.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'formats' => [
                'type' => 'array',
                'required' => true,
                'description' => 'MIME 类型数组（引用传递），可追加新格式',
            ],
        ],
    ],
    
    /**
     * 文件上传前
     * 在文件保存到存储之前触发，可用于验证、修改文件名等
     */
    'Weline_MediaManager::domain::file_upload_before' => [
        'name' => __('文件上传前'),
        'description' => __('文件上传到存储之前触发，可用于验证或修改上传参数。'),
        'doc' => 'domain/file_upload_before.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'file' => [
                'type' => 'array',
                'required' => true,
                'description' => '上传文件信息（name, tmp_name, size, type, error）',
            ],
            'target_path' => [
                'type' => 'string',
                'required' => true,
                'description' => '目标保存路径',
            ],
            'cancel' => [
                'type' => 'bool',
                'required' => false,
                'description' => '设为 true 可取消上传',
                'default' => false,
            ],
        ],
    ],
    
    /**
     * 文件上传后
     * 文件成功保存到存储后触发
     */
    'Weline_MediaManager::domain::file_upload_after' => [
        'name' => __('文件上传后'),
        'description' => __('文件成功上传后触发，可用于后续处理如生成缩略图、记录日志等。'),
        'doc' => 'domain/file_upload_after.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'file_path' => [
                'type' => 'string',
                'required' => true,
                'description' => '已保存的文件完整路径',
            ],
            'file_info' => [
                'type' => 'array',
                'required' => true,
                'description' => '文件信息（hash, name, mime, size 等）',
            ],
        ],
    ],
    
    /**
     * 文件删除前
     */
    'Weline_MediaManager::domain::file_delete_before' => [
        'name' => __('文件删除前'),
        'description' => __('文件删除前触发，可用于验证或阻止删除。'),
        'doc' => 'domain/file_delete_before.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'file_path' => [
                'type' => 'string',
                'required' => true,
                'description' => '待删除的文件路径',
            ],
            'cancel' => [
                'type' => 'bool',
                'required' => false,
                'description' => '设为 true 可取消删除',
                'default' => false,
            ],
        ],
    ],
    
    /**
     * 存储源切换
     */
    'Weline_MediaManager::application::storage_switched' => [
        'name' => __('存储源切换'),
        'description' => __('用户切换存储源时触发。'),
        'doc' => 'application/storage_switched.md',
        'version' => '1.0.0',
        'type' => 'application',
        'data_contract' => [
            'from_storage' => [
                'type' => 'string',
                'required' => true,
                'description' => '原存储源标识',
            ],
            'to_storage' => [
                'type' => 'string',
                'required' => true,
                'description' => '新存储源标识',
            ],
        ],
    ],
];
