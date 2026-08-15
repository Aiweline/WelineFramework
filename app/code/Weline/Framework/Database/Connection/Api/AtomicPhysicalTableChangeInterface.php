<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/**
 * Optional adapter capability for one exact-table backup + destructive DDL
 * critical section. Implementations must bound lock acquisition and keep the
 * callback, all nested model writes, and the DDL in one physical transaction.
 */
interface AtomicPhysicalTableChangeInterface
{
    /**
     * @template TResult
     * @param callable(ConnectorInterface): TResult $callback
     * @return TResult
     */
    public function atomicPhysicalTableChange(
        PhysicalTableIdentity $identity,
        callable $callback,
    ): mixed;
}
