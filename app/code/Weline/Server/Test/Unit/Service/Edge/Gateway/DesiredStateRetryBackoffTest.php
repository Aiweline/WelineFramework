<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\DesiredStateRetryBackoff;

/**
 * P6 contract test for the gateway desired-state worker retry backoff.
 *
 * Incident: `server:gateway:agent` re-marked `$desiredStateBuildPending = true`
 * on every tick while `$probeRegistration === null` (Agent.php:650-657, pre-fix),
 * so a failing `--desired-state-worker=build` child (5-13 CPU seconds each) was
 * re-spawned with no spacing at all: ~6 spawns/minute/project, 1.42 core
 * sustained on an 8-vCPU host that only delivers ~2.2 cores.
 *
 * Contract frozen by the Lead (2026-10-30), mirroring GatewayAutoDiscoveryBackoff:
 *
 *   public const BASE_DELAY_SECONDS = 5.0;
 *   public const MAXIMUM_DELAY_SECONDS = 300.0;
 *   public const MAXIMUM_FAILURE_STREAK = 16;
 *   public const DEGRADED_AFTER_FAILURES = 5;
 *   public const PERMANENT_ERROR_MARKERS = [
 *       'restart the managed instance', 'restart is required', 'startup lease',
 *   ]; // case-insensitive str_contains; real text: GatewayRegistrationBuilder.php:1042-1044
 *
 *   __construct(?callable $clock = null, ?callable $random = null)
 *   isDue(): bool                 // !degraded && now >= nextAttemptAt
 *   recordFailure(string $errorClass = '', string $errorMessage = ''): float
 *   recordSuccess(): void
 *   isDegraded(): bool
 *   failureStreak(): int
 *   degradedReason(): string      // '' | 'consecutive_failures' | 'permanent_configuration_error'
 *   observation(): array{failure_streak:int,next_attempt_at:float,degraded:bool,
 *                        degraded_reason:string,last_error_class:string,permanent:bool}
 *
 * delay = clamp(BASE * 2^min(streak, MAX_STREAK) * (1 + jitterParts/1000), 1.0, MAX_DELAY_SECONDS)
 * with jitterParts drawn from the injected random source on [-200, 200].
 *
 * Degradation (frozen 2026-10-30) only closes `isDue()`; `recordFailure()` keeps
 * computing the ladder, so a degraded schedule parks at the 300 s cap from the
 * 7th consecutive failure on (streak 6 => 320 => clamp 300). Implementer ships
 * ladder continuation instead of "always now + 300"; see tester-report.md.
 */
final class DesiredStateRetryBackoffTest extends TestCase
{
    /**
     * @param float         $now         Mutable monotonic clock (seconds).
     * @param list<int>|int $jitterParts Jitter source output(s); int = fixed.
     */
    private function newBackoff(float &$now, array|int $jitterParts = 0): DesiredStateRetryBackoff
    {
        $parts = \is_int($jitterParts) ? [$jitterParts] : $jitterParts;
        $random = static function (int $minimum, int $maximum) use (&$parts): int {
            self::assertSame(-200, $minimum, 'jitter lower bound must be -200/1000 (±20%)');
            self::assertSame(200, $maximum, 'jitter upper bound must be 200/1000 (±20%)');
            if ($parts === []) {
                return 0;
            }

            return (int)\array_shift($parts);
        };

        return new DesiredStateRetryBackoff(
            static function () use (&$now): float {
                return $now;
            },
            $random,
        );
    }

