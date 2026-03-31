<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\WlsErrorConsumerService;

/**
 * 计划执行器服务
 * 
 * 职责：
 * 1. 解析 dev/ai/plans/*.plan.md 计划文件
 * 2. 提取任务列表
 * 3. 交给 MasterBrain 进行任务拆解（如需要）
 * 4. 写入任务池并启动执行
 */
class PlanExecutorService
{
    private ?TaskPoolService $taskPool = null;
    private ?MasterBrainService $masterBrain = null;
    private ?WatchdogService $watchdog = null;
    
    private string $plansDir;
    private bool $verbose = false;
    private bool $runTests = true;
    
    public function __construct()
    {
        $this->plansDir = BP . 'dev' . DS . 'ai' . DS . 'plans' . DS;
    }
    
    /**
     * 设置详细输出
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }
    
    /**
     * 设置是否运行测试
     */
    public function setRunTests(bool $runTests): self
    {
        $this->runTests = $runTests;
        return $this;
    }
    
    /**
     * 获取任务池
     */
    private function getTaskPool(): TaskPoolService
    {
        if ($this->taskPool === null) {
            $this->taskPool = ObjectManager::getInstance(TaskPoolService::class);
        }
        return $this->taskPool;
    }
    
    /**
     * 获取 Master Brain
     */
    private function getMasterBrain(): MasterBrainService
    {
        if ($this->masterBrain === null) {
            $this->masterBrain = ObjectManager::getInstance(MasterBrainService::class);
            $this->masterBrain->setVerbose($this->verbose);
        }
        return $this->masterBrain;
    }
    
    /**
     * 获取 Watchdog
     */
    private function getWatchdog(): WatchdogService
    {
        if ($this->watchdog === null) {
            $this->watchdog = ObjectManager::getInstance(WatchdogService::class);
            $this->watchdog->setVerbose($this->verbose);
            $this->watchdog->setRunTests($this->runTests);
        }
        return $this->watchdog;
    }
    
    /**
     * 规则：每个需求开始前，先消费所有积压的 WLS 错误
     * 消费完才允许加载新任务
     */
    private function consumeWlsErrorsFirst(): void
    {
        $consumer = ObjectManager::getInstance(WlsErrorConsumerService::class);
        $result = $consumer->consumeAllBlocking();
        
        if ($result['total_claimed'] > 0) {
            $this->log('📋 WLS错误消费结果:');
            $this->log("   消费轮次: {$result['loops']}");
            $this->log("   申领任务: {$result['total_claimed']}");
            $this->log("   成功修复: {$result['total_fixed']}");
            $this->log("   修复失败: {$result['total_failed']}");
            
            if ($result['total_fixed'] > 0) {
                $this->log("✅ WLS错误已全部修复，可以开始新需求");
            }
            if ($result['total_failed'] > 0) {
                $this->log("⚠️  {$result['total_failed']} 个 WLS 错误修复失败，请检查日志");
            }
        } else {
            $this->log('📋 当前无积压 WLS 错误任务');
        }
    }
    
    /**
     * 列出所有计划
     */
    public function listPlans(): array
    {
        $plans = [];
        
        if (!is_dir($this->plansDir)) {
            return $plans;
        }
        
        $files = glob($this->plansDir . '*.plan.md');
        
        foreach ($files as $file) {
            $name = basename($file, '.plan.md');
            $content = file_get_contents($file);
            
            $plan = [
                'name' => $name,
                'file' => $file,
                'title' => $this->extractTitle($content),
                'status' => $this->extractMeta($content, 'status', 'pending'),
                'priority' => $this->extractMeta($content, 'priority', 'normal'),
                'tasks' => $this->countTasks($content),
            ];
            
            $plans[$name] = $plan;
        }
        
        return $plans;
    }
    
    /**
     * 获取待处理计划（pending/ready，未开始）
     */
    public function getPendingPlans(): array
    {
        $plans = $this->listPlans();
        return array_filter($plans, fn($p) => in_array($p['status'], ['pending', 'ready']));
    }
    
    /**
     * 获取进行中计划（running）
     */
    public function getRunningPlans(): array
    {
        $plans = $this->listPlans();
        return array_filter($plans, fn($p) => $p['status'] === 'running');
    }
    
    /**
     * 获取已完成计划（done）
     */
    public function getCompletedPlans(): array
    {
        $plans = $this->listPlans();
        return array_filter($plans, fn($p) => $p['status'] === 'done');
    }
    
