<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;

final class ScheduleWindowUtcMigrationContributionContractTest extends TestCase
{
    public function testModuleProvidesScheduleWindowUtcMigration(): void
    {
        $module = require dirname(__DIR__, 4) . '/etc/module.php';
        $provides = $module['provides'] ?? [];
        self::assertArrayHasKey('schedule_window_utc_migration.Weline_Marketing', $provides);
        self::assertSame(
            \Weline\Marketing\Api\Runtime\ScheduleWindowUtcMigrationContribution::class,
            $provides['schedule_window_utc_migration.Weline_Marketing']
        );
    }

    public function testTargetsCoverCampaignRuleCoupon(): void
    {
        $contribution = new \Weline\Marketing\Api\Runtime\ScheduleWindowUtcMigrationContribution();
        $ids = \array_column($contribution->targets('Asia/Shanghai'), 'id');
        self::assertSame(['marketing_campaign', 'marketing_rule', 'marketing_coupon'], $ids);
    }
}
