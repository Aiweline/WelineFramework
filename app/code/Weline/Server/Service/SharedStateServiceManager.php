<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Provider\MemoryServerProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Shared\Client\SharedStateClient;

class SharedStateServiceManager
{
    private const DEFAULT_ENSURE_TIMEOUT_SEC = 30.0;
    private const DEFAULT_ENSURE_POLL_INTERVAL_MS = 100;
    private const DEFAULT_IDLE_SHUTDOWN_GRACE_SEC = 30;
    private const DEFAULT_EPHEMERAL_CONSUMER_TTL_SEC = 120;
    private const OWNER_TYPE_INSTANCE = 'instance';
    private const OWNER_TYPE_EPHEMERAL = 'ephemeral';

    /**
     * @var array<string, array{count:int, runtime:array<string, mixed>, consumer_code:string, role:string}>
     */
    private static array $localReferences = [];

    private ?SharedStateServiceRegistry $registry = null;
    private ?SharedSidecarInspector $inspector = null;

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
        $session = $this->buildServiceDefinition(
            ControlMessage::ROLE_SESSION_SERVER,
            $requesterInstanceName,
            $config,
            $envConfig
        );
        $memory = $this->buildServiceDefinition(
            ControlMessage::ROLE_MEMORY_SERVER,
            $requesterInstanceName,
            $config,
            $envConfig
        );

        if ((int) $session['port'] === (int) $memory['port']) {
            throw new \RuntimeException(
                \sprintf(
                    'Shared Session and Memory services cannot use the same port %d for instance [%s].',
                    (int) $session['port'],
                    $requesterInstanceName
                )
            );
        }

