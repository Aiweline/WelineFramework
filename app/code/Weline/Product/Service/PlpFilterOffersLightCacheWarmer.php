<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Filters\Service\StorefrontFilterPanelService;
use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * Light PLP HotCache seed after cache:clear: filter_panel.v3 + facet_counts +
 * listing candidates / summary offers (and optional one category path).
 *
 * Soft filter (`filteredListingPage`) consumes HotCache
 * `candidates-facet-listing-v5-max1000` — warmer must seed the same key for the
 * shopper tip (zh_Hans_CN×USD) via publishedListingCandidates(1000, true).
 * Never the heavy full-catalog publishedOffers path. Does not touch Theme
 * chrome bags. Fail-open; warm_partial on budget (shopper tip first).
 */
final class PlpFilterOffersLightCacheWarmer implements CacheWarmerInterface
{
    private const BUDGET_MS = 45000;

    private const MEMORY_FLOOR = '512M';

    /** Same bound as soft filteredListingPage → candidates-facet-listing-v5-max1000. */
    private const FACET_LISTING_LIMIT = 1000;

    /** Bounded category tip slice (not the soft facet bag key). */
    private const CATEGORY_TIP_LIMIT = 120;

    private const SUMMARY_LIMIT = 48;

    /**
     * Frozen PLP tip matrix (contracts plp-filter-soft-cold A).
     * Shopper default tip first so BUDGET_MS prefers zh×USD facet seed.
     *
     * @var list<array{lang:string,currency:string}>
     */
    private const TIP_SCOPES = [
        ['lang' => 'zh_Hans_CN', 'currency' => 'USD'],
        ['lang' => 'de_DE', 'currency' => 'USD'],
        ['lang' => 'ja_JP', 'currency' => 'GBP'],
    ];

    public function getName(): string
    {
        return 'product.plp_light';
    }

    public function getTargetPool(): string
    {
        return StorefrontCatalogViewService::cachePool();
    }

    public function getPriority(): int
    {
        // After homepage shelf/offers locale (230).
        return 235;
    }

    public function canWarm(): bool
    {
        return \class_exists(StorefrontCatalogViewService::class)
            && \class_exists(StorefrontScopeHotCache::class);
    }

    public function warm(): array
    {
        $prevMem = (string)\ini_get('memory_limit');
        $this->ensureMemoryFloor();

        $hadRequest = RequestContext::isInitialized();
        if (!$hadRequest) {
            try {
                RequestContext::init();
            } catch (\Throwable $e) {
                $this->restoreMemoryLimit($prevMem);

                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'request_init_fail:' . $e->getMessage(),
                ];
            }
        }

        $seedOk = 0;
        $skipped = 0;
        $remaining = 0;
        $notes = [];
        $started = \hrtime(true);

