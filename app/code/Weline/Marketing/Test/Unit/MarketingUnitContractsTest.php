<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DateTime\Timezone;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMarketingUnitContractsPass().
 */
final class MarketingUnitContractsTest extends TestCase
{
    public function testAllMarketingUnitContractsPass(): void
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
            'ScheduleWindowTimezone::testRuleIsActiveSourceUsesTimezoneFacade' => function () {
            $this->assert_ScheduleWindowTimezone_testRuleIsActiveSourceUsesTimezoneFacade();
        },
            'ScheduleWindowTimezone::testCouponIsValidSourceUsesTimezoneFacade' => function () {
            $this->assert_ScheduleWindowTimezone_testCouponIsValidSourceUsesTimezoneFacade();
        },
            'ScheduleWindowTimezone::testShanghaiBlackFridayMapsToUtc' => function () {
            $this->assert_ScheduleWindowTimezone_testShanghaiBlackFridayMapsToUtc();
        },
            'ScheduleWindowTimezone::testCampaignServiceUsesLocalInputToUtc' => function () {
            $this->assert_ScheduleWindowTimezone_testCampaignServiceUsesLocalInputToUtc();
        },
        ];
    }

private function assert_ScheduleWindowTimezone_testRuleIsActiveSourceUsesTimezoneFacade(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Model/Rule/Rule.php');
    self::assertIsString($src);
    self::assertStringContainsString('Timezone::isWithinUtcWindow', $src);
    self::assertStringNotContainsString("date('Y-m-d H:i:s')", $src);
}

private function assert_ScheduleWindowTimezone_testCouponIsValidSourceUsesTimezoneFacade(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Model/Coupon/Coupon.php');
    self::assertIsString($src);
    self::assertStringContainsString('Timezone::isWithinUtcWindow', $src);
    self::assertStringNotContainsString("\$now = date('Y-m-d H:i:s')", $src);
}

private function assert_ScheduleWindowTimezone_testShanghaiBlackFridayMapsToUtc(): void
{
    self::assertSame('2026-11-26 16:00:00', Timezone::localInputToUtcSql('2026-11-27T00:00', 'Asia/Shanghai'));
    self::assertTrue(Timezone::isWithinUtcWindow('2020-01-01 00:00:00', '2099-01-01 00:00:00', Timezone::utcNowSql()));
    self::assertFalse(Timezone::isWithinUtcWindow('2099-01-01 00:00:00', null, '2026-01-01 00:00:00'));
}

private function assert_ScheduleWindowTimezone_testCampaignServiceUsesLocalInputToUtc(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Service/CampaignService.php');
    self::assertIsString($src);
    self::assertStringContainsString('localInputToUtcSql', $src);
}
}
