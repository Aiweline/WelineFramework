<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Adaptive spacing for certificate retirement desired-state workers.
 *
 * A non-converging pending queue (hundreds of ai-test / stale intents) must not
 * re-broadcast ssl_cert_reload every heartbeat; that stalls every Worker IPC
 * readable path for hundreds of milliseconds.
 *
 * Empty queues must not keep probing / spawning short-lived retirement workers
 * on the idle floor either — that is the ~350 txn/s background storm.
 */
final class CertificateRetirementReplayBackoff
{
    public const MIN_SECONDS = 10.0;
    public const MAX_SECONDS = 300.0;
    /** Floor after an empty pending probe (no spawn). */
    public const IDLE_EMPTY_SECONDS = 60.0;
    /** Cap while the queue stays empty. */
    public const IDLE_MAX_SECONDS = 600.0;
    /** Process-local pending probe cache TTL upper bound. */
    public const PENDING_PROBE_TTL_SECONDS = 5.0;

    private float $intervalSeconds;
    private bool $idleEmpty = false;

    public function __construct(float $initialSeconds = self::MIN_SECONDS)
    {
        $this->intervalSeconds = $this->clamp($initialSeconds);
    }

    public function intervalSeconds(): float
    {
        return $this->intervalSeconds;
    }

    public function isIdleEmpty(): bool
    {
        return $this->idleEmpty;
    }

    public function isDue(float $nowMonotonic, float $lastAttemptAt): bool
    {
        if ($lastAttemptAt <= 0.0) {
            return true;
        }

        return ($nowMonotonic - $lastAttemptAt) >= $this->intervalSeconds;
    }

    /**
     * Short TTL for process-local pendingRetirementIntents probes so idle
     * loops do not re-walk the retirement store every tick while waiting out
     * the spawn backoff.
     */
    public function probeCacheTtlSeconds(): float
    {
        return \min(
            self::PENDING_PROBE_TTL_SECONDS,
            \max(1.0, $this->intervalSeconds / 4.0),
        );
    }

    /** Empty pending probe: stretch idle floor; never spawn from this note. */
    public function noteEmptyQueue(): void
    {
        $this->idleEmpty = true;
        if ($this->intervalSeconds < self::IDLE_EMPTY_SECONDS) {
            $this->intervalSeconds = self::IDLE_EMPTY_SECONDS;

            return;
        }
        $this->intervalSeconds = $this->clampIdle($this->intervalSeconds * 2.0);
    }

    /**
     * Pending intents observed. Leaving a long idle stretch must not delay the
     * first real replay; reset to the active floor once.
     */
    public function notePendingQueue(): void
    {
        if ($this->idleEmpty) {
            $this->idleEmpty = false;
            $this->intervalSeconds = self::MIN_SECONDS;
        }
    }

    /**
     * @param int $completed Intents finished in the last worker result
     * @param bool $deferred Replay asked to try again later without progress
     * @param bool $ok Worker process reported ok=true
     */
    public function noteResult(int $completed, bool $deferred = false, bool $ok = true): void
    {
        $this->idleEmpty = false;
        if ($completed > 0 && $ok) {
            $this->intervalSeconds = self::MIN_SECONDS;

            return;
        }
        $factor = $deferred || !$ok ? 3.0 : 2.0;
        $this->intervalSeconds = $this->clamp($this->intervalSeconds * $factor);
    }

    private function clamp(float $seconds): float
    {
        if (!\is_finite($seconds) || $seconds < self::MIN_SECONDS) {
            return self::MIN_SECONDS;
        }
        if ($seconds > self::MAX_SECONDS) {
            return self::MAX_SECONDS;
        }

        return $seconds;
    }

    private function clampIdle(float $seconds): float
    {
        if (!\is_finite($seconds) || $seconds < self::IDLE_EMPTY_SECONDS) {
            return self::IDLE_EMPTY_SECONDS;
        }
        if ($seconds > self::IDLE_MAX_SECONDS) {
            return self::IDLE_MAX_SECONDS;
        }

        return $seconds;
    }
}
