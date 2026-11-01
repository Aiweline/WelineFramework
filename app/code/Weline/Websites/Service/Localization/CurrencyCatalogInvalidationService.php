<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Localization;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Service\WebsiteCacheInvalidationService;

/**
 * Soft invalidation after currency *order* changes.
 *
 * Refreshes currency catalog caches (WebsiteData + website/{code}/currency namespace).
 * Does NOT emit website-level w_changed and does NOT run legacy full cacheClear / FPC wipe.
 */
final class CurrencyCatalogInvalidationService
{
    public function invalidateAfterReorder(int $websiteId): void
    {
        $websiteId = \max(0, $websiteId);

        try {
            WebsiteData::currencyCodesForWebsite($websiteId, true);
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(\Weline\Currency\Taglib\CurrencySelect::class)) {
                \Weline\Currency\Taglib\CurrencySelect::clearProcessCaches();
            }
        } catch (\Throwable) {
        }

        // Same gap as the language side: State's allowed-currency map lives for the
        // whole process with no TTL, and only a cache_clear broadcast cleared it.
        // A plain catalog save therefore left running workers rejecting a currency
        // that was just enabled (or still accepting a removed one).
        //
        // Clear the registry memo first so the rebuilt map does not read stale
        // catalogue answers again.
        try {
            ObjectManager::getInstance(
                \Weline\Framework\App\Localization\LocalizationProviderRegistry::class
            )->clearCaches();
        } catch (\Throwable) {
        }

        try {
            \Weline\Framework\App\State::clearProcessLocalizationCaches();
        } catch (\Throwable) {
        }

        // Third holder of the same fact (B2): the storefront render-context snapshot
        // embeds language/currency per scope triple; its reset method had no caller.
        try {
            \Weline\Framework\Runtime\StorefrontRenderContextInstaller::clearProcessCache();
        } catch (\Throwable) {
        }

        try {
            /** @var WebsiteCurrency $wc */
            $wc = ObjectManager::getInstance(WebsiteCurrency::class);
            ObjectManager::getInstance(WebsiteCacheInvalidationService::class)
                ->bumpCurrencyCatalogOnly($wc->getConnection(), $websiteId);
        } catch (\Throwable) {
        }
    }
}
