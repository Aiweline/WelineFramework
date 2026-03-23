<?php

declare(strict_types=1);

namespace WeShop\Logistics\Controller\Backend\Tracking;

use WeShop\Logistics\Service\TrackingAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly TrackingAdminPageDataService $trackingAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $editingId = (int) $this->request->getParam('id', 0);
        $filters = [
            'order_id' => $this->request->getParam('order_id', ''),
            'tracking_number' => $this->request->getParam('tracking_number', ''),
            'carrier' => $this->request->getParam('carrier', ''),
            'status' => $this->request->getParam('status', ''),
        ];

        $this->assign(array_merge(
            [
                'title' => (string) __('Tracking Management'),
                'trackingIndexUrl' => $this->getBackendUrl('*/backend/tracking'),
                'trackingSaveUrl' => $this->getBackendUrl('*/backend/tracking/save'),
            ],
            $this->trackingAdminPageDataService->getPageData($page, $pageSize, $filters, $editingId)
        ));

        return $this->fetchBase();
    }
}
