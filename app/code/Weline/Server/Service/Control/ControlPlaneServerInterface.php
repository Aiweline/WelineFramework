<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

interface ControlPlaneServerInterface
{
    public function setWindowsNativeSocketBridgeEnabled(bool $enabled): void;

    public function isUsingWindowsNativeSocketBridge(): bool;

    public function start(string $host, int $port): bool;

    public function getPort(): int;

    public function onMessage(callable $handler): void;

    public function onDisconnect(callable $handler): void;

    public function setLogToConsole(bool $enable): void;

    public function setExpectedInstanceCode(string $instanceCode): void;

    public function setExpectedControlToken(string $controlToken): void;

    public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int;

    public function sendTo(int $clientId, string $message): bool;

    public function sendToRole(string $role, string $message): void;

    /**
     * @return int[] IPC client IDs that accepted the outbound message.
     */
    public function sendToRoleAndCollectTargets(string $role, string $message): array;

    public function clientExists(int $clientId): bool;

    public function closeClient(int $clientId): void;

    public function countServiceClients(?int $excludeClientId = null): int;

    public function flushPendingWrites(float $maxSeconds = 2.0): void;

    public function close(): void;
}
