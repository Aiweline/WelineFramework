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
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
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
     * @var CachePoolInterface
     */
    private CachePoolInterface $cache;

    /**
     * @var I18nIntegration
     */
    private I18nIntegration $i18nIntegration;

    /**
     * @var DefaultModelManager
     */
    private DefaultModelManager $defaultModelManager;

    /**
     * @var TranslationConcurrencyGate
     */
    private TranslationConcurrencyGate $concurrencyGate;

    /**
     * Per-request timeout for translation model calls (seconds).
     * Avoids infinite curl waits when the local runner is saturated.
     * Keep below TranslationConcurrencyGate::STALE_HOLD_SECONDS so a hung
     * holder is disconnected before the next cron idle-spins on a dead lock.
     * Non-stream Ollama replies send no body until done — low_speed must cover
     * full generation (SSH tunnel + 12B can exceed several minutes per batch).
     */
    private const REQUEST_TIMEOUT_SECONDS = 900;

    /** Low-speed abort (must be <= REQUEST_TIMEOUT_SECONDS); align with full timeout for non-stream. */
    private const REQUEST_LOW_SPEED_SECONDS = 900;

    /** Connect abort when local runner is down (127.0.0.1:11434 refused). */
    private const REQUEST_CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * 构造函数
     * 
     * @param AiService $aiService
     * @param CacheManager $cacheManager
     * @param I18nIntegration $i18nIntegration
     * @param DefaultModelManager $defaultModelManager
     * @param TranslationConcurrencyGate $concurrencyGate
     */
    public function __construct(
        AiService $aiService,
        CacheManager $cacheManager,
        I18nIntegration $i18nIntegration,
        DefaultModelManager $defaultModelManager,
        TranslationConcurrencyGate $concurrencyGate
    ) {
        $this->aiService = $aiService;
        $this->cache = $cacheManager->pool('ai_translation');
        $this->i18nIntegration = $i18nIntegration;
        $this->defaultModelManager = $defaultModelManager;
        $this->concurrencyGate = $concurrencyGate;
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
        string $strategy = self::STRATEGY_LIGHT,
        string $concurrencyLane = TranslationConcurrencyGate::LANE_DICTIONARY,
    ): string {
        // 保存原始语言代码用于适配器参数
        $originalTargetLocale = $targetLocale;
        
        // 验证目标语言（会标准化格式，如 ja_JP -> ja-JP）
        $targetLocale = $this->i18nIntegration->validateAndGetLocale($targetLocale);

        // Never acquire a lane for blank text (avoids starving other work on shared mistakes).
        if (trim($text) === '') {
            return '';
        }
        
        // 生成缓存键
        $cacheKey = $this->generateCacheKey($text, $targetLocale, $sourceLocale, $strategy);
        
        // 尝试从缓存获取
        $cachedTranslation = $this->cache->get($cacheKey);
        if ($cachedTranslation) {
            return $cachedTranslation;
        }

        // 执行翻译
        $translation = $this->performTranslation($text, $targetLocale, $sourceLocale, $strategy, $concurrencyLane);
        if (self::isJunkTranslation($translation)) {
            // Incomplete model replies (e.g. lone "[") must never be cached or returned as copy.
            return '';
        }

        // 缓存翻译结果
        $this->cache->set($cacheKey, $translation, 3600 * 24); // 缓存24小时
        
        return $translation;
    }

    /**
     * True when a model reply is structural garbage (truncated JSON punctuation), not real UI copy.
     */
    public static function isJunkTranslation(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        // Lone / punctuation-only JSON fragments from failed batch parse (e.g. "[").
        if (preg_match('/^[\[\]\{\}\s,",\'\\\\]+$/u', $value) === 1) {
            return true;
        }

        return false;
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
        string $strategy = self::STRATEGY_LIGHT,
        string $concurrencyLane = TranslationConcurrencyGate::LANE_DICTIONARY,
    ): array {
        if (empty($texts)) {
            return [];
        }

        // Drop blank strings before locking — empty work must never occupy a lane.
        $filtered = [];
        foreach ($texts as $key => $text) {
            if (trim((string)$text) === '') {
                continue;
            }
            $filtered[$key] = $text;
        }
        if ($filtered === []) {
            return [];
        }
        $texts = $filtered;

        $concurrencyLane = $concurrencyLane !== ''
            ? $concurrencyLane
            : TranslationConcurrencyGate::LANE_DICTIONARY;
        
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
        
        // 优先使用独立翻译模型；未配置时回退到已配置的通用默认模型。
        // 这样 I18n 的中立翻译事件在可用 AI 服务已存在时仍可完成工作，
        // 而不要求每个调用方重复维护一份 translation 专用配置。
        $defaultModel = $this->defaultModelManager->getDefaultModel('translation')
            ?: $this->defaultModelManager->getDefaultModel('default');
        if (!$defaultModel) {
            throw new Exception(__('未配置翻译或通用默认模型'));
        }
        $modelCode = $defaultModel->getData(\Weline\Ai\Model\AiModel::schema_fields_MODEL_CODE);
        
        // 准备适配器参数
        $targetLanguage = $this->getLanguageName($targetLocale);
        $sourceLanguage = $sourceLocale === 'auto' ? 'auto-detected language' : $this->getLanguageName($sourceLocale);
        $adapterStrategy = $strategy === self::STRATEGY_HIGH_FIDELITY ? 'professional' : 'standard';
        
        // 构建批量翻译提示词
        $batchPrompt = $this->buildBatchTranslationPrompt($textsWithKeys, $targetLanguage, $sourceLanguage, $adapterStrategy);
        
        try {
            // Per-lane single-flight (dictionary vs LocalModel can run in parallel).
            $this->concurrencyGate->acquire(TranslationConcurrencyGate::DEFAULT_WAIT_SECONDS, $concurrencyLane);
            try {
                // 一次性调用AI服务进行批量翻译
                $response = $this->aiService->generate(
                    $batchPrompt,
                    $modelCode,
                    null,
                    null,
                    [
                        'target_language' => $targetLanguage,
                        'source_language' => $sourceLanguage,
                        'strategy' => $adapterStrategy,
                        'timeout_seconds' => self::REQUEST_TIMEOUT_SECONDS,
                        'low_speed_time' => self::REQUEST_LOW_SPEED_SECONDS,
                        'connect_timeout' => self::REQUEST_CONNECT_TIMEOUT_SECONDS,
                        // Batch JSON replies need headroom; default 2000 is usually enough
                        // but long LocalModel fields can truncate mid-array and force parse failure.
                        'max_tokens' => max(512, min(4096, 64 + (count($textsWithKeys) * 96))),
                        'temperature' => 0.2,
                    ]
                );
            } finally {
                $this->concurrencyGate->release($concurrencyLane);
            }
            
            // 解析批量翻译结果
            $translations = $this->parseBatchTranslationResponse($response, count($textsWithKeys));
            
            // 恢复原始键值对应关系
            $result = [];
            foreach ($translations as $index => $translation) {
                $key = $indexToKey[$index] ?? $index;
                $result[$key] = $translation;
            }
            
            // Multi-item batches must not silently fall back to N serial model calls
            // (that re-acquires the concurrency gate and destroys LocalModel throughput).
            if (count($result) !== count($texts)) {
                if (count($texts) > 1) {
                    throw new Exception(__(
                        '批量翻译结果解析失败：期望 %{expected} 条，实际 %{actual} 条',
                        [
                            'expected' => (string)count($texts),
                            'actual' => (string)count($result),
                        ],
                    ));
                }
                // Single-item only: fall back to the one-shot path.
                $result = [];
                foreach ($texts as $key => $text) {
                    try {
                        $translation = $this->translate($text, $originalTargetLocale, $sourceLocale, $strategy, $concurrencyLane);
                        $result[$key] = $translation;
                    } catch (\Exception $e) {
                        if ($this->concurrencyGate->isBusyMarker($e->getMessage())) {
                            throw $e;
                        }
                        $result[$key] = $text;
                    }
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            // Busy / lock failures must not fall back into N more model calls.
            if ($this->concurrencyGate->isBusyMarker($e->getMessage())) {
                throw $e;
            }
            // Multi-item: surface the failure so callers (LocalModel) can abort/retry.
            if (count($texts) > 1) {
                throw $e;
            }
            // 单条失败时回退到循环翻译
            $result = [];
            foreach ($texts as $key => $text) {
                try {
                    $translation = $this->translate($text, $originalTargetLocale, $sourceLocale, $strategy, $concurrencyLane);
                    $result[$key] = $translation;
                } catch (\Exception $ex) {
                    if ($this->concurrencyGate->isBusyMarker($ex->getMessage())) {
                        throw $ex;
                    }
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
        $payload = json_encode(array_values($texts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $quality = $strategy === 'professional'
            ? 'Use polished, publication-ready ecommerce / admin UI wording.'
            : 'Prefer concise, natural UI labels suitable for menus, buttons, settings, and help text.';

        return "You are a professional translator for a multi-website ecommerce admin and storefront (Weline).\n"
            . "Translate each string from {$sourceLanguage} to {$targetLanguage}.\n"
            . "Return ONLY a valid JSON array of translated strings in the same order and with the same item count.\n"
            . "Do not wrap the array in markdown fences, and do not add explanations.\n"
            . "Rules:\n"
            . "- {$quality}\n"
            . "- Keep established product terms natural in the target language "
            . "(SKU, OAuth, webhook, cron, API, CSV may stay as conventional loanwords when that is normal UI copy).\n"
            . "- Prefer \"advanced maintenance\" over marketing words like \"premium\" for admin labels such as 高级维护.\n"
            . "- Prefer \"identity\" for 身份 when it means a system identity, not a human ID card.\n"
            . "- Preserve placeholders such as %{1}, %{name}, HTML tags, and template tokens exactly.\n"
            . "- Do not transliterate Chinese into pinyin; produce real {$targetLanguage}.\n"
            . "- Never return the source text unchanged when the languages differ.\n"
            . "Strategy: {$strategy}.\n"
            . "Input JSON:\n{$payload}";
    }
    
    /**
     * 解析批量翻译响应。
     * Fail closed: incomplete JSON / punctuation junk (e.g. lone "[") must not become translations.
     *
     * @return list<string>
     */
    public static function parseBatchTranslationResponse(string $response, int $expectedCount): array
    {
        if ($expectedCount <= 0) {
            return [];
        }

        $json = trim($response);
        if ($json === '') {
            return [];
        }
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/i', '', $json) ?? $json;
            $json = preg_replace('/\s*```$/', '', $json) ?? $json;
            $json = trim($json);
        }

        $decoded = json_decode($json, true);
        if (!(is_array($decoded) && array_is_list($decoded) && count($decoded) === $expectedCount)) {
            // Model often wraps JSON with prose; extract the outermost array only when balanced.
            $start = strpos($json, '[');
            $end = strrpos($json, ']');
            if ($start !== false && $end !== false && $end > $start) {
                $slice = substr($json, $start, $end - $start + 1);
                $decoded = json_decode($slice, true);
            } else {
                // Truncated replies like "[" or '["foo"' have no closing bracket — never line-fallback them.
                $decoded = null;
            }
        }

        if (is_array($decoded) && array_is_list($decoded) && count($decoded) === $expectedCount) {
            return self::normalizeParsedBatchItems($decoded, $expectedCount);
        }

        // Only allow numbered / plain-line fallback when the reply does not look like broken JSON.
        if (str_contains($json, '[') || str_contains($json, ']')) {
            return [];
        }

        $translations = [];
        $lines = explode("\n", trim($response));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '```') || self::isJunkTranslation($line)) {
                continue;
            }

            // 匹配格式：1. 翻译内容 或 1.翻译内容
            if (preg_match('/^\d+\.\s*(.+)$/', $line, $matches)) {
                $translations[] = trim($matches[1]);
            } elseif (preg_match('/^\d+\.(.+)$/', $line, $matches)) {
                $translations[] = trim($matches[1]);
            }
        }

        // 如果解析的数量不匹配，尝试其他解析方式
        if (count($translations) !== $expectedCount) {
            // 尝试按行分割，每行一个翻译
            $translations = array_filter(array_map('trim', $lines), static function ($line) {
                return $line !== ''
                    && !str_starts_with($line, '```')
                    && !preg_match('/^(原文|翻译|Translation|Result|Input JSON)/i', $line)
                    && !self::isJunkTranslation($line);
            });
            $translations = array_values($translations);
        }

        return self::normalizeParsedBatchItems($translations, $expectedCount);
    }

    /**
     * @param list<mixed> $items
     * @return list<string>
     */
    private static function normalizeParsedBatchItems(array $items, int $expectedCount): array
    {
        if (count($items) !== $expectedCount) {
            return [];
        }

        $mapped = [];
        foreach ($items as $item) {
            $text = trim((string)$item);
            if ($text === '' || self::isJunkTranslation($text)) {
                return [];
            }
            $mapped[] = $text;
        }

        return $mapped;
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
        string $strategy,
        string $concurrencyLane = TranslationConcurrencyGate::LANE_DICTIONARY,
    ): string {
        // 获取翻译模型
        $defaultModel = $this->defaultModelManager->getDefaultModel('translation');
        $modelCode = $defaultModel ? $defaultModel->getData(\Weline\Ai\Model\AiModel::schema_fields_MODEL_CODE) : null;
        
        // 准备适配器参数
        // 注意：$targetLocale 已经被 validateAndGetLocale 标准化了（如 ja-JP）
        // 但 getLanguageName 需要处理标准化后的格式
        $targetLanguage = $this->getLanguageName($targetLocale);
        $sourceLanguage = $sourceLocale === 'auto' ? 'auto-detected language' : $this->getLanguageName($sourceLocale);
        
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
        $this->concurrencyGate->acquire(TranslationConcurrencyGate::DEFAULT_WAIT_SECONDS, $concurrencyLane);
        try {
            $response = $this->aiService->generate(
                $text,  // 原始文本，让适配器处理
                $modelCode, 
                'translation',  // 场景代码
                null,  // locale
                [
                    'target_language' => $targetLanguage,
                    'source_language' => $sourceLanguage,
                    'strategy' => $adapterStrategy,
                    'timeout_seconds' => self::REQUEST_TIMEOUT_SECONDS,
                    'low_speed_time' => self::REQUEST_LOW_SPEED_SECONDS,
                    'connect_timeout' => self::REQUEST_CONNECT_TIMEOUT_SECONDS,
                ]
            );
        } finally {
            $this->concurrencyGate->release($concurrencyLane);
        }
        
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
        $sourceLanguage = $sourceLocale === 'auto' ? 'auto-detected language' : $this->getLanguageName($sourceLocale);
        
        if ($strategy === self::STRATEGY_HIGH_FIDELITY) {
            return "You are a professional translator for ecommerce admin/storefront UI.\n"
                . "Translate from {$sourceLanguage} to {$targetLanguage}.\n"
                . "Keep tone natural and precise; preserve placeholders/HTML/tokens exactly.\n"
                . "Prefer conventional admin wording (advanced maintenance, SKU identity).\n"
                . "Return only the translation text.\n\n"
                . "Source:\n{$text}";
        }

        return "Translate this ecommerce/admin UI string from {$sourceLanguage} to {$targetLanguage}.\n"
            . "Return only the translation. Preserve placeholders and HTML. Do not echo the source.\n\n"
            . $text;
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
            'zh-CN' => 'Chinese',
            'zh-Hans-CN' => 'Chinese',
            'en-US' => 'English',
            'bn-IN' => 'Bengali',
            'bn-BD' => 'Bengali',
            'ja-JP' => 'Japanese',
            'ko-KR' => 'Korean',
            'fr-FR' => 'French',
            'de-DE' => 'German',
            'es-ES' => 'Spanish',
            'ru-RU' => 'Russian'
        ];
        
        // 先尝试完整匹配
        if (isset($languageNames[$normalized])) {
            return $languageNames[$normalized];
        }
        
        // 尝试匹配语言部分（如 zh-CN 匹配 zh）
        $parts = explode('-', $normalized);
        $langCode = strtolower($parts[0] ?? '');
        
        $langMap = [
            'zh' => 'Chinese',
            'en' => 'English',
            'bn' => 'Bengali',
            'hi' => 'Hindi',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'ru' => 'Russian'
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
        return $this->cache->clear();
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
