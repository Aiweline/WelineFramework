<?php

declare(strict_types=1);

namespace Weline\Cart\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Theme\Helper\WidgetI18n;

/**
 * Storefront cart page.
 *
 * Lines hydrate via QueryBin in the browser. The normal layout lifecycle selects
 * captured PHTML sources before the body renders, then wraps it once with chrome.
 */
class Index extends FrontendController
{
    public function index(): string
    {
        // Authoritative lines hydrate via QueryBin in the browser shell.
        // SSR must not call storefrontSummary / QueryBin (empty shell only).
        $cart = $this->emptyStorefrontSummary();

        $this->layoutType = 'cart.default';
        $this->request->setGet('page_type', 'cart');
        $this->request->setGet('layout_type', 'cart');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_page_title', WidgetI18n::label('购物车'));

        $this->assign('page_title', WidgetI18n::label('购物车'));
        $this->assign('title', WidgetI18n::label('购物车'));
        $this->assign('seo', [
            'page_type' => 'cart',
            'title' => WidgetI18n::label('购物车'),
            'description' => WidgetI18n::label('查看已选商品、调整规格数量并进入结算。'),
            'robots' => 'noindex,follow',
        ]);
        $this->assign('cart', $cart);
        $this->assign('items', $cart['items'] ?? []);
        $meta = [
            'showHeader' => true,
            'showFooter' => true,
            'class' => 'weline-cart-page',
            'message' => WidgetI18n::label('您的购物车是空的'),
        ];
        $this->assign('meta', $meta);

        return $this->fetch('Weline_Cart::templates/frontend/cart/index.phtml');
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStorefrontSummary(): array
    {
        return [
            'success' => true,
            'message' => '',
            'items' => [],
            'cart_count' => 0,
            'item_count' => 0,
            'distinct_count' => 0,
            'is_empty' => true,
            'subtotal' => 0.0,
            'grand_total' => 0.0,
            'subtotal_minor' => 0,
            'grand_total_minor' => 0,
            'sibling_carts' => [],
        ];
    }
}
