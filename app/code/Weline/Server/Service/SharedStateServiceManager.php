<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Provider\MemoryServerProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;
use Weline\Server\Shared\Connection\ConnectionPoolManager;

class SharedStateServiceManager
{
    private const DEFAULT_ENSURE_TIMEOUT_SEC = 30.0;
    private const DEFAULT_ENSURE_POLL_INTERVAL_MS = 100;
    private const MAX_RUNTIME_FILE_BYTES = 64 * 1024;
    private const RUNTIME_SCHEMA = 'wls-shared-runtime/2';
    private const DEFAULT_LIFECYCLE_LOCK_WAIT_SECONDS = 30.0;
    private const CONSUMER_RENEW_TRANSACTION_BUDGET_SECONDS = 0.025;
    private const LIFECYCLE_IDENTITY_FIELDS = [
        'role',
        'host',
        'port',
        'pid',
        'token_file_name',
        'started_at',
        'process_name',
        'instance_name',
        'service_instance_name',
        'lifecycle_schema',
        'lifecycle_generation',
        'lifecycle_identity_digest',
    ];

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /**
     * 解析 ensure() 的前台标志，与日志及 Windows 下共享侧车拉起方式一致。
     * - `server:start --win`（或已弃用的 `--frontend`）经 ensureRuntime 显式传入；
     * - Worker 带 `--win`/`--frontend` 时由 worker.php / worker_ssl.php 定义常量 WLS_FRONTEND_MODE。
     *
     * @param array<string, mixed> $config 可含 shared_service_frontend 强制覆盖
     */
    public static function resolveEnsureFrontendFlag(array $config = []): bool
    {
        if (\array_key_exists('shared_service_frontend', $config)) {
            return (bool) $config['shared_service_frontend'];
        }

        return \defined('WLS_FRONTEND_MODE') && WLS_FRONTEND_MODE;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array{
     *   session: array<string, mixed>,
     *   memory: array<string, mixed>
     * }
     */
    public function ensureRuntime(
        string $requesterInstanceName,
        array $config,
        array $envConfig = [],
        bool $frontend = false,
        bool $forceRestart = false
    ): array {
        $roles = [ControlMessage::ROLE_SESSION_SERVER];
        if ($this->isMemoryEnabled($config, $envConfig)) {
            $roles[] = ControlMessage::ROLE_MEMORY_SERVER;
        }
        return $this->withRoleLifecycleLocks(
            $roles,
            fn(): array => $this->ensureRuntimeUnlocked(
                $requesterInstanceName,
                $config,
                $envConfig,
                $frontend,
                $forceRestart,
            ),
        );
    }

    private function ensureRuntimeUnlocked(
        string $requesterInstanceName,
        array $config,
        array $envConfig = [],
        bool $frontend = false,
        bool $forceRestart = false
    ): array {
        $this->recoverRuntimeFileForMutation(
            ControlMessage::ROLE_SESSION_SERVER,
        );
        if ($this->isMemoryEnabled($config, $envConfig)) {
            $this->recoverRuntimeFileForMutation(
                ControlMessage::ROLE_MEMORY_SERVER,
            );
        }
        $sessionDefinition = $this->buildRoleDefinition(
            ControlMessage::ROLE_SESSION_SERVER,
            $requesterInstanceName,
            $config,
            $envConfig
        );
        $memoryDefinition = $this->isMemoryEnabled($config, $envConfig)
            ? $this->buildRoleDefinition(
                ControlMessage::ROLE_MEMORY_SERVER,
                $requesterInstanceName,
                $config,
                $envConfig,
                [(int) $sessionDefinition['port']]
            )
            : null;

        // 快速探测阶段 - 不需要锁，只需检查服务是否已健康
        $sessionProbe = $this->quickProbe($sessionDefinition);
        $memoryProbe = $memoryDefinition !== null ? $this->quickProbe($memoryDefinition) : null;

        // 分析哪些服务需要启动
        $sessionNeedsStartup = ($sessionProbe['status'] ?? '') !== 'ready';
        $memoryNeedsStartup = $memoryProbe !== null && ($memoryProbe['status'] ?? '') !== 'ready';

        // 如果两个服务都无需启动，直接复用
        if (!$sessionNeedsStartup && !$memoryNeedsStartup) {
            WlsLogger::info_('[SharedStateServiceManager] Session 和 Memory 均已就绪，直接复用');
            return $this->buildRuntimeFromQuickProbe($sessionProbe, $memoryProbe, $requesterInstanceName);
        }

        $prepareStartedAt = self::monotonicSeconds();
        $sessionPrepare = $sessionNeedsStartup
            ? $this->prepareSharedService(
                $sessionDefinition,
                $requesterInstanceName,
                $frontend,
                $forceRestart,
                \is_array($sessionProbe['probe'] ?? null) ? $sessionProbe['probe'] : null,
                true
            )
            : $sessionProbe;
        $memoryPrepare = $memoryDefinition !== null
            ? (
                $memoryNeedsStartup
                    ? $this->prepareSharedService(
                        $memoryDefinition,
                        $requesterInstanceName,
                        $frontend,
                        $forceRestart,
                        \is_array($memoryProbe['probe'] ?? null) ? $memoryProbe['probe'] : null,
                        true
                    )
                    : $memoryProbe
            )
            : null;

        $launchDefinitions = [];
        foreach ([$sessionPrepare, $memoryPrepare] as $prepare) {
            if (\is_array($prepare) && (bool)($prepare['launch_required'] ?? false)
                && \is_array($prepare['definition'] ?? null)) {
                $launchDefinitions[] = $prepare['definition'];
            }
        }
        $this->launchSharedServiceProcessesBatch($launchDefinitions, $requesterInstanceName, $frontend);

        // 两个 sidecar 都先完成进程创建，再在同一个总 deadline 内并行探活。
        $pendingDefinitions = [];
        foreach ([$sessionPrepare, $memoryPrepare] as $prepare) {
            if (!\is_array($prepare) || ($prepare['status'] ?? '') === 'ready') {
                continue;
            }
            $pendingDefinition = \is_array($prepare['definition'] ?? null)
                ? $prepare['definition']
                : null;
            if ($pendingDefinition !== null) {
                $pendingDefinitions[] = $pendingDefinition;
            }
        }

        $waitStartedAt = self::monotonicSeconds();
        $readyByRole = $this->waitUntilSharedServicesReadyBatch($pendingDefinitions);
        if ($pendingDefinitions !== []) {
            WlsLogger::info_(
                '[SharedStateServiceManager][BatchReady] roles='
                . \implode(',', \array_map(
                    static fn(array $definition): string => (string)($definition['role'] ?? ''),
                    $pendingDefinitions
                ))
                . ', prepare_ms=' . \round(($waitStartedAt - $prepareStartedAt) * 1000, 3)
                . ', wait_ms=' . \round((self::monotonicSeconds() - $waitStartedAt) * 1000, 3)
            );
        }

        $runtime = [
            'session' => $this->runtimeFromPreparedSharedService(
                $sessionPrepare,
                $sessionDefinition,
                $readyByRole
            ),
        ];

        if ($memoryDefinition !== null && \is_array($memoryPrepare)) {
            $runtime['memory'] = $this->runtimeFromPreparedSharedService(
                $memoryPrepare,
                $memoryDefinition,
                $readyByRole
            );
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

        $runtime['session'] = $this->finalizeSharedRuntime(
            ControlMessage::ROLE_SESSION_SERVER,
            $runtime['session'],
            $requesterInstanceName
        );
        if ($memoryDefinition !== null) {
            $runtime['memory'] = $this->finalizeSharedRuntime(
                ControlMessage::ROLE_MEMORY_SERVER,
                $runtime['memory'],
                $requesterInstanceName
            );
        } else {
            $runtime['memory'] = $this->mergeRuntimeWithRegistryMetadata(
                ControlMessage::ROLE_MEMORY_SERVER,
                $runtime['memory']
            );
        }

        return $runtime;
    }

    /**
     * 快速探测服务状态，无需锁
     */
    private function quickProbe(array $definition): array
    {
        $probe = $this->probeDefinition($definition);
        if ((bool)($probe['healthy'] ?? false)) {
            $runtime = \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];
            $runtime['reuse_existing'] = true;
            $runtime['shared_service'] = true;
            return ['status' => 'ready', 'runtime' => $runtime, 'probe' => $probe];
        }
        return ['status' => 'pending', 'definition' => $definition, 'probe' => $probe];
    }

    /**
     * 从快速探测结果构建运行时数据
     */
    private function buildRuntimeFromQuickProbe(array $sessionProbe, ?array $memoryProbe, string $requesterInstanceName): array
    {
        $runtime = [
            'session' => $sessionProbe['runtime'] ?? [],
        ];
        if ($memoryProbe !== null) {
            $runtime['memory'] = $memoryProbe['runtime'] ?? [];
        } else {
            $runtime['memory'] = ['enabled' => false, 'healthy' => false, 'shared_service' => false];
        }

        $runtime['session'] = $this->finalizeSharedRuntime(
            ControlMessage::ROLE_SESSION_SERVER,
            $runtime['session'],
            $requesterInstanceName
        );
        if ($memoryProbe !== null) {
            $runtime['memory'] = $this->finalizeSharedRuntime(
                ControlMessage::ROLE_MEMORY_SERVER,
                $runtime['memory'],
                $requesterInstanceName
            );
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
        bool $frontend = false,
        bool $forceRestart = false
    ): array {
        $role = $this->normalizeRoleName($role);
        return $this->withRoleLifecycleLocks(
            [$role],
            fn(): array => $this->ensureUnlocked(
                $role,
                $config,
                $envConfig,
                $requesterInstanceName,
                $frontend,
                $forceRestart,
            ),
        );
    }

    private function ensureUnlocked(
        string $role,
        array $config = [],
        array $envConfig = [],
        string $requesterInstanceName = 'system',
        bool $frontend = false,
        bool $forceRestart = false
    ): array {
        $this->recoverRuntimeFileForMutation($role);
        $definition = $this->buildRoleDefinition($role, $requesterInstanceName, $config, $envConfig);

        $prepare = $this->prepareSharedService($definition, $requesterInstanceName, $frontend, $forceRestart);

        if (($prepare['status'] ?? '') === 'ready') {
            return $this->finalizeEnsuredRuntime(
                (string) $definition['role'],
                $prepare['runtime'],
                $requesterInstanceName
            );
        }

        return $this->finalizeEnsuredRuntime(
            (string) $definition['role'],
            $this->waitUntilSharedServicesReadyBatch([$prepare['definition']])[(string) $definition['role']],
            $requesterInstanceName
        );
    }

    /**
     * @param array<string, mixed> $prepare
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function runtimeFromPreparedSharedService(
        array $prepare,
        array $definition,
        array $readyByRole = []
    ): array {
        if (($prepare['status'] ?? '') === 'ready') {
            return \is_array($prepare['runtime'] ?? null) ? $prepare['runtime'] : [];
        }

        $pendingDefinition = \is_array($prepare['definition'] ?? null) ? $prepare['definition'] : $definition;
        $role = (string) ($pendingDefinition['role'] ?? $definition['role'] ?? '');
        if (!\is_array($readyByRole[$role] ?? null)) {
            $readyByRole = $this->waitUntilSharedServicesReadyBatch([$pendingDefinition]);
        }

        return \is_array($readyByRole[$role] ?? null) ? $readyByRole[$role] : [];
    }

    /**
     * 完成探测 / 强制停止 / 拉起共享服务进程。
     * 健康协议复用和 OS 端口绑定是唯一启动并发保护。
     *
     * @param array<string, mixed> $definition
     * @return array{
     *   status: 'ready',
     *   runtime: array<string, mixed>
     * }|array{
     *   status: 'pending',
     *   definition: array<string, mixed>
     * }
     */
    private function prepareSharedService(
        array $definition,
        string $requesterInstanceName,
        bool $frontend,
        bool $forceRestart,
        ?array $knownProbe = null,
        bool $deferLaunch = false
    ): array {
        $probe = $knownProbe ?? $this->probeDefinition($definition);
        if ((bool) ($probe['healthy'] ?? false)) {
            if ($forceRestart) {
                WlsLogger::info_(
                    '[SharedStateServiceManager] 强制重启共享服务 (角色: '
                    . (string) $definition['role']
                    . ", 请求者: {$requesterInstanceName}, 前台: "
                    . ($frontend ? '是' : '否') . ')'
                );
                $selected = $this->selectLifecycleGeneration(
                    (string)$definition['role'],
                    \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [],
                    $this->createRegistry(),
                );
                if (!$this->forceStopReusedService(
                    $definition,
                    $selected,
                )) {
                    throw new \RuntimeException(
                        'Unable to restart the selected shared-service generation.'
                    );
                }
                // Do not wait here; readiness is verified after the process is launched.
            } else {
                $runtime = \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];
                $runtime['reuse_existing'] = true;
                $runtime['shared_service'] = true;
                $this->ensureSharedProcessLogVisible($runtime, $requesterInstanceName);
                WlsLogger::info_(
                    '[SharedStateServiceManager] 共享服务已存在 (角色: ' . (string) $definition['role']
                    . ", 请求者实例名称: $requesterInstanceName, 前台模式: " . ($frontend ? '是' : '否') . ')'
                );
                if ($frontend && \defined('IS_WIN') && IS_WIN) {
                    WlsLogger::info_(
                        '[SharedStateServiceManager] 提示: 当前为复用已有共享进程，不会出现新的控制台窗口；若需 Session/Memory 独立窗口请使用 server:start -r（或先 server:shared:stop）'
                    );
                }
                WlsLogger::flush_(true);

                return ['status' => 'ready', 'runtime' => $runtime];
            }
        }

        if ((bool) ($probe['unexpected_occupant'] ?? false)) {
            throw new \RuntimeException((string) ($probe['message'] ?? 'Shared service port is occupied.'));
        }

        if ((bool) ($probe['reusable_but_unhealthy'] ?? false)) {
            $selected = $this->selectLifecycleGeneration(
                (string)$definition['role'],
                \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [],
                $this->createRegistry(),
            );
            if (!$this->forceStopReusedService(
                $definition,
                $selected,
            )) {
                throw new \RuntimeException(
                    'Unable to retire the unhealthy shared-service generation.'
                );
            }
            // Do not wait here; readiness is verified after the process is launched.
        }
        WlsLogger::info_(
            '[SharedStateServiceManager] 启动共享服务 (角色: ' . (string) $definition['role']
            . ", 请求者实例名称: $requesterInstanceName, 前台模式: " . ($frontend ? '是' : '否') . ')'
        );
        if ($deferLaunch) {
            return ['status' => 'pending', 'definition' => $definition, 'launch_required' => true];
        }

        $pid = $this->launchSharedServiceProcess($definition, $requesterInstanceName, $frontend);
        if ($pid <= 0) {
            throw new \RuntimeException($this->buildSharedSpawnFailureMessage($definition));
        }

        $definition['_launched_pid'] = $pid;

        return ['status' => 'pending', 'definition' => $definition];
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @return array<string, array<string, mixed>> role => runtime
     */
    protected function waitUntilSharedServicesReadyBatch(array $definitions): array
    {
        if ($definitions === []) {
            return [];
        }

        foreach ($definitions as $definition) {
            ConnectionPoolManager::discardPool(
                (string)$definition['host'],
                (int)$definition['port'],
                (string)($definition['token_file_name'] ?? '')
            );
        }

        $platformDeadlineSec = (\defined('IS_WIN') && IS_WIN) ? 30.0 : 3.0;
        $timeoutSec = 0.5;
        foreach ($definitions as $definition) {
            $configured = (float)($definition['ensure_timeout_sec'] ?? $platformDeadlineSec);
            $timeoutSec = \max($timeoutSec, \min($platformDeadlineSec, \max(0.5, $configured)));
        }

        $deadline = self::monotonicSeconds() + $timeoutSec;
        $startedAt = \date('c');
        $pending = [];
        foreach ($definitions as $definition) {
            $pending[(string)$definition['role']] = $definition;
        }

        $pollIntervals = [1_000, 2_000, 5_000, 10_000, 20_000];
        $pollIndex = 0;
        $done = [];

        while (self::monotonicSeconds() < $deadline && $pending !== []) {
            foreach ($pending as $roleKey => $definition) {
                if (!$this->probeRunningSharedService(
                    $definition,
                    (string)($definition['token_file_name'] ?? '')
                )) {
                    continue;
                }

                $probe = $this->probeDefinition($definition);
                if (!((bool)($probe['healthy'] ?? false))) {
                    continue;
                }

                $runtime = \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];
                $runtime['healthy_at'] = \date('c');
                $runtime['created_now'] = true;
                $runtime['shared_service'] = true;
                // Sidecar registry is the authority for incarnation started_at.
                // Using the wait-loop wall clock here creates a same-generation
                // digest fork when bind happens one second later.
                $registryRecord = $this->createRegistry()->getRecord($roleKey);
                $registryPid = (int)($registryRecord['pid'] ?? 0);
                $runtimePid = (int)($runtime['pid'] ?? 0);
                if ($registryPid > 0 && $registryPid === $runtimePid) {
                    foreach ([
                        'started_at',
                        'healthy_at',
                        'process_name',
                        'instance_name',
                        'service_instance_name',
                        'token_file_name',
                    ] as $field) {
                        $value = $registryRecord[$field] ?? null;
                        if (\is_string($value) && \trim($value) !== '') {
                            $runtime[$field] = $value;
                        }
                    }
                }
                if (!\is_string($runtime['started_at'] ?? null)
                    || \trim((string)$runtime['started_at']) === ''
                ) {
                    $runtime['started_at'] = $startedAt;
                }
                $this->writeRuntimeFile($roleKey, $runtime);
                $done[$roleKey] = $runtime;
                unset($pending[$roleKey]);
            }

            if ($pending === []) {
                break;
            }

            $sleepUs = $pollIntervals[\min($pollIndex, \count($pollIntervals) - 1)];
            $pollIndex++;
            if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null) {
                SchedulerSystem::yieldDelay((int)\max(1, \ceil($sleepUs / 1000)));
            } else {
                \usleep($sleepUs);
            }
        }

        if ($pending !== []) {
            $parts = [];
            foreach ($pending as $roleKey => $definition) {
                $parts[] = \sprintf(
                    '%s %s:%d',
                    $this->displayNameForRole($roleKey),
                    (string)$definition['host'],
                    (int)$definition['port']
                );
            }
            throw new \RuntimeException(
                '下列共享服务未在时限内就绪: ' . \implode('; ', $parts)
                . '。请查看对应进程日志与 token 文件；若需释放共享侧车可执行 php bin/w server:shared:stop 后重试。'
            );
        }

        return $done;
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
        $role = $this->normalizeRoleName($role);
        return $this->withRoleLifecycleLocks(
            [$role],
            fn(): array => $this->restartUnlocked(
                $role,
                $config,
                $envConfig,
                $requesterInstanceName,
                $frontend,
            ),
        );
    }

