<?php

declare(strict_types=1);

/**
 * 为汉服 configurable 商品补齐 Offer 规格矩阵（每组合一个 Offer + combination_key）。
 *
 * 前置：seed-hanfu-catalog.php 已创建 SPU 与单 Offer；本脚本展开为完整变体矩阵并发布。
 *
 * Usage: php app/code/Weline/Product/scripts/seed-hanfu-variant-matrix.php [website_id]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Service\InventoryService;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Service\ProductIdentityV2Service;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductVariantMatrixService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var OfferRepository $offers */
$offers = ObjectManager::getInstance(OfferRepository::class);
/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
/** @var PriceRepository $prices */
$prices = ObjectManager::getInstance(PriceRepository::class);
/** @var StoreOfferRepository $storeOffers */
$storeOffers = ObjectManager::getInstance(StoreOfferRepository::class);
/** @var StoreCatalogInterface $storeCatalog */
$storeCatalog = ObjectManager::getInstance(StoreCatalogInterface::class);
/** @var ProductVariantMatrixService $variantMatrix */
$variantMatrix = ObjectManager::getInstance(ProductVariantMatrixService::class);
/** @var ProductIdentityV2Service $identities */
$identities = ObjectManager::getInstance(ProductIdentityV2Service::class);
/** @var StorefrontCatalogCacheCoordinator $catalogCache */
$catalogCache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);

$resolveStoreIds = static function (int $websiteId) use ($storeCatalog): array {
    $storeIds = [];
    foreach ($storeCatalog->byWebsite($websiteId) as $store) {
        if ($store->id >= 0) {
            $storeIds[] = $store->id;
        }
    }

    return $storeIds !== [] ? array_values(array_unique($storeIds)) : [1];
};

$writeOfferAxisAttrs = static function (
    int $websiteId,
    int $offerId,
    array $combination,
) use ($attributes): void {
    foreach (['color', 'size', 'style_type'] as $axisCode) {
        $value = trim((string)($combination[$axisCode] ?? ''));
        if ($value === '') {
            continue;
        }
        $attributes->writeTyped(
            $websiteId,
            0,
            'offer',
            $offerId,
            $axisCode,
            '',
            'select',
            $value,
            false,
        );
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
            'hanfu-matrix-' . strtolower($sku),
            hash('sha256', 'hanfu-matrix-stock:' . strtolower($sku)),
        );
    } catch (Throwable) {
    }
};

$jsonEncode = static fn(array $payload): string => json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);

$requestHash = hash('sha256', 'hanfu-variant-matrix:v1:' . $websiteId);
$storeIds = $resolveStoreIds($websiteId);

$items = [
    [
        'sku' => 'HF-TYQM-260303',
        'slug' => 'taoyuan-qingmeng',
        'price_minor' => 13800,
        'defaults' => ['style_type' => 'set', 'color' => 'm-white', 'size' => 'm'],
    ],
    [
        'sku' => 'HF-SLY-Z230903',
        'slug' => 'shenlong-yin-zhuanghua-mamian',
        'price_minor' => 9750,
        'defaults' => ['style_type' => 'skirt-black', 'color' => 'black', 'size' => 'm'],
    ],
    [
        'sku' => 'HF-ZMXF-XH-2026',
        'slug' => 'zuimeng-xifeng-xianhe-mamian',
        'price_minor' => 4900,
        'defaults' => ['style_type' => 'skirt', 'color' => 'xianhe-black', 'size' => 'm'],
    ],
];

$summary = [];

