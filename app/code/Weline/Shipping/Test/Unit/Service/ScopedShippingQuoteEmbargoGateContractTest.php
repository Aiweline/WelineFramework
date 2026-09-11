<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 报价 SPI：实体配送路径必须经 EmbargoService 硬门（源码契约）。
 */
final class ScopedShippingQuoteEmbargoGateContractTest extends TestCase
{
    public function testQuoteAndListOptionsAssertDestinationAllowed(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ScopedShippingQuoteService.php',
        );
        self::assertStringContainsString('public const ERROR_EMBARGO = \'shipping_destination_embargoed\';', $src);
        self::assertStringContainsString('private function assertDestinationAllowed(ShippingQuoteRequest $request): void', $src);
        self::assertStringContainsString('EmbargoService::class', $src);
        self::assertStringContainsString('assertAllowed($request->address', $src);
        self::assertMatchesRegularExpression(
            '/if \(\$this->shippableCount\(\$request\) === 0\) \{\s*return \[\];\s*\}\s*\$this->assertDestinationAllowed\(\$request\);/s',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/freeReason:\s*\'virtual_only\'[\s\S]*?\$this->assertDestinationAllowed\(\$request\);/',
            $src,
        );
        self::assertStringContainsString('if ($this->useMemory || ShippingQuoteHarnessCatalog::load() !== null)', $src);
    }
}
