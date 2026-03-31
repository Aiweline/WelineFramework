<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Agent\CursorBase\Service\TaskPoolService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Watchdog 服务（监工/守护进程）
 * 
 * 职责：
 * 1. 利用 filemtime 监控源码变化
 * 2. 运行 php -l 语法检查或 phpunit 测试
 * 3. 自动将错误日志写回 tasks.json
 * 4. 重新唤醒 Master 修复错误
 * 5. 检测任务完成状态
 */
class WatchdogService
{
    private ?TaskPoolService $taskPool = null;
    private ?MasterBrainService $masterBrain = null;
    private ?CursorDriverService $driver = null;
    private ?CodeBackupService $backupService = null;
    private ?ComplianceCheckerService $complianceChecker = null;
    private ?AutoTaskGeneratorService $autoTaskGenerator = null;
    
    private array $fileTimestamps = [];
    private int $checkInterval = 2; // 秒
    private bool $verbose = false;
    private bool $runTests = false;
    private bool $running = false;
    private bool $autoBackup = true;
    private bool $complianceCheck = true;
    
    /**
     * 设置检查间隔
     */
    public function setCheckInterval(int $seconds): self
    {
        $this->checkInterval = max(1, $seconds);
        return $this;
    }

    /**
     * 获取主循环等待毫秒数
     */
    public function getLoopDelayMilliseconds(): int
    {
        return $this->checkInterval * 1000;
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
     * 设置详细输出
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
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
     * 获取驱动服务
     */
    private function getDriver(): CursorDriverService
    {
        if ($this->driver === null) {
            $this->driver = ObjectManager::getInstance(CursorDriverService::class);
            $this->driver->setVerbose($this->verbose);
        }
        return $this->driver;
    }
    
    /**
     * 获取备份服务
     */
    private function getBackupService(): CodeBackupService
    {
        if ($this->backupService === null) {
            $this->backupService = ObjectManager::getInstance(CodeBackupService::class);
            $this->backupService->setVerbose($this->verbose);
        }
        return $this->backupService;
    }
    
    /**
     * 获取合规检查服务
     */
    private function getComplianceChecker(): ComplianceCheckerService
    {
        if ($this->complianceChecker === null) {
            $this->complianceChecker = ObjectManager::getInstance(ComplianceCheckerService::class);
            $this->complianceChecker->setVerbose($this->verbose);
        }
        return $this->complianceChecker;
    }
    
    /**
     * 获取自动任务生成器
     */
    private function getAutoTaskGenerator(): AutoTaskGeneratorService
    {
        if ($this->autoTaskGenerator === null) {
            $this->autoTaskGenerator = ObjectManager::getInstance(AutoTaskGeneratorService::class);
            $this->autoTaskGenerator->setVerbose($this->verbose);
            $this->autoTaskGenerator->setAutoBackup($this->autoBackup);
        }
        return $this->autoTaskGenerator;
    }
    
    /**
     * 设置是否自动备份
     */
    public function setAutoBackup(bool $autoBackup): self
    {
        $this->autoBackup = $autoBackup;
        return $this;
    }
    
    /**
     * 设置是否合规检查
     */
    public function setComplianceCheck(bool $complianceCheck): self
    {
        $this->complianceCheck = $complianceCheck;
        return $this;
    }
    
    /**
     * 启动监控循环
     */
    public function start(): void
    {
        $this->running = true;
        $this->log("🐕 Watchdog 启动，开始监控...");
        
        while ($this->running) {
            try {
                // 0. 显示待处理计划提醒
                $this->showPendingPlansReminder();
                
                // 0.1 检查是否有 running 状态的计划
                if (!$this->hasRunningPlans()) {
                    $this->log("⏸️ 无进行中的计划，等待计划启动...");
                    $this->log("   提示: 将 plan.md 中的状态改为 'running' 或执行 cursor:plan:start");
                    SchedulerSystem::yieldDelay($this->getLoopDelayMilliseconds());
                    continue;
                }
                
                // 1. 检查 plan.md 是否有新需求
                $this->checkForNewRequirements();
                
                // 2. 检查运行中的任务状态
                $this->checkRunningTasks();
                
                // 3. 派发新任务
                $this->dispatchReadyTasks();
                
                // 4. 监控源码变化
                $this->watchSourceChanges();
                
            } catch (\Exception $e) {
                $this->log("❌ 错误: " . $e->getMessage());
            }
            
            SchedulerSystem::yieldDelay($this->getLoopDelayMilliseconds());
        }
    }
    
    /**
     * 检查是否有运行中的计划
     */
    private function hasRunningPlans(): bool
    {
        $planExecutor = ObjectManager::getInstance(PlanExecutorService::class);
        $runningPlans = $planExecutor->getRunningPlans();
        return !empty($runningPlans);
    }
    
    /**
     * 显示待处理计划提醒
     */
    private function showPendingPlansReminder(): void
    {
        static $lastReminderTime = 0;
        $now = time();
        
        // 每 30 秒提醒一次
        if ($now - $lastReminderTime < 30) {
            return;
        }
        $lastReminderTime = $now;
        
        $planExecutor = ObjectManager::getInstance(PlanExecutorService::class);
        $pendingPlans = $planExecutor->getPendingPlans();
        
        if (empty($pendingPlans)) {
            return;
        }
        
        echo "\n";
        echo "┌─────────────────────────────────────────────────────────┐\n";
        echo "│  📋 待处理计划 (" . count($pendingPlans) . " 个)                                 │\n";
        echo "├─────────────────────────────────────────────────────────┤\n";
        
        foreach ($pendingPlans as $name => $plan) {
            $status = $plan['status'];
            $title = mb_substr($plan['title'], 0, 30);
            $tasks = $plan['tasks']['total'] ?? 0;
            $statusIcon = $status === 'ready' ? '🟢' : '🟡';
            printf("│  %s %-12s %-25s %3d 任务 │\n", $statusIcon, $name, $title, $tasks);
        }
        
        echo "├─────────────────────────────────────────────────────────┤\n";
        echo "│  启动: cursor:plan:start {name} 或编辑 plan.md 改状态    │\n";
        echo "└─────────────────────────────────────────────────────────┘\n";
        echo "\n";
    }
    
    /**
     * 停止监控
     */
    public function stop(): void
    {
        $this->running = false;
        $this->log("🛑 Watchdog 停止");
    }
    
    /**
     * 单次检查（非循环模式）
     */
    public function check(): array
    {
        $results = [
            'new_requirements' => [],
            'completed' => [],
            'failed' => [],
            'dispatched' => 0,
            'errors' => [],
        ];
        
        // 1. 检查新需求
        $requirement = $this->getMasterBrain()->watchPlanFile();
        if ($requirement) {
            $results['new_requirements'][] = $requirement;
            $this->getMasterBrain()->processRequirement($requirement);
        }
        
        // 2. 检查运行中的任务
        $taskStatus = $this->checkRunningTasksOnce();
        $results['completed'] = $taskStatus['completed'];
        $results['failed'] = $taskStatus['failed'];
        
        // 3. 派发新任务
        $results['dispatched'] = $this->getDriver()->drive();
        
        // 4. 检查源码错误
        $results['errors'] = $this->auditSourceFiles();
        
        return $results;
    }
    
    /**
     * 检查新需求
     */
    private function checkForNewRequirements(): void
    {
        $requirement = $this->getMasterBrain()->watchPlanFile();
        
        if ($requirement) {
            $this->log("📋 发现新需求: {$requirement}");
            
            // 处理需求，拆解任务
            $tasks = $this->getMasterBrain()->processRequirement($requirement);
            
            $this->log("   已拆解为 " . count($tasks) . " 个子任务");
        }
    }
    
    /**
     * 检查运行中的任务
     */
    private function checkRunningTasks(): void
    {
        $status = $this->checkRunningTasksOnce();
        
        // 处理完成的任务
        foreach ($status['completed'] as $agentId) {
            $this->log("✅ 任务完成: {$agentId}");
            $this->getTaskPool()->updateStatus($agentId, 'done');
        }
        
        // 处理失败的任务
        foreach ($status['failed'] as $agentId) {
            $task = $this->getTaskPool()->getTask($agentId);
            $error = $this->getTaskError($agentId, $task);
            
            $this->log("❌ 任务失败: {$agentId} - {$error}");
            
            // 交给 Master Brain 处理
            $this->getMasterBrain()->handleTaskFailure($agentId, $error);
        }
        
        $this->getTaskPool()->save();
    }
    
    /**
     * 一次性检查运行中的任务
     */
    private function checkRunningTasksOnce(): array
    {
        $this->getTaskPool()->load();
        $runningTasks = $this->getTaskPool()->getRunningTasks();
        
        $completed = [];
        $failed = [];
        
        foreach ($runningTasks as $agentId => $task) {
            $status = $this->auditTask($agentId, $task);
            
            switch ($status['status']) {
                case 'completed':
                    $completed[] = $agentId;
                    break;
                    
                case 'failed':
                    $failed[] = $agentId;
                    break;
            }
        }
        
        return [
            'completed' => $completed,
            'failed' => $failed,
        ];
    }
    
    /**
     * 审计单个任务
     */
    private function auditTask(string $agentId, array $task): array
    {
        $filePath = $task['file'];
        
        // 确保是绝对路径
        if (!str_starts_with($filePath, BP)) {
            $filePath = BP . ltrim($filePath, '/\\');
        }
        
        // 检查文件是否存在
        if (!file_exists($filePath)) {
            return ['status' => 'running'];
        }
        
        // 备份文件（如果启用）
        if ($this->autoBackup) {
            $this->getBackupService()->backupFile($filePath);
        }
        
        // 检查完成标记
        $content = file_get_contents($filePath);
        
        // 检查 @Status: Completed 或 SUPERVISOR_TASK 被移除
        if (str_contains($content, '@Status: Completed') || 
            str_contains($content, '@Status: Done')) {
            return ['status' => 'completed'];
        }
        
        // 检查信号弹是否被移除
        if (!str_contains($content, '[SUPERVISOR_TASK]') && 
            !str_contains($content, '@AgentID:')) {
            // 信号弹被移除，检查是否有错误
            $syntaxCheck = $this->checkPhpSyntax($filePath);
            
            if ($syntaxCheck['success']) {
                return ['status' => 'completed'];
            } else {
                return ['status' => 'failed', 'error' => $syntaxCheck['error']];
            }
        }
        
        // 检查语法错误
        if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
            $syntaxCheck = $this->checkPhpSyntax($filePath);
            
            if (!$syntaxCheck['success']) {
                return ['status' => 'failed', 'error' => $syntaxCheck['error']];
            }
        }
        
        // 可选：运行测试
        if ($this->runTests) {
            $testResult = $this->runPhpUnit($filePath);
            
            if (!$testResult['success']) {
                return ['status' => 'failed', 'error' => $testResult['error']];
            }
        }
        
        // 合规性检查（如果启用）
        if ($this->complianceCheck) {
            $compliance = $this->getComplianceChecker()->checkFile($filePath);
            
            if (!$compliance['compliant']) {
                $violationCount = count($compliance['violations']);
                $this->log("⚠️ 发现 {$violationCount} 个规则违规");
                
                // 生成修复任务
                $this->getAutoTaskGenerator()->processFileChange($filePath);
            }
        }
        
        return ['status' => 'running'];
    }
    
