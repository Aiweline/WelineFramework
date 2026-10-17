<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\StorefrontHotCacheBagWarmupProviderInterface;
use Weline\Theme\Service\StorefrontHotCacheBagSeeder;

/**
 * Theme capability for wave8-8c bag priming + Layer B Process L1 hydrate (header/chrome).
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
                'errors' => ['theme_bag_warmup:' . $e->getMessage()],
            ];
        }
    }

    public function hydrateCriticalBagsFromShared(): array
    {
        try {
            return ObjectManager::getInstance(StorefrontHotCacheBagSeeder::class)->hydrate();
        } catch (\Throwable $e) {
            return [
                'seeded' => 0,
                'peeked' => 0,
                'bags' => [],
                'errors' => ['theme_bag_hydrate:' . $e->getMessage()],
            ];
        }
    }
}