    /** Locks the frozen contract surface (constants + observation() shape). */
    public function testFrozenContractSurface(): void
    {
        self::assertSame(5.0, DesiredStateRetryBackoff::BASE_DELAY_SECONDS);
        self::assertSame(300.0, DesiredStateRetryBackoff::MAXIMUM_DELAY_SECONDS);
        self::assertSame(16, DesiredStateRetryBackoff::MAXIMUM_FAILURE_STREAK);
        self::assertSame(5, DesiredStateRetryBackoff::DEGRADED_AFTER_FAILURES);
        // Frozen: three permanent-error markers (case-insensitive str_contains).
        foreach ([
            'restart the managed instance',
            'restart is required',
            'startup lease',
        ] as $marker) {
            self::assertContains(
                $marker,
                DesiredStateRetryBackoff::PERMANENT_ERROR_MARKERS,
                'permanent marker must be frozen in PERMANENT_ERROR_MARKERS: ' . $marker,
            );
        }
        // The exact production exception text (GatewayRegistrationBuilder.php:1042-1044)
        // must be recognised as permanent.
        $productionMessage = 'Gateway backend endpoint has no exact schema-6 startup lease; restart is required.';
        $hits = 0;
        foreach (DesiredStateRetryBackoff::PERMANENT_ERROR_MARKERS as $marker) {
            if (\str_contains(\strtolower($productionMessage), \strtolower($marker))) {
                $hits++;
            }
        }
        self::assertGreaterThanOrEqual(
            1,
            $hits,
            'the production startup-lease message must match at least one permanent marker',
        );

        $now = 10.0;
        $backoff = $this->newBackoff($now, 0);
        self::assertSame('', $backoff->degradedReason());
        self::assertSame(0, $backoff->failureStreak());
        self::assertFalse($backoff->isDegraded());
        $observation = $backoff->observation();
        foreach ([
            'failure_streak',
            'next_attempt_at',
            'degraded',
            'degraded_reason',
            'last_error_class',
            'permanent',
        ] as $key) {
            self::assertArrayHasKey($key, $observation, 'observation() must expose ' . $key);
        }
        self::assertSame(0, $observation['failure_streak']);
        self::assertFalse($observation['degraded']);
        self::assertFalse($observation['permanent']);
    }

    /**
     * ① First failure schedules the next attempt at exactly base delay
     * (jitter 0) and the jitter band is bounded and symmetric (±20%).
     */
    public function testFirstFailureSchedulesBaseDelayWithBoundedJitter(): void
    {
        $base = DesiredStateRetryBackoff::BASE_DELAY_SECONDS;

        $now = 100.0;
        $backoff = $this->newBackoff($now, 0);
        self::assertTrue($backoff->isDue(), 'the very first attempt is allowed');
        $next = $backoff->recordFailure();
        self::assertSame(100.0 + $base, $next, 'zero jitter => exactly base delay');
        self::assertFalse($backoff->isDue(), 'must not be due right after the failure');
        $now = 100.0 + $base - 0.001;
        self::assertFalse($backoff->isDue(), 'must not spawn one tick before the window');
        $now = 100.0 + $base;
        self::assertTrue($backoff->isDue(), 'must spawn exactly at the window boundary (>=)');

        // Lower jitter bound: -200/1000 => base * 0.8.
        $now = 500.0;
        $low = $this->newBackoff($now, [-200]);
        self::assertSame(500.0 + $base * 0.8, $low->recordFailure());

        // Upper jitter bound: +200/1000 => base * 1.2.
        $now = 500.0;
        $high = $this->newBackoff($now, [200]);
        self::assertSame(500.0 + $base * 1.2, $high->recordFailure());

        // Jitter must never collapse the delay to zero or unbounded values.
        foreach ([-200, -100, 0, 100, 200] as $parts) {
            $now = 0.0;
            $instance = $this->newBackoff($now, $parts);
            $delay = $instance->recordFailure() - $now;
            self::assertGreaterThanOrEqual($base * 0.8, $delay);
            self::assertLessThanOrEqual($base * 1.2, $delay);
        }
    }

