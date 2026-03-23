<?php

declare(strict_types=1);

namespace WeShop\Report\Controller\Backend\Report;

use DateTime;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Report\Repository\ReportOrderRepository;
use WeShop\Report\Repository\ReportOrderRepositoryInterface;
use WeShop\Report\Service\ReportService;

class Sales extends BackendController
{
    private const DEFAULT_WINDOW_DAYS = 30;

    public function index(): string
    {
        $endDate = $this->resolveEndDate();
        $startDate = $this->resolveStartDate($endDate);

        $service = $this->createReportService();
        $reportData = $service->getSalesReport($startDate, $endDate);

        $this->assign('title', __('Sales Report'));
        $this->assign('report', $reportData);
        $this->assign('start_date', $startDate);
        $this->assign('end_date', $endDate);

        return $this->fetch('report/sales/index');
    }

    protected function createReportService(): ReportService
    {
        return new ReportService($this->createReportOrderRepository());
    }

    protected function createReportOrderRepository(): ReportOrderRepositoryInterface
    {
        return ObjectManager::getInstance(ReportOrderRepository::class);
    }

    private function resolveStartDate(string $defaultEndDate): string
    {
        $start = (string)$this->request->getParam('start', '');

        if ($start !== '' && $this->isValidDate($start)) {
            return $start;
        }

        $window = new DateTime($defaultEndDate);
        $window->modify('-' . self::DEFAULT_WINDOW_DAYS . ' days');

        return $window->format('Y-m-d');
    }

    private function resolveEndDate(): string
    {
        $end = (string)$this->request->getParam('end', '');

        if ($end !== '' && $this->isValidDate($end)) {
            return $end;
        }

        return (new DateTime())->format('Y-m-d');
    }

    private function isValidDate(string $value): bool
    {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
