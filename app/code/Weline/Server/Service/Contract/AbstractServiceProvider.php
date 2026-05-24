<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

use Weline\Framework\System\IPC\ProcessKind;
use Weline\Framework\System\Process\Processer;
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
    public function requiresStartupReadyBarrier(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function supportsDrain(): bool
    {
        return $this->getReloadStrategy() === 'graceful';
    }

    /**
     * @inheritDoc
     */
    public function supportsShutdown(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function supportsReload(): bool
    {
        return $this->getReloadStrategy() !== 'none';
    }

    /**
     * @inheritDoc
     */
    public function isCriticalRole(): bool
    {
        return false;
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
        // 已有 IPC 连接的实例无需额外做 PID/端口探活。
        // 在 Windows 下进程查询代价高（PowerShell/tasklist），此处优先走 IPC 快路径。
        if ($instance->ipcClientId !== null) {
            return HealthCheckResult::healthy();
        }

        $trackingPid = $instance->getTrackingPid();
        if ($trackingPid <= 0) {
            return HealthCheckResult::unhealthy('No PID');
        }

        $processName = (string) ($instance->getMeta('process_name') ?? '');
        $launchId = $instance->launchId !== '' ? $instance->launchId : (string) ($instance->getMeta('launch_id') ?? '');
        $isRunning = ($trackingPid !== $instance->pid)
            ? Processer::isRunningByPid($trackingPid)
            : (($processName !== '' || $launchId !== '')
                ? Processer::isManagedProcessRunning(
                    $trackingPid,
                    $processName !== '' ? $processName : null,
                    $launchId,
                    $processName !== '' ? '--name=' . $processName : null
                )
                : Processer::isRunningByPid($trackingPid));

        if (!$isRunning) {
            return HealthCheckResult::unhealthy('Process not running');
        }

        // 无 IPC 连接时才用 fsockopen 做端口连通性检测
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

    protected function isSharedStateRuntimeManaged(ServiceContext $context, string $runtimeKey): bool
    {
        $runtime = $context->getConfig('wls.shared_state.runtime.' . $runtimeKey, []);
        if (!\is_array($runtime)) {
            return false;
        }

        if ((bool)($runtime['shared_service'] ?? false)
            || (bool)($runtime['reuse_existing'] ?? false)
            || (bool)($runtime['created_now'] ?? false)) {
            return true;
        }

        $instanceName = \trim((string)($runtime['instance_name'] ?? ''));
        if ($instanceName !== '' && (\str_starts_with($instanceName, 'shared-session-') || \str_starts_with($instanceName, 'shared-memory-'))) {
            return true;
        }

        $processName = \trim((string)($runtime['process_name'] ?? ''));
        return $processName !== '' && \str_contains($processName, '-shared-');
    }

    /**
     * 获取进程归属类型。
     *
     * 框架内置 Provider 无需重写（默认 'framework'）。
     * 模块自定义 Provider 须重写并返回 'module'，同时重写 getModuleCode()。
     */
    public function getProcessKind(): string
    {
        return \Weline\Framework\System\IPC\ProcessKind::FRAMEWORK;
    }

    /**
     * 获取模块代码（仅 module 类进程需要）。
     *
     * 格式建议：'VendorName_ModuleName'（与 register.php 中的模块名一致）。
     * 例如：'Weline_Payment'、'MyCompany_Chat'。
     */
    public function getModuleCode(): string
    {
        return '';
    }
}
