<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;
use Weline\Server\Supervisor\SupervisorRuntime;
use Weline\Server\Supervisor\SupervisorServer;
use Weline\Server\Supervisor\SupervisorSession;

final class HybridControlPlaneServer implements ControlPlaneServerInterface
{
    private const SUPERVISOR_CLIENT_ID_BASE = 1000000;

    private ?string $expectedInstanceCode = null;
    private string $expectedControlToken = '';
    private mixed $messageHandler = null;
    private mixed $disconnectHandler = null;
    private bool $windowsNativeSocketBridgeEnabled = false;
    private bool $logToConsole = true;
    private bool $started = false;

    public function __construct(
        private readonly MasterControlServer $controlServer,
        private readonly ControlEndpointResolver $endpointResolver,
        private readonly bool $supervisorEnabled = false,
        private readonly ?string $channelId = null,
        private readonly ?string $basePath = null,
        private ?SupervisorRuntime $supervisorRuntime = null,
        private ?SupervisorServer $supervisorServer = null,
    ) {
    }

    public function setWindowsNativeSocketBridgeEnabled(bool $enabled): void
    {
        $this->windowsNativeSocketBridgeEnabled = $enabled;
        $this->controlServer->setWindowsNativeSocketBridgeEnabled($enabled);
    }

    public function isUsingWindowsNativeSocketBridge(): bool
    {
        return $this->controlServer->isUsingWindowsNativeSocketBridge();
    }

    public function start(string $host, int $port): bool
    {
        if (!$this->controlServer->start($host, $port)) {
            return false;
        }

        $this->controlServer->setLogToConsole($this->logToConsole);
        if ($this->expectedInstanceCode !== null) {
            $this->controlServer->setExpectedInstanceCode($this->expectedInstanceCode);
        }
        $this->controlServer->setExpectedControlToken($this->expectedControlToken);
        $this->controlServer->onMessage(function (array $msg, int $clientId, MasterControlServer $server): void {
            if ($this->messageHandler !== null) {
                ($this->messageHandler)($msg, $clientId, $this);
            }
        });
        $this->controlServer->onDisconnect(function (int $clientId, array $clientInfo, MasterControlServer $server): void {
            if ($this->disconnectHandler !== null) {
                ($this->disconnectHandler)($clientId, $clientInfo, $this);
            }
        });

        if ($this->supervisorEnabled && $this->expectedInstanceCode !== null) {
            $channelId = $this->channelId !== null && $this->channelId !== ''
                ? $this->channelId
                : 'channel-' . $this->expectedInstanceCode;
            $this->supervisorRuntime ??= new SupervisorRuntime(
                instanceName: $this->expectedInstanceCode,
                channelId: $channelId,
                endpointResolver: $this->endpointResolver,
            );
            $this->supervisorServer ??= new SupervisorServer($this->supervisorRuntime);
            $this->supervisorServer->start();
        }

        $this->started = true;

        return true;
    }

