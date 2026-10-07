<?php

declare(strict_types=1);

/**
 * Fill missing en_US product names from reviewed Hanfu source titles.
 *
 * php app/code/Weline/Product/scripts/remediate-hanfu-product-en-names.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-hanfu-product-en-names.php --apply
 * Optional: --website=0. The default is a read-only dry run.
 * Existing locale text, cleared overlays and store-specific values are preserved.
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\CuratedProductNameTranslation;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$copy = new CuratedProductNameTranslation(require dirname(__DIR__) . '/data/hanfu-product-en-names.php');
$products = ObjectManager::getInstance(ProductRepository::class);
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
$published = array_values(array_filter(
    $products->listAll($websiteId),
    static fn(array $row): bool => ($row['status'] ?? '') === 'published',
));
$productIds = array_map(static fn(array $row): int => (int)$row['product_id'], $published);
$nameRows = [];
foreach ($attributes->listExplicitRows($websiteId, 'product', $productIds, [0]) as $row) {
    if ($row['attribute_code'] === 'name') {
        $nameRows[$row['entity_id']][$row['locale']] = $row;
    }
}

$items = [];
$skipped = 0;
foreach ($published as $product) {
    $productId = (int)$product['product_id'];
    $rows = $nameRows[$productId] ?? [];
    $source = $rows['zh_Hans_CN'] ?? $rows[''] ?? null;
    if ($source === null || $source['cleared']) {
        ++$skipped;
        continue;
    }
    $sourceName = trim((string)$source['value']);
    $name = $copy->replacement($sourceName, $rows['en_US'] ?? null);
    if ($name === null) {
        ++$skipped;
        continue;
    }
    if ($apply) {
        $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'name', 'en_US', $name, true);
    }
    $items[] = [
        'product_id' => $productId,
        'sku' => (string)$product['sku'],
        'source_name' => $sourceName,
        'en_US' => $name,
    ];
}

if ($apply && $items !== []) {
    ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
        $websiteId,
        'hanfu_en_product_names_backfilled',
        ['locale' => 'en_US', 'product_ids' => array_column($items, 'product_id')],
    );
}

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'website_id' => $websiteId,
    'published' => count($published),
    'matched' => count($items),
    'updated' => $apply ? count($items) : 0,
    'skipped' => $skipped,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
