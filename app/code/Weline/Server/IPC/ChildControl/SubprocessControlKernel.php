<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\ControlClient;
use Weline\Server\IPC\ResurrectionCoordinatorInterface;
use Weline\Server\IPC\MasterResurrectionCoordinator;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Supervisor\Client\SupervisorChildClient;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;

final class SubprocessControlKernel
{
    private ?ChildControlClientInterface $client = null;

    private const E2E_READY_DELAY_LIMIT_MS = 60000;
    private const CONTROL_PORT_SELF_HEAL_TIMEOUT_SEC = 30;
    private const CONTROL_PORT_POLL_USEC = 100000;
    private const CONTROL_CONNECT_SELF_HEAL_TIMEOUT_MS = 30000;

    /** 最近一次 connect 成功使用的 control port，供 onDisconnect 自愈用 */
    private int $lastControlPort = 0;

    /** Master 自愈协调器；null 时保持向后兼容（不自愈） */
    private ?ResurrectionCoordinatorInterface $resurrectionCoordinator;

    public function __construct(
        private readonly ChildProcessIdentity $identity,
        private readonly RoleControlHandlerInterface $handler,
        private readonly string $selfTag,
        private readonly bool $verboseLog = false,
        private readonly string $instanceCode = '',
        private readonly mixed $clientFactory = null,
        ?ResurrectionCoordinatorInterface $resurrectionCoordinator = null
    ) {
        // 默认注入真实协调器；生产入口（bin/worker.php 等）无需改代码。
        // 显式传 null 无法绕过，因为我们把 "禁用自愈" 的决策权下放给 MasterResurrector::isChildResurrectionEnabled()
        //（它读 env.php 的 allow_child_resurrection 开关）。
        // 测试若要禁用可显式注入一个 no-op 协调器。
        $this->resurrectionCoordinator = $resurrectionCoordinator ?? new MasterResurrectionCoordinator();
    }

    /**
     * 注入 / 替换 Master 自愈协调器（用于启动期接线与单测）
     */
    public function setResurrectionCoordinator(?ResurrectionCoordinatorInterface $coordinator): void
    {
        $this->resurrectionCoordinator = $coordinator;
    }

    /**
     * Resolve the Master control endpoint during process bootstrap.
     *
     * The endpoint file is a Master-written bootstrap pointer only. Child
     * processes must not use instance JSON as runtime topology or recovery
     * consensus after this initial lookup.
     */
    public static function resolveControlPort(string $instanceName, int $controlPort = 0, int $maxWaitSec = self::CONTROL_PORT_SELF_HEAL_TIMEOUT_SEC): int
    {
        // 优先级 1：命令行参数
        if ($controlPort > 0) {
            return $controlPort;
        }

        $endpointFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
        $maxWaitSec = \max(0, \min(self::CONTROL_PORT_SELF_HEAL_TIMEOUT_SEC, $maxWaitSec));
        $deadline = \microtime(true) + $maxWaitSec;
        $masterHeartbeatTimeout = self::CONTROL_PORT_SELF_HEAL_TIMEOUT_SEC;

        // Wait for the Master endpoint pointer to appear during bootstrap.
        do {
            $now = \time();
            
            if (\is_file($endpointFile)) {
                $instanceData = @\json_decode((string)\file_get_contents($endpointFile), true);
                if (\is_array($instanceData) && isset($instanceData['control_port'])) {
                    $port = (int)($instanceData['control_port']);
                    $updatedAt = (int)($instanceData['updated_at'] ?? 0);
                    
                    if ($port > 0 && $updatedAt > 0 && ($now - $updatedAt) <= $masterHeartbeatTimeout) {
                        return $port;
                    }
                }
            }

            if ($maxWaitSec <= 0) {
                break;
            }

            \usleep(self::CONTROL_PORT_POLL_USEC);
        } while (\microtime(true) < $deadline);

        return 0;
    }

