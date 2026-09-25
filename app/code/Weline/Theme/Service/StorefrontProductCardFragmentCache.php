<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;

/**
 * Scope-hot HTML fragments for storefront product cards (A-axis reuse).
 *
 * Cards stay in SSR HTML (SEO). Policy bags only skip re-fetch of identical
 * product+flags markup across homepage/list widgets and warm workers.
 */
final class StorefrontProductCardFragmentCache
{
    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    public static function cachePolicy(): CachePolicy
    {
        return StorefrontThemeCacheCoordinator::productCardHtmlPolicy();
    }

    public static function cachePool(): string
    {
        return StorefrontThemeCacheCoordinator::PRODUCT_CARD_HTML_POOL;
    }

    /**
     * @param array<string, mixed> $product Normalized card product bag.
     * @param array<string, mixed> $flags Normalized render flags (density/show_*).
     */
    public function rememberCardHtml(array $product, array $flags, callable $builder): string
    {
        $html = $this->hotCache->rememberPolicy(
            self::cachePolicy(),
            $this->logicalKey($product, $flags),
            static function () use ($builder): string {
                $rendered = $builder();

                return \is_string($rendered) ? $rendered : '';
            },
        );

        return \is_string($html) ? $html : '';
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $flags
     */
    public function logicalKey(array $product, array $flags): string
    {
        $productId = max(0, (int)($product['id'] ?? $product['product_id'] ?? 0));
        $parts = [
            (string)$productId,
            (string)($product['name'] ?? ''),
            (string)($product['sku'] ?? ''),
            (string)($product['url'] ?? ''),
            (string)($product['url_path'] ?? ''),
            (string)($product['image'] ?? ''),
            (string)($product['price'] ?? ''),
            (string)($product['original_price'] ?? ''),
            (string)($product['currency'] ?? ''),
            !empty($product['currency_unavailable']) ? '1' : '0',
            !empty($product['is_sale']) ? '1' : '0',
            !empty($product['is_new']) ? '1' : '0',
            !empty($product['sellable']) ? '1' : '0',
            !empty($product['quote_only']) ? '1' : '0',
            !empty($product['needs_selection']) ? '1' : '0',
            (string)($product['global_offer_uuid'] ?? ''),
            (string)($product['campaign_label'] ?? ''),
            (string)($product['campaign_url'] ?? ''),
            // WO-BUYER-SHOW-05：评分变化必须换 key，否则列表星级被旧 HTML 片段钉死
            (string)($product['rating'] ?? '0'),
            (string)($product['review_count'] ?? '0'),
            // Product buckets eager(0)/lazy(INITIAL) so list positions share keys.
            (string)($product['card_index'] ?? ''),
            (string)($flags['density'] ?? 'standard'),
            !empty($flags['show_price']) ? '1' : '0',
            !empty($flags['show_rating']) ? '1' : '0',
            !empty($flags['show_add_to_cart']) ? '1' : '0',
            !empty($flags['show_wishlist']) ? '1' : '0',
            !empty($flags['show_compare']) ? '1' : '0',
            !empty($flags['show_quickview']) ? '1' : '0',
            !empty($flags['show_labels']) ? '1' : '0',
            !empty($flags['show_sku']) ? '1' : '0',
            !empty($flags['wishlist_pixel']) ? '1' : '0',
            (string)($flags['class'] ?? ''),
            // Card HTML embeds absolute @url links; Worker :19655 vs public :9555
            // must not share the same fragment (else purchase-panel fetch breaks).
            $this->storefrontOriginSegment(),
        ];

        // v4: 无评也渲染灰星+(0)，旧 v3 片段缺评分行会导致网格价签错位
        return 'theme.product_card.html.v4.'
            . $this->storefrontLocaleSegment()
            . '.'
            . $productId
            . '.'
            . \substr(\sha1(\implode("\n", $parts)), 0, 20);
    }

    private function storefrontLocaleSegment(): string
    {
        try {
            $locale = \trim(\str_replace('-', '_', (string)\Weline\Framework\App\State::getLangLocal()));
        } catch (\Throwable) {
            $locale = '';
        }

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    /**
     * Partition card HTML by the request URL origin (host + website_url + base_url).
     * Reuses KeyBuilder's existing environment dimensions so Worker diagnostic ports
     * cannot leak absolute links into public acceptance Host pages.
     */
    private function storefrontOriginSegment(): string
    {
        try {
            $env = \Weline\Framework\Cache\KeyBuilder::environmentContext([], [
                'area' => false,
                'area_route' => false,
                'website' => false,
                'website_url' => true,
                'host' => true,
                'base_url' => true,
                'lang' => false,
                'lang_local' => false,
                'currency' => false,
            ]);
            $host = \strtolower(\trim((string)($env['host'] ?? '')));
            $websiteUrl = \strtolower(\trim((string)($env['website_url'] ?? '')));
            $baseUrl = \strtolower(\trim((string)($env['base_url'] ?? '')));
            $joined = $host . '|' . $websiteUrl . '|' . $baseUrl;
            if ($joined === '||') {
                return 'origin-unknown';
            }

            return 'origin-' . \substr(\sha1($joined), 0, 12);
        } catch (\Throwable) {
            return 'origin-unknown';
        }
    }
}
