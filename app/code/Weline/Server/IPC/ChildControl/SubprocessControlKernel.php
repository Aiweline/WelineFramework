<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\ControlClient;
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

    public function __construct(
        private readonly ChildProcessIdentity $identity,
        private readonly RoleControlHandlerInterface $handler,
        private readonly string $selfTag,
        private readonly bool $verboseLog = false,
        private readonly string $instanceCode = '',
        private readonly mixed $clientFactory = null
    ) {
    }

    /**
     * 从实例文件发现 Master 控制端口（支持轮询等待，带心跳检查）
     *
     * 优先级：
     * 1. 命令行参数 --control-port=PORT （兼容旧启动方式）
     * 2. 从实例 JSON 中查找 control_port 字段（主要机制，支持并发启动）
     * 3. 如果实例 JSON 不存在，循环等待 Master 写入（轮询发现）
     *
     * 自愈窗口：
     * - 实例文件不存在、JSON 未写完整、control_port=0、updated_at 过旧时继续重读真实文件
     * - 最多等待 30 秒；30 秒后仍未发现可用 control_port 才返回 0
     * - 目的：并发启动时不因短暂实例文件竞态导致子进程直接断开并被反复复活
     *
     * @param string $instanceName 实例名称
     * @param int $controlPort 命令行参数传入的端口（0 表示未传入）
     * @param int $maxWaitSec 最多等待秒数（0 = 不等待，立即返回；上限 30 秒）
     * @return int 发现的 Master 控制端口，或 0 if timeout/not found/Master stale
     */
    public static function resolveControlPort(string $instanceName, int $controlPort = 0, int $maxWaitSec = self::CONTROL_PORT_SELF_HEAL_TIMEOUT_SEC): int
    {
        // 优先级 1：命令行参数
        if ($controlPort > 0) {
            return $controlPort;
        }

        $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
        $maxWaitSec = \max(0, \min(self::CONTROL_PORT_SELF_HEAL_TIMEOUT_SEC, $maxWaitSec));
        $deadline = \microtime(true) + $maxWaitSec;
        $masterHeartbeatTimeout = self::CONTROL_PORT_SELF_HEAL_TIMEOUT_SEC;

        // 循环等待实例文件和 control_port 字段出现
        do {
            $now = \time();
            
            if (\is_file($instanceFile)) {
                $instanceData = @\json_decode((string)\file_get_contents($instanceFile), true);
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

    /**
     * 从实例文件发现任意服务端口（Session、Memory 等）
     *
     * 机制：
     * - Session Server 和 Memory Server 在启动后会将自己的端口写入实例 JSON
     * - Worker 启动时调用此方法查询这些端口，然后建立连接池
     * - 支持轮询等待，最多等待 $maxWaitSec 秒
     *
     * @param string $instanceName 实例名称
     * @param string $serviceKey JSON 中的字段名（如 'session_port', 'memory_port'）
     * @param int $maxWaitSec 最多等待秒数（0 = 不等待，立即返回）
     * @return int 发现的服务端口，或 0 if timeout/not found
     */
    public static function resolveServicePort(string $instanceName, string $serviceKey, int $maxWaitSec = 6): int
    {
        $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
        $deadline = \time() + $maxWaitSec;
        $pollCount = 0;

        // 循环等待服务端口出现
        while (\time() < $deadline) {
            if (\is_file($instanceFile)) {
                $instanceData = @\json_decode((string)\file_get_contents($instanceFile), true);
                if (\is_array($instanceData) && isset($instanceData[$serviceKey])) {
                    $port = (int)($instanceData[$serviceKey]);
                    if ($port > 0) {
                        return $port;
                    }
                }
            }

            // 等待 100ms 后重试
            $pollCount++;
            if ($pollCount * 0.1 < $maxWaitSec) {
                \usleep(100000); // 100ms
                continue;
            }

            break;
        }

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
        $instanceInfoGateway = $this->instanceCode !== '' ? new InstanceInfoGateway($this->instanceCode) : null;

        while ($retryAttempt < $maxStartupRetries) {
            $retryAttempt++;
            if ($instanceInfoGateway !== null) {
                $latestControlPort = $instanceInfoGateway->getLatestControlPort($controlPort);
                if ($latestControlPort > 0 && $latestControlPort !== $controlPort) {
                    // 端口更新后，先探测新端口是否已进入 LISTEN，避免切换到“尚未就绪端口”导致持续拒绝连接
                    if (self::isTcpPortReachable('127.0.0.1', $latestControlPort, 180)) {
                        $this->log("检测到 Master control_port 更新: {$controlPort} -> {$latestControlPort}");
                        $controlPort = $latestControlPort;
                    } else {
                        $this->log("检测到 Master control_port 更新候选: {$controlPort} -> {$latestControlPort}，但新端口未就绪，暂继续使用旧端口");
                    }
                }
            }
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
            });
            
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

    private static function isTcpPortReachable(string $host, int $port, int $timeoutMs = 150): bool
    {
        if ($port <= 0) {
            return false;
        }
        $errno = 0;
        $errstr = '';
        $timeoutSec = \max(0.05, $timeoutMs / 1000);
        $socket = @\stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeoutSec
        );
        if (!\is_resource($socket)) {
            return false;
        }
        @\fclose($socket);
        return true;
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
