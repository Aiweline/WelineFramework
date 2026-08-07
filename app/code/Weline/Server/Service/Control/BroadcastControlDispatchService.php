<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\ServerInstanceManager;

class BroadcastControlDispatchService
{
    public function __construct(
        private readonly IpcControlGateway $ipcControlGateway,
        private readonly ServerInstanceManager $serverInstanceManager
    ) {
    }

    /**
     * @return array{
     *     success:bool,
     *     attempted:array<int,string>,
     *     succeeded:array<int,string>,
     *     failed_by_instance:array<string,string>,
     *     skipped_by_instance:array<string,string>,
     *     results_by_instance:array<string,array{success:bool,message:string,data?:array}>,
     *     message:string
     * }
     */
    public function reloadAsync(?string $instanceName, string $reloadType, float $timeout = 5.0): array
    {
        $label = match ($reloadType) {
            ControlMessage::RELOAD_TYPE_FORCE => '强制重载',
            default => '代码重载',
        };

        return $this->dispatchToRunningInstances(
            $instanceName,
            $label,
            fn(string $name): array => $this->ipcControlGateway->reloadAsync($name, $reloadType, $timeout),
            fn(array $names): array => $this->ipcControlGateway->reloadAsyncMany($names, $reloadType, $timeout)
        );
    }

    /**
     * @return array{
     *     success:bool,
     *     attempted:array<int,string>,
     *     succeeded:array<int,string>,
     *     failed_by_instance:array<string,string>,
     *     skipped_by_instance:array<string,string>,
     *     results_by_instance:array<string,array{success:bool,message:string,data?:array}>,
     *     message:string
     * }
     */
    public function cacheClear(?string $instanceName = null, float $timeout = 5.0): array
    {
        return $this->dispatchToRunningInstances(
            $instanceName,
            '缓存清理',
            fn(string $name): array => $this->ipcControlGateway->cacheClear($name, $timeout),
            fn(array $names): array => $this->ipcControlGateway->cacheClearMany($names, $timeout)
        );
    }

    /**
     * Accept namespace invalidation on each live Master without waiting for
     * Worker ACKs. Each instance gets at most 100ms and the whole call 500ms.
     *
     * @return array<string,mixed>
     */
    public function cacheNamespaceInvalidateV1(
        int $authorityClock,
        array $changes,
        ?string $instanceName = null,
        string $requestId = '',
    ): array {
        $startedAt = ControlMessage::monotonicSeconds();
        $deadline = $startedAt + 0.5;
        $failedByInstance = [];
        $skippedByInstance = [];
        $targets = $this->resolveRunningInstances($instanceName, $failedByInstance, $skippedByInstance);
        $attempted = [];
        $succeeded = [];
        $resultsByInstance = [];
        $operationsByInstance = [];

        foreach ($targets as $target) {
            $remaining = $deadline - ControlMessage::monotonicSeconds();
            if ($remaining <= 0.0) {
                $failedByInstance[$target] = 'namespace_accept_total_budget_exceeded';
                continue;
            }
            $attempted[] = $target;
            try {
                $result = $this->ipcControlGateway->cacheNamespaceInvalidateV1(
                    $target,
                    $authorityClock,
                    $changes,
                    $requestId,
                    \min(0.1, $remaining),
                );
            } catch (\Throwable) {
                $failedByInstance[$target] = 'dispatch_exception';
                continue;
            }
            $resultsByInstance[$target] = $result;
            $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
            $operationId = \trim((string)($data['operation_id'] ?? ''));
            if (($result['success'] ?? false) === true
                && ($data['accepted'] ?? false) === true
                && $operationId !== ''
            ) {
                $succeeded[] = $target;
                $operationsByInstance[$target] = $operationId;
                continue;
            }
            $failedByInstance[$target] = (string)($data['error_code'] ?? $result['message'] ?? 'accept_failed');
        }

        if ($targets === [] && ($instanceName === null || \trim($instanceName) === '')) {
            $skippedByInstance['*'] = $skippedByInstance['*'] ?? 'runtime_not_present';
        }
        $explicitTarget = $instanceName !== null && \trim($instanceName) !== '';
        $success = $explicitTarget
            ? ($attempted !== [] && $failedByInstance === [])
            : $failedByInstance === [];

        return [
            'success' => $success,
            'completed' => false,
            'attempted' => $attempted,
            'succeeded' => $succeeded,
            'operations_by_instance' => $operationsByInstance,
            'failed_by_instance' => $failedByInstance,
            'skipped_by_instance' => $skippedByInstance,
            'results_by_instance' => $resultsByInstance,
            'elapsed_ms' => \round((ControlMessage::monotonicSeconds() - $startedAt) * 1000, 3),
            'message' => $success
                ? (string)__('可用 WLS 实例已接收缓存命名空间失效操作。')
                : (string)__('部分目标 WLS 实例未接收缓存命名空间失效操作。'),
        ];
    }

