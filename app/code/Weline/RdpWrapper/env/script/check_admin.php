<?php

declare(strict_types=1);

/**
 * 检测当前 PHP 进程是否以管理员权限运行
 *
 * 用法: php check_admin.php check|install
 */

$action = $argv[1] ?? 'check';

if ($action === 'check') {
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        // 非 Windows 检查 root 权限
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            echo "INSTALLED\n";
            echo "当前以 root 权限运行\n";
            exit(0);
        }
        echo "MISSING\n";
        echo "建议以 root 权限运行以支持完整功能\n";
        exit(1);
    }

    // Windows 检查管理员权限
    $output = [];
    exec('net session 2>&1', $output, $returnCode);

    if ($returnCode === 0) {
        echo "INSTALLED\n";
        echo "当前以管理员权限运行\n";
        exit(0);
    }

    echo "MISSING\n";
    echo "当前未以管理员权限运行\n";
    echo "创建/删除 Windows 用户、启用/禁用远程桌面需要管理员权限\n";
    echo "请以管理员身份运行 PHP 或 Weline Server\n";
    exit(1);
}

if ($action === 'install') {
    echo "管理员权限无法通过脚本自动获取。\n";
    echo "\n";
    echo "请按以下方式以管理员身份运行：\n";
    echo "  1. 右键点击 PowerShell 或命令提示符\n";
    echo "  2. 选择"以管理员身份运行"\n";
    echo "  3. 在管理员窗口中启动 Weline Server\n";
    exit(1);
}
