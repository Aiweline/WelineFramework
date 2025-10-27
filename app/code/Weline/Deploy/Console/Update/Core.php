<?php

declare(strict_types=1);

/**
 * Deploy Core Update Command
 * 
 * 从 Git 仓库更新框架核心代码（使用临时目录，不影响项目 Git 仓库）
 *
 * @package Weline\Deploy\Console\Update
 * @author WelineFramework Team
 */

namespace Weline\Deploy\Console\Update;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Output\Cli\Printing;

class Core extends CommandAbstract
{
    private System $system;
    private bool $isWindows;
    private string $defaultRepo = 'https://gitee.com/aiweline/WelineFramework.git';
    
    private array $corePaths = [
        'app/code/Weline/Framework',
        'vendor',
        'app/etc',
        'bin',
        'pub',
    ];

    public function __construct(
        Printing $printer,
        System $system
    ) {
        $this->printer = $printer;
        $this->system = $system;
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    public function tip(): string
    {
        return __('从 Git 仓库更新框架核心代码（只更新核心文件）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:update:core',
            __('从 Git 仓库更新框架核心代码'),
            [
                '-b, --branch=<分支名>' => '指定分支（必填，如：main, master, dev）',
                '-t, --tag=<标签名>' => '指定标签版本（可选，如：v1.0.0）',
                '--repo=<仓库地址>' => '指定 Git 仓库地址（默认：Gitee 仓库）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '强制指定分支' => '必须使用 -b 或 --branch 参数指定分支名',
                '临时目录方式' => '使用临时目录下载，不影响项目 Git 仓库',
                '版本验证' => '如果指定了标签但不存在，命令会报错并退出',
            ],
            [
                '更新到最新' => 'php bin/w update:core -b main',
                '指定标签' => 'php bin/w update:core -b main -t v1.0.0',
            ],
            'php bin/w update:core -b <分支名>'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note('');
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->setup(__('框架核心代码更新（临时目录方式）'));
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');

        // 1. 检查 Git
        $this->printer->setup(__('步骤 1/6：检查 Git...'));
        $this->checkGit();

        // 2. 验证参数
        $this->printer->setup(__('步骤 2/6：验证参数...'));
        $branch = $this->getBranch($args);
        $tag = $args['tag'] ?? $args['t'] ?? null;
        $repo = $args['repo'] ?? $this->defaultRepo;
        
        $this->printer->note(__('仓库：%{1}', [$repo]));
        $this->printer->note(__('分支：%{1}', [$branch]));
        if ($tag) {
            $this->printer->note(__('标签：%{1}', [$tag]));
        }
        $this->printer->note('');
        $this->printer->note(__('将更新以下核心路径：'));
        foreach ($this->corePaths as $path) {
            $this->printer->note(__('  - %{1}', [$path]));
        }

        // 3. 创建临时目录
        $this->printer->setup(__('步骤 3/6：准备临时目录...'));
        $tmpDir = $this->prepareTempDirectory();

        // 4. 克隆/拉取仓库
        $this->printer->setup(__('步骤 4/6：下载框架代码...'));
        $this->downloadFramework($repo, $tmpDir, $branch, $tag);

        // 5. 拷贝核心文件
        $this->printer->setup(__('步骤 5/6：更新核心文件...'));
        $this->copyCoreFiles($tmpDir);

        // 6. 清理
        $this->printer->setup(__('步骤 6/6：清理临时文件...'));
        $this->cleanup($tmpDir);

        $this->printer->note('');
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->success(__('✓✓✓ 框架核心更新完成！✓✓✓'));
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');
    }

    private function checkGit(): void
    {
        if (!$this->commandExists('git')) {
            $this->printer->error(__('错误：未检测到 Git'));
            exit(1);
        }
        $this->printer->success(__('✓ Git 检查通过'));
    }

    private function getBranch(array $args): string
    {
        $branch = $args['branch'] ?? $args['b'] ?? null;
        if (empty($branch)) {
            $this->printer->error(__('错误：必须指定分支'));
            $this->printer->note(__('php bin/w update:core -b <分支名>'));
            exit(1);
        }
        return $branch;
    }

    private function prepareTempDirectory(): string
    {
        $tmpDir = Env::backup_dir . 'tmp' . DS . 'core-update';
        
        if (is_dir($tmpDir)) {
            $this->printer->note(__('删除旧临时目录...'));
            $this->removeDirectory($tmpDir);
        }
        
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        
        $this->printer->success(__('✓ 临时目录：%{1}', [$tmpDir]));
        return $tmpDir;
    }

    private function downloadFramework(string $repo, string $tmpDir, string $branch, ?string $tag): void
    {
        $this->printer->note(__('克隆仓库...'));
        $command = sprintf(
            'cd %s && git clone -b %s --depth 1 %s .',
            escapeshellarg($tmpDir),
            escapeshellarg($branch),
            escapeshellarg($repo)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->printer->warning(__('克隆失败，尝试初始化仓库...'));
            
            // 如果不是 git 仓库，先初始化
            exec('cd ' . escapeshellarg($tmpDir) . ' && git init', $output, $initCode);
            exec('cd ' . escapeshellarg($tmpDir) . ' && git remote add origin ' . escapeshellarg($repo), $output, $remoteCode);
            exec('cd ' . escapeshellarg($tmpDir) . ' && git fetch origin', $output, $fetchCode);
            
            if ($fetchCode !== 0) {
                $this->printer->error(__('初始化仓库失败'));
                $this->printer->printList($output);
                exit(1);
            }
            
            // 根据是否有标签来拉取代码
            if ($tag) {
                $this->printer->note(__('拉取标签 %{1}...', [$tag]));
                exec('cd ' . escapeshellarg($tmpDir) . ' && git checkout ' . escapeshellarg($tag), $output, $checkoutCode);
                
                if ($checkoutCode !== 0) {
                    $this->printer->error(__('标签不存在: %{1}', [$tag]));
                    exit(1);
                }
            } else {
                $this->printer->note(__('拉取分支 %{1}...', [$branch]));
                exec('cd ' . escapeshellarg($tmpDir) . ' && git checkout -b ' . escapeshellarg($branch) . ' origin/' . escapeshellarg($branch), $output, $checkoutCode);
                
                if ($checkoutCode !== 0) {
                    $this->printer->error(__('分支不存在: %{1}', [$branch]));
                    exit(1);
                }
            }
            
            $this->printer->success(__('✓ 仓库初始化成功'));
        } else {
            $this->printer->success(__('✓ 仓库克隆成功'));
            
            if ($tag) {
                $this->printer->note(__('切换到标签 %{1}...', [$tag]));
                $command = sprintf(
                    'cd %s && git fetch --tags && git checkout %s',
                    escapeshellarg($tmpDir),
                    escapeshellarg($tag)
                );
                
                exec($command, $output, $returnCode);
                
                if ($returnCode !== 0) {
                    $this->printer->error(__('标签不存在: %{1}', [$tag]));
                    exit(1);
                }
                
                $this->printer->success(__('✓ 已切换到标签 %{1}', [$tag]));
            }
        }
    }

    private function copyCoreFiles(string $tmpDir): void
    {
        $copied = 0;
        $skipped = 0;
        
        foreach ($this->corePaths as $path) {
            $source = $tmpDir . DS . $path;
            $target = BP . $path;
            
            if (!is_dir($source) && !is_file($source)) {
                $this->printer->warning(__('⚠ 源路径不存在: %{1}', [$path]));
                $skipped++;
                continue;
            }
            
            $this->printer->note(__('更新 %{1}...', [$path]));
            
            if (is_dir($source)) {
                // 改为增量更新，不删除目标目录，只拷贝新文件
                if ($this->copyDirectoryIncremental($source, $target)) {
                    $this->printer->success(__('✓ %{1}', [$path]));
                    $copied++;
                } else {
                    $this->printer->warning(__('⚠ %{1} 拷贝失败', [$path]));
                    $skipped++;
                }
            } else {
                if (file_exists($target)) {
                    unlink($target);
                }
                
                if ($this->copyFile($source, $target)) {
                    $this->printer->success(__('✓ %{1}', [$path]));
                    $copied++;
                } else {
                    $this->printer->warning(__('⚠ %{1} 拷贝失败', [$path]));
                    $skipped++;
                }
            }
        }
        
        $this->printer->note('');
        $this->printer->success(__('✓ 共更新 %{1} 个路径', [$copied]));
        if ($skipped > 0) {
            $this->printer->warning(__('⚠ 跳过 %{1} 个路径', [$skipped]));
        }
    }

    private function copyDirectory(string $source, string $target): bool
    {
        if (!is_dir($source)) {
            return false;
        }
        
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $targetPath = $target . DS . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $sourcePath = $item->getPathname();
                copy($sourcePath, $targetPath);
            }
        }
        
        return true;
    }

