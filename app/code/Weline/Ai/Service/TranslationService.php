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

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiDefaultModel;
use Weline\Ai\Service\DefaultModelManager;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\App\Exception;

/**
 * 翻译服务
 * 
 * 功能：
 * - 提供AI翻译功能
 * - 支持翻译缓存
 * - 多种翻译策略（轻量/高保真）
 * - 成本控制和并发限制
 */
class TranslationService
{
    /**
     * 翻译策略常量
     */
    public const STRATEGY_LIGHT = 'light';
    public const STRATEGY_HIGH_FIDELITY = 'high_fidelity';

    /**
     * @var AiService
     */
    private AiService $aiService;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * @var I18nIntegration
     */
    private I18nIntegration $i18nIntegration;

    /**
     * @var DefaultModelManager
     */
    private DefaultModelManager $defaultModelManager;

    /**
     * 构造函数
     * 
     * @param AiService $aiService
     * @param CacheInterface $cache
     * @param I18nIntegration $i18nIntegration
     * @param DefaultModelManager $defaultModelManager
     */
    public function __construct(
        AiService $aiService,
        CacheInterface $cache,
        I18nIntegration $i18nIntegration,
        DefaultModelManager $defaultModelManager
    ) {
        $this->aiService = $aiService;
        $this->cache = $cache;
        $this->i18nIntegration = $i18nIntegration;
        $this->defaultModelManager = $defaultModelManager;
    }

    /**
     * 翻译文本
     * 
     * @param string $text
     * @param string $targetLocale
     * @param string $sourceLocale
     * @param string $strategy
     * @return string
     * @throws Exception
     */
    public function translate(
        string $text, 
        string $targetLocale, 
        string $sourceLocale = 'auto', 
        string $strategy = self::STRATEGY_LIGHT
    ): string {
        // 保存原始语言代码用于适配器参数
        $originalTargetLocale = $targetLocale;
        
        // 验证目标语言（会标准化格式，如 ja_JP -> ja-JP）
        $targetLocale = $this->i18nIntegration->validateAndGetLocale($targetLocale);
        
        // 生成缓存键
        $cacheKey = $this->generateCacheKey($text, $targetLocale, $sourceLocale, $strategy);
        
        // 尝试从缓存获取
        $cachedTranslation = $this->cache->get($cacheKey);
        if ($cachedTranslation) {
            return $cachedTranslation;
        }

        // 执行翻译
        $translation = $this->performTranslation($text, $targetLocale, $sourceLocale, $strategy);
        
        // 缓存翻译结果
        $this->cache->set($cacheKey, $translation, 3600 * 24); // 缓存24小时
        
        return $translation;
    }

