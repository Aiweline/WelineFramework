<?php
declare(strict_types=1);

namespace Weline\AppStore\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\AppStore\Service\ModuleUninstallService;
use Weline\Framework\App\Exception;

class ModuleUninstallServiceTest extends TestCase
{
    public function testBuildUninstallCommandUsesFrameworkRemoveFlow(): void
    {
        $service = new ModuleUninstallService();
        $method = new ReflectionMethod(ModuleUninstallService::class, 'buildUninstallCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service, 'Weline_SampleModule');

        $this->assertStringContainsString('echo y |', $command);
        $this->assertStringContainsString('bin', $command);
        $this->assertStringContainsString('w', $command);
        $this->assertStringContainsString('module:remove', $command);
        $this->assertStringContainsString('Weline_SampleModule', $command);
    }

    public function testProtectedAppStoreModuleCannotBeUninstalled(): void
    {
        $service = new ModuleUninstallService();
        $module = new AppStoreInstalledModule();
        $module->setModuleName('Weline_AppStore');

        $this->expectException(Exception::class);

        $service->uninstall($module);
    }

    public function testInvalidModuleNameCannotBeUninstalled(): void
    {
        $service = new ModuleUninstallService();
        $module = new AppStoreInstalledModule();
        $module->setModuleName('bad module');

        $this->expectException(Exception::class);

        $service->uninstall($module);
    }
}
