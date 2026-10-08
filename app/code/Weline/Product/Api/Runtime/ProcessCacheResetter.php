<?php

declare(strict_types=1);

namespace Weline\Product\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Product\Service\ProductSearchProjectionService;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontProductDetailProjector;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;

/**
 * Product process-cache reset + MemDiag counters (no Framework FQCN soft-pull).
 */
final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        if (!$context->isExplicitCacheClear() && !$context->aggressive) {
            return 0;
        }

        $cleared = 0;
        ProductSearchProjectionService::clearProcessSnapshotCache();
        $cleared++;
        StorefrontProductMediaUrlResolver::clearProcessReferenceCache();
        $cleared++;

        try {
            $projector = ObjectManager::getInstance(StorefrontProductDetailProjector::class);
            if ($projector instanceof StorefrontProductDetailProjector) {
                $projector->clearProcessDiagCaches();
                $cleared++;
            }
        } catch (\Throwable) {
        }

        try {
            $eav = ObjectManager::getInstance(StorefrontEavLabelResolver::class);
            if ($eav instanceof StorefrontEavLabelResolver) {
                $eav->clearProcessDiagCaches();
                $cleared++;
            }
        } catch (\Throwable) {
        }

        return $cleared;
    }

    public function diagCounts(): array
    {
        $out = [
            'projection_snapshot_keys' => ProductSearchProjectionService::processSnapshotCacheCount(),
            'media_ref_cache' => StorefrontProductMediaUrlResolver::processReferenceCacheCount(),
        ];

        try {
            $projector = ObjectManager::getInstance(StorefrontProductDetailProjector::class);
            if ($projector instanceof StorefrontProductDetailProjector) {
                $out += $projector->diagProcessCacheCounts();
            }
        } catch (\Throwable) {
        }

        try {
            $eav = ObjectManager::getInstance(StorefrontEavLabelResolver::class);
            if ($eav instanceof StorefrontEavLabelResolver) {
                $out += $eav->diagProcessCacheCounts();
            }
        } catch (\Throwable) {
        }

        return $out;
    }
}
