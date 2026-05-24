<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Checkout\Service\CheckoutIdentityService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\State;
use Weline\Framework\Http\ResponseTerminateException;
class PlaceOrder extends FrontendController
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly CustomerSession $customerSession,
        private readonly ?CartIdentityService $cartIdentityService = null,
        private readonly ?CheckoutIdentityService $checkoutIdentityService = null
    ) {
    }

    public function index(): string
    {
        try {
            $cartIdentityService = $this->getCartIdentityService();
            $cartCustomerId = $cartIdentityService->getCartCustomerId();
            $authenticatedCustomerId = $cartIdentityService->getAuthenticatedCustomerId();
            $checkoutIdentity = $this->resolveCheckoutIdentity($cartIdentityService, $cartCustomerId, $authenticatedCustomerId);
            $isGuestCheckout = !empty($checkoutIdentity['is_guest_checkout']);
            $guestEmail = (string) ($checkoutIdentity['guest_email'] ?? $this->readGuestEmail());
            $orderCustomerId = $isGuestCheckout ? $cartIdentityService->getGuestCartCustomerId() : $cartCustomerId;

            $checkoutData = [
                'customer_id' => $orderCustomerId,
                'cart_customer_id' => $cartCustomerId,
                'authenticated_customer_id' => $authenticatedCustomerId,
                'checkout_mode' => (string) ($checkoutIdentity['checkout_mode'] ?? CheckoutIdentityService::MODE_GUEST),
                'is_guest_checkout' => $isGuestCheckout,
                'guest_email' => $guestEmail,
                'order_id' => (int) (($this->readRequestValue('order_id') ?? $this->readRequestValue('retry_order_id')) ?? 0),
                'shipping_address_id' => $isGuestCheckout ? 0 : (int) ($this->readRequestValue('shipping_address_id') ?? 0),
                'billing_address_id' => $isGuestCheckout ? 0 : (int) ($this->readRequestValue('billing_address_id') ?? 0),
                'billing_same_as_shipping' => $this->readBillingSameAsShipping(),
                'shipping_address' => $this->readShippingAddress(),
                'billing_address' => $this->readBillingAddress(),
                'shipping_method' => (string) ($this->readRequestValue('shipping_method') ?? ''),
                'payment_method' => (string) ($this->readRequestValue('payment_method') ?? ''),
                'notification_channels' => $this->readNotificationChannels(),
                'currency' => State::getCurrency(),
                'client_ip' => (string) ($this->request->getServer('REMOTE_ADDR') ?? ''),
            ];
            $result = $this->checkoutService->placeOrder($checkoutData);

            $this->customerSession->set('weshop_checkout_last_order_context', [
                'order_id' => (int) ($result['order_id'] ?? 0),
                'order_increment_id' => (string) ($result['order_increment_id'] ?? ''),
                'customer_id' => $orderCustomerId,
                'cart_customer_id' => $cartCustomerId,
                'authenticated_customer_id' => $authenticatedCustomerId,
                'checkout_mode' => (string) ($checkoutIdentity['checkout_mode'] ?? CheckoutIdentityService::MODE_GUEST),
                'is_guest_checkout' => $isGuestCheckout,
                'guest_email' => $guestEmail,
                'shipping_address' => $checkoutData['shipping_address'],
                'billing_address' => $checkoutData['billing_address'],
                'billing_same_as_shipping' => $checkoutData['billing_same_as_shipping'],
                'shipping_method' => $checkoutData['shipping_method'],
                'payment_method' => \is_array($result['payment_method'] ?? null) ? $result['payment_method'] : [],
                'notification_channels' => $checkoutData['notification_channels'],
                'cart_summary' => \is_array($result['order_summary'] ?? null) ? $result['order_summary'] : [],
                'is_retry_payment' => (bool) ($result['is_retry_payment'] ?? false),
            ]);
            $this->customerSession->getSession()->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('订单提交成功。'),
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

    /**
     * @return array<string, mixed>
     */
    protected function readBillingAddress(): array
    {
        $billingAddress = $this->readRequestValue('billing_address');
        if (!\is_array($billingAddress) || $billingAddress === []) {
            $billingAddress = $this->readRequestValue('billing');
        }

        $billingAddress = \is_array($billingAddress) ? $billingAddress : [];
        $guestEmail = $this->readGuestEmail();
        if ($guestEmail !== '' && empty($billingAddress['email'])) {
            $billingAddress['email'] = $guestEmail;
        }

        return $billingAddress;
    }

    protected function readBillingSameAsShipping(): bool
    {
        $value = $this->readRequestValue('billing_same_as_shipping');
        if ($value === null) {
            return true;
        }

        if (\is_bool($value)) {
            return $value;
        }

        return !\in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no'], true);
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

    /**
     * @return array<int, string>
     */
    protected function readNotificationChannels(): array
    {
        $channels = $this->readRequestValue('notification_channels');
        if (!\is_array($channels)) {
            $channels = $channels === null || $channels === '' ? [] : [$channels];
        }

        $normalized = [];
        foreach ($channels as $channel) {
            $channel = strtolower(trim((string) $channel));
            if ($channel !== '') {
                $normalized[] = $channel;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function getCartIdentityService(): CartIdentityService
    {
        return $this->cartIdentityService ?? new CartIdentityService($this->customerSession);
    }

    private function getCheckoutIdentityService(): CheckoutIdentityService
    {
        return $this->checkoutIdentityService ?? \Weline\Framework\Manager\ObjectManager::getInstance(CheckoutIdentityService::class);
    }

    private function resolveCheckoutIdentity(
        CartIdentityService $cartIdentityService,
        int $cartCustomerId,
        int $authenticatedCustomerId
    ): array {
        return $this->getCheckoutIdentityService()->resolve([
            'checkout_mode' => (string) ($this->readRequestValue('checkout_mode') ?? ''),
            'customer_id' => $cartCustomerId,
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
            'guest_email' => $this->readGuestEmail(),
            'guest_allowed' => true,
            'customer_allowed' => $authenticatedCustomerId > 0,
        ]);
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
