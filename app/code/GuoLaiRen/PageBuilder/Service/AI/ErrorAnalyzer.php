<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI;

/**
 * AI组件错误分析器
 * 
 * 提供详细的错误上下文和修复建议
 */
class ErrorAnalyzer
{
    /**
     * 常见错误模式和修复建议
     */
    private const ERROR_PATTERNS = [
        // 语法错误
        'unexpected.*token.*`' => [
            'cause' => '代码中包含反引号字符',
            'suggestion' => '移除或替换所有反引号 ` 为单引号 \'',
            'severity' => 'error',
        ],
        'unexpected.*end of file' => [
            'cause' => '代码未正确结束，可能缺少括号或分号',
            'suggestion' => '检查所有括号是否匹配，确保语句以分号结尾',
            'severity' => 'error',
        ],
        'unexpected.*\$' => [
            'cause' => '变量使用位置不正确',
            'suggestion' => '检查变量是否在正确的上下文中使用',
            'severity' => 'error',
        ],
        'unexpected.*\)' => [
            'cause' => '括号不匹配，多余的右括号',
            'suggestion' => '检查函数调用和表达式中的括号匹配',
            'severity' => 'error',
        ],
        'unexpected.*\(' => [
            'cause' => '括号使用不正确',
            'suggestion' => '检查函数名和括号之间是否有空格或其他字符',
            'severity' => 'error',
        ],
        'unexpected.*\}' => [
            'cause' => '大括号不匹配，多余的右大括号',
            'suggestion' => '检查if/for/foreach/function等结构的大括号匹配',
            'severity' => 'error',
        ],
        'unexpected.*\{' => [
            'cause' => '大括号使用位置不正确',
            'suggestion' => '检查控制结构语法是否正确',
            'severity' => 'error',
        ],
        'unexpected.*string' => [
            'cause' => '字符串位置不正确，可能缺少运算符或分号',
            'suggestion' => '检查字符串拼接是否使用了正确的点号运算符',
            'severity' => 'error',
        ],
        'unexpected.*\;' => [
            'cause' => '分号位置不正确',
            'suggestion' => '检查是否在表达式中间错误地添加了分号',
            'severity' => 'error',
        ],
        
        // 未定义错误
        'undefined variable.*\$(\w+)' => [
            'cause' => '使用了未定义的变量',
            'suggestion' => '确保变量在使用前已定义，或使用 $getConfig() 获取配置值',
            'severity' => 'error',
        ],
        'undefined function.*(\w+)' => [
            'cause' => '调用了未定义的函数',
            'suggestion' => '检查函数名是否正确，或确保函数已定义',
            'severity' => 'error',
        ],
        'undefined method' => [
            'cause' => '调用了不存在的方法',
            'suggestion' => '检查对象是否有该方法，或对象是否为null',
            'severity' => 'error',
        ],
        
        // 类型错误
        'call to.*member function.*on null' => [
            'cause' => '在null对象上调用方法',
            'suggestion' => '添加null检查：if ($object) { ... }',
            'severity' => 'error',
        ],
        'call to.*member function.*on.*array' => [
            'cause' => '在数组上调用对象方法',
            'suggestion' => '检查变量类型，确保是对象而非数组',
            'severity' => 'error',
        ],
        'cannot access.*on.*null' => [
            'cause' => '尝试访问null值的属性',
            'suggestion' => '添加null检查后再访问属性',
            'severity' => 'error',
        ],
        
        // 数组错误
        'undefined array key' => [
            'cause' => '访问了不存在的数组键',
            'suggestion' => '使用 ?? 运算符提供默认值：$array[$key] ?? $default',
            'severity' => 'warning',
        ],
        'undefined offset' => [
            'cause' => '访问了不存在的数组索引',
            'suggestion' => '检查数组索引是否存在，使用 isset() 或 ?? 运算符',
            'severity' => 'warning',
        ],
        
        // 语法相关
        'syntax error' => [
            'cause' => 'PHP语法错误',
            'suggestion' => '检查代码语法，确保括号匹配、分号完整',
            'severity' => 'error',
        ],
        'parse error' => [
            'cause' => 'PHP解析错误',
            'suggestion' => '检查是否有语法错误，如缺少分号、括号不匹配等',
            'severity' => 'error',
        ],
    ];

    /**
     * 分析错误
     * 
     * @param string $error 错误信息
     * @param string $code 相关代码
     * @return array 分析结果
     */
    public function analyze(string $error, string $code): array
    {
        // 解析错误行号
        $line = $this->extractLineNumber($error);
        
        // 提取错误上下文
        $context = $this->extractContext($code, $line);
        
        // 分析错误原因
        $analysis = $this->analyzeError($error);
        
        // 生成修复建议
        $suggestions = $this->generateSuggestions($error, $context, $code);
        
        return [
            'line' => $line,
            'context' => $context,
            'cause' => $analysis['cause'],
            'severity' => $analysis['severity'],
            'suggestions' => $suggestions,
            'fix_hint' => $this->getFixHint($error, $context),
        ];
    }

    /**
     * 提取错误行号
     */
    private function extractLineNumber(string $error): int
    {
        // 尝试多种格式
        $patterns = [
            '/on line (\d+)/i',
            '/line (\d+)/i',
            '/\((\d+)\)/',
            '/:(\d+)/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $error, $matches)) {
                return (int)$matches[1];
            }
        }
        
