<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor;

use Weline\Server\Supervisor\Endpoint\ControlEndpoint;
use Weline\Server\Supervisor\Lease\SlotLease;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;

final class SupervisorServer
{
    private const MAX_SESSIONS = 256;
    private const MAX_UNREGISTERED_SESSIONS = 64;
    private const MAX_READ_BUFFER_BYTES = 2097152;
    private const MAX_WRITE_BUFFER_BYTES = 2097152;
    private const MAX_LINES_PER_BATCH = 32;
    private const MAX_LINES_PER_WAKE = 192;
    private const MAX_WRITE_BYTES_PER_FLUSH = 65536;
    private const MAX_CLOSED_SESSION_SNAPSHOTS = 512;
    private const HELLO_DEADLINE_SEC = 5.0;
    private const REGISTERED_IDLE_TIMEOUT_SEC = 60.0;
    private const AUTH_NONCE_TTL_SEC = 60;
    private const SELECT_FAILURE_BACKOFF_MIN_USEC = 1000;
    private const SELECT_FAILURE_BACKOFF_MAX_USEC = 50000;

    /**
     * @var resource|null
     */
    private $serverSocket = null;

    /**
     * @var array<int, SupervisorSession>
     */
    private array $sessions = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $closedSessionSnapshots = [];

    private ?ControlEndpoint $boundEndpoint = null;

    /** @var null|callable(array<string, mixed>, int): void */
    private mixed $passthroughMessageHandler = null;

    private string $helloAuthSecret = '';

    /** @var array<string, int> */
    private array $usedHelloAuthNonces = [];

    private int $selectFailureStreak = 0;
    private int $nextSelectAttemptNs = 0;

    public function __construct(
        private readonly SupervisorRuntime $runtime,
        private readonly bool $deferReadyAck = false,
    ) {
    }

    public function runtime(): SupervisorRuntime
    {
        return $this->runtime;
    }

    public function start(?ControlEndpoint $endpoint = null): ControlEndpoint
    {
        $this->closedSessionSnapshots = [];
        $this->selectFailureStreak = 0;
        $this->nextSelectAttemptNs = 0;
        $endpoint ??= $this->runtime->endpoint();
        $this->boundEndpoint = $endpoint;

        if ($endpoint->isUnix()) {
            $socketPath = $endpoint->address;
            $socketDir = \dirname($socketPath);
            if (!\is_dir($socketDir) && !@\mkdir($socketDir, 0700, true) && !\is_dir($socketDir)) {
                throw new \RuntimeException("Unable to create supervisor socket directory: {$socketDir}");
            }
            @\chmod($socketDir, 0700);
            if (\file_exists($socketPath)) {
                @\unlink($socketPath);
            }
        }

        $errno = 0;
        $errstr = '';
        $server = @\stream_socket_server(
            $endpoint->uri(),
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN
        );
        if (!\is_resource($server)) {
            throw new \RuntimeException("Failed to start supervisor endpoint {$endpoint->uri()}: ({$errno}) {$errstr}");
        }

        \stream_set_blocking($server, false);
        @\stream_set_write_buffer($server, 0);
        $this->serverSocket = $server;
        if ($endpoint->isUnix()) {
            @\chmod($endpoint->address, 0600);
        }

        if ($endpoint->isTcp() && $endpoint->port() === 0) {
            $actual = (string)@\stream_socket_get_name($server, false);
            if ($actual !== '' && \str_contains($actual, ':')) {
                [$host, $port] = \explode(':', $actual, 2);
                $this->boundEndpoint = ControlEndpoint::tcp($host, (int)$port);
            }
        }

        return $this->boundEndpoint ?? $endpoint;
    }

    public function endpoint(): ?ControlEndpoint
    {
        return $this->boundEndpoint;
    }

    public function hasSession(int $sessionId): bool
    {
        return isset($this->sessions[$sessionId]);
    }

    /**
     * Forward child control-plane messages that are not part of the Supervisor
     * lease protocol (for example batched telemetry) to the hybrid bridge.
     */
    public function onPassthroughMessage(callable $handler): void
    {
        $this->passthroughMessageHandler = $handler;
    }