    /**
     * 获取计划状态摘要
     */
    public function getStatusSummary(): array
    {
        $plans = $this->listPlans();
        $summary = [
            'total' => count($plans),
            'pending' => 0,
            'ready' => 0,
            'running' => 0,
            'paused' => 0,
            'done' => 0,
            'cancelled' => 0,
        ];
        
        foreach ($plans as $plan) {
            $status = $plan['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }
        
        return $summary;
    }
    
    /**
     * 启动计划（将状态改为 running）
     */
    public function startPlan(string $name): array
    {
        $plan = $this->getPlan($name);
        
        if (!$plan) {
            return ['success' => false, 'error' => "计划 '{$name}' 不存在"];
        }
        
        $currentStatus = $plan['status'];
        
        if ($currentStatus === 'running') {
            return ['success' => true, 'message' => "计划已在运行中"];
        }
        
        if ($currentStatus === 'done') {
            return ['success' => false, 'error' => "计划已完成，如需重新执行请先将状态改为 pending"];
        }
        
        $this->updatePlanStatus($plan['file'], 'running');
        $this->updatePlanMeta($plan['file'], 'start_time', date('Y-m-d H:i:s'));
        
        return ['success' => true, 'message' => "计划 '{$name}' 已启动"];
    }
    
    /**
     * 暂停计划
     */
    public function pausePlan(string $name): array
    {
        $plan = $this->getPlan($name);
        
        if (!$plan) {
            return ['success' => false, 'error' => "计划 '{$name}' 不存在"];
        }
        
        if ($plan['status'] !== 'running') {
            return ['success' => false, 'error' => "只能暂停运行中的计划"];
        }
        
        $this->updatePlanStatus($plan['file'], 'paused');
        
        return ['success' => true, 'message' => "计划 '{$name}' 已暂停"];
    }
    
    /**
     * 完成计划
     */
    public function completePlan(string $name): array
    {
        $plan = $this->getPlan($name);
        
        if (!$plan) {
            return ['success' => false, 'error' => "计划 '{$name}' 不存在"];
        }
        
        $this->updatePlanStatus($plan['file'], 'done');
        $this->updatePlanMeta($plan['file'], 'complete_time', date('Y-m-d H:i:s'));
        
        return ['success' => true, 'message' => "计划 '{$name}' 已完成"];
    }
    
    /**
     * 更新计划元信息
     */
    private function updatePlanMeta(string $file, string $key, string $value): void
    {
        $content = file_get_contents($file);
        
        // 尝试更新现有字段
        $pattern = '/(\*\*' . preg_quote($key, '/') . '\*\*:\s*).*/i';
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, '${1}' . $value, $content);
        } else {
            // 在元信息部分添加新字段
            $content = preg_replace(
                '/(## 元信息\n(?:- [^\n]+\n)+)/',
                '${1}- **' . $key . '**: ' . $value . "\n",
                $content
            );
        }
        
        file_put_contents($file, $content);
    }
    
    /**
     * 获取计划详情
     */
    public function getPlan(string $name): ?array
    {
        $file = $this->plansDir . $name . '.plan.md';
        
        if (!file_exists($file)) {
            return null;
        }
        
        $content = file_get_contents($file);
        
        return [
            'name' => $name,
            'file' => $file,
            'title' => $this->extractTitle($content),
            'status' => $this->extractMeta($content, 'status', 'pending'),
            'priority' => $this->extractMeta($content, 'priority', 'normal'),
            'id' => $this->extractMeta($content, 'id', $name),
            'description' => $this->extractDescription($content),
            'tasks' => $this->extractTasks($content),
            'tests' => $this->extractTests($content),
            'content' => $content,
        ];
    }
    
