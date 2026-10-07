<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Service\InventoryService;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Repository\CategoryRepository;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Model\Website;

/**
 * Seeds published neighborhood-grocery products for website code=grocery.
 *
 * Images use Unsplash photography by grocery category; names/prices are
 * everyday CNY shelf references for a local MVP catalog.
 */
final class StorefrontGroceryCatalogSeeder
{
    /**
     * @var list<array{
     *     sku:string,
     *     name:string,
     *     name_en:string,
     *     slug:string,
     *     price_minor:int,
     *     short_description:string,
     *     short_description_en:string,
     *     category_code:string,
     *     image_url:string
     * }>
     */
    private const ITEMS = [
        [
            'sku' => 'GROCERY-TOMATO-500G',
            'name' => '新鲜番茄 500g',
            'name_en' => 'Fresh Tomatoes 500g',
            'slug' => 'fresh-tomatoes-500g',
            'price_minor' => 690,
            'short_description' => '当日到货番茄，适合炒菜与凉拌。',
            'short_description_en' => 'Same-day tomatoes for stir-fry and salads.',
            'category_code' => 'grocery-produce',
            'image_url' => 'https://images.unsplash.com/photo-1546094096-0df4bcaaa337?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-BANANA-BUNCH',
            'name' => '香蕉 约1把',
            'name_en' => 'Bananas (bunch)',
            'slug' => 'bananas-bunch',
            'price_minor' => 890,
            'short_description' => '熟度适中，早餐与加餐方便。',
            'short_description_en' => 'Ready-to-eat bananas for breakfast and snacks.',
            'category_code' => 'grocery-produce',
            'image_url' => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b98e?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-LEAFY-GREENS',
            'name' => '时令绿叶菜 300g',
            'name_en' => 'Seasonal Leafy Greens 300g',
            'slug' => 'seasonal-leafy-greens-300g',
            'price_minor' => 490,
            'short_description' => '清炒或焯水皆宜的绿叶菜。',
            'short_description_en' => 'Leafy greens for quick stir-fry or blanching.',
            'category_code' => 'grocery-produce',
            'image_url' => 'https://images.unsplash.com/photo-1576045057995-568f588f82fb?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-MILK-1L',
            'name' => '纯牛奶 1L',
            'name_en' => 'Fresh Milk 1L',
            'slug' => 'fresh-milk-1l',
            'price_minor' => 1290,
            'short_description' => '冷藏乳品，早餐搭档。',
            'short_description_en' => 'Chilled milk for everyday breakfast.',
            'category_code' => 'grocery-dairy-bakery',
            'image_url' => 'https://images.unsplash.com/photo-1563636619-e9143da7973b?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-YOGURT-4PK',
            'name' => '原味酸奶 4杯装',
            'name_en' => 'Plain Yogurt 4-pack',
            'slug' => 'plain-yogurt-4pack',
            'price_minor' => 1590,
            'short_description' => '小杯装酸奶，方便分食。',
            'short_description_en' => 'Portion yogurt cups for easy sharing.',
            'category_code' => 'grocery-dairy-bakery',
            'image_url' => 'https://images.unsplash.com/photo-1488477182946-859e8e2d9b4d?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-WHOLE-WHEAT-BREAD',
            'name' => '全麦吐司 400g',
            'name_en' => 'Whole Wheat Toast 400g',
            'slug' => 'whole-wheat-toast-400g',
            'price_minor' => 1190,
            'short_description' => '基础烘焙吐司，三明治与早餐适用。',
            'short_description_en' => 'Everyday toast bread for sandwiches and breakfast.',
            'category_code' => 'grocery-dairy-bakery',
            'image_url' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-RICE-5KG',
            'name' => '东北大米 5kg',
            'name_en' => 'Northeast Rice 5kg',
            'slug' => 'northeast-rice-5kg',
            'price_minor' => 3990,
            'short_description' => '家庭主食大包装，粒感饱满。',
            'short_description_en' => 'Family-size rice staple with a clean grain bite.',
            'category_code' => 'grocery-pantry',
            'image_url' => 'https://images.unsplash.com/photo-1586201375761-83865001e31c?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-SOY-SAUCE-500ML',
            'name' => '酿造酱油 500ml',
            'name_en' => 'Brewed Soy Sauce 500ml',
            'slug' => 'brewed-soy-sauce-500ml',
            'price_minor' => 1090,
            'short_description' => '日常炒菜调味常用酱油。',
            'short_description_en' => 'Everyday soy sauce for home cooking.',
            'category_code' => 'grocery-pantry',
            'image_url' => 'https://images.unsplash.com/photo-1615485290382-441e4d049cb5?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-COOKING-OIL-1.8L',
            'name' => '食用植物油 1.8L',
            'name_en' => 'Cooking Oil 1.8L',
            'slug' => 'cooking-oil-1-8l',
            'price_minor' => 4590,
            'short_description' => '厨房底仓用油，适合日常烹调。',
            'short_description_en' => 'Pantry cooking oil for everyday meals.',
            'category_code' => 'grocery-pantry',
            'image_url' => 'https://images.unsplash.com/photo-1474979266404-7eaacbcd87c5?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-POTATO-CHIPS',
            'name' => '原味薯片 70g',
            'name_en' => 'Classic Potato Chips 70g',
            'slug' => 'classic-potato-chips-70g',
            'price_minor' => 690,
            'short_description' => '休闲零食小包装。',
            'short_description_en' => 'Crispy snack pack for quick breaks.',
            'category_code' => 'grocery-snacks-drinks',
            'image_url' => 'https://images.unsplash.com/photo-1566478989037-eec170784d0b?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-GREEN-TEA-500ML',
            'name' => '绿茶饮料 500ml',
            'name_en' => 'Green Tea Drink 500ml',
            'slug' => 'green-tea-drink-500ml',
            'price_minor' => 450,
            'short_description' => '常温茶饮，解渴补给。',
            'short_description_en' => 'Ready-to-drink green tea for everyday refreshment.',
            'category_code' => 'grocery-snacks-drinks',
            'image_url' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-NUTS-MIX',
            'name' => '每日坚果 25g×7',
            'name_en' => 'Daily Nuts Mix 25g×7',
            'slug' => 'daily-nuts-mix-7pack',
            'price_minor' => 2990,
            'short_description' => '分装坚果，办公学习随手拿。',
            'short_description_en' => 'Portioned nut mix for desk and study snacks.',
            'category_code' => 'grocery-snacks-drinks',
            'image_url' => 'https://images.unsplash.com/photo-1599599810769-bcde5a160d32?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-LAUNDRY-DET',
            'name' => '洗衣液 2kg',
            'name_en' => 'Laundry Detergent 2kg',
            'slug' => 'laundry-detergent-2kg',
            'price_minor' => 3290,
            'short_description' => '家庭洗衣清洁高频复购。',
            'short_description_en' => 'Family laundry detergent for regular washes.',
            'category_code' => 'grocery-household',
            'image_url' => 'https://images.unsplash.com/photo-1583947215250-4f8f9136f916?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-DISH-SOAP',
            'name' => '洗洁精 1kg',
            'name_en' => 'Dish Soap 1kg',
            'slug' => 'dish-soap-1kg',
            'price_minor' => 1290,
            'short_description' => '厨房碗盘清洁日用品。',
            'short_description_en' => 'Kitchen dish soap for daily cleanup.',
            'category_code' => 'grocery-household',
            'image_url' => 'https://images.unsplash.com/photo-1563453392212-326f5e854473?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-TRASH-BAGS',
            'name' => '垃圾袋 中号 45只',
            'name_en' => 'Trash Bags Medium 45ct',
            'slug' => 'trash-bags-medium-45',
            'price_minor' => 990,
            'short_description' => '居家日用消耗品。',
            'short_description_en' => 'Medium trash bags for home use.',
            'category_code' => 'grocery-household',
            'image_url' => 'https://images.unsplash.com/photo-1611284446314-60a58ac0deb9?w=640&h=640&fit=crop',
        ],
        [
            'sku' => 'GROCERY-TOILET-PAPER',
            'name' => '卷纸 10卷',
            'name_en' => 'Toilet Paper 10 rolls',
            'slug' => 'toilet-paper-10-rolls',
            'price_minor' => 2490,
            'short_description' => '卫生间日用常备。',
            'short_description_en' => 'Household toilet paper multipack.',
            'category_code' => 'grocery-household',
            'image_url' => 'https://images.unsplash.com/photo-1584556812952-905ffd0db98c?w=640&h=640&fit=crop',
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
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly CategoryRepository $categories,
        private readonly ProductCategoryAttributeService $categoryAttributes,
        private readonly ProductCatalogEavBootstrap $eavBootstrap,
        private readonly StorefrontCatalogCacheCoordinator $catalogCache,
    ) {
    }

    /**
     * @return array{
     *     website_id:int,
     *     created:int,
     *     updated:int,
     *     published:int,
     *     linked:int,
     *     items:list<array{sku:string,product_id:int,offer_id:int,category_code:string}>
     * }
     */
    public function seed(int $websiteId, string $currency = 'CNY', int $stock = 80): array
    {
        $websiteId = max(0, $websiteId);
        if ($websiteId === Website::ID_DEFAULT) {
            throw new \InvalidArgumentException('Grocery catalog seed refuses website_id=0 (default).');
        }
        $currency = strtoupper(trim($currency)) ?: 'CNY';
        $this->eavBootstrap->ensureStorefrontSchema();

        $categoryIdsByCode = $this->indexCategoryCodes($websiteId);
        $created = 0;
        $updated = 0;
        $published = 0;
        $linked = 0;
        $items = [];
        $storeIds = $this->resolveStoreIds($websiteId);

        foreach (self::ITEMS as $item) {
            $sku = $item['sku'];
            $requestHash = hash('sha256', 'storefront-grocery-catalog:v1:' . $sku);
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

            $this->writeProductCopy($websiteId, $productId, $item);
            $this->prices->writeExplicit($websiteId, 0, $offerId, $currency, $item['price_minor']);
            $this->ensureGallery($websiteId, $sku, $productId, $item['image_url']);

            foreach ($storeIds as $storeId) {
                $this->storeProducts->select($websiteId, $storeId, $productId, true);
                $this->storeOffers->select($websiteId, $storeId, $offerId, true);
                $this->seedInventory($websiteId, $storeId, $offerId, $stock, $sku);
            }

            $categoryCode = strtolower(trim($item['category_code']));
            $categoryId = (int)($categoryIdsByCode[$categoryCode] ?? 0);
            if ($categoryId > 0) {
                $this->categoryLinks->syncProductScope($websiteId, $productId, 0, [[
                    'category_id' => $categoryId,
                    'selected' => true,
                    'scope_state' => 'explicit',
                    'position' => 0,
                ]]);
                ++$linked;
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
                'category_code' => $categoryCode,
            ];
        }

        $this->catalogCache->notifyCatalogChanged($websiteId, 'grocery-catalog-seed');

        return [
            'website_id' => $websiteId,
            'created' => $created,
            'updated' => $updated,
            'published' => $published,
            'linked' => $linked,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, int>
     */
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
        $map = $this->categoryAttributes->readCodeMap($websiteId, $ids, 'en_US');
        $zhMap = $this->categoryAttributes->readCodeMap($websiteId, $ids, 'zh_Hans_CN');
        $out = [];
        foreach ($ids as $id) {
            $code = strtolower(trim((string)($map[$id] ?? '')));
            if ($code === '') {
                $code = strtolower(trim((string)($zhMap[$id] ?? '')));
            }
            if ($code !== '') {
                $out[$code] = $id;
            }
        }

        return $out;
    }

    /**
     * @param array{sku:string,name:string,name_en:string,slug:string,price_minor:int,short_description:string,short_description_en:string,category_code:string,image_url:string} $item
     */
    private function writeProductCopy(int $websiteId, int $productId, array $item): void
    {
        $pairs = [
            ['name', '', $item['name']],
            ['slug', '', $item['slug']],
            ['short_description', '', $item['short_description']],
            ['description', '', $item['short_description']],
            ['product_type', '', 'simple'],
            ['name', 'zh_Hans_CN', $item['name']],
            ['short_description', 'zh_Hans_CN', $item['short_description']],
            ['description', 'zh_Hans_CN', $item['short_description']],
            ['name', 'en_US', $item['name_en']],
            ['short_description', 'en_US', $item['short_description_en']],
            ['description', 'en_US', $item['short_description_en']],
        ];
        foreach ($pairs as [$code, $locale, $value]) {
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
            if ($store->id >= 0) {
                $storeIds[] = $store->id;
            }
        }

        return $storeIds !== [] ? array_values(array_unique($storeIds)) : [0];
    }

    private function ensureGallery(int $websiteId, string $sku, int $productId, string $primaryImage): void
    {
        $path = trim($primaryImage);
        if ($path === '') {
            return;
        }
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $sku) ?? $sku);
        $blobKey = sprintf('storefront-grocery-%s-img-1', $slug);
        if ($this->media->findByBlobKey($websiteId, $blobKey) !== null) {
            return;
        }
        try {
            $this->media->create($websiteId, [
                \Weline\Product\Model\Shard\Media::schema_fields_PRODUCT_ID => $productId,
                \Weline\Product\Model\Shard\Media::schema_fields_PATH => $path,
                \Weline\Product\Model\Shard\Media::schema_fields_BLOB_KEY => $blobKey,
                \Weline\Product\Model\Shard\Media::schema_fields_POSITION => 1,
            ]);
        } catch (\Throwable) {
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
                'grocery-seed-' . strtolower($sku),
                hash('sha256', 'grocery-seed-' . strtolower($sku)),
            );
        } catch (\Throwable) {
        }
    }
}
