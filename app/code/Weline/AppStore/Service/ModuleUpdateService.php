<?php
declare(strict_types=1);

namespace Weline\AppStore\Service;

use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

class ModuleUpdateService
{
    public function __construct(
        private ?ModuleInstallerService $installer = null
    ) {
    }

    public function update(AppStoreInstalledModule $module, array $update, string $currentDomain = ''): array
    {
        $moduleName = $module->getModuleName();
        if ($moduleName === '') {
            throw new Exception(__('模块不存在'));
        }

        if (empty($update['update_available'])) {
            throw new Exception(__('当前模块已是最新版本'));
        }

        $licenseKey = trim((string)($update['license_key'] ?? ''));
        if ($licenseKey === '') {
            $licenseKey = trim((string)($module->getLicenseKey() ?? ''));
        }

        $platformModuleId = (int)($update['platform_module_id'] ?? 0);
        if ($platformModuleId <= 0) {
            $platformModuleId = $module->getPlatformModuleId();
        }

        $latestVersion = trim((string)($update['latest_version'] ?? ''));
        if ($licenseKey === '') {
            throw new Exception(__('缺少许可证，无法下载更新包'));
        }
        if ($platformModuleId <= 0) {
            throw new Exception(__('缺少平台模块 ID，无法下载更新包'));
        }
        if ($latestVersion === '') {
            throw new Exception(__('平台未返回最新版本号'));
        }

        $installer = $this->getInstaller();
        $downloadResult = trim($currentDomain) !== ''
            ? $installer->downloadForDomain($currentDomain, $licenseKey, $platformModuleId, $latestVersion)
            : $installer->download($licenseKey, $platformModuleId, $latestVersion);
        $moduleInfo = is_array($downloadResult['module_info'] ?? null) ? $downloadResult['module_info'] : [];

        $installOptions = [
            'action' => 'upgrade',
            'previous_version' => $module->getVersion(),
            'license_key' => $licenseKey,
            'platform_module_id' => $platformModuleId,
            'download_log_id' => (int)($downloadResult['log_id'] ?? 0),
            'download_file_hash' => (string)($downloadResult['file_hash'] ?? ''),
            'download_file_size' => (int)($downloadResult['file_size'] ?? 0),
            'bound_domain' => (string)($downloadResult['download_domain'] ?? $currentDomain),
            'display_name' => (string)($update['display_name'] ?? $module->getDisplayName() ?? $moduleName),
            'description' => (string)($update['description'] ?? $module->getDescription() ?? ''),
        ];

        if (!empty($moduleInfo['display_name'])) {
            $installOptions['display_name'] = (string)$moduleInfo['display_name'];
        }
        if (!empty($moduleInfo['description'])) {
            $installOptions['description'] = (string)$moduleInfo['description'];
        }

        $result = $installer->install((string)$downloadResult['file_path'], $installOptions);
        $result['message'] = __('模块已更新到 %{1}', [$result['version'] ?? $latestVersion]);

        return $result;
    }

    private function getInstaller(): ModuleInstallerService
    {
        if (!$this->installer) {
            /** @var ModuleInstallerService $installer */
            $installer = ObjectManager::getInstance(ModuleInstallerService::class);
            $this->installer = $installer;
        }

        return $this->installer;
    }
}
