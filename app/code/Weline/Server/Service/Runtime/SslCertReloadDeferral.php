<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Keep TLS reload fences off the Worker event-loop while HTTP Fibers are active.
 *
 * Master ACK timeout is typically 8s. A busy Worker may never become idle
 * under sustained traffic, so apply the fence after a short bounded delay.
 * Newer fences replace older pending ones (coalesce).
 *
 * @phpstan-type ReloadMessage array<string, mixed>
 */
final class SslCertReloadDeferral
{
    private const MAX_BUSY_DEFER_SECONDS = 1.0;

    /** @var ReloadMessage|null */
    private ?array $pending = null;
    private ?float $pendingSince = null;

    /**
     * @param ReloadMessage $message
     * @return bool true when the caller must skip applying now
     */
    public function offerWhileBusy(array $message, bool $foregroundBusy, ?float $now = null): bool
    {
        if (!$foregroundBusy) {
            return false;
        }
        $this->pending = $message;
        $this->pendingSince ??= $now ?? \hrtime(true) / 1_000_000_000;

        return true;
    }

    /**
     * @return ReloadMessage|null
     */
    public function takeWhenIdle(bool $foregroundBusy, ?float $now = null): ?array
    {
        if ($this->pending === null) {
            return null;
        }
        if ($foregroundBusy
            && ($now ?? \hrtime(true) / 1_000_000_000) - ($this->pendingSince ?? 0.0)
                < self::MAX_BUSY_DEFER_SECONDS
        ) {
            return null;
        }
        $message = $this->pending;
        $this->pending = null;
        $this->pendingSince = null;

        return $message;
    }

    public function hasPending(): bool
    {
        return $this->pending !== null;
    }

    public function clear(): void
    {
        $this->pending = null;
        $this->pendingSince = null;
    }
}
