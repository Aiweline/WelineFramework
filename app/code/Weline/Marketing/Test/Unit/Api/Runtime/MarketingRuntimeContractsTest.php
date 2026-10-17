<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMarketingRuntimeContractsPass().
 */
final class MarketingRuntimeContractsTest extends TestCase
{
    public function testAllMarketingRuntimeContractsPass(): void
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
            'ScheduleWindowUtcMigrationContribution::testModuleProvidesScheduleWindowUtcMigration' => function () {
            $this->assert_ScheduleWindowUtcMigrationContribution_testModuleProvidesScheduleWindowUtcMigration();
        },
            'ScheduleWindowUtcMigrationContribution::testTargetsCoverCampaignRuleCoupon' => function () {
            $this->assert_ScheduleWindowUtcMigrationContribution_testTargetsCoverCampaignRuleCoupon();
        },
        ];
    }

private function assert_ScheduleWindowUtcMigrationContribution_testModuleProvidesScheduleWindowUtcMigration(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/etc/module.php';
    $provides = $module['provides'] ?? [];
    self::assertArrayHasKey('schedule_window_utc_migration.Weline_Marketing', $provides);
    self::assertSame(\Weline\Marketing\Api\Runtime\ScheduleWindowUtcMigrationContribution::class, $provides['schedule_window_utc_migration.Weline_Marketing']);
}

private function assert_ScheduleWindowUtcMigrationContribution_testTargetsCoverCampaignRuleCoupon(): void
{
    $contribution = new \Weline\Marketing\Api\Runtime\ScheduleWindowUtcMigrationContribution();
    $ids = \array_column($contribution->targets('Asia/Shanghai'), 'id');
    self::assertSame(['marketing_campaign', 'marketing_rule', 'marketing_coupon'], $ids);
}
}
