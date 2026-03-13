<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline Server 环境需求声明
 *
 * WLS（Weline Server）高性能常驻内存服务器所需的 PHP 扩展和函数。
 *
 * 跨平台必需扩展：
 *  - sockets:   SO_REUSEPORT 多进程端口复用
 *  - openssl:   HTTPS/SSL 证书管理、ACME 自动续签
 *  - curl:      ACME HTTP-01 验证、压力测试（Benchmark）
 *  - mbstring:  控制台表格中文宽度计算
 *
 * 推荐扩展（可选，安装不成功只提示不阻塞）：
 *  - pcntl:     多进程 fork、信号处理、热重载（Linux/macOS，Windows 不可用）
 *  - posix:     守护进程、用户/组切换、进程管理（Linux/macOS，Windows 不可用）
 *  - event:     高性能事件驱动 I/O（替代 stream_select，性能提升 3-5 倍）
 *  - opcache:   OPcode 缓存，热重载时自动重置
 */
return [
    // PHP 版本约束
    'php' => '^8.1',

    // 跨平台必需的扩展
    'extensions' => [
        'sockets',
        'openssl',
        'curl',
        'mbstring',
    ],

    // 必需的函数（须未被 disable_functions）
    'functions' => [
        'proc_open',
        'proc_close',
        'proc_get_status',
        'shell_exec',
    ],

    // ==================== 推荐项（可选，尝试安装，失败只提示不阻塞）====================

    // 推荐函数（依赖可选扩展，不可用时只提示不阻断）
    'recommended_functions' => [
        'pcntl_fork',       // 多进程 fork（依赖 pcntl 扩展，Windows 不可用）
    ],

    // 推荐扩展（通过 extension= 加载的标准扩展，尝试安装，失败只提示）
    // 格式：字符串 = 跨平台，数组 = 带平台条件 ['name' => 扩展名, 'platform' => 'linux'|'unix'|'windows'|'all']
    // platform: 'unix' = Linux+macOS, 'linux' = 仅 Linux, 'windows' = 仅 Windows, 'all' = 全平台（默认）
    'recommended_extensions' => [
        ['name' => 'pcntl', 'platform' => 'unix', 'reason' => '多进程 fork、信号处理、热重载'],
        ['name' => 'posix', 'platform' => 'unix', 'reason' => '守护进程、用户/组切换、进程管理'],
        'event',    // 高性能事件驱动 I/O，替代 stream_select（跨平台）
        ['name' => 'inotify', 'platform' => 'linux', 'reason' => '热重载文件监控（事件驱动，替代轮询）'],
        // 注意: opcache 是 zend_extension，通过 recommended_items 脚本处理
    ],

    // 推荐复杂依赖项（带安装脚本）
    'recommended_items' => [
        [
            'name' => 'pcntl/posix 扩展（Linux/macOS 多进程）',
            'description' => '多进程 fork、信号处理、热重载、守护进程需要 pcntl/posix 扩展。Windows 下不可用，服务器将以 proc_open 多进程模式运行。',
            'script_linux' => 'script/check_unix_extensions.php',
            'script_windows' => 'script/check_unix_extensions.php',
        ],
        [
            'name' => 'event 扩展（高性能事件循环）',
            'description' => 'libevent 的 PHP 绑定，提供 epoll/kqueue 事件驱动 I/O。安装后 WLS 自动切换为 Event 驱动，比 stream_select 性能提升 3-5 倍。',
            'script_linux' => 'script/install_event_extension.php',
            'script_windows' => 'script/install_event_extension.php',
        ],
        [
            'name' => 'opcache（OPcode 缓存）',
            'description' => 'PHP OPcode 缓存，加速脚本执行。WLS 热重载时自动调用 opcache_reset()。',
            'script_linux' => 'script/check_opcache.php',
            'script_windows' => 'script/check_opcache.php',
        ],
    ],
];
