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
            ->where(Component::fields_CODE, $result->getCode())
            ->where(Component::fields_STYLE_CODE, Component::STYLE_CODE_AI_GENERATED)
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
        $component->setData(Component::fields_CODE, $result->getCode());
        $component->setData(Component::fields_NAME, $result->getName());
        $component->setData(Component::fields_DESCRIPTION, $result->getDescription());
        $component->setData(Component::fields_STYLE_CODE, Component::STYLE_CODE_AI_GENERATED);
        $component->setData(Component::fields_CATEGORY, $result->getCategory());
        $component->setData(Component::fields_TYPE, Component::TYPE_SECTION);
        $component->setData(Component::fields_COMPATIBLE_STYLES, json_encode(['*']));
        $component->setData(Component::fields_IS_ACTIVE, 1);
        $component->setData(Component::fields_IS_SYSTEM, 0);
        $component->setData(Component::fields_SORT_ORDER, 100);
        
        // 设置组件路径（必填字段）
        $category = $result->getCategory() ?: 'content';
        $componentPath = 'style/_ai_generated/components/' . $category . '/' . $result->getCode() . '.phtml';
        $component->setData(Component::fields_PATH, $componentPath);
        
        // 设置 AI 相关字段
        $component->setAIGenerated(true);
        $component->setAIPrompt($result->getPrompt());
        $component->setData(Component::fields_AI_VERSION, self::AI_VERSION);
        $component->setTemplateContent($result->getTemplateContent());
        
        // 设置配置 schema
        $configSchema = [
            'fields' => $result->getFields(),
            'region' => $result->getCategory(),
            'icon' => 'bi-robot',
            'ai_generated' => true,
        ];
        $component->setData(Component::fields_CONFIG_SCHEMA, json_encode($configSchema, JSON_UNESCAPED_UNICODE));
        
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
     * 从完整模板中移除「仅含 continue/break」的 PHP 块，避免 Fatal: 'continue' not in the 'loop' or 'switch' context。
     * 预览用模板可能来自 refine/直接代码，未经过 FrameworkBuilder::sanitizeAiData，故在此做防御性清洗。
     *
     * @param string $code 完整 phtml 源码
     * @return string 清洗后的源码
     */
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
        // <4> 且后面是 <?= 或 </h4> 的，视为 <h4> 笔误（AI 常把 h4 写成 4）
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
        // 匹配 <?php 或 <? 后仅含空白 + continue/break（可选层级）+ ; + 空白 + ?> 的整块，支持多行
        $code = preg_replace(
            '/<\?(?:php\s+)?\s*(?:continue|break)(?:\s+\d+)?\s*;\s*\?>/i',
            '<?php /* continue/break removed */ ?>',
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
            'name' => Component::fields_NAME,
            'description' => Component::fields_DESCRIPTION,
            'template_content' => Component::fields_TEMPLATE_CONTENT,
            'is_active' => Component::fields_IS_ACTIVE,
            'sort_order' => Component::fields_SORT_ORDER,
        ];
        
        foreach ($updates as $key => $value) {
            if (isset($allowedFields[$key])) {
                $component->setData($allowedFields[$key], $value);
            }
        }
        
        // 如果模板内容更新了，需要重新生成实体文件
        if (isset($updates['template_content'])) {
            $component->setData(Component::fields_AI_VERSION, self::AI_VERSION);
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
     * 删除 AI 组件
     * 
     * @param int $componentId 组件 ID
     * @return bool
     */
    public function delete(int $componentId): bool
    {
        $componentModel = ObjectManager::getInstance(Component::class);
        $component = clone $componentModel;
        $component->load($componentId);
        
        if (!$component->getId()) {
            return false;
        }
        
        if (!$component->isAIGenerated()) {
            throw new \Exception('只能删除 AI 生成的组件');
        }
        
        // 删除实体文件
        $this->entityFileManager->deleteEntityFile($component);
        
        // 删除数据库记录
        $component->delete();
        
        // 更新 component.json
        $this->entityFileManager->updateComponentJson();
        
        return true;
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
                    'refine_mode' => true,
                    'existing_code' => $existingCode,
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
        $prompt .= "7. php_variables 只能包含简单的变量赋值，不要包含控制结构\n";
        $prompt .= "8. js_content 必须是纯JavaScript，不能包含任何PHP代码\n";
        $prompt .= "9. 如果是错误修复，必须使用工具做精准替换，避免重写整份代码\n";
        
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
        
        // 如果没有代码块，尝试查找 <?php 开始的内容
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
        $html = "<div class=\"ai-component-content\">\n";
        $html .= "    <h2 class=\"ai-component-title\"><?= htmlspecialchars(\$title ?? '{$name}') ?></h2>\n";
        $html .= "    <p class=\"ai-component-desc\"><?= htmlspecialchars(\$description ?? '') ?></p>\n";
        
        // 根据字段添加 HTML
        foreach ($fields as $field) {
            if ($field['group'] === 'button') {
                $html .= "    <?php if (!empty(\$text)): ?>\n";
                $html .= "    <a href=\"<?= htmlspecialchars(\$url ?? '#') ?>\" class=\"ai-component-btn\"><?= htmlspecialchars(\$text) ?></a>\n";
                $html .= "    <?php endif; ?>\n";
                break;
            }
            if ($field['group'] === 'image') {
                $html .= "    <?php if (!empty(\$src)): ?>\n";
                $html .= "    <img src=\"<?= htmlspecialchars(\$src) ?>\" alt=\"<?= htmlspecialchars(\$alt ?? '') ?>\" class=\"ai-component-img\">\n";
                $html .= "    <?php endif; ?>\n";
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
        return <<<CSS
#<?= \$componentId ?> {
    background-color: <?= htmlspecialchars(\$bgColor ?? '#ffffff') ?>;
    color: <?= htmlspecialchars(\$textColor ?? '#333333') ?>;
    padding: 60px 20px;
    width: 100%;
}

#<?= \$componentId ?> .ai-component-content {
    max-width: 1200px;
    margin: 0 auto;
    text-align: center;
}

#<?= \$componentId ?> .ai-component-title {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 20px;
}

#<?= \$componentId ?> .ai-component-desc {
    font-size: 16px;
    line-height: 1.6;
    margin-bottom: 30px;
    opacity: 0.8;
}

#<?= \$componentId ?> .ai-component-btn {
    display: inline-block;
    padding: 12px 30px;
    background: linear-gradient(90deg, #6c5ce7 0%, #a29bfe 100%);
    color: #ffffff;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    transition: transform 0.3s, box-shadow 0.3s;
}

#<?= \$componentId ?> .ai-component-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(108, 92, 231, 0.4);
}

#<?= \$componentId ?> .ai-component-img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    margin: 20px 0;
}

@media (max-width: 767px) {
    #<?= \$componentId ?> {
        padding: 40px 15px;
    }
    
    #<?= \$componentId ?> .ai-component-title {
        font-size: 24px;
    }
}
CSS;
    }
}
