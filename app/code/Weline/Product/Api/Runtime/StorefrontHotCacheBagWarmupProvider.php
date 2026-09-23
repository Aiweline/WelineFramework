<?php

declare(strict_types=1);

namespace Weline\Product\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\StorefrontHotCacheBagWarmupProviderInterface;
use Weline\Product\Service\StorefrontHotCacheBagSeeder;

/**
 * Product capability for wave8-8c2 deferred storefront.cache.builder bag priming.
 */
final class StorefrontHotCacheBagWarmupProvider implements StorefrontHotCacheBagWarmupProviderInterface
{
    public function primeCriticalBags(): array
    {
        try {
            return ObjectManager::getInstance(StorefrontHotCacheBagSeeder::class)->prime();
        } catch (\Throwable $e) {
            return [
                'seeded' => 0,
                'peeked' => 0,
                'bags' => [],
                'errors' => ['product_bag_warmup:' . $e->getMessage()],
            ];
        }
    }
}
