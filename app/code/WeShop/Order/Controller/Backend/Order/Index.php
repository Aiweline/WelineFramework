<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly OrderAdminPageDataService $orderAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $filters = [
            'status' => $this->request->getParam('status', ''),
            'increment_id' => $this->request->getParam('increment_id', ''),
            'customer_id' => $this->request->getParam('customer_id', ''),
        ];

        $this->assign(array_merge(
            [
                'title' => (string) __('Order Management'),
                'orderIndexUrl' => $this->getBackendUrl('*/backend/order'),
            ],
            $this->orderAdminPageDataService->getListData($page, $pageSize, $filters)
        ));

        return $this->fetchBase();
    }
}
