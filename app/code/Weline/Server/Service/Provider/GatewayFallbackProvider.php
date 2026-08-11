<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Edge\PureWlsPublicOrigin;
use Weline\Server\Service\Edge\ServingManifestRuntimeFence;
use Weline\Server\Service\MasterProcess;

/**
 * A dynamically activated TLS listener owned by the existing project Master.
 *
 * The provider is registered at bootstrap but has zero desired instances.
 * Only the authenticated gateway Agent may ask the Orchestrator to create or
 * drain its single runtime instance.
 */
final class GatewayFallbackProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-gateway-fallback';

    public function __construct(
        private readonly int $port = 0,
        private readonly string $certificate = '',
        private readonly string $privateKey = '',
        private readonly string $bindHost = '127.0.0.1',
        private readonly string $publicOrigin = '',
        private readonly bool $inheritedListener = false,
        private readonly bool $runtimeEnabled = false,
        private readonly string $hostLeaseId = '',
    ) {
    }

    public function getRole(): string
    {
        return ControlMessage::ROLE_GATEWAY_FALLBACK;
    }

    public function getDisplayName(): string
    {
        return 'WLS Gateway Fallback TLS';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        return $this->runtimeEnabled;
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return $this->runtimeEnabled ? 1 : 0;
    }

    public function getPriority(): int
    {
        return 45;
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
            throw new \RuntimeException('Gateway fallback port must be inside 20000-29999.');
        }
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $this->hostLeaseId) !== 1) {
            throw new \RuntimeException('Gateway fallback requires its exact host port lease identity.');
        }
        $gatewayMasterLaunchId = \strtolower(\trim((string)$context->getConfig(
            'wls.gateway.launch_id',
            '',
        )));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $gatewayMasterLaunchId) !== 1) {
            throw new \RuntimeException(
                'Gateway fallback requires its exact project Master launch identity.'
            );
        }
        if (!\is_file($this->certificate) || !\is_file($this->privateKey)
            || \is_link($this->certificate) || \is_link($this->privateKey)
        ) {
            throw new \RuntimeException(
                'Gateway fallback requires active project certificate and private-key files.'
            );
        }
        $host = \strtolower(\trim($this->bindHost, " \t\n\r\0\x0B[]"));
        if (\filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException(
                'Gateway fallback bind must be a resolved IPv4 or IPv6 address.'
            );
        }
        $publicOrigin = $this->resolvePublicOrigin($context, $host);
        $arguments = [
            $host,
            (string)$this->port,
            (string)$instanceId,
            $context->instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
            '--memory-limit=' . $context->getWorkerMemoryLimit(),
            '--worker-count=1',
            '--wls-runtime-topology=direct',
            '--wls-listener-mode=' . ($this->inheritedListener ? 'shared_fd' : 'single'),
            '--public-origin=' . $publicOrigin,
            '--wls-loop-driver=' . $context->runtimeSelection->eventLoopDriver,
            '--gateway-fallback',
            '--gateway-host-lease-id=' . $this->hostLeaseId,
            '--gateway-master-launch-id=' . $gatewayMasterLaunchId,
            '--defer-ssl',
        ];
        $arguments = \array_merge(
            $arguments,
            ServingManifestRuntimeFence::workerArguments($context),
        );
        if ($this->inheritedListener) {
            if (\PHP_OS_FAMILY === 'Windows') {
                throw new \RuntimeException(
                    'Windows cannot inherit the POSIX gateway fallback listener.'
                );
            }
            $arguments[] = '--listen-fd=3';
        }
        $arguments = \array_merge(
            $arguments,
            WorkerRuntimeArgumentBuilder::gatewayFallbackProtocolPolicy($context),
            WorkerRuntimeArgumentBuilder::sharedState($context),
        );
        return new ServiceCommand(
            script: BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server'
                . DS . 'bin' . DS . 'worker_ssl.php',
            arguments: $arguments,
            processName: MasterProcess::buildScopedProcessName(
                self::PROCESS_NAME_PREFIX,
                $context->instanceName,
            ),
        );
    }

    private function resolvePublicOrigin(ServiceContext $context, string $bindHost): string
    {
        if (\trim($this->publicOrigin) !== '') {
            return PureWlsPublicOrigin::normalize($this->publicOrigin);
        }

        $candidate = \trim((string)$context->getConfig(
            'wls.gateway.certificate_source.domain',
            $context->publicHost ?? '',
        ));
        if ($candidate === '' || \str_starts_with($candidate, '*.')) {
            // A wildcard certificate still requires a concrete hostname/SNI.
            // The literal bind address is connectable transport metadata, not
            // a valid TLS authority, so never turn it into a public URL.
            return '';
        }

        return PureWlsPublicOrigin::fromHostAndPort($candidate, $this->port, true);
    }
}
