<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Process-local ownership guard for the host/session WSAPROTOCOL export mutex.
 *
 * Windows mutex ownership is attached to the acquiring OS thread. The native
 * release closure verifies that thread before releasing; process termination is
 * the final kernel-backed abandoned-owner recovery path. Deliberately no
 * destructor releases the mutex: php-src may still own exported mapping handles
 * until module shutdown, so only the handoff state machine may release early.
 */
final class WindowsListenerHandoffMutexGuard
{
    private bool $released = false;

    /** @param \Closure():void $releaser */
    public function __construct(
        private readonly bool $abandoned,
        private readonly \Closure $releaser,
    ) {
    }

    public function wasAbandoned(): bool
    {
        return $this->abandoned;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        ($this->releaser)();
        $this->released = true;
    }

}