    /**
     * ② Consecutive failures grow exponentially 5→10→20→40→80 (jitter 0) and
     * the spacing stays clamped by MAXIMUM_DELAY_SECONDS (never unbounded,
     * never shrinking while failures continue).
     */
    public function testConsecutiveFailuresGrowExponentiallyAndStayClamped(): void
    {
        $base = DesiredStateRetryBackoff::BASE_DELAY_SECONDS;
        $maximum = DesiredStateRetryBackoff::MAXIMUM_DELAY_SECONDS;
        $now = 0.0;
        $backoff = $this->newBackoff($now, 0);

        $delays = [];
        for ($attempt = 0; $attempt < DesiredStateRetryBackoff::MAXIMUM_FAILURE_STREAK * 2; $attempt++) {
            $delay = $backoff->recordFailure() - $now;
            $delays[] = $delay;

            // Frozen formula, verifiable for every attempt: the ladder keeps
            // growing past the degrade threshold until MAXIMUM_FAILURE_STREAK
            // saturates the exponent and the cap clamps it to 300 s.
            $expected = \min(
                $maximum,
                $base * (2 ** \min($attempt, DesiredStateRetryBackoff::MAXIMUM_FAILURE_STREAK)),
            );
            $expected = \max(1.0, $expected);
            self::assertSame(
                $expected,
                $delay,
                'failure #' . ($attempt + 1) . ' must wait base * 2^'
                . \min($attempt, DesiredStateRetryBackoff::MAXIMUM_FAILURE_STREAK)
                . ' clamped to the cap: ' . \json_encode($delays),
            );
            if ($attempt > 0) {
                self::assertGreaterThanOrEqual(
                    $delays[\count($delays) - 2],
                    $delay,
                    'spacing must never shrink while failures continue: ' . \json_encode($delays),
                );
            }
            self::assertLessThanOrEqual(
                $maximum,
                $delay,
                'spacing must stay clamped at MAXIMUM_DELAY_SECONDS: ' . \json_encode($delays),
            );
            $now += $delay;
        }

        self::assertSame(
            $base * (2 ** (DesiredStateRetryBackoff::DEGRADED_AFTER_FAILURES - 1)),
            $delays[DesiredStateRetryBackoff::DEGRADED_AFTER_FAILURES - 1],
        );
        self::assertSame(
            $maximum,
            \max($delays),
            'the ladder must saturate at the frozen cap',
        );
    }

    /**
     * ③ One success resets streak, degradation and the schedule immediately
     * (no leftover penalty from earlier failures).
     */
    public function testSuccessResetsStreakDegradationAndSchedule(): void
    {
        $base = DesiredStateRetryBackoff::BASE_DELAY_SECONDS;
        $now = 0.0;
        $backoff = $this->newBackoff($now, 0);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $now = $backoff->recordFailure();
        }
        self::assertSame(3, $backoff->failureStreak());
        self::assertFalse($backoff->isDegraded());

        $backoff->recordSuccess();
        self::assertSame(0, $backoff->failureStreak());
        self::assertFalse($backoff->isDegraded());
        self::assertSame('', $backoff->degradedReason());
        self::assertTrue($backoff->isDue(), 'success must reopen the spawn gate immediately');
        $observation = $backoff->observation();
        self::assertSame(0, $observation['failure_streak']);
        self::assertSame($now, $observation['next_attempt_at']);

        // A fresh failure after a success restarts from base delay.
        self::assertSame($now + $base, $backoff->recordFailure());

