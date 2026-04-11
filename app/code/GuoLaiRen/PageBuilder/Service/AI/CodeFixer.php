<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI;

/**
 * AI生成代码自动修复器
 * 
 * 自动修复AI生成代码中的常见错误
 */
class CodeFixer
{
    /**
     * 修复记录
     */
    private array $fixes = [];

    /**
     * 获取修复记录
     */
    public function getFixes(): array
    {
        return $this->fixes;
    }

    private function normalizePhpEchoTags(string $content, string $context): string
    {
        $original = $content;
        $content = preg_replace('/<\?php\s*=\s*/i', '<?= ', $content);
        $content = preg_replace('/<\?\s+=\s*/', '<?= ', $content);
        if ($content !== $original) {
            $this->addFix($context, '规范化了错误的 PHP 回显标签');
        }
        return $content;
    }

    /**
     * 清除修复记录
     */
    public function clearFixes(): void
    {
        $this->fixes = [];
    }

    /**
     * 修复完整的组件代码
     */
    public function fix(string $code): string
    {
        $this->clearFixes();
        
        // 0. 移除可能夹带的代码块围栏
        $code = $this->stripCodeFences($code, 'phtml');
        
        // 1. 修复JavaScript部分的多行字符串问题
        $code = $this->fixJavaScriptSection($code);
        
        // 2. 移除反引号
        $code = $this->fixBackticks($code);
        
        // 3. 修复PHP标签问题
        $code = $this->fixPhpTags($code);
        $code = $this->normalizePhpEchoTags($code, 'phtml');
        
        // 4. 修复未闭合的括号
        $code = $this->balanceBrackets($code);
        
        // 5. 修复缺失的分号
        $code = $this->addMissingSemicolons($code);
        
        // 6. 修复变量命名问题
        $code = $this->fixVariableNaming($code);
        
        // 7. 清理多余的空白
        $code = $this->cleanupWhitespace($code);
        
        return $code;
    }
    
    /**
     * 修复JavaScript部分的问题
     * 
     * 主要处理：
     * 1. 多行字符串条件判断问题
     * 2. 将单引号字符串改为heredoc
     */
    private function fixJavaScriptSection(string $code): string
    {
        // 匹配有问题的JS部分模式：
        // $jsContent = '多行JS代码'; if (!empty($jsContent) && $jsContent !== '相同的多行代码'):
        $pattern = '/\$jsContent\s*=\s*\'([^\']*(?:\'\'[^\']*)*)\'\s*;\s*if\s*\(\s*!empty\s*\(\s*\$jsContent\s*\)\s*&&\s*\$jsContent\s*!==\s*\'([^\']*(?:\'\'[^\']*)*)\'\s*\)\s*:/s';
        
        if (preg_match($pattern, $code, $matches)) {
            $jsCode = $matches[1];
            
            // 用更安全的结构替换
            $safeCode = "\$_jsPlaceholder = <<<'JSEOF'\n{$jsCode}\nJSEOF;\n\$jsContent = trim(\$_jsPlaceholder);\n\$_hasValidJs = !empty(\$jsContent) && strlen(\$jsContent) > 5;\nif (\$_hasValidJs):";
            
            $code = preg_replace($pattern, $safeCode, $code);
            $this->addFix('js_section', '修复了JavaScript部分的多行字符串语法问题');
        }
        
        // 匹配另一种问题模式：heredoc后面跟着有问题的条件
        // $jsContent = <<<'JSEOF' ... JSEOF; if(...$jsContent !== '多行代码...'):
        $pattern2 = '/if\s*\(\s*!empty\s*\(\s*\$jsContent\s*\)\s*&&\s*\$jsContent\s*!==\s*\'[^\']{20,}/s';
        
        if (preg_match($pattern2, $code)) {
            // 替换为简单的非空检查
            $code = preg_replace(
                '/if\s*\(\s*!empty\s*\(\s*\$jsContent\s*\)\s*&&\s*\$jsContent\s*!==\s*\'[^\)]+\)\s*:/s',
                'if (!empty($jsContent) && strlen($jsContent) > 5):',
                $code
            );
            $this->addFix('js_section', '修复了JavaScript条件判断中的多行字符串');
        }
        
        return $code;
    }