    /**
     * @return array{
     *     success:bool,
     *     attempted:array<int,string>,
     *     succeeded:array<int,string>,
     *     failed_by_instance:array<string,string>,
     *     skipped_by_instance:array<string,string>,
     *     results_by_instance:array<string,array{success:bool,message:string,data?:array}>,
     *     message:string
     * }
     */
    public function setMaintenanceMode(bool $enabled, ?string $instanceName = null, float $timeout = 6.0): array
    {
        $label = $enabled ? (string) __('启用维护模式') : (string) __('禁用维护模式');

        return $this->dispatchToRunningInstances(
            $instanceName,
            $label,
            fn(string $name): array => $this->ipcControlGateway->setMaintenanceMode($name, $enabled, $timeout),
            fn(array $names): array => $this->ipcControlGateway->setMaintenanceModeMany($names, $enabled, $timeout, false)
        );
    }

    /**
     * @return array{
     *     success:bool,
     *     attempted:array<int,string>,
     *     succeeded:array<int,string>,
     *     failed_by_instance:array<string,string>,
     *     skipped_by_instance:array<string,string>,
     *     results_by_instance:array<string,array{success:bool,message:string,data?:array}>,
     *     message:string
     * }
     */
    public function setMaintenanceRoutingOnly(bool $enabled, ?string $instanceName = null, float $timeout = 6.0): array
    {
        $label = $enabled
            ? (string) __('启用 Dispatcher 维护分流')
            : (string) __('禁用 Dispatcher 维护分流');

        return $this->dispatchToRunningInstances(
            $instanceName,
            $label,
            fn(string $name): array => $this->ipcControlGateway->setMaintenanceMode($name, $enabled, $timeout, true),
            fn(array $names): array => $this->ipcControlGateway->setMaintenanceModeMany($names, $enabled, $timeout, true)
        );
    }

    /**
     * @param string[] $domains
     * @return array{
     *     success:bool,
     *     attempted:array<int,string>,
     *     succeeded:array<int,string>,
     *     failed_by_instance:array<string,string>,
     *     skipped_by_instance:array<string,string>,
     *     results_by_instance:array<string,array{success:bool,message:string,data?:array}>,
     *     message:string
     * }
     */
    public function reloadSslCert(array $domains = [], ?string $instanceName = null): array
    {
        return $this->dispatchToRunningInstances(
            $instanceName,
            'SSL 证书刷新',
            fn(string $name): array => $this->ipcControlGateway->reloadSslCert($name, $domains),
            fn(array $names): array => $this->ipcControlGateway->reloadSslCertMany($names, $domains)
        );
    }

