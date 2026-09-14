<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** 默认发货锚点回退：无 is_default 时仍能命中仓绑定/启用地址，避免种子航线被滤光。 */
final class DefaultOriginFallbackContractTest extends TestCase
{
    public function testResolveDefaultOriginFallsBackBeyondIsDefaultFlag(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ServiceLaneMatchService.php'
        );
        self::assertStringContainsString('function resolveDefaultOriginId', $src);
        self::assertStringContainsString('WarehouseShippingOriginInterface', $src);
        self::assertStringContainsString('findShippingAddressId', $src);
        self::assertStringContainsString('schema_fields_IS_ENABLED', $src);
        self::assertStringContainsString('无 is_default', $src);
    }

    public function testSeedPromotesPickedAddressToDefault(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DefaultShippingLaneSeedService.php'
        );
        self::assertStringContainsString('setDefault($pickedId)', $src);
        self::assertStringContainsString('提升为默认', $src);
    }
}
