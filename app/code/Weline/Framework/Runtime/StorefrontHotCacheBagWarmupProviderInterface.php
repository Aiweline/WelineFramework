<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional storefront HotCache bag primer for deferred warmup (wave8-8c).
 *
 * Theme (header/chrome/slot projection) and other owning modules register via
 * CAPABILITY_PREFIX. Runtime drains PostResponse then calls every provider so
 * Process/Shared bags exist before the first public hit — not FPC HTML alone.
 *
 * Fail-open; never invent FPC HIT; never clear shared FPC; no cross-module Model.
 */
interface StorefrontHotCacheBagWarmupProviderInterface
{
    public const CAPABILITY_PREFIX = 'storefront_hot_cache_bag_warmup.';

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
}
