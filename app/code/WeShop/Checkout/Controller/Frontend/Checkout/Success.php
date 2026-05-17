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
            $this->getMessageManager()->addError(__('缺少订单 ID。'));
            $this->redirect('weshop/cart');
            return '';
        }

        $order = $this->getOrderService()->getOrder($orderId);
        $lastOrderContext = $this->getCustomerSession()->get('weshop_checkout_last_order_context');
        if (!\is_array($lastOrderContext) || (int) ($lastOrderContext['order_id'] ?? 0) !== $orderId) {
            $lastOrderContext = [];
        }

        $customer = $this->getCustomerSession()->getCustomer();
        $orderCustomerId = $order ? (int) ($order->getData(Order::schema_fields_customer_id) ?? 0) : 0;
        $sessionCustomerId = 0;
        if ($customer && method_exists($customer, 'getAuthIdentifier')) {
            $sessionCustomerId = (int) $customer->getAuthIdentifier();
        }
        if ($sessionCustomerId <= 0 && $customer && method_exists($customer, 'getId')) {
            $sessionCustomerId = (int) $customer->getId();
        }
        if ($sessionCustomerId <= 0) {
            $sessionCustomerId = (int) ($lastOrderContext['customer_id'] ?? 0);
        }

        if (!$order || $orderCustomerId !== $sessionCustomerId || $sessionCustomerId <= 0) {
            $this->getMessageManager()->addError(__('未找到请求的订单。'));
            $this->redirect('weshop/cart');
            return '';
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
