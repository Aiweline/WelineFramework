<?php

declare(strict_types=1);

/**
 * Seed a demo storefront category forest for department full-tree nav.
 *
 * Usage: php app/code/Weline/Product/scripts/seed-storefront-category-tree.php [website_id]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Service\ProductAdminMutationService;
use Weline\Product\Service\StorefrontCategoryTreeIndex;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));

/** @var ProductAdminMutationService $mutations */
$mutations = ObjectManager::getInstance(ProductAdminMutationService::class);
/** @var CategoryRepository $categories */
$categories = ObjectManager::getInstance(CategoryRepository::class);
/** @var StorefrontCategoryTreeIndex $tree */
$tree = ObjectManager::getInstance(StorefrontCategoryTreeIndex::class);

// Migrate confusing demo root `/home` → `/home-living` (and all descendants).
$renamed = [];
foreach ($categories->listAll($websiteId) as $row) {
    $id = (int)($row['category_id'] ?? 0);
    $stored = strtolower(trim(str_replace('\\', '/', (string)($row['path'] ?? '')), '/'));
    if ($id <= 0 || $stored === '') {
        continue;
    }
    if ($stored !== 'home' && !str_starts_with($stored, 'home/')) {
        continue;
    }
    $newPath = '/' . preg_replace('#^home\b#', 'home-living', $stored, 1);
    $mutations->updateCategoryPath($websiteId, $id, $newPath);
    $renamed[] = ['id' => $id, 'from' => '/' . $stored, 'to' => $newPath];
}

$ensure = static function (string $path, int $parentId) use ($mutations, $categories, $websiteId): int {
    $normalized = '/' . trim(str_replace('\\', '/', $path), '/');
    $needle = strtolower(ltrim($normalized, '/'));
    foreach ($categories->listAll($websiteId) as $row) {
        $stored = strtolower(trim(str_replace('\\', '/', (string)($row['path'] ?? '')), '/'));
        if ($stored === $needle) {
            return (int)($row['category_id'] ?? 0);
        }
    }

    return (int)$mutations->createCategory($websiteId, $normalized, $parentId)->getId();
};

$books = $ensure('/books', 0);
$fiction = $ensure('/books/fiction', $books);
$nonfiction = $ensure('/books/nonfiction', $books);
$scifi = $ensure('/books/fiction/scifi', $fiction);
$fantasy = $ensure('/books/fiction/fantasy', $fiction);
$mystery = $ensure('/books/fiction/mystery', $fiction);
$romance = $ensure('/books/fiction/romance', $fiction);

$homeLiving = $ensure('/home-living', 0);
$kitchen = $ensure('/home-living/kitchen', $homeLiving);
$furniture = $ensure('/home-living/furniture', $homeLiving);
$decor = $ensure('/home-living/decor', $homeLiving);
$cups = $ensure('/home-living/kitchen/cups', $kitchen);
$plates = $ensure('/home-living/kitchen/plates', $kitchen);
$utensils = $ensure('/home-living/kitchen/utensils', $kitchen);

$electronics = $ensure('/electronics', 0);
$phones = $ensure('/electronics/phones', $electronics);
$computers = $ensure('/electronics/computers', $electronics);

$tree->invalidate($websiteId);

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'renamed' => $renamed,
    'ids' => [
        'books' => $books,
        'fiction' => $fiction,
        'nonfiction' => $nonfiction,
        'scifi' => $scifi,
        'fantasy' => $fantasy,
        'mystery' => $mystery,
        'romance' => $romance,
        'home_living' => $homeLiving,
        'kitchen' => $kitchen,
        'furniture' => $furniture,
        'decor' => $decor,
        'cups' => $cups,
        'plates' => $plates,
        'utensils' => $utensils,
        'electronics' => $electronics,
        'phones' => $phones,
        'computers' => $computers,
    ],
    'roots' => count($tree->nestedRoots($websiteId)),
    'total' => count($categories->listAll($websiteId)),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
