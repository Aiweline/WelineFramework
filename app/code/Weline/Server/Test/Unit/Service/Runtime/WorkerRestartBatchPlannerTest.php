<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\WorkerRestartBatchPlanner;

final class WorkerRestartBatchPlannerTest extends TestCase
{
    /**
     * @dataProvider provideBatchMatrices
     * @param list<int> $ids
     */
    public function testDefaultThreeBatchMinCountFour(array $ids, int $expectedBatches): void
    {
        $batches = WorkerRestartBatchPlanner::plan($ids);
        self::assertCount($expectedBatches, $batches);
        $flat = [];
        foreach ($batches as $batch) {
            foreach ($batch as $id) {
                $flat[] = $id;
            }
            $minReady = WorkerRestartBatchPlanner::resolveMinReady(\count($ids));
            self::assertLessThanOrEqual(
                \count($ids) - $minReady,
                \count($batch),
                'each batch must respect min_ready capacity'
            );
        }
        self::assertSame($ids, $flat);
    }

    /** @return array<string, array{0:list<int>,1:int}> */
    public static function provideBatchMatrices(): array
    {
        // N=2/3: requiredBatchCount only (below three-batch threshold).
        // N≥4: fixed three-batch mind with default min_ready.
        return [
            'N1' => [[1], 1],
            'N2' => [[1, 2], 2],
            'N3' => [[1, 2, 3], 3],
            'N4' => [[1, 2, 3, 4], 3],
            'N5' => [[1, 2, 3, 4, 5], 3],
            'N6' => [[1, 2, 3, 4, 5, 6], 3],
            'N9' => [[1, 2, 3, 4, 5, 6, 7, 8, 9], 3],
        ];
    }

    public function testIllegalThreeBatchMinCountClampsToFourNotSeven(): void
    {
        $ids = [1, 2, 3, 4, 5, 6];
        $withTypo = WorkerRestartBatchPlanner::plan($ids, false, 2);
        $withDefault = WorkerRestartBatchPlanner::plan($ids, false, 4);
        self::assertCount(\count($withDefault), $withTypo);
        self::assertCount(3, $withTypo);
    }
}
