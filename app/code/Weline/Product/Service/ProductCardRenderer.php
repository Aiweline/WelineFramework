<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ProductCardUrl;
use Weline\Theme\Helper\StorefrontImagePlaceholder;

/**
 * Canonical storefront product card renderer for Taglib + widget reuse.
 */
final class ProductCardRenderer
{
    private const CSS_FLAG = 'product.product_card_css_emitted';
    private const CSS_DISCARD_HOOK = 'product.product_card_css_discard';
    /** Kept for contracts / call sites; emission is now an inline <style> (body <link> is unreliable + ThemeEditor strips //link). */
    public const CSS_LINK_MARKER = 'data-weline-product-card-css';
    private const CSS_VERSION = '20260918-product-card-css-fpc-heal';
    /** Keep the first two desktop rows available without flooding the network. */
    private const INITIAL_VIEWPORT_IMAGE_COUNT = 8;

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $options
     */
    public static function render(array $product, array $options = []): string
    {
        $normalized = self::normalizeProduct($product);
        if ((int)($normalized['id'] ?? 0) <= 0) {
            return '';
        }

        $flags = self::normalizeOptions($options);
        $template = Template::getInstance();
        $html = (string)RequestLifecycleTrace::measurePhase(
            'product.card.render',
            fn(): string => (string)$template->fetch(
                'Weline_Product::templates/frontend/partials/product-card.phtml',
                [
                    'product' => $normalized,
                    'show_price' => $flags['show_price'],
                    'show_rating' => $flags['show_rating'],
                    'show_add_to_cart' => $flags['show_add_to_cart'],
                    'show_wishlist' => $flags['show_wishlist'],
                    'show_compare' => $flags['show_compare'],
                    'show_quickview' => $flags['show_quickview'],
                    'show_labels' => $flags['show_labels'],
                    'show_sku' => $flags['show_sku'],
                    'density' => $flags['density'],
                    'class' => $flags['class'],
                    'wishlist_pixel' => $flags['wishlist_pixel'],
                ]
            ),
            [
                'density' => $flags['density'],
                'show_price' => $flags['show_price'],
                'show_add_to_cart' => $flags['show_add_to_cart'],
                'show_sku' => $flags['show_sku'],
            ],
        );

        // CSS：宿主 emit + 卡 partial 兜底 emitStylesheetLinkOnce()（once）；禁止 link 注入。
        return $html;
    }

