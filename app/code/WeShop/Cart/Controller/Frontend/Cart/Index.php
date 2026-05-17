<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Cart;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Cart\Service\CartPageDataService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 璐墿杞﹂〉闈㈡帶鍒跺櫒
 */
class Index extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Cart::templates/frontend/cart/index.phtml';

    protected ?string $layoutType = 'cart';

    public function __construct(
        private readonly CartIdentityService|CustomerSession $cartIdentityService,
        private readonly CartPageDataService $cartPageDataService
    ) {
    }

    public function index(): string
    {
        $cartIdentityService = $this->getCartIdentityService();
        $customer = $cartIdentityService->getCustomer();
        $cartCustomerId = $cartIdentityService->getCartCustomerId();

        foreach ($this->cartPageDataService->build($cartCustomerId) as $key => $value) {
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
