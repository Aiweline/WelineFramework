<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class Methods extends FrontendController
{
    public function __construct(
        private readonly CheckoutPageDataService $checkoutPageDataService,
        private readonly CartIdentityService|CustomerSession $cartIdentityService
    ) {
    }

    public function index(): string
    {
        try {
            $cartIdentityService = $this->getCartIdentityService();
            $cartCustomerId = $cartIdentityService->getCartCustomerId();

            $data = $this->checkoutPageDataService->buildDynamicMethodData($cartCustomerId, [
                'shipping_address_id' => (int) ($this->readRequestValue('shipping_address_id') ?? 0),
                'shipping_address' => $this->readShippingAddress(),
                'shipping_method' => (string) ($this->readRequestValue('shipping_method') ?? ''),
                'payment_method' => (string) ($this->readRequestValue('payment_method') ?? ''),
                'order_id' => (int) (($this->readRequestValue('order_id') ?? $this->readRequestValue('retry_order_id')) ?? 0),
                'is_guest_checkout' => $cartIdentityService->isGuest(),
            ]);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Checkout methods refreshed successfully.'),
                'data' => $data,
            ]);
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
