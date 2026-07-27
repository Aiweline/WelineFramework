<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceOrchestrator;

/**
 * Retired WLS Gateway provider retained only for compatibility diagnostics.
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
        $envEnabled = \getenv('WLS_GATEWAY_ENABLED');
        $configuredEnabled = $context->getConfig('wls.gateway.enabled', false);
        if ($envEnabled !== false && \trim((string)$envEnabled) !== '') {
            $enabled = \in_array(\strtolower(\trim((string)$envEnabled)), ['1', 'true', 'yes', 'on'], true);
        } elseif (\is_string($configuredEnabled)) {
            $enabled = \in_array(
                \strtolower(\trim($configuredEnabled)),
                ['1', 'true', 'yes', 'on'],
                true
            );
        } else {
            $enabled = (bool)$configuredEnabled;
        }
        if ($enabled) {
            throw new \RuntimeException(
                'wls.gateway.enabled=true: ' . (string)__('Nginx 是唯一公网边缘，不能跳过其启动。')
            );
        }

        return false;
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 0;
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
        throw new \RuntimeException(
            'GatewayProvider::buildCommand: '
            . (string)__('Nginx 是唯一公网边缘，不能跳过其启动。')
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
