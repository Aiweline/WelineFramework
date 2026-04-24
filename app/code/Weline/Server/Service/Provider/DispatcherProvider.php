<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Framework\App\Env;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceOrchestrator;

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
        // 优先使用运行态配置（由 Start.php 根据 CLI 参数和系统支持计算得出）
        // 这确保了 --direct、--no-dispatcher 等参数能正确生效
        return $context->isDispatcherEnabled();
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
            ($context->envConfig['wls'] ?? [])['host'] ?? '127.0.0.1',
            (string) $port,
            (string) $workerBasePort,
            (string) $workerCount,
            $context->instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
            '--memory-limit=' . $context->getDispatcherMemoryLimit(),
        ];

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
        return $context->mainPort;
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
