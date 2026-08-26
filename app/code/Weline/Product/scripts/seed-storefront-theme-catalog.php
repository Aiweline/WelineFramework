<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontThemeCatalogSeeder;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));
$result = ObjectManager::getInstance(StorefrontThemeCatalogSeeder::class)->seed($websiteId);
$offers = ObjectManager::getInstance(StorefrontCatalogViewService::class)->publishedOffers(20);

echo json_encode([
    'ok' => true,
    'seed' => $result,
    'published_offer_count' => count($offers),
    'sample_names' => array_values(array_map(
        static fn(array $offer): string => (string)($offer['name'] ?? ''),
        array_slice($offers, 0, 8),
    )),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
