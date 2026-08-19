<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\OrderFacadeInterface;

final class AccountOrderDetailResolver
{
    public function __construct(
        private readonly OrderFacadeInterface $orders,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $customerGroups
     * @return array<string, mixed>|null
     */
    public function resolve(array $customerGroups, string $orderUuid): ?array
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return null;
        }

        $owned = false;
        foreach ($customerGroups as $group) {
            foreach ((array)($group['orders'] ?? []) as $order) {
                $candidate = trim((string)($order['order_uuid'] ?? ''));
                if ($candidate !== '' && hash_equals($candidate, $orderUuid)) {
                    $owned = true;
                    break 2;
                }
            }
        }
        if (!$owned) {
            return null;
        }

        try {
            return $this->orders->get($orderUuid)->toArray();
        } catch (\Throwable) {
            return null;
        }
    }
}
