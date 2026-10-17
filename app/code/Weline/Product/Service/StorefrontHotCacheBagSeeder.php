<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Product-owned HotCache bag priming for storefront.cache.builder.
 *
 * wls-perf-regression-20260923 A (architect msg-5):
 * - pre_critical: Shared peek-only for heavy catalog bags (禁冷 publishedOffers(1000))
 * - post_critical_heavy: peek-miss 才冷种；袋间 Fiber yield 分片
 * - locale_bag_prime: light summary + homepage shelf/new-arrivals card HTML（禁 full×locales）
 * - product_card_html: dual flag vectors (shelf full + new-arrivals slim) — 禁单向量全开 flags
 * - other stages: light summary only
 * Fail-open; no Model shortcuts; no fake HIT; no parallel bags.
 */
final class StorefrontHotCacheBagSeeder
{
    /**
     * Layer B: peek Shared product bags into Process L1 only (禁冷 publishedOffers).
     *
     * @return array{
     *   seeded:int,
     *   peeked:int,
     *   bags:list<string>,
     *   errors:list<string>,
     *   heavy_mode?:string,
     *   heavy_deferred?:list<string>
     * }
     */
    public function hydrate(): array
    {
        $peeked = 0;
        $bags = [];
        $errors = [];
        $deferred = [];

        try {
            /** @var StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
        } catch (\Throwable $e) {
            return [
                'seeded' => 0,
                'peeked' => 0,
                'bags' => [],
                'errors' => ['catalog_view:' . $e->getMessage()],
                'heavy_mode' => 'peek_only',
                'heavy_deferred' => [],
            ];
        }

        $proj = $this->hydrateSearchProjectionScope();
        $peeked += (int)($proj['peeked'] ?? 0);
        foreach (\is_array($proj['bags'] ?? null) ? $proj['bags'] : [] as $bag) {
            $bags[] = (string)$bag;
        }
        foreach (\is_array($proj['errors'] ?? null) ? $proj['errors'] : [] as $error) {
            $errors[] = (string)$error;
        }

        $heavy = $this->primeHeavyCatalogBags($catalog, 'peek_only');
        $peeked += (int)($heavy['peeked'] ?? 0);
        foreach (\is_array($heavy['bags'] ?? null) ? $heavy['bags'] : [] as $bag) {
            $bags[] = (string)$bag;
        }
        foreach (\is_array($heavy['errors'] ?? null) ? $heavy['errors'] : [] as $error) {
            $errors[] = (string)$error;
        }
        foreach (\is_array($heavy['deferred'] ?? null) ? $heavy['deferred'] : [] as $bag) {
            $deferred[] = (string)$bag;
        }

        return [
            'seeded' => 0,
            'peeked' => $peeked,
            'bags' => \array_values(\array_unique($bags)),
            'errors' => \array_slice($errors, 0, 8),
            'heavy_mode' => 'peek_only',
            'heavy_deferred' => \array_values(\array_unique($deferred)),
        ];
    }

    /**
     * @return array{
     *   seeded:int,
     *   peeked:int,
     *   bags:list<string>,
     *   errors:list<string>,
     *   heavy_mode?:string,
     *   heavy_deferred?:list<string>
     * }
     */
    public function prime(): array
    {
        $seeded = 0;
        $peeked = 0;
        $bags = [];
        $errors = [];
        $heavyDeferred = [];
        $stage = $this->resolvePrimeStage();
        $heavyMode = $this->resolveHeavyMode($stage);

        try {
            /** @var StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
        } catch (\Throwable $e) {
            return [
                'seeded' => 0,
                'peeked' => 0,
                'bags' => [],
                'errors' => ['catalog_view:' . $e->getMessage()],
                'heavy_mode' => $heavyMode,
                'heavy_deferred' => [],
            ];
        }

        // Light: always re-touch summary so retouch stages keep the bag warm without
        // rebuilding the full 1000-row projection.
        try {
            $rows = $catalog->publishedOfferSummaries(48);
            if (\is_array($rows)) {
                $seeded++;
                $bags[] = 'product.catalog_offers_summary';
            }
        } catch (\Throwable $e) {
            $errors[] = 'catalog_offers_summary:' . $e->getMessage();
        }

        // Channel-scoped search projection (not lang-vary): peek then one seed.
        // Cold-chaos 2026-10-10: empty bag nested under search.query_result ~0.5–1.2s.
        // Allowed on light stages — single bag, not full×locales / publishedOffers(1000).
        if ($stage === 'locale_bag_prime'
            || $stage === 'currency_bag_prime'
            || $stage === 'peer_hydrate'
            || $stage === 'critical'
            || $stage === 'post_critical_heavy'
            || $stage === ''
        ) {
            $proj = $this->primeSearchProjectionScope();
            $seeded += (int)($proj['seeded'] ?? 0);
            $peeked += (int)($proj['peeked'] ?? 0);
            foreach (\is_array($proj['bags'] ?? null) ? $proj['bags'] : [] as $bag) {
                $bags[] = (string)$bag;
            }
            foreach (\is_array($proj['errors'] ?? null) ? $proj['errors'] : [] as $error) {
                $errors[] = (string)$error;
            }
        }

        // locale/currency_bag_prime allowlist: homepage shelf plan + card HTML fragments.
        // Never publishedOffers(1000) here — resolveHeavyMode keeps light_only.
        // peer_hydrate: default-currency Process L1 for shelf plan (owner Shared may miss).
        // locale/currency_bag_prime: vary-dimension shards + product_card_html (v9 identity).
        if ($stage === 'locale_bag_prime'
            || $stage === 'currency_bag_prime'
            || $stage === 'peer_hydrate'
            || $stage === 'critical'
        ) {
            try {
                /** @var StorefrontProductWidgetCatalog $widgetCatalog */
                $widgetCatalog = ObjectManager::getInstance(StorefrontProductWidgetCatalog::class);
                $cards = $widgetCatalog->homepageFeaturedCards(8);
                if (\is_array($cards)) {
                    $seeded++;
                    $bags[] = 'product.homepage_shelf_id_plan';
                }
                // Dual flag vectors (HARD): shelf full-flags ≠ homepage new-arrivals slim.
                // Single shelf-options prime wrote unreachable keys for default homepage SSR.
                $primeBags = $widgetCatalog->homepageFragmentPrimeCardBags(8, 4, 8);
                $shelfCards = \is_array($primeBags['shelf'] ?? null) ? $primeBags['shelf'] : [];
                $newArrivalCards = \is_array($primeBags['new_arrivals'] ?? null)
                    ? $primeBags['new_arrivals']
                    : [];
                if ($shelfCards !== []) {
                    ProductCardRenderer::projectHtmlByProductId(
                        $shelfCards,
                        ProductCardRenderer::homepageShelfFragmentRenderOptions()
                    );
                    $seeded++;
                    $bags[] = 'theme.product_card_html.homepage_shelf';
                }
                if ($newArrivalCards !== []) {
                    ProductCardRenderer::projectHtmlByProductId(
                        $newArrivalCards,
                        ProductCardRenderer::homepageNewArrivalsFragmentRenderOptions()
                    );
                    $seeded++;
                    $bags[] = 'theme.product_card_html.homepage_new_arrivals';
                }
            } catch (\Throwable $e) {
                $errors[] = 'homepage_shelf_id_plan:' . $e->getMessage();
            }
        }

        if ($heavyMode !== 'light_only') {
            $heavyResult = $this->primeHeavyCatalogBags($catalog, $heavyMode);
            $seeded += (int)($heavyResult['seeded'] ?? 0);
            $peeked += (int)($heavyResult['peeked'] ?? 0);
            foreach (\is_array($heavyResult['bags'] ?? null) ? $heavyResult['bags'] : [] as $bag) {
                $bags[] = (string)$bag;
            }
            foreach (\is_array($heavyResult['errors'] ?? null) ? $heavyResult['errors'] : [] as $error) {
                $errors[] = (string)$error;
            }
            foreach (\is_array($heavyResult['deferred'] ?? null) ? $heavyResult['deferred'] : [] as $bag) {
                $heavyDeferred[] = (string)$bag;
            }
        }

        return [
            'seeded' => $seeded,
            'peeked' => $peeked,
            'bags' => \array_values(\array_unique($bags)),
            'errors' => \array_slice($errors, 0, 8),
            'heavy_mode' => $heavyMode,
            'heavy_deferred' => \array_values(\array_unique($heavyDeferred)),
        ];
    }

    /**
     * @return array{seeded:int,peeked:int,bags:list<string>,errors:list<string>,deferred:list<string>}
     */
    private function primeHeavyCatalogBags(StorefrontCatalogViewService $catalog, string $heavyMode): array
    {
        $seeded = 0;
        $peeked = 0;
        $bags = [];
        $errors = [];
        $deferred = [];

        /** @var list<array{bag:string,projection:string,seed:callable():mixed}> $shards */
        $shards = [
            [
                'bag' => 'product.catalog_offers.full',
                'projection' => 'full',
                'seed' => static fn() => $catalog->publishedOffers(1000, true),
            ],
            [
                'bag' => 'product.catalog_offers.summary-card3',
                'projection' => 'summary-card3',
                'seed' => static fn() => $catalog->publishedOffers(1000, false),
            ],
            [
                'bag' => 'product.catalog_offers.candidates',
                'projection' => 'candidates-summary-card3',
                'seed' => static fn() => $catalog->publishedListingCandidates(96, false),
            ],
        ];

        $shardIndex = 0;
        foreach ($shards as $shard) {
            if ($shardIndex > 0 && $heavyMode === 'seed_sharded') {
                // Yield between cold shards so live requests can enter the event loop.
                // P5 O2: yieldDelay (not bare yield) so the scheduler can drain sockets.
                try {
                    SchedulerSystem::yieldDelay(15);
                } catch (\Throwable) {
                }
            }
            $shardIndex++;

            $bag = (string)$shard['bag'];
            $projection = (string)$shard['projection'];
            try {
                if ($this->peekCatalogOffersProjection($projection)) {
                    $peeked++;
                    $bags[] = $bag;
                    continue;
                }
            } catch (\Throwable $e) {
                $errors[] = $bag . ':peek:' . $e->getMessage();
            }

            if ($heavyMode === 'peek_only') {
                // pre_critical: never cold-build 1000-row projections.
                $deferred[] = $bag;
                continue;
            }

            // Yield once before the first cold rebuild so critical_sealed stays responsive.
            if ($shardIndex === 1 && $heavyMode === 'seed_sharded') {
                try {
                    SchedulerSystem::yield();
                } catch (\Throwable) {
                }
            }

            try {
                $rows = ($shard['seed'])();
                if (\is_array($rows)) {
                    $seeded++;
                    $bags[] = $bag;
                }
            } catch (\Throwable $e) {
                $errors[] = $bag . ':' . $e->getMessage();
            }
        }

        return [
            'seeded' => $seeded,
            'peeked' => $peeked,
            'bags' => $bags,
            'errors' => $errors,
            'deferred' => $deferred,
        ];
    }

    /**
     * @return array{seeded:int,peeked:int,bags:list<string>,errors:list<string>}
     */
    private function hydrateSearchProjectionScope(): array
    {
        $seeded = 0;
        $peeked = 0;
        $bags = [];
        $errors = [];

        try {
            $websiteId = $this->resolveWebsiteId();
            $storeId = 0;
            $channelId = 0;
            try {
                $storeId = \max(0, (int)RequestContext::getWelineStoreId());
                $channelId = \max(0, (int)RequestContext::getWelineChannelId());
            } catch (\Throwable) {
            }

            /** @var ProductSearchProjectionService $projection */
            $projection = ObjectManager::getInstance(ProductSearchProjectionService::class);
            $watermark = $projection->currentWatermark($websiteId);
            $logicalKey = StorefrontCatalogCacheCoordinator::searchProjectionScopeLogicalKey(
                $websiteId,
                $storeId,
                $channelId,
                $watermark,
            );

            /** @var StorefrontScopeHotCache $hotCache */
            $hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            $payload = $hotCache->peekPolicy(
                StorefrontCatalogCacheCoordinator::searchProjectionScopePolicy(),
                $logicalKey,
            );
            if (\is_array($payload) && $payload !== []) {
                $peeked++;
                $bags[] = 'product.search_projection_scope';
            }
        } catch (\Throwable $e) {
            $errors[] = 'search_projection_scope:hydrate:' . $e->getMessage();
        }

        return compact('seeded', 'peeked', 'bags', 'errors');
    }

    /**
     * @return array{seeded:int,peeked:int,bags:list<string>,errors:list<string>}
     */
    private function primeSearchProjectionScope(): array
    {
        $seeded = 0;
        $peeked = 0;
        $bags = [];
        $errors = [];

        try {
            $websiteId = $this->resolveWebsiteId();
            $storeId = 0;
            $channelId = 0;
            try {
                $storeId = \max(0, (int)RequestContext::getWelineStoreId());
                $channelId = \max(0, (int)RequestContext::getWelineChannelId());
            } catch (\Throwable) {
            }

            /** @var ProductSearchProjectionService $projection */
            $projection = ObjectManager::getInstance(ProductSearchProjectionService::class);
            $watermark = $projection->currentWatermark($websiteId);
            $logicalKey = StorefrontCatalogCacheCoordinator::searchProjectionScopeLogicalKey(
                $websiteId,
                $storeId,
                $channelId,
                $watermark,
            );

            /** @var StorefrontScopeHotCache $hotCache */
            $hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            $payload = $hotCache->peekPolicy(
                StorefrontCatalogCacheCoordinator::searchProjectionScopePolicy(),
                $logicalKey,
            );
            if (\is_array($payload) && $payload !== []) {
                $peeked++;
                $bags[] = 'product.search_projection_scope';

                return compact('seeded', 'peeked', 'bags', 'errors');
            }

            $snapshot = $projection->snapshotScope($websiteId, $storeId, $channelId);
            if (\is_array($snapshot) && $snapshot !== []) {
                $seeded++;
                $bags[] = 'product.search_projection_scope';
            }
        } catch (\Throwable $e) {
            $errors[] = 'search_projection_scope:' . $e->getMessage();
        }

        return compact('seeded', 'peeked', 'bags', 'errors');
    }

    /**
     * Shared/Process peek only — never invents HIT, never publishes a new entry.
     */
    private function peekCatalogOffersProjection(string $projection): bool
    {
        $websiteId = $this->resolveWebsiteId();
        /** @var StorefrontCatalogCacheCoordinator $catalogCache */
        $catalogCache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);
        /** @var StorefrontScopeHotCache $hotCache */
        $hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);

