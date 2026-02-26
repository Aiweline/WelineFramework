<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;

/**
 * Cursor 智能监督服务
 * 
 * 功能：
 * 1. 监听文件变化（轮询 filemtime）
 * 2. 语法检查（php -l）
 * 3. 逻辑验证（运行代码检测异常）
 * 4. AI 自动修复（调用配置的 AI API）
 * 5. 文档-代码同步监督（扫描 doc/ 任务，派发给 Cursor Agent）
 */
class CursorSupervisorService
{
    public const PROCESS_NAME = 'agent-cursor-supervisor';
    
    private CodeAnalyzerService $codeAnalyzer;
    private AiFixerService $aiFixer;
    private ?DocumentTaskScanner $taskScanner = null;
    private ?CodeTaskMatcher $taskMatcher = null;
    private ?AgentDispatcher $agentDispatcher = null;
    private ?TaskCompletionDetector $completionDetector = null;
    
    private array $watchPaths = [];
    private array $fileTimestamps = [];
    private array $docTimestamps = [];
    private int $checkInterval = 500000; // 0.5秒 (微秒)
    private int $docCheckInterval = 5; // 文档检查间隔（秒）
    private int $lastDocCheck = 0;
    private int $maxRetries = 3;
    private bool $running = false;
    private bool $verbose = false;
    private bool $enableDocSync = true;
    private bool $autoTrigger = true;
    
    public function __construct(
        CodeAnalyzerService $codeAnalyzer,
        AiFixerService $aiFixer
    ) {
        $this->codeAnalyzer = $codeAnalyzer;
        $this->aiFixer = $aiFixer;
    }
    
    /**
     * 懒加载：获取文档任务扫描器
     */
    private function getTaskScanner(): DocumentTaskScanner
    {
        if ($this->taskScanner === null) {
            $this->taskScanner = ObjectManager::getInstance(DocumentTaskScanner::class);
        }
        return $this->taskScanner;
    }
    
    /**
     * 懒加载：获取代码任务匹配器
     */
    private function getTaskMatcher(): CodeTaskMatcher
    {
        if ($this->taskMatcher === null) {
            $this->taskMatcher = ObjectManager::getInstance(CodeTaskMatcher::class);
        }
        return $this->taskMatcher;
    }
    
    /**
     * 懒加载：获取智能体调度器
     */
    private function getAgentDispatcher(): AgentDispatcher
    {
        if ($this->agentDispatcher === null) {
            $this->agentDispatcher = ObjectManager::getInstance(AgentDispatcher::class);
            $this->agentDispatcher->setAutoTrigger($this->autoTrigger);
        }
        return $this->agentDispatcher;
    }
    
    /**
     * 懒加载：获取任务完成检测器
     */
    private function getCompletionDetector(): TaskCompletionDetector
    {
        if ($this->completionDetector === null) {
            $this->completionDetector = ObjectManager::getInstance(TaskCompletionDetector::class);
        }
        return $this->completionDetector;
    }
    
    /**
     * 设置监控路径
     */
    public function setWatchPaths(array $paths): self
    {
        $this->watchPaths = $paths;
        return $this;
    }
    
    /**
     * 添加监控路径
     */
    public function addWatchPath(string $path): self
    {
        if (!in_array($path, $this->watchPaths)) {
            $this->watchPaths[] = $path;
        }
        return $this;
    }
    
    /**
     * 设置检查间隔（毫秒）
     */
    public function setCheckInterval(int $milliseconds): self
    {
        $this->checkInterval = $milliseconds * 1000;
        return $this;
    }
    
    /**
     * 设置详细输出模式
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }
    
    /**
     * 设置是否启用文档同步
     */
    public function setEnableDocSync(bool $enable): self
    {
        $this->enableDocSync = $enable;
        return $this;
    }
    
    /**
     * 设置文档检查间隔（秒）
     */
    public function setDocCheckInterval(int $seconds): self
    {
        $this->docCheckInterval = $seconds;
        return $this;
    }
    
    /**
     * 设置是否自动触发 Cursor（模拟按键）
     */
    public function setAutoTrigger(bool $autoTrigger): self
    {
        $this->autoTrigger = $autoTrigger;
        return $this;
    }
    
    /**
     * 启动监督守护进程
     */
    public function start(): void
    {
        $this->running = true;
        $this->initFileTimestamps();
        
        $this->log('🕵️ Cursor 智能监督助手已启动');
        $this->log('📁 监控路径: ' . implode(', ', $this->watchPaths));
        $this->log('⏱️ 检查间隔: ' . ($this->checkInterval / 1000) . 'ms');
        $this->log('📄 文档同步: ' . ($this->enableDocSync ? '已启用' : '已禁用'));
        $this->log('');
        
        while ($this->running) {
            // 检查代码文件变化
            $this->checkFiles();
            
            // 检查文档任务同步
            if ($this->enableDocSync) {
                $this->checkDocumentTasks();
            }
            
            usleep($this->checkInterval);
        }
    }
    
    /**
     * 停止监督进程
     */
    public function stop(): void
    {
        $this->running = false;
        $this->log('🛑 Cursor 智能监督助手已停止');
    }
    
