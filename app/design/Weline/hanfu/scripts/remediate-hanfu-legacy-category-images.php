<?php

declare(strict_types=1);

/**
 * Backfill image/banner for the legacy Hanfu taxonomy (形制/用途/材质…)
 * by reusing existing R2 category tiles from the commerce category tree.
 *
 * Does not create categories, change tree shape, or touch product media.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-hanfu-legacy-category-images.php --dry-run
 *   php app/code/Weline/Product/scripts/remediate-hanfu-legacy-category-images.php --apply
 *   php app/code/Weline/Product/scripts/remediate-hanfu-legacy-category-images.php --verify
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

const WEBSITE_ID = 0;
const MEDIA_BASE = '/pub/media/catalog/hanfu/r2/categories';
const LOCALES = ['', 'zh_Hans_CN', 'en_US'];

/**
 * Legacy code => existing R2 asset code (icons/{code}.webp already on disk).
 *
 * @var array<string, string>
 */
const ASSET_ALIAS = [
    'hanfu' => 'women',
    'style' => 'ruqun',
    'ming' => 'mamian',
    'tang' => 'qixiong',
    'song' => 'beizi',
    'mamian' => 'mamian',
    'duijin-ao' => 'aoqun',
    'yunjian' => 'wrap',
    'qixiong-ruqun' => 'qixiong',
    'hezi-qun' => 'qixiong',
    'beizi' => 'beizi',
    'baidie-qun' => 'mamian',
    'occasion' => 'daily',
    'daily' => 'daily',
    'wedding' => 'wedding',
    'festival' => 'festival',
    'restoration' => 'quju',
    'material' => 'sets',
    'polyester' => 'women',
    'silk' => 'women-robe',
    'zhijin' => 'mamian',
    'chiffon' => 'qiyao',
    'spec-products' => 'sets',
];

$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--apply', '--verify'], true)) {
    fwrite(STDERR, "Usage: --dry-run | --apply | --verify\n");
    exit(1);
}

$root = dirname(__DIR__, 5);

/**
 * @param list<array<string, mixed>> $nodes
 * @return list<array{category_id:int,code:string,name:string,image:string,banner:string}>
 */
function flattenCategories(array $nodes): array
{
    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $out[] = [
            'category_id' => (int)($node['category_id'] ?? $node['id'] ?? 0),
            'code' => trim((string)($node['code'] ?? '')),
            'name' => trim((string)($node['name'] ?? '')),
            'image' => trim((string)($node['image'] ?? '')),
            'banner' => trim((string)($node['banner'] ?? '')),
        ];
        $kids = $node['nodes'] ?? $node['children'] ?? [];
        if (is_array($kids) && $kids !== []) {
            foreach (flattenCategories($kids) as $child) {
                $out[] = $child;
            }
        }
    }

    return $out;
}

/**
 * @return array{image:string,banner:string,icon_fs:string,banner_fs:string}
 */
function mediaPaths(string $assetCode): array
{
    return [
        'image' => MEDIA_BASE . '/icons/' . $assetCode . '.webp',
        'banner' => MEDIA_BASE . '/banners/' . $assetCode . '.webp',
        'icon_fs' => 'pub/media/catalog/hanfu/r2/categories/icons/' . $assetCode . '.webp',
        'banner_fs' => 'pub/media/catalog/hanfu/r2/categories/banners/' . $assetCode . '.webp',
    ];
}

function ensureNamedCopy(string $root, string $sourceRel, string $targetCode, string $kind): string
{
    $sourceAbs = $root . '/' . $sourceRel;
    if (!is_file($sourceAbs)) {
        throw new RuntimeException('Missing source media: ' . $sourceRel);
    }
    $dir = $kind === 'banner'
        ? 'pub/media/catalog/hanfu/r2/categories/banners'
        : 'pub/media/catalog/hanfu/r2/categories/icons';
    $targetRel = $dir . '/' . $targetCode . '.webp';
    $targetAbs = $root . '/' . $targetRel;
    if (!is_file($targetAbs) || hash_file('sha256', $targetAbs) !== hash_file('sha256', $sourceAbs)) {
        if (!is_dir(dirname($targetAbs)) && !mkdir(dirname($targetAbs), 0775, true) && !is_dir(dirname($targetAbs))) {
            throw new RuntimeException('Cannot create media dir: ' . dirname($targetRel));
        }
        if (!copy($sourceAbs, $targetAbs)) {
            throw new RuntimeException('Failed copying ' . $sourceRel . ' -> ' . $targetRel);
        }
    }

    return '/pub/media/catalog/hanfu/r2/categories/'
        . ($kind === 'banner' ? 'banners/' : 'icons/')
        . $targetCode . '.webp';
}

/** @var ProductCategoryAdminService $categoryAdmin */
$categoryAdmin = ObjectManager::getInstance(ProductCategoryAdminService::class);
/** @var ProductCategoryAttributeService $categoryAttributes */
$categoryAttributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);
/** @var StorefrontCatalogCacheCoordinator $catalogCache */
$catalogCache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);

