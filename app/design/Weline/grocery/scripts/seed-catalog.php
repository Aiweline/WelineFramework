<?php

declare(strict_types=1);

/**
 * Seed grocery categories + published products for website code=grocery.
 *
 * Usage:
 *   php app/design/Weline/grocery/scripts/seed-catalog.php
 *   php app/design/Weline/grocery/scripts/seed-catalog.php grocery
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 5) . '/app/bootstrap.php';
require_once __DIR__ . '/GroceryCatalogSeeder.php';

$requestedCode = trim((string)($argv[1] ?? 'grocery'));
if ($requestedCode === '' || $requestedCode === '0' || $requestedCode === 'default') {
    fwrite(STDERR, "Refuse: grocery catalog seed must target code=grocery, not default/0.\n");
    exit(1);
}
if ($requestedCode !== 'grocery') {
    fwrite(STDERR, "Refuse: only website code grocery is accepted.\n");
    exit(1);
}

/** @var Website $websiteModel */
$websiteModel = ObjectManager::getInstance(Website::class);
$websiteId = 0;
foreach ($websiteModel->reset()->where(Website::schema_primary_key, 0, '>=')->select()->fetchArray() as $row) {
    if ((string)($row['code'] ?? '') === $requestedCode) {
        $websiteId = (int)($row['website_id'] ?? 0);
        break;
    }
}
if ($websiteId <= 0 || $websiteId === Website::ID_DEFAULT) {
    fwrite(STDERR, "Website grocery not found or resolved to default.\n");
    exit(1);
}

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

$categoryScript = dirname(__DIR__) . '/data/seed-categories.php';
$php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
passthru(escapeshellarg($php) . ' ' . escapeshellarg($categoryScript) . ' grocery', $catExit);
if ($catExit !== 0) {
    exit($catExit);
}

$result = \Weline\Design\Grocery\GroceryCatalogSeeder::create()->seed($websiteId);

// Re-run category seed after products so stray empty-code categories (e.g. Earrings)
// created by concurrent catalog noise are deactivated again.
passthru(escapeshellarg($php) . ' ' . escapeshellarg($categoryScript) . ' grocery', $catExit2);
if ($catExit2 !== 0) {
    exit($catExit2);
}

$offers = ObjectManager::getInstance(StorefrontCatalogViewService::class)->publishedOffers(24);

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'website_code' => $requestedCode,
    'seed' => $result,
    'published_offer_count_scope_note' => 'publishedOffers uses request scope; prefer seed.items',
    'seeded_skus' => array_column($result['items'], 'sku'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
