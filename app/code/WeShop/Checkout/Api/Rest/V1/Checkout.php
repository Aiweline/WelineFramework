<?php

declare(strict_types=1);

namespace WeShop\Checkout\Api\Rest\V1;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Checkout\Service\CheckoutIdentityService;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;

class Checkout extends FrontendRestController
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly CheckoutPageDataService $checkoutPageDataService,
        private readonly ?CartIdentityService $cartIdentityService = null,
        private readonly ?CheckoutIdentityService $checkoutIdentityService = null
    ) {
    }

    public function getMethods(): string
    {
        return $this->postMethods();
    }

    public function postMethods(): string
    {
        $authenticatedCustomerId = (int) ($this->customerContext->getUserId() ?? 0);
        $cartCustomerId = $this->getCartIdentityService()->getCartCustomerId();
        $checkoutIdentity = $this->getCheckoutIdentityService()->resolve([
            'checkout_mode' => (string) ($this->readRequestValue('checkout_mode') ?? ''),
            'customer_id' => $cartCustomerId,
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
            'guest_email' => (string) ($this->readRequestValue('guest_email') ?? $this->readRequestValue('email') ?? ''),
            'guest_allowed' => true,
            'customer_allowed' => $authenticatedCustomerId > 0,
        ]);
        $isGuestCheckout = !empty($checkoutIdentity['is_guest_checkout']);

        try {
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
                'code' => 200,
                'msg' => (string) __('Checkout methods resolved successfully.'),
                'data' => $data,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'code' => $throwable instanceof \InvalidArgumentException ? 422 : 500,
                'msg' => $throwable->getMessage(),
                'data' => $this->buildEmptyMethodsPayload(),
            ]);
        }
    }

    protected function fetchJson(array $data): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(200);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        $json = \json_encode($data, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function readShippingAddress(): array
    {
        $shippingAddress = $this->readRequestValue('shipping_address');
        if (!\is_array($shippingAddress) || $shippingAddress === []) {
            $shippingAddress = $this->readRequestValue('shipping');
        }

        return \is_array($shippingAddress) ? $shippingAddress : [];
    }

    private function readRequestValue(string $key): mixed
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

    private function getCartIdentityService(): CartIdentityService
    {
        return $this->cartIdentityService ?? ObjectManager::getInstance(CartIdentityService::class);
    }

    private function getCheckoutIdentityService(): CheckoutIdentityService
    {
        return $this->checkoutIdentityService ?? ObjectManager::getInstance(CheckoutIdentityService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEmptyMethodsPayload(): array
    {
        return [
            'selected_shipping_address_id' => 0,
            'shipping_methods' => [],
            'payment_methods' => [],
            'cart_summary' => [
                'subtotal' => 0.0,
                'shipping' => 0.0,
                'discount' => 0.0,
                'tax' => 0.0,
                'grand_total' => 0.0,
            ],
        ];
    }
}
