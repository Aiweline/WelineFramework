<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipPricingService;

final class DropshipPricingServiceTest extends TestCase
{
    public function testSaleFromOriginDefaultThirtyPercent(): void
    {
        $svc = new DropshipPricingService();
        self::assertSame(1300, $svc->saleFromOrigin(1000, 30));
    }

    public function testPriceUpFollowsUplift(): void
    {
        $svc = new DropshipPricingService();
        $r = $svc->applyRemoteOrigin(1000, 1100, 1300, 30, false);
        self::assertSame('up', $r['direction']);
        self::assertSame(1430, $r['sale_minor']);
        self::assertNull($r['tip']);
    }

    public function testPriceDownWritesTipWithoutSaleChange(): void
    {
        $svc = new DropshipPricingService();
        $r = $svc->applyRemoteOrigin(1000, 800, 1300, 30, false);
        self::assertSame('down', $r['direction']);
        self::assertNull($r['sale_minor']);
        self::assertNotNull($r['tip']);
        self::assertStringContainsString('远程降价', (string)$r['tip']);
    }
}
