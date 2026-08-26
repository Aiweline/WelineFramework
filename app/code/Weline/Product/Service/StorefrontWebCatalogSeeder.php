<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Service\InventoryService;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Seeds published storefront products from publicly listed market references (2026).
 *
 * Names and reference prices are derived from mainstream e-commerce listings and
 * review roundups; images use royalty-free Unsplash photography by category.
 */
final class StorefrontWebCatalogSeeder
{
    /**
     * @var list<array{
     *     sku:string,
     *     name:string,
     *     slug:string,
     *     price_minor:int,
     *     short_description:string,
     *     image_url:string
     * }>
     */
    private const ITEMS = [
        [
            'sku' => 'WEB-REDMI-TURBO4-16-256',
            'name' => 'Redmi Turbo 4 5G手机 16+256G',
            'slug' => 'redmi-turbo-4-16-256',
            'price_minor' => 149900,
            'short_description' => '天玑8400-Ultra，6550mAh大电池，千元档性能机热门款，参考电商到手价约1499元。',
            'image_url' => 'https://images.unsplash.com/photo-1598327105666-5b89351aff23?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-HONOR-PLAY10',
            'name' => '荣耀 Play10 5G手机',
            'slug' => 'honor-play-10',
            'price_minor' => 139900,
            'short_description' => '6000mAh长续航，天玑7200，均衡护眼入门机，参考售价约1399元。',
            'image_url' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-IQOO-Z11I',
            'name' => 'iQOO Z11i 长续航手机',
            'slug' => 'iqoo-z11i',
            'price_minor' => 101900,
            'short_description' => '7000mAh超大电池，骁龙685，备用机/长辈机热门低价款，参考价约1019元。',
            'image_url' => 'https://images.unsplash.com/photo-1565849906261-5a4c496227a9?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-MIJIA-AIR-6PRO',
            'name' => '米家空气净化器 6 Pro',
            'slug' => 'mijia-air-purifier-6-pro',
            'price_minor' => 189900,
            'short_description' => '双芯双架构、复合净化矩阵，2026年热销空净，国补叠券后常见到手价1800-1900元。',
            'image_url' => 'https://images.unsplash.com/photo-1584622781865-329d5d683190?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-TREEFRESH-T2PRO',
            'name' => '树新风 T2 Pro 除甲醛净化器',
            'slug' => 'treefresh-t2-pro',
            'price_minor' => 219900,
            'short_description' => '新房装修除醛中端热销机型，催化分解方案，参考价约2199元。',
            'image_url' => 'https://images.unsplash.com/photo-1605000796989-c3e7684c9a65?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-BELKIN-TB4-DOCK',
            'name' => '贝尔金 12合1 雷电4扩展坞',
            'slug' => 'belkin-thunderbolt-4-dock',
            'price_minor' => 89900,
            'short_description' => '笔记本办公效率神器，多口扩展+PD供电，618清单参考价约899元。',
            'image_url' => 'https://images.unsplash.com/photo-1625723044790-576b099b2b83?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-BENQ-SCREENBAR',
            'name' => '明基 ScreenBar 屏幕挂灯',
            'slug' => 'benq-screenbar',
            'price_minor' => 59900,
            'short_description' => '显示器挂灯品类标杆，减少屏幕反光，提升桌面办公舒适度，参考价约599元。',
            'image_url' => 'https://images.unsplash.com/photo-1524484485831-a92aec687147?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-LOGITECH-MX3S',
            'name' => '罗技 MX Master 3S 无线鼠标',
            'slug' => 'logitech-mx-master-3s',
            'price_minor' => 69900,
            'short_description' => '人体工学旗舰办公鼠标，静音微动，多设备切换，参考价约699元。',
            'image_url' => 'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-DYSON-V15',
            'name' => '戴森 V15 Detect 无线吸尘器',
            'slug' => 'dyson-v15-detect',
            'price_minor' => 499000,
            'short_description' => '激光显尘+高吸力，高端无线吸尘器代表机型，参考价约4990元。',
            'image_url' => 'https://images.unsplash.com/photo-1558317374-873fb1538da2?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-LIBY-LAUNDRY-3KG',
            'name' => '立白天然茶籽除菌洗衣液 3kg',
            'slug' => 'liby-tea-seed-laundry-3kg',
            'price_minor' => 12900,
            'short_description' => '家庭清洁高频复购款，除菌去渍，大促组合装参考价约129元。',
            'image_url' => 'https://images.unsplash.com/photo-1583947215250-4f8f9136f916?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-QINGSHAN-RUG',
            'name' => '青山美宿 极简侘寂风羊毛地毯',
            'slug' => 'qingshan-wabi-rug',
            'price_minor' => 69900,
            'short_description' => '新西兰羊毛混纺，低饱和大地色系，客厅卧室软装热销，参考价约699元。',
            'image_url' => 'https://images.unsplash.com/photo-1600166896080-3945215e8f8a?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'WEB-MIJIA-LOCK-E30',
            'name' => '小米智能门锁 E30',
            'slug' => 'mijia-smart-lock-e30',
            'price_minor' => 129900,
            'short_description' => '2026存量房智能改造热门品类，指纹+密码+APP，参考价约1299元。',
            'image_url' => 'https://images.unsplash.com/photo-1558002038-1055907df827?w=640&h=640&fit=crop',
        ],
    ];

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
        private readonly ProductCatalogEavBootstrap $eavBootstrap,
    ) {
    }

    /**
     * @return array{
     *     created:int,
     *     updated:int,
     *     published:int,
     *     enriched:int,
     *     eav:array<string,mixed>,
     *     items:list<array{sku:string,product_id:int,offer_id:int}>
     * }
     */
    public function seed(int $websiteId = 0, string $currency = 'CNY', int $stock = 80): array
    {
        $websiteId = max(0, $websiteId);
        $currency = strtoupper(trim($currency)) ?: 'CNY';
        $eav = $this->eavBootstrap->ensureStorefrontSchema();

        $created = 0;
        $updated = 0;
        $published = 0;
        $enriched = 0;
        $items = [];
        $storeIds = $this->resolveStoreIds($websiteId);

        foreach (self::ITEMS as $index => $item) {
            $sku = $item['sku'];
            $requestHash = hash('sha256', 'storefront-web-catalog:v1:' . $sku);
            $identity = $this->mutations->registerSku($sku, $requestHash, $websiteId);

            $existing = $this->products->findByGlobalUuid(
                $websiteId,
                $identity->globalProductUuid,
            );
            $wasExisting = $existing !== null;

            $product = $this->mutations->createProduct($websiteId, $sku);
            $offer = $this->mutations->createOffer($websiteId, $sku);
            $productId = (int)$product->getId();
            $offerId = (int)$offer->getId();

            if (!$wasExisting) {
                ++$created;
            } else {
                ++$updated;
            }

            $enrichment = StorefrontProductCatalogEnrichment::forSku($sku);
            $this->writeProductCopy($websiteId, $productId, $item, $enrichment);
            $this->prices->writeExplicit($websiteId, 0, $offerId, $currency, $item['price_minor']);
            $this->ensureGallery($websiteId, $sku, $productId, $item['image_url'], $enrichment['gallery'] ?? []);
            if ($enrichment !== null) {
                ++$enriched;
            }

            foreach ($storeIds as $storeId) {
                $this->storeProducts->select($websiteId, $storeId, $productId, true);
                $this->storeOffers->select($websiteId, $storeId, $offerId, true);
                $this->seedInventory($websiteId, $storeId, $offerId, $stock, $sku);
            }

            $productStatus = strtolower(trim((string)$product->getData(Product::schema_fields_STATUS)));
            if ($productStatus !== Product::STATUS_PUBLISHED) {
                $this->products->publish(
                    $websiteId,
                    $productId,
                    (int)$product->getData(Product::schema_fields_PUBLISH_VERSION),
                );
            }
            $offerStatus = strtolower(trim((string)$offer->getData('status')));
            if ($offerStatus !== 'published') {
                $this->offers->publish(
                    $websiteId,
                    $offerId,
                    (int)$offer->getData('publish_version'),
                );
                ++$published;
            }

            $items[] = [
                'sku' => $sku,
                'product_id' => $productId,
                'offer_id' => $offerId,
            ];
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'published' => $published,
            'enriched' => $enriched,
            'eav' => $eav,
            'items' => $items,
        ];
    }

    /**
     * @param array{sku:string,name:string,slug:string,price_minor:int,short_description:string,image_url:string} $item
     * @param array{attribute_set:string,description:string,attributes:array<string,string>,gallery:list<string>}|null $enrichment
     */
    private function writeProductCopy(int $websiteId, int $productId, array $item, ?array $enrichment): void
    {
        $baseAttributes = [
            ['name', '', $item['name']],
            ['slug', '', $item['slug']],
            ['short_description', '', $item['short_description']],
            ['product_type', '', 'simple'],
        ];

        if ($enrichment !== null) {
            $setCode = $enrichment['attribute_set'];
            $setMeta = StorefrontProductCatalogEnrichment::ATTRIBUTE_SETS[$setCode] ?? null;
            $baseAttributes[] = ['attribute_set', '', $setCode];
            if ($setMeta !== null) {
                $baseAttributes[] = ['attribute_set_label', '', $setMeta['label']];
            }
            $baseAttributes[] = ['description', '', $enrichment['description']];
            foreach ($enrichment['attributes'] as $code => $value) {
                $baseAttributes[] = [$code, '', $value];
            }
        }

        foreach ($baseAttributes as [$code, $locale, $value]) {
            $this->attributes->writeExplicit(
                $websiteId,
                0,
                'product',
                $productId,
                $code,
                $locale,
                $value,
                true,
            );
        }
    }

    /** @return list<int> */
    private function resolveStoreIds(int $websiteId): array
    {
        $storeIds = [];
        foreach ($this->storeCatalog->byWebsite($websiteId) as $store) {
            if ($store->id > 0) {
                $storeIds[] = $store->id;
            }
        }

        return $storeIds !== [] ? array_values(array_unique($storeIds)) : [1];
    }

    /**
     * @param list<string> $gallery
     */
    private function ensureGallery(
        int $websiteId,
        string $sku,
        int $productId,
        string $primaryImage,
        array $gallery,
    ): void {
        $images = array_values(array_unique(array_filter(array_merge(
            [trim($primaryImage)],
            array_map(static fn(string $url): string => trim($url), $gallery),
        ))));
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $sku) ?? $sku);

        foreach ($images as $position => $path) {
            if ($path === '') {
                continue;
            }
            $blobKey = sprintf('storefront-web-%s-img-%d', $slug, $position + 1);
            if ($this->media->findByBlobKey($websiteId, $blobKey) !== null) {
                continue;
            }
            try {
                $this->media->create($websiteId, [
                    \Weline\Product\Model\Shard\Media::schema_fields_PRODUCT_ID => $productId,
                    \Weline\Product\Model\Shard\Media::schema_fields_PATH => $path,
                    \Weline\Product\Model\Shard\Media::schema_fields_BLOB_KEY => $blobKey,
                    \Weline\Product\Model\Shard\Media::schema_fields_POSITION => $position + 1,
                ]);
            } catch (\Throwable) {
            }
        }
    }

    private function seedInventory(int $websiteId, int $storeId, int $offerId, int $stock, string $sku): void
    {
        if (!class_exists(InventoryService::class)) {
            return;
        }

        try {
            ObjectManager::getInstance(InventoryService::class)->setOnHand(
                $websiteId,
                $storeId,
                $offerId,
                max(0, $stock),
                'web-seed-' . strtolower($sku),
                hash('sha256', 'web-seed-' . strtolower($sku)),
            );
        } catch (\Throwable) {
        }
    }
}
