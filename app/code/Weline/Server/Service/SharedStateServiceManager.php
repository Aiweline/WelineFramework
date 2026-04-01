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
use Weline\Server\Shared\Connection\ConnectionPoolManager;

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
    public function ensureRuntime(string $requesterInstanceName, array $config, array $envConfig = [], bool $frontend = false): array
    {
        $runtime = [
            'session' => $this->ensure(ControlMessage::ROLE_SESSION_SERVER, $config, $envConfig, $requesterInstanceName, $frontend),
        ];

        if ($this->isMemoryEnabled($config, $envConfig)) {
            $runtime['memory'] = $this->ensure(ControlMessage::ROLE_MEMORY_SERVER, $config, $envConfig, $requesterInstanceName, $frontend);
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
        string $requesterInstanceName = 'system',
        bool $frontend = false
    ): array {
        $definition = $this->buildRoleDefinition($role, $requesterInstanceName, $config, $envConfig);

        return $this->withRoleLock((string) $definition['role'], function () use ($definition, $requesterInstanceName, $frontend): array {
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
                if (\defined('IS_WIN') && IS_WIN) {
                    // Windows 上强制杀进程后端口进入 TIME_WAIT，过短间隔内子进程 bind 可能失败。
                    SchedulerSystem::usleep(500_000);
                }
            }

            $pid = $this->launchSharedServiceProcess($definition, $requesterInstanceName, $frontend);
            if ($pid <= 0) {
                throw new \RuntimeException($this->buildSharedSpawnFailureMessage($definition));
            }

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
        string $requesterInstanceName = 'system',
        bool $frontend = false
    ): array {
        return $this->restart($role, $config, $envConfig, $requesterInstanceName, $frontend);
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
        string $requesterInstanceName = 'system',
        bool $frontend = false
    ): array {
        $definition = $this->buildRoleDefinition($role, $requesterInstanceName, $config, $envConfig);

        return $this->withRoleLock((string) $definition['role'], function () use ($definition, $requesterInstanceName, $frontend): array {
            $this->forceStopReusedService($definition, []);
            if (\defined('IS_WIN') && IS_WIN) {
                SchedulerSystem::usleep(500_000);
            }
            $pid = $this->launchSharedServiceProcess($definition, $requesterInstanceName, $frontend);
            if ($pid <= 0) {
                throw new \RuntimeException($this->buildSharedSpawnFailureMessage($definition));
            }

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

        ConnectionPoolManager::discardPool(
            (string) $definition['host'],
            (int) $definition['port'],
            (string) ($definition['token_file_name'] ?? '')
        );

        $deadline = \microtime(true) + $timeoutSec;
        $startedAt = \date('c');

        // 渐进式轮询：快速探测 -> 逐步放缓（50ms -> 100ms -> 150ms -> 200ms）
        $pollIntervals = [50_000, 100_000, 150_000, 200_000];
        $pollIndex = 0;

        while (\microtime(true) < $deadline) {
            $sleepUs = $pollIntervals[\min($pollIndex, \count($pollIntervals) - 1)];
            SchedulerSystem::usleep($sleepUs);
            $pollIndex++;

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
                'Shared %s service failed to become ready on %s:%d. 请查看进程日志（进程名 %s）与 %s；若需释放共享侧车可执行 php bin/w server:shared:stop 后重试。',
                $this->displayNameForRole((string) $definition['role']),
                (string) $definition['host'],
                (int) $definition['port'],
                (string) ($definition['process_name'] ?? ''),
                $this->formatSharedTokenFilePathForMessage((string) ($definition['token_file_name'] ?? ''))
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
    protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName, bool $frontend = false): int
    {
        $command = $this->buildLaunchCommand($definition, $requesterInstanceName);
        $cmdLineForRegistry = $command->build();
        $processName = $command->getProcessName();
        if ($processName !== null && $processName !== '') {
            $cmdLineForRegistry .= ' --name=' . \escapeshellarg($processName);
        }

        // Windows：优先用 Start-Process -ArgumentList 数组拉起 PHP，避免中文 BP 下「整段命令行」编码损坏导致 PID=0。
        // 与 Framework Processer 需同版本部署；旧版无 createWindowsDetachedPhpArgv 时回退 create()。
        if (\defined('IS_WIN') && IS_WIN && \method_exists(Processer::class, 'createWindowsDetachedPhpArgv')) {
            $argv = \array_merge(
                [PHP_BINARY, $command->getAbsoluteScript()],
                \array_map(static fn (mixed $a): string => (string) $a, $command->arguments)
            );
            if ($processName !== null && $processName !== '') {
                $argv[] = '--name=' . $processName;
            }
            if ($frontend) {
                $argv[] = '--frontend';
            }
            $pid = Processer::createWindowsDetachedPhpArgv(
                $argv,
                $command->getWorkingDir(),
                $cmdLineForRegistry,
                true
            );
            if ($pid > 0) {
                return $pid;
            }
        }

        // enableLog=true：失败原因写入 Processer 进程日志。
        if ($frontend) {
            $cmdLineForRegistry .= ' --frontend';
        }
        return Processer::create($cmdLineForRegistry, block: false, foreground: $frontend, enableLog: true);
    }

    /**
     * 共享侧车子进程未获得 PID 时的可读错误（避免空等 ensure 超时）。
     */
    private function buildSharedSpawnFailureMessage(array $definition): string
    {
        $role = (string) $definition['role'];
        $host = (string) $definition['host'];
        $port = (int) $definition['port'];
        $proc = (string) ($definition['process_name'] ?? '');
        $token = (string) ($definition['token_file_name'] ?? '');

        return \sprintf(
            '无法拉起共享 %s 子进程（Processer::create 返回 PID=0），目标 %s:%d，进程名 %s。请检查 PowerShell 执行策略、杀毒软件拦截、以及 Processer 为该进程名生成的日志；BP=%s',
            $this->displayNameForRole($role),
            $host,
            $port,
            $proc,
            BP
        ) . ($token !== '' ? '；token 文件应为 ' . $this->formatSharedTokenFilePathForMessage($token) : '');
    }

    /**
     * 与 SessionServer / PooledConnection 一致：BP/var/session/{token_file_name}
     */
    private function formatSharedTokenFilePathForMessage(string $tokenFileName): string
    {
        $tokenFileName = \trim($tokenFileName);
        if ($tokenFileName === '') {
            $tokenFileName = 'session_server.token';
        }

        return Env::VAR_DIR . 'session' . \DIRECTORY_SEPARATOR . $tokenFileName;
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
            $memoryPortExplicit = \array_key_exists('memory_server_port', $config)
                || \array_key_exists('port', $memoryConfig);

            // 默认端口 19971 + 项目偏移量，确保多项目不冲突
            $defaultPort = 19971 + MasterProcess::getProjectPortOffset();
            $port = (int) ($config['memory_server_port'] ?? $memoryConfig['port'] ?? $defaultPort);
            if ($port <= 0) {
                $port = $defaultPort;
            }

            $tokenFileName = \trim((string) (
                $config['memory_server_token_file_name']
                ?? $memoryConfig['token_file_name']
                ?? 'memory_server.token'
            ));
            if ($tokenFileName === '') {
                $tokenFileName = 'memory_server.token';
            }

            $port = $this->resolveSharedServicePort(
                $role,
                $port,
                $tokenFileName,
                $memoryPortExplicit
            );

            return [
                'role' => $role,
                'display_name' => 'Memory Service',
                'host' => '127.0.0.1',
                'port' => $port,
                'token_file_name' => $tokenFileName,
                'process_name' => MemoryServerProvider::PROCESS_NAME_PREFIX . '-' . MasterProcess::getProjectScopeToken() . '-shared-' . $port,
                'service_instance_name' => 'shared-memory-' . MasterProcess::getProjectScopeToken() . '-' . $port,
                'requester_instance_name' => $requesterInstanceName,
                'ensure_timeout_sec' => $ensureTimeoutSec,
                'ensure_poll_interval_ms' => $ensurePollIntervalMs,
            ];
        }

        $sessionConfig = \is_array($envConfig['session'] ?? null) ? $envConfig['session'] : [];
        $wlsSession = \is_array($wlsConfig['session'] ?? null) ? $wlsConfig['session'] : [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];
        $sessionPortExplicit = \array_key_exists('session_server_port', $config)
            || \array_key_exists('port', $wlsServer)
            || \array_key_exists('port', $wlsSession)
            || \array_key_exists('server_port', $sessionConfig);

        // 默认端口 19970 + 项目偏移量，确保多项目不冲突
        $defaultPort = 19970 + MasterProcess::getProjectPortOffset();
        $port = (int) (
            $config['session_server_port']
            ?? $wlsServer['port']
            ?? $wlsSession['port']
            ?? $sessionConfig['server_port']
            ?? $defaultPort
        );
        if ($port <= 0) {
            $port = $defaultPort;
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

        $port = $this->resolveSharedServicePort(
            $role,
            $port,
            $tokenFileName,
            $sessionPortExplicit
        );

        return [
            'role' => $role,
            'display_name' => 'Session Server',
            'host' => '127.0.0.1',
            'port' => $port,
            'token_file_name' => $tokenFileName,
            'process_name' => SessionServerProvider::PROCESS_NAME_PREFIX . '-' . MasterProcess::getProjectScopeToken() . '-shared-' . $port,
            'service_instance_name' => 'shared-session-' . MasterProcess::getProjectScopeToken() . '-' . $port,
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

    private function resolveSharedServicePort(
        string $role,
        int $preferredPort,
        string $tokenFileName,
        bool $explicitConfigured
    ): int {
        if ($preferredPort <= 0) {
            $preferredPort = $this->defaultPortForRole($role);
        }

        if ($explicitConfigured) {
            return $preferredPort;
        }

        $runtime = $this->readRuntimeFile($role);
        $runtimePort = (int) ($runtime['port'] ?? 0);
        if ($runtimePort > 0 && $this->isPortCandidateReusable($role, $runtimePort, $tokenFileName)) {
            return $runtimePort;
        }

        if ($this->isPortCandidateReusable($role, $preferredPort, $tokenFileName)) {
            return $preferredPort;
        }

        $start = \max(1025, $preferredPort + 1);
        $limit = 512;
        $port = $start;
        for ($i = 0; $i < $limit; $i++, $port++) {
            if ($port > 65535) {
                break;
            }

            if ($this->isPortCandidateReusable($role, $port, $tokenFileName)) {
                return $port;
            }
        }

        return $preferredPort;
    }

    private function isPortCandidateReusable(string $role, int $port, string $tokenFileName): bool
    {
        if ($port <= 0) {
            return false;
        }

        if (!Processer::isPortInUse($port)) {
            return true;
        }

        $inspection = $this->inspectRunningSharedService(
            [
                'role' => $this->normalizeRoleName($role),
                'port' => $port,
            ],
            $tokenFileName
        );

        return (bool) ($inspection['reusable'] ?? false) && $this->isInspectionOwnedByCurrentProject($inspection);
    }

    /**
     * 仅复用带当前项目作用域标识的共享服务，避免跨项目误复用/误停服。
     *
     * @param array<string, mixed> $inspection
     */
    private function isInspectionOwnedByCurrentProject(array $inspection): bool
    {
        $scope = MasterProcess::getProjectScopeToken();
        $instanceName = (string) ($inspection['instance_name'] ?? '');
        $processName = (string) ($inspection['process_name'] ?? '');
        if ($scope === '') {
            return false;
        }

        return \str_contains($instanceName, '-' . $scope . '-') || \str_contains($processName, '-' . $scope . '-');
    }
}
