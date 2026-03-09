<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\HealthCheckResult;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Contract\ServiceProviderInterface;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Telemetry\InMemoryMetricsAggregator;
use Weline\Server\Service\Telemetry\IpcTelemetryGateway;

/**
 * 服务编排器
 *
 * 核心职责：
 * 1. 加载所有 ServiceProvider（内置 + 模块扫描）
 * 2. 按优先级启动/停止所有服务
 * 3. 统一健康检查循环
 * 4. 统一 IPC 消息处理
 * 5. 所有进程操作委托给 Processer
 */
class ServiceOrchestrator
{
    private ServiceRegistry $registry;
    private ?MasterControlServer $controlServer = null;
    private ?ServiceContext $context = null;

    private bool $running = false;
    private bool $shuttingDown = false;
    private ?int $stopProgressClientId = null;
    private bool $consoleProgressEnabled = false;
    
    /** ANSI 颜色常量 */
    private const ANSI_RESET = "\033[0m";
    private const ANSI_BLUE = "\033[34m";
    private const ANSI_GREEN = "\033[32m";
    private const ANSI_YELLOW = "\033[33m";
    private const ANSI_RED = "\033[31m";
    private float $lastHealthCheck = 0;
    private float $healthCheckInterval = 30.0;
    private bool $fullRestartOnFailure = true;
    private bool $haMode = true;
    private bool $fullRestartRequested = false;
    private string $fullRestartReason = '';
    private float $lastFullRestartAt = 0.0;
    private float $fullRestartCooldown = 10.0;
    private float $registerTimeout = 60.0;
    private float $reconcileInterval = 5.0;
    private float $sweeperInterval = 15.0;
    private bool $periodicOrphanSweepEnabled = false;
    private float $lastReconcileAt = 0.0;
    private float $lastSweepAt = 0.0;
    private int $registerTimeoutCount = 0;
    private int $fullRestartCount = 0;
    private int $lastSweepKilled = 0;
    private int $lastSweepStalePidFiles = 0;
    /** @var array<string,int> role => count */
    private array $desiredState = [];

    /** @var array<string, array{role: string, instanceId: int, maxRestarts: int, restartDelay: float}> 等待复活的实例 */
    private array $resurrectQueue = [];

    /** 启动时等待服务就绪的超时时间（秒） */
    private float $startupTimeout = 30.0;

    /** 关闭时等待服务排水的超时时间（秒）- Windows 上通常较快 */
    private float $drainTimeout = 5.0;

    /** 维护模式是否激活 */
    private bool $maintenanceMode = false;

    /** 滚动重启是否正在进行 */
    private bool $rollingRestartInProgress = false;

    /** 滚动重启等待结果的 CLI 客户端 ID */
    private ?int $rollingRestartClientId = null;

    /** 滚动重启进度（已完成的 Worker 数量） */
    private int $rollingRestartProgress = 0;

    /** 滚动重启总数（需要重启的 Worker 总数） */
    private int $rollingRestartTotal = 0;

    /** 启动完成时间戳（用于启动后冷却期） */
    private float $startAllCompletedAt = 0.0;

    /** 启动后冷却期（秒）- 在此期间忽略 reload_all:code 请求，避免 FileWatcher 误触发 */
    private float $startupReloadCooldown = 10.0;

    /** 是否已输出"服务器准备就绪"通知 */
    private bool $serverReadyNotified = false;

    /** 单实例重启优先（可恢复角色 IPC 断开时先单实例重启） */
    private bool $singleRestartFirst = true;

    /** 升级窗口（秒）：在此时间内断开次数超过阈值则整组重启 */
    private float $escalationWindowSec = 60.0;

    /** 升级阈值：窗口内断开次数超过此值则整组重启 */
    private int $escalationThreshold = 3;

    /** 滚动重启稳定期（秒）：稳定期内新实例断开仅单实例重启 */
    private float $stabilizationSec = 15.0;

    /** 滚动重启稳定期结束时间戳 */
    private float $rollingRestartStabilizingUntil = 0.0;

    /** 核心角色：这些角色 IPC 断开直接整组重启 */
    /** @var array<string, true> */
    private array $criticalRoles = [];

    /** 按角色的最近断开记录（用于 escalation 计数） */
    /** @var array<string, array{count: int, windowStart: float}> */
    private array $escalationDisconnects = [];
    private ?IpcTelemetryGateway $telemetryGateway = null;
    private ?InMemoryMetricsAggregator $metricsAggregator = null;
    /** @var array<string, true> */
    private array $loadedProviderFiles = [];

    public function __construct()
    {
        $this->registry = new ServiceRegistry();
    }

    /**
     * 获取服务注册表
     */
    public function getRegistry(): ServiceRegistry
    {
        return $this->registry;
    }

    /**
     * 获取服务上下文
     */
    public function getContext(): ?ServiceContext
    {
        return $this->context;
    }

    /**
     * 获取 IPC 控制服务器
     */
    public function getControlServer(): ?MasterControlServer
    {
        return $this->controlServer;
    }

    /**
     * 加载所有 ServiceProvider
     *
     * 1. 加载内置 Provider
     * 2. 扫描模块的 etc/wls_services.php 文件
     */
    public function loadProviders(): void
    {
        $this->loadBuiltinProviders();
        $this->scanModuleProviders();

        WlsLogger::info_('[Orchestrator] 已加载 ' . $this->registry->getProviderCount() . ' 个服务提供者');
    }

    /**
     * 启用控制台进度输出（用于 Ctrl+C 前台停止）
     */
    public function setConsoleProgressEnabled(bool $enabled): void
    {
        $this->consoleProgressEnabled = $enabled;
    }

    /**
     * 加载内置 Provider
     */
    private function loadBuiltinProviders(): void
    {
        $builtinConfigPath = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'etc' . DS . 'wls_services.php';
        if (\is_file($builtinConfigPath)) {
            $this->loadModuleProviders($builtinConfigPath);
            return;
        }

        $fallbackProviders = [
            Provider\SessionServerProvider::class,
            Provider\WorkerProvider::class,
            Provider\DispatcherProvider::class,
            Provider\HttpRedirectProvider::class,
            Provider\MaintenanceWorkerProvider::class,
        ];
        foreach ($fallbackProviders as $className) {
            if (!\class_exists($className)) {
                continue;
            }
            $provider = new $className();
            if (!$provider instanceof ServiceProviderInterface) {
                continue;
            }
            $this->registry->registerProvider($provider);
            WlsLogger::debug_("[Orchestrator] 注册内置 Provider(兜底): {$provider->getRole()} ({$provider->getDisplayName()})");
        }
    }

    /**
     * 扫描模块的 etc/wls_services.php 文件
     */
    private function scanModuleProviders(): void
    {
        $modulesDir = BP . 'app' . DS . 'code';
        if (!\is_dir($modulesDir)) {
            return;
        }

        $vendors = @\scandir($modulesDir);
        if ($vendors === false) {
            return;
        }

        foreach ($vendors as $vendor) {
            if ($vendor === '.' || $vendor === '..') {
                continue;
            }
            $vendorPath = $modulesDir . DS . $vendor;
            if (!\is_dir($vendorPath)) {
                continue;
            }

            $modules = @\scandir($vendorPath);
            if ($modules === false) {
                continue;
            }

            foreach ($modules as $module) {
                if ($module === '.' || $module === '..') {
                    continue;
                }
                $servicePath = $vendorPath . DS . $module . DS . 'etc' . DS . 'wls_services.php';
                if (\is_file($servicePath)) {
                    $this->loadModuleProviders($servicePath);
                }
            }
        }
    }

    /**
     * 加载模块的 Provider 定义
     */
    private function loadModuleProviders(string $servicePath): void
    {
        try {
            $realPath = \realpath($servicePath) ?: $servicePath;
            if (isset($this->loadedProviderFiles[$realPath])) {
                return;
            }
            $this->loadedProviderFiles[$realPath] = true;

            $providers = require $servicePath;
            if (!\is_array($providers)) {
                return;
            }

            foreach ($providers as $className) {
                if (!\class_exists($className)) {
                    WlsLogger::warning_("[Orchestrator] Provider 类不存在: {$className} (from {$servicePath})");
                    continue;
                }

                $provider = new $className();
                if (!$provider instanceof ServiceProviderInterface) {
                    WlsLogger::warning_("[Orchestrator] {$className} 未实现 ServiceProviderInterface (from {$servicePath})");
                    continue;
                }

                if ($this->registry->hasProvider($provider->getRole())) {
                    WlsLogger::warning_("[Orchestrator] 角色 {$provider->getRole()} 已注册，跳过: {$className}");
                    continue;
                }

                $this->registry->registerProvider($provider);
                WlsLogger::info_("[Orchestrator] 注册模块 Provider: {$provider->getRole()} ({$provider->getDisplayName()}) from {$servicePath}");
            }
        } catch (\Throwable $e) {
            WlsLogger::error_("[Orchestrator] 加载 {$servicePath} 失败: {$e->getMessage()}");
        }
    }

    /**
     * 启动所有服务（按优先级）
     */
    public function startAll(ServiceContext $context): void
    {
        $this->context = $context;
        $this->running = true;
        $this->shuttingDown = false;
        $this->serverReadyNotified = false;
        $this->haMode = (bool)$context->getConfig('server.orchestrator.ha_mode', true);
        $this->fullRestartOnFailure = (bool)$context->getConfig('server.orchestrator.full_restart_on_failure', true);
        $this->fullRestartCooldown = (float)$context->getConfig('server.orchestrator.restart_cooldown_sec', 10.0);
        $this->registerTimeout = (float)$context->getConfig('server.orchestrator.register_timeout_sec', $this->startupGracePeriod);
        $this->reconcileInterval = (float)$context->getConfig('server.orchestrator.reconcile_interval_sec', 5.0);
        $this->sweeperInterval = (float)$context->getConfig('server.orchestrator.sweeper_interval_sec', 15.0);
        $this->periodicOrphanSweepEnabled = (bool)$context->getConfig('server.orchestrator.periodic_orphan_sweep', false);
        $this->singleRestartFirst = (bool)$context->getConfig('server.orchestrator.single_restart_first', true);
        $this->escalationWindowSec = (float)$context->getConfig('server.orchestrator.escalation_window_sec', 60.0);
        $this->escalationThreshold = (int)$context->getConfig('server.orchestrator.escalation_threshold', 3);
        $this->stabilizationSec = (float)$context->getConfig('server.orchestrator.stabilization_sec', 15.0);
        $providersForCritical = $this->registry->getAllProviders();
        $defaultCriticalRoles = [];
        foreach ($providersForCritical as $provider) {
            if ($provider->isCriticalRole()) {
                $defaultCriticalRoles[] = $provider->getRole();
            }
        }
        $rawCritical = $context->getConfig('server.orchestrator.critical_roles', $defaultCriticalRoles);
        $this->criticalRoles = \array_fill_keys(\is_array($rawCritical) ? $rawCritical : $defaultCriticalRoles, true);
        $this->escalationDisconnects = [];
        if ($this->registerTimeout < $this->startupGracePeriod) {
            // 防止 register_timeout 过短导致误判，至少与启动宽限一致
            $this->registerTimeout = $this->startupGracePeriod;
        }
        $this->desiredState = [];

        // 启动 IPC 控制服务器
        $this->controlServer = new MasterControlServer();
        if (!$this->controlServer->start('127.0.0.1', $context->controlPort)) {
            throw new \RuntimeException("无法启动 IPC 控制服务器，端口: {$context->controlPort}");
        }
        WlsLogger::info_("[Orchestrator] IPC 控制服务器已启动，端口: " . $this->controlServer->getPort());
        // 开发模式下：前台将子进程日志输出到控制台，后台仅写 wls 日志文件
        $this->controlServer->setLogToConsole($context->frontend);

        // 设置 IPC 消息处理器
        $this->controlServer->onMessage([$this, 'handleIpcMessage']);
        $this->controlServer->onDisconnect([$this, 'handleIpcDisconnect']);

        // 按优先级启动服务（同一服务类型内部使用 Fiber 批量并发启动）
        $providers = $this->registry->getAllProviders();
        $startedCount = 0;

        foreach ($providers as $provider) {
            if (!$provider->isEnabled($context)) {
                WlsLogger::debug_("[Orchestrator] 服务 {$provider->getRole()} 未启用，跳过");
                continue;
            }

            $instanceCount = $provider->getInstanceCount($context);
            $role = $provider->getRole();
            $displayName = $provider->getDisplayName();
            $this->desiredState[$role] = $instanceCount;

            // 输出到控制台
            if ($context->frontend) {
                echo "\033[34m  启动 {$displayName}: {$instanceCount} 个实例\033[0m\n";
            }

            WlsLogger::info_("[Orchestrator] 启动服务 {$displayName} (role={$role}, instances={$instanceCount}, priority={$provider->getPriority()})");

            // 启动 Session Server 前释放其端口，避免残留进程导致 Address already in use
            if ($role === 'session_server') {
                $sessionPort = $provider->getPort(1, $context);
                if ($sessionPort > 0) {
                    Processer::killProcessByPort($sessionPort);
                    Processer::forceReleasePort($sessionPort);
                    \usleep(500000);
                }
            }

            // 使用 Fiber 批量并发启动同一服务类型的所有实例
            $instances = $this->startInstancesBatch($provider, $instanceCount, $context);
            
            foreach ($instances as $instance) {
                if ($instance !== null) {
                    $startedCount++;
                    if ($context->frontend) {
                        $portInfo = $instance->port !== null ? " (port={$instance->port})" : '';
                        echo "\033[32m    ✓ {$role}#{$instance->instanceId}{$portInfo}\033[0m\n";
                    }
                }
            }
            
            // 每个服务类型启动完毕后 poll IPC，确保所有 register/ready 消息被处理
            $this->controlServer?->poll(0, 200000);

            // 对声明了启动屏障能力的服务，在继续后续启动前等待 READY
            if ($provider->requiresStartupReadyBarrier()) {
                foreach ($instances as $instance) {
                    if ($instance !== null && $instance->port !== null) {
                        if ($context->frontend) {
                            echo "\033[33m    等待 {$displayName}#{$instance->instanceId} 就绪...\033[0m\n";
                        }
                        $ready = $this->waitForInstanceReady($role, $instance->instanceId, $this->startupTimeout);
                        if (!$ready) {
                            WlsLogger::warning_("[Orchestrator] {$displayName}#{$instance->instanceId} 就绪超时 ({$this->startupTimeout}s)，继续启动后续服务");
                        } elseif ($context->frontend) {
                            echo "\033[32m    ✓ {$displayName}#{$instance->instanceId} 就绪\033[0m\n";
                        }
                    }
                }
            }
        }

        if ($context->frontend) {
            echo "\033[32m  共启动 {$startedCount} 个服务实例\033[0m\n";
        }

        WlsLogger::info_('[Orchestrator] 所有服务启动完成');

        // 记录启动完成时间（用于启动后冷却期）
        $this->startAllCompletedAt = \microtime(true);

        // 持久化服务实例信息到实例文件
        $this->persistServicesInfo($context);
        $this->broadcastRoutingPolicyToWorkers();
    }
    
