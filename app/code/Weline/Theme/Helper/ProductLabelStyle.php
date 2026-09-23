<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\View\Template;

/**
 * 商品标签（NEW / SALE / DEMO 等）全局主题 Token 与部件级配色覆盖。
 */
final class ProductLabelStyle
{
    public const KIND_NEW = 'new';
    public const KIND_SALE = 'sale';
    public const KIND_DEMO = 'demo';
    /** Homepage shelf badges (WO-HP-P3-MALL-04). */
    public const KIND_HOT = 'hot';
    public const KIND_SHIP = 'ship';

  /** @var list<string> */
    public const KINDS = [
        self::KIND_NEW,
        self::KIND_SALE,
        self::KIND_DEMO,
        self::KIND_HOT,
        self::KIND_SHIP,
    ];

    /**
     * @return array{new_bg:string,new_text:string,sale_bg:string,sale_text:string}
     */
    public static function widgetOverridesFromTemplate(Template $template): array
    {
        return [
            'new_bg' => ThemeUiColor::sanitize($template->getData('label_new_bg') ?? '', ''),
            'new_text' => ThemeUiColor::sanitize($template->getData('label_new_text') ?? '', ''),
            'sale_bg' => ThemeUiColor::sanitize($template->getData('label_sale_bg') ?? '', ''),
            'sale_text' => ThemeUiColor::sanitize($template->getData('label_sale_text') ?? '', ''),
            'demo_bg' => ThemeUiColor::sanitize($template->getData('label_demo_bg') ?? '', ''),
            'demo_text' => ThemeUiColor::sanitize($template->getData('label_demo_text') ?? '', ''),
        ];
    }

    /**
     * @param array<string, string> $overrides
     */
    public static function scopeStyleAttribute(array $overrides): string
    {
        $pairs = [];
        foreach (self::KINDS as $kind) {
            $bg = trim((string)($overrides[$kind . '_bg'] ?? ''));
            $text = trim((string)($overrides[$kind . '_text'] ?? ''));
            if ($bg !== '' && ThemeUiColor::isValid($bg)) {
                $pairs[] = '--w-product-label-' . $kind . '-bg:' . $bg;
            }
            if ($text !== '' && ThemeUiColor::isValid($text)) {
                $pairs[] = '--w-product-label-' . $kind . '-text:' . $text;
            }
        }

        return $pairs === [] ? '' : implode(';', $pairs);
    }

    public static function globalBgToken(string $kind): string
    {
        return match ($kind) {
            self::KIND_NEW => 'var(--weline-product-label-new-bg)',
            self::KIND_SALE => 'var(--weline-product-label-sale-bg)',
            self::KIND_DEMO => 'var(--weline-product-label-demo-bg)',
            self::KIND_HOT => 'color-mix(in srgb, var(--weline-theme-warning, #c4893f) 18%, #fff)',
            self::KIND_SHIP => 'color-mix(in srgb, var(--color-link, #3d6b78) 16%, #fff)',
            default => 'var(--weline-theme-surface-muted)',
        };
    }

    public static function globalTextToken(string $kind): string
    {
        return match ($kind) {
            self::KIND_NEW => 'var(--weline-product-label-new-text)',
            self::KIND_SALE => 'var(--weline-product-label-sale-text)',
            self::KIND_DEMO => 'var(--weline-product-label-demo-text)',
            self::KIND_HOT => 'var(--weline-theme-warning, #c4893f)',
            self::KIND_SHIP => 'var(--color-link, #3d6b78)',
            default => 'var(--weline-theme-text)',
        };
    }

    /**
     * @param array<string, mixed> $product
     * @return array{is_new:bool,is_sale:bool,is_demo:bool,is_hot:bool,is_free_shipping:bool,discount_percent:int,sale_text:string}
     */
    public static function resolveFlags(array $product, int $index = 0): array
    {
        unset($index);

        // Only honor explicit catalog/demo flags. Never fabricate NEW/SALE by
        // card index — that made homepage widgets look inconsistently tagged
        // and duplicated real deal copy (今日精选) with a fake 促销 badge.
        $price = (float)($product['price'] ?? 0);
        $original = (float)($product['original_price'] ?? 0);
        $discountPercent = (int)($product['discount_percent'] ?? 0);
        if ($discountPercent <= 0 && $original > $price && $price > 0) {
            $discountPercent = (int)round((1 - ($price / $original)) * 100);
        }
        $discountPercent = max(0, min(90, $discountPercent));
        $isSale = !empty($product['is_sale']) || $discountPercent > 0 || ($original > $price && $price > 0);
        $saleText = trim((string)($product['sale_badge_text'] ?? ''));
        if ($saleText === '' && $isSale) {
            $saleText = $discountPercent > 0 ? ('-' . $discountPercent . '%') : '特价';
        }

        // Align with storefront trust copy「满 $49 包邮」; USD-facing shelf prices.
        $freeShipThreshold = 49.0;
        $isFreeShipping = !empty($product['free_shipping']) || !empty($product['is_free_shipping']);
        if (!$isFreeShipping && empty($product['currency_unavailable']) && empty($product['quote_only']) && $price >= $freeShipThreshold) {
            $isFreeShipping = true;
        }

        return [
            'is_new' => !empty($product['is_new']),
            'is_sale' => $isSale,
            'is_demo' => !empty($product['is_demo']),
            'is_hot' => !empty($product['is_hot']) || !empty($product['is_bestseller']),
            'is_free_shipping' => $isFreeShipping,
            'discount_percent' => $discountPercent,
            'sale_text' => $saleText,
        ];
    }
}
