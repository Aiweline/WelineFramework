<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Integration\Cart;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductCartContractsPass().
 */
final class ProductCartContractsTest extends TestCase
{
    public function testAllProductCartContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ProductTypeRequiresShippingResolver::testResolverSourceHasNoTravelRouteHardcode' => function () {
            $this->assert_ProductTypeRequiresShippingResolver_testResolverSourceHasNoTravelRouteHardcode();
        },
            'ProductTypeRequiresShippingResolver::testModuleProvidesCartSpiPreference' => function () {
            $this->assert_ProductTypeRequiresShippingResolver_testModuleProvidesCartSpiPreference();
        },
            'ProductTypeRequiresShippingResolver::testCatalogResolverUsesAbsentOfferPath' => function () {
            $this->assert_ProductTypeRequiresShippingResolver_testCatalogResolverUsesAbsentOfferPath();
        },
        ];
    }

private function assert_ProductTypeRequiresShippingResolver_testResolverSourceHasNoTravelRouteHardcode(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Integration/Cart/ProductTypeRequiresShippingResolver.php');
    self::assertStringContainsString('getByType', $src);
    self::assertStringContainsString('getDefinition()', $src);
    self::assertStringNotContainsString("=== 'travel_route'", $src);
    self::assertDoesNotMatchRegularExpression("/['\"]travel_route['\"]/", $src);
}

private function assert_ProductTypeRequiresShippingResolver_testModuleProvidesCartSpiPreference(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/etc/module.php';
    $provides = is_array($module['provides'] ?? null) ? $module['provides'] : [];
    self::assertSame(\Weline\Product\Integration\Cart\ProductTypeRequiresShippingResolver::class, $provides[\Weline\Cart\Api\ProductTypeRequiresShippingResolverInterface::class] ?? null);
}

private function assert_ProductTypeRequiresShippingResolver_testCatalogResolverUsesAbsentOfferPath(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php');
    self::assertStringContainsString('resolveRequiresShippingFlag', $src);
    self::assertStringContainsString('RequiresShippingFlag::isAbsent', $src);
    self::assertStringNotContainsString('$requiresShipping = (bool)$offer->getData(Offer::schema_fields_REQUIRES_SHIPPING);', $src);
}
}
