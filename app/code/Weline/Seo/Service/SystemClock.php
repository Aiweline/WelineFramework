<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/** Production UTC clock for SEO optimization. */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
