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

        $descriptionHtml = self::renderDescriptionHtml(
            (string)($offer['description'] ?? ''),
            $resolve,
        );
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

        $cacheKey = strtolower($assetId) . "\0" . $locale . "\0" . (string)($scope->websiteId ?? 0);
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

                $resolvedUrl = trim($this->assets->resolveUrl(
                    $assetId,
                    new FileAccessContext(
                        scope: $scope,
                        localeCode: $candidateLocale,
                        purpose: FileAccessContext::PURPOSE_PUBLIC_PUBLISH,
                    ),
                )->url);
                break;
            } catch (Throwable) {
                continue;
            }
        }

        $this->resolvedReferenceCache[$cacheKey] = $resolvedUrl;

        return $resolvedUrl;
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
                continue;
            }

            self::clearDescriptionAttributes($child);
            self::sanitizeDescriptionChildren($child, $assetResolver);
        }
    }

    private static function clearDescriptionAttributes(\DOMElement $element): void
    {
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute === null) {
                break;
            }
            $element->removeAttributeNode($attribute);
        }
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
