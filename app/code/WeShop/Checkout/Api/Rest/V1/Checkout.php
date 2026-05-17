<?php

declare(strict_types=1);

namespace WeShop\Checkout\Api\Rest\V1;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;

class Checkout extends FrontendRestController
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly CheckoutPageDataService $checkoutPageDataService,
        private readonly ?CartIdentityService $cartIdentityService = null
    ) {
    }

    public function getMethods(): string
    {
        return $this->postMethods();
    }

    public function postMethods(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        $isGuestCheckout = $customerId <= 0;
        if ($customerId <= 0) {
            $customerId = $this->getCartIdentityService()->getCartCustomerId();
        }

        try {
            $data = $this->checkoutPageDataService->buildDynamicMethodData($customerId, [
                'shipping_address_id' => (int) ($this->readRequestValue('shipping_address_id') ?? 0),
                'shipping_address' => $this->readShippingAddress(),
                'shipping_method' => (string) ($this->readRequestValue('shipping_method') ?? ''),
                'payment_method' => (string) ($this->readRequestValue('payment_method') ?? ''),
                'order_id' => (int) (($this->readRequestValue('order_id') ?? $this->readRequestValue('retry_order_id')) ?? 0),
                'is_guest_checkout' => $isGuestCheckout,
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
