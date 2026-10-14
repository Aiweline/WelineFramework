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

        try {
            /** @var WebsiteCurrency $wc */
            $wc = ObjectManager::getInstance(WebsiteCurrency::class);
            ObjectManager::getInstance(WebsiteCacheInvalidationService::class)
                ->bumpCurrencyCatalogOnly($wc->getConnection(), $websiteId);
        } catch (\Throwable) {
        }
    }
}
