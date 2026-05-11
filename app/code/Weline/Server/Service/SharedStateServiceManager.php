<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Provider\MemoryServerProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Shared\Connection\ConnectionPoolManager;

class SharedStateServiceManager
{
    private const DEFAULT_ENSURE_TIMEOUT_SEC = 30.0;
    private const DEFAULT_ENSURE_POLL_INTERVAL_MS = 100;

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
        // Session / Memory 并发启动：
        // 1. 先快速探测两个服务是否已存在且健康
        // 2. 对于需要启动的服务，使用 Fiber 并发执行锁内操作
        // 3. 等待阶段真正并发探活，不再串行等待

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
                $envConfig
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

        // 需要启动的服务使用 Fiber 并发执行（关键优化：让两个服务的锁操作同时进行）
        $fiberSession = null;
        $fiberMemory = null;

        if ($sessionNeedsStartup) {
            $fiberSession = new \Fiber(function () use ($sessionDefinition, $requesterInstanceName, $frontend, $forceRestart): array {
                return $this->withRoleLock((string) $sessionDefinition['role'], function () use (
                    $sessionDefinition,
                    $requesterInstanceName,
                    $frontend,
                    $forceRestart
                ): array {
                    return $this->prepareSharedServiceUnderLock(
                        $sessionDefinition,
                        $requesterInstanceName,
                        $frontend,
                        $forceRestart
                    );
                });
            });
        }

        if ($memoryNeedsStartup && $memoryDefinition !== null) {
            $fiberMemory = new \Fiber(function () use ($memoryDefinition, $requesterInstanceName, $frontend, $forceRestart): array {
                return $this->withRoleLock((string) $memoryDefinition['role'], function () use (
                    $memoryDefinition,
                    $requesterInstanceName,
                    $frontend,
                    $forceRestart
                ): array {
                    return $this->prepareSharedServiceUnderLock(
                        $memoryDefinition,
                        $requesterInstanceName,
                        $frontend,
                        $forceRestart
                    );
                });
            });
        }

        // 启动所有 Fiber 并拿回其最终 return value。
        //
        // 正确的 \Fiber API：
        //   - start()       返回的是 Fiber 内 `Fiber::suspend()` 的挂起值；若 fiber 一路 return
        //                   未 suspend，则返回 null 且 `isTerminated()=true`。
        //   - getReturn()   仅在 `isTerminated()=true` 后可用，才是真正的 return value。
        //   - resume()      把控制权再交回 fiber，从上一次 suspend 处继续。
        //
        // 早前此处写成 `$fiberSession->get()`（方法根本不存在，intelephense 长期报错；
        // 冷门分支触发时就是 fatal "Call to undefined method Fiber::get()"）。
        // 现修正为：start 后若仍未 terminated，resume 直到跑完，再从 getReturn() 取结果。
        if ($sessionNeedsStartup && $fiberSession !== null) {
            $fiberSession->start();
            while (!$fiberSession->isTerminated()) {
                $fiberSession->resume();
            }
            $sessionPrepare = $fiberSession->getReturn();
        } else {
            $sessionPrepare = $sessionProbe;
        }

        if ($memoryNeedsStartup && $fiberMemory !== null) {
            $fiberMemory->start();
            while (!$fiberMemory->isTerminated()) {
                $fiberMemory->resume();
            }
            $memoryPrepare = $fiberMemory->getReturn();
        } else {
            $memoryPrepare = $memoryProbe;
        }

        // 收集所有需要等待就绪的服务
        $pendingDefinitions = [];
        if (($sessionPrepare['status'] ?? '') === 'pending') {
            $pendingDefinitions[] = $sessionPrepare['definition'];
        }
        if ($memoryPrepare !== null && ($memoryPrepare['status'] ?? '') === 'pending') {
            $pendingDefinitions[] = $memoryPrepare['definition'];
        }

        // 并发等待所有 pending 服务就绪（关键优化：真正并发探活）
        $batchReady = [];
        if ($pendingDefinitions !== []) {
            $roleLabels = [];
            foreach ($pendingDefinitions as $def) {
                $roleLabels[] = (string) ($def['role'] ?? '?');
            }
            WlsLogger::info_(
                '[SharedStateServiceManager] 并发等待共享服务就绪 (角色: ' . \implode(', ', $roleLabels) . ')'
            );
            WlsLogger::flush_(true);
            $batchReady = $this->waitUntilSharedServicesReadyBatch($pendingDefinitions);
        }

