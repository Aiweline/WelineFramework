<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Checkout\Service\CheckoutStorefrontSsrService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * Storefront checkout page.
 *
 * HARD: keep Theme Partials header/footer chrome (theme_seat_integrity).
 * First paint SSR: items / shipping / payment / money summary via CheckoutStorefrontSsrService.
 * Async getData only after shopper changes address / methods / cart.
 */
class Index extends FrontendController
{
    public function index(): string
    {
        // 默认允许匿名结账：未登录也直接渲染结账页，身份由 CheckoutIdentityService 处理。
        // WLS：先 prefetch 再 __()，避免模板预取前把中文 identity miss 写进 Worker/请求缓存。
        $checkoutTitle = '结账';
        $checkoutSubtitle = '确认收货地址、配送方式和支付信息后即可提交订单。';
        $emptyCartMessage = '购物车为空，请先加入商品。';
        \Weline\Framework\Phrase\Parser::prefetchWords([$checkoutTitle, $checkoutSubtitle, $emptyCartMessage]);
        $this->request->setGet('theme_page_title', (string)__($checkoutTitle));
        $this->assign('page_title', __($checkoutTitle));
        $this->assign('title', __($checkoutTitle));
        $this->layoutType = 'checkout';
        $this->request->setGet('page_type', 'checkout');
        $this->request->setGet('layout_type', 'checkout');
        $this->request->setGet('layout_option', 'default');

        /** @var CheckoutStorefrontSsrService $ssr */
        $ssr = ObjectManager::getInstance(CheckoutStorefrontSsrService::class);
        $payload = $ssr->build();
        $this->assignCheckoutSsr($payload, (string)__($emptyCartMessage));
        $this->assign(
            'checkout_page_subtitle',
            (string)__($checkoutSubtitle)
        );

        $meta = [
            'showHeader' => true,
            'showFooter' => true,
            'class' => 'weline-checkout-page',
        ];

        $this->assign('meta', $meta);
        return $this->fetch('Weline_Checkout::frontend/checkout/index.phtml');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assignCheckoutSsr(array $payload, string $emptyCartMessage): void
    {
        $this->assign('checkout_items', \is_array($payload['items'] ?? null) ? $payload['items'] : []);
        $this->assign('checkout_currency', (string)($payload['currency'] ?? 'USD'));
        $this->assign('checkout_items_empty_message', $emptyCartMessage);
        $this->assign('checkout_shipping_methods_html', (string)($payload['shipping_methods_html'] ?? ''));
        $this->assign('checkout_payment_methods_html', (string)($payload['payment_methods_html'] ?? ''));
        $this->assign('checkout_ssr_ready', !empty($payload['ready']));
        $this->assign('checkout_ssr_quote_token', (string)($payload['quote_token'] ?? ''));
        $this->assign('checkout_ssr_goods_text', (string)($payload['goods_text'] ?? ''));
        $this->assign('checkout_ssr_shipping_text', (string)($payload['shipping_text'] ?? ''));
        $this->assign('checkout_ssr_payable_text', (string)($payload['payable_text'] ?? ''));
        $cart = \is_array($payload['cart'] ?? null) ? $payload['cart'] : [];
        $this->assign('checkout_ssr_cart', $cart);
        $this->assign(
            'checkout_ssr_tax_estimate',
            \is_array($payload['tax_estimate'] ?? null) ? $payload['tax_estimate'] : null
        );
    }
}