    /**
     * 执行单次检查（用于交互模式）
     */
    public function checkOnce(): void
    {
        // 首次运行时初始化
        if (empty($this->fileTimestamps)) {
            $this->initFileTimestamps();
        }
        
        // 检查代码文件变化
        $this->checkFiles();
        
        // 检查文档任务同步
        if ($this->enableDocSync) {
            $this->checkDocumentTasks();
        }
    }
    
    /**
     * 为交互模式初始化（预先加载文件时间戳）
     */
    public function initForInteractive(): void
    {
        if (empty($this->fileTimestamps)) {
            $this->initFileTimestamps();
        }
    }
    
    /**
     * 初始化文件时间戳
     */
    private function initFileTimestamps(): void
    {
        foreach ($this->watchPaths as $path) {
            $files = $this->getPhpFiles($path);
            foreach ($files as $file) {
                $this->fileTimestamps[$file] = filemtime($file);
            }
        }
        
        $totalFiles = count($this->fileTimestamps);
        $this->log("📊 初始化完成，监控 {$totalFiles} 个 PHP 文件");
    }
    
    /**
     * 获取目录下所有 PHP 文件
     */
    private function getPhpFiles(string $path): array
    {
        $files = [];
        
        if (is_file($path)) {
            if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                $files[] = $path;
            }
            return $files;
        }
        
