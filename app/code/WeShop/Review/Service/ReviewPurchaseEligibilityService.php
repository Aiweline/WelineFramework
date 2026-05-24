<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use Weline\Framework\Manager\ObjectManager;

class ReviewPurchaseEligibilityService
{
    public function __construct(
        private readonly ?Order $orderModel = null,
        private readonly ?OrderItem $orderItemModel = null
    ) {
    }

    public function customerCanReviewProduct(int $customerId, int $productId): bool
    {
        if ($customerId <= 0 || $productId <= 0) {
            return false;
        }

        $orderIds = $this->getProductOrderIds($productId);
        if ($orderIds === []) {
            return false;
        }

        $orders = $this->newOrderModel()->clear()
            ->where(Order::schema_fields_customer_id, $customerId)
            ->where(Order::schema_fields_ID, $orderIds, 'IN')
            ->select()
            ->fetchArray();

        foreach ($orders as $order) {
            $status = (string) ($order[Order::schema_fields_status] ?? '');
            if (!in_array($status, ['cancelled', 'refunded'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function getProductOrderIds(int $productId): array
    {
        $items = $this->newOrderItemModel()->clear()
            ->where(OrderItem::schema_fields_PRODUCT_ID, $productId)
            ->select(OrderItem::schema_fields_ORDER_ID)
            ->fetchArray();

        $orderIds = [];
        foreach ($items as $item) {
            $orderId = (int) ($item[OrderItem::schema_fields_ORDER_ID] ?? 0);
            if ($orderId > 0) {
                $orderIds[$orderId] = $orderId;
            }
        }

        return array_values($orderIds);
    }

    private function newOrderModel(): Order
    {
        return $this->orderModel ? clone $this->orderModel : ObjectManager::getInstance(Order::class);
    }

    private function newOrderItemModel(): OrderItem
    {
        return $this->orderItemModel ? clone $this->orderItemModel : ObjectManager::getInstance(OrderItem::class);
    }
}
