<?php
declare(strict_types=1);

/**
 * Weline Server - 配置变更观察者
 *
 * 监听 SystemConfig 配置变更事件，当服务器相关配置变更时通知 WLS Worker
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\WlsInstanceRegistry;

/**
 * 配置变更观察者
 *
 * 监听配置变更事件，通知运行中的 WLS 重载。
 * 优先向 Master 发送 SIGHUP，由 Master 统一通知 Worker；无 Master 时写重载标记或向 Worker 发信号。
 */
class ConfigChangedObserver implements ObserverInterface
{
    /**
     * 需要触发 WLS 重载的模块前缀
     * 只有这些模块的配置变更才会触发 Worker 重载
     */
    private const RELOAD_TRIGGER_MODULES = [
        'Weline_Server',
        'Weline_Framework',
    ];

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $module = (string) ($event->getData('module') ?? '');
        $key = (string) ($event->getData('key') ?? '');

        // 只处理服务器相关模块的配置变更
        $shouldReload = false;
        foreach (self::RELOAD_TRIGGER_MODULES as $prefix) {
            if (\str_starts_with($module, $prefix)) {
                $shouldReload = true;
                break;
            }
        }

        if (!$shouldReload) {
            return;
        }

        /** @var WlsInstanceRegistry $registry */
        $registry = ObjectManager::getInstance(WlsInstanceRegistry::class);

        // 检查是否有运行中的 WLS Worker
        if (!$registry->hasRunningWorkers()) {
            return;
        }

        // 通知 Worker 重载
        $this->notifyWorkersToReload($registry, $module, $key);
    }

    /**
     * 通知重载：优先使用 IPC 控制通道，回退到信号方式
     */
    private function notifyWorkersToReload(WlsInstanceRegistry $registry, string $module, string $key): void
    {
        // 优先使用 IPC 控制通道
        $ipcSuccess = MasterProcess::sendReloadCommand('default', 'cache');
        if ($ipcSuccess) {
            return;
        }
        
        // 回退：信号方式
        $masterPids = $registry->getRunningMasterPids();
        if (!empty($masterPids) && \defined('SIGHUP')) {
            foreach ($masterPids as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    Processer::sendSignal($pid, SIGHUP, true);
                }
            }
            return;
        }

        $allWorkerPids = $registry->getRunningWorkerPids();
        if (\defined('SIGUSR1')) {
            foreach ($allWorkerPids as $pid) {
                $pid = (int) $pid;
                if ($pid > 0 && Processer::isRunningByPid($pid)) {
                    Processer::sendSignal($pid, \SIGUSR1, true);
                }
            }
        }
    }
}