    /**
     * 批量翻译
     * 
     * 一次性将所有文本发送给AI进行批量翻译，而不是循环单个翻译
     * 
     * @param array $texts
     * @param string $targetLocale
     * @param string $sourceLocale
     * @param string $strategy
     * @return array
     * @throws Exception
     */
    public function batchTranslate(
        array $texts, 
        string $targetLocale, 
        string $sourceLocale = 'auto', 
        string $strategy = self::STRATEGY_LIGHT
    ): array {
        if (empty($texts)) {
            return [];
        }
        
        // 保存原始键值对应关系
        $textsWithKeys = [];
        $indexToKey = [];
        $index = 0;
        foreach ($texts as $key => $text) {
            $textsWithKeys[] = $text;
            $indexToKey[$index] = $key;
            $index++;
        }
        
        // 验证目标语言
        $originalTargetLocale = $targetLocale;
        $targetLocale = $this->i18nIntegration->validateAndGetLocale($targetLocale);
        
        // 获取翻译模型
        $defaultModel = $this->defaultModelManager->getDefaultModel('translation');
        if (!$defaultModel) {
            throw new Exception(__('未配置翻译默认模型'));
        }
        $modelCode = $defaultModel->getData(\Weline\Ai\Model\AiModel::fields_MODEL_CODE);
        
        // 准备适配器参数
        $targetLanguage = $this->getLanguageName($targetLocale);
        $sourceLanguage = $sourceLocale === 'auto' ? '自动检测' : $this->getLanguageName($sourceLocale);
        $adapterStrategy = $strategy === self::STRATEGY_HIGH_FIDELITY ? 'professional' : 'standard';
        
        // 构建批量翻译提示词
        $batchPrompt = $this->buildBatchTranslationPrompt($textsWithKeys, $targetLanguage, $sourceLanguage, $adapterStrategy);
        
        try {
            // 一次性调用AI服务进行批量翻译
            $response = $this->aiService->generate(
                $batchPrompt,
                $modelCode,
                'translation',
                null,
                [
                    'target_language' => $targetLanguage,
                    'source_language' => $sourceLanguage,
                    'strategy' => $adapterStrategy
                ]
            );
            
            // 解析批量翻译结果
            $translations = $this->parseBatchTranslationResponse($response, count($textsWithKeys));
            
            // 恢复原始键值对应关系
            $result = [];
            foreach ($translations as $index => $translation) {
                $key = $indexToKey[$index] ?? $index;
                $result[$key] = $translation;
            }
            
            // 如果解析失败，回退到单个翻译
            if (count($result) !== count($texts)) {
                // 解析失败，回退到循环翻译
                $result = [];
                foreach ($texts as $key => $text) {
                    try {
                        $translation = $this->translate($text, $originalTargetLocale, $sourceLocale, $strategy);
                        $result[$key] = $translation;
                    } catch (\Exception $e) {
                        // 翻译失败时返回原文
                        $result[$key] = $text;
                    }
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            // 批量翻译失败，回退到循环翻译
            $result = [];
            foreach ($texts as $key => $text) {
                try {
                    $translation = $this->translate($text, $originalTargetLocale, $sourceLocale, $strategy);
                    $result[$key] = $translation;
                } catch (\Exception $ex) {
                    // 翻译失败时返回原文
                    $result[$key] = $text;
                }
            }
            return $result;
        }
    }
    
    /**
     * 构建批量翻译提示词
     * 
     * @param array $texts
     * @param string $targetLanguage
     * @param string $sourceLanguage
     * @param string $strategy
     * @return string
     */
    private function buildBatchTranslationPrompt(
        array $texts,
        string $targetLanguage,
        string $sourceLanguage,
        string $strategy
    ): string {
        $textList = '';
        foreach ($texts as $index => $text) {
            $num = $index + 1;
            $textList .= "{$num}. {$text}\n";
        }
        
        if ($sourceLanguage === '自动检测') {
            $prompt = "请将以下文本列表翻译成{$targetLanguage}，要求：\n";
        } else {
            $prompt = "请将以下{$sourceLanguage}文本列表翻译成{$targetLanguage}，要求：\n";
        }
        
        $prompt .= "1. 保持原文的顺序和编号\n";
        $prompt .= "2. 每行翻译结果格式为：编号. 翻译内容\n";
        $prompt .= "3. 只返回翻译结果，不要包含其他说明\n";
        $prompt .= "4. 如果某条文本无法翻译，保持原文不变\n\n";
        $prompt .= "原文列表：\n{$textList}\n翻译结果：\n";
        
        return $prompt;
    }
    
    /**
     * 解析批量翻译响应
     * 
     * @param string $response
     * @param int $expectedCount
     * @return array
     */
    private function parseBatchTranslationResponse(string $response, int $expectedCount): array
    {
        $translations = [];
        $lines = explode("\n", trim($response));
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // 匹配格式：1. 翻译内容 或 1.翻译内容
            if (preg_match('/^\d+\.\s*(.+)$/', $line, $matches)) {
                $translations[] = trim($matches[1]);
            } elseif (preg_match('/^\d+\.(.+)$/', $line, $matches)) {
                $translations[] = trim($matches[1]);
            } else {
                // 如果没有编号，直接作为翻译内容
                $translations[] = $line;
            }
        }
        
        // 如果解析的数量不匹配，尝试其他解析方式
        if (count($translations) !== $expectedCount) {
            // 尝试按行分割，每行一个翻译
            $translations = array_filter(array_map('trim', $lines), function($line) {
                return !empty($line) && !preg_match('/^(原文|翻译|Translation|Result)/i', $line);
            });
            $translations = array_values($translations);
        }
        
        // 确保返回的数量正确
        while (count($translations) < $expectedCount) {
            $translations[] = '';
        }
        
        return array_slice($translations, 0, $expectedCount);
    }

    /**
     * 执行翻译
     * 
     * @param string $text
     * @param string $targetLocale
     * @param string $sourceLocale
     * @param string $strategy
     * @return string
     * @throws Exception
     */
    private function performTranslation(
        string $text, 
        string $targetLocale, 
        string $sourceLocale, 
        string $strategy
    ): string {
        // 获取翻译模型
        $defaultModel = $this->defaultModelManager->getDefaultModel('translation');
        $modelCode = $defaultModel ? $defaultModel->getData(\Weline\Ai\Model\AiModel::fields_MODEL_CODE) : null;
        
        // 准备适配器参数
        // 注意：$targetLocale 已经被 validateAndGetLocale 标准化了（如 ja-JP）
        // 但 getLanguageName 需要处理标准化后的格式
        $targetLanguage = $this->getLanguageName($targetLocale);
        $sourceLanguage = $sourceLocale === 'auto' ? '自动检测' : $this->getLanguageName($sourceLocale);
        
        // 检查语言名称是否正确获取
        if (empty($targetLanguage) || $targetLanguage === $targetLocale) {
            // 如果无法识别，尝试使用标准化后的格式
            $normalized = str_replace('_', '-', $targetLocale);
            $targetLanguage = $this->getLanguageName($normalized);
            if (empty($targetLanguage) || $targetLanguage === $normalized) {
                // 仍然无法识别，使用原始语言代码
                $targetLanguage = $targetLocale;
            }
        }
        
        // 转换策略名称（TranslationService 的策略 -> TranslationAdapter 的策略）
        $adapterStrategy = $strategy === self::STRATEGY_HIGH_FIDELITY ? 'professional' : 'standard';
        
        // 调用AI服务，传递适配器所需的参数
        // TranslationAdapter 会自动处理提示词构建和响应处理
        $response = $this->aiService->generate(
            $text,  // 原始文本，让适配器处理
            $modelCode, 
            'translation',  // 场景代码
            null,  // locale
            [
                'target_language' => $targetLanguage,
                'source_language' => $sourceLanguage,
                'strategy' => $adapterStrategy
            ]
        );
        
        // 响应已经被 TranslationAdapter 处理过了，直接返回
        return trim($response);
    }

    /**
     * 构建翻译提示词
     * 
     * @param string $text
     * @param string $targetLocale
     * @param string $sourceLocale
     * @param string $strategy
     * @return string
     */
    private function buildTranslationPrompt(
        string $text, 
        string $targetLocale, 
        string $sourceLocale, 
        string $strategy
    ): string {
        $targetLanguage = $this->getLanguageName($targetLocale);
        $sourceLanguage = $sourceLocale === 'auto' ? '自动检测' : $this->getLanguageName($sourceLocale);
        
        if ($strategy === self::STRATEGY_HIGH_FIDELITY) {
            return "请将以下文本从{$sourceLanguage}翻译成{$targetLanguage}，要求：\n" .
                   "1. 保持原文的语气和风格\n" .
                   "2. 准确传达原文含义\n" .
                   "3. 符合目标语言的表达习惯\n" .
                   "4. 只返回翻译结果，不要包含其他内容\n\n" .
                   "原文：{$text}\n\n翻译：";
        } else {
            return "请将以下文本翻译成{$targetLanguage}，只返回翻译结果：\n\n{$text}";
        }
    }

    /**
     * 提取翻译结果
     * 
     * @param string $response
     * @return string
     */
    private function extractTranslation(string $response): string
    {
        // 清理响应内容
        $translation = trim($response);
        
        // 移除可能的前缀
        $prefixes = ['翻译：', '翻译结果：', 'Translation:', 'Result:'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($translation, $prefix)) {
                $translation = trim(substr($translation, strlen($prefix)));
                break;
            }
        }
        
        return $translation;
    }

