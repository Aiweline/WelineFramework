<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Controller\Backend;

use WeShop\RecentlyViewed\Service\RecentlyViewedAdminPageDataService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Manager\MessageManager;

class RecentlyViewed extends BaseController
{
    public function __construct(
        private readonly RecentlyViewedAdminPageDataService $adminPageDataService
    ) {
    }

    /**
     * 浏览历史列表页面
     */
    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $filters = [
            'customer_id' => $this->request->getParam('customer_id', ''),
            'product_id' => $this->request->getParam('product_id', ''),
            'date_from' => $this->request->getParam('date_from', ''),
            'date_to' => $this->request->getParam('date_to', ''),
        ];

        $statistics = $this->adminPageDataService->getStatistics();

        $this->assign(array_merge(
            [
                'title' => (string) __('Recently Viewed Management'),
                'indexUrl' => $this->_url->getBackendUrl('*/backend/recentlyViewed'),
                'statistics' => $statistics,
            ],
            $this->adminPageDataService->getListData($page, $pageSize, $filters)
        ));

        return $this->fetchBase();
    }

    /**
     * 清除所有浏览历史
     */
    public function clearAll(): string
    {
        try {
            $deleted = $this->adminPageDataService->clearAll();
            MessageManager::success(__('Successfully cleared %{1} records', $deleted));
        } catch (\Throwable $e) {
            MessageManager::error(__('Failed to clear history: %{1}', $e->getMessage()));
        }

        return $this->redirect('*/*/index');
    }

    /**
     * 按客户清除浏览历史
     */
    public function clearByCustomer(): string
    {
        $customerId = (int) $this->request->getParam('customer_id', 0);

        if ($customerId <= 0) {
            MessageManager::error(__('Invalid customer ID'));
            return $this->redirect('*/*/index');
        }

        try {
            $deleted = $this->adminPageDataService->clearByCustomerId($customerId);
            MessageManager::success(__('Successfully cleared %{1} records for customer %{2}', [$deleted, $customerId]));
        } catch (\Throwable $e) {
            MessageManager::error(__('Failed to clear history: %{1}', $e->getMessage()));
        }

        return $this->redirect('*/*/index');
    }

    /**
     * 清除过期浏览历史
     */
    public function clearExpired(): string
    {
        $days = (int) $this->request->getParam('days', 30);

        if ($days <= 0) {
            $days = 30;
        }

        try {
            $deleted = $this->adminPageDataService->clearOlderThanDays($days);
            MessageManager::success(__('Successfully cleared %{1} records older than %{2} days', [$deleted, $days]));
        } catch (\Throwable $e) {
            MessageManager::error(__('Failed to clear expired history: %{1}', $e->getMessage()));
        }

        return $this->redirect('*/*/index');
    }
}
