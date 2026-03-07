<?php
declare(strict_types=1);

/**
 * Weline Server - CLI 命令执行完成观察者
 * 
 * 监听 CLI 命令执行完成事件，通知 WLS Worker 重载
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

/**
 * CLI 命令执行完成观察者
 * 
 * 负责在 CLI 命令执行完成后，通知 WLS 重载。
 * 仅当命令匹配指定前缀时才触发重载（白名单）。
 * 重载信号发给 Master，由 Master 统一通知 Worker。
 * 
 * 通知机制：
 * - 优先：通过 IPC 控制通道（TCP）发送命令给 Master
 * - 回退：向 Master 发 SIGHUP，或直接向 Worker 发 SIGUSR1
 * 
 * 重载类型：
 * - code：代码重载（Worker 优雅退出后由 Master 重启，加载新代码）
 * - cache：缓存清理（仅清理 opcache、ObjectManager 缓存等，不重启进程）
 */
class CliCommandExecutedObserver implements ObserverInterface
{
    
    /**
     * 重载类型：代码重载（Worker 重启）
     */
    public const RELOAD_TYPE_CODE = 'code';
    
    /**
     * 重载类型：仅清理缓存（不重启）
     */
    public const RELOAD_TYPE_CACHE = 'cache';
    
    /**
     * 不触发 WLS 重载的命令前缀（即使匹配了下方前缀也跳过）
     */
    private const SKIP_RELOAD_COMMANDS = ['phpunit:'];

    /**
     * env 中 server.reload_prefixes 的默认值
     * 格式：['code' => ['setup:', 'command:'], 'cache' => ['cache:']]
     */
    private const DEFAULT_RELOAD_PREFIXES = [
        'code' => ['setup:', 'command:'],
        'cache' => ['cache:'],
    ];

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $command = (string) ($event->getData('command') ?? '');
        if ($command === '') {
            return;
        }
        // 前缀匹配：如果命令以任何跳过前缀开头，则跳过重载
        foreach (self::SKIP_RELOAD_COMMANDS as $skipPrefix) {
            if (\str_starts_with($command, $skipPrefix)) {
                return;
            }
        }

        $prefixes = $this->getReloadPrefixes();
        $codePrefixes = $prefixes['code'] ?? [];
        $cachePrefixes = $prefixes['cache'] ?? [];

        // 白名单：仅配置中的前缀触发重载
        $reloadType = null;
        foreach ($codePrefixes as $prefix) {
            if (\str_starts_with($command, $prefix)) {
                $reloadType = self::RELOAD_TYPE_CODE;
                break;
            }
        }
        if ($reloadType === null) {
            foreach ($cachePrefixes as $prefix) {
                if (\str_starts_with($command, $prefix)) {
                    $reloadType = self::RELOAD_TYPE_CACHE;
                    break;
                }
            }
        }
        if ($reloadType === null) {
            return;
        }

        /** @var Printing $printer */
        $printer = ObjectManager::getInstance(Printing::class);
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);

        // 优先使用 IPC 控制通道（TCP，跨平台即时生效）：广播到所有运行中的实例。
        $ipcReloadType = ($reloadType === self::RELOAD_TYPE_CACHE) ? 'cache' : 'code';
        $instanceNames = $manager->listInstanceNames();
        $ipcNotified = 0;
        foreach ($instanceNames as $instanceName) {
            if (!$manager->isInstanceRunning($instanceName)) {
                continue;
            }
            if (MasterProcess::sendReloadCommand($instanceName, $ipcReloadType)) {
                $ipcNotified++;
            }
        }
        
        if ($ipcNotified > 0) {
            $typeLabel = $reloadType === self::RELOAD_TYPE_CACHE ? __('仅清缓存') : __('代码重载');
            $printer->note(__('WLS 重载：已通过 IPC 控制通道通知 Master（%{1}，%{2} 个实例）', [$typeLabel, $ipcNotified]));
            return;
        }
        
        // IPC 失败：回退到信号方式
        if ($reloadType === self::RELOAD_TYPE_CODE) {
            $masterPids = $manager->getRunningMasterPids();
            if (!empty($masterPids) && \defined('SIGHUP')) {
                $notified = 0;
                foreach ($masterPids as $pid) {
                    $pid = (int) $pid;
                    if ($pid > 0 && Processer::sendSignal($pid, SIGHUP, true)) {
                        $notified++;
                    }
                }
                if ($notified > 0) {
                    $printer->note(__('WLS 重载：已通知 Master（%{1} 个实例），由 Master 通知 Worker 重载', [$notified]));
                    return;
                }
            }
        }

        // 无 Master 时回退：直接通知 Worker
        $stats = $manager->getRunningStats();
        if ($stats['workers'] === 0) {
            $printer->note(__('WLS 重载：未检测到运行中的 WLS，已跳过'));
            return;
        }

        $allWorkerPids = $manager->getRunningWorkerPids();
        $totalWorkers = $stats['workers'];
        $reloadedWorkers = 0;

        if ($reloadType === self::RELOAD_TYPE_CODE && \defined('SIGUSR1')) {
            foreach ($allWorkerPids as $pid) {
                $pid = (int)$pid;
                if ($pid <= 0) {
                    continue;
                }
                if (Processer::isRunningByPid($pid) && Processer::sendSignal($pid, SIGUSR1, true)) {
                    $reloadedWorkers++;
                }
            }
        }

        $method = __('信号');
        $typeLabel = $reloadType === self::RELOAD_TYPE_CACHE ? __('仅清缓存') : __('代码重载');
        if ($reloadedWorkers > 0) {
            $printer->note(__('WLS 重载：已通知 %{1}/%{2} 个 Worker（%{3}，%{4}）', [$reloadedWorkers, $totalWorkers, $method, $typeLabel]));
        } else {
            $printer->note(__('WLS 重载：所有通知方式均失败，请检查 WLS 是否运行中'));
        }
    }

    /**
     * 从 env server.reload_prefixes 读取重载前缀，未配置时使用默认值
     * 格式：['code' => ['setup:', 'command:'], 'cache' => ['cache:']]
     *
     * @return array{code: string[], cache: string[]}
     */
    private function getReloadPrefixes(): array
    {
        $server = Env::getInstance()->getConfig('server');
        $configured = \is_array($server['reload_prefixes'] ?? null) ? $server['reload_prefixes'] : [];
        $default = self::DEFAULT_RELOAD_PREFIXES;
        return [
            'code' => \is_array($configured['code'] ?? null) ? $configured['code'] : $default['code'],
            'cache' => \is_array($configured['cache'] ?? null) ? $configured['cache'] : $default['cache'],
        ];
    }
    
    /**
     * 触发 WLS 重载（供外部调用）
     * 
     * 优先使用 IPC 控制通道，失败时回退到信号方式。
     * 
     * @param string $type 重载类型：code（代码重载/重启）或 cache（仅清缓存）
     */
    public static function triggerReload(string $type = self::RELOAD_TYPE_CODE): void
    {
        $reloadType = ($type === self::RELOAD_TYPE_CACHE) ? 'cache' : 'code';
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        foreach ($manager->listInstanceNames() as $instanceName) {
            if (!$manager->isInstanceRunning($instanceName)) {
                continue;
            }
            MasterProcess::sendReloadCommand($instanceName, $reloadType);
        }
    }
}