    /**
     * 执行计划
     */
    public function execute(string $name): array
    {
        $plan = $this->getPlan($name);
        
        if (!$plan) {
            return ['success' => false, 'error' => "计划 '{$name}' 不存在"];
        }
        
        // 检查计划状态
        $status = $plan['status'];
        if (!in_array($status, ['running', 'ready'])) {
            return [
                'success' => false, 
                'error' => "计划 '{$name}' 状态为 '{$status}'，需要先将状态改为 'running' 才能开始执行",
                'hint' => "在 plan.md 中将 **状态**: {$status} 改为 **状态**: running",
            ];
        }
        
        $this->log("🚀 开始执行计划: {$plan['title']}");
        
        // 更新计划状态为 running
        $this->updatePlanStatus($plan['file'], 'running');
        
        // 先消费掉所有积压的 WLS 错误（规则：每个需求开始前必须先修复旧错误）
        $this->consumeWlsErrorsFirst();
        
        // 清空当前任务池
        $this->getTaskPool()->load();
        $this->getTaskPool()->clear();
        
        // 设置当前计划
        $pool = $this->getTaskPool()->getPool();
        $pool['current_plan'] = $name;
        $this->getTaskPool()->setPool($pool);
        
        // 解析并添加任务
        $tasks = $plan['tasks'];
        
        if (empty($tasks)) {
            // 如果计划中没有明确的任务，让 AI 拆解
            $this->log("📋 计划中未定义任务，使用 AI 拆解...");
            $tasks = $this->getMasterBrain()->processRequirement($plan['description']);
        } else {
            // 直接添加计划中的任务
            $this->log("📋 从计划中提取 " . count($tasks) . " 个任务");
            $this->getTaskPool()->addTasks($tasks);
        }
        
        $this->getTaskPool()->save();
        
        $this->log("✅ 任务已加载到任务池，共 " . count($tasks) . " 个");
        
        // 启动监控循环
        $this->log("🐕 启动 Watchdog 监控...");
        $this->getWatchdog()->start();
        
        return [
            'success' => true,
            'plan' => $name,
            'tasks' => count($tasks),
        ];
    }
    
    /**
     * 单次检查并派发（非阻塞模式）
     */
    public function checkAndDispatch(string $name): array
    {
        $plan = $this->getPlan($name);
        
        if (!$plan) {
            return ['success' => false, 'error' => "计划 '{$name}' 不存在"];
        }
        
        // 检查是否已加载
        $this->getTaskPool()->load();
        $pool = $this->getTaskPool()->getPool();
        
        if ($pool['current_plan'] !== $name || empty($pool['agents'])) {
            // 首次执行，加载任务
            return $this->loadPlanTasks($name, $plan);
        }
        
        // 执行一次检查
        $result = $this->getWatchdog()->check();
        
        // 检查是否全部完成
        $stats = $this->getTaskPool()->getStats();
        $allDone = $stats['total'] === 0 && $stats['completed'] > 0;
        
        if ($allDone) {
            $this->updatePlanStatus($plan['file'], 'completed');
            $this->log("🎉 计划 '{$name}' 已全部完成！");
        }
        
        return [
            'success' => true,
            'plan' => $name,
            'result' => $result,
            'stats' => $stats,
            'completed' => $allDone,
        ];
    }
    
    /**
     * 加载计划任务
     */
    private function loadPlanTasks(string $name, array $plan): array
    {
        $this->log("🚀 加载计划: {$plan['title']}");
        
        // 更新计划状态
        $this->updatePlanStatus($plan['file'], 'running');
        
        // 清空并设置
        $this->getTaskPool()->clear();
        $pool = $this->getTaskPool()->getPool();
        $pool['current_plan'] = $name;
        $this->getTaskPool()->setPool($pool);
        
        // 解析任务
        $tasks = $plan['tasks'];
        
        if (empty($tasks)) {
            $tasks = $this->getMasterBrain()->processRequirement($plan['description']);
        } else {
            $this->getTaskPool()->addTasks($tasks);
        }
        
        $this->getTaskPool()->save();
        
        return [
            'success' => true,
            'plan' => $name,
            'tasks_loaded' => count($tasks),
        ];
    }
    
    /**
     * 提取标题
     */
    private function extractTitle(string $content): string
    {
        if (preg_match('/^#\s+(.+)$/m', $content, $match)) {
            return trim($match[1]);
        }
        return 'Untitled';
    }
    
