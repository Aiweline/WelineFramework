<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductDeliveryModeLightOriginContractTest extends TestCase
{
    public function testDeliveryModeWidgetUsesDefaultOriginNotPlanPackages(): void
    {
        $widget = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-delivery-mode.phtml',
        );
        $service = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontOfferOriginCountryService.php',
        );

        self::assertStringContainsString('@widget.cache {300}', $widget);
        self::assertStringNotContainsString('@widget.cache_ttl', $widget);
        self::assertStringContainsString('resolveDefaultOriginCountry', $widget);
        self::assertStringNotContainsString('resolveForOffer(', $widget);

        self::assertStringContainsString('function resolveDefaultOriginCountry', $service);
        self::assertStringContainsString('defaultWarehouseId', $service);
        self::assertStringNotContainsString(
            'planPackages($websiteId, $storeId',
            substr(
                $service,
                (int)strpos($service, 'function resolveDefaultOriginCountry'),
                800,
            ),
        );
    }
}