    private function restartUnlocked(
        string $role,
        array $config = [],
        array $envConfig = [],
        string $requesterInstanceName = 'system',
        bool $frontend = false
    ): array {
        $this->recoverRuntimeFileForMutation($role);
        $definition = $this->buildRoleDefinition($role, $requesterInstanceName, $config, $envConfig);

        $registry = $this->createRegistry();
        $selected = $this->selectLifecycleGeneration(
            (string)$definition['role'],
            $this->readRuntimeFile((string)$definition['role']),
            $registry,
        );
        if (!$this->forceStopReusedService($definition, $selected)) {
            throw new \RuntimeException(
                'Unable to restart the shared service because the selected generation remained active.'
            );
        }
        $this->removeSelectedLifecycleGeneration(
            (string)$definition['role'],
            $selected,
            $registry,
        );
        WlsLogger::info_(
            "[SharedStateServiceManager] 启动共享服务 (角色: " . (string) $definition['role']
            . ", 请求者实例名称: $requesterInstanceName, 前台模式: " . ($frontend ? '是' : '否') . ')'
        );
        $pid = $this->launchSharedServiceProcess($definition, $requesterInstanceName, $frontend);
        if ($pid <= 0) {
            throw new \RuntimeException($this->buildSharedSpawnFailureMessage($definition));
        }

        $definition['_launched_pid'] = $pid;

        $prepare = ['status' => 'pending', 'definition' => $definition];

        return $this->finalizeEnsuredRuntime(
            (string) $definition['role'],
            $this->waitUntilSharedServicesReadyBatch([$prepare['definition']])[(string) $definition['role']],
            $requesterInstanceName
        );
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

        $role = $this->normalizeRoleName($role);
        $runtime = $this->mergeRuntimeWithRegistryMetadata($role, $this->readRuntimeFile($role));
        $runtimeBound = SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $runtime,
        );
        $definition = $this->buildStatusProbeDefinition($role, $config, $envConfig, $runtime);
        $healthy = $this->probeRunningSharedService($definition, (string) $definition['token_file_name']);
        // `pid` remains the persisted/registry authority. Live process
        // observation is reported separately only after strict identity checks.
        $pid = (int) ($runtime['pid'] ?? 0);
        $healthyAt = $healthy
            ? \date('c')
            : (\is_string($runtime['healthy_at'] ?? null)
                ? (string)$runtime['healthy_at']
                : null);
        if ($runtimeBound) {
            $runtime['healthy_at'] = $healthyAt;
        } else {
            $runtime = \array_merge(
                $runtime,
                $this->buildRuntimeMetadata(
                    $definition,
                    $pid,
                    \is_string($runtime['started_at'] ?? null)
                        ? (string)$runtime['started_at']
                        : null,
                    $healthyAt,
                ),
            );
        }

        return [
            'role' => (string) $definition['role'],
            'host' => (string) ($runtime['host'] ?? $definition['host']),
            'port' => (int) ($runtime['port'] ?? $definition['port']),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? $definition['token_file_name']),
            'pid' => (int) ($runtime['pid'] ?? 0),
            'healthy' => $healthy,
            'started_at' => $runtime['started_at'] ?? null,
            'healthy_at' => $runtime['healthy_at'] ?? null,
            'process_name' => $runtimeBound
                ? (string)($runtime['process_name'] ?? '')
                : (string)($runtime['process_name'] ?? $definition['process_name']),
            'instance_name' => $runtimeBound
                ? (string)($runtime['instance_name'] ?? '')
                : (string)($runtime['instance_name'] ?? $definition['service_instance_name']),
            'live_observation' => \is_array($runtime['live_observation'] ?? null)
                ? $runtime['live_observation']
                : null,
            'registry_pid_stale' => (bool)($runtime['registry_pid_stale'] ?? false),
            'message' => $healthy ? 'Shared service is healthy.' : 'Shared service is not responding.',
            'shared_service' => true,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     */
    public function stop(string $role, array $config = [], array $envConfig = []): bool
    {
        $role = $this->normalizeRoleName($role);
        return $this->withRoleLifecycleLocks(
            [$role],
            fn(): bool => $this->stopUnlocked($role, $config, $envConfig),
        );
    }

