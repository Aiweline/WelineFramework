<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Observer\SchedulerWaitObserver;
use Weline\Server\Scheduler\FiberScheduler;
use Weline\Server\Service\Contract\HealthCheckResult;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Contract\ServiceProviderInterface;
use Weline\Server\Service\Control\ControlPlaneServerInterface;
use Weline\Server\Service\Control\HybridControlPlaneServer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Telemetry\InMemoryMetricsAggregator;
use Weline\Server\Service\Telemetry\IpcTelemetryGateway;
use Weline\Server\Service\Provider\WorkerProvider;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;

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
    private const STOP_STAGE_IDLE = 'idle';
    private const STOP_STAGE_REQUESTED = 'requested';
    private const STOP_STAGE_DRAIN = 'drain';
    private const STOP_STAGE_WAIT_DRAIN = 'wait_drain';
    private const STOP_STAGE_SHUTDOWN = 'shutdown';
    private const STOP_STAGE_WAIT_EXIT = 'wait_exit';
    private const STOP_STAGE_VERIFY = 'verify';
    private const STOP_STAGE_CLOSE_IPC = 'close_ipc';
    private const STOP_STAGE_COMPLETE = 'complete';
    private const CONTROL_OPERATION_STATE_QUEUED = 'queued';
    private const CONTROL_OPERATION_STATE_RUNNING = 'running';
    private const CONTROL_OPERATION_STATE_ABORTING = 'aborting';
    private const CONTROL_OPERATION_STATE_COMPLETED = 'completed';
    private const CONTROL_OPERATION_STATE_CANCELLED = 'cancelled';
    private const READY_CONFIRM_TIMEOUT_SEC = 3.0;
    private const MIN_READY_TIMER_POLL_USEC = 1000;
    private const SLOT_GENERATIONS_KEY = 'slot_generations';
    private const STARTUP_PORT_PREFLIGHT_ROLES = [
        ControlMessage::ROLE_DISPATCHER => true,
        ControlMessage::ROLE_REDIRECT => true,
    ];

    private ServiceRegistry $registry;
    private ?ControlPlaneServerInterface $controlServer = null;
    private ?ServiceContext $context = null;

    private bool $running = false;
    private bool $shuttingDown = false;
    private bool $stopAllInProgress = false;
    private bool $childProcessStopInProgress = false;
    private ?string $pendingStopReason = null;
    private bool $pendingStopSkipDrain = false;
    private bool $stopAllSkipDrain = false;
    private bool $bulkLaunchPortCheckActive = false;
    private ?string $startupFailureReason = null;
    private ?string $lastControlServerCloseReason = null;
    private ?int $pendingStopProgressClientId = null;
    private string $stopStage = self::STOP_STAGE_IDLE;
    private ?int $stopProgressClientId = null;
    /** 当前 STOP 会话追踪 ID（回写 CLI command_result.msg_id / stop.trace.jsonl） */
    private string $activeStopTraceId = '';

    /** ANSI 颜色常量 */
    private const ANSI_RESET = "\033[0m";
    private const ANSI_BLUE = "\033[34m";
    private const ANSI_CYAN = "\033[36m";
    private const ANSI_GREEN = "\033[32m";
    private const ANSI_YELLOW = "\033[33m";
    private const ANSI_RED = "\033[31m";
    private const ANSI_ORANGE = "\033[38;5;208m";
    private const ANSI_BOLD = "\033[1m";
    private const ANSI_BRIGHT_CYAN = "\033[96m";
    private const ANSI_BRIGHT_GREEN = "\033[92m";
    private const ANSI_BRIGHT_ORANGE = "\033[38;5;214m";
    private float $lastHealthCheck = 0;
    private float $lastTickMainLoopSlowWarningAt = 0.0;
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
    private string $lastDispatcherRouteTableSignature = '__unpublished__';
    private int $routeTableVersion = 0;

    /**
     * B-i 阶段：最近一次成功发布的版本化路由表快照。
     *
     * key: "{role}:{epoch}"，避免不同 role / 不同 epoch 之间互相覆盖。
     * value:
     *   route_version: int
     *   checksum: string
     *   ports: int[]
     *   workers_count: int
     *   published_at: float (microtime)
     *
     * 仅用作幂等去重 + 观测；B-i 不参与路由源切换。
     *
     * @var array<string, array{route_version:int,checksum:string,ports:array<int,int>,workers_count:int,published_at:float}>
     */
    private array $lastDispatcherRouteTablePublish = [];
    private int $registerTimeoutCount = 0;
    private int $fullRestartCount = 0;
    private int $lastSweepKilled = 0;
    private int $lastSweepStalePidFiles = 0;
    /** @var array<string,int> role => count */
    private array $desiredState = [];
    /** @var array<string,int> slot_id => highest leased generation */
    private array $slotGenerationFloor = [];

    /** @var array<string, array{role: string, instanceId: int, maxRestarts: int, restartDelay: float}> 等待复活的实例 */
    private array $resurrectQueue = [];
    /** @var array<int, array{running: bool, checkedAt: float}> */
    private array $processRunningCache = [];
    private float $processRunningCacheTtlSec = 5.0;

    /**
     * 控制面排队操作。子进程协议消息（register/ready/disconnect 等）不进入该队列。
     *
     * @var list<array{
     *     id:string,
     *     action:string,
     *     clientId:int,
     *     payload:array<string, mixed>,
     *     state:string,
     *     queuedAt:float,
     *     startedAt:?float
     * }>
     */
    private array $pendingControlOperations = [];

    /**
     * 当前唯一活跃的控制面操作。
     *
     * @var array{
     *     id:string,
     *     action:string,
     *     clientId:int,
     *     payload:array<string, mixed>,
     *     state:string,
     *     queuedAt:float,
     *     startedAt:?float
     * }|null
     */
    private ?array $activeControlOperation = null;

    private int $nextControlOperationId = 1;

    /** 启动时等待服务就绪的超时时间（秒） */
    private float $startupTimeout = 30.0;

    /** 关闭时等待服务排水的超时时间（秒）- Windows 上通常较快 */
    private float $drainTimeout = 5.0;

    /** IPC 断线后的重连宽限期（秒），避免旧进程仍存活时被过早复活 */
    private float $ipcReconnectGraceSec = 8.0;

    /** 维护模式是否激活 */
    private bool $maintenanceMode = false;

    /** 是否为显式维护态（配置/命令开启），禁止在业务 Worker READY 后自动退出 */
    private bool $maintenanceSticky = false;

    /**
     * 等待维护态切换 ACK：业务 Worker set_maintenance_mode 或 Dispatcher 维护池切换。
     *
     * @var array{request_id: string, expected: array<int|string, true>, acked: array<int|string, true>, kind?: string}|null
     */
    private ?array $pendingMaintenanceModeAck = null;
    private float $lastMaintenanceOperationLogAt = 0.0;
    private string $lastMaintenanceOperationSignature = '';

    /**
     * Master 已发起停机（SIGTERM/Ctrl+C/stop）：在 IPC 停机流程真正执行 stopAll 前，
     * Worker 可能已先断开，须禁止存活审计里再复活/紧急拉起，否则会与停机争抢。
     */
    private bool $masterShutdownIntent = false;

    /**
     * 帝王 IPC 指令独占：非 null 时拒绝其它 CLI 控制类指令（register/ready/drain/shutdown 等子进程协议不受影响）。
     */
    private ?string $ipcExclusiveCommand = null;

    private ?int $ipcExclusiveClientId = null;

    /** 每次新帝王指令或清场抢占时递增，用于打断嵌套 poll 中尚未结束的重载循环 */
    private int $ipcImperialEpoch = 0;

    /** 整组重启完成后短时间内跳过 Worker 紧急拉起（避免子进程尚在注册时误判「全死」） */
    private float $suppressWorkerEmergencyUntil = 0.0;

    /** 滚动重启是否正在进行 */
    private bool $rollingRestartInProgress = false;

    /** 滚动重启等待结果的 CLI 客户端 ID */
    private ?int $rollingRestartClientId = null;

    /**
     * reload_wait（se:rel 等待模式）是否已向 CLI 发送过终态事件（reload_completed / reload_failed）。
     * 用于避免分批重载因帝王抢占、提前 return 等路径未回执导致 CLI 永久挂起。
     */
    private bool $reloadWaitTerminalEventSent = false;

    /** 滚动重启进度（已完成的 Worker 数量） */
    private int $rollingRestartProgress = 0;

    /** 滚动重启总数（需要重启的 Worker 总数） */
    private int $rollingRestartTotal = 0;

    /** 启动完成时间戳（用于启动后冷却期） */
    private float $startAllCompletedAt = 0.0;

    /** 启动后冷却期（秒）- 在此期间忽略 reload_all:code 请求，避免 FileWatcher 误触发 */
    private float $startupReloadCooldown = 10.0;

    /** 总启动超时时间（秒）- 超过此时间未完成启动则强制退出 Master */
    private float $startupMaxDuration = 120.0;

    /** 子服务启动截止绝对时间戳（用于计算总启动时间） */
    private float $childServicesStartupDeadline = 0.0;

    /** 启动确认是否已完成：计划内进程均已上报 READY。只有完成后才能启动健康检查和拉起逻辑 */
    private bool $startupAcceptanceComplete = false;
    private float $sharedConsumerRenewIntervalSec = 10.0;
    private float $lastSharedConsumerRenewAt = 0.0;
    private bool $sharedConsumerRenewLogged = false;
    /** 服务实例信息持久化节流：上次落盘时间 */
    private float $lastPersistServicesInfoAt = 0.0;
    /** 服务实例信息持久化节流：最小落盘间隔（秒） */
    private float $persistServicesInfoMinIntervalSec = 0.25;
    /** 服务实例信息是否有待落盘变更 */
    private bool $persistServicesInfoDirty = false;
    /** 最近一次持久化请求上下文 */
    private ?ServiceContext $persistServicesInfoContext = null;
    /** ROUTING_POLICY 广播节流：上次广播时间 */
    private float $lastRoutingPolicyBroadcastAt = 0.0;
    /** ROUTING_POLICY 广播节流：上次策略摘要 */
    private string $lastRoutingPolicyBroadcastDigest = '';
    /** ROUTING_POLICY 是否有待广播变更 */
    private bool $routingPolicyBroadcastPending = false;
    /** ROUTING_POLICY 广播最小间隔（秒） */
    private float $routingPolicyBroadcastMinIntervalSec = 0.20;

    /**
     * 待聚合的 Fiber 池统计请求（CLI 请求 fiber_stats 后等待各 Worker 回复）
     * @var array{replyClientId: int, request_id: string, waiting: array<int, true>, replies: array<int, array>}|null
     */
    private ?array $pendingFiberStatsRequest = null;

    private ?FiberScheduler $mainLoopFiberScheduler = null;

    /**
     * @var array<string, array{fiber:\Fiber,label:string,startedAt:float}>
     */
    private array $mainLoopTasks = [];

    /** 是否已输出"服务器准备就绪"通知 */
    private bool $serverReadyNotified = false;

    /** 仅当本轮启动已完成所有计划实例投递后，才允许输出 ready 通知 */
    private bool $serverReadyNotificationArmed = false;

    /**
     * startAllChildServices（含 runLoop 内 Fiber 延迟启动）执行期间为 true：
     * 周期任务不得因「desired 已写入但 Worker 尚未注册」而补齐槽位或紧急拉起。
     */
    private bool $childServicesBootstrapInProgress = false;

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

    /** 关键服务 IPC 断开后标记「端点不可用」，由 IPC 事件驱动更新 */
    private array $infraDegraded = [];

    /**
     * Temporarily bypass control-operation preemption so critical sidecar
     * recovery can progress during worker readiness gating.
     */
    private bool $suspendControlPreemption = false;

    /** Session/Memory 断开后本地复活最大尝试次数（均失败再整组重启） */
    private int $infraServiceResurrectAttempts = 3;

    /** 核心角色：这些角色 IPC 断开直接整组重启 */
    /** @var array<string, true> */
    private array $criticalRoles = [];

    /** 按角色的最近断开记录（用于 escalation 计数） */
    /** @var array<string, array{count: int, windowStart: float}> */
    private array $escalationDisconnects = [];

    private float $workerLivenessIntervalSec = 8.0;
    private float $lastWorkerLivenessAt = 0.0;
    private bool $workerEmergencyRestartEnabled = true;
    private bool $reconcileWorkersWithoutHa = true;
    private float $lastEmergencyWorkerRestartAt = 0.0;
    private float $workerEmergencyCooldownSec = 20.0;
    private float $lastWorkerSlotReconcileAt = 0.0;

    /** Master 自检间隔（秒），0=关闭 */
    private float $masterSelfAuditIntervalSec = 0.0;

    private float $lastMasterSelfAuditAt = 0.0;

    /** 遥测同类异常节流：key => 上次 error 时间 */
    private array $telemetryAnomalyLoggedAt = [];

    /** Worker 不齐时遥测 5xx 触发的补齐节流（仅进程已挂/缺槽时生效） */
    private array $telemetryWorkerRecoveryAt = [];
    private array $dispatcherAlertRecoveryAt = [];
    private ?IpcTelemetryGateway $telemetryGateway = null;
    private ?InMemoryMetricsAggregator $metricsAggregator = null;
    /** @var array<string, true> */
    private array $loadedProviderFiles = [];

    /** 批量协调管理器 */
    private ?BatchManager $batchManager = null;

    private function cooperativeYieldIfNeeded(float &$lastYieldAt, float $sliceMs = 20.0): void
    {
        $now = \microtime(true);
        if ((($now - $lastYieldAt) * 1000) < $sliceMs) {
            return;
        }
        $lastYieldAt = $now;
        SchedulerSystem::yield();
    }

    private function clearStaleIpcClientIfNeeded(ServiceInstance $instance): bool
    {
        if ($instance->ipcClientId === null || $this->controlServer === null) {
            return false;
        }

        if ($this->controlServer->clientExists($instance->ipcClientId)) {
            return false;
        }

        WlsLogger::warning_(
            "[Orchestrator] 检测到失效 IPC 槽位: {$instance->role}#{$instance->instanceId} "
            . "(client_id={$instance->ipcClientId})，按已断开处理"
        );
        $instance->ipcClientId = null;
        $this->registry->updateInstance($instance);

        return true;
    }

    public function __construct()
    {
        $this->registry = new ServiceRegistry();
        $this->batchManager = new BatchManager();
    }

    /**
     * 获取批量管理器
     */
    public function getBatchManager(): BatchManager
    {
        return $this->batchManager;
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
    public function getControlServer(): ?ControlPlaneServerInterface
    {
        return $this->controlServer;
    }

    protected function createControlServer(): ControlPlaneServerInterface
    {
        $supervisorEnabledEnvRaw = \getenv('WLS_SUPERVISOR_ENABLED');
        $supervisorEnabledEnv = $supervisorEnabledEnvRaw !== false
            && $supervisorEnabledEnvRaw !== ''
            && \in_array(\strtolower((string) $supervisorEnabledEnvRaw), ['1', 'true', 'yes', 'on'], true);
        $supervisorEnabled = (bool) ($this->context?->getConfig('wls.supervisor.enabled', $supervisorEnabledEnv) ?? $supervisorEnabledEnv);
        $channelId = (string) ($this->context?->getConfig('wls.supervisor.channel', \getenv('WLS_SUPERVISOR_CHANNEL') ?: '') ?? '');
        $basePath = (string) ($this->context?->getConfig('wls.supervisor.base_path', \getenv('WLS_SUPERVISOR_BASE_PATH') ?: BP) ?? BP);

        return new HybridControlPlaneServer(
            controlServer: new MasterControlServer(),
            endpointResolver: new ControlEndpointResolver($basePath),
            supervisorEnabled: $supervisorEnabled,
            channelId: $channelId !== '' ? $channelId : null,
        );
    }

    public function describeLifecycleState(): string
    {
        return 'running=' . ($this->running ? '1' : '0')
            . ', shutting_down=' . ($this->shuttingDown ? '1' : '0')
            . ', stop_all=' . ($this->stopAllInProgress ? '1' : '0')
            . ', pending_stop=' . ($this->pendingStopReason ?? 'null')
            . ', child_stop=' . ($this->childProcessStopInProgress ? '1' : '0')
            . ', full_restart=' . ($this->fullRestartRequested ? '1' : '0')
            . ', main_loop_tasks=' . \count($this->mainLoopTasks)
            . ', startup_failure=' . ($this->startupFailureReason ?? 'null')
            . ', control_server_close_reason=' . ($this->lastControlServerCloseReason ?? 'null');
    }

    private function isStopFlowActive(): bool
    {
        return $this->masterShutdownIntent
            || $this->pendingStopReason !== null
            || $this->stopAllInProgress
            || $this->shuttingDown;
    }

    private function isRecoverySuspended(): bool
    {
        return $this->isStopFlowActive()
            || $this->fullRestartRequested
            || $this->childProcessStopInProgress;
    }

    private function setStopStage(string $stage): void
    {
        $this->stopStage = $stage;
    }

    private function sendStopAlreadyInProgress(?int $clientId = null): void
    {
        if ($clientId === null || $this->controlServer === null) {
            return;
        }

        $this->controlServer->sendTo(
            $clientId,
            ControlMessage::commandResult(
                true,
                ['state' => 'stopping', 'stage' => $this->stopStage],
                'Stop already in progress'
            )
        );
    }

    public function requestStop(
        string $reason = 'shutdown',
        ?int $progressClientId = null,
        bool $exclusiveIpc = false,
        bool $skipDrain = false,
        string $msgId = ''
    ): bool
    {
        if ($this->stopAllInProgress || $this->shuttingDown || $this->pendingStopReason !== null) {
            $this->sendStopAlreadyInProgress($progressClientId);
            WlsLogger::warning_(
                "[Orchestrator] 停机流程已在进行中，忽略重复 stop 请求，原因: {$reason}，阶段: {$this->stopStage}"
            );
            return false;
        }

        $this->masterShutdownIntent = true;
        $this->fullRestartRequested = false;
        $this->fullRestartReason = '';
        $this->resurrectQueue = [];
        $this->rollingRestartInProgress = false;
        $this->rollingRestartClientId = null;
        $this->rollingRestartProgress = 0;
        $this->rollingRestartTotal = 0;

        if ($exclusiveIpc && $progressClientId !== null) {
            $this->ipcClearFieldForNewImperial($progressClientId, ControlMessage::ACTION_STOP);
        } else {
            $this->ipcImperialEpoch++;
            $this->ipcExclusiveCommand = ControlMessage::ACTION_STOP;
            $this->ipcExclusiveClientId = $progressClientId;
            WlsLogger::warning_(
                "[Orchestrator] 本地停机请求已接管控制面，原因: {$reason} imperial_epoch={$this->ipcImperialEpoch}"
            );
        }

        $this->pendingStopReason = $reason;
        $this->pendingStopSkipDrain = $skipDrain;
        $this->pendingStopProgressClientId = $progressClientId;
        $this->setStopStage(self::STOP_STAGE_REQUESTED);

        $trace = $msgId !== '' ? $msgId : ('stop-' . \getmypid() . '-' . \time());
        $this->activeStopTraceId = $trace;
        $this->appendStopTraceLine('accepted', ['reason' => $reason, 'skip_drain' => $skipDrain]);

        if ($progressClientId !== null && $this->controlServer !== null) {
            $sent = $this->controlServer->sendTo(
                $progressClientId,
                ControlMessage::commandResult(
                    true,
                    ['state' => 'stopping', 'stage' => $this->stopStage, 'stop_trace_id' => $trace],
                    'Stopping',
                    $trace
                )
            );
            if (!$sent) {
                WlsLogger::warning_("[Orchestrator] STOP ACK 发送失败 client={$progressClientId}");
            }
            $this->appendStopTraceLine('ack_sent', ['client_id' => $progressClientId, 'ok' => $sent]);
        }

        WlsLogger::info_("[Orchestrator] 已接收停止请求，立即进入统一停机流程，原因: {$reason}");
        $this->consumePendingStopRequest();
        return true;
    }

    private function handleStartupFailure(\Throwable $throwable, string $label): void
    {
        $reason = $label !== '' ? "{$label}: " . $throwable->getMessage() : $throwable->getMessage();
        $this->startupFailureReason = $reason;
        WlsLogger::error_("[Orchestrator] {$label}: " . $throwable->getMessage(), ['exception' => $throwable]);
        if ($this->context !== null) {
            $this->persistStartupFailureToInstance(
                $this->context,
                $reason,
                $this->collectStartupFailurePendingRoleLabels()
            );
        }

        if ($this->running || $this->controlServer !== null) {
            $requested = $this->requestStop('startup_failure');
            WlsLogger::warning_(
                '[Orchestrator] startup failure handed over to unified stop flow'
                . ', requested=' . ($requested ? '1' : '0')
                . ', label=' . $label
                . ', ' . $this->describeLifecycleState()
            );
            return;
        }

        $this->running = false;
    }

    private function consumePendingStopRequest(): bool
    {
        if ($this->pendingStopReason === null || $this->shuttingDown || $this->stopAllInProgress) {
            return false;
        }

        $reason = $this->pendingStopReason;
        $progressClientId = $this->pendingStopProgressClientId;
        $skipDrain = $this->pendingStopSkipDrain;
        $this->pendingStopReason = null;
        $this->pendingStopSkipDrain = false;
        $this->pendingStopProgressClientId = null;
        if ($this->hasMainLoopTask('control:stop_all')) {
            return true;
        }

        if (!$this->scheduleMainLoopTask('control:stop_all', 'stop_all', function () use ($reason, $progressClientId, $skipDrain): void {
            $previousSkipDrain = $this->stopAllSkipDrain;
            $this->stopAllSkipDrain = $skipDrain;
            try {
                $this->stopAll($reason, $progressClientId);
            } catch (\Throwable $throwable) {
                WlsLogger::error_(
                    '[Orchestrator] stopAll 执行异常，将重置停机状态并强制退出: ' . $throwable->getMessage(),
                    ['exception' => $throwable]
                );
                $this->resetStopFlowFlagsAfterStopAllFailure();
                $this->forceTerminateMasterAndChildren('stop_all_exception:' . $reason);
            } finally {
                $this->stopAllSkipDrain = $previousSkipDrain;
            }
        })) {
            $this->pendingStopReason = $reason;
            $this->pendingStopSkipDrain = $skipDrain;
            $this->pendingStopProgressClientId = $progressClientId;
            return false;
        }

        return true;
    }

    private function isQueuedControlCommand(string $action): bool
    {
        return \in_array($action, [
            ControlMessage::ACTION_RELOAD,
            ControlMessage::ACTION_RELOAD_WAIT,
            ControlMessage::ACTION_STOP,
            ControlMessage::ACTION_CACHE_CLEAR,
            ControlMessage::ACTION_ROUTING_CACHE_CLEAR,
            ControlMessage::ACTION_PAGEBUILDER_PAGE_INVALIDATE,
            ControlMessage::ACTION_SSL_CERT_RELOAD,
            ControlMessage::ACTION_MAINTENANCE_ENABLE,
            ControlMessage::ACTION_MAINTENANCE_DISABLE,
            ControlMessage::ACTION_ROLLING_RESTART,
            ControlMessage::ACTION_SECURITY_UNBLOCK,
            ControlMessage::ACTION_FIBER_SET_CONFIG,
            ControlMessage::ACTION_FIBER_RELEASE_IDLE,
        ], true);
    }

    private function isImperialControlCommand(string $action): bool
    {
        return \in_array($action, [
            ControlMessage::ACTION_STOP,
            ControlMessage::ACTION_RELOAD,
            ControlMessage::ACTION_RELOAD_WAIT,
            ControlMessage::ACTION_MAINTENANCE_ENABLE,
            ControlMessage::ACTION_MAINTENANCE_DISABLE,
            ControlMessage::ACTION_ROLLING_RESTART,
        ], true);
    }

    private function isMaintenanceControlAction(string $action): bool
    {
        return $action === ControlMessage::ACTION_MAINTENANCE_ENABLE
            || $action === ControlMessage::ACTION_MAINTENANCE_DISABLE;
    }

    private function getOppositeMaintenanceAction(string $action): ?string
    {
        return match ($action) {
            ControlMessage::ACTION_MAINTENANCE_ENABLE => ControlMessage::ACTION_MAINTENANCE_DISABLE,
            ControlMessage::ACTION_MAINTENANCE_DISABLE => ControlMessage::ACTION_MAINTENANCE_ENABLE,
            default => null,
        };
    }

    private function isMaintenanceActionAlreadySatisfied(string $action): bool
    {
        return match ($action) {
            ControlMessage::ACTION_MAINTENANCE_ENABLE => $this->maintenanceMode && $this->maintenanceSticky,
            ControlMessage::ACTION_MAINTENANCE_DISABLE => !$this->maintenanceMode,
            default => false,
        };
    }

    private function dropQueuedControlOperationsByAction(string $action, string $reason): int
    {
        if ($this->pendingControlOperations === []) {
            return 0;
        }

        $removed = 0;
        $kept = [];
        foreach ($this->pendingControlOperations as $operation) {
            if (($operation['action'] ?? '') !== $action) {
                $kept[] = $operation;
                continue;
            }

            $removed++;
            $this->controlServer?->sendTo(
                (int)$operation['clientId'],
                ControlMessage::commandResult(false, [
                    'operation_id' => $operation['id'],
                    'state' => self::CONTROL_OPERATION_STATE_CANCELLED,
                ], $reason)
            );
        }
        $this->pendingControlOperations = $kept;

        return $removed;
    }

    /**
     * @return array{id:string,action:string,clientId:int,payload:array<string,mixed>,state:string,queuedAt:float,startedAt:?float}|null
     */
    private function findEquivalentQueuedOrActiveOperation(string $action): ?array
    {
        if ($this->activeControlOperation !== null && $this->activeControlOperation['action'] === $action) {
            return $this->activeControlOperation;
        }

        foreach ($this->pendingControlOperations as $operation) {
            if (($operation['action'] ?? '') === $action) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $msg
     */
    private function queueControlOperation(string $action, array $msg, int $clientId): array
    {
        $operation = [
            'id' => 'ctrl_op_' . $this->nextControlOperationId++,
            'action' => $action,
            'clientId' => $clientId,
            'payload' => $msg,
            'state' => self::CONTROL_OPERATION_STATE_QUEUED,
            'queuedAt' => \microtime(true),
            'startedAt' => null,
        ];
        $this->pendingControlOperations[] = $operation;

        WlsLogger::info_(
            "[Orchestrator] 控制操作已入队 id={$operation['id']} action={$action} client={$clientId} pending="
            . \count($this->pendingControlOperations)
        );

        return $operation;
    }

    /**
     * @param array{id:string,action:string,clientId:int,payload:array<string,mixed>,state:string,queuedAt:float,startedAt:?float} $operation
     */
    private function getControlOperationQueuePosition(array $operation): int
    {
        $position = $this->getBlockingControlOperationQueueBase();
        foreach ($this->pendingControlOperations as $index => $queuedOperation) {
            if ($queuedOperation['id'] === $operation['id']) {
                return $position + $index + 1;
            }
        }

        return $position + 1;
    }

    private function getControlOperationQueuePositionById(string $operationId): int
    {
        if ($this->activeControlOperation !== null && $this->activeControlOperation['id'] === $operationId) {
            return 1;
        }

        $position = $this->getBlockingControlOperationQueueBase();
        foreach ($this->pendingControlOperations as $index => $queuedOperation) {
            if (($queuedOperation['id'] ?? '') === $operationId) {
                return $position + $index + 1;
            }
        }

        return $position + 1;
    }

    /**
     * @return array{id:string,action:string}|null
     */
    private function getCurrentControlOperationSummary(): ?array
    {
        if ($this->activeControlOperation !== null) {
            return [
                'id' => $this->activeControlOperation['id'],
                'action' => $this->activeControlOperation['action'],
            ];
        }

        if ($this->pendingStopReason !== null || $this->stopAllInProgress || $this->shuttingDown) {
            return [
                'id' => 'stop',
                'action' => ControlMessage::ACTION_STOP,
            ];
        }

        if ($this->fullRestartRequested) {
            return [
                'id' => 'full_restart',
                'action' => 'full_restart',
            ];
        }

        if ($this->ipcExclusiveCommand !== null) {
            return [
                'id' => 'exclusive_' . $this->ipcExclusiveCommand,
                'action' => $this->ipcExclusiveCommand,
            ];
        }

        return null;
    }

    private function getBlockingControlOperationQueueBase(): int
    {
        if ($this->activeControlOperation !== null) {
            return 1;
        }

        return $this->ipcExclusiveCommand === null ? 0 : 1;
    }

    /**
     * @param array{id:string,action:string,clientId:int,payload:array<string,mixed>,state:string,queuedAt:float,startedAt:?float} $operation
     */
    private function sendQueuedControlOperationAck(array $operation): void
    {
        $queuePosition = $this->getControlOperationQueuePosition($operation);
        $current = $this->getCurrentControlOperationSummary();
        $message = $this->buildQueuedControlOperationAckMessage($operation['action']);
        $data = [
            'async' => true,
            'accepted' => true,
            'operation_id' => $operation['id'],
            'state' => self::CONTROL_OPERATION_STATE_QUEUED,
            'queue_position' => $queuePosition,
            'active_operation' => $current,
        ];

        $this->controlServer?->sendTo(
            $operation['clientId'],
            ControlMessage::commandResult(true, $data, $message, (string)($operation['payload']['msg_id'] ?? ''))
        );
    }

    /**
     * @param array{id:string,action:string,state:string} $operation
     */
    private function sendDeduplicatedControlOperationAck(int $clientId, array $operation): void
    {
        $queuePosition = $this->getControlOperationQueuePositionById($operation['id']);
        $current = $this->getCurrentControlOperationSummary();
        $message = $this->buildQueuedControlOperationAckMessage($operation['action']) . ' (deduplicated)';
        $data = [
            'async' => true,
            'accepted' => true,
            'deduplicated' => true,
            'operation_id' => $operation['id'],
            'state' => $operation['state'],
            'queue_position' => $queuePosition,
            'active_operation' => $current,
        ];

        $this->controlServer?->sendTo(
            $clientId,
            ControlMessage::commandResult(true, $data, $message, (string)($operation['payload']['msg_id'] ?? ''))
        );
    }

    private function buildQueuedControlOperationAckMessage(string $action): string
    {
        return match ($action) {
            ControlMessage::ACTION_RELOAD => 'Reload initiated',
            ControlMessage::ACTION_RELOAD_WAIT => 'Reload initiated',
            ControlMessage::ACTION_ROLLING_RESTART => 'Rolling restart initiated',
            ControlMessage::ACTION_STOP => 'Stopping',
            ControlMessage::ACTION_MAINTENANCE_ENABLE => 'Maintenance enable queued',
            ControlMessage::ACTION_MAINTENANCE_DISABLE => 'Maintenance disable queued',
            ControlMessage::ACTION_CACHE_CLEAR => 'Cache clear queued',
            ControlMessage::ACTION_ROUTING_CACHE_CLEAR => 'Routing cache clear queued',
            ControlMessage::ACTION_PAGEBUILDER_PAGE_INVALIDATE => 'PageBuilder page invalidate queued',
            ControlMessage::ACTION_SSL_CERT_RELOAD => 'SSL cert reload queued',
            ControlMessage::ACTION_SECURITY_UNBLOCK => 'Security unblock queued',
            ControlMessage::ACTION_FIBER_SET_CONFIG => 'Fiber config update queued',
            ControlMessage::ACTION_FIBER_RELEASE_IDLE => 'Fiber release queued',
            default => 'Control operation queued',
        };
    }

    private function initializeMainLoopFiberScheduler(): void
    {
        $this->mainLoopFiberScheduler = new FiberScheduler();
        $this->mainLoopTasks = [];
        SchedulerWaitObserver::setScheduler($this->mainLoopFiberScheduler);
        SchedulerSystem::enableScheduler();
    }

    private function resetMainLoopFiberScheduler(): void
    {
        $this->mainLoopTasks = [];
        if ($this->mainLoopFiberScheduler !== null) {
            $this->mainLoopFiberScheduler->reset();
            $this->mainLoopFiberScheduler = null;
        }
        SchedulerSystem::disableScheduler();
    }

    private function hasPendingMainLoopTasks(): bool
    {
        return $this->mainLoopTasks !== [];
    }

    private function hasMainLoopTask(string $key): bool
    {
        return isset($this->mainLoopTasks[$key]);
    }

    private function getMainLoopPollTimeoutUsec(int $defaultUsec = 100000): int
    {
        if ($this->mainLoopFiberScheduler === null) {
            return $defaultUsec;
        }

        $nextDelay = $this->mainLoopFiberScheduler->getNextTimerDelay();
        if ($nextDelay === null) {
            return $defaultUsec;
        }

        $delayUsec = (int) \ceil($nextDelay * 1000000);
        if ($delayUsec < self::MIN_READY_TIMER_POLL_USEC && $defaultUsec > 0) {
            $delayUsec = \min($defaultUsec, self::MIN_READY_TIMER_POLL_USEC);
        }

        return \max(0, \min($defaultUsec, $delayUsec));
    }

    private function getMainLoopTaskAgeSec(string $key, float $now): ?float
    {
        if (!isset($this->mainLoopTasks[$key])) {
            return null;
        }

        $startedAt = (float) ($this->mainLoopTasks[$key]['startedAt'] ?? 0.0);
        if ($startedAt <= 0.0) {
            return null;
        }

        return \max(0.0, $now - $startedAt);
    }

    private function cancelMainLoopTask(string $key, string $reason): bool
    {
        $task = $this->mainLoopTasks[$key] ?? null;
        if ($task === null) {
            return false;
        }

        $fiber = $task['fiber'] ?? null;
        if ($fiber instanceof \Fiber) {
            $this->mainLoopFiberScheduler?->cancelTimersForFiber($fiber);
        }

        $this->mainLoopFiberScheduler?->unregisterFiber();
        unset($this->mainLoopTasks[$key]);

        $label = (string) ($task['label'] ?? $key);
        WlsLogger::warning_("[Orchestrator] 主循环任务 {$label} 卡住，已取消：{$reason}");

        return true;
    }

    private function guardResurrectQueueTasks(float $now): void
    {
        if ($this->resurrectQueue === []) {
            return;
        }

        $queueTaskStallSec = 15.0;
        $launchTaskStallSec = \max(30.0, $queueTaskStallSec * 2.0);
        if ($this->context !== null) {
            $queueTaskStallSec = (float) $this->context->getConfig(
                'wls.orchestrator.resurrect_queue_task_stall_sec',
                $queueTaskStallSec
            );
            if ($queueTaskStallSec < 3.0) {
                $queueTaskStallSec = 3.0;
            }

            $launchTaskStallSec = (float) $this->context->getConfig(
                'wls.orchestrator.resurrect_launch_task_stall_sec',
                \max(30.0, $queueTaskStallSec * 2.0)
            );
        }
        if ($launchTaskStallSec < $queueTaskStallSec) {
            $launchTaskStallSec = $queueTaskStallSec;
        }

        $resurrectQueueTaskAge = $this->getMainLoopTaskAgeSec('periodic:resurrect_queue', $now);
        if ($resurrectQueueTaskAge !== null && $resurrectQueueTaskAge >= $queueTaskStallSec) {
            $this->cancelMainLoopTask(
                'periodic:resurrect_queue',
                'resurrect queue stalled for ' . \sprintf('%.1f', $resurrectQueueTaskAge) . 's'
            );
        }

        foreach ($this->resurrectQueue as $key => $entry) {
            if (($entry['launching'] ?? false) !== true) {
                continue;
            }

            $taskKey = "resurrect_launch:{$key}";
            $taskAge = $this->getMainLoopTaskAgeSec($taskKey, $now);
            $launchingAt = (float) ($entry['launchingAt'] ?? 0.0);
            $launchAge = null;
            if ($launchingAt > 0.0) {
                $launchAge = \max(0.0, $now - $launchingAt);
            } elseif ($taskAge !== null) {
                $launchAge = $taskAge;
            } elseif (!isset($this->mainLoopTasks[$taskKey])) {
                $launchAge = $launchTaskStallSec;
            }

            if ($launchAge === null || $launchAge < $launchTaskStallSec) {
                continue;
            }

            $this->cancelMainLoopTask(
                $taskKey,
                'resurrect launch stalled for ' . $key . ' (' . \sprintf('%.1f', $launchAge) . 's)'
            );
            unset($entry['launching'], $entry['launchingAt']);
            $entry['restartDelay'] = 1.0;
            $entry['scheduledAt'] = $now + 1.0;
            $entry['delayed'] = false;
            $this->resurrectQueue[$key] = $entry;
            WlsLogger::warning_("[Orchestrator] 复活任务 {$key} 卡住，已重新入队等待 1.0s 后重试");
        }
    }

    private function scheduleMainLoopTask(string $key, string $label, callable $task): bool
    {
        if ($this->mainLoopFiberScheduler === null) {
            $this->initializeMainLoopFiberScheduler();
        }

        if (isset($this->mainLoopTasks[$key])) {
            return false;
        }

        $traceStartupTask = \str_starts_with($key, 'startup:');
        $fiber = new \Fiber(function () use ($task, $key, $label, $traceStartupTask): void {
            SchedulerSystem::yield();
            if ($traceStartupTask) {
                $this->traceStartup('main_loop_task_resume', [
                    'key' => $key,
                    'label' => $label,
                ]);
            }
            $task();
        });

        $this->mainLoopTasks[$key] = [
            'fiber' => $fiber,
            'label' => $label,
            'startedAt' => \microtime(true),
        ];
        $this->mainLoopFiberScheduler->registerFiber();
        if ($traceStartupTask) {
            $this->traceStartup('main_loop_task_scheduled', [
                'key' => $key,
                'label' => $label,
            ]);
        }
        if ($traceStartupTask) {
            WlsLogger::info_("[Orchestrator] scheduling main-loop startup task {$label}");
            WlsLogger::flush_(true);
        }

        try {
            $fiber->start();
            if ($traceStartupTask) {
                $this->traceStartup('main_loop_task_started', [
                    'key' => $key,
                    'label' => $label,
                    'suspended' => $fiber->isSuspended(),
                    'terminated' => $fiber->isTerminated(),
                ]);
                WlsLogger::info_(
                    "[Orchestrator] main-loop startup task {$label} started"
                    . ', suspended=' . ($fiber->isSuspended() ? '1' : '0')
                    . ', terminated=' . ($fiber->isTerminated() ? '1' : '0')
                );
                WlsLogger::flush_(true);
            }
        } catch (\Throwable $throwable) {
            $this->mainLoopFiberScheduler->unregisterFiber();
            unset($this->mainLoopTasks[$key]);
            WlsLogger::error_("[Orchestrator] failed to start main-loop fiber task {$label}: {$throwable->getMessage()}");

            return false;
        }

        if ($fiber->isTerminated()) {
            $this->cleanupMainLoopTask($key);
        }

        return true;
    }

    private function tickMainLoopTasks(): void
    {
        if ($this->mainLoopFiberScheduler === null) {
            return;
        }

        $budgetMs = (float) ($this->context?->getConfig('wls.orchestrator.fiber_tick_budget_ms', 50.0) ?? 50.0);
        if ($budgetMs < 1.0) {
            $budgetMs = 1.0;
        }
        $this->mainLoopFiberScheduler->tick(null, $budgetMs);

        foreach (\array_keys($this->mainLoopTasks) as $key) {
            if (!isset($this->mainLoopTasks[$key])) {
                continue;
            }

            $fiber = $this->mainLoopTasks[$key]['fiber'];
            if (!$fiber->isTerminated()) {
                continue;
            }

            try {
                $fiber->getReturn();
            } catch (\Throwable $throwable) {
                WlsLogger::error_(
                    "[Orchestrator] main-loop fiber task crashed {$this->mainLoopTasks[$key]['label']}: {$throwable->getMessage()}"
                );
            }

            $this->cleanupMainLoopTask($key);
        }
    }

    private function cleanupMainLoopTask(string $key): void
    {
        if (!isset($this->mainLoopTasks[$key])) {
            return;
        }

        $this->mainLoopFiberScheduler?->unregisterFiber();
        unset($this->mainLoopTasks[$key]);
    }

    private function cancelMainLoopTasksForMasterExit(): void
    {
        if ($this->mainLoopTasks === []) {
            return;
        }

        $currentFiber = \Fiber::getCurrent();
        foreach (\array_keys($this->mainLoopTasks) as $key) {
            $fiber = $this->mainLoopTasks[$key]['fiber'] ?? null;
            if ($currentFiber !== null && $fiber === $currentFiber) {
                continue;
            }

            if ($fiber instanceof \Fiber) {
                $this->mainLoopFiberScheduler?->cancelTimersForFiber($fiber);
            }
            $this->mainLoopFiberScheduler?->unregisterFiber();
            unset($this->mainLoopTasks[$key]);
        }
    }

    private function yieldControlPlane(int $pollUsec = 0): void
    {
        if ($this->controlServer !== null) {
            $this->controlServer->poll(0, \max(0, $pollUsec));
        }
        SchedulerSystem::yield();
    }

    /**
     * Lightweight startup trace independent from WlsLogger buffering.
     *
     * @param array<string, mixed> $data
     */
    private function traceStartup(string $event, array $data = []): void
    {
        if (!\defined('BP')) {
            return;
        }

        $line = [
            'ts' => \date('c'),
            'pid' => \getmypid(),
            'event' => $event,
        ];
        if ($this->context !== null) {
            $line['instance'] = $this->context->instanceName;
            $line['epoch'] = $this->context->epoch;
        }
        if ($data !== []) {
            $line['data'] = $data;
        }

        $path = BP . 'var' . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR . 'wls-startup-trace.log';
        @\file_put_contents(
            $path,
            \json_encode($line, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . \PHP_EOL,
            \FILE_APPEND | \LOCK_EX
        );
    }

    private function clearPendingControlOperations(string $reason): void
    {
        foreach ($this->pendingControlOperations as $operation) {
            $this->controlServer?->sendTo(
                $operation['clientId'],
                ControlMessage::commandResult(false, [
                    'operation_id' => $operation['id'],
                    'state' => self::CONTROL_OPERATION_STATE_CANCELLED,
                ], $reason)
            );
        }
        $this->pendingControlOperations = [];
    }

    private function preemptActiveControlOperationForImperial(string $incomingAction): void
    {
        if ($this->activeControlOperation === null) {
            return;
        }

        $this->activeControlOperation['state'] = self::CONTROL_OPERATION_STATE_ABORTING;
        WlsLogger::warning_(
            "[Orchestrator] 帝王指令={$incomingAction} 请求到达，标记活跃控制操作中止 id={$this->activeControlOperation['id']} action={$this->activeControlOperation['action']}"
        );
    }

    /**
     * @param array{id:string,action:string,clientId:int,payload:array<string,mixed>,state:string,queuedAt:float,startedAt:?float} $operation
     */
    private function isOperationExclusiveHolder(array $operation): bool
    {
        return $this->ipcExclusiveCommand !== null
            && $operation['action'] === $this->ipcExclusiveCommand
            && $this->ipcExclusiveClientId !== null
            && $operation['clientId'] === $this->ipcExclusiveClientId;
    }

    private function processNextQueuedControlOperation(): bool
    {
        if ($this->activeControlOperation !== null || $this->pendingControlOperations === []) {
            return false;
        }

        if ($this->ipcExclusiveCommand !== null) {
            $firstOperation = $this->pendingControlOperations[0] ?? null;
            if ($firstOperation === null || !$this->isOperationExclusiveHolder($firstOperation)) {
                return false;
            }
        }

        /** @var array{id:string,action:string,clientId:int,payload:array<string,mixed>,state:string,queuedAt:float,startedAt:?float} $operation */
        $operation = \array_shift($this->pendingControlOperations);
        $operation['state'] = self::CONTROL_OPERATION_STATE_RUNNING;
        $operation['startedAt'] = \microtime(true);
        $this->activeControlOperation = $operation;

        WlsLogger::info_(
            "[Orchestrator] 开始执行控制操作 id={$operation['id']} action={$operation['action']} client={$operation['clientId']}"
        );

        try {
            $this->executeQueuedControlOperation($operation);
            if ($this->activeControlOperation !== null
                && $this->activeControlOperation['id'] === $operation['id']
                && $this->activeControlOperation['state'] !== self::CONTROL_OPERATION_STATE_ABORTING) {
                $this->activeControlOperation['state'] = self::CONTROL_OPERATION_STATE_COMPLETED;
            }
        } catch (\Throwable $throwable) {
            WlsLogger::error_(
                "[Orchestrator] 控制操作执行异常 id={$operation['id']} action={$operation['action']} error={$throwable->getMessage()}"
            );
            $this->controlServer?->sendTo(
                $operation['clientId'],
                ControlMessage::commandResult(false, [
                    'operation_id' => $operation['id'],
                    'state' => 'failed',
                ], $throwable->getMessage())
            );
        } finally {
            if ($this->activeControlOperation !== null && $this->activeControlOperation['id'] === $operation['id']) {
                $this->activeControlOperation = null;
            }
        }

        return true;
    }

    /**
     * @param array{id:string,action:string,clientId:int,payload:array<string,mixed>,state:string,queuedAt:float,startedAt:?float} $operation
     */
    private function executeQueuedControlOperation(array $operation): void
    {
        $action = $operation['action'];
        $clientId = $operation['clientId'];
        $payload = $operation['payload'];
        try {
            switch ($action) {
            case ControlMessage::ACTION_RELOAD:
                $type = (string)($payload['reload_type'] ?? ControlMessage::RELOAD_TYPE_CODE);
                if ($type === ControlMessage::RELOAD_TYPE_CACHE) {
                    $this->broadcastCacheClear();
                    return;
                }
                // 与 reload_wait 一致：占帝王位并固定 imperial 快照，避免异步重载进行中因发起端断连等原因 bump epoch 导致整段重载被误判「抢占中止」、子进程收不到 DRAIN/SHUTDOWN。
                $this->ipcClearFieldForNewImperial($clientId, ControlMessage::ACTION_RELOAD);
                $snap = $this->ipcImperialEpoch;
                try {
                    $this->reloadAll($type, $snap);
                } finally {
                    if ($this->ipcImperialEpoch === $snap && $this->ipcExclusiveClientId === $clientId) {
                        $this->ipcReleaseExclusive();
                    }
                }
                return;

            case ControlMessage::ACTION_RELOAD_WAIT:
                $type = (string)($payload['reload_type'] ?? ControlMessage::RELOAD_TYPE_CODE);
                if ($type === ControlMessage::RELOAD_TYPE_CACHE) {
                    $this->ipcClearFieldForNewImperial($clientId, ControlMessage::ACTION_RELOAD_WAIT);
                    $snap = $this->ipcImperialEpoch;
                    $this->reloadWaitTerminalEventSent = false;
                    try {
                        $this->rollingRestartClientId = $clientId;
                        $this->broadcastCacheClear();
                        $this->sendReloadWaitTerminalOutcome(ControlMessage::reloadCompleted(0.0, 0));
                    } finally {
                        if (!$this->reloadWaitTerminalEventSent && $this->controlServer !== null) {
                            $this->controlServer->sendTo(
                                $clientId,
                                ControlMessage::reloadFailed(
                                    $this->translateMessage('缓存清理已执行，但未能向 CLI 发送完成回执')
                                )
                            );
                        }
                        $this->reloadWaitTerminalEventSent = false;
                        $this->rollingRestartClientId = null;
                        if ($this->ipcImperialEpoch === $snap && $this->ipcExclusiveClientId === $clientId) {
                            $this->ipcReleaseExclusive();
                        }
                    }

                    return;
                }
                $this->ipcClearFieldForNewImperial($clientId, ControlMessage::ACTION_RELOAD_WAIT);
                $snap = $this->ipcImperialEpoch;
                $this->reloadWaitTerminalEventSent = false;
                try {
                    $this->rollingRestartClientId = $clientId;
                    $this->reloadAll($type, $snap);
                } finally {
                    if (!$this->reloadWaitTerminalEventSent && $this->controlServer !== null) {
                        $this->controlServer->sendTo(
                            $clientId,
                            ControlMessage::reloadFailed(
                                (string) __(
                                    '重载未正常结束：未收到完整回执（可能因 IPC 抢占中止、内部提前返回或控制连接异常）'
                                )
                            )
                        );
                    }
                    $this->reloadWaitTerminalEventSent = false;
                    $this->rollingRestartClientId = null;
                    if ($this->ipcImperialEpoch === $snap && $this->ipcExclusiveClientId === $clientId) {
                        $this->ipcReleaseExclusive();
                    }
                }
                return;

            case ControlMessage::ACTION_CACHE_CLEAR:
                $this->broadcastCacheClear();
                return;

            case ControlMessage::ACTION_ROUTING_CACHE_CLEAR:
                $this->broadcastCacheClear();
                return;

            case ControlMessage::ACTION_PAGEBUILDER_PAGE_INVALIDATE:
                $this->broadcastPageBuilderPageInvalidate(
                    (int)($payload['website_id'] ?? 0),
                    (string)($payload['handle'] ?? ''),
                    (bool)($payload['is_home_page'] ?? false)
                );
                return;

            case ControlMessage::ACTION_SSL_CERT_RELOAD:
                $domains = isset($payload['domains']) && \is_array($payload['domains']) ? $payload['domains'] : [];
                $this->broadcastSslCertReload($domains);
                return;

            case ControlMessage::ACTION_MAINTENANCE_ENABLE:
                $this->ipcClearFieldForNewImperial($clientId, ControlMessage::ACTION_MAINTENANCE_ENABLE);
                $snap = $this->ipcImperialEpoch;
                try {
                    if (!empty($payload['dispatcher_only'])) {
                        $this->setDispatcherMaintenanceRouting(true);
                    } else {
                        $this->enableMaintenanceMode(true);
                    }
                } finally {
                    if ($this->ipcImperialEpoch === $snap && $this->ipcExclusiveClientId === $clientId) {
                        $this->ipcReleaseExclusive();
                    }
                }
                return;

            case ControlMessage::ACTION_MAINTENANCE_DISABLE:
                $this->ipcClearFieldForNewImperial($clientId, ControlMessage::ACTION_MAINTENANCE_DISABLE);
                $snap = $this->ipcImperialEpoch;
                try {
                    if (!empty($payload['dispatcher_only'])) {
                        $this->setDispatcherMaintenanceRouting(false);
                    } else {
                        $this->disableMaintenanceMode();
                    }
                } finally {
                    if ($this->ipcImperialEpoch === $snap && $this->ipcExclusiveClientId === $clientId) {
                        $this->ipcReleaseExclusive();
                    }
                }
                return;

            case ControlMessage::ACTION_ROLLING_RESTART:
                $this->ipcClearFieldForNewImperial($clientId, ControlMessage::ACTION_ROLLING_RESTART);
                $snap = $this->ipcImperialEpoch;
                try {
                    $this->startRollingRestart($clientId);
                } finally {
                    if ($this->ipcImperialEpoch === $snap && $this->ipcExclusiveClientId === $clientId) {
                        $this->ipcReleaseExclusive();
                    }
                }
                return;

            case ControlMessage::ACTION_SECURITY_UNBLOCK:
                $ip = $payload['ip'] ?? null;
                $clearAll = !empty($payload['clear_all']);
                $dispatchers = $this->registry->getInstancesByRole('dispatcher');
                foreach ($dispatchers as $dispatcher) {
                    if ($dispatcher->ipcClientId !== null && $this->controlServer !== null) {
                        $this->controlServer->sendTo(
                            $dispatcher->ipcClientId,
                            ControlMessage::securityUnblock($ip !== null && $ip !== '' ? $ip : null, $clearAll)
                        );
                    }
                }
                return;

            case ControlMessage::ACTION_FIBER_SET_CONFIG:
                $idleTtlSec = (int)($payload['idle_ttl_sec'] ?? 0);
                $maxActive = (int)($payload['max_active'] ?? 0);
                foreach ($this->getFiberEligibleInstances() as $instance) {
                    if ($instance->ipcClientId !== null && $this->controlServer !== null) {
                        $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::fiberSetConfig($idleTtlSec, $maxActive));
                    }
                }
                return;

            case ControlMessage::ACTION_FIBER_RELEASE_IDLE:
                foreach ($this->getFiberEligibleInstances() as $instance) {
                    if ($instance->ipcClientId !== null && $this->controlServer !== null) {
                        $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::fiberReleaseIdle());
                    }
                }
                return;
            }
        } finally {
        }
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
            Provider\WorkerProvider::class,
            Provider\DispatcherProvider::class,
            Provider\HttpRedirectProvider::class,
            Provider\MaintenanceWorkerProvider::class,
            Provider\GatewayProvider::class,
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

                if ($this->isSharedStateProviderRole($provider->getRole())) {
                    WlsLogger::debug_("[Orchestrator] 共享状态 Provider 由 SharedStateServiceManager 管理，跳过本地注册: {$provider->getRole()} ({$provider->getDisplayName()})");
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
    public function bootstrapControlPlane(ServiceContext $context): void
    {
        if ($this->controlServer !== null && $this->context !== null) {
            return;
        }

        // P2 观测性埋点：记录控制面 bootstrap 的起始时间，
        // 在方法最终 return 之前（见文件内对应 observe 调用）上报 `orchestrator.bootstrap_control_plane.ms`。
        // 不包 try/finally 是为了避免把这个超长方法整体内嵌一层，保持 diff 最小、调试栈帧不变形；
        // 若 bootstrap 抛异常，本样本缺失不会影响业务（Master 启动失败本身已由 log 强信号告警）。
        $bootstrapStartNs = \hrtime(true);

        $this->context = $context;
        $this->running = true;
        $this->shuttingDown = false;
        $this->stopAllInProgress = false;
        $this->pendingStopReason = null;
        $this->pendingStopSkipDrain = false;
        $this->stopAllSkipDrain = false;
        $this->pendingStopProgressClientId = null;
        $this->stopStage = self::STOP_STAGE_IDLE;
        $this->masterShutdownIntent = false;
        $this->startupFailureReason = null;
        $this->lastControlServerCloseReason = null;
        $this->suppressWorkerEmergencyUntil = 0.0;
        $this->ipcExclusiveCommand = null;
        $this->ipcExclusiveClientId = null;
        $this->ipcImperialEpoch = 0;
        $this->pendingMaintenanceModeAck = null;
        $this->resetServerReadyNotificationState();
        $this->initializeMainLoopFiberScheduler();
        $this->haMode = (bool)$context->getConfig('wls.orchestrator.ha_mode', true);
        $this->fullRestartOnFailure = (bool)$context->getConfig('wls.orchestrator.full_restart_on_failure', true);
        $this->fullRestartCooldown = (float)$context->getConfig('wls.orchestrator.restart_cooldown_sec', 10.0);
        $this->registerTimeout = (float)$context->getConfig('wls.orchestrator.register_timeout_sec', $this->startupGracePeriod);
        $this->startupMaxDuration = (float)$context->getConfig('wls.orchestrator.startup_max_duration_sec', $this->startupMaxDuration);
        $this->ipcReconnectGraceSec = (float)$context->getConfig('wls.orchestrator.ipc_reconnect_grace_sec', 8.0);
        $this->reconcileInterval = (float)$context->getConfig('wls.orchestrator.reconcile_interval_sec', 5.0);
        $this->sweeperInterval = (float)$context->getConfig('wls.orchestrator.sweeper_interval_sec', 15.0);
        $this->periodicOrphanSweepEnabled = (bool)$context->getConfig('wls.orchestrator.periodic_orphan_sweep', false);
        $this->singleRestartFirst = (bool)$context->getConfig('wls.orchestrator.single_restart_first', true);
        $this->escalationWindowSec = (float)$context->getConfig('wls.orchestrator.escalation_window_sec', 60.0);
        $this->escalationThreshold = (int)$context->getConfig('wls.orchestrator.escalation_threshold', 3);
        $this->stabilizationSec = (float)$context->getConfig('wls.orchestrator.stabilization_sec', 15.0);
        $this->workerLivenessIntervalSec = (float)$context->getConfig('wls.orchestrator.worker_liveness_interval_sec', 8.0);
        $this->workerEmergencyRestartEnabled = (bool)$context->getConfig('wls.orchestrator.worker_emergency_restart', true);
        $this->reconcileWorkersWithoutHa = (bool)$context->getConfig('wls.orchestrator.reconcile_workers_without_ha', true);
        $this->drainTimeout = (float) ($context->getConfig('wls.orchestrator.drain_timeout_sec', 5.0) ?? 5.0);
        if ($this->drainTimeout < 1.0) {
            $this->drainTimeout = 1.0;
        }
        if ($this->drainTimeout > 60.0) {
            $this->drainTimeout = 60.0;
        }
        $this->workerEmergencyCooldownSec = (float)$context->getConfig('wls.orchestrator.worker_emergency_cooldown_sec', 20.0);
        $this->masterSelfAuditIntervalSec = (float) ($context->getConfig('wls.orchestrator.master_self_audit_interval_sec', 20.0) ?? 20.0);
        $this->lastMasterSelfAuditAt = 0.0;
        $this->sharedConsumerRenewIntervalSec = (float) ($context->getConfig('wls.orchestrator.shared_consumer_renew_interval_sec', 10.0) ?? 10.0);
        if ($this->sharedConsumerRenewIntervalSec < 1.0) {
            $this->sharedConsumerRenewIntervalSec = 1.0;
        }
        if ($this->sharedConsumerRenewIntervalSec > 120.0) {
            $this->sharedConsumerRenewIntervalSec = 120.0;
        }
        $this->lastSharedConsumerRenewAt = 0.0;
        $this->sharedConsumerRenewLogged = false;
        $this->telemetryAnomalyLoggedAt = [];
        $this->telemetryWorkerRecoveryAt = [];
        $this->dispatcherAlertRecoveryAt = [];
        $providersForCritical = $this->registry->getAllProviders();
        $defaultCriticalRoles = [];
        foreach ($providersForCritical as $provider) {
            if ($provider->isCriticalRole()) {
                $defaultCriticalRoles[] = $provider->getRole();
            }
        }
        $rawCritical = $context->getConfig('wls.orchestrator.critical_roles', $defaultCriticalRoles);
        $this->criticalRoles = \array_fill_keys(\is_array($rawCritical) ? $rawCritical : $defaultCriticalRoles, true);
        $this->escalationDisconnects = [];
        $this->infraDegraded = [];
        $this->infraServiceResurrectAttempts = (int) ($context->getConfig('wls.orchestrator.infra_service_resurrect_attempts', 3) ?? 3);
        if ($this->infraServiceResurrectAttempts < 1) {
            $this->infraServiceResurrectAttempts = 1;
        }
        if ($this->infraServiceResurrectAttempts > 10) {
            $this->infraServiceResurrectAttempts = 10;
        }
        if ($this->registerTimeout < $this->startupGracePeriod) {
            // 防止 register_timeout 过短导致误判，至少与启动宽限一致
            $this->registerTimeout = $this->startupGracePeriod;
        }
        $this->applyOrchestratorStartupTimeoutFromContext($context);
        $this->desiredState = [];
        $this->maintenanceSticky = false;

        $windowsNativeSocketBridgeEnabled = (bool) (
            $context->getConfig('wls.orchestrator.ipc_windows_native_socket_bridge', false) ?? false
        );

        // 启动 IPC 控制服务器
        $this->controlServer = $this->createControlServer();
        $this->controlServer->setWindowsNativeSocketBridgeEnabled($windowsNativeSocketBridgeEnabled);
        $this->controlServer->setExpectedControlToken($context->controlToken);
        if (!$this->controlServer->start('127.0.0.1', $context->controlPort)) {
            $portInUseMsg = '';
            // 尝试诊断端口占用问题
            if (\Weline\Framework\System\Process\Processer::isPortInUse($context->controlPort)) {
                $portInUseMsg = " （端口被占用，可能是前一个 Master 进程尚未完全退出，请稍候几秒后重试，或手动杀死占用该端口的进程）";
            }
            throw new \RuntimeException(
                "无法启动 IPC 控制服务器，端口: {$context->controlPort}{$portInUseMsg}. " .
                "这是严重错误，会导致所有 Worker 无法连接到 Master，系统无法正常运行。"
            );
        }
        $this->controlServer->setExpectedInstanceCode($context->instanceName);
        if ($context->controlPort !== $this->controlServer->getPort()) {
            $this->context = $context->withControlPort($this->controlServer->getPort());
            $context = $this->context;
        }
        WlsLogger::info_("[Orchestrator] IPC 控制服务器已启动，端口: " . $this->controlServer->getPort());
        WlsLogger::info_(
            '[Orchestrator] IPC control transport='
            . ($this->controlServer->isUsingWindowsNativeSocketBridge()
                ? 'windows_native_socket_bridge'
                : 'stream_socket_server')
            . ', bridge_enabled=' . ($windowsNativeSocketBridgeEnabled ? 'true' : 'false')
        );
        // 开发模式下：前台将子进程日志输出到控制台，后台仅写 wls 日志文件
        $this->controlServer->setLogToConsole($context->windowMode);

        // 设置 IPC 消息处理器
        $this->controlServer->onMessage([$this, 'handleIpcMessage']);
        $this->controlServer->onDisconnect([$this, 'handleIpcDisconnect']);

        // 启动预设：优先将入口置入"维护池优先"语义，避免业务 Worker 未就绪时流量无处可去。
        // 真正进程拉起放到 startAllChildServicesBody 的第一阶段并发批启动。
        $this->autoStartMaintenanceMode($context);

        // P2 观测性埋点收尾：方法入口已记录 $bootstrapStartNs，在此处 observe 毫秒耗时。
        // 放在方法末尾而非 finally，是权衡"异常路径样本 vs. 代码可读性"的结果（详见入口注释）。
        \Weline\Server\Observability\MetricsRegistry::observe(
            'orchestrator.bootstrap_control_plane.ms',
            (\hrtime(true) - $bootstrapStartNs) / 1_000_000.0
        );
    }

    /**
     * 自动预置维护模式（不阻塞启动）
     *
     * 启动阶段仅负责：
     * 1) 预置 maintenanceMode=true（使 Dispatcher 在无业务 Worker 时优先维护池）
     * 2) 启用维护 Provider 并设置实例数（由第一阶段批量并发拉起）
     *
     * 不在此处等待维护 Worker READY，避免串行阻塞主启动链路。
     */
    private function autoStartMaintenanceMode(ServiceContext $context): void
    {
        $maintenanceProvider = $this->getMaintenanceProvider();
        if ($maintenanceProvider === null) {
            return;
        }

        $workerCount = $context->getWorkerCount();
        if ($workerCount === 'auto') {
            $workerProvider = $this->registry->getProvider(ControlMessage::ROLE_WORKER);
            $workerCount = $workerProvider?->getInstanceCount($context) ?? 1;
        }
        $workerCount = \max(1, (int) $workerCount);
        $nMaint = \max(1, (int)\ceil($workerCount / 3));
        $sticky = (bool) (
            ($context->envConfig['system']['maintenance'] ?? null)
            ?? ($context->envConfig['maintenance'] ?? false)
        );

        $maintenanceProvider->enable($nMaint);
        $this->maintenanceMode = true;
        $this->maintenanceSticky = $sticky;
        $this->desiredState[ControlMessage::ROLE_MAINTENANCE] = $nMaint;
        // 供 sticky 维护与就绪判断使用，避免 desiredState 未写入 worker 时误用默认 desired=1
        $this->desiredState[ControlMessage::ROLE_WORKER] = $workerCount;
        $this->logMaintenanceOperation(
            '自动维护模式预置完成，待第一阶段并发拉起 maintenance workers='
            . $nMaint
            . ', sticky='
            . ($sticky ? 'true' : 'false')
            . '，'
            . $this->formatMaintenanceOperationContext(),
            'INFO',
            'auto_start_maintenance:' . $nMaint . ':' . ($sticky ? '1' : '0') . ':' . $this->formatMaintenanceOperationContext(),
            0.0
        );
    }

    /**
     * 检查业务 Worker 是否已就绪，决定是否退出维护模式
     *
     * @return bool true = 业务 Worker 已就绪，可以退出维护模式
     */
    public function checkAndDisableMaintenanceIfReady(): bool
    {
        if (!$this->maintenanceMode) {
            return true;
        }

        if ($this->maintenanceSticky) {
            // 显式 sticky 维护模式只允许人工关闭，业务 Worker READY 仅用于待命，不自动切回业务池。
            return false;
        }

        $workers = $this->registry->getInstancesByRole('worker');
        $readyCount = 0;
        foreach ($workers as $w) {
            if ($w->state === Contract\ServiceInstance::STATE_READY && $w->port !== null && $w->port > 0) {
                $readyCount++;
            }
        }

        $desired = (int)($this->desiredState[ControlMessage::ROLE_WORKER] ?? 0);
        if ($desired <= 0) {
            $desired = 1;
        }

        // 业务 Worker 就绪比例达标
        if ($readyCount >= $desired) {
            $result = $this->disableMaintenanceMode();
            WlsLogger::info_('[Orchestrator] 业务 Worker 已就绪(' . $readyCount . '/' . $desired . ')，退出维护模式: ' . ($result['message'] ?? ''));
            return true;
        }

        return false;
    }

    public function startAll(ServiceContext $context): void
    {
        $this->bootstrapControlPlane($context);
        $this->startAllChildServices($context);
    }

    /**
     * 启动前清理当前实例的遗留子进程，避免旧代际 register/ready 干扰新一轮启动。
     * 仅按"实例作用域前缀"清理，不影响其它实例。
     */
    private function cleanupStartupInterferenceProcesses(ServiceContext $context): void
    {
        $enabled = (bool) ($context->getConfig('wls.orchestrator.startup_cleanup_interference_processes', true) ?? true);
        if (!$enabled) {
            return;
        }

        $instanceName = (string) ($context->instanceName ?: 'default');
        $prefixes = $this->getInstanceScopedChildProcessPrefixes($instanceName);
        $startedAt = \microtime(true);

        $killed = Processer::killByProcessNamePrefixes($prefixes);

        if ($killed > 0) {
            WlsLogger::warning_(
                '[Orchestrator] 启动前清场完成: instance=' . $instanceName
                . ', killed=' . $killed
                . ', elapsed_ms=' . \max(0, (int) \round((\microtime(true) - $startedAt) * 1000))
            );
        } else {
            WlsLogger::debug_(
                '[Orchestrator] 启动前清场：未发现当前实例遗留子进程 instance=' . $instanceName
                . ', elapsed_ms=' . \max(0, (int) \round((\microtime(true) - $startedAt) * 1000))
            );
        }
    }

    /**
     * @return list<string>
     */
    private function getInstanceScopedChildProcessPrefixes(string $instanceName): array
    {
        $prefixes = [
            MasterProcess::buildScopedProcessName('weline-wls-session', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-memory', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-worker', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-maintenance', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-redirect', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-gateway', $instanceName),
        ];

        return \array_values(\array_unique($prefixes));
    }

    private function cleanupSubmittedCurrentInstanceProcesses(string $reason): void
    {
        if ($this->context === null) {
            return;
        }

        $instanceName = (string) ($this->context->instanceName ?: 'default');
        $killed = Processer::killByProcessNamePrefixes($this->getInstanceScopedChildProcessPrefixes($instanceName));
        WlsLogger::warning_(
            "[Orchestrator] 当前实例子进程前缀扫尾完成: reason={$reason}, instance={$instanceName}, killed={$killed}"
        );
    }

    /**
     * bootstrapControlPlane 之后拉起子进程与启动确认（供同步 startAll 与 runLoop 内 Fiber 延迟启动共用）。
     */
    private function startAllChildServices(ServiceContext $context): void
    {
        $this->traceStartup('start_all_children_enter');
        $this->childServicesBootstrapInProgress = true;
        try {
            $cleanupStartedAt = \microtime(true);
            $this->cleanupStartupInterferenceProcesses($context);
            $this->traceStartup('start_all_children_after_cleanup', [
                'elapsed_ms' => \max(0, (int) \round((\microtime(true) - $cleanupStartedAt) * 1000)),
            ]);
            $bodyStartedAt = \microtime(true);
            $this->startAllChildServicesBody($context);
            $this->traceStartup('start_all_children_after_body', [
                'elapsed_ms' => \max(0, (int) \round((\microtime(true) - $bodyStartedAt) * 1000)),
            ]);
        } finally {
            $this->childServicesBootstrapInProgress = false;
            $this->traceStartup('start_all_children_leave');
        }
    }

    /**
     * @see startAllChildServices
     */
    private function startAllChildServicesBody(ServiceContext $context): void
    {
        $this->traceStartup('start_all_children_body_enter');
        // P2 观测性埋点：Master 生命周期内子服务批启动阶段耗时。
        // 方法有多处 return（early abort），尾部 observe 覆盖不全 —— 这里只记"完整走到结尾"的样本，
        // 早退场景由 `shouldAbortStartupTransition` 自己写 log/告警，不参与此指标。
        $startChildrenStartNs = \hrtime(true);

        // 计算子服务启动截止绝对时间（用于计算总启动时间）
        $this->childServicesStartupDeadline = \microtime(true) + $this->startupMaxDuration;
        WlsLogger::info_('[Orchestrator] 子服务启动开始，截止时间: '
            . \date('H:i:s', (int)$this->childServicesStartupDeadline)
            . ' (总超时限制: ' . $this->startupMaxDuration . 's)');

        // 本实例子服务统一并发启动；共享状态 sidecar 由 SharedStateServiceManager 前置解析/复用。
        $providers = $this->sortProvidersForStartup($this->registry->getAllProviders());
        $startedCount = 0;
        $startupAcceptance = [];

        $phaseOneProviders = [];  // 非 Worker（与 Worker 合并后仍由 sortProvidersForStartup 的优先级排序）
        $workerProviders = [];

        foreach ($providers as $provider) {
            if (!$provider->isEnabled($context)) {
                WlsLogger::debug_("[Orchestrator] 服务 {$provider->getRole()} 未启用，跳过");
                continue;
            }
            $role = $provider->getRole();
            if ($this->isSharedStateProviderRole($role)) {
                WlsLogger::debug_("[Orchestrator] 共享状态 Provider 不参与本实例并发启动批次: {$role}");
                continue;
            }

            $this->desiredState[$role] = $provider->getInstanceCount($context);
            if ($role === ControlMessage::ROLE_WORKER) {
                $workerProviders[] = $provider;
            } else {
                $phaseOneProviders[] = $provider;
            }
        }

        $criticalRoles = $this->getWorkerCriticalInfraRoles();

        WlsLogger::info_('[Orchestrator] 本地子服务一并并发启动（不包含共享状态 sidecar）');
        if ($context->windowMode) {
            echo "\033[34m  开始启动本地进程（并发）...\033[0m\n";
        }

        $allProviders = \array_values(\array_filter(
            \array_merge($phaseOneProviders, $workerProviders),
            fn (ServiceProviderInterface $p): bool => $p->getInstanceCount($context) > 0
        ));
        $this->traceStartup('start_providers_batch_before', [
            'roles' => \array_map(static fn (ServiceProviderInterface $p): string => $p->getRole(), $allProviders),
        ]);
        $batchStartedAt = \microtime(true);
        $allInstances = $this->startProvidersBatch($allProviders, $context);
        $this->traceStartup('start_providers_batch_after', [
            'elapsed_ms' => \max(0, (int) \round((\microtime(true) - $batchStartedAt) * 1000)),
        ]);

        foreach ($allProviders as $provider) {
            $role = $provider->getRole();
            $instanceCount = $provider->getInstanceCount($context);
            $displayName = $provider->getDisplayName();

            foreach (($allInstances[$role] ?? []) as $instance) {
                if ($instance !== null) {
                    $startedCount++;
                    if ($context->windowMode) {
                        $portInfo = $instance->port !== null ? " (port={$instance->port})" : '';
                        echo "\033[32m      ✓ {$displayName}#{$instance->instanceId}{$portInfo}\033[0m\n";
                    }
                }
            }

            $plannedCount = \count(\array_filter(
                $allInstances[$role] ?? [],
                static fn (mixed $instance): bool => $instance instanceof ServiceInstance
            ));
            if ($plannedCount > 0 && $role !== ControlMessage::ROLE_MAINTENANCE) {
                // Maintenance slots are standby/repair capacity; they must not
                // block the business endpoint from reaching running state.
                $startupAcceptance[$role] = [
                    'displayName' => $displayName,
                    'expected' => $plannedCount,
                    'minReady' => $this->resolveStartupAcceptanceMinReady($role, $plannedCount),
                ];
            }
        }

        // Startup children often connect slightly out of phase on Windows, but
        // a fixed 12s drain delays the first usable request more than needed.
        // Keep a short minimum drain here, then let startup acceptance keep
        // polling for later READY/ACK messages.
        $drainStartedAt = \microtime(true);
        $this->drainControlPlaneAfterStartupStep(
            120,
            50000,
            4,
            $this->resolveConcurrentStartupDrainMinDurationUsec($context)
        );
        $this->traceStartup('startup_drain_after', [
            'elapsed_ms' => \max(0, (int) \round((\microtime(true) - $drainStartedAt) * 1000)),
        ]);
        WlsLogger::info_(
            '[Orchestrator][StartupTiming] concurrent startup drain elapsed='
            . \max(0, (int) \round((\microtime(true) - $drainStartedAt) * 1000))
            . 'ms'
        );
        if ($this->shouldAbortStartupTransition()) {
            return;
        }

        if ($context->windowMode) {
            echo "\033[32m  共启动 {$startedCount} 个服务实例\033[0m\n";
        }

        // 统一的启动确认阶段
        if (!empty($startupAcceptance)) {
            $acceptanceStartedAt = \microtime(true);
            $this->waitForStartupAcceptance($startupAcceptance, $context);
            $this->traceStartup('startup_acceptance_after', [
                'elapsed_ms' => \max(0, (int) \round((\microtime(true) - $acceptanceStartedAt) * 1000)),
            ]);
            if ($this->shouldAbortStartupTransition()) {
                return;
            }
        }

        WlsLogger::info_('[Orchestrator] 所有服务启动完成');

        // 记录启动完成时间（用于启动后冷却期）
        $this->startAllCompletedAt = \microtime(true);

        // 启动后置任务改为异步调度，避免在启动 Fiber 内同步阻塞。
        $this->schedulePostStartupHousekeeping($context);

        // P2 观测性收尾：只统计"完整启动到末尾"的样本，见方法入口注释。
        \Weline\Server\Observability\MetricsRegistry::observe(
            'orchestrator.start_children.ms',
            (\hrtime(true) - $startChildrenStartNs) / 1_000_000.0
        );
    }

    /**
     * 控制面已就绪后进入主循环，并在 Fiber 中执行子服务启动，使等待窗口内仍能 poll IPC（方案 B：启动完成后再回调，例如释放启动锁）。
     *
     * @param \Closure():void|null $afterChildStartup 子服务启动流程结束（成功/早退/异常 finally）后调用
     */
    public function runLoopWithDeferredChildStartup(ServiceContext $context, ?\Closure $afterChildStartup = null): void
    {
        $this->context ??= $context;
        $this->traceStartup('run_loop_with_deferred_child_startup_enter');
        if (!$this->scheduleMainLoopTask('startup:child_services', 'child_service_startup', function () use ($context, $afterChildStartup): void {
            try {
                $this->startAllChildServices($context);
            } catch (\Throwable $e) {
                $this->handleStartupFailure($e, '延迟启动子服务异常');
                throw $e;
            } finally {
                if ($afterChildStartup !== null) {
                    ($afterChildStartup)();
                }
            }
        })) {
            try {
                $this->startAllChildServices($context);
            } catch (\Throwable $e) {
                $this->handleStartupFailure($e, '子服务启动异常');
                throw $e;
            } finally {
                if ($afterChildStartup !== null) {
                    ($afterChildStartup)();
                }
            }
        }

        $this->traceStartup('run_loop_before');
        $this->runLoop();
        $this->traceStartup('run_loop_after');
    }

    /**
     * 启动完成后的后置任务（异步）：
     * - 持久化实例信息
     * - 广播路由策略
     * - 触发 ready 通知
     *
     * 设计目标：避免在启动主路径内做同步 IO/广播，缩短 READY/ACK 窗口延迟。
     */
    private function schedulePostStartupHousekeeping(ServiceContext $context): void
    {
        if ($this->scheduleMainLoopTask('startup:post_housekeeping', 'post_startup_housekeeping', function () use ($context): void {
            $this->persistServicesInfo($context);
            $this->broadcastRoutingPolicyToWorkers();
            $this->armServerReadyNotification();
        })) {
            return;
        }

        // 兜底：调度失败时仍执行，保证功能正确性。
        $this->persistServicesInfo($context);
        $this->broadcastRoutingPolicyToWorkers();
        $this->armServerReadyNotification();
    }

    /**
     * 启动前 Provider 排序：本地子服务一律在同一次 startProvidersBatch 中并发拉起，
     * 此处仅按 priority 与 role 名稳定排序（不再按 Dispatcher/Worker 分阶段）。
     *
     * @param ServiceProviderInterface[] $providers
     * @return ServiceProviderInterface[]
     */
    private function sortProvidersForStartup(array $providers): array
    {
        \usort($providers, static function (ServiceProviderInterface $a, ServiceProviderInterface $b): int {
            $pa = $a->getPriority();
            $pb = $b->getPriority();
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return \strcmp($a->getRole(), $b->getRole());
        });

        return $providers;
    }

    /**
     * 统一启动确认：只等待计划内进程返回 READY，不在启动阶段做健康探测或复活判断。
     *
     * @param array<string,array{displayName:string,expected:int,minReady:int}> $startupAcceptance
     */
    protected function waitForStartupAcceptance(array $startupAcceptance, ServiceContext $context): void
    {
        if ($this->controlServer === null) {
            return;
        }

        $deadline = \microtime(true) + $this->startupTimeout;
        $lastPending = '';
        $lastProgressLogAt = 0.0;
        $acceptanceStartAt = \microtime(true);
        while (\microtime(true) < $deadline) {
            // Do a short multi-step drain first instead of a single poll(0,0).
            // This reduces the chance that register/ready packets which arrive
            // a few milliseconds apart stay queued until the next startup phase.
            $this->drainControlPlaneAfterStartupStep(6, 25000, 2, 50000);

            // 关键：在 Fiber 内主动处理控制操作，避免被主循环阻塞
            // 这确保 maintenance_enable 等操作能及时执行
            if ($this->pendingControlOperations !== []) {
                $this->processNextQueuedControlOperation();
            }

            if ($this->shouldAbortStartupTransition()) {
                return;
            }
            $pending = $this->collectStartupAcceptancePendingLabels($startupAcceptance);

            if ($pending === []) {
                $this->traceStartup('startup_acceptance_passed', [
                    'elapsed_ms' => \max(0, (int) \round((\microtime(true) - $acceptanceStartAt) * 1000)),
                ]);
                WlsLogger::info_('[Orchestrator] 启动确认通过: 计划内进程均已返回 READY');
                $this->startupAcceptanceComplete = true;
                return;
            }

            $fatalReason = $this->detectStartupAcceptanceFatalFailure(
                $startupAcceptance,
                $context,
                \microtime(true) - $acceptanceStartAt
            );
            if ($fatalReason !== null) {
                $this->handleStartupAcceptanceFatalFailure($startupAcceptance, $context, $fatalReason);
                return;
            }

            $pendingLabel = \implode(', ', $pending);
            if ($pendingLabel !== $lastPending) {
                $this->traceStartup('startup_acceptance_pending', [
                    'elapsed_ms' => \max(0, (int) \round((\microtime(true) - $acceptanceStartAt) * 1000)),
                    'pending' => $pending,
                ]);
                WlsLogger::info_("[Orchestrator] 启动确认中: {$pendingLabel}");
                $lastPending = $pendingLabel;
                $lastProgressLogAt = \microtime(true);
            } elseif ((\microtime(true) - $lastProgressLogAt) >= 5.0) {
                $elapsed = \microtime(true) - $acceptanceStartAt;
                WlsLogger::info_(
                    '[Orchestrator] 启动确认等待中: elapsed='
                    . \number_format($elapsed, 2, '.', '')
                    . "s, pending={$pendingLabel}"
                );
                $lastProgressLogAt = \microtime(true);
            }

            // 在 Fiber 内协作式让步，避免忙等待阻塞主循环
            if (\Fiber::getCurrent() !== null) {
                SchedulerSystem::yieldDelay(5);
            }
        }

        $this->handleStartupAcceptanceTimeout($startupAcceptance, $context, \microtime(true) - $acceptanceStartAt);
    }

    /**
     * @param array<string,array{displayName:string,expected:int,minReady:int}> $startupAcceptance
     */
    private function detectStartupAcceptanceFatalFailure(
        array $startupAcceptance,
        ServiceContext $context,
        float $elapsed
    ): ?string {
        $failFastSec = (float)($context->getConfig('wls.orchestrator.critical_startup_fail_fast_sec', 5.0) ?? 5.0);
        $failFastSec = \max(2.0, \min(30.0, $failFastSec));
        if ($elapsed < $failFastSec) {
            return null;
        }

        foreach ($startupAcceptance as $role => $rule) {
            $role = (string)$role;
            if (!$this->requiresStartupPortPreflight($role)) {
                continue;
            }
            if ($this->countRoleReadyInstances($role) >= $rule['minReady']) {
                continue;
            }

            foreach ($this->registry->getInstancesByRole($role) as $instance) {
                if ($instance->state === ServiceInstance::STATE_READY || $instance->ipcClientId !== null) {
                    continue;
                }

                $trackingPid = $this->getInstanceTrackingPid($instance);
                $processRunning = $trackingPid > 0 && $this->isProcessRunning($trackingPid);
                $port = (int)($instance->port ?? 0);
                if ($port <= 0) {
                    if ($trackingPid > 0 && !$processRunning) {
                        return "{$role}#{$instance->instanceId} process exited before READY (pid={$trackingPid})";
                    }
                    continue;
                }

                Processer::clearPortCache($port);
                $inspect = Processer::inspectPortOccupantWithHistory($port);
                if (!($inspect['in_use'] ?? false)) {
                    if (!$processRunning) {
                        return "{$role}#{$instance->instanceId} exited before binding port {$port}";
                    }
                    continue;
                }

                if (!($inspect['is_weline'] ?? false)) {
                    return "{$role}#{$instance->instanceId} cannot become READY because port {$port} is occupied by a non-WLS process"
                        . ' (' . $this->describeLaunchPortOccupant($role, $port, $inspect) . ')';
                }

                $ownerPid = (int)($inspect['pid'] ?? 0);
                if (!$processRunning && $ownerPid > 0 && !$instance->matchesManagedPid($ownerPid)) {
                    return "{$role}#{$instance->instanceId} did not register; port {$port} is still owned by stale WLS pid {$ownerPid}"
                        . ' (' . $this->describeLaunchPortOccupant($role, $port, $inspect) . ')';
                }

                if ($trackingPid > 0 && !$processRunning) {
                    return "{$role}#{$instance->instanceId} process exited before READY (pid={$trackingPid}, port={$port})";
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,array{displayName:string,expected:int,minReady:int}> $startupAcceptance
     * @return list<string>
     */
    protected function collectStartupAcceptancePendingLabels(array $startupAcceptance): array
    {
        $pending = [];
        foreach ($startupAcceptance as $role => $rule) {
            $readyCount = $this->countRoleReadyInstances((string)$role);
            if ($readyCount < $rule['minReady']) {
                $pending[] = "{$role}:{$readyCount}/{$rule['minReady']}";
            }
        }

        return $pending;
    }

    /**
     * @param array<string,array{displayName:string,expected:int,minReady:int}> $startupAcceptance
     */
    private function handleStartupAcceptanceFatalFailure(
        array $startupAcceptance,
        ServiceContext $context,
        string $reason
    ): void {
        $pending = $this->collectStartupAcceptancePendingLabels($startupAcceptance);
        $pendingLabel = $pending !== [] ? \implode(', ', $pending) : '(none)';
        $message = 'Startup failed fast: ' . $reason . ', pending=' . $pendingLabel;
        $this->startupFailureReason = $message;
        $this->persistStartupFailureToInstance($context, $message, $pending);
        WlsLogger::error_('[Orchestrator] ' . $message);

        foreach ($startupAcceptance as $role => $rule) {
            $readyCount = $this->countRoleReadyInstances((string)$role);
            if ($readyCount >= $rule['minReady']) {
                continue;
            }
            foreach ($this->buildStartupAcceptanceTimeoutDiagnostics((string)$role) as $detail) {
                WlsLogger::error_('[Orchestrator] startup pending diagnostic: ' . $detail);
            }
        }

        if ($context->windowMode) {
            echo "\033[31m  {$message}\033[0m\n";
        }

        $this->forceTerminateMasterAndChildren('startup_fail_fast:' . $pendingLabel);
    }

    /**
     * @param array<string,array{displayName:string,expected:int,minReady:int}> $startupAcceptance
     */
    protected function handleStartupAcceptanceTimeout(array $startupAcceptance, ServiceContext $context, float $elapsed): void
    {
        $pending = $this->collectStartupAcceptancePendingLabels($startupAcceptance);
        $pendingLabel = $pending !== [] ? \implode(', ', $pending) : '(none)';
        $timeout = \number_format($this->startupTimeout, 2, '.', '');
        $elapsedLabel = \number_format($elapsed, 2, '.', '');
        $message = "启动异常：计划进程 {$timeout}s 内未全部 READY，pending={$pendingLabel}, elapsed={$elapsedLabel}s";
        $this->startupFailureReason = $message;
        $this->persistStartupFailureToInstance($context, $message, $pending);
        WlsLogger::error_('[Orchestrator] ' . $message . '，关闭服务器并清理已提交进程');
        foreach ($startupAcceptance as $role => $rule) {
            $readyCount = $this->countRoleReadyInstances((string)$role);
            if ($readyCount < $rule['minReady']) {
                foreach ($this->buildStartupAcceptanceTimeoutDiagnostics((string)$role) as $detail) {
                    WlsLogger::error_('[Orchestrator] startup pending diagnostic: ' . $detail);
                }
                WlsLogger::error_(
                    "[Orchestrator] 启动异常明细: {$rule['displayName']}({$role}) READY {$readyCount}/{$rule['minReady']} (expected={$rule['expected']}, timeout={$timeout}s)"
                );
            }
        }
        if ($context->windowMode) {
            echo "\033[31m  {$message}，已关闭服务器并清理已提交进程。\033[0m\n";
        }

        $this->forceTerminateMasterAndChildren('startup_ready_timeout:' . $pendingLabel);
    }

    /**
     * 将启动失败原因写入 Master endpoint，供 server:start 进度条与 status 直接展示。
     *
     * @param list<string> $pendingLabels
     */
    private function persistStartupFailureToInstance(
        ServiceContext $context,
        string $reason,
        array $pendingLabels = []
    ): void {
        $reason = \trim($reason);
        if ($reason === '' || $context->instanceName === '') {
            return;
        }

        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $context->instanceName . '.json';
        $now = \time();
        $at = \date('Y-m-d H:i:s', $now);

        ServerInstanceManager::updateJsonFileAtomically(
            $instanceFile,
            static function (array $data) use ($reason, $pendingLabels, $now, $at): array {
                $data['startup_failure_reason'] = $reason;
                $data['startup_failure_at'] = $at;
                $data['startup_failure_timestamp'] = $now;
                if ($pendingLabels !== []) {
                    $data['startup_failure_pending'] = $pendingLabels;
                }
                $data['updated_at'] = $now;
                return self::filterEndpointRuntimeMetadata($data);
            }
        );
    }

    /**
     * @return list<string>
     */
    private function collectStartupFailurePendingRoleLabels(): array
    {
        $pending = [];
        foreach ($this->registry->getAllInstances() as $instance) {
            if ($instance->state === ServiceInstance::STATE_READY) {
                continue;
            }
            if ($instance->state === ServiceInstance::STATE_STOPPED) {
                continue;
            }
            $pending[] = "{$instance->role}#{$instance->instanceId}:{$instance->state}";
        }

        return $pending;
    }

    /**
     * 自动维护池 Worker 不阻塞整站 startup_phase=running（与 autoStartMaintenanceMode 注释一致）。
     */
    private function isInstanceRequiredForServerReadyNotification(ServiceInstance $instance): bool
    {
        if ($instance->role !== ControlMessage::ROLE_MAINTENANCE) {
            return true;
        }

        return !($this->maintenanceMode && !$this->maintenanceSticky);
    }

    private function isInstanceReadyForServerReadyNotification(ServiceInstance $instance): bool
    {
        if ($instance->state !== ServiceInstance::STATE_READY) {
            return false;
        }

        if ($instance->role !== ControlMessage::ROLE_WORKER) {
            return true;
        }

        return true;
    }

    private function startupRequiresDispatcherPoolConfirmation(): bool
    {
        if ($this->context === null || !$this->context->isDispatcherEnabled()) {
            return false;
        }

        return $this->registry->getInstancesByRole(ControlMessage::ROLE_DISPATCHER) !== [];
    }

    /**
     * 按实例规模与 CLI 后台等待时长对齐 Orchestrator 启动确认超时，避免 30s 硬杀早于 background_ready_wait_sec。
     */
    private function applyOrchestratorStartupTimeoutFromContext(ServiceContext $context): void
    {
        $workerCount = $context->getWorkerCount();
        if ($workerCount === 'auto') {
            $workerProvider = $this->registry->getProvider(ControlMessage::ROLE_WORKER);
            $workerCount = $workerProvider?->getInstanceCount($context) ?? 1;
        }
        $workerCount = \max(1, (int) $workerCount);
        $dispatcherEnabled = $context->isDispatcherEnabled();
        $sslEnabled = $context->sslEnabled;
        $configuredSec = (float) ($context->getConfig('wls.orchestrator.startup_timeout_sec', 0) ?? 0);
        if ($configuredSec > 0.0) {
            $timeoutSec = $configuredSec
                + \max(0, $workerCount - 1) * 4.0
                + ($dispatcherEnabled ? 8.0 : 0.0)
                + ($sslEnabled ? 5.0 : 0.0);
        } else {
            $timeoutSec = 30.0
                + \max(0, $workerCount - 1) * 4.0
                + ($dispatcherEnabled ? 8.0 : 0.0)
                + ($sslEnabled ? 5.0 : 0.0);
        }
        $timeoutSec = \max(15.0, \min(180.0, $timeoutSec));
        $this->startupTimeout = $timeoutSec;

        $maxDuration = (float) ($context->getConfig('wls.orchestrator.startup_max_duration_sec', $this->startupMaxDuration) ?? $this->startupMaxDuration);
        $maxDuration = \max($timeoutSec * 2.0, $maxDuration, 60.0);
        $maxDuration = \min(300.0, $maxDuration);
        $this->startupMaxDuration = $maxDuration;

        WlsLogger::info_(
            '[Orchestrator] 启动确认超时: startup_timeout='
            . \number_format($this->startupTimeout, 1, '.', '')
            . 's, startup_max_duration='
            . \number_format($this->startupMaxDuration, 1, '.', '')
            . 's'
        );
    }

    /**
     * @return list<string>
     */
    protected function buildStartupAcceptanceTimeoutDiagnostics(string $role): array
    {
        $details = [];
        foreach ($this->registry->getInstancesByRole($role) as $instance) {
            $details[] = $this->formatStartupPendingInstanceDiagnostic($instance);
        }

        if ($details === []) {
            $details[] = "role={$role} no registered instances";
        }

        return $details;
    }

    private function formatStartupPendingInstanceDiagnostic(ServiceInstance $instance): string
    {
        $trackingPid = $instance->getTrackingPid();
        $parts = [
            "role={$instance->role}#{$instance->instanceId}",
            "state={$instance->state}",
            "pid={$instance->pid}",
            'root_pid=' . $instance->getRootPid(),
            'launcher_pid=' . $instance->getLauncherPid(),
            "tracking_pid={$trackingPid}",
            'tracking_running=' . ($trackingPid > 0 && Processer::isRunningByPid($trackingPid) ? 'yes' : 'no'),
        ];

        if ($instance->port !== null) {
            $port = (int)$instance->port;
            $parts[] = "port={$port}";
            $portDiagnostic = $this->formatStartupPendingPortDiagnostic($port);
            if ($portDiagnostic !== '') {
                $parts[] = $portDiagnostic;
            }
        }

        $processName = \trim((string)$instance->getMeta('process_name', ''));
        if ($processName !== '') {
            $parts[] = 'process_name=' . $processName;
            try {
                $parts[] = 'log_file=' . Processer::getLogFile('--name=' . $processName);
            } catch (\Throwable $exception) {
                $parts[] = 'log_file_error=' . $this->compactDiagnosticValue($exception->getMessage());
            }
        }

        if ($instance->launchId !== '') {
            $parts[] = 'launch_id=' . $instance->launchId;
        }

        return \implode(' ', $parts);
    }

    private function formatStartupPendingPortDiagnostic(int $port): string
    {
        if ($port <= 0) {
            return '';
        }

        try {
            $occupant = Processer::inspectPortOccupantWithHistory($port);
        } catch (\Throwable $exception) {
            return 'port_probe_error=' . $this->compactDiagnosticValue($exception->getMessage());
        }

        $parts = [
            'port_in_use=' . (!empty($occupant['in_use']) ? 'yes' : 'no'),
        ];
        foreach (['pid', 'pid_running', 'is_weline', 'state', 'pname'] as $key) {
            if (\array_key_exists($key, $occupant)) {
                $value = $occupant[$key];
                if (\is_bool($value)) {
                    $value = $value ? 'yes' : 'no';
                }
                $parts[] = 'port_' . $key . '=' . $this->compactDiagnosticValue((string)$value);
            }
        }

        return \implode(' ', $parts);
    }

    private function compactDiagnosticValue(string $value): string
    {
        $value = \trim(\preg_replace('/\s+/', '_', $value) ?? $value);
        if ($value === '') {
            return '-';
        }

        if (\strlen($value) > 180) {
            return \substr($value, 0, 177) . '...';
        }

        return $value;
    }

    private function countRoleReadyInstances(string $role): int
    {
        $readyCount = 0;
        foreach ($this->registry->getInstancesByRole($role) as $instance) {
            if ($instance->state === ServiceInstance::STATE_READY) {
                $readyCount++;
            }
        }

        return $readyCount;
    }

    private function resolveStartupAcceptanceMinReady(string $role, int $plannedCount): int
    {
        if ($plannedCount <= 0) {
            return 0;
        }

        if ($role === ControlMessage::ROLE_WORKER) {
            return $this->resolveRequiredWorkerReadyCount($plannedCount);
        }

        return $plannedCount;
    }

    private function resolveRequiredWorkerReadyCount(int $plannedCount): int
    {
        if ($plannedCount <= 0) {
            return 0;
        }

        $configured = $this->context?->getConfig('wls.orchestrator.worker_startup_min_ready', 'all') ?? 'all';
        if (\is_scalar($configured)) {
            $flag = \strtolower(\trim((string)$configured));
            if (\in_array($flag, ['all', 'full', 'planned', 'strict'], true)) {
                return $plannedCount;
            }
            if (\in_array($flag, ['1', 'one', 'first', 'minimum', 'legacy'], true)) {
                return 1;
            }
            if (\ctype_digit($flag)) {
                return \max(1, \min($plannedCount, (int)$flag));
            }
        }

        return $plannedCount;
    }

    private function shouldAbortStartupTransition(): bool
    {
        if ($this->consumePendingStopRequest()) {
            return true;
        }
        if ($this->hasMainLoopTask('control:stop_all')) {
            return true;
        }

        return !$this->running || $this->isStopFlowActive();
    }

    /**
     * 检查总启动时间是否超时，超时则强制终止 Master 及所有子进程。
     *
     * 仅在以下条件同时满足时触发：
     * - childServicesStartupDeadline > 0（启动已开始）
     * - startupAcceptanceComplete === false（启动尚未完成）
     * - current time >= deadline（超过总启动时间限制）
     *
     * 使用绝对截止时间而非 elapsed time，确保即使主循环偶尔阻塞也能正确超时。
     */
    private function checkStartupTimeoutAndExitIfNeeded(): void
    {
        // 启动未开始或已确认通过，无需检查
        if ($this->childServicesStartupDeadline <= 0.0 || $this->startupAcceptanceComplete) {
            return;
        }

        $now = \microtime(true);
        if ($now < $this->childServicesStartupDeadline) {
            return;
        }

        $elapsed = $now - ($this->childServicesStartupDeadline - $this->startupMaxDuration);
        WlsLogger::error_(
            '[Orchestrator] 总启动时间超时: elapsed=' . \number_format($elapsed, 2, '.', '')
            . 's >= limit=' . $this->startupMaxDuration . 's，强制终止 Master 及所有子进程'
        );

        // 强制终止 Master 及所有子进程
        $this->forceTerminateMasterAndChildren('startup_timeout_exceeded');
    }

    /**
     * Windows 等环境下第二次 Ctrl+C：若首次停机仍卡在 pending（主循环尚未 consume），在此同步并入队 stop Fiber。
     *
     * @return bool true 表示已处理（Master 不应立即强杀）
     */
    public function applyRepeatTerminationNudge(): bool
    {
        if ($this->pendingStopReason === null) {
            return false;
        }
        WlsLogger::info_('[Orchestrator] 重复终止信号：同步将 pending 停机请求并入队（避免卡在启动 Fiber 同步段）');

        return $this->consumePendingStopRequest();
    }

    /**
     * 跨 Provider 批量拉起（用于 Dispatcher/Session/Memory 等基础服务并发启动）。
     *
     * @param ServiceProviderInterface[] $providers
     * @return array<string, array<int, ServiceInstance|null>>
     */
    protected function startProvidersBatch(array $providers, ServiceContext $context): array
    {
        $this->traceStartup('start_providers_batch_enter', [
            'provider_count' => \count($providers),
        ]);
        $commands = [];
        $prepared = [];
        $result = [];
        $prepareStartedAt = \microtime(true);
        $previousBulkPortCheck = $this->bulkLaunchPortCheckActive;
        $this->bulkLaunchPortCheckActive = true;
        Processer::clearPortCache();

        try {
        foreach ($providers as $provider) {
            $role = $provider->getRole();
            $instanceCount = $provider->getInstanceCount($context);
            $displayName = $provider->getDisplayName();

            if ($context->windowMode) {
                echo "\033[34m  启动 {$displayName}: {$instanceCount} 个实例\033[0m\n";
            }
            WlsLogger::info_("[Orchestrator] 启动服务 {$displayName} (role={$role}, instances={$instanceCount}, priority={$provider->getPriority()})");

            for ($i = 1; $i <= $instanceCount; $i++) {
                $instancePrepareStartedAt = \microtime(true);
                $portResolveStartedAt = \microtime(true);
                $configuredPort = $provider->getPort($i, $context);
                $port = $configuredPort;
                if ($configuredPort !== null) {
                    $resolvedPort = $this->resolveLaunchPortForStart($role, $i, (int)$configuredPort, $context);
                    if ($resolvedPort === null) {
                        $message = "[Orchestrator] {$role}#{$i} port {$configuredPort} is unavailable; startup cannot continue";
                        WlsLogger::warning_($message);
                        if ($role !== ControlMessage::ROLE_MAINTENANCE) {
                            $this->startupFailureReason = $message;
                            throw new \RuntimeException($message);
                        }
                        continue;
                    }
                    $port = $resolvedPort;
                }
                $portResolveElapsedMs = \max(0, (int) \round((\microtime(true) - $portResolveStartedAt) * 1000));
                $launchId = $this->generateLaunchId($role, $i);
                $instance = new ServiceInstance(
                    role: $role,
                    instanceId: $i,
                    epoch: $context->epoch,
                    launchId: $launchId,
                    port: $port,
                    state: ServiceInstance::STATE_STARTING,
                    startedAt: \microtime(true),
                    processKind: $provider->getProcessKind(),
                    moduleCode: $provider->getModuleCode(),
                );
                if ($configuredPort !== null && (int)$configuredPort !== (int)$port) {
                    $this->markEmergencyDynamicPort($instance, (int)$configuredPort, (int)$port, 'providers_batch_start');
                }
                $this->assignSlotLeaseMetadata($instance);

                $commandPrepareStartedAt = \microtime(true);
                $command = $provider->buildCommand($i, $context);
                if ($configuredPort !== null && (int)$configuredPort !== (int)$port) {
                    $command = $this->withServiceCommandPort($command, (int)$port);
                }
                $commandPrepareElapsedMs = \max(0, (int) \round((\microtime(true) - $commandPrepareStartedAt) * 1000));
                $processName = $command->getProcessName();
                if ($processName !== null) {
                    $instance->setMeta('process_name', $processName);
                }
                $instance->setMeta('control_capabilities', $this->buildControlCapabilities($provider));
                $instance->setMeta('epoch', $context->epoch);
                $instance->setMeta('launch_id', $launchId);

                $cmd = $command->build();
                $cmd = $this->appendInstanceIdentityArgs($cmd, $instance);
                if ($processName !== null) {
                    $cmd .= ' --name=' . \escapeshellarg($processName);
                }

                $foreground = $this->shouldLaunchForeground($role, $context);
                $instance->setMeta('spawn_transport', $foreground ? 'processer_create_foreground' : 'processer_create');

                $key = "{$role}#{$i}";
                $commands[$key] = [
                    'command' => $cmd,
                    'block' => false,
                    'foreground' => $foreground,
                    'enableLog' => $this->resolveChildProcessLogFlag($provider, $context),
                ];
                $prepared[$key] = [
                    'instance' => $instance,
                    'provider' => $provider,
                    'role' => $role,
                    'instance_id' => $i,
                    'command_obj' => $command,
                ];
                WlsLogger::info_(
                    '[Orchestrator][StartupTiming] phase-one prepare '
                    . $role . '#' . $i
                    . ' elapsed=' . $commandPrepareElapsedMs . 'ms'
                    . ' port_resolve=' . $portResolveElapsedMs . 'ms'
                    . ' total=' . \max(0, (int) \round((\microtime(true) - $instancePrepareStartedAt) * 1000)) . 'ms'
                    . ($port !== null ? ' port=' . $port : '')
                );
            }
        }
        } finally {
            $this->bulkLaunchPortCheckActive = $previousBulkPortCheck;
        }

        if ($prepared !== []) {
            $preparedRoles = \array_values(\array_unique(\array_map(
                static fn (array $item): string => (string) ($item['role'] ?? ''),
                $prepared
            )));
            WlsLogger::info_(
                '[Orchestrator][StartupTiming] phase-one prepare total roles='
                . \implode(',', $preparedRoles)
                . ' instances=' . \count($prepared)
                . ' elapsed=' . \max(0, (int) \round((\microtime(true) - $prepareStartedAt) * 1000)) . 'ms'
            );
        }

        // Register placeholders before batchCreate so early IPC register/ready
        // messages can still resolve to their intended phase-one instances.
        foreach ($prepared as $item) {
            /** @var ServiceInstance $preparedInstance */
            $preparedInstance = $item['instance'];
            $this->registry->addInstance($preparedInstance);
        }

        $batchSpawnStartedAt = \microtime(true);
        $this->traceStartup('batch_create_before', [
            'command_count' => \count($commands),
        ]);
        $pids = $this->batchCreateProcesses($commands);
        $batchSpawnFinishedAt = \microtime(true);
        $this->traceStartup('batch_create_after', [
            'elapsed_ms' => \max(0, (int) \round(($batchSpawnFinishedAt - $batchSpawnStartedAt) * 1000)),
            'pid_count' => \count($pids),
        ]);
        if ($prepared !== []) {
            $preparedRoles = \array_values(\array_unique(\array_map(
                static fn (array $item): string => (string) ($item['role'] ?? ''),
                $prepared
            )));
            WlsLogger::info_(
                '[Orchestrator][StartupTiming] phase-one batchCreate roles='
                . \implode(',', $preparedRoles)
                . ' instances=' . \count($prepared)
                . ' elapsed=' . \max(0, (int) \round(($batchSpawnFinishedAt - $batchSpawnStartedAt) * 1000)) . 'ms'
            );
        }
        if ($this->shouldAbortStartupTransition()) {
            return $result;
        }
        $batchSize = \count($prepared);
        foreach ($prepared as $key => $item) {
            /** @var ServiceInstance $instance */
            $instance = $item['instance'];
            /** @var ServiceProviderInterface $provider */
            $provider = $item['provider'];
            $role = (string)$item['role'];
            $instanceId = (int)$item['instance_id'];
            $pid = (int)($pids[$key] ?? 0);
            if ($pid <= 0) {
                WlsLogger::warning_("[Orchestrator] 启动 {$role}#{$instanceId} 未返回 PID（非阻塞路径），等待 IPC register 确认");
            }
            $this->markSpawnedInstance(
                $instance,
                $batchSpawnStartedAt,
                $batchSpawnFinishedAt,
                $pid,
                'providers_batch_create',
                $batchSize
            );
            $this->registry->updateInstance($instance);
            $provider->onStarted($instance);
            $configuredPort = (int)($instance->getMeta('configured_port') ?? 0);
            if ($configuredPort > 0 && $configuredPort !== (int)($instance->port ?? 0)) {
                $this->scheduleEmergencyPortCleanup($role, $instanceId, $configuredPort, 'providers_batch_start');
            }
            WlsLogger::info_("[Orchestrator] 已启动 {$role}#{$instanceId} (pid={$pid}" . ($instance->port !== null ? ", port={$instance->port}" : '') . ')');
            $result[$role][] = $instance;
        }

        return $result;
    }

    private function isSharedStateServiceInstance(ServiceInstance $instance): bool
    {
        return $this->isSharedStateProviderRole($instance->role);
    }

    private function isSharedStateProviderRole(string $role): bool
    {
        return $role === ControlMessage::ROLE_SESSION_SERVER
            || $role === ControlMessage::ROLE_MEMORY_SERVER;
    }
        /**
     * 持久化服务实例信息到实例文件
     *
     * 委托给 ServerInstanceManager 统一管理，确保所有命令获取到一致的实例信息。
     */
    private function persistServicesInfo(ServiceContext $context): void
    {
        $this->persistServicesInfoDirty = true;
        $this->persistServicesInfoContext = $context;

        $now = \microtime(true);
        $delta = $now - $this->lastPersistServicesInfoAt;
        if ($delta < $this->persistServicesInfoMinIntervalSec) {
            if (!$this->hasMainLoopTask('mainloop:persist_services_info')) {
                $this->scheduleMainLoopTask('mainloop:persist_services_info', 'persist_services_info', function (): void {
                    $waitMs = (int)\max(1, \ceil($this->persistServicesInfoMinIntervalSec * 1000));
                    SchedulerSystem::yieldDelay($waitMs);
                    $this->flushPersistServicesInfo();
                });
            }
            return;
        }

        $this->flushPersistServicesInfo();
    }

    /**
     * 真正执行实例信息落盘（带节流入口 persistServicesInfo 调用）。
     */
    private function flushPersistServicesInfo(): void
    {
        if (!$this->persistServicesInfoDirty || $this->persistServicesInfoContext === null) {
            return;
        }
        $context = $this->persistServicesInfoContext;
        $manager = new ServerInstanceManager();
        if (!$manager->hasInstance($context->instanceName)) {
            $this->persistServicesInfoDirty = false;
            return;
        }
        $manager->updateMasterPid($context->instanceName, $context->masterPid);
        $this->lastPersistServicesInfoAt = \microtime(true);
        $this->persistServicesInfoDirty = false;
        WlsLogger::debug_('[Orchestrator] Master endpoint metadata refreshed');
    }

    /**
     * @param int[] $instanceIds
     * @return array<ServiceInstance|null>
     */
    private function startInstanceIdsBatch(ServiceProviderInterface $provider, array $instanceIds, ServiceContext $context): array
    {
        $role = $provider->getRole();
        $instanceIds = \array_values(\array_unique(\array_map('intval', $instanceIds)));
        \sort($instanceIds, \SORT_NUMERIC);
        $requestedInstanceIds = $instanceIds;
        $filterStartedAt = \microtime(true);
        $instanceIds = $this->filterStartableInstanceIds($role, $instanceIds);
        WlsLogger::info_(
            '[Orchestrator][StartupTiming] role=' . $role
            . ' filterStartable requested=[' . \implode(',', $requestedInstanceIds) . ']'
            . ' startable=[' . \implode(',', $instanceIds) . ']'
            . ' elapsed=' . \max(0, (int) \round((\microtime(true) - $filterStartedAt) * 1000)) . 'ms'
        );
        if ($instanceIds === []) {
            return [];
        }

        $preparedInstances = [];
        $commands = [];
        $prepareStartedAt = \microtime(true);
        $previousBulkPortCheck = $this->bulkLaunchPortCheckActive;
        $this->bulkLaunchPortCheckActive = false;

        foreach ($instanceIds as $instanceId) {
            $configuredPort = $provider->getPort($instanceId, $context);
            $port = $this->resolveLaunchPortForStart($role, $instanceId, (int)($configuredPort ?? 0), $context);
            if ($port === null) {
                WlsLogger::warning_("[Orchestrator] {$role}#{$instanceId} 端口 {$configuredPort} 未释放，跳过本次批量启动");
                continue;
            }
            $launchId = $this->generateLaunchId($role, $instanceId);

            $instance = new ServiceInstance(
                role: $role,
                instanceId: $instanceId,
                epoch: $context->epoch,
                launchId: $launchId,
                port: $port,
                state: ServiceInstance::STATE_STARTING,
                startedAt: \microtime(true),
                processKind: $provider->getProcessKind(),
                moduleCode: $provider->getModuleCode(),
            );
            $this->assignSlotLeaseMetadata($instance);
            if ($configuredPort !== null && (int)$configuredPort !== (int)$port) {
                $this->markEmergencyDynamicPort($instance, (int)$configuredPort, (int)$port, 'batch_start');
            }

            $commandPrepareStartedAt = \microtime(true);
            $command = $provider->buildCommand($instanceId, $context);
            if ($configuredPort !== null && (int)$configuredPort !== (int)$port) {
                $command = $this->withServiceCommandPort($command, (int)$port);
            }
            $commandPrepareElapsedMs = \max(0, (int) \round((\microtime(true) - $commandPrepareStartedAt) * 1000));
            $processName = $command->getProcessName();
            if ($processName !== null) {
                $instance->setMeta('process_name', $processName);
            }
            $instance->setMeta('control_capabilities', $this->buildControlCapabilities($provider));
            $instance->setMeta('epoch', $context->epoch);
            $instance->setMeta('launch_id', $launchId);

            $cmd = $command->build();
            $cmd = $this->appendInstanceIdentityArgs($cmd, $instance);
            if ($processName !== null) {
                $cmd .= ' --name=' . \escapeshellarg($processName);
            }

            $foreground = $this->shouldLaunchForeground($role, $context);
            $instance->setMeta('spawn_transport', $foreground ? 'processer_create_foreground' : 'processer_create');

            $preparedInstances[$instanceId] = $instance;
            $commands[(string) $instanceId] = [
                'command' => $cmd,
                'block' => false,
                'foreground' => $foreground,
                'enableLog' => $this->resolveChildProcessLogFlag($provider, $context),
            ];
            WlsLogger::info_(
                '[Orchestrator][StartupTiming] role=' . $role
                . ' prepare ' . $role . '#' . $instanceId
                . ' elapsed=' . $commandPrepareElapsedMs . 'ms'
                . ($port !== null ? ' port=' . $port : '')
            );
        }
        $this->bulkLaunchPortCheckActive = $previousBulkPortCheck;

        WlsLogger::info_(
            '[Orchestrator][StartupTiming] role=' . $role
            . ' prepare total instances=' . \count($preparedInstances)
            . ' elapsed=' . \max(0, (int) \round((\microtime(true) - $prepareStartedAt) * 1000)) . 'ms'
        );
        if ($preparedInstances === []) {
            return [];
        }

        // 先登记占位，避免 batchCreate 阻塞期间子进程 READY 无法匹配到实例。
        foreach ($preparedInstances as $preparedInstance) {
            $this->registry->addInstance($preparedInstance);
        }

        WlsLogger::debug_(
            "[Orchestrator] 批量启动 {$role} [" . \implode(',', $instanceIds) . ']（Processer::batchCreate）'
        );
        $batchSpawnStartedAt = \microtime(true);
        $pids = $this->batchCreateProcesses($commands);
        $batchSpawnFinishedAt = \microtime(true);
        WlsLogger::info_(
            '[Orchestrator][StartupTiming] role=' . $role
            . ' batchCreate instances=' . \count($preparedInstances)
            . ' elapsed=' . \max(0, (int) \round(($batchSpawnFinishedAt - $batchSpawnStartedAt) * 1000)) . 'ms'
        );
        if ($this->shouldAbortStartupTransition()) {
            return [];
        }

        $results = [];
        $batchSize = \count($preparedInstances);
        foreach ($preparedInstances as $instanceId => $instance) {
            $pid = (int) ($pids[(string) $instanceId] ?? $pids[$instanceId] ?? 0);
            if ($pid <= 0) {
                WlsLogger::warning_("[Orchestrator] 启动 {$role}#{$instanceId} 未返回 PID（非阻塞路径），等待 IPC register 确认");
            }

            $this->markSpawnedInstance(
                $instance,
                $batchSpawnStartedAt,
                $batchSpawnFinishedAt,
                $pid,
                'instance_batch_create',
                $batchSize
            );
            $this->registry->updateInstance($instance);

            WlsLogger::info_(
                "[Orchestrator] 已启动 {$role}#{$instanceId} (pid={$pid}" . ($instance->port !== null ? ", port={$instance->port}" : '') . ')'
            );

            $provider->onStarted($instance);
            $configuredPort = (int)($instance->getMeta('configured_port') ?? 0);
            if ($configuredPort > 0 && $configuredPort !== (int)($instance->port ?? 0)) {
                $this->scheduleEmergencyPortCleanup($role, (int)$instanceId, $configuredPort, 'batch_start');
            }
            $results[] = $instance;
        }

        $drainStartedAt = \microtime(true);
        $this->drainControlPlaneAfterStartupStep();
        WlsLogger::info_(
            '[Orchestrator][StartupTiming] role=' . $role
            . ' final drain elapsed=' . \max(0, (int) \round((\microtime(true) - $drainStartedAt) * 1000)) . 'ms'
        );

        return $results;
    }

    /**
     * @param array<string|int, array{command:string,block:bool,foreground:bool}> $commands
     * @return array<string|int, int>
     */
    protected function batchCreateProcesses(array $commands): array
    {
        return Processer::batchCreate($commands);
    }

    /**
     * 启动窗口内尽量把已到达的 register/ready/ack 等控制消息及时消化掉，
     * 避免"只 poll 一帧"后马上再次进入长时间 spawnProcess，导致 READY 饿死。
     */
    private function drainControlPlaneAfterStartupStep(
        int $maxPolls = 30,
        int $blockingUsec = 100000,
        int $requiredIdleStreak = 2,
        int $minDurationUsec = 0
    ): void
    {
        if ($this->controlServer === null) {
            return;
        }

        $requiredIdleStreak = \max(1, $requiredIdleStreak);
        $idleStreak = 0;
        $minDeadline = $minDurationUsec > 0
            ? (\microtime(true) + ($minDurationUsec / 1000000))
            : 0.0;
        for ($i = 0; $i < $maxPolls; $i++) {
            $changed = $this->controlServer->poll(0, $blockingUsec);
            if ($this->shouldAbortStartupTransition()) {
                return;
            }
            if ($changed > 0) {
                $idleStreak = 0;
                continue;
            }

            $idleStreak++;
            if ($idleStreak >= $requiredIdleStreak
                && ($minDeadline <= 0.0 || \microtime(true) >= $minDeadline)) {
                return;
            }
        }
    }

    private function resolveConcurrentStartupDrainMinDurationUsec(ServiceContext $context): int
    {
        $configured = (int) ($context->getConfig('wls.orchestrator.concurrent_startup_drain_min_usec', 0) ?? 0);
        if ($configured > 0) {
            return \min(12_000_000, $configured);
        }

        $isWin = \defined('IS_WIN')
            ? (bool) \constant('IS_WIN')
            : (\strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN');

        if ($isWin && $context->windowMode) {
            return 2_500_000;
        }

        return $isWin ? 1_500_000 : 750_000;
    }

    /**
     * 过滤不可重复拉起的槽位，防止同一 worker 槽位在 startup/liveness/reconcile 交叠时被重复 fork。
     *
     * @param int[] $instanceIds
     * @return int[]
     */
    private function filterStartableInstanceIds(string $role, array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $startable = [];
        $now = \microtime(true);
        foreach ($instanceIds as $instanceId) {
            $instanceId = (int) $instanceId;
            if ($instanceId <= 0) {
                continue;
            }
            $existing = $this->registry->getInstance($role, $instanceId);
            if ($existing === null) {
                $startable[] = $instanceId;
                continue;
            }

            $slotOccupancy = $this->inspectSlotOccupancy($existing, null, $now);
            if ($slotOccupancy['occupied']) {
                WlsLogger::warning_(
                    "[Orchestrator] 跳过重复启动 {$role}#{$instanceId}"
                    . "（existing_state={$existing->state}, previous_state={$slotOccupancy['previousState']}, "
                    . "ipc_alive=" . ($slotOccupancy['ipcAlive'] ? '1' : '0')
                    . ", pid=" . $slotOccupancy['trackedPid']
                    . ", pid_alive=" . ($slotOccupancy['pidAlive'] ? '1' : '0')
                    . ", fresh_startup=" . ($slotOccupancy['freshStartupWindow'] ? '1' : '0')
                    . ', port_weline=' . ($slotOccupancy['portHeldByWeline'] ? '1' : '0') . '）'
                );
                continue;
            }

            // 既无 IPC 也无存活 PID，且非新近启动态：视为僵尸占槽，允许接管重拉。
            $this->registry->removeInstance($role, $instanceId);
            $startable[] = $instanceId;
        }

        return $startable;
    }

    /**
     * @param array{pid?: int, previousState?: string}|null $resurrectEntry
     * @return array{
     *     trackedPid:int,
     *     ipcAlive:bool,
     *     pidAlive:bool,
     *     previousState:string,
     *     startupWindowSec:float,
     *     ageSec:float,
     *     freshStartupWindow:bool,
     *     portHeldByWeline:bool,
     *     occupied:bool
     * }
     */
    private function inspectSlotOccupancy(
        ServiceInstance $instance,
        ?array $resurrectEntry = null,
        ?float $now = null
    ): array {
        $now ??= \microtime(true);
        $trackedPid = $this->getQueuedTrackingPid($instance, $resurrectEntry);
        $ipcAlive = $instance->ipcClientId !== null
            && $this->controlServer !== null
            && $this->controlServer->clientExists($instance->ipcClientId);
        $pidAlive = $trackedPid > 0 && $this->isProcessRunning($trackedPid);
        $previousState = (string) ($resurrectEntry['previousState']
            ?? $instance->getMeta('resurrection_queued_from_state', $instance->state));
        $startupWindowSec = $this->getRegisterTimeoutForRole($instance->role) + 5.0;
        $ageSec = $instance->startedAt > 0
            ? \max(0.0, $now - $instance->startedAt)
            : 0.0;
        $freshStartupWindow = $instance->startedAt > 0
            && \in_array($previousState, [ServiceInstance::STATE_STARTING, ServiceInstance::STATE_REGISTERED], true)
            && $ageSec < $startupWindowSec;

        // 端口上仍有 Weline 监听时，即使 IPC 未连上或 PID 探测失败，也必须视为槽位占用，
        // 否则 reconcile/复活路径会在同端口再拉起第二个子进程（典型"逃逸/重复进程"）。
        $port = (int) ($instance->port ?? 0);
        $portHeldByWeline = $port > 0 && $this->isPortHeldByWelineSlotOwner($port);
        $hasPidOrTree = $trackedPid > 0 || $instance->getManagedPids() !== [];

        return [
            'trackedPid' => $trackedPid,
            'ipcAlive' => $ipcAlive,
            'pidAlive' => $pidAlive,
            'hasIpc' => $ipcAlive,
            'hasPidOrTree' => $hasPidOrTree,
            'hasPortOwner' => $portHeldByWeline,
            'previousState' => $previousState,
            'startupWindowSec' => $startupWindowSec,
            'ageSec' => $ageSec,
            'freshStartupWindow' => $freshStartupWindow,
            'portHeldByWeline' => $portHeldByWeline,
            'occupied' => $ipcAlive || $pidAlive || ($trackedPid <= 0 && $freshStartupWindow) || $portHeldByWeline,
        ];
    }

    private function isPortHeldByWelineSlotOwner(int $port): bool
    {
        if ($port <= 0) {
            return false;
        }

        $inspect = Processer::inspectPortOccupantWithHistory($port);
        $inUse = (bool)($inspect['in_use'] ?? false);
        if ((bool)($inspect['is_weline'] ?? false)) {
            return true;
        }

        $portIndex = Processer::readPortIndex();
        $pname = (string)($portIndex[(string)$port] ?? '');
        if ($pname === '') {
            return false;
        }

        $indexSuggestsWeline = \strpos($pname, 'weline-') !== false || \strpos($pname, '--name=weline-') !== false;
        if (!$indexSuggestsWeline) {
            return false;
        }

        return $inUse || $this->canConnectLocalPort($port);
    }

    private function canConnectLocalPort(int $port): bool
    {
        if ($port <= 0) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $socket = @\stream_socket_client(
            'tcp://127.0.0.1:' . $port,
            $errno,
            $errstr,
            0.05,
            \STREAM_CLIENT_CONNECT
        );
        if (!\is_resource($socket)) {
            return false;
        }

        @\fclose($socket);

        return true;
    }
    
    /**
     * 启动单个服务实例
     */
    private function startInstance(
        ServiceProviderInterface $provider,
        int $instanceId,
        ServiceContext $context,
        ?int $launchPortOverride = null,
        ?int $configuredPortOverride = null
    ): ?ServiceInstance
    {
        $role = $provider->getRole();
        if ($this->filterStartableInstanceIds($role, [$instanceId]) === []) {
            return null;
        }
        $configuredPort = $configuredPortOverride ?? $provider->getPort($instanceId, $context);
        $port = $launchPortOverride ?? $this->resolveLaunchPortForStart($role, $instanceId, (int)($configuredPort ?? 0), $context);
        if ($port === null) {
            WlsLogger::warning_("[Orchestrator] {$role}#{$instanceId} 端口 {$configuredPort} 未释放，跳过本次启动");
            return null;
        }
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
            processKind: $provider->getProcessKind(),
            moduleCode: $provider->getModuleCode(),
        );
        $this->assignSlotLeaseMetadata($instance);
        if ($configuredPort !== null && (int)$configuredPort !== (int)$port) {
            $this->markEmergencyDynamicPort($instance, (int)$configuredPort, (int)$port, 'single_start');
        }

        // 构建启动命令
        $command = $provider->buildCommand($instanceId, $context);
        if ($configuredPort !== null && (int)$configuredPort !== (int)$port) {
            $command = $this->withServiceCommandPort($command, (int)$port);
        }

        // 保存进程名以便后续清理 PID 文件
        $processName = $command->getProcessName();
        if ($processName !== null) {
            $instance->setMeta('process_name', $processName);
        }
        $instance->setMeta('control_capabilities', $this->buildControlCapabilities($provider));
        $instance->setMeta('epoch', $context->epoch);
        $instance->setMeta('launch_id', $launchId);

        // 先登记到 Registry，再启动进程，避免 READY 报文先到时匹配不到实例。
        $instance->pid = 0;
        $instance->state = ServiceInstance::STATE_STARTING;
        $instance->startedAt = \microtime(true);
        $this->registry->addInstance($instance);

        // 委托给 Processer 启动进程
        $spawnStartedAt = \microtime(true);
        $pid = $this->spawnProcess($command, $instance);
        $spawnFinishedAt = \microtime(true);
        // 非阻塞启动时 Windows/Linux 均可能不返回 PID，统一等待子进程通过 IPC register 上报
        if ($pid <= 0) {
            WlsLogger::warning_("[Orchestrator] 启动 {$role}#{$instanceId} 未返回 PID（非阻塞路径），等待 IPC register 确认");
        }

        $this->markSpawnedInstance(
            $instance,
            $spawnStartedAt,
            $spawnFinishedAt,
            $pid,
            (string) $instance->getMeta('spawn_transport', 'spawn_process')
        );
        $this->registry->updateInstance($instance);

        WlsLogger::info_("[Orchestrator] 已启动 {$role}#{$instanceId} (pid={$pid}" . ($port !== null ? ", port={$port}" : '') . ')');
        if ($configuredPort !== null && (int)$configuredPort !== (int)$port) {
            $this->scheduleEmergencyPortCleanup($role, $instanceId, (int)$configuredPort, 'single_start');
        }

        // 回调 Provider
        $provider->onStarted($instance);

        return $instance;
    }

    protected function prepareLocalPortForStart(string $role, int $port): bool
    {
        if ($port <= 0) {
            return true;
        }
        if ($this->requiresStartupPortPreflight($role)) {
            return $this->prepareCriticalPortForStart($role, $port);
        }
        if ($this->shouldUseFastBindProbeForPortChecks()) {
            $free = $this->isPortFreeByBindProbe($port);
            if ($free) {
                return true;
            }
            WlsLogger::warning_("[Orchestrator] {$role} launch port {$port} is not bindable; skip startup without netstat scan");
            return false;
        }

        if (\in_array($role, [
            ControlMessage::ROLE_SESSION_SERVER,
            ControlMessage::ROLE_MEMORY_SERVER,
        ], true)) {
            if (!$this->bulkLaunchPortCheckActive) {
                Processer::clearPortCache($port);
            }
            if (!Processer::isPortInUse($port)) {
                return true;
            }

            WlsLogger::warning_(
                "[Orchestrator] {$role} 共享端口 {$port} 已被占用且未成功接入，跳过本次拉起以避免覆盖共享 token"
            );
            return false;
        }

        if (!\in_array($role, [
            ControlMessage::ROLE_WORKER,
            ControlMessage::ROLE_MAINTENANCE,
            ControlMessage::ROLE_DISPATCHER,
            ControlMessage::ROLE_REDIRECT,
        ], true)) {
            return true;
        }

        if (!$this->bulkLaunchPortCheckActive) {
            Processer::clearPortCache($port);
        }
        if (!Processer::isPortInUse($port)) {
            return true;
        }
        if (!Processer::isPortUsedByWeline($port)) {
            return false;
        }

        WlsLogger::warning_("[Orchestrator] {$role} 启动前发现端口 {$port} 仍被 Weline 残留进程占用，先清理再拉起");
        return $this->ensurePortReleasedForResurrection($port);
    }

    private function requiresStartupPortPreflight(string $role): bool
    {
        return isset(self::STARTUP_PORT_PREFLIGHT_ROLES[$role]);
    }

    private function prepareCriticalPortForStart(string $role, int $port): bool
    {
        Processer::clearPortCache($port);
        $inspect = Processer::inspectPortOccupantWithHistory($port);
        if (!($inspect['in_use'] ?? false)) {
            return true;
        }

        if (!($inspect['is_weline'] ?? false)) {
            WlsLogger::error_(
                '[Orchestrator] startup blocked: '
                . $this->describeLaunchPortOccupant($role, $port, $inspect)
            );
            return false;
        }

        if (!$this->isCurrentInstancePortOccupant($inspect)) {
            WlsLogger::error_(
                '[Orchestrator] startup blocked by another WLS instance: '
                . $this->describeLaunchPortOccupant($role, $port, $inspect)
            );
            return false;
        }

        WlsLogger::warning_(
            '[Orchestrator] startup found stale current-instance WLS port owner, releasing before launch: '
            . $this->describeLaunchPortOccupant($role, $port, $inspect)
        );

        if (!$this->releaseCurrentInstancePortOwner($role, $port, $inspect)) {
            WlsLogger::error_(
                '[Orchestrator] startup blocked: failed to release current-instance port owner: '
                . $this->describeLaunchPortOccupant($role, $port, $inspect)
            );
            return false;
        }

        Processer::clearPortCache($port);
        if (!Processer::isPortInUse($port)) {
            return true;
        }

        WlsLogger::error_(
            '[Orchestrator] startup blocked: port remains occupied after cleanup: '
            . $this->describeLaunchPortOccupant($role, $port, Processer::inspectPortOccupantWithHistory($port))
        );
        return false;
    }

    /**
     * @param array<string, mixed> $inspect
     */
    private function isCurrentInstancePortOccupant(array $inspect): bool
    {
        if (!($inspect['is_weline'] ?? false)) {
            return false;
        }

        $ownScope = MasterProcess::getProjectScopeToken();
        $scope = (string)($inspect['scope'] ?? '');
        if ($scope !== '' && $scope !== $ownScope) {
            return false;
        }

        $instanceName = $this->context?->instanceName ?: 'default';
        $scopedInstance = MasterProcess::getScopedInstanceName($instanceName);
        $pname = \strtolower((string)($inspect['pname'] ?? ''));
        if ($pname === '') {
            return false;
        }

        if (\str_contains($pname, \strtolower($scopedInstance))) {
            return true;
        }

        foreach ($this->getInstanceScopedChildProcessPrefixes($instanceName) as $prefix) {
            if (\str_contains($pname, \strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $inspect
     */
    private function releaseCurrentInstancePortOwner(string $role, int $port, array $inspect): bool
    {
        $pid = (int)($inspect['pid'] ?? 0);
        if ($pid > 0 && (bool)($inspect['pid_running'] ?? false)) {
            Processer::killProcessTreeByPid($pid, true);
        } else {
            Processer::killByProcessNamePrefixes($this->getInstanceScopedRoleProcessPrefixes(
                $role,
                $this->context?->instanceName ?: 'default'
            ));
        }

        $deadline = \microtime(true) + 3.0;
        do {
            Processer::clearPortCache($port);
            if (!Processer::isPortInUse($port)) {
                return true;
            }
            SchedulerSystem::usleep(100000);
        } while (\microtime(true) < $deadline);

        return !Processer::isPortInUse($port);
    }

    /**
     * @return list<string>
     */
    private function getInstanceScopedRoleProcessPrefixes(string $role, string $instanceName): array
    {
        return match ($role) {
            ControlMessage::ROLE_SESSION_SERVER => [
                MasterProcess::buildScopedProcessName('weline-wls-session', $instanceName),
            ],
            ControlMessage::ROLE_MEMORY_SERVER => [
                MasterProcess::buildScopedProcessName('weline-wls-memory', $instanceName),
            ],
            ControlMessage::ROLE_DISPATCHER => [
                MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $instanceName),
            ],
            ControlMessage::ROLE_REDIRECT => [
                MasterProcess::buildScopedProcessName(MasterProcess::HTTP_REDIRECT_PROCESS_NAME, $instanceName),
            ],
            ControlMessage::ROLE_WORKER => [
                MasterProcess::buildScopedProcessName('weline-wls-worker', $instanceName) . '-',
            ],
            ControlMessage::ROLE_MAINTENANCE => [
                MasterProcess::buildScopedProcessName('weline-wls-maintenance', $instanceName) . '-',
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $inspect
     */
    private function describeLaunchPortOccupant(string $role, int $port, array $inspect): string
    {
        $pid = (int)($inspect['pid'] ?? 0);
        $state = (string)($inspect['state'] ?? 'unknown');
        $scope = (string)($inspect['scope'] ?? '');
        $pname = \trim((string)($inspect['pname'] ?? ''));

        return 'role=' . $role
            . ', port=' . $port
            . ', state=' . $state
            . ', pid=' . $pid
            . ', pid_running=' . ((bool)($inspect['pid_running'] ?? false) ? 'yes' : 'no')
            . ', is_weline=' . ((bool)($inspect['is_weline'] ?? false) ? 'yes' : 'no')
            . ($scope !== '' ? ', scope=' . $scope : '')
            . ($pname !== '' ? ', process=' . $pname : '');
    }

    private function shouldUseFastBindProbeForPortChecks(): bool
    {
        if (!$this->isWindowsRuntime()) {
            return false;
        }

        return (bool) ($this->context?->getConfig('wls.orchestrator.fast_bind_probe_port_check', true) ?? true);
    }

    private function isPortFreeByBindProbe(int $port): bool
    {
        if ($port <= 0) {
            return true;
        }

        $host = $this->resolveFastBindProbeHost();
        $addressHost = \str_contains($host, ':') && !\str_starts_with($host, '[')
            ? '[' . $host . ']'
            : $host;

        $errno = 0;
        $errstr = '';
        $socket = @\stream_socket_server(
            'tcp://' . $addressHost . ':' . $port,
            $errno,
            $errstr,
            \STREAM_SERVER_BIND
        );
        if (\is_resource($socket)) {
            @\fclose($socket);
            return true;
        }

        return false;
    }

    private function resolveFastBindProbeHost(): string
    {
        $host = \trim((string) ($this->context?->host ?? '127.0.0.1'));
        if ($host === '' || $host === '*') {
            return '0.0.0.0';
        }
        if ($host === '0.0.0.0' || $host === '::' || \strcasecmp($host, 'localhost') === 0) {
            return $host;
        }
        if (\filter_var($host, \FILTER_VALIDATE_IP)) {
            return $host;
        }

        return '127.0.0.1';
    }

    private function resolveLaunchPortForStart(string $role, int $instanceId, int $configuredPort, ServiceContext $context): ?int
    {
        if ($configuredPort <= 0) {
            return $configuredPort;
        }
        if ($this->bulkLaunchPortCheckActive
            && (bool)($context->getConfig('wls.orchestrator.skip_bulk_launch_port_reprobe', true) ?? true)
            && !$this->requiresStartupPortPreflight($role)
        ) {
            return $configuredPort;
        }
        if ($this->prepareLocalPortForStart($role, $configuredPort)) {
            return $configuredPort;
        }

        WlsLogger::warning_(
            "[Orchestrator] {$role}#{$instanceId} configured port {$configuredPort} is not released; "
            . 'trying emergency port if this role allows it.'
        );

        if (!$this->canUseEmergencyDynamicPort($role, $configuredPort, $context)) {
            return null;
        }

        $emergencyPort = $this->allocateEmergencyDynamicPort($role, $instanceId, $configuredPort, $context);
        if ($emergencyPort <= 0) {
            return null;
        }

        WlsLogger::warning_(
            "[Orchestrator] {$role}#{$instanceId} 配置端口 {$configuredPort} 被旧 WLS 占用，"
            . "本代切换到应急端口 {$emergencyPort} 并后台清理旧端口"
        );

        return $emergencyPort;
    }

    protected function canUseEmergencyDynamicPort(string $role, int $configuredPort, ServiceContext $context): bool
    {
        if (!\in_array($role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
            return false;
        }
        if ($context->mode === 'linux-direct') {
            return false;
        }
        if ($configuredPort <= 0) {
            return false;
        }

        return $this->isPortHeldByWelineSlotOwner($configuredPort);
    }

    protected function allocateEmergencyDynamicPort(string $role, int $instanceId, int $configuredPort, ServiceContext $context): int
    {
        $configuredBase = (int)($context->getConfig('wls.orchestrator.emergency_port_base', 0) ?? 0);
        if ($configuredBase > 0) {
            $start = $configuredBase + \max(0, $instanceId - 1);
        } elseif ($role === ControlMessage::ROLE_MAINTENANCE) {
            $workerCount = $context->getWorkerCount();
            if ($workerCount === 'auto') {
                $workerCount = 16;
            }
            $start = $context->getWorkerBasePort() + (int)$workerCount + 500 + \max(0, $instanceId - 1);
        } else {
            $start = $context->getWorkerBasePort() + 1000 + \max(0, $instanceId - 1);
        }
        $start = \max(1024, $start);
        if ($start === $configuredPort) {
            $start++;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = Processer::findAvailablePort($start + ($attempt * 200), 200);
            if ($port > 0 && $port !== $configuredPort) {
                return $port;
            }
        }

        return 0;
    }

    private function withServiceCommandPort(ServiceCommand $command, int $port): ServiceCommand
    {
        $arguments = $command->arguments;
        if (isset($arguments[1])) {
            $arguments[1] = (string)$port;
        }

        return new ServiceCommand(
            script: $command->script,
            arguments: $arguments,
            environment: $command->environment,
            workingDir: $command->workingDir,
            processName: $command->processName,
            processKind: $command->processKind,
            moduleCode: $command->moduleCode,
        );
    }

    private function markEmergencyDynamicPort(ServiceInstance $instance, int $configuredPort, int $emergencyPort, string $reason): void
    {
        $instance->setMeta('configured_port', $configuredPort);
        $instance->setMeta('emergency_dynamic_port', $emergencyPort);
        $instance->setMeta('emergency_dynamic_port_reason', $reason);
        $instance->setMeta('emergency_dynamic_port_assigned_at', \microtime(true));
    }

    protected function scheduleEmergencyPortCleanup(string $role, int $instanceId, int $configuredPort, string $reason, int $attempt = 1): void
    {
        if ($configuredPort <= 0 || $attempt > 30) {
            return;
        }
        $taskBase = "emergency_port_cleanup:{$role}:{$instanceId}:{$configuredPort}";
        if ($attempt <= 1) {
            foreach (\array_keys($this->mainLoopTasks) as $existingTaskKey) {
                if ($existingTaskKey === $taskBase || \str_starts_with($existingTaskKey, $taskBase . ':')) {
                    return;
                }
            }
        }
        $taskKey = "{$taskBase}:{$attempt}";
        $this->scheduleMainLoopTask($taskKey, 'emergency_port_cleanup', function () use ($role, $instanceId, $configuredPort, $reason, $attempt): void {
            SchedulerSystem::yieldDelay($attempt === 1 ? 500 : 1500);
            if ($this->ensurePortReleasedForResurrection($configuredPort)) {
                WlsLogger::info_("[Orchestrator] 已清理 {$role}#{$instanceId} 应急端口切换遗留配置端口 {$configuredPort}");
                return;
            }
            WlsLogger::warning_(
                "[Orchestrator] {$role}#{$instanceId} 遗留配置端口 {$configuredPort} 仍未释放，继续后台清理"
                . " attempt={$attempt}, reason={$reason}"
            );
            $this->scheduleEmergencyPortCleanup($role, $instanceId, $configuredPort, $reason, $attempt + 1);
        });
    }

    private function appendInstanceIdentityArgs(string $cmd, ServiceInstance $instance): string
    {
        if ($instance->epoch > 0) {
            $cmd .= ' --epoch=' . \escapeshellarg((string)$instance->epoch);
        }
        if ($instance->launchId !== '') {
            $cmd .= ' --launch-id=' . \escapeshellarg($instance->launchId);
        }
        $slotId = $this->getInstanceSlotId($instance);
        $leaseId = $this->getInstanceLeaseId($instance);
        $generation = $this->getInstanceGeneration($instance);
        if ($slotId !== '') {
            $cmd .= ' --slot-id=' . \escapeshellarg($slotId);
        }
        if ($leaseId !== '') {
            $cmd .= ' --lease-id=' . \escapeshellarg($leaseId);
        }
        if ($generation > 0) {
            $cmd .= ' --slot-generation=' . \escapeshellarg((string)$generation);
        }

        return $cmd;
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
        $cmd = $this->appendInstanceIdentityArgs($cmd, $instance);
        $processName = $command->getProcessName();

        if ($processName !== null) {
            $cmd .= ' --name=' . \escapeshellarg($processName);
        }

        WlsLogger::debug_("[Orchestrator] 执行命令: {$cmd}");

        $foreground = $this->shouldLaunchForeground($instance->role, $this->context);
        $argv = $this->buildWindowsDetachedPhpArgvForCommand($command, $instance, $processName);
        if ($argv !== []) {
            $instance->setMeta('spawn_transport', 'windows_detached_php_argv');
            return Processer::createWindowsDetachedPhpArgv(
                $argv,
                $command->getWorkingDir(),
                $cmd
            );
        }

        // 必须 block=false，否则会阻塞 Master 主循环。
        // Windows 前台模式下允许 Worker 打开独立控制台窗口，便于直接观察每个槽位的启动与请求日志。
        $instance->setMeta('spawn_transport', $foreground ? 'processer_create_foreground' : 'processer_create');
        return Processer::create(
            $cmd,
            block: false,
            foreground: $foreground
        );
    }

    /**
     * Windows 后台启动优先走 argv 版 Start-Process：
     * - 避免整段命令行经 shell / PowerShell 再解析带来的慢启动与编码问题
     * - 更快返回 PID，减少 startup Fiber 长时间卡在 spawnProcess 阶段
     *
     * @return list<string>
     */
    private function buildWindowsDetachedPhpArgvForCommand(
        ServiceCommand $command,
        ServiceInstance $instance,
        ?string $processName
    ): array {
        if (!(\defined('IS_WIN') && IS_WIN)) {
            return [];
        }
        if ($this->shouldLaunchForeground($instance->role, $this->context)) {
            return [];
        }
        if ($command->environment !== []) {
            return [];
        }

        $argv = [
            PHP_BINARY,
            $command->getAbsoluteScript(),
            ...\array_map(static fn (mixed $arg): string => (string) $arg, $command->arguments),
        ];

        if ($instance->epoch > 0) {
            $argv[] = '--epoch=' . (string) $instance->epoch;
        }
        if ($instance->launchId !== '') {
            $argv[] = '--launch-id=' . $instance->launchId;
        }
        $slotId = $this->getInstanceSlotId($instance);
        $leaseId = $this->getInstanceLeaseId($instance);
        $generation = $this->getInstanceGeneration($instance);
        if ($slotId !== '') {
            $argv[] = '--slot-id=' . $slotId;
        }
        if ($leaseId !== '') {
            $argv[] = '--lease-id=' . $leaseId;
        }
        if ($generation > 0) {
            $argv[] = '--slot-generation=' . (string)$generation;
        }
        if ($processName !== null && $processName !== '') {
            $argv[] = '--name=' . $processName;
        }

        return $argv;
    }

    private function resolveChildProcessLogFlag(ServiceProviderInterface $provider, ServiceContext $context): ?bool
    {
        if ($context->windowMode || $provider->requiresStartupReadyBarrier() || $provider->isCriticalRole()) {
            return true;
        }

        return null;
    }

    private function shouldLaunchForeground(string $role, ?ServiceContext $context): bool
    {
        if ($context === null || !$context->windowMode) {
            return false;
        }

        if ($this->isWindowsRuntime()) {
            // Windows 下前台子进程拉起成本较高，默认强制走后台 detached。
            // server:start --frontend 会通过 buildOrchestratorRuntimeOptions 写入 allow_windows_frontend_child_process
            // 与 frontend_worker_windows；批量脚本已不等待 PID/重定向输出，因此启动批次也可以显示子窗口。
            $allowWindowsFrontendChildProcess = (bool) ($context->getConfig(
                'wls.orchestrator.allow_windows_frontend_child_process',
                false
            ) ?? false);
            if (!$allowWindowsFrontendChildProcess) {
                return false;
            }

            if (\in_array($role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
                return (bool) ($context->getConfig('wls.orchestrator.frontend_worker_windows', false) ?? false);
            }

            return (bool) ($context->getConfig('wls.orchestrator.frontend_non_worker_windows', false) ?? false);
        }

        if ($this->childServicesBootstrapInProgress) {
            return false;
        }

        if (\in_array($role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
            return false;
        }

        return (bool) ($context->getConfig('wls.orchestrator.frontend_non_worker_unix', false) ?? false);
    }

    protected function isWindowsRuntime(): bool
    {
        return \defined('IS_WIN')
            ? (bool) IS_WIN
            : (\DIRECTORY_SEPARATOR === '\\' || \strncasecmp(\PHP_OS, 'WIN', 3) === 0);
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

    private function buildSlotId(string $role, int $instanceId): string
    {
        return "{$role}#{$instanceId}";
    }

    private function assignSlotLeaseMetadata(ServiceInstance $instance, ?int $generation = null): void
    {
        $slotId = $this->buildSlotId($instance->role, $instance->instanceId);
        $generation = $generation !== null && $generation > 0
            ? $generation
            : $this->nextSlotGeneration($instance->role, $instance->instanceId);
        $this->rememberSlotGeneration($slotId, $generation);
        $leaseId = $instance->launchId !== '' ? $instance->launchId : $slotId . '-l' . $generation;
        $instance->setMeta('slot_id', $slotId);
        $instance->setMeta('lease_id', $leaseId);
        $instance->setMeta('generation', $generation);
        $instance->setMeta('lease_state', 'launching');
        $instance->setMeta('last_known_pid_set', $instance->getManagedPids());
    }

    private function nextSlotGeneration(string $role, int $instanceId): int
    {
        $slotId = $this->buildSlotId($role, $instanceId);
        $runtimeFloor = $this->getRuntimeSlotGenerationFloor($slotId);
        $persistentNext = $this->allocatePersistentSlotGeneration($slotId, $runtimeFloor);
        if ($persistentNext !== null) {
            $this->slotGenerationFloor[$slotId] = \max($runtimeFloor + 1, $persistentNext);

            return $this->slotGenerationFloor[$slotId];
        }

        $next = \max($runtimeFloor, $this->readPersistedSlotGenerationFloor($slotId)) + 1;
        $this->slotGenerationFloor[$slotId] = $next;

        return $next;
    }

    private function getRuntimeSlotGenerationFloor(string $slotId): int
    {
        $floor = (int)($this->slotGenerationFloor[$slotId] ?? 0);
        foreach ($this->registry->getAllInstances() as $instance) {
            if ($this->getInstanceSlotId($instance) !== $slotId) {
                continue;
            }
            $floor = \max($floor, (int)($instance->getMeta('generation') ?? 0));
        }

        return $floor;
    }

    private function rememberSlotGeneration(string $slotId, int $generation): void
    {
        if ($slotId === '' || $generation <= 0) {
            return;
        }
        $current = (int)($this->slotGenerationFloor[$slotId] ?? 0);
        if ($generation <= $current) {
            return;
        }
        $this->slotGenerationFloor[$slotId] = $generation;
        $this->persistSlotGenerationFloor($slotId, $generation);
    }

    private function allocatePersistentSlotGeneration(string $slotId, int $minimumFloor): ?int
    {
        $file = $this->getSlotGenerationStoreFile();
        if ($file === null) {
            return null;
        }

        $allocated = 0;
        $updated = ServerInstanceManager::updateJsonFileAtomically(
            $file,
            static function (array $data) use ($slotId, $minimumFloor, &$allocated): array {
                $generations = \is_array($data[self::SLOT_GENERATIONS_KEY] ?? null)
                    ? $data[self::SLOT_GENERATIONS_KEY]
                    : [];
                $floor = \max(
                    $minimumFloor,
                    (int)($generations[$slotId] ?? 0),
                    self::extractPersistedSlotGenerationFloor($data, $slotId)
                );
                $allocated = $floor + 1;
                $generations[$slotId] = $allocated;
                $data[self::SLOT_GENERATIONS_KEY] = $generations;
                $data['slot_generations_updated_at'] = \date('Y-m-d H:i:s');

                return $data;
            }
        );

        return $updated && $allocated > 0 ? $allocated : null;
    }

    private function persistSlotGenerationFloor(string $slotId, int $generation): void
    {
        $file = $this->getSlotGenerationStoreFile();
        if ($file === null) {
            return;
        }

        ServerInstanceManager::updateJsonFileAtomically(
            $file,
            static function (array $data) use ($slotId, $generation): array {
                $generations = \is_array($data[self::SLOT_GENERATIONS_KEY] ?? null)
                    ? $data[self::SLOT_GENERATIONS_KEY]
                    : [];
                $generations[$slotId] = \max((int)($generations[$slotId] ?? 0), $generation);
                $data[self::SLOT_GENERATIONS_KEY] = $generations;
                $data['slot_generations_updated_at'] = \date('Y-m-d H:i:s');

                return $data;
            }
        );
    }

    private function readPersistedSlotGenerationFloor(string $slotId): int
    {
        if ($this->context === null || $slotId === '') {
            return 0;
        }
        $data = (new ServerInstanceManager())->getRawInstanceData($this->context->instanceName);
        if ($data === null) {
            return 0;
        }

        return self::extractPersistedSlotGenerationFloor($data, $slotId);
    }

    private function getSlotGenerationStoreFile(): ?string
    {
        if ($this->context === null || $this->context->instanceName === '') {
            return null;
        }
        $manager = new ServerInstanceManager();
        $file = $manager->getInstanceFile($this->context->instanceName);
        if (!\is_file($file)) {
            return null;
        }

        return $file;
    }

    private static function extractPersistedSlotGenerationFloor(array $data, string $slotId): int
    {
        $floor = 0;
        $generations = \is_array($data[self::SLOT_GENERATIONS_KEY] ?? null)
            ? $data[self::SLOT_GENERATIONS_KEY]
            : [];
        $floor = \max($floor, (int)($generations[$slotId] ?? 0));

        return $floor;
    }

    private function getInstanceSlotId(ServiceInstance $instance): string
    {
        $slotId = (string)($instance->getMeta('slot_id') ?? '');
        return $slotId !== '' ? $slotId : $this->buildSlotId($instance->role, $instance->instanceId);
    }

    private function getInstanceLeaseId(ServiceInstance $instance): string
    {
        $leaseId = (string)($instance->getMeta('lease_id') ?? '');
        if ($leaseId !== '') {
            return $leaseId;
        }
        return $instance->launchId;
    }

    private function getInstanceGeneration(ServiceInstance $instance): int
    {
        $generation = (int)($instance->getMeta('generation') ?? 0);
        if ($generation > 0) {
            return $generation;
        }
        return \max(1, $instance->epoch);
    }

    private function isCurrentLeaseIdentity(ServiceInstance $instance, string $slotId, string $leaseId, int $generation): bool
    {
        $hasLeaseMetadata = $instance->getMeta('lease_id', null) !== null
            || $instance->getMeta('slot_id', null) !== null
            || $instance->getMeta('generation', null) !== null;
        if (!$hasLeaseMetadata) {
            // Hand-built legacy test instances without lease metadata remain compatible.
            return true;
        }
        $expectedLeaseId = $this->getInstanceLeaseId($instance);
        if ($slotId === '' || $leaseId === '' || $generation <= 0) {
            return false;
        }

        return $slotId === $this->getInstanceSlotId($instance)
            && $leaseId === $expectedLeaseId
            && $generation === $this->getInstanceGeneration($instance);
    }

    /**
     * @return array<string, int|string>
     */
    private function buildWorkerDescriptor(ServiceInstance $instance, string $state = ''): array
    {
        return [
            'role' => $instance->role,
            'slot_id' => $this->getInstanceSlotId($instance),
            'lease_id' => $this->getInstanceLeaseId($instance),
            'generation' => $this->getInstanceGeneration($instance),
            'port' => (int)($instance->port ?? 0),
            'state' => $state !== '' ? $state : $instance->state,
        ];
    }

    /**
     * 停止所有服务（实例级停机：可选 Dispatcher 排水、共享服务卸载令牌、非共享进程并发终止）
     *
     * 流程：
     * 1. 需要排水时，仅通知 Dispatcher 排水
     * 2. 等待 Dispatcher 排水完成
     * 3. 共享服务通过 token-scoped IPC 卸载本实例 consumer；非共享进程并发 kill 进程树
     * 4. 校验非共享进程退出并清理本实例元数据
     * 5. 冲刷出站缓冲后关闭 IPC 服务器（stop_ipc_flush_before_close_sec）
     * @param string $reason 停止原因
     * @param int|null $progressClientId 发送进度消息的客户端 ID（用于 CLI stop 命令）
     */
    public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
    {
        // P2 观测性埋点：记录 stopAll 触发次数（幂等-保护的 "already in progress" 分支不计入）。
        // 便于运维观察是否被异常/重复信号频繁触发（例如 Ctrl+C 重按、上层 supervisor 抖动）。
        if (!$this->stopAllInProgress && !$this->shuttingDown) {
            \Weline\Server\Observability\MetricsRegistry::inc('orchestrator.stop_all.invoked');
        }

        if ($this->stopAllInProgress || $this->shuttingDown) {
            $this->sendStopAlreadyInProgress($progressClientId);
            WlsLogger::warning_("[Orchestrator] 已在停机流程中，忽略重复 stopAll 请求，原因: {$reason}，阶段: {$this->stopStage}");
            return;
        }

        $this->pendingStopReason = null;
        $this->pendingStopSkipDrain = false;
        $this->pendingStopProgressClientId = null;
        $this->stopAllInProgress = true;
        $this->shuttingDown = true;
        $this->masterShutdownIntent = true;
        $this->stopProgressClientId = $progressClientId;
        $skipDrain = $this->shouldSkipStopAllDrain();
        WlsLogger::info_("[Orchestrator] 开始停止所有服务，原因: {$reason}, skip_drain=" . ($skipDrain ? '1' : '0'));
        $this->appendStopTraceLine('stop_all_start', ['reason' => $reason]);

        $totalInstances = \count($this->registry->getAllInstances());
        if ($totalInstances === 0) {
            WlsLogger::info_('[Orchestrator] 无运行中的实例');
            $this->sendStopProgress('无运行中的实例');
            $this->setStopStage(self::STOP_STAGE_COMPLETE);
            $this->appendStopTraceLine('complete', ['reason' => $reason, 'empty' => true]);
            $this->running = false;
            $this->closeIpcServer('stop_all:no_running_instances');
            $this->finalizeStopAllMasterExit();
            return;
        }

        // 构建实例清单
        $instanceList = [];
        foreach ($this->registry->getAllInstances() as $inst) {
            $provider = $this->registry->getProvider($inst->role);
            $displayName = $provider?->getDisplayName() ?? $inst->role;
            $instanceList[] = "{$displayName}(PID:{$this->getInstanceTrackingPid($inst)})";
        }
        $this->sendStopProgress("共 {$totalInstances} 个实例待停止: " . \implode(', ', $instanceList));

        if ($skipDrain) {
            WlsLogger::info_('[Orchestrator] -f stop: skip DRAIN and WAIT_DRAIN');
            $this->sendStopProgress('-f 强制模式：跳过排水，直接进入统一终止');
        } else {
            // ========== 阶段 1：通知 Dispatcher 排水 ==========
            $this->setStopStage(self::STOP_STAGE_DRAIN);
            WlsLogger::info_('[Orchestrator] 阶段1: 通知 Dispatcher 排水');
            $this->sendStopProgress('阶段1/5: 通知 Dispatcher 排水 - 停止派发新请求');
            $dispatcherDrainTargets = $this->broadcastDrainToDispatcherForStop();

            // ========== 阶段 2：等待 Dispatcher 排水完成（默认 10s，可配 wls.orchestrator.stop_all_drain_wait_sec）==========
            $this->setStopStage(self::STOP_STAGE_WAIT_DRAIN);
            WlsLogger::info_('[Orchestrator] 阶段2: 等待 Dispatcher 排水完成');
            $this->sendStopProgress('阶段2/5: 等待 Dispatcher 排水完成');
            $stopDrainWait = (float) ($this->context?->getConfig('wls.orchestrator.stop_all_drain_wait_sec', 2.0) ?? 2.0);
            if ($stopDrainWait < 1.0) {
                $stopDrainWait = 1.0;
            }
            if ($stopDrainWait > 30.0) {
                $stopDrainWait = 30.0;
            }
            if ($dispatcherDrainTargets > 0 && $this->waitForAllDrained($stopDrainWait, true)) {
                $this->sendStopProgress('Dispatcher 排水完成，进入实例终止阶段');
            } elseif ($dispatcherDrainTargets > 0) {
                $this->sendStopProgress('Dispatcher 排水等待结束（超时），进入实例终止阶段');
            } else {
                $this->sendStopProgress('未发现可排水 Dispatcher，直接进入实例终止阶段');
            }
        }

        // ========== 阶段 3：通知共享服务卸载令牌 + 非共享进程并发终止 ==========
        $this->setStopStage(self::STOP_STAGE_SHUTDOWN);
        WlsLogger::info_('[Orchestrator] 阶段3: 通知共享服务卸载令牌并终止非共享进程');
        $this->sendStopProgress($skipDrain
            ? '阶段1/3: 通知共享服务卸载令牌，并发终止非共享进程'
            : '阶段3/5: 通知共享服务卸载令牌，并发终止非共享进程'
        );
        $this->releaseSharedStateConsumersForStopFlow();
        $this->terminateAllAfterDrain();
        if (!$skipDrain) {
            $this->waitForServiceIpcDisconnectAfterShutdown();
        }

        // ========== 阶段 4：校验并强制杀死残留非共享进程 ==========
        $this->setStopStage(self::STOP_STAGE_VERIFY);
        WlsLogger::info_('[Orchestrator] 阶段4: 校验非共享进程退出状态');
        $this->sendStopProgress($skipDrain
            ? '阶段2/3: 校验非共享进程退出状态'
            : '阶段4/5: 校验非共享进程退出状态'
        );
        $this->verifyAndKillRemainingProcesses();

        // ========== 阶段 5：关闭 IPC 服务器 ==========
        $this->setStopStage(self::STOP_STAGE_CLOSE_IPC);
        WlsLogger::info_('[Orchestrator] 阶段5: 关闭 IPC 服务器');
        $this->sendStopProgress($skipDrain
            ? '阶段3/3: 关闭 IPC 服务器'
            : '阶段5/5: 关闭 IPC 服务器'
        );
        
        // 不提前移除 Master PID 索引，避免外部将"索引消失"误判为"进程已退出"。
        // 索引交由 Master 进程最终退出阶段统一清理。
        $this->sendStopProgress('所有子进程已完整退出，Master 即将退出进程');
        
        // 先设置状态，再关闭 IPC（关闭后无法再发送消息）
        $this->running = false;
        WlsLogger::info_('[Orchestrator] 所有服务已停止');
        $this->setStopStage(self::STOP_STAGE_COMPLETE);
        $this->appendStopTraceLine('complete', ['reason' => $reason, 'skip_drain' => $skipDrain]);
        
        // 最后关闭 IPC
        $this->closeIpcServer('stop_all:' . $reason);
        $this->finalizeStopAllMasterExit();
    }

    protected function finalizeStopAllMasterExit(): void
    {
        WlsLogger::info_('[Orchestrator] Stop flow complete, Master main loop will exit');
        $this->running = false;
        $this->cancelMainLoopTasksForMasterExit();
        $this->cleanupMasterPidIndex();
    }

    protected function shouldSkipStopAllDrain(): bool
    {
        return $this->stopAllSkipDrain;
    }

    /**
     * 重复终止信号或停机卡死时的兜底：按注册表 PID 批量强杀子进程后关闭 IPC 并退出 Master。
     * 不依赖正在进行的优雅停机流程是否可恢复。
     */
    public function forceTerminateMasterAndChildren(string $reason = 'force'): void
    {
        WlsLogger::warning_("[Orchestrator] 强制终止 Master 及子进程，原因: {$reason}，阶段: {$this->stopStage}");
        try {
            $this->releaseSharedStateConsumersForStopFlow();
        } catch (\Throwable $throwable) {
            WlsLogger::warning_('[Orchestrator] 强制停机前共享服务令牌卸载异常: ' . $throwable->getMessage());
        }
        $pids = [];
        foreach ($this->registry->getAllInstances() as $instance) {
            $trackingPid = $this->getInstanceTrackingPid($instance);
            if ($trackingPid > 0 && !$this->isSharedStateServiceInstance($instance)) {
                $pids[$trackingPid] = $trackingPid;
            }
        }

        if ($this->controlServer !== null && $this->shouldForceTerminateNotifyIpcClients()) {
            try {
                $this->broadcastShutdownToAll();
                $quickFlush = (float) ($this->context?->getConfig('wls.orchestrator.force_stop_ipc_flush_sec', 0.05) ?? 0.05);
                if ($quickFlush > 0.0) {
                    $this->controlServer->flushPendingWrites(\min(0.2, \max(0.01, $quickFlush)));
                }
            } catch (\Throwable $e) {
                WlsLogger::warning_('[Orchestrator] 强制停机前 IPC 通知失败: ' . $e->getMessage());
            }
        } elseif ($this->controlServer !== null) {
            WlsLogger::info_('[Orchestrator] 强制停机前跳过 IPC 广播：无已连接的服务 IPC 客户端');
        }

        if ($pids !== []) {
            $killResult = $this->forceStopRemainingProcesses(\array_values($pids));
            if (!empty($killResult['remaining'])) {
                WlsLogger::warning_(
                    '[Orchestrator] 强制停机仍有残留进程: ' . \implode(',', $killResult['remaining'])
                );
            }
        }

        $this->cleanupSubmittedCurrentInstanceProcesses($reason);

        $this->closeIpcServer(
            'force_terminate:' . $reason,
            (float) ($this->context?->getConfig('wls.orchestrator.force_stop_close_ipc_flush_sec', 0.01) ?? 0.01)
        );
        $this->finalizeForceTerminateMasterExit(2);
    }

    protected function shouldForceTerminateNotifyIpcClients(): bool
    {
        return ($this->controlServer?->countServiceClients($this->stopProgressClientId) ?? 0) > 0;
    }

    protected function finalizeForceTerminateMasterExit(int $exitCode): void
    {
        $this->running = false;
        $this->shuttingDown = true;
        $this->cancelMainLoopTasksForMasterExit();
        $this->cleanupMasterPidIndex();
    }

    /**
     * stopAll 异常时解除卡死的停机标志，避免主循环无法再次处理停止请求。
     */
    private function resetStopFlowFlagsAfterStopAllFailure(): void
    {
        $this->stopAllInProgress = false;
        $this->shuttingDown = false;
        $this->pendingStopReason = null;
        $this->pendingStopSkipDrain = false;
        $this->stopAllSkipDrain = false;
        $this->pendingStopProgressClientId = null;
        $this->stopProgressClientId = null;
        $this->activeStopTraceId = '';
        $this->setStopStage(self::STOP_STAGE_IDLE);
        $this->masterShutdownIntent = false;
        $this->ipcReleaseExclusive();
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
     * Master 侧 STOP 链路审计（JSON Lines）
     *
     * @param array<string, mixed> $extra
     */
    private function appendStopTraceLine(string $event, array $extra = []): void
    {
        if ($this->context === null) {
            return;
        }

        $dir = Env::VAR_DIR . 'server' . \DIRECTORY_SEPARATOR . 'control' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $instance = $this->context->instanceName;
        $file = $dir . $instance . '.stop.trace.jsonl';
        $row = [
            'ts' => \microtime(true),
            'trace' => $this->activeStopTraceId,
            'instance' => $instance,
            'event' => $event,
        ];
        foreach ($extra as $k => $v) {
            $row[$k] = $v;
        }

        @\file_put_contents($file, \json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", \FILE_APPEND | \LOCK_EX);
    }
    
    /**
     * 发送停止进度消息给 CLI 客户端
     */
    private function sendStopProgress(string $message): bool
    {
        if ($this->stopProgressClientId === null || $this->controlServer === null) {
            WlsLogger::warning_("[Orchestrator] stop progress skipped: no progress client. message={$message}");
            return false;
        }

        $mid = $this->activeStopTraceId;
        $ok = $this->controlServer->sendTo(
            $this->stopProgressClientId,
            ControlMessage::commandResult(
                true,
                ['stage' => $this->stopStage, 'state' => 'stopping'],
                $message,
                $mid
            )
        );

        if (!$ok) {
            WlsLogger::warning_("[Orchestrator] stop progress send failed: client={$this->stopProgressClientId}, message={$message}");
        }

        return $ok;
    }

    private function scheduleSharedStateConsumerRenewalIfReady(float $now): void
    {
        if ($this->context === null || $this->masterShutdownIntent || $this->shuttingDown || $this->stopAllInProgress) {
            return;
        }
        if (($now - $this->lastSharedConsumerRenewAt) < $this->sharedConsumerRenewIntervalSec) {
            return;
        }
        if ($this->hasMainLoopTask('periodic:shared_consumer_renew')) {
            return;
        }

        $consumerCode = \trim((string) $this->context->instanceName);
        if ($consumerCode === '') {
            return;
        }

        $roles = $this->getReadySharedStateConsumerRenewalRoles($this->context);
        if ($roles === []) {
            return;
        }

        $this->lastSharedConsumerRenewAt = $now;
        $this->scheduleMainLoopTask(
            'periodic:shared_consumer_renew',
            'shared_consumer_renew',
            function () use ($consumerCode, $roles): void {
                $this->renewSharedStateConsumersForWorkers($consumerCode, $roles);
            }
        );
    }

    /**
     * @return list<string>
     */
    private function getReadySharedStateConsumerRenewalRoles(ServiceContext $context): array
    {
        $roles = [];
        foreach ([ControlMessage::ROLE_SESSION_SERVER, ControlMessage::ROLE_MEMORY_SERVER] as $role) {
            $provider = $this->registry->getProvider($role);
            if ($provider !== null && !$provider->isEnabled($context)) {
                continue;
            }
            if (!$this->hasReadySharedStateServiceRole($role)) {
                return [];
            }

            $roles[] = $role;
        }

        return $roles;
    }

    private function hasReadySharedStateServiceRole(string $role): bool
    {
        foreach ($this->registry->getInstancesByRole($role) as $instance) {
            if ($instance->state === ServiceInstance::STATE_READY) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $roles
     */
    private function renewSharedStateConsumersForWorkers(string $consumerCode, array $roles): void
    {
        $results = $this->renewSharedStateConsumersForWorkersInstance($consumerCode, $roles);
        $displayNames = [
            ControlMessage::ROLE_SESSION_SERVER => 'Session Server',
            ControlMessage::ROLE_MEMORY_SERVER => 'Memory Service',
        ];

        $failed = [];
        foreach ($roles as $role) {
            $renewed = (bool) ($results[$role] ?? false);
            $displayName = $displayNames[$role] ?? $role;
            if ($renewed) {
                continue;
            }

            $failed[] = $displayName;
        }

        if ($failed !== []) {
            WlsLogger::warning_(
                '[Orchestrator] 共享服务 consumer token 异步续约失败: '
                . \implode(', ', $failed)
                . ", consumer={$consumerCode}"
            );
            return;
        }

        if (!$this->sharedConsumerRenewLogged) {
            WlsLogger::info_(
                '[Orchestrator] 共享服务 consumer token 已由 Master 主循环异步续约: '
                . \implode(', ', \array_map(static fn (string $role): string => $displayNames[$role] ?? $role, $roles))
                . ", consumer={$consumerCode}"
            );
            $this->sharedConsumerRenewLogged = true;
        }
    }

    /**
     * @param list<string> $roles
     * @return array<string, bool>
     */
    protected function renewSharedStateConsumersForWorkersInstance(string $consumerCode, array $roles): array
    {
        try {
            return (new SharedStateServiceManager())->renewInstanceConsumers($consumerCode, $roles);
        } catch (\Throwable $throwable) {
            WlsLogger::warning_('[Orchestrator] 共享服务 consumer token 异步续约异常: ' . $throwable->getMessage());

            return [
                ControlMessage::ROLE_SESSION_SERVER => false,
                ControlMessage::ROLE_MEMORY_SERVER => false,
            ];
        }
    }

    private function releaseSharedStateConsumersForStopFlow(): void
    {
        $consumerCode = \trim((string) ($this->context?->instanceName ?? ''));
        if ($consumerCode === '') {
            WlsLogger::warning_('[Orchestrator] 跳过共享服务令牌卸载：当前实例名为空');
            $this->sendStopProgress('跳过共享服务令牌卸载：当前实例名为空');
            return;
        }

        $results = $this->releaseSharedStateConsumersForStop($consumerCode);
        $displayNames = [
            ControlMessage::ROLE_SESSION_SERVER => 'Session Server',
            ControlMessage::ROLE_MEMORY_SERVER => 'Memory Service',
        ];

        foreach ([ControlMessage::ROLE_SESSION_SERVER, ControlMessage::ROLE_MEMORY_SERVER] as $role) {
            $ack = (bool) ($results[$role] ?? false);
            $displayName = $displayNames[$role] ?? $role;
            if ($ack) {
                $message = "已通知 {$displayName} 自行卸载 consumer token，后续由共享服务自治退出";
                WlsLogger::info_('[Orchestrator] ' . $message);
                $this->sendStopProgress('  ✓ ' . $message);
                continue;
            }

            $message = "{$displayName} consumer token 卸载通知未确认，继续终止本实例非共享进程";
            WlsLogger::warning_('[Orchestrator] ' . $message);
            $this->sendStopProgress('  ! ' . $message);
        }
    }

    /**
     * @return array<string, bool>
     */
    protected function releaseSharedStateConsumersForStop(string $consumerCode): array
    {
        try {
            return (new SharedStateServiceManager())->releaseInstanceConsumers($consumerCode);
        } catch (\Throwable $throwable) {
            WlsLogger::warning_('[Orchestrator] 共享服务令牌卸载失败: ' . $throwable->getMessage());

            return [
                ControlMessage::ROLE_SESSION_SERVER => false,
                ControlMessage::ROLE_MEMORY_SERVER => false,
            ];
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
            if ($this->isSharedStateServiceInstance($instance)) {
                continue;
            }
            $provider = $this->registry->getProvider($instance->role);
            if ($provider === null || !$provider->supportsDrain()) {
                continue;
            }
            $instance->state = ServiceInstance::STATE_DRAINING;
            $this->registry->updateInstance($instance);
            $connectedClients[] = "{$instance->role}#{$instance->instanceId}(ipc:{$instance->ipcClientId})";
            // stopAll / stopChildProcesses use a global drain. Passing per-instance ports here
            // causes Dispatcher to interpret the request as a selective worker drain and never
            // report global draining_complete.
            $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::drain([]));
        }
        
        if (!empty($connectedClients)) {
            WlsLogger::info_('[IPC] DRAIN -> ' . \implode(', ', $connectedClients));
        }
    }

    /**
     * stopAll 只让 Dispatcher 进入排水；业务 Worker 后续由进程树并发终止。
     */
    private function broadcastDrainToDispatcherForStop(): int
    {
        $connectedClients = [];
        if ($this->controlServer === null) {
            return 0;
        }

        foreach ($this->registry->getInstancesByRole(ControlMessage::ROLE_DISPATCHER) as $instance) {
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
            $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::drain([]));
        }

        if ($connectedClients !== []) {
            WlsLogger::info_('[IPC] DRAIN Dispatcher -> ' . \implode(', ', $connectedClients));
        } else {
            WlsLogger::info_('[IPC] DRAIN Dispatcher -> (无已连接的 Dispatcher IPC 客户端)');
        }

        return \count($connectedClients);
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
            if ($this->isSharedStateServiceInstance($instance)) {
                continue;
            }
            $provider = $this->registry->getProvider($instance->role);
            if ($provider === null || !$provider->supportsShutdown()) {
                continue;
            }
            $connectedClients[] = "{$instance->role}#{$instance->instanceId}(pid:{$this->getInstanceTrackingPid($instance)},ipc:{$instance->ipcClientId})";
            $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::shutdown());
        }
        
        if (!empty($connectedClients)) {
            WlsLogger::info_('[IPC] SHUTDOWN -> ' . \implode(', ', $connectedClients));
        } else {
            WlsLogger::info_('[IPC] SHUTDOWN -> (无已连接的 IPC 客户端)');
        }
    }

    /**
     * 排水完成后并行终止全部子进程，避免逐个等待导致停机过慢。
     */
    private function terminateAllAfterDrain(): void
    {
        $candidatePids = [];
        $pidToInstance = [];
        $pidListForLog = [];
        foreach ($this->registry->getAllInstances() as $instance) {
            $trackingPid = $this->getInstanceTrackingPid($instance);
            if ($trackingPid <= 0 || $this->isSharedStateServiceInstance($instance)) {
                continue;
            }

            $candidatePids[$trackingPid] = $trackingPid;
            $pidToInstance[$trackingPid] = $instance;
            $provider = $this->registry->getProvider($instance->role);
            $displayName = $provider?->getDisplayName() ?? $instance->role;
            $pidListForLog[] = "{$displayName}(PID:{$trackingPid})";
            if ($instance->ipcClientId !== null) {
                $instance->state = ServiceInstance::STATE_STOPPING;
                $this->registry->updateInstance($instance);
                $this->controlServer?->sendTo($instance->ipcClientId, ControlMessage::shutdown());
            }
        }

        if ($candidatePids === []) {
            $this->sendStopProgress('无非共享进程需要并发终止');
            return;
        }

        $this->sendStopProgress('已向非共享进程发送 SHUTDOWN: ' . \implode(', ', $pidListForLog));
        WlsLogger::warning_('[Orchestrator] SHUTDOWN dispatched to non-shared processes: ' . \implode(',', \array_values($candidatePids)));
        $result = $this->forceStopRemainingProcesses(\array_values($candidatePids));
        foreach ($pidToInstance as $instance) {
            if ($instance->ipcClientId !== null) {
                $this->closeStopFlowClient($instance->ipcClientId);
                $instance->ipcClientId = null;
            }
            $instance->state = ServiceInstance::STATE_STOPPED;
            $this->registry->updateInstance($instance);
        }
        if (($result['killed'] ?? 0) > 0) {
            $this->sendStopProgress("  鉁?宸插苟鍙戠粓姝?{$result['killed']} 涓潪鍏变韩杩涚▼");
        }
        $this->yieldControlPlane(150000);
    }

    /**
     * 等待所有实例排水完成
     * 
     * @param float $timeout 超时时间（秒）
     * @param bool $reportProgress 是否报告进度
     */
    private function waitForAllDrained(float $timeout, bool $reportProgress = false): bool
    {
        $start = \microtime(true);
        $drainedInstances = [];
        $lastHeartbeatAt = 0.0;
        $heartbeatInterval = 5.0;
        
        while (\microtime(true) - $start < $timeout) {
            $this->controlServer?->poll(0, 100000);

            $drainingCount = 0;
            foreach ($this->registry->getAllInstances() as $instance) {
                $key = "{$instance->role}#{$instance->instanceId}";
                
                $trackingPid = $this->getInstanceTrackingPid($instance);
                if ($instance->state === ServiceInstance::STATE_DRAINING) {
                    $drainingCount++;
                } elseif ($instance->state !== ServiceInstance::STATE_DRAINING 
                          && !isset($drainedInstances[$key])
                          && $reportProgress) {
                    // 实例已完成排水
                    $drainedInstances[$key] = true;
                    $provider = $this->registry->getProvider($instance->role);
                    $displayName = $provider?->getDisplayName() ?? $instance->role;
                    $msg = "  ✓ {$displayName}(PID:{$trackingPid}) 排水完成";
                    $this->sendStopProgress($msg);
                }
            }

            if ($reportProgress && $drainingCount > 0) {
                $now = \microtime(true);
                if (($now - $lastHeartbeatAt) >= $heartbeatInterval) {
                    $elapsed = (int)\round($now - $start);
                    $this->sendStopProgress("Stage2 waiting for drain: {$drainingCount} remaining ({$elapsed}s/{$timeout}s)");
                    $lastHeartbeatAt = $now;
                }
            }

            if ($drainingCount === 0) {
                WlsLogger::info_('[Orchestrator] 所有实例排水完成');
                if ($reportProgress) {
                    $this->sendStopProgress('所有子进程排水完成');
                }
                return true;
            }
        }
        WlsLogger::warning_("[Orchestrator] 等待排水超时 ({$timeout}s)");
        if ($reportProgress) {
            $this->sendStopProgress("等待排水超时 ({$timeout}s)，继续执行停止流程");
        }

        return false;
    }

    /**
     * 停机阶段 4：等待子服务 IPC 断开（不含 control/CLI），超时见 wls.orchestrator.stop_all_ipc_disconnect_wait_sec。
     */
    private function waitForServiceIpcDisconnectAfterShutdown(): void
    {
        $timeout = (float) ($this->context?->getConfig('wls.orchestrator.stop_all_ipc_disconnect_wait_sec', 2.0) ?? 2.0);
        if ($timeout < 0.5) {
            $timeout = 0.5;
        }
        if ($timeout > 15.0) {
            $timeout = 15.0;
        }
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
        $lastHeartbeatAt = 0.0;
        $heartbeatInterval = 5.0;

        while (\microtime(true) - $start < $timeout) {
            $this->pollStopFlowIpc(0, 100000);

            $clientCount = $this->controlServer?->countServiceClients($this->stopProgressClientId) ?? 0;

            if ($clientCount > 0) {
                $now = \microtime(true);
                if (($now - $lastHeartbeatAt) >= $heartbeatInterval) {
                    $elapsed = (int)\round($now - $start);
                    $this->sendStopProgress("阶段4: 仍有 {$clientCount} 条子服务 IPC 连接，已等待 {$elapsed}s / {$timeout}s");
                    $lastHeartbeatAt = $now;
                }
            }

            // 检查每个实例的退出状态
            foreach ($this->registry->getAllInstances() as $instance) {
                $key = "{$instance->role}#{$instance->instanceId}";
                if (isset($exitedInstances[$key])) {
                    continue;
                }
                
                // 检查进程是否已退出
                $trackingPid = $this->getInstanceTrackingPid($instance);
                if ($instance->state === ServiceInstance::STATE_STOPPED && $instance->ipcClientId !== null) {
                    $exitedInstances[$key] = true;
                    $provider = $this->registry->getProvider($instance->role);
                    $displayName = $provider?->getDisplayName() ?? $instance->role;
                    $msg = "  ✓ {$displayName}(PID:{$trackingPid}) 已退出";
                    WlsLogger::info_("[Orchestrator] {$msg}");
                    $this->sendStopProgress($msg);
                    // 进程已退出，主动关闭 IPC 连接，避免超时等待
                    if ($instance->ipcClientId !== null && $this->controlServer !== null) {
                        $this->controlServer->closeClient($instance->ipcClientId);
                    }
                }
            }

            // 只在子服务连接数变化时记录日志
            if ($clientCount !== $lastClientCount) {
                if ($clientCount > 0) {
                    WlsLogger::info_("[IPC] 等待子服务断开: 剩余 {$clientCount} 条连接...");
                }
                $lastClientCount = $clientCount;
            }

            if ($clientCount === 0) {
                $elapsed = \round((\microtime(true) - $start) * 1000);
                WlsLogger::info_("[IPC] 所有子服务 IPC 已断开 ({$elapsed}ms)");
                $this->sendStopProgress('阶段4完成: 全部子服务 IPC 已断开');
                return;
            }
            // 下一轮循环顶部 pollStopFlowIpc 即等待；此处不再叠原生 usleep
        }
        $remainingCount = $this->controlServer?->countServiceClients($this->stopProgressClientId) ?? 0;
        WlsLogger::warning_("[IPC] 等待子服务断开超时 ({$timeout}s)，剩余 {$remainingCount} 条连接");
    }

    /**
     * 校验并强制杀死残留进程（带进度报告）
     *
     * 使用 Processer::batchGracefulKill() 批量停止，比逐个停止更高效
     */
    private function verifyAndKillRemainingProcesses(): void
    {
        $allInstances = $this->registry->getAllInstances();
        $nonSharedInstances = [];
        $pidToInstance = [];
        $connectedVerificationPids = [];
        $immediateForceKillPids = [];

        foreach ($allInstances as $instance) {
            $trackingPid = $this->getInstanceTrackingPid($instance);
            if ($this->isSharedStateServiceInstance($instance)) {
                continue;
            }
            if ($instance->state === ServiceInstance::STATE_STOPPED) {
                continue;
            }
            $nonSharedInstances[] = $instance;
            if ($trackingPid <= 0) {
                continue;
            }

            $pidToInstance[$trackingPid] = $instance;
        }

        foreach ($pidToInstance as $pid => $instance) {
            if ($this->shouldWaitForStopFlowExitVerification($instance)) {
                $connectedVerificationPids[] = $pid;
            } else {
                $immediateForceKillPids[] = $pid;
            }
        }

        $runningPids = $connectedVerificationPids;

        $verificationTimeout = $this->getStopVerificationTimeout();
        if (!empty($runningPids) && $verificationTimeout > 0.0) {
            $verificationStartedAt = \microtime(true);
            $deadline = $verificationStartedAt + $verificationTimeout;
            $lastHeartbeatAt = 0.0;

            while (!empty($runningPids) && \microtime(true) < $deadline) {
                $this->pollStopFlowIpc(0, 100000);

                $runningStatus = $this->batchCheckStopFlowRunning($runningPids);
                $stillRunning = [];
                foreach ($runningPids as $pid) {
                    if ($runningStatus[$pid] ?? false) {
                        $stillRunning[] = $pid;
                        continue;
                    }

                    $instance = $pidToInstance[$pid] ?? null;
                    if ($instance?->ipcClientId !== null) {
                        $this->closeStopFlowClient($instance->ipcClientId);
                        $instance->ipcClientId = null;
                        $this->registry->updateInstance($instance);
                    }
                }
                $runningPids = $stillRunning;

                if ($runningPids === []) {
                    break;
                }

                $now = \microtime(true);
                if (($now - $lastHeartbeatAt) >= 1.0) {
                    $elapsed = (int) \round($now - $verificationStartedAt);
                    $remainingCount = \count($runningPids);
                    $this->sendStopProgress("阶段5校验中: 剩余 {$remainingCount} 个进程 ({$elapsed}s/{$verificationTimeout}s)");
                    $lastHeartbeatAt = $now;
                }

                $this->sleepStopFlow(100000);
            }
        }

        if ($immediateForceKillPids !== []) {
            $runningPids = \array_values(\array_unique(\array_merge($runningPids, $immediateForceKillPids)));
        }

        $runningPidSet = [];
        foreach ($runningPids as $pid) {
            $runningPidSet[$pid] = true;
        }

        $totalCount = \count($nonSharedInstances);
        $exitedCount = 0;
        foreach ($nonSharedInstances as $instance) {
            $trackingPid = $this->getInstanceTrackingPid($instance);
            if ($trackingPid <= 0 || !isset($runningPidSet[$trackingPid])) {
                $exitedCount++;
            }
        }
        $runningCount = \count($runningPids);

        if ($runningCount === 0) {
            $this->sendStopProgress("非共享进程校验完成: 全部 {$totalCount} 个已退出");
            WlsLogger::info_("[Orchestrator] 非共享进程校验完成: 全部 {$totalCount} 个已退出");
        } else {
            $this->sendStopProgress("非共享进程校验结果: {$exitedCount}/{$totalCount} 已退出，{$runningCount} 个需强制终止");
            WlsLogger::warning_("[Orchestrator] 非共享进程校验结果: {$exitedCount}/{$totalCount} 已退出，{$runningCount} 个需强制终止");
        }

        if ($runningPids !== []) {
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

            $this->sendStopProgress('强制终止残留进程: ' . \implode(', ', $pidList));
            WlsLogger::warning_('[Orchestrator] 强制终止残留子进程: ' . \implode(',', $runningPids));
            $result = $this->forceStopRemainingProcesses($runningPids);

            foreach ($runningPids as $pid) {
                $instance = $pidToInstance[$pid] ?? null;
                if ($instance?->ipcClientId !== null) {
                    $this->closeStopFlowClient($instance->ipcClientId);
                    $instance->ipcClientId = null;
                    $this->registry->updateInstance($instance);
                }
            }

            if ($result['killed'] > 0) {
                $this->sendStopProgress("  ✓ 已强制终止 {$result['killed']} 个残留进程");
                WlsLogger::info_("[Orchestrator] 已强制终止 {$result['killed']} 个残留子进程");
            }
            if (!empty($result['remaining'])) {
                $remainingCount = \count($result['remaining']);
                $this->sendStopProgress('  ! 仍有 ' . $remainingCount . ' 个进程未终止: ' . \implode(',', $result['remaining']));
                WlsLogger::warning_('[Orchestrator] 仍有 ' . $remainingCount . ' 个进程未终止: ' . \implode(',', $result['remaining']));
            }
        }

        // 最终清理：确保所有实例都被正确清理，包括僵尸 IPC 连接
        $finalTrackingPids = [];
        foreach ($allInstances as $instance) {
            $trackingPid = $this->getInstanceTrackingPid($instance);
            if ($trackingPid > 0 && !$this->isSharedStateServiceInstance($instance)) {
                $finalTrackingPids[$trackingPid] = $trackingPid;
            }
        }
        $finalRunningStatus = [];

        foreach ($allInstances as $instance) {
            $trackingPid = $this->getInstanceTrackingPid($instance);
            $isSharedState = $this->isSharedStateServiceInstance($instance);

            // 强制关闭任何残留的 IPC 连接（防止僵尸 IPC 连接）
            if (!$isSharedState && $instance->ipcClientId !== null) {
                WlsLogger::info_(
                    "[Orchestrator] 清理残留 IPC 连接: {$instance->role}#{$instance->instanceId}, pid={$trackingPid}, ipc={$instance->ipcClientId}"
                );
                $this->closeStopFlowClient($instance->ipcClientId);
                $instance->ipcClientId = null;
            }

            // 如果进程已退出但未被清理，执行清理
            if (!$isSharedState && $trackingPid > 0 && !($finalRunningStatus[$trackingPid] ?? false)) {
                WlsLogger::info_(
                    "[Orchestrator] 检测到僵尸进程: {$instance->role}#{$instance->instanceId}, pid={$trackingPid} 已退出但未被回收"
                );
            }

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
    protected function shouldWaitForStopFlowExitVerification(ServiceInstance $instance): bool
    {
        return false;
    }

    /**
     * @param int[] $pids
     * @return array<int, bool>
     */
    protected function batchCheckStopFlowRunning(array $pids): array
    {
        return Processer::batchCheckRunning($pids);
    }

    /**
     * @param int[] $pids
     * @return array{killed: int, failed: int, remaining: int[]}
     */
    protected function forceStopRemainingProcesses(array $pids): array
    {
        return Processer::dispatchBatchKillProcessTrees($pids, true);
    }

    protected function pollStopFlowIpc(int $timeoutSec = 0, int $timeoutUsec = 100000): int
    {
        return $this->controlServer?->poll($timeoutSec, $timeoutUsec) ?? 0;
    }

    protected function closeStopFlowClient(int $clientId): void
    {
        $this->controlServer?->closeClient($clientId);
    }

    protected function sleepStopFlow(int $microseconds): void
    {
        if ($microseconds > 0) {
            SchedulerSystem::usleep($microseconds);
        }
    }

    protected function getStopVerificationTimeout(): float
    {
        $timeout = (float)($this->context?->getConfig('wls.orchestrator.stop_terminate_timeout_sec', 1.5) ?? 1.5);
        if ($timeout < 0.0) {
            $timeout = 0.0;
        }
        if ($timeout > 10.0) {
            $timeout = 10.0;
        }

        return $timeout;
    }

    private function closeIpcServer(string $reason = 'unspecified', ?float $flushSecOverride = null): void
    {
        $this->lastControlServerCloseReason = $reason;
        if ($this->controlServer === null) {
            return;
        }
        $flushSec = $flushSecOverride;
        if ($flushSec === null) {
            $flushSec = (float) ($this->context?->getConfig('wls.orchestrator.stop_ipc_flush_before_close_sec', 0.2) ?? 0.2);
        }
        if ($flushSec > 0.0) {
            $this->controlServer->flushPendingWrites(\min(10.0, \max(0.01, $flushSec)));
        }
        WlsLogger::info_('[IPC] 控制服务器关闭（出站缓冲已尽力排空）, reason=' . $reason);
        $this->controlServer->close();
        $this->controlServer = null;
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
        if ($this->isSharedStateServiceInstance($instance)) {
            $instance->state = ServiceInstance::STATE_STOPPED;
            $this->registry->updateInstance($instance);
            $provider?->onStopped($instance);
            WlsLogger::info_("[Orchestrator] 跳过共享服务 {$instance->role}#{$instance->instanceId} 的本地停机控制");

            return;
        }

        // 阶段 1：DRAIN
        if ($provider !== null && $provider->getReloadStrategy() === 'graceful' && $instance->ipcClientId !== null) {
            $instance->state = ServiceInstance::STATE_DRAINING;
            $this->registry->updateInstance($instance);
            $this->sendDrainToInstance($instance);
            $this->waitForDrain([$instance], $this->drainTimeout, null);
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
        $trackingPid = $this->getInstanceTrackingPid($instance);
        while (\microtime(true) - $waitStart < $timeout) {
            $this->controlServer?->poll(0, 100000);

            if ($trackingPid <= 0 || !$this->isProcessRunning($trackingPid)) {
                return;
            }
        }

        if ($trackingPid > 0 && $this->isProcessRunning($trackingPid)) {
            WlsLogger::warning_("[Orchestrator] 进程 {$instance->role}#{$instance->instanceId} (pid={$trackingPid}) 未在 {$timeout}s 内退出，强制杀死");
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
        if ($this->isSharedStateServiceInstance($instance)) {
            return;
        }

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
        if ($this->isSharedStateServiceInstance($instance)) {
            WlsLogger::info_("[Orchestrator] 跳过共享服务 {$instance->role}#{$instance->instanceId} 的进程终止");

            return;
        }

        $processName = $this->getInstanceProcessName($instance);
        $launchId = $this->getInstanceLaunchId($instance);
        $servicePid = $instance->pid;
        $trackingPid = $this->getInstanceTrackingPid($instance);

        if ($trackingPid > 0 && $trackingPid !== $servicePid) {
            if (Processer::killProcessTreeByPid($trackingPid, true)) {
                return;
            }
        }

        if ($servicePid > 0 && ($processName !== '' || $launchId !== '')) {
            if (Processer::killManagedProcessTree(
                $servicePid,
                $processName !== '' ? $processName : null,
                $launchId,
                $processName !== '' ? '--name=' . $processName : null
            )) {
                return;
            }
        }

        if ($processName !== '') {
            Processer::destroy('--name=' . $processName);
            return;
        }

        if ($trackingPid > 0) {
            Processer::killProcessTreeByPid($trackingPid, true);
            return;
        }

        if ($servicePid > 0) {
            Processer::killByPid($servicePid);
        }
    }

    private function getInstanceProcessName(ServiceInstance $instance): string
    {
        return (string) ($instance->getMeta('process_name') ?? '');
    }

    private function getInstanceLaunchId(ServiceInstance $instance): string
    {
        return $instance->launchId !== ''
            ? $instance->launchId
            : (string) ($instance->getMeta('launch_id') ?? '');
    }

    /**
     * 清理实例的 PID 文件
     */
    private function cleanupInstancePidFile(ServiceInstance $instance): void
    {
        if ($this->isSharedStateServiceInstance($instance)) {
            return;
        }

        $processName = $this->getInstanceProcessName($instance);
        if ($processName !== '') {
            Processer::removePidFile('--name=' . $processName);
        }
    }

    /**
     * 发送 drain 命令给实例
     */
    private function sendDrainToInstance(ServiceInstance $instance, ?float $timeoutSec = null): void
    {
        if ($this->isSharedStateServiceInstance($instance)) {
            return;
        }

        if ($instance->ipcClientId === null || $this->controlServer === null) {
            if ($instance->ipcClientId === null) {
                WlsLogger::warning_(
                    "[Orchestrator] 无法向 {$instance->role}#{$instance->instanceId} 发送 DRAIN：无 IPC 连接（请检查子进程是否已注册控制通道）"
                );
            }

            return;
        }

        $instance->state = ServiceInstance::STATE_DRAINING;
        $this->registry->updateInstance($instance);

        $ports = $instance->port !== null ? [$instance->port] : [];
        $timeout = $timeoutSec ?? $this->drainTimeout;
        $dt = (int) \max(1, \min(7200, (int) \ceil($timeout)));
        $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::drain($ports, $dt));
    }

    private function resolveWorkerReloadDrainTimeout(): float
    {
        $configured = $this->context?->getConfig('wls.orchestrator.reload_drain_timeout_sec', null);
        if ($configured === null || $configured === '') {
            return \max(1.0, \min($this->drainTimeout, 1.0));
        }

        return \max(1.0, \min(60.0, (float) $configured));
    }

    /**
     * 等待实例排水完成
     *
     * @param ServiceInstance[] $instances
     */
    /**
     * @param ServiceInstance[] $instances
     * @return bool 已排空；false 为超时或帝王指令代际已变（清场抢占）
     */
    private function waitForDrain(array $instances, float $timeout, ?int $imperialEpochSnap = null): bool
    {
        $start = \microtime(true);
        while (\microtime(true) - $start < $timeout) {
            if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                return false;
            }
            $allDrained = true;
            foreach ($instances as $instance) {
                $trackingPid = $this->getInstanceTrackingPid($instance);
                if ($instance->state === ServiceInstance::STATE_DRAINING && $trackingPid > 0 && $this->isProcessRunning($trackingPid)) {
                    $allDrained = false;
                    break;
                }
            }

            if ($allDrained) {
                return true;
            }

            $this->controlServer?->poll(0, 100000);
        }

        WlsLogger::warning_("[Orchestrator] 等待排水超时 ({$timeout}s)");

        return false;
    }

    /**
     * 重载指定服务
     */
    public function reloadService(string $role, string $type = 'code', ?int $imperialEpochSnap = null): void
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
            if ($role === ControlMessage::ROLE_WORKER && $type === ControlMessage::RELOAD_TYPE_FORCE) {
                $this->gracefulReloadWorkersWithDispatcherBatches($provider, [], $type, $imperialEpochSnap);
            }

            return;
        }

        if ($role === ControlMessage::ROLE_WORKER
            && !$this->waitForWorkerCriticalInfraReady('reload worker')) {
            $missingRoles = $this->collectWorkerCriticalInfraNotReadyRoles();
            $this->failWorkerBatchNotify(
                'reload',
                'Worker reload aborted: critical infra not ready ('
                . (!empty($missingRoles) ? \implode(',', $missingRoles) : 'unknown')
                . ')'
            );

            return;
        }

        if ($strategy === 'immediate') {
            foreach ($instances as $instance) {
                if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                    WlsLogger::warning_("[Orchestrator] 重载 {$role} 因 IPC 帝王抢占中止");

                    return;
                }
                $this->restartInstance($instance, $type);
            }
        } elseif ($strategy === 'graceful') {
            if ($role === 'worker' && \count($instances) >= 2) {
                $this->gracefulReloadWorkersWithDispatcherBatches($provider, $instances, $type, $imperialEpochSnap);
            } else {
                $this->gracefulReloadInstances($provider, $instances, $type, $imperialEpochSnap);
            }
        }
    }

    /**
     * 重载所有服务
     */
    /**
     * @param string $type code|force|cache（cache 通常不经此路径整组重启）
     * @param int|null $imperialEpochSnap 本轮帝王 IPC 重载开始时的 ipcImperialEpoch；null 表示非帝王发起（若当前 IPC 独占则直接跳过）
     */
    public function reloadAll(string $type = 'code', ?int $imperialEpochSnap = null): void
    {
        // P2 观测性埋点：reload 触发频次（按 type 标签），用于评估是否有异常热重载风暴。
        // 只计入调用次数不计耗时，因为方法有多条提前 return 分支（独占/epoch 不匹配），
        // 耗时在混杂分支下平均无意义。
        \Weline\Server\Observability\MetricsRegistry::inc('orchestrator.reload_all.invoked.' . $type);

        if ($this->ipcExclusiveCommand === ControlMessage::ACTION_STOP && !$this->isStopFlowActive()) {
            WlsLogger::warning_('[Orchestrator] 停机流程已结束但仍残留 IPC 独占 STOP，已自动清理以便重载');
            $this->ipcReleaseExclusive();
        }

        if ($imperialEpochSnap === null && $this->ipcExclusiveCommand !== null) {
            WlsLogger::info_('[Orchestrator] 控制面 IPC 独占中，跳过非本轮帝王发起的 reload_all');

            return;
        }

        // 冷却期仅过滤「非等待型」重载（如 FileWatcher），避免误触；reload_wait / se:rel 必须执行并回传结果，否则 CLI 永久挂起
        if ($type === 'code' && $this->startAllCompletedAt > 0 && $this->rollingRestartClientId === null) {
            $elapsed = \microtime(true) - $this->startAllCompletedAt;
            if ($elapsed < $this->startupReloadCooldown) {
                WlsLogger::info_("[Orchestrator] 忽略启动后冷却期内的 reload_all:code（已启动 " . \round($elapsed, 1) . "s，冷却 {$this->startupReloadCooldown}s；显式等待重载不受此限）");

                return;
            }
        }

        $configuredRoles = $this->context?->getConfig('wls.orchestrator.reload_roles', ['worker']);
        $reloadRoles = \is_array($configuredRoles) ? $configuredRoles : ['worker'];
        if (empty($reloadRoles)) {
            $reloadRoles = ['worker'];
        }
        WlsLogger::info_("[Orchestrator] 收到重载请求 (type={$type})，目标角色: " . \implode(',', $reloadRoles));
        if ($this->rollingRestartClientId !== null) {
            $total = (int)($this->desiredState[ControlMessage::ROLE_WORKER]
                ?? \count($this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER)));
            $this->sendReloadProgressMessage(
                'reload accepted by Master; preparing worker topology',
                0,
                \max(0, $total),
                'preparing',
                0
            );
        }

        $reloadsWorker = \in_array('worker', $reloadRoles, true);
        $workerCount = \count($this->registry->getInstancesByRole('worker'));
        $multiWorkerWorkerReload = $reloadsWorker && $workerCount >= 2;
        $forceReload = ($type === ControlMessage::RELOAD_TYPE_FORCE);
        // 单 Worker：无同角色接替，必须切维护池。多 Worker + force：一批全杀会短暂无业务 Worker，同样先切维护池兜底。
        $shouldEnableMaintenanceBeforeWorkerReload = $reloadsWorker
            && !$this->maintenanceMode
            && !$multiWorkerWorkerReload;
        $maintenanceEnabledForReload = false;
        $maintenanceStickyBeforeReload = $this->maintenanceSticky;
        try {
            if ($multiWorkerWorkerReload && $this->maintenanceMode && !$this->maintenanceSticky && !$forceReload) {
                $this->disableMaintenanceMode();
                $this->yieldControlPlane(20000);
            }
            if ($reloadsWorker && $forceReload) {
                WlsLogger::warning_(
                    '[Orchestrator] force reload skips maintenance dispatcher takeover and rebuilds worker slots directly'
                );
                $this->sendReloadProgressMessage(
                    'force reload skips maintenance pool; rebuilding worker slots directly',
                    0,
                    (int)($this->desiredState[ControlMessage::ROLE_WORKER] ?? $workerCount),
                    'preparing',
                    0
                );
                $this->yieldControlPlane(20000);
            } elseif ($shouldEnableMaintenanceBeforeWorkerReload) {
                $enableResult = $this->enableMaintenanceMode(false, false);
                if ($enableResult['success']) {
                    $maintenanceEnabledForReload = true;
                    $this->yieldControlPlane(20000);
                } else {
                    WlsLogger::warning_(
                        '[Orchestrator] reload_all 未能启用维护 Worker（'
                        . ($enableResult['message'] ?? 'unknown')
                        . '），将继续重载；单 Worker 场景下入口可能短暂不可用'
                    );
                }
            } elseif ($reloadsWorker && $multiWorkerWorkerReload && !$forceReload) {
                WlsLogger::debug_(
                    '[Orchestrator] reload_all 多 Worker 优雅重载：由分批摘流承接，不单独拉起维护 Worker'
                );
            }

            foreach ($reloadRoles as $role) {
                if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                    WlsLogger::warning_('[Orchestrator] reload_all 因帝王抢占已中止');

                    break;
                }
                $this->reloadService((string)$role, $type, $imperialEpochSnap);
            }
        } finally {
            if ($maintenanceEnabledForReload && !$maintenanceStickyBeforeReload) {
                $this->disableMaintenanceMode();
            }
            $this->broadcastRoutingPolicyToWorkers();
        }
    }

    /**
     * 优雅重载实例列表
     *
     * @param ServiceInstance[] $instances
     */
    /**
     * @param ServiceInstance[] $instances
     */
    private function gracefulReloadInstances(
        ServiceProviderInterface $provider,
        array $instances,
        string $type,
        ?int $imperialEpochSnap = null,
    ): void {
        $savedFullRestartOnFailure = $this->fullRestartOnFailure;
        $this->fullRestartOnFailure = false;
        $startTime = \microtime(true);

        try {
            foreach ($instances as $instance) {
                if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                    WlsLogger::warning_('[Orchestrator] 优雅重载因 IPC 帝王抢占中止');

                    return;
                }
                $role = $instance->role;
                $id = $instance->instanceId;
                WlsLogger::info_("[Orchestrator] 滚动重启 {$role}#{$id}: DRAIN");

                $this->sendDrainToInstance($instance);
                $drained = $this->waitForDrain([$instance], $this->drainTimeout, $imperialEpochSnap);
                if (!$drained) {
                    if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                        WlsLogger::warning_("[Orchestrator] 滚动重载 {$role}#{$id}: 排水阶段被帝王指令打断");

                        return;
                    }
                    WlsLogger::error_("[Orchestrator] 滚动重载 {$role}#{$id} 排水超时");
                    $this->sendReloadWaitTerminalOutcome(
                        ControlMessage::reloadFailed("{$role}#{$id} drain timeout")
                    );

                    return;
                }

                WlsLogger::info_("[Orchestrator] 滚动重启 {$role}#{$id}: STOP 旧实例");
                $this->stopInstanceWithProtocol($instance);
                $this->registry->removeInstance($role, $id);

                WlsLogger::info_("[Orchestrator] 滚动重启 {$role}#{$id}: 启动新实例");
                $started = $this->startInstance($provider, $id, $this->context);
                if ($started === null) {
                    WlsLogger::error_("[Orchestrator] 滚动重载 {$role}#{$id} 启动失败");
                    $this->sendReloadWaitTerminalOutcome(
                        ControlMessage::reloadFailed("{$role}#{$id} start failed")
                    );

                    return;
                }
                $ready = $this->waitForInstanceReady($role, $id, $this->startupTimeout, $imperialEpochSnap);
                if (!$ready) {
                    if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                        WlsLogger::warning_("[Orchestrator] 滚动重载 {$role}#{$id}: 就绪等待被帝王指令打断");

                        return;
                    }
                    if ($this->isStopFlowActive()) {
                        WlsLogger::warning_("[Orchestrator] 滚动重载 {$role}#{$id}: 就绪等待被停机流程接管");

                        return;
                    }
                    WlsLogger::error_("[Orchestrator] 滚动重载 {$role}#{$id} 未在 {$this->startupTimeout}s 内进入 READY");
                    $this->sendReloadWaitTerminalOutcome(
                        ControlMessage::reloadFailed("{$role}#{$id} not ready within {$this->startupTimeout}s")
                    );

                    return;
                }

                WlsLogger::info_("[Orchestrator] 滚动重启 {$role}#{$id}: 完成");
            }

            if ($this->rollingRestartClientId !== null) {
                $elapsedMs = (\microtime(true) - $startTime) * 1000;
                $this->controlServer?->sendTo($this->rollingRestartClientId, ControlMessage::reloadCompleted($elapsedMs, \count($instances)));
            }

            $this->rollingRestartStabilizingUntil = \microtime(true) + $this->stabilizationSec;
        } finally {
            $this->fullRestartOnFailure = $savedFullRestartOnFailure;
            $this->fullRestartRequested = false;
            $this->fullRestartReason = '';
        }
    }

    /**
     * 多 Worker 代码重载：与滚动重启相同策略（Dispatcher 摘批→整批排水→重启→批内全部 READY 后再加回池；Worker 数≥阈值时智能分三批）。
     *
     * @param ServiceInstance[] $instances
     */
    private function gracefulReloadWorkersWithDispatcherBatches(
        ServiceProviderInterface $provider,
        array $instances,
        string $type,
        ?int $imperialEpochSnap,
    ): void {
        $savedFullRestartOnFailure = $this->fullRestartOnFailure;
        $this->fullRestartOnFailure = false;
        $startTime = \microtime(true);

        try {
            $forceReload = ($type === ControlMessage::RELOAD_TYPE_FORCE);
            $orderedIds = [];
            foreach ($instances as $inst) {
                $orderedIds[$inst->instanceId] = true;
            }
            if ($forceReload) {
                $desiredWorkerSlots = $this->resolveDesiredWorkerSlotCount($provider, $instances);
                for ($slot = 1; $slot <= $desiredWorkerSlots; $slot++) {
                    $orderedIds[$slot] = true;
                }
            }
            $ids = \array_keys($orderedIds);
            \sort($ids, SORT_NUMERIC);
            if ($ids === []) {
                $this->sendReloadWaitTerminalOutcome(ControlMessage::reloadCompleted(0.0, 0));
                return;
            }
            $batches = $this->getWorkerRestartBatches($ids, $forceReload);
            $batchTotal = \count($batches);
            WlsLogger::info_(
                "[Orchestrator] Worker 分批重载共 {$batchTotal} 批（三批策略阈值见 wls.orchestrator.worker_three_batch_min_count）"
            );

            $done = 0;
            $batchIdx = 0;
            foreach ($batches as $batch) {
                $batchIdx++;
                if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                    WlsLogger::warning_('[Orchestrator] Worker 分批重载因帝王抢占中止');

                    return;
                }
                WlsLogger::info_(
                    '[Orchestrator] 重载第 ' . $batchIdx . "/{$batchTotal} 批 Worker: " . \implode(',', $batch)
                );
                $result = $this->restartWorkerBatchDispatcherAware(
                    $batch,
                    $imperialEpochSnap,
                    'reload',
                    $done,
                    \count($ids),
                    $batchIdx,
                    $batchTotal,
                    $forceReload
                );
                if ($result === 'aborted') {
                    return;
                }
                if ($result === 'failed') {
                    return;
                }
                $done += \count($batch);
            }

            $this->syncDispatcherFullWorkerPoolFromRegistry(true);

            $elapsedMs = (\microtime(true) - $startTime) * 1000;
            $this->sendReloadWaitTerminalOutcome(ControlMessage::reloadCompleted($elapsedMs, $done));
            $this->rollingRestartStabilizingUntil = \microtime(true) + $this->stabilizationSec;
        } finally {
            $this->fullRestartOnFailure = $savedFullRestartOnFailure;
            $this->fullRestartRequested = false;
            $this->fullRestartReason = '';
        }
    }

    /**
     * Resolve the target worker slot count from Master authority, not from the
     * currently registered worker subset. Force reload must rebuild missing
     * slots in the same transaction instead of relying on later self-heal.
     *
     * @param ServiceInstance[] $instances
     */
    private function resolveDesiredWorkerSlotCount(ServiceProviderInterface $provider, array $instances): int
    {
        $desired = (int)($this->desiredState[ControlMessage::ROLE_WORKER] ?? 0);
        if ($desired <= 0 && $this->context !== null) {
            $desired = (int)$provider->getInstanceCount($this->context);
        }
        foreach ($instances as $instance) {
            if ($instance instanceof ServiceInstance) {
                $desired = \max($desired, (int)$instance->instanceId);
            }
        }

        return \max(0, $desired);
    }

    /**
     * Worker 批次策略：
     * - force 重载：全部 Worker 合并为 1 批，直接切换。
     * - 非 force：Worker 数 ≥ min_count 时均分为三批；否则逐个一批（每批 1 个）。
     *
     * @param int[] $orderedInstanceIds
     * @return array<int, int[]>
     */
    private function getWorkerRestartBatches(array $orderedInstanceIds, bool $forceSingleBatch = false): array
    {
        $n = \count($orderedInstanceIds);
        if ($n === 0) {
            return [];
        }
        if ($forceSingleBatch) {
            return [\array_map('intval', $orderedInstanceIds)];
        }
        $minThree = (int) ($this->context?->getConfig('wls.orchestrator.worker_three_batch_min_count', 7) ?? 7);
        if ($minThree < 4) {
            $minThree = 7;
        }
        if ($n < $minThree) {
            $out = [];
            foreach ($orderedInstanceIds as $id) {
                $out[] = [(int) $id];
            }

            return $out;
        }
        $batchCount = (int)($this->context?->getConfig('wls.orchestrator.worker_reload_batch_count', 1) ?? 1);
        if ($batchCount < 1) {
            $batchCount = 1;
        }
        if ($batchCount > 3) {
            $batchCount = 3;
        }
        $batchCount = \min($batchCount, $n);

        $base = intdiv($n, $batchCount);
        $rem = $n % $batchCount;
        $batches = [];
        $idx = 0;
        for ($b = 0; $b < $batchCount; $b++) {
            $size = $base + ($b < $rem ? 1 : 0);
            if ($size <= 0) {
                break;
            }
            $batches[] = \array_map('intval', \array_slice($orderedInstanceIds, $idx, $size));
            $idx += $size;
        }

        return $batches;
    }

    /**
     * 整批：Dispatcher 摘除 → 排水 → 停 → 拉齐 → 批内全部 READY → 发布完整路由表。
     *
     * @param int[] $instanceIds
     * @return 'ok'|'aborted'|'failed'
     */
    private function restartWorkerBatchDispatcherAware(
        array $instanceIds,
        ?int $imperialEpochSnap,
        string $rollingOrReload,
        int $completedBefore = 0,
        int $totalWorkers = 0,
        int $batchIndex = 0,
        int $batchTotal = 0,
        bool $skipDrain = false,
    ): string {
        if ($this->context === null) {
            $this->failWorkerBatchNotify($rollingOrReload, 'Context lost');

            return 'failed';
        }
        $workerProvider = $this->registry->getProvider('worker');
        if ($workerProvider === null) {
            $this->failWorkerBatchNotify($rollingOrReload, 'Worker provider not found');

            return 'failed';
        }

        $instanceIds = \array_values(\array_unique(\array_map('intval', $instanceIds)));
        \sort($instanceIds, \SORT_NUMERIC);
        if ($instanceIds === []) {
            return 'ok';
        }

        if ($totalWorkers <= 0) {
            $totalWorkers = \count($instanceIds);
        }

        $batchLabel = ($batchIndex > 0 && $batchTotal > 0) ? "Batch {$batchIndex}/{$batchTotal}" : 'Batch';
        $batchList = '[' . \implode(',', $instanceIds) . ']';
        $leadWorkerId = $instanceIds[0] ?? 0;
        $batchMeta = [
            'batch_index' => $batchIndex,
            'batch_total' => $batchTotal,
            'batch_size' => \count($instanceIds),
            'batch_ids' => $instanceIds,
        ];
        $reloadDrainTimeout = $this->resolveWorkerReloadDrainTimeout();

        if (!$skipDrain) {
            $this->sendReloadProgressMessage(
                "{$batchLabel}: removing workers {$batchList} from dispatcher",
                $completedBefore,
                $totalWorkers,
                'removing_from_dispatcher',
                $leadWorkerId,
                $batchMeta
            );
            foreach ($instanceIds as $instanceId) {
                if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                    return 'aborted';
                }
                $worker = $this->registry->getInstance('worker', $instanceId);
                if ($worker !== null) {
                    $this->notifyDispatcherRemoveWorker($worker->port);
                }
            }
            $this->yieldControlPlane(20000);
        } else {
            $this->sendReloadProgressMessage(
                "{$batchLabel}: force mode skips dispatcher drain for workers {$batchList}",
                $completedBefore,
                $totalWorkers,
                'removing_from_dispatcher',
                $leadWorkerId,
                $batchMeta
            );
        }

        if (!$skipDrain) {
            $drainRefs = [];
            $this->sendReloadProgressMessage(
                "{$batchLabel}: draining workers {$batchList}",
                $completedBefore,
                $totalWorkers,
                'draining',
                $leadWorkerId,
                $batchMeta
            );
            foreach ($instanceIds as $instanceId) {
                if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                    return 'aborted';
                }
                $worker = $this->registry->getInstance('worker', $instanceId);
                if ($worker !== null && $worker->ipcClientId !== null) {
                    $this->sendDrainToInstance($worker, $reloadDrainTimeout);
                    $drainRefs[] = $instanceId;
                }
            }
            if ($drainRefs !== []) {
                $instancesForDrain = [];
                foreach ($drainRefs as $id) {
                    $w = $this->registry->getInstance('worker', $id);
                    if ($w !== null) {
                        $instancesForDrain[] = $w;
                    }
                }
                if ($instancesForDrain !== []) {
                    $drainStart = \microtime(true);
                    $drained = false;
                    $lastDrainHeartbeatAt = 0.0;
                    while ((\microtime(true) - $drainStart) < $reloadDrainTimeout) {
                        if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                            return 'aborted';
                        }

                        $remainingDraining = 0;
                        foreach ($instancesForDrain as $instance) {
                            $trackingPid = $this->getInstanceTrackingPid($instance);
                            if ($instance->state === ServiceInstance::STATE_DRAINING && $trackingPid > 0 && $this->isProcessRunning($trackingPid)) {
                                $remainingDraining++;
                            }
                        }

                        if ($remainingDraining === 0) {
                            $drained = true;
                            break;
                        }

                        $now = \microtime(true);
                        if (($now - $lastDrainHeartbeatAt) >= 5.0) {
                            $elapsed = (int) \round($now - $drainStart);
                            $this->sendReloadProgressMessage(
                                "{$batchLabel}: draining {$remainingDraining}/" . \count($instancesForDrain) . " workers {$batchList} ({$elapsed}s/{$reloadDrainTimeout}s)",
                                $completedBefore,
                                $totalWorkers,
                                'draining',
                                $leadWorkerId,
                                $batchMeta
                            );
                            $lastDrainHeartbeatAt = $now;
                        }

                        $this->yieldControlPlane(20000);
                    }
                    if (!$drained) {
                        if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                            return 'aborted';
                        }
                        WlsLogger::warning_(
                            '[Orchestrator] 批次 Worker [' . \implode(',', $instanceIds) . '] 排水未全部完成，继续 shutdown'
                        );
                    }
                }
            }
        } else {
            $this->sendReloadProgressMessage(
                "{$batchLabel}: force mode skip draining workers {$batchList}",
                $completedBefore,
                $totalWorkers,
                'draining',
                $leadWorkerId,
                $batchMeta
            );
        }

        $cleanupRefs = [];
        if ($skipDrain) {
            $this->sendReloadProgressMessage(
                "{$batchLabel}: force killing workers {$batchList} concurrently",
                $completedBefore,
                $totalWorkers,
                'stopping',
                $leadWorkerId,
                $batchMeta
            );
            $killPids = [];
            $closeClientIds = [];
            foreach ($instanceIds as $instanceId) {
                if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                    return 'aborted';
                }
                $worker = $this->registry->getInstance('worker', $instanceId);
                if ($worker === null) {
                    continue;
                }
                $worker->state = ServiceInstance::STATE_STOPPING;
                $this->registry->updateInstance($worker);
                $cleanupRefs[$instanceId] = $worker;
                if ($worker->ipcClientId !== null) {
                    $this->controlServer?->sendTo($worker->ipcClientId, ControlMessage::shutdown());
                    $closeClientIds[] = $worker->ipcClientId;
                }
                $trackingPid = $this->getInstanceTrackingPid($worker);
                if ($trackingPid > 0) {
                    $killPids[$trackingPid] = true;
                }
                if ($worker->pid > 0) {
                    $killPids[(int) $worker->pid] = true;
                }
            }

            if ($killPids !== []) {
                $killResult = $this->forceStopRemainingProcesses(\array_map('intval', \array_keys($killPids)));
                WlsLogger::warning_(
                    '[Orchestrator] force 重载批量终止旧 Worker: requested=' . \count($killPids)
                    . ', killed=' . (int) ($killResult['killed'] ?? 0)
                    . ', failed=' . (int) ($killResult['failed'] ?? 0)
                    . ', remaining=' . \implode(',', \array_map('strval', $killResult['remaining'] ?? []))
                );
            }

            foreach ($closeClientIds as $clientId) {
                $this->controlServer?->closeClient((int) $clientId);
            }
            $this->yieldControlPlane(20000);
        } else {
            $this->sendReloadProgressMessage(
                "{$batchLabel}: stopping workers {$batchList}",
                $completedBefore,
                $totalWorkers,
                'stopping',
                $leadWorkerId,
                $batchMeta
            );
            foreach ($instanceIds as $instanceId) {
                if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                    return 'aborted';
                }
                $worker = $this->registry->getInstance('worker', $instanceId);
                if ($worker !== null) {
                    $this->stopInstance($worker);
                }
            }

            $this->yieldControlPlane(80000);
            $oldWorkerPids = [];
            $oldWorkerClients = [];
            foreach ($instanceIds as $instanceId) {
                $worker = $this->registry->getInstance('worker', $instanceId);
                if ($worker === null) {
                    continue;
                }
                $trackingPid = $this->getInstanceTrackingPid($worker);
                if ($trackingPid > 0) {
                    $oldWorkerPids[$trackingPid] = true;
                }
                if ($worker->pid > 0) {
                    $oldWorkerPids[(int)$worker->pid] = true;
                }
                if ($worker->ipcClientId !== null) {
                    $oldWorkerClients[] = (int)$worker->ipcClientId;
                }
            }

            if ($oldWorkerPids !== []) {
                $running = $this->batchCheckStopFlowRunning(\array_map('intval', \array_keys($oldWorkerPids)));
                $remaining = [];
                foreach ($running as $pid => $isRunning) {
                    if ($isRunning) {
                        $remaining[] = (int)$pid;
                    }
                }
                if ($remaining !== []) {
                    WlsLogger::warning_(
                        '[Orchestrator] reload batch force-terminating old workers: ' . \implode(',', $remaining)
                    );
                    $this->forceStopRemainingProcesses($remaining);
                }
            }

            foreach ($oldWorkerClients as $clientId) {
                $this->controlServer?->closeClient($clientId);
            }
            foreach ($instanceIds as $instanceId) {
                $worker = $this->registry->getInstance('worker', $instanceId);
                if ($worker !== null) {
                    $worker->ipcClientId = null;
                    $worker->pid = 0;
                    $this->registry->updateInstance($worker);
                }
            }

            foreach ($instanceIds as $instanceId) {
                $worker = $this->registry->getInstance('worker', $instanceId);
                if ($worker === null) {
                    continue;
                }

                $stillConnected = $worker->ipcClientId !== null;
                $stillRunning = $worker->pid > 0 && $this->isProcessRunning($worker->pid);
                if (!$stillConnected && !$stillRunning) {
                    continue;
                }

                WlsLogger::warning_(
                    '[Orchestrator] 优雅重载发现旧 Worker 未退出，执行强制终止: '
                    . "worker#{$instanceId}, pid={$worker->pid}, ipc=" . ($stillConnected ? '1' : '0')
                );
                $this->killInstanceProcess($worker);
                if ($stillConnected && $worker->ipcClientId !== null) {
                    $this->controlServer?->closeClient($worker->ipcClientId);
                }
                if ($worker->port !== null && $worker->port > 0) {
                    $this->ensurePortReleasedForResurrection((int) $worker->port);
                }
            }

            foreach ($instanceIds as $instanceId) {
                $worker = $this->registry->getInstance('worker', $instanceId);
                if ($worker !== null) {
                    $cleanupRefs[$instanceId] = $worker;
                }
            }
        }

        foreach ($instanceIds as $instanceId) {
            $this->registry->removeInstance('worker', $instanceId);
        }
        foreach ($cleanupRefs as $worker) {
            $this->cleanupInstancePidFile($worker);
        }

        $this->sendReloadProgressMessage(
            "{$batchLabel}: starting workers {$batchList} concurrently",
            $completedBefore,
            $totalWorkers,
            'starting',
            $leadWorkerId,
            $batchMeta
        );
        if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
            return 'aborted';
        }
        $this->yieldControlPlane(0);
        if (!$this->waitForWorkerCriticalInfraReady("restart worker batch [{$batchList}]")) {
            $missingRoles = $this->collectWorkerCriticalInfraNotReadyRoles();
            $this->failWorkerBatchNotify(
                $rollingOrReload,
                'Batch [' . \implode(',', $instanceIds) . '] blocked: critical infra not ready ('
                . (!empty($missingRoles) ? \implode(',', $missingRoles) : 'unknown')
                . ')'
            );

            return 'failed';
        }
        if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
            return 'aborted';
        }
        $this->yieldControlPlane(0);
        $this->startInstanceIdsBatch($workerProvider, $instanceIds, $this->context);
        $this->yieldControlPlane(0);

        $readyExtra = 20.0 + 10.0 * \count($instanceIds);
        $readyDeadline = \microtime(true) + $this->startupTimeout + $readyExtra;
        $allReady = false;
        $lastReadyHeartbeatAt = 0.0;
        $readyCount = 0;
        while (\microtime(true) < $readyDeadline) {
            if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                return 'aborted';
            }
            $allReady = true;
            $readyCount = 0;
            foreach ($instanceIds as $instanceId) {
                $w = $this->registry->getInstance('worker', $instanceId);
                if ($w !== null && $w->state === Contract\ServiceInstance::STATE_READY) {
                    $readyCount++;
                    continue;
                }
                if ($w === null || $w->state !== Contract\ServiceInstance::STATE_READY) {
                    $allReady = false;
                }
            }
            if ($allReady) {
                break;
            }
            $now = \microtime(true);
            if (($now - $lastReadyHeartbeatAt) >= 5.0) {
                $elapsed = (int) \round(($this->startupTimeout + $readyExtra) - ($readyDeadline - $now));
                $this->sendReloadProgressMessage(
                    "{$batchLabel}: waiting READY {$readyCount}/" . \count($instanceIds) . " for workers {$batchList} ({$elapsed}s/" . ($this->startupTimeout + $readyExtra) . 's)',
                    $completedBefore + $readyCount,
                    $totalWorkers,
                    'waiting_ready',
                    $leadWorkerId,
                    $batchMeta
                );
                $lastReadyHeartbeatAt = $now;
            }
            $this->yieldControlPlane(20000);
        }
        if (!$allReady) {
            $this->failWorkerBatchNotify(
                $rollingOrReload,
                'Batch [' . \implode(',', $instanceIds) . '] not all READY within timeout'
            );

            return 'failed';
        }

        $this->sendReloadProgressMessage(
            "{$batchLabel}: workers {$batchList} are READY, rejoining dispatcher",
            $completedBefore + \count($instanceIds),
            $totalWorkers,
            'rejoin_dispatcher',
            $leadWorkerId,
            $batchMeta
        );
        // 滚动重启批次 READY：用版本化全量路由表一次性广播给所有 Dispatcher。
        // 仅在非维护模式下广播；维护模式中业务流量仍走维护 Worker，新 Worker 留到 disableMaintenance 时一次性入池。
        if (!$this->maintenanceMode) {
            $anyEligible = false;
            foreach ($instanceIds as $instanceId) {
                $readyInst = $this->registry->getInstance('worker', $instanceId);
                if ($readyInst !== null && $readyInst->port !== null && $readyInst->port > 0) {
                    $anyEligible = true;
                    break;
                }
            }
            if ($anyEligible) {
                $this->syncDispatcherFullWorkerPoolFromRegistry();
            }
        }
        $this->broadcastRoutingPolicyToWorkers();
        if ($this->maintenanceMode) {
            WlsLogger::info_(
                '[Orchestrator] 批次 [' . \implode(',', $instanceIds) . '] READY（维护模式中不加入 Dispatcher，流量仍走维护 Worker）'
            );
        } else {
            WlsLogger::info_(
                '[Orchestrator] 批次 [' . \implode(',', $instanceIds) . '] 已全部 READY，已推送全量路由表至 Dispatcher'
            );
        }

        return 'ok';
    }

    /**
     * 向当前 reload_wait / 滚动等待客户端发送终态 NDJSON（reload_completed / reload_failed），成功则标记已回执。
     */
    private function sendReloadWaitTerminalOutcome(string $encodedNdjsonLine): void
    {
        if ($this->rollingRestartClientId === null || $this->controlServer === null) {
            return;
        }
        if ($this->controlServer->sendTo($this->rollingRestartClientId, $encodedNdjsonLine)) {
            $this->reloadWaitTerminalEventSent = true;
        }
    }

    private function failWorkerBatchNotify(string $rollingOrReload, string $message): void
    {
        WlsLogger::error_('[Orchestrator] ' . $message);
        if ($rollingOrReload === 'rolling') {
            $this->finishRollingRestart(false, $message);
        } elseif ($this->rollingRestartClientId !== null && $this->controlServer !== null) {
            $this->sendReloadWaitTerminalOutcome(ControlMessage::reloadFailed($message));
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
    private function waitForInstanceReady(string $role, int $instanceId, float $timeout, ?int $imperialEpochSnap = null): bool
    {
        $start = \microtime(true);
        while (\microtime(true) - $start < $timeout) {
            if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                return false;
            }
            if ($this->shouldAbortStartupTransition()) {
                return false;
            }
            $instance = $this->registry->getInstance($role, $instanceId);
            if ($instance !== null && $instance->state === ServiceInstance::STATE_READY) {
                return true;
            }

            $this->controlServer?->poll(0, 100000);
            if ($this->shouldAbortStartupTransition()) {
                return false;
            }
        }

        return false;
    }

    /**
     * Master 主循环不经 WlsRuntime::handle()，StateManager 不会每请求清理 EventsManager::$events。
     * 高频 scheduler::wait 会在 $events 中滞留 Event 实例，与子进程 IPC 日志叠加易触达 memory_limit。
     */
    private function releaseMasterLoopEphemeralState(): void
    {
        try {
            $instances = ObjectManager::getInstances();
            $em = $instances[EventsManager::class] ?? null;
            if ($em && \method_exists($em, 'resetRequestState')) {
                $em->resetRequestState();
            }
        } catch (\Throwable) {
        }

        try {
            WlsLogger::tick_();
        } catch (\Throwable) {
        }
    }

    /**
     * 主循环（健康检查 + IPC 轮询）
     */
    public function runLoop(): void
    {
        WlsLogger::info_('[Orchestrator] 进入主循环');
        $lastPollAt = 0.0;
        $pollCount = 0;

        while ($this->running || $this->hasPendingMainLoopTasks()) {
            
            // 总启动超时检查：超过 startupMaxDuration 未完成启动则强制退出
            $this->checkStartupTimeoutAndExitIfNeeded();

            // WlsLogger::info_('[Orchestrator] 主循环开始 运行时间:getMainLoopPollTimeoutUsec ' . $this->getMainLoopPollTimeoutUsec(100000) . 'us');
            // 每隔一段时间点答应一个循环数字，表示主循环未被阻塞
            if ($pollCount % 100000 === 0 && $pollCount > 0) {
                WlsLogger::info_('[Orchestrator] 主循环未被阻塞 #' . $pollCount);
            }
            // Poll IPC 消息（可能触发 stopAll 导致 shuttingDown=true）
            $pollStartAt = \microtime(true);
            $this->controlServer?->poll(0, $this->getMainLoopPollTimeoutUsec(100000));
            $pollElapsed = \microtime(true) - $pollStartAt;
            // WlsLogger::info_('[Orchestrator] 主循环 poll 运行时间: getMainLoopPollTimeoutUsec' . \number_format($pollElapsed * 1000, 2) . 'ms');

            // 每 5 秒输出一次循环状态
            if ($pollElapsed > 0.1 || ($pollCount % 50 === 0 && $pollCount > 0)) {
                WlsLogger::debug_('[Orchestrator] 主循环 poll #' . $pollCount
                    . ' elapsed=' . \number_format($pollElapsed * 1000, 2) . 'ms'
                    . ' tasks=' . \count($this->mainLoopTasks)
                    . ' fibers=' . ($this->mainLoopFiberScheduler?->getActiveFiberCount() ?? 0));
            }
            $pollCount++;

            // 必须在 tick 之前消费 pending stop：否则启动 Fiber 若卡在 batchCreate 等同步段，整轮 runLoop 无法入队 stop_all
            if ($this->consumePendingStopRequest()) {
                continue;
            }

            $tickStartAt = \microtime(true);
            $this->tickMainLoopTasks();
            $tickElapsed = \microtime(true) - $tickStartAt;
            $tickSlowWarnThresholdMs = (float) ($this->context?->getConfig('wls.orchestrator.tick_slow_warn_threshold_ms', 500.0) ?? 500.0);
            if ($tickSlowWarnThresholdMs < 100.0) {
                $tickSlowWarnThresholdMs = 100.0;
            }
            $tickSlowWarnCooldownSec = (float) ($this->context?->getConfig('wls.orchestrator.tick_slow_warn_cooldown_sec', 3.0) ?? 3.0);
            if ($tickSlowWarnCooldownSec < 0.5) {
                $tickSlowWarnCooldownSec = 0.5;
            }
            if (($tickElapsed * 1000) > $tickSlowWarnThresholdMs) {
                $now = \microtime(true);
                if (($now - $this->lastTickMainLoopSlowWarningAt) >= $tickSlowWarnCooldownSec) {
                    $this->lastTickMainLoopSlowWarningAt = $now;
                    WlsLogger::warning_(
                        '[Orchestrator] tickMainLoopTasks 耗时过长: '
                        . \number_format($tickElapsed * 1000, 2) . 'ms'
                        . ', tasks=' . \count($this->mainLoopTasks)
                        . ', fibers=' . ($this->mainLoopFiberScheduler?->getActiveFiberCount() ?? 0)
                    );
                }
            }


            $this->completePendingFiberStatsIfTimeout();

            if ($this->consumePendingStopRequest()) {
                continue;
            }

            // 关键：poll 可能在回调中执行 stopAll，需要立即检查退出条件
            if (!$this->running && !$this->hasPendingMainLoopTasks()) {
                break;
            }
            if (!$this->running) {
                continue;
            }

            // 故障统一策略：整组重启。仅在没有活跃控制操作时推进，避免与命令流叠加。
            if ($this->fullRestartRequested
                && $this->activeControlOperation === null
                && !$this->hasMainLoopTask('control:full_restart')
            ) {
                if ($this->scheduleMainLoopTask('control:full_restart', 'full_restart', function (): void {
                    $this->performFullRestart();
                })) {
                    continue;
                }
            }

            if ($this->activeControlOperation === null
                && $this->pendingControlOperations !== []
                && !$this->hasMainLoopTask('mainloop:control_dispatch')
            ) {
                if ($this->scheduleMainLoopTask('mainloop:control_dispatch', 'control_dispatch', function (): void {
                    $this->processNextQueuedControlOperation();
                })) {
                    continue;
                }
            }

            // 定期健康检查 - 仅在启动确认完成后启动
            $now = \microtime(true);
            $this->scheduleSharedStateConsumerRenewalIfReady($now);
            if ($this->startupAcceptanceComplete
                && $now - $this->lastHealthCheck >= $this->healthCheckInterval
                && !$this->hasMainLoopTask('periodic:health_checks')
            ) {
                $this->lastHealthCheck = $now;
                if ($this->scheduleMainLoopTask('periodic:health_checks', 'health_checks', function (): void {
                    $this->performHealthChecks();
                })) {
                    continue;
                }
            }

            if ($this->startupAcceptanceComplete) {
                if ($this->haMode
                    && $now - $this->lastReconcileAt >= $this->reconcileInterval
                    && !$this->hasMainLoopTask('periodic:reconcile')
                ) {
                    $this->lastReconcileAt = $now;
                    if ($this->scheduleMainLoopTask('periodic:reconcile', 'reconcile', function (): void {
                        $this->reconcileDesiredState();
                        $this->syncDispatcherFullWorkerPoolFromRegistry();
                    })) {
                        continue;
                    }
                } elseif (!$this->haMode
                    && $this->reconcileWorkersWithoutHa
                    && $now - $this->lastWorkerSlotReconcileAt >= $this->reconcileInterval
                    && !$this->hasMainLoopTask('periodic:reconcile_worker_slots')
                ) {
                    $this->lastWorkerSlotReconcileAt = $now;
                    if ($this->scheduleMainLoopTask('periodic:reconcile_worker_slots', 'reconcile_worker_slots', function (): void {
                        $this->reconcileWorkerSlotsWithoutHa();
                        $this->syncDispatcherFullWorkerPoolFromRegistry();
                    })) {
                        continue;
                    }
                }
            }

            if ($this->startupAcceptanceComplete
                && $this->workerLivenessIntervalSec > 0
                && ($now - $this->lastWorkerLivenessAt) >= $this->workerLivenessIntervalSec
                && !$this->hasMainLoopTask('periodic:worker_liveness')
            ) {
                $this->lastWorkerLivenessAt = $now;
                if ($this->scheduleMainLoopTask('periodic:worker_liveness', 'worker_liveness', function (): void {
                    $this->runWorkerLivenessAudit();
                })) {
                    continue;
                }
            }

            if ($this->startupAcceptanceComplete
                && $this->haMode
                && $this->periodicOrphanSweepEnabled
                && $now - $this->lastSweepAt >= $this->sweeperInterval
                && !$this->hasMainLoopTask('periodic:orphan_sweep')
            ) {
                $this->lastSweepAt = $now;
                if ($this->scheduleMainLoopTask('periodic:orphan_sweep', 'orphan_sweep', function (): void {
                    $this->cleanupOrphanChildProcesses(aggressiveKill: false);
                })) {
                    continue;
                }
            }

            // 启动确认完成后才处理复活队列；启动期只收集 READY，超时直接失败清理。
            if ($this->startupAcceptanceComplete) {
                $this->guardResurrectQueueTasks($now);
                if (!$this->hasMainLoopTask('periodic:resurrect_queue')) {
                    if ($this->scheduleMainLoopTask('periodic:resurrect_queue', 'resurrect_queue', function (): void {
                        $this->processResurrectQueue();
                    })) {
                        continue;
                    }
                }
            }

            // 稳定期过期
            if ($this->rollingRestartStabilizingUntil > 0 && $now >= $this->rollingRestartStabilizingUntil) {
                $this->rollingRestartStabilizingUntil = 0;
            }

            if ($this->startupAcceptanceComplete
                && !$this->hasMainLoopTask('startup:child_service_startup')
                && $this->masterSelfAuditIntervalSec > 0.0
                && ($now - $this->lastMasterSelfAuditAt) >= $this->masterSelfAuditIntervalSec
                && !$this->hasMainLoopTask('periodic:master_self_audit')
            ) {
                $this->lastMasterSelfAuditAt = $now;
                if ($this->scheduleMainLoopTask('periodic:master_self_audit', 'master_self_audit', function (): void {
                    $this->performMasterSelfAudit();
                })) {
                    continue;
                }
            }

            $this->releaseMasterLoopEphemeralState();
        }

        $this->resetMainLoopFiberScheduler();
        WlsLogger::info_('[Orchestrator] 退出主循环');
    }

    /**
     * 周期性维护任务的抢占点。
     *
     * 周期任务运行时也要顺手 poll 一次控制面，这样 reload/stop 一类帝王指令不用等整段维护逻辑跑完。
     */
    private function shouldYieldPeriodicWork(bool $pollControlPlane = true): bool
    {
        if (!$this->running
            || $this->shuttingDown
            || $this->stopAllInProgress
            || $this->pendingStopReason !== null
            || $this->childProcessStopInProgress) {
            return true;
        }

        if ($pollControlPlane && $this->controlServer !== null) {
            $this->controlServer->poll(0, 0);
        }

        if (!$this->running
            || $this->shuttingDown
            || $this->stopAllInProgress
            || $this->pendingStopReason !== null
            || $this->childProcessStopInProgress) {
            return true;
        }

        if ($this->suspendControlPreemption) {
            return $this->fullRestartRequested || $this->childProcessStopInProgress;
        }

        $shouldYield = $this->activeControlOperation !== null
            || ($this->activeControlOperation === null && $this->ipcExclusiveCommand !== null)
            || $this->pendingControlOperations !== []
            || $this->fullRestartRequested
            || $this->childProcessStopInProgress;
        if (!$shouldYield && SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null) {
            SchedulerSystem::yield();
        }

        return $shouldYield;
    }

    /**
     * @return string[]
     */
    private function getWorkerCriticalInfraRoles(): array
    {
        if ($this->context === null) {
            return [];
        }

        $workerDesired = (int) ($this->desiredState[ControlMessage::ROLE_WORKER]
            ?? \count($this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER)));
        if ($workerDesired <= 0) {
            return [];
        }

        $roles = [];
        foreach ($this->registry->getAllProviders() as $provider) {
            if ($provider->isCriticalRole() && $provider->isEnabled($this->context)) {
                $roles[] = $provider->getRole();
            }
        }

        return $roles;
    }

    /**
     * @param string[]|null $roles
     * @return string[]
     */
    private function collectWorkerCriticalInfraNotReadyRoles(?array $roles = null): array
    {
        $roles ??= $this->getWorkerCriticalInfraRoles();
        $missing = [];
        foreach ($roles as $role) {
            if (!$this->isCriticalInfraRoleReady($role)) {
                $missing[] = $role;
            }
        }

        return $missing;
    }

    private function isCriticalInfraRoleReady(string $role): bool
    {
        // 共享服务现在也是普通服务，统一通过 Registry 检查
        if (($this->infraDegraded[$role] ?? false) === true) {
            return false;
        }

        $instance = $this->registry->getInstance($role, 1);
        if ($instance === null || $instance->state !== ServiceInstance::STATE_READY) {
            return false;
        }

        if (isset($this->resurrectQueue["{$role}:1"])) {
            return false;
        }

        if ($instance->ipcClientId === null
            || ($this->controlServer !== null && !$this->controlServer->clientExists($instance->ipcClientId))) {
            return false;
        }

        $trackingPid = $this->getInstanceTrackingPid($instance);
        if ($trackingPid <= 0) {
            return true;
        }

        return $this->isProcessRunning($trackingPid);
    }

    private function waitForWorkerCriticalInfraReady(string $operationLabel, ?float $timeoutSec = null): bool
    {
        $criticalRoles = $this->getWorkerCriticalInfraRoles();
        if ($criticalRoles === []) {
            return true;
        }

        $missingRoles = $this->collectWorkerCriticalInfraNotReadyRoles($criticalRoles);
        if ($missingRoles === []) {
            return true;
        }

        $timeoutSec ??= \max($this->startupTimeout, $this->ipcReconnectGraceSec + 5.0);
        $deadline = \microtime(true) + $timeoutSec;
        $lastHeartbeatAt = 0.0;
        $oldSuspendFlag = $this->suspendControlPreemption;
        $this->suspendControlPreemption = true;

        try {
            WlsLogger::warning_(
                '[Orchestrator] ' . $operationLabel . ' waiting for critical infra READY: ' . \implode(',', $missingRoles)
            );

            while (\microtime(true) < $deadline) {
                $this->controlServer?->poll(0, 100000);
                if ($this->isStopFlowActive() || $this->fullRestartRequested) {
                    return false;
                }

                $this->processResurrectQueue($criticalRoles);
                foreach ($criticalRoles as $role) {
                    // 共享服务现在也是普通服务，统一处理
                    $this->reconcileRoleSlotGaps($role);
                }

                $missingRoles = $this->collectWorkerCriticalInfraNotReadyRoles($criticalRoles);
                if ($missingRoles === []) {
                    WlsLogger::info_('[Orchestrator] ' . $operationLabel . ' resumed after critical infra recovered');

                    return true;
                }

                $now = \microtime(true);
                if (($now - $lastHeartbeatAt) >= 5.0) {
                    WlsLogger::info_(
                        '[Orchestrator] '
                        . $operationLabel
                        . ' still waiting for critical infra READY: '
                        . \implode(',', $missingRoles)
                    );
                    $lastHeartbeatAt = $now;
                }
            }
        } finally {
            $this->suspendControlPreemption = $oldSuspendFlag;
        }

        WlsLogger::error_(
            '[Orchestrator] '
            . $operationLabel
            . ' timed out waiting for critical infra READY: '
            . \implode(',', $missingRoles)
        );

        return false;
    }

    /**
     * 可中断等待：在 runLoop 的 Fiber 内用协作式 usleep；在主栈（如启动阶段）仅用控制面 poll，避免原生 sleep 期间无法读 IPC。
     */
    private function sleepInterruptiblyForPeriodicWork(int $microseconds, int $sliceMicroseconds = 50000): bool
    {
        if ($microseconds <= 0) {
            return !$this->shouldYieldPeriodicWork(true);
        }

        $inFiber = SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null;
        $deadline = \microtime(true) + ($microseconds / 1000000);
        while (\microtime(true) < $deadline) {
            if ($this->shouldYieldPeriodicWork(true)) {
                return false;
            }

            $remainingUsec = (int)\max(1, ($deadline - \microtime(true)) * 1000000);
            $slice = \min($sliceMicroseconds, $remainingUsec);
            if ($inFiber) {
                SchedulerSystem::usleep($slice);
            } else {
                $this->pollControlPlaneBlockingUsec($slice);
            }
        }

        return !$this->shouldYieldPeriodicWork(true);
    }

    /**
     * 执行一轮 IO 轮询（供外部调用）
     */
    public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
    {
        return $this->controlServer?->poll($timeoutSec, $timeoutUsec) ?? 0;
    }

    /**
     * 控制面等待：用 stream_select 超时代替「poll 后再原生 usleep」，缩短无 IPC 处理窗口。
     */
    private function pollControlPlaneBlockingUsec(int $timeoutUsec): void
    {
        if ($timeoutUsec <= 0) {
            $this->controlServer?->poll(0, 0);

            return;
        }
        $this->controlServer?->poll(0, $timeoutUsec);
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
        if ($this->isRecoverySuspended()) {
            return;
        }

        $now = \microtime(true);
        $lastYieldAt = $now;
        $providers = $this->registry->getAllProviders();

        foreach ($providers as $provider) {
            $this->cooperativeYieldIfNeeded($lastYieldAt);
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
            }
            $instances = $this->registry->getInstancesByRole($provider->getRole());
            foreach ($instances as $instance) {
                $this->cooperativeYieldIfNeeded($lastYieldAt);
                if ($this->shouldYieldPeriodicWork(true)) {
                    return;
                }
                if ($this->childServicesBootstrapInProgress
                    && \in_array($instance->role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
                    continue;
                }
                if ($instance->state === ServiceInstance::STATE_FAILED ||
                    $instance->state === ServiceInstance::STATE_STOPPED) {
                    continue;
                }

                $uptime = $now - $instance->startedAt;

                // 启动中的实例：在宽限期内不检查
                if ($instance->state === ServiceInstance::STATE_STARTING) {
                    // 尚未连上 IPC 且子进程已死 → 不等到 register 超时，立即拉起
                    $trackingPid = $this->getInstanceTrackingPid($instance);
                    if ($instance->ipcClientId === null
                        && $trackingPid > 0
                        && !$this->shouldSkipEarlyPidDeathResurrectionCheck($instance)
                        && !$this->isProcessRunning($trackingPid)
                        && \in_array($instance->role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)
                        && ($provider->getResurrectionPriority() > 0)) {
                        WlsLogger::warning_(
                            "[Orchestrator] {$instance->role}#{$instance->instanceId} 未建立 IPC 且 PID 已退出，立即拉起"
                        );
                        $this->scheduleResurrectionWithDelay($instance, 0.0);
                        continue;
                    }
                    // 启动确认超时（register/ready 未到）
                    $registerTimeoutSec = $this->getRegisterTimeoutForRole($instance->role);
                    if ($instance->ipcClientId === null && $uptime >= $registerTimeoutSec) {
                        $this->registerTimeoutCount++;
                        WlsLogger::warning_(
                            "[Orchestrator] register 超时: {$instance->role}#{$instance->instanceId} "
                            . "(uptime={$uptime}s, timeout={$registerTimeoutSec}s)"
                        );
                        $this->healthCheckRestartOrEscalate($instance, "register_timeout:{$instance->role}#{$instance->instanceId}");
                        continue;
                    }

                    if ($uptime < $this->startupGracePeriod) {
                        if ($instance->ipcClientId !== null) {
                            // 仅建立 IPC 连接并不代表子进程已经显式 READY。
                            // 提前标记 READY 会让 maintenance / business worker 在真正可服务前错误入池。
                            $instance->lastHealthCheck = $now;
                            $this->registry->updateInstance($instance);
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
                $this->clearStaleIpcClientIfNeeded($instance);

                if ($instance->ipcClientId !== null) {
                    // 有 IPC 连接，视为健康
                    $result = $provider->healthCheck($instance);
                    $instance->lastHealthCheck = $now;
                    if (!$result->isHealthy()) {
                        if ($this->shouldAttemptWorkerAccessRecovery($instance)) {
                            if ($this->attemptWorkerAccessRecovery($instance, $result->message)) {
                                $this->registry->updateInstance($instance);
                                continue;
                            }
                            WlsLogger::warning_(
                                "[Orchestrator] Worker 访问恢复失败，准备重启: {$instance->role}#{$instance->instanceId} - {$result->message}"
                            );
                            $this->healthCheckRestartOrEscalate(
                                $instance,
                                "worker_unhealthy_access_failed:{$instance->role}#{$instance->instanceId}"
                            );
                            continue;
                        }
                        WlsLogger::warning_(
                            "[Master自检] 子进程健康检查异常: {$instance->role}#{$instance->instanceId} — {$result->message}"
                        );
                    }
                    $this->registry->updateInstance($instance);
                    continue;
                }

                $trackingPid = $this->getInstanceTrackingPid($instance);
                if ($trackingPid > 0 && $this->isProcessRunning($trackingPid)) {
                    // PID 存活但没有 IPC 连接，可能正在启动或重连
                    if ($uptime < $this->startupGracePeriod * 2) {
                        continue;
                    }
                    // 超过宽限期仍没有 IPC 连接，视为僵尸进程，需要杀死并复活
                    WlsLogger::warning_("[Orchestrator] 进程存活但无 IPC 超时: {$instance->role}#{$instance->instanceId} (pid={$trackingPid}, uptime={$uptime}s)");
                    $this->healthCheckRestartOrEscalate($instance, "no_ipc_timeout:{$instance->role}#{$instance->instanceId}");
                    continue;
                }

                // 既没有 IPC 连接，PID 也不存活
                WlsLogger::warning_("[Orchestrator] 健康检查失败: {$instance->role}#{$instance->instanceId} - No IPC and PID not running");
                $this->healthCheckRestartOrEscalate($instance, "dead_without_ipc:{$instance->role}#{$instance->instanceId}");
            }
        }
    }

    private function shouldAttemptWorkerAccessRecovery(ServiceInstance $instance): bool
    {
        if (!$this->startupAcceptanceComplete) {
            return false;
        }

        if ($instance->role !== ControlMessage::ROLE_WORKER) {
            return false;
        }

        if ($instance->state !== ServiceInstance::STATE_READY) {
            return false;
        }

        return (int) ($instance->port ?? 0) > 0;
    }

    private function attemptWorkerAccessRecovery(ServiceInstance $instance, string $reason): bool
    {
        $port = (int) ($instance->port ?? 0);
        if ($port <= 0) {
            return false;
        }

        $attempts = 2;
        for ($i = 1; $i <= $attempts; $i++) {
            if ($this->probeWorkerHealthEndpoint($port) || $this->canConnectLocalPort($port)) {
                $instance->setMeta('worker_access_recovery_at', \microtime(true));
                $instance->setMeta('worker_access_recovery_reason', $reason);
                WlsLogger::info_(
                    "[Orchestrator] Worker 异常后访问恢复成功: worker#{$instance->instanceId}, port={$port}, attempt={$i}"
                );

                return true;
            }

            if ($i < $attempts) {
                $this->sleepInterruptiblyForPeriodicWork(80000);
            }
        }

        return false;
    }

    private function probeWorkerHealthEndpoint(int $port): bool
    {
        if ($port <= 0) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $socket = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        if (!\is_resource($socket)) {
            return false;
        }

        @\stream_set_timeout($socket, 0, 300000);
        @\fwrite(
            $socket,
            "GET /_wls/health HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n"
            . "\r\n"
        );
        $line = @\fgets($socket, 512);
        @\fclose($socket);
        if (!\is_string($line) || $line === '') {
            return false;
        }

        return \str_starts_with($line, 'HTTP/1.1')
            || \str_starts_with($line, 'HTTP/1.0');
    }

    /**
     * 健康检查触发的重启：凡可复活的子进程均单槽拉起；仅无复活优先级或单槽重启耗尽时整组重启。
     */
    private function healthCheckRestartOrEscalate(ServiceInstance $instance, string $reason): void
    {
        if ($this->isRecoverySuspended()) {
            return;
        }
        if ($this->shouldThrottleHealthRestart($instance, $reason)) {
            return;
        }
        WlsLogger::warning_(
            '[Orchestrator] 健康检查触发复活决策: reason=' . $reason . ', ' . $this->formatInstanceDebugContext($instance)
        );

        $trackingPid = $this->getInstanceTrackingPid($instance);

        $maxRestarts = 10;
        $provider = $this->registry->getProvider($instance->role);
        if ($this->cleanupInactiveMaintenanceInstance($instance, '健康检查失败')) {
            return;
        }
        if (!$this->canUseLocalSlotResurrection($instance, $provider)) {
            $this->requestFullRestart($reason);

            return;
        }
        if ($instance->restarts >= $maxRestarts) {
            $this->requestFullRestart("{$reason} (max_slot_restarts={$instance->restarts})");

            return;
        }

        if ($trackingPid > 0 && $this->isProcessRunning($trackingPid)) {
            $this->killInstanceProcess($instance);
            if (!$this->sleepInterruptiblyForPeriodicWork(200000)) {
                return;
            }
        }
        $this->scheduleResurrection($instance);
    }

    private function shouldThrottleHealthRestart(ServiceInstance $instance, string $reason): bool
    {
        if ($this->context === null) {
            return false;
        }
        $cooldown = (float) ($this->context->getConfig('wls.orchestrator.health_restart_cooldown_sec', 6.0) ?? 6.0);
        if ($cooldown <= 0.0) {
            return false;
        }

        $now = \microtime(true);
        $lastAt = (float) ($instance->getMeta('health_restart_last_at', 0.0) ?? 0.0);
        if ($lastAt > 0.0 && ($now - $lastAt) < $cooldown) {
            WlsLogger::info_(
                "[Orchestrator] 健康重启宽限中，跳过重复重启: {$instance->role}#{$instance->instanceId} "
                . "(reason={$reason}, cooldown={$cooldown}s)"
            );
            return true;
        }

        $instance->setMeta('health_restart_last_at', $now);
        $instance->setMeta('health_restart_last_reason', $reason);
        $this->registry->updateInstance($instance);

        return false;
    }

    private function canUseLocalSlotResurrection(
        ServiceInstance $instance,
        ?ServiceProviderInterface $provider = null
    ): bool {
        $provider ??= $this->registry->getProvider($instance->role);
        if ($provider === null) {
            return false;
        }

        if ($instance->getUptime() < $this->startupGracePeriod) {
            return true;
        }

        if ($provider->getResurrectionPriority() > 0) {
            return true;
        }

        return $instance->role === ControlMessage::ROLE_MAINTENANCE
            && $this->maintenanceMode;
    }

    private function clearMaintenanceResurrectQueue(?int $instanceId = null): void
    {
        if ($instanceId !== null) {
            unset($this->resurrectQueue[ControlMessage::ROLE_MAINTENANCE . ':' . $instanceId]);
            return;
        }

        foreach (\array_keys($this->resurrectQueue) as $key) {
            if (\str_starts_with($key, ControlMessage::ROLE_MAINTENANCE . ':')) {
                unset($this->resurrectQueue[$key]);
            }
        }
    }

    private function cleanupInactiveMaintenanceInstance(ServiceInstance $instance, string $trigger): bool
    {
        if ($instance->role !== ControlMessage::ROLE_MAINTENANCE || $this->maintenanceMode) {
            return false;
        }

        $this->clearMaintenanceResurrectQueue($instance->instanceId);
        $this->registry->removeInstance(ControlMessage::ROLE_MAINTENANCE, $instance->instanceId);
        if ($this->context !== null) {
            $this->persistServicesInfo($this->context);
        }
        WlsLogger::info_(
            "[Orchestrator] maintenance#{$instance->instanceId} {$trigger}，但维护模式未激活，清理残留实例"
        );

        return true;
    }

    private function getRegisterTimeoutForRole(string $role): float
    {
        $default = (float) $this->registerTimeout;
        if (!\in_array($role, [ControlMessage::ROLE_DISPATCHER, ControlMessage::ROLE_REDIRECT], true)) {
            return $default;
        }

        $fastTimeout = (float) ($this->context?->getConfig(
            'wls.orchestrator.register_timeout_dispatcher_redirect_sec',
            20
        ) ?? 20);

        if ($fastTimeout < 5) {
            return 5.0;
        }

        return \min($default, $fastTimeout);
    }

    private function markSpawnedInstance(
        ServiceInstance $instance,
        float $spawnStartedAt,
        float $spawnFinishedAt,
        int $pid,
        string $spawnTransport,
        ?int $batchSize = null
    ): void {
        $resolvedTransport = (string) ($instance->getMeta('spawn_transport') ?? '');
        if ($resolvedTransport === '') {
            $resolvedTransport = $spawnTransport;
        }

        $instance->setMeta('spawn_transport', $resolvedTransport);
        $instance->setMeta('spawn_strategy', $spawnTransport);
        $instance->setMeta('spawn_requested_at', $spawnStartedAt);
        $instance->setMeta('spawn_finished_at', $spawnFinishedAt);
        $instance->setMeta('spawn_cost_ms', \max(0, (int) \round(($spawnFinishedAt - $spawnStartedAt) * 1000)));
        if ($batchSize !== null) {
            $instance->setMeta('spawn_batch_size', $batchSize);
        }

        $alreadyAcceptedByIpc = $instance->ipcClientId !== null
            || \in_array($instance->state, [ServiceInstance::STATE_REGISTERED, ServiceInstance::STATE_READY], true)
            || $instance->getMeta('register_received_at') !== null
            || $instance->getMeta('ready_received_at') !== null;

        $instance->setMeta('spawn_pid_returned', $pid > 0 ? $pid : 0);
        if ($alreadyAcceptedByIpc) {
            $this->mergeSpawnedProcessTreeForAcceptedInstance($instance, $pid);
            if ($instance->startedAt <= 0) {
                $instance->startedAt = $spawnFinishedAt;
            }
        } else {
            $this->applySpawnedProcessTree($instance, $pid);
            $instance->state = ServiceInstance::STATE_STARTING;
            $instance->startedAt = $spawnFinishedAt;
        }

        $this->logStartupTiming($instance, 'spawn_return', [
            'pid_returned' => $pid > 0 ? $pid : 0,
            'batch_size' => $batchSize,
        ]);
    }

    private function mergeSpawnedProcessTreeForAcceptedInstance(ServiceInstance $instance, int $pid): void
    {
        $spawnPid = $pid > 0 ? $pid : 0;
        $servicePid = (int)$instance->pid;
        if ($spawnPid <= 0) {
            $this->syncInstanceProcessTreeMeta($instance);
            return;
        }

        if ($servicePid > 0 && $servicePid !== $spawnPid) {
            if ((string)($instance->getMeta('spawn_transport') ?? '') === 'processer_create_foreground') {
                $instance->setProcessTreePids($servicePid, $servicePid, $spawnPid);
            } else {
                $instance->setProcessTreePids($servicePid, $spawnPid, $spawnPid);
            }
            $this->syncInstanceProcessTreeMeta($instance);
            return;
        }

        $this->applySpawnedProcessTree($instance, $spawnPid);
    }

    private function applySpawnedProcessTree(ServiceInstance $instance, int $pid): void
    {
        $spawnPid = $pid > 0 ? $pid : 0;
        if ((string) ($instance->getMeta('spawn_transport') ?? '') === 'processer_create_foreground') {
            // Windows foreground launches may return a short-lived wrapper PID.
            // Keep that wrapper only as launcherPid until the child registers.
            $instance->setProcessTreePids(0, 0, $spawnPid);
        } else {
            $instance->setProcessTreePids($spawnPid, $spawnPid, $spawnPid);
        }
        $this->syncInstanceProcessTreeMeta($instance);
    }

    private function applyRegisteredServicePid(ServiceInstance $instance, int $pid): void
    {
        if ($pid <= 0) {
            $this->syncInstanceProcessTreeMeta($instance);
            return;
        }

        $currentServicePid = (int) $instance->pid;
        $rootPid = (int) $instance->getRootPid();
        $launcherPid = (int) $instance->getLauncherPid();

        if ($rootPid <= 0 && $currentServicePid > 0 && $currentServicePid !== $pid) {
            $rootPid = $currentServicePid;
        }
        if ($rootPid <= 0) {
            $rootPid = $pid;
        }
        if ($launcherPid <= 0) {
            $launcherPid = $rootPid;
        }

        $instance->setProcessTreePids($pid, $rootPid, $launcherPid);
        $this->syncInstanceProcessTreeMeta($instance);
    }

    private function syncInstanceProcessTreeMeta(ServiceInstance $instance): void
    {
        $instance->setMeta('service_pid', $instance->pid > 0 ? $instance->pid : 0);
        $instance->setMeta('root_pid', $instance->getRootPid());
        $instance->setMeta('launcher_pid', $instance->getLauncherPid());
        $instance->setMeta('tracking_pid', $this->getInstanceTrackingPid($instance));
        $instance->setMeta('last_known_pid_set', $instance->getManagedPids());
    }

    private function getInstanceTrackingPid(ServiceInstance $instance): int
    {
        return $instance->getTrackingPid();
    }

    private function getQueuedTrackingPid(ServiceInstance $instance, ?array $resurrectEntry = null): int
    {
        $trackingPid = (int) ($resurrectEntry['tracking_pid'] ?? 0);
        if ($trackingPid > 0) {
            return $trackingPid;
        }

        return $this->getInstanceTrackingPid($instance);
    }

    private function isInstanceServiceAlive(ServiceInstance $instance): bool
    {
        $managedPids = $instance->getManagedPids();
        foreach ($managedPids as $pid) {
            if ($pid > 0 && $this->isProcessRunning($pid)) {
                return true;
            }
        }
        if ($managedPids !== []) {
            return false;
        }

        $port = (int) ($instance->port ?? 0);
        if ($port > 0 && Processer::isPortUsedByWeline($port)) {
            return true;
        }

        return false;
    }

    private function formatInstanceDebugContext(ServiceInstance $instance): string
    {
        $trackingPid = $this->getInstanceTrackingPid($instance);
        $pidAlive = '0';
        if ($trackingPid > 0) {
            $cached = $this->processRunningCache[$trackingPid] ?? null;
            $pidAlive = $cached === null ? '?' : ((bool) ($cached['running'] ?? false) ? '1' : '0');
        }
        return 'instance=' . $instance->role . '#' . $instance->instanceId
            . ', state=' . $instance->state
            . ', pid=' . $instance->pid
            . ', tracking_pid=' . $trackingPid
            . ', pid_alive=' . $pidAlive
            . ', ipc=' . ($instance->ipcClientId !== null ? (string) $instance->ipcClientId : 'null')
            . ', restarts=' . $instance->restarts
            . ', uptime=' . \number_format($instance->getUptime(), 2, '.', '') . 's'
            . ', launch_id=' . ($instance->launchId !== '' ? $instance->launchId : 'null');
    }

    /**
     * 请求整组重启（防止孤儿进程累积）
     */
    private function logStartupTiming(
        ServiceInstance $instance,
        string $milestone,
        array $extra = [],
        string $level = 'info'
    ): void {
        if (!isset($extra['spawn_transport'])) {
            $spawnTransport = $instance->getMeta('spawn_transport');
            if (\is_string($spawnTransport) && $spawnTransport !== '') {
                $extra['spawn_transport'] = $spawnTransport;
            }
        }
        if (!isset($extra['spawn_cost_ms'])) {
            $spawnCostMs = $instance->getMeta('spawn_cost_ms');
            if ($spawnCostMs !== null) {
                $extra['spawn_cost_ms'] = $spawnCostMs;
            }
        }

        $parts = ["[Orchestrator][StartupTiming] {$milestone}", $this->formatInstanceDebugContext($instance)];
        foreach ($extra as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $key . '=' . $this->formatStartupTimingValue($value);
        }

        $message = \implode(', ', $parts);
        if ($level === 'warning') {
            WlsLogger::warning_($message);
            return;
        }

        WlsLogger::info_($message);
    }

    private function formatStartupTimingValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (\is_float($value)) {
            return \number_format($value, 2, '.', '');
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: \get_debug_type($value);
    }

    private function requestFullRestart(string $reason): void
    {
        if (!$this->haMode || !$this->fullRestartOnFailure || $this->isStopFlowActive()) {
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
        $this->resetServerReadyNotificationState();

        // 4) bump epoch，旧代际进程即使迟到注册也会被拒绝
        $nextEpoch = $this->context->epoch + 1;
        $this->context = $this->context->withEpoch($nextEpoch);
        $this->persistMasterEpoch($this->context);
        WlsLogger::warning_("[Orchestrator] 代际切换到 epoch={$nextEpoch}");

        // 5) 重新拉起全量服务（仅子进程，不重新初始化 IPC 服务器）
        $this->restartChildProcesses($this->context);

        // 更新重启完成时间（确保冷却期从完成时开始计算）
        $this->lastFullRestartAt = \microtime(true);

        WlsLogger::warning_('[Orchestrator] 整组重启完成');
        $this->suppressWorkerEmergencyUntil = \microtime(true) + 30.0;
    }

    /**
     * 由 Master 在收到停机信号或静默 stop 时尽早调用，避免停机窗口内误触发 Worker 紧急拉起。
     */
    public function setMasterShutdownIntent(bool $intent): void
    {
        $this->masterShutdownIntent = $intent;
    }

    /**
     * 新帝王 IPC 指令清场：取消统计/滚动重启等待、整组重启标记、复活队列，并抢占重载代际。
     * 子进程侧排水/业务连接不受此调用直接影响，仅控制面清场。
     */
    private function ipcClearFieldForNewImperial(int $clientId, string $commandLabel): void
    {
        if ($this->pendingFiberStatsRequest !== null) {
            $rid = $this->pendingFiberStatsRequest['replyClientId'];
            $this->controlServer?->sendTo(
                $rid,
                ControlMessage::commandResult(false, [], $this->translateMessage('IPC 清场：已取消 Fiber 池统计请求'))
            );
            $this->pendingFiberStatsRequest = null;
        }
        $hadRollingRestart = $this->rollingRestartInProgress;
        if ($hadRollingRestart) {
            $rid = $this->rollingRestartClientId;
            if ($rid !== null && $rid !== $clientId) {
                $this->controlServer?->sendTo($rid, ControlMessage::encode([
                    'type' => ControlMessage::TYPE_RELOAD_FAILED,
                    'success' => false,
                    'message' => $this->translateMessage('IPC 清场：已由更高优先级控制指令中断'),
                ]));
            }
            $this->rollingRestartInProgress = false;
            $this->rollingRestartClientId = null;
            $this->rollingRestartProgress = 0;
            $this->rollingRestartTotal = 0;
            $dr = $this->disableMaintenanceMode();
            if (!($dr['success'] ?? false)) {
                WlsLogger::warning_('[Orchestrator] IPC 清场后关闭维护模式: ' . ($dr['message'] ?? 'unknown'));
            }
        }
        if ($this->rollingRestartClientId !== null && $this->rollingRestartClientId !== $clientId) {
            $this->controlServer?->sendTo($this->rollingRestartClientId, ControlMessage::encode([
                'type' => ControlMessage::TYPE_RELOAD_FAILED,
                'success' => false,
                'message' => $this->translateMessage('IPC 清场：已由更高优先级控制指令中断'),
            ]));
        }
        $this->rollingRestartClientId = null;

        $this->fullRestartRequested = false;
        $this->fullRestartReason = '';
        $this->resurrectQueue = [];
        $this->ipcImperialEpoch++;
        $this->ipcExclusiveCommand = $commandLabel;
        $this->ipcExclusiveClientId = $clientId;
        WlsLogger::warning_(
            "[Orchestrator] IPC 清场，帝王指令={$commandLabel} client={$clientId} imperial_epoch={$this->ipcImperialEpoch}"
        );
    }

    private function ipcReleaseExclusive(): void
    {
        if ($this->ipcExclusiveCommand !== null) {
            WlsLogger::info_(
                '[Orchestrator] IPC 帝王指令已结束，控制面已恢复常规指令 ('
                . $this->ipcExclusiveCommand . ')'
            );
        }
        $this->ipcExclusiveCommand = null;
        $this->ipcExclusiveClientId = null;
    }

    /**
     * 未注册为服务实例的连接断开（多为 CLI）：若其为当前帝王发起端，则打断本轮并重开控制面。
     */
    private function ipcOnExclusiveHolderDisconnect(int $clientId): void
    {
        if ($this->ipcExclusiveClientId !== $clientId) {
            return;
        }
        $label = $this->ipcExclusiveCommand ?? '';
        if ($label === ControlMessage::ACTION_STOP && $this->isStopFlowActive()) {
            WlsLogger::info_('[Orchestrator] stop 发起端已断开，停机流程继续执行');
            return;
        }
        if ($label === ControlMessage::ACTION_RELOAD_WAIT) {
            $this->rollingRestartClientId = null;
            WlsLogger::warning_('[Orchestrator] reload_wait 发起端已断开，当前滚动重载继续在 Master 内完成');
            return;
        }
        if ($label === ControlMessage::ACTION_RELOAD) {
            $this->ipcReleaseExclusive();
            WlsLogger::warning_('[Orchestrator] reload（异步）发起端已断开，当前重载继续在 Master 内完成');
            return;
        }
        if ($label === ControlMessage::ACTION_ROLLING_RESTART && $this->rollingRestartInProgress) {
            $this->rollingRestartClientId = null;
            WlsLogger::warning_('[Orchestrator] rolling_restart 发起端已断开，维护滚动重启继续在 Master 内完成');
            return;
        }
        if ($this->rollingRestartInProgress) {
            $this->rollingRestartInProgress = false;
            $this->rollingRestartProgress = 0;
            $this->rollingRestartTotal = 0;
            $dr = $this->disableMaintenanceMode();
            if (!($dr['success'] ?? false)) {
                WlsLogger::warning_(
                    '[Orchestrator] 帝王发起端断开后关闭维护模式: ' . ($dr['message'] ?? 'unknown')
                );
            }
        }
        $this->rollingRestartClientId = null;
        $this->ipcImperialEpoch++;
        $this->ipcExclusiveCommand = null;
        $this->ipcExclusiveClientId = null;
        WlsLogger::warning_(
            "[Orchestrator] 帝王指令发起端 IPC 已断开 ({$label})，已打断本轮并重开控制面"
        );
    }

    /**
     * 仅停止子进程，保持 Master 和 IPC 服务器运行。
     * 用于 performFullRestart 场景（停子进程 → 拉新子进程）。
     * 与 stopAll 不同：不设 shuttingDown、不设 running=false、不关闭 IPC。
     */
    private function stopChildProcesses(string $reason): void
    {
        WlsLogger::info_("[Orchestrator] 开始停止子进程（保持 IPC），原因: {$reason}");
        $this->childProcessStopInProgress = true;

        try {
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

            // 阶段 4：等待子进程 IPC 断开（与 stopAll 阶段 4 一致）
            WlsLogger::info_('[Orchestrator] 子进程停止阶段4: 等待子服务 IPC 断开');
            $this->waitForServiceIpcDisconnectAfterShutdown();

            // 阶段 5：强制杀死残留子进程
            WlsLogger::info_('[Orchestrator] 子进程停止阶段5: 校验并杀死残留');
            $this->verifyAndKillRemainingProcesses();

            WlsLogger::info_('[Orchestrator] 所有子进程已停止');
        } finally {
            $this->childProcessStopInProgress = false;
        }
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
        // 周期扫尾默认不做强杀，避免误杀"正在启动但尚未注册"的进程
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

        $killed = Processer::killByProcessNamePrefixes($prefixes);

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
        if ($this->context === null || empty($this->desiredState) || $this->isRecoverySuspended()) {
            return;
        }
        if ($this->childServicesBootstrapInProgress) {
            WlsLogger::debug_('[Orchestrator] reconcileDesiredState 跳过：子服务 bootstrap 进行中');

            return;
        }

        $lastYieldAt = \microtime(true);
        foreach ($this->desiredState as $role => $desiredCount) {
            $this->cooperativeYieldIfNeeded($lastYieldAt);
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
            }
            if ($role === ControlMessage::ROLE_MAINTENANCE && !$this->maintenanceMode) {
                $this->desiredState[ControlMessage::ROLE_MAINTENANCE] = 0;
                $this->clearMaintenanceResurrectQueue();
                $this->getMaintenanceProvider()?->disable();
                continue;
            }
            $provider = $this->registry->getProvider($role);
            if ($provider === null || !$provider->isEnabled($this->context)) {
                continue;
            }

            // 缺失实例补齐
            for ($slot = 1; $slot <= $desiredCount; $slot++) {
                $this->cooperativeYieldIfNeeded($lastYieldAt);
                if ($this->shouldYieldPeriodicWork(true)) {
                    return;
                }
                $queueKey = "{$role}:{$slot}";
                if (isset($this->resurrectQueue[$queueKey])) {
                    // 已在复活队列（含延迟执行）：勿在此再拉起，否则与 processResurrectQueue 重复 fork 同槽位双进程
                    continue;
                }
                $instance = $this->registry->getInstance($role, $slot);
                if ($instance === null || $instance->state === ServiceInstance::STATE_STOPPED || $instance->state === ServiceInstance::STATE_FAILED) {
                    WlsLogger::warning_("[Orchestrator] 收敛补齐实例 {$role}#{$slot}");
                    $portForSlot = (int) ($instance?->port ?? 0);
                    if ($portForSlot <= 0) {
                        $declaredPort = $provider->getPort($slot, $this->context);
                        $portForSlot = $declaredPort !== null ? (int) $declaredPort : 0;
                    }
                    if ($portForSlot > 0 && !$this->ensurePortReleasedForResurrection($portForSlot)) {
                        WlsLogger::warning_(
                            "[Orchestrator] 收敛补齐 {$role}#{$slot} 推迟：端口 {$portForSlot} 仍被占用且未释放（可能存在僵尸子进程或 Master 与监听进程脱节）"
                        );
                        continue;
                    }
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
                $this->cooperativeYieldIfNeeded($lastYieldAt);
                if ($this->shouldYieldPeriodicWork(true)) {
                    return;
                }
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
        if ($this->isStopFlowActive()) {
            return;
        }

        $provider = $this->registry->getProvider($instance->role);
        if ($provider === null) {
            return;
        }

        if (!$this->canUseLocalSlotResurrection($instance, $provider)) {
            WlsLogger::info_("[Orchestrator] 服务 {$instance->role} 不参与本地单槽复活");
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
    /**
     * @param string[]|null $roles
     */
    private function processResurrectQueue(?array $roles = null): void
    {
        if (empty($this->resurrectQueue) || $this->isRecoverySuspended()) {
            return;
        }

        $now = \microtime(true);
        foreach ($this->resurrectQueue as $key => $entry) {
            if ($roles !== null && !\in_array((string) ($entry['role'] ?? ''), $roles, true)) {
                continue;
            }
            if (!empty($entry['launching'])) {
                continue;
            }
            if ($this->childServicesBootstrapInProgress) {
                $entryRole = (string) ($entry['role'] ?? '');
                if ($entryRole === ControlMessage::ROLE_WORKER || $entryRole === ControlMessage::ROLE_MAINTENANCE) {
                    continue;
                }
            }
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
            }
            if (($entry['role'] ?? '') === ControlMessage::ROLE_MAINTENANCE && !$this->maintenanceMode) {
                unset($this->resurrectQueue[$key]);
                WlsLogger::info_("[Orchestrator] 维护模式已关闭，取消 maintenance#{$entry['instanceId']} 待执行复活");
                continue;
            }
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
            $port = (int)($entry['port'] ?? ($oldInstance?->port ?? 0));
            // 旧实例已经重新连回 Master，取消本次复活
            if ($oldInstance !== null
                && $oldInstance->ipcClientId !== null
                && \in_array($oldInstance->state, [ServiceInstance::STATE_REGISTERED, ServiceInstance::STATE_READY], true)
            ) {
                WlsLogger::info_("[Orchestrator] {$entry['role']}#{$entry['instanceId']} 已恢复 IPC 连接，取消待执行复活");
                unset($this->resurrectQueue[$key]);
                continue;
            }

            if ($oldInstance !== null) {
                $slotOccupancy = $this->inspectSlotOccupancy($oldInstance, $entry, $now);
                if ($oldInstance->ipcClientId === null
                    && $slotOccupancy['freshStartupWindow']
                    && ($slotOccupancy['pidAlive'] || $slotOccupancy['trackedPid'] <= 0)) {
                    $remainingSec = \max(0.0, $slotOccupancy['startupWindowSec'] - $slotOccupancy['ageSec']);
                    $entry['scheduledAt'] = \microtime(true) + 1.0;
                    $this->resurrectQueue[$key] = $entry;
                    WlsLogger::info_(
                        "[Orchestrator] {$entry['role']}#{$entry['instanceId']} 仍处于启动宽限窗口，推迟复活"
                        . "（previous_state={$slotOccupancy['previousState']}, pid={$slotOccupancy['trackedPid']}, "
                        . 'pid_alive=' . ($slotOccupancy['pidAlive'] ? '1' : '0')
                        . ', age=' . \round($slotOccupancy['ageSec'], 3) . 's'
                        . ', remaining=' . \round($remainingSec, 3) . 's）'
                    );
                    continue;
                }
            }

            // 延迟复活：检查进程是否仍在运行
            if (!empty($entry['delayed']) && (!empty($entry['tracking_pid']) || !empty($entry['pid']))) {
                if (!$this->terminateStaleProcessBeforeResurrection(
                    $oldInstance,
                    (int) ($entry['tracking_pid'] ?? $entry['pid'] ?? 0),
                    $port
                )) {
                    $entry['scheduledAt'] = \microtime(true) + 1.0;
                    $this->resurrectQueue[$key] = $entry;
                    WlsLogger::warning_("[Orchestrator] {$entry['role']}#{$entry['instanceId']} 旧进程/端口尚未释放，1 秒后重试复活");
                    continue;
                }
            }

            $launchPort = $port;
            if (!$this->ensurePortReleasedForResurrection($port)) {
                if ($this->context !== null
                    && $this->canUseEmergencyDynamicPort((string)$entry['role'], $port, $this->context)) {
                    $emergencyPort = $this->allocateEmergencyDynamicPort((string)$entry['role'], (int)$entry['instanceId'], $port, $this->context);
                    if ($emergencyPort > 0) {
                        $launchPort = $emergencyPort;
                        $entry['emergency_port'] = $emergencyPort;
                        $entry['configured_port'] = $port;
                        $this->resurrectQueue[$key] = $entry;
                        WlsLogger::warning_(
                            "[Orchestrator] {$entry['role']}#{$entry['instanceId']} 旧端口 {$port} 无法及时释放，"
                            . "新 generation 使用应急端口 {$emergencyPort} 复活"
                        );
                    }
                }
                if ($launchPort === $port) {
                    $entry['scheduledAt'] = \microtime(true) + 1.0;
                    $this->resurrectQueue[$key] = $entry;
                    WlsLogger::warning_("[Orchestrator] {$entry['role']}#{$entry['instanceId']} 端口 {$port} 仍被占用，推迟复活");
                    continue;
                }
            }

            // 清理旧实例的 PID 文件
            if ($oldInstance !== null) {
                $this->cleanupInstancePidFile($oldInstance);
            }

            $taskKey = "resurrect_launch:{$key}";
            $entry['launching'] = true;
            $entry['launchingAt'] = $now;
            $this->resurrectQueue[$key] = $entry;
            if (!$this->scheduleMainLoopTask($taskKey, 'resurrect_launch', function () use ($key, $entry, $provider, $oldRestarts, $port, $launchPort): void {
                if ($this->context === null) {
                    unset($this->resurrectQueue[$key]);
                    return;
                }
                $queuedEntry = $this->resurrectQueue[$key] ?? null;
                if ($queuedEntry === null) {
                    return;
                }
                try {
                    WlsLogger::info_("[Orchestrator] 执行复活 {$entry['role']}#{$entry['instanceId']}（异步任务）");
                    $this->registry->removeInstance($entry['role'], $entry['instanceId']);
                    $newInstance = $this->startInstance(
                        $provider,
                        (int)$entry['instanceId'],
                        $this->context,
                        $launchPort !== $port ? $launchPort : null,
                        $port > 0 ? $port : null
                    );
                } catch (\Throwable $throwable) {
                    unset($queuedEntry['launching'], $queuedEntry['launchingAt']);
                    $queuedEntry['scheduledAt'] = \microtime(true) + 1.5;
                    $queuedEntry['delayed'] = false;
                    $queuedEntry['pid'] = 0;
                    $queuedEntry['port'] = $port;
                    $this->resurrectQueue[$key] = $queuedEntry;
                    WlsLogger::error_(
                        "[Orchestrator] {$entry['role']}#{$entry['instanceId']} 复活任务异常，1.5s 后重试：{$throwable->getMessage()}"
                    );

                    return;
                }
                if ($newInstance !== null) {
                    $newInstance->restarts = $oldRestarts;
                    $this->persistServicesInfo($this->context);
                    unset($this->resurrectQueue[$key]);
                    return;
                }

                $infraBudget = (int) ($entry['infraRetryBudget'] ?? 0);
                if ($infraBudget > 0) {
                    $left = $infraBudget - 1;
                    if ($left > 0) {
                        WlsLogger::warning_(
                            "[Orchestrator] {$entry['role']}#{$entry['instanceId']} 复活启动失败，1.5s 后再试（剩余 {$left} 次）"
                        );
                        $this->resurrectQueue[$key] = [
                            'role' => $entry['role'],
                            'instanceId' => $entry['instanceId'],
                            'maxRestarts' => 10,
                            'restartDelay' => 1.5,
                            'scheduledAt' => \microtime(true) + 1.5,
                            'delayed' => false,
                            'pid' => 0,
                            'tracking_pid' => 0,
                            'port' => $port,
                            'infraRetryBudget' => $left,
                        ];
                        return;
                    }
                    unset($this->resurrectQueue[$key]);
                    WlsLogger::error_("[Orchestrator] {$entry['role']} 本地复活已用尽，触发整组重启");
                    $this->requestFullRestart("infra_resurrect_exhausted:{$entry['role']}");
                    return;
                }

                unset($this->resurrectQueue[$key]);
            })) {
                $entry['launching'] = false;
                unset($entry['launchingAt']);
                $this->resurrectQueue[$key] = $entry;
            }
        }
    }

    /**
     * Session / Memory 服务 IPC 断开：广播降级 → 排队复活（最多 N 次），失败再整组重启。
     */
    private function handleInfraServiceIpcDisconnect(ServiceInstance $instance): void
    {
        if ($this->context === null) {
            return;
        }

        $this->infraDegraded[$instance->role] = true;
        WlsLogger::warning_(
            "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开：向 Worker 广播 ROUTING_POLICY（端点降级），随后复活（最多 {$this->infraServiceResurrectAttempts} 次）"
        );
        $this->broadcastRoutingPolicyToWorkers();

        $key = $instance->getKey();
        $trackingPid = $this->getInstanceTrackingPid($instance);
        $processStillRunning = $trackingPid > 0 && $this->isProcessRunning($trackingPid);
        $delay = $processStillRunning ? \max(2.0, $this->ipcReconnectGraceSec) : 0.0;
        $port = (int) ($instance->port ?? 0);

        if (isset($this->resurrectQueue[$key])) {
            $this->resurrectQueue[$key]['infraRetryBudget'] = $this->infraServiceResurrectAttempts;
            if (($this->resurrectQueue[$key]['scheduledAt'] ?? 0.0) > \microtime(true) + $delay) {
                $this->resurrectQueue[$key]['scheduledAt'] = \microtime(true) + $delay;
            }
            WlsLogger::info_("[Orchestrator] {$key} 已在复活队列，刷新重试预算与调度时间");

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
            'delayed' => $processStillRunning,
            'pid' => $instance->pid,
            'tracking_pid' => $trackingPid,
            'root_pid' => $instance->getRootPid(),
            'launcher_pid' => $instance->getLauncherPid(),
            'port' => $port,
            'infraRetryBudget' => $this->infraServiceResurrectAttempts,
        ];
    }

    /**
     * 复活前确保旧进程已经真正退出，优先按真实 PID 终止，避免 PID 文件滞后误杀失败。
     */
    private function terminateStaleProcessBeforeResurrection(?ServiceInstance $oldInstance, int $trackingPid, int $port): bool
    {
        if ($trackingPid <= 0) {
            return $this->ensurePortReleasedForResurrection($port);
        }

        if (!$this->isProcessRunning($trackingPid)) {
            return $this->ensurePortReleasedForResurrection($port);
        }

        WlsLogger::warning_("[Orchestrator] 进程 {$trackingPid} 仍在运行（IPC已断开），优先按进程树终止");

        $stopped = Processer::gracefulKill($trackingPid, 1.0, true);
        if (!$stopped) {
            $stopped = Processer::killProcessTreeByPid($trackingPid, true);
        }
        if (!$stopped && $oldInstance !== null) {
            $this->killInstanceProcess($oldInstance);
            $stopped = !$this->isProcessRunning($trackingPid);
        }

        if ($port > 0) {
            Processer::clearPortCache($port);
        }

        return $stopped && !$this->isProcessRunning($trackingPid) && $this->ensurePortReleasedForResurrection($port);
    }

    /**
     * 复活前确认监听端口已释放，必要时再次清理己方残留占用。
     */
    private function ensurePortReleasedForResurrection(int $port): bool
    {
        if ($port <= 0) {
            return true;
        }

        if ($this->waitForPortRelease($port, 1.5)) {
            return true;
        }

        if ($this->shouldUseFastBindProbeForPortChecks()) {
            WlsLogger::warning_("[Orchestrator] port {$port} is still not bindable after cleanup; defer resurrection without netstat scan");
            return false;
        }

        if (Processer::isPortUsedByWeline($port)) {
            WlsLogger::warning_("[Orchestrator] 端口 {$port} 仍被 Weline 进程占用，尝试按端口清理");
            Processer::killProcessByPort($port);
            Processer::forceReleasePort($port);
        }

        return $this->waitForPortRelease($port, 1.5);
    }

    /**
     * 等待端口释放，同时清理端口缓存，避免 Linux 侧短期缓存误判。
     */
    private function waitForPortRelease(int $port, float $timeout): bool
    {
        if ($port <= 0) {
            return true;
        }

        if ($this->shouldUseFastBindProbeForPortChecks()) {
            $deadline = \microtime(true) + $timeout;
            do {
                if ($this->shouldYieldPeriodicWork(true)) {
                    return false;
                }
                if ($this->isPortFreeByBindProbe($port)) {
                    return true;
                }
                if (!$this->sleepInterruptiblyForPeriodicWork(100000)) {
                    return false;
                }
            } while (\microtime(true) < $deadline);

            return $this->isPortFreeByBindProbe($port);
        }

        $deadline = \microtime(true) + $timeout;
        do {
            if ($this->shouldYieldPeriodicWork(true)) {
                return false;
            }
            Processer::clearPortCache($port);
            if (!Processer::isPortInUse($port)) {
                return true;
            }
            if (!$this->sleepInterruptiblyForPeriodicWork(100000)) {
                return false;
            }
        } while (\microtime(true) < $deadline);

        Processer::clearPortCache($port);
        return !Processer::isPortInUse($port);
    }

    /**
     * 获取所有服务状态
     */
    public function getStatus(): array
    {
        $metricsAggregator = $this->getMetricsAggregator();
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
            'desired_state' => $this->desiredState,
            'control_operation' => [
                'active' => $this->activeControlOperation === null ? null : [
                    'id' => $this->activeControlOperation['id'],
                    'action' => $this->activeControlOperation['action'],
                    'state' => $this->activeControlOperation['state'],
                    'client_id' => $this->activeControlOperation['clientId'],
                ],
                'queued' => \array_map(static fn(array $operation): array => [
                    'id' => (string)$operation['id'],
                    'action' => (string)$operation['action'],
                    'state' => (string)$operation['state'],
                    'client_id' => (int)$operation['clientId'],
                ], $this->pendingControlOperations),
            ],
            'services' => $this->registry->getStatusSnapshot(),
            'resurrect_queue' => \count($this->resurrectQueue),
            'metrics' => [
                'register_timeout_count' => $this->registerTimeoutCount,
                'full_restart_count' => $this->fullRestartCount,
                'last_sweep_killed' => $this->lastSweepKilled,
                'last_sweep_stale_pid_files' => $this->lastSweepStalePidFiles,
                'telemetry_bucket_count' => $metricsAggregator->getBufferedBucketCount(),
                'telemetry_retry_queue_count' => $metricsAggregator->getRetryQueueCount(),
            ],
        ];
    }

    /**
     * 处理 IPC 消息
     */
    /**
     * Lightweight control-plane status for CLI liveness checks.
     *
     * The full status payload contains every service snapshot and telemetry
     * counters; under load that can exceed short CLI timeouts and falsely mark
     * a healthy Master as stopped.
     */
    public function getBriefStatus(): array
    {
        $snapshot = $this->registry->getStatusSnapshot();
        $summary = [];
        $serviceCount = 0;
        $runningCount = 0;

        foreach ($snapshot as $role => $service) {
            $instances = \is_array($service['instances'] ?? null) ? $service['instances'] : [];
            $roleTotal = \count($instances);
            $roleRunning = 0;
            foreach ($instances as $instance) {
                if (!\is_array($instance)) {
                    continue;
                }

                $state = (string)($instance['state'] ?? '');
                if ($state === 'running' || $state === 'ready' || $state === 'starting' || $state === 'registered') {
                    $roleRunning++;
                }
            }

            $summary[(string)$role] = [
                'total' => $roleTotal,
                'running' => $roleRunning,
            ];
            $serviceCount += $roleTotal;
            $runningCount += $roleRunning;
        }

        return [
            'running' => $this->running,
            'shutting_down' => $this->shuttingDown,
            'control_port' => $this->controlServer?->getPort() ?? 0,
            'ha_mode' => $this->haMode,
            'epoch' => $this->context?->epoch ?? 0,
            'maintenance_mode' => $this->maintenanceMode,
            'rolling_restart_in_progress' => $this->rollingRestartInProgress,
            'desired_state' => $this->desiredState,
            'service_count' => $serviceCount,
            'running_service_count' => $runningCount,
            'services_summary' => $summary,
        ];
    }

    public function handleIpcMessage(array $msg, int $clientId, ControlPlaneServerInterface $server): void
    {
        $type = $msg['type'] ?? '';

        // 通用消息处理
        switch ($type) {
            case ControlMessage::TYPE_MAINTENANCE_MODE_ACK:
                $this->handleMaintenanceModeAck($msg, $clientId);
                return;

            case ControlMessage::TYPE_REGISTER:
                $this->handleRegister($msg, $clientId);
                return;

            case ControlMessage::TYPE_READY:
                $this->handleReady($msg, $clientId);
                return;

            case ControlMessage::TYPE_LOG:
                $this->handleChildLogLine($msg, $clientId);
                return;

            case ControlMessage::TYPE_WORKER_POOL_ACK:
                $this->handleWorkerPoolAck($msg, $clientId);
                return;

            case ControlMessage::TYPE_ROUTE_TABLE_ACK:
                // B-i 阶段：仅观测路由表 ACK，不联动 Worker 生死与业务路由源。
                $this->handleRouteTableAck($msg, $clientId);
                return;

            case ControlMessage::TYPE_ROUTE_OBSERVATION:
                // B-i 阶段：仅记录身份/路由观察事件，留待 B-ii/B-iii 据此收敛。
                $this->handleRouteObservation($msg, $clientId);
                return;

            case ControlMessage::TYPE_WORKER_LOOP_STARTED:
                $this->handleWorkerLoopStarted($msg, $clientId);
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

            case ControlMessage::TYPE_FIBER_POOL_STATS:
                $this->handleFiberPoolStatsReply($msg, $clientId);
                return;

            case ControlMessage::TYPE_TELEMETRY:
                $this->handleTelemetry($msg);
                return;

            case ControlMessage::TYPE_DISPATCHER_ALERT:
                $this->handleDispatcherAlert($msg, $clientId);
                return;

            case ControlMessage::TYPE_STATUS_REPORT:
                $this->auditChildStatusReport($msg, $clientId);
                $this->delegateToProvider($msg, $clientId);
                return;

            // 批量协调消息处理
            case ControlMessage::TYPE_BATCH_ACK:
                $this->handleBatchAck($msg, $clientId);
                return;

            case ControlMessage::TYPE_BATCH_RESPONSE:
                $this->handleBatchResponse($msg, $clientId);
                return;
        }

        // 非通用消息：委托给对应 Provider 处理
        $this->delegateToProvider($msg, $clientId);
    }

    private function handleChildLogLine(array $msg, int $clientId): void
    {
        $line = \trim((string)($msg['line'] ?? ''));
        if ($line === '' || !\str_contains($line, '[WorkerWarmup]')) {
            return;
        }

        $instance = $this->registry->getInstanceByIpcClient($clientId);
        if ($this->context === null || $instance === null || $instance->role !== ControlMessage::ROLE_WORKER) {
            return;
        }

        $rawMessage = \trim((string)\preg_replace('/^\[WorkerWarmup\]\s*/', '', $line));
        if ($rawMessage === '') {
            return;
        }

        $workerId = $this->extractWorkerIdFromWarmupMessage($rawMessage);
        if ($workerId <= 0) {
            $workerId = $instance->instanceId;
        }
        if (\str_contains($rawMessage, 'warmup_started')) {
            return;
        }
        $kind = (\str_contains($rawMessage, 'warmup_failed') || \str_contains($rawMessage, 'failed'))
            ? 'worker_warmup_failed'
            : 'worker_warmup_success';
        $message = 'worker ' . $workerId . ' ' . ($kind === 'worker_warmup_failed' ? 'warmup failed' : 'warmup success');

        $this->appendStartupProgressEvent($this->context, $message, $kind, [
            'role' => $instance->role,
            'instance_id' => $instance->instanceId,
            'worker_id' => $workerId,
            'pid' => $instance->pid,
            'port' => $instance->port,
        ]);
    }

    private function extractWorkerIdFromWarmupMessage(string $message): int
    {
        if (\preg_match('/Worker(\d+)/', $message, $matches) === 1) {
            return (int)$matches[1];
        }

        return 0;
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
        $role              = $msg['role'] ?? '';
        $pid               = (int) ($msg['pid'] ?? 0);
        $port              = (int) ($msg['port'] ?? 0);
        $workerId          = (int) ($msg['worker_id'] ?? 0);
        $instanceIdFromMsg = (int) ($msg['instance_id'] ?? 0);
        $epoch             = (int) ($msg['epoch'] ?? 0);
        $launchId          = (string) ($msg['launch_id'] ?? '');
        $slotId            = (string) ($msg['slot_id'] ?? '');
        $leaseId           = (string) ($msg['lease_id'] ?? '');
        $generation        = (int) ($msg['generation'] ?? 0);
        $processKind       = (string) ($msg['process_kind'] ?? ControlMessage::PROCESS_KIND_FRAMEWORK);
        $moduleCode        = (string) ($msg['module_code'] ?? '');

        // 代际校验：只接纳当前 epoch
        if ($this->context !== null && $epoch > 0 && $epoch !== $this->context->epoch) {
            $this->rejectUntrustedChild($clientId, $role, $workerId, $port, 'stale_epoch', (string)($msg['msg_id'] ?? ''));
            WlsLogger::warning_("[Orchestrator] 丢弃旧代际 register: role={$role}, epoch={$epoch}, current_epoch={$this->context->epoch}");
            return;
        }

        // 查找匹配的实例
        $instances = $this->registry->getInstancesByRole($role);

        // 策略0：launch_id 精确匹配（最稳妥）
        if ($launchId !== '') {
            foreach ($instances as $instance) {
                if ($instance->launchId === $launchId) {
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode, $slotId, $leaseId, $generation)) {
                        return;
                    }
                }
            }
        }

        // 策略1：port 匹配（最可靠）
        if ($port > 0) {
            foreach ($instances as $instance) {
                if ($instance->port === $port) {
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode, $slotId, $leaseId, $generation)) {
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
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode, $slotId, $leaseId, $generation)) {
                        return;
                    }
                }
            }
        }

        // 策略3：PID 匹配（Windows 下可能不准确）
        if ($pid > 0) {
            foreach ($instances as $instance) {
                if ($instance->matchesManagedPid($pid)) {
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode, $slotId, $leaseId, $generation)) {
                        return;
                    }
                }
            }
        }

        // 策略4：如果只有一个 STARTING 状态的同角色实例，认为就是它
        $startingInstances = \array_filter($instances, fn($i) => $i->state === ServiceInstance::STATE_STARTING && $i->ipcClientId === null);
        if (\count($startingInstances) === 1) {
            $instance = \reset($startingInstances);
            if (!$this->isCurrentLeaseIdentity($instance, $slotId, $leaseId, $generation)) {
                WlsLogger::warning_("[Orchestrator] 拒绝弱匹配 STARTING 槽位: {$role}#{$instance->instanceId}, slot_id={$slotId}, lease_id={$leaseId}, generation={$generation}");
                $this->rejectUntrustedChild($clientId, $role, $workerId, $port, 'missing_or_stale_lease', (string)($msg['msg_id'] ?? ''), $slotId, $leaseId, $generation);
                return;
            }
            WlsLogger::info_("[Orchestrator] 匹配到唯一 STARTING 实例: {$role}#{$instance->instanceId}");
            if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode, $slotId, $leaseId, $generation)) {
                return;
            }
        }

        WlsLogger::warning_("[Orchestrator] 未找到匹配的实例: role={$role}, pid={$pid}, port={$port}, workerId={$workerId}, epoch={$epoch}, launch_id={$launchId}");
        if (\in_array($role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
            $this->rejectUntrustedChild($clientId, $role, $workerId, $port, 'no_matching_slot', (string)($msg['msg_id'] ?? ''));
            return;
        }
        if ($pid > 0 && $this->shouldTerminateUnmatchedRegisterPid($role, $pid, $port, $launchId)) {
            $killed = Processer::killByPid($pid);
            if ($killed) {
                WlsLogger::warning_("[Orchestrator] 已终止未匹配 register 进程: role={$role}, pid={$pid}");
            }
        }
        $this->controlServer?->closeClient($clientId);
    }

    private function shouldTerminateUnmatchedRegisterPid(string $role, int $pid = 0, int $port = 0, string $launchId = ''): bool
    {
        if (!\in_array(
            $role,
            [
                ControlMessage::ROLE_WORKER,
                ControlMessage::ROLE_MAINTENANCE,
                ControlMessage::ROLE_DISPATCHER,
                ControlMessage::ROLE_REDIRECT,
            ],
            true
        )) {
            return false;
        }

        if ($this->shouldSuppressUnmatchedRegisterTermination($role, $pid, $port, $launchId)) {
            return false;
        }

        return (bool) ($this->context?->getConfig(
            'wls.orchestrator.kill_unmatched_register_processes',
            true
        ) ?? true);
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
        string $launchId = '',
        string $processKind = ControlMessage::PROCESS_KIND_FRAMEWORK,
        string $moduleCode = '',
        string $slotId = '',
        string $leaseId = '',
        int $generation = 0
    ): bool
    {
        $port = (int) ($instance->port ?? 0);
        if ($this->context !== null && $epoch > 0 && $epoch !== $this->context->epoch) {
            WlsLogger::warning_("[Orchestrator] 忽略旧代际实例注册 {$instance->role}#{$instance->instanceId}: epoch={$epoch}");
            return false;
        }
        if (!$this->isCurrentLeaseIdentity($instance, $slotId, $leaseId, $generation)) {
            WlsLogger::warning_(
                "[Orchestrator] 忽略非当前租约注册 {$instance->role}#{$instance->instanceId}: "
                . "slot_id={$slotId}, lease_id={$leaseId}, generation={$generation}, "
                . "expected_slot={$this->getInstanceSlotId($instance)}, expected_lease={$this->getInstanceLeaseId($instance)}, expected_generation={$this->getInstanceGeneration($instance)}"
            );
            $this->rejectUntrustedChild($clientId, $instance->role, $workerId, $port, 'missing_or_stale_lease', '', $slotId, $leaseId, $generation);
            return false;
        }
        if ($instance->role === ControlMessage::ROLE_WORKER
            && $instance->state === ServiceInstance::STATE_READY
            && $instance->ipcClientId !== null
            && $instance->ipcClientId !== $clientId
            && $instance->getMeta('dispatcher_pool_confirmed_at') !== null
            && $this->controlServer !== null
            && $this->controlServer->clientExists($instance->ipcClientId)) {
            WlsLogger::warning_(
                "[Orchestrator] 额外 Worker register 已拒绝: slot=worker#{$instance->instanceId}, "
                . "current_client={$instance->ipcClientId}, extra_client={$clientId}, "
                . "current_pid={$instance->pid}, extra_pid={$pid}, current_port={$instance->port}, "
                . "current_launch={$instance->launchId}, extra_launch={$launchId}"
            );
            $this->rejectUntrustedChild($clientId, $instance->role, $workerId, $port, 'slot_already_owned');
            $this->lastDispatcherRouteTableSignature = '';
            $this->syncDispatcherFullWorkerPoolFromRegistry();
            $this->reconcileRoleSlotGaps(ControlMessage::ROLE_WORKER);
            return true;
        }

        if ($launchId !== '' && $instance->launchId !== '' && $instance->launchId !== $launchId) {
            if ($this->canReplaceExpiredPendingReady($instance, $clientId, $pid, $launchId)) {
                WlsLogger::warning_(
                    "[Orchestrator] 丢弃超时未确认 READY 并接管槽位: {$instance->role}#{$instance->instanceId}"
                    . " old_launch={$instance->launchId}, new_launch={$launchId}, clientId={$clientId}, pid={$pid}"
                );
                $this->resetPendingReadyConfirmation($instance, $clientId);
            } else {
                WlsLogger::warning_("[Orchestrator] 忽略 launchId 不匹配注册 {$instance->role}#{$instance->instanceId}: msg={$launchId}, expected={$instance->launchId}");
                $this->rejectUntrustedChild($clientId, $instance->role, $workerId, $port, 'launch_id_mismatch', $launchId);
                return false;
            }
        }

        $instance->ipcClientId = $clientId;
        $instance->state = ServiceInstance::STATE_REGISTERED;
        if ($epoch > 0) {
            $instance->epoch = $epoch;
        }
        if ($launchId !== '') {
            $instance->launchId = $launchId;
        }
        // 记录进程归属类型和模块代码
        if ($processKind !== ControlMessage::PROCESS_KIND_FRAMEWORK) {
            $instance->processKind = $processKind;
        }
        if ($moduleCode !== '') {
            $instance->moduleCode = $moduleCode;
        }
        // Windows 前台启动返回的可能是 wrapper/root PID，register 上报的是实际服务 PID。
        if ($pid > 0 && $instance->pid !== $pid) {
            WlsLogger::debug_(
                "[Orchestrator] 更新服务 PID: {$instance->role}#{$instance->instanceId}"
                . " service={$instance->pid} -> {$pid}, root={$instance->getRootPid()}"
            );
        }
        $this->applyRegisteredServicePid($instance, $pid);
        $registerReceivedAt = \microtime(true);
        $instance->setMeta('register_received_at', $registerReceivedAt);
        if ($instance->startedAt > 0) {
            $instance->setMeta(
                'register_elapsed_ms',
                \max(0, (int) \round(($registerReceivedAt - $instance->startedAt) * 1000))
            );
        }
        if ($workerId > 0) {
            $instance->setMeta('worker_id', $workerId);
        }
        $hasLeaseMetadata = $instance->getMeta('lease_id', null) !== null
            || $instance->getMeta('slot_id', null) !== null
            || $instance->getMeta('generation', null) !== null;
        $hasIncomingLeaseIdentity = $slotId !== '' || $leaseId !== '' || $generation > 0;
        if ($hasLeaseMetadata || $hasIncomingLeaseIdentity) {
            if ($generation > 0) {
                $this->rememberSlotGeneration($slotId !== '' ? $slotId : $this->getInstanceSlotId($instance), $generation);
            }
            if ($slotId !== '') {
                $instance->setMeta('slot_id', $slotId);
            }
            if ($leaseId !== '') {
                $instance->setMeta('lease_id', $leaseId);
            }
            if ($generation > 0) {
                $instance->setMeta('generation', $generation);
            }
            $instance->setMeta('lease_state', 'registered');
        }
        $this->registry->updateInstance($instance);
        if ($this->context !== null) {
            $this->persistServicesInfo($this->context);
        }

        $resurrectKey = $instance->getKey();
        if (isset($this->resurrectQueue[$resurrectKey])) {
            unset($this->resurrectQueue[$resurrectKey]);
            WlsLogger::info_("[Orchestrator] {$instance->role}#{$instance->instanceId} 已重新注册，取消待执行复活");
        }

        if (\in_array($instance->role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
            $this->sendRoutingPolicyToWorker($instance);
        }

        $this->logStartupTiming($instance, 'registered', [
            'client_id' => $clientId,
            'worker_id' => $workerId > 0 ? $workerId : null,
            'register_elapsed_ms' => $instance->getMeta('register_elapsed_ms'),
            'process_kind' => $processKind !== ControlMessage::PROCESS_KIND_FRAMEWORK ? $processKind : null,
            'module_code' => $moduleCode !== '' ? $moduleCode : null,
        ]);
        $this->traceStartup('child_registered', [
            'role' => $instance->role,
            'instance_id' => $instance->instanceId,
            'pid' => $instance->pid,
            'port' => $instance->port,
            'client_id' => $clientId,
            'elapsed_ms' => $instance->getMeta('register_elapsed_ms'),
        ]);

        $kindInfo = $processKind !== ControlMessage::PROCESS_KIND_FRAMEWORK
            ? ", kind={$processKind}" . ($moduleCode !== '' ? "({$moduleCode})" : '')
            : '';
        WlsLogger::debug_("[Orchestrator] IPC 注册: {$instance->role}#{$instance->instanceId} (pid={$pid}, clientId={$clientId}, port={$instance->port}, epoch={$instance->epoch}, launch_id={$instance->launchId}{$kindInfo})");
        return true;
    }

    private function rejectUntrustedChild(
        int $clientId,
        string $role,
        int $workerId,
        ?int $port,
        string $reason,
        string $msgId = '',
        string $slotId = '',
        string $leaseId = '',
        int $generation = 0
    ): void {
        if ($this->controlServer === null) {
            return;
        }
        $port = \max(0, (int) $port);

        $this->controlServer->sendTo(
            $clientId,
            ControlMessage::readyAck($leaseId, $generation, false, $reason, $workerId, $port, $msgId, $slotId)
        );
        WlsLogger::warning_(
            "[Orchestrator] 已拒绝不可信子进程并要求自毁: role={$role}, worker_id={$workerId}, port={$port}, reason={$reason}, clientId={$clientId}"
        );
        $this->controlServer->closeClient($clientId);
    }

    private function isPendingReadyConfirmationExpired(ServiceInstance $instance): bool
    {
        $readyAt = (float)($instance->getMeta('ready_received_at') ?? $instance->getMeta('ready_at') ?? 0.0);
        if ($readyAt <= 0.0) {
            return false;
        }
        if ((\microtime(true) - $readyAt) < self::READY_CONFIRM_TIMEOUT_SEC) {
            return false;
        }

        if ($instance->role === ControlMessage::ROLE_WORKER) {
            return $instance->getMeta('dispatcher_pool_confirmed_at') === null;
        }

        return $instance->getMeta('ack_ready_at') === null;
    }

    private function canReplaceExpiredPendingReady(
        ServiceInstance $instance,
        int $clientId,
        int $pid,
        string $launchId
    ): bool {
        if (!$this->isPendingReadyConfirmationExpired($instance)) {
            return false;
        }
        if ($instance->ipcClientId !== $clientId) {
            return true;
        }
        if ($launchId !== '' && $instance->launchId !== '' && $instance->launchId !== $launchId) {
            return true;
        }
        return $pid > 0 && !$instance->matchesManagedPid($pid);
    }

    private function resetPendingReadyConfirmation(ServiceInstance $instance, int $newClientId, bool $closePreviousClient = true): void
    {
        $previousClientId = $instance->ipcClientId;
        if ($closePreviousClient && $previousClientId !== null && $previousClientId !== $newClientId) {
            $this->controlServer?->closeClient($previousClientId);
        }

        foreach ([
            'ready_at',
            'ready_received_at',
            'ready_elapsed_ms',
            'dispatcher_pool_confirmed_at',
            'dispatcher_pool_rejected_at',
            'ack_ready_at',
            'ack_ready_elapsed_ms',
        ] as $key) {
            $instance->setMeta($key, null);
        }
        $this->lastDispatcherRouteTableSignature = '';
        $this->registry->updateInstance($instance);
    }

    /**
     * 处理 ready 消息
     */
    private function handleReady(array $msg, int $clientId): void
    {
        $instance = $this->registry->getInstanceByIpcClient($clientId);
        if ($instance === null) {
            // 某些平台/高压场景下可能出现 register 丢失但 ready 到达，先尝试补绑再处理。
            $this->handleRegister($msg, $clientId);
            $instance = $this->registry->getInstanceByIpcClient($clientId);
            if ($instance === null) {
                $role = (string)($msg['role'] ?? '');
                $port = (int)($msg['port'] ?? 0);
                WlsLogger::warning_("[Orchestrator] ready 消息但未找到实例: clientId={$clientId}, role={$role}, port={$port}");
                $this->controlServer?->closeClient($clientId);
                return;
            }
        }

        $epoch = (int) ($msg['epoch'] ?? 0);
        $launchId = (string) ($msg['launch_id'] ?? '');
        $slotId = (string)($msg['slot_id'] ?? '');
        $leaseId = (string)($msg['lease_id'] ?? '');
        $generation = (int)($msg['generation'] ?? 0);
        if ($this->context !== null && $epoch > 0 && $epoch !== $this->context->epoch) {
            $this->rejectUntrustedChild(
                $clientId,
                $instance->role,
                (int)($msg['worker_id'] ?? $instance->getMeta('worker_id') ?? $instance->instanceId),
                (int)($msg['port'] ?? $instance->port ?? 0),
                'stale_epoch',
                (string)($msg['msg_id'] ?? '')
            );
            WlsLogger::warning_("[Orchestrator] 丢弃旧代际 ready: {$instance->role}#{$instance->instanceId}, epoch={$epoch}");
            return;
        }
        if (!$this->isCurrentLeaseIdentity($instance, $slotId, $leaseId, $generation)) {
            $this->rejectUntrustedChild(
                $clientId,
                $instance->role,
                (int)($msg['worker_id'] ?? $instance->getMeta('worker_id') ?? $instance->instanceId),
                (int)($msg['port'] ?? $instance->port ?? 0),
                'missing_or_stale_lease',
                (string)($msg['msg_id'] ?? ''),
                $slotId,
                $leaseId,
                $generation
            );
            WlsLogger::warning_(
                "[Orchestrator] 丢弃非当前租约 ready: {$instance->role}#{$instance->instanceId}, "
                . "slot_id={$slotId}, lease_id={$leaseId}, generation={$generation}, "
                . "expected_slot={$this->getInstanceSlotId($instance)}, expected_lease={$this->getInstanceLeaseId($instance)}, expected_generation={$this->getInstanceGeneration($instance)}"
            );
            return;
        }
        if ($launchId !== '' && $instance->launchId !== '' && $launchId !== $instance->launchId) {
            $this->rejectUntrustedChild(
                $clientId,
                $instance->role,
                (int)($msg['worker_id'] ?? $instance->getMeta('worker_id') ?? $instance->instanceId),
                (int)($msg['port'] ?? $instance->port ?? 0),
                'launch_id_mismatch',
                (string)($msg['msg_id'] ?? '')
            );
            WlsLogger::warning_("[Orchestrator] 丢弃 launchId 不匹配 ready: {$instance->role}#{$instance->instanceId}, msg={$launchId}, expected={$instance->launchId}");
            return;
        }

        // 更新实例端口（以上报的实际端口为准）
        $reportedPort = (int) ($msg['port'] ?? 0);
        if ($reportedPort > 0 && $reportedPort !== $instance->port) {
            WlsLogger::debug_("[Orchestrator] 更新 {$instance->role}#{$instance->instanceId} 端口: {$instance->port} -> {$reportedPort}");
            $instance->port = $reportedPort;
        }

        $isDuplicateReadyFromSameClient = $instance->state === ServiceInstance::STATE_READY
            && $instance->ipcClientId === $clientId;
        $readyAlreadyRecorded = $instance->getMeta('ready_at') !== null;
        $readyConfirmationExpired = $this->isPendingReadyConfirmationExpired($instance);
        if ($isDuplicateReadyFromSameClient && $readyAlreadyRecorded && !$readyConfirmationExpired) {
            $workerId = (int) ($instance->getMeta('worker_id') ?? $instance->instanceId);
            if ($instance->role !== ControlMessage::ROLE_WORKER || $instance->getMeta('dispatcher_pool_confirmed_at') !== null) {
                $this->controlServer?->sendTo(
                    $clientId,
                    ControlMessage::ackReady(
                        $workerId,
                        $instance->role === ControlMessage::ROLE_WORKER,
                        (int)($instance->port ?? 0),
                        (string)($msg['msg_id'] ?? ''),
                        $this->getInstanceSlotId($instance),
                        $this->getInstanceLeaseId($instance),
                        $this->getInstanceGeneration($instance)
                    )
                );
            }
            if ($instance->role === ControlMessage::ROLE_WORKER && $instance->port !== null && $instance->port > 0) {
                $this->convergeDispatcherRouteTableAfterWorkerReady();
            } elseif ($instance->role === ControlMessage::ROLE_DISPATCHER) {
                $this->syncDispatcherFullWorkerPoolFromRegistry(true);
            }
            WlsLogger::info_(
                "[Orchestrator] 重复 READY 已幂等处理: {$instance->role}#{$instance->instanceId} (clientId={$clientId}, port={$instance->port})"
            );
            if ($this->context !== null) {
                $this->persistServicesInfo($this->context);
            }
            return;
        }
        if ($isDuplicateReadyFromSameClient && $readyAlreadyRecorded && $readyConfirmationExpired) {
            WlsLogger::warning_(
                "[Orchestrator] READY 确认超过 " . self::READY_CONFIRM_TIMEOUT_SEC
                . "s 未完成，重置确认窗口: {$instance->role}#{$instance->instanceId} (clientId={$clientId}, port={$instance->port})"
            );
            $this->resetPendingReadyConfirmation($instance, $clientId, false);
        }

        $instance->state = ServiceInstance::STATE_READY;
        $readyReceivedAt = \microtime(true);
        $instance->setMeta('ready_at', $readyReceivedAt);
        $instance->setMeta('ready_received_at', $readyReceivedAt);
        if ($instance->startedAt > 0) {
            $instance->setMeta(
                'ready_elapsed_ms',
                \max(0, (int) \round(($readyReceivedAt - $instance->startedAt) * 1000))
            );
        }
        $this->registry->updateInstance($instance);

        $workerId = (int) ($instance->getMeta('worker_id') ?? $instance->instanceId);
        if ($instance->role === ControlMessage::ROLE_WORKER) {
            $instance->setMeta('dispatcher_pool_confirmed_at', null);
            $instance->setMeta('lease_state', 'ready_accepted');
            $instance->setMeta('listening_at', $readyReceivedAt);
            $this->registry->updateInstance($instance);
            $this->controlServer?->sendTo(
                $clientId,
                ControlMessage::ackReady(
                    $workerId,
                    false,
                    (int)($instance->port ?? 0),
                    (string)($msg['msg_id'] ?? ''),
                    $this->getInstanceSlotId($instance),
                    $this->getInstanceLeaseId($instance),
                    $this->getInstanceGeneration($instance)
                )
            );
            WlsLogger::info_("[Orchestrator] Worker accepted by Master immediately (port={$instance->port}, dispatcher_pool_confirmed=false)");
        } else {
            $this->controlServer?->sendTo(
                $clientId,
                ControlMessage::ackReady(
                    $workerId,
                    false,
                    (int)($instance->port ?? 0),
                    (string)($msg['msg_id'] ?? ''),
                    $this->getInstanceSlotId($instance),
                    $this->getInstanceLeaseId($instance),
                    $this->getInstanceGeneration($instance)
                )
            );
            $ackReadyAt = \microtime(true);
            $instance->setMeta('ack_ready_at', $ackReadyAt);
            $instance->setMeta('lease_state', 'ready');
            if ($instance->startedAt > 0) {
                $instance->setMeta(
                    'ack_ready_elapsed_ms',
                    \max(0, (int) \round(($ackReadyAt - $instance->startedAt) * 1000))
                );
            }
            $this->registry->updateInstance($instance);
            WlsLogger::info_("[Orchestrator] 服务就绪: {$instance->role}#{$instance->instanceId} (已发送 ACK, port={$instance->port})");
        }

        // Worker 就绪：通过 Registry 单一事实源 + 版本化全量路由表向所有 Dispatcher 收敛。
        // SET_ROUTE_TABLE 是默认权威，Master 仅在 Worker 状态变化时统一广播，不再按"是否首次/重复 READY"分支化。
        if ($instance->role === 'worker' && $instance->port !== null) {
            $this->convergeDispatcherRouteTableAfterWorkerReady();
        } elseif ($instance->role === ControlMessage::ROLE_DISPATCHER) {
            $this->syncDispatcherFullWorkerPoolFromRegistry(true);
        }

        // 维护 Worker 就绪：必须在维护模式下立即发布维护路由表。否则 Dispatcher 若早于维护进程 READY，
        // 下发会因「尚无 READY maintenance」跳过，池永久为空直至其它路径补偿。
        $this->logStartupTiming($instance, 'ready', [
            'client_id' => $clientId,
            'worker_id' => $workerId,
            'ready_elapsed_ms' => $instance->getMeta('ready_elapsed_ms'),
            'ack_ready_elapsed_ms' => $instance->getMeta('ack_ready_elapsed_ms'),
        ]);
        $this->traceStartup('child_ready', [
            'role' => $instance->role,
            'instance_id' => $instance->instanceId,
            'pid' => $instance->pid,
            'port' => $instance->port,
            'client_id' => $clientId,
            'elapsed_ms' => $instance->getMeta('ready_elapsed_ms'),
            'ack_elapsed_ms' => $instance->getMeta('ack_ready_elapsed_ms'),
        ]);
        if ($this->context !== null && $instance->role === ControlMessage::ROLE_WORKER) {
            $label = 'Worker' . ($workerId > 0 ? $workerId : $instance->instanceId);
            $this->appendStartupProgressEvent($this->context, 'worker ' . ($workerId > 0 ? $workerId : $instance->instanceId) . ' ready', 'worker_ready', [
                'role' => $instance->role,
                'instance_id' => $instance->instanceId,
                'worker_id' => $workerId,
                'pid' => $instance->pid,
                'port' => $instance->port,
            ]);
        }
        if ($this->context?->windowMode && $instance->role === ControlMessage::ROLE_WORKER) {
            $label = 'Worker' . ($workerId > 0 ? $workerId : $instance->instanceId);
            echo "\033[32m  {$label} 已就绪，等待预热...\033[0m\n";
            if (\function_exists('flush')) {
                @\flush();
            }
        }

        if ($instance->role === ControlMessage::ROLE_MAINTENANCE
            && $this->maintenanceMode
            && $instance->port !== null
            && $instance->port > 0) {
            $this->pushMaintenanceWorkerPoolToDispatchersFromRegistry();
        }

        // Dispatcher 就绪：维护模式下发维护池；否则用 Registry 同步业务 Worker 池，并下发 HTTP Redirect 端口。
        if ($instance->role === 'dispatcher') {
            if ($this->maintenanceMode) {
                $this->pushMaintenanceWorkerPoolToDispatchersFromRegistry();
            } else {
                $this->syncDispatcherFullWorkerPoolFromRegistry();
            }
            $this->sendRedirectPortToDispatcher($instance);
        }
        
        // 如果是 Redirect 就绪，通知所有已就绪的 Dispatcher
        if ($instance->role === 'redirect' && $instance->port !== null) {
            $this->notifyDispatcherRedirectReady($instance);
        }

        if (($this->infraDegraded[$instance->role] ?? false) === true) {
            $this->infraDegraded[$instance->role] = false;
            WlsLogger::info_("[Orchestrator] {$instance->role} 已 READY，解除端点降级并广播 ROUTING_POLICY");
            $this->broadcastRoutingPolicyToWorkers();
        }
        
        // 刷新 Master endpoint 元数据，不持久化服务拓扑。
        if ($this->context !== null) {
            $this->persistServicesInfo($this->context);
        }
        
        // 检查是否所有服务都已就绪，输出服务器准备就绪通知
        $this->checkAndNotifyServerReady();
    }

    /**
     * Dispatcher 入池回执闭环：
     * - Dispatcher 每次处理 add/set 都会回执 in_pool=true/false
     * - 仅当 in_pool=true 时，Master 才向目标 Worker 回 ACK_READY 停止重报
     */
    private function handleWorkerPoolAck(array $msg, int $clientId): void
    {
        $role = (string)($msg['role'] ?? ControlMessage::ROLE_WORKER);
        $port = (int)($msg['port'] ?? 0);
        $inPool = (bool)($msg['in_pool'] ?? false);
        $slotId = (string)($msg['slot_id'] ?? '');
        $leaseId = (string)($msg['lease_id'] ?? '');
        $generation = (int)($msg['generation'] ?? 0);
        if ($role === ControlMessage::ROLE_MAINTENANCE) {
            if ($port <= 0
                || !$inPool
                || $this->pendingMaintenanceModeAck === null
                || ($this->pendingMaintenanceModeAck['kind'] ?? '') !== 'dispatcher_pool') {
                return;
            }

            $ackKey = $clientId . ':' . $port;
            if (!isset($this->pendingMaintenanceModeAck['expected'][$ackKey])) {
                return;
            }

            $this->pendingMaintenanceModeAck['acked'][$ackKey] = true;
            $this->logMaintenanceOperation(
                'Dispatcher 维护池确认: client=' . $clientId . ', port=' . $port
                . '，' . $this->formatMaintenanceOperationContext(),
                'INFO',
                'maintenance_dispatcher_pool_ack:' . $ackKey,
                0.0
            );
            return;
        }
        if ($role !== ControlMessage::ROLE_WORKER) {
            return;
        }

        if ($port <= 0) {
            return;
        }

        $worker = null;
        if ($slotId !== '') {
            foreach ($this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER) as $candidate) {
                if ($this->getInstanceSlotId($candidate) === $slotId) {
                    $worker = $candidate;
                    break;
                }
            }
            if ($worker !== null && !$this->isCurrentLeaseIdentity($worker, $slotId, $leaseId, $generation)) {
                WlsLogger::warning_(
                    "[Orchestrator] 丢弃旧租约 worker_pool_ack: slot_id={$slotId}, lease_id={$leaseId}, generation={$generation}, port={$port}, "
                    . "expected_lease={$this->getInstanceLeaseId($worker)}, expected_generation={$this->getInstanceGeneration($worker)}"
                );
                return;
            }
        } else {
            foreach ($this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER) as $candidate) {
                if ((int)($candidate->port ?? 0) === $port) {
                    $worker = $candidate;
                    break;
                }
            }
        }
        if ($worker === null) {
            WlsLogger::warning_("[Orchestrator] 收到 worker_pool_ack 但未匹配到 Worker: port={$port}, dispatcher_client_id={$clientId}");
            return;
        }

        if (!$inPool) {
            $failedAt = \microtime(true);
            $worker->setMeta('dispatcher_pool_rejected_at', $failedAt);
            $worker->setMeta(
                'dispatcher_pool_reject_count',
                (int) ($worker->getMeta('dispatcher_pool_reject_count') ?? 0) + 1
            );
            // Pool rejection is a routing event, not a Worker lifecycle event.
            // Master decides Worker health independently; just resend route table.
            $this->lastDispatcherRouteTableSignature = '';
            $taskKey = "worker_pool_recover:{$port}";
            if (!$this->hasMainLoopTask($taskKey)) {
                $this->scheduleMainLoopTask($taskKey, 'worker_pool_recover', function () use ($port): void {
                    SchedulerSystem::yieldDelay(120);
                    $this->syncDispatcherFullWorkerPoolFromRegistry();
                });
            }
            WlsLogger::warning_(
                "[Orchestrator] Dispatcher reports worker not in pool (routing event), resending route table: worker#{$worker->instanceId}, port={$port}"
            );
            return;
        }

        // Dispatcher pool confirmed: only update registry metadata.
        // Worker already received READY_ACCEPTED in handleReady(); Dispatcher pool is a separate routing event.
        $ackReadyAt = \microtime(true);
        $worker->setMeta('dispatcher_pool_confirmed_at', $ackReadyAt);
        $worker->setMeta('lease_state', 'dispatcher_active');
        if ($worker->startedAt > 0) {
            $worker->setMeta(
                'ack_ready_elapsed_ms',
                \max(0, (int) \round(($ackReadyAt - $worker->startedAt) * 1000))
            );
        }
        $this->registry->updateInstance($worker);
        WlsLogger::info_("[Orchestrator] Dispatcher confirmed pool (routing event), registry updated: worker#{$worker->instanceId}, port={$port}, dispatcher_client_id={$clientId}");
        if ($this->context !== null) {
            $this->persistServicesInfo($this->context);
        }
        $this->checkAndNotifyServerReady();
    }

    /**
     * B-i 阶段：处理 Dispatcher 回执的路由表版本 ACK。
     *
     * 当前行为：
     * - 只做观测日志：是否与本地最近发布的版本/checksum 一致；
     * - 在 Dispatcher 实例 meta 中记录最新接受的 route_version + checksum（供管理面查询）；
     * - 不联动 Worker 生死；Worker 生命周期仍由 Master Registry 管理。
     *
     * B-ii 阶段将让本回执参与路由源切换（当前阶段保持纯观测）。
     */
    private function handleRouteTableAck(array $msg, int $clientId): void
    {
        $role = (string)($msg['role'] ?? ControlMessage::ROLE_WORKER);
        $routeVersion = (int)($msg['route_version'] ?? 0);
        $checksum = (string)($msg['checksum'] ?? '');
        $status = (string)($msg['status'] ?? '');
        $reason = (string)($msg['reason'] ?? '');
        $epoch = (int)($msg['epoch'] ?? 0);

        $dispatcher = null;
        foreach ($this->registry->getInstancesByRole(ControlMessage::ROLE_DISPATCHER) as $candidate) {
            if ($candidate->ipcClientId === $clientId) {
                $dispatcher = $candidate;
                break;
            }
        }

        $cacheKey = $role . ':' . $epoch;
        $expected = $this->lastDispatcherRouteTablePublish[$cacheKey] ?? null;
        $matches = $expected !== null
            && $expected['route_version'] === $routeVersion
            && $expected['checksum'] === $checksum;

        if ($dispatcher !== null) {
            $dispatcher->setMeta('last_route_table_ack_version', $routeVersion);
            $dispatcher->setMeta('last_route_table_ack_checksum', $checksum);
            $dispatcher->setMeta('last_route_table_ack_status', $status);
            $dispatcher->setMeta('last_route_table_ack_at', \microtime(true));
            $this->registry->updateInstance($dispatcher);
        }

        $dispatcherLabel = $dispatcher !== null ? ('#' . $dispatcher->instanceId) : ('client=' . $clientId);
        WlsLogger::info_(\sprintf(
            '[Orchestrator] ROUTE_TABLE_ACK observed: dispatcher=%s, role=%s, version=%d, status=%s%s%s, matches_local_publish=%s',
            $dispatcherLabel,
            $role,
            $routeVersion,
            $status !== '' ? $status : 'applied',
            $reason !== '' ? ', reason=' . $reason : '',
            $checksum !== '' ? ', checksum=' . \substr($checksum, 0, 12) : '',
            $matches ? 'yes' : 'no'
        ));
    }

    /**
     * B-i 阶段：处理子进程上报的身份/路由观察事件。
     *
     * 仅记录日志 + 写入对应实例 meta（observed_event / observed_event_at）。
     * B-ii/B-iii 阶段将根据 event 类型触发收敛动作（例如 slot/lease 重分配）。
     */
    private function handleRouteObservation(array $msg, int $clientId): void
    {
        $role = (string)($msg['role'] ?? '');
        $event = (string)($msg['event'] ?? '');
        $detail = (string)($msg['detail'] ?? '');
        $slotId = (string)($msg['slot_id'] ?? '');
        $leaseId = (string)($msg['lease_id'] ?? '');
        $generation = (int)($msg['generation'] ?? 0);
        $port = (int)($msg['port'] ?? 0);

        if ($event === '') {
            return;
        }

        $instance = null;
        foreach ($this->registry->getAllInstances() as $candidate) {
            if ($candidate->ipcClientId === $clientId) {
                $instance = $candidate;
                break;
            }
        }
        if ($instance !== null) {
            $instance->setMeta('last_route_observation_event', $event);
            $instance->setMeta('last_route_observation_detail', $detail);
            $instance->setMeta('last_route_observation_at', \microtime(true));
            $this->registry->updateInstance($instance);
        }

        $instanceLabel = $instance !== null
            ? ($instance->role . '#' . $instance->instanceId)
            : ('client=' . $clientId);
        WlsLogger::info_(\sprintf(
            '[Orchestrator] ROUTE_OBSERVATION: instance=%s, role=%s, event=%s%s%s%s%s%s',
            $instanceLabel,
            $role !== '' ? $role : 'unknown',
            $event,
            $port > 0 ? ', port=' . $port : '',
            $slotId !== '' ? ', slot_id=' . $slotId : '',
            $leaseId !== '' ? ', lease_id=' . $leaseId : '',
            $generation > 0 ? ', generation=' . $generation : '',
            $detail !== '' ? ', detail=' . $detail : ''
        ));
    }

    /**
     * 检查所有服务是否就绪，如果是则输出服务器准备就绪通知
     */
    private function checkAndNotifyServerReady(): void
    {
        if (!$this->serverReadyNotificationArmed || $this->serverReadyNotified) {
            return;
        }

        $allInstances = $this->registry->getAllInstances();
        if (empty($allInstances)) {
            return;
        }

        // A worker reports READY only after its local warmup gate has completed.
        // In strict mode the public endpoint is not marked running until every
        // planned business worker has crossed that gate.
        $workerInstances = $this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER);
        $requiredWorkerReady = $this->resolveRequiredWorkerReadyCount(\count($workerInstances));
        if ($workerInstances !== [] && $this->countRoleReadyInstances(ControlMessage::ROLE_WORKER) < $requiredWorkerReady) {
            return;
        }

        // 检查所有实例是否都已就绪（自动维护池 Worker 不阻塞整站 running 标记）
        foreach ($allInstances as $instance) {
            if ($instance->role === ControlMessage::ROLE_WORKER) {
                continue;
            }
            if (!$this->isInstanceRequiredForServerReadyNotification($instance)) {
                continue;
            }
            if (!$this->isInstanceReadyForServerReadyNotification($instance)) {
                return;
            }
        }

        // 所有服务都已就绪
        $this->serverReadyNotified = true;

        $totalServices = \count($allInstances);
        if ($this->context !== null) {
            $this->markStartupPhaseRunning($this->context, $totalServices);
        }
        $mainPort = $this->context?->mainPort ?? 0;
        $bindHost = $this->context?->host ?? '127.0.0.1';
        $displayHost = $this->context?->publicHost ?? $bindHost;
        $sslEnabled = $this->context?->sslEnabled ?? false;
        $protocol = $sslEnabled ? 'https' : 'http';

        // 输出醒目的服务器准备就绪通知
        WlsLogger::info_('[Server] ========================================');
        WlsLogger::info_('[Server] ✓ 服务器准备就绪');
        WlsLogger::info_("[Server]   地址: {$protocol}://{$displayHost}:{$mainPort}");
        WlsLogger::info_("[Server]   服务实例: {$totalServices} 个");
        WlsLogger::info_('[Server] ========================================');

        // 前台模式：直接输出访问地址表与代理转发说明（不悬浮，日志正常滚动）
        if ($this->context?->windowMode) {
            $ctx = $this->context;
            $defaultPort = $sslEnabled ? 443 : 80;
            $baseUrl = $protocol . '://' . $displayHost . ($mainPort !== $defaultPort ? ':' . $mainPort : '');
            $backendPrefix = $ctx->getConfig('router.area_routes.backend.prefix') ?? '';
            $apiPath = $ctx->getConfig('router.area_routes.rest_frontend.prefix') ?: 'api';
            $apiAdminPath = $ctx->getConfig('router.area_routes.rest_backend.prefix') ?: 'api_admin';
            $httpRedirectPort = $ctx->httpRedirectPort ?? 0;

            $frontendUrl = $baseUrl . '/';
            $backendUrl = $baseUrl . '/' . ($backendPrefix !== '' ? $backendPrefix . '/' : '') . 'admin';
            $apiUrl = $baseUrl . '/' . $apiPath . '/';
            $apiAdminUrl = $baseUrl . '/' . $apiAdminPath . '/';
            $httpUrl = $sslEnabled && $httpRedirectPort > 0 ? "http://{$displayHost}:{$httpRedirectPort}/ → HTTPS" : null;

            $paddingX = 2;
            $colLabel = 16;
            $sep = 3;
            $allUrls = array_filter([$frontendUrl, $backendUrl, $apiUrl, $apiAdminUrl, $httpUrl]);
            $urlColWidth = max(array_map(fn(string $url): int => $this->getDisplayWidth($url), $allUrls));
            $contentWidth = $colLabel + $sep + $urlColWidth;
            $tableWidth = ($paddingX * 2) + $contentWidth;

            $B = self::ANSI_BRIGHT_CYAN;
            $Y = self::ANSI_YELLOW;
            $C = self::ANSI_CYAN;
            $G = self::ANSI_BRIGHT_GREEN;
            $BO = self::ANSI_BOLD;
            $R = self::ANSI_RESET;

            $hLine = fn() => "{$B}  ╠" . \str_repeat('═', $tableWidth) . "╣{$R}\n";
            $row = fn(string $label, string $url) => "{$B}  ║{$R}"
                . \str_repeat(' ', $paddingX)
                . "{$Y}" . $this->padToDisplayWidth($label, $colLabel) . "{$R}"
                . \str_repeat(' ', $sep)
                . "{$C}" . $this->padToDisplayWidth($url, $urlColWidth) . "{$R}"
                . \str_repeat(' ', $paddingX)
                . "{$B}║{$R}\n";

            $title = '  ✓ ' . $this->translateMessage('服务器已就绪');
            $titlePad = \max(0, $tableWidth - ($paddingX * 2) - $this->getDisplayWidth($title));
            $titleRow = "{$B}  ║{$R}"
                . \str_repeat(' ', $paddingX)
                . "{$BO}{$G}{$title}{$R}"
                . \str_repeat(' ', $titlePad)
                . \str_repeat(' ', $paddingX)
                . "{$B}║{$R}\n";

            echo "\n";
            echo "{$B}  ╔" . \str_repeat('═', $tableWidth) . "╗{$R}\n";
            echo $titleRow;
            echo $hLine();
            echo $row($this->translateMessage('前端'), $frontendUrl);
            echo $row($this->translateMessage('后端'), $backendUrl);
            echo $hLine();
            echo $row($this->translateMessage('REST API 前端'), $apiUrl);
            echo $row($this->translateMessage('REST API 后端'), $apiAdminUrl);
            if ($httpUrl) {
                echo $row($this->translateMessage('HTTP 重定向'), $httpUrl);
            }
            echo "{$B}  ╚" . \str_repeat('═', $tableWidth) . "╝{$R}\n";
            echo "\n";

            // 使用说明 - 深橙色提示
            echo self::ANSI_BOLD . self::ANSI_GREEN . "  " . $this->translateMessage('使用说明：') . self::ANSI_RESET . "\n";
            $tips = [
                $this->translateMessage('WLS 默认仅监听 127.0.0.1，仅本机可访问'),
                $this->translateMessage('外网访问需用 Nginx/Caddy 等反向代理转发到 %{1}:%{2}', [$bindHost, $mainPort]),
                $this->translateMessage('Nginx 示例：') . "proxy_pass {$protocol}://{$bindHost}:{$mainPort};",
                $this->translateMessage('需直连外网时：') . "php bin/w server:start --host 0.0.0.0",
            ];
            foreach ($tips as $tip) {
                echo "  " . self::ANSI_BRIGHT_ORANGE . "• " . self::ANSI_RESET . self::ANSI_ORANGE . $tip . self::ANSI_RESET . "\n";
            }
            echo "\n";
            if (\function_exists('flush')) {
                @\flush();
            }
        }
    }

    private function getDisplayWidth(string $value): int
    {
        return \mb_strwidth($value, 'UTF-8');
    }

    /**
     * Test and minimal bootstrap paths may not load the global translation helper.
     */
    private function translateMessage(string $message, array $params = []): string
    {
        if (\function_exists('__')) {
            return (string)\__($message, $params);
        }

        foreach ($params as $index => $value) {
            $message = \str_replace('%{' . ($index + 1) . '}', (string)$value, $message);
        }

        return $message;
    }

    private function padToDisplayWidth(string $value, int $width): string
    {
        $padding = $width - $this->getDisplayWidth($value);
        if ($padding <= 0) {
            return $value;
        }

        return $value . \str_repeat(' ', $padding);
    }

    protected function markStartupPhaseRunning(ServiceContext $context, int $totalServices): void
    {
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $context->instanceName . '.json';
        ServerInstanceManager::updateJsonFileAtomically(
            $instanceFile,
            static function (array $data) use ($context, $totalServices): array {
                $data = self::hydrateStartupRuntimeMetadata($data, $context);
                $data['lifecycle_state'] = 'running';
                $data['startup_phase'] = 'running';
                $data['server_ready_at'] = \date('Y-m-d H:i:s');
                $data['server_ready_service_count'] = $totalServices;
                $data['updated_at'] = \time();
                return self::filterEndpointRuntimeMetadata($data);
            }
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function appendStartupProgressEvent(
        ServiceContext $context,
        string $message,
        string $kind,
        array $details = []
    ): void {
        if ($context->instanceName === '') {
            return;
        }

        $message = \trim(\str_replace(["\r", "\n"], ' ', $message));
        if ($message === '') {
            return;
        }
        if (\preg_match('//u', $message) !== 1) {
            $message = 'startup event';
        }

        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $context->instanceName . '.json';
        $now = \time();
        $event = \array_merge([
            'kind' => $kind,
            'message' => $message,
            'ts' => \date('Y-m-d H:i:s', $now),
            'timestamp' => $now,
        ], $details);

        ServerInstanceManager::updateJsonFileAtomically(
            $instanceFile,
            static function (array $data) use ($context, $event, $now): array {
                $data = self::hydrateStartupRuntimeMetadata($data, $context);
                $events = $data['startup_events'] ?? [];
                if (!\is_array($events)) {
                    $events = [];
                }

                $seq = (int)($data['startup_event_seq'] ?? 0) + 1;
                $eventWithSeq = $event;
                $eventWithSeq['seq'] = $seq;
                $events[] = $eventWithSeq;
                if (\count($events) > 80) {
                    $events = \array_slice($events, -80);
                }

                $data['startup_event_seq'] = $seq;
                $data['startup_events'] = \array_values($events);
                $data['updated_at'] = $now;

                return self::filterEndpointRuntimeMetadata($data);
            }
        );
    }

    private function persistMasterEpoch(ServiceContext $context): void
    {
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $context->instanceName . '.json';
        ServerInstanceManager::updateJsonFileAtomically(
            $instanceFile,
            static function (array $data) use ($context): array {
                $data = self::hydrateStartupRuntimeMetadata($data, $context);
                $data['epoch'] = $context->epoch;
                $data['master_epoch'] = $context->epoch;
                $data['updated_at'] = \time();

                return self::filterEndpointRuntimeMetadata($data);
            }
        );
    }

    /**
     * Keep the Master IPC metadata available even if the instance JSON was read
     * during a partial write or created from an empty baseline.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function hydrateStartupRuntimeMetadata(array $data, ServiceContext $context): array
    {
        if ((int)($data['master_pid'] ?? 0) <= 0 && $context->masterPid > 0) {
            $data['master_pid'] = $context->masterPid;
        }
        if ((int)($data['pid'] ?? 0) <= 0 && $context->masterPid > 0) {
            $data['pid'] = $context->masterPid;
        }
        if ((int)($data['control_port'] ?? 0) <= 0 && $context->controlPort > 0) {
            $data['control_port'] = $context->controlPort;
        }
        if (!empty($data['master_pid']) || !empty($data['control_port'])) {
            $data['master_enabled'] = true;
        }

        $defaults = [
            'instance_name' => $context->instanceName,
            'host' => $context->host,
            'public_host' => $context->publicHost ?: $context->host,
            'port' => $context->mainPort,
            'main_port' => $context->mainPort,
            'ssl_enabled' => $context->sslEnabled,
            'ssl_cert' => $context->sslCert,
            'ssl_key' => $context->sslKey,
            'daemon' => $context->daemon,
            'window_mode' => $context->windowMode,
            'master_mode' => $context->mode,
            'dispatcher_enabled' => $context->isDispatcherEnabled(),
            'worker_base_port' => $context->getWorkerBasePort(),
            'worker_port' => $context->getWorkerPort(),
            'http_redirect_port' => $context->httpRedirectPort,
            'control_token' => $context->controlToken,
            'epoch' => $context->epoch,
            'master_epoch' => $context->epoch,
        ];

        $workerCount = $context->getWorkerCount();
        if (\is_int($workerCount) || (\is_string($workerCount) && \ctype_digit($workerCount))) {
            $defaults['count'] = (int)$workerCount;
        }

        foreach ($defaults as $field => $value) {
            if (!\array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    /**
     * The instance file is only the Master endpoint/config record; live service
     * topology remains in the registry and IPC status snapshot.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function filterEndpointRuntimeMetadata(array $data): array
    {
        $allowedFields = [
            'schema_version',
            'name',
            'instance_name',
            'pid',
            'launcher_pid',
            'master_pid',
            'master_enabled',
            'master_started_at',
            'master_mode',
            'orchestrator_mode',
            'control_plane_mode',
            'supervisor_enabled',
            'supervisor_channel',
            'supervisor_endpoint',
            'control_port',
            'control_token',
            'control_token_created_at',
            'epoch',
            'master_epoch',
            'host',
            'public_host',
            'port',
            'main_port',
            'count',
            'daemon',
            'ssl_enabled',
            'ssl_cert',
            'ssl_key',
            'dispatcher_enabled',
            'worker_port',
            'worker_base_port',
            'http_redirect_port',
            'started_by',
            'started_at',
            'started_timestamp',
            'php_version',
            'os',
            'window_mode',
            'frontend',
            'startup_phase',
            'lifecycle_state',
            'server_ready_at',
            'server_ready_service_count',
            'startup_event_seq',
            'startup_events',
            'startup_failure_reason',
            'startup_failure_at',
            'startup_failure_timestamp',
            'startup_failure_pending',
            'slot_generations',
            'slot_generations_updated_at',
            'updated_at',
        ];

        $filtered = [];
        foreach ($allowedFields as $field) {
            if (\array_key_exists($field, $data)) {
                $filtered[$field] = $data[$field];
            }
        }

        return $filtered;
    }

    private function armServerReadyNotification(): void
    {
        $this->serverReadyNotificationArmed = true;
        $this->checkAndNotifyServerReady();
    }

    private function resetServerReadyNotificationState(): void
    {
        $this->serverReadyNotified = false;
        $this->serverReadyNotificationArmed = false;
    }

    /**
     * Worker 进入事件循环后上报：Master 记录存活时间与槽位重启统计
     */
    private function handleWorkerLoopStarted(array $msg, int $clientId): void
    {
        $instance = $this->registry->getInstanceByIpcClient($clientId);
        if ($instance === null) {
            return;
        }
        if ($instance->role !== ControlMessage::ROLE_WORKER && $instance->role !== ControlMessage::ROLE_MAINTENANCE) {
            return;
        }
        $pid = (int) ($msg['pid'] ?? 0);
        $instance->setMeta('worker_loop_started_at', \microtime(true));
        $instance->setMeta('worker_loop_pid', $pid);
        $this->registry->updateInstance($instance);
        if ($this->context !== null && $instance->role === ControlMessage::ROLE_WORKER) {
            $workerId = (int)($msg['worker_id'] ?? $instance->instanceId);
            $this->appendStartupProgressEvent($this->context, 'worker ' . ($workerId > 0 ? $workerId : $instance->instanceId) . ' warmup started', 'worker_warmup_started', [
                'role' => $instance->role,
                'instance_id' => $instance->instanceId,
                'worker_id' => $workerId,
                'pid' => $pid > 0 ? $pid : $instance->pid,
                'port' => $instance->port,
            ]);
        }
        WlsLogger::info_(
            "[Orchestrator] {$instance->role}#{$instance->instanceId} 已确认进入事件循环 "
            . "(pid={$pid}, 槽位 restart 计数={$instance->restarts})"
        );
    }

    /**
     * 非 HA 模式下仍补齐 Worker 槽位（否则 worker 全死后 Master 不会 reconcile）
     */
    private function reconcileWorkerSlotsWithoutHa(): void
    {
        if ($this->context === null || !$this->running || $this->isRecoverySuspended()) {
            return;
        }
        if ($this->childServicesBootstrapInProgress) {
            return;
        }
        $provider = $this->registry->getProvider('worker');
        if ($provider === null || !$provider->isEnabled($this->context)) {
            return;
        }
        $desired = (int) ($this->desiredState['worker'] ?? 0);
        if ($desired <= 0) {
            return;
        }
        for ($slot = 1; $slot <= $desired; $slot++) {
            $qKey = "worker:{$slot}";
            if (isset($this->resurrectQueue[$qKey])) {
                continue;
            }
            $inst = $this->registry->getInstance('worker', $slot);
            if ($inst === null
                || \in_array($inst->state, [ServiceInstance::STATE_STOPPED, ServiceInstance::STATE_FAILED], true)) {
                WlsLogger::warning_("[Orchestrator] 非HA 模式补齐 Worker 槽位 #{$slot}");
                if ($inst !== null) {
                    $this->registry->removeInstance('worker', $slot);
                }
                $this->startInstance($provider, $slot, $this->context);
            }
        }
        // 与 Master 自检同源：READY 但 IPC/PID 已失效的占槽僵尸，不依赖 20s 自检周期即可收敛
        $this->reconcileRoleSlotGaps('worker');
    }

    /**
     * Worker 存活审计：死 PID 摘 IPC、僵尸注册表复活、零存活紧急拉起
     */
    private function queueStaleWorkerRecoveries(): void
    {
        if ($this->context === null || $this->controlServer === null || !$this->running || $this->isRecoverySuspended()) {
            return;
        }
        if ($this->childServicesBootstrapInProgress) {
            return;
        }

        $lastYieldAt = \microtime(true);
        $workers = $this->registry->getInstancesByRole('worker');
        foreach ($workers as $inst) {
            $this->cooperativeYieldIfNeeded($lastYieldAt);
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
            }
            if ($this->controlServer === null) {
                break;
            }
            if ($inst->ipcClientId !== null) {
                $cid = $inst->ipcClientId;
                if (!$this->controlServer->clientExists($cid)) {
                    WlsLogger::warning_("[Orchestrator] Worker#{$inst->instanceId} IPC 槽位失效，同步注册表");
                    $inst->ipcClientId = null;
                    $this->registry->updateInstance($inst);
                    if (!\in_array($inst->state, [
                        ServiceInstance::STATE_DRAINING,
                        ServiceInstance::STATE_STOPPING,
                        ServiceInstance::STATE_STOPPED,
                    ], true)) {
                        $this->scheduleResurrectionWithDelay($inst, 1.0);
                    }
                    continue;
                }
                $cachedRunning = $inst->pid > 0 ? ($this->processRunningCache[$inst->pid]['running'] ?? null) : null;
                if ($inst->pid > 0 && $cachedRunning === false) {
                    WlsLogger::warning_(
                        "[Orchestrator] Worker#{$inst->instanceId} 进程 PID {$inst->pid} 已退出，摘除 IPC 并复活"
                    );
                    $this->controlServer?->closeClient($cid);
                    if ($inst->ipcClientId !== null) {
                        $inst->ipcClientId = null;
                        $this->registry->updateInstance($inst);
                    }
                    if (!\in_array($inst->state, [
                        ServiceInstance::STATE_DRAINING,
                        ServiceInstance::STATE_STOPPING,
                        ServiceInstance::STATE_STOPPED,
                    ], true)) {
                        $this->scheduleResurrectionWithDelay($inst, 1.0);
                    }
                    continue;
                }
                } elseif (!\in_array($inst->state, [
                ServiceInstance::STATE_DRAINING,
                ServiceInstance::STATE_STOPPING,
                ServiceInstance::STATE_STOPPED,
            ], true)
                && $inst->getUptime() > 90.0
                && !isset($this->resurrectQueue['worker:' . $inst->instanceId])
                && \in_array($inst->state, [
                    ServiceInstance::STATE_READY,
                    ServiceInstance::STATE_REGISTERED,
                    ServiceInstance::STATE_STARTING,
                ], true)) {
                WlsLogger::warning_(
                    "[Orchestrator] Worker#{$inst->instanceId} 长期无 IPC（state={$inst->state}），触发复活"
                );
                $this->scheduleResurrectionWithDelay($inst, 0.5);
            }
        }
    }

    private function runWorkerLivenessAudit(): void
    {
        if ($this->context === null || $this->controlServer === null || !$this->running || $this->isRecoverySuspended()) {
            return;
        }
        if ($this->childServicesBootstrapInProgress) {
            return;
        }
        $desired = (int) ($this->desiredState['worker'] ?? 0);
        if ($desired <= 0) {
            return;
        }

        $this->queueStaleWorkerRecoveries();

        $alive = 0;
        $lastYieldAt = \microtime(true);
        if ($this->controlServer !== null) {
            foreach ($this->registry->getInstancesByRole('worker') as $w) {
                $this->cooperativeYieldIfNeeded($lastYieldAt);
                if ($this->shouldYieldPeriodicWork(true)) {
                    return;
                }
                if ($w->ipcClientId !== null
                    && $this->controlServer->clientExists($w->ipcClientId)
                    && $w->state === ServiceInstance::STATE_READY) {
                    $alive++;
                }
            }
        }

        if ($alive > 0 || !$this->workerEmergencyRestartEnabled || $desired <= 0) {
            return;
        }

        $now = \microtime(true);
        if ($this->controlServer === null) {
            return;
        }
        if ($this->rollingRestartInProgress) {
            WlsLogger::info_('[Orchestrator] 滚动重启进行中，跳过 Worker 紧急拉起');
            return;
        }
        if ($now < $this->suppressWorkerEmergencyUntil) {
            WlsLogger::info_('[Orchestrator] 整组重启后宽限期内跳过 Worker 紧急拉起');
            return;
        }
        foreach ($this->registry->getInstancesByRole('worker') as $w) {
            if ($w->state === ServiceInstance::STATE_STARTING
                && ($now - $w->startedAt) < $this->startupGracePeriod) {
                WlsLogger::info_('[Orchestrator] 仍有 Worker 处于启动宽限内，跳过紧急拉起');
                return;
            }
        }

        if (($now - $this->lastEmergencyWorkerRestartAt) < $this->workerEmergencyCooldownSec) {
            return;
        }
        $this->lastEmergencyWorkerRestartAt = $now;
        WlsLogger::error_('[Orchestrator] 全部 Worker 无可用存活连接，执行紧急拉起');
        $this->emergencyRestartAllWorkers();
    }

    /**
     * 杀光本实例 Worker 进程并重新启动各槽位（重置 restart 计数以便持续恢复）
     */
    private function emergencyRestartAllWorkers(): void
    {
        if ($this->context === null) {
            return;
        }
        $provider = $this->registry->getProvider('worker');
        if ($provider === null || !$provider->isEnabled($this->context)) {
            return;
        }
        $desired = (int) ($this->desiredState['worker'] ?? 0);
        $this->killKnownWorkerProcessesForEmergencyRestart();
        // 按实例作用域前缀清理 name_index / 系统可解析的 Worker，避免仅 kill 注册表 PID 后仍有逃逸子进程。
        // 使用 buildScopedProcessName 与 Worker 实际 --name 一致（含实例名规范化）。
        $scopedPrefix = MasterProcess::buildScopedProcessName(
            WorkerProvider::PROCESS_NAME_PREFIX,
            $this->context->instanceName
        ) . '-';
        Processer::killByProcessNamePrefix($scopedPrefix);
        if (!$this->sleepInterruptiblyForPeriodicWork(600000)) {
            return;
        }

        foreach (\array_keys($this->resurrectQueue) as $key) {
            if (\str_starts_with((string) $key, 'worker:')) {
                unset($this->resurrectQueue[$key]);
            }
        }

        $instanceIds = [];
        for ($slot = 1; $slot <= $desired; $slot++) {
            $old = $this->registry->getInstance('worker', $slot);
            if ($old !== null) {
                $this->cleanupInstancePidFile($old);
                $this->registry->removeInstance('worker', $slot);
            }
            $instanceIds[] = $slot;
        }

        $newInstances = $this->startInstanceIdsBatch($provider, $instanceIds, $this->context);
        foreach ($newInstances as $newInst) {
            if (!$newInst instanceof ServiceInstance) {
                continue;
            }
            $newInst->restarts = 0;
            $this->registry->updateInstance($newInst);
        }
        $this->controlServer?->poll(0, 200000);
        WlsLogger::warning_("[Orchestrator] Worker 紧急拉起已提交（{$desired} 槽位）");
    }

    /**
     * 紧急拉起前优先按 Registry 定点结束 Worker，避免 Windows 全表扫描进程导致主循环阻塞。
     */
    private function killKnownWorkerProcessesForEmergencyRestart(): void
    {
        foreach ($this->registry->getInstancesByRole('worker') as $worker) {
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
            }
            $pid = (int) ($worker->pid ?? 0);
            if ($pid > 0) {
                $this->killProcess($pid);
            }
            $port = (int) ($worker->port ?? 0);
            if ($port > 0) {
                Processer::forceReleasePort($port);
            }
        }
    }

    /**
     * 重置服务器就绪通知状态（用于重启时）
     */
    public function resetServerReadyNotification(): void
    {
        $this->resetServerReadyNotificationState();
    }

    /**
     * @return int[]
     */
    private function collectReadyMaintenancePortsSorted(): array
    {
        $maintenancePorts = [];
        foreach ($this->registry->getInstancesByRole(ControlMessage::ROLE_MAINTENANCE) as $maintenance) {
            if ($maintenance->state === ServiceInstance::STATE_READY
                && $maintenance->port !== null
                && $maintenance->port > 0) {
                $maintenancePorts[] = (int) $maintenance->port;
            }
        }
        \sort($maintenancePorts, SORT_NUMERIC);

        return $maintenancePorts;
    }

    /**
     * 维护模式下将当前已 READY 的维护 Worker 端口同步为 Dispatcher 全量池（所有 Dispatcher）。
     */
    private function pushMaintenanceWorkerPoolToDispatchersFromRegistry(): void
    {
        if (!$this->maintenanceMode || $this->controlServer === null) {
            return;
        }
        $ports = $this->collectReadyMaintenancePortsSorted();
        if ($ports === []) {
            $this->logMaintenanceOperation(
                '维护模式已激活，但当前没有 READY maintenance 端口可发布给 Dispatcher，'
                . $this->formatMaintenanceOperationContext(),
                'WARN',
                'publish_maintenance_pool:none:' . $this->formatMaintenanceOperationContext()
            );
        }
        $portsStr = \implode(',', $ports);
        $this->logMaintenanceOperation(
            "维护 Worker READY 后补发 Dispatcher 维护池：ports={$portsStr}，{$this->formatMaintenanceOperationContext()}",
            'INFO',
            "publish_maintenance_pool:{$portsStr}:" . $this->formatMaintenanceOperationContext(),
            0.0
        );
        $this->publishDispatcherRouteTableFromPorts($ports, ControlMessage::ROLE_MAINTENANCE);
    }

    /**
     * Worker 状态变化后，让所有 Dispatcher 通过 Registry 单一事实源
     * 收敛到最新的版本化路由表（只下发 SET_ROUTE_TABLE 权威源）。
     *
     * 维护模式中先尝试退出维护，否则保持业务路由不变；非维护模式直接全量同步。
     * Dispatcher 端依靠版本号 + checksum 去重，因此本方法是幂等的，可在任意 READY 路径上重复调用。
     */
    private function convergeDispatcherRouteTableAfterWorkerReady(): void
    {
        if ($this->maintenanceMode) {
            // 维护模式中：先尝试根据当前 Registry 状态退出维护；若仍在维护中，则保持业务路由不变。
            $this->checkAndDisableMaintenanceIfReady();
            if ($this->maintenanceMode) {
                return;
            }
        }
        $this->syncDispatcherFullWorkerPoolFromRegistry();
    }

    /**
     * Publish the complete route table to dispatchers.
     *
     * @param int[] $ports
     */
    private function publishDispatcherRouteTableFromPorts(array $ports, string $role = ControlMessage::ROLE_WORKER, bool $force = false): int
    {
        $dispatchers = $this->registry->getInstancesByRole('dispatcher');
        if ($dispatchers === [] || $this->controlServer === null) {
            return 0;
        }

        $portSet = \array_fill_keys(\array_map('intval', $ports), true);
        $workers = [];
        foreach ($this->registry->getInstancesByRole($role) as $instance) {
            $p = (int)($instance->port ?? 0);
            if ($p <= 0 || !isset($portSet[$p]) || $instance->state !== ServiceInstance::STATE_READY) {
                continue;
            }
            $workers[] = $this->buildWorkerDescriptor($instance);
        }

        $epoch = $this->context !== null ? $this->context->epoch : 0;
        $portsStr = \implode(',', $ports);

        if ($role === ControlMessage::ROLE_MAINTENANCE || $this->maintenanceMode) {
            $this->logMaintenanceOperation(
                'Publish dispatcher route table: role=' . $role . ', ports=' . ($portsStr !== '' ? $portsStr : '(empty)')
                . ', ' . $this->formatMaintenanceOperationContext(),
                $role === ControlMessage::ROLE_MAINTENANCE ? 'WARN' : 'INFO',
                'dispatcher_route_table_publish:' . $role . ':' . $portsStr . ':' . $this->formatMaintenanceOperationContext(),
                0.0
            );
        }

        if ($role === ControlMessage::ROLE_WORKER) {
            $normalizedPorts = \array_values(\array_filter(
                \array_unique(\array_map('intval', $ports)),
                static fn(int $port): bool => $port > 0
            ));
            \sort($normalizedPorts, \SORT_NUMERIC);
            $this->lastDispatcherRouteTableSignature = \implode(',', $normalizedPorts);
        } else {
            $this->lastDispatcherRouteTableSignature = '';
        }

        return $this->publishDispatcherRouteTable($dispatchers, $ports, $role, $workers, $epoch, $force);
    }
    /**
     * 向所有 Dispatcher 下发版本化路由表。
     *
     * @param ServiceInstance[] $dispatchers
     * @param int[]             $ports
     * @param array<int, array<string, mixed>> $workers 已由 buildWorkerDescriptor() 构造
     */
    private function publishDispatcherRouteTable(array $dispatchers, array $ports, string $role, array $workers, int $epoch, bool $force = false): int
    {
        if ($dispatchers === [] || $this->controlServer === null) {
            return 0;
        }

        $msg = ControlMessage::setRouteTable(
            $ports,
            $role,
            $workers,
            $this->routeTableVersion,
            $epoch
        );

        // 解出 checksum 用于本地缓存（避免重复发布）；
        // decode 失败时直接跳过缓存即可，发送本身仍然要进行。
        $decoded = \json_decode(\rtrim($msg, "\n"), true);
        $checksum = \is_array($decoded) ? (string)($decoded['checksum'] ?? '') : '';
        $cacheKey = $role . ':' . $epoch;

        if (!$force && $checksum !== '' && isset($this->lastDispatcherRouteTablePublish[$cacheKey])
            && $this->lastDispatcherRouteTablePublish[$cacheKey]['checksum'] === $checksum
            && $this->lastDispatcherRouteTablePublish[$cacheKey]['route_version'] === $this->routeTableVersion) {
            return 0;
        }

        $sentCount = 0;
        foreach ($dispatchers as $dispatcher) {
            if ($dispatcher->ipcClientId === null) {
                continue;
            }
            $this->controlServer->sendTo($dispatcher->ipcClientId, $msg);
            $sentCount++;
        }

        if ($sentCount > 0 && $checksum !== '') {
            $normalizedPorts = \array_values(\array_map('intval', $ports));
            \sort($normalizedPorts, \SORT_NUMERIC);
            $this->lastDispatcherRouteTablePublish[$cacheKey] = [
                'route_version' => $this->routeTableVersion,
                'checksum'      => $checksum,
                'ports'         => $normalizedPorts,
                'workers_count' => \count($workers),
                'published_at'  => \microtime(true),
            ];
        }

        if ($sentCount > 0) {
            WlsLogger::info_(\sprintf(
                '[Orchestrator] SET_ROUTE_TABLE published: role=%s, version=%d, epoch=%d, ports=%d, workers=%d, dispatchers=%d, checksum=%s',
                $role,
                $this->routeTableVersion,
                $epoch,
                \count($ports),
                \count($workers),
                $sentCount,
                $checksum !== '' ? \substr($checksum, 0, 12) : 'n/a'
            ));
        }

        return $sentCount;
    }

    /**
     * Rewrite dispatcher worker routes from the current READY worker registry.
     */
    private function syncDispatcherFullWorkerPoolFromRegistry(bool $force = false): void
    {
        if ($this->registry->getInstancesByRole('dispatcher') === [] || $this->controlServer === null) {
            $this->lastDispatcherRouteTableSignature = '';
            return;
        }

        $ports = [];
        foreach ($this->registry->getInstancesByRole('worker') as $w) {
            if ($w->state === ServiceInstance::STATE_READY && $w->port !== null && $w->port > 0) {
                $ports[] = (int) $w->port;
            }
        }

        \sort($ports, SORT_NUMERIC);
        $signature = \implode(',', $ports);
        if (!$force && $signature === $this->lastDispatcherRouteTableSignature) {
            return;
        }

        $this->routeTableVersion++;
        $sentCount = $this->publishDispatcherRouteTableFromPorts($ports);
        if ($sentCount <= 0) {
            $this->lastDispatcherRouteTableSignature = '';
            WlsLogger::warning_('[Orchestrator] Dispatcher route table sync skipped: no connected dispatcher accepted publish for ' . $signature);
            return;
        }

        $this->controlServer->poll(0, 150000);
        $this->broadcastRoutingPolicyToWorkers();
        $this->lastDispatcherRouteTableSignature = $signature;
        WlsLogger::info_('[Orchestrator] Dispatcher route table is aligned with Registry: ' . $signature);
    }

    private function notifyDispatcherRemoveWorker(?int $port): void
    {
        $this->syncDispatcherFullWorkerPoolFromRegistry(true);
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
        if ($code !== 0) {
            WlsLogger::error_(
                "[Master自检] 子进程上报非正常退出: {$instance->role}#{$instance->instanceId} code={$code} reason={$reason}"
            );
        }
    }

    private function handleStopTestCommand(int $clientId): void
    {
        $canSchedule = !$this->stopAllInProgress
            && !$this->shuttingDown
            && $this->pendingStopReason === null;
        $this->controlServer?->sendTo(
            $clientId,
            ControlMessage::commandResult(
                true,
                [
                    'accepted' => true,
                    'can_schedule_stop_task' => $canSchedule,
                    'pending_stop_reason' => $this->pendingStopReason,
                    'stop_all_in_progress' => $this->stopAllInProgress,
                    'shutting_down' => $this->shuttingDown,
                    'stop_stage' => $this->stopStage,
                    'main_loop_task_count' => \count($this->mainLoopTasks),
                    'ipc_exclusive_command' => $this->ipcExclusiveCommand,
                ],
                'stop_test',
            )
        );
    }

    /**
     * 处理 command 消息
     */
    private function handleCommand(array $msg, int $clientId): void
    {
        $action = $msg['action'] ?? '';
        if (!\is_string($action) || $action === '') {
            $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(false, [], 'Unknown command', (string)($msg['msg_id'] ?? '')));

            return;
        }
        $dispatcherOnlyMaintenance = $this->isMaintenanceControlAction($action)
            && !empty($msg['dispatcher_only']);

        if ($action === ControlMessage::ACTION_STOP_TEST) {
            $this->handleStopTestCommand($clientId);
            return;
        }

        if ($this->isQueuedControlCommand($action)) {
            if ($action === ControlMessage::ACTION_STOP) {
                $stopIntent = (string)($msg['stop_intent'] ?? '');
                $stopSource = (string)($msg['stop_source'] ?? 'unknown');
                $stopTraceId = (string)($msg['stop_trace_id'] ?? '');
                $forceStop = (bool) ($msg['force_stop'] ?? false);
                if ($stopIntent !== 'explicit') {
                    WlsLogger::warning_(
                        "[Orchestrator] 拒绝未显式确认的 STOP 命令: client={$clientId}, action={$action}, source={$stopSource}, trace={$stopTraceId}"
                    );
                    $this->controlServer?->sendTo(
                        $clientId,
                        ControlMessage::commandResult(false, [], 'STOP rejected: missing explicit stop intent', (string)($msg['msg_id'] ?? ''))
                    );

                    return;
                }

                WlsLogger::warning_(
                    "[Orchestrator] 接收 STOP 命令: client={$clientId}, source={$stopSource}, trace={$stopTraceId}, force="
                    . ($forceStop ? '1' : '0')
                );
                $this->clearPendingControlOperations('Control operation cancelled by stop');
                $this->preemptActiveControlOperationForImperial($action);
                $stopMsgId = (string) ($msg['msg_id'] ?? '');
                if ($stopMsgId === '') {
                    $stopMsgId = $stopTraceId;
                }
                $this->requestStop('command', $clientId, true, $forceStop, $stopMsgId);

                return;
            }

            if ($this->isImperialControlCommand($action)) {
                $existingImperialOperation = $dispatcherOnlyMaintenance
                    ? null
                    : $this->findEquivalentQueuedOrActiveOperation($action);
                if ($this->ipcExclusiveCommand === $action && $existingImperialOperation !== null) {
                    WlsLogger::info_(
                        "[Orchestrator] 帝王指令复用已有控制操作 action={$action} client={$clientId} -> existing={$existingImperialOperation['id']}"
                    );
                    $this->sendDeduplicatedControlOperationAck($clientId, [
                        'id' => (string)$existingImperialOperation['id'],
                        'action' => (string)$existingImperialOperation['action'],
                        'state' => (string)$existingImperialOperation['state'],
                    ]);

                    return;
                }

                $this->clearPendingControlOperations("Control operation cancelled by imperial {$action}");
                $this->preemptActiveControlOperationForImperial($action);
                $this->ipcClearFieldForNewImperial($clientId, $action);

                if (!$dispatcherOnlyMaintenance
                    && $this->isMaintenanceControlAction($action)
                    && $this->isMaintenanceActionAlreadySatisfied($action)
                ) {
                    $message = $action === ControlMessage::ACTION_MAINTENANCE_ENABLE
                        ? 'Maintenance already enabled'
                        : 'Maintenance already disabled';
                    $this->ipcReleaseExclusive();
                    $this->controlServer?->sendTo(
                        $clientId,
                        ControlMessage::commandResult(true, [
                            'async' => false,
                            'accepted' => true,
                            'operation_id' => null,
                            'state' => self::CONTROL_OPERATION_STATE_COMPLETED,
                        ], $message)
                    );

                    return;
                }
            }

            if ($this->isMaintenanceControlAction($action)) {
                if (!$dispatcherOnlyMaintenance && $this->isMaintenanceActionAlreadySatisfied($action)) {
                    $message = $action === ControlMessage::ACTION_MAINTENANCE_ENABLE
                        ? 'Maintenance already enabled'
                        : 'Maintenance already disabled';
                    $this->controlServer?->sendTo(
                        $clientId,
                        ControlMessage::commandResult(true, [
                            'async' => false,
                            'accepted' => true,
                            'operation_id' => null,
                            'state' => self::CONTROL_OPERATION_STATE_COMPLETED,
                        ], $message)
                    );
                    return;
                }

                $oppositeAction = $this->getOppositeMaintenanceAction($action);
                if ($oppositeAction !== null) {
                    $removed = $this->dropQueuedControlOperationsByAction(
                        $oppositeAction,
                        "Superseded by {$action}"
                    );
                    if ($removed > 0) {
                        WlsLogger::info_(
                            "[Orchestrator] 维护指令队列收敛：移除 {$removed} 个 {$oppositeAction}，incoming={$action}, client={$clientId}"
                        );
                    }
                }
            }

            $operation = $this->queueControlOperation($action, $msg, $clientId);
            $this->sendQueuedControlOperationAck($operation);

            return;
        }

        switch ($action) {
            case ControlMessage::ACTION_STATUS:
                $status = !empty($msg['brief']) ? $this->getBriefStatus() : $this->getStatus();
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(true, $status, 'Status retrieved'));
                break;

            case ControlMessage::ACTION_SCALE_WORKERS:
                $this->handleScaleWorkersCommand($clientId, $msg);
                break;

            case ControlMessage::ACTION_SCALING_STATUS:
                $this->handleScalingStatusCommand($clientId, $msg);
                break;

            case ControlMessage::ACTION_TELEMETRY_QUERY:
                $instance = (string)($msg['instance'] ?? ($this->context?->instanceName ?? 'default'));
                $windowSec = (int)($msg['window_sec'] ?? 300);
                $host = (string)($msg['host'] ?? '');
                $sinceTs = \time() - \max(60, $windowSec);
                $aggregator = $this->getMetricsAggregator();
                $data = [
                    'global' => $aggregator->snapshotGlobal($instance, $sinceTs),
                    'hosts' => $aggregator->snapshotByHost($instance, $sinceTs),
                    'host_detail' => $host !== '' ? $aggregator->snapshotHostDetail($instance, $host, $sinceTs) : null,
                ];
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(true, $data, 'Telemetry retrieved'));
                break;

            case ControlMessage::ACTION_FIBER_STATS:
                $this->requestFiberPoolStats($clientId);
                break;

            default:
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(false, [], 'Unknown command'));
                break;
        }
    }

    private function handleScalingStatusCommand(int $clientId, array $msg): void
    {
        $workers = $this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER);
        $ready = 0;
        $workerRows = [];
        foreach ($workers as $worker) {
            if ($worker->state === ServiceInstance::STATE_READY) {
                $ready++;
            }
            $workerRows[] = [
                'instance_id' => $worker->instanceId,
                'pid' => $worker->pid,
                'port' => $worker->port,
                'state' => $worker->state,
                'restarts' => $worker->restarts,
            ];
        }

        $data = [
            'enabled' => (bool)($this->context?->getConfig('wls.scaling.enabled', false) ?? false),
            'current_workers' => \count($workers),
            'ready_workers' => $ready,
            'desired_workers' => (int)($this->desiredState[ControlMessage::ROLE_WORKER] ?? \count($workers)),
            'min_workers' => (int)($this->context?->getConfig('wls.scaling.min_workers', 1) ?? 1),
            'max_workers' => (int)($this->context?->getConfig('wls.scaling.max_workers', 16) ?? 16),
            'locked' => $this->rollingRestartInProgress || $this->isStopFlowActive(),
            'workers' => $workerRows,
        ];

        $this->controlServer?->sendTo(
            $clientId,
            ControlMessage::commandResult(true, $data, 'Scaling status retrieved', (string)($msg['msg_id'] ?? ''))
        );
    }

    private function handleScaleWorkersCommand(int $clientId, array $msg): void
    {
        $target = (int)($msg['target_workers'] ?? 0);
        if ($target < 1 || $target > 128) {
            $this->controlServer?->sendTo(
                $clientId,
                ControlMessage::commandResult(false, [], 'target_workers must be between 1 and 128', (string)($msg['msg_id'] ?? ''))
            );
            return;
        }
        if ($this->context === null) {
            $this->controlServer?->sendTo(
                $clientId,
                ControlMessage::commandResult(false, [], 'Context not initialized', (string)($msg['msg_id'] ?? ''))
            );
            return;
        }
        if ($this->rollingRestartInProgress || $this->isStopFlowActive()) {
            $this->controlServer?->sendTo(
                $clientId,
                ControlMessage::commandResult(false, [
                    'rolling_restart_in_progress' => $this->rollingRestartInProgress,
                    'stop_flow_active' => $this->isStopFlowActive(),
                ], 'Cannot scale while restart/stop flow is active', (string)($msg['msg_id'] ?? ''))
            );
            return;
        }

        $provider = $this->registry->getProvider(ControlMessage::ROLE_WORKER);
        if (!$provider instanceof ServiceProviderInterface || !$provider->isEnabled($this->context)) {
            $this->controlServer?->sendTo(
                $clientId,
                ControlMessage::commandResult(false, [], 'Worker provider is not available', (string)($msg['msg_id'] ?? ''))
            );
            return;
        }

        $workers = $this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER);
        $current = \count($workers);
        $addedPids = [];
        $removedPids = [];

        if ($target > $current) {
            $existingIds = [];
            foreach ($workers as $worker) {
                $existingIds[(int)$worker->instanceId] = true;
            }
            $ids = [];
            for ($id = 1; \count($ids) < ($target - $current); $id++) {
                if (!isset($existingIds[$id])) {
                    $ids[] = $id;
                }
            }
            $started = $this->startInstanceIdsBatch($provider, $ids, $this->context);
            foreach ($started as $instance) {
                if ($instance instanceof ServiceInstance && $instance->pid > 0) {
                    $addedPids[] = $instance->pid;
                }
            }
        } elseif ($target < $current) {
            \usort($workers, static fn(ServiceInstance $a, ServiceInstance $b): int => $b->instanceId <=> $a->instanceId);
            $toStop = \array_slice($workers, 0, $current - $target);
            foreach ($toStop as $worker) {
                if ($worker->pid > 0) {
                    $removedPids[] = $worker->pid;
                }
                $this->stopInstance($worker);
                $this->registry->removeInstance($worker->role, $worker->instanceId);
            }
        }

        $this->desiredState[ControlMessage::ROLE_WORKER] = $target;
        $this->persistServicesInfo($this->context);
        $currentAfter = \count($this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER));
        $data = [
            'target_workers' => $target,
            'current_workers' => $currentAfter,
            'previous_workers' => $current,
            'added_pids' => $addedPids,
            'removed_pids' => $removedPids,
            'desired_state' => $this->desiredState,
            'accepted' => true,
            'completed' => true,
        ];

        $this->controlServer?->sendTo(
            $clientId,
            ControlMessage::commandResult(true, $data, "Scaled workers to {$target}", (string)($msg['msg_id'] ?? ''))
        );
    }

    /** Fiber 统计请求超时（秒），超时后返回已收集的 partial 结果 */
    private const FIBER_STATS_TIMEOUT_SEC = 12;

    /**
     * 参与 Fiber 池控制的实例（Worker + Maintenance，与 routing_policy 一致）
     *
     * @return iterable<ServiceInstance>
     */
    private function getFiberEligibleInstances(): iterable
    {
        foreach ($this->registry->getAllInstances() as $instance) {
            if (\in_array($instance->role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
                yield $instance;
            }
        }
    }

    /**
     * 向所有 Worker/Maintenance 请求 Fiber 池统计，结果通过 handleFiberPoolStatsReply 聚合后回传 CLI
     */
    private function requestFiberPoolStats(int $replyClientId): void
    {
        if ($this->pendingFiberStatsRequest !== null) {
            $this->controlServer?->sendTo($replyClientId, ControlMessage::commandResult(
                false,
                [],
                $this->translateMessage('已有 Fiber 统计请求进行中，请稍后再试')
            ));
            return;
        }
        $waiting = [];
        $requestId = 'fiber_stats_' . \uniqid('', true);
        foreach ($this->getFiberEligibleInstances() as $instance) {
            if ($instance->ipcClientId !== null && $this->controlServer !== null) {
                $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::fiberPoolQuery($requestId));
                $waiting[$instance->ipcClientId] = true;
            }
        }
        if ($waiting === []) {
            $this->controlServer?->sendTo($replyClientId, ControlMessage::commandResult(true, ['workers' => [], 'total_suspended' => 0], $this->translateMessage('无已连接 Worker')));
            return;
        }
        $this->pendingFiberStatsRequest = [
            'replyClientId' => $replyClientId,
            'request_id' => $requestId,
            'waiting' => $waiting,
            'replies' => [],
            'created_at' => \time(),
        ];
    }

    /**
     * 处理 Worker 上报的 Fiber 池统计，聚合完成后回传 CLI
     */
    private function handleFiberPoolStatsReply(array $msg, int $clientId): void
    {
        if ($this->pendingFiberStatsRequest === null) {
            return;
        }
        $req = &$this->pendingFiberStatsRequest;
        if (($msg['request_id'] ?? '') !== $req['request_id']) {
            return;
        }
        unset($req['waiting'][$clientId]);
        $req['replies'][$clientId] = [
            'worker_id' => $msg['worker_id'] ?? 0,
            'suspended' => $msg['suspended'] ?? 0,
            'idle_ttl_sec' => $msg['idle_ttl_sec'] ?? 0,
            'max_active' => $msg['max_active'] ?? 0,
            'released_count' => $msg['released_count'] ?? 0,
        ];
        if ($req['waiting'] !== []) {
            return;
        }
        $replyClientId = $req['replyClientId'];
        $workers = \array_values($req['replies']);
        $this->pendingFiberStatsRequest = null;
        $totalSuspended = \array_sum(\array_column($workers, 'suspended'));
        $data = ['workers' => $workers, 'total_suspended' => $totalSuspended];
        $this->controlServer?->sendTo($replyClientId, ControlMessage::commandResult(true, $data, __('已聚合 %{1} 个 Worker 的 Fiber 池统计', [\count($workers)])));
    }

    /**
     * 若存在超时的 Fiber 统计请求，则返回已收集的 partial 结果（主循环中调用，避免无消息时永久挂起）
     */
    private function completePendingFiberStatsIfTimeout(): void
    {
        if ($this->pendingFiberStatsRequest === null) {
            return;
        }
        $req = $this->pendingFiberStatsRequest;
        if (\time() - $req['created_at'] < self::FIBER_STATS_TIMEOUT_SEC) {
            return;
        }
        $this->pendingFiberStatsRequest = null;
        $workers = \array_values($req['replies']);
        $totalSuspended = \array_sum(\array_column($workers, 'suspended'));
        $this->controlServer?->sendTo($req['replyClientId'], ControlMessage::commandResult(
            true,
            ['workers' => $workers, 'total_suspended' => $totalSuspended, 'timeout_partial' => true],
            (string)__('Fiber 统计已超时，返回已收到的 %{1} 个 Worker 数据', [\count($workers)])
        ));
    }

    private function handleTelemetry(array $msg): void
    {
        $host = (string) ($msg['host'] ?? '');
        $status = (int) ($msg['status'] ?? 200);
        $latencyMs = (int) ($msg['latency_ms'] ?? 0);
        $bytesOut = (int) ($msg['bytes_out'] ?? 0);
        $instance = (string) ($msg['instance'] ?? ($this->context?->instanceName ?? 'default'));

        if ($status >= 500) {
            $this->recoverSlotsAfterTelemetryHttpFailure($instance);
        } elseif ($status < 100 || $status > 599) {
            $this->logTelemetryAnomalyThrottled(
                "bad_status_{$status}_{$host}",
                "[Master自检] 遥测 status 非法: {$status} host={$host} instance={$instance}"
            );
        }
        if ($latencyMs < 0 || $latencyMs > 600_000) {
            $this->logTelemetryAnomalyThrottled(
                "lat_{$host}",
                "[Master自检] 遥测延迟异常 latency_ms={$latencyMs} host={$host} instance={$instance}"
            );
        }
        if ($bytesOut < 0 || $bytesOut > 512 * 1024 * 1024) {
            $this->logTelemetryAnomalyThrottled(
                "bytes_{$host}",
                "[Master自检] 遥测 bytes_out 异常: {$bytesOut} host={$host} instance={$instance}"
            );
        }

        $this->getTelemetryGateway()->record([
            'instance' => $instance,
            'host' => $host,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'bytes_out' => $bytesOut,
            'ts' => (int) ($msg['ts'] ?? \time()),
        ]);
    }

    private function handleDispatcherAlert(array $msg, int $clientId): void
    {
        $dispatcher = $this->registry->getInstanceByIpcClient($clientId);
        if ($dispatcher === null || $dispatcher->role !== ControlMessage::ROLE_DISPATCHER) {
            $this->logTelemetryAnomalyThrottled(
                'dispatcher_alert_untrusted_' . $clientId,
                "[Master自检] 忽略非 Dispatcher 的 dispatcher_alert: client_id={$clientId}"
            );

            return;
        }

        $reason = (string) ($msg['reason'] ?? '');
        if ($reason === '') {
            $this->logTelemetryAnomalyThrottled(
                'dispatcher_alert_missing_reason_' . $clientId,
                "[Master自检] 忽略缺少 reason 的 dispatcher_alert: dispatcher#{$dispatcher->instanceId}"
            );

            return;
        }

        $instance = (string) ($msg['instance'] ?? ($this->context?->instanceName ?? 'default'));
        $subjectRole = (string) ($msg['subject_role'] ?? ControlMessage::ROLE_WORKER);
        $decision = $this->recoverFromDispatcherAlert($instance, $subjectRole, $reason, $msg);
        if (!$decision['recovery_dispatched']) {
            return;
        }

        $businessPool = \array_values(\array_map('intval', (array) ($msg['business_pool'] ?? [])));
        $maintenanceCandidates = \array_values(\array_map('intval', (array) ($msg['maintenance_candidates'] ?? [])));
        \sort($businessPool, SORT_NUMERIC);
        \sort($maintenanceCandidates, SORT_NUMERIC);
        $maintenancePort = (int) ($msg['maintenance_port'] ?? 0);
        $healthy = (int) ($msg['healthy'] ?? 0);
        $total = (int) ($msg['total'] ?? 0);

        WlsLogger::warning_(
            '[Master自检] 收到 Dispatcher 后端不可用告警，触发自愈: dispatcher#'
            . $dispatcher->instanceId
            . ', subject_role='
            . $subjectRole
            . ", reason={$reason}, business_pool="
            . ($businessPool !== [] ? \implode(',', $businessPool) : '(empty)')
            . ', maintenance_candidates='
            . ($maintenanceCandidates !== [] ? \implode(',', $maintenanceCandidates) : '(none)')
            . ', maintenance_port='
            . ($maintenancePort > 0 ? (string) $maintenancePort : '(none)')
            . ", health={$healthy}/{$total}"
        );
    }

    /**
     * @return array{
     *     eligible: bool,
     *     subject_role: string,
     *     recovery_dispatched: bool,
     *     reason: string
     * }
     */
    private function recoverFromDispatcherAlert(
        string $instance,
        string $subjectRole,
        string $reason,
        array $payload = []
    ): array {
        $subjectRole = $subjectRole !== '' ? $subjectRole : ControlMessage::ROLE_WORKER;
        $decision = [
            'eligible' => false,
            'subject_role' => $subjectRole,
            'recovery_dispatched' => false,
            'reason' => 'orchestrator_inactive',
        ];

        if ($this->context === null || !$this->running || $this->shuttingDown || $this->masterShutdownIntent) {
            return $decision;
        }
        if ($this->childServicesBootstrapInProgress) {
            $decision['reason'] = 'child_services_bootstrap';

            return $decision;
        }

        $businessPool = \array_values(\array_map('intval', (array) ($payload['business_pool'] ?? [])));
        $maintenanceCandidates = \array_values(\array_map('intval', (array) ($payload['maintenance_candidates'] ?? [])));
        \sort($businessPool, SORT_NUMERIC);
        \sort($maintenanceCandidates, SORT_NUMERIC);
        $maintenancePort = (int) ($payload['maintenance_port'] ?? 0);

        $cooldown = (float) ($this->context->getConfig(
            'wls.orchestrator.dispatcher_alert_recovery_cooldown_sec',
            3.0
        ) ?? 3.0);
        if ($cooldown < 1.0) {
            $cooldown = 1.0;
        }
        $decision['eligible'] = true;
        $key = $instance
            . ':'
            . $subjectRole
            . ':'
            . $reason
            . ':'
            . \implode(',', $businessPool)
            . '|'
            . \implode(',', $maintenanceCandidates)
            . '|'
            . $maintenancePort;
        $now = \microtime(true);
        $last = (float) ($this->dispatcherAlertRecoveryAt[$key] ?? 0.0);
        if (($now - $last) < $cooldown) {
            $decision['reason'] = 'dispatcher_alert_cooldown';

            return $decision;
        }
        $this->dispatcherAlertRecoveryAt[$key] = $now;
        if (\count($this->dispatcherAlertRecoveryAt) > 128) {
            $this->dispatcherAlertRecoveryAt = \array_slice($this->dispatcherAlertRecoveryAt, -64, 64, true);
        }

        if ($subjectRole === ControlMessage::ROLE_WORKER) {
            $this->queueDispatcherFailedWorkerRecoveries($payload);
            $this->queueStaleWorkerRecoveries();
        }
        $this->syncDispatcherFullWorkerPoolFromRegistry(true);
        $this->reconcileRoleSlotGaps($subjectRole);
        $decision['recovery_dispatched'] = true;
        $decision['reason'] = 'dispatcher_alert_recovery';

        return $decision;
    }

    private function queueDispatcherFailedWorkerRecoveries(array $payload): void
    {
        $ports = \array_values(\array_unique(\array_map('intval', (array)($payload['failed_ports'] ?? []))));
        if ($ports === []) {
            return;
        }

        $failedReasons = \is_array($payload['failed_reasons'] ?? null) ? $payload['failed_reasons'] : [];
        foreach ($ports as $port) {
            if ($port <= 0) {
                continue;
            }

            $worker = $this->findWorkerInstanceByPort($port);
            if ($worker === null) {
                WlsLogger::warning_("[Orchestrator] Dispatcher reported failed worker port {$port}, but no registry slot matched");
                continue;
            }

            if (\in_array($worker->state, [
                ServiceInstance::STATE_DRAINING,
                ServiceInstance::STATE_STOPPING,
                ServiceInstance::STATE_STOPPED,
            ], true)) {
                continue;
            }

            $reason = (string)($failedReasons[$port] ?? $failedReasons[(string)$port] ?? 'dispatcher health audit failed');
            $worker->setMeta('dispatcher_health_failed_at', \microtime(true));
            $worker->setMeta('dispatcher_health_failed_reason', $reason);
            $worker->setMeta('dispatcher_health_failed_port', $port);
            $worker->setMeta('dispatcher_pool_confirmed_at', null);

            $clientId = $worker->ipcClientId;
            if ($clientId !== null) {
                $this->controlServer?->closeClient($clientId);
                $worker->ipcClientId = null;
            }

            $this->registry->updateInstance($worker);
            $this->lastDispatcherRouteTableSignature = '';
            $this->scheduleResurrectionWithDelay($worker, 0.0);
            WlsLogger::warning_(
                "[Orchestrator] Dispatcher health audit failed for worker#{$worker->instanceId}, "
                . "port={$port}; queued slot resurrection"
            );
        }
    }

    private function findWorkerInstanceByPort(int $port): ?ServiceInstance
    {
        foreach ($this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER) as $worker) {
            if ((int)($worker->port ?? 0) === $port) {
                return $worker;
            }
        }

        return null;
    }

    private function logTelemetryAnomalyThrottled(string $key, string $message): void
    {
        $now = \microtime(true);
        $last = (float) ($this->telemetryAnomalyLoggedAt[$key] ?? 0.0);
        if ($now - $last < 15.0) {
            return;
        }
        $this->telemetryAnomalyLoggedAt[$key] = $now;
        if (\count($this->telemetryAnomalyLoggedAt) > 300) {
            $this->telemetryAnomalyLoggedAt = \array_slice($this->telemetryAnomalyLoggedAt, -150, 150, true);
        }
        WlsLogger::error_($message);
    }

    /**
     * 遥测 HTTP>=500 只作为 Worker 存活槽位补齐信号，不把业务 5xx 直接判成 Master 自检异常。
     *
     * @return array{
     *     eligible: bool,
     *     desired: int,
     *     alive: int,
     *     slots_healthy: bool,
     *     recovery_dispatched: bool,
     *     reason: string
     * }
     */
    private function recoverSlotsAfterTelemetryHttpFailure(string $instance): array
    {
        $decision = $this->inspectTelemetryHttpFailureWorkerSlots($instance);
        if (!$decision['eligible'] || $decision['slots_healthy']) {
            return $decision;
        }
        $interval = (float) ($this->context->getConfig('wls.orchestrator.telemetry_5xx_worker_recovery_cooldown_sec', 3.0) ?? 3.0);
        if ($interval < 1.0) {
            $interval = 1.0;
        }
        $now = \microtime(true);
        $last = (float) ($this->telemetryWorkerRecoveryAt[$instance] ?? 0.0);
        if ($now - $last < $interval) {
            $decision['reason'] = 'worker_slots_missing_cooldown';

            return $decision;
        }
        $this->telemetryWorkerRecoveryAt[$instance] = $now;
        if (\count($this->telemetryWorkerRecoveryAt) > 64) {
            $this->telemetryWorkerRecoveryAt = \array_slice($this->telemetryWorkerRecoveryAt, -32, 32, true);
        }
        WlsLogger::warning_(
            "[Master自检] 遥测 HTTP≥500 且 Worker 存活槽位不足（存活 {$decision['alive']}/期望 {$decision['desired']}）— 立即补齐拉起"
        );
        $this->reconcileRoleSlotGaps('worker');

        $decision['recovery_dispatched'] = true;
        $decision['reason'] = 'worker_slots_missing_recovery';

        return $decision;
    }

    /**
     * @return array{
     *     eligible: bool,
     *     desired: int,
     *     alive: int,
     *     slots_healthy: bool,
     *     recovery_dispatched: bool,
     *     reason: string
     * }
     */
    private function inspectTelemetryHttpFailureWorkerSlots(string $instance): array
    {
        $decision = [
            'eligible' => false,
            'desired' => 0,
            'alive' => 0,
            'slots_healthy' => false,
            'recovery_dispatched' => false,
            'reason' => 'orchestrator_inactive',
        ];

        if ($this->context === null || !$this->running || $this->shuttingDown || $this->masterShutdownIntent) {
            return $decision;
        }
        if ($this->childServicesBootstrapInProgress) {
            $decision['reason'] = 'child_services_bootstrap';

            return $decision;
        }
        if ($this->startAllCompletedAt > 0.0 && (\microtime(true) - $this->startAllCompletedAt) < 50.0) {
            $decision['reason'] = 'startup_cooldown';

            return $decision;
        }
        $desired = (int) ($this->desiredState['worker'] ?? 0);
        $decision['desired'] = $desired;
        if ($desired <= 0) {
            $decision['reason'] = 'worker_not_expected';

            return $decision;
        }

        $alive = $this->countRoleSlotsProcessAlive('worker');
        $decision['eligible'] = true;
        $decision['alive'] = $alive;
        $decision['slots_healthy'] = $alive >= $desired;
        $decision['reason'] = $decision['slots_healthy']
            ? 'worker_slots_healthy'
            : 'worker_slots_missing';

        return $decision;
    }

    /**
     * 子进程 status_report 数值 sanity（connections/memory/requests）
     */
    private function auditChildStatusReport(array $msg, int $clientId): void
    {
        $instance = $this->registry->getInstanceByIpcClient($clientId);
        $tag = $instance !== null ? "{$instance->role}#{$instance->instanceId}" : "ipc:{$clientId}";
        $conn = (int) ($msg['connections'] ?? 0);
        $mem = (int) ($msg['memory'] ?? 0);
        $req = (int) ($msg['requests'] ?? 0);
        if ($conn < 0 || $conn > 500_000) {
            WlsLogger::error_("[Master自检] status_report 异常 connections={$conn} {$tag}");
        }
        if ($mem < 0 || $mem > 64 * 1024 * 1024 * 1024) {
            WlsLogger::error_("[Master自检] status_report 异常 memory={$mem} {$tag}");
        }
        if ($req < 0 || $req > 2_000_000_000) {
            WlsLogger::error_("[Master自检] status_report 异常 requests={$req} {$tag}");
        }
    }

    /**
     * Master 自检：控制面、各角色槽位 READY 数、缺则补齐（Worker / Dispatcher 等）
     */
    private function performMasterSelfAudit(): void
    {
        if ($this->context === null || !$this->running || $this->shuttingDown) {
            return;
        }
        if ($this->masterShutdownIntent) {
            return;
        }
        if ($this->childServicesBootstrapInProgress) {
            return;
        }

        $port = $this->controlServer?->getPort() ?? 0;
        if ($this->controlServer === null || $port <= 0) {
            WlsLogger::error_('[Master自检] Master 控制面 IPC 不可用');

            return;
        }

        if ($this->startAllCompletedAt > 0.0 && (\microtime(true) - $this->startAllCompletedAt) < 50.0) {
            return;
        }

        foreach (['worker', 'dispatcher', 'redirect', 'session_server'] as $role) {
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
            }
            $desired = (int) ($this->desiredState[$role] ?? 0);
            if ($desired <= 0) {
                continue;
            }
            $ready = $this->countRoleSlotsReadyHealthy($role);
            if ($ready >= $desired) {
                continue;
            }
            if ($role === 'worker') {
                WlsLogger::error_(
                    "[Master自检] Worker 未完备: 期望 {$desired} 槽位 READY，当前 {$ready}，执行补齐"
                );
            } elseif ($role === 'dispatcher') {
                WlsLogger::error_(
                    "[Master自检] Dispatcher 异常或缺失: 期望 {$desired}，就绪 {$ready} — 尝试维护/拉起"
                );
            } else {
                WlsLogger::warning_(
                    "[Master自检] {$role} 槽位不齐: 期望 {$desired}，就绪 {$ready}，尝试补齐"
                );
            }
            $this->reconcileRoleSlotGaps($role);
        }
    }

    private function countRoleSlotsReadyHealthy(string $role): int
    {
        $n = 0;
        $desired = (int) ($this->desiredState[$role] ?? 0);
        for ($slot = 1; $slot <= $desired; $slot++) {
            $inst = $this->registry->getInstance($role, $slot);
            if ($inst === null) {
                continue;
            }
            if ($inst->state !== ServiceInstance::STATE_READY) {
                continue;
            }
            if ($inst->ipcClientId === null
                || ($this->controlServer !== null && !$this->controlServer->clientExists($inst->ipcClientId))) {
                continue;
            }
            if (!$this->isInstanceServiceAlive($inst)) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    private function countRoleSlotsProcessAlive(string $role): int
    {
        $n = 0;
        $desired = (int) ($this->desiredState[$role] ?? 0);
        for ($slot = 1; $slot <= $desired; $slot++) {
            $inst = $this->registry->getInstance($role, $slot);
            if ($inst === null) {
                continue;
            }
            if (!$this->isInstanceServiceAlive($inst)) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    /**
     * 按 desiredState 补齐指定角色缺失/失效槽位（与 reconcileDesiredState 单角色逻辑一致）
     */
    private function reconcileRoleSlotGaps(string $role): void
    {
        if ($this->context === null || !$this->running || $this->isStopFlowActive()) {
            return;
        }
        if ($this->childServicesBootstrapInProgress && $role === ControlMessage::ROLE_WORKER) {
            return;
        }
        if ($role === 'maintenance' && !$this->maintenanceMode) {
            return;
        }
        $desired = (int) ($this->desiredState[$role] ?? 0);
        if ($desired <= 0) {
            return;
        }
        $provider = $this->registry->getProvider($role);
        if ($provider === null || !$provider->isEnabled($this->context)) {
            return;
        }

        for ($slot = 1; $slot <= $desired; $slot++) {
            $queueKey = "{$role}:{$slot}";
            if (isset($this->resurrectQueue[$queueKey])) {
                continue;
            }
            $instance = $this->registry->getInstance($role, $slot);
            if ($instance === null
                || \in_array($instance->state, [ServiceInstance::STATE_STOPPED, ServiceInstance::STATE_FAILED], true)) {
                WlsLogger::warning_("[Master自检] 补齐实例 {$role}#{$slot}");
                $this->registry->removeInstance($role, $slot);
                $this->startInstance($provider, $slot, $this->context);
                continue;
            }
            if ($instance->epoch !== $this->context->epoch) {
                WlsLogger::warning_("[Master自检] 回收旧代际 {$role}#{$slot} 并重启");
                $this->stopInstanceWithProtocol($instance);
                $this->registry->removeInstance($role, $slot);
                $this->startInstance($provider, $slot, $this->context);
                continue;
            }
            // READY 但 IPC 已断或进程已死：占槽无效，回收并重启（如 Dispatcher 僵死）
            if ($instance->state === ServiceInstance::STATE_READY) {
                $ipcBad = $instance->ipcClientId === null
                    || ($this->controlServer !== null && !$this->controlServer->clientExists($instance->ipcClientId));
                $pidBad = !$this->isInstanceServiceAlive($instance);
                if ($ipcBad || $pidBad) {
                    $this->clearStaleIpcClientIfNeeded($instance);
                    WlsLogger::warning_(
                        '[Master自检] ' . $role . '#' . (string) $slot . ' 标记 READY 但失效 ipc='
                        . ($ipcBad ? '断' : '通') . ' pid=' . ($pidBad ? '死' : '活') . '，回收重启'
                    );
                    $this->stopInstanceWithProtocol($instance);
                    $this->registry->removeInstance($role, $slot);
                    $this->startInstance($provider, $slot, $this->context);
                }
            }
        }
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
     * @return int[]
     */
    private function collectReadyWorkerPortsSorted(): array
    {
        $workerPorts = [];
        foreach ($this->registry->getInstancesByRole(ControlMessage::ROLE_WORKER) as $worker) {
            if ($worker->state === ServiceInstance::STATE_READY
                && $worker->port !== null
                && $worker->port > 0) {
                $workerPorts[] = (int) $worker->port;
            }
        }
        \sort($workerPorts, SORT_NUMERIC);

        return $workerPorts;
    }

    private function formatMaintenanceOperationContext(): string
    {
        $workerPorts = $this->collectReadyWorkerPortsSorted();
        $maintenancePorts = $this->collectReadyMaintenancePortsSorted();
        $dispatchers = $this->registry->getInstancesByRole(ControlMessage::ROLE_DISPATCHER);
        $dispatcherReady = 0;
        foreach ($dispatchers as $dispatcher) {
            if ($dispatcher->state === ServiceInstance::STATE_READY && $dispatcher->ipcClientId !== null) {
                $dispatcherReady++;
            }
        }

        $expectedAck = \is_array($this->pendingMaintenanceModeAck['expected'] ?? null)
            ? \count($this->pendingMaintenanceModeAck['expected'])
            : 0;
        $acked = \is_array($this->pendingMaintenanceModeAck['acked'] ?? null)
            ? \count($this->pendingMaintenanceModeAck['acked'])
            : 0;

        return 'maintenance_mode=' . ($this->maintenanceMode ? 'true' : 'false')
            . ', sticky=' . ($this->maintenanceSticky ? 'true' : 'false')
            . ', ready_workers=' . ($workerPorts !== [] ? \implode(',', $workerPorts) : '(none)')
            . ', ready_maintenance=' . ($maintenancePorts !== [] ? \implode(',', $maintenancePorts) : '(none)')
            . ', dispatchers=' . $dispatcherReady . '/' . \count($dispatchers)
            . ", pending_ack={$acked}/{$expectedAck}";
    }

    /**
     * @return array{immediate_ack_on_enable: bool, wait_for_worker_ack: bool}
     */
    private function resolveMaintenanceEnableDrainStrategy(bool $hasDispatcher, bool $skipBusinessDrainAck): array
    {
        if ($skipBusinessDrainAck) {
            return [
                'immediate_ack_on_enable' => true,
                'wait_for_worker_ack' => false,
            ];
        }

        return [
            'immediate_ack_on_enable' => !$hasDispatcher,
            'wait_for_worker_ack' => true,
        ];
    }

    private function logMaintenanceOperation(
        string $message,
        string $level = 'INFO',
        ?string $signature = null,
        float $throttleSec = 10.0
    ): void {
        $signature ??= $message;
        $now = \microtime(true);
        if (
            $signature === $this->lastMaintenanceOperationSignature
            && ($now - $this->lastMaintenanceOperationLogAt) < $throttleSec
        ) {
            return;
        }

        $this->lastMaintenanceOperationSignature = $signature;
        $this->lastMaintenanceOperationLogAt = $now;
        $message = '[MaintenanceFlow] ' . $message;

        match (\strtoupper($level)) {
            'DEBUG' => WlsLogger::debug_($message),
            'WARN', 'WARNING' => WlsLogger::warning_($message),
            'ERROR' => WlsLogger::error_($message),
            default => WlsLogger::info_($message),
        };
    }

    /**
     * 启用维护：① ceil(N/3) 拉起维护 Worker；② Dispatcher 切池至仅维护端口并确认回执；③ 再标 maintenanceMode。
     *
     * @return array{success: bool, message: string, maintenance_workers: int, worker_ipc_acked?: int}
     */
    public function enableMaintenanceMode(bool $sticky = false, bool $skipBusinessDrainAck = false): array
    {
        if ($this->maintenanceMode) {
            if ($sticky && !$this->maintenanceSticky) {
                $this->maintenanceSticky = true;
                if ($this->context !== null) {
                    $this->persistServicesInfo($this->context);
                }
            }
            if ($skipBusinessDrainAck && $this->context !== null) {
                $maintPorts = $this->collectReadyMaintenancePortsSorted();
                $dispatchers = $this->registry->getInstancesByRole(ControlMessage::ROLE_DISPATCHER);
                if ($maintPorts === []) {
                    return [
                        'success' => false,
                        'message' => 'Maintenance mode already enabled but no READY maintenance worker ports',
                        'maintenance_workers' => $this->countMaintenanceWorkers(),
                    ];
                }
                $expectedDispatcherAcks = [];
                foreach ($dispatchers as $dispatcher) {
                    if ($dispatcher->state !== ServiceInstance::STATE_READY || $dispatcher->ipcClientId === null) {
                        continue;
                    }
                    foreach ($maintPorts as $port) {
                        $expectedDispatcherAcks[$dispatcher->ipcClientId . ':' . $port] = true;
                    }
                }
                if ($dispatchers !== [] && $expectedDispatcherAcks === []) {
                    return [
                        'success' => false,
                        'message' => 'No READY Dispatcher IPC connection for maintenance pool confirmation',
                        'maintenance_workers' => $this->countMaintenanceWorkers(),
                    ];
                }
                if ($expectedDispatcherAcks !== []) {
                    $this->pendingMaintenanceModeAck = [
                        'kind' => 'dispatcher_pool',
                        'request_id' => 'dispatcher_pool_' . \bin2hex(\random_bytes(6)),
                        'expected' => $expectedDispatcherAcks,
                        'acked' => [],
                    ];
                }
                $this->publishDispatcherRouteTableFromPorts($maintPorts, ControlMessage::ROLE_MAINTENANCE, true);
                if ($expectedDispatcherAcks !== []) {
                    $ackTimeout = (float) ($this->context->getConfig(
                        'wls.orchestrator.maintenance_dispatcher_ack_timeout_sec',
                        5.0
                    ) ?? 5.0);
                    $ackTimeout = \max(0.2, \min(30.0, $ackTimeout));
                    $deadline = \microtime(true) + $ackTimeout;
                    while (\microtime(true) < $deadline) {
                        $expected = \count($this->pendingMaintenanceModeAck['expected'] ?? []);
                        $acked = \count($this->pendingMaintenanceModeAck['acked'] ?? []);
                        if ($expected > 0 && $acked >= $expected) {
                            break;
                        }
                        $this->controlServer?->poll(0, 100000);
                    }
                    $expected = \count($this->pendingMaintenanceModeAck['expected'] ?? []);
                    $acked = \count($this->pendingMaintenanceModeAck['acked'] ?? []);
                    $this->pendingMaintenanceModeAck = null;
                    if ($expected > 0 && $acked < $expected) {
                        return [
                            'success' => false,
                            'message' => "Dispatcher maintenance pool ack timeout ({$acked}/{$expected})",
                            'maintenance_workers' => $this->countMaintenanceWorkers(),
                        ];
                    }
                }
            }
            $this->logMaintenanceOperation(
                '收到启用维护请求，但维护模式已处于激活状态，'
                . $this->formatMaintenanceOperationContext(),
                'INFO',
                'enable_maintenance:noop:' . ($sticky ? 'sticky' : 'normal') . ':' . $this->formatMaintenanceOperationContext(),
                0.0
            );
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

        $maintenanceProvider = $this->getMaintenanceProvider();
        if ($maintenanceProvider === null) {
            return [
                'success' => false,
                'message' => 'Maintenance provider not found',
                'maintenance_workers' => 0,
            ];
        }

        $normalWorkers = $this->registry->getInstancesByRole('worker');
        $wCount = \count($normalWorkers);
        $nMaint = \max(1, (int) \ceil(\max($wCount, 1) / 3));
        $drainAckTimeout = (float) ($this->context->getConfig('wls.orchestrator.maintenance_connection_drain_timeout_sec', 300) ?? 300);
        $readyTimeout = (float) ($this->context->getConfig('wls.orchestrator.maintenance_ready_timeout_sec', 90) ?? 90);
        $drainStrategy = $this->resolveMaintenanceEnableDrainStrategy(
            \count($this->registry->getInstancesByRole('dispatcher')) > 0,
            $skipBusinessDrainAck
        );
        $immediateAckOnEnable = (bool) ($drainStrategy['immediate_ack_on_enable'] ?? false);
        $shouldWaitForWorkerAck = (bool) ($drainStrategy['wait_for_worker_ack'] ?? true);

        $normalPortsSnapshot = [];
        foreach ($normalWorkers as $w) {
            if ($w->port !== null && $w->port > 0) {
                $normalPortsSnapshot[] = (int) $w->port;
            }
        }

        $hasDispatcher = \count($this->registry->getInstancesByRole('dispatcher')) > 0;

        WlsLogger::info_(
            "[Orchestrator] 启用维护: 维护 Worker {$nMaint} 个（业务 Worker {$wCount}，⌈N/3⌉）→ Dispatcher 切池 → 等待存量连接排空"
        );
        $this->logMaintenanceOperation(
            "开始启用维护：sticky=" . ($sticky ? 'true' : 'false')
            . ", maintenance_workers={$nMaint}, business_workers={$wCount}, has_dispatcher="
            . ($hasDispatcher ? 'true' : 'false')
            . ', skip_business_drain_ack=' . ($skipBusinessDrainAck ? 'true' : 'false')
            . '，' . $this->formatMaintenanceOperationContext(),
            'WARN',
            'enable_maintenance:start:' . $nMaint . ':' . ($sticky ? '1' : '0') . ':' . ($skipBusinessDrainAck ? '1' : '0') . ':' . $this->formatMaintenanceOperationContext(),
            0.0
        );

        $maintenanceProvider->enable($nMaint);

        for ($i = 1; $i <= $nMaint; $i++) {
            $existing = $this->registry->getInstance('maintenance', $i);
            if ($existing !== null
                && $existing->state === ServiceInstance::STATE_READY
                && $existing->port !== null
                && $existing->port > 0) {
                continue;
            }
            if ($this->startInstance($maintenanceProvider, $i, $this->context) === null) {
                for ($j = 1; $j < $i; $j++) {
                    $m = $this->registry->getInstance('maintenance', $j);
                    if ($m !== null && $m->ipcClientId !== null) {
                        $this->controlServer?->sendTo($m->ipcClientId, ControlMessage::shutdown());
                    }
                    $this->registry->removeInstance('maintenance', $j);
                }
                $maintenanceProvider->disable();
                $this->logMaintenanceOperation(
                    "启用维护失败：maintenance#{$i} 启动失败，{$this->formatMaintenanceOperationContext()}",
                    'ERROR',
                    "enable_maintenance:start_failed:{$i}:" . $this->formatMaintenanceOperationContext(),
                    0.0
                );

                return [
                    'success' => false,
                    'message' => (string) __('维护 Worker #%{1} 启动失败', [$i]),
                    'maintenance_workers' => 0,
                ];
            }
        }

        if (!$this->waitMaintenanceInstancesReady($nMaint, $readyTimeout)) {
            $this->stopMaintenanceWorkers();
            $maintenanceProvider->disable();

            return [
                'success' => false,
                'message' => (string) __('维护 Worker 未在 %{1}s 内全部 READY（IPC）', [(string) $readyTimeout]),
                'maintenance_workers' => 0,
            ];
        }

        $maintPorts = [];
        for ($i = 1; $i <= $nMaint; $i++) {
            $m = $this->registry->getInstance('maintenance', $i);
            if ($m !== null && $m->port !== null && $m->port > 0) {
                $maintPorts[] = (int) $m->port;
            }
        }
        if ($maintPorts === []) {
            $this->stopMaintenanceWorkers();
            $maintenanceProvider->disable();
            $this->logMaintenanceOperation(
                '启用维护失败：maintenance Worker 全部 READY 但没有可发布的监听端口，'
                . $this->formatMaintenanceOperationContext(),
                'ERROR',
                'enable_maintenance:no_ports:' . $this->formatMaintenanceOperationContext(),
                0.0
            );

            return [
                'success' => false,
                'message' => 'Maintenance workers have no listen port',
                'maintenance_workers' => 0,
            ];
        }

        $maintPortsStr = \implode(',', $maintPorts);
        $this->logMaintenanceOperation(
            "维护 Worker 已 READY：ports={$maintPortsStr}，准备切换 Dispatcher / 排空业务 Worker，"
            . $this->formatMaintenanceOperationContext(),
            'INFO',
            "enable_maintenance:ready_ports:{$maintPortsStr}:" . $this->formatMaintenanceOperationContext(),
            0.0
        );

        if ($hasDispatcher) {
            $expectedDispatcherAcks = [];
            foreach ($this->registry->getInstancesByRole(ControlMessage::ROLE_DISPATCHER) as $dispatcher) {
                if ($dispatcher->state !== ServiceInstance::STATE_READY || $dispatcher->ipcClientId === null) {
                    continue;
                }
                foreach ($maintPorts as $port) {
                    $expectedDispatcherAcks[$dispatcher->ipcClientId . ':' . $port] = true;
                }
            }
            if ($expectedDispatcherAcks === []) {
                $this->logMaintenanceOperation(
                    '启用维护失败：没有 READY Dispatcher IPC 可确认维护池切换，'
                    . $this->formatMaintenanceOperationContext(),
                    'ERROR',
                    'enable_maintenance:no_dispatcher_ack_target:' . $this->formatMaintenanceOperationContext(),
                    0.0
                );

                return [
                    'success' => false,
                    'message' => 'No READY Dispatcher IPC connection for maintenance pool confirmation',
                    'maintenance_workers' => $nMaint,
                ];
            }
            if ($expectedDispatcherAcks !== []) {
                $this->pendingMaintenanceModeAck = [
                    'kind' => 'dispatcher_pool',
                    'request_id' => 'dispatcher_pool_' . \bin2hex(\random_bytes(6)),
                    'expected' => $expectedDispatcherAcks,
                    'acked' => [],
                ];
            }
            $this->publishDispatcherRouteTableFromPorts($maintPorts, ControlMessage::ROLE_MAINTENANCE, true);
            if ($expectedDispatcherAcks !== []) {
                $ackTimeout = (float) ($this->context->getConfig(
                    'wls.orchestrator.maintenance_dispatcher_ack_timeout_sec',
                    5.0
                ) ?? 5.0);
                $ackTimeout = \max(0.2, \min(30.0, $ackTimeout));
                $deadline = \microtime(true) + $ackTimeout;
                while (\microtime(true) < $deadline) {
                    $expected = \count($this->pendingMaintenanceModeAck['expected'] ?? []);
                    $acked = \count($this->pendingMaintenanceModeAck['acked'] ?? []);
                    if ($expected > 0 && $acked >= $expected) {
                        break;
                    }
                    $this->controlServer?->poll(0, 100000);
                }
                $expected = \count($this->pendingMaintenanceModeAck['expected'] ?? []);
                $acked = \count($this->pendingMaintenanceModeAck['acked'] ?? []);
                $this->pendingMaintenanceModeAck = null;
                if ($expected > 0 && $acked < $expected) {
                    $this->logMaintenanceOperation(
                        "Dispatcher 维护池确认超时：acked={$acked}/{$expected}, ports={$maintPortsStr}，"
                        . $this->formatMaintenanceOperationContext(),
                        'ERROR',
                        "enable_maintenance:dispatcher_ack_timeout:{$acked}/{$expected}:{$maintPortsStr}",
                        0.0
                    );

                    return [
                        'success' => false,
                        'message' => "Dispatcher maintenance pool ack timeout ({$acked}/{$expected})",
                        'maintenance_workers' => $nMaint,
                    ];
                }
            } else {
                $this->controlServer?->poll(0, 150000);
            }
        }

        $this->pendingMaintenanceModeAck = null;
        // 维护模式只通过 Dispatcher 池切换做分流，不直接切业务 Worker 进程内维护态。
        $this->logMaintenanceOperation(
            '维护启用采用 Dispatcher 分流：不下发业务 Worker set_maintenance_mode，'
            . $this->formatMaintenanceOperationContext(),
            'WARN',
            'enable_maintenance:dispatcher_only:' . $this->formatMaintenanceOperationContext(),
            0.0
        );
        $ackedClients = [];

        $this->maintenanceMode = true;
        $this->maintenanceSticky = $sticky;
        $this->desiredState['maintenance'] = $nMaint;
        $this->persistServicesInfo($this->context);
        $this->logMaintenanceOperation(
            "维护模式启用完成：dispatcher_only=true, maintenance_workers={$nMaint}, ports={$maintPortsStr}，"
            . $this->formatMaintenanceOperationContext(),
            'WARN',
            "enable_maintenance:done:dispatcher_only:{$maintPortsStr}:" . $this->formatMaintenanceOperationContext(),
            0.0
        );

        return [
            'success' => true,
            'message' => (string) __('维护模式已启用: Dispatcher 已切至维护池, 维护进程 %{1} 个', [$nMaint]),
            'maintenance_workers' => $nMaint,
            'worker_ipc_acked' => \count($ackedClients),
        ];
    }

    /**
     * Switch only Dispatcher routing for framework maintenance CLI toggles.
     *
     * This intentionally does not start/stop maintenance workers and does not
     * send set_maintenance_mode to any worker process.
     *
     * @return array{success: bool, message: string}
     */
    private function setDispatcherMaintenanceRouting(bool $enabled): array
    {
        $ports = $enabled
            ? $this->collectReadyMaintenancePortsSorted()
            : $this->collectReadyWorkerPortsSorted();
        $role = $enabled ? ControlMessage::ROLE_MAINTENANCE : ControlMessage::ROLE_WORKER;
        $portsStr = \implode(',', $ports);

        if ($enabled && $ports === []) {
            $this->pendingMaintenanceModeAck = null;
            $this->logMaintenanceOperation(
                'Dispatcher-only maintenance routing skipped: no READY maintenance ports, '
                . $this->formatMaintenanceOperationContext(),
                'WARN',
                'dispatcher_only_maintenance:enable:no_ports',
                0.0
            );

            return [
                'success' => true,
                'message' => 'Dispatcher maintenance routing unchanged: no ready maintenance workers',
            ];
        }

        if (\count($this->registry->getInstancesByRole(ControlMessage::ROLE_DISPATCHER)) > 0) {
            $this->publishDispatcherRouteTableFromPorts($ports, $role);
            $this->controlServer?->poll(0, 150000);
        }

        $this->pendingMaintenanceModeAck = null;
        $this->maintenanceMode = $enabled;
        $this->maintenanceSticky = $enabled;

        if ($this->context !== null) {
            $this->persistServicesInfo($this->context);
        }

        $this->logMaintenanceOperation(
            'Dispatcher-only maintenance routing ' . ($enabled ? 'enabled' : 'disabled')
            . ", role={$role}, ports=" . ($portsStr !== '' ? $portsStr : '(none)')
            . ', ' . $this->formatMaintenanceOperationContext(),
            'INFO',
            'dispatcher_only_maintenance:' . ($enabled ? 'enable:' : 'disable:') . $role . ':' . $portsStr,
            0.0
        );

        return [
            'success' => true,
            'message' => $enabled
                ? 'Dispatcher maintenance routing enabled'
                : 'Dispatcher maintenance routing disabled',
        ];
    }

    /**
     * 禁用维护模式：仅将流量切回业务 Worker，维护 Worker 常驻待命。
     *
     * @return array{success: bool, message: string}
     */
    public function disableMaintenanceMode(): array
    {
        if (!$this->maintenanceMode) {
            $this->maintenanceSticky = false;
            $this->deactivateMaintenanceCapacity();
            $this->logMaintenanceOperation(
                '收到关闭维护请求，但维护模式本就未激活，'
                . $this->formatMaintenanceOperationContext(),
                'INFO',
                'disable_maintenance:noop:' . $this->formatMaintenanceOperationContext(),
                0.0
            );
            return [
                'success' => true,
                'message' => 'Maintenance mode already disabled',
            ];
        }

        if ($this->rollingRestartInProgress) {
            $this->logMaintenanceOperation(
                '拒绝关闭维护：当前处于滚动重启流程，'
                . $this->formatMaintenanceOperationContext(),
                'WARN',
                'disable_maintenance:rolling_restart:' . $this->formatMaintenanceOperationContext(),
                0.0
            );
            return [
                'success' => false,
                'message' => 'Cannot disable maintenance mode during rolling restart',
            ];
        }

        WlsLogger::info_('[Orchestrator] 禁用维护: 恢复业务池（维护 Worker 常驻）');

        $restorePorts = [];
        foreach ($this->registry->getInstancesByRole('worker') as $w) {
            if ($w->state === Contract\ServiceInstance::STATE_READY && $w->port !== null && $w->port > 0) {
                $restorePorts[] = (int) $w->port;
            }
        }
        $restorePortsStr = \implode(',', $restorePorts);
        $this->logMaintenanceOperation(
            '开始关闭维护：restore_ports=' . ($restorePortsStr !== '' ? $restorePortsStr : '(none)')
            . '，' . $this->formatMaintenanceOperationContext(),
            'INFO',
            'disable_maintenance:start:' . $restorePortsStr . ':' . $this->formatMaintenanceOperationContext(),
            0.0
        );
        if ($restorePorts !== [] && \count($this->registry->getInstancesByRole('dispatcher')) > 0) {
            $this->publishDispatcherRouteTableFromPorts($restorePorts);
            $this->controlServer?->poll(0, 150000);
        }

        $this->pendingMaintenanceModeAck = null;

        // 维护 Worker 保持常驻，不执行 provider->disable / stopMaintenanceWorkers。
        // 仅退出"维护流量态"，下次切换可直接复用已就绪维护池，减少抖动。
        $this->maintenanceMode = false;
        $this->maintenanceSticky = false;
        $this->deactivateMaintenanceCapacity();

        if ($this->context !== null) {
            $this->persistServicesInfo($this->context);
        }
        $this->logMaintenanceOperation(
            '维护模式关闭完成：restore_ports=' . ($restorePortsStr !== '' ? $restorePortsStr : '(none)')
            . '，' . $this->formatMaintenanceOperationContext(),
            'INFO',
            'disable_maintenance:done:' . $restorePortsStr . ':' . $this->formatMaintenanceOperationContext(),
            0.0
        );

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

        $workers = $this->registry->getInstancesByRole('worker');
        $workerCount = \count($workers);

        if ($workerCount === 0) {
            return [
                'success' => false,
                'message' => 'No workers to restart',
            ];
        }

        if (!$this->waitForWorkerCriticalInfraReady('rolling restart')) {
            $missingRoles = $this->collectWorkerCriticalInfraNotReadyRoles();

            return [
                'success' => false,
                'message' => 'Critical infra not ready for rolling restart: '
                    . (!empty($missingRoles) ? \implode(',', $missingRoles) : 'unknown'),
            ];
        }

        // 仅 1 个 Worker 时无法边摘边转：走维护 Worker 接管；≥2 时从 Dispatcher 摘除→排水→重启→再纳入，流量由其余 Worker 轮询分担（约 1/N）。
        if ($workerCount < 2) {
            if (!$this->maintenanceMode) {
                $enableResult = $this->enableMaintenanceMode();
                if (!$enableResult['success']) {
                    return [
                        'success' => false,
                        'message' => 'Failed to enable maintenance mode: ' . $enableResult['message'],
                    ];
                }
                $this->yieldControlPlane(20000);
            }
        } elseif ($this->maintenanceMode && !$this->maintenanceSticky) {
            $this->disableMaintenanceMode();
            $this->yieldControlPlane(20000);
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

        $epochSnap = $this->ipcImperialEpoch;
        if ($this->maintenanceMode) {
            $this->performRollingRestartWithMaintenanceWorker($epochSnap);

            return;
        }

        $this->performRollingRestartMultiWorkerWithDispatcher($epochSnap);
    }

    /**
     * 单 Worker：维护模式接管流量后直接重启。
     */
    private function performRollingRestartWithMaintenanceWorker(int $epochSnap): void
    {
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
            if ($this->ipcImperialEpoch !== $epochSnap || !$this->rollingRestartInProgress) {
                return;
            }

            $instanceId = $worker->instanceId;
            WlsLogger::info_("[Orchestrator] 滚动重启 Worker #{$instanceId}（维护模式）");
            $this->sendRollingRestartProgress("Restarting worker #{$instanceId} ({$restarted}/{$total})");

            if ($worker->ipcClientId !== null) {
                $this->controlServer?->sendTo($worker->ipcClientId, ControlMessage::shutdown());
            }

            $startWait = \microtime(true);
            $maxWait = 10.0;
            while ((\microtime(true) - $startWait) < $maxWait) {
                if ($this->ipcImperialEpoch !== $epochSnap) {
                    return;
                }
                $this->yieldControlPlane(20000);
                $currentWorker = $this->registry->getInstance('worker', $instanceId);
                if ($currentWorker === null || $currentWorker->ipcClientId === null) {
                    break;
                }
            }

            if ($this->ipcImperialEpoch !== $epochSnap) {
                return;
            }

            $this->registry->removeInstance('worker', $instanceId);
            $this->yieldControlPlane(0);
            $newInstance = $this->startInstance($workerProvider, $instanceId, $this->context);
            $this->yieldControlPlane(0);
            if ($newInstance === null) {
                $this->finishRollingRestart(false, "Failed to restart worker #{$instanceId}");
                return;
            }

            if (!$this->waitWorkerReadyWithEpoch($instanceId, $this->startupTimeout, $epochSnap)) {
                if ($this->ipcImperialEpoch !== $epochSnap) {
                    return;
                }
                $this->finishRollingRestart(false, "Worker #{$instanceId} not ready within {$this->startupTimeout}s");
                return;
            }

            $restarted++;
            $this->rollingRestartProgress = $restarted;
        }

        $elapsedMs = (\microtime(true) - $startTime) * 1000;
        $this->finishRollingRestart(true, "Successfully restarted {$restarted} workers", $elapsedMs);
    }

    /**
     * 多 Worker：按批（Worker≥阈值时智能分三批）摘除→排水→重启→批内全部 READY 后再加入 Dispatcher，未重启的 Worker 继续分担流量。
     */
    private function performRollingRestartMultiWorkerWithDispatcher(int $epochSnap): void
    {
        $startTime = \microtime(true);
        if ($this->registry->getProvider('worker') === null) {
            $this->finishRollingRestart(false, 'Worker provider not found');
            return;
        }

        $slotIds = [];
        foreach ($this->registry->getInstancesByRole('worker') as $w) {
            $slotIds[$w->instanceId] = true;
        }
        $orderedIds = \array_keys($slotIds);
        \sort($orderedIds, SORT_NUMERIC);
        $total = \count($orderedIds);
        $batches = $this->getWorkerRestartBatches($orderedIds);
        $batchTotal = \count($batches);
        WlsLogger::info_(
            "[Orchestrator] 多 Worker 滚动重启共 {$batchTotal} 批，总 {$total} 槽位（三批阈值 wls.orchestrator.worker_three_batch_min_count）"
        );

        $restarted = 0;
        $batchIdx = 0;
        foreach ($batches as $batch) {
            $batchIdx++;
            if ($this->ipcImperialEpoch !== $epochSnap || !$this->rollingRestartInProgress) {
                WlsLogger::warning_('[Orchestrator] 多 Worker 滚动重启中止');

                return;
            }
            $this->sendRollingRestartProgress(
                "Batch {$batchIdx}/{$batchTotal}: restart workers [" . \implode(',', $batch) . "] ({$restarted}/{$total} done)"
            );
            $result = $this->restartWorkerBatchDispatcherAware(
                $batch,
                $epochSnap,
                'rolling',
                $restarted,
                $total,
                $batchIdx,
                $batchTotal
            );
            if ($result === 'aborted') {
                return;
            }
            if ($result === 'failed') {
                return;
            }
            $restarted += \count($batch);
            $this->rollingRestartProgress = $restarted;
        }

        $elapsedMs = (\microtime(true) - $startTime) * 1000;
        $this->finishRollingRestart(
            true,
            "Successfully restarted {$restarted} workers ({$batchTotal} batch(es), dispatcher-aware)",
            $elapsedMs
        );
    }

    private function waitWorkerReadyWithEpoch(int $instanceId, float $timeout, int $epochSnap): bool
    {
        $startWait = \microtime(true);
        while ((\microtime(true) - $startWait) < $timeout) {
            if ($this->ipcImperialEpoch !== $epochSnap) {
                return false;
            }
            $this->yieldControlPlane(20000);
            $currentWorker = $this->registry->getInstance('worker', $instanceId);
            if ($currentWorker !== null && $currentWorker->state === Contract\ServiceInstance::STATE_READY) {
                return true;
            }
        }

        return false;
    }

    /**
     * 完成滚动重启
     */
    private function finishRollingRestart(bool $success, string $message, float $elapsedMs = 0): void
    {
        WlsLogger::info_("[Orchestrator] 滚动重启完成: success={$success}, message={$message}, elapsed={$elapsedMs}ms");

        // 先清理滚动标志，再禁用维护模式，避免 disableMaintenanceMode() 因"滚动中"被拒绝。
        $clientId = $this->rollingRestartClientId;
        $progress = $this->rollingRestartProgress;
        $total = $this->rollingRestartTotal;
        $this->rollingRestartInProgress = false;
        $this->rollingRestartClientId = null;

        if (!$this->maintenanceSticky) {
            $disableResult = $this->disableMaintenanceMode();
            if (!($disableResult['success'] ?? false)) {
                WlsLogger::warning_("[Orchestrator] 滚动重启后禁用维护模式失败: " . ($disableResult['message'] ?? 'unknown'));
            }
        }

        if ($success) {
            if ($this->maintenanceSticky) {
                $this->pushMaintenanceWorkerPoolToDispatchersFromRegistry();
            } else {
                $this->syncDispatcherFullWorkerPoolFromRegistry();
            }
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
    private function sendRollingRestartProgress(
        string $message,
        string $stage = '',
        int $currentWorkerId = 0,
        array $extra = []
    ): void {
        $this->sendReloadProgressMessage(
            $message,
            $this->rollingRestartProgress,
            $this->rollingRestartTotal,
            $stage,
            $currentWorkerId,
            $extra
        );
    }

    private function sendReloadProgressMessage(
        string $message,
        int $completed,
        int $total,
        string $stage = '',
        int $currentWorkerId = 0,
        array $extra = []
    ): void {
        if ($this->rollingRestartClientId === null) {
            return;
        }

        $payload = \array_merge([
            'type' => ControlMessage::TYPE_RELOAD_PROGRESS,
            'message' => $message,
            'completed' => $completed,
            'progress' => $completed,
            'total' => $total,
            'current_worker_id' => $currentWorkerId,
            'stage' => $stage,
        ], $extra);
        $this->controlServer?->sendTo($this->rollingRestartClientId, ControlMessage::encode($payload));
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

    private function deactivateMaintenanceCapacity(): void
    {
        $this->desiredState[ControlMessage::ROLE_MAINTENANCE] = 0;
        $this->clearMaintenanceResurrectQueue();

        $provider = $this->getMaintenanceProvider();
        if ($provider !== null) {
            $provider->disable();
        }

        $this->stopMaintenanceWorkers();
    }

    /**
     * 停止所有维护 Worker
     */
    private function stopMaintenanceWorkers(): void
    {
        $maintenanceWorkers = $this->registry->getInstancesByRole('maintenance');
        if ($maintenanceWorkers !== []) {
            $slots = [];
            foreach ($maintenanceWorkers as $worker) {
                $slots[] = (string) $worker->instanceId;
            }
            $this->logMaintenanceOperation(
                '准备停止 maintenance workers: slots=' . \implode(',', $slots)
                . '，' . $this->formatMaintenanceOperationContext(),
                'INFO',
                'stop_maintenance_workers:' . \implode(',', $slots) . ':' . $this->formatMaintenanceOperationContext(),
                0.0
            );
        }
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

    private function handleMaintenanceModeAck(array $msg, int $clientId): void
    {
        if ($this->pendingMaintenanceModeAck === null) {
            return;
        }
        if ((string) ($msg['request_id'] ?? '') !== $this->pendingMaintenanceModeAck['request_id']) {
            return;
        }
        if (!isset($this->pendingMaintenanceModeAck['expected'][$clientId])) {
            return;
        }
        $this->pendingMaintenanceModeAck['acked'][$clientId] = true;
        WlsLogger::debug_("[Orchestrator] 维护信号 ACK client={$clientId} worker_id=" . (int) ($msg['worker_id'] ?? 0));
        $this->logMaintenanceOperation(
            '收到维护 ACK: client=' . $clientId
            . ', worker_id=' . (int) ($msg['worker_id'] ?? 0)
            . '，' . $this->formatMaintenanceOperationContext(),
            'INFO',
            'maintenance_ack:' . (string) ($this->pendingMaintenanceModeAck['request_id'] ?? '') . ':' . $clientId,
            0.0
        );
    }

    private function waitMaintenanceInstancesReady(int $count, float $timeoutSec): bool
    {
        $deadline = \microtime(true) + $timeoutSec;
        while (\microtime(true) < $deadline) {
            $ready = 0;
            for ($i = 1; $i <= $count; $i++) {
                $m = $this->registry->getInstance('maintenance', $i);
                if ($m !== null
                    && $m->state === Contract\ServiceInstance::STATE_READY
                    && $m->port !== null
                    && $m->port > 0) {
                    $ready++;
                }
            }
            if ($ready >= $count) {
                WlsLogger::info_("[Orchestrator] 维护 Worker 共 {$count} 个均已 READY（IPC 确认）");
                $this->logMaintenanceOperation(
                    "维护 Worker READY 达标：ready={$ready}/{$count}，{$this->formatMaintenanceOperationContext()}",
                    'INFO',
                    "maintenance_ready:{$ready}/{$count}:" . $this->formatMaintenanceOperationContext(),
                    0.0
                );

                return true;
            }
            $this->controlServer?->poll(0, 180000);
        }

        $ready = 0;
        for ($i = 1; $i <= $count; $i++) {
            $m = $this->registry->getInstance('maintenance', $i);
            if ($m !== null
                && $m->state === Contract\ServiceInstance::STATE_READY
                && $m->port !== null
                && $m->port > 0) {
                $ready++;
            }
        }
        $this->logMaintenanceOperation(
            "等待 maintenance READY 超时：ready={$ready}/{$count}, timeout_sec={$timeoutSec}，"
            . $this->formatMaintenanceOperationContext(),
            'WARN',
            "maintenance_ready_timeout:{$ready}/{$count}:" . $this->formatMaintenanceOperationContext(),
            0.0
        );

        return false;
    }

    /**
     * 广播缓存清理消息给所有 Worker
     */
    private function broadcastCacheClear(): void
    {
        if ($this->controlServer === null) {
            return;
        }
        $configuredRoles = $this->context?->getConfig('wls.orchestrator.cache_clear_roles', ['worker']);
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
     * 广播 PageBuilder 单页失效：各 Worker 清理 Router handle 静态缓存并重置 ObjectManager（无 opcache 重置）
     */
    private function broadcastPageBuilderPageInvalidate(int $websiteId, string $handle, bool $isHomePage): void
    {
        if ($this->controlServer === null) {
            return;
        }
        $configuredRoles = $this->context?->getConfig('wls.orchestrator.cache_clear_roles', ['worker']);
        $targetRoles = \is_array($configuredRoles) ? $configuredRoles : ['worker'];
        $targetRoles = \array_values(\array_filter(\array_map('strval', $targetRoles)));
        if (empty($targetRoles)) {
            $targetRoles = ['worker'];
        }

        $msg = ControlMessage::pageBuilderPageInvalidate($websiteId, $handle, $isHomePage);
        $targets = [];
        foreach ($this->registry->getAllInstances() as $instance) {
            if ($instance->ipcClientId === null) {
                continue;
            }
            if (!\in_array($instance->role, $targetRoles, true)) {
                continue;
            }
            $targets[] = "{$instance->role}#{$instance->instanceId}(ipc:{$instance->ipcClientId})";
            $this->controlServer->sendTo($instance->ipcClientId, $msg);
        }
        WlsLogger::info_(
            '[IPC] PAGEBUILDER_PAGE_INVALIDATE -> ' . (!empty($targets) ? \implode(', ', $targets) : '(无匹配目标)')
        );
    }

    /**
     * 广播 SSL 证书热重载命令给所有 Worker（含 SSL Worker 和普通 Worker）。
     * Worker 收到后重新读取 ssl_certificate_map.json 并更新进程内 SNI 证书映射。
     *
     * @param string[] $domains 需要针对性清除负缓存的域名列表；空数组 = 全量重载
     */
    private function broadcastSslCertReload(array $domains = []): void
    {
        if ($this->controlServer === null) {
            return;
        }
        $targets = [];
        foreach ($this->registry->getAllInstances() as $instance) {
            if ($instance->ipcClientId === null) {
                continue;
            }
            if ($instance->role !== ControlMessage::ROLE_WORKER) {
                continue;
            }
            $targets[] = "{$instance->role}#{$instance->instanceId}(ipc:{$instance->ipcClientId})";
            $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::sslCertReload($domains ?: null));
        }
        $targetStr = empty($domains) ? '全量' : ('域名:' . \implode(',', $domains));
        WlsLogger::info_("[IPC] SSL_CERT_RELOAD({$targetStr}) -> " . (!empty($targets) ? \implode(', ', $targets) : '(无匹配目标)'));
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
        $this->routingPolicyBroadcastPending = true;
        $now = \microtime(true);
        $delta = $now - $this->lastRoutingPolicyBroadcastAt;
        if ($delta < $this->routingPolicyBroadcastMinIntervalSec) {
            if (!$this->hasMainLoopTask('mainloop:routing_policy_broadcast')) {
                $this->scheduleMainLoopTask('mainloop:routing_policy_broadcast', 'routing_policy_broadcast', function (): void {
                    $waitMs = (int)\max(1, \ceil($this->routingPolicyBroadcastMinIntervalSec * 1000));
                    SchedulerSystem::yieldDelay($waitMs);
                    $this->flushRoutingPolicyBroadcast();
                });
            }
            return;
        }

        $this->flushRoutingPolicyBroadcast();
    }

    /**
     * 真正执行 ROUTING_POLICY 广播（带去重+节流入口）。
     */
    private function flushRoutingPolicyBroadcast(): void
    {
        if ($this->controlServer === null) {
            return;
        }
        $policy = $this->buildRoutingPolicySnapshot();
        $digest = \sha1((string)\json_encode($policy, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        $now = \microtime(true);
        if ($digest === $this->lastRoutingPolicyBroadcastDigest
            && ($now - $this->lastRoutingPolicyBroadcastAt) < 1.5
        ) {
            $this->routingPolicyBroadcastPending = false;
            return;
        }
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

        $this->lastRoutingPolicyBroadcastAt = $now;
        $this->lastRoutingPolicyBroadcastDigest = $digest;
        $this->routingPolicyBroadcastPending = false;
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
        $sessionUnreachable = ($this->infraDegraded[ControlMessage::ROLE_SESSION_SERVER] ?? false);
        $memoryUnreachable = ($this->infraDegraded[ControlMessage::ROLE_MEMORY_SERVER] ?? false);

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
            'infra' => [
                'session_server_unreachable' => $sessionUnreachable,
                'memory_server_unreachable' => $memoryUnreachable,
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
        // 所有服务统一从 Registry 获取端点
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

    // ========== 批量协调消息处理（SOLID: 单一职责）============

    /**
     * 处理批量操作 ACK
     */
    private function handleBatchAck(array $msg, int $clientId): void
    {
        $batchId = $msg['batch_id'] ?? '';
        if ($batchId === '') {
            return;
        }

        $this->batchManager->recordAck($batchId, $clientId);
    }

    /**
     * 处理批量操作响应
     */
    private function handleBatchResponse(array $msg, int $clientId): void
    {
        $batchId = $msg['batch_id'] ?? '';
        if ($batchId === '') {
            return;
        }

        $results = $msg['results'] ?? [];
        $this->batchManager->recordResponse($batchId, $clientId, $results);

        // 检查超时
        $timedOut = $this->batchManager->checkTimeouts();
        foreach ($timedOut as $timeoutBatchId) {
            $this->handleBatchTimeout($timeoutBatchId);
        }
    }

    /**
     * 处理批量操作超时
     */
    private function handleBatchTimeout(string $batchId): void
    {
        $op = $this->batchManager->getOperation($batchId);
        if ($op === null) {
            return;
        }

        WlsLogger::warning_(
            "[Orchestrator] 批量操作 {$batchId}（{$op['action']}）超时，" .
            \count($op['acked']) . '/' . \count($op['expected']) . ' 已响应'
        );

        // 发送取消消息给未响应的子进程
        $this->broadcastToClients(
            $op['expected'],
            ControlMessage::batchCancel($batchId)
        );
    }

    /**
     * 批量发送消息给多个客户端
     *
     * @param list<int> $clientIds 客户端 ID 列表
     * @param string $message 消息内容
     */
    private function broadcastToClients(array $clientIds, string $message): void
    {
        if ($this->controlServer === null) {
            return;
        }

        foreach ($clientIds as $clientId) {
            $this->controlServer->sendTo($clientId, $message);
        }
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
    public function handleIpcDisconnect(int $clientId, array $clientInfo, ControlPlaneServerInterface $server): void
    {
        $instance = $this->registry->getInstanceByIpcClient($clientId);
        if ($instance === null) {
            $this->ipcOnExclusiveHolderDisconnect($clientId);

            return;
        }
        
        $provider = $this->registry->getProvider($instance->role);
        $displayName = $provider?->getDisplayName() ?? $instance->role;

        $peer = (string) ($clientInfo['address'] ?? 'unknown');
        $clientState = (string) ($clientInfo['state'] ?? '');
        $clientRole = (string) ($clientInfo['role'] ?? '');
        $disconnectReason = (string) ($clientInfo['disconnect_reason'] ?? 'unknown');
        $trackingPid = $this->getInstanceTrackingPid($instance);
        WlsLogger::warning_(
            "[Orchestrator] IPC 断开: {$instance->role}#{$instance->instanceId} "
            . "(pid={$trackingPid}, client_id={$clientId}, peer={$peer}, client_state={$clientState}, client_role={$clientRole}, reason={$disconnectReason}), "
            . $this->formatInstanceDebugContext($instance)
        );

        // 清除 IPC 客户端 ID（连接已断开）
        $instance->ipcClientId = null;
        $this->registry->updateInstance($instance);

        // 停机态下的断开一律视为预期行为，不再触发自愈/整组重启
        if ($this->isStopFlowActive()) {
            if ($instance->state !== ServiceInstance::STATE_STOPPED) {
                $instance->state = ServiceInstance::STATE_STOPPING;
                $this->registry->updateInstance($instance);
            }
            if ($this->context !== null) {
                $this->persistServicesInfo($this->context);
            }
            $this->sendStopProgress("  ✓ {$displayName}(PID:{$trackingPid}) 已断开连接");
            return;
        }

        if ($this->fullRestartRequested || $this->childProcessStopInProgress) {
            if (!\in_array($instance->state, [
                ServiceInstance::STATE_STOPPING,
                ServiceInstance::STATE_STOPPED,
            ], true)) {
                $instance->state = ServiceInstance::STATE_STOPPING;
                $this->registry->updateInstance($instance);
            }
            if ($this->context !== null) {
                $this->persistServicesInfo($this->context);
            }
            WlsLogger::info_(
                "[Orchestrator] 实例 {$instance->role}#{$instance->instanceId} 处于整组重启停机窗口，预期断开，跳过复活"
            );
            return;
        }

        // 正在排水、停止中或已停止的实例（graceful reload 主动停止）→ 预期断开，不触发整组重启和复活
        // STATE_STOPPING：draining_complete 后、Worker 退出前，IPC 断开时应跳过（否则会重复安排延迟复活导致 Worker 数量翻倍）
        if (\in_array($instance->state, [
            ServiceInstance::STATE_DRAINING,
            ServiceInstance::STATE_STOPPING,
            ServiceInstance::STATE_STOPPED,
        ], true)) {
            if ($this->context !== null) {
                $this->persistServicesInfo($this->context);
            }
            WlsLogger::info_("[Orchestrator] 实例 {$instance->role}#{$instance->instanceId} 处于 {$instance->state} 状态，预期断开，跳过整组重启");
            return;
        }

        if ($this->cleanupInactiveMaintenanceInstance($instance, 'IPC 断开')) {
            return;
        }

        if ($instance->role === ControlMessage::ROLE_WORKER && $instance->state === ServiceInstance::STATE_READY) {
            $this->fenceWorkerFromDispatcherAfterIpcDisconnect($instance);
        }

        if ($instance->role === ControlMessage::ROLE_SESSION_SERVER
            || $instance->role === ControlMessage::ROLE_MEMORY_SERVER) {
            $this->handleInfraServiceIpcDisconnect($instance);

            return;
        }

        $now = \microtime(true);
        $processStillRunning = $trackingPid > 0 && $this->isProcessRunning($trackingPid);
        $canResurrectLocally = $this->canUseLocalSlotResurrection($instance, $provider);
        $maxSlotRestarts = 10;

        if ($canResurrectLocally && $instance->restarts >= $maxSlotRestarts) {
            WlsLogger::error_(
                "[Orchestrator] {$instance->role}#{$instance->instanceId} 单槽重启已达上限 ({$instance->restarts})，整组重启"
            );
            $this->requestFullRestart(
                "ipc_disconnect:max_restarts:{$instance->role}#{$instance->instanceId} (restarts={$instance->restarts})"
            );

            return;
        }

        // 凡 Master 管理的、参与复活的子进程：进程已死则立即单槽拉起，不整组重启
        if (!$processStillRunning && $canResurrectLocally) {
            if ($this->shouldSkipEarlyPidDeathResurrectionCheck($instance)) {
                $delay = \max(2.0, $this->ipcReconnectGraceSec);
                WlsLogger::warning_(
                    "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开且 PID 不存活，但命中前台启动早期保护，{$delay}s 后复核复活"
                );
                $this->scheduleResurrectionWithDelay($instance, $delay);

                return;
            }
            WlsLogger::warning_(
                "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开且进程已退出，单实例复活"
            );
            $this->scheduleResurrectionWithDelay($instance, 0.0);

            return;
        }

        if ($processStillRunning && $canResurrectLocally) {
            $delay = \max(2.0, $this->ipcReconnectGraceSec);
            WlsLogger::warning_(
                "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开但进程仍存活，{$delay}s 后按复活队列处理（可重连或换进程）"
            );
            $this->scheduleResurrectionWithDelay($instance, $delay);

            return;
        }

        $isNewInstance = $instance->getUptime() < $this->stabilizationSec;
        if ($canResurrectLocally
            && $this->rollingRestartStabilizingUntil > 0
            && $now < $this->rollingRestartStabilizingUntil
            && $isNewInstance) {
            WlsLogger::info_(
                "[Orchestrator] 稳定期内新实例 {$instance->role}#{$instance->instanceId} 断开，单实例重启"
            );
            $this->scheduleResurrectionWithDelay($instance, 2.0);

            return;
        }

        if ($canResurrectLocally) {
            WlsLogger::warning_(
                "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开（无有效 PID 判定），单实例复活"
            );
            $this->scheduleResurrectionWithDelay($instance, 1.0);

            return;
        }

        WlsLogger::error_(
            "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开且该角色不参与本地单槽复活"
        );
        $this->requestFullRestart("ipc_disconnect:no_resurrect:{$instance->role}#{$instance->instanceId}");
    }

    private function fenceWorkerFromDispatcherAfterIpcDisconnect(ServiceInstance $instance): void
    {
        $port = (int)($instance->port ?? 0);
        if ($port <= 0) {
            return;
        }

        $instance->state = ServiceInstance::STATE_FAILED;
        $instance->setMeta('lease_state', 'disconnected_grace');
        $instance->setMeta('dispatcher_pool_confirmed_at', null);
        $instance->setMeta('last_known_pid_set', $instance->getManagedPids());
        $this->registry->updateInstance($instance);

        $this->notifyDispatcherRemoveWorker($port);
        $this->lastDispatcherRouteTableSignature = '';
        $this->syncDispatcherFullWorkerPoolFromRegistry();
        WlsLogger::warning_(
            "[Orchestrator] Worker IPC 断开，已先从 Dispatcher 摘池再进入复活判断: "
            . "{$instance->role}#{$instance->instanceId}, slot_id={$this->getInstanceSlotId($instance)}, port={$port}"
        );
    }

    /**
     * 安排延迟复活（IPC 断开但进程可能还在运行）
     */
    private function scheduleResurrectionWithDelay(ServiceInstance $instance, float $delay): void
    {
        if ($this->isRecoverySuspended()) {
            return;
        }

        $key = $instance->getKey();
        $nowT = \microtime(true);

        if (isset($this->resurrectQueue[$key])) {
            // 已排队为延迟复活，但随后确认进程已死需立即拉起 → 提前到本周期执行
            if ($delay <= 0.0 && (($this->resurrectQueue[$key]['scheduledAt'] ?? 0.0) > $nowT)) {
                $this->resurrectQueue[$key]['scheduledAt'] = $nowT;
                $this->resurrectQueue[$key]['restartDelay'] = 0.0;
                WlsLogger::info_("[Orchestrator] 复活队列改为立即执行: {$key}");
            }
            return;
        }

        $provider = $this->registry->getProvider($instance->role);
        if (!$this->canUseLocalSlotResurrection($instance, $provider)) {
            WlsLogger::info_("[Orchestrator] 服务 {$instance->role} 不参与本地单槽复活");
            return;
        }

        $previousState = $instance->state;
        $instance->state = ServiceInstance::STATE_FAILED;
        $instance->restarts++;
        $instance->setMeta('resurrection_queued_from_state', $previousState);
        $instance->setMeta('resurrection_queued_at', $nowT);
        $this->registry->updateInstance($instance);

        $this->resurrectQueue[$key] = [
            'role' => $instance->role,
            'instanceId' => $instance->instanceId,
            'maxRestarts' => 10,
            'restartDelay' => $delay,
            'scheduledAt' => \microtime(true) + $delay,
            'delayed' => true,  // 标记为延迟复活，执行前需要再次检查进程状态
            'pid' => $instance->pid,  // 保存 PID 用于检查进程是否仍在运行
            'tracking_pid' => $this->getInstanceTrackingPid($instance),
            'root_pid' => $instance->getRootPid(),
            'launcher_pid' => $instance->getLauncherPid(),
            'port' => $instance->port ?? 0,
            'previousState' => $previousState,
        ];
        $trackingPid = $this->getInstanceTrackingPid($instance);
        WlsLogger::info_(
            "[Orchestrator] 安排延迟复活 {$instance->role}#{$instance->instanceId}"
            . "，延迟 {$delay}s (pid={$trackingPid}, previous_state={$previousState})"
        );
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
        $now = \microtime(true);
        $cached = $this->processRunningCache[$pid] ?? null;
        if ($cached !== null && ($now - (float) $cached['checkedAt']) <= $this->processRunningCacheTtlSec) {
            return (bool) $cached['running'];
        }
        $running = Processer::isRunningByPid($pid);
        $this->processRunningCache[$pid] = [
            'running' => $running,
            'checkedAt' => $now,
        ];
        if (\count($this->processRunningCache) > 512) {
            $this->processRunningCache = [];
        }
        return $running;
    }

    /**
     * Windows foreground spawn 场景下，返回的 PID 可能是短生命周期壳进程，
     * 启动初期按 PID 早判"已退出"会误触发复活，导致同槽位重复拉起与 launchId 竞态。
     */
    private function shouldSkipEarlyPidDeathResurrectionCheck(ServiceInstance $instance): bool
    {
        $transport = (string) ($instance->getMeta('spawn_transport') ?? '');
        if ($transport !== 'processer_create_foreground') {
            return false;
        }

        // 仅在启动早期启用保护，避免永久掩盖真实故障。
        return $instance->getUptime() <= ($this->startupGracePeriod * 2);
    }

    private function shouldSuppressUnmatchedRegisterTermination(
        string $role,
        int $pid,
        int $port,
        string $launchId
    ): bool {
        if (!\in_array($role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
            return false;
        }

        foreach ($this->registry->getInstancesByRole($role) as $candidate) {
            if ($port > 0 && (int) ($candidate->port ?? 0) !== $port) {
                continue;
            }
            if (!$this->shouldSkipEarlyPidDeathResurrectionCheck($candidate)) {
                continue;
            }
            if ($launchId !== '' && $candidate->launchId === $launchId) {
                continue;
            }
            WlsLogger::warning_(
                "[Orchestrator] 前台启动早期保护：跳过终止未匹配 register 进程 role={$role}, pid={$pid}, port={$port}, launch_id={$launchId}"
            );

            return true;
        }

        return false;
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
     * 设置总启动最大时长（秒）- 超过此时间未完成启动则强制退出
     */
    public function setStartupMaxDuration(float $duration): void
    {
        $this->startupMaxDuration = $duration;
        // 如果已设置截止时间，需要同步更新
        if ($this->childServicesStartupDeadline > 0) {
            $this->childServicesStartupDeadline = \microtime(true) + $duration;
        }
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
        return $this->isStopFlowActive();
    }

    /**
     * 停止主循环
     */
    public function stop(): void
    {
        $this->running = false;
    }
}
