<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;

class Index extends BaseController
{
    protected ?string $layoutType = 'checkout';

    public function index()
    {
        $customer = $this->getCustomerSession()->getCustomer();
        if (!$customer) {
            $this->redirect('customer/account/login');
            return;
        }

        $currentStep = max(1, min(3, (int) ($this->request->getParam('step') ?? 1)));
        $pageData = $this->getCheckoutPageDataService()->build((int) $customer->getId(), $currentStep);

        if (($pageData['cart_items'] ?? []) === []) {
            $this->getMessageManager()->addWarning(__('购物车为空，无法结账'));
            return $this->redirect('weshop/cart');
        }

        foreach ($pageData as $key => $value) {
            $this->assign($key, $value);
        }
        $this->assign('customer', $customer);

        return $this->fetch();
    }

    protected function getCustomerSession(): CustomerSession
    {
        return ObjectManager::getInstance(CustomerSession::class);
    }

    protected function getCheckoutPageDataService(): CheckoutPageDataService
    {
        return ObjectManager::getInstance(CheckoutPageDataService::class);
    }
}
