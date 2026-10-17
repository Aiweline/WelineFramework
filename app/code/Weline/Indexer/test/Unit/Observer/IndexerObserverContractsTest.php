<?php

declare(strict_types=1);

namespace Weline\Indexer\test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllIndexerObserverContractsPass().
 */
final class IndexerObserverContractsTest extends TestCase
{
    public function testAllIndexerObserverContractsPass(): void
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
            'ReindexCollectorDtoGuard::testCollectorSkipsNonAbstractModelBeforeInstantiation' => function () {
            $this->assert_ReindexCollectorDtoGuard_testCollectorSkipsNonAbstractModelBeforeInstantiation();
        },
            'ReindexCollectorDtoGuard::testIndexConsoleCommandsAlsoGuardBeforeInstantiation' => function () {
            $this->assert_ReindexCollectorDtoGuard_testIndexConsoleCommandsAlsoGuardBeforeInstantiation();
        },
            'ReindexCollectorDtoGuard::testVendorSplitSnapshotIsDtoNotOrmModel' => function () {
            $this->assert_ReindexCollectorDtoGuard_testVendorSplitSnapshotIsDtoNotOrmModel();
        },
        ];
    }

private function repoRoot_ReindexCollectorDtoGuard(): string
{
    // .../Indexer/test/Unit/Observer -> repo root = 7 levels up
    return '/Users/weline/Project/Official/框架';
}

private function assert_ReindexCollectorDtoGuard_testCollectorSkipsNonAbstractModelBeforeInstantiation(): void
{
    $src = file_get_contents(self::repoRoot_ReindexCollectorDtoGuard() . '/app/code/Weline/Indexer/Observer/ReindexCollector.php');
    self::assertNotFalse($src);
    self::assertStringContainsString('isSubclassOf(AbstractModel::class)', $src);
    $getPos = strpos($src, 'ObjectManager::getInstance($modelClass)');
    $guardPos = strpos($src, 'isSubclassOf(AbstractModel::class)');
    self::assertNotFalse($getPos);
    self::assertNotFalse($guardPos);
    self::assertLessThan($getPos, $guardPos, 'isSubclassOf must run before getInstance');
}

private function assert_ReindexCollectorDtoGuard_testIndexConsoleCommandsAlsoGuardBeforeInstantiation(): void
{
    $root = self::repoRoot_ReindexCollectorDtoGuard();
    foreach ([$root . '/app/code/Weline/Framework/Database/Console/Index/Reindex.php', $root . '/app/code/Weline/Framework/Database/Console/Index/Listing.php'] as $path) {
        $src = file_get_contents($path);
        self::assertNotFalse($src, $path);
        $getPos = strpos($src, 'ObjectManager::getInstance($model)');
        $guardPos = strpos($src, 'isSubclassOf(AbstractModel::class)');
        self::assertNotFalse($getPos, $path);
        self::assertNotFalse($guardPos, $path);
        self::assertLessThan($getPos, $guardPos, $path);
    }
}

private function assert_ReindexCollectorDtoGuard_testVendorSplitSnapshotIsDtoNotOrmModel(): void
{
    $ref = new \ReflectionClass(\Weline\Vendor\Model\VendorSplitSnapshot::class);
    self::assertFalse($ref->isSubclassOf(\Weline\Framework\Database\AbstractModel::class));
    self::assertGreaterThan(0, (int) $ref->getConstructor()?->getNumberOfRequiredParameters());
}
}
