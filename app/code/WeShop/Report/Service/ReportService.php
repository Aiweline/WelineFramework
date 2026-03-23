<?php

declare(strict_types=1);

namespace WeShop\Report\Service;

use WeShop\Report\Repository\ReportOrderRepositoryInterface;

/**
 * 鎶ヨ〃鏈嶅姟
 */
class ReportService
{
    public function __construct(private ReportOrderRepositoryInterface $repository)
    {
    }

    /**
     * 鑾峰彇閿€鍞姤琛?
     *
     * @param string $startDate 寮€濮嬫棩鏈?
     * @param string $endDate 缁撴潫鏃ユ湡
     * @return array
     */
    public function getSalesReport(string $startDate, string $endDate): array
    {
        $orders = $this->repository->fetchCompletedOrders($startDate, $endDate);

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
