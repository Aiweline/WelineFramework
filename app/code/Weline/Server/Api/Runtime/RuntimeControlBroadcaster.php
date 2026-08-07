<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\Error\ErrorContext;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;

final class RuntimeControlBroadcaster implements RuntimeControlBroadcasterInterface
{
    private const DEFAULT_STATUS_TIMEOUT_SEC = 2.0;
    private const WEB_STATUS_TIMEOUT_SEC = 0.25;
    private const REQUEST_FIBER_STATUS_TIMEOUT_SEC = 0.05;

    public function __construct(
        private readonly BroadcastControlDispatchService $dispatch,
        private readonly IpcControlGateway $gateway,
    ) {
    }

    public function cacheClear(?string $instanceName = null): array
    {
        return $this->dispatch->cacheClear($instanceName, $this->statusTimeout());
    }

    public function cacheClearAndWait(?string $instanceName = null, float $timeout = 5.0): array
    {
        $timeout = \max(0.2, \min(30.0, $timeout));
        $result = $this->dispatch->cacheClear(
            $instanceName,
            \max($this->statusTimeout(), \min(1.0, $timeout)),
        );
        if (($result['success'] ?? false) !== true) {
            return $result + ['completed' => false];
        }

        $attempted = \array_values(\array_filter(
            (array)($result['attempted'] ?? []),
            static fn(mixed $name): bool => \is_string($name) && $name !== '',
        ));
        if ($attempted === []) {
            return $result + ['completed' => true];
        }

        $pending = [];
        $completionByInstance = [];
        foreach ($attempted as $targetInstance) {
            $commandResult = (array)(($result['results_by_instance'] ?? [])[$targetInstance] ?? []);
            $commandData = (array)($commandResult['data'] ?? []);
            $operationId = \trim((string)($commandData['operation_id'] ?? ''));
            if ($operationId === '') {
                $completionByInstance[$targetInstance] = [
                    'success' => false,
                    'message' => (string)__('缓存清理未返回可等待的操作编号。'),
                ];
                continue;
            }
            $pending[$targetInstance] = $operationId;
        }

        $deadline = self::monotonicSeconds() + $timeout;
        while ($pending !== [] && self::monotonicSeconds() < $deadline) {
            foreach ($pending as $targetInstance => $operationId) {
                $remaining = $deadline - self::monotonicSeconds();
                if ($remaining <= 0) {
                    break 2;
                }

                $status = $this->gateway->getStatus(
                    $targetInstance,
                    \max(0.05, \min(0.25, $remaining)),
                );
                if (($status['success'] ?? false) !== true) {
                    continue;
                }

                $controlOperation = (array)(($status['data']['control_operation'] ?? null) ?: []);
                $last = (array)($controlOperation['last'] ?? []);
                if ((string)($last['id'] ?? '') !== $operationId) {
                    continue;
                }

                $state = (string)($last['state'] ?? '');
                if (!\in_array($state, ['completed', 'failed', 'cancelled'], true)) {
                    continue;
                }

                $success = $state === 'completed' && ($last['success'] ?? false) === true;
                $completionByInstance[$targetInstance] = [
                    'success' => $success,
                    'state' => $state,
                    'message' => (string)($last['message'] ?? ''),
                    'data' => (array)($last['data'] ?? []),
                ];
                unset($pending[$targetInstance]);
            }

            if ($pending !== []) {
                SchedulerSystem::usleep(20_000);
            }
        }

        foreach ($pending as $targetInstance => $operationId) {
            $completionByInstance[$targetInstance] = [
                'success' => false,
                'state' => 'timed_out',
                'operation_id' => $operationId,
                'message' => (string)__('等待 WLS Worker 清理缓存超时。'),
            ];
        }

        $failed = \array_filter(
            $completionByInstance,
            static fn(array $completion): bool => ($completion['success'] ?? false) !== true,
        );
        $completed = $pending === [] && $failed === [];

        $result['success'] = $completed;
        $result['completed'] = $completed;
        $result['completion_by_instance'] = $completionByInstance;
        if (!$completed) {
            $result['message'] = (string)__('WLS Worker 未全部完成缓存清理。');
        }

        return $result;
    }

    public function maintenanceMode(): ?bool
    {
        if ($this->isCurrentProcessMaintenanceWorker()) {
            return true;
        }

        $instanceName = $this->currentRuntimeInstanceName();
        if ($instanceName === null) {
            return null;
        }

        try {
            $status = $this->gateway->getStatus($instanceName, $this->statusTimeout());
            if (!empty($status['success']) && \array_key_exists('maintenance_mode', $status['data'] ?? [])) {
                return (bool)$status['data']['maintenance_mode'];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public function setMaintenanceMode(bool $enabled): array
    {
        $instanceName = $this->currentRuntimeInstanceName();
        if ($instanceName !== null) {
            return $this->gateway->setMaintenanceMode($instanceName, $enabled, 6.0);
        }

        return $this->dispatch->setMaintenanceMode($enabled, null);
    }

    private function currentRuntimeInstanceName(): ?string
    {
        $instanceName = \trim((string)(\getenv('WLS_INSTANCE') ?: \getenv('WLS_INSTANCE_NAME') ?: ''));
        return $instanceName !== '' ? $instanceName : null;
    }

    private function statusTimeout(): float
    {
        if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null) {
            return self::REQUEST_FIBER_STATUS_TIMEOUT_SEC;
        }
        if (\PHP_SAPI !== 'cli') {
            return self::WEB_STATUS_TIMEOUT_SEC;
        }

        return self::DEFAULT_STATUS_TIMEOUT_SEC;
    }

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private function isCurrentProcessMaintenanceWorker(): bool
    {
        if ((bool)ErrorContext::get('is_maintenance', false)) {
            return true;
        }

        $processTag = ErrorContext::getProcessTag();
        return \is_string($processTag)
            && $processTag !== ''
            && \str_contains($processTag, 'Maintenance');
    }
}
