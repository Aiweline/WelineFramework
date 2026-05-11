<?php
declare(strict_types=1);

/**
 * Weline Server - 停止命令
 * 
 * 发送停止信号给 Master，由 Orchestrator 统一处理所有子进程的停止。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Console\Console\Server\Stop as CliStop;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\CliServerService;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\SharedStateServiceManager;

/**
 * server:stop - 停止常驻内存服务器
 * 
 * 架构：命令只负责发送信号，所有停止逻辑由 Orchestrator 处理
 */
class Stop extends CommandAbstract
{
    private const STOP_LOCK_TIMEOUT = 5;

    private $stopLockHandle = null;
    private string $stopLockFile = '';
    /** @var array<int, bool> */
    private array $stopPidRunningCache = [];
    /** @var array<int, string> */
    private array $stopProcessCommandLineCache = [];
    /** @var array<int, string> */
    private array $stopProcessNameCache = [];
    /** @var array<int, bool> */
    private array $stopWelineProcessCache = [];
    /** @var array<int, bool> */
    private array $stopProcessManagerCreatedCache = [];
    /** @var array<int, int> */
    private array $stopParentPidCache = [];
    /** @var array<string, list<int>> */
    private array $recoverableConfiguredPortsCache = [];
    /** @var array<int, array{in_use?:bool,pid?:int,pid_running?:bool,is_weline?:bool,state?:string}> */
    private array $recoverablePortOccupantCache = [];
    /** @var array<string, string> */
    private array $recoverablePortHeadersCache = [];
    /** @var array<string, list<int>> */
    private array $recoverableManagedPidsCache = [];
    /** @var array<string, list<int>> */
    private array $residualPrefixPidsCache = [];
    /** @var array<string, list<int>> */
    private array $directForceStopCandidatePidsCache = [];
    private bool $lastResidualCleanupComplete = true;
    private bool $lastIpcStopFlowStillActive = false;

    /** IPC 等待超时（秒）- 与 Windows 一致，不长时间等待，超时后强制杀进程 */
    private const IPC_TIMEOUT = 15;
    private const IPC_FORCE_TIMEOUT = 3;

    private const RESIDUAL_CLEANUP_MAX_ATTEMPTS = 3;
    private const RESIDUAL_CLEANUP_RETRY_USEC = 300000;

    /** IPC 硬超时（秒）- 避免进度持续刷新时无限等待 */
    private const IPC_HARD_TIMEOUT_WIN = 45;
    private const IPC_HARD_TIMEOUT_LINUX = 30;
    /** `-f --ipc`：短硬超时，避免假死长等 */
    private const IPC_FORCE_HARD_TIMEOUT_WIN = 6;
    private const IPC_FORCE_HARD_TIMEOUT_LINUX = 5;
    
    /** 子进程全部退出后等待 Master 退出的最大时间（秒）- Linux 上 Master 清理索引/退出主循环较慢，需更长超时 */
    private const MASTER_EXIT_TIMEOUT_WIN = 5;
    private const MASTER_EXIT_TIMEOUT_LINUX = 15;
    
    /** IPC 消息颜色常量 */
    private const IPC_COLOR_TAG = 'Blue';       // [IPC] 标签颜色
    private const IPC_COLOR_SUCCESS = 'Green';  // 上报成功：进程排水完成、已退出、已断开
    private const IPC_COLOR_DRAIN = 'Yellow';   // 通知重载/排水：广播 DRAIN、RELOAD
    private const IPC_COLOR_STOP = 'Red';       // 通知停止：广播 SHUTDOWN、强制终止
    private const IPC_COLOR_INFO = 'Blue';      // 一般信息：连接中、等待中
    private const IPC_COLOR_ERROR = 'Red';      // 错误/失败消息
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->resetStopRuntimeCaches();

        // 欢迎语
        $this->printWelcome();

        $instanceName = $this->parseInstanceName($args);
        $stopAll = isset($args['all']) || isset($args['a']);
        $force = isset($args['force']) || isset($args['f']);
        $forceIpc = isset($args['ipc']) || isset($args['force-ipc']) || isset($args['force_ipc']);
        $fastLocal = isset($args['fast-local'])
            || isset($args['fast_local'])
            || ($force && !$forceIpc);

        if ($stopAll) {
            $this->stopAllInstances($force, $fastLocal);
            return;
        }

