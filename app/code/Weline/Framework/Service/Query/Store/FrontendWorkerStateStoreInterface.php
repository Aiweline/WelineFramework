<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

/**
 * Atomic state boundary for Worker bootstrap/session/nonce/ticket data.
 *
 * Implementations must re-run the callback from a fresh snapshot after a CAS
 * conflict. A callback exception must never publish its partial state.
 */
interface FrontendWorkerStateStoreInterface
{
    /**
     * @template T
     * @param callable(array<string, mixed>&):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;

    public function driver(): string;

    public function isShared(): bool;
}
