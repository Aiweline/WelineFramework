<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 任务完成检测器
 * 
 * 功能：
 * 1. 监测代码中的 @Status: Completed 标记
 * 2. 监测 mission.json 状态变化
 * 3. 检测 SUPERVISOR_TASK 信号弹是否被删除
 * 4. 自动更新文档中的任务状态
 * 5. 清理已完成任务的标记
 */
class TaskCompletionDetector
{
    private ?DocumentTaskScanner $taskScanner = null;
    private ?AgentDispatcher $dispatcher = null;
    
    /**
     * 获取任务扫描器
     */
    private function getTaskScanner(): DocumentTaskScanner
    {
        if ($this->taskScanner === null) {
            $this->taskScanner = ObjectManager::getInstance(DocumentTaskScanner::class);
        }
        return $this->taskScanner;
    }
    
    /**
     * 获取调度器
     */
    private function getDispatcher(): AgentDispatcher
    {
        if ($this->dispatcher === null) {
            $this->dispatcher = ObjectManager::getInstance(AgentDispatcher::class);
        }
        return $this->dispatcher;
    }
    
    /**
     * 检测文件中的任务完成状态
     */
    public function detectCompletion(string $filePath): array
    {
        $completions = [];
        
        if (!file_exists($filePath)) {
            return $completions;
        }
        
        $content = file_get_contents($filePath);
        
        // 检测 @Status: Completed 标记
        $pattern = '/@Status:\s*Completed\s+by\s+(\w+)/i';
        
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => $match) {
                $agentId = $match[0];
                $lineNumber = $this->getLineNumber($content, $matches[0][$index][1]);
                
                $completions[] = [
                    'agent_id' => $agentId,
                    'file' => $filePath,
                    'line' => $lineNumber,
                    'type' => 'status_marker',
                ];
            }
        }
        
        return $completions;
    }
    
    /**
     * 扫描模块中的完成状态
     */
    public function scanModuleCompletions(string $modulePath): array
    {
        $allCompletions = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            
            $completions = $this->detectCompletion($file->getRealPath());
            if (!empty($completions)) {
                $allCompletions = array_merge($allCompletions, $completions);
            }
        }
        
        return $allCompletions;
    }
    
    /**
     * 处理完成的任务
     * 
     * @param array $completions 完成列表
     * @param array $tasks 原始任务列表
     */
    public function processCompletions(array $completions, array $tasks): array
    {
        $processed = [];
        
        foreach ($completions as $completion) {
            $agentId = $completion['agent_id'];
            
            // 查找对应的任务
            foreach ($tasks as $task) {
                if (($task['agent_id'] ?? '') === $agentId || 
                    ($task['code_id'] ?? '') === $agentId) {
                    
                    // 更新任务状态
                    if ($task['status'] !== 'completed') {
                        $updated = $this->getTaskScanner()->updateTaskStatus(
                            $task['file'],
                            $task['line'],
                            'completed'
                        );
                        
                        if ($updated) {
                            $processed[] = [
                                'task' => $task,
                                'completion' => $completion,
                                'action' => 'marked_completed',
                            ];
                            
                            $this->log("✅ 任务已自动标记完成: {$task['text']}");
                        }
                    }
                    
                    // 清理 Agent 标记
                    $this->cleanupCompletionMarkers($completion);
                    
                    // 解锁 Agent
                    $this->getDispatcher()->unlockAgent($agentId);
                }
            }
        }
        
        return $processed;
    }
    
    /**
     * 清理完成标记
     */
    private function cleanupCompletionMarkers(array $completion): void
    {
        $filePath = $completion['file'];
        $agentId = $completion['agent_id'];
        
        if (!file_exists($filePath)) {
            return;
        }
        
        $content = file_get_contents($filePath);
        $modified = false;
        
        // 1. 移除 @Status: Completed 标记（保留功能代码）
        $pattern = '/\s*\/\/\s*@Status:\s*Completed\s+by\s+' . preg_quote($agentId, '/') . '\s*/i';
        $newContent = preg_replace($pattern, "\n", $content);
        if ($newContent !== $content) {
            $content = $newContent;
            $modified = true;
        }
        
        // 2. 移除多行注释形式的完成标记
        $pattern = '/\/\*\*?\s*@Status:\s*Completed\s+by\s+' . preg_quote($agentId, '/') . '\s*\*\//i';
        $newContent = preg_replace($pattern, '', $content);
        if ($newContent !== $content) {
            $content = $newContent;
            $modified = true;
        }
        
        // 3. 移除 SUPERVISOR_TASK 信号弹（如果还存在）
        $this->getDispatcher()->cleanupAgentMarker($agentId, $filePath);
        
        if ($modified) {
            // 清理多余的空行
            $content = preg_replace('/\n{3,}/', "\n\n", $content);
            file_put_contents($filePath, $content);
            
            $this->log("🧹 已清理 {$agentId} 的完成标记");
        }
    }
    
    /**
     * 检测 Agent 标记是否被手动删除（表示任务完成但未添加 Status）
     */
    public function detectManualCompletion(array $activeAgents): array
    {
        $manualCompletions = [];
        
        foreach ($activeAgents as $agentId => $lockInfo) {
            $filePath = $lockInfo['file'] ?? null;
            
            if (empty($filePath) || !file_exists($filePath)) {
                continue;
            }
            
            $content = file_get_contents($filePath);
            
            // 检查 SUPERVISOR_TASK 或 @TargetAgent 标记是否还存在
            $hasSupervisorTask = str_contains($content, '[SUPERVISOR_TASK]');
            $hasTargetAgent = str_contains($content, "@AgentID: {$agentId}");
            
            if (!$hasSupervisorTask && !$hasTargetAgent) {
                // 标记已被删除，视为手动完成
                $manualCompletions[] = [
                    'agent_id' => $agentId,
                    'file' => $filePath,
                    'type' => 'manual_completion',
                ];
            }
        }
        
        // 同时检查 mission.json 状态
        foreach ($activeAgents as $agentId => $lockInfo) {
            $status = $this->getDispatcher()->checkTaskStatus($agentId);
            if ($status['completed'] && !in_array($agentId, array_column($manualCompletions, 'agent_id'))) {
                $manualCompletions[] = [
                    'agent_id' => $agentId,
                    'file' => $lockInfo['file'] ?? '',
                    'type' => 'mission_completed',
                ];
            }
        }
        
        return $manualCompletions;
    }
    
    /**
     * 获取行号
     */
    private function getLineNumber(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }
    
    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        echo "[TaskCompletionDetector] {$message}" . PHP_EOL;
        
        $logFile = BP . 'var/log/task-completion.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
