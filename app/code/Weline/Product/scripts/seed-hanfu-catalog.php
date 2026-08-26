<?php

declare(strict_types=1);

/**
 * 汉服分类树 + 多规格属性商品种子。
 *
 * 商品信息参考 2026 年淘宝公开 listing（醉欢楼、醉梦夕风等）。
 * 创建走 ProductAdminMutationService；规格维度（颜色/尺码/类型）必须走 EAV 属性定义 + Product 分片属性值。
 *
 * Usage: php app/code/Weline/Product/scripts/seed-hanfu-catalog.php [website_id]
 */

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
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCategoryTreeIndex;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

$eavBootstrap = ObjectManager::getInstance(ProductCatalogEavBootstrap::class);
$eavBootstrap->ensureStorefrontSchema();
$hanfuEav = $eavBootstrap->ensureHanfuSchema();

/** @var ProductCategoryAdminService $categoryAdmin */
$categoryAdmin = ObjectManager::getInstance(ProductCategoryAdminService::class);
/** @var ProductAdminMutationService $mutations */
$mutations = ObjectManager::getInstance(ProductAdminMutationService::class);
/** @var CategoryRepository $categories */
$categories = ObjectManager::getInstance(CategoryRepository::class);
/** @var CategoryLinkRepository $categoryLinks */
$categoryLinks = ObjectManager::getInstance(CategoryLinkRepository::class);
/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var OfferRepository $offers */
$offers = ObjectManager::getInstance(OfferRepository::class);
/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
/** @var PriceRepository $prices */
$prices = ObjectManager::getInstance(PriceRepository::class);
/** @var MediaRepository $mediaRepo */
$mediaRepo = ObjectManager::getInstance(MediaRepository::class);
/** @var StoreProductRepository $storeProducts */
$storeProducts = ObjectManager::getInstance(StoreProductRepository::class);
/** @var StoreOfferRepository $storeOffers */
$storeOffers = ObjectManager::getInstance(StoreOfferRepository::class);
/** @var StoreCatalogInterface $storeCatalog */
$storeCatalog = ObjectManager::getInstance(StoreCatalogInterface::class);
/** @var StorefrontCategoryTreeIndex $treeIndex */
$treeIndex = ObjectManager::getInstance(StorefrontCategoryTreeIndex::class);

$ensureCategory = static function (int $websiteId, int $parentId, string $name) use ($categoryAdmin, $categories): int {
    foreach ($categories->listAll($websiteId) as $row) {
        if (max(0, (int)($row['parent_id'] ?? 0)) === $parentId
            && trim((string)($row['name'] ?? '')) === $name
        ) {
            return (int)($row['category_id'] ?? 0);
        }
    }
    return (int)$categoryAdmin->save($websiteId, 0, $parentId, $name, 'active')['category_id'];
};

$resolveStoreIds = static function (int $websiteId) use ($storeCatalog): array {
    $storeIds = [];
    foreach ($storeCatalog->byWebsite($websiteId) as $store) {
        if ($store->id > 0) {
            $storeIds[] = $store->id;
        }
    }
    return $storeIds !== [] ? array_values(array_unique($storeIds)) : [1];
};

$writeAttr = static function (
    int $websiteId,
    int $productId,
    string $code,
    string $value,
    bool $required = false,
) use ($attributes): void {
    $attributes->writeExplicit($websiteId, 0, 'product', $productId, $code, '', $value, $required);
};

$writeOfferAttr = static function (
    int $websiteId,
    int $offerId,
    string $code,
    string $value,
) use ($attributes): void {
    $attributes->writeExplicit($websiteId, 0, 'offer', $offerId, $code, '', $value, false);
};

$clearLegacySpecAttrs = static function (int $websiteId, int $productId) use ($attributes): void {
    foreach (['spec_style_type', 'spec_color', 'spec_size', 'spec_axes_json'] as $code) {
        try {
            $attributes->deleteOverlay($websiteId, 0, 'product', $productId, $code, '');
        } catch (Throwable) {
        }
    }
};

$writeTypeConfiguration = static function (
    int $websiteId,
    int $productId,
    array $axes,
    string $skuPrefix,
) use ($attributes): void {
    $attributes->writeTyped(
        $websiteId,
        0,
        'product',
        $productId,
        'type_configuration',
        '',
        'json',
        [
            'axes' => $axes,
            'sku_prefix' => $skuPrefix,
        ],
        false,
    );
};

