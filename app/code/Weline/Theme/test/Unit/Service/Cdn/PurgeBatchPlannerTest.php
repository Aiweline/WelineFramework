<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Service\Cdn;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Cdn\CdnPurgeAdapterInterface;
use Weline\Theme\Service\Cdn\PurgeBatchPlanner;

/**
 * 分批 purge 的离线验证：不触网，用 fake 适配器覆盖分批、失败中断、续跑、draft 与幂等。
 */
class PurgeBatchPlannerTest extends TestCase
{
    public function testSharedImpactSendsOnePrefixPerZone(): void
    {
        $plan = (new PurgeBatchPlanner())->plan(
            ['shared' => true, 'exact' => [], 'static' => false, 'prefixes' => []],
            ['a.test', 'b.test'],
        );

        self::assertCount(2, $plan);
        self::assertSame(['/'], $plan[0]['targets']);
        self::assertSame('shared', $plan[0]['mode']);
        self::assertSame('b.test', $plan[1]['zone']);
    }

    public function testExactImpactIsChunkedPerZone(): void
    {
        $exact = [];
        for ($i = 0; $i < 5; $i++) {
            $exact[] = 'page-' . $i;
        }
        $plan = (new PurgeBatchPlanner())->plan(
            ['shared' => false, 'exact' => $exact, 'static' => false, 'prefixes' => []],
            ['a.test'],
            2,
        );

        self::assertCount(3, $plan, '5 个目标按每批 2 条切成 3 批');
        self::assertSame(['/page-0', '/page-1'], $plan[0]['targets']);
        self::assertSame(['/page-4'], $plan[2]['targets']);
    }

    public function testFailureStopsAndResumeCompletes(): void
    {
        $adapter = new class implements CdnPurgeAdapterInterface {
            /** @var list<array{zone:string,targets:list<string>}> */
            public array $calls = [];
            public int $failAtCall = 2;

            public function purge(string $zone, array $targets): bool
            {
                $this->calls[] = ['zone' => $zone, 'targets' => $targets];
                if (\count($this->calls) === $this->failAtCall) {
                    return false;
                }

                return true;
            }
        };

        $planner = new PurgeBatchPlanner();
        $plan = $planner->plan(
            ['shared' => false, 'exact' => ['a', 'b', 'c', 'd'], 'static' => false, 'prefixes' => []],
            ['site.test'],
            1,
        );
        self::assertCount(4, $plan);

        $first = $planner->run($plan, $adapter);
        self::assertSame(1, $first['done'], '第 1 批成功、第 2 批失败后停下');
        self::assertSame(1, $first['failed']);
        self::assertSame(1, $first['resumeFrom']);
        self::assertSame('purge_failed', $first['log'][1]['reason']);

        // 续跑：从失败那批开始，已成功的批次不重发。
        $adapter->failAtCall = 0;
        $callsBefore = \count($adapter->calls);
        $second = $planner->run($plan, $adapter, $first['resumeFrom']);
        self::assertSame(3, $second['done'], '续跑完成剩余 3 批');
        self::assertNull($second['failed']);
        self::assertSame($callsBefore + 3, \count($adapter->calls), '续跑不重复已成功批次');
    }

    public function testDraftBatchesAreSkippedNotPurged(): void
    {
        $adapter = new class implements CdnPurgeAdapterInterface {
            public int $calls = 0;

            public function purge(string $zone, array $targets): bool
            {
                $this->calls++;

                return true;
            }
        };

        $planner = new PurgeBatchPlanner();
        $plan = $planner->plan(
            ['shared' => true, 'exact' => [], 'static' => false, 'prefixes' => []],
            ['site.test'],
            PurgeBatchPlanner::DEFAULT_BATCH_SIZE,
            true,
        );
        $result = $planner->run($plan, $adapter);

        self::assertSame(0, $adapter->calls, 'draft 不进 CDN');
        self::assertSame(1, $result['skippedDraft']);
        self::assertSame(0, $result['done']);
    }

    public function testStaticOnlyImpactProducesNoPlan(): void
    {
        $plan = (new PurgeBatchPlanner())->plan(
            ['shared' => false, 'exact' => [], 'static' => true, 'prefixes' => []],
            ['site.test'],
        );

        self::assertSame([], $plan, '静态走 ?v=，不需要 HTML 失效');
    }

    public function testGuardedRunRetriesThenSucceeds(): void
    {
        $adapter = new class implements CdnPurgeAdapterInterface {
            public int $calls = 0;

            public function purge(string $zone, array $targets): bool
            {
                $this->calls++;

                // 第 1 次失败、第 2 次成功：重试应当把这一批救回来。
                return $this->calls > 1;
            }
        };

        $planner = new PurgeBatchPlanner();
        $plan = $planner->plan(
            ['shared' => true, 'exact' => [], 'static' => false, 'prefixes' => []],
            ['site.test'],
        );
        $result = $planner->runGuarded($plan, $adapter, 0, 2, 3);

        self::assertSame(1, $result['done']);
        self::assertNull($result['failed']);
        self::assertFalse($result['circuitOpen']);
        self::assertSame([0 => 2], $result['attempts'], '这一批用了 2 次尝试');
    }

    public function testGuardedRunOpensCircuitAfterConsecutiveFailures(): void
    {
        $adapter = new class implements CdnPurgeAdapterInterface {
            public int $calls = 0;

            public function purge(string $zone, array $targets): bool
            {
                $this->calls++;

                return false;
            }
        };

        $planner = new PurgeBatchPlanner();
        $plan = $planner->plan(
            ['shared' => false, 'exact' => ['a', 'b', 'c', 'd'], 'static' => false, 'prefixes' => []],
            ['site.test'],
            1,
        );
        self::assertCount(4, $plan);

        $result = $planner->runGuarded($plan, $adapter, 0, 1, 2);

        self::assertTrue($result['circuitOpen'], '连续失败到阈值即熔断');
        self::assertSame(0, $result['done']);
        self::assertSame(0, $result['failed'], '续跑点取第一次失败的批次');
        self::assertSame('circuit_open', $result['log'][\count($result['log']) - 1]['reason']);
        self::assertCount(2, $result['attempts'], '熔断后不再触碰后续批次');
    }
}
