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
 * - other stages: light summary only
 * Fail-open; no Model shortcuts; no fake HIT; no parallel bags.
 */
final class StorefrontHotCacheBagSeeder
{
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
                'bag' => 'product.catalog_offers.summary-slug2',
                'projection' => 'summary-slug2',
                'seed' => static fn() => $catalog->publishedOffers(1000, false),
            ],
            [
                'bag' => 'product.catalog_offers.candidates',
                'projection' => 'candidates-summary-slug2',
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
     * Empty stage must stay light (peer_hydrate must never thrash catalog.full).
     */
    private function resolveHeavyMode(string $stage): string
    {
        return match ($stage) {
            'pre_critical' => 'peek_only',
            'post_critical_heavy' => 'seed_sharded',
            default => 'light_only',
        };
    }
}
