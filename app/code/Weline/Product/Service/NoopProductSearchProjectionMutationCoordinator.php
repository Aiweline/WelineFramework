<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;

/**
 * Explicit test seam for isolated repository tests without Framework events.
 */
final class NoopProductSearchProjectionMutationCoordinator implements ProductSearchProjectionMutationCoordinatorInterface
{
    public function execute(
        ConnectionFactory $connection,
        int $websiteId,
        string $targetType,
        int $targetId,
        ?int $storeId,
        callable $mutation,
    ): mixed {
        return $mutation();
    }
}
