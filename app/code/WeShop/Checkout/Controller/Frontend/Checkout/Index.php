<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
class Index extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Checkout::templates/frontend/checkout/index.phtml';

    protected ?string $layoutType = 'checkout';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CheckoutPageDataService $checkoutPageDataService
    ) {
    }

    public function index()
    {
        $customer = $this->customerSession->getCustomer();
        if (!$customer) {
            $this->redirect($this->getStorefrontLoginRoute());
            return;
        }

        $currentStep = max(1, min(3, (int) ($this->request->getParam('step') ?? 1)));
        $retryOrderId = (int) ($this->request->getParam('order_id') ?? 0);
        $pageData = $this->checkoutPageDataService->build((int) $customer->getId(), $currentStep, $retryOrderId);

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

        return $this->fetch(self::CONTENT_TEMPLATE);
    }
}
