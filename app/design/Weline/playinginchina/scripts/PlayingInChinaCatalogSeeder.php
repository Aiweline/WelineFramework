<?php

declare(strict_types=1);

namespace Weline\Design\PlayingInChina;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Service\InventoryService;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Service\ProductAdminMutationService;
use Weline\Product\Service\ProductCatalogEavBootstrap;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Model\Website;

/**
 * PlayingInChina 商品录入（design 主题资产，禁止写入 app/code 模块）。
 *
 * 双层商品：
 *   · 线路套餐  PIC-PKG-R{n}          ← data/routes.json（8 条）
 *   · 单景点门票 PIC-TKT-{DEST-ID}    ← data/destinations.json（330 个）
 *
 * 分类挂接：门票同时挂 pic-tickets / pic-scenery-* / pic-region-* / pic-style-*。
 */
final class PlayingInChinaCatalogSeeder
{
    public static function create(): self
    {
        return new self(
            ObjectManager::getInstance(ProductAdminMutationService::class),
            ObjectManager::getInstance(ProductRepository::class),
            ObjectManager::getInstance(OfferRepository::class),
            ObjectManager::getInstance(AttributeValueRepository::class),
            ObjectManager::getInstance(PriceRepository::class),
            ObjectManager::getInstance(MediaRepository::class),
            ObjectManager::getInstance(StoreProductRepository::class),
            ObjectManager::getInstance(StoreOfferRepository::class),
            ObjectManager::getInstance(StoreCatalogInterface::class),
            ObjectManager::getInstance(CategoryLinkRepository::class),
            ObjectManager::getInstance(CategoryRepository::class),
            ObjectManager::getInstance(ProductCategoryAttributeService::class),
            ObjectManager::getInstance(ProductCatalogEavBootstrap::class),
            ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class),
        );
    }

    public function __construct(
        private readonly ProductAdminMutationService $mutations,
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly AttributeValueRepository $attributes,
        private readonly PriceRepository $prices,
        private readonly MediaRepository $media,
        private readonly StoreProductRepository $storeProducts,
        private readonly StoreOfferRepository $storeOffers,
        private readonly StoreCatalogInterface $storeCatalog,
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly CategoryRepository $categories,
        private readonly ProductCategoryAttributeService $categoryAttributes,
        private readonly ProductCatalogEavBootstrap $eavBootstrap,
        private readonly StorefrontCatalogCacheCoordinator $catalogCache,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function seed(int $websiteId, string $currency = 'CNY', int $stock = 99, bool $withTickets = true): array
    {
        $websiteId = \max(0, $websiteId);
        if ($websiteId === Website::ID_DEFAULT) {
            throw new \InvalidArgumentException('PlayingInChina catalog seed refuses website_id=0 (default).');
        }
        $currency = \strtoupper(\trim($currency)) ?: 'CNY';

        $this->eavBootstrap->ensureStorefrontSchema();
        $categoryIds = $this->indexCategoryCodes($websiteId);
        $storeIds = $this->resolveStoreIds($websiteId);

        $stats = ['packages' => 0, 'tickets' => 0, 'linked' => 0, 'media' => 0];

        // ---- 线路套餐 ----
        foreach (\PlayingInChinaData::routes() as $r) {
            $no = \PlayingInChinaData::routeNo((string)($r['id'] ?? ''));
            $sku = 'PIC-PKG-R' . $no;
            $priceMinor = (int)\round(((float)\preg_replace('/[^\d.]/', '', (string)($r['price'] ?? '0'))) * 100);
            $dests = [];
            foreach ((array)($r['dests'] ?? []) as $did) {
                $d = \PlayingInChinaData::findDestination((string)$did);
                if ($d !== null) {
                    $dests[] = \PlayingInChinaData::t($d['name'] ?? '');
                }
            }
            $days = (string)($r['days'] ?? '');
            $desc = \PlayingInChinaData::t($r['desc'] ?? '');
            $itinLines = [];
            foreach ((array)($r['itin'] ?? []) as $row) {
                $itinLines[] = \PlayingInChinaData::t((string)($row[0] ?? '')) . ': ' . \PlayingInChinaData::t((string)($row[1] ?? ''));
            }
            $long = $desc
                . "\n\nCities: " . \PlayingInChinaData::t($r['cities'] ?? '')
                . "\nDuration: " . $days . " days"
                . "\nBest season: " . \PlayingInChinaData::t($r['best'] ?? '')
                . "\nCovers: " . \implode(', ', $dests)
                . "\n\nItinerary:\n- " . \implode("\n- ", $itinLines);

            $item = [
                'sku' => $sku,
                'name' => \PlayingInChinaData::t($r['title'] ?? '') . ' — ' . $days . '-Day China Tour',
                'slug' => 'route-' . (string)($r['id'] ?? ''),
                'short' => \PlayingInChinaData::clip($desc, 190),
                'long' => $long,
                'meta' => \PlayingInChinaData::clip($desc . ' Cities: ' . \PlayingInChinaData::t($r['cities'] ?? '') . '.', 155),
                'image' => (string)($r['img'] ?? ''),
                'gallery' => [],
                'price_minor' => $priceMinor,
                'categories' => ['pic-packages'],
            ];
            $this->upsertProduct($websiteId, $item, $currency, $stock, $storeIds, $categoryIds, $stats);
            ++$stats['packages'];
        }

        // ---- 单景点门票 ----
        if ($withTickets) {
            foreach (\PlayingInChinaData::destinations() as $d) {
                $id = (string)($d['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $sku = 'PIC-TKT-' . \strtoupper(\str_replace('-', '_', $id));
                $priceMinor = (int)\round(((float)($d['price'] ?? 0)) * 100);

                $hl = [];
                foreach ((array)($d['hl'] ?? []) as $h) {
                    $hl[] = \PlayingInChinaData::t($h);
                }
                $tips = [];
                foreach ((array)($d['tips'] ?? []) as $tp) {
                    $tips[] = \PlayingInChinaData::t($tp);
                }
                $long = \PlayingInChinaData::t($d['name'] ?? '') . ' (' . \PlayingInChinaData::t($d['cn'] ?? '') . '), '
                    . \PlayingInChinaData::t($d['city'] ?? '') . ', ' . \PlayingInChinaData::t($d['province'] ?? '') . '.'
                    . "\n\nHighlights:\n- " . \implode("\n- ", $hl)
                    . "\n\nOpening hours: " . \PlayingInChinaData::t($d['hours'] ?? '')
                    . "\nBest season: " . \PlayingInChinaData::t($d['best'] ?? '')
                    . "\nSuggested duration: " . \PlayingInChinaData::t($d['duration'] ?? '')
                    . "\nGetting there: " . \PlayingInChinaData::t($d['how'] ?? '')
                    . "\n\nTips:\n- " . \implode("\n- ", $tips)
                    . "\n\nWhere to stay: " . \PlayingInChinaData::t($d['hotel'] ?? '');

                $cats = ['pic-tickets'];
                $scenery = (string)($d['scenery'] ?? '');
                if ($scenery !== '') {
                    $cats[] = 'pic-scenery-' . $scenery;
                }
                $region = (string)($d['region'] ?? '');
                if ($region !== '') {
                    $cats[] = 'pic-region-' . \strtolower(\str_replace(' ', '-', $region));
                }
                foreach (\array_slice((array)($d['types'] ?? []), 0, 2) as $type) {
                    $cats[] = 'pic-style-' . (string)$type;
                }

                $gallery = [];
                foreach ((array)($d['gallery'] ?? []) as $g) {
                    $g = (string)$g;
                    if ($g !== '' && $g !== (string)($d['img'] ?? '')) {
                        $gallery[] = $g;
                    }
                }

                $item = [
                    'sku' => $sku,
                    'name' => \PlayingInChinaData::t($d['name'] ?? '') . ' Ticket',
                    'slug' => 'ticket-' . $id,
                    'short' => \PlayingInChinaData::clip(\implode(' · ', $hl), 190),
                    'long' => $long,
                    'meta' => \PlayingInChinaData::clip(
                        \PlayingInChinaData::t($d['name'] ?? '') . ' entrance ticket — '
                        . \PlayingInChinaData::t($d['priceCNY'] ?? '') . ', ' . \PlayingInChinaData::t($d['best'] ?? '') . '.',
                        155,
                    ),
                    'image' => (string)($d['img'] ?? ''),
                    'gallery' => \array_slice($gallery, 0, 3),
                    'price_minor' => $priceMinor,
                    'categories' => $cats,
                ];
                $this->upsertProduct($websiteId, $item, $currency, $stock, $storeIds, $categoryIds, $stats);
                ++$stats['tickets'];
            }
        }

        $this->catalogCache->notifyCatalogChanged($websiteId, 'playinginchina-catalog-seed');

        return [
            'website_id' => $websiteId,
            'currency' => $currency,
            'created' => $stats,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param list<int> $storeIds
     * @param array<string,int> $categoryIds
     * @param array<string,int> $stats
     */
    private function upsertProduct(
        int $websiteId,
        array $item,
        string $currency,
        int $stock,
        array $storeIds,
        array $categoryIds,
        array &$stats,
    ): void {
        $sku = (string)$item['sku'];
        $requestHash = \hash('sha256', 'playinginchina-catalog:v1:' . $sku);

        $this->mutations->registerSku($sku, $requestHash, $websiteId);
        $product = $this->mutations->createProduct($websiteId, $sku);
        $offer = $this->mutations->createOffer($websiteId, $sku);
        $productId = (int)$product->getId();
        $offerId = (int)$offer->getId();
        if ($productId <= 0 || $offerId <= 0) {
            throw new \RuntimeException('product/offer creation failed for ' . $sku);
        }

        $this->writeCopy($websiteId, $productId, $item);
        $this->prices->writeExplicit($websiteId, 0, $offerId, $currency, (int)$item['price_minor']);
        $stats['media'] += $this->writeMedia($websiteId, $sku, $productId, (string)$item['image'], (array)$item['gallery']);

        foreach ($storeIds as $storeId) {
            $this->storeProducts->select($websiteId, $storeId, $productId, true);
            $this->storeOffers->select($websiteId, $storeId, $offerId, true);
            $this->seedInventory($websiteId, $storeId, $offerId, $stock, $sku);
        }

        $rows = [];
        $pos = 0;
        foreach ((array)$item['categories'] as $code) {
            $cid = (int)($categoryIds[(string)$code] ?? 0);
            if ($cid > 0) {
                $rows[] = [
                    'category_id' => $cid,
                    'selected' => true,
                    'scope_state' => 'explicit',
                    'position' => $pos++,
                ];
            }
        }
        if ($rows !== []) {
            $this->categoryLinks->syncProductScope($websiteId, $productId, 0, $rows);
            $stats['linked'] += \count($rows);
        }

        $productStatus = \strtolower(\trim((string)$product->getData(Product::schema_fields_STATUS)));
        if ($productStatus !== Product::STATUS_PUBLISHED) {
            $this->products->publish($websiteId, $productId, (int)$product->getData(Product::schema_fields_PUBLISH_VERSION));
        }
        $offerStatus = \strtolower(\trim((string)$offer->getData('status')));
        if ($offerStatus !== 'published') {
            $this->offers->publish($websiteId, $offerId, (int)$offer->getData('publish_version'));
        }
    }

    /** @param array<string,mixed> $item */
    private function writeCopy(int $websiteId, int $productId, array $item): void
    {
        $pairs = [
            ['name', (string)$item['name']],
            ['slug', (string)$item['slug']],
            ['short_description', (string)$item['short']],
            ['description', (string)$item['long']],
            ['meta_description', (string)$item['meta']],
            ['product_type', 'simple'],
        ];
        foreach ($pairs as [$code, $value]) {
            $this->attributes->writeExplicit($websiteId, 0, 'product', $productId, $code, '', $value, true);
        }
    }

    /**
     * 主图 + 画廊（外链 URL，与原静态站同源；落本地需过 media-webp-gate）。
     *
     * @param list<string> $gallery
     */
    private function writeMedia(int $websiteId, string $sku, int $productId, string $image, array $gallery): int
    {
        $urls = [];
        if ($image !== '') {
            $urls[] = $image;
        }
        foreach ($gallery as $g) {
            $g = (string)$g;
            if ($g !== '' && !\in_array($g, $urls, true)) {
                $urls[] = $g;
            }
        }
        $written = 0;
        $position = 1;
        foreach ($urls as $url) {
            $blobKey = \sprintf('pic-%s-img-%d', \strtolower(\preg_replace('/[^a-z0-9]+/i', '-', $sku) ?? $sku), $position);
            if ($this->media->findByBlobKey($websiteId, $blobKey) !== null) {
                ++$position;
                continue;
            }
            try {
                $this->media->create($websiteId, [
                    Media::schema_fields_PRODUCT_ID => $productId,
                    Media::schema_fields_PATH => $url,
                    Media::schema_fields_BLOB_KEY => $blobKey,
                    Media::schema_fields_POSITION => $position,
                ]);
                ++$written;
            } catch (\Throwable) {
            }
            ++$position;
        }
        return $written;
    }

    /** @return array<string,int> */
    private function indexCategoryCodes(int $websiteId): array
    {
        $ids = [];
        foreach ($this->categories->listAll($websiteId) as $row) {
            $id = (int)($row['category_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $en = $this->categoryAttributes->readCodeMap($websiteId, $ids, 'en_US');
        $zh = $this->categoryAttributes->readCodeMap($websiteId, $ids, 'zh_Hans_CN');
        $out = [];
        foreach ($ids as $id) {
            $code = \strtolower(\trim((string)($en[$id] ?? '')));
            if ($code === '') {
                $code = \strtolower(\trim((string)($zh[$id] ?? '')));
            }
            if ($code !== '') {
                $out[$code] = $id;
            }
        }
        return $out;
    }

    /** @return list<int> */
    private function resolveStoreIds(int $websiteId): array
    {
        $storeIds = [];
        try {
            foreach ($this->storeCatalog->byWebsite($websiteId) as $store) {
                if ($store->id >= 0) {
                    $storeIds[] = (int)$store->id;
                }
            }
        } catch (\Throwable) {
        }
        return $storeIds !== [] ? \array_values(\array_unique($storeIds)) : [0];
    }

    private function seedInventory(int $websiteId, int $storeId, int $offerId, int $stock, string $sku): void
    {
        if (!\class_exists(InventoryService::class)) {
            return;
        }
        try {
            ObjectManager::getInstance(InventoryService::class)->setOnHand(
                $websiteId,
                $storeId,
                $offerId,
                \max(0, $stock),
                'pic-seed-' . \strtolower($sku),
                \hash('sha256', 'pic-seed-' . \strtolower($sku)),
            );
        } catch (\Throwable) {
        }
    }
}
