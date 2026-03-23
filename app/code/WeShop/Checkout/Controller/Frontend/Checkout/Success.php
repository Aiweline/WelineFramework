<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Checkout\Service\OrderSuccessPageDataService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;

class Success extends BaseController
{
    protected ?string $layoutType = 'checkout_success';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly OrderService $orderService,
        private readonly OrderSuccessPageDataService $orderSuccessPageDataService
    ) {
    }

    public function index(): string
    {
        $orderId = (int) ($this->request->getParam('order_id') ?? 0);
        if (!$orderId) {
            $this->getMessageManager()->addError(__('Order ID is required.'));

            return $this->redirect('weshop/cart');
        }

        $customer = $this->customerSession->getCustomer();
        if (!$customer || !$customer->getId()) {
            $this->getMessageManager()->addError(__('Please log in to continue.'));

            return $this->redirect('weshop/customer/account/login');
        }

        $order = $this->orderService->getOrder($orderId);
        if (!$order || (int) ($order->getData(Order::schema_fields_customer_id) ?? 0) !== (int) $customer->getId()) {
            $this->getMessageManager()->addError(__('The requested order could not be found.'));

            return $this->redirect('weshop/cart');
        }

        $lastOrderContext = $this->customerSession->get('weshop_checkout_last_order_context');
        if (!is_array($lastOrderContext) || (int) ($lastOrderContext['order_id'] ?? 0) !== $orderId) {
            $lastOrderContext = [];
        }

        foreach ($this->orderSuccessPageDataService->build($order, $lastOrderContext) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}
