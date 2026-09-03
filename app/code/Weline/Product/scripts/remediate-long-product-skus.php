<?php

declare(strict_types=1);

/**
 * Shorten existing product/offer SKUs that were generated from long title pinyin.
 *
 * New shape: {BRAND}-{CONTENT_HASH8}-{compactOptionTokens...}
 * Old SKUs are retained via offer SKU alias (renameSku).
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-long-product-skus.php --dry-run [--website=0] [--max-len=48]
 *   php app/code/Weline/Product/scripts/remediate-long-product-skus.php --apply [--website=0] [--max-len=48]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\ProductIdentityV2Service;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$modes = array_values(array_intersect($argv, ['--dry-run', '--apply']));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one mode: --dry-run or --apply.\n");
    exit(2);
}
$apply = $modes[0] === '--apply';
$websiteId = 0;
$maxLen = 48;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--website=')) {
        $websiteId = max(0, (int)substr($argument, strlen('--website=')));
    }
    if (str_starts_with($argument, '--max-len=')) {
        $maxLen = max(16, (int)substr($argument, strlen('--max-len=')));
    }
}

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var OfferRepository $offers */
$offers = ObjectManager::getInstance(OfferRepository::class);
/** @var ProductIdentityV2Service $identities */
$identities = ObjectManager::getInstance(ProductIdentityV2Service::class);
/** @var StorefrontCatalogCacheCoordinator $catalogCache */
$catalogCache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        (string)($db['hostname'] ?? '127.0.0.1'),
        (string)($db['hostport'] ?? '5432'),
        (string)($db['database'] ?? ''),
    ),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$compactToken = static function (string $value, int $tokenMax = 12): string {
    $part = strtoupper(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
    if ($part === '') {
        $part = 'V' . strtoupper(substr(hash('sha256', $value), 0, 8));
    }
    if (strlen($part) <= $tokenMax) {
        return $part;
    }

    return rtrim(substr($part, 0, max(1, $tokenMax - 5)), '-')
        . '-'
        . strtoupper(substr(hash('sha256', $value), 0, 4));
};

$extractPrefix = static function (string $sku): ?array {
    $sku = strtoupper(trim($sku));
    if (!preg_match('/^([A-Z0-9]+)(?:-.+)?-([A-F0-9]{8})(?:-|$)/', $sku, $match)) {
        return null;
    }
    $brand = $match[1];
    if (strlen($brand) > 16) {
        $brand = rtrim(substr($brand, 0, 16), '-');
    }

    return [
        'brand' => $brand,
        'hash' => $match[2],
        'prefix' => $brand . '-' . $match[2],
    ];
};

$optionCodeById = [];
$optionRows = $pdo->query('SELECT option_id, code FROM w_eav_attribute_option')->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($optionRows as $row) {
    $optionCodeById[(int)$row['option_id']] = (string)$row['code'];
}

$parseCombination = static function (string $combinationKey) use ($optionCodeById): array {
    $parts = [];
    foreach (explode('|', $combinationKey) as $segment) {
        $segment = trim($segment);
        if ($segment === '' || !str_contains($segment, '=')) {
            continue;
        }
        [$code, $value] = explode('=', $segment, 2);
        $code = rawurldecode($code);
        $value = rawurldecode($value);
        if (ctype_digit($value) && isset($optionCodeById[(int)$value])) {
            $parts[$code] = $optionCodeById[(int)$value];
        } else {
            $parts[$code] = $value;
        }
    }
    ksort($parts, SORT_STRING);

    return $parts;
};

$buildSku = static function (string $prefix, array $combination) use ($compactToken): string {
    $parts = [$prefix];
    foreach ($combination as $value) {
        $parts[] = $compactToken((string)$value);
    }
    $sku = trim(implode('-', array_filter($parts, static fn(string $part): bool => $part !== '')), '-');
    if (strlen($sku) <= 64) {
        return $sku;
    }

    return rtrim(substr($sku, 0, 55), '-') . '-' . substr(hash('sha256', $sku), 0, 8);
};

$allProducts = $products->listAll($websiteId);
$plan = [];
$seenNew = [];

foreach ($allProducts as $product) {
    if (!is_array($product)) {
        continue;
    }
    $productId = (int)($product[Product::schema_fields_ID] ?? 0);
    $productUuid = trim((string)($product[Product::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''));
    $productSku = trim((string)($product[Product::schema_fields_SKU] ?? ''));
    if ($productId <= 0) {
        continue;
    }
    $localOffers = $offers->listByProductIds($websiteId, [$productId]);
    $productNeeds = strlen($productSku) > $maxLen;
    $offerRows = [];
    foreach ($localOffers as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sku = trim((string)($row[Offer::schema_fields_SKU] ?? $row['sku'] ?? ''));
        if ($sku === '') {
            continue;
        }
        if (strlen($sku) > $maxLen) {
            $productNeeds = true;
        }
        $offerRows[] = $row;
    }
    if (!$productNeeds || $offerRows === []) {
        continue;
    }

    $prefixInfo = $extractPrefix($productSku);
    if ($prefixInfo === null) {
        foreach ($offerRows as $row) {
            $prefixInfo = $extractPrefix((string)($row[Offer::schema_fields_SKU] ?? $row['sku'] ?? ''));
            if ($prefixInfo !== null) {
                break;
            }
        }
    }
    if ($prefixInfo === null) {
        fwrite(STDERR, "skip product {$productId}: cannot extract brand/hash from SKU\n");
        continue;
    }
    $prefix = (string)$prefixInfo['prefix'];

    $renames = [];
    foreach ($offerRows as $row) {
        $oldSku = trim((string)($row[Offer::schema_fields_SKU] ?? $row['sku'] ?? ''));
        $uuid = trim((string)($row[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? $row['global_offer_uuid'] ?? ''));
        $combinationKey = trim((string)($row['combination_key'] ?? ''));
        if ($oldSku === '' || $uuid === '') {
            continue;
        }
        $combination = $parseCombination($combinationKey);
        $newSku = $combination === []
            ? $prefix
            : $buildSku($prefix, $combination);
        if ($newSku === $oldSku) {
            continue;
        }
        if (isset($seenNew[strtolower($newSku)])) {
            $newSku = rtrim(substr($newSku, 0, 55), '-') . '-O' . (int)($row[Offer::schema_fields_ID] ?? $row['offer_id'] ?? 0);
        }
        $seenNew[strtolower($newSku)] = true;
        $identity = $identities->resolveOfferByUuid($uuid);
        $renames[] = [
            'offer_id' => (int)($row[Offer::schema_fields_ID] ?? $row['offer_id'] ?? 0),
            'global_offer_uuid' => $uuid,
            'old_sku' => $oldSku,
            'new_sku' => $newSku,
            'identity_version' => $identity?->version ?? (int)($row[Offer::schema_fields_IDENTITY_VERSION] ?? $row['identity_version'] ?? 0),
            'publish_version' => (int)($row[Offer::schema_fields_PUBLISH_VERSION] ?? $row['publish_version'] ?? 0),
            'is_default' => (int)($row['is_default'] ?? 0),
        ];
    }
    if ($renames === []) {
        continue;
    }

    usort(
        $renames,
        static fn(array $left, array $right): int => ((int)$right['is_default']) <=> ((int)$left['is_default'])
            ?: ((int)$left['offer_id']) <=> ((int)$right['offer_id']),
    );
    $primaryNew = (string)$renames[0]['new_sku'];

    $plan[] = [
        'product_id' => $productId,
        'global_product_uuid' => $productUuid,
        'old_product_sku' => $productSku,
        'new_product_sku' => $primaryNew,
        'prefix' => $prefix,
        'offers' => $renames,
    ];
}

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'website_id' => $websiteId,
    'max_len' => $maxLen,
    'products' => count($plan),
    'offers' => array_sum(array_map(static fn(array $item): int => count($item['offers']), $plan)),
    'items' => [],
];

foreach ($plan as $item) {
    $entry = [
        'product_id' => $item['product_id'],
        'prefix' => $item['prefix'],
        'product_sku' => [$item['old_product_sku'], $item['new_product_sku']],
        'offer_skus' => [],
    ];
    foreach ($item['offers'] as $rename) {
        $entry['offer_skus'][] = [
            'offer_id' => $rename['offer_id'],
            'from' => $rename['old_sku'],
            'to' => $rename['new_sku'],
            'from_len' => strlen($rename['old_sku']),
            'to_len' => strlen($rename['new_sku']),
        ];
        if (!$apply) {
            continue;
        }
        $identity = $identities->renameSku(
            $rename['global_offer_uuid'],
            $rename['new_sku'],
            (int)$rename['identity_version'],
            $websiteId,
            hash('sha256', 'remediate-long-sku:' . $rename['global_offer_uuid'] . ':' . $rename['new_sku']),
        );
        $local = $offers->findByGlobalUuid($websiteId, $rename['global_offer_uuid'])
            ?? throw new RuntimeException('offer_projection_missing:' . $rename['global_offer_uuid']);
        $offers->updateVersioned(
            $websiteId,
            (int)$local->getId(),
            (int)$local->getData(Offer::schema_fields_PUBLISH_VERSION),
            [
                'sku' => $identity->sku,
                'identity_version' => $identity->version,
            ],
        );
    }
    if ($apply) {
        $product = $products->findById($websiteId, (int)$item['product_id'])
            ?? throw new RuntimeException('product_missing:' . $item['product_id']);
        $products->updateVersioned(
            $websiteId,
            (int)$item['product_id'],
            (int)$product->getData(Product::schema_fields_PUBLISH_VERSION),
            [Product::schema_fields_SKU => $item['new_product_sku']],
        );
    }
    $report['items'][] = $entry;
}

if ($apply && $plan !== []) {
    $catalogCache->notifyCatalogChanged($websiteId, 'remediate-long-product-skus', [
        'products' => count($plan),
    ]);
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
