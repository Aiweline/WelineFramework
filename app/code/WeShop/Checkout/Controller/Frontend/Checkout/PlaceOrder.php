<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Checkout\Service\CheckoutService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;

class PlaceOrder extends FrontendController
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly CustomerSession $customerSession
    ) {
    }

    public function index(): string
    {
        try {
            $customer = $this->customerSession->getCustomer();
            if (!$customer || !$customer->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('Please log in to continue.')]);
            }

            $checkoutData = [
                'customer_id' => (int) $customer->getId(),
                'shipping_address_id' => (int) ($this->request->getParam('shipping_address_id') ?? 0),
                'billing_address_id' => (int) ($this->request->getParam('billing_address_id') ?? 0),
                'shipping_address' => $this->readShippingAddress(),
                'shipping_method' => (string) ($this->request->getParam('shipping_method') ?? ''),
                'payment_method' => (string) ($this->request->getParam('payment_method') ?? ''),
            ];
            $result = $this->checkoutService->placeOrder($checkoutData);

            $this->customerSession->set('weshop_checkout_last_order_context', [
                'order_id' => (int) ($result['order_id'] ?? 0),
                'order_increment_id' => (string) ($result['order_increment_id'] ?? ''),
                'shipping_address' => $checkoutData['shipping_address'],
                'shipping_method' => $checkoutData['shipping_method'],
                'payment_method' => \is_array($result['payment_method'] ?? null) ? $result['payment_method'] : [],
                'cart_summary' => \is_array($result['order_summary'] ?? null) ? $result['order_summary'] : [],
            ]);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Order placed successfully.'),
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

    /**
     * @return array<string, mixed>
     */
    protected function readShippingAddress(): array
    {
        $shippingAddress = $this->request->getParam('shipping_address') ?? $this->request->getParam('shipping') ?? [];

        return \is_array($shippingAddress) ? $shippingAddress : [];
    }
}
