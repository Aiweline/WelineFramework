<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\StorefrontHotCacheBagWarmupProviderInterface;
use Weline\Theme\Service\StorefrontHotCacheBagSeeder;

/**
 * Theme capability for wave8-8c deferred HotCache bag priming (header/chrome).
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
}
