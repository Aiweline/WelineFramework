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
            // QueryAst::where(array|string $field, mixed $value, string $condition=...)：
            // 传入 ['>=', $startDate] 会把 '>=’ 当作日期值，导致 timestamp 解析失败。
            ->where(Order::schema_fields_created_at, $startDate, '>=')
            ->where(Order::schema_fields_created_at, $endDate, '<=')
            ->where(Order::schema_fields_status, 'completed')
            ->select()
            ->fetchArray();
    }
}
