<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
use Weline\Checkout\Service\CheckoutIdentityService;
use Weline\Framework\Manager\ObjectManager;
class Index extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Checkout::templates/frontend/checkout/index.phtml';

    protected ?string $layoutType = 'checkout';

    public function __construct(
        private readonly CartIdentityService|CustomerSession $cartIdentityService,
        private readonly CheckoutPageDataService $checkoutPageDataService,
        private readonly ?CheckoutIdentityService $checkoutIdentityService = null
    ) {
    }

    public function index()
    {
        $cartIdentityService = $this->getCartIdentityService();
        $customer = $cartIdentityService->getCustomer();
        $cartCustomerId = $cartIdentityService->getCartCustomerId();
        $authenticatedCustomerId = $cartIdentityService->getAuthenticatedCustomerId();
        $checkoutIdentity = $this->getCheckoutIdentityService()->resolve([
            'checkout_mode' => (string) ($this->request->getParam('checkout_mode') ?? ''),
            'customer_id' => $cartCustomerId,
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
            'guest_allowed' => true,
            'customer_allowed' => $authenticatedCustomerId > 0,
        ]);

        $currentStep = max(1, min(3, (int) ($this->request->getParam('step') ?? 1)));
        $retryOrderId = (int) ($this->request->getParam('order_id') ?? 0);
        $pageData = $this->checkoutPageDataService->build($cartCustomerId, $currentStep, $retryOrderId, [
            'checkout_mode' => (string) ($checkoutIdentity['checkout_mode'] ?? ''),
            'is_guest_checkout' => !empty($checkoutIdentity['is_guest_checkout']),
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
        ]);
        $methodData = $this->checkoutPageDataService->buildDynamicMethodData($cartCustomerId, [
            'shipping_address_id' => !empty($checkoutIdentity['is_guest_checkout']) ? 0 : (int) ($pageData['selected_shipping_address_id'] ?? 0),
            'checkout_mode' => (string) ($checkoutIdentity['checkout_mode'] ?? ''),
            'is_guest_checkout' => !empty($checkoutIdentity['is_guest_checkout']),
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
        ]);
        foreach (['selected_shipping_address_id', 'shipping_methods', 'payment_methods', 'cart_summary'] as $methodDataKey) {
            if (array_key_exists($methodDataKey, $methodData)) {
                $pageData[$methodDataKey] = $methodData[$methodDataKey];
            }
        }

        if ($retryOrderId > 0 && empty($pageData['is_retry_payment'])) {
            $this->getMessageManager()->addError(__('该订单已无法重新支付。'));
            $this->redirect('weshop/order/list');
            return null;
        }

        if (($pageData['cart_items'] ?? []) === []) {
            $this->getMessageManager()->addWarning(__('结账内容为空。'));
            $this->redirect('weshop/cart');
            return null;
        }

        foreach ($pageData as $key => $value) {
            $this->assign($key, $value);
        }
        $this->assign('customer', $customer);
        $this->assign('checkout_identity', $checkoutIdentity);
        $this->assign('checkout_mode', (string) ($checkoutIdentity['checkout_mode'] ?? CheckoutIdentityService::MODE_GUEST));
        $this->assign('is_guest_checkout', !empty($checkoutIdentity['is_guest_checkout']));
        $this->assign('customer_checkout_allowed', !empty($checkoutIdentity['customer_allowed']));
        $this->assign('guest_checkout_allowed', !empty($checkoutIdentity['guest_allowed']));

        return $this->fetch(self::CONTENT_TEMPLATE);
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
}
