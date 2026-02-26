<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Plan;

use Agent\CursorBase\Service\TaskPoolService;
use Agent\CursorSupervisor\Service\PlanExecutorService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * 查看计划状态命令
 */
class Status extends CommandAbstract
{
    private PlanExecutorService $executor;
    private TaskPoolService $taskPool;
    
    public function __construct(
        PlanExecutorService $executor,
        TaskPoolService $taskPool
    ) {
        $this->executor = $executor;
        $this->taskPool = $taskPool;
    }
    
    public function execute(array $args = [], array $data = []): void
    {
        // 提取位置参数（排除命令本身）
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-') && !str_contains((string)$arg, ':')) {
                $positionalArgs[] = $arg;
            }
        }
        
        // 也检查 $data
        if (empty($positionalArgs)) {
            foreach ($data as $key => $value) {
                if (is_int($key) && !str_starts_with((string)$value, '-') && !str_contains((string)$value, ':')) {
                    $positionalArgs[] = $value;
                }
            }
        }
        
        $planName = $positionalArgs[0] ?? null;
        
        if ($planName) {
            $this->showPlanStatus($planName);
        } else {
            $this->showCurrentStatus();
        }
    }
    
    /**
     * 显示指定计划状态
     */
    private function showPlanStatus(string $planName): void
    {
        $plan = $this->executor->getPlan($planName);
        
        if (!$plan) {
            $this->printer->error("计划 '{$planName}' 不存在");
            return;
        }
        
        $this->printer->success("📋 计划状态: {$plan['title']}");
        $this->printer->printing('');
        
        // 基本信息
        $this->printer->printing("   ID: {$plan['id']}");
        $this->printer->printing("   状态: {$plan['status']}");
        $this->printer->printing("   优先级: {$plan['priority']}");
        $this->printer->printing("   文件: {$plan['file']}");
        $this->printer->printing('');
        
        // 任务列表
        if (!empty($plan['tasks'])) {
            $this->printer->printing('📝 任务列表:');
            foreach ($plan['tasks'] as $task) {
                $status = $this->getTaskStatus($task['agent_id']);
                $icon = $this->getStatusIcon($status);
                $this->printer->printing("   {$icon} {$task['agent_id']}: {$task['description']}");
                if ($task['file']) {
                    $this->printer->printing("      文件: {$task['file']}");
                }
            }
            $this->printer->printing('');
        }
        
        // 测试要求
        if (!empty($plan['tests'])) {
            $this->printer->printing('🧪 测试要求:');
            foreach ($plan['tests'] as $test) {
                $this->printer->printing("   - {$test}");
            }
            $this->printer->printing('');
        }
    }
    
    /**
     * 显示当前执行状态
     */
    private function showCurrentStatus(): void
    {
        $this->taskPool->load();
        $pool = $this->taskPool->getPool();
        $stats = $this->taskPool->getStats();
        
        $currentPlan = $pool['current_plan'] ?? null;
        
        $this->printer->success('📊 当前状态');
        $this->printer->printing('');
        
        if ($currentPlan) {
            $plan = $this->executor->getPlan($currentPlan);
            $this->printer->printing("   当前计划: {$currentPlan}");
            if ($plan) {
                $this->printer->printing("   计划标题: {$plan['title']}");
            }
        } else {
            $this->printer->printing('   当前计划: (无)');
        }
        
        $this->printer->printing('');
        
        // Master Brain 状态
        $master = $pool['master'] ?? [];
        $this->printer->printing('🧠 Master Brain:');
        $this->printer->printing("   状态: {$master['status']}");
        $this->printer->printing("   模型: {$master['model']}");
        $this->printer->printing('');
        
        // 任务统计
        $this->printer->printing('📋 任务统计:');
        $this->printer->printing("   待执行: {$stats['todo']}");
        $this->printer->printing("   运行中: {$stats['running']}");
        $this->printer->printing("   阻塞中: {$stats['blocked']}");
        $this->printer->printing("   已完成: {$stats['completed']}");
        $this->printer->printing("   已失败: {$stats['failed']}");
        $this->printer->printing('');
        
        // 运行中的任务
        $running = $this->taskPool->getRunningTasks();
        if (!empty($running)) {
            $this->printer->printing('🔄 运行中:');
            foreach ($running as $agentId => $task) {
                $this->printer->printing("   - {$agentId}: {$task['description']}");
            }
            $this->printer->printing('');
        }
        
        // 失败的任务
        if ($stats['failed'] > 0) {
            $failed = $pool['failed'] ?? [];
            $this->printer->error('❌ 失败任务:');
            foreach ($failed as $agentId => $task) {
                $error = $task['error'] ?? 'Unknown error';
                $this->printer->printing("   - {$agentId}: {$error}");
            }
            $this->printer->printing('');
        }
    }
    
    /**
     * 获取任务状态
     */
    private function getTaskStatus(string $agentId): string
    {
        $task = $this->taskPool->getTask($agentId);
        
        if (!$task) {
            // 检查是否已完成
            $pool = $this->taskPool->getPool();
            if (isset($pool['completed'][$agentId])) {
                return 'done';
            }
            if (isset($pool['failed'][$agentId])) {
                return 'failed';
            }
            return 'pending';
        }
        
        return $task['status'];
    }
    
    /**
     * 获取状态图标
     */
    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            'todo', 'pending' => '⏳',
            'running' => '🔄',
            'blocked' => '🚫',
            'done' => '✅',
            'failed' => '❌',
            default => '❓',
        };
    }
    
    public function tip(): string
    {
        return __('查看计划或当前执行状态');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:plan:status',
            '查看指定计划的详细状态，或当前执行状态',
            [
                '{plan_name}' => '计划名称（可选，不指定则显示当前状态）',
            ],
            [],
            [
                '当前状态' => 'php bin/w cursor:plan:status',
                '计划状态' => 'php bin/w cursor:plan:status my-feature',
            ]
        );
    }
}