        // 组装结果
        $runtime = [
            'session' => ($sessionPrepare['status'] ?? '') === 'ready'
                ? $sessionPrepare['runtime']
                : ($batchReady[(string) $sessionDefinition['role']] ?? []),
        ];

        if ($memoryDefinition !== null) {
            $memoryRole = ControlMessage::ROLE_MEMORY_SERVER;
            $runtime['memory'] = ($memoryPrepare !== null && ($memoryPrepare['status'] ?? '') === 'ready')
                ? $memoryPrepare['runtime']
                : ($batchReady[$memoryRole] ?? []);
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

        $runtime['session'] = $this->finalizeEnsuredRuntime(
            ControlMessage::ROLE_SESSION_SERVER,
            $runtime['session'],
            $requesterInstanceName
        );
        if ($memoryDefinition !== null) {
            $runtime['memory'] = $this->finalizeEnsuredRuntime(
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
        if ((bool) ($probe['healthy'] ?? false)) {
            $runtime = \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];
            $runtime['reuse_existing'] = true;
            $runtime['shared_service'] = true;
            return ['status' => 'ready', 'runtime' => $runtime];
        }
        return ['status' => 'pending', 'definition' => $definition];
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

        $runtime['session'] = $this->finalizeEnsuredRuntime(
            ControlMessage::ROLE_SESSION_SERVER,
            $runtime['session'],
            $requesterInstanceName
        );
        if ($memoryProbe !== null) {
            $runtime['memory'] = $this->finalizeEnsuredRuntime(
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
        $definition = $this->buildRoleDefinition($role, $requesterInstanceName, $config, $envConfig);

        $prepare = $this->withRoleLock((string) $definition['role'], function () use ($definition, $requesterInstanceName, $frontend, $forceRestart): array {
            return $this->prepareSharedServiceUnderLock($definition, $requesterInstanceName, $frontend, $forceRestart);
        });

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
     * 在角色锁内完成探测 / 强制停止 / 拉起子进程；就绪轮询在锁外执行（便于 Session 与 Memory 并行启动）。
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
    private function prepareSharedServiceUnderLock(
        array $definition,
        string $requesterInstanceName,
        bool $frontend,
        bool $forceRestart
    ): array {
        $probe = $this->probeDefinition($definition);
        if ((bool) ($probe['healthy'] ?? false)) {
            if ($forceRestart) {
                WlsLogger::info_(
                    '[SharedStateServiceManager] 强制重启共享服务 (角色: '
                    . (string) $definition['role']
                    . ", 请求者: {$requesterInstanceName}, 前台: "
                    . ($frontend ? '是' : '否') . ')'
                );
                $this->forceStopReusedService(
                    $definition,
                    \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : []
                );
                // 注意：不再在锁内等待，给其他服务并发启动的机会
            } else {
                $runtime = \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];
                $runtime['reuse_existing'] = true;
                $runtime['shared_service'] = true;
                $this->ensureSharedProcessLogVisible($runtime, $requesterInstanceName);

                $this->writeRuntimeFile((string) $definition['role'], $runtime);
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
            $this->forceStopReusedService($definition, \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : []);
            // 注意：不再在锁内等待，给其他服务并发启动的机会
        }
        WlsLogger::info_(
            '[SharedStateServiceManager] 启动共享服务 (角色: ' . (string) $definition['role']
            . ", 请求者实例名称: $requesterInstanceName, 前台模式: " . ($frontend ? '是' : '否') . ')'
        );
        $pid = $this->launchSharedServiceProcess($definition, $requesterInstanceName, $frontend);
        if ($pid <= 0) {
            throw new \RuntimeException($this->buildSharedSpawnFailureMessage($definition));
        }

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
                (string) $definition['host'],
                (int) $definition['port'],
                (string) ($definition['token_file_name'] ?? '')
            );
        }

        $timeoutSec = self::DEFAULT_ENSURE_TIMEOUT_SEC;
        foreach ($definitions as $definition) {
            $timeoutSec = \max(
                $timeoutSec,
                (float) ($definition['ensure_timeout_sec'] ?? self::DEFAULT_ENSURE_TIMEOUT_SEC)
            );
        }

        $deadline = \microtime(true) + $timeoutSec;
        $startedAt = \date('c');
        $pending = [];
        foreach ($definitions as $definition) {
            $roleKey = (string) $definition['role'];
            $pending[$roleKey] = $definition;
        }

        $pollIntervals = [10_000, 20_000, 50_000, 100_000];  // 优化：加快探活频率
        $pollIndex = 0;
        $done = [];

        while (\microtime(true) < $deadline && $pending !== []) {
            $sleepUs = $pollIntervals[\min($pollIndex, \count($pollIntervals) - 1)];
            // 关键修复：使用 yieldDelay 替代 usleep，让出控制权给主循环处理 IPC。
            // usleep 内部虽然也 suspend，但只让出 ~10μs 就立即恢复，不够主循环完成一轮 poll。
            // yieldDelay 确保当前 Fiber 真正挂起，tick() 返回后主循环可处理 IPC。
            SchedulerSystem::yieldDelay((int) \max(1, $sleepUs / 1000));
            $pollIndex++;

            // 每轮探活后主动 yield，让 Master 主循环有机会处理 IPC 消息
            SchedulerSystem::yield();

            foreach ($pending as $roleKey => $definition) {
                $probe = $this->probeDefinition($definition);
                if (!((bool) ($probe['healthy'] ?? false))) {
                    if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null) {
                        SchedulerSystem::yield();
                    }
                    continue;
                }

                $runtime = \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];
                $runtime['started_at'] = $startedAt;
                $runtime['healthy_at'] = \date('c');
                $runtime['created_now'] = true;
                $runtime['shared_service'] = true;

                $this->writeRuntimeFile($roleKey, $runtime);
                $done[$roleKey] = $runtime;
                unset($pending[$roleKey]);
                if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null) {
                    SchedulerSystem::yield();
                }
            }
        }