        return 0;
    }

    /**
     * 提取错误上下文代码
     */
    private function extractContext(string $code, int $line, int $contextLines = 3): array
    {
        $lines = explode("\n", $code);
        $totalLines = count($lines);
        
        if ($line <= 0 || $line > $totalLines) {
            return [
                'before' => [],
                'error_line' => '',
                'after' => [],
                'line_numbers' => [],
            ];
        }
        
        $startLine = max(1, $line - $contextLines);
        $endLine = min($totalLines, $line + $contextLines);
        
        $before = [];
        $after = [];
        $lineNumbers = [];
        
        for ($i = $startLine; $i <= $endLine; $i++) {
            $lineContent = $lines[$i - 1] ?? '';
            $lineNumbers[] = $i;
            
            if ($i < $line) {
                $before[] = $lineContent;
            } elseif ($i > $line) {
                $after[] = $lineContent;
            }
        }
        
        return [
            'before' => $before,
            'error_line' => $lines[$line - 1] ?? '',
            'after' => $after,
            'line_numbers' => $lineNumbers,
        ];
    }

    /**
     * 分析错误类型
     */
    private function analyzeError(string $error): array
    {
        $errorLower = strtolower($error);
        
        foreach (self::ERROR_PATTERNS as $pattern => $info) {
            if (preg_match('/' . $pattern . '/i', $errorLower)) {
                return $info;
            }
        }
        
        return [
            'cause' => '未知错误',
            'suggestion' => '检查代码语法和逻辑',
            'severity' => 'error',
        ];
    }

    /**
     * 生成修复建议
     */
    private function generateSuggestions(string $error, array $context, string $code): array
    {
        $suggestions = [];
        $errorLine = $context['error_line'] ?? '';
        
        // 基于错误类型的建议
        $analysis = $this->analyzeError($error);
        $suggestions[] = $analysis['suggestion'] ?? '检查代码语法';
        
        // 基于错误行内容的建议
        if (!empty($errorLine)) {
            // 检查反引号
            if (strpos($errorLine, '`') !== false) {
                $suggestions[] = '将反引号 ` 替换为单引号 \'';
            }
            
            // 检查未闭合的字符串
            $singleQuotes = substr_count($errorLine, "'") - substr_count($errorLine, "\\'");
            $doubleQuotes = substr_count($errorLine, '"') - substr_count($errorLine, '\\"');
            if ($singleQuotes % 2 !== 0) {
                $suggestions[] = '检查单引号是否匹配';
            }
            if ($doubleQuotes % 2 !== 0) {
                $suggestions[] = '检查双引号是否匹配';
            }
            
            // 检查 $this 使用
            if (preg_match('/\$this\s*->/', $errorLine)) {
                $suggestions[] = '避免直接使用 $this，使用 $getConfig() 辅助函数';
            }
            
            // 检查分号
            $trimmedLine = rtrim($errorLine);
            if (!empty($trimmedLine) && 
                !str_ends_with($trimmedLine, '{') && 
                !str_ends_with($trimmedLine, '}') && 
                !str_ends_with($trimmedLine, ';') && 
                !str_ends_with($trimmedLine, ':') &&
                !str_ends_with($trimmedLine, ',') &&
                preg_match('/\$\w+\s*=/', $trimmedLine)) {
                $suggestions[] = '该行可能缺少分号';
            }
        }
        
        // 基于整体代码的建议
        if (preg_match('/<\?(php|=)/i', $code) && strpos($error, 'html_content') !== false) {
            $suggestions[] = 'HTML内容中不应包含PHP标签';
        }
        
        return array_unique($suggestions);
    }

    /**
     * 获取修复提示
     */
    private function getFixHint(string $error, array $context): string
    {
        $errorLine = $context['error_line'] ?? '';
        $hints = [];
        
        // 检查常见问题并给出具体修复
        if (strpos($errorLine, '`') !== false) {
            $fixed = str_replace('`', "'", $errorLine);
            $hints[] = "将: {$errorLine}\n改为: {$fixed}";
        }
        
        if (preg_match('/\$this\s*->\s*getData\s*\(\s*[\'"](\w+)[\'"]\s*\)/', $errorLine, $matches)) {
            $key = $matches[1];
            $hints[] = "将: \$this->getData('{$key}')\n改为: \$getConfig('{$key}', '')";
        }
        
        if (empty($hints)) {
            return '请检查错误行附近的代码语法';
        }
        
        return implode("\n\n", $hints);
    }

    /**
     * 格式化分析结果为可读文本
     */
    public function formatAnalysis(array $analysis): string
    {
        $output = [];
        
        // 严重程度
        $severityLabel = match($analysis['severity'] ?? 'error') {
            'warning' => '⚠️ 警告',
            'error' => '❌ 错误',
            default => '❓ 未知',
        };
        $output[] = $severityLabel;
        
        // 行号
        if ($analysis['line'] > 0) {
            $output[] = "行号: {$analysis['line']}";
        }
        
        // 原因
        if (!empty($analysis['cause'])) {
            $output[] = "原因: {$analysis['cause']}";
        }
        
        // 错误行内容
        if (!empty($analysis['context']['error_line'])) {
            $output[] = "问题代码: " . trim($analysis['context']['error_line']);
        }
        
        // 建议
        if (!empty($analysis['suggestions'])) {
            $output[] = "修复建议:";
            foreach ($analysis['suggestions'] as $i => $suggestion) {
                $output[] = "  " . ($i + 1) . ". " . $suggestion;
            }
        }
        
        // 修复提示
        if (!empty($analysis['fix_hint'])) {
            $output[] = "具体修复:\n" . $analysis['fix_hint'];
        }
        
        return implode("\n", $output);
    }

    /**
     * 快速分析并返回格式化结果
     */
    public function quickAnalyze(string $error, string $code): string
    {
        $analysis = $this->analyze($error, $code);
        return $this->formatAnalysis($analysis);
    }
}
