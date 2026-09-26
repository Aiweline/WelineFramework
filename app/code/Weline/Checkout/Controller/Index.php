<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\StorefrontSsrChromeHealer;

/**
 * Storefront checkout page.
 *
 * HARD: keep Theme Partials header/footer chrome (theme_seat_integrity).
 * Bare HTML shells that omit chrome are rejected as a performance "fix".
 *
 * P0 reachability: SSR is a client shell only — no currentCart/getCart/summary,
 * template()/fetchHtml skips LayoutSlotRenderer entity fill. Address / shipping /
 * payment hydrate via QueryBin after first paint.
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

        // Empty SSR payload — browser QueryBin is the source of truth.
        $cart = $this->emptyCurrentCart();
        $this->assign('checkout_items', $cart['items']);
        $this->assign('checkout_currency', $cart['currency']);
        $this->assign('checkout_items_empty_message', __($emptyCartMessage));
        $this->assign(
            'checkout_page_subtitle',
            (string)__($checkoutSubtitle)
        );

        $meta = [
            'showHeader' => true,
            'showFooter' => true,
            'class' => 'weline-checkout-page',
        ];

        $body = $this->template('Weline_Checkout::frontend/checkout/index.phtml');
        $meta['content'] = $body;
        $this->assign('meta', $meta);
        $this->assign('content', $body);

        $html = $this->template('Weline_Checkout::theme/frontend/layouts/checkout/default.phtml');

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
     * @return array{items:list<array<string,mixed>>,currency:string,is_empty:bool,item_count:int,subtotal:float,grand_total:float,discount_preview:?array}
     */
    private function emptyCurrentCart(): array
    {
        return [
            'items' => [],
            'currency' => 'USD',
            'is_empty' => true,
            'item_count' => 0,
            'subtotal' => 0.0,
            'grand_total' => 0.0,
            'discount_preview' => null,
        ];
    }
}
