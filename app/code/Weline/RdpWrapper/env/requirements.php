<?php

declare(strict_types=1);

/**
 * Weline RdpWrapper 环境需求声明
 *
 * RDP Wrapper 多用户远程桌面管理模块所需的 PHP 函数和系统环境。
 *
 * 必需函数：
 *  - exec:        执行 Windows 系统命令（net user、reg、sc 等）
 *  - shell_exec:  执行 PowerShell 安装脚本
 *
 * 系统要求：
 *  - Windows 操作系统（RDP Wrapper 仅支持 Windows）
 *  - 管理员权限（用户管理和 RDP 配置需要）
 */
return [
    // PHP 版本约束
    'php' => '^8.4',

    // 必需的函数（须未被 disable_functions）
    'functions' => [
        'exec',
        'shell_exec',
    ],

    // 必需依赖：仅检测操作系统（不会触发安装，检测即过）
    'items' => [
        [
            'name'           => 'Windows 操作系统',
            'description'    => 'RDP Wrapper 仅支持 Windows 系统。本模块的远程桌面管理功能在非 Windows 系统上不可用。',
            'script_linux'   => 'script/check_windows.php',
            'script_windows' => 'script/check_windows.php',
            'platform'       => 'windows', // 仅 Windows 下参与检测与安装，其他系统跳过
        ],
    ],

    // 推荐项（可选，安装失败不阻塞）
    'recommended_items' => [
        [
            'name'           => 'RDP Wrapper',
            'description'    => 'RDP Wrapper Library (sebaxakerhtc/rdpwrap)，用于支持 Windows 多用户同时远程桌面连接。可在后台管理页面一键安装，或手动下载：https://github.com/sebaxakerhtc/rdpwrap/releases',
            'script_linux'   => 'script/check_rdpwrap.php',
            'script_windows' => 'script/check_rdpwrap.php',
            'platform'       => 'windows',
        ],
        [
            'name'           => '管理员权限',
            'description'    => '创建/删除 Windows 用户、启用/禁用远程桌面需要管理员权限。请以管理员身份运行 PHP 或 Weline Server。',
            'script_linux'   => 'script/check_admin.php',
            'script_windows' => 'script/check_admin.php',
            'platform'       => 'windows',
        ],
    ],
];
