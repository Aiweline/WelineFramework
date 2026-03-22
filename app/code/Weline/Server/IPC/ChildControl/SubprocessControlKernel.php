<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Server\IPC\ControlClient;
use Weline\Server\Log\WlsLogger;

final class SubprocessControlKernel
{
    private ?ControlClient $client = null;

    public function __construct(
        private readonly ChildProcessIdentity $identity,
        private readonly RoleControlHandlerInterface $handler,
        private readonly string $selfTag,
        private readonly bool $verboseLog = false
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
            $this->identity->moduleCode
        );
        if (!$registered) {
            $client->close();
            return false;
        }

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

    public function tick(): void
    {
        if ($this->client && $this->client->isConnected()) {
            $this->client->handleReadable();
        }
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

