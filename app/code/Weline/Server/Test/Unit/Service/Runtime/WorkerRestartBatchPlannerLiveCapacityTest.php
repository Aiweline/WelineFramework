<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\WorkerRestartBatchPlanner;

/**
 * 回归：批次规模必须受「真实存活并接受请求的 Worker 数」约束。
 *
 * 线上事故形态：按 8 槽规划出每批 3 个，而实际只有 4 个 Worker 存活，
 * 于是任一批摘 3 个后只剩 1 个，min_ready 守卫（全池下限 5）恒拒，
 * 滚动重启每一批都失败、永不推进，最终 worker 池归零导致全站 503。
 */
final class WorkerRestartBatchPlannerLiveCapacityTest extends TestCase
{
    /**
     * 存活容量小于槽位数时，每批摘除量不得超过「存活数 - 存活下限」。
     *
     * @dataProvider provideLiveCapacityCases
     * @param list<int> $ids
     */
    public function testBatchesRespectLiveCapacity(array $ids, int $liveCapacity): void
    {
        $minReady = WorkerRestartBatchPlanner::resolveMinReady($liveCapacity);
        $batches = WorkerRestartBatchPlanner::plan(
            $ids,
            false,
            4,
            3,
            $minReady,
            $liveCapacity
        );

        self::assertNotSame([], $batches, 'planning must not collapse to zero batches');

        // 预算：每次摘除后，仍存活的 Worker 不得低于存活下限（至少留 1 个 acceptor）。
        $safeMin = \max(1, \min($liveCapacity - 1, $minReady));
        $maxBatch = \max(1, $liveCapacity - $safeMin);
        foreach ($batches as $batch) {
            self::assertLessThanOrEqual(
                $maxBatch,
                \count($batch),
                'batch size must not exceed the live-capacity safe window'
            );
        }

        // 批次必须覆盖全部槽位且无重复、无遗漏，否则会有槽位永不重建。
        $flat = [];
        foreach ($batches as $batch) {
            foreach ($batch as $id) {
                $flat[] = $id;
            }
        }
        self::assertSame($ids, $flat, 'batches must cover every slot exactly once');
    }

    /** @return array<string, array{0:list<int>,1:int}> */
    public static function provideLiveCapacityCases(): array
    {
        return [
            // 线上实测形态：8 槽规划、仅 4 个存活（旧逻辑每批 3 个 → 恒拒）。
            'planned-8-live-4' => [[1, 2, 3, 4, 5, 6, 7, 8], 4],
            'planned-8-live-6' => [[1, 2, 3, 4, 5, 6, 7, 8], 6],
            'planned-8-live-2' => [[1, 2, 3, 4, 5, 6, 7, 8], 2],
            'planned-4-live-4' => [[1, 2, 3, 4], 4],
            'planned-8-live-8' => [[1, 2, 3, 4, 5, 6, 7, 8], 8],
        ];
    }

    /**
     * 存活容量充足时保持既有行为，避免修复改变正常三批节奏。
     */
    public function testFullCapacityKeepsDefaultThreeBatches(): void
    {
        $ids = [1, 2, 3, 4, 5, 6, 7, 8];
        $default = WorkerRestartBatchPlanner::plan($ids);
        $explicit = WorkerRestartBatchPlanner::plan(
            $ids,
            false,
            4,
            3,
            WorkerRestartBatchPlanner::resolveMinReady(8),
            8
        );

        self::assertSame($default, $explicit, 'full live capacity must not change default planning');
    }

    /**
     * 未传存活容量时保持旧签名语义（liveCapacity=null 等价于列表长度）。
     */
    public function testNullLiveCapacityPreservesLegacyBehaviour(): void
    {
        $ids = [1, 2, 3, 4, 5, 6, 7, 8];
        self::assertSame(
            WorkerRestartBatchPlanner::plan($ids),
            WorkerRestartBatchPlanner::plan($ids, false, 4, 3, null, null)
        );
    }
}
