<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontWebCatalogSeeder;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));
$result = ObjectManager::getInstance(StorefrontWebCatalogSeeder::class)->seed($websiteId);
$offers = ObjectManager::getInstance(StorefrontCatalogViewService::class)->publishedOffers(24);

usort(
    $offers,
    static fn(array $left, array $right): int => (int)($right['product_id'] ?? 0) <=> (int)($left['product_id'] ?? 0),
);

echo json_encode([
    'ok' => true,
    'seed' => $result,
    'published_offer_count' => count($offers),
    'latest_products' => array_values(array_map(
        static fn(array $offer): array => [
            'product_id' => (int)($offer['product_id'] ?? 0),
            'name' => (string)($offer['name'] ?? ''),
            'price' => round(((int)($offer['unit_price_minor'] ?? 0)) / 100, 2),
            'slug' => (string)($offer['slug'] ?? ''),
        ],
        array_slice($offers, 0, 12),
    )),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
