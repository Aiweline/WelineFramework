<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

/**
 * 文档任务扫描器
 * 
 * 扫描模块 doc/ 目录下的计划和任务文件，解析任务状态
 * 
 * 支持的任务状态标记：
 * - [ ] 未开始
 * - [/] 进行中
 * - [x] 已完成
 * - [-] 已取消
 */
class DocumentTaskScanner
{
    private array $taskPatterns = [
        'unchecked' => '/^[\s]*-\s*\[\s*\]\s*(.+)$/m',
        'in_progress' => '/^[\s]*-\s*\[\/\]\s*(.+)$/m',
        'completed' => '/^[\s]*-\s*\[x\]\s*(.+)$/mi',
        'cancelled' => '/^[\s]*-\s*\[-\]\s*(.+)$/m',
    ];
    
    private array $taskFiles = [
        'plan.md',
        'task.md',
        'TODO.md',
        'PLAN.md',
        'TASK.md',
    ];
    
    /**
     * 扫描模块的文档任务
     * 
     * @param string $modulePath 模块路径
     * @return array 任务列表
     */
    public function scanModuleTasks(string $modulePath): array
    {
        $tasks = [];
        $docPath = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'doc';
        
        if (!is_dir($docPath)) {
            return $tasks;
        }
        
        // 递归扫描 doc 目录
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($docPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            
            $filename = $file->getFilename();
            $extension = strtolower($file->getExtension());
            
            // 只处理 markdown 文件
            if ($extension !== 'md') {
                continue;
            }
            
            $fileTasks = $this->parseTaskFile($file->getRealPath());
            if (!empty($fileTasks)) {
                $tasks[$file->getRealPath()] = $fileTasks;
            }
        }
        
        return $tasks;
    }
    
    /**
     * 扫描所有模块的文档任务
     * 
     * @param string $appCodePath app/code 路径
     * @return array 按模块分组的任务
     */
    public function scanAllModuleTasks(string $appCodePath): array
    {
        $allTasks = [];
        
        if (!is_dir($appCodePath)) {
            return $allTasks;
        }
        
        // 遍历 Vendor 目录
        $vendors = new \DirectoryIterator($appCodePath);
        foreach ($vendors as $vendor) {
            if ($vendor->isDot() || !$vendor->isDir()) {
                continue;
            }
            
            // 遍历 Module 目录
            $modules = new \DirectoryIterator($vendor->getRealPath());
            foreach ($modules as $module) {
                if ($module->isDot() || !$module->isDir()) {
                    continue;
                }
                
                $moduleName = $vendor->getFilename() . '_' . $module->getFilename();
                $modulePath = $module->getRealPath();
                
                $tasks = $this->scanModuleTasks($modulePath);
                if (!empty($tasks)) {
                    $allTasks[$moduleName] = [
                        'path' => $modulePath,
                        'tasks' => $tasks,
                    ];
                }
            }
        }
        
        return $allTasks;
    }
    
    /**
     * 解析任务文件
     * 
     * @param string $filePath 文件路径
     * @return array 任务列表
     */
    public function parseTaskFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        $tasks = [];
        
