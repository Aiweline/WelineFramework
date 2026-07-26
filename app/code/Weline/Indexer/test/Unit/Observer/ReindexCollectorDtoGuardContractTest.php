<?php

declare(strict_types=1);

namespace Weline\Indexer\test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/**
 * 契约：Model 目录扫描须在 ObjectManager::getInstance 前过滤非 AbstractModel，
 * 否则 VendorSplitSnapshot 等 DTO 会在 setup:upgrade 后段炸死。
 */
final class ReindexCollectorDtoGuardContractTest extends TestCase
{
    private static function repoRoot(): string
    {
        // .../Indexer/test/Unit/Observer -> repo root = 7 levels up
        return dirname(__DIR__, 7);
    }

    public function testCollectorSkipsNonAbstractModelBeforeInstantiation(): void
    {
        $src = file_get_contents(self::repoRoot() . '/app/code/Weline/Indexer/Observer/ReindexCollector.php');
        self::assertNotFalse($src);
        self::assertStringContainsString('isSubclassOf(AbstractModel::class)', $src);

        $getPos = strpos($src, 'ObjectManager::getInstance($modelClass)');
        $guardPos = strpos($src, 'isSubclassOf(AbstractModel::class)');
        self::assertNotFalse($getPos);
        self::assertNotFalse($guardPos);
        self::assertLessThan($getPos, $guardPos, 'isSubclassOf must run before getInstance');
    }

    public function testIndexConsoleCommandsAlsoGuardBeforeInstantiation(): void
    {
        $root = self::repoRoot();
        foreach ([
            $root . '/app/code/Weline/Framework/Database/Console/Index/Reindex.php',
            $root . '/app/code/Weline/Framework/Database/Console/Index/Listing.php',
        ] as $path) {
            $src = file_get_contents($path);
            self::assertNotFalse($src, $path);
            $getPos = strpos($src, 'ObjectManager::getInstance($model)');
            $guardPos = strpos($src, 'isSubclassOf(AbstractModel::class)');
            self::assertNotFalse($getPos, $path);
            self::assertNotFalse($guardPos, $path);
            self::assertLessThan($getPos, $guardPos, $path);
        }
    }

    public function testVendorSplitSnapshotIsDtoNotOrmModel(): void
    {
        $ref = new \ReflectionClass(\Weline\Vendor\Model\VendorSplitSnapshot::class);
        self::assertFalse($ref->isSubclassOf(\Weline\Framework\Database\AbstractModel::class));
        self::assertGreaterThan(0, (int)$ref->getConstructor()?->getNumberOfRequiredParameters());
    }
}
