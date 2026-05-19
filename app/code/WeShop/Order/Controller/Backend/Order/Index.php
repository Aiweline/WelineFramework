<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderAdminPageDataService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Order::order_management', 'Order Management', 'mdi mdi-receipt-text-outline', 'Manage orders', 'Weline_Backend::order_group')]
class Index extends BaseController
{
    public function __construct(
        private readonly OrderAdminPageDataService $orderAdminPageDataService
    ) {
    }

    #[Acl('WeShop_Order::order_management_index', 'View orders', 'mdi mdi-receipt-text-search-outline', 'View order management page')]
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
                'title' => (string) __('订单管理'),
                'orderIndexUrl' => $this->getUrl('*/backend/order'),
            ],
            $this->orderAdminPageDataService->getListData($page, $pageSize, $filters)
        ));

        return $this->fetchBase();
    }
}