    /**
     * 持久化服务实例信息到实例文件
     *
     * 委托给 ServerInstanceManager 统一管理，确保所有命令获取到一致的实例信息。
     */
    private function persistServicesInfo(ServiceContext $context): void
    {
        $manager = new ServerInstanceManager();
        if (!$manager->hasInstance($context->instanceName)) {
            return;
        }
        
        // 收集所有服务实例并转换为 ServiceInfo
        $services = [];
        $allInstances = $this->registry->getAllInstances();
        
        foreach ($allInstances as $instance) {
            $provider = $this->registry->getProvider($instance->role);
            if ($provider !== null) {
                $instance->setMeta('control_capabilities', $this->buildControlCapabilities($provider));
            }
            $services[] = Contract\ServiceInfo::fromServiceInstance(
                $instance,
                $provider?->getDisplayName() ?? $manager->getDisplayName($instance->role),
                $provider?->getPriority() ?? 99
            );
        }
        
        // 委托给 Manager 持久化
        $manager->updateServices($context->instanceName, $services);
        $manager->updateMasterPid($context->instanceName, $context->masterPid);
        WlsLogger::debug_('[Orchestrator] 服务实例信息已持久化');
    }

    /**
     * 批量并发启动同一服务类型的多个实例（使用 Fiber）
     * 
     * @param ServiceProviderInterface $provider 服务提供者
     * @param int $instanceCount 实例数量
     * @param ServiceContext $context 服务上下文
     * @return array<ServiceInstance|null> 启动的实例列表
     */
    private function startInstancesBatch(ServiceProviderInterface $provider, int $instanceCount, ServiceContext $context): array
    {
        // 单实例时走普通路径（避免 Fiber 开销）
        if ($instanceCount === 1) {
            return [$this->startInstance($provider, 1, $context)];
        }
        
        $role = $provider->getRole();
        
        // 阶段1：准备所有实例对象和命令
        $preparedInstances = [];
        $commands = [];
        
        for ($i = 1; $i <= $instanceCount; $i++) {
            $port = $provider->getPort($i, $context);
            $launchId = $this->generateLaunchId($role, $i);
            
            $instance = new ServiceInstance(
                role: $role,
                instanceId: $i,
                epoch: $context->epoch,
                launchId: $launchId,
                port: $port,
                state: ServiceInstance::STATE_STARTING,
                startedAt: \microtime(true),
            );
            
            $command = $provider->buildCommand($i, $context);
            $processName = $command->getProcessName();
            if ($processName !== null) {
                $instance->setMeta('process_name', $processName);
            }
            $instance->setMeta('control_capabilities', $this->buildControlCapabilities($provider));
            $instance->setMeta('epoch', $context->epoch);
            $instance->setMeta('launch_id', $launchId);
            
            // 构建完整命令字符串
            $cmd = $command->build();
            if ($instance->epoch > 0) {
                $cmd .= ' --epoch=' . \escapeshellarg((string)$instance->epoch);
            }
            if ($instance->launchId !== '') {
                $cmd .= ' --launch-id=' . \escapeshellarg($instance->launchId);
            }
            if ($processName !== null) {
                $cmd .= ' --name=' . \escapeshellarg($processName);
            }
            
            $preparedInstances[$i] = $instance;
            $commands[$i] = [
                'command' => $cmd,
                'block' => false,
                'foreground' => $context->frontend,
            ];
        }
        
        // 阶段2：使用 Fiber 批量并发启动进程
        WlsLogger::debug_("[Orchestrator] 批量启动 {$role} x {$instanceCount} 实例（Fiber 并发）");
        $pids = Processer::batchCreate($commands);
        
        // 阶段3：收集结果并注册实例
        $results = [];
        foreach ($preparedInstances as $instanceId => $instance) {
            $pid = $pids[$instanceId] ?? 0;
            
            // 非阻塞启动时 Windows/Linux 均可能不返回 PID，统一等待子进程通过 IPC register 上报
            if ($pid <= 0) {
                WlsLogger::warning_("[Orchestrator] 启动 {$role}#{$instanceId} 未返回 PID（非阻塞路径），等待 IPC register 确认");
            }
            
            $instance->pid = $pid > 0 ? $pid : 0;
            $instance->state = ServiceInstance::STATE_STARTING;
            $this->registry->addInstance($instance);
            
            WlsLogger::info_("[Orchestrator] 已启动 {$role}#{$instanceId} (pid={$pid}" . ($instance->port !== null ? ", port={$instance->port}" : '') . ')');
            
            $provider->onStarted($instance);
            $results[] = $instance;
        }
        
        // 批量启动后 poll 一次 IPC
        $this->controlServer?->poll(0, 100000);
        
        return $results;
    }
    
    /**
     * 启动单个服务实例
     */
    private function startInstance(ServiceProviderInterface $provider, int $instanceId, ServiceContext $context): ?ServiceInstance
    {
        $role = $provider->getRole();
        $port = $provider->getPort($instanceId, $context);
        $launchId = $this->generateLaunchId($role, $instanceId);

        // 创建实例对象
        $instance = new ServiceInstance(
            role: $role,
            instanceId: $instanceId,
            epoch: $context->epoch,
            launchId: $launchId,
            port: $port,
            state: ServiceInstance::STATE_STARTING,
            startedAt: \microtime(true),
        );

        // 构建启动命令
        $command = $provider->buildCommand($instanceId, $context);

        // 保存进程名以便后续清理 PID 文件
        $processName = $command->getProcessName();
        if ($processName !== null) {
            $instance->setMeta('process_name', $processName);
        }
        $instance->setMeta('control_capabilities', $this->buildControlCapabilities($provider));
        $instance->setMeta('epoch', $context->epoch);
        $instance->setMeta('launch_id', $launchId);

        // 委托给 Processer 启动进程
        $pid = $this->spawnProcess($command, $instance);
        // 非阻塞启动时 Windows/Linux 均可能不返回 PID，统一等待子进程通过 IPC register 上报
        if ($pid <= 0) {
            WlsLogger::warning_("[Orchestrator] 启动 {$role}#{$instanceId} 未返回 PID（非阻塞路径），等待 IPC register 确认");
        }

        $instance->pid = $pid > 0 ? $pid : 0;
        $instance->state = ServiceInstance::STATE_STARTING;
        $this->registry->addInstance($instance);

        WlsLogger::info_("[Orchestrator] 已启动 {$role}#{$instanceId} (pid={$pid}" . ($port !== null ? ", port={$port}" : '') . ')');

        // 回调 Provider
        $provider->onStarted($instance);

        return $instance;
    }

    /**
     * 委托给 Processer 启动进程
     *
     * 注意：Windows 下使用 block=false，PID 可能不准确（返回 cmd.exe PID）
     * 健康检查通过 IPC 连接状态判断，不依赖 PID
     */
    private function spawnProcess(ServiceCommand $command, ServiceInstance $instance): int
    {
        $cmd = $command->build();
        if ($instance->epoch > 0) {
            $cmd .= ' --epoch=' . \escapeshellarg((string)$instance->epoch);
        }
        if ($instance->launchId !== '') {
            $cmd .= ' --launch-id=' . \escapeshellarg($instance->launchId);
        }
        $processName = $command->getProcessName();

        if ($processName !== null) {
            $cmd .= ' --name=' . \escapeshellarg($processName);
        }

        WlsLogger::debug_("[Orchestrator] 执行命令: {$cmd}");

        // 必须 block=false，否则会阻塞 Master 主循环
        // foreground 跟随 context->frontend，前台模式时子进程输出到控制台
        return Processer::create($cmd, block: false, foreground: $this->context->frontend);
    }

    /**
     * 生成启动实例唯一 launchId
     */
    private function generateLaunchId(string $role, int $instanceId): string
    {
        try {
            $rand = \bin2hex(\random_bytes(6));
        } catch (\Throwable) {
            $rand = (string)\mt_rand(100000, 999999);
        }
        return "{$role}-{$instanceId}-{$rand}";
    }

    /**
     * 停止所有服务（优雅停止：广播-等待-关闭模式）
     *
     * 流程：
     * 1. 广播 DRAIN 给所有实例
     * 2. 等待所有实例排水完成（持续 poll IPC）
     * 3. 广播 SHUTDOWN 给所有实例
     * 4. 等待所有 IPC 连接断开（持续 poll IPC）
     * 5. 强制杀死残留进程
     * 6. 最后关闭 IPC 服务器
     * @param string $reason 停止原因
     * @param int|null $progressClientId 发送进度消息的客户端 ID（用于 CLI stop 命令）
     */
    public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
    {
        $this->shuttingDown = true;
        $this->stopProgressClientId = $progressClientId;
        WlsLogger::info_("[Orchestrator] 开始停止所有服务，原因: {$reason}");

        $totalInstances = \count($this->registry->getAllInstances());
        if ($totalInstances === 0) {
            WlsLogger::info_('[Orchestrator] 无运行中的实例');
            $this->sendStopProgress('无运行中的实例');
            $this->closeIpcServer();
            $this->running = false;
            return;
        }

        // 构建实例清单
        $instanceList = [];
        foreach ($this->registry->getAllInstances() as $inst) {
            $provider = $this->registry->getProvider($inst->role);
            $displayName = $provider?->getDisplayName() ?? $inst->role;
            $instanceList[] = "{$displayName}(PID:{$inst->pid})";
        }
        $this->sendStopProgress("共 {$totalInstances} 个实例待停止: " . \implode(', ', $instanceList));

        // ========== 阶段 1：广播 DRAIN ==========
        WlsLogger::info_('[Orchestrator] 阶段1: 广播 DRAIN');
        $this->sendStopProgress('阶段1/6: 广播 DRAIN - 通知子进程停止接受新请求');
        $this->broadcastDrainToAll();

        // ========== 阶段 2：等待排水完成（短超时，与 Windows 一致，不长时间等待）==========
        WlsLogger::info_('[Orchestrator] 阶段2: 等待排水完成');
        $this->sendStopProgress('阶段2/6: 等待排水完成 - 子进程处理完当前请求');
        $this->waitForAllDrained(2.0, true);

        // ========== 阶段 3：广播 SHUTDOWN ==========
        WlsLogger::info_('[Orchestrator] 阶段3: 广播 SHUTDOWN');
        $this->sendStopProgress('阶段3/6: 广播 SHUTDOWN - 通知子进程退出');
        $this->broadcastShutdownToAll();

        // ========== 阶段 4：等待所有 IPC 连接断开（短超时）==========
        WlsLogger::info_('[Orchestrator] 阶段4: 等待子进程退出');
        $this->sendStopProgress('阶段4/6: 等待子进程退出');
        $this->waitForAllDisconnectedWithProgress(3.0);

        // ========== 阶段 5：校验并强制杀死残留进程 ==========
        WlsLogger::info_('[Orchestrator] 阶段5: 校验子进程退出状态');
        $this->sendStopProgress('阶段5/6: 校验子进程退出状态');
        $this->verifyAndKillRemainingProcesses();

        // ========== 阶段 6：关闭 IPC 服务器 ==========
        WlsLogger::info_('[Orchestrator] 阶段6: 关闭 IPC 服务器');
        $this->sendStopProgress('阶段6/6: 关闭 IPC 服务器');
        
        // 不提前移除 Master PID 索引，避免外部将“索引消失”误判为“进程已退出”。
        // 索引交由 Master 进程最终退出阶段统一清理。
        $this->sendStopProgress('所有子进程已完整退出，Master 即将结束主循环');
        
        // 先设置状态，再关闭 IPC（关闭后无法再发送消息）
        $this->running = false;
        WlsLogger::info_('[Orchestrator] 所有服务已停止');
        
        // 最后关闭 IPC
        $this->closeIpcServer();
    }
    
    /**
     * 清理 Master 进程索引
     * 
     * 在发送 "即将退出" 消息前，主动从 pid_index.json 中删除 Master 的记录。
     * 这样 CLI 可以通过检查索引快速判断 Master 是否已退出，无需调用 tasklist/ps。
     */
    private function cleanupMasterPidIndex(): void
    {
        if ($this->context === null) {
            return;
        }
        
        $instanceName = $this->context->instanceName;
        $masterName = '--name=' . MasterProcess::getMasterProcessName($instanceName);
        
        Processer::removePidFile($masterName);
        WlsLogger::info_('[Orchestrator] Master 进程索引已清理');
    }
    
    /**
     * 发送停止进度消息给 CLI 客户端（或控制台）
     */
    private function sendStopProgress(string $message): void
    {
        // IPC 模式：发送给 CLI 客户端
        if ($this->stopProgressClientId !== null && $this->controlServer !== null) {
            $this->controlServer->sendTo($this->stopProgressClientId, ControlMessage::commandResult(true, [], $message));
        }
        
        // 控制台模式：直接输出到终端（Ctrl+C 前台停止）
        if ($this->consoleProgressEnabled) {
            $tag = self::ANSI_BLUE . '[停止]' . self::ANSI_RESET;
            
            // 格式化输出，根据消息类型使用不同颜色
            if (\str_starts_with($message, '✓') || \str_contains($message, '已退出') || \str_contains($message, '已断开') || \str_contains($message, '排水完成')) {
                // 上报成功：绿色
                $content = self::ANSI_GREEN . $message . self::ANSI_RESET;
                echo "    {$content}\n";
            } elseif (\str_contains($message, 'SHUTDOWN') || \str_contains($message, '通知子进程退出') || \str_contains($message, '强制') || \str_contains($message, 'Master 即将退出')) {
                // 停止相关：红色
                $content = self::ANSI_RED . $message . self::ANSI_RESET;
                echo "  {$tag} {$content}\n";
            } elseif (\str_contains($message, 'DRAIN') || \str_contains($message, '排水') || \str_contains($message, '等待排水') || \str_contains($message, '阶段')) {
                // 排水/阶段：黄色
                $content = self::ANSI_YELLOW . $message . self::ANSI_RESET;
                echo "  {$tag} {$content}\n";
            } elseif (\str_contains($message, '待停止')) {
                // 待停止信息：黄色
                $content = self::ANSI_YELLOW . $message . self::ANSI_RESET;
                echo "  {$tag} {$content}\n";
            } else {
                // 其他信息：蓝色
                $content = self::ANSI_BLUE . $message . self::ANSI_RESET;
                echo "    {$content}\n";
            }
        }
    }

