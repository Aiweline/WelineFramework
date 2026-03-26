<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;

class Methods extends FrontendController
{
    public function __construct(
        private readonly CheckoutPageDataService $checkoutPageDataService,
        private readonly CustomerSession $customerSession
    ) {
    }

    public function index(): string
    {
        try {
            $customer = $this->customerSession->getCustomer();
            if (!$customer || !$customer->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Please log in to continue.'),
                ]);
            }

            $data = $this->checkoutPageDataService->buildDynamicMethodData((int) $customer->getId(), [
                'shipping_address_id' => (int) ($this->request->getParam('shipping_address_id') ?? 0),
                'shipping_address' => $this->readShippingAddress(),
                'shipping_method' => (string) ($this->request->getParam('shipping_method') ?? ''),
                'payment_method' => (string) ($this->request->getParam('payment_method') ?? ''),
            ]);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Checkout methods refreshed successfully.'),
                'data' => $data,
            ]);
        } catch (\Throwable $throwable) {
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
        $shippingAddress = $this->request->getParam('shipping_address') ?? $this->request->getParam('shipping') ?? [];

        return \is_array($shippingAddress) ? $shippingAddress : [];
    }
}
