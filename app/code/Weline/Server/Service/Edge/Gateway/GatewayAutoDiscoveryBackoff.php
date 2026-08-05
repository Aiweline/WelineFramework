<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Monotonic, bounded discovery schedule for an auto-mode native fallback.
 *
 * It is deliberately separate from the ACTIVE gateway heartbeat/public-probe
 * cadence. A project with no gateway therefore performs cheap trusted
 * discovery at a low and decreasing frequency without slowing recovery for an
 * already-published route.
 */
final class GatewayAutoDiscoveryBackoff
{
    private const BASE_DELAY_SECONDS = 5.0;
    private const MAXIMUM_DELAY_SECONDS = 300.0;
    private const JITTER_PARTS = 1000;
    private const MAXIMUM_JITTER_PARTS = 200;
    private const MAXIMUM_FAILURE_STREAK = 16;

    /** @var \Closure():float */
    private readonly \Closure $clock;

    /** @var \Closure(int,int):int */
    private readonly \Closure $random;

    private int $failureStreak = 0;
    private float $nextAttemptAt = 0.0;

    public function __construct(?callable $clock = null, ?callable $random = null)
    {
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): float => \hrtime(true) / 1_000_000_000;
        $this->random = $random !== null
            ? \Closure::fromCallable($random)
            : static fn(int $minimum, int $maximum): int => \random_int($minimum, $maximum);
    }

    public function isDue(): bool
    {
        return ($this->clock)() >= $this->nextAttemptAt;
    }

    public function recordFailure(): float
    {
        $exponent = \min($this->failureStreak, self::MAXIMUM_FAILURE_STREAK);
        $delay = \min(
            self::MAXIMUM_DELAY_SECONDS,
            self::BASE_DELAY_SECONDS * (2 ** $exponent),
        );
        $jitterParts = ($this->random)(
            -self::MAXIMUM_JITTER_PARTS,
            self::MAXIMUM_JITTER_PARTS,
        );
        if ($jitterParts < -self::MAXIMUM_JITTER_PARTS
            || $jitterParts > self::MAXIMUM_JITTER_PARTS
        ) {
            throw new \RuntimeException('Gateway discovery jitter source is out of bounds.');
        }
        $delay *= 1.0 + ($jitterParts / self::JITTER_PARTS);
        $delay = \max(1.0, \min(self::MAXIMUM_DELAY_SECONDS, $delay));
        $this->failureStreak = \min(
            self::MAXIMUM_FAILURE_STREAK,
            $this->failureStreak + 1,
        );
        $this->nextAttemptAt = ($this->clock)() + $delay;
        return $this->nextAttemptAt;
    }

    public function recordTrustedDiscovery(): void
    {
        $this->failureStreak = 0;
        $this->nextAttemptAt = ($this->clock)();
    }

    /** @return array{failure_streak:int,next_attempt_at:float} */
    public function observation(): array
    {
        return [
            'failure_streak' => $this->failureStreak,
            'next_attempt_at' => $this->nextAttemptAt,
        ];
    }
}
