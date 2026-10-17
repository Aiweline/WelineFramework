<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional storefront HotCache bag primer / Process L1 hydrate (wave8-8c + Layer B).
 *
 * Theme (header/chrome/slot projection) and other owning modules register via
 * CAPABILITY_PREFIX. Layer A (Shared) is Cron/Clear; Layer B (Process L1) is
 * hydrateCriticalBagsFromShared — peek Shared into this Worker's process bag
 * without cold SSR/seed. primeCriticalBags may seed when Shared is empty.
 *
 * Fail-open; never invent FPC HIT; never clear shared FPC; no cross-module Model.
 */
interface StorefrontHotCacheBagWarmupProviderInterface
{
    public const CAPABILITY_PREFIX = 'storefront_hot_cache_bag_warmup.';

    /** Seed or peek-hydrate critical bags (may cold-build on Shared miss). */
    public const MODE_PRIME = 'prime';

    /** Peek Shared → Process L1 only; miss must not seed/SSR. */
    public const MODE_HYDRATE = 'hydrate';

    /**
     * Seed or peek-hydrate critical header/builder-related HotCache bags.
     *
     * @return array{
     *   seeded:int,
     *   peeked:int,
     *   bags:list<string>,
     *   errors:list<string>
     * }
     */
    public function primeCriticalBags(): array;

    /**
     * Layer B: fill Process L1 from Shared only.
     *
     * MUST NOT cold-seed / SSR / invent HIT when Shared misses.
     *
     * @return array{
     *   seeded:int,
     *   peeked:int,
     *   bags:list<string>,
     *   errors:list<string>
     * }
     */
    public function hydrateCriticalBagsFromShared(): array;
}
