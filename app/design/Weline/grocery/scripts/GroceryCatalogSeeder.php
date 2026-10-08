<?php

declare(strict_types=1);

namespace Weline\Design\Grocery;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Service\InventoryService;
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
 * Website-concept seeder for code=grocery (lives under app/design, not Product module).
 *
 * Images use Unsplash photography by grocery category; names/prices are
 * everyday CNY shelf references for a local MVP catalog.
 */
final class GroceryCatalogSeeder
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

    /**
     * @var list<array{
     *     sku:string,
     *     name:string,
     *     name_en:string,
     *     slug:string,
     *     price_minor:int,
     *     short_description:string,
     *     short_description_en:string,
     *     meta_description:string,
     *     meta_description_en:string,
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
            'meta_description' => '当日到货新鲜番茄，果肉饱满、酸甜适口，适合家常炒菜、凉拌与煲汤佐餐。请冷藏保存并尽快食用，锁住鲜味与水分；邻里杂货铺日常蔬果，方便备菜、清淡搭配与一周餐桌轮换。',
            'meta_description_en' => 'Same-day fresh tomatoes with juicy flavor for stir-fries, salads, and light soups. Keep chilled and use soon—neighborhood produce for everyday cooking.',
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
            'meta_description' => '熟度适中的香蕉一把，软糯香甜，适合早餐、加餐或切块搭配酸奶麦片。常温放置至合适熟度后尽快食用；邻里日常水果，方便全家随手补充能量，也适合带去上班或学校。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Ready-to-eat bananas with balanced ripeness for breakfast, snacks, or yogurt bowls. Ripen at room temperature, then enjoy soon for everyday energy.',
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
            'meta_description' => '时令绿叶菜约300g，叶片鲜嫩，清炒、焯水或凉拌皆宜。建议冷藏保鲜并当日或次日食用，保留清脆口感；邻里餐桌常备蔬菜，轻负担快手下饭，也适合清淡汤品搭配。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Seasonal leafy greens (about 300g) for quick stir-fry, blanching, or salads. Refrigerate and cook within a day or two for crisp neighborhood greens.',
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
            'meta_description' => '冷藏纯牛奶1L，口感清甜顺滑，适合早餐冲饮、泡麦片或日常佐餐。请置于冰箱冷藏并注意保质期，开封后尽快饮用；邻里乳品常备，方便全家日常补给与简单烘焙用量。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Chilled fresh milk (1L) for breakfast drinks, cereal, or everyday cooking. Keep refrigerated and finish soon after opening—household dairy staple.',
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
            'meta_description' => '原味酸奶四杯装，小份分食更方便，适合早餐、加餐或搭配水果坚果。请冷藏保存，开封后尽快食用；邻里乳品小包装，清爽酸甜不浪费，也方便分给家人各自一份。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Plain yogurt 4-pack in handy cups for breakfast, snacks, or fruit toppings. Keep cold and enjoy soon after opening—portioned dairy without waste.',
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
            'meta_description' => '全麦吐司400g，口感扎实，适合三明治、煎吐司与日常早餐。常温阴凉保存，开封后尽快食用以免干硬；邻里烘焙主食，方便备好一周早餐节奏，也适合简单加餐。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Whole wheat toast (400g) for sandwiches, toasted breakfasts, and weekday meals. Store cool and dry; finish after opening—everyday bakery staple.',
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
            'meta_description' => '东北大米5kg家庭装，米粒饱满、蒸煮后软糯有嚼劲，适合日常米饭与粥品。请密封干燥存放，避免受潮；邻里主食大包装，一次囤够省心又实惠，适合家庭日常主食轮换。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Northeast rice 5kg family bag with plump grains for daily steamed rice and porridge. Seal dry to avoid moisture—pantry staple for weeknight meals.',
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
            'meta_description' => '酿造酱油500ml，咸香适中，适合炒菜、凉拌与蘸料调味。常温避光存放，开封后拧紧瓶盖；邻里厨房调味常备，一瓶搞定家常提鲜，也方便配饺子与凉拌小菜。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Brewed soy sauce (500ml) with balanced saltiness for stir-fries, dressings, and dips. Store sealed away from light—everyday kitchen seasoning.',
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
            'meta_description' => '食用植物油1.8L，适合煎炒烹炸等日常烹调，油烟与风味平衡好把握。请密封阴凉存放，开封后尽快用完；邻里厨房底仓用油，家庭备餐更省心，也适合周末多菜快炒。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Cooking oil 1.8L for everyday frying, sautéing, and pan cooking. Keep sealed in a cool place and use steadily—reliable pantry oil for family meals.',
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
            'meta_description' => '原味薯片70g小包装，酥脆咸香，适合追剧、加班与朋友分享的休闲零食。请密封保存以免受潮变软；邻里零食货架常备，随手解馋刚刚好，也方便带去聚会分食。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Classic potato chips (70g) with a crisp, savory crunch for movie nights, desk breaks, and sharing. Reseal to stay crisp—small everyday snack pack.',
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
            'meta_description' => '绿茶饮料500ml，清爽解渴，适合通勤、学习与户外随身补给。常温饮用即可，开瓶后请尽快喝完；邻里茶饮便利装，一口回甘不腻口，也适合搭配轻食小点。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Ready-to-drink green tea (500ml), lightly refreshing for commute, study, or on-the-go. Finish after opening—handy neighborhood tea for everyday thirst.',
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
            'meta_description' => '每日坚果25g×7日装，混合坚果分量清楚，适合办公学习随手补充。请密封干燥保存，开袋后尽快食用；邻里健康零食分装，轻负担不踩雷，也方便按天携带不浪费。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Daily nuts mix 25g×7 packs with clear portions for desk and study snacks. Keep sealed and dry; finish after opening—convenient healthy bites nearby.',
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
            'meta_description' => '洗衣液2kg家庭装，清洁力稳定，适合日常衣物机洗与手洗高频复购。请置于阴凉处并按说明用量；邻里清洁日用，洗衣常备少操心，也适合换季衣物集中清洗。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Laundry detergent 2kg for regular machine or hand washes. Store cool and follow dosage guidance—household cleaning staple for family laundry.',
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
            'meta_description' => '洗洁精1kg，去油快捷、冲洗清爽，适合碗盘锅具与日常厨具清洁。请密封存放并避免儿童误触；邻里厨房清洁常备，洗碗台面更省事，也方便餐后快速收拾残局。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Dish soap 1kg that cuts grease and rinses clean for plates, pots, and daily kitchenware. Cap tightly and keep away from kids—kitchen cleanup essential.',
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
            'meta_description' => '中号垃圾袋约45只，厚实耐用不易破，适合厨房与居家日常收纳。抽用方便，一卷应急够用；邻里日用消耗品，补货不慌，也适合分类装生活垃圾与厨余。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Medium trash bags, about 45 count, sturdy for kitchen and household use. Easy pull-and-tie convenience—day-to-day consumable you will not run out of.',
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
            'meta_description' => '卷纸10卷家庭装，柔软耐用，适合卫生间与居家日常更换。请干燥存放避免受潮；邻里纸品常备，一次补足减少断档，也方便客房与多卫生间轮换使用。新鲜到货，关注保鲜与食用提示。',
            'meta_description_en' => 'Toilet paper 10-roll multipack, soft and reliable for bathroom restocking. Keep dry to avoid dampness—household paper staple for fewer shortages.',
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
        $disabledForeign = $this->disableForeignPublishedProducts($websiteId);

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
            'disabled_foreign' => $disabledForeign,
            'items' => $items,
        ];
    }

    /**
     * Dropship / clone SKUs on grocery website must not appear on the neighborhood shelf.
     *
     * @return int disabled product count
     */
    private function disableForeignPublishedProducts(int $websiteId): int
    {
        $disabled = 0;
        foreach ($this->products->listAll($websiteId) as $row) {
            $productId = (int)($row['product_id'] ?? $row['id'] ?? 0);
            $sku = strtoupper(trim((string)($row['sku'] ?? '')));
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if ($productId <= 0 || $status !== Product::STATUS_PUBLISHED) {
                continue;
            }
            if (str_starts_with($sku, 'GROCERY-')) {
                continue;
            }
            $version = (int)($row['publish_version'] ?? 0);
            try {
                $this->products->transition($websiteId, $productId, $version, Product::STATUS_DISABLED);
                ++$disabled;
            } catch (\Throwable) {
                continue;
            }
            foreach ($this->offers->listByProductIds($websiteId, [$productId]) as $offerRow) {
                if (!is_array($offerRow)) {
                    continue;
                }
                $offerId = (int)($offerRow['offer_id'] ?? 0);
                $offerStatus = strtolower(trim((string)($offerRow['status'] ?? '')));
                $offerVersion = (int)($offerRow['publish_version'] ?? 0);
                if ($offerId <= 0 || $offerStatus !== 'published') {
                    continue;
                }
                try {
                    $this->offers->transition($websiteId, $offerId, $offerVersion, 'disabled');
                } catch (\Throwable) {
                }
            }
        }

        return $disabled;
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
     * @param array{sku:string,name:string,name_en:string,slug:string,price_minor:int,short_description:string,short_description_en:string,meta_description:string,meta_description_en:string,category_code:string,image_url:string} $item
     */
    private function writeProductCopy(int $websiteId, int $productId, array $item): void
    {
        $metaZh = (string)$item['meta_description'];
        $metaEn = (string)$item['meta_description_en'];
        $pairs = [
            ['name', '', $item['name']],
            ['slug', '', $item['slug']],
            ['short_description', '', $item['short_description']],
            ['description', '', $metaZh],
            ['meta_description', '', $metaZh],
            ['product_type', '', 'simple'],
            ['name', 'zh_Hans_CN', $item['name']],
            ['short_description', 'zh_Hans_CN', $item['short_description']],
            ['description', 'zh_Hans_CN', $metaZh],
            ['meta_description', 'zh_Hans_CN', $metaZh],
            ['name', 'en_US', $item['name_en']],
            ['short_description', 'en_US', $item['short_description_en']],
            ['description', 'en_US', $metaEn],
            ['meta_description', 'en_US', $metaEn],
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
