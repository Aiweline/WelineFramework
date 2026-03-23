<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Checkout\Service\CheckoutService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class PlaceOrder extends FrontendController
{
    public function index(): string
    {
        try {
            $customer = $this->getCustomerSession()->getCustomer();
            if (!$customer || !$customer->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('请先登录')]);
            }

            $result = $this->getCheckoutService()->placeOrder([
                'customer_id' => (int) $customer->getId(),
                'shipping_address_id' => (int) ($this->request->getParam('shipping_address_id') ?? 0),
                'billing_address_id' => (int) ($this->request->getParam('billing_address_id') ?? 0),
                'shipping_address' => $this->readShippingAddress(),
                'shipping_method' => (string) ($this->request->getParam('shipping_method') ?? ''),
                'payment_method' => (string) ($this->request->getParam('payment_method') ?? ''),
            ]);

            return $this->fetchJson([
                'success' => true,
                'message' => __('订单创建成功'),
                'data' => [
                    'order_id' => (int) ($result['order_id'] ?? 0),
                    'increment_id' => (string) ($result['order_increment_id'] ?? ''),
                    'payment' => \is_array($result['payment'] ?? null) ? $result['payment'] : [],
                    'payment_method' => \is_array($result['payment_method'] ?? null) ? $result['payment_method'] : [],
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }

    public function post(): string
    {
        return $this->index();
    }

    protected function getCheckoutService(): CheckoutService
    {
        return ObjectManager::getInstance(CheckoutService::class);
    }

    protected function getCustomerSession(): CustomerSession
    {
        return ObjectManager::getInstance(CustomerSession::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function readShippingAddress(): array
    {
        $shippingAddress = $this->request->getParam('shipping_address') ?? $this->request->getParam('shipping') ?? [];

        return \is_array($shippingAddress) ? $shippingAddress : [];
    }
}
