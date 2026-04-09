<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI;

/**
 * AI生成代码验证器
 * 
 * 提供多层验证机制确保AI生成的组件代码质量
 */
class CodeValidator
{
    /**
     * 禁止模式列表
     */
    private const PROHIBITED_PATTERNS = [
        'backtick' => [
            'pattern' => '/`/',
            'message' => '反引号 ` 会导致模板语法错误',
            'context' => 'all',
        ],
        'this_usage' => [
            'pattern' => '/\$this\s*->/',
            'message' => '禁止直接使用$this，请使用$getConfig函数',
            'context' => 'php_variables',
        ],
        'superglobal' => [
            'pattern' => '/\$_(GET|POST|REQUEST|SESSION|COOKIE|SERVER|FILES|ENV)\b/',
            'message' => '禁止使用超全局变量',
            'context' => 'php_variables',
        ],
        'eval_function' => [
            'pattern' => '/\beval\s*\(/',
            'message' => '禁止使用eval函数',
            'context' => 'all',
        ],
        'exec_function' => [
            'pattern' => '/\b(?:exec|shell_exec|system|passthru|popen|proc_open)\s*\(/',
            'message' => '禁止使用系统命令执行函数',
            'context' => 'all',
        ],
        'include_require' => [
            'pattern' => '/\b(?:include|require|include_once|require_once)\s*[(\s]/',
            'message' => '禁止使用文件包含语句',
            'context' => 'php_variables',
        ],
        'file_operations' => [
            'pattern' => '/\b(?:file_get_contents|file_put_contents|fopen|fwrite|unlink|rmdir|mkdir)\s*\(/',
            'message' => '禁止使用文件操作函数',
            'context' => 'php_variables',
        ],
        'continue_break' => [
            'pattern' => '/\b(?:continue|break)\s*(?:\d+\s*)?;/',
            'message' => 'php_variables 禁止使用 continue/break 语句（仅支持简单赋值，不支持循环控制）',
            'context' => 'php_variables',
        ],
        'foreach_while_for' => [
            'pattern' => '/\b(?:foreach|while|for)\s*\(/',
            'message' => 'php_variables 禁止使用循环语句（foreach/while/for）',
            'context' => 'php_variables',
        ],
        'global_selector' => [
            'pattern' => '/^\s*(?:body|html|div|span|p|a|ul|li|h[1-6])\s*\{/m',
            'message' => 'CSS禁止使用全局选择器，必须使用#componentId前缀',
            'context' => 'css_extra',
        ],
        'component_id_in_js' => [
            'pattern' => '/\$componentId\b/',
            'message' => 'js_content 中禁止使用 $componentId，请使用 component 或 component.id',
            'context' => 'js_content',
        ],
        'js_var_keyword' => [
            'pattern' => '/\bvar\s+\w/',
            'message' => 'js_content 中禁止使用 var 声明变量（会提升作用域），请使用 const 或 let',
            'context' => 'js_content',
        ],
        'js_function_declaration' => [
            'pattern' => '/\bfunction\s+\w+\s*\(/',
            'message' => 'js_content 中禁止使用 function 声明函数（会污染作用域），请使用 const xxx = () => {} 或 const xxx = function() {}',
            'context' => 'js_content',
        ],
        'js_window_assignment' => [
            'pattern' => '/\bwindow\s*\.\s*\w+\s*=/',
            'message' => 'js_content 中禁止给 window 挂属性（全局污染），请使用局部变量',
            'context' => 'js_content',
        ],
        'js_document_query' => [
            'pattern' => '/\bdocument\s*\.\s*(?:querySelector|querySelectorAll|getElementById|getElementsByClassName)\s*\(/',
            'message' => 'js_content 中禁止使用 document.querySelector 等全局选择器，请使用 component.querySelector 限定在组件内',
            'context' => 'js_content',
        ],
    ];
    
