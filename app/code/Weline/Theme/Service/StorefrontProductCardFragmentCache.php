<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\ProcessSharedInterface;
use Weline\Framework\Runtime\RequestContext;

/**
 * Scope-hot HTML fragments for storefront product cards (A-axis reuse).
 *
 * Cards stay in SSR HTML (SEO). Policy bags only skip re-fetch of identical
 * product+flags markup across homepage/list widgets and warm workers.
 *
 * v9 identity contract (HARD):
 * - Callers MUST pass {@see \Weline\Product\Service\ProductCardRenderer::normalizeProduct()}
 *   bags into peek/remember (Renderer enforces normalize-before-key).
 * - Fingerprint uses canonical relative {@see url_path} only — never absolute {@see url}
 *   (catalog raw / widget splitForTaglib / getUrl() absolute diverge and broke v8 HIT).
 * - Image loading attrs stay stamped after HIT (not part of identity).
 */
final class StorefrontProductCardFragmentCache implements ProcessSharedInterface
{
    private const REQUEST_ORIGIN_MEMO_KEY = 'theme.product_card.fragment.origin_segment.v9';
    private const REQUEST_LOCALE_MEMO_KEY = 'theme.product_card.fragment.locale_segment.v9';
    public const LOGICAL_KEY_PREFIX = 'theme.product_card.html.v9.';

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
     * Peek without building. Returns null on miss/expired.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $flags
     */
    public function peekCardHtml(array $product, array $flags): ?string
    {
        $peeked = $this->hotCache->peekPolicy(
            self::cachePolicy(),
            $this->logicalKey($product, $flags),
        );

        return \is_string($peeked) && $peeked !== '' ? $peeked : null;
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
            // v9: relative route only — absolute url is presentation and must not shard HIT.
            $this->canonicalRoutePath($product),
            (string)($product['image'] ?? ''),
            (string)($product['price'] ?? ''),
            (string)($product['original_price'] ?? ''),
            (string)($product['currency'] ?? ''),
            !empty($product['currency_unavailable']) ? '1' : '0',
            !empty($product['is_sale']) ? '1' : '0',
            !empty($product['is_new']) ? '1' : '0',
            !empty($product['is_hot']) || !empty($product['is_bestseller']) ? '1' : '0',
            !empty($product['free_shipping']) || !empty($product['is_free_shipping']) ? '1' : '0',
            (string)($product['sale_badge_text'] ?? ''),
            !empty($product['sellable']) ? '1' : '0',
            !empty($product['quote_only']) ? '1' : '0',
            !empty($product['needs_selection']) ? '1' : '0',
            (string)($product['global_offer_uuid'] ?? ''),
            (string)($product['campaign_label'] ?? ''),
            (string)($product['campaign_url'] ?? ''),
            // WO-BUYER-SHOW-05：评分变化必须换 key，否则列表星级被旧 HTML 片段钉死
            (string)($product['rating'] ?? '0'),
            (string)($product['review_count'] ?? '0'),
            // loading/fetchpriority stamped after HIT — not a key dimension.
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

        return self::LOGICAL_KEY_PREFIX
            . $this->storefrontLocaleSegment()
            . '.'
            . $productId
            . '.'
            . \substr(\sha1(\implode("\n", $parts)), 0, 20);
    }

    /**
     * Prefer normalized url_path; fall back to stripping an absolute/relative url to a route.
     *
     * @param array<string, mixed> $product
     */
    private function canonicalRoutePath(array $product): string
    {
        $path = \trim((string)($product['url_path'] ?? ''), '/');
        if ($path !== '') {
            return \strtolower($path);
        }

        $url = \trim((string)($product['url'] ?? ''));
        if ($url === '') {
            return '';
        }
        if (\preg_match('#^(?:https?:)?//#i', $url) === 1) {
            $parsed = \parse_url($url, \PHP_URL_PATH);
            $url = \is_string($parsed) ? $parsed : '';
        }
        $path = \trim(\str_replace('\\', '/', $url), '/');
        // Drop a leading locale-looking segment when present (en_US / zh_Hans_CN).
        if (\preg_match('#^[a-z]{2}(?:_[A-Za-z0-9]+)*/#', $path) === 1) {
            $slash = \strpos($path, '/');
            if ($slash !== false) {
                $path = \substr($path, $slash + 1);
            }
        }

        return \strtolower(\trim($path, '/'));
    }

    private function storefrontLocaleSegment(): string
    {
        if (RequestContext::isInitialized() && RequestContext::has(self::REQUEST_LOCALE_MEMO_KEY)) {
            $memo = RequestContext::get(self::REQUEST_LOCALE_MEMO_KEY);

            return \is_string($memo) && $memo !== '' ? $memo : 'zh_Hans_CN';
        }

        try {
            $locale = \trim(\str_replace('-', '_', (string)\Weline\Framework\App\State::getLangLocal()));
        } catch (\Throwable) {
            $locale = '';
        }
        $segment = $locale !== '' ? $locale : 'zh_Hans_CN';
        if (RequestContext::isInitialized()) {
            RequestContext::set(self::REQUEST_LOCALE_MEMO_KEY, $segment);
        }

        return $segment;
    }

    /**
     * Partition card HTML by the request URL origin (host + website_url + base_url).
     * Reuses KeyBuilder's existing environment dimensions so Worker diagnostic ports
     * cannot leak absolute links into public acceptance Host pages.
     */
    private function storefrontOriginSegment(): string
    {
        if (RequestContext::isInitialized() && RequestContext::has(self::REQUEST_ORIGIN_MEMO_KEY)) {
            $memo = RequestContext::get(self::REQUEST_ORIGIN_MEMO_KEY);

            return \is_string($memo) && $memo !== '' ? $memo : 'origin-unknown';
        }

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
                $segment = 'origin-unknown';
            } else {
                $segment = 'origin-' . \substr(\sha1($joined), 0, 12);
            }
        } catch (\Throwable) {
            $segment = 'origin-unknown';
        }

        if (RequestContext::isInitialized()) {
            RequestContext::set(self::REQUEST_ORIGIN_MEMO_KEY, $segment);
        }

        return $segment;
    }
}
