<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;

/** WLS-owned process that supervises detached resumable task Runners. */
final class RuntimeTaskWatchdogProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-runtime-watchdog';

    public function getRole(): string
    {
        return 'runtime_watchdog';
    }

    public function getDisplayName(): string
    {
        return 'Resumable Task Watchdog';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        $environment = getenv('WLS_RUNTIME_WATCHDOG_ENABLED');
        if (is_string($environment) && trim($environment) !== '') {
            return in_array(strtolower(trim($environment)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool)$context->getConfig('wls.runtime_watchdog.enabled', true);
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 1;
    }

    public function getPriority(): int
    {
        return 15;
    }

    public function getResurrectionPriority(): int
    {
        return 2;
    }

    public function getReloadStrategy(): string
    {
        return 'graceful';
    }

    public function requiresStartupReadyBarrier(): bool
    {
        // Runtime task watchdog is auxiliary for resumable background tasks.
        // HTTP workers and Dispatcher must be allowed to enter READY even if a
        // fresh or partially-upgraded watchdog process exits and self-heals.
        return false;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $instanceName = trim($context->instanceName);
        if ($instanceName === '') {
            throw new \LogicException('Runtime Watchdog requires a WLS instance name.');
        }

        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $instanceName);
        return new ServiceCommand(
            script: BP . 'bin' . DS . 'w',
            arguments: [
                'runtime:task:watch',
                '--daemon',
                '--instance-name=' . $instanceName,
                '--control-port=' . $context->controlPort,
                '--master-pid=' . $context->masterPid,
                '--worker-id=' . $instanceId,
            ],
            processName: $processName,
        );
    }
}
