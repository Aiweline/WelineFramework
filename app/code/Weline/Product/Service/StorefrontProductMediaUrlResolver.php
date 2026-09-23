<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Throwable;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Data\WebsiteData;

/**
 * Resolves durable FileManager references only at the storefront presentation boundary.
 */
final class StorefrontProductMediaUrlResolver
{
    private const ASSET_PREFIX = 'asset://';
    private const DESCRIPTION_ALLOWED_TAGS = [
        'div', 'p', 'br', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'strong', 'em', 'span',
    ];
    private const DESCRIPTION_DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'textarea', 'select', 'option', 'svg', 'math', 'video',
        'audio', 'source', 'link', 'meta', 'base',
    ];

    /** @var array<string, string> */
    private array $resolvedReferenceCache = [];

    public function __construct(
        private readonly FileAssetManagerInterface $assets,
    ) {
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    public function resolveOffer(array $offer, ScopeIdentity $scope, string $locale): array
    {
        return $this->resolveMedia($offer, $scope, $locale, true);
    }

    /**
     * Resolve card media without loading every asset embedded in the PDP body.
     * Raw description remains available as data; rendered description_html is detail-only.
     *
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    public function resolveListingOffer(array $offer, ScopeIdentity $scope, string $locale): array
    {
        return $this->resolveMedia($offer, $scope, $locale, false);
    }

    private function resolveMedia(array $offer, ScopeIdentity $scope, string $locale, bool $includeDescription): array
    {
        $resolvedByReference = [];
        $resolve = function (string $reference) use (&$resolvedByReference, $scope, $locale): string {
            $reference = trim($reference);
            if ($reference === '') {
                return '';
            }
            if (!array_key_exists($reference, $resolvedByReference)) {
                $resolvedByReference[$reference] = $this->resolveReference(
                    $reference,
                    $scope,
                    $locale,
                );
            }

            return $resolvedByReference[$reference];
        };
        $resolveList = static function (mixed $references) use ($resolve): array {
            if (!is_array($references)) {
                return [];
            }
            $resolved = [];
            foreach ($references as $reference) {
                $url = $resolve(trim((string)$reference));
                if ($url !== '') {
                    $resolved[$url] = $url;
                }
            }

            return array_values($resolved);
        };

        $primaryReference = trim((string)($offer['image'] ?? ''));
        $references = $primaryReference !== '' ? [$primaryReference] : [];
        foreach ((array)($offer['images'] ?? []) as $reference) {
            $reference = trim((string)$reference);
            if ($reference !== '' && !in_array($reference, $references, true)) {
                $references[] = $reference;
            }
        }

        $resolvedImages = $resolveList($references);
        $primary = $resolve($primaryReference);
        if ($primary === '' && $resolvedImages !== []) {
            $primary = $resolvedImages[0];
        }

        $variantAxes = $offer['variant_axes'] ?? null;
        if (is_array($variantAxes)) {
            foreach ($variantAxes as &$axis) {
                if (!is_array($axis) || !is_array($axis['options'] ?? null)) {
                    continue;
                }
                foreach ($axis['options'] as &$option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $swatchImage = $resolve(trim((string)($option['swatch_image'] ?? '')));
                    if ($swatchImage === '') {
                        unset($option['swatch_image']);
                    } else {
                        $option['swatch_image'] = $swatchImage;
                    }

                    $galleryImages = $resolveList($option['gallery_images'] ?? null);
                    if ($galleryImages === []) {
                        unset($option['gallery_images']);
                    } else {
                        $option['gallery_images'] = $galleryImages;
                    }

                    $galleryByColor = [];
                    foreach (is_array($option['gallery_by_color'] ?? null)
                        ? $option['gallery_by_color']
                        : [] as $color => $gallery
                    ) {
                        $resolvedGallery = $resolveList($gallery);
                        if ($resolvedGallery !== []) {
                            $galleryByColor[(string)$color] = $resolvedGallery;
                        }
                    }
                    if ($galleryByColor === []) {
                        unset($option['gallery_by_color']);
                    } else {
                        $option['gallery_by_color'] = $galleryByColor;
                    }
                }
                unset($option);
            }
            unset($axis);
            $offer['variant_axes'] = $variantAxes;
        }

        $offer['image'] = $primary;
        $offer['images'] = $resolvedImages;

        $videos = [];
        foreach (is_array($offer['videos'] ?? null) ? $offer['videos'] : [] as $video) {
            if (!is_array($video)) {
                continue;
            }
            $provider = strtolower(trim((string)($video['provider'] ?? '')));
            $embedUrl = trim((string)($video['embed_url'] ?? $video['src'] ?? ''));
            $path = trim((string)($video['path'] ?? ''));
            $assetId = strtolower(trim((string)($video['asset_id'] ?? '')));
            if ($provider === 'file' || str_starts_with($embedUrl, self::ASSET_PREFIX) || str_starts_with($path, self::ASSET_PREFIX)) {
                $resolvedSrc = $resolve($embedUrl !== '' ? $embedUrl : $path);
                if ($resolvedSrc === '' && $assetId !== '') {
                    $resolvedSrc = $resolve(self::ASSET_PREFIX . $assetId);
                }
                if ($resolvedSrc === '') {
                    continue;
                }
                $embedUrl = $resolvedSrc;
            }
            if ($embedUrl === '') {
                continue;
            }
            $poster = trim((string)($video['poster'] ?? $video['poster_url'] ?? ''));
            if ($poster !== '' && str_starts_with($poster, self::ASSET_PREFIX)) {
                $poster = $resolve($poster);
            }
            $videos[] = [
                'type' => 'video',
                'provider' => $provider !== '' ? $provider : 'file',
                'provider_id' => trim((string)($video['provider_id'] ?? '')),
                'path' => $path,
                'asset_id' => $assetId,
                'src' => $embedUrl,
                'embed_url' => $embedUrl,
                'watch_url' => trim((string)($video['watch_url'] ?? '')),
                'poster' => $poster,
                'mime_type' => trim((string)($video['mime_type'] ?? '')),
                'position' => (int)($video['position'] ?? 0),
            ];
        }
        if ($videos === []) {
            unset($offer['videos']);
        } else {
            $offer['videos'] = $videos;
        }

        $descriptionHtml = $includeDescription
            ? self::renderDescriptionHtml((string)($offer['description'] ?? ''), $resolve)
            : '';
        if ($descriptionHtml === '') {
            unset($offer['description_html']);
        } else {
            $offer['description_html'] = $descriptionHtml;
        }

        return $offer;
    }

    /**
     * Resolve a cached catalog projection before it reaches any storefront widget.
     *
     * @param list<array<string, mixed>> $offers
     * @return list<array<string, mixed>>
     */
    public function resolveOffers(array $offers, ScopeIdentity $scope, string $locale): array
    {
        return array_values(array_map(
            fn(array $offer): array => $this->resolveOffer($offer, $scope, $locale),
            $offers,
        ));
    }

    public function resolveReference(string $reference, ScopeIdentity $scope, string $locale): string
    {
        $reference = trim($reference);
        if ($reference === '' || !str_starts_with(strtolower($reference), self::ASSET_PREFIX)) {
            return $reference;
        }

        $assetId = trim(substr($reference, strlen(self::ASSET_PREFIX)));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $assetId) !== 1) {
            return '';
        }

        $cacheKey = $this->referenceCacheKey($assetId, $scope, $locale);
        if (array_key_exists($cacheKey, $this->resolvedReferenceCache)) {
            return $this->resolvedReferenceCache[$cacheKey];
        }

        // Product pixels are factual media, not translated copy. Keep the target
        // locale metadata first, then reuse reviewed English / Website-default
        // metadata instead of turning a real catalog image into a placeholder.
        $resolvedUrl = '';
        foreach ($this->localeCandidates($locale) as $candidateLocale) {
            try {
                $this->assets->locale($assetId, $candidateLocale);

                $candidateUrl = trim($this->assets->resolveUrl(
                    $assetId,
                    new FileAccessContext(
                        scope: $scope,
                        localeCode: $candidateLocale,
                        purpose: FileAccessContext::PURPOSE_PUBLIC_PUBLISH,
                    ),
                )->url);
                if ($candidateUrl === '') {
                    continue;
                }
                $resolvedUrl = $candidateUrl;
                break;
            } catch (Throwable) {
                continue;
            }
        }

        $this->resolvedReferenceCache[$cacheKey] = $resolvedUrl;

        return $resolvedUrl;
    }

    /** @param array<array-key,string> $references @return array<array-key,string> */
    public function resolveReferences(array $references, ScopeIdentity $scope, string $locale): array
    {
        if (!$this->assets instanceof \Weline\FileManager\Api\FileAssetBatchUrlResolverInterface) {
            $result = [];
            foreach ($references as $key => $reference) {
                $result[$key] = $this->resolveReference($reference, $scope, $locale);
            }
            return $result;
        }

        $result = [];
        $requests = [];
        $contexts = null;
        foreach ($references as $key => $reference) {
            $reference = trim($reference);
            $result[$key] = $reference;
            if ($reference === '' || !str_starts_with(strtolower($reference), self::ASSET_PREFIX)) {
                continue;
            }
            $result[$key] = '';
            $assetId = trim(substr($reference, strlen(self::ASSET_PREFIX)));
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $assetId) !== 1) {
                continue;
            }
            $cacheKey = $this->referenceCacheKey($assetId, $scope, $locale);
            if (array_key_exists($cacheKey, $this->resolvedReferenceCache)) {
                $result[$key] = $this->resolvedReferenceCache[$cacheKey];
                continue;
            }
            $contexts ??= array_map(
                static fn(string $candidateLocale): FileAccessContext => new FileAccessContext(
                    scope: $scope,
                    localeCode: $candidateLocale,
                    purpose: FileAccessContext::PURPOSE_PUBLIC_PUBLISH,
                ),
                $this->localeCandidates($locale),
            );
            $requests[$key] = ['asset_id' => $assetId, 'contexts' => $contexts];
        }
        if ($requests === []) {
            return $result;
        }
        try {
            $resolved = $this->assets->resolveUrls($requests);
            foreach ($requests as $key => $_request) {
                $url = trim($resolved[$key]?->url ?? '');
                $result[$key] = $url;
                $assetId = trim(substr((string)$references[$key], strlen(self::ASSET_PREFIX)));
                $this->resolvedReferenceCache[$this->referenceCacheKey($assetId, $scope, $locale)] = $url;
            }
        } catch (Throwable) {
            foreach ($requests as $key => $_request) {
                $result[$key] = $this->resolveReference($references[$key], $scope, $locale);
            }
        }
        return $result;
    }

    private function referenceCacheKey(string $assetId, ScopeIdentity $scope, string $locale): string
    {
        return strtolower($assetId) . "\0" . trim($locale) . "\0" . $scope->canonicalKey();
    }

    /**
     * Render only importer-marked, FileManager-backed rich descriptions.
     */
    public static function renderDescriptionHtml(string $html, callable $assetResolver): string
    {
        $html = trim($html);
        if ($html === ''
            || strlen($html) > 4_194_304
            || !str_contains($html, 'data-weline-product-description="1688"')
        ) {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'
                . '<div id="weline-storefront-description-root">' . $html . '</div>'
                . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return '';
        }

        $xpath = new \DOMXPath($document);
        $containers = $xpath->query('//*[@id="weline-storefront-description-root"]');
        $container = $containers !== false ? $containers->item(0) : null;
        if (!$container instanceof \DOMElement) {
            return '';
        }
        $roots = $xpath->query('.//*[@data-weline-product-description="1688"]', $container);
        $root = $roots !== false ? $roots->item(0) : null;
        if (!$root instanceof \DOMElement) {
            return '';
        }

        self::sanitizeDescriptionChildren($root, $assetResolver);
        $output = '';
        // Keep suite skip marker visible to Agents (root attrs are not serialized with children).
        $weds = trim($root->getAttribute('data-weds'));
        if ($weds === 'xq') {
            $output .= '<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>';
        }
        foreach ($root->childNodes as $child) {
            $output .= (string)$document->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * True when any img lacks a non-empty alt. Avoids DOMDocument when alts are already filled.
     */
    private static function descriptionImageNeedsAlt(string $html): bool
    {
        if (preg_match_all('/<img\b[^>]*>/i', $html, $tags) < 1) {
            return false;
        }
        foreach ($tags[0] as $tag) {
            if (preg_match('/\balt\s*=\s*(["\'])([^"\']+)\1/i', (string)$tag) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fill empty/missing img alt on already-rendered description HTML with product-name fallbacks.
     * Content photos must not ship as decorative empty alt.
     */
    public static function ensureDescriptionImageAlts(string $html, string $productName): string
    {
        $html = trim($html);
        $productName = trim($productName);
        if ($html === '' || $productName === '' || !str_contains(strtolower($html), '<img')) {
            return $html;
        }
        if (!self::descriptionImageNeedsAlt($html)) {
            return $html;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'
                . '<div id="weline-storefront-alt-root">' . $html . '</div>'
                . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($document);
        $containers = $xpath->query('//*[@id="weline-storefront-alt-root"]');
        $container = $containers !== false ? $containers->item(0) : null;
        if (!$container instanceof \DOMElement) {
            return $html;
        }

        $index = 0;
        $images = $xpath->query('.//img', $container);
        if ($images !== false) {
            foreach ($images as $img) {
                if (!$img instanceof \DOMElement) {
                    continue;
                }
                $alt = trim($img->getAttribute('alt'));
                if ($alt !== '') {
                    continue;
                }
                $index++;
                $fallback = mb_substr($productName . ' · ' . $index, 0, 180);
                $img->setAttribute('alt', $fallback);
            }
        }

        $output = '';
        foreach ($container->childNodes as $child) {
            $output .= (string)$document->saveHTML($child);
        }

        return trim($output) !== '' ? trim($output) : $html;
    }

    private static function sanitizeDescriptionChildren(\DOMNode $parent, callable $assetResolver): void
    {
        for ($child = $parent->firstChild; $child !== null; $child = $next) {
            $next = $child->nextSibling;
            if ($child instanceof \DOMComment) {
                $parent->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            // Skip-marker span is re-emitted from root data-weds in renderDescriptionHtml.
            if ($tag === 'span'
                && trim($child->getAttribute('data-weds')) === 'xq'
                && trim($child->textContent ?? '') === ''
            ) {
                $parent->removeChild($child);
                continue;
            }
            if (in_array($tag, self::DESCRIPTION_DROP_WITH_CONTENT, true)) {
                $parent->removeChild($child);
                continue;
            }
            if (!in_array($tag, self::DESCRIPTION_ALLOWED_TAGS, true)) {
                self::sanitizeDescriptionChildren($child, $assetResolver);
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }
            if ($tag === 'img') {
                $reference = trim($child->getAttribute('src'));
                $alt = mb_substr(trim($child->getAttribute('alt')), 0, 180);
                // Preserve importer/editor width+height before attribute wipe (CLS / SEO).
                $width = self::positiveIntAttr($child->getAttribute('width'));
                $height = self::positiveIntAttr($child->getAttribute('height'));
                $resolved = '';
                if (preg_match('#^asset://[a-f0-9-]{36}$#iD', $reference) === 1) {
                    try {
                        $resolved = trim((string)$assetResolver($reference));
                    } catch (Throwable) {
                        $resolved = '';
                    }
                }
                if (!self::safeDescriptionUrl($resolved)) {
                    $parent->removeChild($child);
                    continue;
                }
                self::clearDescriptionAttributes($child);
                $child->setAttribute('src', $resolved);
                $child->setAttribute('alt', $alt);
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('decoding', 'async');
                // Default square placeholder ratio matches storefront product-card; CSS height:auto keeps responsive.
                $child->setAttribute('width', (string)($width ?? 800));
                $child->setAttribute('height', (string)($height ?? 800));
                continue;
            }

            if ($tag === 'table' && self::isPromoOrRecommendedProductBlock($child)) {
                $parent->removeChild($child);
                continue;
            }

            self::clearDescriptionAttributes($child);
            self::sanitizeDescriptionChildren($child, $assetResolver);
        }
    }

    private static function clearDescriptionAttributes(\DOMElement $element): void
    {
        $class = trim($element->getAttribute('class'));
        $marker = trim($element->getAttribute('data-weline-detail-text'));
        $orient = trim($element->getAttribute('data-weline-orient'));
        $pad = trim($element->getAttribute('data-weline-pad'));
        $weds = trim($element->getAttribute('data-weds'));
        $dcHue = trim($element->getAttribute('data-dc-hue'));
        $dcHueRoot = trim($element->getAttribute('data-dc-hue-root'));
        $dcFloorStyle = self::safeDcFloorBgStyle(trim($element->getAttribute('style')));
        $hidden = $element->hasAttribute('hidden');
        $ariaHidden = trim($element->getAttribute('aria-hidden'));
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute === null) {
                break;
            }
            $element->removeAttributeNode($attribute);
        }
        $safeClass = self::safeDetailTextClass($class);
        if ($safeClass !== '') {
            $element->setAttribute('class', $safeClass);
        }
        if ($marker !== '' && preg_match('/^[a-z0-9_-]{1,40}$/D', $marker) === 1) {
            $element->setAttribute('data-weline-detail-text', $marker);
        }
        // §3.1‑B：审图画幅标记（orientation / 竖→横扩图路径）可进前台验收
        if ($orient !== '' && preg_match('/^(?:portrait|landscape|squareish|macro)$/D', $orient) === 1) {
            $element->setAttribute('data-weline-orient', $orient);
        }
        if ($pad !== '' && preg_match('/^[a-z0-9_-]{1,48}$/D', $pad) === 1) {
            $element->setAttribute('data-weline-pad', $pad);
        }
        // ecommerce-detail-suite skip marker (meaningless to buyers; Agents detect via HTML).
        if ($weds === 'xq') {
            $element->setAttribute('data-weds', 'xq');
        }
        // DaoCharms / Apple-style hue diffusion floors (背景色融合).
        if ($dcHue === 'bleed' || $dcHue === 'diffuse') {
            $element->setAttribute('data-dc-hue', $dcHue);
        }
        if ($dcHueRoot === '1') {
            $element->setAttribute('data-dc-hue-root', '1');
        }
        if ($dcFloorStyle !== '') {
            $element->setAttribute('style', $dcFloorStyle);
        }
        if ($hidden) {
            $element->setAttribute('hidden', 'hidden');
        }
        if ($ariaHidden === 'true') {
            $element->setAttribute('aria-hidden', 'true');
        }
    }

    /**
     * Only custom property --dc-floor-bg:#rrggbb (Apple-style texture wash).
     */
    private static function safeDcFloorBgStyle(string $style): string
    {
        if ($style === '') {
            return '';
        }
        if (preg_match('/^--dc-floor-bg:\s*(#[0-9a-fA-F]{6})\s*;?\s*$/D', $style, $m) !== 1) {
            return '';
        }

        return '--dc-floor-bg:' . strtolower($m[1]);
    }

    private static function positiveIntAttr(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{1,5}$/D', $value) !== 1) {
            return null;
        }
        $int = (int)$value;

        return $int >= 1 && $int <= 10000 ? $int : null;
    }

    private static function safeDetailTextClass(string $class): string
    {
        $tokens = preg_split('/\s+/', trim($class)) ?: [];
        $kept = [];
        foreach ($tokens as $token) {
            // Allow semantic text panels + storefront layout helpers used inside description HTML.
            if (preg_match(
                '/^weline-detail-(?:text|prose|feature|figure|quiet|bento)(?:-[a-z0-9]+)*(?:__[a-z0-9-]+)?(?:--[a-z0-9-]+)?$/D',
                $token,
            ) === 1) {
                $kept[] = $token;
                continue;
            }
            // Aspect-first layout markers (ecommerce-detail-suite §3.1‑B).
            if (preg_match('/^weline-detail-orient--(?:portrait|landscape|squareish|macro)$/D', $token) === 1) {
                $kept[] = $token;
                continue;
            }
            // DaoCharms hue-diffusion floor wrapper (Apple-style bg bleed).
            if ($token === 'dc-hue-floor' || $token === 'dc-hue-root') {
                $kept[] = $token;
            }
        }

        return implode(' ', array_values(array_unique($kept)));
    }

    /**
     * Drop 1688 shop promo banners and nested recommended-product price grids
     * that ride along in detail HTML (not this product's own copy/images).
     */
    private static function isPromoOrRecommendedProductBlock(\DOMElement $element): bool
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $element->textContent ?? ''));
        if ($text === '') {
            return false;
        }
        if (preg_match('/火爆大促销|狂欢购|猜你喜欢|推荐商品|店铺推荐|看了又看|同类热销|WUYIKUANHUANGOU/u', $text) === 1) {
            return true;
        }
        if (preg_match('/[￥¥]\s*\d+/u', $text) !== 1) {
            return false;
        }

        return $element->getElementsByTagName('img')->length > 0
            || preg_match('/批发/u', $text) === 1;
    }

    private static function safeDescriptionUrl(string $url): bool
    {
        if ($url === '' || str_starts_with(strtolower($url), self::ASSET_PREFIX)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string)($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }


    /**
     * @return list<string>
     */
    private function localeCandidates(string $locale): array
    {
        $candidates = [trim($locale)];
        if (strtolower(str_replace('-', '_', trim($locale))) !== 'en_us') {
            $candidates[] = 'en_US';
        }

        try {
            $candidates[] = trim((string)WebsiteData::getDefaultLanguage());
        } catch (Throwable) {
            // A missing Website context must not make legacy paths unusable.
        }

        // Catalog pixels are often authored once under the site primary locale
        // (commonly zh_Hans_CN). When WebsiteData has no default language yet —
        // CLI probes, early account AJAX, or incomplete website bind — en_US
        // alone would empty every asset:// card image into a placeholder.
        $candidates[] = 'zh_Hans_CN';

        $result = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if ($candidate === ''
                || preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/', $candidate) !== 1
            ) {
                continue;
            }
            $key = strtolower(str_replace('-', '_', $candidate));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $candidate;
        }

        return $result;
    }

    /**
     * Storefront description layout: group image runs + prose, and replace broken OCR size blobs
     * with a semantic measurement chart so 图文描述 is not a raw 1688 dump.
     */
    public static function normalizeDescriptionLayout(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        // Feature layouts are already storefront HTML. Skip DOMDocument on the PDP hot path.
        if (str_contains($html, 'weline-detail-feature')) {
            return $html;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'
                . '<div id="weline-storefront-layout-root">' . $html . '</div>'
                . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($document);
        $containers = $xpath->query('//*[@id="weline-storefront-layout-root"]');
        $container = $containers !== false ? $containers->item(0) : null;
        if (!$container instanceof \DOMElement) {
            return $html;
        }

        // Already authored as feature/figure layout — keep as-is.
        $existingFeatures = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " weline-detail-feature ")]',
            $container,
        );
        if ($existingFeatures !== false && $existingFeatures->length > 0) {
            return $html;
        }

        // Already figure-stack authored — always re-run pairing so aspect rules stay fresh
        // (e.g. collage boards must become solo after rule updates).
        $existingStacks = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " weline-detail-figure-stack ")]',
            $container,
        );
        if ($existingStacks !== false && $existingStacks->length > 0) {
            foreach ($existingStacks as $stackNode) {
                if ($stackNode instanceof \DOMElement) {
                    self::relayoutFigureStackPreservingImages($document, $stackNode);
                }
            }
            $output = '';
            foreach ($container->childNodes as $child) {
                $output .= (string)$document->saveHTML($child);
            }

            return trim($output) !== '' ? trim($output) : $html;
        }

        // Prose-only authored blocks: keep. Mixed prose+raw imgs still need grouping below.
        $hasProse = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " weline-detail-prose ")]',
            $container,
        );
        $hasRawImg = $xpath->query('.//img', $container);
        if ($hasProse !== false && $hasProse->length > 0
            && ($hasRawImg === false || $hasRawImg->length === 0)
        ) {
            return $html;
        }

        $nodes = [];
        for ($child = $container->firstChild; $child !== null; $child = $child->nextSibling) {
            if ($child instanceof \DOMText && trim($child->textContent ?? '') === '') {
                continue;
            }
            $nodes[] = $child;
        }
        if ($nodes === []) {
            return $html;
        }

        while ($container->firstChild !== null) {
            $container->removeChild($container->firstChild);
        }

        $i = 0;
        $count = count($nodes);
        while ($i < $count) {
            $node = $nodes[$i];
            if (self::isDescriptionImageNode($node)) {
                $stack = $document->createElement('div');
                $stack->setAttribute('class', 'weline-detail-figure-stack');
                while ($i < $count && self::isDescriptionImageNode($nodes[$i])) {
                    $stack->appendChild(self::unwrapLonelyImageParagraph($document, $nodes[$i]));
                    $i++;
                }
                self::layoutFigureStackAsRows($document, $stack);
                $container->appendChild($stack);
                continue;
            }

            if (self::isBrokenOcrSizeBlock($node, $nodes, $i)) {
                $consumed = 0;
                $chartHtml = self::buildChartFromBrokenOcrBlock($node, $nodes, $i, $consumed);
                $i += max(1, $consumed);
                if ($chartHtml !== '') {
                    $fragment = self::importHtmlFragment($document, $chartHtml);
                    if ($fragment !== null) {
                        $container->appendChild($fragment);
                    }
                }
                continue;
            }

            $prose = $document->createElement('div');
            $prose->setAttribute('class', 'weline-detail-prose');
            while ($i < $count
                && !self::isDescriptionImageNode($nodes[$i])
                && !self::isBrokenOcrSizeBlock($nodes[$i], $nodes, $i)
            ) {
                $prose->appendChild($nodes[$i]->cloneNode(true));
                $i++;
            }
            if ($prose->childNodes->length > 0) {
                $container->appendChild($prose);
            }
        }

        $output = '';
        foreach ($container->childNodes as $child) {
            $output .= (string)$document->saveHTML($child);
        }

        return trim($output) !== '' ? trim($output) : $html;
    }

    /**
     * Flatten nested figure/row markup back to bare <img> nodes, then re-pair.
     */
    private static function relayoutFigureStackPreservingImages(\DOMDocument $document, \DOMElement $stack): void
    {
        $images = [];
        $xpath = new \DOMXPath($document);
        $imgNodes = $xpath->query('.//img', $stack);
        if ($imgNodes !== false) {
            foreach ($imgNodes as $img) {
                if ($img instanceof \DOMElement) {
                    $images[] = $img->cloneNode(true);
                }
            }
        }
        while ($stack->firstChild !== null) {
            $stack->removeChild($stack->firstChild);
        }
        foreach ($images as $img) {
            if ($img instanceof \DOMElement) {
                $stack->appendChild($img);
            }
        }
        self::layoutFigureStackAsRows($document, $stack);
    }

    /**
     * Responsive gallery rows: pair similar portraits (1×2), keep ultra-tall/wide boards solo.
     * Guided by masonry/grid skill principles; CSS Grid (not JS masonry) for a11y DOM order.
     */
    private static function layoutFigureStackAsRows(\DOMDocument $document, \DOMElement $stack): void
    {
        $images = [];
        for ($child = $stack->firstChild; $child !== null; $child = $child->nextSibling) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'img') {
                $images[] = $child;
            }
        }
        if ($images === []) {
            return;
        }

        while ($stack->firstChild !== null) {
            $stack->removeChild($stack->firstChild);
        }

        $pending = null;
        foreach ($images as $img) {
            $ratio = self::descriptionImageAspectRatio($img);
            $pairable = self::isPairableDescriptionAspect($ratio);
            if (!$pairable) {
                if ($pending instanceof \DOMElement) {
                    $stack->appendChild(self::wrapFigureRow($document, [$pending], 'solo'));
                    $pending = null;
                }
                $stack->appendChild(self::wrapFigureRow($document, [$img], 'solo'));
                continue;
            }
            if ($pending instanceof \DOMElement) {
                $stack->appendChild(self::wrapFigureRow($document, [$pending, $img], 'pair'));
                $pending = null;
                continue;
            }
            $pending = $img;
        }
        if ($pending instanceof \DOMElement) {
            $stack->appendChild(self::wrapFigureRow($document, [$pending], 'solo'));
        }
    }

    /**
     * @param list<\DOMElement> $images
     */
    private static function wrapFigureRow(\DOMDocument $document, array $images, string $kind): \DOMElement
    {
        $row = $document->createElement('div');
        $row->setAttribute(
            'class',
            'weline-detail-figure-row weline-detail-figure-row--' . ($kind === 'pair' ? 'pair' : 'solo'),
        );
        foreach ($images as $img) {
            $figure = $document->createElement('figure');
            $figure->setAttribute('class', 'weline-detail-figure');
            $figure->appendChild($img->cloneNode(true));
            $row->appendChild($figure);
        }

        return $row;
    }

    private static function descriptionImageAspectRatio(\DOMElement $img): ?float
    {
        $src = trim($img->getAttribute('src'));
        $width = self::positiveIntAttr($img->getAttribute('width'));
        $height = self::positiveIntAttr($img->getAttribute('height'));

        if ($src !== '' && str_starts_with($src, '/pub/media/')) {
            $root = defined('BP') ? (string)BP : dirname(__DIR__, 5);
            $path = $root . $src;
            if (is_file($path)) {
                $info = @getimagesize($path);
                if (is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0) {
                    $width = (int)$info[0];
                    $height = (int)$info[1];
                    // Correct misleading square placeholders from importer defaults.
                    $img->setAttribute('width', (string)$width);
                    $img->setAttribute('height', (string)$height);
                }
            }
        }

        if ($width === null || $height === null || $height < 1) {
            return null;
        }

        return $width / $height;
    }

    private static function isPairableDescriptionAspect(?float $ratio): bool
    {
        if ($ratio === null) {
            // Unknown: prefer pairing so CSS can still do 2-col on tablet+.
            return true;
        }
        // Ultra-tall calligraphy boards and landscapes stay full-bleed solo.
        if ($ratio < 0.58 || $ratio > 1.25) {
            return false;
        }
        // Near-square / short boards are often 1688 multi-panel collages (baked
        // vertical copy + decorative waves). Keep them solo full-width so CSS
        // never squeezes/crops the internal layout into a half column.
        if ($ratio >= 0.78) {
            return false;
        }

        return true;
    }

    private static function isDescriptionImageNode(\DOMNode $node): bool
    {
        if ($node instanceof \DOMElement && strtolower($node->tagName) === 'img') {
            return true;
        }
        if (!$node instanceof \DOMElement || !in_array(strtolower($node->tagName), ['p', 'div', 'span'], true)) {
            return false;
        }
        $elementChildren = 0;
        $img = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText && trim($child->textContent ?? '') === '') {
                continue;
            }
            if (!$child instanceof \DOMElement) {
                return false;
            }
            $elementChildren++;
            if (strtolower($child->tagName) !== 'img' || $elementChildren > 1) {
                return false;
            }
            $img = $child;
        }

        return $img instanceof \DOMElement;
    }

    private static function unwrapLonelyImageParagraph(\DOMDocument $document, \DOMNode $node): \DOMNode
    {
        if ($node instanceof \DOMElement && strtolower($node->tagName) === 'img') {
            return $node->cloneNode(true);
        }
        if ($node instanceof \DOMElement) {
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement && strtolower($child->tagName) === 'img') {
                    return $child->cloneNode(true);
                }
            }
        }

        return $node->cloneNode(true);
    }

    /**
     * @param list<\DOMNode> $nodes
     */
    private static function isBrokenOcrSizeBlock(\DOMNode $node, array $nodes, int $index): bool
    {
        if (!$node instanceof \DOMElement) {
            return false;
        }
        $tag = strtolower($node->tagName);
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
        if ($tag === 'h3' && preg_match('/产品信息/u', $text) === 1 && preg_match('/[名皇]/u', $text) === 1) {
            return true;
        }
        if ($tag === 'ul' && self::ocrSizeListLooksBroken($node)) {
            $prev = $index > 0 ? $nodes[$index - 1] : null;
            if ($prev instanceof \DOMElement && strtolower($prev->tagName) === 'h3') {
                return false; // handled with heading
            }

            return true;
        }

        return false;
    }

    private static function ocrSizeListLooksBroken(\DOMElement $ul): bool
    {
        $rows = 0;
        foreach ($ul->getElementsByTagName('li') as $li) {
            $t = trim(preg_replace('/\s+/u', ' ', $li->textContent ?? '') ?? '');
            if (preg_match('/^[SMLX]{1,3}\b/u', $t) === 1 && preg_match_all('/\d{2,3}/', $t) >= 3) {
                $rows++;
            }
        }

        return $rows >= 2;
    }

    /**
     * @param list<\DOMNode> $nodes
     */
    private static function buildChartFromBrokenOcrBlock(\DOMNode $node, array $nodes, int $index, int &$consumed): string
    {
        $consumed = 1;
        $rows = [];
        $cursor = $index;
        if ($node instanceof \DOMElement && strtolower($node->tagName) === 'h3') {
            $cursor++;
            $consumed++;
        }
        $ul = $nodes[$cursor] ?? null;
        if ($ul instanceof \DOMElement && strtolower($ul->tagName) === 'ul') {
            foreach ($ul->getElementsByTagName('li') as $li) {
                $t = trim(preg_replace('/[«»""]/u', ' ', preg_replace('/\s+/u', ' ', $li->textContent ?? '') ?? '') ?? '');
                if (preg_match('/^([SMLX]{1,3})\s+(.+)$/u', $t, $m) !== 1) {
                    continue;
                }
                $nums = preg_match_all('/\d{1,3}/', $m[2], $nm) ? $nm[0] : [];
                if (count($nums) < 3) {
                    continue;
                }
                $rows[] = [$m[1], implode(' / ', $nums)];
            }
            $consumed = ($cursor - $index) + 1;
            $next = $nodes[$cursor + 1] ?? null;
            if ($next instanceof \DOMElement
                && strtolower($next->tagName) === 'p'
                && str_contains((string)$next->textContent, '手工测量')
            ) {
                $consumed++;
            }
        }
        if ($rows === []) {
            return '';
        }

        return DetailDescriptionTextifier::buildMeasurementSizeChartZh(
            [[
                'title' => '尺码参考',
                'headers' => ['尺码', '尺寸明细(cm)'],
                'rows' => $rows,
            ]],
            '尺码参考表',
            '单位：厘米（cm）。以上数值来自详情图识别，手工测量可能存在 1–3 cm 误差。',
        );
    }

    private static function importHtmlFragment(\DOMDocument $document, string $html): ?\DOMNode
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }
        $tmp = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $tmp->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'
                . '<div id="weline-import-root">' . $html . '</div>'
                . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return null;
        }
        $root = $tmp->getElementById('weline-import-root');
        if (!$root instanceof \DOMElement || $root->firstChild === null) {
            return null;
        }
        $wrapper = $document->createDocumentFragment();
        foreach (iterator_to_array($root->childNodes) as $child) {
            $wrapper->appendChild($document->importNode($child, true));
        }

        return $wrapper->childNodes->length > 0 ? $wrapper : null;
    }
}
