<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 自动任务生成器服务
 * 
 * 职责：
 * 1. 监控文件变化
 * 2. 分析变化是否符合规则
 * 3. 自动生成修复任务
 * 4. 调用 CLI 完成任务
 */
class AutoTaskGeneratorService
{
    private ?RuleAnalyzerService $ruleAnalyzer = null;
    private ?ComplianceCheckerService $complianceChecker = null;
    private ?CodeBackupService $backupService = null;
    private ?TaskPoolService $taskPool = null;
    
    private bool $verbose = false;
    private bool $autoFix = true;
    private bool $autoBackup = true;
    private array $watchedFiles = [];
    private array $fileTimestamps = [];
    
    /**
     * 设置详细输出
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }
    
    /**
     * 设置是否自动修复
     */
    public function setAutoFix(bool $autoFix): self
    {
        $this->autoFix = $autoFix;
        return $this;
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
     * 获取服务实例
     */
    private function getRuleAnalyzer(): RuleAnalyzerService
    {
        if ($this->ruleAnalyzer === null) {
            $this->ruleAnalyzer = ObjectManager::getInstance(RuleAnalyzerService::class);
        }
        return $this->ruleAnalyzer;
    }
    
    private function getComplianceChecker(): ComplianceCheckerService
    {
        if ($this->complianceChecker === null) {
            $this->complianceChecker = ObjectManager::getInstance(ComplianceCheckerService::class);
            $this->complianceChecker->setVerbose($this->verbose);
        }
        return $this->complianceChecker;
    }
    
    private function getBackupService(): CodeBackupService
    {
        if ($this->backupService === null) {
            $this->backupService = ObjectManager::getInstance(CodeBackupService::class);
            $this->backupService->setVerbose($this->verbose);
        }
        return $this->backupService;
    }
    
    private function getTaskPool(): TaskPoolService
    {
        if ($this->taskPool === null) {
            $this->taskPool = ObjectManager::getInstance(TaskPoolService::class);
        }
        return $this->taskPool;
    }
    
    /**
     * 处理文件变更
     */
    public function processFileChange(string $filePath): array
    {
        $result = [
            'file' => $filePath,
            'backed_up' => false,
            'violations' => [],
            'tasks_generated' => [],
            'auto_fixed' => [],
        ];
        
        if (!file_exists($filePath)) {
            return $result;
        }
        
        // 1. 备份文件
        if ($this->autoBackup) {
            $backupPath = $this->getBackupService()->backupFile($filePath);
            $result['backed_up'] = $backupPath !== null;
            if ($backupPath) {
                $this->log("📦 已备份: {$filePath}");
            }
        }
        
        // 2. 检查合规性
        $compliance = $this->getComplianceChecker()->checkFile($filePath);
        $result['violations'] = $compliance['violations'];
        
        if ($compliance['compliant']) {
            $this->log("✅ 文件合规: {$filePath}");
            return $result;
        }
        
        $this->log("⚠️ 发现 " . count($compliance['violations']) . " 个违规项");
        
        // 3. 生成修复任务
        $tasks = $this->generateTasksFromViolations($filePath, $compliance['violations']);
        $result['tasks_generated'] = $tasks;
        
        // 4. 如果启用自动修复，尝试修复简单问题
        if ($this->autoFix) {
            $fixed = $this->attemptAutoFix($filePath, $compliance['violations']);
            $result['auto_fixed'] = $fixed;
        }
        
        return $result;
    }
    
    /**
     * 从违规项生成任务
     */
    private function generateTasksFromViolations(string $filePath, array $violations): array
    {
        $tasks = [];
        $this->getTaskPool()->load();
        
        // 按类型分组违规项
        $byType = [];
        foreach ($violations as $violation) {
            $type = $violation['type'];
            $byType[$type][] = $violation;
        }
        
        foreach ($byType as $type => $typeViolations) {
            $task = $this->createTaskFromViolationType($filePath, $type, $typeViolations);
            if ($task) {
                $this->getTaskPool()->addTask($task);
                $tasks[] = $task;
                $this->log("📋 生成任务: {$task['id']} - {$task['description']}");
            }
        }
        
        $this->getTaskPool()->save();
        
        return $tasks;
    }
    
    /**
     * 根据违规类型创建任务
     */
    private function createTaskFromViolationType(string $filePath, string $type, array $violations): ?array
    {
        $relativePath = $this->getRelativePath($filePath);
        $count = count($violations);
        $rule = $violations[0]['rule'] ?? 'unknown';
        
        $taskTemplates = [
            'css_hardcoded_color' => [
                'agent' => 'Agent_Style',
                'description' => "修复 CSS 硬编码颜色 ({$count} 处)",
                'priority' => 'high',
            ],
            'css_generic_class' => [
                'agent' => 'Agent_Style',
                'description' => "重命名通用 CSS 类名 ({$count} 处)",
                'priority' => 'normal',
            ],
            'js_global_pollution' => [
                'agent' => 'Agent_JS',
                'description' => "修复 JS 全局污染，使用 IIFE 闭包",
                'priority' => 'high',
            ],
            'js_native_dialog' => [
                'agent' => 'Agent_JS',
                'description' => "替换原生对话框为 Toast 组件",
                'priority' => 'high',
            ],
            'phtml_hardcoded_text' => [
                'agent' => 'Agent_I18n',
                'description' => "国际化硬编码文案 ({$count} 处)",
                'priority' => 'normal',
            ],
            'phtml_global_function' => [
                'agent' => 'Agent_Template',
                'description' => "重构模板全局函数为闭包",
                'priority' => 'high',
            ],
            'php_error_log' => [
                'agent' => 'Agent_PHP',
                'description' => "替换 error_log() 为 w_log_error()",
                'priority' => 'high',
            ],
            'php_missing_fetch' => [
                'agent' => 'Agent_PHP',
                'description' => "添加缺失的 fetch() 调用",
                'priority' => 'critical',
            ],
        ];
        
        $template = $taskTemplates[$type] ?? [
            'agent' => 'Agent_Fix',
            'description' => "修复违规: {$type} ({$count} 处)",
            'priority' => 'normal',
        ];
        
        $agentId = $template['agent'] . '_' . substr(md5($filePath . $type), 0, 6);
        
        return [
            'id' => $agentId,
            'file' => $relativePath,
            'description' => $template['description'],
            'priority' => $template['priority'],
            'dep' => null,
            'violation_type' => $type,
            'violations' => $violations,
            'rule' => $rule,
            'auto_generated' => true,
        ];
    }
    
    /**
     * 尝试自动修复
     */
    private function attemptAutoFix(string $filePath, array $violations): array
    {
        $fixed = [];
        $content = file_get_contents($filePath);
        $modified = false;
        
        foreach ($violations as $violation) {
            $type = $violation['type'];
            
            // 只自动修复简单的问题
            switch ($type) {
                case 'php_error_log':
                    // 替换 error_log() 为 w_log_error()
                    $newContent = preg_replace(
                        '/\berror_log\s*\(/',
                        'w_log_error(',
                        $content
                    );
                    if ($newContent !== $content) {
                        $content = $newContent;
                        $modified = true;
                        $fixed[] = $type;
                        $this->log("🔧 自动修复: error_log -> w_log_error");
                    }
                    break;
            }
        }
        
        if ($modified) {
            file_put_contents($filePath, $content);
        }
        
        return $fixed;
    }
    
    /**
     * 监控目录变化
     */
    public function watchDirectory(string $dirPath, array $extensions = ['php', 'phtml', 'css', 'js']): void
    {
        $this->log("👀 开始监控目录: {$dirPath}");
        $this->log("   扩展名: " . implode(', ', $extensions));
        
        // 初始化文件时间戳
        $this->initializeFileTimestamps($dirPath, $extensions);
        
        while (true) {
            $changes = $this->detectChanges($dirPath, $extensions);
            
            foreach ($changes as $change) {
                $this->log("\n🔄 检测到变更: {$change['file']} ({$change['type']})");
                
                if ($change['type'] === 'modified' || $change['type'] === 'created') {
                    $this->processFileChange($change['file']);
                }
            }
            
            sleep(1);
        }
    }
    
    /**
     * 单次检查目录
     */
    public function checkDirectory(string $dirPath, array $extensions = ['php', 'phtml', 'css', 'js']): array
    {
        $results = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            
            $ext = $file->getExtension();
            if (!in_array($ext, $extensions)) {
                continue;
            }
            
            // 跳过 vendor、generated、var 等目录
            $path = $file->getPathname();
            if (preg_match('/(vendor|generated|var|node_modules)[\/\\\\]/i', $path)) {
                continue;
            }
            
            $result = $this->processFileChange($path);
            if (!empty($result['violations'])) {
                $results[$path] = $result;
            }
        }
        
        return $results;
    }
    
