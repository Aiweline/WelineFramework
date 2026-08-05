<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;
use Weline\Server\Service\Runtime\WindowsListenerHandoff;
use Weline\Server\Service\ServiceOrchestrator;

/**
 * Dispatcher 服务提供者
 *
 * 负责把入口连接分发到 Worker。Nginx 模式只接收 loopback HTTP/1.1
 * 回源；纯 WLS 模式可透传公网 TLS/HTTP 连接。
 * Windows 上带 schema-5 启动租约的纯 WLS/网关回源都使用唯一
 * Dispatcher 持续接管预留监听器；无交接标记的 legacy Nginx 可保持 Direct。
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
        $edgeAdapterName = (new \Weline\Server\Service\Edge\EdgeAdapterResolver())
            ->resolve($context->envConfig)
            ->name();
        $gatewayBackend = \strtolower(\trim((string)$context->getConfig(
            'wls.edge.mode',
            $context->getConfig('edge_mode', ''),
        ))) === GatewayStartupDecision::MODE_GATEWAY;

        $arguments = [
            $this->resolveBindHost($context, $edgeAdapterName),
            (string) $port,
            (string) $workerBasePort,
            (string) $workerCount,
            $context->instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
            '--memory-limit=' . $context->getDispatcherMemoryLimit(),
            '--edge-adapter=' . $edgeAdapterName,
        ];
        if ($gatewayBackend) {
            $arguments[] = '--protocol-edge-token-file='
                . ProtocolEdgeRuntime::ensureTokenFile($context->instanceName);
        }

        $handoff = $context->getConfig('wls.gateway.startup_listener_handoff', []);
        $handoffTransport = \is_array($handoff)
            ? (string)($handoff['transport'] ?? '')
            : '';
        if (\is_array($handoff)
            && \in_array($handoffTransport, [
                'posix_inherited_fd',
                WindowsListenerHandoff::TRANSPORT,
            ], true)
            && ($handoff['continuous_ownership'] ?? false) === true
            && (int)($handoff['port'] ?? 0) === $port
            && \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($handoff['lease_id'] ?? ''),
            ) === 1
        ) {
            if ($handoffTransport === 'posix_inherited_fd') {
                if ((int)($handoff['fd'] ?? 0)
                    !== \Weline\Server\Service\Runtime\DirectSharedListener::INHERITED_FD
                ) {
                    throw new \RuntimeException(
                        'Dispatcher POSIX handoff metadata has an invalid inherited FD.'
                    );
                }
                $arguments[] = '--listen-fd='
                    . \Weline\Server\Service\Runtime\DirectSharedListener::INHERITED_FD;
            }
            $arguments[] = '--gateway-host-lease-id=' . (string)$handoff['lease_id'];
        }

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

    private function resolveBindHost(ServiceContext $context, string $edgeAdapterName): string
    {
        if ($edgeAdapterName === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX) {
            return '127.0.0.1';
        }

        $host = \trim((string)$context->host);
        return $host !== '' ? $host : '127.0.0.1';
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
