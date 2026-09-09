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
        foreach ($root->childNodes as $child) {
            $output .= (string)$document->saveHTML($child);
        }

        return trim($output);
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
            if (preg_match('/^weline-detail-text(?:__[a-z0-9-]+|--[a-z0-9-]+)?$/D', $token) === 1) {
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
}
