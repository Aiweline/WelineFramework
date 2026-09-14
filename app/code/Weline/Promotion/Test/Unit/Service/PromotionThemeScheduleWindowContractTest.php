<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DateTime\Timezone;

final class PromotionThemeScheduleWindowContractTest extends TestCase
{
    public function testOutsideUtcWindowRejectedByHelper(): void
    {
        self::assertFalse(Timezone::isWithinUtcWindow(
            '2099-01-01 00:00:00',
            '2099-12-31 00:00:00',
            '2026-01-01 00:00:00',
        ));
        self::assertTrue(Timezone::isWithinUtcWindow(
            '2020-01-01 00:00:00',
            '2099-12-31 00:00:00',
            Timezone::utcNowSql(),
        ));
    }

    public function testActiveDealResolverSourceGuardsWindow(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 3) . '/Service/PromotionStorefrontActiveDealResolver.php',
        );
        self::assertIsString($src);
        self::assertStringContainsString('isWithinUtcWindow', $src);
        self::assertStringContainsString('starts_at', $src);
    }
}
