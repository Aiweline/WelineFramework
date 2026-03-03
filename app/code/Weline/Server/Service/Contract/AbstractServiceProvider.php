<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

use Weline\Server\Service\ServiceOrchestrator;

/**
 * 服务提供者抽象基类
 *
 * 提供默认实现，简化 Provider 开发
 */
abstract class AbstractServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritDoc
     */
    public function getResurrectionPriority(): int
    {
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function getReloadStrategy(): string
    {
        return 'graceful';
    }

    /**
     * @inheritDoc
     */
    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function healthCheck(ServiceInstance $instance): HealthCheckResult
    {
        if ($instance->pid <= 0) {
            return HealthCheckResult::unhealthy('No PID');
        }

        if (!\Weline\Framework\System\Process\Processer::isRunningByPid($instance->pid)) {
            return HealthCheckResult::unhealthy('Process not running');
        }

        if ($instance->port !== null) {
            $socket = @\fsockopen('127.0.0.1', $instance->port, $errno, $errstr, 1);
            if (!$socket) {
                return HealthCheckResult::unhealthy("Port {$instance->port} not responding: {$errstr}");
            }
            \fclose($socket);
        }

        return HealthCheckResult::healthy();
    }

    /**
     * @inheritDoc
     */
    public function handleMessage(array $message, ServiceInstance $instance, ServiceOrchestrator $orchestrator): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function onStarted(ServiceInstance $instance): void
    {
    }

    /**
     * @inheritDoc
     */
    public function onStopped(ServiceInstance $instance): void
    {
    }

    /**
     * 获取自动计算的 CPU 核心数
     */
    protected function getAutoCpuCount(): int
    {
        if (\function_exists('swoole_cpu_num')) {
            return \swoole_cpu_num();
        }

        if (IS_WIN) {
            $cores = (int) \trim((string) @\shell_exec('echo %NUMBER_OF_PROCESSORS%'));
            return $cores > 0 ? $cores : 4;
        }

        $cores = (int) \trim((string) @\shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null'));
        return $cores > 0 ? $cores : 4;
    }
}
