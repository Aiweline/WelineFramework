<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Bounded retry schedule for the Gateway Agent's desired-state build worker.
 *
 * A failing desired-state build child is expensive: it boots the whole
 * framework and runs a serving-publication transaction (5-13 CPU seconds).
 * Before this schedule existed, the Agent re-marked the build as pending on
 * every 1 Hz tick and re-spawned the child with no spacing at all (~6
 * spawns/minute/project, 1.42 core sustained on one project). The Agent now
 * asks this schedule before it spends another child process while an earlier
 * result could not be adopted.
 *
 * Two independent stops keep the degradation bounded:
 *
 *  - `consecutive_failures`: DEGRADED_AFTER_FAILURES failures in a row close
 *    the gate until an explicit success (or an external gateway-intent re-arm
 *    performed by the Agent) reopens it.
 *  - `permanent_configuration_error`: a configuration error marker (missing
 *    backend fence / launch identity / instance generation) degrades on the
 *    very first failure; retrying a broken configuration only burns CPU.
 *
 * The schedule is monotonic-clock based and lives in the Agent process only;
 * an Agent restart rebuilds it from scratch.
 */
final class DesiredStateRetryBackoff
{
    public const BASE_DELAY_SECONDS = 5.0;
    public const MAXIMUM_DELAY_SECONDS = 300.0;
    public const MAXIMUM_FAILURE_STREAK = 16;
    public const DEGRADED_AFTER_FAILURES = 5;
    public const PERMANENT_ERROR_MARKERS = [
        'restart the managed instance',
        'restart is required',
        'startup lease',
    ];

    private const JITTER_PARTS = 1000;
    private const MAXIMUM_JITTER_PARTS = 200;

    /** @var \Closure():float */
    private readonly \Closure $clock;

    /** @var \Closure(int,int):int */
    private readonly \Closure $random;

    private int $failureStreak = 0;
    private float $nextAttemptAt = 0.0;
    private bool $degraded = false;
    private string $degradedReason = '';
    private string $lastErrorClass = '';

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
        if ($this->degraded) {
            return false;
        }

        return $this->now() >= $this->nextAttemptAt;
    }

    /**
     * Record one failed build attempt and schedule the next one.
     *
     * @return float Monotonic timestamp of the next allowed attempt.
     */
    public function recordFailure(string $errorClass = '', string $errorMessage = ''): float
    {
        $now = $this->now();
        $this->lastErrorClass = $errorClass;

        $exponent = \min($this->failureStreak, self::MAXIMUM_FAILURE_STREAK);
        $delay = self::BASE_DELAY_SECONDS * (2 ** $exponent);
        $jitterParts = ($this->random)(
            -self::MAXIMUM_JITTER_PARTS,
            self::MAXIMUM_JITTER_PARTS,
        );
        if ($jitterParts < -self::MAXIMUM_JITTER_PARTS
            || $jitterParts > self::MAXIMUM_JITTER_PARTS
        ) {
            throw new \RuntimeException(
                'Desired-state build jitter source is out of bounds.',
            );
        }
        $delay *= (self::JITTER_PARTS + $jitterParts) / self::JITTER_PARTS;
        if (!\is_finite($delay)) {
            $delay = self::MAXIMUM_DELAY_SECONDS;
        }
        $delay = \max(1.0, \min(self::MAXIMUM_DELAY_SECONDS, $delay));

        $this->failureStreak = \min(
            self::MAXIMUM_FAILURE_STREAK,
            $this->failureStreak + 1,
        );
        // A monotonic clock can only move forward in production; a rolled-back
        // test clock still yields a finite future window (never a past one).
        $this->nextAttemptAt = $now + $delay;

        if ($this->hasPermanentErrorMarker($errorMessage)) {
            $this->degraded = true;
            $this->degradedReason = 'permanent_configuration_error';
        } elseif ($this->failureStreak >= self::DEGRADED_AFTER_FAILURES) {
            $this->degraded = true;
            $this->degradedReason = 'consecutive_failures';
        }

        return $this->nextAttemptAt;
    }

    /**
     * One adopted build result clears every penalty immediately. The Agent also
     * calls this when the external gateway intent changed (edge mode / join
     * state / launch identity), so a sticky degradation cannot outlive a real
     * reconfiguration.
     */
    public function recordSuccess(): void
    {
        $this->failureStreak = 0;
        $this->degraded = false;
        $this->degradedReason = '';
        $this->lastErrorClass = '';
        $this->nextAttemptAt = $this->now();
    }

    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    public function failureStreak(): int
    {
        return $this->failureStreak;
    }

    /** @return ''|'consecutive_failures'|'permanent_configuration_error' */
    public function degradedReason(): string
    {
        return $this->degradedReason;
    }

    /**
     * Process-local observation for throttled logging and diagnostics.
     *
     * @return array{
     *     failure_streak:int,
     *     next_attempt_at:float,
     *     degraded:bool,
     *     degraded_reason:string,
     *     last_error_class:string,
     *     permanent:bool
     * }
     */
    public function observation(): array
    {
        return [
            'failure_streak' => $this->failureStreak,
            'next_attempt_at' => $this->nextAttemptAt,
            'degraded' => $this->degraded,
            'degraded_reason' => $this->degradedReason,
            'last_error_class' => $this->lastErrorClass,
            'permanent' => $this->degradedReason === 'permanent_configuration_error',
        ];
    }

    private function hasPermanentErrorMarker(string $errorMessage): bool
    {
        if ($errorMessage === '') {
            return false;
        }
        foreach (self::PERMANENT_ERROR_MARKERS as $marker) {
            if (\stripos($errorMessage, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function now(): float
    {
        $now = ($this->clock)();

        return \is_finite($now) ? $now : 0.0;
    }
}
