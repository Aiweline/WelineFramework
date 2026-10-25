<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\TraceGate;
use Weline\Framework\Runtime\TemplateRenderRouteTrace;
use Weline\Framework\View\Template;
use Weline\Framework\View\Data\DataInterface;
use Weline\Product\Helper\StorefrontCampaignEntry;
use Weline\Theme\Helper\ProductCardUrl;
use Weline\Theme\Helper\ProductLabelStyle;
use Weline\Theme\Helper\StorefrontImagePlaceholder;

/**
 * Canonical storefront product card renderer for Taglib + widget reuse.
 */
final class ProductCardRenderer
{
    private const CSS_FLAG = 'product.product_card_css_emitted';
    private const CSS_DISCARD_HOOK = 'product.product_card_css_discard';
    /** Request-scoped count of eager product-card images already allocated. */
    private const EAGER_IMAGE_BUDGET_KEY = 'product.card_eager_image_budget_used';
    private const EAGER_IMAGE_BUDGET_DISCARD_HOOK = 'product.card_eager_image_budget_discard';
    /** Marker consumed by the shared widget asset placement pipeline. */
    public const CSS_LINK_MARKER = 'data-weline-product-card-css';
    private const CSS_VERSION = '20260925-rating-zero';
    /** Keep the first two desktop rows available without flooding the network. */
    private const INITIAL_VIEWPORT_IMAGE_COUNT = 8;

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $options
     */
    public static function render(array $product, array $options = []): string
    {
        $flags = self::normalizeOptions($options);
        // Light pre-filter (full normalize deferred to MISS inside renderCachedBody).
        $productId = max(0, (int)($product['id'] ?? $product['product_id'] ?? 0));
        if ($productId <= 0) {
            return '';
        }
        // WO-BUILD-HOME-ZERO：货架密度禁渲零价卡（避免 SSR 出 $0.00；询价品走 PDP）
        if (($flags['density'] ?? 'standard') === 'shelf'
            && empty($product['currency_unavailable'])
            && ((float)($product['price'] ?? 0) <= 0 || !empty($product['quote_only']))
        ) {
            return '';
        }
        $html = (string)TraceGate::measurePhase(
            'product.card.render',
            static fn (): string => self::renderCachedBody($product, $flags),
            [
                'density' => $flags['density'],
                'show_price' => $flags['show_price'],
                'show_add_to_cart' => $flags['show_add_to_cart'],
                'show_sku' => $flags['show_sku'],
                'batch' => false,
            ],
        );

        // CSS：宿主 emit + 卡 partial 兜底 emitStylesheetLinkOnce()（once）；禁止 link 注入。
        // Renderer owns emission when defer_card_css=true so Policy HIT bodies stay CSS-free.
        return self::emitStylesheetLinkOnce() . $html;
    }

    /**
     * Listing batch path: map offers → card HTML in one measurePhase.
     * Skips per-card Taglib and per-card fragment/fetch — one native batch SSR.
     *
     * @param list<mixed> $offers
     * @param array<string, mixed> $options
     */
    public static function projectFromOffers(array $offers, array $options = []): string
    {
        $flags = self::normalizeOptions($options);
        $html = (string)TraceGate::measurePhase(
            'product.card.render',
            static function () use ($offers, $flags): string {
                $products = [];
                $index = 0;
                foreach ($offers as $offer) {
                    if (!\is_array($offer)) {
                        continue;
                    }
                    $product = self::fromStorefrontOffer($offer, $index);
                    if ((int)($product['id'] ?? 0) <= 0) {
                        ++$index;
                        continue;
                    }
                    // 集合/列表同源：缺价 offer 不进卡面（优先真实价；询价走 PDP）
                    if (empty($product['currency_unavailable']) && (float)($product['price'] ?? 0) <= 0) {
                        ++$index;
                        continue;
                    }
                    $products[] = $product;
                    ++$index;
                }

                // WO-BUYER-SHOW-05：列表卡批量回填真实评分；无评论保持 0 → 模板不显假星
                if (!empty($flags['show_rating']) && $products !== []) {
                    $products = self::hydrateReviewAggregates($products);
                }

                return self::renderNormalizedProductsHtml($products, $flags);
            },
            [
                'density' => $flags['density'],
                'show_price' => $flags['show_price'],
                'show_add_to_cart' => $flags['show_add_to_cart'],
                'show_sku' => $flags['show_sku'],
                'batch' => true,
                'offer_count' => \count($offers),
            ],
        );

        return self::emitStylesheetLinkOnce() . $html;
    }

