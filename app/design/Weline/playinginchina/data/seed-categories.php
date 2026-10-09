<?php

declare(strict_types=1);

/**
 * Seed PlayingInChina taxonomy for website code=playinginchina.
 *
 * 分类树**由主题 data/*.json 生成**（不硬编码），保持与内容包同源：
 *   pic-packages   线路套餐
 *   pic-tickets    单景点门票
 *   pic-styles     出行方式大类（6）→ pic-style-{id}
 *   pic-scenery    风景分类（19）   → pic-scenery-{id}
 *   pic-regions    地区（7）        → pic-region-{slug}
 *
 * Usage:
 *   php app/design/Weline/playinginchina/data/seed-categories.php
 *
 * Idempotent by category code. Never writes Website::ID_DEFAULT (0).
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;
use Weline\Product\Service\StorefrontCategoryTreeIndex;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 4) . '/bootstrap.php';
require_once dirname(__DIR__) . '/frontend/includes/PlayingInChinaData.php';

const PIC_WEBSITE_CODE = 'playinginchina';

$requestedCode = trim((string)($argv[1] ?? PIC_WEBSITE_CODE));
if ($requestedCode !== PIC_WEBSITE_CODE) {
    fwrite(STDERR, "Refuse: only website code " . PIC_WEBSITE_CODE . " is accepted (got {$requestedCode}).\n");
    exit(1);
}

$websiteModel = ObjectManager::getInstance(Website::class);
$rows = $websiteModel->reset()->where(Website::schema_primary_key, 0, '>=')->select()->fetchArray();
$websiteId = 0;
foreach ($rows as $row) {
    if ((string)($row['code'] ?? '') === $requestedCode) {
        $websiteId = (int)($row['website_id'] ?? 0);
        break;
    }
}
if ($websiteId <= 0 || $websiteId === Website::ID_DEFAULT) {
    fwrite(STDERR, "Website {$requestedCode} not found or resolved to default.\n");
    exit(1);
}

/**
 * 由 data/*.json 构建分类树。
 *
 * @return list<array<string,mixed>>
 */
function picTree(): array
{
    $n = static function (string $code, string $enName, string $enSummary, string $enDesc, string $zhName): array {
        return [
            'code' => $code,
            'i18n' => [
                'zh_Hans_CN' => ['name' => $zhName, 'summary' => $enSummary, 'description' => $enDesc],
                'en_US'     => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
            ],
            'children' => [],
        ];
    };

    $out = [];

    // 线路套餐
    $out[] = $n('pic-packages', 'Tours & Packages', 'Ready-made multi-day China routes', 'Packaged China tour routes with day-by-day itineraries, indicative prices and seasonal variants.', '线路套餐');

    // 单景点门票
    $out[] = $n('pic-tickets', 'Attraction Tickets', 'Single-destination entrance tickets', 'Buy entrance tickets for a single attraction without booking a full route. Indicative, season-adjusted prices.', '景点门票');

    // 出行方式大类
    $styles = $n('pic-styles', 'Travel Styles', 'Cycling, self-drive, hiking and more', 'Browse China by how you want to move — cycling, self-drive, hiking, family, photography or luxury.', '出行方式');
    foreach (\PlayingInChinaData::travelTypes() as $t) {
        $styles['children'][] = $n(
            'pic-style-' . (string)$t['id'],
            (string)$t['name'],
            (string)$t['sub'],
            (string)$t['desc'],
            (string)$t['name'],
        );
    }
    $out[] = $styles;

    // 风景分类
    $scenery = $n('pic-scenery', 'Scenery Types', 'Browse destinations by landscape', 'Pick the landscape that pulls you — mountains, lakes, gardens, heritage, ancient towns and more.', '风景分类');
    foreach (\PlayingInChinaData::scenery() as $s) {
        $scenery['children'][] = $n(
            'pic-scenery-' . (string)$s['id'],
            (string)$s['name'],
            (string)$s['sub'],
            (string)$s['sub'],
            (string)$s['name'],
        );
    }
    $out[] = $scenery;

    // 地区
    $regions = $n('pic-regions', 'Regions', 'Plan by part of China', 'Browse China by region — north, east, south, southwest, northwest, central and northeast.', '地区');
    foreach (\array_keys(\PlayingInChinaData::indexByRegion()) as $region) {
        $slug = \strtolower(\str_replace(' ', '-', (string)$region));
        $short = \str_replace(' China', '', (string)$region);
        $regions['children'][] = $n(
            'pic-region-' . $slug,
            $short,
            'Destinations in ' . $region,
            'Destinations in ' . $region . '.',
            $short,
        );
    }
    $out[] = $regions;

    return $out;
}

