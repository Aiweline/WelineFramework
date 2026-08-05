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

    private function money(string $currency, float $amount): string
    {
        return $currency . ' ' . number_format($amount, 2, '.', '');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
