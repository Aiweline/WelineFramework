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
