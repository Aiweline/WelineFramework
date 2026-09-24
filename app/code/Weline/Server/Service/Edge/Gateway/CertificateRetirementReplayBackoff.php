<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Adaptive spacing for certificate retirement desired-state workers.
 *
 * A non-converging pending queue (hundreds of ai-test / stale intents) must not
 * re-broadcast ssl_cert_reload every heartbeat; that stalls every Worker IPC
 * readable path for hundreds of milliseconds.
 */
final class CertificateRetirementReplayBackoff
{
    public const MIN_SECONDS = 10.0;
    public const MAX_SECONDS = 300.0;

    private float $intervalSeconds;

    public function __construct(float $initialSeconds = self::MIN_SECONDS)
    {
        $this->intervalSeconds = $this->clamp($initialSeconds);
    }

    public function intervalSeconds(): float
    {
        return $this->intervalSeconds;
    }

    public function isDue(float $nowMonotonic, float $lastAttemptAt): bool
    {
        if ($lastAttemptAt <= 0.0) {
            return true;
        }

        return ($nowMonotonic - $lastAttemptAt) >= $this->intervalSeconds;
    }

    /**
     * @param int $completed Intents finished in the last worker result
     * @param bool $deferred Replay asked to try again later without progress
     * @param bool $ok Worker process reported ok=true
     */
    public function noteResult(int $completed, bool $deferred = false, bool $ok = true): void
    {
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
}
