<?php

declare(strict_types=1);

/*
 * AI 组件生成器
 * 
 * 核心服务类，负责：
 * 1. 接收用户描述并生成组件规格
 * 2. 调用模板构建器生成组件代码
 * 3. 验证生成的组件符合规约
 * 4. 保存到数据库并生成实体文件
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

use GuoLaiRen\PageBuilder\Model\Component;
use GuoLaiRen\PageBuilder\Service\ComponentValidator;
use Weline\Framework\Manager\ObjectManager;

class AIComponentGenerator
{
    private ComponentTemplateBuilder $templateBuilder;
    private EntityFileManager $entityFileManager;
    private ComponentValidator $componentValidator;
    
    // AI 版本号
    public const AI_VERSION = '1.0.0';
    
    public function __construct()
    {
        $this->templateBuilder = ObjectManager::getInstance(ComponentTemplateBuilder::class);
        $this->entityFileManager = ObjectManager::getInstance(EntityFileManager::class);
        $this->componentValidator = ObjectManager::getInstance(ComponentValidator::class);
    }
    
    /**
     * 生成 AI 组件（不保存）
     * 
     * @param string $description 用户描述
     * @param string $category 组件分类
     * @param array $options 额外选项
     * @return AIComponentResult 生成结果
     */
    public function generate(string $description, string $category = 'content', array $options = []): AIComponentResult
    {
        try {
            // 解析用户描述
            $spec = $this->parseDescription($description, $category, $options);
            
            // 生成组件代码
            $code = $options['code'] ?? Component::generateAIComponentCode($category, $spec['name'] ?? null);
            
            // 使用模板构建器生成模板内容
            $templateContent = $this->templateBuilder->createFromAIOutput(
                $spec['name'],
                $category,
                $spec['description'],
                $spec['fields'],
                $spec['html'],
                $spec['css']
            );
            
            // 创建结果对象
            $result = new AIComponentResult();
            $result->setSuccess(true);
            $result->setCode($code);
            $result->setName($spec['name']);
            $result->setDescription($spec['description']);
            $result->setCategory($category);
            $result->setTemplateContent($templateContent);
            $result->setFields($spec['fields']);
            $result->setPrompt($description);
            
            return $result;
            
        } catch (\Exception $e) {
            $result = new AIComponentResult();
            $result->setSuccess(false);
            $result->setError($e->getMessage());
            return $result;
        }
    }
    
    /**
     * 从已有规格生成组件（用于 API 接收完整规格）
     * 
     * @param array $spec 完整的组件规格
     * @return AIComponentResult
     */
    public function generateFromSpec(array $spec): AIComponentResult
    {
        try {
            $category = $spec['category'] ?? 'content';
            $code = $spec['code'] ?? Component::generateAIComponentCode($category, $spec['name'] ?? null);
            
            $templateContent = $this->templateBuilder->createFromAIOutput(
                $spec['name'] ?? 'AI 组件',
                $category,
                $spec['description'] ?? '',
                $spec['fields'] ?? [],
                $spec['html'] ?? '',
                $spec['css'] ?? ''
            );
            
            $result = new AIComponentResult();
            $result->setSuccess(true);
            $result->setCode($code);
            $result->setName($spec['name'] ?? 'AI 组件');
            $result->setDescription($spec['description'] ?? '');
            $result->setCategory($category);
            $result->setTemplateContent($templateContent);
            $result->setFields($spec['fields'] ?? []);
            $result->setPrompt($spec['prompt'] ?? '');
            
            return $result;
            
        } catch (\Exception $e) {
            $result = new AIComponentResult();
            $result->setSuccess(false);
            $result->setError($e->getMessage());
            return $result;
        }
    }
    
    /**
     * 保存 AI 组件到数据库
     * 
     * @param AIComponentResult $result 生成结果
     * @param bool $generateEntityFile 是否立即生成实体文件
     * @return Component 保存的组件模型
     * @throws \Exception 如果保存失败
     */
    public function save(AIComponentResult $result, bool $generateEntityFile = true): Component
    {
        if (!$result->isSuccess()) {
            throw new \Exception('无法保存失败的生成结果: ' . $result->getError());
        }
        
        $componentModel = ObjectManager::getInstance(Component::class);
        
        // 检查是否已存在同名组件
        $existing = clone $componentModel;
        $existing->clear()
            ->where(Component::schema_fields_CODE, $result->getCode())
            ->where(Component::schema_fields_STYLE_CODE, Component::STYLE_CODE_AI_GENERATED)
            ->find()
            ->fetch();
        
        if ($existing->getId()) {
            // 更新已存在的组件
            $component = $existing;
        } else {
            // 创建新组件
            $component = clone $componentModel;
            $component->clearData();
        }
        
        // 设置组件数据
        $component->setData(Component::schema_fields_CODE, $result->getCode());
        $component->setData(Component::schema_fields_NAME, $result->getName());
        $component->setData(Component::schema_fields_DESCRIPTION, $result->getDescription());
        $component->setData(Component::schema_fields_STYLE_CODE, Component::STYLE_CODE_AI_GENERATED);
        $component->setData(Component::schema_fields_CATEGORY, $result->getCategory());
        $component->setData(Component::schema_fields_TYPE, Component::TYPE_SECTION);
        $component->setData(Component::schema_fields_COMPATIBLE_STYLES, json_encode(['*']));
        $component->setData(Component::schema_fields_IS_ACTIVE, 1);
        $component->setData(Component::schema_fields_IS_SYSTEM, 0);
        $component->setData(Component::schema_fields_SORT_ORDER, 100);
        
        // 设置组件路径（必填字段）
        $category = $result->getCategory() ?: 'content';
        $componentPath = 'style/_ai_generated/components/' . $category . '/' . $result->getCode() . '.phtml';
        $component->setData(Component::schema_fields_PATH, $componentPath);
        
        // 设置 AI 相关字段
        $component->setAIGenerated(true);
        $component->setAIPrompt($result->getPrompt());
        $component->setData(Component::schema_fields_AI_VERSION, self::AI_VERSION);
        $component->setTemplateContent($result->getTemplateContent());
        
        // 设置配置 schema
        $configSchema = [
            'fields' => $result->getFields(),
            'region' => $result->getCategory(),
            'icon' => 'bi-robot',
            'ai_generated' => true,
        ];
        $component->setData(Component::schema_fields_CONFIG_SCHEMA, json_encode($configSchema, JSON_UNESCAPED_UNICODE));
        
        // 保存到数据库
        if ($existing->getId()) {
            $component->save();
        } else {
            $component->save(true);
        }
        
        // 生成实体文件
        if ($generateEntityFile) {
            $this->entityFileManager->syncEntityFile($component);
            $this->entityFileManager->updateComponentJson();
        }
        
        return $component;
    }
    
    /**
     * 预览组件（生成但不保存）
     * 
     * @param string $description 用户描述
     * @param string $category 组件分类
     * @param array $options 额外选项
     * @return array ['success' => bool, 'html' => string, 'error' => string]
     */
    public function preview(string $description, string $category = 'content', array $options = []): array
    {
        $result = $this->generate($description, $category, $options);
        
        if (!$result->isSuccess()) {
            return [
                'success' => false,
                'html' => '',
                'error' => $result->getError(),
            ];
        }
        
        return $this->previewTemplateContent($result->getTemplateContent());
    }
    
    /**
     * 预览模板内容
     * 
     * @param string $templateContent 模板内容
     * @return array ['success' => bool, 'html' => string, 'error' => string]
     */
    /**
     * 仅对模板内容做修复（笔误、continue/break、unexpected token if），不渲染。
     * 用于「先写入 phtml 文件，再调用 ob 服务渲染」流程。
     *
     * @param string $templateContent 原始 phtml 源码
     * @return string 修复后的源码
     */
    public function prepareTemplateForPreview(string $templateContent): string
    {
        $templateContent = $this->fixCommonAiTyposInTemplate($templateContent);
        $templateContent = $this->stripInvalidContinueBreakInTemplate($templateContent);
        $templateContent = $this->fixUnexpectedTokenIf($templateContent);
        $templateContent = $this->fixOrphanedLoopVariables($templateContent);
        $templateContent = $this->fixNullParameterCalls($templateContent);
        return $templateContent;
    }
    
    /**
     * 修复孤立的循环变量（有 $line = trim($line) 但没有 foreach 的情况）
     * AI 有时生成不完整的循环代码，导致变量未定义
     */
    private function fixOrphanedLoopVariables(string $code): string
    {
        // 检测模式：$lines = explode/preg_split 后紧跟 $line = trim($line) 但没有 foreach
        // 这种代码片段通常是不完整的循环，需要注释掉
        $pattern = '/(\$lines\s*=\s*(?:explode|preg_split)\s*\([^;]+;\s*\n)(\s*\$line\s*=\s*trim\s*\(\s*\$line\s*(?:\?\?\s*[\'"][^\'"]?[\'"]\s*)?\)\s*;)/s';
        $code = preg_replace(
            $pattern,
            '$1/* 以下代码缺少 foreach 循环，已自动注释 */ // $2',
            $code
        );
        
        // 移除孤立的循环体代码行（在 $lines = ... 之后、没有 foreach 包裹的行）
        // 这些通常是：$line = trim($line); if (empty($line)) continue; $parts = explode(...) 等
        $lines = explode("\n", $code);
        $inOrphanedBlock = false;
        $result = [];
        
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            
            // 检测孤立块的开始：$lines = explode/preg_split
            if (preg_match('/\$lines\s*=\s*(?:explode|preg_split)\s*\(/', $trimmed)) {
                // 检查后续几行是否有 foreach
                $hasForEach = false;
                for ($j = $i + 1; $j < min($i + 5, count($lines)); $j++) {
                    if (preg_match('/foreach\s*\(\s*\$lines\s+as/', trim($lines[$j] ?? ''))) {
                        $hasForEach = true;
                        break;
                    }
                }
                $inOrphanedBlock = !$hasForEach;
            }
            
            // 检测孤立块的结束：遇到非循环相关的代码
            if ($inOrphanedBlock) {
                // 判断是否是孤立循环体的代码
                if (preg_match('/^\s*\$line\s*=/', $trimmed) ||
                    preg_match('/^\s*if\s*\(\s*empty\s*\(\s*\$line/', $trimmed) ||
                    preg_match('/^\s*\/\*\s*continue/', $trimmed) ||
                    preg_match('/^\s*\$parts\s*=\s*explode/', $trimmed) ||
                    preg_match('/^\s*\$text\s*=\s*trim\s*\(\s*\$parts/', $trimmed) ||
                    preg_match('/^\s*\$href\s*=\s*trim\s*\(\s*\$parts/', $trimmed) ||
                    preg_match('/^\s*\$\w+Items\s*\[\]\s*=/', $trimmed)) {
                    // 注释掉这行
                    $result[] = preg_replace('/^(\s*)/', '$1// [orphaned] ', $line);
                    continue;
                } else {
                    // 遇到其他代码，结束孤立块
                    $inOrphanedBlock = false;
                }
            }
            
            $result[] = $line;
        }
        
        return implode("\n", $result);
    }
    
    /**
     * 修复 null 参数调用（PHP 8.4 严格类型）
     * 将 trim($var) 改为 trim($var ?? '')
     */
    private function fixNullParameterCalls(string $code): string
    {
        // 修复常见的字符串函数调用
        $functions = ['trim', 'strtolower', 'strtoupper', 'strlen', 'htmlspecialchars'];
        
        foreach ($functions as $func) {
            // 匹配 func($var) 但不匹配已有 ?? 的情况
            $code = preg_replace(
                '/\b' . $func . '\s*\(\s*(\$\w+)\s*\)(?!\s*\?\?)/',
                $func . '($1 ?? \'\')',
                $code
            );
        }
        
        return $code;
    }
    
    public function previewTemplateContent(string $templateContent): array
    {
        // 修复 AI 常见笔误（如 <4> 应为 <h4>、) 后缺分号导致 unexpected token if）
        $templateContent = $this->fixCommonAiTyposInTemplate($templateContent);
        // 移除「仅含 continue/break」的 PHP 块，避免 Fatal: 'continue' not in the 'loop' or 'switch' context（预览用模板可能未经过 FrameworkBuilder 清洗）
        $templateContent = $this->stripInvalidContinueBreakInTemplate($templateContent);
        // 先进行基本的语法检查
        $syntaxCheck = $this->checkPHPSyntax($templateContent);
        // 若仍报 continue/break 不在 loop 内，按错误行号替换该行为注释后重试一次
        if (!$syntaxCheck['valid'] && preg_match("/'(?:continue|break)'\s+not\s+in\s+the\s+'loop'/i", $syntaxCheck['error'])) {
            $lineNum = null;
            if (preg_match('/on\s+line\s+(\d+)/i', $syntaxCheck['error'], $m)) {
                $lineNum = (int) $m[1];
            } elseif (preg_match('/\s+(\d+)\s*$/i', $syntaxCheck['error'], $m)) {
                $lineNum = (int) $m[1];
            }
            if ($lineNum !== null && $lineNum >= 1) {
                $templateContent = $this->replaceContinueBreakLineByNumber($templateContent, $lineNum);
                $syntaxCheck = $this->checkPHPSyntax($templateContent);
            }
        }
        // 若报 unexpected token "if"，多为 ) 后缺分号，多次尝试修复后重试
        if (!$syntaxCheck['valid'] && preg_match('/unexpected\s+token\s+[\'"]?if[\'"]?/i', $syntaxCheck['error'])) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $fixed = $this->fixUnexpectedTokenIf($templateContent);
                if ($fixed === $templateContent) {
                    break;
                }
                $templateContent = $fixed;
                $syntaxCheck = $this->checkPHPSyntax($templateContent);
                if ($syntaxCheck['valid']) {
                    break;
                }
            }
            if (!$syntaxCheck['valid']) {
                $lineNum = null;
                if (preg_match('/on\s+line\s+(\d+)/i', $syntaxCheck['error'], $m)) {
                    $lineNum = (int) $m[1];
                }
                for ($tryLine = 0; $tryLine < 3 && !$syntaxCheck['valid'] && $lineNum !== null && $lineNum > 1; $tryLine++) {
                    $templateContent = $this->ensureSemicolonBeforeLine($templateContent, $lineNum);
                    $syntaxCheck = $this->checkPHPSyntax($templateContent);
                    if (!$syntaxCheck['valid'] && preg_match('/on\s+line\s+(\d+)/i', $syntaxCheck['error'], $m2)) {
                        $lineNum = (int) $m2[1];
                    } else {
                        break;
                    }
                }
            }
        }
        if (!$syntaxCheck['valid']) {
            return [
                'success' => false,
                'html' => $this->generatePreviewPlaceholder($templateContent, $syntaxCheck['error']),
                'error' => '代码语法错误: ' . $syntaxCheck['error'],
            ];
        }
        
        // 渲染预览（使用清洗后的内容）
        try {
            // 创建模拟渲染器
            $renderer = new PreviewRenderer();
            return $renderer->render($templateContent);
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'html' => $this->generatePreviewPlaceholder($templateContent, $e->getMessage()),
                'error' => '预览渲染失败: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * 检查PHP语法
     * 
     * @param string $code PHP代码
     * @return array ['valid' => bool, 'error' => string]
     */
    private function checkPHPSyntax(string $code): array
    {
        // 创建临时文件
        $tempFile = sys_get_temp_dir() . '/pb_syntax_' . uniqid() . '.php';
        file_put_contents($tempFile, $code);
        
        // 使用 php -l 进行语法检查
        $output = [];
        $returnCode = 0;
        exec('php -l ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnCode);
        
        // 清理临时文件
        @unlink($tempFile);
        
        if ($returnCode !== 0) {
            $errorOutput = implode("\n", $output);
            // 保留完整错误信息（含 " in file on line N"），便于按行号修复
            return ['valid' => false, 'error' => trim($errorOutput)];
        }
        
        return ['valid' => true, 'error' => ''];
    }
    
    /**
     * 修复 AI 生成模板中的常见笔误，避免预览语法错误与显示错误
     * - <4> 修正为 <h4>（AI 常把 h4 误写成 4）
     * - 行末 ) 后紧跟换行再 if ( 时在 ) 后补分号，避免 unexpected token "if"
     *
     * @param string $code 完整 phtml 源码
     * @return string 修复后的源码
     */
    private function fixCommonAiTyposInTemplate(string $code): string
    {
        // CSS 中 AI 常把 #< ?= $componentId ? > 错写成 #= $componentId（缺 PHP 标签），导致选择器无效
        $code = preg_replace('/#=\s*\$componentId\b/', '#<' . '?= $componentId ?' . '>', $code);
        
        // @fields_start ... @fields_end 内双星号 "* * " 修正为 "* "
        $code = preg_replace_callback(
            '/@fields_start(.*?)@fields_end/s',
            function ($m) {
                return '@fields_start' . preg_replace('/\*\s*\*\s+/', '* ', $m[1]) . '@fields_end';
            },
            $code
        );
        // JS 中 alert( 替换为兼容 FrontendToast 的调用（先占位再替换，避免对插入内容中的 alert 二次替换）
        $placeholder = '__PB_ALERT_' . bin2hex(random_bytes(4)) . '__';
        $code = preg_replace('/\balert\s*\(\s*/', $placeholder, $code);
        $code = str_replace($placeholder, '(typeof FrontendToast !== \'undefined\' && FrontendToast.warning ? FrontendToast.warning : alert)(', $code);
        // <4> 且后面是 PHP 短标签或 </h4> 的，视为 <h4> 笔误（AI 常把 h4 写成 4）
        $code = preg_replace('/<4>(?=\s*<\?=|\s*<\/h4>)/i', '<h4>', $code);
        // 跨行：上一行未以 ; } { : , 结尾且下一行以 if ( 开头 → 在上一行末补 ;（覆盖 ) 或 >5 等漏写分号导致 unexpected token "if"）
        $lines = explode("\n", $code);
        $i = 0;
        while ($i < count($lines) - 1) {
            $trimmed = rtrim($lines[$i]);
            $next = isset($lines[$i + 1]) ? $lines[$i + 1] : '';
            $nextTrim = trim($next);
            $nextStartsWithIf = $nextTrim !== '' && (preg_match('/^\s*if\s*\(/i', $nextTrim) || preg_match('/^\s*<\?php\s+if\s*\(/i', $nextTrim));
            // 已以 ; } { : , 结尾的无需补；以 ] 或数字等结尾且下一行 if 时也补（如 $_hasValidJs = ... > 5 漏写分号）
            $lineEndsStatement = preg_match('/[;{}:,]\s*$/', $trimmed);
            if ($trimmed !== '' && $nextTrim !== ''
                && !$lineEndsStatement
                && $nextStartsWithIf) {
                $lines[$i] = rtrim($lines[$i]) . ';';
            }
            $i++;
        }
        return implode("\n", $lines);
    }
    
    private function stripInvalidContinueBreakInTemplate(string $code): string
    {
        // 1. 匹配 PHP 开标签后仅含空白 + continue/break（可选层级）+ ; + 空白 + PHP 闭标签的整块，支持多行
        $code = preg_replace(
            '/<\?(?:php\s+)?\s*(?:continue|break)(?:\s+\d+)?\s*;\s*\?' . '>/i',
            '<' . '?php /* continue/break removed */ ?' . '>',
            $code
        );
        
        // 2. 处理 PHP 块内部的 if (...) continue/break; 语句（可能不在循环中）
        //    支持嵌套一层括号，如 if (empty($line)) continue;
        $code = preg_replace(
            '/(\bif\s*\([^)]*(?:\([^)]*\)[^)]*)*\)\s*)(continue|break)(\s*(?:\d+\s*)?;)/i',
            '$1/* $2 removed */$3',
            $code
        );
        
        // 3. 移除独立的 continue/break 行（不在 if 内的）
        $code = preg_replace(
            '/^(\s*)(continue|break)(\s*(?:\d+\s*)?;\s*)$/mi',
            '$1/* $2 removed */$3',
            $code
        );
        
        return $code;
    }
    
    /**
     * 按行号替换仅含 continue/break 的那一行为注释（用于语法报错时的兜底修复）
     *
     * @param string $code 完整源码
     * @param int $lineNum 行号（从 1 计）
     * @return string 替换后的源码
     */
    private function replaceContinueBreakLineByNumber(string $code, int $lineNum): string
    {
        $lines = explode("\n", $code);
        $idx = $lineNum - 1;
        if ($idx < 0 || $idx >= count($lines)) {
            return $code;
        }
        $trimmed = trim($lines[$idx]);
        if ($trimmed === '' || $trimmed === ';') {
            return $code;
        }
        if (preg_match('/^\s*(?:continue|break)(?:\s+\d+)?\s*;\s*$/i', $trimmed)) {
            $lines[$idx] = preg_replace('/^(\s*)(.*)$/', '$1/* continue/break removed */', $lines[$idx]);
            return implode("\n", $lines);
        }
        return $code;
    }
    
    /**
     * 尝试修复「unexpected token "if"」：) 后紧跟 if 时在 ) 后补分号
     *
     * @param string $code 完整源码
     * @return string 修复后的源码（无改动则返回原串）
     */
    private function fixUnexpectedTokenIf(string $code): string
    {
        // ) 后紧跟 if (：在 ) 后补分号
        $code = (string) preg_replace('/\)(\s*)if\s*\(/i', ');$1if (', $code);
        // 数字后紧跟 if (：如 ... > 5 if ($_hasValidJs) 漏写分号，在数字后补 ;
        $code = (string) preg_replace('/(\d)(\s*)if\s*\(/i', '$1;$2if (', $code);
        return $code;
    }
    
    /**
     * 在指定行（通常为报错行）的上一行末尾补分号（若该行未以 ; } { 结尾），用于修复 unexpected token
     *
     * @param string $code 完整源码
     * @param int $lineNum 报错行号（从 1 计），会在 lineNum-1 行末补 ;
     * @return string 修复后的源码
     */
    private function ensureSemicolonBeforeLine(string $code, int $lineNum): string
    {
        $lines = explode("\n", $code);
        $idx = $lineNum - 2; // 上一行
        if ($idx < 0 || $idx >= count($lines)) {
            return $code;
        }
        $line = $lines[$idx];
        $trimmed = rtrim($line);
        if ($trimmed === '') {
            return $code;
        }
        $last = substr($trimmed, -1);
        if ($last === ';' || $last === '}' || $last === '{' || $last === ':' || $last === ',') {
            return $code;
        }
        // 上一行以字母/数字/) 等结尾，可能缺分号
        $lines[$idx] = rtrim($line) . ';';
        return implode("\n", $lines);
    }
    
    /**
     * 生成预览占位符HTML（当渲染失败时使用）
     * 
     * @param string $code 组件代码
     * @param string $error 错误信息
     * @return string
     */
    private function generatePreviewPlaceholder(string $code, string $error = ''): string
    {
        $errorHtml = $error ? '<p style="color:#dc3545;margin-top:10px;font-size:12px;">' . htmlspecialchars($error) . '</p>' : '';
        
        // 尝试提取组件名称（默认根据当前语言生成预置文本）
        $name = __('Component');
        if (preg_match('/name:\s*([^\n]+)/', $code, $matches)) {
            $name = trim($matches[1]);
        }
        
        $msgFailed = __('Component code generated, but preview failed to render');
        $msgHint = __('You can continue to refine or save the component directly');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .preview-placeholder {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .preview-placeholder h3 { margin: 0 0 10px; font-size: 24px; }
        .preview-placeholder p { margin: 5px 0; opacity: 0.9; }
        .preview-placeholder .icon { font-size: 48px; margin-bottom: 20px; }
        .error-box { background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; margin-top: 15px; max-width: 400px; }
    </style>
</head>
<body>
    <div class="preview-placeholder">
        <div class="icon">🎨</div>
        <h3>{$name}</h3>
        <p>{$msgFailed}</p>
        <p style="font-size: 12px; opacity: 0.7;">{$msgHint}</p>
        {$errorHtml}
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * 验证组件代码是否符合规约
     * 
     * @param string $templateContent 模板内容
     * @return ValidationResult
     */
    public function validate(string $templateContent): ValidationResult
    {
        $result = new ValidationResult();
        $errors = [];
        $warnings = [];
        
        // 检查必需的元数据块
        if (!preg_match('/@component_start.*@component_end/s', $templateContent)) {
            $errors[] = '缺少 @component_start / @component_end 元数据块';
        }
        
        // 检查必需的字段定义块
        if (!preg_match('/@fields_start.*@fields_end/s', $templateContent)) {
            $warnings[] = '缺少 @fields_start / @fields_end 字段定义块';
        }
        
        // 检查必需的代码结构
        if (strpos($templateContent, '$componentId') === false) {
            $warnings[] = '建议使用 $componentId 作为组件唯一标识';
        }
        
        // 检查 HTML 结构
        if (strpos($templateContent, '<section') === false && strpos($templateContent, '<div') === false) {
            $warnings[] = '组件应该有一个根 HTML 元素';
        }
        
        // 检查安全性
        if (preg_match('/eval\s*\(|exec\s*\(|system\s*\(|shell_exec\s*\(/i', $templateContent)) {
            $errors[] = '禁止使用危险函数';
        }
        
        $result->setValid(empty($errors));
        $result->setErrors($errors);
        $result->setWarnings($warnings);
        
        return $result;
    }
    
    /**
     * 更新已有的 AI 组件
     * 
     * @param int $componentId 组件 ID
     * @param array $updates 更新内容
     * @return Component
     */
    public function update(int $componentId, array $updates): Component
    {
        $componentModel = ObjectManager::getInstance(Component::class);
        $component = clone $componentModel;
        $component->load($componentId);
        
        if (!$component->getId()) {
            throw new \Exception('组件不存在: ' . $componentId);
        }
        
        if (!$component->isAIGenerated()) {
            throw new \Exception('只能更新 AI 生成的组件');
        }
        
        // 更新允许的字段
        $allowedFields = [
            'name' => Component::schema_fields_NAME,
            'description' => Component::schema_fields_DESCRIPTION,
            'template_content' => Component::schema_fields_TEMPLATE_CONTENT,
            'is_active' => Component::schema_fields_IS_ACTIVE,
            'sort_order' => Component::schema_fields_SORT_ORDER,
        ];
        
        foreach ($updates as $key => $value) {
            if (isset($allowedFields[$key])) {
                $component->setData($allowedFields[$key], $value);
            }
        }
        
        // 如果模板内容更新了，需要重新生成实体文件
        if (isset($updates['template_content'])) {
            $component->setData(Component::schema_fields_AI_VERSION, self::AI_VERSION);
        }
        
        $component->save();
        
        // 同步实体文件
        if (isset($updates['template_content'])) {
            $this->entityFileManager->syncEntityFile($component);
            $this->entityFileManager->updateComponentJson();
        }
        
        return $component;
    }
    
    /**
     * 获取组件被引用的页面列表
     * 
     * 检查组件在 PageLayout 中的引用情况：
     * - header_component / footer_component 直接引用
     * - content_components JSON 数组中的引用
     * 
     * @param int $componentId 组件 ID
     * @return array 引用信息 ['has_references' => bool, 'references' => [...]]
     */
    public function getComponentReferences(int $componentId): array
    {
        $componentModel = ObjectManager::getInstance(Component::class);
        $component = clone $componentModel;
        $component->load($componentId);
        
        if (!$component->getId()) {
            return ['has_references' => false, 'references' => []];
        }
        
        $componentCode = $component->getData(Component::schema_fields_CODE);
        $references = [];
        
        $pageLayoutModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\PageLayout::class);
        $pageModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\Page::class);
        
        $layouts = clone $pageLayoutModel;
        $layouts->clear()
            ->where(\GuoLaiRen\PageBuilder\Model\PageLayout::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();
        
        foreach ($layouts->getItems() as $layout) {
            $pageId = (int)$layout->getData(\GuoLaiRen\PageBuilder\Model\PageLayout::schema_fields_PAGE_ID);
            $usedIn = [];
            
            $headerComponent = $layout->getData(\GuoLaiRen\PageBuilder\Model\PageLayout::schema_fields_HEADER_COMPONENT);
            if ($headerComponent === $componentCode) {
                $usedIn[] = 'header';
            }
            
            $footerComponent = $layout->getData(\GuoLaiRen\PageBuilder\Model\PageLayout::schema_fields_FOOTER_COMPONENT);
            if ($footerComponent === $componentCode) {
                $usedIn[] = 'footer';
            }
            
            $contentComponents = $layout->getContentComponents();
            foreach ($contentComponents as $contentComp) {
                $code = $contentComp['component'] ?? $contentComp['code'] ?? '';
                if ($code === $componentCode) {
                    $usedIn[] = 'content';
                    break;
                }
            }
            
            if (!empty($usedIn)) {
                $page = clone $pageModel;
                $page->load($pageId);
                
                if ($page->getId()) {
                    $references[] = [
                        'page_id' => $pageId,
                        'page_name' => $page->getData(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_NAME) ?: $page->getData(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_TITLE),
                        'page_handle' => $page->getData(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_HANDLE),
                        'used_in' => $usedIn,
                    ];
                }
            }
        }
        
        return [
            'has_references' => !empty($references),
            'references' => $references,
            'component_code' => $componentCode,
            'component_name' => $component->getData(Component::schema_fields_NAME),
        ];
    }
    
    /**
     * 删除 AI 组件
     * 
     * @param int $componentId 组件 ID
     * @param bool $force 强制删除（忽略引用检查）
     * @return array 删除结果 ['success' => bool, 'message' => string, 'references' => array]
     * @throws \Exception
     */
    public function delete(int $componentId, bool $force = false): array
    {
        $componentModel = ObjectManager::getInstance(Component::class);
        $component = clone $componentModel;
        $component->load($componentId);
        
        if (!$component->getId()) {
            return [
                'success' => false,
                'message' => __('组件不存在'),
                'references' => [],
            ];
        }
        
        if (!$component->isAIGenerated()) {
            throw new \Exception(__('只能删除 AI 生成的组件'));
        }
        
        if (!$force) {
            $refResult = $this->getComponentReferences($componentId);
            if ($refResult['has_references']) {
                $pageNames = array_map(fn($r) => $r['page_name'], $refResult['references']);
                return [
                    'success' => false,
                    'message' => __('无法删除：组件「%{1}」正在被 %{2} 个页面使用，请先从这些页面中移除该组件后再删除。', [
                        $refResult['component_name'],
                        count($refResult['references']),
                    ]),
                    'references' => $refResult['references'],
                    'component_code' => $refResult['component_code'],
                    'component_name' => $refResult['component_name'],
                ];
            }
        }
        
        $this->entityFileManager->deleteEntityFile($component);
        
        $component->delete();
        
        $this->entityFileManager->updateComponentJson();
        
        return [
            'success' => true,
            'message' => __('AI 组件已删除'),
            'references' => [],
        ];
    }
    
    /**
     * 微调 AI 组件
     * 
     * 基于现有组件代码进行微调，将现有代码和调整提示词一起发送给AI
     * 
     * @param string $existingCode 现有组件代码
     * @param string $adjustmentPrompt 调整提示词（如：颜色改为蓝色、字体加大等）
     * @param string $category 组件分类
     * @param array $options 额外选项
     * @return AIComponentResult 生成结果
     */
    public function refine(string $existingCode, string $adjustmentPrompt, string $category = 'content', array $options = []): AIComponentResult
    {
        try {
            // 构建微调提示词（包含错误信息如果有）
            $lastError = $options['last_error'] ?? '';
            $refinePrompt = $this->buildRefinePrompt($existingCode, $adjustmentPrompt, $category, $lastError);
            
            // 调用智能体进行微调（支持工具调用与结构化规划）
            $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
            $streamCallback = $options['stream_callback'] ?? null;
            $agentResult = $aiService->executeAgent(
                'pagebuilder_component_refine',
                $refinePrompt,
                null,
                [
                    'category' => $category,
                    'style_code' => $options['style_code'] ?? '',
                    'language' => $options['language'] ?? '',
                    'refine_mode' => true,
                    'existing_code' => $existingCode,
                    'allow_zero_balance_provider' => true,
                ],
                is_callable($streamCallback) ? $streamCallback : null
            );
            
            if (!$agentResult->success) {
                throw new \Exception($agentResult->error ?? 'AI 微调失败');
            }
            
            // 解析AI响应，提取组件代码
            $refinedCode = $this->parseComponentResponse($agentResult->content);
            
            // 从现有代码中提取元数据（如果AI没有生成完整的）
            $metadata = $this->extractMetadata($existingCode);
            
            // 创建结果对象
            $result = new AIComponentResult();
            $result->setSuccess(true);
            $result->setCode($options['code'] ?? $metadata['code'] ?? Component::generateAIComponentCode($category));
            $result->setName($metadata['name'] ?? '微调后的组件');
            $result->setDescription($metadata['description'] ?? $adjustmentPrompt);
            $result->setCategory($category);
            $result->setTemplateContent($refinedCode);
            $result->setFields($metadata['fields'] ?? []);
            $result->setPrompt($adjustmentPrompt);
            $result->setAgentInfo($agentResult->toArray());
            
            return $result;
            
        } catch (\Exception $e) {
            $result = new AIComponentResult();
            $result->setSuccess(false);
            $result->setError($e->getMessage());
            return $result;
        }
    }
    
    /**
     * 构建微调提示词
     * 
     * @param string $existingCode 现有组件代码
     * @param string $adjustmentPrompt 调整提示词
     * @param string $category 组件分类
     * @param string $lastError 最后一次渲染错误（可选）
     * @return string 完整的微调提示词
     */
    private function buildRefinePrompt(string $existingCode, string $adjustmentPrompt, string $category, string $lastError = ''): string
    {
        $prompt = "请基于以下现有的PageBuilder组件代码进行微调：\n\n";
        
        // 如果有渲染错误，优先显示
        if (!empty($lastError)) {
            $prompt .= "【重要：修复渲染错误】\n";
            $prompt .= "上次渲染时出现以下错误，请务必修复：\n";
            $prompt .= "```\n{$lastError}\n```\n\n";
            $prompt .= "请仔细分析错误原因，常见问题包括：\n";
            $prompt .= "- PHP语法错误（未闭合的括号、引号、分号缺失等）\n";
            $prompt .= "- 在JS代码中使用了PHP标签\n";
            $prompt .= "- 双美元符号变量（如 \$\$var 应为 \$var）\n";
            $prompt .= "- 未定义的变量或函数\n";
            $prompt .= "- 不平衡的控制结构（if/endif, foreach/endforeach）\n\n";
            $prompt .= "修复流程：\n";
            $prompt .= "1) 先调用 locate_template_error 提取错误文件与行号\n";
            $prompt .= "2) 根据上下文定位需要修改的片段\n";
            $prompt .= "3) 使用 replace_template_snippet 精准替换对应行范围\n\n";
        }
        
        $prompt .= "【现有组件代码】\n";
        $prompt .= "```php\n{$existingCode}\n```\n\n";
        $prompt .= "【调整要求】\n";
        $prompt .= "{$adjustmentPrompt}\n\n";
        $prompt .= "【重要要求】\n";
        $prompt .= "1. 必须保持原有的组件结构、元数据块和字段定义\n";
        $prompt .= "2. 只调整需要修改的部分，不要改变整体结构\n";
        $prompt .= "3. 必须保持 @component_start / @component_end 元数据块不变\n";
        $prompt .= "4. 必须保持 @fields_start / @fields_end 字段定义块不变（除非明确要求修改）\n";
        $prompt .= "5. 只返回修改后的完整组件代码，不要包含其他说明\n";
        $prompt .= "6. 确保代码可以直接使用，符合PageBuilder组件规约\n";
        $prompt .= "7. php_variables 只能包含简单的变量赋值（如 \$var = \$getConfig('key', 'default');），绝对禁止 if/foreach/while/for/continue/break 等控制结构\n";
        $prompt .= "8. js_content 必须是纯JavaScript，不能包含任何PHP代码\n";
        $prompt .= "9. 如果是错误修复，必须使用工具做精准替换，避免重写整份代码\n";
        $prompt .= "\n【PHP 8.4 严格类型约束】\n";
        $prompt .= "- 所有字符串函数参数必须确保非 null：使用 trim(\$var ?? '') 而非 trim(\$var)\n";
        $prompt .= "- 数组访问使用 null 合并：\$arr['key'] ?? '' 或 \$arr['key'] ?? []\n";
        $prompt .= "- 循环前检查数组：foreach ((\$items ?? []) as \$item)\n";
        $prompt .= "- htmlspecialchars 参数不能为 null：htmlspecialchars(\$text ?? '')\n";
        
        return $prompt;
    }
    
    /**
     * 从组件代码中提取元数据
     * 
     * @param string $code 组件代码
     * @return array 元数据
     */
    private function extractMetadata(string $code): array
    {
        $metadata = [
            'code' => '',
            'name' => '',
            'description' => '',
            'fields' => [],
        ];
        
        // 提取 code
        if (preg_match('/code:\s*([^\n]+)/', $code, $matches)) {
            $metadata['code'] = trim($matches[1]);
        }
        
        // 提取 name
        if (preg_match('/name:\s*([^\n]+)/', $code, $matches)) {
            $metadata['name'] = trim($matches[1]);
        }
        
        // 提取 description
        if (preg_match('/description:\s*([^\n]+)/', $code, $matches)) {
            $metadata['description'] = trim($matches[1]);
        }
        
        // 提取字段定义（简化版）
        if (preg_match('/@fields_start(.*?)@fields_end/s', $code, $matches)) {
            // 这里可以进一步解析字段定义
            // 暂时返回空数组
        }
        
        return $metadata;
    }
    
    /**
     * 解析AI响应的组件代码
     * 
     * @param string $response AI响应
     * @return string 组件代码
     */
    private function parseComponentResponse(string $response): string
    {
        // 尝试提取PHP代码
        if (preg_match('/```(?:php|phtml)?\s*(.*?)\s*```/s', $response, $matches)) {
            return trim($matches[1]);
        }
        
        // 如果没有代码块，尝试查找 PHP 开标签开始的内容
        if (preg_match('/(<\?php.*)/s', $response, $matches)) {
            return trim($matches[1]);
        }
        
        // 如果都没有，返回原始响应
        return trim($response);
    }
    
    /**
     * 解析用户描述，生成组件规格
     * 
     * 这是一个简化的解析器，实际项目中可以接入真正的 AI 服务
     * 
     * @param string $description 用户描述
     * @param string $category 组件分类
     * @param array $options 额外选项
     * @return array 组件规格
     */
    private function parseDescription(string $description, string $category, array $options = []): array
    {
        // 如果选项中包含完整规格，直接使用
        if (!empty($options['spec'])) {
            return $options['spec'];
        }
        
        // 从选项中提取或生成默认值
        $name = $options['name'] ?? $this->extractName($description);
        $fields = $options['fields'] ?? $this->generateDefaultFields($description, $category);
        $html = $options['html'] ?? $this->generateDefaultHtml($description, $name, $fields);
        $css = $options['css'] ?? $this->generateDefaultCss($description);
        
        return [
            'name' => $name,
            'description' => $description,
            'fields' => $fields,
            'html' => $html,
            'css' => $css,
        ];
    }
    
    /**
     * 从描述中提取组件名称
     */
    private function extractName(string $description): string
    {
        // 简单的名称提取逻辑
        $words = preg_split('/[\s,，。.]+/', $description, 5);
        $name = implode(' ', array_slice($words, 0, 3));
        
        // 限制长度
        if (mb_strlen($name) > 30) {
            $name = mb_substr($name, 0, 30);
        }
        
        return $name ?: 'AI 组件 ' . date('YmdHi');
    }
    
    /**
     * 生成默认配置字段
     */
    private function generateDefaultFields(string $description, string $category): array
    {
        $fields = [
            ['group' => 'content', 'key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '组件标题'],
            ['group' => 'content', 'key' => 'description', 'label' => '描述', 'type' => 'textarea', 'default' => $description],
        ];
        
        // 根据描述关键词添加字段
        $descLower = strtolower($description);
        
        if (strpos($descLower, '按钮') !== false || strpos($descLower, 'button') !== false) {
            $fields[] = ['group' => 'button', 'key' => 'text', 'label' => '按钮文字', 'type' => 'text', 'default' => '点击这里'];
            $fields[] = ['group' => 'button', 'key' => 'url', 'label' => '按钮链接', 'type' => 'text', 'default' => '#'];
        }
        
        if (strpos($descLower, '图片') !== false || strpos($descLower, 'image') !== false) {
            $fields[] = ['group' => 'image', 'key' => 'src', 'label' => '图片地址', 'type' => 'image', 'default' => ''];
            $fields[] = ['group' => 'image', 'key' => 'alt', 'label' => '图片描述', 'type' => 'text', 'default' => ''];
        }
        
        // 添加样式字段
        $fields[] = ['group' => 'style', 'key' => 'bg_color', 'label' => '背景颜色', 'type' => 'color', 'default' => '#ffffff'];
        $fields[] = ['group' => 'style', 'key' => 'text_color', 'label' => '文字颜色', 'type' => 'color', 'default' => '#333333'];
        
        return $fields;
    }
    
    /**
     * 生成默认 HTML 结构
     */
    private function generateDefaultHtml(string $description, string $name, array $fields): string
    {
        $phpOpen = '<' . '?php';
        $phpShort = '<' . '?=';
        $phpClose = '?' . '>';
        $cls = "{$phpShort} \$componentId {$phpClose}";
        
        $html = "<div class=\"{$cls}-content\">\n";
        $html .= "    <h2 class=\"{$cls}-title\">{$phpShort} htmlspecialchars(\$title ?? '{$name}') {$phpClose}</h2>\n";
        $html .= "    <p class=\"{$cls}-desc\">{$phpShort} htmlspecialchars(\$description ?? '') {$phpClose}</p>\n";
        
        foreach ($fields as $field) {
            if ($field['group'] === 'button') {
                $html .= "    {$phpOpen} if (!empty(\$text)): {$phpClose}\n";
                $html .= "    <a href=\"{$phpShort} htmlspecialchars(\$url ?? '#') {$phpClose}\" class=\"{$cls}-btn\">{$phpShort} htmlspecialchars(\$text) {$phpClose}</a>\n";
                $html .= "    {$phpOpen} endif; {$phpClose}\n";
                break;
            }
            if ($field['group'] === 'image') {
                $html .= "    {$phpOpen} if (!empty(\$src)): {$phpClose}\n";
                $html .= "    <img src=\"{$phpShort} htmlspecialchars(\$src) {$phpClose}\" alt=\"{$phpShort} htmlspecialchars(\$alt ?? '') {$phpClose}\" class=\"{$cls}-img\">\n";
                $html .= "    {$phpOpen} endif; {$phpClose}\n";
                break;
            }
        }
        
        $html .= "</div>\n";
        
        return $html;
    }
    
    /**
     * 生成默认 CSS 样式
     */
    private function generateDefaultCss(string $description): string
    {
        $phpShort = '<' . '?=';
        $phpClose = '?' . '>';
        $id = "{$phpShort} \$componentId {$phpClose}";
        $cls = "{$phpShort} \$componentId {$phpClose}";
        $bgColor = "{$phpShort} htmlspecialchars(\$bgColor ?? '#ffffff') {$phpClose}";
        $textColor = "{$phpShort} htmlspecialchars(\$textColor ?? '#333333') {$phpClose}";
        
        return <<<CSS
#{$id} {
    background-color: {$bgColor};
    color: {$textColor};
    padding: 64px 24px;
    width: 100%;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

#{$id} .{$cls}-content {
    max-width: 1200px;
    margin: 0 auto;
    text-align: center;
}

#{$id} .{$cls}-title {
    font-size: 2.25rem;
    font-weight: 700;
    margin-bottom: 20px;
    letter-spacing: 0;
}

#{$id} .{$cls}-desc {
    font-size: 1.0625rem;
    line-height: 1.7;
    margin-bottom: 32px;
    opacity: 0.85;
    max-width: 640px;
    margin-left: auto;
    margin-right: auto;
}

#{$id} .{$cls}-btn {
    display: inline-block;
    padding: 12px 28px;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: #ffffff;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.25s ease;
}

#{$id} .{$cls}-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
}

#{$id} .{$cls}-img {
    max-width: 100%;
    height: auto;
    border-radius: 12px;
    margin: 24px 0;
}

@media (max-width: 767px) {
    #{$id} {
        padding: 48px 20px;
    }
    
    #{$id} .{$cls}-title {
        font-size: 1.5rem;
    }
}
CSS;
    }
}
