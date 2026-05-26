<?php

namespace WeShop\Product\Helper;

final class ProductOptionRenderer
{
    /**
     * @param array<int, array<string, mixed>> $options
     */
    public static function renderItems(array $options, bool $withSeparators = false): string
    {
        $html = [];
        foreach ($options as $option) {
            if (!\is_array($option)) {
                continue;
            }

            $value = \trim((string) ($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $label = \trim((string) ($option['label'] ?? ''));
            $item = '<span class="weshop-product-option">';
            if ($label !== '') {
                $item .= '<span class="weshop-product-option__label">' . self::escape($label) . ':</span>';
            }
            $item .= self::renderSwatch($option, $label, $value);
            $item .= '<span class="weshop-product-option__value">' . self::escape($value) . '</span>';
            $item .= '</span>';
            $html[] = $item;
        }

        if ($html === []) {
            return '';
        }

        if (!$withSeparators) {
            return \implode('', $html);
        }

        $separated = [];
        foreach ($html as $index => $itemHtml) {
            if ($index > 0) {
                $separated[] = '<span class="weshop-product-option__separator">/</span>';
            }
            $separated[] = $itemHtml;
        }

        return \implode('', $separated);
    }

    /**
     * @param array<string, mixed> $option
     */
    private static function renderSwatch(array $option, string $label, string $value): string
    {
        $type = \strtolower(\trim((string) ($option['swatch_type'] ?? '')));
        $swatchValue = \trim((string) ($option['swatch_value'] ?? ''));
        $optionImage = \trim((string) ($option['option_image'] ?? ''));
        if ($type === '' && $optionImage !== '') {
            $type = 'image';
        }
        if ($type === '' || ($swatchValue === '' && $optionImage === '')) {
            return '';
        }

        if ($type === 'color') {
            $color = self::sanitizeCssColor($swatchValue);
            if ($color === null) {
                return '';
            }

            return '<span class="weshop-product-option__swatch weshop-product-option__swatch--color" style="background-color:'
                . self::escape($color)
                . '"></span>';
        }

        if ($type === 'image') {
            $imageUrl = self::sanitizeImageUrl($swatchValue !== '' ? $swatchValue : $optionImage);
            if ($imageUrl === null) {
                return '';
            }

            $alt = \trim($label . ' ' . $value);

            return '<span class="weshop-product-option__swatch weshop-product-option__swatch--image">'
                . '<img src="' . self::escape($imageUrl) . '" alt="' . self::escape($alt) . '">'
                . '</span>';
        }

        return '';
    }

    private static function sanitizeCssColor(string $value): ?string
    {
        $value = \trim($value);
        if (\preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1) {
            return $value;
        }

        if (\preg_match('/^(?:rgb|rgba|hsl|hsla)\([0-9.,%\s]+\)$/i', $value) === 1) {
            return $value;
        }

        return null;
    }

    private static function sanitizeImageUrl(string $value): ?string
    {
        $value = \trim(\str_replace(["\r", "\n"], '', $value));
        if ($value === '' || \preg_match('/^(?:javascript|vbscript|data):/i', $value) === 1) {
            return null;
        }

        return $value;
    }

    private static function escape(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
