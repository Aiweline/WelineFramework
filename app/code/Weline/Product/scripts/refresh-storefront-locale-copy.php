<?php

declare(strict_types=1);

require dirname(__DIR__, 5) . '/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontWebCatalogSeeder;

$websiteId = max(0, (int)($argv[1] ?? 0));
$result = ObjectManager::getInstance(StorefrontWebCatalogSeeder::class)->refreshLocaleCopy($websiteId);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
