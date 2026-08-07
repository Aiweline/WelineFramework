<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;

final class RuntimeNamespaceInvalidationPublisher implements RuntimeNamespaceInvalidationPublisherInterface
{
    public function __construct(
        private readonly BroadcastControlDispatchService $dispatch,
        private readonly IpcControlGateway $gateway,
    ) {
    }

    public function publish(
        int $authorityClock,
        array $changes,
        ?string $instanceName = null,
        string $requestId = ''
    ): array {
        if (!$this->publisherEnabled()) {
            return [
                'success' => true,
                'completed' => false,
                'attempted' => [],
                'operations_by_instance' => [],
                'failed_by_instance' => [],
                'skipped_by_instance' => ['*' => 'publisher_disabled'],
                'message' => (string)__('缓存命名空间失效发布器已禁用。'),
            ];
        }

        try {
            $result = $this->dispatch->cacheNamespaceInvalidateV1(
                $authorityClock,
                $changes,
                $instanceName,
                $requestId,
            );
        } catch (\Throwable) {
            $result = [
                'success' => false,
                'completed' => false,
                'attempted' => [],
                'operations_by_instance' => [],
                'failed_by_instance' => ['*' => 'runtime_accept_failed'],
                'skipped_by_instance' => [],
                'message' => (string)__('缓存命名空间失效发布失败。'),
            ];
        }
        if (($result['success'] ?? false) !== true && \function_exists('w_log_error')) {
            \w_log_error(
                'cache_namespace_publish_failed',
                [
                    'instance' => $instanceName ?? '',
                    'error_code' => 'runtime_accept_failed',
                ],
                'wls',
            );
        }

        return $result;
    }

    public function publishAndWait(
        int $authorityClock,
        array $changes,
        ?string $instanceName = null,
        string $requestId = '',
        float $timeout = 5.0
    ): array {
        $timeout = \max(0.2, \min(30.0, $timeout));
        $result = $this->publish($authorityClock, $changes, $instanceName, $requestId);
        if (($result['success'] ?? false) !== true) {
            return $result + ['completed' => false, 'completion_by_instance' => []];
        }
        $pending = [];
        foreach ((array)($result['operations_by_instance'] ?? []) as $instance => $operationId) {
            if (\is_string($instance) && \is_string($operationId) && $operationId !== '') {
                $pending[$instance] = $operationId;
            }
        }
        if ($pending === []) {
            $result['completed'] = true;
            $result['completion_by_instance'] = [];
            return $result;
        }

        $completion = [];
        $deadline = self::monotonicSeconds() + $timeout;
        while ($pending !== [] && self::monotonicSeconds() < $deadline) {
            foreach ($pending as $instance => $operationId) {
                $remaining = $deadline - self::monotonicSeconds();
                if ($remaining <= 0.0) {
                    break 2;
                }
                $status = $this->gateway->cacheNamespaceInvalidationStatusV1(
                    $instance,
                    $operationId,
                    \min(0.1, $remaining),
                );
                if (($status['success'] ?? false) !== true) {
                    continue;
                }
                $operation = \is_array($status['data']['operation'] ?? null)
                    ? $status['data']['operation']
                    : [];
                $state = (string)($operation['state'] ?? '');
                if (!\in_array($state, ['completed', 'failed'], true)) {
                    continue;
                }
                $completion[$instance] = $operation;
                unset($pending[$instance]);
            }
            if ($pending !== []) {
                SchedulerSystem::usleep(20_000);
            }
        }

        foreach ($pending as $instance => $operationId) {
            $completion[$instance] = [
                'id' => $operationId,
                'state' => 'timed_out',
                'success' => false,
                'error_code' => 'ack_timeout',
            ];
        }
        $failed = \array_filter(
            $completion,
            static fn(array $entry): bool => ($entry['success'] ?? false) !== true,
        );
        $completed = $pending === [] && $failed === [];
        $result['success'] = $completed;
        $result['completed'] = $completed;
        $result['completion_by_instance'] = $completion;
        if (!$completed) {
            $result['message'] = (string)__('缓存命名空间失效未在所有目标 Worker 上完成。');
        }

        return $result;
    }

    private function publisherEnabled(): bool
    {
        $moduleEnv = Env::module_env('Weline_Framework');
        $default = \is_array($moduleEnv)
            ? ($moduleEnv['cache']['namespace']['publisher_enabled'] ?? false)
            : false;
        $value = Env::get('cache.namespace.publisher_enabled', $default);
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value)) {
            return $value !== 0;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
