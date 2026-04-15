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
    ) {
    }

    public function hasPendingWrites(): bool
    {
        return $this->writeBuffer !== '';
    }
}
