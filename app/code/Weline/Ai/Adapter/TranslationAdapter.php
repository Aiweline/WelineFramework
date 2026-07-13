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
        if ($sourceLanguage === '自动检测') {
            return "请将以下文本翻译成{$targetLanguage}，只返回翻译结果：\n\n{$text}";
        } else {
            return "请将以下{$sourceLanguage}文本翻译成{$targetLanguage}，只返回翻译结果：\n\n{$text}";
        }
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
        $contextInfo = $context ? "，这是{$context}相关内容" : '';
        
        return "请将以下文本从{$sourceLanguage}翻译成{$targetLanguage}{$contextInfo}，要求：\n" .
               "1. 保持原文的专业性和准确性\n" .
               "2. 使用标准的专业术语\n" .
               "3. 保持原文的结构和格式\n" .
               "4. 只返回翻译结果，不要包含其他内容\n\n" .
               "原文：{$text}\n\n翻译：";
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
        return "请将以下{$sourceLanguage}文本翻译成{$targetLanguage}，要求：\n" .
               "1. 使用自然、口语化的表达\n" .
               "2. 符合日常交流习惯\n" .
               "3. 保持原文的语气和情感\n" .
               "4. 只返回翻译结果\n\n" .
               "原文：{$text}\n\n翻译：";
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
        $contextInfo = $context ? "，这是{$context}" : '';
        
        return "请将以下技术文本从{$sourceLanguage}翻译成{$targetLanguage}{$contextInfo}，要求：\n" .
               "1. 保持技术术语的准确性\n" .
               "2. 代码和命令保持原样不翻译\n" .
               "3. 保持原文的技术逻辑\n" .
               "4. 使用标准的技术文档表达方式\n" .
               "5. 只返回翻译结果\n\n" .
               "原文：{$text}\n\n翻译：";
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
