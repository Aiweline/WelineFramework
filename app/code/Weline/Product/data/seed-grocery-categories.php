<?php

declare(strict_types=1);

/**
 * Seed Neighborhood Grocery taxonomy for website code=grocery.
 *
 * Usage:
 *   php app/code/Weline/Product/data/seed-grocery-categories.php
 *   php app/code/Weline/Product/data/seed-grocery-categories.php grocery
 *
 * Idempotent by category code. Never writes Website::ID_DEFAULT (0).
 * Non-grocery categories on the target website are set inactive so Toys/Hanfu
 * clone trees stop appearing in storefront nav.
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;
use Weline\Product\Service\StorefrontCategoryTreeIndex;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_CODE = 'grocery';

$requestedCode = trim((string)($argv[1] ?? WEBSITE_CODE));
if ($requestedCode === '' || $requestedCode === '0' || $requestedCode === 'default') {
    fwrite(STDERR, "Refuse: Grocery seed must target website code grocery, not default/0.\n");
    exit(1);
}
if ($requestedCode !== WEBSITE_CODE) {
    fwrite(STDERR, "Refuse: Grocery seed only accepts code=grocery (got {$requestedCode}).\n");
    exit(1);
}

/** @var Website $websiteModel */
$websiteModel = ObjectManager::getInstance(Website::class);
$websiteRow = $websiteModel->reset()->where(Website::schema_primary_key, 0, '>=')->select()->fetchArray();
$websiteId = 0;
foreach ($websiteRow as $row) {
    if ((string)($row['code'] ?? '') === $requestedCode) {
        $websiteId = (int)($row['website_id'] ?? 0);
        break;
    }
}
if ($websiteId <= 0) {
    fwrite(STDERR, "Website not found for code={$requestedCode}\n");
    exit(1);
}
if ($websiteId === Website::ID_DEFAULT) {
    fwrite(STDERR, "Refuse: resolved website is default (0).\n");
    exit(1);
}

/**
 * @return list<array<string, mixed>>
 */
function groceryTree(): array
{
    $n = static function (
        string $code,
        string $zhName,
        string $zhSummary,
        string $zhDesc,
        string $enName,
        string $enSummary,
        string $enDesc,
        array $children = [],
    ): array {
        return [
            'code' => $code,
            'i18n' => [
                'zh_Hans_CN' => [
                    'name' => $zhName,
                    'summary' => $zhSummary,
                    'description' => $zhDesc,
                ],
                'en_US' => [
                    'name' => $enName,
                    'summary' => $enSummary,
                    'description' => $enDesc,
                ],
            ],
            'children' => $children,
        ];
    };

    return [
        $n(
            'grocery-produce',
            '生鲜蔬果',
            '当季蔬菜与水果，邻里餐桌新鲜起点。',
            '精选时令蔬果，适合当日烹饪与轻食搭配。',
            'Fresh Produce',
            'Seasonal vegetables and fruit for everyday cooking.',
            'Picked for same-day meals and simple neighborhood recipes.',
        ),
        $n(
            'grocery-dairy-bakery',
            '乳品烘焙',
            '牛奶酸奶与基础烘焙，早餐货架常备。',
            '乳制品与面包烘焙，方便早餐与加餐。',
            'Dairy & Bakery',
            'Milk, yogurt, and everyday bakery staples.',
            'Breakfast-ready dairy and bakery picks for the shelf.',
        ),
        $n(
            'grocery-pantry',
            '粮油调味',
            '米面粮油与常用调味，厨房底仓。',
            '主食与调味品，撑起日常烹调。',
            'Pantry Staples',
            'Rice, oil, and everyday seasonings.',
            'Kitchen basics that keep weeknight cooking moving.',
        ),
        $n(
            'grocery-snacks-drinks',
            '零食饮料',
            '休闲零食与常温饮品，邻里补给。',
            '小包装零食与饮料，适合随手补给。',
            'Snacks & Drinks',
            'Snacks and everyday drinks for quick top-ups.',
            'Convenient packs for study, work, and family moments.',
        ),
        $n(
            'grocery-household',
            '日用清洁',
            '洗衣清洁与居家日用品。',
            '家务清洁与日用消耗品，货架到家更省心。',
            'Household Essentials',
            'Laundry, cleaning, and home consumables.',
            'Practical household goods for a tidy neighborhood home.',
        ),
    ];
}

/**
 * @return array<string, int> code => category_id
 */