        foreach ($this->taskPatterns as $status => $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $index => $match) {
                    $taskText = trim($match[0]);
                    $lineNumber = $this->getLineNumber($content, $matches[0][$index][1]);
                    
                    // 解析任务详情
                    $taskInfo = $this->parseTaskInfo($taskText);
                    
                    $tasks[] = [
                        'status' => $status,
                        'text' => $taskText,
                        'line' => $lineNumber,
                        'file' => $filePath,
                        'agent_id' => $taskInfo['agent_id'],
                        'target_file' => $taskInfo['target_file'],
                        'target_method' => $taskInfo['target_method'],
                        'code_id' => $taskInfo['code_id'],
                        'priority' => $taskInfo['priority'],
                    ];
                }
            }
        }
        
        return $tasks;
    }
    
    /**
     * 获取行号
     */
    private function getLineNumber(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }
    
    /**
     * 解析任务信息
     * 
     * 支持的格式：
     * - [ ] 实现用户认证 @Agent:Auth @File:src/Auth.php @Method:checkAuth
     * - [ ] [P1] 紧急修复登录问题 @CodeID:AUTH_001
     */
    private function parseTaskInfo(string $taskText): array
    {
        $info = [
            'agent_id' => null,
            'target_file' => null,
            'target_method' => null,
            'code_id' => null,
            'priority' => 'normal',
        ];
        
        // 解析 Agent ID
        if (preg_match('/@Agent:(\w+)/', $taskText, $match)) {
            $info['agent_id'] = 'Agent_' . $match[1];
        }
        
        // 解析目标文件
        if (preg_match('/@File:([^\s@]+)/', $taskText, $match)) {
            $info['target_file'] = $match[1];
        }
        
        // 解析目标方法
        if (preg_match('/@Method:(\w+)/', $taskText, $match)) {
            $info['target_method'] = $match[1];
        }
        
        // 解析代码 ID
        if (preg_match('/@CodeID:(\w+)/', $taskText, $match)) {
            $info['code_id'] = $match[1];
        }
        
        // 解析优先级
        if (preg_match('/\[P([1-5])\]/', $taskText, $match)) {
            $priorities = ['1' => 'critical', '2' => 'high', '3' => 'normal', '4' => 'low', '5' => 'trivial'];
            $info['priority'] = $priorities[$match[1]] ?? 'normal';
        }
        
        return $info;
    }
    
    /**
     * 获取进行中的任务
     */
    public function getInProgressTasks(array $allTasks): array
    {
        $inProgress = [];
        
        foreach ($allTasks as $moduleName => $moduleData) {
            foreach ($moduleData['tasks'] as $file => $tasks) {
                foreach ($tasks as $task) {
                    if ($task['status'] === 'in_progress') {
                        $task['module'] = $moduleName;
                        $task['module_path'] = $moduleData['path'];
                        $inProgress[] = $task;
                    }
                }
            }
        }
        
        // 按优先级排序
        usort($inProgress, function ($a, $b) {
            $priorityOrder = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3, 'trivial' => 4];
            return ($priorityOrder[$a['priority']] ?? 2) <=> ($priorityOrder[$b['priority']] ?? 2);
        });
        
        return $inProgress;
    }
    
    /**
     * 获取未完成的任务
     */
    public function getUncompletedTasks(array $allTasks): array
    {
        $uncompleted = [];
        
        foreach ($allTasks as $moduleName => $moduleData) {
            foreach ($moduleData['tasks'] as $file => $tasks) {
                foreach ($tasks as $task) {
                    if (in_array($task['status'], ['unchecked', 'in_progress'])) {
                        $task['module'] = $moduleName;
                        $task['module_path'] = $moduleData['path'];
                        $uncompleted[] = $task;
                    }
                }
            }
        }
        
        return $uncompleted;
    }
    
    /**
     * 更新任务状态
     * 
     * @param string $filePath 文件路径
     * @param int $line 行号
     * @param string $newStatus 新状态 (unchecked, in_progress, completed, cancelled)
     */
    public function updateTaskStatus(string $filePath, int $line, string $newStatus): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $lines = file($filePath);
        if (!isset($lines[$line - 1])) {
            return false;
        }
        
        $statusMarkers = [
            'unchecked' => '[ ]',
            'in_progress' => '[/]',
            'completed' => '[x]',
            'cancelled' => '[-]',
        ];
        
        $marker = $statusMarkers[$newStatus] ?? '[ ]';
        
        // 替换状态标记
        $lines[$line - 1] = preg_replace(
            '/\[[\s\/x-]\]/',
            $marker,
            $lines[$line - 1]
        );
        
        return file_put_contents($filePath, implode('', $lines)) !== false;
    }
}