$flat = flattenCategories($categoryAdmin->tree(WEBSITE_ID, 'zh_Hans_CN'));
$planned = [];
$skipped = [];
foreach ($flat as $row) {
    $code = $row['code'];
    if ($code === '' || !isset(ASSET_ALIAS[$code])) {
        continue;
    }
    $assetCode = ASSET_ALIAS[$code];
    $source = mediaPaths($assetCode);
    if (!is_file($root . '/' . $source['icon_fs']) || !is_file($root . '/' . $source['banner_fs'])) {
        throw new RuntimeException("Source asset missing for alias {$code} -> {$assetCode}");
    }

    // Prefer code-stable paths; pixels may be copied from the alias on --apply.
    $imageUrl = $code === $assetCode
        ? $source['image']
        : MEDIA_BASE . '/icons/' . $code . '.webp';
    $bannerUrl = $code === $assetCode
        ? $source['banner']
        : MEDIA_BASE . '/banners/' . $code . '.webp';

    $needsWrite = $row['image'] !== $imageUrl || $row['banner'] !== $bannerUrl;
    if (!$needsWrite) {
        $skipped[] = $code;
        continue;
    }

    $planned[] = [
        'category_id' => $row['category_id'],
        'code' => $code,
        'name' => $row['name'],
        'asset_code' => $assetCode,
        'source_icon_fs' => $source['icon_fs'],
        'source_banner_fs' => $source['banner_fs'],
        'image' => $imageUrl,
        'banner' => $bannerUrl,
        'had_image' => $row['image'] !== '',
        'had_banner' => $row['banner'] !== '',
    ];
}

if ($mode === '--dry-run') {
    echo json_encode([
        'ok' => true,
        'mode' => 'dry-run',
        'website_id' => WEBSITE_ID,
        'planned' => count($planned),
        'skipped_already_ok' => count($skipped),
        'items' => $planned,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit(0);
}

if ($mode === '--verify') {
    $missing = [];
    foreach ($flat as $row) {
        $code = $row['code'];
        if ($code === '' || !isset(ASSET_ALIAS[$code])) {
            continue;
        }
        $assetCode = ASSET_ALIAS[$code];
        $expectImage = $code === $assetCode
            ? MEDIA_BASE . '/icons/' . $assetCode . '.webp'
            : MEDIA_BASE . '/icons/' . $code . '.webp';
        $expectBanner = $code === $assetCode
            ? MEDIA_BASE . '/banners/' . $assetCode . '.webp'
            : MEDIA_BASE . '/banners/' . $code . '.webp';
        $iconFs = ltrim($expectImage, '/');
        $bannerFs = ltrim($expectBanner, '/');
        if ($row['image'] !== $expectImage || $row['banner'] !== $expectBanner) {
            $missing[] = [
                'code' => $code,
                'category_id' => $row['category_id'],
                'image' => $row['image'],
                'banner' => $row['banner'],
                'expect_image' => $expectImage,
                'expect_banner' => $expectBanner,
            ];
        }
        if (!is_file($root . '/' . $iconFs) || !is_file($root . '/' . $bannerFs)) {
            $missing[] = [
                'code' => $code,
                'error' => 'media_file_missing',
                'icon' => $iconFs,
                'banner' => $bannerFs,
            ];
        }
    }
    if ($missing !== []) {
        echo json_encode([
            'ok' => false,
            'mode' => 'verify',
            'missing' => $missing,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
        exit(1);
    }
    echo json_encode([
        'ok' => true,
        'mode' => 'verify',
        'checked' => count(ASSET_ALIAS),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit(0);
}

// --apply
$writes = 0;
foreach ($planned as $item) {
    $categoryId = (int)$item['category_id'];
    $code = (string)$item['code'];
    $assetCode = (string)$item['asset_code'];
    if ($code !== $assetCode) {
        ensureNamedCopy($root, (string)$item['source_icon_fs'], $code, 'icon');
        ensureNamedCopy($root, (string)$item['source_banner_fs'], $code, 'banner');
    }
    foreach (LOCALES as $locale) {
        $categoryAttributes->writeImage(WEBSITE_ID, $categoryId, (string)$item['image'], $locale);
        $categoryAttributes->writeBanner(WEBSITE_ID, $categoryId, (string)$item['banner'], $locale);
        $writes += 2;
    }
}

$catalogCache->notifyCatalogChanged(
    WEBSITE_ID,
    'hanfu_legacy_category_image_backfill',
    ['categories' => count($planned), 'writes' => $writes],
);

echo json_encode([
    'ok' => true,
    'mode' => 'apply',
    'website_id' => WEBSITE_ID,
    'categories' => count($planned),
    'attribute_writes' => $writes,
    'skipped_already_ok' => count($skipped),
    'items' => array_map(static fn(array $i): array => [
        'category_id' => $i['category_id'],
        'code' => $i['code'],
        'name' => $i['name'],
        'asset_code' => $i['asset_code'],
        'image' => $i['image'],
    ], $planned),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
