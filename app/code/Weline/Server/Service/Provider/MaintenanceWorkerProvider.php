<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;

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

        return 1;
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
        return false;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $scriptDir = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin';
        $edgeAdapter = (new \Weline\Server\Service\Edge\EdgeAdapterResolver())->resolve($context->envConfig);
        $pureWlsSsl = $edgeAdapter->name()
            === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
            && $context->sslEnabled;
        $script = $pureWlsSsl
            ? $scriptDir . DS . 'worker_ssl.php'
            : $scriptDir . DS . 'worker.php';

        $port = $this->getPort($instanceId, $context);
        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName, $instanceId);

        // Maintenance is never a public listener, including Direct topology.
        $host = '127.0.0.1';

        $arguments = [
            $host,
            (string) $port,
            (string) $instanceId,
            $context->instanceName,
            '--maintenance',
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
            '--memory-limit=' . $context->getWorkerMemoryLimit(),
        ];

        if ($pureWlsSsl) {
            if ($context->sslCert === '' || $context->sslKey === '') {
                throw new \RuntimeException('Pure WLS maintenance HTTPS requires certificate and private-key paths.');
            }
            $arguments[] = '--ssl-cert=' . $context->sslCert;
            $arguments[] = '--ssl-key=' . $context->sslKey;
            $arguments = \array_merge(
                $arguments,
                WorkerRuntimeArgumentBuilder::protocolPolicy($context, false),
            );
        }

        $arguments[] = '--wls-runtime-topology='
            . $context->runtimeSelection->effectiveTopology->value;
        $arguments[] = '--wls-listener-mode=single';
        $arguments[] = '--public-origin=' . WorkerRuntimeArgumentBuilder::publicOrigin($context);

        $gatewayBackend = \strtolower(\trim((string)$context->getConfig(
            'wls.edge.mode',
            $context->getConfig('edge_mode', ''),
        ))) === GatewayStartupDecision::MODE_GATEWAY;
        if ($gatewayBackend) {
            $arguments[] = '--protocol-edge-token-file='
                . ProtocolEdgeRuntime::ensureTokenFile($context->instanceName);
            $projectUuid = \strtolower(\trim((string)$context->getConfig(
                'wls.gateway.project_uuid',
                '',
            )));
            $instanceGeneration = (int)$context->getConfig(
                'wls.gateway.instance_generation',
                0,
            );
            $instanceLaunchId = \strtolower(\trim((string)$context->getConfig(
                'wls.gateway.launch_id',
                '',
            )));
            if (\preg_match('/^[a-f0-9-]{36}$/D', $projectUuid) !== 1
                || $instanceGeneration < 1
                || \preg_match('/^[a-f0-9]{32}$/D', $instanceLaunchId) !== 1
            ) {
                throw new \RuntimeException(
                    'Gateway maintenance backend requires project UUID, instance launch ID, and monotonic instance generation.'
                );
            }
            $arguments[] = '--gateway-project-uuid=' . $projectUuid;
            $arguments[] = '--gateway-instance-generation=' . $instanceGeneration;
            $arguments[] = '--gateway-instance-launch-id=' . $instanceLaunchId;
        }

        $arguments = \array_merge($arguments, WorkerRuntimeArgumentBuilder::sharedState($context));

        $arguments[] = '--wls-loop-driver=' . $context->runtimeSelection->eventLoopDriver;

        if ($pureWlsSsl) {
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
        $mainPort = $context->mainPort;

        if ($context->isDirect() && $mainPort > 0) {
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

}
