<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class ForceMenuSetupEventContractTest extends TestCase
{
    public function testUpgradeMenuAppliesForceMenuFromEvent(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/UpgradeMenu.php'
        );
        self::assertStringContainsString('force_menu', $src);
        self::assertStringContainsString('beginForceFullHold', $src);
        self::assertStringContainsString('requestForceFull', $src);
    }

    public function testSetupUpgradeAfterReleasesForceMenu(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/SetupUpgradeAfter.php'
        );
        self::assertStringContainsString('force_menu_release', $src);
        self::assertStringContainsString('resetForceFullState', $src);
    }
}
