<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Optional SPI: memory-pressure relief that respects soft vs hard tiers.
 *
 * Soft must not blindly clearMemory() when finer eviction exists.
 */
interface MemoryPressureAwareInterface
{
    /**
     * @return int Number of entries removed (best-effort)
     */
    public function relievePressure(bool $aggressive): int;
}
