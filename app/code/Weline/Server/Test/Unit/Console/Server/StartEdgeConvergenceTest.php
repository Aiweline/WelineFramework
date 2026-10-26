<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Server;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

/**
 * 「服务代在跑」不等于「公网边缘在跑」。start 必须能收敛到边缘可用，
 * 且只补边缘、不重建服务代（2026-10-26 线上 33h 停站后的收敛修复）。
 */
final class StartEdgeConvergenceTest extends TestCase
{
    public function testHealthyOwnedEdgeNeedsNoRepair(): void
    {
        self::assertFalse(Start::edgeSnapshotNeedsRepair(
            ['running' => true, 'pid' => 1234],
            ['instance_name' => 'default'],
            'default',
        ));
    }

    public function testStoppedEdgeNeedsRepair(): void
    {
        self::assertTrue(Start::edgeSnapshotNeedsRepair(
            ['running' => false, 'pid' => null],
            ['instance_name' => 'default'],
            'default',
        ));
    }

    public function testForeignOrMissingOwnerNeedsRepair(): void
    {
        self::assertTrue(Start::edgeSnapshotNeedsRepair(
            ['running' => true, 'pid' => 1234],
            ['instance_name' => 'other'],
            'default',
        ));
        self::assertTrue(Start::edgeSnapshotNeedsRepair(
            ['running' => true, 'pid' => 1234],
            null,
            'default',
        ));
    }

    public function testOwnerComparisonIgnoresSurroundingWhitespace(): void
    {
        self::assertFalse(Start::edgeSnapshotNeedsRepair(
            ['running' => true],
            ['instance_name' => ' default '],
            'default',
        ));
    }
}