    /**
     * 检查 PHP 语法
     */
    private function checkPhpSyntax(string $filePath): array
    {
        $output = [];
        $returnCode = 0;
        
        exec("php -l " . escapeshellarg($filePath) . " 2>&1", $output, $returnCode);
        
        return [
            'success' => $returnCode === 0,
            'error' => $returnCode !== 0 ? implode("\n", $output) : null,
        ];
    }
    
    /**
     * 运行 PHPUnit 测试
     */
    private function runPhpUnit(string $filePath): array
    {
        // 查找对应的测试文件
        $testFile = $this->findTestFile($filePath);
        
        if (!$testFile) {
            $this->log("⏭️ 无对应测试文件: {$filePath}");
            return ['success' => true, 'error' => null];
        }
        
        $this->log("🧪 运行单元测试: {$testFile}");
        
        $output = [];
        $returnCode = 0;
        
        // 使用配置中的测试命令
        $testCommand = $this->getTaskPool()->getConfig('watchdog.test_command', 'php bin/w phpunit:run');
        exec("{$testCommand} " . escapeshellarg($testFile) . " 2>&1", $output, $returnCode);
        
        if ($returnCode === 0) {
            $this->log("✅ 单元测试通过");
        } else {
            $this->log("❌ 单元测试失败");
        }
        
        return [
            'success' => $returnCode === 0,
            'error' => $returnCode !== 0 ? implode("\n", $output) : null,
        ];
    }
    