    private function stopUnlocked(
        string $role,
        array $config,
        array $envConfig,
    ): bool {
        $this->recoverRuntimeFileForMutation($role);
        $definition = $this->buildRoleDefinition($role, 'system', $config, $envConfig);

        $registry = $this->createRegistry();
        $selected = $this->selectLifecycleGeneration(
            (string)$definition['role'],
            $this->readRuntimeFile((string)$definition['role']),
            $registry,
        );
        $stopped = $this->forceStopReusedService($definition, $selected);
        if ($stopped) {
            $this->removeSelectedLifecycleGeneration(
                (string)$definition['role'],
                $selected,
                $registry,
            );
        }

        return $stopped;
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

        return $this->ensure(
            $role,
            $config,
            $envConfig,
            $consumerCode !== '' ? $consumerCode : 'system',
            self::resolveEnsureFrontendFlag($config)
        );
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

    /**
     * @param list<string>|null $roles
     * @return array<string, bool> role => renewed
     */
    public function renewInstanceConsumers(string $instanceName, ?array $roles = null): array
    {
        $results = [
            ControlMessage::ROLE_SESSION_SERVER => false,
            ControlMessage::ROLE_MEMORY_SERVER => false,
        ];

        if (!$this->shouldTrackConsumer($instanceName)) {
            return $results;
        }

        $targetRoles = $this->normalizeSharedConsumerRoles($roles);
        foreach ($targetRoles as $role) {
            $deadline = self::monotonicSeconds()
                + self::CONSUMER_RENEW_TRANSACTION_BUDGET_SECONDS;
            try {
                $results[$role] = $this->withRoleLifecycleLocks(
                    [$role],
                    function () use ($role, $instanceName, $deadline): bool {
                        if (VerifiedPersistentFileLock::isHeld(
                            $this->getRuntimeFilePath($role) . '.lock',
                        ) !== false) {
                            return false;
                        }
                        $remaining = $deadline - self::monotonicSeconds();
                        if (!\is_finite($remaining) || $remaining <= 0.0) {
                            return false;
                        }
                        $registry = $this->createRegistry();
                        return $registry->touchConsumerIfAvailable(
                            $role,
                            $instanceName,
                            $remaining,
                        );
                    },
                    \min(
                        self::CONSUMER_RENEW_TRANSACTION_BUDGET_SECONDS,
                        $this->lifecycleLockWaitSeconds(),
                    ),
                );
            } catch (\Throwable $throwable) {
                WlsLogger::warning_(
                    "[SharedStateServiceManager] 共享服务 {$role} consumer token 续租异常: "
                    . $throwable->getMessage()
                );
                $results[$role] = false;
            }
        }

        return $results;
    }

    /**
     * @return array<string, bool> role => registered
     */
    public function registerInstanceConsumers(string $instanceName): array
    {
        return $this->renewInstanceConsumers($instanceName);
    }

    /**
     * @return array<string, bool> role => shared service ACK received
     */
    public function releaseInstanceConsumers(string $instanceName): array
    {
        $results = [
            ControlMessage::ROLE_SESSION_SERVER => false,
            ControlMessage::ROLE_MEMORY_SERVER => false,
        ];

        if (!$this->shouldTrackConsumer($instanceName)) {
            return $results;
        }

        foreach ([ControlMessage::ROLE_SESSION_SERVER, ControlMessage::ROLE_MEMORY_SERVER] as $role) {
            try {
                $registry = $this->createRegistry();
                $runtime = $this->readRuntimeFile($role);
                if ($runtime === []) {
                    $runtime = $registry->getRecord($role);
                }

                $results[$role] = $this->sendSharedServiceConsumerShutdown($role, $instanceName, $runtime);
            } catch (\Throwable $throwable) {
                WlsLogger::warning_(
                    "[SharedStateServiceManager] 共享服务 {$role} consumer token 卸载通知异常: "
                    . $throwable->getMessage()
                );
                $results[$role] = false;
            }
        }

        return $results;
    }

    /**
     * @param list<string>|null $roles
     * @return list<string>
     */
    private function normalizeSharedConsumerRoles(?array $roles): array
    {
        $defaultRoles = [ControlMessage::ROLE_SESSION_SERVER, ControlMessage::ROLE_MEMORY_SERVER];
        if ($roles === null) {
            return $defaultRoles;
        }

        $allowed = \array_fill_keys($defaultRoles, true);
        $normalized = [];
        foreach ($roles as $role) {
            $role = $this->normalizeRoleName((string) $role);
            if (!isset($allowed[$role])) {
                continue;
            }
            $normalized[$role] = $role;
        }

        return \array_values($normalized);
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
        $role = $this->normalizeRoleName($role);
        return $this->withRoleLifecycleLocks(
            [$role],
            fn(): bool => $this->shutdownIfUnusedNow($role, $options),
        );
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
        $record = $this->mergeRuntimeWithRegistryMetadata($role, $this->readRuntimeFile($role));

        $result = \array_merge(
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
                'consumer_count' => 0,
                'shutdown_due_at' => null,
                'enabled' => $shortRole === 'memory' ? $this->isMemoryEnabled([], $envConfig) : true,
            ],
            $record
        );
        if (SharedStateServiceRegistry::hasExactLifecycleBinding($role, $record)) {
            foreach (self::LIFECYCLE_IDENTITY_FIELDS as $field) {
                if (!\array_key_exists($field, $record)) {
                    unset($result[$field]);
                }
            }
        }
        return $result;
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
        $healthy = (bool)($definition['_authenticated_probe_verified'] ?? false)
            || $this->probeRunningSharedService($definition, $configuredTokenFileName);

        if ($healthy) {
            $runtimePid = 0;
            $launchedPid = (int)($definition['_launched_pid'] ?? 0);
            if ($launchedPid > 0) {
                $registryRecord = $this->createRegistry()->getRecord($role);
                $registryToken = \basename((string)($registryRecord['token_file_name'] ?? ''));
                if ((int)($registryRecord['pid'] ?? 0) === $launchedPid
                    && (int)($registryRecord['port'] ?? 0) === (int)$definition['port']
                    && $registryToken === \basename($configuredTokenFileName)
                ) {
                    // The child publishes this record only after owning the
                    // listen socket. Combined with the authenticated ping, the
                    // exact launcher PID is a complete one-hop identity proof.
                    $runtimePid = $launchedPid;
                }
            }
            $runtimeFilePid = (int)($runtimeFile['pid'] ?? 0);
            $sameRuntimePort = (int)($runtimeFile['port'] ?? 0) === (int)$definition['port'];
            if ($runtimePid <= 0
                && $sameRuntimePort
                && $runtimeFilePid > 0
                && Processer::isRunningByPid($runtimeFilePid)
            ) {
                // A successful authenticated protocol probe plus the live PID
                // already persisted for this exact port is sufficient. Avoid a
                // second process-table/command-line inspection on every ensure.
                $runtimePid = $runtimeFilePid;
            }
            if ($runtimePid <= 0) {
                $runtimePid = $this->resolveValidatedLivePortOwnerPid($definition);
            }
            $runtime = $this->buildRuntimeMetadata(
                $definition,
                $runtimePid,
                \is_string($runtimeFile['started_at'] ?? null) ? (string) $runtimeFile['started_at'] : null,
                \date('c')
            );
            // Internal one-hop proof: the authenticated protocol probe and
            // exact live PID validation above already established this
            // sidecar identity. Reconciliation consumes and removes this
            // marker before runtime metadata is persisted.
            $runtime['_authenticated_identity_verified'] = $runtimePid > 0;

            return [
                'healthy' => true,
                'runtime' => \array_merge($runtime, ['reuse_existing' => true]),
                'message' => 'Shared service is healthy.',
            ];
        }

        $port = (int) $definition['port'];
        $portOccupied = $this->isPortOccupied($port);
        $inspection = null;
        if (!$portOccupied) {
            // A wedged listener can keep the kernel port while refusing new
            // TCP connects. Fall back to strict process/role inspection before
            // declaring the port free and launching a duplicate sidecar.
            $inspection = $this->inspectRunningSharedService($definition, $configuredTokenFileName);
        }
        if (!$portOccupied && !(bool)($inspection['in_use'] ?? false)) {
            $runtime = \array_merge($this->buildRuntimeMetadata($definition, 0, null, null), $runtimeFile);
            $runtime['healthy'] = false;

            return [
                'healthy' => false,
                'runtime' => $runtime,
                'message' => 'Shared service is not running.',
            ];
        }

        $inspection = $this->inspectRunningSharedService($definition, $configuredTokenFileName);
        $sameScope = (bool)($inspection['reusable'] ?? false)
            && $this->isInspectionOwnedByCurrentSidecarScope($inspection);

        return [
            'healthy' => false,
            'runtime' => \array_merge($this->buildRuntimeMetadata($definition, 0, null, null), $runtimeFile),
            'unexpected_occupant' => true,
            'message' => $sameScope
                ? \sprintf(
                    'Shared %s port %d is occupied by a current-scope process that failed authenticated PING; refusing to reuse or stop it.',
                    $this->displayNameForRole($role),
                    $port
                )
                : \sprintf(
                    'Shared %s port %d is occupied by an unexpected process.',
                    $this->displayNameForRole($role),
                    $port
                ),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function forceStopReusedService(array $definition, array $runtime): bool
    {
        $role = (string) $definition['role'];
        $record = \array_merge($runtime, [
            'role' => $role,
            'host' => (string) ($runtime['host'] ?? $definition['host']),
            'port' => (int) ($runtime['port'] ?? $definition['port']),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? $definition['token_file_name']),
            'pid' => (int) ($runtime['pid'] ?? 0),
        ]);

        $stopped = $this->forceStopSharedService($record);
        $host = (string)$record['host'];
        $port = (int)$record['port'];
        $portReleased = !$this->probeTcpPortInUse($host, $port);
        if (!$stopped && !$portReleased) {
            WlsLogger::warning_(
                '[SharedStateServiceManager] retaining runtime identity after failed stop: '
                . 'role=' . $role . ', host=' . $host . ', port=' . $port
            );
        }

        // Lifecycle callers own generation-CAS cleanup after this method
        // returns. Keeping selection and deletion separate prevents an old
        // shutdown from unlinking a replacement generation.
        return $stopped || $portReleased;
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function forceStopSharedService(array $record): bool
    {
        $role = $this->normalizeRoleName((string) ($record['role'] ?? ''));
        $host = \trim((string) ($record['host'] ?? '127.0.0.1'));
        $port = (int) ($record['port'] ?? 0);
        $tokenFileName = \trim((string) ($record['token_file_name'] ?? $this->defaultTokenForRole($role)));

        if ($host === '' || $port <= 0) {
            return false;
        }

        $protocolHealthy = $this->probeRunningSharedService(['host' => $host, 'port' => $port], $tokenFileName);
        $inspection = $this->inspectRunningSharedService([
            'role' => $role,
            'host' => $host,
            'port' => $port,
        ], $tokenFileName);
        if (!$protocolHealthy || !(bool)($inspection['in_use'] ?? false)) {
            return false;
        }
        if (!$this->inspectionMatchesSelectedLifecycle($role, $record, $inspection)
            || !$this->selectedLifecycleIsCurrent($role, $record)
        ) {
            WlsLogger::warning_(
                '[SharedStateServiceManager] refusing graceful shutdown after lifecycle replacement: '
                . 'role=' . $role
                . ', selected_generation=' . (int)($record['lifecycle_generation'] ?? 0)
                . ', selected_pid=' . (int)($record['pid'] ?? 0)
                . ', observed_pid=' . (int)($inspection['pid'] ?? 0)
            );
            return false;
        }

        $shutdownRequested = $this->sendSharedServiceServerShutdown($record);
        if ($shutdownRequested && $this->waitForSharedServicePortRelease($host, $port, 2.0)) {
            return true;
        }

        // Authenticated PING and command/scope inspection prove which service
        // answered, but the runtime record does not carry a host-boot-bound
        // process-birth identity. After graceful shutdown fails, its numeric
        // PID is therefore diagnostic only and cannot authorize a signal.
        // Leave the sidecar in place and let an exact platform/credential tree
        // recovery path handle it rather than risking a reused foreign PID.
        if ((bool)($inspection['reusable'] ?? false)) {
            WlsLogger::error_(
                '[SharedStateServiceManager] graceful shutdown failed; refusing PID fallback '
                . 'without a credential-bound process-birth lease: role=' . $role
                . ', port=' . $port
                . ', pid=' . (int)($inspection['pid'] ?? 0)
            );
        }

        return !$this->probeTcpPortInUse($host, $port);
    }

    /**
     * @param array<string,mixed> $selected
     * @param array<string,mixed> $inspection
     */
    private function inspectionMatchesSelectedLifecycle(
        string $role,
        array $selected,
        array $inspection,
    ): bool {
        if (!SharedStateServiceRegistry::hasExactLifecycleBinding($role, $selected)
            || !(bool)($inspection['reusable'] ?? false)
            || (int)($inspection['pid'] ?? 0) !== (int)($selected['pid'] ?? 0)
            || (int)($inspection['port'] ?? 0) !== (int)($selected['port'] ?? 0)
            || !\hash_equals($role, (string)($inspection['role'] ?? ''))
        ) {
            return false;
        }
        $selectedToken = \basename((string)($selected['token_file_name'] ?? ''));
        $observedToken = \basename((string)($inspection['token_file_name'] ?? ''));
        if ($selectedToken === ''
            || $observedToken === ''
            || !\hash_equals($selectedToken, $observedToken)
        ) {
            return false;
        }
        $observed = $selected;
        $observed['role'] = $role;
        $observed['port'] = (int)$inspection['port'];
        $observed['pid'] = (int)$inspection['pid'];
        $observed['token_file_name'] = $observedToken;
        foreach (['process_name', 'instance_name'] as $field) {
            $expected = \trim((string)($selected[$field] ?? ''));
            if ($expected === '') {
                continue;
            }
            $value = \trim((string)($inspection[$field] ?? ''));
            if ($value === '' || !\hash_equals($expected, $value)) {
                return false;
            }
            $observed[$field] = $value;
        }
        $serviceInstance = \trim((string)($selected['service_instance_name'] ?? ''));
        if ($serviceInstance !== '') {
            $observedInstance = \trim((string)($inspection['instance_name'] ?? ''));
            if ($observedInstance === ''
                || !\hash_equals($serviceInstance, $observedInstance)
            ) {
                return false;
            }
            $observed['service_instance_name'] = $observedInstance;
        }
        return \hash_equals(
            (string)$selected['lifecycle_identity_digest'],
            SharedStateServiceRegistry::lifecycleIdentityDigest($role, $observed),
        );
    }

    /** @param array<string,mixed> $selected */
    private function selectedLifecycleIsCurrent(string $role, array $selected): bool
    {
        if (!SharedStateServiceRegistry::hasExactLifecycleBinding($role, $selected)) {
            return false;
        }
        try {
            $authority = self::highestLifecycleAuthority(
                $role,
                $this->createRegistry()->getRecord($role),
                $this->readRuntimeFile($role),
            );
        } catch (\Throwable) {
            return false;
        }
        return SharedStateServiceRegistry::hasExactLifecycleBinding($role, $authority)
            && (int)($authority['lifecycle_generation'] ?? 0)
                === (int)($selected['lifecycle_generation'] ?? 0)
            && \hash_equals(
                (string)($authority['lifecycle_identity_digest'] ?? ''),
                (string)($selected['lifecycle_identity_digest'] ?? ''),
            );
    }

    private function waitForSharedServicePortRelease(string $host, int $port, float $timeoutSec): bool
    {
        $deadline = self::monotonicSeconds() + \max(0.05, $timeoutSec);
        do {
            if (!$this->probeTcpPortInUse($host, $port, 0.05)) {
                return true;
            }
            SchedulerSystem::yieldDelay(25);
        } while (self::monotonicSeconds() < $deadline);

        return !$this->probeTcpPortInUse($host, $port, 0.05);
    }

    protected function sendSharedServiceConsumerShutdown(string $role, string $consumerCode, array $runtime): bool
    {
        return $this->sendSharedServiceShutdown($role, $runtime, $consumerCode, []);
    }

    protected function sendSharedServiceServerShutdown(array $runtime): bool
    {
        return $this->sendSharedServiceShutdown((string) ($runtime['role'] ?? ''), $runtime, null, ['server' => true]);
    }

    protected function sendSharedServiceShutdown(
        string $role,
        array $runtime,
        ?string $consumerCode,
        array $params
    ): bool {
        $role = $this->normalizeRoleName($role);
        $host = \trim((string) ($runtime['host'] ?? '127.0.0.1'));
        $port = (int) ($runtime['port'] ?? 0);
        $tokenFileName = \trim((string) ($runtime['token_file_name'] ?? $this->defaultTokenForRole($role)));
        if ($host === '' || $port <= 0 || $tokenFileName === '') {
            return false;
        }

        try {
            return SharedStateProtocolProbe::shutdownWithTokenBasename(
                $host,
                $port,
                $tokenFileName,
                $consumerCode,
                $params,
                $role,
            );
        } catch (\Throwable) {
            return false;
        }
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
        try {
            return SharedStateProtocolProbe::pingWithTokenBasename(
                (string) $definition['host'],
                (int) $definition['port'],
                $tokenFileName,
                (string) $definition['role'],
            );
        } catch (\Throwable) {
            return false;
        }
    }

    protected function isPortOccupied(int $port): bool
    {
        return $this->probePortInUse($port);
    }

    protected function probeTcpPortInUse(string $host, int $port, float $timeoutSec = 0.15): bool
    {
        $host = \trim($host);
        if ($host === '' || $port <= 0) {
            return false;
        }

        $targetHost = $host;
        if (\strpos($host, ':') !== false && ($host[0] ?? '') !== '[') {
            $targetHost = '[' . $host . ']';
        }

        $errno = 0;
        $errstr = '';
        $socket = @\stream_socket_client(
            'tcp://' . $targetHost . ':' . $port,
            $errno,
            $errstr,
            $timeoutSec,
            \STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            return false;
        }

        @\fclose($socket);

        return true;
    }

    /**
     * @param array<string, mixed> $definition
     */
    /**
     * @param array<int, array<string, mixed>> $definitions
     * @return array<string, int>
     */
    protected function launchSharedServiceProcessesBatch(
        array $definitions,
        string $requesterInstanceName,
        bool $frontend = false
    ): array {
        if ($definitions === []) {
            return [];
        }

        // Only explicit frontend mode needs one visible console per sidecar.
        // Normal Windows startup uses the same batch launcher as POSIX so
        // Session and Memory bootstrap concurrently under one launch budget.
        if ($frontend) {
            $pids = [];
            foreach ($definitions as $definition) {
                $role = (string)($definition['role'] ?? '');
                $pid = $this->launchSharedServiceProcess($definition, $requesterInstanceName, true);
                if ($pid <= 0) {
                    throw new \RuntimeException($this->buildSharedSpawnFailureMessage($definition));
                }
                $pids[$role] = $pid;
            }
            return $pids;
        }

        $commands = [];
        foreach ($definitions as $definition) {
            $command = $this->buildLaunchCommand($definition, $requesterInstanceName);
            $role = (string)($definition['role'] ?? '');
            $processName = (string)($command->getProcessName() ?? '');
            $registryIdentity = $command->build();
            $argv = \array_merge(
                [PHP_BINARY],
                LongRunningPhpRuntime::startupCliArguments(),
                [$command->getAbsoluteScript()],
                \array_map(static fn(mixed $argument): string => (string)$argument, $command->arguments)
            );
            if ($processName !== '') {
                $registryIdentity .= ' --name=' . \escapeshellarg($processName);
                $argv[] = '--name=' . $processName;
            }
            $logInstanceName = \trim((string)($definition['service_instance_name'] ?? $requesterInstanceName));
            if ($logInstanceName === '') {
                $logInstanceName = 'default';
            }
            $commands[$role] = $this->buildSharedProcessBatchConfig(
                $registryIdentity,
                $argv,
                $command->getWorkingDir(),
                $processName,
                $logInstanceName,
                false
            );
        }

        $pids = Processer::batchCreate($commands);
        foreach ($definitions as $definition) {
            $role = (string)($definition['role'] ?? '');
            if ((int)($pids[$role] ?? 0) <= 0) {
                throw new \RuntimeException($this->buildSharedSpawnFailureMessage($definition));
            }
        }
        return \array_map(static fn(mixed $pid): int => (int)$pid, $pids);
    }

    protected function buildSharedProcessBatchConfig(
        string $registryIdentity,
        array $argv,
        string $workingDir,
        string $processName,
        string $logInstanceName,
        bool $frontend,
        ?string $configuredLogPath = null
    ): array {
        if ($frontend) {
            $registryIdentity .= ' --win';
            $argv[] = '--win';
        }

        return \array_merge(
            [
                'command' => $registryIdentity,
                'block' => false,
                'foreground' => $frontend,
                'enableLog' => true,
                'childOwnsPid' => true,
                'isolateParentHandles' => \defined('IS_WIN') && IS_WIN,
                'windowsArgv' => $argv,
                'cwd' => $workingDir,
            ],
            WlsLogService::getProcessLaunchLogConfig(
                processName: $processName,
                instanceName: $logInstanceName,
                enableLog: true,
                configuredPath: $configuredLogPath
            )
        );
    }

    protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName, bool $frontend = false): int
    {
        $command = $this->buildLaunchCommand($definition, $requesterInstanceName);
        $registryIdentity = $command->build();
        $processName = (string)($command->getProcessName() ?? '');
        $argv = \array_merge(
            [PHP_BINARY],
            LongRunningPhpRuntime::startupCliArguments(),
            [$command->getAbsoluteScript()],
            \array_map(static fn(mixed $argument): string => (string)$argument, $command->arguments)
        );
        if ($processName !== '') {
            $registryIdentity .= ' --name=' . \escapeshellarg($processName);
            $argv[] = '--name=' . $processName;
        }

        $logInstanceName = \trim((string)($definition['service_instance_name'] ?? $requesterInstanceName));
        if ($logInstanceName === '') {
            $logInstanceName = 'default';
        }
        $pids = Processer::batchCreate([
            'shared-service' => $this->buildSharedProcessBatchConfig(
                $registryIdentity,
                $argv,
                $command->getWorkingDir(),
                $processName,
                $logInstanceName,
                $frontend
            ),
        ]);

        return (int)($pids['shared-service'] ?? 0);
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
     * 与 SessionServer / PooledConnection 使用同一个运行环境本地 token 目录。
     */
    private function formatSharedTokenFilePathForMessage(string $tokenFileName): string
    {
        $tokenFileName = \trim($tokenFileName);
        if ($tokenFileName === '') {
            $tokenFileName = SharedStateRuntimeScope::scopeDefaultFileName('session_server.token');
        }

        return SharedStateRuntimeScope::tokenFilePath($tokenFileName);
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function buildLaunchCommand(array $definition, string $requesterInstanceName): ServiceCommand
    {
        $sharedLogInstanceName = (string) ($definition['service_instance_name'] ?? $requesterInstanceName);
        if (\trim($sharedLogInstanceName) === '') {
            $sharedLogInstanceName = 'default';
        }

        $arguments = [
            (string) $definition['host'],
            (string) $definition['port'],
            (string) $definition['service_instance_name'],
            '--instance-name=' . (string) $definition['service_instance_name'],
            '--token-file-name=' . (string) $definition['token_file_name'],
            '--bootstrap-instance=' . $requesterInstanceName,
            '--log-instance-name=' . $sharedLogInstanceName,
            '--shared-service=1',
            '--memory-limit=' . (string) ($definition['memory_limit'] ?? '256M'),
            '--launch-id=sidecar-' . \bin2hex(\random_bytes(16)),
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
     * Status is read-only: it should not run port adoption or command-line ownership scans.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    protected function buildStatusProbeDefinition(
        string $role,
        array $config,
        array $envConfig,
        array $runtime
    ): array {
        $role = $this->normalizeRoleName($role);
        $wlsConfig = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $sidecarScope = SharedStateRuntimeScope::sidecarIdentityToken();

        if ($role === ControlMessage::ROLE_MEMORY_SERVER) {
            $memoryConfig = \is_array($wlsConfig['memory_service'] ?? null) ? $wlsConfig['memory_service'] : [];
            $defaultPort = 19971 + MasterProcess::getProjectPortOffset();
            $port = (int) (
                $runtime['port']
                ?? $config['memory_server_port']
                ?? $memoryConfig['port']
                ?? $defaultPort
            );
            $tokenFileName = \trim((string) (
                $runtime['token_file_name']
                ?? $config['memory_server_token_file_name']
                ?? $memoryConfig['token_file_name']
                ?? $this->defaultTokenForRole($role)
            ));
            if ($tokenFileName === '') {
                $tokenFileName = $this->defaultTokenForRole($role);
            }

            return [
                'role' => $role,
                'display_name' => 'Memory Service',
                'host' => (string) ($runtime['host'] ?? '127.0.0.1'),
                'port' => $port,
                'token_file_name' => \basename($tokenFileName),
                'process_name' => (string) (
                    $runtime['process_name']
                    ?? (MemoryServerProvider::PROCESS_NAME_PREFIX . '-' . $sidecarScope . '-shared-' . $port)
                ),
                'service_instance_name' => (string) (
                    $runtime['service_instance_name']
                    ?? $runtime['instance_name']
                    ?? ('shared-memory-' . $sidecarScope . '-' . $port)
                ),
            ];
        }

        $sessionConfig = \is_array($envConfig['session'] ?? null) ? $envConfig['session'] : [];
        $wlsSession = \is_array($wlsConfig['session'] ?? null) ? $wlsConfig['session'] : [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];
        $defaultPort = 19970 + MasterProcess::getProjectPortOffset();
        $port = (int) (
            $runtime['port']
            ?? $config['session_server_port']
            ?? $wlsServer['port']
            ?? $wlsSession['port']
            ?? $sessionConfig['server_port']
            ?? $defaultPort
        );
            $tokenFileName = \trim((string) (
                $runtime['token_file_name']
                ?? $config['session_server_token_file_name']
                ?? $wlsServer['token_file_name']
                ?? $wlsSession['token_file_name']
                ?? $this->defaultTokenForRole($role)
            ));
            if ($tokenFileName === '') {
                $tokenFileName = $this->defaultTokenForRole($role);
            }

        return [
            'role' => $role,
            'display_name' => 'Session Server',
            'host' => (string) ($runtime['host'] ?? '127.0.0.1'),
            'port' => $port,
            'token_file_name' => \basename($tokenFileName),
            'process_name' => (string) (
                $runtime['process_name']
                ?? (SessionServerProvider::PROCESS_NAME_PREFIX . '-' . $sidecarScope . '-shared-' . $port)
            ),
            'service_instance_name' => (string) (
                $runtime['service_instance_name']
                ?? $runtime['instance_name']
                ?? ('shared-session-' . $sidecarScope . '-' . $port)
            ),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @param list<int> $reservedPorts Ports already assigned to another role in the same startup batch.
     * @return array<string, mixed>
     */
    protected function buildRoleDefinition(
        string $role,
        string $requesterInstanceName,
        array $config,
        array $envConfig,
        array $reservedPorts = []
    ): array {
        $role = $this->normalizeRoleName($role);
        $wlsConfig = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $sidecarScope = SharedStateRuntimeScope::sidecarIdentityToken();
        $sharedState = \is_array($wlsConfig['shared_state'] ?? null) ? $wlsConfig['shared_state'] : [];
        $ensureTimeoutSec = (float) ($sharedState['ensure_timeout_sec'] ?? self::DEFAULT_ENSURE_TIMEOUT_SEC);
        $ensurePollIntervalMs = (int) ($sharedState['ensure_poll_interval_ms'] ?? self::DEFAULT_ENSURE_POLL_INTERVAL_MS);
        $memoryLimit = \Weline\Server\Service\Contract\ServiceContext::normalizeMemoryLimit(
            $config['worker_memory_limit'] ?? $wlsConfig['worker_memory_limit'] ?? '256M'
        );

        if ($role === ControlMessage::ROLE_MEMORY_SERVER) {
            $memoryConfig = \is_array($wlsConfig['memory_service'] ?? null) ? $wlsConfig['memory_service'] : [];
            // 仅 env/wls 中显式端口视为「用户钉死」；勿用 Master 注入的 runtime 端口禁用启动阶段可用性扫描。
            $memoryPortExplicit = \array_key_exists('port', $memoryConfig)
                || (bool)($config['_memory_server_port_explicit'] ?? false);
            // 仅 env/wls 中显式 token_file_name 视为「用户钉死」；
            // 勿用 runtime token 作为用户钉死配置，避免继续固定旧端口 token。
            $memoryTokenExplicit = \trim((string)($memoryConfig['token_file_name'] ?? '')) !== ''
                || (bool)($config['_memory_server_token_file_name_explicit'] ?? false);

            // 默认端口 19971 + 项目偏移量，确保多项目不冲突
            $defaultPort = 19971 + MasterProcess::getProjectPortOffset();
            $port = (int) ($config['memory_server_port'] ?? $memoryConfig['port'] ?? $defaultPort);
            if ($port <= 0) {
                $port = $defaultPort;
            }

            $tokenFileName = \trim((string) (
                $config['memory_server_token_file_name']
                ?? $memoryConfig['token_file_name']
                ?? $this->defaultTokenForRole($role)
            ));
            if ($tokenFileName === '') {
                $tokenFileName = $this->defaultTokenForRole($role);
            }

            $port = $this->resolveSharedServicePort(
                $role,
                $port,
                $tokenFileName,
                $memoryPortExplicit,
                $reservedPorts
            );
            $tokenFileName = $this->resolveSharedServiceTokenFileName(
                $role,
                $tokenFileName,
                $port,
                $memoryTokenExplicit
            );

            return [
                'role' => $role,
                'display_name' => 'Memory Service',
                'host' => '127.0.0.1',
                'port' => $port,
                'token_file_name' => $tokenFileName,
                'process_name' => MemoryServerProvider::PROCESS_NAME_PREFIX . '-' . $sidecarScope . '-shared-' . $port,
                'service_instance_name' => 'shared-memory-' . $sidecarScope . '-' . $port,
                'requester_instance_name' => $requesterInstanceName,
                'ensure_timeout_sec' => $ensureTimeoutSec,
                'ensure_poll_interval_ms' => $ensurePollIntervalMs,
                'memory_limit' => $memoryLimit,
            ];
        }

        $sessionConfig = \is_array($envConfig['session'] ?? null) ? $envConfig['session'] : [];
        $wlsSession = \is_array($wlsConfig['session'] ?? null) ? $wlsConfig['session'] : [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];
        // 仅 env 中显式端口视为「用户钉死」；勿用 $config['session_server_port']（server:start 写入的运行时端口非用户意图钉死）
        $sessionPortExplicit = \array_key_exists('port', $wlsServer)
            || \array_key_exists('port', $wlsSession)
            || \array_key_exists('server_port', $sessionConfig)
            || (bool)($config['_session_server_port_explicit'] ?? false);
        // 仅 env/wls 中显式 token_file_name 视为「用户钉死」；
        // 勿用 runtime token 作为用户钉死配置，避免继续固定旧端口 token。
        $sessionTokenExplicit = \trim((string)($wlsServer['token_file_name'] ?? '')) !== ''
            || \trim((string)($wlsSession['token_file_name'] ?? '')) !== ''
            || (bool)($config['_session_server_token_file_name_explicit'] ?? false);

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
            ?? $this->defaultTokenForRole($role)
        ));
        if ($tokenFileName === '') {
            $tokenFileName = $this->defaultTokenForRole($role);
        }

        $port = $this->resolveSharedServicePort(
            $role,
            $port,
            $tokenFileName,
            $sessionPortExplicit,
            $reservedPorts
        );
        $tokenFileName = $this->resolveSharedServiceTokenFileName(
            $role,
            $tokenFileName,
            $port,
            $sessionTokenExplicit
        );

        return [
            'role' => $role,
            'display_name' => 'Session Server',
            'host' => '127.0.0.1',
            'port' => $port,
            'token_file_name' => $tokenFileName,
            'process_name' => SessionServerProvider::PROCESS_NAME_PREFIX . '-' . $sidecarScope . '-shared-' . $port,
            'service_instance_name' => 'shared-session-' . $sidecarScope . '-' . $port,
            'requester_instance_name' => $requesterInstanceName,
            'ensure_timeout_sec' => $ensureTimeoutSec,
            'ensure_poll_interval_ms' => $ensurePollIntervalMs,
            'memory_limit' => $memoryLimit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function readRuntimeFile(string $role): array
    {
        $role = $this->normalizeRoleName($role);
        $path = $this->getRuntimeFilePath($role);
        $data = ServerInstanceManager::readValidatedJsonStatic(
            $path,
            self::runtimeRecoveryValidator($role),
            'WLS shared-state ' . $this->toShortRole($role) . ' runtime',
            self::MAX_RUNTIME_FILE_BYTES,
        );
        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $runtime
     */
    protected function writeRuntimeFile(string $role, array $runtime): void
    {
        $role = $this->normalizeRoleName($role);
        $path = $this->getRuntimeFilePath($role);
        $payload = [
            'schema' => self::RUNTIME_SCHEMA,
            'role' => $role,
            'host' => (string) ($runtime['host'] ?? '127.0.0.1'),
            'port' => (int) ($runtime['port'] ?? $this->defaultPortForRole($role)),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? $this->defaultTokenForRole($role)),
            'pid' => (int) ($runtime['pid'] ?? 0),
            'started_at' => $runtime['started_at'] ?? null,
            'healthy_at' => $runtime['healthy_at'] ?? null,
            'process_name' => (string) ($runtime['process_name'] ?? ''),
            'instance_name' => (string) ($runtime['instance_name'] ?? ''),
            'service_instance_name' => (string) ($runtime['service_instance_name'] ?? ''),
            'reuse_existing' => (bool) ($runtime['reuse_existing'] ?? false),
            'created_now' => (bool) ($runtime['created_now'] ?? false),
            'shared_service' => (bool) ($runtime['shared_service'] ?? false),
            'registered' => (bool) ($runtime['registered'] ?? false),
            'consumer_count' => (int) ($runtime['consumer_count'] ?? 0),
            'shutdown_due_at' => $runtime['shutdown_due_at'] ?? null,
        ];
        foreach ([
            'lifecycle_schema',
            'lifecycle_generation',
            'lifecycle_identity_digest',
        ] as $field) {
            if (\array_key_exists($field, $runtime)) {
                $payload[$field] = $runtime[$field];
            }
        }
        if (!ServerInstanceManager::updateValidatedJsonFileAtomically(
            $path,
            static function (array $previous) use ($role, $payload): array {
                $boundPayload = SharedStateServiceRegistry::bindLifecycleGeneration(
                    $role,
                    $payload,
                    $previous,
                );
                if (!SharedStateServiceRegistry::hasExactLifecycleBinding(
                    $role,
                    $boundPayload,
                )) {
                    throw new \RuntimeException(
                        'Unable to bind the shared-state runtime to a complete lifecycle identity.'
                    );
                }
                return $boundPayload;
            },
            self::runtimeRecoveryValidator($role),
            'WLS shared-state ' . $this->toShortRole($role) . ' runtime',
            self::MAX_RUNTIME_FILE_BYTES,
        )) {
            throw new \RuntimeException('Unable to persist shared-state runtime file.');
        }
    }

    protected function removeRuntimeFile(string $role): void
    {
        $role = $this->normalizeRoleName($role);
        $this->recoverRuntimeFileForMutation($role);
        $selected = $this->readRuntimeFile($role);
        if ($selected === []) {
            return;
        }
        $generation = (int)($selected['lifecycle_generation'] ?? 0);
        $digest = (string)($selected['lifecycle_identity_digest'] ?? '');
        if (!$this->removeRuntimeFileIfGeneration($role, $generation, $digest)) {
            throw new \RuntimeException(
                'Shared-state runtime generation changed before removal.'
            );
        }
    }

    protected function removeRuntimeFileIfGeneration(
        string $role,
        int $expectedGeneration,
        string $expectedIdentityDigest,
    ): bool {
        $role = $this->normalizeRoleName($role);
        if ($expectedGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedIdentityDigest) !== 1
        ) {
            return false;
        }
        return ServerInstanceManager::removeValidatedJsonFileIf(
            $this->getRuntimeFilePath($role),
            static fn(array $data): bool =>
                (int)($data['lifecycle_generation'] ?? 0) === $expectedGeneration
                && \hash_equals(
                    (string)($data['lifecycle_identity_digest'] ?? ''),
                    $expectedIdentityDigest,
                ),
            self::runtimeRecoveryValidator($role),
            'WLS shared-state ' . $this->toShortRole($role) . ' runtime',
            self::MAX_RUNTIME_FILE_BYTES,
        );
    }

    /**
     * Recover a unique committed backup before a lifecycle mutation performs
     * any read. Read-only status paths deliberately remain observation-only.
     */
    protected function recoverRuntimeFileForMutation(string $role): void
    {
        $role = $this->normalizeRoleName($role);
        if (!ServerInstanceManager::updateValidatedJsonFileAtomically(
            $this->getRuntimeFilePath($role),
            static fn(array $current): array => $current,
            self::runtimeRecoveryValidator($role),
            'WLS shared-state ' . $this->toShortRole($role) . ' runtime',
            self::MAX_RUNTIME_FILE_BYTES,
        )) {
            throw new \RuntimeException(
                'Unable to recover shared-state runtime before lifecycle mutation.'
            );
        }
    }

    /** @return \Closure(string):void */
    private static function runtimeRecoveryValidator(string $expectedRole): \Closure
    {
        return static function (string $raw) use ($expectedRole): void {
            if ($raw === '' || \strlen($raw) > self::MAX_RUNTIME_FILE_BYTES) {
                throw new \RuntimeException(
                    'WLS shared-state runtime size is invalid.'
                );
            }
            $data = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                throw new \RuntimeException(
                    'WLS shared-state runtime is not a JSON object.'
                );
            }
            $schema = (string)($data['schema'] ?? '');
            if ($schema !== '' && !\hash_equals(self::RUNTIME_SCHEMA, $schema)) {
                throw new \RuntimeException(
                    'WLS shared-state runtime schema is invalid.'
                );
            }
            $recordRole = \trim((string)($data['role'] ?? $expectedRole));
            $host = \trim((string)($data['host'] ?? ''));
            $port = (int)($data['port'] ?? 0);
            $pid = (int)($data['pid'] ?? 0);
            $token = \trim((string)($data['token_file_name'] ?? ''));
            if (!\hash_equals($expectedRole, $recordRole)
                || $host === ''
                || $port < 1
                || $port > 65535
                || $pid < 0
                || $token === ''
                || $token === '.'
                || $token === '..'
                || \str_contains($token, "\0")
                || \strlen($token) > 255
                || !\hash_equals(
                    \basename(\str_replace('\\', '/', $token)),
                    $token,
                )
            ) {
                throw new \RuntimeException(
                    'WLS shared-state runtime identity fields are invalid.'
                );
            }
            $hasBinding = \array_key_exists('lifecycle_schema', $data)
                || \array_key_exists('lifecycle_generation', $data)
                || \array_key_exists('lifecycle_identity_digest', $data);
            if (($schema !== '' || $hasBinding)
                && !SharedStateServiceRegistry::hasExactLifecycleBinding(
                    $expectedRole,
                    $data,
                )
            ) {
                throw new \RuntimeException(
                    'WLS shared-state runtime lifecycle binding is invalid.'
                );
            }
        };
    }

    protected function getRuntimeFilePath(string $role): string
    {
        $shortRole = $this->toShortRole($role);

        return Env::VAR_DIR . 'server' . \DIRECTORY_SEPARATOR . 'shared' . \DIRECTORY_SEPARATOR
            . SharedStateRuntimeScope::scopeDefaultFileName($shortRole . '.json');
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

    protected function createRegistry(): SharedStateServiceRegistry
    {
        return new SharedStateServiceRegistry();
    }

    /**
     * @template TResult
     * @param list<string> $roles
     * @param \Closure():TResult $callback
     * @param float|null $waitTimeoutSeconds Null uses the lifecycle default.
     * @return TResult
     */
    private function withRoleLifecycleLocks(
        array $roles,
        \Closure $callback,
        ?float $waitTimeoutSeconds = null,
    ): mixed {
        $normalized = [];
        foreach ($roles as $role) {
            $role = $this->normalizeRoleName($role);
            $normalized[$role] = $role;
        }
        \ksort($normalized, SORT_STRING);
        return $this->acquireRoleLifecycleLocks(
            \array_values($normalized),
            0,
            $callback,
            $waitTimeoutSeconds ?? $this->lifecycleLockWaitSeconds(),
        );
    }

    /**
     * @template TResult
     * @param list<string> $roles
     * @param \Closure():TResult $callback
     * @return TResult
     */
    private function acquireRoleLifecycleLocks(
        array $roles,
        int $offset,
        \Closure $callback,
        float $waitTimeoutSeconds,
    ): mixed {
        if (!isset($roles[$offset])) {
            return $callback();
        }
        $path = $this->getRoleLifecycleLockPath($roles[$offset]);
        $directory = \dirname($path);
        if (!\is_dir($directory)
            && !@\mkdir($directory, 0755, true)
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Unable to create the WLS shared-state lifecycle directory.'
            );
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'WLS shared-state lifecycle directory is unsafe.'
            );
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $path,
            fn(): mixed => $this->acquireRoleLifecycleLocks(
                $roles,
                $offset + 1,
                $callback,
                $waitTimeoutSeconds,
            ),
            waitTimeoutSeconds: $waitTimeoutSeconds,
        );
    }

    protected function getRoleLifecycleLockPath(string $role): string
    {
        $shortRole = $this->toShortRole($this->normalizeRoleName($role));
        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'shared'
            . DIRECTORY_SEPARATOR
            . SharedStateRuntimeScope::scopeDefaultFileName(
                $shortRole . '.lifecycle.lock',
            );
    }

    protected function lifecycleLockWaitSeconds(): float
    {
        return self::DEFAULT_LIFECYCLE_LOCK_WAIT_SECONDS;
    }

    /**
     * Select and, for legacy/incomplete files, publish one exact lifecycle
     * generation before a destructive operation authenticates the sidecar.
     * The caller must already hold the stable per-role lifecycle lock.
     *
     * @return array<string,mixed>
     */
    private function selectLifecycleGeneration(
        string $role,
        array $runtime,
        SharedStateServiceRegistry $registry,
    ): array {
        $role = $this->normalizeRoleName($role);
        $record = $registry->getRecord($role);
        $recordBound = SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $record,
        );
        $runtimeBound = SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $runtime,
        );
        $authority = self::highestLifecycleAuthority($role, $record, $runtime);
        if ($recordBound
            && $runtimeBound
            && (int)($record['lifecycle_generation'] ?? 0)
                === (int)($runtime['lifecycle_generation'] ?? 0)
        ) {
            $selected = \array_merge($runtime, $record);
            foreach (self::LIFECYCLE_IDENTITY_FIELDS as $field) {
                if (\array_key_exists($field, $record)) {
                    $selected[$field] = $record[$field];
                } else {
                    unset($selected[$field]);
                }
            }
            $selected['role'] = $role;
            return $selected;
        }

        $selected = $authority === $runtime
            ? \array_merge($record, $runtime)
            : \array_merge($runtime, $record);
        if ($authority !== []) {
            foreach (self::LIFECYCLE_IDENTITY_FIELDS as $field) {
                if (\array_key_exists($field, $authority)) {
                    $selected[$field] = $authority[$field];
                } else {
                    unset($selected[$field]);
                }
            }
        }
        $selected['role'] = $role;
        $selected = SharedStateServiceRegistry::bindLifecycleGeneration(
            $role,
            $selected,
            $authority,
        );
        if (SharedStateServiceRegistry::lifecycleIdentityDigest(
            $role,
            $selected,
        ) === '') {
            return $selected;
        }

        $published = $this->publishRuntimeRecord($role, $selected, $registry);
        if ($published !== []) {
            $selected = \array_merge($selected, $published);
        }
        if (SharedStateServiceRegistry::hasExactLifecycleBinding($role, $selected)) {
            $this->writeRuntimeFile($role, $selected);
        }
        return $selected;
    }

    /**
     * Choose one complete generation/identity tuple. Equal generations are a
     * single authority only when their canonical identity digests agree.
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $runtime
     * @return array<string,mixed>
     */
    private static function highestLifecycleAuthority(
        string $role,
        array $record,
        array $runtime,
    ): array {
        $recordBound = SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $record,
        );
        $runtimeBound = SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $runtime,
        );
        if ($recordBound && $runtimeBound) {
            $recordGeneration = (int)($record['lifecycle_generation'] ?? 0);
            $runtimeGeneration = (int)($runtime['lifecycle_generation'] ?? 0);
            if ($recordGeneration === $runtimeGeneration
                && !\hash_equals(
                    (string)($record['lifecycle_identity_digest'] ?? ''),
                    (string)($runtime['lifecycle_identity_digest'] ?? ''),
                )
            ) {
                $completed = self::preferConvergedLifecycleIdentity(
                    $role,
                    $record,
                    $runtime,
                );
                if ($completed !== null) {
                    return $completed;
                }
                throw new \RuntimeException(
                    'Conflicting WLS shared-state lifecycle identities claim the same generation.'
                );
            }
            return $runtimeGeneration > $recordGeneration ? $runtime : $record;
        }
        if ($recordBound) {
            return $record;
        }
        return $runtimeBound ? $runtime : [];
    }

