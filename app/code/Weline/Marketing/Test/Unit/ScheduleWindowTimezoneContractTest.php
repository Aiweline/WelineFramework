<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DateTime\Timezone;

final class ScheduleWindowTimezoneContractTest extends TestCase
{
    public function testRuleIsActiveSourceUsesTimezoneFacade(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/Model/Rule/Rule.php');
        self::assertIsString($src);
        self::assertStringContainsString('Timezone::isWithinUtcWindow', $src);
        self::assertStringNotContainsString("date('Y-m-d H:i:s')", $src);
    }

    public function testCouponIsValidSourceUsesTimezoneFacade(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/Model/Coupon/Coupon.php');
        self::assertIsString($src);
        self::assertStringContainsString('Timezone::isWithinUtcWindow', $src);
        self::assertStringNotContainsString("\$now = date('Y-m-d H:i:s')", $src);
    }

    public function testShanghaiBlackFridayMapsToUtc(): void
    {
        self::assertSame(
            '2026-11-26 16:00:00',
            Timezone::localInputToUtcSql('2026-11-27T00:00', 'Asia/Shanghai'),
        );
        self::assertTrue(Timezone::isWithinUtcWindow(
            '2020-01-01 00:00:00',
            '2099-01-01 00:00:00',
            Timezone::utcNowSql(),
        ));
        self::assertFalse(Timezone::isWithinUtcWindow(
            '2099-01-01 00:00:00',
            null,
            '2026-01-01 00:00:00',
        ));
    }

    public function testCampaignServiceUsesLocalInputToUtc(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/Service/CampaignService.php');
        self::assertIsString($src);
        self::assertStringContainsString('localInputToUtcSql', $src);
    }
}
