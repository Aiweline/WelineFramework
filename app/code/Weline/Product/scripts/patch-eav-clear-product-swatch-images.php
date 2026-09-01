<?php

declare(strict_types=1);

/**
 * 清除全局 EAV 选项上的商品样本图，仅保留抽象色值。
 *
 * 商品级 swatch_image / gallery_* 应维护在 product.type_configuration，不得写回 EAV 选项。
 *
 * Usage: php app/code/Weline/Product/scripts/patch-eav-clear-product-swatch-images.php
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductCatalogEavBootstrap;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

/** @var ProductCatalogEavBootstrap $bootstrap */
$bootstrap = ObjectManager::getInstance(ProductCatalogEavBootstrap::class);
$bootstrap->ensureStorefrontSchema();
$result = $bootstrap->ensureHanfuSchema();

echo json_encode([
    'ok' => true,
    'cleared_global_swatch_images' => (int)($result['cleared_global_swatch_images'] ?? 0),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
