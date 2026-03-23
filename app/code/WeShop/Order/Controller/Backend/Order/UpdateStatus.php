<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderService;
use Weline\Admin\Controller\BaseController;

class UpdateStatus extends BaseController
{
    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    public function post(): string
    {
        $orderId = (int) $this->request->getParam('id', 0);
        $status = (string) $this->request->getParam('status', '');
        $backUrl = (string) $this->request->getParam('back_url', $this->getBackendUrl('*/backend/order'));

        if (!$orderId) {
            $this->getMessageManager()->addError(__('Order ID is required.'));
            $this->redirect($backUrl);
            return '';
        }

        try {
            $this->orderService->updateOrderStatus($orderId, $status);
            $this->getMessageManager()->addSuccess(__('Order status updated.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Order status update failed.'));
        }

        $this->redirect($backUrl);
        return '';
    }

    public function index(): string
    {
        return $this->post();
    }
}
