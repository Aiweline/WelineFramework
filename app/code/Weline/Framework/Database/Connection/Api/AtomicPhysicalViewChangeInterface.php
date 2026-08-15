<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/** Optional adapter capability for one exact-view backup + DDL transaction. */
interface AtomicPhysicalViewChangeInterface
{
    /**
     * The boolean is the view existence observed before the callback. Existing
     * views are locked ACCESS EXCLUSIVE; absent views must be created without
     * replacement so an external concurrent create causes rollback.
     *
     * @template TResult
     * @param callable(ConnectorInterface, bool): TResult $callback
     * @return TResult
     */
    public function atomicPhysicalViewChange(
        PhysicalViewIdentity $identity,
        callable $callback,
    ): mixed;
}
