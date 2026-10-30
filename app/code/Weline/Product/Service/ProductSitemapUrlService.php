<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
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

    /**
     * Public locs on every website shard that carries this global_product_uuid.
     * Pass $onlyWebsiteId to stay single-website (store-local / scoped reads).
     *
     * @return list<array<string,mixed>>
     */
    public function getUrlsForGlobalProduct(
        string $globalUuid,
        ?int $onlyWebsiteId = null,
        ?int $onlyStoreId = null,
    ): array {
        $globalUuid = trim($globalUuid);
        if ($globalUuid === '') {
            return [];
        }
        $out = [];
        foreach ($this->websites->all() as $website) {
            if ($onlyWebsiteId !== null && $website->id !== $onlyWebsiteId) {
                continue;
            }
            $product = $this->products->findByGlobalUuid($website->id, $globalUuid);
            if ($product === null) {
                continue;
            }
            foreach ($this->getUrlsForProduct($website->id, (int)$product->getId(), $onlyStoreId) as $row) {
                $row['website_id'] = $website->id;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Website ids that have a product row for this global uuid (any status).
     *
     * @return list<int>
     */
    public function listWebsiteIdsForGlobalProduct(string $globalUuid): array
    {
        $globalUuid = trim($globalUuid);
        if ($globalUuid === '') {
            return [];
        }
        $ids = [];
        foreach ($this->websites->all() as $website) {
            if ($this->products->findByGlobalUuid($website->id, $globalUuid) !== null) {
                $ids[] = $website->id;
            }
        }

        return $ids;
    }

    /**
     * Absolute listing shells (home / products / new-arrivals / best-sellers / category)
     * for each member website that carries this global product.
     *
     * @return list<string>
     */
    public function getCatalogListingUrlsForGlobalProduct(string $globalUuid): array
    {
        /** @var StorefrontCatalogShellUrlBuilder $shells */
        $shells = ObjectManager::getInstance(StorefrontCatalogShellUrlBuilder::class);

        return $shells->absoluteListingUrlsForGlobalProduct($globalUuid);
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
        // 标题/描述按门店预取（listExplicitRows 批量读，避免每个产品×门店各发一条查询）。
        // 消费方（GEO feed、SEO facts）拿不到标题时会退化成把 URL 当标题，所以行里必须带出来。
        // 注意 listExplicitRows 有 20000 行 materialize 硬上限：全站产品×门店×locale 会直接抛异常，
        // 所以这里自己按小片推进，绝不整站一把梭。
        $storesByWebsite = $this->stores->byWebsite($websiteId);
        $storeIds = array_values(array_filter(
            array_map(static fn($store) => (int)$store->id, $storesByWebsite),
            static fn(int $id): bool => $id >= 0,
        ));
        // 门店读必须带上站点级兜底层 store_id=0（CatalogOverlayResolver 的既有口径是 [storeId, 0]）：
        // 站点级属性（绝大多数产品名/描述只在 store_id=0 上）不随门店行出现，漏掉就会读成空标题。
        $prefetchStoreIds = array_values(array_unique(array_merge($storeIds, [0])));
        $nameByProduct = $this->prefetchNameByProduct($websiteId, array_keys($published), $prefetchStoreIds);
        foreach ($storesByWebsite as $store) {
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
                $resolvedName = $nameByProduct[$productId] ?? ['name' => '', 'description' => ''];
                $urls[$loc] ??= [
                    'url_key' => 'product:' . $productId . ':store:' . $store->id,
                    'loc' => $loc,
                    'lastmod' => substr((string)($product['updated_at'] ?? ''), 0, 10),
                    'changefreq' => 'daily',
                    'priority' => '0.8',
                    'product_id' => $productId,
                    'store_id' => $store->id,
                    // 真实标题/描述：消费方（GEO feed / SEO facts）靠这两个字段出 feed item 的 title，
                    // 缺了就退化成把 loc 当标题，进而污染整个 geo-feed。
                    'name' => $resolvedName['name'],
                    'description' => $resolvedName['description'],
                    'title' => $resolvedName['name'],
                ];
            }
        }
        return array_values($urls);
    }

    /**
     * 批量预取产品名（含描述）：按 128 实体一片推进，避开 listExplicitRows 的 20000 行硬上限。
     *
     * 每个产品只保留一条最优值：店铺级显式行优先于 store_id=0 的站点级兜底行；
     * locale 按「空 → zh → en」优先级取第一个非空值。
     *
     * @param list<int> $productIds
     * @param list<int> $storeIds
     * @return array<int,array{name:string,description:string}>
     */
    private function prefetchNameByProduct(int $websiteId, array $productIds, array $storeIds): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0));
        if ($productIds === [] || $storeIds === []) {
            return [];
        }
        // locale 优先级：默认空串最优先，其次中英。
        $localePriority = ['', 'zh_Hans', 'zh_CN', 'en_US', 'en'];
        $best = [];
        foreach (array_chunk($productIds, 128) as $chunk) {
            try {
                $rows = $this->attributes->listExplicitRows(
                    $websiteId,
                    'product',
                    $chunk,
                    $storeIds,
                    null,
                    ['name', 'description'],
                );
            } catch (\Throwable) {
                // 预取是增强而非必需：取不到就退化成调用方原有的 URL 兜底，绝不能拖垮 sitemap。
                continue;
            }
            foreach ($rows as $row) {
                $code = (string)($row['attribute_code'] ?? '');
                if ($code !== 'name' && $code !== 'description') {
                    continue;
                }
                if ((bool)($row['cleared'] ?? false) || (string)($row['scope_state'] ?? '') === 'cleared') {
                    continue;
                }
                $productId = (int)($row['entity_id'] ?? 0);
                $value = trim((string)($row['value'] ?? ''));
                if ($productId <= 0 || $value === '') {
                    continue;
                }
                $storeId = (int)($row['store_id'] ?? 0);
                $locale = (string)($row['locale'] ?? '');
                $localeRank = array_search($locale, $localePriority, true);
                $localeRank = $localeRank === false ? count($localePriority) : $localeRank;
                // 店铺级行（store_id > 0）优先于站点级兜底行（store_id = 0）。
                $storeRank = $storeId > 0 ? 0 : 1;
                $rank = [$storeRank, $localeRank];
                $current = $best[$productId][$code] ?? null;
                if ($current !== null && $current['rank'] <= $rank) {
                    continue;
                }
                $best[$productId][$code] = ['rank' => $rank, 'value' => $value];
            }
        }
        $out = [];
        foreach ($best as $productId => $byCode) {
            $out[$productId] = [
                'name' => (string)($byCode['name']['value'] ?? ''),
                'description' => (string)($byCode['description']['value'] ?? ''),
            ];
        }

        return $out;
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
