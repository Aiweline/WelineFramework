<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Memory\WorkerMemoryBudgetCalculator;
use Weline\Server\Service\Runtime\RuntimeStrategyResolver;
use Weline\Server\Service\Runtime\WlsRuntimeProfile;

final class WorkerMemoryBudgetCalculatorTest extends TestCase
{
    public function testTwoGigFixtureHardCapAtMostTwo(): void
    {
        $calculator = new WorkerMemoryBudgetCalculator();
        $result = $calculator->calculate(
            cpuBasedCount: 8,
            limitMb: 2048,
            limitSource: 'memtotal',
            workerMemoryLimitMb: 256,
            strategy: 'auto',
            budgetConfig: ['enabled' => true]
        );

        self::assertSame(2, $result['hard_cap']);
        self::assertContains($result['desired'], [1, 2]);
        self::assertLessThanOrEqual(2, $result['desired']);
        self::assertStringContainsString('limit_source=memtotal', $result['reason']);
        self::assertStringContainsString('hard_cap=2', $result['reason']);
    }

    public function testOneGigStillAtLeastOne(): void
    {
        $result = (new WorkerMemoryBudgetCalculator())->calculate(
            4,
            1024,
            'memtotal',
            256,
            'auto',
            ['enabled' => true]
        );
        self::assertSame(1, $result['desired']);
    }

    public function testFourGigCanExceedTwo(): void
    {
        $result = (new WorkerMemoryBudgetCalculator())->calculate(
            8,
            4096,
            'memtotal',
            256,
            'auto',
            ['enabled' => true]
        );
        self::assertGreaterThan(2, $result['desired']);
        self::assertSame(8, $result['desired']);
    }

    public function testRssUsesFullWorkerLimitNotMin256(): void
    {
        $result = (new WorkerMemoryBudgetCalculator())->calculate(
            8,
            4096,
            'memtotal',
            512,
            'auto',
            ['enabled' => true]
        );
        self::assertSame(512, $result['rss_estimate_mb']);
        self::assertLessThanOrEqual(5, $result['desired']);
    }

    public function testHistoricalClampOnLowMem(): void
    {
        $calculator = new WorkerMemoryBudgetCalculator();
        $clamp = $calculator->clampHistoricalCount(3, 2048, ['enabled' => true]);
        self::assertTrue($clamp['clamped']);
        self::assertSame(2, $clamp['count']);
    }

    public function testResolverAutoTwoGigUsesBudget(): void
    {
        $detailed = (new RuntimeStrategyResolver())->resolveWorkerCountDetailed(
            'auto',
            'io',
            'auto',
            new WlsRuntimeProfile([
                'os_family' => 'Linux',
                'cpu_cores' => 4,
                'memory_mb' => 2048,
                'memory_limit_source' => 'memtotal',
            ]),
            256
        );
        self::assertLessThanOrEqual(2, $detailed['count']);
        self::assertSame($detailed['count'], $detailed['budget_ceiling']);
        self::assertStringContainsString('worker_budget', $detailed['reason']);
    }

    public function testExplicitCountNotSilentlyRewritten(): void
    {
        $detailed = (new RuntimeStrategyResolver())->resolveWorkerCountDetailed(
            4,
            'io',
            'auto',
            new WlsRuntimeProfile([
                'os_family' => 'Linux',
                'cpu_cores' => 4,
                'memory_mb' => 2048,
                'memory_limit_source' => 'memtotal',
            ]),
            256
        );
        self::assertSame(4, $detailed['count']);
        self::assertSame('explicit worker count', $detailed['reason']);
    }
}
