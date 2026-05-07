<?php
declare(strict_types=1);

namespace Weline\AppStore\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\AppStore\Service\ModuleInstallerService;

/**
 * 下载完成观察者
 *
 * 监听下载完成事件，检测是否为可安装模块并自动触发安装
 */
class DownloadCompleteObserver implements ObserverInterface
{
    /**
     * 处理下载完成事件
     *
     * @param Event $event
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');

        // 确保 data 是数组
        if (!is_array($data)) {
            return;
        }

        // 检查是否为可安装模块
        if (!$this->isInstallableModule($data)) {
            return;
        }

        // 执行安装
        $result = $this->installModule($data);

        // 更新事件数据（必须通过 setData 更新）
        $data['install_result'] = $result;
        $data['installed'] = $result['success'] ?? false;
        $event->setData('data', $data);
    }

    /**
     * 检查是否为可安装模块
     *
     * @param array $data 下载数据
     * @return bool
     */
    private function isInstallableModule(array $data): bool
    {
        // 检查产品类型
        $productType = $data['product_type'] ?? null;
        if ($productType !== 'module') {
            return false;
        }

        // 检查可安装属性
        $installableAttr = $data['installable_module'] ?? null;
        if ($installableAttr !== 'yes' && $installableAttr !== 1 && $installableAttr !== true) {
            return false;
        }

        // 检查下载文件路径
        $downloadPath = $data['download_path'] ?? $data['file_path'] ?? null;
        if (!$downloadPath || !file_exists($downloadPath)) {
            return false;
        }

        return true;
    }

    /**
     * 安装模块
     *
     * @param array $data 下载数据
     * @return array 安装结果
     */
    private function installModule(array $data): array
    {
        try {
            /** @var ModuleInstallerService $installer */
            $installer = ObjectManager::getInstance(ModuleInstallerService::class);

            $downloadPath = $data['download_path'] ?? $data['file_path'];

            // 执行安装
            $result = $installer->install($downloadPath, [
                'license_key' => $data['license_key'] ?? null,
                'platform_module_id' => $data['module_id'] ?? 0,
            ]);

            return $result;

        } catch (\Exception $e) {
            // 记录错误日志
            \Weline\Framework\App\Env::log_error(
                'appstore/download_complete.log',
                'AppStore module install failed: ' . $e->getMessage()
                . '; data=' . \json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR)
            );

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
