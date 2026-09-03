<?php

declare(strict_types=1);

/**
 * Expand an existing configurable product into a full variant Offer matrix.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/expand-configurable-matrix.php [website_id] [product_id|slug]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\ProductCatalogEavBootstrap;
use Weline\Product\Service\ProductConfigurableMatrixSeedService;
use Weline\Product\Service\ProductIdentityV2Service;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));
$handle = trim((string)($argv[2] ?? ''));

if ($handle === '') {
    fwrite(STDERR, "Usage: php expand-configurable-matrix.php [website_id] [product_id|slug]\n");
    exit(1);
}

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);
$hanfuEav = ObjectManager::getInstance(ProductCatalogEavBootstrap::class)->ensureHanfuSchema();

/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var OfferRepository $offers */
$offers = ObjectManager::getInstance(OfferRepository::class);
/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
/** @var ProductConfigurableMatrixSeedService $matrixSeed */
$matrixSeed = ObjectManager::getInstance(ProductConfigurableMatrixSeedService::class);
/** @var StoreCatalogInterface $storeCatalog */
$storeCatalog = ObjectManager::getInstance(StoreCatalogInterface::class);

$productId = ctype_digit($handle) ? (int)$handle : 0;
if ($productId <= 0) {
    foreach ($products->listAll($websiteId) as $row) {
        $slugRow = strtolower(trim((string)($row['slug'] ?? '')));
        if ($slugRow === strtolower($handle)) {
            $productId = (int)($row['product_id'] ?? $row['id'] ?? 0);
            break;
        }
    }
    if ($productId <= 0) {
        foreach ($attributes->listExplicitRows($websiteId, 'product', [], [0]) as $row) {
            if (strtolower(trim((string)($row['attribute_code'] ?? ''))) !== 'slug') {
                continue;
            }
            if (strtolower(trim((string)($row['value'] ?? ''))) !== strtolower($handle)) {
                continue;
            }
            $productId = (int)($row['entity_id'] ?? 0);
            break;
        }
    }
}

if ($productId <= 0) {
    fwrite(STDERR, "Product not found: {$handle}\n");
    exit(1);
}

$productOffers = $offers->listByProductIds($websiteId, [$productId]);
if ($productOffers === []) {
    fwrite(STDERR, "No offers for product {$productId}\n");
    exit(1);
}

$primaryOffer = $productOffers[0];
$primaryOfferId = (int)($primaryOffer['offer_id'] ?? $primaryOffer['id'] ?? 0);
$primarySku = trim((string)($primaryOffer['sku'] ?? ''));
if ($primarySku === '') {
    $offerUuid = trim((string)($primaryOffer['global_offer_uuid'] ?? ''));
    if ($offerUuid !== '') {
        $identity = ObjectManager::getInstance(ProductIdentityV2Service::class)->resolveOfferByUuid($offerUuid);
        $primarySku = trim((string)($identity?->sku ?? ''));
    }
}
if ($primarySku === '') {
    fwrite(STDERR, "Primary offer SKU missing for product {$productId}\n");
    exit(1);
}

$productAttributeRows = $attributes->listExplicitRows(
    $websiteId,
    'product',
    [$productId],
    [0],
);
$availableValues = [];
foreach ($productAttributeRows as $row) {
    if (strtolower(trim((string)($row['value_type'] ?? ''))) !== 'multiselect'
        || !is_array($row['value'] ?? null)
    ) {
        continue;
    }
    $code = strtolower(trim((string)($row['attribute_code'] ?? '')));
    if ($code !== '') {
        $availableValues[$code] = $row['value'];
    }
}
if ($availableValues === []) {
    fwrite(STDERR, "Product {$productId} has no Product EAV multiselect variant values\n");
    exit(1);
}

$offerValues = [];
foreach ($attributes->listExplicitRows($websiteId, 'offer', [$primaryOfferId], [0]) as $row) {
    $code = strtolower(trim((string)($row['attribute_code'] ?? '')));
    $value = $row['value'] ?? null;
    if ($code === ''
        || !isset($availableValues[$code])
        || strtolower(trim((string)($row['value_type'] ?? ''))) !== 'select'
        || !is_scalar($value)
        || trim((string)$value) === ''
    ) {
        continue;
    }
    $offerValues[$code] = trim((string)$value);
}

$axisResolver = ObjectManager::getInstance(\Weline\Product\Service\StorefrontVariantAxisResolver::class);
$axes = $axisResolver->buildAxes($offerValues, $availableValues);
$axisCodes = array_values(array_filter(array_map(
    static fn(array $axis): string => strtolower(trim((string)($axis['code'] ?? ''))),
    $axes,
)));
if ($axes === [] || $axisCodes === []) {
    fwrite(STDERR, "Could not resolve Product EAV variant axes for product {$productId}\n");
    exit(1);
}

$defaults = [];
foreach ($axes as $axis) {
    $code = strtolower(trim((string)($axis['code'] ?? '')));
    $selected = trim((string)($offerValues[$code] ?? ''));
    if ($selected === '') {
        $selected = trim((string)($axis['options'][0]['value'] ?? ''));
    }
    if ($code !== '' && $selected !== '') {
        $defaults[$code] = $selected;
    }
}

$priceMinor = 0;
foreach ($productOffers as $offerRow) {
    $offerId = (int)($offerRow['id'] ?? 0);
    if ($offerId <= 0) {
        continue;
    }
    $priceMinor = max($priceMinor, (int)(ObjectManager::getInstance(\Weline\Product\Repository\PriceRepository::class)
        ->read($websiteId, 0, $offerId, 'CNY')->value ?? 0));
}
if ($priceMinor <= 0) {
    $priceMinor = 4900;
}

$storeIds = [];
foreach ($storeCatalog->byWebsite($websiteId) as $store) {
    if ($store->id >= 0) {
        $storeIds[] = $store->id;
    }
}
if ($storeIds === []) {
    $storeIds = [1];
}

$result = $matrixSeed->expand(
    $websiteId,
    $productId,
    $primaryOfferId,
    $primarySku,
    $axes,
    $defaults,
    $priceMinor,
    $storeIds,
);

ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)
    ->notifyCatalogChanged($websiteId, 'expand-configurable-matrix');

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'product_id' => $productId,
    'primary_offer_id' => $primaryOfferId,
    'primary_sku' => $primarySku,
    'axes' => array_column($axes, 'code'),
    'defaults' => $defaults,
    'matrix' => $result,
    'eav' => $hanfuEav,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
