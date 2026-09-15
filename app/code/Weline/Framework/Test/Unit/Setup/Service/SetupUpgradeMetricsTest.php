<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Setup\Service\SetupUpgradeMetrics;

final class SetupUpgradeMetricsTest extends TestCase
{
    public function testMeasureAccumulatesPhases(): void
    {
        $metrics = new SetupUpgradeMetrics(null);
        $metrics->start('op-test');
        $metrics->measure('phase.a', static function (): void {
            \usleep(20_000);
        });
        $metrics->measure('phase.b', static function (): void {
            \usleep(10_000);
        });

        $phases = $metrics->getPhases();
        self::assertArrayHasKey('phase.a', $phases);
        self::assertArrayHasKey('phase.b', $phases);
        self::assertGreaterThan(0.01, $phases['phase.a']);
        self::assertGreaterThan(0.005, $phases['phase.b']);

        $top = $metrics->topPhases(2);
        self::assertCount(2, $top);
        self::assertSame('phase.a', $top[0]['name']);
    }

    public function testContractSourceContainsOverviewKeys(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Setup/Service/SetupUpgradeMetrics.php'
        );
        self::assertStringContainsString('系统更新耗时概览', $src);
        self::assertStringContainsString('内存峰值', $src);
        self::assertStringContainsString('观测峰值', $src);
        self::assertStringContainsString('getrusage', $src);
        self::assertStringContainsString('阶段 Top', $src);
    }

    public function testUpgradeWiresMetricsOverview(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Setup/Console/Setup/Upgrade.php'
        );
        self::assertStringContainsString('SetupUpgradeMetrics', $src);
        self::assertStringContainsString('printOverview', $src);
    }
}