    /**
     * 广播 DRAIN 给所有实例
     */
    private function broadcastDrainToAll(): void
    {
        $connectedClients = [];
        if ($this->controlServer === null) {
            return;
        }
        foreach ($this->registry->getAllInstances() as $instance) {
            if ($instance->ipcClientId === null) {
                continue;
            }
            $provider = $this->registry->getProvider($instance->role);
            if ($provider === null || !$provider->supportsDrain()) {
                continue;
            }
            $instance->state = ServiceInstance::STATE_DRAINING;
            $this->registry->updateInstance($instance);
            $connectedClients[] = "{$instance->role}#{$instance->instanceId}(ipc:{$instance->ipcClientId})";
            $ports = $instance->port !== null ? [$instance->port] : [];
            $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::drain($ports));
        }
        
        if (!empty($connectedClients)) {
            WlsLogger::info_('[IPC] DRAIN -> ' . \implode(', ', $connectedClients));
        }
    }

    /**
     * 广播 SHUTDOWN 给所有实例
     */
    private function broadcastShutdownToAll(): void
    {
        $connectedClients = [];
        if ($this->controlServer === null) {
            return;
        }
        foreach ($this->registry->getAllInstances() as $instance) {
            if ($instance->ipcClientId === null) {
                continue;
            }
            $provider = $this->registry->getProvider($instance->role);
            if ($provider === null || !$provider->supportsShutdown()) {
                continue;
            }
            $connectedClients[] = "{$instance->role}#{$instance->instanceId}(pid:{$instance->pid},ipc:{$instance->ipcClientId})";
            $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::shutdown());
        }
        
        if (!empty($connectedClients)) {
            WlsLogger::info_('[IPC] SHUTDOWN -> ' . \implode(', ', $connectedClients));
        } else {
            WlsLogger::info_('[IPC] SHUTDOWN -> (无已连接的 IPC 客户端)');
        }
    }

    /**
     * 等待所有实例排水完成
     * 
     * @param float $timeout 超时时间（秒）
     * @param bool $reportProgress 是否报告进度
     */
    private function waitForAllDrained(float $timeout, bool $reportProgress = false): void
    {
        $start = \microtime(true);
        $drainedInstances = [];
        
        while (\microtime(true) - $start < $timeout) {
            $this->controlServer?->poll(0, 100000);

            $drainingCount = 0;
            foreach ($this->registry->getAllInstances() as $instance) {
                $key = "{$instance->role}#{$instance->instanceId}";
                
                if ($instance->state === ServiceInstance::STATE_DRAINING
                    && $this->isProcessRunning($instance->pid)
                ) {
                    $drainingCount++;
                } elseif ($instance->state !== ServiceInstance::STATE_DRAINING 
                          && !isset($drainedInstances[$key])
                          && $reportProgress) {
                    // 实例已完成排水
                    $drainedInstances[$key] = true;
                    $provider = $this->registry->getProvider($instance->role);
                    $displayName = $provider?->getDisplayName() ?? $instance->role;
                    $msg = "  ✓ {$displayName}(PID:{$instance->pid}) 排水完成";
                    $this->sendStopProgress($msg);
                }
            }

            if ($drainingCount === 0) {
                WlsLogger::info_('[Orchestrator] 所有实例排水完成');
                if ($reportProgress) {
                    $this->sendStopProgress('所有子进程排水完成');
                }
                return;
            }
        }
        WlsLogger::warning_("[Orchestrator] 等待排水超时 ({$timeout}s)");
        if ($reportProgress) {
            $this->sendStopProgress("等待排水超时 ({$timeout}s)，继续执行停止流程");
        }
    }

    /**
     * 等待所有 IPC 连接断开
     */
    private function waitForAllDisconnected(float $timeout): void
    {
        $this->waitForAllDisconnectedWithProgress($timeout);
    }
    
    /**
     * 等待所有 IPC 连接断开（带进度报告）
     */
    private function waitForAllDisconnectedWithProgress(float $timeout): void
    {
        $start = \microtime(true);
        $lastClientCount = -1;
        $exitedInstances = [];

        while (\microtime(true) - $start < $timeout) {
            $this->controlServer?->poll(0, 100000);

            $clientCount = $this->controlServer?->getClientCount() ?? 0;

            // 检查每个实例的退出状态
            foreach ($this->registry->getAllInstances() as $instance) {
                $key = "{$instance->role}#{$instance->instanceId}";
                if (isset($exitedInstances[$key])) {
                    continue;
                }
                
                // 检查进程是否已退出
                if ($instance->pid > 0 && !$this->isProcessRunning($instance->pid)) {
                    $exitedInstances[$key] = true;
                    $provider = $this->registry->getProvider($instance->role);
                    $displayName = $provider?->getDisplayName() ?? $instance->role;
                    $msg = "  ✓ {$displayName}(PID:{$instance->pid}) 已退出";
                    WlsLogger::info_("[Orchestrator] {$msg}");
                    $this->sendStopProgress($msg);
                    // 进程已退出，主动关闭 IPC 连接，避免超时等待
                    if ($instance->ipcClientId !== null && $this->controlServer !== null) {
                        $this->controlServer->closeClient($instance->ipcClientId);
                    }
                }
            }

            // 只在客户端数变化时记录日志
            if ($clientCount !== $lastClientCount) {
                if ($clientCount > 0) {
                    WlsLogger::info_("[IPC] 等待断开: 剩余 {$clientCount} 个连接...");
                }
                $lastClientCount = $clientCount;
            }

            if ($clientCount === 0) {
                $elapsed = \round((\microtime(true) - $start) * 1000);
                WlsLogger::info_("[IPC] 所有子进程已断开连接 ({$elapsed}ms)");
                return;
            }

            \usleep(50000); // 50ms 轮询间隔
        }
        $remainingCount = $this->controlServer?->getClientCount() ?? 0;
        WlsLogger::warning_("[IPC] 等待断开超时 ({$timeout}s)，剩余 {$remainingCount} 个连接");
    }

    /**
     * 强制杀死残留进程（兼容旧调用）
     */
    private function forceKillRemainingProcesses(): void
    {
        $this->verifyAndKillRemainingProcesses();
    }
    
    /**
     * 校验并强制杀死残留进程（带进度报告）
     *
     * 使用 Processer::batchGracefulKill() 批量停止，比逐个停止更高效
     */
    private function verifyAndKillRemainingProcesses(): void
    {
        $allInstances = $this->registry->getAllInstances();

        // 收集仍在运行的进程 PID
        $runningPids = [];
        $exitedPids = [];
        $pidToInstance = [];

        foreach ($allInstances as $instance) {
            $provider = $this->registry->getProvider($instance->role);
            $displayName = $provider?->getDisplayName() ?? $instance->role;
            
            if ($instance->pid > 0) {
                if ($this->isProcessRunning($instance->pid)) {
                    $runningPids[] = $instance->pid;
                    $pidToInstance[$instance->pid] = $instance;
                } else {
                    $exitedPids[] = $instance->pid;
                    $msg = "  ✓ {$displayName}(PID:{$instance->pid}) 已退出";
                    $this->sendStopProgress($msg);
                }
            }
        }
        
        // 报告校验结果
        $totalCount = \count($allInstances);
        $exitedCount = \count($exitedPids);
        $runningCount = \count($runningPids);
        
        if ($runningCount === 0) {
            $this->sendStopProgress("校验完成: 所有 {$totalCount} 个子进程已正常退出");
            WlsLogger::info_("[Orchestrator] 校验完成: 所有 {$totalCount} 个子进程已正常退出");
        } else {
            $this->sendStopProgress("校验结果: {$exitedCount}/{$totalCount} 已退出，{$runningCount} 个需强制终止");
            WlsLogger::warning_("[Orchestrator] 校验结果: {$exitedCount}/{$totalCount} 已退出，{$runningCount} 个需强制终止");
        }

        // 批量优雅停止（使用 Processer 的增强方法）
        if (!empty($runningPids)) {
            $pidList = [];
            foreach ($runningPids as $pid) {
                $inst = $pidToInstance[$pid] ?? null;
                if ($inst) {
                    $provider = $this->registry->getProvider($inst->role);
                    $displayName = $provider?->getDisplayName() ?? $inst->role;
                    $pidList[] = "{$displayName}(PID:{$pid})";
                } else {
                    $pidList[] = "PID:{$pid}";
                }
            }
            $this->sendStopProgress("强制终止残留进程: " . \implode(', ', $pidList));
            WlsLogger::warning_("[Orchestrator] 批量终止 " . \count($runningPids) . " 个残留进程：" . \implode(',', $runningPids));
            
            $result = Processer::batchGracefulKill($runningPids, 2.0, true); // 2秒超时

            if ($result['killed'] > 0) {
                $this->sendStopProgress("  ✓ 成功终止 {$result['killed']} 个残留进程");
                WlsLogger::info_("[Orchestrator] 批量终止成功 {$result['killed']} 个进程");
            }
            if (!empty($result['remaining'])) {
                $remainingCount = \count($result['remaining']);
                $this->sendStopProgress("  ⚠ 仍有 {$remainingCount} 个进程无法终止: " . \implode(',', $result['remaining']));
                WlsLogger::warning_("[Orchestrator] 仍有 {$remainingCount} 个进程无法终止：" . \implode(',', $result['remaining']));
            }
        }

        // 清理所有实例状态
        foreach ($allInstances as $instance) {
            $this->cleanupInstancePidFile($instance);
            $instance->state = ServiceInstance::STATE_STOPPED;
            $this->registry->updateInstance($instance);

            $provider = $this->registry->getProvider($instance->role);
            $provider?->onStopped($instance);
        }
    }

    /**
     * 关闭 IPC 服务器
     */
    private function closeIpcServer(): void
    {
        if ($this->controlServer !== null) {
            $this->controlServer->close();
            $this->controlServer = null;
        }
    }

    /**
     * 三阶段停机协议：DRAIN -> SHUTDOWN -> KILL（单实例完整停止）
     *
     * 此方法用于单实例停止场景（如滚动重启），会等待实例退出。
     * 批量停止请使用 stopAll()。
     */
    private function stopInstanceWithProtocol(ServiceInstance $instance): void
    {
        $provider = $this->registry->getProvider($instance->role);

        // 阶段 1：DRAIN
        if ($provider !== null && $provider->getReloadStrategy() === 'graceful' && $instance->ipcClientId !== null) {
            $instance->state = ServiceInstance::STATE_DRAINING;
            $this->registry->updateInstance($instance);
            $this->sendDrainToInstance($instance);
            $this->waitForDrain([$instance], $this->drainTimeout);
        }

        // 阶段 2：SHUTDOWN
        $this->stopInstance($instance);

        // 阶段 3：等待并强制杀死
        $this->waitForInstanceExit($instance, 5.0);

        // 清理
        $this->cleanupInstancePidFile($instance);
        $instance->state = ServiceInstance::STATE_STOPPED;
        $this->registry->updateInstance($instance);
        $provider?->onStopped($instance);

        WlsLogger::info_("[Orchestrator] 已停止 {$instance->role}#{$instance->instanceId}");
    }

    /**
     * 等待单个实例退出
     */
    private function waitForInstanceExit(ServiceInstance $instance, float $timeout): void
    {
        $waitStart = \microtime(true);
        while (\microtime(true) - $waitStart < $timeout) {
            $this->controlServer?->poll(0, 100000);

            if (!$this->isProcessRunning($instance->pid)) {
                return;
            }
        }

        if ($this->isProcessRunning($instance->pid)) {
            WlsLogger::warning_("[Orchestrator] 进程 {$instance->role}#{$instance->instanceId} (pid={$instance->pid}) 未在 {$timeout}s 内退出，强制杀死");
            $this->killInstanceProcess($instance);
        }
    }

    /**
     * 停止单个服务实例（仅发送 shutdown 消息）
     *
     * 注意：此方法仅发送 IPC 消息，不等待进程退出。
     * 等待和清理逻辑由 stopAll() 统一处理。
     * 如需单独停止实例并等待，使用 stopInstanceWithProtocol()。
     */
    private function stopInstance(ServiceInstance $instance): void
    {
        if ($instance->ipcClientId !== null && $this->controlServer !== null) {
            $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::shutdown());
            WlsLogger::info_("[Orchestrator] 已发送 shutdown 给 {$instance->role}#{$instance->instanceId}");
        } else {
            WlsLogger::warning_("[Orchestrator] 无法发送 shutdown 给 {$instance->role}#{$instance->instanceId}（无 IPC 连接）");
        }
    }

    /**
     * 杀死实例进程（委托给进程管理类）
     *
     * 遵循 SOLID 原则：进程管理职责由 Processer 类承担，
     * ServiceOrchestrator 只负责调度逻辑。
     */
    private function killInstanceProcess(ServiceInstance $instance): void
    {
        $processName = $instance->getMeta('process_name');

        // 优先使用进程名杀死（Processer 会处理跨平台兼容性和 PID 文件清理）
        if ($processName !== null) {
            Processer::destroy('--name=' . $processName);
            return;
        }

        // 回退：使用 PID 杀死
        $pid = $instance->pid;
        if ($pid > 0) {
            Processer::killByPid($pid, true);
        }
    }

    /**
     * 清理实例的 PID 文件
     */
    private function cleanupInstancePidFile(ServiceInstance $instance): void
    {
        $processName = $instance->getMeta('process_name');
        if ($processName !== null) {
            Processer::removePidFile('--name=' . $processName);
        }
    }

    /**
     * 发送 drain 命令给实例
     */
    private function sendDrainToInstance(ServiceInstance $instance): void
    {
        if ($instance->ipcClientId === null || $this->controlServer === null) {
            return;
        }

        $instance->state = ServiceInstance::STATE_DRAINING;
        $this->registry->updateInstance($instance);

        $ports = $instance->port !== null ? [$instance->port] : [];
        $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::drain($ports));
    }

    /**
     * 等待实例排水完成
     *
     * @param ServiceInstance[] $instances
     */
    private function waitForDrain(array $instances, float $timeout): void
    {
        $start = \microtime(true);
        while (\microtime(true) - $start < $timeout) {
            $allDrained = true;
            foreach ($instances as $instance) {
                if ($instance->state === ServiceInstance::STATE_DRAINING && $this->isProcessRunning($instance->pid)) {
                    $allDrained = false;
                    break;
                }
            }

            if ($allDrained) {
                return;
            }

            // Poll IPC 消息
            $this->controlServer?->poll(0, 100000);
        }

        WlsLogger::warning_("[Orchestrator] 等待排水超时 ({$timeout}s)");
    }

    /**
     * 重载指定服务
     */
    public function reloadService(string $role, string $type = 'code'): void
    {
        $provider = $this->registry->getProvider($role);
        if ($provider === null) {
            WlsLogger::warning_("[Orchestrator] 未找到服务: {$role}");
            return;
        }

        if (!$provider->supportsReload()) {
            WlsLogger::info_("[Orchestrator] 服务 {$role} 不支持重载");
            return;
        }
        $strategy = $provider->getReloadStrategy();

        WlsLogger::info_("[Orchestrator] 开始重载服务 {$role} (strategy={$strategy}, type={$type})");

        $instances = $this->registry->getInstancesByRole($role);
        if (empty($instances)) {
            return;
        }

        if ($strategy === 'immediate') {
            // 立即重启所有实例
            foreach ($instances as $instance) {
                $this->restartInstance($instance, $type);
            }
        } elseif ($strategy === 'graceful') {
            // 滚动重启
            $this->gracefulReloadInstances($provider, $instances, $type);
        }
    }

    /**
     * 重载所有服务
     */
    public function reloadAll(string $type = 'code'): void
    {
        // 启动后冷却期：忽略 FileWatcher 在启动初期的误触发
        if ($type === 'code' && $this->startAllCompletedAt > 0) {
            $elapsed = \microtime(true) - $this->startAllCompletedAt;
            if ($elapsed < $this->startupReloadCooldown) {
                WlsLogger::info_("[Orchestrator] 忽略启动后冷却期内的 reload_all:code 请求（已启动 " . \round($elapsed, 1) . "s，冷却期 {$this->startupReloadCooldown}s）");
                return;
            }
        }

        $configuredRoles = $this->context?->getConfig('server.orchestrator.reload_roles', ['worker']);
        $reloadRoles = \is_array($configuredRoles) ? $configuredRoles : ['worker'];
        if (empty($reloadRoles)) {
            $reloadRoles = ['worker'];
        }
        WlsLogger::info_("[Orchestrator] 收到重载请求 (type={$type})，目标角色: " . \implode(',', $reloadRoles));
        foreach ($reloadRoles as $role) {
            $this->reloadService((string)$role, $type);
        }
        $this->broadcastRoutingPolicyToWorkers();
    }

    /**
     * 优雅重载实例列表
     *
     * @param ServiceInstance[] $instances
     */
    private function gracefulReloadInstances(ServiceProviderInterface $provider, array $instances, string $type): void
    {
        // 滚动重启期间暂停整组重启（IPC 断开和健康检查都可能误触发）
        $savedFullRestartOnFailure = $this->fullRestartOnFailure;
        $this->fullRestartOnFailure = false;
        $startTime = \microtime(true);

        try {
            foreach ($instances as $instance) {
                $role = $instance->role;
                $id = $instance->instanceId;
                WlsLogger::info_("[Orchestrator] 滚动重启 {$role}#{$id}: DRAIN");

                $this->sendDrainToInstance($instance);
                $this->waitForDrain([$instance], $this->drainTimeout);

                WlsLogger::info_("[Orchestrator] 滚动重启 {$role}#{$id}: STOP 旧实例");
                $this->stopInstanceWithProtocol($instance);
                $this->registry->removeInstance($role, $id);

                WlsLogger::info_("[Orchestrator] 滚动重启 {$role}#{$id}: 启动新实例");
                $started = $this->startInstance($provider, $id, $this->context);
                if ($started === null) {
                    WlsLogger::error_("[Orchestrator] 滚动重载 {$role}#{$id} 启动失败");
                    if ($this->rollingRestartClientId !== null) {
                        $this->controlServer?->sendTo(
                            $this->rollingRestartClientId,
                            ControlMessage::reloadFailed("{$role}#{$id} start failed")
                        );
                    }
                    return;
                }
                $ready = $this->waitForInstanceReady($role, $id, $this->startupTimeout);
                if (!$ready) {
                    WlsLogger::error_("[Orchestrator] 滚动重载 {$role}#{$id} 未在 {$this->startupTimeout}s 内进入 READY");
                    if ($this->rollingRestartClientId !== null) {
                        $this->controlServer?->sendTo(
                            $this->rollingRestartClientId,
                            ControlMessage::reloadFailed("{$role}#{$id} not ready within {$this->startupTimeout}s")
                        );
                    }
                    return;
                }

                WlsLogger::info_("[Orchestrator] 滚动重启 {$role}#{$id}: 完成");
            }
            
            // 发送完成消息给 CLI（如果有）
            if ($this->rollingRestartClientId !== null) {
                $elapsedMs = (\microtime(true) - $startTime) * 1000;
                $this->controlServer?->sendTo($this->rollingRestartClientId, ControlMessage::reloadCompleted($elapsedMs, \count($instances)));
            }

            // 进入稳定期：此期间新实例断开仅单实例重启
            $this->rollingRestartStabilizingUntil = \microtime(true) + $this->stabilizationSec;
        } finally {
            $this->fullRestartOnFailure = $savedFullRestartOnFailure;
            // 清除滚动重启期间累积的误触发标志
            $this->fullRestartRequested = false;
            $this->fullRestartReason = '';
        }
    }

    /**
     * 重启单个实例
     */
    private function restartInstance(ServiceInstance $instance, string $type): void
    {
        $provider = $this->registry->getProvider($instance->role);
        if ($provider === null) {
            return;
        }

        // 停止旧实例
        $this->stopInstanceWithProtocol($instance);
        $this->registry->removeInstance($instance->role, $instance->instanceId);

        // 启动新实例
        $this->startInstance($provider, $instance->instanceId, $this->context);
    }

    /**
     * 等待实例就绪
     */
    private function waitForInstanceReady(string $role, int $instanceId, float $timeout): bool
    {
        $start = \microtime(true);
        while (\microtime(true) - $start < $timeout) {
            $instance = $this->registry->getInstance($role, $instanceId);
            if ($instance !== null && $instance->state === ServiceInstance::STATE_READY) {
                return true;
            }

            // Poll IPC 消息
            $this->controlServer?->poll(0, 100000);
        }

        return false;
    }

    /**
     * 主循环（健康检查 + IPC 轮询）
     */
    public function runLoop(): void
    {
        WlsLogger::info_('[Orchestrator] 进入主循环');

        while ($this->running && !$this->shuttingDown) {
            // Poll IPC 消息（可能触发 stopAll 导致 shuttingDown=true）
            $this->controlServer?->poll(0, 100000);
            
            // 关键：poll 可能在回调中执行 stopAll，需要立即检查退出条件
            if (!$this->running || $this->shuttingDown) {
                break;
            }

            // 故障统一策略：整组重启
            if ($this->fullRestartRequested) {
                $this->performFullRestart();
                continue;
            }

            // 定期健康检查
            $now = \microtime(true);
            if ($now - $this->lastHealthCheck >= $this->healthCheckInterval) {
                $this->performHealthChecks();
                $this->lastHealthCheck = $now;
            }

            if ($this->haMode && $now - $this->lastReconcileAt >= $this->reconcileInterval) {
                $this->reconcileDesiredState();
                $this->lastReconcileAt = $now;
            }

            if ($this->haMode && $this->periodicOrphanSweepEnabled && $now - $this->lastSweepAt >= $this->sweeperInterval) {
                $this->cleanupOrphanChildProcesses(aggressiveKill: false);
                $this->lastSweepAt = $now;
            }

            // 处理复活队列
            $this->processResurrectQueue();

            // 稳定期过期
            if ($this->rollingRestartStabilizingUntil > 0 && $now >= $this->rollingRestartStabilizingUntil) {
                $this->rollingRestartStabilizingUntil = 0;
            }

            // 短暂休眠避免 CPU 空转
            \usleep(50000);
        }

        WlsLogger::info_('[Orchestrator] 退出主循环');
    }

    /**
     * 执行一轮 IO 轮询（供外部调用）
     */
    public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
    {
        return $this->controlServer?->poll($timeoutSec, $timeoutUsec) ?? 0;
    }

    /**
     * 启动宽限期（秒）- 在此期间不对新启动的进程进行健康检查
     */
    private float $startupGracePeriod = 60.0;

    /**
     * 执行健康检查
     *
     * 健康检查策略：
     * 1. 启动中的实例：只检查是否超过启动宽限期
     * 2. 运行中的实例：优先检查 IPC 连接状态，其次检查 PID
     * 3. Windows 下 PID 可能不准确，因此以 IPC 连接为主要依据
     */
    private function performHealthChecks(): void
    {
        $now = \microtime(true);
        $providers = $this->registry->getAllProviders();

        foreach ($providers as $provider) {
            $instances = $this->registry->getInstancesByRole($provider->getRole());
            foreach ($instances as $instance) {
                if ($instance->state === ServiceInstance::STATE_FAILED ||
                    $instance->state === ServiceInstance::STATE_STOPPED) {
                    continue;
                }

                $uptime = $now - $instance->startedAt;

                // 启动中的实例：在宽限期内不检查
                if ($instance->state === ServiceInstance::STATE_STARTING) {
                    // 启动确认超时（register/ready 未到）
                    if ($instance->ipcClientId === null && $uptime >= $this->registerTimeout) {
                        $this->registerTimeoutCount++;
                        WlsLogger::warning_("[Orchestrator] register 超时: {$instance->role}#{$instance->instanceId} (uptime={$uptime}s, timeout={$this->registerTimeout}s)");
                        $this->healthCheckRestartOrEscalate($instance, "register_timeout:{$instance->role}#{$instance->instanceId}");
                        continue;
                    }

                    if ($uptime < $this->startupGracePeriod) {
                        // 但如果已经有 IPC 连接，说明启动成功
                        if ($instance->ipcClientId !== null) {
                            $instance->state = ServiceInstance::STATE_READY;
                            $this->registry->updateInstance($instance);
                            WlsLogger::info_("[Orchestrator] 服务就绪: {$instance->role}#{$instance->instanceId} (via IPC, uptime={$uptime}s)");
                        }
                        continue;
                    }
                    // 宽限期已过但还没有 IPC 连接
                    if ($instance->ipcClientId === null) {
                        WlsLogger::warning_("[Orchestrator] 启动超时: {$instance->role}#{$instance->instanceId} (uptime={$uptime}s, no IPC)");
                        $this->healthCheckRestartOrEscalate($instance, "startup_timeout:{$instance->role}#{$instance->instanceId}");
                        continue;
                    }
                }

                // 运行中的实例：检查 IPC 连接状态
                // 优先以 IPC 连接判断存活（Windows 下 PID 可能不准确）
                if ($instance->ipcClientId !== null) {
                    // 有 IPC 连接，视为健康
                    $result = $provider->healthCheck($instance);
                    $instance->lastHealthCheck = $now;
                    if (!$result->isHealthy()) {
                        WlsLogger::warning_("[Orchestrator] 健康检查失败: {$instance->role}#{$instance->instanceId} - {$result->message}");
                    }
                    $this->registry->updateInstance($instance);
                    continue;
                }

                // 没有 IPC 连接，检查 PID（作为备用方案）
                if ($instance->pid > 0 && $this->isProcessRunning($instance->pid)) {
                    // PID 存活但没有 IPC 连接，可能正在启动或重连
                    if ($uptime < $this->startupGracePeriod * 2) {
                        continue;
                    }
                    // 超过宽限期仍没有 IPC 连接，视为僵尸进程，需要杀死并复活
                    WlsLogger::warning_("[Orchestrator] 进程存活但无 IPC 超时: {$instance->role}#{$instance->instanceId} (pid={$instance->pid}, uptime={$uptime}s)");
                    $this->healthCheckRestartOrEscalate($instance, "no_ipc_timeout:{$instance->role}#{$instance->instanceId}");
                    continue;
                }

                // 既没有 IPC 连接，PID 也不存活
                WlsLogger::warning_("[Orchestrator] 健康检查失败: {$instance->role}#{$instance->instanceId} - No IPC and PID not running");
                $this->healthCheckRestartOrEscalate($instance, "dead_without_ipc:{$instance->role}#{$instance->instanceId}");
            }
        }
    }

    /**
     * 健康检查触发的重启：可恢复角色优先单实例重启，核心角色或超阈值则整组重启
     */
    private function healthCheckRestartOrEscalate(ServiceInstance $instance, string $reason): void
    {
        $maxRestarts = 10;
        if (isset($this->criticalRoles[$instance->role]) || !$this->singleRestartFirst) {
            $this->requestFullRestart($reason);
            return;
        }

        $provider = $this->registry->getProvider($instance->role);
        $resurrectionPriority = $provider?->getResurrectionPriority() ?? 0;
        if ($resurrectionPriority <= 0 || $instance->restarts >= $maxRestarts) {
            $this->requestFullRestart($reason);
            return;
        }

        $role = $instance->role;
        $now = \microtime(true);
        if (!isset($this->escalationDisconnects[$role]) || $now - $this->escalationDisconnects[$role]['windowStart'] > $this->escalationWindowSec) {
            $this->escalationDisconnects[$role] = ['count' => 0, 'windowStart' => $now];
        }
        $this->escalationDisconnects[$role]['count']++;

        if ($this->escalationDisconnects[$role]['count'] > $this->escalationThreshold) {
            $this->requestFullRestart("{$reason} (escalation_count={$this->escalationDisconnects[$role]['count']})");
            return;
        }

        if ($instance->pid > 0 && $this->isProcessRunning($instance->pid)) {
            $this->killInstanceProcess($instance);
            \usleep(200000);
        }
        $this->scheduleResurrection($instance);
    }

    /**
     * 请求整组重启（防止孤儿进程累积）
     */
    private function requestFullRestart(string $reason): void
    {
        if (!$this->haMode || !$this->fullRestartOnFailure || $this->shuttingDown) {
            return;
        }

        $now = \microtime(true);
        if (($now - $this->lastFullRestartAt) < $this->fullRestartCooldown) {
            WlsLogger::warning_("[Orchestrator] 忽略频繁整组重启请求（冷却中）: {$reason}");
            return;
        }

        if ($this->fullRestartRequested) {
            return;
        }

        $this->fullRestartRequested = true;
        $this->fullRestartReason = $reason;
        WlsLogger::warning_("[Orchestrator] 已标记整组重启，原因: {$reason}");
    }

    /**
     * 执行整组重启：先停全量，再重新拉起
     */
    private function performFullRestart(): void
    {
        if ($this->context === null) {
            WlsLogger::error_('[Orchestrator] 缺少 context，无法执行整组重启');
            $this->fullRestartRequested = false;
            return;
        }
        $this->fullRestartCount++;

        $reason = $this->fullRestartReason !== '' ? $this->fullRestartReason : 'unknown';
        $this->fullRestartRequested = false;
        $this->fullRestartReason = '';
        $this->lastFullRestartAt = \microtime(true);

        WlsLogger::warning_("[Orchestrator] 开始执行整组重启，原因: {$reason}");

        // 1) 仅停止子进程（不关闭 IPC 服务器、不设 shuttingDown/running=false）
        $this->stopChildProcesses("full_restart:{$reason}");

        // 2) 扫尾清理：按前缀清理逃逸子进程，防止窗口与孤儿进程累积
        $this->cleanupOrphanChildProcesses(aggressiveKill: true);

        // 3) 清空注册表实例索引，避免残留实例污染新一轮生命周期
        $this->registry->clearInstances();
        $this->resurrectQueue = [];
        $this->serverReadyNotified = false;

        // 4) bump epoch，旧代际进程即使迟到注册也会被拒绝
        $nextEpoch = $this->context->epoch + 1;
        $this->context = $this->context->withEpoch($nextEpoch);
        WlsLogger::warning_("[Orchestrator] 代际切换到 epoch={$nextEpoch}");

        // 5) 重新拉起全量服务（仅子进程，不重新初始化 IPC 服务器）
        $this->restartChildProcesses($this->context);

        // 更新重启完成时间（确保冷却期从完成时开始计算）
        $this->lastFullRestartAt = \microtime(true);

        WlsLogger::warning_('[Orchestrator] 整组重启完成');
    }

    /**
     * 仅停止子进程，保持 Master 和 IPC 服务器运行。
     * 用于 performFullRestart 场景（停子进程 → 拉新子进程）。
     * 与 stopAll 不同：不设 shuttingDown、不设 running=false、不关闭 IPC。
     */
    private function stopChildProcesses(string $reason): void
    {
        WlsLogger::info_("[Orchestrator] 开始停止子进程（保持 IPC），原因: {$reason}");

        $allInstances = $this->registry->getAllInstances();
        if (\count($allInstances) === 0) {
            WlsLogger::info_('[Orchestrator] 无运行中的子进程');
            return;
        }

        // 阶段 1：广播 DRAIN
        WlsLogger::info_('[Orchestrator] 子进程停止阶段1: 广播 DRAIN');
        $this->broadcastDrainToAll();

        // 阶段 2：等待排水完成
        WlsLogger::info_('[Orchestrator] 子进程停止阶段2: 等待排水完成');
        $this->waitForAllDrained($this->drainTimeout, true);

        // 阶段 3：广播 SHUTDOWN
        WlsLogger::info_('[Orchestrator] 子进程停止阶段3: 广播 SHUTDOWN');
        $this->broadcastShutdownToAll();

        // 阶段 4：等待子进程退出
        WlsLogger::info_('[Orchestrator] 子进程停止阶段4: 等待子进程退出');
        $this->waitForAllDisconnectedWithProgress(5.0);

        // 阶段 5：强制杀死残留子进程
        WlsLogger::info_('[Orchestrator] 子进程停止阶段5: 校验并杀死残留');
        $this->verifyAndKillRemainingProcesses();

        WlsLogger::info_('[Orchestrator] 所有子进程已停止');
    }
    
    /**
     * 仅重启子进程（不重新初始化 IPC 控制服务器）
     * 
     * 用于 performFullRestart 场景，此时 Master 和 IPC 服务器仍在运行，
     * 只需要重新启动子进程（Worker、Dispatcher 等）。
     */
    private function restartChildProcesses(ServiceContext $context): void
    {
        // 按优先级启动服务
        $providers = $this->registry->getAllProviders();
        $startedCount = 0;

        foreach ($providers as $provider) {
            if (!$provider->isEnabled($context)) {
                WlsLogger::debug_("[Orchestrator] 服务 {$provider->getRole()} 未启用，跳过");
                continue;
            }

            $instanceCount = $provider->getInstanceCount($context);
            $role = $provider->getRole();
            $displayName = $provider->getDisplayName();
            $this->desiredState[$role] = $instanceCount;

            WlsLogger::info_("[Orchestrator] 重启服务 {$displayName} (role={$role}, instances={$instanceCount})");

            for ($i = 1; $i <= $instanceCount; $i++) {
                $instance = $this->startInstance($provider, $i, $context);
                if ($instance !== null) {
                    $startedCount++;
                    
                    // 启动后立即 poll IPC，处理子进程的连接和注册消息
                    $this->controlServer?->poll(0, 100000);
                }
            }
            
            // 每个服务类型启动完毕后额外 poll 一次
            $this->controlServer?->poll(0, 200000);
        }

        WlsLogger::info_("[Orchestrator] 子进程重启完成，共启动 {$startedCount} 个实例");

        // 持久化服务实例信息
        $this->persistServicesInfo($context);
    }

    /**
     * 按前缀清理逃逸子进程（不处理 Master 自身）
     */
    private function cleanupOrphanChildProcesses(bool $aggressiveKill = true): void
    {
        // 周期扫尾默认不做强杀，避免误杀“正在启动但尚未注册”的进程
        if (!$aggressiveKill) {
            $staleRemoved = Processer::cleanupStalePidFiles();
            $this->lastSweepKilled = 0;
            $this->lastSweepStalePidFiles = $staleRemoved;
            WlsLogger::info_("[Orchestrator] 轻量扫尾完成: killed=0, stale_pid_files={$staleRemoved}");
            return;
        }

        $prefixes = [
            'weline-wls-session-',
            'weline-wls-worker-',
            'weline-wls-dispatcher-',
            'weline-wls-redirect-',
            'weline-wls-maintenance-',
        ];

        $killed = 0;
        foreach ($prefixes as $prefix) {
            $killed += Processer::killByProcessNamePrefix($prefix);
        }

        $staleRemoved = Processer::cleanupStalePidFiles();
        $this->lastSweepKilled = $killed;
        $this->lastSweepStalePidFiles = $staleRemoved;
        WlsLogger::warning_("[Orchestrator] 子进程扫尾完成: killed={$killed}, stale_pid_files={$staleRemoved}");
    }

    /**
     * 期望状态收敛：确保实例数量与实例槽位一致
     */
    private function reconcileDesiredState(): void
    {
        if ($this->context === null || empty($this->desiredState)) {
            return;
        }

        foreach ($this->desiredState as $role => $desiredCount) {
            $provider = $this->registry->getProvider($role);
            if ($provider === null || !$provider->isEnabled($this->context)) {
                continue;
            }

            // 缺失实例补齐
            for ($slot = 1; $slot <= $desiredCount; $slot++) {
                $instance = $this->registry->getInstance($role, $slot);
                if ($instance === null || $instance->state === ServiceInstance::STATE_STOPPED || $instance->state === ServiceInstance::STATE_FAILED) {
                    WlsLogger::warning_("[Orchestrator] 收敛补齐实例 {$role}#{$slot}");
                    $this->registry->removeInstance($role, $slot);
                    $this->startInstance($provider, $slot, $this->context);
                    continue;
                }

                // 旧代际实例强制回收
                if ($instance->epoch !== $this->context->epoch) {
                    WlsLogger::warning_("[Orchestrator] 收敛回收旧代际实例 {$role}#{$slot} (epoch={$instance->epoch} -> {$this->context->epoch})");
                    $this->stopInstanceWithProtocol($instance);
                    $this->registry->removeInstance($role, $slot);
                    $this->startInstance($provider, $slot, $this->context);
                }
            }

            // 超额实例回收
            foreach ($this->registry->getInstancesByRole($role) as $instanceId => $instance) {
                if ($instanceId <= $desiredCount) {
                    continue;
                }
                WlsLogger::warning_("[Orchestrator] 收敛回收超额实例 {$role}#{$instanceId}");
                $this->stopInstanceWithProtocol($instance);
                $this->registry->removeInstance($role, $instanceId);
            }
        }
    }

    /**
     * 安排实例复活
     */
    private function scheduleResurrection(ServiceInstance $instance): void
    {
        $provider = $this->registry->getProvider($instance->role);
        if ($provider === null) {
            return;
        }

        $priority = $provider->getResurrectionPriority();
        if ($priority <= 0) {
            WlsLogger::info_("[Orchestrator] 服务 {$instance->role} 不参与复活");
            return;
        }

        $key = $instance->getKey();
        if (isset($this->resurrectQueue[$key])) {
            return;
        }

        $instance->state = ServiceInstance::STATE_FAILED;
        $instance->restarts++;
        $this->registry->updateInstance($instance);

        $maxRestarts = 10;
        if ($instance->restarts > $maxRestarts) {
            WlsLogger::error_("[Orchestrator] 服务 {$instance->role}#{$instance->instanceId} 已重启 {$instance->restarts} 次，放弃复活");
            return;
        }

        // 指数退避延迟
        $delay = \min(30.0, \pow(2, $instance->restarts - 1));

        $this->resurrectQueue[$key] = [
            'role' => $instance->role,
            'instanceId' => $instance->instanceId,
            'maxRestarts' => $maxRestarts,
            'restartDelay' => $delay,
            'scheduledAt' => \microtime(true) + $delay,
        ];

        WlsLogger::info_("[Orchestrator] 安排复活 {$instance->role}#{$instance->instanceId}，延迟 {$delay}s");
    }

    /**
     * 处理复活队列
     */
    private function processResurrectQueue(): void
    {
        if (empty($this->resurrectQueue)) {
            return;
        }

        $now = \microtime(true);
        foreach ($this->resurrectQueue as $key => $entry) {
            if ($now < $entry['scheduledAt']) {
                continue;
            }

            $provider = $this->registry->getProvider($entry['role']);
            if ($provider === null) {
                unset($this->resurrectQueue[$key]);
                continue;
            }

            // 获取旧实例（在移除前）用于清理和传递 restarts
            $oldInstance = $this->registry->getInstance($entry['role'], $entry['instanceId']);
            $oldRestarts = $oldInstance?->restarts ?? 0;

            // 延迟复活：检查进程是否仍在运行
            if (!empty($entry['delayed']) && !empty($entry['pid'])) {
                if ($this->isProcessRunning($entry['pid'])) {
                    // 进程仍在运行，尝试强制终止后再复活
                    WlsLogger::warning_("[Orchestrator] 进程 {$entry['pid']} 仍在运行（IPC已断开），强制终止");
                    if ($oldInstance !== null) {
                        $this->killInstanceProcess($oldInstance);
                    } else {
                        $this->forceKillProcess($entry['pid']);
                    }
                    // 稍等片刻让进程退出
                    \usleep(200000); // 200ms
                }
            }

            // 清理旧实例的 PID 文件
            if ($oldInstance !== null) {
                $this->cleanupInstancePidFile($oldInstance);
            }

            WlsLogger::info_("[Orchestrator] 执行复活 {$entry['role']}#{$entry['instanceId']}");

            // 移除旧实例
            $this->registry->removeInstance($entry['role'], $entry['instanceId']);

            // 启动新实例
            $newInstance = $this->startInstance($provider, $entry['instanceId'], $this->context);
            if ($newInstance !== null) {
                $newInstance->restarts = $oldRestarts;
            }

            unset($this->resurrectQueue[$key]);
        }
    }

    /**
     * 强制终止进程（委托给进程管理类）
     *
     * 遵循 SOLID 原则：进程管理职责由 Processer 类承担。
     */
    private function forceKillProcess(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        return Processer::killByPid($pid, true);
    }

    /**
     * 获取所有服务状态
     */
    public function getStatus(): array
    {
        $this->getMetricsAggregator()->flushDueBuckets(false);
        return [
            'running' => $this->running,
            'shutting_down' => $this->shuttingDown,
            'control_port' => $this->controlServer?->getPort() ?? 0,
            'ha_mode' => $this->haMode,
            'epoch' => $this->context?->epoch ?? 0,
            'maintenance_mode' => $this->maintenanceMode,
            'rolling_restart_in_progress' => $this->rollingRestartInProgress,
            'rolling_restart_progress' => $this->rollingRestartProgress,
            'rolling_restart_total' => $this->rollingRestartTotal,
            'services' => $this->registry->getStatusSnapshot(),
            'resurrect_queue' => \count($this->resurrectQueue),
            'metrics' => [
                'register_timeout_count' => $this->registerTimeoutCount,
                'full_restart_count' => $this->fullRestartCount,
                'last_sweep_killed' => $this->lastSweepKilled,
                'last_sweep_stale_pid_files' => $this->lastSweepStalePidFiles,
                'telemetry_bucket_count' => \count($this->getMetricsAggregator()->snapshotByHost($this->context?->instanceName ?? 'default', \time() - 300)),
            ],
        ];
    }

    /**
     * 处理 IPC 消息
     */
    public function handleIpcMessage(array $msg, int $clientId, MasterControlServer $server): void
    {
        $type = $msg['type'] ?? '';

        // 通用消息处理
        switch ($type) {
            case ControlMessage::TYPE_REGISTER:
                $this->handleRegister($msg, $clientId);
                return;

            case ControlMessage::TYPE_READY:
                $this->handleReady($msg, $clientId);
                return;

            case ControlMessage::TYPE_DRAINING_COMPLETE:
                $this->handleDrainingComplete($msg, $clientId);
                return;

            case ControlMessage::TYPE_EXIT_REASON:
                $this->handleExitReason($msg, $clientId);
                return;

            case ControlMessage::TYPE_COMMAND:
                $this->handleCommand($msg, $clientId);
                return;
            case ControlMessage::TYPE_TELEMETRY:
                $this->handleTelemetry($msg);
                return;
        }

        // 非通用消息：委托给对应 Provider 处理
        $this->delegateToProvider($msg, $clientId);
    }

    /**
     * 处理 register 消息
     *
     * 匹配策略（按优先级）：
     * 1. port 匹配（最可靠）
     * 2. instance_id/worker_id 匹配
     * 3. PID 匹配（Windows 下可能不准确，作为后备）
     * 4. 如果都不匹配但只有一个 STARTING 状态的实例，认为就是它
     */
    private function handleRegister(array $msg, int $clientId): void
    {
        $role = $msg['role'] ?? '';
        $pid = (int) ($msg['pid'] ?? 0);
        $port = (int) ($msg['port'] ?? 0);
        $workerId = (int) ($msg['worker_id'] ?? 0);
        $instanceIdFromMsg = (int) ($msg['instance_id'] ?? 0);
        $epoch = (int) ($msg['epoch'] ?? 0);
        $launchId = (string) ($msg['launch_id'] ?? '');

        // 代际校验：只接纳当前 epoch
        if ($this->context !== null && $epoch > 0 && $epoch !== $this->context->epoch) {
            WlsLogger::warning_("[Orchestrator] 丢弃旧代际 register: role={$role}, epoch={$epoch}, current_epoch={$this->context->epoch}");
            return;
        }

        // 查找匹配的实例
        $instances = $this->registry->getInstancesByRole($role);

        // 策略0：launch_id 精确匹配（最稳妥）
        if ($launchId !== '') {
            foreach ($instances as $instance) {
                if ($instance->launchId === $launchId) {
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId)) {
                        return;
                    }
                }
            }
        }

        // 策略1：port 匹配（最可靠）
        if ($port > 0) {
            foreach ($instances as $instance) {
                if ($instance->port === $port) {
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId)) {
                        return;
                    }
                }
            }
        }

        // 策略2：instance_id/worker_id 匹配
        if ($instanceIdFromMsg > 0 || $workerId > 0) {
            $targetId = $instanceIdFromMsg > 0 ? $instanceIdFromMsg : $workerId;
            foreach ($instances as $instance) {
                if ($instance->instanceId === $targetId) {
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId)) {
                        return;
                    }
                }
            }
        }

        // 策略3：PID 匹配（Windows 下可能不准确）
        if ($pid > 0) {
            foreach ($instances as $instance) {
                if ($instance->pid === $pid) {
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId)) {
                        return;
                    }
                }
            }
        }

        // 策略4：如果只有一个 STARTING 状态的同角色实例，认为就是它
        $startingInstances = \array_filter($instances, fn($i) => $i->state === ServiceInstance::STATE_STARTING && $i->ipcClientId === null);
        if (\count($startingInstances) === 1) {
            $instance = \reset($startingInstances);
            WlsLogger::info_("[Orchestrator] 匹配到唯一 STARTING 实例: {$role}#{$instance->instanceId}");
            if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId)) {
                return;
            }
        }

        WlsLogger::warning_("[Orchestrator] 未找到匹配的实例: role={$role}, pid={$pid}, port={$port}, workerId={$workerId}, epoch={$epoch}, launch_id={$launchId}");
    }

    /**
     * 注册实例的 IPC 连接
     */
    private function registerInstanceIpc(
        ServiceInstance $instance,
        int $clientId,
        int $pid,
        int $workerId,
        int $epoch = 0,
        string $launchId = ''
    ): bool
    {
        if ($this->context !== null && $epoch > 0 && $epoch !== $this->context->epoch) {
            WlsLogger::warning_("[Orchestrator] 忽略旧代际实例注册 {$instance->role}#{$instance->instanceId}: epoch={$epoch}");
            return false;
        }
        if ($launchId !== '' && $instance->launchId !== '' && $instance->launchId !== $launchId) {
            WlsLogger::warning_("[Orchestrator] 忽略 launchId 不匹配注册 {$instance->role}#{$instance->instanceId}: msg={$launchId}, expected={$instance->launchId}");
            return false;
        }

        $instance->ipcClientId = $clientId;
        $instance->state = ServiceInstance::STATE_REGISTERED;
        if ($epoch > 0) {
            $instance->epoch = $epoch;
        }
        if ($launchId !== '') {
            $instance->launchId = $launchId;
        }
        // 更新真实 PID（Windows 下 spawnProcess 返回的可能不准确）
        if ($pid > 0 && $instance->pid !== $pid) {
            WlsLogger::debug_("[Orchestrator] 更新 PID: {$instance->role}#{$instance->instanceId} 从 {$instance->pid} 到 {$pid}");
            $instance->pid = $pid;
        }
        if ($workerId > 0) {
            $instance->setMeta('worker_id', $workerId);
        }
        $this->registry->updateInstance($instance);

        if (\in_array($instance->role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
            $this->sendRoutingPolicyToWorker($instance);
        }

        WlsLogger::debug_("[Orchestrator] IPC 注册: {$instance->role}#{$instance->instanceId} (pid={$pid}, clientId={$clientId}, port={$instance->port}, epoch={$instance->epoch}, launch_id={$instance->launchId})");
        return true;
    }

    /**
     * 处理 ready 消息
     */
    private function handleReady(array $msg, int $clientId): void
    {
        $instance = $this->registry->getInstanceByIpcClient($clientId);
        if ($instance === null) {
            WlsLogger::warning_("[Orchestrator] ready 消息但未找到实例: clientId={$clientId}");
            return;
        }

        $epoch = (int) ($msg['epoch'] ?? 0);
        $launchId = (string) ($msg['launch_id'] ?? '');
        if ($this->context !== null && $epoch > 0 && $epoch !== $this->context->epoch) {
            WlsLogger::warning_("[Orchestrator] 丢弃旧代际 ready: {$instance->role}#{$instance->instanceId}, epoch={$epoch}");
            return;
        }
        if ($launchId !== '' && $instance->launchId !== '' && $launchId !== $instance->launchId) {
            WlsLogger::warning_("[Orchestrator] 丢弃 launchId 不匹配 ready: {$instance->role}#{$instance->instanceId}, msg={$launchId}, expected={$instance->launchId}");
            return;
        }

        // 更新实例端口（以上报的实际端口为准）
        $reportedPort = (int) ($msg['port'] ?? 0);
        if ($reportedPort > 0 && $reportedPort !== $instance->port) {
            WlsLogger::debug_("[Orchestrator] 更新 {$instance->role}#{$instance->instanceId} 端口: {$instance->port} -> {$reportedPort}");
            $instance->port = $reportedPort;
        }

        $instance->state = ServiceInstance::STATE_READY;
        $instance->setMeta('ready_at', \microtime(true));
        $this->registry->updateInstance($instance);

        // 发送 ACK_READY 确认
        $workerId = (int) ($instance->getMeta('worker_id') ?? $instance->instanceId);
        $this->controlServer?->sendTo($clientId, ControlMessage::ackReady($workerId));

        WlsLogger::info_("[Orchestrator] 服务就绪: {$instance->role}#{$instance->instanceId} (已发送 ACK, port={$instance->port})");

        // 如果是 Worker 就绪，通知 Dispatcher 添加该端口
        if ($instance->role === 'worker' && $instance->port !== null) {
            $this->notifyDispatcherWorkerReady($instance);
        }

        // 如果是 Dispatcher 就绪，发送所有已就绪的 Worker 端口列表和 HTTP Redirect 端口
        if ($instance->role === 'dispatcher') {
            $this->sendAllWorkerPortsToDispatcher($instance);
            $this->sendRedirectPortToDispatcher($instance);
        }
        
        // 如果是 Redirect 就绪，通知所有已就绪的 Dispatcher
        if ($instance->role === 'redirect' && $instance->port !== null) {
            $this->notifyDispatcherRedirectReady($instance);
        }

        if (\in_array($instance->role, [ControlMessage::ROLE_SESSION_SERVER, ControlMessage::ROLE_MEMORY_SERVER], true)) {
            $this->broadcastRoutingPolicyToWorkers();
        }
        
        // 更新实例文件中的服务信息
        if ($this->context !== null) {
            $this->persistServicesInfo($this->context);
        }
        
        // 检查是否所有服务都已就绪，输出服务器准备就绪通知
        $this->checkAndNotifyServerReady();
    }

    /**
     * 检查所有服务是否就绪，如果是则输出服务器准备就绪通知
     */
    private function checkAndNotifyServerReady(): void
    {
        if ($this->serverReadyNotified) {
            return;
        }

        $allInstances = $this->registry->getAllInstances();
        if (empty($allInstances)) {
            return;
        }

        // 检查所有实例是否都已就绪
        foreach ($allInstances as $instance) {
            if ($instance->state !== ServiceInstance::STATE_READY) {
                return;
            }
        }

        // 所有服务都已就绪
        $this->serverReadyNotified = true;

        $totalServices = \count($allInstances);
        $mainPort = $this->context?->mainPort ?? 0;
        $host = $this->context?->host ?? '0.0.0.0';
        $sslEnabled = $this->context?->sslEnabled ?? false;
        $protocol = $sslEnabled ? 'https' : 'http';

        // 输出醒目的服务器准备就绪通知
        WlsLogger::info_('[Server] ========================================');
        WlsLogger::info_('[Server] ✓ 服务器准备就绪');
        WlsLogger::info_("[Server]   地址: {$protocol}://{$host}:{$mainPort}");
        WlsLogger::info_("[Server]   服务实例: {$totalServices} 个");
        WlsLogger::info_('[Server] ========================================');

        // 前台模式输出彩色通知到控制台（含访问地址表，并设为悬浮顶部）
        if ($this->context?->frontend) {
            $ctx = $this->context;
            $defaultPort = $sslEnabled ? 443 : 80;
            $baseUrl = $protocol . '://' . $host . ($mainPort !== $defaultPort ? ':' . $mainPort : '');
            $backendPrefix = $ctx->getConfig('router.area_routes.backend.prefix') ?? '';
            $apiPath = $ctx->getConfig('router.area_routes.rest_frontend.prefix') ?: 'api';
            $apiAdminPath = $ctx->getConfig('router.area_routes.rest_backend.prefix') ?: 'api_admin';
            $httpRedirectPort = $ctx->httpRedirectPort ?? 0;

            $tableWidth = 76;
            $colType = 16;
            $colUrl = $tableWidth - $colType - 5;

            // 使用 DECSTBM 设置滚动区域，使地址表悬浮在顶部（约 14 行固定，后续日志在下方滚动）
            $bannerLines = 14;
            $scrollStart = $bannerLines + 1;
            echo "\033[{$scrollStart};9999r"; // 设置滚动区域从第 15 行到底部
            echo "\033[1;1H";                  // 光标移到左上角

            echo "\n" . self::ANSI_GREEN . "  ╔" . \str_repeat('═', $tableWidth) . "╗\n";
            echo "  ║" . \str_pad('   ✓ ' . __('服务器已就绪'), $tableWidth, ' ', STR_PAD_RIGHT) . "║\n";
            echo "  ╠" . \str_repeat('═', $colType) . '╤' . \str_repeat('═', $tableWidth - $colType - 1) . "╣\n";
            $frontendUrl = $baseUrl . '/';
            echo "  ║ " . \str_pad(__('前端'), $colType - 2, ' ') . "│ " . \str_pad($frontendUrl, $colUrl - 1, ' ') . "║\n";
            // 后端入口 = 密钥路径 + /admin（backend prefix 为随机 key 时）
            $backendUrl = $baseUrl . '/' . ($backendPrefix !== '' ? $backendPrefix . '/' : '') . 'admin';
            echo "  ║ " . \str_pad(__('后端'), $colType - 2, ' ') . "│ " . \str_pad($backendUrl, $colUrl - 1, ' ') . "║\n";
            echo "  ╟" . \str_repeat('─', $colType) . "┼" . \str_repeat('─', $tableWidth - $colType - 1) . "╢\n";
            $apiUrl = $baseUrl . '/' . $apiPath . '/';
            echo "  ║ " . \str_pad(__('REST API 前端'), $colType - 2, ' ') . "│ " . \str_pad($apiUrl, $colUrl - 1, ' ') . "║\n";
            $apiAdminUrl = $baseUrl . '/' . $apiAdminPath . '/';
            echo "  ║ " . \str_pad(__('REST API 后端'), $colType - 2, ' ') . "│ " . \str_pad($apiAdminUrl, $colUrl - 1, ' ') . "║\n";
            if ($sslEnabled && $httpRedirectPort > 0) {
                $httpUrl = "http://{$host}:{$httpRedirectPort}/ → HTTPS";
                echo "  ║ " . \str_pad(__('HTTP 重定向'), $colType - 2, ' ') . "│ " . \str_pad($httpUrl, $colUrl - 1, ' ') . "║\n";
            }
            echo "  ╟" . \str_repeat('─', $colType) . "┴" . \str_repeat('─', $tableWidth - $colType - 1) . "╢\n";
            echo "  ║" . \str_pad('   ' . __('服务实例: %{1} 个已就绪', [(string) $totalServices]), $tableWidth, ' ', STR_PAD_RIGHT) . "║\n";
            echo "  ╚" . \str_repeat('═', $tableWidth) . "╝" . self::ANSI_RESET . "\n";

            // 光标移到滚动区域首行，后续日志在此区域滚动
            echo "\033[{$scrollStart};1H";
            if (\function_exists('flush')) {
                @\flush();
            }
            // 进程退出时重置终端滚动区域，避免留下异常状态
            static $scrollResetRegistered = false;
            if (!$scrollResetRegistered) {
                $scrollResetRegistered = true;
                \register_shutdown_function(static function (): void {
                    echo "\033[r"; // 重置 DECSTBM 为默认（全屏滚动）
                });
            }
        }
    }

    /**
     * 重置服务器就绪通知状态（用于重启时）
     */
    public function resetServerReadyNotification(): void
    {
        $this->serverReadyNotified = false;
    }

    /**
     * 发送所有已就绪的 Worker 端口给 Dispatcher
     */
    private function sendAllWorkerPortsToDispatcher(ServiceInstance $dispatcherInstance): void
    {
        if ($dispatcherInstance->ipcClientId === null || $this->controlServer === null) {
            return;
        }

        $workerPorts = [];
        $workers = $this->registry->getInstancesByRole('worker');
        foreach ($workers as $worker) {
            if ($worker->state === ServiceInstance::STATE_READY && $worker->port !== null) {
                $workerPorts[] = $worker->port;
            }
        }

        if (!empty($workerPorts)) {
            $this->controlServer->sendTo($dispatcherInstance->ipcClientId, ControlMessage::addWorker($workerPorts));
            WlsLogger::info_("[Orchestrator] 发送 Worker 端口列表给 Dispatcher#{$dispatcherInstance->instanceId}: " . \implode(', ', $workerPorts));
        }
    }

    /**
     * 通知 Dispatcher 有 Worker 就绪
     */
    private function notifyDispatcherWorkerReady(ServiceInstance $workerInstance): void
    {
        $dispatchers = $this->registry->getInstancesByRole('dispatcher');
        if (empty($dispatchers)) {
            return;
        }

        $addWorkerMsg = ControlMessage::addWorker([$workerInstance->port]);

        foreach ($dispatchers as $dispatcher) {
            if ($dispatcher->ipcClientId !== null && $this->controlServer !== null) {
                $this->controlServer->sendTo($dispatcher->ipcClientId, $addWorkerMsg);
                WlsLogger::debug_("[Orchestrator] 通知 Dispatcher#{$dispatcher->instanceId} 添加 Worker 端口 {$workerInstance->port}");
            }
        }
    }

    /**
     * 发送 HTTP Redirect 端口给 Dispatcher（Dispatcher 就绪时调用）
     */
    private function sendRedirectPortToDispatcher(ServiceInstance $dispatcherInstance): void
    {
        if ($dispatcherInstance->ipcClientId === null || $this->controlServer === null) {
            return;
        }

        // 查找已就绪的 redirect 实例
        $redirectInstances = $this->registry->getInstancesByRole('redirect');
        foreach ($redirectInstances as $redirectInstance) {
            if ($redirectInstance->state === ServiceInstance::STATE_READY && $redirectInstance->port !== null) {
                $this->controlServer->sendTo(
                    $dispatcherInstance->ipcClientId,
                    ControlMessage::setRedirectPort($redirectInstance->port)
                );
                WlsLogger::info_("[Orchestrator] 发送 HTTP Redirect 端口 {$redirectInstance->port} 给 Dispatcher#{$dispatcherInstance->instanceId}");
                break; // 只有一个 redirect 实例
            }
        }
    }

    /**
     * 通知 Dispatcher HTTP Redirect Worker 就绪（Redirect 就绪时调用）
     */
    private function notifyDispatcherRedirectReady(ServiceInstance $redirectInstance): void
    {
        $dispatchers = $this->registry->getInstancesByRole('dispatcher');
        if (empty($dispatchers)) {
            return;
        }

        $setRedirectMsg = ControlMessage::setRedirectPort($redirectInstance->port);

        foreach ($dispatchers as $dispatcher) {
            if ($dispatcher->state === ServiceInstance::STATE_READY
                && $dispatcher->ipcClientId !== null
                && $this->controlServer !== null
            ) {
                $this->controlServer->sendTo($dispatcher->ipcClientId, $setRedirectMsg);
                WlsLogger::info_("[Orchestrator] 通知 Dispatcher#{$dispatcher->instanceId} HTTP Redirect 端口 {$redirectInstance->port}");
            }
        }
    }

    /**
     * 处理 draining_complete 消息
     */
    private function handleDrainingComplete(array $msg, int $clientId): void
    {
        $instance = $this->registry->getInstanceByIpcClient($clientId);
        if ($instance === null) {
            return;
        }

        $instance->state = ServiceInstance::STATE_STOPPING;
        $this->registry->updateInstance($instance);

        WlsLogger::info_("[Orchestrator] 排水完成: {$instance->role}#{$instance->instanceId}");
    }

    /**
     * 处理 exit_reason 消息（Worker 退出前上报原因，best-effort，Master 兼容缺失）
     */
    private function handleExitReason(array $msg, int $clientId): void
    {
        $instance = $this->registry->getInstanceByIpcClient($clientId);
        if ($instance === null) {
            return;
        }

        $reason = (string)($msg['reason'] ?? 'unknown');
        $code = (int)($msg['code'] ?? 0);
        $instance->setMeta('exit_reason', $reason);
        if ($code !== 0) {
            $instance->setMeta('exit_code', $code);
        }
        $this->registry->updateInstance($instance);
        WlsLogger::info_("[Orchestrator] 退出原因: {$instance->role}#{$instance->instanceId} reason={$reason}" . ($code !== 0 ? " code={$code}" : ''));
    }

    /**
     * 处理 command 消息
     */
    private function handleCommand(array $msg, int $clientId): void
    {
        $action = $msg['action'] ?? '';

        switch ($action) {
            case ControlMessage::ACTION_STATUS:
                $status = $this->getStatus();
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(true, $status, 'Status retrieved'));
                break;

            case ControlMessage::ACTION_RELOAD:
                // 异步重载：先发送响应，再执行（不阻塞 CLI）
                $type = $msg['reload_type'] ?? ControlMessage::RELOAD_TYPE_CODE;
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(true, [], 'Reload initiated'));
                if ($type === ControlMessage::RELOAD_TYPE_CACHE) {
                    // cache 类型：仅广播缓存清理，不重启 Worker
                    $this->broadcastCacheClear();
                } else {
                    // code/force 类型：滚动重启 Worker
                    $this->reloadAll($type);
                }
                break;

            case ControlMessage::ACTION_RELOAD_WAIT:
                // 同步重载：等待完成后发送完成消息
                $this->rollingRestartClientId = $clientId; // 记录请求客户端 ID，用于发送进度/完成消息
                $type = $msg['reload_type'] ?? ControlMessage::RELOAD_TYPE_CODE;
                // 先发送 "initiated" 表示已收到
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(true, [], 'Reload initiated'));
                if ($type === ControlMessage::RELOAD_TYPE_CACHE) {
                    // cache 类型：仅广播缓存清理，不重启 Worker
                    $this->broadcastCacheClear();
                } else {
                    // code/force 类型：滚动重启 Worker，完成后 gracefulReloadInstances 会发送 RELOAD_COMPLETED
                    $this->reloadAll($type);
                }
                break;

            case ControlMessage::ACTION_STOP:
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(true, [], 'Stopping'));
                $this->stopAll('command', $clientId);
                break;

            case ControlMessage::ACTION_CACHE_CLEAR:
                $this->broadcastCacheClear();
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(true, [], 'Cache clear broadcast sent'));
                break;

            case ControlMessage::ACTION_MAINTENANCE_ENABLE:
                $result = $this->enableMaintenanceMode();
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(
                    $result['success'],
                    $result,
                    $result['message']
                ));
                break;

            case ControlMessage::ACTION_MAINTENANCE_DISABLE:
                $result = $this->disableMaintenanceMode();
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(
                    $result['success'],
                    $result,
                    $result['message']
                ));
                break;

            case ControlMessage::ACTION_ROLLING_RESTART:
                $result = $this->startRollingRestart($clientId);
                if (!$result['success']) {
                    $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(false, $result, $result['message']));
                }
                break;

            case ControlMessage::ACTION_SECURITY_UNBLOCK:
                $ip = $msg['ip'] ?? null;
                $clearAll = !empty($msg['clear_all']);
                $dispatchers = $this->registry->getInstancesByRole('dispatcher');
                $sent = 0;
                foreach ($dispatchers as $dispatcher) {
                    if ($dispatcher->ipcClientId !== null && $this->controlServer !== null) {
                        $this->controlServer->sendTo(
                            $dispatcher->ipcClientId,
                            ControlMessage::securityUnblock($ip !== null && $ip !== '' ? $ip : null, $clearAll)
                        );
                        $sent++;
                    }
                }
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(
                    true,
                    ['dispatchers_notified' => $sent],
                    $clearAll ? __('已通知 %{1} 个 Dispatcher 清空封禁列表', [$sent])
                        : ($ip !== null && $ip !== '' ? __('已通知 %{1} 个 Dispatcher 解封 IP %{2}', [$sent, $ip]) : __('未指定 ip 或 clear_all'))
                ));
                break;
            case ControlMessage::ACTION_TELEMETRY_QUERY:
                $instance = (string)($msg['instance'] ?? ($this->context?->instanceName ?? 'default'));
                $windowSec = (int)($msg['window_sec'] ?? 300);
                $host = (string)($msg['host'] ?? '');
                $sinceTs = \time() - \max(60, $windowSec);
                $aggregator = $this->getMetricsAggregator();
                $aggregator->flushDueBuckets(false);
                $data = [
                    'global' => $aggregator->snapshotGlobal($instance, $sinceTs),
                    'hosts' => $aggregator->snapshotByHost($instance, $sinceTs),
                    'host_detail' => $host !== '' ? $aggregator->snapshotHostDetail($instance, $host, $sinceTs) : null,
                ];
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(true, $data, 'Telemetry retrieved'));
                break;
        }
    }

    private function handleTelemetry(array $msg): void
    {
        $this->getTelemetryGateway()->record([
            'instance' => (string)($msg['instance'] ?? ($this->context?->instanceName ?? 'default')),
            'host' => (string)($msg['host'] ?? ''),
            'status' => (int)($msg['status'] ?? 200),
            'latency_ms' => (int)($msg['latency_ms'] ?? 0),
            'bytes_out' => (int)($msg['bytes_out'] ?? 0),
            'ts' => (int)($msg['ts'] ?? \time()),
        ]);
    }

    private function getTelemetryGateway(): IpcTelemetryGateway
    {
        if ($this->telemetryGateway === null) {
            $this->telemetryGateway = ObjectManager::getInstance(IpcTelemetryGateway::class);
        }
        return $this->telemetryGateway;
    }

    private function getMetricsAggregator(): InMemoryMetricsAggregator
    {
        if ($this->metricsAggregator === null) {
            $this->metricsAggregator = ObjectManager::getInstance(InMemoryMetricsAggregator::class);
        }
        return $this->metricsAggregator;
    }

    /**
     * 启用维护模式：启动维护 Worker
     *
     * @return array{success: bool, message: string, maintenance_workers: int}
     */
    public function enableMaintenanceMode(): array
    {
        if ($this->maintenanceMode) {
            return [
                'success' => true,
                'message' => 'Maintenance mode already enabled',
                'maintenance_workers' => $this->countMaintenanceWorkers(),
            ];
        }

        if ($this->context === null) {
            return [
                'success' => false,
                'message' => 'Context not initialized',
                'maintenance_workers' => 0,
            ];
        }

        WlsLogger::info_('[Orchestrator] 启用维护模式');

        $maintenanceProvider = $this->getMaintenanceProvider();
        if ($maintenanceProvider === null) {
            return [
                'success' => false,
                'message' => 'Maintenance provider not found',
                'maintenance_workers' => 0,
            ];
        }

        $maintenanceProvider->enable();
        $this->maintenanceMode = true;

        $instanceCount = $maintenanceProvider->getInstanceCount($this->context);
        $this->desiredState['maintenance'] = $instanceCount;

        for ($i = 1; $i <= $instanceCount; $i++) {
            $instance = $this->startInstance($maintenanceProvider, $i, $this->context);
            if ($instance !== null) {
                WlsLogger::info_("[Orchestrator] 启动维护 Worker #{$i}");
            }
        }

        $this->persistServicesInfo($this->context);

        return [
            'success' => true,
            'message' => "Maintenance mode enabled with {$instanceCount} worker(s)",
            'maintenance_workers' => $instanceCount,
        ];
    }

    /**
     * 禁用维护模式：停止维护 Worker
     *
     * @return array{success: bool, message: string}
     */
    public function disableMaintenanceMode(): array
    {
        if (!$this->maintenanceMode) {
            return [
                'success' => true,
                'message' => 'Maintenance mode already disabled',
            ];
        }

        if ($this->rollingRestartInProgress) {
            return [
                'success' => false,
                'message' => 'Cannot disable maintenance mode during rolling restart',
            ];
        }

        WlsLogger::info_('[Orchestrator] 禁用维护模式');

        $maintenanceProvider = $this->getMaintenanceProvider();
        if ($maintenanceProvider !== null) {
            $maintenanceProvider->disable();
        }

        $this->stopMaintenanceWorkers();
        $this->maintenanceMode = false;
        unset($this->desiredState['maintenance']);

        if ($this->context !== null) {
            $this->persistServicesInfo($this->context);
        }

        return [
            'success' => true,
            'message' => 'Maintenance mode disabled',
        ];
    }

    /**
     * 开始滚动重启
     *
     * @param int|null $clientId 等待结果的 CLI 客户端 ID
     * @return array{success: bool, message: string}
     */
    public function startRollingRestart(?int $clientId = null): array
    {
        if ($this->rollingRestartInProgress) {
            return [
                'success' => false,
                'message' => 'Rolling restart already in progress',
            ];
        }

        if ($this->context === null) {
            return [
                'success' => false,
                'message' => 'Context not initialized',
            ];
        }

        WlsLogger::info_('[Orchestrator] 开始滚动重启');

        if (!$this->maintenanceMode) {
            $enableResult = $this->enableMaintenanceMode();
            if (!$enableResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to enable maintenance mode: ' . $enableResult['message'],
                ];
            }
            \usleep(500000);
            $this->controlServer?->poll(0, 100000);
        }

        $workers = $this->registry->getInstancesByRole('worker');
        $workerCount = \count($workers);

        if ($workerCount === 0) {
            $this->disableMaintenanceMode();
            return [
                'success' => false,
                'message' => 'No workers to restart',
            ];
        }

        $this->rollingRestartInProgress = true;
        $this->rollingRestartClientId = $clientId;
        $this->rollingRestartProgress = 0;
        $this->rollingRestartTotal = $workerCount;

        $this->sendRollingRestartProgress("Starting rolling restart of {$workerCount} workers");

        $this->performRollingRestart();

        return [
            'success' => true,
            'message' => "Rolling restart initiated for {$workerCount} workers",
        ];
    }

    /**
     * 执行滚动重启的核心逻辑
     */
    private function performRollingRestart(): void
    {
        if ($this->context === null) {
            $this->finishRollingRestart(false, 'Context lost');
            return;
        }

        $startTime = \microtime(true);
        $workerProvider = $this->registry->getProvider('worker');
        if ($workerProvider === null) {
            $this->finishRollingRestart(false, 'Worker provider not found');
            return;
        }

        $workers = $this->registry->getInstancesByRole('worker');
        $total = \count($workers);
        $restarted = 0;

        foreach ($workers as $worker) {
            if (!$this->rollingRestartInProgress) {
                WlsLogger::warning_('[Orchestrator] 滚动重启被中断');
                return;
            }

            $instanceId = $worker->instanceId;
            WlsLogger::info_("[Orchestrator] 滚动重启 Worker #{$instanceId}");

            $this->sendRollingRestartProgress("Restarting worker #{$instanceId} ({$restarted}/{$total})");

            if ($worker->ipcClientId !== null) {
                $this->controlServer?->sendTo($worker->ipcClientId, ControlMessage::shutdown());
            }

            $startWait = \microtime(true);
            $maxWait = 10.0;
            while ((\microtime(true) - $startWait) < $maxWait) {
                $this->controlServer?->poll(0, 100000);

                $currentWorker = $this->registry->getInstance('worker', $instanceId);
                if ($currentWorker === null || $currentWorker->ipcClientId === null) {
                    break;
                }
                \usleep(100000);
            }

            $this->registry->removeInstance('worker', $instanceId);

            $newInstance = $this->startInstance($workerProvider, $instanceId, $this->context);
            if ($newInstance === null) {
                WlsLogger::error_("[Orchestrator] 滚动重启 Worker #{$instanceId} 失败");
                $this->finishRollingRestart(false, "Failed to restart worker #{$instanceId}");
                return;
            }

            $startWait = \microtime(true);
            $maxWait = $this->startupTimeout;
            $ready = false;
            while ((\microtime(true) - $startWait) < $maxWait) {
                $this->controlServer?->poll(0, 100000);

                $currentWorker = $this->registry->getInstance('worker', $instanceId);
                if ($currentWorker !== null && $currentWorker->state === Contract\ServiceInstance::STATE_READY) {
                    $ready = true;
                    break;
                }
                \usleep(100000);
            }

            if (!$ready) {
                WlsLogger::error_("[Orchestrator] Worker #{$instanceId} 在 {$maxWait}s 内未进入 READY，滚动重启失败");
                $this->finishRollingRestart(false, "Worker #{$instanceId} not ready within {$maxWait}s");
                return;
            }

            $restarted++;
            $this->rollingRestartProgress = $restarted;
            WlsLogger::info_("[Orchestrator] Worker #{$instanceId} 重启完成 ({$restarted}/{$total})");
        }

        $elapsedMs = (\microtime(true) - $startTime) * 1000;
        $this->finishRollingRestart(true, "Successfully restarted {$restarted} workers", $elapsedMs);
    }

    /**
     * 完成滚动重启
     */
    private function finishRollingRestart(bool $success, string $message, float $elapsedMs = 0): void
    {
        WlsLogger::info_("[Orchestrator] 滚动重启完成: success={$success}, message={$message}, elapsed={$elapsedMs}ms");

        // 先清理滚动标志，再禁用维护模式，避免 disableMaintenanceMode() 因“滚动中”被拒绝。
        $clientId = $this->rollingRestartClientId;
        $progress = $this->rollingRestartProgress;
        $total = $this->rollingRestartTotal;
        $this->rollingRestartInProgress = false;
        $this->rollingRestartClientId = null;

        $disableResult = $this->disableMaintenanceMode();
        if (!($disableResult['success'] ?? false)) {
            WlsLogger::warning_("[Orchestrator] 滚动重启后禁用维护模式失败: " . ($disableResult['message'] ?? 'unknown'));
        }

        if ($clientId !== null) {
            $msgType = $success ? ControlMessage::TYPE_RELOAD_COMPLETED : ControlMessage::TYPE_RELOAD_FAILED;
            $this->controlServer?->sendTo($clientId, ControlMessage::encode([
                'type' => $msgType,
                'success' => $success,
                'message' => $message,
                'progress' => $progress,
                'total' => $total,
                'elapsed_ms' => $elapsedMs,
                'worker_count' => $total,
            ]));
        }

        $this->rollingRestartProgress = 0;
        $this->rollingRestartTotal = 0;

        if ($this->context !== null) {
            $this->persistServicesInfo($this->context);
        }
    }

    /**
     * 发送滚动重启进度
     */
    private function sendRollingRestartProgress(string $message): void
    {
        if ($this->rollingRestartClientId !== null) {
            $this->controlServer?->sendTo($this->rollingRestartClientId, ControlMessage::encode([
                'type' => ControlMessage::TYPE_RELOAD_PROGRESS,
                'message' => $message,
                'progress' => $this->rollingRestartProgress,
                'total' => $this->rollingRestartTotal,
            ]));
        }
    }

    /**
     * 获取 MaintenanceWorkerProvider
     */
    private function getMaintenanceProvider(): ?Provider\MaintenanceWorkerProvider
    {
        $provider = $this->registry->getProvider('maintenance');
        if ($provider instanceof Provider\MaintenanceWorkerProvider) {
            return $provider;
        }
        return null;
    }

    /**
     * 停止所有维护 Worker
     */
    private function stopMaintenanceWorkers(): void
    {
        $maintenanceWorkers = $this->registry->getInstancesByRole('maintenance');
        foreach ($maintenanceWorkers as $worker) {
            if ($worker->ipcClientId !== null) {
                $this->controlServer?->sendTo($worker->ipcClientId, ControlMessage::shutdown());
            }
            $this->registry->removeInstance('maintenance', $worker->instanceId);
        }
    }

    /**
     * 统计维护 Worker 数量
     */
    private function countMaintenanceWorkers(): int
    {
        return \count($this->registry->getInstancesByRole('maintenance'));
    }

    /**
     * 广播缓存清理消息给所有 Worker
     */
    private function broadcastCacheClear(): void
    {
        if ($this->controlServer === null) {
            return;
        }
        $configuredRoles = $this->context?->getConfig('server.orchestrator.cache_clear_roles', ['worker']);
        $targetRoles = \is_array($configuredRoles) ? $configuredRoles : ['worker'];
        $targetRoles = \array_values(\array_filter(\array_map('strval', $targetRoles)));
        if (empty($targetRoles)) {
            $targetRoles = ['worker'];
        }

        $targets = [];
        foreach ($this->registry->getAllInstances() as $instance) {
            if ($instance->ipcClientId === null) {
                continue;
            }
            if (!\in_array($instance->role, $targetRoles, true)) {
                continue;
            }
            $targets[] = "{$instance->role}#{$instance->instanceId}(ipc:{$instance->ipcClientId})";
            $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::cacheClear());
        }
        WlsLogger::info_('[IPC] CACHE_CLEAR -> ' . (!empty($targets) ? \implode(', ', $targets) : '(无匹配目标)'));
    }

    /**
     * 提供统一的子进程控制能力快照，供状态展示与控制面决策使用。
     */
    private function buildControlCapabilities(ServiceProviderInterface $provider): array
    {
        return [
            'startup_ready_barrier' => $provider->requiresStartupReadyBarrier(),
            'drain' => $provider->supportsDrain(),
            'shutdown' => $provider->supportsShutdown(),
            'reload' => $provider->supportsReload(),
            'reload_strategy' => $provider->getReloadStrategy(),
            'critical_role' => $provider->isCriticalRole(),
            'resurrection_priority' => $provider->getResurrectionPriority(),
        ];
    }

    /**
     * 发送最新路由策略给单个 Worker。
     */
    private function sendRoutingPolicyToWorker(ServiceInstance $instance): void
    {
        if ($instance->ipcClientId === null || $this->controlServer === null) {
            return;
        }
        $policy = $this->buildRoutingPolicySnapshot();
        $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::routingPolicy($policy));
        WlsLogger::debug_("[Orchestrator] ROUTING_POLICY -> {$instance->role}#{$instance->instanceId}(ipc:{$instance->ipcClientId})");
    }

    /**
     * 广播最新路由策略给所有 Worker/Maintenance。
     */
    private function broadcastRoutingPolicyToWorkers(): void
    {
        if ($this->controlServer === null) {
            return;
        }
        $policy = $this->buildRoutingPolicySnapshot();
        $message = ControlMessage::routingPolicy($policy);
        $targets = [];

        foreach ($this->registry->getAllInstances() as $instance) {
            if ($instance->ipcClientId === null) {
                continue;
            }
            if (!\in_array($instance->role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
                continue;
            }
            $targets[] = "{$instance->role}#{$instance->instanceId}(ipc:{$instance->ipcClientId})";
            $this->controlServer->sendTo($instance->ipcClientId, $message);
        }

        WlsLogger::info_('[IPC] ROUTING_POLICY -> ' . (!empty($targets) ? \implode(', ', $targets) : '(无匹配目标)'));
    }

    /**
     * 构建 Worker 侧驱动路由策略快照。
     *
     * @return array<string, mixed>
     */
    private function buildRoutingPolicySnapshot(): array
    {
        $sessionEndpoint = $this->resolveServiceEndpoint(ControlMessage::ROLE_SESSION_SERVER, 19970);
        $memoryEndpoint = $this->resolveServiceEndpoint(ControlMessage::ROLE_MEMORY_SERVER, 19971);

        return [
            'version' => 1,
            'effective_at' => \time(),
            'routing' => [
                'session' => [
                    'hijack_file_driver' => true,
                    'wls_driver' => 'wls',
                ],
                'cache' => [
                    'hijack_file_driver' => true,
                    'wls_driver' => 'wls_memory',
                ],
            ],
            'endpoints' => [
                'session' => $sessionEndpoint,
                'memory' => $memoryEndpoint,
            ],
        ];
    }

    /**
     * 解析指定角色服务端点。
     *
     * @return array{host: string, port: int}
     */
    private function resolveServiceEndpoint(string $role, int $defaultPort): array
    {
        $host = '127.0.0.1';
        $port = $defaultPort;
        $instances = $this->registry->getInstancesByRole($role);

        foreach ($instances as $instance) {
            if ($instance->state === ServiceInstance::STATE_READY && $instance->port !== null && $instance->port > 0) {
                return ['host' => $host, 'port' => (int)$instance->port];
            }
        }

        foreach ($instances as $instance) {
            if ($instance->port !== null && $instance->port > 0) {
                $port = (int)$instance->port;
                break;
            }
        }

        return ['host' => $host, 'port' => $port];
    }

    /**
     * 委托给 Provider 处理非通用消息
     */
    private function delegateToProvider(array $msg, int $clientId): void
    {
        $instance = $this->registry->getInstanceByIpcClient($clientId);
        if ($instance === null) {
            return;
        }

        $provider = $this->registry->getProvider($instance->role);
        if ($provider === null) {
            return;
        }

        $handled = $provider->handleMessage($msg, $instance, $this);
        if (!$handled) {
            WlsLogger::debug_("[Orchestrator] 未处理的消息: type=" . ($msg['type'] ?? 'unknown') . ", role={$instance->role}");
        }
    }

    /**
     * 处理 IPC 断开
     */
    public function handleIpcDisconnect(int $clientId, array $clientInfo, MasterControlServer $server): void
    {
        $instance = $this->registry->getInstanceByIpcClient($clientId);
        if ($instance === null) {
            return;
        }
        
        $provider = $this->registry->getProvider($instance->role);
        $displayName = $provider?->getDisplayName() ?? $instance->role;

        WlsLogger::warning_("[Orchestrator] IPC 断开: {$instance->role}#{$instance->instanceId} (pid={$instance->pid})");

        // 清除 IPC 客户端 ID（连接已断开）
        $instance->ipcClientId = null;
        $this->registry->updateInstance($instance);

        // 正在关闭：仅发送进度消息
        if ($this->shuttingDown) {
            $this->sendStopProgress("  ✓ {$displayName}(PID:{$instance->pid}) 已断开连接");
            return;
        }

        // 正在排水、停止中或已停止的实例（graceful reload 主动停止）→ 预期断开，不触发整组重启和复活
        // STATE_STOPPING：draining_complete 后、Worker 退出前，IPC 断开时应跳过（否则会重复安排延迟复活导致 Worker 数量翻倍）
        if (\in_array($instance->state, [
            ServiceInstance::STATE_DRAINING,
            ServiceInstance::STATE_STOPPING,
            ServiceInstance::STATE_STOPPED,
        ], true)) {
            WlsLogger::info_("[Orchestrator] 实例 {$instance->role}#{$instance->instanceId} 处于 {$instance->state} 状态，预期断开，跳过整组重启");
            return;
        }

        $now = \microtime(true);
        $isNewInstance = $instance->getUptime() < $this->stabilizationSec;
        if ($this->rollingRestartStabilizingUntil > 0 && $now < $this->rollingRestartStabilizingUntil && $isNewInstance) {
            $provider = $this->registry->getProvider($instance->role);
            if ($provider !== null && $provider->getResurrectionPriority() > 0) {
                WlsLogger::info_("[Orchestrator] 稳定期内新实例 {$instance->role}#{$instance->instanceId} 断开，仅单实例重启");
                $this->scheduleResurrectionWithDelay($instance, 2.0);
                return;
            }
        }

        // 分级故障策略：可恢复角色优先单实例重启，核心角色或超阈值则整组重启
        $isCritical = isset($this->criticalRoles[$instance->role]);
        if ($isCritical || !$this->singleRestartFirst) {
            $this->requestFullRestart("ipc_disconnect:{$instance->role}#{$instance->instanceId}");
            return;
        }

        $resurrectionPriority = $provider?->getResurrectionPriority() ?? 0;
        if ($resurrectionPriority <= 0) {
            $this->requestFullRestart("ipc_disconnect:{$instance->role}#{$instance->instanceId}");
            return;
        }

        // 记录 escalation 计数
        $role = $instance->role;
        if (!isset($this->escalationDisconnects[$role]) || $now - $this->escalationDisconnects[$role]['windowStart'] > $this->escalationWindowSec) {
            $this->escalationDisconnects[$role] = ['count' => 0, 'windowStart' => $now];
        }
        $this->escalationDisconnects[$role]['count']++;

        $maxRestarts = 10;
        if ($instance->restarts >= $maxRestarts) {
            $this->requestFullRestart("ipc_disconnect:max_restarts:{$instance->role}#{$instance->instanceId} (restarts={$instance->restarts})");
            return;
        }

        if ($this->escalationDisconnects[$role]['count'] <= $this->escalationThreshold) {
            $this->scheduleResurrectionWithDelay($instance, 2.0);
            return;
        }

        $this->requestFullRestart("ipc_disconnect:escalation:{$instance->role}#{$instance->instanceId} (count={$this->escalationDisconnects[$role]['count']})");
    }

    /**
     * 安排延迟复活（IPC 断开但进程可能还在运行）
     */
    private function scheduleResurrectionWithDelay(ServiceInstance $instance, float $delay): void
    {
        $key = $instance->getKey();

        // 如果已在队列中，保持现有安排
        if (isset($this->resurrectQueue[$key])) {
            return;
        }

        $provider = $this->registry->getProvider($instance->role);
        if ($provider === null || $provider->getResurrectionPriority() <= 0) {
            WlsLogger::info_("[Orchestrator] 服务 {$instance->role} 不参与复活");
            return;
        }

        $instance->state = ServiceInstance::STATE_FAILED;
        $instance->restarts++;
        $this->registry->updateInstance($instance);

        $this->resurrectQueue[$key] = [
            'role' => $instance->role,
            'instanceId' => $instance->instanceId,
            'maxRestarts' => 10,
            'restartDelay' => $delay,
            'scheduledAt' => \microtime(true) + $delay,
            'delayed' => true,  // 标记为延迟复活，执行前需要再次检查进程状态
            'pid' => $instance->pid,  // 保存 PID 用于检查进程是否仍在运行
        ];
        WlsLogger::info_("[Orchestrator] 安排延迟复活 {$instance->role}#{$instance->instanceId}，延迟 {$delay}s (pid={$instance->pid})");
    }

    /**
     * 委托给 Processer 杀死进程
     */
    private function killProcess(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        return Processer::killByPid($pid);
    }

    /**
     * 委托给 Processer 检查进程存活
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        return Processer::isRunningByPid($pid);
    }

    /**
     * 发送消息给指定角色的所有实例
     */
    public function broadcastToRole(string $role, string $message): void
    {
        $this->controlServer?->sendToRole($role, $message);
    }

    /**
     * 发送消息给指定实例
     */
    public function sendToInstance(ServiceInstance $instance, string $message): bool
    {
        if ($instance->ipcClientId === null || $this->controlServer === null) {
            return false;
        }
        return $this->controlServer->sendTo($instance->ipcClientId, $message);
    }

    /**
     * 设置健康检查间隔
     */
    public function setHealthCheckInterval(float $interval): void
    {
        $this->healthCheckInterval = $interval;
    }

    /**
     * 设置启动超时
     */
    public function setStartupTimeout(float $timeout): void
    {
        $this->startupTimeout = $timeout;
    }

    /**
     * 设置排水超时
     */
    public function setDrainTimeout(float $timeout): void
    {
        $this->drainTimeout = $timeout;
    }

    /**
     * 检查是否正在运行
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * 检查是否正在关闭
     */
    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /**
     * 停止主循环
     */
    public function stop(): void
    {
        $this->running = false;
    }
}
