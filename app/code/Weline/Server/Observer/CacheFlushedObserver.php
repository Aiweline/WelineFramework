<?php
declare(strict_types=1);

/**
 * Weline Server - 缓存清理观察者
 *
 * 监听 CacheFactory 的 flush/clear 事件，通知 WLS Worker 重载内存缓存。
 * 在 HTTP 请求和 CLI 环境下均可触发（CLI 环境下 CliCommandExecutedObserver 已覆盖 cache: 命令，
 * 本观察者主要服务于 HTTP 请求中的缓存清理场景，如主题发布、后台操作等）。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

/**
 * 缓存清理观察者
 *
 * 当 CacheFactory::flush() / clear() 被调用后触发。
 * 通知 WLS Master 执行缓存重载（不重启 Worker，仅清理 opcache、内存缓存等）。
 * 优先通过 IPC 控制通道发送命令，回退到信号方式。
 */
class CacheFlushedObserver implements ObserverInterface
{
    /**
     * 防止同一请求内重复通知（多次 flush 只发送一次重载命令）
     */
    private static bool $notifiedInRequest = false;

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 同一请求内只通知一次
        if (self::$notifiedInRequest) {
            return;
        }

        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);

        // 没有运行中的 WLS Worker 则跳过
        if (!$manager->hasRunningWorkers()) {
            return;
        }

        self::$notifiedInRequest = true;

        // 通知 Worker 重载缓存（不重启进程）
        $this->notifyWorkersToReload($manager);
    }

    /**
     * 通知 WLS 重载缓存：优先 IPC，回退信号
     */
    private function notifyWorkersToReload(ServerInstanceManager $manager): void
    {
        // 优先使用 IPC 控制通道
        $ipcSuccess = MasterProcess::sendReloadCommand('default', 'cache');
        if ($ipcSuccess) {
            return;
        }

        // 回退：向 Master 发 SIGHUP
        $masterPids = $manager->getRunningMasterPids();
        if (!empty($masterPids) && \defined('SIGHUP')) {
            foreach ($masterPids as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    Processer::sendSignal($pid, SIGHUP, true);
                }
            }
            return;
        }

        // 无 Master 时回退：直接向 Worker 发 SIGUSR1
        $allWorkerPids = $manager->getRunningWorkerPids();
        if (\defined('SIGUSR1')) {
            foreach ($allWorkerPids as $pid) {
                $pid = (int) $pid;
                if ($pid > 0 && Processer::isRunningByPid($pid)) {
                    Processer::sendSignal($pid, \SIGUSR1, true);
                }
            }
        }
    }

    /**
     * 重置请求级状态（WLS 模式下由 StateManager 调用）
     */
    public static function resetRequestState(): void
    {
        self::$notifiedInRequest = false;
    }
}
