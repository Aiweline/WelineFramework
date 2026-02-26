<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Git;

use Agent\CursorSupervisor\Service\GitCommitService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * 智能 Git 提交命令
 */
class Commit extends CommandAbstract
{
    private GitCommitService $gitService;
    
    public function __construct(GitCommitService $gitService)
    {
        $this->gitService = $gitService;
    }
    
    public function execute(array $args = [], array $data = []): void
    {
        $verbose = isset($args['v']) || isset($args['verbose']);
        $dryRun = isset($args['dry-run']) || isset($args['d']);
        $noAi = isset($args['no-ai']);
        $analyze = isset($args['analyze']) || isset($args['a']);
        $status = isset($args['status']) || isset($args['s']);
        
        $this->gitService->setVerbose($verbose);
        
        if ($status) {
            $this->showStatus();
            return;
        }
        
        if ($analyze) {
            $this->showAnalysis();
            return;
        }
        
        // 智能提交
        $this->smartCommit($dryRun, !$noAi);
    }
    
    /**
     * 显示 Git 状态
     */
    private function showStatus(): void
    {
        $status = $this->gitService->getStatus();
        
        if (isset($status['error'])) {
            $this->printer->error($status['error']);
            return;
        }
        
        $this->printer->success('📊 Git 状态');
        $this->printer->printing('');
        
        $total = count($status['staged']) + count($status['modified']) + 
                 count($status['untracked']) + count($status['deleted']);
        
        if ($total === 0) {
            $this->printer->note('工作区干净，没有待提交的更改');
            return;
        }
        
        if (!empty($status['staged'])) {
            $this->printer->printing('暂存区 (staged):');
            foreach ($status['staged'] as $item) {
                $this->printer->printing("   ✅ [{$item['status']}] {$item['file']}");
            }
            $this->printer->printing('');
        }
        
        if (!empty($status['modified'])) {
            $this->printer->printing('已修改 (modified):');
            foreach ($status['modified'] as $file) {
                $this->printer->printing("   📝 {$file}");
            }
            $this->printer->printing('');
        }
        
        if (!empty($status['untracked'])) {
            $this->printer->printing('未跟踪 (untracked):');
            foreach ($status['untracked'] as $file) {
                $this->printer->printing("   ➕ {$file}");
            }
            $this->printer->printing('');
        }
        
        if (!empty($status['deleted'])) {
            $this->printer->printing('已删除 (deleted):');
            foreach ($status['deleted'] as $file) {
                $this->printer->printing("   ❌ {$file}");
            }
            $this->printer->printing('');
        }
        
        $this->printer->printing("共 {$total} 个文件待处理");
    }
    
    /**
     * 显示分组分析
     */
    private function showAnalysis(): void
    {
        $this->printer->printing('🔍 分析变更...');
        $this->printer->printing('');
        
        $analysis = $this->gitService->analyzeChanges();
        
        if (isset($analysis['error'])) {
            $this->printer->error($analysis['error']);
            return;
        }
        
        if (empty($analysis['groups'])) {
            $this->printer->note('没有待提交的更改');
            return;
        }
        
        $this->printer->success("📋 变更分析 ({$analysis['total_files']} 个文件)");
        $this->printer->printing('');
        
        $groupNum = 1;
        foreach ($analysis['groups'] as $module => $group) {
            $fileCount = count($group['files']);
            $types = implode(', ', $group['types']);
            $action = $group['analysis']['action'] ?? 'update';
            $actionIcon = match ($action) {
                'add' => '➕',
                'remove' => '➖',
                default => '📝',
            };
            
            $this->printer->printing("┌─ 分组 {$groupNum}: {$module}");
            $this->printer->printing("│  动作: {$actionIcon} {$action}");
            $this->printer->printing("│  类型: {$types}");
            $this->printer->printing("│  文件: {$fileCount} 个");
            
            foreach ($group['files'] as $file) {
                $status = $file['status'];
                $statusIcon = match ($status) {
                    'A' => '➕',
                    'M' => '📝',
                    'D' => '❌',
                    default => '❓',
                };
                $this->printer->printing("│    {$statusIcon} {$file['file']}");
            }
            
            // 生成建议的提交信息
            $message = $this->gitService->generateCommitMessage($group, false);
            $this->printer->printing("│  建议: {$message}");
            $this->printer->printing("└─");
            $this->printer->printing('');
            
            $groupNum++;
        }
        
        $this->printer->note("提交: php bin/w cursor:git:commit");
        $this->printer->note("预览: php bin/w cursor:git:commit --dry-run");
    }
    
    /**
     * 智能提交
     */
    private function smartCommit(bool $dryRun, bool $useAi): void
    {
        if ($dryRun) {
            $this->printer->note('🔍 预览模式（不会实际提交）');
        } else {
            $this->printer->success('🚀 开始智能提交');
        }
        $this->printer->printing('');
        
        // 设置进度回调，实时输出进度
        $this->gitService->setProgressCallback(function (string $message, string $type) {
            match ($type) {
                'success' => $this->printer->success($message),
                'error' => $this->printer->error($message),
                'note' => $this->printer->note($message),
                default => $this->printer->printing($message),
            };
        });
        
        $result = $this->gitService->smartCommit($dryRun, $useAi);
        
        if (isset($result['error'])) {
            $this->printer->error($result['error']);
            return;
        }
        
        if ($result['message'] === 'Nothing to commit') {
            $this->printer->note('没有待提交的更改');
            return;
        }
        
        if ($dryRun) {
            $this->printer->printing('');
            $this->printer->note('确认提交: php bin/w cursor:git:commit');
        } else {
            $this->printer->printing('');
            $this->showRecentCommits();
        }
    }
    
    /**
     * 显示最近提交
     */
    private function showRecentCommits(): void
    {
        $commits = $this->gitService->getRecentCommits(5);
        
        if (empty($commits)) {
            return;
        }
        
        $this->printer->printing('📜 最近提交:');
        foreach ($commits as $commit) {
            $this->printer->printing("   {$commit['hash']} {$commit['message']}");
        }
    }
    
    public function tip(): string
    {
        return __('智能分析并提交 Git 更改');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:git:commit',
            '智能分析未提交的文件变更，按模块分组并生成清晰的提交信息',
            [
                '-s, --status' => '显示 Git 状态',
                '-a, --analyze' => '分析变更并显示建议的提交分组',
                '-d, --dry-run' => '预览模式，不实际提交',
                '--no-ai' => '不使用 AI 生成提交信息',
                '-v, --verbose' => '详细输出',
            ],
            [],
            [
                '查看状态' => 'php bin/w cursor:git:commit --status',
                '分析变更' => 'php bin/w cursor:git:commit --analyze',
                '预览提交' => 'php bin/w cursor:git:commit --dry-run',
                '智能提交' => 'php bin/w cursor:git:commit',
                '简单提交' => 'php bin/w cursor:git:commit --no-ai',
            ]
        );
    }
}
