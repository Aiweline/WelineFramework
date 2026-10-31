<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Preload;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Localization\ResolvedLanguageCatalogInterface;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\ScopeResolver;
use Weline\Websites\Service\StoreCatalog;

/**
 * Publishes the website directory into process memory at Worker start-up.
 *
 * Everything a storefront request reads about "which site am I serving" is a
 * global fact: the website rows, their domain mounts, the per-website store and
 * channel catalogue, and the locale/currency catalogues. Resolving any of them
 * lazily put a database read on the request path (measured 25–85 ms per cold
 * Worker for the first request), so the whole directory is loaded once here and
 * every later lookup is an in-memory read.
 *
 * This provider owns that warm-up; the framework runtime only schedules it.
 * Registered from etc/module.php:
 *   'worker_preload_provider.Weline_Websites' => WebsiteDirectoryPreloadProvider::class
 */
final class WebsiteDirectoryPreloadProvider extends AbstractWorkerPreloadProvider
{
    public function code(): string
    {
        return 'website_directory';
    }

    public function priority(): int
    {
        return 55;
    }

    /**
     * The website catalogue needs the extension registry (module metadata) to be
     * in memory before the first read, and nothing else.
     */
    public function dependsOn(): array
    {
        return ['extension_registry'];
    }

    public function invalidationKeys(): array
    {
        return ['website', 'website_domain', 'store', 'sales_channel', 'language_catalog', 'currency_catalog'];
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        unset($context);
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        $items = 0;

        // Website/domain rows are the root of everything below.
        WebsiteDomain::fetchCatalogRows();
        $websiteRows = Website::fetchCatalogRows();
        $items += 2;

        // Parser site version — participates in every site-match cache key.
        if (\class_exists(Url::class)) {
            Url::websiteParserSitesVersion();
            $items++;
        }

        // Collect the website ids present in the catalogue. The default website
        // (id 0) is the fallback for unmatched hosts, so it is always included.
        $websiteIds = [0 => true];
        foreach ($websiteRows as $row) {
            $websiteIds[(int)($row['website_id'] ?? 0)] = true;
        }
        $websiteIds = \array_keys($websiteIds);

        // Storefront host used for scope warm-up (configured instance host).
        $defaultHost = \strtolower(\trim((string)(\Weline\Framework\App\Env::get('wls.host') ?? '')));
        if ($defaultHost === '') {
            $defaultHost = 'localhost';
        }

        // Store + channel catalogue.
        //
        // ScopeResolver resolves per website (StoreCatalog::byWebsite($id), cache
        // key "website:{id}") and never calls StoreCatalog::all() (key "all"), so
        // warming the per-website entries is what the request path actually hits.
        $storeCatalog = ObjectManager::getInstance(StoreCatalog::class);
        $channelCatalog = interface_exists(SalesChannelCatalogInterface::class)
            ? ObjectManager::getInstance(SalesChannelCatalogInterface::class)
            : null;

        $storeIds = [];
        foreach ($websiteIds as $websiteId) {
            foreach ((array)$storeCatalog->byWebsite((int)$websiteId) as $store) {
                $storeId = \is_object($store) ? (int)($store->storeId ?? 0) : (int)($store['store_id'] ?? 0);
                if ($storeId >= 0) {
                    $storeIds[$storeId] = true;
                }
            }
            $items++;
        }
        $storeIds = \array_keys($storeIds);

        $channelIdsByStore = [];
        if ($channelCatalog !== null) {
            foreach ($storeIds as $storeId) {
                $channelIds = [];
                foreach ((array)$channelCatalog->byStore((int)$storeId) as $channel) {
                    $channelId = \is_object($channel)
                        ? (int)($channel->id ?? 0)
                        : (int)($channel['channel_id'] ?? 0);
                    if ($channelId >= 0) {
                        $channelIds[] = $channelId;
                    }
                }
                $channelIdsByStore[(int)$storeId] = $channelIds;
                $items++;
            }
        }

        // Locale catalogue: website default plus every store/channel override.
        //
        // StorefrontRenderContextInstaller asks for the resolved language codes on
        // every request; the first lookup per scope walks the query event chain
        // plus a catalogue read.
        if (interface_exists(ResolvedLanguageCatalogInterface::class)) {
            $languageCatalog = ObjectManager::getInstance(ResolvedLanguageCatalogInterface::class);
            foreach ($websiteIds as $websiteId) {
                $languageCatalog->getResolvedLanguageCodes((int)$websiteId);
                $items++;
            }
            foreach ($storeIds as $storeId) {
                $languageCatalog->getResolvedLanguageCodes(0, (int)$storeId);
                foreach ($channelIdsByStore[$storeId] ?? [] as $channelId) {
                    // The request path passes the resolved website, so warm the
                    // triple for every website that owns this store as well.
                    foreach ($websiteIds as $websiteId) {
                        $languageCatalog->getResolvedLanguageCodes((int)$websiteId, (int)$storeId, (int)$channelId);
                    }
                }
                $items++;
            }
        }

        // Currency catalogue per website.
        if (\class_exists(WebsiteData::class)) {
            foreach ($websiteIds as $websiteId) {
                WebsiteData::currencyCodesForWebsite((int)$websiteId);
                $items++;
            }
        }

        // Scope resolution (website → store → channel → route path).
        //
        // ScopeResolver::resolve() runs on every storefront request. Its first
        // call in a Worker measured 5.8 ms: the path-match cache is empty, so it
        // walked the store and channel candidates and rebuilds each entry's
        // canonical URL. Those candidates are catalogue rows this provider has
        // already loaded, so resolving the default storefront path here leaves
        // the path-match process cache populated and turns the request path into
        // a summary lookup.
        if (\class_exists(ScopeResolver::class)) {
            try {
                $resolver = ObjectManager::getInstance(ScopeResolver::class);
                $scheme = 'https';
                $resolver->resolve(0, Website::CODE_DEFAULT, $scheme . '://' . $defaultHost . '/', [], '/');
                $items++;
            } catch (\Throwable) {
                // Warm-up is an optimisation; the request path resolves lazily.
            }
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $this->phase(),
            $items,
            \round((\microtime(true) - $start) * 1000, 2),
            \memory_get_usage(true) - $memoryStart,
            ['websites' => \count($websiteIds), 'stores' => \count($storeIds)],
        );
    }
}
