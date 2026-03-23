<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class View extends BaseController
{
    public function __construct(
        private readonly OrderAdminPageDataService $orderAdminPageDataService
    ) {
    }

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
                'orderIndexUrl' => $this->getBackendUrl('*/backend/order'),
                'updateStatusUrl' => $this->getBackendUrl('*/backend/order/update-status'),
            ],
            $detailData
        ));

        return $this->fetchBase();
    }
}
