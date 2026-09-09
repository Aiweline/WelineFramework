<?php

declare(strict_types=1);

namespace Weline\Cart\Controller;

use Weline\Cart\Service\CartService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class Index extends FrontendController
{
    public function index(): string
    {
        $cart = $this->cartService()->storefrontSummary();

        // The authoritative storefront cart is hydrated through QueryBin. The
        // HTML request can carry a different WLS session, so it must not select
        // an empty-only layout before the browser has read Cart.
        $this->layoutType = 'cart.default';
        $this->request->setGet('page_type', 'cart');
        $this->request->setGet('layout_type', 'cart');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_public_route', 'cart');
        $this->request->setGet('theme_page_title', (string)__('购物车'));

        $this->assign('page_title', __('购物车'));
        $this->assign('title', __('购物车'));
        $this->assign('seo', [
            'page_type' => 'cart',
            'title' => (string)__('购物车'),
            'description' => (string)__('查看已选汉服商品、调整规格数量并进入结算。'),
            'robots' => 'noindex,follow',
        ]);
        $this->assign('cart', $cart);
        $this->assign('items', $cart['items'] ?? []);
        $this->assign('meta', [
            'showHeader' => true,
            'showFooter' => true,
            'class' => 'weline-cart-page',
            'message' => __('您的购物车是空的'),
        ]);

        return (string)$this->fetch('Weline_Cart::templates/frontend/cart/index.phtml');
    }

    private function cartService(): CartService
    {
        return ObjectManager::getInstance(CartService::class);
    }
}
