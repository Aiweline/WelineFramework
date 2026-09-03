<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

/**
 * Server-side checkout HTML fragments（P2E-003）.
 * Product/option DOM is generated here; browser JS must not createElement for items.
 */
final class CheckoutHtmlRenderer
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function renderItems(array $items, string $currency = 'CNY', string $emptyMessage = ''): string
    {
        if ($items === []) {
            $msg = $emptyMessage !== '' ? $emptyMessage : (string)__('购物车为空，请先加入商品。');
            return '<p class="weline-checkout__empty">' . $this->e($msg) . '</p>';
        }
        $html = '';
        foreach ($items as $item) {
            $name = (string)($item['name'] ?? $item['product_name'] ?? '');
            $qty = (float)($item['qty'] ?? $item['quantity'] ?? 1);
            $row = (float)($item['row_total'] ?? ((float)($item['price'] ?? 0) * $qty));
            $html .= '<div class="weline-checkout__item" data-checkout-item>'
                . '<div><strong>' . $this->e($name) . '</strong>'
                . '<small>x' . $this->e((string)$qty) . '</small></div>'
                . '<span>' . $this->e($this->money($currency, $row)) . '</span>'
                . '</div>';
        }
        return $html;
    }

    /**
     * @param list<array<string, mixed>> $methods
     */
    public function renderMethodOptions(
        array $methods,
        string $inputName,
        string $currency = 'CNY',
        string $emptyMessage = '',
        bool $showPrice = false,
    ): string {
        if ($methods === []) {
            return '<p class="weline-checkout__empty">' . $this->e($emptyMessage) . '</p>';
        }
        $html = '';
        foreach ($methods as $index => $method) {
            $code = (string)($method['code'] ?? '');
            $label = (string)($method['label'] ?? $method['title'] ?? $code);
            $desc = (string)($method['description'] ?? $method['eta_label'] ?? $method['source'] ?? '');
            $amount = (float)($method['amount'] ?? $method['fee'] ?? 0);
            $checked = $index === 0 ? ' checked' : '';
            $priceHtml = $showPrice ? '<span>' . $this->e($this->money($currency, $amount)) . '</span>' : '<span></span>';
            $html .= '<label class="weline-checkout__option">'
                . '<input type="radio" name="' . $this->e($inputName) . '" value="' . $this->e($code) . '"' . $checked . '>'
                . '<span><strong>' . $this->e($label) . '</strong>'
                . ($desc !== '' ? '<small>' . $this->e($desc) . '</small>' : '')
                . '</span>'
                . $priceHtml
                . '</label>';
        }
        return $html;
    }

    /**
     * Payment method cards: logo + expandable intro + provider guide details link.
     *
     * @param list<array<string, mixed>> $methods
     */
    public function renderPaymentMethodOptions(
        array $methods,
        string $inputName = 'payment_method',
        string $emptyMessage = '',
    ): string {
        if ($methods === []) {
            return '<p class="weline-checkout__empty">' . $this->e($emptyMessage) . '</p>';
        }

        $expandLabel = (string)__('展开简介');
        $collapseLabel = (string)__('收起');
        $detailsLabel = (string)__('查看详情');
        $html = '';

        foreach ($methods as $index => $method) {
            $code = (string)($method['code'] ?? '');
            $label = (string)($method['label'] ?? $method['title'] ?? $code);
            $desc = trim((string)($method['description'] ?? ''));
            $iconUrl = trim((string)($method['icon_url'] ?? ''));
            $guideUrl = trim((string)($method['guide_url'] ?? ''));
            $hasGuide = array_key_exists('has_guide', $method)
                ? (bool)$method['has_guide']
                : ($guideUrl !== '');
            $checked = $index === 0 ? ' checked' : '';

            if ($guideUrl !== '' && !str_starts_with($guideUrl, '/') && !preg_match('#^https?://#i', $guideUrl)) {
                $guideUrl = '/' . ltrim($guideUrl, '/');
            }

            $logoHtml = $iconUrl !== ''
                ? '<img class="weline-checkout__payment-logo" src="' . $this->e($iconUrl) . '" alt="'
                    . $this->e($label) . '" loading="lazy" decoding="async" width="40" height="28">'
                : '<span class="weline-checkout__payment-logo weline-checkout__payment-logo--empty" aria-hidden="true"></span>';

            $detailsHtml = ($hasGuide && $guideUrl !== '')
                ? '<a class="weline-checkout__payment-details" href="' . $this->e($guideUrl)
                    . '" target="_blank" rel="noopener noreferrer" data-payment-details>'
                    . $this->e($detailsLabel) . '</a>'
                : '';

            $introHtml = '';
            if ($desc !== '') {
                $introHtml = '<span class="weline-checkout__payment-intro" data-payment-intro>'
                    . '<small class="weline-checkout__payment-intro-text">' . $this->e($desc) . '</small>'
                    . '<button type="button" class="weline-checkout__payment-intro-toggle"'
                    . ' data-payment-intro-toggle'
                    . ' data-label-expand="' . $this->e($expandLabel) . '"'
                    . ' data-label-collapse="' . $this->e($collapseLabel) . '"'
                    . ' hidden>' . $this->e($expandLabel) . '</button>'
                    . '</span>';
            }

            $html .= '<label class="weline-checkout__option weline-checkout__option--payment"'
                . ' data-payment-method="' . $this->e($code) . '">'
                . '<input type="radio" name="' . $this->e($inputName) . '" value="' . $this->e($code) . '"' . $checked . '>'
                . $logoHtml
                . '<span class="weline-checkout__payment-body">'
                . '<span class="weline-checkout__payment-title-row">'
                . '<strong>' . $this->e($label) . '</strong>'
                . $detailsHtml
                . '</span>'
                . $introHtml
                . '</span>'
                . '</label>';
        }

        return $html;
    }

    private function money(string $currency, float $amount): string
    {
        return $currency . ' ' . number_format($amount, 2, '.', '');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
