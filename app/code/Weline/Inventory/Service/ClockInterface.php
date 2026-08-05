<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

/** Injectable clock for lease tests (TEST-P2B-05). */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
