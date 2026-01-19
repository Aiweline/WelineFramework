<?php

/*
 * GuoLaiRen Desensitization Module Configuration
 * 数据脱敏模块配置文件
 */

return [
    // 模块路由别名配置
    'router' => 'desensitization',
    'backend_router' => 'guolairen_desensitization',
    
    'desensitization' => [
        // AI模型配置
        'ai' => [
            // 使用的AI模型代码，为空则使用默认模型
            'model_code' => '',
            
            // 是否启用AI脱敏
            'enabled' => true,
            
            // 脱敏提示词模板
            'prompt_template' => '请对以下内容进行数据脱敏处理，保护敏感信息：{content}',
            
            // 脱敏场景适配器代码
            'desensitization_adapter' => 'desensitization',
            
            // 重写场景适配器代码
            'rewrite_adapter' => 'rewrite',
        ],
        
        // 默认脱敏规则
        'default_rules' => [
            'email' => [
                'pattern' => '/([a-zA-Z0-9._-]+)@([a-zA-Z0-9.-]+)\.([a-zA-Z]{2,})/',
                'replacement' => '$1***@$2.***',
                'type' => 'email',
                'description' => '邮箱脱敏：保留@前部分首尾，中间用***替换'
            ],
            'phone' => [
                'pattern' => '/(\d{3})\d{4}(\d{4})/',
                'replacement' => '$1****$2',
                'type' => 'phone',
                'description' => '手机号脱敏：保留前3位和后4位，中间用****替换'
            ],
            'id_card' => [
                'pattern' => '/(\d{6})\d{8}(\d{4})/',
                'replacement' => '$1********$2',
                'type' => 'id_card',
                'description' => '身份证号脱敏：保留前6位和后4位，中间用********替换'
            ],
            'bank_card' => [
                'pattern' => '/(\d{4})\d{12}(\d{4})/',
                'replacement' => '$1************$2',
                'type' => 'bank_card',
                'description' => '银行卡号脱敏：保留前4位和后4位，中间用************替换'
            ],
            'credit_card' => [
                'pattern' => '/(\d{4})[\s-]?\d{4}[\s-]?\d{4}[\s-]?(\d{4})/',
                'replacement' => '$1****$2',
                'type' => 'credit_card',
                'description' => '信用卡号脱敏：保留前4位和后4位'
            ],
            'name' => [
                'pattern' => '/[\x{4e00}-\x{9fa5}]{2,4}/u',
                // 禁止在配置文件中直接写闭包，将脱敏用的替换规则用字符串标记
                'replacement' => 'mask_chinese_name',
                'type' => 'name',
                'description' => '姓名脱敏：2字显示第1字+*，3字及以上显示首字+*+尾字'
            ],
        ],
        
        // 脱敏策略配置
        'strategies' => [
            'regex' => [
                'description' => '正则脱敏 - 基于规则快速脱敏',
                'method' => 'regex'
            ],
            'ai' => [
                'description' => 'AI智能脱敏 - 使用AI理解上下文并脱敏',
                'method' => 'ai'
            ],
            'custom' => [
                'description' => '自定义脱敏 - 使用自定义规则',
                'method' => 'custom'
            ]
        ],
        
        // AI重写风格配置
        'rewrite_styles' => [
            'natural' => '自然流畅',
            'formal' => '正式专业',
            'casual' => '轻松随意',
            'professional' => '专业严谨',
            'concise' => '简洁精炼'
        ],
        
        // 批量处理配置
        'batch' => [
            // 单次处理的最大记录数
            'max_records' => 1000,
            
            // 批量处理延迟（秒）
            'delay' => 0.1,
            
            // 是否异步处理
            'async' => false
        ],
        
        // 日志配置
        'logging' => [
            // 是否记录脱敏日志
            'enabled' => true,
            
            // 日志保留天数
            'retention_days' => 30
        ]
    ]
];

