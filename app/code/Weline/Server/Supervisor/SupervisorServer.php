<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor;

use Weline\Server\Supervisor\Endpoint\ControlEndpoint;

final class SupervisorServer
{
    /**
     * @var resource|null
     */
    private $serverSocket = null;

    /**
     * @var array<int, SupervisorSession>
     */
    private array $sessions = [];

    private ?ControlEndpoint $boundEndpoint = null;

    public function __construct(
        private readonly SupervisorRuntime $runtime,
    ) {
    }

    public function runtime(): SupervisorRuntime
    {
        return $this->runtime;
    }

    public function start(?ControlEndpoint $endpoint = null): ControlEndpoint
    {
        $endpoint ??= $this->runtime->endpoint();
        $this->boundEndpoint = $endpoint;

        if ($endpoint->isUnix()) {
            $socketPath = $endpoint->address;
            $socketDir = \dirname($socketPath);
            if (!\is_dir($socketDir) && !@\mkdir($socketDir, 0777, true) && !\is_dir($socketDir)) {
                throw new \RuntimeException("Unable to create supervisor socket directory: {$socketDir}");
            }
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

    public function closeSessionById(int $sessionId): void
    {
        $this->closeSession($sessionId);
    }

    public function sendToSession(int $sessionId, string $message): bool
    {
        if ($message === '' || !isset($this->sessions[$sessionId])) {
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
     * @return array<int, array<string, int|string|float>>
     */
    public function sessionsSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->sessions as $session) {
            $snapshot[$session->id] = [
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
            ];
        }

        return $snapshot;
    }

    public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
    {
        if (!\is_resource($this->serverSocket)) {
            return 0;
        }

        $read = [$this->serverSocket];
        $write = [];
        foreach ($this->sessions as $session) {
            $read[] = $session->socket;
            if ($session->hasPendingWrites()) {
                $write[] = $session->socket;
            }
        }
        $except = [];

        $changed = @\stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
        if ($changed === false || $changed === 0) {
            return 0;
        }

        $events = 0;
        foreach ($read as $socket) {
            if ($socket === $this->serverSocket) {
                $events += $this->acceptPendingConnections();
                continue;
            }

            $sessionId = (int) $socket;
            if (!isset($this->sessions[$sessionId])) {
                continue;
            }
            $events += $this->handleReadable($this->sessions[$sessionId]);
        }

        foreach ($write as $socket) {
            $sessionId = (int) $socket;
            if (!isset($this->sessions[$sessionId])) {
                continue;
            }
            $events += $this->flushWrites($this->sessions[$sessionId]) ? 1 : 0;
        }

        return $events;
    }

    public function close(): void
    {
        foreach (\array_keys($this->sessions) as $sessionId) {
            $this->closeSession($sessionId);
        }

        if (\is_resource($this->serverSocket)) {
            @\fclose($this->serverSocket);
        }
        $this->serverSocket = null;

        if ($this->boundEndpoint?->isUnix()) {
            @\unlink($this->boundEndpoint->address);
        }
        $this->boundEndpoint = null;
    }

    private function acceptPendingConnections(): int
    {
        $accepted = 0;
        while (\is_resource($this->serverSocket)) {
            $conn = @\stream_socket_accept($this->serverSocket, 0, $peer);
            if (!\is_resource($conn)) {
                break;
            }

            \stream_set_blocking($conn, false);
            @\stream_set_write_buffer($conn, 0);
            $session = new SupervisorSession(
                id: (int) $conn,
                peer: \is_string($peer) && $peer !== '' ? $peer : 'unknown',
                socket: $conn,
                lastActivityAt: \microtime(true),
            );
            $this->sessions[$session->id] = $session;
            $accepted++;
        }

        return $accepted;
    }

    private function handleReadable(SupervisorSession $session): int
    {
        $data = @\fread($session->socket, 65536);
        if ($data === false || ($data === '' && @\feof($session->socket))) {
            $this->closeSession($session->id);
            return 1;
        }

        if ($data === '') {
            return 0;
        }

        $session->readBuffer .= $data;
        $session->lastActivityAt = \microtime(true);
        $this->sessions[$session->id] = $session;

        $messages = 0;
        while (($newlinePos = \strpos($session->readBuffer, "\n")) !== false) {
            $line = \substr($session->readBuffer, 0, $newlinePos + 1);
            $session->readBuffer = (string)\substr($session->readBuffer, $newlinePos + 1);
            $this->sessions[$session->id] = $session;

            $decoded = \Weline\Server\Supervisor\Protocol\SupervisorMessage::decode($line);
            if ($decoded === []) {
                continue;
            }
            $session = $this->rememberSessionMetadata($session, $decoded);
            $this->sessions[$session->id] = $session;
            $response = $this->runtime->handle($decoded);
            if (\is_string($response) && $response !== '') {
                $this->sessions[$session->id]->writeBuffer .= $response;
                $this->flushWrites($this->sessions[$session->id]);
            }
            $messages++;
        }

        return $messages;
    }

    private function flushWrites(SupervisorSession $session): bool
    {
        if ($session->writeBuffer === '') {
            return false;
        }

        $written = @\fwrite($session->socket, $session->writeBuffer);
        if ($written === false) {
            $this->closeSession($session->id);
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

        $workerId = $session->workerId;
        $slotId = (string)($decoded['slot_id'] ?? $session->slotId);
        if ($workerId <= 0 && \preg_match('/#(\d+)$/', $slotId, $matches) === 1) {
            $workerId = (int)$matches[1];
        }

        $port = $session->port;
        $listen = \is_array($decoded['listen'] ?? null) ? $decoded['listen'] : [];
        if (isset($listen['port'])) {
            $port = (int)$listen['port'];
        } elseif (isset($decoded['port'])) {
            $port = (int)$decoded['port'];
        }

        $leaseId = $session->leaseId;
        $generation = $session->generation;
        if ($type === \Weline\Server\Supervisor\Protocol\SupervisorMessage::TYPE_LEASE_ASSIGN
            || $type === \Weline\Server\Supervisor\Protocol\SupervisorMessage::TYPE_READY_ACK) {
            $leaseId = (string)($decoded['lease_id'] ?? $leaseId);
            $generation = (int)($decoded['generation'] ?? $generation);
        } elseif (isset($decoded['lease_id']) || isset($decoded['generation'])) {
            $leaseId = (string)($decoded['lease_id'] ?? $leaseId);
            $generation = (int)($decoded['generation'] ?? $generation);
        }

        return new SupervisorSession(
            id: $session->id,
            peer: $session->peer,
            socket: $session->socket,
            readBuffer: $session->readBuffer,
            writeBuffer: $session->writeBuffer,
            lastActivityAt: $session->lastActivityAt,
            instance: (string)($decoded['instance'] ?? $session->instance),
            channel: (string)($decoded['channel'] ?? $session->channel),
            role: (string)($decoded['role'] ?? $session->role),
            slotId: $slotId,
            workerId: $workerId,
            pid: (int)($decoded['pid'] ?? $session->pid),
            port: $port,
            launchNonce: (string)($decoded['launch_nonce'] ?? $session->launchNonce),
            leaseId: $leaseId,
            generation: $generation,
        );
    }

    private function closeSession(int $sessionId): void
    {
        $session = $this->sessions[$sessionId] ?? null;
        if (!$session instanceof SupervisorSession) {
            return;
        }

        if (\is_resource($session->socket)) {
            @\fclose($session->socket);
        }

        unset($this->sessions[$sessionId]);
    }
}