    public function setHelloAuthSecret(string $secret): void
    {
        $this->helloAuthSecret = \trim($secret);
    }

    public function closeSessionById(int $sessionId, string $disconnectReason = 'server_close_client'): void
    {
        $this->closeSession($sessionId, $disconnectReason);
    }

    public function markSessionMasterAccepted(int $sessionId): bool
    {
        $session = $this->sessions[$sessionId] ?? null;
        if (!$session instanceof SupervisorSession || $session->role === '' || $session->leaseId === '') {
            return false;
        }
        $session->masterAccepted = true;
        $this->sessions[$sessionId] = $session;

        return true;
    }

    public function resolveDeferredReady(int $sessionId, bool $accepted): bool
    {
        $session = $this->sessions[$sessionId] ?? null;
        if (!$session instanceof SupervisorSession || $session->pendingReady === []) {
            return false;
        }
        $pending = $session->pendingReady;
        $session->pendingReady = [];
        if (!$accepted) {
            $this->sessions[$sessionId] = $session;
            return true;
        }

        $response = $this->runtime->handle($pending);
        $decodedResponse = \is_string($response) ? SupervisorMessage::decode($response) : [];
        if (($decodedResponse['type'] ?? '') !== SupervisorMessage::TYPE_READY_ACK
            || !($decodedResponse['accepted'] ?? false)) {
            $this->sessions[$sessionId] = $session;
            return false;
        }

        $session = $this->rememberSessionMetadata($session, $pending);
        $session->pendingReady = [];
        $this->sessions[$sessionId] = $session;

        return true;
    }

    public function sendToSession(int $sessionId, string $message): bool
    {
        if ($message === '' || !isset($this->sessions[$sessionId])) {
            return false;
        }

        if ((\strlen($this->sessions[$sessionId]->writeBuffer) + \strlen($message)) > self::MAX_WRITE_BUFFER_BYTES) {
            $this->closeSession($sessionId, 'write_overflow');
            return false;
        }
        $this->sessions[$sessionId]->writeBuffer .= $message;
        $this->flushWrites($this->sessions[$sessionId]);

        return isset($this->sessions[$sessionId]);
    }

    /**
     * @return array<int, SupervisorSession>
     */
    public function sessions(): array
    {
        return $this->sessions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sessionsSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->sessions as $session) {
            $snapshot[$session->id] = $this->buildSessionSnapshot($session);
        }

