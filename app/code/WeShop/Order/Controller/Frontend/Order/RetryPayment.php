<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Service\OrderService;

class RetryPayment extends BaseController
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly OrderService $orderService
    ) {
    }

    public function index(): string
    {
        $orderId = (int) ($this->request->getParam('order_id') ?? 0);
        if ($orderId <= 0) {
            $this->getMessageManager()->addError(__('缺少订单 ID。'));
            $this->redirect('weshop/order/list');
            return '';
        }

        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('请先登录。'));
            $this->redirect($this->getStorefrontLoginRoute());
            return '';
        }

        $retryContext = $this->orderService->getRetryPaymentContext($orderId, $customerId);
        if ($retryContext === null) {
            $this->getMessageManager()->addError(__('该订单无法继续支付。'));
            $this->redirect('weshop/order/list');
            return '';
        }

        $this->getMessageManager()->addSuccess(__('请从结账页继续完成支付。'));
        $this->redirect('checkout', ['order_id' => $orderId]);
        return '';
    }
}
