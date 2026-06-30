<?php
declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

interface ChildControlClientInterface
{
    public function connect(string $host, int $port): bool;

    public function isConnected(): bool;

    public function getSocket();

    public function hasPendingWrites(): bool;

    public function hasReceivedShutdown(): bool;

    public function isReadyStateConfirmed(): bool;

    public function onMessage(callable $handler): void;

    public function onDisconnect(callable $handler): void;

    public function setVerboseLog(bool $verbose): void;

    public function setSelfTag(string $tag): void;

    public function register(
        string $role,
        int $pid,
        int $port = 0,
        int $workerId = 0,
        int $epoch = 0,
        string $launchId = '',
        string $processKind = 'framework',
        string $moduleCode = '',
        string $instanceCode = '',
        string $msgId = ''
    ): bool;

    public function rememberRegistration(
        string $role,
        int $pid,
        int $port = 0,
        int $workerId = 0,
        int $epoch = 0,
        string $launchId = '',
        string $processKind = 'framework',
        string $moduleCode = '',
        string $instanceCode = '',
        string $msgId = ''
    ): void;

    public function markReadyState(bool $isReady = true): void;

    public function sendReady(
        string $role = '',
        int $workerId = 0,
        int $port = 0,
        int $epoch = 0,
        string $launchId = '',
        string $msgId = ''
    ): bool;

    public function sendWorkerLoopStarted(int $workerId, int $port, int $pid): bool;

    public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = '', string $reason = ''): bool;

    public function sendStatusReport(int $connections, int $memory, int $requests): bool;

    public function sendLogLine(string $line, string $level, string $processTag): bool;

    public function send(string $message, bool $disconnectOnWriteOverflow = true): bool;

    public function flushPendingWrites(float $timeBudgetSec = 0.0): bool;

    public function handleReadable(): array;

    public function handleWritable(): bool;

    public function tryReconnect(): bool;

    public function close(): void;

    /**
     * 返回当前子进程的 Master 自愈优先级（ControlMessage::RESURRECTION_*）
     *
     * 由 Master ACK 下发给 Client，驱动 MasterResurrectionCoordinator；
     * Supervisor 等不参与子进程自愈的 Client 实现应返回 0。
     */
    public function getResurrectionPriority(): int;
}
