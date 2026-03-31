<?php

declare(strict_types=1);

namespace WeShop\Store\Service;

use WeShop\Store\Model\Store;
use Weline\Framework\App\State;
use Weline\Websites\Data\WebsiteData;

class StoreContextService
{
    public function __construct(
        private readonly Store $storeModel
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCurrentStore(
        ?int $websiteId = null,
        ?string $locale = null,
        ?string $currency = null
    ): ?array {
        $websiteId ??= $this->resolveWebsiteId();
        $locale = $this->normalizeLocale($locale ?? $this->resolveLocale());
        $currency = $this->normalizeCurrency($currency ?? $this->resolveCurrency());

        $stores = $websiteId > 0
            ? $this->storeModel->getStoresByWebsiteId($websiteId)
            : $this->storeModel->getEnabledStores();

        if ((!is_array($stores) || $stores === []) && $websiteId > 0) {
            $stores = $this->storeModel->getEnabledStores();
        }

        return $this->pickBestStore(is_array($stores) ? $stores : [], $websiteId, $locale, $currency);
    }

    protected function resolveWebsiteId(): int
    {
        return (int) (WebsiteData::getWebsiteId() ?? 0);
    }

    protected function resolveLocale(): string
    {
        try {
            return (string) State::getLangLocal();
        } catch (\Throwable) {
            return '';
        }
    }

    protected function resolveCurrency(): string
    {
        try {
            return (string) State::getCurrency();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array<int, mixed> $stores
     * @return array<string, mixed>|null
     */
    private function pickBestStore(array $stores, int $websiteId, string $locale, string $currency): ?array
    {
        $bestStore = null;
        $bestScore = null;
        $bestSortOrder = null;

        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }

            $score = $this->scoreStore($store, $websiteId, $locale, $currency);
            $sortOrder = (int) ($store[Store::schema_fields_SORT_ORDER] ?? $store['sort_order'] ?? 0);

            if ($bestStore === null || $score > $bestScore || ($score === $bestScore && $sortOrder < $bestSortOrder)) {
                $bestStore = $store;
                $bestScore = $score;
                $bestSortOrder = $sortOrder;
            }
        }

        return $bestStore;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function scoreStore(array $store, int $websiteId, string $locale, string $currency): int
    {
        $score = 0;

        $storeWebsiteId = (int) ($store[Store::schema_fields_WEBSITE_ID] ?? $store['website_id'] ?? 0);
        if ($websiteId > 0 && $storeWebsiteId === $websiteId) {
            $score += 100;
        }

        $storeLocale = $this->normalizeLocale((string) ($store[Store::schema_fields_LOCAL] ?? $store['local'] ?? ''));
        if ($locale !== '') {
            if ($storeLocale === $locale) {
                $score += 40;
            } elseif ($storeLocale !== '' && $this->extractLanguage($storeLocale) === $this->extractLanguage($locale)) {
                $score += 20;
            }
        }

        $storeCurrency = $this->normalizeCurrency((string) ($store[Store::schema_fields_CURRENCY] ?? $store['currency'] ?? ''));
        if ($currency !== '' && $storeCurrency === $currency) {
            $score += 30;
        }

        if ($storeLocale === '') {
            $score += 5;
        }

        if ($storeCurrency === '') {
            $score += 5;
        }

        return $score;
    }

    private function normalizeLocale(string $locale): string
    {
        return strtolower(str_replace('-', '_', trim($locale)));
    }

    private function normalizeCurrency(string $currency): string
    {
        return strtoupper(trim($currency));
    }

    private function extractLanguage(string $locale): string
    {
        $locale = $this->normalizeLocale($locale);
        if ($locale === '') {
            return '';
        }

        return explode('_', $locale)[0] ?? '';
    }
}
