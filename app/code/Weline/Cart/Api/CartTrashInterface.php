<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

interface CartTrashInterface
{
    /**
     * Move an active cart item into the cart trash for quick recovery.
     */
    public function moveToTrash(int $cartId, int $customerId = 0): bool;

    /**
     * Restore a trashed cart item back into the active cart.
     */
    public function restoreFromTrash(int $cartId, int $customerId = 0): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTrashItems(int $customerId, int $limit = 10): array;

    public function getTrashItemCount(int $customerId): int;
}
