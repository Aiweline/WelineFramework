<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\View\Template;

/**
 * 商品卡片收藏 / 对比 / 快速查看开关（部件级，默认全开）。
 */
final class ProductCardShopperActionParams
{
    /**
     * @param array<string, mixed>|null $source
     * @return array{show_wishlist: bool, show_compare: bool, show_quickview: bool}
     */
    public static function fromWidget(?array $source = null): array
    {
        $read = static function (string $key, bool $default) use ($source): bool {
            if (!is_array($source) || !array_key_exists($key, $source)) {
                return $default;
            }

            return (bool)$source[$key];
        };

        return [
            'show_wishlist' => $read('show_wishlist', true),
            'show_compare' => $read('show_compare', true),
            'show_quickview' => $read('show_quickview', true),
        ];
    }

    /**
     * @return array{show_wishlist: bool, show_compare: bool, show_quickview: bool}
     */
    public static function fromTemplate(Template $template): array
    {
        return [
            'show_wishlist' => self::resolveFlag($template, 'show_wishlist', '_unused_', true),
            'show_compare' => self::resolveFlag($template, 'show_compare', '_unused_', true),
            'show_quickview' => self::resolveFlag($template, 'show_quickview', '_unused_', true),
        ];
    }

    /**
     * 从 Template data 解析商品 ID（兼容嵌套 fetch 与 widget 级 product_id 污染）。
     */
    public static function resolveProductId(Template $template): int
    {
        foreach (['shopper_action_product_id', 'product_id'] as $key) {
            if (!$template->hasData($key)) {
                continue;
            }
            $value = (int)$template->getData($key);
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    /**
     * 读取 bool 开关；空字符串视为未设置（widget 占位常见）。
     */
    public static function resolveFlag(
        Template $template,
        string $primaryKey,
        string $fallbackKey,
        bool $default
    ): bool {
        foreach ([$primaryKey, $fallbackKey] as $key) {
            if (!$template->hasData($key)) {
                continue;
            }
            $value = $template->getData($key);
            if ($value === null || $value === '') {
                continue;
            }

            return (bool)$value;
        }

        return $default;
    }

    /**
     * @param array{show_wishlist?: bool, show_compare?: bool, show_quickview?: bool} $flags
     * @return array<string, mixed>
     */
    public static function fetchDictionary(int $productId, array $flags, bool $wishlistPixel = false): array
    {
        return [
            'shopper_action_product_id' => $productId,
            'shopper_show_wishlist' => (bool)($flags['show_wishlist'] ?? true),
            'shopper_show_compare' => (bool)($flags['show_compare'] ?? true),
            'shopper_show_quickview' => (bool)($flags['show_quickview'] ?? true),
            'shopper_wishlist_pixel' => $wishlistPixel,
        ];
    }
}
