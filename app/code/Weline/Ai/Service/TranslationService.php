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
     * 构造函数
     * 
     * @param AiService $aiService
     * @param CacheInterface $cache
     * @param I18nIntegration $i18nIntegration
     */
    public function __construct(
        AiService $aiService,
        CacheInterface $cache,
        I18nIntegration $i18nIntegration
    ) {
        $this->aiService = $aiService;
        $this->cache = $cache;
        $this->i18nIntegration = $i18nIntegration;
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
        // 验证目标语言
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
        $translations = [];
        
        foreach ($texts as $key => $text) {
            try {
                $translations[$key] = $this->translate($text, $targetLocale, $sourceLocale, $strategy);
            } catch (\Exception $e) {
                // 翻译失败时返回原文
                $translations[$key] = $text;
            }
        }
        
        return $translations;
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
        // 构建翻译提示词
        $prompt = $this->buildTranslationPrompt($text, $targetLocale, $sourceLocale, $strategy);
        
        // 获取翻译模型
        $modelCode = AiDefaultModel::getDefaultModelCode(AiDefaultModel::SERVICE_TYPE_TRANSLATION);
        
        // 调用AI服务
        $response = $this->aiService->generate($prompt, $modelCode, 'translation');
        
        // 提取翻译结果
        return $this->extractTranslation($response);
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
        $languageNames = [
            'zh-CN' => '中文',
            'en-US' => '英文',
            'ja-JP' => '日文',
            'ko-KR' => '韩文',
            'fr-FR' => '法文',
            'de-DE' => '德文',
            'es-ES' => '西班牙文',
            'ru-RU' => '俄文'
        ];
        
        return $languageNames[$localeCode] ?? $localeCode;
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
