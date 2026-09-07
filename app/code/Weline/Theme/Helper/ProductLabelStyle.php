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

  /** @var list<string> */
    public const KINDS = [
        self::KIND_NEW,
        self::KIND_SALE,
        self::KIND_DEMO,
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
            default => 'var(--weline-theme-surface-muted)',
        };
    }

    public static function globalTextToken(string $kind): string
    {
        return match ($kind) {
            self::KIND_NEW => 'var(--weline-product-label-new-text)',
            self::KIND_SALE => 'var(--weline-product-label-sale-text)',
            self::KIND_DEMO => 'var(--weline-product-label-demo-text)',
            default => 'var(--weline-theme-text)',
        };
    }

    /**
     * @param array<string, mixed> $product
     * @return array{is_new:bool,is_sale:bool,is_demo:bool}
     */
    public static function resolveFlags(array $product, int $index = 0): array
    {
        unset($index);

        // Only honor explicit catalog/demo flags. Never fabricate NEW/SALE by
        // card index — that made homepage widgets look inconsistently tagged
        // and duplicated real deal copy (今日精选) with a fake 促销 badge.
        return [
            'is_new' => !empty($product['is_new']),
            'is_sale' => !empty($product['is_sale']),
            'is_demo' => !empty($product['is_demo']),
        ];
    }
}