        if ($pending !== []) {
            $parts = [];
            foreach ($pending as $roleKey => $definition) {
                $parts[] = \sprintf(
                    '%s %s:%d',
                    $this->displayNameForRole($roleKey),
                    (string) $definition['host'],
                    (int) $definition['port']
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
        $definition = $this->buildRoleDefinition($role, $requesterInstanceName, $config, $envConfig);

        $prepare = $this->withRoleLock((string) $definition['role'], function () use ($definition, $requesterInstanceName, $frontend): array {
            $this->forceStopReusedService($definition, []);
            // 注意：不再在锁内等待，给其他服务并发启动的机会
            WlsLogger::info_(
                "[SharedStateServiceManager] 启动共享服务 (角色: " . (string) $definition['role']
                . ", 请求者实例名称: $requesterInstanceName, 前台模式: " . ($frontend ? '是' : '否') . ')'
            );
            $pid = $this->launchSharedServiceProcess($definition, $requesterInstanceName, $frontend);
            if ($pid <= 0) {
                throw new \RuntimeException($this->buildSharedSpawnFailureMessage($definition));
            }

            return ['status' => 'pending', 'definition' => $definition];
        });

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
        $runtime = $this->readRuntimeFile($role);
        $definition = $this->buildStatusProbeDefinition($role, $config, $envConfig, $runtime);
        $healthy = $this->probeRunningSharedService($definition, (string) $definition['token_file_name']);
        $runtime = \array_merge(
            $this->buildRuntimeMetadata(
                $definition,
                (int) ($runtime['pid'] ?? 0),
                \is_string($runtime['started_at'] ?? null) ? (string) $runtime['started_at'] : null,
                $healthy ? \date('c') : (\is_string($runtime['healthy_at'] ?? null) ? (string) $runtime['healthy_at'] : null)
            ),
            $runtime
        );

        return [
            'role' => (string) $definition['role'],
            'host' => (string) ($runtime['host'] ?? $definition['host']),
            'port' => (int) ($runtime['port'] ?? $definition['port']),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? $definition['token_file_name']),
            'pid' => (int) ($runtime['pid'] ?? 0),
            'healthy' => $healthy,
            'started_at' => $runtime['started_at'] ?? null,
            'healthy_at' => $runtime['healthy_at'] ?? null,
            'process_name' => (string) ($runtime['process_name'] ?? $definition['process_name']),
            'instance_name' => (string) ($runtime['instance_name'] ?? $definition['service_instance_name']),
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
        $definition = $this->buildRoleDefinition($role, 'system', $config, $envConfig);

        return $this->withRoleLock((string) $definition['role'], function () use ($definition): bool {
            $stopped = $this->forceStopReusedService($definition, []);
            $this->removeRuntimeFile((string) $definition['role']);
            $this->createRegistry()->removeRecord((string) $definition['role']);

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
            $results[$role] = (bool) $this->tryWithRoleLock($role, function () use ($role, $instanceName): bool {
                $registry = $this->createRegistry();
                $registry->touchConsumer($role, $instanceName);
                $this->syncRuntimeRegistryMetadata($role, $registry);

                return true;
            }, false);
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

        return $this->withRoleLock($role, function () use ($role, $options): bool {
            return $this->shutdownIfUnusedUnderLock($role, $options);
        });
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
                'consumer_count' => 0,
                'shutdown_due_at' => null,
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
        return $this->waitUntilSharedServicesReadyBatch([$definition])[(string) $definition['role']];
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
        $this->removeRuntimeFile($role);

        return $stopped;
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function forceStopSharedService(array $record): bool
    {
        $port = (int) ($record['port'] ?? 0);

        if ($port > 0) {
            return $this->sendSharedServiceServerShutdown($record);
        }

        return false;
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
                $params
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
                $tokenFileName
            );
        } catch (\Throwable) {
            return false;
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
        // createWindowsDetachedPhpArgv 的脚本固定 WindowStyle=Hidden，前台模式必须走 Processer::create(foreground:true) 才有可见控制台。
        // 与 Framework Processer 需同版本部署；旧版无 createWindowsDetachedPhpArgv 时回退 create()。
        if (\defined('IS_WIN') && IS_WIN && \method_exists(Processer::class, 'createWindowsDetachedPhpArgv') && !$frontend) {
            $argv = \array_merge(
                [PHP_BINARY, $command->getAbsoluteScript()],
                \array_map(static fn (mixed $a): string => (string) $a, $command->arguments)
            );
            if ($processName !== null && $processName !== '') {
                $argv[] = '--name=' . $processName;
            }
            if ($frontend) {
                $argv[] = '--win';
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
            $cmdLineForRegistry .= ' --win';
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
                ?? 'memory_server.token'
            ));
            if ($tokenFileName === '') {
                $tokenFileName = 'memory_server.token';
            }

            return [
                'role' => $role,
                'display_name' => 'Memory Service',
                'host' => (string) ($runtime['host'] ?? '127.0.0.1'),
                'port' => $port,
                'token_file_name' => \basename($tokenFileName),
                'process_name' => (string) (
                    $runtime['process_name']
                    ?? (MemoryServerProvider::PROCESS_NAME_PREFIX . '-' . MasterProcess::getProjectScopeToken() . '-shared-' . $port)
                ),
                'service_instance_name' => (string) (
                    $runtime['service_instance_name']
                    ?? $runtime['instance_name']
                    ?? ('shared-memory-' . MasterProcess::getProjectScopeToken() . '-' . $port)
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
            ?? 'session_server.token'
        ));
        if ($tokenFileName === '') {
            $tokenFileName = 'session_server.token';
        }

        return [
            'role' => $role,
            'display_name' => 'Session Server',
            'host' => (string) ($runtime['host'] ?? '127.0.0.1'),
            'port' => $port,
            'token_file_name' => \basename($tokenFileName),
            'process_name' => (string) (
                $runtime['process_name']
                ?? (SessionServerProvider::PROCESS_NAME_PREFIX . '-' . MasterProcess::getProjectScopeToken() . '-shared-' . $port)
            ),
            'service_instance_name' => (string) (
                $runtime['service_instance_name']
                ?? $runtime['instance_name']
                ?? ('shared-session-' . MasterProcess::getProjectScopeToken() . '-' . $port)
            ),
        ];
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
            // 仅 env/wls 中显式端口视为「用户钉死」；勿用 $config['memory_server_port']（Master/实例 JSON 总会带该键，会禁用启动阶段端口可用性扫描）
            $memoryPortExplicit = \array_key_exists('port', $memoryConfig);
            // 仅 env/wls 中显式 token_file_name 视为「用户钉死」；
            // 勿用 $config['memory_server_token_file_name']（实例 JSON / 运行时配置会残留旧端口 token，不能继续钉死）。
            $memoryTokenExplicit = \array_key_exists('token_file_name', $memoryConfig);

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
        // 仅 env 中显式端口视为「用户钉死」；勿用 $config['session_server_port']（server:start 写入的运行时端口非用户意图钉死）
        $sessionPortExplicit = \array_key_exists('port', $wlsServer)
            || \array_key_exists('port', $wlsSession)
            || \array_key_exists('server_port', $sessionConfig);
        // 仅 env/wls 中显式 token_file_name 视为「用户钉死」；
        // 勿用 $config['session_server_token_file_name']（实例 JSON / 运行时配置会残留旧端口 token，不能继续钉死）。
        $sessionTokenExplicit = \array_key_exists('token_file_name', $wlsServer)
            || \array_key_exists('token_file_name', $wlsSession);

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
            // 使用非阻塞锁 + 超时重试，避免多项目启动时无限等待
            $lockTimeout = 60.0; // 60 秒超时
            $deadline = \microtime(true) + $lockTimeout;
            $locked = false;

            while (\microtime(true) < $deadline) {
                if (\flock($handle, LOCK_EX | LOCK_NB)) {
                    $locked = true;
                    break;
                }
                // 等待 20ms 后重试（优化：加快锁竞争响应）
                SchedulerSystem::usleep(20_000);
            }

            if (!$locked) {
                throw new \RuntimeException(
                    "Unable to acquire shared-state lock for {$role} within {$lockTimeout}s. " .
                    "Another project may be starting the shared service. Please wait and retry."
                );
            }

            return $callback();
        } finally {
            \flock($handle, LOCK_UN);
            @\fclose($handle);
        }
    }

    /**
     * @param callable(): mixed $callback
     */
    protected function tryWithRoleLock(string $role, callable $callback, mixed $fallback = null): mixed
    {
        $lockPath = $this->getRuntimeFilePath($role) . '.ensure.lock';
        $dir = \dirname($lockPath);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $handle = @\fopen($lockPath, 'c+');
        if ($handle === false) {
            return $fallback;
        }

        $locked = false;
        try {
            if (!\flock($handle, LOCK_EX | LOCK_NB)) {
                return $fallback;
            }
            $locked = true;

            return $callback();
        } finally {
            if ($locked) {
                \flock($handle, LOCK_UN);
            }
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

    protected function createRegistry(): SharedStateServiceRegistry
    {
        return new SharedStateServiceRegistry();
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    protected function finalizeEnsuredRuntime(string $role, array $runtime, string $requesterInstanceName): array
    {
        $role = $this->normalizeRoleName($role);

        return $this->withRoleLock($role, function () use ($role, $runtime, $requesterInstanceName): array {
            $registry = $this->createRegistry();
            if ($this->shouldTrackConsumer($requesterInstanceName)) {
                $registry->touchConsumer($role, $requesterInstanceName);
            }

            $runtime = $this->mergeRuntimeWithRegistryMetadata($role, $runtime, $registry);
            if ($runtime !== []) {
                $this->ensureSharedProcessLogVisible($runtime, $requesterInstanceName);
                $this->writeRuntimeFile($role, $runtime);
            }

            return $runtime;
        });
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

        $runtime['registered'] = $record !== [];
        $runtime['consumer_count'] = \count($consumers);
        $runtime['shutdown_due_at'] = $record['shutdown_due_at'] ?? null;

        return $runtime;
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
        $processName = \trim((string) ($runtime['process_name'] ?? ''));
        $sharedLogInstanceName = \trim((string) ($runtime['service_instance_name'] ?? $runtime['instance_name'] ?? ''));
        if ($sharedLogInstanceName === '') {
            $sharedLogInstanceName = 'default';
        }
        if ($processName === '') {
            return;
        }

        try {
            $targetLog = WlsLogService::ensureProcessLogFile($processName, $sharedLogInstanceName);
            $sourceLog = Processer::getLogFile('--name=' . $processName);
        } catch (\Throwable) {
            return;
        }

        if (!\is_file($sourceLog) || \realpath($sourceLog) === \realpath($targetLog)) {
            return;
        }

        $sourceSize = (int) (@\filesize($sourceLog) ?: 0);
        $targetSize = (int) (@\filesize($targetLog) ?: 0);
        if ($sourceSize <= 0 || $targetSize > 0) {
            return;
        }

        $snapshot = @\file_get_contents($sourceLog);
        if ($snapshot === false || $snapshot === '') {
            return;
        }

        @\file_put_contents($targetLog, $snapshot, FILE_APPEND);
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
    protected function shutdownIfUnusedUnderLock(
        string $role,
        array $options = [],
        ?SharedStateServiceRegistry $registry = null
    ): bool {
        $role = $this->normalizeRoleName($role);
        $registry ??= $this->createRegistry();
        if ($registry->getConsumers($role) !== []) {
            return false;
        }

        $envConfig = \is_array($options['env_config'] ?? null) ? $options['env_config'] : $this->loadEnvConfig();
        $config = \is_array($options['config'] ?? null) ? $options['config'] : [];
        $definition = $this->buildRoleDefinition($role, 'system', $config, $envConfig);
        $runtime = \is_array($options['runtime'] ?? null) ? $options['runtime'] : $this->readRuntimeFile($role);

        $stopped = false;
        if ($runtime !== [] || $this->isPortOccupied((int) $definition['port'])) {
            $stopped = $this->forceStopReusedService($definition, $runtime);
        } else {
            $this->removeRuntimeFile($role);
        }

        $registry->removeRecord($role);

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

        $secondEnd = \min($preferredPort, 65536);
        for ($port = 1025; $port < $secondEnd; $port++) {
            if ($this->isPortCandidateReusable($role, $port, $tokenFileName)) {
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

        if (!$this->probePortInUse($port)) {
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
     * 判定端口是否被 OS 级占用（TCP LISTEN / socket connect 成功等）。
     *
     * 单独提为 protected 钩子有两个意图：
     * 1. 生产路径保持原语义 —— 委托给 `Processer::isPortInUse()`，其内部对 Windows/Linux 各有
     *    带缓存（10s TTL）和多级兜底的实现；
     * 2. 单元测试路径 —— 通过子类 override 屏蔽宿主环境/静态缓存带来的非确定性。
     *    `isPortCandidateReusable()` 是 `resolveSharedServicePort()` 在 `explicitConfigured`
     *    早抛分支前的唯一判据，若直接静态调用会让 `SharedStateServiceManagerTest` 的断言随
     *    宿主机端口状态漂移（已发生："Configured ..." vs "Shared ... occupied by unexpected"）。
     */
    protected function probePortInUse(int $port): bool
    {
        return Processer::isPortInUse($port);
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

        return $this->matchesProjectScopeToken($instanceName, $scope)
            || $this->matchesProjectScopeToken($processName, $scope);
    }

    private function matchesProjectScopeToken(string $value, string $scope): bool
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
        // 避免实例 JSON 残留旧端口 token（例如 port=26422 却继续携带 session_server.26425.token）。
        $tokenFileName = $defaultTokenFileName;
        $defaultPort = $this->defaultPortForRole($role);
        if ($port <= 0 || $port === $defaultPort) {
            return $tokenFileName;
        }

        $ext = \pathinfo($tokenFileName, \PATHINFO_EXTENSION);
        $name = \pathinfo($tokenFileName, \PATHINFO_FILENAME);
        if ($name === '') {
            $name = $this->toShortRole($role) . '_server';
        }
        if ($ext === '') {
            $ext = 'token';
        }

        return $name . '.' . $port . '.' . $ext;
    }
}
