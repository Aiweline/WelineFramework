<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Framework\App\Env;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\LocalDomainPolicy;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;

/**
 * Dispatcher 服务提供者
 *
 * 负责将外部流量分发到后端 Worker 进程。
 * 仅在 Windows 或启用 Dispatcher 模式时启用。
 *
 * 优先级：30（在 Worker 之后启动，确保 Worker 就绪后再接收流量）
 */
class DispatcherProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-dispatcher';

    public function getRole(): string
    {
        return 'dispatcher';
    }

    public function getDisplayName(): string
    {
        return 'Dispatcher';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        return $context->runtimeSelection->isDispatcher();
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 1;
    }

    public function getPriority(): int
    {
        return 30;
    }

    public function getResurrectionPriority(): int
    {
        return 3;
    }

    public function isCriticalRole(): bool
    {
        return true;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $scriptDir = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin';
        $script = $scriptDir . DS . 'dispatcher.php';

        $port = $this->getPort($instanceId, $context);
        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName);

        $workerCount = $context->getWorkerCount();
        if ($workerCount === 'auto') {
            $workerCount = $this->getAutoCpuCount();
        }
        $workerBasePort = $context->getWorkerBasePort();

        $arguments = [
            $this->resolveBindHost($context),
            (string) $port,
            (string) $workerBasePort,
            (string) $workerCount,
            $context->instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
            '--memory-limit=' . $context->getDispatcherMemoryLimit(),
        ];

        if ($context->windowMode) {
            $arguments[] = '--win';
        }
        if ($context->isProtocolEdgeEnabled()) {
            $arguments[] = '--protocol-edge-token-file=' . ProtocolEdgeRuntime::ensureTokenFile($context->instanceName);
        }

        return new ServiceCommand(
            script: $script,
            arguments: $arguments,
            processName: $processName,
        );
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        if ($context->isProtocolEdgeEnabled()) {
            return ProtocolEdgeRuntime::dispatcherPort($context);
        }
        return $context->mainPort;
    }

    private function resolveBindHost(ServiceContext $context): string
    {
        if ($context->isProtocolEdgeEnabled()) {
            return '127.0.0.1';
        }
        $wlsConfig = \is_array($context->envConfig['wls'] ?? null) ? $context->envConfig['wls'] : [];
        $dispatcherConfig = \is_array($wlsConfig['dispatcher'] ?? null) ? $wlsConfig['dispatcher'] : [];

        foreach ([
            $dispatcherConfig['bind_host'] ?? null,
            $wlsConfig['bind_host'] ?? null,
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $host = \trim((string)$context->host);
        if ($host === '' || $host === 'localhost' || LocalDomainPolicy::isManagedLocalDomain($host)) {
            return '127.0.0.1';
        }

        if (\filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        return '0.0.0.0';
    }

    public function handleMessage(array $message, ServiceInstance $instance, ServiceOrchestrator $orchestrator): bool
    {
        $type = $message['type'] ?? '';

        switch ($type) {
            case 'worker_health':
                $instance->setMeta('worker_health', $message['data'] ?? []);
                $orchestrator->getRegistry()->updateInstance($instance);
                return true;
        }

        return false;
    }
}