    public static function resolveReadyDelayMilliseconds(string $role): int
    {
        $envName = match ($role) {
            ControlMessage::ROLE_WORKER => 'WLS_E2E_WORKER_READY_DELAY_MS',
            ControlMessage::ROLE_MAINTENANCE => 'WLS_E2E_MAINTENANCE_READY_DELAY_MS',
            default => '',
        };

        if ($envName === '') {
            return 0;
        }

        $raw = \getenv($envName);
        if ($raw === false || $raw === '') {
            return 0;
        }

        $delayMs = (int) $raw;
        if ($delayMs <= 0) {
            return 0;
        }

        return \min($delayMs, self::E2E_READY_DELAY_LIMIT_MS);
    }

    public function connectAndRegister(int $controlPort): bool
    {
        if ($controlPort <= 0 && !$this->shouldUseSupervisorTransport() && !\is_callable($this->clientFactory)) {
            return false;
        }

        // 启动时重试连接 Master（支持并发启动，自动等待 Master 就绪）
        $retryDelayMs = \max(50, \intval(\getenv('WLS_STARTUP_CONNECT_RETRY_DELAY_MS') ?: 250));
        $maxRetriesBySelfHealWindow = \max(1, (int) \ceil(self::CONTROL_CONNECT_SELF_HEAL_TIMEOUT_MS / $retryDelayMs));
        $maxStartupRetries = \intval(\getenv('WLS_STARTUP_CONNECT_RETRIES') ?: $maxRetriesBySelfHealWindow);
        $maxStartupRetries = \max(1, \min($maxStartupRetries, $maxRetriesBySelfHealWindow));
        $retryAttempt = 0;
        $lastError = '';

        while ($retryAttempt < $maxStartupRetries) {
            $retryAttempt++;
            $client = $this->createClient();
            $this->client = $client;
            $client->setSelfTag($this->selfTag);
            $client->setVerboseLog($this->verboseLog);
            $client->rememberRegistration(
                $this->identity->role,
                $this->identity->pid,
                $this->identity->port,
                $this->identity->workerId,
                $this->identity->epoch,
                $this->identity->launchId,
                $this->identity->processKind,
                $this->identity->moduleCode,
                $this->instanceCode,
                $this->identity->launchId !== '' ? $this->identity->launchId : ''
            );
            $client->markReadyState(true);
            $kernel = $this;
            $client->onMessage(static function (array $msg, ChildControlClientInterface $client) use ($kernel): void {
                $kernel->handler->onMessage($msg, $kernel);
            });
            $client->onDisconnect(static function (bool $receivedShutdown, ChildControlClientInterface $client) use ($kernel): void {
                $kernel->handler->onDisconnect($receivedShutdown, $kernel);
                $kernel->maybeTriggerMasterSelfHeal($receivedShutdown, $client);
            });

            $this->lastControlPort = $controlPort;
            if (!$client->connect('127.0.0.1', $controlPort)) {
                $lastError = "[连接失败]";
                if ($retryAttempt < $maxStartupRetries) {
                    $this->log("连接 Master 失败 (第 {$retryAttempt}/{$maxStartupRetries} 次)，{$retryDelayMs}ms 后重试...");
                    \usleep($retryDelayMs * 1000);
                    continue;
                }
                return false;
            }

            $registered = $client->register(
                $this->identity->role,
                $this->identity->pid,
                $this->identity->port,
                $this->identity->workerId,
                $this->identity->epoch,
                $this->identity->launchId,
                $this->identity->processKind,
                $this->identity->moduleCode,
                $this->instanceCode,
                $this->identity->launchId !== '' ? $this->identity->launchId : ''
            );
            if (!$registered) {
                $lastError = "[注册失败]";
                $client->close();
                if ($retryAttempt < $maxStartupRetries) {
                    $this->log("向 Master 注册失败 (第 {$retryAttempt}/{$maxStartupRetries} 次)，{$retryDelayMs}ms 后重试...");
                    \usleep($retryDelayMs * 1000);
                    continue;
                }
                return false;
            }
            
            if (!$client->flushPendingWrites(2.0)) {
                $lastError = "[刷新缓冲失败]";
                $client->close();
                if ($retryAttempt < $maxStartupRetries) {
                    $this->log("向 Master 发送消息失败 (第 {$retryAttempt}/{$maxStartupRetries} 次)，{$retryDelayMs}ms 后重试...");
                    \usleep($retryDelayMs * 1000);
                    continue;
                }
                return false;
            }

            $this->applyE2EReadyDelayIfNeeded();

            $ready = $client->sendReady(
                $this->identity->role,
                $this->identity->workerId,
                $this->identity->port,
                $this->identity->epoch,
                $this->identity->launchId,
                $this->identity->launchId !== '' ? $this->identity->launchId : ''
            );
            if (!$ready) {
                $lastError = "[Ready 消息失败]";
                $client->close();
                if ($retryAttempt < $maxStartupRetries) {
                    $this->log("发送 Ready 消息失败 (第 {$retryAttempt}/{$maxStartupRetries} 次)，{$retryDelayMs}ms 后重试...");
                    \usleep($retryDelayMs * 1000);
                    continue;
                }
                return false;
            }
            
            if (!$client->flushPendingWrites(2.0)) {
                $lastError = "[Ready 刷新失败]";
                $client->close();
                if ($retryAttempt < $maxStartupRetries) {
                    $this->log("发送 Ready 消息后刷新失败 (第 {$retryAttempt}/{$maxStartupRetries} 次)，{$retryDelayMs}ms 后重试...");
                    \usleep($retryDelayMs * 1000);
                    continue;
                }
                return false;
            }
            
            // 全部成功！
            $this->log("成功连接到 Master 并完成注册 (第 {$retryAttempt} 次尝试)");
            return true;
        }

        $this->log("启动时重连 Master 失败，次数上限已达 {$maxStartupRetries}，{$lastError}");
        return false;
    }

