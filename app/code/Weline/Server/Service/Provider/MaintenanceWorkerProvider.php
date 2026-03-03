<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;

/**
 * 维护 Worker 服务提供者
 *
 * 维护 Worker 用于在滚动重启期间保持服务可用。
 * 默认不启动，仅在滚动重启时动态启用。
 *
 * 优先级：50（最后启动）
 */
class MaintenanceWorkerProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-maintenance';

    /** 是否被动态启用（由 Orchestrator 在滚动重启时设置） */
    private bool $dynamicEnabled = false;

    /** 动态实例数量 */
    private int $dynamicInstanceCount = 0;

    public function getRole(): string
    {
        return 'maintenance';
    }

    public function getDisplayName(): string
    {
        return 'Maintenance Worker';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        return $this->dynamicEnabled;
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        if ($this->dynamicInstanceCount > 0) {
            return $this->dynamicInstanceCount;
        }

        $workerCount = $context->getWorkerCount();
        if ($workerCount === 'auto') {
            $workerCount = $this->getAutoCpuCount();
        }

        return \max(1, (int) \ceil((int) $workerCount / 10));
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function getResurrectionPriority(): int
    {
        return 0;
    }

    public function getReloadStrategy(): string
    {
        return 'none';
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $scriptDir = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin';

        $script = $context->sslEnabled
            ? $scriptDir . DS . 'worker_ssl.php'
            : $scriptDir . DS . 'worker.php';

        $port = $this->getPort($instanceId, $context);
        $processName = self::PROCESS_NAME_PREFIX . '-' . $context->instanceName . '-' . $instanceId;

        $arguments = [
            '--port=' . $port,
            '--instance=' . $context->instanceName,
            '--maintenance',
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
        ];

        if ($context->sslEnabled && $context->sslCert && $context->sslKey) {
            $arguments[] = '--ssl-cert=' . $context->sslCert;
            $arguments[] = '--ssl-key=' . $context->sslKey;
        }

        if ($context->frontend) {
            $arguments[] = '--frontend';
        }

        return new ServiceCommand(
            script: $script,
            arguments: $arguments,
            processName: $processName,
        );
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        $basePort = $this->getMaintenanceBasePort($context);
        return $basePort + ($instanceId - 1);
    }

    /**
     * 获取维护 Worker 基础端口
     */
    private function getMaintenanceBasePort(ServiceContext $context): int
    {
        $mode = $context->mode;
        $mainPort = $context->mainPort;

        if ($mode === 'linux-direct' && $mainPort > 0) {
            return $mainPort + 100;
        }

        $workerCount = $context->getWorkerCount();
        if ($workerCount === 'auto') {
            $workerCount = $this->getAutoCpuCount();
        }

        $maxWorkerPort = $context->getWorkerBasePort() + (int) $workerCount - 1;
        return $maxWorkerPort + 100;
    }

    /**
     * 动态启用维护 Worker
     */
    public function enable(int $instanceCount = 0): void
    {
        $this->dynamicEnabled = true;
        $this->dynamicInstanceCount = $instanceCount;
    }

    /**
     * 动态禁用维护 Worker
     */
    public function disable(): void
    {
        $this->dynamicEnabled = false;
        $this->dynamicInstanceCount = 0;
    }
}
