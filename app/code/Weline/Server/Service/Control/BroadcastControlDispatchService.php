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
            fn(string $name): array => $this->ipcControlGateway->reloadAsync($name, $reloadType, $timeout)
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
            fn(string $name): array => $this->ipcControlGateway->cacheClear($name, $timeout)
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
            fn(string $name): array => $this->ipcControlGateway->setMaintenanceMode($name, $enabled, $timeout)
        );
    }

    public function reloadSslCert(array $domains = [], ?string $instanceName = null): array
    {
        return $this->dispatchToRunningInstances(
            $instanceName,
            'SSL 证书刷新',
            fn(string $name): array => $this->ipcControlGateway->reloadSslCert($name, $domains)
        );
    }

    /**
     * @param callable(string): array{success:bool,message:string,data?:array} $dispatcher
     * @return array{
     *     success:bool,
     *     attempted:array<int,string>,
     *     succeeded:array<int,string>,
     *     failed_by_instance:array<string,string>,
     *     message:string
     * }
     */
    private function dispatchToRunningInstances(?string $instanceName, string $actionLabel, callable $dispatcher): array
    {
        $attempted = [];
        $succeeded = [];
        $failedByInstance = [];
        $targetInstances = $this->resolveRunningInstances($instanceName, $failedByInstance);

        foreach ($targetInstances as $targetInstance) {
            $attempted[] = $targetInstance;
            try {
                $result = $dispatcher($targetInstance);
            } catch (\Throwable $throwable) {
                $failedByInstance[$targetInstance] = $throwable->getMessage();
                continue;
            }

            if (!empty($result['success'])) {
                $succeeded[] = $targetInstance;
                continue;
            }

            $failedByInstance[$targetInstance] = (string) ($result['message'] ?? 'unknown');
        }

        return [
            'success' => $attempted !== [] && $failedByInstance === [],
            'attempted' => $attempted,
            'succeeded' => $succeeded,
            'failed_by_instance' => $failedByInstance,
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
                $failedByInstance[$instanceName] = '实例未运行';
                return [];
            }

            if (!$this->serverInstanceManager->isInstanceIpcControllable($instanceName)) {
                $failedByInstance[$instanceName] = 'Master 未运行，无法通过 IPC 控制。';
                return [];
            }

            return [$instanceName];
        }

        $instances = [];
        foreach ($this->serverInstanceManager->listPersistedInstanceNames() as $name) {
            if ($this->serverInstanceManager->isInstanceIpcControllable($name)) {
                $instances[] = $name;
            }
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