    /**
     * 运行 HTTP 测试
     */
    private function runHttpTest(string $agentId, array $task): array
    {
        // 检查是否启用 HTTP 测试
        if (!$this->getTaskPool()->getConfig('watchdog.run_http_tests', false)) {
            return ['success' => true, 'error' => null];
        }
        
        // 查找 HTTP 测试路径
        $httpTestPath = $this->findHttpTestPath($agentId, $task);
        
        if (!$httpTestPath) {
            return ['success' => true, 'error' => null];
        }
        
        $this->log("🌐 运行 HTTP 测试: {$httpTestPath}");
        
        $output = [];
        $returnCode = 0;
        
        $httpCommand = $this->getTaskPool()->getConfig('watchdog.http_test_command', 'php bin/w http:req');
        exec("{$httpCommand} -b {$httpTestPath} 2>&1", $output, $returnCode);
        
        if ($returnCode === 0) {
            $this->log("✅ HTTP 测试通过");
        } else {
            $this->log("❌ HTTP 测试失败");
        }
        
        return [
            'success' => $returnCode === 0,
            'error' => $returnCode !== 0 ? implode("\n", $output) : null,
        ];
    }
    
    /**
     * 查找 HTTP 测试路径
     */
    private function findHttpTestPath(string $agentId, array $task): ?string
    {
        $filePath = $task['file'] ?? '';
        
        // 如果是 Controller，推断测试路径
        if (str_contains($filePath, 'Controller')) {
            // 从文件路径推断路由
            // app/code/Module/Controller/Backend/Action.php -> /backend/module/action
            if (preg_match('/Controller[\/\\\\](Backend|Frontend)[\/\\\\](\w+)\.php$/', $filePath, $match)) {
                $area = strtolower($match[1]);
                $action = strtolower($match[2]);
                return "/{$area}/{$action}";
            }
        }
        
        return null;
    }
    
