<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Cart;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Cart\Service\CartIdentityService;
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
            /** @var CartIdentityService $cartIdentityService */
            $cartIdentityService = ObjectManager::getInstance(CartIdentityService::class);
            
            $cartId = (int)($this->request->getParam('cart_id') ?? 0);
            
            if (!$cartId) {
                return $this->fetchJson(['success' => false, 'message' => __('购物车ID不能为空')]);
            }
            
            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            $cartService->removeFromCart($cartId, $cartIdentityService->getCartCustomerId());
            
            return $this->fetchJson(['success' => true, 'message' => __('已从购物车移除')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
