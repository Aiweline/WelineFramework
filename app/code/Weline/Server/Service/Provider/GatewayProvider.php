<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\MasterProcess;

/**
 * Project-side WLS 2.0 registration/heartbeat/fallback agent.
 *
 * The host controller and Nginx data plane are deliberately not children of
 * this provider. Stopping the project therefore cannot stop the shared gateway.
 */
final class GatewayProvider extends AbstractServiceProvider
{
    public const ROLE = ControlMessage::ROLE_GATEWAY_AGENT;
    public const PROCESS_NAME_PREFIX = 'weline-wls-gateway-agent';

    public function getRole(): string
    {
        return self::ROLE;
    }

    public function getDisplayName(): string
    {
        return 'WLS 2.0 Gateway Agent';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        $effective = \strtolower(\trim((string)$context->getConfig(
            'wls.edge.mode',
            '',
        )));
        $requested = \strtolower(\trim((string)$context->getConfig(
            'wls.gateway.requested_mode',
            '',
        )));
        return $effective === GatewayStartupDecision::MODE_GATEWAY
            || $effective === GatewayStartupDecision::MODE_WLS
            || $requested === GatewayStartupDecision::MODE_AUTO;
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 1;
    }

    public function getPriority(): int
    {
        return 40;
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

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $instanceName = \trim($context->instanceName);
        if ($instanceName === '') {
            throw new \LogicException('WLS Gateway Agent requires an instance name.');
        }
        $effective = \strtolower(\trim((string)$context->getConfig(
            'wls.edge.mode',
            '',
        )));
        $requested = \strtolower(\trim((string)$context->getConfig(
            'wls.gateway.requested_mode',
            '',
        )));
        $arguments = [
            'server:gateway:agent',
            '--daemon',
            '--instance-name=' . $instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
            '--worker-id=' . $instanceId,
        ];
        if ($effective === GatewayStartupDecision::MODE_WLS
            && $requested !== GatewayStartupDecision::MODE_AUTO
        ) {
            $arguments[] = '--certificate-retirement-only';
        }
        return new ServiceCommand(
            script: BP . 'bin' . DS . 'w',
            arguments: $arguments,
            processName: MasterProcess::buildScopedProcessName(
                self::PROCESS_NAME_PREFIX,
                $instanceName,
            ),
        );
    }
}
