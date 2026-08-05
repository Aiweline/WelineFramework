<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\UpgradeInterface;

/**
 * SchemaDiff commit 前校验本轮将执行的 Install/Upgrade 脚本契约。
 */
final class SetupScriptContractValidator
{
    /**
     * @param list<Module> $installModules
     * @param list<Module> $upgradeModules
     * @throws Exception
     */
    public function assertContracts(array $installModules, array $upgradeModules): void
    {
        foreach ($installModules as $module) {
            $this->assertInstallContract($module);
        }
        foreach ($upgradeModules as $module) {
            $this->assertUpgradeContract($module);
        }
    }

    /**
     * @throws Exception
     */
    public function assertInstallContract(Module $module): void
    {
        $path = rtrim((string)$module->getBasePath(), '/\\') . DIRECTORY_SEPARATOR . 'Setup' . DIRECTORY_SEPARATOR . 'Install.php';
        if (!is_file($path)) {
            return;
        }
        $class = $this->resolveSetupClass($module, 'Install');
        if (!class_exists($class)) {
            throw new Exception(__('模块 %{1} 的 Install 类 %{2} 无法加载', [$module->getName(), $class]));
        }
        if (!is_a($class, InstallInterface::class, true)) {
            throw new Exception(__(
                '模块 %{1} 的 %{2} 必须实现 %{3}',
                [$module->getName(), $class, InstallInterface::class]
            ));
        }
        if (!is_callable([$class, 'setup']) && !method_exists($class, 'setup')) {
            throw new Exception(__(
                '模块 %{1} 的 %{2} 缺少可调用的 setup(Data\\Setup, Data\\Context) 方法',
                [$module->getName(), $class]
            ));
        }
    }

    /**
     * @throws Exception
     */
    public function assertUpgradeContract(Module $module): void
    {
        $path = rtrim((string)$module->getBasePath(), '/\\') . DIRECTORY_SEPARATOR . 'Setup' . DIRECTORY_SEPARATOR . 'Upgrade.php';
        if (!is_file($path)) {
            return;
        }
        $class = $this->resolveSetupClass($module, 'Upgrade');
        if (!class_exists($class)) {
            throw new Exception(__('模块 %{1} 的 Upgrade 类 %{2} 无法加载', [$module->getName(), $class]));
        }
        if (!is_a($class, UpgradeInterface::class, true)) {
            throw new Exception(__(
                '模块 %{1} 的 %{2} 必须实现 %{3}，并提供 setup()（不是 upgrade()）',
                [$module->getName(), $class, UpgradeInterface::class]
            ));
        }
        if (!method_exists($class, 'setup')) {
            throw new Exception(__(
                '模块 %{1} 的 %{2} 缺少可调用的 setup(Data\\Setup, Data\\Context) 方法',
                [$module->getName(), $class]
            ));
        }
    }

    private function resolveSetupClass(Module $module, string $shortName): string
    {
        return $module->getNamespacePath() . '\\Setup\\' . $shortName;
    }
}
