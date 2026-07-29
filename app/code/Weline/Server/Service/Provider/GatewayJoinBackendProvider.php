<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;

/**
 * Supplemental loopback H1 application backend used while a pure-WLS project
 * joins the host gateway. It never owns a public listener or TLS material.
 */
final class GatewayJoinBackendProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-gateway-join-backend';

    public function __construct(
        private readonly int $port = 0,
        private readonly bool $inheritedListener = false,
        private readonly bool $runtimeEnabled = false,
        private readonly int $instanceCount = 1,
    ) {
    }

    public function getRole(): string
    {
        return ControlMessage::ROLE_GATEWAY_BACKEND;
    }

    public function getDisplayName(): string
    {
        return 'WLS Gateway Join Backend';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        return $this->runtimeEnabled;
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return $this->runtimeEnabled ? \max(1, $this->instanceCount) : 0;
    }

    public function getPriority(): int
    {
        return 42;
    }

    public function getResurrectionPriority(): int
    {
        return 4;
    }

    public function getReloadStrategy(): string
    {
        return 'graceful';
    }

    public function requiresStartupReadyBarrier(): bool
    {
        return false;
    }

    public function isCriticalRole(): bool
    {
        return false;
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        return $this->port > 0 ? $this->port : null;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        if ($this->port < 20000 || $this->port > 29999) {
            throw new \RuntimeException('Gateway join backend port must be inside 20000-29999.');
        }
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
        if (\preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $projectUuid,
        ) !== 1
            || $instanceGeneration < 1
            || \preg_match('/^[a-f0-9]{32}$/D', $instanceLaunchId) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway join backend requires project UUID, instance launch ID, and monotonic instance generation.'
            );
        }
        $listenerMode = $this->inheritedListener ? 'shared_fd' : 'single';
        if ($this->inheritedListener && \PHP_OS_FAMILY === 'Windows') {
            throw new \RuntimeException(
                'Windows cannot inherit the POSIX gateway join listener.'
            );
        }
        $arguments = [
            '127.0.0.1',
            (string)$this->port,
            (string)$instanceId,
            $context->instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
            '--memory-limit=' . $context->getWorkerMemoryLimit(),
            '--worker-count=' . \max(1, $this->instanceCount),
            '--wls-runtime-topology=direct',
            '--wls-listener-mode=' . $listenerMode,
            '--public-origin=' . WorkerRuntimeArgumentBuilder::publicOrigin($context),
            '--protocol-edge-token-file='
                . ProtocolEdgeRuntime::ensureTokenFile($context->instanceName),
            '--gateway-project-uuid=' . $projectUuid,
            '--gateway-instance-generation=' . $instanceGeneration,
            '--gateway-instance-launch-id=' . $instanceLaunchId,
            '--gateway-join-backend',
            '--wls-loop-driver=' . $context->runtimeSelection->eventLoopDriver,
        ];
        if ($this->inheritedListener) {
            $arguments[] = '--listen-fd=3';
        }
        $arguments = \array_merge(
            $arguments,
            WorkerRuntimeArgumentBuilder::sharedState($context),
        );
        return new ServiceCommand(
            script: BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server'
                . DS . 'bin' . DS . 'worker.php',
            arguments: $arguments,
            processName: MasterProcess::buildScopedProcessName(
                self::PROCESS_NAME_PREFIX . '-' . $instanceId,
                $context->instanceName,
            ),
        );
    }
}
