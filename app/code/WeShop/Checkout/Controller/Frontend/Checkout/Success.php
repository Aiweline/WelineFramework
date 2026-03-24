<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Checkout\Service\OrderSuccessPageDataService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

class Success extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Checkout::templates/frontend/checkout/success.phtml';

    protected ?string $layoutType = 'checkout_success';

    public function __construct(
        private ?CustomerSession $customerSession = null,
        private ?OrderService $orderService = null,
        private ?OrderSuccessPageDataService $orderSuccessPageDataService = null
    ) {
    }

    public function index(): string
    {
        $orderId = (int) ($this->request->getParam('order_id') ?? 0);
        if (!$orderId) {
            $this->getMessageManager()->addError(__('Order ID is required.'));
            $this->redirect('weshop/cart');
            return '';
        }

        $customer = $this->getCustomerSession()->getCustomer();
        if (!$customer || !$customer->getId()) {
            $this->getMessageManager()->addError(__('Please log in to continue.'));
            $this->redirect('customer/account/login');
            return '';
        }

        $order = $this->getOrderService()->getOrder($orderId);
        if (!$order || (int) ($order->getData(Order::schema_fields_customer_id) ?? 0) !== (int) $customer->getId()) {
            $this->getMessageManager()->addError(__('The requested order could not be found.'));
            $this->redirect('weshop/cart');
            return '';
        }

        $lastOrderContext = $this->getCustomerSession()->get('weshop_checkout_last_order_context');
        if (!\is_array($lastOrderContext) || (int) ($lastOrderContext['order_id'] ?? 0) !== $orderId) {
            $lastOrderContext = [];
        }

        foreach ($this->getOrderSuccessPageDataService()->build($order, $lastOrderContext) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch(self::CONTENT_TEMPLATE);
    }

    private function getCustomerSession(): CustomerSession
    {
        return $this->customerSession ??= ObjectManager::getInstance(CustomerSession::class);
    }

    private function getOrderService(): OrderService
    {
        return $this->orderService ??= ObjectManager::getInstance(OrderService::class);
    }

    private function getOrderSuccessPageDataService(): OrderSuccessPageDataService
    {
        return $this->orderSuccessPageDataService ??= ObjectManager::getInstance(OrderSuccessPageDataService::class);
    }
}
