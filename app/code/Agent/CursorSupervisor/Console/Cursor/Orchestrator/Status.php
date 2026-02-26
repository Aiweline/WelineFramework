<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Orchestrator;

use Agent\CursorBase\Service\TaskPoolService;
use Agent\CursorSupervisor\Service\WatchdogService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Printer;

/**
 * 查看编排器状态
 */
class Status extends CommandAbstract
{
    private TaskPoolService $taskPool;
    private WatchdogService $watchdog;
    
    public function __construct(
        TaskPoolService $taskPool,
        WatchdogService $watchdog
    ) {
        $this->taskPool = $taskPool;
        $this->watchdog = $watchdog;
    }
    
    public function execute(array $args = [], array $data = []): void
    {
        $this->taskPool->load();
        
        $stats = $this->taskPool->getStats();
        $masterStatus = $this->taskPool->getMasterStatus();
        $pool = $this->taskPool->getPool();
        
        $this->printer->success('📊 PHP Agent Orchestrator 状态');
        $this->printer->printing('');
        
        // Master Brain 状态
        $this->printer->printing('🧠 Master Brain:');
        $this->printer->printing("   状态: {$masterStatus['status']}");
        $this->printer->printing("   模型: {$masterStatus['model']}");
        if ($masterStatus['last_task']) {
            $this->printer->printing("   最后任务: {$masterStatus['last_task']}");
        }
        $this->printer->printing('');
        
        // 任务统计
        $this->printer->printing('📋 任务统计:');
        $this->printer->printing("   待执行: {$stats['todo']}");
        $this->printer->printing("   运行中: {$stats['running']}");
        $this->printer->printing("   阻塞中: {$stats['blocked']}");
        $this->printer->printing("   已完成: {$stats['completed']}");
        $this->printer->printing("   已失败: {$stats['failed']}");
        $this->printer->printing('');
        
        // 活跃任务
        $runningTasks = $this->taskPool->getRunningTasks();
        if (!empty($runningTasks)) {
            $this->printer->printing('🚀 运行中的任务:');
            foreach ($runningTasks as $agentId => $task) {
                $this->printer->printing("   - {$agentId}: {$task['description']}");
                $this->printer->printing("     文件: {$task['file']}");
            }
            $this->printer->printing('');
        }
        
        // 阻塞任务
        $blockedTasks = $this->taskPool->getBlockedTasks();
        if (!empty($blockedTasks)) {
            $this->printer->printing('⏳ 阻塞中的任务:');
            foreach ($blockedTasks as $agentId => $task) {
                $this->printer->printing("   - {$agentId}: 等待 {$task['dep']}");
            }
            $this->printer->printing('');
        }
        
        // 待执行任务
        $todoTasks = $this->taskPool->getTodoTasks();
        if (!empty($todoTasks)) {
            $this->printer->printing('📝 待执行任务:');
            foreach (array_slice($todoTasks, 0, 5, true) as $agentId => $task) {
                $this->printer->printing("   - {$agentId}: {$task['description']} [{$task['priority']}]");
            }
            if (count($todoTasks) > 5) {
                $this->printer->printing("   ... 还有 " . (count($todoTasks) - 5) . " 个任务");
            }
            $this->printer->printing('');
        }
        
        // 最近完成
        $completed = $pool['completed'] ?? [];
        if (!empty($completed)) {
            $this->printer->printing('✅ 最近完成:');
            foreach (array_slice($completed, -3, 3, true) as $agentId => $task) {
                $this->printer->printing("   - {$agentId}: {$task['description']}");
            }
            $this->printer->printing('');
        }
        
        // 失败任务
        $failed = $pool['failed'] ?? [];
        if (!empty($failed)) {
            $this->printer->error('❌ 失败任务:');
            foreach ($failed as $agentId => $task) {
                $this->printer->printing("   - {$agentId}: {$task['error']}");
            }
            $this->printer->printing('');
        }
    }
    
    public function tip(): string
    {
        return __('查看 PHP Agent Orchestrator 状态');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:orchestrator:status',
            '查看 PHP Agent Orchestrator 状态，包括任务池、Master Brain、运行中的 Agent',
            [],
            [],
            [
                '查看状态' => 'php bin/w cursor:orchestrator:status',
            ]
        );
    }
}
