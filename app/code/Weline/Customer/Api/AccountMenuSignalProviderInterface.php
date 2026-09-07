<?php

declare(strict_types=1);

namespace Weline\Customer\Api;

/**
 * Contributes unread/action counts for a stable account menu code.
 *
 * Codes align with account section hashes (e.g. product.quotes ↔ #product-quotes).
 * Counts must never be SSR'd into FPC HTML; clients fetch via account.menuSignals.
 */
interface AccountMenuSignalProviderInterface
{
    /**
     * Stable menu signal code (dot-namespaced), e.g. product.quotes.
     */
    public function code(): string;

    /**
     * Unread / pending-action count for the signed-in customer in website scope.
     */
    public function count(int $customerId, int $websiteId): int;
}
