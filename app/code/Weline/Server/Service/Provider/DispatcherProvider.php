<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceOrchestrator;

/**
 * Dispatcher 服务提供者
 *
 * 负责把项目托管 Nginx 的 loopback HTTP/1.1 回源流量分发到 Worker。
 * 仅在显式选择 Dispatcher 兼容/诊断拓扑时启用；所有平台 auto 都是 Direct。
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

        return new ServiceCommand(
            script: $script,
            arguments: $arguments,
            processName: $processName,
        );
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        return $context->mainPort;
    }

    private function resolveBindHost(ServiceContext $context): string
    {
        return '127.0.0.1';
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