    /**
     * 修复AI返回的JSON数据
     */
    public function fixAiData(array $data): array
    {
        $this->clearFixes();
        
        // 先统一移除可能的代码块围栏
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $cleaned = $this->stripCodeFences($value, $key);
                if ($cleaned !== $value) {
                    $data[$key] = $cleaned;
                }
            }
        }
        
        // 修复html_content
        if (isset($data['html_content'])) {
            $data['html_content'] = $this->fixHtmlContent($data['html_content']);
        }
        
        // 修复php_variables
        if (isset($data['php_variables'])) {
            $data['php_variables'] = $this->fixPhpVariables($data['php_variables']);
        }
        
        // 修复css_extra
        if (isset($data['css_extra'])) {
            $data['css_extra'] = $this->fixCss($data['css_extra']);
        }
        
        // 修复css_responsive
        if (isset($data['css_responsive'])) {
            $data['css_responsive'] = $this->fixCss($data['css_responsive']);
        }
        
        // 修复js_content
        if (isset($data['js_content'])) {
            $data['js_content'] = $this->fixJsContent($data['js_content']);
        }
        
        return $data;
    }
    
    /**
     * 移除AI可能返回的代码块围栏
     */
    private function stripCodeFences(string $text, string $context): string
    {
        $original = $text;
        // 移除 ```lang 与 ``` 行
        $text = preg_replace('/^\s*```[a-zA-Z]*\s*$/m', '', $text);
        $text = preg_replace('/^\s*```\s*$/m', '', $text);
        if ($text !== $original) {
            $this->addFix($context, '移除了代码块围栏');
        }
        return $text;
    }

    /**
     * 修复HTML内容
     * 不转义 PHP 标签，否则模板阶段 PHP 不执行、预览会显示源码（如 &lt;?= ... ?&gt;）
     */
    public function fixHtmlContent(string $html): string
    {
        // 移除反引号（避免破坏字符串）
        $original = $html;
        $html = str_replace('`', "'", $html);
        if ($html !== $original) {
            $this->addFix('html_content', '替换了反引号为单引号');
        }
        $html = $this->normalizePhpEchoTags($html, 'html_content');
        return $html;
    }

    /**
     * 修复PHP变量代码
     */
    public function fixPhpVariables(string $php): string
    {
        $phpOpen = chr(60) . chr(63) . 'php';
        $phpShort = chr(60) . chr(63);
        $phpClose = chr(63) . chr(62);
        $php = str_replace(["\r\n", "\r"], "\n", $php);
        
        // 移除PHP开始/结束标签
        $original = $php;
        $php = preg_replace('/^' . preg_quote($phpOpen, '/') . '\s*/i', '', $php);
        $php = preg_replace('/^' . preg_quote($phpShort, '/') . '\s*/i', '', $php);
        $php = preg_replace('/\s*' . preg_quote($phpClose, '/') . '\s*$/', '', $php);
        if ($php !== $original) {
            $this->addFix('php_variables', '移除了PHP标签');
        }
        
        // 移除反引号
        $original = $php;
        $php = str_replace('`', "'", $php);
        if ($php !== $original) {
            $this->addFix('php_variables', '替换了反引号');
        }
        
        // 修复双美元符号（$$var -> $var）
        $original = $php;
        $php = preg_replace('/\$\$([a-zA-Z_]\w*)/', '\$$1', $php);
        if ($php !== $original) {
            $this->addFix('php_variables', '修复了双美元符号变量');
        }

        // 修复数组箭头语法 `=>`：AI 可能误解模板变量格式而输出 `$key => value` 而不是 `$key = value`
        // 这种语法在 try{ php_variables } 块内是无效的，会导致 "Parse error: unexpected token =>"
        $original = $php;
        $fixedLines = [];
        $hasArrowFix = false;
        foreach (preg_split('/\n/', $php) ?: [] as $line) {
            $trimmed = trim($line);
            // 匹配 $var => value 或 $var => 'value' 模式（不是字符串内的 =>）
            if (preg_match('/^\$[a-zA-Z_]\w*\s*=>\s*/', $trimmed)) {
                $fixedLines[] = preg_replace('/^(\$[a-zA-Z_]\w*)\s*=>\s*/', '$1 = ', $line);
                $hasArrowFix = true;
            } else {
                $fixedLines[] = $line;
            }
        }
        if ($hasArrowFix) {
            $php = implode("\n", $fixedLines);
            $this->addFix('php_variables', '修复了 `=>` 数组语法为 `$var = value` 赋值语法');
        }

        // 移除所有内嵌的 PHP 标签（不仅仅是开头结尾）
        $original = $php;
        $php = preg_replace('/<\?(?:php|=)?/i', '', $php);
        $php = preg_replace('/\?>/i', '', $php);
        if ($php !== $original) {
            $this->addFix('php_variables', '移除了内嵌的PHP标签');
        }
        
        // 修复常见的语法问题
        $original = $php;
        $lines = preg_split('/\n/', $php) ?: [];
        $safeLines = [];
        $removedUnsafeStatements = false;
        $addedSemicolons = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (
                str_contains($trimmed, '```')
                || str_contains($trimmed, '$this->')
                || str_contains($trimmed, '{')
                || str_contains($trimmed, '}')
                || preg_match('/^\s*(?:if|else|elseif|foreach|while|for|switch|continue|break|return|echo|print)\b/i', $trimmed)
                || preg_match('/=\s*(?:function|fn)\s*\(/i', $trimmed)
                || preg_match('/=\s*new\s+class\b/i', $trimmed)
                || !preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*\s*=/', $trimmed)
            ) {
                $removedUnsafeStatements = true;
                continue;
            }

            if (!preg_match('/;\s*$/', $trimmed)) {
                $trimmed .= ';';
                $addedSemicolons = true;
            }

            $safeLines[] = $trimmed;
        }
        $php = implode("\n", $safeLines);
        if ($removedUnsafeStatements) {
            $this->addFix('php_variables', '移除了不受支持的 php_variables 语句');
        }
        if ($addedSemicolons) {
            $this->addFix('php_variables', '补全了 php_variables 赋值语句分号');
        }
        if ($php !== $original && !$removedUnsafeStatements && !$addedSemicolons) {
            $this->addFix('php_variables', '规范化了 php_variables 内容');
        }

        $php = $this->fixPhpSyntax($php);
        
        return $php;
    }

    /**
     * 修复CSS代码
     */
    public function fixCss(string $css): string
    {
        // 移除反引号
        $original = $css;
        $css = str_replace('`', "'", $css);
        if ($css !== $original) {
            $this->addFix('css', '替换了反引号');
        }
        
        // 修复大括号匹配
        $css = $this->normalizePhpEchoTags($css, 'css');

        $openCount = substr_count($css, '{');
        $closeCount = substr_count($css, '}');
        if ($openCount > $closeCount) {
            $css .= str_repeat('}', $openCount - $closeCount);
            $this->addFix('css', '补充了缺失的右大括号');
        }
        
        // 移除空规则
        $original = $css;
        $css = preg_replace('/[^{}]+\{\s*\}/', '', $css);
        if ($css !== $original) {
            $this->addFix('css', '移除了空的CSS规则');
        }
        
        return trim($css);
    }

    /**
     * 修复JavaScript代码
     */
    public function fixJsContent(string $js): string
    {
        // 移除 PHP 标签 - JS 中不应该有 PHP 代码
        $original = $js;
        $phpOpen = chr(60) . chr(63);
        $phpClose = chr(63) . chr(62);
        
        // 移除残留的 PHP 标签
        $js = str_replace($phpOpen . 'php', '', $js);
        $js = str_replace($phpOpen . '=', '', $js);
        $js = str_replace($phpOpen, '', $js);
        $js = str_replace($phpClose, '', $js);
        if ($js !== $original) {
            $this->addFix('js_content', '移除了JavaScript中的PHP代码');
        }
        
        // 处理 $componentId（禁止在 JS 中使用 PHP 变量）
        $original = $js;
        if (strpos($js, '$componentId') !== false) {
            $js = str_replace('$componentId', 'component.id', $js);
            $this->addFix('js_content', '替换了 JS 中的 $componentId 为 component.id');
        }
        
        // 修复无效选择器：'# $componentId -toggle' 之类
        $original = $js;
        $js = preg_replace(
            '/querySelector\s*\(\s*([\'"])\s*#\s*component\.id\s*-\s*([a-zA-Z0-9_-]+)\s*\1\s*\)/',
            "querySelector('#' + component.id + '-$2')",
            $js
        );
        $js = preg_replace(
            '/querySelector\s*\(\s*([\'"])\s*#\s*component\.id\s*\1\s*\)/',
            "querySelector('#' + component.id)",
            $js
        );
        if ($js !== $original) {
            $this->addFix('js_content', '修复了 JS 中无效的 componentId 选择器');
        }
        
        // 移除 document.addEventListener('DOMContentLoaded', ...) 包装
        $original = $js;
        $pattern = '/^\s*document\.addEventListener\s*\(\s*[\'"]DOMContentLoaded[\'"]\s*,\s*function\s*\(\s*\)\s*\{/';
        if (preg_match($pattern, $js)) {
            // 尝试提取内部代码
            $js = preg_replace($pattern, '', $js);
            // 移除末尾的 });
            $js = preg_replace('/\}\s*\)\s*;?\s*$/', '', $js);
            $this->addFix('js_content', '移除了DOMContentLoaded包装');
        }
        
        // 移除 IIFE 包装 (function(){...})();
        if (preg_match('/^\s*\(\s*function\s*\(\s*\)\s*\{/', $js)) {
            $js = preg_replace('/^\s*\(\s*function\s*\(\s*\)\s*\{/', '', $js);
            $js = preg_replace('/\}\s*\)\s*\(\s*\)\s*;?\s*$/', '', $js);
            $this->addFix('js_content', '移除了IIFE包装');
        }
        
        // 移除 (function(){...}());
        if (preg_match('/^\s*\(\s*function\s*\(\s*\)\s*\{/', $js)) {
            $js = preg_replace('/^\s*\(\s*function\s*\(\s*\)\s*\{/', '', $js);
            $js = preg_replace('/\}\s*\(\s*\)\s*\)\s*;?\s*$/', '', $js);
            $this->addFix('js_content', '移除了IIFE包装');
        }
        
        // 将 var 替换为 const（避免变量提升）
        $original = $js;
        $js = preg_replace('/\bvar\s+(\w)/', 'const $1', $js);
        if ($js !== $original) {
            $this->addFix('js_content', '将 var 替换为 const（避免变量提升）');
        }
        
        // 将 function xxx() 替换为 const xxx = function()（避免函数提升到全局）
        $original = $js;
        $js = preg_replace('/\bfunction\s+([a-zA-Z_]\w*)\s*\(/', 'const $1 = function(', $js);
        if ($js !== $original) {
            $this->addFix('js_content', '将 function 声明转换为 const 表达式（避免全局污染）');
        }
        
        // 将 document.querySelector 替换为 component.querySelector
        $original = $js;
        $js = preg_replace('/\bdocument\s*\.\s*(querySelector|querySelectorAll)\s*\(/', 'component.$1(', $js);
        if ($js !== $original) {
            $this->addFix('js_content', '将 document.querySelector 替换为 component.querySelector（限定组件作用域）');
        }
        
        // 修复大括号匹配
        $openCount = substr_count($js, '{');
        $closeCount = substr_count($js, '}');
        if ($openCount > $closeCount) {
            $js .= str_repeat('}', $openCount - $closeCount);
            $this->addFix('js_content', '补充了缺失的右大括号');
        }
        
        // 修复括号匹配
        $openCount = substr_count($js, '(');
        $closeCount = substr_count($js, ')');
        if ($openCount > $closeCount) {
            $js .= str_repeat(')', $openCount - $closeCount);
            $this->addFix('js_content', '补充了缺失的右括号');
        }
        
        return trim($js);
    }

    /**
     * 修复反引号
     */
    private function fixBackticks(string $code): string
    {
        $original = $code;
        $code = str_replace('`', "'", $code);
        if ($code !== $original) {
            $count = substr_count($original, '`');
            $this->addFix('backticks', "替换了 {$count} 个反引号");
        }
        return $code;
    }

    /**
     * 修复PHP标签问题
     */
    private function fixPhpTags(string $code): string
    {
        // Simply remove redundant close-open tag sequences
        $origCode = $code;
        $code = preg_replace('/\x3f\x3e\s*\x3c\x3fphp/', '', $code);
        if ($code !== $origCode) {
            $this->addFix('php_tags', '移除了多余的PHP标签组合');
        }
        
        // Ensure code starts with PHP tag if it looks like pure PHP
        $trimmedCode = trim($code);
        $phpOpenFull = "\x3c\x3fphp";
        $phpOpenShort = "\x3c\x3f";
        $hasPhpOpen = (strpos($trimmedCode, $phpOpenFull) === 0);
        $hasShortOpen = (strpos($trimmedCode, $phpOpenShort) === 0);
        if (!$hasPhpOpen && !$hasShortOpen) {
            $isPurePhp = preg_match('/^\s*(?:\/\*|\$|\/\/|namespace|use|class|function|if|for|foreach|while)/', $code);
            if ($isPurePhp) {
                $code = $phpOpenFull . "\n" . $code;
                $this->addFix('php_tags', '添加了缺失的PHP开始标签');
            }
        }
        
        return $code;
    }

    /**
     * 平衡括号
     */
    private function balanceBrackets(string $code): string
    {
        $brackets = [
            '(' => ')',
            '[' => ']',
            '{' => '}',
        ];

        foreach ($brackets as $open => $close) {
            $openCount = substr_count($code, $open);
            $closeCount = substr_count($code, $close);
            
            if ($openCount > $closeCount) {
                $diff = $openCount - $closeCount;
                $code .= str_repeat($close, $diff);
                $this->addFix('brackets', "补充了 {$diff} 个 '{$close}'");
            }
        }
        
        return $code;
    }

    /**
     * 添加缺失的分号
     */
    private function addMissingSemicolons(string $code): string
    {
        $patterns = [
            '/(\$[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*[^;{]+)(\n|$)/' => '$1;$2',
            '/(\breturn\s+[^;{]+)(\n|$)/' => '$1;$2',
            '/(\b(?:break|continue))(\s*\n)/' => '$1;$2',
        ];
        
        $fixCount = 0;
        foreach ($patterns as $pattern => $replacement) {
            $original = $code;
            $code = preg_replace($pattern, $replacement, $code);
            if ($code !== $original) {
                $fixCount++;
            }
        }
        
        if ($fixCount > 0) {
            $this->addFix('semicolons', "修复了 {$fixCount} 处可能缺失的分号");
        }
        
        return $code;
    }

    /**
     * 修复变量命名问题
     */
    private function fixVariableNaming(string $code): string
    {
        if (preg_match('/\$this\s*->\s*getData\s*\(/', $code)) {
            $this->addFix('variables', '警告: 发现$this->getData使用，建议使用$getConfig');
        }
        
        return $code;
    }

    /**
     * 清理多余的空白
     */
    private function cleanupWhitespace(string $code): string
    {
        $original = $code;
        $code = preg_replace('/\n{4,}/', "\n\n\n", $code);
        if ($code !== $original) {
            $this->addFix('whitespace', '清理了多余的空行');
        }
        
        $code = preg_replace('/[ \t]+$/m', '', $code);
        
        return $code;
    }

    /**
     * 修复PHP语法问题
     */
    private function fixPhpSyntax(string $php): string
    {
        $original = $php;
        $php = preg_replace('/\.\s+\$/', '. $', $php);
        $php = preg_replace('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s+\./', '$$$1 .', $php);
        if ($php !== $original) {
            $this->addFix('php_syntax', '规范化了字符串拼接格式');
        }
        
        return $php;
    }

    /**
     * 尝试修复并验证
     */
    public function fixAndValidate(string $code, CodeValidator $validator): array
    {
        // 第一次修复
        $fixedCode = $this->fix($code);
        $fixes = $this->getFixes();
        
        // 验证
        $validationResult = $validator->validate($fixedCode);
        
        // 如果还有错误，尝试更激进的修复
        if (!$validationResult['valid']) {
            $fixedCode = $this->aggressiveFix($fixedCode);
            $fixes = array_merge($fixes, $this->getFixes());
            $validationResult = $validator->validate($fixedCode);
        }
        
        return [
            'code' => $fixedCode,
            'fixes' => $fixes,
            'validation' => $validationResult,
        ];
    }

    /**
     * 更激进的修复尝试
     */
    private function aggressiveFix(string $code): string
    {
        $this->clearFixes();
        
        $original = $code;
        $code = preg_replace('/foreach\s*\([^)]*$/', '// foreach removed due to syntax error', $code);
        if ($code !== $original) {
            $this->addFix('aggressive', '移除了不完整的foreach循环');
        }
        
        $original = $code;
        $code = preg_replace('/function\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*$/', '// function removed due to syntax error', $code);
        if ($code !== $original) {
            $this->addFix('aggressive', '移除了不完整的函数定义');
        }
        
        return $code;
    }

    /**
     * 添加修复记录
     */
    private function addFix(string $type, string $description): void
    {
        $this->fixes[] = [
            'type' => $type,
            'description' => $description,
        ];
    }

    /**
     * 获取修复摘要
     */
    public function getFixSummary(): string
    {
        if (empty($this->fixes)) {
            return '无需修复';
        }
        
        $summary = ['自动修复 (' . count($this->fixes) . '):'];
        foreach ($this->fixes as $fix) {
            $summary[] = '  - [' . $fix['type'] . '] ' . $fix['description'];
        }
        
        return implode("\n", $summary);
    }
}
