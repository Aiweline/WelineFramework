<?php

declare(strict_types=1);

namespace WeShop\Report\Repository;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Order\Model\Order;

class ReportOrderRepository implements ReportOrderRepositoryInterface
{
    /**
     * @inheritDoc
     */
    public function fetchCompletedOrders(string $startDate, string $endDate): array
    {
        /** @var Order $orderModel */
        $orderModel = ObjectManager::getInstance(Order::class);

        return $orderModel->clear()
            ->where(Order::schema_fields_created_at, ['>=', $startDate])
            ->where(Order::schema_fields_created_at, ['<=', $endDate])
            ->where(Order::schema_fields_status, 'completed')
            ->select()
            ->fetchArray();
    }
}
