<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Cart;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Cart\Service\CartService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 从购物车移除控制器
 */
class Remove extends FrontendController
{
    /**
     * 从购物车移除
     */
    public function index(): string
    {
        try {
            /** @var CustomerSession $customerSession */
            $customerSession = ObjectManager::getInstance(CustomerSession::class);
            $customer = $customerSession->getCustomer();
            
            $cartId = (int)($this->request->getParam('cart_id') ?? 0);
            
            if (!$cartId) {
                return $this->fetchJson(['success' => false, 'message' => __('购物车ID不能为空')]);
            }
            
            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            $cartService->removeFromCart($cartId, $customer ? $customer->getId() : 0);
            
            return $this->fetchJson(['success' => true, 'message' => __('已从购物车移除')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
