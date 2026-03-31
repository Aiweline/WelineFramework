<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Checkout\Service\CheckoutService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\State;
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
                $this->request->getResponse()->setHttpResponseCode(401);
                return $this->fetchJson(['success' => false, 'message' => __('Please log in to continue.')]);
            }

            $checkoutData = [
                'customer_id' => (int) $customer->getId(),
                'order_id' => (int) (($this->readRequestValue('order_id') ?? $this->readRequestValue('retry_order_id')) ?? 0),
                'shipping_address_id' => (int) ($this->readRequestValue('shipping_address_id') ?? 0),
                'billing_address_id' => (int) ($this->readRequestValue('billing_address_id') ?? 0),
                'shipping_address' => $this->readShippingAddress(),
                'shipping_method' => (string) ($this->readRequestValue('shipping_method') ?? ''),
                'payment_method' => (string) ($this->readRequestValue('payment_method') ?? ''),
                'currency' => State::getCurrency(),
                'client_ip' => (string) ($this->request->getServer('REMOTE_ADDR') ?? ''),
            ];
            $result = $this->checkoutService->placeOrder($checkoutData);

            $this->customerSession->set('weshop_checkout_last_order_context', [
                'order_id' => (int) ($result['order_id'] ?? 0),
                'order_increment_id' => (string) ($result['order_increment_id'] ?? ''),
                'shipping_address' => $checkoutData['shipping_address'],
                'shipping_method' => $checkoutData['shipping_method'],
                'payment_method' => \is_array($result['payment_method'] ?? null) ? $result['payment_method'] : [],
                'cart_summary' => \is_array($result['order_summary'] ?? null) ? $result['order_summary'] : [],
                'is_retry_payment' => (bool) ($result['is_retry_payment'] ?? false),
            ]);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Order placed successfully.'),
                'data' => [
                    'order_id' => (int) ($result['order_id'] ?? 0),
                    'increment_id' => (string) ($result['order_increment_id'] ?? ''),
                    'payment' => \is_array($result['payment'] ?? null) ? $result['payment'] : [],
                    'payment_method' => \is_array($result['payment_method'] ?? null) ? $result['payment_method'] : [],
                    'redirect_url' => (string) (($result['payment']['redirect_url'] ?? $result['payment']['payment_url'] ?? '')),
                    'is_retry_payment' => (bool) ($result['is_retry_payment'] ?? false),
                ],
            ]);
        } catch (\InvalidArgumentException $exception) {
            if (isset($this->request)) {
                $this->request->getResponse()->setHttpResponseCode(400);
            }
            return $this->fetchJson(['success' => false, 'message' => $exception->getMessage()]);
        } catch (\Throwable $throwable) {
            if (isset($this->request)) {
                $this->request->getResponse()->setHttpResponseCode(500);
            }
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
        $shippingAddress = $this->readRequestValue('shipping_address');
        if (!\is_array($shippingAddress) || $shippingAddress === []) {
            $shippingAddress = $this->readRequestValue('shipping');
        }

        return \is_array($shippingAddress) ? $shippingAddress : [];
    }

    protected function readRequestValue(string $key): mixed
    {
        $postValue = $this->request->getPost($key, null);
        if ($postValue !== null && $postValue !== '') {
            return $postValue;
        }

        $bodyValue = $this->request->getBodyParam($key, null);
        if ($bodyValue !== null && $bodyValue !== '') {
            return $bodyValue;
        }

        return $this->request->getParam($key, null);
    }

}
