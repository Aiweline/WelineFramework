<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;
class Index extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Checkout::templates/frontend/checkout/index.phtml';

    protected ?string $layoutType = 'checkout';

    public function __construct(
        private readonly CartIdentityService|CustomerSession $cartIdentityService,
        private readonly CheckoutPageDataService $checkoutPageDataService
    ) {
    }

    public function index()
    {
        $cartIdentityService = $this->getCartIdentityService();
        $customer = $cartIdentityService->getCustomer();
        $cartCustomerId = $cartIdentityService->getCartCustomerId();

        $currentStep = max(1, min(3, (int) ($this->request->getParam('step') ?? 1)));
        $retryOrderId = (int) ($this->request->getParam('order_id') ?? 0);
        $pageData = $this->checkoutPageDataService->build($cartCustomerId, $currentStep, $retryOrderId);

        if ($retryOrderId > 0 && empty($pageData['is_retry_payment'])) {
            $this->getMessageManager()->addError(__('This order can no longer be retried.'));
            $this->redirect('weshop/order/list');
            return null;
        }

        if (($pageData['cart_items'] ?? []) === []) {
            $this->getMessageManager()->addWarning(__('The checkout is empty.'));
            $this->redirect('weshop/cart');
            return null;
        }

        foreach ($pageData as $key => $value) {
            $this->assign($key, $value);
        }
        $this->assign('customer', $customer);
        $this->assign('is_guest_checkout', $cartIdentityService->isGuest());

        return $this->fetch(self::CONTENT_TEMPLATE);
    }

    private function getCartIdentityService(): CartIdentityService
    {
        if ($this->cartIdentityService instanceof CartIdentityService) {
            return $this->cartIdentityService;
        }

        return ObjectManager::getInstance(CartIdentityService::class);
    }
}
