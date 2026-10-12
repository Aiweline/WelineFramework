<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\ProductTypeRequiresShippingResolverInterface;
use Weline\Cart\Service\CheckoutCartSnapshotService;
use Weline\Cart\Service\LegacyProductTypeRequiresShippingResolver;
use Weline\Cart\Service\RequiresShippingFlag;

/**
 * S3 harden: null Offer/snapshot flag → type SPI; no travel_route hardcode in Cart core.
 */
final class ProductTypeRequiresShippingFallbackContractTest extends TestCase
{
    public function testLegacyResolverOnlyKnowsBuiltinDigitalTypes(): void
    {
        $r = new LegacyProductTypeRequiresShippingResolver();
        self::assertFalse($r->requiresShippingForProductType('virtual'));
        self::assertFalse($r->requiresShippingForProductType('digital'));
        self::assertFalse($r->requiresShippingForProductType('downloadable'));
        self::assertNull($r->requiresShippingForProductType('simple'));
        self::assertNull($r->requiresShippingForProductType('travel_route'));
    }

    /**
     * Cart must NOT module-provide the type SPI when Product Integration does —
     * dual provides blow ServiceProviderRegistry and surface as「请求边界清理失败」.
     */
    public function testCartModuleDoesNotProvideTypeRequiresShippingSpi(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        $provides = is_array($module['provides'] ?? null) ? $module['provides'] : [];
        self::assertArrayNotHasKey(
            ProductTypeRequiresShippingResolverInterface::class,
            $provides,
        );
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/module.php');
        self::assertStringNotContainsString(
            'LegacyProductTypeRequiresShippingResolver::class',
            $src,
        );
    }

    public function testRequiresShippingFlagAbsentAndCoerce(): void
    {
        self::assertTrue(RequiresShippingFlag::isAbsent(null));
        self::assertTrue(RequiresShippingFlag::isAbsent(''));
        self::assertFalse(RequiresShippingFlag::isAbsent(0));
        self::assertFalse(RequiresShippingFlag::coercePresent(0));
        self::assertFalse(RequiresShippingFlag::coercePresent('0'));
        self::assertTrue(RequiresShippingFlag::coercePresent(1));
        self::assertTrue(RequiresShippingFlag::coercePresent('1'));
    }

    public function testFreezeUsesTypeResolverWhenSnapshotFlagNull(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutCartSnapshotService.php'
        );
        self::assertStringContainsString('ProductTypeRequiresShippingResolverInterface', $src);
        self::assertStringContainsString('resolveRequiresShipping', $src);
        self::assertStringNotContainsString("product_type === 'travel_route'", $src);
        self::assertStringNotContainsString("'travel_route'", $src);
        self::assertStringNotContainsString(
            "!in_array(strtolower(\$snapshot->productType), ['virtual', 'digital', 'downloadable'], true)",
            $src,
        );
    }

    public function testResolveViaInjectedTypeSpi(): void
    {
        $typeSpi = new class implements ProductTypeRequiresShippingResolverInterface {
            public function requiresShippingForProductType(string $productType): ?bool
            {
                return strtolower($productType) === 'travel_route' ? false : null;
            }
        };

        $method = new \ReflectionMethod(CheckoutCartSnapshotService::class, 'resolveRequiresShipping');
        $method->setAccessible(true);
        $ref = new \ReflectionClass(CheckoutCartSnapshotService::class);
        $obj = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('typeRequiresShipping');
        $prop->setAccessible(true);
        $prop->setValue($obj, $typeSpi);

        self::assertTrue($method->invoke($obj, true, 'travel_route'));
        self::assertFalse($method->invoke($obj, false, 'simple'));
        self::assertFalse($method->invoke($obj, null, 'travel_route'));
        self::assertTrue($method->invoke($obj, null, 'simple'));
    }
}
