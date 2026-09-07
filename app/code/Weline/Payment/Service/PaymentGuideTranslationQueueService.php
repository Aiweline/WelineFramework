<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\I18n\Service\AiTranslationConfig;
use Weline\Queue\Service\IdempotentQueueAdmission;

/**
 * 为支付客户指南词条入队 I18n AI 翻译（word_filter 定向批次）。
 */
final class PaymentGuideTranslationQueueService
{
    public const QUEUE_CLASS = 'Weline\\I18n\\Queue\\AiTranslateQueue';
    public const DOMAIN = 'payment_guide';
    public const IDEMPOTENCY_SCOPE = 'payment_guide_ai_translation_slot';

    public function __construct(
        private readonly PaymentGuideI18nCatalog $catalog,
        private readonly AiTranslationConfig $translationConfig,
        private readonly IdempotentQueueAdmission $admission,
    ) {
    }

    public function buildBizKey(string $localeCode, ?string $methodCode = null): string
    {
        $localeCode = $this->normalizeLocale($localeCode);
        $methodCode = $methodCode !== null ? strtolower(trim($methodCode)) : '';

        return $methodCode === ''
            ? self::DOMAIN . '.ai_translation:' . $localeCode
            : self::DOMAIN . '.ai_translation:' . $localeCode . ':' . $methodCode;
    }

    /**
     * @return array{queue_id:int,phrase_count:int,locale:string,method_code:?string,missing_only:bool}
     */
    public function enqueue(
        string $localeCode,
        ?string $methodCode = null,
        bool $missingOnly = true,
        bool $force = false,
        string $requestedBy = 'payment_guide',
    ): array {
        $localeCode = $this->normalizeLocale($localeCode);
        if ($localeCode === '') {
            throw new \InvalidArgumentException((string) __('目标语言不能为空'));
        }

        $methodCode = $methodCode !== null ? strtolower(trim($methodCode)) : '';
        if ($methodCode !== '' && $this->catalog->audit($methodCode, $localeCode)['methods'] === []) {
            throw new \InvalidArgumentException((string) __('支付方式 %{1} 未注册客户指南', [$methodCode]));
        }

        $words = $missingOnly
            ? $this->collectMissingPhraseKeys($methodCode !== '' ? $methodCode : null, $localeCode)
            : $this->catalog->listPhraseKeys($methodCode !== '' ? $methodCode : null, true);

        if ($words === []) {
            return [
                'queue_id' => 0,
                'phrase_count' => 0,
                'locale' => $localeCode,
                'method_code' => $methodCode !== '' ? $methodCode : null,
                'missing_only' => $missingOnly,
            ];
        }

        $bizKey = $this->buildBizKey($localeCode, $methodCode !== '' ? $methodCode : null);
        $label = $methodCode !== '' ? $methodCode : (string) __('全部');
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
            'owner' => $methodCode !== '' ? self::DOMAIN . ':' . $methodCode : self::DOMAIN . ':all',
            'words' => $words,
        ];

        $queueId = $this->admission->admit([
            'class' => self::QUEUE_CLASS,
            'name' => (string) __('支付指南 AI 翻译 %{1} (%{2})', [$localeCode, $label]),
            'module' => 'Weline_Payment',
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
            'method_code' => $methodCode !== '' ? $methodCode : null,
            'missing_only' => $missingOnly,
        ];
    }

    /**
     * @return list<string>
     */
    private function collectMissingPhraseKeys(?string $methodCode, string $locale): array
    {
        $report = $this->catalog->audit($methodCode, $locale);
        $missing = [];

        foreach ((array) ($report['methods'] ?? []) as $methodReport) {
            foreach ((array) ($methodReport['missing'] ?? []) as $phrase) {
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
