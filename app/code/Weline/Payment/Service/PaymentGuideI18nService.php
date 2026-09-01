<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * 支付客户指南 i18n 编排：审计、列表、AI 翻译入队。
 */
class PaymentGuideI18nService
{
    public function __construct(
        private readonly PaymentCustomerGuideRegistry $guideRegistry,
        private readonly PaymentGuideI18nCatalog $catalog,
        private readonly PaymentGuideTranslationQueueService $translationQueue,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listGuidesWithI18nStatus(string $locale = 'en_US'): array
    {
        $locale = trim($locale) !== '' ? trim($locale) : 'en_US';
        $audit = $this->catalog->audit(null, $locale);
        $rows = [];

        foreach ($this->guideRegistry->listPublishedEntries() as $entry) {
            $methodCode = strtolower(trim((string) ($entry['method_code'] ?? '')));
            $methodAudit = (array) ($audit['methods'][$methodCode] ?? []);

            $rows[] = array_merge($entry, [
                'i18n_locale' => $locale,
                'i18n_phrase_count' => (int) ($methodAudit['phrase_count'] ?? 0),
                'i18n_missing_count' => (int) ($methodAudit['missing_count'] ?? 0),
                'i18n_complete' => !empty($methodAudit['complete']),
            ]);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(?string $methodCode = null, string $locale = 'en_US'): array
    {
        return $this->catalog->audit($methodCode, $locale);
    }

    /**
     * @return array{queue_id:int,phrase_count:int,locale:string,method_code:?string,missing_only:bool}
     */
    public function enqueueAiTranslation(
        string $locale,
        ?string $methodCode = null,
        bool $missingOnly = true,
        bool $force = false,
    ): array {
        return $this->translationQueue->enqueue($locale, $methodCode, $missingOnly, $force);
    }
}
