<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Cart;

use WeShop\Cart\Service\CartPageDataService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;

/**
 * 璐墿杞﹂〉闈㈡帶鍒跺櫒
 */
class Index extends BaseController
{
    protected ?string $layoutType = 'cart';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CartPageDataService $cartPageDataService
    ) {
    }

    public function index(): string
    {
        $customer = $this->customerSession->getCustomer();
        if (!$customer || !$customer->getId()) {
            $this->redirect('customer/account/login');

            return '';
        }

        foreach ($this->cartPageDataService->build((int) $customer->getId()) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}