$ensureGallery = static function (int $websiteId, string $sku, int $productId, string $url) use ($mediaRepo): void {
    $blobKey = 'hanfu-seed-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $sku) ?? $sku);
    if ($mediaRepo->findByBlobKey($websiteId, $blobKey) !== null) {
        return;
    }
    try {
        $mediaRepo->create($websiteId, [
            Media::schema_fields_PRODUCT_ID => $productId,
            Media::schema_fields_PATH => $url,
            Media::schema_fields_BLOB_KEY => $blobKey,
            Media::schema_fields_POSITION => 1,
        ]);
    } catch (Throwable) {
    }
};

$seedInventory = static function (int $websiteId, int $storeId, int $offerId, int $stock, string $sku): void {
    if (!class_exists(InventoryService::class)) {
        return;
    }
    try {
        ObjectManager::getInstance(InventoryService::class)->setOnHand(
            $websiteId,
            $storeId,
            $offerId,
            max(0, $stock),
            'hanfu-seed-' . strtolower($sku),
            hash('sha256', 'hanfu-seed-stock:' . strtolower($sku)),
        );
    } catch (Throwable) {
    }
};

/** @param list<int> $categoryIds */
$createHanfuProduct = static function (
    int $websiteId,
    array $item,
    array $categoryIds,
    array $storeIds,
) use (
    $mutations,
    $products,
    $offers,
    $prices,
    $writeAttr,
    $writeOfferAttr,
    $clearLegacySpecAttrs,
    $writeTypeConfiguration,
    $ensureGallery,
    $seedInventory,
    $storeProducts,
    $storeOffers,
    $categoryLinks,
): array {
    $sku = (string)$item['sku'];
    $requestHash = hash('sha256', 'hanfu-catalog:v2:' . $sku);
    $mutations->registerSku($sku, $requestHash, $websiteId);
    $product = $mutations->createProduct($websiteId, $sku);
    $offer = $mutations->createOffer($websiteId, $sku);
    $productId = (int)$product->getId();
    $offerId = (int)$offer->getId();

    $writeAttr($websiteId, $productId, 'name', (string)$item['name'], true);
    $writeAttr($websiteId, $productId, 'slug', (string)$item['slug'], true);
    $writeAttr($websiteId, $productId, 'short_description', (string)$item['short_description']);
    $writeAttr($websiteId, $productId, 'description', (string)$item['description']);
    $writeAttr($websiteId, $productId, 'brand', (string)$item['brand']);
    $writeAttr($websiteId, $productId, 'attribute_set', 'hanfu');
    $writeAttr($websiteId, $productId, 'material', (string)($item['material'] ?? ''));
    if (trim((string)($item['reference'] ?? '')) !== '') {
        $writeAttr($websiteId, $productId, 'reference_source', (string)$item['reference']);
    }
    $writeAttr($websiteId, $productId, 'product_type', 'configurable');

    $clearLegacySpecAttrs($websiteId, $productId);

    $defaults = is_array($item['defaults'] ?? null) ? $item['defaults'] : [];
    foreach (['color', 'size', 'style_type'] as $axisCode) {
        $value = trim((string)($defaults[$axisCode] ?? ''));
        if ($value !== '') {
            $writeAttr($websiteId, $productId, $axisCode, $value);
            $writeOfferAttr($websiteId, $offerId, $axisCode, $value);
        }
    }

    $writeTypeConfiguration($websiteId, $productId, $item['axes'], $sku);

    $prices->writeExplicit($websiteId, 0, $offerId, 'CNY', (int)$item['price_minor']);
    $ensureGallery($websiteId, $sku, $productId, (string)$item['image_url']);

    foreach ($storeIds as $storeId) {
        $storeProducts->select($websiteId, $storeId, $productId, true);
        $storeOffers->select($websiteId, $storeId, $offerId, true);
        $seedInventory($websiteId, $storeId, $offerId, 50, $sku);
    }

    $categoryRows = [];
    foreach ($categoryIds as $index => $categoryId) {
        $categoryRows[] = [
            'category_id' => $categoryId,
            'selected' => true,
            'scope_state' => 'explicit',
            'position' => $index,
        ];
    }
    $categoryLinks->syncProductScope($websiteId, $productId, 0, $categoryRows);

    if (strtolower(trim((string)$product->getData(Product::schema_fields_STATUS))) !== Product::STATUS_PUBLISHED) {
        $products->publish(
            $websiteId,
            $productId,
            (int)$product->getData(Product::schema_fields_PUBLISH_VERSION),
        );
    }
    $offerRow = $offers->findById($websiteId, $offerId);
    if ($offerRow !== null && strtolower(trim((string)$offerRow->getData('status'))) !== 'published') {
        $offers->publish(
            $websiteId,
            $offerId,
            (int)$offerRow->getData('publish_version'),
        );
    }

    return [
        'sku' => $sku,
        'product_id' => $productId,
        'offer_id' => $offerId,
        'name' => $item['name'],
        'variant_count' => count($item['axes'][0]['options'] ?? []) * count($item['axes'][1]['options'] ?? []) * count($item['axes'][2]['options'] ?? []),
    ];
};

