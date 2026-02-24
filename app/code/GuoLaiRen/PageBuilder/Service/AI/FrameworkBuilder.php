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
        // 首个 PHP 结束标签之前为初始 PHP 块（含变量定义与 try{ {{PHP_VARIABLES}} }）
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
        
        // 兜底：content 组件必须有基础 HTML（根据当前语言生成预置文本）
        if ($category === 'content' && (empty($aiData['html_content']) || !is_string($aiData['html_content']))) {
            $aiData['html_content'] = '<div class="ai-empty">' . __('AI content placeholder') . '</div>';
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
        // HTML 相关字段会作为模板执行；移除「仅含 continue/break」的 PHP 块（含 continue N;/break N;、多行），避免 Fatal: 'continue' not in the 'loop' or 'switch' context
        $htmlKeys = ['html_content', 'html_extra', 'html_extra_column', 'footer_extra_text'];
        foreach ($htmlKeys as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = preg_replace('/<\?(?:php\s+)?\s*(?:continue|break)(?:\s+\d+)?\s*;\s*\?>/i', '<?php /* continue/break removed */ ?>', $data[$key]);
            }
        }
        // 移除所有字段中的反引号
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = str_replace('`', "'", $value);
            }
        }
        
        // 清理 php_variables：仅允许简单赋值，禁止控制结构
        if (isset($data['php_variables']) && is_string($data['php_variables'])) {
            $pv = $data['php_variables'];
            if (strpos($pv, '{') !== false || strpos($pv, '}') !== false) {
                // 禁止大括号，只保留不含 { } 的行
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
            // 移除 continue/break：php_variables 注入在 try 块内无 loop/switch，会导致 Fatal error: 'continue' not in the 'loop' or 'switch' context
            $lines = explode("\n", $pv);
            $safe = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (preg_match('/^\s*continue\s*(;\s*|\s+\d+\s*;?\s*)$/i', $trimmed)
                    || preg_match('/^\s*break\s*(;\s*|\s+\d+\s*;?\s*)$/i', $trimmed)) {
                    continue;
                }
                $safe[] = $line;
            }
            $data['php_variables'] = implode("\n", $safe);
            $pv = $data['php_variables'];
            // 移除所有 PHP 开始/结束标签
            $pv = preg_replace('/<\?(?:php)?\s*/i', '', $pv);
            $pv = preg_replace('/\s*\?>\s*/', '', $pv);
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
        // 注入 try { {{PHP_VARIABLES}} } 时，末行若以 ) 或 ] 结尾且无分号，补分号，避免下一 token 为 } 或 if 时报 unexpected token
        if (!empty($pvSafe)) {
            $lastIdx = count($pvSafe) - 1;
            $last = rtrim($pvSafe[$lastIdx]);
            if ($last !== '' && !preg_match('/;\s*$/', $last)) {
                $pvSafe[$lastIdx] = rtrim($pvSafe[$lastIdx]) . ';';
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
            if ($hasBrace) {
                $errors[] = 'php_variables 只能为简单赋值，禁止包含大括号 { }';
            } else {
                $syntaxCheck = $this->checkPhpSyntax($pv);
                if (!$syntaxCheck['valid']) {
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
        // 创建临时文件进行语法检查
        $tempFile = sys_get_temp_dir() . '/ai_php_check_' . uniqid() . '.php';
        $phpOpen = chr(60) . chr(63) . 'php';
        $fullCode = $phpOpen . "\n" . $code;
        file_put_contents($tempFile, $fullCode);
        
        $output = [];
        $returnVar = 0;
        exec("php -l " . escapeshellarg($tempFile) . " 2>&1", $output, $returnVar);
        
        unlink($tempFile);

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
        $guideDir = __DIR__ . DIRECTORY_SEPARATOR . 'prompt_guides' . DIRECTORY_SEPARATOR;
        $commonRulesFile = $guideDir . 'common_rules.md';
        $commonRules = is_file($commonRulesFile) ? file_get_contents($commonRulesFile) : '';
        $guideFile = $guideDir . $category . '.md';
        if (!is_file($guideFile)) {
            $guideFile = $guideDir . 'content.md';
        }
        $guide = is_file($guideFile) ? file_get_contents($guideFile) : '';
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
