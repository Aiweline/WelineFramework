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
    private const MAX_PENDING_SUPERVISOR_PASSTHROUGH = 1024;
    private const MAX_PENDING_SUPERVISOR_PASSTHROUGH_BYTES = 2097152;
    private const MAX_SUPERVISOR_PASSTHROUGH_MESSAGE_BYTES = 524288;

    private ?string $expectedInstanceCode = null;
    private string $expectedControlToken = '';
    private mixed $messageHandler = null;
    private mixed $disconnectHandler = null;
    private bool $windowsNativeSocketBridgeEnabled = false;
    private bool $logToConsole = true;
    private bool $started = false;

    /** @var list<array{session_id:int,message:array<string, mixed>}> */
    private array $pendingSupervisorPassthrough = [];
    private int $pendingSupervisorPassthroughBytes = 0;
    private int $droppedSupervisorPassthrough = 0;

    /** @var array<int, true> */
    private array $notifiedSupervisorDisconnects = [];

    public function __construct(
        private readonly MasterControlServer $controlServer,
        private readonly ControlEndpointResolver $endpointResolver,
        private readonly bool $supervisorEnabled = false,
        private readonly ?string $channelId = null,
        private readonly ?string $basePath = null,
        private readonly string $supervisorAuthSecret = '',
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
            // Hybrid mode treats the Orchestrator capability gate as the only
            // authority allowed to ACK READY. The Supervisor only transports it.
            $this->supervisorServer ??= new SupervisorServer($this->supervisorRuntime, deferReadyAck: true);
            $this->supervisorServer->setHelloAuthSecret($this->supervisorAuthSecret);
            $this->supervisorServer->onPassthroughMessage(function (array $message, int $sessionId): void {
                $this->enqueueSupervisorPassthroughMessage($message, $sessionId);
            });
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
            $this->disconnectSupervisorSession($sessionId, 'server_close_client');
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
        $this->pendingSupervisorPassthrough = [];
        $this->pendingSupervisorPassthroughBytes = 0;
        $this->notifiedSupervisorDisconnects = [];
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
        $this->dispatchSupervisorPassthroughMessages();
        foreach (\array_keys($this->notifiedSupervisorDisconnects) as $sessionId) {
            if (!isset($before[$sessionId]) && !isset($after[$sessionId])) {
                unset($this->notifiedSupervisorDisconnects[$sessionId]);
            }
        }

        return $events;
    }

    /** @param array<string, mixed> $message */
    private function enqueueSupervisorPassthroughMessage(array $message, int $sessionId): void
    {
        $session = $this->supervisorServer?->sessions()[$sessionId] ?? null;
        $type = (string)($message['type'] ?? '');
        if (!$session instanceof SupervisorSession
            || !$session->masterAccepted
            || !$this->isAllowedSupervisorPassthroughType($session->role, $type)) {
            return;
        }

        $encoded = \json_encode($message, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $bytes = \is_string($encoded) ? \strlen($encoded) : self::MAX_SUPERVISOR_PASSTHROUGH_MESSAGE_BYTES + 1;
        $critical = $this->isCriticalSupervisorPassthroughType($type);
        $overflow = $bytes > self::MAX_SUPERVISOR_PASSTHROUGH_MESSAGE_BYTES
            || \count($this->pendingSupervisorPassthrough) >= self::MAX_PENDING_SUPERVISOR_PASSTHROUGH
            || ($this->pendingSupervisorPassthroughBytes + $bytes) > self::MAX_PENDING_SUPERVISOR_PASSTHROUGH_BYTES;
        if ($overflow) {
            if ($critical) {
                $this->disconnectSupervisorSession($sessionId, 'critical_control_backpressure');
            } else {
                $this->droppedSupervisorPassthrough++;
            }
            return;
        }

        $this->pendingSupervisorPassthrough[] = [
            'session_id' => $sessionId,
            'message' => $message,
        ];
        $this->pendingSupervisorPassthroughBytes += $bytes;
    }

    private function dispatchSupervisorPassthroughMessages(): void
    {
        if ($this->pendingSupervisorPassthrough === []) {
            return;
        }
        $pending = $this->pendingSupervisorPassthrough;
        $this->pendingSupervisorPassthrough = [];
        $this->pendingSupervisorPassthroughBytes = 0;
        foreach ($pending as $entry) {
            $sessionId = $entry['session_id'];
            $session = $this->supervisorServer?->sessions()[$sessionId] ?? null;
            $type = (string)($entry['message']['type'] ?? '');
            if (!$session instanceof SupervisorSession
                || !$session->masterAccepted
                || $session->role === ''
                || $session->slotId === ''
                || $session->leaseId === ''
                || $session->generation <= 0
                || ($this->expectedInstanceCode !== null
                    && !\hash_equals($this->expectedInstanceCode, $session->instance))
                || !$this->isAllowedSupervisorPassthroughType($session->role, $type)) {
                continue;
            }

            if ($type === ControlMessage::TYPE_EXITED) {
                $this->disconnectSupervisorSession($sessionId, 'client_exited');
                continue;
            }
            if ($type === SupervisorMessage::TYPE_POOL_SNAPSHOT_ACK || $type === ControlMessage::TYPE_PONG) {
                continue;
            }

            $message = $this->normalizeSupervisorPassthroughMessage($entry['message'], $session);
            if ($message !== [] && $this->messageHandler !== null) {
                ($this->messageHandler)($message, $this->toSupervisorClientId($sessionId), $this);
            }
        }
    }

    private function isAllowedSupervisorPassthroughType(string $role, string $type): bool
    {
        $policyAcks = [
            ControlMessage::TYPE_POLICY_PREPARED_ACK,
            ControlMessage::TYPE_POLICY_ACTIVATED_ACK,
            ControlMessage::TYPE_POLICY_COMMITTED_ACK,
            ControlMessage::TYPE_POLICY_ROLLBACK_ACK,
        ];
        $allowed = match ($role) {
            ControlMessage::ROLE_WORKER => [
                ...$policyAcks,
                ControlMessage::TYPE_CACHE_CLEAR_ACK,
                ControlMessage::TYPE_POLICY_STATE_DELTA,
                ControlMessage::TYPE_DRAINING_COMPLETE,
                ControlMessage::TYPE_EXITED,
                ControlMessage::TYPE_EXIT_REASON,
                ControlMessage::TYPE_LOG,
                ControlMessage::TYPE_STATUS_REPORT,
                ControlMessage::TYPE_WORKER_LOOP_STARTED,
                ControlMessage::TYPE_TELEMETRY,
                ControlMessage::TYPE_TELEMETRY_BATCH,
                ControlMessage::TYPE_FIBER_POOL_STATS,
                ControlMessage::TYPE_MAINTENANCE_MODE_ACK,
                ControlMessage::TYPE_PONG,
            ],
            ControlMessage::ROLE_MAINTENANCE => [
                ...$policyAcks,
                ControlMessage::TYPE_CACHE_CLEAR_ACK,
                ControlMessage::TYPE_POLICY_STATE_DELTA,
                ControlMessage::TYPE_DRAINING_COMPLETE,
                ControlMessage::TYPE_EXITED,
                ControlMessage::TYPE_EXIT_REASON,
                ControlMessage::TYPE_LOG,
                ControlMessage::TYPE_STATUS_REPORT,
                ControlMessage::TYPE_WORKER_LOOP_STARTED,
                ControlMessage::TYPE_TELEMETRY,
                ControlMessage::TYPE_TELEMETRY_BATCH,
                ControlMessage::TYPE_MAINTENANCE_MODE_ACK,
                ControlMessage::TYPE_PONG,
            ],
            ControlMessage::ROLE_DISPATCHER => [
                ...$policyAcks,
                ControlMessage::TYPE_POLICY_STATE_DELTA,
                ControlMessage::TYPE_DRAINING_COMPLETE,
                ControlMessage::TYPE_EXITED,
                ControlMessage::TYPE_EXIT_REASON,
                ControlMessage::TYPE_LOG,
                ControlMessage::TYPE_STATUS_REPORT,
                ControlMessage::TYPE_DISPATCHER_ALERT,
                ControlMessage::TYPE_WORKER_POOL_ACK,
                ControlMessage::TYPE_ROUTE_TABLE_ACK,
                SupervisorMessage::TYPE_POOL_SNAPSHOT_ACK,
                ControlMessage::TYPE_PONG,
            ],
            ControlMessage::ROLE_REDIRECT => [
                ControlMessage::TYPE_EXITED,
                ControlMessage::TYPE_EXIT_REASON,
                ControlMessage::TYPE_LOG,
                ControlMessage::TYPE_STATUS_REPORT,
                ControlMessage::TYPE_PONG,
            ],
            ControlMessage::ROLE_SESSION_SERVER, ControlMessage::ROLE_MEMORY_SERVER => [
                ControlMessage::TYPE_DRAINING_COMPLETE,
                ControlMessage::TYPE_EXITED,
                ControlMessage::TYPE_EXIT_REASON,
                ControlMessage::TYPE_LOG,
                ControlMessage::TYPE_STATUS_REPORT,
                ControlMessage::TYPE_PONG,
            ],
            default => [],
        };

        return \in_array($type, $allowed, true);
    }

    private function isCriticalSupervisorPassthroughType(string $type): bool
    {
        return \in_array($type, [
            ControlMessage::TYPE_POLICY_PREPARED_ACK,
            ControlMessage::TYPE_POLICY_ACTIVATED_ACK,
            ControlMessage::TYPE_POLICY_COMMITTED_ACK,
            ControlMessage::TYPE_POLICY_ROLLBACK_ACK,
            ControlMessage::TYPE_CACHE_CLEAR_ACK,
            ControlMessage::TYPE_DRAINING_COMPLETE,
            ControlMessage::TYPE_EXITED,
            ControlMessage::TYPE_EXIT_REASON,
            ControlMessage::TYPE_WORKER_LOOP_STARTED,
            ControlMessage::TYPE_WORKER_POOL_ACK,
            ControlMessage::TYPE_ROUTE_TABLE_ACK,
            SupervisorMessage::TYPE_POOL_SNAPSHOT_ACK,
            ControlMessage::TYPE_MAINTENANCE_MODE_ACK,
        ], true);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function normalizeSupervisorPassthroughMessage(array $message, SupervisorSession $session): array
    {
        $type = (string)($message['type'] ?? '');
        if (\in_array($type, [
            ControlMessage::TYPE_POLICY_PREPARED_ACK,
            ControlMessage::TYPE_POLICY_ACTIVATED_ACK,
            ControlMessage::TYPE_POLICY_COMMITTED_ACK,
            ControlMessage::TYPE_POLICY_ROLLBACK_ACK,
        ], true)) {
            $capabilities = [];
            foreach (\array_slice((array)($message['capabilities'] ?? []), 0, 16) as $capability) {
                $value = \substr((string)$capability, 0, 64);
                if ($value !== '') {
                    $capabilities[] = $value;
                }
            }
            $message = [
                'type' => $type,
                'digest' => \substr(\strtolower((string)($message['digest'] ?? '')), 0, 64),
                'success' => (bool)($message['success'] ?? false),
                'error' => \substr((string)($message['error'] ?? ''), 0, 1024),
                'capabilities' => $capabilities,
            ];
        }

        if ($type === ControlMessage::TYPE_CACHE_CLEAR_ACK) {
            $message = [
                'type' => $type,
                'cache_epoch' => \max(0, (int)($message['cache_epoch'] ?? 0)),
                'success' => (bool)($message['success'] ?? false),
                'applied' => (bool)($message['applied'] ?? false),
                'current_epoch' => \max(0, (int)($message['current_epoch'] ?? 0)),
                'error' => \substr((string)($message['error'] ?? ''), 0, 512),
            ];
        }

        $message['source_instance'] = $session->instance;
        $message['source_role'] = $session->role;
        $message['source_pid'] = $session->pid;
        $message['source_port'] = $session->port;
        $message['source_worker_id'] = $session->workerId;
        $message['source_slot_id'] = $session->slotId;
        $message['source_lease_id'] = $session->leaseId;
        $message['source_generation'] = $session->generation;

        if (\in_array($type, [
            ControlMessage::TYPE_POLICY_STATE_DELTA,
            ControlMessage::TYPE_TELEMETRY,
            ControlMessage::TYPE_TELEMETRY_BATCH,
            ControlMessage::TYPE_DISPATCHER_ALERT,
        ], true)) {
            $message['instance'] = $session->instance;
        }
        if ($type === ControlMessage::TYPE_WORKER_LOOP_STARTED) {
            $message['pid'] = $session->pid;
            $message['port'] = $session->port;
            $message['worker_id'] = $session->workerId;
        } elseif ($type === ControlMessage::TYPE_DRAINING_COMPLETE) {
            $message['port'] = $session->port;
            $message['worker_id'] = $session->workerId;
        } elseif (\in_array($type, [
            ControlMessage::TYPE_FIBER_POOL_STATS,
            ControlMessage::TYPE_MAINTENANCE_MODE_ACK,
            ControlMessage::TYPE_CACHE_CLEAR_ACK,
        ], true)) {
            $message['worker_id'] = $session->workerId;
        } elseif ($type === ControlMessage::TYPE_LOG) {
            $message['process_tag'] = $session->role
                . ($session->workerId > 0 ? '#' . $session->workerId : '');
        } elseif ($type === ControlMessage::TYPE_DISPATCHER_ALERT) {
            $subjectRole = (string)($message['subject_role'] ?? '');
            if (!\in_array($subjectRole, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)) {
                unset($message['subject_role']);
            }
        }

        return $message;
    }

    private function disconnectSupervisorSession(int $sessionId, string $reason): void
    {
        $session = $this->supervisorServer?->sessions()[$sessionId] ?? null;
        if (!$session instanceof SupervisorSession) {
            return;
        }
        $this->supervisorServer?->closeSessionById($sessionId);
        $this->removePendingSupervisorMessagesForSession($sessionId);
        if ($this->disconnectHandler !== null) {
            ($this->disconnectHandler)(
                $this->toSupervisorClientId($sessionId),
                $this->buildSupervisorClientInfo($session, $reason),
                $this,
            );
        }
        $this->notifiedSupervisorDisconnects[$sessionId] = true;
    }

    private function removePendingSupervisorMessagesForSession(int $sessionId): void
    {
        if ($this->pendingSupervisorPassthrough === []) {
            return;
        }
        $this->pendingSupervisorPassthrough = \array_values(\array_filter(
            $this->pendingSupervisorPassthrough,
            static fn(array $entry): bool => (int)($entry['session_id'] ?? 0) !== $sessionId,
        ));
        $this->pendingSupervisorPassthroughBytes = 0;
        foreach ($this->pendingSupervisorPassthrough as $entry) {
            $encoded = \json_encode($entry['message'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if (\is_string($encoded)) {
                $this->pendingSupervisorPassthroughBytes += \strlen($encoded);
            }
        }
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
            $masterAccepted = (bool)($sessionInfo['master_accepted'] ?? false);
            if (!$masterAccepted && ($becameIdentified || $leaseChanged)) {
                if ($this->messageHandler !== null) {
                    ($this->messageHandler)($msg, $clientId, $this);
                }
                if ($this->supervisorServer?->hasSession((int)$sessionId)) {
                    $masterAccepted = $this->supervisorServer->markSessionMasterAccepted((int)$sessionId);
                    $sessionInfo['master_accepted'] = $masterAccepted;
                }
            }

            $pendingReady = \is_array($sessionInfo['pending_ready'] ?? null)
                ? $sessionInfo['pending_ready']
                : [];
            $previousPendingReady = \is_array($prev['pending_ready'] ?? null)
                ? $prev['pending_ready']
                : [];
            if ($masterAccepted
                && $pendingReady !== []
                && (string)($pendingReady['msg_id'] ?? '') !== (string)($previousPendingReady['msg_id'] ?? '')) {
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
            if (isset($this->notifiedSupervisorDisconnects[$sessionId])) {
                unset($this->notifiedSupervisorDisconnects[$sessionId]);
                continue;
            }
            if (($sessionInfo['role'] ?? '') === '' || $this->disconnectHandler === null) {
                continue;
            }
            $clientId = $this->toSupervisorClientId((int)$sessionId);
            ($this->disconnectHandler)($clientId, $this->buildSupervisorClientInfo($sessionInfo, 'socket_closed'), $this);
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
        $pending = \is_array($sessionInfo['pending_ready'] ?? null)
            ? $sessionInfo['pending_ready']
            : [];
        $listen = \is_array($pending['listen'] ?? null) ? $pending['listen'] : [];
        $message = [
            'type' => ControlMessage::TYPE_READY,
            'role' => (string)($sessionInfo['role'] ?? ''),
            'port' => (int)($listen['port'] ?? $pending['port'] ?? 0),
            'worker_id' => (int)($sessionInfo['worker_id'] ?? 0),
            'epoch' => 0,
            'launch_id' => (string)($sessionInfo['launch_nonce'] ?? ''),
            'msg_id' => (string)($pending['msg_id'] ?? $sessionInfo['lease_id'] ?? ''),
            'slot_id' => (string)($sessionInfo['slot_id'] ?? ''),
            'lease_id' => (string)($sessionInfo['lease_id'] ?? ''),
            'generation' => (int)($sessionInfo['generation'] ?? 0),
        ];
        foreach ([
            'readiness_protocol_version',
            'readiness_capabilities',
            'topology',
            'policy_digest',
            'container_registry_digest',
            'warmup_state',
            'homepage_fpc',
            'dynamic_first_render',
            'listen_capabilities',
        ] as $field) {
            if (\array_key_exists($field, $pending)) {
                $message[$field] = $pending[$field];
            }
        }

        return $message;
    }

    /**
     * @param array<string, mixed>|SupervisorSession $session
     * @return array<string, mixed>
     */
    private function buildSupervisorClientInfo(
        array|SupervisorSession $session,
        string $disconnectReason = 'socket_closed',
    ): array
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
                'address' => $session->peer,
                'state' => $disconnectReason === 'client_exited' ? 'exited' : 'disconnected',
                'disconnect_reason' => $disconnectReason,
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
            'address' => (string)($session['peer'] ?? ''),
            'state' => $disconnectReason === 'client_exited' ? 'exited' : 'disconnected',
            'disconnect_reason' => $disconnectReason,
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
        if (\in_array($type, [ControlMessage::TYPE_ACK_READY, ControlMessage::TYPE_READY_ACK], true)) {
            return $this->translateSupervisorReadyAck($sessionId, $session, $message);
        }

        return match ($type) {
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
     */
    private function translateSupervisorReadyAck(
        int $sessionId,
        SupervisorSession $session,
        array $message,
    ): ?string {
        $pending = $session->pendingReady;
        if ($pending === []) {
            return null;
        }
        $pendingMsgId = (string)($pending['msg_id'] ?? '');
        $receivedMsgId = (string)($message['msg_id'] ?? '');
        if ($pendingMsgId === ''
            || $receivedMsgId === ''
            || !\hash_equals($pendingMsgId, $receivedMsgId)
            || ((string)($message['slot_id'] ?? $session->slotId)) !== $session->slotId
            || !\hash_equals((string)($message['lease_id'] ?? ''), $session->leaseId)
            || (int)($message['generation'] ?? 0) !== $session->generation) {
            return null;
        }

        $accepted = (bool)($message['accepted'] ?? true);
        $reason = \substr((string)($message['reason'] ?? ''), 0, 1024);
        $resolved = $this->supervisorServer?->resolveDeferredReady($sessionId, $accepted) ?? false;
        if (!$resolved) {
            $accepted = false;
            $reason = $reason !== '' ? $reason : 'supervisor_ready_commit_failed';
        }
        $listen = \is_array($pending['listen'] ?? null) ? $pending['listen'] : [];

        return SupervisorMessage::encode([
            'type' => SupervisorMessage::TYPE_READY_ACK,
            'accepted' => $accepted,
            'slot_id' => $session->slotId,
            'lease_id' => $session->leaseId,
            'generation' => $session->generation,
            'msg_id' => $pendingMsgId,
            'channel' => $session->channel,
            'port' => (int)($message['port'] ?? $listen['port'] ?? 0),
            'worker_id' => (int)($message['worker_id'] ?? $session->workerId),
            'reason' => $reason,
        ]);
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
