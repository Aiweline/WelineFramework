<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class Index extends FrontendController
{
    public function index(): string
    {
        // 默认允许匿名结账：未登录也直接渲染结账页，身份由 CheckoutIdentityService 处理。
        $this->assign('page_title', __('结账'));
        $this->layoutType = 'checkout';

        /** @var \Weline\Checkout\Service\CheckoutPageViewModel $viewModel */
        $viewModel = ObjectManager::getInstance(\Weline\Checkout\Service\CheckoutPageViewModel::class);
        $cart = $viewModel->currentCart();
        $this->assign('checkout_items', $cart['items']);
        $this->assign('checkout_currency', $cart['currency']);
        $this->assign('checkout_items_empty_message', __('购物车为空，请先加入商品。'));

        return $this->fetch('Weline_Checkout::frontend/checkout/index.phtml');
    }
}
