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
    
    private bool $updateAll = false;  // 是否更新整个项目
    private bool $forceUpdate = false;  // 是否强制更新（删除本地重新拉取）
    private int $updatedFiles = 0;  // 更新的文件数
    private int $skippedFiles = 0;  // 跳过的文件数（内容相同）
    private int $newFiles = 0;  // 新增的文件数

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
        return __('从 Git 仓库增量更新框架核心代码（只更新变化的文件）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:update:core',
            __('从 Git 仓库增量更新框架核心代码'),
            [
                '-b, --branch=<分支名>' => '指定分支（必填，如：main, master, dev）',
                '-t, --tag=<标签名>' => '指定标签版本（可选，如：v1.0.0）',
                '--repo=<仓库地址>' => '指定 Git 仓库地址（默认：Gitee 仓库）',
                '-f, --force' => '强制更新：删除本地缓存，重新克隆仓库',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '增量更新' => '默认使用 git pull 增量拉取，只更新有变化的文件',
                '强制更新' => '使用 -f 参数强制删除本地缓存，重新完整克隆',
                '临时目录方式' => '使用临时目录下载，不影响项目 Git 仓库',
                '版本验证' => '如果指定了标签但不存在，命令会报错并退出',
            ],
            [
                '增量更新到最新' => 'php bin/w update:core -b main',
                '强制完整更新' => 'php bin/w update:core -b main -f',
                '指定标签' => 'php bin/w update:core -b main -t v1.0.0',
            ],
            'php bin/w update:core -b <分支名>'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        // 检查是否强制更新
        $this->forceUpdate = isset($args['f']) || isset($args['force']);
        
        $this->printer->note('');
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        if ($this->forceUpdate) {
            $this->printer->setup(__('框架核心代码更新（强制模式 - 完整重新下载）'));
        } else {
            $this->printer->setup(__('框架核心代码更新（增量模式 - 只更新变化部分）'));
        }
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
        $this->printer->note(__('更新模式：%{1}', [$this->forceUpdate ? '强制完整更新' : '增量更新']));
        $this->printer->note('');

        // 3. 创建/准备临时目录
        $this->printer->setup(__('步骤 3/6：准备临时目录...'));
        $tmpDir = $this->prepareTempDirectory();

        // 4. 克隆/拉取仓库
        $this->printer->setup(__('步骤 4/6：下载框架代码...'));
        $this->downloadFramework($repo, $tmpDir, $branch, $tag);

        // 5. 拷贝核心文件（增量）
        $this->printer->setup(__('步骤 5/6：更新核心文件...'));
        $this->copyCoreFiles($tmpDir);

        // 6. 保留临时目录（用于下次增量更新），除非强制模式
        $this->printer->setup(__('步骤 6/6：完成处理...'));
        if ($this->forceUpdate) {
            $this->printer->note(__('强制模式：保留缓存目录用于下次增量更新'));
        } else {
            $this->printer->note(__('增量模式：保留缓存目录用于下次更新'));
        }
        $this->printer->success(__('✓ 缓存目录：%{1}', [$tmpDir]));

        $this->printer->note('');
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->success(__('✓✓✓ 框架核心更新完成！✓✓✓'));
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');
        
        // 显示更新统计
        $this->printer->note(__('更新统计：'));
        $this->printer->note(__('  - 新增文件：%{1} 个', [$this->newFiles]));
        $this->printer->note(__('  - 更新文件：%{1} 个', [$this->updatedFiles]));
        $this->printer->note(__('  - 跳过文件：%{1} 个（内容相同）', [$this->skippedFiles]));
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
        
        // 只有在强制模式下才删除旧目录
        if ($this->forceUpdate && is_dir($tmpDir)) {
            $this->printer->note(__('强制模式：删除旧缓存目录...'));
            $this->removeDirectory($tmpDir);
        }
        
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
            $this->printer->success(__('✓ 创建新缓存目录：%{1}', [$tmpDir]));
        } else {
            $this->printer->success(__('✓ 使用现有缓存目录：%{1}', [$tmpDir]));
        }
        
        return $tmpDir;
    }

    private function downloadFramework(string $repo, string $tmpDir, string $branch, ?string $tag): void
    {
        // 检查是否已有 Git 仓库
        $gitDir = $tmpDir . DS . '.git';
        $isExistingRepo = is_dir($gitDir);
        
        if ($isExistingRepo && !$this->forceUpdate) {
            // 增量模式：已有仓库，使用 git fetch + reset 更新
            $this->printer->note(__('增量更新：检测到现有仓库缓存，使用 git fetch 拉取变更...'));
            
            // 获取远程更新
            $fetchCmd = sprintf('cd %s && git fetch origin', escapeshellarg($tmpDir));
            exec($fetchCmd, $output, $fetchCode);
            
            if ($fetchCode !== 0) {
                $this->printer->warning(__('fetch 失败，尝试重新克隆...'));
                $this->removeDirectory($tmpDir);
                mkdir($tmpDir, 0755, true);
                $this->cloneRepository($repo, $tmpDir, $branch, $tag);
                return;
            }
            
            if ($tag) {
                // 如果指定了标签，获取所有标签并切换
                $this->printer->note(__('获取标签并切换到 %{1}...', [$tag]));
                exec(sprintf('cd %s && git fetch --tags', escapeshellarg($tmpDir)), $output, $tagFetchCode);
                exec(sprintf('cd %s && git checkout %s', escapeshellarg($tmpDir), escapeshellarg($tag)), $output, $checkoutCode);
                
                if ($checkoutCode !== 0) {
                    $this->printer->error(__('标签不存在: %{1}', [$tag]));
                    exit(1);
                }
                $this->printer->success(__('✓ 已切换到标签 %{1}', [$tag]));
            } else {
                // 重置到远程分支最新
                $this->printer->note(__('重置到远程分支 %{1} 最新...', [$branch]));
                
                // 先切换到目标分支
                exec(sprintf('cd %s && git checkout %s 2>&1', escapeshellarg($tmpDir), escapeshellarg($branch)), $output, $checkoutCode);
                
                if ($checkoutCode !== 0) {
                    // 分支不存在，从远程创建
                    exec(sprintf('cd %s && git checkout -b %s origin/%s 2>&1', 
                        escapeshellarg($tmpDir), 
                        escapeshellarg($branch), 
                        escapeshellarg($branch)
                    ), $output, $createBranchCode);
                    
                    if ($createBranchCode !== 0) {
                        $this->printer->error(__('分支不存在: %{1}', [$branch]));
                        exit(1);
                    }
                }
                
                // 强制重置到远程分支最新
                $resetCmd = sprintf('cd %s && git reset --hard origin/%s', 
                    escapeshellarg($tmpDir), 
                    escapeshellarg($branch)
                );
                exec($resetCmd, $output, $resetCode);
                
                if ($resetCode !== 0) {
                    $this->printer->warning(__('重置失败，尝试重新克隆...'));
                    $this->removeDirectory($tmpDir);
                    mkdir($tmpDir, 0755, true);
                    $this->cloneRepository($repo, $tmpDir, $branch, $tag);
                    return;
                }
                
                $this->printer->success(__('✓ 已更新到分支 %{1} 最新版本', [$branch]));
            }
            
            // 显示最新提交信息
            $this->showLatestCommit($tmpDir);
            
        } else {
            // 强制模式或新仓库：完整克隆
            $this->cloneRepository($repo, $tmpDir, $branch, $tag);
        }
    }
    
    /**
     * 完整克隆仓库
     */
    private function cloneRepository(string $repo, string $tmpDir, string $branch, ?string $tag): void
    {
        $this->printer->note(__('完整克隆仓库...'));
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
        
        // 显示最新提交信息
        $this->showLatestCommit($tmpDir);
    }
    
    /**
     * 显示最新提交信息
     */
    private function showLatestCommit(string $tmpDir): void
    {
        exec(sprintf('cd %s && git log -1 --format="%%h - %%s (%%ci)"', escapeshellarg($tmpDir)), $logOutput, $logCode);
        if ($logCode === 0 && !empty($logOutput)) {
            $this->printer->note(__('最新提交：%{1}', [$logOutput[0]]));
        }
    }

    private function copyCoreFiles(string $tmpDir): void
    {
        // 重置计数器
        $this->updatedFiles = 0;
        $this->skippedFiles = 0;
        $this->newFiles = 0;
        
        // 更新整个项目，增量更新（只更新变化的文件）
        $processedPaths = 0;
        $failedPaths = 0;
        
        // 需要更新的所有目录（但不包括 vendor）
        $allPaths = ['app', 'bin', 'pub'];
        
        foreach ($allPaths as $path) {
            $source = $tmpDir . DS . $path;
            $target = BP . $path;
            
            if (!is_dir($source)) {
                $this->printer->warning(__('⚠ 源路径不存在: %{1}', [$path]));
                $failedPaths++;
                continue;
            }
            
            $this->printer->note(__('扫描 %{1}...', [$path]));
            
            // 增量更新：只更新有变化的文件
            if ($this->copyDirectoryIncremental($source, $target)) {
                $this->printer->success(__('✓ %{1}', [$path]));
                $processedPaths++;
            } else {
                $this->printer->warning(__('⚠ %{1} 处理失败', [$path]));
                $failedPaths++;
            }
        }
        
        // 注意：不更新 vendor 目录，保留现有的依赖包
        $this->printer->note('');
        $this->printer->success(__('✓ 共处理 %{1} 个路径', [$processedPaths]));
        if ($failedPaths > 0) {
            $this->printer->warning(__('⚠ 跳过 %{1} 个路径', [$failedPaths]));
        }
        $this->printer->note(__('⚠ vendor 目录未更新，保留现有依赖包'));
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
     * 增量更新目录：
     * 1. 新文件 → 拷贝
     * 2. 内容变化的文件 → 更新
     * 3. 内容相同的文件 → 跳过
     * 4. 受保护的配置文件 → 永不覆盖
     * 5. Git 仓库中不存在的用户文件 → 保留不动
     */
    private function copyDirectoryIncremental(string $source, string $target): bool
    {
        if (!is_dir($source)) {
            return false;
        }
        
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        
        // 需要保护的完整文件路径（绝对不覆盖）
        $protectedPaths = ['etc' . DS . 'env.php', '.env'];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathName();
            $targetPath = $target . DS . $relativePath;
            
            if ($item->isDir()) {
                // 目录：不存在就创建，存在就保持（永远不删除）
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } elseif ($item->isFile()) {
                $sourcePath = $item->getPathname();
                
                // 检查是否是受保护的文件路径（完整路径匹配）
                $shouldProtect = false;
                foreach ($protectedPaths as $protectedPath) {
                    if ($relativePath === $protectedPath) {
                        $shouldProtect = true;
                        break;
                    }
                }
                
                // 保护配置文件：绝对不覆盖 app/etc/env.php 和 .env
                if ($shouldProtect && file_exists($targetPath)) {
                    // 目标文件已存在，绝对跳过（保护用户配置）
                    $this->skippedFiles++;
                    continue;
                }
                
                // 检查目标文件是否存在
                if (file_exists($targetPath)) {
                    // 文件已存在，比较内容是否相同
                    if ($this->isFileContentSame($sourcePath, $targetPath)) {
                        // 内容相同，跳过
                        $this->skippedFiles++;
                        continue;
                    }
                    // 内容不同，更新文件
                    copy($sourcePath, $targetPath);
                    $this->updatedFiles++;
                } else {
                    // 新文件，拷贝
                    copy($sourcePath, $targetPath);
                    $this->newFiles++;
                }
            }
        }
        
        return true;
    }
    
    /**
     * 比较两个文件内容是否相同（使用 MD5 哈希）
     */
    private function isFileContentSame(string $file1, string $file2): bool
    {
        // 先比较文件大小，大小不同则内容必定不同
        $size1 = filesize($file1);
        $size2 = filesize($file2);
        
        if ($size1 !== $size2) {
            return false;
        }
        
        // 大小相同，比较内容哈希
        return md5_file($file1) === md5_file($file2);
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

