<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Keep TLS reload fences off the Worker event-loop while HTTP Fibers are active.
 *
 * Master ACK timeout is typically 8s; deferring until the current request
 * drains (usually &lt;2s) preserves ACK semantics without a 300ms IPC stall.
 * Newer fences replace older pending ones (coalesce).
 *
 * @phpstan-type ReloadMessage array<string, mixed>
 */
final class SslCertReloadDeferral
{
    /** @var ReloadMessage|null */
    private ?array $pending = null;

    /**
     * @param ReloadMessage $message
     * @return bool true when the caller must skip applying now
     */
    public function offerWhileBusy(array $message, bool $foregroundBusy): bool
    {
        if (!$foregroundBusy) {
            return false;
        }
        $this->pending = $message;

        return true;
    }

    /**
     * @return ReloadMessage|null
     */
    public function takeWhenIdle(bool $foregroundBusy): ?array
    {
        if ($foregroundBusy || $this->pending === null) {
            return null;
        }
        $message = $this->pending;
        $this->pending = null;

        return $message;
    }

    public function hasPending(): bool
    {
        return $this->pending !== null;
    }

    public function clear(): void
    {
        $this->pending = null;
    }
}
