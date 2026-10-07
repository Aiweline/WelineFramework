<?php

declare(strict_types=1);

namespace Weline\Product\Taglib;

use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;
use Weline\Product\Service\ProductCardRenderer;

/**
 * 前台统一商品卡片。
 *
 * 单卡：
 * <w:product:card product="product" show-price="true" density="shelf" />
 *
 * 批量（禁止 foreach 解标签）：ids 指向变量名（根段，点号前）。
 * - id 列表：内部 cardsByIds + 批渲
 * - 已映射卡数组：直接批渲（不重拉）
 *
 * <w:product:card ids="relatedProductIds" density="compact" />
 * <w:product:card ids="shelfProducts" density="shelf" show-price="showPrice" />
 * <w:product:card ids="products.id" density="shelf" />
 */
final class ProductCard implements TaglibInterface
{
    public static function name(): string
    {
        return 'product:card';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'product' => false,
            'ids' => false,
            'show-price' => false,
            'show-rating' => false,
            'show-add-to-cart' => false,
            'show-wishlist' => false,
            'show-compare' => false,
            'show-quickview' => false,
            'show-labels' => false,
            'show-sku' => false,
            'density' => false,
            'class' => false,
            'wishlist-pixel' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            unset($tagKey, $config, $tagData);
            $idsAttr = trim((string)($attributes['ids'] ?? ''));
            unset($attributes['ids']);

            $optionsLiteral = '['
                . "'show_price' => \$Taglib__show_price ?? true,"
                . "'show_rating' => \$Taglib__show_rating ?? false,"
                . "'show_add_to_cart' => \$Taglib__show_add_to_cart ?? true,"
                . "'show_wishlist' => \$Taglib__show_wishlist ?? true,"
                . "'show_compare' => \$Taglib__show_compare ?? true,"
                . "'show_quickview' => \$Taglib__show_quickview ?? true,"
                . "'show_labels' => \$Taglib__show_labels ?? true,"
                . "'show_sku' => \$Taglib__show_sku ?? false,"
                . "'density' => \$Taglib__density ?? 'standard',"
                . "'class' => \$Taglib__class ?? '',"
                . "'wishlist_pixel' => \$Taglib__wishlist_pixel ?? false,"
                . ']';

            if ($idsAttr !== '') {
                $idsRoot = trim((string)(explode('.', $idsAttr, 2)[0] ?? ''));
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $idsRoot) === 1) {
                    $idsExpr = '(isset($' . $idsRoot . ') && is_array($' . $idsRoot
                        . ') ? array_values($' . $idsRoot . ') : [])';
                } else {
                    $idsExpr = '[]';
                }
                unset($attributes['product']);
                $code = AttributeCodeCompiler::attributes($attributes);

                return '<?php ' . $code . ' echo \\Weline\\Product\\Service\\ProductCardRenderer::projectFromIds('
                    . $idsExpr . ', ' . $optionsLiteral . '); ?>';
            }

            $productAttr = trim((string)($attributes['product'] ?? 'product'));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $productAttr) === 1) {
                $productExpr = '(isset($' . $productAttr . ') && is_array($' . $productAttr . ') ? $' . $productAttr . ' : [])';
                unset($attributes['product']);
            } else {
                $productExpr = '[]';
            }
            $code = AttributeCodeCompiler::attributes($attributes);

            return '<?php ' . $code . ' echo \\Weline\\Product\\Service\\ProductCardRenderer::renderFromTaglib('
                . $productExpr . ', ' . $optionsLiteral . '); ?>';
        };
    }

    public static function runtimeCallback(): callable
    {
        return static function (
            Template $template,
            string $tagKey,
            array $attributes,
            string $content,
        ): string {
            unset($template, $content);
            if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs') {
                return '';
            }

            $options = [
                'show_price' => $attributes['show-price'] ?? true,
                'show_rating' => $attributes['show-rating'] ?? false,
                'show_add_to_cart' => $attributes['show-add-to-cart'] ?? true,
                'show_wishlist' => $attributes['show-wishlist'] ?? true,
                'show_compare' => $attributes['show-compare'] ?? true,
                'show_quickview' => $attributes['show-quickview'] ?? true,
                'show_labels' => $attributes['show-labels'] ?? true,
                'show_sku' => $attributes['show-sku'] ?? false,
                'density' => $attributes['density'] ?? 'standard',
                'class' => $attributes['class'] ?? '',
                'wishlist_pixel' => $attributes['wishlist-pixel'] ?? false,
            ];

            $ids = $attributes['ids'] ?? null;
            if (\is_array($ids)) {
                return ProductCardRenderer::projectFromIds(array_values($ids), $options);
            }

            $product = $attributes['product'] ?? [];
            if (!\is_array($product)) {
                $product = [];
            }

            return ProductCardRenderer::renderFromTaglib($product, $options);
        };
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return htmlentities(
            '<w:product:card product="product" show-price="true" density="standard" />'
            . "\n"
            . '<w:product:card ids="productIds" density="compact" />'
            . "\n"
            . '<w:product:card ids="shelfProducts" density="shelf" show-price="showPrice" />'
        );
    }
}
