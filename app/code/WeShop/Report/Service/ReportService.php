<?php

declare(strict_types=1);

namespace WeShop\Report\Service;

use WeShop\Report\Repository\ReportOrderRepositoryInterface;

/**
 * Report Service
 *
 * Provides sales, customer, and product report data.
 */
class ReportService
{
    public function __construct(private ReportOrderRepositoryInterface $repository)
    {
    }

    /**
     * Get sales report summary for a date range.
     *
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     */
    public function getSalesReport(string $startDate, string $endDate): array
    {
        $orders = $this->repository->fetchCompletedOrders($startDate, $endDate);

        $totalSales = 0;
        $orderCount = count($orders);
        $byDay = [];

        foreach ($orders as $orderData) {
            $total = (float) ($orderData['total'] ?? 0);
            $totalSales += $total;

            $day = substr((string) ($orderData['created_at'] ?? ''), 0, 10);
            if ($day !== '') {
                if (!isset($byDay[$day])) {
                    $byDay[$day] = ['total' => 0.0, 'count' => 0];
                }
                $byDay[$day]['total'] += $total;
                $byDay[$day]['count']++;
            }
        }

        return [
            'total_sales' => $totalSales,
            'order_count' => $orderCount,
            'average_order' => $orderCount > 0 ? round($totalSales / $orderCount, 2) : 0.0,
            'by_day' => $byDay,
        ];
    }

    /**
     * Get sales report data formatted for admin page display.
     */
    public function getPageData(string $startDate, string $endDate): array
    {
        $report = $this->getSalesReport($startDate, $endDate);

        return [
            'total_sales' => number_format((float) $report['total_sales'], 2),
            'order_count' => (int) $report['order_count'],
            'average_order' => number_format((float) $report['average_order'], 2),
            'by_day' => $report['by_day'],
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }
}
