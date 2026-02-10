<?php

declare(strict_types=1);

/**
 * AI组件框架构建服务
 * 
 * 负责加载框架模板并将AI生成的JSON数据回填到框架中
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

use Weline\Framework\Manager\ObjectManager;

class FrameworkBuilder
{
    /**
     * 框架模板目录
     */
    private const FRAMEWORK_DIR = BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/style/_ai_frameworks/';
    
    /**
     * 框架文件映射
     */
    private const FRAMEWORK_FILES = [
        'header' => 'header_framework.phtml',
        'content' => 'content_framework.phtml',
        'footer' => 'footer_framework.phtml',
    ];
    
    /**
     * 所有可用的占位符
     */
    private const PLACEHOLDERS = [
        'COMPONENT_NAME',
        'COMPONENT_NAME_EN', 
        'COMPONENT_DESC',
        'CATEGORY',
        'EXTRA_FIELDS',
        'PHP_VARIABLES',
        'CSS_EXTRA',
        'CSS_RESPONSIVE',
        'CSS_CONTENT',
        'HTML_CONTENT',
        'HTML_EXTRA',
        'HTML_EXTRA_COLUMN',
        'FOOTER_EXTRA_TEXT',
        'JS_CONTENT',
        'WRAPPER_TAG',
    ];
    
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    /**
     * 获取单例实例
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 加载框架模板
     * 
     * @param string $category 组件分类 (header/content/footer)
     * @return string 框架模板内容
     * @throws \Exception 如果框架不存在
     */
    public function loadFramework(string $category): string
    {
        $category = strtolower($category);
        // #region agent log
        @file_put_contents((defined('BP') ? BP : dirname(__DIR__, 6)) . '/.cursor/debug.log', json_encode(['hypothesisId' => 'H4', 'location' => 'FrameworkBuilder::loadFramework', 'message' => 'entry', 'data' => ['category' => $category], 'timestamp' => (int)(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
        // #endregion
        if (!isset(self::FRAMEWORK_FILES[$category])) {
            throw new \Exception("未知的组件分类: {$category}");
        }
        
        $frameworkFile = self::FRAMEWORK_DIR . self::FRAMEWORK_FILES[$category];
        
        if (!file_exists($frameworkFile)) {
            throw new \Exception("框架模板文件不存在: {$frameworkFile}");
        }
        
        return file_get_contents($frameworkFile);
    }

    /**
     * 获取框架模板的「仅 PHP 变量定义块」（从文件头到首个 ?＞ 结束的 PHP 块），并替换 {{PHP_VARIABLES}}
     * 用于智能体生成组件时：先输出该块再输出 AI 的 html/css/js，保证 $brandLogo 等框架变量已定义
     *
     * @param string $category header|footer|content
     * @param string $phpVariables AI 的 php_variables 内容（可选）
     * @return string 可写入 phtml 的 PHP 块（含 ?＞ 结尾）
     */
    public function getFrameworkPhpBlock(string $category, string $phpVariables = ''): string
    {
        $category = strtolower($category);
        if (!isset(self::FRAMEWORK_FILES[$category])) {
            return "<?php\n// unknown category: {$category}\n?>\n";
        }
        $framework = $this->loadFramework($category);
        // 首个 ?> 之前为初始 PHP 块（含变量定义与 try{ {{PHP_VARIABLES}} }）
        $closeTag = '?>';
        $pos = strpos($framework, $closeTag);
        if ($pos === false) {
            return "<?php\n// no php block\n?>\n";
        }
        $block = substr($framework, 0, $pos + strlen($closeTag));
        $block = str_replace('{{PHP_VARIABLES}}', $phpVariables, $block);
        return $block;
    }

    /**
     * 按区域返回框架已注入的变量列表（与框架 phtml 严格对齐，供校验与提示共用）
     *
     * @param string $category header|footer|content
     * @return array 带 $ 前缀的变量名列表，如 ['$brandLogo', '$brandName', ...]
     */
    public function getFrameworkProvidedVariables(string $category): array
    {
        $category = strtolower($category);
        $common = [
            '$page', '$styleSettings', '$componentConfig', '$config', '$getConfig', '$componentId',
        ];
        $footer = array_merge($common, [
            '$parseLinks', '$brandLogo', '$brandName', '$brandDesc',
            '$col1Title', '$col1Items', '$col2Title', '$col2Items',
            '$showSocial', '$socialLinks', '$copyrightText', '$startYear', '$currentYear', '$yearDisplay',
            '$bgColor', '$textColor', '$titleColor', '$linkColor', '$linkHoverColor', '$accentColor',
            '$item', '$platform', '$url',
        ]);
        $header = array_merge($common, [
            '$showLogo', '$logoImage', '$logoText', '$logoWidth',
            '$showNav', '$navItems', '$showCta', '$ctaText', '$ctaUrl',
            '$bgColor', '$textColor', '$linkColor', '$linkHoverColor', '$accentColor',
        ]);
        $content = array_merge($common, [
            '$title', '$subtitle', '$description',
            '$containerWidth', '$paddingTop', '$paddingBottom', '$textAlign',
            '$bgType', '$bgColor', '$bgGradient', '$bgImage', '$textColor', '$titleColor', '$accentColor',
            '$bgStyle', '$maxWidth', '$cssPrefix',
        ]);
        return match ($category) {
            'footer' => $footer,
            'header' => $header,
            'content' => $content,
            default => $content,
        };
    }
    
    /**
     * 构建完整的组件代码
     * 
     * @param string $category 组件分类
     * @param array $componentInfo 组件基本信息
     * @param array $aiData AI返回的JSON数据
     * @return string 完整的组件代码
     */
    public function buildComponent(string $category, array $componentInfo, array $aiData): string
    {
        // 1. 预处理AI数据 - 移除危险内容
        $aiData = $this->sanitizeAiData($aiData);
        
        // 兜底：content 组件必须有基础 HTML
        if ($category === 'content' && (empty($aiData['html_content']) || !is_string($aiData['html_content']))) {
            $aiData['html_content'] = '<div class="ai-empty">AI content placeholder</div>';
        }
        
        // 2. 验证每个字段
        foreach ($aiData as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $validation = $this->validateField($key, $value);
            if (!$validation['valid']) {
                // 记录警告但继续执行
                error_log("AI组件字段验证警告 [{$key}]: " . implode(', ', $validation['warnings']));
            }
        }
        
        // 3. 加载框架模板
        $framework = $this->loadFramework($category);
        
        // 4. 准备替换数据
        $replacements = $this->prepareReplacements($category, $componentInfo, $aiData);
        
        // 5. 安全替换（使用标记确保替换正确）
        $result = $this->safeReplace($framework, $replacements);
        
        // 6. 清理未使用的占位符
        $result = $this->cleanupPlaceholders($result);
        
        return $result;
    }
    
    /**
     * 安全替换占位符
     * 
     * @param string $template 模板内容
     * @param array $replacements 替换映射
     * @return string 替换后的内容
     */
    private function safeReplace(string $template, array $replacements): string
    {
        $result = $template;
        
        foreach ($replacements as $placeholder => $value) {
            // 确保值是字符串
            if (!is_string($value)) {
                $value = '';
            }
            
            // 执行替换
            $result = str_replace('{{' . $placeholder . '}}', $value, $result);
        }
        
        return $result;
    }
    
    /**
     * 清理AI数据中的危险内容
     * 
     * @param array $data AI返回的数据
     * @return array 清理后的数据
     */
    private function sanitizeAiData(array $data): array
    {
        // 清理 HTML 相关字段中的 PHP 标签（防止短标签触发语法错误）
        $htmlKeys = [
            'html_content',
            'html_extra',
            'html_extra_column',
            'footer_extra_text',
        ];
        foreach ($htmlKeys as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = str_replace(['<?', '?>'], ['&lt;?', '?&gt;'], $data[$key]);
            }
        }
        
        // 移除所有字段中的反引号
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = str_replace('`', "'", $value);
            }
        }
        
        // 清理 php_variables 中的 PHP 标签
        if (isset($data['php_variables']) && is_string($data['php_variables'])) {
            $pv = $data['php_variables'];
            if (strpos($pv, '{') !== false || strpos($pv, '}') !== false) {
                // php_variables 仅允许简单赋值（$var = ...;），禁止大括号。含大括号时只保留不含 { } 的行，避免生成无效 PHP 导致 Unclosed '{' on line N
                $lines = explode("\n", $pv);
                $safe = [];
                foreach ($lines as $line) {
                    if (strpos($line, '{') === false && strpos($line, '}') === false) {
                        $safe[] = $line;
                    }
                }
                $data['php_variables'] = implode("\n", $safe);
            }
            $pv = $data['php_variables'];
            // 移除所有 PHP 开始标签（不仅仅是开头的）
            $pv = preg_replace('/<\?(?:php)?\s*/i', '', $pv);
            // 移除所有 PHP 结束标签（不仅仅是结尾的）- 这非常重要，否则会破坏模板结构
            $pv = preg_replace('/\s*\?>\s*/', '', $pv);
            // 检查并修复不平衡的控制结构（仅针对 if/foreach 等，不再含 { }）
            $data['php_variables'] = $this->balanceControlStructures($pv);
        }
        
        // 清理 CSS 相关字段中的 PHP 标签
        $cssKeys = [
            'css_extra',
            'css_responsive',
            'css_content',
        ];
        foreach ($cssKeys as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = str_replace(['<?', '?>'], ['', ''], $data[$key]);
            }
        }
        
        // 清理 js_content 
        if (isset($data['js_content']) && is_string($data['js_content'])) {
            // 移除 </script> 标签
            $data['js_content'] = str_replace('</script>', '<\/script>', $data['js_content']);
            
            // 构造 PHP 标签字符串
            $phpOpen = chr(60) . chr(63);
            $phpClose = chr(63) . chr(62);
            
            // 移除残留的 PHP 标签和代码块
            $data['js_content'] = str_replace($phpOpen . 'php', '', $data['js_content']);
            $data['js_content'] = str_replace($phpOpen . '=', '', $data['js_content']);
            $data['js_content'] = str_replace($phpOpen, '', $data['js_content']);
            $data['js_content'] = str_replace($phpClose, '', $data['js_content']);
        }
        
        return $data;
    }
    
    /**
     * 验证单个字段
     * 
     * @param string $fieldName 字段名
     * @param string $value 字段值
     * @return array ['valid' => bool, 'warnings' => array]
     */
    private function validateField(string $fieldName, string $value): array
    {
        $warnings = [];
        
        // 检查反引号
        if (strpos($value, '`') !== false) {
            $warnings[] = '包含反引号字符';
        }
        
        // 检查 html_content 中的 PHP 标签
        if ($fieldName === 'html_content') {
            if (preg_match('/<\?(php|=)/i', $value)) {
                $warnings[] = 'HTML内容中包含PHP标签';
            }
        }
        
        // 检查 php_variables 中的危险函数
        if ($fieldName === 'php_variables') {
            $dangerousFunctions = ['eval', 'exec', 'shell_exec', 'system', 'passthru'];
            foreach ($dangerousFunctions as $func) {
                if (preg_match('/\b' . $func . '\s*\(/i', $value)) {
                    $warnings[] = "包含危险函数: {$func}";
                }
            }
        }
        
        return [
            'valid' => empty($warnings),
            'warnings' => $warnings,
        ];
    }
    
    /**
     * 平衡PHP控制结构
     * 
     * 检测并移除不平衡的控制结构，防止破坏模板
     * 
     * @param string $code PHP代码
     * @return string 平衡后的代码
     */
    private function balanceControlStructures(string $code): string
    {
        // 定义控制结构对
        $structures = [
            'if' => 'endif',
            'foreach' => 'endforeach',
            'for' => 'endfor',
            'while' => 'endwhile',
            'switch' => 'endswitch',
        ];
        
        foreach ($structures as $start => $end) {
            // 统计开始和结束标记
            // 匹配 if(...)：或 if (...): 形式
            $startPattern = '/\b' . $start . '\s*\([^)]*\)\s*:/';
            $endPattern = '/\b' . $end . '\s*;/';
            
            preg_match_all($startPattern, $code, $startMatches);
            preg_match_all($endPattern, $code, $endMatches);
            
            $startCount = count($startMatches[0]);
            $endCount = count($endMatches[0]);
            
            // 如果结束标记多于开始标记，移除多余的结束标记
            if ($endCount > $startCount) {
                $excess = $endCount - $startCount;
                for ($i = 0; $i < $excess; $i++) {
                    // 从后往前移除多余的结束标记
                    $code = preg_replace('/\b' . $end . '\s*;\s*$/', '', $code, 1);
                    if ($code === null) break;
                    $code = preg_replace('/\b' . $end . '\s*;/', '', $code, 1);
                }
            }
            // 如果开始标记多于结束标记，添加缺失的结束标记
            elseif ($startCount > $endCount) {
                $missing = $startCount - $endCount;
                for ($i = 0; $i < $missing; $i++) {
                    $code .= "\n" . $end . ";";
                }
            }
        }
        
        // 同样处理大括号形式的控制结构
        $openBraces = substr_count($code, '{');
        $closeBraces = substr_count($code, '}');
        
        if ($openBraces > $closeBraces) {
            // 添加缺失的闭合大括号
            $code .= str_repeat("\n}", $openBraces - $closeBraces);
        } elseif ($closeBraces > $openBraces) {
            // 移除多余的闭合大括号（从末尾开始）
            $excess = $closeBraces - $openBraces;
            for ($i = 0; $i < $excess; $i++) {
                $code = preg_replace('/\}\s*$/', '', $code, 1);
            }
        }
        
        return trim($code);
    }
    
    /**
     * 准备替换数据
     * 
     * @param string $category 组件分类
     * @param array $componentInfo 组件基本信息
     * @param array $aiData AI返回的数据
     * @return array 占位符 => 值 的映射
     */
    private function prepareReplacements(string $category, array $componentInfo, array $aiData): array
    {
        $replacements = [];
        
        // 基本组件信息
        $replacements['COMPONENT_NAME'] = $componentInfo['name'] ?? 'AI Generated Component';
        $replacements['COMPONENT_NAME_EN'] = $componentInfo['name_en'] ?? $this->generateEnglishName($componentInfo['name'] ?? '');
        $replacements['COMPONENT_DESC'] = $componentInfo['description'] ?? '';
        $replacements['CATEGORY'] = $category;
        $replacements['WRAPPER_TAG'] = $category === 'header' ? 'header' : ($category === 'footer' ? 'footer' : 'section');
        
        // AI生成的代码
        $replacements['EXTRA_FIELDS'] = $this->formatExtraFields($aiData['extra_fields'] ?? '');
        // 注入前再次移除含 { } 的行，避免 try{ {{PHP_VARIABLES}} } 内出现未闭合大括号导致 Parse error on line N
        $pv = $aiData['php_variables'] ?? '';
        $pvLines = explode("\n", str_replace("\r\n", "\n", $pv));
        $pvSafe = [];
        foreach ($pvLines as $line) {
            if (strpos($line, '{') === false && strpos($line, '}') === false) {
                $pvSafe[] = $line;
            }
        }
        $replacements['PHP_VARIABLES'] = implode("\n", $pvSafe);
        $replacements['CSS_EXTRA'] = $aiData['css_extra'] ?? '';
        $replacements['CSS_RESPONSIVE'] = $aiData['css_responsive'] ?? '';
        $replacements['CSS_CONTENT'] = $aiData['css_content'] ?? '';
        $replacements['HTML_CONTENT'] = $aiData['html_content'] ?? '';
        $replacements['HTML_EXTRA'] = $aiData['html_extra'] ?? '';
        $replacements['HTML_EXTRA_COLUMN'] = $aiData['html_extra_column'] ?? '';
        $replacements['FOOTER_EXTRA_TEXT'] = $aiData['footer_extra_text'] ?? '';
        $replacements['JS_CONTENT'] = $aiData['js_content'] ?? '';
        
        return $replacements;
    }
    
    /**
     * 格式化额外的字段定义
     * 
     * @param string $fields 字段定义字符串
     * @return string 格式化后的字段定义
     */
    private function formatExtraFields(string $fields): string
    {
        if (empty($fields)) {
            return '';
        }
        
        // 确保每行以正确的格式开始
        $lines = explode("\n", $fields);
        $formatted = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // 如果不是以 group: 或字段名开头，添加 * 前缀
            if (!preg_match('/^(group:|[a-z_]+\.)/', $line)) {
                $line = '* ' . $line;
            } else if (!str_starts_with($line, '*')) {
                $line = '* ' . $line;
            }
            
            $formatted[] = $line;
        }
        
        return implode("\n", $formatted);
    }
    
    /**
     * 生成英文名称
     * 
     * @param string $name 中文名称
     * @return string 英文名称
     */
    private function generateEnglishName(string $name): string
    {
        // 简单转换，实际应用中可以使用翻译服务
        return preg_replace('/[^a-zA-Z0-9\s]/', '', $name) ?: 'AI Component';
    }
    
    /**
     * 清理未使用的占位符
     * 
     * @param string $content 内容
     * @return string 清理后的内容
     */
    private function cleanupPlaceholders(string $content): string
    {
        // 移除所有未替换的占位符
        foreach (self::PLACEHOLDERS as $placeholder) {
            $content = str_replace('{{' . $placeholder . '}}', '', $content);
        }
        
        // 清理空的样式块
        $content = preg_replace('/\/\* AI生成的额外CSS \*\/\s*\n\s*\n/', "/* AI生成的额外CSS */\n", $content);
        
        return $content;
    }
    
    /**
     * 验证AI返回的JSON数据
     * 
     * @param array $data AI返回的数据
     * @param string $category 组件分类
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateAiData(array $data, string $category): array
    {
        $errors = [];
        
        // 基本验证：至少要有HTML内容
        if (empty($data['html_content']) && $category === 'content') {
            $errors[] = '缺少必需的 html_content 字段';
        }
        
        // 验证PHP代码语法（如果有）
        if (!empty($data['php_variables'])) {
            $pv = $data['php_variables'];
            $hasBrace = (strpos($pv, '{') !== false || strpos($pv, '}') !== false);
            // #region agent log
            @file_put_contents(
                (defined('BP') ? BP : dirname(__DIR__, 6)) . '/.cursor/debug.log',
                json_encode(['hypothesisId' => 'H2', 'location' => 'FrameworkBuilder::validateAiData', 'message' => 'php_variables check', 'data' => ['pvLen' => strlen($pv), 'hasBrace' => $hasBrace, 'willCallCheckPhpSyntax' => !$hasBrace], 'timestamp' => (int)(microtime(true) * 1000)]) . "\n",
                FILE_APPEND | LOCK_EX
            );
            // #endregion
            if ($hasBrace) {
                $errors[] = 'php_variables 只能为简单赋值，禁止包含大括号 { }';
            } else {
                $syntaxCheck = $this->checkPhpSyntax($pv);
                if (!$syntaxCheck['valid']) {
                    // #region agent log
                    @file_put_contents(
                        (defined('BP') ? BP : dirname(__DIR__, 6)) . '/.cursor/debug.log',
                        json_encode(['hypothesisId' => 'H2', 'location' => 'FrameworkBuilder::validateAiData', 'message' => 'checkPhpSyntax failed', 'data' => ['error' => $syntaxCheck['error']], 'timestamp' => (int)(microtime(true) * 1000)]) . "\n",
                        FILE_APPEND | LOCK_EX
                    );
                    // #endregion
                    $errors[] = 'PHP代码语法错误: ' . $syntaxCheck['error'];
                }
            }
        }
        
        // 验证CSS（基本检查）
        if (!empty($data['css_extra'])) {
            if (!$this->validateCss($data['css_extra'])) {
                $errors[] = 'CSS代码可能存在语法问题';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * 检查PHP语法
     * 
     * @param string $code PHP代码
     * @return array ['valid' => bool, 'error' => string]
     */
    private function checkPhpSyntax(string $code): array
    {
        // #region agent log
        $lineCount = substr_count($code, "\n") + 2;
        @file_put_contents(
            (defined('BP') ? BP : dirname(__DIR__, 6)) . '/.cursor/debug.log',
            json_encode(['hypothesisId' => 'H2', 'location' => 'FrameworkBuilder::checkPhpSyntax', 'message' => 'entry', 'data' => ['codeLen' => strlen($code), 'lineCount' => $lineCount], 'timestamp' => (int)(microtime(true) * 1000)]) . "\n",
            FILE_APPEND | LOCK_EX
        );
        // #endregion
        // 创建临时文件进行语法检查
        $tempFile = sys_get_temp_dir() . '/ai_php_check_' . uniqid() . '.php';
        $phpOpen = chr(60) . chr(63) . 'php';
        $fullCode = $phpOpen . "\n" . $code;
        file_put_contents($tempFile, $fullCode);
        
        $output = [];
        $returnVar = 0;
        exec("php -l " . escapeshellarg($tempFile) . " 2>&1", $output, $returnVar);
        
        unlink($tempFile);
        
        if ($returnVar !== 0) {
            // #region agent log
            @file_put_contents(
                (defined('BP') ? BP : dirname(__DIR__, 6)) . '/.cursor/debug.log',
                json_encode(['hypothesisId' => 'H2', 'location' => 'FrameworkBuilder::checkPhpSyntax', 'message' => 'php -l failed', 'data' => ['rawOutput' => $output], 'timestamp' => (int)(microtime(true) * 1000)]) . "\n",
                FILE_APPEND | LOCK_EX
            );
            // #endregion
        }
        return [
            'valid' => $returnVar === 0,
            'error' => $returnVar !== 0 ? implode("\n", $output) : '',
        ];
    }
    
    /**
     * 基本CSS验证
     * 
     * @param string $css CSS代码
     * @return bool 是否有效
     */
    private function validateCss(string $css): bool
    {
        // 检查括号是否匹配
        $openBraces = substr_count($css, '{');
        $closeBraces = substr_count($css, '}');
        
        return $openBraces === $closeBraces;
    }
    
    /**
     * 获取框架的提示词说明
     * 
     * @param string $category 组件分类
     * @return string 提示词说明
     */
    public function getFrameworkPromptGuide(string $category): string
    {
        $category = strtolower($category);
        // #region agent log
        @file_put_contents((defined('BP') ? BP : dirname(__DIR__, 6)) . '/.cursor/debug.log', json_encode(['hypothesisId' => 'H4', 'location' => 'FrameworkBuilder::getFrameworkPromptGuide', 'message' => 'entry', 'data' => ['category' => $category], 'timestamp' => (int)(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
        // #endregion
        $commonRules = <<<'RULES'

【重要 - 框架已提供的变量，不要重复定义】
- $page, $config, $componentConfig, $styleSettings - 数据变量
- $getConfig - 配置读取函数
- $componentId - 组件唯一ID
- $showLogo, $showNav, $showCta, $navItems 等 - Header框架变量
- $title, $subtitle, $description 等 - Content框架变量

【重要 - php_variables 格式要求】
- 只用于定义简单变量，如：$myVar = $getConfig('key', 'default');
- 每行必须是完整的语句，以分号结尾
- 禁止包含 PHP 开始或结束标签
- 禁止使用 if/foreach/for/while 等控制结构
- 禁止定义函数或类
- 如果不需要额外变量，php_variables 应该为空字符串

【重要 - js_content 格式要求】
- 只提供组件内部的JavaScript逻辑代码
- 框架已提供 component 变量指向组件DOM元素
- 不要包含 document.addEventListener('DOMContentLoaded', ...)
- 不要包含 (function(){...})() 自执行函数包装
- 直接写操作 component 元素的代码即可
- 禁止使用任何 PHP 标签
- js_content 必须是纯 JavaScript，不能混合 PHP 代码
- 字符串引号必须成对且正确转义：推荐统一使用双引号，避免单引号冲突；如必须用单引号，内部单引号必须转义
- 禁止在 js_content 中使用 $componentId 或 "# $componentId" 这样的 PHP 变量，请使用 component / component.id

【正确的 js_content 示例】
```
const buttons = component.querySelectorAll('.btn');
buttons.forEach(btn => {
    btn.addEventListener('click', () => btn.classList.toggle('active'));
});

// 如需使用配置值，通过 data-* 属性获取
const config = JSON.parse(component.dataset.config || '{}');
```

【错误的 js_content 示例 - 绝对不要这样写】
```
// 错误1：不要使用 DOMContentLoaded 包装
document.addEventListener('DOMContentLoaded', function() { });

// 错误2：不要在 JS 中嵌入服务端代码
if (serverVar) { }  // 禁止在JS中使用服务端变量

// 错误3：单引号不转义
const text = 'I'm broken';
```
RULES;

        $guides = [
            'header' => <<<'GUIDE'
## Header 组件框架 — 返回 JSON 格式

框架已包含：Logo 区域、导航链接循环、CTA 按钮、汉堡菜单、Flex 布局、基础颜色。
你负责用 css_extra 增强视觉（渐变背景、hover 动画、阴影、滚动效果），用 js_content 实现交互（滚动固定、菜单展开动画）。

```json
{
    "extra_fields": "额外配置字段（可选）",
    "php_variables": "额外 PHP 变量（可选）",
    "css_extra": "增强样式（必填！— 让 header 看起来专业美观）",
    "html_extra": "额外装饰 HTML（可选 — 禁止输出导航或 Logo）",
    "js_content": "交互逻辑（可选 — 滚动固定、移动端菜单等）"
}
```
GUIDE,
            
            'content' => <<<'GUIDE'
## Content 组件框架 — 返回 JSON 格式

框架已包含：标题/副标题/描述头部、背景色、容器布局。
你负责用 html_content 实现核心内容（卡片、FAQ、画廊等），用 css_extra 写样式。

```json
{
    "extra_fields": "额外配置字段（可选）",
    "php_variables": "额外 PHP 变量（可选）",
    "css_extra": "CSS 样式（必填）",
    "css_responsive": "移动端样式（可选）",
    "html_content": "核心内容 HTML（必填！— 放在 .ai-content-body 内）",
    "js_content": "交互逻辑（可选）"
}
```
GUIDE,
            
            'footer' => <<<'GUIDE'
## Footer 组件框架 — 返回 JSON 格式

框架已包含：品牌 Logo/描述、两列链接、社交图标、版权信息、Grid 布局。
你负责用 css_extra 增强视觉，用 html_extra_column 添加第三列链接，用 html_extra 添加附加内容（如订阅表单）。

```json
{
    "extra_fields": "额外配置字段（可选）",
    "php_variables": "额外 PHP 变量（可选）",
    "css_extra": "增强样式（必填！）",
    "html_extra_column": "额外链接列 HTML（可选）",
    "html_extra": "附加内容（可选 — 如订阅表单）",
    "footer_extra_text": "底部额外文字（可选）",
    "js_content": "交互逻辑（可选）"
}
```
GUIDE,
        ];
        
        $guide = $guides[$category] ?? $guides['content'];
        return $guide . "\n" . $commonRules;
    }
    
    /**
     * 检查框架是否存在
     * 
     * @param string $category 组件分类
     * @return bool
     */
    public function frameworkExists(string $category): bool
    {
        $category = strtolower($category);
        
        if (!isset(self::FRAMEWORK_FILES[$category])) {
            return false;
        }
        
        return file_exists(self::FRAMEWORK_DIR . self::FRAMEWORK_FILES[$category]);
    }
    
    /**
     * 获取所有可用的框架
     * 
     * @return array
     */
    public function getAvailableFrameworks(): array
    {
        $available = [];
        
        foreach (self::FRAMEWORK_FILES as $category => $file) {
            if (file_exists(self::FRAMEWORK_DIR . $file)) {
                $available[$category] = [
                    'file' => $file,
                    'path' => self::FRAMEWORK_DIR . $file,
                ];
            }
        }
        
        return $available;
    }
}
