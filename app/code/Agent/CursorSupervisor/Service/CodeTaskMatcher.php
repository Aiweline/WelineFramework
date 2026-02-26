<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

/**
 * 代码任务匹配器
 * 
 * 检测代码是否与文档任务匹配，识别差异和缺失
 */
class CodeTaskMatcher
{
    /**
     * 检查方法是否存在于文件中
     */
    public function methodExists(string $filePath, string $methodName): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $content = file_get_contents($filePath);
        
        // 检查方法定义
        $pattern = '/(?:public|protected|private|static|\s)+function\s+' . preg_quote($methodName, '/') . '\s*\(/';
        return (bool) preg_match($pattern, $content);
    }
    
    /**
     * 检查方法是否有实现（非空方法体）
     */
    public function methodHasImplementation(string $filePath, string $methodName): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $content = file_get_contents($filePath);
        
        // 使用更精确的方法体检测
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{([^}]*)\}/s';
        
        if (preg_match($pattern, $content, $matches)) {
            $body = trim($matches[1]);
            
            // 检查是否为空或只有占位代码
            if (empty($body)) {
                return false;
            }
            
            // 检查是否只有 TODO/FIXME 注释
            $cleanBody = preg_replace('/\/\/.*TODO.*|\/\/.*FIXME.*|\/\*.*TODO.*\*\/|\/\*.*FIXME.*\*\//is', '', $body);
            $cleanBody = preg_replace('/\/\/\s*\.{3}|\/\*\s*\.{3}\s*\*\//', '', $cleanBody);
            $cleanBody = trim($cleanBody);
            
            if (empty($cleanBody)) {
                return false;
            }
            
            // 检查是否只有 throw new \Exception 占位
            if (preg_match('/^\s*throw\s+new\s+\\\\?Exception\s*\([\'"].*not\s*implemented/i', $cleanBody)) {
                return false;
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 检查类是否存在
     */
    public function classExists(string $filePath, string $className): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $content = file_get_contents($filePath);
        $pattern = '/(?:class|interface|trait)\s+' . preg_quote($className, '/') . '(?:\s|{)/';
        
        return (bool) preg_match($pattern, $content);
    }
    
    /**
     * 检查文件是否包含指定的 CodeID 标记
     */
    public function hasCodeId(string $filePath, string $codeId): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $content = file_get_contents($filePath);
        $pattern = '/@CodeID:\s*' . preg_quote($codeId, '/') . '/';
        
        return (bool) preg_match($pattern, $content);
    }
    
    /**
     * 获取文件中所有的 CodeID
     */
    public function getCodeIds(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        $codeIds = [];
        
        if (preg_match_all('/@CodeID:\s*(\w+)/', $content, $matches)) {
            $codeIds = $matches[1];
        }
        
        return $codeIds;
    }
    
    /**
     * 检查任务是否已在代码中完成
     * 
     * @param array $task 任务信息
     * @param string $modulePath 模块路径
     * @return array 匹配结果
     */
    public function matchTask(array $task, string $modulePath): array
    {
        $result = [
            'matched' => false,
            'implemented' => false,
            'file_exists' => false,
            'method_exists' => false,
            'code_id_found' => false,
            'issues' => [],
            'target_file' => null,
            'target_line' => null,
        ];
        
        // 如果有指定目标文件
        if (!empty($task['target_file'])) {
            $targetFile = $this->resolveFilePath($task['target_file'], $modulePath);
            $result['target_file'] = $targetFile;
            
            if (file_exists($targetFile)) {
                $result['file_exists'] = true;
                
                // 检查方法
                if (!empty($task['target_method'])) {
                    $result['method_exists'] = $this->methodExists($targetFile, $task['target_method']);
                    
                    if ($result['method_exists']) {
                        $result['implemented'] = $this->methodHasImplementation($targetFile, $task['target_method']);
                        $result['target_line'] = $this->getMethodLine($targetFile, $task['target_method']);
                        
                        if (!$result['implemented']) {
                            $result['issues'][] = "方法 {$task['target_method']} 存在但未实现";
                        }
                    } else {
                        $result['issues'][] = "方法 {$task['target_method']} 不存在";
                    }
                } else {
                    $result['matched'] = true;
                    $result['implemented'] = true;
                }
            } else {
                $result['issues'][] = "文件 {$task['target_file']} 不存在";
            }
        }
        
        // 检查 CodeID
        if (!empty($task['code_id'])) {
            // 在模块中搜索 CodeID
            $foundIn = $this->findCodeIdInModule($task['code_id'], $modulePath);
            
            if (!empty($foundIn)) {
                $result['code_id_found'] = true;
                $result['target_file'] = $foundIn['file'];
                $result['target_line'] = $foundIn['line'];
                
                // 检查 CodeID 对应的代码块是否完整
                if (!$foundIn['completed']) {
                    $result['issues'][] = "CodeID {$task['code_id']} 标记的代码未完成";
                } else {
                    $result['implemented'] = true;
                }
            } else {
                $result['issues'][] = "未找到 CodeID {$task['code_id']}";
            }
        }
        
        // 确定最终匹配状态
        $result['matched'] = $result['file_exists'] || $result['code_id_found'];
        
        return $result;
    }
    
    /**
     * 解析文件路径
     */
    private function resolveFilePath(string $relativePath, string $modulePath): string
    {
        // 如果是绝对路径
        if (str_starts_with($relativePath, '/') || preg_match('/^[A-Z]:/i', $relativePath)) {
            return $relativePath;
        }
        
        // 相对于模块路径
        return rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    }
    
    /**
     * 获取方法所在行号
     */
    private function getMethodLine(string $filePath, string $methodName): ?int
    {
        if (!file_exists($filePath)) {
            return null;
        }
        
        $lines = file($filePath);
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\(/';
        
        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line)) {
                return $index + 1;
            }
        }
        
        return null;
    }
    
    /**
     * 在模块中查找 CodeID
     */
    private function findCodeIdInModule(string $codeId, string $modulePath): array
    {
        $result = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            
            $filePath = $file->getRealPath();
            $content = file_get_contents($filePath);
            
            // 搜索 CodeID 标记
            $pattern = '/@CodeID:\s*' . preg_quote($codeId, '/') . '/';
            
            if (preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
                $lineNumber = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;
                
                // 检查该 CodeID 标记的代码是否完成
                $completed = !preg_match(
                    '/@CodeID:\s*' . preg_quote($codeId, '/') . '.*?@Status:\s*Pending/is',
                    $content
                );
                
                // 检查是否有 @Status: Completed 标记
                if (preg_match('/@CodeID:\s*' . preg_quote($codeId, '/') . '.*?@Status:\s*Completed/is', $content)) {
                    $completed = true;
                }
                
                return [
                    'file' => $filePath,
                    'line' => $lineNumber,
                    'completed' => $completed,
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * 分析任务与代码的差异
     * 
     * @param array $tasks 任务列表
     * @param string $modulePath 模块路径
     * @return array 差异报告
     */
    public function analyzeTaskCodeGap(array $tasks, string $modulePath): array
    {
        $gaps = [];
        
        foreach ($tasks as $task) {
            if ($task['status'] === 'completed' || $task['status'] === 'cancelled') {
                continue;
            }
            
            $matchResult = $this->matchTask($task, $modulePath);
            
            // 如果任务标记为进行中但代码已完成
            if ($task['status'] === 'in_progress' && $matchResult['implemented']) {
                $gaps[] = [
                    'type' => 'task_behind_code',
                    'task' => $task,
                    'match' => $matchResult,
                    'message' => '任务标记为进行中，但代码已实现',
                    'action' => 'update_task_status',
                ];
            }
            // 如果任务未完成且代码也未完成
            elseif (!$matchResult['implemented'] && !empty($matchResult['issues'])) {
                $gaps[] = [
                    'type' => 'code_behind_task',
                    'task' => $task,
                    'match' => $matchResult,
                    'message' => '任务要求的代码未实现: ' . implode('; ', $matchResult['issues']),
                    'action' => 'dispatch_agent',
                ];
            }
        }
        
        return $gaps;
    }
}
