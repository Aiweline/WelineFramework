<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

/** Controllable clock for lease hard-cap tests. */
final class ControllableClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
