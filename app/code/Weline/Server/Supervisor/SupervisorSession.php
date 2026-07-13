<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor;

final class SupervisorSession
{
    public function __construct(
        public readonly int $id,
        public readonly string $peer,
        public readonly mixed $socket,
        public string $readBuffer = '',
        public string $writeBuffer = '',
        public float $lastActivityAt = 0.0,
        public string $instance = '',
        public string $channel = '',
        public string $role = '',
        public string $slotId = '',
        public int $workerId = 0,
        public int $pid = 0,
        public int $port = 0,
        public string $launchNonce = '',
        public string $leaseId = '',
        public int $generation = 0,
        /** @var array<string, mixed> */
        public array $readyCapabilities = [],
        public float $connectedAt = 0.0,
        public bool $masterAccepted = false,
        /** @var array<string, mixed> */
        public array $pendingReady = [],
    ) {
    }

    public function hasPendingWrites(): bool
    {
        return $this->writeBuffer !== '';
    }
}
