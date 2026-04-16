<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor\Client;

use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\Supervisor\Endpoint\ControlEndpoint;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;

final class SupervisorChildClient implements ChildControlClientInterface
{
    /**
     * @var resource|null
     */
    private $socket = null;

    private string $readBuffer = '';
    private string $writeBuffer = '';
    private bool $receivedShutdown = false;
    private bool $verboseLog = false;
    private string $selfTag = 'SupervisorChild';
    private bool $isReady = false;
    private int $reconnectFailCount = 0;
    private float $lastReconnectAt = 0.0;
    private float $reconnectIntervalSec = 2.0;

    /**
     * @var null|callable(array, self): void
     */
    private $messageHandler = null;

    /**
     * @var null|callable(bool, self): void
     */
    private $disconnectHandler = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $registerInfo = null;

    private string $leaseId = '';
    private int $generation = 0;
    private int $poolSnapshotVersion = 0;

    public function __construct(
        private readonly string $instanceName,
        private readonly string $channelId,
        private readonly ControlEndpointResolver $endpointResolver,
        private readonly ?ControlEndpoint $endpoint = null,
        private readonly mixed $progressCallback = null,
    ) {
    }

    public function connect(string $host, int $port): bool
    {
        unset($host, $port);
        $endpoint = $this->endpoint ?? $this->endpointResolver->resolve($this->instanceName);
        $errno = 0;
        $errstr = '';
        $this->socket = @\stream_socket_client($endpoint->uri(), $errno, $errstr, 3);
        if (!\is_resource($this->socket)) {
            $this->socket = null;
            return false;
        }

        \stream_set_blocking($this->socket, false);
        @\stream_set_write_buffer($this->socket, 0);
        $this->readBuffer = '';
        $this->writeBuffer = '';
        $this->receivedShutdown = false;
        $this->reconnectFailCount = 0;

        return true;
    }

    public function isConnected(): bool
    {
        return \is_resource($this->socket);
    }

    public function getSocket()
    {
        return $this->socket;
    }

    public function hasPendingWrites(): bool
    {
        return $this->writeBuffer !== '';
    }

    public function hasReceivedShutdown(): bool
    {
        return $this->receivedShutdown;
    }

    public function isReadyStateConfirmed(): bool
    {
        return $this->isReady;
    }

    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    public function onDisconnect(callable $handler): void
    {
        $this->disconnectHandler = $handler;
    }

    public function setVerboseLog(bool $verbose): void
    {
        $this->verboseLog = $verbose;
    }