        return $snapshot;
    }

    /**
     * Consume sessions closed during the latest poll so the Hybrid bridge can
     * retain the precise transport reason after the live socket is gone.
     *
     * @return array<int, array<string, mixed>>
     */
    public function drainClosedSessionSnapshots(): array
    {
        $snapshots = $this->closedSessionSnapshots;
        $this->closedSessionSnapshots = [];

        return $snapshots;
    }

    public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
    {
        if (!\is_resource($this->serverSocket)) {
            return 0;
        }

        // A delayed Master may already have healthy heartbeats in user-space
        // or kernel buffers. Drain them before evaluating idle deadlines.
        $events = $this->drainBufferedMessagesFairly();
        if ($events > 0) {
            $timeoutSec = 0;
            $timeoutUsec = 0;
        }

        if ($this->nextSelectAttemptNs > \hrtime(true)) {
            return $events + $this->expireStaleSessions();
        }

        $read = [$this->serverSocket];
        $write = [];
        foreach ($this->sessions as $session) {
            if (!\is_resource($session->socket)) {
                $this->closeSession($session->id, 'invalid_socket');
                $events++;
                continue;
            }
            $read[] = $session->socket;
            if ($session->hasPendingWrites()) {
                $write[] = $session->socket;
            }
        }
        $except = [];

        $changed = @\stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
        if ($changed === false) {
            $this->selectFailureStreak = \min(16, $this->selectFailureStreak + 1);
            $exponent = \min(6, $this->selectFailureStreak - 1);
            $backoffUsec = \min(
                self::SELECT_FAILURE_BACKOFF_MAX_USEC,
                self::SELECT_FAILURE_BACKOFF_MIN_USEC * (1 << $exponent)
            );
            $jitterUsec = ((\getmypid() ?: 0) + ($this->selectFailureStreak * 97)) % 1000;
            $this->nextSelectAttemptNs = \hrtime(true) + (($backoffUsec + $jitterUsec) * 1000);

            return $events + $this->expireStaleSessions();
        }

        $this->selectFailureStreak = 0;
        $this->nextSelectAttemptNs = 0;
        if ($changed > 0) {
            foreach ($read as $socket) {
                if ($socket === $this->serverSocket) {
                    $events += $this->acceptPendingConnections();
                    continue;
                }

                $sessionId = (int)$socket;
                if (!isset($this->sessions[$sessionId])) {
                    continue;
                }
                $events += $this->handleReadable($this->sessions[$sessionId]);
            }

            foreach ($write as $socket) {
                $sessionId = (int)$socket;
                if (!isset($this->sessions[$sessionId])) {
                    continue;
                }
                $events += $this->flushWrites($this->sessions[$sessionId]) ? 1 : 0;
            }
        }

        $events += $this->expireStaleSessions();

        return $events;
    }

    public function close(): void
    {
        foreach (\array_keys($this->sessions) as $sessionId) {
            $this->closeSession($sessionId, 'server_shutdown');
        }

        if (\is_resource($this->serverSocket)) {
            @\fclose($this->serverSocket);
        }
        $this->serverSocket = null;
        $this->selectFailureStreak = 0;
        $this->nextSelectAttemptNs = 0;

        if ($this->boundEndpoint?->isUnix()) {
            @\unlink($this->boundEndpoint->address);
        }
        $this->boundEndpoint = null;
        $this->closedSessionSnapshots = [];
    }

    private function acceptPendingConnections(): int
    {
        $accepted = 0;
        while (\is_resource($this->serverSocket)) {
            $conn = @\stream_socket_accept($this->serverSocket, 0, $peer);
            if (!\is_resource($conn)) {
                break;
            }

            if (\count($this->sessions) >= self::MAX_SESSIONS
                || $this->countUnregisteredSessions() >= self::MAX_UNREGISTERED_SESSIONS) {
                @\fclose($conn);
                continue;
            }

            \stream_set_blocking($conn, false);
            @\stream_set_write_buffer($conn, 0);
            $connectedAt = \microtime(true);
            $session = new SupervisorSession(
                id: (int) $conn,
                peer: \is_string($peer) && $peer !== '' ? $peer : 'unknown',
                socket: $conn,
                lastActivityAt: $connectedAt,
                connectedAt: $connectedAt,
            );
            $this->sessions[$session->id] = $session;
            $accepted++;
        }

        return $accepted;
    }

    private function handleReadable(SupervisorSession $session): int
    {
        if (!\is_resource($session->socket)) {
            $this->closeSession($session->id, 'invalid_socket');
            return 1;
        }

        $data = @\fread($session->socket, 65536);
        if ($data === false) {
            if (!@\feof($session->socket)) {
                return $this->flushBufferedMessages($session);
            }
            $this->closeSession($session->id, 'read_eof');
            return 1;
        }

        if ($data === '' && @\feof($session->socket)) {
            $this->closeSession($session->id, 'read_eof');
            return 1;
        }

        if ($data === '') {
            return 0;
        }

        if ((\strlen($session->readBuffer) + \strlen($data)) > self::MAX_READ_BUFFER_BYTES) {
            $this->closeSession($session->id, 'read_overflow');
            return 1;
        }
        $session->readBuffer .= $data;
        $session->lastActivityAt = \microtime(true);
        $this->sessions[$session->id] = $session;

        return $this->flushBufferedMessagesForWake($session);
    }

    private function flushBufferedMessagesForWake(SupervisorSession $session): int
    {
        $messages = 0;
        while ($messages < self::MAX_LINES_PER_WAKE && isset($this->sessions[$session->id])) {
            $processed = $this->flushBufferedMessages(
                $this->sessions[$session->id],
                \min(self::MAX_LINES_PER_BATCH, self::MAX_LINES_PER_WAKE - $messages),
            );
            $messages += $processed;
            if ($processed < self::MAX_LINES_PER_BATCH) {
                break;
            }
        }

        return $messages;
    }

    private function flushBufferedMessages(SupervisorSession $session, int $maxLines = self::MAX_LINES_PER_BATCH): int
    {
        if (!isset($this->sessions[$session->id])) {
            return 0;
        }

        $messages = 0;
        $maxLines = \max(1, $maxLines);
        while ($messages < $maxLines && ($newlinePos = \strpos($session->readBuffer, "\n")) !== false) {
            $line = \substr($session->readBuffer, 0, $newlinePos + 1);
            $session->readBuffer = (string)\substr($session->readBuffer, $newlinePos + 1);
            $this->sessions[$session->id] = $session;
            $messages++;

            if (\strlen($line) > self::MAX_READ_BUFFER_BYTES) {
                $this->closeSession($session->id, 'read_overflow');
                break;
            }

            $decoded = SupervisorMessage::decode($line);
            if ($decoded === []) {
                continue;
            }
            if (!$this->isSupervisorProtocolMessage($decoded)) {
                if ($this->isCurrentRegisteredSession($session)
                    && ($session->masterAccepted || !$this->deferReadyAck)
                    && $this->passthroughMessageHandler !== null) {
                    ($this->passthroughMessageHandler)($decoded, $session->id);
                }
                continue;
            }

            $rejection = $this->runtime->validateEnvelope($decoded);
            if ($rejection !== null) {
                $this->sendToSession($session->id, $rejection);
                $this->closeSession($session->id, 'envelope_rejected');
                break;
            }

            $type = (string)($decoded['type'] ?? '');
            if ($type === SupervisorMessage::TYPE_HELLO) {
                if (!$this->acceptHello($session, $decoded)) {
                    break;
                }
                $session = $this->sessions[$session->id] ?? $session;
                continue;
            }

            if (!$this->messageMatchesSessionLease($session, $decoded)) {
                $this->sendToSession(
                    $session->id,
                    SupervisorMessage::channelReject(
                        (string)($decoded['msg_id'] ?? ''),
                        $session->channel,
                        (string)($decoded['channel'] ?? ''),
                        'session_lease_mismatch',
                        $session->instance,
                        $session->instance,
                    ),
                );
                $this->closeSession($session->id, 'session_lease_mismatch');
                break;
            }

            if ($type === SupervisorMessage::TYPE_HEARTBEAT) {
                $session->lastActivityAt = \microtime(true);
                $this->sessions[$session->id] = $session;
            }

            if ($type === SupervisorMessage::TYPE_READY && $this->deferReadyAck) {
                $listen = \is_array($decoded['listen'] ?? null) ? $decoded['listen'] : [];
                $requiresListenPort = Supervisor::requiresListenPort($session->role);
                $invalidListenPort = $requiresListenPort
                    && (int)($listen['port'] ?? $decoded['port'] ?? 0) <= 0;
                if (!$session->masterAccepted
                    || $invalidListenPort
                    || $session->pendingReady !== []) {
                    $reason = !$session->masterAccepted
                        ? 'master_register_pending'
                        : ($invalidListenPort ? 'invalid_ready_payload' : 'ready_already_pending');
                    $this->sendToSession(
                        $session->id,
                        SupervisorMessage::readyNack(
                            $session->slotId,
                            $session->leaseId,
                            $session->generation,
                            (string)($decoded['msg_id'] ?? ''),
                            $reason,
                            $session->channel,
                        ),
                    );
                    if (!$session->masterAccepted) {
                        $this->closeSession($session->id, 'master_register_pending');
                    }
                    break;
                }
                $session->pendingReady = $decoded;
                $session->lastActivityAt = \microtime(true);
                $this->sessions[$session->id] = $session;
                continue;
            }

            $response = $this->runtime->handle($decoded);
            $responsePayload = \is_string($response) ? SupervisorMessage::decode($response) : [];
            if ($type === SupervisorMessage::TYPE_READY
                && (($responsePayload['type'] ?? '') !== SupervisorMessage::TYPE_READY_ACK
                    || !($responsePayload['accepted'] ?? false))) {
                if (\is_string($response) && $response !== '') {
                    $this->sendToSession($session->id, $response);
                }
                continue;
            }
            if ($type === SupervisorMessage::TYPE_READY) {
                $session = $this->rememberSessionMetadata($session, $decoded);
                $this->sessions[$session->id] = $session;
            }
            if (\is_string($response) && $response !== '') {
                $this->sendToSession($session->id, $response);
            }
        }

        return $messages;
    }

    /** @param array<string, mixed> $message */
    private function isSupervisorProtocolMessage(array $message): bool
    {
        return \in_array((string)($message['type'] ?? ''), [
            SupervisorMessage::TYPE_HELLO,
            SupervisorMessage::TYPE_READY,
            SupervisorMessage::TYPE_HEARTBEAT,
            SupervisorMessage::TYPE_LEASE_RELEASE,
        ], true);
    }

    /** @param array<string, mixed> $message */
    private function acceptHello(SupervisorSession $session, array $message): bool
    {
        $role = (string)($message['role'] ?? '');
        $slotId = (string)($message['slot_id'] ?? '');
        $msgId = (string)($message['msg_id'] ?? '');
        if ($session->role !== ''
            || \preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $role) !== 1
            || $slotId === ''
            || \strlen($slotId) > 128
            || !\str_starts_with($slotId, $role . '#')
            || !$this->authenticateHello($message)
            || $this->helloConflictsWithCurrentLease($session->id, $message)) {
            $this->sendToSession(
                $session->id,
                SupervisorMessage::channelReject(
                    $msgId,
                    (string)($message['channel'] ?? ''),
                    (string)($message['channel'] ?? ''),
                    'hello_identity_rejected',
                    (string)($message['instance'] ?? ''),
                    (string)($message['instance'] ?? ''),
                ),
            );
            $this->closeSession($session->id, 'hello_identity_rejected');
            return false;
        }

        try {
            $response = $this->runtime->handle($message);
        } catch (\Throwable) {
            $this->closeSession($session->id, 'hello_runtime_error');
            return false;
        }
        $responsePayload = \is_string($response) ? SupervisorMessage::decode($response) : [];
        if (($responsePayload['type'] ?? '') !== SupervisorMessage::TYPE_LEASE_ASSIGN
            || (string)($responsePayload['lease_id'] ?? '') === ''
            || (int)($responsePayload['generation'] ?? 0) <= 0) {
            if (\is_string($response) && $response !== '') {
                $this->sendToSession($session->id, $response);
            }
            $this->closeSession($session->id, 'lease_assignment_rejected');
            return false;
        }

        $message['lease_id'] = (string)$responsePayload['lease_id'];
        $message['generation'] = (int)$responsePayload['generation'];
        $session = $this->rememberSessionMetadata($session, $message);
        $this->sessions[$session->id] = $session;
        $this->sendToSession($session->id, (string)$response);

        return isset($this->sessions[$session->id]);
    }

    /** @param array<string, mixed> $message */
    private function authenticateHello(array $message): bool
    {
        if ($this->helloAuthSecret === '') {
            return true;
        }
        if (!SupervisorMessage::verifyHelloAuthentication($message, $this->helloAuthSecret)) {
            return false;
        }

        $now = \time();
        foreach ($this->usedHelloAuthNonces as $nonce => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->usedHelloAuthNonces[$nonce]);
            }
        }
        $nonce = (string)($message['auth_nonce'] ?? '');
        if ($nonce === '' || isset($this->usedHelloAuthNonces[$nonce])) {
            return false;
        }
        $this->usedHelloAuthNonces[$nonce] = $now + self::AUTH_NONCE_TTL_SEC;

        return true;
    }

    /** @param array<string, mixed> $message */
    private function helloConflictsWithCurrentLease(int $sessionId, array $message): bool
    {
        $slotId = (string)($message['slot_id'] ?? '');
        $current = $slotId !== '' ? $this->runtime->supervisor()->leases()->get($slotId) : null;
        if (!$current instanceof SlotLease) {
            return false;
        }

        $leaseId = (string)($message['lease_id'] ?? '');
        $generation = (int)($message['generation'] ?? 0);
        if ($leaseId === ''
            || $generation <= 0
            || !\hash_equals($current->leaseId, $leaseId)
            || $current->generation !== $generation) {
            return true;
        }
        foreach ($this->sessions as $candidate) {
            if ($candidate->id === $sessionId) {
                continue;
            }
            if ($candidate->slotId === $slotId
                && $candidate->generation === $generation
                && $candidate->leaseId !== ''
                && \hash_equals($candidate->leaseId, $leaseId)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $message */
    private function messageMatchesSessionLease(SupervisorSession $session, array $message): bool
    {
        $slotId = (string)($message['slot_id'] ?? '');
        $leaseId = (string)($message['lease_id'] ?? '');
        $generation = (int)($message['generation'] ?? 0);

        return $this->isCurrentRegisteredSession($session)
            && $slotId === $session->slotId
            && $leaseId !== ''
            && \hash_equals($session->leaseId, $leaseId)
            && $generation === $session->generation;
    }

    private function isCurrentRegisteredSession(SupervisorSession $session): bool
    {
        if ($session->role === ''
            || $session->slotId === ''
            || $session->leaseId === ''
            || $session->generation <= 0) {
            return false;
        }
        $lease = $this->runtime->supervisor()->leases()->get($session->slotId);

        return $lease instanceof SlotLease
            && \hash_equals($lease->leaseId, $session->leaseId)
            && $lease->generation === $session->generation;
    }

    private function countUnregisteredSessions(): int
    {
        $count = 0;
        foreach ($this->sessions as $session) {
            if ($session->role === '') {
                $count++;
            }
        }

        return $count;
    }

    private function expireStaleSessions(): int
    {
        $expired = 0;
        $now = \microtime(true);
        foreach ($this->sessions as $session) {
            $connectedAt = $session->connectedAt > 0.0 ? $session->connectedAt : $session->lastActivityAt;
            if ($session->role === '' && ($now - $connectedAt) > self::HELLO_DEADLINE_SEC) {
                $this->closeSession($session->id, 'hello_timeout');
                $expired++;
                continue;
            }
            if ($session->role !== ''
                && $session->lastActivityAt > 0.0
                && ($now - $session->lastActivityAt) > self::REGISTERED_IDLE_TIMEOUT_SEC) {
                $this->closeSession($session->id, 'idle_timeout');
                $expired++;
            }
        }

        return $expired;
    }

    private function drainBufferedMessagesFairly(): int
    {
        $messages = 0;
        foreach ($this->sessions as $session) {
            if (!\str_contains($session->readBuffer, "\n")) {
                continue;
            }
            $messages += $this->flushBufferedMessagesForWake($session);
        }

        return $messages;
    }

    private function flushWrites(SupervisorSession $session): bool
    {
        if ($session->writeBuffer === '') {
            return false;
        }

        if (!\is_resource($session->socket)) {
            $this->closeSession($session->id, 'invalid_socket');
            return false;
        }

        try {
            $written = @\fwrite(
                $session->socket,
                \substr($session->writeBuffer, 0, self::MAX_WRITE_BYTES_PER_FLUSH),
            );
        } catch (\Throwable) {
            $this->closeSession($session->id, 'write_error');
            return false;
        }
        if ($written === false) {
            if (!@\feof($session->socket)) {
                return false;
            }
            $this->closeSession($session->id, 'write_eof');
            return false;
        }
        if ($written > 0) {
            $session->writeBuffer = (string)\substr($session->writeBuffer, $written);
            $session->lastActivityAt = \microtime(true);
            $this->sessions[$session->id] = $session;
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function rememberSessionMetadata(SupervisorSession $session, array $decoded): SupervisorSession
    {
        $type = (string)($decoded['type'] ?? '');
        if ($type === '') {
            return $session;
        }

        $isHello = $type === \Weline\Server\Supervisor\Protocol\SupervisorMessage::TYPE_HELLO;
        $isReady = $type === \Weline\Server\Supervisor\Protocol\SupervisorMessage::TYPE_READY;

        $slotId = $isHello ? (string)($decoded['slot_id'] ?? $session->slotId) : $session->slotId;
        $workerId = $session->workerId;
        if ($isHello && $workerId <= 0 && \preg_match('/#(\d+)$/', $slotId, $matches) === 1) {
            $workerId = (int)$matches[1];
        }

        $port = $session->port;
        if ($isReady) {
            $listen = \is_array($decoded['listen'] ?? null) ? $decoded['listen'] : [];
            if (isset($listen['port'])) {
                $port = (int)$listen['port'];
            } elseif (isset($decoded['port'])) {
                $port = (int)$decoded['port'];
            }
        }

        $leaseId = $session->leaseId;
        $generation = $session->generation;
        if ($isHello || $isReady) {
            $leaseId = (string)($decoded['lease_id'] ?? $leaseId);
            $generation = (int)($decoded['generation'] ?? $generation);
        }

        $readyCapabilities = $session->readyCapabilities;
        if ($isReady) {
            $readyCapabilities = [];
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
                if (\array_key_exists($field, $decoded)) {
                    $readyCapabilities[$field] = $decoded[$field];
                }
            }
        }

        return new SupervisorSession(
            id: $session->id,
            peer: $session->peer,
            socket: $session->socket,
            readBuffer: $session->readBuffer,
            writeBuffer: $session->writeBuffer,
            lastActivityAt: $session->lastActivityAt,
            instance: $isHello ? (string)($decoded['instance'] ?? $session->instance) : $session->instance,
            channel: $isHello ? (string)($decoded['channel'] ?? $session->channel) : $session->channel,
            role: $isHello ? (string)($decoded['role'] ?? $session->role) : $session->role,
            slotId: $slotId,
            workerId: $workerId,
            pid: $isHello ? (int)($decoded['pid'] ?? $session->pid) : $session->pid,
            port: $port,
            launchNonce: $isHello ? (string)($decoded['launch_nonce'] ?? $session->launchNonce) : $session->launchNonce,
            leaseId: $leaseId,
            generation: $generation,
            readyCapabilities: $readyCapabilities,
            connectedAt: $session->connectedAt,
            masterAccepted: $session->masterAccepted,
            pendingReady: $session->pendingReady,
        );
    }

    /** @return array<string, mixed> */
    private function buildSessionSnapshot(SupervisorSession $session): array
    {
        return [
            'id' => $session->id,
            'peer' => $session->peer,
            'pending_writes' => \strlen($session->writeBuffer),
            'last_activity_at' => $session->lastActivityAt,
            'instance' => $session->instance,
            'channel' => $session->channel,
            'role' => $session->role,
            'slot_id' => $session->slotId,
            'worker_id' => $session->workerId,
            'pid' => $session->pid,
            'port' => $session->port,
            'launch_nonce' => $session->launchNonce,
            'lease_id' => $session->leaseId,
            'generation' => $session->generation,
            'ready_capabilities' => $session->readyCapabilities,
            'master_accepted' => $session->masterAccepted,
            'pending_ready' => $session->pendingReady,
        ];
    }

    private function closeSession(int $sessionId, string $disconnectReason = 'socket_closed'): void
    {
        $session = $this->sessions[$sessionId] ?? null;
        if (!$session instanceof SupervisorSession) {
            return;
        }

        $reason = \substr(\trim($disconnectReason), 0, 64);
        $snapshot = $this->buildSessionSnapshot($session);
        $snapshot['disconnect_reason'] = $reason !== '' ? $reason : 'socket_closed';
        $snapshot['disconnected_at'] = \microtime(true);
        if (!isset($this->closedSessionSnapshots[$sessionId])
            && \count($this->closedSessionSnapshots) >= self::MAX_CLOSED_SESSION_SNAPSHOTS) {
            $oldestSessionId = \array_key_first($this->closedSessionSnapshots);
            if ($oldestSessionId !== null) {
                unset($this->closedSessionSnapshots[$oldestSessionId]);
            }
        }
        $this->closedSessionSnapshots[$sessionId] = $snapshot;

        if ($this->isCurrentRegisteredSession($session)) {
            $this->runtime->releaseLease(
                $session->slotId,
                $session->leaseId,
                $session->generation,
            );
        }
        if (\is_resource($session->socket)) {
            @\fclose($session->socket);
        }

        unset($this->sessions[$sessionId]);
    }
}