    private function applyE2EReadyDelayIfNeeded(): void
    {
        $delayMs = self::resolveReadyDelayMilliseconds($this->identity->role);
        if ($delayMs <= 0) {
            return;
        }

        $this->log("E2E startup hook: delaying READY by {$delayMs}ms");
        \usleep($delayMs * 1000);
    }

    public function tick(): void
    {
        if ($this->client && $this->client->isConnected()) {
            $this->client->handleReadable();
        }
    }

    public function flushWrites(): void
    {
        if ($this->client && $this->client->isConnected()) {
            $this->client->handleWritable();
        }
    }

    public function hasPendingWrites(): bool
    {
        return $this->client !== null && $this->client->hasPendingWrites();
    }

    public function reconnect(): bool
    {
        if ($this->client === null) {
            return false;
        }
        return $this->client->tryReconnect();
    }

    public function isConnected(): bool
    {
        return $this->client !== null && $this->client->isConnected();
    }

    public function hasReceivedShutdown(): bool
    {
        return $this->client !== null && $this->client->hasReceivedShutdown();
    }

    public function getSocket()
    {
        return $this->client?->getSocket();
    }

    public function getClient(): ?ChildControlClientInterface
    {
        return $this->client;
    }

    /**
     * 由 ControlClient 断开回调触发；在 Handler 业务处理后评估是否需要自愈 Master。
     *
     * 职责：
     *   - 收到 shutdown（显式停止）不自愈
     *   - 本子进程优先级 = 0 不自愈（已在 Coordinator 内二次判断）
     *   - 其它由 coordinator 统一走 `should -> confirm grace -> attempt`
     */
    public function maybeTriggerMasterSelfHeal(bool $receivedShutdown, ChildControlClientInterface $client): void
    {
        if ($this->resurrectionCoordinator === null) {
            return;
        }
        if ($receivedShutdown) {
            return;
        }

        $priority = 0;
        try {
            $priority = $client->getResurrectionPriority();
        } catch (\Throwable $e) {
            WlsLogger::debug_('[Kernel] 读取复活优先级失败: ' . $e->getMessage());
        }

        if ($priority <= 0 || $this->instanceCode === '' || $this->lastControlPort <= 0) {
            return;
        }

        try {
            $this->resurrectionCoordinator->handleDisconnect(
                $priority,
                $this->instanceCode,
                $this->lastControlPort,
                $receivedShutdown
            );
        } catch (\Throwable $e) {
            WlsLogger::error_('[Kernel] 触发 Master 自愈失败: ' . $e->getMessage());
        }
    }

