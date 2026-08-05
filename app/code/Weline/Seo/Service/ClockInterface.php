<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/** UTC clock boundary for deterministic optimization windows and evaluation. */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
