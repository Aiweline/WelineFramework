<?php

declare(strict_types=1);

/**
 * 检测 RDP Wrapper 是否已安装 / 自动提权安装
 *
 * 用法: php check_rdpwrap.php check|install
 */

$action = $argv[1] ?? 'check';
$installDir = 'C:\\Program Files\\RDP Wrapper';

if ($action === 'check') {
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        echo "MISSING\n";
        echo "非 Windows 系统，RDP Wrapper 不适用\n";
        exit(1);
    }

    if (is_dir($installDir) && file_exists($installDir . '\\rdpwrap.dll')) {
        echo "INSTALLED\n";
        echo "RDP Wrapper 已安装: {$installDir}\n";

        // 检查 TermService 服务状态
        $output = [];
        exec('sc query TermService 2>nul', $output);
        $outputStr = implode(' ', $output);
        if (str_contains($outputStr, 'RUNNING')) {
            echo "TermService 服务: 运行中\n";
        } else {
            echo "TermService 服务: 未运行（请启动远程桌面服务）\n";
        }

        exit(0);
    }

    echo "MISSING\n";
    echo "RDP Wrapper 未安装\n";
    echo "可通过后台管理页面一键安装，或手动下载：https://github.com/sebaxakerhtc/rdpwrap/releases\n";
    exit(1);
}

if ($action === 'install') {
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        echo "RDP Wrapper 仅支持 Windows 系统\n";
        exit(1);
    }

    // 已安装则跳过
    if (is_dir($installDir) && file_exists($installDir . '\\rdpwrap.dll')) {
        echo "INSTALLED\n";
        echo "RDP Wrapper 已安装，无需重复安装\n";
        exit(0);
    }

    // 查找模块安装脚本
    $moduleDir = dirname(__DIR__, 2);
    $installScript = $moduleDir . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'install_rdpwrap.ps1';

    if (!file_exists($installScript)) {
        echo "安装脚本不存在: {$installScript}\n";
        echo "请手动从 GitHub 下载 RDP Wrapper:\n";
        echo "https://github.com/sebaxakerhtc/rdpwrap/releases\n";
        exit(1);
    }

    // 检测是否具有管理员权限
    $isAdmin = false;
    $output = [];
    exec('net session 2>&1', $output, $returnCode);
    $isAdmin = ($returnCode === 0);

    if ($isAdmin) {
        // 已有管理员权限，直接执行
        echo "以管理员权限执行安装脚本...\n";
        $cmd = "powershell -NoProfile -ExecutionPolicy Bypass -File \"{$installScript}\" 2>&1";
        passthru($cmd, $returnCode);
        exit($returnCode);
    }

    // 非管理员：使用 Start-Process -Verb RunAs 自动提权执行
    echo "当前非管理员权限，正在请求提权安装...\n";
    echo "系统将弹出 UAC 提权确认窗口，请点击\"是\"。\n\n";

    // 构建提权命令：用 Start-Process 以管理员身份启动 PowerShell 执行安装脚本
    $escapedScript = str_replace("'", "''", $installScript);
    $psCmd = "Start-Process powershell -Verb RunAs -Wait -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','\"{$escapedScript}\"'";
    $cmd = "powershell -NoProfile -ExecutionPolicy Bypass -Command \"{$psCmd}\" 2>&1";
    passthru($cmd, $returnCode);

    // 安装后再次检测
    if (is_dir($installDir) && file_exists($installDir . '\\rdpwrap.dll')) {
        echo "\nINSTALLED\n";
        echo "RDP Wrapper 安装成功！\n";
        exit(0);
    }

    echo "\n安装可能未完成，请检查 UAC 是否已授权。\n";
    echo "也可手动下载安装：https://github.com/sebaxakerhtc/rdpwrap/releases\n";
    exit(1);
}