    public function sendExited(): void
    {
        if ($this->client === null || !$this->client->isConnected()) {
            return;
        }

        $this->client->send(\Weline\Server\IPC\ControlMessage::exited(
            $this->identity->role,
            $this->identity->pid,
            $this->identity->port,
            $this->identity->workerId,
            $this->identity->launchId !== '' ? $this->identity->launchId : ''
        ));
    }

    public function sendExitReason(string $reason, int $code = 0): void
    {
        if ($this->client === null || !$this->client->isConnected()) {
            return;
        }
        $this->client->send(\Weline\Server\IPC\ControlMessage::exitReason($reason, $code));
    }

    public function sendDrainingComplete(): void
    {
        if ($this->client === null || !$this->client->isConnected()) {
            return;
        }
        $this->client->sendDrainingComplete($this->identity->workerId, $this->identity->port, $this->identity->launchId !== '' ? $this->identity->launchId : '');
    }

    public function close(): void
    {
        if ($this->client !== null) {
            $this->client->close();
        }
    }

    public function log(string $message): void
    {
        WlsLogger::info_("[{$this->selfTag}] {$message}");
    }

    private function shouldUseSupervisorTransport(): bool
    {
        $flag = \getenv('WLS_SUPERVISOR_ENABLED');
        if ($flag !== false && $flag !== '') {
            return \in_array(\strtolower((string) $flag), ['1', 'true', 'yes', 'on'], true);
        }

        $instanceCode = $this->instanceCode !== '' ? $this->instanceCode : 'default';
        $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceCode . '.json';
        if (!\is_file($instanceFile)) {
            return false;
        }

        $raw = @\file_get_contents($instanceFile);
        if (!\is_string($raw) || $raw === '') {
            return false;
        }

        $data = @\json_decode($raw, true);
        if (!\is_array($data)) {
            return false;
        }

        if (isset($data['supervisor_enabled'])) {
            return (bool) $data['supervisor_enabled'];
        }

        return (string)($data['control_plane_mode'] ?? '') === 'hybrid';
    }

    private function createClient(): ChildControlClientInterface
    {
        if (\is_callable($this->clientFactory)) {
            /** @var ChildControlClientInterface $client */
            $client = ($this->clientFactory)($this);

            return $client;
        }

        if ($this->shouldUseSupervisorTransport()) {
            $channelId = (string) (\getenv('WLS_SUPERVISOR_CHANNEL') ?: $this->resolveSupervisorChannelId());
            $basePath = (string) (\getenv('WLS_SUPERVISOR_BASE_PATH') ?: BP);

            return new SupervisorChildClient(
                instanceName: $this->instanceCode !== '' ? $this->instanceCode : 'default',
                channelId: $channelId,
                endpointResolver: new ControlEndpointResolver($basePath),
            );
        }

        return new ControlClient();
    }

    private function resolveSupervisorChannelId(): string
    {
        $instanceCode = $this->instanceCode !== '' ? $this->instanceCode : 'default';
        $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceCode . '.json';
        if (\is_file($instanceFile)) {
            $raw = @\file_get_contents($instanceFile);
            $data = \is_string($raw) ? @\json_decode($raw, true) : null;
            if (\is_array($data)) {
                $channelId = (string)($data['supervisor_channel'] ?? '');
                if ($channelId !== '') {
                    return $channelId;
                }
            }
        }

        return 'channel-' . $instanceCode;
    }
}
