<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Adapter;

use Weline\Ai\Api\ScenarioAdapterInterface;

/**
 * 翻译场景适配器
 * 
 * 功能：
 * - 专门优化AI翻译任务
 * - 提供翻译专用提示词模板
 * - 支持多种翻译策略
 * - 优化翻译质量和准确性
 */
class TranslationAdapter implements ScenarioAdapterInterface
{
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'translation';
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return '翻译适配器';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return '专门用于AI翻译任务的场景适配器，提供高质量的翻译优化和多语言支持';
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * @inheritDoc
     */
    public function getSupportedModelTypes(): array
    {
        return ['chat', 'completion'];
    }

    /**
     * @inheritDoc
     */
    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $targetLanguage = $params['target_language'] ?? '中文';
        $sourceLanguage = $params['source_language'] ?? '自动检测';
        $strategy = $params['strategy'] ?? 'standard';
        $context = $params['context'] ?? '';

        // 根据策略选择不同的提示词模板
        switch ($strategy) {
            case 'professional':
                return $this->buildProfessionalPrompt($prompt, $targetLanguage, $sourceLanguage, $context);
            case 'casual':
                return $this->buildCasualPrompt($prompt, $targetLanguage, $sourceLanguage);
            case 'technical':
                return $this->buildTechnicalPrompt($prompt, $targetLanguage, $sourceLanguage, $context);
            default:
                return $this->buildStandardPrompt($prompt, $targetLanguage, $sourceLanguage);
        }
    }

    /**
     * @inheritDoc
     */
    public function processResponse(string $response, array $params = []): string
    {
        // 清理响应内容
        $translation = trim($response);
        
        // 移除可能的前缀和后缀
        $prefixes = [
            '翻译：', '翻译结果：', 'Translation:', 'Result:', 
            '译文：', '翻译为：', 'Translated text:', '翻译内容：'
        ];
        
        foreach ($prefixes as $prefix) {
            if (str_starts_with($translation, $prefix)) {
                $translation = trim(substr($translation, strlen($prefix)));
                break;
            }
        }

        // 移除引号包围
        if ((str_starts_with($translation, '"') && str_ends_with($translation, '"')) ||
            (str_starts_with($translation, "'") && str_ends_with($translation, "'"))) {
            $translation = substr($translation, 1, -1);
        }

        // 处理特殊格式
        if (isset($params['format'])) {
            $translation = $this->formatTranslation($translation, $params['format']);
        }

        return trim($translation);
    }

    /**
     * @inheritDoc
     */
    public function validateParams(array $params = []): array
    {
        $errors = [];

        // 验证目标语言
        if (empty($params['target_language'])) {
            $errors[] = '目标语言不能为空';
        }

        // 验证策略
        $validStrategies = ['standard', 'professional', 'casual', 'technical'];
        if (isset($params['strategy']) && !in_array($params['strategy'], $validStrategies)) {
            $errors[] = '无效的翻译策略';
        }

        // 验证格式
        $validFormats = ['plain', 'markdown', 'html'];
        if (isset($params['format']) && !in_array($params['format'], $validFormats)) {
            $errors[] = '无效的输出格式';
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function getParamTemplate(): array
    {
        return [
            'target_language' => [
                'type' => 'string',
                'required' => true,
                'description' => '目标语言',
                'example' => '中文',
                'options' => ['中文', '英文', '日文', '韩文', '法文', '德文', '西班牙文', '俄文']
            ],
            'source_language' => [
                'type' => 'string',
                'required' => false,
                'description' => '源语言',
                'example' => '英文',
                'default' => '自动检测'
            ],
            'strategy' => [
                'type' => 'string',
                'required' => false,
                'description' => '翻译策略',
                'example' => 'professional',
                'options' => ['standard', 'professional', 'casual', 'technical'],
                'default' => 'standard'
            ],
            'context' => [
                'type' => 'string',
                'required' => false,
                'description' => '上下文信息',
                'example' => '技术文档'
            ],
            'format' => [
                'type' => 'string',
                'required' => false,
                'description' => '输出格式',
                'example' => 'markdown',
                'options' => ['plain', 'markdown', 'html'],
                'default' => 'plain'
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getExamples(): array
    {
        return [
            [
                'title' => '标准翻译',
                'description' => '将英文翻译为中文',
                'input' => 'Hello, how are you?',
                'params' => [
                    'target_language' => '中文',
                    'source_language' => '英文',
                    'strategy' => 'standard'
                ],
                'expected_output' => '你好，你好吗？'
            ],
            [
                'title' => '专业翻译',
                'description' => '技术文档翻译',
                'input' => 'The API endpoint returns a JSON response.',
                'params' => [
                    'target_language' => '中文',
                    'source_language' => '英文',
                    'strategy' => 'technical',
                    'context' => 'API文档'
                ],
                'expected_output' => 'API端点返回JSON响应。'
            ],
            [
                'title' => '口语化翻译',
                'description' => '日常对话翻译',
                'input' => "What's up?",
                'params' => [
                    'target_language' => '中文',
                    'source_language' => '英文',
                    'strategy' => 'casual'
                ],
                'expected_output' => '怎么样？'
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function supportsModel(string $modelCode): bool
    {
        // 支持所有聊天和补全模型
        $supportedModels = [
            'gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo',
            'claude-3', 'claude-2', 'text-davinci-003'
        ];

        return in_array($modelCode, $supportedModels) || 
               str_contains($modelCode, 'gpt') || 
               str_contains($modelCode, 'claude');
    }

    /**
     * 构建标准翻译提示词
     * 
     * @param string $text
     * @param string $targetLanguage
     * @param string $sourceLanguage
     * @return string
     */
    private function buildStandardPrompt(string $text, string $targetLanguage, string $sourceLanguage): string
    {
        $from = $sourceLanguage === '自动检测' ? 'the detected source language' : $sourceLanguage;

        return "You are a professional translator for ecommerce admin and storefront UI (Weline).\n"
            . "Translate from {$from} to {$targetLanguage}.\n"
            . "Return only the translation text — no quotes, labels, or explanations.\n"
            . "Use concise natural UI wording; preserve placeholders (%{1}, %{name}), HTML, and template tokens.\n"
            . "Prefer \"advanced maintenance\" for 高级维护 and \"identity\" for system 身份.\n"
            . "Do not return the source unchanged when languages differ.\n\n"
            . $text;
    }

    /**
     * 构建专业翻译提示词
     * 
     * @param string $text
     * @param string $targetLanguage
     * @param string $sourceLanguage
     * @param string $context
     * @return string
     */
    private function buildProfessionalPrompt(string $text, string $targetLanguage, string $sourceLanguage, string $context): string
    {
        $contextInfo = $context !== '' ? " Context: {$context}." : '';

        return "You are a professional translator for ecommerce/admin documentation and UI.{$contextInfo}\n"
            . "Translate from {$sourceLanguage} to {$targetLanguage}.\n"
            . "Requirements:\n"
            . "1. Accurate, domain-appropriate terminology\n"
            . "2. Preserve structure, placeholders, and HTML exactly\n"
            . "3. Prefer conventional admin wording over marketing tone\n"
            . "4. Return only the translation\n\n"
            . $text;
    }

    /**
     * 构建口语化翻译提示词
     * 
     * @param string $text
     * @param string $targetLanguage
     * @param string $sourceLanguage
     * @return string
     */
    private function buildCasualPrompt(string $text, string $targetLanguage, string $sourceLanguage): string
    {
        return "Translate this storefront-facing copy from {$sourceLanguage} to {$targetLanguage}.\n"
            . "Use natural everyday wording; return only the translation; preserve placeholders/HTML.\n\n"
            . $text;
    }

    /**
     * 构建技术翻译提示词
     * 
     * @param string $text
     * @param string $targetLanguage
     * @param string $sourceLanguage
     * @param string $context
     * @return string
     */
    private function buildTechnicalPrompt(string $text, string $targetLanguage, string $sourceLanguage, string $context): string
    {
        $contextInfo = $context !== '' ? " Context: {$context}." : '';

        return "Translate this technical/admin text from {$sourceLanguage} to {$targetLanguage}.{$contextInfo}\n"
            . "Keep code, commands, and API identifiers unchanged when they are literal tokens.\n"
            . "Preserve placeholders and HTML. Return only the translation.\n\n"
            . $text;
    }

    /**
     * 格式化翻译结果
     * 
     * @param string $translation
     * @param string $format
     * @return string
     */
    private function formatTranslation(string $translation, string $format): string
    {
        switch ($format) {
            case 'markdown':
                // 如果原文包含markdown格式，保持格式
                return $translation;
            case 'html':
                // 转换为HTML格式
                return htmlspecialchars($translation, ENT_QUOTES, 'UTF-8');
            default:
                return $translation;
        }
    }
}
