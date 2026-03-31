<?php

declare(strict_types=1);

namespace WeShop\Report\Controller\Frontend\Report;

use DateTime;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Report\Repository\ReportOrderRepository;
use WeShop\Report\Repository\ReportOrderRepositoryInterface;
use WeShop\Report\Service\ReportService;

/**
 * 前台报表控制器
 */
class Sales extends BaseController
{
    private const DEFAULT_WINDOW_DAYS = 30;

    protected ?string $layoutType = 'account';

    public function __construct(
        private ?CustomerContextInterface $customerContext = null
    ) {
    }

    public function index(): string
    {
        $customerId = $this->getCustomerContext()->getUserId();
        if (!$customerId) {
            $this->redirect($this->getStorefrontLoginRoute());
            return '';
        }

        $endDate = $this->resolveEndDate();
        $startDate = $this->resolveStartDate($endDate);

        $service = $this->createReportService();
        $reportData = $service->getSalesReport($startDate, $endDate);

        $this->assign('title', (string) __('My Sales Report'));
        $this->assign('page_title', (string) __('Sales Report'));
        $this->assign('report', $reportData);
        $this->assign('start_date', $startDate);
        $this->assign('end_date', $endDate);

        return $this->fetch('WeShop_Report::templates/Frontend/Report/Sales/index.phtml');
    }

    protected function createReportService(): ReportService
    {
        return new ReportService($this->createReportOrderRepository());
    }

    protected function createReportOrderRepository(): ReportOrderRepositoryInterface
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(ReportOrderRepository::class);
    }

    private function getCustomerContext(): CustomerContextInterface
    {
        return $this->customerContext ??= \Weline\Framework\Manager\ObjectManager::getInstance(CustomerContextInterface::class);
    }

    private function resolveStartDate(string $defaultEndDate): string
    {
        $start = (string) $this->request->getParam('start', '');

        if ($start !== '' && $this->isValidDate($start)) {
            return $start;
        }

        $window = new DateTime($defaultEndDate);
        $window->modify('-' . self::DEFAULT_WINDOW_DAYS . ' days');

        return $window->format('Y-m-d');
    }

    private function resolveEndDate(): string
    {
        $end = (string) $this->request->getParam('end', '');

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