    /**
     * 查找测试文件
     */
    private function findTestFile(string $filePath): ?string
    {
        $baseName = pathinfo($filePath, PATHINFO_FILENAME);
        $dirPath = dirname($filePath);
        
        // 常见测试文件命名模式
        $patterns = [
            $dirPath . '/Test/' . $baseName . 'Test.php',
            $dirPath . '/../Test/' . $baseName . 'Test.php',
            str_replace('/Service/', '/Test/Service/', $filePath),
        ];
        
        foreach ($patterns as $pattern) {
            $testFile = str_replace('.php', 'Test.php', $pattern);
            if (file_exists($testFile)) {
                return $testFile;
            }
        }
        
        return null;
    }
    
    /**
     * 获取任务错误信息
     */
    private function getTaskError(string $agentId, ?array $task): string
    {
        if (!$task) {
            return 'Task not found';
        }
        
        $filePath = $task['file'];
        if (!str_starts_with($filePath, BP)) {
            $filePath = BP . ltrim($filePath, '/\\');
        }
        
        // 检查语法错误
        $syntaxCheck = $this->checkPhpSyntax($filePath);
        if (!$syntaxCheck['success']) {
            return $syntaxCheck['error'];
        }
        
        // 检查 Agent 日志
        $logFile = BP . 'dev/ai/agents/' . $agentId . '.log';
        if (file_exists($logFile)) {
            return file_get_contents($logFile);
        }
        
        return 'Unknown error';
    }
    
