<?php

declare(strict_types=1);

namespace Weline\Catalog\Service;

use Weline\Framework\Async\TaskStatus;
use Weline\I18n\Service\AiTranslationConfig;

/**
 * Enqueue AI translation jobs for Google taxonomy dictionary keys.
 */
final class GoogleTaxonomyTranslationQueueService
{
    public const QUEUE_CLASS = 'Weline\\I18n\\Queue\\AiTranslateQueue';
    public const WORD_PREFIX = 'google_taxonomy.';

    public function __construct(
        private readonly AiTranslationConfig $translationConfig,
    ) {
    }

    public function buildBizKey(string $localeCode): string
    {
        return 'google_taxonomy.ai_translation:' . trim(str_replace('-', '_', $localeCode));
    }

    /**
     * @param list<string> $googleIds
     */
    public function enqueue(string $localeCode, array $googleIds = [], string $requestedBy = 'catalog'): int
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            throw new \InvalidArgumentException((string)__('目标语言不能为空'));
        }

        $bizKey = $this->buildBizKey($localeCode);
        $existing = $this->getLatestQueueByBizKey($bizKey);
        if ($existing && in_array((string)($existing['status'] ?? ''), [TaskStatus::PENDING, TaskStatus::RUNNING], true)) {
            return (int)($existing['queue_id'] ?? 0);
        }

        $words = [];
        foreach ($googleIds as $googleId) {
            $googleId = trim((string)$googleId);
            if ($googleId !== '') {
                $words[] = self::WORD_PREFIX . $googleId;
            }
        }

        $content = [
            'locale_code' => $localeCode,
            'source_locale' => 'en_US',
            'strategy' => AiTranslationConfig::DEFAULT_STRATEGY,
            'batch_size' => $this->translationConfig->getBatchSize($localeCode),
            'publish' => true,
            'requested_by' => $requestedBy,
            'domain' => 'google_taxonomy',
            'allow_key_only_words' => true,
        ];
        if ($words !== []) {
            $content['words'] = $words;
        } else {
            $content['word_prefix'] = self::WORD_PREFIX;
        }

        $result = w_query('queue', 'create', [
            'class' => self::QUEUE_CLASS,
            'name' => (string)__('Google 分类 AI 翻译 %{1}', [$localeCode]),
            'module' => 'Weline_Catalog',
            'content' => $content,
            'status' => TaskStatus::PENDING,
            'auto' => true,
            'biz_key' => $bizKey,
        ]);

        if (is_array($result)) {
            return (int)($result['queue_id'] ?? $result['id'] ?? 0);
        }
        if (is_object($result) && method_exists($result, 'getData')) {
            return (int)($result->getData('queue_id') ?? 0);
        }

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLatestQueueByBizKey(string $bizKey): ?array
    {
        try {
            $row = w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        return $row;
    }
}
