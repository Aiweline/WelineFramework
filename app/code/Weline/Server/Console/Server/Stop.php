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
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Runtime\RuntimeSelection;
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

    /**
     * Keep the historical one-argument protected extension point compatible;
     * restart cleanup supplies its preservation policy as invocation state.
     */
    private bool $preserveSharedStateConsumersForRestartCleanup = false;

    /** IPC 等待超时（秒）- 与 Windows 一致，不长时间等待，超时后强制杀进程 */
    private const IPC_TIMEOUT = 6;
    private const IPC_FORCE_TIMEOUT = 3;

    private const RESIDUAL_CLEANUP_MAX_ATTEMPTS = 3;
    private const RESIDUAL_CLEANUP_RETRY_USEC = 300000;

    /** IPC 硬超时（秒）- 避免进度持续刷新时无限等待 */
    private const IPC_HARD_TIMEOUT_WIN = 12;
    private const IPC_HARD_TIMEOUT_LINUX = 12;
    /** `-f --ipc`：短硬超时，避免假死长等 */
    private const IPC_FORCE_HARD_TIMEOUT_WIN = 4;
    private const IPC_FORCE_HARD_TIMEOUT_LINUX = 4;
    
    /** 子进程全部退出后等待 Master 退出的最大时间（秒）- Linux 上 Master 清理索引/退出主循环较慢，需更长超时 */
    private const MASTER_EXIT_TIMEOUT_WIN = 2;
    private const MASTER_EXIT_TIMEOUT_LINUX = 5;
    
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

        $instanceNames = $this->parseInstanceNames($args);
        $instancePrefix = $this->parseInstancePrefix($args);
        $stopAll = isset($args['all']) || isset($args['a']);
        $force = isset($args['force']) || isset($args['f']);
        $forceIpc = isset($args['ipc']) || isset($args['force-ipc']) || isset($args['force_ipc']);
        $fastLocal = isset($args['fast-local'])
            || isset($args['fast_local'])
            || ($force && !$forceIpc);
        $restartCleanup = isset($args['restart-cleanup']) || isset($args['restart_cleanup']);

        if ($stopAll && $instancePrefix !== '') {
            $this->printer->error(__('`--all` 与 `-pre/--prefix` 不能同时使用。'));
            $this->printer->note(__('请二选一：要么停止所有实例，要么只停止某个前缀。'));
            return;
        }

        if ($stopAll) {
            $this->stopAllInstances($force, $fastLocal);
            return;
        }

        if ($instancePrefix !== '') {
            $this->stopInstancesByPrefix($instancePrefix, $force, $fastLocal);
            return;
        }

        if (\count($instanceNames) > 1) {
            $this->stopNamedInstances($instanceNames, $force, $fastLocal, $restartCleanup);
            return;
        }

        $instanceName = $instanceNames[0];
        if (!$this->acquireStopLock($instanceName)) {
            $this->printer->warning(__('另一个 server:stop 任务正在处理中，请稍后再试。'));
            $this->printer->note(__('若锁文件长期存在，请确认停止任务是否已结束后再重试。'));
            return;
        }
        try {
            $this->stopInstance($instanceName, $force, $fastLocal, $restartCleanup);
        } finally {
            $this->releaseStopLock();
        }
    }
    
    /**
     * Stop per-project managed nginx (if running) before tearing down WLS.
     */
    private function maybeStopManagedNginx(): void
    {
        try {
            $service = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv();
            if (!$service->paths()->managedEnabled()) {
                return;
            }
            $status = $service->doctorSnapshot();
            if (!(bool)($status['running'] ?? false) && !(bool)($status['installed'] ?? false)) {
                return;
            }
            $result = $service->stop();
            if ($result['ok'] ?? false) {
                $this->printer->note(__('托管 Nginx：%{1}', [(string)$result['message']]));
            }
        } catch (\Throwable $e) {
            $this->printer->warning(__('托管 Nginx 停止异常：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        return $this->parseInstanceNames($args)[0];
    }

    /**
     * @return list<string>
     */
    protected function parseInstanceNames(array $args): array
    {
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string) $arg, '-')) {
                $name = \trim((string) $arg);
                if ($name !== '') {
                    $positionalArgs[] = $name;
                }
            }
        }
        \array_shift($positionalArgs);

        if ($positionalArgs === []) {
            return ['default'];
        }

        return \array_values(\array_unique($positionalArgs));
    }

    protected function parseInstancePrefix(array $args): string
    {
        $prefix = $args['pre'] ?? $args['prefix'] ?? '';
        if (\is_array($prefix)) {
            $prefix = $prefix[0] ?? '';
        }

        return \trim((string) $prefix);
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
     * 其它情况（端口空闲、自家占用、无作用域段进程）返回 null。
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
        if ($pid <= 0) {
            return false;
        }
        $pname = $this->getProcessPnameByPid($pid);
        $isWls = \str_contains($pname, 'weline-wls')
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
    protected function stopInstance(
        string $name,
        bool $force = false,
        bool $fastLocal = false,
        bool $restartCleanup = false
    ): void
    {
        // CLI 服务器委托给专用处理
        $nameLower = strtolower($name);
        if ($nameLower === 'cli' || $nameLower === 'cli-server') {
            $this->stopCliServer($force);
            return;
        }

        $this->maybeStopManagedNginx();

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
        $masterAvailableForStop = $this->isMasterProcessAvailableForStop($instanceInfo);
        if (!$masterAvailableForStop) {
            $this->printer->warning(__('Master 进程不存在 (PID: %{1})', [$masterPid]));
            $this->showInstanceInfo($instanceInfo);
            // Master 失联后子进程脱离 Orchestrator/IPC；必须含 Session/Memory，否则会残留为「逃逸」进程。
            $includeSharedStateCleanup = $this->resolveMasterGoneResidualIncludeSharedState($instanceInfo);

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
                    $this->invokeReleaseSharedStateConsumersForInstance($name, $restartCleanup);
                    $manager->deleteInstance($name);
                    $this->cleanupPidFiles($name, $instanceInfo);
                    $this->releaseStartLock($name);
                    $this->printer->success(__('实例元数据已标记停止并保留 ✓'));
                    return;
                }
            }

            $this->terminateKnownSubprocessesAfterMasterGone($instanceInfo);

            // 清理可能残留的进程和文件（含按 PID 与按名前缀，确保 Worker/Dispatcher 等全部退出）
            $this->runResidualCleanupPairWithRetry($name, $instanceInfo, $includeSharedStateCleanup);
            if (!$this->wasLastResidualCleanupComplete()) {
                $this->printer->warning(__('实例 [%{1}] 仍有残留 WLS 进程，已保留实例文件以便继续清理。', [$name]));
                return;
            }
            $this->invokeReleaseSharedStateConsumersForInstance($name, $restartCleanup);
            $manager->deleteInstance($name);
            $this->cleanupPidFiles($name, $instanceInfo);
            $this->releaseStartLock($name);
            $this->printer->success(__('实例元数据已标记停止并保留 ✓'));
            return;
        }
        
        // 显示实例信息
        $this->showInstanceInfo($instanceInfo);
        echo "\n";

        if (!$fastLocal && $masterAvailableForStop) {
            if (!$this->validateInstanceForIpcStop($instanceInfo)) {
                $this->printer->warning(__('实例 Master PID 或控制端口归属校验未通过，跳过 IPC，改用本地清理。'));
                $fastLocal = true;
            }
        }

        if ($fastLocal) {
            $this->printer->note(__('快速清场模式：跳过 IPC 排水，并发终止旧实例子进程...'));
            $this->runFastLocalResidualCleanup($name, $instanceInfo);
            if (!$this->wasLastResidualCleanupComplete()) {
                echo "\n";
                $this->printer->warning(__('实例 [%{1}] 仍有残留进程，已保留实例文件以便继续清理。', [$name]));
                return;
            }
            $this->invokeReleaseSharedStateConsumersForInstance($name, $restartCleanup);
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
            if ($masterPid > 0 && $this->terminateResidualProcesses([$masterPid], false) > 0) {
                SchedulerSystem::usleep(500000);
            }
            $this->runResidualCleanupPairWithRetry($name, $instanceInfo, true);
            if (!$this->wasLastResidualCleanupComplete()) {
                $this->printer->warning(__('Instance [%{1}] still has residual WLS processes; keeping instance metadata for continued cleanup.', [$name]));
                return;
            }
            $this->invokeReleaseSharedStateConsumersForInstance($name, $restartCleanup);
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
            // Master IPC is the runtime authority. Once Master reports a full
            // stop, do not run the expensive local prefix/port residual scan on
            // the success path; it belongs to IPC failure and fast-local modes.
            $this->lastResidualCleanupComplete = true;
        } else {
            if ($this->lastIpcStopFlowStillActive) {
                $this->printer->warning(__('Stop flow is still running in Master after the CLI wait ended; keeping instance metadata and skipping local cleanup.'));
                return;
            }
            // IPC 失败，强制杀死 Master 并彻底清理该实例下所有进程（含 Worker/Dispatcher 等）
            $this->printer->warning(__('IPC 超时，验证 Master 实时身份后清理该实例下所有进程...'));
            if ($this->terminateResidualProcesses([$masterPid], false) > 0) {
                SchedulerSystem::usleep(500000);
            }
            
            $this->runResidualCleanupPairWithRetry($name, $instanceInfo, true);
            if (!$this->wasLastResidualCleanupComplete()) {
                $this->printer->warning(__('Instance [%{1}] still has residual WLS processes; keeping instance metadata for continued cleanup.', [$name]));
                return;
            }
        }
        
        // 从共享 Session/Memory 注册表移除本实例消费者，避免误导其它工具
        $this->invokeReleaseSharedStateConsumersForInstance($name, $restartCleanup);

        // 标记 endpoint 停止；运行态恢复必须回到 Master IPC，不从文件推断拓扑。
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
        unset($info);
        return false;
    }
    
    /**
     * 显示实例信息（统一入口，使用 ServerInstanceInfo 对象）
     *
     * 所有信息都来自 ServerInstanceManager，确保一致性。
     */
    protected function isMasterProcessAvailableForStop(ServerInstanceInfo $info): bool
    {
        // The control connection is the stop authority. Avoid slow Windows
        // tasklist/PowerShell PID probes before attempting IPC; a stale endpoint
        // fails quickly and falls back to local cleanup.
        return $info->masterPid > 0 && $info->controlPort > 0;
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

    protected function isMasterPidMissingFromIndex(int $masterPid): bool
    {
        if ($masterPid <= 0) {
            return true;
        }

        $pidIndex = Processer::readPidIndex();

        return !isset($pidIndex[$masterPid]);
    }

    protected function showInstanceInfo(ServerInstanceInfo $info): void
    {
        $boxInnerWidth = 62;
        $this->printer->note('╔' . \str_repeat('═', $boxInnerWidth) . '╗');
        $this->printer->note($this->renderStopBoxContent((string) __('停止服务器实例'), $boxInnerWidth, STR_PAD_BOTH));
        $this->printer->note('╠' . \str_repeat('═', $boxInnerWidth) . '╣');
        $this->printer->note($this->renderStopBoxContent('  ' . __('实例名称：') . $info->name, $boxInnerWidth));
        $this->printer->note($this->renderStopBoxContent('  ' . __('Master PID：') . ($info->masterPid > 0 ? $info->masterPid : __('(未运行)')), $boxInnerWidth));
        $this->printer->note($this->renderStopBoxContent('  ' . __('控制端口：') . ($info->controlPort > 0 ? $info->controlPort : __('(未配置)')), $boxInnerWidth));
        $this->printer->note($this->renderStopBoxContent('  ' . __('监听地址：') . $info->getListenAddress(), $boxInnerWidth));
        $this->printer->note($this->renderStopBoxContent('  ' . __('SSL 状态：') . ($info->sslEnabled ? __('已启用 (HTTPS)') : __('未启用 (HTTP)')), $boxInnerWidth));
        
        if ($info->httpRedirectPort > 0) {
            $this->printer->note($this->renderStopBoxContent('  ' . __('HTTP 跳转：') . ":{$info->httpRedirectPort} -> :{$info->port}", $boxInnerWidth));
        }
        
        $this->printer->note('╠' . \str_repeat('═', $boxInnerWidth) . '╣');
        $this->printer->note($this->renderStopBoxContent('  ' . __('启动时间：') . ($info->startedAt ?: __('(未知)')), $boxInnerWidth));
        $this->printer->note('╚' . \str_repeat('═', $boxInnerWidth) . '╝');
    }

    protected function renderStopBoxContent(string $content, int $width, int $padType = STR_PAD_RIGHT): string
    {
        return '║' . $this->padStopDisplayWidth($content, $width, $padType) . '║';
    }

    protected function padStopDisplayWidth(string $text, int $width, int $padType = STR_PAD_RIGHT): string
    {
        if ($width <= 0) {
            return '';
        }

        $displayText = $text;
        $displayWidth = $this->stopDisplayWidth($displayText);
        if ($displayWidth > $width) {
            if (\function_exists('mb_strimwidth')) {
                $displayText = (string) \mb_strimwidth($displayText, 0, $width, '', 'UTF-8');
            } else {
                $displayText = \substr($displayText, 0, $width);
            }
            $displayWidth = $this->stopDisplayWidth($displayText);
        }

        $padding = $width - $displayWidth;
        if ($padding <= 0) {
            return $displayText;
        }

        return match ($padType) {
            STR_PAD_LEFT => \str_repeat(' ', $padding) . $displayText,
            STR_PAD_BOTH => \str_repeat(' ', \intdiv($padding, 2)) . $displayText . \str_repeat(' ', $padding - \intdiv($padding, 2)),
            default => $displayText . \str_repeat(' ', $padding),
        };
    }

    protected function stopDisplayWidth(string $text): int
    {
        $plainText = \preg_replace('/\e\[[\d;]*m/', '', $text) ?? $text;
        if (\function_exists('mb_strwidth')) {
            return \mb_strwidth($plainText, 'UTF-8');
        }

        return \strlen($plainText);
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
        $ports = \array_merge($ports, $this->collectRecoverablePortsFromEndpointRecord($name, $includeSharedState));

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

        return \array_values(\array_unique(\array_filter(
            \array_map('intval', $ports),
            static fn (int $port): bool => $port > 0
        )));
    }

    /**
     * @return list<int>
     */
    protected function collectRecoverablePortsFromEndpointRecord(string $name, bool $includeSharedState = false): array
    {
        $rawData = $this->getRawStopInstanceData($name);
        if ($rawData === null) {
            return [];
        }

        $ports = $this->collectRecoverablePortsFromRecord($rawData, $includeSharedState);

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
        unset($includeSharedState);

        $selection = RuntimeSelection::fromEndpoint($record);
        $direct = $selection->isDirect();
        $ports = [];

        $recordFields = $direct
            ? ['port', 'main_port', 'control_port', 'http_redirect_port']
            : ['port', 'main_port', 'dispatcher_port', 'worker_port', 'worker_base_port', 'control_port', 'http_redirect_port'];
        foreach ($recordFields as $field) {
            $port = (int)($record[$field] ?? 0);
            if ($port > 0) {
                $ports[] = $port;
            }
        }

        if (!$direct) {
            $count = \max(1, (int)($record['count'] ?? 1));
            foreach (['worker_base_port', 'worker_port'] as $baseField) {
                $basePort = (int)($record[$baseField] ?? 0);
                if ($basePort <= 0 || $count <= 1) {
                    continue;
                }
                for ($offset = 1; $offset < $count; $offset++) {
                    $ports[] = $basePort + $offset;
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
        if ($pid <= 0) {
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
            $this->collectResidualPidsFromEndpointRecord($name),
            $this->collectIndexedResidualPids($name),
            $this->collectResidualPrefixPids($name)
        )));
    }

    protected function terminateDirectForceStopCandidatePids(ServerInstanceInfo $info): int
    {
        $candidates = $this->collectDirectForceStopCandidatePids($info);

        // Instance metadata only supplies candidates. Every PID must still prove
        // its current OS command line identity before any destructive signal.
        return $this->terminateResidualProcesses($candidates, false);
    }

    /**
     * Master 已退出时，残留清理必须包含 Session/Memory 共享侧车（否则子进程会脱离 WLS 管理残留）。
     */
    protected function resolveMasterGoneResidualIncludeSharedState(ServerInstanceInfo $info): bool
    {
        unset($info);

        return true;
    }

    /**
     * Master 已退出时的快速清场：先杀实例快照中的已知 PID（含 Session/Memory），再交给前缀/端口复核。
     */
    protected function terminateKnownSubprocessesAfterMasterGone(ServerInstanceInfo $info): void
    {
        $this->printer->note(__('Master 已退出，先快速终止实例内已知子进程（含 Session/Memory）...'));
        $terminated = $this->terminateDirectForceStopCandidatePids($info);
        if ($terminated > 0) {
            SchedulerSystem::usleep(300000);
            $this->printer->note(__('  已发送终止信号 %{1} 个已知子进程', [$terminated]));
        }
    }

    protected function runFastLocalResidualCleanup(string $name, ServerInstanceInfo $info): void
    {
        $this->lastResidualCleanupComplete = false;

        $candidatePids = \array_values(\array_unique(\array_filter(
            \array_map(
                'intval',
                $this->collectFastLocalResidualPids($name, $info)
            ),
            static fn (int $pid): bool => $pid > 0 && $pid !== \getmypid()
        )));

        $killed = $this->terminateResidualProcesses($candidatePids, false);
        if ($killed > 0) {
            SchedulerSystem::usleep(200000);
        }

        $this->cleanupStaleRecoverableProcessPidFilesForPids($candidatePids);
        $remaining = $this->collectRunningResidualPids($candidatePids);
        if ($remaining !== []) {
            $this->printer->warning(__('  Fast-local cleanup still has residual process ids: %{1}', [
                \implode(',', $remaining),
            ]));
            return;
        }

        if ($killed > 0) {
            $this->printer->success(__('  Fast-local cleanup terminated %{1} residual processes.', [$killed]));
        } else {
            $this->printer->note(__('  Fast-local cleanup found no verified residual processes.'));
        }
        $this->lastResidualCleanupComplete = true;
    }

    /**
     * Fast-local restart is the hot path. Keep it bounded to current-instance
     * records and exact pid_index entries; prefix/name_index scans can contain
     * stale PIDs on Windows and make restart both slow and unsafe.
     *
     * @return list<int>
     */
    protected function collectFastLocalResidualPids(string $name, ServerInstanceInfo $info): array
    {
        return \array_values(\array_unique(\array_filter(
            \array_map(
                'intval',
                \array_merge(
                    $this->collectDirectForceStopCandidatePids($info),
                    $this->collectServiceManagedPidsFromInfo($info, false),
                    $this->collectIndexedResidualPids($name, false)
                )
            ),
            static fn (int $pid): bool => $pid > 0
        )));
    }

    /**
     * @return list<int>
     */
    private function collectServiceManagedPidsFromInfo(ServerInstanceInfo $info, bool $includeSharedState = false): array
    {
        $pids = [];
        foreach ($info->services as $service) {
            $role = (string)($service->role ?? '');
            if (!$includeSharedState && $this->isSharedStateServiceRole($role)) {
                continue;
            }

            if (\method_exists($service, 'getManagedPids')) {
                foreach ($service->getManagedPids() as $pid) {
                    $pid = (int)$pid;
                    if ($pid > 0) {
                        $pids[$pid] = true;
                    }
                }
                continue;
            }

            foreach (['pid', 'rootPid', 'launcherPid'] as $field) {
                $pid = (int)($service->{$field} ?? 0);
                if ($pid > 0) {
                    $pids[$pid] = true;
                }
            }
        }

        return \array_map('intval', \array_keys($pids));
    }

    private function isSharedStateServiceRole(string $role): bool
    {
        return \in_array($role, ['session_server', 'memory_server'], true);
    }

    /**
     * @param list<string>|array<int, string> $prefixes
     * @return list<int>
     */
    private function collectPidsFromProcessNamePrefixes(array $prefixes): array
    {
        $prefixes = \array_values(\array_unique(\array_filter(
            \array_map('strval', $prefixes),
            static fn (string $prefix): bool => $prefix !== ''
        )));
        if ($prefixes === []) {
            return [];
        }

        $pids = [];
        $currentPid = \getmypid();
        foreach (Processer::readNameIndex() as $pname => $entries) {
            $taskName = '';
            try {
                $taskName = Processer::getTaskName((string) $pname);
            } catch (\Throwable) {
                $taskName = \str_starts_with((string) $pname, '--name=')
                    ? \substr((string) $pname, 7)
                    : (string) $pname;
            }

            foreach ($prefixes as $prefix) {
                if (!\str_starts_with($taskName, $prefix) && !\str_starts_with((string) $pname, '--name=' . $prefix)) {
                    continue;
                }

                foreach ((array) $entries as $entry) {
                    $pid = (int) ($entry['pid'] ?? 0);
                    if ($pid > 0 && $pid !== $currentPid) {
                        $pids[$pid] = true;
                    }
                }
                break;
            }
        }

        return \array_map('intval', \array_keys($pids));
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
                $this->collectDirectForceBaseResidualPids($info)
            ),
            static fn (int $pid): bool => $pid > 0
        )));
        $this->directForceStopCandidatePidsCache[$info->name] = $candidates;

        return $candidates;
    }

    /**
     * Direct force-stop is the hot path used by --fast-local. Keep its first pass scoped to
     * the Master endpoint record and current indexes.
     *
     * @return list<int>
     */
    protected function collectDirectForceBaseResidualPids(ServerInstanceInfo $info): array
    {
        return \array_values(\array_unique(\array_merge(
            $this->collectResidualPidsByInfo($info, true)
        )));
    }

    protected function resolveManagedStopRootPid(int $pid): int
    {
        if ($pid <= 0) {
            return 0;
        }

        if ($this->isWindowsPlatform()) {
            // Windows parent processes are often transient cmd/powershell launch
            // wrappers. Querying their command line through CIM can hang during
            // restart cleanup, while taskkill /T on the managed WLS PID is
            // already sufficient to terminate that process tree.
            return $pid;
        }

        if (!$this->isStopPidRunning($pid)) {
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
                $this->collectRecoverablePortsFromEndpointRecord($name, true)
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

            $pids[$pid] = true;
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
        $endpoint = MasterProcess::getMasterEndpoint($instanceName);
        $controlToken = (string)($endpoint['control_token'] ?? '');
        $stopMsg = \Weline\Server\IPC\ControlMessage::command(
            \Weline\Server\IPC\ControlMessage::ACTION_STOP,
            '',
            [
                'msg_id' => $traceId,
                'stop_intent' => 'explicit',
                'stop_source' => 'cli:server:stop',
                'stop_trace_id' => $traceId,
                'force_stop' => $force,
            ],
            $controlToken
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
            if ($masterPid > 0 && $this->isMasterPidMissingFromIndex($masterPid)) {
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
                    
                    return $this->finishCompletedIpcStopAfterFinalProgress(
                        $masterPid,
                        $masterAboutToExit,
                        $childrenFullyExited,
                        $observedStopStage
                    );
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
                return $this->finishCompletedIpcStopAfterFinalProgress(
                    $masterPid,
                    $masterAboutToExit,
                    $childrenFullyExited,
                    $observedStopStage
                );
            }
            // 空闲超时仅作提示，不立即判定失败，避免长排水阶段误杀 Master
            $now = \microtime(true);
            if (($now - $lastActivityAt) >= $timeout) {
                if ($masterPid <= 0 && $observedStopStage === 0 && !$childrenFullyExited && !$masterAboutToExit) {
                    $this->ipcMsg("No STOP progress from control port after {$timeout}s; switch to local cleanup.", 'error');
                    @\fclose($conn);
                    return false;
                }
                if ($masterPid > 0 && $this->isMasterPidMissingFromIndex($masterPid)) {
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
        if ($masterPid > 0 && $this->isMasterPidMissingFromIndex($masterPid)) {
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

    protected function finishCompletedIpcStopAfterFinalProgress(
        int $masterPid,
        bool $masterAboutToExit,
        bool $childrenFullyExited,
        int $observedStopStage
    ): bool {
        if (!$this->hasAuthoritativeStopCompletion($masterAboutToExit, $childrenFullyExited, $observedStopStage)) {
            return $this->waitForMasterExit($masterPid);
        }

        $this->waitForMasterPidIndexCleanupAfterFinalProgress($masterPid);
        $this->ipcMsg('Master 停止协议已完成（控制面已关闭）✓', 'success');

        return true;
    }

    protected function hasAuthoritativeStopCompletion(
        bool $masterAboutToExit,
        bool $childrenFullyExited,
        int $observedStopStage
    ): bool {
        return $masterAboutToExit || ($childrenFullyExited && $observedStopStage >= 5);
    }

    protected function waitForMasterPidIndexCleanupAfterFinalProgress(int $masterPid): bool
    {
        if ($masterPid <= 0) {
            return true;
        }

        $deadline = \microtime(true) + 0.4;
        do {
            if ($this->isMasterPidMissingFromIndex($masterPid)) {
                return true;
            }
            SchedulerSystem::usleep(50000);
        } while (\microtime(true) < $deadline);

        return false;
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
        if (\preg_match('/(?:阶段|Stage)\s*(\d+)\s*\/\s*(?:3|5|6)/u', $message, $matches)) {
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

        return (\str_contains($message, '全部') || \str_contains($message, '所有'))
            && \str_contains($message, '子进程')
            && (\str_contains($message, '已退出') || \str_contains($message, '完整退出'));
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

        $stopDrainWait = (float) Env::get('wls.orchestrator.stop_all_drain_wait_sec', 2.0);
        if ($stopDrainWait < 1.0) {
            $stopDrainWait = 1.0;
        }
        if ($stopDrainWait > 30.0) {
            $stopDrainWait = 30.0;
        }

        $terminateTimeout = (float) Env::get('wls.orchestrator.stop_terminate_timeout_sec', 3.0);
        if ($terminateTimeout < 1.0) {
            $terminateTimeout = 1.0;
        }
        if ($terminateTimeout > 10.0) {
            $terminateTimeout = 10.0;
        }

        // 预留阶段切换、IPC 传输与最终校验窗口，避免长排水配置下过早硬超时
        $adaptive = (int) \ceil($stopDrainWait + $terminateTimeout + 4.0);
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
            if ($this->isMasterPidMissingFromIndex($masterPid)) {
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
        if ($this->isMasterPidMissingFromIndex($masterPid)) {
            echo $this->printer->colorize(' 完成 ✓', self::IPC_COLOR_SUCCESS) . "\n";
            return true;
        }
        
        echo $this->printer->colorize(' 超时', self::IPC_COLOR_ERROR) . "\n";
        return false;
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

        return \array_map('intval', \array_keys($pids));
    }

    /**
     * @return list<int>
     */
    protected function collectResidualPidsFromEndpointRecord(string $name, bool $includeSharedState = false): array
    {
        $rawData = $this->getRawStopInstanceData($name);
        if ($rawData === null) {
            return [];
        }

        $pids = $this->collectResidualPidsFromRecord($rawData, $includeSharedState);

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

        return \array_map('intval', \array_keys($pids));
    }

    /**
     * 停止实例时从共享服务 registry 移除消费者；残留清理因此不会误杀仍被其它 WLS 实例使用的 Session/Memory 进程。
     */
    private function invokeReleaseSharedStateConsumersForInstance(
        string $instanceName,
        bool $preserveForRestartCleanup
    ): void
    {
        $previous = $this->preserveSharedStateConsumersForRestartCleanup;
        $this->preserveSharedStateConsumersForRestartCleanup = $previous || $preserveForRestartCleanup;
        try {
            $this->releaseSharedStateConsumersForInstance($instanceName);
        } finally {
            $this->preserveSharedStateConsumersForRestartCleanup = $previous;
        }
    }

    protected function releaseSharedStateConsumersForInstance(string $instanceName): void
    {
        if ($this->preserveSharedStateConsumersForRestartCleanup) {
            return;
        }

        try {
            (new SharedStateServiceManager())->releaseInstanceConsumers($instanceName);
        } catch (\Throwable) {
            // best-effort：registry 损坏或并发时不阻塞停机
        }
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
        if ($pid <= 0) {
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
        if ($pid <= 0) {
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
                continue;
            }

            $pids[$pid] = true;
        }

        return \array_map('intval', \array_keys($pids));
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

        if ($uniquePids === []) {
            return 0;
        }

        // Historical metadata/trusted arrays are never destructive authority:
        // Windows may reuse their PID for svchost or another unrelated process.
        unset($skipCheck, $trustedPids);
        $runningPids = $this->collectRunningResidualPids($uniquePids);
        if ($runningPids === []) {
            return 0;
        }

        // Processer repeats the live managed-identity check immediately before kill.
        $result = Processer::dispatchBatchKillProcessTrees($runningPids, false);
        $this->invalidateStopRuntimeState();

        return (int) ($result['killed'] ?? 0);
    }

    /**
     * @param list<int> $pids
     * @param list<int>|array<int> $trustedPids
     */
    private function areAllResidualPidsTrusted(array $pids, array $trustedPids): bool
    {
        if ($pids === [] || $trustedPids === []) {
            return false;
        }

        $trusted = \array_fill_keys(\array_map('intval', $trustedPids), true);
        foreach ($pids as $pid) {
            if (!isset($trusted[(int) $pid])) {
                return false;
            }
        }

        return true;
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
        if ($uniquePids === []) {
            return [];
        }

        // Kept for caller compatibility only; a PID number can never self-authorize.
        unset($trustedPids);
        $processInfo = $this->batchGetStopProcessInfo($uniquePids);
        $running = [];
        foreach ($uniquePids as $pid) {
            $info = \is_array($processInfo[$pid] ?? null) ? $processInfo[$pid] : [];
            if (!(bool) ($info['exists'] ?? false) || (bool) ($info['is_zombie'] ?? false)) {
                continue;
            }
            if (!$this->isLikelyResidualWlsProcessName((string) ($info['name'] ?? ''))) {
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

    protected function isWindowsPlatform(): bool
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

        $count = $info->workerCount;
        for ($i = 1; $i <= $count; $i++) {
            Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-worker', $name, $i));
            Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-maintenance', $name, $i));
        }
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $name));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-session', $name));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName(MasterProcess::HTTP_REDIRECT_PROCESS_NAME, $name));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-memory', $name));
        
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
            $ports = $this->collectRecoverablePortsFromEndpointRecord($name, false);
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
                    if (!\str_contains($processName, 'weline-wls-')) {
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
        // Do not run command-line/port ownership scans on the normal IPC path.
        // The control connection and protocol response are the authority; if the
        // endpoint is stale or foreign, sendStopViaIpcAndWait() fails quickly and
        // the caller falls back to local cleanup.
        return $info->masterPid > 0 && $info->controlPort > 0;
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
        if (!$this->isResidualPidStillOwnedByWls($pid)) {
            return false;
        }
        if ($this->isWindowsPlatform()) {
            return $this->killWindowsProcessForStop($pid, true, false);
        }

        return Processer::killProcessTreeByPid($pid, false);
    }

    protected function killWindowsProcessForStop(int $pid, bool $tree, bool $skipCheck = true): bool
    {
        if ($pid <= 0) {
            return false;
        }

        unset($skipCheck);
        if (!$this->isResidualPidStillOwnedByWls($pid)) {
            return false;
        }

        $this->executeWindowsTaskkillForStop($pid, $tree);
        unset($this->stopPidRunningCache[$pid]);

        // Dispatching taskkill is sufficient on the stop hot path. If the PID
        // is already gone, proving that via tasklist costs more than the kill.
        return true;
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
        if ($pid <= 0) {
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
        if ($pid > 0) {
            return $pid;
        }

        $pid = $this->getPortProcessId($port);
        return $pid > 0 ? $pid : 0;
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
     * @param list<string> $instanceNames
     */
    protected function stopNamedInstances(
        array $instanceNames,
        bool $force = false,
        bool $fastLocal = false,
        bool $restartCleanup = false
    ): void
    {
        $totalInstances = \count($instanceNames);
        foreach ($instanceNames as $index => $name) {
            $this->printer->note(__('进度 [%{1}/%{2}] 正在停止实例 [%{3}]...', [$index + 1, $totalInstances, $name]));
            if (!$this->acquireStopLock($name)) {
                $this->printer->warning(__('实例 [%{1}] 正在被其他 stop 任务处理，已跳过。', [$name]));
                continue;
            }
            try {
                $this->stopInstance($name, $force, $fastLocal, $restartCleanup);
            } finally {
                $this->releaseStopLock();
            }
            echo "\n";
        }
    }

    protected function stopInstancesByPrefix(string $prefix, bool $force = false, bool $fastLocal = false): void
    {
        $prefix = \trim($prefix);
        if ($prefix === '') {
            $this->printer->warning(__('实例前缀不能为空。'));
            return;
        }

        $manager = $this->getInstanceManager();
        $instances = $this->collectStopPrefixInstanceNames($manager, $prefix);

        if ($instances === []) {
            $this->printer->warning(__('没有找到前缀为 [%{1}] 的实例。', [$prefix]));
            $this->printer->note(__('使用 server:listing 查看当前实例列表。'));
            return;
        }

        $this->printer->setup(__('按前缀停止服务器实例'));
        echo "\n";
        $this->printer->note(__('实例前缀：%{1}', [$prefix]));
        $this->printer->note(__('发现 %{1} 个匹配实例', [\count($instances)]));
        echo "\n";

        $totalInstances = \count($instances);
        foreach ($instances as $index => $name) {
            $this->printer->note(__('进度 [%{1}/%{2}] 正在停止实例 [%{3}]...', [$index + 1, $totalInstances, $name]));
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

        $this->printer->success(__('前缀 [%{1}] 匹配的实例已处理完成。', [$prefix]));
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

    /**
     * @return list<string>
     */
    protected function collectStopPrefixInstanceNames(ServerInstanceManager $manager, string $prefix): array
    {
        $prefixLower = \strtolower(\trim($prefix));
        if ($prefixLower === '') {
            return [];
        }

        $names = [];
        foreach ($manager->listPersistedInstanceNames() as $name) {
            if (!\str_starts_with(\strtolower($name), $prefixLower)) {
                continue;
            }

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

        \sort($names);
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
        $pids = $this->collectResidualPidsFromEndpointRecord($name, true);
        if ($info !== null) {
            $pids = \array_merge($pids, $this->collectResidualPidsByInfo($info, true));
        }
        if ($this->collectRunningResidualPids($pids) !== []) {
            return true;
        }

        $ports = $this->collectRecoverablePortsFromEndpointRecord($name, true);
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
            'server:stop [name ...]',
            __('停止正在运行的 Weline Server 或 PHP 内置服务器实例'),
            [
                '[name ...]' => __('一个或多个实例名称（默认：default；cli/cli-server 表示 PHP 内置服务器）'),
                '-a, --all' => __('停止所有运行中的实例（含 Weline Server 与 CLI 服务器）'),
                '-pre, --prefix <prefix>' => __('停止所有实例名以该前缀开头的 Weline Server 实例'),
                '-f, --force' => __('强制停止：默认本地快速清场（不走 IPC）；若需仍通过 Master 发 STOP，请加 --ipc'),
                '--ipc, --force-ipc' => __('与 -f 联用：显式走 IPC 强制停机（短 ACK/硬超时）；不带 -f 时忽略'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('停止默认实例') => 'php bin/w server:stop',
                __('停止指定实例') => 'php bin/w server:stop api-server',
                __('停止多个指定实例') => 'php bin/w server:stop api-server worker-server',
                __('按前缀停止实例') => 'php bin/w server:stop -pre=ai-test-login',
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
            $this->printer->note('  ' . __('server:stop only stops WLS-managed processes; independent bin/w commands such as setup:upgrade or cron:task:run must be handled separately.'));
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
