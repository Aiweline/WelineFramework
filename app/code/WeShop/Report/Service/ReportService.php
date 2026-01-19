<?php

declare(strict_types=1);

namespace WeShop\Report\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Order\Model\Order;

/**
 * 报表服务
 */
class ReportService
{
    /**
     * 获取销售报表
     * 
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return array
     */
    public function getSalesReport(string $startDate, string $endDate): array
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        
        $orders = $order->clear()
            ->where(Order::fields_created_at, ['>=', $startDate])
            ->where(Order::fields_created_at, ['<=', $endDate])
            ->where(Order::fields_status, 'completed')
            ->select()
            ->fetchArray();
        
        $totalSales = 0;
        $orderCount = count($orders);
        
        foreach ($orders as $orderData) {
            $totalSales += (float)($orderData['total'] ?? 0);
        }
        
        return [
            'total_sales' => $totalSales,
            'order_count' => $orderCount,
            'average_order' => $orderCount > 0 ? $totalSales / $orderCount : 0,
        ];
    }
}