    /**
     * 提取元信息
     */
    private function extractMeta(string $content, string $key, string $default = ''): string
    {
        // 中英文键名映射
        $keyMap = [
            'status' => ['status', '状态'],
            'priority' => ['priority', '优先级'],
            'id' => ['id', 'ID'],
            'created' => ['created', '创建时间'],
            'start_time' => ['start_time', '开始时间'],
            'complete_time' => ['complete_time', '完成时间'],
        ];
        
        $keys = $keyMap[$key] ?? [$key];
        
        foreach ($keys as $k) {
            $patterns = [
                "/\*\*{$k}\*\*:\s*(.+)/iu",
                "/-\s*\*\*{$k}\*\*:\s*(.+)/iu",
                "/{$k}:\s*(.+)/iu",
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $content, $match)) {
                    return trim($match[1]);
                }
            }
        }
        
        return $default;
    }
    
    /**
     * 提取描述
     */
    private function extractDescription(string $content): string
    {
        // 查找 "## 需求描述" 部分
        if (preg_match('/##\s*需求描述\s*\n([\s\S]+?)(?=\n##|\z)/i', $content, $match)) {
            return trim($match[1]);
        }
        
        // 或者取第一段非标题内容
        if (preg_match('/^#.+\n\n([\s\S]+?)(?=\n##|\z)/m', $content, $match)) {
            return trim($match[1]);
        }
        
        return '';
    }
    
    /**
     * 提取任务列表
     */
    private function extractTasks(string $content): array
    {
        $tasks = [];
        
        // 匹配任务行：- [ ] 任务描述 @Agent:xxx @File:xxx
        $pattern = '/^-\s*\[[ x\/\-]\]\s*(.+)$/m';
        
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $idx => $taskLine) {
                $task = $this->parseTaskLine($taskLine, $idx + 1);
                if ($task) {
                    $tasks[] = $task;
                }
            }
        }
        
        return $tasks;
    }
    
    /**
     * 解析任务行
     */
    private function parseTaskLine(string $line, int $index): ?array
    {
        $task = [
            'agent_id' => 'Agent_General_' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
            'file' => '',
            'description' => '',
            'dep' => null,
            'priority' => 'normal',
        ];
        
        // 提取 @Agent:xxx
        if (preg_match('/@Agent:(\w+)/', $line, $match)) {
            $task['agent_id'] = 'Agent_' . $match[1] . '_' . str_pad((string)$index, 3, '0', STR_PAD_LEFT);
        }
        
        // 提取 @File:xxx
        if (preg_match('/@File:([^\s@\[\]]+)/', $line, $match)) {
            $task['file'] = $match[1];
        }
        
        // 提取 @Dep:xxx
        if (preg_match('/@Dep:([^\s@\[\]]+)/', $line, $match)) {
            $task['dep'] = $match[1];
        }
        
        // 提取优先级 [P1] - [P5]
        if (preg_match('/\[P([1-5])\]/', $line, $match)) {
            $task['priority'] = match ($match[1]) {
                '1' => 'critical',
                '2' => 'high',
                '3' => 'normal',
                '4' => 'low',
                '5' => 'trivial',
                default => 'normal',
            };
        }
        
        // 清理描述
        $description = preg_replace('/@\w+:[^\s@\[\]]+/', '', $line);
        $description = preg_replace('/\[P[1-5]\]/', '', $description);
        $task['description'] = trim($description);
        
        if (empty($task['description'])) {
            return null;
        }
        
        return $task;
    }
    
    /**
     * 提取测试要求
     */
    private function extractTests(string $content): array
    {
        $tests = [];
        
        // 查找 "## 测试要求" 部分
        if (preg_match('/##\s*测试要求\s*\n([\s\S]+?)(?=\n##|\z)/i', $content, $match)) {
            $testSection = $match[1];
            
            // 提取测试项
            if (preg_match_all('/^-\s*\[[ x]\]\s*(.+)$/m', $testSection, $testMatches)) {
                foreach ($testMatches[1] as $test) {
                    $tests[] = trim($test);
                }
            }
        }
        
        return $tests;
    }
    
    /**
     * 统计任务数
     */
    private function countTasks(string $content): array
    {
        $total = 0;
        $completed = 0;
        
        if (preg_match_all('/^-\s*\[([ x\/\-])\]/m', $content, $matches)) {
            $total = count($matches[0]);
            foreach ($matches[1] as $status) {
                if ($status === 'x') {
                    $completed++;
                }
            }
        }
        
        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $total - $completed,
        ];
    }
    
    /**
     * 更新计划状态
     */
    private function updatePlanStatus(string $file, string $status): void
    {
        if (!file_exists($file)) {
            return;
        }
        
        $content = file_get_contents($file);
        
        // 更新状态
        $patterns = [
            '/(\*\*状态\*\*:\s*)(\w+)/i',
            '/(-\s*\*\*状态\*\*:\s*)(\w+)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, '$1' . $status, $content);
                file_put_contents($file, $content);
                return;
            }
        }
    }
    
    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[PlanExecutor] {$message}\n";
        }
        
        $logFile = BP . 'var/log/plan-executor.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