    /**
     * CSS 通用类名黑名单（容易污染全局）
     */
    private const GENERIC_CSS_CLASSES = [
        'card', 'title', 'header', 'footer', 'content', 'wrapper', 'container',
        'item', 'list', 'row', 'col', 'box', 'panel', 'section', 'main',
        'nav', 'menu', 'btn', 'button', 'link', 'text', 'icon', 'image',
        'form', 'input', 'label', 'group', 'active', 'disabled', 'hidden',
        'show', 'hide', 'open', 'close', 'toggle', 'dropdown', 'modal',
    ];

    /**
     * 必需的组件元数据字段
     */
    private const REQUIRED_METADATA = [
        'code',
        'name',
    ];

    /**
     * 验证完整的组件代码
     */
    public function validate(string $code): array
    {
        $errors = [];
        $warnings = [];

        // 1. 语法检查
        $syntaxResult = $this->checkSyntax($code);
        if (!$syntaxResult['valid']) {
            $errors[] = $syntaxResult['error'];
        }

        // 2. 结构检查
        $structureResult = $this->checkStructure($code);
        if (!$structureResult['valid']) {
            $errors = array_merge($errors, $structureResult['errors']);
        }

        // 3. 禁止模式检测
        $prohibitedResult = $this->checkProhibitedPatterns($code);
        if (!$prohibitedResult['valid']) {
            $errors = array_merge($errors, $prohibitedResult['errors']);
        }

        // 4. 括号匹配检查
        $balanceResult = $this->checkBalancedTokens($code);
        if (!$balanceResult['valid']) {
            $errors = array_merge($errors, $balanceResult['errors']);
        }

        // 5. 变量作用域检查（警告级别）
        $scopeResult = $this->checkVariableScope($code);
        if (!$scopeResult['valid']) {
            $warnings = array_merge($warnings, $scopeResult['warnings']);
        }
        
        // 6. JavaScript部分检查
        $jsResult = $this->checkJavaScriptSection($code);
        if (!$jsResult['valid']) {
            $errors = array_merge($errors, $jsResult['errors']);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    
    /**
     * 检查JavaScript部分是否有问题
     */
    private function checkJavaScriptSection(string $code): array
    {
        $errors = [];
        
        // 检查是否有多行字符串在条件判断中
        // 模式：$jsContent !== '多行内容（超过100字符且包含换行）
        if (preg_match('/\$jsContent\s*!==\s*\'[^\n]{0,50}\n/s', $code)) {
            $errors[] = 'JavaScript条件判断中包含多行字符串，将导致语法错误';
        }
        
        // 检查是否有未正确闭合的heredoc
        if (preg_match('/<<<\s*[\'"]?(\w+)[\'"]?\s*\n/', $code, $matches)) {
            $heredocEnd = $matches[1];
            if (!preg_match('/\n' . preg_quote($heredocEnd, '/') . '\s*;/', $code)) {
                $errors[] = "Heredoc '{$heredocEnd}' 未正确闭合";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 验证AI返回的JSON数据
     */
    public function validateAiData(array $data, string $category = 'content'): array
    {
        $errors = [];
        $warnings = [];

        // 检查必需字段
        if ($category === 'content' && empty($data['html_content'])) {
            $errors[] = 'Content组件必须提供html_content字段';
        }

        // 验证各个字段
        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            $fieldResult = $this->validateField($key, $value);
            if (!$fieldResult['valid']) {
                $errors = array_merge($errors, $fieldResult['errors']);
            }
            if (!empty($fieldResult['warnings'])) {
                $warnings = array_merge($warnings, $fieldResult['warnings']);
            }
        }

        // 特别验证php_variables
        if (!empty($data['php_variables'])) {
            $phpResult = $this->validatePhpCode($data['php_variables']);
            if (!$phpResult['valid']) {
                $errors = array_merge($errors, $phpResult['errors']);
            }
        }

        // 验证CSS（css_extra 和 css_content）
        $cssFields = ['css_extra', 'css_content', 'css_responsive'];
        foreach ($cssFields as $cssField) {
            if (!empty($data[$cssField])) {
                $cssResult = $this->validateCss($data[$cssField]);
                if (!empty($cssResult['errors'])) {
                    $errors = array_merge($errors, array_map(fn($e) => "[{$cssField}] {$e}", $cssResult['errors']));
                }
                if (!empty($cssResult['warnings'])) {
                    $warnings = array_merge($warnings, array_map(fn($w) => "[{$cssField}] {$w}", $cssResult['warnings']));
                }
            }
        }
        
        // 验证JS
        if (!empty($data['js_content'])) {
            $jsResult = $this->validateJsContent($data['js_content']);
            if (!empty($jsResult['errors'])) {
                $errors = array_merge($errors, $jsResult['errors']);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 验证单个字段
     */
    public function validateField(string $fieldName, string $value): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::PROHIBITED_PATTERNS as $patternInfo) {
            // 检查是否适用于当前字段
            if ($patternInfo['context'] !== 'all' && $patternInfo['context'] !== $fieldName) {
                continue;
            }

            if (preg_match($patternInfo['pattern'], $value)) {
                $errors[] = "[{$fieldName}] " . $patternInfo['message'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * PHP语法检查
     */
    public function checkSyntax(string $code): array
    {
        // 确保代码以PHP标签开始
        $testCode = $code;
        $phpOpen = chr(60) . chr(63) . 'php';
        if (strpos(trim($code), $phpOpen) !== 0 && strpos(trim($code), chr(60) . chr(63)) !== 0) {
            $testCode = $phpOpen . ' ' . $code;
        }

        // 创建临时文件
        $tempFile = tempnam(sys_get_temp_dir(), 'php_syntax_check_');
        if ($tempFile === false) {
            return ['valid' => true, 'error' => null];
        }

        file_put_contents($tempFile, $testCode);

        // 执行语法检查
        $output = [];
        $returnCode = 0;
        exec('php -l ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnCode);

        // 删除临时文件
        @unlink($tempFile);

        if ($returnCode !== 0) {
            $errorMessage = implode("\n", $output);
            $errorMessage = preg_replace('/in\s+[^\s]+\s+on\s+line/', 'on line', $errorMessage);
            $errorMessage = preg_replace('/Errors parsing [^\n]+/', '', $errorMessage);
            return [
                'valid' => false,
                'error' => 'PHP语法错误: ' . trim($errorMessage),
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * 组件结构检查
     */
    public function checkStructure(string $code): array
    {
        $errors = [];

        if (strpos($code, '@component_start') === false) {
            $errors[] = '缺少组件元数据块 (@component_start)';
        }
        if (strpos($code, '@component_end') === false) {
            $errors[] = '缺少组件元数据结束标记 (@component_end)';
        }

        if (strpos($code, '@fields_start') === false) {
            $errors[] = '缺少字段定义块 (@fields_start)';
        }
        if (strpos($code, '@fields_end') === false) {
            $errors[] = '缺少字段定义结束标记 (@fields_end)';
        }

        if (preg_match('/@component_start\s*(.*?)@component_end/s', $code, $matches)) {
            $metadata = $matches[1];
            foreach (self::REQUIRED_METADATA as $field) {
                if (!preg_match('/\b' . $field . '\s*:/i', $metadata)) {
                    $errors[] = "缺少必需的元数据字段: {$field}";
                }
            }
        }

        if (strpos($code, '$componentId') === false && strpos($code, 'componentId') === false) {
            $errors[] = '组件应使用唯一ID ($componentId) 进行样式隔离';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 禁止模式检测
     */
    public function checkProhibitedPatterns(string $code, ?string $context = null): array
    {
        $errors = [];

        foreach (self::PROHIBITED_PATTERNS as $name => $patternInfo) {
            if ($context !== null && $patternInfo['context'] !== 'all' && $patternInfo['context'] !== $context) {
                continue;
            }

            if (preg_match($patternInfo['pattern'], $code)) {
                $errors[] = $patternInfo['message'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 括号/引号匹配检查
     */
    public function checkBalancedTokens(string $code): array
    {
        $errors = [];

        $brackets = [
            '(' => ')',
            '[' => ']',
            '{' => '}',
        ];

        foreach ($brackets as $open => $close) {
            $openCount = substr_count($code, $open);
            $closeCount = substr_count($code, $close);
            
            if ($openCount !== $closeCount) {
                $diff = $openCount - $closeCount;
                if ($diff > 0) {
                    $errors[] = "括号不匹配: 缺少 {$diff} 个 '{$close}'";
                } else {
                    $errors[] = "括号不匹配: 多余 " . abs($diff) . " 个 '{$close}'";
                }
            }
        }

        $phpOpenPattern = '/<\x3f(?:php)?/i';
        $phpOpenCount = preg_match_all($phpOpenPattern, $code);
        $phpCloseTag = chr(63) . chr(62);
        $phpCloseCount = substr_count($code, $phpCloseTag);
        if ($phpCloseCount > $phpOpenCount) {
            $errors[] = "PHP标签不匹配: 多余的结束标签";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 变量作用域检查
     */
    public function checkVariableScope(string $code): array
    {
        $warnings = [];

        $definedVars = [];
        preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=/', $code, $matches);
        if (!empty($matches[1])) {
            $definedVars = array_unique($matches[1]);
        }

        $predefinedVars = [
            'this', 'componentId', 'config', 'styleSettings', 'componentConfig',
            'page', 'getConfig', 'i', 'key', 'value', 'item', 'index',
            '_GET', '_POST', '_REQUEST', '_SESSION', '_COOKIE', '_SERVER',
        ];
        $definedVars = array_merge($definedVars, $predefinedVars);

        preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*=)/', $code, $matches);
        if (!empty($matches[1])) {
            $usedVars = array_unique($matches[1]);
            foreach ($usedVars as $var) {
                if (!in_array($var, $definedVars)) {
                    $warnings[] = "可能使用了未定义的变量: \${$var}";
                }
            }
        }

        return [
            'valid' => empty($warnings),
            'warnings' => $warnings,
        ];
    }

    /**
     * 验证PHP代码片段
     */
    public function validatePhpCode(string $phpCode): array
    {
        $errors = [];

        // 早期检测 `=>` 数组语法（AI 误解格式时会产生 `$key => value` 而非 `$key = value`）
        // 这种语法在 try{ php_variables } 块内会导致 "Parse error: unexpected token => on line N"
        if (preg_match('/^\s*\$[a-zA-Z_]\w*\s*=>/m', $phpCode)) {
            $errors[] = 'php_variables 检测到 `=>` 数组语法（应为 `$var = value` 而非 `$var => value`）';
        }

        $phpOpenPattern = '/<\x3f(?:php|=)?/';
        if (preg_match($phpOpenPattern, $phpCode)) {
            $errors[] = 'php_variables字段不应包含PHP开始标签';
        }

        $closeTag = chr(63) . chr(62);
        if (strpos($phpCode, $closeTag) !== false) {
            $errors[] = 'php_variables字段不应包含PHP结束标签';
        }

        if (strpos($phpCode, '{') !== false || strpos($phpCode, '}') !== false) {
            $errors[] = 'php_variables 只能为简单赋值（每行 $var = ...;），禁止包含大括号 { }';
        }

        if (empty($errors)) {
            $openTag = chr(60) . chr(63) . 'php';
            $wrappedCode = $openTag . "\n" . $phpCode . "\n" . $closeTag;
            $syntaxResult = $this->checkSyntax($wrappedCode);
            if (!$syntaxResult['valid']) {
                $errors[] = $syntaxResult['error'];
            }
        }

        $prohibitedResult = $this->checkProhibitedPatterns($phpCode, 'php_variables');
        if (!$prohibitedResult['valid']) {
            $errors = array_merge($errors, $prohibitedResult['errors']);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 验证CSS代码
     */
    public function validateCss(string $css): array
    {
        $errors = [];
        $warnings = [];

        $openBraces = substr_count($css, '{');
        $closeBraces = substr_count($css, '}');
        if ($openBraces !== $closeBraces) {
            $errors[] = 'CSS大括号不匹配';
        }

        $globalSelectors = ['body', 'html', '*'];
        foreach ($globalSelectors as $selector) {
            if (preg_match('/(?:^|\s|,)' . preg_quote($selector, '/') . '\s*\{/', $css)) {
                $errors[] = "CSS使用了全局选择器 '{$selector}'，必须使用组件ID前缀 #componentId";
            }
        }

        if (!preg_match('/#[a-zA-Z]/', $css) && strlen($css) > 50) {
            $errors[] = 'CSS样式必须使用 #<?= $componentId ?> 前缀进行作用域隔离';
        }
        
        // 检查是否使用了通用类名（容易污染全局）
        $genericClassesUsed = [];
        foreach (self::GENERIC_CSS_CLASSES as $genericClass) {
            // 匹配 .card { 或 .card, 或 .card:hover 等
            if (preg_match('/\.' . preg_quote($genericClass, '/') . '\b(?![a-z0-9_-])/i', $css)) {
                $genericClassesUsed[] = '.' . $genericClass;
            }
        }
        if (!empty($genericClassesUsed)) {
            $errors[] = 'CSS 使用了通用类名（会污染全局）：' . implode(', ', array_slice($genericClassesUsed, 0, 5)) . 
                       '。请使用组件唯一前缀，如 .pb-{组件代码}-{元素名}';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 验证JS代码（作用域隔离）
     */
    public function validateJsContent(string $js): array
    {
        $errors = [];
        
        if (empty(trim($js))) {
            return ['valid' => true, 'errors' => []];
        }
        
        // 检查禁止模式
        $prohibitedResult = $this->checkProhibitedPatterns($js, 'js_content');
        if (!$prohibitedResult['valid']) {
            $errors = array_merge($errors, array_map(fn($e) => "[js_content] {$e}", $prohibitedResult['errors']));
        }
        
        // 检查是否有未使用 const/let 声明的顶层变量赋值
        // 例如 myVar = 1; 会创建全局变量
        if (preg_match('/(?:^|;\s*|\n\s*)([a-zA-Z_]\w*)\s*=\s*[^=]/m', $js, $matches)) {
            // 排除对象属性赋值（如 obj.prop = 或 component.innerHTML =）
            $varName = $matches[1];
            if (!in_array($varName, ['component', 'this'])) {
                // 检查该变量是否在之前用 const/let 声明过
                if (!preg_match('/\b(?:const|let)\s+' . preg_quote($varName, '/') . '\b/', $js)) {
                    $errors[] = "[js_content] 变量 '{$varName}' 未用 const/let 声明，可能创建全局变量。请使用 const {$varName} = ...";
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 获取验证摘要
     */
    public function getValidationSummary(array $result): string
    {
        $summary = [];
        
        if ($result['valid']) {
            $summary[] = '代码验证通过';
        } else {
            $summary[] = '代码验证失败';
        }

        if (!empty($result['errors'])) {
            $summary[] = '错误 (' . count($result['errors']) . '):';
            foreach ($result['errors'] as $error) {
                $summary[] = '  - ' . $error;
            }
        }

        if (!empty($result['warnings'])) {
            $summary[] = '警告 (' . count($result['warnings']) . '):';
            foreach ($result['warnings'] as $warning) {
                $summary[] = '  - ' . $warning;
            }
        }

        return implode("\n", $summary);
    }
}
