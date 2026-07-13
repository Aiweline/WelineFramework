<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;

/**
 * Public HTTP/3, HTTP/2 and HTTP/1.1 transport adapter.
 *
 * This process owns only TLS/QUIC and connection multiplexing. Every request
 * is forwarded to WorkerPolicyKernel, which remains the sole L7 policy owner.
 */
final class ProtocolEdgeProvider extends AbstractServiceProvider
{
    public function getRole(): string
    {
        return ProtocolEdgeRuntime::ROLE;
    }

    public function getDisplayName(): string
    {
        return 'HTTP Protocol Edge';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        return $context->isProtocolEdgeEnabled();
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 1;
    }

    public function getPriority(): int
    {
        return 35;
    }

    public function getResurrectionPriority(): int
    {
        return 4;
    }

    public function getReloadStrategy(): string
    {
        // Code reloads keep the established public TLS/QUIC listener and its
        // session cache alive. Certificate reload is handled over Caddy admin.
        return 'none';
    }

    public function requiresStartupReadyBarrier(): bool
    {
        return true;
    }

    public function isCriticalRole(): bool
    {
        return true;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $binary = ProtocolEdgeRuntime::resolveBinary($context);
        if ($binary === '') {
            throw new \RuntimeException('The verified Caddy protocol-edge binary is unavailable to Master.');
        }

        $configFile = ProtocolEdgeRuntime::writeConfig($context);
        $tokenFile = ProtocolEdgeRuntime::ensureTokenFile($context->instanceName);
        $script = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin' . DS . 'protocol_edge.php';
        $processName = MasterProcess::buildScopedProcessName(
            ProtocolEdgeRuntime::PROCESS_NAME_PREFIX,
            $context->instanceName,
        );
        $arguments = [
            $context->instanceName,
            (string)$context->mainPort,
            '--caddy-binary=' . $binary,
            '--config=' . $configFile,
            '--pid-file=' . ProtocolEdgeRuntime::pidFile($context->instanceName),
            '--token-file=' . $tokenFile,
            '--public-host=' . (string)($context->publicHost ?: $context->host),
            '--admin-address=127.0.0.1:' . ProtocolEdgeRuntime::adminPort($context),
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
        ];
        foreach (ProtocolEdgeRuntime::upstreams($context) as $upstream) {
            $arguments[] = '--upstream=' . $upstream;
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
}
