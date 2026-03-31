<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Provider\MemoryServerProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Shared\Client\SharedStateClient;

class SharedStateServiceManager
{
    private const DEFAULT_ENSURE_TIMEOUT_SEC = 30.0;
    private const DEFAULT_ENSURE_POLL_INTERVAL_MS = 100;

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array{
     *   session: array<string, mixed>,
     *   memory: array<string, mixed>
     * }
     */
    public function ensureRuntime(string $requesterInstanceName, array $config, array $envConfig = []): array
    {
        $runtime = [
            'session' => $this->ensure(ControlMessage::ROLE_SESSION_SERVER, $config, $envConfig, $requesterInstanceName),
        ];

        if ($this->isMemoryEnabled($config, $envConfig)) {
            $runtime['memory'] = $this->ensure(ControlMessage::ROLE_MEMORY_SERVER, $config, $envConfig, $requesterInstanceName);
        } else {
            $runtime['memory'] = $this->buildRoleDefinition(
                ControlMessage::ROLE_MEMORY_SERVER,
                $requesterInstanceName,
                $config,
                $envConfig
            ) + [
                'enabled' => false,
                'healthy' => false,
                'shared_service' => false,
            ];
        }

        return $runtime;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    public function ensure(
        string $role,
        array $config = [],
        array $envConfig = [],
        string $requesterInstanceName = 'system'
    ): array {
        $definition = $this->buildRoleDefinition($role, $requesterInstanceName, $config, $envConfig);

        return $this->withRoleLock((string) $definition['role'], function () use ($definition, $requesterInstanceName): array {
            $probe = $this->probeDefinition($definition);
            if ((bool) ($probe['healthy'] ?? false)) {
                $runtime = \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];
                $runtime['reuse_existing'] = true;
                $runtime['shared_service'] = true;

                $this->writeRuntimeFile((string) $definition['role'], $runtime);

                return $runtime;
            }

            if ((bool) ($probe['unexpected_occupant'] ?? false)) {
                throw new \RuntimeException((string) ($probe['message'] ?? 'Shared service port is occupied.'));
            }

            if ((bool) ($probe['reusable_but_unhealthy'] ?? false)) {
                $this->forceStopReusedService($definition, \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : []);
            }

            $this->launchSharedServiceProcess($definition, $requesterInstanceName);

            return $this->waitUntilServiceReady($definition);
        });
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    public function start(
        string $role,
        array $config = [],
        array $envConfig = [],
        string $requesterInstanceName = 'system'
    ): array {
        return $this->restart($role, $config, $envConfig, $requesterInstanceName);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    public function restart(
        string $role,
        array $config = [],
        array $envConfig = [],
        string $requesterInstanceName = 'system'
    ): array {
        $definition = $this->buildRoleDefinition($role, $requesterInstanceName, $config, $envConfig);

        return $this->withRoleLock((string) $definition['role'], function () use ($definition, $requesterInstanceName): array {
            $this->forceStopReusedService($definition, []);
            $this->launchSharedServiceProcess($definition, $requesterInstanceName);

            return $this->waitUntilServiceReady($definition);
        });
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    public function probe(string $role, array $config = [], array $envConfig = []): array
    {
        $definition = $this->buildRoleDefinition($role, 'system', $config, $envConfig);

        return $this->probeDefinition($definition);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>|array{session:array<string,mixed>,memory:array<string,mixed>}
     */
    public function status(?string $role = null, array $config = [], array $envConfig = []): array
    {
        if ($role === null) {
            return [
                'session' => $this->status(ControlMessage::ROLE_SESSION_SERVER, $config, $envConfig),
                'memory' => $this->isMemoryEnabled($config, $envConfig)
                    ? $this->status(ControlMessage::ROLE_MEMORY_SERVER, $config, $envConfig)
                    : ['enabled' => false, 'healthy' => false],
            ];
        }

        $definition = $this->buildRoleDefinition($role, 'system', $config, $envConfig);
        $probe = $this->probeDefinition($definition);
        $runtime = \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];

        return [
            'role' => (string) $definition['role'],
            'host' => (string) ($runtime['host'] ?? $definition['host']),
            'port' => (int) ($runtime['port'] ?? $definition['port']),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? $definition['token_file_name']),
            'pid' => (int) ($runtime['pid'] ?? 0),
            'healthy' => (bool) ($probe['healthy'] ?? false),
            'started_at' => $runtime['started_at'] ?? null,
            'healthy_at' => $runtime['healthy_at'] ?? null,
            'process_name' => (string) ($runtime['process_name'] ?? $definition['process_name']),
            'instance_name' => (string) ($runtime['instance_name'] ?? $definition['service_instance_name']),
            'message' => (string) ($probe['message'] ?? ''),
            'shared_service' => true,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     */
    public function stop(string $role, array $config = [], array $envConfig = []): bool
    {
        $definition = $this->buildRoleDefinition($role, 'system', $config, $envConfig);

        return $this->withRoleLock((string) $definition['role'], function () use ($definition): bool {
            $stopped = $this->forceStopReusedService($definition, []);
            $this->removeRuntimeFile((string) $definition['role']);

            return $stopped;
        });
    }

    /**
     * 兼容旧调用面：现在等价于 ensure()，不再维护消费者状态。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function acquire(string $role, string $consumerCode = '', array $options = []): array
    {
        $envConfig = \is_array($options['env_config'] ?? null) ? $options['env_config'] : $this->loadEnvConfig();
        $config = \is_array($options['config'] ?? null) ? $options['config'] : [];
        if (\is_array($options['runtime'] ?? null)) {
            return $options['runtime'];
        }

        return $this->ensure($role, $config, $envConfig, $consumerCode !== '' ? $consumerCode : 'system');
    }

    /**
     * 兼容旧调用面：共享服务不再按消费者引用计数关闭。
     *
     * @param array<string, mixed> $options
     * @return array{
     *   released: bool,
     *   local_ref_count: int,
     *   shutdown_scheduled: bool,
     *   runtime?: array<string, mixed>
     * }
     */
    public function release(string $role, string $consumerCode = '', array $options = []): array
    {
        return [
            'released' => true,
            'local_ref_count' => 0,
            'shutdown_scheduled' => false,
            'runtime' => \is_array($options['runtime'] ?? null) ? $options['runtime'] : [],
        ];
    }

    public function releaseInstanceConsumers(string $instanceName): void
    {
    }

    /**
     * @return array{role:string, removed:list<string>, record:array<string, mixed>}
     */
    public function sweepStaleConsumers(string $role): array
    {
        return [
            'role' => $this->normalizeRoleName($role),
            'removed' => [],
            'record' => $this->peekRuntime($role),
        ];
    }

    /**
     * @return array{role:string, removed:list<string>, record:array<string, mixed>, skipped_locked?:bool}
     */
    public function sweepStaleConsumersIfAvailable(string $role): array
    {
        return [
            'role' => $this->normalizeRoleName($role),
            'removed' => [],
            'record' => $this->peekRuntime($role),
            'skipped_locked' => false,
        ];
    }

    /**
     * 兼容旧调用面：共享服务只会在显式 stop/restart 时停掉。
     *
     * @param array<string, mixed> $options
     */
    public function shutdownIfUnused(string $role, array $options = []): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function peekRuntime(string $role): array
    {
        $role = $this->normalizeRoleName($role);
        $shortRole = $this->toShortRole($role);
        $envConfig = $this->loadEnvConfig();
        $definition = $this->buildRoleDefinition($role, 'system', [], $envConfig);
        $record = $this->readRuntimeFile($role);

        return \array_merge(
            [
                'role' => $role,
                'instance_name' => (string) $definition['service_instance_name'],
                'process_name' => (string) $definition['process_name'],
                'host' => (string) $definition['host'],
                'port' => (int) $definition['port'],
                'token_file_name' => (string) $definition['token_file_name'],
                'started_at' => null,
                'healthy_at' => null,
                'healthy' => false,
                'registered' => false,
                'enabled' => $shortRole === 'memory' ? $this->isMemoryEnabled([], $envConfig) : true,
            ],
            $record
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function probeDefinition(array $definition): array
    {
        $role = (string) $definition['role'];
        $configuredTokenFileName = (string) $definition['token_file_name'];
        $runtimeFile = $this->readRuntimeFile($role);
        $healthy = $this->probeRunningSharedService($definition, $configuredTokenFileName);

        if ($healthy) {
            $runtime = $this->buildRuntimeMetadata(
                $definition,
                (int) ($runtimeFile['pid'] ?? 0),
                \is_string($runtimeFile['started_at'] ?? null) ? (string) $runtimeFile['started_at'] : null,
                \date('c')
            );

            return [
                'healthy' => true,
                'runtime' => \array_merge($runtime, ['reuse_existing' => true]),
                'message' => 'Shared service is healthy.',
            ];
        }

        $port = (int) $definition['port'];
        $portOccupied = $this->isPortOccupied($port);
        if (!$portOccupied) {
            $runtime = \array_merge($this->buildRuntimeMetadata($definition, 0, null, null), $runtimeFile);
            $runtime['healthy'] = false;

            return [
                'healthy' => false,
                'runtime' => $runtime,
                'message' => 'Shared service is not running.',
            ];
        }

        $inspection = $this->inspectRunningSharedService($definition, $configuredTokenFileName);
        if (!(bool) ($inspection['reusable'] ?? false)) {
            return [
                'healthy' => false,
                'runtime' => \array_merge($this->buildRuntimeMetadata($definition, 0, null, null), $runtimeFile),
                'unexpected_occupant' => true,
                'message' => \sprintf(
                    'Shared %s port %d is occupied by an unexpected process.',
                    $this->displayNameForRole($role),
                    $port
                ),
            ];
        }

        $pid = (int) ($inspection['pid'] ?? 0);
        if ($pid <= 0) {
            $occupant = Processer::inspectPortOccupantWithHistory($port);
            $pid = (int) ($occupant['pid'] ?? 0);
        }

        $runtime = $this->buildRuntimeMetadata(
            $definition,
            $pid,
            \is_string($runtimeFile['started_at'] ?? null) ? (string) $runtimeFile['started_at'] : null,
            $healthy ? \date('c') : (\is_string($runtimeFile['healthy_at'] ?? null) ? (string) $runtimeFile['healthy_at'] : null)
        );

        return [
            'healthy' => false,
            'runtime' => $runtime,
            'reusable_but_unhealthy' => true,
            'message' => 'Shared service process exists but failed health probe.',
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function waitUntilServiceReady(array $definition): array
    {
        $timeoutSec = (float) ($definition['ensure_timeout_sec'] ?? self::DEFAULT_ENSURE_TIMEOUT_SEC);
        $pollMs = (int) ($definition['ensure_poll_interval_ms'] ?? self::DEFAULT_ENSURE_POLL_INTERVAL_MS);
        if ($pollMs <= 0) {
            $pollMs = self::DEFAULT_ENSURE_POLL_INTERVAL_MS;
        }

        $deadline = \microtime(true) + $timeoutSec;
        $startedAt = \date('c');

        while (\microtime(true) < $deadline) {
            SchedulerSystem::usleep($pollMs * 1000);
            $probe = $this->probeDefinition($definition);
            if (!((bool) ($probe['healthy'] ?? false))) {
                continue;
            }

            $runtime = \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];
            $runtime['started_at'] = $startedAt;
            $runtime['healthy_at'] = \date('c');
            $runtime['created_now'] = true;
            $runtime['shared_service'] = true;

            $this->writeRuntimeFile((string) $definition['role'], $runtime);

            return $runtime;
        }

        throw new \RuntimeException(
            \sprintf(
                'Shared %s service failed to become ready on %s:%d.',
                $this->displayNameForRole((string) $definition['role']),
                (string) $definition['host'],
                (int) $definition['port']
            )
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function forceStopReusedService(array $definition, array $runtime): bool
    {
        $role = (string) $definition['role'];
        $record = \array_merge($runtime, [
            'host' => (string) ($runtime['host'] ?? $definition['host']),
            'port' => (int) ($runtime['port'] ?? $definition['port']),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? $definition['token_file_name']),
            'pid' => (int) ($runtime['pid'] ?? 0),
        ]);

        $stopped = $this->forceStopSharedService($record);
        $this->removeRuntimeFile($role);

        return $stopped;
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function forceStopSharedService(array $record): bool
    {
        $pid = (int) ($record['pid'] ?? 0);
        $port = (int) ($record['port'] ?? 0);

        if ($pid > 0) {
            $stopped = Processer::gracefulKill($pid, 1.0, true);
            if (!$stopped) {
                $stopped = Processer::killProcessTreeByPid($pid, true);
            }
            if ($stopped) {
                return true;
            }
        }

        if ($port > 0) {
            return Processer::killProcessByPort($port) || Processer::forceReleasePort($port);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
    {
        return (new SharedSidecarInspector())->inspect(
            (int) $definition['port'],
            (string) $definition['role'],
            $expectedTokenFileName
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
    {
        $client = new SharedStateClient(
            (string) $definition['host'],
            (int) $definition['port'],
            [
                'token_file_name' => $tokenFileName,
                'acquire_timeout' => 0.35,
                'connect_timeout' => 0.85,
                'timeout' => 1.25,
                'log_connect_fail' => false,
            ]
        );

        try {
            return $client->ping();
        } catch (\Throwable) {
            return false;
        } finally {
            $client->disconnect();
        }
    }

    protected function isPortOccupied(int $port): bool
    {
        return $port > 0 && (Processer::isPortUsedByWeline($port) || Processer::isPortInUse($port));
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName): int
    {
        $command = $this->buildLaunchCommand($definition, $requesterInstanceName);
        $cmd = $command->build();
        $processName = $command->getProcessName();
        if ($processName !== null) {
            $cmd .= ' --name=' . \escapeshellarg($processName);
        }

        return Processer::create($cmd, block: false, foreground: false);
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function buildLaunchCommand(array $definition, string $requesterInstanceName): ServiceCommand
    {
        $arguments = [
            (string) $definition['host'],
            (string) $definition['port'],
            (string) $definition['service_instance_name'],
            '--instance-name=' . (string) $definition['service_instance_name'],
            '--token-file-name=' . (string) $definition['token_file_name'],
            '--bootstrap-instance=' . $requesterInstanceName,
            '--shared-service=1',
        ];

        if ((string) $definition['role'] === ControlMessage::ROLE_MEMORY_SERVER) {
            $arguments[] = '--role=' . ControlMessage::ROLE_MEMORY_SERVER;
        }

        return new ServiceCommand(
            script: 'app/code/Weline/Server/bin/session_server.php',
            arguments: $arguments,
            processName: (string) $definition['process_name'],
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    protected function buildRoleDefinition(
        string $role,
        string $requesterInstanceName,
        array $config,
        array $envConfig
    ): array {
        $role = $this->normalizeRoleName($role);
        $wlsConfig = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $sharedState = \is_array($wlsConfig['shared_state'] ?? null) ? $wlsConfig['shared_state'] : [];
        $ensureTimeoutSec = (float) ($sharedState['ensure_timeout_sec'] ?? self::DEFAULT_ENSURE_TIMEOUT_SEC);
        $ensurePollIntervalMs = (int) ($sharedState['ensure_poll_interval_ms'] ?? self::DEFAULT_ENSURE_POLL_INTERVAL_MS);

        if ($role === ControlMessage::ROLE_MEMORY_SERVER) {
            $memoryConfig = \is_array($wlsConfig['memory_service'] ?? null) ? $wlsConfig['memory_service'] : [];
            $port = (int) ($config['memory_server_port'] ?? $memoryConfig['port'] ?? 19971);
            if ($port <= 0) {
                $port = 19971;
            }

            $tokenFileName = \trim((string) (
                $config['memory_server_token_file_name']
                ?? $memoryConfig['token_file_name']
                ?? 'memory_server.token'
            ));
            if ($tokenFileName === '') {
                $tokenFileName = 'memory_server.token';
            }

            return [
                'role' => $role,
                'display_name' => 'Memory Service',
                'host' => '127.0.0.1',
                'port' => $port,
                'token_file_name' => $tokenFileName,
                'process_name' => MemoryServerProvider::PROCESS_NAME_PREFIX . '-shared-' . $port,
                'service_instance_name' => 'shared-memory-' . $port,
                'requester_instance_name' => $requesterInstanceName,
                'ensure_timeout_sec' => $ensureTimeoutSec,
                'ensure_poll_interval_ms' => $ensurePollIntervalMs,
            ];
        }

        $sessionConfig = \is_array($envConfig['session'] ?? null) ? $envConfig['session'] : [];
        $wlsSession = \is_array($wlsConfig['session'] ?? null) ? $wlsConfig['session'] : [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];

        $port = (int) (
            $config['session_server_port']
            ?? $wlsServer['port']
            ?? $wlsSession['port']
            ?? $sessionConfig['server_port']
            ?? 19970
        );
        if ($port <= 0) {
            $port = 19970;
        }

        $tokenFileName = \trim((string) (
            $config['session_server_token_file_name']
            ?? $wlsServer['token_file_name']
            ?? $wlsSession['token_file_name']
            ?? 'session_server.token'
        ));
        if ($tokenFileName === '') {
            $tokenFileName = 'session_server.token';
        }

        return [
            'role' => $role,
            'display_name' => 'Session Server',
            'host' => '127.0.0.1',
            'port' => $port,
            'token_file_name' => $tokenFileName,
            'process_name' => SessionServerProvider::PROCESS_NAME_PREFIX . '-shared-' . $port,
            'service_instance_name' => 'shared-session-' . $port,
            'requester_instance_name' => $requesterInstanceName,
            'ensure_timeout_sec' => $ensureTimeoutSec,
            'ensure_poll_interval_ms' => $ensurePollIntervalMs,
        ];
    }

    /**
     * @param callable(): mixed $callback
     */
    protected function withRoleLock(string $role, callable $callback): mixed
    {
        $lockPath = $this->getRuntimeFilePath($role) . '.ensure.lock';
        $dir = \dirname($lockPath);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $handle = @\fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open shared-state lock file.');
        }

        try {
            if (!\flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock shared-state runtime.');
            }

            return $callback();
        } finally {
            \flock($handle, LOCK_UN);
            @\fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function readRuntimeFile(string $role): array
    {
        $path = $this->getRuntimeFilePath($role);
        if (!\is_file($path)) {
            return [];
        }

        $raw = @\file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = \json_decode($raw, true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $runtime
     */
    protected function writeRuntimeFile(string $role, array $runtime): void
    {
        $path = $this->getRuntimeFilePath($role);
        $payload = [
            'host' => (string) ($runtime['host'] ?? '127.0.0.1'),
            'port' => (int) ($runtime['port'] ?? $this->defaultPortForRole($role)),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? $this->defaultTokenForRole($role)),
            'pid' => (int) ($runtime['pid'] ?? 0),
            'started_at' => $runtime['started_at'] ?? null,
            'healthy_at' => $runtime['healthy_at'] ?? null,
        ];

        if (!ServerInstanceManager::atomicWriteJsonStatic($path, $payload)) {
            throw new \RuntimeException('Unable to persist shared-state runtime file.');
        }
    }

    protected function removeRuntimeFile(string $role): void
    {
        $path = $this->getRuntimeFilePath($role);
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    protected function getRuntimeFilePath(string $role): string
    {
        $shortRole = $this->toShortRole($role);

        return Env::VAR_DIR . 'server' . \DIRECTORY_SEPARATOR . 'shared' . \DIRECTORY_SEPARATOR . $shortRole . '.json';
    }

    protected function loadEnvConfig(): array
    {
        $config = Env::getInstance()->getConfig();

        return \is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     */
    protected function isMemoryEnabled(array $config, array $envConfig): bool
    {
        if (\array_key_exists('memory_server_enabled', $config)) {
            return (bool) $config['memory_server_enabled'];
        }

        return (bool) (($envConfig['wls']['memory_service']['enabled'] ?? true));
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function buildRuntimeMetadata(
        array $definition,
        int $pid,
        ?string $startedAt,
        ?string $healthyAt
    ): array {
        return [
            'role' => (string) $definition['role'],
            'host' => (string) $definition['host'],
            'port' => (int) $definition['port'],
            'token_file_name' => (string) $definition['token_file_name'],
            'pid' => $pid,
            'started_at' => $startedAt,
            'healthy_at' => $healthyAt,
            'process_name' => (string) $definition['process_name'],
            'instance_name' => (string) $definition['service_instance_name'],
            'service_instance_name' => (string) $definition['service_instance_name'],
        ];
    }

    private function normalizeRoleName(string $role): string
    {
        $role = \trim($role);

        return match ($role) {
            'session' => ControlMessage::ROLE_SESSION_SERVER,
            'memory' => ControlMessage::ROLE_MEMORY_SERVER,
            default => $role,
        };
    }

    private function toShortRole(string $role): string
    {
        $role = $this->normalizeRoleName($role);

        return $role === ControlMessage::ROLE_MEMORY_SERVER ? 'memory' : 'session';
    }

    private function defaultPortForRole(string $role): int
    {
        return $this->normalizeRoleName($role) === ControlMessage::ROLE_MEMORY_SERVER ? 19971 : 19970;
    }

    private function defaultTokenForRole(string $role): string
    {
        return $this->normalizeRoleName($role) === ControlMessage::ROLE_MEMORY_SERVER
            ? 'memory_server.token'
            : 'session_server.token';
    }

    private function displayNameForRole(string $role): string
    {
        return $this->normalizeRoleName($role) === ControlMessage::ROLE_MEMORY_SERVER
            ? 'Memory Service'
            : 'Session Server';
    }
}
