<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

final class FilterPanelProcessL1PolicyContractTest extends TestCase
{
    public function testFilterPanelAndFacetCountsSkipProcessL1(): void
    {
        self::assertFalse(StorefrontCatalogCacheCoordinator::filterPanelPolicy()->storeProcessL1);
        self::assertFalse(StorefrontCatalogCacheCoordinator::catalogFacetCountsPolicy()->storeProcessL1);
    }
}