/**
 * @return array<string,int> code => category_id
 */
function picIndexCodes(CategoryRepository $categories, ProductCategoryAttributeService $attributes, int $websiteId): array
{
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
    $en = $attributes->readCodeMap($websiteId, $ids, 'en_US');
    $zh = $attributes->readCodeMap($websiteId, $ids, 'zh_Hans_CN');
    $out = [];
    foreach ($ids as $id) {
        $code = \strtolower(\trim((string)($en[$id] ?? '')));
        if ($code === '') {
            $code = \strtolower(\trim((string)($zh[$id] ?? '')));
        }
        if ($code !== '') {
            $out[$code] = $id;
        }
    }
    return $out;
}

/** @return list<string> */
function picCollectCodes(array $nodes): array
{
    $codes = [];
    foreach ($nodes as $node) {
        $code = \strtolower(\trim((string)($node['code'] ?? '')));
        if ($code !== '') {
            $codes[] = $code;
        }
        $children = \is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children !== []) {
            $codes = \array_merge($codes, picCollectCodes($children));
        }
    }
    return $codes;
}

function picSeedNodes(
    ProductCategoryAdminService $admin,
    ProductCategoryAttributeService $attributes,
    int $websiteId,
    array $nodes,
    int $parentId,
    array &$codeIndex,
): int {
    $count = 0;
    foreach ($nodes as $node) {
        $code = \strtolower(\trim((string)($node['code'] ?? '')));
        $en = $node['i18n']['en_US'] ?? [];
        $zh = $node['i18n']['zh_Hans_CN'] ?? [];
        if ($code === '' || \trim((string)($en['name'] ?? '')) === '') {
            throw new \RuntimeException('invalid category node: ' . $code);
        }

        $existingId = (int)($codeIndex[$code] ?? 0);
        $saved = $admin->save(
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
        $categoryId = (int)($saved['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new \RuntimeException('failed to upsert category ' . $code);
        }

        $attributes->writeName($websiteId, $categoryId, (string)$zh['name'], 'zh_Hans_CN');
        $attributes->writeSummary($websiteId, $categoryId, (string)($zh['summary'] ?? ''), 'zh_Hans_CN');
        $attributes->writeDescription($websiteId, $categoryId, (string)($zh['description'] ?? ''), 'zh_Hans_CN');
        $attributes->writeCode($websiteId, $categoryId, $code, 'zh_Hans_CN');

        $codeIndex[$code] = $categoryId;
        echo ($existingId > 0 ? '~' : '+') . " #{$categoryId} {$code} {$en['name']}" . PHP_EOL;
        ++$count;

        $children = \is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children !== []) {
            $count += picSeedNodes($admin, $attributes, $websiteId, $children, $categoryId, $codeIndex);
        }
    }
    return $count;
}

$admin = ObjectManager::getInstance(ProductCategoryAdminService::class);
$attributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);
$categories = ObjectManager::getInstance(CategoryRepository::class);

$tree = picTree();
$keepCodes = picCollectCodes($tree);
$codeIndex = picIndexCodes($categories, $attributes, $websiteId);
$total = picSeedNodes($admin, $attributes, $websiteId, $tree, 0, $codeIndex);

try {
    ObjectManager::getInstance(StorefrontCategoryTreeIndex::class)->invalidate($websiteId);
} catch (\Throwable) {
}
try {
    ObjectManager::getInstance(StorefrontAllMenuCategoryTreeService::class)->invalidate($websiteId);
} catch (\Throwable) {
}

echo \json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'website_code' => $requestedCode,
    'upserted' => $total,
    'codes' => \array_values(\array_intersect(\array_keys($codeIndex), $keepCodes)),
], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . PHP_EOL;
