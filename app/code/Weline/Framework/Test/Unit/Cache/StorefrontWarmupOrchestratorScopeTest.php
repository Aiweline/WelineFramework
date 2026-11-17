<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOrchestrator;

/** 多站点单片时长预算：按站点数分摊，且不得把 0（让出带）抬成正数。 */
final class StorefrontWarmupOrchestratorScopeTest extends TestCase
{
    public function testWallBudgetDividesByScopeCount(): void
    {
        self::assertSame(6875, StorefrontWarmupOrchestrator::wallBudgetForScopes(55000, 8));
    }

    public function testWallBudgetSingleScopeUnchanged(): void
    {
        self::assertSame(55000, StorefrontWarmupOrchestrator::wallBudgetForScopes(55000, 1));
    }

    public function testWallBudgetHasFloorForManyScopes(): void
    {
        self::assertSame(1000, StorefrontWarmupOrchestrator::wallBudgetForScopes(55000, 100));
        self::assertSame(0, StorefrontWarmupOrchestrator::wallBudgetForScopes(55000, 0));
    }

    public function testClampWallMsCapsEffectiveWall(): void
    {
        self::assertSame(6875, StorefrontWarmupOrchestrator::clampWallMs(55000, 6875));
    }

    public function testClampWallMsZeroRequestedKeepsEffective(): void
    {
        self::assertSame(55000, StorefrontWarmupOrchestrator::clampWallMs(55000, 0));
    }

    public function testClampWallMsZeroEffectiveStaysZero(): void
    {
        self::assertSame(0, StorefrontWarmupOrchestrator::clampWallMs(0, 6875));
    }
}
