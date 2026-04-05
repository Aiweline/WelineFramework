<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\SharedStateRuntimeResolver;
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

    public function requiresStartupReadyBarrier(): bool
    {
        return true;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $scriptDir = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin';

        $script = $context->sslEnabled
            ? $scriptDir . DS . 'worker_ssl.php'
            : $scriptDir . DS . 'worker.php';

        $port = $this->getPort($instanceId, $context);
        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName, $instanceId);

        $mode = $context->mode;
        $host = ($mode === 'linux-direct')
            ? ($context->host ?: '127.0.0.1')
            : '127.0.0.1';

        $arguments = [
            $host,
            (string) $port,
            (string) $instanceId,
            $context->instanceName,
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

        if ($mode === 'linux-direct') {
            $arguments[] = '--reuseport';
        }

        $arguments = \array_merge($arguments, $this->buildSharedStateArguments($context));

        $loopDriver = (string) $context->getConfig('wls.loop.driver', 'auto');
        $loopDriver = \strtolower(\trim($loopDriver));
        if ($loopDriver === '') {
            $loopDriver = 'auto';
        }
        $arguments[] = '--wls-loop-driver=' . $loopDriver;

        if ($context->sslEnabled && $mode !== 'linux-direct') {
            $arguments[] = '--defer-ssl';
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
        $this->dynamicInstanceCount = $instanceCount > 0 ? $instanceCount : 0;
    }

    /**
     * 动态禁用维护 Worker
     */
    public function disable(): void
    {
        $this->dynamicEnabled = false;
        $this->dynamicInstanceCount = 0;
    }

    /**
     * @return string[]
     */
    private function buildSharedStateArguments(ServiceContext $context): array
    {
        $runtime = (new SharedStateRuntimeResolver())->resolve($context->envConfig, $context->envConfig, $context->instanceName);
        $session = \is_array($runtime['session'] ?? null) ? $runtime['session'] : [];
        $memory = \is_array($runtime['memory'] ?? null) ? $runtime['memory'] : [];

        $sessionHost = \trim((string) ($session['host'] ?? '127.0.0.1'));
        if ($sessionHost === '') {
            $sessionHost = '127.0.0.1';
        }
        $defaultSessionPort = 19970 + MasterProcess::getProjectPortOffset();
        $sessionPort = (int) ($session['port'] ?? $defaultSessionPort);
        $sessionTokenFileName = \trim((string) ($session['token_file_name'] ?? 'session_server.token'));

        $memoryHost = \trim((string) ($memory['host'] ?? '127.0.0.1'));
        if ($memoryHost === '') {
            $memoryHost = '127.0.0.1';
        }
        // 默认端口 19971 + 项目偏移量，确保多项目不冲突
        $defaultMemoryPort = 19971 + MasterProcess::getProjectPortOffset();
        $memoryPort = (int) ($memory['port'] ?? $defaultMemoryPort);
        $memoryTokenFileName = \trim((string) ($memory['token_file_name'] ?? 'memory_server.token'));

        return [
            '--session-host=' . $sessionHost,
            '--session-port=' . ($sessionPort > 0 ? $sessionPort : $defaultSessionPort),
            '--session-token-file-name=' . ($sessionTokenFileName !== '' ? $sessionTokenFileName : 'session_server.token'),
            '--memory-host=' . $memoryHost,
            '--memory-port=' . ($memoryPort > 0 ? $memoryPort : $defaultMemoryPort),
            '--memory-token-file-name=' . ($memoryTokenFileName !== '' ? $memoryTokenFileName : 'memory_server.token'),
        ];
    }
}
