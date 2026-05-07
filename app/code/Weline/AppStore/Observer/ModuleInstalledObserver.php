<?php
declare(strict_types=1);

namespace Weline\AppStore\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\AppStore\Model\AppStoreInstalledModule;

/**
 * 模块安装后观察者
 *
 * 监听模块安装事件，更新 AppStore 已安装模块记录
 */
class ModuleInstalledObserver implements ObserverInterface
{
    /**
     * 处理模块安装后事件
     *
     * @param Event $event
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');

        $moduleName = $data['module_name'] ?? null;
        if (!$moduleName) {
            return;
        }

        // 更新已安装模块记录
        $this->updateInstalledModule($moduleName, $data);
    }

    /**
     * 更新已安装模块记录
     *
     * @param string $moduleName 模块名
     * @param array $data 模块数据
     */
    private function updateInstalledModule(string $moduleName, array $data): void
    {
        try {
            /** @var AppStoreInstalledModule $installedModule */
            $installedModule = ObjectManager::getInstance(AppStoreInstalledModule::class);
            $installedModule->load($moduleName, AppStoreInstalledModule::schema_fields_module_name);

            // 更新模块信息
            $installedModule->setModuleName($moduleName);
            $installedModule->setVersion($data['version'] ?? '1.0.0');

            if (isset($data['display_name'])) {
                $installedModule->setDisplayName($data['display_name']);
            }

            if (isset($data['description'])) {
                $installedModule->setDescription($data['description']);
            }

            if (isset($data['icon'])) {
                $installedModule->setIcon($data['icon']);
            }

            if (isset($data['license_key'])) {
                $installedModule->setLicenseKey($data['license_key']);
            }

            if (isset($data['platform_module_id'])) {
                $installedModule->setPlatformModuleId($data['platform_module_id']);
            }

            $installedModule->setInstalledAt(date('Y-m-d H:i:s'));
            $installedModule->save();

        } catch (\Exception $e) {
            // 记录错误日志
            \Weline\Framework\App\Env::log_error(
                'appstore/module_installed.log',
                'AppStore update installed module failed: ' . $e->getMessage()
                . '; module_name=' . $moduleName
                . '; data=' . \json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR)
            );
        }
    }
}
