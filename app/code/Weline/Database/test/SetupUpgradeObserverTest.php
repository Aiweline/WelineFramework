<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Database\Observer\SetupUpgradeObserver;

final class SetupUpgradeObserverTest extends TestCase
{
    public function testSpaceSeparatedModuleFilterKeepsEveryRequestedActiveModule(): void
    {
        $reflection = new ReflectionClass(SetupUpgradeObserver::class);
        $observer = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('getActiveModules');
        $method->setAccessible(true);

        $modules = $method->invoke($observer, [
            'module' => 'Weline_Database Weline_ModuleManager',
        ]);

        self::assertContains('Weline_Database', $modules);
        self::assertContains('Weline_ModuleManager', $modules);
    }

    public function testArrayModuleFilterAlsoSplitsCommaSeparatedValues(): void
    {
        $reflection = new ReflectionClass(SetupUpgradeObserver::class);
        $observer = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('getActiveModules');
        $method->setAccessible(true);

        $modules = $method->invoke($observer, [
            'module' => ['Weline_Database,Weline_ModuleManager'],
        ]);

        self::assertContains('Weline_Database', $modules);
        self::assertContains('Weline_ModuleManager', $modules);
    }

    public function testVersionCursorWaitsForModuleSetupCommit(): void
    {
        $reflection = new ReflectionClass(SetupUpgradeObserver::class);
        $observer = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveCompletedSetupVersion');
        $method->setAccessible(true);

        self::assertNull($method->invoke($observer, [
            'version' => '2.1.0',
            'setup_version' => '1.2.0',
            'upgrading' => true,
            'pending_setup_upgrade' => true,
        ]));
        self::assertNull($method->invoke($observer, [
            'version' => '2.1.0',
            'installing' => true,
        ]));
        self::assertSame('2.1.0', $method->invoke($observer, [
            'version' => '2.1.0',
            'setup_version' => '2.1.0',
        ]));
        self::assertSame('2.1.0', $method->invoke($observer, [
            'version' => '2.1.0',
        ]));
    }
}
