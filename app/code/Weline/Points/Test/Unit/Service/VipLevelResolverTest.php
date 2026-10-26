<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Points\Service\VipLevelResolver;

final class VipLevelResolverTest extends TestCase
{
    private const LEVELS = [
        ['level_id' => 1, 'code' => 'standard', 'name' => 'Standard', 'min_growth' => 0, 'points_multiplier' => 1.0],
        ['level_id' => 2, 'code' => 'silver', 'name' => 'Silver', 'min_growth' => 1000, 'points_multiplier' => 1.05],
        ['level_id' => 3, 'code' => 'gold', 'name' => 'Gold', 'min_growth' => 5000, 'points_multiplier' => 1.1],
        ['level_id' => 4, 'code' => 'diamond', 'name' => 'Diamond', 'min_growth' => 50000, 'points_multiplier' => 1.3, 'is_active' => false],
    ];

    public function testResolvesHighestReachedLevel(): void
    {
        $resolver = new VipLevelResolver();

        self::assertSame('standard', $resolver->resolve(self::LEVELS, 0)['code']);
        self::assertSame('standard', $resolver->resolve(self::LEVELS, 999)['code']);
        self::assertSame('silver', $resolver->resolve(self::LEVELS, 1000)['code']);
        self::assertSame('gold', $resolver->resolve(self::LEVELS, 50000)['code']);
    }

    public function testInactiveLevelIsNeverSelected(): void
    {
        $level = (new VipLevelResolver())->resolve(self::LEVELS, 999999);

        self::assertSame('gold', $level['code']);
    }

    public function testFallsBackToStandardWhenNoLevelMatches(): void
    {
        $level = (new VipLevelResolver())->resolve([], 100);

        self::assertSame('standard', $level['code']);
        self::assertSame(1.0, $level['points_multiplier']);
    }

    public function testBenefitsAreClamped(): void
    {
        $level = (new VipLevelResolver())->resolve([
            ['code' => 'weird', 'min_growth' => 0, 'discount_rate' => 3.5, 'points_multiplier' => -1],
        ], 0);

        self::assertSame(1.0, $level['discount_rate']);
        self::assertSame(0.0, $level['points_multiplier']);
    }
}
