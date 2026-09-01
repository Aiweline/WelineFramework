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
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontCategoryTreeIndex;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));

/** @var ProductAdminMutationService $mutations */
$mutations = ObjectManager::getInstance(ProductAdminMutationService::class);
/** @var CategoryRepository $categories */
$categories = ObjectManager::getInstance(CategoryRepository::class);
/** @var StorefrontCategoryTreeIndex $tree */
$tree = ObjectManager::getInstance(StorefrontCategoryTreeIndex::class);
/** @var ProductCategoryAttributeService $categoryAttributes */
$categoryAttributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);

$localizedNames = [
    '/books' => ['zh_Hans_CN' => '图书', 'en_US' => 'Books'],
    '/books/fiction' => ['zh_Hans_CN' => '小说', 'en_US' => 'Fiction'],
    '/books/nonfiction' => ['zh_Hans_CN' => '非虚构', 'en_US' => 'Nonfiction'],
    '/books/fiction/scifi' => ['zh_Hans_CN' => '科幻', 'en_US' => 'Sci-Fi'],
    '/books/fiction/fantasy' => ['zh_Hans_CN' => '奇幻', 'en_US' => 'Fantasy'],
    '/books/fiction/mystery' => ['zh_Hans_CN' => '悬疑', 'en_US' => 'Mystery'],
    '/books/fiction/romance' => ['zh_Hans_CN' => '言情', 'en_US' => 'Romance'],
    '/home-living' => ['zh_Hans_CN' => '家居生活', 'en_US' => 'Home Living'],
    '/home-living/kitchen' => ['zh_Hans_CN' => '厨房', 'en_US' => 'Kitchen'],
    '/home-living/furniture' => ['zh_Hans_CN' => '家具', 'en_US' => 'Furniture'],
    '/home-living/decor' => ['zh_Hans_CN' => '装饰', 'en_US' => 'Decor'],
    '/home-living/kitchen/cups' => ['zh_Hans_CN' => '杯具', 'en_US' => 'Cups'],
    '/home-living/kitchen/plates' => ['zh_Hans_CN' => '餐盘', 'en_US' => 'Plates'],
    '/home-living/kitchen/utensils' => ['zh_Hans_CN' => '厨具', 'en_US' => 'Utensils'],
    '/electronics' => ['zh_Hans_CN' => '电子产品', 'en_US' => 'Electronics'],
    '/electronics/phones' => ['zh_Hans_CN' => '手机', 'en_US' => 'Phones'],
    '/electronics/computers' => ['zh_Hans_CN' => '电脑', 'en_US' => 'Computers'],
];

$writeLocalizedNames = static function (int $websiteId, int $categoryId, string $path) use (
    $categoryAttributes,
    $localizedNames,
): void {
    $normalized = '/' . trim(str_replace('\\', '/', $path), '/');
    $labels = $localizedNames[$normalized] ?? null;
    if (!is_array($labels)) {
        return;
    }
    foreach ($labels as $locale => $label) {
        $label = trim((string)$label);
        if ($label === '') {
            continue;
        }
        $categoryAttributes->writeName($websiteId, $categoryId, $label, (string)$locale);
    }
};

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

$ensure = static function (string $path, int $parentId) use (
    $mutations,
    $categories,
    $websiteId,
    $writeLocalizedNames,
): int {
    $normalized = '/' . trim(str_replace('\\', '/', $path), '/');
    $needle = strtolower(ltrim($normalized, '/'));
    foreach ($categories->listAll($websiteId) as $row) {
        $stored = strtolower(trim(str_replace('\\', '/', (string)($row['path'] ?? '')), '/'));
        if ($stored === $needle) {
            $categoryId = (int)($row['category_id'] ?? 0);
            if ($categoryId > 0) {
                $writeLocalizedNames($websiteId, $categoryId, $normalized);
            }

            return $categoryId;
        }
    }

    $categoryId = (int)$mutations->createCategory($websiteId, $normalized, $parentId)->getId();
    $writeLocalizedNames($websiteId, $categoryId, $normalized);

    return $categoryId;
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
