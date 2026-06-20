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
 * Gateway 服务提供者
 *
 * 负责启动 WLS Gateway 反向代理进程。
 * 仅在配置启用时启动。
 *
 * 优先级：40（在 Dispatcher 之后启动）
 */
class GatewayProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-gateway';

    public function getRole(): string
    {
        return 'gateway';
    }

    public function getDisplayName(): string
    {
        return 'Gateway';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        // 检查 env.php 中是否配置了 wls.gateway.enabled
        $envEnabled = \getenv('WLS_GATEWAY_ENABLED');
        if ($envEnabled !== false && \trim((string)$envEnabled) !== '') {
            return \in_array(\strtolower(\trim((string)$envEnabled)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $context->getConfig('wls.gateway.enabled', false);
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 1; // Gateway 单实例
    }

    public function getPriority(): int
    {
        return 40; // 在 Dispatcher (30) 之后启动
    }

    public function getResurrectionPriority(): int
    {
        return 4; // Gateway 复活优先级
    }

    public function isCriticalRole(): bool
    {
        return false;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $scriptDir = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin';
        $script = $scriptDir . DS . 'gateway.php';

        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName);

        // 获取监听地址
        $listen = $this->resolveListenAddress($context);
        [$listenHost, $listenPort] = explode(':', $listen);

        $arguments = [
            $listenHost,
            $listenPort,
            (string) $context->controlPort,
            (string) $context->masterPid,
            $context->instanceName,
        ];

        return new ServiceCommand(
            script: $script,
            arguments: $arguments,
            processName: $processName,
        );
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        // Gateway 监听的端口
        $listen = $this->resolveListenAddress($context);
        [, $listenPort] = explode(':', $listen);
        return (int) $listenPort;
    }

    private function resolveListenAddress(ServiceContext $context): string
    {
        $envListen = \getenv('WLS_GATEWAY_LISTEN');
        if ($envListen !== false && \trim((string)$envListen) !== '') {
            return \trim((string)$envListen);
        }

        $gatewayConfig = $context->getConfig('wls.gateway', []);
        return (string)($gatewayConfig['listen'] ?? '0.0.0.0:443');
    }

    public function handleMessage(array $message, ServiceInstance $instance, ServiceOrchestrator $orchestrator): bool
    {
        $type = $message['type'] ?? '';

        switch ($type) {
            case 'status_report':
                $instance->setMeta('last_status_report', $message);
                $orchestrator->getRegistry()->updateInstance($instance);
                return true;
        }

        return false;
    }
}