    /**
     * 增量更新目录，保护用户配置文件
     */
    private function copyDirectoryIncremental(string $source, string $target): bool
    {
        if (!is_dir($source)) {
            return false;
        }
        
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        
        // 需要保护的配置文件列表
        $protectedFiles = ['env.php', '.env'];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $targetPath = $target . DS . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } elseif ($item->isFile()) {
                $sourcePath = $item->getPathname();
                $fileName = basename($targetPath);
                
                // 保护用户配置文件，不覆盖
                if (in_array($fileName, $protectedFiles) && file_exists($targetPath)) {
                    continue;
                }
                
                copy($sourcePath, $targetPath);
            }
        }
        
        return true;
    }

    private function copyFile(string $source, string $target): bool
    {
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return copy($source, $target);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        if ($this->isWindows) {
            exec(sprintf('rmdir /s /q %s', escapeshellarg($dir)));
        } else {
            exec(sprintf('rm -rf %s', escapeshellarg($dir)));
        }
    }

    private function cleanup(string $tmpDir): void
    {
        $this->removeDirectory($tmpDir);
        $this->printer->success(__('✓ 临时文件已清理'));
    }

    private function commandExists(string $command): bool
    {
        if ($this->isWindows) {
            exec("where {$command} 2>nul", $output, $returnCode);
        } else {
            exec("which {$command} 2>/dev/null", $output, $returnCode);
        }
        return $returnCode === 0;
    }
}

