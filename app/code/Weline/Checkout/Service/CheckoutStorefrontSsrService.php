<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Cart\Service\CartService;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Runtime\RequestContext;

/**
 * Traditional checkout first paint: server-render items / shipping / payment / totals.
 * Async getData only refreshes after the shopper changes address / methods / cart.
 */
final class CheckoutStorefrontSsrService
{
    public function __construct(
        private readonly CheckoutPageViewModel $pageViewModel,
    ) {
    }

    /**
     * @return array{
     *   ready: bool,
     *   items: list<array<string,mixed>>,
     *   currency: string,
     *   cart: array<string,mixed>,
     *   shipping_methods: list<array<string,mixed>>,
     *   payment_methods: list<array<string,mixed>>,
     *   shipping_methods_html: string,
     *   payment_methods_html: string,
     *   items_html: string,
     *   goods_text: string,
     *   shipping_text: string,
     *   payable_text: string,
     *   quote_token: string
     * }
     */
    public function build(): array
    {
        $empty = $this->emptyPayload();
        if (!\function_exists('w_query')) {
            return $empty;
        }

        $guestToken = trim((string)Cookie::get(CartService::GUEST_TOKEN_COOKIE));
        $mode = $this->sellingMode();
        $params = [
            'shipping_address' => [],
            'guest_token' => $guestToken,
            'cart_type' => $mode,
            'selling_mode' => $mode,
        ];

        try {
            $result = w_query('checkout', 'getData', $params);
        } catch (\Throwable) {
            // Fall back to cart-only SSR so items still paint when quote fails.
            return $this->fromCartOnly();
        }

        if (!\is_array($result) || empty($result['success'])) {
            return $this->fromCartOnly();
        }
        $data = \is_array($result['data'] ?? null) ? $result['data'] : $result;
        if (!\is_array($data)) {
            return $this->fromCartOnly();
        }

        $items = \is_array($data['items'] ?? null) ? $data['items'] : [];
        $currency = strtoupper(trim((string)($data['currency'] ?? 'CNY')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'CNY';
        }
        $cart = \is_array($data['cart'] ?? null) ? $data['cart'] : [];
        $shippingHtml = trim((string)($data['shipping_methods_html'] ?? ''));
        $paymentHtml = trim((string)($data['payment_methods_html'] ?? ''));
        $itemsHtml = trim((string)($data['items_html'] ?? ''));
        $shippingMethods = \is_array($data['shipping_methods'] ?? null) ? $data['shipping_methods'] : [];
        $paymentMethods = \is_array($data['payment_methods'] ?? null) ? $data['payment_methods'] : [];
        $shippingAmount = 0.0;
        if ($shippingMethods !== [] && \is_array($shippingMethods[0] ?? null)) {
            $shippingAmount = (float)($shippingMethods[0]['amount'] ?? $shippingMethods[0]['fee'] ?? 0);
        }
        $subtotal = $this->majorAmount($cart, 'subtotal', 'subtotal_minor');
        if ($subtotal <= 0.0) {
            $preview = \is_array($cart['discount_preview'] ?? null) ? $cart['discount_preview'] : [];
            $subtotal = $this->majorAmount($preview, 'subtotal', 'subtotal_minor');
        }
        $discount = $this->majorAmount(
            \is_array($cart['discount_preview'] ?? null) ? $cart['discount_preview'] : [],
            'amount',
            'amount_minor'
        );
        // SSR HTML 默认勾选首项支付；跳过 getData 时 JS 仍须用同套扁字段扣激励/COD。
        $paymentDelta = $this->defaultPaymentMoneyDelta($paymentMethods);
        $grand = $this->majorAmount($cart, 'grand_total', 'grand_total_minor');
        if ($grand <= 0.0) {
            $grand = max(0.0, $subtotal + $shippingAmount - $discount);
        }
        if ($shippingAmount > 0 && $grand <= $subtotal + 0.0001 && $discount <= 0.0) {
            $grand = $subtotal + $shippingAmount;
        }
        $grand = max(0.0, $grand - $paymentDelta['incentive_major'] + $paymentDelta['cod_major']);

        $ready = $items !== []
            || $shippingHtml !== ''
            || $paymentHtml !== '';

        return [
            'ready' => $ready,
            'items' => $items,
            'currency' => $currency,
            // array_merge：后写覆盖 — cart 自带 grand_total 不得盖掉已扣激励的应付。
            'cart' => array_merge($cart, [
                'subtotal' => $subtotal,
                'grand_total' => $grand,
                'currency' => $currency,
                'is_empty' => $items === [],
            ]),
            'shipping_methods' => $shippingMethods,
            'payment_methods' => $paymentMethods,
            'shipping_methods_html' => $shippingHtml,
            'payment_methods_html' => $paymentHtml,
            'items_html' => $itemsHtml,
            'goods_text' => $this->money($currency, $subtotal),
            'shipping_text' => $this->money($currency, $shippingAmount),
            'payable_text' => $this->money($currency, $grand),
            'quote_token' => trim((string)($data['quote_token'] ?? '')),
            'tax_estimate' => \is_array($data['tax_estimate'] ?? null) ? $data['tax_estimate'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromCartOnly(): array
    {
        $empty = $this->emptyPayload();
        try {
            $cart = $this->pageViewModel->currentCart();
        } catch (\Throwable) {
            return $empty;
        }
        $items = \is_array($cart['items'] ?? null) ? $cart['items'] : [];
        $currency = strtoupper(trim((string)($cart['currency'] ?? 'CNY')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'CNY';
        }
        $subtotal = $this->majorAmount($cart, 'subtotal', 'subtotal_minor');
        $grand = $this->majorAmount($cart, 'grand_total', 'grand_total_minor');
        if ($grand <= 0.0) {
            $grand = $subtotal;
        }
        /** @var CheckoutHtmlRenderer $html */
        $html = \Weline\Framework\Manager\ObjectManager::getInstance(CheckoutHtmlRenderer::class);
        $emptyMsg = (string)__('购物车为空，请先加入商品。');
        $itemsHtml = $html->renderItems($items, $currency, $emptyMsg);
        $payEmpty = $items === []
            ? (string)__('请先加入商品后再选择支付方式。')
            : (string)__('暂无可用支付方式。');
        $paymentHtml = '';
        $methods = [];
        try {
            /** @var CheckoutPaymentMethodsProvider $payments */
            $payments = \Weline\Framework\Manager\ObjectManager::getInstance(CheckoutPaymentMethodsProvider::class);
            $methods = $payments->listMethods([
                'currency' => $currency,
                'amount' => $grand > 0 ? $grand : $subtotal,
            ]);
            $paymentHtml = $html->renderPaymentMethodOptions($methods, 'payment_method', $payEmpty);
        } catch (\Throwable) {
            $paymentHtml = '';
            $methods = [];
        }
        $paymentDelta = $this->defaultPaymentMoneyDelta($methods);
        $grand = max(0.0, $grand - $paymentDelta['incentive_major'] + $paymentDelta['cod_major']);

        return [
            'ready' => $items !== [] || $paymentHtml !== '',
            'items' => $items,
            'currency' => $currency,
            'cart' => $cart,
            'shipping_methods' => [],
            'payment_methods' => $methods,
            'shipping_methods_html' => '',
            'payment_methods_html' => $paymentHtml,
            'items_html' => $itemsHtml,
            'goods_text' => $this->money($currency, $subtotal),
            'shipping_text' => $this->money($currency, 0),
            'payable_text' => $this->money($currency, $grand),
            'quote_token' => '',
            'tax_estimate' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return [
            'ready' => false,
            'items' => [],
            'currency' => 'USD',
            'cart' => [
                'items' => [],
                'currency' => 'USD',
                'is_empty' => true,
                'subtotal' => 0.0,
                'grand_total' => 0.0,
            ],
            'shipping_methods' => [],
            'payment_methods' => [],
            'shipping_methods_html' => '',
            'payment_methods_html' => '',
            'items_html' => '',
            'goods_text' => '',
            'shipping_text' => '',
            'payable_text' => '',
            'quote_token' => '',
            'tax_estimate' => null,
        ];
    }

    /**
     * 与 HtmlRenderer 默认 selected_index=0 对齐：首项激励/COD 进入 SSR 应付与 boot 扁字段。
     *
     * @param list<array<string,mixed>> $paymentMethods
     * @return array{incentive_major:float,cod_major:float}
     */
    private function defaultPaymentMoneyDelta(array $paymentMethods): array
    {
        $empty = ['incentive_major' => 0.0, 'cod_major' => 0.0];
        if ($paymentMethods === [] || !\is_array($paymentMethods[0] ?? null)) {
            return $empty;
        }
        $method = $paymentMethods[0];
        $incentive = 0.0;
        if (!empty($method['incentive_available'])) {
            $incentive = max(0, (int)($method['incentive_savings_minor'] ?? 0)) / 100.0;
        }
        $cod = max(0, (int)($method['cod_fee_amount_minor'] ?? 0)) / 100.0;

        return [
            'incentive_major' => $incentive,
            'cod_major' => $cod,
        ];
    }

    private function sellingMode(): string
    {
        $websiteId = (int)RequestContext::getWelineWebsiteId();
        if ($websiteId > 0) {
            $scoped = strtolower(trim((string)Cookie::get('weline_selling_mode_w' . $websiteId)));
            if ($scoped === 'toc' || $scoped === 'tob') {
                return $scoped;
            }
        }
        $cookie = strtolower(trim((string)Cookie::get('weline_selling_mode')));
        if ($cookie === 'toc' || $cookie === 'tob') {
            return $cookie;
        }

        return 'toc';
    }

    private function money(string $currency, float $amount): string
    {
        return $currency . ' ' . number_format($amount, 2, '.', '');
    }

    /**
     * Prefer positive major; otherwise fall back to *_minor / 100.
     *
     * @param array<string, mixed> $row
     */
    private function majorAmount(array $row, string $majorKey, string $minorKey): float
    {
        if (\array_key_exists($majorKey, $row) && is_numeric($row[$majorKey])) {
            $major = (float)$row[$majorKey];
            if ($major > 0.0) {
                return $major;
            }
        }
        if (\array_key_exists($minorKey, $row) && is_numeric($row[$minorKey])) {
            return ((int)$row[$minorKey]) / 100.0;
        }
        if (\array_key_exists($majorKey, $row) && is_numeric($row[$majorKey])) {
            return (float)$row[$majorKey];
        }

        return 0.0;
    }
}
