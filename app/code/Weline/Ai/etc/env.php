<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>
 */

/**
 * AI模块环境配置
 */
return [
    // 路由别名配置
    'router' => 'ai',
    
    // 模块配置
    'config' => [
        // 默认AI模型配置
        'default_model' => [
            'vendor' => 'openai',
            'model_code' => 'gpt-3.5-turbo',
        ],
        
        // 场景适配器配置
        'adapters' => [
            'scan_path' => 'app/code/Weline/Ai/Adapter/',
            'auto_scan' => true,
        ],
        
        // API配置
        'api' => [
            'timeout' => 30,
            'retry_times' => 3,
            'rate_limit' => 100, // 每分钟请求限制
        ],
        
        // 缓存配置
        'cache' => [
            'model_list_ttl' => 3600, // 模型列表缓存时间（秒）
            'adapter_list_ttl' => 1800, // 适配器列表缓存时间（秒）
        ],
    ]
];
