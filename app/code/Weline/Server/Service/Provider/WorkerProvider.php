<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\HealthCheckResult;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\Runtime\DirectSharedListener;
use Weline\Server\Service\Edge\Gateway\GatewayBackendIngressTokenStore;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\Edge\ServingManifestRuntimeFence;

/**
 * Worker 服务提供者
 *
 * HTTP Worker 进程，处理实际的 HTTP 请求。
 *
 * 优先级：20（在 Session Server 之后启动）
 */
class WorkerProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-worker';

    public function getRole(): string
    {
        return 'worker';
    }

    public function getDisplayName(): string
    {
        return 'HTTP Worker';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        return true;
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        $count = $context->getWorkerCount();
        if ($count === 'auto') {
            return $this->getAutoCpuCount();
        }
        return (int) $count;
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getResurrectionPriority(): int
    {
        return 2;
    }

    public function getReloadStrategy(): string
    {
        return 'graceful';
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $scriptDir = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin';

        $edgeAdapter = (new \Weline\Server\Service\Edge\EdgeAdapterResolver())->resolve($context->envConfig);
        $pureWls = $edgeAdapter->name()
            === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS;
        $gatewayBackend = \strtolower(\trim((string)$context->getConfig(
            'wls.edge.mode',
            $context->getConfig('edge_mode', ''),
        ))) === GatewayStartupDecision::MODE_GATEWAY;
        $script = $pureWls && $context->sslEnabled
            ? $this->resolveSslWorkerScript($scriptDir, $context)
            : $scriptDir . DS . 'worker.php';

        $port = $this->getPort($instanceId, $context);
        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName, $instanceId);

        // Nginx owns a loopback H1 backend. Pure WLS Direct owns the selected
        // public listener; pure WLS Dispatcher keeps Workers on loopback.
        $direct = $context->isDirect();
        $listenerMode = $context->runtimeSelection->listenerMode;
        $host = $pureWls && $direct
            ? ($context->host ?: '127.0.0.1')
            : '127.0.0.1';

        $arguments = [
            $host,
            (string) $port,
            (string) $instanceId,
            $context->instanceName,
        ];

        if ($pureWls && $context->sslEnabled) {
            $arguments = \array_merge(
                $arguments,
                ServingManifestRuntimeFence::workerArguments($context),
            );
            $arguments = \array_merge($arguments, WorkerRuntimeArgumentBuilder::protocolPolicy($context));
        }

        $arguments[] = '--control-port=' . $context->controlPort;
        $arguments[] = '--master-pid=' . $context->masterPid;
        $arguments[] = '--memory-limit=' . $context->getWorkerMemoryLimit();
        $arguments[] = '--worker-count=' . $this->getInstanceCount($context);
        $arguments[] = '--wls-runtime-topology='
            . $context->runtimeSelection->effectiveTopology->value;
        $arguments[] = '--wls-listener-mode=' . $listenerMode;
        // READY 首页预热使用启动时固化的对外 origin，避免 Windows 替换 instance.json 时的短暂空窗。
        // 保持为离散 argv，不使用 environment，以便 Windows 继续走快速批量启动路径。
        $arguments[] = '--public-origin=' . WorkerRuntimeArgumentBuilder::publicOrigin($context);
        if ($gatewayBackend) {
            $arguments[] = '--gateway-backend-token-file='
                . GatewayBackendIngressTokenStore::ensureTokenFile($context->instanceName);
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
                    'Gateway backend requires project UUID, instance launch ID, and monotonic instance generation.'
                );
            }
            $arguments[] = '--gateway-project-uuid=' . $projectUuid;
            $arguments[] = '--gateway-instance-generation=' . $instanceGeneration;
            $arguments[] = '--gateway-instance-launch-id=' . $instanceLaunchId;
            $arguments = \array_merge(
                $arguments,
                WorkerRuntimeArgumentBuilder::gatewayBackendCapability($context),
            );
            $arguments = \array_merge(
                $arguments,
                $this->gatewayListenerIdentityArguments(
                    $context,
                    $host,
                    $port,
                    $instanceLaunchId,
                ),
            );
        }

        if ($direct && $listenerMode === 'shared_fd') {
            $arguments[] = '--listen-fd=' . DirectSharedListener::INHERITED_FD;
            $handoff = $context->getConfig('wls.gateway.startup_listener_handoff', []);
            if (\is_array($handoff)
                && (string)($handoff['transport'] ?? '') === 'posix_inherited_fd'
                && ($handoff['continuous_ownership'] ?? false) === true
                && (int)($handoff['fd'] ?? 0) === DirectSharedListener::INHERITED_FD
                && (int)($handoff['port'] ?? 0) === $port
                && \preg_match(
                    '/\A[a-f0-9]{32}\z/D',
                    (string)($handoff['lease_id'] ?? ''),
                ) === 1
            ) {
                if (!$gatewayBackend) {
                    $arguments[] = '--gateway-host-lease-id=' . (string)$handoff['lease_id'];
                }
            }
        } elseif ($direct && !\in_array($listenerMode, ['reuseport', 'worker_ports'], true)) {
            throw new \InvalidArgumentException(
                'Direct topology requires listener mode reuseport, shared_fd, or worker_ports.'
            );
        }

        $arguments = \array_merge($arguments, WorkerRuntimeArgumentBuilder::sharedState($context));

        $arguments[] = '--wls-loop-driver=' . $context->runtimeSelection->eventLoopDriver;

        if ($pureWls && $context->sslEnabled) {
            // Accept TCP first so one long-lived Stream SSL context can select
            // SNI material and ALPN h2/http/1.1 per connection.
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
        $basePort = $context->getWorkerBasePort();
        if ($context->isDirect()) {
            if ($context->runtimeSelection->listenerMode === 'worker_ports') {
                return $context->getWorkerPort() + \max(0, $instanceId - 1);
            }
            return $context->mainPort;
        }

        // 零停机滚动重启：临时 Worker (ID > 100) 使用动态端口避免冲突
        // 例如：Worker 1 使用 9501，临时 Worker 101 使用 9601
        if ($instanceId > 100) {
            return $basePort + $instanceId;
        }

        return $basePort + $instanceId;
    }

    public function healthCheck(ServiceInstance $instance): HealthCheckResult
    {
        $result = parent::healthCheck($instance);
        if (!$result->isHealthy()) {
            return $result;
        }

        if ($instance->state !== ServiceInstance::STATE_READY) {
            return HealthCheckResult::degraded('Worker not ready, state: ' . $instance->state);
        }

        return HealthCheckResult::healthy();
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

    private function resolveSslWorkerScript(string $scriptDir, ServiceContext $context): string
    {
        return match ($context->runtimeSelection->sslEngine) {
            'stream' => $scriptDir . DS . 'worker_ssl.php',
            default => throw new \InvalidArgumentException(
                'Pure WLS HTTPS requires wls.ssl.engine=stream.'
            ),
        };
    }

    /**
     * Bind the signed backend attestation to the listener the gateway actually
     * connected to. In Dispatcher topology the request is forwarded to a
     * private Worker, so the Worker's own port is not the public backend
     * identity. The immutable startup handoff remains the authority for the
     * Dispatcher tuple and its host lease.
     *
     * @return list<string>
     */
    private function gatewayListenerIdentityArguments(
        ServiceContext $context,
        string $workerHost,
        int $workerPort,
        string $instanceLaunchId,
    ): array {
        $gateway = $context->getConfig('wls.gateway', []);
        $gateway = \is_array($gateway) ? $gateway : [];
        $dispatcher = $context->runtimeSelection->isDispatcher();
        $expectedHost = \strtolower(\trim($workerHost));
        $expectedPort = $dispatcher ? $context->mainPort : $workerPort;
        if (!\in_array($expectedHost, ['127.0.0.1', '::1'], true)
            || $expectedPort < 1
            || $expectedPort > 65535
        ) {
            throw new \RuntimeException(
                'Gateway Worker listener identity is outside the loopback backend boundary.'
            );
        }

        $lease = \is_array($gateway['backend_lease'] ?? null)
            ? $gateway['backend_lease']
            : [];
        $leaseId = \strtolower(\trim((string)($lease['lease_id'] ?? '')));
        if ((int)($lease['schema_version'] ?? 0) !== GatewayPortLeaseAllocator::SCHEMA_VERSION
            || !\hash_equals($expectedHost, \strtolower(\trim((string)($lease['bind_host'] ?? ''))))
            || (int)($lease['port'] ?? 0) !== $expectedPort
            || \preg_match('/\A[a-f0-9]{32}\z/D', $leaseId) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway Worker listener identity does not match its schema-6 backend lease.'
            );
        }

        $handoff = \is_array($gateway['startup_listener_handoff'] ?? null)
            ? $gateway['startup_listener_handoff']
            : [];
        if ($handoff !== []) {
            $handoffLeaseId = \strtolower(\trim((string)($handoff['lease_id'] ?? '')));
            $handoffLaunchId = \strtolower(\trim((string)($handoff['launch_id'] ?? '')));
            if ((int)($handoff['schema_version'] ?? 0) !== 1
                || ($handoff['continuous_ownership'] ?? false) !== true
                || !\hash_equals($expectedHost, \strtolower(\trim((string)($handoff['bind_host'] ?? ''))))
                || (int)($handoff['port'] ?? 0) !== $expectedPort
                || !\hash_equals($leaseId, $handoffLeaseId)
                || !\hash_equals($instanceLaunchId, $handoffLaunchId)
            ) {
                throw new \RuntimeException(
                    'Gateway Worker listener identity does not match its startup handoff.'
                );
            }
        } elseif ($dispatcher) {
            throw new \RuntimeException(
                'Dispatcher-backed Gateway Worker requires a continuous startup listener handoff.'
            );
        }

        return [
            '--gateway-listener-host=' . $expectedHost,
            '--gateway-listener-port=' . $expectedPort,
            '--gateway-host-lease-id=' . $leaseId,
        ];
    }

}