        if (!is_dir($path)) {
            return $files;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $realPath = $file->getRealPath();
                if ($realPath && !$this->isExcluded($realPath)) {
                    $files[] = $realPath;
                }
            }
        }
        
        return $files;
    }
    
    /**
     * 检查是否排除的路径
     */
    private function isExcluded(string $path): bool
    {
        $excludePatterns = [
            '/vendor/',
            '/generated/',
            '/var/',
            '/pub/static/',
            '/node_modules/',
        ];
        
        foreach ($excludePatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查文件变化
     */
    private function checkFiles(): void
    {
        clearstatcache();
        
        foreach ($this->watchPaths as $path) {
            $files = $this->getPhpFiles($path);
            
            foreach ($files as $file) {
                $currentMTime = filemtime($file);
                $lastMTime = $this->fileTimestamps[$file] ?? 0;
                
                if ($currentMTime > $lastMTime) {
                    $this->fileTimestamps[$file] = $currentMTime;
                    $this->onFileChanged($file);
                }
            }
        }
    }
    
    /**
     * 检查文档任务同步
     */
    private function checkDocumentTasks(): void
    {
        $now = time();
        
        // 控制检查频率
        if ($now - $this->lastDocCheck < $this->docCheckInterval) {
            return;
        }
        
        $this->lastDocCheck = $now;
        
        $appCodePath = BP . 'app' . DIRECTORY_SEPARATOR . 'code';
        
        // 1. 扫描所有模块的任务
        $allTasks = $this->getTaskScanner()->scanAllModuleTasks($appCodePath);
        
        if (empty($allTasks)) {
            return;
        }
        
        // 2. 获取进行中的任务
        $inProgressTasks = $this->getTaskScanner()->getInProgressTasks($allTasks);
        
        // 3. 检测任务完成状态
        $activeAgents = $this->getAgentDispatcher()->getActiveAgents();
        
        if (!empty($activeAgents)) {
            // 检测手动完成（Agent 标记被删除）
            $manualCompletions = $this->getCompletionDetector()->detectManualCompletion($activeAgents);
            
            foreach ($manualCompletions as $completion) {
                // 找到对应的任务并标记完成
                foreach ($inProgressTasks as $task) {
                    if (($task['agent_id'] ?? '') === $completion['agent_id']) {
                        $this->getTaskScanner()->updateTaskStatus(
                            $task['file'],
                            $task['line'],
                            'completed'
                        );
                        $this->getAgentDispatcher()->unlockAgent($completion['agent_id']);
                        $this->log("✅ 检测到任务完成（手动）: {$task['text']}");
                    }
                }
            }
        }
        
        // 4. 分析任务与代码的差异
        foreach ($allTasks as $moduleName => $moduleData) {
            $modulePath = $moduleData['path'];
            $tasks = [];
            
            // 扁平化任务列表
            foreach ($moduleData['tasks'] as $fileTasks) {
                $tasks = array_merge($tasks, $fileTasks);
            }
            
            // 分析差异
            $gaps = $this->getTaskMatcher()->analyzeTaskCodeGap($tasks, $modulePath);
            
            foreach ($gaps as $gap) {
                $this->handleTaskGap($gap);
            }
            
            // 检测代码中的完成标记
            $completions = $this->getCompletionDetector()->scanModuleCompletions($modulePath);
            if (!empty($completions)) {
                $this->getCompletionDetector()->processCompletions($completions, $tasks);
            }
        }
    }
    
    /**
     * 处理任务差异
     */
    private function handleTaskGap(array $gap): void
    {
        $task = $gap['task'];
        $match = $gap['match'];
        
        switch ($gap['action']) {
            case 'update_task_status':
                // 代码已完成，更新任务状态
                $this->getTaskScanner()->updateTaskStatus(
                    $task['file'],
                    $task['line'],
                    'completed'
                );
                $this->log("📝 自动更新任务状态为完成: {$task['text']}");
                break;
                
            case 'dispatch_agent':
                // 代码未完成，派发给 Agent
                $agentId = $task['agent_id'] ?? $this->generateAgentId($task);
                
                $this->log("\n🔔 检测到任务-代码不同步:");
                $this->log("   任务: {$task['text']}");
                $this->log("   问题: {$gap['message']}");
                
                // 派发任务
                $dispatched = $this->getAgentDispatcher()->dispatch($agentId, $task, $match);
                
                if ($dispatched) {
                    $this->log("🚀 已派发任务给 {$agentId}");
                }
                break;
        }
    }
    
    /**
     * 生成 Agent ID
     */
    private function generateAgentId(array $task): string
    {
        // 从任务文本中提取关键词
        $text = $task['text'];
        
        // 尝试识别模块名
        if (!empty($task['module'])) {
            $parts = explode('_', $task['module']);
            return 'Agent_' . end($parts);
        }
        
        // 从任务文本提取
        if (preg_match('/(?:实现|修复|添加|创建|更新)\s*(\w+)/u', $text, $match)) {
            return 'Agent_' . $match[1];
        }
        
        // 默认
        return 'Agent_General';
    }
    
    /**
     * 文件变化处理
     */
    private function onFileChanged(string $file): void
    {
        $relativePath = $this->getRelativePath($file);
        $this->log("\n[!] 检测到文件变化: {$relativePath}");
        $this->log('    时间: ' . date('Y-m-d H:i:s'));
        
        $retryCount = 0;
        $success = false;
        
        while (!$success && $retryCount < $this->maxRetries) {
            $retryCount++;
            
            if ($retryCount > 1) {
                $this->log("    [第 {$retryCount} 次尝试修复]");
            }
            
            $result = $this->analyzeAndFix($file);
            $success = $result['success'];
            
            if (!$success && $retryCount >= $this->maxRetries) {
                $this->log("⚠️ 连续 {$this->maxRetries} 次修复失败，停止尝试");
                $this->log('    请手动检查文件: ' . $relativePath);
            }
        }
    }
    
    /**
     * 分析并修复文件
     */
    private function analyzeAndFix(string $file): array
    {
        $result = ['success' => true, 'message' => ''];
        
        // 1. 语法检查
        $this->log('🔍 语法检查中...');
        $lintResult = $this->codeAnalyzer->lintFile($file);
        
        if (!$lintResult['valid']) {
            $this->log('❌ 发现语法错误:');
            $this->log('    ' . $lintResult['error']);
            
            if ($this->aiFixer->isEnabled()) {
                $this->log('🤖 启动 AI 自动修复...');
                $fixResult = $this->aiFixer->fixSyntaxError($file, $lintResult['error']);
                
                if ($fixResult['success']) {
                    $this->log('✅ AI 修复成功，验证中...');
                    $verifyResult = $this->codeAnalyzer->lintFile($file);
                    
                    if ($verifyResult['valid']) {
                        $this->log('✅ 修复后语法检查通过');
                    } else {
                        $result['success'] = false;
                        $result['message'] = '修复后仍有语法错误';
                    }
                } else {
                    $result['success'] = false;
                    $result['message'] = 'AI 修复失败: ' . $fixResult['error'];
                    $this->log('❌ AI 修复失败: ' . $fixResult['error']);
                }
            } else {
                $result['success'] = false;
                $result['message'] = '语法错误，AI 修复未启用';
            }
            
            return $result;
        }
        
        $this->log('✅ 语法检查通过');
        
        // 2. 逻辑验证（可选）
        if ($this->verbose) {
            $this->log('🔍 逻辑验证中...');
            $logicResult = $this->codeAnalyzer->checkLogic($file);
            
            if (!$logicResult['valid']) {
                $this->log('⚠️ 发现潜在问题:');
                foreach ($logicResult['warnings'] as $warning) {
                    $this->log('    - ' . $warning);
                }
            } else {
                $this->log('✅ 逻辑验证通过');
            }
        }
        
        $this->log('✅ 审查通过');
        return $result;
    }
    
    /**
     * 获取相对路径
     */
    private function getRelativePath(string $file): string
    {
        $basePath = BP;
        if (str_starts_with($file, $basePath)) {
            return substr($file, strlen($basePath));
        }
        return $file;
    }
    
    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        echo $message . PHP_EOL;
        
        // 同时写入日志文件
        $logFile = BP . 'var/log/cursor-supervisor.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] " . strip_tags($message) . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    /**
     * 检查进程是否在运行
     */
    public static function isRunning(): bool
    {
        $processName = '--name=' . self::PROCESS_NAME;
        $pid = (int) Processer::getData($processName, 'pid');
        
        if ($pid > 0 && Processer::isRunningByPid($pid)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取进程 PID
     */
    public static function getPid(): int
    {
        $processName = '--name=' . self::PROCESS_NAME;
        return (int) Processer::getData($processName, 'pid');
    }
}
