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
     * @param string[] $domains
     * @return array{
     *     success:bool,
     *     attempted:array<int,string>,
     *     succeeded:array<int,string>,
     *     failed_by_instance:array<string,string>,
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
     * P0-3 修复：支持批量并发派发。
     *
     * - `$dispatcher` 仍用于单实例场景（目标数 == 1）以保持旧行为和测试兼容性。
     * - `$batchDispatcher` 存在且目标数 >= 2 时，走并发路径；由 IpcControlGateway::*Many 系列实现
     *   一次 open-fwrite + stream_select 多路复用等待 ACK，总耗时从 N × timeout 降到 ≈ timeout。
     *
     * @param callable(string): array{success:bool,message:string,data?:array} $dispatcher
     * @param null|callable(array<int,string>): array<string, array{success:bool,message:string,data?:array}> $batchDispatcher
     * @return array{
     *     success:bool,
     *     attempted:array<int,string>,
     *     succeeded:array<int,string>,
     *     failed_by_instance:array<string,string>,
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
        $resultsByInstance = [];
        $targetInstances = $this->resolveRunningInstances($instanceName, $failedByInstance);

        $useBatch = $batchDispatcher !== null && \count($targetInstances) >= 2;

        if ($useBatch) {
            $batchResults = null;
            try {
                /** @var array<string, array{success:bool,message:string,data?:array}> $batchResults */
                $batchResults = $batchDispatcher($targetInstances);
            } catch (\Throwable $throwable) {
                // 批量派发器整体崩溃：把全部目标登记为 attempted + failed，保持对等可观测性。
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
            'results_by_instance' => $resultsByInstance,
            'message' => $this->buildMessage($actionLabel, $attempted, $succeeded, $failedByInstance, $instanceName),
        ];
    }

    /**
     * @param array<string,string> $failedByInstance
     * @return string[]
     */
    private function resolveRunningInstances(?string $instanceName, array &$failedByInstance): array
    {
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

        // P1-8 修复：广播场景下不再静默跳过「存在但无 IPC」的实例，
        // 将其显式登记到 failedByInstance，避免运维误以为「什么都没发生」。
        // （相比之下历史行为是仅对「指定单实例」的场景报错，广播全部时悄悄丢弃。）
        $instances = [];
        foreach ($this->serverInstanceManager->listPersistedInstanceNames() as $name) {
            if ($this->serverInstanceManager->isInstanceIpcControllable($name)) {
                $instances[] = $name;
                continue;
            }

            if (!$this->serverInstanceManager->hasInstance($name)) {
                // 已过时的登记信息（实例已彻底不存在），无需告警
                continue;
            }

            $failedByInstance[$name] = (string) __('Master 未运行，跳过该实例（请检查 server:start 或 Master 复活状态）。');
        }

        return $instances;
    }

    /**
     * @param string[] $attempted
     * @param string[] $succeeded
     * @param array<string,string> $failedByInstance
     */
    private function buildMessage(
        string $actionLabel,
        array $attempted,
        array $succeeded,
        array $failedByInstance,
        ?string $instanceName
    ): string {
        if ($attempted === []) {
            if ($instanceName !== null && $instanceName !== '' && isset($failedByInstance[$instanceName])) {
                return (string) __('WLS 实例 %{1} 未运行：%{2}', [$instanceName, $failedByInstance[$instanceName]]);
            }

            return (string) __('未发现运行中的 WLS 实例，已跳过 %{1}', [$actionLabel]);
        }

        if ($failedByInstance === []) {
            if ($instanceName !== null && $instanceName !== '' && \count($succeeded) === 1) {
                return (string) __('已向 WLS 实例 %{1} 发送 %{2}', [$succeeded[0], $actionLabel]);
            }

            return (string) __('已向 %{1} 个运行中的 WLS 实例发送 %{2}', [\count($succeeded), $actionLabel]);
        }

        $failedParts = [];
        foreach ($failedByInstance as $failedInstance => $reason) {
            $failedParts[] = $failedInstance . ': ' . $reason;
        }
        $failedSummary = \implode('；', $failedParts);

        if ($succeeded === []) {
            return (string) __('WLS 在运行，但 %{1} 派发失败：%{2}', [$actionLabel, $failedSummary]);
        }

        return (string) __('已向 %{1}/%{2} 个运行中的 WLS 实例发送 %{3}，失败：%{4}', [
            \count($succeeded),
            \count($attempted),
            $actionLabel,
            $failedSummary,
        ]);
    }
}
