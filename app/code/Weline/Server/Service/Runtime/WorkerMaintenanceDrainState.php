<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Tracks the control-plane maintenance acknowledgement barrier for one Worker.
 *
 * Maintenance is applied by the transport immediately. Business Workers delay
 * the matching ACK until request work that is already being drained is gone;
 * maintenance Workers and disable operations may acknowledge on the next loop.
 */
final class WorkerMaintenanceDrainState
{
    private ?string $pendingRequestId = null;

    private bool $waitForRequestDrain = false;

    /**
     * Maintenance is applied while polling IPC near the start of a transport
     * loop. Requiring one later poll gives that loop a chance to consume TLS
     * bytes already buffered by OpenSSL before the Worker can acknowledge.
     */
    private bool $requestDrainCycleArmed = false;

    public function __construct(private readonly bool $maintenanceWorker)
    {
    }

    public function modeApplied(bool $enabled, string $requestId): void
    {
        $requestId = \trim($requestId);

        if ($requestId === '') {
            if (!$enabled || $this->maintenanceWorker) {
                $this->pendingRequestId = null;
                $this->waitForRequestDrain = false;
                $this->requestDrainCycleArmed = false;
            }
            return;
        }

        $this->pendingRequestId = $requestId;
        $this->waitForRequestDrain = $enabled && !$this->maintenanceWorker;
        $this->requestDrainCycleArmed = false;
    }

    public function nextAcknowledgement(bool $requestWorkDrained): ?string
    {
        if ($this->pendingRequestId === null) {
            return null;
        }
        if ($this->waitForRequestDrain && !$this->requestDrainCycleArmed) {
            $this->requestDrainCycleArmed = true;
            return null;
        }
        if ($this->waitForRequestDrain && !$requestWorkDrained) {
            return null;
        }

        return $this->pendingRequestId;
    }

    public function isWaitingForRequestDrain(): bool
    {
        return $this->pendingRequestId !== null && $this->waitForRequestDrain;
    }

    public function markAcknowledged(string $requestId): void
    {
        if ($requestId !== $this->pendingRequestId) {
            return;
        }

        $this->pendingRequestId = null;
        $this->waitForRequestDrain = false;
        $this->requestDrainCycleArmed = false;
    }
}
