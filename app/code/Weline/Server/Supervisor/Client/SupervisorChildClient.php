<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor\Client;

use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Policy\DispatcherPolicyControl;
use Weline\Server\Service\Runtime\WorkerReadinessState;
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
    private int $maxReadBufferSize = 2097152;
    private int $maxWriteBufferSize = 2097152;
    private int $maxNdjsonLinesPerReadable = 192;
    private int $heartbeatSeq = 0;
    private float $lastHeartbeatAt = 0.0;
    private float $heartbeatIntervalSec = 5.0;
    private bool $receivedShutdown = false;
    private bool $verboseLog = false;
    private string $selfTag = 'SupervisorChild';
    private bool $readyDesired = false;
    private bool $readyConfirmed = false;
    private string $lastConnectError = '';
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
    private bool $releaseSent = false;

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
        $uri = $endpoint->uri();
        $this->socket = @\stream_socket_client($uri, $errno, $errstr, 3);
        if (!\is_resource($this->socket)) {
            $this->socket = null;
            $this->lastConnectError = \trim("supervisor_uri={$uri}, errno={$errno}, errstr={$errstr}");
            return false;
        }

        $this->lastConnectError = '';
        \stream_set_blocking($this->socket, false);
        @\stream_set_write_buffer($this->socket, 0);
        $this->readBuffer = '';
        $this->writeBuffer = '';
        $this->receivedShutdown = false;
        $this->readyConfirmed = false;
        $this->reconnectFailCount = 0;
        $this->leaseId = '';
        $this->generation = 0;
        $this->heartbeatSeq = 0;
        $this->lastHeartbeatAt = 0.0;

        return true;
    }

    public function isConnected(): bool
    {
        return \is_resource($this->socket);
    }

    public function getLastConnectError(): string
    {
        return $this->lastConnectError;
    }

    public function getSocket()
    {
        return $this->socket;
    }

    public function hasPendingWrites(): bool
    {
        // Worker loops call this before building their writable socket set.
        // Schedule heartbeats here so an otherwise idle control channel does
        // not depend on Master traffic becoming readable first.
        $this->maybeSendHeartbeat();

        return $this->writeBuffer !== '';
    }

    public function hasReceivedShutdown(): bool
    {
        return $this->receivedShutdown;
    }

    public function isReadyStateConfirmed(): bool
    {
        return $this->readyConfirmed;
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

        [$slotId, $leaseId, $generation] = $this->resolveLeaseIdentityFromRuntimeArgs($role, $workerId, $launchId, $epoch);
        $hello = SupervisorMessage::hello(
            instance: $this->instanceName,
            channel: $this->channelId,
            role: $role,
            slotId: $slotId,
            pid: $pid,
            launchNonce: $launchId !== '' ? $launchId : $msgId,
            msgId: $msgId !== '' ? $msgId : $launchId,
            leaseId: $leaseId,
            generation: $generation,
            authSecret: $this->resolveHelloAuthSecret(),
        );

        if (!$this->sendRaw($hello)) {
            return false;
        }

        $response = $this->waitForResponse(SupervisorMessage::TYPE_LEASE_ASSIGN, 2.0);
        if (!\is_array($response)) {
            return false;
        }

        $this->leaseId = (string)($response['lease_id'] ?? '');
        $this->generation = (int)($response['generation'] ?? 0);
        $this->heartbeatSeq = 0;
        $this->lastHeartbeatAt = \microtime(true);
        $this->readyConfirmed = false;
        $this->releaseSent = false;

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
        $this->readyDesired = $isReady;
        if (!$isReady) {
            $this->readyConfirmed = false;
        }
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

        $readiness = match (true) {
            \in_array($role, [ControlMessage::ROLE_WORKER, ControlMessage::ROLE_MAINTENANCE], true)
                => WorkerReadinessState::snapshot(),
            $role === ControlMessage::ROLE_DISPATCHER
                => DispatcherPolicyControl::readinessSnapshot(),
            default => [],
        };
        $ready = SupervisorMessage::ready(
            slotId: $this->buildSlotId($role, $workerId),
            leaseId: $this->leaseId,
            generation: $this->generation,
            port: $port,
            msgId: $msgId !== '' ? $msgId : $this->leaseId,
            channel: $this->channelId,
            readiness: $readiness,
        );
        if (!$this->sendRaw($ready)) {
            $this->readyConfirmed = false;
            return false;
        }

        $response = $this->waitForResponse(
            SupervisorMessage::TYPE_READY_ACK,
            ControlMessage::READY_CONFIRM_TIMEOUT_SEC,
        );
        if (!\is_array($response)
            || !($response['accepted'] ?? false)
            || \strtolower(\trim((string)($response['ready_phase'] ?? 'final'))) !== 'final'
        ) {
            $this->readyConfirmed = false;
            return false;
        }

        $this->readyDesired = true;
        $this->readyConfirmed = true;
        $this->poolSnapshotVersion = (int)($response['pool_snapshot_version'] ?? 0);

        return true;
    }

    public function sendWorkerLoopStarted(int $workerId, int $port, int $pid): bool
    {
        return $this->send(ControlMessage::workerLoopStarted($workerId, $port, $pid));
    }

    public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = '', string $reason = ''): bool
    {
        if ($workerId === 0 && $this->registerInfo !== null) {
            $workerId = (int)$this->registerInfo['worker_id'];
            $port = (int)$this->registerInfo['port'];
            $msgId = (string)($this->registerInfo['msg_id'] ?? $msgId);
        }

        return $this->send(ControlMessage::drainingComplete($workerId, $port, $msgId, $reason));
    }

    public function sendStatusReport(int $connections, int $memory, int $requests): bool
    {
        return $this->send(ControlMessage::statusReport($connections, $memory, $requests));
    }

    public function sendLogLine(string $line, string $level, string $processTag): bool
    {
        return $this->send(ControlMessage::logLine($line, $level, $processTag), false);
    }

    public function send(string $message, bool $disconnectOnWriteOverflow = true): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        if (!$this->enqueueWrite($message)) {
            if ($disconnectOnWriteOverflow) {
                $this->handleDisconnect();
            }
            return false;
        }

        // Ordinary child events are hot-path best effort. Queue them and only
        // consume the currently writable window; the event loop drains the rest.
        $this->flushPendingWrites();
        return true;
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
        $this->maybeSendHeartbeat();

        // Some daemon loops poll this method directly instead of first
        // including the control socket in their own stream_select set. On
        // Windows a non-blocking fread with no available bytes may look like
        // EOF, which caused an idle Runtime Watchdog to disconnect and be
        // resurrected continuously. Make an idle poll a no-op while retaining
        // the normal EOF path once the socket is actually readable.
        $read = [$this->socket];
        $write = [];
        $except = [];
        $changed = @\stream_select($read, $write, $except, 0, 0);
        if ($changed === false) {
            return [];
        }
        if ($changed === 0) {
            return $this->extractMessages();
        }

        $data = @\fread($this->socket, 65536);
        if ($data === false) {
            if (!@\feof($this->socket)) {
                return $this->extractMessages();
            }
            $this->handleDisconnect();
            return [];
        }

        if ($data === '' && @\feof($this->socket)) {
            $this->handleDisconnect();
            return [];
        }
        if ($data !== '') {
            if ((\strlen($this->readBuffer) + \strlen($data)) > $this->maxReadBufferSize) {
                $this->handleDisconnect();
                return [];
            }
            $this->readBuffer .= $data;
        }

        return $this->extractMessages();
    }

    public function handleWritable(): bool
    {
        $this->maybeSendHeartbeat();

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
            if ($this->readyDesired) {
                return $this->sendReady();
            }
        }

        return true;
    }

    public function close(): void
    {
        $this->sendLeaseReleaseIfShutdownReceived();
        if (\is_resource($this->socket)) {
            @\fclose($this->socket);
        }
        $this->socket = null;
        $this->readBuffer = '';
        $this->writeBuffer = '';
        $this->readyConfirmed = false;
        $this->leaseId = '';
        $this->generation = 0;
        $this->heartbeatSeq = 0;
        $this->lastHeartbeatAt = 0.0;
    }

    /**
     * Supervisor 模式不参与子进程自愈（由 Supervisor 统一负责生命周期）
     */
    public function getResurrectionPriority(): int
    {
        return 0;
    }

    private function buildSlotId(string $role, int $workerId): string
    {
        return match ($role) {
            'worker' => 'worker#' . ($workerId > 0 ? $workerId : 1),
            'maintenance' => 'maintenance#' . ($workerId > 0 ? $workerId : 1),
            default => $role . '#1',
        };
    }

    /**
     * @return array{0:string,1:string,2:int}
     */
    private function resolveLeaseIdentityFromRuntimeArgs(string $role, int $workerId, string $launchId, int $epoch): array
    {
        $slotId = $this->buildSlotId($role, $workerId);
        $leaseId = $launchId !== '' ? $launchId : (string)($this->registerInfo['lease_id'] ?? '');
        $generation = $epoch > 0 ? $epoch : (int)($this->registerInfo['generation'] ?? 0);
        $argv = $GLOBALS['argv'] ?? ($_SERVER['argv'] ?? []);
        if (!\is_array($argv)) {
            return [$slotId, $leaseId, $generation];
        }

        foreach ($argv as $arg) {
            $arg = (string)$arg;
            if (\str_starts_with($arg, '--slot-id=')) {
                $value = (string)\substr($arg, 10);
                if ($value !== '') {
                    $slotId = $value;
                }
                continue;
            }
            if (\str_starts_with($arg, '--lease-id=')) {
                $value = (string)\substr($arg, 11);
                if ($value !== '') {
                    $leaseId = $value;
                }
                continue;
            }
            if (\str_starts_with($arg, '--slot-generation=')) {
                $value = (int)\substr($arg, 18);
                if ($value > 0) {
                    $generation = $value;
                }
            }
        }

        return [$slotId, $leaseId, $generation];
    }

    private function resolveHelloAuthSecret(): string
    {
        $environmentSecret = (string)(\getenv('WLS_MASTER_TOKEN') ?: '');
        $argv = $GLOBALS['argv'] ?? ($_SERVER['argv'] ?? []);
        if (!\is_array($argv)) {
            return $environmentSecret;
        }
        foreach ($argv as $arg) {
            $arg = (string)$arg;
            if (!\str_starts_with($arg, '--master-token=')) {
                continue;
            }
            $secret = (string)\substr($arg, 15);
            if ($secret !== '') {
                return $secret;
            }
        }

        return $environmentSecret;
    }

    private function sendRaw(string $message): bool
    {
        if (!$this->enqueueWrite($message)) {
            return false;
        }
        return $this->flushPendingWrites(0.5);
    }

    private function enqueueWrite(string $message): bool
    {
        if ($message === '') {
            return true;
        }
        if ((\strlen($this->writeBuffer) + \strlen($message)) > $this->maxWriteBufferSize) {
            return false;
        }

        $this->writeBuffer .= $message;
        return true;
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
        while (\count($messages) < $this->maxNdjsonLinesPerReadable
            && ($newlinePos = \strpos($this->readBuffer, "\n")) !== false) {
            $line = \substr($this->readBuffer, 0, $newlinePos + 1);
            $this->readBuffer = (string)\substr($this->readBuffer, $newlinePos + 1);
            $decoded = SupervisorMessage::decode($line);
            if ($decoded === []) {
                continue;
            }
            if (($decoded['type'] ?? '') === SupervisorMessage::TYPE_CHANNEL_REJECT) {
                $this->readyConfirmed = false;
                $this->close();
                continue;
            }
            if (($decoded['type'] ?? '') === SupervisorMessage::TYPE_LEASE_ASSIGN) {
                $this->leaseId = (string)($decoded['lease_id'] ?? '');
                $this->generation = (int)($decoded['generation'] ?? 0);
                $this->readyConfirmed = false;
            }
            if (($decoded['type'] ?? '') === SupervisorMessage::TYPE_READY_ACK) {
                $readyPhase = \strtolower(\trim((string)($decoded['ready_phase'] ?? 'final')));
                $this->readyConfirmed = (bool)($decoded['accepted'] ?? false)
                    && $readyPhase === 'final';
                $this->poolSnapshotVersion = (int)($decoded['pool_snapshot_version'] ?? $this->poolSnapshotVersion);
            }
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SHUTDOWN) {
                $this->receivedShutdown = true;
            }
            if (($decoded['type'] ?? '') === SupervisorMessage::TYPE_LEASE_RELEASE_ACK
                && ($decoded['accepted'] ?? false)) {
                $this->releaseSent = true;
            }
            if ($this->messageHandler !== null) {
                ($this->messageHandler)($decoded, $this);
            }
            $messages[] = $decoded;
        }

        return $messages;
    }

    private function maybeSendHeartbeat(): void
    {
        if ($this->leaseId === '' || $this->generation <= 0 || $this->registerInfo === null) {
            return;
        }
        $now = \microtime(true);
        if (($now - $this->lastHeartbeatAt) < $this->heartbeatIntervalSec) {
            return;
        }
        $role = (string)($this->registerInfo['role'] ?? '');
        $workerId = (int)($this->registerInfo['worker_id'] ?? 0);
        $this->heartbeatSeq++;
        if (!$this->send(SupervisorMessage::heartbeat(
            $this->buildSlotId($role, $workerId),
            $this->leaseId,
            $this->generation,
            $this->heartbeatSeq,
            channel: $this->channelId,
        ), false)) {
            $this->heartbeatSeq--;
            return;
        }
        $this->lastHeartbeatAt = $now;
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
            if (!@\feof($socket)) {
                return 0;
            }
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

    private function sendLeaseReleaseIfShutdownReceived(): void
    {
        if (!$this->receivedShutdown
            || $this->releaseSent
            || $this->registerInfo === null
            || $this->leaseId === ''
            || $this->generation <= 0
            || !$this->isConnected()) {
            return;
        }

        $role = (string)$this->registerInfo['role'];
        $workerId = (int)$this->registerInfo['worker_id'];
        $message = SupervisorMessage::leaseRelease(
            slotId: $this->buildSlotId($role, $workerId),
            leaseId: $this->leaseId,
            generation: $this->generation,
            msgId: $this->leaseId,
            channel: $this->channelId,
        );
        $this->releaseSent = true;
        $this->sendRaw($message);
    }
}