function indexCodesById(
    CategoryRepository $categories,
    ProductCategoryAttributeService $attributes,
    int $websiteId,
): array {
    $ids = [];
    foreach ($categories->listAll($websiteId) as $row) {
        $id = (int)($row['category_id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if ($ids === []) {
        return [];
    }
    $map = $attributes->readCodeMap($websiteId, $ids, 'en_US');
    $zhMap = $attributes->readCodeMap($websiteId, $ids, 'zh_Hans_CN');
    $out = [];
    foreach ($ids as $id) {
        $code = strtolower(trim((string)($map[$id] ?? '')));
        if ($code === '') {
            $code = strtolower(trim((string)($zhMap[$id] ?? '')));
        }
        if ($code !== '') {
            $out[$code] = $id;
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $nodes
 * @param array<string, int> $codeIndex
 * @return list<string>
 */
function collectCodes(array $nodes): array
{
    $codes = [];
    foreach ($nodes as $node) {
        $code = strtolower(trim((string)($node['code'] ?? '')));
        if ($code !== '') {
            $codes[] = $code;
        }
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children !== []) {
            $codes = array_merge($codes, collectCodes($children));
        }
    }

    return $codes;
}

/**
 * @param array<string, int> $codeIndex
 */
function deactivateForeignCategories(
    ProductCategoryAdminService $admin,
    ProductCategoryAttributeService $attributes,
    CategoryRepository $categories,
    int $websiteId,
    array $keepCodes,
    array $codeIndex,
): int {
    $keep = array_fill_keys($keepCodes, true);
    $deactivated = 0;
    foreach ($categories->listAll($websiteId) as $row) {
        $categoryId = (int)($row['category_id'] ?? 0);
        if ($categoryId <= 0) {
            continue;
        }
        $status = strtolower(trim((string)($row['status'] ?? 'active')));
        if ($status === 'inactive') {
            continue;
        }
        $code = '';
        foreach ($codeIndex as $c => $id) {
            if ($id === $categoryId) {
                $code = (string)$c;
                break;
            }
        }
        if ($code !== '' && isset($keep[$code])) {
            continue;
        }
        $nameEn = trim((string)($attributes->readNameMap($websiteId, [$categoryId], 'en_US')[$categoryId] ?? ''));
        if ($nameEn === '') {
            $nameEn = trim((string)($attributes->readNameMap($websiteId, [$categoryId], 'zh_Hans_CN')[$categoryId] ?? 'category'));
        }
        $parentId = (int)($row['parent_id'] ?? 0);
        $admin->save(
            $websiteId,
            $categoryId,
            $parentId,
            $nameEn !== '' ? $nameEn : 'category-' . $categoryId,
            'inactive',
            $code,
            'en_US',
        );
        echo "- #{$categoryId} inactive " . ($code !== '' ? $code : '(no-code)') . " {$nameEn}" . PHP_EOL;
        ++$deactivated;
    }

    return $deactivated;
}

/**
 * @param list<array<string, mixed>> $nodes
 * @param array<string, int> $codeIndex
 */
function seedNodes(
    ProductCategoryAdminService $admin,
    ProductCategoryAttributeService $attributes,
    int $websiteId,
    array $nodes,
    int $parentId,
    array &$codeIndex,
): int {
    $count = 0;
    foreach ($nodes as $node) {
        $code = strtolower(trim((string)($node['code'] ?? '')));
        $i18n = is_array($node['i18n'] ?? null) ? $node['i18n'] : [];
        $zh = $i18n['zh_Hans_CN'] ?? null;
        $en = $i18n['en_US'] ?? null;
        if (!is_array($zh) || trim((string)($zh['name'] ?? '')) === '') {
            throw new RuntimeException('missing zh name for ' . $code);
        }
        if (!is_array($en) || trim((string)($en['name'] ?? '')) === '') {
            throw new RuntimeException('missing en name for ' . $code);
        }

        $existingId = (int)($codeIndex[$code] ?? 0);
        $created = $admin->save(
            $websiteId,
            $existingId,
            $parentId,
            (string)$en['name'],
            'active',
            $code,
            'en_US',
            null,
            null,
            null,
            (string)($en['summary'] ?? ''),
            (string)($en['description'] ?? ''),
        );
        $categoryId = (int)($created['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new RuntimeException('failed upsert ' . $code);
        }

        $attributes->writeName($websiteId, $categoryId, (string)$zh['name'], 'zh_Hans_CN');
        $attributes->writeSummary($websiteId, $categoryId, (string)($zh['summary'] ?? ''), 'zh_Hans_CN');
        $attributes->writeDescription($websiteId, $categoryId, (string)($zh['description'] ?? ''), 'zh_Hans_CN');
        $attributes->writeCode($websiteId, $categoryId, $code, 'zh_Hans_CN');

        $codeIndex[$code] = $categoryId;
        $action = $existingId > 0 ? '~' : '+';
        echo "{$action} #{$categoryId} {$code} {$zh['name']} / {$en['name']}" . PHP_EOL;
        ++$count;

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children !== []) {
            $count += seedNodes($admin, $attributes, $websiteId, $children, $categoryId, $codeIndex);
        }
    }

    return $count;
}

$admin = ObjectManager::getInstance(ProductCategoryAdminService::class);
$attributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);
$categories = ObjectManager::getInstance(CategoryRepository::class);

$tree = groceryTree();
$keepCodes = collectCodes($tree);
$codeIndex = indexCodesById($categories, $attributes, $websiteId);
$deactivated = deactivateForeignCategories(
    $admin,
    $attributes,
    $categories,
    $websiteId,
    $keepCodes,
    $codeIndex,
);
$codeIndex = indexCodesById($categories, $attributes, $websiteId);
$total = seedNodes($admin, $attributes, $websiteId, $tree, 0, $codeIndex);

try {
    ObjectManager::getInstance(StorefrontCategoryTreeIndex::class)->invalidate($websiteId);
} catch (Throwable) {
}
try {
    ObjectManager::getInstance(StorefrontAllMenuCategoryTreeService::class)->invalidate($websiteId);
} catch (Throwable) {
}

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'website_code' => $requestedCode,
    'upserted' => $total,
    'deactivated_foreign' => $deactivated,
    'codes' => array_values(array_intersect(array_keys($codeIndex), $keepCodes)),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
