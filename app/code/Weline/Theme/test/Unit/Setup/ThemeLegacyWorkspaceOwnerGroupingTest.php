<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Setup\Upgrade;

final class ThemeLegacyWorkspaceOwnerGroupingTest extends TestCase
{
    public function testSameScopeModesStaySeparateAndPageOptionsRemainInTheirOwner(): void
    {
        $rows = [
            ['scope' => 'shop-a.store.channel', 'store_mode' => 'normal', 'area' => 'frontend', 'layout_type' => 'homepage', 'layout_option' => 'default'],
            ['scope' => 'shop-a.store.channel', 'store_mode' => 'dev', 'area' => 'frontend', 'layout_type' => 'homepage', 'layout_option' => 'draft'],
            ['scope' => 'shop-a.store.channel', 'store_mode' => 'dev', 'area' => 'frontend', 'layout_type' => 'account/login', 'layout_option' => 'alternate', 'target_type' => 'customer', 'target_id' => 9],
            ['scope' => 'shop_a.store.channel', 'store_mode' => 'normal', 'area' => 'frontend', 'layout_type' => 'homepage', 'layout_option' => 'default'],
        ];
        $method = new \ReflectionMethod(Upgrade::class, 'groupLegacyWorkspaceOwners');
        $groups = \array_values($method->invoke(new Upgrade(), $rows));
        self::assertCount(3, $groups);
        self::assertSame([$rows[0]], $groups[0]);
        self::assertSame([$rows[1], $rows[2]], $groups[1]);
        self::assertSame([$rows[3]], $groups[2]);
    }
}
