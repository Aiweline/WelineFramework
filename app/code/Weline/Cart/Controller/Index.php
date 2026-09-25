<?php

declare(strict_types=1);

namespace Weline\Cart\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\WidgetI18n;
use Weline\Theme\Service\StorefrontSsrChromeHealer;

/**
 * Storefront cart page.
 *
 * Lines hydrate via QueryBin in the browser. SSR must stay cheap: heavy
 * LayoutSlotRenderer fill historically spent ~30s, starved the 2-worker pool,
 * and surfaced as nginx 502 + RequestExit fiber cancel.
 *
 * HARD: keep Theme Partials header/footer chrome (theme_seat_integrity).
 * Bare HTML shells that omit chrome are rejected as a performance "fix".
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

        // P0: template()/fetchHtml — skips fetch_file_after LayoutSlotRenderer
        // entity fill (after_ms≈30s / worker starvation / nginx 502 + RequestExit).
        // Theme Partials header/footer stay; do not tear chrome.
        // Disk-splice footer-container into empty weline-footer--shell (theme_seat_integrity).
        $body = $this->template('Weline_Cart::templates/frontend/cart/index.phtml');
        $meta['content'] = $body;
        $this->assign('meta', $meta);
        $this->assign('content', $body);

        $html = $this->template('Weline_Cart::theme/frontend/layouts/cart/default.phtml');

        return $this->ensurePublishedChrome($html);
    }

    private function ensurePublishedChrome(string $html): string
    {
        try {
            /** @var StorefrontSsrChromeHealer $healer */
            $healer = ObjectManager::getInstance(StorefrontSsrChromeHealer::class);

            return $healer->ensure($html);
        } catch (\Throwable) {
            return $html;
        }
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