    /**
     * 派发可执行的任务
     */
    private function dispatchReadyTasks(): void
    {
        $dispatched = $this->getDriver()->drive();
        
        if ($dispatched > 0) {
            $this->log("🚀 派发了 {$dispatched} 个新任务");
        }
    }
    
    /**
     * 监控源码变化
     */
    private function watchSourceChanges(): void
    {
        $this->getTaskPool()->load();
        $runningTasks = $this->getTaskPool()->getRunningTasks();
        
        foreach ($runningTasks as $agentId => $task) {
            $filePath = $task['file'];
            if (!str_starts_with($filePath, BP)) {
                $filePath = BP . ltrim($filePath, '/\\');
            }
            
            if (!file_exists($filePath)) {
                continue;
            }
            
            $mtime = filemtime($filePath);
            $lastMtime = $this->fileTimestamps[$filePath] ?? 0;
            
            if ($mtime > $lastMtime) {
                $this->fileTimestamps[$filePath] = $mtime;
                
                if ($lastMtime > 0) {
                    $this->log("📝 文件变化检测: {$filePath}");
                    
                    // 立即审计
                    $result = $this->auditTask($agentId, $task);
                    
                    if ($result['status'] === 'failed') {
                        $this->log("⚠️ 检测到错误，通知 Master Brain 处理");
                        $this->getMasterBrain()->handleTaskFailure($agentId, $result['error']);
                    } elseif ($result['status'] === 'completed') {
                        $this->log("✅ 任务完成: {$agentId}");
                        $this->getTaskPool()->updateStatus($agentId, 'done');
                        $this->getTaskPool()->save();
                    }
                }
            }
        }
    }
    
    /**
     * 审计所有源文件
     */
    private function auditSourceFiles(): array
    {
        $errors = [];
        
        $this->getTaskPool()->load();
        $runningTasks = $this->getTaskPool()->getRunningTasks();
        
        foreach ($runningTasks as $agentId => $task) {
            $filePath = $task['file'];
            if (!str_starts_with($filePath, BP)) {
                $filePath = BP . ltrim($filePath, '/\\');
            }
            
            if (!file_exists($filePath)) {
                continue;
            }
            
            if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
                $syntaxCheck = $this->checkPhpSyntax($filePath);
                
                if (!$syntaxCheck['success']) {
                    $errors[$agentId] = $syntaxCheck['error'];
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * 获取状态报告
     */
    public function getStatusReport(): array
    {
        $this->getTaskPool()->load();
        $stats = $this->getTaskPool()->getStats();
        $masterStatus = $this->getTaskPool()->getMasterStatus();
        
        return [
            'watchdog' => [
                'running' => $this->running,
                'check_interval' => $this->checkInterval,
                'run_tests' => $this->runTests,
            ],
            'master' => $masterStatus,
            'tasks' => $stats,
            'active_instances' => $this->getDriver()->getActiveCount(),
        ];
    }
    
    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[Watchdog] {$message}\n";
        }
        
        $logFile = BP . 'var/log/watchdog.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
