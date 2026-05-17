<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\State;
use Weline\Framework\Http\ResponseTerminateException;
class PlaceOrder extends FrontendController
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly CustomerSession $customerSession,
        private readonly ?CartIdentityService $cartIdentityService = null
    ) {
    }

    public function index(): string
    {
        try {
            $cartIdentityService = $this->getCartIdentityService();
            $cartCustomerId = $cartIdentityService->getCartCustomerId();
            $isGuestCheckout = $cartIdentityService->isGuest();
            $guestEmail = $this->readGuestEmail();
            if ($isGuestCheckout && $guestEmail === '') {
                $this->request->getResponse()->setHttpResponseCode(400);
                return $this->fetchJson(['success' => false, 'message' => __('Email is required for guest checkout.')]);
            }

            $checkoutData = [
                'customer_id' => $cartCustomerId,
                'is_guest_checkout' => $isGuestCheckout,
                'guest_email' => $guestEmail,
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
                'customer_id' => $cartCustomerId,
                'is_guest_checkout' => $isGuestCheckout,
                'guest_email' => $guestEmail,
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
        } catch (ResponseTerminateException $exception) {
            throw $exception;
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

        $shippingAddress = \is_array($shippingAddress) ? $shippingAddress : [];
        $guestEmail = $this->readGuestEmail();
        if ($guestEmail !== '' && empty($shippingAddress['email'])) {
            $shippingAddress['email'] = $guestEmail;
        }

        return $shippingAddress;
    }

    protected function readGuestEmail(): string
    {
        $email = trim((string) ($this->readRequestValue('guest_email') ?? $this->readRequestValue('email') ?? ''));
        if ($email === '') {
            $shippingAddress = $this->readRequestValue('shipping_address');
            if (\is_array($shippingAddress)) {
                $email = trim((string) ($shippingAddress['email'] ?? ''));
            }
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function getCartIdentityService(): CartIdentityService
    {
        return $this->cartIdentityService ?? new CartIdentityService($this->customerSession);
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
