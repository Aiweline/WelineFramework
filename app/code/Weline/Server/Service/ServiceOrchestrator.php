<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
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
use Weline\Server\Service\Provider\WorkerProvider;

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

    private ServiceRegistry $registry;
    private ?MasterControlServer $controlServer = null;
    private ?ServiceContext $context = null;

    private bool $running = false;
    private bool $shuttingDown = false;
    private bool $stopAllInProgress = false;
    private ?string $pendingStopReason = null;
    private ?int $pendingStopProgressClientId = null;
    private string $stopStage = self::STOP_STAGE_IDLE;
    private ?int $stopProgressClientId = null;
    
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
    private string $lastDispatcherWorkerPoolSignature = '';
    private int $registerTimeoutCount = 0;
    private int $fullRestartCount = 0;
    private int $lastSweepKilled = 0;
    private int $lastSweepStalePidFiles = 0;
    /** @var array<string,int> role => count */
    private array $desiredState = [];

    /** @var array<string, array{role: string, instanceId: int, maxRestarts: int, restartDelay: float}> 等待复活的实例 */
    private array $resurrectQueue = [];

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

    /**
     * 等待 Worker 对 set_maintenance_mode 的 ACK（request_id 对齐）
     *
     * @var array{request_id: string, expected: array<int, true>, acked: array<int, true>}|null
     */
    private ?array $pendingMaintenanceModeAck = null;

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

    /** 滚动重启进度（已完成的 Worker 数量） */
    private int $rollingRestartProgress = 0;

    /** 滚动重启总数（需要重启的 Worker 总数） */
    private int $rollingRestartTotal = 0;

    /** 启动完成时间戳（用于启动后冷却期） */
    private float $startAllCompletedAt = 0.0;

    /** 启动后冷却期（秒）- 在此期间忽略 reload_all:code 请求，避免 FileWatcher 误触发 */
    private float $startupReloadCooldown = 10.0;

    /**
     * 待聚合的 Fiber 池统计请求（CLI 请求 fiber_stats 后等待各 Worker 回复）
     * @var array{replyClientId: int, request_id: string, waiting: array<int, true>, replies: array<int, array>}|null
     */
    private ?array $pendingFiberStatsRequest = null;

    /** 是否已输出"服务器准备就绪"通知 */
    private bool $serverReadyNotified = false;

    /** 仅当本轮启动已完成所有计划实例投递后，才允许输出 ready 通知 */
    private bool $serverReadyNotificationArmed = false;

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

    /** Session/Memory IPC 断开后 Worker 策略里标记「端点不可用」 */
    private array $infraDegraded = [
        ControlMessage::ROLE_SESSION_SERVER => false,
        ControlMessage::ROLE_MEMORY_SERVER => false,
    ];

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

    private function isStopFlowActive(): bool
    {
        return $this->masterShutdownIntent
            || $this->pendingStopReason !== null
            || $this->stopAllInProgress
            || $this->shuttingDown;
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

    public function requestStop(string $reason = 'shutdown', ?int $progressClientId = null, bool $exclusiveIpc = false): bool
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
        $this->pendingStopProgressClientId = $progressClientId;
        $this->setStopStage(self::STOP_STAGE_REQUESTED);

        if ($progressClientId !== null && $this->controlServer !== null) {
            $this->controlServer->sendTo(
                $progressClientId,
                ControlMessage::commandResult(
                    true,
                    ['state' => 'stopping', 'stage' => $this->stopStage],
                    'Stopping'
                )
            );
        }

        WlsLogger::info_("[Orchestrator] 已接收停止请求，等待进入统一停机流程，原因: {$reason}");
        return true;
    }

    private function consumePendingStopRequest(): bool
    {
        if ($this->pendingStopReason === null || $this->shuttingDown || $this->stopAllInProgress) {
            return false;
        }

        $reason = $this->pendingStopReason;
        $progressClientId = $this->pendingStopProgressClientId;
        $this->pendingStopReason = null;
        $this->pendingStopProgressClientId = null;
        $this->stopAll($reason, $progressClientId);

        return true;
    }

    private function isQueuedControlCommand(string $action): bool
    {
        return \in_array($action, [
            ControlMessage::ACTION_RELOAD,
            ControlMessage::ACTION_RELOAD_WAIT,
            ControlMessage::ACTION_STOP,
            ControlMessage::ACTION_CACHE_CLEAR,
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
        $position = $this->activeControlOperation === null ? 0 : 1;
        foreach ($this->pendingControlOperations as $index => $queuedOperation) {
            if ($queuedOperation['id'] === $operation['id']) {
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

        return null;
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
            ControlMessage::commandResult(true, $data, $message)
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
            ControlMessage::ACTION_PAGEBUILDER_PAGE_INVALIDATE => 'PageBuilder page invalidate queued',
            ControlMessage::ACTION_SSL_CERT_RELOAD => 'SSL cert reload queued',
            ControlMessage::ACTION_SECURITY_UNBLOCK => 'Security unblock queued',
            ControlMessage::ACTION_FIBER_SET_CONFIG => 'Fiber config update queued',
            ControlMessage::ACTION_FIBER_RELEASE_IDLE => 'Fiber release queued',
            default => 'Control operation queued',
        };
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

    private function preemptActiveControlOperationForStop(): void
    {
        if ($this->activeControlOperation === null) {
            return;
        }

        $this->activeControlOperation['state'] = self::CONTROL_OPERATION_STATE_ABORTING;
        WlsLogger::warning_(
            "[Orchestrator] stop 请求到达，标记活跃控制操作中止 id={$this->activeControlOperation['id']} action={$this->activeControlOperation['action']}"
        );
    }

    private function processNextQueuedControlOperation(): bool
    {
        if ($this->activeControlOperation !== null || $this->pendingControlOperations === []) {
            return false;
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

        switch ($action) {
            case ControlMessage::ACTION_RELOAD:
                $type = (string)($payload['reload_type'] ?? ControlMessage::RELOAD_TYPE_CODE);
                if ($type === ControlMessage::RELOAD_TYPE_CACHE) {
                    $this->broadcastCacheClear();
                    return;
                }
                $this->reloadAll($type, $this->ipcImperialEpoch);
                return;

            case ControlMessage::ACTION_RELOAD_WAIT:
                $type = (string)($payload['reload_type'] ?? ControlMessage::RELOAD_TYPE_CODE);
                if ($type === ControlMessage::RELOAD_TYPE_CACHE) {
                    $this->broadcastCacheClear();
                    return;
                }
                $this->ipcClearFieldForNewImperial($clientId, ControlMessage::ACTION_RELOAD_WAIT);
                $snap = $this->ipcImperialEpoch;
                try {
                    $this->rollingRestartClientId = $clientId;
                    $this->reloadAll($type, $snap);
                } finally {
                    $this->rollingRestartClientId = null;
                    if ($this->ipcImperialEpoch === $snap && $this->ipcExclusiveClientId === $clientId) {
                        $this->ipcReleaseExclusive();
                    }
                }
                return;

            case ControlMessage::ACTION_CACHE_CLEAR:
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
                    $this->enableMaintenanceMode();
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
                    $this->disableMaintenanceMode();
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
        $this->stopAllInProgress = false;
        $this->pendingStopReason = null;
        $this->pendingStopProgressClientId = null;
        $this->stopStage = self::STOP_STAGE_IDLE;
        $this->masterShutdownIntent = false;
        $this->suppressWorkerEmergencyUntil = 0.0;
        $this->ipcExclusiveCommand = null;
        $this->ipcExclusiveClientId = null;
        $this->ipcImperialEpoch = 0;
        $this->pendingMaintenanceModeAck = null;
        $this->resetServerReadyNotificationState();
        $this->haMode = (bool)$context->getConfig('wls.orchestrator.ha_mode', true);
        $this->fullRestartOnFailure = (bool)$context->getConfig('wls.orchestrator.full_restart_on_failure', true);
        $this->fullRestartCooldown = (float)$context->getConfig('wls.orchestrator.restart_cooldown_sec', 10.0);
        $this->registerTimeout = (float)$context->getConfig('wls.orchestrator.register_timeout_sec', $this->startupGracePeriod);
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
        $this->drainTimeout = (float) ($context->getConfig('wls.orchestrator.drain_timeout_sec', 120.0) ?? 120.0);
        if ($this->drainTimeout < 5.0) {
            $this->drainTimeout = 5.0;
        }
        if ($this->drainTimeout > 7200.0) {
            $this->drainTimeout = 7200.0;
        }
        $this->workerEmergencyCooldownSec = (float)$context->getConfig('wls.orchestrator.worker_emergency_cooldown_sec', 20.0);
        $this->masterSelfAuditIntervalSec = (float) ($context->getConfig('wls.orchestrator.master_self_audit_interval_sec', 20.0) ?? 20.0);
        $this->lastMasterSelfAuditAt = 0.0;
        $this->telemetryAnomalyLoggedAt = [];
        $this->telemetryWorkerRecoveryAt = [];
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
        $this->infraDegraded = [
            ControlMessage::ROLE_SESSION_SERVER => false,
            ControlMessage::ROLE_MEMORY_SERVER => false,
        ];
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

        // 启动顺序：Dispatcher -> 基础服务(session/memory/redirect/maintenance...) -> Worker（批量）
        $providers = $this->sortProvidersForStartup($this->registry->getAllProviders());
        $startedCount = 0;
        $startupAcceptance = [];
        $phaseOneProviders = [];
        $workerProviders = [];

        foreach ($providers as $provider) {
            if (!$provider->isEnabled($context)) {
                WlsLogger::debug_("[Orchestrator] 服务 {$provider->getRole()} 未启用，跳过");
                continue;
            }
            $role = $provider->getRole();
            $this->desiredState[$role] = $provider->getInstanceCount($context);
            if ($role === ControlMessage::ROLE_WORKER) {
                $workerProviders[] = $provider;
            } else {
                $phaseOneProviders[] = $provider;
            }
        }

        // 第一阶段：Dispatcher/Session/Memory 等非 Worker 服务并发批量启动
        if ($phaseOneProviders !== []) {
            $phaseOneInstances = $this->startProvidersBatch($phaseOneProviders, $context);
            foreach ($phaseOneProviders as $provider) {
                $role = $provider->getRole();
                $displayName = $provider->getDisplayName();
                $instanceCount = $provider->getInstanceCount($context);
                foreach (($phaseOneInstances[$role] ?? []) as $instance) {
                    if ($instance !== null) {
                        $startedCount++;
                        if ($context->frontend) {
                            $portInfo = $instance->port !== null ? " (port={$instance->port})" : '';
                            echo "\033[32m    ✓ {$role}#{$instance->instanceId}{$portInfo}\033[0m\n";
                        }
                    }
                }
                if ($provider->requiresStartupReadyBarrier()) {
                    $minReady = $this->resolveStartupAcceptanceMinReady($role, $instanceCount, $context);
                    $startupAcceptance[$role] = [
                        'displayName' => $displayName,
                        'expected' => $instanceCount,
                        'minReady' => $minReady,
                    ];
                }
            }
            $this->controlServer?->poll(0, 250000);
        }

        // 第二阶段：Worker 批量启动
        foreach ($workerProviders as $provider) {
            $instanceCount = $provider->getInstanceCount($context);
            $role = $provider->getRole();
            $displayName = $provider->getDisplayName();
            if ($context->frontend) {
                echo "\033[34m  启动 {$displayName}: {$instanceCount} 个实例\033[0m\n";
            }
            WlsLogger::info_("[Orchestrator] 启动服务 {$displayName} (role={$role}, instances={$instanceCount}, priority={$provider->getPriority()})");
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
            $this->controlServer?->poll(0, 200000);
            if ($provider->requiresStartupReadyBarrier()) {
                $minReady = $this->resolveStartupAcceptanceMinReady($role, $instanceCount, $context);
                $startupAcceptance[$role] = [
                    'displayName' => $displayName,
                    'expected' => $instanceCount,
                    'minReady' => $minReady,
                ];
            }
        }

        if (!empty($startupAcceptance)) {
            $this->waitForStartupAcceptance($startupAcceptance, $context);
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
        $this->armServerReadyNotification();
    }

    /**
     * 启动顺序策略：
     * 1) dispatcher 最先启动（先占入口，配合启动保护阈值兜底）
     * 2) 其他角色按 provider priority 启动
     * 3) worker 最后启动（批量拉起）
     *
     * @param ServiceProviderInterface[] $providers
     * @return ServiceProviderInterface[]
     */
    private function sortProvidersForStartup(array $providers): array
    {
        \usort($providers, static function (ServiceProviderInterface $a, ServiceProviderInterface $b): int {
            $rank = static function (string $role): int {
                if ($role === ControlMessage::ROLE_DISPATCHER) {
                    return 0;
                }
                if ($role === ControlMessage::ROLE_WORKER) {
                    return 2;
                }

                return 1;
            };

            $ra = $rank($a->getRole());
            $rb = $rank($b->getRole());
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            $pa = $a->getPriority();
            $pb = $b->getPriority();
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return \strcmp($a->getRole(), $b->getRole());
        });

        return $providers;
    }

    private function resolveStartupAcceptanceMinReady(string $role, int $expected, ServiceContext $context): int
    {
        $ratio = (float)($context->getConfig('wls.orchestrator.startup_acceptance_ratio', 1.0) ?? 1.0);
        $min = (int)($context->getConfig('wls.orchestrator.startup_acceptance_min_ready', 1) ?? 1);
        $roleMap = $context->getConfig('wls.orchestrator.startup_acceptance_min_ready_by_role', []);
        if (\is_array($roleMap) && isset($roleMap[$role])) {
            $min = (int)$roleMap[$role];
        } elseif ($role === ControlMessage::ROLE_WORKER) {
            // Worker 启动默认策略：谁先 READY 先接流量，不要求全量就绪。
            $ratio = (float)($context->getConfig('wls.orchestrator.worker_startup_acceptance_ratio', 0.0) ?? 0.0);
            $min = (int)($context->getConfig('wls.orchestrator.worker_startup_acceptance_min_ready', 1) ?? 1);
        }

        if ($ratio <= 0.0) {
            $ratio = 0.0;
        }
        if ($ratio > 1.0) {
            $ratio = 1.0;
        }

        $ratioMin = (int)\ceil($expected * $ratio);
        $resolved = \max(1, $min, $ratioMin);
        if ($resolved > $expected) {
            $resolved = $expected;
        }

        return $resolved;
    }

    /**
     * 统一启动验收：按角色等待 READY 数达到阈值，避免逐实例串行等待导致启动过慢。
     *
     * @param array<string,array{displayName:string,expected:int,minReady:int}> $startupAcceptance
     */
    private function waitForStartupAcceptance(array $startupAcceptance, ServiceContext $context): void
    {
        if ($this->controlServer === null) {
            return;
        }

        $deadline = \microtime(true) + $this->startupTimeout;
        $lastPending = '';
        while (\microtime(true) < $deadline) {
            $this->controlServer->poll(0, 100000);
            $pending = [];
            foreach ($startupAcceptance as $role => $rule) {
                $readyCount = $this->countRoleReadyInstances((string)$role);
                if ($readyCount < $rule['minReady']) {
                    $pending[] = "{$role}:{$readyCount}/{$rule['minReady']}";
                }
            }

            if ($pending === []) {
                WlsLogger::info_('[Orchestrator] 启动验收通过: 所有关键角色 READY 已达阈值');
                return;
            }

            $pendingLabel = \implode(', ', $pending);
            if ($pendingLabel !== $lastPending) {
                WlsLogger::info_("[Orchestrator] 启动验收中: {$pendingLabel}");
                $lastPending = $pendingLabel;
            }
            SchedulerSystem::usleep(100000);
        }

        foreach ($startupAcceptance as $role => $rule) {
            $readyCount = $this->countRoleReadyInstances((string)$role);
            if ($readyCount < $rule['minReady']) {
                WlsLogger::warning_(
                    "[Orchestrator] 启动验收超时: {$rule['displayName']}({$role}) READY {$readyCount}/{$rule['minReady']} (expected={$rule['expected']}, timeout={$this->startupTimeout}s)"
                );
                if ($context->frontend) {
                    echo "\033[33m    启动验收超时 {$rule['displayName']}({$role}): {$readyCount}/{$rule['minReady']}\033[0m\n";
                }
            }
        }
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

    /**
     * 跨 Provider 批量拉起（用于 Dispatcher/Session/Memory 等基础服务并发启动）。
     *
     * @param ServiceProviderInterface[] $providers
     * @return array<string, array<int, ServiceInstance|null>>
     */
    private function startProvidersBatch(array $providers, ServiceContext $context): array
    {
        $commands = [];
        $prepared = [];
        $result = [];

        foreach ($providers as $provider) {
            $role = $provider->getRole();
            $instanceCount = $provider->getInstanceCount($context);
            $displayName = $provider->getDisplayName();

            if ($context->frontend) {
                echo "\033[34m  启动 {$displayName}: {$instanceCount} 个实例\033[0m\n";
            }
            WlsLogger::info_("[Orchestrator] 启动服务 {$displayName} (role={$role}, instances={$instanceCount}, priority={$provider->getPriority()})");

            if ($role === 'session_server') {
                $sessionPort = $provider->getPort(1, $context);
                if ($sessionPort > 0) {
                    Processer::killProcessByPort($sessionPort);
                    Processer::forceReleasePort($sessionPort);
                    SchedulerSystem::usleep(500000);
                }
            }

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
                    processKind: $provider->getProcessKind(),
                    moduleCode: $provider->getModuleCode(),
                );

                $command = $provider->buildCommand($i, $context);
                $processName = $command->getProcessName();
                if ($processName !== null) {
                    $instance->setMeta('process_name', $processName);
                }
                $instance->setMeta('control_capabilities', $this->buildControlCapabilities($provider));
                $instance->setMeta('epoch', $context->epoch);
                $instance->setMeta('launch_id', $launchId);

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

                $key = "{$role}#{$i}";
                $commands[$key] = [
                    'command' => $cmd,
                    'block' => false,
                    'foreground' => $this->shouldLaunchForeground($role, $context),
                ];
                $prepared[$key] = [
                    'instance' => $instance,
                    'provider' => $provider,
                    'role' => $role,
                    'instance_id' => $i,
                ];
            }
        }

        $pids = Processer::batchCreate($commands);
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
            $instance->pid = $pid > 0 ? $pid : 0;
            $instance->state = ServiceInstance::STATE_STARTING;
            // Use the real post-spawn time as the startup baseline so
            // acceptance timing tracks the actual batch launch completion.
            $instance->startedAt = \microtime(true);
            $this->registry->addInstance($instance);
            $provider->onStarted($instance);
            WlsLogger::info_("[Orchestrator] 已启动 {$role}#{$instanceId} (pid={$pid}" . ($instance->port !== null ? ", port={$instance->port}" : '') . ')');
            $result[$role][] = $instance;
        }

        return $result;
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
        if ($instanceCount <= 0) {
            return [];
        }

        return $this->startInstanceIdsBatch($provider, \range(1, $instanceCount), $context);
    }

    /**
     * @param int[] $instanceIds
     * @return array<ServiceInstance|null>
     */
    private function startInstanceIdsBatch(ServiceProviderInterface $provider, array $instanceIds, ServiceContext $context): array
    {
        $instanceIds = \array_values(\array_unique(\array_map('intval', $instanceIds)));
        \sort($instanceIds, \SORT_NUMERIC);
        if ($instanceIds === []) {
            return [];
        }

        if (\count($instanceIds) === 1) {
            return [$this->startInstance($provider, $instanceIds[0], $context)];
        }

        $role = $provider->getRole();
        $preparedInstances = [];
        $commands = [];

        foreach ($instanceIds as $instanceId) {
            $port = $provider->getPort($instanceId, $context);
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

            $command = $provider->buildCommand($instanceId, $context);
            $processName = $command->getProcessName();
            if ($processName !== null) {
                $instance->setMeta('process_name', $processName);
            }
            $instance->setMeta('control_capabilities', $this->buildControlCapabilities($provider));
            $instance->setMeta('epoch', $context->epoch);
            $instance->setMeta('launch_id', $launchId);

            $cmd = $command->build();
            if ($instance->epoch > 0) {
                $cmd .= ' --epoch=' . \escapeshellarg((string) $instance->epoch);
            }
            if ($instance->launchId !== '') {
                $cmd .= ' --launch-id=' . \escapeshellarg($instance->launchId);
            }
            if ($processName !== null) {
                $cmd .= ' --name=' . \escapeshellarg($processName);
            }

            $preparedInstances[$instanceId] = $instance;
            $commands[(string) $instanceId] = [
                'command' => $cmd,
                'block' => false,
                'foreground' => $this->shouldLaunchForeground($role, $context),
            ];
        }

        WlsLogger::debug_(
            "[Orchestrator] 批量启动 {$role} [" . \implode(',', $instanceIds) . ']（Processer::batchCreate）'
        );
        $pids = Processer::batchCreate($commands);

        $results = [];
        foreach ($preparedInstances as $instanceId => $instance) {
            $pid = (int) ($pids[(string) $instanceId] ?? $pids[$instanceId] ?? 0);
            if ($pid <= 0) {
                WlsLogger::warning_("[Orchestrator] 启动 {$role}#{$instanceId} 未返回 PID（非阻塞路径），等待 IPC register 确认");
            }

            $instance->pid = $pid > 0 ? $pid : 0;
            $instance->state = ServiceInstance::STATE_STARTING;
            $instance->startedAt = \microtime(true);
            $this->registry->addInstance($instance);

            WlsLogger::info_(
                "[Orchestrator] 已启动 {$role}#{$instanceId} (pid={$pid}" . ($instance->port !== null ? ", port={$instance->port}" : '') . ')'
            );

            $provider->onStarted($instance);
            $results[] = $instance;
        }

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
            processKind: $provider->getProcessKind(),
            moduleCode: $provider->getModuleCode(),
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
        $instance->startedAt = \microtime(true);
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

        // 必须 block=false，否则会阻塞 Master 主循环。
        // Windows 前台模式下允许 Worker 打开独立控制台窗口，便于直接观察每个槽位的启动与请求日志。
        return Processer::create(
            $cmd,
            block: false,
            foreground: $this->shouldLaunchForeground($instance->role, $this->context)
        );
    }

    private function shouldLaunchForeground(string $role, ?ServiceContext $context): bool
    {
        if ($context === null || !$context->frontend) {
            return false;
        }

        if (\in_array($role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
            return IS_WIN && (bool) ($context->getConfig('wls.orchestrator.frontend_worker_windows', true) ?? true);
        }

        return true;
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
        if ($this->stopAllInProgress || $this->shuttingDown) {
            $this->sendStopAlreadyInProgress($progressClientId);
            WlsLogger::warning_("[Orchestrator] 已在停机流程中，忽略重复 stopAll 请求，原因: {$reason}，阶段: {$this->stopStage}");
            return;
        }

        $this->pendingStopReason = null;
        $this->pendingStopProgressClientId = null;
        $this->stopAllInProgress = true;
        $this->shuttingDown = true;
        $this->masterShutdownIntent = true;
        $this->stopProgressClientId = $progressClientId;
        WlsLogger::info_("[Orchestrator] 开始停止所有服务，原因: {$reason}");

        $totalInstances = \count($this->registry->getAllInstances());
        if ($totalInstances === 0) {
            WlsLogger::info_('[Orchestrator] 无运行中的实例');
            $this->sendStopProgress('无运行中的实例');
            $this->setStopStage(self::STOP_STAGE_COMPLETE);
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
        $this->setStopStage(self::STOP_STAGE_DRAIN);
        WlsLogger::info_('[Orchestrator] 阶段1: 广播 DRAIN');
        $this->sendStopProgress('阶段1/6: 广播 DRAIN - 通知子进程停止接受新请求');
        $this->broadcastDrainToAll();

        // ========== 阶段 2：等待排水完成（默认 10s，可配 wls.orchestrator.stop_all_drain_wait_sec）==========
        $this->setStopStage(self::STOP_STAGE_WAIT_DRAIN);
        WlsLogger::info_('[Orchestrator] 阶段2: 等待排水完成');
        $this->sendStopProgress('阶段2/6: 等待排水完成 - 子进程处理完当前请求');
        $stopDrainWait = (float) ($this->context?->getConfig('wls.orchestrator.stop_all_drain_wait_sec', 10.0) ?? 10.0);
        if ($stopDrainWait < 1.0) {
            $stopDrainWait = 1.0;
        }
        if ($stopDrainWait > 300.0) {
            $stopDrainWait = 300.0;
        }
        $this->waitForAllDrained($stopDrainWait, true);

        // ========== 阶段 3：统一终止子进程（并发） ==========
        $this->setStopStage(self::STOP_STAGE_SHUTDOWN);
        WlsLogger::info_('[Orchestrator] 阶段3: 统一终止子进程');
        $this->sendStopProgress('阶段3/6: 统一终止子进程 - 排水后并行结束所有服务');
        $this->broadcastShutdownToAll();
        $this->terminateAllAfterDrain();

        // ========== 阶段 4：等待所有 IPC 连接断开（短超时）==========
        $this->setStopStage(self::STOP_STAGE_WAIT_EXIT);
        WlsLogger::info_('[Orchestrator] 阶段4: 收取退出回执（非阻塞）');
        $this->sendStopProgress('阶段4/6: 收取退出回执（非阻塞）');
        $this->settleShutdownIpcNonBlocking();

        // ========== 阶段 5：校验并强制杀死残留进程 ==========
        $this->setStopStage(self::STOP_STAGE_VERIFY);
        WlsLogger::info_('[Orchestrator] 阶段5: 校验子进程退出状态');
        $this->sendStopProgress('阶段5/6: 校验子进程退出状态');
        $this->verifyAndKillRemainingProcesses();

        // ========== 阶段 6：关闭 IPC 服务器 ==========
        $this->setStopStage(self::STOP_STAGE_CLOSE_IPC);
        WlsLogger::info_('[Orchestrator] 阶段6: 关闭 IPC 服务器');
        $this->sendStopProgress('阶段6/6: 关闭 IPC 服务器');
        
        // 不提前移除 Master PID 索引，避免外部将“索引消失”误判为“进程已退出”。
        // 索引交由 Master 进程最终退出阶段统一清理。
        $this->sendStopProgress('所有子进程已完整退出，Master 即将结束主循环');
        
        // 先设置状态，再关闭 IPC（关闭后无法再发送消息）
        $this->running = false;
        WlsLogger::info_('[Orchestrator] 所有服务已停止');
        $this->setStopStage(self::STOP_STAGE_COMPLETE);
        
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
     * 发送停止进度消息给 CLI 客户端
     */
    private function sendStopProgress(string $message): void
    {
        if ($this->stopProgressClientId !== null && $this->controlServer !== null) {
            $this->controlServer->sendTo($this->stopProgressClientId, ControlMessage::commandResult(true, [], $message));
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
     * 排水完成后并行终止全部子进程，避免逐个等待导致停机过慢。
     */
    private function terminateAllAfterDrain(): void
    {
        $runningPids = [];
        foreach ($this->registry->getAllInstances() as $instance) {
            if ($instance->pid > 0 && $this->isChildProcessRunning($instance->pid)) {
                $runningPids[$instance->pid] = $instance->pid;
            }
        }

        if ($runningPids === []) {
            return;
        }

        $pidList = \array_values($runningPids);
        WlsLogger::info_('[Orchestrator] 批量下发退出信号: ' . \implode(',', $pidList));
        $result = $this->sendStopBatchTerminationSignals($pidList);
        $failed = [];
        foreach ($pidList as $pid) {
            if (!($result[$pid] ?? false)) {
                $failed[] = $pid;
            }
        }
        if (!empty($failed)) {
            WlsLogger::warning_('[Orchestrator] 批量下发退出信号失败: ' . \implode(',', $failed));
            return;
        }

        WlsLogger::info_('[Orchestrator] 批量退出信号已下发，阶段5统一校验退出结果');
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
        $lastHeartbeatAt = 0.0;
        $heartbeatInterval = 5.0;
        
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
    private function settleShutdownIpcNonBlocking(): void
    {
        $this->pollStopFlowIpc(0, 50000);
    }

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
        $lastHeartbeatAt = 0.0;
        $heartbeatInterval = 5.0;

        while (\microtime(true) - $start < $timeout) {
            $this->controlServer?->poll(0, 100000);

            $clientCount = $this->controlServer?->getClientCount() ?? 0;

            if ($clientCount > 0) {
                $now = \microtime(true);
                if (($now - $lastHeartbeatAt) >= $heartbeatInterval) {
                    $elapsed = (int)\round($now - $start);
                    $this->sendStopProgress("Stage4 waiting for exits: {$clientCount} IPC connections remaining ({$elapsed}s/{$timeout}s)");
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

            SchedulerSystem::usleep(50000); // 50ms 轮询间隔
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
        $runningPids = [];
        $pidToInstance = [];

        foreach ($allInstances as $instance) {
            if ($instance->pid <= 0) {
                continue;
            }

            $pidToInstance[$instance->pid] = $instance;
            if ($this->isChildProcessRunning($instance->pid)) {
                $runningPids[] = $instance->pid;
            } elseif ($instance->ipcClientId !== null) {
                $this->closeStopFlowClient($instance->ipcClientId);
            }
        }

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

        $runningPidSet = [];
        foreach ($runningPids as $pid) {
            $runningPidSet[$pid] = true;
        }

        $totalCount = \count($allInstances);
        $exitedCount = 0;
        foreach ($allInstances as $instance) {
            if ($instance->pid <= 0 || !isset($runningPidSet[$instance->pid])) {
                $exitedCount++;
            }
        }
        $runningCount = \count($runningPids);

        if ($runningCount === 0) {
            $this->sendStopProgress("阶段5完成: 全部 {$totalCount} 个子进程已退出");
            WlsLogger::info_("[Orchestrator] 阶段5完成: 全部 {$totalCount} 个子进程已退出");
        } else {
            $this->sendStopProgress("阶段5结果: {$exitedCount}/{$totalCount} 已退出，{$runningCount} 个需强制终止");
            WlsLogger::warning_("[Orchestrator] 阶段5结果: {$exitedCount}/{$totalCount} 已退出，{$runningCount} 个需强制终止");
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
    protected function isChildProcessRunning(int $pid): bool
    {
        return $this->isProcessRunning($pid);
    }

    /**
     * @param int[] $pids
     * @return array<int, bool>
     */
    protected function sendStopBatchTerminationSignals(array $pids): array
    {
        return Processer::batchSendSignal($pids, 15);
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
        return Processer::batchGracefulKill($pids, 0.0, true);
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
        $timeout = (float)($this->context?->getConfig('wls.orchestrator.stop_terminate_timeout_sec', 3.0) ?? 3.0);
        if ($timeout < 0.0) {
            $timeout = 0.0;
        }
        if ($timeout > 30.0) {
            $timeout = 30.0;
        }

        return $timeout;
    }

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
        $processName = $this->getInstanceProcessName($instance);
        $launchId = $this->getInstanceLaunchId($instance);
        $pid = $instance->pid;

        if ($pid > 0 && ($processName !== '' || $launchId !== '')) {
            if (Processer::killManagedProcess(
                $pid,
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

        if ($pid > 0) {
            Processer::killByPid($pid);
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

    private function isManagedInstanceRunning(ServiceInstance $instance): bool
    {
        if ($instance->pid <= 0) {
            return false;
        }

        $processName = $this->getInstanceProcessName($instance);
        $launchId = $this->getInstanceLaunchId($instance);

        if ($processName !== '' || $launchId !== '') {
            return Processer::isManagedProcessRunning(
                $instance->pid,
                $processName !== '' ? $processName : null,
                $launchId,
                $processName !== '' ? '--name=' . $processName : null
            );
        }

        return Processer::isRunningByPid($instance->pid);
    }

    /**
     * 清理实例的 PID 文件
     */
    private function cleanupInstancePidFile(ServiceInstance $instance): void
    {
        $processName = $this->getInstanceProcessName($instance);
        if ($processName !== '') {
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
        $dt = (int) \max(10, \min(7200, (int) \ceil($this->drainTimeout)));
        $this->controlServer->sendTo($instance->ipcClientId, ControlMessage::drain($ports, $dt));
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
                if ($instance->state === ServiceInstance::STATE_DRAINING && $this->isProcessRunning($instance->pid)) {
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

        $reloadsWorker = \in_array('worker', $reloadRoles, true);
        $workerCount = \count($this->registry->getInstancesByRole('worker'));
        $multiWorkerWorkerReload = $reloadsWorker && $workerCount >= 2;
        $maintenanceEnabledForReload = false;
        try {
            if ($multiWorkerWorkerReload && $this->maintenanceMode) {
                $this->disableMaintenanceMode();
                SchedulerSystem::usleep(300000);
                $this->controlServer?->poll(0, 100000);
            }
            if ($reloadsWorker && !$multiWorkerWorkerReload && !$this->maintenanceMode) {
                $enableResult = $this->enableMaintenanceMode();
                if ($enableResult['success']) {
                    $maintenanceEnabledForReload = true;
                    SchedulerSystem::usleep(500000);
                    $this->controlServer?->poll(0, 100000);
                }
            }

            foreach ($reloadRoles as $role) {
                if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                    WlsLogger::warning_('[Orchestrator] reload_all 因帝王抢占已中止');

                    break;
                }
                $this->reloadService((string)$role, $type, $imperialEpochSnap);
            }
        } finally {
            if ($maintenanceEnabledForReload) {
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
                    if ($this->rollingRestartClientId !== null) {
                        $this->controlServer?->sendTo(
                            $this->rollingRestartClientId,
                            ControlMessage::reloadFailed("{$role}#{$id} drain timeout")
                        );
                    }

                    return;
                }

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
                $ready = $this->waitForInstanceReady($role, $id, $this->startupTimeout, $imperialEpochSnap);
                if (!$ready) {
                    if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                        WlsLogger::warning_("[Orchestrator] 滚动重载 {$role}#{$id}: 就绪等待被帝王指令打断");

                        return;
                    }
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
        unset($provider, $type);
        $savedFullRestartOnFailure = $this->fullRestartOnFailure;
        $this->fullRestartOnFailure = false;
        $startTime = \microtime(true);

        try {
            $orderedIds = [];
            foreach ($instances as $inst) {
                $orderedIds[$inst->instanceId] = true;
            }
            $ids = \array_keys($orderedIds);
            \sort($ids, SORT_NUMERIC);
            $batches = $this->getWorkerRestartBatches($ids);
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
                    $batchTotal
                );
                if ($result === 'aborted') {
                    return;
                }
                if ($result === 'failed') {
                    return;
                }
                $done += \count($batch);
            }

            $this->syncDispatcherFullWorkerPoolFromRegistry();

            if ($this->rollingRestartClientId !== null) {
                $elapsedMs = (\microtime(true) - $startTime) * 1000;
                $this->controlServer?->sendTo(
                    $this->rollingRestartClientId,
                    ControlMessage::reloadCompleted($elapsedMs, $done)
                );
            }
            $this->rollingRestartStabilizingUntil = \microtime(true) + $this->stabilizationSec;
        } finally {
            $this->fullRestartOnFailure = $savedFullRestartOnFailure;
            $this->fullRestartRequested = false;
            $this->fullRestartReason = '';
        }
    }

    /**
     * Worker 数 ≥ min_count 时均分为三批；否则逐个一批（每批 1 个）。
     *
     * @param int[] $orderedInstanceIds
     * @return array<int, int[]>
     */
    private function getWorkerRestartBatches(array $orderedInstanceIds): array
    {
        $n = \count($orderedInstanceIds);
        if ($n === 0) {
            return [];
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
        $base = intdiv($n, 3);
        $rem = $n % 3;
        $batches = [];
        $idx = 0;
        for ($b = 0; $b < 3; $b++) {
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
     * 整批：Dispatcher 摘除 → 排水 → 停 → 拉齐 → 批内全部 READY → add_worker。
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
        $this->controlServer?->poll(0, 80000);
        SchedulerSystem::usleep(80000);

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
                $this->sendDrainToInstance($worker);
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
                while ((\microtime(true) - $drainStart) < $this->drainTimeout) {
                    if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                        return 'aborted';
                    }

                    $remainingDraining = 0;
                    foreach ($instancesForDrain as $instance) {
                        if ($instance->state === ServiceInstance::STATE_DRAINING && $this->isProcessRunning($instance->pid)) {
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
                            "{$batchLabel}: draining {$remainingDraining}/" . \count($instancesForDrain) . " workers {$batchList} ({$elapsed}s/{$this->drainTimeout}s)",
                            $completedBefore,
                            $totalWorkers,
                            'draining',
                            $leadWorkerId,
                            $batchMeta
                        );
                        $lastDrainHeartbeatAt = $now;
                    }

                    $this->controlServer?->poll(0, 100000);
                    SchedulerSystem::usleep(100000);
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

        $maxWaitExit = 15.0 + 5.0 * \count($instanceIds);
        $exitDeadline = \microtime(true) + $maxWaitExit;
        $lastExitHeartbeatAt = 0.0;
        while (\microtime(true) < $exitDeadline) {
            if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
                return 'aborted';
            }
            $allGone = true;
            $remainingExit = 0;
            foreach ($instanceIds as $instanceId) {
                $cur = $this->registry->getInstance('worker', $instanceId);
                if ($cur !== null && $cur->ipcClientId !== null) {
                    $allGone = false;
                    $remainingExit++;
                }
            }
            if ($allGone) {
                break;
            }
            $now = \microtime(true);
            if (($now - $lastExitHeartbeatAt) >= 5.0) {
                $elapsed = (int) \round($maxWaitExit - ($exitDeadline - $now));
                $this->sendReloadProgressMessage(
                    "{$batchLabel}: waiting old workers to exit {$remainingExit}/" . \count($instanceIds) . " {$batchList} ({$elapsed}s/{$maxWaitExit}s)",
                    $completedBefore,
                    $totalWorkers,
                    'waiting_exit',
                    $leadWorkerId,
                    $batchMeta
                );
                $lastExitHeartbeatAt = $now;
            }
            $this->controlServer?->poll(0, 100000);
            SchedulerSystem::usleep(100000);
        }

        $cleanupRefs = [];
        foreach ($instanceIds as $instanceId) {
            $worker = $this->registry->getInstance('worker', $instanceId);
            if ($worker !== null) {
                $cleanupRefs[$instanceId] = $worker;
                $this->registry->removeInstance('worker', $instanceId);
            }
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
        $this->startInstanceIdsBatch($workerProvider, $instanceIds, $this->context);

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
            $this->controlServer?->poll(0, 100000);
            SchedulerSystem::usleep(100000);
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
        foreach ($instanceIds as $instanceId) {
            $readyInst = $this->registry->getInstance('worker', $instanceId);
            if ($readyInst !== null && $readyInst->port !== null && $readyInst->port > 0 && !$this->maintenanceMode) {
                $this->notifyDispatcherWorkerReady($readyInst);
            }
        }
        $this->broadcastRoutingPolicyToWorkers();
        if ($this->maintenanceMode) {
            WlsLogger::info_(
                '[Orchestrator] 批次 [' . \implode(',', $instanceIds) . '] READY（维护模式中不加入 Dispatcher，流量仍走维护 Worker）'
            );
        } else {
            WlsLogger::info_(
                '[Orchestrator] 批次 [' . \implode(',', $instanceIds) . '] 已全部 READY 并已通知 Dispatcher 纳入负载池'
            );
        }

        return 'ok';
    }

    private function failWorkerBatchNotify(string $rollingOrReload, string $message): void
    {
        WlsLogger::error_('[Orchestrator] ' . $message);
        if ($rollingOrReload === 'rolling') {
            $this->finishRollingRestart(false, $message);
        } elseif ($this->rollingRestartClientId !== null && $this->controlServer !== null) {
            $this->controlServer->sendTo($this->rollingRestartClientId, ControlMessage::reloadFailed($message));
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
            $instance = $this->registry->getInstance($role, $instanceId);
            if ($instance !== null && $instance->state === ServiceInstance::STATE_READY) {
                return true;
            }

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

            $this->completePendingFiberStatsIfTimeout();

            if ($this->consumePendingStopRequest()) {
                break;
            }

            // 关键：poll 可能在回调中执行 stopAll，需要立即检查退出条件
            if (!$this->running || $this->shuttingDown) {
                break;
            }

            // 故障统一策略：整组重启。仅在没有活跃控制操作时推进，避免与命令流叠加。
            if ($this->fullRestartRequested && $this->activeControlOperation === null) {
                $this->performFullRestart();
                continue;
            }

            if ($this->processNextQueuedControlOperation()) {
                continue;
            }

            // 定期健康检查
            $now = \microtime(true);
            if ($now - $this->lastHealthCheck >= $this->healthCheckInterval) {
                $this->performHealthChecks();
                $this->lastHealthCheck = $now;
                if ($this->shouldYieldPeriodicWork(false)) {
                    continue;
                }
            }

            if ($this->haMode && $now - $this->lastReconcileAt >= $this->reconcileInterval) {
                $this->reconcileDesiredState();
                $this->syncDispatcherFullWorkerPoolFromRegistry();
                $this->lastReconcileAt = $now;
                if ($this->shouldYieldPeriodicWork(false)) {
                    continue;
                }
            } elseif (!$this->haMode
                && $this->reconcileWorkersWithoutHa
                && $now - $this->lastWorkerSlotReconcileAt >= $this->reconcileInterval) {
                $this->reconcileWorkerSlotsWithoutHa();
                $this->syncDispatcherFullWorkerPoolFromRegistry();
                $this->lastWorkerSlotReconcileAt = $now;
                if ($this->shouldYieldPeriodicWork(false)) {
                    continue;
                }
            }

            if ($this->workerLivenessIntervalSec > 0
                && ($now - $this->lastWorkerLivenessAt) >= $this->workerLivenessIntervalSec) {
                $this->lastWorkerLivenessAt = $now;
                $this->runWorkerLivenessAudit();
                if ($this->shouldYieldPeriodicWork(false)) {
                    continue;
                }
            }

            if ($this->haMode && $this->periodicOrphanSweepEnabled && $now - $this->lastSweepAt >= $this->sweeperInterval) {
                $this->cleanupOrphanChildProcesses(aggressiveKill: false);
                $this->lastSweepAt = $now;
            }

            // 处理复活队列
            $this->processResurrectQueue();
            if ($this->shouldYieldPeriodicWork(false)) {
                continue;
            }

            // 稳定期过期
            if ($this->rollingRestartStabilizingUntil > 0 && $now >= $this->rollingRestartStabilizingUntil) {
                $this->rollingRestartStabilizingUntil = 0;
            }

            if ($this->masterSelfAuditIntervalSec > 0.0
                && ($now - $this->lastMasterSelfAuditAt) >= $this->masterSelfAuditIntervalSec) {
                $this->lastMasterSelfAuditAt = $now;
                $this->performMasterSelfAudit();
                if ($this->shouldYieldPeriodicWork(false)) {
                    continue;
                }
            }

            // 短暂休眠避免 CPU 空转
            SchedulerSystem::usleep(50000);
        }

        WlsLogger::info_('[Orchestrator] 退出主循环');
    }

    /**
     * 周期性维护任务的抢占点。
     *
     * 周期任务运行时也要顺手 poll 一次控制面，这样 reload/stop 一类帝王指令不用等整段维护逻辑跑完。
     */
    private function shouldYieldPeriodicWork(bool $pollControlPlane = true): bool
    {
        if (!$this->running || $this->shuttingDown || $this->stopAllInProgress || $this->pendingStopReason !== null) {
            return true;
        }

        if ($pollControlPlane && $this->controlServer !== null) {
            $this->controlServer->poll(0, 0);
        }

        if (!$this->running || $this->shuttingDown || $this->stopAllInProgress || $this->pendingStopReason !== null) {
            return true;
        }

        return $this->activeControlOperation !== null
            || $this->pendingControlOperations !== []
            || $this->fullRestartRequested;
    }

    private function sleepInterruptiblyForPeriodicWork(int $microseconds, int $sliceMicroseconds = 50000): bool
    {
        if ($microseconds <= 0) {
            return !$this->shouldYieldPeriodicWork(true);
        }

        $deadline = \microtime(true) + ($microseconds / 1000000);
        while (\microtime(true) < $deadline) {
            if ($this->shouldYieldPeriodicWork(true)) {
                return false;
            }

            $remainingUsec = (int)\max(1, ($deadline - \microtime(true)) * 1000000);
            SchedulerSystem::usleep(\min($sliceMicroseconds, $remainingUsec));
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
        if ($this->isStopFlowActive()) {
            return;
        }

        $now = \microtime(true);
        $providers = $this->registry->getAllProviders();

        foreach ($providers as $provider) {
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
            }
            $instances = $this->registry->getInstancesByRole($provider->getRole());
            foreach ($instances as $instance) {
                if ($this->shouldYieldPeriodicWork(true)) {
                    return;
                }
                if ($instance->state === ServiceInstance::STATE_FAILED ||
                    $instance->state === ServiceInstance::STATE_STOPPED) {
                    continue;
                }

                $uptime = $now - $instance->startedAt;

                // 启动中的实例：在宽限期内不检查
                if ($instance->state === ServiceInstance::STATE_STARTING) {
                    // 尚未连上 IPC 且子进程已死 → 不等到 register 超时，立即拉起
                    if ($instance->ipcClientId === null
                        && $instance->pid > 0
                        && !$this->isProcessRunning($instance->pid)
                        && \in_array($instance->role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)
                        && ($provider->getResurrectionPriority() > 0)) {
                        WlsLogger::warning_(
                            "[Orchestrator] {$instance->role}#{$instance->instanceId} 未建立 IPC 且 PID 已退出，立即拉起"
                        );
                        $this->scheduleResurrectionWithDelay($instance, 0.0);
                        continue;
                    }
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
                        WlsLogger::warning_(
                            "[Master自检] 子进程健康检查异常: {$instance->role}#{$instance->instanceId} — {$result->message}"
                        );
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
     * 健康检查触发的重启：凡可复活的子进程均单槽拉起；仅无复活优先级或单槽重启耗尽时整组重启。
     */
    private function healthCheckRestartOrEscalate(ServiceInstance $instance, string $reason): void
    {
        if ($this->isStopFlowActive()) {
            return;
        }

        $maxRestarts = 10;
        $provider = $this->registry->getProvider($instance->role);
        $resurrectionPriority = $provider?->getResurrectionPriority() ?? 0;
        if ($resurrectionPriority <= 0) {
            $this->requestFullRestart($reason);

            return;
        }
        if ($instance->restarts >= $maxRestarts) {
            $this->requestFullRestart("{$reason} (max_slot_restarts={$instance->restarts})");

            return;
        }

        if ($instance->pid > 0 && $this->isProcessRunning($instance->pid)) {
            $this->killInstanceProcess($instance);
            if (!$this->sleepInterruptiblyForPeriodicWork(200000)) {
                return;
            }
        }
        $this->scheduleResurrection($instance);
    }

    /**
     * 请求整组重启（防止孤儿进程累积）
     */
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
                ControlMessage::commandResult(false, [], (string)__('IPC 清场：已取消 Fiber 池统计请求'))
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
                    'message' => (string)__('IPC 清场：已由更高优先级控制指令中断'),
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
                'message' => (string)__('IPC 清场：已由更高优先级控制指令中断'),
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
        WlsLogger::info_('[Orchestrator] 子进程停止阶段4: 收取退出回执（非阻塞）');
        $this->settleShutdownIpcNonBlocking();

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
        if ($this->context === null || empty($this->desiredState) || $this->isStopFlowActive()) {
            return;
        }

        foreach ($this->desiredState as $role => $desiredCount) {
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
            }
            $provider = $this->registry->getProvider($role);
            if ($provider === null || !$provider->isEnabled($this->context)) {
                continue;
            }

            // 缺失实例补齐
            for ($slot = 1; $slot <= $desiredCount; $slot++) {
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
        if (empty($this->resurrectQueue) || $this->isStopFlowActive()) {
            return;
        }

        $now = \microtime(true);
        foreach ($this->resurrectQueue as $key => $entry) {
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
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

            // 延迟复活：检查进程是否仍在运行
            if (!empty($entry['delayed']) && !empty($entry['pid'])) {
                if (!$this->terminateStaleProcessBeforeResurrection($oldInstance, (int)$entry['pid'], $port)) {
                    $entry['scheduledAt'] = \microtime(true) + 1.0;
                    $this->resurrectQueue[$key] = $entry;
                    WlsLogger::warning_("[Orchestrator] {$entry['role']}#{$entry['instanceId']} 旧进程/端口尚未释放，1 秒后重试复活");
                    continue;
                }
            }

            if (!$this->ensurePortReleasedForResurrection($port)) {
                $entry['scheduledAt'] = \microtime(true) + 1.0;
                $this->resurrectQueue[$key] = $entry;
                WlsLogger::warning_("[Orchestrator] {$entry['role']}#{$entry['instanceId']} 端口 {$port} 仍被占用，推迟复活");
                continue;
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

            $infraBudget = (int) ($entry['infraRetryBudget'] ?? 0);
            if ($infraBudget > 0 && $newInstance === null) {
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
                        'scheduledAt' => $now + 1.5,
                        'delayed' => false,
                        'pid' => 0,
                        'port' => $port,
                        'infraRetryBudget' => $left,
                    ];

                    continue;
                }
                unset($this->resurrectQueue[$key]);
                WlsLogger::error_("[Orchestrator] {$entry['role']} 本地复活已用尽，触发整组重启");
                $this->requestFullRestart("infra_resurrect_exhausted:{$entry['role']}");

                continue;
            }

            unset($this->resurrectQueue[$key]);
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
        $pid = $instance->pid;
        $processStillRunning = $pid > 0 && $this->isProcessRunning($pid);
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
            'pid' => $pid,
            'port' => $port,
            'infraRetryBudget' => $this->infraServiceResurrectAttempts,
        ];
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
     * 复活前确保旧进程已经真正退出，优先按真实 PID 终止，避免 PID 文件滞后误杀失败。
     */
    private function terminateStaleProcessBeforeResurrection(?ServiceInstance $oldInstance, int $pid, int $port): bool
    {
        if ($pid <= 0) {
            return $this->ensurePortReleasedForResurrection($port);
        }

        if (!$this->isProcessRunning($pid)) {
            return $this->ensurePortReleasedForResurrection($port);
        }

        WlsLogger::warning_("[Orchestrator] 进程 {$pid} 仍在运行（IPC已断开），优先按真实 PID 终止");

        $stopped = Processer::gracefulKill($pid, 1.0, true);
        if (!$stopped) {
            $stopped = Processer::killProcessTreeByPid($pid, true);
        }
        if (!$stopped && $oldInstance !== null) {
            $this->killInstanceProcess($oldInstance);
            $stopped = !$this->isProcessRunning($pid);
        }

        if ($port > 0) {
            Processer::clearPortCache($port);
        }

        return $stopped && !$this->isProcessRunning($pid) && $this->ensurePortReleasedForResurrection($port);
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
            case ControlMessage::TYPE_MAINTENANCE_MODE_ACK:
                $this->handleMaintenanceModeAck($msg, $clientId);
                return;

            case ControlMessage::TYPE_REGISTER:
                $this->handleRegister($msg, $clientId);
                return;

            case ControlMessage::TYPE_READY:
                $this->handleReady($msg, $clientId);
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

            case ControlMessage::TYPE_STATUS_REPORT:
                $this->auditChildStatusReport($msg, $clientId);
                $this->delegateToProvider($msg, $clientId);
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
        $role              = $msg['role'] ?? '';
        $pid               = (int) ($msg['pid'] ?? 0);
        $port              = (int) ($msg['port'] ?? 0);
        $workerId          = (int) ($msg['worker_id'] ?? 0);
        $instanceIdFromMsg = (int) ($msg['instance_id'] ?? 0);
        $epoch             = (int) ($msg['epoch'] ?? 0);
        $launchId          = (string) ($msg['launch_id'] ?? '');
        $processKind       = (string) ($msg['process_kind'] ?? ControlMessage::PROCESS_KIND_FRAMEWORK);
        $moduleCode        = (string) ($msg['module_code'] ?? '');

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
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode)) {
                        return;
                    }
                }
            }
        }

        // 策略1：port 匹配（最可靠）
        if ($port > 0) {
            foreach ($instances as $instance) {
                if ($instance->port === $port) {
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode)) {
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
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode)) {
                        return;
                    }
                }
            }
        }

        // 策略3：PID 匹配（Windows 下可能不准确）
        if ($pid > 0) {
            foreach ($instances as $instance) {
                if ($instance->pid === $pid) {
                    if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode)) {
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
            if ($this->registerInstanceIpc($instance, $clientId, $pid, $workerId, $epoch, $launchId, $processKind, $moduleCode)) {
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
        string $launchId = '',
        string $processKind = ControlMessage::PROCESS_KIND_FRAMEWORK,
        string $moduleCode = ''
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
        // 记录进程归属类型和模块代码
        if ($processKind !== ControlMessage::PROCESS_KIND_FRAMEWORK) {
            $instance->processKind = $processKind;
        }
        if ($moduleCode !== '') {
            $instance->moduleCode = $moduleCode;
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

        $resurrectKey = $instance->getKey();
        if (isset($this->resurrectQueue[$resurrectKey])) {
            unset($this->resurrectQueue[$resurrectKey]);
            WlsLogger::info_("[Orchestrator] {$instance->role}#{$instance->instanceId} 已重新注册，取消待执行复活");
        }

        if (\in_array($instance->role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
            $this->sendRoutingPolicyToWorker($instance);
        }

        $kindInfo = $processKind !== ControlMessage::PROCESS_KIND_FRAMEWORK
            ? ", kind={$processKind}" . ($moduleCode !== '' ? "({$moduleCode})" : '')
            : '';
        WlsLogger::debug_("[Orchestrator] IPC 注册: {$instance->role}#{$instance->instanceId} (pid={$pid}, clientId={$clientId}, port={$instance->port}, epoch={$instance->epoch}, launch_id={$instance->launchId}{$kindInfo})");
        return true;
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
                return;
            }
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
            $this->infraDegraded[$instance->role] = false;
            WlsLogger::info_("[Orchestrator] {$instance->role} 已 READY，解除端点降级并广播 ROUTING_POLICY");
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
        if (!$this->serverReadyNotificationArmed || $this->serverReadyNotified) {
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
        $host = $this->context?->host ?? '127.0.0.1';
        $sslEnabled = $this->context?->sslEnabled ?? false;
        $protocol = $sslEnabled ? 'https' : 'http';

        // 输出醒目的服务器准备就绪通知
        WlsLogger::info_('[Server] ========================================');
        WlsLogger::info_('[Server] ✓ 服务器准备就绪');
        WlsLogger::info_("[Server]   地址: {$protocol}://{$host}:{$mainPort}");
        WlsLogger::info_("[Server]   服务实例: {$totalServices} 个");
        WlsLogger::info_('[Server] ========================================');

        // 前台模式：直接输出访问地址表与代理转发说明（不悬浮，日志正常滚动）
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

            echo "\n" . self::ANSI_GREEN . "  ╔" . \str_repeat('═', $tableWidth) . "╗\n";
            echo "  ║" . \str_pad('   ✓ ' . __('服务器已就绪'), $tableWidth, ' ', STR_PAD_RIGHT) . "║\n";
            echo "  ╠" . \str_repeat('═', $colType) . '╤' . \str_repeat('═', $tableWidth - $colType - 1) . "╣\n";
            $frontendUrl = $baseUrl . '/';
            echo "  ║ " . \str_pad(__('前端'), $colType - 2, ' ') . "│ " . \str_pad($frontendUrl, $colUrl - 1, ' ') . "║\n";
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
            echo "\n";
            echo self::ANSI_GREEN . "  " . __('使用说明：') . self::ANSI_RESET . "\n";
            echo "  • " . __('WLS 默认仅监听 127.0.0.1，仅本机可访问') . "\n";
            echo "  • " . __('外网访问需用 Nginx/Caddy 等反向代理转发到 ') . "{$host}:{$mainPort}" . "\n";
            echo "  • " . __('Nginx 示例：') . "proxy_pass {$protocol}://{$host}:{$mainPort};" . "\n";
            echo "  • " . __('需直连外网时：') . "php bin/w server:start --host 0.0.0.0" . "\n";
            echo "\n";
            if (\function_exists('flush')) {
                @\flush();
            }
        }
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
        if ($this->context === null || !$this->running || $this->isStopFlowActive()) {
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
    }

    /**
     * Worker 存活审计：死 PID 摘 IPC、僵尸注册表复活、零存活紧急拉起
     */
    private function runWorkerLivenessAudit(): void
    {
        if ($this->context === null || $this->controlServer === null || !$this->running || $this->isStopFlowActive()) {
            return;
        }
        $desired = (int) ($this->desiredState['worker'] ?? 0);
        if ($desired <= 0) {
            return;
        }

        $workers = $this->registry->getInstancesByRole('worker');
        foreach ($workers as $inst) {
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
                if ($inst->pid > 0 && !$this->isProcessRunning($inst->pid)) {
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

        $alive = 0;
        if ($this->controlServer !== null) {
            foreach ($this->registry->getInstancesByRole('worker') as $w) {
                if ($this->shouldYieldPeriodicWork(true)) {
                    return;
                }
                if ($w->ipcClientId !== null
                    && $this->controlServer->clientExists($w->ipcClientId)
                    && $w->state === ServiceInstance::STATE_READY
                    && $w->pid > 0
                    && $this->isProcessRunning($w->pid)) {
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
        $prefix = WorkerProvider::PROCESS_NAME_PREFIX . '-' . $this->context->instanceName . '-';
        Processer::killByProcessNamePrefix($prefix);
        if (!$this->sleepInterruptiblyForPeriodicWork(600000)) {
            return;
        }

        foreach (\array_keys($this->resurrectQueue) as $key) {
            if (\str_starts_with((string) $key, 'worker:')) {
                unset($this->resurrectQueue[$key]);
            }
        }

        for ($slot = 1; $slot <= $desired; $slot++) {
            if ($this->shouldYieldPeriodicWork(true)) {
                return;
            }
            $old = $this->registry->getInstance('worker', $slot);
            if ($old !== null) {
                $this->cleanupInstancePidFile($old);
                $this->registry->removeInstance('worker', $slot);
            }
            $newInst = $this->startInstance($provider, $slot, $this->context);
            if ($newInst !== null) {
                $newInst->restarts = 0;
                $this->registry->updateInstance($newInst);
            }
        }
        $this->controlServer?->poll(0, 200000);
        WlsLogger::warning_("[Orchestrator] Worker 紧急拉起已提交（{$desired} 槽位）");
    }

    /**
     * 重置服务器就绪通知状态（用于重启时）
     */
    public function resetServerReadyNotification(): void
    {
        $this->resetServerReadyNotificationState();
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
     * Dispatcher 负载池整体替换（维护切换）
     *
     * @param int[] $ports
     */
    private function notifyDispatcherSetWorkerPool(array $ports): void
    {
        $dispatchers = $this->registry->getInstancesByRole('dispatcher');
        if ($dispatchers === [] || $this->controlServer === null) {
            return;
        }
        $msg = ControlMessage::setWorkerPool($ports);
        foreach ($dispatchers as $dispatcher) {
            if ($dispatcher->state === ServiceInstance::STATE_READY && $dispatcher->ipcClientId !== null) {
                $this->controlServer->sendTo($dispatcher->ipcClientId, $msg);
                WlsLogger::info_(
                    "[Orchestrator] Dispatcher#{$dispatcher->instanceId} SET_WORKER_POOL: " . \implode(',', $ports)
                );
            }
        }
    }

    /**
     * 用 Registry 中当前所有 READY Worker 端口重写 Dispatcher 负载池并广播路由策略。
     *
     * 多 Worker 滚动/热重载（se:rel、server:maintenance rolling）在 maintenanceMode=false 时
     * 仅靠 REMOVE_WORKER + ADD_WORKER 逐条更新；若 IPC 偶发丢包或顺序与 Dispatcher 内部状态漂移，
     * 会出现「Master 侧 Worker 已 READY 但入口仍难访问」。结束后以 SET_WORKER_POOL 全量对齐可恢复。
     */
    private function syncDispatcherFullWorkerPoolFromRegistry(): void
    {
        if ($this->registry->getInstancesByRole('dispatcher') === [] || $this->controlServer === null) {
            $this->lastDispatcherWorkerPoolSignature = '';
            return;
        }

        $ports = [];
        foreach ($this->registry->getInstancesByRole('worker') as $w) {
            if ($w->state === ServiceInstance::STATE_READY && $w->port !== null && $w->port > 0) {
                $ports[] = (int) $w->port;
            }
        }

        if ($ports === []) {
            WlsLogger::warning_('[Orchestrator] syncDispatcherFullWorkerPoolFromRegistry: 无 READY Worker，跳过 SET_WORKER_POOL');
            $this->lastDispatcherWorkerPoolSignature = '';

            return;
        }

        \sort($ports, SORT_NUMERIC);
        $signature = \implode(',', $ports);
        if ($signature === $this->lastDispatcherWorkerPoolSignature) {
            return;
        }

        $this->notifyDispatcherSetWorkerPool($ports);
        $this->controlServer->poll(0, 150000);
        $this->broadcastRoutingPolicyToWorkers();
        $this->lastDispatcherWorkerPoolSignature = $signature;
        WlsLogger::info_('[Orchestrator] Dispatcher 全量 Worker 池已对齐 Registry: ' . $signature);
    }

    private function notifyDispatcherRemoveWorker(?int $port): void
    {
        if ($port === null || $port <= 0) {
            return;
        }
        $dispatchers = $this->registry->getInstancesByRole('dispatcher');
        if (empty($dispatchers) || $this->controlServer === null) {
            return;
        }
        $msg = ControlMessage::removeWorker([$port]);
        foreach ($dispatchers as $dispatcher) {
            if ($dispatcher->ipcClientId !== null) {
                $this->controlServer->sendTo($dispatcher->ipcClientId, $msg);
                WlsLogger::info_(
                    "[Orchestrator] 通知 Dispatcher#{$dispatcher->instanceId} 摘除 Worker 端口 {$port}（滚动重启）"
                );
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
        if ($code !== 0) {
            WlsLogger::error_(
                "[Master自检] 子进程上报非正常退出: {$instance->role}#{$instance->instanceId} code={$code} reason={$reason}"
            );
        }
    }

    /**
     * 处理 command 消息
     */
    private function handleCommand(array $msg, int $clientId): void
    {
        $action = $msg['action'] ?? '';
        if (!\is_string($action) || $action === '') {
            $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(false, [], 'Unknown command'));

            return;
        }

        if ($this->isQueuedControlCommand($action)) {
            if ($action === ControlMessage::ACTION_STOP) {
                $this->clearPendingControlOperations('Control operation cancelled by stop');
                $this->preemptActiveControlOperationForStop();
                $this->requestStop('command', $clientId, true);

                return;
            }

            $operation = $this->queueControlOperation($action, $msg, $clientId);
            $this->sendQueuedControlOperationAck($operation);

            return;
        }

        switch ($action) {
            case ControlMessage::ACTION_STATUS:
                $status = $this->getStatus();
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(true, $status, 'Status retrieved'));
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

            case ControlMessage::ACTION_FIBER_STATS:
                $this->requestFiberPoolStats($clientId);
                break;

            default:
                $this->controlServer?->sendTo($clientId, ControlMessage::commandResult(false, [], 'Unknown command'));
                break;
        }
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
                (string)__('已有 Fiber 统计请求进行中，请稍后再试')
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
            $this->controlServer?->sendTo($replyClientId, ControlMessage::commandResult(true, ['workers' => [], 'total_suspended' => 0], __('无已连接 Worker')));
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
            WlsLogger::error_(
                "[Master自检] 遥测 HTTP 异常 status={$status} host={$host} instance={$instance} latency_ms={$latencyMs}"
            );
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
     * 遥测 HTTP≥500：立即检查 Worker 进程/槽位是否已挂或缺失；仅在不齐备时补齐拉起（业务 5xx 但 Worker 仍存活则不动作）
     */
    private function recoverSlotsAfterTelemetryHttpFailure(string $instance): void
    {
        if ($this->context === null || !$this->running || $this->shuttingDown || $this->masterShutdownIntent) {
            return;
        }
        if ($this->startAllCompletedAt > 0.0 && (\microtime(true) - $this->startAllCompletedAt) < 50.0) {
            return;
        }
        $desired = (int) ($this->desiredState['worker'] ?? 0);
        if ($desired <= 0) {
            return;
        }
        $alive = $this->countRoleSlotsProcessAlive('worker');
        if ($alive >= $desired) {
            $this->logTelemetryAnomalyThrottled(
                "5xx_worker_alive_{$instance}",
                "[Master自检] 遥测 HTTP≥500 但 Worker 进程存活槽位正常（{$alive}/{$desired}），不拉起"
            );

            return;
        }
        $interval = (float) ($this->context->getConfig('wls.orchestrator.telemetry_5xx_worker_recovery_cooldown_sec', 3.0) ?? 3.0);
        if ($interval < 1.0) {
            $interval = 1.0;
        }
        $now = \microtime(true);
        $last = (float) ($this->telemetryWorkerRecoveryAt[$instance] ?? 0.0);
        if ($now - $last < $interval) {
            return;
        }
        $this->telemetryWorkerRecoveryAt[$instance] = $now;
        if (\count($this->telemetryWorkerRecoveryAt) > 64) {
            $this->telemetryWorkerRecoveryAt = \array_slice($this->telemetryWorkerRecoveryAt, -32, 32, true);
        }
        WlsLogger::warning_(
            "[Master自检] 遥测 HTTP≥500 且 Worker 存活槽位不足（存活 {$alive}/期望 {$desired}）— 立即补齐拉起"
        );
        $this->reconcileRoleSlotGaps('worker');
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
            if ($inst->pid > 0 && !$this->isProcessRunning($inst->pid)) {
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
            if ($inst->pid <= 0) {
                continue;
            }
            if (!$this->isProcessRunning($inst->pid)) {
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
                $pidBad = $instance->pid > 0 && !$this->isProcessRunning($instance->pid);
                if ($ipcBad || $pidBad) {
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
     * 启用维护：① ceil(N/3) 拉起维护 Worker；② Dispatcher 切池至仅维护端口；③ 业务 Worker 排空存量连接后 ACK；④ 再标 maintenanceMode。
     *
     * @return array{success: bool, message: string, maintenance_workers: int, worker_ipc_acked?: int}
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

        $maintenanceProvider->enable($nMaint);

        for ($i = 1; $i <= $nMaint; $i++) {
            if ($this->startInstance($maintenanceProvider, $i, $this->context) === null) {
                for ($j = 1; $j < $i; $j++) {
                    $m = $this->registry->getInstance('maintenance', $j);
                    if ($m !== null && $m->ipcClientId !== null) {
                        $this->controlServer?->sendTo($m->ipcClientId, ControlMessage::shutdown());
                    }
                    $this->registry->removeInstance('maintenance', $j);
                }
                $maintenanceProvider->disable();

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

            return [
                'success' => false,
                'message' => 'Maintenance workers have no listen port',
                'maintenance_workers' => 0,
            ];
        }

        if ($hasDispatcher) {
            $this->notifyDispatcherSetWorkerPool($maintPorts);
            $this->controlServer?->poll(0, 150000);
        }

        $requestId = 'wm_on_' . \bin2hex(\random_bytes(8));
        $expected = [];
        if ($this->controlServer !== null) {
            foreach ($normalWorkers as $w) {
                if ($w->ipcClientId !== null
                    && $w->state === Contract\ServiceInstance::STATE_READY
                    && $w->role === ControlMessage::ROLE_WORKER) {
                    $expected[(int) $w->ipcClientId] = true;
                }
            }
            $noDrainPath = !$hasDispatcher;
            foreach (\array_keys($expected) as $cid) {
                $this->controlServer->sendTo($cid, ControlMessage::setMaintenanceMode(true, $requestId, $noDrainPath));
            }
        }

        $ackedClients = [];
        if ($expected !== []) {
            $this->pendingMaintenanceModeAck = [
                'request_id' => $requestId,
                'expected' => $expected,
                'acked' => [],
            ];
            $deadline = \microtime(true) + $drainAckTimeout;
            while (\microtime(true) < $deadline) {
                if (\count($this->pendingMaintenanceModeAck['acked']) >= \count($expected)) {
                    break;
                }
                $this->controlServer?->poll(0, 100000);
            }
            $ackedClients = \array_keys($this->pendingMaintenanceModeAck['acked']);
            $missing = \array_diff_key($expected, $this->pendingMaintenanceModeAck['acked']);
            $this->pendingMaintenanceModeAck = null;

            if ($missing !== []) {
                if ($hasDispatcher && $normalPortsSnapshot !== []) {
                    $this->notifyDispatcherSetWorkerPool($normalPortsSnapshot);
                    $this->controlServer?->poll(0, 150000);
                }
                $revId = 'wm_rev_' . \bin2hex(\random_bytes(4));
                foreach ($ackedClients as $cid) {
                    $this->controlServer?->sendTo((int) $cid, ControlMessage::setMaintenanceMode(false, $revId, true));
                }
                $this->controlServer?->poll(0, 200000);
                $this->stopMaintenanceWorkers();
                $maintenanceProvider->disable();
                $missList = \implode(',', \array_map('strval', \array_keys($missing)));

                return [
                    'success' => false,
                    'message' => (string) __('维护切换超时或存量连接未排空，缺失 ACK: %{1}', [$missList]),
                    'maintenance_workers' => 0,
                ];
            }
        }

        $this->maintenanceMode = true;
        $this->desiredState['maintenance'] = $nMaint;
        $this->persistServicesInfo($this->context);

        return [
            'success' => true,
            'message' => (string) __('维护模式已启用: 业务 Worker 排空确认 %{1} 个, 维护进程 %{2} 个', [\count($ackedClients), $nMaint]),
            'maintenance_workers' => $nMaint,
            'worker_ipc_acked' => \count($ackedClients),
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

        WlsLogger::info_('[Orchestrator] 禁用维护: 恢复业务池 → 关维护页 → 销毁维护进程');

        $restorePorts = [];
        foreach ($this->registry->getInstancesByRole('worker') as $w) {
            if ($w->state === Contract\ServiceInstance::STATE_READY && $w->port !== null && $w->port > 0) {
                $restorePorts[] = (int) $w->port;
            }
        }
        if ($restorePorts !== [] && \count($this->registry->getInstancesByRole('dispatcher')) > 0) {
            $this->notifyDispatcherSetWorkerPool($restorePorts);
            $this->controlServer?->poll(0, 150000);
        }

        $workerClientIds = [];
        foreach ($this->registry->getInstancesByRole('worker') as $w) {
            if ($w->ipcClientId !== null && $w->state === Contract\ServiceInstance::STATE_READY) {
                $workerClientIds[] = (int) $w->ipcClientId;
            }
        }

        $this->sendWorkersMaintenanceIpc(false, $workerClientIds);

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

        $workers = $this->registry->getInstancesByRole('worker');
        $workerCount = \count($workers);

        if ($workerCount === 0) {
            return [
                'success' => false,
                'message' => 'No workers to restart',
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
                SchedulerSystem::usleep(500000);
                $this->controlServer?->poll(0, 100000);
            }
        } elseif ($this->maintenanceMode) {
            $this->disableMaintenanceMode();
            SchedulerSystem::usleep(300000);
            $this->controlServer?->poll(0, 100000);
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
                $this->controlServer?->poll(0, 100000);
                $currentWorker = $this->registry->getInstance('worker', $instanceId);
                if ($currentWorker === null || $currentWorker->ipcClientId === null) {
                    break;
                }
                SchedulerSystem::usleep(100000);
            }

            if ($this->ipcImperialEpoch !== $epochSnap) {
                return;
            }

            $this->registry->removeInstance('worker', $instanceId);
            $newInstance = $this->startInstance($workerProvider, $instanceId, $this->context);
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
            $this->controlServer?->poll(0, 100000);
            $currentWorker = $this->registry->getInstance('worker', $instanceId);
            if ($currentWorker !== null && $currentWorker->state === Contract\ServiceInstance::STATE_READY) {
                return true;
            }
            SchedulerSystem::usleep(100000);
        }

        return false;
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

        if ($success) {
            $this->syncDispatcherFullWorkerPoolFromRegistry();
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

                return true;
            }
            $this->controlServer?->poll(0, 100000);
            SchedulerSystem::usleep(80000);
        }

        return false;
    }

    /**
     * @param int[] $ipcClientIds
     */
    private function sendWorkersMaintenanceIpc(bool $enabled, array $ipcClientIds): void
    {
        if ($this->controlServer === null || $ipcClientIds === []) {
            return;
        }
        $rid = ($enabled ? 'wm_up_' : 'wm_dn_') . \bin2hex(\random_bytes(6));
        foreach ($ipcClientIds as $cid) {
            $this->controlServer->sendTo((int) $cid, ControlMessage::setMaintenanceMode($enabled, $rid));
        }
        $this->controlServer->poll(0, 150000);
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
            'infra' => [
                'session_server_unreachable' => (bool) ($this->infraDegraded[ControlMessage::ROLE_SESSION_SERVER] ?? false),
                'memory_server_unreachable' => (bool) ($this->infraDegraded[ControlMessage::ROLE_MEMORY_SERVER] ?? false),
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
            $this->ipcOnExclusiveHolderDisconnect($clientId);

            return;
        }
        
        $provider = $this->registry->getProvider($instance->role);
        $displayName = $provider?->getDisplayName() ?? $instance->role;

        WlsLogger::warning_("[Orchestrator] IPC 断开: {$instance->role}#{$instance->instanceId} (pid={$instance->pid})");

        // 清除 IPC 客户端 ID（连接已断开）
        $instance->ipcClientId = null;
        $this->registry->updateInstance($instance);

        // 停机态下的断开一律视为预期行为，不再触发自愈/整组重启
        if ($this->isStopFlowActive()) {
            if ($instance->state !== ServiceInstance::STATE_STOPPED) {
                $instance->state = ServiceInstance::STATE_STOPPING;
                $this->registry->updateInstance($instance);
            }
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

        if ($instance->role === ControlMessage::ROLE_SESSION_SERVER
            || $instance->role === ControlMessage::ROLE_MEMORY_SERVER) {
            $this->handleInfraServiceIpcDisconnect($instance);

            return;
        }

        $now = \microtime(true);
        $processStillRunning = $instance->pid > 0 && $this->isProcessRunning($instance->pid);
        $resurrectionPriority = $provider?->getResurrectionPriority() ?? 0;
        $maxSlotRestarts = 10;

        if ($resurrectionPriority > 0 && $instance->restarts >= $maxSlotRestarts) {
            WlsLogger::error_(
                "[Orchestrator] {$instance->role}#{$instance->instanceId} 单槽重启已达上限 ({$instance->restarts})，整组重启"
            );
            $this->requestFullRestart(
                "ipc_disconnect:max_restarts:{$instance->role}#{$instance->instanceId} (restarts={$instance->restarts})"
            );

            return;
        }

        // 凡 Master 管理的、参与复活的子进程：进程已死则立即单槽拉起，不整组重启
        if (!$processStillRunning && $resurrectionPriority > 0) {
            WlsLogger::warning_(
                "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开且进程已退出，单实例复活"
            );
            $this->scheduleResurrectionWithDelay($instance, 0.0);

            return;
        }

        if ($processStillRunning && $resurrectionPriority > 0) {
            $delay = \max(2.0, $this->ipcReconnectGraceSec);
            WlsLogger::warning_(
                "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开但进程仍存活，{$delay}s 后按复活队列处理（可重连或换进程）"
            );
            $this->scheduleResurrectionWithDelay($instance, $delay);

            return;
        }

        $isNewInstance = $instance->getUptime() < $this->stabilizationSec;
        if ($resurrectionPriority > 0
            && $this->rollingRestartStabilizingUntil > 0
            && $now < $this->rollingRestartStabilizingUntil
            && $isNewInstance) {
            WlsLogger::info_(
                "[Orchestrator] 稳定期内新实例 {$instance->role}#{$instance->instanceId} 断开，单实例重启"
            );
            $this->scheduleResurrectionWithDelay($instance, 2.0);

            return;
        }

        if ($resurrectionPriority > 0) {
            WlsLogger::warning_(
                "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开（无有效 PID 判定），单实例复活"
            );
            $this->scheduleResurrectionWithDelay($instance, 1.0);

            return;
        }

        WlsLogger::error_(
            "[Orchestrator] {$instance->role}#{$instance->instanceId} IPC 断开且该角色不参与单槽复活"
        );
        $this->requestFullRestart("ipc_disconnect:no_resurrect:{$instance->role}#{$instance->instanceId}");
    }

    /**
     * 安排延迟复活（IPC 断开但进程可能还在运行）
     */
    private function scheduleResurrectionWithDelay(ServiceInstance $instance, float $delay): void
    {
        if ($this->isStopFlowActive()) {
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
            'port' => $instance->port ?? 0,
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