    /**
     * 执行任务（调用 CLI）
     */
    public function executeTask(array $task): array
    {
        $this->log("🚀 执行任务: {$task['id']}");
        
        // 构建 CLI 命令
        $command = $this->buildCliCommand($task);
        
        if (!$command) {
            return ['success' => false, 'error' => '无法构建命令'];
        }
        
        $this->log("   命令: {$command}");
        
        // 执行命令
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        $result = [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'return_code' => $returnCode,
        ];
        
        if ($result['success']) {
            $this->log("✅ 任务完成");
        } else {
            $this->log("❌ 任务失败: {$result['output']}");
        }
        
        return $result;
    }
    
    /**
     * 构建 CLI 命令
     */
    private function buildCliCommand(array $task): ?string
    {
        $type = $task['violation_type'] ?? '';
        $file = $task['file'] ?? '';
        
        // 基于任务类型构建不同命令
        switch ($type) {
            case 'php_error_log':
            case 'php_missing_fetch':
            case 'phtml_global_function':
                // 这些需要代码修改，通过 Cursor 完成
                return null;
                
            default:
                // 默认派发给 Cursor Orchestrator
                return "php bin/w cursor:orchestrator:task dispatch";
        }
    }
    
    /**
     * 初始化文件时间戳
     */
    private function initializeFileTimestamps(string $dirPath, array $extensions): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            
            $ext = $file->getExtension();
            if (!in_array($ext, $extensions)) {
                continue;
            }
            
