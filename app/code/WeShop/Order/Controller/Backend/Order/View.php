<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderAdminPageDataService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Order::order_management_view', 'Order view actions', 'mdi mdi-eye-outline', 'View order details', 'WeShop_Order::order_management')]
class View extends BaseController
{
    public function __construct(
        private readonly OrderAdminPageDataService $orderAdminPageDataService
    ) {
    }

    #[Acl('WeShop_Order::order_management_view_index', 'View order detail', 'mdi mdi-eye', 'View order detail page')]
    public function index(): string
    {
        $orderId = (int) $this->request->getParam('id', 0);
        if (!$orderId) {
            $this->getMessageManager()->addError(__('Order ID is required.'));
            $this->redirect('*/backend/order');
            return '';
        }

        try {
            $detailData = $this->orderAdminPageDataService->getDetailData($orderId);
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Order not found.'));
            $this->redirect('*/backend/order');
            return '';
        }

        $this->assign(array_merge(
            [
                'title' => (string) __('Order Detail'),
                'orderIndexUrl' => $this->getUrl('*/backend/order'),
                'updateStatusUrl' => $this->getUrl('*/backend/order/update-status'),
                'createShipmentUrl' => $this->getUrl('*/backend/order/create-shipment'),
                'markDeliveredUrl' => $this->getUrl('*/backend/order/mark-delivered'),
            ],
            $detailData
        ));

        return $this->fetchBase();
    }
}
