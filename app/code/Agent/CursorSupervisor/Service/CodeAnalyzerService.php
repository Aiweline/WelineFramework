<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

/**
 * 代码分析服务
 * 
 * 功能：
 * 1. PHP 语法检查（php -l）
 * 2. 逻辑验证（检测常见问题模式）
 * 3. 代码质量检查
 */
class CodeAnalyzerService
{
    /**
     * 语法检查
     * 
     * @param string $file 文件路径
     * @return array ['valid' => bool, 'error' => string|null, 'output' => array]
     */
    public function lintFile(string $file): array
    {
        if (!file_exists($file)) {
            return [
                'valid' => false,
                'error' => "文件不存在: {$file}",
                'output' => [],
            ];
        }
        
        $output = [];
        $returnVar = 0;
        
        // 使用 php -l 进行语法检查
        $command = 'php -l ' . escapeshellarg($file) . ' 2>&1';
        exec($command, $output, $returnVar);
        
        $outputText = implode("\n", $output);
        
        if ($returnVar !== 0) {
            // 提取错误信息
            $error = $this->extractLintError($outputText);
            
            return [
                'valid' => false,
                'error' => $error,
                'output' => $output,
            ];
        }
        
        return [
            'valid' => true,
            'error' => null,
            'output' => $output,
        ];
    }
    
    /**
     * 提取语法错误信息
     */
    private function extractLintError(string $output): string
    {
        // PHP 语法错误格式：Parse error: syntax error, ... in /path/to/file.php on line X
        if (preg_match('/Parse error:\s*(.+)/i', $output, $matches)) {
            return trim($matches[1]);
        }
        
        // Fatal error 格式
        if (preg_match('/Fatal error:\s*(.+)/i', $output, $matches)) {
            return trim($matches[1]);
        }
        
        return $output;
    }
    
    /**
     * 逻辑检查（检测常见问题模式）
     * 
     * @param string $file 文件路径
     * @return array ['valid' => bool, 'warnings' => array]
     */
    public function checkLogic(string $file): array
    {
        if (!file_exists($file)) {
            return [
                'valid' => false,
                'warnings' => ["文件不存在: {$file}"],
            ];
        }
        
        $content = file_get_contents($file);
        $warnings = [];
        
        // 检查过时函数
        $deprecatedFunctions = $this->checkDeprecatedFunctions($content);
        if (!empty($deprecatedFunctions)) {
            $warnings = array_merge($warnings, $deprecatedFunctions);
        }
        
        // 检查潜在的安全问题
        $securityIssues = $this->checkSecurityIssues($content);
        if (!empty($securityIssues)) {
            $warnings = array_merge($warnings, $securityIssues);
        }
        
        // 检查未完成的代码
        $todoIssues = $this->checkUnfinishedCode($content);
        if (!empty($todoIssues)) {
            $warnings = array_merge($warnings, $todoIssues);
        }
        
        return [
            'valid' => empty($warnings),
            'warnings' => $warnings,
        ];
    }
    
    /**
     * 检查过时函数
     */
    private function checkDeprecatedFunctions(string $content): array
    {
        $warnings = [];
        
        $deprecatedPatterns = [
            '/\bmysql_\w+\s*\(/' => '使用了已废弃的 mysql_* 函数，建议使用 PDO 或 mysqli',
            '/\bereg\s*\(/' => '使用了已废弃的 ereg 函数，建议使用 preg_match',
            '/\beregi\s*\(/' => '使用了已废弃的 eregi 函数，建议使用 preg_match with i modifier',
            '/\bsplit\s*\(/' => '使用了已废弃的 split 函数，建议使用 explode 或 preg_split',
            '/\bcreate_function\s*\(/' => '使用了已废弃的 create_function，建议使用匿名函数',
            '/\beach\s*\(/' => '使用了已废弃的 each 函数，建议使用 foreach',
        ];
        
        foreach ($deprecatedPatterns as $pattern => $message) {
            if (preg_match($pattern, $content)) {
                $warnings[] = $message;
            }
        }
        
        return $warnings;
    }
    
    /**
     * 检查安全问题
     */
    private function checkSecurityIssues(string $content): array
    {
        $warnings = [];
        
        $securityPatterns = [
            '/\beval\s*\(/' => '使用了 eval() 函数，存在代码注入风险',
            '/\b(shell_exec|exec|system|passthru)\s*\(\s*\$/' => '使用用户输入执行系统命令，存在命令注入风险',
            '/\binclude\s*\(\s*\$/' => '使用变量包含文件，存在文件包含漏洞风险',
            '/\brequire\s*\(\s*\$/' => '使用变量包含文件，存在文件包含漏洞风险',
            '/\bfile_get_contents\s*\(\s*\$_/' => '直接使用用户输入读取文件，存在安全风险',
            '/\bunserialize\s*\(\s*\$_/' => '直接反序列化用户输入，存在对象注入风险',
        ];
        
        foreach ($securityPatterns as $pattern => $message) {
            if (preg_match($pattern, $content)) {
                $warnings[] = $message;
            }
        }
        
        return $warnings;
    }
    
    /**
     * 检查未完成的代码
     */
    private function checkUnfinishedCode(string $content): array
    {
        $warnings = [];
        
        // 检查 TODO 和 FIXME 注释
        if (preg_match_all('/\/\/\s*(TODO|FIXME|XXX|HACK)[:：]?\s*(.+)/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = strtoupper($match[1]);
                $message = trim($match[2]);
                $warnings[] = "{$type}: {$message}";
            }
        }
        
        // 检查省略号代码（Cursor 可能留下的）
        if (preg_match('/\/\/\s*\.{3}|\/\*\s*\.{3}\s*\*\//', $content)) {
            $warnings[] = '发现省略号代码（...），可能是未完成的实现';
        }
        
        return $warnings;
    }
    
    /**
     * 运行代码并检测运行时错误
     * 
     * @param string $file 文件路径
     * @return array ['success' => bool, 'output' => string, 'errors' => array]
     */
    public function runFile(string $file): array
    {
        if (!file_exists($file)) {
            return [
                'success' => false,
                'output' => '',
                'errors' => ["文件不存在: {$file}"],
            ];
        }
        
        $output = [];
        $returnVar = 0;
        
        // 运行文件
        $command = 'php ' . escapeshellarg($file) . ' 2>&1';
        exec($command, $output, $returnVar);
        
        $outputText = implode("\n", $output);
        $errors = [];
        
        // 检测运行时错误
        $errorPatterns = [
            '/Fatal error:/i',
            '/Parse error:/i',
            '/Warning:/i',
            '/Exception:/i',
            '/Error:/i',
            '/Uncaught/i',
        ];
        
        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $outputText)) {
                $errors[] = $outputText;
                break;
            }
        }
        
        return [
            'success' => empty($errors) && $returnVar === 0,
            'output' => $outputText,
            'errors' => $errors,
        ];
    }
    
    /**
     * 获取文件的类名和命名空间
     */
    public function getClassInfo(string $file): array
    {
        if (!file_exists($file)) {
            return ['namespace' => null, 'class' => null];
        }
        
        $content = file_get_contents($file);
        $namespace = null;
        $class = null;
        
        // 提取命名空间
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim($matches[1]);
        }
        
        // 提取类名
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $class = trim($matches[1]);
        }
        
        return [
            'namespace' => $namespace,
            'class' => $class,
            'fqcn' => $namespace && $class ? "{$namespace}\\{$class}" : $class,
        ];
    }
}
