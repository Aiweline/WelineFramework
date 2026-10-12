<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Integration\Cart;

use PHPUnit\Framework\TestCase;
/**
 * Product preference for Cart SPI: definition.requiresShipping, no site type hardcode.
 */
final class ProductTypeRequiresShippingResolverContractTest extends TestCase
{
    public function testResolverSourceHasNoTravelRouteHardcode(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Integration/Cart/ProductTypeRequiresShippingResolver.php'
        );
        self::assertStringContainsString('getByType', $src);
        self::assertStringContainsString('getDefinition()', $src);
        self::assertStringNotContainsString("=== 'travel_route'", $src);
        self::assertDoesNotMatchRegularExpression("/['\"]travel_route['\"]/", $src);
    }

    public function testModuleProvidesCartSpiPreference(): void
    {
        $module = include dirname(__DIR__, 4) . '/etc/module.php';
        $provides = is_array($module['provides'] ?? null) ? $module['provides'] : [];
        self::assertSame(
            \Weline\Product\Integration\Cart\ProductTypeRequiresShippingResolver::class,
            $provides[\Weline\Cart\Api\ProductTypeRequiresShippingResolverInterface::class] ?? null,
        );
    }

    public function testCatalogResolverUsesAbsentOfferPath(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php'
        );
        self::assertStringContainsString('resolveRequiresShippingFlag', $src);
        self::assertStringContainsString('RequiresShippingFlag::isAbsent', $src);
        self::assertStringNotContainsString(
            '$requiresShipping = (bool)$offer->getData(Offer::schema_fields_REQUIRES_SHIPPING);',
            $src,
        );
    }
}