        // Success must also clear a degraded (consecutive-failure) state.
        $now = 0.0;
        $degraded = $this->newBackoff($now, 0);
        for ($attempt = 0; $attempt < DesiredStateRetryBackoff::DEGRADED_AFTER_FAILURES; $attempt++) {
            $now = $degraded->recordFailure();
        }
        self::assertTrue($degraded->isDegraded());
        $degraded->recordSuccess();
        self::assertFalse($degraded->isDegraded());
        self::assertTrue($degraded->isDue());
    }

    /**
     * ④a Consecutive failures reaching DEGRADED_AFTER_FAILURES close the spawn
     * gate (isDue() stays false) and mark reason=consecutive_failures.
     */
    public function testConsecutiveFailuresDegradeAndStopSpawning(): void
    {
        $base = DesiredStateRetryBackoff::BASE_DELAY_SECONDS;
        $maximum = DesiredStateRetryBackoff::MAXIMUM_DELAY_SECONDS;
        $now = 0.0;
        $backoff = $this->newBackoff($now, 0);

        for ($attempt = 1; $attempt < DesiredStateRetryBackoff::DEGRADED_AFTER_FAILURES; $attempt++) {
            $now = $backoff->recordFailure();
            self::assertFalse(
                $backoff->isDegraded(),
                'must not degrade after only ' . $attempt . ' failure(s)',
            );
            self::assertTrue($backoff->isDue(), 'pre-degradation attempts stay allowed');
        }

        $now = $backoff->recordFailure();
        self::assertTrue($backoff->isDegraded(), 'must degrade at the frozen threshold');
        self::assertSame('consecutive_failures', $backoff->degradedReason());
        self::assertGreaterThanOrEqual(
            DesiredStateRetryBackoff::DEGRADED_AFTER_FAILURES,
            $backoff->failureStreak(),
        );

        // Degraded => no spawn for a full minute even though the last window
        // (80s) has not elapsed yet; this is the "不再 spawn" guarantee.
        for ($tick = 1; $tick <= 60; $tick++) {
            $now += 1.0;
            self::assertFalse(
                $backoff->isDue(),
                'degraded backoff must not spawn on tick ' . $tick,
            );
        }

        // Even beyond the maximum delay window the gate stays closed.
        $now += DesiredStateRetryBackoff::MAXIMUM_DELAY_SECONDS * 2;
        self::assertFalse($backoff->isDue(), 'degradation is sticky until a success');

        // Degraded keeps computing the frozen ladder: the 5th failure parked the
        // schedule at 80 s (streak 5 => 160 s on the next failure, streak 6 =>
        // 320 s clamped to the 300 s cap). The spawn gate stays closed.
        $parkedAt = $backoff->recordFailure();
        self::assertSame($now + $base * 32, $parkedAt, 'streak 5 => 160s ladder step');
        self::assertFalse($backoff->isDue(), 'ladder step must not reopen the gate');
        $parkedAt = $backoff->recordFailure();
        self::assertSame($now + $maximum, $parkedAt, 'streak 6 => clamped to 300s cap');
        for ($attempt = 0; $attempt < 8; $attempt++) {
            self::assertSame(
                $now + $maximum,
                $backoff->recordFailure(),
                'degraded failures must stay parked at the cap',
            );
            self::assertFalse($backoff->isDue());
        }

        $observation = $backoff->observation();
        self::assertTrue($observation['degraded']);
        self::assertSame('consecutive_failures', $observation['degraded_reason']);
        self::assertSame($now + $maximum, $observation['next_attempt_at']);
    }

    /**
     * ④b A permanent configuration error degrades on the FIRST failure with
     * reason=permanent_configuration_error: exactly one spawn is ever allowed
     * (no retry storm on a broken config). Covers the real production message
     * (`GatewayRegistrationBuilder.php:1042-1044`) and case-insensitivity.
     */
    public function testPermanentConfigurationErrorDegradesImmediately(): void
    {
        $productionMessage = 'Gateway backend endpoint has no exact schema-6 startup lease; restart is required.';
        $cases = [
            'production startup-lease message' => $productionMessage,
            'restart is required' => 'worker refused: RESTART IS REQUIRED before any build',
            'restart the managed instance' => 'Restart The Managed Instance, config drift detected',
            'startup lease (uppercase)' => 'NO EXACT Schema-6 STARTUP LEASE FOR ENDPOINT',
        ];

        foreach ($cases as $label => $message) {
            $now = 100.0;
            $backoff = $this->newBackoff($now, 0);
            // Spawn #1 is the only spawn this policy will ever allow.
            self::assertTrue($backoff->isDue(), $label . ': first spawn must be allowed');

            $backoff->recordFailure('RuntimeException', $message);
            self::assertTrue($backoff->isDegraded(), $label . ': permanent error must degrade immediately');
            self::assertSame(
                'permanent_configuration_error',
                $backoff->degradedReason(),
                $label . ': degraded reason must be permanent_configuration_error',
            );
            self::assertFalse($backoff->isDue(), $label . ': no second spawn');
            self::assertSame(1, $backoff->failureStreak(), $label . ': exactly one failure observed');

            $observation = $backoff->observation();
            self::assertTrue($observation['degraded'], $label);
            self::assertTrue($observation['permanent'], $label . ': observation must flag permanent');
            self::assertSame('RuntimeException', $observation['last_error_class'], $label);

            // Spawn count stays at exactly 1 for a full 10-minute horizon: the
            // gate is closed by degradation regardless of the ladder position.
            for ($tick = 1; $tick <= 600; $tick++) {
                $now += 1.0;
                self::assertFalse($backoff->isDue(), $label . ': must stay closed on tick ' . $tick);
            }

            // The ladder still parks the schedule at the frozen cap; nothing
            // reopens the gate except an explicit success.
            $parkedAt = 0.0;
            for ($attempt = 0; $attempt < 12; $attempt++) {
                $parkedAt = $backoff->recordFailure('RuntimeException', $message);
                self::assertTrue($backoff->isDegraded(), $label);
                self::assertFalse($backoff->isDue(), $label . ': no second spawn');
            }
            self::assertSame(
                $now + DesiredStateRetryBackoff::MAXIMUM_DELAY_SECONDS,
                $parkedAt,
                $label . ': degraded ladder must park at the frozen cap',
            );

            // An explicit recovery (successful worker) reopens the gate.
            $backoff->recordSuccess();
            self::assertFalse($backoff->isDegraded(), $label);
            self::assertSame('', $backoff->degradedReason(), $label);
            self::assertTrue($backoff->isDue(), $label . ': success must reopen the gate');
        }
    }

    /**
     * ⑤ Boundary values: zero elapsed time, exactly-equal window, and a
     * monotonic clock that rolls backwards (fail closed, never spawn, no crash).
     */
    public function testBoundaryZeroWindowAndClockRollback(): void
    {
        $base = DesiredStateRetryBackoff::BASE_DELAY_SECONDS;
        $now = 1000.0;
        $backoff = $this->newBackoff($now, 0);
        self::assertTrue($backoff->isDue(), 'first attempt is allowed');

        $next = $backoff->recordFailure();
        self::assertSame(1000.0 + $base, $next);

        // Elapsed == 0: no immediate respawn (zero-delay spawn storm guard).
        $now = 1000.0;
        self::assertFalse($backoff->isDue(), 'zero elapsed time must not be due');

        // Exactly equal to the window: due (>= semantics).
        $now = $next;
        self::assertTrue($backoff->isDue(), 'exactly reaching the window must be due');

        // Clock rollback: fail closed, do not crash, never spawn.
        $now = $next - 500.0;
        self::assertFalse($backoff->isDue(), 'a rolled-back clock must not spawn');
        $rolledBackNext = $backoff->recordFailure();
        self::assertTrue(\is_finite($rolledBackNext), 'rollback must produce a finite schedule');
        self::assertGreaterThanOrEqual($now, $rolledBackNext, 'no attempt may be scheduled in the past');
        self::assertFalse($backoff->isDue(), 'still not due right after a rolled-back failure');
        self::assertSame(2, $backoff->failureStreak());

        // Once the clock catches up past the new window the gate opens again.
        $now = $rolledBackNext;
        self::assertTrue($backoff->isDue(), 'recovered clock must reopen the gate at its window');

        // Degraded + rolled back stays closed (fail closed on both conditions).
        $now = 0.0;
        $degraded = $this->newBackoff($now, 0);
        for ($attempt = 0; $attempt < DesiredStateRetryBackoff::DEGRADED_AFTER_FAILURES; $attempt++) {
            $now = $degraded->recordFailure();
        }
        self::assertTrue($degraded->isDegraded());
        $now -= 10_000.0;
        self::assertFalse($degraded->isDue(), 'degraded + rollback must stay closed');
    }
}