            $path = $file->getPathname();
            $this->fileTimestamps[$path] = $file->getMTime();
        }
        
        $this->log("   监控 " . count($this->fileTimestamps) . " 个文件");
    }
    
    /**
     * 检测变化
     */
    private function detectChanges(string $dirPath, array $extensions): array
    {
        $changes = [];
        $currentFiles = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            
            $ext = $file->getExtension();
            if (!in_array($ext, $extensions)) {
                continue;
            }
            
            $path = $file->getPathname();
            $mtime = $file->getMTime();
            $currentFiles[$path] = $mtime;
            
            if (!isset($this->fileTimestamps[$path])) {
                $changes[] = ['file' => $path, 'type' => 'created'];
                $this->fileTimestamps[$path] = $mtime;
            } elseif ($this->fileTimestamps[$path] < $mtime) {
                $changes[] = ['file' => $path, 'type' => 'modified'];
                $this->fileTimestamps[$path] = $mtime;
            }
        }
        
        // 检测删除的文件
        foreach ($this->fileTimestamps as $path => $mtime) {
            if (!isset($currentFiles[$path])) {
                $changes[] = ['file' => $path, 'type' => 'deleted'];
                unset($this->fileTimestamps[$path]);
            }
        }
        
        return $changes;
    }
    
    /**
     * 获取相对路径
     */
    private function getRelativePath(string $filePath): string
    {
        $filePath = str_replace('\\', '/', $filePath);
        $basePath = str_replace('\\', '/', BP);
        
        if (str_starts_with($filePath, $basePath)) {
            return substr($filePath, strlen($basePath));
        }
        
        return $filePath;
    }
    
    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[AutoTask] {$message}\n";
        }
        
        $logFile = BP . 'var/log/auto-task-generator.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
