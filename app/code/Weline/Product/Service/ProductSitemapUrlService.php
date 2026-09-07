<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/** Product-owned public URLs shared by sitemap discovery and change events. */
final class ProductSitemapUrlService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly StoreProductRepository $storeProducts,
        private readonly StoreOfferRepository $storeOffers,
        private readonly AttributeValueRepository $attributes,
        private readonly WebsiteCatalogInterface $websites,
        private readonly StoreCatalogInterface $stores,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function getUrlsForWebsite(int $websiteId): array
    {
        return $this->collect($websiteId, $this->products->listAll($websiteId), sameOriginOnly: true);
    }

    /** @return list<array<string,mixed>> */
    public function getUrlsForProduct(int $websiteId, int $productId, ?int $storeId = null): array
    {
        $product = $this->products->findById($websiteId, $productId);
        return $product === null ? [] : $this->collect($websiteId, [$product->getData()], $storeId);
    }

    /** @param list<array<string,mixed>> $products @return list<array<string,mixed>> */
    private function collect(int $websiteId, array $products, ?int $onlyStoreId = null, bool $sameOriginOnly = false): array
    {
        $websiteUrl = '';
        foreach ($this->websites->all() as $website) {
            if ($website->id === $websiteId) {
                $websiteUrl = rtrim(trim($website->url), '/');
                break;
            }
        }
        $published = [];
        foreach ($products as $product) {
            if (($product[Product::schema_fields_STATUS] ?? '') === Product::STATUS_PUBLISHED) {
                $published[(int)$product[Product::schema_fields_ID]] = $product;
            }
        }
        if ($published === []) {
            return [];
        }
        $offers = [];
        foreach ($this->offers->listByProductIds($websiteId, array_keys($published)) as $offer) {
            if (($offer['status'] ?? '') === 'published') {
                $offers[(int)$offer['product_id']][] = (int)$offer['offer_id'];
            }
        }
        $urls = [];
        foreach ($this->stores->byWebsite($websiteId) as $store) {
            // dev/test storefronts are explicitly noindex in the SEO Head contract.
            if (!$store->enabled || $store->lifecycleStatus !== 'active' || $store->storeMode !== 'normal'
                || ($onlyStoreId !== null && $store->id !== $onlyStoreId)) {
                continue;
            }
            $base = rtrim(trim((string)$store->url), '/') ?: $websiteUrl;
            if (!in_array(strtolower((string)parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)
                || !parse_url($base, PHP_URL_HOST)) {
                continue;
            }
            // The SEO publisher validates every bucket against one Website origin.
            // Independent Store hosts still participate in Product change events.
            if ($sameOriginOnly && $this->origin($base) !== $this->origin($websiteUrl)) {
                continue;
            }
            foreach ($published as $productId => $product) {
                if (!$this->storeProducts->isSelected($websiteId, $store->id, $productId)) {
                    continue;
                }
                $visible = false;
                foreach ($offers[$productId] ?? [] as $offerId) {
                    if ($this->storeOffers->isSelected($websiteId, $store->id, $offerId)) {
                        $visible = true;
                        break;
                    }
                }
                if (!$visible) {
                    continue;
                }
                // Match storefront source_slug → slug precedence and Store → Website fallback.
                $slug = '';
                foreach (['source_slug', 'slug'] as $attribute) {
                    $resolved = $this->attributes->read($websiteId, $store->id, 'product', $productId, $attribute);
                    if ($resolved->isExplicit()) {
                        $slug = strtolower(trim((string)$resolved->value));
                        break;
                    }
                }
                if (preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) !== 1) {
                    $slug = (string)$productId;
                }
                $loc = $base . '/product/' . $slug;
                $urls[$loc] ??= [
                    'url_key' => 'product:' . $productId . ':store:' . $store->id,
                    'loc' => $loc,
                    'lastmod' => substr((string)($product['updated_at'] ?? ''), 0, 10),
                    'changefreq' => 'daily',
                    'priority' => '0.8',
                    'product_id' => $productId,
                    'store_id' => $store->id,
                ];
            }
        }
        return array_values($urls);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $scheme = strtolower((string)$parts['scheme']);
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        return $scheme . '://' . strtolower((string)$parts['host']) . ':' . $port;
    }
}
