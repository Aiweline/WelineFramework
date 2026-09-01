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
ObjectManager::getInstance(ProductCatalogEavBootstrap::class)->ensureHanfuSchema();

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

$typeConfigRaw = '';
foreach ($attributes->listExplicitRows($websiteId, 'product', [$productId], [0]) as $row) {
    if (strtolower(trim((string)($row['attribute_code'] ?? ''))) === 'type_configuration') {
        $typeConfigRaw = trim((string)($row['value'] ?? ''));
        break;
    }
}

$config = [];
if ($typeConfigRaw !== '') {
    try {
        $decoded = json_decode($typeConfigRaw, true, 512, JSON_THROW_ON_ERROR);
        $config = is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
    }
}

$axisCodes = [];
foreach ((array)($config['axes'] ?? []) as $axis) {
    if (is_array($axis)) {
        $code = strtolower(trim((string)($axis['code'] ?? '')));
        if ($code !== '') {
            $axisCodes[] = $code;
        }
    } elseif (is_string($axis) && trim($axis) !== '') {
        $axisCodes[] = strtolower(trim($axis));
    }
}

if ($axisCodes === []) {
    fwrite(STDERR, "Product {$productId} has no type_configuration.axes\n");
    exit(1);
}

$eavBootstrap = ObjectManager::getInstance(ProductCatalogEavBootstrap::class);
$hanfuEav = $eavBootstrap->ensureHanfuSchema();

$axisResolver = ObjectManager::getInstance(\Weline\Product\Service\StorefrontVariantAxisResolver::class);

$axes = [];
foreach ((array)($config['axes'] ?? []) as $axisConfig) {
    if (!is_array($axisConfig)) {
        continue;
    }
    $code = strtolower(trim((string)($axisConfig['code'] ?? '')));
    if ($code === '') {
        continue;
    }
    $built = $axisResolver->buildAxes(['axes' => [$axisConfig]], []);
    if ($built !== []) {
        $axes[] = $built[0];
    }
}
if ($axes === [] && $axisCodes !== []) {
    foreach ($axisCodes as $code) {
        $built = $axisResolver->buildAxes(['axes' => [['code' => $code]]], []);
        if ($built !== []) {
            $axes[] = $built[0];
        }
    }
}

if ($axes === []) {
    fwrite(STDERR, "Could not resolve EAV options for axes: " . implode(', ', $axisCodes) . "\n");
    exit(1);
}

$defaults = [];
foreach ($axisCodes as $code) {
    foreach ($attributes->listExplicitRows($websiteId, 'product', [$productId], [0]) as $row) {
        if (strtolower(trim((string)($row['attribute_code'] ?? ''))) === $code) {
            $defaults[$code] = trim((string)($row['value'] ?? ''));
        }
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