        try {
            $this->installDefaultChannelIdentity();
            $notes[] = 'tip_scopes=' . \count(self::TIP_SCOPES);
            $notes[] = 'facet_listing_limit=' . self::FACET_LISTING_LIMIT;
            $notes[] = 'facet_listing_details=1';
            $notes[] = 'summary_limit=' . self::SUMMARY_LIMIT;

            /** @var StorefrontCacheKeyContextResolver $resolver */
            $resolver = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class);
            /** @var StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
            $panelService = $this->resolveFilterPanelService();
            $categoryTip = $this->resolveCategoryTipPath();
            if ($categoryTip !== null) {
                $notes[] = 'category_tip=' . $categoryTip['listing_url'];
            } else {
                $notes[] = 'category_tip=none';
            }

            foreach (self::TIP_SCOPES as $idx => $scope) {
                $elapsedMs = (\hrtime(true) - $started) / 1e6;
                if ($elapsedMs >= self::BUDGET_MS) {
                    $remaining = \count(self::TIP_SCOPES) - $idx;
                    $notes[] = 'budget_ms=' . self::BUDGET_MS;
                    $notes[] = 'remaining=' . $remaining;
                    break;
                }

                $lang = $scope['lang'];
                $currency = $scope['currency'];
                try {
                    RequestContext::setWelineUserLang($lang);
                    RequestContext::setWelineUserCurrency($currency);
                    RequestContext::remove('framework.cache.storefront_key_context.v2');
                    RequestContext::remove('product.catalog.offers.request');
                    RequestContext::remove('product.catalog.summary.bag.request');
                    RequestContext::remove('storefront.filters.panel');

                    if ($resolver instanceof StorefrontCacheKeyContextResolver) {
                        $ctx = $resolver->freezeCurrent();
                        if (!$ctx->cacheable) {
                            ++$skipped;
                            $notes[] = 'freeze_fail:' . $lang . ':' . ($ctx->failureCode ?: 'unknown');
                            continue;
                        }
                    }

                    // Soft-same key: candidates-facet-listing-v5-max1000 (not summary/max120).
                    $offers = $catalog->publishedListingCandidates(self::FACET_LISTING_LIMIT, true);
                    if (!\is_array($offers) || $offers === []) {
                        ++$skipped;
                        $notes[] = 'empty_facet_listing:' . $lang;
                        continue;
                    }
                    ++$seedOk;
                    $notes[] = 'facet_seeded:' . $lang . ':' . $currency;

                    $summaries = $catalog->publishedOfferSummaries(self::SUMMARY_LIMIT);
                    if (\is_array($summaries) && $summaries !== []) {
                        ++$seedOk;
                    }

                    if ($panelService instanceof StorefrontFilterPanelService) {
                        $websiteId = Website::ID_DEFAULT;
                        $panel = $panelService->buildPanel(
                            $offers,
                            '/products',
                            [],
                            $websiteId,
                            true,
                        );
                        if (\is_array($panel) && $panel !== []) {
                            ++$seedOk;
                            $codes = $this->attributeCodesFromPanel($panel);
                            if ($codes !== []) {
                                $productIds = $this->productIdsFromOffers($offers);
                                $counts = $catalog->facetCountsForProductIds($productIds, $codes, $offers);
                                if (\is_array($counts) && $counts !== []) {
                                    ++$seedOk;
                                }
                            }
                        }
                    } else {
                        $notes[] = 'filters_unavailable';
                    }

                    if ($categoryTip !== null && $panelService instanceof StorefrontFilterPanelService) {
                        $catOffers = $catalog->publishedOffersForProductIds(
                            $categoryTip['product_ids'],
                            self::CATEGORY_TIP_LIMIT,
                            false,
                        );
                        if (\is_array($catOffers) && $catOffers !== []) {
                            ++$seedOk;
                            $catPanel = $panelService->buildPanel(
                                $catOffers,
                                $categoryTip['listing_url'],
                                [],
                                Website::ID_DEFAULT,
                                false,
                            );
                            if (\is_array($catPanel) && $catPanel !== []) {
                                ++$seedOk;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    ++$skipped;
                    $notes[] = 'error:' . $lang . ':' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            ++$skipped;
            $notes[] = 'error:' . $e->getMessage();
        } finally {
            try {
                RequestContext::resetWelineVars();
            } catch (\Throwable) {
            }
            $this->restoreMemoryLimit($prevMem);
        }

        if ($remaining > 0) {
            ++$skipped;
            $notes[] = 'partial=1';
        }
        $notes[] = 'seed_ok=' . $seedOk;

        return [
            'warmed' => \max(0, $seedOk),
            'skipped' => $skipped,
            'message' => \implode(';', $notes) ?: 'ok',
        ];
    }

    private function resolveFilterPanelService(): ?StorefrontFilterPanelService
    {
        if (!\class_exists(StorefrontFilterPanelService::class)) {
            return null;
        }
        try {
            $svc = ObjectManager::getInstance(StorefrontFilterPanelService::class);

            return $svc instanceof StorefrontFilterPanelService ? $svc : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{listing_url:string,product_ids:list<int>}|null
     */
    private function resolveCategoryTipPath(): ?array
    {
        try {
            /** @var StorefrontCategoryTreeIndex $tree */
            $tree = ObjectManager::getInstance(StorefrontCategoryTreeIndex::class);
            $children = $tree->childrenOf(Website::ID_DEFAULT, 0);
            foreach ($children as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $path = \trim(\str_replace('\\', '/', (string)($row['path'] ?? '')), '/');
                $id = (int)($row['id'] ?? 0);
                if ($path === '' || $id <= 0) {
                    continue;
                }
                /** @var \Weline\Product\Repository\CategoryLinkRepository $links */
                $links = ObjectManager::getInstance(\Weline\Product\Repository\CategoryLinkRepository::class);
                $productIds = [];
                foreach ($links->listByCategoryIds(Website::ID_DEFAULT, [$id]) as $link) {
                    $pid = (int)($link['product_id'] ?? 0);
                    if ($pid > 0) {
                        $productIds[] = $pid;
                    }
                }
                $productIds = \array_values(\array_unique($productIds));
                if ($productIds === []) {
                    continue;
                }

                return [
                    'listing_url' => '/category/' . $path,
                    'product_ids' => \array_slice($productIds, 0, self::CATEGORY_TIP_LIMIT),
                ];
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $panel
     * @return array<string, string>
     */
    private function attributeCodesFromPanel(array $panel): array
    {
        $codes = [];
        $groups = $panel['attributes'] ?? [];
        if (!\is_array($groups)) {
            return [];
        }
        foreach ($groups as $group) {
            if (!\is_array($group)) {
                continue;
            }
            $code = \strtolower(\trim((string)($group['code'] ?? '')));
            if ($code === '' || \str_starts_with($code, 'source_')) {
                continue;
            }
            $codes[$code] = (string)($group['name'] ?? $code);
        }

        return $codes;
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return list<int>
     */
    private function productIdsFromOffers(array $offers): array
    {
        $ids = [];
        foreach ($offers as $offer) {
            if (!\is_array($offer)) {
                continue;
            }
            $id = (int)($offer['product_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return \array_values(\array_unique($ids));
    }

    private function ensureMemoryFloor(): void
    {
        $current = \trim((string)\ini_get('memory_limit'));
        if ($current === '' || $current === '-1') {
            return;
        }
        if (!\preg_match('/^(\d+)([KMG]?)$/i', $current, $m)) {
            @\ini_set('memory_limit', self::MEMORY_FLOOR);

            return;
        }
        $number = (float)$m[1];
        $unit = \strtoupper($m[2] !== '' ? $m[2] : 'B');
        $bytes = match ($unit) {
            'G' => (int)($number * 1024 * 1024 * 1024),
            'M' => (int)($number * 1024 * 1024),
            'K' => (int)($number * 1024),
            default => (int)$number,
        };
        if ($bytes > 0 && $bytes < 512 * 1024 * 1024) {
            @\ini_set('memory_limit', self::MEMORY_FLOOR);
        }
    }

    private function restoreMemoryLimit(string $prevMem): void
    {
        if ($prevMem !== '') {
            @\ini_set('memory_limit', $prevMem);
        }
    }

    private function installDefaultChannelIdentity(): void
    {
        $storeMode = Store::MODE_NORMAL;
        $identity = ScopeIdentity::channel(
            Website::ID_DEFAULT,
            Website::CODE_DEFAULT,
            Store::CODE_DEFAULT,
            SalesChannel::CODE_DEFAULT,
            $storeMode,
        );
        RequestContext::setWelineWebsiteId(Website::ID_DEFAULT);
        RequestContext::setWelineWebsiteCode(Website::CODE_DEFAULT);
        RequestContext::setWelineStoreId(0);
        RequestContext::setWelineStoreCode(Store::CODE_DEFAULT);
        RequestContext::setWelineStoreMode($storeMode);
        RequestContext::setWelineChannelId(0);
        RequestContext::setWelineChannelCode(SalesChannel::CODE_DEFAULT);
        RequestContext::installScopeIdentity($identity);
    }
}
