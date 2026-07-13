<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use DateTimeImmutable;

/**
 * Cooperative control surface exposed to a Runner delegate.
 */
final class RuntimeRunnerControl
{
    public function __construct(
        private readonly RuntimeRunnerStoreInterface $store,
        private readonly RuntimeRunnerClaim $claim,
    ) {
    }

    public function heartbeat(): void
    {
        if (!$this->store->heartbeat($this->claim, new DateTimeImmutable('now'))) {
            throw new RuntimeRunnerFenceLostException('The Runtime Runner lost its fencing generation.');
        }
    }

    public function isStopRequested(): bool
    {
        return $this->store->isStopRequested($this->claim);
    }

    public function throwIfStopRequested(): void
    {
        if ($this->isStopRequested()) {
            throw new RuntimeRunnerStopRequestedException('The Runtime Runner received a cooperative stop request.');
        }
    }
}
