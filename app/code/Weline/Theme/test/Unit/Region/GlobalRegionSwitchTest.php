<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Region;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Region\GlobalRegionFragment;
use Weline\Theme\Region\GlobalRegionSwitch;

final class GlobalRegionSwitchTest extends TestCase
{
    private function fragment(string $source = GlobalRegionFragment::SOURCE_THEME): GlobalRegionFragment
    {
        return new GlobalRegionFragment(
            id: 'base.head.analytics.01-pixel',
            region: 'head',
            group: 'analytics',
            order: 1,
            name: 'pixel',
            source: $source,
            path: '/tmp/01-pixel.phtml',
            defaultEnabled: $source === GlobalRegionFragment::SOURCE_THEME,
        );
    }

    public function testNothingConfiguredFallsBackToThePathDefault(): void
    {
        $switch = new GlobalRegionSwitch([]);

        self::assertTrue($switch->allows($this->fragment(GlobalRegionFragment::SOURCE_THEME)));
        self::assertFalse($switch->allows($this->fragment(GlobalRegionFragment::SOURCE_MODULE)));
    }

    public function testSiteWideSwitchVetoesEverything(): void
    {
        $switch = new GlobalRegionSwitch(['enabled' => false]);

        self::assertFalse($switch->allows($this->fragment()));
    }

    public function testRegionSwitchOnlyVetoesItsOwnRegion(): void
    {
        $switch = new GlobalRegionSwitch(['regions' => ['head' => false]]);

        self::assertFalse($switch->allows($this->fragment()));
        self::assertTrue($switch->allows(new GlobalRegionFragment(
            id: 'base.body_end.ops.01-tail',
            region: 'body_end',
            group: 'ops',
            order: 1,
            name: 'tail',
            source: GlobalRegionFragment::SOURCE_THEME,
            path: '/tmp/01-tail.phtml',
            defaultEnabled: true,
        )));
    }

    public function testGroupSwitchVetoesTheWholeGroup(): void
    {
        $switch = new GlobalRegionSwitch(['groups' => ['head.analytics' => false]]);

        self::assertFalse($switch->allows($this->fragment()));
    }

    public function testEnablingARegionDoesNotTurnOnModuleFragments(): void
    {
        $switch = new GlobalRegionSwitch([
            'enabled' => true,
            'regions' => ['head' => true],
            'groups' => ['head.analytics' => true],
        ]);

        self::assertFalse(
            $switch->allows($this->fragment(GlobalRegionFragment::SOURCE_MODULE)),
            'a module fragment must still need its own opt-in',
        );
    }

    public function testItemSwitchCanEnableAModuleFragment(): void
    {
        $switch = new GlobalRegionSwitch(['items' => ['base.head.analytics.01-pixel' => true]]);

        self::assertTrue($switch->allows($this->fragment(GlobalRegionFragment::SOURCE_MODULE)));
    }

    public function testItemFalseWinsOverGroupAndRegionTrue(): void
    {
        $switch = new GlobalRegionSwitch([
            'enabled' => true,
            'regions' => ['head' => true],
            'groups' => ['head.analytics' => true],
            'items' => ['base.head.analytics.01-pixel' => false],
        ]);

        self::assertFalse($switch->allows($this->fragment()));
    }

    public function testMalformedConfigIsTreatedAsEmpty(): void
    {
        $switch = new GlobalRegionSwitch(['regions' => 'nope', 'groups' => null, 'items' => 42]);

        self::assertTrue($switch->allows($this->fragment()));
    }
}
