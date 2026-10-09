<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class StorefrontCatalogProductVisibilityContractTest extends TestCase
{
    public function testProductIdGateUsesCatalogPublishedOffers(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/StorefrontCatalogProductVisibility.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function filterSellableProductIds(', $source);
        self::assertStringContainsString('function isProductSellable(', $source);
        self::assertStringContainsString('publishedOffersForProductIds(', $source);
        self::assertStringContainsString('StorefrontCatalogVisibilityFilterInterface', $source);
    }
}
