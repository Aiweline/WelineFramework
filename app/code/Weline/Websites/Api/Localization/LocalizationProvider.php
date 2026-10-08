<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Localization;

use Weline\Framework\App\Localization\LocalizationProviderInterface;
use Weline\Framework\Env\WelineEnv;
use Weline\Websites\Data\WebsiteData;

/**
 * Website localization codes — always via WebsiteData snapshots
 * (request → process → shared → DB once). No parallel model queries.
 */
final class LocalizationProvider implements LocalizationProviderInterface
{
    public function priority(): int
    {
        return 100;
    }

    public function languageCodes(): array
    {
        $websiteId = $this->websiteId();
        if ($websiteId === null) {
            return WebsiteData::hasLanguageSnapshot()
                ? WebsiteData::getLanguageCodes()
                : [];
        }

        return WebsiteData::languageCodesForWebsite($websiteId);
    }

    public function currencyCodes(): array
    {
        $websiteId = $this->websiteId();
        if ($websiteId === null) {
            return WebsiteData::hasCurrencySnapshot()
                ? WebsiteData::getCurrencyCodes()
                : [];
        }

        return WebsiteData::currencyCodesForWebsite($websiteId);
    }

    public function defaultLanguage(): ?string
    {
        $websiteId = $this->websiteId();
        if ($websiteId === null) {
            $code = \trim((string)(WebsiteData::getDefaultLanguage() ?? ''));

            return $code !== '' ? $code : null;
        }

        return WebsiteData::defaultLanguageForWebsite($websiteId);
    }

    public function defaultCurrency(): ?string
    {
        $websiteId = $this->websiteId();
        if ($websiteId === null) {
            $code = \strtoupper(\trim((string)(WebsiteData::getDefaultCurrency() ?? '')));

            return $code !== '' ? $code : null;
        }

        return WebsiteData::defaultCurrencyForWebsite($websiteId);
    }

    public function supportsLanguage(string $code): ?bool
    {
        return null;
    }

    public function supportsCurrency(string $code): ?bool
    {
        return null;
    }

    public static function clearProcessCache(): void
    {
        WebsiteData::clearProcessCache();
    }

    private function websiteId(): ?int
    {
        try {
            $id = WebsiteData::getWebsiteId();
            if ($id !== null) {
                return (int)$id;
            }
        } catch (\Throwable) {
        }

        $raw = w_env('website_id', null);
        if ($raw !== null && $raw !== '') {
            return (int)$raw;
        }

        $serverId = WelineEnv::server('WELINE_WEBSITE_ID', null);
        if ($serverId !== null && $serverId !== '') {
            return (int)$serverId;
        }

        return null;
    }
}