    /**
     * @param mixed $product
     * @param array<string, mixed> $options
     */
    public static function renderFromTaglib(mixed $product, array $options = []): string
    {
        if (!\is_array($product)) {
            return '';
        }

        return self::render($product, $options);
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function normalizeProduct(array $product): array
    {
        $productId = (int)($product['product_id'] ?? $product['id'] ?? 0);
        $product['id'] = $productId;
        $product['product_id'] = $productId;

        $route = trim((string)($product['url'] ?? ''));
        $slug = trim((string)($product['slug'] ?? ''));
        if ($route === '' && $slug !== '') {
            $route = 'product/' . ltrim($slug, '/');
        } elseif ($route === '' && $productId > 0) {
            $route = 'product/' . $productId;
        }
        $querySuffix = '';
        if (str_contains($route, '?')) {
            [$routePathOnly, $queryPart] = explode('?', $route, 2);
            $route = $routePathOnly;
            $queryPart = trim($queryPart);
            $querySuffix = $queryPart !== '' ? '?' . $queryPart : '';
        }
        $link = ProductCardUrl::splitForTaglib($route);
        if ($link['url'] !== '') {
            $product['url'] = $link['url'] . $querySuffix;
            $product['url_path'] = '';
        } else {
            $path = trim((string)$link['url_path']);
            if ($path === '') {
                $product['url'] = '';
                $product['url_path'] = '';
            } elseif ($querySuffix !== '') {
                $prefix = rtrim(Url::getPrefix(), '/');
                $product['url'] = ($prefix !== '' ? $prefix : '') . '/' . ltrim($path, '/') . $querySuffix;
                $product['url_path'] = '';
            } else {
                $product['url_path'] = $path;
                $prefix = rtrim(Url::getPrefix(), '/');
                $product['url'] = ($prefix !== '' ? $prefix : '') . '/' . ltrim($path, '/');
            }
        }

        $imageResolved = StorefrontImagePlaceholder::resolve((string)($product['image'] ?? ''), $productId);
        $product['image'] = $imageResolved['src'];
        $product['image_fallback'] = (string)($imageResolved['fallback'] ?? '');

        $product['name'] = trim((string)($product['name'] ?? ''));
        $product['sku'] = trim((string)($product['sku'] ?? ''));
        $product['currency'] = trim((string)($product['currency'] ?? 'CNY')) ?: 'CNY';
        $product['currency_unavailable'] = !empty($product['currency_unavailable']);
        $product['price'] = (float)($product['price'] ?? 0);
        $product['original_price'] = (float)($product['original_price'] ?? 0);
        $product['rating'] = (float)($product['rating'] ?? 0);
        $product['review_count'] = (int)($product['review_count'] ?? 0);
        $product['is_new'] = !empty($product['is_new']);
        $product['is_sale'] = !empty($product['is_sale']);
        $product['is_demo'] = !empty($product['is_demo']);
        $product['sellable'] = !empty($product['sellable']);
        $product['quote_only'] = !empty($product['quote_only']);
        $product['needs_selection'] = !empty($product['needs_selection']);
        $product['global_offer_uuid'] = trim((string)($product['global_offer_uuid'] ?? ''));
        $product['campaign_label'] = trim((string)($product['campaign_label'] ?? ''));
        $product['campaign_url'] = trim((string)($product['campaign_url'] ?? ''));
        if (array_key_exists('card_index', $product)) {
            $product['card_index'] = max(0, (int)$product['card_index']);
        }

        return $product;
    }

    /**
     * Return the native image loading hints for a card's position in its list.
     *
     * Cards are server-rendered regardless of this policy. Only the image fetch
     * is changed: the initial viewport gets an eager, high-priority request and
     * all later cards keep the browser's native lazy loading behaviour.
     *
     * @param array<string, mixed> $product
     * @return array{loading:'eager'|'lazy', fetchpriority?:'high'}
     */
    public static function imageLoadingAttributes(array $product): array
    {
        $index = array_key_exists('card_index', $product)
            ? max(0, (int)$product['card_index'])
            : null;
        if ($index !== null && $index < self::INITIAL_VIEWPORT_IMAGE_COUNT) {
            return [
                'loading' => 'eager',
                'fetchpriority' => 'high',
            ];
        }

        return ['loading' => 'lazy'];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{
     *   show_price: bool,
     *   show_rating: bool,
     *   show_add_to_cart: bool,
     *   show_wishlist: bool,
     *   show_compare: bool,
     *   show_quickview: bool,
     *   show_labels: bool,
     *   show_sku: bool,
     *   density: string,
     *   class: string,
     *   wishlist_pixel: bool
     * }
     */
    /**
     * Map a storefront listing offer (catalog/category/search) into card product shape.
     *
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    public static function fromStorefrontOffer(array $offer, int $index = 0): array
    {
        $productId = max(0, (int)($offer['product_id'] ?? $offer['id'] ?? 0));
        $slug = strtolower(trim((string)($offer['slug'] ?? '')));
        $name = trim((string)($offer['name'] ?? ''));
        $sku = trim((string)($offer['sku'] ?? ''));
        $priceMinor = max(0, (int)($offer['unit_price_minor'] ?? 0));
        $price = round($priceMinor / 100, 2);
        $catalogMinor = max(0, (int)($offer['catalog_price_minor'] ?? 0));
        $compareAtMinor = max(0, (int)($offer['compare_at_minor'] ?? 0));
        $originalMinor = max($catalogMinor, $compareAtMinor);
        if ($originalMinor <= $priceMinor) {
            $originalMinor = 0;
        }
        $originalPrice = $originalMinor > 0 ? round($originalMinor / 100, 2) : 0.0;
        $hasDeal = !empty($offer['has_deal']) || ($originalMinor > $priceMinor && $priceMinor > 0);
        $currency = trim((string)($offer['currency'] ?? 'CNY'));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $productPath = $slug !== ''
            ? 'product/' . $slug
            : ($productId > 0 ? 'product/' . $productId : '');
        $query = \Weline\Product\Helper\StorefrontOfferDetailQuery::params($offer);
        $route = $productPath;
        if ($productPath !== '' && $query !== []) {
            $route .= '?' . http_build_query($query);
        }

        $image = trim((string)($offer['image'] ?? ''));
        if ($image === '' && isset($offer['images']) && \is_array($offer['images'])) {
            foreach ($offer['images'] as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    $image = $candidate;
                    break;
                }
            }
        }

        $combination = $offer['combination'] ?? [];
        $variantAxes = $offer['variant_axes'] ?? [];
        $needsSelection = !empty($offer['selection_required'])
            || !empty($offer['needs_selection'])
            || ((int)($offer['variant_offer_count'] ?? $offer['offer_count'] ?? 0) > 1)
            || (\is_array($combination) && $combination !== [])
            || (\is_array($variantAxes) && $variantAxes !== []);

        return self::normalizeProduct([
            'id' => $productId,
            'product_id' => $productId,
            'card_index' => max(0, $index),
            'name' => $name !== '' ? $name : $sku,
            'slug' => $slug,
            'sku' => $sku,
            'url' => $route,
            'image' => $image,
            'price' => $price,
            'original_price' => $originalPrice,
            'currency' => $currency,
            'rating' => 0.0,
            'review_count' => 0,
            'is_sale' => $hasDeal,
            'is_new' => !empty($offer['is_new']),
            'is_demo' => !empty($offer['is_demo']),
            'sellable' => !empty($offer['sellable']),
            'currency_unavailable' => !empty($offer['currency_unavailable']),
            'quote_only' => !empty($offer['quote_only']),
            'global_offer_uuid' => trim((string)($offer['global_offer_uuid'] ?? '')),
            'campaign_label' => trim((string)($offer['campaign_label'] ?? '')),
            'campaign_url' => trim((string)($offer['campaign_url'] ?? '')),
            'provider_code' => trim((string)($offer['provider_code'] ?? 'product')) ?: 'product',
            'message' => trim((string)($offer['message'] ?? '')),
            'stock' => (int)($offer['stock'] ?? 0),
            'needs_selection' => $needsSelection,
        ]);
    }

    public static function normalizeOptions(array $options): array
    {
        $density = strtolower(trim((string)($options['density'] ?? 'standard')));
        if (!\in_array($density, ['standard', 'compact', 'shelf'], true)) {
            $density = 'standard';
        }

        return [
            'show_price' => self::toBool($options['show_price'] ?? true, true),
            'show_rating' => self::toBool($options['show_rating'] ?? true, true),
            'show_add_to_cart' => self::toBool($options['show_add_to_cart'] ?? true, true),
            'show_wishlist' => self::toBool($options['show_wishlist'] ?? true, true),
            'show_compare' => self::toBool($options['show_compare'] ?? true, true),
            'show_quickview' => self::toBool($options['show_quickview'] ?? true, true),
            'show_labels' => self::toBool($options['show_labels'] ?? true, true),
            'show_sku' => self::toBool($options['show_sku'] ?? false, false),
            'density' => $density,
            'class' => trim((string)($options['class'] ?? '')),
            'wishlist_pixel' => self::toBool($options['wishlist_pixel'] ?? false, false),
        ];
    }

    public static function toBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value !== 0;
        }
        $normalized = strtolower(trim((string)$value));
        if (\in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * Allow preview / widget repair passes / Fiber discard to emit card CSS again.
     */
    public static function resetProductCardCssEmission(): void
    {
        RequestContext::remove(self::CSS_FLAG);
        RequestContext::removeCaptureDiscard(self::CSS_DISCARD_HOOK);
    }

    /**
     * 货架/列表宿主与卡 partial 按需注入 canonical product-card.css（每请求一次）。
     * 内联 <style> 随宿主/卡走（body <link> 易被 FPC/预览消毒丢掉）。
     *
     * 仅在成功产出非空 style 后置位；Fiber discardCapture 会复位以便重渲再发。
     */
    public static function emitStylesheetLinkOnce(): string
    {
        if (RequestContext::has(self::CSS_FLAG)) {
            return '';
        }

        $tag = self::buildProductCardStyleTag();
        if ($tag === '') {
            return '';
        }

        RequestContext::set(self::CSS_FLAG, true);
        RequestContext::onCaptureDiscard(
            static function (): void {
                self::resetProductCardCssEmission();
            },
            self::CSS_DISCARD_HOOK
        );

        return $tag;
    }

    /**
     * Inline CSS owned by the canonical product card (title/price/media/shopper chrome).
     */
    public static function buildProductCardStyleTag(): string
    {
        $cssPath = dirname(__DIR__) . '/view/statics/css/frontend/product-card.css';
        $css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';
        if ($css === '') {
            return '';
        }

        return '<style ' . self::CSS_LINK_MARKER . '="1" '
            . 'data-weline-product-card-version="' . htmlspecialchars(self::CSS_VERSION, ENT_QUOTES, 'UTF-8') . '" '
            . 'data-no-extract="true">'
            . $css
            . '</style>' . "\n";
    }
}
