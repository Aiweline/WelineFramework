<?php

declare(strict_types=1);

/**
 * 检测当前操作系统是否为 Windows
 *
 * 用法: php check_windows.php check|install
 */

$action = $argv[1] ?? 'check';

if ($action === 'check') {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        echo "INSTALLED\n";
        echo "当前系统: Windows (" . php_uname('v') . ")\n";
        exit(0);
    }
    echo "MISSING\n";
    echo "当前系统: " . PHP_OS . " - RDP Wrapper 仅支持 Windows 系统\n";
    exit(1);
}

if ($action === 'install') {
    echo "RDP Wrapper 仅支持 Windows 操作系统，无法在当前系统上安装。\n";
    echo "请在 Windows 系统上运行此模块。\n";
    exit(1);
}