    /**
     * Taglib ids= entry: accept product_id scalars OR already-mapped card rows.
     * Card-shaped lists go to projectFromProducts (no re-fetch); id lists hydrate via cardsByIds.
     *
     * @param list<mixed> $items
     * @param array<string, mixed> $options
     */
    public static function projectFromIds(array $items, array $options = []): string
    {
        $items = \array_values($items);
        if ($items === []) {
            return self::emitStylesheetLinkOnce();
        }

        $first = $items[0];
        if (\is_array($first) && self::looksLikeCardRow($first)) {
            return self::projectFromProducts($items, $options);
        }

        $orderedIds = [];
        $seen = [];
        foreach ($items as $raw) {
            if (\is_array($raw)) {
                $productId = max(0, (int)($raw['product_id'] ?? $raw['id'] ?? 0));
            } else {
                $productId = max(0, (int)$raw);
            }
            if ($productId <= 0 || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $orderedIds[] = $productId;
        }
        if ($orderedIds === []) {
            return self::emitStylesheetLinkOnce();
        }

        return self::projectFromProducts(self::cardsForIds($orderedIds), $options);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function looksLikeCardRow(array $row): bool
    {
        return isset($row['name'])
            || isset($row['price'])
            || isset($row['image'])
            || isset($row['url'])
            || isset($row['url_path'])
            || isset($row['global_offer_uuid'])
            || isset($row['unit_price_minor']);
    }

    /**
     * Resolve storefront card rows for product_ids (request-memoized per id).
     *
     * @param list<int> $orderedIds
     * @return list<array<string, mixed>>
     */
    private static function cardsForIds(array $orderedIds): array
    {
        $bagKey = 'product.card.rows_by_id';
        /** @var array<int, array<string, mixed>> $bag */
        $bag = [];
        if (RequestContext::isInitialized() && RequestContext::has($bagKey)) {
            $existing = RequestContext::get($bagKey);
            if (\is_array($existing)) {
                $bag = $existing;
            }
        }

        $missing = [];
        foreach ($orderedIds as $productId) {
            if (!isset($bag[$productId])) {
                $missing[] = $productId;
            }
        }

        if ($missing !== [] && \class_exists(StorefrontProductWidgetCatalog::class)) {
            try {
                /** @var StorefrontProductWidgetCatalog $catalog */
                $catalog = ObjectManager::getInstance(StorefrontProductWidgetCatalog::class);
                $fetched = $catalog->cardsByIds($missing, min(24, max(12, \count($missing))));
            } catch (\Throwable) {
                $fetched = [];
            }
            foreach ($fetched as $index => $product) {
                if (!\is_array($product)) {
                    continue;
                }
                $productId = max(0, (int)($product['product_id'] ?? $product['id'] ?? 0));
                if ($productId <= 0) {
                    continue;
                }
                $product['id'] = $productId;
                $product['product_id'] = $productId;
                $route = trim((string)($product['url'] ?? ''));
                $productLink = ProductCardUrl::splitForTaglib($route);
                $product['url'] = $productLink['url'];
                $product['url_path'] = $productLink['url_path'];
                if (\class_exists(ProductLabelStyle::class)) {
                    $labelFlags = ProductLabelStyle::resolveFlags($product, (int)$index);
                    $product['is_demo'] = !empty($labelFlags['is_demo']);
                    $product['is_new'] = !empty($labelFlags['is_new']);
                    $product['is_sale'] = !empty($labelFlags['is_sale']);
                }
                $bag[$productId] = $product;
            }
            // Remember misses as empty so we do not re-query the same id this request.
            foreach ($missing as $productId) {
                if (!isset($bag[$productId])) {
                    $bag[$productId] = [];
                }
            }
            if (RequestContext::isInitialized()) {
                RequestContext::set($bagKey, $bag);
            }
        }

        $ordered = [];
        foreach ($orderedIds as $productId) {
            $row = $bag[$productId] ?? null;
            if (\is_array($row) && $row !== [] && (int)($row['id'] ?? 0) > 0) {
                $ordered[] = $row;
            }
        }

        return $ordered;
    }

    /**
     * Widget / pre-mapped card batch path: product-card-shaped arrays → HTML in one
     * measurePhase. Skips per-card Taglib and per-card fragment/fetch (native batch).
     * Caller owns pricing/rating hydration (e.g. StorefrontProductWidgetCatalog::cardsByIds).
     *
     * @param list<mixed> $products
     * @param array<string, mixed> $options
     */
    public static function projectFromProducts(array $products, array $options = []): string
    {
        $flags = self::normalizeOptions($options);
        $html = (string)TraceGate::measurePhase(
            'product.card.render',
            static function () use ($products, $flags): string {
                return self::renderNormalizedProductsHtml($products, $flags);
            },
            [
                'density' => $flags['density'],
                'show_price' => $flags['show_price'],
                'show_add_to_cart' => $flags['show_add_to_cart'],
                'show_sku' => $flags['show_sku'],
                'batch' => true,
                'product_count' => \count($products),
            ],
        );

        return self::emitStylesheetLinkOnce() . $html;
    }

    /**
     * Same batch SSR as projectFromProducts, keyed by product_id for dialog/shelf
     * reuse without re-entering Taglib or re-rendering the same SKU twice.
     *
     * @param list<mixed> $products
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public static function projectHtmlByProductId(array $products, array $options = []): array
    {
        $flags = self::normalizeOptions($options);

        /** @var array<int, string> $map */
        $map = TraceGate::measurePhase(
            'product.card.render',
            static function () use ($products, $flags): array {
                $unique = [];
                $index = 0;
                foreach ($products as $product) {
                    if (!\is_array($product)) {
                        continue;
                    }
                    if (!\array_key_exists('card_index', $product)) {
                        $product['card_index'] = $index;
                    }
                    $productId = max(0, (int)($product['product_id'] ?? $product['id'] ?? 0));
                    if ($productId <= 0) {
                        ++$index;
                        continue;
                    }
                    if (($flags['density'] ?? 'standard') === 'shelf'
                        && empty($product['currency_unavailable'])
                        && ((float)($product['price'] ?? 0) <= 0 || !empty($product['quote_only']))
                    ) {
                        ++$index;
                        continue;
                    }
                    if (!isset($unique[$productId])) {
                        $unique[$productId] = $product;
                    }
                    ++$index;
                }

                $concat = self::renderNormalizedProductsHtml(\array_values($unique), $flags);
                $out = [];
                foreach ($unique as $productId => $_) {
                    $out[$productId] = self::extractCardArticleHtml($concat, (int)$productId);
                }

                return $out;
            },
            [
                'density' => $flags['density'],
                'show_price' => $flags['show_price'],
                'show_add_to_cart' => $flags['show_add_to_cart'],
                'show_sku' => $flags['show_sku'],
                'batch' => true,
                'keyed' => true,
                'product_count' => \count($products),
            ],
        );

        return \is_array($map) ? $map : [];
    }

    /**
     * Batch native SSR: one Template::fetch of the batch shell, then include the
     * already-compiled card partial per product (no per-card fetch_html / fragment).
     *
     * @param list<mixed> $products
     * @param array<string, mixed> $flags
     */
    private static function renderNormalizedProductsHtml(array $products, array $flags): string
    {
        $normalized = [];
        $index = 0;
        foreach ($products as $product) {
            if (!\is_array($product)) {
                continue;
            }
            if (!\array_key_exists('card_index', $product)) {
                $product['card_index'] = $index;
            }
            $productId = max(0, (int)($product['id'] ?? $product['product_id'] ?? 0));
            if ($productId <= 0) {
                ++$index;
                continue;
            }
            if (($flags['density'] ?? 'standard') === 'shelf'
                && empty($product['currency_unavailable'])
                && ((float)($product['price'] ?? 0) <= 0 || !empty($product['quote_only']))
            ) {
                ++$index;
                continue;
            }
            $row = self::normalizeProduct($product);
            // Match former fragment-neutral bag: lazy in markup; stamp eager after batch.
            unset($row['card_index']);
            $row['image_loading'] = 'lazy';
            $normalized[] = $row;
            ++$index;
        }

        if ($normalized === []) {
            return '';
        }

        TemplateRenderRouteTrace::mark(
            'card_batch',
            'native:' . \count($normalized),
            ['density' => (string)($flags['density'] ?? '')]
        );

        $html = self::buildCardsBatchHtml($normalized, $flags);
        for ($i = 0; $i < self::INITIAL_VIEWPORT_IMAGE_COUNT; ++$i) {
            $html = self::stampImageLoadingAttributes($html, $i);
        }

        return $html;
    }

    /**
     * One fetch of the batch shell; card markup comes from a single compiled include path.
     *
     * @param list<array<string, mixed>> $products Already normalized.
     * @param array<string, mixed> $flags
     */
    private static function buildCardsBatchHtml(array $products, array $flags): string
    {
        $template = Template::getInstance();
        $partial = ($flags['density'] ?? 'standard') === 'shelf'
            ? 'Weline_Product::templates/frontend/partials/product-card-shelf.phtml'
            : 'Weline_Product::templates/frontend/partials/product-card.phtml';

        try {
            $compiledCard = $template->getFetchFile($partial);
        } catch (\Throwable) {
            // Fallback: sequential body (still no fragment) if compile resolve fails.
            // Caller stamps eager budget once across the concatenated HTML.
            $parts = [];
            foreach ($products as $product) {
                $parts[] = self::buildCardPartialHtml($product, $flags, true);
            }

            return \implode('', $parts);
        }

        return (string)$template->fetchHtml(
            'Weline_Product::templates/frontend/partials/product-cards-batch.phtml',
            [
                'products' => $products,
                'compiled_card_file' => $compiledCard,
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
                'defer_card_css' => true,
            ]
        );
    }

    /**
     * Split keyed batch HTML back into per-id articles (data-product-id root).
     */
    private static function extractCardArticleHtml(string $html, int $productId): string
    {
        if ($html === '' || $productId <= 0) {
            return '';
        }
        $pattern = '/<article\b[^>]*\bdata-product-id="' . $productId . '"[^>]*>.*?<\/article>/is';
        if (\preg_match($pattern, $html, $match) === 1 && isset($match[0]) && \is_string($match[0])) {
            return $match[0];
        }

        return '';
    }

    /**
     * Consume one page-level eager image slot when budget remains.
     * Used only by {@see stampImageLoadingAttributes()} (v9: not part of fragment key).
     */
    private static function tryAllocateEagerImageSlot(): bool
    {
        $used = max(0, (int)RequestContext::get(self::EAGER_IMAGE_BUDGET_KEY, 0));
        if ($used >= self::INITIAL_VIEWPORT_IMAGE_COUNT) {
            return false;
        }
        RequestContext::set(self::EAGER_IMAGE_BUDGET_KEY, $used + 1);
        if ($used === 0 && RequestContext::isInitialized()) {
            RequestContext::onCaptureDiscard(
                static function (): void {
                    RequestContext::remove(self::EAGER_IMAGE_BUDGET_KEY);
                    RequestContext::removeCaptureDiscard(self::EAGER_IMAGE_BUDGET_DISCARD_HOOK);
                },
                self::EAGER_IMAGE_BUDGET_DISCARD_HOOK
            );
        }

        return true;
    }

    /**
     * Shared shelf flags for homepage page-prefetch + worker bag prime.
     * Must stay aligned with featured/deals/hot widget Taglib defaults.
     *
     * @return array<string, mixed>
     */
    public static function homepageShelfFragmentRenderOptions(): array
    {
        return [
            'density' => 'shelf',
            'show_price' => true,
            'show_rating' => true,
            'show_add_to_cart' => true,
            'show_wishlist' => true,
            'show_compare' => true,
            'show_quickview' => true,
            'show_labels' => true,
            'wishlist_pixel' => true,
        ];
    }

    /**
     * Homepage new-arrivals widget flags for page-prefetch fragment identity.
     * Matches layout homepage params (no rating/wishlist/compare/quickview/labels).
     *
     * @return array<string, mixed>
     */
    public static function homepageNewArrivalsFragmentRenderOptions(): array
    {
        return [
            'density' => 'shelf',
            'show_price' => true,
            'show_rating' => false,
            'show_add_to_cart' => true,
            'show_wishlist' => false,
            'show_compare' => false,
            'show_quickview' => false,
            'show_labels' => false,
            'wishlist_pixel' => true,
        ];
    }

    /**
     * Fragment-cached card body (no CSS wrapper; caller owns emitStylesheetLinkOnce).
     *
     * v9: always normalize before peek/remember so catalog raw, widget
     * splitForTaglib, and prime share one fragment identity. Bag HTML stores
     * lazy images; eager/high is stamped after HIT/MISS from the page budget.
     *
     * @param array<string, mixed> $product Card bag (may be pre-normalized).
     * @param array<string, mixed> $flags
     */
    private static function renderCachedBody(array $product, array $flags): string
    {
        $stampIndex = \array_key_exists('card_index', $product)
            ? max(0, (int)$product['card_index'])
            : null;

        // HARD: identity bag is always normalized before any fragment key lookup.
        $normalized = self::normalizeProduct($product);
        if ($stampIndex !== null) {
            $normalized['card_index'] = $stampIndex;
        }

        try {
            if (\class_exists(\Weline\Theme\Service\StorefrontProductCardFragmentCache::class)) {
                /** @var \Weline\Theme\Service\StorefrontProductCardFragmentCache $cardCache */
                $cardCache = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Theme\Service\StorefrontProductCardFragmentCache::class
                );

                // HIT light path: skip Template when fingerprint already warmed.
                $peeked = $cardCache->peekCardHtml($normalized, $flags);
                if ($peeked !== null) {
                    TemplateRenderRouteTrace::mark(
                        'card_fragment',
                        'HIT:' . (int)($normalized['product_id'] ?? $normalized['id'] ?? 0),
                        ['density' => (string)($flags['density'] ?? '')]
                    );

                    return self::stampImageLoadingAttributes($peeked, $stampIndex);
                }

                TemplateRenderRouteTrace::mark(
                    'card_fragment',
                    'MISS:' . (int)($normalized['product_id'] ?? $normalized['id'] ?? 0),
                    ['density' => (string)($flags['density'] ?? '')]
                );
                $html = $cardCache->rememberCardHtml(
                    $normalized,
                    $flags,
                    static function () use ($normalized, $flags): string {
                        return self::buildCardPartialHtml($normalized, $flags, true);
                    },
                );

                return self::stampImageLoadingAttributes($html, $stampIndex);
            }
        } catch (\Throwable) {
            // Theme optional / Policy unavailable → plain render.
        }

        return self::stampImageLoadingAttributes(
            self::buildCardPartialHtml($normalized, $flags, false),
            $stampIndex,
        );
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $flags
     * @param bool $fragmentNeutral When true, force lazy loading into the cached body.
     */
    private static function buildCardPartialHtml(array $normalized, array $flags, bool $fragmentNeutral): string
    {
        $template = Template::getInstance();
        $partial = ($flags['density'] ?? 'standard') === 'shelf'
            ? 'Weline_Product::templates/frontend/partials/product-card-shelf.phtml'
            : 'Weline_Product::templates/frontend/partials/product-card.phtml';
        $tplProduct = $normalized;
        if ($fragmentNeutral) {
            unset($tplProduct['card_index']);
            $tplProduct['image_loading'] = 'lazy';
        }

        // CSS is request-once outside the Policy bag so HIT bodies never
        // embed/omit a stale <style> relative to emitStylesheetLinkOnce().
        return (string)$template->fetchHtml(
            $partial,
            [
                'product' => $tplProduct,
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
                'defer_card_css' => true,
            ]
        );
    }

    /**
     * Apply page-level eager/lazy image hints onto fragment HTML after cache HIT/MISS.
     *
     * @param int|null $cardIndex Local list index (null → always leave lazy bag as-is / no eager).
     */
    public static function stampImageLoadingAttributes(string $html, ?int $cardIndex): string
    {
        if ($html === '' || !\str_contains($html, 'wpc-image')) {
            return $html;
        }
        $wantsEager = $cardIndex !== null && $cardIndex < self::INITIAL_VIEWPORT_IMAGE_COUNT;
        if (!$wantsEager || !self::tryAllocateEagerImageSlot()) {
            return $html;
        }

        // First product-card image only: loading=lazy → eager + fetchpriority=high.
        // Dotall-ish via [^>]: multiline shelf <img> tags keep class before loading.
        $replaced = \preg_replace(
            '/(<img\b[^>]*\bclass="[^"]*\bwpc-image\b[^"]*"[^>]*\bloading=")lazy(")/is',
            '$1eager$2 fetchpriority="high"',
            $html,
            1,
        );
        if (!\is_string($replaced) || $replaced === $html) {
            // Fallback: loading may appear before class= in some partials.
            $replaced = \preg_replace(
                '/(<img\b[^>]*\bloading=")lazy("[^>]*\bclass="[^"]*\bwpc-image\b)/is',
                '$1eager$2 fetchpriority="high"',
                $html,
                1,
            );
        }

        return \is_string($replaced) ? $replaced : $html;
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

        // Widgets often call ProductCardUrl::splitForTaglib first, which moves a
        // relative SEO path into url_path and clears url. Prefer url → url_path →
        // slug before falling back to /product/{id} (numeric IDs are not SEO).
        $route = trim((string)($product['url'] ?? ''));
        $urlPath = trim((string)($product['url_path'] ?? ''));
        $slug = strtolower(trim((string)($product['slug'] ?? '')));
        if ($route === '' && $urlPath !== '') {
            $route = $urlPath;
        }
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
        // Path-only PDP: drop offer/axis identity query even from stale bag URLs.
        $querySuffix = self::sanitizeStorefrontCardQuery($querySuffix);
        $link = ProductCardUrl::splitForTaglib($route);
        if ($link['url'] !== '') {
            $product['url'] = $link['url'] . $querySuffix;
            $product['url_path'] = '';
        } else {
            $path = trim((string)$link['url_path']);
            if ($path === '') {
                $product['url'] = '';
                $product['url_path'] = '';
            } else {
                // Keep route path for consumers; bake href with website mount + locale
                // (Url::getPrefix alone omits /daocharms-style mounts and breaks cards).
                $product['url_path'] = $path;
                $product['url'] = self::buildStorefrontCardHref($path, $querySuffix);
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
        // Storefront hydrate cards (recently-viewed / you-may-like) must reuse the
        // same glyph formatting as SSR product-card.phtml — never bare amounts.
        if (\class_exists(\Weline\Currency\Helper\CurrencySymbol::class)) {
            $product['currency_symbol'] = \Weline\Currency\Helper\CurrencySymbol::forCode(
                (string)$product['currency']
            );
            $product['formatted_price'] = !$product['currency_unavailable'] && (float)$product['price'] > 0
                ? \Weline\Currency\Helper\CurrencySymbol::formatAmount(
                    (float)$product['price'],
                    (string)$product['currency']
                )
                : '';
        } else {
            $product['currency_symbol'] = (string)$product['currency'];
            $product['formatted_price'] = !$product['currency_unavailable'] && (float)$product['price'] > 0
                ? ((string)$product['currency'] . ' ' . \number_format((float)$product['price'], 2, '.', ''))
                : '';
        }
        $product['rating'] = (float)($product['rating'] ?? 0);
        $product['review_count'] = (int)($product['review_count'] ?? 0);
        $product['is_new'] = !empty($product['is_new']);
        $product['is_sale'] = !empty($product['is_sale']);
        $product['is_demo'] = !empty($product['is_demo']);
        $product['is_hot'] = !empty($product['is_hot']) || !empty($product['is_bestseller']);
        $product['free_shipping'] = !empty($product['free_shipping']) || !empty($product['is_free_shipping']);
        $product['discount_percent'] = max(0, min(90, (int)($product['discount_percent'] ?? 0)));
        if ($product['discount_percent'] <= 0) {
            $price = (float)$product['price'];
            $original = (float)$product['original_price'];
            if ($original > $price && $price > 0) {
                $product['discount_percent'] = max(0, min(90, (int)round((1 - ($price / $original)) * 100)));
            }
        }
        if ($product['discount_percent'] > 0) {
            $product['is_sale'] = true;
        }
        $product['sellable'] = !empty($product['sellable']);
        $product['quote_only'] = !empty($product['quote_only']);
        $product['needs_selection'] = !empty($product['needs_selection']);
        // WO-BUILD-OPS-02-HOME：零价/缺价不得当正常售价；归一为询价不可购
        if (!$product['currency_unavailable'] && (float)$product['price'] <= 0) {
            $product['quote_only'] = true;
            $product['sellable'] = false;
        }
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
        if (isset($product['image_loading'])) {
            $explicit = \strtolower(\trim((string)$product['image_loading']));
            if ($explicit === 'lazy') {
                return ['loading' => 'lazy'];
            }
            if ($explicit === 'eager') {
                return [
                    'loading' => 'eager',
                    'fetchpriority' => 'high',
                ];
            }
        }
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
            'is_hot' => !empty($offer['is_hot']) || !empty($offer['is_bestseller']),
            'is_free_shipping' => !empty($offer['is_free_shipping'])
                || !empty($offer['free_shipping'])
                || (($offer['fulfillment_metadata']['is_free_shipping'] ?? '') === '1')
                || !empty($offer['fulfillment_metadata']['is_free_shipping']),
            'free_shipping_min_amount' => max(
                0.0,
                (float)($offer['free_shipping_min_amount']
                    ?? ($offer['fulfillment_metadata']['free_shipping_min_amount'] ?? 0)),
            ),
            'discount_percent' => $hasDeal && $originalPrice > $price && $price > 0
                ? max(0, min(90, (int)round((1 - ($price / $originalPrice)) * 100)))
                : 0,
            'sellable' => !empty($offer['sellable']) && $priceMinor > 0 && empty($offer['quote_only']),
            'currency_unavailable' => !empty($offer['currency_unavailable']),
            'quote_only' => !empty($offer['quote_only']) || $priceMinor <= 0,
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
            'show_rating' => self::toBool($options['show_rating'] ?? false, false),
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

    /**
     * Build a storefront card href that includes the website mount path
     * (e.g. /daocharms) plus any non-default locale/currency prefix.
     *
     * Prefer Template::getUrl when a request is available; fall back to
     * mount + Url::getPrefix so CLI/unit still produce site-rooted paths.
     */
    /**
     * Keep marketing/quote flags only; never emit offer UUID or axis deep links.
     */
    public static function sanitizeStorefrontCardQuery(string $querySuffix): string
    {
        $querySuffix = trim($querySuffix);
        if ($querySuffix === '') {
            return '';
        }
        $params = [];
        parse_str(ltrim($querySuffix, '?&'), $params);
        if (!\is_array($params) || $params === []) {
            return '';
        }
        $keep = [];
        foreach ($params as $key => $value) {
            $key = strtolower(trim((string)$key));
            if ($key === 'quote'
                || $key === StorefrontCampaignEntry::QUERY_CAMPAIGN
                || $key === StorefrontCampaignEntry::QUERY_THEME_ID
            ) {
                if (\is_scalar($value) && trim((string)$value) !== '') {
                    $keep[$key] = trim((string)$value);
                }
            }
        }

        return $keep === [] ? '' : '?' . http_build_query($keep);
    }

    public static function buildStorefrontCardHref(string $path, string $querySuffix = ''): string
    {
        $querySuffix = self::sanitizeStorefrontCardQuery($querySuffix);
        $raw = trim($path);
        if ($raw === '') {
            return '';
        }

        // Already absolute / protocol-relative: keep as-is (optional query merge).
        if (preg_match('#^(?:https?:)?//#i', $raw) === 1) {
            if ($querySuffix === '') {
                return $raw;
            }
            $join = str_contains($raw, '?') ? '&' : '?';

            return $raw . $join . ltrim($querySuffix, '?&');
        }

        $queryParams = [];
        if (str_contains($raw, '?')) {
            [$raw, $embeddedQuery] = explode('?', $raw, 2);
            parse_str(trim($embeddedQuery), $queryParams);
            if (!\is_array($queryParams)) {
                $queryParams = [];
            }
        }
        if ($querySuffix !== '') {
            $extra = [];
            parse_str(ltrim($querySuffix, '?'), $extra);
            if (\is_array($extra) && $extra !== []) {
                $queryParams = array_merge($queryParams, $extra);
            }
        }

        // Idempotent: strip current website mount + locale/currency prefix so
        // catalog→widget→card re-entry never produces /daocharms/daocharms/...
        $path = ltrim($raw, '/');
        $mount = '';
        try {
            $mount = trim(Url::resolveCurrentWebsiteMountPath(), '/');
        } catch (\Throwable) {
            $mount = '';
        }
        $locale = '';
        try {
            $locale = trim(Url::getPrefix(), '/');
        } catch (\Throwable) {
            $locale = '';
        }
        if ($mount !== '' && (strcasecmp($path, $mount) === 0 || stripos($path, $mount . '/') === 0)) {
            $path = ltrim(substr($path, strlen($mount)), '/');
        }
        if ($locale !== '' && (strcasecmp($path, $locale) === 0 || stripos($path, $locale . '/') === 0)) {
            $path = ltrim(substr($path, strlen($locale)), '/');
        }
        if ($path === '') {
            return '';
        }

        try {
            $href = (string)Template::getInstance()->getUrl($path, $queryParams);
            if ($href !== '') {
                return $href;
            }
        } catch (\Throwable) {
            // Template / request unavailable (CLI, isolated unit) → static fallback.
        }

        $segments = [];
        if ($mount !== '') {
            $segments[] = $mount;
        }
        if ($locale !== '') {
            $segments[] = $locale;
        }
        $segments[] = $path;
        $href = '/' . implode('/', $segments);
        if ($queryParams !== []) {
            $href .= '?' . http_build_query($queryParams);
        }

        return $href;
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
     * External CSS owned by the canonical product card.
     */
    public static function buildProductCardStyleTag(): string
    {
        $url = (string)Template::getInstance()->fetchTagSource(
            DataInterface::dir_type_STATICS,
            'Weline_Product::css/frontend/product-card.css'
        );
        if ($url === '') {
            return '';
        }

        $instanceStyles = '';
        foreach (['css/widgets/widget-instance-styles.css', 'js/widgets/widget-instance-styles.js'] as $asset) {
            $assetUrl = htmlspecialchars((string)Template::getInstance()->fetchTagSource(
                DataInterface::dir_type_STATICS, 'Weline_Theme::' . $asset
            ), ENT_QUOTES, 'UTF-8');
            $instanceStyles .= str_ends_with($asset, '.css')
                ? '<link rel="stylesheet" data-weline-widget-asset="source" data-weline-source-position="head" href="' . $assetUrl . '">'
                : '<script defer data-weline-widget-asset="source" data-weline-source-position="head" src="' . $assetUrl . '"></script>';
        }

        return '<link rel="stylesheet" ' . self::CSS_LINK_MARKER . '="1" '
            . 'data-weline-widget-asset="source" data-weline-source-position="head" '
            . 'data-weline-product-card-version="' . htmlspecialchars(self::CSS_VERSION, ENT_QUOTES, 'UTF-8') . '" '
            . 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n" . $instanceStyles;
    }

    /**
     * Fill rating/review_count from approved Review aggregates (optional soft dep).
     *
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private static function hydrateReviewAggregates(array $products): array
    {
        if ($products === [] || !interface_exists(\Weline\Review\Api\ReviewSeoFactsInterface::class)) {
            return $products;
        }

        $uuids = [];
        foreach ($products as $product) {
            $uuid = trim((string)($product['global_offer_uuid'] ?? ''));
            if ($uuid !== '') {
                $uuids[$uuid] = true;
            }
        }
        if ($uuids === []) {
            return $products;
        }

        try {
            $reviews = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Review\Api\ReviewSeoFactsInterface::class
            );
            if (!\is_object($reviews) || !method_exists($reviews, 'aggregatesForExternalUuids')) {
                return $products;
            }
            /** @var array<string, array{review_count?:int, average_rating?:float}> $aggregates */
            $aggregates = $reviews->aggregatesForExternalUuids('product', array_keys($uuids));
        } catch (\Throwable) {
            return $products;
        }

        foreach ($products as &$product) {
            $uuid = trim((string)($product['global_offer_uuid'] ?? ''));
            if ($uuid === '' || !isset($aggregates[$uuid]) || !\is_array($aggregates[$uuid])) {
                $product['rating'] = 0.0;
                $product['review_count'] = 0;
                continue;
            }
            $count = max(0, (int)($aggregates[$uuid]['review_count'] ?? 0));
            $rating = $count > 0 ? max(0.0, (float)($aggregates[$uuid]['average_rating'] ?? 0)) : 0.0;
            $product['rating'] = $rating;
            $product['review_count'] = $count;
        }
        unset($product);

        return $products;
    }

    /**
     * 商品图 alt：去掉末尾音译括号并限长，避免把“英文名 + 拼音”整串塞进 alt。
     */
    public static function imageAlt(string $name): string
    {
        $alt = \trim((string)\preg_replace('/\s+/u', ' ', $name));
        $stripped = \preg_replace('/\s*\[[A-Za-z0-9 ,.\'’&\-]{20,}\]\s*$/u', '', $alt);
        if (\is_string($stripped) && \trim($stripped) !== '') {
            $alt = \trim($stripped);
        }
        if (\mb_strlen($alt, 'UTF-8') > 120) {
            $alt = \mb_substr($alt, 0, 120, 'UTF-8');
            $space = \mb_strrpos($alt, ' ');
            if ($space !== false && $space >= 60) {
                $alt = \mb_substr($alt, 0, $space, 'UTF-8');
            }
            $alt = \rtrim($alt, " ,.;:，。；：");
        }
        return $alt !== '' ? $alt : \trim($name);
    }
}
