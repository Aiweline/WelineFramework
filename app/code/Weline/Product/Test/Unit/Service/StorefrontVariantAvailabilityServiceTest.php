<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class StorefrontVariantAvailabilityServiceTest extends TestCase
{
    public function testServiceProjectsLiveAvailabilitySliceFromCatalog(): void
    {
        $service = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontVariantAvailabilityService.php',
        );
        $catalog = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontCatalogViewService.php',
        );

        self::assertStringContainsString('liveVariantAvailabilityForProduct', $service);
        self::assertStringNotContainsString('livePublishedOffersForProduct', $service);
        self::assertStringContainsString('publishedOfferBySlug', $service);
        self::assertStringContainsString("'stock' => (int)", $service);
        self::assertStringContainsString("'sellable' => !empty", $service);
        self::assertStringContainsString('liveVariantAvailabilityForProduct', $catalog);
        self::assertStringContainsString('resolveCatalogOffers', $catalog);
    }

    public function testVariantAvailabilityApiIsNoStoreJson(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Api/VariantAvailability.php',
        );

        self::assertStringContainsString("Cache-Control', 'no-store'", $controller);
        self::assertStringContainsString('StorefrontVariantAvailabilityService', $controller);
        self::assertStringContainsString("'success' => true", $controller);
    }

    public function testVariantAvailabilityUsesLightweightCatalogSnapshot(): void
    {
        $service = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontVariantAvailabilityService.php',
        );

        self::assertStringContainsString('liveVariantAvailabilityForProduct', $service);
        self::assertStringNotContainsString('livePublishedOffersForProduct', $service);

        $resolver = (string)file_get_contents(
            BP . 'app/code/Weline/Product/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php',
        );
        self::assertStringContainsString('bool $includeMedia = true', $resolver);
        self::assertStringContainsString('bool $includeOptions = true', $resolver);
        self::assertStringContainsString('if ($includeOptions)', $resolver);
    }
}
