<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/** Test-only controllable UTC clock; never changes process or system time. */
final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable $now)
    {
        $this->now = $this->utc($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $this->utc($now);
    }

    public function advance(string $modifier): void
    {
        $next = $this->now->modify($modifier);
        if (!$next instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Frozen clock modifier is invalid.');
        }
        $this->now = $this->utc($next);
    }

    private function utc(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTimezone(new \DateTimeZone('UTC'));
    }
}
