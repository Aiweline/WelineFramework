<?php

declare(strict_types=1);

namespace Weline\Order\Api;

/**
 * Decoupled payment-method directory for admin order chrome (select + labels).
 */
interface OrderPaymentMethodCatalogInterface
{
    /**
     * @return list<array{code: string, label: string}>
     */
    public function listActiveOptions(int $websiteId = 0, int $storeId = 0): array;

    public function resolveLabel(string $code, int $websiteId = 0, int $storeId = 0): string;
}