    /**
     * 获取语言名称
     * 
     * @param string $localeCode
     * @return string
     */
    private function getLanguageName(string $localeCode): string
    {
        // 标准化语言代码格式（支持 zh_Hans_CN, zh-CN, ja_JP, ja-JP 等格式）
        $normalized = str_replace('_', '-', $localeCode);
        
        $languageNames = [
            'zh-CN' => '中文',
            'zh-Hans-CN' => '中文',
            'en-US' => '英文',
            'ja-JP' => '日文',
            'ko-KR' => '韩文',
            'fr-FR' => '法文',
            'de-DE' => '德文',
            'es-ES' => '西班牙文',
            'ru-RU' => '俄文'
        ];
        
        // 先尝试完整匹配
        if (isset($languageNames[$normalized])) {
            return $languageNames[$normalized];
        }
        
        // 尝试匹配语言部分（如 zh-CN 匹配 zh）
        $parts = explode('-', $normalized);
        $langCode = strtolower($parts[0] ?? '');
        
        $langMap = [
            'zh' => '中文',
            'en' => '英文',
            'ja' => '日文',
            'ko' => '韩文',
            'fr' => '法文',
            'de' => '德文',
            'es' => '西班牙文',
            'ru' => '俄文'
        ];
        
        return $langMap[$langCode] ?? $localeCode;
    }

    /**
     * 生成缓存键
     * 
     * @param string $text
     * @param string $targetLocale
     * @param string $sourceLocale
     * @param string $strategy
     * @return string
     */
    private function generateCacheKey(
        string $text, 
        string $targetLocale, 
        string $sourceLocale, 
        string $strategy
    ): string {
        $data = [
            'text' => $text,
            'target' => $targetLocale,
            'source' => $sourceLocale,
            'strategy' => $strategy
        ];
        
        return 'ai_translation_' . md5(json_encode($data));
    }

    /**
     * 清理翻译缓存
     * 
     * @param string|null $pattern
     * @return bool
     */
    public function clearTranslationCache(?string $pattern = null): bool
    {
        if ($pattern) {
            return $this->cache->deleteByPattern('ai_translation_' . $pattern);
        } else {
            return $this->cache->deleteByPattern('ai_translation_*');
        }
    }

    /**
     * 获取支持的翻译策略
     * 
     * @return array
     */
    public static function getSupportedStrategies(): array
    {
        return [
            self::STRATEGY_LIGHT => [
                'name' => '轻量翻译',
                'description' => '快速翻译，适合大量文本',
                'cost' => 'low'
            ],
            self::STRATEGY_HIGH_FIDELITY => [
                'name' => '高保真翻译',
                'description' => '高质量翻译，保持语气和风格',
                'cost' => 'high'
            ]
        ];
    }
}
