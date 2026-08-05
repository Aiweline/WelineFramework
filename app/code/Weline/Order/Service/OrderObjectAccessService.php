<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Model\Order;

/**
 * 后台订单授权前的最小持久化加载边界。
 */
class OrderObjectAccessService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly OrderObjectScopeService $scopeService,
    ) {
    }

    /**
     * @return array{order:Order,scope:ScopeIdentity}|null
     */
    public function find(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        /** @var Order $order */
        $order = $this->objectManager->getInstance(Order::class);
        $order->load($orderId);
        if (!$order->getId()) {
            return null;
        }

        return [
            'order' => $order,
            'scope' => $this->scopeService->fromOrder($order),
        ];
    }
}
