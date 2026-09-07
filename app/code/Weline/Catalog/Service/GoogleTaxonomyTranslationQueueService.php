<?php

declare(strict_types=1);

namespace Weline\Catalog\Service;

use Weline\I18n\Service\AiTranslationConfig;
use Weline\Queue\Service\IdempotentQueueAdmission;

/**
 * Enqueue AI translation jobs for Google taxonomy dictionary keys.
 */
final class GoogleTaxonomyTranslationQueueService
{
    public const QUEUE_CLASS = 'Weline\\I18n\\Queue\\AiTranslateQueue';
    public const WORD_PREFIX = 'google_taxonomy.';
    public const IDEMPOTENCY_SCOPE = 'google_taxonomy_ai_translation_slot';

    public function __construct(
        private readonly AiTranslationConfig $translationConfig,
        private readonly IdempotentQueueAdmission $admission,
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

        return $this->admission->admit([
            'class' => self::QUEUE_CLASS,
            'name' => (string)__('Google 分类 AI 翻译 %{1}', [$localeCode]),
            'module' => 'Weline_Catalog',
            'content' => $content,
            'biz_key' => $bizKey,
            'idempotency_scope' => self::IDEMPOTENCY_SCOPE,
            'idempotency_key' => $bizKey,
            'auto' => true,
        ]);
    }
}
