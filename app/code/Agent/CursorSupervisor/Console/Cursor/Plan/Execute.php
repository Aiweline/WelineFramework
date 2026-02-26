<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Plan;

use Agent\CursorBase\Service\TaskPoolService;
use Agent\CursorSupervisor\Service\PlanExecutorService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * 执行计划命令
 */
class Execute extends CommandAbstract
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
        $verbose = isset($args['v']) || isset($args['verbose']);
        $runTests = !isset($args['no-test']);
        $once = isset($args['once']);
        
        $this->executor->setVerbose($verbose)->setRunTests($runTests);
        
        if (!$planName) {
            $this->printer->error('请指定计划名称');
            $this->printer->printing('');
            $this->showAvailablePlans();
            return;
        }
        
        // 获取计划
        $plan = $this->executor->getPlan($planName);
        
        if (!$plan) {
            $this->printer->error("计划 '{$planName}' 不存在");
            $this->printer->printing('');
            $this->showAvailablePlans();
            return;
        }
        
        // 显示计划信息
        $this->showPlanInfo($plan);
        
        // 检查计划状态
        if (!in_array($plan['status'], ['running', 'ready'])) {
            $this->showStatusWarning($plan);
            return;
        }
        
        if ($once) {
            // 单次检查模式
            $this->printer->note('单次检查模式');
            $result = $this->executor->checkAndDispatch($planName);
            
            if ($result['success']) {
                if (isset($result['tasks_loaded'])) {
                    $this->printer->success("已加载 {$result['tasks_loaded']} 个任务");
                } else {
                    $this->showCheckResult($result);
                }
            } else {
                $this->printer->error($result['error'] ?? '执行失败');
                if (isset($result['hint'])) {
                    $this->printer->note($result['hint']);
                }
            }
        } else {
            // 持续执行模式
            $this->printer->note('按 Ctrl+C 停止');
            $this->printer->printing('');
            
            $result = $this->executor->execute($planName);
            
            if (!$result['success']) {
                $this->printer->error($result['error'] ?? '执行失败');
                if (isset($result['hint'])) {
                    $this->printer->note($result['hint']);
                }
            }
        }
    }
    
    /**
     * 显示状态警告
     */
    private function showStatusWarning(array $plan): void
    {
        $status = $plan['status'];
        $name = $plan['name'];
        
        echo "\n";
        echo "┌────────────────────────────────────────────────────────────┐\n";
        echo "│  ⚠️  计划状态为 '{$status}'，无法执行                         │\n";
        echo "├────────────────────────────────────────────────────────────┤\n";
        echo "│                                                            │\n";
        echo "│  Watchdog 只会处理状态为 'running' 的计划。                 │\n";
        echo "│                                                            │\n";
        echo "│  启动方式：                                                 │\n";
        echo "│  1. 编辑 plan.md，将 **状态**: {$status} 改为 **状态**: running  │\n";
        echo "│  2. 或执行: php bin/w cursor:plan:start {$name}              │\n";
        echo "│                                                            │\n";
        echo "└────────────────────────────────────────────────────────────┘\n";
        echo "\n";
        
        $this->printer->note("启动计划后再执行: php bin/w cursor:plan:execute {$name}");
    }
    
    /**
     * 显示可用计划
     */
    private function showAvailablePlans(): void
    {
        $plans = $this->executor->listPlans();
        
        if (empty($plans)) {
            $this->printer->note('暂无可用计划');
            $this->printer->printing('');
            $this->printer->printing('创建计划: dev/ai/plans/your-plan.plan.md');
            return;
        }
        
        $this->printer->success('📋 可用计划:');
        $this->printer->printing('');
        
        foreach ($plans as $name => $plan) {
            $status = $this->getStatusIcon($plan['status']);
            $tasks = $plan['tasks'];
            $this->printer->printing("   {$status} {$name}: {$plan['title']}");
            $this->printer->printing("      任务: {$tasks['completed']}/{$tasks['total']} | 优先级: {$plan['priority']}");
        }
        
        $this->printer->printing('');
        $this->printer->printing('执行计划: php bin/w cursor:plan:execute {plan_name}');
    }
    
    /**
     * 显示计划信息
     */
    private function showPlanInfo(array $plan): void
    {
        $this->printer->success("🚀 执行计划: {$plan['title']}");
        $this->printer->printing('');
        $this->printer->printing("   ID: {$plan['id']}");
        $this->printer->printing("   状态: {$plan['status']}");
        $this->printer->printing("   优先级: {$plan['priority']}");
        
        $tasks = $plan['tasks'];
        $this->printer->printing("   任务数: " . count($tasks));
        
        if (!empty($plan['tests'])) {
            $this->printer->printing("   测试要求: " . count($plan['tests']) . " 项");
        }
        
        $this->printer->printing('');
    }
    
    /**
     * 显示检查结果
     */
    private function showCheckResult(array $result): void
    {
        $stats = $result['stats'] ?? [];
        
        $this->printer->printing('📊 当前状态:');
        $this->printer->printing("   待执行: {$stats['todo']}");
        $this->printer->printing("   运行中: {$stats['running']}");
        $this->printer->printing("   已完成: {$stats['completed']}");
        $this->printer->printing("   已失败: {$stats['failed']}");
        
        $checkResult = $result['result'] ?? [];
        
        if (!empty($checkResult['completed'])) {
            $this->printer->success('✅ 本次完成: ' . implode(', ', $checkResult['completed']));
        }
        
        if (!empty($checkResult['failed'])) {
            $this->printer->error('❌ 本次失败: ' . implode(', ', $checkResult['failed']));
        }
        
        if ($checkResult['dispatched'] ?? 0 > 0) {
            $this->printer->note("🚀 已派发: {$checkResult['dispatched']} 个任务");
        }
        
        if ($result['completed'] ?? false) {
            $this->printer->success('🎉 计划已全部完成！');
        }
    }
    
    /**
     * 获取状态图标
     */
    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            'pending' => '⏳',
            'running' => '🔄',
            'completed' => '✅',
            'cancelled' => '❌',
            default => '❓',
        };
    }
    
    public function tip(): string
    {
        return __('执行指定的开发计划（自监督，带测试）');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:plan:execute',
            '执行指定的开发计划。计划文件位于 dev/ai/plans/*.plan.md',
            [
                '{plan_name}' => '计划名称（不含 .plan.md 后缀）',
                '-v, --verbose' => '详细输出模式',
                '--no-test' => '禁用自动测试',
                '--once' => '单次检查模式（不阻塞）',
            ],
            [],
            [
                '执行计划' => 'php bin/w cursor:plan:execute my-feature',
                '详细模式' => 'php bin/w cursor:plan:execute my-feature -v',
                '禁用测试' => 'php bin/w cursor:plan:execute my-feature --no-test',
                '单次检查' => 'php bin/w cursor:plan:execute my-feature --once',
            ]
        );
    }
}