// ── 分类维度树 ────────────────────────────────────────────────────
$hanfu = $ensureCategory($websiteId, 0, '汉服');
$styleRoot = $ensureCategory($websiteId, $hanfu, '形制分类');
$ming = $ensureCategory($websiteId, $styleRoot, '明制');
$tang = $ensureCategory($websiteId, $styleRoot, '唐制');
$song = $ensureCategory($websiteId, $styleRoot, '宋制');
$catMamian = $ensureCategory($websiteId, $ming, '马面裙');
$ensureCategory($websiteId, $ming, '对襟袄');
$ensureCategory($websiteId, $ming, '云肩');
$ensureCategory($websiteId, $tang, '齐胸襦裙');
$ensureCategory($websiteId, $tang, '诃子裙');
$ensureCategory($websiteId, $song, '褙子');
$ensureCategory($websiteId, $song, '百迭裙');

$occasionRoot = $ensureCategory($websiteId, $hanfu, '用途分类');
$catDaily = $ensureCategory($websiteId, $occasionRoot, '日常通勤');
$ensureCategory($websiteId, $occasionRoot, '婚礼婚服');
$ensureCategory($websiteId, $occasionRoot, '节日出游');
$ensureCategory($websiteId, $occasionRoot, '复原款');

$materialRoot = $ensureCategory($websiteId, $hanfu, '材质分类');
$ensureCategory($websiteId, $materialRoot, '涤纶混纺');
$ensureCategory($websiteId, $materialRoot, '真丝桑蚕');
$catZhijin = $ensureCategory($websiteId, $materialRoot, '织金妆花');
$ensureCategory($websiteId, $materialRoot, '纱雪纺');

$specCategoryId = $ensureCategory($websiteId, $hanfu, '规格商品专区');
$treeIndex->invalidate($websiteId);

$storeIds = $resolveStoreIds($websiteId);