    public function getPort(): int
    {
        return $this->controlServer->getPort();
    }

    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
        if ($this->started) {
            $this->controlServer->onMessage(function (array $msg, int $clientId, MasterControlServer $server): void {
                if ($this->messageHandler !== null) {
                    ($this->messageHandler)($msg, $clientId, $this);
                }
            });
        }
    }

    public function onDisconnect(callable $handler): void
    {
        $this->disconnectHandler = $handler;
        if ($this->started) {
            $this->controlServer->onDisconnect(function (int $clientId, array $clientInfo, MasterControlServer $server): void {
                if ($this->disconnectHandler !== null) {
                    ($this->disconnectHandler)($clientId, $clientInfo, $this);
                }
            });
        }
    }

    public function setLogToConsole(bool $enable): void
    {
        $this->logToConsole = $enable;
        $this->controlServer->setLogToConsole($enable);
    }

    public function setExpectedInstanceCode(string $instanceCode): void
    {
        $this->expectedInstanceCode = $instanceCode;
        $this->controlServer->setExpectedInstanceCode($instanceCode);
    }

    public function setExpectedControlToken(string $controlToken): void
    {
        $this->expectedControlToken = \trim($controlToken);
        $this->controlServer->setExpectedControlToken($this->expectedControlToken);
    }

    public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
    {
        $events = $this->controlServer->poll($timeoutSec, $timeoutUsec);
        if ($this->supervisorServer !== null) {
            $events += $this->pollSupervisor(0, 0);
        }

        return $events;
    }

    public function sendTo(int $clientId, string $message): bool
    {
        if ($this->isSupervisorClientId($clientId)) {
            return $this->sendToSupervisor($clientId, $message);
        }

        return $this->controlServer->sendTo($clientId, $message);
    }

    public function sendToRole(string $role, string $message): void
    {
        $this->controlServer->sendToRole($role, $message);
        if ($this->supervisorServer === null) {
            return;
        }

        foreach ($this->supervisorServer->sessions() as $session) {
            if ($session->role !== $role) {
                continue;
            }
            $supervisorClientId = $this->toSupervisorClientId($session->id);
            $this->sendToSupervisor($supervisorClientId, $message);
        }
    }

    /**
     * @return int[] IPC client IDs that accepted the outbound message.
     */
    public function sendToRoleAndCollectTargets(string $role, string $message): array
    {
        $targets = $this->controlServer->sendToRoleAndCollectTargets($role, $message);
        if ($this->supervisorServer === null) {
            return $targets;
        }

        foreach ($this->supervisorServer->sessions() as $session) {
            if ($session->role !== $role) {
                continue;
            }
            $supervisorClientId = $this->toSupervisorClientId($session->id);
            if ($this->sendToSupervisor($supervisorClientId, $message)) {
                $targets[] = $supervisorClientId;
            }
        }

        return $targets;
    }

    public function clientExists(int $clientId): bool
    {
        if ($this->isSupervisorClientId($clientId)) {
            return $this->supervisorServer?->hasSession($this->fromSupervisorClientId($clientId)) ?? false;
        }

        return $this->controlServer->clientExists($clientId);
    }

    public function closeClient(int $clientId): void
    {
        if ($this->isSupervisorClientId($clientId)) {
            $sessionId = $this->fromSupervisorClientId($clientId);
            $session = $this->supervisorServer?->sessions()[$sessionId] ?? null;
            $this->supervisorServer?->closeSessionById($sessionId);
            if ($session instanceof SupervisorSession && $this->disconnectHandler !== null) {
                ($this->disconnectHandler)($clientId, $this->buildSupervisorClientInfo($session), $this);
            }
            return;
        }

        $this->controlServer->closeClient($clientId);
    }

    public function countServiceClients(?int $excludeClientId = null): int
    {
        $count = $this->controlServer->countServiceClients(
            $excludeClientId !== null && !$this->isSupervisorClientId($excludeClientId) ? $excludeClientId : null
        );
        if ($this->supervisorServer === null) {
            return $count;
        }

        foreach ($this->supervisorServer->sessions() as $session) {
            $clientId = $this->toSupervisorClientId($session->id);
            if ($excludeClientId !== null && $excludeClientId === $clientId) {
                continue;
            }
            if ($session->role === '') {
                continue;
            }
            $count++;
        }

        return $count;
    }

    public function flushPendingWrites(float $maxSeconds = 2.0): void
    {
        $this->controlServer->flushPendingWrites($maxSeconds);
        if ($this->supervisorServer !== null) {
            $deadline = \microtime(true) + \max(0.0, $maxSeconds);
            do {
                $changed = $this->pollSupervisor(0, 0);
                if ($changed <= 0) {
                    break;
                }
            } while (\microtime(true) < $deadline);
        }
    }

    public function close(): void
    {
        $this->supervisorServer?->close();
        $this->controlServer->close();
        $this->started = false;
    }

    public function supervisorEndpointUri(): ?string
    {
        return $this->supervisorServer?->endpoint()?->uri();
    }

    public function isSupervisorEnabled(): bool
    {
        return $this->supervisorEnabled;
    }

    public function supervisorChannelId(): ?string
    {
        if (!$this->supervisorEnabled) {
            return null;
        }

        if ($this->channelId !== null && $this->channelId !== '') {
            return $this->channelId;
        }

        return $this->expectedInstanceCode !== null && $this->expectedInstanceCode !== ''
            ? 'channel-' . $this->expectedInstanceCode
            : null;
    }

    private function pollSupervisor(int $timeoutSec, int $timeoutUsec): int
    {
        if ($this->supervisorServer === null) {
            return 0;
        }

        $before = $this->supervisorServer->sessionsSnapshot();
        $events = $this->supervisorServer->poll($timeoutSec, $timeoutUsec);
        $after = $this->supervisorServer->sessionsSnapshot();

        $this->dispatchSupervisorLifecycleEvents($before, $after);

        return $events;
    }

    /**
     * @param array<int, array<string, mixed>> $before
     * @param array<int, array<string, mixed>> $after
     */
    private function dispatchSupervisorLifecycleEvents(array $before, array $after): void
    {
        foreach ($after as $sessionId => $sessionInfo) {
            $prev = $before[$sessionId] ?? null;
            if (($sessionInfo['role'] ?? '') === '' || ($sessionInfo['slot_id'] ?? '') === '') {
                continue;
            }

            $clientId = $this->toSupervisorClientId((int)$sessionId);
            $msg = $this->buildSupervisorRegisterMessage($sessionInfo);
            $becameIdentified = $prev === null
                || (
                    (($prev['role'] ?? '') === '' || ($prev['slot_id'] ?? '') === '')
                    && (($sessionInfo['role'] ?? '') !== '' && ($sessionInfo['slot_id'] ?? '') !== '')
                );
            $leaseChanged = ($prev['lease_id'] ?? '') !== ($sessionInfo['lease_id'] ?? '')
                || (int)($prev['generation'] ?? 0) !== (int)($sessionInfo['generation'] ?? 0);
            if ($becameIdentified || $leaseChanged) {
                if ($this->messageHandler !== null) {
                    ($this->messageHandler)($msg, $clientId, $this);
                }
            }

            if (($prev['port'] ?? 0) !== ($sessionInfo['port'] ?? 0) && (int)($sessionInfo['port'] ?? 0) > 0) {
                $ready = $this->buildSupervisorReadyMessage($sessionInfo);
                if ($this->messageHandler !== null) {
                    ($this->messageHandler)($ready, $clientId, $this);
                }
            }
        }

        foreach ($before as $sessionId => $sessionInfo) {
            if (isset($after[$sessionId])) {
                continue;
            }
            if (($sessionInfo['role'] ?? '') === '' || $this->disconnectHandler === null) {
                continue;
            }
            $clientId = $this->toSupervisorClientId((int)$sessionId);
            ($this->disconnectHandler)($clientId, $this->buildSupervisorClientInfo($sessionInfo), $this);
        }
    }

    /**
     * @param array<string, mixed> $sessionInfo
     * @return array<string, mixed>
     */
    private function buildSupervisorRegisterMessage(array $sessionInfo): array
    {
        return [
            'type' => ControlMessage::TYPE_REGISTER,
            'role' => (string)($sessionInfo['role'] ?? ''),
            'pid' => (int)($sessionInfo['pid'] ?? 0),
            'port' => (int)($sessionInfo['port'] ?? 0),
            'worker_id' => (int)($sessionInfo['worker_id'] ?? 0),
            'epoch' => 0,
            'launch_id' => (string)($sessionInfo['launch_nonce'] ?? ''),
            'slot_id' => (string)($sessionInfo['slot_id'] ?? ''),
            'lease_id' => (string)($sessionInfo['lease_id'] ?? ''),
            'generation' => (int)($sessionInfo['generation'] ?? 0),
            'process_kind' => ControlMessage::PROCESS_KIND_FRAMEWORK,
            'module_code' => '',
            'instance_code' => (string)($sessionInfo['instance'] ?? $this->expectedInstanceCode ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $sessionInfo
     * @return array<string, mixed>
     */
    private function buildSupervisorReadyMessage(array $sessionInfo): array
    {
        return [
            'type' => ControlMessage::TYPE_READY,
            'role' => (string)($sessionInfo['role'] ?? ''),
            'port' => (int)($sessionInfo['port'] ?? 0),
            'worker_id' => (int)($sessionInfo['worker_id'] ?? 0),
            'epoch' => 0,
            'launch_id' => (string)($sessionInfo['launch_nonce'] ?? ''),
            'msg_id' => (string)($sessionInfo['lease_id'] ?? ''),
            'slot_id' => (string)($sessionInfo['slot_id'] ?? ''),
            'lease_id' => (string)($sessionInfo['lease_id'] ?? ''),
            'generation' => (int)($sessionInfo['generation'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed>|SupervisorSession $session
     * @return array<string, mixed>
     */
    private function buildSupervisorClientInfo(array|SupervisorSession $session): array
    {
        if ($session instanceof SupervisorSession) {
            return [
                'role' => $session->role,
                'pid' => $session->pid,
                'port' => $session->port,
                'worker_id' => $session->workerId,
                'launch_id' => $session->launchNonce,
                'peer_name' => $session->peer,
                'instance_code' => $session->instance,
                'slot_id' => $session->slotId,
                'lease_id' => $session->leaseId,
                'generation' => $session->generation,
            ];
        }

        return [
            'role' => (string)($session['role'] ?? ''),
            'pid' => (int)($session['pid'] ?? 0),
            'port' => (int)($session['port'] ?? 0),
            'worker_id' => (int)($session['worker_id'] ?? 0),
            'launch_id' => (string)($session['launch_nonce'] ?? ''),
            'peer_name' => (string)($session['peer'] ?? ''),
            'instance_code' => (string)($session['instance'] ?? ''),
            'slot_id' => (string)($session['slot_id'] ?? ''),
            'lease_id' => (string)($session['lease_id'] ?? ''),
            'generation' => (int)($session['generation'] ?? 0),
        ];
    }

    private function sendToSupervisor(int $clientId, string $message): bool
    {
        if ($this->supervisorServer === null || $message === '') {
            return false;
        }

        $sessionId = $this->fromSupervisorClientId($clientId);
        $decoded = ControlMessage::decode($message);
        if (!\is_array($decoded)) {
            return $this->supervisorServer->sendToSession($sessionId, $message);
        }

        $translated = $this->translateToSupervisorMessage($sessionId, $decoded);
        if ($translated === null || $translated === '') {
            return false;
        }

        return $this->supervisorServer->sendToSession($sessionId, $translated);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function translateToSupervisorMessage(int $sessionId, array $message): ?string
    {
        if ($this->supervisorServer === null) {
            return null;
        }

        $session = $this->supervisorServer->sessions()[$sessionId] ?? null;
        if (!$session instanceof SupervisorSession) {
            return null;
        }

        $type = (string)($message['type'] ?? '');
        return match ($type) {
            ControlMessage::TYPE_ACK_READY, ControlMessage::TYPE_READY_ACK => SupervisorMessage::encode([
                'type' => SupervisorMessage::TYPE_READY_ACK,
                'accepted' => (bool)($message['accepted'] ?? true),
                'slot_id' => $session->slotId,
                'lease_id' => $session->leaseId,
                'generation' => $session->generation,
                'msg_id' => (string)($message['msg_id'] ?? $session->leaseId),
                'channel' => $session->channel,
                'port' => (int)($message['port'] ?? $session->port),
            ]),
            ControlMessage::TYPE_SET_ROUTE_TABLE => SupervisorMessage::poolSnapshot(
                workers: $this->translateRouteTableToSnapshotWorkers($message),
                version: (int)($message['route_version'] ?? 1),
                scope: (string)($message['scope'] ?? (((string)($message['role'] ?? '')) === ControlMessage::ROLE_WORKER ? 'business' : (string)($message['role'] ?? ''))),
                msgId: (string)($message['trace_id'] ?? $message['msg_id'] ?? ''),
                channel: $session->channel,
            ),
            ControlMessage::TYPE_SHUTDOWN => ControlMessage::shutdown(),
            ControlMessage::TYPE_DRAIN => ControlMessage::drain($message['ports'] ?? []),
            default => $message !== [] ? ControlMessage::encode($message) : null,
        };
    }

    /**
     * @param array<string, mixed> $message
     * @return array<int, array<string, int|string>>
     */
    private function translateRouteTableToSnapshotWorkers(array $message): array
    {
        $workers = [];
        if (\is_array($message['workers'] ?? null)) {
            foreach ($message['workers'] as $worker) {
                if (!\is_array($worker)) {
                    continue;
                }
                $port = (int)($worker['port'] ?? 0);
                if ($port <= 0) {
                    continue;
                }
                $workers[] = [
                    'slot_id' => (string)($worker['slot_id'] ?? ''),
                    'lease_id' => (string)($worker['lease_id'] ?? ''),
                    'generation' => (int)($worker['generation'] ?? 0),
                    'port' => $port,
                    'state' => (string)($worker['state'] ?? 'ready'),
                ];
            }
            if ($workers !== []) {
                return $workers;
            }
        }
        foreach (\is_array($message['ports'] ?? null) ? $message['ports'] : [] as $port) {
            $p = (int)$port;
            if ($p <= 0) {
                continue;
            }
            $workers[] = [
                'slot_id' => 'worker@' . $p,
                'lease_id' => '',
                'generation' => 0,
                'port' => $p,
                'state' => 'ready',
            ];
        }

        return $workers;
    }

    private function isSupervisorClientId(int $clientId): bool
    {
        return $clientId >= self::SUPERVISOR_CLIENT_ID_BASE;
    }

    private function toSupervisorClientId(int $sessionId): int
    {
        return self::SUPERVISOR_CLIENT_ID_BASE + $sessionId;
    }

    private function fromSupervisorClientId(int $clientId): int
    {
        return $clientId - self::SUPERVISOR_CLIENT_ID_BASE;
    }
}