    /**
     * Same generation with different digests is usually a hard fork. Recoverable
     * cases remain when both sides describe the same live sidecar ownership:
     * - registry published before managed process/instance names were known;
     * - wait-loop runtime stamped started_at one second before the sidecar bind.
     * Prefer the registry tuple; it is written by the owning sidecar process.
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $runtime
     * @return array<string,mixed>|null
     */
    private static function preferConvergedLifecycleIdentity(
        string $role,
        array $record,
        array $runtime,
    ): ?array {
        if (!self::lifecycleLiveOwnershipEquals($role, $record, $runtime)) {
            return null;
        }
        $recordComplete = self::hasManagedLifecycleNames($record);
        $runtimeComplete = self::hasManagedLifecycleNames($runtime);
        if ($recordComplete !== $runtimeComplete) {
            return $runtimeComplete ? $runtime : $record;
        }
        if (!$recordComplete || !$runtimeComplete) {
            return null;
        }
        if (!self::managedLifecycleNamesEqual($record, $runtime)) {
            return null;
        }

        // Same live ownership and managed names: only started_at (or another
        // non-ownership stamp) diverged. Sidecar registry wins.
        return $record;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private static function lifecycleLiveOwnershipEquals(
        string $role,
        array $left,
        array $right,
    ): bool {
        $role = \trim($role);
        $leftHost = \strtolower(\trim((string)($left['host'] ?? '')));
        $rightHost = \strtolower(\trim((string)($right['host'] ?? '')));
        $leftToken = \trim((string)($left['token_file_name'] ?? ''));
        $rightToken = \trim((string)($right['token_file_name'] ?? ''));
        $leftRole = \trim((string)($left['role'] ?? $role));
        $rightRole = \trim((string)($right['role'] ?? $role));
        if ($leftHost === ''
            || $rightHost === ''
            || $leftToken === ''
            || $rightToken === ''
            || $leftRole === ''
            || $rightRole === ''
            || !\hash_equals($leftHost, $rightHost)
            || !\hash_equals($leftToken, $rightToken)
            || !\hash_equals($role, $leftRole)
            || !\hash_equals($role, $rightRole)
        ) {
            return false;
        }

        return (int)($left['port'] ?? 0) === (int)($right['port'] ?? 0)
            && (int)($left['pid'] ?? 0) === (int)($right['pid'] ?? 0)
            && (int)($left['port'] ?? 0) > 0
            && (int)($left['pid'] ?? 0) > 0;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private static function managedLifecycleNamesEqual(array $left, array $right): bool
    {
        foreach (['process_name', 'instance_name', 'service_instance_name'] as $field) {
            $leftValue = \trim((string)($left[$field] ?? ''));
            $rightValue = \trim((string)($right[$field] ?? ''));
            if ($leftValue === '' || $rightValue === '' || !\hash_equals($leftValue, $rightValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private static function lifecycleCoreIdentityEquals(
        string $role,
        array $left,
        array $right,
    ): bool {
        if (!self::lifecycleLiveOwnershipEquals($role, $left, $right)) {
            return false;
        }
        $leftStarted = \trim((string)($left['started_at'] ?? ''));
        $rightStarted = \trim((string)($right['started_at'] ?? ''));

        return $leftStarted !== ''
            && $rightStarted !== ''
            && \hash_equals($leftStarted, $rightStarted);
    }

    /** @param array<string,mixed> $record */
    private static function hasManagedLifecycleNames(array $record): bool
    {
        return \trim((string)($record['process_name'] ?? '')) !== ''
            || \trim((string)($record['instance_name'] ?? '')) !== ''
            || \trim((string)($record['service_instance_name'] ?? '')) !== '';
    }

    /**
     * Status and peek paths may combine observational metadata only when both
     * documents authenticate the same lifecycle tuple. A lower generation is
     * never allowed to fill fields that are absent from the higher authority.
     *
     * @param array<string,mixed> $runtime
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private static function mergeLifecycleStateAuthority(
        string $role,
        array $runtime,
        array $record,
    ): array {
        $runtimeBound = SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $runtime,
        );
        $recordBound = SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $record,
        );
        $authority = self::highestLifecycleAuthority($role, $record, $runtime);
        if ($authority === []) {
            return $runtime !== [] ? $runtime : $record;
        }
        if (!$runtimeBound || !$recordBound) {
            return $authority;
        }
        $sameGeneration = (int)($runtime['lifecycle_generation'] ?? 0)
            === (int)($record['lifecycle_generation'] ?? 0);
        $sameIdentity = \hash_equals(
            (string)($runtime['lifecycle_identity_digest'] ?? ''),
            (string)($record['lifecycle_identity_digest'] ?? ''),
        );
        if (!$sameGeneration || !$sameIdentity) {
            return $authority;
        }

        $selected = \array_merge($runtime, $record);
        foreach (self::LIFECYCLE_IDENTITY_FIELDS as $field) {
            if (\array_key_exists($field, $authority)) {
                $selected[$field] = $authority[$field];
            } else {
                unset($selected[$field]);
            }
        }
        return $selected;
    }

    /**
     * Delete only the exact generation selected before shutdown. A compliant
     * replacement cannot enter while the caller holds the role lock; CAS also
     * protects against an out-of-band writer that ignores that lock.
     *
     * @param array<string,mixed> $selected
     */
    private function removeSelectedLifecycleGeneration(
        string $role,
        array $selected,
        SharedStateServiceRegistry $registry,
    ): void {
        $generation = (int)($selected['lifecycle_generation'] ?? 0);
        $digest = (string)($selected['lifecycle_identity_digest'] ?? '');
        if ($generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
        ) {
            WlsLogger::warning_(
                '[SharedStateServiceManager] refusing unbound shared-state cleanup: role='
                . $role
            );
            return;
        }
        $registryRemoved = $registry->removeRecordIfGeneration(
            $role,
            $generation,
            $digest,
        );
        if (!$registryRemoved && $registry->getRecord($role) !== []) {
            WlsLogger::warning_(
                '[SharedStateServiceManager] retained replacement registry generation: role='
                . $role
            );
        }
        if (!$this->removeRuntimeFileIfGeneration($role, $generation, $digest)) {
            WlsLogger::warning_(
                '[SharedStateServiceManager] retained replacement runtime generation: role='
                . $role
            );
        }
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    protected function finalizeEnsuredRuntime(string $role, array $runtime, string $requesterInstanceName): array
    {
        $role = $this->normalizeRoleName($role);

        $registry = $this->createRegistry();
        $previousRuntime = $this->readRuntimeFile($role);
        $authority = self::highestLifecycleAuthority(
            $role,
            $registry->getRecord($role),
            $previousRuntime,
        );
        $candidateBound = SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $runtime,
        );
        $runtimeAuthorityBound = SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $previousRuntime,
        );
        if ($candidateBound || $runtimeAuthorityBound) {
            $runtime = SharedStateServiceRegistry::bindLifecycleGeneration(
                $role,
                $runtime,
                $authority,
            );
        }
        $publishedRecord = $this->publishRuntimeRecord($role, $runtime, $registry);
        if ($publishedRecord !== []) {
            $runtime = \array_merge($runtime, $publishedRecord);
        }
        if ($this->shouldTrackConsumer($requesterInstanceName)) {
            $registry->touchConsumer($role, $requesterInstanceName);
        }

        $runtime = $this->mergeRuntimeWithRegistryMetadata($role, $runtime, $registry);
        if ($runtime !== []) {
            $this->ensureSharedProcessLogVisible($runtime, $requesterInstanceName);
            $this->writeRuntimeFile($role, $runtime);
        }

        return $runtime;
    }

    /**
     * Shared sidecar runtime only merges metadata here; startup never waits on
     * per-role file locks.
     *
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    protected function finalizeSharedRuntime(string $role, array $runtime, string $requesterInstanceName): array
    {
        $role = $this->normalizeRoleName($role);
        if (!((bool) ($runtime['created_now'] ?? false))) {
            $runtime['reuse_existing'] = true;
        }
        $runtime['shared_service'] = true;

        return $this->finalizeEnsuredRuntime($role, $runtime, $requesterInstanceName);
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    protected function mergeRuntimeWithRegistryMetadata(
        string $role,
        array $runtime,
        ?SharedStateServiceRegistry $registry = null
    ): array {
        $registry ??= $this->createRegistry();
        $record = $registry->getRecord($role);
        $consumers = $registry->getConsumers($role);
        $runtime = self::mergeLifecycleStateAuthority($role, $runtime, $record);

        $runtime['registered'] = $record !== [];
        $runtime['consumer_count'] = \count($consumers);
        $runtime['shutdown_due_at'] = $record['shutdown_due_at'] ?? null;

        return $this->reconcileRuntimeWithLivePortOwner($role, $runtime, $registry);
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    protected function reconcileRuntimeWithLivePortOwner(
        string $role,
        array $runtime,
        SharedStateServiceRegistry $registry
    ): array {
        // Kept in the signature for extension compatibility. Reconciliation is
        // intentionally observation-only; authenticated ensure/start paths own
        // all registry and runtime-file writes.
        unset($registry);
        $authenticatedIdentityVerified = (bool)($runtime['_authenticated_identity_verified'] ?? false);
        unset($runtime['_authenticated_identity_verified']);
        if ($authenticatedIdentityVerified) {
            return $runtime;
        }

        $port = (int) ($runtime['port'] ?? 0);
        if ($port <= 0) {
            return $runtime;
        }

        // Reuse a recent strict identity observation only after a fresh,
        // project-token-authenticated protocol ping. This avoids the expensive
        // Windows CIM command-line scan on every server:start while retaining
        // the original fail-closed inspector for stale or conflicting state.
        $expectedRole = $this->normalizeRoleName($role);
        $observation = \is_array($runtime['live_observation'] ?? null)
            ? $runtime['live_observation']
            : [];
        if ($observation === []) {
            // Runtime files are written only by authenticated ensure/start paths.
            // Older files do not persist live_observation, so reconstruct the
            // previous strict identity from their immutable ownership fields and
            // require a fresh AUTH -> PING before accepting it.
            $observation = [
                'pid' => (int)($runtime['pid'] ?? 0),
                'role' => $expectedRole,
                'process_name' => (string)($runtime['process_name'] ?? ''),
                'instance_name' => (string)(
                    $runtime['instance_name']
                    ?? $runtime['service_instance_name']
                    ?? ''
                ),
                'validated_at' => (string)(
                    $runtime['healthy_at']
                    ?? $runtime['started_at']
                    ?? ''
                ),
            ];
        }
        $observedAt = \strtotime((string)($observation['validated_at'] ?? ''));
        $observationAge = $observedAt === false ? PHP_INT_MAX : \time() - $observedAt;
        $runtimePid = (int)($runtime['pid'] ?? 0);
        $observedPid = (int)($observation['pid'] ?? 0);
        $observedProcessName = \trim((string)($observation['process_name'] ?? ''));
        $runtimeProcessName = \trim((string)($runtime['process_name'] ?? ''));
        $tokenFileName = (string)(
            $runtime['token_file_name']
            ?? $this->defaultTokenForRole($expectedRole)
        );
        if ($observationAge >= 0
            && $observationAge <= 3600
            && $runtimePid > 0
            && $observedPid === $runtimePid
            && (string)($observation['role'] ?? '') === $expectedRole
            && $observedProcessName !== ''
            && ($runtimeProcessName === '' || $observedProcessName === $runtimeProcessName)
            && $this->isInspectionOwnedByCurrentSidecarScope($observation)
            && $this->probeSharedPortWithToken($port, $tokenFileName)) {
            $runtime['live_observation']['validated_at'] = \date('c');
            $runtime['live_observation']['validation'] = 'authenticated_protocol_pid';
            return $runtime;
        }

        Processer::clearPortCache($port);
        $occupant = Processer::inspectPortOccupantWithHistory($port);
        $ownerPid = (int) ($occupant['pid'] ?? 0);
        if ($ownerPid <= 0
            || !($occupant['pid_running'] ?? false)
            || !($occupant['is_weline'] ?? false)) {
            return $runtime;
        }

        $expectedRole = $this->normalizeRoleName($role);
        $inspection = (new SharedSidecarInspector())->inspect(
            $port,
            $expectedRole,
            (string)($runtime['token_file_name'] ?? $this->defaultTokenForRole($expectedRole))
        );
        $observedPid = (int)($inspection['pid'] ?? 0);
        $observedRole = (string)($inspection['role'] ?? '');
        $observedProcessName = \trim((string)($inspection['process_name'] ?? ''));
        $expectedProcessName = \trim((string)($runtime['process_name'] ?? ''));
        if (!(bool)($inspection['reusable'] ?? false)
            || $observedPid !== $ownerPid
            || $observedRole !== $expectedRole
            || !$this->isInspectionOwnedByCurrentSidecarScope($inspection)
            || ($expectedProcessName !== '' && $observedProcessName !== $expectedProcessName)) {
            return $runtime;
        }

        $runtimePid = (int)($runtime['pid'] ?? 0);
        $observedAt = \date('c');
        $runtime['live_observation'] = [
            'pid' => $observedPid,
            'role' => $observedRole,
            'process_name' => $observedProcessName,
            'instance_name' => (string)($inspection['instance_name'] ?? ''),
            'validated_at' => $observedAt,
        ];
        $registryPidStale = $runtimePid > 0 && $runtimePid !== $observedPid;
        $runtime['registry_pid_stale'] = $registryPidStale;
        if ($registryPidStale) {
            $runtime['registry_pid_stale_previous'] = $runtimePid;
            $runtime['registry_pid_observed_at'] = $observedAt;
        } else {
            unset(
                $runtime['registry_pid_stale_previous'],
                $runtime['registry_pid_corrected_at'],
                $runtime['registry_pid_observed_at']
            );
        }

        return $runtime;
    }

    /**
     * Authenticated ensure/probe paths may adopt a live PID, but only after the
     * same role, process-name, project-scope and liveness checks used by the
     * read-only observation path.
     *
     * @param array<string, mixed> $definition
     */
    private function resolveValidatedLivePortOwnerPid(array $definition): int
    {
        $port = (int)($definition['port'] ?? 0);
        $role = $this->normalizeRoleName((string)($definition['role'] ?? ''));
        if ($port <= 0) {
            return 0;
        }

        $inspection = $this->inspectRunningSharedService(
            $definition,
            (string)($definition['token_file_name'] ?? $this->defaultTokenForRole($role))
        );
        $pid = (int)($inspection['pid'] ?? 0);
        $expectedProcessName = \trim((string)($definition['process_name'] ?? ''));
        $observedProcessName = \trim((string)($inspection['process_name'] ?? ''));
        if (!(bool)($inspection['reusable'] ?? false)
            || $pid <= 0
            || !Processer::isRunningByPid($pid)
            || (string)($inspection['role'] ?? '') !== $role
            || !$this->isInspectionOwnedByCurrentSidecarScope($inspection)
            || ($expectedProcessName !== '' && $observedProcessName !== $expectedProcessName)) {
            return 0;
        }

        return $pid;
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private function publishRuntimeRecord(
        string $role,
        array $runtime,
        SharedStateServiceRegistry $registry
    ): array {
        $role = $this->normalizeRoleName($role);
        $pid = (int) ($runtime['pid'] ?? 0);
        $port = (int) ($runtime['port'] ?? 0);
        if ($pid <= 0 || $port <= 0) {
            return [];
        }

        // Do not carry lifecycle_* from runtime into the registry updater.
        // bindLifecycleGeneration() must rebind from the previous registry
        // identity; copying a peer binding turns an incomplete→complete
        // identity repair into a same-generation fork.
        $recordFields = [];
        foreach ([
            'role',
            'host',
            'port',
            'pid',
            'token_file_name',
            'started_at',
            'healthy_at',
            'process_name',
            'instance_name',
            'service_instance_name',
            'shared_service',
        ] as $key) {
            if (!\array_key_exists($key, $runtime)) {
                continue;
            }
            $value = $runtime[$key];
            if (($key === 'pid' || $key === 'port') && (int) $value <= 0) {
                continue;
            }
            if (\is_string($value) && \trim($value) === '') {
                continue;
            }
            $recordFields[$key] = $value;
        }

        if ($recordFields === []) {
            return [];
        }
        $recordFields['role'] = $role;
        $recordFields['shared_service'] = true;

        return $registry->updateRecord(
            $role,
            static function (array $record) use ($recordFields): array {
                $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
                $record = \array_merge($record, $recordFields);
                $record['consumers'] = $consumers;
                unset($record['shutdown_due_at'], $record['shutdown_requested_at']);

                return $record;
            }
        );
    }

    /**
     * Reused shared sidecars may have been started before the current consumer
     * instance existed, so make their existing process log visible from the
     * consumer's WLS log directory as well.
     *
     * @param array<string, mixed> $runtime
     */
    protected function ensureSharedProcessLogVisible(array $runtime, string $requesterInstanceName): void
    {
        $processName = \trim((string)($runtime['process_name'] ?? ''));
        $sharedLogInstanceName = \trim((string)(
            $runtime['service_instance_name'] ?? $runtime['instance_name'] ?? $requesterInstanceName
        ));
        if ($sharedLogInstanceName === '') {
            $sharedLogInstanceName = 'default';
        }
        if ($processName === '') {
            return;
        }

        try {
            WlsLogService::ensureProcessLogFile($processName, $sharedLogInstanceName);
        } catch (\Throwable) {
            // Reused legacy processes may not expose managed launch logs yet.
        }
    }

    protected function syncRuntimeRegistryMetadata(string $role, ?SharedStateServiceRegistry $registry = null): void
    {
        $role = $this->normalizeRoleName($role);
        $runtime = $this->readRuntimeFile($role);
        if ($runtime === []) {
            return;
        }

        $this->writeRuntimeFile(
            $role,
            $this->mergeRuntimeWithRegistryMetadata($role, $runtime, $registry)
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function shutdownIfUnusedNow(
        string $role,
        array $options = [],
        ?SharedStateServiceRegistry $registry = null
    ): bool {
        $role = $this->normalizeRoleName($role);
        $this->recoverRuntimeFileForMutation($role);
        $registry ??= $this->createRegistry();
        if ($registry->getConsumers($role) !== []) {
            return false;
        }

        $envConfig = \is_array($options['env_config'] ?? null) ? $options['env_config'] : $this->loadEnvConfig();
        $config = \is_array($options['config'] ?? null) ? $options['config'] : [];
        $definition = $this->buildRoleDefinition($role, 'system', $config, $envConfig);
        $runtime = \is_array($options['runtime'] ?? null) ? $options['runtime'] : $this->readRuntimeFile($role);
        $runtime = $this->selectLifecycleGeneration($role, $runtime, $registry);

        $stopped = false;
        $serviceObserved = $runtime !== [] || $this->isPortOccupied((int) $definition['port']);
        if ($serviceObserved) {
            $stopped = $this->forceStopReusedService($definition, $runtime);
        }

        if ($stopped || !$serviceObserved) {
            $this->removeSelectedLifecycleGeneration($role, $runtime, $registry);
        } else {
            WlsLogger::warning_(
                '[SharedStateServiceManager] retaining registry record after failed stop: '
                . 'role=' . $role . ', port=' . (int)$definition['port']
            );
        }

        return $stopped;
    }

    protected function shouldTrackConsumer(string $consumerCode): bool
    {
        $consumerCode = \trim($consumerCode);

        return $consumerCode !== '' && $consumerCode !== 'system';
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
        // 使用项目偏移量计算动态端口，避免硬编码
        $basePort = $this->normalizeRoleName($role) === ControlMessage::ROLE_MEMORY_SERVER ? 19971 : 19970;
        return $basePort + MasterProcess::getProjectPortOffset();
    }

    private function defaultTokenForRole(string $role): string
    {
        $fileName = $this->normalizeRoleName($role) === ControlMessage::ROLE_MEMORY_SERVER
            ? 'memory_server.token'
            : 'session_server.token';

        return SharedStateRuntimeScope::scopeDefaultFileName($fileName);
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
        bool $explicitConfigured,
        array $reservedPorts = []
    ): int {
        if ($preferredPort <= 0) {
            $preferredPort = $this->defaultPortForRole($role);
        }

        $reservedPortMap = [];
        foreach ($reservedPorts as $reservedPort) {
            $reservedPort = (int) $reservedPort;
            if ($reservedPort > 0 && $reservedPort <= 65535) {
                $reservedPortMap[$reservedPort] = true;
            }
        }

        if ($explicitConfigured) {
            if (isset($reservedPortMap[$preferredPort])) {
                throw new \RuntimeException(\sprintf(
                    'Configured shared %s port %d collides with another shared service in the same startup batch.',
                    $this->displayNameForRole($role),
                    $preferredPort
                ));
            }
            // 用户在 env.php 中钉死了端口：严格按配置返回，不做"可复用性"早校验、也不顺延。
            //
            // 早前版本会在这里调 `isPortCandidateReusable()` 做一次前置校验，占用不可复用时立即
            // 抛 "Configured %s port %d is not available"。移除原因：
            //   1. 与下游 `probeDefinition()` / `assessHealth()` 的 "Shared %s port %d is occupied
            //      by an unexpected process." 语义完全重合，却消息不一致，调用方难以统一处理；
            //   2. `isPortCandidateReusable()` 依赖 `Processer::isPortInUse()` 的静态缓存（Win 上
            //      10s TTL），使 `SharedStateServiceManagerTest` 在批量运行时可能被前序测试的
            //      netstat 探测污染，出现与单跑不同的行为漂移。
            // 放弃早抛，让主流程 `ensureSharedService()` 在 `probeDefinition()` 阶段统一判定、
            // 统一报错消息即可；生产端"端口不可用"的失败行为不变。
            return $preferredPort;
        }

        if (!isset($reservedPortMap[$preferredPort])
            && $this->isPortCandidateReusable($role, $preferredPort, $tokenFileName)
        ) {
            return $preferredPort;
        }

        $runtime = $this->readRuntimeFile($role);
        $runtimePort = (int) ($runtime['port'] ?? 0);
        $runtimeTokenFileName = \trim((string)($runtime['token_file_name'] ?? $tokenFileName));
        if ($runtimeTokenFileName === '') {
            $runtimeTokenFileName = $tokenFileName;
        }
        if ($runtimePort > 0
            && !isset($reservedPortMap[$runtimePort])
            && $this->isPortCandidateReusable($role, $runtimePort, $runtimeTokenFileName)
        ) {
            return $runtimePort;
        }

        $start = \max(1025, $preferredPort + 1);
        $limit = 512;
        $port = $start;
        for ($i = 0; $i < $limit; $i++, $port++) {
            if ($port > 65535) {
                break;
            }

            if (!isset($reservedPortMap[$port])
                && $this->isPortCandidateReusable($role, $port, $tokenFileName)
            ) {
                return $port;
            }
        }

        $secondEnd = \min($preferredPort, 65536);
        for ($port = 1025; $port < $secondEnd; $port++) {
            if (!isset($reservedPortMap[$port])
                && $this->isPortCandidateReusable($role, $port, $tokenFileName)
            ) {
                return $port;
            }
        }

        throw new \RuntimeException(\sprintf(
            'No allocatable port found for shared %s after scanning (preferred=%d).',
            $this->displayNameForRole($role),
            $preferredPort
        ));
    }

    private function isPortCandidateReusable(string $role, int $port, string $tokenFileName): bool
    {
        if ($port <= 0) {
            return false;
        }

        if ($this->probeSharedPortWithToken($port, $tokenFileName)) {
            return true;
        }

        if (!$this->probePortInUse($port)) {
            return true;
        }

        // An occupied port is reusable only after an authenticated protocol
        // PING. Process-table inspection remains diagnostic evidence; it must
        // never authorize adoption or termination of an unauthenticated
        // sidecar, even when its command line resembles this project.
        return false;
    }

    protected function probeSharedPortWithToken(int $port, string $tokenFileName): bool
    {
        return SharedStateProtocolProbe::pingWithTokenBasename('127.0.0.1', $port, $tokenFileName);
    }

    /**
     * 判定共享 sidecar 端口是否有真实 TCP 监听。
     *
     * 单独提为 protected 钩子有两个意图：
     * 1. 生产路径用短 TCP 连接确认监听，避免进程表/命令行探测把空端口误判为占用；
     * 2. 单元测试路径 —— 通过子类 override 屏蔽宿主环境/静态缓存带来的非确定性。
     *    `isPortCandidateReusable()` 是 `resolveSharedServicePort()` 在 `explicitConfigured`
     *    早抛分支前的唯一判据，若直接静态调用会让 `SharedStateServiceManagerTest` 的断言随
     *    宿主机端口状态漂移（已发生："Configured ..." vs "Shared ... occupied by unexpected"）。
     */
    protected function probePortInUse(int $port): bool
    {
        return $this->probeTcpPortInUse('127.0.0.1', $port);
    }

    /**
     * Only accept a sidecar bearing this runtime's complete ownership scope.
     *
     * @param array<string, mixed> $inspection
     */
    private function isInspectionOwnedByCurrentSidecarScope(array $inspection): bool
    {
        $scope = SharedStateRuntimeScope::sidecarIdentityToken();
        $instanceName = (string) ($inspection['instance_name'] ?? '');
        $processName = (string) ($inspection['process_name'] ?? '');
        if ($scope === '') {
            return false;
        }

        return $this->matchesSidecarScopeToken($instanceName, $scope)
            || $this->matchesSidecarScopeToken($processName, $scope);
    }

    private function matchesSidecarScopeToken(string $value, string $scope): bool
    {
        if ($value === '' || $scope === '') {
            return false;
        }

        return \preg_match('/(?:^|-)' . \preg_quote($scope, '/') . '(?:-|$)/', $value) === 1;
    }

    private function resolveSharedServiceTokenFileName(
        string $role,
        string $tokenFileName,
        int $port,
        bool $explicitConfigured
    ): string {
        $defaultTokenFileName = $this->defaultTokenForRole($role);
        $tokenFileName = \basename(\trim($tokenFileName));
        if ($tokenFileName === '' || $tokenFileName === '.' || $tokenFileName === '..') {
            $tokenFileName = $defaultTokenFileName;
        }

        if ($explicitConfigured) {
            return $tokenFileName;
        }

        // 非 env 显式配置时，统一按最终端口重建规范 token 名，
        // 避免 runtime 配置残留旧端口 token（例如 port=26422 却继续携带 session_server.26425.token）。
        return SharedStateRuntimeScope::defaultTokenFileNameForRole($role, $port);
    }
}
