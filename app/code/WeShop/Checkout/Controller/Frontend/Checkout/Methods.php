<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Checkout\Service\CheckoutIdentityService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;

class Methods extends FrontendController
{
    public function __construct(
        private readonly CheckoutPageDataService $checkoutPageDataService,
        private readonly CartIdentityService|CustomerSession $cartIdentityService,
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

            $data = $this->checkoutPageDataService->buildDynamicMethodData($cartCustomerId, [
                'shipping_address_id' => $isGuestCheckout ? 0 : (int) ($this->readRequestValue('shipping_address_id') ?? 0),
                'shipping_address' => $this->readShippingAddress(),
                'shipping_method' => (string) ($this->readRequestValue('shipping_method') ?? ''),
                'payment_method' => (string) ($this->readRequestValue('payment_method') ?? ''),
                'order_id' => (int) (($this->readRequestValue('order_id') ?? $this->readRequestValue('retry_order_id')) ?? 0),
                'checkout_mode' => (string) ($checkoutIdentity['checkout_mode'] ?? CheckoutIdentityService::MODE_GUEST),
                'is_guest_checkout' => $isGuestCheckout,
                'guest_email' => (string) ($checkoutIdentity['guest_email'] ?? ''),
                'cart_customer_id' => $cartCustomerId,
                'authenticated_customer_id' => $authenticatedCustomerId,
            ]);

            return $this->fetchJson([
                'success' => true,
                'message' => __('结账方式刷新成功。'),
                'data' => $data,
            ]);
        } catch (ResponseTerminateException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            if (isset($this->request)) {
                $this->request->getResponse()->setHttpResponseCode(500);
            }
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
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

    private function getCartIdentityService(): CartIdentityService
    {
        if ($this->cartIdentityService instanceof CartIdentityService) {
            return $this->cartIdentityService;
        }

        return ObjectManager::getInstance(CartIdentityService::class);
    }

    private function getCheckoutIdentityService(): CheckoutIdentityService
    {
        return $this->checkoutIdentityService ?? ObjectManager::getInstance(CheckoutIdentityService::class);
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
            'guest_email' => (string) ($this->readRequestValue('guest_email') ?? $this->readRequestValue('email') ?? ''),
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
