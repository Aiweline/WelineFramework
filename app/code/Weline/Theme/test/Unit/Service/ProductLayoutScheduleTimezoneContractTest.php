<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DateTime\Timezone;
use Weline\Theme\Service\ProductLayoutScheduleService;

final class ProductLayoutScheduleTimezoneContractTest extends TestCase
{
    public function testSaveConvertsLocalWallClockToUtc(): void
    {
        $utc = Timezone::localInputToUtcSql('2026-11-27T00:00', 'Asia/Shanghai');
        self::assertSame('2026-11-26 16:00:00', $utc);
    }

    public function testResolveActiveComparesUtcNow(): void
    {
        $ref = new \ReflectionClass(ProductLayoutScheduleService::class);
        self::assertTrue($ref->hasMethod('resolveActive'));
        $src = file_get_contents($ref->getFileName() ?: '');
        self::assertIsString($src);
        self::assertStringContainsString('Timezone::SQL_FORMAT', $src);
        self::assertStringContainsString('localInputToUtcSql', $src);
        self::assertStringContainsString("new \\DateTimeZone('UTC')", $src);
    }
}