foreach ($items as $item) {
    $primarySku = trim((string)$item['sku']);
    $product = $products->findBySku($websiteId, $primarySku);
    if ($product === null) {
        $summary[] = ['sku' => $primarySku, 'status' => 'skipped', 'reason' => 'product_not_found'];
        continue;
    }

    $productId = (int)$product->getId();
    $globalProductUuid = trim((string)$product->getData('global_product_uuid'));
    if ($globalProductUuid === '') {
        $summary[] = ['sku' => $primarySku, 'status' => 'skipped', 'reason' => 'missing_global_product_uuid'];
        continue;
    }

    $typeConfigRaw = $attributes->read($websiteId, 0, 'product', $productId, 'type_configuration')->value;
    $typeConfig = is_array($typeConfigRaw)
        ? $typeConfigRaw
        : (is_string($typeConfigRaw) ? json_decode($typeConfigRaw, true) : null);
    if (!is_array($typeConfig)) {
        $summary[] = ['sku' => $primarySku, 'status' => 'skipped', 'reason' => 'type_configuration_missing'];
        continue;
    }

    $axes = is_array($typeConfig['axes'] ?? null) ? $typeConfig['axes'] : [];
    $skuPrefix = trim((string)($typeConfig['sku_prefix'] ?? $primarySku));
    if ($axes === [] || $skuPrefix === '') {
        $summary[] = ['sku' => $primarySku, 'status' => 'skipped', 'reason' => 'type_configuration_invalid'];
        continue;
    }

    $defaults = is_array($item['defaults'] ?? null) ? $item['defaults'] : [];
    $defaultKey = $variantMatrix->combinationKey($defaults);
    $skuOverrides = [$defaultKey => $primarySku];
    $generated = $variantMatrix->generate($axes, $skuPrefix, $skuOverrides);
    $expectedCount = count($generated);

    $existingOffers = $offers->listByProductIds($websiteId, [$productId]);
    $keyedOffers = array_values(array_filter(
        $existingOffers,
        static fn(array $row): bool => trim((string)($row['combination_key'] ?? '')) !== '',
    ));

    if (count($keyedOffers) >= $expectedCount) {
        $summary[] = [
            'sku' => $primarySku,
            'slug' => (string)$item['slug'],
            'status' => 'already_complete',
            'offers' => count($keyedOffers),
            'expected' => $expectedCount,
        ];
        continue;
    }

    if (count($existingOffers) === 1 && trim((string)($existingOffers[0]['combination_key'] ?? '')) === '') {
        $bootstrap = $existingOffers[0];
        $bootstrapOffer = $offers->findById($websiteId, (int)($bootstrap['offer_id'] ?? 0));
        if ($bootstrapOffer !== null) {
            $defaultRow = null;
            foreach ($generated as $row) {
                if ($row['combination_key'] === $defaultKey) {
                    $defaultRow = $row;
                    break;
                }
            }
            if ($defaultRow === null) {
                throw new RuntimeException('default_combination_not_in_matrix:' . $primarySku);
            }
            $offers->updateVersioned(
                $websiteId,
                (int)$bootstrapOffer->getId(),
                (int)$bootstrapOffer->getData(Offer::schema_fields_PUBLISH_VERSION),
                [
                    'combination_key' => $defaultKey,
                    'is_default' => 1,
                    'type_config_json' => $jsonEncode(['combination' => $defaultRow['combination']]),
                ],
            );
            $existingOffers = $offers->listByProductIds($websiteId, [$productId]);
        }
    }

    $existingByKey = [];
    foreach ($existingOffers as $offerRow) {
        $key = trim((string)($offerRow['combination_key'] ?? ''));
        if ($key !== '') {
            $existingByKey[$key] = $offerRow;
        }
    }

    $submittedRows = [];
    foreach ($generated as $row) {
        $submitted = [
            'combination' => $row['combination'],
            'combination_key' => $row['combination_key'],
            'sku' => $row['sku'],
            'amount_minor' => (int)$item['price_minor'],
            'currency' => 'CNY',
            'scope_state' => 'explicit',
            'store_id' => 0,
        ];
        $existing = $existingByKey[$row['combination_key']] ?? null;
        if ($existing !== null) {
            $submitted['global_offer_uuid'] = trim((string)($existing['global_offer_uuid'] ?? ''));
            $submitted['offer_version'] = (int)($existing['publish_version'] ?? 0);
            $submitted['identity_version'] = (int)($existing['identity_version'] ?? 0);
        }
        $submittedRows[] = $submitted;
    }

    $plan = $variantMatrix->reconcile($axes, $skuPrefix, $submittedRows, $existingOffers);
    $created = 0;
    $updated = 0;

    foreach ($plan['update'] as $row) {
        $uuid = trim((string)$row['global_offer_uuid']);
        $identity = $identities->resolveOfferByUuid($uuid)
            ?? throw new RuntimeException('offer_v2_identity_not_found:' . $uuid);
        if ($identity->sku !== (string)$row['sku']) {
            $identity = $identities->renameSku(
                $uuid,
                (string)$row['sku'],
                $identity->version,
                $websiteId,
                hash('sha256', $requestHash . ':rename:' . $row['combination_key']),
            );
        }
        if ($identity->status !== 'active') {
            $identity = $identities->transitionOfferStatus(
                $uuid,
                'active',
                $identity->version,
                $websiteId,
                hash('sha256', $requestHash . ':activate:' . $row['combination_key']),
            );
        }

        $local = $offers->findByGlobalUuid($websiteId, $uuid)
            ?? throw new RuntimeException('offer_projection_not_found:' . $uuid);
        $local = $offers->updateVersioned(
            $websiteId,
            (int)$local->getId(),
            (int)$row['offer_version'],
            [
                'sku' => $identity->sku,
                'identity_version' => $identity->version,
                'combination_key' => (string)$row['combination_key'],
                'is_default' => (string)$row['combination_key'] === $defaultKey ? 1 : 0,
                'requires_shipping' => 1,
                'type_config_json' => $jsonEncode(['combination' => $row['combination']]),
            ],
        );
        $prices->writeExplicit($websiteId, 0, (int)$local->getId(), 'CNY', (int)$item['price_minor']);
        $writeOfferAxisAttrs($websiteId, (int)$local->getId(), $row['combination']);
        foreach ($storeIds as $storeId) {
            $storeOffers->select($websiteId, $storeId, (int)$local->getId(), true);
            $seedInventory($websiteId, $storeId, (int)$local->getId(), 50, (string)$row['sku']);
        }
        if (strtolower(trim((string)$local->getData(Offer::schema_fields_STATUS))) !== 'published') {
            $local = $offers->publish(
                $websiteId,
                (int)$local->getId(),
                (int)$local->getData(Offer::schema_fields_PUBLISH_VERSION),
            );
        }
        $updated++;
    }

    foreach ($plan['create'] as $row) {
        $identity = $identities->createOffer(
            $globalProductUuid,
            (string)$row['sku'],
            hash('sha256', $requestHash . ':create:' . $row['combination_key']),
        );
        $local = $offers->create($websiteId, [
            Offer::schema_fields_PRODUCT_ID => $productId,
            Offer::schema_fields_GLOBAL_OFFER_UUID => $identity->globalOfferUuid,
            'sku' => $identity->sku,
            'identity_version' => $identity->version,
            'combination_key' => (string)$row['combination_key'],
            'is_default' => (string)$row['combination_key'] === $defaultKey ? 1 : 0,
            'requires_shipping' => 1,
            'type_config_json' => $jsonEncode(['combination' => $row['combination']]),
        ]);
        $prices->writeExplicit($websiteId, 0, (int)$local->getId(), 'CNY', (int)$item['price_minor']);
        $writeOfferAxisAttrs($websiteId, (int)$local->getId(), $row['combination']);
        foreach ($storeIds as $storeId) {
            $storeOffers->select($websiteId, $storeId, (int)$local->getId(), true);
            $seedInventory($websiteId, $storeId, (int)$local->getId(), 50, (string)$row['sku']);
        }
        $local = $offers->publish(
            $websiteId,
            (int)$local->getId(),
            (int)$local->getData(Offer::schema_fields_PUBLISH_VERSION),
        );
        $created++;
    }

    $finalOffers = $offers->listByProductIds($websiteId, [$productId]);
    $summary[] = [
        'sku' => $primarySku,
        'slug' => (string)$item['slug'],
        'status' => 'matrix_ready',
        'product_id' => $productId,
        'created' => $created,
        'updated' => $updated,
        'offers' => count($finalOffers),
        'expected' => $expectedCount,
        'default_key' => $defaultKey,
    ];
}

$catalogCache->notifyCatalogChanged($websiteId, 'hanfu_variant_matrix_seed');

echo json_encode(
    [
        'website_id' => $websiteId,
        'items' => $summary,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
) . PHP_EOL;
