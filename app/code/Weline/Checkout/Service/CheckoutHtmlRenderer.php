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
            $unitMinor = max(0, (int)($item['unit_price_minor'] ?? (int)round(((float)($item['price'] ?? 0)) * 100)));
            $compareAtMinor = max(0, (int)($item['compare_at_minor'] ?? 0));
            $original = (float)($item['original_price'] ?? ($compareAtMinor > 0 ? $compareAtMinor / 100 : 0));
            $hasDeal = !empty($item['has_deal'])
                || ($compareAtMinor > $unitMinor && $unitMinor > 0)
                || ($original > (float)($item['price'] ?? 0) && (float)($item['price'] ?? 0) > 0);
            $campaignLabel = trim((string)($item['campaign_label'] ?? ''));
            $campaignUrl = trim((string)($item['campaign_url'] ?? ''));
            $sku = trim((string)($item['sku'] ?? ''));
            $metaHtml = '';
            if ($sku !== '') {
                $metaHtml .= '<small class="weline-checkout__item-sku">SKU: ' . $this->e($sku) . '</small>';
            }
            $metaHtml .= $this->renderOptionsHtml($item);
            $image = $this->resolveItemImage($item);
            $thumbHtml = $image['src'] !== ''
                ? '<img class="weline-checkout__item-thumb" src="' . $this->e($image['src']) . '" alt=""'
                    . ' data-storefront-img="1"'
                    . ' data-fallback="' . $this->e($image['fallback'] !== '' ? $image['fallback'] : $image['src']) . '"'
                    . ' loading="lazy" decoding="async" width="64" height="64">'
                : '<span class="weline-checkout__item-thumb weline-checkout__item-thumb--empty" aria-hidden="true"></span>';
            $priceHtml = '<span class="weline-checkout__item-price">'
                . '<span class="weline-checkout__item-price-row">'
                . '<span class="weline-checkout__item-price-now">' . $this->e($this->money($currency, $row)) . '</span>';
            if ($hasDeal && $original > 0) {
                $priceHtml .= '<span class="weline-checkout__item-price-was">'
                    . $this->e($this->money($currency, $original * max(1.0, $qty)))
                    . '</span>';
            }
            $priceHtml .= '</span>';
            if ($hasDeal && $original > 0 && $campaignLabel !== '') {
                if ($campaignUrl !== '') {
                    $priceHtml .= '<a class="weline-checkout__item-price-campaign" href="'
                        . $this->e($campaignUrl) . '">' . $this->e($campaignLabel) . '</a>';
                } else {
                    $priceHtml .= '<span class="weline-checkout__item-price-campaign">'
                        . $this->e($campaignLabel) . '</span>';
                }
            }
            $priceHtml .= '</span>';
            // Price floats top-right inside main so the title can use the
            // full summary column width (wrapping around the price), instead
            // of being crushed by a competing flex/grid track.
            $html .= '<div class="weline-checkout__item" data-checkout-item>'
                . $thumbHtml
                . '<div class="weline-checkout__item-main">'
                . $priceHtml
                . '<strong class="weline-checkout__item-title">' . $this->e($name) . '</strong>'
                . $metaHtml
                . '<small class="weline-checkout__item-qty">x' . $this->e((string)$qty) . '</small>'
                . '</div>'
                . '</div>';
        }
        return $html;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{src:string,fallback:string}
     */
    private function resolveItemImage(array $item): array
    {
        $imageSrc = trim((string)($item['image_src'] ?? $item['image'] ?? ''));
        $imageFallback = trim((string)($item['image_fallback'] ?? ''));
        $seed = (int)($item['product_id'] ?? 0);
        if (class_exists(\Weline\Theme\Helper\StorefrontImagePlaceholder::class)) {
            $resolved = \Weline\Theme\Helper\StorefrontImagePlaceholder::resolve($imageSrc, $seed);

            return [
                'src' => $resolved['src'],
                'fallback' => $imageFallback !== '' ? $imageFallback : $resolved['fallback'],
            ];
        }

        return [
            'src' => $imageSrc,
            'fallback' => $imageFallback !== '' ? $imageFallback : $imageSrc,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderOptionsHtml(array $item): string
    {
        $options = is_array($item['options'] ?? null) ? $item['options'] : [];
        $rows = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $label = trim((string)($option['label'] ?? $option['code'] ?? ''));
            $value = trim((string)($option['value_label'] ?? $option['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            $swatchHtml = '';
            $swatchImage = trim((string)($option['swatch_image'] ?? ''));
            $swatchColor = trim((string)($option['swatch_color'] ?? ''));
            if ($this->isDisplayableSwatchUrl($swatchImage)) {
                $previewLabel = (string)__('查看规格图');
                $swatchHtml = '<button type="button" class="weline-checkout__item-option-swatch-btn"'
                    . ' data-checkout-swatch-trigger data-checkout-swatch-src="' . $this->e($swatchImage) . '"'
                    . ' aria-label="' . $this->e($previewLabel) . '">'
                    . '<img class="weline-checkout__item-option-swatch" src="' . $this->e($swatchImage) . '" alt=""'
                    . ' loading="lazy" decoding="async" width="16" height="16">'
                    . '</button>';
            } elseif ($swatchColor !== '' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $swatchColor) === 1) {
                $swatchHtml = '<span class="weline-checkout__item-option-swatch weline-checkout__item-option-swatch--color"'
                    . ' style="background-color:' . $this->e($swatchColor) . '" aria-hidden="true"></span>';
            }
            $rows[] = '<li class="weline-checkout__item-option">'
                . '<span class="weline-checkout__item-option-label">' . $this->e($label) . '</span>'
                . '<span class="weline-checkout__item-option-sep" aria-hidden="true">·</span>'
                . '<span class="weline-checkout__item-option-value-wrap">'
                . $swatchHtml
                . '<span class="weline-checkout__item-option-value">' . $this->e($value) . '</span>'
                . '</span></li>';
        }
        if ($rows !== []) {
            return '<ul class="weline-checkout__item-options">' . implode('', $rows) . '</ul>';
        }

        $fallback = $this->formatOptionsFallback($item);
        if ($fallback === '') {
            return '';
        }

        return '<small class="weline-checkout__item-options">' . $this->e($fallback) . '</small>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatOptionsFallback(array $item): string
    {
        $parts = [];
        $options = is_array($item['options'] ?? null) ? $item['options'] : [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $label = trim((string)($option['label'] ?? $option['code'] ?? ''));
            $value = trim((string)($option['value_label'] ?? $option['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            $parts[] = $label . ': ' . $value;
        }
        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        $selection = is_array($item['selection'] ?? null) ? $item['selection'] : [];
        $keys = array_keys($selection);
        sort($keys, SORT_STRING);
        foreach ($keys as $code) {
            $code = trim((string)$code);
            $value = trim((string)($selection[$code] ?? ''));
            if ($code === '' || $value === '') {
                continue;
            }
            $parts[] = $code . ': ' . $value;
        }

        return implode(' · ', $parts);
    }

    private function isDisplayableSwatchUrl(string $image): bool
    {
        $image = trim($image);
        if ($image === '') {
            return false;
        }
        if (str_starts_with(strtolower($image), 'asset://')) {
            return false;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $image) === 1
            && preg_match('#^(https?:)?//#i', $image) !== 1
        ) {
            return false;
        }

        return true;
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
