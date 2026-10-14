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
        // Cart baseline grand — no payment incentive. Payable only via MoneyTotalsBuilder.
        $grandBaseline = $this->majorAmount($cart, 'grand_total', 'grand_total_minor');
        if ($grandBaseline <= 0.0) {
            $discount = $this->majorAmount(
                \is_array($cart['discount_preview'] ?? null) ? $cart['discount_preview'] : [],
                'amount',
                'amount_minor'
            );
            $grandBaseline = max(0.0, $subtotal + $shippingAmount - $discount);
        }
        if ($shippingAmount > 0 && $grandBaseline <= $subtotal + 0.0001) {
            $discount = $this->majorAmount(
                \is_array($cart['discount_preview'] ?? null) ? $cart['discount_preview'] : [],
                'amount',
                'amount_minor'
            );
            if ($discount <= 0.0) {
                $grandBaseline = $subtotal + $shippingAmount;
            }
        }

        $ready = $items !== []
            || $shippingHtml !== ''
            || $paymentHtml !== '';

        $payload = [
            'ready' => $ready,
            'items' => $items,
            'currency' => $currency,
            'cart_type' => $mode,
            'selling_mode' => $mode,
            'cart' => array_merge($cart, [
                'subtotal' => $subtotal,
                'grand_total' => $grandBaseline,
                'currency' => $currency,
                'cart_type' => $mode,
                'is_empty' => $items === [],
            ]),
            'shipping_methods' => $shippingMethods,
            'payment_methods' => $paymentMethods,
            'requires_shipping' => array_key_exists('requires_shipping', $data)
                ? (bool)$data['requires_shipping']
                : $this->cartItemsRequireShipping($items),
            'shipping_methods_html' => $shippingHtml,
            'payment_methods_html' => $paymentHtml,
            'items_html' => $itemsHtml,
            'quote_token' => trim((string)($data['quote_token'] ?? '')),
            'tax_estimate' => \is_array($data['tax_estimate'] ?? null) ? $data['tax_estimate'] : null,
        ];

        return $this->finalizeWithMoneyTotals($payload);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function cartItemsRequireShipping(array $items): bool
    {
        if ($items === []) {
            return true;
        }
        foreach ($items as $item) {
            if (\is_array($item) && (bool)($item['requires_shipping'] ?? true)) {
                return true;
            }
        }

        return false;
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
        $mode = $this->sellingMode();
        $requiresShipping = $this->cartItemsRequireShipping($items);
        $shippingMethods = [];
        $shippingHtml = '';
        if ($items !== [] && !$requiresShipping) {
            $shippingMethods = [[
                'code' => 'none',
                'label' => (string)__('无需配送'),
                'title' => (string)__('无需配送'),
                'description' => (string)__('电子交付'),
                'amount' => 0.0,
                'fee' => 0.0,
                'amount_minor' => 0,
                'source' => 'virtual_only',
                'is_free' => true,
                'free_reason' => 'virtual_only',
            ]];
            $shippingHtml = $html->renderMethodOptions(
                $shippingMethods,
                'shipping_method',
                $currency,
                '',
                true,
                '',
                '',
            );
        }

        $payload = [
            'ready' => $items !== [] || $paymentHtml !== '',
            'items' => $items,
            'currency' => $currency,
            'cart_type' => $mode,
            'selling_mode' => $mode,
            'cart' => array_merge($cart, [
                'cart_type' => $mode,
                'grand_total' => $grand,
                'currency' => $currency,
            ]),
            'shipping_methods' => $shippingMethods,
            'payment_methods' => $methods,
            'requires_shipping' => $requiresShipping,
            'shipping_methods_html' => $shippingHtml,
            'payment_methods_html' => $paymentHtml,
            'items_html' => $itemsHtml,
            'quote_token' => '',
            'tax_estimate' => null,
        ];

        return $this->finalizeWithMoneyTotals($payload);
    }

    /**
     * Same channel as getData: Builder → money_summary → optional Policy chrome strip.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function finalizeWithMoneyTotals(array $payload): array
    {
        $builder = new StorefrontMoneyTotalsBuilder();
        $money = $builder->build([
            'currency' => (string)($payload['currency'] ?? 'CNY'),
            'cart' => \is_array($payload['cart'] ?? null) ? $payload['cart'] : [],
            'shipping_methods' => \is_array($payload['shipping_methods'] ?? null) ? $payload['shipping_methods'] : [],
            'payment_methods' => \is_array($payload['payment_methods'] ?? null) ? $payload['payment_methods'] : [],
            'tax_estimate' => \is_array($payload['tax_estimate'] ?? null) ? $payload['tax_estimate'] : null,
            'cart_type' => (string)($payload['cart_type'] ?? 'toc'),
            'selling_mode' => (string)($payload['selling_mode'] ?? 'toc'),
            // SSR default = first method (HtmlRenderer selected_index=0).
            'selected_payment_method' => '',
            'selected_shipping_method' => '',
        ]);
        $payload = $builder->mergeIntoPayload($payload, $money);

        return $this->applyMoneySummaryPolicy($payload);
    }

    /**
     * Optional commerce policy chrome (B2B strip badges). Totals already via Builder+adjustDto.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyMoneySummaryPolicy(array $payload): array
    {
        try {
            $policy = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Checkout\Api\StorefrontMoneySummaryPolicyInterface::class
            );
            if ($policy instanceof \Weline\Checkout\Api\StorefrontMoneySummaryPolicyInterface) {
                return $policy->adjustSsrPayload($payload);
            }
        } catch (\Throwable) {
            // B2B optional — retail SSR continues.
        }

        return $payload;
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
            'requires_shipping' => true,
            'shipping_methods_html' => '',
            'payment_methods_html' => '',
            'items_html' => '',
            'goods_text' => '',
            'shipping_text' => '',
            'payable_text' => '',
            'incentive_text' => '',
            'incentive_minor' => 0,
            'cod_fee_text' => '',
            'cod_fee_minor' => 0,
            'quote_token' => '',
            'tax_estimate' => null,
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
        if (\class_exists(\Weline\Currency\Helper\CurrencySymbol::class)) {
            return \Weline\Currency\Helper\CurrencySymbol::formatAmount($amount, $currency);
        }
        $code = \strtoupper(\trim($currency)) ?: 'CNY';
        $glyphs = ['CNY' => '¥', 'RMB' => '¥', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥'];
        $symbol = $glyphs[$code] ?? $code;
        $formatted = \number_format($amount, 2, '.', ',');

        return $symbol === $code ? ($code . ' ' . $formatted) : ($symbol . $formatted);
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
