<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\I18n\Service\AiTranslationConfig;
use Weline\Queue\Service\IdempotentQueueAdmission;

/**
 * 为社媒登录客户指南词条入队 I18n AI 翻译（word_filter 定向批次）。
 */
final class SocialLoginGuideTranslationQueueService
{
    public const QUEUE_CLASS = 'Weline\\I18n\\Queue\\AiTranslateQueue';
    public const DOMAIN = 'social_login_guide';
    public const IDEMPOTENCY_SCOPE = 'social_login_guide_ai_translation_slot';

    public function __construct(
        private readonly SocialLoginGuideI18nCatalog $catalog,
        private readonly AiTranslationConfig $translationConfig,
        private readonly IdempotentQueueAdmission $admission,
    ) {
    }

    public function buildBizKey(string $localeCode, ?string $providerCode = null): string
    {
        $localeCode = $this->normalizeLocale($localeCode);
        $providerCode = $providerCode !== null ? strtolower(trim($providerCode)) : '';

        return $providerCode === ''
            ? self::DOMAIN . '.ai_translation:' . $localeCode
            : self::DOMAIN . '.ai_translation:' . $localeCode . ':' . $providerCode;
    }

    /**
     * @return array{queue_id:int,phrase_count:int,locale:string,provider_code:?string,missing_only:bool}
     */
    public function enqueue(
        string $localeCode,
        ?string $providerCode = null,
        bool $missingOnly = true,
        bool $force = false,
        string $requestedBy = 'social_login_guide',
    ): array {
        $localeCode = $this->normalizeLocale($localeCode);
        if ($localeCode === '') {
            throw new \InvalidArgumentException((string) __('目标语言不能为空'));
        }

        $providerCode = $providerCode !== null ? strtolower(trim($providerCode)) : '';
        if ($providerCode !== '' && $this->catalog->audit($providerCode, $localeCode)['providers'] === []) {
            throw new \InvalidArgumentException((string) __('社媒登录提供方 %{1} 未注册客户指南', [$providerCode]));
        }

        $words = $missingOnly
            ? $this->collectMissingPhraseKeys($providerCode !== '' ? $providerCode : null, $localeCode)
            : $this->catalog->listPhraseKeys($providerCode !== '' ? $providerCode : null, true);

        if ($words === []) {
            return [
                'queue_id' => 0,
                'phrase_count' => 0,
                'locale' => $localeCode,
                'provider_code' => $providerCode !== '' ? $providerCode : null,
                'missing_only' => $missingOnly,
            ];
        }

        $bizKey = $this->buildBizKey($localeCode, $providerCode !== '' ? $providerCode : null);
        $label = $providerCode !== '' ? $providerCode : (string) __('全部');
        $content = [
            'locale_code' => $localeCode,
            'source_locale' => $this->translationConfig->getSourceLocale(),
            'strategy' => $this->translationConfig->getStrategy($localeCode),
            'batch_size' => $this->translationConfig->getBatchSize($localeCode),
            'publish' => $this->translationConfig->shouldAutoPublish(),
            'manual' => true,
            'force' => $force,
            'requested_by' => $requestedBy,
            'domain' => self::DOMAIN,
            'owner' => $providerCode !== '' ? self::DOMAIN . ':' . $providerCode : self::DOMAIN . ':all',
            'words' => $words,
        ];

        $queueId = $this->admission->admit([
            'class' => self::QUEUE_CLASS,
            'name' => (string) __('社媒登录指南 AI 翻译 %{1} (%{2})', [$localeCode, $label]),
            'module' => 'Weline_Customer',
            'content' => $content,
            'biz_key' => $bizKey,
            'idempotency_scope' => self::IDEMPOTENCY_SCOPE,
            'idempotency_key' => $bizKey,
            'auto' => true,
        ]);

        return [
            'queue_id' => $queueId,
            'phrase_count' => count($words),
            'locale' => $localeCode,
            'provider_code' => $providerCode !== '' ? $providerCode : null,
            'missing_only' => $missingOnly,
        ];
    }

    /**
     * @return list<string>
     */
    private function collectMissingPhraseKeys(?string $providerCode, string $locale): array
    {
        $report = $this->catalog->audit($providerCode, $locale);
        $missing = [];

        foreach ((array) ($report['providers'] ?? []) as $providerReport) {
            foreach ((array) ($providerReport['missing'] ?? []) as $phrase) {
                $missing[(string) $phrase] = true;
            }
        }

        foreach ((array) ($report['shared']['missing'] ?? []) as $phrase) {
            $missing[(string) $phrase] = true;
        }

        return array_values(array_keys($missing));
    }

    private function normalizeLocale(string $locale): string
    {
        return trim(str_replace('-', '_', $locale));
    }
}
