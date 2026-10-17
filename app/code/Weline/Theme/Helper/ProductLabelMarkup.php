<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Runtime\RequestContext;

/**
 * Request-scoped product badge HTML for shelf-density cards (A-axis hot path).
 *
 * Avoids nested Template::fetch(labels → product-label) and WidgetUiScope random uids
 * inside fragment-cached card bodies.
 */
final class ProductLabelMarkup
{
    private const PHRASE_MEMO_KEY = 'theme.product_label.markup.phrases.v1';

    /**
     * @return array{new:string,sale:string,demo:string,hot:string,ship:string}
     */
    public static function phrases(): array
    {
        if (RequestContext::isInitialized() && RequestContext::has(self::PHRASE_MEMO_KEY)) {
            $memo = RequestContext::get(self::PHRASE_MEMO_KEY);
            if (\is_array($memo)
                && isset($memo['new'], $memo['sale'], $memo['demo'], $memo['hot'], $memo['ship'])
            ) {
                return [
                    'new' => (string)$memo['new'],
                    'sale' => (string)$memo['sale'],
                    'demo' => (string)$memo['demo'],
                    'hot' => (string)$memo['hot'],
                    'ship' => (string)$memo['ship'],
                ];
            }
        }

        $phrases = [
            'new' => (string)\__('NEW'),
            'sale' => (string)\__('特价'),
            'demo' => (string)\__('DEMO'),
            'hot' => (string)\__('热销'),
            'ship' => (string)\__('包邮'),
        ];
        if (RequestContext::isInitialized()) {
            RequestContext::set(self::PHRASE_MEMO_KEY, $phrases);
        }

        return $phrases;
    }

    /**
     * @param array{
     *   is_demo?:bool,
     *   is_new?:bool,
     *   is_sale?:bool,
     *   is_hot?:bool,
     *   is_free_shipping?:bool,
     *   sale_text?:string
     * } $flags
     */
    public static function renderBadges(array $flags, string $containerClass = 'wpc-badges'): string
    {
        $isDemo = !empty($flags['is_demo']);
        $isNew = !empty($flags['is_new']);
        $isSale = !empty($flags['is_sale']);
        $isHot = !empty($flags['is_hot']);
        $isShip = !empty($flags['is_free_shipping']);
        if (!$isDemo && !$isNew && !$isSale && !$isHot && !$isShip) {
            return '';
        }

        $phrases = self::phrases();
        $esc = static fn (string $value): string => \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $saleText = \trim((string)($flags['sale_text'] ?? ''));
        if ($saleText === '') {
            $saleText = $phrases['sale'];
        } elseif (\preg_match('/\p{Han}/u', $saleText) === 1) {
            $saleText = WidgetI18n::label($saleText);
        }

        $parts = [];
        if ($isDemo) {
            $parts[] = self::labelSpan(ProductLabelStyle::KIND_DEMO, $phrases['demo'], $esc);
        }
        if ($isSale) {
            $parts[] = self::labelSpan(ProductLabelStyle::KIND_SALE, $saleText, $esc);
        }
        if ($isHot) {
            $parts[] = self::labelSpan(ProductLabelStyle::KIND_HOT, $phrases['hot'], $esc);
        }
        if ($isShip) {
            $parts[] = self::labelSpan(ProductLabelStyle::KIND_SHIP, $phrases['ship'], $esc);
        }
        if ($isNew) {
            $parts[] = self::labelSpan(ProductLabelStyle::KIND_NEW, $phrases['new'], $esc);
        }

        $class = \trim('w-product-labels ' . $containerClass);

        return '<div class="' . $esc($class) . '">' . \implode('', $parts) . '</div>';
    }

    /**
     * @param callable(string):string $esc
     */
    private static function labelSpan(string $kind, string $text, callable $esc): string
    {
        $backgroundColor = match ($kind) {
            ProductLabelStyle::KIND_SALE => 'var(--w-product-label-sale-bg, var(--weline-product-label-sale-bg, var(--color-product-label-sale-bg)))',
            ProductLabelStyle::KIND_DEMO => 'var(--w-product-label-demo-bg, var(--weline-product-label-demo-bg, var(--color-product-label-demo-bg)))',
            ProductLabelStyle::KIND_HOT => ProductLabelStyle::globalBgToken(ProductLabelStyle::KIND_HOT),
            ProductLabelStyle::KIND_SHIP => ProductLabelStyle::globalBgToken(ProductLabelStyle::KIND_SHIP),
            default => 'var(--w-product-label-new-bg, var(--weline-product-label-new-bg, var(--color-product-label-new-bg)))',
        };
        $textColor = match ($kind) {
            ProductLabelStyle::KIND_SALE => 'var(--w-product-label-sale-text, var(--weline-product-label-sale-text, var(--color-product-label-sale-text)))',
            ProductLabelStyle::KIND_DEMO => 'var(--w-product-label-demo-text, var(--weline-product-label-demo-text, var(--color-product-label-demo-text)))',
            ProductLabelStyle::KIND_HOT => ProductLabelStyle::globalTextToken(ProductLabelStyle::KIND_HOT),
            ProductLabelStyle::KIND_SHIP => ProductLabelStyle::globalTextToken(ProductLabelStyle::KIND_SHIP),
            default => 'var(--w-product-label-new-text, var(--weline-product-label-new-text, var(--color-product-label-new-text)))',
        };
        $inlineStyle = 'background: ' . $backgroundColor
            . '; color: ' . $textColor
            . '; box-shadow: inset 0 0 0 1px color-mix(in srgb, ' . $textColor . ' 12%, transparent);';

        return '<span class="w-product-label"'
            . ' data-kind="' . $esc($kind) . '"'
            . ' data-widget-style="' . $esc($inlineStyle) . '"'
            . '>' . $esc($text) . '</span>';
    }
}
