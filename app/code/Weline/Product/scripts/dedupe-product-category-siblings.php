<?php

declare(strict_types=1);

/**
 * Remove duplicate product categories that share the same localized sibling name.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/dedupe-product-category-siblings.php [website_id] [parent_id] [name] [locale]
 *
 * Examples:
 *   php app/code/Weline/Product/scripts/dedupe-product-category-siblings.php 0 0 汉服 zh_Hans_CN
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCategoryTreeIndex;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));
$parentId = max(0, (int)($argv[2] ?? 0));
$name = trim((string)($argv[3] ?? '汉服'));
$locale = trim((string)($argv[4] ?? 'zh_Hans_CN'));

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

/** @var ProductCategoryAdminService $categoryAdmin */
$categoryAdmin = ObjectManager::getInstance(ProductCategoryAdminService::class);
/** @var StorefrontCategoryTreeIndex $treeIndex */
$treeIndex = ObjectManager::getInstance(StorefrontCategoryTreeIndex::class);

$removed = $categoryAdmin->dedupeSiblingsByLocalizedName($websiteId, $parentId, $name, $locale);
$treeIndex->invalidate($websiteId);

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'parent_id' => $parentId,
    'name' => $name,
    'locale' => $locale,
    'removed_category_ids' => $removed,
    'root_count' => count($treeIndex->nestedRoots($websiteId)),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
