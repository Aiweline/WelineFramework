<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Service\OrderService;
class RetryPayment extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly OrderService $orderService
    ) {
    }

    public function index(): string
    {
        $orderId = (int) ($this->request->getParam('order_id') ?? 0);
        if ($orderId <= 0) {
            $this->getMessageManager()->addError(__('Order ID is required.'));
            $this->redirect('weshop/order/list');
            return '';
        }

        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('Please log in to continue.'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        $retryContext = $this->orderService->getRetryPaymentContext($orderId, $customerId);
        if ($retryContext === null) {
            $this->getMessageManager()->addError(__('This order cannot continue to payment.'));
            $this->redirect('weshop/order/list');
            return '';
        }

        $this->getMessageManager()->addSuccess(__('Continue the payment flow from checkout.'));
        $this->redirect('checkout', ['order_id' => $orderId]);
        return '';
    }
}
