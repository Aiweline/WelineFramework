<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

/**
 * 社媒登录客户指南 i18n 编排：审计、列表、AI 翻译入队。
 */
class SocialLoginGuideI18nService
{
    public function __construct(
        private readonly SocialLoginGuideRegistry $guideRegistry,
        private readonly SocialLoginGuideI18nCatalog $catalog,
        private readonly SocialLoginGuideTranslationQueueService $translationQueue,
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

        foreach ($this->guideRegistry->listEntries() as $entry) {
            $providerCode = strtolower(trim((string) ($entry['code'] ?? '')));
            $providerAudit = (array) ($audit['providers'][$providerCode] ?? []);

            $rows[] = array_merge($entry, [
                'i18n_locale' => $locale,
                'i18n_phrase_count' => (int) ($providerAudit['phrase_count'] ?? 0),
                'i18n_missing_count' => (int) ($providerAudit['missing_count'] ?? 0),
                'i18n_complete' => !empty($providerAudit['complete']),
            ]);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(?string $providerCode = null, string $locale = 'en_US'): array
    {
        return $this->catalog->audit($providerCode, $locale);
    }

    /**
     * @return array{queue_id:int,phrase_count:int,locale:string,provider_code:?string,missing_only:bool}
     */
    public function enqueueAiTranslation(
        string $locale,
        ?string $providerCode = null,
        bool $missingOnly = true,
        bool $force = false,
    ): array {
        return $this->translationQueue->enqueue($locale, $providerCode, $missingOnly, $force);
    }
}
