<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Builds compact SEO listing facts from storefront offer/card payloads.
 *
 * Controllers and SeoProfileProviders share this normalizer so HeadRenderer
 * receives item_list / breadcrumbs without merchant copy hardcoding.
 */
final class StorefrontSeoListingFacts
{
    /**
     * @param list<array<string, mixed>> $offers
     * @return list<array{name:string,url:string,image?:string,description?:string}>
     */
    public function itemListFromOffers(array $offers, int $limit = 24): array
    {
        $items = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $item = $this->itemFromOfferLike($offer);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
            if (count($items) >= max(1, $limit)) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<array{name:string,url:string,image?:string,description?:string}>
     */
    public function itemListFromCards(array $cards, int $limit = 24): array
    {
        return $this->itemListFromOffers($cards, $limit);
    }

    /**
     * @param list<array<string, mixed>> $crumbs label/url or name/url trails
     * @return list<array{name:string,url:string}>
     */
    public function normalizeBreadcrumbs(array $crumbs): array
    {
        $normalized = [];
        foreach ($crumbs as $crumb) {
            if (!is_array($crumb)) {
                continue;
            }
            $name = trim((string)($crumb['name'] ?? $crumb['label'] ?? $crumb['title'] ?? ''));
            $url = trim((string)($crumb['url'] ?? $crumb['href'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized[] = ['name' => $name, 'url' => $url];
        }

        return $normalized;
    }

    /**
     * @param list<array{name:string,url:string}> $trail
     * @return list<array{name:string,url:string}>
     */
    public function withHomeBreadcrumb(array $trail, string $homeLabel = '', string $homeUrl = '/'): array
    {
        $homeLabel = trim($homeLabel) !== '' ? trim($homeLabel) : (string)\__('首页');
        $homeUrl = trim($homeUrl) !== '' ? trim($homeUrl) : '/';
        $hasHome = false;
        foreach ($trail as $crumb) {
            $url = trim((string)($crumb['url'] ?? ''));
            if ($url === '/' || $url === '' || preg_match('#^https?://[^/]+/?$#i', $url)) {
                $hasHome = true;
                break;
            }
        }
        if ($hasHome) {
            return $trail;
        }

        return array_values(array_merge([['name' => $homeLabel, 'url' => $homeUrl]], $trail));
    }

    /**
     * @param array<string, mixed> $offer
     * @return array{name:string,url:string,image?:string,description?:string}|null
     */
    private function itemFromOfferLike(array $offer): ?array
    {
        $slug = trim((string)($offer['url_key'] ?? $offer['slug'] ?? $offer['source_slug'] ?? ''));
        $name = $this->resolveOfferDisplayName($offer, $slug);
        $url = trim((string)($offer['url'] ?? $offer['href'] ?? $offer['public_url'] ?? $offer['canonical'] ?? ''));
        if ($url === '') {
            $productId = (int)($offer['product_id'] ?? $offer['id'] ?? 0);
            if ($slug !== '') {
                $url = '/product/' . ltrim($slug, '/');
            } elseif ($productId > 0) {
                $url = '/product/' . $productId;
            }
        }
        if ($name === '' || $url === '') {
            return null;
        }

        $item = ['name' => $name, 'url' => $url];
        $image = trim((string)($offer['image'] ?? ''));
        if ($image === '' && is_array($offer['images'] ?? null)) {
            $first = $offer['images'][0] ?? null;
            $image = is_array($first)
                ? trim((string)($first['url'] ?? $first['src'] ?? ''))
                : trim((string)$first);
        }
        if ($image !== '') {
            $item['image'] = $image;
        }
        $description = trim(strip_tags((string)($offer['short_description'] ?? $offer['description'] ?? $offer['meta_description'] ?? '')));
        if ($description !== '') {
            $item['description'] = mb_substr($description, 0, 180);
        }

        return $item;
    }

    /**
     * Prefer human titles over factory SKU codes so ItemList matches what shoppers expect.
     *
     * @param array<string, mixed> $offer
     */
    private function resolveOfferDisplayName(array $offer, string $slug): string
    {
        $candidates = [
            trim((string)($offer['display_name'] ?? '')),
            trim((string)($offer['seo_title'] ?? '')),
            trim((string)($offer['meta_title'] ?? '')),
            trim((string)($offer['title'] ?? '')),
            trim((string)($offer['label'] ?? '')),
            trim((string)($offer['name'] ?? '')),
            trim((string)($offer['meta_name'] ?? '')),
        ];
        $sku = trim((string)($offer['sku'] ?? ''));
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if ($this->looksLikeFactorySku($candidate) && ($sku === '' || strcasecmp($candidate, $sku) === 0)) {
                continue;
            }
            return $candidate;
        }

        $fromSlug = $this->humanizeProductSlug($slug);
        if ($fromSlug !== '') {
            return $fromSlug;
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $sku;
    }

    private function looksLikeFactorySku(string $value): bool
    {
        // e.g. YUEYANICHANG-4D375D6D-C7370-S7CA5
        return (bool)preg_match('/^[A-Z0-9]{3,}(?:-[A-Z0-9]{2,}){2,}$/', $value);
    }

    private function humanizeProductSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return '';
        }
        // Drop trailing short hash segments commonly appended to storefront slugs.
        $parts = explode('-', $slug);
        while ($parts !== [] && preg_match('/^[a-f0-9]{6,}$/', (string)end($parts))) {
            array_pop($parts);
        }
        if ($parts === [] || count($parts) < 2) {
            return '';
        }

        return implode(' ', $parts);
    }
}
