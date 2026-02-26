<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Plan;

use Agent\CursorSupervisor\Service\PlanExecutorService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * 列出计划命令
 */
class Lists extends CommandAbstract
{
    private PlanExecutorService $executor;
    
    public function __construct(PlanExecutorService $executor)
    {
        $this->executor = $executor;
    }
    
    public function execute(array $args = [], array $data = []): void
    {
        $plans = $this->executor->listPlans();
        $summary = $this->executor->getStatusSummary();
        
        if (empty($plans)) {
            $this->printer->note('暂无可用计划');
            $this->printer->printing('');
            $this->printer->printing('创建计划文件: dev/ai/plans/your-plan.plan.md');
            $this->printer->printing('');
            $this->showTemplate();
            return;
        }
        
        // 显示待处理提醒
        $pendingCount = $summary['pending'] + $summary['ready'];
        if ($pendingCount > 0) {
            $this->showPendingReminder($pendingCount);
        }
        
        $this->printer->success('📋 计划列表');
        $this->printer->printing('');
        $this->printer->printing("   状态统计: {$summary['total']} 总计 | {$summary['running']} 进行中 | {$pendingCount} 待启动 | {$summary['done']} 已完成");
        $this->printer->printing('');
        
        // 按状态分组显示
        $grouped = [
            'running' => [],
            'ready' => [],
            'pending' => [],
            'paused' => [],
            'done' => [],
            'cancelled' => [],
        ];
        
        foreach ($plans as $name => $plan) {
            $status = $plan['status'];
            if (isset($grouped[$status])) {
                $grouped[$status][$name] = $plan;
            }
        }
        
        foreach ($grouped as $status => $statusPlans) {
            if (empty($statusPlans)) {
                continue;
            }
            
            $statusLabel = $this->getStatusLabel($status);
            $this->printer->printing("   ─── {$statusLabel} ───");
            
            foreach ($statusPlans as $name => $plan) {
                $icon = $this->getStatusIcon($status);
                $tasks = $plan['tasks'];
                $progress = $tasks['total'] > 0 
                    ? round($tasks['completed'] / $tasks['total'] * 100) 
                    : 0;
                
                $this->printer->printing("   {$icon} {$name}: {$plan['title']}");
                $this->printer->printing("      优先级: {$plan['priority']} | 任务: {$tasks['completed']}/{$tasks['total']} ({$progress}%)");
            }
            $this->printer->printing('');
        }
        
        $this->printer->printing('启动计划: php bin/w cursor:plan:start {plan_name}');
        $this->printer->printing('执行计划: php bin/w cursor:plan:execute {plan_name}');
    }
    
    /**
     * 显示待处理计划提醒
     */
    private function showPendingReminder(int $count): void
    {
        echo "\n";
        echo "┌────────────────────────────────────────────────────────────┐\n";
        echo "│  ⚠️  有 {$count} 个计划待启动                                      │\n";
        echo "│                                                            │\n";
        echo "│  这些计划不会被 Watchdog 处理，需要先启动：                 │\n";
        echo "│  1. 编辑 plan.md 将状态改为 'running'                       │\n";
        echo "│  2. 或执行: php bin/w cursor:plan:start {name}              │\n";
        echo "└────────────────────────────────────────────────────────────┘\n";
        echo "\n";
    }
    
    /**
     * 获取状态标签
     */
    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'running' => '🔵 进行中',
            'ready' => '🟢 已就绪',
            'pending' => '🟡 待启动',
            'paused' => '⏸️ 已暂停',
            'done' => '✅ 已完成',
            'cancelled' => '❌ 已取消',
            default => '❓ 未知',
        };
    }
    
    /**
     * 显示计划模板
     */
    private function showTemplate(): void
    {
        $template = <<<'TEMPLATE'
计划文件模板:

```markdown
# 计划标题

## 元信息
- **ID**: plan_001
- **优先级**: high
- **状态**: pending

## 需求描述

描述要实现的功能...

## 任务分解

- [ ] 任务1 @Agent:DB @File:Model/User.php [P1]
- [ ] 任务2 @Agent:Logic @File:Service/Auth.php @Dep:Agent_DB_001 [P2]

## 测试要求

- [ ] 单元测试: Test/AuthTest.php
```
TEMPLATE;
        
        $this->printer->printing($template);
    }
    
    /**
     * 获取状态图标
     */
    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            'pending' => '🟡',
            'ready' => '🟢',
            'running' => '🔵',
            'paused' => '⏸️',
            'done' => '✅',
            'cancelled' => '❌',
            default => '❓',
        };
    }
    
    public function tip(): string
    {
        return __('列出所有开发计划');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:plan:list',
            '列出 dev/ai/plans/ 目录下的所有计划文件',
            [],
            [],
            [
                '列出计划' => 'php bin/w cursor:plan:list',
            ]
        );
    }
}
