<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Orchestrator;

use Agent\CursorBase\Service\TaskPoolService;
use Agent\CursorSupervisor\Service\MasterBrainService;
use Agent\CursorSupervisor\Service\CursorDriverService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Printer;

/**
 * 任务管理命令
 */
class Task extends CommandAbstract
{
    private MasterBrainService $masterBrain;
    private TaskPoolService $taskPool;
    private CursorDriverService $driver;
    
    public function __construct(
        MasterBrainService $masterBrain,
        TaskPoolService $taskPool,
        CursorDriverService $driver
    ) {
        $this->masterBrain = $masterBrain;
        $this->taskPool = $taskPool;
        $this->driver = $driver;
    }
    
    public function execute(array $args = [], array $data = []): void
    {
        $action = $args[0] ?? $data[0] ?? 'list';
        
        switch ($action) {
            case 'add':
                $this->addTask($args, $data);
                break;
                
            case 'process':
                $this->processRequirement($args, $data);
                break;
                
            case 'dispatch':
                $this->dispatchTasks($args, $data);
                break;
                
            case 'clear':
                $this->clearTasks();
                break;
                
            case 'reset':
                $this->resetFailed();
                break;
                
            case 'list':
            default:
                $this->listTasks();
                break;
        }
    }
    
    /**
     * 添加单个任务
     */
    private function addTask(array $args, array $data): void
    {
        $agentId = $args['agent'] ?? $args['a'] ?? $data['agent'] ?? null;
        $file = $args['file'] ?? $args['f'] ?? $data['file'] ?? null;
        $desc = $args['desc'] ?? $args['d'] ?? $data['desc'] ?? null;
        $dep = $args['dep'] ?? $data['dep'] ?? null;
        $priority = $args['priority'] ?? $args['p'] ?? $data['priority'] ?? 'normal';
        
        if (!$agentId || !$file || !$desc) {
            $this->printer->error('缺少必要参数: --agent, --file, --desc');
            return;
        }
        
        $this->taskPool->load();
        $this->taskPool->addTask($agentId, $file, $desc, $dep, $priority);
        $this->taskPool->save();
        
        $this->printer->success("✅ 任务已添加: {$agentId}");
    }
    
    /**
     * 处理需求（使用 AI 拆解）
     */
    private function processRequirement(array $args, array $data): void
    {
        $requirement = $args['req'] ?? $args['r'] ?? $data['req'] ?? null;
        
        if (!$requirement) {
            // 尝试从 plan.md 读取
            $planFile = BP . 'doc/plan.md';
            if (file_exists($planFile)) {
                $content = file_get_contents($planFile);
                if (preg_match('/^- \[ \] (.+)$/m', $content, $match)) {
                    $requirement = trim($match[1]);
                }
            }
        }
        
        if (!$requirement) {
            $this->printer->error('缺少需求参数: --req "你的需求描述"');
            return;
        }
        
        $this->printer->printing("🧠 开始处理需求: {$requirement}");
        $this->printer->printing('');
        
        $model = $args['model'] ?? $args['m'] ?? 'deepseek';
        $this->masterBrain->setModel($model)->setVerbose(true);
        
        $tasks = $this->masterBrain->processRequirement($requirement);
        
        $this->printer->success("✅ 已拆解为 " . count($tasks) . " 个子任务");
        
        foreach ($tasks as $task) {
            $this->printer->printing("   - {$task['agent_id']}: {$task['description']}");
        }
    }
    
    /**
     * 派发任务
     */
    private function dispatchTasks(array $args, array $data): void
    {
        $maxParallel = (int) ($args['parallel'] ?? $args['p'] ?? 3);
        $autoTrigger = !isset($args['no-auto-trigger']);
        
        $this->driver
            ->setMaxParallelAgents($maxParallel)
            ->setAutoTrigger($autoTrigger)
            ->setVerbose(true);
        
        $dispatched = $this->driver->drive();
        
        if ($dispatched > 0) {
            $this->printer->success("🚀 已派发 {$dispatched} 个任务");
        } else {
            $this->printer->note('没有可派发的任务');
        }
    }
    
