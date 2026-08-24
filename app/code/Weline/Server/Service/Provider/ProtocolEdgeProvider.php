<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;

/**
 * Retired compatibility provider. Public protocol termination belongs to Nginx.
 */
final class ProtocolEdgeProvider extends AbstractServiceProvider
{
    public function getRole(): string
    {
        return 'protocol_edge_retired';
    }

    public function getDisplayName(): string
    {
        return 'Retired HTTP Protocol Edge';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        return false;
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
        // Provider is permanently disabled and retained only for stale class references.
        return 'none';
    }

    public function requiresStartupReadyBarrier(): bool
    {
        return false;
    }

    public function isCriticalRole(): bool
    {
        return false;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        throw new \RuntimeException(
            'WLS protocol edge is retired; Nginx is the only supported public protocol terminator.'
        );
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        return $context->mainPort;
    }
}
