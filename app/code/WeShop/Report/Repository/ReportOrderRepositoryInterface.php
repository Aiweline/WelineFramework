<?php

declare(strict_types=1);

namespace WeShop\Report\Repository;

interface ReportOrderRepositoryInterface
{
    /**
     * Fetch raw completed orders within the provided date window.
     *
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function fetchCompletedOrders(string $startDate, string $endDate): array;
}