        $logicalKey = $catalogCache->catalogOffersLogicalKey($websiteId, $projection);
        $payload = $hotCache->peekPolicy(
            StorefrontCatalogCacheCoordinator::catalogOffersPolicy(),
            $logicalKey,
        );

        return \is_array($payload) && $payload !== [];
    }

    private function resolveWebsiteId(): int
    {
        try {
            $scope = RequestContext::scopeIdentity();
            if ($scope instanceof ScopeIdentity && $scope->websiteId !== null) {
                return \max(0, (int)$scope->websiteId);
            }
        } catch (\Throwable) {
        }

        try {
            return \max(0, (int)RequestContext::getWelineWebsiteId());
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolvePrimeStage(): string
    {
        $stage = '';
        try {
            if (RequestContext::isInitialized()
                && RequestContext::has('wls.storefront_hot_cache_bag_prime.stage')
            ) {
                $stage = \strtolower(\trim((string)RequestContext::get(
                    'wls.storefront_hot_cache_bag_prime.stage'
                )));
            }
        } catch (\Throwable) {
            $stage = '';
        }
        if ($stage === '') {
            try {
                $stage = \strtolower(\trim((string)($_SERVER['WLS_PRIME_HOT_CACHE_BAGS_STAGE'] ?? '')));
            } catch (\Throwable) {
                $stage = '';
            }
        }

        return $stage;
    }

    /**
     * Heavy cold rebuild only on post_critical_heavy (after `/`+`/products` seal).
     * locale_bag_prime / peer_hydrate / empty stage must stay light (禁 full×locales).
     */
    private function resolveHeavyMode(string $stage): string
    {
        return match ($stage) {
            'pre_critical' => 'peek_only',
            'post_critical_heavy' => 'seed_sharded',
            'locale_bag_prime' => 'light_only',
            'currency_bag_prime' => 'light_only',
            default => 'light_only',
        };
    }
}
