<?php

/**
 * Agent_CursorSupervisor 模块环境依赖声明
 * 
 * 运行 php bin/w env:check 检查
 * 运行 php bin/w env:install 自动安装
 */
return [
    // PHP 版本要求
    'php' => '^8.1',

    // 必须的扩展
    'extensions' => [
        'json',      // JSON 处理
        'mbstring',  // 多字节字符串
    ],

    // 必须的函数
    'functions' => [
        'proc_open',  // 进程管理
        'exec',       // 命令执行
    ],

    // 推荐的扩展（交互模式需要）
    'recommended_extensions' => [
        'readline',   // 交互式命令行（Tab 补全、历史记录）
    ],

    // 推荐项（带详细说明）
    'recommended_items' => [
        [
            'name' => 'Readline Extension',
            'description' => '交互式命令行支持，提供 Tab 自动补全、命令历史、类似 Claude CLI 的体验',
            'check' => "extension_loaded('readline')",
            'install_hint' => 'Windows: php.ini 中取消 ;extension=readline 注释；Linux: apt-get install php-readline',
        ],
    ],
];
