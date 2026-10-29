<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Monotonic probe deadline for the certificate-retirement queue observation.
 *
 * The Agent observes the retirement queue from inside a 1 Hz tick. The deadline
 * used to be derived from the tick START timestamp (`$now + 0.25`): whenever
 * earlier work in the same tick (heartbeat, controller status, worker reaping,
 * lock waits) consumed more than 250 ms, the store rejected the probe with
 * "Certificate retirement state deadline was exhausted." — the retirement queue
 * never advanced and the Agent logged one error per probe window forever.
 *
 * This helper derives the observation window from the CURRENT monotonic clock
 * and guarantees a deadline that is strictly in the future, so a late-in-tick
 * probe still gets a real (bounded) window:
 *
 *   d = min(tickDeadline, now + BUDGET)  when the tick deadline is usable
 *   d = now + BUDGET                     otherwise, or when that is not finite
 *                                        / not strictly after `now`
 */
final class RetirementProbeDeadline
{
    public const BUDGET_SECONDS = 0.25;

    /** @var \Closure():float */
    private readonly \Closure $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): float => \hrtime(true) / 1_000_000_000;
    }

    public function forTick(float $tickDeadline): float
    {
        $now = $this->now();
        $fallback = $now + self::BUDGET_SECONDS;
        if (!\is_finite($tickDeadline) || $tickDeadline <= 0.0) {
            return $fallback;
        }
        $deadline = \min($tickDeadline, $fallback);
        if (!\is_finite($deadline) || $deadline <= $now) {
            // Stale (already consumed) tick budget or a rolled-back clock: the
            // observation still gets its own bounded window.
            return $fallback;
        }

        return $deadline;
    }

    private function now(): float
    {
        $now = ($this->clock)();

        return \is_finite($now) ? $now : 0.0;
    }
}
