<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * 平台相关扩展检测/安装脚本（pcntl、posix）
 *
 * Windows 下这两个扩展不可用，服务器以单进程 + proc_open 模式运行，直接通过。
 * Linux/macOS 下需要 pcntl 和 posix 以支持多进程 fork、信号处理、守护进程。
 *
 * 用法：
 *   php check_unix_extensions.php check   - 检查扩展是否可用
 *   php check_unix_extensions.php install  - 尝试安装扩展
 *
 * 退出码：
 *   0 - 成功（check: 扩展可用或 Windows 平台; install: 安装成功）
 *   1 - 失败
 */

$action = $argv[1] ?? 'check';

// Windows 下直接通过——pcntl/posix 不可用，Server 模块已有兼容处理
if (PHP_OS_FAMILY === 'Windows') {
    echo json_encode([
        'installed' => true,
        'message'   => 'Windows 平台无需 pcntl/posix 扩展，服务器将以单进程模式运行',
    ]) . PHP_EOL;
    exit(0);
}

// Linux/macOS：检测 pcntl 和 posix
$requiredExtensions = ['pcntl', 'posix'];
$loadedExtensions   = array_map('strtolower', get_loaded_extensions());

switch ($action) {
    case 'check':
        $missing = [];
        foreach ($requiredExtensions as $ext) {
            if (!in_array($ext, $loadedExtensions, true)) {
                $missing[] = $ext;
            }
        }

        if (empty($missing)) {
            echo json_encode([
                'installed' => true,
                'message'   => 'pcntl 和 posix 扩展已加载',
            ]) . PHP_EOL;
            exit(0);
        }

        echo json_encode([
            'installed' => false,
            'missing'   => $missing,
            'message'   => '缺失扩展: ' . implode(', ', $missing),
            'guide'     => [
                'Ubuntu/Debian' => 'sudo apt-get install -y php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-pcntl php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-posix',
                'CentOS/RHEL'   => 'sudo yum install -y php-process',
                'macOS (brew)'  => 'PHP 通过 Homebrew 安装时默认包含 pcntl/posix',
                'Docker'        => 'docker-php-ext-install pcntl posix',
            ],
        ]) . PHP_EOL;
        exit(1);

    case 'install':
        $missing = [];
        foreach ($requiredExtensions as $ext) {
            if (!in_array($ext, $loadedExtensions, true)) {
                $missing[] = $ext;
            }
        }

        if (empty($missing)) {
            echo json_encode([
                'success' => true,
                'message' => 'pcntl 和 posix 扩展已加载，无需安装',
            ]) . PHP_EOL;
            exit(0);
        }

        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $installed  = [];
        $failed     = [];

        foreach ($missing as $ext) {
            $success = false;

            // Docker 环境
            if (file_exists('/.dockerenv')) {
                $cmd = 'docker-php-ext-install ' . escapeshellarg($ext) . ' 2>&1';
                $output = [];
                $code = 0;
                exec($cmd, $output, $code);
                if ($code === 0) {
                    $success = true;
                }
            }

            // phpenmod（已安装但未启用）
            if (!$success) {
                $cmd = 'phpenmod ' . escapeshellarg($ext) . ' 2>&1';
                $output = [];
                $code = 0;
                @exec($cmd, $output, $code);
                if ($code === 0) {
                    $success = true;
                }
            }

            // apt（Debian/Ubuntu）
            if (!$success) {
                // CentOS/RHEL 中 pcntl 和 posix 在 php-process 包中
                $packages = [
                    'php' . $phpVersion . '-' . $ext,
                    'php-' . $ext,
                ];
                // posix/pcntl 在 CentOS 中属于 php-process
                if ($ext === 'posix' || $ext === 'pcntl') {
                    $packages[] = 'php-process';
                }

                foreach ($packages as $pkg) {
                    $cmd = 'apt-get install -y ' . escapeshellarg($pkg) . ' 2>&1';
                    $output = [];
                    $code = 0;
                    @exec($cmd, $output, $code);
                    if ($code === 0) {
                        $success = true;
                        break;
                    }

                    // 尝试 yum
                    $cmd = 'yum install -y ' . escapeshellarg($pkg) . ' 2>&1';
                    $output = [];
                    $code = 0;
                    @exec($cmd, $output, $code);
                    if ($code === 0) {
                        $success = true;
                        break;
                    }

                    // 尝试 dnf
                    $cmd = 'dnf install -y ' . escapeshellarg($pkg) . ' 2>&1';
                    $output = [];
                    $code = 0;
                    @exec($cmd, $output, $code);
                    if ($code === 0) {
                        $success = true;
                        break;
                    }
                }
            }

            if ($success) {
                $installed[] = $ext;
            } else {
                $failed[] = $ext;
            }
        }

        if (empty($failed)) {
            echo json_encode([
                'success'   => true,
                'installed' => $installed,
                'message'   => '已安装: ' . implode(', ', $installed),
            ]) . PHP_EOL;
            exit(0);
        }

        echo json_encode([
            'success'   => false,
            'installed' => $installed,
            'failed'    => $failed,
            'message'   => '以下扩展安装失败: ' . implode(', ', $failed),
            'guide'     => [
                'Ubuntu/Debian' => 'sudo apt-get install -y php' . $phpVersion . '-pcntl php' . $phpVersion . '-posix',
                'CentOS/RHEL'   => 'sudo yum install -y php-process',
                'macOS (brew)'  => 'PHP 通过 Homebrew 安装时默认包含 pcntl/posix',
                'Docker'        => 'docker-php-ext-install pcntl posix',
            ],
        ]) . PHP_EOL;
        exit(1);

    default:
        echo json_encode(['error' => '未知动作: ' . $action . '，请使用 check 或 install']) . PHP_EOL;
        exit(1);
}