    /**
     * Dispatch an exact certificate transaction to one explicitly fenced
     * instance. The immutable operation/manifest identity is also projected
     * at the top level so callers cannot confuse the generic dispatch wrapper
     * with the Master's terminal Worker aggregate.
     *
     * @param string[] $domains
     * @return array<string,mixed>
     */
    public function reloadSslCertAndWait(
        array $domains,
        string $instanceName,
        string $operationId,
        int $expectedManifestGeneration,
        string $expectedManifestDigest,
        int $expectedTlsRouteCount,
        float $timeout = 8.0,
    ): array {
        $instanceName = \trim($instanceName);
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            $failure = [[
                'code' => 'invalid_instance_name',
                'error' => 'Exact SSL reload requires one explicit valid instance name.',
            ]];
            $failureKey = $instanceName !== '' ? $instanceName : '*';
            return [
                'success' => false,
                'attempted' => [],
                'succeeded' => [],
                'failed_by_instance' => [
                    $failureKey => 'Exact SSL reload instance name is invalid.',
                ],
                'skipped_by_instance' => [],
                'results_by_instance' => [],
                'message' => 'Exact SSL reload instance name is invalid.',
                'operation_id' => \strtolower(\trim($operationId)),
                'expected_manifest_generation' => $expectedManifestGeneration,
                'expected_manifest_digest' => \strtolower(\trim(
                    $expectedManifestDigest,
                )),
                'expected_tls_route_count' => $expectedTlsRouteCount,
                'expected_serving_mode' => $expectedTlsRouteCount === 0
                    ? 'neutral'
                    : 'routes',
                'expected_retired_context_count' => 0,
                'expected_retired_context_digest' => \hash('sha256', '[]'),
                'eligible_workers' => [],
                'acked_workers' => [],
                'failed_workers' => $failure,
                'control_operation_id' => '',
            ];
        }
        $dispatch = $this->dispatchToRunningInstances(
            $instanceName,
            'SSL 证书精确刷新',
            fn(string $name): array => $this->ipcControlGateway->reloadSslCertAndWait(
                $domains,
                $name,
                $operationId,
                $expectedManifestGeneration,
                $expectedManifestDigest,
                $expectedTlsRouteCount,
                $timeout,
            ),
        );
        $instanceResult = \is_array(
            $dispatch['results_by_instance'][$instanceName] ?? null,
        ) ? $dispatch['results_by_instance'][$instanceName] : [];
        $terminal = \is_array($instanceResult['data'] ?? null)
            ? $instanceResult['data']
            : [];
        $eligible = \is_array($terminal['eligible_workers'] ?? null)
            ? \array_values($terminal['eligible_workers'])
            : [];
        $acked = \is_array($terminal['acked_workers'] ?? null)
            ? \array_values($terminal['acked_workers'])
            : [];
        $failed = \is_array($terminal['failed_workers'] ?? null)
            ? \array_values($terminal['failed_workers'])
            : [];
        $expectedRetiredContextCount = $terminal['expected_retired_context_count']
            ?? null;
        $expectedRetiredContextDigest = \strtolower(\trim((string)(
            $terminal['expected_retired_context_digest'] ?? ''
        )));
        $exact = \hash_equals(
                \strtolower(\trim($operationId)),
                (string)($terminal['operation_id'] ?? ''),
            )
            && (int)($terminal['expected_manifest_generation'] ?? 0)
                === $expectedManifestGeneration
            && \hash_equals(
                \strtolower(\trim($expectedManifestDigest)),
                (string)($terminal['expected_manifest_digest'] ?? ''),
            )
            && (int)($terminal['expected_tls_route_count'] ?? -1)
                === $expectedTlsRouteCount
            && \hash_equals(
                $expectedTlsRouteCount === 0 ? 'neutral' : 'routes',
                (string)($terminal['expected_serving_mode'] ?? ''),
            )
            && \is_int($expectedRetiredContextCount)
            && $expectedRetiredContextCount >= 0
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $expectedRetiredContextDigest,
            ) === 1;
        $workerSetMatches = $this->sslReloadWorkerSetMatches(
            $eligible,
            $acked,
            $expectedManifestGeneration,
            \strtolower(\trim($expectedManifestDigest)),
            $expectedTlsRouteCount,
            (int)$expectedRetiredContextCount,
            $expectedRetiredContextDigest,
        );
        $success = ($dispatch['success'] ?? false) === true
            && ($instanceResult['success'] ?? false) === true
            && ($terminal['success'] ?? false) === true
            && $exact
            && $eligible !== []
            && $failed === []
            && $workerSetMatches;
        if (!$success && !isset($dispatch['failed_by_instance'][$instanceName])) {
            $dispatch['failed_by_instance'][$instanceName] = $exact
                ? 'TLS Worker exact acknowledgement aggregate was incomplete.'
                : 'Master returned a different SSL reload transaction fence.';
            $dispatch['succeeded'] = \array_values(\array_filter(
                (array)($dispatch['succeeded'] ?? []),
                static fn(mixed $name): bool => (string)$name !== $instanceName,
            ));
        }
        if (!$success && $failed === []) {
            $failed[] = [
                'code' => $exact
                    ? 'terminal_worker_aggregate_incomplete'
                    : 'terminal_transaction_fence_mismatch',
                'error' => (string)(
                    $dispatch['failed_by_instance'][$instanceName]
                    ?? $dispatch['message']
                    ?? 'Exact SSL reload failed without a terminal Worker aggregate.'
                ),
            ];
        }

        return \array_merge($dispatch, [
            'success' => $success,
            'operation_id' => (string)($terminal['operation_id'] ?? $operationId),
            'expected_manifest_generation' => (int)(
                $terminal['expected_manifest_generation']
                ?? $expectedManifestGeneration
            ),
            'expected_manifest_digest' => (string)(
                $terminal['expected_manifest_digest']
                ?? $expectedManifestDigest
            ),
            'expected_tls_route_count' => (int)(
                $terminal['expected_tls_route_count']
                ?? $expectedTlsRouteCount
            ),
            'expected_serving_mode' => (string)(
                $terminal['expected_serving_mode']
                ?? ($expectedTlsRouteCount === 0 ? 'neutral' : 'routes')
            ),
            'expected_retired_context_count' => \is_int($expectedRetiredContextCount)
                ? $expectedRetiredContextCount
                : 0,
            'expected_retired_context_digest' => $expectedRetiredContextDigest !== ''
                ? $expectedRetiredContextDigest
                : \hash('sha256', '[]'),
            'eligible_workers' => $eligible,
            'acked_workers' => $acked,
            'failed_workers' => $failed,
            'control_operation_id' => (string)(
                $terminal['control_operation_id'] ?? ''
            ),
        ]);
    }

    /**
     * @param list<mixed> $eligible
     * @param list<mixed> $acked
     */
    private function sslReloadWorkerSetMatches(
        array $eligible,
        array $acked,
        int $generation,
        string $digest,
        int $expectedTlsRouteCount,
        int $expectedRetiredContextCount,
        string $expectedRetiredContextDigest,
    ): bool {
        if (\count($eligible) !== \count($acked)) {
            return false;
        }
        $expected = [];
        foreach ($eligible as $identity) {
            if (!\is_array($identity)) {
                return false;
            }
            $clientId = (int)($identity['client_id'] ?? 0);
            if ($clientId < 1 || isset($expected[$clientId])) {
                return false;
            }
            $expected[$clientId] = $identity;
        }
        foreach ($acked as $receipt) {
            if (!\is_array($receipt)) {
                return false;
            }
            $clientId = (int)($receipt['client_id'] ?? 0);
            $identity = $expected[$clientId] ?? null;
            if (!\is_array($identity)
                || (int)($receipt['worker_id'] ?? 0)
                    !== (int)($identity['instance_id'] ?? 0)
                || !\hash_equals(
                    (string)($identity['role'] ?? ''),
                    (string)($receipt['role'] ?? ''),
                )
                || !\hash_equals(
                    (string)($identity['slot_id'] ?? ''),
                    (string)($receipt['slot_id'] ?? ''),
                )
                || !\hash_equals(
                    (string)($identity['lease_id'] ?? ''),
                    (string)($receipt['lease_id'] ?? ''),
                )
                || (int)($identity['generation'] ?? 0)
                    !== (int)($receipt['generation'] ?? -1)
                || (int)($identity['pid'] ?? 0)
                    !== (int)($receipt['pid'] ?? -1)
                || (int)($receipt['applied_manifest_generation'] ?? 0)
                    !== $generation
                || !\hash_equals(
                    $digest,
                    (string)($receipt['applied_manifest_digest'] ?? ''),
                )
                || (int)($receipt['applied_tls_route_count'] ?? -1)
                    !== $expectedTlsRouteCount
                || !\hash_equals(
                    $expectedTlsRouteCount === 0 ? 'neutral' : 'routes',
                    (string)($receipt['serving_mode'] ?? ''),
                )
                || !\hash_equals(
                    $expectedTlsRouteCount === 0 ? 'disabled' : 'active',
                    (string)($receipt['tls_context_state'] ?? ''),
                )
                || !\is_int($receipt['retired_context_count'] ?? null)
                || (int)($receipt['retired_context_count'] ?? -1)
                    !== $expectedRetiredContextCount
                || !\hash_equals(
                    $expectedRetiredContextDigest,
                    (string)($receipt['retired_context_digest'] ?? ''),
                )
            ) {
                return false;
            }
            unset($expected[$clientId]);
        }

        return $expected === [];
    }

    /**
     * @param callable(string): array{success:bool,message:string,data?:array} $dispatcher
     * @param null|callable(array<int,string>): array<string,array{success:bool,message:string,data?:array}> $batchDispatcher
     * @return array{
     *     success:bool,
     *     attempted:array<int,string>,
     *     succeeded:array<int,string>,
     *     failed_by_instance:array<string,string>,
     *     skipped_by_instance:array<string,string>,
     *     results_by_instance:array<string,array{success:bool,message:string,data?:array}>,
     *     message:string
     * }
     */
    private function dispatchToRunningInstances(
        ?string $instanceName,
        string $actionLabel,
        callable $dispatcher,
        ?callable $batchDispatcher = null
    ): array {
        $attempted = [];
        $succeeded = [];
        $failedByInstance = [];
        $skippedByInstance = [];
        $resultsByInstance = [];
        $targetInstances = $this->resolveRunningInstances($instanceName, $failedByInstance, $skippedByInstance);

        $useBatch = $batchDispatcher !== null && \count($targetInstances) >= 2;

        if ($useBatch) {
            $batchResults = null;
            try {
                /** @var array<string,array{success:bool,message:string,data?:array}> $batchResults */
                $batchResults = $batchDispatcher($targetInstances);
            } catch (\Throwable $throwable) {
                foreach ($targetInstances as $name) {
                    $attempted[] = $name;
                    $failedByInstance[$name] = $throwable->getMessage();
                }
            }

            if ($batchResults !== null) {
                foreach ($targetInstances as $targetInstance) {
                    $attempted[] = $targetInstance;
                    if (!\array_key_exists($targetInstance, $batchResults)) {
                        $failedByInstance[$targetInstance] = (string) __('批量派发遗漏该实例');
                        continue;
                    }

                    $result = $batchResults[$targetInstance];
                    $resultsByInstance[$targetInstance] = $result;

                    if (!empty($result['success'])) {
                        $succeeded[] = $targetInstance;
                        continue;
                    }

                    $failedByInstance[$targetInstance] = (string) ($result['message'] ?? 'unknown');
                }
            }
        } else {
            foreach ($targetInstances as $targetInstance) {
                $attempted[] = $targetInstance;
                try {
                    $result = $dispatcher($targetInstance);
                } catch (\Throwable $throwable) {
                    $failedByInstance[$targetInstance] = $throwable->getMessage();
                    continue;
                }

                $resultsByInstance[$targetInstance] = $result;

                if (!empty($result['success'])) {
                    $succeeded[] = $targetInstance;
                    continue;
                }

                $failedByInstance[$targetInstance] = (string) ($result['message'] ?? 'unknown');
            }
        }

        return [
            'success' => $attempted !== [] && $failedByInstance === [],
            'attempted' => $attempted,
            'succeeded' => $succeeded,
            'failed_by_instance' => $failedByInstance,
            'skipped_by_instance' => $skippedByInstance,
            'results_by_instance' => $resultsByInstance,
            'message' => $this->buildMessage(
                $actionLabel,
                $attempted,
                $succeeded,
                $failedByInstance,
                $skippedByInstance,
                $instanceName
            ),
        ];
    }

    /**
     * @param array<string,string> $failedByInstance
     * @param array<string,string> $skippedByInstance
     * @return string[]
     */
    private function resolveRunningInstances(
        ?string $instanceName,
        array &$failedByInstance,
        array &$skippedByInstance
    ): array {
        $instanceName = $instanceName !== null ? \trim($instanceName) : null;
        if ($instanceName !== null && $instanceName !== '') {
            if (!$this->serverInstanceManager->hasInstance($instanceName)) {
                $failedByInstance[$instanceName] = (string) __('实例未运行');
                return [];
            }

            if (!$this->serverInstanceManager->isInstanceIpcControllable($instanceName)) {
                $failedByInstance[$instanceName] = (string) __('Master 未运行，无法通过 IPC 控制。');
                return [];
            }

            return [$instanceName];
        }

        $instances = [];
        foreach ($this->serverInstanceManager->listPersistedInstanceNames() as $name) {
            $instance = $this->serverInstanceManager->getRawInstanceData($name);
            if ($instance === null) {
                if ($this->serverInstanceManager->isInstanceIpcControllable($name)) {
                    $instances[] = $name;
                    continue;
                }
                if ($this->serverInstanceManager->hasInstance($name)) {
                    $skippedByInstance[$name] = (string) __('Master 未运行，跳过该实例（请检查 server:start 或 Master 复活状态）。');
                }
                continue;
            }
            if ($this->isStoppedInstanceRecord($instance)) {
                continue;
            }
            if ($this->mayAcceptControlCommand($instance)) {
                $instances[] = $name;
                continue;
            }

            $skippedByInstance[$name] = (string) __('Master 未运行，跳过该实例（请检查 server:start 或 Master 复活状态）。');
        }

        return $instances;
    }

    /** @param array<string, mixed> $instance */
    private function mayAcceptControlCommand(array $instance): bool
    {
        return !$this->isStoppedInstanceRecord($instance)
            && (int)($instance['control_port'] ?? 0) > 0;
    }

    /** @param array<string, mixed> $instance */
    private function isStoppedInstanceRecord(array $instance): bool
    {
        $lifecycleState = \strtolower(\trim((string)($instance['lifecycle_state'] ?? '')));
        $startupPhase = \strtolower(\trim((string)($instance['startup_phase'] ?? '')));
        $terminalStates = [
            'stopped',
            'stale_cleanup',
            'master_exited',
            'master_exited_children_retained',
            'startup_failed',
            'failed',
        ];

        return \in_array($lifecycleState, $terminalStates, true)
            || \in_array($startupPhase, $terminalStates, true);
    }

    /**
     * @param string[] $attempted
     * @param string[] $succeeded
     * @param array<string,string> $failedByInstance
     * @param array<string,string> $skippedByInstance
     */
    private function buildMessage(
        string $actionLabel,
        array $attempted,
        array $succeeded,
        array $failedByInstance,
        array $skippedByInstance,
        ?string $instanceName
    ): string {
        if ($attempted === []) {
            if ($instanceName !== null && $instanceName !== '' && isset($failedByInstance[$instanceName])) {
                return (string) __('WLS 实例 %{1} 未运行：%{2}', [$instanceName, $failedByInstance[$instanceName]]);
            }

            if ($skippedByInstance !== []) {
                return (string) __('未发现可接收 %{1} 的运行中 WLS 实例，已跳过：%{2}', [
                    $actionLabel,
                    $this->formatInstanceReasonSummary($skippedByInstance),
                ]);
            }

            return (string) __('未发现运行中的 WLS 实例，已跳过 %{1}', [$actionLabel]);
        }

        if ($failedByInstance === [] && $skippedByInstance === []) {
            if ($instanceName !== null && $instanceName !== '' && \count($succeeded) === 1) {
                return (string) __('已向 WLS 实例 %{1} 发送 %{2}', [$succeeded[0], $actionLabel]);
            }

            return (string) __('已向 %{1} 个运行中的 WLS 实例发送 %{2}', [\count($succeeded), $actionLabel]);
        }

        if ($failedByInstance === []) {
            return (string) __('已向 %{1} 个可控 WLS 实例发送 %{2}，跳过：%{3}', [
                \count($succeeded),
                $actionLabel,
                $this->formatInstanceReasonSummary($skippedByInstance),
            ]);
        }

        $failedSummary = $this->formatInstanceReasonSummary($failedByInstance);
        $skippedSummary = $this->formatInstanceReasonSummary($skippedByInstance);

        if ($succeeded === []) {
            if ($skippedByInstance !== []) {
                return (string) __('WLS 在运行，但 %{1} 派发失败：%{2}；跳过：%{3}', [
                    $actionLabel,
                    $failedSummary,
                    $skippedSummary,
                ]);
            }

            return (string) __('WLS 在运行，但 %{1} 派发失败：%{2}', [$actionLabel, $failedSummary]);
        }

        if ($skippedByInstance !== []) {
            return (string) __('已向 %{1}/%{2} 个可控 WLS 实例发送 %{3}，失败：%{4}；跳过：%{5}', [
                \count($succeeded),
                \count($attempted),
                $actionLabel,
                $failedSummary,
                $skippedSummary,
            ]);
        }

        return (string) __('已向 %{1}/%{2} 个可控 WLS 实例发送 %{3}，失败：%{4}', [
            \count($succeeded),
            \count($attempted),
            $actionLabel,
            $failedSummary,
        ]);
    }

    /**
     * @param array<string,string> $reasons
     */
    private function formatInstanceReasonSummary(array $reasons): string
    {
        $parts = [];
        foreach ($reasons as $instance => $reason) {
            $parts[] = $instance . ': ' . $reason;
        }

        return \implode('，', $parts);
    }
}
