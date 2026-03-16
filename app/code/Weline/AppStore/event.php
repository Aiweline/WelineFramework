<?php
declare(strict_types=1);

return [
    'Weline_AppStore::download_complete' => [
        'name' => __('应用下载完成'),
        'description' => __('当应用市场模块下载完成后触发，用于安装前检查和下载日志处理。'),
        'doc' => 'integration/download_complete.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'module_name' => ['type' => 'string', 'required' => false, 'description' => '模块名称'],
            'package_path' => ['type' => 'string', 'required' => false, 'description' => '下载包路径'],
            'result' => ['type' => 'array', 'required' => false, 'description' => '下载结果信息'],
        ],
    ],
];
