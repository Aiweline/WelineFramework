<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 合规检查服务
 * 
 * 职责：
 * 1. 检测文件变更是否符合规则
 * 2. 识别违规项
 * 3. 生成修复建议
 */
class ComplianceCheckerService
{
    private ?RuleAnalyzerService $ruleAnalyzer = null;
    private bool $verbose = false;
    private array $violations = [];
    
    /**
     * 设置详细输出
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }
    
    /**
     * 获取规则分析器
     */
    private function getRuleAnalyzer(): RuleAnalyzerService
    {
        if ($this->ruleAnalyzer === null) {
            $this->ruleAnalyzer = ObjectManager::getInstance(RuleAnalyzerService::class);
        }
        return $this->ruleAnalyzer;
    }
    
    /**
     * 检查文件合规性
     */
    public function checkFile(string $filePath): array
    {
        $this->violations = [];
        
        if (!file_exists($filePath)) {
            return ['compliant' => true, 'violations' => []];
        }
        
        $content = file_get_contents($filePath);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        
        // 获取适用的规则
        $rules = $this->getRuleAnalyzer()->getRulesForFile($filePath);
        
        // 基于文件类型检查
        switch ($ext) {
            case 'css':
                $this->checkCssCompliance($content, $filePath);
                break;
            case 'js':
                $this->checkJsCompliance($content, $filePath);
                break;
            case 'phtml':
                $this->checkPhtmlCompliance($content, $filePath);
                break;
            case 'php':
                $this->checkPhpCompliance($content, $filePath);
                break;
        }
        
        // 基于规则约束检查
        foreach ($rules as $rule) {
            $this->checkRuleConstraints($content, $filePath, $rule);
        }
        
        return [
            'compliant' => empty($this->violations),
            'violations' => $this->violations,
            'applicable_rules' => array_map(fn($r) => $r['name'], $rules),
        ];
    }
    