        return [
            'session' => $this->acquire(ControlMessage::ROLE_SESSION_SERVER, $requesterInstanceName, [
                'owner_type' => self::OWNER_TYPE_INSTANCE,
                'config' => $config,
                'env_config' => $envConfig,
                'service_definition' => $session,
            ]),
            'memory' => $this->acquire(ControlMessage::ROLE_MEMORY_SERVER, $requesterInstanceName, [
                'owner_type' => self::OWNER_TYPE_INSTANCE,
                'config' => $config,
                'env_config' => $envConfig,
                'service_definition' => $memory,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function acquire(string $role, string $consumerCode = '', array $options = []): array
    {
        $role = $this->normalizeRoleName($role);
        $consumerCode = $this->resolveConsumerCode($consumerCode, $options);
        $this->sweepStaleConsumers($role);
        $definition = $this->resolveServiceDefinitionFromOptions($role, $consumerCode, $options);

        $existingKeys = $this->findLocalReferenceKeys($role, $consumerCode, $definition);
        if ($existingKeys !== []) {
            $key = $existingKeys[0];
            self::$localReferences[$key]['count']++;

            return self::$localReferences[$key]['runtime'];
        }

        $runtime = $this->ensureService($definition, $consumerCode);
        $this->registerConsumer($role, $consumerCode, $runtime, $options);

        $localKey = $this->buildLocalReferenceKey($role, $consumerCode, $runtime);
        self::$localReferences[$localKey] = [
            'count' => 1,
            'runtime' => $runtime,
            'consumer_code' => $consumerCode,
            'role' => $role,
        ];

        return $runtime;
    }

    /**
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
        $role = $this->normalizeRoleName($role);
        $consumerCode = $this->resolveConsumerCode($consumerCode, $options);
        $this->sweepStaleConsumers($role);
        $matchingKeys = $this->findLocalReferenceKeys(
            $role,
            $consumerCode,
            \is_array($options['runtime'] ?? null) ? $options['runtime'] : null
        );

        $runtime = null;
        $localRefCount = 0;
        foreach ($matchingKeys as $key) {
            if (!isset(self::$localReferences[$key])) {
                continue;
            }

            self::$localReferences[$key]['count']--;
            $localRefCount = \max(0, (int) self::$localReferences[$key]['count']);
            $runtime = self::$localReferences[$key]['runtime'];
            if ($localRefCount > 0) {
                return [
                    'released' => true,
                    'local_ref_count' => $localRefCount,
                    'shutdown_scheduled' => false,
                    'runtime' => $runtime,
                ];
            }

            unset(self::$localReferences[$key]);
        }

        if ($runtime === null && \is_array($options['runtime'] ?? null)) {
            $runtime = $options['runtime'];
        }

        $forceRemoteRelease = (bool) ($options['force_remote_release'] ?? false);
        $ownerType = $this->resolveOwnerType($consumerCode, $options);
        if (!$forceRemoteRelease && $ownerType === self::OWNER_TYPE_INSTANCE) {
            return [
                'released' => true,
                'local_ref_count' => 0,
                'shutdown_scheduled' => false,
                'runtime' => $runtime,
            ];
        }
        if ($runtime === null && !$forceRemoteRelease) {
            return [
                'released' => false,
                'local_ref_count' => 0,
                'shutdown_scheduled' => false,
            ];
        }

        $record = $this->getRegistry()->withRoleLock($role, function () use ($role, $consumerCode, $options): array {
            return $this->getRegistry()->updateRecord($role, function (array $record) use ($role, $consumerCode, $options): array {
                $record = $this->pruneStaleConsumersFromRecord($role, $record, [$consumerCode]);
                $consumers = $this->normalizeConsumers($record['consumers'] ?? []);
                unset($consumers[$consumerCode]);
                $record['consumers'] = $consumers;

                if ($consumers === []) {
                    $graceSec = $this->resolveIdleShutdownGraceSec($options);
                    $record['idle_shutdown_grace_sec'] = $graceSec;
                    $record['shutdown_requested_at'] = \date('c');
                    $record['shutdown_due_at'] = \date('c', \time() + $graceSec);
                } else {
                    unset($record['shutdown_requested_at']);
                    unset($record['shutdown_due_at']);
                }

                return $record;
            });
        });

        $shutdownScheduled = empty($this->normalizeConsumers($record['consumers'] ?? []));
        if ($shutdownScheduled) {
            $this->shutdownIfUnused($role, \array_merge($options, ['runtime' => $runtime]));
        }

        return [
            'released' => true,
            'local_ref_count' => 0,
            'shutdown_scheduled' => $shutdownScheduled,
            'runtime' => $runtime,
        ];
    }

    public function releaseInstanceConsumers(string $instanceName): void
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return;
        }

        foreach ([ControlMessage::ROLE_SESSION_SERVER, ControlMessage::ROLE_MEMORY_SERVER] as $role) {
            $this->release($role, $instanceName, [
                'owner_type' => self::OWNER_TYPE_INSTANCE,
                'force_remote_release' => true,
            ]);
        }
    }

    /**
     * @return array{role:string, removed:list<string>, record:array<string, mixed>}
     */
    public function sweepStaleConsumers(string $role): array
    {
        $role = $this->normalizeRoleName($role);
        return $this->getRegistry()->withRoleLock($role, function () use ($role): array {
            return $this->sweepStaleConsumersLocked($role);
        });
    }

    /**
     * @return array{role:string, removed:list<string>, record:array<string, mixed>, skipped_locked?:bool}
     */
    public function sweepStaleConsumersIfAvailable(string $role): array
    {
        $role = $this->normalizeRoleName($role);
        $result = $this->getRegistry()->tryWithRoleLock(
            $role,
            function () use ($role): array {
                return $this->sweepStaleConsumersLocked($role);
            }
        );

        if (\is_array($result)) {
            return $result;
        }

        return [
            'role' => $role,
            'removed' => [],
            'record' => $this->getRegistryRecord($role),
            'skipped_locked' => true,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function shutdownIfUnused(string $role, array $options = []): bool
    {
        $role = $this->normalizeRoleName($role);

        return $this->getRegistry()->withRoleLock($role, function () use ($role, $options): bool {
            $record = $this->getRegistryRecord($role);
            if ($record === []) {
                return true;
            }

            $record = $this->pruneStaleConsumersFromRecord($role, $record);
            $consumers = $this->normalizeConsumers($record['consumers'] ?? []);
            if ($consumers !== []) {
                unset($record['shutdown_due_at']);
                unset($record['shutdown_requested_at']);
                $this->putRegistryRecord($role, $record);

                return false;
            }

            $dueAtTs = $this->parseTimestamp($record['shutdown_due_at'] ?? null);
            if ($dueAtTs === null) {
                $graceSec = $this->resolveIdleShutdownGraceSec($options);
                $record['idle_shutdown_grace_sec'] = $graceSec;
                $record['shutdown_requested_at'] = \date('c');
                $record['shutdown_due_at'] = \date('c', \time() + $graceSec);
                $this->putRegistryRecord($role, $record);

                return false;
            }

            if ($dueAtTs > \time()) {
                $this->putRegistryRecord($role, $record);

                return false;
            }

            $stopped = $this->gracefullyShutdownSharedService($role, $record);
            if (!$stopped) {
                $stopped = $this->forceStopSharedService($record);
            }

            if ($stopped || !$this->isPortOccupied((int) ($record['port'] ?? 0))) {
                $this->removeRegistryRecord($role);

                return true;
            }

            $this->putRegistryRecord($role, $record);

            return false;
        });
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function peekRuntime(string $role, array $options = []): array
    {
        $role = $this->normalizeRoleName($role);
        $envConfig = \is_array($options['env_config'] ?? null) ? $options['env_config'] : $this->loadEnvConfig();
        $runtime = $this->resolveCurrentRuntimeEndpoint($role, $envConfig, $options);
        $record = $this->getRegistryRecord($role);

        if ($record !== []) {
            $runtime['host'] = (string) ($record['host'] ?? $runtime['host'] ?? '127.0.0.1');
            $runtime['port'] = (int) ($record['port'] ?? $runtime['port'] ?? $this->defaultPortForRole($role));
            $runtime['token_file_name'] = (string) ($record['token_file_name'] ?? $runtime['token_file_name'] ?? $this->defaultTokenForRole($role));
        }

        $runtime['registered'] = $record !== [];
        $runtime['consumer_count'] = \count($this->normalizeConsumers($record['consumers'] ?? []));
        $runtime['shutdown_due_at'] = $record['shutdown_due_at'] ?? null;
        $runtime['shutdown_requested_at'] = $record['shutdown_requested_at'] ?? null;

        return $runtime;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function ensureService(array $definition, string $requesterInstanceName): array
    {
        $role = (string) $definition['role'];

        return $this->getRegistry()->withRoleLock($role, function () use ($definition, $requesterInstanceName): array {
            $running = $this->resolveRunningService($definition, $requesterInstanceName);
            if ($running !== null) {
                return $running;
            }

            $this->launchSharedServiceProcess($definition, $requesterInstanceName);

            return $this->waitUntilServiceReady($definition, $requesterInstanceName);
        });
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>|null
     */
    protected function resolveRunningService(array $definition, string $requesterInstanceName): ?array
    {
        $role = (string) $definition['role'];
        $record = $this->getRegistryRecord($role);

        if ($this->registryRecordMatchesDefinition($record, $definition)) {
            foreach ($this->collectRegistryMatchProbeTokens($record, $definition) as $tokenFileName) {
                if ($this->probeRunningSharedService($definition, $tokenFileName)) {
                    $inspection = $this->inspectRunningSharedService($definition, $tokenFileName);

                    return $this->storeAndBuildRuntime(
                        $definition,
                        $requesterInstanceName,
                        $inspection,
                        $tokenFileName,
                        true,
                        false
                    );
                }
            }
            if ($this->isPortOccupied((int) $definition['port'])) {
                $directToken = $this->tryDirectProtocolProbeForOccupiedPort($definition);
                if ($directToken !== null) {
                    $connectivityInspection = $this->buildConnectivityTrustInspection(
                        $definition,
                        $this->inspectRunningSharedService($definition, $directToken)
                    );
                    $connectivityInspection['token_file_name'] = $directToken;

                    return $this->storeAndBuildRuntime(
                        $definition,
                        $requesterInstanceName,
                        $connectivityInspection,
                        $directToken,
                        true,
                        false
                    );
                }
            }
        }

        $inspection = $this->inspectRunningSharedService(
            $definition,
            (string) $definition['token_file_name']
        );

        if ((bool) ($inspection['reusable'] ?? false)) {
            $tokenFileName = \trim((string) ($inspection['token_file_name'] ?? ''));
            if ($tokenFileName === '') {
                $tokenFileName = (string) $definition['token_file_name'];
            }

            if ($this->probeRunningSharedService($definition, $tokenFileName)) {
                return $this->storeAndBuildRuntime(
                    $definition,
                    $requesterInstanceName,
                    $inspection,
                    $tokenFileName,
                    true,
                    false
                );
            }
            if ($this->isPortOccupied((int) $definition['port'])) {
                $directToken = $this->tryDirectProtocolProbeForOccupiedPort($definition);
                if ($directToken !== null) {
                    $connectivityInspection = $this->buildConnectivityTrustInspection($definition, $inspection);
                    $connectivityInspection['token_file_name'] = $directToken;

                    return $this->storeAndBuildRuntime(
                        $definition,
                        $requesterInstanceName,
                        $connectivityInspection,
                        $directToken,
                        true,
                        false
                    );
                }
            }
        }

        $port = (int) $definition['port'];
        $portBusy = $this->isPortOccupied($port);
        if ($portBusy) {
            foreach ($this->collectProbeTokenCandidates($definition, $record, $inspection) as $tokenGuess) {
                if (!$this->probeRunningSharedService($definition, $tokenGuess)) {
                    continue;
                }
                $connectivityInspection = $this->buildConnectivityTrustInspection($definition, $inspection);
                $connectivityInspection['token_file_name'] = $tokenGuess;

                return $this->storeAndBuildRuntime(
                    $definition,
                    $requesterInstanceName,
                    $connectivityInspection,
                    $tokenGuess,
                    true,
                    false
                );
            }
            $directToken = $this->tryDirectProtocolProbeForOccupiedPort($definition);
            if ($directToken !== null) {
                $connectivityInspection = $this->buildConnectivityTrustInspection($definition, $inspection);
                $connectivityInspection['token_file_name'] = $directToken;

                return $this->storeAndBuildRuntime(
                    $definition,
                    $requesterInstanceName,
                    $connectivityInspection,
                    $directToken,
                    true,
                    false
                );
            }
            $processTrustInspection = $this->buildProcessSignatureTrustInspection($definition, $inspection);
            if ($processTrustInspection !== []) {
                $trustedToken = \trim((string) ($processTrustInspection['token_file_name'] ?? ''));
                if ($trustedToken === '') {
                    $trustedToken = (string) $definition['token_file_name'];
                    $processTrustInspection['token_file_name'] = $trustedToken;
                }

                return $this->storeAndBuildRuntime(
                    $definition,
                    $requesterInstanceName,
                    $processTrustInspection,
                    $trustedToken,
                    true,
                    false
                );
            }
        }

        $this->removeRegistryRecord($role);

        if ($portBusy) {
            $pid = (int) ($inspection['pid'] ?? 0);
            $processName = (string) ($inspection['process_name'] ?? '');
            throw new \RuntimeException(
                \sprintf(
                    'Shared %s port %d is occupied by a non-reusable process for instance [%s] (pid=%d, process=%s).',
                    $this->displayNameForRole($role),
                    $port,
                    $requesterInstanceName,
                    $pid,
                    $processName !== '' ? $processName : 'unknown'
                )
            );
        }

        return null;
    }

    /**
     * Registry 与定义一致时，优先用当前配置的 token 再试持久化记录（避免记录里旧 token 名导致误判）。
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    protected function collectRegistryMatchProbeTokens(array $record, array $definition): array
    {
        $seen = [];
        $out = [];
        $push = static function (string $t) use (&$seen, &$out): void {
            $t = \trim($t);
            if ($t === '' || isset($seen[$t])) {
                return;
            }
            $seen[$t] = true;
            $out[] = $t;
        };

        $push((string) $definition['token_file_name']);
        $push((string) ($record['token_file_name'] ?? ''));
        $push((string) ($record['configured_token_file_name'] ?? ''));

        return $out;
    }

    /**
     * 端口已被占用且无法从命令行可靠识别 Weline 共享进程时，依次尝试多种 token 文件名做协议 ping。
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $inspection
     * @return list<string>
     */
    protected function collectProbeTokenCandidates(array $definition, array $record, array $inspection): array
    {
        $seen = [];
        $out = [];
        $push = static function (string $t) use (&$seen, &$out): void {
            $t = \trim($t);
            if ($t === '' || isset($seen[$t])) {
                return;
            }
            $seen[$t] = true;
            $out[] = $t;
        };

        $push((string) $definition['token_file_name']);
        $push((string) ($record['token_file_name'] ?? ''));
        $push((string) ($record['configured_token_file_name'] ?? ''));
        $push((string) ($inspection['token_file_name'] ?? ''));
        $push(SharedSidecarInspector::extractTokenFileNameFromCommandLine((string) ($inspection['command_line'] ?? '')));
        $push($this->tryReadTokenFileNameFromOccupyingProcess((int) $definition['port']));

        $role = (string) $definition['role'];
        if ($role === ControlMessage::ROLE_MEMORY_SERVER) {
            $push('memory_server.token');
        } else {
            $push('session_server.token');
        }

        foreach ($this->listVarSessionTokenBasenames() as $base) {
            $push($base);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    protected function listVarSessionTokenBasenames(): array
    {
        if (!\defined('BP')) {
            return [];
        }

        $dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'session' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (\glob($dir . '*.token') ?: [] as $path) {
            $base = \basename((string) $path);
            if ($base !== '' && $base !== '.' && $base !== '..') {
                $out[] = $base;
            }
            if (\count($out) >= 24) {
                break;
            }
        }

        return $out;
    }

    protected function tryReadTokenFileNameFromOccupyingProcess(int $port): string
    {
        if ($port <= 0) {
            return '';
        }

        $occupant = $this->inspectPortOccupant($port);
        $pid = (int) ($occupant['pid'] ?? 0);
        if ($pid <= 0 || !($occupant['pid_running'] ?? false)) {
            return '';
        }

        $cmd = Processer::getProcessCommandLine($pid);

        return SharedSidecarInspector::extractTokenFileNameFromCommandLine($cmd);
    }

    /**
     * 直连 Session 协议：先无鉴权 PING（服务端未启用 token 时），再按磁盘上各 *.token 内容尝试 AUTH+PING。
     *
     * @param array<string, mixed> $definition
     */
    protected function tryDirectProtocolProbeForOccupiedPort(array $definition): ?string
    {
        return SharedStateProtocolProbe::findWorkingTokenBasename(
            (string) $definition['host'],
            (int) $definition['port'],
            (string) $definition['token_file_name']
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function waitUntilServiceReady(array $definition, string $requesterInstanceName): array
    {
        $timeoutSec = (float) ($definition['ensure_timeout_sec'] ?? self::DEFAULT_ENSURE_TIMEOUT_SEC);
        $pollMs = (int) ($definition['ensure_poll_interval_ms'] ?? self::DEFAULT_ENSURE_POLL_INTERVAL_MS);
        $pollMs = $pollMs > 0 ? $pollMs : self::DEFAULT_ENSURE_POLL_INTERVAL_MS;
        $deadline = \microtime(true) + $timeoutSec;
        $lastInspection = [];

        while (\microtime(true) < $deadline) {
            SchedulerSystem::usleep($pollMs * 1000);

            $lastInspection = $this->inspectRunningSharedService(
                $definition,
                (string) $definition['token_file_name']
            );

            if ((bool) ($lastInspection['reusable'] ?? false)) {
                $tokenFileName = \trim((string) ($lastInspection['token_file_name'] ?? ''));
                if ($tokenFileName === '') {
                    $tokenFileName = (string) $definition['token_file_name'];
                }

                if ($this->probeRunningSharedService($definition, $tokenFileName)) {
                    return $this->storeAndBuildRuntime(
                        $definition,
                        $requesterInstanceName,
                        $lastInspection,
                        $tokenFileName,
                        false,
                        true
                    );
                }
            }
        }

        $pid = (int) ($lastInspection['pid'] ?? 0);
        throw new \RuntimeException(
            \sprintf(
                'Shared %s service failed to become ready on %s:%d for instance [%s] (pid=%d).',
                $this->displayNameForRole((string) $definition['role']),
                (string) $definition['host'],
                (int) $definition['port'],
                $requesterInstanceName,
                $pid
            )
        );
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
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
    {
        return $this->getInspector()->inspect(
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
        return $port > 0 && Processer::isPortInUse($port);
    }

    /**
     * 当命令行侧无法判定为「可复用共享服务」但协议 ping 已成功时，用端口占用信息与定义补齐元数据。
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    protected function buildConnectivityTrustInspection(array $definition, array $inspection): array
    {
        $port = (int) $definition['port'];
        $pid = (int) ($inspection['pid'] ?? 0);
        if ($pid <= 0) {
            $occupant = $this->inspectPortOccupant($port);
            $pid = (int) ($occupant['pid'] ?? 0);
        }

        $role = (string) $definition['role'];
        $processName = \trim((string) ($inspection['process_name'] ?? ''));
        if ($processName === '') {
            $processName = (string) $definition['process_name'];
        }

        $instanceName = \trim((string) ($inspection['instance_name'] ?? ''));
        if ($instanceName === '') {
            $instanceName = (string) $definition['service_instance_name'];
        }

        return [
            'in_use' => true,
            'reusable' => true,
            'pid' => $pid,
            'port' => $port,
            'role' => $role,
            'instance_name' => $instanceName,
            'token_file_name' => (string) $definition['token_file_name'],
            'process_name' => $processName !== '' ? $processName : $this->defaultSharedProcessNameForRole($role, $port),
            'command_line' => (string) ($inspection['command_line'] ?? ''),
        ];
    }

    /**
     * 当协议探测失败但端口占用方明显是共享 sidecar 时，采用进程签名兜底复用，避免误判触发重启/报错。
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    protected function buildProcessSignatureTrustInspection(array $definition, array $inspection): array
    {
        $port = (int) $definition['port'];
        $occupant = $this->inspectPortOccupant($port);
        $pid = (int) ($occupant['pid'] ?? 0);
        if (
            !($occupant['in_use'] ?? false)
            || $pid <= 0
            || !($occupant['pid_running'] ?? false)
            || !($occupant['is_weline'] ?? false)
        ) {
            return [];
        }

        $commandLine = (string) ($occupant['command_line'] ?? '');
        if ($commandLine === '') {
            $commandLine = Processer::getProcessCommandLine($pid);
        }
        $processName = \trim((string) ($occupant['process_name'] ?? ''));
        if ($processName === '') {
            $processName = \trim((string) ($inspection['process_name'] ?? ''));
        }
        if ($processName === '') {
            $processName = (string) $definition['process_name'];
        }

        $role = (string) $definition['role'];
        $expectedPrefix = $role === ControlMessage::ROLE_MEMORY_SERVER
            ? MemoryServerProvider::PROCESS_NAME_PREFIX
            : SessionServerProvider::PROCESS_NAME_PREFIX;
        $looksShared = \str_contains($processName, $expectedPrefix . '-shared-')
            || \str_contains($processName, '-shared-')
            || (
                \str_contains($commandLine, 'session_server.php')
                && (\preg_match('/--shared-service(?:=1)?(?:\s|$)/i', $commandLine) === 1)
            );
        if (!$looksShared) {
            return [];
        }

        $tokenFileName = SharedSidecarInspector::extractTokenFileNameFromCommandLine($commandLine);
        if ($tokenFileName === '') {
            $tokenFileName = \trim((string) ($inspection['token_file_name'] ?? ''));
        }
        if ($tokenFileName === '') {
            $tokenFileName = (string) $definition['token_file_name'];
        }

        $instanceName = \trim((string) ($inspection['instance_name'] ?? ''));
        if ($instanceName === '') {
            $instanceName = (string) $definition['service_instance_name'];
        }

        return [
            'in_use' => true,
            'reusable' => true,
            'pid' => $pid,
            'port' => $port,
            'role' => $role,
            'instance_name' => $instanceName,
            'token_file_name' => $tokenFileName,
            'process_name' => $processName,
            'command_line' => $commandLine,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function inspectPortOccupant(int $port): array
    {
        return Processer::inspectPortOccupantWithHistory($port);
    }

    private function defaultSharedProcessNameForRole(string $role, int $port): string
    {
        if ($role === ControlMessage::ROLE_MEMORY_SERVER) {
            return MemoryServerProvider::PROCESS_NAME_PREFIX . '-shared-' . $port;
        }

        return SessionServerProvider::PROCESS_NAME_PREFIX . '-shared-' . $port;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    protected function storeAndBuildRuntime(
        array $definition,
        string $requesterInstanceName,
        array $inspection,
        string $tokenFileName,
        bool $reuseExisting,
        bool $createdNow
    ): array {
        $role = (string) $definition['role'];
        $existingRecord = $this->getRegistryRecord($role);
        $consumers = $this->normalizeConsumers($existingRecord['consumers'] ?? []);
        $consumers[$requesterInstanceName] = [
            'consumer_code' => $requesterInstanceName,
            'owner_type' => self::OWNER_TYPE_INSTANCE,
            'last_seen_at' => \date('c'),
            'last_ensured_at' => \date('c'),
            'lease_expires_at' => null,
        ];

        $actualProcessName = \trim((string) ($inspection['process_name'] ?? ''));
        if ($actualProcessName === '') {
            $actualProcessName = (string) $definition['process_name'];
        }

        $actualInstanceName = \trim((string) ($inspection['instance_name'] ?? ''));
        if ($actualInstanceName === '') {
            $actualInstanceName = (string) $definition['service_instance_name'];
        }

        $record = [
            'role' => $role,
            'host' => (string) $definition['host'],
            'port' => (int) $definition['port'],
            'token_file_name' => $tokenFileName,
            'pid' => (int) ($inspection['pid'] ?? 0),
            'process_name' => $actualProcessName,
            'instance_name' => $actualInstanceName,
            'service_instance_name' => $actualInstanceName,
            'shared_service' => true,
            'independent' => true,
            'configured_token_file_name' => (string) $definition['token_file_name'],
            'last_ensured_by_instance' => $requesterInstanceName,
            'last_ensured_at' => \date('c'),
            'last_verified_at' => \date('c'),
            'consumers' => $consumers,
        ];
        unset($record['shutdown_due_at']);
        unset($record['shutdown_requested_at']);

        $this->putRegistryRecord($role, $record);

        return [
            'host' => (string) $definition['host'],
            'port' => (int) $definition['port'],
            'token_file_name' => $tokenFileName,
            'reuse_existing' => $reuseExisting,
            'created_now' => $createdNow,
            'pid' => (int) ($inspection['pid'] ?? 0),
            'process_name' => $actualProcessName,
            'instance_name' => $actualInstanceName,
            'service_instance_name' => $actualInstanceName,
            'independent' => true,
            'shared_service' => true,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $definition
     */
    protected function registryRecordMatchesDefinition(array $record, array $definition): bool
    {
        if ($record === []) {
            return false;
        }

        return (string) ($record['role'] ?? '') === (string) $definition['role']
            && (int) ($record['port'] ?? 0) === (int) $definition['port']
            && \trim((string) ($record['host'] ?? '')) === (string) $definition['host'];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    protected function buildServiceDefinition(
        string $role,
        string $requesterInstanceName,
        array $config,
        array $envConfig
    ): array {
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

            $tokenFileName = \trim((string) ($config['memory_server_token_file_name'] ?? $memoryConfig['token_file_name'] ?? 'memory_server.token'));
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
            ?? $wlsSession['port']
            ?? $wlsServer['port']
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
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function resolveServiceDefinitionFromOptions(string $role, string $consumerCode, array $options): array
    {
        if (\is_array($options['service_definition'] ?? null)) {
            return $options['service_definition'];
        }

        $envConfig = \is_array($options['env_config'] ?? null) ? $options['env_config'] : $this->loadEnvConfig();
        $config = \is_array($options['config'] ?? null) ? $options['config'] : [];
        $runtime = $this->resolveCurrentRuntimeEndpoint($role, $envConfig, $options);

        if ($role === ControlMessage::ROLE_MEMORY_SERVER) {
            $config['memory_server_port'] = (int) ($config['memory_server_port'] ?? $runtime['port']);
            $config['memory_server_token_file_name'] = (string) ($config['memory_server_token_file_name'] ?? $runtime['token_file_name']);
        } else {
            $config['session_server_port'] = (int) ($config['session_server_port'] ?? $runtime['port']);
            $config['session_server_token_file_name'] = (string) ($config['session_server_token_file_name'] ?? $runtime['token_file_name']);
        }

        return $this->buildServiceDefinition($role, $consumerCode, $config, $envConfig);
    }

    /**
     * @param array<string, mixed> $envConfig
     * @param array<string, mixed> $options
     * @return array{host:string, port:int, token_file_name:string}
     */
    private function resolveCurrentRuntimeEndpoint(string $role, array $envConfig, array $options): array
    {
        if (\is_array($options['runtime'] ?? null)) {
            return [
                'host' => (string) ($options['runtime']['host'] ?? '127.0.0.1'),
                'port' => (int) ($options['runtime']['port'] ?? $this->defaultPortForRole($role)),
                'token_file_name' => (string) ($options['runtime']['token_file_name'] ?? $this->defaultTokenForRole($role)),
            ];
        }

        $instanceName = $this->resolveCurrentInstanceName();
        if ($instanceName !== null) {
            $runtimeOptions = SharedStateRuntimeOptions::fromCliArgs([], $instanceName, $envConfig);
            return $role === ControlMessage::ROLE_MEMORY_SERVER
                ? $runtimeOptions->getMemory()
                : $runtimeOptions->getSession();
        }

        $endpoint = $role === ControlMessage::ROLE_MEMORY_SERVER
            ? RoutingPolicyRegistry::getMemoryEndpoint()
            : RoutingPolicyRegistry::getSessionEndpoint();
        $record = $this->getRegistryRecord($role);
        $tokenFileName = (string) ($record['token_file_name'] ?? $this->defaultTokenForRole($role));
        if ((int) ($record['port'] ?? 0) !== (int) $endpoint['port']) {
            $tokenFileName = $this->defaultTokenForRole($role);
        }

        return [
            'host' => (string) ($endpoint['host'] ?? '127.0.0.1'),
            'port' => (int) ($endpoint['port'] ?? $this->defaultPortForRole($role)),
            'token_file_name' => $tokenFileName !== '' ? $tokenFileName : $this->defaultTokenForRole($role),
        ];
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $options
     */
    private function registerConsumer(string $role, string $consumerCode, array $runtime, array $options): void
    {
        $ownerType = $this->resolveOwnerType($consumerCode, $options);
        $payload = [
            'consumer_code' => $consumerCode,
            'owner_type' => $ownerType,
            'last_seen_at' => \date('c'),
            'last_ensured_at' => \date('c'),
            'lease_expires_at' => $ownerType === self::OWNER_TYPE_EPHEMERAL
                ? \date('c', \time() + $this->resolveEphemeralConsumerTtlSec($options))
                : null,
            'host' => (string) ($runtime['host'] ?? '127.0.0.1'),
            'port' => (int) ($runtime['port'] ?? 0),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? ''),
        ];

        $this->getRegistry()->upsertConsumer($role, $consumerCode, $payload);
    }

    private function resolveConsumerCode(string $consumerCode, array $options): string
    {
        $consumerCode = \trim($consumerCode);
        if ($consumerCode !== '') {
            return $consumerCode;
        }

        $optionCode = \trim((string) ($options['consumer_code'] ?? ''));
        if ($optionCode !== '') {
            return $optionCode;
        }

        $instanceName = $this->resolveCurrentInstanceName();
        if ($instanceName !== null) {
            return $instanceName;
        }

        $pid = \getmypid();

        return 'cli:' . ($pid !== false ? (string) $pid : 'unknown');
    }

    private function resolveOwnerType(string $consumerCode, array $options): string
    {
        $ownerType = \trim((string) ($options['owner_type'] ?? ''));
        if ($ownerType !== '') {
            return $ownerType;
        }

        $instanceName = $this->resolveCurrentInstanceName();
        if ($instanceName !== null && $instanceName === $consumerCode) {
            return self::OWNER_TYPE_INSTANCE;
        }

        return self::OWNER_TYPE_EPHEMERAL;
    }

    private function resolveCurrentInstanceName(): ?string
    {
        $candidates = [
            \getenv('WLS_INSTANCE'),
            \getenv('WLS_INSTANCE_NAME'),
            $_ENV['WLS_INSTANCE'] ?? null,
            $_ENV['WLS_INSTANCE_NAME'] ?? null,
            $_SERVER['WLS_INSTANCE'] ?? null,
            $_SERVER['WLS_INSTANCE_NAME'] ?? null,
            \defined('WLS_INSTANCE') ? \constant('WLS_INSTANCE') : null,
            \defined('WLS_INSTANCE_NAME') ? \constant('WLS_INSTANCE_NAME') : null,
        ];

        foreach ($candidates as $candidate) {
            if (!\is_string($candidate)) {
                continue;
            }

            $trimmed = \trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $runtimeFilter
     * @return list<string>
     */
    private function findLocalReferenceKeys(string $role, string $consumerCode, ?array $runtimeFilter = null): array
    {
        $keys = [];
        $filterHost = $runtimeFilter !== null ? (string) ($runtimeFilter['host'] ?? '') : '';
        $filterPort = $runtimeFilter !== null ? (int) ($runtimeFilter['port'] ?? 0) : 0;
        $filterToken = $runtimeFilter !== null ? (string) ($runtimeFilter['token_file_name'] ?? '') : '';

        foreach (self::$localReferences as $key => $ref) {
            if (($ref['role'] ?? '') !== $role || ($ref['consumer_code'] ?? '') !== $consumerCode) {
                continue;
            }

            if ($runtimeFilter !== null) {
                $runtime = \is_array($ref['runtime'] ?? null) ? $ref['runtime'] : [];
                if ($filterHost !== '' && (string) ($runtime['host'] ?? '') !== $filterHost) {
                    continue;
                }
                if ($filterPort > 0 && (int) ($runtime['port'] ?? 0) !== $filterPort) {
                    continue;
                }
                if ($filterToken !== '' && (string) ($runtime['token_file_name'] ?? '') !== $filterToken) {
                    continue;
                }
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private function buildLocalReferenceKey(string $role, string $consumerCode, array $runtime): string
    {
        return \implode('|', [
            $role,
            $consumerCode,
            (string) ($runtime['host'] ?? '127.0.0.1'),
            (string) ($runtime['port'] ?? 0),
            (string) ($runtime['token_file_name'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string> $knownLiveConsumers
     * @return array<string, mixed>
     */
    private function pruneStaleConsumersFromRecord(string $role, array $record, array $knownLiveConsumers = []): array
    {
        $consumers = $this->normalizeConsumers($record['consumers'] ?? []);
        if ($consumers === []) {
            $record['consumers'] = [];

            return $record;
        }

        $knownLive = \array_fill_keys($knownLiveConsumers, true);
        foreach ($consumers as $consumerCode => $consumer) {
            if (isset($knownLive[$consumerCode])) {
                continue;
            }

            if ($this->isStaleConsumer($consumerCode, $consumer)) {
                unset($consumers[$consumerCode]);
            }
        }

        $record['consumers'] = $consumers;

        return $record;
    }

    /**
     * @return array{role:string, removed:list<string>, record:array<string, mixed>}
     */
    private function sweepStaleConsumersLocked(string $role): array
    {
        $existingRecord = $this->getRegistryRecord($role);
        if ($existingRecord === []) {
            return [
                'role' => $role,
                'removed' => [],
                'record' => [],
            ];
        }

        $removed = [];
        $record = $this->getRegistry()->updateRecord($role, function (array $record) use ($role, &$removed): array {
            $before = \array_keys($this->normalizeConsumers($record['consumers'] ?? []));
            $record = $this->pruneStaleConsumersFromRecord($role, $record);
            $after = \array_keys($this->normalizeConsumers($record['consumers'] ?? []));
            $removed = \array_values(\array_diff($before, $after));
            if ($removed !== []) {
                $record = $this->scheduleIdleShutdownOnEmptyRecord($record, []);
            }

            return $record;
        });

        return [
            'role' => $role,
            'removed' => $removed,
            'record' => $record,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function scheduleIdleShutdownOnEmptyRecord(array $record, array $options): array
    {
        $consumers = $this->normalizeConsumers($record['consumers'] ?? []);
        $record['consumers'] = $consumers;
        if ($consumers !== []) {
            unset($record['shutdown_due_at'], $record['shutdown_requested_at']);

            return $record;
        }

        if (!$this->hasSharedServiceIdentity($record)) {
            return $record;
        }

        if ($this->parseTimestamp($record['shutdown_due_at'] ?? null) !== null) {
            return $record;
        }

        $graceSec = $this->resolveIdleShutdownGraceSec($options);
        $record['idle_shutdown_grace_sec'] = $graceSec;
        $record['shutdown_requested_at'] = \date('c');
        $record['shutdown_due_at'] = \date('c', \time() + $graceSec);

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function hasSharedServiceIdentity(array $record): bool
    {
        $role = \trim((string) ($record['role'] ?? ''));
        $host = \trim((string) ($record['host'] ?? ''));
        $port = (int) ($record['port'] ?? 0);

        return $role !== '' && $host !== '' && $port > 0;
    }

    /**
     * @param array<string, mixed> $consumer
     */
    private function isStaleConsumer(string $consumerCode, array $consumer): bool
    {
        $ownerType = \trim((string) ($consumer['owner_type'] ?? self::OWNER_TYPE_INSTANCE)) ?: self::OWNER_TYPE_INSTANCE;
        if ($ownerType === self::OWNER_TYPE_INSTANCE) {
            $instanceManager = new ServerInstanceManager();

            return !$instanceManager->hasInstance($consumerCode);
        }

        $leaseExpiresAt = $this->parseTimestamp($consumer['lease_expires_at'] ?? null);
        if ($leaseExpiresAt === null) {
            $lastSeenAt = $this->parseTimestamp($consumer['last_seen_at'] ?? null);
            if ($lastSeenAt === null) {
                return true;
            }

            $leaseExpiresAt = $lastSeenAt + self::DEFAULT_EPHEMERAL_CONSUMER_TTL_SEC;
        }

        return $leaseExpiresAt <= \time();
    }

    /**
     * @param mixed $consumers
     * @return array<string, array<string, mixed>>
     */
    private function normalizeConsumers(mixed $consumers): array
    {
        if (!\is_array($consumers)) {
            return [];
        }

        $normalized = [];
        foreach ($consumers as $consumerCode => $consumer) {
            $code = \trim((string) $consumerCode);
            if ($code === '') {
                continue;
            }

            if (!\is_array($consumer)) {
                $consumer = [];
            }

            $normalized[$code] = \array_merge(
                [
                    'consumer_code' => $code,
                    'owner_type' => self::OWNER_TYPE_INSTANCE,
                    'last_seen_at' => (string) ($consumer['last_ensured_at'] ?? \date('c')),
                    'lease_expires_at' => null,
                ],
                $consumer
            );
            $normalized[$code]['consumer_code'] = $code;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveIdleShutdownGraceSec(array $options): int
    {
        $graceSec = (int) ($options['idle_shutdown_grace_sec'] ?? 0);
        if ($graceSec > 0) {
            return $graceSec;
        }

        $envConfig = \is_array($options['env_config'] ?? null) ? $options['env_config'] : $this->loadEnvConfig();
        $sharedState = \is_array(($envConfig['wls'] ?? [])['shared_state'] ?? null) ? $envConfig['wls']['shared_state'] : [];
        $graceSec = (int) ($sharedState['idle_shutdown_grace_sec'] ?? self::DEFAULT_IDLE_SHUTDOWN_GRACE_SEC);

        return $graceSec > 0 ? $graceSec : self::DEFAULT_IDLE_SHUTDOWN_GRACE_SEC;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveEphemeralConsumerTtlSec(array $options): int
    {
        $ttl = (int) ($options['ephemeral_consumer_ttl_sec'] ?? 0);
        if ($ttl > 0) {
            return $ttl;
        }

        $envConfig = \is_array($options['env_config'] ?? null) ? $options['env_config'] : $this->loadEnvConfig();
        $sharedState = \is_array(($envConfig['wls'] ?? [])['shared_state'] ?? null) ? $envConfig['wls']['shared_state'] : [];
        $ttl = (int) ($sharedState['ephemeral_consumer_ttl_sec'] ?? self::DEFAULT_EPHEMERAL_CONSUMER_TTL_SEC);

        return $ttl > 0 ? $ttl : self::DEFAULT_EPHEMERAL_CONSUMER_TTL_SEC;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function gracefullyShutdownSharedService(string $role, array $record): bool
    {
        $host = (string) ($record['host'] ?? '127.0.0.1');
        $port = (int) ($record['port'] ?? 0);
        if ($port <= 0) {
            return false;
        }

        $tokenFileName = (string) ($record['token_file_name'] ?? $this->defaultTokenForRole($role));
        $client = new SharedStateClient($host, $port, [
            'token_file_name' => $tokenFileName,
            'acquire_timeout' => 0.35,
            'connect_timeout' => 0.85,
            'timeout' => 1.25,
            'log_connect_fail' => false,
        ]);

        try {
            $client->request(SessionProtocol::CMD_PERSIST);
            $response = $client->request(SessionProtocol::CMD_SHUTDOWN);
            if (!\is_array($response) || !SessionProtocol::isSuccess($response)) {
                return false;
            }

            $deadline = \microtime(true) + 3.0;
            while (\microtime(true) < $deadline) {
                if (!$this->isPortOccupied($port)) {
                    return true;
                }

                SchedulerSystem::usleep(100000);
            }

            return !$this->isPortOccupied($port);
        } catch (\Throwable) {
            return false;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function forceStopSharedService(array $record): bool
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

    private function normalizeRoleName(string $role): string
    {
        $role = \trim($role);

        return match ($role) {
            'session' => ControlMessage::ROLE_SESSION_SERVER,
            'memory' => ControlMessage::ROLE_MEMORY_SERVER,
            default => $role,
        };
    }

    private function defaultPortForRole(string $role): int
    {
        return $role === ControlMessage::ROLE_MEMORY_SERVER ? 19971 : 19970;
    }

    private function defaultTokenForRole(string $role): string
    {
        return $role === ControlMessage::ROLE_MEMORY_SERVER ? 'memory_server.token' : 'session_server.token';
    }

    private function parseTimestamp(mixed $value): ?int
    {
        if (!\is_string($value) || \trim($value) === '') {
            return null;
        }

        $ts = \strtotime($value);

        return $ts === false ? null : $ts;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEnvConfig(): array
    {
        $envFile = \defined('BP')
            ? BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php'
            : '';
        if ($envFile === '' || !\is_file($envFile)) {
            return [];
        }

        $config = require $envFile;

        return \is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getRegistryRecord(string $role): array
    {
        return $this->getRegistry()->getRecord($role);
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function putRegistryRecord(string $role, array $record): void
    {
        $this->getRegistry()->putRecord($role, $record);
    }

    protected function removeRegistryRecord(string $role): void
    {
        $this->getRegistry()->removeRecord($role);
    }

    protected function displayNameForRole(string $role): string
    {
        return $role === ControlMessage::ROLE_MEMORY_SERVER ? 'Memory Service' : 'Session Server';
    }

    protected function getRegistry(): SharedStateServiceRegistry
    {
        if ($this->registry === null) {
            $this->registry = new SharedStateServiceRegistry();
        }

        return $this->registry;
    }

    protected function getInspector(): SharedSidecarInspector
    {
        if ($this->inspector === null) {
            $this->inspector = new SharedSidecarInspector();
        }

        return $this->inspector;
    }
}
