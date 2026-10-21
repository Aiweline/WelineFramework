<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdatePointerPlanner;

/**
 * 主题更新代次指针决策的验收测试（纯逻辑，无 DB）。
 */
final class ThemeUpdatePointerPlannerTest extends TestCase
{
    public function testBatchIdFormat(): void
    {
        $id = ThemeUpdatePointerPlanner::newBatchId(new \DateTimeImmutable('2026-10-22 01:53:00'), 'Ab12Cd');
        self::assertSame('tu-20261022-015300-ab12cd', $id);
        self::assertTrue(ThemeUpdatePointerPlanner::isBatchIdValid($id));
        self::assertFalse(ThemeUpdatePointerPlanner::isBatchIdValid('legacy-5b43d39a2274'));
        self::assertFalse(ThemeUpdatePointerPlanner::isBatchIdValid('tu-2026-01-01-x'));
    }

    public function testBatchIdSuffixIsNormalized(): void
    {
        $id = ThemeUpdatePointerPlanner::newBatchId(new \DateTimeImmutable('2026-10-22 00:00:00'), 'a-b_c');
        self::assertSame('tu-20261022-000000-abc000', $id);

        $short = ThemeUpdatePointerPlanner::newBatchId(new \DateTimeImmutable('2026-10-22 00:00:00'), 'x');
        self::assertSame('tu-20261022-000000-x00000', $short);
    }

    public function testPromoteKeepsPreviousAndBumpsEpoch(): void
    {
        $next = ThemeUpdatePointerPlanner::planPromote(
            ['current' => 'tu-20261022-010000-aaaaaa', 'previous' => '', 'epoch' => 7],
            'tu-20261022-015300-ab12cd'
        );

        self::assertSame('tu-20261022-015300-ab12cd', $next['current']);
        self::assertSame('tu-20261022-010000-aaaaaa', $next['previous']);
        self::assertSame(8, $next['epoch']);
    }

    public function testPromoteRejectsSameBatchAndInvalidId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ThemeUpdatePointerPlanner::planPromote(
            ['current' => 'tu-20261022-015300-ab12cd', 'previous' => '', 'epoch' => 1],
            'tu-20261022-015300-ab12cd'
        );
    }

    public function testPromoteRejectsLegacyBatchId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ThemeUpdatePointerPlanner::planPromote(['current' => '', 'previous' => '', 'epoch' => 0], 'legacy-abc');
    }

    public function testRollbackSwapsCurrentAndPrevious(): void
    {
        $state = ThemeUpdatePointerPlanner::planRollback([
            'current' => 'tu-20261022-015300-ab12cd',
            'previous' => 'tu-20261022-010000-aaaaaa',
            'epoch' => 8,
        ]);

        self::assertSame('tu-20261022-010000-aaaaaa', $state['current']);
        self::assertSame('tu-20261022-015300-ab12cd', $state['previous']);
        self::assertSame(9, $state['epoch']);
    }

    public function testRollbackRequiresPrevious(): void
    {
        $this->expectException(\RuntimeException::class);
        ThemeUpdatePointerPlanner::planRollback(['current' => 'tu-20261022-015300-ab12cd', 'previous' => '', 'epoch' => 8]);
    }

    public function testGcProtectsCurrentAndPreviousAndKeepsNewest(): void
    {
        $batches = [
            ['batch_id' => 'tu-20261001-000000-aaaaaa', 'create_time' => '2026-10-01 00:00:00'],
            ['batch_id' => 'tu-20261005-000000-bbbbbb', 'create_time' => '2026-10-05 00:00:00'],
            ['batch_id' => 'tu-20261010-000000-cccccc', 'create_time' => '2026-10-10 00:00:00'],
            ['batch_id' => 'tu-20261015-000000-dddddd', 'create_time' => '2026-10-15 00:00:00'],
            ['batch_id' => 'tu-20261020-000000-eeeeee', 'create_time' => '2026-10-20 00:00:00'],
        ];

        $drop = ThemeUpdatePointerPlanner::planGc(
            $batches,
            'tu-20261020-000000-eeeeee',
            'tu-20261015-000000-dddddd',
            2
        );

        // 保留 current + previous + 最新 2 个历史（cccccc / bbbbbb），只删最旧的 aaaaaa。
        self::assertSame(['tu-20261001-000000-aaaaaa'], $drop);
    }

    public function testGcKeepsEverythingWhenWithinBudget(): void
    {
        $batches = [
            ['batch_id' => 'tu-20261001-000000-aaaaaa', 'create_time' => '2026-10-01 00:00:00'],
            ['batch_id' => 'tu-20261002-000000-bbbbbb', 'create_time' => '2026-10-02 00:00:00'],
        ];

        self::assertSame([], ThemeUpdatePointerPlanner::planGc($batches, 'tu-20261002-000000-bbbbbb', '', 2));
    }

    public function testGcNeverDropsProtectedEvenIfOldest(): void
    {
        $batches = [
            ['batch_id' => 'tu-20261001-000000-aaaaaa', 'create_time' => '2026-10-01 00:00:00'],
            ['batch_id' => 'tu-20261002-000000-bbbbbb', 'create_time' => '2026-10-02 00:00:00'],
            ['batch_id' => 'tu-20261003-000000-cccccc', 'create_time' => '2026-10-03 00:00:00'],
        ];

        $drop = ThemeUpdatePointerPlanner::planGc($batches, 'tu-20261003-000000-cccccc', 'tu-20261001-000000-aaaaaa', 0);

        self::assertSame(['tu-20261002-000000-bbbbbb'], $drop);
    }

    public function testServableRequiresVerifiedManifest(): void
    {
        self::assertTrue(ThemeUpdatePointerPlanner::isServable([
            'status' => 'verified',
            'manifest' => ['verify' => ['ok' => true]],
        ]));
        self::assertTrue(ThemeUpdatePointerPlanner::isServable([
            'status' => 'current',
            'manifest' => ['verify' => ['ok' => true]],
        ]));
        self::assertFalse(ThemeUpdatePointerPlanner::isServable([
            'status' => 'verified',
            'manifest' => ['verify' => ['ok' => false]],
        ]));
        self::assertFalse(ThemeUpdatePointerPlanner::isServable([
            'status' => 'building',
            'manifest' => ['verify' => ['ok' => true]],
        ]));
        self::assertFalse(ThemeUpdatePointerPlanner::isServable([
            'status' => 'verified',
            'manifest' => [],
        ]));
    }
}