    /**
     * 检查 CSS 合规性
     */
    private function checkCssCompliance(string $content, string $filePath): void
    {
        // 检查硬编码颜色
        $colorPatterns = [
            '/#[0-9a-fA-F]{3,6}(?![0-9a-fA-F])/' => '硬编码颜色值',
            '/rgba?\s*\([^)]+\)/' => '硬编码 RGB/RGBA 颜色',
            '/hsla?\s*\([^)]+\)/' => '硬编码 HSL/HSLA 颜色',
        ];
        
        foreach ($colorPatterns as $pattern => $description) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $value = $match[0];
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    
                    // 排除 CSS 变量定义
                    $lineContent = $this->getLineContent($content, $match[1]);
                    if (str_contains($lineContent, '--backend-color') || str_contains($lineContent, '--frontend-color')) {
                        continue;
                    }
                    
                    $this->addViolation([
                        'type' => 'css_hardcoded_color',
                        'severity' => 'error',
                        'message' => "禁止硬编码颜色: {$value}",
                        'description' => $description,
                        'file' => $filePath,
                        'line' => $line,
                        'value' => $value,
                        'fix' => '使用 CSS 变量: var(--backend-color-*)',
                        'rule' => 'theme-development',
                    ]);
                }
            }
        }
        
        // 检查通用类名（可能污染全局）
        $genericClasses = ['.card', '.header', '.footer', '.item', '.list', '.btn', '.button'];
        foreach ($genericClasses as $class) {
            $pattern = '/(?<![a-z-])' . preg_quote($class, '/') . '\s*\{/i';
            if (preg_match($pattern, $content)) {
                $this->addViolation([
                    'type' => 'css_generic_class',
                    'severity' => 'warning',
                    'message' => "通用类名可能污染全局: {$class}",
                    'file' => $filePath,
                    'fix' => '使用组件前缀: .weline-module-card',
                    'rule' => 'theme-development',
                ]);
            }
        }
    }
    
    /**
     * 检查 JS 合规性
     */
    private function checkJsCompliance(string $content, string $filePath): void
    {
        // 检查是否使用 IIFE
        $hasIIFE = preg_match('/\(function\s*\([^)]*\)\s*\{/', $content) ||
                   preg_match('/\(\(\)\s*=>\s*\{/', $content);
        
        // 检查全局变量
        $globalPatterns = [
            '/^var\s+\w+\s*=/m' => '全局 var 声明',
            '/^let\s+\w+\s*=/m' => '全局 let 声明',
            '/^const\s+\w+\s*=/m' => '全局 const 声明',
            '/^function\s+\w+\s*\(/m' => '全局函数声明',
        ];
        
        if (!$hasIIFE) {
            foreach ($globalPatterns as $pattern => $description) {
                if (preg_match($pattern, $content)) {
                    $this->addViolation([
                        'type' => 'js_global_pollution',
                        'severity' => 'error',
                        'message' => "可能的全局污染: {$description}",
                        'file' => $filePath,
                        'fix' => '使用 IIFE 闭包: (function() { "use strict"; ... })();',
                        'rule' => 'theme-development',
                    ]);
                    break;
                }
            }
        }
        
        // 检查 alert/confirm/prompt
        $nativeDialogs = ['alert(', 'confirm(', 'prompt('];
        foreach ($nativeDialogs as $dialog) {
            if (str_contains($content, $dialog)) {
                $this->addViolation([
                    'type' => 'js_native_dialog',
                    'severity' => 'error',
                    'message' => "禁止使用原生对话框: {$dialog}",
                    'file' => $filePath,
                    'fix' => '使用 AdminToast 或 ThemeToast',
                    'rule' => 'friendly-notifications',
                ]);
            }
        }
    }
    
    /**
     * 检查 PHTML 合规性
     */
    private function checkPhtmlCompliance(string $content, string $filePath): void
    {
        // 检查硬编码文案（没有使用 __() 或 <lang>）
        if (preg_match_all('/>([^<>\{\}]{4,})</u', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $text = trim($match[0]);
                // 排除数字、变量、空白
                if (preg_match('/^[\s\d\{\}\$\-\.\,\:]+$/', $text)) {
                    continue;
                }
                // 排除已经在 lang 标签内的
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $lineContent = $this->getLineContent($content, $match[1]);
                if (str_contains($lineContent, '<lang>') || str_contains($lineContent, '__')) {
                    continue;
                }
                
                // 只报告中文文本
                if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $text)) {
                    $this->addViolation([
                        'type' => 'phtml_hardcoded_text',
                        'severity' => 'warning',
                        'message' => "硬编码文案: {$text}",
                        'file' => $filePath,
                        'line' => $line,
                        'fix' => '使用 <lang>文案</lang> 或 __("文案")',
                        'rule' => 'i18n-internationalization',
                    ]);
                }
            }
        }
        
        // 检查全局函数定义
        if (preg_match('/function\s+\w+\s*\(/', $content) && 
            !str_contains($content, 'function_exists')) {
            // 排除闭包
            if (!preg_match('/\$\w+\s*=\s*function/', $content)) {
                $this->addViolation([
                    'type' => 'phtml_global_function',
                    'severity' => 'error',
                    'message' => '禁止在模板中定义全局函数',
                    'file' => $filePath,
                    'fix' => '使用闭包: $render = function() use (&$render) { ... };',
                    'rule' => 'code-generation-standards',
                ]);
            }
        }
    }
    
    /**
     * 检查 PHP 合规性
     */
    private function checkPhpCompliance(string $content, string $filePath): void
    {
        // 检查 error_log
        if (preg_match('/\berror_log\s*\(/', $content)) {
            $this->addViolation([
                'type' => 'php_error_log',
                'severity' => 'error',
                'message' => '禁止使用 error_log()',
                'file' => $filePath,
                'fix' => '使用 Env::log_error() 或 Debug::debug()',
                'rule' => 'debug-logging',
            ]);
        }
        
        // 检查手写 SQL
        if (preg_match('/\bSELECT\s+.*\s+FROM\s+/i', $content) ||
            preg_match('/\bINSERT\s+INTO\s+/i', $content) ||
            preg_match('/\bUPDATE\s+\w+\s+SET\s+/i', $content)) {
            $this->addViolation([
                'type' => 'php_raw_sql',
                'severity' => 'warning',
                'message' => '建议使用 ORM 而非手写 SQL',
                'file' => $filePath,
                'fix' => '使用 Model::select()/insert()/update()->fetch()',
                'rule' => 'database-model-standards',
            ]);
        }
        
        // 检查 Model 操作是否有 fetch()
        if (preg_match('/->(?:select|where|join|order|limit)\s*\([^)]*\)\s*;/', $content)) {
            $this->addViolation([
                'type' => 'php_missing_fetch',
                'severity' => 'error',
                'message' => 'Model 查询缺少 fetch()',
                'file' => $filePath,
                'fix' => '链式调用末尾添加 ->fetch() 或 ->fetchOrigin()',
                'rule' => 'database-model-standards',
            ]);
        }
    }
    
    /**
     * 检查规则约束
     */
    private function checkRuleConstraints(string $content, string $filePath, array $rule): void
    {
        $ruleData = $rule['rule'] ?? $rule['skill'] ?? null;
        if (!$ruleData) {
            return;
        }
        
        $constraints = $ruleData['constraints'] ?? [];
        
        foreach ($constraints as $constraint) {
            // 提取禁止的关键词
            if (preg_match('/禁止[使用]?(.+)/u', $constraint, $match)) {
                $forbidden = trim($match[1]);
                if (str_contains($content, $forbidden)) {
                    $this->addViolation([
                        'type' => 'rule_constraint',
                        'severity' => 'warning',
                        'message' => $constraint,
                        'file' => $filePath,
                        'rule' => $rule['name'],
                    ]);
                }
            }
        }
    }
    
    /**
     * 获取行内容
     */
    private function getLineContent(string $content, int $offset): string
    {
        $lineStart = strrpos(substr($content, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        
        $lineEnd = strpos($content, "\n", $offset);
        $lineEnd = $lineEnd === false ? strlen($content) : $lineEnd;
        
        return substr($content, $lineStart, $lineEnd - $lineStart);
    }
    
    /**
     * 添加违规项
     */
    private function addViolation(array $violation): void
    {
        $this->violations[] = $violation;
        
        if ($this->verbose) {
            $severity = $violation['severity'] ?? 'warning';
            $icon = $severity === 'error' ? '❌' : '⚠️';
            echo "{$icon} [{$violation['type']}] {$violation['message']}\n";
            if (isset($violation['fix'])) {
                echo "   修复: {$violation['fix']}\n";
            }
        }
    }
    
    /**
     * 批量检查文件
     */
    public function checkFiles(array $filePaths): array
    {
        $results = [];
        $totalViolations = 0;
        
        foreach ($filePaths as $filePath) {
            $result = $this->checkFile($filePath);
            $results[$filePath] = $result;
            $totalViolations += count($result['violations']);
        }
        
        return [
            'files_checked' => count($filePaths),
            'files_with_violations' => count(array_filter($results, fn($r) => !$r['compliant'])),
            'total_violations' => $totalViolations,
            'results' => $results,
        ];
    }
    
    /**
     * 获取违规摘要
     */
    public function getViolationSummary(): array
    {
        $byType = [];
        $bySeverity = ['error' => 0, 'warning' => 0];
        $byRule = [];
        
        foreach ($this->violations as $violation) {
            $type = $violation['type'];
            $severity = $violation['severity'] ?? 'warning';
            $rule = $violation['rule'] ?? 'unknown';
            
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $bySeverity[$severity]++;
            $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
        }
        
        return [
            'total' => count($this->violations),
            'by_type' => $byType,
            'by_severity' => $bySeverity,
            'by_rule' => $byRule,
        ];
    }
}
