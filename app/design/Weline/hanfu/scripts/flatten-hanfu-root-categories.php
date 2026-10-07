<?php

declare(strict_types=1);

/**
 * 专营汉服站：去掉唯一顶层「汉服」，将其直接子类提升为顶级。
 *
 * 注意：不要对汉服根调用 delete()——ProductCategoryAdminService::delete 会递归删除子孙。
 * 提升子节点后，仅将汉服根设为 inactive（或确认无子后再删）。
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/flatten-hanfu-root-categories.php [website_id] [locale]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCategoryTreeIndex;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));
$locale = trim((string)($argv[2] ?? 'zh_Hans_CN'));
if ($locale === '') {
    $locale = 'zh_Hans_CN';
}

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

/** @var ProductCategoryAdminService $categoryAdmin */
$categoryAdmin = ObjectManager::getInstance(ProductCategoryAdminService::class);
/** @var StorefrontCategoryTreeIndex $treeIndex */
$treeIndex = ObjectManager::getInstance(StorefrontCategoryTreeIndex::class);

$rows = $categoryAdmin->tree($websiteId, $locale);
$hanfuRoots = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $name = trim((string)($row['name'] ?? ''));
    $code = trim((string)($row['code'] ?? ''));
    $categoryId = (int)($row['category_id'] ?? 0);
    if ($categoryId <= 0) {
        continue;
    }
    if ($name === '汉服' || strcasecmp($code, 'hanfu') === 0) {
        $hanfuRoots[] = $row;
    }
}

if ($hanfuRoots === []) {
    $rootNames = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $rootNames[] = (string)($row['name'] ?? '');
        }
    }
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'reason' => 'no_top_level_hanfu',
        'website_id' => $websiteId,
        'root_names' => $rootNames,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$promoted = [];
$deactivated = [];
foreach ($hanfuRoots as $root) {
    $rootId = (int)($root['category_id'] ?? 0);
    if ($rootId <= 0) {
        continue;
    }
    $children = is_array($root['nodes'] ?? null) ? $root['nodes'] : [];
    usort(
        $children,
        static fn(array $a, array $b): int => ((int)($a['position'] ?? 0)) <=> ((int)($b['position'] ?? 0))
            ?: ((int)($a['category_id'] ?? 0)) <=> ((int)($b['category_id'] ?? 0))
    );

    foreach ($children as $child) {
        if (!is_array($child)) {
            continue;
        }
        $childId = (int)($child['category_id'] ?? 0);
        if ($childId <= 0) {
            continue;
        }
        $result = $categoryAdmin->save(
            $websiteId,
            $childId,
            0,
            trim((string)($child['name'] ?? '')),
            (string)($child['status'] ?? 'active'),
            (string)($child['code'] ?? ''),
            $locale,
            (($child['google_taxonomy_id'] ?? '') !== '' ? (string)$child['google_taxonomy_id'] : null),
            (($child['image'] ?? '') !== '' ? (string)$child['image'] : null),
            (($child['banner'] ?? '') !== '' ? (string)$child['banner'] : null),
            array_key_exists('summary', $child) ? (string)$child['summary'] : null,
            array_key_exists('description', $child) ? (string)$child['description'] : null,
        );
        $promoted[] = [
            'category_id' => $childId,
            'name' => (string)($child['name'] ?? ''),
            'path' => (string)($result['path'] ?? ''),
        ];
    }

    // 仅停用汉服壳节点；禁止 delete()（会递归清空子孙）。
    $categoryAdmin->save(
        $websiteId,
        $rootId,
        0,
        trim((string)($root['name'] ?? '汉服')),
        'inactive',
        (string)($root['code'] ?? 'hanfu'),
        $locale,
    );
    $deactivated[] = [
        'category_id' => $rootId,
        'name' => (string)($root['name'] ?? ''),
        'code' => (string)($root['code'] ?? ''),
        'status' => 'inactive',
    ];
}

$treeIndex->invalidate($websiteId);
$freshRoots = [];
foreach ($categoryAdmin->tree($websiteId, $locale) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $freshRoots[] = [
        'category_id' => (int)($row['category_id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'path' => (string)($row['path'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'position' => (int)($row['position'] ?? 0),
    ];
}

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'locale' => $locale,
    'promoted' => $promoted,
    'deactivated' => $deactivated,
    'top_level' => $freshRoots,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
