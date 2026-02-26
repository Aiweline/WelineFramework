<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Plan;

use Agent\CursorSupervisor\Service\PlanExecutorService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * 启动计划命令
 */
class Start extends CommandAbstract
{
    private PlanExecutorService $planExecutor;
    
    public function __construct(PlanExecutorService $planExecutor)
    {
        $this->planExecutor = $planExecutor;
    }
    
    public function execute(array $args = [], array $data = []): void
    {
        $verbose = isset($args['v']) || isset($args['verbose']);
        $this->planExecutor->setVerbose($verbose);
        
        // 提取计划名称
        $planName = null;
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-') && !str_contains((string)$arg, ':')) {
                $planName = $arg;
                break;
            }
        }
        foreach ($data as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-') && !str_contains((string)$arg, ':')) {
                $planName = $arg;
                break;
            }
        }
        
        if (!$planName) {
            $this->showPendingPlans();
            return;
        }
        
        $result = $this->planExecutor->startPlan($planName);
        
        if ($result['success']) {
            $this->printer->success($result['message']);
            $this->printer->printing('');
            $this->printer->note("现在可以执行: php bin/w cursor:plan:execute {$planName}");
        } else {
            $this->printer->error($result['error']);
        }
    }
    
    /**
     * 显示待处理计划
     */
    private function showPendingPlans(): void
    {
        $pendingPlans = $this->planExecutor->getPendingPlans();
        $runningPlans = $this->planExecutor->getRunningPlans();
        $summary = $this->planExecutor->getStatusSummary();
        
        $this->printer->success('📋 计划状态概览');
        $this->printer->printing('');
        $this->printer->printing("   总计划: {$summary['total']}");
        $this->printer->printing("   待启动: {$summary['pending']} (pending)");
        $this->printer->printing("   已就绪: {$summary['ready']} (ready)");
        $this->printer->printing("   进行中: {$summary['running']} (running)");
        $this->printer->printing("   已暂停: {$summary['paused']} (paused)");
        $this->printer->printing("   已完成: {$summary['done']} (done)");
        $this->printer->printing('');
        
        if (!empty($pendingPlans)) {
            $this->printer->note('待启动的计划:');
            foreach ($pendingPlans as $name => $plan) {
                $status = $plan['status'];
                $icon = $status === 'ready' ? '🟢' : '🟡';
                $this->printer->printing("   {$icon} {$name} [{$status}] - {$plan['title']}");
            }
            $this->printer->printing('');
            $this->printer->printing('启动计划: php bin/w cursor:plan:start {name}');
        }
        
        if (!empty($runningPlans)) {
            $this->printer->printing('');
            $this->printer->note('进行中的计划:');
            foreach ($runningPlans as $name => $plan) {
                $this->printer->printing("   🔵 {$name} [running] - {$plan['title']}");
            }
        }
        
        if (empty($pendingPlans) && empty($runningPlans)) {
            $this->printer->note('没有待处理的计划');
            $this->printer->printing('创建计划: dev/ai/plans/{name}.plan.md');
        }
    }
    
    public function tip(): string
    {
        return __('启动计划（将状态改为 running）');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:plan:start',
            '启动指定的开发计划，将状态从 pending/ready 改为 running',
            [
                '{plan_name}' => '计划名称（不带 .plan.md 后缀）',
                '-v, --verbose' => '详细输出模式',
            ],
            [],
            [
                '查看计划' => 'php bin/w cursor:plan:start',
                '启动计划' => 'php bin/w cursor:plan:start my-feature',
            ]
        );
    }
}
