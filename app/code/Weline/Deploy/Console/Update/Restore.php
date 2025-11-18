<?php

declare(strict_types=1);

/**
 * Restore Core Files From Git
 * 
 * 从当前 Git 仓库恢复框架核心文件
 *
 * @package Weline\Deploy\Console\Update
 * @author WelineFramework Team
 */

namespace Weline\Deploy\Console\Update;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Output\Cli\Printing;

class Restore extends CommandAbstract
{
    private bool $isWindows;
    
    private array $corePaths = [
        'app/code/Weline/Framework',
        'vendor',
        'app/etc',
        'bin',
        'pub',
    ];

    public function __construct(
        Printing $printer
    ) {
        $this->printer = $printer;
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    public function tip(): string
    {
        return __('从 Git 仓库恢复框架核心文件');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:update:restore',
            __('从 Git 仓库恢复框架核心文件'),
            [
                '-b, --branch=<分支名>' => '指定分支（默认：dev）',
                '--hard' => '强制覆盖所有更改（git reset --hard）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '紧急恢复' => '当核心文件损坏时使用此命令恢复',
                'Git 依赖' => '需要当前目录是 Git 仓库',
            ],
            [
                '恢复当前分支' => 'php bin/w update:restore',
                '恢复到指定分支' => 'php bin/w update:restore -b dev',
                '强制恢复' => 'php bin/w update:restore --hard',
            ],
            'php bin/w update:restore -b <分支名>'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note('');
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->setup(__('框架核心文件恢复'));
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');

        // 检查是否在 Git 仓库中
        $this->printer->setup(__('步骤 1/4：检查 Git 仓库...'));
        $this->checkGitRepo();

        // 获取分支
        $branch = $args['branch'] ?? $args['b'] ?? 'dev';
        $this->printer->note(__('目标分支：%{1}', [$branch]));

        // 强制恢复选项
        $hard = isset($args['hard']);

        // 拉取最新代码
        $this->printer->setup(__('步骤 2/4：拉取最新代码...'));
        $this->pullLatest($branch, $hard);

        // 恢复核心文件
        $this->printer->setup(__('步骤 3/4：恢复核心文件...'));
        $this->restoreCoreFiles();

        // 清理缓存
        $this->printer->setup(__('步骤 4/4：清理缓存...'));
        $this->clearCache();

        $this->printer->note('');
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->success(__('✓✓✓ 核心文件恢复完成！✓✓✓'));
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');
    }

    private function checkGitRepo(): void
    {
        if (!is_dir(BP . '.git')) {
            $this->printer->error(__('错误：当前目录不是 Git 仓库'));
            exit(1);
        }
        $this->printer->success(__('✓ 检测到 Git 仓库'));
    }

    private function pullLatest(string $branch, bool $hard): void
    {
        $commands = [
            'git fetch origin',
            "git checkout {$branch}",
            'git pull origin ' . $branch,
        ];

        if ($hard) {
            $this->printer->warning(__('⚠ 将强制覆盖所有更改'));
            $commands[] = 'git reset --hard origin/' . $branch;
        }

        foreach ($commands as $cmd) {
            $this->printer->note(__('执行: %{1}', [$cmd]));
            exec($cmd, $output, $returnCode);
            
            if ($returnCode !== 0 && strpos($cmd, 'git checkout') === false) {
                $this->printer->warning(__('命令执行返回码: %{1}', [$returnCode]));
            }
        }

        $this->printer->success(__('✓ 代码拉取完成'));
    }

    private function restoreCoreFiles(): void
    {
        $restored = 0;
        
        foreach ($this->corePaths as $path) {
            $fullPath = BP . $path;
            
            if (!file_exists($fullPath)) {
                $this->printer->note(__('恢复: %{1}', [$path]));
                exec("git checkout HEAD -- " . escapeshellarg($path), $output, $returnCode);
                
                if ($returnCode === 0) {
                    $this->printer->success(__('✓ %{1}', [$path]));
                    $restored++;
                } else {
                    $this->printer->warning(__('⚠ %{1} 恢复失败', [$path]));
                }
            } else {
                $this->printer->note(__('✓ %{1} 已存在', [$path]));
            }
        }

        $this->printer->note('');
        $this->printer->success(__('✓ 共恢复 %{1} 个路径', [$restored]));
    }

    private function clearCache(): void
    {
        $cacheDirs = [
            BP . 'var/cache',
            BP . 'generated/code',
        ];

        foreach ($cacheDirs as $cacheDir) {
            if (is_dir($cacheDir)) {
                if ($this->isWindows) {
                    exec(sprintf('rmdir /s /q %s', escapeshellarg($cacheDir)));
                } else {
                    exec(sprintf('rm -rf %s', escapeshellarg($cacheDir)));
                }
                $this->printer->note(__('清理: %{1}', [$cacheDir]));
            }
        }

        $this->printer->success(__('✓ 缓存清理完成'));
    }
}

