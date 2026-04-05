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

    public static function resolveControlPort(string $instanceName, int $controlPort): int
    {
        if ($controlPort > 0) {
            return $controlPort;
        }
        $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
        if (!\is_file($instanceFile)) {
            return 0;
        }
        $instanceData = @\json_decode((string)\file_get_contents($instanceFile), true);
        if (!\is_array($instanceData)) {
            return 0;
        }
        return (int)($instanceData['control_port'] ?? 0);
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

        $client = new ControlClient();
        $client->setSelfTag($this->selfTag);
        $client->setVerboseLog($this->verboseLog);
        if (!$client->connect('127.0.0.1', $controlPort)) {
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
            $client->close();
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
            $client->close();
            return false;
        }

        $kernel = $this;
        $client->onMessage(static function (array $msg, ControlClient $client) use ($kernel): void {
            $kernel->handler->onMessage($msg, $kernel);
        });
        $client->onDisconnect(static function (bool $receivedShutdown, ControlClient $client) use ($kernel): void {
            $kernel->handler->onDisconnect($receivedShutdown, $kernel);
        });

        $this->client = $client;
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

