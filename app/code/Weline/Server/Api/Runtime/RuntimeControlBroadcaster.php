<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\Error\ErrorContext;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;

final class RuntimeControlBroadcaster implements RuntimeControlBroadcasterInterface
{
    private const DEFAULT_STATUS_TIMEOUT_SEC = 2.0;
    private const WEB_STATUS_TIMEOUT_SEC = 0.25;
    private const REQUEST_FIBER_STATUS_TIMEOUT_SEC = 0.05;

    public function __construct(
        private readonly BroadcastControlDispatchService $dispatch,
        private readonly IpcControlGateway $gateway,
    ) {
    }

    public function cacheClear(?string $instanceName = null): array
    {
        return $this->dispatch->cacheClear($instanceName, $this->statusTimeout());
    }

    public function maintenanceMode(): ?bool
    {
        if ($this->isCurrentProcessMaintenanceWorker()) {
            return true;
        }

        $instanceName = $this->currentRuntimeInstanceName();
        if ($instanceName === null) {
            return null;
        }

        try {
            $status = $this->gateway->getStatus($instanceName, $this->statusTimeout());
            if (!empty($status['success']) && \array_key_exists('maintenance_mode', $status['data'] ?? [])) {
                return (bool)$status['data']['maintenance_mode'];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public function setMaintenanceMode(bool $enabled): array
    {
        $instanceName = $this->currentRuntimeInstanceName();
        if ($instanceName !== null) {
            return $this->gateway->setMaintenanceMode($instanceName, $enabled, 6.0);
        }

        return $this->dispatch->setMaintenanceMode($enabled, null);
    }

    private function currentRuntimeInstanceName(): ?string
    {
        $instanceName = \trim((string)(\getenv('WLS_INSTANCE') ?: \getenv('WLS_INSTANCE_NAME') ?: ''));
        return $instanceName !== '' ? $instanceName : null;
    }

    private function statusTimeout(): float
    {
        if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null) {
            return self::REQUEST_FIBER_STATUS_TIMEOUT_SEC;
        }
        if (\PHP_SAPI !== 'cli') {
            return self::WEB_STATUS_TIMEOUT_SEC;
        }

        return self::DEFAULT_STATUS_TIMEOUT_SEC;
    }

    private function isCurrentProcessMaintenanceWorker(): bool
    {
        if ((bool)ErrorContext::get('is_maintenance', false)) {
            return true;
        }

        $processTag = ErrorContext::getProcessTag();
        return \is_string($processTag)
            && $processTag !== ''
            && \str_contains($processTag, 'Maintenance');
    }
}
