<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\ControlClient;
use Weline\Server\Log\WlsLogger;

final class SubprocessControlKernel
{
    private ?ControlClient $client = null;

    private const E2E_READY_DELAY_LIMIT_MS = 60000;

    public function __construct(
        private readonly ChildProcessIdentity $identity,
        private readonly RoleControlHandlerInterface $handler,
        private readonly string $selfTag,
        private readonly bool $verboseLog = false,
        private readonly string $instanceCode = ''
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
     * 心跳检查：
     * - 初始检查（前2秒）：宽限，即使 updated_at 有点旧也接受，因为可能是Master刚启动尚未更新
     * - 后续检查（2-6秒）：严格模式，如果 updated_at 超过 10 秒未更新，认为 Master 已死并快速返回 0
     * - 目的：快速检测Master故障，避免Worker长时间等待
     *
     * @param string $instanceName 实例名称
     * @param int $controlPort 命令行参数传入的端口（0 表示未传入）
     * @param int $maxWaitSec 最多等待秒数（0 = 不等待，立即返回）
     * @return int 发现的 Master 控制端口，或 0 if timeout/not found/Master stale
     */
    public static function resolveControlPort(string $instanceName, int $controlPort = 0, int $maxWaitSec = 6): int
    {
        // 优先级 1：命令行参数
        if ($controlPort > 0) {
            return $controlPort;
        }

        $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
        $deadline = \time() + $maxWaitSec;
        $pollCount = 0;
        $graceStartTime = \time();
        $gracePeriodSec = 2;  // 前2秒为宽限期，接受任何 control_port（即使 updated_at 旧也可以）
        $masterHeartbeatTimeout = 10;  // 2秒后如果 updated_at > 10秒未更新，判定 Master 已死

        // 循环等待实例文件和 control_port 字段出现
        while (\time() < $deadline) {
            $now = \time();
            $isInGracePeriod = ($now - $graceStartTime) <= $gracePeriodSec;
            
            if (\is_file($instanceFile)) {
                $instanceData = @\json_decode((string)\file_get_contents($instanceFile), true);
                if (\is_array($instanceData) && isset($instanceData['control_port'])) {
                    $port = (int)($instanceData['control_port']);
                    
                    // 心跳检查：检查 Master 是否仍然活着
                    $updatedAt = (int)($instanceData['updated_at'] ?? 0);
                    $heartbeatAge = $now - $updatedAt;
                    
                    if ($port > 0) {
                        if ($isInGracePeriod) {
                            // 宽限期内：只要 control_port > 0 就返回（Master可能刚启动）
                            return $port;
                        } elseif ($heartbeatAge <= $masterHeartbeatTimeout) {
                            // 宽限期外：心跳正常，返回端口
                            return $port;
                        } else {
                            // 宽限期外：心跳超时，Master 已死亡
                            // 立即返回 0 而不是继续轮询（快速失败）
                            return 0;
                        }
                    }
                }
            }

            // 等待 100ms 后重试（支持轮询发现）
            $pollCount++;
            if ($pollCount * 0.1 < $maxWaitSec) {
                \usleep(100000); // 100ms
                continue;
            }

            break;
        }

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
        if ($controlPort <= 0) {
            return false;
        }

        // 启动时重试连接 Master（支持并发启动，自动等待 Master 就绪）
        // 快速失败：减少重试次数和延迟，加快启动速度
        $maxStartupRetries = \intval(\getenv('WLS_STARTUP_CONNECT_RETRIES') ?: 10);  // 默认 10 次（之前 60 次）
        $retryDelayMs = \intval(\getenv('WLS_STARTUP_CONNECT_RETRY_DELAY_MS') ?: 50);  // 默认 50ms（之前 100ms）
        $retryAttempt = 0;
        $lastError = '';

        while ($retryAttempt < $maxStartupRetries) {
            $retryAttempt++;
            $client = new ControlClient();
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
                $this->instanceCode
            );
            $client->markReadyState(true);
            $kernel = $this;
            $client->onMessage(static function (array $msg, ControlClient $client) use ($kernel): void {
                $kernel->handler->onMessage($msg, $kernel);
            });
            $client->onDisconnect(static function (bool $receivedShutdown, ControlClient $client) use ($kernel): void {
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
                $this->instanceCode
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
                $this->identity->launchId
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

    public function getClient(): ?ControlClient
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
            $this->identity->workerId
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
        $this->client->sendDrainingComplete($this->identity->workerId, $this->identity->port);
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
}

