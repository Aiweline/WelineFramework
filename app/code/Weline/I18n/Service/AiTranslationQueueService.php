<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Queue\Service\IdempotentQueueAdmission;

class AiTranslationQueueService
{
    public const QUEUE_CLASS = 'Weline\\I18n\\Queue\\AiTranslateQueue';
    public const IDEMPOTENCY_SCOPE = 'i18n_ai_translation_slot';

    public function __construct(
        private readonly AiTranslationConfig $config,
        private readonly IdempotentQueueAdmission $admission,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function enqueueEnabledLocales(string $requestedBy = 'auto', bool $force = false): array
    {
        $queueIds = [];
        foreach ($this->config->getEnabledLocaleCodes() as $localeCode) {
            $queueId = $this->enqueueLocale($localeCode, [], $requestedBy, $force);
            if ($queueId > 0) {
                $queueIds[$localeCode] = $queueId;
            }
        }

        return $queueIds;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function enqueueLocale(
        string $localeCode,
        array $overrides = [],
        string $requestedBy = 'manual',
        bool $force = false,
        bool $deduplicate = true
    ): int {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if (!$this->config->isTranslatableLocale($localeCode)) {
            return 0;
        }

        $bizKey = $this->buildBizKey($localeCode);
        $content = array_merge([
            'locale_code' => $localeCode,
            'source_locale' => $this->config->getSourceLocale(),
            'batch_size' => $this->config->getBatchSize($localeCode),
            'strategy' => $this->config->getStrategy($localeCode),
            'publish' => $this->config->shouldAutoPublish(),
            'force' => $force,
            'requested_by' => $requestedBy,
            'manual' => $requestedBy === 'manual' || !empty($overrides['manual']),
            'consecutive_failures' => 0,
        ], $overrides);

        return $this->admission->admit([
            'class' => self::QUEUE_CLASS,
            'name' => 'I18n AI翻译 ' . $localeCode,
            'module' => AiTranslationConfig::MODULE,
            'content' => $content,
            'biz_key' => $bizKey,
            'idempotency_scope' => self::IDEMPOTENCY_SCOPE,
            'idempotency_key' => $bizKey,
            'auto' => true,
        ]);
    }

    public function enqueueContinuation(string $localeCode, array $currentContent): int
    {
        return $this->enqueueLocale(
            $localeCode,
            [
                'source_locale' => (string)($currentContent['source_locale'] ?? $this->config->getSourceLocale()),
                'batch_size' => (int)($currentContent['batch_size'] ?? $this->config->getBatchSize($localeCode)),
                'strategy' => (string)($currentContent['strategy'] ?? $this->config->getStrategy($localeCode)),
                'publish' => (bool)($currentContent['publish'] ?? $this->config->shouldAutoPublish()),
                'force' => false,
                'manual' => !empty($currentContent['manual']),
                'words' => is_array($currentContent['words'] ?? null) ? $currentContent['words'] : [],
                'word_filter' => is_array($currentContent['word_filter'] ?? null) ? $currentContent['word_filter'] : [],
                'module_name' => (string)($currentContent['module_name'] ?? ''),
                'word_prefix' => (string)($currentContent['word_prefix'] ?? ''),
                'allow_key_only_words' => !empty($currentContent['allow_key_only_words']),
                'domain' => (string)($currentContent['domain'] ?? ''),
                'requested_by' => (string)($currentContent['requested_by'] ?? 'continuation'),
                'consecutive_failures' => max(0, (int)($currentContent['consecutive_failures'] ?? 0)),
            ],
            'continuation',
            false,
            false
        );
    }

    public function buildBizKey(string $localeCode): string
    {
        return 'i18n:ai_translation:' . trim(str_replace('-', '_', $localeCode));
    }
}