        if (!$this->acquireStopLock($instanceName)) {
            $this->printer->warning(__('另一个 server:stop 任务正在处理中，请稍后再试。'));
            $this->printer->note(__('若锁文件长期存在，请确认停止任务是否已结束后再重试。'));
            return;
        }
        try {
            $this->stopInstance($instanceName, $force, $fastLocal);
        } finally {
            $this->releaseStopLock();
        }
    }
    
    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        array_shift($positionalArgs);
        
        return $positionalArgs[0] ?? 'default';
    }

    protected function acquireStopLock(string $instanceName, int $timeout = self::STOP_LOCK_TIMEOUT): bool
    {
        $lockDir = Env::VAR_DIR . 'server' . DS . 'locks' . DS;
        if (!\is_dir($lockDir)) {
            @\mkdir($lockDir, 0755, true);
        }

        $this->stopLockFile = $lockDir . 'stop_' . $instanceName . '.lock';
        $fp = @\fopen($this->stopLockFile, 'c');
        if ($fp === false) {
            return false;
        }

        $startTime = \time();
        while (\time() - $startTime < $timeout) {
            if (\flock($fp, \LOCK_EX | \LOCK_NB)) {
                $this->stopLockHandle = $fp;
                @\ftruncate($fp, 0);
                @\fwrite($fp, \json_encode([
                    'pid' => \getmypid(),
                    'instance' => $instanceName,
                    'started_at' => \date('Y-m-d H:i:s'),
                    'command' => \implode(' ', $_SERVER['argv'] ?? []),
                ], JSON_PRETTY_PRINT));
                @\fflush($fp);
                return true;
            }
            SchedulerSystem::usleep(100000);
        }

        @\fclose($fp);
        return false;
    }

    protected function releaseStopLock(): void
    {
        if ($this->stopLockHandle !== null) {
            @\flock($this->stopLockHandle, \LOCK_UN);
            @\fclose($this->stopLockHandle);
            $this->stopLockHandle = null;
        }

        if ($this->stopLockFile !== '' && \is_file($this->stopLockFile)) {
            @\unlink($this->stopLockFile);
        }
    }

    private function resetStopRuntimeCaches(): void
    {
        $this->stopPidRunningCache = [];
        $this->stopProcessCommandLineCache = [];
        $this->stopProcessNameCache = [];
        $this->stopWelineProcessCache = [];
        $this->stopProcessManagerCreatedCache = [];
        $this->stopParentPidCache = [];
        $this->recoverableConfiguredPortsCache = [];
        $this->recoverablePortOccupantCache = [];
        $this->recoverablePortHeadersCache = [];
        $this->recoverableManagedPidsCache = [];
        $this->residualPrefixPidsCache = [];
        $this->directForceStopCandidatePidsCache = [];
        $this->lastResidualCleanupComplete = true;
        $this->lastIpcStopFlowStillActive = false;
    }

    private function invalidateStopRuntimeState(): void
    {
        Processer::clearPortCache();
        $this->stopPidRunningCache = [];
        $this->stopProcessCommandLineCache = [];
        $this->stopProcessNameCache = [];
        $this->stopWelineProcessCache = [];
        $this->stopProcessManagerCreatedCache = [];
        $this->stopParentPidCache = [];
        $this->recoverablePortOccupantCache = [];
        $this->recoverablePortHeadersCache = [];
        $this->recoverableManagedPidsCache = [];
        $this->residualPrefixPidsCache = [];
    }

    protected function wasLastResidualCleanupComplete(): bool
    {
        return $this->lastResidualCleanupComplete;
    }

    /**
     * 按端口查找占用该端口的本项目作用域 Weline Server 实例名。
     *
     * 若端口实际由其它项目作用域（不同 BP 哈希派生的 pXXXXXXXX）的 WLS 占用，
     * 一律返回 null —— 不冒充自家实例名，避免上层 -r -f 流程误停外项目。
     * 调用方需要识别外项目占用时，请改用 {@see self::findForeignWelineServerScopeByPort()}。
     */
    public function findWelineServerInstanceNameByPort(int $port): ?string
    {
        $portInspect = $this->inspectPortOccupantWithHistory($port);
        $runningWelineOnPort = (bool) ($portInspect['pid_running'] ?? false)
            && (bool) ($portInspect['is_weline'] ?? false);
        if (!$runningWelineOnPort) {
            return null;
        }
        if ($this->isSharedStateIndexedPort($port)) {
            return null;
        }

        $scope = (string) ($portInspect['scope'] ?? '');
        if ($scope !== '' && $scope !== MasterProcess::getProjectScopeToken()) {
            return null;
        }

        $instanceName = $this->getInstanceManager()->findRunningInstanceNameByPort($port);
        if ($instanceName !== null) {
            return $instanceName;
        }

        $instanceName = $this->findPersistedRecoverableInstanceNameByPort($port);
        if ($instanceName !== null) {
            return $instanceName;
        }

        return $this->findConfiguredRunningInstanceNameByPort($port);
    }

    /**
     * 若端口被其它项目作用域的 WLS 占用，则返回该外项目的作用域 token（如 pAAAAAAAA）。
     *
     * 命中条件：
     * - 端口正在被运行中的 weline 进程占用
     * - 进程名带有合规的项目作用域段（{@see Processer::extractProjectScopeFromProcessName()}）
     * - 该作用域与当前进程的 {@see MasterProcess::getProjectScopeToken()} 不一致
     *
     * 其它情况（端口空闲、自家占用、无作用域段的老版本进程）返回 null。
     */
    public function findForeignWelineServerScopeByPort(int $port): ?string
    {
        $portInspect = $this->inspectPortOccupantWithHistory($port);
        $runningWelineOnPort = (bool) ($portInspect['pid_running'] ?? false)
            && (bool) ($portInspect['is_weline'] ?? false);
        if (!$runningWelineOnPort) {
            return null;
        }

        $scope = (string) ($portInspect['scope'] ?? '');
        if ($scope === '' || $scope === MasterProcess::getProjectScopeToken()) {
            return null;
        }

        return $scope;
    }

    /**
     * 若指定端口被 Weline Server 占用则停止该实例
     */
    public function stopWelineServerOnPort(int $port): bool
    {
        $name = $this->findWelineServerInstanceNameByPort($port);
        if ($name === null) {
            return false;
        }
        $this->stopInstance($name, true, true);
        return true;
    }

    /**
     * 按端口强杀占用该端口的 WLS 进程（实例文件不存在或未匹配时兜底，确保“系统创建的 WLS 进程”可被结束）
     *
     * @return bool 若识别到 WLS 进程并已发送终止则 true，否则 false
     */
    public function killWlsProcessOnPort(int $port): bool
    {
        $pid = $this->getPortProcessId($port);
        if ($pid <= 0 || !$this->isStopPidRunning($pid)) {
            return false;
        }
        $pname = $this->getProcessPnameByPid($pid);
        $isWls = \str_contains($pname, 'weline-wls')
            || \str_contains($pname, 'weline-master')
            || $this->isRecoverableWlsPortResponder($port);
        if (!$isWls) {
            return false;
        }

        return $this->terminateWlsPortProcess($port, $pid);
        /*
        $this->printer->note(__('检测到端口 %{1} 被 WLS 进程占用 (PID: %{2})，正在结束…', [$port, $pid]));
        $rootPid = $this->resolveManagedStopRootPid($pid);
        $killPid = $rootPid > 0 ? $rootPid : $pid;
        if ($killPid !== $pid) {
            $this->printer->note(__('  ROOT PID: %{1}', [$killPid]));
        }

        if ($this->killManagedProcessTreeForStop($killPid)) {
            return true;
        }

        if ($killPid !== $pid) {
            return $this->killManagedProcessTreeForStop($pid);
        }

        return false;
        */
    }
    
    /**
     * 停止单个实例
     * 
     * 策略：
     * 1. 通过 IPC 发送 STOP 命令给 Master
     * 2. Master 的 Orchestrator 会：广播 DRAIN → 广播 SHUTDOWN → 等待退出 → 清理
     * 3. 如果 IPC 超时，强制杀死 Master（Orchestrator 会处理残留）
     */
    protected function stopInstance(string $name, bool $force = false, bool $fastLocal = false): void
    {
        // CLI 服务器委托给专用处理
        $nameLower = strtolower($name);
        if ($nameLower === 'cli' || $nameLower === 'cli-server') {
            $this->stopCliServer($force);
            return;
        }

        // 通过 ServerInstanceManager 获取实例信息（统一入口）
        $manager = $this->getInstanceManager();
        $instanceInfo = $manager->getInstanceInfo($name, false);
        
        if ($instanceInfo === null) {
            $this->printer->warning(__('实例 [%{1}] 不存在', [$name]));
            if ($this->hasRecoverableManagedProcessHint($name) || $this->hasRecoverableConfiguredPortHint($name)) {
                $recovered = $this->cleanupRecoverableProcessesWithoutInstanceFile($name);
                if ($recovered > 0) {
                    $this->printer->success(__('已在实例文件缺失场景下安全清理 %{1} 个受管 WLS 进程', [$recovered]));
                }
            } else {
                $this->printer->note(__('使用 server:listing 查看所有实例'));
            }
            // 清理可能残留的启动锁（如上次 server:start 崩溃遗留），便于后续启动
            return;
        }
        
        $masterPid = $instanceInfo->masterPid;
        $controlPort = $instanceInfo->controlPort;
        $startupPhase = $this->resolveInstanceStartupPhase($manager->getRawInstanceData($name) ?? []);
        
        $this->printer->setup(__('停止 Weline Server'));
        echo "\n";
        
        // 检查 Master 是否存在
        if (!$this->isMasterProcessAvailableForStop($instanceInfo)) {
            $this->printer->warning(__('Master 进程不存在 (PID: %{1})', [$masterPid]));
            $this->showInstanceInfo($instanceInfo);
            $includeSharedStateCleanup = $force
                || $fastLocal
                || $this->shouldBypassGracefulStopDuringBootstrap($startupPhase)
                || $this->hasPendingStartupServices($instanceInfo);

            if ($this->isControlPortAvailableForStop($instanceInfo)) {
                $this->printer->note(__('Master PID 缺失但控制端口仍可用，先尝试通过 IPC 自停...'));
                $ipcSuccess = $this->sendStopViaIpcAndWait($name, $controlPort, $masterPid, $force);
                if ($ipcSuccess) {
                    $this->printer->success(__('控制端口已接受 STOP，继续复核残留进程 ✓'));
                    $this->runResidualCleanupPairWithRetry($name, $instanceInfo, $includeSharedStateCleanup);
                    if (!$this->wasLastResidualCleanupComplete()) {
                        $this->printer->warning(__('Instance [%{1}] still has residual WLS processes; keeping instance metadata for continued cleanup.', [$name]));
                        return;
                    }
                    $this->releaseSharedStateConsumersForInstance($name);
                    $manager->deleteInstance($name);
                    $this->cleanupPidFiles($name, $instanceInfo);
                    $this->releaseStartLock($name);
                    $this->printer->success(__('实例元数据已标记停止并保留 ✓'));
                    return;
                }
            }

            // 清理可能残留的进程和文件（含按 PID 与按名前缀，确保 Worker/Dispatcher 等全部退出）
            $this->runResidualCleanupPairWithRetry($name, $instanceInfo, $includeSharedStateCleanup);
            if (!$this->wasLastResidualCleanupComplete()) {
                $this->printer->warning(__('Instance [%{1}] still has residual WLS processes; keeping instance metadata for continued cleanup.', [$name]));
                return;
            }
            $this->releaseSharedStateConsumersForInstance($name);
            $manager->deleteInstance($name);
            $this->cleanupPidFiles($name, $instanceInfo);
            $this->releaseStartLock($name);
            $this->printer->success(__('实例元数据已标记停止并保留 ✓'));
            return;
        }
        
        // 显示实例信息
        $this->showInstanceInfo($instanceInfo);
        echo "\n";

        if (!$fastLocal && $this->isMasterProcessAvailableForStop($instanceInfo)) {
            if (!$this->validateInstanceForIpcStop($instanceInfo)) {
                $this->printer->warning(__('实例 Master PID 或控制端口归属校验未通过，跳过 IPC，改用本地清理。'));
                $fastLocal = true;
            }
        }

        if ($fastLocal) {
            $this->printer->note(__('快速清场模式：跳过 IPC 排水，并发终止旧实例子进程...'));
            $terminated = $this->terminateDirectForceStopCandidatePids($instanceInfo);
            if ($terminated > 0) {
                SchedulerSystem::usleep(500000);
                $this->cleanupStaleRecoverableProcessPidFilesForPids(
                    $this->collectDirectForceStopCandidatePids($instanceInfo)
                );
                $this->releaseSharedStateConsumersForInstance($name);
                $manager->deleteInstance($name);
                $this->cleanupPidFiles($name, $instanceInfo);
                $this->releaseStartLock($name);
                echo "\n";
                $this->printer->success(
                    $force
                        ? __('实例 [%{1}] 已停止（-f 强制模式） ✓', [$name])
                        : __('实例 [%{1}] 已停止（快速清场） ✓', [$name])
                );
                $this->printGoodbye(true);
                return;
            }
            $this->runResidualCleanupPairWithRetry($name, $instanceInfo, true);
            if (!$this->wasLastResidualCleanupComplete()) {
                echo "\n";
                $this->printer->warning(__('实例 [%{1}] 仍有残留进程，已保留实例文件以便继续清理。', [$name]));
                return;
            }
            $this->releaseSharedStateConsumersForInstance($name);
            $manager->deleteInstance($name);
            $this->cleanupPidFiles($name, $instanceInfo);
            $this->releaseStartLock($name);
            echo "\n";
            $this->printer->success(
                $force
                    ? __('实例 [%{1}] 已停止（-f 强制模式） ✓', [$name])
                    : __('实例 [%{1}] 已停止（快速清场） ✓', [$name])
            );
            $this->printGoodbye(true);
            return;
        }

        if (
            $this->shouldBypassGracefulStopDuringBootstrap($startupPhase)
            || $this->hasPendingStartupServices($instanceInfo)
        ) {
            $phaseLabel = $startupPhase !== '' ? $startupPhase : 'bootstrapping';
            $this->printer->note(\sprintf(
                'Instance startup_phase=%s, skip IPC graceful stop and use local cleanup.',
                $phaseLabel
            ));
            if ($masterPid > 0) {
                Processer::killProcessTreeByPid($masterPid, true);
                SchedulerSystem::usleep(500000);
            }
            $this->runResidualCleanupPairWithRetry($name, $instanceInfo, true);
            if (!$this->wasLastResidualCleanupComplete()) {
                $this->printer->warning(__('Instance [%{1}] still has residual WLS processes; keeping instance metadata for continued cleanup.', [$name]));
                return;
            }
            $this->releaseSharedStateConsumersForInstance($name);
            $manager->deleteInstance($name);
            $this->cleanupPidFiles($name, $instanceInfo);
            $this->releaseStartLock($name);
            echo "\n";
            $this->printer->success(\sprintf('Instance [%s] stopped (bootstrap cleanup).', $name));
            return;
        }

        // 通过 IPC 发送 STOP 命令并等待完整停止（`-f --ipc`：仍走 IPC，但 Master 侧按强制停机处理）
        if ($force) {
            $this->printer->note(__('-f --ipc：跳过排水，通过 IPC 发起停止，响应后并发清理当前实例进程...'));
        }
        $this->printer->note(__('发送 STOP 命令给 Master (通过 IPC)...'));
        $ipcSuccess = $this->sendStopViaIpcAndWait($name, $controlPort, $masterPid, $force);
        
        if ($ipcSuccess) {
            $this->printer->success(__('所有子进程已完整退出 ✓'));
            $this->runResidualCleanupPairWithRetry($name, $instanceInfo, false);
            if (!$this->wasLastResidualCleanupComplete()) {
                $this->printer->warning(__('Instance [%{1}] still has residual WLS processes; keeping instance metadata for continued cleanup.', [$name]));
                return;
            }
        } else {
            if ($this->lastIpcStopFlowStillActive) {
                $this->printer->warning(__('Stop flow is still running in Master after the CLI wait ended; keeping instance metadata and skipping local cleanup.'));
                return;
            }
            // IPC 失败，强制杀死 Master 并彻底清理该实例下所有进程（含 Worker/Dispatcher 等）
            $this->printer->warning(__('IPC 超时，强制终止 Master 并清理该实例下所有进程...'));
            Processer::killProcessTreeByPid($masterPid, true);
            SchedulerSystem::usleep(500000);
            
            $this->runResidualCleanupPairWithRetry($name, $instanceInfo, true);
            if (!$this->wasLastResidualCleanupComplete()) {
                $this->printer->warning(__('Instance [%{1}] still has residual WLS processes; keeping instance metadata for continued cleanup.', [$name]));
                return;
            }
        }
        
        // 从共享 Session/Memory 注册表移除本实例消费者，避免误导其它工具
        $this->releaseSharedStateConsumersForInstance($name);

        // 标记实例停止；保留实例 JSON 供后续控制面恢复和审计使用。
        $manager->deleteInstance($name);
        
        // 清理 PID 文件
        $this->cleanupPidFiles($name, $instanceInfo);
        
        // 释放启动锁
        $this->releaseStartLock($name);
        
        echo "\n";
        $this->printer->success(__('实例 [%{1}] 已停止 ✓', [$name]));

        // 打印成功结束语
        $this->printGoodbye(true);
    }
    
    /**
     * 获取实例管理器
     */
    protected function getInstanceManager(): ServerInstanceManager
    {
        return ObjectManager::getInstance(ServerInstanceManager::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getRawStopInstanceData(string $name): ?array
    {
        return $this->getInstanceManager()->getRawInstanceData($name);
    }

    /**
     * @param array<string, mixed> $instanceData
     */
    protected function resolveInstanceStartupPhase(array $instanceData): string
    {
        return \trim((string) ($instanceData['startup_phase'] ?? ''));
    }

    protected function shouldBypassGracefulStopDuringBootstrap(string $startupPhase): bool
    {
        return $startupPhase !== '' && $startupPhase !== 'running';
    }

    protected function hasPendingStartupServices(ServerInstanceInfo $info): bool
    {
        foreach ($info->services as $service) {
            if (!\in_array($service->role, ['worker', 'dispatcher', 'redirect', 'maintenance'], true)) {
                continue;
            }

            if ($service->state !== ServiceInstance::STATE_READY) {
                return true;
            }

            if ($service->ipcClientId === null) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * 显示实例信息（统一入口，使用 ServerInstanceInfo 对象）
     *
     * 所有信息都来自 ServerInstanceManager，确保一致性。
     */
    protected function isMasterProcessAvailableForStop(ServerInstanceInfo $info): bool
    {
        if ($info->masterPid <= 0) {
            return false;
        }

        $exists = $this->masterProcessExists($info->masterPid);
        if ($this->hasMasterExitedFast($info->masterPid) && !$exists) {
            return false;
        }

        return $exists;
    }

    protected function isControlPortAvailableForStop(ServerInstanceInfo $info): bool
    {
        if ($info->controlPort <= 0) {
            return false;
        }

        $inspect = $this->inspectRecoverablePortOccupant($info->controlPort);
        if (!($inspect['in_use'] ?? false)) {
            return false;
        }

        return ((bool)($inspect['pid_running'] ?? false) && (bool)($inspect['is_weline'] ?? false))
            || $this->isRecoverableManagedPort($info->controlPort, $inspect, $info);
    }

    /**
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     */
    protected function isMasterProcessAvailableForStopFromRuntime(
        ServerInstanceInfo $info,
        array $processInfoMap,
        bool $hasExitedFast
    ): bool {
        if ($info->masterPid <= 0) {
            return false;
        }

        if (!($processInfoMap[$info->masterPid]['exists'] ?? false)) {
            return false;
        }

        return !$hasExitedFast;
    }

    protected function hasMasterExitedFast(int $masterPid): bool
    {
        return Processer::hasExitedFast($masterPid);
    }

    protected function isMasterPidMissingFromIndex(int $masterPid): bool
    {
        if ($masterPid <= 0) {
            return true;
        }

        $pidIndex = Processer::readPidIndex();

        return !isset($pidIndex[$masterPid]);
    }

    protected function masterProcessExists(int $masterPid): bool
    {
        return Processer::processExists($masterPid);
    }

    protected function showInstanceInfo(ServerInstanceInfo $info): void
    {
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                   停止服务器实例                               ║'));
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $info->name));
        $this->printer->note(\sprintf('║  Master PID：%-48s║', $info->masterPid > 0 ? $info->masterPid : '(未运行)'));
        $this->printer->note(\sprintf('║  控制端口：%-50s║', $info->controlPort > 0 ? $info->controlPort : '(未配置)'));
        $this->printer->note(\sprintf('║  监听地址：%-50s║', $info->getListenAddress()));
        $this->printer->note(\sprintf('║  SSL 状态：%-50s║', $info->sslEnabled ? '已启用 (HTTPS)' : '未启用 (HTTP)'));
        
        if ($info->httpRedirectPort > 0) {
            $this->printer->note(\sprintf('║  HTTP 跳转：%-49s║', ":{$info->httpRedirectPort} → :{$info->port}"));
        }
        
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        
        // 显示所有服务实例（已按优先级排序）
        $currentRole = '';
        $roleInstances = [];
        
        // 按角色分组
        foreach ($info->services as $service) {
            $roleInstances[$service->role][] = $service;
        }
        
        foreach ($roleInstances as $role => $services) {
            $count = \count($services);
            $displayName = $services[0]->displayName;
            
            $pids = [];
            $ports = [];
            foreach ($services as $service) {
                if ($service->pid > 0) {
                    $pids[] = $service->pid;
                }
                if ($service->port !== null && $service->port > 0) {
                    $ports[] = $service->port;
                }
            }
            
            $pidStr = !empty($pids) ? \implode(',', $pids) : '(无 PID)';
            $portStr = !empty($ports) ? \implode(',', $ports) : '-';
            
            $line = \sprintf('║  %s (%d): PID=%s, Port=%s', $displayName, $count, $pidStr, $portStr);
            $this->printer->note(\sprintf('%-63s║', $line));
        }
        
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  启动时间：%-50s║', $info->startedAt ?: '(未知)'));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
    }
    
    /**
     * 按 PID + 进程名前缀各扫一遍，控制台只输出一组「清理残留进程」提示。
     */
    protected function runResidualCleanupPair(string $name, ServerInstanceInfo $info): void
    {
        $this->printer->note(__('清理残留进程...'));

        // 1) 先按已知 PID（实例信息 + PID 索引）直接终止
        $pids = $this->collectResidualCleanupCandidatePids($name, $info);
        $killedByPid = $this->terminateResidualProcesses($pids, true);
        $killedByPrefix = $this->terminateCurrentInstanceProcessPrefixes($name);

        Processer::cleanupStalePidFiles();

        $totalKilled = $killedByPid + $killedByPrefix;
        if ($totalKilled > 0) {
            $this->printer->success(__('  已处理 %{1} 个进程', [$totalKilled]));
        } else {
            $this->printer->note(__('  无残留进程'));
        }
    }

    /**
     * 根据 ServerInstanceInfo 清理残留进程
     *
     * 优化策略：优先使用已知 PID 直接杀（快速），随后把当前实例 scoped 前缀交给 Processer 批量树杀
     *
     * @param bool $quiet 为 true 时不打印，仅返回终止/尝试数（供 runResidualCleanupPair 合并输出）
     * @return int 已发送终止信号的 PID 数（quiet）或同上；非 quiet 时与原先展示一致
     */
    protected function runResidualCleanupPairWithRetry(
        string $name,
        ServerInstanceInfo $info,
        bool $includeSharedState = false
    ): void
    {
        $this->lastResidualCleanupComplete = false;
        $this->printer->note(__('清理残留进程...'));

        $attempt = 0;
        $totalKilled = 0;
        $remainingPorts = [];
        $remainingPids = [];

        while (++$attempt <= self::RESIDUAL_CLEANUP_MAX_ATTEMPTS) {
            $knownCandidatePids = $this->collectResidualCleanupCandidatePids($name, $info, $includeSharedState);
            $killedThisAttempt = $this->runResidualCleanupPass($name, $info, true, true, $includeSharedState);
            $totalKilled += $killedThisAttempt;
            if ($killedThisAttempt > 0) {
                $this->invalidateStopRuntimeState();
            }

            $remainingPids = $this->collectRunningResidualPids($knownCandidatePids);
            if (!empty($remainingPids)) {
                if ($killedThisAttempt === 0) {
                    break;
                }
                if ($attempt < self::RESIDUAL_CLEANUP_MAX_ATTEMPTS) {
                    SchedulerSystem::usleep(self::RESIDUAL_CLEANUP_RETRY_USEC);
                }
                continue;
            }

            $remainingPorts = $this->collectRemainingRecoverableWlsPorts($name, $info, $includeSharedState);
            $killedPorts = $this->cleanupRecoverableConfiguredPorts($remainingPorts, $info, $includeSharedState);
            $totalKilled += $killedPorts;
            if ($killedPorts > 0) {
                $remainingPorts = $this->collectRemainingRecoverableWlsPorts($name, $info, $includeSharedState);
            }
            $candidatePids = $this->collectResidualVerificationPids($name, $info, $remainingPorts, true, $includeSharedState);
            $remainingPids = $this->collectRunningResidualPids($candidatePids);

            if (empty($remainingPids) && empty($remainingPorts)) {
                if ($totalKilled > 0) {
                    $this->printer->success(__('  已清理 %{1} 个残留进程', [$totalKilled]));
                    if ($attempt > 1) {
                        $this->printer->note(__('  重试次数：%{1}', [$attempt]));
                    }
                } else {
                    $this->printer->note(__('  无残留进程'));
                }
                $this->lastResidualCleanupComplete = true;
                return;
            }

            if ($attempt < self::RESIDUAL_CLEANUP_MAX_ATTEMPTS) {
                SchedulerSystem::usleep(self::RESIDUAL_CLEANUP_RETRY_USEC);
            }
        }

        if (!empty($remainingPids) || !empty($remainingPorts)) {
            $this->printer->warning(
                __('残留清理未完全完成，残留进程: %{1}, 残留端口: %{2}', [
                    implode(',', $remainingPids),
                    implode(',', $remainingPorts),
                ])
            );
            $this->lastResidualCleanupComplete = false;
            return;
        }

        if ($totalKilled > 0) {
            $this->printer->success(__('  已清理 %{1} 个残留进程（复核结束）', [$totalKilled]));
        } else {
            $this->printer->note(__('  无残留进程'));
        }
        $this->lastResidualCleanupComplete = true;
    }

    protected function runResidualCleanupPass(
        string $name,
        ServerInstanceInfo $info,
        bool $quiet = false,
        bool $allowPrefixFallback = true,
        bool $includeSharedState = false
    ): int
    {
        if (!$quiet) {
            $this->printer->note(__('清理残留进程...'));
        }

        $pids = $this->collectResidualCleanupCandidatePids($name, $info, $includeSharedState);
        $killedByPid = $this->terminateResidualProcesses($pids, true);
        $killedByPrefix = $allowPrefixFallback
            ? $this->terminateCurrentInstanceProcessPrefixes($name, $includeSharedState)
            : 0;

        $this->cleanupStaleRecoverableProcessPidFilesForPids($pids);

        $totalKilled = $killedByPid + $killedByPrefix;
        if (!$quiet) {
            if ($totalKilled > 0) {
                $this->printer->success(__('  已处理 %{1} 个进程', [$totalKilled]));
            } else {
                $this->printer->note(__('  无残留进程'));
            }
        }

        return $totalKilled;
    }

    protected function terminateCurrentInstanceProcessPrefixes(string $name, bool $includeSharedState = false): int
    {
        $pids = [];
        foreach ($this->collectResidualCleanupPrefixes($name, $includeSharedState) as $prefix) {
            foreach ($this->collectResidualPidsByPrefix($prefix) as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    $pids[$pid] = true;
                }
            }
        }

        return $this->terminateResidualProcesses(\array_map('intval', \array_keys($pids)), true);
    }

    /**
     * 组合清理时使用的残留进程名前缀列表。
     *
     * @return list<string>
     */
    private function collectResidualCleanupPrefixes(string $name, bool $includeSharedState = false): array
    {
        $scopedInstance = MasterProcess::getScopedInstanceName($name);
        $prefixes = [
            MasterProcess::getMasterProcessName($name),
            MasterProcess::getMasterProcessName($name) . '-win',
            MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $name),
            MasterProcess::buildScopedProcessName('weline-wls-worker', $name) . '-',
            MasterProcess::buildScopedProcessName('weline-wls-maintenance', $name) . '-',
            MasterProcess::buildScopedProcessName(MasterProcess::HTTP_REDIRECT_PROCESS_NAME, $name),
            'weline-wls-worker-http-' . $scopedInstance . '-',
            'weline-wls-worker-ssl-' . $scopedInstance . '-',
            'weline-wls-maintenance-http-' . $scopedInstance . '-',
            'weline-wls-maintenance-ssl-' . $scopedInstance . '-',
        ];
        if ($includeSharedState) {
            $prefixes[] = MasterProcess::buildScopedProcessName('weline-wls-session', $name);
            $prefixes[] = MasterProcess::buildScopedProcessName('weline-wls-memory', $name);
            $prefixes[] = 'weline-wls-session-' . $name;
            $prefixes[] = 'weline-wls-memory-' . $name;
        }

        return \array_values(\array_unique(\array_merge(
            $prefixes,
            $this->getRecoverableManagedProcessPrefixes($name)
        )));
    }

    /**
     * @return list<int>
     */
    protected function collectRemainingRecoverableWlsPorts(
        string $name,
        ServerInstanceInfo $info,
        bool $includeSharedState = false
    ): array
    {
        $ports = $this->collectRecoverableKnownPorts($name, $info, $includeSharedState);
        $remaining = [];

        foreach ($ports as $port) {
            $inspect = $this->inspectRecoverablePortOccupant((int) $port);
            if (!($inspect['in_use'] ?? false)) {
                continue;
            }
            if ($this->isSharedStatePortOccupant($inspect)) {
                if (!$includeSharedState) {
                    continue;
                }
                if (!$this->isSharedStatePortOwnedByInstance((int) $port, $inspect, $name)) {
                    continue;
                }
            }

            $recoverable = (bool) ($inspect['is_weline'] ?? false)
                || $this->isRecoverableWlsPortResponder((int) $port)
                || $this->isRecoverableManagedPort((int) $port, $inspect, $info);
            if (!$recoverable) {
                continue;
            }

            $remaining[] = (int) $port;
        }

        return \array_values(\array_unique(\array_filter(
            $remaining,
            static fn (int $port): bool => $port > 0
        )));
    }

    /**
     * @return list<int>
     */
    protected function collectRecoverableKnownPorts(
        string $name,
        ?ServerInstanceInfo $info = null,
        bool $includeSharedState = false
    ): array
    {
        $ports = $this->getRecoverableConfiguredPorts($name);
        if ($info !== null) {
            $ports = \array_merge($ports, $this->collectRecoverablePortsFromInstance($info, $includeSharedState));
        }
        $ports = \array_merge($ports, $this->collectRecoverablePortsFromInstanceRecords($name, $includeSharedState));

        $ports = \array_values(\array_unique(\array_filter(
            \array_map('intval', $ports),
            static fn (int $port): bool => $port > 0
        )));
        \sort($ports);

        return $ports;
    }

    /**
     * @return list<int>
     */
    protected function collectRecoverablePortsFromInstance(ServerInstanceInfo $info, bool $includeSharedState = false): array
    {
        $ports = [];

        if ($info->port > 0) {
            $ports[] = $info->port;
        }
        if ($info->httpRedirectPort > 0) {
            $ports[] = $info->httpRedirectPort;
        }
        if ($info->controlPort > 0) {
            $ports[] = $info->controlPort;
        }
        if ($info->workerBasePort > 0) {
            $ports[] = $info->workerBasePort;
        }

        foreach ($info->services as $service) {
            if (!$includeSharedState && $this->isSharedStateServiceInfo($service)) {
                continue;
            }
            $port = (int) ($service->port ?? 0);
            if ($port > 0) {
                $ports[] = $port;
            }
        }

        return \array_values(\array_unique(\array_filter(
            \array_map('intval', $ports),
            static fn (int $port): bool => $port > 0
        )));
    }

    /**
     * @return list<int>
     */
    protected function collectRecoverablePortsFromInstanceRecords(string $name, bool $includeSharedState = false): array
    {
        $rawData = $this->getRawStopInstanceData($name);
        if ($rawData === null) {
            return [];
        }

        $ports = $this->collectRecoverablePortsFromRecord($rawData, $includeSharedState);
        foreach (($rawData['instance_records'] ?? []) as $record) {
            if (\is_array($record)) {
                $ports = \array_merge($ports, $this->collectRecoverablePortsFromRecord($record, $includeSharedState));
            }
        }

        $ports = \array_values(\array_unique(\array_filter(
            \array_map('intval', $ports),
            static fn (int $port): bool => $port > 0
        )));
        \sort($ports);

        return $ports;
    }

    /**
     * @param array<string, mixed> $record
     * @return list<int>
     */
    private function collectRecoverablePortsFromRecord(array $record, bool $includeSharedState = false): array
    {
        $ports = [];
        foreach (['port', 'worker_port', 'worker_base_port', 'control_port', 'dispatcher_port', 'http_redirect_port'] as $field) {
            $port = (int) ($record[$field] ?? 0);
            if ($port > 0) {
                $ports[] = $port;
            }
        }

        foreach (['port', 'worker_port', 'worker_base_port'] as $baseField) {
            $basePort = (int) ($record[$baseField] ?? 0);
            $count = (int) ($record['count'] ?? 0);
            if ($basePort <= 0 || $count <= 1) {
                continue;
            }
            for ($offset = 1; $offset < $count; $offset++) {
                $ports[] = $basePort + $offset;
            }
        }

        $services = \is_array($record['services'] ?? null) ? $record['services'] : [];
        foreach ($services as $role => $roleData) {
            if (!\is_array($roleData) || !\is_array($roleData['instances'] ?? null)) {
                continue;
            }
            if (!$includeSharedState && $this->isSharedStateServiceRole((string) $role)) {
                continue;
            }
            foreach ($roleData['instances'] as $serviceRecord) {
                if (!\is_array($serviceRecord)) {
                    continue;
                }
                if (!$includeSharedState && $this->isSharedStateServiceRecord($serviceRecord)) {
                    continue;
                }
                $port = (int) ($serviceRecord['port'] ?? 0);
                if ($port > 0) {
                    $ports[] = $port;
                }
            }
        }

        return \array_values(\array_unique(\array_filter(
            \array_map('intval', $ports),
            static fn (int $port): bool => $port > 0
        )));
    }

    /**
     * @param array{
     *   in_use?:bool,
     *   pid?:int,
     *   pid_running?:bool,
     *   is_weline?:bool,
     *   state?:string
     * } $inspect
     */
    protected function isRecoverableManagedPort(int $port, array $inspect, ?ServerInstanceInfo $info = null): bool
    {
        if (!($inspect['in_use'] ?? false)) {
            return false;
        }

        $pid = (int) ($inspect['pid'] ?? 0);
        if ($pid <= 0 || !$this->isStopPidRunning($pid)) {
            return false;
        }

        $pname = $this->getProcessPnameByPid($pid);
        if ($this->isSharedStateProcessName($pname)) {
            return false;
        }
        if (\str_contains($pname, 'weline-wls') || \str_contains($pname, 'weline-master')) {
            return true;
        }

        return $info !== null && \in_array($port, $this->collectRecoverablePortsFromInstance($info), true);
    }

    /**
     * @return list<int>
     */
    protected function collectBaseResidualPids(string $name, ServerInstanceInfo $info): array
    {
        // Duplicate same-instance masters may outlive pid_index records but still carry a scoped --name.
        return \array_values(\array_unique(\array_merge(
            $this->collectResidualPidsByInfo($info),
            $this->collectResidualPidsFromInstanceRecords($name),
            $this->collectIndexedResidualPids($name),
            $this->collectResidualPrefixPids($name)
        )));
    }

    protected function terminateDirectForceStopCandidatePids(ServerInstanceInfo $info): int
    {
        $candidates = $this->collectDirectForceStopCandidatePids($info);
        $trustedCurrentPids = $this->collectResidualPidsByInfo($info, true);
        $killedByPid = $this->terminateResidualProcesses(
            $candidates,
            true,
            $trustedCurrentPids
        );

        return $killedByPid;
    }

    /**
     * @return list<int>
     */
    protected function collectDirectForceStopCandidatePids(ServerInstanceInfo $info): array
    {
        if (isset($this->directForceStopCandidatePidsCache[$info->name])) {
            return $this->directForceStopCandidatePidsCache[$info->name];
        }

        $candidates = \array_values(\array_unique(\array_filter(
            \array_map(
                'intval',
                \array_merge(
                    $this->collectDirectForceBaseResidualPids($info),
                    $this->collectRecoverableManagedPids($info->name)
                )
            ),
            static fn (int $pid): bool => $pid > 0
        )));
        $this->directForceStopCandidatePidsCache[$info->name] = $candidates;

        return $candidates;
    }

    /**
     * Direct force-stop is the hot path used by --fast-local. Keep its first pass scoped to
     * the current runtime snapshot and current indexes; append-only history remains in the
     * retry/verification cleanup path where stale PID reuse can be filtered more carefully.
     *
     * @return list<int>
     */
    protected function collectDirectForceBaseResidualPids(ServerInstanceInfo $info): array
    {
        return \array_values(\array_unique(\array_merge(
            $this->collectResidualPidsByInfo($info, true)
        )));
    }

    protected function hasKnownRecoverablePortsInUse(string $name, ServerInstanceInfo $info): bool
    {
        foreach ($this->collectRecoverableKnownPorts($name, $info) as $port) {
            $inspect = $this->inspectRecoverablePortOccupant($port);
            if (!($inspect['in_use'] ?? false)) {
                continue;
            }
            if ($this->isSharedStatePortOccupant($inspect)) {
                continue;
            }

            if (
                (bool) ($inspect['is_weline'] ?? false)
                || $this->isRecoverableWlsPortResponder($port)
                || $this->isRecoverableManagedPort($port, $inspect, $info)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $pids
     * @return list<int>
     */
    protected function collectManagedStopPids(array $pids): array
    {
        $resolved = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }

            $resolved[$pid] = true;
            $rootPid = $this->resolveManagedStopRootPid($pid);
            if ($rootPid > 0) {
                $resolved[$rootPid] = true;
            }
        }

        return \array_map('intval', \array_keys($resolved));
    }

    protected function resolveManagedStopRootPid(int $pid): int
    {
        if ($pid <= 0 || !$this->isStopPidRunning($pid)) {
            return $pid > 0 ? $pid : 0;
        }

        if ($this->isWindowsPlatform()) {
            // Windows parent processes are often transient cmd/powershell launch
            // wrappers. Querying their command line through CIM can hang during
            // restart cleanup, while taskkill /T on the managed WLS PID is
            // already sufficient to terminate that process tree.
            return $pid;
        }

        $currentPid = $pid;
        $visited = [];
        for ($depth = 0; $depth < 6; $depth++) {
            $visited[$currentPid] = true;
            $parentPid = $this->getStopParentPid($currentPid);
            if ($parentPid <= 0 || isset($visited[$parentPid]) || !$this->isStopPidRunning($parentPid)) {
                break;
            }

            if (!$this->shouldPromoteManagedStopToParent($currentPid, $parentPid)) {
                break;
            }

            $currentPid = $parentPid;
        }

        return $currentPid;
    }

    protected function shouldPromoteManagedStopToParent(int $currentPid, int $parentPid): bool
    {
        unset($currentPid);

        if (!$this->isStopWelineServerProcess($parentPid) && !$this->isStopProcessManagerCreated($parentPid)) {
            return false;
        }

        return $this->isManagedStopShellWrapper($parentPid);
    }

    protected function isManagedStopShellWrapper(int $pid): bool
    {
        $cmdLine = \strtolower($this->getStopProcessCommandLine($pid));
        if ($cmdLine === '') {
            return false;
        }

        return \str_contains($cmdLine, 'cmd.exe')
            || \str_contains($cmdLine, '\\cmd ')
            || \str_contains($cmdLine, 'powershell')
            || \str_contains($cmdLine, 'pwsh');
    }

    /**
     * 强制 stop / 本地清场阶段要把 recoverable 托管 PID 也纳入候选集合，
     * 否则仅靠实例文件与 pid_index 时，部分带 --instance-name/--master-pid 的派生进程会逃逸。
     *
     * @return list<int>
     */
    protected function collectResidualCleanupCandidatePids(
        string $name,
        ServerInstanceInfo $info,
        bool $includeSharedState = false
    ): array
    {
        $recoverablePorts = $includeSharedState
            ? \array_values(\array_unique(\array_merge(
                $this->collectRecoverablePortsFromInstance($info, true),
                $this->collectRecoverablePortsFromInstanceRecords($name, true)
            )))
            : [];
        return \array_values(\array_unique(\array_filter(
            \array_map(
                'intval',
                \array_merge(
                    $this->collectBaseResidualPids($name, $info),
                    $includeSharedState ? $this->collectResidualPidsByInfo($info, true) : [],
                    $includeSharedState ? $this->collectIndexedResidualPids($name, true) : [],
                    $this->collectRecoverablePortResidualPids($recoverablePorts),
                    $this->collectRecoverableManagedPids($name)
                )
            ),
            static fn (int $pid): bool => $pid > 0
        )));
    }

    /**
     * @param list<int> $ports
     * @return list<int>
     */
    private function collectRecoverablePortResidualPids(array $ports): array
    {
        $pids = [];
        foreach ($ports as $port) {
            $inspect = $this->inspectRecoverablePortOccupant((int) $port);
            if (!($inspect['in_use'] ?? false)) {
                continue;
            }
            $pid = (int) ($inspect['pid'] ?? 0);
            if ($pid <= 0 || !\is_int($pid)) {
                continue;
            }

            if ($this->isStopPidRunning($pid)) {
                $pids[$pid] = true;
            }
        }

        return \array_values(\array_unique(\array_filter(
            \array_map('intval', \array_keys($pids)),
            static fn (int $pid): bool => $pid > 0
        )));
    }

    /**
     * 复核阶段必须复用与清理阶段一致的候选来源，否则 prefix-only 的残留 worker
     * 会在“已处理 N 个进程”后漏出。
     *
     * @param list<int> $remainingPorts
     * @return list<int>
     */
    protected function collectResidualVerificationPids(
        string $name,
        ServerInstanceInfo $info,
        array $remainingPorts,
        bool $includePrefixPids = true,
        bool $includeSharedState = false
    ): array
    {
        $pids = \array_merge(
            $this->collectResidualCleanupCandidatePids($name, $info, $includeSharedState),
            $this->collectRecoverablePortResidualPids($remainingPorts)
        );
        if ($includePrefixPids) {
            $pids = \array_merge($pids, $this->collectResidualPrefixPids($name));
        }

        return \array_values(\array_unique(\array_filter(
            \array_map(
                'intval',
                $pids
            ),
            static fn (int $pid): bool => $pid > 0
        )));
    }

    /**
     * @return list<int>
     */
    protected function collectResidualPrefixPids(string $name): array
    {
        if (!isset($this->residualPrefixPidsCache[$name])) {
            $this->residualPrefixPidsCache[$name] = $this->queryResidualPrefixPids($name);
        }

        return $this->residualPrefixPidsCache[$name];
    }

    /**
     * @return list<int>
     */
    protected function queryResidualPrefixPids(string $name): array
    {
        $pids = [];
        foreach ($this->collectResidualCleanupPrefixes($name) as $prefix) {
            foreach ($this->collectResidualPidsByPrefix($prefix) as $pid) {
                if ($pid > 0) {
                    $pids[$pid] = true;
                }
            }
        }

        return \array_map('intval', \array_keys($pids));
    }

    /**
     * @return list<int>
     */
    protected function collectResidualPidsByPrefix(string $prefix): array
    {
        return Processer::getProcessIdsByPrefix($prefix);
    }

    protected function cleanupResidualProcessesByInfo(string $name, ServerInstanceInfo $info, bool $quiet = false): int
    {
        return $this->runResidualCleanupPass($name, $info, $quiet);
    }

    /**
     * 格式化 IPC 消息（带颜色）
     * 
     * @param string $message 消息内容
     * @param string $type 消息类型：success, drain, stop, error, info
     */
    protected function ipcMsg(string $message, string $type = 'info'): void
    {
        $color = match ($type) {
            'success' => self::IPC_COLOR_SUCCESS,  // 绿色：上报成功
            'drain' => self::IPC_COLOR_DRAIN,      // 黄色：通知排水/重载
            'stop' => self::IPC_COLOR_STOP,        // 红色：通知停止
            'error' => self::IPC_COLOR_ERROR,      // 红色：错误
            default => self::IPC_COLOR_INFO,       // 蓝色：一般信息
        };
        
        $tag = $this->printer->colorize('[IPC]', self::IPC_COLOR_TAG);
        $content = $this->printer->colorize($message, $color);
        echo "  {$tag} {$content}\n";
    }
    
    /**
     * 格式化 IPC 进度消息（来自 Orchestrator，自动判断颜色）
     * 
     * 颜色区分：
     * - 绿色：上报成功（进程排水完成、已退出、已断开）
     * - 黄色：通知排水/重载（广播 DRAIN、RELOAD、等待排水）
     * - 红色：通知停止（广播 SHUTDOWN、强制终止、阶段停止）
     * - 蓝色：一般信息
     */
    protected function ipcProgress(string $message): void
    {
        $tag = $this->printer->colorize('[IPC]', self::IPC_COLOR_TAG);
        
        // 根据消息内容自动判断颜色
        if (\str_contains($message, '✓') || \str_contains($message, '已退出') || \str_contains($message, '已断开') || \str_contains($message, '排水完成')) {
            // 绿色：上报成功
            $content = $this->printer->colorize($message, self::IPC_COLOR_SUCCESS);
        } elseif (\str_contains($message, '✗') || \str_contains($message, '失败') || \str_contains($message, '错误')) {
            // 红色：错误
            $content = $this->printer->colorize($message, self::IPC_COLOR_ERROR);
        } elseif (\str_contains($message, 'SHUTDOWN') || \str_contains($message, '通知子进程退出') || \str_contains($message, '强制') || \str_contains($message, '校验子进程退出') || \str_contains($message, 'Master 即将退出')) {
            // 红色：通知停止
            $content = $this->printer->colorize($message, self::IPC_COLOR_STOP);
        } elseif (\str_contains($message, 'DRAIN') || \str_contains($message, 'RELOAD') || \str_contains($message, '排水') || \str_contains($message, '等待排水') || \str_contains($message, '重载')) {
            // 黄色：通知排水/重载
            $content = $this->printer->colorize($message, self::IPC_COLOR_DRAIN);
        } elseif (\str_contains($message, '阶段') || \str_contains($message, 'Phase')) {
            // 黄色：阶段信息（作为进度提示）
            $content = $this->printer->colorize($message, self::IPC_COLOR_DRAIN);
        } else {
            // 蓝色：一般信息
            $content = $this->printer->colorize($message, self::IPC_COLOR_INFO);
        }
        
        echo "  {$tag} {$content}\n";
    }
    
    /**
     * 通过 IPC 发送 STOP 命令并等待所有子进程完整退出
     */
    protected function sendStopViaIpcAndWait(string $instanceName, int $controlPort, int $masterPid, bool $force): bool
    {
        if ($controlPort <= 0) {
            return false;
        }
        
        // 连接 IPC
        $host = '127.0.0.1';
        $masterPidLabel = $masterPid > 0 ? (string)$masterPid : 'unknown';
        $this->ipcMsg("连接 Master (PID:{$masterPidLabel}) 控制端口 {$host}:{$controlPort}...", 'info');
        
        $errno = 0;
        $errstr = '';
        $conn = @\stream_socket_client("tcp://{$host}:{$controlPort}", $errno, $errstr, 5);
        if (!$conn) {
            $this->ipcMsg("连接失败: {$errstr} (errno:{$errno})", 'error');
            return false;
        }
        
        $this->ipcMsg("连接成功 ✓", 'success');
        $this->ipcMsg("发送 STOP 命令...", 'stop');
        
        // 发送 STOP 命令（msg_id 与 Master ACK / trace 对齐）
        $traceId = 'cli-stop-' . \getmypid() . '-' . \time();
        $stopMsg = \Weline\Server\IPC\ControlMessage::command(
            \Weline\Server\IPC\ControlMessage::ACTION_STOP,
            '',
            [
                'msg_id' => $traceId,
                'stop_intent' => 'explicit',
                'stop_source' => 'cli:server:stop',
                'stop_trace_id' => $traceId,
                'force_stop' => $force,
            ]
        );
        $written = @\fwrite($conn, $stopMsg);
        
        if ($written === false || $written === 0) {
            $this->ipcMsg("发送命令失败", 'error');
            @\fclose($conn);
            return false;
        }
        
        $this->ipcMsg("等待 Orchestrator 停止所有子进程...", 'stop');
        
        // 设置流为非阻塞，持续读取直到连接断开（表示 Master 已停止 IPC 服务器）
        \stream_set_timeout($conn, 1);
        \stream_set_blocking($conn, false);
        
        // force 模式用于“更快进入停止流程”，不应把 IPC 等待缩短到低于 Orchestrator 的正常停机时长，
        // 否则会频繁误判超时并走强杀 Master，造成状态抖动。
        $timeout = $force ? self::IPC_FORCE_TIMEOUT : self::IPC_TIMEOUT;
        $startedAt = \microtime(true);
        $hardTimeout = $this->getIpcHardTimeout($force);
        $hardDeadline = $startedAt + $hardTimeout;
        $lastActivityAt = $startedAt;
        $lastIdleNoticeAt = 0.0;
        $lastProgress = '';
        $observedStopStage = 0;
        $childrenFullyExited = false;
        $readBuffer = '';
        $masterAboutToExit = false; // 只在收到 "Master 即将退出" 时置 true
        $exitedPids = []; // 用 PID 去重，防止同一进程的 "已断开" 和 "已退出" 重复计数
        $totalInstances = 0; // 总实例数
        $stopAccepted = false;
        $ackDeadline = $startedAt + ($force ? 1.0 : 2.0);

        while (\microtime(true) < $hardDeadline) {
            if (!$stopAccepted && \microtime(true) >= $ackDeadline) {
                $this->ipcMsg(__('STOP 命令在 ACK 超时内未得到确认（未见 Stopping），转为本地清理。'), 'error');
                $this->ipcAppendStopTraceHint($instanceName);
                @\fclose($conn);
                return false;
            }

            // 优先用 pid_index.json 状态文件判定 Master 是否已退出（毫秒级），
            // 只有怀疑已退出时才用更重的 processExists/tasklist 二次确认。
            // 这一改动避免了 0.5s 间隔的循环每次过 TTL 触发一次 ~2.6s 的全表 tasklist，
            // 把 Windows 上 server:stop 的主循环开销从 N×全表扫描降到 0。
            if ($masterPid > 0
                && $this->isMasterPidMissingFromIndex($masterPid)
                && !Processer::processExists($masterPid)
            ) {
                $this->ipcMsg("Master 进程已退出 ✓", 'success');
                @\fclose($conn);
                return true;
            }

            $read = [$conn];
            $write = $except = null;
            // 缩短 select 超时到 0.5 秒，更快响应
            $ready = @\stream_select($read, $write, $except, 0, 500000);
            
            if ($ready === false) {
                // stream_select 错误，连接可能已断开
                break;
            }
            
            if ($ready > 0) {
                $data = @\fread($conn, 4096);
                if ($data === false || $data === '') {
                    foreach ($this->flushTrailingIpcBufferLines($readBuffer) as $line) {
                        $this->processStopProgressLine(
                            $line,
                            $lastProgress,
                            $exitedPids,
                            $totalInstances,
                            $observedStopStage,
                            $childrenFullyExited,
                            $masterAboutToExit,
                            $stopAccepted
                        );
                    }
                    // 连接断开 - Master 已关闭 IPC
                    $this->ipcMsg("Master 已关闭连接 ✓", 'success');
                    @\fclose($conn);
                    
                    // 快速等待 Master 进程完全退出
                    return $this->waitForMasterExit($masterPid);
                }

                $lastActivityAt = \microtime(true);
                $readBuffer .= $data;
                foreach ($this->extractCompleteIpcLines($readBuffer) as $line) {
                    $this->processStopProgressLine(
                        $line,
                        $lastProgress,
                        $exitedPids,
                        $totalInstances,
                        $observedStopStage,
                        $childrenFullyExited,
                        $masterAboutToExit,
                        $stopAccepted
                    );
                    if ($masterAboutToExit) {
                        break;
                    }
                }
                continue;
            }
            
            // 只在 Master 明确发送 "即将退出" 后才进入等待退出流程
            if ($masterAboutToExit) {
                $this->ipcMsg("所有子进程已退出，等待 Master 清理...", 'success');
                @\fclose($conn);
                return $this->waitForMasterExit($masterPid);
            }
            // 空闲超时仅作提示，不立即判定失败，避免长排水阶段误杀 Master
            $now = \microtime(true);
            if (($now - $lastActivityAt) >= $timeout) {
                if ($masterPid <= 0 && $observedStopStage === 0 && !$childrenFullyExited && !$masterAboutToExit) {
                    $this->ipcMsg("No STOP progress from control port after {$timeout}s; switch to local cleanup.", 'error');
                    @\fclose($conn);
                    return false;
                }
                if ($masterPid > 0
                    && $this->isMasterPidMissingFromIndex($masterPid)
                    && !Processer::processExists($masterPid)
                ) {
                    $this->ipcMsg("Master 进程已退出 ✓", 'success');
                    @\fclose($conn);
                    return true;
                }
                if ($this->shouldAbortToLocalCleanupAfterIdle(
                    $observedStopStage,
                    $childrenFullyExited,
                    $masterAboutToExit
                )) {
                    $elapsed = (int)\round($now - $startedAt);
                    $this->ipcMsg(
                        "Stage 5 idle {$timeout}s (elapsed {$elapsed}s), switch to local cleanup.",
                        'error'
                    );
                    @\fclose($conn);
                    return false;
                }
                if (($now - $lastIdleNoticeAt) >= $timeout) {
                    $elapsed = (int)\round($now - $startedAt);
                    $this->ipcMsg("Idle {$timeout}s (elapsed {$elapsed}s), Master still running, continue waiting...", 'info');
                    $lastIdleNoticeAt = $now;
                }
                $lastActivityAt = $now;
            }
        }

        @\fclose($conn);
        
        // 超时前最后一次检查 Master 状态
        if ($masterPid > 0 && !Processer::processExists($masterPid)) {
            $this->ipcMsg("Master 进程已退出 ✓", 'success');
            return true;
        }
        if ($observedStopStage > 0) {
            $this->lastIpcStopFlowStillActive = true;
            $this->ipcMsg("Stop flow still appears active after hard wait; keep metadata and avoid local cleanup.", 'info');
            return false;
        }

        $this->ipcMsg("Wait timeout (hard {$hardTimeout}s)", 'error');
        return false;
    }

    /**
     * @param array<int, bool> $exitedPids
     */
    protected function shouldWaitForMasterExitAfterProgress(
        string $message,
        array $exitedPids,
        int $totalInstances,
        int $observedStopStage = 0,
        bool $childrenFullyExited = false
    ): bool
    {
        unset($exitedPids, $totalInstances);

        if (\str_contains($message, 'Master 即将退出')) {
            return true;
        }

        return $childrenFullyExited && $observedStopStage >= 5;
    }

    protected function shouldAbortToLocalCleanupAfterIdle(
        int $observedStopStage,
        bool $childrenFullyExited,
        bool $masterAboutToExit
    ): bool
    {
        if ($masterAboutToExit) {
            return false;
        }

        return $childrenFullyExited || $observedStopStage >= 5;
    }

    /**
     * @return list<string>
     */
    protected function extractCompleteIpcLines(string &$buffer): array
    {
        $lines = [];

        while (($newlinePos = \strpos($buffer, "\n")) !== false) {
            $line = \trim(\substr($buffer, 0, $newlinePos), "\r\n\t ");
            $buffer = (string) \substr($buffer, $newlinePos + 1);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected function flushTrailingIpcBufferLines(string &$buffer): array
    {
        $lines = $this->extractCompleteIpcLines($buffer);
        $tail = \trim($buffer, "\r\n\t ");
        $buffer = '';
        if ($tail !== '') {
            $lines[] = $tail;
        }

        return $lines;
    }

    /**
     * @param array<int, bool> $exitedPids
     */
    protected function processStopProgressLine(
        string $line,
        string &$lastProgress,
        array &$exitedPids,
        int &$totalInstances,
        int &$observedStopStage,
        bool &$childrenFullyExited,
        bool &$masterAboutToExit,
        bool &$stopAccepted
    ): void {
        $msg = \Weline\Server\IPC\ControlMessage::decode($line);
        if ($msg === null) {
            return;
        }

        if (($msg['type'] ?? '') !== \Weline\Server\IPC\ControlMessage::TYPE_COMMAND_RESULT) {
            return;
        }

        $message = (string) ($msg['message'] ?? '');
        $dataPayload = \is_array($msg['data'] ?? null) ? $msg['data'] : [];
        $state = \strtolower((string) ($dataPayload['state'] ?? ''));
        if ($message === 'Stopping' || $state === 'stopping') {
            $stopAccepted = true;
        }

        if ($message === '' || $message === $lastProgress) {
            return;
        }

        $this->ipcProgress($message);
        $lastProgress = $message;

        if (\preg_match('/共\s*(\d+)\s*个实例待停止/u', $message, $matches)) {
            $totalInstances = (int) $matches[1];
        }

        if (\preg_match('/PID[:\s]*(\d+)\)?\s*(?:已退出|已断开连接)/u', $message, $pidMatch)) {
            $exitedPids[(int) $pidMatch[1]] = true;
        }

        $observedStopStage = $this->updateObservedStopStage($message, $observedStopStage);
        $childrenFullyExited = $childrenFullyExited || $this->isChildrenFullyExitedProgress($message);

        if ($this->shouldWaitForMasterExitAfterProgress(
            $message,
            $exitedPids,
            $totalInstances,
            $observedStopStage,
            $childrenFullyExited
        )) {
            $masterAboutToExit = true;
        }
    }

    protected function updateObservedStopStage(string $message, int $observedStopStage): int
    {
        if (\preg_match('/(?:阶段|Stage)\s*(\d+)\s*\/\s*6/u', $message, $matches)) {
            return \max($observedStopStage, (int) $matches[1]);
        }

        if ($this->isChildrenFullyExitedProgress($message)) {
            return \max($observedStopStage, 5);
        }

        return $observedStopStage;
    }

    protected function isChildrenFullyExitedProgress(string $message): bool
    {
        if (\str_contains($message, '阶段5完成')) {
            return true;
        }

        return \str_contains($message, '全部')
            && \str_contains($message, '子进程已退出');
    }

    private function getIpcHardTimeout(bool $force = false): int
    {
        $isWin = (\strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN');
        if ($force) {
            return $isWin
                ? self::IPC_FORCE_HARD_TIMEOUT_WIN
                : self::IPC_FORCE_HARD_TIMEOUT_LINUX;
        }

        $base = $isWin ? self::IPC_HARD_TIMEOUT_WIN : self::IPC_HARD_TIMEOUT_LINUX;
        $cap = $isWin ? 600 : 420;

        $stopDrainWait = (float) Env::get('wls.orchestrator.stop_all_drain_wait_sec', 10.0);
        if ($stopDrainWait < 1.0) {
            $stopDrainWait = 1.0;
        }
        if ($stopDrainWait > 300.0) {
            $stopDrainWait = 300.0;
        }

        $terminateTimeout = (float) Env::get('wls.orchestrator.stop_terminate_timeout_sec', 3.0);
        if ($terminateTimeout < 1.0) {
            $terminateTimeout = 1.0;
        }
        if ($terminateTimeout > 30.0) {
            $terminateTimeout = 30.0;
        }

        // 预留阶段切换、IPC 传输与最终校验窗口，避免长排水配置下过早硬超时
        $adaptive = (int) \ceil($stopDrainWait + $terminateTimeout + 20.0);
        $adaptive = \max($base, $adaptive);

        return \min($adaptive, $cap);
    }
    
    /**
     * 等待 Master 进程退出（子进程已全部退出后调用）
     * 
     * 优化策略：使用 hasExitedFast() 快速检测
     * 当 Master 从 pid_index.json 删除自己的 PID 后，
     * hasExitedFast() 会立即返回 true，无需调用 tasklist/ps 等外部命令。
     */
    protected function waitForMasterExit(int $masterPid): bool
    {
        if ($masterPid <= 0) {
            return true;
        }

        $tag = $this->printer->colorize('[IPC]', self::IPC_COLOR_TAG);
        $waitMsg = $this->printer->colorize('等待 Master 进程退出', self::IPC_COLOR_INFO);
        echo "  {$tag} {$waitMsg}";
        
        $timeout = (\strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN')
            ? self::MASTER_EXIT_TIMEOUT_WIN
            : self::MASTER_EXIT_TIMEOUT_LINUX;
        $deadline = \microtime(true) + $timeout;
        $confirmed = 0;
        
        while (\microtime(true) < $deadline) {
            SchedulerSystem::usleep(200000); // 200ms
            echo $this->printer->colorize('.', self::IPC_COLOR_INFO);
            // 快速路径 + 真实进程校验双确认，避免“索引先删、进程未退”的假退出。
            if ($this->isMasterPidMissingFromIndex($masterPid) && !Processer::processExists($masterPid)) {
                $confirmed++;
            } else {
                $confirmed = 0;
            }
            if ($confirmed >= 2) {
                echo $this->printer->colorize(' 完成 ✓', self::IPC_COLOR_SUCCESS) . "\n";
                return true;
            }
        }
        
        // 最后一次检查
        if (!Processer::processExists($masterPid)) {
            echo $this->printer->colorize(' 完成 ✓', self::IPC_COLOR_SUCCESS) . "\n";
            return true;
        }
        
        echo $this->printer->colorize(' 超时', self::IPC_COLOR_ERROR) . "\n";
        return false;
    }
    
    /**
     * 清理残留进程
     *
     * 当 Master IPC 失败时，按进程名前缀批量清理
     *
     * @param bool $quiet 为 true 时不打印，仅返回按前缀杀死的进程数
     */
    protected function cleanupResidualProcesses(string $name, array $instanceData, bool $quiet = false): int
    {
        if (!$quiet) {
            $this->printer->note(__('清理残留进程...'));
        }
        $totalKilled = $this->terminateResidualProcesses($this->collectIndexedResidualPids($name), true);
        
        if (!$quiet) {
            if ($totalKilled > 0) {
                $this->printer->note(__('  已处理 %{1} 个进程', [$totalKilled]));
            } else {
                $this->printer->note(__('  无残留进程'));
            }
        }

        return $totalKilled;
    }

    /**
     * 收集实例文件中记录的残留 PID。
     *
     * @return array<int>
     */
    private function collectResidualPidsByInfo(ServerInstanceInfo $info, bool $includeSharedState = false): array
    {
        $pids = [];

        if ($info->masterPid > 0) {
            $pids[$info->masterPid] = true;
        }

        foreach ($info->services as $service) {
            if ((bool) ($service->metadata['shared_external'] ?? false)
                || (!$includeSharedState && $this->isSharedStateServiceInfo($service))) {
                continue;
            }
            foreach ($service->getManagedPids() as $pid) {
                if ($pid > 0) {
                    $pids[$pid] = true;
                }
            }
        }

        return \array_map('intval', \array_keys($pids));
    }

    /**
     * @return list<int>
     */
    protected function collectResidualPidsFromInstanceRecords(string $name, bool $includeSharedState = false): array
    {
        $rawData = $this->getRawStopInstanceData($name);
        if ($rawData === null) {
            return [];
        }

        $pids = $this->collectResidualPidsFromRecord($rawData, $includeSharedState);
        foreach (($rawData['instance_records'] ?? []) as $record) {
            if (\is_array($record)) {
                $pids = \array_merge($pids, $this->collectResidualPidsFromRecord($record, $includeSharedState));
            }
        }

        return \array_values(\array_unique(\array_filter(
            \array_map('intval', $pids),
            static fn (int $pid): bool => $pid > 0
        )));
    }

    /**
     * @param array<string, mixed> $record
     * @return list<int>
     */
    private function collectResidualPidsFromRecord(array $record, bool $includeSharedState = false): array
    {
        $pids = [];
        foreach (['pid', 'master_pid', 'root_pid', 'launcher_pid', 'master_exited_pid'] as $field) {
            $pid = (int) ($record[$field] ?? 0);
            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }

        $retainedPids = \is_array($record['retained_pids'] ?? null) ? $record['retained_pids'] : [];
        foreach ($retainedPids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }

        $services = \is_array($record['services'] ?? null) ? $record['services'] : [];
        foreach ($services as $role => $roleData) {
            if (!\is_array($roleData) || !\is_array($roleData['instances'] ?? null)) {
                continue;
            }
            if (!$includeSharedState && $this->isSharedStateServiceRole((string) $role)) {
                continue;
            }
            foreach ($roleData['instances'] as $serviceRecord) {
                if (!\is_array($serviceRecord)) {
                    continue;
                }
                if (!$includeSharedState && $this->isSharedStateServiceRecord($serviceRecord)) {
                    continue;
                }
                foreach (['pid', 'root_pid', 'launcher_pid', 'tracking_pid'] as $field) {
                    $pid = (int) ($serviceRecord[$field] ?? 0);
                    if ($pid > 0) {
                        $pids[$pid] = true;
                    }
                }
            }
        }

        return \array_map('intval', \array_keys($pids));
    }

    /**
     * 停止实例时从共享服务 registry 移除消费者；残留清理因此不会误杀仍被其它 WLS 实例使用的 Session/Memory 进程。
     */
    protected function releaseSharedStateConsumersForInstance(string $instanceName): void
    {
        try {
            (new SharedStateServiceManager())->releaseInstanceConsumers($instanceName);
        } catch (\Throwable) {
            // best-effort：registry 损坏或并发时不阻塞停机
        }
    }

    private function isSharedStateServiceInfo(object $service): bool
    {
        if ($this->isSharedStateServiceRole((string) ($service->role ?? ''))) {
            return true;
        }

        return $this->isSharedStateProcessName((string) ($service->metadata['process_name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isSharedStateServiceRecord(array $record): bool
    {
        if ((bool) ($record['metadata']['shared_external'] ?? false)) {
            return true;
        }
        if ($this->isSharedStateServiceRole((string) ($record['role'] ?? ''))) {
            return true;
        }

        return $this->isSharedStateProcessName((string) ($record['metadata']['process_name'] ?? ''));
    }

    private function isSharedStateServiceRole(string $role): bool
    {
        $role = \strtolower(\trim($role));

        return \in_array($role, [
            'session',
            'memory',
            ControlMessage::ROLE_SESSION_SERVER,
            ControlMessage::ROLE_MEMORY_SERVER,
        ], true);
    }

    private function isSharedStateProcessName(string $processName): bool
    {
        $processName = \trim($processName);
        if ($processName === '') {
            return false;
        }

        try {
            $taskName = Processer::getTaskName($processName);
            if ($taskName !== '') {
                $processName = $taskName;
            }
        } catch (\Throwable) {
            if (\str_starts_with($processName, '--name=')) {
                $processName = \substr($processName, 7);
            }
        }

        return \str_contains($processName, 'weline-wls-session-')
            || \str_contains($processName, 'weline-wls-memory-');
    }

    /**
     * @param array{pid?:int} $inspect
     */
    private function isSharedStatePortOccupant(array $inspect): bool
    {
        $pid = (int) ($inspect['pid'] ?? 0);
        if ($pid <= 0 || !$this->isStopPidRunning($pid)) {
            return false;
        }

        return $this->isSharedStateProcessName($this->getProcessPnameByPid($pid));
    }

    /**
     * Shared-state ports are common defaults (26422/26423), so includeSharedState
     * must still prove the live port owner belongs to the instance being stopped.
     */
    private function isSharedStatePortOwnedByInstance(int $port, array $inspect, string $name): bool
    {
        unset($port);

        $pid = (int) ($inspect['pid'] ?? 0);
        if ($pid <= 0 || !$this->isStopPidRunning($pid)) {
            return false;
        }

        foreach ([
            $this->getProcessPnameByPid($pid),
            $this->getStopProcessCommandLine($pid),
        ] as $descriptor) {
            if ($this->processDescriptorBelongsToInstance((string) $descriptor, $name)) {
                return true;
            }
        }

        return false;
    }

    private function processDescriptorBelongsToInstance(string $descriptor, string $name): bool
    {
        $descriptor = \trim($descriptor);
        if ($descriptor === '') {
            return false;
        }

        try {
            $taskName = Processer::getTaskName($descriptor);
            if ($taskName !== '') {
                $descriptor .= ' ' . $taskName;
            }
        } catch (\Throwable) {
            // Best-effort guard only; raw command lines are still checked below.
        }

        $haystack = \strtolower($descriptor);
        $instance = \strtolower($name);
        $scoped = \strtolower(MasterProcess::getScopedInstanceName($name));

        return \str_contains($haystack, '--instance-name=' . $instance)
            || \str_contains($haystack, '--instance-name="' . $instance . '"')
            || \str_contains($haystack, '"' . $instance . '"')
            || \str_contains($haystack, ' ' . $instance . ' ')
            || \str_contains($haystack, '-' . $scoped)
            || \str_contains($haystack, '-' . $instance . '-');
    }

    /**
     * 从 name_index 中一次性收集指定实例的 WLS PID，避免逐前缀触发系统搜索。
     *
     * @return array<int>
     */
    private function collectIndexedResidualPids(string $instanceName, bool $includeSharedState = false): array
    {
        $pidIndex = Processer::readPidIndex();
        if (empty($pidIndex)) {
            return [];
        }

        return $this->collectIndexedResidualPidsFromPidIndex($pidIndex, $instanceName, \getmypid(), $includeSharedState);
    }

    /**
     * @param array<int, array{pname: string, jsonPath: string}> $pidIndex
     * @return array<int>
     */
    private function collectIndexedResidualPidsFromPidIndex(
        array $pidIndex,
        string $instanceName,
        int $currentPid,
        bool $includeSharedState = false
    ): array
    {
        $scopedInstanceSuffix = '-' . MasterProcess::getScopedInstanceName($instanceName);
        $legacyWorkerPrefix = 'weline-master-' . $instanceName . '-worker-';
        $legacyMasterPrefix = 'weline-master-' . $instanceName . '-';
        $legacyPrefixes = [
            'weline-wls-master-' . $instanceName,
            'weline-wls-worker-' . $instanceName . '-',
            'weline-wls-maintenance-' . $instanceName . '-',
            'weline-wls-dispatcher-' . $instanceName,
            MasterProcess::HTTP_REDIRECT_PROCESS_NAME . '-' . $instanceName,
            $legacyMasterPrefix,
            $legacyWorkerPrefix,
        ];
        $pids = [];

        foreach ($pidIndex as $pid => $record) {
            $pid = (int) $pid;
            if ($pid <= 0 || $pid === $currentPid) {
                continue;
            }

            $pname = (string) ($record['pname'] ?? '');
            if ($pname === '') {
                continue;
            }
            $jsonPath = (string) ($record['jsonPath'] ?? '');

            try {
                $taskName = Processer::getTaskName($pname);
            } catch (\Throwable) {
                $taskName = \str_starts_with($pname, '--name=')
                    ? \substr($pname, 7)
                    : $pname;
            }
            if (!$includeSharedState && $this->isSharedStateProcessName($taskName)) {
                continue;
            }

            $isCurrentInstance = \str_starts_with($taskName, 'weline-wls-')
                && \str_contains($taskName, $scopedInstanceSuffix);
            if (!$isCurrentInstance) {
                foreach ($legacyPrefixes as $legacyPrefix) {
                    if (\str_starts_with($taskName, $legacyPrefix)) {
                        $isCurrentInstance = true;
                        break;
                    }
                }
            }

            if (!$isCurrentInstance) {
                continue;
            }

            if (!$this->isResidualIndexedPidStillRunning($pid, $pname, $taskName)) {
                continue;
            }

            $pids[$pid] = true;
        }

        return \array_map('intval', \array_keys($pids));
    }

    protected function isResidualIndexedPidStillRunning(int $pid, string $pname, string $taskName): bool
    {
        if (!Processer::isRunningByPid($pid)) {
            return false;
        }

        if (Processer::isManagedProcessRunning($pid, $taskName, '', $pname)) {
            return true;
        }

        return Processer::isWelineServerProcess($pid);
    }

    /**
     * 直接终止给定 PID 列表，避免额外的 processExists 探测开销。
     *
     * @param array<int> $pids
     */
    private function terminateResidualProcesses(array $pids, bool $skipCheck = true, array $trustedPids = []): int
    {
        $currentPid = \getmypid();
        $uniquePids = \array_values(\array_unique(\array_filter(
            \array_map('intval', $pids),
            static fn (int $pid): bool => $pid > 0 && $pid !== $currentPid
        )));

        if (empty($uniquePids)) {
            return 0;
        }

        $runningPids = $this->collectRunningResidualPids($uniquePids, $trustedPids);
        if ($runningPids === []) {
            return 0;
        }

        $result = Processer::dispatchBatchKillProcessTrees($runningPids, $skipCheck);
        $this->invalidateStopRuntimeState();

        return (int) ($result['killed'] ?? 0);
    }

    /**
     * @param array<int> $pids
     * @return array<int>
     */
    protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
    {
        $uniquePids = \array_values(\array_unique(\array_filter(
            \array_map('intval', $pids),
            static fn (int $pid): bool => $pid > 0
        )));
        if (empty($uniquePids)) {
            return [];
        }
        $trustedPidMap = \array_fill_keys(\array_map('intval', $trustedPids), true);

        // 统一走 Processer::batchGetProcessInfo（内部带进程内 TTL 缓存的 tasklist 全表 map）。
        // 历史上 Windows 分支自己跑 `tasklist /FO CSV /NH`（约 2.6s/次）且每个残留清理 retry 都重复调用，
        // 是 server:stop 在 Windows 上慢 10×+ 的主因；现在改为命中共享缓存即可。
        $processInfo = $this->batchGetStopProcessInfo($uniquePids);
        $running = [];
        foreach ($uniquePids as $pid) {
            $info = \is_array($processInfo[$pid] ?? null) ? $processInfo[$pid] : [];
            if (!(bool) ($info['exists'] ?? false)) {
                continue;
            }
            if ((bool) ($info['is_zombie'] ?? false)) {
                continue;
            }
            if (!$this->isLikelyResidualWlsProcessName((string) ($info['name'] ?? ''))) {
                continue;
            }
            if (isset($trustedPidMap[$pid])) {
                $running[] = $pid;
                continue;
            }
            if ($this->isResidualPidStillOwnedByWls($pid)) {
                $running[] = $pid;
            }
        }

        return $running;
    }

    /**
     * @param list<int> $pids
     * @return array<int, array<string, mixed>>
     */
    protected function batchGetStopProcessInfo(array $pids): array
    {
        return Processer::batchGetProcessInfo($pids);
    }

    protected function isResidualPidStillOwnedByWls(int $pid): bool
    {
        return $this->isStopWelineServerProcess($pid) || $this->isStopProcessManagerCreated($pid);
    }

    protected function isLikelyResidualWlsProcessName(string $processName): bool
    {
        $name = \strtolower(\trim($processName));
        if ($name === '') {
            return true;
        }

        return \str_contains($name, 'php')
            || \str_contains($name, 'cmd.exe')
            || \str_contains($name, 'powershell')
            || \str_contains($name, 'pwsh');
    }

    /**
     * 兼容历史调用点（含单元测试）：转发到统一的 collectRunningResidualPids。
     *
     * 历史实现里这里会直接执行 `tasklist /FO CSV /NH` 全表扫描（约 2.6s/次，
     * 在 Windows 上是 server:stop 的主要慢源），现已统一走带进程内缓存的
     * Processer::batchGetProcessInfo。
     *
     * @param list<int> $pids
     * @return list<int>
     */
    protected function collectRunningResidualPidsWindows(array $pids): array
    {
        return $this->collectRunningResidualPids($pids);
    }

    /**
     * @deprecated 仅保留兼容历史单测/外部脚本调用；新逻辑请使用 Processer::batchGetProcessInfo。
     * @param list<int> $pids
     */
    protected function buildWindowsCollectRunningPidsCommand(array $pids): string
    {
        unset($pids);

        return 'tasklist /FO CSV /NH';
    }

    private function isWindowsPlatform(): bool
    {
        return \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * 清理 PID 文件
     */
    protected function cleanupPidFiles(string $name, ServerInstanceInfo $info): void
    {
        // Master
        Processer::removePidFile('--name=' . MasterProcess::getMasterProcessName($name));

        // 统一按服务元信息清理，新增服务无需改 stop 命令
        foreach ($info->services as $service) {
            $processName = (string)($service->metadata['process_name'] ?? '');
            if ($processName !== '') {
                Processer::removePidFile('--name=' . $processName);
            }
        }

        // 兼容历史命名前缀（防止老实例残留）
        $count = $info->workerCount;
        for ($i = 1; $i <= $count; $i++) {
            Processer::removePidFile('--name=weline-wls-worker-' . $name . '-' . $i);
            Processer::removePidFile('--name=weline-master-' . $name . '-worker-' . $i);
        }
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $name));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-session', $name));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName(MasterProcess::HTTP_REDIRECT_PROCESS_NAME, $name));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-memory', $name));
        Processer::removePidFile('--name=weline-wls-dispatcher-' . $name);
        Processer::removePidFile('--name=weline-wls-session-' . $name);
        Processer::removePidFile('--name=weline-wls-memory-' . $name);
        Processer::removePidFile('--name=' . MasterProcess::HTTP_REDIRECT_PROCESS_NAME . '-' . $name);
        Processer::removePidFile('--name=weline-master-' . $name . '-redirect-1');
        
        // Global stale pid pruning is intentionally excluded from the stop hot path.
    }
    
    /**
     * 释放启动锁
     * 
     * 服务器停止后删除启动锁文件，允许重新启动实例
     */
    protected function releaseStartLock(string $instanceName): void
    {
        $lockDir = Env::VAR_DIR . 'server' . DS . 'locks' . DS;
        $lockFile = $lockDir . 'start_' . $instanceName . '.lock';
        
        if (\is_file($lockFile)) {
            @\unlink($lockFile);
            $this->printer->note(__('启动锁已释放 ✓'));
        }
    }
    

    private function findConfiguredRunningInstanceNameByPort(int $port): ?string
    {
        $configDir = Env::VAR_DIR . 'server' . DS . 'config' . DS;
        if (!\is_dir($configDir)) {
            return null;
        }

        foreach (\glob($configDir . '*.json') ?: [] as $file) {
            $name = \basename($file, '.json');
            $data = \json_decode((string) @\file_get_contents($file), true);
            if (!\is_array($data)) {
                continue;
            }

            $instancePort = (int) ($data['port'] ?? 0);
            $httpRedirectPort = $this->resolveConfiguredHttpRedirectPort($data);
            if ($instancePort !== $port && $httpRedirectPort !== $port) {
                continue;
            }

            if ($this->hasRecoverableManagedProcessHint($name)) {
                return $name;
            }
        }

        return null;
    }

    private function findPersistedRecoverableInstanceNameByPort(int $port): ?string
    {
        $manager = $this->getInstanceManager();
        foreach ($manager->listPersistedInstanceNames() as $name) {
            $ports = $this->collectRecoverablePortsFromInstanceRecords($name, false);
            $info = $manager->getInstanceInfo($name, false);
            if ($info !== null) {
                $ports = \array_merge($ports, $this->collectRecoverablePortsFromInstance($info, false));
            }
            $ports = \array_values(\array_unique(\array_filter(
                \array_map('intval', $ports),
                static fn (int $candidatePort): bool => $candidatePort > 0
            )));
            if (\in_array($port, $ports, true)) {
                return $name;
            }
        }

        return null;
    }

    private function isSharedStateIndexedPort(int $port): bool
    {
        if ($port <= 0) {
            return false;
        }

        $portIndex = Processer::readPortIndex();
        $processName = (string) ($portIndex[(string) $port] ?? $portIndex[$port] ?? '');

        return $processName !== '' && $this->isSharedStateProcessName($processName);
    }

    private function hasRecoverableManagedProcessHint(string $name): bool
    {
        $processNames = [
            MasterProcess::getMasterProcessName($name),
            MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $name),
            MasterProcess::buildScopedProcessName('weline-wls-session', $name),
            MasterProcess::buildScopedProcessName('weline-wls-memory', $name),
            MasterProcess::buildScopedProcessName(MasterProcess::HTTP_REDIRECT_PROCESS_NAME, $name),
            'weline-wls-dispatcher-' . $name,
            'weline-wls-session-' . $name,
            'weline-wls-memory-' . $name,
            MasterProcess::HTTP_REDIRECT_PROCESS_NAME . '-' . $name,
            'weline-master-' . $name . '-redirect-',
        ];

        foreach ($processNames as $processName) {
            $pname = '--name=' . $processName;
            $pid = (int) Processer::getData($pname, 'pid');
            if ($pid > 0 && Processer::isManagedProcessRunning($pid, $processName, '', $pname)) {
                return true;
            }
        }

        foreach ($this->getRecoverableManagedProcessPrefixes($name) as $prefix) {
            foreach (Processer::getProcessNamesByPrefix($prefix) as $pname) {
                $pname = (string) $pname;
                if ($pname === '') {
                    continue;
                }

                $processName = \str_starts_with($pname, '--name=')
                    ? \substr($pname, 7)
                    : $pname;
                $pid = (int) Processer::getData($pname, 'pid');
                if ($pid > 0 && Processer::isManagedProcessRunning($pid, $processName, '', $pname)) {
                    return true;
                }
            }
        }

        if ($this->collectRecoverableManagedPids($name) !== []) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getRecoverableManagedProcessPrefixes(string $name): array
    {
        $scopedInstance = MasterProcess::getScopedInstanceName($name);

        return [
            MasterProcess::buildScopedProcessName('weline-wls-worker', $name) . '-',
            MasterProcess::buildScopedProcessName('weline-wls-maintenance', $name) . '-',
            'weline-wls-worker-http-' . $scopedInstance . '-',
            'weline-wls-worker-ssl-' . $scopedInstance . '-',
            'weline-wls-maintenance-http-' . $scopedInstance . '-',
            'weline-wls-maintenance-ssl-' . $scopedInstance . '-',
        ];
    }

    private function hasRecoverableConfiguredPortHint(string $name): bool
    {
        foreach ($this->getRecoverableConfiguredPorts($name) as $port) {
            $inspect = $this->inspectRecoverablePortOccupant($port);
            $recoverableWlsPort = ((bool) ($inspect['in_use'] ?? false) && (bool) ($inspect['is_weline'] ?? false))
                || $this->isRecoverableWlsPortResponder($port);
            if ($recoverableWlsPort) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    protected function getRecoverableConfiguredPorts(string $name): array
    {
        if (!isset($this->recoverableConfiguredPortsCache[$name])) {
            $this->recoverableConfiguredPortsCache[$name] = $this->queryRecoverableConfiguredPorts($name);
        }

        return $this->recoverableConfiguredPortsCache[$name];
    }

    /**
     * @return list<int>
     */
    protected function queryRecoverableConfiguredPorts(string $name): array
    {
        $ports = [];
        $configFile = Env::VAR_DIR . 'server' . DS . 'config' . DS . $name . '.json';
        if (\is_file($configFile)) {
            $data = \json_decode((string) @\file_get_contents($configFile), true);
            if (\is_array($data)) {
                $mainPort = (int) ($data['port'] ?? 0);
                if ($mainPort > 0) {
                    $ports[] = $mainPort;
                }

                $httpRedirectPort = $this->resolveConfiguredHttpRedirectPort($data);
                if ($httpRedirectPort > 0) {
                    $ports[] = $httpRedirectPort;
                }
            }
        }

        $portIndexFile = Env::VAR_DIR . 'process' . DS . 'pid' . DS . 'port_index.json';
        if (\is_file($portIndexFile)) {
            $portIndex = \json_decode((string) @\file_get_contents($portIndexFile), true);
            if (\is_array($portIndex)) {
                $needle = '-' . $name . '-';
                $needleSuffix = '-' . $name;
                foreach ($portIndex as $portKey => $processName) {
                    $port = (int) $portKey;
                    if ($port <= 0 || !\is_string($processName)) {
                        continue;
                    }
                    if (!\str_contains($processName, 'weline-wls-') && !\str_contains($processName, 'weline-master-')) {
                        continue;
                    }
                    if ($this->isSharedStateProcessName($processName)) {
                        continue;
                    }
                    if (!\str_contains($processName, $needle) && !\str_ends_with($processName, $needleSuffix)) {
                        continue;
                    }

                    $ports[] = $port;
                }
            }
        }

        $ports = \array_values(\array_unique(\array_filter($ports, static fn (int $port): bool => $port > 0)));
        \sort($ports);

        return $ports;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function resolveConfiguredHttpRedirectPort(array $data): int
    {
        $httpRedirectPort = (int) ($data['http_redirect_port'] ?? 0);
        if ($httpRedirectPort > 0) {
            return $httpRedirectPort;
        }

        $mainPort = (int) ($data['port'] ?? 0);
        $sslEnabled = (bool) ($data['ssl_enabled'] ?? $mainPort === 443);
        if ($sslEnabled && $mainPort === 443) {
            return 80;
        }

        return 0;
    }

    protected function validateInstanceForIpcStop(ServerInstanceInfo $info): bool
    {
        if ($info->masterPid <= 0 || !Processer::processExists($info->masterPid)) {
            return false;
        }

        $expectedName = MasterProcess::getMasterProcessName($info->name);
        $cmd = Processer::getProcessCommandLine($info->masterPid);
        if ($cmd === '') {
            return false;
        }

        if (!\str_contains($cmd, $expectedName) && !\str_contains($cmd, '--name=' . $expectedName)) {
            return false;
        }

        $cp = $info->controlPort;
        if ($cp <= 0) {
            return false;
        }

        $inspect = $this->inspectRecoverablePortOccupant($cp);
        if (!($inspect['in_use'] ?? false)) {
            return false;
        }

        $ownerPid = (int) ($inspect['pid'] ?? 0);
        if ($ownerPid <= 0) {
            return false;
        }

        $scope = (string) ($inspect['scope'] ?? '');
        $own = MasterProcess::getProjectScopeToken();
        if ($scope !== '' && $scope !== $own) {
            return false;
        }

        return $ownerPid === $info->masterPid
            || \str_contains(Processer::getProcessCommandLine($ownerPid), $expectedName)
            || $this->isRecoverableManagedPort($cp, $inspect, $info);
    }

    protected function ipcAppendStopTraceHint(string $instanceName): void
    {
        $file = Env::VAR_DIR . 'server' . DS . 'control' . DS . $instanceName . '.stop.trace.jsonl';
        if (!\is_file($file)) {
            return;
        }

        $tail = @\file_get_contents($file, false, null, \max(0, \filesize($file) - 4096));
        if ($tail === false || $tail === '') {
            return;
        }

        $lines = \preg_split('/\R/', \trim($tail)) ?: [];
        $last = '';
        foreach ($lines as $line) {
            if ($line !== '') {
                $last = $line;
            }
        }

        if ($last === '') {
            return;
        }

        $decoded = \json_decode($last, true);
        $event = \is_array($decoded) ? (string) ($decoded['event'] ?? '') : '';
        if ($event !== '') {
            $this->printer->note(__('Master STOP 追踪文件末条事件：%{1}', [$event]));
        }
    }

    /**
     * @return array{
     *   in_use:bool,
     *   pid:int,
     *   pid_running:bool,
     *   is_weline:bool,
     *   state:string
     * }
     */
    protected function inspectRecoverablePortOccupant(int $port): array
    {
        if (!isset($this->recoverablePortOccupantCache[$port])) {
            $this->recoverablePortOccupantCache[$port] = $this->queryRecoverablePortOccupant($port);
        }

        return $this->recoverablePortOccupantCache[$port];
    }

    /**
     * @return array{
     *   in_use?:bool,
     *   pid?:int,
     *   pid_running?:bool,
     *   is_weline?:bool,
     *   state?:string
     * }
     */
    protected function inspectPortOccupantWithHistory(int $port): array
    {
        return $this->inspectRecoverablePortOccupant($port);
    }

    /**
     * @return array{
     *   in_use?:bool,
     *   pid?:int,
     *   pid_running?:bool,
     *   is_weline?:bool,
     *   state?:string
     * }
     */
    protected function queryRecoverablePortOccupant(int $port): array
    {
        return Processer::inspectPortOccupantWithHistory($port);
    }

    protected function terminateWlsPortProcess(int $port, int $pid): bool
    {
        $rootPid = $this->resolveManagedStopRootPid($pid);
        $killPid = $rootPid > 0 ? $rootPid : $pid;
        $this->logWlsPortTermination($port, $pid, $killPid);

        return $this->killManagedProcessTreeForStop($killPid);
    }

    protected function logWlsPortTermination(int $port, int $pid, int $killPid): void
    {
        $this->printer->note($this->translateStopMessage(
            '检测到端口 %{1} 被 WLS 进程占用 (PID: %{2})，正在结束…',
            [$port, $pid]
        ));

        if ($killPid !== $pid) {
            $this->printer->note($this->translateStopMessage('  ROOT PID: %{1}', [$killPid]));
        }
    }

    protected function translateStopMessage(string $message, array $args = []): string
    {
        $translator = __NAMESPACE__ . '\\__';
        if (\function_exists($translator)) {
            return $translator($message, $args);
        }

        $translated = $message;
        foreach (\array_values($args) as $index => $value) {
            $translated = \str_replace('%{' . ($index + 1) . '}', (string)$value, $translated);
        }

        return $translated;
    }

    protected function getPortProcessId(int $port): int
    {
        return Processer::getProcessIdByPort($port);
    }

    protected function isStopPidRunning(int $pid): bool
    {
        if (!isset($this->stopPidRunningCache[$pid])) {
            $this->stopPidRunningCache[$pid] = $this->queryStopPidRunning($pid);
        }

        return $this->stopPidRunningCache[$pid];
    }

    protected function getProcessPnameByPid(int $pid): string
    {
        return Processer::getNameByPid($pid);
    }

    protected function getStopProcessCommandLine(int $pid): string
    {
        if (!isset($this->stopProcessCommandLineCache[$pid])) {
            $this->stopProcessCommandLineCache[$pid] = $this->queryStopProcessCommandLine($pid);
        }

        return $this->stopProcessCommandLineCache[$pid];
    }

    protected function getStopProcessName(int $pid): string
    {
        if (!isset($this->stopProcessNameCache[$pid])) {
            $this->stopProcessNameCache[$pid] = $this->queryStopProcessName($pid);
        }

        return $this->stopProcessNameCache[$pid];
    }

    protected function isStopWelineServerProcess(int $pid): bool
    {
        if (!isset($this->stopWelineProcessCache[$pid])) {
            $this->stopWelineProcessCache[$pid] = $this->queryStopWelineServerProcess($pid);
        }

        return $this->stopWelineProcessCache[$pid];
    }

    protected function isStopProcessManagerCreated(int $pid): bool
    {
        if (!isset($this->stopProcessManagerCreatedCache[$pid])) {
            $this->stopProcessManagerCreatedCache[$pid] = $this->queryStopProcessManagerCreated($pid);
        }

        return $this->stopProcessManagerCreatedCache[$pid];
    }

    protected function getStopParentPid(int $pid): int
    {
        if (!isset($this->stopParentPidCache[$pid])) {
            $this->stopParentPidCache[$pid] = $this->queryStopParentPid($pid);
        }

        return $this->stopParentPidCache[$pid];
    }

    protected function killManagedProcessTreeForStop(int $pid): bool
    {
        $result = $this->queryKillManagedProcessTreeForStop($pid);
        if ($result) {
            $this->invalidateStopRuntimeState();
        }

        return $result;
    }

    protected function killStopPid(int $pid, bool $skipCheck = true): bool
    {
        $result = $this->queryKillStopPid($pid, $skipCheck);
        if ($result) {
            $this->invalidateStopRuntimeState();
        }

        return $result;
    }

    protected function isRecoverableWlsPortResponder(int $port): bool
    {
        $transports = $port === 80 ? ['tcp'] : ['ssl', 'tcp'];
        foreach ($transports as $transport) {
            $headers = $this->readRecoverablePortHeaders($port, $transport);
            if ($headers === '') {
                continue;
            }

            $headersLower = \strtolower($headers);
            if (\str_contains($headersLower, 'server: weline-server')
                || \str_contains($headersLower, 'x-powered-by: wls/')
                || \str_contains($headersLower, 'x-weline-route-hint:')
            ) {
                return true;
            }
        }

        return false;
    }

    protected function isRecoverablePortInUse(int $port): bool
    {
        return Processer::isPortInUse($port);
    }

    protected function cleanupStaleRecoverableProcessPidFiles(): void
    {
        Processer::cleanupStalePidFiles();
    }

    /**
     * @param list<int>|array<int> $pids
     */
    protected function cleanupStaleRecoverableProcessPidFilesForPids(array $pids): void
    {
        Processer::cleanupStalePidFilesForPids($pids);
    }

    protected function queryStopPidRunning(int $pid): bool
    {
        return Processer::isRunningByPid($pid);
    }

    protected function queryStopProcessCommandLine(int $pid): string
    {
        return Processer::getProcessCommandLine($pid);
    }

    protected function queryStopProcessName(int $pid): string
    {
        $info = Processer::getProcessInfo($pid);

        return (string) ($info['name'] ?? '');
    }

    protected function queryStopWelineServerProcess(int $pid): bool
    {
        return Processer::isWelineServerProcess($pid);
    }

    protected function queryStopProcessManagerCreated(int $pid): bool
    {
        return Processer::isProcessManagerCreated($pid);
    }

    protected function queryStopParentPid(int $pid): int
    {
        return Processer::getParentPidByPid($pid);
    }

    protected function queryKillManagedProcessTreeForStop(int $pid): bool
    {
        if ($this->isWindowsPlatform()) {
            return $this->killWindowsProcessForStop($pid, true);
        }

        return Processer::killProcessTreeByPid($pid, true);
    }

    protected function queryKillStopPid(int $pid, bool $skipCheck = true): bool
    {
        if ($this->isWindowsPlatform()) {
            return $this->killWindowsProcessForStop($pid, false, $skipCheck);
        }

        return Processer::killByPid($pid, $skipCheck);
    }

    protected function killWindowsProcessForStop(int $pid, bool $tree, bool $skipCheck = true): bool
    {
        if ($pid <= 0) {
            return false;
        }

        unset($skipCheck);

        $maxAttempts = $tree ? 3 : 2;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $exitCode = $this->executeWindowsTaskkillForStop($pid, $tree);
            unset($this->stopPidRunningCache[$pid]);
            if ($exitCode === 0 || !$this->isStopPidRunning($pid)) {
                return true;
            }

            if ($attempt < $maxAttempts) {
                SchedulerSystem::usleep(200000);
            }
        }

        if ($tree) {
            $exitCode = $this->executeWindowsTaskkillForStop($pid, false);
            unset($this->stopPidRunningCache[$pid]);
            return $exitCode === 0 || !$this->isStopPidRunning($pid);
        }

        return false;
    }

    protected function executeWindowsTaskkillForStop(int $pid, bool $tree): int
    {
        $output = [];
        $returnCode = 0;
        $command = 'taskkill /F '
            . ($tree ? '/T ' : '')
            . '/PID '
            . $pid
            . ' 1>NUL 2>NUL';
        Processer::execute($command, $output, $returnCode);

        return $returnCode;
    }

    protected function isWindowsShellWrapperForStop(int $pid): bool
    {
        $name = \strtolower($this->getStopProcessName($pid));
        $cmdLine = \strtolower($this->getStopProcessCommandLine($pid));

        return \in_array($name, ['cmd.exe', 'powershell.exe', 'pwsh.exe'], true)
            || \str_contains($cmdLine, 'cmd.exe')
            || \str_contains($cmdLine, '\\cmd ')
            || \str_contains($cmdLine, 'powershell')
            || \str_contains($cmdLine, 'pwsh');
    }

    /**
     * @return list<int>
     */
    protected function collectRecoverableManagedPids(string $name): array
    {
        if (!isset($this->recoverableManagedPidsCache[$name])) {
            $this->recoverableManagedPidsCache[$name] = $this->queryRecoverableManagedPids($name);
        }

        return $this->recoverableManagedPidsCache[$name];
    }

    /**
     * @return list<int>
     */
    protected function queryRecoverableManagedPids(string $name): array
    {
        $pidDir = Env::VAR_DIR . 'process' . DS . 'pid' . DS;
        if (!\is_dir($pidDir)) {
            return [];
        }

        $scopedInstanceSuffix = '-' . MasterProcess::getScopedInstanceName($name);
        $legacyWorkerPrefix = 'weline-master-' . $name . '-worker-';
        $legacyPrefixes = [
            'weline-wls-worker-' . $name . '-',
            'weline-wls-maintenance-' . $name . '-',
            'weline-wls-dispatcher-' . $name,
            MasterProcess::HTTP_REDIRECT_PROCESS_NAME . '-' . $name,
            $legacyWorkerPrefix,
            'weline-master-' . $name . '-redirect-',
            'weline-master-' . $name . '-maintenance-',
        ];
        $pids = [];

        foreach (\glob($pidDir . '*-pid.json') ?: [] as $file) {
            $record = \json_decode((string) @\file_get_contents($file), true);
            if (!\is_array($record)) {
                continue;
            }

            $taskName = \trim((string) ($record['task_name'] ?? $record['process_name'] ?? ''));
            $pname = (string) ($record['pname'] ?? '');
            if ($taskName === '' && $pname !== '') {
                try {
                    $taskName = Processer::getTaskName($pname);
                } catch (\Throwable) {
                    $taskName = \str_starts_with($pname, '--name=')
                        ? \substr($pname, 7)
                        : $pname;
                }
            }
            if ($this->isSharedStateProcessName($taskName)) {
                continue;
            }

            $isCurrentInstance = \str_starts_with($taskName, 'weline-wls-')
                && \str_contains($taskName, $scopedInstanceSuffix);
            if (!$isCurrentInstance) {
                foreach ($legacyPrefixes as $legacyPrefix) {
                    if (\str_starts_with($taskName, $legacyPrefix)) {
                        $isCurrentInstance = true;
                        break;
                    }
                }
            }
            if (
                !$isCurrentInstance
                && $pname !== ''
                && (\str_contains($pname, '"' . $name . '"') || \str_contains($pname, '--instance-name=' . $name))
            ) {
                $isCurrentInstance = true;
            }
            if (!$isCurrentInstance) {
                continue;
            }

            $pid = (int) ($record['pid'] ?? 0);
            if ($pid > 0) {
                $pids[$pid] = true;
            }

            if ($pname !== '' && \preg_match('/--master-pid=(\d+)/', $pname, $matches) === 1) {
                $masterPid = (int) ($matches[1] ?? 0);
                if ($masterPid > 0) {
                    $pids[$masterPid] = true;
                }
            }
        }

        return \array_map('intval', \array_keys($pids));
    }

    /**
     * @param list<int> $pids
     */
    protected function terminateRecoverableProcessIds(array $pids): int
    {
        return $this->terminateResidualProcesses($pids, true);
    }

    protected function killRecoverableProcessPrefix(string $prefix): int
    {
        return Processer::killByProcessNamePrefix($prefix);
    }

    protected function killStopProcessPrefix(string $prefix): int
    {
        return Processer::killByProcessNamePrefix($prefix);
    }

    protected function cleanupRecoverableConfiguredPort(int $port, ?ServerInstanceInfo $info = null): bool
    {
        $inspect = $this->inspectRecoverablePortOccupant($port);
        if ($this->isSharedStatePortOccupant($inspect)) {
            return false;
        }
        $recoverableWlsPort = ((bool) ($inspect['in_use'] ?? false) && (bool) ($inspect['is_weline'] ?? false))
            || $this->isRecoverableWlsPortResponder($port)
            || $this->isRecoverableManagedPort($port, $inspect, $info);
        if (!$recoverableWlsPort) {
            return false;
        }

        if ($this->killWlsProcessOnPort($port)) {
            SchedulerSystem::usleep(500000);

            return !$this->isRecoverablePortInUse($port);
        }

        $pid = (int) ($inspect['pid'] ?? 0);
        if ($pid <= 0 || !$this->isStopPidRunning($pid)) {
            return false;
        }

        $this->killManagedProcessTreeForStop($pid);
        SchedulerSystem::usleep(500000);

        return !$this->isRecoverablePortInUse($port);
    }

    /**
     * @param list<int> $ports
     */
    protected function cleanupRecoverableConfiguredPorts(
        array $ports,
        ?ServerInstanceInfo $info = null,
        bool $includeSharedState = false
    ): int
    {
        $candidates = $this->collectRecoverablePortKillCandidates($ports, $info, $includeSharedState);
        if ($candidates === []) {
            if ($ports !== []) {
                $this->invalidateStopRuntimeState();
                SchedulerSystem::usleep(100000);
            } else {
                return 0;
            }
            $candidates = $this->collectRecoverablePortKillCandidates($ports, $info, $includeSharedState);
            if ($candidates === []) {
                return 0;
            }
        }

        $this->printer->note($this->translateStopMessage(
            '检测到 %{1} 个端口被 WLS 进程占用，正在并发结束…',
            [\count($candidates)]
        ));

        $killPids = [];
        foreach ($candidates as $candidate) {
            $port = (int) $candidate['port'];
            $pid = (int) $candidate['pid'];
            $killPid = (int) $candidate['kill_pid'];

            $this->printer->note($this->translateStopMessage(
                '  端口 %{1}: PID %{2}',
                [$port, $pid]
            ));
            if ($killPid !== $pid) {
                $this->printer->note($this->translateStopMessage('    ROOT PID: %{1}', [$killPid]));
            }

            $killPids[$killPid] = $killPid;
        }

        $processed = $this->terminateRecoverableProcessIds(\array_values($killPids));
        if ($processed > 0) {
            SchedulerSystem::usleep(500000);
        }
        $this->invalidateStopRuntimeState();

        return $processed;
    }

    /**
     * @param list<int> $ports
     * @return list<array{port:int,pid:int,kill_pid:int}>
     */
    protected function collectRecoverablePortKillCandidates(
        array $ports,
        ?ServerInstanceInfo $info = null,
        bool $includeSharedState = false
    ): array
    {
        $candidates = [];
        $uniquePorts = \array_values(\array_unique(\array_filter(
            \array_map('intval', $ports),
            static fn (int $port): bool => $port > 0
        )));

        foreach ($uniquePorts as $port) {
            $inspect = $this->inspectRecoverablePortOccupant($port);
            $recoverableWlsPort = ((bool) ($inspect['in_use'] ?? false) && (bool) ($inspect['is_weline'] ?? false))
                || $this->isRecoverableWlsPortResponder($port)
                || $this->isRecoverableManagedPort($port, $inspect, $info);
            if (!$recoverableWlsPort) {
                continue;
            }

            $pid = $this->resolveRecoverablePortProcessId($port, $inspect);
            if ($pid <= 0) {
                continue;
            }

            $pname = $this->getProcessPnameByPid($pid);
            if (!$includeSharedState && $this->isSharedStateProcessName($pname)) {
                continue;
            }
            $isWls = \str_contains($pname, 'weline-wls')
                || \str_contains($pname, 'weline-master')
                || $recoverableWlsPort;

            if (!$isWls && !$recoverableWlsPort) {
                continue;
            }

            $rootPid = $this->resolveManagedStopRootPid($pid);
            $killPid = $rootPid > 0 ? $rootPid : $pid;
            $candidates[] = [
                'port' => $port,
                'pid' => $pid,
                'kill_pid' => $killPid,
            ];
        }

        return $candidates;
    }

    /**
     * @param array{pid?:int,pid_running?:bool} $inspect
     */
    protected function resolveRecoverablePortProcessId(int $port, array $inspect): int
    {
        $pid = (int) ($inspect['pid'] ?? 0);
        if ($pid > 0 && $this->isStopPidRunning($pid)) {
            return $pid;
        }

        $pid = $this->getPortProcessId($port);
        if ($pid > 0 && $this->isStopPidRunning($pid)) {
            return $pid;
        }

        $this->invalidateStopRuntimeState();
        Processer::clearPortCache($port);
        SchedulerSystem::usleep(100000);

        $freshInspect = $this->inspectRecoverablePortOccupant($port);
        if (!($freshInspect['in_use'] ?? false)) {
            return 0;
        }

        $pid = (int) ($freshInspect['pid'] ?? 0);
        if ($pid > 0 && $this->isStopPidRunning($pid)) {
            return $pid;
        }

        $pid = $this->getPortProcessId($port);
        return $pid > 0 && $this->isStopPidRunning($pid) ? $pid : 0;
    }

    protected function readRecoverablePortHeaders(int $port, string $transport): string
    {
        $cacheKey = $port . ':' . $transport;
        if (!isset($this->recoverablePortHeadersCache[$cacheKey])) {
            $this->recoverablePortHeadersCache[$cacheKey] = $this->queryRecoverablePortHeaders($port, $transport);
        }

        return $this->recoverablePortHeadersCache[$cacheKey];
    }

    protected function queryRecoverablePortHeaders(int $port, string $transport): string
    {
        $scheme = $transport === 'ssl' ? 'ssl' : 'tcp';
        $contextOptions = [];
        if ($scheme === 'ssl') {
            $contextOptions['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
            ];
        }

        $context = \stream_context_create($contextOptions);
        $socket = @\stream_socket_client(
            "{$scheme}://127.0.0.1:{$port}",
            $errno,
            $errstr,
            1.0,
            STREAM_CLIENT_CONNECT,
            $context
        );
        unset($errno, $errstr);
        if (!\is_resource($socket)) {
            return '';
        }

        @\stream_set_timeout($socket, 1);
        @\fwrite($socket, "HEAD / HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

        $headers = '';
        while (!\feof($socket) && \strlen($headers) < 4096) {
            $chunk = (string) @\fgets($socket, 512);
            if ($chunk === '') {
                $meta = @\stream_get_meta_data($socket);
                if (($meta['timed_out'] ?? false) === true) {
                    break;
                }
            }

            $headers .= $chunk;
            if (\str_contains($headers, "\r\n\r\n")) {
                break;
            }
        }

        @\fclose($socket);

        return $headers;
    }

    private function cleanupRecoverableProcessesWithoutInstanceFile(string $name, bool $dryRun = false): int
    {
        $prefixes = [
            MasterProcess::getMasterProcessName($name),
            MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $name),
            MasterProcess::buildScopedProcessName('weline-wls-worker', $name) . '-',
            MasterProcess::buildScopedProcessName(MasterProcess::HTTP_REDIRECT_PROCESS_NAME, $name),
            'weline-wls-dispatcher-' . $name,
            'weline-wls-worker-' . $name . '-',
            'weline-master-' . $name . '-worker-',
            'weline-master-' . $name . '-redirect-',
            MasterProcess::HTTP_REDIRECT_PROCESS_NAME . '-' . $name,
        ];

        $recoverablePids = \array_values(\array_unique(\array_filter(
            $this->collectRecoverableManagedPids($name),
            static fn (int $pid): bool => $pid > 0
        )));
        $recoverable = 0;
        foreach ($prefixes as $prefix) {
            if ($dryRun) {
                if (
                    $this->hasRecoverableManagedProcessHint($name)
                    || $this->hasRecoverableConfiguredPortHint($name)
                    || $recoverablePids !== []
                ) {
                    return 1;
                }
                continue;
            }
            $recoverable += $this->killRecoverableProcessPrefix($prefix);
        }

        if (!$dryRun) {
            if ($recoverablePids !== []) {
                $recoverable += $this->terminateRecoverableProcessIds($recoverablePids);
            }

            foreach ($this->getRecoverableConfiguredPorts($name) as $port) {
                if ($this->cleanupRecoverableConfiguredPort((int) $port)) {
                    $recoverable++;
                }
            }

            $this->cleanupStaleRecoverableProcessPidFiles();
        }

        return $recoverable;
    }
    
    /**
     * 停止所有实例
     */
    protected function stopAllInstances(bool $force = false, bool $fastLocal = false): void
    {
        $manager = $this->getInstanceManager();
        $instances = $this->collectStopAllInstanceNames($manager);
        $cliService = ObjectManager::getInstance(CliServerService::class);
        $cliStatus = $cliService->getCliServerStatus();

        if (empty($instances) && !$cliStatus) {
            $this->printer->warning(__('没有正在运行的实例'));
            return;
        }

        $this->printer->setup(__('停止所有服务器实例'));
        echo "\n";

        if (!empty($instances)) {
            $totalInstances = \count($instances);
            $this->printer->note(__('发现 %{1} 个 Weline Server 实例', [$totalInstances]));
            echo "\n";
            foreach ($instances as $name) {
                $this->printer->note(__('正在停止实例 [%{1}]...', [$name]));
                if (!$this->acquireStopLock($name)) {
                    $this->printer->warning(__('实例 [%{1}] 正在被其他 stop 任务处理，已跳过。', [$name]));
                    continue;
                }
                try {
                    $this->stopInstance($name, $force, $fastLocal);
                } finally {
                    $this->releaseStopLock();
                }
                echo "\n";
            }
            $this->printer->success(__('所有 Weline Server 实例已停止'));
        }

        if ($cliStatus) {
            echo "\n";
            $this->printer->note(__('正在停止 PHP 内置服务器 (cli-server)...'));
            $this->stopCliServer($force);
            $this->printer->success(__('PHP 内置服务器已停止'));
        }
    }

    /**
     * @return list<string>
     */
    protected function collectStopAllInstanceNames(ServerInstanceManager $manager): array
    {
        $names = [];
        foreach ($manager->listPersistedInstanceNames() as $name) {
            $info = $manager->getInstanceInfo($name, false);
            if ($info === null) {
                if ($this->hasRecoverableManagedProcessHint($name) || $this->hasRecoverableConfiguredPortHint($name)) {
                    $names[] = $name;
                }
                continue;
            }

            if (!$this->isInactiveStoppedInstanceRecord($name, $info)) {
                $names[] = $name;
            }
        }

        return \array_values(\array_unique($names));
    }

    protected function isInactiveStoppedInstanceRecord(string $name, ServerInstanceInfo $info): bool
    {
        $rawData = $this->getRawStopInstanceData($name) ?? [];
        $state = (string) ($rawData['lifecycle_state'] ?? $rawData['startup_phase'] ?? '');
        if (!\in_array($state, ['stopped', 'stale_cleanup', 'master_exited'], true)) {
            return false;
        }

        return !$this->hasRecoverablePersistedInstanceRuntimeHint($name, $info);
    }

    protected function hasRecoverablePersistedInstanceRuntimeHint(string $name, ?ServerInstanceInfo $info = null): bool
    {
        $pids = $this->collectResidualPidsFromInstanceRecords($name, true);
        if ($info !== null) {
            $pids = \array_merge($pids, $this->collectResidualPidsByInfo($info, true));
        }
        if ($this->collectRunningResidualPids($pids) !== []) {
            return true;
        }

        $ports = $this->collectRecoverablePortsFromInstanceRecords($name, true);
        if ($info !== null) {
            $ports = \array_merge($ports, $this->collectRecoverablePortsFromInstance($info, true));
        }
        foreach (\array_values(\array_unique(\array_map('intval', $ports))) as $port) {
            if ($port <= 0) {
                continue;
            }
            $inspect = $this->inspectRecoverablePortOccupant($port);
            if (!($inspect['in_use'] ?? false)) {
                continue;
            }
            if (
                (bool) ($inspect['is_weline'] ?? false)
                || $this->isRecoverableWlsPortResponder($port)
                || $this->isRecoverableManagedPort($port, $inspect, $info)
            ) {
                return true;
            }
        }

        return $this->hasRecoverableManagedProcessHint($name) || $this->hasRecoverableConfiguredPortHint($name);
    }

    /**
     * 停止 PHP 内置 CLI 服务器
     */
    protected function stopCliServer(bool $force = false): void
    {
        $args = $force ? ['force' => true, 'f' => true] : [];
        $cliStop = ObjectManager::getInstance(CliStop::class);
        $cliStop->execute($args, []);
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('停止 Weline Server 或 PHP 内置服务器实例');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:stop [name]',
            __('停止正在运行的 Weline Server 或 PHP 内置服务器实例'),
            [
                '[name]' => __('实例名称（默认：default；cli/cli-server 表示 PHP 内置服务器）'),
                '-a, --all' => __('停止所有运行中的实例（含 Weline Server 与 CLI 服务器）'),
                '-f, --force' => __('强制停止：默认本地快速清场（不走 IPC）；若需仍通过 Master 发 STOP，请加 --ipc'),
                '--ipc, --force-ipc' => __('与 -f 联用：显式走 IPC 强制停机（短 ACK/硬超时）；不带 -f 时忽略'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('停止默认实例') => 'php bin/w server:stop',
                __('停止指定实例') => 'php bin/w server:stop api-server',
                __('停止 PHP 内置服务器') => 'php bin/w server:stop cli-server',
                __('停止所有实例') => 'php bin/w server:stop --all',
                __('强制停止（本地快速清场）') => 'php bin/w server:stop -f',
                __('强制但走 IPC（短超时）') => 'php bin/w server:stop -f --ipc',
            ]
        );
    }

    /**
     * 打印欢迎语
     */
    protected function printWelcome(): void
    {
        $width = 60;
        $title = 'Weline Framework Server';
        $action = __('正在停止服务器...');
        $padding = ($width - \mb_strlen($title) - \mb_strlen($action) - 3) / 2;

        $this->printer->note('');
        $this->printer->note($this->colorize(str_repeat('═', $width), 'Yellow'));
        $this->printer->note(
            $this->colorize('║', 'Yellow') .
            \str_repeat(' ', $width - 2) .
            $this->colorize('║', 'Yellow')
        );
        $this->printer->note(
            $this->colorize('║', 'Yellow') .
            \str_repeat(' ', (int)\floor($padding)) .
            $this->colorize($title, 'Yellow') .
            ' ' .
            $this->colorize($action, 'Red') .
            \str_repeat(' ', (int)\ceil($padding)) .
            $this->colorize('║', 'Yellow')
        );
        $this->printer->note(
            $this->colorize('║', 'Yellow') .
            \str_repeat(' ', $width - 2) .
            $this->colorize('║', 'Yellow')
        );
        $this->printer->note($this->colorize(str_repeat('═', $width), 'Yellow'));
        $this->printer->note('');
    }

    /**
     * 打印结束语
     *
     * @param bool $success 是否成功
     * @param string $message 附加消息
     */
    protected function printGoodbye(bool $success = true, string $message = ''): void
    {
        $this->printer->note('');

        if ($success) {
            $this->printer->successIcon(__('Weline Server 已停止！'));
        } else {
            $this->printer->errorIcon(__('Weline Server 停止失败'));
        }

        if ($message) {
            $this->printer->note('  ' . $message);
        }

        $this->printer->note('');
        $this->printer->note($this->colorize(str_repeat('─', 60), 'Yellow'));
        $this->printer->note('');
    }

    /**
     * 彩色化输出（内部使用 ANSI 颜色）
     *
     * @param string $text 文本
     * @param string $color 颜色
     * @return string
     */
    private function colorize(string $text, string $color): string
    {
        $colors = [
            'Black' => '30',
            'Red' => '31',
            'Green' => '32',
            'Yellow' => '33',
            'Blue' => '34',
            'Magenta' => '35',
            'Cyan' => '36',
            'White' => '37',
        ];

        $code = $colors[$color] ?? '33';
        return "\033[{$code}m{$text}\033[0m";
    }
}
