<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * Internal persistence boundary for OrderFacade.
 *
 * The public cross-module contract remains OrderFacadeInterface; this interface
 * exists so persistence races and rollback can be tested without exposing ORM
 * models to callers.
 */
interface OrderFacadeStoreInterface
{
    /** @return array<string, mixed>|null */
    public function findGroupByIdempotencyKey(string $idempotencyKey): ?array;

    /** @return array<string, mixed>|null */
    public function findGroup(string $checkoutGroupUuid): ?array;

    /** @return array<string, mixed>|null */
    public function findOrder(string $orderUuid): ?array;

    /** @param array<string, mixed> $group */
    public function persist(array $group): void;
}