$items = [
    [
        'sku' => 'HF-TYQM-260303',
        'name' => '明制马面裙套装「桃园清梦」',
        'slug' => 'taoyuan-qingmeng',
        'brand' => '醉欢楼',
        'material' => '聚酯纤维100%',
        'price_minor' => 13800,
        'reference' => '淘宝：明制马面裙套装汉服女2026 桃园清梦，参考价约138元',
        'short_description' => '明制琵琶袖上衣+马面裙套装，聚酯纤维100%，参考电商到手价约138元。',
        'description' => '货号 260303桃园清梦。2026春季上市，米白/粉色，S-XL。参考醉欢楼淘宝公开参数。',
        'image_url' => 'https://images.unsplash.com/photo-1610030469668-9a1f0f0a6b7a?w=800&h=1000&fit=crop',
        'defaults' => ['style_type' => 'set', 'color' => 'm-white', 'size' => 'm'],
        'axes' => [
            [
                'code' => 'style_type',
                'label' => '类型',
                'options' => [
                    ['value' => 'set', 'label' => '套装（上衣+马面裙）'],
                    ['value' => 'skirt', 'label' => '马面裙单件'],
                    ['value' => 'top', 'label' => '琵琶袖上衣单件'],
                ],
            ],
            [
                'code' => 'color',
                'label' => '颜色',
                'options' => [
                    ['value' => 'm-white', 'label' => '米白色'],
                    ['value' => 'pink', 'label' => '粉色'],
                ],
            ],
            [
                'code' => 'size',
                'label' => '尺码',
                'options' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                    ['value' => 'l', 'label' => 'L'],
                    ['value' => 'xl', 'label' => 'XL'],
                ],
            ],
        ],
        'categories' => [$specCategoryId, $hanfu, $ming, $catMamian, $catDaily],
    ],
    [
        'sku' => 'HF-SLY-Z230903',
        'name' => '神龙吟妆花明制马面裙',
        'slug' => 'shenlong-yin-zhuanghua-mamian',
        'brand' => '醉欢楼',
        'material' => '复合面料 / 聚酯纤维100%',
        'price_minor' => 9750,
        'reference' => '淘宝：神龙吟-原创马面裙套装，参考价约97.5元',
        'short_description' => '妆花明制马面裙/飞机袖，黑/红/白多色，参考价约97.5元。',
        'description' => '货号 Z23-0903 神龙吟。复合面料，可选妆花马面裙或白色飞机袖。',
        'image_url' => 'https://images.unsplash.com/photo-1583394838336-acd977736f90?w=800&h=1000&fit=crop',
        'defaults' => ['style_type' => 'skirt-black', 'color' => 'black', 'size' => 'm'],
        'axes' => [
            [
                'code' => 'style_type',
                'label' => '类型',
                'options' => [
                    ['value' => 'skirt-black', 'label' => '黑色妆花马面裙'],
                    ['value' => 'skirt-red', 'label' => '红色妆花马面裙'],
                    ['value' => 'top-white', 'label' => '白色飞机袖'],
                ],
            ],
            [
                'code' => 'color',
                'label' => '颜色',
                'options' => [
                    ['value' => 'black', 'label' => '黑色'],
                    ['value' => 'red', 'label' => '红色'],
                    ['value' => 'white', 'label' => '白色'],
                ],
            ],
            [
                'code' => 'size',
                'label' => '尺码',
                'options' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                    ['value' => 'l', 'label' => 'L'],
                    ['value' => 'xl', 'label' => 'XL'],
                ],
            ],
        ],
        'categories' => [$specCategoryId, $hanfu, $ming, $catMamian, $catZhijin],
    ],
    [
        'sku' => 'HF-ZMXF-XH-2026',
        'name' => '醉梦夕风织金马面裙「仙鹤」',
        'slug' => 'zuimeng-xifeng-xianhe-mamian',
        'brand' => '醉梦夕风',
        'material' => '螺钿幻彩织金 / 聚酯95%+其他5%',
        'price_minor' => 4900,
        'reference' => '淘宝：醉梦夕风新中式马面裙2026 仙鹤黑色，参考价约49元',
        'short_description' => '织金妆花日常通勤马面裙，仙鹤黑/红/卿竹白，参考价约49元。',
        'description' => '货号 ZJZH-20250905。螺钿幻彩织金，聚酯95%+其他5%，S-L。',
        'image_url' => 'https://images.unsplash.com/photo-1515372039744-b8f02a3ae446?w=800&h=1000&fit=crop',
        'defaults' => ['style_type' => 'skirt', 'color' => 'xianhe-black', 'size' => 'm'],
        'axes' => [
            [
                'code' => 'style_type',
                'label' => '类型',
                'options' => [
                    ['value' => 'skirt', 'label' => '马面裙单件'],
                    ['value' => 'set', 'label' => '套装'],
                ],
            ],
            [
                'code' => 'color',
                'label' => '颜色',
                'options' => [
                    ['value' => 'xianhe-black', 'label' => '仙鹤黑色'],
                    ['value' => 'xianhe-red', 'label' => '仙鹤红色'],
                    ['value' => 'qingzhu-white', 'label' => '幻彩卿竹白色'],
                ],
            ],
            [
                'code' => 'size',
                'label' => '尺码',
                'options' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                    ['value' => 'l', 'label' => 'L'],
                ],
            ],
        ],
        'categories' => [$specCategoryId, $hanfu, $ming, $catMamian, $catDaily, $catZhijin],
    ],
];

$created = [];
foreach ($items as $item) {
    $created[] = $createHanfuProduct(
        $websiteId,
        $item,
        $item['categories'],
        $storeIds,
    );
}

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'eav' => $hanfuEav,
    'categories' => [
        'hanfu' => $hanfu,
        'style_root' => $styleRoot,
        'ming' => $ming,
        'spec_products' => $specCategoryId,
        'mamian' => $catMamian,
    ],
    'products' => $created,
    'backend_path' => 'product/backend/catalog/products?website_id=' . $websiteId,
    'category_path' => 'product/backend/catalog/categories?website_id=' . $websiteId,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