    /**
     * 清空任务池
     */
    private function clearTasks(): void
    {
        $this->taskPool->load();
        $this->taskPool->clear();
        $this->taskPool->save();
        
        $this->printer->success('✅ 任务池已清空');
    }
    
    /**
     * 重置失败的任务
     */
    private function resetFailed(): void
    {
        $this->taskPool->load();
        $count = $this->taskPool->resetFailedTasks();
        $this->taskPool->save();
        
        if ($count > 0) {
            $this->printer->success("✅ 已重置 {$count} 个失败任务");
        } else {
            $this->printer->note('没有失败的任务');
        }
    }
    
    /**
     * 列出任务
     */
    private function listTasks(): void
    {
        $this->taskPool->load();
        $pool = $this->taskPool->getPool();
        
        $this->printer->success('📋 任务列表');
        $this->printer->printing('');
        
        // 活跃任务
        if (!empty($pool['agents'])) {
            $this->printer->printing('📝 活跃任务:');
            foreach ($pool['agents'] as $agentId => $task) {
                $status = $this->getStatusIcon($task['status']);
                $this->printer->printing("   {$status} {$agentId}: {$task['description']}");
                $this->printer->printing("      文件: {$task['file']} | 优先级: {$task['priority']}");
                if ($task['dep']) {
                    $this->printer->printing("      依赖: {$task['dep']}");
                }
            }
            $this->printer->printing('');
        }
        
        // 已完成
        if (!empty($pool['completed'])) {
            $this->printer->printing('✅ 已完成 (' . count($pool['completed']) . '):');
            foreach (array_slice($pool['completed'], -5, 5, true) as $agentId => $task) {
                $this->printer->printing("   ✓ {$agentId}: {$task['description']}");
            }
            $this->printer->printing('');
        }
        
        // 失败
        if (!empty($pool['failed'])) {
            $this->printer->printing('❌ 失败 (' . count($pool['failed']) . '):');
            foreach ($pool['failed'] as $agentId => $task) {
                $this->printer->printing("   ✗ {$agentId}: {$task['error']}");
            }
            $this->printer->printing('');
        }
    }
    
    /**
     * 获取状态图标
     */
    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            'todo' => '⏳',
            'running' => '🔄',
            'blocked' => '🚫',
            'done' => '✅',
            'failed' => '❌',
            default => '❓',
        };
    }
    
    public function tip(): string
    {
        return __('管理 Orchestrator 任务（添加/处理/派发/清空）');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:orchestrator:task',
            '管理 Orchestrator 任务池',
            [
                'list' => '列出所有任务（默认）',
                'add' => '添加单个任务',
                'process' => '处理需求（AI 拆解）',
                'dispatch' => '派发可执行任务',
                'clear' => '清空任务池',
                'reset' => '重置失败的任务',
                '--agent, -a' => '[add] Agent ID',
                '--file, -f' => '[add] 目标文件',
                '--desc, -d' => '[add] 任务描述',
                '--dep' => '[add] 依赖的 Agent ID',
                '--priority, -p' => '[add] 优先级',
                '--req, -r' => '[process] 需求描述',
                '--model, -m' => '[process] AI 模型',
                '--parallel' => '[dispatch] 最大并行数',
            ],
            [],
            [
                '列出任务' => 'php bin/w cursor:orchestrator:task list',
                '添加任务' => 'php bin/w cursor:orchestrator:task add --agent=Agent_DB_001 --file=Model/User.php --desc="创建用户模型"',
                'AI 拆解' => 'php bin/w cursor:orchestrator:task process --req="开发用户登录功能"',
                '派发任务' => 'php bin/w cursor:orchestrator:task dispatch -p 5',
                '清空任务' => 'php bin/w cursor:orchestrator:task clear',
            ]
        );
    }
}
