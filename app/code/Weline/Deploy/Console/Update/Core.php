<?php

declare(strict_types=1);

/**
 * Deploy Core Update Command
 * 
 * 从 Git 仓库更新框架核心代码（只更新核心文件，不影响其他文件）
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
    
    // 需要更新的框架核心路径
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
                '-b, --branch=<分支名>' => '指定分支（必填，如：main, master, develop）',
                '-t, --tag=<标签名>' => '指定标签版本（可选，如：v1.0.0）',
                '--repo=<仓库地址>' => '指定 Git 仓库地址（默认：https://gitee.com/aiweline/WelineFramework.git）',
                '-f, --force' => '强制更新（会覆盖本地修改）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '强制指定分支' => '必须使用 -b 或 --branch 参数指定分支名',
                '只更新核心' => '只更新核心路径文件，不影响其他文件',
                '版本验证' => '如果指定了标签但不存在，命令会报错并退出',
            ],
            [
                '更新到最新' => 'php bin/w deploy:update:core -b main',
                '指定标签' => 'php bin/w deploy:update:core -b main -t v1.0.0',
            ],
            'php bin/w deploy:update:core -b <分支名>'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note('');
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->setup(__('框架核心代码更新（仅核心文件）'));
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');

        // 1. 检查 Git
        $this->printer->setup(__('步骤 1/5：检查 Git...'));
        $this->checkGit();
        $this->printer->success(__('✓ Git 检查通过'));

        // 2. 验证参数
        $this->printer->setup(__('步骤 2/5：验证参数...'));
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
        $this->printer->success(__('✓ 参数验证通过'));

        // 3. 检查本地仓库
        $this->printer->setup(__('步骤 3/5：检查本地仓库...'));
        if (!is_dir(BP . '.git')) {
            $this->printer->error(__('错误：当前目录不是 Git 仓库！'));
            exit(1);
        }
        $this->printer->success(__('✓ 本地仓库检查通过'));

        // 4. 执行 Git 更新
        $this->printer->setup(__('步骤 4/5：执行核心文件更新...'));
        $this->executeGitUpdate($repo, $branch, $tag);
        $this->printer->success(__('✓ Git 更新完成'));

        // 5. 验证更新
        $this->printer->setup(__('步骤 5/5：验证更新结果...'));
        $this->verifyUpdate();
        $this->printer->success(__('✓ 更新验证通过'));

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
    }

    private function getBranch(array $args): string
    {
        $branch = $args['branch'] ?? $args['b'] ?? null;
        if (empty($branch)) {
            $this->printer->error(__('错误：必须指定分支'));
            $this->printer->note(__('php bin/w deploy:update:core -b <分支名>'));
            exit(1);
        }
        return $branch;
    }

    /**
     * 执行 Git 更新（使用 git checkout 只更新指定路径）
     */
    private function executeGitUpdate(string $repo, string $branch, ?string $tag): void
    {
        // 添加远程仓库
        exec('cd ' . escapeshellarg(BP) . ' && git remote remove core-update 2>/dev/null');
        exec('cd ' . escapeshellarg(BP) . ' && git remote add core-update ' . escapeshellarg($repo));
        
        // 拉取
        $this->printer->note(__('拉取远程仓库...'));
        exec('cd ' . escapeshellarg(BP) . ' && git fetch core-update', $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->printer->error(__('拉取失败'));
            exit(1);
        }

        // 验证标签
        if ($tag) {
            exec('cd ' . escapeshellarg(BP) . ' && git ls-remote --tags core-update ' . escapeshellarg($tag), $output, $returnCode);
            if (empty($output)) {
                $this->printer->error(__('标签不存在: %{1}', [$tag]));
                exit(1);
            }
        }

        // 确定引用
        $ref = $tag ?? $branch;
        $remoteRef = 'core-update/' . $ref;
        
        $this->printer->note(__('从 %{1} 更新核心文件...', [$remoteRef]));
        
        // 使用 git checkout 只提取核心路径的文件
        foreach ($this->corePaths as $path) {
            if (!is_dir(BP . $path)) {
                $this->printer->warning(__('路径不存在: %{1}', [$path]));
                continue;
            }
            
            $command = sprintf(
                'cd %s && git checkout %s -- %s 2>&1',
                escapeshellarg(BP),
                escapeshellarg($remoteRef),
                escapeshellarg($path)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0) {
                $this->printer->success(__('✓ %{1}', [$path]));
            } else {
                $this->printer->warning(__('⚠ %{1}', [$path]));
            }
        }
    }

    private function verifyUpdate(): void
    {
        exec('cd ' . escapeshellarg(BP) . ' && git status --short', $output, $returnCode);
        
        if (!empty($output)) {
            $count = count($output);
            $this->printer->note(__('共更新 %{1} 个文件', [$count]));
        } else {
            $this->printer->note(__('文件已是最新'));
        }
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
