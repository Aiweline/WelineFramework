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

    public function testIdleModuleMigrationOutputIsCompressed(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/Observer/SetupUpgradeObserver.php');

        self::assertStringNotContainsString('检查模块: {$moduleName}', $source);
        self::assertStringNotContainsString('没有待执行的迁移', $source);
        self::assertStringNotContainsString('setStickyFooter', $source);
        self::assertStringContainsString('迁移检查完成：共 %{total}', $source);
        self::assertStringContainsString('▶ %{module}：%{count} 个待执行迁移', $source);
        self::assertStringContainsString('迁移检查进度 %{i}/%{total}', $source);
        self::assertStringContainsString('flushCli', $source);
    }
}