    public function setSelfTag(string $tag): void
    {
        $this->selfTag = $tag;
    }

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
    ): bool {
        $this->rememberRegistration($role, $pid, $port, $workerId, $epoch, $launchId, $processKind, $moduleCode, $instanceCode, $msgId);
        if (!$this->isConnected()) {
            return false;
        }

        $hello = SupervisorMessage::hello(
            instance: $this->instanceName,
            channel: $this->channelId,
            role: $role,
            slotId: $this->buildSlotId($role, $workerId),
            pid: $pid,
            launchNonce: $launchId !== '' ? $launchId : $msgId,
            msgId: $msgId !== '' ? $msgId : $launchId,
        );

        if (!$this->sendRaw($hello)) {
            return false;
        }

        $response = $this->waitForResponse('lease_assign', 2.0);
        if (!\is_array($response)) {
            return false;
        }

        $this->leaseId = (string)($response['lease_id'] ?? '');
        $this->generation = (int)($response['generation'] ?? 0);

        return $this->leaseId !== '' && $this->generation > 0;
    }

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
    ): void {
        $this->registerInfo = [
            'role' => $role,
            'pid' => $pid,
            'port' => $port,
            'worker_id' => $workerId,
            'epoch' => $epoch,
            'launch_id' => $launchId,
            'process_kind' => $processKind,
            'module_code' => $moduleCode,
            'instance_code' => $instanceCode,
            'msg_id' => $msgId,
        ];
    }

    public function markReadyState(bool $isReady = true): void
    {
        $this->isReady = $isReady;
    }

    public function sendReady(
        string $role = '',
        int $workerId = 0,
        int $port = 0,
        int $epoch = 0,
        string $launchId = '',
        string $msgId = ''
    ): bool {
        unset($epoch, $launchId);
        if ($role === '' && $this->registerInfo !== null) {
            $role = (string)$this->registerInfo['role'];
            $workerId = (int)$this->registerInfo['worker_id'];
            $port = (int)$this->registerInfo['port'];
            $msgId = (string)($this->registerInfo['msg_id'] ?? $msgId);
        }

        if (!$this->isConnected() || $this->leaseId === '' || $this->generation <= 0) {
            return false;
        }

        $ready = SupervisorMessage::ready(
            slotId: $this->buildSlotId($role, $workerId),
            leaseId: $this->leaseId,
            generation: $this->generation,
            port: $port,
            msgId: $msgId !== '' ? $msgId : $this->leaseId,
            channel: $this->channelId,
        );
        if (!$this->sendRaw($ready)) {
            return false;
        }

        $response = $this->waitForResponse('ready_ack', 2.0);
        if (!\is_array($response) || !($response['accepted'] ?? false)) {
            return false;
        }

        $this->isReady = true;
        $this->poolSnapshotVersion = (int)($response['pool_snapshot_version'] ?? 0);

        return true;
    }

    public function sendWorkerLoopStarted(int $workerId, int $port, int $pid): bool
    {
        unset($workerId, $port, $pid);
        return true;
    }

    public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = ''): bool
    {
        unset($workerId, $port, $msgId);
        return true;
    }

    public function sendStatusReport(int $connections, int $memory, int $requests): bool
    {
        unset($connections, $memory, $requests);
        return true;
    }

    public function sendLogLine(string $line, string $level, string $processTag): bool
    {
        unset($line, $level, $processTag);
        return true;
    }

    public function send(string $message, bool $disconnectOnWriteOverflow = true): bool
    {
        unset($disconnectOnWriteOverflow);
        return $this->sendRaw($message);
    }

    public function flushPendingWrites(float $timeBudgetSec = 0.0): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        if ($this->writeBuffer === '') {
            return true;
        }

        $deadline = $timeBudgetSec > 0.0 ? (\microtime(true) + $timeBudgetSec) : 0.0;
        do {
            $written = $this->flushWriteBufferChunk($deadline);
            if ($written < 0) {
                $this->handleDisconnect();
                return false;
            }
            if ($written === 0) {
                break;
            }
        } while ($this->writeBuffer !== '' && $deadline > 0.0 && \microtime(true) < $deadline);

        return $this->writeBuffer === '';
    }

    public function handleReadable(): array
    {
        if (!$this->isConnected()) {
            return [];
        }
        $data = @\fread($this->socket, 65536);
        if ($data === false || ($data === '' && @\feof($this->socket))) {
            $this->handleDisconnect();
            return [];
        }
        if ($data !== '') {
            $this->readBuffer .= $data;
        }

        return $this->extractMessages();
    }

    public function handleWritable(): bool
    {
        return $this->flushPendingWrites();
    }

    public function tryReconnect(): bool
    {
        if ($this->isConnected()) {
            return true;
        }
        $now = \microtime(true);
        if (($now - $this->lastReconnectAt) < $this->reconnectIntervalSec) {
            return false;
        }
        $this->lastReconnectAt = $now;

        if (!$this->connect('127.0.0.1', 0)) {
            $this->reconnectFailCount++;
            return false;
        }

        if ($this->registerInfo !== null) {
            $registered = $this->register(
                (string)$this->registerInfo['role'],
                (int)$this->registerInfo['pid'],
                (int)$this->registerInfo['port'],
                (int)$this->registerInfo['worker_id'],
                (int)$this->registerInfo['epoch'],
                (string)$this->registerInfo['launch_id'],
                (string)$this->registerInfo['process_kind'],
                (string)$this->registerInfo['module_code'],
                (string)$this->registerInfo['instance_code'],
                '',
            );
            if (!$registered) {
                return false;
            }
            if ($this->isReady) {
                return $this->sendReady();
            }
        }

        return true;
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            @\fclose($this->socket);
        }
        $this->socket = null;
        $this->readBuffer = '';
        $this->writeBuffer = '';
    }

    private function buildSlotId(string $role, int $workerId): string
    {
        return match ($role) {
            'worker' => 'worker#' . ($workerId > 0 ? $workerId : 1),
            'maintenance' => 'maintenance#' . ($workerId > 0 ? $workerId : 1),
            default => $role . '#1',
        };
    }

    private function sendRaw(string $message): bool
    {
        $this->writeBuffer .= $message;
        return $this->flushPendingWrites(0.5);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function waitForResponse(string $type, float $timeoutSec): ?array
    {
        $deadline = \microtime(true) + $timeoutSec;
        while (\microtime(true) < $deadline) {
            if (\is_callable($this->progressCallback)) {
                ($this->progressCallback)();
            }
            foreach ($this->handleReadable() as $message) {
                if (($message['type'] ?? '') === $type) {
                    return $message;
                }
            }
            \usleep(10000);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractMessages(): array
    {
        $messages = [];
        while (($newlinePos = \strpos($this->readBuffer, "\n")) !== false) {
            $line = \substr($this->readBuffer, 0, $newlinePos + 1);
            $this->readBuffer = (string)\substr($this->readBuffer, $newlinePos + 1);
            $decoded = SupervisorMessage::decode($line);
            if ($decoded === []) {
                continue;
            }
            if (($decoded['type'] ?? '') === SupervisorMessage::TYPE_CHANNEL_REJECT) {
                $this->close();
                continue;
            }
            if (($decoded['type'] ?? '') === SupervisorMessage::TYPE_LEASE_ASSIGN) {
                $this->leaseId = (string)($decoded['lease_id'] ?? '');
                $this->generation = (int)($decoded['generation'] ?? 0);
            }
            if (($decoded['type'] ?? '') === SupervisorMessage::TYPE_READY_ACK) {
                $this->poolSnapshotVersion = (int)($decoded['pool_snapshot_version'] ?? $this->poolSnapshotVersion);
            }
            if ($this->messageHandler !== null) {
                ($this->messageHandler)($decoded, $this);
            }
            $messages[] = $decoded;
        }

        return $messages;
    }

    private function flushWriteBufferChunk(float $deadline = 0.0): int
    {
        if (!$this->isConnected() || $this->writeBuffer === '') {
            return 0;
        }

        $socket = $this->socket;
        $read = [];
        $write = [$socket];
        $except = [];
        if ($deadline > 0.0) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0.0) {
                return 0;
            }
            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1000000);
        } else {
            $sec = 0;
            $usec = 0;
        }

        $ready = @\stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false) {
            return -1;
        }
        if ($ready === 0) {
            return 0;
        }

        $written = @\fwrite($socket, \substr($this->writeBuffer, 0, 65536));
        if ($written === false) {
            return -1;
        }
        if ($written === 0) {
            return 0;
        }
        $this->writeBuffer = (string)\substr($this->writeBuffer, $written);

        return $written;
    }

    private function handleDisconnect(): void
    {
        $this->close();
        if ($this->disconnectHandler !== null) {
            ($this->disconnectHandler)($this->receivedShutdown, $this);
        }
    }
}
