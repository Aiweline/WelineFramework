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
        $cart = $this->cartService()->summary();
        $isEmpty = (bool)($cart['is_empty'] ?? true);

        $this->layoutType = $isEmpty ? 'cart.empty' : 'cart.default';
        $this->request->setGet('page_type', 'cart');
        $this->request->setGet('layout_type', 'cart');
        $this->request->setGet('layout_option', $isEmpty ? 'empty' : 'default');
        $this->request->setGet('theme_public_route', 'cart');
        $this->request->setGet('theme_page_title', (string)__('购物车'));

        $this->assign('title', __('购物车'));
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

