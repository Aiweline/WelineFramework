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

        self::assertStringContainsString('livePublishedOffersForProduct', $service);
        self::assertStringContainsString('publishedOfferBySlug', $service);
        self::assertStringContainsString("'stock' => (int)", $service);
        self::assertStringContainsString("'sellable' => !empty", $service);
        self::assertStringContainsString('livePublishedOffersForProduct', $catalog);
        self::assertStringContainsString('Bypasses', $catalog);
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
}
